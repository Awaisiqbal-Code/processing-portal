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

// ---------- Tab Counts for Current Scope ----------
$cntAll = (int)q("SELECT COUNT(*) FROM students s WHERE $w", $p)->fetchColumn();
$cntBs = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND s.program_level = 'BS'", $p)->fetchColumn();
$cntMs = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND s.program_level = 'MS'", $p)->fetchColumn();
$cntInc = (int)q("SELECT COUNT(*) FROM students s WHERE $w AND (s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '')", $p)->fetchColumn();

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
        $where[] = "s.country = ?";
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
    $where[] = "(s.assigned_counselor_id = ? OR LOWER(TRIM(s.counselor)) = LOWER(TRIM(?)))";
    array_push($params, (int)$fcounselor, $fcounselor);
}

// PMS Lead filter
if ($fpms !== '') {
    $where[] = "s.assigned_pms_lead_id = ?";
    $params[] = (int)$fpms;
}

// Officer filter
if ($admin && $fo !== '') {
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
    default            => 'ORDER BY s.id ASC',
};

// Execute query
$rows = q('SELECT s.*, o.name AS officer_name, pms.name AS pms_lead_name,
                  (SELECT sr.created_at FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_created_at,
                  (SELECT sr.remark_text FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_text,
                  (SELECT sr.author_name FROM student_remarks sr WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_name,
                  (SELECT u.role FROM student_remarks sr LEFT JOIN users u ON u.id = sr.author_id WHERE sr.student_id = s.id AND sr.deleted_at IS NULL ORDER BY sr.created_at DESC, sr.id DESC LIMIT 1) AS last_remark_author_role
           FROM students s 
           LEFT JOIN users o ON o.id = s.officer_id 
           LEFT JOIN users pms ON pms.id = s.assigned_pms_lead_id 
           WHERE ' . implode(' AND ', $where) . ' ' . $orderSql, $params)->fetchAll();

// In-memory stage filters
if ($fs !== '') $rows = array_values(array_filter($rows, fn($r) => $fs === 'closed' ? next_stage($r) === null : next_stage($r) === $fs));
if ($fk === 'onboarding') $rows = array_values(array_filter($rows, fn($r) => $r['consent'] !== 'Yes' || $r['docs_checklist'] !== 'Yes' || $r['initial_meeting'] !== 'Yes'));
if ($fk === 'offers') $rows = array_values(array_filter($rows, fn($r) => $r['offer'] === 'Yes'));
if ($fk === 'visa_granted') $rows = array_values(array_filter($rows, fn($r) => $r['visa'] === 'Granted'));
if ($fd !== '' && isset(STAGES[(int)$fd])) $rows = array_values(array_filter($rows, fn($r) => stage_done($r, (int)$fd)));

$total = count($rows);
$per = 50;
$pages = max(1, (int)ceil($total / $per));
$page = min($pages, max(1, (int)get('page') ?: 1));
$rows = array_slice($rows, ($page - 1) * $per, $per);

// Reference lists for filter dropdowns
$officers = $admin ? q("SELECT id, name, role, COALESCE(department, 'backend') AS department FROM users WHERE role IN ('officer', 'pms_lead', 'processing_staff') ORDER BY department DESC, name ASC")->fetchAll() : [];
$distinctCountries = q("SELECT DISTINCT country FROM students WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);
$counselorsList = q("SELECT id, name, username FROM users WHERE role = 'counselor' AND active = 1 ORDER BY name ASC")->fetchAll();
$pmsLeadsList = q("SELECT id, name FROM users WHERE role = 'pms_lead' AND active = 1 ORDER BY name ASC")->fetchAll();

$qs = function (array $over) {
    return '?' . http_build_query(array_merge($_GET, $over));
};

page_start($admin ? 'Students' : ($u['role'] === 'counselor' ? 'My Caseload' : 'My students'), $u, PORTALS[$u['role']]['label']);
?>

<?php if ($admin && $fo !== ''): 
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
    <?php if ($fo !== 'none'): ?>
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
      <?php if ($canManage): ?><a class="btn sm primary" href="<?= h(url($base . '/student.php?new=1')) ?>">Add student</a><?php endif; ?>
    </div>
  </div>

  <!-- Phase 3: Program Level Tabs (All | BS | MS | Incomplete) -->
  <div style="display:flex; gap:0.5rem; border-bottom:1.5px solid var(--border); padding-bottom:0.6rem; margin-bottom:1.15rem; flex-wrap:wrap;">
    <a href="<?= h($qs(['tab' => 'all', 'page' => 1])) ?>" class="btn sm <?= $tab === 'all' ? 'primary' : 'ghost' ?>">
      All Students <span class="pill sm" style="margin-left:0.35rem;"><?= $cntAll ?></span>
    </a>
    <a href="<?= h($qs(['tab' => 'bs', 'page' => 1])) ?>" class="btn sm <?= $tab === 'bs' ? 'primary' : 'ghost' ?>">
      BS (Bachelors) <span class="pill sm" style="margin-left:0.35rem;"><?= $cntBs ?></span>
    </a>
    <a href="<?= h($qs(['tab' => 'ms', 'page' => 1])) ?>" class="btn sm <?= $tab === 'ms' ? 'primary' : 'ghost' ?>">
      MS (Masters) <span class="pill sm" style="margin-left:0.35rem;"><?= $cntMs ?></span>
    </a>
    <a href="<?= h($qs(['tab' => 'incomplete', 'page' => 1])) ?>" class="btn sm <?= $tab === 'incomplete' ? 'primary' : 'ghost' ?>">
      Incomplete <span class="pill sm warn" style="margin-left:0.35rem;"><?= $cntInc ?></span>
    </a>
  </div>

  <!-- Advanced Filtering Toolbar -->
  <form method="get" class="toolbar" style="gap:0.45rem; flex-wrap:wrap; align-items:center;">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search name, SR#, email, counselor..." aria-label="Search" style="min-width:200px;">

    <!-- Country Filter -->
    <select name="country" aria-label="Country" data-autosubmit>
      <option value="">All Countries</option>
      <?php foreach ($distinctCountries as $cOpt): ?>
        <option value="<?= h($cOpt) ?>" <?= $fc === $cOpt ? 'selected' : '' ?>><?= h($cOpt) ?></option>
      <?php endforeach; ?>
      <option value="none" <?= $fc === 'none' ? 'selected' : '' ?>>Not set (Incomplete)</option>
    </select>

    <!-- Status Filter -->
    <select name="status" aria-label="Status" data-autosubmit>
      <option value="">All Statuses</option>
      <option value="active" <?= $fst === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $fst === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      <option value="disabled" <?= $fst === 'disabled' ? 'selected' : '' ?>>Disabled</option>
    </select>

    <!-- Verified Filter -->
    <select name="verified" aria-label="Verified" data-autosubmit>
      <option value="">Verification: All</option>
      <option value="1" <?= $fv === '1' ? 'selected' : '' ?>>✓ Verified</option>
      <option value="0" <?= $fv === '0' ? 'selected' : '' ?>>Unverified</option>
    </select>

    <!-- Refunded Filter -->
    <select name="refunded" aria-label="Refunded" data-autosubmit>
      <option value="">Refunded: All</option>
      <option value="1" <?= $fref === '1' ? 'selected' : '' ?>>Refunded</option>
      <option value="0" <?= $fref === '0' ? 'selected' : '' ?>>Not Refunded</option>
    </select>

    <!-- Pending Filter -->
    <select name="pending" aria-label="Pending" data-autosubmit>
      <option value="">Pending: All</option>
      <option value="1" <?= $fp === '1' ? 'selected' : '' ?>>⚠ Pending Action</option>
      <option value="0" <?= $fp === '0' ? 'selected' : '' ?>>Up to date</option>
    </select>

    <!-- Counselor Filter (Visible to staff who have broader visibility) -->
    <?php if ($u['role'] !== 'counselor'): ?>
    <select name="counselor" aria-label="Counselor" data-autosubmit>
      <option value="">All Counselors</option>
      <?php foreach ($counselorsList as $cl): ?>
        <option value="<?= $cl['id'] ?>" <?= $fcounselor === (string)$cl['id'] ? 'selected' : '' ?>><?= h($cl['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <!-- PMS Lead Filter -->
    <?php if ($admin): ?>
    <select name="pms_lead" aria-label="PMS Lead" data-autosubmit>
      <option value="">All PMS Leads</option>
      <?php foreach ($pmsLeadsList as $pLead): ?>
        <option value="<?= $pLead['id'] ?>" <?= $fpms === (string)$pLead['id'] ? 'selected' : '' ?>><?= h($pLead['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <!-- Officer Filter -->
    <?php if ($admin): ?>
    <select name="officer" aria-label="Officer" data-autosubmit>
      <option value="">All officers (FE &amp; BE)</option>
      <optgroup label="Front End Processing Officers">
        <?php foreach ($officers as $o): if ($o['department'] !== 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?> (Front End)</option><?php endforeach; ?>
      </optgroup>
      <optgroup label="Back End Processing Officers">
        <?php foreach ($officers as $o): if ($o['department'] === 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?> (Back End)</option><?php endforeach; ?>
      </optgroup>
      <option value="none" <?= $fo === 'none' ? 'selected' : '' ?>>Not assigned</option>
    </select>
    <?php endif; ?>

    <!-- Stage / Next step -->
    <select name="stage" aria-label="Next step" data-autosubmit>
      <option value="">Any next step</option>
      <?php foreach (STAGES as $s): ?><option <?= $fs === $s[0] ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
      <option value="closed" <?= $fs === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>

    <!-- Sort Dropdown -->
    <select name="sort" aria-label="Sort By" data-autosubmit>
      <option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>Sort: Default (SR#)</option>
      <option value="last_remark_desc" <?= $sort === 'last_remark_desc' ? 'selected' : '' ?>>Sort: Last Remark (Newest)</option>
      <option value="last_remark_asc" <?= $sort === 'last_remark_asc' ? 'selected' : '' ?>>Sort: Last Remark (Oldest)</option>
      <option value="last_edited_desc" <?= $sort === 'last_edited_desc' ? 'selected' : '' ?>>Sort: Last Edited (Newest)</option>
      <option value="last_edited_asc" <?= $sort === 'last_edited_asc' ? 'selected' : '' ?>>Sort: Last Edited (Oldest)</option>
      <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
      <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Sort: Name (Z-A)</option>
      <option value="received_desc" <?= $sort === 'received_desc' ? 'selected' : '' ?>>Sort: Received Date</option>
    </select>

    <button class="btn sm" type="submit">Apply</button>
    <a class="btn sm ghost" href="<?= h(url($base . '/students.php?tab=' . $tab)) ?>">Reset</a>
    <span class="count num" style="margin-left:auto;"><?= $total ?> students</span>
  </form>

  <?php if (!$rows): ?>
    <div class="empty" style="margin-top:1.5rem;">No students match the selected filters. Clear filters or switch tabs.</div>
  <?php else: ?>

  <!-- Phase 3: Bulk Edit Bar (Super Admin, Admin, PMS Lead only) -->
  <?php if ($canManage): ?>
  <form method="post" id="bulk-edit-form" style="margin-top:1rem; margin-bottom:0.75rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_edit">
    <div style="background:var(--sunk); border:1px solid var(--border); border-radius:6px; padding:0.6rem 0.85rem; display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
      <span style="font-weight:600; font-size:0.85rem;">⚡ Bulk Edit (<span id="bulk-selected-count">0</span> selected):</span>
      
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
      <span class="small muted" style="margin-left:auto;">Bulk updates do not change Last Edited or fire notifications.</span>
    </div>
  <?php endif; ?>

  <div class="tbl" style="margin-top:0.75rem;"><table>
    <thead>
      <tr>
        <?php if ($canManage): ?>
          <th style="width:34px; text-align:center;"><input type="checkbox" id="check-all" title="Select all on this page" onchange="toggleSelectAll(this)"></th>
        <?php endif; ?>
        <th>SR#</th>
        <th>Student</th>
        <th>Program &amp; Country</th>
        <th>Counselor</th>
        <?php if ($admin): ?><th>Officers (FE / BE)</th><?php endif; ?>
        <th>Docs &amp; Meeting</th>
        <th>Refund</th>
        <th>Received</th>
        <th>Progress</th>
        <th>Next step</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $ns = next_stage($r);
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
    ?>
      <tr class="<?= $isPending ? 'row-pending' : '' ?>" style="<?= $isPending ? 'background:rgba(239,68,68,0.04);' : '' ?>">
        <?php if ($canManage): ?>
          <td style="text-align:center;">
            <input type="checkbox" name="selected_ids[]" value="<?= (int)$r['id'] ?>" class="student-checkbox" onchange="updateBulkCount()">
          </td>
        <?php endif; ?>
        <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
        <td>
          <a href="<?= h($editUrl) ?>" style="text-decoration:none;">
            <b style="color:var(--fg);"><?= h($r['name']) ?></b>
          </a>
          <div style="display:flex; gap:0.25rem; flex-wrap:wrap; margin-top:0.25rem;">
            <!-- Status Badge -->
            <span class="pill sm <?= $stStatus === 'active' ? 'ok' : ($stStatus === 'disabled' ? 'bad' : 'warn') ?>" style="<?= $stStatus === 'disabled' ? 'background:#64748b;color:#fff;' : '' ?>">
              <?= ucfirst($stStatus) ?>
            </span>

            <!-- Verified Badge -->
            <?php if ($isVerified): ?>
              <span class="pill sm ok" title="Verified by <?= h($r['csc_verified_by'] ?? 'Staff') ?>">✓ Verified</span>
            <?php endif; ?>

            <!-- Pending Badge -->
            <?php if ($isPending): ?>
              <span class="pill sm bad" style="font-weight:700;" title="Pending processing staff / lead response">⚠ PENDING</span>
            <?php endif; ?>

            <!-- Refunded Flag -->
            <?php if ($isRefunded): ?>
              <span class="pill sm bad" title="Refund processed on <?= h($r['refund_date'] ?? '') ?>">Refunded</span>
            <?php endif; ?>

            <!-- Incomplete Flag -->
            <?php if ($isIncomplete): ?>
              <span class="pill sm warn" title="Program Level or Country incomplete">Incomplete</span>
            <?php endif; ?>

            <!-- Branch 30% Error Flag -->
            <?php if ($hasBranchError): ?>
              <span class="pill sm bad" style="border:1px solid #dc2626; background:#fef2f2; color:#b91c1c; font-weight:600;" title="Data error: Legacy CSV column shift in branch field">⚠ Branch: 30%</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($r['last_remark_text'])): ?>
            <div class="small muted" style="margin-top:0.35rem; font-size:0.75rem; line-height:1.35; max-width:320px;">
              💬 <b><?= h($r['last_remark_author_name'] ?: 'Staff') ?>:</b>
              <?php if (($r['last_remark_author_role'] ?? '') === 'counselor'): ?>
                <span class="pill sm" style="background:#0284c7; color:#fff; font-size:0.62rem; padding:0.05rem 0.35rem; font-weight:600; vertical-align:middle;">Counselor</span>
              <?php endif; ?>
              <?= h(mb_strimwidth((string)$r['last_remark_text'], 0, 70, '…')) ?>
              <span style="font-size:0.7rem; color:var(--muted); opacity:0.85;">· <?= h(fmt_date(substr((string)$r['last_remark_created_at'], 0, 10))) ?></span>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex; flex-direction:column; gap:0.2rem;">
            <div><?= !empty($r['program_level']) ? '<span class="pill sm acc">' . h($r['program_level']) . '</span>' : '<span class="pill sm muted" style="opacity:0.6;">No Program</span>' ?></div>
            <div><?= !empty($r['country']) ? '<span class="pill sm">' . h($r['country']) . '</span>' : '<span class="pill sm warn">No Country</span>' ?></div>
          </div>
        </td>
        <td>
          <?= h($r['counselor']) ?>
        </td>
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
        <td class="num"><?= h(fmt_date($r['received_date'])) ?></td>
        <td><a href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>" title="View student progress" style="text-decoration:none; display:inline-block;"><?= progress_dots($r) ?></a></td>
        <td><?= $ns ? '<span class="pill acc">' . h($ns) . '</span>' : '<span class="pill ok">Closed</span>' ?></td>
        <td>
          <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
            <a class="btn sm" href="<?= h($editUrl) ?>"><?= $u['role'] === 'counselor' ? 'View' : 'Edit' ?></a>
            <a class="btn sm primary" href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>">Progress</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

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
<?php page_end();
