/* LyaiDeu scroll memory: keeps the page where the user left it after saving,
   deleting, reloading or submitting a form on that same page. Scroll position
   is stored per page path in sessionStorage and restored on the next load.

   A plain link to a different page (e.g. clicking "Home" from the menu) must
   land at the top — or at the clicked anchor — so the saved position is only
   restored on reload, on back/forward, or right after a form submission that
   returns to the same path.

   This script must never interfere with the user: it stops restoring the
   moment the user scrolls on their own, and it does nothing on back/forward
   navigation where the browser already restores the exact position. */
(function () {
  'use strict';

  var KEY = 'lyaideu_scroll_v1:' + location.pathname;
  var FLAG_KEY = 'lyaideu_scroll_do_restore:1';
  var target = null;
  var restoring = true;
  var autoScrolling = false;
  var timer = null;

  function navType() {
    try {
      var entries = performance.getEntriesByType('navigation');
      if (entries && entries.length) return entries[0].type; /* navigate | reload | back_forward | prerender */
    } catch (e) {}
    if (window.performance && window.performance.navigation) {
      var n = window.performance.navigation.type; /* 0 navigate, 1 reload, 2 back_forward */
      return n === 1 ? 'reload' : (n === 2 ? 'back_forward' : 'navigate');
    }
    return 'navigate';
  }

  /* A form submission (save/edit/delete) that ends up back on this same page
     should keep the place. Record the target path so only that page restores;
     a plain nav link sets no flag, so it can never cause a restore. */
  function markForRestore() {
    try { sessionStorage.setItem(FLAG_KEY, location.pathname); } catch (e) {}
  }

  function takeRestoreFlag() {
    var savedPath = null;
    try {
      savedPath = sessionStorage.getItem(FLAG_KEY);
      sessionStorage.removeItem(FLAG_KEY);
    } catch (e) {}
    return savedPath === location.pathname;
  }

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
  document.addEventListener('submit', function () { markForRestore(); saveNow(); }, true);

  /* Decide whether this load is allowed to restore: reload, back/forward, or a
     form submit that returned to this same path. Everything else (typing a
     URL, clicking a nav link like "Home") must start at the top/anchor. */
  var type = navType();
  if (type !== 'reload' && type !== 'back_forward' && !takeRestoreFlag()) {
    restoring = false;
  }

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