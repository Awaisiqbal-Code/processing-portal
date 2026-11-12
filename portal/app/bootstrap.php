<?php
declare(strict_types=1);

// Always open our own (unlimited) output buffer. Hosts often run with output_buffering=4096, which
// flushes headers part-way through a page — the session cookie then never updates and flash
// messages ("Student saved.") kept re-appearing on every page.
if (PHP_SAPI !== 'cli') {
    ob_start();
}

define('APP', __DIR__);
define('ROOT', dirname(__DIR__));

$CONFIG = require APP . '/config.php';
if (is_file(APP . '/config.local.php')) {
    $CONFIG = array_merge($CONFIG, require APP . '/config.local.php');
}

date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

// ---------- Production Condition 1 & 3: Railway Persistent Volume Check ----------
$isRailwayEnv = (bool)(getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_ID'));
$railwayVolumeDir = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: '/data';
$GLOBALS['RAILWAY_VOLUME_MISSING'] = false;

if ($isRailwayEnv) {
    if (!is_dir($railwayVolumeDir) || !is_writable($railwayVolumeDir)) {
        $GLOBALS['RAILWAY_VOLUME_MISSING'] = true;
        // Block non-GET write requests in production when volume is missing
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && php_sapi_name() !== 'cli') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Railway persistent volume /data is not mounted or writable. Writes are disabled to prevent data loss.'
            ]);
            exit;
        }
    }
}

// ---------- security headers ----------
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
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
/** Asset URL with a version stamp, so browsers pick up new CSS / JS right after an update (no Ctrl+F5 needed). */
function asset_url(string $path): string {
    static $v = [];
    if (!isset($v[$path])) {
        $f = ROOT . '/' . ltrim($path, '/');
        $v[$path] = is_file($f) ? substr(md5((string)filesize($f) . '|' . (string)filemtime($f)), 0, 10) : '0';
    }
    return url($path) . '?v=' . $v[$path];
}

// ---------- session ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
      || (bool)getenv('VERCEL');
if ($https && !headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000');
}

require_once APP . '/turso.php';
if (turso_enabled()) {
    // Sessions live in the shared online database, so every server copy sees the same sign-in.
    session_set_save_handler(new TursoSessionHandler(), true);
} elseif (getenv('VERCEL') && is_writable(sys_get_temp_dir())) {
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
// Keep server-side session files at least as long as the staff idle timeout (some hosts default to 24 min)
ini_set('session.gc_maxlifetime', '14400');
ini_set('session.use_only_cookies', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Stateless HMAC-signed cookie fallback for serverless environments (Vercel)
// The signing secret must NOT live in the code: set PORTAL_COOKIE_SECRET in the host's environment variables.
// Until it is set, the old built-in value is used so nobody is signed out.
define('PTP_COOKIE_SECRET', (string)(getenv('PORTAL_COOKIE_SECRET') ?: getenv('PORTAL_APP_KEY') ?: 'ptp_vercel_hmac_key_2026_9f8e7d6c5b4a3'));
define('PTP_COOKIE_SECRET_FROM_ENV', (bool)(getenv('PORTAL_COOKIE_SECRET') ?: getenv('PORTAL_APP_KEY')));
if (turso_enabled() && !empty($_COOKIE['PTPSESSDATA']) && !headers_sent()) {
    // not needed with shared sessions: remove the old signed cookie
    setcookie('PTPSESSDATA', '', ['expires' => 1, 'path' => base_url() === '' ? '/' : base_url() . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
if (!turso_enabled() && !empty($_COOKIE['PTPSESSDATA'])) {
    $parts = explode('.', (string)$_COOKIE['PTPSESSDATA'], 2);
    if (count($parts) === 2 && hash_equals(hash_hmac('sha256', $parts[0], PTP_COOKIE_SECRET), $parts[1])) {
        $decoded = json_decode((string)base64_decode($parts[0], true), true);
        // "Password already set in this sign-in" always carries over, so no server copy asks for it again.
        if (is_array($decoded) && !empty($decoded['pw_set']) && is_array($decoded['pw_set'])) {
            $_SESSION['pw_set'] = ($_SESSION['pw_set'] ?? []) + array_filter($decoded['pw_set']);
        }
        // Only fall back to the signed cookie when the server-side session is empty (serverless cold start).
        $nativeSessionAlive = !empty($_SESSION['csrf']) || !empty($_SESSION['auth']);
        if (is_array($decoded) && !$nativeSessionAlive) {
            foreach (['auth', 'last_login_role', 'csrf', 'flash', 'last', 'staff_last_active', 'pw_set'] as $k) {
                if (empty($_SESSION[$k]) && isset($decoded[$k])) {
                    $_SESSION[$k] = $decoded[$k];
                }
            }
            // Inactivity check on restored cookie session for staff roles (Fix 6)
            $staffRoles = ['super_admin', 'admin', 'pms_lead', 'processing_staff', 'officer', 'counselor'];
            $idleTimeoutSec = 20 * 60; // 20 minutes
            $now = time();
            foreach ($staffRoles as $sr) {
                if (!empty($_SESSION['auth'][$sr])) {
                    $lastActive = $_SESSION['last'][$sr] ?? $_SESSION['staff_last_active'] ?? 0;
                    if ($lastActive > 0 && ($now - $lastActive) >= $idleTimeoutSec) {
                        unset($_SESSION['auth'][$sr]);
                        unset($_SESSION['last'][$sr]);
                        $_SESSION['idle_logged_out'] = true;
                    }
                }
            }
        }
    }
}

if (!turso_enabled()) register_shutdown_function(function () use ($https): void {
    if (!headers_sent()) {
        $payload = [
            'auth'              => $_SESSION['auth'] ?? [],
            'last_login_role'   => $_SESSION['last_login_role'] ?? null,
            'csrf'              => $_SESSION['csrf'] ?? null,
            'flash'             => $_SESSION['flash'] ?? [],
            'last'              => $_SESSION['last'] ?? [],
            'staff_last_active' => $_SESSION['staff_last_active'] ?? null,
            'pw_set'            => $_SESSION['pw_set'] ?? [],
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
require APP . '/services.php';
require APP . '/workflow.php';
require APP . '/staff.php';
require APP . '/quick_actions.php';
require APP . '/password.php';
require APP . '/assistant.php';

// Apply pending additive schema migrations automatically (once) so a plain `php -S` run works too.
if (PHP_SAPI !== 'cli' && empty($GLOBALS['MIGRATION_RUNNING'])) {
    try {
        $latestMig = basename((string)(array_slice(glob(APP . '/migrations/*.php') ?: [], -1)[0] ?? ''), '.php');
        $migMarker = turso_enabled() ? turso_marker('mig_' . $latestMig) : null;
        $hasMig = ($migMarker && is_file($migMarker))
            || (q("SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn()
                && q("SELECT 1 FROM schema_migrations WHERE version = ?", [$latestMig])->fetchColumn());
        if ($hasMig && $migMarker && !is_file($migMarker)) @touch($migMarker);
        if (!$hasMig) {
            require_once APP . '/migrate.php';
            ob_start();
            run_migrations(false);
            ob_end_clean();
            $GLOBALS['MIGRATION_RUNNING'] = false;
        }
    } catch (Throwable $e) {
        if (ob_get_level() > 1) { @ob_end_clean(); }
        error_log('Auto-migration failed: ' . $e->getMessage());
    }
}
