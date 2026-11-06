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
$wsFlags = ['Italy' => '🇮🇹', 'France' => '🇫🇷', 'Germany' => '🇩🇪', 'Czech Republic' => '🇨🇿', 'Austria' => '🇦🇹', 'China' => '🇨🇳'];
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
