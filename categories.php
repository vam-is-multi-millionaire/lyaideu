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
require_once __DIR__ . '/seo.php';
lyaideu_ensure_categories_table();
lyaideu_ensure_sections_tables();

$catGroups = lyaideu_section_groups();

$catTrees = [];
foreach (array_keys($catGroups) as $type) {
    $cats = lyaideu_visible_categories($type);
    $parents = array_values(array_filter($cats, fn($c) => $c['parent_id'] === null));
    $children = [];
    foreach ($cats as $c) {
        if ($c['parent_id'] !== null) {
            $children[(int)$c['parent_id']][] = $c;
        }
    }
    $catTrees[$type] = ['parents' => $parents, 'children' => $children];
}

$catGroupsJson = [];
$catTreesJson = [];
foreach ($catGroups as $type => $group) {
    $catGroupsJson[$type] = [
        'label'  => $group['label'],
        'page'   => $group['page'],
        'param'  => $group['param'],
        'pool'   => (string)($group['pool'] ?? ''),
        'custom' => !empty($group['custom']),
    ];
    $flat = [];
    $walk = function (array $parents, int $depth) use (&$walk, &$flat, &$catTrees, $type): void {
        foreach ($parents as $c) {
            $flat[] = [
                'id'        => (int)$c['id'],
                'name'      => $c['name'],
                'slug'      => $c['slug'],
                'parent_id' => $c['parent_id'] === null ? null : (int)$c['parent_id'],
                'image'     => lyaideu_category_image_url($c),
                'icon'      => (string)($c['icon'] ?? ''),
                'depth'     => $depth,
            ];
            if (!empty($catTrees[$type]['children'][(int)$c['id']])) {
                $walk($catTrees[$type]['children'][(int)$c['id']], $depth + 1);
            }
        }
    };
    $walk($catTrees[$type]['parents'], 0);
    $catTreesJson[$type] = $flat;
}
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<?php echo lyaideu_seo_page([
    'title' => 'All Categories — Food, Grocery & More Delivery in Surkhet | LyaiDeu',
    'desc' => 'Browse every LyaiDeu category — food from Birendranagar hotels, fresh groceries, beverages, flowers, gifts & more. Anything delivered across the Surkhet Valley.',
    'path' => 'categories',
    'robots' => $q !== '' ? 'noindex, follow' : 'index, follow',
    'keywords' => ['categories lyai deu', 'online shopping surkhet', 'birendranagar delivery categories'],
]); ?>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=63">
<link rel="stylesheet" href="css/categories-mobile.css?v=11">
<link rel="stylesheet" href="css/cards-mobile.css?v=15">
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="index" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search" data-ph-rotate="anything, foods, drinks, products, momo, store, apple" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search LyaiDeu"></form>
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
    <section id="categories" class="section">
        <div class="container">
            <?php foreach ($catGroups as $type => $group):
                $tree = $catTrees[$type];
                if (!$tree['parents']) continue;
            ?>
            <div class="cat-section">
                <div class="cat-section-head">
                    <span class="cat-section-ico"><i class="fa-solid <?= $ce($group['icon']) ?>"></i></span>
                    <h2><?= $ce($group['label']) ?></h2>
                    <a class="see-all" href="<?= $ce($group['page']) ?>">View all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <p class="cat-section-desc"><?= $ce($group['desc']) ?></p>
                <div class="cat-grid">
                    <?php foreach ($tree['parents'] as $pc):
                        $pcImg = lyaideu_category_image_url($pc);
                    ?>
                    <div class="cat-card<?= $pcImg !== '' ? ' has-img' : '' ?>">
                        <a class="cat-card-main" href="<?= lyaideu_group_category_href($group, (string)$pc['slug']) ?>" data-mc-open="<?= $ce($type) ?>" data-mc-slug="<?= $ce($pc['slug']) ?>">
                            <?php if ($pcImg !== ''): ?><span class="cat-card-img-wrap"><img class="cat-card-img" src="<?= $ce($pcImg) ?>" alt="<?= $ce($pc['name']) ?>" loading="lazy"></span><?php endif; ?>
                            <strong><?= $ce($pc['name']) ?></strong>
                            <i class="cat-card-arrow fa-solid fa-chevron-right"></i>
                        </a>
                        <?php if (!empty($tree['children'][(int)$pc['id']])): ?>
                        <div class="cat-card-children">
                            <?php foreach ($tree['children'][(int)$pc['id']] as $cc): ?>
                            <a class="cat-pill" href="<?= lyaideu_group_category_href($group, (string)$cc['slug']) ?>" data-mc-open="<?= $ce($type) ?>" data-mc-slug="<?= $ce($cc['slug']) ?>"><?= $ce($cc['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<div class="mc-view" id="mcView" aria-hidden="true">
    <header class="mc-head">
        <button type="button" class="mc-back" id="mcBack" aria-label="Back to categories"><i class="fa-solid fa-arrow-left"></i></button>
        <div class="mc-head-titles">
            <strong class="mc-head-label" id="mcHeadLabel">Categories</strong>
            <span class="mc-head-sub" id="mcHeadSub"></span>
        </div>
    </header>
    <div class="mc-body">
        <nav class="mc-rail" id="mcRail" aria-label="Categories"></nav>
        <div class="mc-main">
            <div class="mc-products" id="mcProducts"></div>
            <div class="mc-empty" id="mcEmpty" hidden></div>
        </div>
    </div>
</div>

<button class="cart-fab cart-open-btn" type="button" aria-label="Open cart"><span class="cart-fab-icon"><i class="fa-solid fa-cart-shopping"></i></span><span class="cart-fab-label">Cart</span><span class="cart-count">0</span></button>
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-head"><h2><i class="fa-solid fa-cart-shopping"></i> Your Cart</h2><button type="button" class="cart-close" id="cartClose">×</button></div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Your cart is waiting for something tasty. <i class="fa-solid fa-pizza-slice"></i></div>
  <div class="cart-summary"><div class="summary-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0</strong></div><div class="summary-row"><span>Delivery</span><strong id="cartDelivery">Rs. 50</strong></div><div class="summary-row total"><span>Estimated total</span><strong id="cartTotal">Rs. 50</strong></div><a href="checkout" class="btn btn-primary btn-block" id="checkoutBtn">Checkout <i class="fa-solid fa-arrow-right"></i></a><button class="btn btn-outline btn-block" id="clearCart" type="button">Clear Cart</button></div>
</aside>

<script src="js/script.js?v=41"></script>
<script src="js/scroll-memory.js?v=6"></script>
<script src="js/notify.js?v=8"></script>
<script>
window.LY_CATS = <?= json_encode($catTreesJson, $jsonFlags) ?>;
window.LY_GROUPS = <?= json_encode($catGroupsJson, $jsonFlags) ?>;
</script>
<script src="js/categories-mobile.js?v=23"></script>
</body>
</html>
