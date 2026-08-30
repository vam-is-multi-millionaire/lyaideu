/* Admin product variants editor.
   Works with the markup produced by lyaideu_variants_editor_html():
   each form has a .pm-variants block with a .pv-toggle checkbox and a .pv-list
   of .pv-row option rows. Handles add/remove, reorder and re-indexing of the
   nested input names so the first option whose .pv-default-input is checked
   becomes the default on save. */
(function () {
  'use strict';

  function reindex(list) {
    var rows = list.querySelectorAll('.pv-row');
    rows.forEach(function (row, i) {
      row.querySelectorAll('input').forEach(function (input) {
        var name = input.getAttribute('name') || '';
        name = name.replace(/(variants\])\[\d+\](\[)/, '$1[' + i + ']$2');
        input.setAttribute('name', name);
      });
    });
  }

  /* Reset a cloned/cleared row for fresh input. Checkboxes must keep their
     value attribute (the "Default" boxes submit value="1") — only uncheck
     them, otherwise a checked box would submit an empty value and the
     server would silently drop the default flag. */
  function resetRowInputs(row) {
    row.querySelectorAll('input').forEach(function (input) {
      if (input.type === 'checkbox') {
        input.checked = false;
      } else if (input.type === 'file') {
        input.value = '';
      } else {
        input.value = '';
      }
      input.removeAttribute('checked');
      input.classList.remove('invalid');
    });
    var preview = row.querySelector('.pv-img-preview');
    if (preview) {
      preview.classList.add('pv-img-empty');
      preview.innerHTML = '<i class="fa-solid fa-image"></i>';
    }
    var existing = row.querySelector('.pv-existing-image');
    if (existing) existing.value = '';
    var remLabel = row.querySelector('.pv-remove-img');
    if (remLabel) {
      remLabel.style.display = 'none';
      var remInput = remLabel.querySelector('input');
      if (remInput) remInput.checked = false;
    }
  }

  function addRow(block, templateRow) {
    var list = block.querySelector('.pv-list');
    if (!list) return;
    var proto = templateRow || list.querySelector('.pv-row');
    var row = proto ? proto.cloneNode(true) : null;
    if (!row) return;
    resetRowInputs(row);
    var labelInput = row.querySelector('.pv-label');
    if (labelInput) labelInput.focus();
    list.insertBefore(row, list.querySelector('.pv-add'));
    reindex(list);
  }

  function removeRow(row, list) {
    var remaining = list.querySelectorAll('.pv-row');
    if (remaining.length <= 1) {
      resetRowInputs(row);
      return;
    }
    row.parentNode.removeChild(row);
    reindex(list);
  }

  function moveRow(row, list, dir) {
    var rows = Array.prototype.slice.call(list.querySelectorAll('.pv-row'));
    var idx = rows.indexOf(row);
    var target = idx + dir;
    if (target < 0 || target >= rows.length) return;
    list.insertBefore(row, dir > 0 ? rows[target].nextSibling : rows[target]);
    reindex(list);
  }

  function initBlock(block) {
    var toggle = block.querySelector('.pv-toggle');
    var list = block.querySelector('.pv-list');
    if (!toggle || !list) return;

    toggle.addEventListener('change', function () {
      list.style.display = toggle.checked ? '' : 'none';
    });

    /* "Default" acts like a radio: picking one option unmarks the others so
       the saved product always has exactly one preselected option. */
    list.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('pv-default-input') && e.target.checked) {
        list.querySelectorAll('.pv-default-input').forEach(function (other) {
          if (other !== e.target) other.checked = false;
        });
      }
      if (e.target && e.target.classList && e.target.classList.contains('pv-image-input') && e.target.files) {
        var row = e.target.closest('.pv-row');
        var preview = row ? row.querySelector('.pv-img-preview') : null;
        var file = e.target.files[0];
        if (file && preview) {
          var url = URL.createObjectURL(file);
          preview.classList.remove('pv-img-empty');
          preview.innerHTML = '<img src="' + url + '" alt="">';
          var rem = row ? row.querySelector('.pv-remove-img input') : null;
          if (rem) rem.checked = false;
        } else if (preview) {
          var existing = row ? row.querySelector('.pv-existing-image') : null;
          var existingVal = existing ? (existing.value || '').trim() : '';
          if (existingVal) {
            preview.classList.remove('pv-img-empty');
            preview.innerHTML = '<img src="' + existingVal.replace(/"/g, '&quot;') + '" alt="">';
          } else {
            preview.classList.add('pv-img-empty');
            preview.innerHTML = '<i class="fa-solid fa-image"></i>';
          }
        }
      }
    });

    /* Vendor-style price sync (opt-in via data-sync-price on .pm-variants):
       while options are active the form's main Price field is hidden and
       mirrors the default (or first priced) option; it comes back with its
       original required/min state when the toggle is turned off. */
    if (block.hasAttribute('data-sync-price')) {
      var syncForm = block.closest('form');
      var priceInput = syncForm ? syncForm.querySelector('input[name$="[price]"]') : null;
      if (priceInput) {
        var priceWrap = priceInput.parentElement;
        var priceRequiredInit = priceInput.required;
        var priceMinInit = priceInput.getAttribute('min');
        var syncPriceField = function () {
          if (toggle.checked) {
            if (priceWrap) priceWrap.style.display = 'none';
            priceInput.required = false;
            priceInput.min = 0;
            var defRow = null;
            list.querySelectorAll('.pv-row').forEach(function (row) {
              var d = row.querySelector('.pv-default-input');
              if (d && d.checked && !defRow) defRow = row;
            });
            var src = (defRow && parseInt(defRow.querySelector('.pv-price').value, 10) > 0) ? defRow : null;
            if (!src) {
              list.querySelectorAll('.pv-row').forEach(function (row) {
                if (!src && parseInt(row.querySelector('.pv-price').value, 10) > 0) src = row;
              });
            }
            var v = src ? parseInt(src.querySelector('.pv-price').value, 10) : NaN;
            if (!isNaN(v) && v > 0) priceInput.value = v;
          } else {
            if (priceWrap) priceWrap.style.display = '';
            priceInput.required = priceRequiredInit;
            if (priceMinInit === null) priceInput.removeAttribute('min');
            else priceInput.setAttribute('min', priceMinInit);
          }
        };
        toggle.addEventListener('change', syncPriceField);
        list.addEventListener('input', function (e) {
          if (toggle.checked && e.target && e.target.classList &&
              (e.target.classList.contains('pv-price') || e.target.classList.contains('pv-default-input'))) {
            syncPriceField();
          }
        });
        list.addEventListener('change', function (e) {
          if (toggle.checked && e.target && e.target.classList && e.target.classList.contains('pv-default-input')) {
            syncPriceField();
          }
        });
        syncPriceField();
      }
    }

    var form = block.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (!toggle.checked) return;
        var valid = Array.prototype.some.call(list.querySelectorAll('.pv-row'), function (row) {
          var l = row.querySelector('.pv-label');
          var pr = row.querySelector('.pv-price');
          return l && pr && l.value.trim() !== '' && parseInt(pr.value, 10) > 0;
        });
        if (!valid) {
          e.preventDefault();
          var firstLabel = list.querySelector('.pv-label');
          if (firstLabel) firstLabel.focus();
          alert('Add at least one size / quantity option with a price, or turn off "Enable size / quantity options".');
        }
      });
    }

    block.addEventListener('click', function (e) {
      var t = e.target;
      var addBtn = t.closest('.pv-add');
      if (addBtn) {
        addRow(block);
        return;
      }
      var del = t.closest('.pv-del');
      if (del) {
        var row = del.closest('.pv-row');
        if (row) removeRow(row, list);
        return;
      }
      var up = t.closest('.pv-up');
      if (up) {
        var rowU = up.closest('.pv-row');
        if (rowU) moveRow(rowU, list, -1);
        return;
      }
      var down = t.closest('.pv-down');
      if (down) {
        var rowD = down.closest('.pv-row');
        if (rowD) moveRow(rowD, list, 1);
      }
    });

    list.addEventListener('keydown', function (e) {
      if (e.target.classList && e.target.classList.contains('pv-label') && e.key === 'Enter') {
        e.preventDefault();
        addRow(block, e.target.closest('.pv-row'));
      }
    });
  }

  function initAll() {
    document.querySelectorAll('.pm-variants').forEach(function (block) {
      if (block.__pvInit) return;
      block.__pvInit = true;
      initBlock(block);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
