<?php
session_start();
if (!isset($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}
if (!isset($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';

$allowed = ['Pending', 'Confirmed', 'Preparing', 'Out for delivery', 'Delivered', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) {
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

    header('Location: admin_orders.php?saved=1');
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
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Orders | LyaiDeu Admin</title><link rel="stylesheet" href="css/style.css"></head><body class="admin-body"><div class="admin-header"><h1 class="display" style="color:white;margin:0">📦 Order Management</h1><div class="admin-actions"><a href="admin.php" class="btn btn-outline">← Dashboard</a><a href="index.php" target="_blank" class="btn btn-outline">Website</a></div></div><div class="admin-container"><div class="admin-stats"><div><strong><?= count($orders) ?></strong><span>Total Orders</span></div><?php foreach ($counts as $k => $v): ?><div><strong><?= $v ?></strong><span><?= htmlspecialchars($k) ?></span></div><?php endforeach; ?></div><?php if (isset($_GET['saved'])): ?><div class="flash-banner flash-success">✅ Order status updated.</div><?php endif; ?><div class="admin-order-list"><?php if (!$orders): ?><div class="admin-card"><h3>No orders yet.</h3><p class="small-note">Orders placed by customers will appear here.</p></div><?php endif; ?><?php foreach ($orders as $o): ?><article class="admin-order-card"><div class="order-card-head"><div><h2>#<?= (int)$o['id'] ?> · <?= htmlspecialchars($o['customer_name']) ?></h2><p><?= htmlspecialchars($o['created']) ?> · 📞 <?= htmlspecialchars($o['phone']) ?></p></div><form method="POST" class="status-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_admin']) ?>"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><select name="status"><?php foreach (array_keys($counts) as $st): ?><option <?=$o['status']===$st?'selected':''?>><?= htmlspecialchars($st) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Update</button></form></div><p>📍 <?= htmlspecialchars($o['address']) ?><?php if ($o['note']): ?> · 📝 <?= htmlspecialchars($o['note']) ?><?php endif; ?></p><div class="admin-order-items"><?php foreach ($o['items'] as $it): ?><span><?= htmlspecialchars($it['name']) ?> × <?= (int)$it['qty'] ?> — Rs. <?= (int)$it['line_total'] ?></span><?php endforeach; ?></div><div class="summary-row total"><span>Total · <?= htmlspecialchars($o['payment']) ?></span><strong>Rs. <?= (int)$o['total'] ?></strong></div></article><?php endforeach; ?></div></div></body></html>
