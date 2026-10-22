<?php
declare(strict_types=1);

function nav_items(string $role): array {
    return match ($role) {
        'super_admin' => [
            'admin/index.php'             => 'Dashboard',
            'admin/students.php'          => 'Students',
            'admin/officers.php'          => 'Processing Officers',
            'admin/reports.php'           => 'Reports',
            'admin/disabled_refunds.php'  => 'Disabled & Refunds',
            'admin/consent_templates.php' => 'Consent Templates',
            'admin/staff.php'             => 'Staff and admins',
            'admin/roles.php'             => 'User & Role Management',
            'admin/logins.php'            => 'Student logins',
        ],
        'admin' => [
            'admin/index.php'             => 'Dashboard',
            'admin/students.php'          => 'Students',
            'admin/officers.php'          => 'Processing Officers',
            'admin/reports.php'           => 'Reports',
            'admin/disabled_refunds.php'  => 'Disabled & Refunds',
            'admin/consent_templates.php' => 'Consent Templates',
            'admin/staff.php'             => 'Staff and admins',
            'admin/logins.php'            => 'Student logins',
        ],
        'pms_lead' => [
            'officer/index.php'          => 'Dashboard',
            'officer/students.php'       => 'My students',
            'officer/reports.php'        => 'Reports',
        ],
        'processing_staff', 'officer', 'counselor' => [
            'officer/index.php'          => 'Dashboard',
            'officer/students.php'       => 'My students',
        ],
        'student' => ['student/index.php' => 'My file'],
        default   => [],
    };
}

/**
 * Accessible Sun/Moon Theme Toggle Button.
 */
function theme_toggle_btn(): string {
    return '<button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch theme" title="Switch between Light and Dark mode" aria-pressed="false">'
         . '<svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
         . '<svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
         . '<span data-theme-label>Dark</span>'
         . '</button>';
}

/**
 * Inline early-execution script to prevent Flash of Incorrect Theme (FOUC).
 */
function theme_boot_script(): string {
    return "<script>(function(){try{var t=localStorage.getItem('ptp_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
}

function page_start(string $title, ?array $u = null, string $portalLabel = ''): void {
    global $CONFIG;
    $self = ltrim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', strlen(base_url())), '/');
    $cookieTheme = $_COOKIE['ptp_theme'] ?? '';
    $htmlThemeAttr = in_array($cookieTheme, ['light', 'dark'], true) ? ' data-theme="' . h($cookieTheme) . '"' : '';
    $isStaff = $u && is_staff_role($u['role'] ?? null);
    $idleWarnMin = $isStaff ? (int)setting('idle_warning_minutes', $CONFIG['idle_warning_minutes'] ?? 15) : 0;
    $idleLogoutMin = $isStaff ? (int)setting('idle_logout_minutes', $CONFIG['idle_logout_minutes'] ?? 20) : 0;
    $bodyAttrs = '';
    if ($isStaff) {
        $bodyAttrs = sprintf(
            ' data-idle-enabled="1" data-idle-warning-minutes="%d" data-idle-logout-minutes="%d" data-ping-url="%s" data-logout-url="%s"',
            $idleWarnMin,
            $idleLogoutMin,
            h(url('ping.php')),
            h(url('index.php?idle_logout=1'))
        );
    }
    ?><!doctype html>
<html lang="en"<?= $htmlThemeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h($CONFIG['app_name']) ?></title>
<?= theme_boot_script() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@500;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
<script src="<?= h(url('assets/app.js')) ?>" defer></script>
</head>
<body<?= $bodyAttrs ?>>
<div class="wrap">
<?php if ($u):
    $roleBadge = role_label($u['role']);
    if (in_array($u['role'], ['pms_lead', 'processing_staff', 'officer'], true)) {
        $teamLabel = (($u['department'] ?? 'backend') === 'frontend') ? 'Front-End' : 'Back-End';
        $roleBadge = role_label($u['role']) . ' · ' . $teamLabel;
    }
?>
<header class="top">
  <a class="brand" href="<?= h(url(PORTALS[$u['role']]['home'] ?? 'index.php')) ?>" style="text-decoration:none; color:inherit;"><div class="mark">P</div><div><div class="app"><?= h($CONFIG['app_name']) ?></div><small><?= h($portalLabel) ?></small></div></a>
  <nav class="tabs">
    <?php foreach (nav_items($u['role']) as $href => $label): ?>
      <a href="<?= h(url($href)) ?>" <?= $self === $href ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= h(url('account/password.php?portal=' . $u['role'])) ?>" <?= $self === 'account/password.php' ? 'aria-current="page"' : '' ?>>Change password</a>
  </nav>
  <div class="who">
    <?= theme_toggle_btn() ?>
    <?php if ($isStaff): ?>
      <div class="notif-bell-container" style="position:relative; display:inline-flex; align-items:center;">
        <button type="button" id="notif-bell-btn" class="btn sm ghost" aria-label="Notifications" title="Notifications" style="position:relative; padding:0.3rem 0.55rem; font-size:1.05rem; line-height:1;">
          🔔<span id="notif-badge" class="pill bad" style="display:none; position:absolute; top:-6px; right:-6px; font-size:0.65rem; padding:0.1rem 0.35rem; font-weight:700; border-radius:10px;">0</span>
        </button>
        <div id="notif-dropdown" class="notif-dropdown" data-notif-url="<?= h(url('notifications.php')) ?>" data-student-base="<?= h(url(is_admin_role($u['role']) ? 'admin' : 'officer') . '/student.php?id=') ?>" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:340px; background:var(--surface); border:1.5px solid var(--border); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.18); z-index:1000; overflow:hidden;">
          <div style="display:flex; justify-content:space-between; align-items:center; padding:0.6rem 0.85rem; border-bottom:1px solid var(--border); background:var(--sunk);">
            <b style="font-size:0.88rem;">Notifications</b>
            <button type="button" id="notif-mark-all" class="btn sm" style="font-size:0.72rem; padding:0.15rem 0.45rem;">Mark all read</button>
          </div>
          <div id="notif-list" style="max-height:360px; overflow-y:auto; padding:0.3rem 0;">
            <div class="empty" style="padding:1rem; font-size:0.85rem;">No notifications.</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <span class="role"><?= h($roleBadge) ?></span>
    <b><?= h($u['name']) ?></b>
    <a class="btn sm ghost" href="<?= h(url('index.php?switch=1')) ?>">Switch account</a>
    <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_field() ?><input type="hidden" name="role" value="<?= h($u['role']) ?>"><input type="hidden" name="portal" value="<?= h($u['role']) ?>"><button class="btn sm ghost" type="submit">Sign out</button></form>
  </div>
</header>
<?php if ($isStaff): ?>
<div id="idle-warning-modal" class="idle-modal-backdrop" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="idle-modal-title">
  <div class="idle-modal-card">
    <h2 id="idle-modal-title">Session Inactivity Warning</h2>
    <p id="idle-modal-message">You will be logged out in <b id="idle-countdown">60</b> seconds due to inactivity.</p>
    <div class="idle-modal-actions">
      <button type="button" id="idle-stay-btn" class="btn primary">Stay logged in</button>
      <a href="<?= h(url('index.php?idle_logout=1')) ?>" class="btn ghost">Sign out now</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="guest-topbar">
  <?= theme_toggle_btn() ?>
</div>
<?php endif; ?>
<main>
<?php if (!empty($GLOBALS['RAILWAY_VOLUME_MISSING'])): ?>
  <div class="flash bad" style="font-weight:600; font-size:15px; border-width:2px; margin-bottom:15px;" role="alert">
    ⚠️ CRITICAL: Railway persistent volume (/data) is NOT mounted or writable! All database writes are disabled to protect against data loss. Attach a persistent volume to /data in Railway service settings.
  </div>
<?php endif; ?>
<?php foreach (take_flash() as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>" role="status"><?= h($msg) ?></div>
<?php endforeach;
}

function page_end(): void { ?>
</main>
</div>
</body>
</html>
<?php }

function pill_class(string $v): string {
    return match ($v) {
        'Granted', 'Verified', 'Yes', 'Done' => 'ok',
        'Refused', 'Non-Refundable', 'No'    => 'bad',
        'Pending', 'Requested'               => 'warn',
        default                              => 'acc',
    };
}

function progress_dots(array $r): string {
    $o = '<span class="steps" title="' . done_count($r) . ' of ' . count(STAGES) . ' steps">';
    foreach (STAGES as $i => $_) $o .= '<i class="' . (stage_done($r, $i) ? 'd' : '') . '"></i>';
    return $o . '</span>';
}
