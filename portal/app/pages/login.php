<?php
declare(strict_types=1);
// Unified sign-in page for Admin, Processing Officers (Front-End & Back-End), Counselors, and Students.

$portal = $portal ?? 'officer';
$idleLogout = get('idle_logout') === '1';
if ($idleLogout) {
    logout_now(null);
}

$activeUser = current_user();
if ($activeUser && get('switch') !== '1' && !$idleLogout) {
    $next = safe_next($activeUser['role'], get('next'));
    redirect($next ?: PORTALS[$activeUser['role']]['home']);
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login($portal, post('username'), $_POST['password'] ?? '');
    if ($error === null) {
        $loggedRole = $_SESSION['last_login_role'] ?? $portal;
        $next = safe_next($loggedRole, get('next'));
        redirect($next ?: PORTALS[$loggedRole]['home']);
    }
}

page_start('Sign In · ScholarshipHive Edutancy');
?>
<div class="login">
  <div class="brand-hero" style="flex-direction:column; align-items:flex-start; gap:10px; padding:22px 24px;">
    <div style="display:flex; gap:14px; align-items:center;">
      <div class="brand-logo-wrap" style="width:52px; height:52px;"><?= brand_logo_svg(52) ?></div>
      <div>
        <div style="font-family:var(--f-serif); font-weight:700; font-size:21px; color:#FFFFFF; line-height:1.15;">
          ScholarshipHive <span style="font-style:italic; font-weight:400; color:#74C3E4;">Edutancy</span>
        </div>
        <div style="font-size:12px; color:#E8EDF2; font-weight:600; margin-top:2px;">
          Global Education, Scholarships &amp; Admissions Ecosystem
        </div>
      </div>
    </div>
    <div class="brand-promise">“Guiding every student's journey with clarity, honesty, and expertise.”</div>
    <div class="values-strip">
      <span class="value-chip">🎓 Student First</span>
      <span class="value-chip">🛡 Integrity &amp; Trust</span>
      <span class="value-chip">🌍 Global Opportunity</span>
      <span class="value-chip">✨ Excellence</span>
    </div>
  </div>
  <section class="panel" style="padding:22px 24px;">
    <h1>Sign in to your portal</h1>
    <p class="muted">Enter your username and password to open your <b>Super Admin / Admin</b>, <b>PMS Lead / Processing Officer</b>, <b>Counselor</b>, or <b>Student</b> workspace.</p>
    <?php if ($idleLogout): ?>
      <div class="flash warn" role="alert">Logged out due to inactivity</div>
    <?php endif; ?>
    <?php if ($activeUser): ?>
      <div class="flash" style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;">
        <span class="small">Currently signed in as <b><?= h($activeUser['name']) ?></b> (<?= h(role_label($activeUser['role'])) ?>)</span>
        <a class="btn sm primary" href="<?= h(url(PORTALS[$activeUser['role']]['home'])) ?>">Continue to Dashboard →</a>
      </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="on">
      <?= csrf_field() ?>
      <label class="f"><span>Username</span><input name="username" id="username" required autocomplete="username" placeholder="e.g. admin, nimra, mujassam, minahil, sehrish, farwa" value="<?= h(post('username')) ?>" autofocus></label>
      <label class="f"><span>Password</span><input name="password" id="password" type="password" required autocomplete="current-password" placeholder="Enter your password"></label>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  </section>
</div>
<?php page_end();
