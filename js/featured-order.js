/* index.php "Random Picks" order memory.
   The server shuffles the featured grids with a fresh random seed on every
   load. When the underlying catalog is bigger than the displayed count
   (menu, others…), consecutive loads can render a DIFFERENT SUBSET of
   products — merely re-ordering the fresh cards cannot reproduce what the
   user last saw. So on Back/Forward the entire grid markup is restored
   verbatim from a sessionStorage snapshot: identical products, identical
   order. An explicit refresh keeps the freshly shuffled sets. */
(function () {
  'use strict';

  var GRID_IDS = ['featuredDishes', 'featuredMart', 'featuredOthers', 'featuredBeverages', 'featuredHotels', 'featuredMartStores', 'featuredOtherStores'];
  var STORE_KEY = 'lyaideu_featured_v4:' + location.pathname.replace(/\/+$/, '');
  var SNAP_TTL = 30 * 60 * 1000; /* half a browsing session */

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

  function loadSaved() {
    try {
      var s = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null');
      if (s && s.html && Date.now() - (s.ts || 0) < SNAP_TTL) return s;
    } catch (e) {}
    return null;
  }

  /* Snapshot the live grid markup so Back/Forward can put it back verbatim. */
  function saveSnapshot(grids) {
    var data = { ts: Date.now(), html: {} };
    for (var i = 0; i < grids.length; i++) data.html[grids[i].id] = grids[i].innerHTML;
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(data)); } catch (e) {}
  }

  /* A leftover ?fs=... from an older build would otherwise stay visible in the
     address bar — strip it so the URL stays clean. */
  function cleanUrl() {
    try {
      var u = new URL(window.location.href);
      if (u.searchParams.has('fs')) {
        u.searchParams.delete('fs');
        history.replaceState(history.state, '', u.toString());
      }
    } catch (e) {}
  }

  function init() {
    var grids = [];
    for (var i = 0; i < GRID_IDS.length; i++) {
      var g = document.getElementById(GRID_IDS[i]);
      if (g) grids.push(g);
    }
    if (!grids.length) return;

    cleanUrl();

    /* Back/Forward: put the last-seen grids back verbatim. The snapshot is
       intentionally NOT overwritten here, so repeated backs keep working. */
    if (navType() === 'back_forward') {
      var snap = loadSaved();
      if (snap) {
        for (var j = 0; j < grids.length; j++) {
          var html = snap.html[grids[j].id];
          if (typeof html === 'string' && html) grids[j].innerHTML = html;
        }
      }
      return;
    }

    /* Fresh visit or explicit refresh: remember this newly shuffled state. */
    saveSnapshot(grids);
  }

  try {
    var gridsExist = false;
    for (var gi = 0; gi < GRID_IDS.length; gi++) {
      if (document.getElementById(GRID_IDS[gi])) { gridsExist = true; break; }
    }
    if (gridsExist) {
      init(); /* script sits right after the featured markup, so run now */
    } else if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  } catch (e) {}
})();