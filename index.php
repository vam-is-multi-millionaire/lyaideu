<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
$user = $_SESSION['user'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
require_once __DIR__ . '/site_config.php';

function lyaideu_featured_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$featured = ['dishes' => [], 'mart' => [], 'hotels' => []];
$featuredPdo = lyaideu_load_pdo();
if ($featuredPdo instanceof PDO) {
    try {
        lyaideu_seed_catalog();
        $featured['dishes'] = $featuredPdo->query('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img FROM dishes')->fetchAll();
        $featured['mart']   = $featuredPdo->query('SELECT id, name, cat, unit, price, tag, `desc`, img FROM mart_items')->fetchAll();
        $featured['hotels'] = $featuredPdo->query('SELECT id, name, type, phone, emoji, logo FROM hotels')->fetchAll();
    } catch (Throwable $e) {
        $featured = ['dishes' => [], 'mart' => [], 'hotels' => []];
    }
    shuffle($featured['dishes']);
    shuffle($featured['mart']);
    shuffle($featured['hotels']);
    $featured['dishes'] = array_slice($featured['dishes'], 0, 12);
    $featured['mart']   = array_slice($featured['mart'], 0, 12);
    $featured['hotels'] = array_slice($featured['hotels'], 0, 8);
}

$FEATURED_MART_ICONS = [
    'vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow',
    'staples'    => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $user ? 'LyaiDeu · Namaste, ' . htmlspecialchars($firstName) . '!' : 'LyaiDeu · Food Delivery in Surkhet Valley' ?></title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6">
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
    <section id="home" class="hero">
        <span class="float-emoji" style="top:18%;right:8%;font-size:3.4rem;"><i class="fa-solid fa-pizza-slice"></i></span>
        <span class="float-emoji" style="bottom:16%;right:18%;font-size:2.6rem;"><i class="fa-solid fa-drumstick-bite"></i></span>
        <span class="float-emoji" style="top:26%;right:26%;font-size:2.1rem;"><i class="fa-solid fa-mug-saucer"></i></span>
        <div class="container hero-inner">
            <p class="kicker"><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</p>
            <h1 class="display"><?= $user ? 'Namaste ' . htmlspecialchars($firstName) . '!' : 'Welcome to LyaiDeu!' ?> <i class="fa-solid fa-hand"></i><br>Khaja time — <em>what's the craving?</em></h1>
            <form class="search-bar" action="menu.php" method="get"><span><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search momo, pizza, hotels…" aria-label="Search the menu"></form>
            <div class="hero-actions"><a class="btn btn-primary" href="menu.php"><i class="fa-solid fa-utensils"></i> Browse Menu</a><button class="btn btn-outline cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> Cart <span class="cart-count">0</span></button></div>
        </div>
    </section>

    <section id="featured" class="section">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-shuffle"></i> Fresh every visit</p>
                <h2 class="display">Random Picks for You <i class="fa-solid fa-dice"></i></h2>
                <p class="section-sub">Tasty dishes, grocery essentials and partner kitchens — shuffled fresh on every refresh.</p>
            </div>

            <div class="featured-stack">
                <?php if ($featured['dishes']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-utensils"></i> From the Menu</h3>
                        <a class="see-all" href="menu.php">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredDishes">
                        <?php foreach ($featured['dishes'] as $fDish): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fDish['id'] ?>" data-type="dish">
                            <div class="dish-art">
                                <?php if ($fDish['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fDish['img']) ?>" alt="<?= lyaideu_featured_e($fDish['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fDish['name']) ?></h3></div>
                            <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$fDish['price'] ?></span>
                            <button class="btn-order add-cart" data-id="<?= (int)$fDish['id'] ?>" data-type="dish" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['mart']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-basket-shopping"></i> From the Mart</h3>
                        <a class="see-all" href="mart.php">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredMart">
                        <?php foreach ($featured['mart'] as $fMart): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fMart['id'] ?>" data-type="mart">
                            <div class="dish-art mart-art">
                                <?php if ($fMart['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fMart['img']) ?>" alt="<?= lyaideu_featured_e($fMart['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= $FEATURED_MART_ICONS[$fMart['cat']] ?? 'fa-basket-shopping' ?>"></i>
                                <?php endif; ?>
                                <?php if ($fMart['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($fMart['tag']) ?></span><?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fMart['name']) ?></h3></div>
                            <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$fMart['price'] ?><?= $fMart['unit'] !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e($fMart['unit']) . '</span>' : '' ?></span>
                            <button class="btn-order add-cart" data-id="<?= (int)$fMart['id'] ?>" data-type="mart" type="button"><i class="fa-solid fa-cart-plus"></i> Buy</button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['hotels']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-hotel"></i> Partner Hotels</h3>
                        <a class="see-all" href="hotels.php">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredHotels">
                        <?php foreach ($featured['hotels'] as $fHotel): ?>
                        <div class="hotel-card reveal visible">
                            <div class="hotel-avatar">
                                <?php if ($fHotel['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fHotel['logo']) ?>" alt="<?= lyaideu_featured_e($fHotel['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fHotel['emoji'] !== '' ? $fHotel['emoji'] : 'fa-hotel') ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fHotel['name']) ?></h3><p><?= lyaideu_featured_e($fHotel['type']) ?></p></div>
                            <a class="hotel-call" href="tel:+977<?= lyaideu_featured_e($fHotel['phone']) ?>"><i class="fa-solid fa-phone"></i> <?= lyaideu_featured_e($fHotel['phone']) ?></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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

<script src="js/script.js?v=6"></script>
</body>
</html>