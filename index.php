<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$user = $_SESSION['user'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$parts = preg_split('/\s+/', trim($user['name']));
$firstName = $parts[0];
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
require_once __DIR__ . '/site_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LyaiDeu · Namaste, <?= htmlspecialchars($firstName) ?>!</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=3">
</head>
<body>

<div class="promo-ticker"><div class="ticker-track">
    <span><i class="fa-solid fa-fire"></i> Free delivery on your first order — code LYAIDEU</span>
    <span><i class="fa-solid fa-drumstick-bite"></i> Momo Festival: 20% off</span>
    <span><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</span>
    <span><i class="fa-solid fa-heart"></i> 25+ hotels across the city</span>
    <span><i class="fa-solid fa-fire"></i> Free delivery on your first order — code LYAIDEU</span>
    <span><i class="fa-solid fa-drumstick-bite"></i> Momo Festival: 20% off</span>
    <span><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</span>
    <span><i class="fa-solid fa-heart"></i> 25+ hotels across the city</span>
</div></div>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="#home"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"> Lyai<span>Deu</span></a>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="#home" class="nav-a active">Home</a></li>
            <li><a href="menu.php" class="nav-a">Menu</a></li>
            <li><a href="hotels.php" class="nav-a">Hotels</a></li>
            <li><a href="mart.php" class="nav-a">Mart</a></li>
            <li><a href="contact.php" class="nav-a">Contact</a></li>
            <li><a href="faq.php" class="nav-a">FAQ</a></li>
            <li><a href="orders.php" class="nav-a"><i class="fa-solid fa-box"></i> Orders</a></li>
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
        </ul>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="flash-banner flash-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<main>
    <section id="home" class="hero">
        <span class="float-emoji" style="top:18%;right:8%;font-size:3.4rem;"><i class="fa-solid fa-pizza-slice"></i></span>
        <span class="float-emoji" style="bottom:16%;right:18%;font-size:2.6rem;"><i class="fa-solid fa-drumstick-bite"></i></span>
        <span class="float-emoji" style="top:26%;right:26%;font-size:2.1rem;"><i class="fa-solid fa-mug-saucer"></i></span>
        <div class="container hero-inner">
            <p class="kicker"><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</p>
            <h1 class="display">Namaste <?= htmlspecialchars($firstName) ?>! <i class="fa-solid fa-hand"></i><br>Khaja time — <em>what's the craving?</em></h1>
            <form class="search-bar" action="menu.php" method="get"><span><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search momo, pizza, hotels…" aria-label="Search the menu"></form>
            <div class="hero-actions"><a class="btn btn-primary" href="menu.php"><i class="fa-solid fa-utensils"></i> Browse Menu</a><button class="btn btn-outline cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> Cart <span class="cart-count">0</span></button></div>
        </div>
    </section>

    <section id="menu" class="section">
        <div class="container">
            <div class="section-head">
                <h2 class="display">What are you craving? <i class="fa-solid fa-utensils"></i></h2>
                <p class="section-sub">Jump straight into the food, our partner hotels, or get in touch.</p>
            </div>
            <div class="grid browse-grid">
                <a class="browse-card" href="menu.php">
                    <span class="browse-ico"><i class="fa-solid fa-utensils"></i></span>
                    <h3>Today's Menu</h3>
                    <p>Browse fresh dishes from our partner kitchens and order now.</p>
                    <span class="browse-link">Explore Menu <i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <a class="browse-card" href="hotels.php">
                    <span class="browse-ico"><i class="fa-solid fa-hotel"></i></span>
                    <h3>Partner Hotels</h3>
                    <p>Discover the trusted kitchens cooking for you right now.</p>
                    <span class="browse-link">View Hotels <i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <a class="browse-card" href="contact.php">
                    <span class="browse-ico"><i class="fa-solid fa-phone"></i></span>
                    <h3>Contact Us</h3>
                    <p>One call away — order hotline, delivery support & partnerships.</p>
                    <span class="browse-link">Get In Touch <i class="fa-solid fa-arrow-right"></i></span>
                </a>
            </div>
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
        <div><h4>Quick Links</h4><ul><li><a href="#home">Home</a></li><li><a href="menu.php">Menu</a></li><li><a href="hotels.php">Hotels</a></li><li><a href="mart.php">Mart</a></li><li><a href="contact.php">Contact</a></li><li><a href="faq.php">FAQ &amp; Privacy</a></li><li><a href="terms.php">Terms of Service</a></li><li><a href="demo.html"><i class="fa-solid fa-film"></i> Product Demo</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> Lazimpat, Kathmandu</li><li><i class="fa-solid fa-envelope"></i> hello@lyaideu.com.np</li><li><i class="fa-solid fa-phone"></i> 9800000001</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>Sun – Fri: 7 AM – 10 PM</li><li>Saturday: 8 AM – 10 PM</li><li><i class="fa-solid fa-motorcycle"></i> Deliveries every day!</li></ul></div>
    </div>
    <div class="footer-bottom">© <span id="year">2026</span> LyaiDeu · All rights reserved.</div>
</footer>

<script src="js/script.js?v=3"></script>
</body>
</html>