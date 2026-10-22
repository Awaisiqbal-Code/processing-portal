<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';

// Strict super_admin gate: even 'admin' gets 403 here
$curr = current_user();
if (!$curr) {
    redirect('admin/login.php?next=' . rawurlencode('admin/roles.php'));
}
if (($curr['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Only Super Admin can assign roles']);
        exit;
    }
    page_start('Access Denied', $curr, PORTALS[$curr['role']]['label'] ?? 'Portal');
    echo '<div class="flash bad" role="alert">Only Super Admin can assign roles</div>';
    echo '<p><a class="btn" href="' . h(url(PORTALS[$curr['role']]['home'] ?? 'index.php')) . '">← Return to Dashboard</a></p>';
    page_end();
    exit;
}

$u = require_role('super_admin');
$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if (is_post()) {
    csrf_check();
    $action = post('action');
    $result = ['ok' => false, 'error' => 'Invalid action.'];

    if ($action === 'assign_role') {
        $targetId = (int)post('user_id');
        $newRole = post('role');
        $department = post('department');
        $result = assign_user_role($u, $targetId, $newRole, $department !== '' ? $department : null);
        if ($result['ok']) {
            flash('Updated role for ' . ($result['user']['name'] ?? 'user') . ' to ' . role_label($newRole) . '.');
        } else {
            flash($result['error'], 'bad');
        }
    } elseif ($action === 'create_user') {
        $name = mb_substr(post('name'), 0, 150);
        $username = strtolower(post('username'));
        $role = post('role');
        $department = post('department', 'backend');
        $result = create_staff_user($u, $name, $username, $role, $department);
        if ($result['ok']) {
            $_SESSION['new_cred'] = [$name, $username, $result['password'], $role];
            flash('Created ' . role_label($role) . ' account for ' . $name . '.');
        } else {
            flash($result['error'], 'bad');
        }
    } elseif ($action === 'toggle_active') {
        $targetId = (int)post('user_id');
        $result = toggle_staff_active($u, $targetId);
        if ($result['ok']) {
            flash($result['active'] ? 'Account enabled.' : 'Account disabled. They will be signed out on their next click.');
        } else {
            flash($result['error'], 'bad');
        }
    } elseif ($action === 'reset_password') {
        $targetId = (int)post('user_id');
        $t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$targetId])->fetch();
        if (!$t) {
            $result = ['ok' => false, 'error' => 'User not found.'];
            flash($result['error'], 'bad');
        } else {
            $pw = random_password(12);
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $targetId]);
            log_action('Reset password for ' . $t['username']);
            log_activity('user', $targetId, 'password_reset', null, ['username' => $t['username']], (int)$u['id']);
            $_SESSION['new_cred'] = [$t['name'], $t['username'], $pw, $t['role']];
            $result = ['ok' => true, 'password' => $pw];
            flash('Temporary password generated for ' . $t['name'] . '.');
        }
    }

    if ($wantsJson) {
        if (!$result['ok']) {
            http_response_code(422);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    redirect('admin/roles.php');
}

$cred = $_SESSION['new_cred'] ?? null;
unset($_SESSION['new_cred']);

$staffUsers = q("
    SELECT u.*,
           (SELECT COUNT(*) FROM students s WHERE s.officer_id = u.id OR s.frontend_officer_id = u.id) AS student_count
    FROM users u
    WHERE u.role != 'student'
    ORDER BY
      CASE u.role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'pms_lead' THEN 3
        WHEN 'processing_staff' THEN 4
        WHEN 'officer' THEN 5
        WHEN 'counselor' THEN 6
        ELSE 7
      END,
      u.name ASC
")->fetchAll();

$superAdminCount = (int)q("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
$adminCount      = (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$pmsLeadCount    = (int)q("SELECT COUNT(*) FROM users WHERE role = 'pms_lead'")->fetchColumn();
$staffCount      = (int)q("SELECT COUNT(*) FROM users WHERE role IN ('processing_staff','officer')")->fetchColumn();
$counselorCount  = (int)q("SELECT COUNT(*) FROM users WHERE role = 'counselor'")->fetchColumn();
$volStatus       = railway_volume_status();

$assignableRoles = [
    'super_admin'      => 'Super Admin (Max 1)',
    'admin'            => 'Admin (Max 1)',
    'pms_lead'         => 'PMS Lead',
    'processing_staff' => 'Processing Staff',
    'counselor'        => 'Counselor',
];

page_start('User & Role Management', $u, PORTALS[$u['role']]['label']);
?>
<h1>User &amp; Role Management <span class="pill ok" style="font-size:0.8rem; vertical-align:middle;">Super Admin Only</span></h1>

<?php if ($cred): ?>
<div class="cred" role="status">
  <b>Credentials for <?= h($cred[0]) ?> (<?= h(role_label((string)$cred[3])) ?>)</b>
  <div>Sign-In URL: <code><?= h(url('index.php')) ?></code></div>
  <div>Username: <code><?= h($cred[1]) ?></code> · Temporary Password: <code><?= h($cred[2]) ?></code></div>
  <p class="small">Copy this now. It is shown only once and the user will be prompted to set a new password upon first login.</p>
</div>
<?php endif; ?>

<section class="kpis" style="margin-bottom:1.25rem;">
  <div class="kpi">
    <small>Super Admin Slot</small>
    <b><?= $superAdminCount ?> / 1</b>
    <span class="small muted">Hard limit: max 1</span>
  </div>
  <div class="kpi">
    <small>Admin Slot</small>
    <b><?= $adminCount ?> / 1</b>
    <span class="small muted">Hard limit: max 1</span>
  </div>
  <div class="kpi">
    <small>PMS Leads</small>
    <b><?= $pmsLeadCount ?></b>
    <span class="small muted">Processing team leads</span>
  </div>
  <div class="kpi">
    <small>Processing Staff</small>
    <b><?= $staffCount ?></b>
    <span class="small muted">Officers &amp; staff</span>
  </div>
  <div class="kpi">
    <small>Counselors</small>
    <b><?= $counselorCount ?></b>
    <span class="small muted">Canonical counselor logins</span>
  </div>
</section>

<section class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-h">
    <h2>Database &amp; Volume Health</h2>
    <p>Active SQLite path: <code><?= h($volStatus['active_db_path']) ?></code> · Backups: <code><?= h($volStatus['backup_dir']) ?></code></p>
  </div>
  <div style="padding:0.85rem 1.1rem; font-size:0.88rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
    <span>Environment: <b><?= $volStatus['is_railway'] ? 'Railway' : 'Local / Standard' ?></b></span>
    <span>Persistent Volume (<code>/data</code>): <span class="pill <?= $volStatus['data_dir_writable'] ? 'ok' : 'warn' ?>"><?= $volStatus['data_dir_writable'] ? 'Mounted & Writable' : 'Not Mounted (Container Disk)' ?></span></span>
    <span>Idle Warning: <b><?= (int)setting('idle_warning_minutes', 15) ?> min</b> · Auto-Logout: <b><?= (int)setting('idle_logout_minutes', 20) ?> min</b></span>
  </div>
</section>

<section class="panel">
  <div class="panel-h">
    <h2>Create New Staff / Admin Account</h2>
    <p>Only Super Admin can create users or assign roles. Creating a second Admin or Super Admin is blocked at both the service and database trigger levels.</p>
  </div>
  <form method="post" class="fields">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_user">
    <label class="f"><span>Full name</span><input name="name" required maxlength="150" placeholder="e.g. Ayesha Khan"></label>
    <label class="f"><span>Username</span><input name="username" required pattern="[a-z0-9._\-]{3,40}" placeholder="e.g. ayesha.khan" autocomplete="off"></label>
    <label class="f"><span>Role</span>
      <select name="role">
        <option value="pms_lead">PMS Lead</option>
        <option value="processing_staff">Processing Staff</option>
        <option value="counselor">Counselor</option>
        <option value="admin">Admin (Max 1)</option>
      </select>
    </label>
    <label class="f"><span>Team / Department</span>
      <select name="department">
        <option value="backend">Back End</option>
        <option value="frontend">Front End</option>
        <option value="counseling">Counseling</option>
      </select>
    </label>
    <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Create User</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-h">
    <h2>All Staff, Counselors &amp; Administrators (<?= count($staffUsers) ?>)</h2>
    <p>Change any user's role or team, reset their password, or activate/deactivate their login.</p>
  </div>
  <div class="tbl">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Username</th>
          <th>Current Role</th>
          <th>Assign Role &amp; Team</th>
          <th>Students</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($staffUsers as $p):
          $dept = $p['department'] ?? 'backend';
      ?>
        <tr>
          <td class="mono">#<?= (int)$p['id'] ?></td>
          <td>
            <b><?= h($p['name']) ?></b>
            <?= (int)$p['id'] === (int)$u['id'] ? ' <span class="pill ok">You</span>' : '' ?>
          </td>
          <td class="mono"><?= h($p['username']) ?></td>
          <td>
            <span class="pill <?= in_array($p['role'], ['super_admin', 'admin'], true) ? 'ok' : 'acc' ?>"><?= h(role_label($p['role'])) ?></span>
          </td>
          <td>
            <?php if ($p['role'] === 'super_admin'): ?>
              <span class="small muted">Fixed (Primary Super Admin)</span>
            <?php else: ?>
              <form method="post" style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_role">
                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                <select name="role" style="padding:0.28rem 0.45rem; font-size:0.82rem;">
                  <?php foreach ($assignableRoles as $rKey => $rLabel): 
                      if ($rKey === 'super_admin') continue; // only 1 super_admin allowed
                  ?>
                    <option value="<?= h($rKey) ?>" <?= $p['role'] === $rKey ? 'selected' : '' ?>><?= h($rLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="department" style="padding:0.28rem 0.45rem; font-size:0.82rem;">
                  <option value="backend" <?= $dept === 'backend' ? 'selected' : '' ?>>Back End</option>
                  <option value="frontend" <?= $dept === 'frontend' ? 'selected' : '' ?>>Front End</option>
                  <option value="counseling" <?= $dept === 'counseling' ? 'selected' : '' ?>>Counseling</option>
                </select>
                <button class="btn sm primary" type="submit">Save Role</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int)$p['student_count'] ?></td>
          <td>
            <span class="pill <?= $p['active'] ? 'ok' : 'bad' ?>"><?= $p['active'] ? 'Active' : 'Disabled' ?></span>
          </td>
          <td>
            <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
              <form method="post" class="row-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                <button class="btn sm" name="action" value="reset_password" type="submit">Reset PW</button>
                <?php if ((int)$p['id'] !== (int)$u['id']): ?>
                  <button class="btn sm" name="action" value="toggle_active" type="submit"><?= $p['active'] ? 'Disable' : 'Enable' ?></button>
                <?php endif; ?>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_end();
