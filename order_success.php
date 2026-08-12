<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

$id = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user']['id'];
$order = null;
$items = [];

if ($id > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, customer_name, phone, address, note, payment, promo,
                subtotal, delivery_fee, discount, total, status, created_at, updated_at
         FROM orders
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':user_id' => $uid]);
    $order = $stmt->fetch();

    if ($order) {
        $itemStmt = $pdo->prepare(
            'SELECT dish_id, name, hotel, price, qty, line_total
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id'
        );
        $itemStmt->execute([':order_id' => $id]);
        $items = $itemStmt->fetchAll();
    }
}

if (!$order) {
    header('Location: orders.php');
    exit;
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Order #<?= (int)$order['id'] ?> | LyaiDeu</title><?= site_head_icons() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6"></head><body class="checkout-body"><header class="topbar"><nav class="nav"><a class="brand" href="index.php"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"> Lyai<span>Deu</span></a><form class="nav-search" action="menu.php" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search momo, pizza, hotels…" aria-label="Search the menu"></form><a class="btn btn-outline" href="orders.php">My Orders</a></nav></header><main class="success-page container"><div class="success-icon"><i class="fa-solid fa-circle-check"></i></div><p class="kicker">Order placed</p><h1 class="display">Thanks, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</h1><p class="section-sub">Your order <strong>#<?= (int)$order['id'] ?></strong> has been received.</p><div class="success-card"><div class="order-status-pill status-pending"><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($order['status']) ?></div><h2>Order summary</h2><?php foreach ($items as $it): ?><div class="summary-row"><span><?= htmlspecialchars($it['name']) ?> × <?= (int)$it['qty'] ?></span><strong>Rs. <?= (int)$it['line_total'] ?></strong></div><?php endforeach; ?><hr><div class="summary-row"><span>Subtotal</span><strong>Rs. <?= (int)$order['subtotal'] ?></strong></div><div class="summary-row"><span>Delivery</span><strong>Rs. <?= (int)$order['delivery_fee'] - (int)$order['discount'] ?></strong></div><div class="summary-row total"><span>Total</span><strong>Rs. <?= (int)$order['total'] ?></strong></div><p class="small-note">Delivering to: <?= htmlspecialchars($order['address']) ?></p><div class="success-actions"><a class="btn btn-primary" href="orders.php">Track My Order</a><a class="btn btn-outline" href="menu.php">Order More</a></div></div></main><script>localStorage.removeItem('fe_cart');</script></body></html>
