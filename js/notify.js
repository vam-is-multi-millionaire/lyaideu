/* LyaiDeu live notification feed: bell + beep + toast + browser notifications. */
(function () {
    var POLL_MS = 5000;
    var FIRST_LOAD_ALERT_SECS = 90;
    var mq = window.matchMedia('(max-width: 960px)');
    var endpoint = 'api/notifications.php?role=' + encodeURIComponent(window.LYAIDEU_NOTIFY_ROLE || '');
    var seen = {};
    var first = true;
    var bell = null, badge = null, list = null, open = false;
    var lastItems = [], lastSig = '';
    var audioCtx = null;
    var audioUnlocked = false;

    /* Server already sends NPT 12h strings; raw UTC datetimes fall back here. */
    function fmtNP12(dt) {
        var s = String(dt == null ? '' : dt);
        if (!s || s.indexOf('M') >= 0 || /[AP]M/i.test(s)) return s;
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!m) return s;
        try {
            var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)));
            return d.toLocaleString('en-US', { timeZone: 'Asia/Kathmandu', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
        } catch (e) { return s; }
    }

    function isUnread(it) {
        // PDO/MySQL returns "0"/"1" strings — !"0" is false in JS, so cast.
        var v = it && it.is_read;
        return v === 0 || v === '0' || v === false || v === null || v === undefined;
    }

    function itemTs(it) {
        var t = parseInt((it && (it.created_ts || it.ts)) || '0', 10);
        if (t > 0 && t < 10000000000) return t;
        if (t >= 10000000000) return Math.floor(t / 1000);
        return 0;
    }

    function isFreshRecent(it) {
        var t = itemTs(it);
        if (!t) return false;
        var now = Math.floor(Date.now() / 1000);
        return (now - t) >= 0 && (now - t) <= FIRST_LOAD_ALERT_SECS;
    }

    function unlockAudio() {
        if (audioUnlocked) {
            try { if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); } catch (e) {}
            return;
        }
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            audioUnlocked = true;
        } catch (e) {}
    }

    function beep(retry) {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') {
                try { audioCtx.resume(); } catch (e) {}
                // Single retry only — avoids infinite loop on blocked tabs.
                if (!retry) setTimeout(function () { try { beep(true); } catch (e) {} }, 350);
                return;
            }
            var o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.type = 'sine'; o.connect(g); g.connect(audioCtx.destination);
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.15, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
            o.start(); o.stop(audioCtx.currentTime + 0.5);
        } catch (e) {}
    }

    function toast(msg, link) {
        var el = document.createElement('div');
        el.className = 'flash-banner flash-success delivery-flash notify-toast';
        el.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.18);cursor:pointer;max-width:min(92vw,560px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-flex;align-items:center;gap:.4rem;';
        el.innerHTML = '<i class="fa-solid fa-bell"></i> ' + msg;
        if (link) el.addEventListener('click', function () { window.location.href = link; });
        document.body.appendChild(el);
        try{var s=0.9;el.style.fontSize=s+'rem';void el.offsetWidth;var m=0.66,step=0.02;while(el.scrollWidth>el.clientWidth+2&&s>m){s-=step;el.style.fontSize=s.toFixed(2)+'rem';void el.offsetWidth;}}catch(e){}
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

    function askPermission() {
        if (!window.Notification) return;
        try {
            if (Notification.permission === 'default') Notification.requestPermission();
        } catch (e) {}
    }

    function permHint() {
        if (!window.Notification) return '';
        if (Notification.permission === 'granted') return '';
        if (Notification.permission === 'denied')
            return '<p style="margin:.4rem;color:#a02a2a;font-weight:700;">Browser popups blocked — click the lock icon > Site settings > Allow Notifications, then click the bell again.</p>';
        return '<p style="margin:.4rem;color:#777;">Tip: click the bell > Allow when asked to enable browser popups + sound.</p>';
    }

    function placeBell() {
        var delivery = document.body.classList.contains('delivery-body');
        var isAdmin = document.body.classList.contains('admin-body');
        // Mobile/tablet: park the bell inside the header so it never
        // overlays the hamburger or floats detached. Desktop keeps it fixed.
        if (mq.matches) {
            if (isAdmin) {
                var aHost = document.querySelector('.admin-header .admin-actions');
                if (aHost) {
                    bell.style.position = 'relative';
                    bell.style.top = 'auto';
                    bell.style.right = 'auto';
                    bell.style.bottom = 'auto';
                    bell.style.margin = '0';
                    bell.style.zIndex = '5';
                    if (bell.parentNode !== aHost) aHost.insertBefore(bell, aHost.firstChild || null);
                    if (list) { list.style.right = '0'; list.style.top = '52px'; }
                    return;
                }
            }
            if (!delivery && !isAdmin) {
                var host = document.querySelector('header.topbar .nav');
                if (host) {
                    bell.style.position = 'relative';
                    bell.style.top = 'auto';
                    bell.style.right = 'auto';
                    bell.style.bottom = 'auto';
                    bell.style.margin = '0';
                    bell.style.zIndex = '5';
                    var search = host.querySelector('.nav-search');
                    if (search) {
                        host.insertBefore(bell, search.nextSibling || null);
                    } else {
                        host.appendChild(bell);
                    }
                    return;
                }
            } else if (delivery) {
                var dHost = document.querySelector('.delivery-topbar');
                var dToggle = document.querySelector('.delivery-nav-toggle');
                if (dHost) {
                    bell.style.position = 'relative';
                    bell.style.top = 'auto';
                    bell.style.right = 'auto';
                    bell.style.bottom = 'auto';
                    bell.style.margin = '0';
                    bell.style.zIndex = '3';
                    // keep dropdown anchored below the header
                    if (list) { list.style.right = '-6px'; list.style.top = '46px'; }
                    if (dToggle && dToggle.parentNode === dHost) {
                        dHost.insertBefore(bell, dToggle);
                    } else {
                        dHost.appendChild(bell);
                    }
                    return;
                }
            }
        }
        if (bell.parentNode !== document.body) document.body.appendChild(bell);
        bell.style.position = 'fixed';
        bell.style.right = '14px';
        bell.style.bottom = 'auto';
        bell.style.margin = '0';
        bell.style.zIndex = '99998';
        if (list) { list.style.right = '0'; list.style.top = '52px'; }
        if (delivery) {
            var tb = document.querySelector('.delivery-topbar');
            bell.style.top = (tb ? tb.getBoundingClientRect().height + 6 : 74) + 'px';
        } else if (isAdmin) {
            var ah = document.querySelector('.admin-header');
            bell.style.top = (ah ? ah.getBoundingClientRect().height + 6 : 74) + 'px';
        } else {
            bell.style.top = '12px';
        }
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
            '<div id="notifyList" style="display:none;position:absolute;right:0;top:52px;width:330px;max-width:92vw;max-height:min(70vh,480px);overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;background:#fff;border:2px solid var(--orange-100);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);padding:0 .6rem .6rem;font-size:.85rem;"></div>';
        badge = bell.querySelector('#notifyBadge');
        list = bell.querySelector('#notifyList');
        placeBell();
        if (mq.addEventListener) mq.addEventListener('change', placeBell);
        else if (mq.addListener) mq.addListener(placeBell);
        var btn = bell.querySelector('button');
        btn.addEventListener('click', function () { unlockAudio(); askPermission(); open ? hideList() : showList(); });
        document.addEventListener('click', function (e) { if (open && !bell.contains(e.target)) hideList(); });
    }

    function hideList() { open = false; list.style.display = 'none'; }

    function renderItems(items) {
        lastItems = items || [];
        lastSig = lastItems.map(function (x) { return x.id + ':' + (isUnread(x) ? 0 : 1); }).join(',');
        var hint = permHint();
        if (!lastItems.length) {
            list.innerHTML = '<p style="margin:.4rem;color:#777;">No notifications yet.</p>' + hint;
            return;
        }
        var isDeliveryPage = document.body.classList.contains('delivery-body');
        var html = isDeliveryPage
            ? '<div style="display:flex;justify-content:space-between;align-items:center;gap:.4rem;position:sticky;top:0;background:#fff;z-index:3;border-bottom:1px solid var(--orange-100);padding:.3rem 0 .35rem;margin-bottom:.4rem;box-sizing:border-box;min-width:0;"><b style="white-space:nowrap;flex:0 0 auto;font-size:.88rem;"><i class="fa-solid fa-bell"></i> Notifications</b><button type="button" id="notifyMarkAll" style="background:none;border:none;color:var(--orange-700);font-weight:700;cursor:pointer;white-space:nowrap;flex:0 0 auto;text-align:right;padding-left:10px;padding-right:.2rem;font-size:.78rem;">Mark all read</button></div>'
            : '<div style="display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:3;border-bottom:1px solid var(--orange-100);padding:.35rem 0 .4rem;margin-bottom:.4rem;"><b><i class="fa-solid fa-bell"></i> Notifications</b><button type="button" id="notifyMarkAll" style="background:none;border:none;color:var(--orange-700);font-weight:700;cursor:pointer;">Mark all read</button></div>';
        lastItems.forEach(function (it) {
            var link = it.link || 'orders';
            html += '<a href="' + link + '" style="display:block;text-decoration:none;color:inherit;padding:.45rem .35rem;border-radius:8px;' + (isUnread(it) ? 'background:var(--orange-50);font-weight:700;' : '') + '">' + it.message +
                '<small style="display:block;color:#888;font-weight:400;">' + (fmtNP12(it.created_at) || '') + '</small></a>';
        });
        list.innerHTML = html + hint;
        var ma = list.querySelector('#notifyMarkAll');
        if (ma) ma.addEventListener('click', markAllRead);
    }

    function defaultLink() {
        var r = window.LYAIDEU_NOTIFY_ROLE || '';
        if (r === 'vendor') return 'vendor';
        if (r === 'rider') return 'rider';
        if (r === 'admin') return 'admin_orders';
        return 'orders';
    }

    function showList() {
        open = true;
        list.style.display = 'block';
        fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var items = (d && d.items) || [];
                items.forEach(function (it) { if (!it.link) it.link = defaultLink(); });
                renderItems(items);
            })
            .catch(function () {});
    }

    function markAllRead() {
        if (!list) return;
        var ma = list.querySelector('#notifyMarkAll');
        if (ma) { ma.disabled = true; ma.style.opacity = '.5'; }
        /* Optimistic: flip every rendered item to read right away so the
           click always gives instant feedback, then sync with the server. */
        lastItems.forEach(function (x) { x.is_read = 1; });
        renderItems(lastItems);
        updateBadge(0);
        fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ all: true }) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                return (d && d.ok)
                    ? fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); })
                    : null;
            })
            .then(function (d) {
                if (!d) return; /* server failed: badge/list resync on next poll */
                updateBadge(d.unread || 0);
                renderItems(d.items || []);
            })
            .catch(function () {});
    }

    function markRead(ids) {
        if (!ids || !ids.length) return;
        fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(ids) })
            .catch(function () {});
    }

    function updateBadge(n) {
        if (!badge) return;
        if (n > 0) { badge.textContent = n > 99 ? '99+' : String(n); badge.style.display = ''; }
        else { badge.style.display = 'none'; }
    }

    function alertItems(fresh) {
        if (!fresh.length) return;
        unlockAudio();
        beep();
        fresh.forEach(function (it) {
            var link = it.link || defaultLink();
            toast(it.message, link);
            browserNotify(it.message, link);
        });
        markRead(fresh.map(function (x) { return x.id; }));
        var lb = document.querySelector('[data-live-indicator]');
        if (lb) lb.classList.add('live-on');
    }

    function scan() {
        fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.items) return;
                d.items.forEach(function (it) { if (!it.link) it.link = defaultLink(); });
                updateBadge(d.unread || 0);
                /* Keep an OPEN dropdown live — but only repaint when something
                   actually changed, so the list never jumps while scrolling. */
                if (open) {
                    var sig = d.items.map(function (x) { return x.id + ':' + (isUnread(x) ? 0 : 1); }).join(',');
                    if (sig !== lastSig) renderItems(d.items);
                }
                // First poll seeds seen, but still alerts very recent unread
                // rows so a tab opened just before/after the order still pops.
                if (first) {
                    first = false;
                    var recent = [];
                    d.items.forEach(function (it) {
                        seen[String(it.id)] = true;
                        if (isUnread(it) && isFreshRecent(it)) recent.push(it);
                    });
                    if (recent.length) {
                        alertItems(recent);
                        updateBadge(Math.max(0, (d.unread || 0) - recent.length));
                    }
                    return;
                }
                var fresh = [];
                d.items.forEach(function (it) {
                    var key = String(it.id);
                    if (!seen[key] && isUnread(it)) { seen[key] = true; fresh.push(it); }
                    else { seen[key] = true; }
                });
                if (fresh.length) {
                    alertItems(fresh);
                    updateBadge(Math.max(0, (d.unread || 0) - fresh.length));
                }
            })
            .catch(function () {});
    }

    function init() {
        buildBell();
        scan();
        setInterval(scan, POLL_MS);
        // Unlock audio + ask popup permission on first real interaction
        // (required by Chrome; background tabs stay silent otherwise).
        var onInteract = function () {
            unlockAudio();
            askPermission();
        };
        document.addEventListener('click', onInteract);
        document.addEventListener('keydown', onInteract);
        if (window.Notification && Notification.permission === 'default') {
            var once = function () { try { Notification.requestPermission(); } catch (e) {} document.removeEventListener('click', once); };
            document.addEventListener('click', once);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
