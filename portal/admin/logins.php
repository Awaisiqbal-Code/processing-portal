<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $sid = (int)post('student_id');
    $s = q('SELECT id, name FROM students WHERE id = ?', [$sid])->fetch();
    if (!$s) { http_response_code(404); exit('Not found.'); }
    $login = q('SELECT * FROM users WHERE student_id = ?', [$sid])->fetch();
    $back = post('back') === 'list' ? 'admin/logins.php' : "admin/student.php?id=$sid";
    if (post('action') === 'reset') {
        $pw = random_password(10);
        if ($login) {
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $login['id']]);
            $username = $login['username'];
        } else {
            $username = make_username($s['name']);
            q("INSERT INTO users (name, username, password_hash, role, student_id, active, must_change_password, created_at) VALUES (?,?,?,'student',?,1,1,?)",
              [$s['name'], $username, password_hash($pw, PASSWORD_DEFAULT), $sid, now()]);
        }
        log_action('Set student login password', $sid);
        $_SESSION['new_cred'] = [$s['name'], $username, $pw];
    } elseif (post('action') === 'toggle' && $login) {
        q('UPDATE users SET active = 1 - active WHERE id = ?', [$login['id']]);
        log_action(($login['active'] ? 'Disabled' : 'Enabled') . ' student login', $sid);
        flash($login['active'] ? 'Student login disabled.' : 'Student login enabled.');
    }
    redirect($back);
}

$fq = get('q');
$params = [];
$sql = 'SELECT s.id, s.name, o.name AS officer_name, l.username, l.active, l.last_login, l.must_change_password
        FROM students s LEFT JOIN users l ON l.student_id = s.id LEFT JOIN users o ON o.id = s.officer_id';
if ($fq !== '') { $sql .= ' WHERE s.name LIKE ? OR l.username LIKE ?'; $params = ['%' . $fq . '%', '%' . $fq . '%']; }
$rows = q($sql . ' ORDER BY s.name', $params)->fetchAll();
$cred = $_SESSION['new_cred'] ?? null; unset($_SESSION['new_cred']);

page_start('Student logins', $u, PORTALS['admin']['label']);
?>
<h1>Student logins</h1>
<?php if ($cred): ?>
<div class="cred" role="status">
  <b>Login for <?= h($cred[0]) ?></b>
  <div>Portal: <code><?= h(url('student/login.php')) ?></code></div>
  <div>Username: <code><?= h($cred[1]) ?></code> · Password: <code><?= h($cred[2]) ?></code></div>
  <p class="small">Send this to the student now. The password is shown only once, and they will choose their own when they first sign in.</p>
</div>
<?php endif; ?>
<section class="panel">
  <div class="panel-h"><div><h2>All students</h2><p>Passwords are stored encrypted, so they cannot be viewed. Reset one to issue a new password.</p></div></div>
  <form method="get" class="toolbar"><input type="search" name="q" value="<?= h($fq) ?>" placeholder="Search name or username" aria-label="Search"><button class="btn sm">Search</button><span class="count num"><?= count($rows) ?> students</span></form>
  <div class="tbl"><table>
    <thead><tr><th>Student</th><th>Username</th><th>Officer</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= h(url('admin/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a></td>
        <td class="mono"><?= h($r['username'] ?? '—') ?></td>
        <td><?= h($r['officer_name'] ?? 'Not assigned') ?></td>
        <td><?php if (!$r['username']): ?><span class="pill">No login</span><?php elseif (!$r['active']): ?><span class="pill bad">Disabled</span><?php elseif ($r['must_change_password']): ?><span class="pill warn">Not signed in yet</span><?php else: ?><span class="pill ok">Active</span><?php endif; ?></td>
        <td class="num"><?= $r['last_login'] ? h(fmt_date(substr($r['last_login'], 0, 10))) : 'Never' ?></td>
        <td><form method="post" class="row-actions"><?= csrf_field() ?><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="list">
          <a class="btn sm" href="<?= h(url('admin/preview.php?id=' . $r['id'])) ?>">View portal</a>
          <button class="btn sm" name="action" value="reset"><?= $r['username'] ? 'Reset password' : 'Create login' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php page_end();
