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
lyaideu_ensure_categories_table();
lyaideu_ensure_sections_tables();
lyaideu_ensure_variant_tables();
lyaideu_ensure_discount_columns();

$sce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$sectionSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_GET['s'] ?? ''))));
$catSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_GET['cat'] ?? ''))));

$sectionRow = null;
foreach (lyaideu_custom_sections(true) as $s) {
    if ((string)$s['slug'] === $sectionSlug) {
        $sectionRow = $s;
        break;
    }
}
if (!$sectionRow) {
    header('Location: categories');
    exit;
}

$secCats = lyaideu_visible_categories((string)$sectionRow['slug']);
$secParents = array_values(array_filter($secCats, fn($c) => $c['parent_id'] === null));
$secChildren = [];
foreach ($secCats as $c) {
    if ($c['parent_id'] !== null) {
        $secChildren[(int)$c['parent_id']][] = $c;
    }
}

$scopeCat = null;
if ($catSlug !== '') {
    foreach ($secCats as $c) {
        if ((string)$c['slug'] === $catSlug) {
            $scopeCat = $c;
            break;
        }
    }
}

$scopeIds = [];
if ($scopeCat) {
    $scopeIds[(int)$scopeCat['id']] = true;
    $frontier = [(int)$scopeCat['id']];
    while ($frontier) {
        $cur = array_shift($frontier);
        foreach ($secChildren[$cur] ?? [] as $cc) {
            $cid = (int)$cc['id'];
            if (!isset($scopeIds[$cid])) {
                $scopeIds[$cid] = true;
                $frontier[] = $cid;
            }
        }
    }
} else {
    foreach ($secCats as $c) {
        $scopeIds[(int)$c['id']] = true;
    }
}

$products = [];
$pdo = lyaideu_load_pdo();
if ($pdo instanceof PDO && $scopeIds) {
    try {
        $ph = implode(',', array_fill(0, count($scopeIds), '?'));
        $sql =
            "SELECT 'dish' AS itype, d.id, d.name, d.cat, d.price, d.discount_percent, d.tag, '' AS unit, d.`desc`, d.img, d.category_id, d.name_slug AS slug, d.has_variants, d.hotel AS hotel
             FROM dishes d JOIN section_item_links sil ON sil.item_type = 'dish' AND sil.item_id = d.id
             WHERE sil.category_id IN ($ph)
             UNION ALL
             SELECT 'mart' AS itype, m.id, m.name, m.cat, m.price, m.discount_percent, m.tag, m.unit, m.`desc`, m.img, m.category_id, m.name_slug AS slug, m.has_variants, COALESCE(h.name, '') AS hotel
             FROM mart_items m JOIN section_item_links sil ON sil.item_type = 'mart' AND sil.item_id = m.id
             LEFT JOIN vendors v ON v.id = m.vendor_id LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE sil.category_id IN ($ph)
             UNION ALL
             SELECT 'beverage' AS itype, b.id, b.name, b.cat, b.price, b.discount_percent, b.tag, b.unit, b.`desc`, b.img, b.category_id, b.name_slug AS slug, b.has_variants, COALESCE(h.name, '') AS hotel
             FROM beverage_items b JOIN section_item_links sil ON sil.item_type = 'beverage' AND sil.item_id = b.id
             LEFT JOIN vendors v ON v.id = b.vendor_id LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE sil.category_id IN ($ph)
             UNION ALL
             SELECT 'other' AS itype, o.id, o.name, o.cat, o.price, o.discount_percent, o.tag, o.unit, o.`desc`, o.img, o.category_id, o.name_slug AS slug, o.has_variants, COALESCE(h.name, '') AS hotel
             FROM other_items o JOIN section_item_links sil ON sil.item_type = 'other' AND sil.item_id = o.id
             LEFT JOIN vendors v ON v.id = o.vendor_id LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE sil.category_id IN ($ph)
             ORDER BY name";
        $bind = array_merge(array_keys($scopeIds), array_keys($scopeIds), array_keys($scopeIds), array_keys($scopeIds));
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $seenItem = [];
        $searchNeedle = mb_strtolower($q, 'UTF-8');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['itype'] . ':' . (int)$row['id'];
            if (isset($seenItem[$key])) {
                continue;
            }
            $seenItem[$key] = true;
            $natCat = (int)($row['category_id'] ?? 0);
            if ($natCat > 0 && !lyaideu_category_is_active($natCat)) {
                continue;
            }
            if ($searchNeedle !== '') {
                $hay = mb_strtolower((string)$row['name'] . ' ' . (string)$row['desc'], 'UTF-8');
                if (mb_strpos($hay, $searchNeedle) === false) {
                    continue;
                }
            }
            $products[] = $row;
        }
    } catch (Throwable $e) {
        $products = [];
    }
}

$urlBaseOf = ['dish' => 'menu', 'mart' => 'mart', 'beverage' => 'beverages', 'other' => 'others'];
$fallbackIcoOf = ['dish' => 'fa-utensils', 'mart' => 'fa-basket-shopping', 'beverage' => 'fa-glass-water', 'other' => 'fa-gift'];
$cardHref = function (array $p) use ($urlBaseOf): string {
    $base = $urlBaseOf[(string)$p['itype']] ?? 'menu';
    $cats = lyaideu_item_cats(isset($p['category_id']) ? (int)$p['category_id'] : 0, (string)($p['cat'] ?? ''));
    $path = $cats ? implode('/', array_map('rawurlencode', $cats)) . '/' : '';
    return htmlspecialchars($base . '/' . $path . rawurlencode((string)$p['slug']), ENT_QUOTES, 'UTF-8');
};

$secName = (string)$sectionRow['name'];
$secIcon = (string)$sectionRow['icon'] !== '' ? (string)$sectionRow['icon'] : 'fa-layer-group';
$secDesc = (string)$sectionRow['desc'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title><?= $sce($secName) ?> | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=62">
<link rel="stylesheet" href="css/cards-mobile.css?v=15">
<style>.chip{text-decoration:none;}.chip.sub-chip{font-size:.78rem;padding:.4rem .8rem;}</style>
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="section" method="get" role="search">
            <input type="hidden" name="s" value="<?= $sce($sectionSlug) ?>">
            <?php if ($scopeCat): ?><input type="hidden" name="cat" value="<?= $sce((string)$scopeCat['slug']) ?>"><?php endif; ?>
            <span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in <?= $sce($secName) ?>" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search <?= $sce($secName) ?>">
        </form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="categories" class="nav-a active">Categories</a></li>
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
    <section id="custom-section" class="section">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid <?= $sce($secIcon) ?>"></i> <?= $sce($secDesc !== '' ? $secDesc : 'A LyaiDeu collection') ?></p>
                <h1 class="display"><?= $sce($secName) ?><?= $scopeCat ? ' · ' . $sce((string)$scopeCat['name']) : '' ?> <i class="fa-solid <?= $sce($secIcon) ?>"></i></h1>
                <p class="section-sub">Hand-picked items from all our sections, gathered in one place.</p>
                <div class="hero-actions" style="margin-top:1.2rem;">
                    <button class="btn btn-primary cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> View Cart <span class="cart-count">0</span></button>
                </div>
            </div>
            <?php if ($secParents): ?>
            <div class="menu-toolbar"><div class="chip-row">
                <a class="chip<?= !$scopeCat ? ' active' : '' ?>" href="section?s=<?= $sce($sectionSlug) ?>">All</a>
                <?php foreach ($secParents as $pc):
                    $pcActive = $scopeCat && (int)$scopeCat['id'] === (int)$pc['id'];
                ?>
                <a class="chip<?= $pcActive ? ' active' : '' ?>" href="section?s=<?= $sce($sectionSlug) ?>&amp;cat=<?= $sce((string)$pc['slug']) ?>"><?= $sce($pc['name']) ?></a>
                <?php foreach ($secChildren[(int)$pc['id']] ?? [] as $cc): ?>
                <a class="chip sub-chip<?= $scopeCat && (int)$scopeCat['id'] === (int)$cc['id'] ? ' active' : '' ?>" href="section?s=<?= $sce($sectionSlug) ?>&amp;cat=<?= $sce((string)$cc['slug']) ?>"><?= $sce($cc['name']) ?></a>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div></div>
            <?php endif; ?>

            <?php if (!$secCats): ?>
            <div class="empty-state"><span class="big"><i class="fa-solid <?= $sce($secIcon) ?>"></i></span><p>This section is being set up — check back soon!</p></div>
            <?php elseif (!$products): ?>
            <div class="empty-state"><span class="big"><i class="fa-solid fa-box-open"></i></span><p>No items here yet<?= $q ? ' for "' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"' : '' ?>.</p></div>
            <?php else: ?>
            <div class="grid dish-grid" id="section-grid">
                <?php foreach ($products as $p):
                    $itype = (string)$p['itype'];
                    $pct = lyaideu_deal_percent((int)($p['discount_percent'] ?? 0));
                    $basePrice = max(0, (int)$p['price']);
                    $now = lyaideu_deal_price($basePrice, $pct);
                    $unit = trim((string)($p['unit'] ?? ''));
                    $hotel = trim((string)($p['hotel'] ?? ''));
                    $img = trim((string)($p['img'] ?? ''));
                    $catsAttr = implode(',', lyaideu_item_cats(isset($p['category_id']) ? (int)$p['category_id'] : 0, (string)($p['cat'] ?? '')));
                ?>
                <article class="dish-card reveal visible" data-id="<?= (int)$p['id'] ?>" data-slug="<?= $sce((string)$p['slug']) ?>" data-cats="<?= $sce($catsAttr) ?>" data-url="<?= $cardHref($p) ?>">
                    <div class="dish-art mart-art">
                        <?php if ($img !== ''): ?><img src="<?= $sce($img) ?>" alt="<?= $sce((string)$p['name']) ?>" loading="lazy"><?php else: ?><span class="dish-art-ico"><i class="fa-solid <?= $sce($fallbackIcoOf[$itype] ?? 'fa-tags') ?>"></i></span><?php endif; ?>
                        <?php if (trim((string)($p['tag'] ?? '')) !== ''): ?><span class="dish-tag"><?= $sce((string)$p['tag']) ?></span><?php endif; ?>
                    </div>
                    <div class="dish-body">
                        <div class="dish-top"><h3><?= $sce((string)$p['name']) ?></h3></div>
                        <?php if ($hotel !== ''): ?><p class="dish-hotel"><i class="fa-solid fa-store"></i> <?= $sce($hotel) ?></p><?php endif; ?>
                        <div class="dish-foot">
                            <span class="price"><small class="rs-l">Rs.</small><small class="rs-s" aria-hidden="true">रु</small> <?= (int)$now ?><?php if ($unit !== ''): ?> <span class="unit">/ <?= $sce($unit) ?></span><?php endif; ?></span>
                            <?php if ($pct > 0 && $basePrice > 0): ?><span class="deal-badge deal-badge-inline">-<?= $pct ?>%</span><?php endif; ?>
                            <button class="btn-order add-cart" data-id="<?= (int)$p['id'] ?>" data-type="<?= $sce($itype) ?>" data-name="<?= $sce((string)$p['name']) ?>" data-price="<?= (int)$now ?>" data-unit="<?= $sce($unit) ?>" data-hotel="<?= $sce($hotel) ?>" data-cats="<?= $sce($catsAttr) ?>" data-slug="<?= $sce((string)$p['slug']) ?>" data-url="<?= $cardHref($p) ?>"<?= !empty($p['has_variants']) ? ' data-has-variants="1"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i><span class="add-label">Add</span></button>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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

<script>
document.addEventListener('click', function (e) {
    var card = e.target.closest('#section-grid .dish-card');
    if (!card) return;
    if (e.target.closest('.add-cart') || e.target.closest('.btn-order')) return;
    var url = card.dataset.url;
    if (url) window.location.href = url;
});
</script>
<script src="js/script.js?v=39"></script>
<script src="js/scroll-memory.js?v=6"></script>
<script src="js/notify.js?v=8"></script>
</body>
</html>
