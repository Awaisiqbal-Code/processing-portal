<?php
declare(strict_types=1);
// Student portal view. Expects $u, $sid (student id) and $preview (true when an admin is looking).

require_once __DIR__ . '/../drive_resolver.php';

// Auto-resolve specific Google Drive files and recorded meeting link if not cached yet
$driveAssets = resolve_student_drive_assets((int)$sid);
$allDriveFiles = $driveAssets['files'] ?? [];

$r = q('SELECT s.id, s.name, s.counselor, s.branch, s.intake, s.drive_link, s.meeting_link, s.drive_files_json,
               s.consent, s.docs_checklist, s.initial_meeting, s.recorded_uploaded, s.second_meeting,
               s.tax_meeting, s.offer, s.enrollment, s.visa,
               s.follow_up_1, s.follow_up_2, s.follow_up_3,
               s.email, s.portal_id, s.shortlisting, s.pending_docs_note,
               s.officer_id, s.frontend_officer_id,
               o.name AS officer_name,
               COALESCE(fo.name, s.frontend_officer_name) AS frontend_officer_name
        FROM students s
        LEFT JOIN users o ON o.id = s.officer_id
        LEFT JOIN users fo ON fo.id = s.frontend_officer_id
        WHERE s.id = ?', [$sid])->fetch();
if (!$r) {
    if ($preview) { http_response_code(404); exit('Student not found.'); }
    logout_now('student'); flash('Your file could not be found. Contact the office.', 'bad'); redirect('student/login.php');
}

$docs = [];
foreach (q('SELECT doc_index, status, file_name, file_size, file_path FROM student_documents WHERE student_id = ?', [$sid]) as $d) {
    $docs[(int)$d['doc_index']] = $d;
}

$ns = next_stage($r);
$done = done_count($r);
$total = count(STAGES);
if ($r['visa'] === 'Granted') $head = ['Visa granted', 'Congratulations. Your processing officer will share the next steps for travel.'];
elseif ($r['visa'] === 'Refused') $head = ['Visa refused', 'Your processing officer will contact you about the options.'];
elseif ($ns) { $i = array_search($ns, array_column(STAGES, 0), true); $head = [$ns, STAGES[$i][2]]; }
else $head = ['File complete', ''];
$upcoming = array_values(array_filter(due_list([$r]), fn($d) => $d['date'] >= today()));

$portalLabel = $preview ? PORTALS[$u['role']]['label'] : PORTALS['student']['label'];
$base = ($preview && is_admin_role($u['role'])) ? 'admin' : (($preview && is_officer_role($u['role'])) ? 'officer' : 'student');
$reportBase = $preview ? (is_admin_role($u['role']) ? 'admin' : 'officer') : 'student';
$idParam = $preview ? '&id=' . $sid : '';
$driveUrl = safe_link($r['drive_link'] ?? null);
$hasMeeting = student_has_meeting($r);
$meetingUrl = url('meeting.php?id=' . (int)$sid);

$isStaff = ($preview || is_staff_role($u['role']));
$isStudent = !$isStaff;

// Compute pending required documents for banner notification
$pendingDocsList = [];
foreach (DOCS as $idx => $dName) {
    if (empty($docs[$idx]['file_path'])) {
        $pendingDocsList[$idx] = $dName;
    }
}
$pendingCount = count($pendingDocsList);

page_start($preview ? 'Student progress view' : 'My file', $u, $portalLabel);
if ($preview): ?>
<div class="flash warn" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem; margin-bottom: 1rem;">
  <div><b><?= h(role_label($u['role'])) ?> Access:</b> Viewing live student portal for <b><?= h($r['name']) ?></b>.</div>
  <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
    <?php if (is_admin_role($u['role']) && !empty($r['officer_id'])): ?>
      <a class="btn sm" href="<?= h(url('admin/officers.php?id=' . $r['officer_id'])) ?>">← <?= h($r['officer_name'] ?: 'Officer') ?>'s Workspace</a>
    <?php endif; ?>
    <a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $sid)) ?>">Edit student record</a>
    <a class="btn sm ghost" href="<?= h(url($base . '/students.php')) ?>">Student list</a>
  </div>
</div>
<?php endif; ?>

<!-- Prominent Notification Banner for Pending Documents -->
<?php if ($pendingCount > 0): ?>
  <div style="background: var(--surface); border: 1.5px solid #f59e0b; border-left: 6px solid #d97706; border-radius: 8px; padding: 1.15rem 1.35rem; margin-bottom: 1.25rem; box-shadow: 0 2px 10px rgba(217, 119, 6, 0.08);">
    <div style="display:flex; gap:0.85rem; align-items:flex-start;">
      <div style="font-size: 1.65rem; line-height: 1;">⚠️</div>
      <div style="flex: 1;">
        <h3 style="margin: 0 0 0.35rem 0; font-size: 1.05rem; color: #b45309; font-weight:700;">
          <?= $isStudent ? 'Action Required: ' . $pendingCount . ' Pending Documents' : 'Notice: ' . $pendingCount . ' Documents Pending for this Student' ?>
        </h3>
        <p style="margin: 0 0 0.55rem 0; font-size: 0.88rem; color: var(--fg); line-height: 1.5;">
          <?= $isStudent ? 'Please upload the following required documents below so our processing team can proceed with your university admission and scholarships:' : 'The following required documents are still pending submission or file attachment:' ?>
        </p>
        <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:0.4rem;">
          <?php foreach ($pendingDocsList as $pDoc): ?>
            <span style="display:inline-block; background:rgba(245, 158, 11, 0.15); color:#b45309; border: 1px solid rgba(217, 119, 6, 0.3); padding:0.2rem 0.6rem; border-radius:4px; font-size:0.8rem; font-weight:600;">
              • <?= h($pDoc) ?>
            </span>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($r['pending_docs_note'])): ?>
          <div style="background: var(--bg); border: 1px dashed var(--border); border-radius: 6px; padding: 0.65rem 0.9rem; font-size: 0.85rem; margin-top: 0.5rem;">
            <b>📝 Processing Officer Note:</b> <?= nl2br(h($r['pending_docs_note'])) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div style="background: var(--surface); border: 1.5px solid #10b981; border-left: 6px solid #059669; border-radius: 8px; padding: 0.9rem 1.25rem; margin-bottom: 1.25rem; display:flex; gap:0.75rem; align-items:center;">
    <div style="font-size: 1.45rem;">✅</div>
    <div style="font-size: 0.9rem; color: var(--fg); font-weight:500;">
      All required documents have been received! Our processing team is preparing and submitting your university applications.
    </div>
  </div>
<?php endif; ?>

<section class="panel hero-step">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
    <div>
      <div class="eyebrow">Where your file is now</div>
      <h1><?= h($head[0]) ?></h1>
      <p class="muted"><?= h($head[1]) ?></p>
    </div>
    <?php if ($isStaff): ?>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <?php if ($driveUrl): ?>
          <a class="btn sm" href="<?= h($driveUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open Student Documents Folder in Google Drive">📁 Open Documents Folder</a>
        <?php endif; ?>
        <?php if ($hasMeeting): ?>
          <a class="btn sm" href="<?= h($meetingUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open Recorded Meeting Video">🎥 Open Recorded Meeting</a>
        <?php endif; ?>
        <a class="btn sm primary" href="<?= h(url($reportBase . '/report.php?format=pdf' . $idParam)) ?>">⬇ Download Student Report (PDF)</a>
        <a class="btn sm ghost" href="<?= h(url($reportBase . '/report.php?format=csv' . $idParam)) ?>">Download Student CSV</a>
      </div>
    <?php endif; ?>
  </div>
  <div class="prog"><i style="width:<?= round($done / $total * 100, 1) ?>%"></i></div>
  <div class="num small muted"><?= $done ?> of <?= $total ?> steps complete</div>
  <?php if ($upcoming): ?><div class="flash acc"><b>Upcoming:</b> <?= h($upcoming[0]['what']) ?> on <?= h(fmt_date($upcoming[0]['date'])) ?></div><?php endif; ?>
</section>

<div class="grid2">
  <section class="panel">
    <div class="panel-h"><h2>Your steps</h2></div>
    <ol class="tl">
    <?php foreach (STAGES as $i => $s):
        $st = stage_done($r, $i) ? 'done' : ($s[0] === $ns ? 'now' : 'later'); ?>
      <li class="<?= $st ?>"><span class="dot"></span>
        <div>
          <b><?= h($s[0]) ?></b>
          <div class="m"><?= h($s[2]) ?></div>
          <?php if ($isStaff): ?>
            <?php if ($s[1] === 'recorded_uploaded'): ?>
              <?php if ($hasMeeting): ?>
                <div style="margin-top:0.35rem; display:flex; gap:0.4rem; flex-wrap:wrap;">
                  <a class="btn sm" href="<?= h($meetingUrl) ?>" target="_blank" rel="noopener noreferrer">🎥 Open Meeting / Recording ↗</a>
                  <a class="btn sm ghost" href="<?= h($meetingUrl . '&download=1') ?>">⬇ Download Recording</a>
                </div>
              <?php else: ?>
                <div class="small" style="color:#ef4444; margin-top:0.35rem; font-weight:600;">
                  ⚠️ Recorded meeting video not available
                </div>
              <?php endif; ?>
            <?php elseif ($s[1] === 'docs_checklist' && $driveUrl): ?>
              <div style="margin-top:0.35rem;"><a class="btn sm" href="<?= h($driveUrl) ?>" target="_blank" rel="noopener noreferrer">📁 Open Documents Folder ↗</a></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
          $pillClass = $st === 'done' ? 'ok' : ($st === 'now' ? 'acc' : '');
          $pillText = $st === 'done' ? 'Done' : ($st === 'now' ? 'In progress' : 'Upcoming');
          if ($s[1] === 'recorded_uploaded' && !$hasMeeting) {
              $pillClass = 'bad';
              $pillText = 'Not Available';
          }
        ?>
        <span class="pill <?= $pillClass ?>"><?= $pillText ?></span></li>
    <?php endforeach; ?>
    </ol>
  </section>

  <div class="stack">
    <section class="panel">
      <div class="panel-h">
        <div>
          <h2>Required Documents</h2>
          <p><?= $isStudent ? 'Upload any pending documents below or inspect your verified files' : 'Click View or Download to inspect verified files or attach replacements' ?></p>
        </div>
        <?php if ($isStaff && $driveUrl): ?>
          <a class="btn sm primary" href="<?= h($driveUrl) ?>" target="_blank" rel="noopener noreferrer">📁 Open All in Drive ↗</a>
        <?php endif; ?>
      </div>
      <div class="list">
      <?php foreach (DOCS as $i => $d): 
        $dRow = $docs[$i] ?? null;
        $v = is_array($dRow) ? ($dRow['status'] ?? '') : (string)$dRow;
        $hasFile = is_array($dRow) && !empty($dRow['file_path']);
        $fileName = ($hasFile && !empty($dRow['file_name'])) ? $dRow['file_name'] : '';
      ?>
        <div class="li" style="flex-wrap:wrap; gap:0.6rem; justify-content:space-between; align-items:flex-start;">
          <div style="flex: 1; min-width: 240px;">
            <b><?= h($d) ?></b>
            <?php if ($hasFile): ?>
              <div class="small muted" style="font-size:0.78rem; margin-top:0.15rem;">📎 <?= h($fileName) ?></div>
              <div class="small" style="margin-top:0.35rem; display:flex; gap:0.45rem; align-items:center; flex-wrap:wrap;">
                <a class="btn sm" href="<?= h(url('document.php?id=' . $sid . '&doc=' . $i)) ?>" target="_blank" title="View <?= h($fileName) ?>">📄 View <?= h($d) ?></a>
                <a class="btn sm primary" href="<?= h(url('document.php?id=' . $sid . '&doc=' . $i . '&download=1')) ?>" title="Download <?= h($fileName) ?>">⬇ Download</a>
                
                <?php if ($isStaff): ?>
                  <details style="display:inline-block;">
                    <summary class="small muted" style="cursor:pointer; font-size:0.75rem; text-decoration:underline;">Replace</summary>
                    <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" style="margin-top:0.35rem; display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
                      <input type="hidden" name="doc_index" value="<?= (int)$i ?>">
                      <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="font-size:0.75rem;">
                      <button class="btn sm primary" type="submit" style="padding:0.15rem 0.5rem; font-size:0.75rem;">Save</button>
                    </form>
                  </details>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="small" style="font-size:0.78rem; color:#b45309; margin-top:0.2rem; font-weight:500;">
                ⚠️ Not submitted yet
              </div>
              <!-- Direct inline upload form right here for both student and staff! -->
              <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" style="margin-top:0.4rem; display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
                <input type="hidden" name="doc_index" value="<?= (int)$i ?>">
                <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="font-size:0.78rem; max-width:210px;">
                <button class="btn sm primary" type="submit" style="padding:0.2rem 0.6rem; font-size:0.78rem;">
                  📤 Upload <?= h($d) ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
          <span class="pill <?= pill_class($v ?: 'Pending') ?>" style="margin-top:0.2rem;"><?= h($v ?: 'Pending') ?></span>
        </div>
      <?php endforeach; ?>
      </div>
    </section>

    <!-- Additional / Custom Documents Section (Add Manually) -->
    <?php
      $customDocs = q("SELECT * FROM custom_documents WHERE student_id = ? ORDER BY id DESC", [$sid])->fetchAll();
      $mappedFileIds = [];
      foreach ($docs as $dItem) {
          $fp = (string)($dItem['file_path'] ?? '');
          if (str_starts_with($fp, 'gdrive:')) {
              $mappedFileIds[substr($fp, 7)] = true;
          }
      }
      $extraDriveFiles = array_values(array_filter($allDriveFiles, function ($gf) use ($mappedFileIds) {
          $fid = (string)($gf['id'] ?? '');
          if ($fid === '' || isset($mappedFileIds[$fid])) return false;
          if (!empty($gf['is_meeting'])) return false;
          $fn = strtolower((string)($gf['name'] ?? '') . ' ' . (string)($gf['raw_title'] ?? ''));
          if (str_contains($fn, '.mp4') || str_contains($fn, '.m4a') || str_contains($fn, ' video')) return false;
          return true;
      }));
    ?>
    <section class="panel">
      <div class="panel-h">
        <div>
          <h2>Additional Documents & Custom Files</h2>
          <p>Extra documents, portfolios, internships, or custom files added for this student</p>
        </div>
      </div>

      <?php if (!empty($customDocs) || ($isStaff && !empty($extraDriveFiles))): ?>
      <div class="list" style="margin-bottom: 1.25rem;">
        <?php foreach ($customDocs as $cd): 
          $cKb = round(($cd['file_size'] ?? 0) / 1024, 1);
        ?>
          <div class="li" style="flex-wrap:wrap; gap:0.5rem; justify-content:space-between; align-items:center;">
            <div>
              <b>📄 <?= h($cd['title']) ?></b>
              <div class="small muted">📎 <?= h($cd['file_name']) ?> (<?= $cKb ?> KB) · Uploaded <?= fmt_date(substr((string)$cd['created_at'], 0, 10)) ?></div>
            </div>
            <div style="display:flex; gap:0.35rem; align-items:center;">
              <a class="btn sm" href="<?= h(url('document.php?id=' . $sid . '&cid=' . $cd['id'])) ?>" target="_blank">📄 View</a>
              <a class="btn sm primary" href="<?= h(url('document.php?id=' . $sid . '&cid=' . $cd['id'] . '&download=1')) ?>">⬇ Download</a>
              <?php if ($isStaff): ?>
                <form method="post" action="<?= h(url('upload_document.php')) ?>" style="display:inline;" onsubmit="return confirm('Remove this custom document?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_custom">
                  <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
                  <input type="hidden" name="cid" value="<?= (int)$cd['id'] ?>">
                  <button class="btn sm bad" type="submit" style="padding: 0.2rem 0.5rem;" title="Delete Document">🗑</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($isStaff): ?>
          <?php foreach ($extraDriveFiles as $gf):
            $fid = (string)($gf['id'] ?? '');
            $fn  = (string)($gf['name'] ?? 'Document');
            $sf  = (string)($gf['subfolder'] ?? '');
            $sz  = (string)($gf['size'] ?? '');
            $viewHref = url('document.php?id=' . $sid . '&fid=' . rawurlencode($fid) . '&name=' . rawurlencode($fn));
            $dlHref = url('document.php?id=' . $sid . '&fid=' . rawurlencode($fid) . '&name=' . rawurlencode($fn) . '&download=1');
          ?>
            <div class="li" style="flex-wrap:wrap; gap:0.5rem; justify-content:space-between; align-items:center;">
              <div>
                <b>📄 <?= h($fn) ?></b>
                <div class="small muted">
                  <?= $sf !== '' ? 'Cloud Folder: <b>' . h($sf) . '</b>' : 'Cloud Storage' ?>
                  <?= $sz !== '' ? ' · ' . h($sz) : '' ?>
                </div>
              </div>
              <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                <a class="btn sm" href="<?= h($viewHref) ?>" target="_blank" rel="noopener noreferrer">📄 View</a>
                <a class="btn sm primary" href="<?= h($dlHref) ?>">⬇ Download</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php else: ?>
        <p class="muted small" style="margin-bottom: 1rem;">No additional custom files added yet.</p>
      <?php endif; ?>

      <!-- Form to Add Additional Document Manually -->
      <div style="background: var(--bg); border: 1px dashed var(--border); border-radius: 8px; padding: 1rem;">
        <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;">➕ Add Document Manually</h4>
        <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end;">
          <?= csrf_field() ?>
          <input type="hidden" name="student_id" value="<?= (int)$sid ?>">
          <label style="flex: 1; min-width: 160px; margin:0;">
            <span class="small muted" style="display:block; margin-bottom: 0.2rem;">Document Title:</span>
            <input name="custom_title" placeholder="e.g. Work Experience, Portfolio, Affidavit" required style="width:100%; font-size:0.85rem;">
          </label>
          <label style="flex: 1; min-width: 180px; margin:0;">
            <span class="small muted" style="display:block; margin-bottom: 0.2rem;">Choose File:</span>
            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="width:100%; font-size:0.85rem;">
          </label>
          <button class="btn primary sm" type="submit" style="align-self: flex-end;">Upload Document</button>
        </form>
      </div>
    </section>

    <section class="panel">
      <div class="panel-h"><h2>Your team</h2></div>
      <div class="list">
        <div class="li"><span>Counselor</span><b><?= h($r['counselor'] ?: 'Not assigned') ?></b></div>
        <div class="li"><span>Front-End Officer</span><b><?= h($r['frontend_officer_name'] ?: 'Not assigned') ?></b></div>
        <div class="li"><span>Back-End Officer</span><b><?= h($r['officer_name'] ?: 'Not assigned') ?></b></div>
        <div class="li"><span>Branch</span><b><?= h($r['branch']) ?></b></div>
        <div class="li"><span>Intake</span><b><?= h($r['intake']) ?></b></div>
      </div>
    </section>
    <?php if (!empty($r['shortlisting']) || !empty($r['portal_id']) || !empty($r['pending_docs_note'])): ?>
    <section class="panel">
      <div class="panel-h"><h2>Application details</h2></div>
      <div class="list">
        <?php if (!empty($r['shortlisting'])): ?><div class="li"><span>Shortlisting</span><b><?= h($r['shortlisting']) ?></b></div><?php endif; ?>
        <?php if (!empty($r['portal_id'])): ?><div class="li"><span>Portal ID</span><b><?= h($r['portal_id']) ?></b></div><?php endif; ?>
        <?php if (!empty($r['pending_docs_note'])): ?><div class="li" style="flex-direction:column; align-items:flex-start; gap:0.25rem;"><span>Pending documents required</span><span class="muted" style="font-size:0.85rem;"><?= nl2br(h($r['pending_docs_note'])) ?></span></div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php page_end();
