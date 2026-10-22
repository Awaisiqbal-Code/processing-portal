<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
$isSuper = ($u['role'] === 'super_admin');

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'add') {
        $name = mb_substr(post('name'), 0, 150);
        $username = strtolower(post('username'));
        $role = post('role', 'processing_staff');
        $res = create_staff_user($u, $name, $username, $role, post('department', 'backend'));
        if ($res['ok']) {
            $_SESSION['new_cred'] = [$name, $username, $res['password'], $role];
            flash('Created ' . role_label($role) . ' account for ' . $name . '.');
        } else {
            flash($res['error'], 'bad');
        }
    } elseif ($action === 'assign_role') {
        $res = assign_user_role($u, (int)post('id'), post('role'), post('department'));
        if ($res['ok']) {
            flash('Role updated to ' . role_label(post('role')) . '.');
        } else {
            flash($res['error'], 'bad');
        }
    } elseif (in_array($action, ['reset', 'toggle'], true)) {
        $id = (int)post('id');
        $t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
        if (!$t) { http_response_code(404); exit('Not found.'); }
        if ($action === 'reset') {
            if (in_array($t['role'], ['super_admin', 'admin'], true) && !$isSuper) {
                flash('Only Super Admin can reset admin passwords.', 'bad');
            } else {
                $pw = random_password(12);
                q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
                log_action('Reset password for ' . $t['username']);
                $_SESSION['new_cred'] = [$t['name'], $t['username'], $pw, $t['role']];
            }
        } else {
            $res = toggle_staff_active($u, $id);
            if ($res['ok']) {
                flash($res['active'] ? 'Account enabled.' : 'Account disabled. They are signed out on their next click.');
            } else {
                flash($res['error'], 'bad');
            }
        }
    }
    redirect('admin/staff.php');
}

$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);
$people = q("SELECT u.*, (SELECT COUNT(*) FROM students s WHERE s.officer_id = u.id OR s.frontend_officer_id = u.id) AS n FROM users u WHERE role != 'student' ORDER BY CASE role WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 WHEN 'pms_lead' THEN 3 WHEN 'processing_staff' THEN 4 WHEN 'officer' THEN 5 ELSE 6 END, COALESCE(department, 'backend') DESC, name")->fetchAll();

page_start('Staff and admins', $u, PORTALS[$u['role']]['label']);
?>
<h1>Staff and admins</h1>

<?php if ($cred): ?>
<div class="cred" role="status">
  <b>Login for <?= h($cred[0]) ?> (<?= h(role_label((string)$cred[3])) ?>)</b>
  <div>Portal: <code><?= h(url('index.php')) ?></code></div>
  <div>Username: <code><?= h($cred[1]) ?></code> · Password: <code><?= h($cred[2]) ?></code></div>
  <p class="small">Copy this now. The password is shown only once. They will be asked to choose a new one when they sign in.</p>
</div>
<?php endif; ?>

<section class="panel">
  <div class="panel-h">
    <h2>People with staff access</h2>
    <p>PMS Leads &amp; Processing Staff see their assigned students. Counselors see their counselled students. <?php if ($isSuper): ?><a href="<?= h(url('admin/roles.php')) ?>">Open User &amp; Role Management →</a><?php endif; ?></p>
  </div>
  <div class="tbl"><table>
    <thead><tr><th>Name</th><th>Username</th><th>Role &amp; Team</th><th>Students</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): 
        $dept = $p['department'] ?? 'backend';
        $isOfficerLike = in_array($p['role'], ['pms_lead', 'processing_staff', 'officer'], true);
    ?>
      <tr>
        <td><b><?= h($p['name']) ?></b><?= (int)$p['id'] === (int)$u['id'] ? ' <span class="pill">You</span>' : '' ?></td>
        <td class="mono"><?= h($p['username']) ?></td>
        <td>
          <?php if (in_array($p['role'], ['super_admin', 'admin'], true)): ?>
            <span class="pill ok"><?= h(role_label($p['role'])) ?></span>
          <?php elseif ($p['role'] === 'counselor'): ?>
            <span class="pill acc">Counselor</span>
          <?php else: ?>
            <?= h(role_label($p['role'])) ?> <span class="pill <?= $dept === 'frontend' ? 'acc' : '' ?>" style="font-size:0.72rem; margin-left:0.25rem;"><?= $dept === 'frontend' ? 'Front End' : 'Back End' ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= $isOfficerLike ? (int)$p['n'] : '' ?></td>
        <td class="num"><?= $p['last_login'] ? h(fmt_date(substr($p['last_login'], 0, 10))) : 'Never' ?></td>
        <td><span class="pill <?= $p['active'] ? 'ok' : 'bad' ?>"><?= $p['active'] ? 'Active' : 'Disabled' ?></span></td>
        <td><div style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
          <?php if ($isOfficerLike): ?>
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

<?php if ($isSuper): ?>
<section class="panel">
  <div class="panel-h"><h2>Add a person</h2><p>A strong password is created and shown once. Role assignment is restricted to Super Admin.</p></div>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="f"><span>Full name</span><input name="name" required maxlength="150"></label>
    <label class="f"><span>Username</span><input name="username" required pattern="[a-z0-9._\-]{3,40}" placeholder="e.g. ayesha" autocomplete="off"></label>
    <label class="f"><span>Role</span>
      <select name="role">
        <option value="pms_lead">PMS Lead</option>
        <option value="processing_staff">Processing Staff</option>
        <option value="counselor">Counselor</option>
        <option value="admin">Admin (Max 1)</option>
      </select>
    </label>
    <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Add person</button></div>
  </form>
</section>
<?php endif; ?>
<?php page_end();
