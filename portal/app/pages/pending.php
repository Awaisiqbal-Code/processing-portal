<?php
declare(strict_types=1);
// Pending Tracker — one clear rule per category, every student counted once per category.
// Expects $u.

[$w, $p] = scope_sql($u);

// ---------- fix an item right here (same fields as the profile) ----------
if (is_post() && post('action') === 'quick') {
    csrf_check();
    $qsid = (int)post('sid');
    $qr = q("SELECT s.* FROM students s WHERE s.id = ? AND $w", array_merge([$qsid], $p))->fetch();
    $back = (string)post('back');
    if (!preg_match('#^(admin|officer)/pending\.php(\?[^#]*)?$#', $back)) $back = (is_admin_role($u['role']) ? 'admin' : 'officer') . '/pending.php';
    if (!$qr) { flash('Student not found.', 'bad'); redirect($back . '#list'); }
    $res = pending_quick_action($u, $qr, (string)post('act'), $_POST, $_FILES);
    flash($res['ok'] ? $res['message'] : $res['error'], $res['ok'] ? 'ok' : 'bad');
    redirect($back . '#list');
}
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
$fSid = (int)get('sid');
$list = array_values(array_filter($rows, function ($r) use ($cat, $fCountry, $fOfficer, $fLevel, $fDoc, $fq, $fSid) {
    $pi = $r['_p'];
    if ($fSid && (int)$r['id'] !== $fSid) return false;
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
    $params = array_merge(['cat' => get('cat') ?: 'all', 'country' => get('country'), 'officer' => get('officer'), 'program_level' => get('program_level'), 'doc' => get('doc'), 'q' => get('q'), 'sid' => get('sid'), 'by' => get('by')], $over);
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


<?php
// ---------- the list: one item at a time, grouped by officer, fixable in place ----------
$feCats = ['docs', 'consent', 'first_meeting', 'recording'];
$groupBy = in_array(get('by'), ['fe', 'be'], true) ? get('by') : (in_array($cat, $feCats, true) ? 'fe' : 'be');
$officerName = function (array $r, string $side): string {
    return $side === 'fe' ? (($r['fe_user_name'] ?: $r['frontend_officer_name']) ?: '') : ($r['officer_name'] ?: '');
};
$groups = [];
foreach ($list as $r) {
    $gname = $officerName($r, $groupBy) ?: ($officerName($r, $groupBy === 'fe' ? 'be' : 'fe') ?: 'No officer assigned');
    $groups[$gname][] = $r;
}
uksort($groups, fn($a, $b) => [$a === 'No officer assigned', -count($groups[$a])] <=> [$b === 'No officer assigned', -count($groups[$b])]);
$backUrl = ltrim(substr(pending_url($base), strlen(url(''))), '/');
$uniList = $cat === 'shortlisting' ? array_column(q("SELECT DISTINCT university FROM student_applications WHERE TRIM(COALESCE(university,'')) != '' ORDER BY university COLLATE NOCASE LIMIT 400")->fetchAll(), 'university') : [];
$MAX_ROWS = 250; $shown = 0;
$qa = function (string $act, int $sid, string $inner, bool $multipart = false) use ($backUrl): string {
    return '<form method="post" class="qa-form"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>' . csrf_field()
         . '<input type="hidden" name="action" value="quick"><input type="hidden" name="act" value="' . h($act) . '"><input type="hidden" name="sid" value="' . $sid . '"><input type="hidden" name="back" value="' . h($backUrl) . '">'
         . $inner . '</form>';
};
?>
<section class="panel" id="list">
  <div class="panel-h">
    <div><h2><?= h($CATS[$cat]) ?> — <?= count($list) ?> student<?= count($list) === 1 ? '' : 's' ?></h2>
      <p><?= $cat === 'all' ? 'Click an open item to work on just that item for that student.' : 'Only what is missing for each student. Fix it right here — it saves to the student’s profile, and the row disappears once done.' ?></p></div>
    <?php if ($cat !== 'all'): ?>
      <div class="seg seg-sm no-print" aria-label="Group by">
        <a class="<?= $groupBy === 'fe' ? 'on' : '' ?>" href="<?= h(pending_url($base, ['by' => 'fe']) . '#list') ?>">By front-end officer</a>
        <a class="<?= $groupBy === 'be' ? 'on' : '' ?>" href="<?= h(pending_url($base, ['by' => 'be']) . '#list') ?>">By back-end officer</a>
      </div>
    <?php endif; ?>
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
    <?php if ($cat === 'docs'): ?><select name="doc" data-autosubmit aria-label="Missing document"><option value="">Any document</option><?php foreach (array_keys($docFreq) as $dn): ?><option <?= $fDoc === $dn ? 'selected' : '' ?>><?= h($dn) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="program_level" data-autosubmit aria-label="Level"><option value="">BS &amp; MS</option><option <?= $fLevel === 'BS' ? 'selected' : '' ?>>BS</option><option <?= $fLevel === 'MS' ? 'selected' : '' ?>>MS</option></select>
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search student…">
    <?php if ($fSid): ?><input type="hidden" name="sid" value="<?= $fSid ?>"><?php endif; ?>
    <button class="btn sm" type="submit">Filter</button>
    <?php if ($cat !== 'all' || $fCountry || $fOfficer || $fLevel || $fDoc || $fq || $fSid): ?><a class="btn sm ghost" href="<?= h(url($base . '/pending.php')) ?>#list">Reset</a><?php endif; ?>
  </form>
  <?php if ($fSid && $list): ?><p class="small"><b>One student:</b> <?= h($list[0]['name']) ?> · <a href="<?= h(pending_url($base, ['sid' => null])) ?>#list">show everyone</a></p><?php endif; ?>

  <?php if (!$list): ?>
    <div class="empty">✓ Nothing pending here.</div>
  <?php elseif ($cat === 'all'): ?>
    <div class="tbl"><table class="pend-all">
      <thead><tr><th>Student</th><th>Officers</th><th>Open items <span class="muted small">(click one to fix it)</span></th><th>Last remark</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): if (++$shown > $MAX_ROWS) break; $pi = $r['_p']; $cn = trim((string)$r['country']); ?>
        <tr>
          <td><a href="<?= h(url($base . '/student.php?id=' . (int)$r['id'])) ?>"><b><?= h($r['name']) ?></b></a><div class="small muted"><?= $flagMap[$cn] ?? '' ?> <?= h($cn ?: '—') ?><?= $r['program_level'] ? ' · ' . h($r['program_level']) : '' ?></div></td>
          <td class="small"><span class="tag-fe">FE</span> <?= h($officerName($r, 'fe') ?: '—') ?><br><span class="tag-be">BE</span> <?= h($officerName($r, 'be') ?: '—') ?></td>
          <td><div class="chip-row">
            <?php foreach ($pi['task_keys'] as $k): ?>
              <a class="pill sm <?= $k === 'docs' || $k === 'remarks' ? 'bad' : 'warn' ?>" href="<?= h(pending_url($base, ['cat' => $k, 'sid' => (int)$r['id'], 'country' => null, 'officer' => null, 'q' => null]) . '#list') ?>" title="<?= h($catHelp[$k]) ?>"><?= h($k === 'docs' ? 'Documents (' . $pi['docs_count'] . ')' : PENDING_TASKS[$k]) ?></a>
            <?php endforeach; ?>
          </div></td>
          <td class="small"><?= last_remarks_cell($r) ?></td>
          <td><a class="btn sm" href="<?= h(url($base . '/student.php?id=' . (int)$r['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <?php $gi = 0; foreach ($groups as $gname => $grows): ?>
    <details class="pend-group" <?= $gi++ === 0 || count($groups) <= 3 || $fOfficer || $fSid ? 'open' : '' ?>>
      <summary><b><?= h($gname) ?></b> <span class="pill sm <?= $catTone[$cat] === 'bad' ? 'bad' : 'warn' ?>"><?= count($grows) ?></span></summary>
      <div class="pend-rows">
      <?php foreach ($grows as $r): if (++$shown > $MAX_ROWS) break; $pi = $r['_p']; $sid = (int)$r['id']; $cn = trim((string)$r['country']);
            $canFix = $cat === 'remarks' || (($u['role'] ?? '') !== 'counselor' && can_edit_student($u, $r)); ?>
        <div class="pend-row" id="s<?= $sid ?>">
          <div class="pr-who">
            <a href="<?= h(url($base . '/student.php?id=' . $sid)) ?>"><b><?= h($r['name']) ?></b></a>
            <div class="small muted"><?= $flagMap[$cn] ?? '' ?> <?= h($cn ?: '—') ?><?= $r['program_level'] ? ' · ' . h($r['program_level']) : '' ?> · <span class="tag-fe">FE</span> <?= h($officerName($r, 'fe') ?: '—') ?> · <span class="tag-be">BE</span> <?= h($officerName($r, 'be') ?: '—') ?></div>
          </div>
          <div class="pr-what">
          <?php if (!$canFix): ?>
            <?php if ($cat === 'docs'): ?><div class="chip-row"><?php foreach ($pi['docs_missing'] as $d): ?><span class="doc-chip"><?= h($d) ?></span><?php endforeach; ?></div><?php endif; ?>
            <span class="small muted">View only — not your student.</span>
          <?php elseif ($cat === 'docs'): ?>
            <?php $listIdx = array_flip($pi['docs_missing_idx'] ?? []); $named = array_values(array_diff($pi['docs_missing'], array_intersect_key(REQUIRED_DOCS, $listIdx))); ?>
            <?= $qa('docs_received', $sid,
                '<div class="chip-row">' . implode('', array_map(fn($i) => '<label class="chk-chip"><input type="checkbox" name="docs[]" value="' . $i . '"><span>' . h(REQUIRED_DOCS[$i]) . '</span></label>', $pi['docs_missing_idx'] ?? []))
                . implode('', array_map(fn($d) => '<span class="doc-chip" title="From the “other documents” note — clear it on the profile when received">' . h($d) . '</span>', $named)) . '</div>'
                . (($pi['docs_missing_idx'] ?? []) ? '<div class="qa-btns"><button class="btn sm primary" type="submit">✓ Mark ticked as received</button><button class="btn sm" type="submit" name="act" value="docs_asked" title="Note that you asked the student for these">Asked student</button>'
                . '<a class="btn sm ghost" href="' . h(url($base . '/student.php?id=' . $sid)) . '#documents">Upload files →</a></div>' : '<a class="btn sm ghost" href="' . h(url($base . '/student.php?id=' . $sid)) . '#documents">Open documents →</a>')) ?>
          <?php elseif ($cat === 'consent'): ?>
            <?= $qa('consent', $sid, '<span class="small">Signed consent form not received.</span><div class="qa-btns"><button class="btn sm primary" type="submit">✓ Signed form received</button><a class="btn sm ghost" href="' . h(url($base . '/student.php?id=' . $sid)) . '#consent">Upload signed copy →</a></div>') ?>
          <?php elseif ($cat === 'first_meeting'): ?>
            <?= $qa('first_meeting', $sid, '<span class="small">Initial meeting not held' . ($r['follow_up_1'] ? ' · follow-up ' . h(fmt_date((string)$r['follow_up_1'])) : '') . '.</span><div class="qa-btns"><button class="btn sm primary" type="submit" name="held" value="1">✓ Meeting held</button><input type="date" name="follow_up" value="' . h((string)($r['follow_up_1'] ?? '')) . '" aria-label="Follow-up date"><button class="btn sm" type="submit">Set follow-up</button></div>') ?>
          <?php elseif ($cat === 'recording'): ?>
            <div class="meet-edit" data-meet>
              <div class="seg seg-sm" role="radiogroup" aria-label="Recording">
                <label><input type="radio" name="meet_mode_<?= $sid ?>" value="link" checked><span>🔗 Link</span></label>
                <label><input type="radio" name="meet_mode_<?= $sid ?>" value="none"><span>✖ Not recorded</span></label>
                <label><input type="radio" name="meet_mode_<?= $sid ?>" value="video"><span>🎬 Video</span></label>
              </div>
              <div class="meet-pane" data-pane="link"><?= $qa('meeting_save_link', $sid, '<div class="qa-btns"><input type="url" name="meeting_link" placeholder="https://… recording link" required><input type="hidden" name="meeting_held" value="1"><button class="btn sm primary" type="submit">Save link</button></div>') ?></div>
              <div class="meet-pane" data-pane="none" hidden><?= $qa('meeting_no_recording', $sid, '<div class="qa-btns"><input name="meeting_note" list="meet-reasons" placeholder="Why was it not recorded?" required><button class="btn sm primary" type="submit">Save reason</button></div>') ?></div>
              <div class="meet-pane" data-pane="video" hidden><?= $qa('meeting_upload_video', $sid, '<div class="qa-btns"><input type="file" name="meeting_video" accept="video/*,.mp4,.mov,.webm,.mkv,.m4a,.mp3" required><input type="hidden" name="meeting_held" value="1"><button class="btn sm primary" type="submit">Upload</button></div>', true) ?></div>
            </div>
          <?php elseif ($cat === 'second_tax'): ?>
            <?= $qa('second_tax', $sid, '<div class="qa-btns">'
                . (($r['second_meeting'] ?? '') !== 'Yes' ? '<label class="chk-chip"><input type="checkbox" name="second_meeting" value="1"><span>2nd processing meeting held</span></label>' : '<span class="pill sm ok">2nd meeting ✓</span>')
                . (($r['tax_meeting'] ?? '') !== 'Yes' ? '<label class="chk-chip"><input type="checkbox" name="tax_meeting" value="1"><span>Tax consultant meeting held</span></label>' : '<span class="pill sm ok">Tax meeting ✓</span>')
                . '<button class="btn sm primary" type="submit">Save</button></div>') ?>
          <?php elseif ($cat === 'shortlisting'): ?>
            <?= $qa('shortlist', $sid, '<div class="qa-btns"><input name="shortlisting" list="uni-list" placeholder="Shortlisted university / universities" value="' . h(stripos((string)$r['shortlisting'], 'pending') !== false ? '' : (string)$r['shortlisting']) . '" required><button class="btn sm primary" type="submit">Save</button></div>') ?>
          <?php elseif ($cat === 'credentials'): ?>
            <?= $qa('credentials', $sid, '<div class="qa-btns">'
                . (trim((string)$r['email']) === '' ? '<input type="email" name="email" placeholder="Student email">' : '<span class="pill sm ok">✓ ' . h($r['email']) . '</span>')
                . (trim((string)$r['portal_id']) === '' ? '<input name="portal_id" placeholder="University portal ID">' : '<span class="pill sm ok">Portal ✓ ' . h($r['portal_id']) . '</span>')
                . '<button class="btn sm primary" type="submit">Save</button></div>') ?>
          <?php elseif ($cat === 'remarks'): ?>
            <div class="small muted">Edited <?= $pi['remark_hours'] !== null ? $pi['remark_hours'] . 'h ago' : '' ?> without a remark.</div>
            <?= $qa('remark', $sid, '<div class="qa-btns"><input name="remark_text" placeholder="What was done / what is next…" required style="flex:1 1 260px"><button class="btn sm primary" type="submit">Add remark</button></div>') ?>
          <?php endif; ?>
          </div>
          <div class="pr-go"><a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $sid)) ?>">Open</a></div>
        </div>
      <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($shown > $MAX_ROWS): ?><p class="small muted">Showing the first <?= $MAX_ROWS ?>. Filter by officer or country to see the rest.</p><?php endif; ?>
  <datalist id="meet-reasons"><option>Meeting held in office (not recorded)</option><option>Internet / Zoom issue</option><option>Student did not allow recording</option><option>Meeting not held yet</option><option>Recording lost / not saved</option></datalist>
  <?php if ($uniList): ?><datalist id="uni-list"><?php foreach ($uniList as $un): ?><option><?= h($un) ?></option><?php endforeach; ?></datalist><?php endif; ?>
</section>
<?php page_end();
