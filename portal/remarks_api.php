<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u || !is_staff_role($u['role'] ?? null)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (is_post()) {
    csrf_check();
    $sid = (int)post('student_id');
    $text = post('remark_text');
    if ($sid <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid student ID']);
        exit;
    }
    // Check scope access
    [$w, $p] = scope_sql($u);
    $p[] = $sid;
    $st = q("SELECT id, name FROM students s WHERE ($w) AND s.id = ?", $p)->fetch();
    if (!$st) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Student not found or out of scope']);
        exit;
    }

    $res = add_student_remark($u, $sid, $text);
    if (!$res['ok']) {
        http_response_code($res['code'] ?? 400);
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Failed to add remark']);
        exit;
    }

    $remarks = get_student_remarks($sid);
    foreach ($remarks as &$rem) {
        $rem['date_formatted'] = fmt_date(substr((string)$rem['created_at'], 0, 10));
        $rem['time_ago'] = time_ago((string)$rem['created_at']);
    }
    unset($rem);

    echo json_encode([
        'ok'           => true,
        'student_id'   => $sid,
        'student_name' => $st['name'],
        'remarks'      => $remarks,
        'total'        => count($remarks),
    ]);
    exit;
}

$sid = (int)get('student_id');
if ($sid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid student ID']);
    exit;
}

[$w, $p] = scope_sql($u);
$p[] = $sid;
$st = q("SELECT id, name, remarks, counselor, country, program_level FROM students s WHERE ($w) AND s.id = ?", $p)->fetch();
if (!$st) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Student not found']);
    exit;
}

$remarks = get_student_remarks($sid);
if (empty($remarks) && !empty(trim((string)$st['remarks']))) {
    // Fallback if legacy remark exists in students.remarks column only
    $remarks[] = [
        'id'             => 0,
        'student_id'     => $sid,
        'remark_text'    => trim((string)$st['remarks']),
        'author_id'      => null,
        'author_name'    => 'Sheet / Legacy Remark',
        'author_role'    => 'staff',
        'created_at'     => '',
        'date_formatted' => 'Imported',
        'time_ago'       => '',
    ];
} else {
    foreach ($remarks as &$rem) {
        $rem['date_formatted'] = fmt_date(substr((string)$rem['created_at'], 0, 10));
        $rem['time_ago'] = time_ago((string)$rem['created_at']);
    }
    unset($rem);
}

echo json_encode([
    'ok'           => true,
    'student_id'   => $sid,
    'student_name' => $st['name'],
    'country'      => $st['country'] ?: 'Italy',
    'program_level'=> $st['program_level'] ?: '',
    'remarks'      => $remarks,
    'total'        => count($remarks),
]);
