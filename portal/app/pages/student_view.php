<?php
declare(strict_types=1);
// Student portal ("My file"). Expects $u, $sid (student id) and $preview (true when staff are looking).
// Same data and rules as the staff side: journey, pending documents, payments, meeting, applications.

require_once __DIR__ . '/../drive_resolver.php';

$driveAssets = resolve_student_drive_assets((int)$sid);
$allDriveFiles = $driveAssets['files'] ?? [];

$r = q('SELECT s.*, o.name AS officer_name, o.phone AS officer_phone, o.email AS officer_email,
               COALESCE(fo.name, s.frontend_officer_name) AS frontend_officer_name, fo.phone AS fe_phone, fo.email AS fe_email,
               cu.phone AS counselor_phone
        FROM students s
        LEFT JOIN users o ON o.id = s.officer_id
        LEFT JOIN users fo ON fo.id = s.frontend_officer_id
        LEFT JOIN users cu ON cu.id = s.assigned_counselor_id
        WHERE s.id = ?', [$sid])->fetch();
if (!$r) {
    if ($preview) { http_response_code(404); exit('Student not found.'); }
    logout_now('student'); flash('Your file could not be found. Contact the office.', 'bad'); redirect('student/login.php');
}

$studentApps = q('SELECT * FROM student_applications WHERE student_id = ? ORDER BY id ASC', [$sid])->fetchAll();
$docs = [];
foreach (q('SELECT doc_index, status, file_name, file_size, file_path FROM student_documents WHERE student_id = ?', [$sid]) as $d) $docs[(int)$d['doc_index']] = $d;
$docState = load_doc_state([$sid])[$sid] ?? [];
$docInfo = student_pending_docs($r, $docState);
$r['_doc_info'] = $docInfo;
$journey = student_journey($r);
$pays = student_payments($r);
$paySum = payment_summary($pays);
$rec = meeting_recording_state($r);
$upcoming = array_values(array_filter(due_list([$r]), fn($d) => $d['date'] >= today()));
$consentTpl = get_applicable_consent_template((string)($r['country'] ?? ''));

$isStaff = ($preview || is_staff_role($u['role']));
$isStudent = !$isStaff;
$portalLabel = $preview ? PORTALS[$u['role']]['label'] : PORTALS['student']['label'];
$base = ($preview && is_admin_role($u['role'])) ? 'admin' : 'officer';
$reportBase = $preview ? (is_admin_role($u['role']) ? 'admin' : 'officer') : 'student';
$idParam = $preview ? '&id=' . $sid : '';
$driveUrl = safe_link($r['drive_link'] ?? null);

// Student-friendly words for each journey step
$friendly = [
    'registration' => ['Registration', 'Your file is opened and your team is assigned.'],
    'documents'    => ['Documents', 'You send your documents; we check and verify them.'],
    'processing'   => ['Preparation', 'We prepare your accounts, CV / SOP and shortlist universities.'],
    'university'   => ['Applications', 'We submit your applications to universities.'],
    'offer'        => ['Offer letter', 'Admission / offer letter from the university.'],
    'payment'      => ['Payments', 'Fee and consultancy installments.'],
    'visa'         => ['Visa', 'Embassy appointment, visa file and decision.'],
    'final'        => ['Complete', 'Visa granted — get ready to travel!'],
];
$doneSteps = count(array_filter($journey, fn($s) => in_array($s['status'], ['Completed', 'Skipped'], true)));
$current = null;
foreach ($journey as $k => $st) if (!in_array($st['status'], ['Completed', 'Skipped'], true)) { $current = $k; break; }
$pct = (int)round($doneSteps / max(1, count($journey)) * 100);
$firstName = trim(explode(' ', trim((string)$r['name']))[0] ?? '');
$wa = fn(?string $phone, string $txt = '') => wa_link($phone, $txt);

// What the student must do now
$todo = [];
if (!$docInfo['complete']) $todo[] = ['📄', 'Send ' . $docInfo['count'] . ' document' . ($docInfo['count'] === 1 ? '' : 's'), implode(', ', $docInfo['missing']), '#documents'];
if (($r['consent'] ?? '') !== 'Yes') $todo[] = ['✍', 'Sign the consent form', $consentTpl ? 'Download it, sign it, and send it to your officer.' : 'Your officer will share it with you.', '#consent'];
if ($upcoming) $todo[] = ['📅', $upcoming[0]['what'], 'On ' . fmt_date($upcoming[0]['date']), '#journey'];

page_start($preview ? 'Student portal preview' : 'My file', $u, $portalLabel);
?>
<?php if ($preview): ?>
<div class="flash warn sp-preview">
  <div><b><?= h(role_label($u['role'])) ?> view:</b> this is exactly what <b><?= h($r['name']) ?></b> sees in the student portal.</div>
  <div class="row-actions">
    <a class="btn sm primary" href="<?= h(url($base . '/student.php?id=' . $sid)) ?>">Open staff profile</a>
    <a class="btn sm ghost" href="<?= h(url($base . '/students.php')) ?>">Student list</a>
  </div>
</div>
<?php endif; ?>

<section class="sp-hero">
  <div class="sp-hero-main">
    <div class="eyebrow"><?= country_flag($r['country'] ?? '') ?> <?= h(($r['country'] ?: '—') . ($r['program_level'] ? ' · ' . $r['program_level'] : '') . ($r['intake'] ? ' · Intake ' . $r['intake'] : '')) ?></div>
    <h1><?= $isStudent ? 'Assalam o Alaikum, ' . h($firstName) . ' 👋' : h($r['name']) ?></h1>
    <p class="sp-now"><?php if ($r['visa'] === 'Granted'): ?>🎉 <b>Your visa is granted.</b> Your officer will share the travel steps.<?php elseif ($r['visa'] === 'Refused'): ?>Your visa was refused. Your officer will contact you about the options.<?php elseif ($current): ?>Now: <b><?= h($friendly[$current][0]) ?></b> — <?= h($friendly[$current][1]) ?><?php else: ?>Your file is complete.<?php endif; ?></p>
  </div>
  <div class="sp-ring" style="--p:<?= $pct ?>" role="img" aria-label="<?= $doneSteps ?> of <?= count($journey) ?> steps done">
    <span><b><?= $pct ?>%</b><small><?= $doneSteps ?> / <?= count($journey) ?> steps</small></span>
  </div>
</section>

<?php if ($todo): ?>
<section class="panel sp-todo">
  <div class="panel-h"><h2><?= $isStudent ? 'What you need to do' : 'Waiting on the student' ?></h2></div>
  <div class="sp-todo-list">
    <?php foreach ($todo as [$ic, $t, $sub, $href]): ?>
      <a class="sp-todo-item" href="<?= h($href) ?>"><span class="sp-ic"><?= $ic ?></span><span><b><?= h($t) ?></b><small><?= h($sub) ?></small></span><span class="sp-go">→</span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<div class="flash ok">✅ Nothing needed from <?= $isStudent ? 'you' : 'the student' ?> right now. Your team is working on the next step.</div>
<?php endif; ?>

<section class="panel" id="journey">
  <div class="panel-h"><h2>Your journey</h2><p>Every step of your file, from registration to visa.</p></div>
  <ol class="sp-steps">
    <?php foreach ($journey as $k => $st):
      $state = in_array($st['status'], ['Completed', 'Skipped'], true) ? 'done' : ($k === $current ? 'now' : ($st['status'] === 'On Hold' || $st['status'] === 'Rejected' ? 'hold' : 'later')); ?>
      <li class="<?= $state ?>">
        <span class="sp-dot"><?= $state === 'done' ? '✓' : array_search($k, array_keys($journey), true) + 1 ?></span>
        <div><b><?= h($friendly[$k][0]) ?></b><small><?= h($friendly[$k][1]) ?></small>
          <?php if ($st['date']): ?><small class="sp-date"><?= h(fmt_date((string)$st['date'])) ?></small><?php endif; ?></div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($upcoming): ?><p class="flash acc" style="margin:12px 0 0"><b>Coming up:</b> <?= h($upcoming[0]['what']) ?> on <?= h(fmt_date($upcoming[0]['date'])) ?></p><?php endif; ?>
</section>

<div class="sp-grid">
  <section class="panel" id="documents">
    <div class="panel-h"><div><h2>Documents</h2><p><?= $docInfo['complete'] ? '✓ All required documents received.' : $docInfo['in_hand'] . ' of ' . $docInfo['required'] . ' received · ' . $docInfo['count'] . ' still needed' ?></p></div>
      <?php if ($isStaff && $driveUrl): ?><a class="btn sm" href="<?= h($driveUrl) ?>" target="_blank" rel="noopener noreferrer">📁 Drive ↗</a><?php endif; ?></div>
    <div class="sp-bar"><i style="width:<?= (int)round($docInfo['in_hand'] / max(1, $docInfo['required']) * 100) ?>%"></i></div>
    <div class="sp-docs">
    <?php foreach (DOCS as $i => $d):
      $dRow = $docs[$i] ?? null; $v = (string)($dRow['status'] ?? '');
      if ($v === 'Uploaded') $v = 'Received';
      $hasFile = !empty($dRow['file_path']);
      $later = $i >= 9;
      $needed = in_array($i, $docInfo['missing_idx'] ?? [], true);
      if ($later && !$hasFile && !$v) continue;   // offer / CAS / visa letters appear once they exist
      if (!$later && !isset(REQUIRED_DOCS[$i])) continue;
      $isBsSkip = $i === 4 && strtoupper((string)($r['program_level'] ?? '')) === 'BS';
      if ($isBsSkip && !$hasFile) continue;
      $label = $needed ? ($v === 'Requested' ? 'Asked again' : 'Needed') : ($v === 'Verified' ? 'Verified' : ($v === 'Not Required' ? 'Not needed' : ($later && !$hasFile && in_array($v, ['', 'Pending', 'Requested'], true) ? 'Not yet' : 'Received')));
      $tone = ['Needed' => 'bad', 'Asked again' => 'warn', 'Verified' => 'ok', 'Received' => 'acc', 'Not needed' => '', 'Not yet' => ''][$label]; ?>
      <div class="sp-doc <?= $needed ? 'is-needed' : '' ?>">
        <div class="sp-doc-main"><b><?= h($d) ?></b><?php if ($hasFile && !str_starts_with((string)$dRow['file_path'], 'gdrive:')): ?><small>📎 <?= h($dRow['file_name'] ?? '') ?></small><?php endif; ?></div>
        <span class="pill sm <?= $tone ?>"><?= $label === 'Verified' ? '✓ ' : '' ?><?= h($label) ?></span>
        <div class="sp-doc-act">
          <?php if ($hasFile): ?><a class="btn sm" href="<?= h(url('document.php?id=' . $sid . '&doc=' . $i)) ?>" target="_blank">View</a><?php endif; ?>
          <?php if ($needed): ?>
            <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" class="sp-up">
              <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$sid ?>"><input type="hidden" name="doc_index" value="<?= (int)$i ?>">
              <label class="btn sm primary sp-file">⬆ Upload<input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onchange="this.form.submit()" aria-label="Upload <?= h($d) ?>"></label>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php foreach ($docInfo['missing'] as $m): if (in_array($m, REQUIRED_DOCS, true)) continue; ?>
      <div class="sp-doc is-needed"><div class="sp-doc-main"><b><?= h($m) ?></b><small>Asked by your officer</small></div><span class="pill sm bad">Needed</span><div class="sp-doc-act"></div></div>
    <?php endforeach; ?>
    </div>
    <details class="sp-more"><summary>Send another document</summary>
      <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" class="fields" style="margin-top:8px">
        <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$sid ?>">
        <label class="f"><span>What is it?</span><input name="custom_title" required placeholder="e.g. Experience letter, LOR"></label>
        <label class="f"><span>File</span><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></label>
        <div class="f"><span>&nbsp;</span><button class="btn primary sm" type="submit">Upload</button></div>
      </form>
    </details>
    <?php $customDocs = q('SELECT * FROM custom_documents WHERE student_id = ? ORDER BY id DESC', [$sid])->fetchAll(); if ($customDocs): ?>
      <div class="sp-docs" style="margin-top:8px">
        <?php foreach ($customDocs as $cd): ?>
          <div class="sp-doc"><div class="sp-doc-main"><b><?= h($cd['title']) ?></b><small>📎 <?= h($cd['file_name']) ?></small></div><span class="pill sm acc">Sent</span>
            <div class="sp-doc-act"><a class="btn sm" href="<?= h(url('document.php?id=' . $sid . '&cid=' . $cd['id'])) ?>" target="_blank">View</a></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="stack">
    <section class="panel" id="team">
      <div class="panel-h"><h2>Your team</h2></div>
      <?php foreach ([['Front-end officer', $r['frontend_officer_name'], $r['fe_phone'] ?? null, $r['fe_email'] ?? null, 'Documents & first meeting'],
                      ['Back-end officer', $r['officer_name'], $r['officer_phone'] ?? null, $r['officer_email'] ?? null, 'Applications, offer & visa'],
                      ['Counselor', $r['counselor'], $r['counselor_phone'] ?? null, null, 'Your counselling']] as [$role, $name, $ph, $em, $what]): if (!$name) continue; ?>
        <div class="sp-person">
          <span class="avatar"><?= h(user_initials((string)$name)) ?></span>
          <div><b><?= h($name) ?></b><small><?= h($role) ?> · <?= h($what) ?></small></div>
          <div class="sp-person-act">
            <?php if ($l = $wa($ph, 'Assalam o Alaikum, this is ' . $r['name'] . ' (ScholarshipHive student).')): ?><a class="btn sm" href="<?= h($l) ?>" target="_blank" rel="noopener noreferrer" title="WhatsApp">💬</a><?php endif; ?>
            <?php if ($em): ?><a class="btn sm" href="mailto:<?= h($em) ?>" title="Email">✉</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$r['frontend_officer_name'] && !$r['officer_name']): ?><p class="muted small">Your team will be assigned shortly.</p><?php endif; ?>
    </section>

    <section class="panel" id="consent">
      <div class="panel-h"><h2>Consent form</h2><span class="pill sm <?= ($r['consent'] ?? '') === 'Yes' ? 'ok' : 'warn' ?>"><?= ($r['consent'] ?? '') === 'Yes' ? '✓ Received' : 'Not received yet' ?></span></div>
      <?php if ($consentTpl): ?>
        <p class="small muted"><?= ($r['consent'] ?? '') === 'Yes' ? 'Thank you — your signed form is on file.' : 'Download the form, sign it, and send the signed copy to your officer (or upload it as “Signed consent form” above).' ?></p>
        <a class="btn sm" href="<?= h(url('consent_download.php?type=template&id=' . (int)$consentTpl['id'] . '&download=1')) ?>">⬇ <?= h($consentTpl['title']) ?></a>
      <?php else: ?><p class="small muted">Your officer will share the consent form with you.</p><?php endif; ?>
    </section>

    <section class="panel" id="payments">
      <div class="panel-h"><h2>Payments</h2><span class="small muted"><?= $paySum['received'] ?> of <?= $paySum['total'] ?> received</span></div>
      <div class="sp-pays">
        <?php foreach ($pays as $p): $okp = $p['status'] === 'Received'; ?>
          <div class="sp-pay <?= $okp ? 'is-ok' : '' ?>"><span class="sp-pay-n"><?= ordinal_label((int)$p['installment_no']) ?></span>
            <span><?= $okp ? '✓ Received' : h($p['status']) ?><?= $p['received_date'] ? ' · ' . h(fmt_date((string)$p['received_date'])) : '' ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if (!empty($r['meeting_video_path']) || ($rec['state'] === 'recorded' && !empty($rec['url']))): ?>
    <section class="panel" id="meeting">
      <div class="panel-h"><h2>Your meeting recording</h2></div>
      <?php if (!empty($r['meeting_video_path'])): ?>
        <video class="meet-video" controls preload="metadata" src="<?= h(url('meeting_video.php?id=' . (int)$sid)) ?>"></video>
      <?php elseif ($rec['url']): ?>
        <a class="btn primary" href="<?= h($rec['url']) ?>" target="_blank" rel="noopener noreferrer">▶ Watch recording</a>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($studentApps): ?>
<section class="panel" id="applications">
  <div class="panel-h"><div><h2>Your universities (<?= count($studentApps) ?>)</h2><p>Where we are applying for you and the latest status.</p></div></div>
  <div class="sp-apps">
    <?php foreach ($studentApps as $app): $st = (string)($app['status'] ?: 'Shortlisted'); ?>
      <article class="sp-app">
        <b><?= h($app['university'] ?: 'University') ?></b>
        <small><?= h($app['program'] ?: '—') ?></small>
        <div class="sp-app-meta">
          <span class="pill sm <?= stripos($st, 'admit') !== false || stripos($st, 'accept') !== false || stripos($st, 'offer') !== false ? 'ok' : (stripos($st, 'reject') !== false ? 'bad' : 'acc') ?>"><?= h($st) ?></span>
          <?php if (!empty($app['deadline'])): ?><small>Deadline <?= h($app['deadline']) ?></small><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel sp-foot">
  <div class="row-actions">
    <a class="btn sm" href="<?= h(url($reportBase . '/report.php?format=pdf' . $idParam)) ?>">⬇ Download my file summary (PDF)</a>
    <?php if ($isStudent): ?><a class="btn sm ghost" href="<?= h(url('account/password.php')) ?>">Change password</a><?php endif; ?>
  </div>
</section>
<?php page_end();
