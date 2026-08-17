/* index.php "Random Picks" order memory.
   The server shuffles the featured grids with a fresh random seed on every
   load, so no seed is kept in the URL (the address bar stays clean). The exact
   order a user saw is remembered client-side in sessionStorage and re-applied
   to the DOM whenever they return to this page with the browser Back/Forward,
   reproducing the order they last saw — even after navigating pages deep. */
(function () {
  'use strict';

  var GRID_IDS = ['featuredDishes', 'featuredMart', 'featuredOthers', 'featuredBeverages', 'featuredHotels', 'featuredMartStores', 'featuredOtherStores'];
  var STORE_KEY = 'lyaideu_featured_v3:' + location.pathname.replace(/\/+$/, '');

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

    var type = navType();
    var overwrite = true;

    /* On Back/Forward, restore the last-seen order from sessionStorage by
       re-arranging the freshly-shuffled cards. Don't clobber the snapshot if
       the rendered cards can't be re-arranged back to it. */
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