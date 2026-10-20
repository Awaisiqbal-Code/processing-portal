<?php
declare(strict_types=1);

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

function redirect(string $path): never {
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

// ---------- flash messages ----------
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

// ---------- input ----------
function post(string $k): string { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }
function get(string $k): string { $v = $_GET[$k] ?? ''; return is_string($v) ? trim($v) : ''; }
function valid_date(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}
function fmt_date(?string $d): string {
    if (!$d) return '';
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "$m[3]/$m[2]/$m[1]" : $d;
}
function safe_link(?string $u): ?string {
    $u = trim((string)$u);
    return preg_match('#^https?://#i', $u) ? $u : null;
}

/**
 * Resolves the valid recorded meeting or Zoom video URL for a student record.
 * Returns null if no actual meeting video/session link exists.
 */
function student_meeting_url(array $s): ?string {
    $ml = trim((string)($s['meeting_link'] ?? ''));
    $dl = trim((string)($s['drive_link'] ?? ''));
    if ($ml !== '' && $ml !== $dl && safe_link($ml) && !str_contains($ml, '/drive/folders/')) {
        return $ml;
    }
    if (!empty($s['drive_files_json'])) {
        $dfj = is_array($s['drive_files_json']) ? $s['drive_files_json'] : json_decode((string)$s['drive_files_json'], true);
        if (is_array($dfj)) {
            if (!empty($dfj['meeting_link']) && $dfj['meeting_link'] !== $dl && safe_link($dfj['meeting_link']) && !str_contains((string)$dfj['meeting_link'], '/drive/folders/')) {
                return trim((string)$dfj['meeting_link']);
            }
            if (!empty($dfj['files']) && is_array($dfj['files'])) {
                foreach ($dfj['files'] as $f) {
                    $fn = strtolower($f['name'] ?? '');
                    $rt = strtolower($f['raw_title'] ?? '');
                    if (preg_match('/\.(pdf|jpg|jpeg|png|doc|docx)\b/i', $fn) || str_contains($rt, ' pdf')) {
                        continue;
                    }
                    if (!empty($f['is_meeting']) || str_ends_with($fn, '.mp4') || str_ends_with($fn, '.m4a') || str_contains($rt, 'video')) {
                        if (!empty($f['id'])) {
                            return 'https://drive.google.com/file/d/' . $f['id'] . '/view';
                        }
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Returns true only if the student has a confirmed recorded meeting or Zoom video link.
 */
function student_has_meeting(array $s): bool {
    return student_meeting_url($s) !== null;
}


function random_password(int $len = 10): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function log_action(string $action, ?int $studentId = null): void {
    q('INSERT INTO activity_log (user_id, student_id, action, ip, created_at) VALUES (?,?,?,?,?)',
      [current_user()['id'] ?? null, $studentId, mb_substr($action, 0, 255), client_ip(), now()]);
}

function make_username(string $name): string {
    $p = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', '', $name)), -1, PREG_SPLIT_NO_EMPTY);
    $base = ($p[0] ?? 'student') . '.' . ($p[1] ?? 'student');
    $u = $base; $n = 1;
    while (q('SELECT 1 FROM users WHERE username = ?', [$u])->fetchColumn()) { $n++; $u = $base . $n; }
    return $u;
}
