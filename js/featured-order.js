/* index.php "Random Picks" order memory.
   Fresh visits and manual refreshes get a brand-new random order. The order is
   made deterministic on the server via a seed carried in the URL (?fs=...), so
   returning to this page with the browser Back/Forward reproduces the exact
   order the user last saw — even after navigating several pages deep. A
   sessionStorage snapshot of the rendered order is kept as a fallback for the
   rare cases where the seed isn't present in the URL. */
(function () {
  'use strict';

  var GRID_IDS = ['featuredDishes', 'featuredMart', 'featuredOthers', 'featuredHotels', 'featuredMartStores', 'featuredOtherStores'];
  var STORE_KEY = 'lyaideu_featured_v2:' + location.pathname.replace(/\/+$/, '');

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

  function currentFs() {
    var m = /[?&]fs=(\d+)/.exec(location.search);
    return m ? m[1] : null;
  }

  function makeUrl(seed) {
    var u = new URL(window.location.href);
    u.searchParams.set('fs', seed);
    return u.toString();
  }

  function cardKey(card) {
    var id = card.getAttribute('data-id');
    if (id) return (card.getAttribute('data-type') || 'item') + ':' + id;
    var storeUrl = card.getAttribute('data-store-url');
    if (storeUrl) return 'store:' + storeUrl;
    return null;
  }

  function readOrder(grid) {
    var keys = [];
    for (var i = 0; i < grid.children.length; i++) {
      var k = cardKey(grid.children[i]);
      if (k) keys.push(k);
    }
    return keys;
  }

  function loadSaved() {
    try { return JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null'); } catch (e) { return null; }
  }

  function saveOrders(grids) {
    var data = {};
    for (var i = 0; i < grids.length; i++) data[grids[i].id] = readOrder(grids[i]);
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(data)); } catch (e) {}
  }

  /* Re-arrange the grid's children into the saved order. Returns false if the
     current cards no longer match the saved list (e.g. items changed). */
  function applyOrder(grid, savedKeys) {
    var current = Array.prototype.slice.call(grid.children);
    var currentKeys = readOrder(grid);
    if (!savedKeys || currentKeys.length !== savedKeys.length) return false;
    var byKey = {};
    var ok = true;
    for (var i = 0; i < currentKeys.length; i++) {
      if (!currentKeys[i]) { ok = false; break; }
      byKey[currentKeys[i]] = current[i];
    }
    if (!ok) return false;
    for (var j = 0; j < savedKeys.length; j++) {
      if (!byKey[savedKeys[j]]) { ok = false; break; }
    }
    if (!ok) return false;
    for (var k = 0; k < savedKeys.length; k++) grid.appendChild(byKey[savedKeys[k]]);
    return true;
  }

  function init() {
    var grids = [];
    for (var i = 0; i < GRID_IDS.length; i++) {
      var g = document.getElementById(GRID_IDS[i]);
      if (g) grids.push(g);
    }
    if (!grids.length) return;

    var type = navType();
    var seed = (typeof window.LYADEU_FS !== 'undefined' && window.LYADEU_FS !== null && window.LYADEU_FS !== '' && window.LYADEU_FS !== 0 && window.LYADEU_FS !== '0')
      ? String(window.LYADEU_FS)
      : '';

    if (seed) {
      if (currentFs() === null) {
        /* Fresh load — the server already rendered this seed's order, so just
           stamp it into the URL (no reload) and Back/Forward reproduces it. */
        try { history.replaceState(history.state, '', makeUrl(seed)); } catch (e) {}
      } else if (type === 'reload') {
        /* Manual refresh of a seeded URL → bounce to a brand-new random order.
           location.replace() swaps the current history entry, no extra entry. */
        var fresh = Math.floor(Math.random() * 2147483647) + 1;
        try { location.replace(makeUrl(fresh)); } catch (e) {}
        return;
      }
    }

    /* Fallback: on Back/Forward, restore the last-rendered order from
       sessionStorage when the seed isn't in play. Don't clobber the snapshot
       if the freshly rendered cards can't be re-arranged back to it. */
    var overwrite = true;
    if (type === 'back_forward') {
      var saved = loadSaved();
      if (saved) {
        for (var j = 0; j < grids.length; j++) {
          var keys = saved[grids[j].id];
          if (keys && keys.length && grids[j].children.length && !applyOrder(grids[j], keys)) {
            overwrite = false;
          }
        }
      }
    }
    if (overwrite) saveOrders(grids);
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