<?php
declare(strict_types=1);
// Step 3 of the reset: the one-time link (opened after the emailed code was verified).
require __DIR__ . '/app/bootstrap.php';
require APP . '/layout.php';

$token = (string)(get('t') ?: post('t'));
$req = reset_request_for_token($token);
$error = null;
if (is_post() && $req) {
    csrf_check();
    [$ok, $msg] = complete_password_reset($token, (string)($_POST['new'] ?? ''), (string)($_POST['repeat'] ?? ''));
    if ($ok) { flash($msg); redirect('index.php'); }
    $error = $msg;
}
page_start('Set a new password · ScholarshipHive Edutancy');
?>
<div class="narrow auth-narrow">
<section class="panel">
  <div class="steps-mini"><span class="done">1 · Ask the admin</span><span class="done">2 · Code</span><span class="on">3 · New password</span></div>
  <?php if (!$req): ?>
    <h1>Link expired</h1>
    <p>This link has expired or was already used. Links work for <?= RESET_LINK_MINUTES ?> minutes.</p>
    <p><a class="btn primary" href="<?= h(url('forgot_password.php')) ?>">Ask for a new reset</a></p>
  <?php else: ?>
    <h1>Set a new password</h1>
    <p class="muted">For <b><?= h($req['name']) ?></b> (<?= h($req['username']) ?>). After saving, every other sign-in of this account is closed.</p>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack" data-pw-form data-user="<?= h(strtolower($req['username'] . ' ' . $req['name'])) ?>">
      <?= csrf_field() ?><input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="text" name="username" value="<?= h($req['username']) ?>" autocomplete="username" hidden>
      <label class="f"><span>New password</span><input type="password" name="new" required minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" data-pw-new autofocus></label>
      <?php include APP . '/pages/_pw_rules.php'; ?>
      <label class="f"><span>Repeat new password</span><input type="password" name="repeat" required minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" data-pw-repeat></label>
      <button class="btn primary" type="submit">Save new password</button>
    </form>
  <?php endif; ?>
</section>
</div>
<?php page_end();
