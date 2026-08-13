<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
$user = $_SESSION['user'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$q = trim((string)($_GET['q'] ?? ''));
$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
require_once __DIR__ . '/site_config.php';
lyaideu_ensure_mart_table();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mart | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6">
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index.php"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="mart.php" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search the mart"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" class="nav-a">Home</a></li>
            <li><a href="menu.php" class="nav-a">Menu</a></li>
            <li><a href="hotels.php" class="nav-a">Hotels</a></li>
            <li><a href="mart.php" class="nav-a active">Mart</a></li>
            <li><a href="contact.php" class="nav-a">Contact</a></li>
            <li><a href="orders.php" class="nav-a"><i class="fa-solid fa-box"></i> Orders</a></li>
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

<main>
    <section id="mart" class="section">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-basket-shopping"></i> Grocery essentials — right at your door</p>
                <h1 class="display">LyaiDeu Mart <i class="fa-solid fa-basket-shopping"></i></h1>
                <p class="section-sub">Fresh veggies, fruits &amp; daily essentials — add them to your cart with your food.</p>
                <div class="hero-actions" style="margin-top:1.2rem;">
                    <button class="btn btn-primary cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> View Cart <span class="cart-count">0</span></button>
                </div>
            </div>
            <div class="menu-toolbar"><div class="chip-row">
                <button class="chip active" data-mcat="all">All</button>
                <button class="chip" data-mcat="vegetables">Vegetables</button>
                <button class="chip" data-mcat="fruits">Fruits</button>
                <button class="chip" data-mcat="dairy">Dairy</button>
                <button class="chip" data-mcat="staples">Staples</button>
                <button class="chip" data-mcat="oils">Oils &amp; Spices</button>
                <button class="chip" data-mcat="snacks">Snacks</button>
            </div><div class="menu-tools"><div class="search-bar menu-search"><span><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" id="martSearch" placeholder="Search potatoes, milk, rice…" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"></div><select id="sortMart" class="sort-select"><option value="default">Sort: Recommended</option><option value="price-low">Price: Low to High</option><option value="price-high">Price: High to Low</option></select></div></div>
            <div class="grid dish-grid" id="mart-grid"></div>
            <div class="empty-state" id="martEmpty"><span class="big"><i class="fa-solid fa-basket-shopping"></i></span><p>No groceries match your search.</p></div>
        </div>
    </section>
</main>

<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-head"><h2><i class="fa-solid fa-cart-shopping"></i> Your Cart</h2><button type="button" class="cart-close" id="cartClose">×</button></div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Your cart is waiting for something tasty. <i class="fa-solid fa-pizza-slice"></i></div>
  <div class="cart-summary"><div class="summary-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0</strong></div><div class="summary-row"><span>Delivery</span><strong>Rs. 50</strong></div><div class="summary-row total"><span>Estimated total</span><strong id="cartTotal">Rs. 50</strong></div><a href="checkout.php" class="btn btn-primary btn-block" id="checkoutBtn">Checkout <i class="fa-solid fa-arrow-right"></i></a><button class="btn btn-outline btn-block" id="clearCart" type="button">Clear Cart</button></div>
</aside>
<footer class="footer">
    <div class="footer-grid">
        <div><p class="footer-brand"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"></p><p class="footer-blurb">Nepal's friendliest food delivery service — connecting you to the best hotels in the valley.</p></div>
        <div><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="menu.php">Menu</a></li><li><a href="hotels.php">Hotels</a></li><li><a href="mart.php">Mart</a></li><li><a href="contact.php">Contact</a></li><li><a href="faq.php">FAQ &amp; Privacy</a></li><li><a href="terms.php">Terms of Service</a></li><li><a href="demo.html"><i class="fa-solid fa-film"></i> Product Demo</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> Lazimpat, Kathmandu</li><li><i class="fa-solid fa-envelope"></i> hello@lyaideu.com.np</li><li><i class="fa-solid fa-phone"></i> 9800000001</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>Sun – Fri: 7 AM – 10 PM</li><li>Saturday: 8 AM – 10 PM</li><li><i class="fa-solid fa-motorcycle"></i> Deliveries every day!</li></ul></div>
    </div>
    <div class="footer-bottom">© <span id="year">2026</span> LyaiDeu · All rights reserved.</div>
</footer>

<script src="js/script.js?v=6"></script>
</body>
</html>