/* LyaiDeu scroll memory: keeps the page where the user left it after saving,
   deleting, reloading or navigating. Scroll position is stored per page path
   in sessionStorage and restored on the next load of that page.

   This script must never interfere with the user: it stops restoring the
   moment the user scrolls on their own, and it does nothing on back/forward
   navigation where the browser already restores the exact position. */
(function () {
  'use strict';

  var KEY = 'lyaideu_scroll_v1:' + location.pathname;
  var target = null;
  var restoring = true;
  var autoScrolling = false;
  var timer = null;

  function read() {
    var raw = null;
    try { raw = sessionStorage.getItem(KEY); } catch (e) {}
    var n = parseInt(raw, 10);
    target = (isFinite(n) && n > 0) ? n : null;
  }

  function currentY() {
    return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
  }

  function doScroll(y) {
    autoScrolling = true;
    window.scrollTo(0, y);
    setTimeout(function () { autoScrolling = false; }, 120);
  }

  function restore() {
    if (!restoring || target === null) return;
    var maxY = document.documentElement.scrollHeight || document.body.scrollHeight || 0;
    if (!maxY) return;
    var y = Math.min(target, maxY);
    if (Math.abs(currentY() - y) > 2) doScroll(y);
  }

  function stopRestoring() {
    restoring = false;
    if (timer) { clearInterval(timer); timer = null; }
  }

  function saveNow() {
    try { sessionStorage.setItem(KEY, String(currentY())); } catch (e) {}
  }

  /* A scroll that we did not cause means the user is in control - back off. */
  window.addEventListener('scroll', function () {
    if (!autoScrolling) stopRestoring();
  }, { passive: true });

  /* On back/forward the browser already keeps the exact scroll position. */
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) stopRestoring();
  });

  window.addEventListener('beforeunload', saveNow);
  document.addEventListener('submit', saveNow, true);

  read();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore);
  } else {
    restore();
  }
  window.addEventListener('load', function () {
    restore();
    stopRestoring();
  });

  /* Short retry window only (so late-loading images can extend the page
     height); short enough that it can never feel like lag. */
  var tries = 0;
  timer = setInterval(function () {
    if (++tries >= 5) { stopRestoring(); return; }
    restore();
  }, 150);
})();