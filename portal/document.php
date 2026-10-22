<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/stages.php';
require_once __DIR__ . '/app/drive_resolver.php';

$u = current_user();
if (!$u) {
    redirect('student/login.php');
}

$studentId = (int)($_GET['id'] ?? 0);
$docIndex  = isset($_GET['doc']) ? (int)$_GET['doc'] : -1;
$customDocId = (int)($_GET['cid'] ?? 0);
$directFid = trim((string)($_GET['fid'] ?? ''));
$directName = trim((string)($_GET['name'] ?? 'document.pdf'));
$forceDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($studentId <= 0) {
    http_response_code(400);
    exit('Invalid student parameters.');
}

// Verify role-based authorization for this student record
if ($u['role'] === 'student') {
    if ((int)($u['student_id'] ?? 0) !== $studentId) {
        http_response_code(403);
        exit('Access denied.');
    }
    $s = q("SELECT s.*, u.name AS officer_name FROM students s LEFT JOIN users u ON u.id = s.officer_id WHERE s.id = ?", [$studentId])->fetch();
} elseif (is_officer_role($u['role'])) {
    [$w, $p] = scope_sql($u, 's');
    $s = q("SELECT s.*, u.name AS officer_name FROM students s LEFT JOIN users u ON u.id = s.officer_id WHERE s.id = ? AND $w", array_merge([$studentId], $p))->fetch();
} elseif (is_admin_role($u['role'])) {
    $s = q("SELECT s.*, u.name AS officer_name FROM students s LEFT JOIN users u ON u.id = s.officer_id WHERE s.id = ?", [$studentId])->fetch();
} else {
    $s = null;
}

if (!$s) {
    http_response_code(404);
    exit('Student record not found or access denied.');
}

// 1. If a direct Google Drive file ID (?fid=...) was requested, serve/download that exact specific file directly!
if ($directFid !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $directFid)) {
    serve_google_drive_file($directFid, $directName !== '' ? $directName : 'document.pdf', $forceDownload);
}

// 2. If a custom document (?cid=...) was requested, serve it directly!
if ($customDocId > 0) {
    $cd = q("SELECT * FROM custom_documents WHERE id = ? AND student_id = ?", [$customDocId, $studentId])->fetch();
    if ($cd && !empty($cd['file_path'])) {
        $fullPath = realpath(ROOT . '/' . ltrim((string)$cd['file_path'], '/\\'));
        $baseDir = realpath(ROOT . '/uploads/documents');
        if ($fullPath && $baseDir && str_starts_with($fullPath, $baseDir) && is_file($fullPath)) {
            $mime = $cd['mime_type'] ?: 'application/octet-stream';
            $fileName = $cd['file_name'] ?: basename($fullPath);
            $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $fileName);
            $disposition = $forceDownload ? 'attachment' : 'inline';

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . $disposition . '; filename="' . $safeFileName . '"');
            header('Content-Length: ' . filesize($fullPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            header('X-Content-Type-Options: nosniff');
            readfile($fullPath);
            exit;
        }
    }
    http_response_code(404);
    exit('Custom document file not found.');
}

if (!isset(DOCS[$docIndex])) {
    http_response_code(400);
    exit('Invalid document index.');
}

$docLabel = DOCS[$docIndex];
$doc = q("SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?", [$studentId, $docIndex])->fetch();

// 3. If not yet linked to a file, auto-resolve the student's Google Drive folder to see if this specific document exists there!
if (!$doc || empty($doc['file_path'])) {
    resolve_student_drive_assets($studentId);
    $doc = q("SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?", [$studentId, $docIndex])->fetch();
}

// 4. If a Google Drive file ID is mapped (gdrive:<fileId>), stream or download that exact specific file from Google Drive!
if ($doc && !empty($doc['file_path']) && str_starts_with((string)$doc['file_path'], 'gdrive:')) {
    $gFileId = substr((string)$doc['file_path'], 7);
    $gFileName = !empty($doc['file_name']) ? (string)$doc['file_name'] : ($docLabel . '.pdf');
    serve_google_drive_file($gFileId, $gFileName, $forceDownload);
}

// 5. If a physical file was uploaded to local disk, serve it directly
if ($doc && !empty($doc['file_path']) && !str_starts_with((string)$doc['file_path'], 'gdrive:')) {
    $baseDir = realpath(ROOT . '/uploads/documents');
    $fullPath = realpath(ROOT . '/' . ltrim((string)$doc['file_path'], '/\\'));
    if ($baseDir && $fullPath && str_starts_with($fullPath, $baseDir) && is_file($fullPath)) {
        $mime = $doc['mime_type'] ?: 'application/octet-stream';
        $fileName = $doc['file_name'] ?: basename($fullPath);
        $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $fileName);
        $disposition = $forceDownload ? 'attachment' : 'inline';

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFileName . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        readfile($fullPath);
        exit;
    }
}

// 6. IF THE DOCUMENT HAS NOT BEEN UPLOADED YET:
// DO NOT generate any dummy PDF record sheet!
// Instead, render a clean, professional in-portal page with an upload form so the user can upload the real document!
$portalBase = PORTALS[$u['role']]['home'] ?? 'index.php';
$backUrl = $u['role'] === 'student'
    ? url('student/index.php')
    : url(dirname($portalBase) . '/progress.php?id=' . $studentId);

page_start($docLabel . ' — Not Uploaded Yet', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel" style="max-width: 600px; margin: 2rem auto; padding: 2.25rem 1.75rem;">
  <div style="text-align: center; margin-bottom: 1.25rem;">
    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📁</div>
    <h1 style="font-size: 1.35rem; margin-bottom: 0.35rem;"><?= h($docLabel) ?> Not Uploaded Yet</h1>
    <p class="muted small" style="margin: 0;">
      Student: <b><?= h($s['name']) ?></b> (Ref: STU-<?= str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT) ?>)
    </p>
  </div>

  <div style="background: var(--bg); border: 1px dashed var(--border); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
    <h3 style="font-size: 0.95rem; margin-top: 0; margin-bottom: 0.5rem;">Upload <?= h($docLabel) ?> File Now:</h3>
    <p class="small muted" style="margin-bottom: 0.75rem;">
      Select the real scanned PDF, picture, or document to attach it directly to this student's file:
    </p>

    <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="student_id" value="<?= (int)$studentId ?>">
      <input type="hidden" name="doc_index" value="<?= (int)$docIndex ?>">
      
      <div style="margin-bottom: 0.75rem;">
        <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="width: 100%; font-size: 0.85rem;">
      </div>

      <button class="btn primary sm" type="submit" style="width: 100%;">
        📤 Upload and Attach <?= h($docLabel) ?>
      </button>
    </form>
  </div>

  <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
    <a class="btn sm ghost" href="<?= h($backUrl) ?>">← Back to Student Profile</a>
    <?php if (safe_link($s['drive_link'] ?? '')): ?>
      <a class="btn sm" href="<?= h($s['drive_link']) ?>" target="_blank" rel="noopener noreferrer">
        📁 Open Student Google Drive Folder ↗
      </a>
    <?php endif; ?>
  </div>
</section>
<?php
page_end();
