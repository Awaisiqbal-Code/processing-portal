// Processing Team Portal — Client Controller, Theme Manager & Staff Idle Timeout
(function () {
  'use strict';

  var STORAGE_KEY = 'ptp_theme';

  function getStoredTheme() {
    try {
      var t = localStorage.getItem(STORAGE_KEY);
      if (t === 'light' || t === 'dark') return t;
    } catch (e) {}
    return null;
  }

  function getEffectiveTheme() {
    var current = document.documentElement.getAttribute('data-theme');
    if (current === 'light' || current === 'dark') return current;
    var stored = getStoredTheme();
    if (stored) return stored;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }
    return 'light';
  }

  function syncToggleButtons(theme) {
    var isDark = theme === 'dark';
    var nextLabel = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    var modeText = isDark ? 'Light' : 'Dark';
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      btn.setAttribute('aria-label', nextLabel);
      btn.setAttribute('title', nextLabel);
      var labelEl = btn.querySelector('[data-theme-label]');
      if (labelEl) {
        labelEl.textContent = modeText;
      }
    }
  }

  function applyTheme(theme, persist) {
    if (theme !== 'light' && theme !== 'dark') return;
    document.documentElement.setAttribute('data-theme', theme);
    if (persist) {
      try {
        localStorage.setItem(STORAGE_KEY, theme);
      } catch (e) {}
      try {
        document.cookie = STORAGE_KEY + '=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
      } catch (e) {}
    }
    syncToggleButtons(theme);
  }

  function initThemeUI() {
    var effective = getEffectiveTheme();
    document.documentElement.setAttribute('data-theme', effective);
    syncToggleButtons(effective);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThemeUI);
  } else {
    initThemeUI();
  }

  document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
    if (!toggleBtn) return;
    e.preventDefault();
    var current = getEffectiveTheme();
    var next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
  });

  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY && (e.newValue === 'light' || e.newValue === 'dark')) {
      applyTheme(e.newValue, false);
    }
  });

  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) {
      mq.addEventListener('change', function (e) {
        if (!getStoredTheme()) {
          applyTheme(e.matches ? 'dark' : 'light', false);
        }
      });
    }
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.matches && e.target.matches('select[data-autosubmit]')) {
      e.target.form.submit();
    }
  });

  // =========================================================================
  // Staff-Only Inactivity Auto-Logout (Phase 1.3)
  // - Warning modal at 15 minutes idle (60-second visible countdown)
  // - Auto-logout at 20 minutes idle (or if the 60s modal countdown expires)
  // - Synced across browser tabs via BroadcastChannel + localStorage
  // - Applied ONLY when <body data-idle-enabled="1"> is present (staff portals)
  // =========================================================================
  function initStaffIdleTimer() {
    var body = document.body;
    if (!body || body.getAttribute('data-idle-enabled') !== '1') {
      return;
    }

    var warnMinutes = parseFloat(body.getAttribute('data-idle-warning-minutes') || '15');
    var logoutMinutes = parseFloat(body.getAttribute('data-idle-logout-minutes') || '20');
    var pingUrl = body.getAttribute('data-ping-url') || 'ping.php';
    var logoutUrl = body.getAttribute('data-logout-url') || 'index.php?idle_logout=1';

    var warnMs = Math.max(1000, warnMinutes * 60 * 1000);
    var logoutMs = Math.max(warnMs + 1000, logoutMinutes * 60 * 1000);
    var modalCountdownSeconds = 60;

    var modal = document.getElementById('idle-warning-modal');
    var countdownEl = document.getElementById('idle-countdown');
    var stayBtn = document.getElementById('idle-stay-btn');

    var IDLE_STORAGE_KEY = 'ptp_staff_last_active_ts';
    var IDLE_LOGOUT_KEY = 'ptp_staff_force_logout_ts';
    var lastActivityTs = Date.now();
    var lastPingTs = Date.now();
    var warningShownAt = null;
    var isLoggingOut = false;

    var channel = null;
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        channel = new BroadcastChannel('ptp_idle_sync');
      }
    } catch (e) {}

    function broadcastActivity(ts) {
      try {
        localStorage.setItem(IDLE_STORAGE_KEY, String(ts));
      } catch (e) {}
      if (channel) {
        try {
          channel.postMessage({ type: 'activity', ts: ts });
        } catch (e) {}
      }
    }

    function broadcastLogout() {
      var ts = Date.now();
      try {
        localStorage.setItem(IDLE_LOGOUT_KEY, String(ts));
      } catch (e) {}
      if (channel) {
        try {
          channel.postMessage({ type: 'logout', ts: ts });
        } catch (e) {}
      }
    }

    function showWarningModal(secondsLeft) {
      if (!modal) return;
      if (countdownEl) {
        countdownEl.textContent = String(Math.max(0, Math.ceil(secondsLeft)));
      }
      if (modal.hasAttribute('hidden')) {
        modal.removeAttribute('hidden');
        modal.setAttribute('aria-hidden', 'false');
      }
    }

    function hideWarningModal() {
      warningShownAt = null;
      if (!modal) return;
      if (!modal.hasAttribute('hidden')) {
        modal.setAttribute('hidden', 'hidden');
        modal.setAttribute('aria-hidden', 'true');
      }
    }

    function pingServerKeepAlive() {
      lastPingTs = Date.now();
      try {
        fetch(pingUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
          if (res.status === 401) {
            triggerIdleLogout(false);
          }
        }).catch(function () {});
      } catch (e) {}
    }

    function recordActivity(fromRemoteTab, forceServerPing) {
      if (isLoggingOut) return;
      // While the warning modal is visible, require clicking "Stay logged in"
      // (unless another tab explicitly reset activity).
      if (warningShownAt !== null && !fromRemoteTab && !forceServerPing) {
        return;
      }
      var now = Date.now();
      lastActivityTs = now;
      hideWarningModal();

      if (!fromRemoteTab) {
        broadcastActivity(now);
        if (forceServerPing || (now - lastPingTs) >= 60000) {
          pingServerKeepAlive();
        }
      }
    }

    function triggerIdleLogout(fromRemoteTab) {
      if (isLoggingOut) return;
      isLoggingOut = true;
      if (!fromRemoteTab) {
        broadcastLogout();
      }
      window.location.href = logoutUrl;
    }

    // Initialize shared timestamp
    broadcastActivity(lastActivityTs);

    var activityEvents = ['mousemove', 'mousedown', 'click', 'keydown', 'scroll', 'touchstart'];
    var throttleTs = 0;
    function onUserDomEvent(e) {
      if (modal && !modal.hasAttribute('hidden') && modal.contains(e.target)) {
        return;
      }
      var now = Date.now();
      if (now - throttleTs < 1000) return;
      throttleTs = now;
      recordActivity(false, false);
    }

    for (var i = 0; i < activityEvents.length; i++) {
      window.addEventListener(activityEvents[i], onUserDomEvent, { passive: true });
    }

    if (stayBtn) {
      stayBtn.addEventListener('click', function (e) {
        e.preventDefault();
        recordActivity(false, true);
      });
    }

    window.addEventListener('storage', function (e) {
      if (e.key === IDLE_STORAGE_KEY && e.newValue) {
        var remoteTs = parseInt(e.newValue, 10);
        if (!isNaN(remoteTs) && remoteTs > lastActivityTs) {
          lastActivityTs = remoteTs;
          hideWarningModal();
        }
      } else if (e.key === IDLE_LOGOUT_KEY && e.newValue) {
        triggerIdleLogout(true);
      }
    });

    if (channel) {
      channel.onmessage = function (ev) {
        if (!ev || !ev.data) return;
        if (ev.data.type === 'activity' && ev.data.ts > lastActivityTs) {
          lastActivityTs = ev.data.ts;
          hideWarningModal();
        } else if (ev.data.type === 'logout') {
          triggerIdleLogout(true);
        }
      };
    }

    setInterval(function () {
      if (isLoggingOut) return;
      var now = Date.now();
      var idleElapsed = now - lastActivityTs;

      if (idleElapsed >= logoutMs) {
        triggerIdleLogout(false);
        return;
      }

      if (idleElapsed >= warnMs) {
        if (warningShownAt === null) {
          warningShownAt = now;
        }
        var modalElapsedSec = (now - warningShownAt) / 1000;
        var remainingModalSec = modalCountdownSeconds - modalElapsedSec;
        var remainingHardSec = (logoutMs - idleElapsed) / 1000;
        var secLeft = Math.min(remainingModalSec, remainingHardSec);
        if (secLeft <= 0) {
          triggerIdleLogout(false);
          return;
        }
        showWarningModal(secLeft);
      } else {
        hideWarningModal();
      }
    }, 1000);
  }

  function initNotificationBell() {
    var bellBtn = document.getElementById('notif-bell-btn');
    var badge = document.getElementById('notif-badge');
    var dropdown = document.getElementById('notif-dropdown');
    var list = document.getElementById('notif-list');
    var markAllBtn = document.getElementById('notif-mark-all');

    if (!bellBtn || !dropdown || !list) return;

    var notifUrl = dropdown.getAttribute('data-notif-url') || 'notifications.php';
    var studentBase = dropdown.getAttribute('data-student-base') || 'student.php?id=';

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function renderNotifications(data, unreadCount) {
      if (unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }

      if (!data || data.length === 0) {
        list.innerHTML = '<div class="empty" style="padding:1.25rem; font-size:0.85rem; text-align:center;">No notifications.</div>';
        return;
      }

      var html = '';
      for (var i = 0; i < data.length; i++) {
        var n = data[i];
        var isUnread = (parseInt(n.is_read, 10) === 0);
        var bg = isUnread ? 'background:rgba(59,130,246,0.06);' : '';
        var border = isUnread ? 'border-left:3px solid var(--accent);' : 'border-left:3px solid transparent;';
        var cursor = n.student_id ? 'cursor:pointer;' : '';
        html += '<div class="notif-item" data-id="' + n.id + '" data-student="' + (n.student_id || '') + '" style="padding:0.6rem 0.85rem; border-bottom:1px solid var(--border); ' + bg + ' ' + border + ' ' + cursor + '">';
        html += '<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.4rem;">';
        html += '<b style="font-size:0.84rem; color:var(--fg);">' + escapeHtml(n.title) + '</b>';
        html += '<span class="small muted" style="font-size:0.7rem; white-space:nowrap;">' + escapeHtml(n.created_at ? n.created_at.substr(5, 11) : '') + '</span>';
        html += '</div>';
        html += '<div style="font-size:0.8rem; color:var(--muted); margin-top:0.2rem; line-height:1.4;">' + escapeHtml(n.message) + '</div>';
        html += '</div>';
      }
      list.innerHTML = html;

      // Attach click events to notification rows
      var rows = list.querySelectorAll('.notif-item');
      for (var j = 0; j < rows.length; j++) {
        rows[j].addEventListener('click', function () {
          var nid = this.getAttribute('data-id');
          var sid = this.getAttribute('data-student');
          // Mark single read
          fetch(notifUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Background-Poll': '1' },
            body: 'action=mark_read&id=' + encodeURIComponent(nid)
          }).catch(function () {});

          if (sid) {
            window.location.href = studentBase + encodeURIComponent(sid);
          } else {
            fetchNotifications();
          }
        });
      }
    }

    function fetchNotifications() {
      fetch(notifUrl + '?limit=30', {
        headers: { 'X-Background-Poll': '1', 'Accept': 'application/json' }
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res && res.ok) {
          renderNotifications(res.data, res.unread_count);
        }
      })
      .catch(function () {});
    }

    bellBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isClosed = (dropdown.style.display === 'none' || dropdown.style.display === '');
      dropdown.style.display = isClosed ? 'block' : 'none';
      if (isClosed) {
        fetchNotifications();
      }
    });

    if (markAllBtn) {
      markAllBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fetch(notifUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Background-Poll': '1' },
          body: 'action=mark_all_read'
        })
        .then(function (r) { return r.json(); })
        .then(function () {
          fetchNotifications();
        })
        .catch(function () {});
      });
    }

    document.addEventListener('click', function (e) {
      if (!dropdown.contains(e.target) && !bellBtn.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });

    // Initial fetch & 60-second polling loop
    fetchNotifications();
    setInterval(fetchNotifications, 60000);
  }

  function initApp() {
    initStaffIdleTimer();
    initNotificationBell();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
  } else {
    initApp();
  }
})();
