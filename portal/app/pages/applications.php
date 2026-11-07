<?php
declare(strict_types=1);
// University applications: for each student, what was shortlisted, what is already submitted and what is
// still to submit — with "Mark submitted" right here (same student_applications rows as the profile).
// Expects $u.

$admin = is_admin_role($u['role']);
$base = $admin ? 'admin' : 'officer';
$self = $base . '/applications.php';
[$w, $p] = scope_sql($u, 's');

if (is_post()) {
    csrf_check();
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['app'] ?? []))));
    $act = post('action');
    $done = 0; $denied = 0;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('applied_date')) ? (string)post('applied_date') : date('Y-m-d');
    foreach ($ids as $aid) {
        $a = q("SELECT a.*, s.officer_id, s.frontend_officer_id, s.id AS sid FROM student_applications a JOIN students s ON s.id = a.student_id WHERE a.id = ? AND $w", array_merge([$aid], $p))->fetch();
        if (!$a) continue;
        if (($u['role'] ?? '') === 'counselor' || !can_edit_student($u, $a)) { $denied++; continue; }
        if ($act === 'submit' && app_is_remaining($a['status'])) {
            q('UPDATE student_applications SET status = ?, applied_date = COALESCE(NULLIF(applied_date, \'\'), ?), submitted_by = ?, updated_at = ? WHERE id = ?', ['Applied', $date, (int)$u['id'], now(), $aid]);
            log_activity('student', (int)$a['sid'], 'application_submitted', ['status' => $a['status']], ['app_id' => $aid, 'university' => $a['university'], 'status' => 'Applied'], (int)$u['id']);
            $done++;
        } elseif ($act === 'undo' && app_is_submitted($a['status'])) {
            q('UPDATE student_applications SET status = ?, updated_at = ? WHERE id = ?', ['Shortlisted', now(), $aid]);
            log_activity('student', (int)$a['sid'], 'application_unsubmitted', ['status' => $a['status']], ['app_id' => $aid, 'status' => 'Shortlisted'], (int)$u['id']);
            $done++;
        }
    }
    if ($done) flash($act === 'submit' ? "$done application" . ($done === 1 ? '' : 's') . ' marked submitted.' : "$done application" . ($done === 1 ? '' : 's') . ' moved back to shortlisted.');
    elseif ($denied) flash(view_only_message('students.edit_all'), 'bad');
    else flash('Nothing was selected.', 'bad');
    $back = (string)post('back');
    redirect($self . (preg_match('~^\?[\w=&%.+-]*$~', $back) ? $back : '') . (post('sid') ? '#st' . (int)post('sid') : ''));
}

// ---------- data ----------
$view = in_array(get('view'), ['remaining', 'submitted', 'all'], true) ? get('view') : 'remaining';
$fCountry = trim((string)get('country'));
$fOfficer = (int)get('officer');
$fq = strtolower(trim((string)get('q')));
$students = q("SELECT s.id, s.name, s.country, s.program_level, s.intake, s.officer_id, s.frontend_officer_id, s.sheet_sr,
                      o.name AS be_name, COALESCE(fo.name, s.frontend_officer_name) AS fe_name
               FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users fo ON fo.id = s.frontend_officer_id
               WHERE $w AND s.student_status = 'active'", $p)->fetchAll();
$byId = array_column($students, null, 'id');
$apps = [];
foreach (q('SELECT * FROM student_applications ORDER BY COALESCE(NULLIF(deadline, \'\'), \'9999\'), university COLLATE NOCASE') as $a) {
    if (isset($byId[(int)$a['student_id']])) $apps[(int)$a['student_id']][] = $a;
}
$tot = ['sent' => 0, 'left' => 0, 'stu_sent' => 0, 'stu_left' => 0, 'stu_none' => 0];
$cards = [];
foreach ($byId as $sid => $s) {
    $list = $apps[$sid] ?? [];
    $sent = array_values(array_filter($list, fn($a) => app_is_submitted($a['status'])));
    $left = array_values(array_filter($list, fn($a) => app_is_remaining($a['status'])));
    $tot['sent'] += count($sent); $tot['left'] += count($left);
    if ($sent) $tot['stu_sent']++;
    if ($left) $tot['stu_left']++;
    if (!$list) $tot['stu_none']++;
    if ($fCountry !== '' && strcasecmp((string)$s['country'], $fCountry) !== 0) continue;
    if ($fOfficer && (int)$s['officer_id'] !== $fOfficer && (int)$s['frontend_officer_id'] !== $fOfficer) continue;
    if ($fq !== '' && !str_contains(strtolower($s['name'] . ' ' . implode(' ', array_column($list, 'university'))), $fq)) continue;
    if ($view === 'remaining' && !$left) continue;
    if ($view === 'submitted' && !$sent) continue;
    if ($view === 'all' && !$list) continue;
    $cards[] = ['s' => $s, 'sent' => $sent, 'left' => $left, 'other' => array_values(array_filter($list, fn($a) => !app_is_submitted($a['status']) && !app_is_remaining($a['status'])))];
}
usort($cards, fn($a, $b) => [count($b['left']), count($b['sent'])] <=> [count($a['left']), count($a['sent'])]);
$officers = staff_options();
$countries = array_values(array_unique(array_filter(array_map(fn($s) => trim((string)$s['country']), $students))));
sort($countries);
$qsv = fn(array $o = []) => '?' . http_build_query(array_filter(array_merge(['view' => $view, 'country' => $fCountry, 'officer' => $fOfficer ?: null, 'q' => get('q')], $o), fn($v) => $v !== null && $v !== ''));
$backQs = $qsv();
$MAX = 150; $n = 0;
$appTone = function (string $st): string {
    $l = strtolower($st);
    if (str_contains($l, 'reject')) return 'bad';
    if (str_contains($l, 'offer') || str_contains($l, 'accept') || str_contains($l, 'enrol') || str_contains($l, 'admit')) return 'ok';
    return app_is_submitted($st) ? 'acc' : 'warn';
};

page_start('Applications', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h"><div><div class="eyebrow">University applications</div><h1>Shortlisted → submitted</h1>
    <p>For every student: the universities shortlisted, which applications are already submitted, and which are still to submit. Tick and mark them submitted here — it updates the student’s profile too.</p></div></div>
  <div class="kpis">
    <a class="kpi kg kg-teal" href="<?= h($qsv(['view' => 'submitted'])) ?>"><span class="kg-ic">✅</span><div class="l">Submitted</div><div class="v"><?= $tot['sent'] ?></div><div class="s">applications · <?= $tot['stu_sent'] ?> students</div></a>
    <a class="kpi kg kg-amber" href="<?= h($qsv(['view' => 'remaining'])) ?>"><span class="kg-ic">📝</span><div class="l">Still to submit</div><div class="v"><?= $tot['left'] ?></div><div class="s">shortlisted · <?= $tot['stu_left'] ?> students</div></a>
    <a class="kpi kg kg-violet" href="<?= h(url($base . '/pending.php?cat=shortlisting')) ?>#list"><span class="kg-ic">🔎</span><div class="l">No shortlist yet</div><div class="v"><?= $tot['stu_none'] ?></div><div class="s">students without any application</div></a>
  </div>
</section>

<section class="panel">
  <form method="get" class="toolbar" action="<?= h(url($self)) ?>">
    <div class="seg-chips" role="group" aria-label="Show">
      <?php foreach (['remaining' => 'Still to submit', 'submitted' => 'Submitted', 'all' => 'All'] as $vk => $vl): ?><a class="<?= $view === $vk ? 'on' : '' ?>" href="<?= h($qsv(['view' => $vk])) ?>"><?= $vl ?></a><?php endforeach; ?>
    </div>
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <select name="country" data-autosubmit aria-label="Country"><option value="">All countries</option><?php foreach ($countries as $c): ?><option <?= strcasecmp($fCountry, $c) === 0 ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
    <?php if ($admin || $u['role'] === 'pms_lead'): ?>
      <select name="officer" data-autosubmit aria-label="Officer"><option value="">All officers</option>
        <optgroup label="Front End"><?php foreach ($officers['frontend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $fOfficer === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></optgroup>
        <optgroup label="Back End"><?php foreach ($officers['backend'] as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $fOfficer === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></optgroup>
      </select>
    <?php endif; ?>
    <input type="search" name="q" value="<?= h(get('q')) ?>" placeholder="Search student or university…">
    <button class="btn sm" type="submit">Filter</button>
    <span class="count num" style="margin-left:auto"><?= count($cards) ?> students</span>
  </form>

  <?php if (!$cards): ?><div class="empty"><?= $view === 'remaining' ? '✓ Nothing left to submit for this filter.' : 'No applications for this filter.' ?></div><?php endif; ?>
  <div class="ap-list">
  <?php foreach ($cards as $c): if (++$n > $MAX) break; $s = $c['s']; $sid = (int)$s['id'];
        $total = count($c['sent']) + count($c['left']); $pctS = $total ? (int)round(count($c['sent']) / $total * 100) : 0;
        $canEdit = ($u['role'] ?? '') !== 'counselor' && can_edit_student($u, $s); ?>
    <article class="ap-card" id="st<?= $sid ?>">
      <header>
        <div class="ap-who"><a href="<?= h(url($base . '/student.php?id=' . $sid)) ?>#applications"><b><?= h($s['name']) ?></b></a>
          <small><?= country_flag($s['country']) ?> <?= h($s['country'] ?: '—') ?><?= $s['program_level'] ? ' · ' . h($s['program_level']) : '' ?><?= $s['intake'] ? ' · ' . h($s['intake']) : '' ?> · <span class="tag-fe">FE</span> <?= h($s['fe_name'] ?: '—') ?> · <span class="tag-be">BE</span> <?= h($s['be_name'] ?: '—') ?></small></div>
        <div class="ap-score"><b><?= count($c['sent']) ?></b><span>/ <?= $total ?> submitted</span></div>
      </header>
      <div class="ap-bar"><i style="width:<?= $pctS ?>%"></i></div>
      <form method="post" class="ap-form">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($backQs) ?>"><input type="hidden" name="sid" value="<?= $sid ?>">
        <?php if ($c['left'] && $view !== 'submitted'): ?>
          <div class="ap-sec"><span class="ap-sec-h warn-t">📝 Still to submit (<?= count($c['left']) ?>)</span>
            <?php foreach ($c['left'] as $li => $a): if ($li === 6): ?><details class="ap-more"><summary>Show <?= count($c['left']) - 6 ?> more</summary><?php endif; ?>
              <label class="ap-row is-left">
                <?php if ($canEdit): ?><input type="checkbox" name="app[]" value="<?= (int)$a['id'] ?>"><?php endif; ?>
                <span class="ap-u"><b><?= h($a['university'] ?: 'University') ?></b><small><?= h($a['program'] ?: '—') ?><?= $a['application_type'] ? ' · ' . h($a['application_type']) : '' ?></small></span>
                <?php if (!empty($a['deadline'])): $late = $a['deadline'] < date('Y-m-d') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['deadline']); ?><span class="ap-dl <?= $late ? 'bad-t' : '' ?>" title="Deadline: <?= h($a['deadline']) ?>">⏰ <?= h(preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['deadline']) ? fmt_date($a['deadline']) : mb_strimwidth((string)$a['deadline'], 0, 28, '…')) ?></span><?php endif; ?>
                <span class="pill sm warn"><?= h($a['status'] ?: 'Shortlisted') ?></span>
              </label>
            <?php endforeach; if (count($c['left']) > 6): ?></details><?php endif; ?>
            <?php if ($canEdit): ?>
              <div class="ap-act"><button class="btn sm primary" name="action" value="submit">✓ Mark ticked as submitted</button>
                <input type="date" name="applied_date" value="<?= date('Y-m-d') ?>" aria-label="Submitted on">
                <button type="button" class="btn sm ghost" data-ap-all>Tick all</button></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($c['sent'] && $view !== 'remaining'): ?>
          <div class="ap-sec"><span class="ap-sec-h ok-t">✅ Submitted (<?= count($c['sent']) ?>)</span><small class="muted">To change a submitted application, open the student (click the name).</small>
            <?php foreach ($c['sent'] as $a): ?>
              <div class="ap-row">
                <span class="ap-u"><b><?= h($a['university'] ?: 'University') ?></b><small><?= h($a['program'] ?: '—') ?><?= $a['applied_date'] ? ' · applied ' . h(preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['applied_date']) ? fmt_date($a['applied_date']) : $a['applied_date']) : '' ?></small></span>
                <span class="pill sm <?= $appTone((string)$a['status']) ?>"><?= h($a['status']) ?></span>

              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($c['sent']): ?>
          <p class="small muted ap-note">✅ <?= count($c['sent']) ?> already submitted: <?= h(implode(', ', array_slice(array_map(fn($a) => $a['university'], $c['sent']), 0, 4))) ?><?= count($c['sent']) > 4 ? '…' : '' ?></p>
        <?php endif; ?>
      </form>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (count($cards) > $MAX): ?><p class="small muted">Showing <?= $MAX ?> of <?= count($cards) ?> students — filter by officer or country to see the rest.</p><?php endif; ?>
</section>
<?php page_end();
