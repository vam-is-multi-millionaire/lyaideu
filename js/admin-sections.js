/* ==========================================================
   LyaiDeu Admin — Sections page behaviour:
   section quick-edit toggles, icon previews, delete confirms,
   and the product-assignment manager that links existing items
   from Menu / Mart / Beverages / Others into custom categories.
   ========================================================== */
(function () {
  'use strict';

  /* ---- Icon previews ---------------------------------------------------- */
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || sel.tagName !== 'SELECT' || !sel.name || sel.name.indexOf('[icon]') === -1) return;
    var wrap = sel.closest('.wp-cat-icon-wrap');
    if (!wrap) return;
    var chip = wrap.querySelector('.cat-icon-chip i');
    if (chip) chip.className = 'fa-solid ' + sel.value;
  });
  document.querySelectorAll('.wp-cat-icon-wrap').forEach(function (wrap) {
    var sel = wrap.querySelector('select');
    var chip = wrap.querySelector('.cat-icon-chip i');
    if (sel && chip) chip.className = 'fa-solid ' + sel.value;
  });

  /* ---- Quick-edit open / cancel ----------------------------------------- */
  document.addEventListener('click', function (e) {
    var edit = e.target.closest('.wp-cat-edit');
    if (edit) {
      var form = document.getElementById(edit.getAttribute('data-target'));
      var row = edit.closest('.wp-cat-row');
      if (!form || !row) return;
      var opening = form.style.display === 'none' || !form.style.display;
      document.querySelectorAll('.wp-cat-quick-edit').forEach(function (f) { f.style.display = 'none'; });
      document.querySelectorAll('.wp-cat-item').forEach(function (it) { it.style.display = ''; });
      if (opening) {
        row.querySelector('.wp-cat-item').style.display = 'none';
        form.style.display = 'block';
      }
      return;
    }
    var cancel = e.target.closest('.wp-cat-cancel');
    if (cancel) {
      var f = cancel.closest('.wp-cat-quick-edit');
      if (f) {
        f.style.display = 'none';
        var it = f.closest('.wp-cat-row').querySelector('.wp-cat-item');
        if (it) it.style.display = '';
      }
    }
  });

  /* ---- Delete confirmations --------------------------------------------- */
  document.querySelectorAll('.wp-cat-del-inline').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var btn = form.querySelector('.wp-cat-del-btn');
      if (btn && !window.confirm(btn.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* ---- Product assignment manager ---------------------------------------- */
  var LINKS = window.LY_ASSIGN_LINKS || {};
  var NAMES = window.LY_ASSIGN_NAMES || {};
  var catSel = document.getElementById('assignCat');
  var catIdInput = document.getElementById('assignCatId');
  var wrap = document.getElementById('assignedWrap');
  var search = document.getElementById('assignSearch');
  var form = document.getElementById('assignForm');

  if (!catSel || !form) return;

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function poolLabel(t) {
    return t === 'dish' ? 'Menu' : (t === 'mart' ? 'Mart' : (t === 'beverage' ? 'Beverage' : 'Other'));
  }

  function checkedBoxes() {
    return Array.prototype.slice.call(form.querySelectorAll('.assign-check:checked'));
  }

  function renderAssigned() {
    if (!wrap) return;
    var boxes = checkedBoxes();
    if (!boxes.length) {
      wrap.innerHTML = '<span style="font-size:.8rem;font-weight:700;color:var(--muted);">Nothing linked to this category yet.</span>';
      return;
    }
    wrap.innerHTML = boxes.map(function (cb) {
      var key = cb.value; /* checkbox values are "type:id" tokens */
      return '<span class="cat-pill"><i class="fa-solid fa-link"></i> ' + esc(NAMES[key] || cb.textContent.trim()) +
        ' <small style="opacity:.65;font-weight:800;">(' + poolLabel(key.split(':')[0]) + ')</small></span>';
    }).join('');
  }

  function applyCategory() {
    var cid = String(catSel.value);
    if (catIdInput) catIdInput.value = cid;
    var linked = {};
    (LINKS[cid] || []).forEach(function (pair) {
      linked[pair[0] + ':' + pair[1]] = true;
    });
    form.querySelectorAll('.assign-check').forEach(function (cb) {
      cb.checked = !!linked[cb.value];
    });
    renderAssigned();
  }

  form.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('assign-check')) {
      renderAssigned();
    }
  });

  catSel.addEventListener('change', applyCategory);

  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      document.querySelectorAll('.assign-row').forEach(function (rowEl) {
        var hay = rowEl.getAttribute('data-search') || '';
        rowEl.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  form.addEventListener('submit', function (e) {
    if (!catSel.value) {
      e.preventDefault();
      window.alert('Pick a category first.');
    }
  });

  applyCategory();
})();
