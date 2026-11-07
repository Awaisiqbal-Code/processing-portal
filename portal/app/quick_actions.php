<?php
declare(strict_types=1);
// Small "fix it here" actions used by the Pending Tracker and the profile.
// They write to the same student fields the profile uses, so there is only one place the data lives.

/** Meeting recording: link / uploaded video / reason it was not recorded. $act = meeting_save_link | meeting_upload_video | meeting_no_recording */
function save_meeting_recording(array $u, array $r, string $act, array $post, array $files): array {
    if (!can_update_journey($u) || !can_edit_student($u, $r)) return ['ok' => false, 'error' => 'You have read-only access to this student.'];
    $id = (int)$r['id'];
    $held = ($post['meeting_held'] ?? '') === '1' ? 'Yes' : null;
    $set = []; $msg = null; $err = null;
    if ($act === 'meeting_save_link') {
        $link = trim((string)($post['meeting_link'] ?? ''));
        if (!safe_link($link)) $err = 'Paste a full link starting with https://';
        elseif ($link === trim((string)($r['drive_link'] ?? ''))) $err = 'That is the documents folder link. Paste the link of the recording itself.';
        else { $set = ['meeting_link' => $link, 'recorded_uploaded' => 'Yes', 'meeting_note' => null]; $msg = 'Recording link saved.'; }
    } elseif ($act === 'meeting_no_recording') {
        $why = trim(mb_substr((string)($post['meeting_note'] ?? ''), 0, 500));
        if ($why === '') $err = 'Write why the meeting was not recorded.';
        else {
            $set = ['meeting_note' => $why, 'recorded_uploaded' => 'No'];
            if (!safe_link((string)($r['meeting_link'] ?? ''))) $set['meeting_link'] = null;   // old sheets kept the reason in this column
            $msg = 'Note saved: meeting not recorded.';
        }
    } elseif ($act === 'meeting_upload_video') {
        $oldVid = trim((string)($r['meeting_video_path'] ?? ''));
        if (($post['remove_video'] ?? '') === '1') {
            if ($oldVid !== '' && is_file(ROOT . '/' . $oldVid)) @unlink(ROOT . '/' . $oldVid);
            $set = ['meeting_video_path' => null, 'meeting_video_name' => null];
            if (!student_meeting_url($r)) $set['recorded_uploaded'] = null;
            $msg = 'Recording removed.'; $held = null;
        } else {
            $f = $files['meeting_video'] ?? null;
            $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (!$f || ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) $err = 'Choose the recording file first.';
            elseif (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) $err = 'The file is larger than the server allows (' . (int)(meeting_upload_limit() / 1048576) . ' MB). Upload it to Drive and use the Link option instead.';
            elseif ($f['error'] !== UPLOAD_ERR_OK) $err = 'Upload failed, please try again.';
            elseif (!in_array($ext, ['mp4', 'mov', 'webm', 'mkv', 'm4v', 'm4a', 'mp3', 'wav', 'ogg'], true)) $err = 'Upload a video or audio file (mp4, mov, webm, mkv, m4a, mp3).';
            else {
                $dir = ROOT . '/uploads/meetings/' . $id;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $disk = 'meeting_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                if (is_dir($dir) && is_writable($dir) && move_uploaded_file($f['tmp_name'], $dir . '/' . $disk)) {
                    if ($oldVid !== '' && is_file(ROOT . '/' . $oldVid)) @unlink(ROOT . '/' . $oldVid);
                    $set = ['meeting_video_path' => 'uploads/meetings/' . $id . '/' . $disk, 'meeting_video_name' => basename((string)$f['name']), 'recorded_uploaded' => 'Yes', 'meeting_note' => null];
                    $msg = 'Recording uploaded.';
                } else $err = 'The server could not save the file here. Upload it to Drive and use the Link option instead.';
            }
        }
    } else {
        $err = 'Unknown action.';
    }
    if ($err) return ['ok' => false, 'error' => $err];
    if ($held) $set['initial_meeting'] = 'Yes';
    student_quick_update($u, $id, $set, 'meeting_updated');
    return ['ok' => true, 'message' => $msg ?? 'Saved.'];
}

/** Writes a few fields on a student the same way the profile form does (edit stamp + "remark due" timer). */
function student_quick_update(array $u, int $id, array $set, string $what): void {
    if (!$set) return;
    $now = now();
    $set['updated_at'] = $now;
    $set['last_edited_at'] = $now;
    $set['last_edited_by'] = (int)$u['id'];
    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    q("UPDATE students SET $cols, pending_since = COALESCE(pending_since, ?) WHERE id = ?", array_merge(array_values($set), [$now, $id]));
    $logged = array_diff_key($set, array_flip(['updated_at', 'last_edited_at', 'last_edited_by', 'meeting_video_path']));
    log_activity('student', $id, $what, null, $logged, (int)$u['id']);
}

/** Sets one document's status (and remembers who did it). */
function set_document_status(array $u, int $sid, int $idx, string $status): bool {
    if (!isset(DOCS[$idx]) || !in_array($status, DOC_STATUS, true)) return false;
    if ($status === 'Verified' && !can_verify_docs($u)) return false;
    $ex = q('SELECT status FROM student_documents WHERE student_id = ? AND doc_index = ?', [$sid, $idx])->fetch();
    if ($ex) {
        if ($ex['status'] === $status) return true;
        q('UPDATE student_documents SET status = ?, updated_by = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?', [$status, (int)$u['id'], now(), $sid, $idx]);
    } else {
        q('INSERT INTO student_documents (student_id, doc_index, status, updated_by, updated_at) VALUES (?,?,?,?,?)', [$sid, $idx, $status, (int)$u['id'], now()]);
    }
    log_activity('student', $sid, 'document_status', ['doc' => DOCS[$idx], 'status' => $ex['status'] ?? null], ['doc' => DOCS[$idx], 'status' => $status], (int)$u['id']);
    return true;
}

/**
 * One pending item fixed straight from the Pending Tracker.
 * Returns ['ok' => bool, 'message' | 'error'].
 */
function pending_quick_action(array $u, array $r, string $act, array $post, array $files): array {
    $sid = (int)$r['id'];
    if ($act === 'remark') {
        $txt = trim((string)($post['remark_text'] ?? ''));
        if ($txt === '') return ['ok' => false, 'error' => 'Write the remark first.'];
        $res = add_student_remark($u, $sid, $txt);
        return ($res['ok'] ?? false) ? ['ok' => true, 'message' => 'Remark added for ' . $r['name'] . '.'] : ['ok' => false, 'error' => $res['error'] ?? 'Could not add the remark.'];
    }
    if (($u['role'] ?? '') === 'counselor' || !can_edit_student($u, $r)) return ['ok' => false, 'error' => view_only_message('students.edit_all')];

    switch ($act) {
        case 'docs_received':
            $idx = array_values(array_unique(array_map('intval', (array)($post['docs'] ?? []))));
            $idx = array_filter($idx, fn($i) => isset(REQUIRED_DOCS[$i]));
            if (!$idx) return ['ok' => false, 'error' => 'Tick the documents you have received.'];
            foreach ($idx as $i) set_document_status($u, $sid, $i, 'Received');
            sync_docs_checklist($sid);
            student_quick_update($u, $sid, [], 'documents_received');
            return ['ok' => true, 'message' => count($idx) . ' document' . (count($idx) === 1 ? '' : 's') . ' marked Received for ' . $r['name'] . '.'];
        case 'docs_asked':
            $idx = array_filter(array_map('intval', (array)($post['docs'] ?? [])), fn($i) => isset(REQUIRED_DOCS[$i]));
            foreach ($idx as $i) set_document_status($u, $sid, $i, 'Requested');
            return ['ok' => true, 'message' => 'Marked as asked from the student.'];
        case 'consent':
            student_quick_update($u, $sid, ['consent' => 'Yes'], 'consent_received');
            return ['ok' => true, 'message' => 'Consent form marked received for ' . $r['name'] . '.'];
        case 'first_meeting':
            if (($post['held'] ?? '') === '1') {
                student_quick_update($u, $sid, ['initial_meeting' => 'Yes'], 'first_meeting_held');
                return ['ok' => true, 'message' => '1st meeting marked held for ' . $r['name'] . '.'];
            }
            $d = (string)($post['follow_up'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['ok' => false, 'error' => 'Pick the follow-up date.'];
            student_quick_update($u, $sid, ['follow_up_1' => $d], 'first_meeting_followup');
            return ['ok' => true, 'message' => 'Follow-up date saved for ' . $r['name'] . '.'];
        case 'meeting_save_link':
        case 'meeting_no_recording':
        case 'meeting_upload_video':
            return save_meeting_recording($u, $r, $act, $post, $files);
        case 'second_tax':
            if (($post['held'] ?? '') === '1') {
                student_quick_update($u, $sid, ['tax_meeting' => 'Yes'], 'tax_meeting_held');
                return ['ok' => true, 'message' => 'Tax consultant meeting marked held for ' . $r['name'] . '.'];
            }
            $d = (string)($post['follow_up'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['ok' => false, 'error' => 'Pick the follow-up date.'];
            student_quick_update($u, $sid, ['follow_up_3' => $d], 'tax_meeting_followup');
            return ['ok' => true, 'message' => 'Tax meeting follow-up date saved for ' . $r['name'] . '.'];
        case 'shortlist':
            $uni = trim(mb_substr((string)($post['shortlisting'] ?? ''), 0, 300));
            if ($uni === '') return ['ok' => false, 'error' => 'Write the shortlisted university (or universities).'];
            student_quick_update($u, $sid, ['shortlisting' => $uni], 'shortlisting_added');
            return ['ok' => true, 'message' => 'Shortlisting saved for ' . $r['name'] . '.'];
        case 'credentials':
            $set = [];
            $em = strtolower(trim((string)($post['email'] ?? '')));
            $pid = trim(mb_substr((string)($post['portal_id'] ?? ''), 0, 120));
            if ($em !== '') {
                if (!filter_var($em, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'That email does not look right.'];
                $set['email'] = $em;
            }
            if ($pid !== '') $set['portal_id'] = $pid;
            if (!$set) return ['ok' => false, 'error' => 'Enter the email or the portal ID.'];
            student_quick_update($u, $sid, $set, 'credentials_added');
            return ['ok' => true, 'message' => 'Saved for ' . $r['name'] . '.'];
    }
    return ['ok' => false, 'error' => 'Unknown action.'];
}

/** Received documents waiting for the PMS Lead to check (status Received, required + later documents). */
function docs_waiting_verification(array $u, ?int $officerId = null): array {
    [$w, $p] = scope_sql($u, 's');
    $sql = "SELECT d.student_id, d.doc_index, d.status, d.file_path, d.file_name, d.updated_at, d.updated_by, d.uploaded_by,
                   s.name, s.country, s.program_level, s.officer_id, s.frontend_officer_id, s.drive_link, s.sheet_sr, s.docs_checklist
            FROM student_documents d JOIN students s ON s.id = d.student_id
            WHERE d.status = 'Received' AND s.student_status = 'active' AND $w";
    $args = $p;
    if ($officerId) { $sql .= ' AND (s.officer_id = ? OR s.frontend_officer_id = ?)'; $args[] = $officerId; $args[] = $officerId; }
    return q($sql . ' ORDER BY s.name COLLATE NOCASE, d.doc_index', $args)->fetchAll();
}
