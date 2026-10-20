<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_any();
$forced = (int)$u['must_change_password'] === 1;
$error = null;

if (is_post()) {
    csrf_check();
    $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $rep = $_POST['repeat'] ?? '';
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn();
    if (!is_string($cur) || !password_verify($cur, (string)$hash)) $error = 'Your current password is not correct.';
    elseif (!is_string($new) || strlen($new) < 8) $error = 'Choose a new password of at least 8 characters.';
    elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) $error = 'Use at least one letter and one number.';
    elseif ($new !== $rep) $error = 'The two new passwords do not match.';
    elseif (password_verify($new, (string)$hash)) $error = 'Choose a password different from the current one.';
    else {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        log_action('Changed password');
        flash('Password changed.');
        redirect(PORTALS[$u['role']]['home']);
    }
}

page_start('Change password', $u, PORTALS[$u['role']]['label']);
?>
<div class="narrow">
<section class="panel">
  <h1>Change password</h1>
  <?php if ($forced): ?><div class="flash warn">Welcome. Choose your own password before you continue.</div><?php endif; ?>
  <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="text" name="username" value="<?= h($u['username']) ?>" autocomplete="username" hidden>
    <label class="f"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
    <label class="f"><span>New password</span><input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
    <label class="f"><span>Repeat new password</span><input type="password" name="repeat" required minlength="8" autocomplete="new-password"></label>
    <p class="muted small">At least 8 characters, with a letter and a number.</p>
    <button class="btn primary" type="submit">Save new password</button>
  </form>
</section>
</div>
<?php page_end();
