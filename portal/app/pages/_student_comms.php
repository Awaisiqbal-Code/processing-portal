<?php
declare(strict_types=1);
// Additional documents + communication history panels on the student profile.
// Expects: $u, $r, $id, $customDocs, $isCounselor

$cmList = list_communications($id);
$cmCanAdd = is_staff_role($u['role'] ?? '');
?>
<section class="panel" id="other-documents" style="margin-top:1.5rem;">
  <div class="panel-h">
    <div><h2>Other Documents (<?= count($customDocs) ?>)</h2><p>Anything outside the standard checklist — police certificate, medical, affidavit, scholarship letters, fee receipts.</p></div>
  </div>
  <?php if ($customDocs): ?>
    <div class="doc-grid" style="margin-bottom:14px;">
      <?php foreach ($customDocs as $cd): ?>
        <div class="doc-card has-file">
          <div><b style="font-size:0.88rem;"><?= h($cd['title']) ?></b>
            <div class="small muted"><?= h($cd['file_name']) ?><?= (int)$cd['file_size'] > 0 ? ' · ' . round((int)$cd['file_size'] / 1024, 1) . ' KB' : '' ?> · <?= h(fmt_date(substr((string)$cd['created_at'], 0, 10))) ?></div>
          </div>
          <div class="row-actions" style="margin-top:8px;">
            <a class="btn sm" href="<?= h(url('document.php?id=' . (int)$id . '&cid=' . (int)$cd['id'])) ?>" target="_blank">📄 View</a>
            <a class="btn sm primary" href="<?= h(url('document.php?id=' . (int)$id . '&cid=' . (int)$cd['id'] . '&download=1')) ?>">⬇ Download</a>
            <?php if (!$isCounselor): ?>
              <form method="post" action="<?= h(url('upload_document.php')) ?>" onsubmit="return confirm('Remove this document?');" style="margin-left:auto;">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_custom"><input type="hidden" name="student_id" value="<?= (int)$id ?>"><input type="hidden" name="cid" value="<?= (int)$cd['id'] ?>">
                <button class="btn sm ghost" type="submit" title="Remove">🗑</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!$isCounselor): ?>
    <form method="post" action="<?= h(url('upload_document.php')) ?>" enctype="multipart/form-data" class="other-doc-form">
      <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="upload">
      <label class="f"><span>Document title</span><input name="custom_title" required maxlength="120" list="dl-other-docs" placeholder="e.g. Police character certificate"></label>
      <datalist id="dl-other-docs"><?php foreach (['Police character certificate', 'Medical certificate', 'Affidavit', 'Domicile', 'Birth certificate', 'Family registration (FRC)', 'Experience letter', 'Recommendation letter', 'Motivation letter / SOP', 'Fee receipt', 'Scholarship letter', 'Accommodation proof', 'Health insurance', 'Travel ticket'] as $o): ?><option value="<?= h($o) ?>"></option><?php endforeach; ?></datalist>
      <label class="dropzone"><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.zip"><span data-dz-label>⬆ Drop file or click to choose (max 25MB)</span></label>
      <button class="btn primary sm" type="submit">Upload</button>
    </form>
  <?php endif; ?>
</section>

<section class="panel" id="communication" style="margin-top:1.5rem;">
  <div class="panel-h">
    <div><h2>Communication History (<?= count($cmList) ?>)</h2><p>Calls, WhatsApp chats, emails and meetings with the student — with follow-up dates.</p></div>
  </div>
  <?php if ($cmCanAdd): ?>
    <details class="ws-edit no-print" <?= $cmList ? '' : 'open' ?>>
      <summary class="btn sm">＋ Log a conversation</summary>
      <form method="post" class="comm-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_comm"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <label class="f"><span>Channel</span><select name="comm_channel"><?php foreach (COMM_CHANNELS as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></label>
        <label class="f"><span>Direction</span><select name="comm_direction"><option value="Outgoing">We contacted student</option><option value="Incoming">Student contacted us</option></select></label>
        <label class="f"><span>Date</span><input type="date" name="comm_date" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>"></label>
        <label class="f"><span>Follow-up on</span><input type="date" name="comm_follow_up"></label>
        <label class="f wide"><span>Summary</span><textarea name="comm_summary" rows="2" required maxlength="3000" placeholder="What was discussed, what the student will send / do next…"></textarea></label>
        <div><button class="btn primary sm" type="submit">Save</button></div>
      </form>
    </details>
  <?php endif; ?>
  <?php if (!$cmList): ?>
    <div class="empty">No conversations logged yet.</div>
  <?php else: ?>
    <ul class="timeline comm-timeline">
      <?php foreach ($cmList as $cm):
          $icon = ['Call' => '📞', 'WhatsApp' => '💬', 'Email' => '✉️', 'Meeting' => '🤝', 'Zoom / Online' => '🎥', 'SMS' => '📱', 'Walk-in' => '🚶'][$cm['channel']] ?? '•';
          $fuDue = !empty($cm['follow_up_date']) && $cm['follow_up_date'] <= date('Y-m-d');
      ?>
        <li>
          <div class="comm-head"><span class="comm-ico"><?= $icon ?></span><b><?= h($cm['channel']) ?></b>
            <?php if ($cm['direction']): ?><span class="pill sm"><?= h($cm['direction']) ?></span><?php endif; ?>
            <span class="muted small"><?= h(fmt_date($cm['contact_date'])) ?> · <?= h($cm['by_name'] ?: 'Staff') ?></span>
            <?php if (!empty($cm['follow_up_date'])): ?><span class="pill sm <?= $fuDue ? 'bad' : 'warn' ?>">Follow-up <?= h(fmt_date($cm['follow_up_date'])) ?></span><?php endif; ?>
          </div>
          <div class="comm-body"><?= nl2br(h($cm['summary'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
