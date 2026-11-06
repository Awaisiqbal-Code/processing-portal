<?php
declare(strict_types=1);
// Shared University Programs Directory & Applications Tracker for admin, pms_lead, processing_staff, and counselor. Expects $u.

$admin = is_admin_role($u['role']);
$canManage = can_manage_student_lifecycle($u);
$base = $admin ? 'admin' : 'officer';

// Handle adding a new program to the directory
if (is_post() && post('action') === 'add_program') {
    csrf_check();
    if (!$canManage) {
        http_response_code(403);
        flash('Permission denied.', 'bad');
        redirect($base . '/programs.php');
    }
    $univ = trim((string)post('university'));
    $prog = trim((string)post('program_name'));
    $cntry = trim((string)post('country'));
    if ($univ === '' || $prog === '' || $cntry === '') {
        flash('Country, University, and Program Name are required.', 'bad');
    } else {
        q("INSERT INTO university_programs (
               country, university, programme, degree, field, english_requirement,
               application_fee, tuition_eur, deadline, exam_selection, deadline_note, status, created_at
           ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)", [
            $cntry,
            $univ,
            $prog,
            trim((string)post('degree_type')) ?: null,
            trim((string)post('field')) ?: null,
            trim((string)post('language_req')) ?: null,
            trim((string)post('application_fee')) ?: null,
            trim((string)post('tuition_fee')) ?: null,
            trim((string)post('deadline')) ?: null,
            trim((string)post('admission_req')) ?: null,
            trim((string)post('portal_link')) ?: null,
            now(),
        ]);
        flash('University program added to catalog.');
    }
    redirect($base . '/programs.php?country=' . rawurlencode($cntry));
}

$view = strtolower(get('view') ?: 'catalog'); // 'catalog' or 'applications'
$fc = trim((string)get('country'));
$fdeg = trim((string)get('degree'));
$funiv = trim((string)get('university'));
$fq = trim((string)get('q'));

$totalCatalog = (int)q("SELECT COUNT(*) FROM university_programs")->fetchColumn();
$totalApps = (int)q("SELECT COUNT(*) FROM student_applications")->fetchColumn();

$catalogCountries = q("SELECT country, COUNT(*) AS cnt FROM university_programs GROUP BY country ORDER BY cnt DESC")->fetchAll();
$appCountries = q("SELECT country, COUNT(*) AS cnt FROM student_applications GROUP BY country ORDER BY cnt DESC")->fetchAll();

$qs = function (array $over) {
    return '?' . http_build_query(array_merge($_GET, $over));
};

page_start('University Programs & Applications', $u, PORTALS[$u['role']]['label']);
?>

<section class="panel">
  <div class="panel-h">
    <div>
      <h1>🏛 University Programs Directory &amp; Applications</h1>
      <p>Search 339+ university programs across Czech Republic &amp; Germany, or track 437+ active student university applications across France, Germany, Czech Republic &amp; Austria.</p>
    </div>
    <div class="row-actions">
      <a class="btn sm <?= $view === 'catalog' ? 'primary' : 'ghost' ?>" href="<?= h(url($base . '/programs.php?view=catalog')) ?>">
        📚 Programs Catalog <span class="pill sm" style="margin-left:0.3rem;"><?= $totalCatalog ?></span>
      </a>
      <a class="btn sm <?= $view === 'applications' ? 'primary' : 'ghost' ?>" href="<?= h(url($base . '/programs.php?view=applications')) ?>">
        🎓 Student Applications <span class="pill sm" style="margin-left:0.3rem;"><?= $totalApps ?></span>
      </a>
    </div>
  </div>

  <?php if ($view === 'applications'):
      $where = ['1=1'];
      $params = [];
      if ($fc !== '') { $where[] = 'sa.country = ?'; $params[] = $fc; }
      if ($fq !== '') {
          $where[] = '(sa.university LIKE ? OR sa.program LIKE ? OR s.name LIKE ? OR sa.status LIKE ? OR sa.submitted_by LIKE ?)';
          $like = '%' . $fq . '%';
          array_push($params, $like, $like, $like, $like, $like);
      }
      $appRows = q("
          SELECT sa.*, s.name AS student_name, s.program_level, s.counselor
          FROM student_applications sa
          JOIN students s ON s.id = sa.student_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY sa.country ASC, s.name ASC, sa.id ASC
      ", $params)->fetchAll();
  ?>
    <!-- Country Tabs for Student Applications -->
    <div style="display:flex; gap:0.45rem; padding-bottom:0.75rem; margin-bottom:1rem; border-bottom:1.5px solid var(--border); flex-wrap:wrap; align-items:center;">
      <a href="<?= h($qs(['country' => ''])) ?>" class="btn sm <?= $fc === '' ? 'primary' : 'ghost' ?>">
        All Countries <span class="pill sm" style="margin-left:0.3rem;"><?= $totalApps ?></span>
      </a>
      <?php
        $flagMap = country_flags_map();
        foreach ($appCountries as $ac):
            $flag = $flagMap[$ac['country']] ?? '📍';
      ?>
        <a href="<?= h($qs(['country' => $ac['country']])) ?>" class="btn sm <?= $fc === $ac['country'] ? 'primary' : 'ghost' ?>">
          <?= $flag ?> <?= h($ac['country']) ?> <span class="pill sm" style="margin-left:0.3rem;"><?= (int)$ac['cnt'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="toolbar" style="gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
      <input type="hidden" name="view" value="applications">
      <input type="hidden" name="country" value="<?= h($fc) ?>">
      <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search student, university, program, status, officer..." style="min-width:280px;">
      <button class="btn sm" type="submit">Filter</button>
      <a class="btn sm ghost" href="<?= h(url($base . '/programs.php?view=applications')) ?>">Reset</a>
      <span class="count num" style="margin-left:auto;"><?= count($appRows) ?> applications</span>
    </form>

    <?php if (empty($appRows)): ?>
      <div class="empty">No student applications match your filter.</div>
    <?php else: ?>
      <div class="tbl"><table>
        <thead>
          <tr>
            <th>#</th>
            <th>Student</th>
            <th>Country</th>
            <th>University &amp; Program</th>
            <th>Track / Type</th>
            <th>Deadline &amp; Applied</th>
            <th>Fee &amp; Nostrification</th>
            <th>Submitted By</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appRows as $i => $ar):
              $pLink = safe_link($ar['portal_link'] ?? null);
          ?>
            <tr>
              <td class="num"><?= $i + 1 ?></td>
              <td>
                <a href="<?= h(url($base . '/student.php?id=' . (int)$ar['student_id'] . '#applications')) ?>"><b><?= h($ar['student_name']) ?></b></a>
                <div class="small muted"><?= h($ar['program_level'] ?: '') ?> · <?= h($ar['counselor'] ?: '') ?></div>
              </td>
              <td><span class="pill sm"><?= h($ar['country']) ?></span></td>
              <td>
                <b><?= h($ar['university'] ?: '—') ?></b>
                <?php if (!empty($ar['program'])): ?><div class="small"><?= h($ar['program']) ?></div><?php endif; ?>
                <?php if ($pLink): ?><div><a href="<?= h($pLink) ?>" target="_blank" rel="noopener noreferrer" class="small">🔗 Portal ↗</a></div><?php endif; ?>
              </td>
              <td class="small"><?= h($ar['application_type'] ?: '—') ?></td>
              <td class="small">
                <?php if (!empty($ar['deadline'])): ?><div>DL: <b><?= h($ar['deadline']) ?></b></div><?php endif; ?>
                <?php if (!empty($ar['applied_date'])): ?><div>App: <?= h($ar['applied_date']) ?></div><?php endif; ?>
              </td>
              <td class="small">
                <?= h(trim(($ar['application_fee'] ?? '') . (!empty($ar['nostrification_status']) ? ' · Nostr: ' . $ar['nostrification_status'] : '')) ?: '—') ?>
              </td>
              <td class="small"><?= h($ar['submitted_by'] ?: '—') ?></td>
              <td><span class="pill sm acc"><?= h($ar['status'] ?: 'Pending') ?></span></td>
              <td class="small muted"><?= h(mb_strimwidth((string)($ar['remarks'] ?? ''), 0, 80, '…') ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

  <?php else:
      $where = ['1=1'];
      $params = [];
      if ($fc !== '') { $where[] = 'country = ?'; $params[] = $fc; }
      if ($fdeg !== '') { $where[] = 'degree LIKE ?'; $params[] = '%' . $fdeg . '%'; }
      if ($funiv !== '') { $where[] = 'university = ?'; $params[] = $funiv; }
      if ($fq !== '') {
          $where[] = '(university LIKE ? OR programme LIKE ? OR field LIKE ? OR faculty LIKE ? OR exam_selection LIKE ? OR city LIKE ?)';
          $like = '%' . $fq . '%';
          array_push($params, $like, $like, $like, $like, $like, $like);
      }
      $progRows = q("SELECT * FROM university_programs WHERE " . implode(' AND ', $where) . " ORDER BY country ASC, university ASC, programme ASC", $params)->fetchAll();
      $univList = q("SELECT DISTINCT university FROM university_programs WHERE country IS NOT NULL " . ($fc !== '' ? "AND country = " . db()->quote($fc) : "") . " ORDER BY university ASC")->fetchAll(PDO::FETCH_COLUMN);
  ?>
    <!-- Country Tabs for Programs Catalog -->
    <div style="display:flex; gap:0.45rem; padding-bottom:0.75rem; margin-bottom:1rem; border-bottom:1.5px solid var(--border); flex-wrap:wrap; align-items:center;">
      <a href="<?= h($qs(['country' => '', 'university' => ''])) ?>" class="btn sm <?= $fc === '' ? 'primary' : 'ghost' ?>">
        All Countries <span class="pill sm" style="margin-left:0.3rem;"><?= $totalCatalog ?></span>
      </a>
      <?php
        $flagMap = country_flags_map();
        foreach ($catalogCountries as $cc):
            $flag = $flagMap[$cc['country']] ?? '📍';
      ?>
        <a href="<?= h($qs(['country' => $cc['country'], 'university' => ''])) ?>" class="btn sm <?= $fc === $cc['country'] ? 'primary' : 'ghost' ?>">
          <?= $flag ?> <?= h($cc['country']) ?> <span class="pill sm" style="margin-left:0.3rem;"><?= (int)$cc['cnt'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="toolbar" style="gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
      <input type="hidden" name="view" value="catalog">
      <input type="hidden" name="country" value="<?= h($fc) ?>">
      <input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search university, program, field, requirements..." style="min-width:250px;">
      <select name="degree" data-autosubmit>
        <option value="">All Degrees</option>
        <option value="Bachelor" <?= $fdeg === 'Bachelor' ? 'selected' : '' ?>>Bachelor (BS)</option>
        <option value="Master" <?= $fdeg === 'Master' ? 'selected' : '' ?>>Master (MS)</option>
      </select>
      <select name="university" data-autosubmit style="max-width:260px;">
        <option value="">All Universities (<?= count($univList) ?>)</option>
        <?php foreach ($univList as $uOpt): ?>
          <option value="<?= h($uOpt) ?>" <?= $funiv === $uOpt ? 'selected' : '' ?>><?= h($uOpt) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm" type="submit">Filter</button>
      <a class="btn sm ghost" href="<?= h(url($base . '/programs.php?view=catalog')) ?>">Reset</a>
      <span class="count num" style="margin-left:auto;"><?= count($progRows) ?> programs</span>
    </form>

    <?php if ($canManage): ?>
    <details style="background:var(--sunk); border:1px solid var(--border); border-radius:6px; padding:0.75rem 1rem; margin-bottom:1rem;">
      <summary style="cursor:pointer; font-weight:600; font-size:0.88rem;">➕ Add New University Program to Directory</summary>
      <form method="post" class="fields" style="margin-top:0.75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_program">
        <label class="f"><span>Country *</span>
          <select name="country" required>
            <option value="Czech Republic">Czech Republic</option>
            <option value="Germany">Germany</option>
            <option value="France">France</option>
            <option value="Italy">Italy</option>
            <option value="Austria">Austria</option>
          </select>
        </label>
        <label class="f"><span>University Name *</span><input name="university" required placeholder="e.g. Charles University"></label>
        <label class="f"><span>Program Name *</span><input name="program_name" required placeholder="e.g. Computer Science"></label>
        <label class="f"><span>Degree Type</span><input name="degree_type" placeholder="Bachelor's / Master's"></label>
        <label class="f"><span>Field / Faculty</span><input name="field" placeholder="IT / Engineering / Business"></label>
        <label class="f"><span>Language Requirement</span><input name="language_req" placeholder="IELTS 6.0 / English B2"></label>
        <label class="f"><span>Application Fee</span><input name="application_fee" placeholder="e.g. 32 EUR"></label>
        <label class="f"><span>Tuition Fee</span><input name="tuition_fee" placeholder="e.g. 3000 EUR / year"></label>
        <label class="f"><span>Deadline</span><input name="deadline" placeholder="e.g. 2026-04-30"></label>
        <label class="f"><span>Portal / Info Link</span><input name="portal_link" placeholder="https://..."></label>
        <label class="f wide"><span>Admission Requirements / Notes</span><input name="admission_req" placeholder="Entrance exam, interview, GPA criteria..."></label>
        <div class="f"><span>&nbsp;</span><button class="btn sm primary" type="submit">Add Program</button></div>
      </form>
    </details>
    <?php endif; ?>

    <?php if (empty($progRows)): ?>
      <div class="empty">No programs match the selected criteria.</div>
    <?php else: ?>
      <div class="tbl"><table>
        <thead>
          <tr>
            <th>#</th>
            <th>Country &amp; University</th>
            <th>Program &amp; Field</th>
            <th>Degree</th>
            <th>Language &amp; Requirements</th>
            <th>App Fee &amp; Tuition</th>
            <th>Deadline &amp; Portal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($progRows as $idx => $pr):
              $pLink = safe_link($pr['deadline_note'] ?? null);
              $progName = $pr['programme'] ?? ($pr['program_name'] ?? '—');
              $degType = $pr['degree'] ?? ($pr['degree_type'] ?? 'Degree');
              $langReq = $pr['english_requirement'] ?? ($pr['language'] ?? '');
              $admReq = $pr['exam_selection'] ?? '';
              $tuition = $pr['tuition_eur'] ?? '';
          ?>
            <tr>
              <td class="num"><?= $idx + 1 ?></td>
              <td>
                <span class="pill sm"><?= h($pr['country']) ?></span>
                <?php if (!empty($pr['city'])): ?><span class="small muted"> · <?= h($pr['city']) ?></span><?php endif; ?>
                <div style="margin-top:0.2rem;"><b><?= h($pr['university']) ?></b></div>
                <?php if (!empty($pr['faculty'])): ?><div class="small muted"><?= h($pr['faculty']) ?></div><?php endif; ?>
              </td>
              <td>
                <b><?= h($progName) ?></b>
                <?php if (!empty($pr['field'])): ?><div class="small muted"><?= h($pr['field']) ?></div><?php endif; ?>
              </td>
              <td><span class="pill sm acc"><?= h($degType ?: 'Degree') ?></span></td>
              <td class="small" style="max-width:280px;">
                <?php if (!empty($langReq)): ?><div><b>Lang:</b> <?= h($langReq) ?></div><?php endif; ?>
                <?php if (!empty($pr['min_cgpa'])): ?><div><b>Min CGPA:</b> <?= h($pr['min_cgpa']) ?></div><?php endif; ?>
                <?php if (!empty($admReq)): ?><div class="muted"><?= h(mb_strimwidth((string)$admReq, 0, 140, '…')) ?></div><?php endif; ?>
              </td>
              <td class="small">
                <?php if (!empty($pr['application_fee'])): ?><div><span class="muted">App Fee:</span> <b><?= h($pr['application_fee']) ?></b></div><?php endif; ?>
                <?php if (!empty($tuition)): ?><div><span class="muted">Tuition:</span> <?= h($tuition) ?></div><?php endif; ?>
              </td>
              <td class="small">
                <?php if (!empty($pr['deadline'])): ?><div><b><?= h($pr['deadline']) ?></b></div><?php endif; ?>
                <?php if (!empty($pr['study_start'])): ?><div class="muted"><?= h($pr['study_start']) ?></div><?php endif; ?>
                <?php if ($pLink): ?>
                  <div style="margin-top:0.2rem;"><a href="<?= h($pLink) ?>" target="_blank" rel="noopener noreferrer" class="btn sm" style="padding:0.12rem 0.45rem; font-size:0.74rem;">🔗 Official Link ↗</a></div>
                <?php elseif (!empty($pr['deadline_note'])): ?>
                  <div class="muted"><?= h(mb_strimwidth((string)$pr['deadline_note'], 0, 60, '…')) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php page_end();
