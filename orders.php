<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login');
    exit;
}
$user = $_SESSION['user'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

$uid = (int)$_SESSION['user']['id'];
$orders = [];

$orderStmt = $pdo->prepare(
    'SELECT id, created_at
     FROM orders
     WHERE user_id = :user_id
     ORDER BY created_at DESC'
);
$orderStmt->execute([':user_id' => $uid]);
$rows = $orderStmt->fetchAll();

foreach ($rows as $row) {
    $track = lyaideu_order_tracking((int)$row['id']);
    if (!$track) {
        continue;
    }
    $track['created'] = $row['created_at'];
    $orders[] = $track;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title>My Orders | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=18">
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="orders" class="nav-a active">Orders</a></li>
            <?php if ($user): ?>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"<?= !empty($user['avatar']) ? ' style="background-image:url(\'' . htmlspecialchars($user['avatar'], ENT_QUOTES, 'UTF-8') . '\')"' : '' ?>><?= empty($user['avatar']) ? htmlspecialchars($initials) : '' ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($user['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($user['phone']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($user['dob']) ?></p>
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
</header>

<?php if ($flash): ?>
    <div class="flash-banner flash-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>
<main class="orders-page container" data-live-orders>
<span data-live-indicator class="live-indicator" title="Checks for updates automatically">● Live updates</span>
<div class="section-head">
    <p class="kicker"><i class="fa-solid fa-box"></i> Your activity</p>
    <h1 class="display">My Orders</h1>
    <p class="section-sub">Track current orders and revisit previous ones. <span class="live-badge">● Live updates</span></p>
</div>
<?php if (!$orders): ?>
<div class="empty-state" style="display:block"><span class="big"><i class="fa-solid fa-motorcycle"></i></span><p>You haven't placed an order yet.</p><a class="btn btn-primary" href="menu">Start Ordering</a></div>
<?php endif; ?>
<div class="orders-list">
<?php foreach ($orders as $o): $cls = lyaideu_order_pill_class($o['status']); ?>
<article class="order-card" data-order-id="<?= (int)$o['id'] ?>">
    <div class="order-card-head">
        <div><h2>Order #<?= (int)$o['id'] ?></h2><p><?= htmlspecialchars($o['created']) ?></p></div>
        <span class="order-status-pill status-<?= $cls ?>"><?= htmlspecialchars($o['status']) ?></span>
    </div>
    <?= lyaideu_order_track_html($o['status']) ?>
    <?php foreach ($o['vendors'] as $v): ?><?= lyaideu_order_vendor_html($v) ?><?php endforeach; ?>
    <?php if (!empty($o['other_items'])): ?><?= lyaideu_order_other_html($o['other_items']) ?><?php endif; ?>
    <?= lyaideu_order_delivery_html($o) ?>
    <div class="summary-row"><span>Subtotal</span><strong>Rs. <?= (int)$o['subtotal'] ?></strong></div>
    <div class="summary-row"><span>Delivery</span><strong>Rs. <?= max(0, (int)$o['delivery_fee'] - (int)$o['discount']) ?></strong></div>
    <?php if (!empty($o['eta_minutes'])): ?>
    <div class="summary-row"><span>Estimated delivery</span><strong>about <?= (int)$o['eta_minutes'] ?> min<?= (count($o['vendors']) + (!empty($o['other_items']) ? 1 : 0)) > 1 ? ' · ' . (count($o['vendors']) + (!empty($o['other_items']) ? 1 : 0)) . ' vendors' : '' ?></strong></div>
    <?php endif; ?>
    <div class="summary-row total"><span>Total</span><strong>Rs. <?= (int)$o['total'] ?></strong></div>
    <p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['address']) ?> · <i class="fa-solid fa-credit-card"></i> <?= htmlspecialchars($o['payment']) ?></p>
</article>
<?php endforeach; ?>
</div>
</main>
<script src="js/script.js?v=18"></script><script src="js/notify.js?v=4"></script></body></html>
