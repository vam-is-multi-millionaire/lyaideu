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
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';
lyaideu_ensure_stores();
lyaideu_ensure_discount_columns();

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ===================== RESOLVE SINGLE STORE (detail) OR LISTING ===================== */
$id = (int)($_GET['id'] ?? 0);
$slug = trim((string)($_GET['slug'] ?? ''));
if ($id <= 0 && $slug !== '') {
    try {
        $st = $pdo->prepare('SELECT id FROM hotels WHERE id = ?');
        if (preg_match('/^[0-9]+$/', $slug)) {
            $st->execute([(int)$slug]);
            $id = (int)$st->fetchColumn();
        } else {
            $id = 0;
            $rows = $pdo->query('SELECT id, name FROM hotels')->fetchAll();
            $needle = lyaideu_slugify($slug);
            foreach ($rows as $r) {
                if (lyaideu_slugify((string)$r['name']) === $needle) {
                    $id = (int)$r['id'];
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $id = 0;
    }
}

$store = null;
$products = [];
$vendorId = 0;
$kind = 'hotel';
if ($id > 0) {
    try {
        $st = $pdo->prepare('SELECT id, name, type, phone, emoji, logo, kind, `desc` FROM hotels WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $store = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $store = null;
    }
}

if ($id > 0 && !$store) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Store not found.'];
    header('Location: ' . lyaideu_base_url() . 'store');
    exit;
}

$isDetail = $store !== null;
if ($isDetail) {
    $kind = strtolower((string)($store['kind'] ?? 'hotel'));
    $storeName = (string)$store['name'];

    try {
        if ($kind === 'mart') {
            $vendorId = 0;
            try {
                $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'mart' AND hotel_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
                $st->execute([$id]);
                $vendorId = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $vendorId = 0;
            }
            if ($vendorId > 0) {
                $st = $pdo->prepare('SELECT id, name, cat, unit, price, discount_percent, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM mart_items WHERE vendor_id = ? ORDER BY id');
                $st->execute([$vendorId]);
                $products = $st->fetchAll();
            }
        } elseif ($kind === 'other') {
            lyaideu_ensure_other_table();
            $vendorId = 0;
            try {
                $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'other' AND hotel_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
                $st->execute([$id]);
                $vendorId = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $vendorId = 0;
            }
            if ($vendorId > 0) {
                $st = $pdo->prepare('SELECT id, name, cat, unit, price, discount_percent, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM other_items WHERE vendor_id = ? ORDER BY id');
                $st->execute([$vendorId]);
                $products = $st->fetchAll();
            }
        } elseif ($kind === 'beverage') {
            lyaideu_ensure_beverage_table();
            $vendorId = 0;
            try {
                $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'beverage' AND hotel_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
                $st->execute([$id]);
                $vendorId = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $vendorId = 0;
            }
            if ($vendorId > 0) {
                $st = $pdo->prepare('SELECT id, name, cat, unit, price, discount_percent, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM beverage_items WHERE vendor_id = ? ORDER BY id');
                $st->execute([$vendorId]);
                $products = $st->fetchAll();
            }
        } elseif ($kind === 'hotel') {
            $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'hotel' AND hotel_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
            $st->execute([$id]);
            $vendorId = (int)$st->fetchColumn();
            $st = $pdo->prepare('SELECT id, name, hotel, cat, price, discount_percent, phone, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM dishes WHERE (vendor_id = ?) OR (hotel = ?) ORDER BY id');
            $st->execute([$vendorId > 0 ? $vendorId : 0, $storeName]);
            $products = $st->fetchAll();
        }
    } catch (Throwable $e) {
        $products = [];
    }
    lyaideu_attach_variants(
        $products,
        $kind === 'mart' ? 'mart' : ($kind === 'other' ? 'other' : ($kind === 'beverage' ? 'beverage' : 'dish'))
    );
}

$kindLabel = $kind === 'mart' ? 'Mart' : ($kind === 'other' ? 'Other' : ($kind === 'beverage' ? 'Beverages' : 'Hotel'));
$kindIcon = $kind === 'mart' ? 'fa-basket-shopping' : ($kind === 'other' ? 'fa-gift' : ($kind === 'beverage' ? 'fa-champagne-glasses' : 'fa-hotel'));
$MART_CAT_ICONS = ['vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow', 'staples' => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie'];
$OTHER_CAT_ICONS = ['flowers' => 'fa-bouquet', 'candles' => 'fa-candle-holder', 'achar' => 'fa-jar', 'gifts' => 'fa-gift'];
$BEVERAGE_CAT_ICONS = ['cold-drinks' => 'fa-glass-water', 'alcohol' => 'fa-champagne-glasses', 'water' => 'fa-faucet-drip'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title><?= $isDetail ? e($store['name']) . ' | Stores · LyaiDeu' : 'Stores | LyaiDeu' ?></title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=44">
<link rel="stylesheet" href="css/cards-mobile.css?v=1">
<style>
/* Hide the per-store "Call" button on the stores listing (all viewports);
   "View Store" stays as the card's single action. */
.hotel-call-row .hotel-call[href^="tel:"]{display:none !important;}
@media (max-width:960px){
  footer.footer{display:none !important;}
  .section-head .kicker{display:none;}
  .section-head .section-sub{display:none;}
  #storeKinds{flex-wrap:nowrap;overflow-x:auto;align-items:center;padding:.35rem .25rem .6rem;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
  #storeKinds::-webkit-scrollbar{display:none;}
  #storeKinds .chip{flex:0 0 auto;white-space:nowrap;}
}
</style>
<script>window.LYADEU_BACK_TO_TOP=1;</script>
</head>
<body<?= $isDetail ? ' class="store-pg"' : '' ?>>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <?php if ($isDetail): ?><a class="back-link top-back-link" href="<?= lyaideu_from_home() ? 'index' : 'store' ?>" aria-label="Go back"><i class="fa-solid fa-arrow-left"></i> Back</a><?php endif; ?>
        <form class="nav-search" action="store" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search stores" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search stores"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a active">Stores</a></li>
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

<main>
<?php if ($isDetail): ?>
    <section id="store" class="section section-white">
        <div class="container">
            <a class="back-link" href="<?= lyaideu_from_home() ? 'index' : 'store' ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>

            <div class="store-hero">
                <div class="store-hero-avatar">
                    <?php if (!empty($store['logo'])): ?>
                        <img class="hotel-logo" src="<?= e($store['logo']) ?>" alt="<?= e($store['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <i class="fa-solid <?= $store['emoji'] !== '' ? e($store['emoji']) : e($kindIcon) ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="store-hero-info">
                    <span class="hotel-kind-badge"><i class="fa-solid <?= e($kindIcon) ?>"></i> <?= e($kindLabel) ?></span>
                    <h1 class="display"><?= e($store['name']) ?></h1>
                    <?php if ($store['type'] !== ''): ?>
                        <p class="store-hero-type"><i class="fa-solid fa-location-dot"></i> <?= e($store['type']) ?></p>
                    <?php endif; ?>
                    <?php if ($store['desc'] !== ''): ?>
                        <p class="store-hero-desc"><?= nl2br(e($store['desc'])) ?></p>
                    <?php endif; ?>
                    <div class="store-hero-actions">
                        <button class="btn btn-primary cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> View Cart <span class="cart-count">0</span></button>
                    </div>
                </div>
            </div>

            <div class="store-products">
                <h2 class="store-products-title">
                    <?php if ($kind === 'mart'): ?>
                        <i class="fa-solid fa-basket-shopping"></i> Our Products
                    <?php elseif ($kind === 'other'): ?>
                        <i class="fa-solid fa-gift"></i> Our Products
                    <?php elseif ($kind === 'beverage'): ?>
                        <i class="fa-solid fa-glass-water"></i> Our Products
                    <?php else: ?>
                        <i class="fa-solid fa-utensils"></i> Our Menu
                    <?php endif; ?>
                </h2>
                <?php if ($products): ?>
                    <div class="grid dish-grid store-grid">
                        <?php foreach ($products as $p):
                            $isMart = $kind === 'mart';
                            $isOther = $kind === 'other';
                            $isBeverage = $kind === 'beverage';
                            $hasUnit = $isMart || $isOther || $isBeverage;
                            $defVar = null;
                            if (!empty($p['has_variants']) && !empty($p['variants'])) {
                                foreach ($p['variants'] as $vv) {
                                    if (!empty($vv['is_default'])) { $defVar = $vv; break; }
                                }
                                if ($defVar === null) { $defVar = $p['variants'][0]; }
                            }
                            $price = $defVar ? (int)$defVar['price'] : (int)$p['price'];
                            $dealPct = lyaideu_deal_percent($p['discount_percent'] ?? 0);
                            $dealNow = lyaideu_deal_price($price, $dealPct);
                            $unitLabel = $defVar && (string)$defVar['label'] !== '' ? (string)$defVar['label'] : (string)($p['unit'] ?? '');
                            $unitHtml = $hasUnit && $unitLabel !== '' ? ' <span class="unit">/ ' . e($unitLabel) . '</span>' : '';
                            $img = (string)$p['img'];
                            $catIcon = $isMart ? ($MART_CAT_ICONS[$p['cat']] ?? 'fa-basket-shopping') : ($isOther ? ($OTHER_CAT_ICONS[$p['cat']] ?? 'fa-gift') : ($BEVERAGE_CAT_ICONS[$p['cat']] ?? 'fa-glass-water'));
                            $art = $img !== '' ? '<img src="' . e($img) . '" alt="' . e($p['name']) . '" loading="lazy">' : ($hasUnit ? '<span class="mart-art"><i class="fa-solid ' . e($catIcon) . '"></i></span>' : '<span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>');
                            $url = ($isMart ? 'mart' : ($isOther ? 'others' : ($isBeverage ? 'beverages' : 'menu'))) . '/' . (int)$p['id'];
                            $cardType = $isMart ? 'mart' : ($isOther ? 'other' : ($isBeverage ? 'beverage' : 'dish'));
                        ?>
                        <article class="dish-card reveal visible" data-url="<?= $url ?>" data-type="<?= $cardType ?>">
                            <div class="dish-art <?= $hasUnit ? 'mart-art' : '' ?>"><?= $art ?><?= $p['tag'] !== '' ? '<span class="dish-tag">' . e($p['tag']) . '</span>' : '' ?></div>
                            <div class="dish-body">
                                <div class="dish-top"><h3><?= e($p['name']) ?></h3></div>
                                <div class="dish-foot"><span class="price"><small>Rs.</small> <?= $dealNow ?><?= $unitHtml ?></span><?= $dealPct > 0 ? '<span class="deal-badge deal-badge-inline">-' . $dealPct . '%</span>' : '' ?>
                                <button class="btn-order add-cart" data-id="<?= (int)$p['id'] ?>" data-type="<?= $cardType ?>" data-name="<?= e($p['name']) ?>" data-price="<?= $dealNow ?>"<?= $hasUnit && $unitLabel !== '' ? ' data-unit="' . e($unitLabel) . '"' : '' ?> data-hotel="<?= e($store['name']) ?>" data-img="<?= e($img) ?>"<?= !empty($p['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="display:block;">
                        <span class="big"><i class="fa-solid <?= e($kindIcon) ?>"></i></span>
                        <p><?= $kind === 'other' || $kind === 'beverage' ? 'This partner store is setting up its catalog — check back soon!' : 'No products published yet. Check back soon!' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>
    <section id="hotels" class="section section-white">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-store"></i> Partner businesses</p>
                <h1 class="display">Our Partner Stores <i class="fa-solid fa-store"></i></h1>
                <p class="section-sub">Hotels, the Mart and every partner business — all in one place.</p>
            </div>
            <div class="menu-toolbar">
                <div class="chip-row" id="storeKinds">
                    <button class="chip active" data-skind="all">All</button>
                    <button class="chip" data-skind="hotel"><i class="fa-solid fa-hotel"></i> Hotels</button>
                    <button class="chip" data-skind="mart"><i class="fa-solid fa-basket-shopping"></i> Marts</button>
                    <button class="chip" data-skind="beverage"><i class="fa-solid fa-glass-water"></i> Beverages</button>
                    <button class="chip" data-skind="other"><i class="fa-solid fa-store"></i> Others</button>
                </div>
                <div class="menu-tools"></div>
            </div>
            <div class="grid hotels-grid" id="hotels-grid"></div>
            <div class="empty-state" id="hotelsEmpty"><span class="big"><i class="fa-solid fa-store"></i></span><p>No stores match your search.</p></div>
        </div>
    </section>
<?php endif; ?>
</main>

<?php if ($isDetail): ?>
<button class="cart-fab cart-open-btn" type="button" aria-label="Open cart"><span class="cart-fab-icon"><i class="fa-solid fa-cart-shopping"></i></span><span class="cart-fab-label">Cart</span><span class="cart-count">0</span></button>
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-head"><h2><i class="fa-solid fa-cart-shopping"></i> Your Cart</h2><button type="button" class="cart-close" id="cartClose">×</button></div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Your cart is waiting for something tasty. <i class="fa-solid fa-pizza-slice"></i></div>
  <div class="cart-summary"><div class="summary-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0</strong></div><div class="summary-row"><span>Delivery</span><strong id="cartDelivery">Rs. 50</strong></div><div class="summary-row total"><span>Estimated total</span><strong id="cartTotal">Rs. 50</strong></div><a href="checkout" class="btn btn-primary btn-block" id="checkoutBtn">Checkout <i class="fa-solid fa-arrow-right"></i></a><button class="btn btn-outline btn-block" id="clearCart" type="button">Clear Cart</button></div>
</aside>
<?php endif; ?>

<?= lyaideu_footer_html() ?>

<script src="js/script.js?v=30"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
<script>
(function(){
  /* Back link: use the browser's own history so the previous page is
     restored from its cache instantly (order and scroll stay exactly as
     they were). The static href is only followed when this page was
     opened directly (shared link / new tab). */
  var backLinks = document.querySelectorAll('.back-link');
  if (!backLinks.length || !window.history) return;
  var sameOriginRef = false;
  try {
    sameOriginRef = !!document.referrer && new URL(document.referrer).origin === location.origin;
  } catch (err) { sameOriginRef = false; }
  backLinks.forEach(function (backLink) {
    backLink.addEventListener('click', function (e) {
      if (sameOriginRef && window.history.length > 1) {
        e.preventDefault();
        window.history.back();
      }
    });
  });
})();
</script>
<?php if ($isDetail): ?>
<script>
(function(){
  document.addEventListener('click',function(e){
    var card=e.target.closest('.store-grid .dish-card');
    if(!card)return;
    if(e.target.closest('.add-cart')||e.target.closest('a'))return;
    var url=card.getAttribute('data-url');
    if(url)window.location.href=url;
  });
})();
</script>
<?php endif; ?>
</body>
</html>