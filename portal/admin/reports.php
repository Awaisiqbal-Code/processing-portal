<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require APP . '/reports.php';

$u = require_role('admin');

// 1. Month and Year Filtering
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$year = (int)get('year');
if ($year < 2020 || $year > $currentYear + 2) {
    $year = $currentYear;
}

$month = (int)get('month');
if ($month < 1 || $month > 12) {
    $month = $currentMonth;
}

$scope = get('scope') === 'active' ? 'active' : 'received';

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

// 2. Query matching student records
if ($scope === 'received') {
    // Records received/created within this month
    $sql = "SELECT s.*, o.name AS officer_name 
            FROM students s 
            LEFT JOIN users o ON o.id = s.officer_id 
            WHERE (s.received_date BETWEEN ? AND ?) 
               OR (s.received_date IS NULL AND substr(s.created_at, 1, 10) BETWEEN ? AND ?)
            ORDER BY s.id DESC";
    $params = [$startDate, $endDate, $startDate, $endDate];
} else {
    // Active pipeline cases during this month
    $sql = "SELECT s.*, o.name AS officer_name 
            FROM students s 
            LEFT JOIN users o ON o.id = s.officer_id 
            WHERE (s.received_date <= ? OR (s.received_date IS NULL AND substr(s.created_at, 1, 10) <= ?))
              AND (s.visa IS NULL OR s.visa = 'Pending' OR substr(s.updated_at, 1, 7) = ?)
            ORDER BY s.id DESC";
    $monthPrefix = sprintf('%04d-%02d', $year, $month);
    $params = [$endDate, $endDate, $monthPrefix];
}

$students = q($sql, $params)->fetchAll();

// 3. Compute KPI Summary & Aggregations
$summary = [
    'total'          => count($students),
    'granted'        => 0,
    'refused'        => 0,
    'pending_visa'   => 0,
    'consent_done'   => 0,
    'checklist_done' => 0,
    'drive_folders'  => 0,
    'stage_counts'   => [],
    'officer_counts' => [],
    'branch_counts'  => [],
];

foreach (STAGES as $st) {
    $summary['stage_counts'][$st[0]] = 0;
}
$summary['stage_counts']['Closed'] = 0;

foreach ($students as $s) {
    if ($s['visa'] === 'Granted') $summary['granted']++;
    elseif ($s['visa'] === 'Refused') $summary['refused']++;
    else $summary['pending_visa']++;

    if (!empty($s['drive_link'])) $summary['drive_folders']++;
    if (($s['consent'] ?? '') === 'Yes') $summary['consent_done']++;
    if (($s['docs_checklist'] ?? '') === 'Yes') $summary['checklist_done']++;

    $ns = next_stage($s);
    if ($ns && isset($summary['stage_counts'][$ns])) {
        $summary['stage_counts'][$ns]++;
    } else {
        $summary['stage_counts']['Closed']++;
    }

    $offName = $s['officer_name'] ?: 'Not assigned';
    $summary['officer_counts'][$offName] = ($summary['officer_counts'][$offName] ?? 0) + 1;

    $brName = $s['branch'] ?: 'Other / Unset';
    $summary['branch_counts'][$brName] = ($summary['branch_counts'][$brName] ?? 0) + 1;
}

// 4. Handle Direct Monthly PDF / CSV Downloads
$download = strtolower(get('download'));
if ($download === 'pdf') {
    $content = generate_monthly_pdf($year, $month, $summary, $students, $scope);
    $filename = sprintf("Monthly_Report_%04d-%02d_%s.pdf", $year, $month, $scope);
    log_action("Downloaded monthly PDF report for {$year}-{$month}");

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)strlen($content));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $content;
    exit;
} elseif ($download === 'csv') {
    $content = generate_monthly_csv($year, $month, $students, $scope);
    $filename = sprintf("Monthly_Data_%04d-%02d_%s.csv", $year, $month, $scope);
    log_action("Downloaded monthly CSV export for {$year}-{$month}");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)strlen($content));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $content;
    exit;
}

// 5. Render Admin Reports View
$monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$selectedMonthName = $monthNames[$month] . ' ' . $year;

page_start('Monthly Reports', $u, PORTALS['admin']['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div>
      <h1>Monthly Reports & Analytics</h1>
      <p>Comprehensive executive reporting, status breakdowns, and monthly PDF/CSV exports.</p>
    </div>
    <div class="row-actions">
      <a class="btn sm primary" href="<?= h(url("admin/reports.php?year={$year}&month={$month}&scope={$scope}&download=pdf")) ?>">
        Download Monthly Report (PDF)
      </a>
      <a class="btn sm" href="<?= h(url("admin/reports.php?year={$year}&month={$month}&scope={$scope}&download=csv")) ?>">
        Download Monthly CSV
      </a>
    </div>
  </div>

  <!-- Filter Selector Form -->
  <form method="get" class="toolbar report-filter-bar">
    <label class="f" style="margin: 0; min-width: 140px;">
      <span class="small muted">Report Month</span>
      <select name="month" data-autosubmit>
        <?php foreach ($monthNames as $num => $name): ?>
          <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="f" style="margin: 0; min-width: 110px;">
      <span class="small muted">Year</span>
      <select name="year" data-autosubmit>
        <?php for ($y = $currentYear + 1; $y >= 2023; $y--): ?>
          <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </label>

    <label class="f" style="margin: 0; min-width: 220px;">
      <span class="small muted">Reporting Scope</span>
      <select name="scope" data-autosubmit>
        <option value="received" <?= $scope === 'received' ? 'selected' : '' ?>>Applications Received In Month</option>
        <option value="active" <?= $scope === 'active' ? 'selected' : '' ?>>Active Pipeline Cases In Month</option>
      </select>
    </label>

    <div style="align-self: flex-end;">
      <button class="btn sm" type="submit">Filter Report</button>
    </div>
  </form>

  <!-- Executive Summary KPI Cards -->
  <div class="report-kpi-grid">
    <div class="report-kpi-card kpi-blue">
      <div class="report-kpi-title">Total Records</div>
      <div class="report-kpi-val"><?= $summary['total'] ?></div>
      <div class="report-kpi-sub"><?= $scope === 'received' ? 'Received in ' . h($selectedMonthName) : 'Active in ' . h($selectedMonthName) ?></div>
    </div>

    <div class="report-kpi-card kpi-green">
      <div class="report-kpi-title">Visa Decided</div>
      <div class="report-kpi-val val-green"><?= $summary['granted'] ?> <span class="small muted" style="font-weight:normal;">(<?= $summary['refused'] ?> refused)</span></div>
      <div class="report-kpi-sub"><?= $summary['pending_visa'] ?> pending in pipeline</div>
    </div>

    <div class="report-kpi-card kpi-cyan">
      <div class="report-kpi-title">Student Folders</div>
      <div class="report-kpi-val val-cyan"><?= $summary['drive_folders'] ?></div>
      <div class="report-kpi-sub">Drive folders linked</div>
    </div>

    <div class="report-kpi-card kpi-purple">
      <div class="report-kpi-title">Docs Checklist Done</div>
      <div class="report-kpi-val val-purple"><?= $summary['checklist_done'] ?></div>
      <div class="report-kpi-sub"><?= $summary['consent_done'] ?> consent forms signed</div>
    </div>
  </div>

  <!-- Breakdown Sections -->
  <div class="grid2" style="margin-bottom: 1.75rem;">
    <!-- By Progress Stage -->
    <div class="report-subpanel">
      <h3 style="font-size: 0.95rem; margin-top: 0; margin-bottom: 0.75rem;">Breakdown by Progress Stage</h3>
      <div class="tbl"><table>
        <thead><tr><th>Stage Milestone</th><th>Count</th><th>Ratio</th></tr></thead>
        <tbody>
        <?php foreach (STAGES as $st): $c = $summary['stage_counts'][$st[0]] ?? 0; $pct = round(($c / max(1, $summary['total'])) * 100); ?>
          <tr>
            <td><?= h($st[0]) ?></td>
            <td class="num"><b><?= $c ?></b></td>
            <td><?= $pct ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php $closed = $summary['stage_counts']['Closed'] ?? 0; ?>
        <tr class="tr-summary">
          <td>Closed / File Complete</td>
          <td class="num"><?= $closed ?></td>
          <td><?= round(($closed / max(1, $summary['total'])) * 100) ?>%</td>
        </tr>
        </tbody>
      </table></div>
    </div>

    <!-- By Officer & Branch -->
    <div class="report-subpanel">
      <h3 style="font-size: 0.95rem; margin-top: 0; margin-bottom: 0.75rem;">Officer & Branch Workload</h3>
      <div class="tbl"><table>
        <thead><tr><th>Processing Officer</th><th>Cases</th><th>Branch</th><th>Cases</th></tr></thead>
        <tbody>
        <?php 
          $offKeys = array_keys($summary['officer_counts']);
          $brKeys = array_keys($summary['branch_counts']);
          $maxRows = max(count($offKeys), count($brKeys));
          for ($i = 0; $i < $maxRows; $i++):
            $oK = $offKeys[$i] ?? '';
            $oV = $oK !== '' ? $summary['officer_counts'][$oK] : '';
            $bK = $brKeys[$i] ?? '';
            $bV = $bK !== '' ? $summary['branch_counts'][$bK] : '';
        ?>
          <tr>
            <td><?= h($oK) ?></td>
            <td class="num"><?= $oV ?></td>
            <td><?= h($bK) ?></td>
            <td class="num"><?= $bV ?></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- Student Records Table for the Month -->
  <div class="panel-h" style="margin-top: 1rem;">
    <h2>Student Records for <?= h($selectedMonthName) ?> (<?= count($students) ?>)</h2>
  </div>

  <?php if (!$students): ?>
    <div class="empty">No student records match <?= h($selectedMonthName) ?> under scope "<?= h($scope) ?>". Try selecting another month or switching to "Active Pipeline Cases".</div>
  <?php else: ?>
  <div class="tbl"><table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Student</th>
        <th>Counselor</th>
        <th>Officer</th>
        <th>Received</th>
        <th>Progress</th>
        <th>Next step</th>
        <th>Visa</th>
        <th>Docs, Meeting & Reports</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($students as $s): 
      $ns = next_stage($s); 
      $dl = safe_link($s['drive_link'] ?? '');
      $hasMeeting = student_has_meeting($s);
      $ml = url('meeting.php?id=' . (int)$s['id']);
    ?>
      <tr>
        <td><?= (int)$s['id'] ?></td>
        <td><a href="<?= h(url('admin/student.php?id=' . $s['id'])) ?>"><b><?= h($s['name']) ?></b></a></td>
        <td><?= h($s['counselor'] ?: '-') ?></td>
        <td><?= h($s['officer_name'] ?: 'Not assigned') ?></td>
        <td class="num"><?= h(fmt_date($s['received_date'])) ?></td>
        <td><a href="<?= h(url('admin/progress.php?id=' . $s['id'])) ?>" title="View student progress" style="text-decoration:none; display:inline-block;"><?= progress_dots($s) ?></a></td>
        <td><?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?></td>
        <td><?php if ($s['visa']): ?><span class="pill <?= pill_class($s['visa']) ?>"><?= h($s['visa']) ?></span><?php endif; ?></td>
        <td>
          <div style="display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap;">
            <?php if ($dl): ?>
              <a class="btn sm ghost" href="<?= h($dl) ?>" target="_blank" rel="noopener noreferrer" title="Open Student Documents Folder in Google Drive">📁 Docs</a>
            <?php endif; ?>
            <?php if ($hasMeeting): ?>
              <a class="btn sm ghost" href="<?= h($ml) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video / Zoom">🎥 Meeting</a>
            <?php else: ?>
              <span class="pill muted" style="font-size:0.72rem; padding:0.2rem 0.45rem; background:rgba(100,116,139,0.12); color:#64748b;" title="Meeting video not available">Not Available</span>
            <?php endif; ?>
            <a class="btn sm" href="<?= h(url('admin/progress.php?id=' . $s['id'])) ?>" title="Live Student Progress">Progress</a>
            <a class="btn sm primary" href="<?= h(url('admin/report.php?id=' . $s['id'] . '&format=pdf')) ?>" title="Download PDF">⬇ PDF</a>
            <a class="btn sm ghost" href="<?= h(url('admin/report.php?id=' . $s['id'] . '&format=csv')) ?>" title="Download CSV">CSV</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php
page_end();
