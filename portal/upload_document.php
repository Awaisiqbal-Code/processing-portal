<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/stages.php';

$u = current_user();
if (!$u) {
    redirect('student/login.php');
}

$action = (string)(post('action') ?: get('action') ?: 'upload');
$token = (string)(post('_csrf') ?: get('csrf') ?: ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

// CSRF check
if (!verify_csrf($token)) {
    flash('Security session expired. Please try again.', 'bad');
    redirect_back();
}

$studentId = (int)(post('student_id') ?: get('student_id') ?: 0);
$cid = (int)(post('cid') ?: get('cid') ?: 0);

// If deleting custom doc and studentId not specified, lookup student_id from cid
if ($action === 'delete_custom' && $cid > 0 && $studentId <= 0) {
    $studentId = (int)q("SELECT student_id FROM custom_documents WHERE id = ?", [$cid])->fetchColumn();
}

if ($studentId <= 0) {
    flash('Invalid student ID.', 'bad');
    redirect_back();
}

// Authorize
if ($u['role'] === 'student') {
    if ((int)($u['student_id'] ?? 0) !== $studentId) {
        http_response_code(403);
        exit('Access denied.');
    }
} elseif (is_officer_role($u['role'])) {
    [$w, $p] = scope_sql($u, 'students');
    $allowed = q("SELECT id FROM students WHERE id = ? AND $w", array_merge([$studentId], $p))->fetchColumn();
    if (!$allowed) {
        http_response_code(403);
        exit('Access denied.');
    }
} elseif (!is_admin_role($u['role'])) {
    http_response_code(403);
    exit('Access denied.');
}
// Admin / PMS Lead without edit access on this student: view only
$editRow = q('SELECT id, officer_id, frontend_officer_id FROM students WHERE id = ?', [$studentId])->fetch();
if ($editRow && staff_edit_blocked($u, $editRow)) {
    flash(view_only_message('students.edit_all'), 'bad');
    redirect_back();
}

// 1. Delete Custom Document
if ($action === 'delete_custom') {
    $cd = q("SELECT * FROM custom_documents WHERE id = ? AND student_id = ?", [$cid, $studentId])->fetch();
    if ($cd) {
        if (!empty($cd['file_path']) && is_file(ROOT . '/' . ltrim($cd['file_path'], '/\\'))) {
            @unlink(ROOT . '/' . ltrim($cd['file_path'], '/\\'));
        }
        q("DELETE FROM custom_documents WHERE id = ?", [$cid]);
        log_action("Deleted custom document: " . $cd['title'], $studentId);
        flash("Custom document '{$cd['title']}' removed.", 'ok');
    }
    redirect_back();
}

// 1b. Mark Document Status (Staff can mark a required document as Received / Verified / Not Required / Pending without file upload)
if ($action === 'set_doc_status' && is_staff_role($u['role'])) {
    $docIndex = (int)post('doc_index');
    $newStatus = trim((string)post('doc_status'));
    if ($newStatus === 'Verified' && !can_verify_docs($u)) {
        flash('Only the PMS Lead (or an admin with “Verify documents” access) can mark documents Verified.', 'bad');
        redirect_back();
    }
    if (isset(DOCS[$docIndex]) && in_array($newStatus, ['Pending', 'Requested', 'Received', 'Verified'], true)) {
        $existing = q("SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?", [$studentId, $docIndex])->fetch();
        if ($existing) {
            q("UPDATE student_documents SET status = ?, updated_by = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?", [$newStatus, (int)$u['id'], now(), $studentId, $docIndex]);
        } else {
            q("INSERT INTO student_documents (student_id, doc_index, status, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)", [$studentId, $docIndex, $newStatus, (int)$u['id'], now()]);
        }
        sync_docs_checklist($studentId);
        log_action("Marked document '" . DOCS[$docIndex] . "' as {$newStatus}", $studentId);
        flash("Document '" . DOCS[$docIndex] . "' marked as {$newStatus}.", 'ok');
    }
    redirect_back();
}

// 1c. Mark All Core Required Documents as Received (Clears pending document count banner)
if ($action === 'mark_all_docs_received' && is_staff_role($u['role'])) {
    foreach (DOCS as $idx => $dName) {
        if (in_array($idx, [9, 10, 11], true)) continue;
        $existing = q("SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?", [$studentId, $idx])->fetch();
        if ($existing) {
            if (!in_array($existing['status'] ?? '', ['Received', 'Verified'], true)) {
                q("UPDATE student_documents SET status = 'Received', updated_at = ? WHERE student_id = ? AND doc_index = ?", [now(), $studentId, $idx]);
            }
        } else {
            q("INSERT INTO student_documents (student_id, doc_index, status, updated_at) VALUES (?, ?, 'Received', ?)", [$studentId, $idx, now()]);
        }
    }
    q("UPDATE students SET docs_checklist = 'Yes' WHERE id = ?", [$studentId]);
    log_action("Marked all core required documents as Received", $studentId);
    flash("All core required documents marked as Received.", 'ok');
    redirect_back();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 2. Upload Document
if (empty($_FILES['file']['name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    flash('Please select a valid file to upload.', 'bad');
    redirect_back();
}

$file = $_FILES['file'];
$origName = basename((string)$file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'];

if (!in_array($ext, $allowedExts, true)) {
    flash('Invalid file type. Allowed formats: PDF, JPG, PNG, DOC, DOCX.', 'bad');
    redirect_back();
}

if ($file['size'] > 25 * 1024 * 1024) {
    flash('File exceeds maximum upload size (25MB).', 'bad');
    redirect_back();
}

$targetDir = ROOT . '/uploads/documents/' . $studentId;
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$safeDiskName = 'up_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $targetDir . '/' . $safeDiskName;
$relPath = 'uploads/documents/' . $studentId . '/' . $safeDiskName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    flash('Failed to save uploaded file. Check server permissions.', 'bad');
    redirect_back();
}

$fileSize = (int)$file['size'];
$mimeType = mime_content_type($destPath) ?: 'application/octet-stream';

// Invalidate cached reports for this student so reports update immediately
$stCacheDir = ROOT . '/uploads/cache/' . $studentId;
if (is_dir($stCacheDir)) {
    $files = glob($stCacheDir . '/*');
    foreach ($files as $cf) {
        if (is_file($cf)) @unlink($cf);
    }
}

$docIndex = isset($_POST['doc_index']) && $_POST['doc_index'] !== '' ? (int)$_POST['doc_index'] : -1;
$customTitle = trim((string)post('custom_title'));

if ($docIndex >= 0 && isset(DOCS[$docIndex])) {
    $docName = DOCS[$docIndex];
    $existing = q("SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?", [$studentId, $docIndex])->fetch();
    if ($existing) {
        if (!empty($existing['file_path']) && !str_starts_with($existing['file_path'], 'gdrive:') && is_file(ROOT . '/' . ltrim($existing['file_path'], '/\\'))) {
            @unlink(ROOT . '/' . ltrim($existing['file_path'], '/\\'));
        }
        q("UPDATE student_documents SET status = 'Received', file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_at = ?, uploaded_by = ?, updated_by = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?",
          [$relPath, $origName, $fileSize, $mimeType, now(), $u['id'], $u['id'], now(), $studentId, $docIndex]);
    } else {
        q("INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, file_size, mime_type, uploaded_at, uploaded_by, updated_by, updated_at) VALUES (?, ?, 'Received', ?, ?, ?, ?, ?, ?, ?, ?)",
          [$studentId, $docIndex, $relPath, $origName, $fileSize, $mimeType, now(), $u['id'], $u['id'], now()]);
    }
    // Auto sync student stage if relevant document was uploaded
    if ($docIndex === 9) {
        q("UPDATE students SET offer = 'Yes', updated_at = ? WHERE id = ?", [now(), $studentId]);
    } elseif ($docIndex === 10) {
        q("UPDATE students SET enrollment = 'Yes', updated_at = ? WHERE id = ?", [now(), $studentId]);
    } elseif ($docIndex === 11) {
        q("UPDATE students SET visa = 'Granted', updated_at = ? WHERE id = ?", [now(), $studentId]);
    }
    sync_docs_checklist($studentId);
    log_action("Uploaded document: {$docName}", $studentId);
    flash("Document '{$docName}' uploaded successfully!", 'ok');
} elseif ($customTitle !== '') {
    q("INSERT INTO custom_documents (student_id, title, file_path, file_name, file_size, mime_type, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
      [$studentId, $customTitle, $relPath, $origName, $fileSize, $mimeType, now(), $u['id']]);
    log_action("Uploaded custom document: {$customTitle}", $studentId);
    flash("Additional document '{$customTitle}' added successfully!", 'ok');
} else {
    flash('Document saved.', 'ok');
}

redirect_back();

function redirect_back(): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && str_contains($ref, $_SERVER['HTTP_HOST'] ?? '')) {
        header('Location: ' . $ref);
        exit;
    }
    header('Location: ' . url('officer/index.php'));
    exit;
}
