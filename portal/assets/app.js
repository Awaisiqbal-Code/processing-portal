// Processing Team Portal — Client Controller & Theme Manager
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

  // Initialize state on DOM ready
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

  // Handle clicks on any theme toggle button
  document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
    if (!toggleBtn) return;
    e.preventDefault();
    var current = getEffectiveTheme();
    var next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
  });

  // Sync across multiple open tabs/windows
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY && (e.newValue === 'light' || e.newValue === 'dark')) {
      applyTheme(e.newValue, false);
    }
  });

  // Listen for OS theme changes if user has not manually locked a preference
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

  // Submit filter forms when a dropdown changes
  document.addEventListener('change', function (e) {
    if (e.target && e.target.matches && e.target.matches('select[data-autosubmit]')) {
      e.target.form.submit();
    }
  });
})();
