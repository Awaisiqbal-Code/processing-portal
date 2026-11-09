<?php
declare(strict_types=1);
// Shared student list for admin, pms_lead, processing_staff, and counselor. Expects $u.

$admin = is_admin_role($u['role']);
$canManage = can_manage_student_lifecycle($u);
$base = $admin ? 'admin' : 'officer';
[$w, $p] = scope_sql($u);

// ---------- Handle Bulk Edit Action (Super Admin, Admin, PMS Lead only) ----------
if (is_post() && post('action') === 'bulk_edit') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        flash('Permission denied: Only Admin and PMS Lead can perform bulk edits.', 'bad');
        redirect($base . '/students.php');
    }

    $selectedIds = $_POST['selected_ids'] ?? [];
    if (!is_array($selectedIds)) $selectedIds = [];
    $bProg = post('bulk_program_level');
    $bCountry = post('bulk_country');

    if ($bProg === '' && $bCountry === '') {
        flash('Please specify a Program Level or Country to apply to the selected students.', 'warn');
    } else {
        $res = bulk_update_students(
            $u,
            array_map('intval', $selectedIds),
            $bProg !== '' ? $bProg : null,
            $bCountry !== '' ? $bCountry : null
        );
        if ($res['ok']) {
            flash("Bulk updated {$res['updated_count']} students.");
        } else {
            flash($res['error'], 'bad');
        }
    }
    $returnQs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    redirect($base . '/students.php' . $returnQs);
}

// ---------- Quick registration (Add Student modal) ----------
if (is_post() && post('action') === 'quick_add') {
    csrf_check();
    $res = quick_register_student($u, $_POST);
    if ($res['ok']) {
        flash('Student registered. Their profile is ready — add documents and details below.');
        redirect($base . '/student.php?id=' . (int)$res['id'] . '&created=1');
    }
    $old = array_intersect_key($_POST, array_flip(['name', 'first_name', 'last_name', 'contact_number', 'email', 'country', 'program_level', 'intake', 'applied_for', 'branch', 'received_date', 'assigned_counselor_id', 'frontend_officer_id', 'officer_id', 'first_note']));
    if (!empty($res['duplicate_id'])) $old['_duplicate_id'] = (int)$res['duplicate_id'];
    $_SESSION['quick_add_old'] = $old;
    flash($res['error'] ?? 'Could not register the student.', 'bad');
    redirect($base . '/students.php?add=1');
}

// ---------- Tab Counts for Current Scope ----------
$cntAll = (int)q("SELECT COUNT(*) FROM students s WHERE $w", $p)->fetchColumn();
$cntBs = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND s.program_level = 'BS'", $p)->fetchColumn();
$cntMs = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND s.program_level = 'MS'", $p)->fetchColumn();
$cntInc = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND (s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '')", $p)->fetchColumn();

$countryCounts = [];
foreach (q("SELECT COALESCE(NULLIF(TRIM(s.country), ''), 'Not set') AS c_name, COUNT(*) AS cnt FROM students s WHERE $w GROUP BY c_name ORDER BY cnt DESC", $p)->fetchAll() as $cRow) {
    $countryCounts[$cRow['c_name']] = (int)$cRow['cnt'];
}

// ---------- Request Filters & Parameters ----------
$tab = strtolower(get('tab') ?: 'all');
$fq = get('q');
$fo = get('officer');
$fs = get('stage');
$fr = get('refund');
$fk = get('kpi');
$fd = get('done_stage');
$fc = get('country');
$fst = get('status');
$fv = get('verified');
$fref = get('refunded');
$fp = get('pending');
$finc = get('incomplete');
$fcounselor = get('counselor');
$fpms = get('pms_lead');
$sort = get('sort') ?: 'default';
$fps = get('pstatus');
$view = get('view') === 'cards' ? 'cards' : (get('view') === 'table' ? 'table' : (($_COOKIE['ptp_students_view'] ?? '') === 'cards' ? 'cards' : 'table'));
$canFilterOfficer = $admin || $canManage;

$where = [$w];
$params = $p;

// Tab condition
if ($tab === 'bs') {
    $where[] = "s.program_level = 'BS'";
} elseif ($tab === 'ms') {
    $where[] = "s.program_level = 'MS'";
} elseif ($tab === 'incomplete') {
    $where[] = "(s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '')";
}

// Search
if ($fq !== '') {
    $where[] = '(s.name LIKE ? OR s.counselor LIKE ? OR s.remarks LIKE ? OR s.email LIKE ? OR CAST(s.id AS TEXT) LIKE ? OR CAST(s.sheet_sr AS TEXT) LIKE ?)';
    $like = '%' . $fq . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

// Country filter
if ($fc !== '') {
    if ($fc === 'none') {
        $where[] = "(s.country IS NULL OR s.country = '')";
    } else {
        $where[] = "LOWER(TRIM(s.country)) = LOWER(TRIM(?))";
        $params[] = $fc;
    }
}

// Status filter
if ($fst !== '' && in_array($fst, ['active', 'inactive', 'disabled'], true)) {
    $where[] = "s.student_status = ?";
    $params[] = $fst;
}

// Verification filter
if ($fv === '1') {
    $where[] = "(s.csc_status = 'Verified' OR s.csc_verified_at IS NOT NULL)";
} elseif ($fv === '0') {
    $where[] = "((s.csc_status != 'Verified' OR s.csc_status IS NULL) AND s.csc_verified_at IS NULL)";
}

// Refunded filter
if ($fref === '1') {
    $where[] = "s.is_refunded = 1";
} elseif ($fref === '0') {
    $where[] = "(s.is_refunded = 0 OR s.is_refunded IS NULL)";
}

// Pending filter
if ($fp === '1') {
    $where[] = "s.pending_since IS NOT NULL";
} elseif ($fp === '0') {
    $where[] = "s.pending_since IS NULL";
}

// Incomplete filter
if ($finc === '1') {
    $where[] = "(s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '')";
} elseif ($finc === '0') {
    $where[] = "(s.program_level IS NOT NULL AND s.program_level != '' AND s.country IS NOT NULL AND s.country != '')";
}

// Counselor filter
if ($fcounselor !== '') {
    if (ctype_digit((string)$fcounselor)) {
        $cName = q("SELECT name FROM users WHERE id = ?", [(int)$fcounselor])->fetchColumn();
        if ($cName) {
            $where[] = "(s.assigned_counselor_id = ? OR LOWER(TRIM(s.counselor)) = LOWER(TRIM(?)))";
            array_push($params, (int)$fcounselor, (string)$cName);
        } else {
            $where[] = "s.assigned_counselor_id = ?";
            $params[] = (int)$fcounselor;
        }
    } else {
        $where[] = "(LOWER(TRIM(s.counselor)) = LOWER(TRIM(?)) OR s.assigned_counselor_id IN (SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND role = 'counselor'))";
        array_push($params, $fcounselor, $fcounselor);
    }
}

// PMS Lead filter
if ($fpms !== '') {
    $where[] = "s.assigned_pms_lead_id = ?";
    $params[] = (int)$fpms;
}

// Officer filter
if ($canFilterOfficer && $fo !== '') {
    if ($fo === 'none') {
        $where[] = '(s.officer_id IS NULL OR s.frontend_officer_id IS NULL)';
    } else {
        $where[] = '(s.officer_id = ? OR s.frontend_officer_id = ?)';
        array_push($params, (int)$fo, (int)$fo);
    }
}

// Legacy refund tier filter
if ($fr !== '') {
    $where[] = 's.refund = ?';
    $params[] = $fr;
}

// Sorting logic
$orderSql = match ($sort) {
    'last_remark_desc' => 'ORDER BY CASE WHEN last_remark_created_at IS NULL THEN 1 ELSE 0 END, last_remark_created_at DESC, s.id ASC',
    'last_remark_asc'  => 'ORDER BY CASE WHEN last_remark_created_at IS NULL THEN 1 ELSE 0 END, last_remark_created_at ASC, s.id ASC',
    'last_edited_desc' => 'ORDER BY CASE WHEN s.last_edited_at IS NULL THEN 1 ELSE 0 END, s.last_edited_at DESC, s.id ASC',
    'last_edited_asc'  => 'ORDER BY CASE WHEN s.last_edited_at IS NULL THEN 1 ELSE 0 END, s.last_edited_at ASC, s.id ASC',
    'name_asc'         => 'ORDER BY s.name ASC, s.id ASC',
    'name_desc'        => 'ORDER BY s.name DESC, s.id ASC',
    'received_desc'    => 'ORDER BY CASE WHEN s.received_date IS NULL THEN 1 ELSE 0 END, s.received_date DESC, s.id ASC',
    'received_asc'     => 'ORDER BY CASE WHEN s.received_date IS NULL THEN 1 ELSE 0 END, s.received_date ASC, s.id ASC',
    default            => 'ORDER BY s.id ASC',
};

// Execute query
$rows = q('SELECT s.*, o.name AS officer_name, pms.name AS pms_lead_name, fo.name AS fe_user_name,
                  (SELECT COUNT(*) FROM student_applications sa WHERE sa.student_id = s.id) AS app_count,
                  (SELECT COUNT(*) FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL) AS remark_count,
                  (SELECT sr.created_at FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_created_at,
                  (SELECT sr.remark_text FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_text,
                  (SELECT sr.author_name FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_name,
                  (SELECT u.role FROM student_remarks sr LEFT JOIN users u ON u.id = sr.author_id WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_role
           FROM students s 
           LEFT JOIN users o ON o.id = s.officer_id 
           LEFT JOIN users pms ON pms.id = s.assigned_pms_lead_id 
           LEFT JOIN users fo ON fo.id = s.frontend_officer_id 
           WHERE ' . implode(' AND ', $where) . ' ' . $orderSql, $params)->fetchAll();

// In-memory stage filters
if ($fs !== '') $rows = array_values(array_filter($rows, fn($r) => $fs === 'closed' ? next_stage($r) === null : next_stage($r) === $fs));
if ($fk === 'onboarding') $rows = array_values(array_filter($rows, fn($r) => ($r['student_status'] ?? 'active') === 'active' && ($r['consent'] !== 'Yes' || $r['initial_meeting'] !== 'Yes' || !student_pending_docs($r, load_doc_state([(int)$r['id']])[(int)$r['id']] ?? [])['complete'])));
if ($fk === 'offers') $rows = array_values(array_filter($rows, fn($r) => $r['offer'] === 'Yes'));
if ($fk === 'visa_granted') $rows = array_values(array_filter($rows, fn($r) => $r['visa'] === 'Granted'));
if ($fk === 'apps_submitted' || $fk === 'apps_remaining') {
    $kpiApps = [];
    foreach (q('SELECT student_id, status FROM student_applications') as $ap) {
        if ($fk === 'apps_submitted' ? app_is_submitted($ap['status']) : app_is_remaining($ap['status'])) $kpiApps[(int)$ap['student_id']] = true;
    }
    $rows = array_values(array_filter($rows, fn($r) => isset($kpiApps[(int)$r['id']])));
}
if ($fd !== '' && isset(STAGES[(int)$fd])) $rows = array_values(array_filter($rows, fn($r) => stage_done($r, (int)$fd)));

// Processing status (order-style) — computed from the record + saved journey stages
$allIds = array_map(fn($r) => (int)$r['id'], $rows);
$stageRowsBy = [];
$appStatusBy = [];
if ($allIds) {
    $ph = implode(',', array_fill(0, count($allIds), '?'));
    foreach (q("SELECT * FROM student_stages WHERE student_id IN ($ph)", $allIds) as $sr) $stageRowsBy[(int)$sr['student_id']][$sr['stage_key']] = $sr;
    foreach (q("SELECT student_id, status FROM student_applications WHERE student_id IN ($ph)", $allIds) as $ar) $appStatusBy[(int)$ar['student_id']][] = $ar;
}
$listDocState = load_doc_state($allIds ?: [0]);
$staffNames = staff_name_map();
$statusCounts = array_fill_keys(PROCESSING_STATUSES, 0);
foreach ($rows as $i => $r) {
    $sid = (int)$r['id'];
    $saved = $stageRowsBy[$sid] ?? [];
    $apps = $appStatusBy[$sid] ?? [];
    $r['_doc_info'] = student_pending_docs($r, $listDocState[$sid] ?? []);
    $rows[$i]['_doc_info'] = $r['_doc_info'];
    $auto = auto_journey($r, $apps, 0);
    $j = [];
    foreach (JOURNEY_STAGES as $k => $_) $j[$k] = ['status' => isset($saved[$k]) ? $saved[$k]['status'] : $auto[$k][0], 'label' => JOURNEY_STAGES[$k][0]];
    $rows[$i]['_journey'] = $j;
    $rows[$i]['_pstatus'] = processing_status($r, $j);
    $statusCounts[$rows[$i]['_pstatus']]++;
}
$fjnow = get('jnow');
if ($fjnow !== '' && isset(JOURNEY_STAGES[$fjnow])) {
    $rows = array_values(array_filter($rows, function ($r) use ($fjnow) {
        foreach ($r['_journey'] as $k => $st) if ($st['status'] !== 'Completed') return $k === $fjnow && ($r['student_status'] ?? 'active') === 'active';
        return false;
    }));
}
if ($fps !== '' && in_array($fps, PROCESSING_STATUSES, true)) {
    $rows = array_values(array_filter($rows, fn($r) => $r['_pstatus'] === $fps));
}

$total = count($rows);
$per = 50;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)get('page') ?: 1));
$rows = array_slice($rows, ($page - 1) * $per, $per);

// Reference lists for filter dropdowns
$officers = $canFilterOfficer ? q("SELECT id, name, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department DESC, name ASC")->fetchAll() : [];
$distinctCountries = q("SELECT DISTINCT country FROM students WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);
$counselorsList = q("SELECT id, name, username FROM users WHERE (role = 'counselor' OR id IN (SELECT DISTINCT assigned_counselor_id FROM students WHERE assigned_counselor_id IS NOT NULL)) AND active = 1 ORDER BY name ASC")->fetchAll();
$pmsLeadsList = q("SELECT id, name FROM users WHERE role = 'pms_lead' AND active = 1 ORDER BY name ASC")->fetchAll();

$qs = function (array $over) {
    return '?' . http_build_query(array_merge($_GET, $over));
};

page_start($admin ? 'Students' : ($u['role'] === 'counselor' ? 'My Caseload' : 'My students'), $u, PORTALS[$u['role']]['label']);
?>

<?php if ($canFilterOfficer && $fo !== ''): 
    $filteredOfficerName = 'Unassigned';
    if ($fo !== 'none') {
        foreach ($officers as $o) {
            if ((string)$o['id'] === $fo) {
                $filteredOfficerName = $o['name'] . ' (' . ($o['department'] === 'frontend' ? 'Front End' : 'Back End') . ')';
                break;
            }
        }
    }
?>
<div class="flash" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem; background:var(--surface); border:1.5px solid var(--accent); border-left:5px solid var(--accent);">
  <div>
    Viewing students assigned to Processing Officer: <b><?= h($filteredOfficerName) ?></b> (<?= $total ?> students)
  </div>
  <div style="display:flex; gap:0.4rem;">
    <?php if ($fo !== 'none' && $admin): ?>
      <a class="btn sm primary" href="<?= h(url('admin/officers.php?id=' . $fo)) ?>">Open Dedicated Workspace →</a>
    <?php endif; ?>
    <a class="btn sm ghost" href="<?= h(url($base . '/students.php')) ?>">Clear Officer Filter</a>
  </div>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div>
      <h1><?= $admin ? 'All students' : ($u['role'] === 'counselor' ? 'My Assigned Students' : 'My students') ?></h1>
      <p><?= $admin ? 'Full registry with advanced filtering, pending action tracking, and bulk updates.' : ($u['role'] === 'counselor' ? 'Read-only view of your caseload. Upload signed consents and communicate via remarks.' : 'Only students assigned to your workflow.') ?></p>
    </div>
    <div class="row-actions">
      <?php if ($admin): ?><a class="btn sm" href="<?= h(url('admin/export.php')) ?>">Download CSV</a><?php endif; ?>
      <div class="view-toggle" role="group" aria-label="View">
        <a href="<?= h($qs(['view' => 'table'])) ?>" class="<?= $view === 'table' ? 'on' : '' ?>" data-remember-view="table">☰ Table</a>
        <a href="<?= h($qs(['view' => 'cards'])) ?>" class="<?= $view === 'cards' ? 'on' : '' ?>" data-remember-view="cards">▦ Cards</a>
      </div>
      <?php if (can_quick_add_student($u)): ?>
        <button type="button" class="btn primary" data-qa-open>＋ Add Student</button>
      <?php endif; ?>
    </div>
  </div>

  <?php
    $flagMap = country_flags_map();
    $moreCount = count(array_filter([$fst, $fv, $fref, $fp, $fcounselor, $fpms, $fo !== '' && $canFilterOfficer ? $fo : '', $fs], fn($v) => $v !== ''));
    $activeFilters = [];
    if ($tab !== 'all') $activeFilters['tab'] = 'Degree: ' . strtoupper($tab);
    if ($fc !== '')     $activeFilters['country'] = 'Country: ' . ($fc === 'none' ? 'Not set' : $fc);
    if ($fq !== '')     $activeFilters['q'] = 'Search: “' . $fq . '”';
    if ($fo !== '')     $activeFilters['officer'] = 'Officer: ' . ($filteredOfficerName ?? $fo);
    if ($fcounselor !== '') {
        $cnLabel = $fcounselor;
        foreach ($counselorsList as $cl) { if ((string)$cl['id'] === $fcounselor) { $cnLabel = $cl['name']; break; } }
        $activeFilters['counselor'] = 'Counselor: ' . $cnLabel;
    }
    if ($fpms !== '')   $activeFilters['pms_lead'] = 'PMS Lead';
    if ($fst !== '')    $activeFilters['status'] = 'Account: ' . ucfirst($fst);
    if ($fv !== '')     $activeFilters['verified'] = $fv === '1' ? 'Verified' : 'Unverified';
    if ($fref !== '')   $activeFilters['refunded'] = $fref === '1' ? 'Refunded' : 'Not refunded';
    if ($fp !== '')     $activeFilters['pending'] = $fp === '1' ? 'Remark due' : 'Up to date';
    if ($finc !== '')   $activeFilters['incomplete'] = $finc === '1' ? 'Incomplete' : 'Complete';
    if ($fr !== '')     $activeFilters['refund'] = 'Refund: ' . $fr;
    if ($fs !== '')     $activeFilters['stage'] = 'Next step: ' . $fs;
    if ($fps !== '')    $activeFilters['pstatus'] = 'Status: ' . $fps;
    if ($fjnow !== '' && isset(JOURNEY_STAGES[$fjnow])) $activeFilters['jnow'] = 'Now at: ' . JOURNEY_STAGES[$fjnow][0];
    if ($fk !== '')     $activeFilters['kpi'] = ['apps_submitted' => 'Applications submitted', 'apps_remaining' => 'Applications still to submit', 'onboarding' => 'In onboarding', 'visa_granted' => 'Visa granted', 'offers' => 'Offer received'][$fk] ?? ucfirst(str_replace('_', ' ', $fk));
    if ($fd !== '' && isset(STAGES[(int)$fd])) $activeFilters['done_stage'] = 'Step done: ' . STAGES[(int)$fd][0];
  ?>

  <form method="get" action="<?= h(url($base . '/students.php')) ?>" class="sf" id="student-filters">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <?php if ($fc !== ''): ?><input type="hidden" name="country" value="<?= h($fc) ?>"><?php endif; ?>
    <?php if ($fps !== ''): ?><input type="hidden" name="pstatus" value="<?= h($fps) ?>"><?php endif; ?>
    <?php if ($fo !== '' && !$canFilterOfficer): ?><input type="hidden" name="officer" value="<?= h($fo) ?>"><?php endif; ?>
    <?php foreach (['jnow' => $fjnow, 'kpi' => $fk, 'incomplete' => $finc, 'refund' => $fr, 'done_stage' => $fd] as $hk => $hv): if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= h($hv) ?>"><?php endif; endforeach; ?>

    <!-- 1. Search + sort -->
    <div class="sf-top">
      <label class="sf-search"><span aria-hidden="true">⌕</span><input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search name, SR#, email, phone, counselor…" aria-label="Search students"></label>
      <select name="sort" aria-label="Sort" data-autosubmit class="sf-sort">
        <?php foreach (['default' => 'Sort: SR#', 'last_remark_desc' => 'Latest remark first', 'last_remark_asc' => 'Oldest remark first', 'last_edited_desc' => 'Recently edited', 'last_edited_asc' => 'Least recently edited', 'name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'received_desc' => 'Newest registration', 'received_asc' => 'Oldest registration'] as $sk => $sl): ?>
          <option value="<?= $sk ?>" <?= $sort === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn sm sf-more-btn <?= $moreCount ? 'on' : '' ?>" data-sf-more aria-expanded="<?= $moreCount ? 'true' : 'false' ?>">⚙ More filters<?= $moreCount ? ' <b>' . $moreCount . '</b>' : '' ?></button>
    </div>

    <!-- 2. Country (one place only) -->
    <div class="sf-row" role="group" aria-label="Country">
      <span class="sf-label">Country</span>
      <div class="seg-chips">
        <a href="<?= h($qs(['country' => '', 'page' => 1])) ?>" class="<?= $fc === '' ? 'on' : '' ?>">All <b><?= $cntAll ?></b></a>
        <?php foreach ($countryCounts as $cName => $cCnt): if ($cName === 'Not set') continue; ?>
          <a href="<?= h($qs(['country' => $cName, 'page' => 1])) ?>" class="<?= $fc === $cName ? 'on' : '' ?>"><?= $flagMap[$cName] ?? '' ?> <?= h($cName) ?> <b><?= $cCnt ?></b></a>
        <?php endforeach; ?>
        <?php if (!empty($countryCounts['Not set'])): ?><a href="<?= h($qs(['country' => 'none', 'page' => 1])) ?>" class="<?= $fc === 'none' ? 'on' : '' ?>">Not set <b><?= (int)$countryCounts['Not set'] ?></b></a><?php endif; ?>
      </div>
    </div>

    <!-- 3. Degree + processing status -->
    <div class="sf-row" role="group" aria-label="Degree and status">
      <span class="sf-label">Degree</span>
      <div class="seg-chips">
        <a href="<?= h($qs(['tab' => 'all', 'page' => 1])) ?>" class="<?= $tab === 'all' ? 'on' : '' ?>">All <b><?= $cntAll ?></b></a>
        <a href="<?= h($qs(['tab' => 'bs', 'page' => 1])) ?>" class="<?= $tab === 'bs' ? 'on' : '' ?>">BS <b><?= $cntBs ?></b></a>
        <a href="<?= h($qs(['tab' => 'ms', 'page' => 1])) ?>" class="<?= $tab === 'ms' ? 'on' : '' ?>">MS <b><?= $cntMs ?></b></a>
        <?php if ($cntInc): ?><a href="<?= h($qs(['tab' => 'incomplete', 'page' => 1])) ?>" class="<?= $tab === 'incomplete' ? 'on' : '' ?>">Level not set <b><?= $cntInc ?></b></a><?php endif; ?>
      </div>
      <span class="sf-label sf-gap">Status</span>
      <div class="status-chips sf-status">
        <a href="<?= h($qs(['pstatus' => null, 'page' => 1])) ?>" class="status-chip <?= $fps === '' ? 'on' : '' ?>">All <b><?= array_sum($statusCounts) ?></b></a>
        <?php foreach (PROCESSING_STATUSES as $psOpt): if (!$statusCounts[$psOpt] && $fps !== $psOpt) continue; ?>
          <a href="<?= h($qs(['pstatus' => $psOpt, 'page' => 1])) ?>" class="status-chip <?= processing_status_class($psOpt) ?> <?= $fps === $psOpt ? 'on' : '' ?>"><i></i><?= h($psOpt) ?> <b><?= $statusCounts[$psOpt] ?></b></a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 4. Everything else, folded away -->
    <div class="sf-more" data-sf-panel <?= $moreCount ? '' : 'hidden' ?>>
      <?php if ($canFilterOfficer): ?>
      <label class="f"><span>Processing officer</span>
        <select name="officer" aria-label="Processing officer" data-autosubmit>
          <option value="">Any processing officer</option>
          <optgroup label="Front End"><?php foreach ($officers as $o): if ($o['department'] !== 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></optgroup>
          <optgroup label="Back End"><?php foreach ($officers as $o): if ($o['department'] === 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></optgroup>
          <option value="none" <?= $fo === 'none' ? 'selected' : '' ?>>Not assigned</option>
        </select>
      </label>
      <?php endif; ?>
      <?php if ($u['role'] !== 'counselor'): ?>
      <label class="f"><span>Counselor</span>
        <select name="counselor" aria-label="Counselor" data-autosubmit><option value="">Any counselor</option>
          <?php foreach ($counselorsList as $cl): ?><option value="<?= $cl['id'] ?>" <?= $fcounselor === (string)$cl['id'] ? 'selected' : '' ?>><?= h($cl['name']) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <?php if ($admin && count($pmsLeadsList) > 1): ?>
      <label class="f"><span>PMS Lead</span>
        <select name="pms_lead" aria-label="PMS Lead" data-autosubmit><option value="">Any lead</option>
          <?php foreach ($pmsLeadsList as $pLead): ?><option value="<?= $pLead['id'] ?>" <?= $fpms === (string)$pLead['id'] ? 'selected' : '' ?>><?= h($pLead['name']) ?></option><?php endforeach; ?>
        </select></label>
      <?php endif; ?>
      <label class="f"><span>Next step</span>
        <select name="stage" aria-label="Next step" data-autosubmit><option value="">Any</option>
          <?php foreach (STAGES as $st): ?><option <?= $fs === $st[0] ? 'selected' : '' ?>><?= h($st[0]) ?></option><?php endforeach; ?>
          <option value="closed" <?= $fs === 'closed' ? 'selected' : '' ?>>Closed</option>
        </select></label>
      <label class="f"><span>Account</span>
        <select name="status" aria-label="Account status" data-autosubmit><option value="">Any</option>
          <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'disabled' => 'Disabled'] as $k => $l): ?><option value="<?= $k ?>" <?= $fst === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></label>
      <label class="f"><span>Staff remark</span>
        <select name="pending" aria-label="Remark due" data-autosubmit><option value="">Any</option><option value="1" <?= $fp === '1' ? 'selected' : '' ?>>⚠ Remark due</option><option value="0" <?= $fp === '0' ? 'selected' : '' ?>>Up to date</option></select></label>
      <label class="f"><span>Verification</span>
        <select name="verified" aria-label="Verification" data-autosubmit><option value="">Any</option><option value="1" <?= $fv === '1' ? 'selected' : '' ?>>Verified</option><option value="0" <?= $fv === '0' ? 'selected' : '' ?>>Not verified</option></select></label>
      <label class="f"><span>Refund</span>
        <select name="refunded" aria-label="Refund" data-autosubmit><option value="">Any</option><option value="1" <?= $fref === '1' ? 'selected' : '' ?>>Refunded</option><option value="0" <?= $fref === '0' ? 'selected' : '' ?>>Not refunded</option></select></label>
      <noscript><button class="btn sm" type="submit">Apply</button></noscript>
    </div>
  </form>

  <!-- Active filters: the one place to see and clear them -->
  <div class="sf-active">
    <span class="sf-count"><b><?= $total ?></b> of <?= $cntAll ?> students</span>
    <?php foreach ($activeFilters as $fKey => $fLabel): $clearParam = ($fKey === 'tab') ? ['tab' => 'all', 'page' => 1] : [$fKey => null, 'page' => 1]; ?>
      <a href="<?= h($qs($clearParam)) ?>" class="sf-pill" title="Remove this filter"><?= h($fLabel) ?> <b aria-hidden="true">×</b></a>
    <?php endforeach; ?>
    <?php if ($activeFilters): ?><a class="sf-clear" href="<?= h(url($base . '/students.php?view=' . $view)) ?>">Clear all</a><?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty" style="margin-top:1.5rem;">No students match the selected filters. Clear filters or switch tabs.</div>
  <?php else: ?>

  <!-- Phase 3: Bulk Edit Bar (Super Admin, Admin, PMS Lead only) -->
  <?php if ($canManage): ?>
  <form method="post" id="bulk-edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_edit">
    <div class="bulk-bar" id="bulk-bar" hidden>
      <span class="bulk-n"><b id="bulk-selected-count">0</b> selected</span>
      
      <select name="bulk_program_level" style="font-size:0.82rem; padding:0.25rem 0.5rem;">
        <option value="">Program Level: Keep unchanged</option>
        <option value="BS">Set to BS</option>
        <option value="MS">Set to MS</option>
      </select>

      <input name="bulk_country" list="country-datalist" placeholder="Country: Keep unchanged" style="font-size:0.82rem; padding:0.25rem 0.5rem; width:180px;">
      <datalist id="country-datalist">
        <?php foreach ($distinctCountries as $cOpt): ?>
          <option value="<?= h($cOpt) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <button class="btn sm primary" type="submit" id="bulk-submit-btn" disabled onclick="return confirm('Apply bulk update to all selected students? This will not trigger notifications or mark records as pending.');">Apply to Selected</button>
      <button type="button" class="btn sm ghost" onclick="document.querySelectorAll('.student-checkbox, [onclick*=toggleSelectAll]').forEach(function(b){b.checked=false});updateBulkCount();">Clear selection</button>
    </div>
  <?php endif; ?>

  <?php
    $flagMapAll = country_flags_map();
    $journeyBar = function (array $j): string {
        $html = '<span class="jbar" title="Processing journey">';
        foreach ($j as $st) $html .= '<i class="' . stage_status_class($st['status']) . '" title="' . h($st['label'] . ': ' . $st['status']) . '"></i>';
        return $html . '</span>';
    };
    $journeyNow = function (array $j): string {
        foreach ($j as $st) if ($st['status'] !== 'Completed') return $st['label'];
        return 'All steps complete';
    };
  ?>
  <?php if ($view === 'cards'): ?>
  <div class="order-grid">
    <?php foreach ($rows as $r):
        $editUrl = url($base . '/student.php?id=' . $r['id']);
        $cName = trim((string)($r['country'] ?? ''));
        $feName = $r['fe_user_name'] ?: ($r['frontend_officer_name'] ?: '');
        $done = count(array_filter($r['_journey'], fn($x) => $x['status'] === 'Completed'));
    ?>
      <article class="order-card <?= !empty($r['pending_since']) ? 'is-pending' : '' ?>">
        <header>
          <span class="order-id">#<?= (int)($r['sheet_sr'] ?: $r['id']) ?> · ID <?= (int)$r['id'] ?></span>
          <?= processing_status_badge($r['_pstatus']) ?>
        </header>
        <a class="order-name" href="<?= h($editUrl) ?>"><?= h($r['name']) ?></a>
        <div class="order-tags">
          <?php if ($cName !== ''): ?><span class="pill sm"><?= $flagMapAll[$cName] ?? '📍' ?> <?= h($cName) ?></span><?php else: ?><span class="pill sm warn">No country</span><?php endif; ?>
          <?php if (!empty($r['program_level'])): ?><span class="pill sm acc"><?= h($r['program_level']) ?></span><?php endif; ?>
          <?php if (!empty($r['intake'])): ?><span class="pill sm"><?= h($r['intake']) ?></span><?php endif; ?>
          <?php if (!empty($r['is_refunded'])): ?><span class="pill sm bad">Refunded</span><?php endif; ?>
        </div>
        <dl class="order-meta">
          <div><dt>Front-End</dt><dd><?= h($feName ?: '—') ?></dd></div>
          <div><dt>Back-End</dt><dd><?= h($r['officer_name'] ?: '—') ?></dd></div>
          <div><dt>Counselor</dt><dd><?= h($r['counselor'] ?: '—') ?></dd></div>
          <div><dt>Registered</dt><dd><?= h(fmt_date($r['received_date']) ?: '—') ?></dd></div>
        </dl>
        <div class="order-journey">
          <?= $journeyBar($r['_journey']) ?>
          <span class="small"><b><?= $done ?>/8</b> · <?= h($journeyNow($r['_journey'])) ?></span>
        </div>
        <footer>
          <?= last_remarks_cell($r) ?>
          <div class="order-actions">
            <a class="btn sm primary" href="<?= h($editUrl) ?>">Open</a>
            <?php if ($dl = safe_link($r['drive_link'] ?? null)): ?><a class="btn sm" href="<?= h($dl) ?>" target="_blank" rel="noopener noreferrer" title="Google Drive folder">📁</a><?php endif; ?>
          </div>
        </footer>
      </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="tbl tbl-students" style="margin-top:0.75rem;"><table>
    <thead>
      <tr>
        <?php if ($canManage): ?>
          <th style="width:34px; text-align:center;"><input type="checkbox" id="check-all" title="Select all on this page" onchange="toggleSelectAll(this)"></th>
        <?php endif; ?>
        <th>SR#</th>
        <th>Student</th>
        <th>Program &amp; Country</th>
        <th>Processing Officers</th>
        <th>Journey</th>
        <th>Docs &amp; Meeting</th>
        <th>Registered</th>
        <th>Last Remarks</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $dLink = safe_link($r['drive_link'] ?? null);
        $hasMeeting = student_has_meeting($r);
        $mLink = url('meeting.php?id=' . (int)$r['id']);
        
        $stStatus = (string)($r['student_status'] ?? 'active');
        $isVerified = ($r['csc_status'] ?? '') === 'Verified' || !empty($r['csc_verified_at']);
        $isRefunded = !empty($r['is_refunded']);
        $isPending = !empty($r['pending_since']);
        $isIncomplete = empty($r['program_level']) || empty($r['country']);
        $hasBranchError = ($r['branch'] === '30%');
        $editUrl = url($base . '/student.php?id=' . $r['id']);
        $feName = $r['fe_user_name'] ?: ($r['frontend_officer_name'] ?: '');
    ?>
      <tr class="<?= $isPending ? 'row-pending' : '' ?>">
        <?php if ($canManage): ?>
          <td style="text-align:center;">
            <input type="checkbox" name="selected_ids[]" value="<?= (int)$r['id'] ?>" class="student-checkbox" onchange="updateBulkCount()">
          </td>
        <?php endif; ?>
        <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
        <td class="wrap" style="min-width:210px;">
          <a href="<?= h($editUrl) ?>" style="text-decoration:none;">
            <b style="color:var(--fg);"><?= h($r['name']) ?></b>
          </a>
          <div style="display:flex; gap:0.25rem; flex-wrap:wrap; margin-top:0.3rem; align-items:center;">
            <?= processing_status_badge($r['_pstatus']) ?>
            <?php if ($stStatus !== 'active'): ?>
              <span class="pill sm <?= $stStatus === 'disabled' ? 'bad' : 'warn' ?>"><?= ucfirst($stStatus) ?></span>
            <?php endif; ?>
            <?php if ($isVerified): ?>
              <span class="pill sm ok" title="Verified">✓ Verified</span>
            <?php endif; ?>
            <?php if ($isPending): ?>
              <span class="pill sm bad" style="font-weight:700;" title="Pending processing staff / lead response">⚠ 48h</span>
            <?php endif; ?>
            <?php if ($isRefunded): ?>
              <span class="pill sm bad" title="Refund processed on <?= h($r['refund_date'] ?? '') ?>">Refunded</span>
            <?php endif; ?>
            <?php if ($isIncomplete): ?>
              <span class="pill sm warn" title="Program Level or Country incomplete">Incomplete</span>
            <?php endif; ?>
            <?php if ($hasBranchError): ?>
              <span class="pill sm bad" title="Data error: Legacy CSV column shift in branch field">⚠ Branch: 30%</span>
            <?php endif; ?>
          </div>
        </td>
        <td class="wrap" style="min-width:150px;">
          <div style="display:flex; flex-direction:column; gap:0.2rem;">
            <div style="display:flex; gap:0.25rem; flex-wrap:wrap; align-items:center;">
              <?= !empty($r['program_level']) ? '<span class="pill sm acc">' . h($r['program_level']) . '</span>' : '<span class="pill sm muted" style="opacity:0.6;">No Program</span>' ?>
              <?= !empty($r['country']) ? '<span class="pill sm">' . ($flagMapAll[$r['country']] ?? '') . ' ' . h($r['country']) . '</span>' : '<span class="pill sm warn">No Country</span>' ?>
            </div>
            <?php if ((int)($r['app_count'] ?? 0) > 0): ?>
              <div><a href="<?= h($editUrl . '#applications') ?>" class="pill sm ok" style="text-decoration:none; font-weight:600;">🏛 <?= (int)$r['app_count'] ?> Univ. App<?= (int)$r['app_count'] > 1 ? 's' : '' ?></a></div>
            <?php endif; ?>
            <?php if (!empty($r['applied_for']) && $r['applied_for'] !== ($r['program_level'] ?? '')): ?>
              <div class="small muted" style="font-size:0.72rem;">Track: <b><?= h($r['applied_for']) ?></b></div>
            <?php endif; ?>
          </div>
        </td>
        <td class="small wrap" style="min-width:150px;">
          <div class="officer-line"><span class="tag-fe">FE</span> <b><?= h($feName ?: 'Not set') ?></b></div>
          <div class="officer-line"><span class="tag-be">BE</span> <b><?= h($r['officer_name'] ?? 'Not assigned') ?></b></div>
          <?php if (!empty($r['counselor'])): ?><div class="muted" style="font-size:11.5px; margin-top:2px;">Counselor: <?= h($r['counselor']) ?></div><?php endif; ?>
        </td>
        <td class="wrap" style="min-width:150px;">
          <?= $journeyBar($r['_journey']) ?>
          <div class="small muted" style="margin-top:4px; font-size:11.5px;"><?= h($journeyNow($r['_journey'])) ?></div>
        </td>
        <td>
          <div style="display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap; max-width:170px;">
            <?php if ($dLink): ?>
              <a class="btn sm" href="<?= h($dLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Google Drive Documents Folder">📁 Docs</a>
            <?php endif; ?>
            <?php if ($hasMeeting): ?>
              <a class="btn sm" href="<?= h($mLink) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video / Zoom">🎥</a>
            <?php endif; ?>
            <a class="btn sm ghost" href="<?= h(url($base . '/report.php?id=' . $r['id'] . '&format=pdf')) ?>" title="Download Complete Student PDF Dossier">⬇</a>
          </div>
        </td>
        <td class="num small">
          <?= h(fmt_date($r['received_date']) ?: '—') ?>
          <?php if ($r['refund']): ?><div style="margin-top:3px;"><span class="pill sm <?= $r['refund'] === 'Non-Refundable' ? 'bad' : '' ?>" title="Refund policy tier"><?= h($r['refund']) ?></span></div><?php endif; ?>
        </td>
        <td><?= last_remarks_cell($r) ?></td>
        <td>
          <div style="display:flex; gap:0.3rem; align-items:center;">
            <a class="btn sm primary" href="<?= h($editUrl) ?>"><?= $u['role'] === 'counselor' ? 'View' : 'Open' ?></a>
            <a class="btn sm ghost" href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>" title="Student file (documents &amp; progress)">File</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if ($canManage): ?>
  </form>
  <script>
    function toggleSelectAll(master) {
      var boxes = document.querySelectorAll('.student-checkbox');
      for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = master.checked;
      }
      updateBulkCount();
    }
    function updateBulkCount() {
      var checked = document.querySelectorAll('.student-checkbox:checked').length;
      var countSpan = document.getElementById('bulk-selected-count');
      var submitBtn = document.getElementById('bulk-submit-btn');
      if (countSpan) countSpan.textContent = checked;
      if (submitBtn) submitBtn.disabled = (checked === 0);
      var bar = document.getElementById('bulk-bar'); if (bar) bar.hidden = (checked === 0);
    }
  </script>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a class="btn sm" href="<?= h($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
    <span class="num">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn sm" href="<?= h($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php require APP . '/pages/_quick_add.php'; ?>
<?php page_end();
