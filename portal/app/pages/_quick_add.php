<?php
declare(strict_types=1);
// Quick registration modal ("Add Student"). Expects $u and $base. Posts to {base}/students.php (action=quick_add).

if (!can_quick_add_student($u)) return;

$qaOpts = staff_options();
$qaOld = $_SESSION['quick_add_old'] ?? [];
unset($_SESSION['quick_add_old']);
$qaIsLead = can_manage_student_lifecycle($u);
$qaDept = (string)($u['department'] ?? 'backend');
$qaOpen = get('add') === '1' || !empty($qaOld);

// Suggested back-end officer per country = the officer who already handles most of that country's students (from real data)
$qaSuggest = [];
foreach (q("SELECT country, officer_id, COUNT(*) c FROM students WHERE officer_id IS NOT NULL AND country IS NOT NULL AND country != '' GROUP BY country, officer_id ORDER BY c DESC")->fetchAll() as $row) {
    if (!isset($qaSuggest[$row['country']])) $qaSuggest[$row['country']] = (int)$row['officer_id'];
}
$qaV = fn(string $k, string $d = '') => h((string)($qaOld[$k] ?? $d));
$qaSel = fn(string $k, $val) => ((string)($qaOld[$k] ?? '') === (string)$val) ? 'selected' : '';
?>
<div id="quick-add-modal" class="qa-backdrop" <?= $qaOpen ? '' : 'hidden' ?> role="dialog" aria-modal="true" aria-labelledby="qa-title" data-suggest='<?= h(json_encode($qaSuggest)) ?>' data-lead="<?= $qaIsLead ? '1' : '0' ?>">
  <form class="qa-card" method="post" action="<?= h(url($base . '/students.php')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="quick_add">
    <div class="qa-head">
      <div>
        <div class="eyebrow">New registration</div>
        <h2 id="qa-title">Add Student</h2>
        <p class="muted small">Only the essentials now — documents, academics, applications, payments and visa are added later in the student's profile.</p>
      </div>
      <button type="button" class="icon-btn" data-qa-close aria-label="Close">✕</button>
    </div>

    <div class="qa-body">
      <div class="qa-section-title">Student</div>
      <div class="qa-grid">
        <?php [$qaFirst, $qaLast] = isset($qaOld['first_name']) || isset($qaOld['last_name']) ? [(string)($qaOld['first_name'] ?? ''), (string)($qaOld['last_name'] ?? '')] : student_name_parts(['name' => (string)($qaOld['name'] ?? '')]); ?>
        <label class="f"><span>First name *</span><input name="first_name" required minlength="2" maxlength="80" value="<?= h($qaFirst) ?>" placeholder="As on passport" autocomplete="off"></label>
        <label class="f"><span>Last name</span><input name="last_name" maxlength="80" value="<?= h($qaLast) ?>" placeholder="Surname / family name" autocomplete="off"></label>
        <p class="small muted qa-span2" style="margin:-4px 0 0">Full name is saved as first + last, exactly as on the passport. If two students share a name, adjust the first or last name to tell them apart.</p>
        <label class="f"><span>Contact / WhatsApp</span><input name="contact_number" inputmode="tel" maxlength="20" value="<?= $qaV('contact_number') ?>" placeholder="+92 3xx xxxxxxx"></label>
        <label class="f"><span>Email</span><input type="email" name="email" maxlength="150" value="<?= $qaV('email') ?>" placeholder="student@email.com"></label>
      </div>

      <div class="qa-section-title">Program</div>
      <div class="qa-grid">
        <label class="f"><span>Destination country *</span>
          <select name="country" required data-qa-country>
            <option value="">Choose…</option>
            <?php foreach (array_merge(portal_countries(), ['Other']) as $c): ?>
              <option <?= $qaSel('country', $c) ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="f"><span>Program level</span>
          <div class="seg">
            <?php foreach (['BS' => 'BS · Bachelors', 'MS' => 'MS · Masters'] as $lv => $lvl): ?>
              <label><input type="radio" name="program_level" value="<?= $lv ?>" <?= ($qaOld['program_level'] ?? 'MS') === $lv ? 'checked' : '' ?>><span><?= $lvl ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <label class="f"><span>Intake</span><input name="intake" list="qa-intakes" value="<?= $qaV('intake') ?>" placeholder="e.g. Fall <?= date('Y') + 1 ?>"></label>
        <datalist id="qa-intakes"><?php foreach (field_suggestions('intake') as $o): ?><option value="<?= h($o) ?>"></option><?php endforeach; ?></datalist>
        <label class="f"><span>Applying for</span><input name="applied_for" list="qa-applied" value="<?= $qaV('applied_for') ?>" placeholder="Masters / Bachelors / Erasmus"></label>
        <datalist id="qa-applied"><?php foreach (field_suggestions('applied_for') as $o): ?><option value="<?= h($o) ?>"></option><?php endforeach; ?></datalist>
        <label class="f"><span>Branch</span>
          <select name="branch"><option value="">Not set</option><?php foreach (BRANCHES as $b): ?><option <?= $qaSel('branch', $b) ?>><?= h($b) ?></option><?php endforeach; ?></select>
        </label>
        <label class="f"><span>Registration date</span><input type="date" name="received_date" value="<?= $qaV('received_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d', strtotime('+1 day')) ?>"></label>
      </div>

      <div class="qa-section-title">Assignment</div>
      <div class="qa-grid">
        <label class="f qa-span2"><span>Counselor * <small class="muted">(from counselor records)</small></span>
          <select name="assigned_counselor_id" required data-searchable="Search counselor…">
            <option value="">Choose counselor…</option>
            <?php foreach ($qaOpts['counselor'] as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $qaSel('assigned_counselor_id', $c['id']) ?>><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="f"><span>Front-End Officer</span>
          <?php if (!$qaIsLead && $qaDept === 'frontend'): ?>
            <input value="<?= h($u['name']) ?> (you)" disabled><span class="small muted">Auto-assigned to your login</span>
          <?php else: ?>
            <select name="frontend_officer_id" data-searchable="Search officer…">
              <option value="">Not assigned yet</option>
              <?php foreach ($qaOpts['frontend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $qaSel('frontend_officer_id', $o['id']) ?>><?= h($o['name']) ?></option><?php endforeach; ?>
            </select>
          <?php endif; ?>
        </label>
        <label class="f"><span>Back-End Officer</span>
          <?php if (!$qaIsLead && $qaDept !== 'frontend'): ?>
            <input value="<?= h($u['name']) ?> (you)" disabled><span class="small muted">Auto-assigned to your login</span>
          <?php else: ?>
            <select name="officer_id" data-searchable="Search officer…" data-qa-backend>
              <option value="">Not assigned yet</option>
              <?php foreach ($qaOpts['backend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $qaSel('officer_id', $o['id']) ?>><?= h($o['name']) ?><?= $o['role'] === 'pms_lead' ? ' (PMS Lead)' : '' ?></option><?php endforeach; ?>
            </select>
            <span class="small muted" data-qa-hint></span>
          <?php endif; ?>
        </label>
      </div>

      <label class="f"><span>First note <small class="muted">(optional — saved as the first remark)</small></span><textarea name="first_note" rows="2" maxlength="2000" placeholder="e.g. Registered at Lahore branch, documents to be shared on WhatsApp"><?= $qaV('first_note') ?></textarea></label>
      <?php if (!empty($qaOld['_duplicate_id'])): ?>
        <label class="check small"><input type="checkbox" name="allow_duplicate" value="1"> Yes, this is a different person — create anyway</label>
      <?php endif; ?>
    </div>

    <div class="qa-foot">
      <a class="btn ghost sm" href="<?= h(url($base . '/student.php?new=1')) ?>">Open full sheet form instead</a>
      <span style="flex:1"></span>
      <button type="button" class="btn" data-qa-close>Cancel</button>
      <button type="submit" class="btn primary">Save &amp; open profile →</button>
    </div>
  </form>
</div>
