<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$u = current_user();
if (!$u) {
    http_response_code(401);
    exit('Not authenticated.');
}

$type = get('type'); // 'template' or 'doc'
$id = (int)get('id');
$isDownload = get('download') === '1';

if ($type === 'template') {
    $t = q("SELECT * FROM consent_templates WHERE id = ?", [$id])->fetch();
    if (!$t) {
        http_response_code(404);
        exit('Template not found.');
    }
    $filePath = (string)$t['file_path'];
    $fileName = (string)$t['original_name'];
    $mimeType = (string)$t['mime_type'];
} elseif ($type === 'doc') {
    $d = q("SELECT * FROM consent_documents WHERE id = ?", [$id])->fetch();
    if (!$d) {
        http_response_code(404);
        exit('Consent document not found.');
    }
    $sid = (int)$d['student_id'];
    // Permission check
    if ($u['role'] === 'student') {
        if ((int)($u['student_id'] ?? 0) !== $sid) {
            http_response_code(403);
            exit('Access denied.');
        }
    } else {
        // Staff role scope check
        find_student($u, $sid);
    }
    $filePath = (string)$d['file_path'];
    $fileName = (string)$d['original_name'];
    $mimeType = (string)$d['mime_type'];
} else {
    http_response_code(400);
    exit('Invalid download type.');
}

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Physical document file not found on server.');
}

header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($filePath));
$disp = $isDownload ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($fileName) . '"');
header('Cache-Control: private, max-age=3600');

readfile($filePath);
exit;
