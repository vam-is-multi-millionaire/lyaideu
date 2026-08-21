<?php
require_once __DIR__ . '/delivery_inc.php';

$pdo = lyaideu_load_pdo();
$role = 'vendor';
delivery_require_login($role);
$user = delivery_user();
delivery_logout();
$vendorId = (int)$user['id'];

$vendor = null;
try {
    $st = $pdo->prepare('SELECT id, name, scope, hotel_id FROM vendors WHERE id = ?');
    $st->execute([$vendorId]);
    $vendor = $st->fetch();
} catch (Throwable $e) {
    $vendor = null;
}
if (!$vendor) {
    $_SESSION['delivery_login_error'] = 'Your vendor account could not be found.';
    header('Location: vendor');
    exit;
}

$scope = (string)($vendor['scope'] ?? 'hotel');
$isMart = $scope === 'mart';
$isOther = $scope === 'other';
$isBeverage = $scope === 'beverage';
$hotelName = '';
if (!$isMart && !$isOther && !$isBeverage) {
    try {
        $st = $pdo->prepare('SELECT name FROM hotels WHERE id = ?');
        $st->execute([(int)$vendor['hotel_id']]);
        $hotelName = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        $hotelName = '';
    }
}

lyaideu_ensure_categories_table();
lyaideu_ensure_variant_tables();
$catType = $isMart ? 'mart' : ($isOther ? 'other' : ($isBeverage ? 'beverage' : 'menu'));
$catsFlat = lyaideu_categories_flat($catType);
$allowedCats = [];
foreach ($catsFlat as $c) {
    $allowedCats[(int)$c['id']] = $c;
}

$table = $isMart ? 'mart_items' : ($isOther ? 'other_items' : ($isBeverage ? 'beverage_items' : 'dishes'));
$itemType = $isMart ? 'mart' : ($isOther ? 'other' : ($isBeverage ? 'beverage' : 'dish'));
$msg = $_GET['msg'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (isset($_POST['product_save'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $price = max(0, (int)($_POST['price'] ?? 0));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $tag = trim(strip_tags((string)($_POST['tag'] ?? '')));
        $desc = trim(strip_tags((string)($_POST['desc'] ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $unit = trim(strip_tags((string)($_POST['unit'] ?? '')));
        $hasVariants = $id > 0
            ? !empty($_POST['product'][$id]['has_variants'] ?? [])
            : !empty($_POST['new_product']['has_variants'] ?? []);

        if ($categoryId > 0 && !isset($allowedCats[$categoryId])) {
            $categoryId = 0;
        }

        if ($hasVariants && $price <= 0) {
            $variantOptions = $id > 0
                ? ($_POST['product'][$id]['variants'] ?? [])
                : ($_POST['new_product']['variants'] ?? []);
            foreach ($variantOptions as $opt) {
                if (!empty($opt['default'])) {
                    $optPrice = max(0, (int)($opt['price'] ?? 0));
                    if ($optPrice > 0) {
                        $price = $optPrice;
                        break;
                    }
                }
            }
            if ($price <= 0) {
                foreach ($variantOptions as $opt) {
                    $optPrice = max(0, (int)($opt['price'] ?? 0));
                    if ($optPrice > 0) {
                        $price = $optPrice;
                        break;
                    }
                }
            }
        }

        $error = null;
        if ($name === '') {
            $error = 'Product name is required.';
        } elseif ($price <= 0) {
            $error = 'Price must be greater than 0.';
        } elseif ($scope === 'hotel' && $hotelName === '') {
            $error = 'Your account is not linked to a hotel, so you cannot add menu products. Ask the admin to link a hotel.';
        }

        if (!$error && $id > 0) {
            try {
                $st = $pdo->prepare("SELECT id FROM `$table` WHERE id = ? AND vendor_id = ?");
                $st->execute([$id, $vendorId]);
                if (!$st->fetchColumn()) {
                    $error = 'You can only edit your own products.';
                }
            } catch (Throwable $e) {
                $error = 'Could not verify the product.';
            }
        }

        if (!$error) {
            try {
                $file = null;
                if (isset($_FILES['img_file']) && ($_FILES['img_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['img_file'];
                }
                $existing = '';
                if ($id > 0) {
                    $st = $pdo->prepare("SELECT img FROM `$table` WHERE id = ?");
                    $st->execute([$id]);
                    $existing = (string)$st->fetchColumn();
                }
                $img = lyaideu_handle_item_image($existing, $_POST, $file, $isMart ? 'mart_img' : ($isOther ? 'other_img' : ($isBeverage ? 'beverage_img' : 'dish_img')));

                if ($id > 0) {
                    if ($isMart) {
                        $upd = $pdo->prepare('UPDATE mart_items SET name = ?, category_id = ?, unit = ?, price = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $id, $vendorId]);
                    } elseif ($isOther) {
                        $upd = $pdo->prepare('UPDATE other_items SET name = ?, category_id = ?, unit = ?, price = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $id, $vendorId]);
                    } elseif ($isBeverage) {
                        $upd = $pdo->prepare('UPDATE beverage_items SET name = ?, category_id = ?, unit = ?, price = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $id, $vendorId]);
                    } else {
                        $upd = $pdo->prepare('UPDATE dishes SET name = ?, hotel = ?, category_id = ?, price = ?, phone = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $hotelName, $categoryId ?: null, $price, $phone, $tag, $desc, $img, $id, $vendorId]);
                    }
                    lyaideu_sync_item_slug($table, $id, $name);
                    lyaideu_save_item_variants($pdo, $itemType, $id, $hasVariants, $_POST['product'][$id]['variants'] ?? []);
                } else {
                    if ($isMart) {
                        $ins = $pdo->prepare('INSERT INTO mart_items (name, category_id, unit, price, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $vendorId]);
                    } elseif ($isOther) {
                        $ins = $pdo->prepare('INSERT INTO other_items (name, category_id, unit, price, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $vendorId]);
                    } elseif ($isBeverage) {
                        $ins = $pdo->prepare('INSERT INTO beverage_items (name, category_id, unit, price, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $vendorId]);
                    } else {
                        $ins = $pdo->prepare('INSERT INTO dishes (name, hotel, category_id, price, phone, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $hotelName, $categoryId ?: null, $price, $phone, $tag, $desc, $img, $vendorId]);
                    }
                    $newItemId = (int)$pdo->lastInsertId();
                    lyaideu_sync_item_slug($table, $newItemId, $name);
                    lyaideu_save_item_variants($pdo, $itemType, $newItemId, $hasVariants, $_POST['new_product']['variants'] ?? []);
                }
                header('Location: vendor_products?msg=' . urlencode('Product saved. It is now live on the website.'));
                exit;
            } catch (Throwable $e) {
                $msg = ($e instanceof RuntimeException) ? $e->getMessage() : 'Could not save the product.';
            }
        } else {
            $msg = $error;
        }
    }

    if (isset($_POST['product_delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $del = $pdo->prepare("DELETE FROM `$table` WHERE id = ? AND vendor_id = ?");
            $del->execute([$id, $vendorId]);
            lyaideu_delete_item_variants($pdo, $itemType, $id);
            header('Location: vendor_products?msg=' . urlencode('Product deleted.'));
            exit;
        } catch (Throwable $e) {
            $msg = 'Could not delete the product.';
        }
    }
}

$products = [];
try {
    $st = $pdo->prepare(
$isMart
? 'SELECT id, name, category_id, unit, price, tag, `desc`, img, has_variants FROM mart_items WHERE vendor_id = ? ORDER BY id DESC'
: ($isOther
? 'SELECT id, name, category_id, unit, price, tag, `desc`, img, has_variants FROM other_items WHERE vendor_id = ? ORDER BY id DESC'
: ($isBeverage
? 'SELECT id, name, category_id, unit, price, tag, `desc`, img, has_variants FROM beverage_items WHERE vendor_id = ? ORDER BY id DESC'
: 'SELECT id, name, hotel, category_id, price, phone, tag, `desc`, img, has_variants FROM dishes WHERE vendor_id = ? ORDER BY id DESC'))
    );
    $st->execute([$vendorId]);
    $products = $st->fetchAll();
} catch (Throwable $e) {
    $products = [];
}

function vp_esc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

delivery_header(
    $isMart ? 'My Mart Products' : ($isOther ? 'My Other Products' : ($isBeverage ? 'My Beverage Products' : 'My Products')),
    $isMart ? 'Manage My Mart Items' : ($isOther ? 'Manage My Other Items' : ($isBeverage ? 'Manage My Beverage Items' : 'Manage My Menu')),
    $isMart ? 'fa-basket-shopping' : ($isOther ? 'fa-gift' : ($isBeverage ? 'fa-glass-water' : 'fa-store')),
    $role
);
?>
<a class="btn btn-outline" href="vendor" style="margin-bottom:1.2rem;"><i class="fa-solid fa-arrow-left"></i> Back to Order Queue</a>

<?php if ($msg): ?>
<div class="flash-banner flash-success delivery-flash"><i class="fa-solid fa-circle-check"></i> <?= vp_esc($msg) ?></div>
<?php endif; ?>

<div class="delivery-section products-manage">
    <div class="products-toolbar">
        <p class="small-note">
            <?php if ($isMart): ?>
            <i class="fa-solid fa-basket-shopping"></i> These items appear on the <strong>Mart</strong> page as soon as you save them.
            <?php elseif ($isOther): ?>
            <i class="fa-solid fa-gift"></i> These items appear on the <strong>Others</strong> page as soon as you save them.
            <?php elseif ($isBeverage): ?>
            <i class="fa-solid fa-glass-water"></i> These items appear on the <strong>Beverages</strong> page as soon as you save them.
            <?php else: ?>
            <i class="fa-solid fa-hotel"></i> Your items appear under <strong><?= vp_esc($hotelName) ?></strong> on the <strong>Menu</strong> page as soon as you save them.
            <?php endif; ?>
        </p>
        <span class="delivery-count"><b><?= count($products) ?></b> product<?= count($products) === 1 ? '' : 's' ?> live</span>
        <button type="button" class="btn btn-primary btn-sm" data-open-add><i class="fa-solid fa-plus"></i> Add Product</button>
    </div>

    <div class="products-search">
        <div class="admin-order-search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" placeholder="Search by name, price, category, tag or description…" aria-label="Search products" data-product-search></div>
        <span class="delivery-count" data-result-count aria-live="polite"></span>
    </div>

    <details class="product-add-card"<?= $products ? '' : ' open' ?> data-add-panel>
        <summary><span class="add-plus"><i class="fa-solid fa-plus"></i></span> Add a new product <i class="fa-solid fa-chevron-down chev"></i></summary>
        <div class="product-add-inner">
            <form action="vendor_products" method="POST" enctype="multipart/form-data" class="delivery-form">
                <input type="hidden" name="csrf_token" value="<?= vp_esc(delivery_csrf_token()) ?>">
                <input type="hidden" name="id" value="0">

                <div class="store-field">
                    <label for="a-name">Product name</label>
                    <div class="store-input">
                        <i class="fa-solid fa-tag"></i>
                        <input type="text" id="a-name" name="name" placeholder="<?= $isMart ? 'e.g. Fresh Apples' : ($isOther ? 'e.g. Rose Bouquet' : ($isBeverage ? 'e.g. Coca-Cola 500ml' : 'e.g. Chicken Momo')) ?>" required>
                    </div>
                </div>

                <div class="store-field-row">
                    <div class="store-field">
                        <label for="a-price">Price (Rs.)</label>
                        <div class="store-input">
                            <i class="fa-solid fa-money-bill-wave"></i>
                            <input type="number" min="1" step="1" id="a-price" name="price" placeholder="250" required>
                        </div>
                    </div>
                    <div class="store-field">
                        <label for="a-cat">Category</label>
                        <select id="a-cat" name="category_id">
                            <option value="0">— No category —</option>
                            <?php foreach ($catsFlat as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= str_repeat('— ', $c['depth']) ?><?= vp_esc($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="store-field-row">
                    <?php if ($isMart || $isOther || $isBeverage): ?>
                    <div class="store-field">
                        <label for="a-unit">Unit</label>
                        <div class="store-input">
                            <i class="fa-solid fa-weight-hanging"></i>
                            <input type="text" id="a-unit" name="unit" placeholder="<?= $isOther ? 'piece / set / bunch' : ($isBeverage ? '500ml / bottle / can' : 'kg / litre / pack') ?>">
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="store-field">
                        <label for="a-phone">Phone</label>
                        <div class="store-input">
                            <i class="fa-solid fa-phone"></i>
                            <input type="text" id="a-phone" name="phone" placeholder="98XXXXXXXX">
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="store-field">
                        <label for="a-tag">Tag</label>
                        <div class="store-input">
                            <i class="fa-solid fa-star"></i>
                            <input type="text" id="a-tag" name="tag" placeholder="New! / Best Seller">
                        </div>
                    </div>
                </div>

                <div class="store-field">
                    <label for="a-img">Image <span class="muted">(optional)</span></label>
                    <div class="product-img-upload">
                        <div class="img-preview"><i class="fa-solid fa-image"></i></div>
                        <div>
                            <input type="file" id="a-img" name="img_file" class="settings-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                        </div>
                    </div>
                    <p class="field-hint">A square image looks best. PNG, JPG, WebP, GIF or SVG.</p>
                </div>

                <div class="store-field">
                    <label for="a-desc">Description</label>
                    <textarea id="a-desc" name="desc" rows="2" placeholder="Short description..."></textarea>
                </div>

                <?= lyaideu_variants_editor_html('new_product') ?>

                <div class="store-form-actions">
                    <button type="submit" name="product_save" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Publish Product</button>
                </div>
            </form>
        </div>
    </details>

    <div class="product-list">
        <?php if (!$products): ?>
        <div class="admin-card">
            <h3>No products yet.</h3>
            <p class="small-note">Use the "Add Product" form below to publish your first item.</p>
        </div>
        <?php endif; ?>

        <?php foreach ($products as $p): ?>
        <?php
            $pid = (int)$p['id'];
            $catName = '';
            if ((int)$p['category_id'] > 0 && isset($allowedCats[(int)$p['category_id']])) {
                $catName = $allowedCats[(int)$p['category_id']]['name'];
            }
            $searchText = strtolower((string)$p['name'] . ' ' . (int)$p['price'] . ' ' . (string)($p['unit'] ?? '') . ' ' . $catName . ' ' . (string)($p['tag'] ?? '') . ' ' . (string)($p['desc'] ?? ''));
            $itemVariants = lyaideu_item_variants($itemType, $pid);
        ?>
        <article class="product-card" data-search="<?= vp_esc($searchText) ?>">
            <div class="product-card-main">
                <div class="product-thumb">
                    <?php if (!empty($p['img'])): ?>
                    <img src="<?= vp_esc($p['img']) ?>" alt="<?= vp_esc($p['name']) ?>">
                    <?php else: ?>
                    <i class="fa-solid <?= $isMart ? 'fa-box' : ($isOther ? 'fa-gift' : ($isBeverage ? 'fa-glass-water' : 'fa-utensils')) ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="product-card-info">
                    <h3><?= vp_esc($p['name']) ?></h3>
                    <div class="product-meta">
                        <span class="product-price">Rs. <?= (int)$p['price'] ?></span>
                        <?php if (!empty($p['unit'])): ?><span><?= vp_esc($p['unit']) ?></span><?php endif; ?>
                        <?php if ($catName !== ''): ?><span class="product-cat"><i class="fa-solid fa-layer-group"></i> <?= vp_esc($catName) ?></span><?php endif; ?>
                        <?php if (!empty($p['tag'])): ?><span class="product-tag"><?= vp_esc($p['tag']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($p['desc'])): ?>
                    <p class="product-desc"><?= vp_esc($p['desc']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="product-card-actions">
                    <form action="vendor_products" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= vp_esc(delivery_csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <button type="submit" name="product_delete" value="1" class="btn btn-outline btn-del" data-confirm="Delete <?= vp_esc($p['name']) ?>? This cannot be undone."><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </div>
            </div>

            <details class="product-edit">
                <summary><i class="fa-solid fa-pen-to-square"></i> Edit product details <i class="fa-solid fa-chevron-down chev"></i></summary>
                <div class="product-edit-inner">
                    <form action="vendor_products" method="POST" enctype="multipart/form-data" class="delivery-form">
                        <input type="hidden" name="csrf_token" value="<?= vp_esc(delivery_csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $pid ?>">

                        <div class="store-field">
                            <label for="p-name-<?= $pid ?>">Product name</label>
                            <div class="store-input">
                                <i class="fa-solid fa-tag"></i>
                                <input type="text" id="p-name-<?= $pid ?>" name="name" value="<?= vp_esc($p['name']) ?>" required>
                            </div>
                        </div>

                        <div class="store-field-row">
                            <div class="store-field">
                                <label for="p-price-<?= $pid ?>">Price (Rs.)</label>
                                <div class="store-input">
                                    <i class="fa-solid fa-money-bill-wave"></i>
                                    <input type="number" min="1" step="1" id="p-price-<?= $pid ?>" name="price" value="<?= (int)$p['price'] ?>" required>
                                </div>
                            </div>
                            <div class="store-field">
                                <label for="p-cat-<?= $pid ?>">Category</label>
                                <select id="p-cat-<?= $pid ?>" name="category_id">
                                    <option value="0">— No category —</option>
                                    <?php foreach ($catsFlat as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)$p['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $c['depth']) ?><?= vp_esc($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="store-field-row">
                            <?php if ($isMart || $isOther || $isBeverage): ?>
                            <div class="store-field">
                                <label for="p-unit-<?= $pid ?>">Unit</label>
                                <div class="store-input">
                                    <i class="fa-solid fa-weight-hanging"></i>
                                    <input type="text" id="p-unit-<?= $pid ?>" name="unit" value="<?= vp_esc($p['unit']) ?>" placeholder="<?= $isOther ? 'piece / set / bunch' : ($isBeverage ? '500ml / bottle / can' : 'kg / litre / pack') ?>">
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="store-field">
                                <label for="p-phone-<?= $pid ?>">Phone</label>
                                <div class="store-input">
                                    <i class="fa-solid fa-phone"></i>
                                    <input type="text" id="p-phone-<?= $pid ?>" name="phone" value="<?= vp_esc($p['phone']) ?>" placeholder="98XXXXXXXX">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="store-field">
                                <label for="p-tag-<?= $pid ?>">Tag</label>
                                <div class="store-input">
                                    <i class="fa-solid fa-star"></i>
                                    <input type="text" id="p-tag-<?= $pid ?>" name="tag" value="<?= vp_esc($p['tag']) ?>" placeholder="<?= $isMart || $isOther || $isBeverage ? 'New!' : 'Best Seller' ?>">
                                </div>
                            </div>
                        </div>

                        <div class="store-field">
                            <label for="p-img-<?= $pid ?>">Image <span class="muted">(optional)</span></label>
                            <div class="product-img-upload">
                                <div class="img-preview">
                                    <?php if (!empty($p['img'])): ?>
                                    <img src="<?= vp_esc($p['img']) ?>" alt="Current image">
                                    <?php else: ?>
                                    <i class="fa-solid fa-image"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="file" id="p-img-<?= $pid ?>" name="img_file" class="settings-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                                    <?php if (!empty($p['img'])): ?>
                                    <label class="delete-check"><input type="checkbox" name="remove_img" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="field-hint">A square image looks best. PNG, JPG, WebP, GIF or SVG.</p>
                        </div>

                        <div class="store-field">
                            <label for="p-desc-<?= $pid ?>">Description</label>
                            <textarea id="p-desc-<?= $pid ?>" name="desc" rows="2"><?= vp_esc($p['desc']) ?></textarea>
                        </div>

                        <?= lyaideu_variants_editor_html('product[' . $pid . ']', $itemVariants, (bool)($p['has_variants'] ?? false)) ?>

                        <div class="store-form-actions">
                            <button type="submit" name="product_save" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Product</button>
                        </div>
                    </form>
                </div>
            </details>
        </article>
        <?php endforeach; ?>

        <div class="empty-state" id="productSearchEmpty" style="display:none"><span class="big"><i class="fa-solid fa-filter-circle-xmark"></i></span><h3>No matching products</h3><p>No products match your search. Try a different term or clear the search.</p></div>
    </div>
</div>

<script>
(function(){
  var search = document.querySelector("[data-product-search]");
  function applySearch(){
    if (!search) return;
    var q = (search.value || "").trim().toLowerCase();
    var total = 0, visible = 0;
    document.querySelectorAll(".product-card").forEach(function(card){
      total++;
      var show = !q || (card.getAttribute("data-search") || "").indexOf(q) !== -1;
      card.style.display = show ? "" : "none";
      if (show) visible++;
    });
    var none = document.getElementById("productSearchEmpty");
    if (none) none.style.display = (total > 0 && visible === 0) ? "" : "none";
    var cnt = document.querySelector(".products-search [data-result-count]");
    if (cnt) cnt.innerHTML = q
      ? "Showing <b>" + visible + "</b> of <b>" + total + "</b>"
      : "<b>" + total + "</b> product" + (total === 1 ? "" : "s");
  }
  if (search) search.addEventListener("input", applySearch);
  applySearch();

  document.addEventListener("click", function(e){
    var addBtn = e.target && e.target.closest ? e.target.closest("[data-open-add]") : null;
    if (addBtn) {
      var panel = document.querySelector("[data-add-panel]");
      if (!panel) return;
      if (!panel.open) panel.open = true;
      panel.scrollIntoView({ behavior: "smooth", block: "start" });
      var f = panel.querySelector("input[name='name']");
      if (f) setTimeout(function(){ f.focus(); }, 350);
      return;
    }
    var del = e.target && e.target.closest ? e.target.closest("[data-confirm]") : null;
    if (del && !window.confirm(del.getAttribute("data-confirm") || "Are you sure?")) e.preventDefault();
  });

  function vpSyncPrice(form){
    var toggle = form.querySelector(".pv-toggle");
    if (!toggle) return;
    var priceInput = form.querySelector("input[name='price']");
    if (!priceInput) return;
    var field = priceInput.closest(".store-field") || priceInput.parentNode;
    if (toggle.checked) {
      field.style.display = "none";
      priceInput.required = false;
      priceInput.min = 0;
      var defRow = null;
      form.querySelectorAll(".pv-row").forEach(function(row){
        var d = row.querySelector(".pv-default-input");
        if (d && d.checked && !defRow) defRow = row;
      });
      var src = (defRow && parseInt(defRow.querySelector(".pv-price").value, 10) > 0) ? defRow : null;
      if (!src) {
        form.querySelectorAll(".pv-row").forEach(function(row){
          if (!src && parseInt(row.querySelector(".pv-price").value, 10) > 0) src = row;
        });
      }
      if (!src) src = defRow || form.querySelector(".pv-row");
      var v = src ? parseInt(src.querySelector(".pv-price").value, 10) : NaN;
      if (!isNaN(v) && v > 0) priceInput.value = v;
    } else {
      field.style.display = "";
      priceInput.required = true;
      priceInput.min = 1;
    }
  }
  document.querySelectorAll("form.delivery-form").forEach(function(form){
    var toggle = form.querySelector(".pv-toggle");
    if (!toggle) return;
    toggle.addEventListener("change", function(){ vpSyncPrice(form); });
    var list = form.querySelector(".pv-list");
    if (list) list.addEventListener("input", function(e){
      if (e.target && e.target.classList && (e.target.classList.contains("pv-price") || e.target.classList.contains("pv-default-input"))) {
        var t = form.querySelector(".pv-toggle");
        if (t && t.checked) vpSyncPrice(form);
      }
    });
    if (list) list.addEventListener("change", function(e){
      if (e.target && e.target.classList && e.target.classList.contains("pv-default-input")) {
        var t = form.querySelector(".pv-toggle");
        if (t && t.checked) vpSyncPrice(form);
      }
    });
    vpSyncPrice(form);
  });
})();
</script>
<script src="js/admin-variants.js?v=5"></script>
<?php
delivery_footer();
