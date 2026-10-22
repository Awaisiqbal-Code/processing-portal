<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP . '/layout.php';
require_once APP . '/reporting_service.php';

$u = require_role('officer');
if (!in_array($u['role'], ['pms_lead', 'super_admin', 'admin'], true)) {
    http_response_code(403);
    echo "Access denied. Only PMS Leads and System Administrators can view reporting metrics.";
    exit;
}

$today = date('Y-m-d');
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

// Basic sanity validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = $today;
if ($startDate > $endDate) $startDate = $endDate;

$summary = get_reporting_summary($u, $startDate, $endDate);

// Handle Downloads
$download = strtolower(get('download') ?: '');
if ($download === 'xlsx') {
    export_reporting_xlsx($summary, null, 'PMS_Lead_Report_' . date('Ymd_His') . '.xlsx');
}

if ($download === 'pdf') {
    $pdfData = generate_reporting_pdf($summary, null, 'PMS Lead Operational & Caseload Report');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="PMS_Lead_Report_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . (string)strlen($pdfData));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $pdfData;
    exit;
}

page_start('Operations & Caseload Report', $u, PORTALS[$u['role']]['label'] ?? 'PMS Lead');
?>

<div class="panel-h" style="margin-bottom:1.25rem;">
  <div>
    <h1>PMS Lead Operations &amp; Caseload Report</h1>
    <p>Operational metrics, active caseload distribution, and pending attention items for your supervised students (Timezone: Asia/Karachi PKT).</p>
  </div>
  <div class="row-actions">
    <a class="btn sm primary" href="<?= h(url('officer/reports.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&download=xlsx')) ?>">⬇ Export Excel (.xlsx)</a>
    <a class="btn sm ghost" href="<?= h(url('officer/reports.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&download=pdf')) ?>" target="_blank">⬇ Export PDF (.pdf)</a>
  </div>
</div>

<!-- Date Filter Bar -->
<section class="panel" style="margin-bottom:1.5rem; padding:1rem 1.25rem;">
  <form method="get" action="<?= h(url('officer/reports.php')) ?>" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
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
      <a class="btn sm <?= ($startDate === $today && $endDate === $today) ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/reports.php?preset=today')) ?>">Today</a>
      <a class="btn sm <?= $preset === 'week' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/reports.php?preset=week')) ?>">This Week</a>
      <a class="btn sm <?= $preset === 'month' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/reports.php?preset=month')) ?>">This Month</a>
      <a class="btn sm <?= $preset === 'all' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/reports.php?preset=all')) ?>">All Time</a>
    </div>
  </form>
</section>

<!-- Core KPI Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
  <!-- New Registrations in Period -->
  <div class="panel" style="padding:1.15rem;">
    <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">New Students (Period)</div>
    <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['new_students'] ?></div>
    <div style="font-size:0.82rem; color:var(--muted);">
      <span class="pill sm">BS: <?= $summary['new_bs'] ?></span>
      <span class="pill sm" style="margin-left:0.25rem;">MS: <?= $summary['new_ms'] ?></span>
      <span style="font-size:0.75rem; margin-left:0.35rem; color:var(--muted);">(Created <?= h($startDate) ?> to <?= h($endDate) ?>)</span>
    </div>
  </div>

  <!-- Total Caseload & Lifecycle Status -->
  <div class="panel" style="padding:1.15rem;">
    <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Total Caseload Scope</div>
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

  <!-- Pending Review Flag -->
  <div class="panel" style="padding:1.15rem; border-left:4px solid var(--bad, #ef4444);">
    <div style="font-size:0.82rem; font-weight:600; color:var(--bad, #ef4444); text-transform:uppercase; letter-spacing:0.04em;">Action: Pending Attention</div>
    <div style="font-size:2rem; font-weight:700; color:var(--bad, #ef4444); margin:0.35rem 0;"><?= $summary['pending_count'] ?></div>
    <div style="font-size:0.82rem;">
      <a href="<?= h(url('officer/students.php?pending=1')) ?>" style="font-weight:600; color:var(--bad, #ef4444); text-decoration:underline;">
        Review <?= $summary['pending_count'] ?> pending files &rarr;
      </a>
    </div>
  </div>

  <!-- Incomplete Profiles -->
  <div class="panel" style="padding:1.15rem;">
    <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Incomplete Profiles</div>
    <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['incomplete_count'] ?></div>
    <div style="font-size:0.82rem;">
      <a href="<?= h(url('officer/students.php?incomplete=1')) ?>" style="color:var(--muted); text-decoration:underline;">
        Missing country or degree level &rarr;
      </a>
    </div>
  </div>

  <!-- Refunded Count -->
  <div class="panel" style="padding:1.15rem;">
    <div style="font-size:0.82rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em;">Refund Processed</div>
    <div style="font-size:2rem; font-weight:700; color:var(--text); margin:0.35rem 0;"><?= $summary['refunded_count'] ?></div>
    <div style="font-size:0.82rem; color:var(--muted);">
      Students with refund settlements recorded
    </div>
  </div>
</div>

<!-- Country-wise Distribution Table -->
<section class="panel">
  <div class="panel-h">
    <h2>Country-wise Caseload Distribution</h2>
    <p>Breakdown of students by study destination, degree level, and pending review flags.</p>
  </div>

  <?php if (empty($summary['country_stats'])): ?>
    <div class="empty">No student records found in your assigned scope.</div>
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
                <a class="btn sm ghost" href="<?= h(url('officer/students.php?country=' . urlencode($cs['country_name']))) ?>">View list &rarr;</a>
              <?php else: ?>
                <a class="btn sm ghost" href="<?= h(url('officer/students.php?incomplete=1')) ?>">Complete profiles &rarr;</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php page_end(); ?>
