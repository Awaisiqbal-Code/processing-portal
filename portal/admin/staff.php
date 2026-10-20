<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'add') {
        $name = mb_substr(post('name'), 0, 150);
        $username = strtolower(post('username'));
        $role = post('role');
        if ($name === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $username) || !in_array($role, ['admin', 'officer'], true)) {
            flash('Enter a name, a username of 3 to 40 letters, numbers, dots or dashes, and a role.', 'bad');
        } elseif (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetchColumn()) {
            flash('That username is already taken.', 'bad');
        } else {
            $pw = random_password(12);
            q('INSERT INTO users (name, username, password_hash, role, active, must_change_password, created_at) VALUES (?,?,?,?,1,1,?)',
              [$name, $username, password_hash($pw, PASSWORD_DEFAULT), $role, now()]);
            log_action("Added $role $username");
            $_SESSION['new_cred'] = [$name, $username, $pw, $role];
        }
    } elseif (in_array($action, ['reset', 'toggle'], true)) {
        $id = (int)post('id');
        $t = q("SELECT * FROM users WHERE id = ? AND role IN ('admin','officer')", [$id])->fetch();
        if (!$t) { http_response_code(404); exit('Not found.'); }
        if ($action === 'reset') {
            $pw = random_password(12);
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            log_action('Reset password for ' . $t['username']);
            $_SESSION['new_cred'] = [$t['name'], $t['username'], $pw, $t['role']];
        } else {
            if ((int)$t['id'] === (int)$u['id']) flash('You cannot disable your own account.', 'bad');
            elseif ($t['role'] === 'admin' && $t['active'] && (int)q("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1) flash('At least one admin must stay active.', 'bad');
            else {
                q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
                log_action(($t['active'] ? 'Disabled ' : 'Enabled ') . $t['username']);
                flash($t['active'] ? 'Account disabled. They are signed out on their next click.' : 'Account enabled.');
            }
        }
    }
    redirect('admin/staff.php');
}

$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);
$people = q("SELECT u.*, (SELECT COUNT(*) FROM students s WHERE s.officer_id = u.id OR s.frontend_officer_id = u.id) AS n FROM users u WHERE role IN ('admin','officer') ORDER BY role, COALESCE(department, 'backend') DESC, name")->fetchAll();

page_start('Staff and admins', $u, PORTALS['admin']['label']);
?>
<h1>Staff and admins</h1>

<?php if ($cred): ?>
<div class="cred" role="status">
  <b>Login for <?= h($cred[0]) ?> (<?= $cred[3] === 'officer' ? 'processing officer' : 'admin' ?>)</b>
  <div>Portal: <code><?= h(url($cred[3] . '/login.php')) ?></code></div>
  <div>Username: <code><?= h($cred[1]) ?></code> · Password: <code><?= h($cred[2]) ?></code></div>
  <p class="small">Copy this now. The password is shown only once. They will be asked to choose a new one when they sign in.</p>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h"><h2>People with staff access</h2><p>Officers see only their own students (Front End or Back End). Admins see everything.</p></div>
  <div class="tbl"><table>
    <thead><tr><th>Name</th><th>Username</th><th>Role & Team</th><th>Students</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): 
        $dept = $p['department'] ?? 'backend';
    ?>
      <tr>
        <td><b><?= h($p['name']) ?></b><?= (int)$p['id'] === (int)$u['id'] ? ' <span class="pill">You</span>' : '' ?></td>
        <td class="mono"><?= h($p['username']) ?></td>
        <td>
          <?php if ($p['role'] === 'admin'): ?>
            <span class="pill">Admin</span>
          <?php else: ?>
            Processing officer <span class="pill <?= $dept === 'frontend' ? 'acc' : '' ?>" style="font-size:0.72rem; margin-left:0.25rem;"><?= $dept === 'frontend' ? 'Front End' : 'Back End' ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= $p['role'] === 'officer' ? (int)$p['n'] : '' ?></td>
        <td class="num"><?= $p['last_login'] ? h(fmt_date(substr($p['last_login'], 0, 10))) : 'Never' ?></td>
        <td><span class="pill <?= $p['active'] ? 'ok' : 'bad' ?>"><?= $p['active'] ? 'Active' : 'Disabled' ?></span></td>
        <td><div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
          <?php if ($p['role'] === 'officer'): ?>
            <a class="btn sm ghost" href="<?= h(url('admin/officers.php?id=' . $p['id'])) ?>" title="View <?= h($p['name']) ?>'s Caseload and Workspace">View Data →</a>
          <?php endif; ?>
          <form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn sm" name="action" value="reset">Reset password</button>
            <?php if ((int)$p['id'] !== (int)$u['id']): ?><button class="btn sm" name="action" value="toggle"><?= $p['active'] ? 'Disable' : 'Enable' ?></button><?php endif; ?>
          </form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="panel-h"><h2>Add a person</h2><p>A strong password is created and shown once.</p></div>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="f"><span>Full name</span><input name="name" required maxlength="150"></label>
    <label class="f"><span>Username</span><input name="username" required pattern="[a-z0-9._\-]{3,40}" placeholder="e.g. ayesha" autocomplete="off"></label>
    <label class="f"><span>Role</span><select name="role"><option value="officer">Processing officer</option><option value="admin">Admin</option></select></label>
    <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Add person</button></div>
  </form>
</section>
<?php page_end();
