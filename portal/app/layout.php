<?php
declare(strict_types=1);

function nav_items(string $role): array {
    return match ($role) {
        'admin'   => ['admin/index.php' => 'Dashboard', 'admin/students.php' => 'Students', 'admin/officers.php' => 'Processing Officers', 'admin/reports.php' => 'Reports', 'admin/staff.php' => 'Staff and admins', 'admin/logins.php' => 'Student logins'],
        'officer' => ['officer/index.php' => 'Dashboard', 'officer/students.php' => 'My students'],
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
<body>
<div class="wrap">
<?php if ($u):
    $roleBadge = ucfirst($u['role']);
    if ($u['role'] === 'officer') {
        $roleBadge = (($u['department'] ?? 'backend') === 'frontend') ? 'Front-End Officer' : 'Back-End Officer';
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
    <span class="role"><?= h($roleBadge) ?></span>
    <b><?= h($u['name']) ?></b>
    <a class="btn sm ghost" href="<?= h(url('index.php?switch=1')) ?>">Switch account</a>
    <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_field() ?><input type="hidden" name="role" value="<?= h($u['role']) ?>"><input type="hidden" name="portal" value="<?= h($u['role']) ?>"><button class="btn sm ghost" type="submit">Sign out</button></form>
  </div>
</header>
<?php else: ?>
<div class="guest-topbar">
  <?= theme_toggle_btn() ?>
</div>
<?php endif; ?>
<main>
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
