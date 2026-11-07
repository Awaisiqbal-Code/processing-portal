<?php
declare(strict_types=1);
// Forgot password: 1) ask the admin  2) enter the emailed code  -> a one-time link to set a new password.
require __DIR__ . '/app/bootstrap.php';
require APP . '/layout.php';

$step = get('step') === 'code' ? 'code' : 'request';
$done = null; $error = null;
if (is_post()) {
    csrf_check();
    if (post('action') === 'request') {
        $done = request_password_reset((string)post('username'), (string)post('note'));
    } elseif (post('action') === 'code') {
        $step = 'code';
        [$ok, $res] = verify_reset_code((string)post('username'), (string)post('code'));
        if ($ok) redirect('reset_password.php?t=' . $res);
        $error = $res;
    }
}
page_start('Forgot password · ScholarshipHive Edutancy');
?>
<div class="narrow auth-narrow">
<section class="panel">
  <div class="steps-mini" aria-label="Steps">
    <span class="<?= $step === 'request' ? 'on' : 'done' ?>">1 · Ask the admin</span>
    <span class="<?= $step === 'code' ? 'on' : '' ?>">2 · Enter the emailed code</span>
    <span>3 · Set a new password</span>
  </div>
  <?php if ($step === 'request'): ?>
    <h1>Forgot your password?</h1>
    <?php if ($done): ?>
      <div class="flash ok" role="status"><?= h($done) ?></div>
      <p><a class="btn primary" href="<?= h(url('forgot_password.php?step=code')) ?>">I have the code →</a></p>
    <?php else: ?>
      <p class="muted">Send a request to the admin. After they approve it, a 6-digit code goes to the email on your account.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="request">
        <label class="f"><span>Your username</span><input name="username" required autocomplete="username" autofocus></label>
        <label class="f"><span>Message for the admin <small class="muted">(optional)</small></span><input name="note" maxlength="300" placeholder="e.g. I forgot it after the holiday"></label>
        <button class="btn primary" type="submit">Send request</button>
      </form>
      <p class="small"><a href="<?= h(url('forgot_password.php?step=code')) ?>">Already have a code?</a></p>
    <?php endif; ?>
  <?php else: ?>
    <h1>Enter your code</h1>
    <p class="muted">Use the 6-digit code from the email. It works for <?= RESET_CODE_MINUTES ?> minutes and <?= RESET_MAX_CODE_TRIES ?> tries.</p>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="code">
      <label class="f"><span>Your username</span><input name="username" required autocomplete="username" value="<?= h(post('username')) ?>"></label>
      <label class="f"><span>6-digit code</span><input name="code" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="otp-input"></label>
      <button class="btn primary" type="submit">Verify code</button>
    </form>
  <?php endif; ?>
  <p class="small" style="margin-top:14px"><a href="<?= h(url('index.php')) ?>">← Back to sign in</a></p>
</section>
</div>
<?php page_end();
