<?php
declare(strict_types=1);
// Password policy, email sending (SMTP) and the "forgot password" request flow:
//   1. the person asks for a reset (username)              -> request waits for Admin / Super Admin
//   2. Admin approves                                       -> a 6-digit code is emailed to the address on the account
//   3. the person enters the code                           -> a one-time link (15 minutes) opens
//   4. they set a new password that meets the policy        -> every other sign-in is closed, admins are told
// Passwords themselves are never stored in readable form; the request history shows who, when, approved by whom.

const PASSWORD_MIN = 10;
const PASSWORD_HISTORY = 3;            // cannot reuse the last 3 passwords
const RESET_CODE_MINUTES = 30;
const RESET_LINK_MINUTES = 15;
const RESET_MAX_CODE_TRIES = 5;
const RESET_MAX_REQUESTS_PER_DAY = 3;

/** Problems with a new password (empty array = fine). */
function password_problems(string $pw, array $user = []): array {
    $p = [];
    if (mb_strlen($pw) < PASSWORD_MIN) $p[] = 'at least ' . PASSWORD_MIN . ' characters';
    if (!preg_match('/[a-z]/', $pw)) $p[] = 'a small letter';
    if (!preg_match('/[A-Z]/', $pw)) $p[] = 'a capital letter';
    if (!preg_match('/\d/', $pw)) $p[] = 'a number';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) $p[] = 'a symbol such as ! @ # $';
    $low = strtolower($pw);
    foreach (array_filter(array_merge([strtolower((string)($user['username'] ?? ''))], preg_split('/\s+/', strtolower((string)($user['name'] ?? ''))) ?: [])) as $part) {
        if (strlen($part) >= 3 && str_contains($low, $part)) { $p[] = 'no part of your name or username'; break; }
    }
    $common = ['password', 'qwerty', '123456', 'scholarship', 'pakistan', 'welcome', 'admin', 'abc123', 'iloveyou', 'letmein'];
    foreach ($common as $c) if (str_contains($low, $c)) { $p[] = 'no common words like “' . $c . '”'; break; }
    return $p;
}

function password_policy_text(): string {
    return 'At least ' . PASSWORD_MIN . ' characters with a capital letter, a small letter, a number and a symbol. Not your name or username, and not one of your last ' . PASSWORD_HISTORY . ' passwords.';
}

/** Was this password used recently by this account? */
function password_recently_used(int $userId, string $pw): bool {
    try { $row = q('SELECT password_hash, password_history FROM users WHERE id = ?', [$userId])->fetch(); } catch (Throwable) { return false; }
    if (!$row) return false;
    $hashes = array_merge([(string)$row['password_hash']], json_decode((string)($row['password_history'] ?? '[]'), true) ?: []);
    foreach (array_slice($hashes, 0, PASSWORD_HISTORY) as $h) if ($h && password_verify($pw, (string)$h)) return true;
    return false;
}

/** Sets a new password, keeps a short history, and signs the account out everywhere else. */
function set_user_password(int $userId, string $pw, bool $mustChange = false, ?string $keepToken = null): void {
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$userId])->fetch();
    $hist = [];
    try { $hist = json_decode((string)(q('SELECT password_history FROM users WHERE id = ?', [$userId])->fetchColumn() ?: '[]'), true) ?: []; } catch (Throwable) {}
    if ($row && $row['password_hash']) array_unshift($hist, (string)$row['password_hash']);
    $hist = array_slice($hist, 0, PASSWORD_HISTORY);
    q('UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $userId]);
    try {
        q('UPDATE users SET password_history = ?, password_changed_at = ?, session_token = ? WHERE id = ?',
          [json_encode($hist), now(), $keepToken ?? ('pwchange-' . bin2hex(random_bytes(8))), $userId]);
    } catch (Throwable) {}
}

// ---------------------------------------------------------------------------
// Email (SMTP). Settings come from environment variables first (SMTP_HOST, SMTP_PORT, SMTP_USER,
// SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME, SMTP_SECURE=ssl|tls), then from Team → System → Email.
// ---------------------------------------------------------------------------

function mail_settings(): array {
    $g = fn(string $env, string $key, string $def = '') => (string)(getenv($env) ?: setting($key, $def));
    return [
        'host' => $g('SMTP_HOST', 'smtp_host'), 'port' => (int)$g('SMTP_PORT', 'smtp_port', '465'),
        'user' => $g('SMTP_USER', 'smtp_user'), 'pass' => $g('SMTP_PASS', 'smtp_pass'),
        'from' => $g('SMTP_FROM', 'smtp_from'), 'from_name' => $g('SMTP_FROM_NAME', 'smtp_from_name', 'ScholarshipHive Portal'),
        'secure' => strtolower($g('SMTP_SECURE', 'smtp_secure', 'ssl')),
    ];
}

function mail_ready(): bool {
    $m = mail_settings();
    return $m['host'] !== '' && $m['user'] !== '' && $m['pass'] !== '' && filter_var($m['from'] ?: $m['user'], FILTER_VALIDATE_EMAIL);
}

/** Sends a plain-text + HTML email over SMTP. Returns [ok, error]. */
function send_mail(string $to, string $subject, string $text, string $html = ''): array {
    $m = mail_settings();
    if (!mail_ready()) return [false, 'Email is not set up yet (Team → System → Email).'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'The email address on the account is not valid.'];
    $from = $m['from'] ?: $m['user'];
    $remote = ($m['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $m['host'] . ':' . $m['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "Could not reach the email server ($errstr)."];
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $d; };
    $cmd = function (string $c, array $ok) use ($fp, $read): array { fwrite($fp, $c . "\r\n"); $r = $read(); return [in_array((int)substr($r, 0, 3), $ok, true), $r]; };
    $greet = $read();
    if ((int)substr($greet, 0, 3) !== 220) { fclose($fp); return [false, 'Email server did not answer.']; }
    $host = gethostname() ?: 'portal';
    [$ok] = $cmd("EHLO $host", [250]);
    if ($ok && $m['secure'] === 'tls') {
        [$ok] = $cmd('STARTTLS', [220]);
        if (!$ok || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { fclose($fp); return [false, 'Secure connection (TLS) failed.']; }
        [$ok] = $cmd("EHLO $host", [250]);
    }
    if ($ok) [$ok] = $cmd('AUTH LOGIN', [334]);
    if ($ok) [$ok] = $cmd(base64_encode($m['user']), [334]);
    if ($ok) [$ok, $r] = $cmd(base64_encode($m['pass']), [235]);
    if (!$ok) { fclose($fp); return [false, 'Email login failed — check the SMTP username / app password.']; }
    [$ok] = $cmd("MAIL FROM:<$from>", [250]);
    if ($ok) [$ok] = $cmd("RCPT TO:<$to>", [250, 251]);
    if ($ok) [$ok] = $cmd('DATA', [354]);
    if (!$ok) { fclose($fp); return [false, 'The email server refused the message.']; }
    $b = 'b' . bin2hex(random_bytes(8));
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = 'From: ' . $enc($m['from_name']) . " <$from>\r\nTo: <$to>\r\nSubject: " . $enc($subject) . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? 'portal') . ">\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html ?: nl2br(h($text)))) . "--$b--\r\n";
    $data = str_replace("\n.", "\n..", $headers . "\r\n" . $body);
    [$ok] = $cmd($data . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
    return $ok ? [true, ''] : [false, 'The email server did not accept the message.'];
}

// ---------------------------------------------------------------------------
// Reset requests
// ---------------------------------------------------------------------------

/** Email address used for a reset: the staff email, or the student's email for student logins. */
function account_email(array $user): string {
    $e = trim((string)($user['email'] ?? ''));
    if ($e === '' && ($user['role'] ?? '') === 'student' && !empty($user['student_id'])) {
        $e = trim((string)q('SELECT email FROM students WHERE id = ?', [(int)$user['student_id']])->fetchColumn());
    }
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? strtolower($e) : '';
}

function mask_email(string $e): string {
    if (!str_contains($e, '@')) return '';
    [$a, $d] = explode('@', $e, 2);
    return mb_substr($a, 0, 2) . str_repeat('•', max(2, mb_strlen($a) - 2)) . '@' . $d;
}

/** Who may approve this person's reset. */
function can_approve_reset(array $actor, array $target): bool {
    if (($actor['role'] ?? '') === 'super_admin') return ($target['role'] ?? '') !== 'super_admin' || (int)$actor['id'] !== (int)$target['id'];
    if (($actor['role'] ?? '') === 'admin' && user_can($actor, 'staff.manage')) return !is_admin_role($target['role'] ?? '');
    return false;
}

function notify_reset_approvers(string $title, string $message): void {
    foreach (q("SELECT id, role FROM users WHERE active = 1 AND role IN ('super_admin', 'admin')")->fetchAll() as $a) {
        if ($a['role'] === 'admin' && !user_can($a, 'staff.manage')) continue;
        try { q('INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?,?,?,?,0,?)', [(int)$a['id'], 'password_reset', $title, $message, now()]); } catch (Throwable) {}
    }
}

/** Step 1. Always returns the same message, so the form cannot be used to find out which usernames exist. */
function request_password_reset(string $username, string $note): string {
    $generic = 'If that account exists, your request has been sent to the admin. Once approved, a code will be emailed to the address on your account.';
    $username = strtolower(trim($username));
    $ip = client_ip();
    $since = date('Y-m-d H:i:s', time() - 3600);
    if ((int)q('SELECT COUNT(*) FROM password_reset_requests WHERE request_ip = ? AND requested_at >= ?', [$ip, $since])->fetchColumn() >= 10) return $generic;
    $user = q('SELECT * FROM users WHERE LOWER(username) = ? AND active = 1', [$username])->fetch();
    if (!$user) return $generic;
    $day = date('Y-m-d H:i:s', time() - 86400);
    if ((int)q('SELECT COUNT(*) FROM password_reset_requests WHERE user_id = ? AND requested_at >= ?', [(int)$user['id'], $day])->fetchColumn() >= RESET_MAX_REQUESTS_PER_DAY) return $generic;
    q("UPDATE password_reset_requests SET status = 'expired' WHERE user_id = ? AND status IN ('pending', 'approved', 'code_verified')", [(int)$user['id']]);
    q('INSERT INTO password_reset_requests (user_id, status, requested_at, request_ip, request_note) VALUES (?,?,?,?,?)',
      [(int)$user['id'], 'pending', now(), $ip, mb_substr(trim($note), 0, 300) ?: null]);
    notify_reset_approvers('Password reset request', $user['name'] . ' (' . role_label($user['role']) . ') asked to reset their password.');
    log_activity('user', (int)$user['id'], 'password_reset_requested', null, ['ip' => $ip], null);
    return $generic;
}

/** Step 2. Admin approves: a code is emailed. Returns [ok, message]. */
function approve_password_reset(array $actor, int $requestId): array {
    $r = q('SELECT r.*, u.username, u.name, u.role, u.email, u.student_id FROM password_reset_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$requestId])->fetch();
    if (!$r || $r['status'] !== 'pending') return [false, 'This request is no longer open.'];
    if (!can_approve_reset($actor, $r)) return [false, 'You cannot approve a reset for this account.'];
    $email = account_email($r);
    if ($email === '') return [false, $r['name'] . ' has no valid email on the account. Add it on Team → Manage (or the student profile), or give a temporary password instead.'];
    if (!mail_ready()) return [false, 'Email is not set up yet. Set it up under Team → System → Email, or give a temporary password instead.'];
    $code = (string)random_int(100000, 999999);
    $exp = date('Y-m-d H:i:s', time() + RESET_CODE_MINUTES * 60);
    $txt = "Assalam o Alaikum {$r['name']},\n\nYour password reset was approved.\nYour code: {$code}\n\nOpen " . absolute_url('forgot_password.php?step=code') . " and enter your username and this code within " . RESET_CODE_MINUTES . " minutes.\nIf you did not ask for this, ignore this email and tell the admin.\n\nScholarshipHive Edutancy";
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px"><p>Assalam o Alaikum ' . h($r['name']) . ',</p><p>Your password reset was approved. Your code:</p>'
          . '<p style="font-size:28px;letter-spacing:6px;font-weight:bold;background:#f1f5f9;padding:12px 16px;border-radius:10px;display:inline-block">' . $code . '</p>'
          . '<p>Open <a href="' . h(absolute_url('forgot_password.php?step=code')) . '">the reset page</a> and enter your username and this code within ' . RESET_CODE_MINUTES . ' minutes.</p><p style="color:#64748b">If you did not ask for this, ignore this email and tell the admin.</p></div>';
    [$sent, $err] = send_mail($email, 'Your password reset code', $txt, $html);
    q('UPDATE password_reset_requests SET status = ?, decided_by = ?, decided_at = ?, code_hash = ?, code_expires_at = ?, code_attempts = 0, email_sent_to = ?, email_status = ? WHERE id = ?',
      [$sent ? 'approved' : 'pending', (int)$actor['id'], now(), $sent ? password_hash($code, PASSWORD_DEFAULT) : null, $sent ? $exp : null, $email, $sent ? 'sent' : ('failed: ' . $err), $requestId]);
    if (!$sent) return [false, 'The email could not be sent: ' . $err];
    log_activity('user', (int)$r['user_id'], 'password_reset_approved', null, ['email' => mask_email($email)], (int)$actor['id']);
    return [true, 'Approved. A code was emailed to ' . mask_email($email) . ' (valid ' . RESET_CODE_MINUTES . ' minutes).'];
}

function reject_password_reset(array $actor, int $requestId, string $why): array {
    $r = q('SELECT r.*, u.role FROM password_reset_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$requestId])->fetch();
    if (!$r || $r['status'] !== 'pending') return [false, 'This request is no longer open.'];
    if (!can_approve_reset($actor, ['id' => $r['user_id'], 'role' => $r['role']])) return [false, 'You cannot decide on this account.'];
    q("UPDATE password_reset_requests SET status = 'rejected', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?", [(int)$actor['id'], now(), mb_substr(trim($why), 0, 300) ?: null, $requestId]);
    return [true, 'Request rejected.'];
}

/** Step 3. Username + code -> one-time link token (or an error). */
function verify_reset_code(string $username, string $code): array {
    $user = q('SELECT id FROM users WHERE LOWER(username) = ? AND active = 1', [strtolower(trim($username))])->fetch();
    $r = $user ? q("SELECT * FROM password_reset_requests WHERE user_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [(int)$user['id']])->fetch() : null;
    if (!$r) return [false, 'No approved reset for this username. Check the username, or ask again.'];
    if ((string)$r['code_expires_at'] < now()) { q("UPDATE password_reset_requests SET status = 'expired' WHERE id = ?", [(int)$r['id']]); return [false, 'The code has expired. Please ask for a new reset.']; }
    if ((int)$r['code_attempts'] >= RESET_MAX_CODE_TRIES) { q("UPDATE password_reset_requests SET status = 'expired' WHERE id = ?", [(int)$r['id']]); return [false, 'Too many wrong codes. Please ask for a new reset.']; }
    if (!preg_match('/^\d{6}$/', trim($code)) || !password_verify(trim($code), (string)$r['code_hash'])) {
        q('UPDATE password_reset_requests SET code_attempts = code_attempts + 1 WHERE id = ?', [(int)$r['id']]);
        $left = RESET_MAX_CODE_TRIES - (int)$r['code_attempts'] - 1;
        return [false, 'That code is not correct.' . ($left > 0 ? " $left tries left." : '')];
    }
    $token = bin2hex(random_bytes(32));
    q("UPDATE password_reset_requests SET status = 'code_verified', link_hash = ?, link_expires_at = ? WHERE id = ?",
      [hash('sha256', $token), date('Y-m-d H:i:s', time() + RESET_LINK_MINUTES * 60), (int)$r['id']]);
    return [true, $token];
}

/** The request behind a reset link, if it is still valid. */
function reset_request_for_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $r = q("SELECT r.*, u.username, u.name, u.role FROM password_reset_requests r JOIN users u ON u.id = r.user_id WHERE r.link_hash = ? AND r.status = 'code_verified'", [hash('sha256', $token)])->fetch();
    if (!$r || (string)$r['link_expires_at'] < now()) return null;
    return $r;
}

/** Step 4. */
function complete_password_reset(string $token, string $pw, string $repeat): array {
    $r = reset_request_for_token($token);
    if (!$r) return [false, 'This link has expired or was already used. Please ask for a new reset.'];
    if ($pw !== $repeat) return [false, 'The two passwords do not match.'];
    $problems = password_problems($pw, $r);
    if ($problems) return [false, 'Your password needs ' . implode(', ', $problems) . '.'];
    if (password_recently_used((int)$r['user_id'], $pw)) return [false, 'Choose a password you have not used recently.'];
    set_user_password((int)$r['user_id'], $pw);
    q("UPDATE password_reset_requests SET status = 'completed', completed_at = ?, completed_ip = ?, link_hash = NULL WHERE id = ?", [now(), client_ip(), (int)$r['id']]);
    log_activity('user', (int)$r['user_id'], 'password_reset_completed', null, ['ip' => client_ip()], (int)$r['user_id']);
    notify_reset_approvers('Password was reset', $r['name'] . ' set a new password through the approved reset.');
    try { staff_log((int)$r['user_id'], 'password', null, 'Password reset by request (code emailed)', 0, null, (int)$r['user_id']); } catch (Throwable) {}
    return [true, 'Your password has been changed. Sign in with your new password.'];
}

function reset_requests_list(int $limit = 40): array {
    return q('SELECT r.*, u.name, u.username, u.role, d.name AS decided_by_name FROM password_reset_requests r JOIN users u ON u.id = r.user_id
              LEFT JOIN users d ON d.id = r.decided_by ORDER BY CASE r.status WHEN \'pending\' THEN 0 ELSE 1 END, r.requested_at DESC LIMIT ' . (int)$limit)->fetchAll();
}
