<?php
declare(strict_types=1);
// Shared dashboard for admin and officer. Expects $u.

[$w, $p] = scope_sql($u);
$rows = q("SELECT s.*, o.name AS officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id WHERE $w", $p)->fetchAll();
$admin = $u['role'] === 'admin';
$base = $admin ? 'admin' : 'officer';

$n = count($rows);
$month = date('Y-m');
$thisMonth = count(array_filter($rows, fn($r) => str_starts_with((string)$r['created_at'], $month)));
$onb = count(array_filter($rows, fn($r) => $r['consent'] !== 'Yes' || $r['docs_checklist'] !== 'Yes' || $r['initial_meeting'] !== 'Yes'));
$offers = count(array_filter($rows, fn($r) => $r['offer'] === 'Yes'));
$granted = count(array_filter($rows, fn($r) => $r['visa'] === 'Granted'));
$refused = count(array_filter($rows, fn($r) => $r['visa'] === 'Refused'));
$due = due_list($rows);
$dueNow = count(array_filter($due, fn($d) => $d['date'] <= today()));

page_start('Dashboard', $u, PORTALS[$u['role']]['label']);
?>
<h1><?= $admin ? 'Team overview' : 'Hello, ' . h($u['name']) ?></h1>

<div class="kpis">
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php')) ?>"><div class="l">Total students</div><div class="v"><?= $n ?></div><div class="s"><?= $thisMonth ?> added this month · Click to view</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php?kpi=onboarding')) ?>"><div class="l">In onboarding</div><div class="v"><?= $onb ?></div><div class="s">Consent, checklist or first meeting open</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php?kpi=offers')) ?>"><div class="l">Offers received</div><div class="v"><?= $offers ?></div><div class="s"><?= $n ? round($offers / $n * 100) : 0 ?>% of students</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php?kpi=visa_granted')) ?>"><div class="l">Visa granted</div><div class="v"><?= $granted ?></div><div class="s"><?= $refused ?> refused</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="#followups-panel"><div class="l">Follow-ups due</div><div class="v <?= $dueNow ? 'bad-t' : '' ?>"><?= $dueNow ?></div><div class="s">Today or overdue</div></a>
</div>

<div class="grid2">
<?php if ($admin):
    $officers = q("SELECT id, name, active, COALESCE(department, 'backend') AS department FROM users WHERE role = 'officer' ORDER BY department DESC, name ASC")->fetchAll(); ?>
  <section class="panel">
    <div class="panel-h">
      <div>
        <h2>Students per processing officer (Front End & Back End)</h2>
        <p>Click an officer to open their dedicated profile and workspace</p>
      </div>
      <div class="row-actions">
        <a class="btn sm primary" href="<?= h(url('admin/officers.php')) ?>">View All Officers Overview →</a>
      </div>
    </div>
    <div class="tbl"><table>
      <thead><tr><th>Officer & Team</th><th>Students</th><th>Offers</th><th>Visa granted</th><th>Follow-ups due</th><th>Workspace</th></tr></thead>
      <tbody>
      <?php foreach ($officers as $o):
          $mine = array_filter($rows, fn($r) => (int)$r['officer_id'] === (int)$o['id'] || (int)($r['frontend_officer_id'] ?? 0) === (int)$o['id']);
          $d = count(array_filter(due_list($mine), fn($x) => $x['date'] <= today())); ?>
        <tr>
          <td>
            <a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><b><?= h($o['name']) ?></b></a>
            <span class="pill <?= $o['department'] === 'frontend' ? 'acc' : '' ?>" style="font-size:0.7rem; margin-left:0.25rem;"><?= $o['department'] === 'frontend' ? 'Front End' : 'Back End' ?></span>
            <?= $o['active'] ? '' : ' <span class="pill">Inactive</span>' ?>
          </td>
          <td class="num"><a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><?= count($mine) ?></a></td>
          <td class="num"><?= count(array_filter($mine, fn($r) => $r['offer'] === 'Yes')) ?></td>
          <td class="num"><?= count(array_filter($mine, fn($r) => $r['visa'] === 'Granted')) ?></td>
          <td><span class="pill <?= $d ? 'bad' : 'ok' ?>"><?= $d ?></span></td>
          <td><a class="btn sm" href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>">Open Profile →</a></td>
        </tr>
      <?php endforeach;
          $none = count(array_filter($rows, fn($r) => !$r['officer_id'] || empty($r['frontend_officer_id'])));
          if ($none): ?>
        <tr><td><a href="<?= h(url('admin/officers.php?officer=none')) ?>">Unassigned (FE/BE)</a></td><td class="num"><?= $none ?></td><td></td><td></td><td></td><td><a class="btn sm bad" href="<?= h(url('admin/officers.php?officer=none')) ?>">Assign</a></td></tr>
      <?php endif; ?>
        <tr class="total"><td><a href="<?= h(url('admin/officers.php')) ?>">Total Unique Students</a></td><td class="num"><?= $n ?></td><td class="num"><?= $offers ?></td><td class="num"><?= $granted ?></td><td></td><td><a class="btn sm ghost" href="<?= h(url('admin/officers.php')) ?>">All Officers</a></td></tr>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>

  <section class="panel">
    <div class="panel-h"><h2><?= $admin ? 'Whole-team pipeline' : 'My pipeline' ?></h2><p>Click any step to view students who completed it</p></div>
    <div class="bars">
    <?php foreach (STAGES as $i => $s):
        $c = count(array_filter($rows, fn($r) => stage_done($r, $i))); ?>
      <a class="bar" style="text-decoration:none; color:inherit;" href="<?= h(url($base . '/students.php?done_stage=' . $i)) ?>"><span><?= h($s[0]) ?></span><span class="t"><i style="width:<?= $n ? round($c / $n * 100, 1) : 0 ?>%"></i></span><span class="n num"><?= $c ?></span></a>
    <?php endforeach; ?>
    </div>
  </section>

<?php if (!$admin): ?>
  <section class="panel" id="followups-panel">
    <div class="panel-h"><h2>Follow-ups</h2><p>Missed meetings with a follow-up date</p></div>
    <?php include APP . '/pages/_followups.php'; ?>
  </section>
<?php endif; ?>
</div>

<?php if ($admin):
    $groups = function (string $key) use ($rows): array {
        $m = [];
        foreach ($rows as $r) { $k = trim((string)$r[$key]) ?: 'Not set'; $m[$k] = ($m[$k] ?? 0) + 1; }
        arsort($m);
        return array_slice($m, 0, 10, true);
    }; ?>
<div class="grid2">
  <?php foreach (['counselor' => 'Students per counselor', 'refund' => 'Refund status'] as $key => $title):
      $g = $groups($key); $max = max($g ?: [1]); ?>
  <section class="panel">
    <div class="panel-h"><h2><?= h($title) ?></h2><p><?= $key === 'counselor' ? 'Top 10 (click to filter)' : 'All students (click to filter)' ?></p></div>
    <div class="bars">
    <?php foreach ($g as $k => $c):
        $filterHref = $key === 'counselor' ? url('admin/students.php?q=' . rawurlencode((string)$k)) : url('admin/students.php?refund=' . rawurlencode((string)$k)); ?>
      <a class="bar" style="text-decoration:none; color:inherit;" href="<?= h($filterHref) ?>"><span><?= h($k) ?></span><span class="t"><i style="width:<?= round($c / $max * 100, 1) ?>%"></i></span><span class="n num"><?= $c ?></span></a>
    <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2><?= $admin ? 'Recent Student Files' : 'My Assigned Students' ?> (Showing <?= min(25, count($rows)) ?> of <?= count($rows) ?>)</h2><p>Click on any student name to edit their record or click Progress to view full checklist</p></div>
    <div class="row-actions">
      <a class="btn sm primary" href="<?= h(url($base . '/students.php')) ?>">View all <?= count($rows) ?> students →</a>
    </div>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">No students assigned yet.</div>
  <?php else: ?>
  <div class="tbl"><table>
    <thead><tr><th>SR#</th><th>Student</th><th>Counselor</th><?php if ($admin): ?><th>Officers (FE / BE)</th><?php endif; ?><th>Docs & Meeting</th><th>Refund</th><th>Progress</th><th>Next step</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($rows, 0, 25) as $r):
        $ns = next_stage($r);
        $dLink = safe_link($r['drive_link'] ?? null);
        $hasMeeting = student_has_meeting($r);
        $mLink = url('meeting.php?id=' . (int)$r['id']);
    ?>
      <tr>
        <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
        <td><a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a></td>
        <td><?= h($r['counselor'] ?: '-') ?></td>
        <?php if ($admin): ?>
          <td class="small">
            <div><span class="muted">FE:</span> <b><?= h($r['frontend_officer_name'] ?: 'Not set') ?></b></div>
            <div><span class="muted">BE:</span> <b><?= h($r['officer_name'] ?? 'Not assigned') ?></b></div>
          </td>
        <?php endif; ?>
        <td>
          <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
            <?php if ($dLink): ?>
              <a class="btn sm" href="<?= h($dLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Google Drive Documents Folder">📁 Docs</a>
            <?php endif; ?>
            <?php if ($hasMeeting): ?>
              <a class="btn sm" href="<?= h($mLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video / Zoom">🎥 Meeting</a>
            <?php else: ?>
              <span class="pill muted" style="font-size:0.72rem; padding:0.2rem 0.45rem; background:rgba(100,116,139,0.12); color:#64748b;" title="Meeting video not available">Not Available</span>
            <?php endif; ?>
            <a class="btn sm ghost" href="<?= h(url($base . '/report.php?id=' . $r['id'] . '&format=pdf')) ?>" title="Download Complete Student PDF Dossier">⬇ PDF</a>
          </div>
        </td>
        <td><?php if ($r['refund']): ?><span class="pill <?= $r['refund'] === 'Non-Refundable' ? 'bad' : '' ?>"><?= h($r['refund']) ?></span><?php endif; ?></td>
        <td><a href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>" title="View student progress" style="text-decoration:none; display:inline-block;"><?= progress_dots($r) ?></a></td>
        <td><?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?></td>
        <td>
          <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
            <a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>">Edit</a>
            <a class="btn sm primary" href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>">Progress</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($admin): ?>
<section class="panel" id="followups-panel">
  <div class="panel-h"><h2>Follow-ups</h2><p>Missed meetings with a follow-up date, all officers</p></div>
  <?php include APP . '/pages/_followups.php'; ?>
</section>
<?php endif;
page_end();
