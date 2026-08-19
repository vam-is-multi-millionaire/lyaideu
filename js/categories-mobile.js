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
  var GROUPS = window.LY_GROUPS; // { type: {label,page,param,pool} }

  var view = document.getElementById('mcView');
  if (!view) return;

  var rail = document.getElementById('mcRail');
  var products = document.getElementById('mcProducts');
  var empty = document.getElementById('mcEmpty');
  var headLabel = document.getElementById('mcHeadLabel');
  var headSub = document.getElementById('mcHeadSub');
  var viewAll = document.getElementById('mcViewAll');
  var backBtn = document.getElementById('mcBack');

  var cache = null;
  var current = null;      // { type, slug }
  var canGoBack = false;
  var syncTimer = null;

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function slugify(s) {
    return String(s || '').replace(/&amp;/g, '&').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
  }

  function hashFor(type, slug) { return '#mc=' + type + ':' + slug; }
  function parseHash() {
    var h = location.hash || '';
    if (h.indexOf('#mc=') !== 0) return null;
    var body = h.slice(4);
    var i = body.indexOf(':');
    if (i < 0) return null;
    return { type: decodeURIComponent(body.slice(0, i)), slug: decodeURIComponent(body.slice(i + 1)) };
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
    html += '<a class="mc-rail-all" href="' + esc(GROUPS[type].page) + '">All ' + esc(GROUPS[type].label) + '</a>';
    rail.innerHTML = html;
  }

  /* ---- Right product grid ---- */
  function cardHTML(p, type, fallbackCat) {
    var id = Number(p.id), name = esc(p.name), tag = esc(p.tag);
    var img = esc(p.img || '');
    var unit = esc(p.unit || '');
    var hotel = esc(p.hotel || '');
    var cats = (p.cats && p.cats.length) ? p.cats.map(esc) : [esc(p.cat || '')];
    var slug = p.slug || slugify(p.name);
    var url = productUrl(type, slug, (p.cats && p.cats.length) ? p.cats : [p.cat]);
    var art;
    if (img) {
      art = '<img src="' + img + '" alt="' + name + '" loading="lazy">';
    } else if (fallbackCat) {
      art = catThumb(fallbackCat);
    } else {
      art = '<span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>';
    }
    var dataType = type === 'menu' ? 'dish' : type;
    var addBtn = '<button class="btn-order add-cart" data-id="' + id + '" data-type="' + dataType +
      '" data-name="' + name + '" data-price="' + (Number(p.price) || 0) + '" data-unit="' + unit +
      '" data-hotel="' + hotel + '"' + (p.has_variants ? ' data-has-variants="1"' : '') +
      ' type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button>';
    return '<article class="dish-card reveal visible" data-id="' + id + '" data-slug="' + esc(slug) +
      '" data-cats="' + cats.join(',') + '" data-url="' + esc(url) + '">' +
      '<div class="dish-art mart-art">' + art + (tag ? '<span class="dish-tag">' + tag + '</span>' : '') + '</div>' +
      '<div class="dish-body"><div class="dish-top"><h3>' + name + '</h3></div>' +
      '<div class="dish-foot"><span class="price"><small>Rs.</small> ' + (Number(p.price) || 0) +
      (unit ? ' <span class="unit">/ ' + unit + '</span>' : '') + '</span>' + addBtn + '</div></div>' +
    '</article>';
  }

  function renderProducts(type, slug) {
    var cat = findCat(type, slug);
    var items = cache ? poolItems(type) : null;
    if (!items) {
      products.hidden = false;
      empty.hidden = true;
      products.innerHTML = '<div class="mc-empty"><span class="mc-empty-ico"><i class="fa-solid fa-spinner fa-spin"></i></span>Loading ' + esc(cat ? cat.name : slug) + '…</div>';
      return;
    }
    var list = items.filter(function (p) {
      var cats = (p.cats && p.cats.length) ? p.cats : [p.cat || ''];
      return cats.indexOf(slug) !== -1;
    });
    if (list.length) {
      empty.hidden = true;
      products.hidden = false;
      products.innerHTML = list.map(function (p) { return cardHTML(p, type, cat); }).join('');
    } else {
      /* No products in this category — show a clean blank area. */
      products.innerHTML = '';
      products.hidden = true;
      empty.hidden = true;
    }
  }

  /* ---- Open / close ---- */
  function openView(type, slug, opts) {
    opts = opts || {};
    var scope = rootSlug(type, slug);
    current = { type: type, slug: slug, scope: scope };
    var cat = findCat(type, slug);
    headLabel.textContent = GROUPS[type].label;
    headSub.textContent = cat ? cat.name : slug;
    viewAll.href = GROUPS[type].page + '?' + GROUPS[type].param + '=' + encodeURIComponent(scope);
    renderRail(type, scope, slug);
    renderProducts(type, slug);
    view.classList.add('open');
    view.setAttribute('aria-hidden', 'false');
    document.body.classList.add('mc-open');
    canGoBack = opts.push !== false;
    startSync();
    if (opts.push !== false) {
      try { history.pushState({ lycat: 1 }, '', hashFor(type, slug)); } catch (e) {}
    }
    try { backBtn.focus({ preventScroll: true }); } catch (e) {}
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
    try { history.replaceState({ lycat: 1 }, '', hashFor(current.type, slug)); } catch (e) {}
  }

  function closeView() {
    if (!view.classList.contains('open')) return;
    view.classList.remove('open');
    view.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mc-open');
    canGoBack = false;
    stopSync();
    try {
      if (location.hash && location.hash.indexOf('#mc=') === 0) {
        history.replaceState(null, '', location.pathname + location.search);
      }
    } catch (e) {}
  }

  /* ---- Wire up ---- */
  document.addEventListener('click', function (e) {
    if (!isMobile()) return;
    var t = e.target.closest('[data-mc-open]');
    if (!t) return;
    var type = t.getAttribute('data-mc-open');
    var slug = t.getAttribute('data-mc-slug');
    if (!slug || !GROUPS[type]) return;
    e.preventDefault();
    openView(type, slug, { push: true });
  });

  rail.addEventListener('click', function (e) {
    var btn = e.target.closest('.mc-rail-item');
    if (btn && btn.dataset.slug) selectCat(btn.dataset.slug);
  });

  products.addEventListener('click', function (e) {
    if (e.target.closest('.add-cart')) return; /* handled by script.js cart flow */
    var card = e.target.closest('.dish-card');
    if (!card) return;
    var url = card.dataset.url;
    if (url) window.location.href = url;
  });

  backBtn.addEventListener('click', function () {
    if (canGoBack) { try { history.back(); } catch (e) { closeView(); } }
    else closeView();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && view.classList.contains('open')) closeView();
  });

  window.addEventListener('popstate', function () {
    var parsed = parseHash();
    if (view.classList.contains('open')) {
      if (!parsed || !current || parsed.type !== current.type || parsed.slug !== current.slug) {
        closeView();
      }
    } else if (parsed && GROUPS[parsed.type] && findCat(parsed.type, parsed.slug)) {
      openView(parsed.type, parsed.slug, { push: false });
    }
  });

  function onViewportChange() {
    if (!isMobile() && view.classList.contains('open')) {
      closeView();
      try {
        if (location.hash && location.hash.indexOf('#mc=') === 0) {
          history.replaceState(null, '', location.pathname + location.search);
        }
      } catch (e) {}
    }
  }
  if (MQ && MQ.addEventListener) MQ.addEventListener('change', onViewportChange);
  else window.addEventListener('resize', onViewportChange);

  /* Reloading with a #mc=... hash reopens the browse view directly. */
  if (isMobile()) {
    var initial = parseHash();
    if (initial && GROUPS[initial.type] && findCat(initial.type, initial.slug)) {
      openView(initial.type, initial.slug, { push: false });
    }
  }
})();