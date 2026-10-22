<?php
declare(strict_types=1);

/**
 * Processing Team Portal — Services Layer (Phase 2 & Phase 3)
 */

// =========================================================================
// 1. Remarks Service (Append-only + students.remarks sync + Soft Delete)
// =========================================================================

/**
 * Adds an append-only remark to student_remarks and synchronizes students.remarks
 * and students.last_remark_at to clear the Pending timer.
 * Does NOT update last_edited_at / last_edited_by.
 */
function add_student_remark(array $author, int $studentId, string $remarkText): array {
    if (!is_staff_role($author['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only staff members can add remarks.'];
    }

    $cleanText = trim($remarkText);
    if ($cleanText === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'Remark text cannot be empty.'];
    }
    if (mb_strlen($cleanText) > 5000) {
        return ['ok' => false, 'code' => 400, 'error' => 'Remark exceeds 5,000 characters limit.'];
    }

    $st = q("SELECT id, name, officer_id, frontend_officer_id, assigned_pms_lead_id FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) {
        return ['ok' => false, 'code' => 404, 'error' => 'Student not found.'];
    }

    $now = now();
    $authorName = (string)($author['name'] ?? 'Staff');
    $authorId = (int)($author['id'] ?? 0);

    // 1. Insert append-only remark record
    q("INSERT INTO student_remarks (student_id, remark_text, author_id, author_name, created_at) VALUES (?, ?, ?, ?, ?)", [
        $studentId,
        $cleanText,
        $authorId ?: null,
        $authorName,
        $now,
    ]);
    $remarkId = (int)db()->lastInsertId();

    // 2. Synchronize backward compatibility: students.remarks & students.last_remark_at
    // Notice: last_edited_at and last_edited_by are strictly NOT touched.
    // Rule: Counselors can add remarks, but counselor remarks do NOT clear Pending.
    // Tunable pending clearance: processing staff + leads clear pending by default; or PMS lead only if setting is enabled.
    $isCounselor = ($author['role'] ?? '') === 'counselor';
    $pendingPmsOnly = setting('pending_clear_pms_only', '0') === '1';
    $canClearPending = !$isCounselor && (!$pendingPmsOnly || in_array($author['role'] ?? '', ['super_admin', 'admin', 'pms_lead'], true));

    if ($canClearPending) {
        q("UPDATE students SET remarks = ?, last_remark_at = ?, pending_since = NULL WHERE id = ?", [
            $cleanText,
            $now,
            $studentId,
        ]);
    } else {
        q("UPDATE students SET remarks = ? WHERE id = ?", [
            $cleanText,
            $studentId,
        ]);
    }

    // 3. Log to activity_logs
    log_activity(
        'student',
        $studentId,
        'remark_added',
        null,
        ['remark' => mb_substr($cleanText, 0, 100), 'author' => $authorName, 'remark_id' => $remarkId],
        $authorId ?: null
    );

    // 4. Notify assigned officers if someone else leaves a remark
    $recipientIds = array_unique(array_filter([
        (int)($st['officer_id'] ?? 0),
        (int)($st['frontend_officer_id'] ?? 0),
        (int)($st['assigned_pms_lead_id'] ?? 0),
    ]));
    foreach ($recipientIds as $recId) {
        if ($recId > 0 && $recId !== $authorId) {
            create_notification(
                $recId,
                'remark_added',
                'New remark on ' . $st['name'],
                "{$authorName} added a remark: \"" . mb_substr($cleanText, 0, 60) . '..."',
                $studentId
            );
        }
    }

    return [
        'ok'         => true,
        'code'       => 201,
        'remark_id'  => $remarkId,
        'created_at' => $now,
        'author_name'=> $authorName,
        'remark_text'=> $cleanText,
    ];
}

/**
 * Edit a remark (Allowed ONLY for super_admin or admin).
 */
function edit_student_remark(array $actor, int $remarkId, string $newText): array {
    if (!is_admin_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only administrators can edit remarks.'];
    }

    $clean = trim($newText);
    if ($clean === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'Remark text cannot be empty.'];
    }

    $r = q("SELECT * FROM student_remarks WHERE id = ? AND deleted_at IS NULL", [$remarkId])->fetch();
    if (!$r) {
        return ['ok' => false, 'code' => 404, 'error' => 'Remark not found.'];
    }

    $oldText = $r['remark_text'];
    q("UPDATE student_remarks SET remark_text = ? WHERE id = ?", [$clean, $remarkId]);

    // If this was the most recent remark on the student, sync students.remarks
    $latestId = (int)q("SELECT id FROM student_remarks WHERE student_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1", [(int)$r['student_id']])->fetchColumn();
    if ($latestId === $remarkId) {
        q("UPDATE students SET remarks = ? WHERE id = ?", [$clean, (int)$r['student_id']]);
    }

    log_activity(
        'student',
        (int)$r['student_id'],
        'remark_edited',
        ['old' => mb_substr($oldText, 0, 100)],
        ['new' => mb_substr($clean, 0, 100), 'remark_id' => $remarkId],
        (int)$actor['id']
    );

    return ['ok' => true, 'code' => 200];
}

/**
 * Soft Delete a remark (Allowed ONLY for super_admin or admin).
 * Sets deleted_at and deleted_by, preserves text in activity_logs, and resyncs from latest non-deleted remark.
 */
function delete_student_remark(array $actor, int $remarkId): array {
    if (!is_admin_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only administrators can delete remarks.'];
    }

    $r = q("SELECT * FROM student_remarks WHERE id = ? AND deleted_at IS NULL", [$remarkId])->fetch();
    if (!$r) {
        return ['ok' => false, 'code' => 404, 'error' => 'Remark not found or already deleted.'];
    }

    $studentId = (int)$r['student_id'];
    $now = now();
    $actorId = (int)$actor['id'];

    // Soft delete
    q("UPDATE student_remarks SET deleted_at = ?, deleted_by = ? WHERE id = ?", [$now, $actorId, $remarkId]);

    // Resync students.remarks and students.last_remark_at from newest remaining non-deleted remark
    $latest = q("SELECT remark_text, created_at FROM student_remarks WHERE student_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1", [$studentId])->fetch();
    q("UPDATE students SET remarks = ?, last_remark_at = ? WHERE id = ?", [
        $latest['remark_text'] ?? null,
        $latest['created_at'] ?? null,
        $studentId,
    ]);

    log_activity(
        'student',
        $studentId,
        'remark_deleted',
        ['remark_id' => $remarkId, 'deleted_text' => mb_substr($r['remark_text'], 0, 150)],
        ['deleted_at' => $now, 'deleted_by' => $actorId],
        $actorId
    );

    return ['ok' => true, 'code' => 200];
}

/**
 * Lists all active (non-deleted) remarks for a student ordered latest first.
 */
function get_student_remarks(int $studentId): array {
    return q("
        SELECT sr.id, sr.student_id, sr.remark_text, sr.author_id, sr.author_name, sr.created_at, u.role AS author_role
        FROM student_remarks sr
        LEFT JOIN users u ON u.id = sr.author_id
        WHERE sr.student_id = ? AND sr.deleted_at IS NULL
        ORDER BY sr.created_at DESC, sr.id DESC
    ", [$studentId])->fetchAll();
}

// =========================================================================
// 2. Student Lifecycle Status, Verification & Refund Services
// =========================================================================

/**
 * Check whether an actor has managerial privileges (super_admin, admin, pms_lead).
 * Processing_staff and counselors cannot disable, refund, or verify.
 */
function can_manage_student_lifecycle(array $actor): bool {
    $role = $actor['role'] ?? '';
    return in_array($role, ['super_admin', 'admin', 'pms_lead'], true);
}

/**
 * Change student lifecycle status (active, inactive, disabled).
 * Reason is strictly required when status is 'disabled'.
 * Disabling or in-activating adds the reason as an automatic remark prefixed with "[Status: ...]".
 * Sets last_edited_at and last_edited_by.
 */
function change_student_status(array $actor, int $studentId, string $newStatus, ?string $disabledReason = null): array {
    if (!can_manage_student_lifecycle($actor)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied: Processing staff and counselors cannot change student lifecycle status.'];
    }

    if (!in_array($newStatus, ['active', 'inactive', 'disabled'], true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid status value. Must be active, inactive, or disabled.'];
    }

    $st = q("SELECT id, name, student_status, disabled_reason, assigned_pms_lead_id FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) {
        return ['ok' => false, 'code' => 404, 'error' => 'Student not found.'];
    }

    $actorId = (int)($actor['id'] ?? 0);
    $now = now();
    $oldStatus = (string)($st['student_status'] ?? 'active');

    if ($newStatus === 'disabled') {
        $cleanReason = trim((string)$disabledReason);
        if (mb_strlen($cleanReason) < 3) {
            return ['ok' => false, 'code' => 400, 'error' => 'A valid reason is required when disabling a student account.'];
        }
        q("UPDATE students SET student_status = 'disabled', disabled_reason = ?, disabled_by = ?, disabled_at = ?, last_edited_at = ?, last_edited_by = ?, pending_since = COALESCE(pending_since, ?) WHERE id = ?", [
            $cleanReason,
            $actorId ?: null,
            $now,
            $now,
            $actorId ?: null,
            $now,
            $studentId,
        ]);
        // Disabling student also deactivates their portal login
        q("UPDATE users SET active = 0 WHERE student_id = ?", [$studentId]);

        // Auto-add remark so it clears pending and appears in history
        add_student_remark($actor, $studentId, "[Status: disabled] " . $cleanReason);
    } elseif ($newStatus === 'inactive') {
        $note = trim((string)$disabledReason);
        q("UPDATE students SET student_status = 'inactive', disabled_reason = ?, disabled_by = NULL, disabled_at = NULL, last_edited_at = ?, last_edited_by = ?, pending_since = COALESCE(pending_since, ?) WHERE id = ?", [
            $note !== '' ? $note : null,
            $now,
            $actorId ?: null,
            $now,
            $studentId,
        ]);
        // Auto-add remark for inactive
        $remarkText = "[Status: inactive]" . ($note !== '' ? " " . $note : " Student marked as inactive.");
        add_student_remark($actor, $studentId, $remarkText);
    } else {
        // Active
        q("UPDATE students SET student_status = 'active', disabled_reason = NULL, disabled_by = NULL, disabled_at = NULL, last_edited_at = ?, last_edited_by = ?, pending_since = COALESCE(pending_since, ?) WHERE id = ?", [
            $now,
            $actorId ?: null,
            $now,
            $studentId,
        ]);
        // Reactivating restores student portal login
        q("UPDATE users SET active = 1 WHERE student_id = ?", [$studentId]);
    }

    log_activity(
        'student',
        $studentId,
        'status_changed',
        ['student_status' => $oldStatus],
        ['student_status' => $newStatus, 'disabled_reason' => $disabledReason],
        $actorId ?: null
    );

    // Phase 4: Notify assigned PMS Lead if someone else made the status change
    $pmsLeadId = (int)($st['assigned_pms_lead_id'] ?? 0);
    if ($pmsLeadId <= 0) {
        $pmsLeadId = (int)setting('default_pms_lead_id', 0);
    }
    if ($pmsLeadId > 0 && $pmsLeadId !== $actorId) {
        $reasonSnippet = $disabledReason ? " (Reason: {$disabledReason})" : "";
        create_notification(
            $pmsLeadId,
            'status_changed',
            "Status changed for {$st['name']}",
            "{$actor['name']} changed {$st['name']}'s status to " . ucfirst($newStatus) . $reasonSnippet . ".",
            $studentId
        );
    }

    return ['ok' => true, 'code' => 200, 'new_status' => $newStatus];
}

/**
 * Mark a student refunded with refund_date and refund_note.
 * Restricted to super_admin, admin, pms_lead.
 * Sets last_edited_at and last_edited_by.
 */
function mark_student_refunded(array $actor, int $studentId, bool $isRefunded, ?string $refundDate = null, ?string $refundNote = null): array {
    if (!can_manage_student_lifecycle($actor)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied: Only administrators and PMS Leads can manage student refunds.'];
    }

    $st = q("SELECT id, name, is_refunded, refund_date, refund_note FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) {
        return ['ok' => false, 'code' => 404, 'error' => 'Student not found.'];
    }

    $actorId = (int)($actor['id'] ?? 0);
    $now = now();
    $oldValues = [
        'is_refunded' => (int)($st['is_refunded'] ?? 0),
        'refund_date' => $st['refund_date'],
        'refund_note' => $st['refund_note'],
    ];

    $newVal = $isRefunded ? 1 : 0;
    $dateVal = $isRefunded ? ($refundDate ?: substr($now, 0, 10)) : null;
    $noteVal = $isRefunded ? trim((string)$refundNote) : null;

    q("UPDATE students SET is_refunded = ?, refund_date = ?, refund_note = ?, last_edited_at = ?, last_edited_by = ?, pending_since = COALESCE(pending_since, ?) WHERE id = ?", [
        $newVal,
        $dateVal,
        $noteVal,
        $now,
        $actorId ?: null,
        $now,
        $studentId,
    ]);

    log_activity(
        'student',
        $studentId,
        'refunded',
        $oldValues,
        ['is_refunded' => $newVal, 'refund_date' => $dateVal, 'refund_note' => $noteVal],
        $actorId ?: null
    );

    return ['ok' => true, 'code' => 200, 'is_refunded' => $newVal];
}

/**
 * Set student verification using existing csc_status, csc_verified_at, csc_verified_by columns.
 * Restricted to super_admin, admin, pms_lead.
 * Sets last_edited_at and last_edited_by.
 */
function set_student_verification(array $actor, int $studentId, bool $verified): array {
    if (!can_manage_student_lifecycle($actor)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied: Only administrators and PMS Leads can verify students.'];
    }

    $st = q("SELECT id, name, csc_status FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) {
        return ['ok' => false, 'code' => 404, 'error' => 'Student not found.'];
    }

    $actorId = (int)($actor['id'] ?? 0);
    $now = now();
    $oldStatus = (string)($st['csc_status'] ?? '');
    $newStatus = $verified ? 'Verified' : 'Unverified';

    q("UPDATE students SET csc_status = ?, csc_verified_at = ?, csc_verified_by = ?, last_edited_at = ?, last_edited_by = ?, pending_since = COALESCE(pending_since, ?) WHERE id = ?", [
        $newStatus,
        $verified ? $now : null,
        $verified ? $actorId : null,
        $now,
        $actorId ?: null,
        $now,
        $studentId,
    ]);

    log_activity(
        'student',
        $studentId,
        'verified',
        ['csc_status' => $oldStatus],
        ['csc_status' => $newStatus, 'verified' => $verified],
        $actorId ?: null
    );

    return ['ok' => true, 'code' => 200, 'verified' => $verified];
}

// =========================================================================
// 3. Bulk Edit Service (Phase 3)
// =========================================================================

/**
 * Bulk updates program_level and/or country for selected students.
 * Allowed for super_admin, admin, pms_lead.
 * Does NOT update last_edited_at.
 * Does NOT fire notifications.
 * Logs each changed student in activity_logs.
 */
function bulk_update_students(array $actor, array $studentIds, ?string $programLevel, ?string $country): array {
    if (!can_manage_student_lifecycle($actor)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied: Only administrators and PMS Leads can perform bulk edits.'];
    }

    $validLevels = ['BS', 'MS'];
    if ($programLevel !== null && $programLevel !== '' && !in_array($programLevel, $validLevels, true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid program level. Must be BS or MS.'];
    }

    $cleanCountry = ($country !== null && trim($country) !== '') ? trim($country) : null;
    $cleanLevel = ($programLevel !== null && trim($programLevel) !== '') ? trim($programLevel) : null;

    if ($cleanLevel === null && $cleanCountry === null) {
        return ['ok' => false, 'code' => 400, 'error' => 'Specify at least program level or country to update.'];
    }

    $cleanIds = array_unique(array_filter(array_map('intval', $studentIds)));
    if (empty($cleanIds)) {
        return ['ok' => false, 'code' => 400, 'error' => 'No valid students selected.'];
    }

    $updatedCount = 0;
    $actorId = (int)$actor['id'];

    foreach ($cleanIds as $sid) {
        $st = q("SELECT id, name, program_level, country FROM students WHERE id = ?", [$sid])->fetch();
        if (!$st) continue;

        $updates = [];
        $params = [];
        $oldVals = [];
        $newVals = [];

        if ($cleanLevel !== null) {
            $updates[] = "program_level = ?";
            $params[] = $cleanLevel;
            $oldVals['program_level'] = $st['program_level'];
            $newVals['program_level'] = $cleanLevel;
        }
        if ($cleanCountry !== null) {
            $updates[] = "country = ?";
            $params[] = $cleanCountry;
            $oldVals['country'] = $st['country'];
            $newVals['country'] = $cleanCountry;
        }

        if (!empty($updates)) {
            $params[] = $sid;
            q("UPDATE students SET " . implode(", ", $updates) . " WHERE id = ?", $params);
            log_activity('student', $sid, 'bulk_updated', $oldVals, $newVals, $actorId);
            $updatedCount++;
        }
    }

    return [
        'ok'            => true,
        'code'          => 200,
        'updated_count' => $updatedCount,
    ];
}

// =========================================================================
// 4. Consent Templates & Consent Documents Service
// =========================================================================

function secure_consents_storage_dir(): string {
    $railwayData = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: (is_dir('/data') ? '/data' : '');
    $base = ($railwayData !== '' && is_dir($railwayData) && is_writable($railwayData))
        ? rtrim($railwayData, '/\\') . '/storage/consent'
        : dirname(ROOT) . '/storage_secure/consent';

    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

/**
 * Upload a country-specific consent template (Super Admin or Admin only).
 */
function upload_consent_template(array $actor, ?string $country, string $title, array $file): array {
    if (!is_admin_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only administrators can upload consent templates.'];
    }

    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'Template title is required.'];
    }

    if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => 400, 'error' => 'File upload error or no file provided.'];
    }

    // 10 MB limit
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'code' => 400, 'error' => 'File exceeds maximum size limit of 10 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid file format. Allowed: PDF, DOCX, JPG, PNG.'];
    }

    $mimes = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $mimeType = $mimes[$ext] ?? 'application/octet-stream';

    $storageDir = secure_consents_storage_dir();
    $safeName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $safeName;

    $moved = is_uploaded_file($file['tmp_name']) ? @move_uploaded_file($file['tmp_name'], $targetPath) : @copy($file['tmp_name'], $targetPath);
    if (!$moved) {
        return ['ok' => false, 'code' => 500, 'error' => 'Failed to store template file on server.'];
    }

    $now = now();
    $actorId = (int)($actor['id'] ?? 0);
    q("INSERT INTO consent_templates (country, title, file_path, original_name, mime_type, file_size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
        $country ?: null,
        $title,
        $targetPath,
        $file['name'],
        $mimeType,
        (int)$file['size'],
        $actorId ?: null,
        $now,
    ]);
    $tplId = (int)db()->lastInsertId();

    log_activity('consent_template', $tplId, 'created', null, ['title' => $title, 'country' => $country], $actorId ?: null);

    return ['ok' => true, 'code' => 201, 'template_id' => $tplId];
}

/**
 * Upload a student's signed consent document (Staff or assigned counselor).
 */
function upload_consent_document(array $actor, int $studentId, ?int $templateId, array $file): array {
    if (!is_staff_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Access denied.'];
    }

    $st = q("SELECT id, name FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) {
        return ['ok' => false, 'code' => 404, 'error' => 'Student not found.'];
    }

    if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => 400, 'error' => 'File upload error or no file provided.'];
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'code' => 400, 'error' => 'File exceeds maximum size limit of 10 MB.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid file format. Allowed: PDF, DOCX, JPG, PNG.'];
    }

    $mimes = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $mimeType = $mimes[$ext] ?? 'application/octet-stream';

    $storageDir = secure_consents_storage_dir();
    $safeName = 'doc_' . $studentId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $safeName;

    $moved = is_uploaded_file($file['tmp_name']) ? @move_uploaded_file($file['tmp_name'], $targetPath) : @copy($file['tmp_name'], $targetPath);
    if (!$moved) {
        return ['ok' => false, 'code' => 500, 'error' => 'Failed to store consent document on server.'];
    }

    $now = now();
    $actorId = (int)($actor['id'] ?? 0);
    q("INSERT INTO consent_documents (student_id, template_id, file_path, original_name, mime_type, file_size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
        $studentId,
        $templateId ?: null,
        $targetPath,
        $file['name'],
        $mimeType,
        (int)$file['size'],
        $actorId ?: null,
        $now,
    ]);
    $docId = (int)db()->lastInsertId();

    log_activity('consent_document', $docId, 'uploaded', null, ['student_id' => $studentId, 'original_name' => $file['name']], $actorId ?: null);

    // Phase 6: Notify PMS Lead when consent is uploaded
    $st = q("SELECT id, name, assigned_pms_lead_id FROM students WHERE id = ?", [$studentId])->fetch();
    $leadId = (int)($st['assigned_pms_lead_id'] ?? 0);
    if ($leadId > 0 && $leadId !== $actorId) {
        create_notification(
            $leadId,
            'consent_uploaded',
            'Consent uploaded: ' . $st['name'],
            "{$actor['name']} uploaded a signed consent form for {$st['name']}.",
            $studentId
        );
    }

    return ['ok' => true, 'code' => 201, 'document_id' => $docId];
}

/**
 * Delete a consent template (Super Admin / Admin only).
 */
function delete_consent_template(array $actor, int $templateId): array {
    if (!is_admin_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only administrators can delete consent templates.'];
    }

    $tpl = q("SELECT * FROM consent_templates WHERE id = ?", [$templateId])->fetch();
    if (!$tpl) {
        return ['ok' => false, 'code' => 404, 'error' => 'Template not found.'];
    }

    if (!empty($tpl['file_path']) && is_file($tpl['file_path'])) {
        @unlink($tpl['file_path']);
    }

    q("DELETE FROM consent_templates WHERE id = ?", [$templateId]);
    log_activity('consent_template', $templateId, 'deleted', ['title' => $tpl['title']], null, (int)$actor['id']);

    return ['ok' => true, 'code' => 200];
}

/**
 * Replace a consent template's file (Super Admin / Admin only).
 */
function replace_consent_template(array $actor, int $templateId, array $file): array {
    if (!is_admin_role($actor['role'] ?? '')) {
        return ['ok' => false, 'code' => 403, 'error' => 'Only administrators can replace consent templates.'];
    }

    $tpl = q("SELECT * FROM consent_templates WHERE id = ?", [$templateId])->fetch();
    if (!$tpl) {
        return ['ok' => false, 'code' => 404, 'error' => 'Template not found.'];
    }

    if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => 400, 'error' => 'File upload error or no file provided.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid file format. Allowed: PDF, DOCX, JPG, PNG.'];
    }

    $mimes = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $mimeType = $mimes[$ext] ?? 'application/octet-stream';

    $storageDir = secure_consents_storage_dir() . '/templates';
    if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);

    $safeName = 'tpl_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $safeName;

    $moved = is_uploaded_file($file['tmp_name']) ? @move_uploaded_file($file['tmp_name'], $targetPath) : @copy($file['tmp_name'], $targetPath);
    if (!$moved) {
        return ['ok' => false, 'code' => 500, 'error' => 'Failed to store replacement template on server.'];
    }

    if (!empty($tpl['file_path']) && is_file($tpl['file_path'])) {
        @unlink($tpl['file_path']);
    }

    q("UPDATE consent_templates SET file_path = ?, original_name = ?, mime_type = ?, file_size = ?, uploaded_by = ?, created_at = ? WHERE id = ?", [
        $targetPath,
        $file['name'],
        $mimeType,
        (int)$file['size'],
        (int)$actor['id'],
        now(),
        $templateId,
    ]);

    log_activity('consent_template', $templateId, 'replaced', ['old_file' => $tpl['original_name']], ['new_file' => $file['name']], (int)$actor['id']);

    return ['ok' => true, 'code' => 200];
}

/**
 * Get applicable consent template for a student's country.
 * Matches country first; falls back to 'General' or NULL/empty country template.
 */
function get_applicable_consent_template(?string $country): ?array {
    $cleanCountry = trim((string)$country);

    if ($cleanCountry !== '') {
        $tpl = q("SELECT * FROM consent_templates WHERE LOWER(TRIM(country)) = LOWER(TRIM(?)) ORDER BY id DESC LIMIT 1", [$cleanCountry])->fetch();
        if ($tpl) return $tpl;
    }

    // Fall back to general template
    return q("SELECT * FROM consent_templates WHERE country IS NULL OR country = '' OR LOWER(TRIM(country)) = 'general' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
}

/**
 * Creates a student record with validation and PMS Lead notification.
 */
function create_student(array $actor, array $data, bool $suppressNotification = false): array {
    if (!can_manage_student_lifecycle($actor) && !in_array($actor['role'] ?? '', ['processing_staff', 'officer'], true)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied to create student records.'];
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'code' => 400, 'error' => 'Student name is required.'];
    }

    // Assign default PMS Lead if not specified
    if (empty($data['assigned_pms_lead_id'])) {
        $defaultPmsId = (int)setting('default_pms_lead_id', 0);
        if ($defaultPmsId > 0) {
            $data['assigned_pms_lead_id'] = $defaultPmsId;
        }
    }

    $now = now();
    $data['name'] = $name;
    $data['student_status'] = in_array($data['student_status'] ?? '', ['active', 'inactive', 'disabled'], true) ? $data['student_status'] : 'active';
    $data['is_legacy'] = 0;
    $data['created_at'] = $now;
    $data['updated_at'] = $now;
    $data['pending_since'] = $now;
    $data['last_edited_at'] = $now;
    $data['last_edited_by'] = (int)($actor['id'] ?? 0);

    $cols = array_keys($data);
    q('INSERT INTO students (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
    $id = (int)db()->lastInsertId();

    log_action('Added student', $id);
    log_activity('student', $id, 'created', null, ['name' => $name], (int)($actor['id'] ?? 0));

    // Notify assigned PMS Lead
    if (!$suppressNotification) {
        $leadId = (int)($data['assigned_pms_lead_id'] ?? 0);
        if ($leadId > 0 && $leadId !== (int)($actor['id'] ?? 0)) {
            create_notification(
                $leadId,
                'student_created',
                'New student created: ' . $name,
                "{$actor['name']} registered new student file for {$name}.",
                $id
            );
        }
    }

    return ['ok' => true, 'code' => 201, 'id' => $id, 'student_id' => $id];
}

/**
 * Bulk import multiple student records with a single summary notification to PMS Lead.
 */
function bulk_import_students(array $actor, array $studentsList): array {
    if (!can_manage_student_lifecycle($actor)) {
        return ['ok' => false, 'code' => 403, 'error' => 'Permission denied for bulk import.'];
    }

    $imported = 0;
    $pmsLeadsToNotify = [];

    foreach ($studentsList as $studentData) {
        if (!is_array($studentData) || empty($studentData['name'])) continue;
        $res = create_student($actor, $studentData, true); // Suppress individual notification
        if ($res['ok']) {
            $imported++;
            $leadId = (int)($studentData['assigned_pms_lead_id'] ?? 0);
            if ($leadId > 0) {
                $pmsLeadsToNotify[$leadId] = true;
            }
        }
    }

    if ($imported > 0) {
        $defaultPmsId = (int)setting('default_pms_lead_id', 0);
        if (empty($pmsLeadsToNotify) && $defaultPmsId > 0) {
            $pmsLeadsToNotify[$defaultPmsId] = true;
        }

        foreach (array_keys($pmsLeadsToNotify) as $leadId) {
            if ($leadId !== (int)($actor['id'] ?? 0)) {
                create_notification(
                    $leadId,
                    'student_import_summary',
                    "Bulk Import: {$imported} students imported",
                    "{$imported} student records were imported into the system by {$actor['name']}.",
                    null
                );
            }
        }
    }

    return ['ok' => true, 'code' => 201, 'imported_count' => $imported];
}

// =========================================================================
// 5. In-App Notifications Service
// =========================================================================

/**
 * Creates an in-app notification. Suppressed during migration runs.
 */
function create_notification(int $userId, string $type, string $title, string $message, ?int $studentId = null, ?string $createdAt = null): ?int {
    if (!empty($GLOBALS['MIGRATION_RUNNING'])) {
        return null;
    }

    try {
        $hasTable = q("SELECT 1 FROM sqlite_master WHERE type='table' AND name='notifications'")->fetchColumn();
        if (!$hasTable) return null;

        q("INSERT INTO notifications (user_id, type, title, message, student_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)", [
            $userId,
            $type,
            $title,
            $message,
            $studentId ?: null,
            $createdAt ?: now(),
        ]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('create_notification failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lists notifications for a given user.
 */
function list_notifications(int $userId, bool $unreadOnly = false, int $limit = 50): array {
    $where = "user_id = ?";
    $params = [$userId];
    if ($unreadOnly) {
        $where .= " AND is_read = 0";
    }
    return q("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit, $params)->fetchAll();
}

/**
 * Marks a specific notification as read.
 */
function mark_notification_read(int $userId, int $notificationId): bool {
    q("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$notificationId, $userId]);
    return true;
}

/**
 * Marks all notifications for a user as read.
 */
function mark_all_notifications_read(int $userId): bool {
    q("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$userId]);
    return true;
}

// =========================================================================
// 6. Pending Reminders & Hourly Escalation Cron Service (Phase 4)
// =========================================================================

/**
 * Checks for students pending action (> 48 hours for PMS Lead, > 72 hours for Admin escalation).
 * Deduplicated per pending cycle (reset when a remark clears pending).
 * Allows $fakeTime for automated testing of time shifts.
 */
function process_pending_reminders(?int $fakeTime = null): array {
    $nowTs = $fakeTime ?? time();
    $remHours = (int)setting('pending_reminder_hours', 48);
    $escHours = (int)setting('pending_escalation_hours', 72);

    $defaultPmsLeadId = (int)setting('default_pms_lead_id', 0);
    $adminUser = q("SELECT id FROM users WHERE role = 'admin' AND active = 1 LIMIT 1")->fetch();
    $escalationRecipientId = $adminUser ? (int)$adminUser['id'] : (int)q("SELECT id FROM users WHERE role = 'super_admin' AND active = 1 LIMIT 1")->fetchColumn();

    $pendingStudents = q("
        SELECT id, name, pending_since, assigned_pms_lead_id
        FROM students
        WHERE student_status = 'active'
          AND pending_since IS NOT NULL
    ")->fetchAll();

    $remindersSent = 0;
    $escalationsSent = 0;
    $eventTime = date('Y-m-d H:i:s', $nowTs);

    foreach ($pendingStudents as $s) {
        $sid = (int)$s['id'];
        $pendingSince = (string)$s['pending_since'];
        $pendingTs = strtotime($pendingSince);
        if ($pendingTs === false || $pendingTs > $nowTs) continue;

        $diffHours = ($nowTs - $pendingTs) / 3600.0;

        // 1. 48h Reminder to PMS Lead
        if ($diffHours >= $remHours) {
            $alreadyReminded = (bool)q("
                SELECT 1 FROM notifications 
                WHERE student_id = ? AND type = 'pending_reminder' AND created_at >= ?
                LIMIT 1
            ", [$sid, $pendingSince])->fetchColumn();

            if (!$alreadyReminded) {
                $pmsId = (int)($s['assigned_pms_lead_id'] ?: $defaultPmsLeadId);
                if ($pmsId > 0) {
                    create_notification(
                        $pmsId,
                        'pending_reminder',
                        "Pending Student Reminder: {$s['name']}",
                        "{$s['name']} has been pending action with no remark for over {$remHours} hours.",
                        $sid,
                        $eventTime
                    );
                    $remindersSent++;
                }
            }
        }

        // 2. 72h Escalation to Admin / Super Admin
        if ($diffHours >= $escHours) {
            $alreadyEscalated = (bool)q("
                SELECT 1 FROM notifications 
                WHERE student_id = ? AND type = 'pending_escalation' AND created_at >= ?
                LIMIT 1
            ", [$sid, $pendingSince])->fetchColumn();

            if (!$alreadyEscalated && $escalationRecipientId > 0) {
                create_notification(
                    $escalationRecipientId,
                    'pending_escalation',
                    "ESCALATION: Pending Student {$s['name']}",
                    "{$s['name']} has remained pending with no remark for over {$escHours} hours. Escalated to administration.",
                    $sid,
                    $eventTime
                );
                $escalationsSent++;
            }
        }
    }

    return [
        'ok'               => true,
        'pending_count'    => count($pendingStudents),
        'reminders_sent'   => $remindersSent,
        'escalations_sent' => $escalationsSent,
        'checked_at'       => date('Y-m-d H:i:s', $nowTs),
    ];
}
