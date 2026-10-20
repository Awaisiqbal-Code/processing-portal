<?php
declare(strict_types=1);
// Shared sign-in page. Expects $portal = 'admin' | 'officer' | 'student'.

$next = safe_next($portal, get('next'));
$u = current_user($portal);
if ($u && $u['role'] === $portal && get('switch') !== '1') redirect($next ?: PORTALS[$portal]['home']);

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login($portal, post('username'), $_POST['password'] ?? '');
    if ($error === null) redirect($next ?: PORTALS[$portal]['home']);
}

$hint = [
    'admin'   => 'For office administrators.',
    'officer' => 'For processing officers. You will see only the students assigned to you.',
    'student' => 'Use the username and password given to you by the office.',
][$portal];

page_start(PORTALS[$portal]['label']);
?>
<div class="login">
  <div class="brand"><div class="mark">P</div><div><div class="app"><?= h($CONFIG['app_name']) ?></div><small><?= h(PORTALS[$portal]['label']) ?></small></div></div>
  <section class="panel">
    <h1>Sign in</h1>
    <p class="muted"><?= h($hint) ?></p>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="on">
      <?= csrf_field() ?>
      <label class="f"><span>Username</span><input name="username" id="username" required autocomplete="username" value="<?= h(post('username')) ?>" autofocus></label>
      <label class="f"><span>Password</span><input name="password" id="password" type="password" required autocomplete="current-password"></label>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  </section>
  <p class="muted small"><a href="<?= h(url('index.php')) ?>">Back to all portals</a></p>
</div>
<?php page_end();
