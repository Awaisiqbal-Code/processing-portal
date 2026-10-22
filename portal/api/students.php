<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_api_user();

$input = api_input();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once APP . '/services.php';
    $res = create_student($user, $input);
    http_response_code($res['code']);
    echo json_encode([
        'success'    => $res['ok'],
        'student_id' => $res['id'] ?? null,
        'message'    => $res['ok'] ? 'Student created successfully.' : ($res['error'] ?? 'Error creating student.'),
    ]);
    exit;
}

[$w, $p] = scope_sql($user);
$tab = strtolower((string)($input['tab'] ?? 'all'));
$q = trim((string)($input['q'] ?? ($input['search'] ?? '')));
$country = trim((string)($input['country'] ?? ''));
$status = trim((string)($input['status'] ?? ''));
$verified = (string)($input['verified'] ?? '');
$refunded = (string)($input['refunded'] ?? '');
$pending = (string)($input['pending'] ?? '');
$page = max(1, (int)($input['page'] ?? 1));
$perPage = min(100, max(1, (int)($input['per_page'] ?? 50)));

$where = [$w];
$params = $p;

// Tab condition
if ($tab === 'bs') {
    $where[] = "s.program_level = 'BS'";
} elseif ($tab === 'ms') {
    $where[] = "s.program_level = 'MS'";
} elseif ($tab === 'incomplete') {
    $where[] = "(s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '')";
}

// Search
if ($q !== '') {
    $where[] = '(s.name LIKE ? OR s.counselor LIKE ? OR s.remarks LIKE ? OR s.email LIKE ? OR CAST(s.id AS TEXT) LIKE ? OR CAST(s.sheet_sr AS TEXT) LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

// Country
if ($country !== '') {
    if ($country === 'none') {
        $where[] = "(s.country IS NULL OR s.country = '')";
    } else {
        $where[] = "s.country = ?";
        $params[] = $country;
    }
}

// Status
if ($status !== '' && in_array($status, ['active', 'inactive', 'disabled'], true)) {
    $where[] = "s.student_status = ?";
    $params[] = $status;
}

// Verification
if ($verified === '1') {
    $where[] = "(s.csc_status = 'Verified' OR s.csc_verified_at IS NOT NULL)";
} elseif ($verified === '0') {
    $where[] = "((s.csc_status != 'Verified' OR s.csc_status IS NULL) AND s.csc_verified_at IS NULL)";
}

// Refunded
if ($refunded === '1') {
    $where[] = "s.is_refunded = 1";
} elseif ($refunded === '0') {
    $where[] = "(s.is_refunded = 0 OR s.is_refunded IS NULL)";
}

// Pending
if ($pending === '1') {
    $where[] = "s.pending_since IS NOT NULL";
} elseif ($pending === '0') {
    $where[] = "s.pending_since IS NULL";
}

$whereSql = implode(' AND ', $where);

// Total count
$total = (int)q("SELECT COUNT(*) FROM students s WHERE $whereSql", $params)->fetchColumn();
$offset = ($page - 1) * $perPage;

// Fetch page
$students = q("SELECT s.*, o.name AS officer_name, fo.name AS frontend_officer_full_name, pms.name AS pms_lead_name,
                      CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END AS is_pending
               FROM students s
               LEFT JOIN users o ON o.id = s.officer_id
               LEFT JOIN users fo ON fo.id = s.frontend_officer_id
               LEFT JOIN users pms ON pms.id = s.assigned_pms_lead_id
               WHERE $whereSql
               ORDER BY s.id ASC
               LIMIT ? OFFSET ?", array_merge($params, [$perPage, $offset]))->fetchAll();

echo json_encode([
    'success'  => true,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    'pages'    => max(1, (int)ceil($total / $perPage)),
    'students' => $students,
]);
