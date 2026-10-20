<?php
declare(strict_types=1);
// Shared student list for admin and officer. Expects $u.

$admin = $u['role'] === 'admin';
$base = $admin ? 'admin' : 'officer';
[$w, $p] = scope_sql($u);

$fq = get('q'); $fo = get('officer'); $fs = get('stage'); $fr = get('refund'); $fk = get('kpi'); $fd = get('done_stage');
$where = [$w]; $params = $p;
if ($fq !== '') { $where[] = '(s.name LIKE ? OR s.counselor LIKE ? OR s.remarks LIKE ? OR s.email LIKE ?)'; $like = '%' . $fq . '%'; array_push($params, $like, $like, $like, $like); }
if ($admin && $fo !== '') {
    if ($fo === 'none') $where[] = '(s.officer_id IS NULL OR s.frontend_officer_id IS NULL)';
    else { $where[] = '(s.officer_id = ? OR s.frontend_officer_id = ?)'; array_push($params, (int)$fo, (int)$fo); }
}
if ($fr !== '') { $where[] = 's.refund = ?'; $params[] = $fr; }

$rows = q('SELECT s.*, o.name AS officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id ASC', $params)->fetchAll();
if ($fs !== '') $rows = array_values(array_filter($rows, fn($r) => $fs === 'closed' ? next_stage($r) === null : next_stage($r) === $fs));
if ($fk === 'onboarding') $rows = array_values(array_filter($rows, fn($r) => $r['consent'] !== 'Yes' || $r['docs_checklist'] !== 'Yes' || $r['initial_meeting'] !== 'Yes'));
if ($fk === 'offers') $rows = array_values(array_filter($rows, fn($r) => $r['offer'] === 'Yes'));
if ($fk === 'visa_granted') $rows = array_values(array_filter($rows, fn($r) => $r['visa'] === 'Granted'));
if ($fd !== '' && isset(STAGES[(int)$fd])) $rows = array_values(array_filter($rows, fn($r) => stage_done($r, (int)$fd)));

$total = count($rows);
$per = 50; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)get('page') ?: 1));
$rows = array_slice($rows, ($page - 1) * $per, $per);
$officers = $admin ? q("SELECT id, name, COALESCE(department, 'backend') AS department FROM users WHERE role = 'officer' ORDER BY department DESC, name ASC")->fetchAll() : [];
$qs = function (array $over) { return '?' . http_build_query(array_merge($_GET, $over)); };

page_start($admin ? 'Students' : 'My students', $u, PORTALS[$u['role']]['label']);
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
    <a class="btn sm ghost" href="<?= h(url('admin/students.php')) ?>">Clear Officer Filter</a>
  </div>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <div><h1><?= $admin ? 'All students' : 'My students' ?></h1><p><?= $admin ? 'Every record. Filter by Front End / Back End officer or next step.' : 'Only the students assigned to you.' ?></p></div>
    <div class="row-actions">
      <?php if ($admin): ?><a class="btn sm" href="<?= h(url('admin/export.php')) ?>">Download CSV</a><?php endif; ?>
      <a class="btn sm primary" href="<?= h(url($base . '/student.php?new=1')) ?>">Add student</a>
    </div>
  </div>

  <form method="get" class="toolbar">
    <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search name, counselor, remarks" aria-label="Search">
    <?php if ($admin): ?>
    <select name="officer" aria-label="Officer" data-autosubmit>
      <option value="">All officers (FE & BE)</option>
      <optgroup label="Front End Processing Officers">
        <?php foreach ($officers as $o): if ($o['department'] !== 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?> (Front End)</option><?php endforeach; ?>
      </optgroup>
      <optgroup label="Back End Processing Officers">
        <?php foreach ($officers as $o): if ($o['department'] === 'frontend') continue; ?><option value="<?= $o['id'] ?>" <?= $fo === (string)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?> (Back End)</option><?php endforeach; ?>
      </optgroup>
      <option value="none" <?= $fo === 'none' ? 'selected' : '' ?>>Not assigned</option>
    </select>
    <?php endif; ?>
    <select name="stage" aria-label="Next step" data-autosubmit>
      <option value="">Any next step</option>
      <?php foreach (STAGES as $s): ?><option <?= $fs === $s[0] ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
      <option value="closed" <?= $fs === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>
    <select name="refund" aria-label="Refund status" data-autosubmit>
      <option value="">Any refund status</option>
      <?php foreach (['30%', '50%', '90%', 'Non-Refundable'] as $v): ?><option <?= $fr === $v ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit">Apply</button>
    <span class="count num"><?= $total ?> students</span>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">No students match. Clear the filters or add a student.</div>
  <?php else: ?>
  <div class="tbl"><table>
    <thead><tr><th>SR#</th><th>Student</th><th>Counselor</th><?php if ($admin): ?><th>Officers (FE / BE)</th><?php endif; ?><th>Docs & Meeting</th><th>Refund</th><th>Received</th><th>Progress</th><th>Next step</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $ns = next_stage($r);
        $dLink = safe_link($r['drive_link'] ?? null);
        $hasMeeting = student_has_meeting($r);
        $mLink = url('meeting.php?id=' . (int)$r['id']);
    ?>
      <tr>
        <td class="num"><?= (int)($r['sheet_sr'] ?: $r['id']) ?></td>
        <td><a href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a></td>
        <td><?= h($r['counselor']) ?></td>
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
            <a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $r['id'])) ?>">Edit</a>
            <a class="btn sm primary" href="<?= h(url($base . '/progress.php?id=' . $r['id'])) ?>">Progress</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
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
