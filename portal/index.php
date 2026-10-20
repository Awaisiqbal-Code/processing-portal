<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require APP . '/layout.php';

$uAdmin = current_user('admin');
$uOff   = current_user('officer');
$uStu   = current_user('student');

page_start('Select Portal');
?>
<div class="login wide-login">
  <div class="brand"><div class="mark">P</div><div><div class="app"><?= h($CONFIG['app_name']) ?></div><small>Choose your portal</small></div></div>
  <div class="portal-grid">
    <div class="portal-card" style="display:flex;flex-direction:column;justify-content:space-between;gap:0.75rem;">
      <div>
        <b>Student portal</b>
        <span style="display:block;margin-top:0.25rem;">Check where your file is, your steps and your documents.</span>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        <?php if ($uStu): ?>
          <a class="btn sm primary" href="<?= h(url('student/index.php')) ?>">Open (<?= h($uStu['name']) ?>)</a>
          <a class="btn sm ghost" href="<?= h(url('student/login.php?switch=1')) ?>">Switch account</a>
        <?php else: ?>
          <a class="btn sm primary" href="<?= h(url('student/login.php')) ?>">Sign in</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="portal-card" style="display:flex;flex-direction:column;justify-content:space-between;gap:0.75rem;">
      <div>
        <b>Processing officer portal</b>
        <span style="display:block;margin-top:0.25rem;">Work on the students assigned to you (Minahil, Sehrish, Farwa).</span>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        <?php if ($uOff): ?>
          <a class="btn sm primary" href="<?= h(url('officer/index.php')) ?>">Open (<?= h($uOff['name']) ?>)</a>
          <a class="btn sm ghost" href="<?= h(url('officer/login.php?switch=1')) ?>">Switch officer</a>
        <?php else: ?>
          <a class="btn sm primary" href="<?= h(url('officer/login.php')) ?>">Sign in</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="portal-card" style="display:flex;flex-direction:column;justify-content:space-between;gap:0.75rem;">
      <div>
        <b>Admin portal</b>
        <span style="display:block;margin-top:0.25rem;">All 250 students, totals per officer, reports, staff and logins.</span>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        <?php if ($uAdmin): ?>
          <a class="btn sm primary" href="<?= h(url('admin/index.php')) ?>">Open Admin Dashboard</a>
          <a class="btn sm ghost" href="<?= h(url('admin/students.php')) ?>">All 250 Students</a>
        <?php else: ?>
          <a class="btn sm primary" href="<?= h(url('admin/login.php')) ?>">Sign in as Admin</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php page_end();
