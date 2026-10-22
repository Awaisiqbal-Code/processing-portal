<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_api_user();
$input = api_input();

$studentId = (int)($input['student_id'] ?? ($input['id'] ?? 0));
if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID.']);
    exit;
}

try {
    $st = find_student($user, $studentId);
} catch (Throwable) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Student not found or access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $docs = q("SELECT doc_index, status, file_name, file_size, mime_type, uploaded_at FROM student_documents WHERE student_id = ? ORDER BY doc_index ASC", [$studentId])->fetchAll();
    $consents = q("SELECT id, template_id, original_name, mime_type, file_size, created_at FROM consent_documents WHERE student_id = ? ORDER BY created_at DESC", [$studentId])->fetchAll();

    echo json_encode([
        'success'           => true,
        'student_id'        => $studentId,
        'documents'         => $docs,
        'consent_documents' => $consents,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a consent upload
    if (!empty($_FILES['consent_file'])) {
        $tplId = !empty($input['template_id']) ? (int)$input['template_id'] : null;
        $res = upload_consent_document($user, $studentId, $_FILES['consent_file'], $tplId);
        if (!$res['ok']) {
            http_response_code($res['code'] ?? 400);
            echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Upload failed.']);
            exit;
        }
        echo json_encode([
            'success'     => true,
            'message'     => 'Consent document uploaded successfully.',
            'document_id' => $res['document_id'],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid document file provided in request.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
