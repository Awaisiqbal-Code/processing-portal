<?php
// Expects $due (from due_list), $base ('admin' | 'officer'), $admin (bool)
if (!$due): ?>
  <div class="empty">No follow-ups pending.</div>
<?php else: ?>
  <div class="list">
  <?php foreach (array_slice($due, 0, 12) as $d):
      $t = today();
      $cls = $d['date'] < $t ? 'bad' : ($d['date'] === $t ? 'warn' : 'acc');
      $lbl = $d['date'] < $t ? 'Overdue · ' : ($d['date'] === $t ? 'Today · ' : ''); ?>
    <a class="li" href="<?= h(url($base . '/student.php?id=' . $d['r']['id'])) ?>">
      <div><b><?= h($d['r']['name']) ?></b><div class="m"><?= h($d['what']) ?><?= $admin ? ' · ' . h($d['r']['officer_name'] ?? 'Not assigned') : '' ?></div></div>
      <span class="pill <?= $cls ?>"><?= $lbl . h(fmt_date($d['date'])) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
  <?php if (count($due) > 12): ?><p class="muted small"><?= count($due) - 12 ?> more not shown.</p><?php endif; ?>
<?php endif;
