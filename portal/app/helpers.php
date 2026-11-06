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
function verify_csrf(?string $t): bool {
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $t);
}
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf(is_string($t) ? $t : '')) {
        // Session gone (idle sign-out / signed in elsewhere): send the person to sign in, not to a dead end.
        if (empty($_SESSION['auth'])) {
            $reason = !empty($_SESSION['session_replaced']) ? 'replaced' : 'idle';
            if (function_exists('is_json_request') && is_json_request()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => false, 'reason' => $reason, 'error' => 'Your session ended. Please sign in again.']));
            }
            redirect('index.php?logged_out=' . $reason);
        }
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        $back = h($_SERVER['HTTP_REFERER'] ?? url('index.php'));
        exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Page refreshed</title>'
           . '<link rel="stylesheet" href="' . h(url('assets/style.css')) . '"><body><main style="max-width:520px;margin:12vh auto"><section class="panel">'
           . '<h1>This page was refreshed for security</h1><p class="muted">The form was open for a long time, so its security token expired. Nothing was saved. '
           . 'Go back, reload the page and submit again.</p><p style="margin-top:14px"><a class="btn primary" href="' . $back . '">← Go back and reload</a></p></section></main></body>');
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

function time_ago(?string $ts): string {
    if (!$ts) return '';
    // Timestamps are written by now() in the app timezone (Asia/Karachi), so parse them in that same timezone.
    $t = strtotime($ts);
    if (!$t) return $ts;
    $diff = time() - $t;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return fmt_date(substr($ts, 0, 10));
}

function drive_link_for_student(array $s): ?string {
    return safe_link($s['drive_link'] ?? null);
}

function meeting_link_for_student(array $s): ?string {
    return student_has_meeting($s) ? url('meeting.php?id=' . (int)$s['id']) : null;
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

/**
 * Reads a tunable setting from the `settings` table, falling back to environment variables or $CONFIG.
 */
function setting(string $key, mixed $default = null, bool $reload = false): mixed {
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        try {
            $hasTable = q("SELECT 1 FROM sqlite_master WHERE type='table' AND name='settings'")->fetchColumn();
            if ($hasTable) {
                foreach (q('SELECT key, value FROM settings') as $r) {
                    $cache[$r['key']] = $r['value'];
                }
            }
        } catch (Throwable) {
            // Ignore if settings table is not migrated yet
        }
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    global $CONFIG;
    $lowerKey = strtolower($key);
    return $CONFIG[$lowerKey] ?? $default;
}

function set_setting(string $key, mixed $value): void {
    $now = now();
    q("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at", [
        $key,
        (string)$value,
        $now,
    ]);
    setting($key, null, true);
}

/**
 * Structured audit logger for `activity_logs` (Phase 1 & Phase 2.3).
 */
function log_activity(string $entityType, ?int $entityId, string $action, ?array $oldValues = null, ?array $newValues = null, ?int $userId = null): void {
    try {
        $hasTable = q("SELECT 1 FROM sqlite_master WHERE type='table' AND name='activity_logs'")->fetchColumn();
        if (!$hasTable) return;
        $uid = $userId ?? (current_user()['id'] ?? null);
        q('INSERT INTO activity_logs (user_id, entity_type, entity_id, action, old_values, new_values, created_at) VALUES (?,?,?,?,?,?,?)', [
            $uid ? (int)$uid : null,
            $entityType,
            $entityId,
            $action,
            $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            now(),
        ]);
    } catch (Throwable $e) {
        error_log('log_activity error: ' . $e->getMessage());
    }
}

/**
 * Checks whether a Railway Persistent Volume is mounted and active.
 */
function railway_volume_status(): array {
    $volPath = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: (is_dir('/data') ? '/data' : '');
    $dbPath = getenv('PORTAL_SQLITE') ?: (APP . '/portal.sqlite');
    $dataSqliteExists = is_file('/data/portal.sqlite');
    $isMounted = ($volPath !== '' && is_dir($volPath) && is_writable($volPath));
    $isRailway = (bool)(getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_ID') || $isMounted);
    return [
        'volume_mount_path' => $volPath ?: null,
        'is_mounted'        => $isMounted,
        'is_railway'        => $isRailway,
        'data_dir_writable' => $isMounted,
        'data_sqlite_exists'=> $dataSqliteExists,
        'active_db_path'    => $dbPath,
        'backup_dir'        => ($isMounted ? rtrim($volPath, '/\\') . '/backups' : dirname(ROOT) . '/backups'),
    ];
}

function make_username(string $name): string {
    $p = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', '', $name)), -1, PREG_SPLIT_NO_EMPTY);
    $base = ($p[0] ?? 'student') . '.' . ($p[1] ?? 'student');
    $u = $base; $n = 1;
    while (q('SELECT 1 FROM users WHERE username = ?', [$u])->fetchColumn()) { $n++; $u = $base . $n; }
    return $u;
}
