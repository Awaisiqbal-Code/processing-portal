<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_any();
$forced = (int)$u['must_change_password'] === 1 && empty($_SESSION['pw_set'][(int)$u['id']]);
$error = null;

if (is_post()) {
    csrf_check();
    $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $rep = $_POST['repeat'] ?? '';
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn();
    if (!is_string($cur) || !password_verify($cur, (string)$hash)) $error = 'Your current password is not correct.';
    elseif (!is_string($new) || ($pp = password_problems($new, $u))) $error = 'Your new password needs ' . implode(', ', $pp ?? ['at least ' . PASSWORD_MIN . ' characters']) . '.';
    elseif ($new !== $rep) $error = 'The two new passwords do not match.';
    elseif (password_recently_used((int)$u['id'], $new)) $error = 'Choose a password you have not used recently.';
    else {
        // keep this browser signed in, close every other one
        $slot = array_key_first(array_filter($_SESSION['auth'] ?? [], fn($a) => (int)($a['uid'] ?? 0) === (int)$u['id']));
        $tok = $slot !== null ? (string)($_SESSION['auth'][$slot]['sid'] ?? '') : '';
        set_user_password((int)$u['id'], $new, false, $tok !== '' ? $tok : null);
        session_regenerate_id(true);
        $_SESSION['pw_set'][(int)$u['id']] = 1;   // remembered for this sign-in on every server copy
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
    <label class="f"><span>New password</span><input type="password" name="new" required minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" data-pw-new></label>
    <?php include APP . '/pages/_pw_rules.php'; ?>
    <label class="f"><span>Repeat new password</span><input type="password" name="repeat" required minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" data-pw-repeat></label>
    <p class="muted small">Saving signs this account out on every other device.</p>
    <button class="btn primary" type="submit">Save new password</button>
  </form>
</section>
</div>
<?php page_end();
