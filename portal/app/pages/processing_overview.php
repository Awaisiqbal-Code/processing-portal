<?php
declare(strict_types=1);
// Processing Overview — e-commerce style analytics (orders → students). Expects $u and $reportsUrl.

$today = date('Y-m-d');
$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : $today;
if ($from > $to) [$from, $to] = [$to, $from];
$team = in_array(get('team'), ['frontend', 'backend'], true) ? get('team') : '';
$countryF = get('country');
$base = is_admin_role($u['role']) ? 'admin' : 'officer';

[$w, $p] = scope_sql($u);
$where = [$w];
$params = $p;
if ($countryF !== '') { $where[] = 'LOWER(TRIM(s.country)) = LOWER(?)'; $params[] = $countryF; }
$rows = q('SELECT s.* FROM students s WHERE ' . implode(' AND ', $where), $params)->fetchAll();

$ids = array_map(fn($r) => (int)$r['id'], $rows);
$saved = []; $apps = [];
if ($ids) {
    foreach (q('SELECT * FROM student_stages') as $sr) $saved[(int)$sr['student_id']][$sr['stage_key']] = $sr;
    foreach (q('SELECT student_id, status FROM student_applications') as $ar) $apps[(int)$ar['student_id']][] = $ar;
}
$ovDocState = $ids ? load_doc_state($ids) : [];
$names = staff_name_map();
$statusCount = array_fill_keys(PROCESSING_STATUSES, 0);
$stageDone = array_fill_keys(array_keys(JOURNEY_STAGES), 0);
$newInPeriod = 0;
$byCounselor = []; $byFe = []; $byBe = [];
$blank = fn() => ['students' => 0, 'new' => 0, 'progress' => 0, 'pending' => 0, 'completed' => 0, 'offers' => 0, 'apps' => 0, 'docs_done' => 0, 'visa' => 0, 'rejected' => 0];

foreach ($rows as $r) {
    $sid = (int)$r['id'];
    $r['_doc_info'] = student_pending_docs($r, $ovDocState[$sid] ?? []);
    $auto = auto_journey($r, $apps[$sid] ?? [], 0);
    $j = [];
    foreach (JOURNEY_STAGES as $k => $_) $j[$k] = ['status' => isset($saved[$sid][$k]) ? $saved[$sid][$k]['status'] : $auto[$k][0]];
    $st = processing_status($r, $j);
    $statusCount[$st]++;
    foreach ($j as $k => $x) if ($x['status'] === 'Completed') $stageDone[$k]++;
    $reg = substr((string)($r['received_date'] ?: $r['created_at']), 0, 10);
    $isNew = $reg >= $from && $reg <= $to;
    if ($isNew) $newInPeriod++;

    $add = function (array &$bucket, string $key) use ($st, $isNew, $j, $apps, $sid, $r, $blank) {
        if (!isset($bucket[$key])) $bucket[$key] = $blank();
        $b = &$bucket[$key];
        $b['students']++;
        if ($isNew) $b['new']++;
        if ($st === 'In Progress' || $st === 'New') $b['progress']++;
        if ($st === 'Pending') $b['pending']++;
        if ($st === 'Completed') $b['completed']++;
        if ($st === 'Rejected') $b['rejected']++;
        if ($j['offer']['status'] === 'Completed') $b['offers']++;
        if ($j['documents']['status'] === 'Completed') $b['docs_done']++;
        if ($j['visa']['status'] === 'Completed') $b['visa']++;
        $b['apps'] += count($apps[$sid] ?? []);
    };
    $cn = trim((string)($r['counselor'] ?? '')) ?: 'Not assigned';
    $add($byCounselor, $cn);
    if ($team !== 'backend') { $fe = (int)($r['frontend_officer_id'] ?? 0); $add($byFe, $fe ? ($names[$fe] ?? 'Unknown') : 'Not assigned'); }
    if ($team !== 'frontend') { $be = (int)($r['officer_id'] ?? 0); $add($byBe, $be ? ($names[$be] ?? 'Unknown') : 'Not assigned'); }
}
$sortDesc = function (array &$a) { uasort($a, fn($x, $y) => $y['students'] <=> $x['students']); };
$sortDesc($byCounselor); $sortDesc($byFe); $sortDesc($byBe);
$total = count($rows);
$countries = q("SELECT DISTINCT country FROM students WHERE country IS NOT NULL AND country != '' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
$qsO = fn(array $o) => '?' . http_build_query(array_merge(['tab' => 'overview', 'from' => $from, 'to' => $to, 'team' => $team ?: null, 'country' => $countryF ?: null], $o));

page_start('Processing Overview', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div><div class="eyebrow">Reports</div><h1>Processing Overview</h1><p>Every student is tracked like an order: status, journey step and who is handling it.</p></div>
    <div class="row-actions no-print">
      <a class="btn sm ghost" href="<?= h($reportsUrl) ?>">Operations report</a>
      <a class="btn sm ghost" href="<?= h(url($base . '/pending_report.php')) ?>">Officer pending reports</a>
      <button type="button" class="btn sm" onclick="window.print()">🖨 Print</button>
    </div>
  </div>
  <form method="get" class="toolbar no-print" style="margin:0;">
    <input type="hidden" name="tab" value="overview">
    <label class="small muted">Registered from <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="small muted">to <input type="date" name="to" value="<?= h($to) ?>"></label>
    <select name="team" data-autosubmit aria-label="Team"><option value="">Front-end &amp; back-end</option><option value="frontend" <?= $team === 'frontend' ? 'selected' : '' ?>>Front-end only</option><option value="backend" <?= $team === 'backend' ? 'selected' : '' ?>>Back-end only</option></select>
    <select name="country" data-autosubmit aria-label="Country"><option value="">All countries</option><?php foreach ($countries as $c): ?><option <?= $countryF === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
    <button class="btn sm" type="submit">Apply</button>
  </form>
</section>

<div class="kpis">
  <a class="kpi" href="<?= h(url($base . '/students.php')) ?>"><div class="l">Total students</div><div class="v"><?= $total ?></div><div class="s"><?= $countryF ?: 'All countries' ?></div></a>
  <div class="kpi"><div class="l">New registrations</div><div class="v" style="color:#7C3AED"><?= $newInPeriod ?></div><div class="s"><?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?></div></div>
  <a class="kpi" href="<?= h(url($base . '/students.php?pstatus=In+Progress')) ?>"><div class="l">In progress</div><div class="v"><?= $statusCount['In Progress'] + $statusCount['New'] ?></div><div class="s">Being processed</div></a>
  <a class="kpi" href="<?= h(url($base . '/students.php?pstatus=Pending')) ?>"><div class="l">Pending</div><div class="v" style="color:var(--warn)"><?= $statusCount['Pending'] ?></div><div class="s">Waiting for staff remark</div></a>
  <a class="kpi" href="<?= h(url($base . '/students.php?pstatus=Completed')) ?>"><div class="l">Completed</div><div class="v" style="color:var(--ok)"><?= $statusCount['Completed'] ?></div><div class="s">Visa granted / closed</div></a>
  <a class="kpi" href="<?= h(url($base . '/students.php?pstatus=On+Hold')) ?>"><div class="l">On hold · Rejected</div><div class="v"><?= $statusCount['On Hold'] ?> · <?= $statusCount['Rejected'] ?></div><div class="s">Inactive, disabled or refused</div></a>
</div>

<div class="grid2">
  <section class="panel">
    <div class="panel-h"><div><h2>Status mix</h2><p>Share of all <?= $total ?> students by processing status.</p></div></div>
    <div class="stackbar">
      <?php foreach (PROCESSING_STATUSES as $ps): if (!$statusCount[$ps]) continue; ?>
        <i class="<?= processing_status_class($ps) ?>" style="flex: <?= $statusCount[$ps] ?>" title="<?= h($ps) ?>: <?= $statusCount[$ps] ?>"></i>
      <?php endforeach; ?>
    </div>
    <div class="status-chips" style="margin-top:12px;">
      <?php foreach (PROCESSING_STATUSES as $ps): ?><a class="status-chip <?= processing_status_class($ps) ?>" href="<?= h(url($base . '/students.php?pstatus=' . rawurlencode($ps))) ?>"><i></i><?= h($ps) ?> <b><?= $statusCount[$ps] ?></b></a><?php endforeach; ?>
    </div>
  </section>
  <section class="panel">
    <div class="panel-h"><div><h2>Journey funnel</h2><p>Students who have completed each step.</p></div></div>
    <div class="bars">
      <?php foreach (JOURNEY_STAGES as $k => $def): ?>
        <div class="bar"><span><?= h($def[0]) ?></span><span class="t"><i style="width:<?= $total ? round($stageDone[$k] / $total * 100, 1) : 0 ?>%"></i></span><span class="n num"><?= $stageDone[$k] ?></span></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php
$tableOf = function (string $title, string $sub, array $data, array $cols) {
    ?>
    <section class="panel">
      <div class="panel-h"><div><h2><?= h($title) ?></h2><p><?= h($sub) ?></p></div></div>
      <?php if (!$data): ?><div class="empty">No data for this filter.</div><?php else: ?>
      <div class="tbl"><table>
        <thead><tr><th>Name</th><?php foreach ($cols as $c => $lbl): ?><th class="num"><?= h($lbl) ?></th><?php endforeach; ?><th>Share of students</th></tr></thead>
        <tbody>
          <?php $mx = max(1, max(array_column($data, 'students'))); foreach ($data as $nm => $b): ?>
            <tr><td><b><?= h($nm) ?></b></td>
              <?php foreach ($cols as $c => $lbl): ?><td class="num"><?= $c === 'conv' ? ($b['students'] ? round($b['offers'] / $b['students'] * 100) . '%' : '—') : (int)$b[$c] ?></td><?php endforeach; ?>
              <td><span class="meter blue"><i style="width:<?= round($b['students'] / $mx * 100) ?>%"></i></span></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
};
if ($team !== 'backend') $tableOf('Front-end officers — student count', 'Students per front-end officer, registrations in the period and document collection completed.', $byFe, ['students' => 'Students', 'new' => 'New', 'docs_done' => 'Docs complete', 'progress' => 'In progress', 'pending' => 'Pending']);
if ($team !== 'frontend') $tableOf('Back-end officers — processing count', 'Applications, offers and visas handled by each back-end officer.', $byBe, ['students' => 'Students', 'apps' => 'Applications', 'offers' => 'Offers', 'visa' => 'Visas', 'completed' => 'Completed', 'pending' => 'Pending']);
$tableOf('Counselor-wise performance', 'Students referred by each counselor and how far they progressed.', $byCounselor, ['students' => 'Students', 'new' => 'New', 'offers' => 'Offers', 'visa' => 'Visas', 'rejected' => 'Rejected', 'conv' => 'Offer rate']);
page_end();
