<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$uid = (int)$u['id'];

if (is_post()) {
    $action = post('action');
    if ($action === 'mark_read') {
        $nid = (int)post('id');
        mark_notification_read($uid, $nid);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'mark_all_read') {
        mark_all_notifications_read($uid);
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

$unreadOnly = get('unread') === '1';
$limit = max(1, min(100, (int)get('limit', 50)));
$notifications = list_notifications($uid, $unreadOnly, $limit);
$unreadCount = (int)q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$uid])->fetchColumn();

echo json_encode([
    'ok'           => true,
    'unread_count' => $unreadCount,
    'data'         => $notifications,
]);
