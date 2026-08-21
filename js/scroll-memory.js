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

  /* Pages that must always open at the very top when the user returns to them
     with the browser Back/Forward button (e.g. index.php after browsing a
     product) opt in with <script>window.LYADEU_BACK_TO_TOP=1;</script>. This
     disables the browser's own scroll restoration and forces the top. */
  var backToTop = false;
  try { backToTop = window.LYADEU_BACK_TO_TOP === 1; } catch (e) {}
  if (backToTop) {
    try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
  }

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

  /* After submitting a search on this page (?q=...) the user wants to see the
     results section (#search) — not the spot they happened to be scrolled to
     when they searched. Mobile only; desktop keeps its current behaviour. */
  function searchResultsEl() {
    var el = document.getElementById('search');
    if (!el) return null;
    var q = '';
    try { q = (new URLSearchParams(location.search).get('q') || '').trim(); } catch (e) { return null; }
    if (!q) return null;
    try { if (!window.matchMedia('(max-width: 960px)').matches) return null; } catch (e) { return null; }
    return el;
  }

  /* Land on the results, just below the sticky header. Retried briefly so
     late-loading images/fonts cannot leave us short of the target. */
  function scrollToSearch(el, tries) {
    var bar = document.querySelector('.topbar');
    var off = bar ? bar.offsetHeight : 0;
    doScroll(Math.max(0, el.getBoundingClientRect().top + currentY() - off - 10));
    if (--tries > 0) setTimeout(function () { scrollToSearch(el, tries); }, 150);
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

  /* Scroll instantly even though the site uses css `scroll-behavior:smooth`,
     otherwise restoring the position animates across the whole page. */
  function jumpTo(y) {
    var doc = document.documentElement;
    var prev = doc.style.scrollBehavior;
    doc.style.scrollBehavior = 'auto';
    window.scrollTo(0, y);
    doc.style.scrollBehavior = prev;
  }

  /* Some pages add the `lyai-restoring` class in <head> to hide the page until
     the saved scroll position has been applied, so reloading never flashes the
     top of the page first. Remove it as soon as we are done (or give up). */
  function reveal() {
    try { document.documentElement.classList.remove('lyai-restoring'); } catch (e) {}
  }

  function doScroll(y) {
    autoScrolling = true;
    jumpTo(y);
    setTimeout(function () { autoScrolling = false; }, 120);
  }

  function restore() {
    if (!restoring || target === null) return;
    var maxY = document.documentElement.scrollHeight || document.body.scrollHeight || 0;
    if (!maxY) return;
    var y = Math.min(target, maxY);
    if (Math.abs(currentY() - y) > 2) doScroll(y);
    reveal();
  }

  function stopRestoring() {
    restoring = false;
    reveal();
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
    if (e.persisted) {
      if (backToTop) {
        jumpTo(0);
        stopRestoring();
      } else {
        stopRestoring();
      }
    }
  });

  window.addEventListener('beforeunload', saveNow);
  document.addEventListener('submit', function () { markForRestore(); saveNow(); }, true);

  /* Decide whether this load is allowed to restore: reload, back/forward, or a
     form submit that returned to this same path. Everything else (typing a
     URL, clicking a nav link like "Home") must start at the top/anchor. */
  var type = navType();
  var searchEl = null;
  if (type === 'back_forward') {
    /* On Back/Forward the browser itself restores the exact scroll position
       (bfcache or the recorded offset), so manual restoration here only fights
       it and causes visible jumping/glitching while the page loads. */
    restoring = false;
    target = null;
    if (backToTop) {
      /* Opt-in pages must instead always land at the very top on Back/Forward. */
      jumpTo(0);
    }
  }
  if (type !== 'reload' && type !== 'back_forward') {
    if (takeRestoreFlag()) {
      /* Load comes right after a form submit on this same page. A submitted
         search lands on the results section instead of the old position. */
      searchEl = searchResultsEl();
      if (searchEl) restoring = false;
    } else {
      restoring = false;
    }
  }

  read();
  if (searchEl) {
    target = null; /* the search scroll owns this load */
    var goSearch = function () { scrollToSearch(searchEl, 5); };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', goSearch);
    } else {
      goSearch();
    }
  } else if (document.readyState === 'loading') {
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