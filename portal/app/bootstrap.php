<?php
declare(strict_types=1);

if (!ob_get_level()) {
    ob_start();
}

define('APP', __DIR__);
define('ROOT', dirname(__DIR__));

$CONFIG = require APP . '/config.php';
if (is_file(APP . '/config.local.php')) {
    $CONFIG = array_merge($CONFIG, require APP . '/config.local.php');
}

date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

// ---------- security headers ----------
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

// ---------- base URL ----------
function base_url(): string {
    global $CONFIG;
    static $b = null;
    if ($b !== null) return $b;
    if (getenv('VERCEL')) return $b = '';
    if (!empty($CONFIG['base_url'])) return $b = rtrim($CONFIG['base_url'], '/');
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = realpath(ROOT) ?: ROOT;
    $b = '';
    if ($doc !== '' && str_starts_with($root, $doc)) {
        $b = str_replace('\\', '/', substr($root, strlen($doc)));
    }
    return $b = rtrim($b, '/');
}
function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }

// ---------- session ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
      || (bool)getenv('VERCEL');
if ($https && !headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000');
}

if (getenv('VERCEL') && is_writable(sys_get_temp_dir())) {
    session_save_path(sys_get_temp_dir());
}

// Standard, single session cookie across all portals and routes
session_name('PTPSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => base_url() === '' ? '/' : base_url() . '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Stateless HMAC-signed cookie fallback for serverless environments (Vercel)
const PTP_COOKIE_SECRET = 'ptp_vercel_hmac_key_2026_9f8e7d6c5b4a3';
if (!empty($_COOKIE['PTPSESSDATA'])) {
    $parts = explode('.', (string)$_COOKIE['PTPSESSDATA'], 2);
    if (count($parts) === 2 && hash_equals(hash_hmac('sha256', $parts[0], PTP_COOKIE_SECRET), $parts[1])) {
        $decoded = json_decode((string)base64_decode($parts[0], true), true);
        if (is_array($decoded)) {
            foreach (['auth', 'last_login_role', 'csrf', 'flash'] as $k) {
                if (empty($_SESSION[$k]) && isset($decoded[$k])) {
                    $_SESSION[$k] = $decoded[$k];
                }
            }
        }
    }
}

register_shutdown_function(function () use ($https): void {
    if (!headers_sent()) {
        $payload = [
            'auth'            => $_SESSION['auth'] ?? [],
            'last_login_role' => $_SESSION['last_login_role'] ?? null,
            'csrf'            => $_SESSION['csrf'] ?? null,
            'flash'           => $_SESSION['flash'] ?? [],
        ];
        $b64 = base64_encode((string)json_encode($payload));
        $sig = hash_hmac('sha256', $b64, PTP_COOKIE_SECRET);
        setcookie('PTPSESSDATA', $b64 . '.' . $sig, [
            'expires'  => 0,
            'path'     => base_url() === '' ? '/' : base_url() . '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
});

require APP . '/db.php';
require APP . '/helpers.php';
require APP . '/stages.php';
require APP . '/auth.php';
