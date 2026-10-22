<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$user = require_api_user();
$input = api_input();

$studentId = (int)($input['student_id'] ?? ($input['id'] ?? 0));
$status = strtolower(trim((string)($input['status'] ?? ($input['student_status'] ?? ''))));
$reason = trim((string)($input['reason'] ?? ($input['disabled_reason'] ?? '')));

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing valid student_id.']);
    exit;
}

if (!in_array($status, ['active', 'inactive', 'disabled'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid status. Must be active, inactive, or disabled.']);
    exit;
}

// Ensure caller is in scope
try {
    find_student($user, $studentId);
} catch (Throwable) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Student not found or access denied.']);
    exit;
}

// Call backend service
$res = change_student_status($user, $studentId, $status, $reason !== '' ? $reason : null);

if (!$res['ok']) {
    http_response_code($res['code'] ?? 400);
    echo json_encode([
        'success' => false,
        'error'   => $res['error'] ?? 'Failed to update student status.',
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'message'    => 'Student status successfully updated to ' . ucfirst($status) . '.',
    'student_id' => $studentId,
    'status'     => $status,
]);
