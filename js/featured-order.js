/* index.php "Random Picks" order memory.
   A fresh visit or a manual refresh gets a brand-new random order (rendered by
   PHP). Returning to this page via the browser Back/Forward keeps the exact
   order the user last saw: the freshly rendered cards are re-arranged back to
   the order saved in sessionStorage. */
(function () {
  'use strict';

  var KEY = 'lyaideu_featured_v1:' + location.pathname + location.search;
  var GRID_IDS = ['featuredDishes', 'featuredMart', 'featuredOthers', 'featuredHotels', 'featuredMartStores', 'featuredOtherStores'];

  function navType() {
    try {
      var entries = performance.getEntriesByType('navigation');
      if (entries && entries.length) return entries[0].type; /* navigate | reload | back_forward | prerender */
    } catch (e) {}
    if (window.performance && window.performance.navigation) {
      var n = window.performance.navigation.type; /* 0 navigate, 1 reload, 2 back_forward */
      return n === 1 ? 'reload' : (n === 2 ? 'back_forward' : 'navigate');
    }
    return null;
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
    try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
  }

  function saveOrders(grids) {
    var data = {};
    for (var i = 0; i < grids.length; i++) data[grids[i].id] = readOrder(grids[i]);
    try { sessionStorage.setItem(KEY, JSON.stringify(data)); } catch (e) {}
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
    var saved = loadSaved();
    var backForward = navType() === 'back_forward';
    for (var j = 0; j < grids.length; j++) {
      var keys = (saved && saved[grids[j].id]) || [];
      if (backForward && keys.length && grids[j].children.length) {
        applyOrder(grids[j], keys);
      }
    }
    saveOrders(grids);
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