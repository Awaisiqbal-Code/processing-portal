<?php
declare(strict_types=1);
// Shared dashboard for admin and officer. Expects $u.

[$w, $p] = scope_sql($u);
$rows = q("SELECT s.*, o.name AS officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id WHERE $w", $p)->fetchAll();
$admin = is_admin_role($u['role']);
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

if ($u['role'] === 'counselor') {
    // Dedicated Counselor Workspace Landing Page
    $tab = strtolower((string)(get('tab') ?: 'all'));
    $countryFilter = trim((string)(get('country') ?: ''));
    $q = trim((string)(get('q') ?: ''));

    $allCounselorStudents = $rows;
    $bsCount = count(array_filter($allCounselorStudents, fn($r) => ($r['program_level'] ?? '') === 'BS'));
    $msCount = count(array_filter($allCounselorStudents, fn($r) => ($r['program_level'] ?? '') === 'MS'));
    $pendingCount = count(array_filter($allCounselorStudents, fn($r) => !empty($r['pending_since'])));
    $incompleteCount = count(array_filter($allCounselorStudents, fn($r) => empty($r['program_level']) || empty($r['country'])));

    // Distinct countries for filter
    $counselorCountries = array_values(array_unique(array_filter(array_map(fn($r) => $r['country'] ?? '', $allCounselorStudents))));
    sort($counselorCountries);

    // Apply Tab filter
    $filtered = $allCounselorStudents;
    if ($tab === 'bs') {
        $filtered = array_filter($filtered, fn($r) => ($r['program_level'] ?? '') === 'BS');
    } elseif ($tab === 'ms') {
        $filtered = array_filter($filtered, fn($r) => ($r['program_level'] ?? '') === 'MS');
    } elseif ($tab === 'incomplete') {
        $filtered = array_filter($filtered, fn($r) => empty($r['program_level']) || empty($r['country']));
    }

    // Apply Country filter
    if ($countryFilter !== '') {
        $filtered = array_filter($filtered, fn($r) => ($r['country'] ?? '') === $countryFilter);
    }

    // Apply Search
    if ($q !== '') {
        $filtered = array_filter($filtered, fn($r) => stripos((string)$r['name'], $q) !== false || stripos((string)($r['remarks'] ?? ''), $q) !== false);
    }

    page_start('Counselor Workspace · Dashboard', $u, PORTALS['counselor']['label']);
    ?>

    <div class="panel-h" style="margin-bottom:1.25rem;">
      <div>
        <h1>Welcome, <?= h($u['name']) ?></h1>
        <p>Counselor Workspace: Read-only overview of your assigned student caseload. Monitor progress, review action badges, upload signed consents, and add internal remarks.</p>
      </div>
      <div class="row-actions">
        <a class="btn sm primary" href="<?= h(url('officer/students.php')) ?>">Open Full Student Table →</a>
      </div>
    </div>

    <!-- KPI Metric Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
      <div class="panel" style="padding:1rem;">
        <div style="font-size:0.78rem; font-weight:600; color:var(--muted); text-transform:uppercase;">Total Assigned</div>
        <div style="font-size:1.85rem; font-weight:700; margin:0.25rem 0;"><?= $n ?></div>
        <div class="small muted">Active student files</div>
      </div>

      <div class="panel" style="padding:1rem;">
        <div style="font-size:0.78rem; font-weight:600; color:var(--muted); text-transform:uppercase;">BS Degree Level</div>
        <div style="font-size:1.85rem; font-weight:700; margin:0.25rem 0;"><?= $bsCount ?></div>
        <div class="small muted">Undergraduate applicants</div>
      </div>

      <div class="panel" style="padding:1rem;">
        <div style="font-size:0.78rem; font-weight:600; color:var(--muted); text-transform:uppercase;">MS Degree Level</div>
        <div style="font-size:1.85rem; font-weight:700; margin:0.25rem 0;"><?= $msCount ?></div>
        <div class="small muted">Postgraduate applicants</div>
      </div>

      <div class="panel" style="padding:1rem; <?= $pendingCount > 0 ? 'border-left:4px solid var(--bad, #ef4444);' : '' ?>">
        <div style="font-size:0.78rem; font-weight:600; color:<?= $pendingCount > 0 ? 'var(--bad, #ef4444)' : 'var(--muted)' ?>; text-transform:uppercase;">Pending Review</div>
        <div style="font-size:1.85rem; font-weight:700; color:<?= $pendingCount > 0 ? 'var(--bad, #ef4444)' : 'inherit' ?>; margin:0.25rem 0;"><?= $pendingCount ?></div>
        <div class="small"><a href="<?= h(url('officer/students.php?pending=1')) ?>" style="color:var(--bad, #ef4444); text-decoration:underline;">Needs attention &rarr;</a></div>
      </div>

      <div class="panel" style="padding:1rem; <?= $incompleteCount > 0 ? 'border-left:4px solid var(--warn, #f59e0b);' : '' ?>">
        <div style="font-size:0.78rem; font-weight:600; color:<?= $incompleteCount > 0 ? 'var(--warn, #f59e0b)' : 'var(--muted)' ?>; text-transform:uppercase;">Incomplete Profiles</div>
        <div style="font-size:1.85rem; font-weight:700; color:<?= $incompleteCount > 0 ? 'var(--warn, #f59e0b)' : 'inherit' ?>; margin:0.25rem 0;"><?= $incompleteCount ?></div>
        <div class="small"><a href="<?= h(url('officer/students.php?incomplete=1')) ?>" style="color:var(--muted); text-decoration:underline;">Missing country/level &rarr;</a></div>
      </div>

      <div class="panel" style="padding:1rem;">
        <div style="font-size:0.78rem; font-weight:600; color:var(--muted); text-transform:uppercase;">Visa Decided</div>
        <div style="font-size:1.85rem; font-weight:700; margin:0.25rem 0;"><?= $granted ?> <span style="font-size:0.95rem; font-weight:400; color:var(--muted);">/ <?= $refused ?> refused</span></div>
        <div class="small muted"><?= $offers ?> offer letters received</div>
      </div>
    </div>

    <!-- Caseload Tabs & Filter Toolbar -->
    <section class="panel">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; border-bottom:1.5px solid var(--border); padding-bottom:0.75rem;">
        <!-- Program Level Tabs -->
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
          <a class="btn sm <?= $tab === 'all' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/index.php?tab=all' . ($countryFilter ? '&country=' . urlencode($countryFilter) : ''))) ?>">
            All Students <span class="pill sm" style="margin-left:0.35rem;"><?= $n ?></span>
          </a>
          <a class="btn sm <?= $tab === 'bs' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/index.php?tab=bs' . ($countryFilter ? '&country=' . urlencode($countryFilter) : ''))) ?>">
            BS Program <span class="pill sm" style="margin-left:0.35rem;"><?= $bsCount ?></span>
          </a>
          <a class="btn sm <?= $tab === 'ms' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/index.php?tab=ms' . ($countryFilter ? '&country=' . urlencode($countryFilter) : ''))) ?>">
            MS Program <span class="pill sm" style="margin-left:0.35rem;"><?= $msCount ?></span>
          </a>
          <a class="btn sm <?= $tab === 'incomplete' ? 'primary' : 'ghost' ?>" href="<?= h(url('officer/index.php?tab=incomplete' . ($countryFilter ? '&country=' . urlencode($countryFilter) : ''))) ?>">
            Incomplete <span class="pill sm warn" style="margin-left:0.35rem;"><?= $incompleteCount ?></span>
          </a>
        </div>

        <!-- Filter Form: Country & Search -->
        <form method="get" action="<?= h(url('officer/index.php')) ?>" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <select name="country" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.6rem;">
            <option value="">All Destination Countries</option>
            <?php foreach ($counselorCountries as $cName): ?>
              <option value="<?= h($cName) ?>" <?= $countryFilter === $cName ? 'selected' : '' ?>><?= h($cName) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search student name..." style="font-size:0.82rem; padding:0.35rem 0.6rem;">
          <button type="submit" class="btn sm">Filter</button>
          <?php if ($countryFilter !== '' || $q !== '' || $tab !== 'all'): ?>
            <a class="btn sm ghost" href="<?= h(url('officer/index.php')) ?>">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- Students Table -->
      <?php if (empty($filtered)): ?>
        <div class="empty">No students found matching your selected filters.</div>
      <?php else: ?>
        <div class="tbl"><table>
          <thead>
            <tr>
              <th style="width:55px;">SR#</th>
              <th>Student &amp; Status Badges</th>
              <th>Program &amp; Country</th>
              <th>Drive &amp; Meetings</th>
              <th>Progress Stage</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filtered as $r):
              $stStatus = (string)($r['student_status'] ?? 'active');
              $isVerified = ($r['csc_status'] === 'Verified' || !empty($r['csc_verified_at']));
              $isPending = !empty($r['pending_since']);
              $isIncomplete = (empty($r['program_level']) || empty($r['country']));
              $ns = next_stage($r);
              $dLink = drive_link_for_student($r);
              $mLink = meeting_link_for_student($r);
            ?>
              <tr>
                <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
                <td>
                  <a href="<?= h(url('officer/student.php?id=' . (int)$r['id'])) ?>" style="text-decoration:none;">
                    <b><?= h($r['name']) ?></b>
                  </a>
                  <div style="display:flex; gap:0.25rem; flex-wrap:wrap; margin-top:0.25rem;">
                    <span class="pill sm <?= $stStatus === 'active' ? 'ok' : ($stStatus === 'disabled' ? 'bad' : 'warn') ?>">
                      <?= ucfirst($stStatus) ?>
                    </span>
                    <?php if ($isVerified): ?>
                      <span class="pill sm ok">✓ Verified</span>
                    <?php endif; ?>
                    <?php if ($isPending): ?>
                      <span class="pill sm bad" style="font-weight:700;">⚠ PENDING</span>
                    <?php endif; ?>
                    <?php if ($isIncomplete): ?>
                      <span class="pill sm warn">Incomplete</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div style="display:flex; flex-direction:column; gap:0.15rem;">
                    <div><?= !empty($r['program_level']) ? '<span class="pill sm acc">' . h($r['program_level']) . '</span>' : '<span class="pill sm muted">No Program</span>' ?></div>
                    <div><?= !empty($r['country']) ? '<span class="pill sm">' . h($r['country']) . '</span>' : '<span class="pill sm warn">No Country</span>' ?></div>
                  </div>
                </td>
                <td>
                  <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                    <?php if ($dLink): ?>
                      <a class="btn sm" href="<?= h($dLink) ?>" target="_blank" rel="noopener noreferrer">📁 Docs</a>
                    <?php endif; ?>
                    <?php if ($mLink): ?>
                      <a class="btn sm" href="<?= h($mLink) ?>" target="_blank" rel="noopener noreferrer">🎥 Meeting</a>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?></td>
                <td style="text-align:right;">
                  <a class="btn sm primary" href="<?= h(url('officer/student.php?id=' . (int)$r['id'])) ?>">View Student &rarr;</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <?php
    page_end();
    return;
}

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
    $officers = q("SELECT id, name, active, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department DESC, name ASC")->fetchAll(); ?>
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
