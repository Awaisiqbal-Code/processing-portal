<?php
declare(strict_types=1);
// Verify documents — the PMS Lead (or an admin with "Verify documents") checks what officers marked Received.
// Expects $u.

if (!can_verify_docs($u)) {
    http_response_code(403);
    page_start('Verify documents', $u, PORTALS[$u['role']]['label']);
    echo '<section class="panel"><h1>Verify documents</h1><p>' . h(view_only_message('docs.verify')) . '</p></section>';
    page_end();
    exit;
}
$base = is_admin_role($u['role']) ? 'admin' : 'officer';
$self = $base . '/verify_docs.php';
[$w, $p] = scope_sql($u);

if (is_post()) {
    csrf_check();
    $act = post('action');
    $picked = [];
    foreach ((array)($_POST['docs'] ?? []) as $k) {
        if (preg_match('/^(\d+):(\d+)$/', (string)$k, $m)) $picked[] = [(int)$m[1], (int)$m[2]];
    }
    if ($act === 'verify_all') {
        foreach (docs_waiting_verification($u, (int)post('officer') ?: null) as $d) {
            if (post('country') !== '' && strcasecmp((string)$d['country'], (string)post('country')) !== 0) continue;
            $picked[] = [(int)$d['student_id'], (int)$d['doc_index']];
        }
        $act = 'verify';
    }
    $ok = 0; $students = [];
    foreach ($picked as [$sid, $idx]) {
        if (!q("SELECT 1 FROM students s WHERE s.id = ? AND $w", array_merge([$sid], $p))->fetchColumn()) continue;
        $cur = q('SELECT status FROM student_documents WHERE student_id = ? AND doc_index = ?', [$sid, $idx])->fetchColumn();
        if ($cur !== 'Received') continue;
        if ($act === 'verify' && set_document_status($u, $sid, $idx, 'Verified')) { $ok++; $students[$sid] = true; }
        if ($act === 'send_back' && set_document_status($u, $sid, $idx, 'Requested')) { $ok++; $students[$sid][] = DOCS[$idx]; }
    }
    if ($act === 'send_back' && $ok) {
        $note = trim((string)post('note'));
        foreach ($students as $sid => $names) {
            add_student_remark($u, (int)$sid, 'Documents sent back for re-check: ' . implode(', ', (array)$names) . ($note !== '' ? ' — ' . $note : ''));
        }
    }
    flash($ok ? ($act === 'verify' ? "$ok document" . ($ok === 1 ? '' : 's') . ' verified for ' . count($students) . ' student' . (count($students) === 1 ? '' : 's') . '.' : "$ok document" . ($ok === 1 ? '' : 's') . ' sent back to the officer (marked “Asked”).') : 'Nothing was selected.', $ok ? 'ok' : 'bad');
    redirect($self . (($qs = (string)post('back_qs')) !== '' ? '?' . preg_replace('/[^\w=&%.+-]/', '', $qs) : ''));
}

$fOfficer = (int)get('officer');
$fCountry = trim((string)get('country'));
$fq = strtolower(trim((string)get('q')));
$all = docs_waiting_verification($u);
$names = staff_name_map();
$perOfficer = []; $perCountry = [];
foreach ($all as $d) {
    foreach (array_unique(array_filter([(int)$d['officer_id'], (int)$d['frontend_officer_id']])) as $oid) $perOfficer[$oid] = ($perOfficer[$oid] ?? 0) + 1;
    $c = $d['country'] ?: 'Not set'; $perCountry[$c] = ($perCountry[$c] ?? 0) + 1;
}
arsort($perOfficer);
$rows = array_filter($all, function ($d) use ($fOfficer, $fCountry, $fq) {
    if ($fOfficer && (int)$d['officer_id'] !== $fOfficer && (int)$d['frontend_officer_id'] !== $fOfficer) return false;
    if ($fCountry !== '' && strcasecmp((string)($d['country'] ?: 'Not set'), $fCountry) !== 0) return false;
    if ($fq !== '' && !str_contains(strtolower((string)$d['name']), $fq)) return false;
    return true;
});
$byStudent = [];
foreach ($rows as $d) $byStudent[(int)$d['student_id']][] = $d;
$verifiedToday = (int)q("SELECT COUNT(*) FROM student_documents WHERE status = 'Verified' AND updated_by = ? AND updated_at >= ?", [(int)$u['id'], date('Y-m-d') . ' 00:00:00'])->fetchColumn();
$backQs = http_build_query(array_filter(['officer' => $fOfficer ?: null, 'country' => $fCountry ?: null, 'q' => get('q') ?: null]));
$flagMap = country_flags_map();
$MAX = 120; $n = 0;

page_start('Verify documents', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div><div class="eyebrow">Documents</div><h1>Verify documents</h1>
      <p>Officers mark a document <b>Received</b> when it comes in (uploaded here or kept in Drive). Check it and mark it <b>Verified</b> — or send it back. A student disappears from this list once everything is verified.</p></div>
  </div>
  <div class="kpis">
    <div class="kpi"><div class="l">Waiting for you</div><div class="v"><?= count($all) ?></div><div class="s">documents · <?= count(array_unique(array_column($all, 'student_id'))) ?> students</div></div>
    <div class="kpi"><div class="l">In this view</div><div class="v"><?= count($rows) ?></div><div class="s"><?= count($byStudent) ?> students</div></div>
    <div class="kpi"><div class="l">Verified by you today</div><div class="v" style="color:var(--ok)"><?= $verifiedToday ?></div><div class="s">documents</div></div>
  </div>
</section>

<section class="panel">
  <form method="get" class="toolbar" action="<?= h(url($self)) ?>">
    <select name="officer" data-autosubmit aria-label="Officer"><option value="">All officers</option>
      <?php foreach ($perOfficer as $oid => $c): ?><option value="<?= $oid ?>" <?= $fOfficer === $oid ? 'selected' : '' ?>><?= h($names[$oid] ?? ('#' . $oid)) ?> (<?= $c ?>)</option><?php endforeach; ?>
    </select>
    <select name="country" data-autosubmit aria-label="Country"><option value="">All countries</option>
      <?php foreach ($perCountry as $cn => $c): ?><option <?= strcasecmp($fCountry, $cn) === 0 ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= h(get('q')) ?>" placeholder="Search student…">
    <button class="btn sm" type="submit">Filter</button>
    <?php if ($fOfficer || $fCountry || $fq): ?><a class="btn sm ghost" href="<?= h(url($self)) ?>">Reset</a><?php endif; ?>
  </form>

  <?php if (!$byStudent): ?>
    <div class="empty">✓ Nothing waiting. Every received document here is verified.</div>
  <?php else: ?>
  <form method="post" id="verify-form">
    <?= csrf_field() ?><input type="hidden" name="back_qs" value="<?= h($backQs) ?>">
    <input type="hidden" name="officer" value="<?= $fOfficer ?: '' ?>"><input type="hidden" name="country" value="<?= h($fCountry) ?>">
    <div class="verify-bar">
      <label class="check"><input type="checkbox" data-check-all="#verify-form"> Select all shown</label>
      <span style="flex:1"></span>
      <button class="btn sm primary" name="action" value="verify">✓ Verify selected</button>
      <button class="btn sm" name="action" value="send_back" data-needs-note>↩ Send back selected</button>
      <input name="note" placeholder="Reason for sending back (optional)" maxlength="200" class="verify-note">
      <?php if (!$fq): ?><button class="btn sm" name="action" value="verify_all" onclick="return confirm('Mark all <?= count($rows) ?> documents in this view as Verified?')">Verify all <?= count($rows) ?> in this view</button><?php endif; ?>
    </div>
    <div class="verify-list">
    <?php foreach ($byStudent as $sid => $docs): if (++$n > $MAX) break; $d0 = $docs[0]; $cn = $d0['country'] ?: ''; ?>
      <div class="verify-row">
        <div class="vr-who">
          <label class="check"><input type="checkbox" data-row-all aria-label="Select all documents of <?= h($d0['name']) ?>"></label>
          <div>
            <a href="<?= h(url($base . '/student.php?id=' . $sid)) ?>#documents"><b><?= h($d0['name']) ?></b></a>
            <div class="small muted"><?= $flagMap[$cn] ?? '' ?> <?= h($cn ?: '—') ?><?= $d0['program_level'] ? ' · ' . h($d0['program_level']) : '' ?>
              · <span class="tag-fe">FE</span> <?= h($names[(int)$d0['frontend_officer_id']] ?? '—') ?> · <span class="tag-be">BE</span> <?= h($names[(int)$d0['officer_id']] ?? '—') ?></div>
          </div>
        </div>
        <div class="vr-docs">
          <?php foreach ($docs as $d): $hasFile = !empty($d['file_path']); ?>
            <span class="v-doc">
              <label><input type="checkbox" name="docs[]" value="<?= $sid ?>:<?= (int)$d['doc_index'] ?>"><span><?= h(DOCS[(int)$d['doc_index']] ?? 'Document') ?></span></label>
              <?php if ($hasFile): ?><a href="<?= h(url('document.php?id=' . $sid . '&doc=' . (int)$d['doc_index'])) ?>" target="_blank" title="Open <?= h($d['file_name'] ?: 'file') ?>">View</a><?php endif; ?>
              <small class="muted"><?= $d['updated_by'] ? 'by ' . h($names[(int)$d['updated_by']] ?? '') : '' ?></small>
            </span>
          <?php endforeach; ?>
        </div>
        <div class="vr-go">
          <?php if ($dl = safe_link($d0['drive_link'] ?? null)): ?><a class="btn sm" href="<?= h($dl) ?>" target="_blank" rel="noopener noreferrer">Drive ↗</a><?php endif; ?>
          <a class="btn sm" href="<?= h(url($base . '/student.php?id=' . $sid)) ?>#documents">Open</a>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (count($byStudent) > $MAX): ?><p class="small muted">Showing <?= $MAX ?> of <?= count($byStudent) ?> students. Use the filters, or “Verify all in this view”.</p><?php endif; ?>
  </form>
  <?php endif; ?>
</section>
<?php page_end();
