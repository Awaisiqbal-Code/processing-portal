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
// One pending rule for the whole portal (same as the Pending Tracker)
$dashDocState = load_doc_state(array_map(fn($r) => (int)$r['id'], $rows));
$dashApps = [];
foreach (q('SELECT student_id, COUNT(*) c FROM student_applications GROUP BY student_id') as $ar) $dashApps[(int)$ar['student_id']] = (int)$ar['c'];
foreach ($rows as $i => $r) {
    $rows[$i]['_p'] = student_pending($r, $dashDocState[(int)$r['id']] ?? [], $dashApps[(int)$r['id']] ?? 0);
    $rows[$i]['_doc_info'] = ['complete' => !$rows[$i]['_p']['docs'], 'in_hand' => $rows[$i]['_p']['docs_in_hand']];
}
$activeRows = array_filter($rows, fn($r) => ($r['student_status'] ?? 'active') === 'active');
$offers = count(array_filter($rows, fn($r) => $r['offer'] === 'Yes'));
$granted = count(array_filter($rows, fn($r) => $r['visa'] === 'Granted'));
$refused = count(array_filter($rows, fn($r) => $r['visa'] === 'Refused'));
$due = due_list($rows);
$dueNow = count(array_filter($due, fn($d) => $d['date'] <= today()));
$onb = count(array_filter($activeRows, fn($r) => $r['_p']['consent'] || $r['_p']['docs'] || $r['_p']['first_meeting']));
$pendingTotalCases = count(array_filter($activeRows, fn($r) => $r['_p']['any']));
$pendingDocsCases  = count(array_filter($activeRows, fn($r) => $r['_p']['docs']));
$pendingConsents   = count(array_filter($activeRows, fn($r) => $r['_p']['consent']));
$pendingMeetings   = count(array_filter($activeRows, fn($r) => $r['_p']['first_meeting']));

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
      <?php $heroCountries = array_count_values(array_filter(array_map(fn($x) => trim((string)($x['country'] ?? '')), $rows)));
            arsort($heroCountries);
            foreach ($heroCountries as $hc => $hn): ?>
        <a class="value-chip" href="<?= h(url($base . '/students.php?country=' . rawurlencode($hc))) ?>" style="text-decoration:none"><?= country_flag($hc) ?> <?= h($hc) ?> · <?= $hn ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="row-actions">
    <a class="btn sm" style="background:rgba(255,255,255,0.14); color:#FFFFFF; border-color:rgba(116,195,228,0.5);" href="<?= h(url($base . '/pending.php')) ?>">⏳ Open Pending Tracker &rarr;</a>
    <a class="btn sm primary" style="background:#1392CB; border-color:#74C3E4;" href="<?= h(url($base . '/students.php')) ?>"><?= $admin ? 'Manage All Students' : 'My students' ?> &rarr;</a>
    <?php if (can_quick_add_student($u)): ?><button type="button" class="btn sm" data-qa-open style="background:#fff; color:#0F5D9E; border-color:#fff;">＋ Add Student</button><?php endif; ?>
  </div>
</div>

<?php if (can_verify_docs($u)):
    $vq = docs_waiting_verification($u);
    if ($vq):
        $vNames = staff_name_map(); $vBy = [];
        foreach ($vq as $vd) { $k = (int)($vd['updated_by'] ?: ($vd['officer_id'] ?: $vd['frontend_officer_id'])); $vBy[$k] = ($vBy[$k] ?? 0) + 1; }
        arsort($vBy); ?>
<a class="verify-cta" href="<?= h(url($base . '/verify_docs.php')) ?>">
  <span class="vc-n"><?= count($vq) ?></span>
  <span class="vc-t"><b>documents received — waiting for you to verify</b>
    <span class="small"><?= h(implode(' · ', array_map(fn($k, $c) => ($vNames[$k] ?? 'Imported') . ' ' . $c, array_keys(array_slice($vBy, 0, 5, true)), array_slice($vBy, 0, 5, true)))) ?><?= count($vBy) > 5 ? ' · …' : '' ?></span></span>
  <span class="btn sm primary">Check &amp; verify →</span>
</a>
<?php endif; endif; ?>

<?php if (!$admin):
    $work = array_fill_keys(array_keys(PENDING_TASKS), 0);
    foreach ($activeRows as $ar) foreach (PENDING_TASKS as $k => $_) if (!empty($ar['_p'][$k])) $work[$k]++;
    $workIcons = ['docs' => '📄', 'consent' => '✍', 'first_meeting' => '🤝', 'recording' => '🎥', 'second_tax' => '🧾', 'shortlisting' => '🏛', 'credentials' => '🔑', 'remarks' => '💬'];
    $workTotal = array_sum($work); ?>
<section class="panel my-work">
  <div class="panel-h"><div><div class="eyebrow">My work</div><h2><?= $workTotal ? 'What needs you today' : 'All clear 🎉' ?></h2>
    <p><?= $workTotal ? 'Click a card to open just those students and fix each item right there.' : 'Nothing is pending on your students right now.' ?></p></div>
    <a class="btn sm" href="<?= h(url('officer/pending_report.php')) ?>">My pending report →</a></div>
  <?php if ($workTotal): ?>
  <div class="work-cards">
    <?php foreach ($work as $k => $c): if (!$c) continue; ?>
      <a class="work-card <?= $k === 'remarks' || $k === 'docs' ? 'is-hot' : '' ?>" href="<?= h(url($base . '/pending.php?cat=' . $k)) ?>#list">
        <span class="wc-ic"><?= $workIcons[$k] ?? '•' ?></span><span class="wc-n"><?= $c ?></span><span class="wc-l"><?= h(PENDING_TASKS[$k]) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
  // ---------- numbers for the KPI tiles and the country cards (this dashboard's students only) ----------
  $scopeIds = array_flip(array_map(fn($x) => (int)$x['id'], $rows));
  $appsSent = 0; $appsLeft = 0; $stuSent = []; $stuLeft = [];
  $appByStudent = [];
  foreach (q('SELECT student_id, status FROM student_applications') as $ap) {
      $sidA = (int)$ap['student_id'];
      if (!isset($scopeIds[$sidA])) continue;
      if (app_is_submitted($ap['status'])) { $appsSent++; $stuSent[$sidA] = true; $appByStudent[$sidA]['sent'] = ($appByStudent[$sidA]['sent'] ?? 0) + 1; }
      elseif (app_is_remaining($ap['status'])) { $appsLeft++; $stuLeft[$sidA] = true; $appByStudent[$sidA]['left'] = ($appByStudent[$sidA]['left'] ?? 0) + 1; }
  }
  $countryStats = [];
  $flagMap = country_flags_map();
  foreach ($rows as $r) {
      $cName = trim((string)($r['country'] ?? '')) ?: 'Other';
      $c = &$countryStats[$cName];
      $c ??= ['total' => 0, 'active' => 0, 'bs' => 0, 'ms' => 0, 'pending_docs' => 0, 'docs_ok' => 0, 'any' => 0, 'offers' => 0, 'visa' => 0, 'sent' => 0, 'left' => 0, 'stu_sent' => 0, 'fe' => [], 'be' => []];
      $c['total']++;
      if (($r['program_level'] ?? '') === 'BS') $c['bs']++;
      if (($r['program_level'] ?? '') === 'MS') $c['ms']++;
      if (($r['student_status'] ?? 'active') === 'active') {
          $c['active']++;
          if ($r['_p']['docs']) $c['pending_docs']++; else $c['docs_ok']++;
          if ($r['_p']['any']) $c['any']++;
      }
      if (($r['offer'] ?? '') === 'Yes') $c['offers']++;
      if (($r['visa'] ?? '') === 'Granted') $c['visa']++;
      $c['sent'] += $appByStudent[(int)$r['id']]['sent'] ?? 0;
      $c['left'] += $appByStudent[(int)$r['id']]['left'] ?? 0;
      if (!empty($appByStudent[(int)$r['id']]['sent'])) $c['stu_sent']++;
      unset($c);
  }
  uasort($countryStats, fn($a, $b) => $b['total'] <=> $a['total']);
  $pct = fn($a, $b) => $b ? (int)round($a / $b * 100) : 0;
  $countryTint = ['Italy' => '#10B981', 'France' => '#3B82F6', 'Germany' => '#F59E0B', 'Czech Republic' => '#EF4444', 'China' => '#E11D48', 'Austria' => '#F43F5E'];
?>
<div class="kpis kpi-glow">
  <a class="kpi kg kg-blue" href="<?= h(url($base . '/students.php')) ?>">
    <span class="kg-ic">🎓</span><div class="l">Total students</div><div class="v"><?= $n ?></div><div class="s"><b>+<?= $thisMonth ?></b> this month</div></a>
  <a class="kpi kg kg-violet" href="<?= h(url($base . '/students.php?kpi=onboarding')) ?>">
    <span class="kg-ic">🧭</span><div class="l">In onboarding</div><div class="v"><?= $onb ?></div><div class="s">Documents, consent or 1st meeting open</div></a>
  <a class="kpi kg kg-amber" href="<?= h(url($base . '/pending.php')) ?>">
    <span class="kg-ic">⏳</span><div class="l">Pending tracker</div><div class="v"><?= $pendingTotalCases ?></div>
    <div class="kg-chips"><span>📄 <?= $pendingDocsCases ?></span><span>✍ <?= $pendingConsents ?></span><span>🤝 <?= $pendingMeetings ?></span></div></a>
  <a class="kpi kg kg-green" href="<?= h(url($base . '/students.php?kpi=visa_granted')) ?>">
    <span class="kg-ic">🛂</span><div class="l">Visa granted</div><div class="v"><?= $granted ?></div><div class="s"><?= $offers ?> offers received · <?= $refused ?> refused</div></a>
  <div class="kpi kg kg-teal kpi-apps">
    <span class="kg-ic">🏛</span>
    <a class="kpi-link" href="<?= h(url($base . '/students.php?kpi=apps_submitted')) ?>"><div class="l">Applications submitted</div><div class="v"><?= $appsSent ?></div><div class="s">for <?= count($stuSent) ?> students</div></a>
    <a class="kpi-left" href="<?= h(url($base . '/students.php?kpi=apps_remaining')) ?>"><b><?= $appsLeft ?></b> still to submit · <?= count($stuLeft) ?> students →</a>
    <div class="kg-bar" title="<?= $pct($appsSent, $appsSent + $appsLeft) ?>% of shortlisted applications sent"><i style="width:<?= $pct($appsSent, $appsSent + $appsLeft) ?>%"></i></div>
  </div>
</div>

<section class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-h">
    <div>
      <h2>🌍 <?= $admin ? 'Countries at a glance' : (count($countryStats) > 1 ? 'My countries' : 'My country') ?></h2>
      <p>Each card is one destination: students, documents, applications and results. Click anything to open those students.</p>
    </div>
    <div class="row-actions">
      <a class="btn sm" href="<?= h(url($base . '/pending.php')) ?>">⏳ Pending tracker →</a>
      <a class="btn sm primary" href="<?= h(url($base . '/programs.php')) ?>">🏛 University programs →</a>
    </div>
  </div>
  <div class="cc-grid">
    <?php foreach ($countryStats as $cName => $st):
        $tint = $countryTint[$cName] ?? '#1392CB';
        $docsPct = $pct($st['docs_ok'], $st['active']);
        $appPct = $pct($st['sent'], $st['sent'] + $st['left']);
        $cq = rawurlencode($cName);
    ?>
      <article class="cc" style="--tint: <?= $tint ?>">
        <a class="cc-head" href="<?= h(url($base . '/students.php?country=' . $cq)) ?>">
          <span class="cc-flag"><?= country_flag($cName) ?></span>
          <span class="cc-name"><b><?= h($cName) ?></b><small><?= $st['bs'] ?> BS · <?= $st['ms'] ?> MS</small></span>
          <span class="cc-total"><b><?= $st['total'] ?></b><small>students</small></span>
        </a>
        <div class="cc-split" title="BS <?= $st['bs'] ?> · MS <?= $st['ms'] ?>"><i class="bs" style="width:<?= $pct($st['bs'], max(1, $st['bs'] + $st['ms'])) ?>%"></i><i class="ms" style="width:<?= $pct($st['ms'], max(1, $st['bs'] + $st['ms'])) ?>%"></i></div>

        <a class="cc-row" href="<?= h(url($base . '/pending.php?cat=docs&country=' . $cq)) ?>#list">
          <span class="cc-ring" style="--p: <?= $docsPct ?>"><b><?= $docsPct ?>%</b></span>
          <span class="cc-txt"><b>Documents</b><?php if ($st['pending_docs']): ?><small><b class="warn-t"><?= $st['pending_docs'] ?></b> still sending</small><?php else: ?><small>All complete ✓</small><?php endif; ?></span>
        </a>
        <a class="cc-row" href="<?= h(url($base . '/students.php?kpi=apps_submitted&country=' . $cq)) ?>">
          <span class="cc-ring alt" style="--p: <?= $appPct ?>"><b><?= $appPct ?>%</b></span>
          <span class="cc-txt"><b>Applications</b><small><b class="ok-t"><?= $st['sent'] ?></b> sent</small><?php if ($st['left']): ?><small><b class="warn-t"><?= $st['left'] ?></b> to submit</small><?php endif; ?></span>
        </a>
        <div class="cc-foot">
          <a href="<?= h(url($base . '/students.php?kpi=offers&country=' . $cq)) ?>"><b><?= $st['offers'] ?></b> offers</a>
          <a href="<?= h(url($base . '/students.php?kpi=visa_granted&country=' . $cq)) ?>"><b><?= $st['visa'] ?></b> visas</a>
          <a href="<?= h(url($base . '/pending.php?country=' . $cq)) ?>#list" class="<?= $st['any'] ? 'warn' : '' ?>"><b><?= $st['any'] ?></b> with pending</a>
        </div>
      </article>
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
      <thead><tr><th>Officer &amp; Team</th><th class="num">Students</th><th class="num" title="Students with at least one required document missing">Documents</th><th class="num" title="Consent form not received">Consent</th><th class="num" title="1st meeting not held">1st meeting</th><th class="num" title="No shortlisted university yet">Shortlisting</th><th class="num">Follow-ups</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($officers as $o):
          $mine = array_filter($activeRows, fn($r) => (int)$r['officer_id'] === (int)$o['id'] || (int)($r['frontend_officer_id'] ?? 0) === (int)$o['id']);
          if (!$mine && !$o['active']) continue;
          $cnt = fn(string $k) => count(array_filter($mine, fn($r) => $r['_p'][$k]));
          $d = count(array_filter(due_list($mine), fn($x) => $x['date'] <= today()));
          $cell = function (string $k, string $tone) use ($cnt, $o) {
              $v = $cnt($k);
              return '<a class="cnt ' . ($v ? $tone : 'zero') . '" href="' . h(url('admin/pending.php?officer=' . $o['id'] . '&cat=' . $k . '#list')) . '">' . $v . '</a>';
          }; ?>
        <tr>
          <td>
            <a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><b><?= h($o['name']) ?></b></a>
            <span class="<?= $o['department'] === 'frontend' ? 'tag-fe' : 'tag-be' ?>"><?= $o['department'] === 'frontend' ? 'FE' : 'BE' ?></span>
            <?= $o['active'] ? '' : ' <span class="pill">Inactive</span>' ?>
          </td>
          <td class="num"><a href="<?= h(url('admin/officers.php?id=' . $o['id'])) ?>"><b><?= count($mine) ?></b></a></td>
          <td class="num"><?= $cell('docs', 'bad') ?></td>
          <td class="num"><?= $cell('consent', 'warn') ?></td>
          <td class="num"><?= $cell('first_meeting', 'warn') ?></td>
          <td class="num"><?= $cell('shortlisting', 'warn') ?></td>
          <td class="num"><span class="cnt <?= $d ? 'bad' : 'zero' ?>"><?= $d ?></span></td>
          <td><a class="btn sm" href="<?= h(url('admin/pending.php?officer=' . $o['id'] . '#list')) ?>">Pending →</a></td>
        </tr>
      <?php endforeach;
          $none = count(array_filter($activeRows, fn($r) => !$r['officer_id'] && empty($r['frontend_officer_id'])));
          if ($none): ?>
        <tr><td><a href="<?= h(url('admin/officers.php?officer=none')) ?>">Unassigned (FE/BE)</a></td><td class="num"><?= $none ?></td><td colspan="5"></td><td><a class="btn sm bad" href="<?= h(url('admin/officers.php?officer=none')) ?>">Assign</a></td></tr>
      <?php endif; ?>
        <tr class="total"><td>All active students</td><td class="num"><?= count($activeRows) ?></td>
          <td class="num"><a href="<?= h(url('admin/pending.php?cat=docs#list')) ?>"><?= $pendingDocsCases ?></a></td>
          <td class="num"><a href="<?= h(url('admin/pending.php?cat=consent#list')) ?>"><?= $pendingConsents ?></a></td>
          <td class="num"><a href="<?= h(url('admin/pending.php?cat=first_meeting#list')) ?>"><?= $pendingMeetings ?></a></td>
          <td class="num"><a href="<?= h(url('admin/pending.php?cat=shortlisting#list')) ?>"><?= count(array_filter($activeRows, fn($r) => $r['_p']['shortlisting'])) ?></a></td>
          <td></td><td><a class="btn sm primary" href="<?= h(url('admin/pending.php')) ?>">Tracker →</a></td></tr>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>

  <section class="panel">
    <div class="panel-h"><h2><?= $admin ? 'Where students are now' : 'Where my students are now' ?></h2><p>Current step of each active student's processing journey — click to list them</p></div>
    <div class="bars">
    <?php
      $nowCount = array_fill_keys(array_keys(JOURNEY_STAGES), 0); $doneAll = 0;
      $dashSaved = [];
      foreach (q('SELECT student_id, stage_key, status FROM student_stages') as $sr) $dashSaved[(int)$sr['student_id']][$sr['stage_key']] = $sr['status'];
      $dashAppRows = [];
      foreach (q('SELECT student_id, status FROM student_applications') as $ar) $dashAppRows[(int)$ar['student_id']][] = $ar;
      foreach ($activeRows as $r) {
          $auto = auto_journey($r, $dashAppRows[(int)$r['id']] ?? [], 0);
          $cur = null;
          foreach (JOURNEY_STAGES as $k => $_) { $st = $dashSaved[(int)$r['id']][$k] ?? $auto[$k][0]; if ($st !== 'Completed') { $cur = $k; break; } }
          if ($cur) $nowCount[$cur]++; else $doneAll++;
      }
      $na = max(1, count($activeRows));
      foreach (JOURNEY_STAGES as $k => $def): ?>
      <a class="bar" style="text-decoration:none; color:inherit;" href="<?= h(url($base . '/students.php?jnow=' . $k)) ?>"><span><?= h($def[0]) ?></span><span class="t"><i style="width:<?= round($nowCount[$k] / $na * 100, 1) ?>%"></i></span><span class="n num"><?= $nowCount[$k] ?></span></a>
    <?php endforeach; ?>
      <a class="bar" style="text-decoration:none; color:inherit;" href="<?= h(url($base . '/students.php?pstatus=Completed')) ?>"><span>All steps complete</span><span class="t"><i style="width:<?= round($doneAll / $na * 100, 1) ?>%"></i></span><span class="n num"><?= $doneAll ?></span></a>
    </div>
  </section>

<?php if (!$admin): ?>
  <section class="panel" id="followups-panel">
    <div class="panel-h"><h2>Follow-ups <?= $dueNow ? '<span class="pill sm bad">' . $dueNow . ' due</span>' : '' ?></h2><p>1st meeting and tax consultant meeting follow-up dates</p></div>
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
  <div class="panel-h"><h2>Follow-ups <?= $dueNow ? '<span class="pill sm bad">' . $dueNow . ' due</span>' : '' ?></h2><p>1st meeting and tax consultant meeting follow-up dates, all officers</p></div>
  <?php include APP . '/pages/_followups.php'; ?>
</section>
<?php endif;
require APP . '/pages/_quick_add.php';
page_end();
