<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

$parts = preg_split('/\s+/', trim($_SESSION['user']['name']));
$firstName = $parts[0] ?? '';
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

$id = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user']['id'];
$order = null;

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT id, user_id FROM orders WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute([':id' => $id, ':user_id' => $uid]);
    $owner = $stmt->fetch();
    if ($owner) {
        $track = lyaideu_order_tracking($id);
        if ($track) {
            $track['created'] = $track['created_at'];
            $order = $track;
        }
    }
}

if (!$order) {
    header('Location: orders');
    exit;
}
$orderId = (int)$order['id'];
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?><title>Order #<?= (int)$order['id'] ?> | LyaiDeu</title><?= site_head_icons() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=61"></head><body class="checkout-body"><header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
            <?php if (!empty($_SESSION['user'])): ?>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"<?= !empty($_SESSION['user']['avatar']) ? ' style="background-image:url(\'' . htmlspecialchars($_SESSION['user']['avatar'], ENT_QUOTES, 'UTF-8') . '\')"' : '' ?>><?= empty($_SESSION['user']['avatar']) ? htmlspecialchars($initials) : '' ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($_SESSION['user']['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($_SESSION['user']['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($_SESSION['user']['phone']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($_SESSION['user']['dob']) ?></p>
                        <?php if (!empty($_SESSION['is_admin'])): ?>
                            <a class="btn btn-outline btn-block" href="admin"><i class="fa-solid fa-gear"></i> Admin Panel</a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-block" href="profile" style="margin-top:.5rem;"><i class="fa-solid fa-user-gear"></i> My Profile</a>
                        <a class="btn btn-outline btn-block" href="orders" style="margin-top:.5rem;"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a class="btn btn-primary btn-block" href="logout" style="margin-top:.5rem; background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Log Out</a>
                    </div>
                </div>
            </li>
            <?php else: ?>
            <li><a class="nav-a nav-cta" href="login"><i class="fa-solid fa-right-to-bracket"></i> Login / Sign Up</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header><main class="success-page container"><div class="success-icon"><i class="fa-solid fa-circle-check"></i></div><p class="kicker">Order placed</p><h1 class="display">Thanks, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</h1><p class="section-sub">Your order <strong>#<?= (int)$order['id'] ?></strong> has been received.</p><div class="success-card" id="orderTracker" data-live-order="<?= $orderId ?>">
    <div class="order-card-head">
        <div><h2>Order #<?= $orderId ?></h2><p><?= htmlspecialchars($order['created']) ?></p></div>
        <span class="order-status-pill status-<?= lyaideu_order_pill_class($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span>
    </div>
    <?= lyaideu_order_track_html($order['status']) ?>
    <?php foreach ($order['vendors'] as $v): ?><?= lyaideu_order_vendor_html($v) ?><?php endforeach; ?>
    <?php if (!empty($order['other_items'])): ?><?= lyaideu_order_other_html($order['other_items']) ?><?php endif; ?>
    <?= lyaideu_order_delivery_html($order) ?>
    <div class="summary-row"><span>Subtotal</span><strong>Rs. <?= (int)$order['subtotal'] ?></strong></div>
    <div class="summary-row"><span>Delivery</span><strong>Rs. <?= max(0, (int)$order['delivery_fee'] - (int)$order['discount']) ?></strong></div>
    <div class="summary-row"><span>Estimated delivery</span><strong><i class="fa-solid fa-clock"></i> about <?= (int)$order['eta_minutes'] ?> min</strong></div>
    <div class="summary-row total"><span>Total</span><strong>Rs. <?= (int)$order['total'] ?></strong></div>
    <p class="small-note"><i class="fa-solid fa-location-dot"></i> Delivering to: <?= htmlspecialchars($order['address']) ?><?php if (!empty($order['delivery_lat']) && !empty($order['delivery_lng'])): ?> · <a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= htmlspecialchars((string)$order['delivery_lat']) ?>,<?= htmlspecialchars((string)$order['delivery_lng']) ?>">Open in Maps</a><?php endif; ?></p>
    <div class="success-actions"><a class="btn btn-primary" href="orders">Track My Order</a><a class="btn btn-outline" href="menu">Order More</a></div>
</div></main><script>localStorage.removeItem('fe_cart');</script><script src="js/script.js?v=37"></script><script src="js/scroll-memory.js?v=5"></script><script src="js/notify.js?v=8"></script></body></html>
