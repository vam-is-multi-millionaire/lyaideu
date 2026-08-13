<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

$allowed = ['Pending', 'Confirmed', 'Preparing', 'Out for delivery', 'Delivered', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $id = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($id > 0 && in_array($status, $allowed, true)) {
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
        'SELECT id, user_id, customer_name, phone, address, note, payment, promo,
                subtotal, delivery_fee, discount, total, status, created_at, updated_at
         FROM orders
         ORDER BY created_at DESC'
    )->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT name, hotel, price, qty, line_total
         FROM order_items
         WHERE order_id = :order_id
         ORDER BY id'
    );

    foreach ($rows as $row) {
        $itemStmt->execute([':order_id' => (int)$row['id']]);
        $row['items'] = $itemStmt->fetchAll();
        $row['created'] = $row['created_at'];
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
?>
    <div class="admin-stats">
        <div><strong><?= count($orders) ?></strong><span>Total Orders</span></div>
        <?php foreach ($counts as $k => $v): ?>
        <div><strong><?= $v ?></strong><span><?= htmlspecialchars($k) ?></span></div>
        <?php endforeach; ?>
    </div>

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Review customer orders and update their delivery status.</p>
        </div>
        <div class="admin-order-list">
            <?php if (!$orders): ?>
            <div class="admin-card">
                <h3>No orders yet.</h3>
                <p class="small-note">Orders placed by customers will appear here.</p>
            </div>
            <?php endif; ?>

            <?php foreach ($orders as $o): ?>
            <article class="admin-order-card">
                <div class="order-card-head">
                    <div>
                        <h2>#<?= (int)$o['id'] ?> · <?= htmlspecialchars($o['customer_name']) ?></h2>
                        <p><?= htmlspecialchars($o['created']) ?> · <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($o['phone']) ?></p>
                    </div>
                    <form method="POST" class="status-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <select name="status">
                            <?php foreach (array_keys($counts) as $st): ?>
                            <option <?= $o['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" type="submit">Update</button>
                    </form>
                </div>
                <p><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['address']) ?><?php if ($o['note']): ?> · <i class="fa-solid fa-note-sticky"></i> <?= htmlspecialchars($o['note']) ?><?php endif; ?></p>
                <div class="admin-order-items">
                    <?php foreach ($o['items'] as $it): ?>
                    <span><?= htmlspecialchars($it['name']) ?> × <?= (int)$it['qty'] ?> — Rs. <?= (int)$it['line_total'] ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="summary-row total">
                    <span>Total · <?= htmlspecialchars($o['payment']) ?></span>
                    <strong>Rs. <?= (int)$o['total'] ?></strong>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php
admin_page_end();
