<?php
declare(strict_types=1);
// Comprehensive Pending Data & Workload Breakdown Page (Admin & Officer)
// Shows every category of pending items by Officer, by Country, by Counselor, and Student-by-Student.

[$w, $p] = scope_sql($u);
$admin = is_admin_role($u['role']);
$base = $admin ? 'admin' : 'officer';

$rows = q("
    SELECT s.*,
           o.name AS officer_name,
           o.department AS officer_dept,
           fo.name AS fe_user_name
    FROM students s
    LEFT JOIN users o ON o.id = s.officer_id
    LEFT JOIN users fo ON fo.id = s.frontend_officer_id
    WHERE $w
    ORDER BY s.id ASC
", $p)->fetchAll();

// Load document counts per student from student_documents
$docStatsByStudent = [];
$docRows = q("
    SELECT student_id, doc_index, status, file_path
    FROM student_documents
")->fetchAll();

$coreDocIndices = [
    0 => 'Passport',
    1 => 'CNIC',
    2 => 'Matric',
    3 => 'Inter',
    4 => 'Bachelor/Transcript',
    5 => 'IELTS/Language',
    6 => 'CV',
    7 => 'Bank Statement',
    8 => 'Tax/FBR',
];

foreach ($docRows as $dr) {
    $sid = (int)$dr['student_id'];
    $idx = (int)$dr['doc_index'];
    $hasRealFile = !empty($dr['file_path']);
    if (!isset($docStatsByStudent[$sid])) {
        $docStatsByStudent[$sid] = [
            'received'     => 0,
            'pending'      => 0,
            'missing_core' => $coreDocIndices,
        ];
    }
    if ($hasRealFile && in_array($dr['status'], ['Received', 'Verified'], true)) {
        $docStatsByStudent[$sid]['received']++;
        unset($docStatsByStudent[$sid]['missing_core'][$idx]);
    } else {
        $docStatsByStudent[$sid]['pending']++;
    }
}

// Load university application counts per student
$appCountsByStudent = [];
foreach (q("SELECT student_id, COUNT(*) AS cnt FROM student_applications GROUP BY student_id")->fetchAll() as $ar) {
    $appCountsByStudent[(int)$ar['student_id']] = (int)$ar['cnt'];
}

// Helper to evaluate all pending categories for a student
function evaluate_student_pending(array $r, array $docStats, int $appCount): array {
    $country = trim((string)($r['country'] ?? 'Italy')) ?: 'Italy';
    $isItaly = strtolower($country) === 'italy';
    $level = strtoupper(trim((string)($r['program_level'] ?? 'MS')));

    // If BS student, Bachelor degree (doc_index 4) is not required
    $missingCore = $docStats['missing_core'] ?? [0 => 'Passport', 1 => 'CNIC', 2 => 'Matric', 3 => 'Inter', 5 => 'IELTS/Language', 6 => 'CV'];
    if ($level === 'BS') {
        unset($missingCore[4]);
    }

    $noteRaw = trim((string)($r['pending_docs_note'] ?? ''));
    $hasPendingNote = ($noteRaw !== '' && !in_array(strtolower($noteRaw), ['n/a', 'none', 'nil', 'complete', 'completed', 'all complete', 'done', '-'], true));
    $checklistDone = ($r['docs_checklist'] ?? '') === 'Yes';
    $receivedDocs = (int)($docStats['received'] ?? 0);
    $pendingDocsCount = (int)($docStats['pending'] ?? 12);

    // 1. Pending Documents
    $isDocsPending = !$checklistDone || $hasPendingNote || count($missingCore) > 0;

    // 2. Pending Consent
    $isConsentPending = ($r['consent'] ?? '') !== 'Yes';

    // 3. Pending Initial Meeting / Recording
    $isFirstMeetingPending = ($r['initial_meeting'] ?? '') !== 'Yes';
    $isRecordingPending = $isItaly && (($r['recorded_uploaded'] ?? '') !== 'Yes' && empty($r['meeting_link']));
    $isMeetingsPending = $isFirstMeetingPending || $isRecordingPending;

    // 4. Pending 2nd Meeting / Tax Meeting (primarily Italy)
    $isTaxPending = $isItaly && (($r['tax_meeting'] ?? '') !== 'Yes');
    $isSecondMeetingPending = $isItaly && (($r['second_meeting'] ?? '') !== 'Yes');
    $isSecondTaxPending = $isTaxPending || $isSecondMeetingPending;

    // 5. Pending Shortlisting / University Applications
    $shortRaw = strtolower(trim((string)($r['shortlisting'] ?? '')));
    $shortDone = ($shortRaw !== '' && !str_contains($shortRaw, 'pending') && $shortRaw !== 'no') || $appCount > 0;
    $isShortlistingPending = !$shortDone;

    // 6. Pending Portal / Email Credentials
    $isCredentialsPending = empty(trim((string)($r['email'] ?? ''))) || empty(trim((string)($r['portal_id'] ?? '')));

    // 7. Pending Staff Action / Remark
    $isRemarkPending = !empty($r['pending_since']) || empty(trim((string)($r['remarks'] ?? '')));

    $pendingItems = [];
    if ($isConsentPending)       $pendingItems[] = 'Consent Form';
    if (!$checklistDone)         $pendingItems[] = 'Docs Checklist';
    if ($hasPendingNote)         $pendingItems[] = 'Pending Docs Note';
    if ($isFirstMeetingPending)  $pendingItems[] = '1st Meeting';
    if ($isRecordingPending)     $pendingItems[] = 'Meeting Recording';
    if ($isTaxPending)           $pendingItems[] = 'Tax Meeting';
    if ($isSecondMeetingPending) $pendingItems[] = '2nd Meeting';
    if ($isShortlistingPending)  $pendingItems[] = 'Shortlisting / Uni Apps';
    if ($isCredentialsPending)   $pendingItems[] = 'Portal / Email ID';
    if (!empty($r['pending_since'])) $pendingItems[] = '48h Staff Remark Due';

    $hasAnyPending = !empty($pendingItems) || count($missingCore) > 0;

    return [
        'any'               => $hasAnyPending,
        'docs'              => $isDocsPending,
        'checklist_open'    => !$checklistDone,
        'has_doc_note'      => $hasPendingNote,
        'doc_note'          => $hasPendingNote ? $noteRaw : '',
        'received_docs'     => $receivedDocs,
        'pending_docs_cnt'  => $pendingDocsCount,
        'missing_core'      => array_values($missingCore),
        'consent'           => $isConsentPending,
        'meetings'          => $isMeetingsPending,
        'first_meeting'     => $isFirstMeetingPending,
        'recording'         => $isRecordingPending,
        'second_tax'        => $isSecondTaxPending,
        'tax_meeting'       => $isTaxPending,
        'second_meeting'    => $isSecondMeetingPending,
        'shortlisting'      => $isShortlistingPending,
        'credentials'       => $isCredentialsPending,
        'remarks'           => $isRemarkPending,
        'pending_timer'     => !empty($r['pending_since']),
        'items'             => $pendingItems,
        'app_count'         => $appCount,
    ];
}

// Compute pending analysis for all students in scope
$enriched = [];
$totals = [
    'students'       => count($rows),
    'any'            => 0,
    'docs'           => 0,
    'checklist_open' => 0,
    'has_doc_note'   => 0,
    'consent'        => 0,
    'meetings'       => 0,
    'second_tax'     => 0,
    'shortlisting'   => 0,
    'credentials'    => 0,
    'remarks'        => 0,
    'pending_timer'  => 0,
];

// Aggregations per Officer, per Country, per Counselor
$byOfficer = [];
$officersList = q("SELECT id, name, username, role, active, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department ASC, name ASC")->fetchAll();
foreach ($officersList as $o) {
    $oid = (int)$o['id'];
    $byOfficer[$oid] = [
        'id'             => $oid,
        'name'           => $o['name'],
        'department'     => $o['department'],
        'role'           => $o['role'],
        'countries'      => [],
        'total'          => 0,
        'any'            => 0,
        'consent'        => 0,
        'docs'           => 0,
        'checklist_open' => 0,
        'has_doc_note'   => 0,
        'missing_files'  => 0,
        'first_meeting'  => 0,
        'recording'      => 0,
        'second_tax'     => 0,
        'shortlisting'   => 0,
        'credentials'    => 0,
        'remarks'        => 0,
    ];
}

$byCountry = [];
$byCounselor = [];

foreach ($rows as $r) {
    $sid = (int)$r['id'];
    $dStats = $docStatsByStudent[$sid] ?? ['received' => 0, 'pending' => 12, 'missing_core' => $coreDocIndices];
    $appCnt = $appCountsByStudent[$sid] ?? 0;
    $pInfo = evaluate_student_pending($r, $dStats, $appCnt);

    $r['_pending'] = $pInfo;
    $enriched[] = $r;

    if ($pInfo['any'])            $totals['any']++;
    if ($pInfo['docs'])           $totals['docs']++;
    if ($pInfo['checklist_open']) $totals['checklist_open']++;
    if ($pInfo['has_doc_note'])   $totals['has_doc_note']++;
    if ($pInfo['consent'])        $totals['consent']++;
    if ($pInfo['meetings'])       $totals['meetings']++;
    if ($pInfo['second_tax'])     $totals['second_tax']++;
    if ($pInfo['shortlisting'])   $totals['shortlisting']++;
    if ($pInfo['credentials'])    $totals['credentials']++;
    if ($pInfo['remarks'])        $totals['remarks']++;
    if ($pInfo['pending_timer'])  $totals['pending_timer']++;

    // Officer aggregation (credit both Backend officer_id and Frontend frontend_officer_id)
    $cName = trim((string)($r['country'] ?? 'Italy')) ?: 'Italy';
    $assignedOids = array_unique(array_filter([(int)($r['officer_id'] ?? 0), (int)($r['frontend_officer_id'] ?? 0)]));
    foreach ($assignedOids as $oid) {
        if (!isset($byOfficer[$oid])) continue;
        $byOfficer[$oid]['total']++;
        $byOfficer[$oid]['countries'][$cName] = true;
        if ($pInfo['any'])            $byOfficer[$oid]['any']++;
        if ($pInfo['consent'])        $byOfficer[$oid]['consent']++;
        if ($pInfo['docs'])           $byOfficer[$oid]['docs']++;
        if ($pInfo['checklist_open']) $byOfficer[$oid]['checklist_open']++;
        if ($pInfo['has_doc_note'])   $byOfficer[$oid]['has_doc_note']++;
        $byOfficer[$oid]['missing_files'] += count($pInfo['missing_core']);
        if ($pInfo['first_meeting'])  $byOfficer[$oid]['first_meeting']++;
        if ($pInfo['recording'])      $byOfficer[$oid]['recording']++;
        if ($pInfo['second_tax'])     $byOfficer[$oid]['second_tax']++;
        if ($pInfo['shortlisting'])   $byOfficer[$oid]['shortlisting']++;
        if ($pInfo['credentials'])    $byOfficer[$oid]['credentials']++;
        if ($pInfo['remarks'])        $byOfficer[$oid]['remarks']++;
    }

    // Country aggregation
    if (!isset($byCountry[$cName])) {
        $byCountry[$cName] = [
            'country'        => $cName,
            'total'          => 0,
            'any'            => 0,
            'consent'        => 0,
            'docs'           => 0,
            'checklist_open' => 0,
            'has_doc_note'   => 0,
            'missing_files'  => 0,
            'meetings'       => 0,
            'second_tax'     => 0,
            'shortlisting'   => 0,
            'credentials'    => 0,
            'remarks'        => 0,
        ];
    }
    $byCountry[$cName]['total']++;
    if ($pInfo['any'])            $byCountry[$cName]['any']++;
    if ($pInfo['consent'])        $byCountry[$cName]['consent']++;
    if ($pInfo['docs'])           $byCountry[$cName]['docs']++;
    if ($pInfo['checklist_open']) $byCountry[$cName]['checklist_open']++;
    if ($pInfo['has_doc_note'])   $byCountry[$cName]['has_doc_note']++;
    $byCountry[$cName]['missing_files'] += count($pInfo['missing_core']);
    if ($pInfo['meetings'])       $byCountry[$cName]['meetings']++;
    if ($pInfo['second_tax'])     $byCountry[$cName]['second_tax']++;
    if ($pInfo['shortlisting'])   $byCountry[$cName]['shortlisting']++;
    if ($pInfo['credentials'])    $byCountry[$cName]['credentials']++;
    if ($pInfo['remarks'])        $byCountry[$cName]['remarks']++;

    // Counselor aggregation
    $counName = trim((string)($r['counselor'] ?? '')) ?: 'Unassigned';
    if (!isset($byCounselor[$counName])) {
        $byCounselor[$counName] = [
            'counselor'      => $counName,
            'countries'      => [],
            'total'          => 0,
            'any'            => 0,
            'consent'        => 0,
            'docs'           => 0,
            'has_doc_note'   => 0,
            'meetings'       => 0,
            'shortlisting'   => 0,
            'remarks'        => 0,
        ];
    }
    $byCounselor[$counName]['total']++;
    $byCounselor[$counName]['countries'][$cName] = true;
    if ($pInfo['any'])          $byCounselor[$counName]['any']++;
    if ($pInfo['consent'])      $byCounselor[$counName]['consent']++;
    if ($pInfo['docs'])         $byCounselor[$counName]['docs']++;
    if ($pInfo['has_doc_note']) $byCounselor[$counName]['has_doc_note']++;
    if ($pInfo['meetings'])     $byCounselor[$counName]['meetings']++;
    if ($pInfo['shortlisting']) $byCounselor[$counName]['shortlisting']++;
    if ($pInfo['remarks'])      $byCounselor[$counName]['remarks']++;
}

uasort($byCountry, fn($a, $b) => $b['total'] <=> $a['total']);
uasort($byCounselor, fn($a, $b) => $b['total'] <=> $a['total']);

// Active Filters
$catFilter       = strtolower(trim((string)(get('cat') ?: 'all')));
$countryFilter   = trim((string)(get('country') ?: ''));
$officerFilter   = (int)(get('officer') ?: 0);
$counselorFilter = trim((string)(get('counselor') ?: ''));
$levelFilter     = strtoupper(trim((string)(get('program_level') ?: '')));
$qFilter         = trim((string)(get('q') ?: ''));

$filteredStudents = array_filter($enriched, function (array $r) use ($catFilter, $countryFilter, $officerFilter, $counselorFilter, $levelFilter, $qFilter): bool {
    $p = $r['_pending'];
    $matchCat = match ($catFilter) {
        'docs'           => $p['docs'],
        'checklist_open' => $p['checklist_open'],
        'doc_note'       => $p['has_doc_note'],
        'consent'        => $p['consent'],
        'meetings'       => $p['meetings'],
        'first_meeting'  => $p['first_meeting'],
        'recording'      => $p['recording'],
        'second_tax'     => $p['second_tax'],
        'shortlisting'   => $p['shortlisting'],
        'credentials'    => $p['credentials'],
        'remarks'        => $p['remarks'],
        'timer'          => $p['pending_timer'],
        default          => $p['any'],
    };
    if (!$matchCat) return false;

    if ($countryFilter !== '' && strcasecmp((string)($r['country'] ?? ''), $countryFilter) !== 0) {
        return false;
    }
    if ($officerFilter > 0 && (int)($r['officer_id'] ?? 0) !== $officerFilter && (int)($r['frontend_officer_id'] ?? 0) !== $officerFilter) {
        return false;
    }
    if ($counselorFilter !== '' && strcasecmp(trim((string)($r['counselor'] ?? '')), $counselorFilter) !== 0) {
        return false;
    }
    if ($levelFilter !== '' && strcasecmp((string)($r['program_level'] ?? ''), $levelFilter) !== 0) {
        return false;
    }
    if ($qFilter !== '') {
        $hay = strtolower(($r['name'] ?? '') . ' ' . ($r['email'] ?? '') . ' ' . ($r['pending_docs_note'] ?? '') . ' ' . ($r['remarks'] ?? '') . ' ' . ($r['counselor'] ?? ''));
        if (!str_contains($hay, strtolower($qFilter))) return false;
    }
    return true;
});

function pending_filter_url(string $base, array $overrides = []): string {
    $params = [
        'cat'           => get('cat') ?: 'all',
        'country'       => get('country') ?: '',
        'officer'       => get('officer') ?: '',
        'counselor'     => get('counselor') ?: '',
        'program_level' => get('program_level') ?: '',
        'q'             => get('q') ?: '',
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    $clean = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return url($base . '/pending.php' . ($clean ? '?' . http_build_query($clean) : ''));
}

$flagMap = ['Italy' => '🇮🇹', 'France' => '🇫🇷', 'Germany' => '🇩🇪', 'Czech Republic' => '🇨🇿', 'Austria' => '🇦🇹', 'China' => '🇨🇳'];

page_start('Pending Items & Workload Breakdown', $u, PORTALS[$u['role']]['label']);
?>

<div class="panel-h" style="margin-bottom:1rem;">
  <div>
    <h1>⏳ Complete Pending Tracker (Kis Ka Kitna Pending Ha)</h1>
    <p>Detailed breakdown of all pending documents, consents, meetings, university shortlisting, portal credentials, and remarks across every Officer, Country, Counselor, and Student.</p>
  </div>
  <div class="row-actions">
    <a class="btn sm ghost" href="<?= h(url($base . '/index.php')) ?>">&larr; Back to Dashboard</a>
    <a class="btn sm primary" href="<?= h(url($base . '/students.php')) ?>">Open All Students Table &rarr;</a>
  </div>
</div>

<!-- 1. TOP PENDING CATEGORY CARDS (CLICK TO FILTER) -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(185px, 1fr)); gap:0.75rem; margin-bottom:1.35rem;">
  <?php
  $catCards = [
      'all'          => ['label' => 'All Pending Cases',        'val' => $totals['any'],            'sub' => 'Out of ' . $totals['students'] . ' total students', 'color' => 'var(--accent)'],
      'docs'         => ['label' => 'Pending Documents',        'val' => $totals['docs'],           'sub' => $totals['has_doc_note'] . ' with specific doc notes', 'color' => '#ef4444'],
      'consent'      => ['label' => 'Pending Consent Form',     'val' => $totals['consent'],        'sub' => 'Consent not marked Yes', 'color' => '#f59e0b'],
      'meetings'     => ['label' => 'Pending 1st / Recording',  'val' => $totals['meetings'],       'sub' => 'Initial meeting or video open', 'color' => '#3b82f6'],
      'second_tax'   => ['label' => 'Pending 2nd / Tax Meet',   'val' => $totals['second_tax'],     'sub' => '2nd consultation or Tax open', 'color' => '#8b5cf6'],
      'shortlisting' => ['label' => 'Pending Shortlisting/Apps','val' => $totals['shortlisting'],   'sub' => 'University shortlisting open', 'color' => '#ec4899'],
      'credentials'  => ['label' => 'Pending Portal / Email',   'val' => $totals['credentials'],    'sub' => 'Email or Portal ID missing', 'color' => '#14b8a6'],
      'remarks'      => ['label' => 'Pending Remarks / Action', 'val' => $totals['remarks'],        'sub' => $totals['pending_timer'] . ' active 48h timer', 'color' => '#f97316'],
  ];
  foreach ($catCards as $cKey => $card):
      $isActive = ($catFilter === $cKey);
  ?>
    <a href="<?= h(pending_filter_url($base, ['cat' => $cKey]) . '#student-pending-list') ?>"
       style="text-decoration:none; color:inherit; background:<?= $isActive ? 'var(--sunk)' : 'var(--surface)' ?>; border:<?= $isActive ? '2px solid ' . $card['color'] : '1.5px solid var(--border)' ?>; border-left:4px solid <?= $card['color'] ?>; border-radius:8px; padding:0.85rem 1rem; display:flex; flex-direction:column; gap:0.2rem;">
      <div style="font-size:0.74rem; font-weight:700; text-transform:uppercase; color:var(--muted);"><?= h($card['label']) ?></div>
      <div style="font-size:1.75rem; font-weight:700; color:<?= $card['color'] ?>; line-height:1.15;"><?= (int)$card['val'] ?></div>
      <div class="small muted"><?= h($card['sub']) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($admin || $u['role'] === 'pms_lead'): ?>
<!-- 2. OFFICER-WISE PENDING BREAKDOWN (KIS OFFICER KA KITNA PENDING HA) -->
<section class="panel" style="margin-bottom:1.35rem;">
  <div class="panel-h">
    <div>
      <h2>👩‍💼 Pending Breakdown per Processing Officer (Front-End &amp; Back-End)</h2>
      <p>Exact pending counts for each officer. Click any number in the table to list those exact pending students below.</p>
    </div>
    <?php if ($officerFilter > 0): ?>
      <div class="row-actions">
        <a class="btn sm ghost" href="<?= h(pending_filter_url($base, ['officer' => ''])) ?>">Clear Officer Filter ✕</a>
      </div>
    <?php endif; ?>
  </div>
  <div class="tbl"><table>
    <thead>
      <tr>
        <th>Processing Officer</th>
        <th>Team &amp; Countries</th>
        <th class="num">Assigned</th>
        <th class="num" title="Students with any pending item">Total Pending</th>
        <th class="num" title="Consent != Yes">Pending Consent</th>
        <th class="num" title="Docs Checklist != Yes">Checklist Open</th>
        <th class="num" title="Students with specific Pending Docs Note in sheet">Pending Docs Note</th>
        <th class="num" title="Total missing core document files in Google Drive">Missing Drive Files</th>
        <th class="num" title="Initial Meeting != Yes">Pending 1st Meet</th>
        <th class="num" title="Recorded Video not uploaded">Pending Recording</th>
        <th class="num" title="2nd Meeting or Tax Meeting open">Pending 2nd / Tax</th>
        <th class="num" title="Shortlisting or University Applications pending">Pending Shortlist</th>
        <th class="num" title="Missing Remarks or 48h Pending Timer">Pending Remarks</th>
        <th>Filter</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($byOfficer as $oid => $st):
          if ($st['total'] === 0) continue;
          $isSelectedOff = ($officerFilter === $oid);
          $cList = implode(', ', array_keys($st['countries']));
      ?>
        <tr style="<?= $isSelectedOff ? 'background:rgba(59,130,246,0.08);' : '' ?>">
          <td>
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'all']) . '#student-pending-list') ?>">
              <b><?= h($st['name']) ?></b>
            </a>
          </td>
          <td>
            <span class="pill sm <?= $st['department'] === 'frontend' ? 'acc' : '' ?>"><?= $st['department'] === 'frontend' ? 'Front-End' : 'Back-End' ?></span>
            <span class="small muted" style="margin-left:0.25rem;"><?= h($cList) ?></span>
          </td>
          <td class="num"><b><?= $st['total'] ?></b></td>
          <td class="num">
            <a class="pill sm <?= $st['any'] > 0 ? 'bad' : 'ok' ?>" href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'all']) . '#student-pending-list') ?>" style="text-decoration:none;">
              <?= $st['any'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'consent']) . '#student-pending-list') ?>" style="color:<?= $st['consent'] > 0 ? 'var(--warn, #f59e0b)' : 'var(--muted)' ?>; font-weight:<?= $st['consent'] > 0 ? '700' : '400' ?>;">
              <?= $st['consent'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'checklist_open']) . '#student-pending-list') ?>" style="color:<?= $st['checklist_open'] > 0 ? 'var(--bad, #ef4444)' : 'var(--muted)' ?>; font-weight:<?= $st['checklist_open'] > 0 ? '700' : '400' ?>;">
              <?= $st['checklist_open'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'doc_note']) . '#student-pending-list') ?>" style="color:<?= $st['has_doc_note'] > 0 ? 'var(--bad, #ef4444)' : 'var(--muted)' ?>; font-weight:<?= $st['has_doc_note'] > 0 ? '700' : '400' ?>;">
              <?= $st['has_doc_note'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'docs']) . '#student-pending-list') ?>" title="Total missing core documents across assigned students">
              <span class="pill sm <?= $st['missing_files'] > 0 ? 'warn' : 'ok' ?>"><?= $st['missing_files'] ?> files</span>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'first_meeting']) . '#student-pending-list') ?>" style="font-weight:<?= $st['first_meeting'] > 0 ? '700' : '400' ?>;">
              <?= $st['first_meeting'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'recording']) . '#student-pending-list') ?>" style="font-weight:<?= $st['recording'] > 0 ? '700' : '400' ?>;">
              <?= $st['recording'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'second_tax']) . '#student-pending-list') ?>" style="font-weight:<?= $st['second_tax'] > 0 ? '700' : '400' ?>;">
              <?= $st['second_tax'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'shortlisting']) . '#student-pending-list') ?>" style="font-weight:<?= $st['shortlisting'] > 0 ? '700' : '400' ?>;">
              <?= $st['shortlisting'] ?>
            </a>
          </td>
          <td class="num">
            <a href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'remarks']) . '#student-pending-list') ?>" style="color:<?= $st['remarks'] > 0 ? 'var(--bad, #ef4444)' : 'var(--muted)' ?>; font-weight:<?= $st['remarks'] > 0 ? '700' : '400' ?>;">
              <?= $st['remarks'] ?>
            </a>
          </td>
          <td>
            <a class="btn sm <?= $isSelectedOff ? 'primary' : '' ?>" href="<?= h(pending_filter_url($base, ['officer' => $oid, 'cat' => 'all']) . '#student-pending-list') ?>">
              View Pending &rarr;
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<!-- 3. COUNTRY-WISE & COUNSELOR-WISE PENDING BREAKDOWN -->
<div class="grid2" style="margin-bottom:1.35rem;">
  <!-- Country Breakdown -->
  <section class="panel">
    <div class="panel-h">
      <div>
        <h2>🌍 Pending by Country</h2>
        <p>Click any country or number to filter the detailed pending list</p>
      </div>
      <?php if ($countryFilter !== ''): ?>
        <a class="btn sm ghost" href="<?= h(pending_filter_url($base, ['country' => ''])) ?>">All Countries ✕</a>
      <?php endif; ?>
    </div>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th>Country</th>
          <th class="num">Total</th>
          <th class="num">Pending Docs</th>
          <th class="num">Consent</th>
          <th class="num">Meetings</th>
          <th class="num">Shortlist</th>
          <th class="num">Portal ID</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($byCountry as $cName => $cst):
            $flag = $flagMap[$cName] ?? '📍';
            $isSelC = (strcasecmp($countryFilter, $cName) === 0);
        ?>
          <tr style="<?= $isSelC ? 'background:rgba(59,130,246,0.08);' : '' ?>">
            <td>
              <a href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'all']) . '#student-pending-list') ?>">
                <b><?= $flag ?> <?= h($cName) ?></b>
              </a>
            </td>
            <td class="num"><b><?= $cst['total'] ?></b></td>
            <td class="num">
              <a class="pill sm <?= $cst['docs'] > 0 ? 'bad' : 'ok' ?>" href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'docs']) . '#student-pending-list') ?>" style="text-decoration:none;">
                <?= $cst['docs'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'consent']) . '#student-pending-list') ?>">
                <?= $cst['consent'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'meetings']) . '#student-pending-list') ?>">
                <?= $cst['meetings'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'shortlisting']) . '#student-pending-list') ?>">
                <?= $cst['shortlisting'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['country' => $cName, 'cat' => 'credentials']) . '#student-pending-list') ?>">
                <?= $cst['credentials'] ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <!-- Counselor Breakdown -->
  <section class="panel">
    <div class="panel-h">
      <div>
        <h2>🧑‍💼 Pending by Counselor</h2>
        <p>Whose students have pending consents, documents, or meetings</p>
      </div>
      <?php if ($counselorFilter !== ''): ?>
        <a class="btn sm ghost" href="<?= h(pending_filter_url($base, ['counselor' => ''])) ?>">All Counselors ✕</a>
      <?php endif; ?>
    </div>
    <div class="tbl" style="max-height:340px; overflow-y:auto;"><table>
      <thead>
        <tr>
          <th>Counselor</th>
          <th class="num">Students</th>
          <th class="num">Pending Consent</th>
          <th class="num">Pending Docs</th>
          <th class="num">Doc Notes</th>
          <th class="num">Pending Meet</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($byCounselor as $counName => $cnSt):
            $isSelCn = (strcasecmp($counselorFilter, $counName) === 0);
        ?>
          <tr style="<?= $isSelCn ? 'background:rgba(59,130,246,0.08);' : '' ?>">
            <td>
              <a href="<?= h(pending_filter_url($base, ['counselor' => $counName, 'cat' => 'all']) . '#student-pending-list') ?>">
                <b><?= h($counName) ?></b>
              </a>
              <div class="small muted"><?= h(implode(', ', array_keys($cnSt['countries']))) ?></div>
            </td>
            <td class="num"><b><?= $cnSt['total'] ?></b></td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['counselor' => $counName, 'cat' => 'consent']) . '#student-pending-list') ?>" style="color:<?= $cnSt['consent'] > 0 ? 'var(--warn, #f59e0b)' : 'var(--muted)' ?>; font-weight:<?= $cnSt['consent'] > 0 ? '700' : '400' ?>;">
                <?= $cnSt['consent'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['counselor' => $counName, 'cat' => 'docs']) . '#student-pending-list') ?>" style="color:<?= $cnSt['docs'] > 0 ? 'var(--bad, #ef4444)' : 'var(--muted)' ?>; font-weight:<?= $cnSt['docs'] > 0 ? '700' : '400' ?>;">
                <?= $cnSt['docs'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['counselor' => $counName, 'cat' => 'doc_note']) . '#student-pending-list') ?>">
                <?= $cnSt['has_doc_note'] ?>
              </a>
            </td>
            <td class="num">
              <a href="<?= h(pending_filter_url($base, ['counselor' => $counName, 'cat' => 'meetings']) . '#student-pending-list') ?>">
                <?= $cnSt['meetings'] ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
</div>

<!-- 4. DETAILED STUDENT-BY-STUDENT PENDING LIST -->
<section class="panel" id="student-pending-list">
  <div class="panel-h" style="flex-wrap:wrap; gap:0.75rem;">
    <div>
      <h2>📋 Detailed Student-by-Student Pending List (Showing <?= count($filteredStudents) ?> students)</h2>
      <p>Exact missing documents, sheet pending notes, and open workflow stages for every matching student</p>
    </div>
    <form method="get" action="<?= h(url($base . '/pending.php')) ?>#student-pending-list" style="display:flex; gap:0.45rem; flex-wrap:wrap; align-items:center;">
      <select name="cat" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.55rem;">
        <option value="all" <?= $catFilter === 'all' ? 'selected' : '' ?>>All Pending Categories (<?= $totals['any'] ?>)</option>
        <option value="docs" <?= $catFilter === 'docs' ? 'selected' : '' ?>>Pending Documents (<?= $totals['docs'] ?>)</option>
        <option value="doc_note" <?= $catFilter === 'doc_note' ? 'selected' : '' ?>>Has Pending Docs Note (<?= $totals['has_doc_note'] ?>)</option>
        <option value="checklist_open" <?= $catFilter === 'checklist_open' ? 'selected' : '' ?>>Docs Checklist Not Done (<?= $totals['checklist_open'] ?>)</option>
        <option value="consent" <?= $catFilter === 'consent' ? 'selected' : '' ?>>Pending Consent Form (<?= $totals['consent'] ?>)</option>
        <option value="meetings" <?= $catFilter === 'meetings' ? 'selected' : '' ?>>Pending 1st Meeting / Recording (<?= $totals['meetings'] ?>)</option>
        <option value="second_tax" <?= $catFilter === 'second_tax' ? 'selected' : '' ?>>Pending 2nd / Tax Meeting (<?= $totals['second_tax'] ?>)</option>
        <option value="shortlisting" <?= $catFilter === 'shortlisting' ? 'selected' : '' ?>>Pending Shortlisting / Apps (<?= $totals['shortlisting'] ?>)</option>
        <option value="credentials" <?= $catFilter === 'credentials' ? 'selected' : '' ?>>Pending Portal / Email ID (<?= $totals['credentials'] ?>)</option>
        <option value="remarks" <?= $catFilter === 'remarks' ? 'selected' : '' ?>>Pending Remarks / 48h Action (<?= $totals['remarks'] ?>)</option>
      </select>

      <select name="country" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.55rem;">
        <option value="">All Countries</option>
        <?php foreach (array_keys($byCountry) as $cOpt): ?>
          <option value="<?= h($cOpt) ?>" <?= strcasecmp($countryFilter, $cOpt) === 0 ? 'selected' : '' ?>><?= h($cOpt) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if ($admin || $u['role'] === 'pms_lead'): ?>
      <select name="officer" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.55rem;">
        <option value="">All Processing Officers</option>
        <?php foreach ($byOfficer as $oid => $oOpt): if ($oOpt['total'] === 0) continue; ?>
          <option value="<?= $oid ?>" <?= $officerFilter === $oid ? 'selected' : '' ?>><?= h($oOpt['name']) ?> (<?= $oOpt['department'] === 'frontend' ? 'FE' : 'BE' ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <select name="counselor" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.55rem;">
        <option value="">All Counselors</option>
        <?php foreach (array_keys($byCounselor) as $cnOpt): ?>
          <option value="<?= h($cnOpt) ?>" <?= strcasecmp($counselorFilter, $cnOpt) === 0 ? 'selected' : '' ?>><?= h($cnOpt) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="program_level" onchange="this.form.submit();" style="font-size:0.82rem; padding:0.35rem 0.55rem;">
        <option value="">BS &amp; MS</option>
        <option value="BS" <?= $levelFilter === 'BS' ? 'selected' : '' ?>>BS Only</option>
        <option value="MS" <?= $levelFilter === 'MS' ? 'selected' : '' ?>>MS Only</option>
      </select>

      <input type="text" name="q" value="<?= h($qFilter) ?>" placeholder="Search student or pending doc..." style="font-size:0.82rem; padding:0.35rem 0.6rem; width:190px;">
      <button type="submit" class="btn sm primary">Filter</button>
      <?php if ($catFilter !== 'all' || $countryFilter !== '' || $officerFilter > 0 || $counselorFilter !== '' || $levelFilter !== '' || $qFilter !== ''): ?>
        <a class="btn sm ghost" href="<?= h(url($base . '/pending.php')) ?>">Reset All</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($filteredStudents)): ?>
    <div class="empty">No students match the selected pending filter criteria.</div>
  <?php else: ?>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th style="width:50px;">SR#</th>
          <th>Student &amp; Country</th>
          <th>Assigned Team (BE / FE / Counselor)</th>
          <th>What Stages Are Pending?</th>
          <th>What Documents Are Pending? (Sheet Note &amp; Drive Files)</th>
          <th>Latest Remark</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filteredStudents as $r):
            $p = $r['_pending'];
            $cName = trim((string)($r['country'] ?? 'Italy')) ?: 'Italy';
            $flag = $flagMap[$cName] ?? '📍';
            $dLink = safe_link($r['drive_link'] ?? null);
        ?>
          <tr>
            <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
            <td>
              <a href="<?= h(url($base . '/student.php?id=' . (int)$r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
              <div style="display:flex; gap:0.25rem; flex-wrap:wrap; margin-top:0.25rem;">
                <span class="pill sm"><?= $flag ?> <?= h($cName) ?></span>
                <?php if (!empty($r['program_level'])): ?>
                  <span class="pill sm acc"><?= h($r['program_level']) ?></span>
                <?php endif; ?>
                <?php if (($r['student_status'] ?? 'active') !== 'active'): ?>
                  <span class="pill sm bad"><?= h(ucfirst((string)$r['student_status'])) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="small">
              <div><span class="muted">BE Officer:</span> <b><?= h($r['officer_name'] ?? 'Unassigned') ?></b></div>
              <?php if (!empty($r['frontend_officer_name']) || !empty($r['fe_user_name'])): ?>
                <div><span class="muted">FE Officer:</span> <b><?= h($r['frontend_officer_name'] ?: $r['fe_user_name']) ?></b></div>
              <?php endif; ?>
              <div><span class="muted">Counselor:</span> <b><?= h($r['counselor'] ?: 'Unassigned') ?></b></div>
            </td>
            <td>
              <div style="display:flex; gap:0.25rem; flex-wrap:wrap; max-width:290px;">
                <?php foreach ($p['items'] as $itemLabel):
                    $pillClass = (str_contains($itemLabel, 'Docs') || str_contains($itemLabel, '48h')) ? 'bad' : 'warn';
                ?>
                  <span class="pill sm <?= $pillClass ?>"><?= h($itemLabel) ?></span>
                <?php endforeach; ?>
                <?php if (empty($p['items'])): ?>
                  <span class="pill sm ok">Stages Done</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="max-width:340px;">
              <?php if ($p['has_doc_note']): ?>
                <div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.35); border-radius:6px; padding:0.35rem 0.55rem; font-size:0.78rem; margin-bottom:0.35rem;">
                  <b style="color:var(--bad, #ef4444);">Sheet Pending Note:</b> <?= h($p['doc_note']) ?>
                </div>
              <?php endif; ?>
              <div class="small">
                <span class="pill sm <?= $p['received_docs'] >= 5 ? 'ok' : ($p['received_docs'] > 0 ? 'warn' : 'bad') ?>">
                  📁 <?= $p['received_docs'] ?> Received
                </span>
                <?php if (!empty($p['missing_core'])): ?>
                  <span class="muted" style="display:block; margin-top:0.2rem;">
                    <b>Missing in Drive:</b> <?= h(implode(', ', $p['missing_core'])) ?>
                  </span>
                <?php else: ?>
                  <span class="muted" style="margin-left:0.25rem;">All core files in Drive ✓</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="small" style="max-width:230px;">
              <?php if (!empty($r['remarks'])): ?>
                <div style="white-space:normal; line-height:1.35;"><?= h(mb_strimwidth((string)$r['remarks'], 0, 110, '...')) ?></div>
              <?php else: ?>
                <span class="muted">No remarks added</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <div style="display:flex; gap:0.3rem; justify-content:flex-end; align-items:center;">
                <?php if ($dLink): ?>
                  <a class="btn sm" href="<?= h($dLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Google Drive Folder">📁 Drive</a>
                <?php endif; ?>
                <a class="btn sm primary" href="<?= h(url($base . '/student.php?id=' . (int)$r['id'])) ?>">Open &amp; Fix &rarr;</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php page_end(); ?>
