<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
$isSuper = ($u['role'] === 'super_admin');
$id = (int)(get('id') ?: post('id'));
$t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
if (!$t) { http_response_code(404); exit('Person not found.'); }
$self = (int)$t['id'] === (int)$u['id'];
$canSecurity = !$self && $t['role'] !== 'super_admin' && ($isSuper || (user_can($u, 'staff.manage') && !is_admin_role($t['role'])));
$back = 'admin/staff_member.php?id=' . $id;

if (is_post()) {
    csrf_check();
    $a = post('action');
    $res = ['ok' => false, 'error' => 'Unknown action.'];
    if ($a === 'details') {
        $res = update_staff_details($u, $id, $_POST);
    } elseif ($a === 'role') {
        $res = change_staff_role($u, $id, (string)post('role'), post('department') ?: null, [
            'students' => post('students'), 'to_frontend' => post('to_frontend'), 'to_backend' => post('to_backend'),
            'to_counselor' => post('to_counselor'), 'to_lead' => post('to_lead'), 'note' => post('note'),
            'perms' => $_POST['perms'] ?? [],
        ]);
    } elseif ($a === 'access') {
        $res = set_staff_permissions($u, $id, $_POST['perms'] ?? []);
    } elseif ($a === 'reset' && $canSecurity) {
        $pw = random_password(12);
        q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
        log_action('Reset password for ' . $t['username']);
        staff_log($id, 'password', null, 'Password reset', 0, null, (int)$u['id']);
        $_SESSION['new_cred'] = ['name' => $t['name'], 'username' => $t['username'], 'password' => $pw, 'role' => $t['role'], 'phone' => $t['phone'] ?? null, 'email' => $t['email'] ?? null];
        redirect($back . '#security');
    } elseif ($a === 'toggle' && $canSecurity) {
        $res = toggle_staff_active($u, $id);
        if ($res['ok']) staff_log($id, 'status', null, $res['active'] ? 'Enabled' : 'Disabled', 0, null, (int)$u['id']);
    } elseif ($a === 'signout' && $canSecurity) {
        $res = force_sign_out($u, $id);
    } elseif (in_array($a, ['reset', 'toggle', 'signout'], true)) {
        $res = ['ok' => false, 'error' => 'You cannot do that for this person.'];
    }
    flash($res['ok'] ? ($res['message'] ?? 'Saved.') : ($res['error'] ?? 'Could not save.'), $res['ok'] ? 'ok' : 'bad');
    redirect($back . match ($a) { 'role' => '#role', 'access' => '#access', 'details' => '#details', default => '#security' });
}

$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);
$t = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
$role = $t['role'] === 'officer' ? 'processing_staff' : $t['role'];
$dept = $t['department'] ?: 'backend';
$load = staff_caseload($id);
$perms = user_perms($t);
$history = staff_history($id);
$opts = staff_options();
$leads = array_values(array_filter($opts['pms_lead'], fn($o) => (int)$o['id'] !== $id));
$feOpts = array_values(array_filter($opts['frontend'], fn($o) => (int)$o['id'] !== $id));
$beOpts = array_values(array_filter($opts['backend'], fn($o) => (int)$o['id'] !== $id));
$cnOpts = array_values(array_filter($opts['counselor'], fn($o) => (int)$o['id'] !== $id));
$adminTaken = (bool)q("SELECT 1 FROM users WHERE role = 'admin' AND id != ?", [$id])->fetchColumn();
$officerLike = is_officer_like_role($t['role']);
$wa = wa_link($t['phone'] ?? null);
$editable = $isSuper && $t['role'] !== 'super_admin';
$kindIcon = ['created' => '✨', 'role' => '🔁', 'team' => '↔', 'access' => '🔑', 'details' => '✎', 'status' => '⏻', 'password' => '🔒'];

page_start($t['name'] . ' · Team', $u, PORTALS[$u['role']]['label']);
?>
<p><a href="<?= h(url('admin/staff.php')) ?>">← Team</a></p>

<?php if ($cred): $msg = login_message($cred['name'], $cred['username'], $cred['password']); $wl = wa_link($cred['phone'] ?? null, $msg); ?>
<div class="cred" role="status">
  <b>New password for <?= h($cred['name']) ?></b>
  <div>Username: <code><?= h($cred['username']) ?></code> · Temporary password: <code><?= h($cred['password']) ?></code></div>
  <div class="row-actions" style="margin-top:6px">
    <button type="button" class="btn sm" data-copy-text="<?= h($msg) ?>">📋 Copy login message</button>
    <?php if ($wl): ?><a class="btn sm primary" href="<?= h($wl) ?>" target="_blank" rel="noopener noreferrer">💬 Send on WhatsApp</a><?php endif; ?>
    <?php if (!empty($cred['email'])): ?><a class="btn sm" href="mailto:<?= h($cred['email']) ?>?subject=<?= rawurlencode('Your ScholarshipHive portal password') ?>&body=<?= rawurlencode($msg) ?>">✉ Email</a><?php endif; ?>
  </div>
  <p class="small">Shown only once.</p>
</div>
<?php endif; ?>

<section class="panel member-head">
  <span class="avatar lg"><?= h(user_initials($t['name'])) ?></span>
  <div class="member-main">
    <h1><?= h($t['name']) ?> <?= $self ? '<span class="pill sm">You</span>' : '' ?></h1>
    <div class="order-tags">
      <span class="pill <?= is_admin_role($t['role']) ? 'ok' : ($t['role'] === 'counselor' ? 'acc' : '') ?>"><?= h(STAFF_ROLE_OPTIONS[$role] ?? role_label($role)) ?></span>
      <?php if ($officerLike): ?><span class="pill <?= $dept === 'frontend' ? 'acc' : '' ?>"><?= h(team_label($dept)) ?></span><?php endif; ?>
      <span class="pill <?= $t['active'] ? 'ok' : 'bad' ?>"><?= $t['active'] ? 'Active' : 'Disabled' ?></span>
      <?php if ($t['branch']): ?><span class="pill">📍 <?= h($t['branch']) ?></span><?php endif; ?>
    </div>
    <div class="small muted" style="margin-top:6px">
      <span class="mono"><?= h($t['username']) ?></span><?= $t['job_title'] ? ' · ' . h($t['job_title']) : '' ?>
      · Joined <?= h(fmt_date(substr((string)$t['created_at'], 0, 10)) ?: '—') ?>
      · Last sign-in <?= $t['last_login'] ? h(fmt_date(substr((string)$t['last_login'], 0, 10))) : 'never' ?>
    </div>
  </div>
  <div class="row-actions">
    <?php if (!empty($t['email'])): ?><a class="btn sm" href="mailto:<?= h($t['email']) ?>">✉ <?= h($t['email']) ?></a><?php endif; ?>
    <?php if ($wa): ?><a class="btn sm" href="<?= h($wa) ?>" target="_blank" rel="noopener noreferrer">💬 <?= h($t['phone']) ?></a><?php endif; ?>
    <?php if ($officerLike): ?><a class="btn sm primary" href="<?= h(url('admin/officers.php?id=' . $id)) ?>">Open caseload →</a><?php endif; ?>
  </div>
</section>

<div class="kpis" style="margin:14px 0">
  <div class="kpi"><div class="l">Front-end students</div><div class="v"><?= $load['frontend'] ?></div></div>
  <div class="kpi"><div class="l">Back-end students</div><div class="v"><?= $load['backend'] ?></div></div>
  <?php if ($load['counselor'] || $t['role'] === 'counselor'): ?><div class="kpi"><div class="l">Counselled students</div><div class="v"><?= $load['counselor'] ?></div></div><?php endif; ?>
  <?php if ($load['lead'] || $t['role'] === 'pms_lead'): ?><div class="kpi"><div class="l">Students as lead</div><div class="v"><?= $load['lead'] ?></div></div><?php endif; ?>
</div>

<div class="member-grid">
<section class="panel" id="details">
  <div class="panel-h"><h2>Details</h2><p><?= $editable ? 'Name, sign-in and contact.' : 'Only the Super Admin can change these.' ?></p></div>
  <form method="post" class="fields">
    <?= csrf_field() ?><input type="hidden" name="action" value="details"><input type="hidden" name="id" value="<?= $id ?>">
    <?php $ro = $editable ? '' : 'disabled'; ?>
    <label class="f"><span>Full name</span><input name="name" value="<?= h($t['name']) ?>" required maxlength="150" <?= $ro ?>></label>
    <label class="f"><span>Username</span><input name="username" value="<?= h($t['username']) ?>" required pattern="[a-z0-9._\-]{3,40}" <?= $ro ?>></label>
    <label class="f"><span>Email</span><input type="email" name="email" value="<?= h($t['email'] ?? '') ?>" required <?= $ro ?>></label>
    <label class="f"><span>WhatsApp number</span><input type="tel" name="phone" value="<?= h($t['phone'] ?? '') ?>" required placeholder="0300 1234567" <?= $ro ?>></label>
    <label class="f"><span>Branch</span><select name="branch" <?= $ro ?>><option value="">—</option><?php foreach (BRANCHES as $b): ?><option <?= ($t['branch'] ?? '') === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?></select></label>
    <label class="f"><span>Job title</span><input name="job_title" value="<?= h($t['job_title'] ?? '') ?>" maxlength="80" <?= $ro ?>></label>
    <?php if ($editable): ?><div class="f"><span>&nbsp;</span><button class="btn primary" type="submit">Save details</button></div><?php endif; ?>
  </form>
</section>

<?php if ($editable): ?>
<section class="panel" id="role">
  <div class="panel-h"><h2>Change role or team</h2><p>Promote, move between Front End and Back End, and decide what happens to their students. Every student move is written to that student’s assignment history.</p></div>
  <form method="post" data-role-wizard
        data-old-role="<?= h($role) ?>" data-old-dept="<?= h($dept) ?>"
        data-fe="<?= $load['frontend'] ?>" data-be="<?= $load['backend'] ?>" data-cn="<?= $load['counselor'] ?>" data-ld="<?= $load['lead'] ?>"
        data-be-taken="<?= $load['be_taken'] ?>" data-fe-taken="<?= $load['fe_taken'] ?>" data-name="<?= h($t['name']) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="role-cards compact" role="radiogroup" aria-label="New role">
      <?php foreach (STAFF_ROLE_OPTIONS as $rk => $rl): ?>
        <label class="role-card"><input type="radio" name="role" value="<?= $rk ?>" <?= $role === $rk ? 'checked' : '' ?> <?= $rk === 'admin' && $adminTaken ? 'disabled' : '' ?>><span><b><?= h($rl) ?></b><?php if ($role === $rk): ?><small>current</small><?php elseif ($rk === 'admin' && $adminTaken): ?><small>one Admin already exists</small><?php endif; ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="f" data-rw-team style="margin-top:12px"><span>Team</span>
      <div class="seg" role="radiogroup" aria-label="Team">
        <label><input type="radio" name="department" value="frontend" <?= $dept === 'frontend' ? 'checked' : '' ?>><span>Front End</span></label>
        <label><input type="radio" name="department" value="backend" <?= $dept !== 'frontend' ? 'checked' : '' ?>><span>Back End</span></label>
      </div>
    </div>

    <div class="rw-box" data-rw-students hidden>
      <b>Their students</b>
      <div class="rw-opts">
        <label class="check" data-opt="carry"><input type="radio" name="students" value="carry"> <span data-carry-text>Take their students along</span></label>
        <label class="check" data-opt="keep"><input type="radio" name="students" value="keep"> <span>Keep their students as they are</span></label>
        <label class="check" data-opt="handover"><input type="radio" name="students" value="handover"> <span>Hand their students over to a colleague</span></label>
      </div>
      <div class="fields" style="margin-top:8px">
        <label class="f" data-to="frontend"><span data-to-label>Front-end students go to</span>
          <select name="to_frontend"><option value="">Leave unassigned</option><?php foreach ($feOpts as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f" data-to="backend"><span data-to-label>Back-end students go to</span>
          <select name="to_backend"><option value="">Leave unassigned</option><?php foreach ($beOpts as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f" data-to="counselor"><span>Counselled students go to</span>
          <select name="to_counselor"><option value="">Leave without counselor</option><?php foreach ($cnOpts as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f" data-to="lead"><span>Students they lead go to</span>
          <select name="to_lead"><option value="">Leave without lead</option><?php foreach ($leads as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
      </div>
    </div>

    <div class="rw-box" data-rw-perms hidden>
      <b>Access</b> <span class="small muted">— they can always view everything; tick what they may also change</span>
      <div class="perm-grid" style="margin-top:8px">
        <?php foreach (PERMISSIONS as $pk => [$pl, $pd]): ?>
          <label class="perm"><input type="checkbox" name="perms[]" value="<?= h($pk) ?>" data-cur="<?= in_array($pk, $perms, true) ? 1 : 0 ?>" data-def-admin="<?= in_array($pk, ROLE_DEFAULT_PERMISSIONS['admin'], true) ? 1 : 0 ?>" data-def-pms_lead="<?= in_array($pk, ROLE_DEFAULT_PERMISSIONS['pms_lead'], true) ? 1 : 0 ?>" <?= in_array($pk, $perms, true) ? 'checked' : '' ?>><span><b><?= h($pl) ?></b><small><?= h($pd) ?></small></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <label class="f" style="margin-top:12px"><span>Reason / note <small class="muted">(saved in the history)</small></span><input name="note" maxlength="400" placeholder="e.g. Promoted after 6 months, moved to back end"></label>
    <p class="rw-summary" data-rw-summary></p>
    <button class="btn primary" type="submit" data-rw-submit>Apply change</button>
  </form>
</section>
<?php endif; ?>

<?php if (in_array($t['role'], CONFIGURABLE_PERM_ROLES, true)): ?>
<section class="panel" id="access">
  <div class="panel-h"><h2>Access</h2><p><?= h(STAFF_ROLE_OPTIONS[$role]) ?> can view every student, officer and report. <?= $isSuper ? 'Tick what they may also change.' : '' ?></p></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="access"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="perm-grid">
      <label class="perm is-fixed"><input type="checkbox" checked disabled><span><b>View everything</b><small>All students, officers, pending tracker and dashboards.</small></span></label>
      <?php if ($t['role'] === 'pms_lead'): ?><label class="perm is-fixed"><input type="checkbox" checked disabled><span><b>Edit own students</b><small>Students where they are the front-end or back-end officer.</small></span></label><?php endif; ?>
      <?php foreach (PERMISSIONS as $pk => [$pl, $pd]): ?>
        <label class="perm"><input type="checkbox" name="perms[]" value="<?= h($pk) ?>" <?= in_array($pk, $perms, true) ? 'checked' : '' ?> <?= $isSuper ? '' : 'disabled' ?>><span><b><?= h($pl) ?></b><small><?= h($pd) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <?php if ($isSuper): ?><div class="actions" style="margin-top:12px"><button class="btn primary" type="submit">Save access</button></div><?php endif; ?>
  </form>
</section>
<?php endif; ?>

<?php if ($canSecurity): ?>
<section class="panel" id="security">
  <div class="panel-h"><h2>Security</h2><p>One device at a time is enforced for every staff account.</p></div>
  <div class="sec-rows">
    <form method="post" class="sec-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reset">
      <div><b>Reset password</b><div class="small muted">Creates a temporary password to send them. They set their own on next sign-in.</div></div>
      <button class="btn sm" type="submit">Reset password</button></form>
    <form method="post" class="sec-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="signout">
      <div><b>Sign out now</b><div class="small muted"><?= $t['session_started_at'] ? 'Signed in since ' . h(fmt_date(substr((string)$t['session_started_at'], 0, 10))) . ($t['session_ip'] ? ' from ' . h($t['session_ip']) : '') : 'No active sign-in recorded.' ?></div></div>
      <button class="btn sm" type="submit">Sign out</button></form>
    <form method="post" class="sec-row" onsubmit="return confirm('<?= $t['active'] ? 'Disable this account? They are signed out at once.' : 'Enable this account?' ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="toggle">
      <div><b><?= $t['active'] ? 'Disable account' : 'Enable account' ?></b><div class="small muted"><?= $t['active'] ? 'Blocks sign-in. Their students stay assigned — hand them over first with “Change role or team” if needed.' : 'Lets them sign in again.' ?></div></div>
      <button class="btn sm <?= $t['active'] ? 'danger-ghost' : 'primary' ?>" type="submit"><?= $t['active'] ? 'Disable' : 'Enable' ?></button></form>
  </div>
</section>
<?php endif; ?>

<section class="panel" id="history">
  <div class="panel-h"><h2>History</h2><p>Role, team, access and account changes.</p></div>
  <?php if (!$history): ?><p class="empty">No changes recorded yet.</p><?php else: ?>
  <ul class="timeline">
    <?php foreach ($history as $hc): ?>
      <li><span class="muted small"><?= h(fmt_date(substr((string)$hc['changed_at'], 0, 10))) ?> · <?= h(substr((string)$hc['changed_at'], 11, 5)) ?></span>
        <?= $kindIcon[$hc['kind']] ?? '•' ?> <b><?= h(ucfirst($hc['kind'] === 'details' ? 'Details changed' : ($hc['kind'] === 'access' ? 'Access' : ($hc['kind'] === 'team' ? 'Team change' : ($hc['kind'] === 'role' ? 'Role change' : $hc['kind']))))) ?></b>:
        <?= $hc['old_value'] ? h($hc['old_value']) . ' → ' : '' ?><b><?= h($hc['new_value'] ?? '') ?></b>
        <?= (int)$hc['students_moved'] ? ' · <span class="pill sm">' . (int)$hc['students_moved'] . ' student moves</span>' : '' ?>
        <span class="muted">by <?= h($hc['by_name'] ?: 'System') ?></span>
        <?= $hc['note'] ? '<div class="small">' . h($hc['note']) . '</div>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
</div>
<?php page_end();
