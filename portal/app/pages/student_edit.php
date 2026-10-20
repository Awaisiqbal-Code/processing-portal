<?php
declare(strict_types=1);
// Shared add/edit page for admin and officer. Expects $u.

$admin = $u['role'] === 'admin';
$base = $admin ? 'admin' : 'officer';
$isNew = get('new') === '1' || post('mode') === 'new';
$id = (int)(get('id') ?: post('id'));
$r = $isNew ? [] : find_student($u, $id);           // 404s if out of scope
$officers = q("SELECT id, name, COALESCE(department, 'backend') AS department FROM users WHERE role = 'officer' AND active = 1 ORDER BY CASE WHEN department = 'frontend' THEN 0 ELSE 1 END, name")->fetchAll();
$officerIds = array_map(fn($o) => (int)$o['id'], $officers);
$officerMap = [];
foreach ($officers as $o) {
    $officerMap[(int)$o['id']] = $o['name'];
}
$errors = [];

// ---------- delete (admin only) ----------
if (is_post() && post('action') === 'delete') {
    csrf_check();
    if (!$admin) { http_response_code(403); exit('Not allowed.'); }
    if (post('confirm') !== 'yes') { flash('Tick the box to confirm the delete.', 'bad'); redirect("admin/student.php?id=$id"); }
    q('DELETE FROM users WHERE student_id = ?', [$id]);
    q('DELETE FROM students WHERE id = ?', [$id]);
    log_action('Deleted student ' . $r['name'], null);
    flash('Student deleted.');
    redirect('admin/students.php');
}

// ---------- save ----------
if (is_post() && post('action') === 'save') {
    csrf_check();
    $data = [];
    foreach (FIELD_GROUPS as $fields) {
        foreach ($fields as $k => $f) {
            $type = $f[1];
            if ($type === 'officer') continue;
            $v = mb_substr(post($k), 0, $type === 'textarea' ? 5000 : 500);
            if ($type === 'yn' && !in_array($v, ['', 'Yes', 'No'], true)) $v = '';
            if ($type === 'select' && !in_array($v, $f[2], true)) $v = $r[$k] ?? '';
            if ($type === 'date' && $v !== '' && !valid_date($v)) { $errors[] = $f[0] . ' is not a valid date.'; }
            if ($type === 'url' && $v !== '' && !safe_link($v)) { $errors[] = 'The Drive link must start with https://'; }
            $data[$k] = $v === '' ? null : $v;
        }
    }
    if (!$data['name']) $errors[] = 'Enter the student name.';

    if ($admin) {
        $oid = post('officer_id');
        $data['officer_id'] = ($oid !== '' && in_array((int)$oid, $officerIds, true)) ? (int)$oid : null;
        $foid = post('frontend_officer_id');
        $data['frontend_officer_id'] = ($foid !== '' && in_array((int)$foid, $officerIds, true)) ? (int)$foid : null;
        $data['frontend_officer_name'] = $data['frontend_officer_id'] ? ($officerMap[$data['frontend_officer_id']] ?? null) : null;
    } elseif ($isNew) {
        $myDept = q('SELECT COALESCE(department, "backend") FROM users WHERE id = ?', [(int)$u['id']])->fetchColumn();
        if ($myDept === 'frontend') {
            $data['frontend_officer_id'] = (int)$u['id'];
            $data['frontend_officer_name'] = $u['name'];
        } else {
            $data['officer_id'] = (int)$u['id'];
        }
    }

    if (!$errors) {
        if ($isNew) {
            $data['entered_by'] = (int)$u['id'];
            $data['created_at'] = now();
            $cols = array_keys($data);
            q('INSERT INTO students (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
            $id = (int)db()->lastInsertId();
            log_action('Added student', $id);
        } else {
            $data['updated_at'] = now();
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
            [$w, $p] = scope_sql($u, 'students');
            q("UPDATE students SET $set WHERE id = ? AND $w", array_merge(array_values($data), [$id], $p));
            log_action('Updated student', $id);
        }
        // documents and file uploads
        foreach (DOCS as $i => $docName) {
            $v = post('doc_' . $i);
            $existingDoc = q('SELECT * FROM student_documents WHERE student_id = ? AND doc_index = ?', [$id, $i])->fetch();
            $filePath = $existingDoc['file_path'] ?? null;
            $fileName = $existingDoc['file_name'] ?? null;
            $fileSize = $existingDoc['file_size'] ?? null;
            $mimeType = $existingDoc['mime_type'] ?? null;
            $uploadedAt = $existingDoc['uploaded_at'] ?? null;
            $uploadedBy = $existingDoc['uploaded_by'] ?? null;

            // Check if removal requested
            if (post('remove_doc_file_' . $i) === '1') {
                if ($filePath && is_file(ROOT . '/' . ltrim($filePath, '/\\'))) {
                    @unlink(ROOT . '/' . ltrim($filePath, '/\\'));
                }
                $filePath = null; $fileName = null; $fileSize = null; $mimeType = null; $uploadedAt = null; $uploadedBy = null;
            }

            // Check if a new file was uploaded
            if (!empty($_FILES['doc_file_' . $i]['name']) && ($_FILES['doc_file_' . $i]['error'] ?? 1) === UPLOAD_ERR_OK) {
                $file = $_FILES['doc_file_' . $i];
                $origName = basename((string)$file['name']);
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'zip'];

                if (in_array($ext, $allowedExts, true) && $file['size'] <= 15 * 1024 * 1024) {
                    $targetDir = ROOT . '/uploads/documents/' . $id;
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    $safeDiskName = 'doc_' . $i . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                    $destPath = $targetDir . '/' . $safeDiskName;

                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        if ($filePath && is_file(ROOT . '/' . ltrim($filePath, '/\\'))) {
                            @unlink(ROOT . '/' . ltrim($filePath, '/\\'));
                        }
                        $filePath = 'uploads/documents/' . $id . '/' . $safeDiskName;
                        $fileName = $origName;
                        $fileSize = (int)$file['size'];
                        $mimeType = mime_content_type($destPath) ?: 'application/octet-stream';
                        $uploadedAt = now();
                        $uploadedBy = (int)$u['id'];
                    }
                } else {
                    $errors[] = "Document \"{$docName}\" has an invalid file format or exceeds 15MB limit.";
                }
            }

            if (!in_array($v, DOC_STATUS, true) && !$filePath) {
                q('DELETE FROM student_documents WHERE student_id = ? AND doc_index = ?', [$id, $i]);
                continue;
            }

            $docStatus = in_array($v, DOC_STATUS, true) ? $v : ($existingDoc['status'] ?? 'Pending');
            // If no physical/drive file is attached, status cannot be Received/Verified
            if (empty($filePath) && ($docStatus === 'Received' || $docStatus === 'Verified' || $docStatus === '')) {
                $docStatus = 'Pending';
            }
            if ($existingDoc) {
                q('UPDATE student_documents SET status = ?, file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_at = ?, uploaded_by = ?, updated_by = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?',
                  [$docStatus, $filePath, $fileName, $fileSize, $mimeType, $uploadedAt, $uploadedBy, $u['id'], now(), $id, $i]);
            } elseif ($docStatus !== '' || $filePath !== null) {
                q('INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, file_size, mime_type, uploaded_at, uploaded_by, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$id, $i, $docStatus, $filePath, $fileName, $fileSize, $mimeType, $uploadedAt, $uploadedBy, $u['id'], now()]);
            }
        }
        // Auto-sync offer stage with doc 9 real file availability
        $hasOfferDoc = q("SELECT file_path FROM student_documents WHERE student_id = ? AND doc_index = 9", [$id])->fetchColumn();
        q("UPDATE students SET offer = ? WHERE id = ?", [!empty($hasOfferDoc) ? 'Yes' : 'No', $id]);

        // Auto-sync recorded_uploaded stage with real meeting availability
        $stRow = q("SELECT drive_link, meeting_link, drive_files_json FROM students WHERE id = ?", [$id])->fetch();
        if ($stRow) {
            $hasMeet = student_has_meeting($stRow);
            q("UPDATE students SET recorded_uploaded = ? WHERE id = ?", [$hasMeet ? 'Yes' : 'No', $id]);
        }

        flash($isNew ? 'Student added.' : 'Changes saved.');
        redirect("$base/student.php?id=$id");
    }
    $r = array_merge($r, $data);
}

$docs = [];
$docRows = [];
if (!$isNew) {
    require_once __DIR__ . '/../drive_resolver.php';
    resolve_student_drive_assets((int)$id);
    // Refresh $r meeting_link if updated
    $freshM = q("SELECT meeting_link FROM students WHERE id = ?", [$id])->fetchColumn();
    if ($freshM) $r['meeting_link'] = $freshM;
    foreach (q('SELECT * FROM student_documents WHERE student_id = ?', [$id]) as $d) {
        $docs[(int)$d['doc_index']] = $d['status'];
        $docRows[(int)$d['doc_index']] = $d;
    }
    $customDocs = q('SELECT * FROM custom_documents WHERE student_id = ? ORDER BY id ASC', [$id])->fetchAll();
} else {
    $customDocs = [];
}
if ($errors) foreach (DOCS as $i => $_) $docs[$i] = post('doc_' . $i);
$login = (!$isNew && $admin) ? q("SELECT id, username, active, last_login FROM users WHERE student_id = ?", [$id])->fetch() : null;

page_start($isNew ? 'Add student' : $r['name'], $u, PORTALS[$u['role']]['label']);
?>
<p class="small"><a href="<?= h(url($base . '/students.php')) ?>">← Back to students</a></p>
<div class="panel-h">
  <div>
    <h1><?= $isNew ? 'Add student' : h($r['name']) ?></h1>
    <?php if (!$isNew): $ns = next_stage($r); ?>
      <p><a href="<?= h(url($base . '/progress.php?id=' . (int)$id)) ?>" title="View student progress" style="text-decoration:none;"><?= progress_dots($r) ?></a> <?= $ns ? 'Next step: <b>' . h($ns) . '</b>' : '<b>Closed</b>' ?></p>
    <?php endif; ?>
  </div>
  <?php if (!$isNew): 
    $dl = safe_link($r['drive_link'] ?? '');
    $ml = url('meeting.php?id=' . (int)$id);
    $hasMeetBtn = student_has_meeting($r);
  ?>
    <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
      <?php if ($dl): ?>
        <a class="btn sm" href="<?= h($dl) ?>" target="_blank" rel="noopener noreferrer" title="Open Student Documents Folder in Google Drive">📁 Open Docs Folder</a>
      <?php endif; ?>
      <?php if ($hasMeetBtn): ?>
        <a class="btn sm" href="<?= h($ml) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video / Zoom Session">🎥 Open Meeting</a>
      <?php else: ?>
        <span class="pill" title="No recorded meeting file found in student folder">🎥 Meeting: Not Available</span>
      <?php endif; ?>
      <a class="btn sm" href="<?= h(url($base . '/progress.php?id=' . (int)$id)) ?>">View Progress</a>
      <a class="btn sm primary" href="<?= h(url($base . '/report.php?id=' . (int)$id . '&format=pdf')) ?>">⬇ Download Report (PDF)</a>
      <a class="btn sm ghost" href="<?= h(url($base . '/report.php?id=' . (int)$id . '&format=csv')) ?>">Download CSV</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?><div class="flash bad" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <?php foreach (FIELD_GROUPS as $group => $fields): ?>
  <fieldset><legend><?= h($group) ?></legend><div class="fields">
    <?php foreach ($fields as $k => $f):
        $v = (string)($r[$k] ?? '');
        $wide = in_array($f[1], ['textarea', 'url'], true) || in_array($k, ['disc2', 'disc3'], true) ? ' wide' : ''; ?>
      <label class="f<?= $wide ?>"><span><?= h($f[0]) ?><?= $k === 'name' ? ' *' : '' ?></span>
      <?php switch ($f[1]):
        case 'officer':
            if ($admin): ?>
              <select name="<?= $k ?>"><option value="">Not assigned</option>
                <?php foreach ($officers as $o):
                  $deptTag = ($o['department'] ?? 'backend') === 'frontend' ? 'Front End' : 'Back End';
                ?><option value="<?= $o['id'] ?>" <?= (string)$o['id'] === $v ? 'selected' : '' ?>><?= h($o['name']) ?> (<?= $deptTag ?>)</option><?php endforeach; ?>
              </select>
            <?php else: ?><input value="<?= h($officerMap[(int)$v] ?? 'Not assigned') ?>" disabled><?php endif;
            break;
        case 'yn': ?>
              <select name="<?= $k ?>"><?php foreach (['' => 'Not set', 'Yes' => 'Yes', 'No' => 'No'] as $ov => $ol): ?><option value="<?= $ov ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ol ?></option><?php endforeach; ?></select>
        <?php break;
        case 'select': ?>
              <select name="<?= $k ?>"><?php foreach ($f[2] as $ov): ?><option value="<?= h($ov) ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ov === '' ? 'Not set' : h($ov) ?></option><?php endforeach; ?></select>
        <?php break;
        case 'textarea': ?>
              <textarea name="<?= $k ?>" rows="3"><?= h($v) ?></textarea>
        <?php break;
        case 'date': ?>
              <input type="date" name="<?= $k ?>" value="<?= h($v) ?>">
        <?php break;
        case 'url': ?>
              <input type="url" name="<?= $k ?>" value="<?= h($v) ?>" placeholder="https://drive.google.com/...">
              <?php if (safe_link($v)): ?>
                <a class="small" href="<?= h($v) ?>" target="_blank" rel="noopener noreferrer" style="font-weight:600; display:inline-block; margin-top:0.2rem;">
                  <?= $k === 'meeting_link' ? '🎥 Open Meeting / Recording ↗' : '📁 Open Drive Folder ↗' ?>
                </a>
              <?php endif; ?>
        <?php break;
        default: ?>
              <input name="<?= $k ?>" value="<?= h($v) ?>" <?= $k === 'name' ? 'required' : '' ?>>
      <?php endswitch; ?>
      </label>
    <?php endforeach; ?>
  </div></fieldset>
  <?php endforeach; ?>

  <fieldset><legend>Documents & Attachments</legend>
    <p class="muted small">Update document statuses and view, download, or upload verified document files. Students see these in their portal.</p>
    <div class="doc-grid">
    <?php foreach (DOCS as $i => $d): 
      $v = $docs[$i] ?? ''; 
      $hasFile = !empty($docRows[$i]['file_path']);
    ?>
      <div class="doc-card">
        <label class="f" style="margin-bottom: 0.4rem;">
          <span style="font-weight:600; color:var(--fg);"><?= h($d) ?></span>
          <select name="doc_<?= $i ?>">
            <?php foreach (DOC_STATUS as $ov): ?>
              <option value="<?= h($ov) ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ov === '' ? 'Not requested' : h($ov) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        
        <?php if (!$isNew): ?>
          <div class="doc-attachment-box">
            <?php if ($hasFile): 
              $df = $docRows[$i];
              $isGdrive = str_starts_with((string)($df['file_path'] ?? ''), 'gdrive:');
              $kb = round(($df['file_size'] ?? 0) / 1024, 1);
            ?>
              <div class="doc-attachment-name">📎 <?= h($df['file_name']) ?> <?= $isGdrive ? '<span class="muted">(Google Drive)</span>' : '(' . $kb . ' KB)' ?></div>
              <div style="margin-top: 0.3rem; display:flex; gap: 0.4rem; align-items:center; flex-wrap:wrap;">
                <a class="btn sm" href="<?= h(url('document.php?id=' . (int)$id . '&doc=' . $i)) ?>" target="_blank" title="View Specific Document">📄 View</a>
                <a class="btn sm primary" href="<?= h(url('document.php?id=' . (int)$id . '&doc=' . $i . '&download=1')) ?>" title="Download Specific Document">⬇ Download</a>
                <?php if (!$isGdrive): ?>
                  <label class="small check doc-remove-label">
                    <input type="checkbox" name="remove_doc_file_<?= $i ?>" value="1"> Remove
                  </label>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="doc-attachment-name muted">⚠️ No real file attached yet</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div style="margin-top: 0.35rem;">
          <label class="small muted" style="display:block; margin-bottom: 0.2rem; font-size: 0.75rem;">
            <?= $hasFile ? 'Upload custom replacement:' : 'Attach document file:' ?>
          </label>
          <input type="file" name="doc_file_<?= $i ?>" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="font-size:0.8rem; width:100%;">
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="actions"><a class="btn ghost" href="<?= h(url($base . '/students.php')) ?>">Cancel</a><button class="btn primary" type="submit"><?= $isNew ? 'Add student' : 'Save changes' ?></button></div>
</form>

<?php if (!$isNew): ?>
<section class="panel">
  <div class="panel-h">
    <h2>Additional Documents & Custom Files (ایڈیشنل سیکشن)</h2>
    <span class="muted small">Add extra documents (e.g. Work Experience, Affidavits, Portfolios)</span>
  </div>
  <?php if (!empty($customDocs)): ?>
    <div class="list" style="margin-bottom: 1.25rem;">
      <?php foreach ($customDocs as $cd):
        $ckb = round(($cd['file_size'] ?? 0) / 1024, 1);
      ?>
        <div class="li" style="flex-wrap:wrap; gap:0.5rem; justify-content:space-between; align-items:center;">
          <div>
            <b>📄 <?= h($cd['title']) ?></b>
            <div class="small muted">📎 <?= h($cd['file_name']) ?> (<?= $ckb ?> KB) · Uploaded <?= h(substr($cd['created_at'], 0, 10)) ?></div>
          </div>
          <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
            <a class="btn sm" href="<?= h(url('document.php?id=' . (int)$id . '&cid=' . (int)$cd['id'])) ?>" target="_blank">📄 View</a>
            <a class="btn sm primary" href="<?= h(url('document.php?id=' . (int)$id . '&cid=' . (int)$cd['id'] . '&download=1')) ?>">⬇ Download</a>
            <a class="btn sm bad-b" href="<?= h(url('upload_document.php?action=delete_custom&cid=' . (int)$cd['id'] . '&csrf=' . csrf_token())) ?>" onclick="return confirm('Delete this custom document?');">🗑 Delete</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted small" style="margin-bottom: 1.25rem;">No additional custom documents uploaded yet.</p>
  <?php endif; ?>

  <div style="background: var(--bg); border: 1px dashed var(--border); border-radius: 8px; padding: 1rem;">
    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;">➕ Add Document Manually</h4>
    <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="student_id" value="<?= (int)$id ?>">
      <label style="flex: 1; min-width: 160px; margin:0;">
        <span class="small muted" style="display:block; margin-bottom: 0.2rem;">Document Title:</span>
        <input name="custom_title" placeholder="e.g. Work Experience, Affidavit, Portfolio" required style="width:100%; font-size:0.85rem;">
      </label>
      <label style="flex: 1; min-width: 180px; margin:0;">
        <span class="small muted" style="display:block; margin-bottom: 0.2rem;">Choose File:</span>
        <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="width:100%; font-size:0.85rem;">
      </label>
      <button class="btn primary sm" type="submit" style="align-self: flex-end;">Upload Document</button>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if ($admin && !$isNew): ?>
<section class="panel">
  <div class="panel-h"><h2>Student portal login</h2><a class="btn sm primary" href="<?= h(url('admin/preview.php?id=' . (int)$id)) ?>">View this student's portal</a></div>
  <p class="muted small">The student signs in at the student portal with this username.</p>
  <?php if ($login): ?>
    <p>Username <code><?= h($login['username']) ?></code> · <?= $login['active'] ? '<span class="pill ok">Active</span>' : '<span class="pill bad">Disabled</span>' ?> · Last sign-in <?= $login['last_login'] ? h(fmt_date(substr($login['last_login'], 0, 10))) : 'never' ?></p>
  <?php else: ?>
    <p class="muted">This student has no login yet.</p>
  <?php endif; ?>
  <form method="post" action="<?= h(url('admin/logins.php')) ?>" class="row-actions">
    <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>">
    <button class="btn sm" name="action" value="reset"><?= $login ? 'Reset password' : 'Create login' ?></button>
    <?php if ($login): ?><button class="btn sm" name="action" value="toggle"><?= $login['active'] ? 'Disable login' : 'Enable login' ?></button><?php endif; ?>
  </form>
</section>

<section class="panel danger">
  <div class="panel-h"><h2>Delete student</h2><p>Removes the record, documents status and the student's login. This cannot be undone.</p></div>
  <form method="post" class="row-actions">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <label class="check"><input type="checkbox" name="confirm" value="yes"> Yes, delete <?= h($r['name']) ?></label>
    <button class="btn sm bad-b" type="submit">Delete student</button>
  </form>
</section>
<?php endif;
page_end();
