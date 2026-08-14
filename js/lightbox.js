(function () {
  'use strict';
  if (window.LYAIDEU_LIGHTBOX) return;
  window.LYAIDEU_LIGHTBOX = true;

  var overlay = null;

  function ensure() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = '<button type="button" class="lightbox-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
      '<div class="lightbox-stage"><div class="lightbox-cell"></div></div>' +
      '<p class="lightbox-caption"></p>';
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('.lightbox-close')) close();
    });
    document.body.appendChild(overlay);
    return overlay;
  }

  function open(src, caption) {
    var o = ensure();
    var cell = o.querySelector('.lightbox-cell');
    var cap = o.querySelector('.lightbox-caption');
    var isPdf = /\.pdf($|\?)/i.test(src);
    cell.innerHTML = isPdf
      ? '<iframe src="' + src + '" class="lightbox-frame" title="' + (caption || '') + '"></iframe>'
      : '<img src="' + src + '" class="lightbox-img" alt="' + (caption || '') + '">';
    cap.textContent = caption || '';
    document.body.classList.add('lightbox-open');
    o.classList.add('show');
  }

  function close() {
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.classList.remove('lightbox-open');
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-lightbox]');
    if (!t) return;
    e.preventDefault();
    open(t.getAttribute('data-lightbox'), t.getAttribute('data-lightbox-caption') || '');
  });
})();
