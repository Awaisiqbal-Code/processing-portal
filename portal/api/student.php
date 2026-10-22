<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_api_user();
$input = api_input();
$id = (int)($input['id'] ?? ($input['student_id'] ?? 0));

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing student ID.']);
    exit;
}

try {
    $student = find_student($user, $id);
} catch (Throwable) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Student record not found or access denied.']);
    exit;
}

// Fetch documents
$documents = q("SELECT doc_index, status, file_name, file_size, file_path, uploaded_at FROM student_documents WHERE student_id = ? ORDER BY doc_index ASC", [$id])->fetchAll();

// Fetch remarks
$remarks = get_student_remarks($id);

// Fetch consent documents
$consentDocs = q("SELECT id, template_id, original_name, mime_type, file_size, created_at FROM consent_documents WHERE student_id = ? ORDER BY created_at DESC", [$id])->fetchAll();

echo json_encode([
    'success'           => true,
    'student'           => $student,
    'documents'         => $documents,
    'remarks'           => $remarks,
    'consent_documents' => $consentDocs,
]);
