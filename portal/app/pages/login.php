<?php
declare(strict_types=1);
// Unified sign-in page for Admin, Processing Officers (Front-End & Back-End), Counselors, and Students.

$portal = $portal ?? 'officer';
$idleLogout = get('idle_logout') === '1';
if ($idleLogout) {
    logout_now(null);
}

$activeUser = current_user();
if ($activeUser && get('switch') !== '1' && !$idleLogout) {
    $next = safe_next($activeUser['role'], get('next'));
    redirect($next ?: PORTALS[$activeUser['role']]['home']);
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login($portal, post('username'), $_POST['password'] ?? '');
    if ($error === null) {
        $loggedRole = $_SESSION['last_login_role'] ?? $portal;
        $next = safe_next($loggedRole, get('next'));
        redirect($next ?: PORTALS[$loggedRole]['home']);
    }
}

page_start('Sign In · Unified Portal');
?>
<div class="login">
  <div class="brand">
    <div class="mark">P</div>
    <div>
      <div class="app"><?= h($CONFIG['app_name']) ?></div>
      <small>Unified Sign-In Portal (Admin · PMS Leads · Counselors · Students)</small>
    </div>
  </div>
  <section class="panel">
    <h1>Sign in to your account</h1>
    <p class="muted">Enter your username and password. The portal will automatically open your <b>Super Admin / Admin</b>, <b>PMS Lead / Processing Staff</b>, <b>Counselor</b>, or <b>Student</b> workspace.</p>
    <?php if ($idleLogout): ?>
      <div class="flash warn" role="alert">Logged out due to inactivity</div>
    <?php endif; ?>
    <?php if ($activeUser): ?>
      <div class="flash" style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;">
        <span class="small">Currently signed in as <b><?= h($activeUser['name']) ?></b> (<?= h(role_label($activeUser['role'])) ?>)</span>
        <a class="btn sm primary" href="<?= h(url(PORTALS[$activeUser['role']]['home'])) ?>">Continue to Dashboard →</a>
      </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="on">
      <?= csrf_field() ?>
      <label class="f"><span>Username</span><input name="username" id="username" required autocomplete="username" placeholder="e.g. admin, nimra, mujassam, minahil, sehrish, farwa" value="<?= h(post('username')) ?>" autofocus></label>
      <label class="f"><span>Password</span><input name="password" id="password" type="password" required autocomplete="current-password" placeholder="Enter your password"></label>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  </section>
</div>
<?php page_end();
