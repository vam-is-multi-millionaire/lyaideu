<?php
require_once __DIR__ . '/delivery_inc.php';

$pdo = lyaideu_load_pdo();
$role = 'vendor';
delivery_require_login($role);
$user = delivery_user();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($user) {
    delivery_logout();
    $vendorId = (int)$user['id'];

    $allowedTransitions = [
        'Pending' => ['Accepted', 'Rejected'],
        'Accepted' => ['Preparing'],
        'Preparing' => ['Ready for pickup'],
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_action'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $newStatus = trim((string)($_POST['order_action']));
            $performed = false;
            try {
                $st = $pdo->prepare('SELECT status FROM order_vendor_status WHERE order_id = ? AND vendor_id = ? LIMIT 1');
                $st->execute([$orderId, $vendorId]);
                $perVendor = (string)$st->fetchColumn();
                if ($perVendor !== '' && in_array($newStatus, $allowedTransitions[$perVendor] ?? [], true)) {
                    $pdo->prepare(
                        'UPDATE order_vendor_status SET status = ?, updated_at = ? WHERE order_id = ? AND vendor_id = ?'
                    )->execute([$newStatus, date('Y-m-d H:i:s'), $orderId, $vendorId]);
                    if ($newStatus === 'Rejected') {
                        $pdo->prepare('UPDATE order_items SET vendor_id = NULL WHERE order_id = ? AND vendor_id = ?')
                            ->execute([$orderId, $vendorId]);
                        $pdo->prepare('UPDATE orders SET vendor_id = NULL WHERE id = ? AND vendor_id = ?')
                            ->execute([$orderId, $vendorId]);
                    }
                    $performed = true;
                    $flashMsg = match ($newStatus) {
                        'Accepted' => 'Order #' . $orderId . ' accepted — the customer has been notified.',
                        'Rejected' => 'Order #' . $orderId . ' rejected and removed from your queue.',
                        'Preparing' => 'Order #' . $orderId . ' is now being prepared.',
                        default => 'Order #' . $orderId . ' is ready for pickup.',
                    };
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => $flashMsg];
                    $aggregate = lyaideu_recompute_order_status($orderId);
                    $st = $pdo->prepare('SELECT user_id FROM orders WHERE id = ? LIMIT 1');
                    $st->execute([$orderId]);
                    $orderUserId = (int)$st->fetchColumn();
                    $vendorName = (string)$user['name'];
                    $link = 'orders?id=' . $orderId;
                    if ($newStatus === 'Accepted') {
                        lyaideu_notify($orderId, 'user', $orderUserId, $vendorName . ' accepted your order #' . $orderId . '.', $link);
                        lyaideu_notify_riders($orderId, 'Order #' . $orderId . ' was accepted — it will be ready soon.', 'rider');
                    } elseif ($newStatus === 'Preparing') {
                        lyaideu_notify($orderId, 'user', $orderUserId, $vendorName . ' started preparing your order #' . $orderId . '.', $link);
                        lyaideu_notify_riders($orderId, 'Order #' . $orderId . ' is being prepared.', 'rider');
                    } elseif ($newStatus === 'Ready for pickup') {
                        if ($aggregate === 'Ready for pickup') {
                            lyaideu_notify($orderId, 'user', $orderUserId, 'Your order #' . $orderId . ' is ready for pickup.', $link);
                            lyaideu_notify_riders($orderId, 'Order #' . $orderId . ' is ready — be the first to accept!', 'rider');
                        } else {
                            lyaideu_notify($orderId, 'user', $orderUserId, $vendorName . ' marked part of your order #' . $orderId . ' ready for pickup.', $link);
                        }
                    } elseif ($newStatus === 'Rejected') {
                        lyaideu_notify($orderId, 'user', $orderUserId, $vendorName . ' declined your order #' . $orderId . '.', $link);
                        if ($aggregate === 'Cancelled') {
                            lyaideu_notify($orderId, 'user', $orderUserId, 'Your order #' . $orderId . ' was cancelled because all vendors declined it.', $link);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore transition errors.
            }
            if (!$performed) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That action couldn\'t be completed — the order may have already been updated.'];
            }
        }
        header('Location: vendor');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Location: vendor');
        exit;
    }

    $queue = [];
    try {
        $rows = $pdo->prepare(
            'SELECT DISTINCT o.id, o.customer_name, o.phone, o.address, o.note, o.payment, o.status, o.total,
                    o.created_at, o.vendor_id, o.rider_id, ovs.status AS vendor_status,
                    r.name AS rider_name, r.phone AS rider_phone
             FROM orders o
             JOIN order_vendor_status ovs ON ovs.order_id = o.id AND ovs.vendor_id = :vid
             LEFT JOIN riders r ON r.id = o.rider_id
             WHERE ovs.status IN ("Pending", "Accepted", "Preparing", "Ready for pickup")
             ORDER BY FIELD(ovs.status, "Pending", "Accepted", "Preparing", "Ready for pickup"), o.created_at ASC'
        );
        $rows->execute([':vid' => $vendorId]);
        $orders = $rows->fetchAll();

        $itemStmt = $pdo->prepare('SELECT name, qty, line_total, vendor_id FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $row) {
            $itemStmt->execute([(int)$row['id']]);
            $items = $itemStmt->fetchAll();
            $primary = (int)$row['vendor_id'] === $vendorId;
            $row['items'] = array_values(array_filter($items, function ($it) use ($vendorId, $primary) {
                $vid = (int)$it['vendor_id'];
                if ($vid > 0) {
                    return $vid === $vendorId;
                }
                return $primary;
            }));
            $queue[] = $row;
        }
    } catch (Throwable $e) {
        $queue = [];
    }

    delivery_header('Vendor Dashboard', 'Your Kitchen Queue', 'fa-store', $role);

    $completed = [];
    try {
        $rows = $pdo->prepare(
            'SELECT DISTINCT o.id, o.customer_name, o.phone, o.address, o.status, o.total, o.created_at,
                    r.name AS rider_name
             FROM orders o
             LEFT JOIN riders r ON r.id = o.rider_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status IN ("Out for delivery", "Delivered", "Cancelled")
               AND (o.vendor_id = :vid1 OR oi.vendor_id = :vid2)
             ORDER BY o.created_at DESC LIMIT 20'
        );
        $rows->execute([':vid1' => $vendorId, ':vid2' => $vendorId]);
        $completed = $rows->fetchAll();
    } catch (Throwable $e) {
        $completed = [];
    }

if ($flash) {
        $ftype = ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success';
        $ficon = $ftype === 'error' ? 'fa-circle-xmark' : 'fa-circle-check';
        echo '<div class="delivery-toast flash-banner flash-' . $ftype . '" data-auto-dismiss="1" role="status"><i class="fa-solid ' . $ficon . '"></i> ' . delivery_esc($flash['msg']) . '</div>';
    }

    echo '<div class="delivery-toolbar">';
    echo '<div class="admin-order-search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" placeholder="Search order #, customer, phone, address or item…" aria-label="Search orders" data-order-search></div>';
    echo '<span class="delivery-count" data-result-count aria-live="polite"></span>';
    echo '</div>';

    echo '<div id="deliveryQueue">';
    echo '<div class="delivery-stats" role="group" aria-label="Filter orders by status">';
    $stats = ['Pending' => 0, 'Accepted' => 0, 'Preparing' => 0, 'Ready for pickup' => 0];
    foreach ($queue as $q) {
        $stats[$q['vendor_status']] = ($stats[$q['vendor_status']] ?? 0) + 1;
    }
    $total = count($queue);
    $inProgress = $stats['Accepted'] + $stats['Preparing'];
    echo '<button type="button" class="delivery-stat stat-all active" data-stat-filter="all" aria-pressed="true"><span class="stat-ico"><i class="fa-solid fa-layer-group"></i></span><strong>' . $total . '</strong><span>All orders</span></button>';
    echo '<button type="button" class="delivery-stat stat-pending" data-stat-filter="pending" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-bell"></i></span><strong>' . $stats['Pending'] . '</strong><span>New</span></button>';
    echo '<button type="button" class="delivery-stat stat-progress" data-stat-filter="progress" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-fire-burner"></i></span><strong>' . $inProgress . '</strong><span>In progress</span></button>';
    echo '<button type="button" class="delivery-stat stat-ready" data-stat-filter="ready" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-bag-shopping"></i></span><strong>' . $stats['Ready for pickup'] . '</strong><span>Ready</span></button>';
    echo '</div>';

    if (!$queue) {
        echo '<div class="empty-state"><span class="big"><i class="fa-solid fa-store"></i></span><h3>You\'re all caught up</h3><p>No active orders right now. New orders will appear here automatically — keep this page open and you\'ll get a notification.</p></div>';
    } else {
        echo '<div class="delivery-list-wrap"><div class="delivery-list">';
        foreach ($queue as $o):
            $pill = match ($o['vendor_status']) {
                'Accepted' => 'confirmed',
                'Preparing' => 'preparing',
                'Ready for pickup' => 'ready',
                default => 'pending',
            };
            $filter = $o['vendor_status'] === 'Ready for pickup' ? 'ready' : ($o['vendor_status'] === 'Pending' ? 'pending' : 'progress');
            $statusIcon = match ($o['vendor_status']) {
                'Accepted' => 'fa-circle-check',
                'Preparing' => 'fa-fire-burner',
                'Ready for pickup' => 'fa-bag-shopping',
                default => 'fa-bell',
            };
            $payIcon = $o['payment'] === 'Cash on Delivery' ? 'fa-money-bill-wave' : 'fa-wallet';
            $payClass = $o['payment'] === 'Cash on Delivery' ? 'cash' : 'wallet';
            $payLabel = $o['payment'] === 'Cash on Delivery' ? 'Cash on delivery' : 'Digital wallet';
            $searchText = (int)$o['id'] . ' ' . $o['customer_name'] . ' ' . $o['phone'] . ' ' . $o['address'] . ' ' . $o['note'] . ' ' . $o['payment'] . ' ' . implode(' ', array_map(fn($it) => $it['name'], $o['items']));
            ?>
            <article class="delivery-card status-<?= $pill ?><?= $o['vendor_status'] === 'Pending' ? ' is-new' : '' ?>"
                     data-order-id="<?= (int)$o['id'] ?>"
                     data-status="<?= $filter ?>"
                     data-search="<?= delivery_esc($searchText) ?>">
                <div class="delivery-card-head">
                    <div>
                        <h2>Order #<?= (int)$o['id'] ?>
                            <span class="order-status-pill status-<?= $pill ?>"><i class="fa-solid <?= $statusIcon ?>"></i> <?= delivery_esc($o['vendor_status']) ?></span>
                        </h2>
                        <p><span data-rel-time data-ts="<?= (int)strtotime((string)$o['created_at']) ?>"></span> · <?= delivery_esc($o['created_at']) ?></p>
                    </div>
                    <div class="delivery-total-wrap">
                        <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                        <span class="delivery-pay <?= $payClass ?>"><i class="fa-solid <?= $payIcon ?>"></i> <?= $payLabel ?></span>
                    </div>
                </div>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <a href="tel:+977<?= delivery_esc($o['phone']) ?>">+977 <?= delivery_esc($o['phone']) ?></a></p>
                <p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?><?php if ($o['note']): ?> · <i class="fa-solid fa-note-sticky"></i> <?= delivery_esc($o['note']) ?><?php endif; ?></p>
                <div class="delivery-items">
                    <?php foreach ($o['items'] as $it): ?>
                    <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                    <?php endforeach; ?>
                    <span class="delivery-item-count"><i class="fa-solid fa-receipt"></i> <?= count($o['items']) ?> item<?= count($o['items']) === 1 ? '' : 's' ?></span>
                </div>
                <?php if ($o['rider_id']): ?>
                <p class="delivery-rider"><i class="fa-solid fa-motorcycle"></i> <?= delivery_esc($o['rider_name'] ?? 'Rider') ?> · <a href="tel:+977<?= delivery_esc($o['rider_phone'] ?? '') ?>">+977 <?= delivery_esc($o['rider_phone'] ?? '') ?></a></p>
                <?php endif; ?>
                <div class="delivery-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <?php if ($o['vendor_status'] === 'Pending'): ?>
                        <button type="submit" name="order_action" value="Accepted" class="btn btn-accept"><i class="fa-solid fa-circle-check"></i> Accept order</button>
                        <button type="submit" name="order_action" value="Rejected" class="btn btn-reject" data-confirm="Reject order #<?= (int)$o['id'] ?>? It will be removed from your queue."><i class="fa-solid fa-xmark"></i> Reject</button>
                        <?php elseif ($o['vendor_status'] === 'Accepted'): ?>
                        <button type="submit" name="order_action" value="Preparing" class="btn btn-primary"><i class="fa-solid fa-fire-burner"></i> Start preparing</button>
                        <?php elseif ($o['vendor_status'] === 'Preparing'): ?>
                        <button type="submit" name="order_action" value="Ready for pickup" class="btn btn-primary"><i class="fa-solid fa-bag-shopping"></i> Mark ready for pickup</button>
                        <?php endif; ?>
                    </form>
                </div>
            </article>
        <?php endforeach;
        echo '</div>';
        echo '<div class="empty-state delivery-none" data-no-results style="display:none"><span class="big"><i class="fa-solid fa-filter-circle-xmark"></i></span><h3>No matching orders</h3><p>No orders match your filter or search. Try another status or clear your search.</p></div>';
        echo '</div>';
    }
    echo '</div>';

    if ($completed) {
        echo '<section class="delivery-section"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Completed Orders</h2><div class="delivery-list">';
        foreach ($completed as $o):
            $pill = match ($o['status']) {
                'Delivered' => 'delivered',
                'Cancelled' => 'cancelled',
                default => 'delivery',
            };
            ?>
            <article class="delivery-card status-<?= $pill ?>">
                <div class="delivery-card-head">
                    <div><h2>Order #<?= (int)$o['id'] ?> <span class="order-status-pill status-<?= $pill ?>"><?= delivery_esc($o['status']) ?></span></h2><p><?= delivery_esc($o['created_at']) ?></p></div>
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                </div>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?></p>
                <?php if ($o['rider_name']): ?><p class="delivery-rider"><i class="fa-solid fa-motorcycle"></i> Delivered by <?= delivery_esc($o['rider_name']) ?></p><?php endif; ?>
            </article>
        <?php endforeach;
        echo '</div></section>';
    }

    echo '<script>
(function(){
  var activeFilter = "all";
  try { var saved = localStorage.getItem("lyaidu_vendor_filter"); if (saved) activeFilter = saved; } catch(e){}

  function searchValue(){
    var el = document.querySelector("[data-order-search]");
    return el ? (el.value || "").trim().toLowerCase() : "";
  }
  function timeAgo(ts){
    var s = Math.floor(Date.now()/1000) - ts;
    if (s < 45) return "just now";
    if (s < 90) return "1 min ago";
    if (s < 3600) return Math.floor(s/60) + " min ago";
    if (s < 86400) return Math.floor(s/3600) + " hr ago";
    return Math.floor(s/86400) + " days ago";
  }
  function tickTimes(){
    document.querySelectorAll("[data-rel-time]").forEach(function(el){
      var ts = parseInt(el.getAttribute("data-ts") || "0", 10);
      if (ts) el.textContent = timeAgo(ts);
    });
  }
  function applyFilter(){
    var queue = document.getElementById("deliveryQueue");
    if (!queue) return;
    var cards = queue.querySelectorAll(".delivery-list .delivery-card[data-status]");
    var total = cards.length, visible = 0;
    var q = searchValue();
    cards.forEach(function(card){
      var show = activeFilter === "all" || card.getAttribute("data-status") === activeFilter;
      if (show && q) show = (card.getAttribute("data-search") || "").toLowerCase().indexOf(q) !== -1;
      card.style.display = show ? "" : "none";
      if (show) visible++;
    });
    var none = queue.querySelector("[data-no-results]");
    if (none) none.style.display = (total > 0 && visible === 0) ? "" : "none";
    var cnt = document.querySelector("[data-result-count]");
    if (cnt) cnt.innerHTML = visible === total
      ? "<b>" + total + "</b> order" + (total === 1 ? "" : "s")
      : "Showing <b>" + visible + "</b> of <b>" + total + "</b>";
    queue.querySelectorAll("[data-stat-filter]").forEach(function(b){
      var on = b.getAttribute("data-stat-filter") === activeFilter;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }

  document.addEventListener("click", function(e){
    var btn = e.target && e.target.closest ? e.target.closest("[data-stat-filter]") : null;
    if (btn) {
      activeFilter = btn.getAttribute("data-stat-filter") || "all";
      try { localStorage.setItem("lyaidu_vendor_filter", activeFilter); } catch(err){}
      applyFilter();
      return;
    }
    var rj = e.target && e.target.closest ? e.target.closest("[data-confirm]") : null;
    if (rj && !window.confirm(rj.getAttribute("data-confirm") || "Are you sure?")) e.preventDefault();
  });
  document.addEventListener("input", function(e){
    if (e.target && e.target.hasAttribute && e.target.hasAttribute("data-order-search")) applyFilter();
  });

  if (window.MutationObserver) {
    var queueEl = document.getElementById("deliveryQueue");
    var mo = new MutationObserver(function(){
      var cur = document.getElementById("deliveryQueue");
      if (cur !== queueEl) { queueEl = cur; applyFilter(); tickTimes(); }
    });
    mo.observe(document.body, { childList: true, subtree: true });
  }

  var toast = document.querySelector("[data-auto-dismiss]");
  if (toast) {
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add("show"); }); });
    setTimeout(function(){ toast.classList.add("hide"); setTimeout(function(){ toast.remove(); }, 350); }, 5000);
  }

  applyFilter();
  tickTimes();
  setInterval(tickTimes, 30000);
})();
</script>';

    delivery_footer();
    exit;
}
