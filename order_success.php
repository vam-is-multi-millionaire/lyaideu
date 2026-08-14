<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

$id = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user']['id'];
$order = null;
$items = [];
$vendorCount = 1;

if ($id > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, customer_name, phone, address, note, payment, promo,
                subtotal, delivery_fee, eta_minutes, discount, total, status, created_at, updated_at,
                delivery_lat, delivery_lng
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
        $hotelNames = [];
        $hasHotel = false;
        foreach ($items as $it) {
            $hotelNames[$it['hotel']] = true;
            if (!empty($it['dish_id'])) {
                $hasHotel = true;
            }
        }
        $vendorCount = count($hotelNames);
        $etaFallback = lyaideu_delivery_eta($vendorCount, $hasHotel);
    }
}

if (!$order) {
    header('Location: orders');
    exit;
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?><title>Order #<?= (int)$order['id'] ?> | LyaiDeu</title><?= site_head_icons() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=14"></head><body class="checkout-body"><header class="topbar"><nav class="nav"><a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a><form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form><a class="btn btn-outline" href="orders">My Orders</a></nav></header><main class="success-page container"><div class="success-icon"><i class="fa-solid fa-circle-check"></i></div><p class="kicker">Order placed</p><h1 class="display">Thanks, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</h1><p class="section-sub">Your order <strong>#<?= (int)$order['id'] ?></strong> has been received.</p><div class="success-card"><div class="order-status-pill status-pending"><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($order['status']) ?></div><h2>Order summary</h2><?php foreach ($items as $it): ?><div class="summary-row"><span><?= htmlspecialchars($it['name']) ?> × <?= (int)$it['qty'] ?></span><strong>Rs. <?= (int)$it['line_total'] ?></strong></div><?php endforeach; ?><hr><div class="summary-row"><span>Subtotal</span><strong>Rs. <?= (int)$order['subtotal'] ?></strong></div><div class="summary-row"><span>Delivery</span><strong>Rs. <?= (int)$order['delivery_fee'] - (int)$order['discount'] ?></strong></div><div class="summary-row"><span>Estimated delivery</span><strong>about <?= (int)($order['eta_minutes'] ?? $etaFallback) ?> min<?= $vendorCount > 1 ? ' · ' . $vendorCount . ' vendors' : '' ?></strong></div><div class="summary-row total"><span>Total</span><strong>Rs. <?= (int)$order['total'] ?></strong></div><p class="small-note">Delivering to: <?= htmlspecialchars($order['address']) ?><?php if (!empty($order['delivery_lat']) && !empty($order['delivery_lng'])): ?> <a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= htmlspecialchars((string)$order['delivery_lat'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars((string)$order['delivery_lng'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-map-location-dot"></i> View on map</a><?php endif; ?></p><div class="success-actions"><a class="btn btn-primary" href="orders">Track My Order</a><a class="btn btn-outline" href="menu">Order More</a></div></div></main><script>localStorage.removeItem('fe_cart');</script></body></html>
