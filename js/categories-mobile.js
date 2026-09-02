/* ==========================================================
   LyaiDeu — Mobile Categories browse view
   Powers the app-like experience on phones/tablets only:
   main category cards -> full-screen browse view with a
   draggable left category rail and a right product grid.
   Desktop/PC is completely untouched (gated by media query).

   Product cards reuse the site's existing .dish-card / add-cart
   markup so the cart system, product details links and variant
   flows keep working exactly as before.
   ========================================================== */
(function () {
  'use strict';

  var MQ = window.matchMedia ? window.matchMedia('(max-width: 960px)') : null;
  function isMobile() { return MQ ? MQ.matches : window.innerWidth <= 960; }

  if (!window.LY_CATS || !window.LY_GROUPS) return;

  var TREES = window.LY_CATS;    // { type: [ {id,name,slug,parent_id,image,icon,depth} ] }
  var GROUPS = window.LY_GROUPS; // { type: {label,page,param,pool,custom} }

  /* Custom sections hold no products of their own — their cards are links
     into existing pools, resolved via api "links" (t = item source type). */
  var SRC_GROUPS = { dish: 'menu', mart: 'mart', other: 'other', beverage: 'beverage' };

  var view = document.getElementById('mcView');
  if (!view) return;

  var rail = document.getElementById('mcRail');
  var products = document.getElementById('mcProducts');
  var empty = document.getElementById('mcEmpty');
  var headLabel = document.getElementById('mcHeadLabel');
  var headSub = document.getElementById('mcHeadSub');
  var backBtn = document.getElementById('mcBack');

  var cache = null;
  var current = null;      // { type, slug }
  var syncTimer = null;
  var mcHistoryPushed = false;

  /* Legacy hash cleanup: older builds used #mc=… which could stick to
     unrelated URLs after history coalescing. Remove it once if present. */
  try {
    if (location.hash && location.hash.indexOf('#mc=') === 0) {
      history.replaceState(history.state, '', location.pathname + location.search);
      if (window.LYAI_TRAIL_UPDATE) window.LYAI_TRAIL_UPDATE(location.pathname + location.search);
    }
  } catch (e) {}

  /* ---- Browse-view state lives in sessionStorage, NOT the URL ----------
     The previous implementation kept this in a #mc=… URL hash managed
     with pushState/replaceState. Mobile browsers coalesce or skip
     hash-only history entries, which glued the hash onto completely
     unrelated URLs (e.g. the site root showing index.php with
     "#mc=menu:beverages"). The address bar is now never touched: the
     open category + product-list scroll are stored here and restored
     whenever the user comes back to this page. */
  var STATE_KEY = 'lyai_mc_view_v1';

  function saveState() {
    try {
      var main = view.querySelector('.mc-main');
      sessionStorage.setItem(STATE_KEY, JSON.stringify({
        type: current ? current.type : '',
        slug: current ? current.slug : '',
        main: main ? Math.round(main.scrollTop) : 0
      }));
    } catch (e) {}
  }

  function readState() {
    try {
      var s = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null');
      if (s && s.type && s.slug) return s;
    } catch (e) {}
    return null;
  }

  function clearState() {
    try { sessionStorage.removeItem(STATE_KEY); } catch (e) {}
  }

  /* The product grid renders asynchronously, so keep re-applying for a
     short window — otherwise late renders/images clamp the position. */
  function restoreMainScroll(y) {
    if (!y) return;
    var main = view.querySelector('.mc-main');
    if (!main) return;
    var tries = 0;
    (function apply() {
      if (!view.classList.contains('open')) return;
      main.scrollTop = y;
      if (++tries < 10) setTimeout(apply, 150);
    })();
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function slugify(s) {
    return String(s || '').replace(/&amp;/g, '&').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
  }

  function findCat(type, slug) {
    var list = TREES[type] || [];
    for (var i = 0; i < list.length; i++) if (list[i].slug === slug) return list[i];
    return null;
  }

  /* Walks up to the top-level (depth 0) ancestor of a category slug. */
  function rootSlug(type, slug) {
    var list = TREES[type] || [];
    var byId = {};
    list.forEach(function (c) { byId[c.id] = c; });
    var cur = findCat(type, slug);
    var hops = 0;
    while (cur && cur.parent_id !== null && hops < 20) {
      cur = byId[cur.parent_id] || null;
      hops++;
    }
    return cur ? cur.slug : slug;
  }

  function catThumb(cat) {
    var icon = (cat && cat.icon) || 'fa-tags';
    var cls = /^(fa-solid|fa-regular|fa-brands)\s+/.test(icon) ? icon : 'fa-solid ' + icon;
    return cat && cat.image
      ? '<img src="' + esc(cat.image) + '" alt="' + esc(cat.name) + '" loading="lazy">'
      : '<i class="' + cls + '"></i>';
  }

  function productUrl(type, slug, cats) {
    cats = (cats || []).map(function (x) { return String(x).trim(); }).filter(Boolean);
    var p = cats.length ? cats.map(encodeURIComponent).join('/') + '/' : '';
    return GROUPS[type].page + '/' + p + encodeURIComponent(slug || 'item');
  }

  /* ---- Catalog loading / live refresh ---- */
  function fetchCatalog() {
    if (cache) return Promise.resolve(cache);
    return fetch('api?v=' + Date.now()).then(function (r) { return r.json(); })
      .then(function (d) { cache = d; return d; })
      .catch(function () { return null; });
  }
  function applyCatalogImages(d) {
    var cats = d.categories || [];
    var img = {};
    cats.forEach(function (c) { img[c.id] = { image: c.image || '', icon: c.icon || '' }; });
    Object.keys(TREES).forEach(function (t) {
      TREES[t].forEach(function (c) {
        var m = img[c.id];
        if (m) { c.image = m.image; c.icon = m.icon; }
      });
    });
  }
  function poolItems(type) { return (cache && cache[GROUPS[type].pool]) || []; }

  /* Products linked (admin) into a custom section's category, including the
     whole subtree — mirrors how native pools match via the cats path. */
  function linkedRows(type, slug) {
    if (!cache) return null;
    var cat = findCat(type, slug);
    if (!cat) return [];
    var list = TREES[type] || [];
    var ids = {};
    var frontier = [cat.id];
    while (frontier.length) {
      var curId = frontier.shift();
      if (ids[curId]) continue;
      ids[curId] = true;
      list.forEach(function (c) {
        if (c.parent_id !== null && c.parent_id === curId) frontier.push(c.id);
      });
    }
    var out = [];
    var seen = {};
    (cache.links || []).forEach(function (l) {
      if (!ids[l.c]) return;
      var src = SRC_GROUPS[l.t];
      if (!src || !GROUPS[src] || !GROUPS[src].pool) return;
      var key = l.t + ':' + l.id;
      if (seen[key]) return;
      var pool = cache[GROUPS[src].pool] || [];
      for (var i = 0; i < pool.length; i++) {
        if (Number(pool[i].id) === Number(l.id)) {
          seen[key] = true;
          out.push({ p: pool[i], src: src });
          break;
        }
      }
    });
    return out;
  }
  function startSync() {
    stopSync();
    syncTimer = setInterval(function () {
      if (!view.classList.contains('open')) { stopSync(); return; }
      fetch('api?v=' + Date.now()).then(function (r) { return r.json(); }).then(function (d) {
        if (!d) return;
        cache = d;
        applyCatalogImages(d);
        if (view.classList.contains('open') && current) {
          renderRail(current.type, current.scope, current.slug);
          renderProducts(current.type, current.slug);
        }
      }).catch(function () {});
    }, 20000);
  }
  function stopSync() { if (syncTimer) { clearInterval(syncTimer); syncTimer = null; } }

  function setBottomNavHidden(hidden) {
    try {
      var bn = document.getElementById('bottomNav');
      if (!bn) return;
      if (hidden) {
        bn.setAttribute('aria-hidden', 'true');
        bn.style.display = 'none';
        bn.style.visibility = 'hidden';
        bn.style.pointerEvents = 'none';
      } else {
        bn.removeAttribute('aria-hidden');
        bn.style.display = '';
        bn.style.visibility = '';
        bn.style.pointerEvents = '';
      }
    } catch (e) {}
  }

  /* If #bottomNav is injected after mc-view is already open (script.js
     injects it on DOMContentLoaded), the CSS rule body.mc-open #bottomNav
     already hides it, but this observer makes the inline-hide immediate
     even for that race. */
  try {
    var bnObserver = new MutationObserver(function () {
      if (view.classList.contains('open') && isMobile()) setBottomNavHidden(true);
    });
    bnObserver.observe(document.body, { childList: true });
  } catch (e) {}

  /* ---- Left category rail: the tapped parent + its subcategories ---- */
  function renderRail(type, scopeSlug, activeSlug) {
    var list = TREES[type] || [];
    var scope = findCat(type, scopeSlug);
    var items = [];
    if (scope) {
      items.push(scope);
      list.forEach(function (c) { if (c.parent_id !== null && c.parent_id === scope.id) items.push(c); });
    } else {
      items = list.filter(function (c) { return c.depth === 0; });
    }
    var html = items.map(function (cat) {
      var cls = 'mc-rail-item' + (cat.slug === scopeSlug ? ' is-scope' : '') + (cat.slug === activeSlug ? ' active' : '');
      return '<button type="button" class="' + cls + '" data-slug="' + esc(cat.slug) + '">' +
        '<span class="mc-rail-thumb">' + catThumb(cat) + '</span>' +
        '<span class="mc-rail-name">' + esc(cat.name) + '</span>' +
      '</button>';
    }).join('');
    rail.innerHTML = html;
  }

  /* ---- Right product grid ---- */
  function defVar(p){var vs=(p&&p.variants)||[];if(!vs.length)return null;for(var i=0;i<vs.length;i++){if(vs[i]&&vs[i].is_default)return vs[i]}return vs[0]}

  /* Discount deal helpers (same math as script.js; reads discount_percent
     with a .discount fallback so it matches the api.php field name). */
  function dealOf(it,base){
    if(base==null)base=(it&&Number(it.price))||0;
    base=Number(base)||0;
    var rawPct=it?(it.discount!=null?it.discount:it.discount_percent):0;
    var pct=Math.min(95,Math.max(0,Number(rawPct)||0));
    return pct>0&&base>0?{pct:pct,now:Math.round(base*(100-pct)/100),was:base}:{pct:0,now:base,was:base};
  }
  function dealTag(deal){return deal&&deal.pct>0?'<span class="deal-badge deal-badge-inline">-'+deal.pct+'%</span>':''}

  /* Skeleton placeholders that mirror the real .dish-card layout (square
     art, title lines, price + button row) so the grid keeps its exact size
     while the catalog is still loading — no spinner, no layout jump. */
  function skeletonCards() {
    var card = '<article class="mc-skel-card" aria-hidden="true">' +
      '<div class="mc-skel-art skel-shimmer"></div>' +
      '<div class="mc-skel-body">' +
        '<span class="mc-skel-line skel-shimmer" style="width:82%"></span>' +
        '<span class="mc-skel-line skel-shimmer" style="width:52%"></span>' +
        '<div class="mc-skel-foot">' +
          '<span class="mc-skel-line skel-shimmer" style="width:36%"></span>' +
          '<span class="mc-skel-pill skel-shimmer"></span>' +
        '</div>' +
      '</div>' +
    '</article>';
    var out = '';
    for (var i = 0; i < 6; i++) out += card;
    return out;
  }

  function cardHTML(p, type) {
    var id = Number(p.id), name = esc(p.name), tag = esc(p.tag);
    var img = esc(p.img || '');
    var dv = defVar(p);
    var base = dv ? (Number(dv.price) || 0) : (Number(p.price) || 0);
    var deal = dealOf(p, base);
    var unit = esc(dv && dv.label ? dv.label : (p.unit || ''));
    var hotel = esc(p.hotel || '');
    var cats = (p.cats && p.cats.length) ? p.cats.map(esc) : [esc(p.cat || '')];
    var slug = p.slug || slugify(p.name);
    var url = productUrl(type, slug, (p.cats && p.cats.length) ? p.cats : [p.cat]);
    /* Products without their own image get a neutral icon — never the
       category's image. */
    var art = img
      ? '<img src="' + img + '" alt="' + name + '" loading="lazy">'
      : '<span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>';
    var dataType = type === 'menu' ? 'dish' : type;
    var addBtn = '<button class="btn-order add-cart" data-id="' + id + '" data-type="' + dataType +
      '" data-name="' + name + '" data-price="' + deal.now + '" data-unit="' + unit +
      '" data-hotel="' + hotel + '"' + (p.has_variants ? ' data-has-variants="1"' : '') +
      ' type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button>';
    return '<article class="dish-card reveal visible" data-id="' + id + '" data-slug="' + esc(slug) +
      '" data-cats="' + cats.join(',') + '" data-url="' + esc(url) + '">' +
      '<div class="dish-art mart-art">' + art + (tag ? '<span class="dish-tag">' + tag + '</span>' : '') + '</div>' +
      '<div class="dish-body"><div class="dish-top"><h3>' + name + '</h3></div>' +
      (hotel ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' + hotel + '</p>' : '') +
      '<div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> ' + deal.now +
      '</span>' + dealTag(deal) + addBtn + '</div></div>' +
    '</article>';
  }

  function renderProducts(type, slug) {
    var cat = findCat(type, slug);
    var group = GROUPS[type];
    var isCustom = !!(group && group.custom);

    var rows = null;
    if (isCustom) {
      rows = linkedRows(type, slug);
    } else {
      var items = cache ? poolItems(type) : null;
      if (items === null) {
        rows = null;
      } else {
        var list = items.filter(function (p) {
          var cats = (p.cats && p.cats.length) ? p.cats : [p.cat || ''];
          return cats.indexOf(slug) !== -1;
        });
        rows = list.map(function (p) { return { p: p, src: type }; });
      }
    }

    if (rows === null) {
      products.style.display = '';
      products.hidden = false;
      empty.hidden = true;
      products.classList.add('is-loading');
      products.setAttribute('aria-busy', 'true');
      products.innerHTML = skeletonCards();
      return;
    }
    products.classList.remove('is-loading');
    products.removeAttribute('aria-busy');
    if (rows.length) {
      products.style.display = '';
      products.hidden = false;
      empty.hidden = true;
      products.innerHTML = rows.map(function (r) { return cardHTML(r.p, r.src); }).join('');
    } else {
      /* No products in this category — show a short friendly message. */
      products.innerHTML = '';
      products.style.display = 'none';
      empty.hidden = false;
      empty.innerHTML = '<span class="mc-empty-ico"><i class="fa-solid fa-box-open"></i></span>' +
        'No items in <b>' + esc(cat ? cat.name : slug) + '</b> yet.<br>' +
        '<a href="' + esc(GROUPS[type].page) + '">Browse all ' + esc(GROUPS[type].label) + '</a>';
    }
  }

  /* ---- Open / close (with history handling) ---- */
  function doCloseView() {
    if (!view.classList.contains('open')) return false;
    view.classList.remove('open');
    view.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mc-open');
    setBottomNavHidden(false);
    try { if (document.activeElement === backBtn) backBtn.blur(); } catch (e) {}
    // Ensure button never stays stuck in :focus/:active visual state after close (esp. on mobile)
    try { backBtn.style.transform = ''; } catch (e) {}
    stopSync();
    clearState();
    return true;
  }

  function closeView() {
    if (!view.classList.contains('open')) return;
    /* If we pushed a history entry when opening, the UI back button
       should pop that entry instead of directly hiding the overlay.
       That keeps the browser Back button and the in-page back button
       perfectly in sync: one tap → one history step → front page. */
    if (mcHistoryPushed) {
      try {
        if (history.state && history.state.lyMc) {
          mcHistoryPushed = false;
          history.back();
          return;
        }
      } catch (e) {}
    }
    doCloseView();
    mcHistoryPushed = false;
    /* If history somehow still holds our marker (e.g. direct close
       without back), clean it so future Back goes to the real previous
       page instead of needing two presses. */
    try {
      if (history.state && history.state.lyMc) {
        history.replaceState(null, '', location.pathname + location.search);
        if (window.LYAI_TRAIL_UPDATE) window.LYAI_TRAIL_UPDATE(location.pathname + location.search);
      }
    } catch (e) {}
  }

  function openView(type, slug, opts) {
    opts = opts || {};
    var scope = rootSlug(type, slug);
    current = { type: type, slug: slug, scope: scope };
    var cat = findCat(type, slug);
    headLabel.textContent = GROUPS[type].label;
    headSub.textContent = cat ? cat.name : slug;
    renderRail(type, scope, slug);
    renderProducts(type, slug);
    view.classList.add('open');
    view.setAttribute('aria-hidden', 'false');
    document.body.classList.add('mc-open');
    setBottomNavHidden(true);
    startSync();
    saveState();
    /* Push a history entry so the mobile OS / browser Back button
       closes the overlay instead of leaving categories.php entirely.
       The URL itself is unchanged (same pathname+search), so no hash
       leaks to other pages. */
    if (isMobile()) {
      try {
        var hasMarker = history.state && history.state.lyMc;
        if (!hasMarker && !mcHistoryPushed) {
          history.pushState({ lyMc: 1 }, '', location.href);
          mcHistoryPushed = true;
          if (window.LYAI_TRAIL_UPDATE) window.LYAI_TRAIL_UPDATE(location.pathname + location.search + location.hash);
        } else if (hasMarker) {
          mcHistoryPushed = true;
        }
      } catch (e) {}
    }
    // Focus only for fresh tap-open (keyboard a11y). On restore from product Back
    // the button must NOT stay focused or its focus shadow sticks on mobile.
    if (opts.skipFocus) {
      try { backBtn.blur(); } catch (e) {}
    } else {
      try { backBtn.focus({ preventScroll: true }); } catch (e) {}
      // On touch devices focus sticks + shows shadow/outline - clear it shortly after
      try {
        setTimeout(function(){ try{ if(document.activeElement===backBtn) backBtn.blur(); }catch(e2){} }, 600);
      } catch (e) {}
    }
    fetchCatalog().then(function (d) {
      if (!d) return;
      applyCatalogImages(d);
      if (view.classList.contains('open') && current && current.type === type) {
        renderRail(current.type, current.scope, current.slug);
        renderProducts(current.type, current.slug);
      }
    });
  }

  function selectCat(slug) {
    if (!current || slug === current.slug) return;
    current.slug = slug;
    var cat = findCat(current.type, slug);
    headSub.textContent = cat ? cat.name : slug;
    renderRail(current.type, current.scope, slug);
    renderProducts(current.type, slug);
    saveState();
  }

  /* Browser / OS Back while the overlay is open must return to the
     categories grid, not to the previous site page. */
  window.addEventListener('popstate', function () {
    if (!isMobile()) return;
    if (view.classList.contains('open')) {
      doCloseView();
      mcHistoryPushed = false;
      try { backBtn.blur(); } catch (e) {}
    } else {
      // Overlay already closed but button may still be focused from before - clear sticky focus shadow
      try { if (document.activeElement === backBtn) backBtn.blur(); } catch (e) {}
    }
  });

  /* bfcache restore can bring back a page with the overlay already in
     the DOM but with a stale flag. Sync the flag to the actual history. */
  window.addEventListener('pageshow', function () {
    try {
      if (view.classList.contains('open') && history.state && history.state.lyMc) {
        mcHistoryPushed = true;
        // Coming back from product via bfcache: ensure back btn not stuck focused with shadow
        try { if (document.activeElement === backBtn) backBtn.blur(); } catch (e) {}
        // Also clear any stuck :active transform
        try { backBtn.style.transform = ''; } catch (e) {}
      }
      if (!view.classList.contains('open') && history.state && history.state.lyMc && !isMobile()) {
        history.replaceState(null, '', location.pathname + location.search);
      }
    } catch (e) {}
  });

  /* ---- Wire up ---- */
  document.addEventListener('click', function (e) {
    if (!isMobile()) return;
    var t = e.target.closest('[data-mc-open]');
    if (!t) return;
    var type = t.getAttribute('data-mc-open');
    var slug = t.getAttribute('data-mc-slug');
    if (!slug || !GROUPS[type]) return;
    e.preventDefault();
    openView(type, slug);
  });

  /* Bottom-nav "Categories" tab: while the browse overlay is open, tapping
     it must return to the categories grid instantly. Letting the link
     navigate would reload the page — and the saved-state restore below
     would just reopen the overlay, so the tap looked like it did nothing. */
  document.addEventListener('click', function (e) {
    if (!isMobile()) return;
    var tab = e.target.closest('.bottom-nav [data-nav="categories"]');
    if (!tab || !view.classList.contains('open')) return;
    e.preventDefault();
    closeView();
    window.scrollTo(0, 0);
  }, true);

  rail.addEventListener('click', function (e) {
    var btn = e.target.closest('.mc-rail-item');
    if (btn && btn.dataset.slug) selectCat(btn.dataset.slug);
  });

  products.addEventListener('click', function (e) {
    if (e.target.closest('.add-cart')) return; /* handled by script.js cart flow */
    var card = e.target.closest('.dish-card');
    if (!card) return;
    var url = card.dataset.url;
    if (url) {
      saveState();
      window.location.href = url;
    }
  });

  backBtn.addEventListener('click', function () {
    closeView();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && view.classList.contains('open')) closeView();
  });

  function onViewportChange() {
    if (!isMobile() && view.classList.contains('open')) {
      doCloseView();
      mcHistoryPushed = false;
      try {
        if (history.state && history.state.lyMc) {
          history.replaceState(null, '', location.pathname + location.search);
          if (window.LYAI_TRAIL_UPDATE) window.LYAI_TRAIL_UPDATE(location.pathname + location.search);
        }
      } catch (e) {}
    }
  }
  if (MQ && MQ.addEventListener) MQ.addEventListener('change', onViewportChange);
  else window.addEventListener('resize', onViewportChange);

  /* Coming back to this page — browser Back from a product, or a reload
     while the view was open — reopens the browse view exactly where the
     user left it, straight from the saved state. No URL hashes involved. */
  if (isMobile()) {
    var saved = readState();
    if (saved && GROUPS[saved.type] && findCat(saved.type, saved.slug)) {
      openView(saved.type, saved.slug, { skipFocus: true });
      restoreMainScroll(Number(saved.main) || 0);
      // Safety: ensure no stuck focus shadow right after restore (back from product)
      try { setTimeout(function(){ try{ backBtn.blur(); backBtn.style.transform=''; }catch(e){} }, 50); } catch (e) {}
    } else {
      /* If history still claims we are in the overlay but storage was
         cleared, clean the stray marker so Back goes to the real page. */
      try {
        if (history.state && history.state.lyMc && !view.classList.contains('open')) {
          history.replaceState(null, '', location.pathname + location.search);
          if (window.LYAI_TRAIL_UPDATE) window.LYAI_TRAIL_UPDATE(location.pathname + location.search);
        }
      } catch (e) {}
    }
  }
})();
