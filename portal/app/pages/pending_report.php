<?php
declare(strict_types=1);
// Officer-wise Pending Reports: what each processing officer completed in a period and what is still pending,
// student by student. Shareable (copy / WhatsApp), printable (Save as PDF) and downloadable (Excel).
// Expects $u. Admin / PMS Lead: any officer. Processing staff: only their own report.

require_once APP . '/reporting_service.php';

$admin = is_admin_role($u['role']);
$base = $admin ? 'admin' : 'officer';
$isLead = in_array($u['role'], ['super_admin', 'admin', 'pms_lead'], true);
if ($u['role'] === 'counselor') {
    flash('Pending reports are for processing officers.', 'warn');
    redirect('officer/index.php');
}

// ---------- period ----------
$preset = get('preset');
$today = date('Y-m-d');
[$from, $to] = match ($preset) {
    'today' => [$today, $today],
    'week'  => [date('Y-m-d', strtotime('monday this week')), $today],
    'last7' => [date('Y-m-d', strtotime('-6 days')), $today],
    'month' => [date('Y-m-01'), $today],
    'last30'=> [date('Y-m-d', strtotime('-29 days')), $today],
    default => [get('from') ?: date('Y-m-01'), get('to') ?: $today],
};
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = $today;
if ($from > $to) [$from, $to] = [$to, $from];
$fromTs = $from . ' 00:00:00';
$toTs = $to . ' 23:59:59';

// ---------- officers ----------
$officers = q("SELECT id, name, username, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('pms_lead','processing_staff','officer') AND active = 1 ORDER BY CASE WHEN department = 'frontend' THEN 0 ELSE 1 END, name COLLATE NOCASE")->fetchAll();
$officerById = array_column($officers, null, 'id');
$oid = $isLead ? (int)get('officer') : (int)$u['id'];
if ($oid && !isset($officerById[$oid])) $oid = 0;

// ---------- students and pending evaluation ----------
$students = q("SELECT s.*, o.name AS officer_name, fo.name AS fe_user_name,
                      (SELECT MAX(sr.created_at) FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL) AS last_remark_created_at,
                      (SELECT sr.remark_text FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_text,
                      (SELECT sr.author_name FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_name
               FROM students s
               LEFT JOIN users o ON o.id = s.officer_id
               LEFT JOIN users fo ON fo.id = s.frontend_officer_id
               WHERE s.student_status = 'active'" . ($oid ? ' AND (s.officer_id = ? OR s.frontend_officer_id = ?)' : '') . "
               ORDER BY s.id", $oid ? [$oid, $oid] : [])->fetchAll();
$ids = array_map(fn($r) => (int)$r['id'], $students);
$docStats = load_doc_stats($ids);
$appCounts = [];
foreach (q('SELECT student_id, COUNT(*) c FROM student_applications GROUP BY student_id') as $ar) $appCounts[(int)$ar['student_id']] = (int)$ar['c'];

$evaluated = [];
foreach ($students as $r) {
    $sid = (int)$r['id'];
    $p = student_pending($r, $docStats[$sid] ?? [], $appCounts[$sid] ?? 0);
    $lastTouch = $r['last_remark_created_at'] ?: ($r['last_edited_at'] ?: $r['created_at']);
    $r['_p'] = $p;
    $r['_idle_days'] = $lastTouch ? max(0, (int)floor((time() - (strtotime((string)$lastTouch) ?: time())) / 86400)) : null;
    $r['_pending_hours'] = !empty($r['pending_since']) ? max(0, (int)floor((time() - (strtotime((string)$r['pending_since']) ?: time())) / 3600)) : null;
    $evaluated[] = $r;
}

/** Work done by one officer in the period (from the audit tables). */
function officer_work_done(int $officerId, string $fromTs, string $toTs): array {
    $remarks = q("SELECT sr.student_id, s.name AS student_name, sr.remark_text AS detail, sr.created_at AS at, 'Remark' AS kind
                  FROM student_remarks sr JOIN students s ON s.id = sr.student_id
                  WHERE sr.author_id = ? AND sr.deleted_at IS NULL AND sr.created_at BETWEEN ? AND ?", [$officerId, $fromTs, $toTs])->fetchAll();
    $stages = q("SELECT h.student_id, s.name AS student_name, (h.stage_key || ': ' || COALESCE(h.old_status,'—') || ' → ' || h.new_status) AS detail, h.changed_at AS at, 'Journey step' AS kind
                 FROM student_stage_history h JOIN students s ON s.id = h.student_id
                 WHERE h.changed_by = ? AND h.changed_at BETWEEN ? AND ?", [$officerId, $fromTs, $toTs])->fetchAll();
    foreach ($stages as &$sg) {
        $k = strstr((string)$sg['detail'], ':', true);
        if ($k !== false && isset(JOURNEY_STAGES[$k])) $sg['detail'] = JOURNEY_STAGES[$k][0] . substr((string)$sg['detail'], strlen($k));
    }
    unset($sg);
    $docs = q("SELECT d.student_id, s.name AS student_name, d.doc_index, d.status AS detail, d.updated_at AS at, 'Document' AS kind
               FROM student_documents d JOIN students s ON s.id = d.student_id
               WHERE (d.updated_by = ? OR d.uploaded_by = ?) AND d.updated_at BETWEEN ? AND ?", [$officerId, $officerId, $fromTs, $toTs])->fetchAll();
    foreach ($docs as &$dc) {
        $dc['detail'] = (DOCS[(int)$dc['doc_index']] ?? ('Document #' . ((int)$dc['doc_index'] + 1))) . ' → ' . $dc['detail'];
    }
    unset($dc);
    $comms = q("SELECT c.student_id, s.name AS student_name, (c.channel || ': ' || c.summary) AS detail, c.created_at AS at, 'Communication' AS kind
                FROM student_communications c JOIN students s ON s.id = c.student_id
                WHERE c.created_by = ? AND c.created_at BETWEEN ? AND ?", [$officerId, $fromTs, $toTs])->fetchAll();
    $edits = q("SELECT a.student_id, s.name AS student_name, a.action AS detail, a.created_at AS at, 'Profile update' AS kind
                FROM activity_log a JOIN students s ON s.id = a.student_id
                WHERE a.user_id = ? AND a.student_id IS NOT NULL AND a.created_at BETWEEN ? AND ?
                  AND a.action NOT LIKE 'Viewed%'", [$officerId, $fromTs, $toTs])->fetchAll();
    $created = q("SELECT s.id AS student_id, s.name AS student_name, ('Registered (' || COALESCE(s.country,'') || ')') AS detail, s.created_at AS at, 'New student' AS kind
                  FROM students s WHERE s.entered_by = ? AND s.created_at BETWEEN ? AND ?", [$officerId, $fromTs, $toTs])->fetchAll();
    $all = array_merge($remarks, $stages, $docs, $comms, $edits, $created);
    usort($all, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    $counts = ['Remark' => count($remarks), 'Journey step' => count($stages), 'Document' => count($docs), 'Communication' => count($comms), 'Profile update' => count($edits), 'New student' => count($created)];
    $touched = count(array_unique(array_map(fn($x) => (int)$x['student_id'], $all)));
    return ['items' => $all, 'counts' => $counts, 'touched' => $touched];
}

/** Aggregate pending for a set of evaluated students. */
function summarize_pending(array $rows): array {
    $sum = ['assigned' => count($rows), 'clear' => 0, 'with_pending' => 0, 'items' => 0, 'overdue' => 0, 'stale' => 0,
            'consent' => 0, 'docs' => 0, 'meetings' => 0, 'second_tax' => 0, 'shortlisting' => 0, 'credentials' => 0];
    foreach ($rows as $r) {
        $p = $r['_p'];
        if ($p['any']) { $sum['with_pending']++; $sum['items'] += count($p['items']); } else { $sum['clear']++; }
        if (($r['_pending_hours'] ?? 0) >= 48) $sum['overdue']++;
        if (($r['_idle_days'] ?? 0) >= 7) $sum['stale']++;
        foreach (['consent', 'docs', 'meetings', 'second_tax', 'shortlisting', 'credentials'] as $k) if ($p[$k]) $sum[$k]++;
    }
    return $sum;
}

function pending_share_text(array $officer, array $sum, array $work, array $rows, string $from, string $to, int $maxStudents = 25): string {
    $lines = [];
    $lines[] = "*Pending Report — {$officer['name']}*";
    $lines[] = 'Period: ' . fmt_date($from) . ' to ' . fmt_date($to);
    $lines[] = '';
    $lines[] = "✅ Done in this period: " . array_sum($work['counts']) . ' updates on ' . $work['touched'] . ' students'
             . " (remarks {$work['counts']['Remark']}, documents {$work['counts']['Document']}, journey steps {$work['counts']['Journey step']}, calls/chats {$work['counts']['Communication']})";
    $lines[] = "📂 Assigned: {$sum['assigned']} · Clear: {$sum['clear']} · With pending: {$sum['with_pending']}";
    if ($sum['overdue']) $lines[] = "⏰ 48h remark overdue: {$sum['overdue']}";
    if ($sum['stale']) $lines[] = "💤 Not touched for 7+ days: {$sum['stale']}";
    $lines[] = "Pending by type — Docs: {$sum['docs']}, Consent: {$sum['consent']}, Meetings: {$sum['meetings']}, Shortlisting: {$sum['shortlisting']}, Portal/Email: {$sum['credentials']}";
    $lines[] = '';
    $lines[] = '*Students still pending:*';
    $pending = array_values(array_filter($rows, fn($r) => $r['_p']['any']));
    usort($pending, fn($a, $b) => [($b['_pending_hours'] ?? -1), count($b['_p']['items'])] <=> [($a['_pending_hours'] ?? -1), count($a['_p']['items'])]);
    foreach (array_slice($pending, 0, $maxStudents) as $i => $r) {
        $lines[] = ($i + 1) . '. ' . $r['name'] . ' (' . ($r['country'] ?: '—') . ') — ' . implode(', ', $r['_p']['items'])
                 . ($r['_p']['docs_missing'] ? ' | Documents: ' . implode(', ', $r['_p']['docs_missing']) : '');
    }
    if (count($pending) > $maxStudents) $lines[] = '…and ' . (count($pending) - $maxStudents) . ' more (see full report).';
    $lines[] = '';
    $lines[] = 'Please update these files and add a remark on each. — ScholarshipHive Edutancy';
    return implode("\n", $lines);
}

// ---------- per-officer rows ----------
$byOfficer = [];
foreach ($officers as $o) $byOfficer[(int)$o['id']] = [];
foreach ($evaluated as $r) {
    foreach (array_unique(array_filter([(int)($r['officer_id'] ?? 0), (int)($r['frontend_officer_id'] ?? 0)])) as $x) {
        if (isset($byOfficer[$x])) $byOfficer[$x][] = $r;
    }
}

// ---------- PDF download (same cards as on screen) ----------
if (get('download') === 'pdf' && $oid) {
    require_once APP . '/pending_report_pdf.php';
    $o = $officerById[$oid];
    $bytes = generate_pending_report_pdf($o, summarize_pending($byOfficer[$oid]), officer_work_done($oid, $fromTs, $toTs), $byOfficer[$oid], $from, $to, $u['name']);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Pending_Report_' . preg_replace('/\W+/', '_', $o['name']) . '_' . $from . '_to_' . $to . '.pdf"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

// ---------- Excel download ----------
if (get('download') === 'xlsx') {
    if ($oid) {
        $o = $officerById[$oid];
        $rowsX = [];
        foreach ($byOfficer[$oid] as $r) {
            $p = $r['_p'];
            $rowsX[] = [(int)($r['sheet_sr'] ?: $r['id']), $r['name'], $r['country'] ?: '', $r['program_level'] ?: '',
                $r['fe_user_name'] ?: ($r['frontend_officer_name'] ?: ''), $r['officer_name'] ?: '',
                $p['any'] ? 'Pending' : 'Clear', implode(', ', $p['items']), implode(', ', $p['docs_missing']), $p['docs_in_hand'] . ' of ' . $p['docs_required'],
                $r['_pending_hours'] !== null ? $r['_pending_hours'] . 'h' : '', $r['_idle_days'] !== null ? $r['_idle_days'] : '', (string)($r['remarks'] ?? '')];
        }
        stream_xlsx_download('Pending ' . mb_substr($o['name'], 0, 20),
            ['SR#', 'Student', 'Country', 'Level', 'Front-End', 'Back-End', 'State', 'Open items', 'Documents pending', 'Required documents in hand', '48h timer', 'Days since last update', 'Last remark'],
            $rowsX, 'Pending_Report_' . preg_replace('/\W+/', '_', $o['name']) . '_' . $from . '_to_' . $to . '.xlsx');
    }
    $rowsX = [];
    foreach ($officers as $o) {
        $sum = summarize_pending($byOfficer[(int)$o['id']]);
        $work = officer_work_done((int)$o['id'], $fromTs, $toTs);
        $rowsX[] = [$o['name'], $o['department'] === 'frontend' ? 'Front-End' : 'Back-End', $sum['assigned'], $sum['clear'], $sum['with_pending'], $sum['items'], $sum['overdue'], $sum['stale'],
            $sum['docs'], $sum['consent'], $sum['meetings'], $sum['shortlisting'], $sum['credentials'], array_sum($work['counts']), $work['touched']];
    }
    stream_xlsx_download('Officer Pending Summary',
        ['Officer', 'Team', 'Assigned', 'Clear', 'With pending', 'Pending items', '48h overdue', 'Untouched 7+ days', 'Docs', 'Consent', 'Meetings', 'Shortlisting', 'Portal/Email', 'Updates done in period', 'Students worked on'],
        $rowsX, 'Officer_Pending_Summary_' . $from . '_to_' . $to . '.xlsx');
}

$qsBase = fn(array $over = []) => '?' . http_build_query(array_merge(['officer' => $oid ?: null, 'from' => $from, 'to' => $to], $over));
page_start($oid ? 'Pending Report · ' . $officerById[$oid]['name'] : 'Officer Pending Reports', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel report-head">
  <div class="panel-h">
    <div>
      <div class="eyebrow">Officer Pending Reports</div>
      <h1><?= $oid ? 'Pending report · ' . h($officerById[$oid]['name']) : 'Pending work by processing officer' ?></h1>
      <p>What was <b>done</b> in the selected period and what is still <b>pending</b>, student by student. Share it on WhatsApp, print it as PDF, or download Excel.</p>
    </div>
    <div class="row-actions no-print">
      <?php if ($oid && $isLead): ?><a class="btn sm ghost" href="<?= h($qsBase(['officer' => null])) ?>">← All officers</a><?php endif; ?>
      <a class="btn sm" href="<?= h($qsBase(['download' => 'xlsx'])) ?>">⬇ Excel</a>
      <button type="button" class="btn sm" onclick="window.print()">🖨 Print / Save PDF</button>
    </div>
  </div>
  <form method="get" class="toolbar no-print" style="margin:0;">
    <?php if ($isLead): ?>
      <select name="officer" data-autosubmit aria-label="Officer">
        <option value="">All officers (summary)</option>
        <optgroup label="Front-End">
          <?php foreach ($officers as $o): if ($o['department'] !== 'frontend') continue; ?><option value="<?= (int)$o['id'] ?>" <?= $oid === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Back-End">
          <?php foreach ($officers as $o): if ($o['department'] === 'frontend') continue; ?><option value="<?= (int)$o['id'] ?>" <?= $oid === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?><?= $o['role'] === 'pms_lead' ? ' (PMS Lead)' : '' ?></option><?php endforeach; ?>
        </optgroup>
      </select>
    <?php endif; ?>
    <label class="small muted">From <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="small muted">To <input type="date" name="to" value="<?= h($to) ?>" max="<?= h($today) ?>"></label>
    <button class="btn sm" type="submit">Apply</button>
    <span class="preset-links">
      <?php foreach (['today' => 'Today', 'week' => 'This week', 'last7' => 'Last 7 days', 'month' => 'This month', 'last30' => 'Last 30 days'] as $pk => $pl): ?>
        <a class="btn sm ghost <?= $preset === $pk ? 'on' : '' ?>" href="<?= h('?' . http_build_query(['officer' => $oid ?: null, 'preset' => $pk])) ?>"><?= $pl ?></a>
      <?php endforeach; ?>
    </span>
  </form>
  <div class="print-only small">Period: <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?> · Generated <?= h(date('d/m/Y H:i')) ?> by <?= h($u['name']) ?></div>
</section>

<?php if (!$oid): ?>
  <?php
    $cards = [];
    foreach ($officers as $o) {
        $rowsO = $byOfficer[(int)$o['id']];
        if (!$rowsO && $o['role'] === 'pms_lead') continue;
        $cards[] = ['o' => $o, 'sum' => summarize_pending($rowsO), 'work' => officer_work_done((int)$o['id'], $fromTs, $toTs), 'rows' => $rowsO];
    }
    $team = ['assigned' => 0, 'with_pending' => 0, 'items' => 0, 'overdue' => 0, 'done' => 0];
    foreach ($cards as $c) { $team['assigned'] += $c['sum']['assigned']; $team['with_pending'] += $c['sum']['with_pending']; $team['items'] += $c['sum']['items']; $team['overdue'] += $c['sum']['overdue']; $team['done'] += array_sum($c['work']['counts']); }
  ?>
  <div class="kpis">
    <div class="kpi"><div class="l">Officers</div><div class="v"><?= count($cards) ?></div><div class="s">Front-end &amp; back-end</div></div>
    <div class="kpi"><div class="l">Assignments</div><div class="v"><?= $team['assigned'] ?></div><div class="s">Active students (FE + BE counted separately)</div></div>
    <div class="kpi"><div class="l">With pending</div><div class="v bad-t"><?= $team['with_pending'] ?></div><div class="s"><?= $team['items'] ?> open items</div></div>
    <div class="kpi"><div class="l">48h overdue</div><div class="v"><?= $team['overdue'] ?></div><div class="s">Remark not added in time</div></div>
    <div class="kpi"><div class="l">Done in period</div><div class="v" style="color:var(--ok)"><?= $team['done'] ?></div><div class="s">Remarks, documents, steps, calls</div></div>
  </div>
  <section class="panel">
    <div class="panel-h"><div><h2>Officer comparison</h2><p>Click an officer to open their full report (done vs remaining) and share it with them.</p></div></div>
    <div class="tbl"><table>
      <thead><tr><th>Officer</th><th class="num">Assigned</th><th class="num">Clear</th><th class="num">With pending</th><th>Workload</th><th class="num">Docs</th><th class="num">Consent</th><th class="num">Meetings</th><th class="num">Shortlist</th><th class="num">48h overdue</th><th class="num">Done in period</th><th class="no-print">Report</th></tr></thead>
      <tbody>
        <?php foreach ($cards as $c): $o = $c['o']; $sm = $c['sum']; $pct = $sm['assigned'] ? round($sm['with_pending'] / $sm['assigned'] * 100) : 0; ?>
          <tr>
            <td><a href="<?= h($qsBase(['officer' => (int)$o['id']])) ?>"><b><?= h($o['name']) ?></b></a> <span class="<?= $o['department'] === 'frontend' ? 'tag-fe' : 'tag-be' ?>"><?= $o['department'] === 'frontend' ? 'FE' : 'BE' ?></span></td>
            <td class="num"><?= $sm['assigned'] ?></td>
            <td class="num" style="color:var(--ok)"><?= $sm['clear'] ?></td>
            <td class="num bad-t"><b><?= $sm['with_pending'] ?></b></td>
            <td><span class="meter" title="<?= $pct ?>% of assigned students have something pending"><i style="width:<?= $pct ?>%"></i></span> <span class="small muted"><?= $pct ?>%</span></td>
            <td class="num"><?= $sm['docs'] ?></td>
            <td class="num"><?= $sm['consent'] ?></td>
            <td class="num"><?= $sm['meetings'] ?></td>
            <td class="num"><?= $sm['shortlisting'] ?></td>
            <td class="num <?= $sm['overdue'] ? 'bad-t' : '' ?>"><?= $sm['overdue'] ?></td>
            <td class="num" style="color:var(--ok)"><b><?= array_sum($c['work']['counts']) ?></b> <span class="small muted">/ <?= $c['work']['touched'] ?> st.</span></td>
            <td class="no-print"><div class="row-actions" style="flex-wrap:nowrap;">
              <a class="btn sm primary" href="<?= h($qsBase(['officer' => (int)$o['id']])) ?>#share">View report</a>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php else:
    $o = $officerById[$oid];
    $rowsO = $byOfficer[$oid];
    $sum = summarize_pending($rowsO);
    $work = officer_work_done($oid, $fromTs, $toTs);
    $share = pending_share_text($o, $sum, $work, $rowsO, $from, $to);
    $pendingRows = array_values(array_filter($rowsO, fn($r) => $r['_p']['any']));
    usort($pendingRows, fn($a, $b) => [($b['_pending_hours'] ?? -1), count($b['_p']['items'])] <=> [($a['_pending_hours'] ?? -1), count($a['_p']['items'])]);
    $countries = array_values(array_unique(array_filter(array_map(fn($r) => $r['country'] ?? '', $rowsO))));
?>
  <section class="report-officer">
    <div class="ro-id">
      <span class="avatar lg"><?= h(user_initials($o['name'])) ?></span>
      <div>
        <h2><?= h($o['name']) ?></h2>
        <div class="small muted"><?= h(role_label($o['role'])) ?> · <?= $o['department'] === 'frontend' ? 'Front-End' : 'Back-End' ?> · <?= h(implode(', ', $countries) ?: 'No countries') ?></div>
        <div class="small muted">Period <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?></div>
      </div>
    </div>
    <div class="row-actions no-print"><a class="btn sm primary" href="#share">Report to share ↓</a></div>
  </section>

  <div class="kpis">
    <div class="kpi"><div class="l">Assigned</div><div class="v"><?= $sum['assigned'] ?></div><div class="s">Active students</div></div>
    <div class="kpi"><div class="l">Clear</div><div class="v" style="color:var(--ok)"><?= $sum['clear'] ?></div><div class="s">Nothing pending</div></div>
    <div class="kpi"><div class="l">With pending</div><div class="v bad-t"><?= $sum['with_pending'] ?></div><div class="s"><?= $sum['items'] ?> open items</div></div>
    <div class="kpi"><div class="l">48h overdue</div><div class="v <?= $sum['overdue'] ? 'bad-t' : '' ?>"><?= $sum['overdue'] ?></div><div class="s">Remark not added</div></div>
    <div class="kpi"><div class="l">Untouched 7+ days</div><div class="v"><?= $sum['stale'] ?></div><div class="s">No remark / edit</div></div>
    <div class="kpi"><div class="l">Done in period</div><div class="v" style="color:var(--ok)"><?= array_sum($work['counts']) ?></div><div class="s">on <?= $work['touched'] ?> students</div></div>
  </div>

  <?php $oContact = (function () use ($oid) { try { return q('SELECT phone, email FROM users WHERE id = ?', [$oid])->fetch() ?: []; } catch (Throwable) { return []; } })();
        $waShare = wa_link($oContact['phone'] ?? null, $share) ?: 'https://wa.me/?text=' . rawurlencode($share); ?>
  <section class="panel share-panel" id="share">
    <div class="panel-h">
      <div><h2>Students still pending — <?= count($pendingRows) ?></h2><p>One card per student, showing only what is missing. Download the same cards as PDF, or copy the text for WhatsApp.</p></div>
      <div class="row-actions no-print">
        <a class="btn sm primary" href="<?= h($qsBase(['download' => 'pdf'])) ?>">⬇ Download PDF</a>
        <button type="button" class="btn sm" data-copy-text="<?= h($share) ?>">📋 Copy text</button>
        <a class="btn sm" target="_blank" rel="noopener noreferrer" href="<?= h($waShare) ?>">💬 WhatsApp<?= !empty($oContact['phone']) ? ' ' . h($o['name']) : '' ?></a>
        <a class="btn sm" href="mailto:<?= h($oContact['email'] ?? '') ?>?subject=<?= rawurlencode('Pending report — ' . $o['name']) ?>&body=<?= rawurlencode($share) ?>">✉️ Email</a>
        <a class="btn sm ghost" href="<?= h($qsBase(['download' => 'xlsx'])) ?>">⬇ Excel</a>
      </div>
    </div>
    <?php if (!$pendingRows): ?><div class="empty">✓ Nothing pending — every assigned student is clear.</div><?php else: ?>
    <div class="rcards">
      <?php foreach ($pendingRows as $i => $r): $p = $r['_p']; $hrs = $r['_pending_hours']; ?>
        <article class="rcard <?= $hrs !== null && $hrs >= 48 ? 'is-late' : ($p['docs'] ? 'is-docs' : '') ?>">
          <header>
            <span class="rc-n"><?= $i + 1 ?></span>
            <div class="rc-who"><a href="<?= h(url($base . '/student.php?id=' . (int)$r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
              <div class="small muted"><?= country_flag($r['country'] ?? '') ?> <?= h($r['country'] ?: '—') ?><?= $r['program_level'] ? ' · ' . h($r['program_level']) : '' ?> · <span class="tag-fe">FE</span> <?= h(($r['fe_user_name'] ?: $r['frontend_officer_name']) ?: '—') ?> · <span class="tag-be">BE</span> <?= h($r['officer_name'] ?: '—') ?></div></div>
            <?php if ($hrs !== null): ?><span class="pill sm <?= $hrs >= 48 ? 'bad' : 'warn' ?>" title="Edited without a remark">⏳ <?= $hrs ?>h</span><?php endif; ?>
          </header>
          <?php $items = array_values(array_filter($p['task_keys'], fn($k) => $k !== 'docs')); if ($items): ?>
            <div class="chip-row"><?php foreach ($items as $k): ?><a class="pill sm warn" href="<?= h(url($base . '/pending.php?cat=' . $k . '&sid=' . (int)$r['id'])) ?>#list"><?= h(PENDING_TASKS[$k]) ?></a><?php endforeach; ?></div>
          <?php endif; ?>
          <?php if ($p['docs']): ?>
            <div class="rc-docs"><span class="small bad-t"><b>Documents (<?= $p['docs_count'] ?>)</b></span>
              <div class="chip-row"><?php foreach ($p['docs_missing'] as $d): ?><span class="doc-chip"><?= h($d) ?></span><?php endforeach; ?></div></div>
          <?php endif; ?>
          <?php if (!empty($r['last_remark_text'])): ?><p class="rc-remark small">“<?= h(mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['last_remark_text']), 0, 160, '…')) ?>”<?= $r['last_remark_author_name'] ? ' <span class="muted">— ' . h($r['last_remark_author_name']) . '</span>' : '' ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <details class="no-print" style="margin-top:12px"><summary class="small">Show the WhatsApp text</summary><pre class="share-preview"><?= h($share) ?></pre></details>
  </section>

  <div class="grid2">
    <section class="panel">
      <div class="panel-h"><div><h2>✅ Done in this period</h2><p>Remarks, documents, journey steps, calls and profile updates by <?= h($o['name']) ?>.</p></div></div>
      <div class="done-chips">
        <?php foreach ($work['counts'] as $k => $v): ?><span class="pill <?= $v ? 'ok' : '' ?>"><?= h($k) ?>: <b><?= $v ?></b></span><?php endforeach; ?>
      </div>
      <?php if (!$work['items']): ?>
        <div class="empty">No recorded work in this period.</div>
      <?php else: ?>
        <ul class="timeline done-list">
          <?php foreach (array_slice($work['items'], 0, 80) as $it): ?>
            <li><span class="muted small"><?= h(fmt_date(substr((string)$it['at'], 0, 10))) ?> <?= h(substr((string)$it['at'], 11, 5)) ?></span>
              <span class="pill sm"><?= h($it['kind']) ?></span>
              <a href="<?= h(url($base . '/student.php?id=' . (int)$it['student_id'])) ?>"><b><?= h($it['student_name']) ?></b></a>
              <div class="small"><?= h(mb_strimwidth((string)$it['detail'], 0, 140, '…')) ?></div></li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($work['items']) > 80): ?><p class="small muted">Showing latest 80 of <?= count($work['items']) ?>. Download Excel for everything pending.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-h"><div><h2>⏳ Pending by type</h2><p>How many of <?= h($o['name']) ?>'s students are waiting on each item.</p></div></div>
      <div class="bars">
        <?php
          $types = ['docs' => 'Documents', 'consent' => 'Consent form', 'meetings' => '1st meeting / recording', 'second_tax' => 'Tax consultant meeting', 'shortlisting' => 'University shortlisting', 'credentials' => 'Email / portal account', 'overdue' => '48h remark overdue', 'stale' => 'Untouched 7+ days'];
          $mx = max(1, $sum['assigned']);
          foreach ($types as $tk => $tl): ?>
          <div class="bar"><span><?= h($tl) ?></span><span class="t"><i style="width:<?= round($sum[$tk] / $mx * 100, 1) ?>%"></i></span><span class="n num"><?= $sum[$tk] ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

<?php endif; ?>
<?php page_end();
