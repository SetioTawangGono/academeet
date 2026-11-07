(function () {
  'use strict';
  var STORAGE_KEY = 'academeet-theme';
  var root = document.documentElement;

  function getStored() {
    try { var v = localStorage.getItem(STORAGE_KEY); return (v === 'light' || v === 'dark') ? v : null; }
    catch (e) { return null; }
  }
  function currentTheme() { return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; }

  function applyTheme(theme, persist) {
    root.setAttribute('data-bs-theme', theme);
    if (persist) { try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {} }
    updateControls(theme);
  }

  function updateControls(theme) {
    var toggles = document.querySelectorAll('.js-theme-toggle');
    for (var i = 0; i < toggles.length; i++) {
      var btn = toggles[i];
      var nextIsDark = theme !== 'dark';
      btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
      btn.setAttribute('aria-label', nextIsDark ? 'Ganti ke mode gelap' : 'Ganti ke mode terang');
    }
  }

  // Klik toggle
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.js-theme-toggle') : null;
    if (!btn) return;
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
  });

  // Ikuti preferensi OS selama user belum memilih manual
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function (e) { if (!getStored()) applyTheme(e.matches ? 'dark' : 'light', false); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { updateControls(currentTheme()); });
  } else {
    updateControls(currentTheme());
  }
})();