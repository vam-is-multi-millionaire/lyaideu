/* LyaiDeu live notification feed: bell + beep + toast + browser notifications. */
(function () {
    var POLL_MS = 5000;
    var mq = window.matchMedia('(max-width: 960px)');
    var endpoint = 'api/notifications.php?role=' + encodeURIComponent(window.LYAIDEU_NOTIFY_ROLE || '');
    var seen = {};
    var first = true;
    var bell = null, badge = null, list = null, open = false;

    function beep() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine'; o.connect(g); g.connect(ctx.destination);
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.15, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
            o.start(); o.stop(ctx.currentTime + 0.5);
        } catch (e) {}
    }

    function toast(msg, link) {
        var el = document.createElement('div');
        el.className = 'flash-banner flash-success delivery-flash notify-toast';
        el.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.18);cursor:pointer;max-width:92vw;';
        el.innerHTML = '<i class="fa-solid fa-bell"></i> ' + msg;
        if (link) el.addEventListener('click', function () { window.location.href = link; });
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 5200);
    }

    function browserNotify(msg, link) {
        if (window.Notification && Notification.permission === 'granted') {
            try {
                var n = new Notification('LyaiDeu', { body: msg });
                n.onclick = function () { if (link) window.location.href = link; n.close(); };
            } catch (e) {}
        }
    }

    function placeBell() {
        var delivery = document.body.classList.contains('delivery-body');
        if (mq.matches) {
            var host = delivery ? document.querySelector('.delivery-topbar') : document.querySelector('header.topbar .nav');
            if (host) {
                bell.style.position = 'relative';
                bell.style.top = 'auto';
                bell.style.right = 'auto';
                bell.style.bottom = 'auto';
                bell.style.margin = '0';
                if (delivery) {
                    host.appendChild(bell);
                } else {
                    bell.style.marginLeft = 'auto';
                    var toggle = host.querySelector('.nav-toggle');
                    host.insertBefore(bell, toggle);
                }
                return;
            }
        }
        if (bell.parentNode !== document.body) document.body.appendChild(bell);
        bell.style.position = 'fixed';
        bell.style.top = delivery ? '74px' : '12px';
        bell.style.right = '14px';
        bell.style.bottom = 'auto';
        bell.style.margin = '0';
    }

    function buildBell() {
        if (bell) return;
        bell = document.createElement('div');
        bell.id = 'notifyBell';
        bell.style.position = 'relative';
        bell.style.zIndex = '99998';
        bell.style.fontFamily = 'Nunito,sans-serif';
        bell.innerHTML =
            '<button type="button" aria-label="Notifications" style="position:relative;background:#fff;border:2px solid var(--orange-200);border-radius:50%;width:46px;height:46px;font-size:1.05rem;cursor:pointer;color:var(--orange-700);box-shadow:0 6px 18px rgba(0,0,0,.12);">' +
            '<i class="fa-solid fa-bell"></i>' +
            '<span id="notifyBadge" style="display:none;position:absolute;top:-4px;right:-4px;background:#c93a3a;color:#fff;font-size:.7rem;font-weight:800;min-width:18px;height:18px;border-radius:9px;line-height:18px;text-align:center;padding:0 4px;"></span>' +
            '</button>' +
            '<div id="notifyList" style="display:none;position:absolute;right:0;top:52px;width:330px;max-width:92vw;background:#fff;border:2px solid var(--orange-100);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);padding:.6rem;font-size:.85rem;"></div>';
        badge = bell.querySelector('#notifyBadge');
        list = bell.querySelector('#notifyList');
        placeBell();
        if (mq.addEventListener) mq.addEventListener('change', placeBell);
        else if (mq.addListener) mq.addListener(placeBell);
        var btn = bell.querySelector('button');
        btn.addEventListener('click', function () { open ? hideList() : showList(); });
        document.addEventListener('click', function (e) { if (open && !bell.contains(e.target)) hideList(); });
    }

    function hideList() { open = false; list.style.display = 'none'; }

    function showList() {
        open = true;
        fetch(endpoint, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var items = (d && d.items) || [];
                if (!items.length) {
                    list.innerHTML = '<p style="margin:.4rem;color:#777;">No notifications yet.</p>';
                } else {
                    var html = '<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--orange-100);padding-bottom:.4rem;margin-bottom:.4rem;"><b><i class="fa-solid fa-bell"></i> Notifications</b><button type="button" id="notifyMarkAll" style="background:none;border:none;color:var(--orange-700);font-weight:700;cursor:pointer;">Mark all read</button></div>';
                    items.forEach(function (it) {
                        var link = it.link || 'orders';
                        html += '<a href="' + link + '" style="display:block;text-decoration:none;color:inherit;padding:.45rem .35rem;border-radius:8px;' + (it.is_read ? '' : 'background:var(--orange-50);font-weight:700;') + '">' + it.message +
                            '<small style="display:block;color:#888;font-weight:400;">' + (it.created_at || '') + '</small></a>';
                    });
                    list.innerHTML = html;
                    var ma = list.querySelector('#notifyMarkAll');
                    if (ma) ma.addEventListener('click', function () { markRead(items.map(function (x) { return x.id; })); });
                }
            })
            .catch(function () {});
        list.style.display = 'block';
    }

    function markRead(ids) {
        if (!ids || !ids.length) return;
        fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(ids) })
            .catch(function () {});
    }

    function updateBadge(n) {
        if (!badge) return;
        if (n > 0) { badge.textContent = n > 99 ? '99+' : String(n); badge.style.display = ''; }
        else { badge.style.display = 'none'; }
    }

    function scan() {
        fetch(endpoint, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.items) return;
                updateBadge(d.unread || 0);
                // First poll of this page load only seeds the "seen" list so we
                // never blast the whole feed as new (prevents notification spam).
                if (first) {
                    first = false;
                    d.items.forEach(function (it) { seen[String(it.id)] = true; });
                    return;
                }
                var fresh = [];
                d.items.forEach(function (it) {
                    var key = String(it.id);
                    if (!seen[key] && !it.is_read) { seen[key] = true; fresh.push(it); }
                    else { seen[key] = true; }
                });
                if (fresh.length) {
                    beep();
                    fresh.forEach(function (it) { toast(it.message, it.link); browserNotify(it.message, it.link); });
                    markRead(fresh.map(function (x) { return x.id; }));
                    updateBadge(Math.max(0, (d.unread || 0) - fresh.length));
                    var lb = document.querySelector('[data-live-indicator]');
                    if (lb) lb.classList.add('live-on');
                }
            })
            .catch(function () {});
    }

    function init() {
        buildBell();
        scan();
        setInterval(scan, POLL_MS);
        if (window.Notification && Notification.permission === 'default') {
            var onInteract = function () { try { Notification.requestPermission(); } catch (e) {} document.removeEventListener('click', onInteract); };
            document.addEventListener('click', onInteract);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();