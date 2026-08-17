<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();
lyaideu_ensure_location_columns();

$allowed = ['Pending', 'Confirmed', 'Preparing', 'Ready for pickup', 'Out for delivery', 'Delivered', 'Cancelled'];

$statusIcon = [
    'Pending' => 'fa-clock',
    'Confirmed' => 'fa-check-double',
    'Preparing' => 'fa-fire-burner',
    'Ready for pickup' => 'fa-box-open',
    'Out for delivery' => 'fa-motorcycle',
    'Delivered' => 'fa-circle-check',
    'Cancelled' => 'fa-ban',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $id = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($id > 0 && $status !== '' && in_array($status, $allowed, true)) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $status,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $id,
            ]);
        } catch (Throwable $e) {
            // Fall through to redirect without saved flag on failure.
        }
    }

    header('Location: admin_orders?saved=1');
    exit;
}

$orders = [];
$counts = array_fill_keys($allowed, 0);

try {
    $rows = $pdo->query(
        'SELECT o.id, o.customer_name, o.phone, o.address, o.note, o.payment,
                o.subtotal, o.delivery_fee, o.discount, o.total, o.status, o.created_at,
                o.delivery_lat, o.delivery_lng
         FROM orders o
         ORDER BY o.created_at DESC
         LIMIT 300'
    )->fetchAll();

    foreach ($rows as $row) {
        $track = lyaideu_order_tracking((int)$row['id']);
        if (!$track) {
            $track = [
                'status' => $row['status'],
                'total' => (int)$row['total'],
                'subtotal' => (int)$row['subtotal'],
                'delivery_fee' => (int)$row['delivery_fee'],
                'discount' => (int)$row['discount'],
                'vendors' => [],
                'other_items' => [],
                'rider' => null,
            ];
        }
        $row['created'] = $row['created_at'];
        $track['customer_name'] = $row['customer_name'];
        $track['phone'] = $row['phone'];
        $track['created'] = $row['created_at'];
        $row['track'] = $track;
        $orders[] = $row;
        if (isset($counts[$row['status']])) {
            $counts[$row['status']]++;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load orders.');
}

admin_page_start('Orders', 'orders', 'Order Management');
?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <div class="admin-stats admin-stats-orders">
        <div class="stat-total"><span class="stat-ico"><i class="fa-solid fa-receipt"></i></span><strong data-count-status=""><?= count($orders) ?></strong><span>Total Orders</span></div>
        <?php foreach ($counts as $k => $v): ?>
        <div class="stat-<?= htmlspecialchars(strtolower($k), ENT_QUOTES, 'UTF-8') ?>"><span class="stat-ico"><i class="fa-solid <?= $statusIcon[$k] ?? 'fa-circle-info' ?>"></i></span><strong data-count-status="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"><?= $v ?></strong><span><?= htmlspecialchars($k) ?></span></div>
        <?php endforeach; ?>
    </div>

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Watch every order move from the kitchen to the doorstep — updates arrive live as vendors and riders work.</p>
            <span class="live-badge" data-live-badge><span class="live-dot"></span> Live updates</span>
        </div>

        <?php if ($orders): ?>
        <div class="admin-order-tools">
            <div class="admin-order-search">
                <span><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" id="adminOrderSearch" placeholder="Search by name, phone or order number…" autocomplete="off">
            </div>
        </div>
        <div class="admin-filter-tabs" role="tablist" aria-label="Filter orders by status">
            <button type="button" class="admin-filter-tab active" data-status=""><i class="fa-solid fa-layer-group"></i> All <span><?= count($orders) ?></span></button>
            <?php foreach ($counts as $k => $v): ?>
            <button type="button" class="admin-filter-tab" data-status="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid <?= $statusIcon[$k] ?? 'fa-circle-info' ?>"></i> <?= htmlspecialchars($k) ?> <span><?= $v ?></span></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="admin-order-list" id="adminOrderList">
            <?php if (!$orders): ?>
            <div class="admin-card">
                <h3>No orders yet.</h3>
                <p class="small-note">Orders placed by customers will appear here in real time.</p>
            </div>
            <?php else: ?>

            <?php foreach ($orders as $o):
                $track = $o['track'];
                $pill = lyaideu_order_pill_class((string)$o['status']);
                $searchTxt = mb_strtolower((string)$o['customer_name'] . ' ' . (string)$o['phone'] . ' #' . (int)$o['id']);
            ?>
            <article class="admin-order-card status-<?= $pill ?>" data-order-id="<?= (int)$o['id'] ?>" data-status="<?= htmlspecialchars((string)$o['status'], ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchTxt, ENT_QUOTES, 'UTF-8') ?>">
                <div class="order-card-head">
                    <div>
                        <h2>#<?= (int)$o['id'] ?> · <?= htmlspecialchars((string)$o['customer_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><i class="fa-regular fa-clock"></i> <?= htmlspecialchars((string)$o['created'], ENT_QUOTES, 'UTF-8') ?> · <i class="fa-solid fa-phone"></i> <a href="tel:+977<?= htmlspecialchars((string)$o['phone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$o['phone'], ENT_QUOTES, 'UTF-8') ?></a></p>
                    </div>
                    <div class="admin-card-actions">
                        <span class="order-status-pill status-<?= $pill ?>"><i class="fa-solid <?= $statusIcon[$o['status']] ?? 'fa-circle-info' ?>"></i> <?= htmlspecialchars((string)$o['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <form method="POST" class="status-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <select name="status" aria-label="Order status">
                                <?php foreach ($allowed as $st): ?>
                                <option <?= $o['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline btn-sm" type="submit" title="Update order status"><i class="fa-solid fa-check"></i> Update</button>
                        </form>
                    </div>
                </div>

                <?= lyaideu_order_track_html((string)$o['status']) ?>

                <?php foreach ($track['vendors'] as $v): ?>
                <?= lyaideu_order_vendor_html($v) ?>
                <?php endforeach; ?>
                <?php if (!empty($track['other_items'])): ?>
                <?= lyaideu_order_other_html($track['other_items']) ?>
                <?php endif; ?>

                <?= lyaideu_order_delivery_html($track) ?>

                <p class="admin-order-address"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars((string)$o['address'], ENT_QUOTES, 'UTF-8') ?><?php if ((string)$o['note'] !== ''): ?> · <i class="fa-solid fa-note-sticky"></i> <?= htmlspecialchars((string)$o['note'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>

                <?php if ($o['delivery_lat'] !== null && $o['delivery_lat'] !== '' && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''): ?>
                <div class="rider-map" data-lat="<?= htmlspecialchars((string)$o['delivery_lat'], ENT_QUOTES, 'UTF-8') ?>" data-lng="<?= htmlspecialchars((string)$o['delivery_lng'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <a class="btn btn-outline btn-sm" href="https://www.google.com/maps/dir/?api=1&destination=<?= htmlspecialchars((string)$o['delivery_lat'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars((string)$o['delivery_lng'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Get Directions</a>
                <?php endif; ?>

                <div class="admin-order-foot">
                    <div class="admin-order-meta">
                        <span><i class="fa-solid fa-credit-card"></i> <?= htmlspecialchars((string)$o['payment'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span><i class="fa-solid fa-basket-shopping"></i> Rs. <?= (int)$track['subtotal'] ?></span>
                        <?php if ((int)$track['delivery_fee'] > 0): ?>
                        <span><i class="fa-solid fa-truck-fast"></i> Rs. <?= (int)$track['delivery_fee'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="admin-order-total"><span>Order total</span><strong>Rs. <?= (int)$track['total'] ?></strong></div>
                </div>
            </article>
            <?php endforeach; ?>

            <?php endif; ?>
            <div class="empty-state" id="adminOrderEmpty" style="display:none"><span class="big"><i class="fa-solid fa-filter-circle-xmark"></i></span><p>No orders match this filter.</p></div>
        </div>
    </section>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var list = document.getElementById('adminOrderList');
    if (!list) return;
    var search = document.getElementById('adminOrderSearch');
    var badge = document.querySelector('[data-live-badge]');
    var empty = document.getElementById('adminOrderEmpty');
    var maps = [];

    function flashCard(card) {
        if (!card) return;
        card.classList.remove('track-flash');
        void card.offsetWidth;
        card.classList.add('track-flash');
    }

    function currentFilter() {
        var tab = document.querySelector('.admin-filter-tab.active');
        return tab ? tab.getAttribute('data-status') : '';
    }

    function applyFilter() {
        var q = (search ? search.value : '').trim().toLowerCase();
        var st = currentFilter();
        var visible = 0;
        list.querySelectorAll(':scope > .admin-order-card').forEach(function (card) {
            var show = true;
            if (st && card.getAttribute('data-status') !== st) show = false;
            if (show && q && (card.getAttribute('data-search') || '').indexOf(q) === -1) show = false;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (empty) {
            var any = list.querySelectorAll(':scope > .admin-order-card').length > 0;
            empty.style.display = (any && visible === 0) ? '' : 'none';
        }
        invalidateVisibleMaps();
    }

    function updateLive() {
        var counts = { '': 0 };
        list.querySelectorAll(':scope > .admin-order-card').forEach(function (card) {
            var s = card.getAttribute('data-status') || '';
            counts[s] = (counts[s] || 0) + 1;
            counts['']++;
        });
        document.querySelectorAll('.admin-filter-tab').forEach(function (t) {
            var n = counts[t.getAttribute('data-status') || ''] || 0;
            var b = t.querySelector('span');
            if (b) b.textContent = n;
        });
        document.querySelectorAll('.admin-stats [data-count-status]').forEach(function (el) {
            var s = el.getAttribute('data-count-status') || '';
            var strong = el.querySelector('strong');
            if (strong) strong.textContent = counts[s] || 0;
        });
    }

    document.querySelectorAll('.admin-filter-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.admin-filter-tab').forEach(function (x) { x.classList.remove('active'); });
            t.classList.add('active');
            applyFilter();
        });
    });
    if (search) search.addEventListener('input', applyFilter);

    function initMaps() {
        if (typeof L === 'undefined') return;
        list.querySelectorAll('.rider-map').forEach(function (el) {
            if (el.getAttribute('data-map-ready') === '1') return;
            var card = el.closest('.admin-order-card');
            if (card && card.style.display === 'none') return;
            var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
            if (isNaN(lat) || isNaN(lng)) return;
            var map = L.map(el, { scrollWheelZoom: false, attributionControl: false }).setView([lat, lng], 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            L.marker([lat, lng]).addTo(map);
            el.setAttribute('data-map-ready', '1');
            maps.push(map);
            setTimeout(function () { map.invalidateSize(); }, 60);
        });
    }

    function invalidateVisibleMaps() {
        maps.forEach(function (m) {
            var el = m.getContainer();
            var card = el.closest ? el.closest('.admin-order-card') : null;
            if (card && card.style.display === 'none') return;
            setTimeout(function () { m.invalidateSize(); }, 60);
        });
    }

    function refresh() {
        if (document.visibilityState === 'hidden') return;
        fetch(location.pathname + location.search, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var d = new DOMParser().parseFromString(html, 'text/html');
                var next = d.getElementById('adminOrderList');
                if (!next) return;
                if (list.innerHTML === next.innerHTML) {
                    if (badge) badge.classList.add('live-on');
                    return;
                }
                var prev = {};
                list.querySelectorAll(':scope > .admin-order-card').forEach(function (c) {
                    prev[c.getAttribute('data-order-id')] = c.getAttribute('data-status');
                });
                list.innerHTML = next.innerHTML;
                maps = [];
                list.querySelectorAll(':scope > .admin-order-card').forEach(function (c) {
                    var id = c.getAttribute('data-order-id');
                    if (prev[id] && prev[id] !== c.getAttribute('data-status')) flashCard(c);
                });
                applyFilter();
                updateLive();
                initMaps();
                if (badge) badge.classList.add('live-on');
            })
            .catch(function () {});
    }

    updateLive();
    initMaps();
    refresh();
    setInterval(refresh, 6000);
    setInterval(initMaps, 2000);
})();
</script>
<?php
admin_page_end();
