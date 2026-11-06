<?php
declare(strict_types=1);
// Pending Tracker — one clear rule per category, every student counted once per category.
// Expects $u.

[$w, $p] = scope_sql($u);
$admin = is_admin_role($u['role']);
$isLead = $admin || $u['role'] === 'pms_lead';
$base = $admin ? 'admin' : 'officer';

$rows = q("
    SELECT s.*, o.name AS officer_name, fo.name AS fe_user_name,
           (SELECT COUNT(*) FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL) AS remark_count,
           (SELECT sr.created_at FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_created_at,
           (SELECT sr.remark_text FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_text,
           (SELECT sr.author_name FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_name
    FROM students s
    LEFT JOIN users o ON o.id = s.officer_id
    LEFT JOIN users fo ON fo.id = s.frontend_officer_id
    WHERE $w AND s.student_status = 'active'
    ORDER BY s.id ASC
", $p)->fetchAll();

$docState = load_doc_state();
$appCounts = [];
foreach (q('SELECT student_id, COUNT(*) AS cnt FROM student_applications GROUP BY student_id') as $ar) $appCounts[(int)$ar['student_id']] = (int)$ar['cnt'];

$CATS = ['all' => 'Any pending'] + PENDING_TASKS;
$blankCounts = fn() => array_fill_keys(array_merge(['students', 'any'], array_keys(PENDING_TASKS)), 0);
$totals = $blankCounts();
$byOfficer = [];
$officersList = q("SELECT id, name, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer','pms_lead','processing_staff') AND active = 1 ORDER BY CASE WHEN department = 'frontend' THEN 0 ELSE 1 END, name COLLATE NOCASE")->fetchAll();
foreach ($officersList as $o) $byOfficer[(int)$o['id']] = $o + ['c' => $blankCounts(), 'countries' => []];
$byCountry = [];
$docFreq = [];

foreach ($rows as $i => $r) {
    $sid = (int)$r['id'];
    $pi = student_pending($r, $docState[$sid] ?? [], $appCounts[$sid] ?? 0);
    $rows[$i]['_p'] = $pi;
    $add = function (array &$c) use ($pi) {
        $c['students']++;
        if ($pi['any']) $c['any']++;
        foreach (PENDING_TASKS as $k => $_) if ($pi[$k]) $c[$k]++;
    };
    $add($totals);
    $cn = trim((string)($r['country'] ?? '')) ?: 'Not set';
    if (!isset($byCountry[$cn])) $byCountry[$cn] = $blankCounts();
    $add($byCountry[$cn]);
    foreach (array_unique(array_filter([(int)$r['officer_id'], (int)$r['frontend_officer_id']])) as $oid) {
        if (!isset($byOfficer[$oid])) continue;
        $add($byOfficer[$oid]['c']);
        $byOfficer[$oid]['countries'][$cn] = true;
    }
    foreach ($pi['docs_missing'] as $d) $docFreq[$d] = ($docFreq[$d] ?? 0) + 1;
}
uasort($byCountry, fn($a, $b) => $b['students'] <=> $a['students']);
arsort($docFreq);

// ---------- filters ----------
$cat = get('cat') ?: 'all';
if (!isset($CATS[$cat])) $cat = 'all';
$fCountry = get('country');
$fOfficer = (int)get('officer');
$fLevel = strtoupper(get('program_level'));
$fDoc = get('doc');
$fq = get('q');
$list = array_values(array_filter($rows, function ($r) use ($cat, $fCountry, $fOfficer, $fLevel, $fDoc, $fq) {
    $pi = $r['_p'];
    if ($cat === 'all' ? !$pi['any'] : !$pi[$cat]) return false;
    if ($fCountry !== '' && strcasecmp(trim((string)$r['country']) ?: 'Not set', $fCountry) !== 0) return false;
    if ($fOfficer && (int)$r['officer_id'] !== $fOfficer && (int)$r['frontend_officer_id'] !== $fOfficer) return false;
    if ($fLevel !== '' && strtoupper((string)$r['program_level']) !== $fLevel) return false;
    if ($fDoc !== '' && !in_array($fDoc, $pi['docs_missing'], true)) return false;
    if ($fq !== '' && !str_contains(strtolower($r['name'] . ' ' . $r['email'] . ' ' . implode(' ', $pi['docs_missing'])), strtolower($fq))) return false;
    return true;
}));
usort($list, fn($a, $b) => [count($b['_p']['task_keys']), $b['_p']['docs_count']] <=> [count($a['_p']['task_keys']), $a['_p']['docs_count']]);

function pending_url(string $base, array $over = []): string {
    $params = array_merge(['cat' => get('cat') ?: 'all', 'country' => get('country'), 'officer' => get('officer'), 'program_level' => get('program_level'), 'doc' => get('doc'), 'q' => get('q')], $over);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 0 && $v !== '0');
    return url($base . '/pending.php' . ($params ? '?' . http_build_query($params) : ''));
}
$catTone = ['all' => 'acc', 'docs' => 'bad', 'consent' => 'warn', 'first_meeting' => 'acc', 'recording' => 'acc', 'second_tax' => 'hold', 'shortlisting' => 'warn', 'credentials' => 'acc', 'remarks' => 'bad'];
$catHelp = [
    'all'           => 'Active students with at least one open item',
    'docs'          => 'At least one required document not yet received',
    'consent'       => 'Signed consent form not marked as received',
    'first_meeting' => 'Initial meeting with the student not held',
    'recording'     => 'No recording link / video and no reason given (Italy)',
    'second_tax'    => '2nd processing or tax consultant meeting open (Italy)',
    'shortlisting'  => 'No shortlisted university yet',
    'credentials'   => 'Student email or university portal ID missing',
    'remarks'       => 'Profile was edited and no staff remark added since',
];
$flagMap = country_flags_map();

page_start('Pending Tracker', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div>
      <div class="eyebrow">Pending Tracker</div>
      <h1>What is pending, and with whom</h1>
      <p>Each student is counted once per item. Documents are one item: a student is “documents pending” if any required document is missing — wherever the file is kept (Google Drive or uploaded here).</p>
    </div>
    <div class="row-actions">
      <a class="btn sm" href="<?= h(url($base . '/pending_report.php')) ?>">Officer pending reports →</a>
    </div>
  </div>
  <div class="pend-cards">
    <?php foreach ($CATS as $ck => $cl): $val = $ck === 'all' ? $totals['any'] : $totals[$ck]; ?>
      <a class="pend-card tone-<?= $catTone[$ck] ?> <?= $cat === $ck ? 'on' : '' ?>" href="<?= h(pending_url($base, ['cat' => $ck]) . '#list') ?>" title="<?= h($catHelp[$ck]) ?>">
        <span class="pc-l"><?= h($cl) ?></span>
        <span class="pc-v"><?= $val ?></span>
        <span class="pc-s"><?= h($catHelp[$ck]) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($isLead): ?>
<section class="panel">
  <div class="panel-h"><div><h2>Pending by processing officer</h2><p>Number of students with each open item. Click a number to see exactly those students.</p></div></div>
  <div class="tbl"><table class="matrix">
    <thead><tr><th>Officer</th><th class="num">Students</th><th class="num">With pending</th>
      <?php foreach (PENDING_TASKS as $k => $l): ?><th class="num" title="<?= h($catHelp[$k]) ?>"><?= h($l) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($byOfficer as $oid => $o): if (!$o['c']['students']) continue; ?>
        <tr class="<?= $fOfficer === $oid ? 'is-sel' : '' ?>">
          <td><a href="<?= h(pending_url($base, ['officer' => $oid, 'cat' => 'all']) . '#list') ?>"><b><?= h($o['name']) ?></b></a>
            <span class="<?= $o['department'] === 'frontend' ? 'tag-fe' : 'tag-be' ?>"><?= $o['department'] === 'frontend' ? 'FE' : 'BE' ?></span>
            <div class="small muted"><?= implode(' ', array_map(fn($c) => $flagMap[$c] ?? '', array_keys($o['countries']))) ?> <?= h(implode(', ', array_keys($o['countries']))) ?></div></td>
          <td class="num"><?= $o['c']['students'] ?></td>
          <td class="num"><a class="cnt <?= $o['c']['any'] ? 'warn' : 'zero' ?>" href="<?= h(pending_url($base, ['officer' => $oid, 'cat' => 'all']) . '#list') ?>"><?= $o['c']['any'] ?></a></td>
          <?php foreach (PENDING_TASKS as $k => $_): $v = $o['c'][$k]; ?>
            <td class="num"><a class="cnt <?= $v ? ($k === 'docs' || $k === 'remarks' ? 'bad' : 'warn') : 'zero' ?>" href="<?= h(pending_url($base, ['officer' => $oid, 'cat' => $k]) . '#list') ?>"><?= $v ?></a></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <tr class="total"><td>All students (each counted once)</td><td class="num"><?= $totals['students'] ?></td><td class="num"><?= $totals['any'] ?></td>
        <?php foreach (PENDING_TASKS as $k => $_): ?><td class="num"><?= $totals[$k] ?></td><?php endforeach; ?></tr>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<div class="grid2">
  <section class="panel">
    <div class="panel-h"><div><h2>Pending by country</h2><p>Students with each open item.</p></div></div>
    <div class="tbl"><table>
      <thead><tr><th>Country</th><th class="num">Students</th><th class="num">Documents</th><th class="num">Consent</th><th class="num">Meetings</th><th class="num">Shortlist</th><th class="num">Email / portal</th></tr></thead>
      <tbody>
        <?php foreach ($byCountry as $cn => $c): ?>
          <tr class="<?= strcasecmp($fCountry, $cn) === 0 ? 'is-sel' : '' ?>">
            <td><a href="<?= h(pending_url($base, ['country' => $cn]) . '#list') ?>"><?= $flagMap[$cn] ?? '' ?> <b><?= h($cn) ?></b></a></td>
            <td class="num"><?= $c['students'] ?></td>
            <td class="num"><a class="cnt <?= $c['docs'] ? 'bad' : 'zero' ?>" href="<?= h(pending_url($base, ['country' => $cn, 'cat' => 'docs']) . '#list') ?>"><?= $c['docs'] ?></a></td>
            <td class="num"><a class="cnt <?= $c['consent'] ? 'warn' : 'zero' ?>" href="<?= h(pending_url($base, ['country' => $cn, 'cat' => 'consent']) . '#list') ?>"><?= $c['consent'] ?></a></td>
            <td class="num"><a class="cnt <?= $c['first_meeting'] ? 'warn' : 'zero' ?>" href="<?= h(pending_url($base, ['country' => $cn, 'cat' => 'first_meeting']) . '#list') ?>"><?= $c['first_meeting'] ?></a></td>
            <td class="num"><a class="cnt <?= $c['shortlisting'] ? 'warn' : 'zero' ?>" href="<?= h(pending_url($base, ['country' => $cn, 'cat' => 'shortlisting']) . '#list') ?>"><?= $c['shortlisting'] ?></a></td>
            <td class="num"><a class="cnt <?= $c['credentials'] ? 'warn' : 'zero' ?>" href="<?= h(pending_url($base, ['country' => $cn, 'cat' => 'credentials']) . '#list') ?>"><?= $c['credentials'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <section class="panel">
    <div class="panel-h"><div><h2>Most-missing documents</h2><p>How many students still have to send each document. Click to see who.</p></div></div>
    <?php if (!$docFreq): ?><div class="empty">No documents pending.</div><?php else: $mx = max($docFreq); ?>
      <div class="bars">
        <?php foreach (array_slice($docFreq, 0, 12, true) as $dn => $dc): ?>
          <a class="bar <?= $fDoc === $dn ? 'is-sel' : '' ?>" href="<?= h(pending_url($base, ['cat' => 'docs', 'doc' => $dn]) . '#list') ?>"><span><?= h($dn) ?></span><span class="t"><i style="width:<?= round($dc / $mx * 100, 1) ?>%"></i></span><span class="n num"><?= $dc ?></span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel" id="list">
  <div class="panel-h">
    <div><h2><?= h($CATS[$cat]) ?> — <?= count($list) ?> student<?= count($list) === 1 ? '' : 's' ?></h2><p>Most open items first. Click a document to open the student's Documents section.</p></div>
  </div>
  <form method="get" class="toolbar" action="<?= h(url($base . '/pending.php')) ?>#list">
    <select name="cat" data-autosubmit aria-label="Pending item">
      <?php foreach ($CATS as $ck => $cl): ?><option value="<?= h($ck) ?>" <?= $cat === $ck ? 'selected' : '' ?>><?= h($cl) ?> (<?= $ck === 'all' ? $totals['any'] : $totals[$ck] ?>)</option><?php endforeach; ?>
    </select>
    <select name="country" data-autosubmit aria-label="Country"><option value="">All countries</option><?php foreach (array_keys($byCountry) as $cn): ?><option <?= strcasecmp($fCountry, $cn) === 0 ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select>
    <?php if ($isLead): ?>
      <select name="officer" data-autosubmit aria-label="Processing officer"><option value="">All processing officers</option>
        <?php foreach ($byOfficer as $oid => $o): if (!$o['c']['students']) continue; ?><option value="<?= $oid ?>" <?= $fOfficer === $oid ? 'selected' : '' ?>><?= h($o['name']) ?> (<?= $o['department'] === 'frontend' ? 'FE' : 'BE' ?>)</option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="doc" data-autosubmit aria-label="Missing document"><option value="">Any document</option><?php foreach (array_keys($docFreq) as $dn): ?><option <?= $fDoc === $dn ? 'selected' : '' ?>><?= h($dn) ?></option><?php endforeach; ?></select>
    <select name="program_level" data-autosubmit aria-label="Level"><option value="">BS &amp; MS</option><option <?= $fLevel === 'BS' ? 'selected' : '' ?>>BS</option><option <?= $fLevel === 'MS' ? 'selected' : '' ?>>MS</option></select>
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search student…">
    <button class="btn sm" type="submit">Filter</button>
    <?php if ($cat !== 'all' || $fCountry || $fOfficer || $fLevel || $fDoc || $fq): ?><a class="btn sm ghost" href="<?= h(url($base . '/pending.php')) ?>#list">Reset</a><?php endif; ?>
  </form>

  <?php if (!$list): ?>
    <div class="empty">Nothing pending for this filter.</div>
  <?php else: ?>
  <div class="tbl tbl-pending"><table>
    <colgroup><col style="width:52px"><col style="width:190px"><col style="width:150px"><col style="width:220px"><col style="width:300px"><col style="width:220px"><col style="width:96px"></colgroup>
    <thead><tr><th>SR#</th><th>Student</th><th>Processing officers</th><th>Open items</th><th>Documents pending</th><th>Last remark</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): $pi = $r['_p']; $cn = trim((string)$r['country']); $prof = url($base . '/student.php?id=' . (int)$r['id']); ?>
        <tr>
          <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
          <td><a href="<?= h($prof) ?>"><b><?= h($r['name']) ?></b></a>
            <div class="order-tags" style="margin-top:4px;"><span class="pill sm"><?= $flagMap[$cn] ?? '' ?> <?= h($cn ?: '—') ?></span><?php if ($r['program_level']): ?><span class="pill sm acc"><?= h($r['program_level']) ?></span><?php endif; ?></div></td>
          <td class="small">
            <div class="officer-line"><span class="tag-fe">FE</span> <b><?= h(($r['fe_user_name'] ?: $r['frontend_officer_name']) ?: 'Not set') ?></b></div>
            <div class="officer-line"><span class="tag-be">BE</span> <b><?= h($r['officer_name'] ?: 'Not set') ?></b></div>
          </td>
          <td>
            <div class="chip-row">
              <?php foreach ($pi['task_keys'] as $k): if ($k === 'docs') continue; ?>
                <span class="pill sm <?= $k === 'remarks' ? 'bad' : 'warn' ?>" title="<?= h($catHelp[$k]) ?>"><?= h(PENDING_TASKS[$k]) ?><?= $k === 'remarks' && $pi['remark_hours'] !== null ? ' · ' . $pi['remark_hours'] . 'h' : '' ?></span>
              <?php endforeach; ?>
              <?php if (!array_diff($pi['task_keys'], ['docs'])): ?><span class="pill sm ok">Only documents</span><?php endif; ?>
            </div>
            <?php if ($pi['recording_state']['state'] === 'not_recorded' && strtolower($cn) === 'italy'): ?><div class="small muted" style="margin-top:4px;">No recording: <?= h(mb_strimwidth($pi['recording_state']['reason'], 0, 60, '…')) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($pi['docs']): ?>
              <div class="small muted" style="margin-bottom:4px;"><b class="bad-t"><?= $pi['docs_count'] ?> pending</b> · <?= $pi['docs_in_hand'] ?> of <?= $pi['docs_required'] ?> required in hand</div>
              <div class="chip-row"><?php foreach ($pi['docs_missing'] as $d): ?><a class="doc-chip" href="<?= h($prof . '#documents') ?>"><?= h($d) ?></a><?php endforeach; ?></div>
            <?php else: ?>
              <span class="pill sm ok">✓ All documents received</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= last_remarks_cell($r) ?></td>
          <td><div class="pend-actions"><a class="btn sm primary" href="<?= h($prof) ?>">Open</a><?php if ($dl = safe_link($r['drive_link'] ?? null)): ?><a class="btn sm" href="<?= h($dl) ?>" target="_blank" rel="noopener noreferrer">Drive</a><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php page_end();
