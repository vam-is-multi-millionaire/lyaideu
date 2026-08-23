<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['csrf_contact'])) $_SESSION['csrf_contact'] = bin2hex(random_bytes(32));
$user = $_SESSION['user'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$q = trim((string)($_GET['q'] ?? ''));
$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
require_once __DIR__ . '/site_config.php';

function lyaideu_featured_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Default (preselected) variant of an item — rows must have `variants` attached
   via lyaideu_attach_variants(). Null when the item has no options. */
function lyaideu_featured_variant(array $item): ?array {
    if (empty($item['has_variants']) || empty($item['variants'])) {
        return null;
    }
    foreach ($item['variants'] as $v) {
        if (!empty($v['is_default'])) {
            return $v;
        }
    }
    return $item['variants'][0];
}
function lyaideu_featured_price(array $item): int {
    $v = lyaideu_featured_variant($item);
    return $v ? (int)$v['price'] : (int)$item['price'];
}
function lyaideu_featured_deal_pct(array $item): int {
    return lyaideu_deal_percent($item['discount_percent'] ?? 0);
}
function lyaideu_featured_deal_price(array $item): int {
    return lyaideu_deal_price(lyaideu_featured_price($item), lyaideu_featured_deal_pct($item));
}
function lyaideu_featured_unit(array $item): string {
    $v = lyaideu_featured_variant($item);
    return $v && (string)$v['label'] !== '' ? (string)$v['label'] : (string)($item['unit'] ?? '');
}

$featured = ['dishes' => [], 'mart' => [], 'others' => [], 'beverages' => [], 'hotels' => [], 'mart_stores' => [], 'other_stores' => [], 'partners' => []];
$fsSeed = 0;
$featuredPdo = lyaideu_load_pdo();
if ($featuredPdo instanceof PDO) {
    try {
        lyaideu_ensure_stores();
        lyaideu_ensure_other_table();
        lyaideu_ensure_beverage_table();
        $featured['dishes'] = $featuredPdo->query('SELECT id, name, hotel, cat, price, discount_percent, phone, tag, `desc`, img, category_id, name_slug, has_variants FROM dishes ORDER BY id')->fetchAll();
        $featured['mart']   = $featuredPdo->query(
            'SELECT m.id, m.name, m.cat, m.unit, m.price, m.discount_percent, m.tag, m.`desc`, m.img, m.category_id, m.name_slug, m.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM mart_items m
             LEFT JOIN vendors v ON v.id = m.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             ORDER BY m.id'
        )->fetchAll();
        $featured['others'] = $featuredPdo->query(
            'SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.discount_percent, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug, oi.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM other_items oi
             LEFT JOIN vendors v ON v.id = oi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             ORDER BY oi.id'
        )->fetchAll();
        $featured['beverages'] = $featuredPdo->query(
            'SELECT bi.id, bi.name, bi.cat, bi.unit, bi.price, bi.discount_percent, bi.tag, bi.`desc`, bi.img, bi.category_id, bi.name_slug, bi.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM beverage_items bi
             LEFT JOIN vendors v ON v.id = bi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             ORDER BY bi.id'
        )->fetchAll();
        $featured['stores'] = $featuredPdo->query('SELECT id, name, type, phone, emoji, logo, kind FROM hotels ORDER BY id')->fetchAll();

        /* Control Panel: products in switched-off category subtrees never
           reach the home page (Random Picks / partners stay consistent). */
        $featVisible = function (array $rows): array {
            return array_values(array_filter($rows, fn($r) => (int)($r['category_id'] ?? 0) <= 0 || lyaideu_category_is_active((int)$r['category_id'])));
        };
        $featured['dishes'] = $featVisible($featured['dishes']);
        $featured['mart'] = $featVisible($featured['mart']);
        $featured['others'] = $featVisible($featured['others']);
        $featured['beverages'] = $featVisible($featured['beverages']);
    } catch (Throwable $e) {
$featured = ['dishes' => [], 'mart' => [], 'others' => [], 'beverages' => [], 'hotels' => [], 'mart_stores' => [], 'other_stores' => [], 'partners' => []];
    }
    $featured['hotels']      = array_values(array_filter($featured['stores'] ?? [], fn($s) => ($s['kind'] ?? 'hotel') === 'hotel'));
    $featured['mart_stores'] = array_values(array_filter($featured['stores'] ?? [], fn($s) => ($s['kind'] ?? '') === 'mart'));
    $featured['other_stores'] = array_values(array_filter($featured['stores'] ?? [], fn($s) => ($s['kind'] ?? '') === 'other'));
    $fsSeed = mt_rand(1, 2147483647);
    mt_srand($fsSeed);
    shuffle($featured['dishes']);
    shuffle($featured['mart']);
    shuffle($featured['others']);
    shuffle($featured['beverages']);
    shuffle($featured['hotels']);
    shuffle($featured['mart_stores']);
    shuffle($featured['other_stores']);
    $featured['dishes'] = array_slice($featured['dishes'], 0, 12);
    $featured['mart']   = array_slice($featured['mart'], 0, 12);
    $featured['others'] = array_slice($featured['others'], 0, 12);
    $featured['hotels'] = array_slice($featured['hotels'], 0, 8);
    $featured['mart_stores'] = array_slice($featured['mart_stores'], 0, 4);
    $featured['other_stores'] = array_slice($featured['other_stores'], 0, 4);
    /* Mobile-only merged block: hotels + mart partners + other stores in
       one "Our Trusted Partners" rail, capped at 8 cards. */
    $featured['partners'] = array_merge($featured['hotels'], $featured['mart_stores'], $featured['other_stores']);
    shuffle($featured['partners']);
    $featured['partners'] = array_slice($featured['partners'], 0, 8);
    lyaideu_attach_variants($featured['dishes'], 'dish');
    lyaideu_attach_variants($featured['mart'], 'mart');
    lyaideu_attach_variants($featured['others'], 'other');
    lyaideu_attach_variants($featured['beverages'], 'beverage');
}

$searchResults = null;
if ($q !== '' && $featuredPdo instanceof PDO) {
    $searchResults = ['dishes' => [], 'mart' => [], 'others' => [], 'beverages' => [], 'hotels' => []];
    try {
        $qp = '%' . $q . '%';
        $st = $featuredPdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug, has_variants FROM dishes WHERE name LIKE ? OR tag LIKE ? OR `desc` LIKE ? ORDER BY name LIMIT 30');
        $st->execute([$qp, $qp, $qp]);
        $searchResults['dishes'] = $st->fetchAll();
        $st = $featuredPdo->prepare(
            'SELECT m.id, m.name, m.cat, m.unit, m.price, m.tag, m.`desc`, m.img, m.category_id, m.name_slug, m.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM mart_items m
             LEFT JOIN vendors v ON v.id = m.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE m.name LIKE ? OR m.tag LIKE ? OR m.`desc` LIKE ?
             ORDER BY m.name LIMIT 30'
        );
        $st->execute([$qp, $qp, $qp]);
        $searchResults['mart'] = $st->fetchAll();
        $st = $featuredPdo->prepare(
            'SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug, oi.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM other_items oi
             LEFT JOIN vendors v ON v.id = oi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE oi.name LIKE ? OR oi.tag LIKE ? OR oi.`desc` LIKE ?
             ORDER BY oi.name LIMIT 30'
        );
        $st->execute([$qp, $qp, $qp]);
        $searchResults['others'] = $st->fetchAll();
        $st = $featuredPdo->prepare(
            'SELECT bi.id, bi.name, bi.cat, bi.unit, bi.price, bi.tag, bi.`desc`, bi.img, bi.category_id, bi.name_slug, bi.has_variants,
                    COALESCE(h.name, \'\') AS hotel
             FROM beverage_items bi
             LEFT JOIN vendors v ON v.id = bi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE bi.name LIKE ? OR bi.tag LIKE ? OR bi.`desc` LIKE ?
             ORDER BY bi.name LIMIT 30'
        );
        $st->execute([$qp, $qp, $qp]);
        $searchResults['beverages'] = $st->fetchAll();

        /* Control Panel: hide switched-off category products from search too. */
        $searchVisible = function (array $rows): array {
            return array_values(array_filter($rows, fn($r) => (int)($r['category_id'] ?? 0) <= 0 || lyaideu_category_is_active((int)$r['category_id'])));
        };
        $searchResults['dishes'] = $searchVisible($searchResults['dishes']);
        $searchResults['mart'] = $searchVisible($searchResults['mart']);
        $searchResults['others'] = $searchVisible($searchResults['others']);
        $searchResults['beverages'] = $searchVisible($searchResults['beverages']);
        $st = $featuredPdo->prepare('SELECT id, name, type, phone, emoji, logo, kind FROM hotels WHERE name LIKE ? OR type LIKE ? ORDER BY name LIMIT 20');
        $st->execute([$qp, $qp]);
        $searchResults['hotels'] = $st->fetchAll();
        lyaideu_attach_variants($searchResults['dishes'], 'dish');
        lyaideu_attach_variants($searchResults['mart'], 'mart');
        lyaideu_attach_variants($searchResults['others'], 'other');
        lyaideu_attach_variants($searchResults['beverages'], 'beverage');
    } catch (Throwable $e) {
        $searchResults = null;
    }
}
$totalResults = $searchResults ? count($searchResults['dishes']) + count($searchResults['mart']) + count($searchResults['others']) + count($searchResults['beverages']) + count($searchResults['hotels']) : 0;

$FEATURED_MART_ICONS = [
    'vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow',
    'staples'    => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie',
];

$FEATURED_OTHER_ICONS = [
    'flowers' => 'fa-bouquet', 'candles' => 'fa-candle-holder',
    'achar'   => 'fa-jar', 'gifts' => 'fa-gift',
];

$FEATURED_BEVERAGE_ICONS = [
    'cold-drinks' => 'fa-glass-water', 'alcohol' => 'fa-champagne-glasses',
    'water'   => 'fa-faucet-drip',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title><?= $user ? 'LyaiDeu · Namaste, ' . htmlspecialchars($firstName) . '!' : 'LyaiDeu · Food Delivery in Surkhet Valley' ?></title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=62">
<style>
/* Vendor/store line on product cards — rendered for phones only */
.dish-grid.home-grid .dish-hotel{display:none;}
/* Home page: hide the section subtitle lines on phones only */
@media (max-width:960px){
  .section-head .section-sub{display:none;}
  /* Vendor/store name under the title — one line, truncated with “…” */
  .dish-grid.home-grid .dish-card{min-width:0;}
  .dish-grid.home-grid .dish-hotel{
    display:block;margin:.16rem 0 0;max-width:100%;
    font-size:.62rem;font-weight:800;line-height:1.2;
    color:var(--orange-600,#e76608);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .dish-grid.home-grid .dish-hotel i{font-size:.55rem;color:var(--orange-400,#f5974e);margin-right:.24rem;}
  /* Product title stays on one line too — same “…” truncation */
  .dish-grid.home-grid .dish-top{min-width:0;}
  .dish-grid.home-grid .dish-top h3{
    min-width:0;flex:0 1 auto;max-width:100%;
    font-size:.78rem;line-height:1.25;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    overflow-wrap:normal;
  }
  /* Mobile hero shows only the slider — trim its heavy vertical padding */
  .hero.hero-split{padding:1.25rem .95rem 1.75rem;}
  /* Random Picks sits right under the hero on phones — no top gap */
  #featured{padding-top:0;}
  /* Hide the "/ unit" text after prices on phones */
  .dish-card .price .unit{display:none;}
  /* All product cards: slimmer body padding on phones (top/bottom only) */
  .dish-grid.home-grid .dish-body{padding-top:.2rem;padding-bottom:.24rem;}
  /* Keep the dashed divider flush with the content above on phones only */
  .dish-foot{padding-top:0;}
  /* Add button shows only the cart icon on phones */
  .dish-card .btn-order.add-cart .add-label{display:none;}
  /* Bare cart icon only — no pill, no fill, no border */
  .dish-card .btn-order.add-cart{
    width:2.25rem;height:2.25rem;min-height:0;padding:0;
    display:inline-flex;align-items:center;justify-content:center;flex:none;
    background:transparent!important;
    color:var(--orange-600,#e76608)!important;
    border:0!important;
    border-radius:.72rem;
    box-shadow:none!important;
    transition:color .2s ease,transform .2s ease;
  }
  .dish-card .btn-order.add-cart::after{display:none;}
  /* Swap plain cart for a cart+plus glyph on phones */
  .dish-card .btn-order.add-cart i{display:none;}
  .dish-card .btn-order.add-cart::before{
    font-family:"Font Awesome 6 Free";
    font-weight:900;
    content:"\f217"; /* fa-cart-plus */
    display:inline-block;
    font-style:normal;
    font-size:.95rem;
    line-height:1;
    -webkit-font-smoothing:antialiased;
  }
  .dish-card .btn-order.add-cart:hover{
    color:var(--orange-700,#a03c07)!important;
    transform:none!important;box-shadow:none!important;background:transparent!important;
  }
  .dish-card .btn-order.add-cart:active{transform:scale(.88);color:var(--orange-800,#742a05)!important;}
  /* Mobile: the three separate store ribbons collapse into one
     "Our Trusted Partners" block (rendered further down) */
  #featured .feat-block.partner-block{display:none;}
}
/* Mobile: product cards go 3-per-row (featured + search result grids) */
/* Combined partners block is a phones-only section */
@media (min-width:961px){
  #featured .feat-block.partner-block-mobile{display:none;}
}
@media (max-width:960px){
  #featured .dish-grid.home-grid,
  #search .dish-grid.home-grid{grid-template-columns:repeat(3,1fr);gap:.55rem;}
}
@media (max-width:360px){
  #featured .dish-grid.home-grid,
  #search .dish-grid.home-grid{gap:.45rem;}
  #featured .dish-grid.home-grid .dish-body,
  #search .dish-grid.home-grid .dish-body{padding:.16rem .4rem .2rem;}
  #featured .dish-grid.home-grid .dish-top h3,
  #search .dish-grid.home-grid .dish-top h3{font-size:.72rem;}
  #featured .dish-grid.home-grid .price,
  #search .dish-grid.home-grid .price{font-size:.8rem;}
  #featured .dish-grid.home-grid .dish-hotel,
  #search .dish-grid.home-grid .dish-hotel{font-size:.58rem;}
  #featured .dish-grid.home-grid .dish-hotel i,
  #search .dish-grid.home-grid .dish-hotel i{font-size:.52rem;}
  #featured .dish-grid.home-grid .btn-order.add-cart,
  #search .dish-grid.home-grid .btn-order.add-cart{width:2.05rem;height:2.05rem;}
  #featured .dish-grid.home-grid .btn-order.add-cart::before,
  #search .dish-grid.home-grid .btn-order.add-cart::before{font-size:.8rem;}
}
</style>
<script>
(function(){
  try {
    var doc = document.documentElement;
    var searchLoad = false, mobileView = false;
    try { searchLoad = !!((new URLSearchParams(location.search).get('q') || '').trim()); } catch (e) {}
    try { mobileView = window.matchMedia('(max-width: 960px)').matches; } catch (e) {}
    /* Mobile search loads scroll to the results instead of restoring the old
       position, so the page must not be hidden while waiting for a restore. */
    if (!(searchLoad && mobileView) && sessionStorage.getItem('lyaideu_scroll_do_restore:1') === location.pathname) {
      doc.classList.add('lyai-restoring');
    }
    window.addEventListener('load', function () { doc.classList.remove('lyai-restoring'); });
  } catch (e) {}
})();
</script>
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="#home"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="index" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search" data-ph-rotate="anything, foods, drinks, products, momo, store, apple" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search LyaiDeu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="#home" class="nav-a active">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
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
    <?php if ($q === ''): ?>
    <section id="home" class="hero hero-split">
        <div class="container hero-split-inner">
            <div class="hero-split-text">
                <p class="kicker"><i class="fa-solid fa-motorcycle"></i> Fast delivery across Surkhet Valley</p>
                <h1 class="display">Delicious food, delivered fast &amp; <em>fresh</em></h1>
                <p class="hero-tagline">Order from your favourite hotels, grab groceries from the mart, and get it all to your door — hot and on time.</p>
                <div class="hero-ctas">
                    <a class="btn btn-primary" href="menu">Order Now <i class="fa-solid fa-arrow-right"></i></a>
                    <a class="btn btn-outline" href="mart">Shop the Mart</a>
                </div>
                <div class="hero-badges">
                    <span><i class="fa-solid fa-truck-fast"></i> 15–60 min delivery</span>
                    <span><i class="fa-solid fa-wallet"></i> eSewa &middot; Khalti &middot; COD</span>
                    <span><i class="fa-solid fa-star"></i> Trusted hotels</span>
                </div>
            </div>
            <div class="hero-split-slider">
                <div class="hero-slider-viewport">
                    <div class="hero-slides" id="heroSlides">
                        <?php foreach (site_hero_slides() as $heroSlide): ?>
                        <div class="hero-slide"><img src="<?= htmlspecialchars($heroSlide, ENT_QUOTES, 'UTF-8') ?>" alt="Hero slide"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hero-slider-dots" id="heroDots"></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($q !== '' && $searchResults): ?>
    <section id="search" class="section">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-magnifying-glass"></i> Search results</p>
                <h2 class="display">Results for &ldquo;<?= lyaideu_featured_e($q) ?>&rdquo;</h2>
                <p class="section-sub"><?= $totalResults > 0 ? $totalResults . ' match' . ($totalResults === 1 ? '' : 'es') . ' found across the menu, mart, other products and partner stores.' : 'No matches found. Try a different word or browse the sections below.' ?></p>
            </div>

            <?php if ($searchResults['dishes']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-utensils"></i> From the Menu</h3>
                    <a class="see-all" href="menu">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid dish-grid home-grid">
                    <?php foreach ($searchResults['dishes'] as $sDish): ?>
                    <article class="dish-card reveal visible" data-id="<?= (int)$sDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($sDish['name']) ?>" data-slug="<?= lyaideu_featured_e($sDish['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($sDish['category_id'] ?? 0), (string)$sDish['cat']))) ?>">
                        <div class="dish-art">
                            <?php if ($sDish['img'] !== ''): ?>
                                <img src="<?= lyaideu_featured_e($sDish['img']) ?>" alt="<?= lyaideu_featured_e($sDish['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sDish['name']) ?></h3></div><?= ($sDish['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($sDish['hotel']) . '</p>' : '' ?>
                        <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($sDish) ?></span><?= lyaideu_featured_deal_pct($sDish) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($sDish) . '%</span>' : '' ?>
                        <button class="btn-order add-cart" data-id="<?= (int)$sDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($sDish['name']) ?>" data-price="<?= lyaideu_featured_deal_price($sDish) ?>" data-unit=""<?= !empty($sDish['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($searchResults['mart']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-basket-shopping"></i> From the Mart</h3>
                    <a class="see-all" href="mart">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid dish-grid home-grid">
                    <?php foreach ($searchResults['mart'] as $sMart): ?>
                    <article class="dish-card reveal visible" data-id="<?= (int)$sMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($sMart['name']) ?>" data-slug="<?= lyaideu_featured_e($sMart['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($sMart['category_id'] ?? 0), (string)$sMart['cat']))) ?>">
                        <div class="dish-art mart-art">
                            <?php if ($sMart['img'] !== ''): ?>
                                <img src="<?= lyaideu_featured_e($sMart['img']) ?>" alt="<?= lyaideu_featured_e($sMart['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= $FEATURED_MART_ICONS[$sMart['cat']] ?? 'fa-basket-shopping' ?>"></i>
                            <?php endif; ?>
                            <?php if ($sMart['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($sMart['tag']) ?></span><?php endif; ?>
                        </div>
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sMart['name']) ?></h3></div><?= ($sMart['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($sMart['hotel']) . '</p>' : '' ?>
                        <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($sMart) ?><?= lyaideu_featured_unit($sMart) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($sMart)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($sMart) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($sMart) . '%</span>' : '' ?>
                        <button class="btn-order add-cart" data-id="<?= (int)$sMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($sMart['name']) ?>" data-price="<?= lyaideu_featured_deal_price($sMart) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($sMart)) ?>" data-hotel="<?= lyaideu_featured_e($sMart['hotel'] ?? '') ?>"<?= !empty($sMart['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($searchResults['others']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-gift"></i> Other Products</h3>
                    <a class="see-all" href="others">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid dish-grid home-grid">
                    <?php foreach ($searchResults['others'] as $sOther): ?>
                    <article class="dish-card reveal visible" data-id="<?= (int)$sOther['id'] ?>" data-type="other" data-name="<?= lyaideu_featured_e($sOther['name']) ?>" data-slug="<?= lyaideu_featured_e($sOther['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($sOther['category_id'] ?? 0), (string)$sOther['cat']))) ?>">
                        <div class="dish-art mart-art">
                            <?php if ($sOther['img'] !== ''): ?>
                                <img src="<?= lyaideu_featured_e($sOther['img']) ?>" alt="<?= lyaideu_featured_e($sOther['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= $FEATURED_OTHER_ICONS[$sOther['cat']] ?? 'fa-gift' ?>"></i>
                            <?php endif; ?>
                            <?php if ($sOther['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($sOther['tag']) ?></span><?php endif; ?>
                        </div>
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sOther['name']) ?></h3></div><?= ($sOther['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($sOther['hotel']) . '</p>' : '' ?>
                        <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($sOther) ?><?= lyaideu_featured_unit($sOther) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($sOther)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($sOther) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($sOther) . '%</span>' : '' ?>
                        <button class="btn-order add-cart" data-id="<?= (int)$sOther['id'] ?>" data-type="other" data-name="<?= lyaideu_featured_e($sOther['name']) ?>" data-price="<?= lyaideu_featured_deal_price($sOther) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($sOther)) ?>" data-hotel="<?= lyaideu_featured_e($sOther['hotel'] ?? '') ?>"<?= !empty($sOther['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($searchResults['beverages']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-glass-water"></i> Beverages</h3>
                    <a class="see-all" href="beverages">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid dish-grid home-grid">
                    <?php foreach ($searchResults['beverages'] as $sBev): ?>
                    <article class="dish-card reveal visible" data-id="<?= (int)$sBev['id'] ?>" data-type="beverage" data-name="<?= lyaideu_featured_e($sBev['name']) ?>" data-slug="<?= lyaideu_featured_e($sBev['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($sBev['category_id'] ?? 0), (string)$sBev['cat']))) ?>">
                        <div class="dish-art mart-art">
                            <?php if ($sBev['img'] !== ''): ?>
                                <img src="<?= lyaideu_featured_e($sBev['img']) ?>" alt="<?= lyaideu_featured_e($sBev['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= $FEATURED_BEVERAGE_ICONS[$sBev['cat']] ?? 'fa-glass-water' ?>"></i>
                            <?php endif; ?>
                            <?php if ($sBev['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($sBev['tag']) ?></span><?php endif; ?>
                        </div>
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sBev['name']) ?></h3></div><?= ($sBev['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($sBev['hotel']) . '</p>' : '' ?>
                        <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($sBev) ?><?= lyaideu_featured_unit($sBev) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($sBev)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($sBev) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($sBev) . '%</span>' : '' ?>
                        <button class="btn-order add-cart" data-id="<?= (int)$sBev['id'] ?>" data-type="beverage" data-name="<?= lyaideu_featured_e($sBev['name']) ?>" data-price="<?= lyaideu_featured_deal_price($sBev) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($sBev)) ?>" data-hotel="<?= lyaideu_featured_e($sBev['hotel'] ?? '') ?>"<?= !empty($sBev['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($searchResults['hotels']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-store"></i> Partner Stores</h3>
                    <a class="see-all" href="store">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid hotels-grid home-grid">
                    <?php foreach ($searchResults['hotels'] as $sHotel): ?>
                    <div class="hotel-card reveal visible" data-store-url="store/<?= lyaideu_slugify((string)$sHotel['name']) ?>">
                        <div class="hotel-avatar">
                            <?php if ($sHotel['logo'] !== ''): ?>
                                <img class="hotel-logo" src="<?= lyaideu_featured_e($sHotel['logo']) ?>" alt="<?= lyaideu_featured_e($sHotel['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= lyaideu_featured_e($sHotel['emoji'] !== '' ? $sHotel['emoji'] : (($sHotel['kind'] ?? '') === 'mart' ? 'fa-basket-shopping' : 'fa-hotel')) ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="hotel-info"><h3><?= lyaideu_featured_e($sHotel['name']) ?></h3><p><?= lyaideu_featured_e($sHotel['type']) ?></p></div>
                        <div class="hotel-call-row">
                            <a class="hotel-call" href="store/<?= lyaideu_slugify((string)$sHotel['name']) ?>"><i class="fa-solid fa-store"></i> View Store</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($totalResults === 0): ?>
            <div class="empty-state" style="display:block"><span class="big"><i class="fa-solid fa-magnifying-glass"></i></span><p>No results found for &ldquo;<?= lyaideu_featured_e($q) ?>&rdquo;. Try a different word.</p></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section id="featured" class="section">
        <div class="container">
            <div class="section-head">
                <h2 class="display">Random Picks for You <i class="fa-solid fa-dice"></i></h2>
                <p class="section-sub">Tasty dishes, grocery essentials, gifts &amp; decor and partner stores — shuffled fresh on every refresh.</p>
            </div>

            <div class="featured-stack">
                <?php if ($featured['dishes']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-utensils"></i> From the Menu</h3>
                        <a class="see-all" href="menu">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredDishes">
                        <?php foreach ($featured['dishes'] as $fDish): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($fDish['name']) ?>" data-slug="<?= lyaideu_featured_e($fDish['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($fDish['category_id'] ?? 0), (string)$fDish['cat']))) ?>">
                            <div class="dish-art">
                                <?php if ($fDish['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fDish['img']) ?>" alt="<?= lyaideu_featured_e($fDish['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="dish-art-ico"><i class="fa-solid fa-utensils"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fDish['name']) ?></h3></div><?= ($fDish['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($fDish['hotel']) . '</p>' : '' ?>
                            <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($fDish) ?></span><?= lyaideu_featured_deal_pct($fDish) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($fDish) . '%</span>' : '' ?>
                            <button class="btn-order add-cart" data-id="<?= (int)$fDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($fDish['name']) ?>" data-price="<?= lyaideu_featured_deal_price($fDish) ?>" data-unit=""<?= !empty($fDish['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['mart']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-basket-shopping"></i> From the Mart</h3>
                        <a class="see-all" href="mart">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredMart">
                        <?php foreach ($featured['mart'] as $fMart): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($fMart['name']) ?>" data-slug="<?= lyaideu_featured_e($fMart['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($fMart['category_id'] ?? 0), (string)$fMart['cat']))) ?>">
                            <div class="dish-art mart-art">
                                <?php if ($fMart['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fMart['img']) ?>" alt="<?= lyaideu_featured_e($fMart['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= $FEATURED_MART_ICONS[$fMart['cat']] ?? 'fa-basket-shopping' ?>"></i>
                                <?php endif; ?>
                                <?php if ($fMart['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($fMart['tag']) ?></span><?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fMart['name']) ?></h3></div><?= ($fMart['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($fMart['hotel']) . '</p>' : '' ?>
                            <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($fMart) ?><?= lyaideu_featured_unit($fMart) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($fMart)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($fMart) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($fMart) . '%</span>' : '' ?>
                            <button class="btn-order add-cart" data-id="<?= (int)$fMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($fMart['name']) ?>" data-price="<?= lyaideu_featured_deal_price($fMart) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($fMart)) ?>" data-hotel="<?= lyaideu_featured_e($fMart['hotel'] ?? '') ?>"<?= !empty($fMart['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['others']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-gift"></i> Other Products</h3>
                        <a class="see-all" href="others">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredOthers">
                        <?php foreach ($featured['others'] as $fOther): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fOther['id'] ?>" data-type="other" data-name="<?= lyaideu_featured_e($fOther['name']) ?>" data-slug="<?= lyaideu_featured_e($fOther['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($fOther['category_id'] ?? 0), (string)$fOther['cat']))) ?>">
                            <div class="dish-art mart-art">
                                <?php if ($fOther['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fOther['img']) ?>" alt="<?= lyaideu_featured_e($fOther['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= $FEATURED_OTHER_ICONS[$fOther['cat']] ?? 'fa-gift' ?>"></i>
                                <?php endif; ?>
                                <?php if ($fOther['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($fOther['tag']) ?></span><?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fOther['name']) ?></h3></div><?= ($fOther['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($fOther['hotel']) . '</p>' : '' ?>
                            <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($fOther) ?><?= lyaideu_featured_unit($fOther) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($fOther)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($fOther) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($fOther) . '%</span>' : '' ?>
                            <button class="btn-order add-cart" data-id="<?= (int)$fOther['id'] ?>" data-type="other" data-name="<?= lyaideu_featured_e($fOther['name']) ?>" data-price="<?= lyaideu_featured_deal_price($fOther) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($fOther)) ?>" data-hotel="<?= lyaideu_featured_e($fOther['hotel'] ?? '') ?>"<?= !empty($fOther['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['beverages']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-glass-water"></i> Beverages</h3>
                        <a class="see-all" href="beverages">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid dish-grid home-grid" id="featuredBeverages">
                        <?php foreach ($featured['beverages'] as $fBev): ?>
                        <article class="dish-card reveal visible" data-id="<?= (int)$fBev['id'] ?>" data-type="beverage" data-name="<?= lyaideu_featured_e($fBev['name']) ?>" data-slug="<?= lyaideu_featured_e($fBev['name_slug'] ?? '') ?>" data-cats="<?= lyaideu_featured_e(implode(',', lyaideu_item_cats((int)($fBev['category_id'] ?? 0), (string)$fBev['cat']))) ?>">
                            <div class="dish-art mart-art">
                                <?php if ($fBev['img'] !== ''): ?>
                                    <img src="<?= lyaideu_featured_e($fBev['img']) ?>" alt="<?= lyaideu_featured_e($fBev['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= $FEATURED_BEVERAGE_ICONS[$fBev['cat']] ?? 'fa-glass-water' ?>"></i>
                                <?php endif; ?>
                                <?php if ($fBev['tag'] !== ''): ?><span class="dish-tag"><?= lyaideu_featured_e($fBev['tag']) ?></span><?php endif; ?>
                            </div>
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fBev['name']) ?></h3></div><?= ($fBev['hotel'] ?? '') !== '' ? '<p class="dish-hotel"><i class="fa-solid fa-store"></i> ' . lyaideu_featured_e($fBev['hotel']) . '</p>' : '' ?>
                            <div class="dish-foot"><span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= lyaideu_featured_deal_price($fBev) ?><?= lyaideu_featured_unit($fBev) !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e(lyaideu_featured_unit($fBev)) . '</span>' : '' ?></span><?= lyaideu_featured_deal_pct($fBev) > 0 ? '<span class="deal-badge deal-badge-inline">-' . lyaideu_featured_deal_pct($fBev) . '%</span>' : '' ?>
                            <button class="btn-order add-cart" data-id="<?= (int)$fBev['id'] ?>" data-type="beverage" data-name="<?= lyaideu_featured_e($fBev['name']) ?>" data-price="<?= lyaideu_featured_deal_price($fBev) ?>" data-unit="<?= lyaideu_featured_e(lyaideu_featured_unit($fBev)) ?>" data-hotel="<?= lyaideu_featured_e($fBev['hotel'] ?? '') ?>"<?= !empty($fBev['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['hotels']): ?>
                <div class="feat-block partner-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-hotel"></i> Partner Hotels</h3>
                        <a class="see-all" href="store">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredHotels">
                        <?php foreach ($featured['hotels'] as $fHotel): ?>
                        <div class="hotel-card reveal visible" data-store-url="store/<?= lyaideu_slugify((string)$fHotel['name']) ?>">
                            <div class="hotel-avatar">
                                <?php if ($fHotel['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fHotel['logo']) ?>" alt="<?= lyaideu_featured_e($fHotel['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fHotel['emoji'] !== '' ? $fHotel['emoji'] : 'fa-hotel') ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fHotel['name']) ?></h3><p><?= lyaideu_featured_e($fHotel['type']) ?></p></div>
                            <div class="hotel-call-row">
                                <a class="hotel-call" href="store/<?= lyaideu_slugify((string)$fHotel['name']) ?>"><i class="fa-solid fa-store"></i> View Store</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['mart_stores']): ?>
                <div class="feat-block partner-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-basket-shopping"></i> Mart Partner</h3>
                        <a class="see-all" href="store">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredMartStores">
                        <?php foreach ($featured['mart_stores'] as $fMartStore): ?>
                        <div class="hotel-card reveal visible" data-store-url="store/<?= lyaideu_slugify((string)$fMartStore['name']) ?>">
                            <div class="hotel-avatar">
                                <?php if ($fMartStore['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fMartStore['logo']) ?>" alt="<?= lyaideu_featured_e($fMartStore['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fMartStore['emoji'] !== '' ? $fMartStore['emoji'] : 'fa-basket-shopping') ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fMartStore['name']) ?></h3><p><?= lyaideu_featured_e($fMartStore['type']) ?></p></div>
                            <div class="hotel-call-row">
                                <a class="hotel-call" href="store/<?= lyaideu_slugify((string)$fMartStore['name']) ?>"><i class="fa-solid fa-store"></i> View Store</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['other_stores']): ?>
                <div class="feat-block partner-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-gift"></i> Other Stores</h3>
                        <a class="see-all" href="store">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredOtherStores">
                        <?php foreach ($featured['other_stores'] as $fOtherStore): ?>
                        <div class="hotel-card reveal visible" data-store-url="store/<?= lyaideu_slugify((string)$fOtherStore['name']) ?>">
                            <div class="hotel-avatar">
                                <?php if ($fOtherStore['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fOtherStore['logo']) ?>" alt="<?= lyaideu_featured_e($fOtherStore['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fOtherStore['emoji'] !== '' ? $fOtherStore['emoji'] : 'fa-gift') ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fOtherStore['name']) ?></h3><p><?= lyaideu_featured_e($fOtherStore['type']) ?></p></div>
                            <div class="hotel-call-row">
                                <a class="hotel-call" href="store/<?= lyaideu_slugify((string)$fOtherStore['name']) ?>"><i class="fa-solid fa-store"></i> View Store</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['partners']): ?>
                <!-- Mobile-only: the three store ribbons above merge into one
                     "Our Trusted Partners" section (max 8 cards). Hidden on
                     desktop, where the separate ribbons stay. -->
                <div class="feat-block partner-block-mobile">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-handshake"></i> Our Trusted Partners</h3>
                        <a class="see-all" href="store">View all <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredPartners">
                        <?php foreach ($featured['partners'] as $fPartner): ?>
                        <div class="hotel-card reveal visible" data-store-url="store/<?= lyaideu_slugify((string)$fPartner['name']) ?>">
                            <div class="hotel-avatar">
                                <?php if ($fPartner['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fPartner['logo']) ?>" alt="<?= lyaideu_featured_e($fPartner['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fPartner['emoji'] !== '' ? $fPartner['emoji'] : (($fPartner['kind'] ?? '') === 'mart' ? 'fa-basket-shopping' : (($fPartner['kind'] ?? '') === 'other' ? 'fa-gift' : 'fa-hotel'))) ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fPartner['name']) ?></h3><p><?= lyaideu_featured_e($fPartner['type']) ?></p></div>
                            <div class="hotel-call-row">
                                <a class="hotel-call" href="store/<?= lyaideu_slugify((string)$fPartner['name']) ?>"><i class="fa-solid fa-store"></i> View Store</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script src="js/featured-order.js?v=6"></script>

    <section id="faq" class="section section-white">
        <div class="container">
            <div class="section-head">
                <h2 class="display">Frequently Asked Questions <i class="fa-solid fa-circle-question"></i></h2>
                <p class="section-sub">Everything you need to know about ordering, delivery and payments on LyaiDeu.</p>
            </div>
            <div class="faq-list">
                <details class="faq-item" open>
                    <summary>How do I place an order?</summary>
                    <p>Head over to the Menu page, add your favourite dishes to the cart, and check out with your delivery details. Your order goes straight to the partner hotel — we'll confirm it by phone if needed.</p>
                </details>
                <details class="faq-item">
                    <summary>How long does delivery take?</summary>
                    <p>Mart items are ready-made, so a Mart-only order arrives in as little as <strong>15 minutes</strong>. Food from hotels is freshly cooked, so hotel orders take <strong>45–60 minutes</strong> depending on how many hotels your order mixes in. Exact time depends on distance, traffic and how busy the kitchen is.</p>
                </details>
                <details class="faq-item">
                    <summary>How much is delivery?</summary>
                    <p>Delivery starts at Rs. 50 to anywhere in the valley, and goes up a little when your order mixes items from more than one hotel or the Mart (more stops = a bit more time and fee). Your first order is free with the code <strong>LYAIDEU</strong> — and promo codes like <strong>FOODXPRESS</strong> give you free delivery too.</p>
                </details>
                <details class="faq-item">
                    <summary>What payment methods do you accept?</summary>
                    <p>We accept Cash on Delivery and eSewa / Khalti on delivery. Please have the exact amount ready for a smooth handover.</p>
                </details>
                <details class="faq-item">
                    <summary>Can I track my order?</summary>
                    <p>Yes! Open the Orders page at any time to see your order status — from Pending and Preparing to Out for delivery and Delivered. The page refreshes live automatically.</p>
                </details>
                <details class="faq-item">
                    <summary>What if my order is late or wrong?</summary>
                    <p>We're sorry about that! Call our Delivery Support line immediately and we'll fix it fast — re-delivery, replacement or a refund, whichever fits best.</p>
                </details>
            </div>
        </div>
    </section>

    <section id="contact" class="section section-contact">
        <div class="container">
            <div class="contact-split">
                <div class="contact-split-left">
                    <div class="section-head">
                        <h2 class="display">Contact Our Service Team <i class="fa-solid fa-phone"></i></h2>
                    </div>
                    <div class="grid contact-grid" id="contact-grid"></div>
                    <div class="contact-map">
                        <iframe src="https://www.google.com/maps?q=Birendranagar%2C%20Surkhet%2C%20Nepal&output=embed" width="100%" height="280" style="border:0" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="LyaiDeu location — Birendranagar, Surkhet, Nepal"></iframe>
                    </div>
                </div>
                <div class="contact-split-right">
                    <div class="section-head">
                        <h2 class="display">Send Us a Message <i class="fa-solid fa-envelope"></i></h2>
                        <p class="section-sub">Questions, feedback or partnership ideas — drop us a line and our team will reply soon.</p>
                    </div>
                    <form action="contact_send" method="POST" class="contact-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_contact'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="contact-form-row">
                            <div><label>Your Name</label><input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" placeholder="Full name" required></div>
                            <div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="you@example.com" required></div>
                        </div>
                        <div class="contact-form-row">
                            <div><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="98XXXXXXXX"></div>
                            <div><label>Subject</label><input type="text" name="subject" placeholder="e.g. Order help, feedback…"></div>
                        </div>
                        <label>Message</label>
                        <textarea name="message" rows="5" placeholder="Tell us how we can help…" minlength="10" required></textarea>
                        <button type="submit" class="btn btn-primary contact-submit"><i class="fa-solid fa-paper-plane"></i> Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>


<button class="cart-fab cart-open-btn" type="button" aria-label="Open cart"><span class="cart-fab-icon"><i class="fa-solid fa-cart-shopping"></i></span><span class="cart-fab-label">Cart</span><span class="cart-count">0</span></button>
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-head"><h2><i class="fa-solid fa-cart-shopping"></i> Your Cart</h2><button type="button" class="cart-close" id="cartClose">×</button></div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Your cart is waiting for something tasty. <i class="fa-solid fa-pizza-slice"></i></div>
  <div class="cart-summary"><div class="summary-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0</strong></div><div class="summary-row"><span>Delivery</span><strong id="cartDelivery">Rs. 50</strong></div><div class="summary-row total"><span>Estimated total</span><strong id="cartTotal">Rs. 50</strong></div><a href="checkout" class="btn btn-primary btn-block" id="checkoutBtn">Checkout <i class="fa-solid fa-arrow-right"></i></a><button class="btn btn-outline btn-block" id="clearCart" type="button">Clear Cart</button></div>
</aside>
<?= lyaideu_footer_html() ?>

<script src="js/script.js?v=39"></script>
<script src="js/scroll-memory.js?v=6"></script>
<script src="js/notify.js?v=8"></script>
</body>
</html>