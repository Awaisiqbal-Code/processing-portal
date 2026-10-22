<?php
declare(strict_types=1);
// Shared add/edit page for admin, pms_lead, processing_staff, and counselor. Expects $u.

$admin = is_admin_role($u['role']);
$canManage = can_manage_student_lifecycle($u);
$isCounselor = ($u['role'] === 'counselor');
$base = $admin ? 'admin' : 'officer';
$isNew = get('new') === '1' || post('mode') === 'new';
$id = (int)(get('id') ?: post('id'));
$r = $isNew ? [] : find_student($u, $id);           // 404s if out of scope

$officers = q("SELECT id, name, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') AND active = 1 ORDER BY CASE WHEN department = 'frontend' THEN 0 ELSE 1 END, name")->fetchAll();
$officerIds = array_map(fn($o) => (int)$o['id'], $officers);
$officerMap = [];
foreach ($officers as $o) {
    $officerMap[(int)$o['id']] = $o['name'];
}
$errors = [];

// ---------- Counselor Read-Only Security Guard ----------
if ($isCounselor && is_post()) {
    $allowedCounselorActions = ['add_remark', 'upload_consent_doc'];
    if (!in_array(post('action'), $allowedCounselorActions, true)) {
        http_response_code(403);
        flash('Counselors have read-only access to student profile details.', 'bad');
        redirect($base . "/student.php?id=$id");
    }
}

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

// ---------- add remark ----------
if (is_post() && post('action') === 'add_remark') {
    csrf_check();
    $text = post('remark_text');
    $res = add_student_remark($u, $id, (string)$text);
    if ($res['ok']) {
        flash('Remark added successfully.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}#remarks");
}

// ---------- edit remark (admin only) ----------
if (is_post() && post('action') === 'edit_remark') {
    csrf_check();
    $rid = (int)post('remark_id');
    $text = post('remark_text');
    $res = edit_student_remark($u, $rid, (string)$text);
    if ($res['ok']) {
        flash('Remark updated.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}#remarks");
}

// ---------- delete remark (admin only) ----------
if (is_post() && post('action') === 'delete_remark') {
    csrf_check();
    $rid = (int)post('remark_id');
    $res = delete_student_remark($u, $rid);
    if ($res['ok']) {
        flash('Remark deleted.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}#remarks");
}

// ---------- change lifecycle status (Super Admin, Admin, PMS Lead only) ----------
if (is_post() && post('action') === 'change_status') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        flash('Permission denied: Only Admin and PMS Lead can change student lifecycle status.', 'bad');
        redirect($base . "/student.php?id={$id}");
    }
    $newSt = post('student_status');
    $reason = post('disabled_reason');
    $res = change_student_status($u, $id, (string)$newSt, $reason !== '' ? (string)$reason : null);
    if ($res['ok']) {
        flash('Student lifecycle status changed to ' . ucfirst((string)$newSt) . '.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}");
}

// ---------- toggle / update verification (Super Admin, Admin, PMS Lead only) ----------
if (is_post() && post('action') === 'toggle_verify') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        flash('Permission denied: Only Admin and PMS Lead can verify student files.', 'bad');
        redirect($base . "/student.php?id={$id}");
    }
    $isVer = post('verify') === '1';
    $res = set_student_verification($u, $id, $isVer);
    if ($res['ok']) {
        flash($isVer ? 'Student marked as Verified.' : 'Student marked as Unverified.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}");
}

// ---------- toggle / update refund (Super Admin, Admin, PMS Lead only) ----------
if (is_post() && post('action') === 'update_refund') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        flash('Permission denied: Only Admin and PMS Lead can process student refunds.', 'bad');
        redirect($base . "/student.php?id={$id}");
    }
    $isRef = post('is_refunded') === '1';
    $rDate = post('refund_date');
    $rNote = post('refund_note');
    $res = mark_student_refunded($u, $id, $isRef, $rDate !== '' ? (string)$rDate : null, $rNote !== '' ? (string)$rNote : null);
    if ($res['ok']) {
        flash($isRef ? 'Student marked as Refunded.' : 'Refund status updated.');
    } else {
        flash($res['error'], 'bad');
    }
    redirect($base . "/student.php?id={$id}");
}

// ---------- upload consent document (Counselors and Staff) ----------
if (is_post() && post('action') === 'upload_consent_doc') {
    csrf_check();
    if (empty($_FILES['consent_doc']) || ($_FILES['consent_doc']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('Please choose a valid consent document file (PDF, DOCX, JPG, PNG up to 10MB).', 'bad');
    } else {
        $tplId = (int)post('template_id') ?: null;
        $res = upload_consent_document($u, $id, $_FILES['consent_doc'], $tplId);
        if ($res['ok']) {
            flash('Signed consent document uploaded successfully.');
        } else {
            flash($res['error'], 'bad');
        }
    }
    redirect($base . "/student.php?id={$id}#consent");
}

// ---------- save student profile fields ----------
if (is_post() && post('action') === 'save') {
    csrf_check();
    if ($isCounselor) {
        http_response_code(403);
        exit('Access denied.');
    }

    $data = [];
    foreach (FIELD_GROUPS as $fields) {
        foreach ($fields as $k => $f) {
            $type = $f[1];
            if ($type === 'officer' || $type === 'readonly_textarea') continue;
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
            $res = create_student($u, $data);
            if (!$res['ok']) {
                $errors[] = $res['error'] ?? 'Failed to create student.';
            } else {
                $id = (int)$res['id'];
                if (!empty(post('new_remark_text'))) {
                    add_student_remark($u, $id, (string)post('new_remark_text'));
                }
            }
        } else {
            // Staff data change: update last_edited_at and last_edited_by; mark pending_since if not already pending
            $now = now();
            $data['updated_at'] = $now;
            $data['last_edited_at'] = $now;
            $data['last_edited_by'] = (int)$u['id'];
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
            [$w, $p] = scope_sql($u, 'students');
            q("UPDATE students SET $set, pending_since = COALESCE(pending_since, ?) WHERE id = ? AND $w", array_merge(array_values($data), [$now, $id], $p));
            log_action('Updated student', $id);
            log_activity('student', $id, 'updated', null, ['name' => $data['name'] ?? ($r['name'] ?? '')], (int)$u['id']);

            if (!empty(post('new_remark_text'))) {
                add_student_remark($u, $id, (string)post('new_remark_text'));
            }
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
            if (empty($filePath) && ($docStatus === 'Received' || $docStatus === 'Verified' || $docStatus === '')) {
                $docStatus = 'Pending';
            }
            if ($existingDoc) {
                q('UPDATE student_documents SET status = ?, file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_at = ?, uploaded_by = ?, updated_by = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?',
                  [$docStatus, $filePath, $fileName, $fileSize, $mimeType, $uploadedAt, $uploadedBy, (int)$u['id'], now(), $id, $i]);
            } else {
                q('INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, file_size, mime_type, uploaded_at, uploaded_by, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$id, $i, $docStatus, $filePath, $fileName, $fileSize, $mimeType, $uploadedAt, $uploadedBy, (int)$u['id'], now()]);
            }
        }

        flash('Student saved.');
        redirect($base . "/student.php?id=$id");
    }
}

// Reload fresh record for edit
$docs = [];
$docRows = [];
$studentRemarksList = [];
$consentDocs = [];
$consentTemplates = [];
$activityEntries = [];

if (!$isNew) {
    $r = find_student($u, $id);
    $freshM = q("SELECT meeting_link FROM students WHERE id = ?", [$id])->fetchColumn();
    if ($freshM) $r['meeting_link'] = $freshM;
    foreach (q('SELECT * FROM student_documents WHERE student_id = ?', [$id]) as $d) {
        $docs[(int)$d['doc_index']] = $d['status'];
        $docRows[(int)$d['doc_index']] = $d;
    }
    $customDocs = q('SELECT * FROM custom_documents WHERE student_id = ? ORDER BY id ASC', [$id])->fetchAll();
    $studentRemarksList = get_student_remarks($id);
    
    // Consent documents & templates
    $consentDocs = q("SELECT cd.*, u.name AS uploader_name FROM consent_documents cd LEFT JOIN users u ON u.id = cd.uploaded_by WHERE cd.student_id = ? ORDER BY cd.created_at DESC", [$id])->fetchAll();
    $studentCountry = $r['country'] ?? '';
    $applicableTemplate = get_applicable_consent_template($studentCountry);
    $consentTemplates = q("SELECT ct.*, u.name AS uploader_name FROM consent_templates ct LEFT JOIN users u ON u.id = ct.uploaded_by ORDER BY CASE WHEN LOWER(TRIM(ct.country)) = LOWER(TRIM(?)) THEN 0 WHEN ct.country IS NULL OR ct.country = '' OR LOWER(TRIM(ct.country)) = 'general' THEN 1 ELSE 2 END, ct.country ASC", [$studentCountry])->fetchAll();
    
    // Audit Activity Logs
    $activityEntries = q("SELECT al.*, u.name AS actor_name, u.role AS actor_role FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.entity_type = 'student' AND al.entity_id = ? ORDER BY al.created_at DESC LIMIT 50", [$id])->fetchAll();
} else {
    $customDocs = [];
}
if ($errors) foreach (DOCS as $i => $_) $docs[$i] = post('doc_' . $i);
$login = (!$isNew && $admin) ? q("SELECT id, username, active, last_login FROM users WHERE student_id = ?", [$id])->fetch() : null;

page_start($isNew ? 'Add student' : $r['name'], $u, PORTALS[$u['role']]['label']);
?>
<p class="small"><a href="<?= h(url($base . '/students.php')) ?>">← Back to students</a></p>

<!-- Branch 30% Legacy Data Error Flag (If unresolved in record) -->
<?php if (!$isNew && (($r['branch'] ?? '') === '30%')): ?>
<div class="flash bad" style="border-left:5px solid #dc2626; margin-bottom:1rem; background:rgba(239,68,68,0.08); border-color:#dc2626;">
  <b>⚠ Legacy Data Error Flag:</b> This student record has <code>branch = '30%'</code> due to a column offset in the legacy CSV import. Please correct the branch and refund policy tiers when appropriate.
</div>
<?php endif; ?>

<!-- Counselor Read-Only Informational Notice -->
<?php if ($isCounselor): ?>
<div class="flash" style="margin-bottom:1rem; border-left:5px solid var(--accent); background:var(--surface);">
  <b>Counselor Workspace:</b> You are viewing your assigned student in read-only mode. You can add internal remarks below and upload signed consent forms.
</div>
<?php endif; ?>

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

<?php if (!$isNew): 
    $stStatus = $r['student_status'] ?? 'active';
    $isVerified = ($r['csc_status'] ?? '') === 'Verified' || !empty($r['csc_verified_at']);
    $isRefunded = !empty($r['is_refunded']);
?>
<!-- Phase 2 & 3: Lifecycle Status, Verification & Refund Controls (Restricted to Super Admin, Admin, PMS Lead) -->
<section class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-h">
    <h2>Student Status &amp; Controls</h2>
    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
      <span class="pill <?= $stStatus === 'active' ? 'ok' : ($stStatus === 'disabled' ? 'bad' : 'warn') ?>" style="<?= $stStatus === 'disabled' ? 'background:#64748b;color:#fff;' : '' ?>">
        Status: <?= h(ucfirst($stStatus)) ?>
      </span>
      <span class="pill <?= $isVerified ? 'ok' : 'acc' ?>">
        <?= $isVerified ? '✓ Verified' : 'Unverified' ?>
      </span>
      <?php if ($isRefunded): ?>
        <span class="pill bad">⚠ Refunded</span>
      <?php endif; ?>
      <?php if (!empty($r['last_edited_at']) && (empty($r['last_remark_at']) || $r['last_remark_at'] < $r['last_edited_at'])): ?>
        <span class="pill bad" style="font-weight:700;">⚠ PENDING ACTION</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canManage): ?>
  <div style="display:flex; gap:1.25rem; flex-wrap:wrap; align-items:flex-start; padding:0.5rem 0;">
    <!-- Status Transition Form -->
    <form method="post" style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_status">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="small muted" style="font-weight:600;">Change Status:</label>
      <select name="student_status" id="status-select" onchange="document.getElementById('disabled-reason-box').style.display = (this.value === 'disabled' ? 'inline-block' : 'none');">
        <option value="active" <?= $stStatus === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $stStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        <option value="disabled" <?= $stStatus === 'disabled' ? 'selected' : '' ?>>Disabled</option>
      </select>
      <span id="disabled-reason-box" style="display:<?= $stStatus === 'disabled' ? 'inline-block' : 'none' ?>;">
        <input name="disabled_reason" placeholder="Reason for disabling (required)..." value="<?= h($r['disabled_reason'] ?? '') ?>" style="font-size:0.82rem; padding:0.3rem 0.5rem; width:220px;">
      </span>
      <button class="btn sm" type="submit">Update Status</button>
    </form>

    <!-- Verification Toggle -->
    <form method="post" style="display:flex; gap:0.4rem; align-items:center;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_verify">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="verify" value="<?= $isVerified ? '0' : '1' ?>">
      <button class="btn sm <?= $isVerified ? 'ghost' : 'primary' ?>" type="submit">
        <?= $isVerified ? 'Unmark Verification' : 'Mark as Verified' ?>
      </button>
    </form>

    <!-- Refund Toggle Form -->
    <form method="post" style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_refund">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="check small">
        <input type="checkbox" name="is_refunded" value="1" <?= $isRefunded ? 'checked' : '' ?> onchange="this.form.submit()">
        <span>Mark Refunded</span>
      </label>
      <?php if ($isRefunded): ?>
        <input type="date" name="refund_date" value="<?= h($r['refund_date'] ?? '') ?>" style="font-size:0.82rem; padding:0.25rem 0.4rem;" title="Refund Date">
        <input name="refund_note" value="<?= h($r['refund_note'] ?? '') ?>" placeholder="Refund note..." style="font-size:0.82rem; padding:0.25rem 0.4rem; width:150px;">
        <button class="btn sm" type="submit">Save Refund Details</button>
      <?php endif; ?>
    </form>
  </div>
  <?php else: ?>
  <p class="small muted" style="margin:0.25rem 0 0 0;">
    Lifecycle status, verification, and refunds are managed by Administrators and PMS Leads.
  </p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($errors): ?><div class="flash bad" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<!-- Main Student Profile Fields Form -->
<form method="post" enctype="multipart/form-data" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <?php foreach (FIELD_GROUPS as $group => $fields): ?>
  <fieldset><legend><?= h($group) ?></legend><div class="fields">
    <?php foreach ($fields as $k => $f):
        $v = (string)($r[$k] ?? '');
        $wide = in_array($f[1], ['textarea', 'readonly_textarea', 'url'], true) || in_array($k, ['disc2', 'disc3'], true) ? ' wide' : ''; ?>
      <label class="f<?= $wide ?>"><span><?= h($f[0]) ?><?= $k === 'name' ? ' *' : '' ?></span>
      <?php switch ($f[1]):
        case 'officer':
            if ($admin): ?>
              <select name="<?= $k ?>" <?= $isCounselor ? 'disabled' : '' ?>><option value="">Not assigned</option>
                <?php foreach ($officers as $o):
                  $deptTag = ($o['department'] ?? 'backend') === 'frontend' ? 'Front End' : 'Back End';
                ?><option value="<?= $o['id'] ?>" <?= (string)$o['id'] === $v ? 'selected' : '' ?>><?= h($o['name']) ?> (<?= $deptTag ?>)</option><?php endforeach; ?>
              </select>
            <?php else: ?><input value="<?= h($officerMap[(int)$v] ?? 'Not assigned') ?>" disabled><?php endif;
            break;
        case 'yn': ?>
              <select name="<?= $k ?>" <?= $isCounselor ? 'disabled' : '' ?>><?php foreach (['' => 'Not set', 'Yes' => 'Yes', 'No' => 'No'] as $ov => $ol): ?><option value="<?= $ov ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ol ?></option><?php endforeach; ?></select>
        <?php break;
        case 'select': ?>
              <select name="<?= $k ?>" <?= $isCounselor ? 'disabled' : '' ?>><?php foreach ($f[2] as $ov): ?><option value="<?= h($ov) ?>" <?= $v === $ov ? 'selected' : '' ?>><?= $ov === '' ? 'Not set' : h($ov) ?></option><?php endforeach; ?></select>
        <?php break;
        case 'readonly_textarea': ?>
              <textarea rows="2" readonly disabled style="background:var(--sunk); color:var(--muted);"><?= h($v) ?></textarea>
              <span class="small muted">Remarks are append-only. Use the "Add Remark" section below.</span>
        <?php break;
        case 'textarea': ?>
              <textarea name="<?= $k ?>" rows="3" <?= $isCounselor ? 'readonly' : '' ?>><?= h($v) ?></textarea>
        <?php break;
        case 'date': ?>
              <input type="date" name="<?= $k ?>" value="<?= h($v) ?>" <?= $isCounselor ? 'readonly' : '' ?>>
        <?php break;
        case 'url': ?>
              <input type="url" name="<?= $k ?>" value="<?= h($v) ?>" placeholder="https://drive.google.com/..." <?= $isCounselor ? 'readonly' : '' ?>>
              <?php if (safe_link($v)): ?>
                <a class="small" href="<?= h($v) ?>" target="_blank" rel="noopener noreferrer" style="font-weight:600; display:inline-block; margin-top:0.2rem;">
                  <?= $k === 'meeting_link' ? '🎥 Open Meeting / Recording ↗' : '📁 Open Drive Folder ↗' ?>
                </a>
              <?php endif; ?>
        <?php break;
        default: ?>
              <input name="<?= $k ?>" value="<?= h($v) ?>" <?= $k === 'name' ? 'required' : '' ?> <?= $isCounselor ? 'readonly' : '' ?>>
      <?php endswitch; ?>
      </label>
    <?php endforeach; ?>
  </div></fieldset>
  <?php endforeach; ?>

  <fieldset><legend>Documents &amp; Attachments</legend>
    <p class="muted small">Update document statuses and view, download, or upload verified document files.</p>
    <div class="doc-grid">
    <?php foreach (DOCS as $i => $d): 
      $v = $docs[$i] ?? ''; 
      $dRow = $docRows[$i] ?? null;
      $hasFile = !empty($dRow['file_path']);
      $fileName = $dRow['file_name'] ?? '';
      $fileKb = round(($dRow['file_size'] ?? 0) / 1024, 1);
    ?>
      <div class="doc-card">
        <div>
          <span style="font-weight:600; font-size:0.85rem; display:block;"><?= h($d) ?></span>
          <?php if ($hasFile): ?>
            <div class="doc-attachment-box">
              <div class="doc-attachment-name">📎 <?= h($fileName) ?> <span class="muted" style="font-size:0.75rem;">(<?= $fileKb ?> KB)</span></div>
              <div style="display:flex; gap:0.4rem; align-items:center; margin-top:0.3rem;">
                <a class="btn sm" href="<?= h(url('document.php?id=' . $id . '&doc=' . $i)) ?>" target="_blank">📄 View</a>
                <a class="btn sm primary" href="<?= h(url('document.php?id=' . $id . '&doc=' . $i . '&download=1')) ?>">⬇ Download</a>
                <?php if (!$isCounselor): ?>
                  <label class="small check doc-remove-label">
                    <input type="checkbox" name="remove_doc_file_<?= $i ?>" value="1"> Remove
                  </label>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if (!$isCounselor): ?>
          <div style="margin-top: 0.35rem;">
            <label class="small muted" style="display:block; margin-bottom:0.15rem;"><?= $hasFile ? 'Replace File (Max 15MB):' : 'Attach File (PDF, Images, Word):' ?></label>
            <input type="file" name="doc_file_<?= $i ?>" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.zip" style="font-size:0.75rem;">
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top: 0.5rem;">
          <label class="f">
            <span>Status</span>
            <select name="doc_<?= $i ?>" <?= (!$hasFile ? 'title="Attach a file to mark as Received or Verified"' : '') ?> <?= $isCounselor ? 'disabled' : '' ?>>
              <?php foreach (DOC_STATUS as $opt): 
                $dis = (!$hasFile && ($opt === 'Received' || $opt === 'Verified')) ? 'disabled' : '';
              ?>
                <option value="<?= h($opt) ?>" <?= $v === $opt ? 'selected' : '' ?> <?= $dis ?>><?= $opt === '' ? 'Not set' : h($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="actions">
    <?php if (!$isCounselor): ?>
      <button class="btn primary" type="submit"><?= $isNew ? 'Create student' : 'Save profile changes' ?></button>
    <?php else: ?>
      <span class="pill muted" style="font-size:0.85rem; padding:0.4rem 0.8rem;">Counselor Read-Only Access</span>
    <?php endif; ?>
    <a class="btn" href="<?= h(url($base . '/students.php')) ?>">Back to list</a>
  </div>
</form>

<?php if (!$isNew): ?>
<!-- Phase 3: Signed Student Consent Documents Section -->
<section class="panel" id="consent" style="margin-top:1.5rem;">
  <div class="panel-h">
    <h2>Signed Consent Documents &amp; Country Templates</h2>
    <p>Upload signed student consent forms and download country-specific templates.</p>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:1.25rem;">
    <!-- Upload Signed Consent -->
    <div style="background:var(--sunk); border:1px solid var(--border); border-radius:6px; padding:1rem;">
      <h3 style="font-size:0.95rem; margin-top:0; margin-bottom:0.5rem;">Upload Signed Consent</h3>
      <form method="post" enctype="multipart/form-data" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_consent_doc">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        
        <?php if (!empty($consentTemplates)): ?>
        <label class="f">
          <span>Associated Template:</span>
          <select name="template_id">
            <option value="">General / None</option>
            <?php foreach ($consentTemplates as $ct): ?>
              <option value="<?= (int)$ct['id'] ?>"><?= h($ct['title']) ?> (<?= h($ct['country'] ?: 'Global') ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>

        <label class="f">
          <span>Select File (PDF, DOCX, JPG, PNG &le; 10MB):</span>
          <input type="file" name="consent_doc" accept=".pdf,.docx,.jpg,.jpeg,.png" required style="font-size:0.82rem;">
        </label>
        <div>
          <button class="btn sm primary" type="submit">Upload Signed Consent</button>
        </div>
      </form>
    </div>

    <!-- Country Consent Templates Available -->
    <div style="background:var(--sunk); border:1px solid var(--border); border-radius:6px; padding:1rem;">
      <h3 style="font-size:0.95rem; margin-top:0; margin-bottom:0.5rem;">Official Consent Templates</h3>
      
      <?php if ($applicableTemplate): ?>
        <div style="background:var(--surface); border:1.5px solid var(--accent, #0284c7); border-radius:6px; padding:0.75rem 0.85rem; margin-bottom:0.75rem;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
            <div>
              <span class="pill sm acc" style="font-weight:700;">
                <?= !empty($applicableTemplate['country']) ? '📍 ' . h($applicableTemplate['country']) : '🌐 General Template' ?>
              </span>
              <div style="font-weight:700; font-size:0.92rem; margin-top:0.25rem;"><?= h($applicableTemplate['title']) ?></div>
              <div class="small muted"><?= h($applicableTemplate['original_name']) ?> &middot; <?= round((int)$applicableTemplate['file_size'] / 1024, 1) ?> KB</div>
            </div>
            <a class="btn sm primary" href="<?= h(url('consent_download.php?type=template&id=' . (int)$applicableTemplate['id'] . '&download=1')) ?>">⬇ Download Template</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($consentTemplates)): ?>
        <p class="small muted">No consent templates configured in the system yet.</p>
      <?php else: ?>
        <div class="small muted" style="margin-bottom:0.35rem; font-weight:600;">All Available System Templates:</div>
        <div style="display:flex; flex-direction:column; gap:0.4rem;">
          <?php foreach ($consentTemplates as $ct): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--surface); border:1px solid var(--border); border-radius:4px; padding:0.4rem 0.6rem;">
              <div>
                <b style="font-size:0.85rem;"><?= h($ct['title']) ?></b>
                <div class="small muted"><?= h($ct['country'] ?: 'General') ?> &middot; <?= round((int)$ct['file_size'] / 1024, 1) ?> KB</div>
              </div>
              <a class="btn sm" href="<?= h(url('consent_download.php?type=template&id=' . (int)$ct['id'] . '&download=1')) ?>">⬇ Download</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Uploaded Consent Documents List -->
  <div style="margin-top:1.25rem;">
    <h3 style="font-size:0.95rem; margin-bottom:0.5rem;">Uploaded Consent Files for this Student</h3>
    <?php if (empty($consentDocs)): ?>
      <p class="small muted">No signed consent documents attached yet.</p>
    <?php else: ?>
      <div class="tbl"><table>
        <thead>
          <tr>
            <th>Document</th>
            <th>Size</th>
            <th>Uploaded By</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($consentDocs as $cd): ?>
            <tr>
              <td><b>📄 <?= h($cd['original_name']) ?></b></td>
              <td><?= round((int)$cd['file_size'] / 1024, 1) ?> KB</td>
              <td><?= h($cd['uploader_name'] ?: 'Staff') ?></td>
              <td class="num"><?= h(fmt_date(substr((string)$cd['created_at'], 0, 10))) ?></td>
              <td>
                <a class="btn sm primary" href="<?= h(url('consent_download.php?type=doc&id=' . (int)$cd['id'])) ?>">⬇ Download</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</section>

<!-- Phase 2: Append-Only Remarks Timeline & Form -->
<section class="panel" id="remarks" style="margin-top:1.5rem;">
  <div class="panel-h">
    <h2>Remarks &amp; Activity Log (<?= count($studentRemarksList) ?>)</h2>
    <p>Remarks are append-only. Adding a remark clears the Pending status.</p>
  </div>

  <!-- Add Remark Form (Available to all staff and counselors) -->
  <form method="post" class="fields" style="margin-bottom:1.25rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_remark">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <label class="f wide">
      <span>Add New Internal Remark</span>
      <textarea name="remark_text" rows="3" required placeholder="Type a new remark or update for this student..."></textarea>
    </label>
    <div class="f">
      <span>&nbsp;</span>
      <button class="btn primary" type="submit">Add Remark</button>
    </div>
  </form>

  <!-- Remarks List -->
  <div class="list">
  <?php if (empty($studentRemarksList)): ?>
    <div class="empty">No remarks recorded yet.</div>
  <?php else: ?>
    <?php foreach ($studentRemarksList as $rem):
        $isLegacy = ($rem['author_name'] === 'Legacy import') || empty($rem['author_id']);
        $isCounselorRemark = (($rem['author_role'] ?? '') === 'counselor');
    ?>
      <div class="li" style="flex-direction:column; align-items:flex-start; gap:0.4rem; padding:0.85rem 1rem;">
        <div style="display:flex; justify-content:space-between; width:100%; align-items:center; flex-wrap:wrap; gap:0.5rem;">
          <div style="font-size:0.85rem;">
            <b><?= h($rem['author_name']) ?></b>
            <?php if ($isLegacy): ?>
              <span class="pill" style="font-size:0.7rem; margin-left:0.35rem; font-weight:600;">Legacy</span>
            <?php else: ?>
              <?php if ($isCounselorRemark): ?>
                <span class="pill sm" style="background:#0284c7; color:#fff; font-size:0.68rem; font-weight:600; margin-left:0.35rem;">Counselor</span>
              <?php endif; ?>
              <span class="muted small" style="margin-left:0.35rem;">· <?= h(fmt_date(substr((string)$rem['created_at'], 0, 10))) ?> (<?= h(time_ago((string)$rem['created_at'])) ?>)</span>
            <?php endif; ?>
          </div>
          <?php if ($admin && !$isLegacy): ?>
            <div style="display:flex; gap:0.3rem;">
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this remark?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_remark">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="remark_id" value="<?= (int)$rem['id'] ?>">
                <button class="btn sm bad" type="submit" style="padding:0.15rem 0.4rem; font-size:0.72rem;">Delete</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <div style="font-size:0.9rem; line-height:1.5; color:var(--fg); white-space:pre-wrap;"><?= h($rem['remark_text']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</section>

<!-- Phase 3: Audit Activity Log Section -->
<section class="panel" id="audit-log" style="margin-top:1.5rem;">
  <div class="panel-h">
    <h2>Student Audit History (<?= count($activityEntries) ?> entries)</h2>
    <p>Comprehensive audit record of updates, remarks, and status changes for compliance.</p>
  </div>

  <?php if (empty($activityEntries)): ?>
    <p class="small muted">No audit activity recorded yet for this student.</p>
  <?php else: ?>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th>Action</th>
          <th>Actor</th>
          <th>Details</th>
          <th>Timestamp</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activityEntries as $ae): 
            $actionLabel = ucwords(str_replace('_', ' ', (string)$ae['action']));
            $newV = json_decode((string)($ae['new_values'] ?? ''), true);
            $oldV = json_decode((string)($ae['old_values'] ?? ''), true);
        ?>
          <tr>
            <td><span class="pill sm"><?= h($actionLabel) ?></span></td>
            <td>
              <b><?= h($ae['actor_name'] ?: 'System') ?></b>
              <?php if (!empty($ae['actor_role'])): ?>
                <span class="small muted">(<?= h(role_label($ae['actor_role'])) ?>)</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($ae['action'] === 'status_changed'): ?>
                Status changed: <s><?= h($oldV['student_status'] ?? 'unknown') ?></s> &rarr; <b><?= h($newV['student_status'] ?? '') ?></b>
                <?php if (!empty($newV['disabled_reason'])): ?> (Reason: <?= h($newV['disabled_reason']) ?>)<?php endif; ?>
              <?php elseif ($ae['action'] === 'remark_added'): ?>
                Added remark: <i>"<?= h(mb_substr($newV['remark'] ?? '', 0, 75)) ?>"</i>
              <?php elseif ($ae['action'] === 'bulk_updated'): ?>
                Bulk updated: <?php if (isset($newV['program_level'])): ?>Program: <b><?= h($newV['program_level']) ?></b> <?php endif; ?><?php if (isset($newV['country'])): ?>Country: <b><?= h($newV['country']) ?></b><?php endif; ?>
              <?php elseif ($ae['action'] === 'refund_updated'): ?>
                Refund: <b><?= !empty($newV['is_refunded']) ? 'Marked Refunded' : 'Unmarked' ?></b>
              <?php elseif ($ae['action'] === 'verification_updated'): ?>
                Verification: <b><?= h($newV['csc_status'] ?? '') ?></b>
              <?php else: ?>
                <?= h(json_encode($newV, JSON_UNESCAPED_UNICODE)) ?>
              <?php endif; ?>
            </td>
            <td class="num small"><?= h($ae['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($login): ?>
<section class="panel danger" style="margin-top:20px;">
  <div class="panel-h"><h2>Student login account</h2><p>Credentials this student uses to access their portal.</p></div>
  <p>Username: <code class="mono"><?= h($login['username']) ?></code> &middot; Status: <span class="pill <?= $login['active'] ? 'ok' : 'bad' ?>"><?= $login['active'] ? 'Active' : 'Disabled' ?></span> &middot; Last sign-in: <?= $login['last_login'] ? h(fmt_date(substr($login['last_login'], 0, 10))) : 'Never' ?></p>
  <form method="post" class="row-actions"><?= csrf_field() ?>
    <button class="btn sm" formaction="<?= h(url('admin/logins.php')) ?>" name="action" value="reset" name="id" value="<?= $login['id'] ?>">Reset password</button>
    <button class="btn sm" formaction="<?= h(url('admin/logins.php')) ?>" name="action" value="toggle" name="id" value="<?= $login['id'] ?>"><?= $login['active'] ? 'Disable account' : 'Enable account' ?></button>
  </form>
</section>
<?php endif; ?>

<?php if (!$isNew && $admin): ?>
<section class="panel danger" style="margin-top:20px;">
  <div class="panel-h"><h2>Delete student</h2><p>Removes the student record, all uploaded document files, and their portal login account permanently.</p></div>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <label class="check"><input type="checkbox" name="confirm" value="yes"><span>I understand this cannot be undone</span></label>
    <div><button class="btn bad" type="submit">Delete student</button></div>
  </form>
</section>
<?php endif; ?>

<?php page_end();
