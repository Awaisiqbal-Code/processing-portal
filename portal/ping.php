<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$u = current_user();
if (!$u || !is_staff_role($u['role'] ?? null)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$role = $u['role'];
$idleLogoutSeconds = max(60, (int)setting('idle_logout_minutes', $CONFIG['idle_logout_minutes'] ?? 20) * 60);
$last = $_SESSION['last'][$role] ?? $_SESSION['staff_last_active'] ?? 0;

if ($last && (time() - $last) >= $idleLogoutSeconds) {
    logout_now(null);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Logged out due to inactivity', 'expired' => true]);
    exit;
}

$nowTs = time();
$_SESSION['last'][$role] = $nowTs;
$_SESSION['staff_last_active'] = $nowTs;

echo json_encode([
    'ok'                  => true,
    'last_active'         => $nowTs,
    'idle_warning_minutes'=> (int)setting('idle_warning_minutes', $CONFIG['idle_warning_minutes'] ?? 15),
    'idle_logout_minutes' => (int)setting('idle_logout_minutes', $CONFIG['idle_logout_minutes'] ?? 20),
]);
