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

    echo '<div id="deliveryQueue">';
    echo '<div class="delivery-stats">';
    $stats = ['Pending' => 0, 'Accepted' => 0, 'Preparing' => 0, 'Ready for pickup' => 0];
    foreach ($queue as $q) {
        $stats[$q['vendor_status']] = ($stats[$q['vendor_status']] ?? 0) + 1;
    }
    echo '<div><strong>' . $stats['Pending'] . '</strong><span>New</span></div>';
    echo '<div><strong>' . $stats['Accepted'] + $stats['Preparing'] . '</strong><span>In progress</span></div>';
    echo '<div><strong>' . $stats['Ready for pickup'] . '</strong><span>Ready</span></div>';
    echo '</div>';

    if (!$queue) {
        echo '<div class="empty-state"><span class="big"><i class="fa-solid fa-store"></i></span><p>No active orders right now. New orders will appear here automatically.</p></div>';
    } else {
        echo '<div class="delivery-list">';
        foreach ($queue as $o):
            $pill = match ($o['vendor_status']) {
                'Accepted' => 'confirmed',
                'Preparing' => 'preparing',
                'Ready for pickup' => 'ready',
                default => 'pending',
            };
            ?>
            <article class="delivery-card status-<?= $pill ?>" data-order-id="<?= (int)$o['id'] ?>">
                <div class="delivery-card-head">
                    <div>
<h2>Order #<?= (int)$o['id'] ?> <span class="order-status-pill status-<?= $pill ?>"><?= delivery_esc($o['vendor_status']) ?></span></h2>
                        <p><?= delivery_esc($o['created_at']) ?></p>
                    </div>
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                </div>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <a href="tel:+977<?= delivery_esc($o['phone']) ?>">+977 <?= delivery_esc($o['phone']) ?></a></p>
                <p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?><?php if ($o['note']): ?> · <i class="fa-solid fa-note-sticky"></i> <?= delivery_esc($o['note']) ?><?php endif; ?></p>
                <div class="delivery-items">
                    <?php foreach ($o['items'] as $it): ?>
                    <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($o['rider_id']): ?>
                <p class="delivery-rider"><i class="fa-solid fa-motorcycle"></i> <?= delivery_esc($o['rider_name'] ?? 'Rider') ?> · <a href="tel:+977<?= delivery_esc($o['rider_phone'] ?? '') ?>">+977 <?= delivery_esc($o['rider_phone'] ?? '') ?></a></p>
                <?php endif; ?>
                <div class="delivery-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <?php if ($o['vendor_status'] === 'Pending'): ?>
                        <button type="submit" name="order_action" value="Accepted" class="btn btn-primary">Accept order</button>
                        <button type="submit" name="order_action" value="Rejected" class="btn btn-outline">Reject</button>
                        <?php elseif ($o['vendor_status'] === 'Accepted'): ?>
                        <button type="submit" name="order_action" value="Preparing" class="btn btn-primary">Start preparing</button>
                        <?php elseif ($o['vendor_status'] === 'Preparing'): ?>
                        <button type="submit" name="order_action" value="Ready for pickup" class="btn btn-primary">Mark ready for pickup</button>
                        <?php endif; ?>
                    </form>
                </div>
            </article>
        <?php endforeach;
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

    delivery_footer();
    exit;
}
