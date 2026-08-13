<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
$user = $_SESSION['user'] ?? null;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

$type = (($_GET['type'] ?? 'dish') === 'mart') ? 'mart' : 'dish';
$id = (int)($_GET['id'] ?? 0);
$back = $type === 'mart' ? 'mart.php' : 'menu.php';

$item = null;
$related = [];
try {
    if ($type === 'mart') {
        lyaideu_ensure_mart_table();
        $st = $pdo->prepare('SELECT id, name, cat, unit, price, tag, `desc`, img FROM mart_items WHERE id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT id, name, cat, unit, price, tag, `desc`, img FROM mart_items WHERE cat = :cat AND id <> :id ORDER BY id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    } else {
        $st = $pdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img FROM dishes WHERE id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img FROM dishes WHERE cat = :cat AND id <> :id ORDER BY id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    }
} catch (Throwable $e) {
    $item = null;
}

if (!$item) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Product not found.'];
    header('Location: ' . $back);
    exit;
}

$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$MART_CAT_ICONS = ['vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow', 'staples' => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie'];
$ICON = $type === 'mart' ? ($MART_CAT_ICONS[$item['cat']] ?? 'fa-basket-shopping') : 'fa-utensils';
$REL_ICONS = [
    'vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow',
    'staples' => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie',
    'momo' => 'fa-drumstick-bite', 'pizza' => 'fa-pizza-slice', 'chowmein' => 'fa-bowl-rice',
    'snacks2' => 'fa-cookie', 'beverages' => 'fa-mug-saucer', 'dinner' => 'fa-bowl-food',
];
$relIcon = function ($cat) use ($REL_ICONS) {
    if (isset($REL_ICONS[$cat])) return '<i class="fa-solid ' . $REL_ICONS[$cat] . '"></i>';
    return '<i class="fa-solid fa-basket-shopping"></i>';
};
$unitHtml = ($type === 'mart' && $item['unit'] !== '') ? ' <span class="unit">/ ' . e($item['unit']) . '</span>' : '';
$tagHtml = $item['tag'] !== '' ? '<span class="dish-tag">' . e($item['tag']) . '</span>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($item['name']) ?> | <?= $type === 'mart' ? 'LyaiDeu Mart' : 'Menu' ?></title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6">
</head>
<body class="product-pg" data-needs-catalog>

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
        <a class="brand" href="index.php"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu.php" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" class="nav-a">Home</a></li>
            <li><a href="menu.php" class="nav-a <?= $type === 'dish' ? 'active' : '' ?>">Menu</a></li>
            <li><a href="hotels.php" class="nav-a">Hotels</a></li>
            <li><a href="mart.php" class="nav-a <?= $type === 'mart' ? 'active' : '' ?>">Mart</a></li>
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

<main class="product-wrap section">
    <div class="container">
        <a class="back-link" href="<?= $back ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?= $type === 'mart' ? 'Mart' : 'Menu' ?></a>

        <div class="product-main">
            <div class="product-media">
                <?php if ($item['img'] !== ''): ?>
                    <img src="<?= e($item['img']) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="product-media-noimg"><i class="fa-solid <?= $ICON ?>"></i></div>
                <?php endif; ?>
                <?= $tagHtml ?>
            </div>

            <div class="product-details">
                <span class="product-cat"><i class="fa-solid <?= $ICON ?>"></i> <?= e(ucfirst($item['cat'])) ?></span>
                <h1 class="display"><?= e($item['name']) ?></h1>
                <?php if ($type === 'dish' && $item['hotel'] !== ''): ?>
                    <p class="product-hotel"><i class="fa-solid fa-hotel"></i> <?= e($item['hotel']) ?></p>
                <?php endif; ?>
                <?php if ($item['desc'] !== ''): ?>
                    <p class="product-desc"><?= e($item['desc']) ?></p>
                <?php endif; ?>

                <div class="product-price-row">
                    <span class="product-price"><small>Rs.</small> <?= (int)$item['price'] ?><?= $unitHtml ?></span>
                </div>

                <div class="product-actions">
                    <button class="btn btn-primary add-cart" data-id="<?= (int)$item['id'] ?>" data-type="<?= $type ?>" type="button"><i class="fa-solid fa-cart-shopping"></i> Add to Cart</button>
                    <button class="btn btn-outline cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> View Cart <span class="cart-count">0</span></button>
                </div>

                <?php if ($type === 'dish' && $item['phone'] !== ''): ?>
                    <p class="small-note" style="margin-top:.9rem;"><i class="fa-solid fa-phone"></i> Confirm your order with the hotel: <a class="product-call" href="tel:+977<?= e($item['phone']) ?>">+977 <?= e($item['phone']) ?></a></p>
                <?php endif; ?>
                <?php if ($type === 'mart'): ?>
                    <p class="small-note" style="margin-top:.9rem;"><i class="fa-solid fa-box"></i> Fresh groceries delivered with your khaja order.</p>
                <?php endif; ?>
            </div>
        </div>

        <section class="related-section">
            <h3><i class="fa-solid fa-thumbs-up"></i> Related <?= e(ucfirst($item['cat'])) ?></h3>
            <?php if ($related): ?>
                <div class="related-grid">
                    <?php foreach ($related as $rItem): ?>
                        <a class="related-card" href="product.php?type=<?= $type ?>&amp;id=<?= (int)$rItem['id'] ?>">
                            <div class="related-img">
                                <?php if ($rItem['img'] !== ''): ?>
                                    <img src="<?= e($rItem['img']) ?>" alt="<?= e($rItem['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <?= $relIcon($rItem['cat']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="related-info">
                                <h4><?= e($rItem['name']) ?></h4>
                                <span class="price"><small>Rs.</small> <?= (int)$rItem['price'] ?><?= ($type === 'mart' && $rItem['unit'] !== '') ? ' <span class="unit">/ ' . e($rItem['unit']) . '</span>' : '' ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="small-note">No other items in this category yet — check back soon!</p>
            <?php endif; ?>
        </section>
    </div>
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