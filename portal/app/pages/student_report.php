<?php
declare(strict_types=1);

require_once APP . '/reports.php';

// Expects $u and $sid
$format = strtolower(get('format') ?: 'pdf');
if (!in_array($format, ['pdf', 'csv'], true)) {
    http_response_code(400);
    exit('Invalid format. Choose pdf or csv.');
}

$isStaff = in_array($u['role'], ['admin', 'officer'], true);

// Fetch student safely
if ($u['role'] === 'student') {
    $sid = (int)($u['student_id'] ?? 0);
}
if ($sid <= 0) {
    http_response_code(400);
    exit('Invalid student ID.');
}

$r = find_student($u, $sid); // Scoped to role, throws 404 if not found or unauthorized

// Fetch documents with file info
$docRows = q('SELECT * FROM student_documents WHERE student_id = ?', [$sid])->fetchAll();
$docs = [];
foreach ($docRows as $d) {
    $docs[(int)$d['doc_index']] = $d;
}

// Fetch recent activity
$activity = q('SELECT action, created_at FROM activity_log WHERE student_id = ? ORDER BY id DESC LIMIT 10', [$sid])->fetchAll();

log_action("Exported student {$format} report", $sid);

$cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $r['name']);

if ($format === 'pdf') {
    $content = compile_full_student_dossier($sid, $isStaff);
    if (!$content) {
        $content = generate_student_pdf($r, $docs, $activity, $isStaff);
    }
    $filename = "Student_Complete_Dossier_{$sid}_{$cleanName}.pdf";
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)strlen($content));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $content;
    exit;
} else {
    $content = generate_student_csv($r, $docs, $isStaff);
    $filename = "Student_Data_{$sid}_{$cleanName}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)strlen($content));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $content;
    exit;
}
