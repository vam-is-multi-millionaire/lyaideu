<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
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
    'SELECT id, customer_name, phone, address, note, payment, promo,
            subtotal, delivery_fee, discount, total, status, created_at, updated_at
     FROM orders
     WHERE user_id = :user_id
     ORDER BY created_at DESC'
);
$orderStmt->execute([':user_id' => $uid]);
$rows = $orderStmt->fetchAll();

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
}

$statusClass = [
    'Pending' => 'pending',
    'Confirmed' => 'confirmed',
    'Preparing' => 'preparing',
    'Out for delivery' => 'delivery',
    'Delivered' => 'delivered',
    'Cancelled' => 'cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Orders | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=8">
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index.php"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu.php" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" class="nav-a">Home</a></li>
            <li><a href="menu.php" class="nav-a">Menu</a></li>
            <li><a href="hotels.php" class="nav-a">Hotels</a></li>
            <li><a href="mart.php" class="nav-a">Mart</a></li>
            <li><a href="contact.php" class="nav-a">Contact</a></li>
            <li><a href="orders.php" class="nav-a active"><i class="fa-solid fa-box"></i> Orders</a></li>
            <?php if ($user): ?>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($user['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($user['phone']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($user['dob']) ?></p>
                        <?php if (!empty($_SESSION['is_admin'])): ?>
                            <a class="btn btn-outline btn-block" href="admin.php"><i class="fa-solid fa-gear"></i> Admin Panel</a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-block" href="orders.php" style="margin-top:.5rem;"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a class="btn btn-primary btn-block" href="logout.php" style="margin-top:.5rem; background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Log Out</a>
                    </div>
                </div>
            </li>
            <?php else: ?>
            <li><a class="nav-a nav-cta" href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Login / Sign Up</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="flash-banner flash-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>
<main class="orders-page container"><span data-live-indicator class="live-indicator" title="Checks for updates automatically">● Live updates</span><div class="section-head"><p class="kicker"><i class="fa-solid fa-box"></i> Your activity</p><h1 class="display">My Orders</h1><p class="section-sub">Track current orders and revisit previous ones. <span class="live-badge">● Live updates</span></p></div><?php if (!$orders): ?><div class="empty-state" style="display:block"><span class="big"><i class="fa-solid fa-motorcycle"></i></span><p>You haven't placed an order yet.</p><a class="btn btn-primary" href="menu.php">Start Ordering</a></div><?php endif; ?><div class="orders-list"><?php foreach ($orders as $o): $cls = $statusClass[$o['status']] ?? 'pending'; ?><article class="order-card"><div class="order-card-head"><div><h2>Order #<?= (int)$o['id'] ?></h2><p><?= htmlspecialchars($o['created']) ?></p></div><span class="order-status-pill status-<?= $cls ?>"><?= htmlspecialchars($o['status']) ?></span></div><div class="order-items"><?php foreach ($o['items'] as $it): ?><div class="summary-row"><span><?= htmlspecialchars($it['name']) ?> × <?= (int)$it['qty'] ?></span><strong>Rs. <?= (int)$it['line_total'] ?></strong></div><?php endforeach; ?></div><div class="summary-row total"><span>Total</span><strong>Rs. <?= (int)$o['total'] ?></strong></div><p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['address']) ?> · <i class="fa-solid fa-credit-card"></i> <?= htmlspecialchars($o['payment']) ?></p></article><?php endforeach; ?></div></main><script>setInterval(()=>{if(document.visibilityState==='visible'){fetch('orders.php?live=1',{cache:'no-store'}).then(r=>r.text()).then(html=>{const doc=new DOMParser().parseFromString(html,'text/html');const next=doc.querySelector('.orders-list');const current=document.querySelector('.orders-list');if(next&&current&&next.innerHTML!==current.innerHTML){current.replaceWith(next);}}).catch(()=>{});}},5000);</script><script src="js/script.js?v=6"></script></body></html>
