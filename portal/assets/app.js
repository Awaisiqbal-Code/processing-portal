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
    var stored = getStoredTheme();
    if (stored) return stored;
    var current = document.documentElement.getAttribute('data-theme');
    if (current === 'light' || current === 'dark') return current;
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
            res.json().then(function (j) { handleSessionEnd(j && j.reason); }).catch(function () { triggerIdleLogout(false); });
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

    // Session ended on the server (signed in elsewhere, or expired): go to the sign-in page with the right message.
    function handleSessionEnd(reason) {
      if (isLoggingOut) return;
      isLoggingOut = true;
      var base = logoutUrl.split('index.php')[0];
      window.location.href = base + 'index.php?logged_out=' + (reason === 'replaced' ? 'replaced' : 'idle');
    }

    // Background check every 25s: detects "signed in on another device" quickly, without extending the idle timer.
    setInterval(function () {
      if (isLoggingOut || document.hidden) return;
      fetch(pingUrl + '?check=1', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Background-Poll': '1' }
      }).then(function (res) {
        if (res.status === 401) {
          res.json().then(function (j) { handleSessionEnd(j && j.reason); }).catch(function () { handleSessionEnd('idle'); });
        }
      }).catch(function () {});
    }, 25000);

    function triggerIdleLogout(fromRemoteTab) {
      if (isLoggingOut) return;
      isLoggingOut = true;
      if (!fromRemoteTab) {
        broadcastLogout();
      }
      // Come back to the same page after signing in again
      var basePath = logoutUrl.split('index.php')[0];
      var rel = window.location.pathname;
      if (basePath && rel.indexOf(basePath) === 0) rel = rel.substring(basePath.length);
      rel = rel.replace(/^\/+/, '') + window.location.search;
      var sep = logoutUrl.indexOf('?') === -1 ? '?' : '&';
      window.location.href = /^(admin|officer)\//.test(rel) ? logoutUrl + sep + 'next=' + encodeURIComponent(rel) : logoutUrl;
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

  function initRemarksModal() {
    var modal = document.getElementById('remarks-modal');
    if (!modal) return;

    var apiUrl = modal.getAttribute('data-api-url') || 'remarks_api.php';
    var csrf = modal.getAttribute('data-csrf') || '';
    var studentBase = modal.getAttribute('data-student-base') || 'student.php?id=';

    var titleEl = document.getElementById('remarks-modal-title');
    var subEl = document.getElementById('remarks-modal-sub');
    var profileLink = document.getElementById('remarks-modal-profile-link');
    var closeBtn = document.getElementById('remarks-modal-close');
    var form = document.getElementById('remarks-modal-form');
    var sidInput = document.getElementById('remarks-modal-sid');
    var textInput = document.getElementById('remarks-modal-input');
    var feedbackEl = document.getElementById('remarks-modal-feedback');
    var submitBtn = document.getElementById('remarks-modal-submit');
    var listEl = document.getElementById('remarks-modal-list');

    var activeTriggerBtn = null;

    function esc(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function buildDotsHtml(total) {
      var html = '<span class="steps remark-dots">';
      if (total > 0) {
        var show = Math.min(10, total);
        for (var i = 0; i < show; i++) {
          var isLatest = (i === show - 1);
          html += '<i class="d' + (isLatest ? ' latest' : '') + '"></i>';
        }
      } else {
        html += '<i class="empty-dot"></i>';
      }
      html += '</span>';
      return html;
    }

    function updateTableCellsForStudent(sid, remarks) {
      var total = remarks ? remarks.length : 0;
      var latest = total > 0 ? remarks[0] : null;
      var btns = document.querySelectorAll('[data-open-remarks="' + sid + '"]');
      for (var i = 0; i < btns.length; i++) {
        var b = btns[i];
        var dotsHtml = buildDotsHtml(total);
        var badgeHtml = '<span class="pill sm ' + (total > 0 ? 'acc' : 'muted') + '" style="font-size:10.8px; padding:1px 6px;">'
                      + (total > 0 ? total + ' remark' + (total > 1 ? 's' : '') : 'No remarks')
                      + '</span>';
        var metaParts = [];
        if (latest && latest.author_name && latest.author_name !== 'Legacy import') {
          metaParts.push(esc(latest.author_name));
        }
        if (latest && latest.date_formatted) {
          metaParts.push(esc(latest.date_formatted));
        }
        var metaHtml = metaParts.length > 0
          ? '<span class="small muted" style="font-size:10.5px;"> · ' + metaParts.join(' · ') + '</span>'
          : '';
        var prevText = (latest && latest.remark_text)
          ? esc(latest.remark_text.length > 52 ? latest.remark_text.substring(0, 50) + '…' : latest.remark_text)
          : '<span class="muted">Click to add remark…</span>';
        b.innerHTML = '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">' + dotsHtml + badgeHtml + metaHtml + '</div>'
                    + '<div class="remark-cell-text">' + prevText + '</div>';
      }
    }

    function renderRemarksList(res) {
      var remarks = res.remarks || [];
      var total = remarks.length;
      if (subEl) {
        var meta = [];
        if (res.country) meta.push(res.country);
        if (res.program_level) meta.push(res.program_level);
        meta.push(total + ' total remark' + (total === 1 ? '' : 's'));
        subEl.textContent = meta.join(' · ');
      }

      if (total === 0) {
        listEl.innerHTML = '<div class="empty" style="padding:18px;">No remarks recorded for this student yet. Add the first remark above!</div>';
        return;
      }

      var html = '';
      for (var i = 0; i < total; i++) {
        var r = remarks[i];
        var isLatest = (i === 0);
        var isCounselor = (r.author_role === 'counselor');
        var borderStyle = isLatest ? 'border-left:4px solid #0F5D9E;' : 'border-left:3px solid var(--line-strong);';
        html += '<div class="li" style="flex-direction:column; align-items:flex-start; gap:5px; padding:10px 13px; ' + borderStyle + '">';
        html += '<div style="display:flex; justify-content:space-between; width:100%; align-items:center; flex-wrap:wrap; gap:6px;">';
        html += '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:12.5px;">';
        html += '<span class="steps"><i class="d' + (isLatest ? ' latest' : '') + '"></i></span>';
        html += '<b>' + esc(r.author_name || 'Staff') + '</b>';
        if (isLatest) {
          html += '<span class="pill sm acc" style="font-size:10.5px; padding:1px 6px;">Latest Remark</span>';
        }
        if (isCounselor) {
          html += '<span class="pill sm" style="background:#0284c7; color:#fff; font-size:10.5px; padding:1px 6px;">Counselor</span>';
        }
        html += '</div>';
        html += '<span class="small muted" style="font-size:11.5px;">' + esc(r.date_formatted || '') + (r.time_ago ? ' (' + esc(r.time_ago) + ')' : '') + '</span>';
        html += '</div>';
        html += '<div style="font-size:13.3px; line-height:1.48; color:var(--fg); white-space:pre-wrap; word-break:break-word;">' + esc(r.remark_text) + '</div>';
        html += '</div>';
      }
      listEl.innerHTML = html;
    }

    function openModal(sid, stName, triggerBtn) {
      activeTriggerBtn = triggerBtn || null;
      sidInput.value = String(sid);
      if (titleEl) titleEl.textContent = stName ? ('Remarks · ' + stName) : ('Student #' + sid + ' Remarks');
      if (subEl) subEl.textContent = 'Loading remarks history...';
      if (profileLink) profileLink.href = studentBase + encodeURIComponent(sid) + '#remarks';
      if (textInput) textInput.value = '';
      if (feedbackEl) {
        feedbackEl.textContent = 'Each remark adds a dot to the student\'s remark bar.';
        feedbackEl.style.color = 'var(--muted)';
      }
      listEl.innerHTML = '<div class="empty" style="padding:18px;">Loading remarks...</div>';

      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');

      fetch(apiUrl + '?student_id=' + encodeURIComponent(sid), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.ok) {
          if (titleEl && res.student_name) titleEl.textContent = 'Remarks · ' + res.student_name;
          renderRemarksList(res);
          updateTableCellsForStudent(sid, res.remarks || []);
        } else {
          listEl.innerHTML = '<div class="empty" style="color:var(--bad);">' + esc((res && res.error) || 'Could not load remarks.') + '</div>';
        }
      })
      .catch(function () {
        listEl.innerHTML = '<div class="empty" style="color:var(--bad);">Network error while loading remarks.</div>';
      });
    }

    function closeModal() {
      modal.setAttribute('hidden', 'hidden');
      modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-open-remarks]') : null;
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        var sid = btn.getAttribute('data-open-remarks');
        var stName = btn.getAttribute('data-student-name') || '';
        if (sid) openModal(sid, stName, btn);
        return;
      }
      if (e.target === modal) {
        closeModal();
      }
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
        closeModal();
      }
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var sid = sidInput.value;
        var text = textInput.value.trim();
        if (!sid || !text) return;

        if (submitBtn) submitBtn.disabled = true;
        if (feedbackEl) {
          feedbackEl.textContent = 'Saving remark...';
          feedbackEl.style.color = 'var(--muted)';
        }

        var body = '_csrf=' + encodeURIComponent(csrf)
                 + '&student_id=' + encodeURIComponent(sid)
                 + '&remark_text=' + encodeURIComponent(text);

        fetch(apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          credentials: 'same-origin',
          body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (submitBtn) submitBtn.disabled = false;
          if (res && res.ok) {
            textInput.value = '';
            if (feedbackEl) {
              feedbackEl.textContent = '✓ Remark saved & new dot added!';
              feedbackEl.style.color = 'var(--ok)';
            }
            renderRemarksList(res);
            updateTableCellsForStudent(sid, res.remarks || []);
          } else {
            if (feedbackEl) {
              feedbackEl.textContent = (res && res.error) || 'Failed to save remark.';
              feedbackEl.style.color = 'var(--bad)';
            }
          }
        })
        .catch(function () {
          if (submitBtn) submitBtn.disabled = false;
          if (feedbackEl) {
            feedbackEl.textContent = 'Network error while saving remark.';
            feedbackEl.style.color = 'var(--bad)';
          }
        });
      });
    }
  }

  // =========================================================================
  // macOS-style dock: magnifies under the pointer, hides while scrolling,
  // comes back as soon as scrolling stops (or the pointer nears the bottom).
  // =========================================================================
  function initDock() {
    var dock = document.getElementById('app-dock');
    if (!dock) return;
    var items = Array.prototype.slice.call(dock.querySelectorAll('.dock-item'));
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var wide = function () { return window.innerWidth > 760; };

    if (!reduce) {
      dock.addEventListener('mousemove', function (e) {
        if (!wide()) return;
        for (var i = 0; i < items.length; i++) {
          var r = items[i].getBoundingClientRect();
          var cx = r.left + r.width / 2;
          var d = Math.abs(e.clientX - cx);
          var s = 1 + 0.42 * Math.max(0, 1 - d / 130);
          items[i].style.setProperty('--s', s.toFixed(3));
        }
      });
      dock.addEventListener('mouseleave', function () {
        for (var i = 0; i < items.length; i++) items[i].style.setProperty('--s', '1');
      });
    }

    var timer = null;
    var lastY = window.scrollY;
    var pointerNearBottom = false;
    function show() { dock.classList.remove('is-hidden'); }
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (Math.abs(y - lastY) > 4 && !pointerNearBottom && !dock.matches(':hover')) {
        dock.classList.add('is-hidden');
      }
      lastY = y;
      clearTimeout(timer);
      timer = setTimeout(show, 420);
    }, { passive: true });
    document.addEventListener('mousemove', function (e) {
      pointerNearBottom = (window.innerHeight - e.clientY) < 90;
      if (pointerNearBottom) show();
    }, { passive: true });
    dock.addEventListener('focusin', show);
  }

  function initUserMenu() {
    var menus = document.querySelectorAll('details.user-menu');
    if (!menus.length) return;
    document.addEventListener('click', function (e) {
      for (var i = 0; i < menus.length; i++) {
        if (menus[i].open && !menus[i].contains(e.target)) menus[i].open = false;
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') for (var i = 0; i < menus.length; i++) menus[i].open = false;
    });
  }

  // Searchable dropdowns: <select data-searchable="placeholder"> gets a small filter box above it
  function initSearchableSelects(root) {
    var sels = (root || document).querySelectorAll('select[data-searchable]:not([data-ss-ready])');
    for (var i = 0; i < sels.length; i++) {
      (function (sel) {
        sel.setAttribute('data-ss-ready', '1');
        if (sel.options.length < 7) return;
        var box = document.createElement('input');
        box.type = 'search';
        box.className = 'ss-filter';
        box.placeholder = sel.getAttribute('data-searchable') || 'Search…';
        box.setAttribute('aria-label', box.placeholder);
        sel.parentNode.insertBefore(box, sel);
        box.addEventListener('input', function () {
          var q = box.value.toLowerCase().trim();
          var firstMatch = null;
          for (var k = 0; k < sel.options.length; k++) {
            var o = sel.options[k];
            if (!o.value) { o.hidden = false; continue; }
            var hit = !q || o.text.toLowerCase().indexOf(q) !== -1;
            o.hidden = !hit;
            if (hit && !firstMatch) firstMatch = o;
          }
          if (q && firstMatch && (sel.selectedIndex < 0 || sel.options[sel.selectedIndex].hidden || !sel.value)) {
            sel.value = firstMatch.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
        box.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sel.focus(); } });
      })(sels[i]);
    }
  }

  function initQuickAdd() {
    var modal = document.getElementById('quick-add-modal');
    if (!modal) return;
    function open() { modal.hidden = false; var f = modal.querySelector('input[name=name]'); if (f) setTimeout(function () { f.focus(); }, 60); }
    function close() { modal.hidden = true; }
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-qa-open]')) { e.preventDefault(); open(); }
      else if (e.target.closest && e.target.closest('[data-qa-close]')) { e.preventDefault(); close(); }
      else if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    // Suggest the back-end officer who already handles that country (admin / PMS lead only)
    var suggest = {};
    try { suggest = JSON.parse(modal.getAttribute('data-suggest') || '{}'); } catch (e) {}
    var country = modal.querySelector('[data-qa-country]');
    var be = modal.querySelector('[data-qa-backend]');
    var hint = modal.querySelector('[data-qa-hint]');
    if (country && be) {
      country.addEventListener('change', function () {
        var sid = suggest[country.value];
        if (sid && !be.value) {
          be.value = String(sid);
          if (hint) hint.textContent = 'Suggested: handles most ' + country.value + ' students';
        }
      });
    }
    if (!modal.hidden) open();
  }

  function initViewMemory() {
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('[data-remember-view]') : null;
      if (a) { try { document.cookie = 'ptp_students_view=' + a.getAttribute('data-remember-view') + '; path=/; max-age=31536000; SameSite=Lax'; } catch (er) {} }
    });
  }

  function initDropzones() {
    var zones = document.querySelectorAll('.dropzone');
    for (var i = 0; i < zones.length; i++) {
      (function (z) {
        var input = z.querySelector('input[type=file]');
        var label = z.querySelector('[data-dz-label]');
        if (!input) return;
        var original = label ? label.textContent : '';
        input.addEventListener('change', function () {
          var f = input.files && input.files[0];
          z.classList.toggle('has-file', !!f);
          if (label) label.textContent = f ? '✓ ' + f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB) — will upload on save' : original;
        });
        ['dragenter', 'dragover'].forEach(function (ev) { z.addEventListener(ev, function () { z.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { z.addEventListener(ev, function () { z.classList.remove('is-over'); }); });
      })(zones[i]);
    }
  }

  function initCopyButtons() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-copy-text]') : null;
      if (!b) return;
      e.preventDefault();
      var txt = b.getAttribute('data-copy-text') || '';
      var done = function () { var o = b.textContent; b.textContent = '✓ Copied'; setTimeout(function () { b.textContent = o; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done).catch(function () { window.prompt('Copy this text:', txt); });
      } else {
        window.prompt('Copy this text:', txt);
      }
    });
  }

  function initApp() {
    initDropzones();
    initCopyButtons();
    initSearchableSelects();
    initQuickAdd();
    initViewMemory();
    initDock();
    initUserMenu();
    initStaffIdleTimer();
    initNotificationBell();
    initRemarksModal();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
  } else {
    initApp();
  }
})();
