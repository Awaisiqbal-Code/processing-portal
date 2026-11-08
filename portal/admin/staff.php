<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
$isSuper = ($u['role'] === 'super_admin');
$canStaff = user_can($u, 'staff.manage');
try { $u['email'] = (string)q('SELECT email FROM users WHERE id = ?', [(int)$u['id']])->fetchColumn(); } catch (Throwable) {}

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'add') {
        $res = create_staff_member($u, $_POST);
        if ($res['ok']) {
            [$n, $un, $pw, $rl] = $res['credentials'];
            $_SESSION['new_cred'] = ['name' => $n, 'username' => $un, 'password' => $pw, 'role' => $rl, 'phone' => $res['phone'], 'email' => $res['email'], 'id' => $res['user_id'], 'new' => true];
            flash('Added ' . $n . ' as ' . (STAFF_ROLE_OPTIONS[$rl] ?? role_label($rl)) . '.');
            redirect('admin/staff.php#cred');
        }
        $_SESSION['staff_add_old'] = array_intersect_key($_POST, array_flip(['name', 'username', 'email', 'phone', 'role', 'department', 'branch', 'job_title', 'perms']));
        flash($res['error'], 'bad');
        redirect('admin/staff.php?add=1#add-person');
    }
    if (in_array($action, ['reset_approve', 'reset_reject'], true)) {
        [$ok, $m] = $action === 'reset_approve' ? approve_password_reset($u, (int)post('req')) : reject_password_reset($u, (int)post('req'), (string)post('why'));
        flash($m, $ok ? 'ok' : 'bad');
        redirect('admin/staff.php#reset-requests');
    }
    if ($action === 'mail_settings' && $isSuper) {
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_from_name', 'smtp_secure'] as $k) {
            $v = trim((string)post($k));
            q('INSERT INTO settings (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at', [$k, $v, now()]);
        }
        if (trim((string)post('smtp_pass')) !== '') q('INSERT INTO settings (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at', ['smtp_pass', trim((string)post('smtp_pass')), now()]);
        flash('Email settings saved.');
        redirect('admin/staff.php#system');
    }
    if ($action === 'ai_settings' && $isSuper) {
        $key = trim((string)post('gemini_key'));
        if ($key !== '') set_setting('GEMINI_API_KEY', $key);
        if (post('gemini_key_remove') === '1') set_setting('GEMINI_API_KEY', '');
        $model = (string)post('gemini_model');
        set_setting('GEMINI_MODEL', array_key_exists($model, ASSISTANT_MODELS) ? $model : ASSISTANT_DEFAULT_MODEL);
        set_setting('ASSISTANT_ENABLED', post('assistant_enabled') === '1' ? '1' : '0');
        log_activity('settings', null, 'assistant_settings', null, ['model' => assistant_model(), 'enabled' => post('assistant_enabled') === '1', 'key_changed' => $key !== ''], (int)$u['id']);
        flash('AI assistant settings saved.');
        redirect('admin/staff.php#system');
    }
    if ($action === 'ai_test' && $isSuper) {
        [$ok, $m] = assistant_test();
        flash($ok ? $m : 'Gemini test failed: ' . $m, $ok ? 'ok' : 'bad');
        redirect('admin/staff.php#system');
    }
    if ($action === 'mail_test' && $isSuper) {
        [$ok, $err] = send_mail((string)($u['email'] ?? '') ?: (string)post('to'), 'Portal email test', "This is a test email from the ScholarshipHive portal.
If you can read this, password reset codes will arrive too.");
        flash($ok ? 'Test email sent.' : 'Test email failed: ' . $err, $ok ? 'ok' : 'bad');
        redirect('admin/staff.php#system');
    }
    if (in_array($action, ['reset', 'toggle'], true)) {
        $id = (int)post('id');
        $t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
        if (!$t) { http_response_code(404); exit('Not found.'); }
        if ($action === 'reset') {
            if (is_admin_role($t['role']) && !$isSuper) {
                flash('Only the Super Admin can reset admin passwords.', 'bad');
            } else {
                $pw = random_password(12);
                q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
                log_action('Reset password for ' . $t['username']);
                staff_log($id, 'password', null, 'Password reset', 0, null, (int)$u['id']);
                $_SESSION['new_cred'] = ['name' => $t['name'], 'username' => $t['username'], 'password' => $pw, 'role' => $t['role'], 'phone' => $t['phone'] ?? null, 'email' => $t['email'] ?? null, 'id' => $id, 'new' => false];
                redirect('admin/staff.php#cred');
            }
        } else {
            $res = toggle_staff_active($u, $id);
            if ($res['ok']) staff_log($id, 'status', null, $res['active'] ? 'Enabled' : 'Disabled', 0, null, (int)$u['id']);
            flash($res['ok'] ? $res['message'] : $res['error'], $res['ok'] ? 'ok' : 'bad');
        }
    }
    redirect('admin/staff.php');
}

$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);
$old = $_SESSION['staff_add_old'] ?? []; unset($_SESSION['staff_add_old']);

$people = q("SELECT u.*,
    (SELECT COUNT(*) FROM students s WHERE s.frontend_officer_id = u.id) AS n_fe,
    (SELECT COUNT(*) FROM students s WHERE s.officer_id = u.id) AS n_be,
    (SELECT COUNT(*) FROM students s WHERE s.assigned_counselor_id = u.id) AS n_cn
  FROM users u WHERE role != 'student'
  ORDER BY CASE role WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 WHEN 'pms_lead' THEN 3 WHEN 'processing_staff' THEN 4 WHEN 'officer' THEN 5 ELSE 6 END,
           COALESCE(department, 'backend') DESC, name")->fetchAll();

$cnt = ['active' => 0, 'frontend' => 0, 'backend' => 0, 'counselor' => 0, 'leads' => 0, 'disabled' => 0];
foreach ($people as $p) {
    if (!(int)$p['active']) { $cnt['disabled']++; continue; }
    $cnt['active']++;
    if ($p['role'] === 'counselor') $cnt['counselor']++;
    elseif (in_array($p['role'], ['pms_lead', 'admin', 'super_admin'], true)) $cnt['leads']++;
    if (in_array($p['role'], ['processing_staff', 'officer', 'pms_lead'], true)) $cnt[($p['department'] ?? 'backend') === 'frontend' ? 'frontend' : 'backend']++;
}
$adminTaken = (bool)array_filter($people, fn($p) => $p['role'] === 'admin');
$oldRole = $old['role'] ?? 'processing_staff';
$oldPerms = isset($old['perms']) && is_array($old['perms']) ? $old['perms'] : null;

page_start('Staff and admins', $u, PORTALS[$u['role']]['label']);
?>
<div class="panel-h" style="margin-bottom:6px">
  <h1 style="margin:0">Team</h1>
  <?php if ($isSuper): ?><a class="btn primary" href="#add-person" data-open-add>+ Add a person</a><?php endif; ?>
</div>
<p class="muted" style="margin-top:0">Everyone with staff access. <?= $isSuper ? 'Open <b>Manage</b> to change details, role or team (with student hand-over), access, and security.' : 'Only the Super Admin can add people or change roles.' ?></p>

<?php if ($cred):
  $msg = login_message($cred['name'], $cred['username'], $cred['password']);
  $wa = wa_link($cred['phone'] ?? null, $msg); ?>
<div class="cred" role="status" id="cred">
  <b><?= $cred['new'] ? 'Account created for ' : 'New password for ' ?><?= h($cred['name']) ?> (<?= h(STAFF_ROLE_OPTIONS[$cred['role']] ?? role_label((string)$cred['role'])) ?>)</b>
  <div>Portal: <code><?= h(absolute_url('index.php')) ?></code></div>
  <div>Username: <code><?= h($cred['username']) ?></code> · Temporary password: <code><?= h($cred['password']) ?></code></div>
  <div class="row-actions" style="margin-top:6px">
    <button type="button" class="btn sm" data-copy-text="<?= h($msg) ?>">📋 Copy login message</button>
    <?php if ($wa): ?><a class="btn sm primary" href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer">💬 Send on WhatsApp</a><?php endif; ?>
    <?php if (!empty($cred['email'])): ?><a class="btn sm" href="mailto:<?= h($cred['email']) ?>?subject=<?= rawurlencode('Your ScholarshipHive portal account') ?>&body=<?= rawurlencode($msg) ?>">✉ Email</a><?php endif; ?>
  </div>
  <p class="small">The password is shown only once. They choose their own password on first sign-in.</p>
</div>
<?php endif; ?>

<div class="kpis" style="margin:14px 0">
  <div class="kpi"><div class="l">Active people</div><div class="v"><?= $cnt['active'] ?></div><div class="s"><?= $cnt['disabled'] ?> disabled</div></div>
  <div class="kpi"><div class="l">Front End</div><div class="v"><?= $cnt['frontend'] ?></div><div class="s">processing officers</div></div>
  <div class="kpi"><div class="l">Back End</div><div class="v"><?= $cnt['backend'] ?></div><div class="s">processing officers</div></div>
  <div class="kpi"><div class="l">Counselors</div><div class="v"><?= $cnt['counselor'] ?></div><div class="s">see their own students</div></div>
  <div class="kpi"><div class="l">Leads &amp; admins</div><div class="v"><?= $cnt['leads'] ?></div><div class="s">view the whole team</div></div>
</div>

<section class="panel">
  <div class="team-filters" data-team-filter>
    <input type="search" placeholder="Search name, username, email or number…" data-tf-q aria-label="Search people">
    <select data-tf-role aria-label="Role"><option value="">All roles</option><option value="super_admin">Super Admin</option><option value="admin">Admin</option><option value="pms_lead">PMS Lead</option><option value="processing_staff">Processing Officer</option><option value="counselor">Counselor</option></select>
    <select data-tf-team aria-label="Team"><option value="">All teams</option><option value="frontend">Front End</option><option value="backend">Back End</option></select>
    <select data-tf-status aria-label="Status"><option value="">Any status</option><option value="1">Active</option><option value="0">Disabled</option></select>
  </div>
  <div class="tbl"><table class="team-tbl">
    <thead><tr><th>Person</th><th>Role &amp; team</th><th>Access</th><th class="num">Students</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p):
        $dept = $p['department'] ?: 'backend';
        $role = $p['role'] === 'officer' ? 'processing_staff' : $p['role'];
        $officerLike = is_officer_like_role($p['role']);
        $perms = user_perms($p);
        $search = strtolower($p['name'] . ' ' . $p['username'] . ' ' . ($p['email'] ?? '') . ' ' . preg_replace('/\D/', '', (string)($p['phone'] ?? '')));
        $waP = wa_link($p['phone'] ?? null);
    ?>
      <tr data-q="<?= h($search) ?>" data-role="<?= h($role) ?>" data-team="<?= $officerLike ? h($dept) : '' ?>" data-active="<?= (int)$p['active'] ?>" class="<?= (int)$p['active'] ? '' : 'is-off' ?>">
        <td>
          <div class="person">
            <span class="avatar sm"><?= h(user_initials($p['name'])) ?></span>
            <div>
              <b><?= h($p['name']) ?></b><?= (int)$p['id'] === (int)$u['id'] ? ' <span class="pill sm">You</span>' : '' ?>
              <div class="small muted"><span class="mono"><?= h($p['username']) ?></span><?= $p['job_title'] ? ' · ' . h($p['job_title']) : '' ?><?= $p['branch'] ? ' · ' . h($p['branch']) : '' ?></div>
              <div class="small">
                <?php if (!empty($p['email'])): ?><a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a><?php endif; ?>
                <?php if ($waP): ?><?= !empty($p['email']) ? ' · ' : '' ?><a href="<?= h($waP) ?>" target="_blank" rel="noopener noreferrer">💬 <?= h($p['phone']) ?></a><?php endif; ?>
                <?php if (empty($p['email']) && !$waP && $p['role'] !== 'super_admin'): ?><span class="muted">No email / WhatsApp yet</span><?php endif; ?>
              </div>
            </div>
          </div>
        </td>
        <td>
          <?php if (is_admin_role($p['role'])): ?><span class="pill ok"><?= h(role_label($p['role'])) ?></span>
          <?php elseif ($p['role'] === 'counselor'): ?><span class="pill acc">Counselor</span>
          <?php else: ?><?= h(STAFF_ROLE_OPTIONS[$role] ?? role_label($role)) ?> <span class="pill sm <?= $dept === 'frontend' ? 'acc' : '' ?>"><?= h(team_label($dept)) ?></span><?php endif; ?>
        </td>
        <td class="small">
          <?php if ($p['role'] === 'super_admin'): ?>Full access
          <?php elseif (in_array($p['role'], CONFIGURABLE_PERM_ROLES, true)):
              $extra = array_diff($perms, ['reports.view']); ?>
            Views everyone<?= $extra ? '<br><span class="muted">+ ' . h(implode(', ', array_map(fn($k) => PERMISSIONS[$k][0], $extra))) . '</span>' : '<br><span class="muted">no editing outside own students</span>' ?>
          <?php elseif ($p['role'] === 'counselor'): ?>Own students · read-only
          <?php else: ?>Own students<?php endif; ?>
        </td>
        <td class="num small">
          <?php if ($officerLike): ?><a href="<?= h(url('admin/officers.php?id=' . $p['id'])) ?>" title="Open caseload"><?= (int)$p['n_fe'] + (int)$p['n_be'] ?></a><?php if ((int)$p['n_fe'] && (int)$p['n_be']): ?><div class="muted">FE <?= (int)$p['n_fe'] ?> · BE <?= (int)$p['n_be'] ?></div><?php endif; ?>
          <?php elseif ($p['role'] === 'counselor'): ?><?= (int)$p['n_cn'] ?: '—' ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="small"><?= $p['last_login'] ? h(fmt_date(substr($p['last_login'], 0, 10))) : '<span class="muted">Never</span>' ?></td>
        <td><span class="pill <?= $p['active'] ? 'ok' : 'bad' ?>"><?= $p['active'] ? 'Active' : 'Disabled' ?></span></td>
        <td><div class="row-actions" style="justify-content:flex-end">
          <a class="btn sm <?= $isSuper ? 'primary' : '' ?>" href="<?= h(url('admin/staff_member.php?id=' . $p['id'])) ?>"><?= $isSuper ? 'Manage' : 'View' ?></a>
          <?php if ((int)$p['id'] !== (int)$u['id'] && ($isSuper || ($canStaff && !is_admin_role($p['role'])))): ?>
            <form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn sm" name="action" value="reset" title="Create a new temporary password">Reset password</button>
              <?php if ($p['role'] !== 'super_admin'): ?><button class="btn sm" name="action" value="toggle" <?= $p['active'] ? "onclick=\"return confirm('Disable " . h(addslashes($p['name'])) . "? They will be signed out.')\"" : '' ?>><?= $p['active'] ? 'Disable' : 'Enable' ?></button><?php endif; ?>
            </form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="empty" data-tf-empty hidden>No one matches these filters.</p>
</section>

<?php if ($isSuper || $canStaff): $resets = reset_requests_list(30); $openResets = array_filter($resets, fn($x) => $x['status'] === 'pending'); ?>
<section class="panel" id="reset-requests">
  <div class="panel-h"><h2>Password reset requests <?= $openResets ? '<span class="pill sm bad">' . count($openResets) . ' waiting</span>' : '' ?></h2>
    <p>People ask from “Forgot password?”. Approve → a 6-digit code is emailed to the address on their account → they set a new password. Nobody (including admins) ever sees the password.</p></div>
  <?php if (!$resets): ?><div class="empty">No requests yet.</div><?php else: ?>
  <div class="tbl"><table>
    <thead><tr><th>Person</th><th>Asked</th><th>Status</th><th>Decided by</th><th>Email</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($resets as $rq): $em = account_email($rq + ['email' => q('SELECT email FROM users WHERE id = ?', [(int)$rq['user_id']])->fetchColumn(), 'student_id' => q('SELECT student_id FROM users WHERE id = ?', [(int)$rq['user_id']])->fetchColumn()]); ?>
      <tr>
        <td><b><?= h($rq['name']) ?></b> <span class="small muted"><?= h($rq['username']) ?> · <?= h(role_label($rq['role'])) ?></span><?= $rq['request_note'] ? '<div class="small">“' . h($rq['request_note']) . '”</div>' : '' ?></td>
        <td class="small"><?= h(fmt_date(substr((string)$rq['requested_at'], 0, 10))) ?> <?= h(substr((string)$rq['requested_at'], 11, 5)) ?><div class="muted"><?= h((string)$rq['request_ip']) ?></div></td>
        <td><?php $tone = ['pending' => 'warn', 'approved' => 'acc', 'code_verified' => 'acc', 'completed' => 'ok', 'rejected' => 'bad', 'expired' => '']; ?>
          <span class="pill sm <?= $tone[$rq['status']] ?? '' ?>"><?= h(['pending' => 'Waiting for approval', 'approved' => 'Code emailed', 'code_verified' => 'Code verified', 'completed' => 'Password changed', 'rejected' => 'Rejected', 'expired' => 'Expired'][$rq['status']] ?? $rq['status']) ?></span>
          <?= $rq['completed_at'] ? '<div class="small muted">' . h(fmt_date(substr((string)$rq['completed_at'], 0, 10))) . ' ' . h(substr((string)$rq['completed_at'], 11, 5)) . '</div>' : '' ?>
          <?= str_starts_with((string)$rq['email_status'], 'failed') ? '<div class="small bad-t">' . h($rq['email_status']) . '</div>' : '' ?></td>
        <td class="small"><?= h($rq['decided_by_name'] ?: '—') ?></td>
        <td class="small"><?= $em ? h(mask_email($em)) : '<span class="bad-t">No email on account</span>' ?></td>
        <td><?php if ($rq['status'] === 'pending' && can_approve_reset($u, $rq + ['id' => $rq['user_id']])): ?>
          <form method="post" class="row-actions" style="justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="req" value="<?= (int)$rq['id'] ?>">
            <button class="btn sm primary" name="action" value="reset_approve" <?= !$em || !mail_ready() ? 'disabled title="Needs a valid email on the account and email set up (System → Email)"' : '' ?>>Approve &amp; email code</button>
            <input name="why" placeholder="Reason (if rejecting)" aria-label="Reason" style="max-width:150px">
            <button class="btn sm" name="action" value="reset_reject">Reject</button>
          </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!mail_ready()): ?><p class="flash warn" style="margin-top:10px">Email is not set up yet, so codes cannot be sent. <?= $isSuper ? 'Set it up in <a href="#system">System → Email</a>.' : 'Ask the Super Admin to set it up.' ?> Until then use “Reset password” (temporary password) on the person’s row.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($isSuper): ?>
<section class="panel add-person" id="add-person">
  <div class="panel-h"><h2>Add a person</h2><p>A temporary password is created and shown once, ready to send on WhatsApp or email.</p></div>
  <form method="post" data-add-person data-admin-taken="<?= $adminTaken ? '1' : '0' ?>" data-username-suggest>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">

    <div class="step-h"><span>1</span> Who is it?</div>
    <div class="fields">
      <label class="f"><span>Full name *</span><input name="name" required maxlength="150" value="<?= h($old['name'] ?? '') ?>" placeholder="e.g. Ayesha Khan" data-name-src></label>
      <label class="f"><span>Email *</span><input type="email" name="email" required maxlength="150" value="<?= h($old['email'] ?? '') ?>" placeholder="name@scholarshiphive.com"></label>
      <label class="f"><span>WhatsApp number *</span><input type="tel" name="phone" required maxlength="20" value="<?= h($old['phone'] ?? '') ?>" placeholder="0300 1234567" inputmode="tel"></label>
      <label class="f"><span>Username <small class="muted">(for sign-in)</small></span><input name="username" pattern="[a-z0-9._\-]{3,40}" maxlength="40" value="<?= h($old['username'] ?? '') ?>" placeholder="made from the name" autocomplete="off" data-username></label>
      <label class="f"><span>Branch</span><select name="branch"><option value="">—</option><?php foreach (BRANCHES as $b): ?><option <?= ($old['branch'] ?? '') === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?></select></label>
      <label class="f"><span>Job title</span><input name="job_title" maxlength="80" value="<?= h($old['job_title'] ?? '') ?>" placeholder="e.g. Senior Processing Officer"></label>
    </div>

    <div class="step-h"><span>2</span> What will they do?</div>
    <div class="role-cards" role="radiogroup" aria-label="Role">
      <?php foreach ([
          'processing_staff' => ['Processing Officer', 'Works on the students assigned to them (front end or back end).'],
          'pms_lead'         => ['PMS Lead', 'Sees every officer’s work and students. Edits only their own students unless you allow more.'],
          'counselor'        => ['Counselor', 'Sees their own counselled students, read-only. Can add remarks.'],
          'admin'            => ['Admin', 'Oversees the whole portal. View only, unless you switch on access below.' . ($adminTaken ? ' (Only one Admin is allowed — one already exists.)' : '')],
        ] as $rk => [$rl, $rd]): ?>
        <label class="role-card"><input type="radio" name="role" value="<?= $rk ?>" <?= $oldRole === $rk ? 'checked' : '' ?> <?= $rk === 'admin' && $adminTaken ? 'disabled' : '' ?>><span><b><?= h($rl) ?></b><small><?= h($rd) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <div class="fields" data-show-for="processing_staff pms_lead" style="margin-top:12px">
      <div class="f"><span>Team</span>
        <div class="seg" role="radiogroup" aria-label="Team">
          <label><input type="radio" name="department" value="frontend" <?= ($old['department'] ?? '') === 'frontend' ? 'checked' : '' ?>><span>Front End</span></label>
          <label><input type="radio" name="department" value="backend" <?= ($old['department'] ?? 'backend') !== 'frontend' ? 'checked' : '' ?>><span>Back End</span></label>
        </div>
      </div>
    </div>

    <div data-show-for="admin pms_lead">
      <div class="step-h"><span>3</span> Access <small class="muted">— they can always view everything; tick what they may also change</small></div>
      <div class="perm-grid">
        <?php foreach (PERMISSIONS as $pk => [$pl, $pd]): ?>
          <label class="perm"><input type="checkbox" name="perms[]" value="<?= h($pk) ?>" data-def-admin="<?= in_array($pk, ROLE_DEFAULT_PERMISSIONS['admin'], true) ? 1 : 0 ?>" data-def-pms_lead="<?= in_array($pk, ROLE_DEFAULT_PERMISSIONS['pms_lead'], true) ? 1 : 0 ?>" <?= $oldPerms !== null ? (in_array($pk, $oldPerms, true) ? 'checked' : '') : (in_array($pk, ROLE_DEFAULT_PERMISSIONS[in_array($oldRole, CONFIGURABLE_PERM_ROLES, true) ? $oldRole : 'pms_lead'], true) ? 'checked' : '') ?>><span><b><?= h($pl) ?></b><small><?= h($pd) ?></small></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="actions" style="margin-top:16px"><button class="btn primary" type="submit">Add person</button></div>
  </form>
</section>
<?php endif; ?>
<?php if ($isSuper): $vol = railway_volume_status(); $onVolume = (bool)$vol['data_dir_writable']; ?>
<section class="panel" id="system">
  <div class="panel-h"><h2>System</h2><p>Where the data is kept and how sign-in is protected.</p></div>
  <div class="sys-rows small">
    <div>Database: <code><?= h($vol['active_db_path']) ?></code> · Backups: <code><?= h($vol['backup_dir']) ?></code></div>
    <div>Hosting: <b><?= $vol['is_railway'] ? 'Railway' : (getenv('VERCEL') ? 'Vercel' : 'Local / standard') ?></b> · Persistent volume: <span class="pill sm <?= $onVolume ? 'ok' : 'warn' ?>"><?= $onVolume ? 'Mounted & writable' : 'Not mounted' ?></span></div>
    <div>One device per account: <b><?= single_session_applies('admin') ? 'On for all staff' : 'Off' ?></b> · Idle warning <?= (int)setting('idle_warning_minutes', 15) ?> min · Auto sign-out <?= (int)setting('idle_logout_minutes', 20) ?> min</div>
    <?php $ms = mail_settings(); ?>
    <details class="mail-box" <?= mail_ready() ? '' : 'open' ?>><summary><b>Email (for password reset codes)</b> — <?= mail_ready() ? '<span class="pill sm ok">Ready</span>' : '<span class="pill sm warn">Not set up</span>' ?></summary>
      <p class="small muted">Use the SMTP of your email provider. For Gmail: host <code>smtp.gmail.com</code>, port <code>465</code>, security SSL, your Gmail address, and an <b>App password</b> (Google Account → Security → App passwords). Values set as environment variables (SMTP_HOST, SMTP_USER, SMTP_PASS …) take priority.</p>
      <form method="post" class="fields"><?= csrf_field() ?><input type="hidden" name="action" value="mail_settings">
        <label class="f"><span>SMTP host</span><input name="smtp_host" value="<?= h($ms['host']) ?>" placeholder="smtp.gmail.com"></label>
        <label class="f"><span>Port</span><input name="smtp_port" value="<?= h((string)$ms['port']) ?>" inputmode="numeric"></label>
        <label class="f"><span>Security</span><select name="smtp_secure"><option value="ssl" <?= $ms['secure'] === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option><option value="tls" <?= $ms['secure'] === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS (port 587)</option></select></label>
        <label class="f"><span>Username (email)</span><input name="smtp_user" value="<?= h($ms['user']) ?>" autocomplete="off"></label>
        <label class="f"><span>Password / app password</span><input type="password" name="smtp_pass" placeholder="<?= $ms['pass'] !== '' ? '•••••••• (saved — leave empty to keep)' : '' ?>" autocomplete="new-password"></label>
        <label class="f"><span>From address</span><input name="smtp_from" value="<?= h($ms['from']) ?>" placeholder="same as username"></label>
        <label class="f"><span>From name</span><input name="smtp_from_name" value="<?= h($ms['from_name']) ?>"></label>
        <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Save email settings</button></div>
      </form>
      <form method="post" class="row-actions" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="mail_test">
        <?php if (empty($u['email'])): ?><input type="email" name="to" placeholder="Send test to…" required aria-label="Test address"><?php endif; ?>
        <button class="btn sm" type="submit" <?= mail_ready() ? '' : 'disabled' ?>>Send a test email<?= !empty($u['email']) ? ' to ' . h($u['email']) : '' ?></button></form>
    </details>
    <?php $aiKey = assistant_key(); $aiFromEnv = getenv('GEMINI_API_KEY') !== false && getenv('GEMINI_API_KEY') !== ''; ?>
    <details class="mail-box" <?= $aiKey === '' ? 'open' : '' ?>><summary><b>AI assistant (Gemini)</b> — <?= assistant_enabled() ? '<span class="pill sm ok">On for everyone</span>' : ($aiKey === '' ? '<span class="pill sm warn">No API key</span>' : '<span class="pill sm">Turned off</span>') ?></summary>
      <p class="small muted">A chat + voice assistant on every page. It answers from portal data and follows each person's access: officers only get their own students, team-wide answers only for Admin / PMS Lead (with “reports” access), students only their own file. It never changes data. Get a key at <b>aistudio.google.com → Get API key</b>. Note: on Google's free tier, Google may use the questions and the student data sent with them to improve its products — enable billing on the key for full privacy.</p>
      <form method="post" class="fields"><?= csrf_field() ?><input type="hidden" name="action" value="ai_settings">
        <label class="f"><span>Gemini API key</span><input type="password" name="gemini_key" autocomplete="new-password" placeholder="<?= $aiKey !== '' ? '•••• ' . h(substr($aiKey, -4)) . ' (saved — leave empty to keep)' : 'Paste your key' ?>" <?= $aiFromEnv ? 'disabled title="Set by the GEMINI_API_KEY environment variable"' : '' ?>></label>
        <label class="f"><span>Model</span><select name="gemini_model"><?php foreach (ASSISTANT_MODELS as $mk => $ml): ?><option value="<?= h($mk) ?>" <?= assistant_model() === $mk ? 'selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select></label>
        <label class="f chk"><span>Assistant</span><span><input type="checkbox" name="assistant_enabled" value="1" <?= (string)setting('ASSISTANT_ENABLED', '1') !== '0' ? 'checked' : '' ?>> Show it to everyone</span></label>
        <?php if ($aiKey !== '' && !$aiFromEnv): ?><label class="f chk"><span>&nbsp;</span><span><input type="checkbox" name="gemini_key_remove" value="1"> Remove the saved key</span></label><?php endif; ?>
        <div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Save assistant settings</button></div>
      </form>
      <form method="post" class="row-actions" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="ai_test"><button class="btn sm" type="submit" <?= $aiKey === '' ? 'disabled' : '' ?>>Test the connection</button></form>
    </details>
    <?php if (!$onVolume): ?><div class="flash warn" style="margin:6px 0 0">This server keeps the database on temporary disk<?= getenv('VERCEL') ? ' (Vercel runs several copies, each with its own database)' : '' ?>. Changes can be lost on restart, and “one device per account” cannot be guaranteed across copies. Run the portal on Railway with the <code>/data</code> volume for permanent data.</div><?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php page_end();
