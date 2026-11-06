<?php
declare(strict_types=1);
// Order-details style workspace shown at the top of a student's profile.
// Expects: $u, $r, $id, $base, $canManage, $studentApps, $docRows, $studentRemarksList

$wsNames = staff_name_map();
$wsJourney = student_journey($r, $studentApps);
$wsStatus = processing_status($r, $wsJourney);
$wsDone = journey_done_count($wsJourney);
$wsCurrent = journey_current($wsJourney);
$wsCanStage = can_update_journey($u);
$wsCanAssign = can_change_assignments($u);
$wsOpts = staff_options();
$wsHistory = assignment_history($id);
$wsStageHistory = q('SELECT h.*, u.name AS by_name FROM student_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.student_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 30', [$id])->fetchAll();
$wsFe = (int)($r['frontend_officer_id'] ?? 0);
$wsBe = (int)($r['officer_id'] ?? 0);
$wsPms = (int)($r['assigned_pms_lead_id'] ?? 0);
$wsCn = (int)($r['assigned_counselor_id'] ?? 0) ?: (counselor_id_by_name($r['counselor'] ?? '') ?? 0);
$wsFlags = country_flags_map();
$wsStaffForStage = array_merge($wsOpts['frontend'], $wsOpts['backend']);
usort($wsStaffForStage, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$wsStaffForStage = array_values(array_column($wsStaffForStage, null, 'id'));
$wsFieldLabel = ASSIGNMENT_FIELDS;
$wsCountry = trim((string)($r['country'] ?? ''));
$wsPendingHours = !empty($r['pending_since']) ? (int)floor((time() - (strtotime((string)$r['pending_since']) ?: time())) / 3600) : null;
?>
<nav class="section-nav no-print" aria-label="Profile sections">
  <a href="#overview">Overview</a>
  <a href="#journey">Processing Journey</a>
  <a href="#payments">Payments</a>
  <a href="#meeting">Meeting</a>
  <a href="#assignments">Assignments</a>
  <a href="#sheet-details">Sheet Details</a>
  <a href="#documents">Documents</a>
  <a href="#applications">Applications</a>
  <a href="#consent">Consent</a>
  <a href="#communication">Communication</a>
  <a href="#remarks">Remarks</a>
  <a href="#audit-log">History</a>
</nav>

<section class="ws-grid" id="overview">
  <!-- Order summary -->
  <div class="ws-card ws-summary">
    <div class="ws-card-h">
      <span class="order-id">STUDENT #<?= (int)$r['id'] ?><?= !empty($r['sheet_sr']) ? ' · SR ' . (int)$r['sheet_sr'] : '' ?></span>
      <?= processing_status_badge($wsStatus) ?>
    </div>
    <h2 class="ws-name"><?= h($r['name']) ?></h2>
    <div class="order-tags">
      <?php if ($wsCountry !== ''): ?><span class="pill sm"><?= $wsFlags[$wsCountry] ?? '📍' ?> <?= h($wsCountry) ?></span><?php endif; ?>
      <?php if (!empty($r['program_level'])): ?><span class="pill sm acc"><?= h($r['program_level']) ?></span><?php endif; ?>
      <?php if (!empty($r['intake'])): ?><span class="pill sm"><?= h($r['intake']) ?></span><?php endif; ?>
      <?php if (!empty($r['branch'])): ?><span class="pill sm">📍 <?= h($r['branch']) ?></span><?php endif; ?>
    </div>
    <dl class="ws-dl">
      <div><dt>Registered</dt><dd><?= h(fmt_date($r['received_date'] ?? '') ?: fmt_date(substr((string)($r['created_at'] ?? ''), 0, 10)) ?: '—') ?></dd></div>
      <div><dt>Contact</dt><dd><?php if (!empty($r['contact_number'])): $wa = preg_replace('/\D+/', '', (string)$r['contact_number']); if (str_starts_with($wa, '0')) $wa = '92' . substr($wa, 1); ?><a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['contact_number']) ?></a><?php else: ?>—<?php endif; ?></dd></div>
      <div><dt>Email</dt><dd><?= !empty($r['email']) ? '<a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : '—' ?></dd></div>
      <div><dt>Applying for</dt><dd><?= h($r['applied_for'] ?: '—') ?></dd></div>
    </dl>
    <?php if ($canManage): ?>
      <form method="post" class="ws-inline-form no-print">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_pstatus"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <label class="small muted" for="ps-sel">Processing status</label>
        <select id="ps-sel" name="processing_status" data-autosubmit>
          <option value="">Automatic (<?= h(processing_status(array_merge($r, ['processing_status' => null]), $wsJourney)) ?>)</option>
          <?php foreach (PROCESSING_STATUSES as $ps): ?><option <?= ($r['processing_status'] ?? '') === $ps ? 'selected' : '' ?>><?= h($ps) ?></option><?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <!-- Progress summary -->
  <div class="ws-card ws-progress">
    <div class="ws-card-h"><span class="eyebrow">Progress</span><a class="small" href="#journey">Open journey ↓</a></div>
    <div class="ring" style="--p: <?= round($wsDone / 8 * 100) ?>">
      <div><b><?= $wsDone ?>/8</b><span>steps done</span></div>
    </div>
    <div class="ws-now">
      <span class="muted small">Current step</span>
      <b><?= h($wsCurrent['label'] ?? 'All steps complete') ?></b>
      <?php if ($wsCurrent): ?><span class="pill sm <?= stage_status_class($wsCurrent['status']) ?>"><?= h($wsCurrent['status']) ?></span><?php endif; ?>
    </div>
    <?php if ($wsPendingHours !== null): ?>
      <div class="ws-alert <?= $wsPendingHours >= 48 ? 'bad' : 'warn' ?>">⏳ Waiting for a staff remark — <?= $wsPendingHours < 1 ? "since less than an hour" : $wsPendingHours . "h" ?></div>
    <?php endif; ?>
    <?php if (!empty($studentRemarksList)): $lr = $studentRemarksList[0]; ?>
      <div class="ws-last">
        <span class="muted small">Last remark · <?= h($lr['author_name']) ?> · <?= h(time_ago((string)$lr['created_at'])) ?></span>
        <div><?= h(mb_strimwidth((string)$lr['remark_text'], 0, 140, '…')) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Assignments -->
  <div class="ws-card" id="assignments">
    <div class="ws-card-h"><span class="eyebrow">Assignments</span><?php if ($wsHistory): ?><a class="small" href="#assignment-history">History (<?= count($wsHistory) ?>)</a><?php endif; ?></div>
    <ul class="assign-list">
      <li><span class="tag-fe">FE</span><span class="muted">Front-End Officer</span><b><?= h($wsNames[$wsFe] ?? ($r['frontend_officer_name'] ?: 'Not assigned')) ?></b></li>
      <li><span class="tag-be">BE</span><span class="muted">Back-End Officer</span><b><?= h($wsNames[$wsBe] ?? 'Not assigned') ?></b></li>
      <li><span class="tag-cn">CN</span><span class="muted">Counselor</span><b><?= h($r['counselor'] ?: ($wsNames[$wsCn] ?? 'Not assigned')) ?></b></li>
      <li><span class="tag-pm">PM</span><span class="muted">PMS Lead</span><b><?= h($wsNames[$wsPms] ?? 'Not assigned') ?></b></li>
    </ul>
    <?php if ($wsCanAssign): ?>
      <details class="ws-edit no-print">
        <summary class="btn sm">Change assignments</summary>
        <form method="post" class="stack" style="margin-top:10px;">
          <?= csrf_field() ?><input type="hidden" name="action" value="change_assignment"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <label class="f"><span>Front-End Officer</span><select name="frontend_officer_id" data-searchable="Search…"><option value="0">Not assigned</option><?php foreach ($wsOpts['frontend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $wsFe ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
          <label class="f"><span>Back-End Officer</span><select name="officer_id" data-searchable="Search…"><option value="0">Not assigned</option><?php foreach ($wsOpts['backend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $wsBe ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
          <label class="f"><span>Counselor</span><select name="assigned_counselor_id" data-searchable="Search counselor…"><option value="0">Not assigned</option><?php foreach ($wsOpts['counselor'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $wsCn ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
          <label class="f"><span>PMS Lead</span><select name="assigned_pms_lead_id"><option value="0">Not assigned</option><?php foreach ($wsOpts['pms_lead'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $wsPms ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
          <label class="f"><span>Reason (kept in history)</span><input name="assignment_note" maxlength="200" placeholder="e.g. Workload balancing, officer on leave"></label>
          <div><button class="btn primary sm" type="submit">Save assignments</button></div>
        </form>
      </details>
    <?php endif; ?>
  </div>
</section>

<!-- Processing journey -->
<section class="panel" id="journey">
  <div class="panel-h">
    <div>
      <h2>Processing Journey</h2>
      <p>Each step can be updated on its own. Steps marked <span class="pill sm">auto</span> are read from the sheet fields until someone updates them here.</p>
    </div>
    <span class="pill acc"><?= $wsDone ?> of 8 complete</span>
  </div>
  <ol class="stepper">
    <?php $n = 0; foreach ($wsJourney as $st): $n++; ?>
      <li class="<?= stage_status_class($st['status']) ?> <?= $wsCurrent && $wsCurrent['key'] === $st['key'] ? 'is-current' : '' ?>">
        <a href="#stage-<?= h($st['key']) ?>">
          <span class="node"><?= $st['status'] === 'Completed' ? '✓' : ($st['status'] === 'Rejected' ? '✕' : $n) ?></span>
          <span class="lbl"><?= h($st['label']) ?></span>
          <span class="st"><?= h($st['status']) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ol>

  <div class="stage-list">
    <?php $n = 0; foreach ($wsJourney as $st): $n++; ?>
      <article class="stage-card <?= stage_status_class($st['status']) ?>" id="stage-<?= h($st['key']) ?>">
        <div class="stage-main">
          <span class="stage-no"><?= $n ?></span>
          <div class="stage-text">
            <div class="stage-title"><b><?= h($st['label']) ?></b> <span class="pill sm <?= stage_status_class($st['status']) ?>"><?= h($st['status']) ?></span><?php if ($st['is_auto']): ?> <span class="pill sm" title="Worked out from the sheet fields">auto</span><?php endif; ?></div>
            <div class="small muted"><?= h($st['hint']) ?></div>
            <div class="stage-meta small">
              <span>📅 <?= h($st['date'] ? fmt_date($st['date']) : 'No date') ?></span>
              <span>👤 <?= h($st['assigned_name'] ?: 'Unassigned') ?></span>
              <?php if (!$st['is_auto'] && $st['updated_at']): ?><span class="muted">Updated <?= h(time_ago((string)$st['updated_at'])) ?><?= $st['updated_by_name'] ? ' by ' . h($st['updated_by_name']) : '' ?></span><?php endif; ?>
            </div>
            <?php if ($st['key'] === 'payment'): ?><div class="chip-row"><?php foreach (student_payments($r) as $pp): ?><a class="doc-chip <?= $pp['status'] === 'Received' ? 'is-ok' : '' ?>" href="#payments"><?= ordinal_label((int)$pp['installment_no']) ?> · <?= $pp['status'] === 'Received' ? '✓ received' : h(strtolower($pp['status'])) ?></a><?php endforeach; ?></div><?php endif; ?>
            <?php if (!empty($st['notes'])): ?><div class="stage-notes"><?= nl2br(h($st['notes'])) ?></div><?php endif; ?>
          </div>
        </div>
        <?php if ($wsCanStage): ?>
          <details class="stage-edit no-print">
            <summary class="btn sm">Update</summary>
            <form method="post" class="stage-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="update_stage"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="stage_key" value="<?= h($st['key']) ?>">
              <label class="f"><span>Status</span><select name="stage_status"><?php foreach (STAGE_STATUSES as $ss): ?><option <?= $ss === $st['status'] ? 'selected' : '' ?>><?= h($ss) ?></option><?php endforeach; ?></select></label>
              <label class="f"><span>Date</span><input type="date" name="stage_date" value="<?= h($st['date'] && valid_date(substr((string)$st['date'], 0, 10)) ? substr((string)$st['date'], 0, 10) : date('Y-m-d')) ?>"></label>
              <label class="f"><span>Assigned to</span><select name="stage_assigned_to"><option value="">Unassigned</option><?php foreach ($wsStaffForStage as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$st['assigned_to'] ? 'selected' : '' ?>><?= h($o['name']) ?> (<?= $o['department'] === 'frontend' ? 'FE' : 'BE' ?>)</option><?php endforeach; ?></select></label>
              <label class="f wide"><span>Details / notes</span><textarea name="stage_notes" rows="2" maxlength="2000" placeholder="What was done, what is waiting, reference numbers…"><?= h($st['notes'] ?? '') ?></textarea></label>
              <label class="check small wide"><input type="checkbox" name="stage_post_remark" value="1" checked> Also post this update as a remark (clears the 48h pending timer)</label>
              <div><button class="btn primary sm" type="submit">Save step</button></div>
            </form>
          </details>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($wsStageHistory): ?>
    <details class="ws-history">
      <summary>Journey change history (<?= count($wsStageHistory) ?>)</summary>
      <ul class="timeline">
        <?php foreach ($wsStageHistory as $hst): ?>
          <li><span class="muted small"><?= h(fmt_date(substr((string)$hst['changed_at'], 0, 10))) ?> · <?= h(substr((string)$hst['changed_at'], 11, 5)) ?></span>
            <b><?= h(JOURNEY_STAGES[$hst['stage_key']][0] ?? $hst['stage_key']) ?></b>: <?= h($hst['old_status'] ?: '—') ?> → <b><?= h($hst['new_status']) ?></b>
            <span class="muted">by <?= h($hst['by_name'] ?: 'System') ?></span><?= $hst['notes'] ? '<div class="small">' . h(mb_strimwidth((string)$hst['notes'], 0, 160, '…')) . '</div>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
  <?php if ($wsHistory): ?>
    <details class="ws-history" id="assignment-history">
      <summary>Assignment history (<?= count($wsHistory) ?>)</summary>
      <ul class="timeline">
        <?php foreach ($wsHistory as $hh): ?>
          <li><span class="muted small"><?= h(fmt_date(substr((string)$hh['changed_at'], 0, 10))) ?></span>
            <b><?= h($wsFieldLabel[$hh['field']] ?? $hh['field']) ?></b>: <?= h($hh['old_name'] ?: '—') ?> → <b><?= h($hh['new_name'] ?: 'Not assigned') ?></b>
            <span class="muted">by <?= h($hh['changed_by_name'] ?: 'System') ?><?= $hh['note'] ? ' · ' . h($hh['note']) : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
</section>

<?php
// ---------- Payments (1st / 2nd / 3rd …) ----------
$wsPays = student_payments($r);
$wsPaySum = payment_summary($wsPays);
$wsNextPay = count($wsPays) + 1;
// ---------- Meeting recording ----------
$wsRec = meeting_recording_state($r);
$wsVideo = trim((string)($r['meeting_video_path'] ?? ''));
$wsRecLink = trim((string)($r['meeting_link'] ?? ''));
$wsRecLink = safe_link($wsRecLink) && $wsRecLink !== trim((string)($r['drive_link'] ?? '')) ? $wsRecLink : '';
$wsRecMode = $wsVideo !== '' ? 'video' : ($wsRec['state'] === 'not_recorded' ? 'none' : 'link');
$wsPayTone = ['Received' => 'ok', 'Partially received' => 'warn', 'Waived' => 'acc', 'Pending' => 'bad'];
?>
<section class="ws-grid ws-grid-2" id="payments-meeting">
  <div class="ws-card" id="payments">
    <div class="ws-card-h"><span class="eyebrow">Payments</span>
      <span class="small"><b><?= $wsPaySum['received'] ?></b> of <?= $wsPaySum['total'] ?> received<?= $wsPaySum['amount_received'] > 0 ? ' · ' . h(number_format($wsPaySum['amount_received'])) : '' ?></span></div>
    <div class="pay-steps">
      <?php foreach ($wsPays as $p): $n = (int)$p['installment_no']; $tone = $wsPayTone[$p['status']] ?? 'bad'; ?>
        <div class="pay-row tone-<?= $tone ?>">
          <div class="pay-top">
            <span class="pay-no"><?= ordinal_label($n) ?></span>
            <div class="pay-text">
              <b><?= ordinal_label($n) ?> payment</b>
              <span class="pill sm <?= $tone ?>"><?= $p['status'] === 'Received' ? '✓ Received' : h($p['status']) ?></span>
              <div class="small muted">
                <?= $p['amount'] ? 'Amount ' . h(is_numeric(str_replace(',', '', (string)$p['amount'])) ? number_format((float)str_replace(',', '', (string)$p['amount'])) : $p['amount']) : 'No amount' ?>
                <?= $p['received_date'] ? ' · ' . h(fmt_date($p['received_date'])) : '' ?>
                <?= $p['method'] ? ' · ' . h($p['method']) : '' ?>
                <?= $p['is_auto'] ? ' · <span title="Read from the sheet columns">from sheet</span>' : ($p['updated_by_name'] ? ' · by ' . h($p['updated_by_name']) : '') ?>
              </div>
              <?php if (!empty($p['note'])): ?><div class="small"><?= h($p['note']) ?></div><?php endif; ?>
            </div>
            <?php if ($wsCanStage): ?><div class="pay-actions no-print">
              <button type="button" class="btn sm" data-toggle-next>Update</button>
              <?php if ($n > 3): ?><form method="post" onsubmit="return confirm('Remove the <?= ordinal_label($n) ?> payment?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="installment_no" value="<?= $n ?>"><button class="btn sm danger-ghost" type="submit" title="Remove this payment (only 4th and later can be removed)">✕ Remove</button></form><?php endif; ?>
            </div><?php endif; ?>
          </div>
          <?php if ($wsCanStage): ?>
          <form method="post" class="pay-form no-print" hidden>
            <?= csrf_field() ?><input type="hidden" name="action" value="save_payment"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="installment_no" value="<?= $n ?>">
            <div class="seg seg-sm" role="radiogroup" aria-label="Status">
              <?php foreach (['Pending', 'Received', 'Partially received', 'Waived'] as $ps): ?>
                <label><input type="radio" name="pay_status" value="<?= h($ps) ?>" <?= $p['status'] === $ps ? 'checked' : '' ?>><span><?= h($ps === 'Partially received' ? 'Partial' : $ps) ?></span></label>
              <?php endforeach; ?>
            </div>
            <div class="pay-fields">
              <label class="f"><span>Amount</span><input name="pay_amount" inputmode="decimal" value="<?= h($p['amount'] ?? '') ?>" placeholder="e.g. 100000"></label>
              <label class="f"><span>Date received</span><input type="date" name="pay_date" value="<?= h($p['received_date'] ?? '') ?>"></label>
              <label class="f"><span>Method</span><select name="pay_method"><option value="">—</option><?php foreach (PAYMENT_METHODS as $pm): ?><option <?= ($p['method'] ?? '') === $pm ? 'selected' : '' ?>><?= h($pm) ?></option><?php endforeach; ?></select></label>
              <label class="f wide"><span>Note</span><input name="pay_note" maxlength="500" value="<?= h($p['note'] ?? '') ?>" placeholder="Receipt no., who paid, anything to remember"></label>
            </div>
            <div><button class="btn primary sm" type="submit">Save <?= ordinal_label($n) ?> payment</button></div>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($wsCanStage && $wsNextPay <= 12): ?>
      <form method="post" class="no-print" style="margin-top:8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_payment"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="installment_no" value="<?= $wsNextPay ?>"><input type="hidden" name="pay_status" value="Pending">
        <button class="btn sm" type="submit">+ Add <?= ordinal_label($wsNextPay) ?> payment</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="ws-card" id="meeting">
    <div class="ws-card-h"><span class="eyebrow">Meeting recording</span>
      <?php if ($wsRec['state'] === 'recorded'): ?><span class="pill sm ok">✓ Recorded</span>
      <?php elseif ($wsRec['state'] === 'not_recorded'): ?><span class="pill sm warn">Not recorded</span>
      <?php else: ?><span class="pill sm bad">Pending</span><?php endif; ?>
    </div>
    <div class="small muted" style="margin-bottom:8px">1st meeting held: <b><?= ($r['initial_meeting'] ?? '') === 'Yes' ? 'Yes' : 'Not yet' ?></b><?= !empty($r['follow_up_1']) ? ' · ' . h(fmt_date((string)$r['follow_up_1'])) : '' ?></div>

    <?php if ($wsVideo !== ''): ?>
      <video class="meet-video" controls preload="metadata" src="<?= h(url('meeting_video.php?id=' . (int)$id)) ?>"></video>
      <div class="small muted">🎬 <?= h($r['meeting_video_name'] ?: 'Recording') ?> · <a href="<?= h(url('meeting_video.php?id=' . (int)$id . '&download=1')) ?>">Download</a></div>
    <?php elseif ($wsRec['state'] === 'recorded' && $wsRec['url']): ?>
      <a class="btn primary" href="<?= h($wsRec['url']) ?>" target="_blank" rel="noopener noreferrer">▶ Play recording</a>
      <div class="small muted" style="margin-top:4px;word-break:break-all"><?= h($wsRec['url']) ?></div>
    <?php elseif ($wsRec['state'] === 'not_recorded'): ?>
      <div class="meet-reason"><b>Why not recorded:</b> <?= h($wsRec['reason']) ?></div>
    <?php elseif ($wsRec['state'] === 'recorded'): ?>
      <div class="small">Marked as recorded on the sheet, but no link or video is saved yet.</div>
    <?php else: ?>
      <div class="small muted">No recording yet. Add a link, upload the video, or note why it was not recorded.</div>
    <?php endif; ?>

    <?php if ($wsCanStage): ?>
    <div class="meet-edit no-print" data-meet>
      <div class="seg seg-sm" role="radiogroup" aria-label="Recording option" style="margin-top:12px">
        <label><input type="radio" name="meet_mode" value="link" <?= $wsRecMode === 'link' ? 'checked' : '' ?>><span>🔗 Link</span></label>
        <label><input type="radio" name="meet_mode" value="video" <?= $wsRecMode === 'video' ? 'checked' : '' ?>><span>🎬 Upload video</span></label>
        <label><input type="radio" name="meet_mode" value="none" <?= $wsRecMode === 'none' ? 'checked' : '' ?>><span>✖ Not recorded</span></label>
      </div>
      <form method="post" class="meet-pane" data-pane="link">
        <?= csrf_field() ?><input type="hidden" name="action" value="meeting_save_link"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <label class="f"><span>Recording link (Zoom, Google Drive file, YouTube…)</span><input type="url" name="meeting_link" value="<?= h($wsRecLink) ?>" placeholder="https://…" required></label>
        <label class="check small"><input type="checkbox" name="meeting_held" value="1" <?= ($r['initial_meeting'] ?? '') === 'Yes' ? 'checked' : '' ?>> 1st meeting was held</label>
        <button class="btn primary sm" type="submit">Save link</button>
      </form>
      <form method="post" class="meet-pane" data-pane="video" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="meeting_upload_video"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <label class="dropzone"><input type="file" name="meeting_video" accept="video/*,.mp4,.mov,.webm,.mkv,.m4a,.mp3" required><span data-dz-label>⬆ Drop the recording or click to choose (max <?= (int)min(512, max(1, (int)(meeting_upload_limit() / 1048576))) ?> MB)</span></label>
        <label class="check small"><input type="checkbox" name="meeting_held" value="1" checked> 1st meeting was held</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn primary sm" type="submit">Upload video</button>
        <?php if ($wsVideo !== ''): ?><button class="btn sm" type="submit" name="remove_video" value="1" formnovalidate onclick="return confirm('Remove the uploaded recording?')">Remove current video</button><?php endif; ?></div>
      </form>
      <form method="post" class="meet-pane" data-pane="none">
        <?= csrf_field() ?><input type="hidden" name="action" value="meeting_no_recording"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <label class="f"><span>Why was it not recorded?</span><input name="meeting_note" maxlength="500" value="<?= h($wsRec['state'] === 'not_recorded' ? $wsRec['reason'] : '') ?>" placeholder="e.g. Student met in office, internet issue, student refused" list="meet-reasons" required></label>
        <datalist id="meet-reasons"><option>Meeting held in office (not recorded)</option><option>Internet / Zoom issue</option><option>Student did not allow recording</option><option>Meeting not held yet</option><option>Recording lost / not saved</option></datalist>
        <label class="check small"><input type="checkbox" name="meeting_held" value="1" <?= ($r['initial_meeting'] ?? '') === 'Yes' ? 'checked' : '' ?>> 1st meeting was held</label>
        <button class="btn primary sm" type="submit">Save note</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</section>
