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

$featured = ['dishes' => [], 'mart' => [], 'hotels' => [], 'mart_stores' => []];
$featuredPdo = lyaideu_load_pdo();
if ($featuredPdo instanceof PDO) {
    try {
        lyaideu_ensure_stores();
        $featured['dishes'] = $featuredPdo->query('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug FROM dishes')->fetchAll();
        $featured['mart']   = $featuredPdo->query('SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, name_slug FROM mart_items')->fetchAll();
        $featured['stores'] = $featuredPdo->query('SELECT id, name, type, phone, emoji, logo, kind FROM hotels')->fetchAll();
    } catch (Throwable $e) {
        $featured = ['dishes' => [], 'mart' => [], 'hotels' => [], 'mart_stores' => []];
    }
    $featured['hotels']      = array_values(array_filter($featured['stores'] ?? [], fn($s) => ($s['kind'] ?? 'hotel') === 'hotel'));
    $featured['mart_stores'] = array_values(array_filter($featured['stores'] ?? [], fn($s) => ($s['kind'] ?? '') === 'mart'));
    shuffle($featured['dishes']);
    shuffle($featured['mart']);
    shuffle($featured['hotels']);
    shuffle($featured['mart_stores']);
    $featured['dishes'] = array_slice($featured['dishes'], 0, 12);
    $featured['mart']   = array_slice($featured['mart'], 0, 12);
    $featured['hotels'] = array_slice($featured['hotels'], 0, 8);
    $featured['mart_stores'] = array_slice($featured['mart_stores'], 0, 4);
}

$searchResults = null;
if ($q !== '' && $featuredPdo instanceof PDO) {
    $searchResults = ['dishes' => [], 'mart' => [], 'hotels' => []];
    try {
        $qp = '%' . $q . '%';
        $st = $featuredPdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug FROM dishes WHERE name LIKE ? OR tag LIKE ? OR `desc` LIKE ? ORDER BY name LIMIT 30');
        $st->execute([$qp, $qp, $qp]);
        $searchResults['dishes'] = $st->fetchAll();
        $st = $featuredPdo->prepare('SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, name_slug FROM mart_items WHERE name LIKE ? OR tag LIKE ? OR `desc` LIKE ? ORDER BY name LIMIT 30');
        $st->execute([$qp, $qp, $qp]);
        $searchResults['mart'] = $st->fetchAll();
        $st = $featuredPdo->prepare('SELECT id, name, type, phone, emoji, logo, kind FROM hotels WHERE name LIKE ? OR type LIKE ? ORDER BY name LIMIT 20');
        $st->execute([$qp, $qp]);
        $searchResults['hotels'] = $st->fetchAll();
    } catch (Throwable $e) {
        $searchResults = null;
    }
}
$totalResults = $searchResults ? count($searchResults['dishes']) + count($searchResults['mart']) + count($searchResults['hotels']) : 0;

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
<?= lyaideu_base_tag() ?>
<title><?= $user ? 'LyaiDeu · Namaste, ' . htmlspecialchars($firstName) . '!' : 'LyaiDeu · Food Delivery in Surkhet Valley' ?></title>
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
        <a class="brand" href="#home"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="index" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search dishes, mart &amp; hotels" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search LyaiDeu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="#home" class="nav-a active">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="hotels" class="nav-a">Stores</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="orders" class="nav-a">Orders</a></li>
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
                <p class="section-sub"><?= $totalResults > 0 ? $totalResults . ' match' . ($totalResults === 1 ? '' : 'es') . ' found across the menu, mart and partner hotels.' : 'No matches found. Try a different word or browse the sections below.' ?></p>
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
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sDish['name']) ?></h3></div>
                        <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$sDish['price'] ?></span>
                        <button class="btn-order add-cart" data-id="<?= (int)$sDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($sDish['name']) ?>" data-price="<?= (int)$sDish['price'] ?>" data-unit="" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div>
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
                        <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($sMart['name']) ?></h3></div>
                        <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$sMart['price'] ?><?= $sMart['unit'] !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e($sMart['unit']) . '</span>' : '' ?></span>
                        <button class="btn-order add-cart" data-id="<?= (int)$sMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($sMart['name']) ?>" data-price="<?= (int)$sMart['price'] ?>" data-unit="<?= lyaideu_featured_e($sMart['unit']) ?>" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($searchResults['hotels']): ?>
            <div class="feat-block">
                <div class="feat-ribbon">
                    <h3><i class="fa-solid fa-store"></i> Partner Stores</h3>
                    <a class="see-all" href="hotels">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="grid hotels-grid home-grid">
                    <?php foreach ($searchResults['hotels'] as $sHotel): ?>
                    <div class="hotel-card reveal visible">
                        <div class="hotel-avatar">
                            <?php if ($sHotel['logo'] !== ''): ?>
                                <img class="hotel-logo" src="<?= lyaideu_featured_e($sHotel['logo']) ?>" alt="<?= lyaideu_featured_e($sHotel['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fa-solid <?= lyaideu_featured_e($sHotel['emoji'] !== '' ? $sHotel['emoji'] : (($sHotel['kind'] ?? '') === 'mart' ? 'fa-basket-shopping' : 'fa-hotel')) ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="hotel-info"><h3><?= lyaideu_featured_e($sHotel['name']) ?></h3><p><?= lyaideu_featured_e($sHotel['type']) ?></p></div>
                        <?php if (($sHotel['kind'] ?? '') === 'mart'): ?>
                        <a class="hotel-call" href="mart"><i class="fa-solid fa-basket-shopping"></i> Shop the Mart</a>
                        <?php else: ?>
                        <a class="hotel-call" href="tel:+977<?= lyaideu_featured_e($sHotel['phone']) ?>"><i class="fa-solid fa-phone"></i> <?= lyaideu_featured_e($sHotel['phone']) ?></a>
                        <?php endif; ?>
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
                <p class="section-sub">Tasty dishes, grocery essentials and partner stores — shuffled fresh on every refresh.</p>
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
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fDish['name']) ?></h3></div>
                            <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$fDish['price'] ?></span>
                            <button class="btn-order add-cart" data-id="<?= (int)$fDish['id'] ?>" data-type="dish" data-name="<?= lyaideu_featured_e($fDish['name']) ?>" data-price="<?= (int)$fDish['price'] ?>" data-unit="" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div>
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
                            <div class="dish-body"><div class="dish-top"><h3><?= lyaideu_featured_e($fMart['name']) ?></h3></div>
                            <div class="dish-foot"><span class="price"><small>Rs.</small> <?= (int)$fMart['price'] ?><?= $fMart['unit'] !== '' ? ' <span class="unit">/ ' . lyaideu_featured_e($fMart['unit']) . '</span>' : '' ?></span>
                            <button class="btn-order add-cart" data-id="<?= (int)$fMart['id'] ?>" data-type="mart" data-name="<?= lyaideu_featured_e($fMart['name']) ?>" data-price="<?= (int)$fMart['price'] ?>" data-unit="<?= lyaideu_featured_e($fMart['unit']) ?>" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button></div></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($featured['hotels']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-hotel"></i> Partner Hotels</h3>
                        <a class="see-all" href="hotels">View all <i class="fa-solid fa-arrow-right"></i></a>
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

                <?php if ($featured['mart_stores']): ?>
                <div class="feat-block">
                    <div class="feat-ribbon">
                        <h3><i class="fa-solid fa-basket-shopping"></i> Mart Partner</h3>
                        <a class="see-all" href="mart">Shop the Mart <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                    <div class="grid hotels-grid home-grid" id="featuredMartStores">
                        <?php foreach ($featured['mart_stores'] as $fMartStore): ?>
                        <div class="hotel-card reveal visible">
                            <div class="hotel-avatar">
                                <?php if ($fMartStore['logo'] !== ''): ?>
                                    <img class="hotel-logo" src="<?= lyaideu_featured_e($fMartStore['logo']) ?>" alt="<?= lyaideu_featured_e($fMartStore['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid <?= lyaideu_featured_e($fMartStore['emoji'] !== '' ? $fMartStore['emoji'] : 'fa-basket-shopping') ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="hotel-info"><h3><?= lyaideu_featured_e($fMartStore['name']) ?></h3><p><?= lyaideu_featured_e($fMartStore['type']) ?></p></div>
                            <a class="hotel-call" href="mart"><i class="fa-solid fa-basket-shopping"></i> Shop the Mart</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

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
<footer class="footer">
    <div class="footer-grid">
        <div><p class="footer-brand"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"></p><p class="footer-blurb">Nepal's friendliest food delivery service — connecting you to the best hotels in the valley.</p></div>
        <div><h4>Quick Links</h4><ul><li><a href="#home">Home</a></li><li><a href="menu">Menu</a></li><li><a href="hotels">Stores</a></li><li><a href="mart">Mart</a></li><li><a href="contact">Contact</a></li><li><a href="faq">FAQ &amp; Privacy</a></li><li><a href="terms">Terms of Service</a></li><li><a href="demo.html"><i class="fa-solid fa-film"></i> Product Demo</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> Lazimpat, Kathmandu</li><li><i class="fa-solid fa-envelope"></i> hello@lyaideu.com.np</li><li><i class="fa-solid fa-phone"></i> 9800000001</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>Sun – Fri: 7 AM – 10 PM</li><li>Saturday: 8 AM – 10 PM</li><li><i class="fa-solid fa-motorcycle"></i> Deliveries every day!</li></ul></div>
    </div>
    <div class="footer-bottom">© <span id="year">2026</span> LyaiDeu · All rights reserved.</div>
</footer>

<script src="js/script.js?v=16"></script>
<script src="js/notify.js?v=4"></script>
</body>
</html>