<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_api_user();
$input = api_input();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $studentId = (int)($input['student_id'] ?? ($input['id'] ?? 0));
    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing student ID.']);
        exit;
    }

    try {
        find_student($user, $studentId);
    } catch (Throwable) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found or access denied.']);
        exit;
    }

    $remarks = get_student_remarks($studentId);
    echo json_encode([
        'success' => true,
        'student_id' => $studentId,
        'remarks' => $remarks,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int)($input['student_id'] ?? ($input['id'] ?? 0));
    $text = trim((string)($input['remark'] ?? ($input['remark_text'] ?? ($input['text'] ?? ''))));

    if ($studentId <= 0 || $text === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'student_id and remark text are required.']);
        exit;
    }

    try {
        find_student($user, $studentId);
    } catch (Throwable) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student not found or access denied.']);
        exit;
    }

    $res = add_student_remark($user, $studentId, $text);

    if (!$res['ok']) {
        http_response_code($res['code'] ?? 400);
        echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Could not add remark.']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success'   => true,
        'message'   => 'Remark added successfully.',
        'remark_id' => $res['remark_id'],
        'student_id'=> $studentId,
        'author'    => $res['author_name'],
        'created_at'=> $res['created_at'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
