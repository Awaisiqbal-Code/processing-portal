<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// GET  ?check=1  → background session check (does NOT extend the idle timer)
// POST          → keep-alive from real user activity (extends the idle timer)
$isCheckOnly = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !empty($_SERVER['HTTP_X_BACKGROUND_POLL']);

$u = current_user();
if (!$u || !is_staff_role($u['role'] ?? null)) {
    $replaced = !empty($_SESSION['session_replaced']);
    http_response_code(401);
    echo json_encode($replaced
        ? ['ok' => false, 'reason' => 'replaced', 'error' => 'Your account was signed in on another device.']
        : ['ok' => false, 'reason' => 'expired', 'error' => 'Not signed in']);
    exit;
}

$idleLogoutSeconds = max(60, (int)setting('idle_logout_minutes', $CONFIG['idle_logout_minutes'] ?? 20) * 60);

// Most recent activity across all signed-in slots (the same value require_role() checks)
$last = 0;
foreach ($_SESSION['auth'] ?? [] as $sess) {
    $last = max($last, (int)($sess['last'] ?? 0));
}
if (!$last) {
    $last = (int)($_SESSION['staff_last_active'] ?? 0);
}

if ($last && (time() - $last) >= $idleLogoutSeconds) {
    logout_now(null);
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'idle', 'error' => 'Logged out due to inactivity', 'expired' => true]);
    exit;
}

$nowTs = time();
if (!$isCheckOnly) {
    // Keep-alive: refresh every signed-in slot so the server timer matches the browser timer.
    foreach (array_keys($_SESSION['auth'] ?? []) as $slot) {
        $_SESSION['auth'][$slot]['last'] = $nowTs;
    }
    $_SESSION['last'][$u['role']] = $nowTs;
    $_SESSION['staff_last_active'] = $nowTs;
}

echo json_encode([
    'ok'                   => true,
    'last_active'          => $isCheckOnly ? $last : $nowTs,
    'idle_warning_minutes' => (int)setting('idle_warning_minutes', $CONFIG['idle_warning_minutes'] ?? 15),
    'idle_logout_minutes'  => (int)setting('idle_logout_minutes', $CONFIG['idle_logout_minutes'] ?? 20),
]);
