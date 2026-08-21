<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
$user = $_SESSION['user'] ?? null;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';
lyaideu_ensure_categories_table();

$rawType = (string)($_GET['type'] ?? 'dish');
if ($rawType === 'others') {
    $rawType = 'other';
}
if ($rawType === 'beverages') {
    $rawType = 'beverage';
}
$type = in_array($rawType, ['mart', 'other', 'beverage'], true) ? $rawType : 'dish';
$id = (int)($_GET['id'] ?? 0);
$slug = trim((string)($_GET['slug'] ?? ''));
if ($id <= 0 && $slug !== '') {
    if (preg_match('/^[0-9]+$/', $slug)) {
        $id = (int)$slug;
    } else {
        try {
            $table = $type === 'mart' ? 'mart_items' : ($type === 'other' ? 'other_items' : ($type === 'beverage' ? 'beverage_items' : 'dishes'));
            $st = $pdo->prepare("SELECT id FROM `$table` WHERE name_slug = :s LIMIT 1");
            $st->execute([':s' => $slug]);
            $id = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $id = 0;
        }
    }
}
$back = $type === 'mart' ? 'mart' : ($type === 'other' ? 'others' : ($type === 'beverage' ? 'beverages' : 'menu'));
$backLink = lyaideu_from_home() ? 'index' : $back;

$item = null;
$related = [];
try {
    if ($type === 'mart') {
        lyaideu_ensure_mart_table();
        $st = $pdo->prepare('SELECT m.id, m.name, m.cat, m.unit, m.price, m.tag, m.`desc`, m.img, m.category_id, m.name_slug AS slug, m.has_variants,
                                    COALESCE(h.name, \'\') AS hotel
                             FROM mart_items m
                             LEFT JOIN vendors v ON v.id = m.vendor_id
                             LEFT JOIN hotels h ON h.id = v.hotel_id
                             WHERE m.id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT m.id, m.name, m.cat, m.unit, m.price, m.tag, m.`desc`, m.img, m.category_id, m.name_slug AS slug, m.has_variants,
                                      COALESCE(h.name, \'\') AS hotel
                               FROM mart_items m
                               LEFT JOIN vendors v ON v.id = m.vendor_id
                               LEFT JOIN hotels h ON h.id = v.hotel_id
                               WHERE m.cat = :cat AND m.id <> :id ORDER BY m.id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    } elseif ($type === 'other') {
        lyaideu_ensure_other_table();
        $st = $pdo->prepare('SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug AS slug, oi.has_variants,
                                    COALESCE(h.name, \'\') AS hotel
                             FROM other_items oi
                             LEFT JOIN vendors v ON v.id = oi.vendor_id
                             LEFT JOIN hotels h ON h.id = v.hotel_id
                             WHERE oi.id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug AS slug, oi.has_variants,
                                      COALESCE(h.name, \'\') AS hotel
                               FROM other_items oi
                               LEFT JOIN vendors v ON v.id = oi.vendor_id
                               LEFT JOIN hotels h ON h.id = v.hotel_id
                               WHERE oi.cat = :cat AND oi.id <> :id ORDER BY oi.id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    } elseif ($type === 'beverage') {
        lyaideu_ensure_beverage_table();
        $st = $pdo->prepare('SELECT bi.id, bi.name, bi.cat, bi.unit, bi.price, bi.tag, bi.`desc`, bi.img, bi.category_id, bi.name_slug AS slug, bi.has_variants,
                                    COALESCE(h.name, \'\') AS hotel
                             FROM beverage_items bi
                             LEFT JOIN vendors v ON v.id = bi.vendor_id
                             LEFT JOIN hotels h ON h.id = v.hotel_id
                             WHERE bi.id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT bi.id, bi.name, bi.cat, bi.unit, bi.price, bi.tag, bi.`desc`, bi.img, bi.category_id, bi.name_slug AS slug, bi.has_variants,
                                      COALESCE(h.name, \'\') AS hotel
                               FROM beverage_items bi
                               LEFT JOIN vendors v ON v.id = bi.vendor_id
                               LEFT JOIN hotels h ON h.id = v.hotel_id
                               WHERE bi.cat = :cat AND bi.id <> :id ORDER BY bi.id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    } else {
        $st = $pdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM dishes WHERE id = :id');
        $st->execute([':id' => $id]);
        $item = $st->fetch();
        if ($item) {
            $r = $pdo->prepare('SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug AS slug, has_variants FROM dishes WHERE cat = :cat AND id <> :id ORDER BY id LIMIT 6');
            $r->execute([':cat' => $item['cat'], ':id' => $id]);
            $related = $r->fetchAll();
        }
    }
} catch (Throwable $e) {
    $item = null;
}
if ($item) {
    lyaideu_attach_variants($related, $type);
}

if (!$item) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Product not found.'];
    header('Location: ' . lyaideu_base_url() . $back);
    exit;
}

$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$MART_CAT_ICONS = ['vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow', 'staples' => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie'];
$OTHER_CAT_ICONS = ['flowers' => 'fa-bouquet', 'candles' => 'fa-candle-holder', 'achar' => 'fa-jar', 'gifts' => 'fa-gift'];
$BEVERAGE_CAT_ICONS = ['cold-drinks' => 'fa-glass-water', 'alcohol' => 'fa-champagne-glasses', 'water' => 'fa-faucet-drip'];
$catPath = lyaideu_category_path((int)($item['category_id'] ?? 0));
if (!$catPath) {
    $urlCat = trim((string)($_GET['catpath'] ?? ''), '/');
    if ($urlCat !== '') {
        $urlCatParts = explode('/', $urlCat);
        $cid = lyaideu_category_id_by_slug((string)end($urlCatParts), $type);
        if ($cid) {
            $catPath = lyaideu_category_path($cid);
        }
    }
}
$leafCat = $catPath ? end($catPath) : null;
$catName = $leafCat ? $leafCat['name'] : ucfirst((string)$item['cat']);
$ICON = $leafCat && $leafCat['icon'] !== '' ? $leafCat['icon'] : ($type === 'mart' ? ($MART_CAT_ICONS[$item['cat']] ?? 'fa-basket-shopping') : ($type === 'other' ? ($OTHER_CAT_ICONS[$item['cat']] ?? 'fa-gift') : ($type === 'beverage' ? ($BEVERAGE_CAT_ICONS[$item['cat']] ?? 'fa-glass-water') : 'fa-utensils')));
$REL_ICONS = [
    'vegetables' => 'fa-carrot', 'fruits' => 'fa-apple-whole', 'dairy' => 'fa-cow',
    'staples' => 'fa-bowl-rice', 'oils' => 'fa-mortar-pestle', 'snacks' => 'fa-cookie',
    'flowers' => 'fa-bouquet', 'candles' => 'fa-candle-holder', 'achar' => 'fa-jar', 'gifts' => 'fa-gift',
    'cold-drinks' => 'fa-glass-water', 'alcohol' => 'fa-champagne-glasses', 'water' => 'fa-faucet-drip',
    'momo' => 'fa-drumstick-bite', 'pizza' => 'fa-pizza-slice', 'chowmein' => 'fa-bowl-rice',
    'snacks2' => 'fa-cookie', 'beverages' => 'fa-mug-saucer', 'dinner' => 'fa-bowl-food',
];
$relIcon = function ($cat) use ($REL_ICONS) {
    if (isset($REL_ICONS[$cat])) return '<i class="fa-solid ' . $REL_ICONS[$cat] . '"></i>';
    return '<i class="fa-solid fa-basket-shopping"></i>';
};
$unitHtml = ($type !== 'dish' && $item['unit'] !== '') ? ' <span class="unit">/ ' . e($item['unit']) . '</span>' : '';
$tagHtml = $item['tag'] !== '' ? '<span class="dish-tag">' . e($item['tag']) . '</span>' : '';

$hasVariants = !empty($item['has_variants']);
$variants = [];
$defaultVariant = null;
if ($hasVariants) {
    $variants = lyaideu_item_variants($type, (int)$item['id']);
    foreach ($variants as $v) {
        if (!empty($v['is_default'])) {
            $defaultVariant = $v;
            break;
        }
    }
    if ($defaultVariant === null && $variants) {
        $defaultVariant = $variants[0];
    }
    if (!$variants) {
        $hasVariants = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title><?= e($item['name']) ?> | <?= $type === 'mart' ? 'LyaiDeu Mart' : ($type === 'other' ? 'LyaiDeu Others' : ($type === 'beverage' ? 'LyaiDeu Beverages' : 'Menu')) ?></title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=35">
</head>
<body class="product-pg" data-needs-catalog>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <a class="back-link top-back-link" href="<?= $backLink ?>" aria-label="Go back"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a <?= $type === 'dish' ? 'active' : '' ?>">Menu</a></li>
            <li><a href="mart" class="nav-a <?= $type === 'mart' ? 'active' : '' ?>">Mart</a></li>
            <li><a href="beverages" class="nav-a <?= $type === 'beverage' ? 'active' : '' ?>">Beverages</a></li>
            <li><a href="others" class="nav-a <?= $type === 'other' ? 'active' : '' ?>">Others</a></li>
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

<main class="product-wrap section">
    <div class="container">
        <a class="back-link" href="<?= $backLink ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>

        <div class="product-breadcrumb">
            <a href="<?= $back ?>"><?= $type === 'mart' ? 'Mart' : ($type === 'other' ? 'Others' : ($type === 'beverage' ? 'Beverages' : 'Menu')) ?></a>
            <?php foreach ($catPath as $c): ?>
                <span class="crumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
                <a href="<?= $back ?>?<?= $type === 'mart' ? 'mcat' : ($type === 'other' ? 'ocat' : ($type === 'beverage' ? 'bcat' : 'cat')) ?>=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
            <span class="crumb-sep"><i class="fa-solid fa-chevron-right"></i></span>
            <span class="crumb-current"><?= e($item['name']) ?></span>
        </div>

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
                <span class="product-cat"><i class="fa-solid <?= $ICON ?>"></i> <?= e($catName) ?></span>
                <h1 class="display"><?= e($item['name']) ?></h1>
                <?php if ($type === 'dish' && $item['hotel'] !== ''): ?>
                    <p class="product-hotel"><i class="fa-solid fa-hotel"></i> <?= e($item['hotel']) ?></p>
                <?php elseif ($type !== 'dish' && !empty($item['hotel'])): ?>
                    <p class="product-hotel"><i class="fa-solid <?= $type === 'mart' ? 'fa-store' : ($type === 'other' ? 'fa-gift' : 'fa-champagne-glasses') ?>"></i> <?= e($item['hotel']) ?></p>
                <?php endif; ?>
                <?php if ($item['desc'] !== ''): ?>
                    <p class="product-desc"><?= e($item['desc']) ?></p>
                <?php endif; ?>

                <div class="product-price-row">
                    <span class="product-price"><small>Rs.</small> <span id="productPrice"><?= $hasVariants && $defaultVariant ? (int)$defaultVariant['price'] : (int)$item['price'] ?></span><?= $unitHtml ?></span>
                </div>

                <?php if ($hasVariants && $variants): ?>
                <div class="variant-picker">
                    <label class="variant-label"><i class="fa-solid fa-layer-group"></i> Select option</label>
                    <div class="variant-options" id="variantOptions">
                        <?php foreach ($variants as $vi => $v): $selected = $defaultVariant && $v['id'] == $defaultVariant['id']; ?>
                        <label class="variant-option<?= $selected ? ' selected' : '' ?><?= $selected ? '' : '' ?>">
                            <input type="radio" name="product_variant" value="<?= (int)$v['id'] ?>" data-label="<?= e($v['label']) ?>" data-price="<?= (int)$v['price'] ?>"<?= $selected ? ' checked' : '' ?>>
                            <span class="variant-option-body">
                                <span class="variant-option-label"><?= e($v['label']) ?></span>
                                <span class="variant-option-price"><small>Rs.</small> <?= (int)$v['price'] ?></span>
                                <?php if ($v['info'] !== ''): ?><span class="variant-option-info"><?= e($v['info']) ?></span><?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="product-actions">
                    <button class="btn btn-primary add-cart" data-id="<?= (int)$item['id'] ?>" data-type="<?= $type ?>" data-hotel="<?= e($item['hotel'] ?? '') ?>"<?= $hasVariants && $defaultVariant ? ' data-variant="' . e($defaultVariant['label']) . '" data-price="' . (int)$defaultVariant['price'] . '" data-name="' . e($item['name']) . '"' : '' ?> type="button"><i class="fa-solid fa-cart-shopping"></i> Add to Cart</button>
                    <button class="btn btn-outline cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> View Cart <span class="cart-count">0</span></button>
                </div>

                <?php if ($type === 'mart'): ?>
                    <p class="small-note" style="margin-top:.9rem;"><i class="fa-solid fa-box"></i> Fresh groceries delivered with your khaja order.</p>
                <?php elseif ($type === 'other'): ?>
                    <p class="small-note" style="margin-top:.9rem;"><i class="fa-solid fa-box"></i> Handmade &amp; festive finds delivered with your khaja order.</p>
                <?php elseif ($type === 'beverage'): ?>
                    <p class="small-note" style="margin-top:.9rem;"><i class="fa-solid fa-glass-water"></i> Chilled &amp; sealed beverages delivered with your order. Please drink responsibly.</p>
                <?php endif; ?>
            </div>
        </div>

        <section class="related-section">
            <h3><i class="fa-solid fa-thumbs-up"></i> Related <?= e($catName) ?></h3>
            <?php if ($related): ?>
                <div class="related-grid">
                    <?php foreach ($related as $rItem): ?>
                        <?php
                            $relPath = implode('/', lyaideu_item_cats((int)($rItem['category_id'] ?? 0), (string)$rItem['cat']));
                            $rDef = null;
                            if (!empty($rItem['has_variants']) && !empty($rItem['variants'])) {
                                foreach ($rItem['variants'] as $rv) {
                                    if (!empty($rv['is_default'])) { $rDef = $rv; break; }
                                }
                                if ($rDef === null) { $rDef = $rItem['variants'][0]; }
                            }
                            $rPrice = $rDef ? (int)$rDef['price'] : (int)$rItem['price'];
                            $rUnit = $rDef && (string)$rDef['label'] !== '' ? (string)$rDef['label'] : (string)($rItem['unit'] ?? '');
                        ?>
                        <div class="related-card">
                            <a class="related-link" href="<?= $type === 'mart' ? 'mart' : ($type === 'other' ? 'others' : ($type === 'beverage' ? 'beverages' : 'menu')) ?>/<?= $relPath !== '' ? e($relPath) . '/' : '' ?><?= e($rItem['slug'] !== '' ? $rItem['slug'] : lyaideu_slugify((string)$rItem['name'])) ?>">
                                <div class="related-img">
                                    <?php if ($rItem['img'] !== ''): ?>
                                        <img src="<?= e($rItem['img']) ?>" alt="<?= e($rItem['name']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <?= $relIcon($rItem['cat']) ?>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="related-info">
                                <h4><?= e($rItem['name']) ?></h4>
                                <div class="related-foot">
                                    <span class="price"><small>Rs.</small> <?= $rPrice ?><?= ($type !== 'dish' && $rUnit !== '') ? ' <span class="unit">/ ' . e($rUnit) . '</span>' : '' ?></span>
                                    <button class="btn-order add-cart" data-id="<?= (int)$rItem['id'] ?>" data-type="<?= $type ?>" data-name="<?= e($rItem['name']) ?>" data-price="<?= $rPrice ?>"<?= ($type !== 'dish' && $rUnit !== '') ? ' data-unit="' . e($rUnit) . '"' : '' ?> data-hotel="<?= e($rItem['hotel'] ?? '') ?>" data-img="<?= e($rItem['img']) ?>" type="button"><i class="fa-solid fa-cart-shopping"></i> Add</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="small-note">No other items in this category yet — check back soon!</p>
            <?php endif; ?>
        </section>
    </div>
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

<script src="js/script.js?v=26"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
<script>
(function(){
  /* Back link: use the browser's own history so the previous page is
     restored from its cache instantly — listings like index.php keep
     their exact shuffled order and scroll position that way. The static
     href rendered by PHP is only followed when this page was opened
     directly (shared link / new tab), where history.back() would leave
     the site. */
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
(function(){
  var opts = document.querySelectorAll('#variantOptions input[type="radio"]');
  var priceEl = document.getElementById('productPrice');
  var addBtn = document.querySelector('.product-actions .add-cart');
  if (!opts.length || !addBtn) return;
  function apply(){
    var sel = document.querySelector('#variantOptions input[type="radio"]:checked');
    if (!sel) return;
    document.querySelectorAll('#variantOptions .variant-option').forEach(function(o){
      var r = o.querySelector('input[type="radio"]');
      o.classList.toggle('selected', !!r && r.checked);
    });
    var label = sel.getAttribute('data-label') || '';
    var price = Number(sel.getAttribute('data-price')) || 0;
    if (priceEl) priceEl.textContent = price;
    addBtn.setAttribute('data-variant', label);
    addBtn.setAttribute('data-price', price);
  }
  opts.forEach(function(r){ r.addEventListener('change', apply); });
  apply();
})();
</script>
</body>
</html>