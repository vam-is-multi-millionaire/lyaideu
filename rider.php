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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $claimId = (int)$_POST['claim'];
            $upd = $pdo->prepare(
                "UPDATE orders SET rider_id = :rid, updated_at = :now
                 WHERE id = :oid AND rider_id IS NULL AND status = 'Ready for pickup'"
            );
            $upd->execute([
                ':rid' => $riderId,
                ':now' => date('Y-m-d H:i:s'),
                ':oid' => $claimId,
            ]);
            if ($upd->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Order #' . $claimId . ' is yours — go pick it up!'];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That order was just taken by another rider.'];
            }
        }
        header('Location: rider');
        exit;
    }

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
    $pool = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.phone, o.address, o.note, o.payment, o.status, o.total,
                    o.created_at, o.rider_id, o.delivery_lat, o.delivery_lng, v.name AS vendor_name, v.phone AS vendor_phone
             FROM orders o
             LEFT JOIN vendors v ON v.id = o.vendor_id
             WHERE (o.rider_id = :rid AND o.status IN ("Pending", "Accepted", "Preparing", "Ready for pickup", "Out for delivery"))
                OR (o.rider_id IS NULL AND o.status = "Ready for pickup")
             ORDER BY FIELD(o.status, "Pending", "Accepted", "Preparing", "Ready for pickup", "Out for delivery"), o.created_at ASC'
        );
        $rows->execute([':rid' => $riderId]);
        $orders = $rows->fetchAll();

        $itemStmt = $pdo->prepare('SELECT name, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $row) {
            $itemStmt->execute([(int)$row['id']]);
            $row['items'] = $itemStmt->fetchAll();
            $claimable = (int)$row['rider_id'] === 0 || $row['rider_id'] === null;
            $row['claimable'] = $claimable;
            if ($claimable) {
                $pool[] = $row;
            } else {
                $queue[] = $row;
            }
        }
    } catch (Throwable $e) {
        $queue = [];
        $pool = [];
    }

    delivery_header('Rider Dashboard', 'Your Delivery Queue', 'fa-motorcycle', $role);

    $card = function (array $o) use ($riderId): void {
        $pill = match ($o['status']) {
            'Pending' => 'pending',
            'Accepted' => 'confirmed',
            'Preparing' => 'preparing',
            'Ready for pickup' => 'ready',
            default => 'delivery',
        };
        $claimable = !empty($o['claimable']);
        ?>
        <article class="delivery-card status-<?= $pill ?><?= $claimable ? ' claimable' : '' ?>" data-order-id="<?= (int)$o['id'] ?>">
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
            <?php if ($o['delivery_lat'] !== null && $o['delivery_lat'] !== '' && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''): ?>
            <div class="rider-map" data-lat="<?= delivery_esc($o['delivery_lat']) ?>" data-lng="<?= delivery_esc($o['delivery_lng']) ?>"></div>
            <a class="btn btn-outline btn-sm" href="https://www.google.com/maps/dir/?api=1&destination=<?= delivery_esc($o['delivery_lat']) ?>,<?= delivery_esc($o['delivery_lng']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Get Directions</a>
            <?php endif; ?>
            <div class="delivery-items">
                <?php foreach ($o['items'] as $it): ?>
                <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                <?php endforeach; ?>
            </div>
            <div class="delivery-actions">
                <?php if ($claimable): ?>
                <p class="delivery-waiting"><i class="fa-solid fa-bullhorn"></i> This order is ready — be the first rider to accept it.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                    <button type="submit" name="claim" value="<?= (int)$o['id'] ?>" class="btn btn-primary"><i class="fa-solid fa-hand-pointer"></i> Accept order</button>
                </form>
                <?php elseif ($o['status'] === 'Pending' || $o['status'] === 'Accepted' || $o['status'] === 'Preparing'): ?>
                <p class="delivery-waiting"><i class="fa-solid fa-hourglass-half"></i> Waiting for the vendor to prepare this order — you'll pick it up when it's ready.</p>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <?php if ($o['status'] === 'Ready for pickup'): ?>
                    <button type="submit" name="order_action" value="Out for delivery" class="btn btn-primary">Pick up & start delivery</button>
                    <?php else: ?>
                    <button type="submit" name="order_action" value="Delivered" class="btn btn-primary">Mark as delivered</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php
    };

    echo '<div id="deliveryQueue">';
    echo '<div class="delivery-stats">';
    $available = count($pool);
    $ready = count(array_filter($queue, fn($q) => $q['status'] === 'Ready for pickup'));
    $out = count(array_filter($queue, fn($q) => $q['status'] === 'Out for delivery'));
    echo '<div><strong>' . $available . '</strong><span>Available to pick up</span></div>';
    echo '<div><strong>' . $ready . '</strong><span>Ready</span></div>';
    echo '<div><strong>' . $out . '</strong><span>On the way</span></div>';
    echo '</div>';

    if (!$pool && !$queue) {
        echo '<div class="empty-state"><span class="big"><i class="fa-solid fa-motorcycle"></i></span><p>No orders available right now. When a vendor marks an order as ready, every rider gets notified and the first to accept picks it up.</p></div>';
    } else {
        if ($pool) {
            echo '<section class="delivery-section"><h2><i class="fa-solid fa-bullhorn"></i> Available to pick up <span class="small-note">— first rider to accept takes it</span></h2><div class="delivery-list">';
            foreach ($pool as $o) {
                $card($o);
            }
            echo '</div></section>';
        }
        if ($queue) {
            echo '<section class="delivery-section"><h2><i class="fa-solid fa-motorcycle"></i> My deliveries</h2><div class="delivery-list">';
            foreach ($queue as $o) {
                $card($o);
            }
            echo '</div></section>';
        }
    }
    echo '</div>';

    $completed = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.address, o.status, o.total, o.created_at, o.delivery_lat, o.delivery_lng
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
                <?php if ($o['delivery_lat'] !== null && $o['delivery_lat'] !== '' && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''): ?>
                <a class="btn btn-outline btn-sm" href="https://www.google.com/maps/dir/?api=1&destination=<?= delivery_esc($o['delivery_lat']) ?>,<?= delivery_esc($o['delivery_lng']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Get Directions</a>
                <?php endif; ?>
            </article>
        <?php endforeach;
        echo '</div></section>';
    }

    delivery_footer();
    exit;
}
