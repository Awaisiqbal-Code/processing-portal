<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';

$u = require_role('admin');

// Fetch all processing officers (both Frontend and Backend)
$officers = q("SELECT id, name, username, active, last_login, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department DESC, name ASC")->fetchAll();

// Determine selected officer
$rawParam = get('id');
if ($rawParam === '' && get('officer') !== '') {
    $rawParam = get('officer');
}

$selectedOfficer = null;
$isUnassignedView = false;
$selectedId = null;

if ($rawParam === 'none') {
    $isUnassignedView = true;
    $selectedId = 'none';
} elseif ($rawParam !== '' && is_numeric($rawParam)) {
    $oid = (int)$rawParam;
    foreach ($officers as $o) {
        if ((int)$o['id'] === $oid) {
            $selectedOfficer = $o;
            $selectedId = $oid;
            break;
        }
    }
}

// Fetch all students for global stats
$allStudents = q("SELECT s.*, o.name AS officer_name, fo.name AS fe_officer_user_name 
                  FROM students s 
                  LEFT JOIN users o ON o.id = s.officer_id 
                  LEFT JOIN users fo ON fo.id = s.frontend_officer_id 
                  ORDER BY s.id ASC")->fetchAll();
$totalStudents = count($allStudents);

// Calculate metrics per officer
$officerStats = [];
foreach ($officers as $o) {
    $oid = (int)$o['id'];
    $dept = $o['department'] ?? 'backend';
    $stRows = array_values(array_filter($allStudents, fn($s) => (int)$s['officer_id'] === $oid || (int)($s['frontend_officer_id'] ?? 0) === $oid));
    $stCount = count($stRows);
    
    // Doc counts
    $docStats = q("SELECT 
        COUNT(*) as total_docs,
        SUM(CASE WHEN file_path IS NOT NULL AND file_path != '' THEN 1 ELSE 0 END) as verified_docs,
        SUM(CASE WHEN file_path IS NULL OR file_path = '' THEN 1 ELSE 0 END) as pending_docs
        FROM student_documents sd 
        JOIN students s ON s.id = sd.student_id 
        WHERE s.officer_id = ? OR s.frontend_officer_id = ?", [$oid, $oid])->fetch();

    $verifiedDocs = (int)($docStats['verified_docs'] ?? 0);
    $pendingDocs = (int)($docStats['pending_docs'] ?? 0);
    $offersCount = count(array_filter($stRows, fn($s) => ($s['offer'] ?? '') === 'Yes'));
    $visaGranted = count(array_filter($stRows, fn($s) => ($s['visa'] ?? '') === 'Granted'));
    $visaRefused = count(array_filter($stRows, fn($s) => ($s['visa'] ?? '') === 'Refused'));
    $visaPending = count(array_filter($stRows, fn($s) => ($s['visa'] ?? '') !== 'Granted' && ($s['visa'] ?? '') !== 'Refused'));
    $onboardingDone = count(array_filter($stRows, fn($s) => ($s['consent'] ?? '') === 'Yes' && ($s['docs_checklist'] ?? '') === 'Yes' && ($s['initial_meeting'] ?? '') === 'Yes'));
    $meetingVideos = count(array_filter($stRows, fn($s) => student_has_meeting($s)));
    $dueFollowups = count(array_filter(due_list($stRows), fn($x) => $x['date'] <= today()));

    $officerStats[$oid] = [
        'officer'         => $o,
        'department'      => $dept,
        'student_count'   => $stCount,
        'workload_pct'    => $totalStudents > 0 ? round(($stCount / $totalStudents) * 100, 1) : 0,
        'verified_docs'   => $verifiedDocs,
        'pending_docs'    => $pendingDocs,
        'offers_count'    => $offersCount,
        'visa_granted'    => $visaGranted,
        'visa_refused'    => $visaRefused,
        'visa_pending'    => $visaPending,
        'onboarding_done' => $onboardingDone,
        'meeting_videos'  => $meetingVideos,
        'due_followups'   => $dueFollowups,
        'students'        => $stRows,
    ];
}

$feOfficers = array_values(array_filter($officers, fn($o) => ($o['department'] ?? '') === 'frontend'));
$beOfficers = array_values(array_filter($officers, fn($o) => ($o['department'] ?? 'backend') === 'backend'));

$unassignedStudents = array_values(array_filter($allStudents, fn($s) => empty($s['officer_id']) || empty($s['frontend_officer_id'])));
$unassignedCount = count($unassignedStudents);

// Set page title
$pageTitle = $selectedOfficer ? 'Officer: ' . $selectedOfficer['name'] : ($isUnassignedView ? 'Unassigned Students' : 'Processing Officers');
page_start($pageTitle, $u, PORTALS['admin']['label']);
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
  <div>
    <div class="eyebrow" style="color:var(--accent); font-weight:700; text-transform:uppercase; font-size:0.78rem; letter-spacing:0.05em; margin-bottom:0.25rem;">
      Admin Operations · Front End & Back End Processing Teams
    </div>
    <h1 style="margin:0 0 0.25rem 0;">Processing Officers & Workspaces</h1>
    <p class="muted" style="margin:0; font-size:0.95rem;">
      View Front End (Onboarding & Initial Meetings) and Back End (Admissions & Visa Filing) officers separately with complete student progress.
    </p>
  </div>
  <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
    <?php if ($selectedOfficer || $isUnassignedView): ?>
      <a class="btn sm" href="<?= h(url('admin/officers.php')) ?>">← All Officers Overview</a>
      <?php if ($selectedOfficer): ?>
        <a class="btn sm ghost" href="<?= h(url('admin/export.php?officer=' . $selectedOfficer['id'])) ?>">⬇ Export Officer CSV</a>
      <?php endif; ?>
    <?php else: ?>
      <a class="btn sm ghost" href="<?= h(url('admin/export.php')) ?>">⬇ Export All Students CSV</a>
      <a class="btn sm" href="<?= h(url('admin/staff.php')) ?>">Manage Staff Accounts</a>
    <?php endif; ?>
    <a class="btn sm primary" href="<?= h(url('admin/student.php?new=1' . ($selectedOfficer ? '&officer_id=' . $selectedOfficer['id'] : ''))) ?>">+ Add Student</a>
  </div>
</div>

<!-- Interactive Officer Selection Tabs / Bar -->
<div style="background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:0.9rem 1.15rem; margin-bottom:1.5rem; box-shadow:var(--shadow-sm); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.85rem;">
  <div style="display:flex; align-items:center; gap:0.45rem; flex-wrap:wrap;">
    <a class="btn sm <?= ($selectedId === null) ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/officers.php')) ?>" style="border-radius:20px; font-weight:600;">
      📊 All Officers <span class="pill" style="margin-left:0.3rem; font-size:0.74rem; background:rgba(255,255,255,0.18);"><?= $totalStudents ?></span>
    </a>

    <span style="color:var(--line-strong); margin:0 0.2rem;">|</span>
    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--cyan);">Front End:</span>
    <?php foreach ($feOfficers as $o):
        $oid = (int)$o['id'];
        $stInfo = $officerStats[$oid] ?? null;
        $cnt = $stInfo['student_count'] ?? 0;
        $isSelected = ($selectedId === $oid);
    ?>
      <a class="btn sm <?= $isSelected ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="border-radius:20px; font-weight:600;">
        👤 <?= h($o['name']) ?>
        <span class="pill" style="margin-left:0.3rem; font-size:0.74rem; background:rgba(255,255,255,0.18);"><?= $cnt ?></span>
      </a>
    <?php endforeach; ?>

    <span style="color:var(--line-strong); margin:0 0.2rem;">|</span>
    <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--purple);">Back End:</span>
    <?php foreach ($beOfficers as $o):
        $oid = (int)$o['id'];
        $stInfo = $officerStats[$oid] ?? null;
        $cnt = $stInfo['student_count'] ?? 0;
        $isSelected = ($selectedId === $oid);
    ?>
      <a class="btn sm <?= $isSelected ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="border-radius:20px; font-weight:600;">
        👤 <?= h($o['name']) ?>
        <span class="pill" style="margin-left:0.3rem; font-size:0.74rem; background:rgba(255,255,255,0.18);"><?= $cnt ?></span>
      </a>
    <?php endforeach; ?>

    <?php if ($unassignedCount > 0): ?>
      <span style="color:var(--line-strong); margin:0 0.2rem;">|</span>
      <a class="btn sm <?= ($selectedId === 'none') ? 'bad' : 'ghost' ?>" href="<?= h(url('admin/officers.php?officer=none')) ?>" style="border-radius:20px; font-weight:600; color:#ef4444;">
        ⚠️ Unassigned <span class="pill bad" style="margin-left:0.3rem; font-size:0.74rem;"><?= $unassignedCount ?></span>
      </a>
    <?php endif; ?>
  </div>

  <div style="display:flex; align-items:center; gap:0.4rem;">
    <label for="officer-quick-select" class="small muted" style="font-weight:600;">Quick Select:</label>
    <select id="officer-quick-select" onchange="if(this.value) window.location.href=this.value;" style="padding:0.35rem 0.65rem; border-radius:6px; font-size:0.85rem; border:1px solid var(--line); background:var(--input-bg); color:var(--fg);">
      <option value="<?= h(url('admin/officers.php')) ?>" <?= $selectedId === null ? 'selected' : '' ?>>All Officers Overview</option>
      <optgroup label="Front End Processing Team">
        <?php foreach ($feOfficers as $o): ?>
          <option value="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>" <?= $selectedId === (int)$o['id'] ? 'selected' : '' ?>>
            <?= h($o['name']) ?> — Front End (<?= $officerStats[(int)$o['id']]['student_count'] ?? 0 ?> students)
          </option>
        <?php endforeach; ?>
      </optgroup>
      <optgroup label="Back End Processing Team">
        <?php foreach ($beOfficers as $o): ?>
          <option value="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>" <?= $selectedId === (int)$o['id'] ? 'selected' : '' ?>>
            <?= h($o['name']) ?> — Back End (<?= $officerStats[(int)$o['id']]['student_count'] ?? 0 ?> students)
          </option>
        <?php endforeach; ?>
      </optgroup>
      <?php if ($unassignedCount > 0): ?>
        <option value="<?= h(url('admin/officers.php?officer=none')) ?>" <?= $selectedId === 'none' ? 'selected' : '' ?>>
          Unassigned Students (<?= $unassignedCount ?>)
        </option>
      <?php endif; ?>
    </select>
  </div>
</div>

<?php if ($selectedOfficer): 
    $oid = (int)$selectedOfficer['id'];
    $sInfo = $officerStats[$oid];
    $dept = $sInfo['department'];
    $isFeOfficer = ($dept === 'frontend');
    $officerStudents = $sInfo['students'];
    $totalOfficerStudents = count($officerStudents);

    // Apply local filters if set
    $fq = get('q');
    $fs = get('stage');
    $fv = get('visa');
    $fo = get('offer');
    $fd = get('done_stage');

    $filteredRows = $officerStudents;
    if ($fq !== '') {
        $lq = strtolower($fq);
        $filteredRows = array_values(array_filter($filteredRows, fn($r) => 
            str_contains(strtolower($r['name'] ?? ''), $lq) ||
            str_contains(strtolower($r['counselor'] ?? ''), $lq) ||
            str_contains(strtolower($r['intake'] ?? ''), $lq) ||
            str_contains(strtolower($r['branch'] ?? ''), $lq) ||
            str_contains(strtolower($r['remarks'] ?? ''), $lq)
        ));
    }
    if ($fs !== '') {
        $filteredRows = array_values(array_filter($filteredRows, fn($r) => 
            $fs === 'closed' ? next_stage($r) === null : next_stage($r) === $fs
        ));
    }
    if ($fv !== '') {
        $filteredRows = array_values(array_filter($filteredRows, fn($r) => ($r['visa'] ?? '') === $fv));
    }
    if ($fo !== '') {
        $filteredRows = array_values(array_filter($filteredRows, fn($r) => ($r['offer'] ?? '') === $fo));
    }
    if ($fd !== '' && isset(STAGES[(int)$fd])) {
        $filteredRows = array_values(array_filter($filteredRows, fn($r) => stage_done($r, (int)$fd)));
    }

    $filteredCount = count($filteredRows);
?>

  <!-- Officer Profile Banner -->
  <section class="panel" style="margin-bottom:1.5rem; background:linear-gradient(to right, var(--surface), var(--surface-elevated));">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.2rem;">
      <div style="display:flex; align-items:center; gap:1.1rem;">
        <div style="width:62px; height:62px; border-radius:50%; background:var(--accent); color:var(--accent-fg); display:flex; align-items:center; justify-content:center; font-size:1.85rem; font-weight:800; box-shadow:0 4px 12px rgba(76, 201, 196, 0.25);">
          <?= h(strtoupper(substr($selectedOfficer['name'], 0, 1))) ?>
        </div>
        <div>
          <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <h2 style="margin:0; font-size:1.4rem;"><?= h($selectedOfficer['name']) ?></h2>
            <span class="pill acc"><?= $isFeOfficer ? 'Front End Processing Officer' : 'Back End Processing Officer' ?></span>
            <span class="pill <?= $selectedOfficer['active'] ? 'ok' : 'bad' ?>"><?= $selectedOfficer['active'] ? 'Active' : 'Inactive' ?></span>
            <span class="pill mono">@<?= h($selectedOfficer['username']) ?></span>
          </div>
          <p class="muted" style="margin:0.25rem 0 0 0; font-size:0.88rem;">
            Assigned Caseload: <b><?= $totalOfficerStudents ?> students</b> (<?= $sInfo['workload_pct'] ?>% of total 250 students) ·
            Last sign-in: <?= $selectedOfficer['last_login'] ? h(fmt_date(substr($selectedOfficer['last_login'], 0, 10))) : 'Never' ?>
          </p>
        </div>
      </div>

      <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
        <a class="btn sm" href="<?= h(url('admin/students.php?officer=' . $oid)) ?>">View in Main Student List</a>
        <a class="btn sm ghost" href="<?= h(url('admin/reports.php')) ?>">Monthly Reports</a>
      </div>
    </div>
  </section>

  <!-- Dedicated Officer Scorecards / KPIs -->
  <div class="kpis" style="margin-bottom:1.5rem;">
    <div class="kpi">
      <div class="l">Assigned Students</div>
      <div class="v"><?= $totalOfficerStudents ?></div>
      <div class="s"><?= $sInfo['workload_pct'] ?>% of entire student body</div>
    </div>
    <div class="kpi">
      <div class="l">Documents Verified</div>
      <div class="v ok-t"><?= $sInfo['verified_docs'] ?></div>
      <div class="s"><?= $sInfo['pending_docs'] ?> documents pending submission</div>
    </div>
    <div class="kpi">
      <div class="l">Admission Offers</div>
      <div class="v"><?= $sInfo['offers_count'] ?></div>
      <div class="s"><?= $totalOfficerStudents > 0 ? round($sInfo['offers_count'] / $totalOfficerStudents * 100) : 0 ?>% offer success rate</div>
    </div>
    <div class="kpi">
      <div class="l">Visa Approvals</div>
      <div class="v ok-t"><?= $sInfo['visa_granted'] ?></div>
      <div class="s"><?= $sInfo['visa_refused'] ?> refused · <?= $sInfo['visa_pending'] ?> in process</div>
    </div>
    <div class="kpi">
      <div class="l">Follow-ups Due</div>
      <div class="v <?= $sInfo['due_followups'] ? 'bad-t' : '' ?>"><?= $sInfo['due_followups'] ?></div>
      <div class="s"><?= $sInfo['due_followups'] ? 'Requires officer attention' : 'All follow-ups on track' ?></div>
    </div>
    <div class="kpi">
      <div class="l">Meeting Recordings</div>
      <div class="v"><?= $sInfo['meeting_videos'] ?></div>
      <div class="s"><?= $totalOfficerStudents - $sInfo['meeting_videos'] ?> without recorded video</div>
    </div>
  </div>

  <!-- Pipeline Stage Breakdown for this Officer -->
  <section class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-h">
      <div>
        <h2><?= h($selectedOfficer['name']) ?>'s Pipeline Progression</h2>
        <p>Click any stage bar to filter this officer's student files below</p>
      </div>
      <?php if ($fd !== ''): ?>
        <a class="btn sm ghost" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>">Clear Stage Filter</a>
      <?php endif; ?>
    </div>
    <div class="bars">
      <?php foreach (STAGES as $i => $s):
          $c = count(array_filter($officerStudents, fn($r) => stage_done($r, $i)));
          $pct = $totalOfficerStudents > 0 ? round($c / $totalOfficerStudents * 100, 1) : 0;
          $isActiveStage = ($fd !== '' && (int)$fd === $i);
      ?>
        <a class="bar" style="text-decoration:none; color:inherit; <?= $isActiveStage ? 'border-color:var(--accent); background:var(--surface-elevated);' : '' ?>" href="<?= h(url('admin/officers.php?id=' . $oid . '&done_stage=' . $i)) ?>">
          <span><?= h($s[0]) ?></span>
          <span class="t"><i style="width:<?= $pct ?>%"></i></span>
          <span class="n num"><?= $c ?> <small class="muted" style="font-size:0.75rem;">(<?= $pct ?>%)</small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Applications Table for this Officer -->
  <section class="panel">
    <div class="panel-h">
      <div>
        <h2>Applications Assigned to <?= h($selectedOfficer['name']) ?> (<?= $filteredCount ?> of <?= $totalOfficerStudents ?>)</h2>
        <p>Click <b>Progress</b> to view live student portal, documents checklist, verified files, and verification timeline.</p>
      </div>
      <div class="row-actions">
        <a class="btn sm" href="<?= h(url('admin/export.php?officer=' . $oid)) ?>">⬇ Export This Table (CSV)</a>
      </div>
    </div>

    <!-- Filter Toolbar -->
    <form method="get" class="toolbar" style="margin-bottom:1rem;">
      <input type="hidden" name="id" value="<?= $oid ?>">
      <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search student name, counselor, intake..." aria-label="Search">
      
      <select name="stage" aria-label="Next Step" data-autosubmit>
        <option value="">Any Next Step</option>
        <?php foreach (STAGES as $s): ?>
          <option value="<?= h($s[0]) ?>" <?= $fs === $s[0] ? 'selected' : '' ?>><?= h($s[0]) ?></option>
        <?php endforeach; ?>
        <option value="closed" <?= $fs === 'closed' ? 'selected' : '' ?>>Closed (Completed)</option>
      </select>

      <select name="visa" aria-label="Visa Status" data-autosubmit>
        <option value="">Any Visa Status</option>
        <option value="Granted" <?= $fv === 'Granted' ? 'selected' : '' ?>>Visa Granted</option>
        <option value="Refused" <?= $fv === 'Refused' ? 'selected' : '' ?>>Visa Refused</option>
        <option value="Pending" <?= $fv === 'Pending' ? 'selected' : '' ?>>Visa Pending</option>
      </select>

      <select name="offer" aria-label="Offer Status" data-autosubmit>
        <option value="">Any Offer Status</option>
        <option value="Yes" <?= $fo === 'Yes' ? 'selected' : '' ?>>Offer Received</option>
        <option value="No" <?= $fo === 'No' ? 'selected' : '' ?>>Offer Pending</option>
      </select>

      <button class="btn sm" type="submit">Filter</button>
      <?php if ($fq !== '' || $fs !== '' || $fv !== '' || $fo !== '' || $fd !== ''): ?>
        <a class="btn sm ghost" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>">Reset</a>
      <?php endif; ?>
      <span class="count num"><?= $filteredCount ?> matching</span>
    </form>

    <?php if (!$filteredRows): ?>
      <div class="empty">No students match the selected filters for <?= h($selectedOfficer['name']) ?>.</div>
    <?php else: ?>
      <div class="tbl"><table>
        <thead>
          <tr>
            <th>SR#</th>
            <th>Student Name</th>
            <th>Counselor</th>
            <th><?= $isFeOfficer ? 'Back End Officer' : 'Front End Officer' ?></th>
            <th>Branch & Intake</th>
            <th>Docs & Meeting</th>
            <th>Progress</th>
            <th>Next Step</th>
            <th>Offer</th>
            <th>Visa</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($filteredRows as $r):
            $ns = next_stage($r);
            $dLink = safe_link($r['drive_link'] ?? null);
            $hasMeeting = student_has_meeting($r);
            $mLink = url('meeting.php?id=' . (int)$r['id']);
            $progressUrl = url('admin/progress.php?id=' . $r['id']);
            $editUrl = url('admin/student.php?id=' . $r['id']);
            $pdfUrl = url('admin/report.php?id=' . $r['id'] . '&format=pdf');
            $counterpartName = $isFeOfficer ? ($r['officer_name'] ?: 'Unassigned') : ($r['frontend_officer_name'] ?: $r['fe_officer_user_name'] ?: 'Unassigned');
            $counterpartId = $isFeOfficer ? ($r['officer_id'] ?? null) : ($r['frontend_officer_id'] ?? null);
        ?>
          <tr>
            <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
            <td>
              <a href="<?= h($progressUrl) ?>" title="View complete student progress and documents">
                <b><?= h($r['name']) ?></b>
              </a>
              <?php if (!empty($r['portal_id'])): ?>
                <div class="small muted" style="font-size:0.75rem;">ID: <?= h($r['portal_id']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= h($r['counselor'] ?: '-') ?></td>
            <td>
              <?php if ($counterpartId): ?>
                <a href="<?= h(url('admin/officers.php?id=' . $counterpartId)) ?>" class="pill" style="text-decoration:none;"><?= h($counterpartName) ?></a>
              <?php else: ?>
                <span class="muted small"><?= h($counterpartName) ?></span>
              <?php endif; ?>
            </td>
            <td class="small">
              <div><?= h($r['intake'] ?: '-') ?></div>
              <div class="muted"><?= h($r['branch'] ?: '') ?></div>
            </td>
            <td>
              <div style="display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap;">
                <?php if ($dLink): ?>
                  <a class="btn sm" href="<?= h($dLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Google Drive Documents Folder">📁 Docs</a>
                <?php endif; ?>
                <?php if ($hasMeeting): ?>
                  <a class="btn sm" href="<?= h($mLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video / Zoom">🎥 Meeting</a>
                <?php else: ?>
                  <span class="pill muted" style="font-size:0.72rem; padding:0.2rem 0.45rem; background:rgba(100,116,139,0.12); color:#64748b;" title="Meeting video not available">Not Available</span>
                <?php endif; ?>
                <a class="btn sm ghost" href="<?= h($pdfUrl) ?>" title="Download Complete Student PDF Dossier">⬇ PDF</a>
              </div>
            </td>
            <td>
              <a href="<?= h($progressUrl) ?>" title="Click to open student progress" style="text-decoration:none; display:inline-block;">
                <?= progress_dots($r) ?>
              </a>
            </td>
            <td>
              <?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?>
            </td>
            <td>
              <?php if (($r['offer'] ?? '') === 'Yes'): ?>
                <span class="pill ok">Received</span>
              <?php else: ?>
                <span class="pill muted">Pending</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (($r['visa'] ?? '') === 'Granted'): ?>
                <span class="pill ok">Granted</span>
              <?php elseif (($r['visa'] ?? '') === 'Refused'): ?>
                <span class="pill bad">Refused</span>
              <?php else: ?>
                <span class="pill"><?= h($r['visa'] ?: 'In Process') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap;">
                <a class="btn sm primary" href="<?= h($progressUrl) ?>" title="Open Full Student Portal View">Progress</a>
                <a class="btn sm" href="<?= h($editUrl) ?>" title="Edit Student Record">Edit</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

<?php elseif ($isUnassignedView): ?>

  <!-- Unassigned Students Section -->
  <section class="panel" style="margin-bottom:1.5rem; border-left:4px solid #ef4444;">
    <div class="panel-h">
      <div>
        <h2>Partially or Fully Unassigned Applications (<?= $unassignedCount ?>)</h2>
        <p>These student applications are missing either a Front End Officer or Back End Officer assignment.</p>
      </div>
    </div>

    <?php if (!$unassignedStudents): ?>
      <div class="empty" style="color:#10b981;">✅ Excellent! All student applications are assigned to both Front End and Back End officers.</div>
    <?php else: ?>
      <div class="tbl"><table>
        <thead>
          <tr>
            <th>SR#</th>
            <th>Student Name</th>
            <th>Counselor</th>
            <th>Front End Officer</th>
            <th>Back End Officer</th>
            <th>Next Step</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($unassignedStudents as $r): ?>
          <tr>
            <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
            <td><a href="<?= h(url('admin/progress.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a></td>
            <td><?= h($r['counselor'] ?: '-') ?></td>
            <td><?= !empty($r['frontend_officer_name']) ? '<span class="pill ok">' . h($r['frontend_officer_name']) . '</span>' : '<span class="pill bad">Not Assigned</span>' ?></td>
            <td><?= !empty($r['officer_name']) ? '<span class="pill ok">' . h($r['officer_name']) . '</span>' : '<span class="pill bad">Not Assigned</span>' ?></td>
            <td><span class="pill acc"><?= h(next_stage($r) ?: 'Closed') ?></span></td>
            <td>
              <a class="btn sm primary" href="<?= h(url('admin/student.php?id=' . $r['id'])) ?>">Assign Officer →</a>
              <a class="btn sm ghost" href="<?= h(url('admin/progress.php?id=' . $r['id'])) ?>">Progress</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

<?php else: 
    // ALL OFFICERS OVERVIEW MODE
    $totalDocRow = q("SELECT 
        SUM(CASE WHEN file_path IS NOT NULL AND file_path != '' THEN 1 ELSE 0 END) as verified_docs,
        SUM(CASE WHEN file_path IS NULL OR file_path = '' THEN 1 ELSE 0 END) as pending_docs
        FROM student_documents")->fetch();
    $totalVerifiedDocs = (int)($totalDocRow['verified_docs'] ?? 0);
    $totalPendingDocs = (int)($totalDocRow['pending_docs'] ?? 0);
    $totalOffers = count(array_filter($allStudents, fn($s) => ($s['offer'] ?? '') === 'Yes'));
    $totalGranted = count(array_filter($allStudents, fn($s) => ($s['visa'] ?? '') === 'Granted'));
    $totalRefused = count(array_filter($allStudents, fn($s) => ($s['visa'] ?? '') === 'Refused'));
    $totalFollowups = count(array_filter(due_list($allStudents), fn($x) => $x['date'] <= today()));
?>

  <!-- Executive Overview KPIs -->
  <div class="kpis" style="margin-bottom:1.5rem;">
    <div class="kpi">
      <div class="l">Total Unique Students</div>
      <div class="v"><?= $totalStudents ?></div>
      <div class="s"><?= count($feOfficers) ?> Front End · <?= count($beOfficers) ?> Back End Officers</div>
    </div>
    <div class="kpi">
      <div class="l">Documents Verified</div>
      <div class="v ok-t"><?= $totalVerifiedDocs ?></div>
      <div class="s"><?= $totalPendingDocs ?> pending verification</div>
    </div>
    <div class="kpi">
      <div class="l">University Offers</div>
      <div class="v"><?= $totalOffers ?></div>
      <div class="s"><?= $totalStudents > 0 ? round($totalOffers / $totalStudents * 100) : 0 ?>% overall offer rate</div>
    </div>
    <div class="kpi">
      <div class="l">Visas Approved</div>
      <div class="v ok-t"><?= $totalGranted ?></div>
      <div class="s"><?= $totalRefused ?> refused · <?= $totalStudents - $totalGranted - $totalRefused ?> in process</div>
    </div>
    <div class="kpi">
      <div class="l">Follow-ups Due</div>
      <div class="v <?= $totalFollowups ? 'bad-t' : '' ?>"><?= $totalFollowups ?></div>
      <div class="s">Across all 250 students</div>
    </div>
  </div>

  <!-- 1. FRONT END PROCESSING TEAM -->
  <section class="panel" style="margin-bottom:1.5rem; border-top:3px solid var(--cyan);">
    <div class="panel-h">
      <div>
        <h2>Front End Processing Team (Onboarding, Initial Meetings & Checklist)</h2>
        <p>Data synced from <b>Front End - Processing Team Dashboard (1)</b> without duplicating student records</p>
      </div>
    </div>

    <div class="tbl"><table>
      <thead>
        <tr>
          <th>Front End Officer</th>
          <th>Department</th>
          <th>Assigned Students</th>
          <th>Documents Status</th>
          <th>Recorded Meetings</th>
          <th>Offers Received</th>
          <th>Visa Granted</th>
          <th>Follow-ups Due</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($feOfficers as $o):
          $oid = (int)$o['id'];
          $sInfo = $officerStats[$oid];
          $totDocs = $sInfo['verified_docs'] + $sInfo['pending_docs'];
          $docPct = $totDocs > 0 ? round(($sInfo['verified_docs'] / $totDocs) * 100) : 0;
      ?>
        <tr>
          <td>
            <a href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="font-size:1.05rem; font-weight:700; color:inherit; text-decoration:none;">
              👤 <b><?= h($o['name']) ?></b>
            </a>
            <div class="mono small muted" style="font-size:0.75rem;">@<?= h($o['username']) ?></div>
          </td>
          <td><span class="pill acc">Front End</span></td>
          <td class="num">
            <a href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="font-weight:700; font-size:1.05rem;">
              <?= $sInfo['student_count'] ?>
            </a>
            <div class="muted small" style="font-size:0.75rem;"><?= $sInfo['workload_pct'] ?>% of students</div>
          </td>
          <td>
            <div style="font-size:0.85rem; margin-bottom:0.2rem;">
              <b style="color:var(--ok);"><?= $sInfo['verified_docs'] ?></b> verified / <span class="muted"><?= $sInfo['pending_docs'] ?> pending</span>
            </div>
            <div style="width:120px; height:6px; border-radius:3px; background:var(--line); overflow:hidden;">
              <div style="width:<?= $docPct ?>%; height:100%; background:var(--ok);"></div>
            </div>
          </td>
          <td class="num">
            <b><?= $sInfo['meeting_videos'] ?></b> videos
          </td>
          <td class="num"><b><?= $sInfo['offers_count'] ?></b></td>
          <td class="num"><span class="pill ok"><?= $sInfo['visa_granted'] ?></span></td>
          <td><span class="pill <?= $sInfo['due_followups'] ? 'bad' : 'ok' ?>"><?= $sInfo['due_followups'] ?></span></td>
          <td>
            <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
              <a class="btn sm primary" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>">Open <?= h($o['name']) ?>'s Data →</a>
              <a class="btn sm ghost" href="<?= h(url('admin/export.php?officer=' . $oid)) ?>">⬇ CSV</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <!-- 2. BACK END PROCESSING TEAM -->
  <section class="panel" style="border-top:3px solid var(--purple);">
    <div class="panel-h">
      <div>
        <h2>Back End Processing Team (University Portals, Admissions & Visa Filing)</h2>
        <p>Data synced from <b>BackEnd - Processing Team Dashboard</b> linked to the exact same 250 student records</p>
      </div>
    </div>

    <div class="tbl"><table>
      <thead>
        <tr>
          <th>Back End Officer</th>
          <th>Department</th>
          <th>Assigned Students</th>
          <th>Documents Status</th>
          <th>Recorded Meetings</th>
          <th>Offers Received</th>
          <th>Visa Granted</th>
          <th>Follow-ups Due</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($beOfficers as $o):
          $oid = (int)$o['id'];
          $sInfo = $officerStats[$oid];
          $totDocs = $sInfo['verified_docs'] + $sInfo['pending_docs'];
          $docPct = $totDocs > 0 ? round(($sInfo['verified_docs'] / $totDocs) * 100) : 0;
      ?>
        <tr>
          <td>
            <a href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="font-size:1.05rem; font-weight:700; color:inherit; text-decoration:none;">
              👤 <b><?= h($o['name']) ?></b>
            </a>
            <div class="mono small muted" style="font-size:0.75rem;">@<?= h($o['username']) ?></div>
          </td>
          <td><span class="pill">Back End</span></td>
          <td class="num">
            <a href="<?= h(url('admin/officers.php?id=' . $oid)) ?>" style="font-weight:700; font-size:1.05rem;">
              <?= $sInfo['student_count'] ?>
            </a>
            <div class="muted small" style="font-size:0.75rem;"><?= $sInfo['workload_pct'] ?>% of students</div>
          </td>
          <td>
            <div style="font-size:0.85rem; margin-bottom:0.2rem;">
              <b style="color:var(--ok);"><?= $sInfo['verified_docs'] ?></b> verified / <span class="muted"><?= $sInfo['pending_docs'] ?> pending</span>
            </div>
            <div style="width:120px; height:6px; border-radius:3px; background:var(--line); overflow:hidden;">
              <div style="width:<?= $docPct ?>%; height:100%; background:var(--ok);"></div>
            </div>
          </td>
          <td class="num">
            <b><?= $sInfo['meeting_videos'] ?></b> videos
          </td>
          <td class="num"><b><?= $sInfo['offers_count'] ?></b></td>
          <td class="num"><span class="pill ok"><?= $sInfo['visa_granted'] ?></span></td>
          <td><span class="pill <?= $sInfo['due_followups'] ? 'bad' : 'ok' ?>"><?= $sInfo['due_followups'] ?></span></td>
          <td>
            <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
              <a class="btn sm primary" href="<?= h(url('admin/officers.php?id=' . $oid)) ?>">Open <?= h($o['name']) ?>'s Data →</a>
              <a class="btn sm ghost" href="<?= h(url('admin/export.php?officer=' . $oid)) ?>">⬇ CSV</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

<?php endif; ?>

<?php page_end(); ?>
