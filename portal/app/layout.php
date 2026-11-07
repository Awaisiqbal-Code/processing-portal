<?php
declare(strict_types=1);

function nav_items(string $role): array {
    return match ($role) {
        'super_admin' => [
            'admin/index.php'             => 'Dashboard',
            'admin/pending.php'           => 'Pending Tracker',
            'admin/students.php'          => 'Students',
            'admin/programs.php'          => 'University Programs',
            'admin/officers.php'          => 'Processing Officers',
            'admin/reports.php'           => 'Reports',
            'admin/pending_report.php'    => 'Officer Pending Reports',
            'admin/disabled_refunds.php'  => 'Disabled & Refunds',
            'admin/consent_templates.php' => 'Consent Templates',
            'admin/verify_docs.php'       => 'Verify documents',
            'admin/staff.php'             => 'Team',
            'admin/logins.php'            => 'Student logins',
        ],
        'admin' => [
            'admin/index.php'             => 'Dashboard',
            'admin/pending.php'           => 'Pending Tracker',
            'admin/students.php'          => 'Students',
            'admin/programs.php'          => 'University Programs',
            'admin/officers.php'          => 'Processing Officers',
            'admin/reports.php'           => 'Reports',
            'admin/pending_report.php'    => 'Officer Pending Reports',
            'admin/disabled_refunds.php'  => 'Disabled & Refunds',
            'admin/consent_templates.php' => 'Consent Templates',
            'admin/verify_docs.php'       => 'Verify documents',
            'admin/staff.php'             => 'Team',
            'admin/logins.php'            => 'Student logins',
        ],
        'pms_lead' => [
            'officer/index.php'          => 'Dashboard',
            'officer/pending.php'        => 'Pending Tracker',
            'officer/students.php'       => 'My students',
            'officer/programs.php'       => 'University Programs',
            'officer/reports.php'        => 'Reports',
            'officer/pending_report.php' => 'Officer Pending Reports',
            'officer/verify_docs.php'    => 'Verify documents',
        ],
        'processing_staff', 'officer' => [
            'officer/index.php'          => 'Dashboard',
            'officer/pending.php'        => 'Pending Tracker',
            'officer/students.php'       => 'My students',
            'officer/programs.php'       => 'University Programs',
            'officer/pending_report.php' => 'My Pending Report',
        ],
        'counselor' => [
            'officer/index.php'          => 'Dashboard',
            'officer/pending.php'        => 'Pending Tracker',
            'officer/students.php'       => 'My students',
            'officer/programs.php'       => 'University Programs',
        ],
        'student' => ['student/index.php' => 'My file'],
        default   => [],
    };
}

/** Line icons for the dock (24px grid, stroke = currentColor). */
function nav_icon(string $href): string {
    $file = basename(parse_url($href, PHP_URL_PATH) ?: $href, '.php');
    $p = match ($file) {
        'index'             => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        'pending'           => '<path d="M6 2h12M6 22h12"/><path d="M7 2v4a5 5 0 0 0 10 0V2"/><path d="M7 22v-4a5 5 0 0 1 10 0v4"/>',
        'students'          => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17.5" cy="9" r="2.5"/><path d="M16 14.2A5 5 0 0 1 21.5 19"/>',
        'programs'          => '<path d="M3 10 12 4l9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 21h18"/>',
        'officers'          => '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
        'reports'           => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'pending_report'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/><circle cx="18.5" cy="18.5" r="3" fill="var(--dock-badge-bg)"/><path d="M18.5 17.2v1.4l.9.6"/>',
        'disabled_refunds'  => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'consent_templates' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m9 15 2 2 4-4"/>',
        'staff'             => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'verify_docs'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 3v5h5"/><circle cx="16.5" cy="16.5" r="4.5"/><path d="m14.6 16.6 1.4 1.4 2.6-2.8"/>',
        'roles'             => '<path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'logins'            => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2M17 6l2 2M15 8l2 2"/>',
        default             => '<circle cx="12" cy="12" r="8"/>',
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

/** Small CSS flag (emoji flags show as two letters on Windows). */
function country_flag(?string $country): string {
    $code = match (trim((string)$country)) {
        'Italy' => 'it', 'France' => 'fr', 'Germany' => 'de', 'Czech Republic' => 'cz', 'Austria' => 'at', 'China' => 'cn', default => '',
    };
    return $code !== '' ? '<span class="flag flag-' . $code . '" title="' . h($country) . '" aria-hidden="true"></span>' : '<span class="flag flag-xx" aria-hidden="true"></span>';
}
function country_flags_map(): array {
    $m = [];
    foreach (['Italy', 'France', 'Germany', 'Czech Republic', 'Austria', 'China'] as $c) $m[$c] = country_flag($c);
    return $m;
}

function user_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    return $ini ?: '?';
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
 * Official ScholarshipHive Edutancy Emblem (Shield + Graduation Cap + Honeycomb Hive).
 */
function brand_logo_svg(int $size = 42): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" fill="none" aria-hidden="true">'
         . '<defs>'
         . '<linearGradient id="shShieldG" x1="8" y1="4" x2="56" y2="60" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#0F5D9E"/><stop offset="65%" stop-color="#12304B"/><stop offset="100%" stop-color="#1392CB"/></linearGradient>'
         . '<linearGradient id="shHiveG" x1="16" y1="24" x2="48" y2="54" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#74C3E4"/><stop offset="100%" stop-color="#1392CB"/></linearGradient>'
         . '</defs>'
         . '<path d="M32 4L10 13V30.5C10 45.2 19.4 55.9 32 60C44.6 55.9 54 45.2 54 30.5V13L32 4Z" fill="url(#shShieldG)" stroke="#74C3E4" stroke-width="1.8"/>'
         . '<path d="M32 7.5L13.5 15.2V30.2C13.5 42.8 21.4 52.2 32 56C42.6 52.2 50.5 42.8 50.5 30.2V15.2L32 7.5Z" stroke="#74C3E4" stroke-opacity="0.32" stroke-width="1"/>'
         . '<g stroke="url(#shHiveG)" stroke-width="1.5" fill="#1392CB" fill-opacity="0.22" stroke-linejoin="round">'
         . '<polygon points="32,33 36.5,35.6 36.5,40.8 32,43.4 27.5,40.8 27.5,35.6"/>'
         . '<polygon points="23,38.2 27.5,40.8 27.5,46 23,48.6 18.5,46 18.5,40.8"/>'
         . '<polygon points="41,38.2 45.5,40.8 45.5,46 41,48.6 36.5,46 36.5,40.8"/>'
         . '<polygon points="32,43.4 36.5,46 36.5,51.2 32,53.8 27.5,51.2 27.5,46"/>'
         . '</g>'
         . '<polygon points="32,13.5 15,21 32,28.5 49,21" fill="#FFFFFF"/>'
         . '<path d="M22 24.8V30.2C22 32.5 26.5 34.5 32 34.5C37.5 34.5 42 32.5 42 30.2V24.8L32 29.2L22 24.8Z" fill="#74C3E4"/>'
         . '<path d="M46.5 22.2V30.5" stroke="#74C3E4" stroke-width="1.8" stroke-linecap="round"/>'
         . '<circle cx="46.5" cy="31.5" r="1.8" fill="#FFFFFF"/>'
         . '</svg>';
}

/**
 * Brand Wordmark HTML ("ScholarshipHive" strong serif + "Edutancy" italic serif).
 */
function brand_lockup_html(string $subtitle = ''): string {
    $sub = $subtitle !== '' ? $subtitle : 'Global Education & Processing Portal';
    return '<div class="brand-logo-wrap">' . brand_logo_svg(42) . '</div>'
         . '<div>'
         . '<div class="app">ScholarshipHive<span class="edutancy">Edutancy</span></div>'
         . '<small>' . h($sub) . '</small>'
         . '</div>';
}

/**
 * Inline early-execution script to prevent Flash of Incorrect Theme (FOUC).
 * Defaults to Light theme (60% White, 25% Deep Academic Blue #0F5D9E, 10% Sky Blue #74C3E4) per Brand Guidelines.
 */
function theme_boot_script(): string {
    return "<script>(function(){try{var t=localStorage.getItem('ptp_theme');if(t!=='light'&&t!=='dark'){t='light';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
}

function page_start(string $title, ?array $u = null, string $portalLabel = ''): void {
    global $CONFIG;
    $self = ltrim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', strlen(base_url())), '/');
    $cookieTheme = $_COOKIE['ptp_theme'] ?? '';
    $htmlThemeAttr = in_array($cookieTheme, ['light', 'dark'], true) ? ' data-theme="' . h($cookieTheme) . '"' : ' data-theme="light"';
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
        $bodyAttrs .= ' data-has-dock="1"';
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
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,400;0,700;1,400;1,700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset_url('assets/style.css')) ?>">
<script src="<?= h(asset_url('assets/app.js')) ?>" defer></script>
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
<header class="topbar">
  <a class="brand" href="<?= h(url(PORTALS[$u['role']]['home'] ?? 'index.php')) ?>"><?= brand_lockup_html($portalLabel) ?></a>
  <div class="topbar-title" aria-hidden="true"><?= h(nav_items($u['role'])[$self] ?? '') ?></div>
  <div class="who">
    <?= theme_toggle_btn() ?>
    <?php if ($isStaff): ?>
      <div class="notif-bell-container">
        <button type="button" id="notif-bell-btn" class="icon-btn" aria-label="Notifications" title="Notifications">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <span id="notif-badge" class="notif-badge" style="display:none;">0</span>
        </button>
        <div id="notif-dropdown" class="notif-dropdown" data-notif-url="<?= h(url('notifications.php')) ?>" data-student-base="<?= h(url(is_admin_role($u['role']) ? 'admin' : 'officer') . '/student.php?id=') ?>" style="display:none;">
          <div class="notif-head">
            <b>Notifications</b>
            <button type="button" id="notif-mark-all" class="btn sm">Mark all read</button>
          </div>
          <div id="notif-list" class="notif-list">
            <div class="empty">No notifications.</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <details class="user-menu">
      <summary>
        <span class="avatar"><?= h(user_initials((string)$u['name'])) ?></span>
        <span class="user-meta"><b><?= h($u['name']) ?></b><small><?= h($roleBadge) ?></small></span>
        <svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
      </summary>
      <div class="user-menu-pop">
        <div class="user-menu-head"><b><?= h($u['name']) ?></b><span class="role"><?= h($roleBadge) ?></span></div>
        <a href="<?= h(url('account/password.php?portal=' . $u['role'])) ?>">Change password</a>
        <a href="<?= h(url('index.php?switch=1')) ?>">Switch account</a>
        <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_field() ?><input type="hidden" name="role" value="<?= h($u['role']) ?>"><input type="hidden" name="portal" value="<?= h($u['role']) ?>"><button type="submit">Sign out</button></form>
      </div>
    </details>
  </div>
</header>
<nav class="dock" id="app-dock" aria-label="Main navigation">
  <div class="dock-inner">
    <?php foreach (nav_items($u['role']) as $href => $label): if (!nav_allowed($u, $href)) continue; ?>
      <a class="dock-item" href="<?= h(url($href)) ?>" <?= $self === $href ? 'aria-current="page"' : '' ?> aria-label="<?= h($label) ?>">
        <span class="dock-icon"><?= nav_icon($href) ?></span>
        <span class="dock-label"><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
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

<!-- Student Remarks Interactive Modal -->
<div id="remarks-modal" class="idle-modal-backdrop" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="remarks-modal-title" data-api-url="<?= h(url('remarks_api.php')) ?>" data-csrf="<?= h(csrf_token()) ?>" data-student-base="<?= h(url(is_admin_role($u['role']) ? 'admin' : 'officer') . '/student.php?id=') ?>">
  <div class="idle-modal-card remarks-modal-card" style="max-width:620px; width:100%; border-color:var(--brand-trust); padding:20px 22px; max-height:88vh; display:flex; flex-direction:column; gap:12px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid var(--line); padding-bottom:10px;">
      <div>
        <div class="eyebrow" style="color:var(--accent);">💬 Student Remarks &amp; Timeline</div>
        <h2 id="remarks-modal-title" style="font-size:19px; margin-top:2px;">Student Remarks</h2>
        <div id="remarks-modal-sub" class="small muted" style="margin-top:2px;">All saved remarks and updates</div>
      </div>
      <div style="display:flex; gap:6px; align-items:center;">
        <a id="remarks-modal-profile-link" href="#" class="btn sm ghost">Open Profile &rarr;</a>
        <button type="button" id="remarks-modal-close" class="btn sm ghost" aria-label="Close" style="padding:4px 9px; font-weight:700;">✕</button>
      </div>
    </div>

    <!-- Add New Remark Form Inside Modal -->
    <form id="remarks-modal-form" style="display:flex; flex-direction:column; gap:8px; background:var(--sunk); padding:11px 13px; border-radius:10px; border:1px solid var(--line);">
      <input type="hidden" id="remarks-modal-sid" name="student_id" value="">
      <label style="font-size:12.5px; font-weight:700; color:var(--fg);">➕ Add New Remark (Adds a new dot &amp; updates Last Remark)</label>
      <textarea id="remarks-modal-input" name="remark_text" rows="2" required placeholder="Write a new remark or update for this student..." style="width:100%; border:1px solid var(--line); background:var(--input-bg); color:var(--fg); border-radius:8px; padding:8px 10px; font-size:13.2px; resize:vertical;"></textarea>
      <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
        <span id="remarks-modal-feedback" class="small muted">Each remark adds a clickable dot to the student's remark bar.</span>
        <button type="submit" id="remarks-modal-submit" class="btn sm primary">Save Remark</button>
      </div>
    </form>

    <!-- Remarks History List -->
    <div id="remarks-modal-list" style="overflow-y:auto; max-height:360px; display:flex; flex-direction:column; gap:8px; padding-right:2px;">
      <div class="empty">Loading remarks...</div>
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
    return last_remarks_cell($r);
}

/**
 * Renders the interactive "Last Remarks" cell with remark dots.
 * Each remark adds a dot; clicking opens the full remarks history & add-remark modal.
 */
function last_remarks_cell(array $r): string {
    $sid = (int)($r['id'] ?? 0);
    $stName = (string)($r['name'] ?? 'Student');
    $remCount = isset($r['remark_count']) ? (int)$r['remark_count'] : -1;
    $lastText = trim((string)($r['last_remark_text'] ?? ($r['remarks'] ?? '')));
    $lastAuthor = trim((string)($r['last_remark_author_name'] ?? ''));
    $lastDate = trim((string)($r['last_remark_created_at'] ?? ($r['last_remark_at'] ?? '')));

    if ($remCount < 0) {
        try {
            $remCount = (int)q("SELECT COUNT(*) FROM student_remarks WHERE student_id = ? AND deleted_at IS NULL", [$sid])->fetchColumn();
            if ($lastText === '' || $lastAuthor === '') {
                $lr = q("SELECT remark_text, author_name, created_at FROM student_remarks WHERE student_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1", [$sid])->fetch();
                if ($lr) {
                    $lastText = trim((string)$lr['remark_text']);
                    $lastAuthor = trim((string)$lr['author_name']);
                    $lastDate = trim((string)$lr['created_at']);
                }
            }
        } catch (Throwable) {
            $remCount = $lastText !== '' ? 1 : 0;
        }
    }
    if ($remCount === 0 && $lastText !== '') {
        $remCount = 1;
    }

    // Build remark dots: each remark adds an active filled dot (up to 10 visible dots)
    $dotsHtml = '<span class="steps remark-dots">';
    if ($remCount > 0) {
        $showDots = min(10, $remCount);
        for ($i = 0; $i < $showDots; $i++) {
            $isLatest = ($i === $showDots - 1);
            $dotsHtml .= '<i class="d' . ($isLatest ? ' latest' : '') . '"></i>';
        }
    } else {
        $dotsHtml .= '<i class="empty-dot"></i>';
    }
    $dotsHtml .= '</span>';

    $countBadge = '<span class="pill sm ' . ($remCount > 0 ? 'acc' : 'muted') . '" style="font-size:10.8px; padding:1px 6px;">'
                . ($remCount > 0 ? $remCount . ' remark' . ($remCount > 1 ? 's' : '') : 'No remarks')
                . '</span>';

    $previewText = $lastText !== ''
        ? h(mb_strimwidth($lastText, 0, 52, '…'))
        : '<span class="muted">Click to add remark…</span>';

    $metaParts = [];
    if ($lastAuthor !== '' && $lastAuthor !== 'Legacy import') {
        $metaParts[] = h($lastAuthor);
    }
    if ($lastDate !== '') {
        $metaParts[] = h(fmt_date(substr($lastDate, 0, 10)));
    }
    $metaHtml = !empty($metaParts)
        ? '<span class="small muted" style="font-size:10.5px;"> · ' . implode(' · ', $metaParts) . '</span>'
        : '';

    return '<button type="button" class="remark-cell-btn" data-open-remarks="' . $sid . '" data-student-name="' . h($stName) . '" title="Click to view all remarks &amp; add new remark for ' . h($stName) . '">'
         . '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">' . $dotsHtml . $countBadge . $metaHtml . '</div>'
         . '<div class="remark-cell-text">' . $previewText . '</div>'
         . '</button>';
}
