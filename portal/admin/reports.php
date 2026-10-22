<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP . '/layout.php';
require_once APP . '/reports.php';
require_once APP . '/reporting_service.php';

$u = require_role('admin');

$viewTab = get('tab') ?: 'operations';
$today = date('Y-m-d');

if ($viewTab === 'operations') {
    // -------------------------------------------------------------
    // Operations & Performance Report (Phase 5)
    // -------------------------------------------------------------
    $preset = get('preset') ?: '';
    if ($preset === 'today') {
        $startDate = $today;
        $endDate = $today;
    } elseif ($preset === 'week') {
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = $today;
    } elseif ($preset === 'month') {
        $startDate = date('Y-m-01');
        $endDate = $today;
    } elseif ($preset === 'all') {
        $startDate = '2020-01-01';
        $endDate = $today;
    } else {
        $startDate = get('start_date') ?: $today;
        $endDate = get('end_date') ?: $today;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = $today;
    if ($startDate > $endDate) $startDate = $endDate;

    $summary = get_reporting_summary($u, $startDate, $endDate);
    $breakdowns = get_staff_and_counselor_breakdown();

    // Handle Downloads
    $download = strtolower(get('download') ?: '');
    if ($download === 'xlsx') {
        export_reporting_xlsx($summary, $breakdowns, 'Operations_Report_' . date('Ymd_His') . '.xlsx');
    }

    if ($download === 'pdf') {
        $pdfData = generate_reporting_pdf($summary, $breakdowns, 'Administrative Caseload & Staff Performance Report');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Admin_Operations_Report_' . date('Ymd_His') . '.pdf"');
        header('Content-Length: ' . (string)strlen($pdfData));
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Pragma: private');
        echo $pdfData;
        exit;
    }

    page_start('Operations & Analytics Report', $u, PORTALS[$u['role']]['label']);
    ?>

    <div class="panel-h" style="margin-bottom:1.25rem;">
      <div>
        <h1>Executive Operations &amp; Performance Report</h1>
        <p>Comprehensive system oversight, staff allocation, counselor caseload, and pipeline monitoring (Timezone: Asia/Karachi PKT).</p>
      </div>
      <div class="row-actions">
        <a class="btn sm primary" href="<?= h(url('admin/reports.php?tab=operations&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&download=xlsx')) ?>">⬇ Export Excel (.xlsx)</a>
        <a class="btn sm ghost" href="<?= h(url('admin/reports.php?tab=operations&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&download=pdf')) ?>" target="_blank">⬇ Export PDF (.pdf)</a>
      </div>
    </div>

    <!-- Navigation Sub-Tabs -->
    <div style="display:flex; gap:0.5rem; margin-bottom:1.25rem; border-bottom:1.5px solid var(--border); padding-bottom:0.5rem;">
      <a class="btn sm primary" href="<?= h(url('admin/reports.php?tab=operations')) ?>">Operations &amp; Performance</a>
      <a class="btn sm ghost" href="<?= h(url('admin/reports.php?tab=monthly')) ?>">Monthly Archive</a>
      <a class="btn sm ghost" href="<?= h(url('admin/disabled_refunds.php')) ?>">Disabled &amp; Refunds Audit &rarr;</a>
    </div>

    <!-- Date Filter Bar -->
    <section class="panel" style="margin-bottom:1.5rem; padding:1rem 1.25rem;">
      <form method="get" action="<?= h(url('admin/reports.php')) ?>" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
        <input type="hidden" name="tab" value="operations">
        <div style="display:flex; flex-direction:column; gap:0.25rem;">
          <label for="start_date" style="font-size:0.8rem; font-weight:600; color:var(--muted);">Start Date</label>
          <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>" class="input" style="padding:0.4rem 0.65rem;">
        </div>
        <div style="display:flex; flex-direction:column; gap:0.25rem;">
          <label for="end_date" style="font-size:0.8rem; font-weight:600; color:var(--muted);">End Date</label>
          <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>" class="input" style="padding:0.4rem 0.65rem;">
        </div>
        <div>
          <button type="submit" class="btn primary" style="padding:0.45rem 1rem;">Apply Filter</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.35rem; margin-left:auto; font-size:0.82rem;">
          <span style="color:var(--muted); margin-right:0.25rem;">Quick Presets:</span>
          <a class="btn sm <?= ($startDate === $today && $endDate === $today) ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/reports.php?tab=operations&preset=today')) ?>">Today</a>
          <a class="btn sm <?= $preset === 'week' ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/reports.php?tab=operations&preset=week')) ?>">This Week</a>
          <a class="btn sm <?= $preset === 'month' ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/reports.php?tab=operations&preset=month')) ?>">This Month</a>
          <a class="btn sm <?= $preset === 'all' ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/reports.php?tab=operations&preset=all')) ?>">All Time</a>
        </div>
      </form>
    </section>

    <!-- Core KPI Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
      <!-- New Students in Period -->
      <div class="panel" style="padding:1.15rem;">
        <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">New Registrations (Period)</div>
        <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['new_students'] ?></div>
        <div style="font-size:0.82rem; color:var(--muted);">
          <span class="pill sm">BS: <?= $summary['new_bs'] ?></span>
          <span class="pill sm" style="margin-left:0.25rem;">MS: <?= $summary['new_ms'] ?></span>
          <span style="font-size:0.75rem; margin-left:0.35rem; color:var(--muted);">(Created <?= h($startDate) ?> to <?= h($endDate) ?>)</span>
        </div>
      </div>

      <!-- Total Caseload Scope -->
      <div class="panel" style="padding:1.15rem;">
        <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Total System Caseload</div>
        <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['total_caseload'] ?></div>
        <div style="font-size:0.82rem; display:flex; gap:0.35rem; flex-wrap:wrap;">
          <span class="pill sm ok">Active: <?= $summary['active_count'] ?></span>
          <span class="pill sm">Inactive: <?= $summary['inactive_count'] ?></span>
          <span class="pill sm bad">Disabled: <?= $summary['disabled_count'] ?></span>
        </div>
      </div>

      <!-- CSC Verification Status -->
      <div class="panel" style="padding:1.15rem;">
        <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">CSC Verification</div>
        <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['verified_count'] ?> <span style="font-size:1rem; font-weight:400; color:var(--muted);">/ <?= $summary['total_caseload'] ?></span></div>
        <div style="font-size:0.82rem; color:var(--muted);">
          <span class="pill sm ok">Verified: <?= $summary['verified_count'] ?></span>
          <span class="pill sm warn" style="margin-left:0.25rem;">Unverified: <?= $summary['unverified_count'] ?></span>
        </div>
      </div>

      <!-- Pending Review Attention -->
      <div class="panel" style="padding:1.15rem; border-left:4px solid var(--bad, #ef4444);">
        <div style="font-size:0.82rem; font-weight:600; color:var(--bad, #ef4444); text-transform:uppercase; letter-spacing:0.04em;">Action: Pending Attention</div>
        <div style="font-size:2rem; font-weight:700; color:var(--bad, #ef4444); margin:0.35rem 0;"><?= $summary['pending_count'] ?></div>
        <div style="font-size:0.82rem;">
          <a href="<?= h(url('admin/students.php?pending=1')) ?>" style="font-weight:600; color:var(--bad, #ef4444); text-decoration:underline;">
            Review <?= $summary['pending_count'] ?> pending files &rarr;
          </a>
        </div>
      </div>

      <!-- Incomplete Profiles -->
      <div class="panel" style="padding:1.15rem;">
        <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Incomplete Profiles</div>
        <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['incomplete_count'] ?></div>
        <div style="font-size:0.82rem;">
          <a href="<?= h(url('admin/students.php?incomplete=1')) ?>" style="color:var(--muted); text-decoration:underline;">
            Missing country or degree level &rarr;
          </a>
        </div>
      </div>

      <!-- Refunded Count -->
      <div class="panel" style="padding:1.15rem;">
        <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Refund Processed</div>
        <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['refunded_count'] ?></div>
        <div style="font-size:0.82rem; color:var(--muted);">
          <a href="<?= h(url('admin/disabled_refunds.php?tab=refunds')) ?>" style="color:var(--text); text-decoration:underline;">
            View refund audit log &rarr;
          </a>
        </div>
      </div>
    </div>

    <!-- Country Distribution Table -->
    <section class="panel" style="margin-bottom:1.5rem;">
      <div class="panel-h">
        <h2>Country-wise Caseload Distribution</h2>
        <p>Breakdown of students by study destination, degree level, and pending review flags.</p>
      </div>

      <?php if (empty($summary['country_stats'])): ?>
        <div class="empty">No student records found in current scope.</div>
      <?php else: ?>
        <div class="tbl"><table>
          <thead>
            <tr>
              <th>Country Destination</th>
              <th style="text-align:center;">Total Students</th>
              <th style="text-align:center;">BS Program</th>
              <th style="text-align:center;">MS Program</th>
              <th style="text-align:center;">Pending Attention</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['country_stats'] as $cs): ?>
              <tr>
                <td>
                  <b><?= h($cs['country_name']) ?></b>
                  <?php if ($cs['country_name'] === 'Unassigned / Incomplete'): ?>
                    <span class="pill sm bad" style="margin-left:0.35rem;">Incomplete</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center; font-weight:600;"><?= $cs['student_count'] ?></td>
                <td style="text-align:center;"><span class="pill sm"><?= $cs['bs_count'] ?></span></td>
                <td style="text-align:center;"><span class="pill sm"><?= $cs['ms_count'] ?></span></td>
                <td style="text-align:center;">
                  <?php if ($cs['pending_count'] > 0): ?>
                    <span class="pill sm bad"><?= $cs['pending_count'] ?> pending</span>
                  <?php else: ?>
                    <span style="color:var(--muted); font-size:0.85rem;">0</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <?php if ($cs['country_name'] !== 'Unassigned / Incomplete'): ?>
                    <a class="btn sm ghost" href="<?= h(url('admin/students.php?country=' . urlencode($cs['country_name']))) ?>">View list &rarr;</a>
                  <?php else: ?>
                    <a class="btn sm ghost" href="<?= h(url('admin/students.php?incomplete=1')) ?>">Complete profiles &rarr;</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <!-- Processing Staff Breakdown -->
    <section class="panel" style="margin-bottom:1.5rem;">
      <div class="panel-h">
        <h2>Processing Staff Breakdown (Front-End &amp; Back-End)</h2>
        <p>Assigned caseload, verification progress, milestone offers, and visa grants per staff officer.</p>
      </div>

      <?php if (empty($breakdowns['staff_stats'])): ?>
        <div class="empty">No processing staff accounts found.</div>
      <?php else: ?>
        <div class="tbl"><table>
          <thead>
            <tr>
              <th>Staff Member</th>
              <th style="text-align:center;">Department</th>
              <th style="text-align:center;">Assigned Caseload</th>
              <th style="text-align:center;">CSC Verified</th>
              <th style="text-align:center;">Offer Letters</th>
              <th style="text-align:center;">Visa Grants</th>
              <th style="text-align:center;">Pending Attention</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($breakdowns['staff_stats'] as $st): ?>
              <tr>
                <td><b><?= h($st['name']) ?></b></td>
                <td style="text-align:center;">
                  <span class="pill sm <?= ($st['department'] === 'frontend') ? 'info' : 'primary' ?>">
                    <?= ($st['department'] === 'frontend') ? 'Front-End' : 'Back-End' ?>
                  </span>
                </td>
                <td style="text-align:center; font-weight:600;"><?= $st['assigned_count'] ?></td>
                <td style="text-align:center;"><span class="pill sm ok"><?= $st['verified_count'] ?></span></td>
                <td style="text-align:center;"><?= $st['offer_count'] ?></td>
                <td style="text-align:center;"><span class="pill sm ok"><?= $st['visa_granted_count'] ?></span></td>
                <td style="text-align:center;">
                  <?php if ($st['pending_count'] > 0): ?>
                    <span class="pill sm bad"><?= $st['pending_count'] ?></span>
                  <?php else: ?>
                    <span style="color:var(--muted); font-size:0.85rem;">0</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <!-- Counselor Caseload Breakdown -->
    <section class="panel" style="margin-bottom:1.5rem;">
      <div class="panel-h">
        <h2>Counselor Caseload Breakdown</h2>
        <p>Distribution of students, degree programs, and pending review cases across counselors.</p>
      </div>

      <?php if (empty($breakdowns['counselor_stats'])): ?>
        <div class="empty">No counselor accounts found.</div>
      <?php else: ?>
        <div class="tbl"><table>
          <thead>
            <tr>
              <th>Counselor</th>
              <th>Username</th>
              <th style="text-align:center;">Total Caseload</th>
              <th style="text-align:center;">BS Program</th>
              <th style="text-align:center;">MS Program</th>
              <th style="text-align:center;">CSC Verified</th>
              <th style="text-align:center;">Pending Attention</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($breakdowns['counselor_stats'] as $cs): ?>
              <tr>
                <td><b><?= h($cs['name']) ?></b></td>
                <td><code><?= h($cs['username'] ?? '') ?></code></td>
                <td style="text-align:center; font-weight:600;"><?= $cs['caseload_count'] ?></td>
                <td style="text-align:center;"><span class="pill sm"><?= $cs['bs_count'] ?></span></td>
                <td style="text-align:center;"><span class="pill sm"><?= $cs['ms_count'] ?></span></td>
                <td style="text-align:center;"><span class="pill sm ok"><?= $cs['verified_count'] ?></span></td>
                <td style="text-align:center;">
                  <?php if ($cs['pending_count'] > 0): ?>
                    <span class="pill sm bad"><?= $cs['pending_count'] ?></span>
                  <?php else: ?>
                    <span style="color:var(--muted); font-size:0.85rem;">0</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <!-- PMS Lead Breakdown -->
    <section class="panel">
      <div class="panel-h">
        <h2>PMS Lead Oversight</h2>
        <p>Student supervision volume and pending escalations per PMS Lead.</p>
      </div>

      <?php if (empty($breakdowns['pms_stats'])): ?>
        <div class="empty">No PMS Lead accounts recorded.</div>
      <?php else: ?>
        <div class="tbl"><table>
          <thead>
            <tr>
              <th>PMS Lead</th>
              <th style="text-align:center;">Supervised Students</th>
              <th style="text-align:center;">Pending Attention</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($breakdowns['pms_stats'] as $pm): ?>
              <tr>
                <td><b><?= h($pm['name']) ?></b></td>
                <td style="text-align:center; font-weight:600;"><?= $pm['assigned_students'] ?></td>
                <td style="text-align:center;">
                  <?php if ($pm['pending_count'] > 0): ?>
                    <span class="pill sm bad"><?= $pm['pending_count'] ?> pending</span>
                  <?php else: ?>
                    <span style="color:var(--muted); font-size:0.85rem;">0</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <?php
    page_end();
    exit;
}

// -------------------------------------------------------------
// Monthly Progress Archive (Legacy View)
// -------------------------------------------------------------
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

if ($scope === 'received') {
    $sql = "SELECT s.*, o.name AS officer_name 
            FROM students s 
            LEFT JOIN users o ON o.id = s.officer_id 
            WHERE (s.received_date BETWEEN ? AND ?) 
               OR (s.received_date IS NULL AND substr(s.created_at, 1, 10) BETWEEN ? AND ?)
            ORDER BY s.id DESC";
    $params = [$startDate, $endDate, $startDate, $endDate];
} else {
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

$download = strtolower(get('download') ?: '');
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

$monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$selectedMonthName = $monthNames[$month] . ' ' . $year;

page_start('Monthly Reports', $u, PORTALS['admin']['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div>
      <h1>Monthly Reports &amp; Analytics Archive</h1>
      <p>Historical monthly progress tracking, stage status breakdowns, and monthly PDF/CSV exports.</p>
    </div>
    <div class="row-actions">
      <a class="btn sm primary" href="<?= h(url("admin/reports.php?tab=monthly&year={$year}&month={$month}&scope={$scope}&download=pdf")) ?>">
        Download Monthly Report (PDF)
      </a>
      <a class="btn sm" href="<?= h(url("admin/reports.php?tab=monthly&year={$year}&month={$month}&scope={$scope}&download=csv")) ?>">
        Download Monthly CSV
      </a>
    </div>
  </div>

  <!-- Navigation Sub-Tabs -->
  <div style="display:flex; gap:0.5rem; margin-bottom:1.25rem; border-bottom:1.5px solid var(--border); padding-bottom:0.5rem;">
    <a class="btn sm ghost" href="<?= h(url('admin/reports.php?tab=operations')) ?>">Operations &amp; Performance</a>
    <a class="btn sm primary" href="<?= h(url('admin/reports.php?tab=monthly')) ?>">Monthly Archive</a>
    <a class="btn sm ghost" href="<?= h(url('admin/disabled_refunds.php')) ?>">Disabled &amp; Refunds Audit &rarr;</a>
  </div>

  <!-- Filter Selector Form -->
  <form method="get" class="toolbar report-filter-bar">
    <input type="hidden" name="tab" value="monthly">
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
      <div class="report-kpi-sub">Checklist completed</div>
    </div>
  </div>

  <!-- Progress Stages Breakdown Table -->
  <div class="tbl" style="margin-top: 1.5rem;">
    <table>
      <thead>
        <tr>
          <th>Progress Stage</th>
          <th style="width: 140px; text-align: center;">Students in Stage</th>
          <th style="width: 180px; text-align: center;">Pipeline %</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $tot = max(1, count($students));
        foreach (STAGES as $st): 
          $c = $summary['stage_counts'][$st[0]] ?? 0;
          $pct = round(($c / $tot) * 100, 1);
        ?>
          <tr>
            <td><b><?= h($st[0]) ?></b></td>
            <td style="text-align: center;"><span class="pill sm"><?= $c ?></span></td>
            <td style="text-align: center; color: var(--muted);"><?= $pct ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php 
        $closed = $summary['stage_counts']['Closed'] ?? 0; 
        $closedPct = round(($closed / $tot) * 100, 1);
        ?>
        <tr style="background: var(--sunk);">
          <td><b>File Complete / Closed</b></td>
          <td style="text-align: center;"><span class="pill sm ok"><?= $closed ?></span></td>
          <td style="text-align: center; color: var(--muted);"><?= $closedPct ?>%</td>
        </tr>
      </tbody>
    </table>
  </div>
</section>

<?php page_end(); ?>
