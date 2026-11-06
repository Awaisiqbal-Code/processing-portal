<?php
declare(strict_types=1);
// Shared dashboard for admin and officer. Expects $u.

[$w, $p] = scope_sql($u);
$rows = q("SELECT s.*, o.name AS officer_name,
                  (SELECT COUNT(*) FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL) AS remark_count,
                  (SELECT sr.created_at FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_created_at,
                  (SELECT sr.remark_text FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_text,
                  (SELECT sr.author_name FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_name
           FROM students s LEFT JOIN users o ON o.id = s.officer_id WHERE $w", $p)->fetchAll();
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

$isDocNoteOpen = function (array $r): bool {
    $note = strtolower(trim((string)($r['pending_docs_note'] ?? '')));
    return $note !== '' && !in_array($note, ['n/a', 'none', 'nil', 'complete', 'completed', 'all complete', 'done', '-'], true);
};
$isStudentPendingAny = function (array $r) use ($isDocNoteOpen): bool {
    return ($r['consent'] ?? '') !== 'Yes'
        || ($r['docs_checklist'] ?? '') !== 'Yes'
        || $isDocNoteOpen($r)
        || ($r['initial_meeting'] ?? '') !== 'Yes'
        || !empty($r['pending_since']);
};
$pendingTotalCases = count(array_filter($rows, $isStudentPendingAny));
$pendingDocsCases  = count(array_filter($rows, fn($r) => ($r['docs_checklist'] ?? '') !== 'Yes' || $isDocNoteOpen($r)));
$pendingConsents   = count(array_filter($rows, fn($r) => ($r['consent'] ?? '') !== 'Yes'));
$pendingMeetings   = count(array_filter($rows, fn($r) => ($r['initial_meeting'] ?? '') !== 'Yes'));

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

    <div class="brand-hero" style="margin-bottom:1.25rem;">
      <div>
        <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#74C3E4; margin-bottom:2px;">ScholarshipHive Edutancy · Counselor Advisory</div>
        <h1>Welcome, <?= h($u['name']) ?></h1>
        <p>Monitor your assigned students, track pending documents &amp; stages, upload signed consent forms, and add counselor remarks.</p>
        <div class="brand-promise">“Guiding every student's journey with clarity, honesty, and expertise.”</div>
      </div>
      <div class="row-actions">
        <a class="btn sm" style="background:rgba(255,255,255,0.14); color:#FFFFFF; border-color:rgba(116,195,228,0.5);" href="<?= h(url('officer/pending.php')) ?>">⏳ Pending Tracker</a>
        <a class="btn sm primary" style="background:#1392CB; border-color:#74C3E4;" href="<?= h(url('officer/students.php')) ?>">Open Full Student Table &rarr;</a>
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
              <th>Last Remarks (Click Dots)</th>
              <th>Next Step</th>
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
                <td><?= last_remarks_cell($r) ?></td>
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
<div class="brand-hero">
  <div>
    <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#74C3E4; margin-bottom:3px;">
      ScholarshipHive Edutancy · <?= h(PORTALS[$u['role']]['label']) ?>
    </div>
    <h1><?= $admin ? 'Global Operations & Team Overview' : 'Welcome, ' . h($u['name']) ?></h1>
    <p>Empowering ambitious students to unlock global education opportunities through trusted guidance and scholarship expertise.</p>
    <div class="values-strip">
      <span class="value-chip">🇮🇹 Italy</span>
      <span class="value-chip">🇫🇷 France</span>
      <span class="value-chip">🇩🇪 Germany</span>
      <span class="value-chip">🇨🇿 Czech Republic</span>
      <span class="value-chip">🇨🇳 China</span>
      <span class="value-chip">🇦🇹 Austria</span>
    </div>
  </div>
  <div class="row-actions">
    <a class="btn sm" style="background:rgba(255,255,255,0.14); color:#FFFFFF; border-color:rgba(116,195,228,0.5);" href="<?= h(url($base . '/pending.php')) ?>">⏳ Open Pending Tracker &rarr;</a>
    <a class="btn sm primary" style="background:#1392CB; border-color:#74C3E4;" href="<?= h(url($base . '/students.php')) ?>">Manage All Students &rarr;</a>
    <?php if (can_quick_add_student($u)): ?><button type="button" class="btn sm" data-qa-open style="background:#fff; color:#0F5D9E; border-color:#fff;">＋ Add Student</button><?php endif; ?>
  </div>
</div>

<div class="kpis">
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php')) ?>"><div class="l">Total students</div><div class="v"><?= $n ?></div><div class="s"><?= $thisMonth ?> added this month · Click to view</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php?kpi=onboarding')) ?>"><div class="l">In onboarding</div><div class="v"><?= $onb ?></div><div class="s">Consent, checklist or first meeting open</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer; border-color:var(--warn, #f59e0b);" href="<?= h(url($base . '/pending.php')) ?>"><div class="l" style="color:var(--warn, #f59e0b); font-weight:700;">⏳ Pending Tracker (Click)</div><div class="v"><?= $pendingTotalCases ?></div><div class="s">Docs: <?= $pendingDocsCases ?> · Consent: <?= $pendingConsents ?> · Meet: <?= $pendingMeetings ?> &rarr;</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="<?= h(url($base . '/students.php?kpi=visa_granted')) ?>"><div class="l">Visa granted</div><div class="v"><?= $granted ?></div><div class="s"><?= $refused ?> refused · <?= $offers ?> non-Italy offers</div></a>
  <a class="kpi" style="text-decoration:none; color:inherit; cursor:pointer;" href="#followups-panel"><div class="l">Follow-ups due</div><div class="v <?= $dueNow ? 'bad-t' : '' ?>"><?= $dueNow ?></div><div class="s">Today or overdue</div></a>
</div>

<?php
  $countryStats = [];
  $flagMap = ['Italy' => '🇮🇹', 'France' => '🇫🇷', 'Germany' => '🇩🇪', 'Czech Republic' => '🇨🇿', 'Austria' => '🇦🇹', 'China' => '🇨🇳'];
  foreach ($rows as $r) {
      $cName = trim((string)($r['country'] ?? '')) ?: 'Other';
      if (!isset($countryStats[$cName])) {
          $countryStats[$cName] = ['total' => 0, 'bs' => 0, 'ms' => 0, 'pending_docs' => 0, 'offers' => 0];
      }
      $countryStats[$cName]['total']++;
      if (($r['program_level'] ?? '') === 'BS') $countryStats[$cName]['bs']++;
      if (($r['program_level'] ?? '') === 'MS') $countryStats[$cName]['ms']++;
      if (($r['docs_checklist'] ?? '') !== 'Yes' || $isDocNoteOpen($r)) $countryStats[$cName]['pending_docs']++;
      if (($r['offer'] ?? '') === 'Yes') $countryStats[$cName]['offers']++;
  }
  uasort($countryStats, fn($a, $b) => $b['total'] <=> $a['total']);
  $appCountsByCountry = [];
  foreach (q("SELECT country, COUNT(*) AS cnt FROM student_applications GROUP BY country")->fetchAll() as $ar) {
      $appCountsByCountry[$ar['country']] = (int)$ar['cnt'];
  }
?>
<section class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-h">
    <div>
      <h2>🌍 Multi-Country Operations Overview</h2>
      <p>Click any destination country to view and manage its students, officers, pending items, and university applications</p>
    </div>
    <div class="row-actions">
      <a class="btn sm" href="<?= h(url($base . '/pending.php')) ?>">⏳ Full Pending Breakdown &rarr;</a>
      <a class="btn sm primary" href="<?= h(url($base . '/programs.php')) ?>">🏛 University Programs Directory &rarr;</a>
    </div>
  </div>
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(195px, 1fr)); gap:0.85rem;">
    <?php foreach ($countryStats as $cName => $st):
        $flag = $flagMap[$cName] ?? '📍';
        $appsCnt = $appCountsByCountry[$cName] ?? 0;
    ?>
      <div style="background:var(--sunk); border:1.5px solid var(--border); border-radius:8px; padding:0.9rem 1rem; display:flex; flex-direction:column; gap:0.35rem;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <a href="<?= h(url($base . '/students.php?country=' . rawurlencode($cName))) ?>" style="text-decoration:none; color:inherit;">
            <b style="font-size:1rem;"><?= $flag ?> <?= h($cName) ?></b>
          </a>
          <a href="<?= h(url($base . '/students.php?country=' . rawurlencode($cName))) ?>" class="pill sm acc" style="text-decoration:none;"><?= $st['total'] ?> students</a>
        </div>
        <div class="small muted">
          BS: <b><?= $st['bs'] ?></b> &middot; MS: <b><?= $st['ms'] ?></b>
          <?php if ($st['offers'] > 0): ?> &middot; Offers: <b><?= $st['offers'] ?></b><?php endif; ?>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.1rem;">
          <a href="<?= h(url($base . '/pending.php?country=' . rawurlencode($cName))) ?>" class="small" style="color:var(--warn, #f59e0b); font-weight:600; text-decoration:underline;">
            ⏳ <?= $st['pending_docs'] ?> Pending Docs &rarr;
          </a>
          <?php if ($appsCnt > 0): ?>
            <span class="small" style="color:var(--accent); font-weight:600;">🏛 <?= $appsCnt ?> Apps</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="grid2">
<?php if ($admin):
    $officers = q("SELECT id, name, active, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department DESC, name ASC")->fetchAll(); ?>
  <section class="panel">
    <div class="panel-h">
      <div>
        <h2>Students &amp; Pending Workload per Processing Officer</h2>
        <p>Click an officer or any pending count to open their detailed pending breakdown</p>
      </div>
      <div class="row-actions">
        <a class="btn sm" href="<?= h(url('admin/pending.php')) ?>">⏳ Officer Pending Matrix &rarr;</a>
        <a class="btn sm primary" href="<?= h(url('admin/officers.php')) ?>">All Officers &rarr;</a>
      </div>
    </div>
    <div class="tbl"><table>
      <thead><tr><th>Officer &amp; Team</th><th>Students</th><th>Pending Docs</th><th>Pending Stages</th><th>Follow-ups due</th><th>Workspace</th></tr></thead>
      <tbody>
      <?php foreach ($officers as $o):
          $mine = array_filter($rows, fn($r) => (int)$r['officer_id'] === (int)$o['id'] || (int)($r['frontend_officer_id'] ?? 0) === (int)$o['id']);
          $minePendingDocs = count(array_filter($mine, fn($r) => ($r['docs_checklist'] ?? '') !== 'Yes' || $isDocNoteOpen($r)));
          $minePendingAny  = count(array_filter($mine, $isStudentPendingAny));
          $d = count(array_filter(due_list($mine), fn($x) => $x['date'] <= today())); ?>
        <tr>
          <td>
            <a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><b><?= h($o['name']) ?></b></a>
            <span class="pill <?= $o['department'] === 'frontend' ? 'acc' : '' ?>" style="font-size:0.7rem; margin-left:0.25rem;"><?= $o['department'] === 'frontend' ? 'Front End' : 'Back End' ?></span>
            <?= $o['active'] ? '' : ' <span class="pill">Inactive</span>' ?>
          </td>
          <td class="num"><a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><b><?= count($mine) ?></b></a></td>
          <td class="num">
            <a class="pill sm <?= $minePendingDocs > 0 ? 'bad' : 'ok' ?>" href="<?= h(url('admin/pending.php?officer=' . $o['id'] . '&cat=docs#student-pending-list')) ?>" style="text-decoration:none;">
              <?= $minePendingDocs ?>
            </a>
          </td>
          <td class="num">
            <a class="pill sm <?= $minePendingAny > 0 ? 'warn' : 'ok' ?>" href="<?= h(url('admin/pending.php?officer=' . $o['id'] . '&cat=all#student-pending-list')) ?>" style="text-decoration:none;">
              <?= $minePendingAny ?>
            </a>
          </td>
          <td><span class="pill <?= $d ? 'bad' : 'ok' ?>"><?= $d ?></span></td>
          <td>
            <div style="display:flex; gap:0.3rem;">
              <a class="btn sm" href="<?= h(url('admin/pending.php?officer=' . $o['id'])) ?>">⏳ Pending</a>
              <a class="btn sm ghost" href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>">Profile &rarr;</a>
            </div>
          </td>
        </tr>
      <?php endforeach;
          $none = count(array_filter($rows, fn($r) => !$r['officer_id'] && empty($r['frontend_officer_id'])));
          if ($none): ?>
        <tr><td><a href="<?= h(url('admin/officers.php?officer=none')) ?>">Unassigned (FE/BE)</a></td><td class="num"><?= $none ?></td><td></td><td></td><td></td><td><a class="btn sm bad" href="<?= h(url('admin/officers.php?officer=none')) ?>">Assign</a></td></tr>
      <?php endif; ?>
        <tr class="total"><td><a href="<?= h(url('admin/pending.php')) ?>">Total Unique Students</a></td><td class="num"><?= $n ?></td><td class="num"><a href="<?= h(url('admin/pending.php?cat=docs')) ?>"><?= $pendingDocsCases ?></a></td><td class="num"><a href="<?= h(url('admin/pending.php?cat=all')) ?>"><?= $pendingTotalCases ?></a></td><td></td><td><a class="btn sm primary" href="<?= h(url('admin/pending.php')) ?>">Full Pending Report &rarr;</a></td></tr>
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
  <?php
    // Students per processing officer (front-end + back-end), from real assignments
    $officerLoad = [];
    $officerIdsByName = [];
    foreach ($rows as $r) {
        foreach ([(int)($r['frontend_officer_id'] ?? 0), (int)($r['officer_id'] ?? 0)] as $oidL) {
            if (!$oidL) continue;
            $nm = staff_name_map()[$oidL] ?? null;
            if (!$nm) continue;
            $officerLoad[$nm] = ($officerLoad[$nm] ?? 0) + 1;
            $officerIdsByName[$nm] = $oidL;
        }
    }
    arsort($officerLoad);
  ?>
  <?php foreach (['officer' => 'Students per processing officer', 'refund' => 'Refund status'] as $key => $title):
      $g = $key === 'officer' ? $officerLoad : $groups($key); $max = max($g ?: [1]); ?>
  <section class="panel">
    <div class="panel-h"><h2><?= h($title) ?></h2><p><?= $key === 'officer' ? 'Front-end and back-end caseload (click to filter)' : 'All students (click to filter)' ?></p></div>
    <div class="bars">
    <?php foreach ($g as $k => $c):
        $filterHref = $key === 'officer' ? url('admin/students.php?officer=' . (int)($officerIdsByName[$k] ?? 0)) : url('admin/students.php?refund=' . rawurlencode((string)$k)); ?>
      <a class="bar" style="text-decoration:none; color:inherit;" href="<?= h($filterHref) ?>"><span><?= h($k) ?></span><span class="t"><i style="width:<?= round($c / $max * 100, 1) ?>%"></i></span><span class="n num"><?= $c ?></span></a>
    <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h2><?= $admin ? 'Recent Student Files' : 'My Assigned Students' ?> (Showing <?= min(25, count($rows)) ?> of <?= count($rows) ?>)</h2><p>Click on any student name to edit their record or click Last Remarks dots to open all remarks &amp; add new remarks</p></div>
    <div class="row-actions">
      <a class="btn sm primary" href="<?= h(url($base . '/students.php')) ?>">View all <?= count($rows) ?> students →</a>
    </div>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">No students assigned yet.</div>
  <?php else: ?>
  <div class="tbl"><table>
    <thead><tr><th>SR#</th><th>Student</th><th>Country &amp; Program</th><th>Processing Officers</th><th>Docs & Meeting</th><th>Refund</th><th>Last Remarks (Click Dots)</th><th>Next step</th><th>Actions</th></tr></thead>
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
        <td>
          <span class="pill sm"><?= h($r['country'] ?: 'Italy') ?></span>
          <?php if (!empty($r['program_level'])): ?><span class="pill sm acc"><?= h($r['program_level']) ?></span><?php endif; ?>
        </td>
        <td class="small">
          <div class="officer-line"><span class="tag-fe">FE</span> <b><?= h($r['frontend_officer_name'] ?: 'Not set') ?></b></div>
          <div class="officer-line"><span class="tag-be">BE</span> <b><?= h($r['officer_name'] ?? 'Not assigned') ?></b></div>
          <?php if (!empty($r['counselor'])): ?><div class="muted" style="font-size:11.5px;">Counselor: <?= h($r['counselor']) ?></div><?php endif; ?>
        </td>
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
        <td><?= last_remarks_cell($r) ?></td>
        <td><?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?></td>
        <td>
          <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
            <a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>">Edit</a>
            <button type="button" class="btn sm ghost" data-open-remarks="<?= (int)$r['id'] ?>" data-student-name="<?= h($r['name']) ?>">💬 Remarks</button>
            <a class="btn sm primary" href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>">File</a>
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
require APP . '/pages/_quick_add.php';
page_end();
