<?php
require_once __DIR__ . '/delivery_inc.php';

$pdo = lyaideu_load_pdo();
$role = 'rider';
delivery_require_login($role);
$user = delivery_user();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($user) {
    delivery_logout();
    $riderId = (int)$user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_action'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $newStatus = trim((string)($_POST['order_action']));
            try {
                $st = $pdo->prepare('SELECT status, rider_id FROM orders WHERE id = ? LIMIT 1');
                $st->execute([$orderId]);
                $order = $st->fetch();
                $valid = $order && (int)$order['rider_id'] === $riderId;
                if ($valid && $newStatus === 'Out for delivery' && $order['status'] === 'Ready for pickup') {
                    $upd = $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?');
                    $upd->execute([$newStatus, date('Y-m-d H:i:s'), $orderId]);
                } elseif ($valid && $newStatus === 'Delivered' && $order['status'] === 'Out for delivery') {
                    $upd = $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?');
                    $upd->execute([$newStatus, date('Y-m-d H:i:s'), $orderId]);
                }
            } catch (Throwable $e) {
                // Ignore transition errors.
            }
        }
        header('Location: rider');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Location: rider');
        exit;
    }

    $queue = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.phone, o.address, o.note, o.payment, o.status, o.total,
                    o.created_at, o.rider_id, v.name AS vendor_name, v.phone AS vendor_phone
             FROM orders o
             LEFT JOIN vendors v ON v.id = o.vendor_id
             WHERE o.rider_id = :rid AND o.status IN ("Ready for pickup", "Out for delivery")
             ORDER BY FIELD(o.status, "Ready for pickup", "Out for delivery"), o.created_at ASC'
        );
        $rows->execute([':rid' => $riderId]);
        $orders = $rows->fetchAll();

        $itemStmt = $pdo->prepare('SELECT name, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $row) {
            $itemStmt->execute([(int)$row['id']]);
            $row['items'] = $itemStmt->fetchAll();
            $queue[] = $row;
        }
    } catch (Throwable $e) {
        $queue = [];
    }

    delivery_header('Rider Dashboard', 'Your Delivery Queue', 'fa-motorcycle', $role);

    echo '<div id="deliveryQueue">';
    echo '<div class="delivery-stats">';
    $ready = count(array_filter($queue, fn($q) => $q['status'] === 'Ready for pickup'));
    $out = count(array_filter($queue, fn($q) => $q['status'] === 'Out for delivery'));
    echo '<div><strong>' . $ready . '</strong><span>Ready to pick up</span></div>';
    echo '<div><strong>' . $out . '</strong><span>On the way</span></div>';
    echo '</div>';

    if (!$queue) {
        echo '<div class="empty-state"><span class="big"><i class="fa-solid fa-motorcycle"></i></span><p>No deliveries assigned yet. Orders you are assigned to will show up here.</p></div>';
    } else {
        echo '<div class="delivery-list">';
        foreach ($queue as $o):
            $pill = $o['status'] === 'Ready for pickup' ? 'ready' : 'delivery';
            ?>
            <article class="delivery-card status-<?= $pill ?>" data-order-id="<?= (int)$o['id'] ?>">
                <div class="delivery-card-head">
                    <div>
                        <h2>Order #<?= (int)$o['id'] ?> <span class="order-status-pill status-<?= $pill ?>"><?= delivery_esc($o['status']) ?></span></h2>
                        <p><?= delivery_esc($o['created_at']) ?></p>
                    </div>
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                </div>
                <?php if ($o['vendor_name']): ?>
                <p class="delivery-rider"><i class="fa-solid fa-store"></i> <?= delivery_esc($o['vendor_name']) ?> · <a href="tel:+977<?= delivery_esc($o['vendor_phone'] ?? '') ?>">+977 <?= delivery_esc($o['vendor_phone'] ?? '') ?></a></p>
                <?php endif; ?>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <a href="tel:+977<?= delivery_esc($o['phone']) ?>">+977 <?= delivery_esc($o['phone']) ?></a></p>
                <p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?><?php if ($o['note']): ?> · <i class="fa-solid fa-note-sticky"></i> <?= delivery_esc($o['note']) ?><?php endif; ?></p>
                <div class="delivery-items">
                    <?php foreach ($o['items'] as $it): ?>
                    <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="delivery-actions">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <?php if ($o['status'] === 'Ready for pickup'): ?>
                        <button type="submit" name="order_action" value="Out for delivery" class="btn btn-primary">Pick up & start delivery</button>
                        <?php else: ?>
                        <button type="submit" name="order_action" value="Delivered" class="btn btn-primary">Mark as delivered</button>
                        <?php endif; ?>
                    </form>
                </div>
            </article>
        <?php endforeach;
        echo '</div>';
    }
    echo '</div>';

    $completed = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.address, o.status, o.total, o.created_at
             FROM orders o
             WHERE o.rider_id = :rid AND o.status = "Delivered"
             ORDER BY o.created_at DESC LIMIT 20'
        );
        $rows->execute([':rid' => $riderId]);
        $completed = $rows->fetchAll();
    } catch (Throwable $e) {
        $completed = [];
    }

    if ($completed) {
        echo '<section class="delivery-section"><h2><i class="fa-solid fa-circle-check"></i> Recently Delivered</h2><div class="delivery-list">';
        foreach ($completed as $o):
            ?>
            <article class="delivery-card status-delivered">
                <div class="delivery-card-head">
                    <div><h2>Order #<?= (int)$o['id'] ?> <span class="order-status-pill status-delivered"><?= delivery_esc($o['status']) ?></span></h2><p><?= delivery_esc($o['created_at']) ?></p></div>
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                </div>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?></p>
            </article>
        <?php endforeach;
        echo '</div></section>';
    }

    delivery_footer();
    exit;
}
