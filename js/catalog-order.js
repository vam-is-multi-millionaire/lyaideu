/* catalog pages order + scroll memory (menu, mart, beverages, others, store)
   Mirrors index.php featured-order.js + scroll-memory.js
   - Fresh load / reload: shuffle once, save snapshot
   - Back/Forward: restore snapshot verbatim so order + scroll identical
   - Refresh: new random like index.php Random Picks
*/
(function () {
  'use strict';

  var GRID_IDS = ['menu-grid', 'mart-grid', 'beverages-grid', 'others-grid', 'hotels-grid', 'store-grid'];
  var STORE_KEY = 'lyaideu_catalog_v1:' + location.pathname.replace(/\/+$/, '');
  var SNAP_TTL = 30 * 60 * 1000;

  function navType() {
    try {
      var e = performance.getEntriesByType('navigation');
      if (e && e.length) return e[0].type;
    } catch (err) {}
    if (window.performance && window.performance.navigation) {
      var n = window.performance.navigation.type;
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
  function saveSnapshot(grids) {
    var data = { ts: Date.now(), html: {} };
    for (var i = 0; i < grids.length; i++) {
      var g = grids[i];
      if (!g) continue;
      var key = g.id || ('cls:' + i);
      // Only save if grid has content (avoid saving empty skeleton)
      if (g.innerHTML && g.innerHTML.trim().length > 100) {
        data.html[key] = g.innerHTML;
      }
    }
    // Only save if at least one grid has real content
    var hasContent = false;
    for (var k in data.html) { if (data.html[k] && data.html[k].length > 100) { hasContent = true; break; } }
    if (!hasContent) return;
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(data)); } catch (e) {}
  }
  function shouldShuffle(grid) {
    if (!grid || !grid.children.length) return false;
    if (grid.dataset.shuffled === '1') return false;
    // Respect price sort
    var sec = grid.closest('.section');
    var sel = sec ? sec.querySelector('.sort-select') : null;
    if (sel && sel.value !== 'default') return false;
    // Don't shuffle if grid is filtered to empty (no visible cards)
    var visible = 0;
    for (var i = 0; i < grid.children.length; i++) {
      if (grid.children[i].style.display !== 'none') visible++;
    }
    if (visible <= 1) return false;
    return true;
  }
  function shuffleChildren(grid) {
    if (!shouldShuffle(grid)) return false;
    var arr = Array.prototype.slice.call(grid.children);
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    // Use DocumentFragment for minimal reflow
    var frag = document.createDocumentFragment();
    for (var k = 0; k < arr.length; k++) frag.appendChild(arr[k]);
    grid.appendChild(frag);
    grid.dataset.shuffled = '1';
    return true;
  }
  function getGrids() {
    var out = [];
    for (var i = 0; i < GRID_IDS.length; i++) {
      var g = document.getElementById(GRID_IDS[i]);
      if (g) out.push(g);
    }
    var sg = document.querySelector('.store-grid');
    if (sg && !sg.id) { sg.id = 'store-grid'; if (out.indexOf(sg) === -1) out.push(sg); }
    else if (sg && out.indexOf(sg) === -1) out.push(sg);
    return out;
  }

  function init() {
    var grids = getGrids();
    if (!grids.length) return;

    var type = navType();

    if (type === 'back_forward') {
      var snap = loadSaved();
      if (snap && snap.html) {
        var restore = function () {
          var did = false;
          for (var i = 0; i < grids.length; i++) {
            var g = grids[i];
            var key = g.id || ('cls:' + i);
            var html = snap.html[key];
            if (typeof html === 'string' && html && html.length > 100) {
              // Only restore if grid is currently populated with different content
              // and has at least been rendered once (has children)
              if (g.children.length && g.innerHTML !== html) {
                // Preserve current filter/sort? No, restore verbatim to keep exact previous view
                g.innerHTML = html;
                g.dataset.shuffled = '1';
                did = true;
              } else if (!g.children.length && html.length > 100) {
                // Grid not yet populated by fetch, restore now (covers race where fetch hasn't run)
                // Wait: if we restore empty grid with html that has cards, we can set innerHTML
                g.innerHTML = html;
                g.dataset.shuffled = '1';
                did = true;
              }
            }
          }
          return did;
        };
        // Try immediately (server-rendered store detail may already have content)
        restore();
        // Observe future renders (api fetch)
        var obs = new MutationObserver(function () {
          // Use rAF to batch
          requestAnimationFrame(function () { restore(); });
        });
        grids.forEach(function (g) { obs.observe(g, { childList: true, subtree: false }); });
        // Fallbacks for slower fetch
        setTimeout(restore, 200);
        setTimeout(restore, 600);
        setTimeout(restore, 1200);
        return;
      }
      return;
    }

    // Fresh / reload / navigate: shuffle once after first real population, then save
    var pendingSave = false;
    var scheduleSave = function () {
      if (pendingSave) return;
      pendingSave = true;
      setTimeout(function () {
        pendingSave = false;
        saveSnapshot(getGrids());
      }, 150);
    };

    var mo = new MutationObserver(function (muts) {
      var did = false;
      grids = getGrids();
      grids.forEach(function (g) {
        if (g.children.length && g.dataset.shuffled !== '1') {
          if (shuffleChildren(g)) did = true;
        }
      });
      if (did) scheduleSave();
    });

    grids.forEach(function (g) {
      mo.observe(g, { childList: true });
      if (g.children.length && g.dataset.shuffled !== '1') {
        if (shuffleChildren(g)) scheduleSave();
      }
    });

    // Fallbacks for fetch that populates after observer setup
    setTimeout(function () {
      var did2 = false;
      getGrids().forEach(function (g) {
        if (g.children.length && g.dataset.shuffled !== '1') {
          if (shuffleChildren(g)) did2 = true;
        }
      });
      if (did2) saveSnapshot(getGrids());
    }, 700);
    setTimeout(function () {
      var grids2 = getGrids();
      var anyShuffled = grids2.some(function (g) { return g.dataset.shuffled === '1'; });
      if (anyShuffled) saveSnapshot(grids2);
    }, 1500);

    window.addEventListener('beforeunload', function () { saveSnapshot(getGrids()); });
    // Also save after price sort changes (user may have sorted, we want to save that state too)
    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('sort-select')) {
        setTimeout(function () { saveSnapshot(getGrids()); }, 100);
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
