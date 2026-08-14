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

$isMart = ($vendor['scope'] ?? 'hotel') === 'mart';
$hotelName = '';
if (!$isMart) {
    try {
        $st = $pdo->prepare('SELECT name FROM hotels WHERE id = ?');
        $st->execute([(int)$vendor['hotel_id']]);
        $hotelName = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        $hotelName = '';
    }
}

lyaideu_ensure_categories_table();
$catType = $isMart ? 'mart' : 'menu';
$catsFlat = lyaideu_categories_flat($catType);
$allowedCats = [];
foreach ($catsFlat as $c) {
    $allowedCats[(int)$c['id']] = $c;
}

$table = $isMart ? 'mart_items' : 'dishes';
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

        if ($categoryId > 0 && !isset($allowedCats[$categoryId])) {
            $categoryId = 0;
        }

        $error = null;
        if ($name === '') {
            $error = 'Product name is required.';
        } elseif ($price <= 0) {
            $error = 'Price must be greater than 0.';
        } elseif (!$isMart && $hotelName === '') {
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
                $img = lyaideu_handle_item_image($existing, $_POST, $file, $isMart ? 'mart_img' : 'dish_img');

                if ($id > 0) {
                    if ($isMart) {
                        $upd = $pdo->prepare('UPDATE mart_items SET name = ?, category_id = ?, unit = ?, price = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $id, $vendorId]);
                    } else {
                        $upd = $pdo->prepare('UPDATE dishes SET name = ?, hotel = ?, category_id = ?, price = ?, phone = ?, tag = ?, `desc` = ?, img = ? WHERE id = ? AND vendor_id = ?');
                        $upd->execute([$name, $hotelName, $categoryId ?: null, $price, $phone, $tag, $desc, $img, $id, $vendorId]);
                    }
                    lyaideu_sync_item_slug($table, $id, $name);
                } else {
                    if ($isMart) {
                        $ins = $pdo->prepare('INSERT INTO mart_items (name, category_id, unit, price, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $categoryId ?: null, $unit, $price, $tag, $desc, $img, $vendorId]);
                    } else {
                        $ins = $pdo->prepare('INSERT INTO dishes (name, hotel, category_id, price, phone, tag, `desc`, img, vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $ins->execute([$name, $hotelName, $categoryId ?: null, $price, $phone, $tag, $desc, $img, $vendorId]);
                    }
                    lyaideu_sync_item_slug($table, (int)$pdo->lastInsertId(), $name);
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
            ? 'SELECT id, name, category_id, unit, price, tag, `desc`, img FROM mart_items WHERE vendor_id = ? ORDER BY name'
            : 'SELECT id, name, hotel, category_id, price, phone, tag, `desc`, img FROM dishes WHERE vendor_id = ? ORDER BY name'
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
    $isMart ? 'My Mart Products' : 'My Products',
    $isMart ? 'Manage My Mart Items' : 'Manage My Menu',
    $isMart ? 'fa-basket-shopping' : 'fa-store',
    $role
);
?>
<a class="btn btn-outline" href="vendor" style="margin-bottom:1rem;"><i class="fa-solid fa-arrow-left"></i> Back to Order Queue</a>

<?php if ($msg): ?>
<div class="flash-banner flash-success delivery-flash"><i class="fa-solid fa-circle-check"></i> <?= vp_esc($msg) ?></div>
<?php endif; ?>

<div class="delivery-section">
    <p class="small-note" style="margin-bottom:1rem;">
        <?php if ($isMart): ?>
        <i class="fa-solid fa-basket-shopping"></i> These items appear on the <strong>Mart</strong> page as soon as you save them.
        <?php else: ?>
        <i class="fa-solid fa-hotel"></i> Your items appear under <strong><?= vp_esc($hotelName) ?></strong> on the <strong>Menu</strong> page as soon as you save them.
        <?php endif; ?>
    </p>

    <div class="admin-grid">
        <?php if (!$products): ?>
        <div class="admin-card">
            <h3>No products yet.</h3>
            <p class="small-note">Use the "Add Product" form to publish your first item.</p>
        </div>
        <?php endif; ?>

        <?php foreach ($products as $p): ?>
        <form action="vendor_products" method="POST" enctype="multipart/form-data" class="admin-card">
            <h3><?= vp_esc($p['name']) ?></h3>
            <input type="hidden" name="csrf_token" value="<?= vp_esc(delivery_csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <label>Product Name</label>
            <input type="text" name="name" value="<?= vp_esc($p['name']) ?>" required>
            <div class="admin-field-row">
                <div><label>Price (Rs.)</label>
                    <input type="number" min="1" step="1" name="price" value="<?= (int)$p['price'] ?>" required>
                </div>
                <div><label>Category</label>
                    <select name="category_id">
                        <option value="0">— No category —</option>
                        <?php foreach ($catsFlat as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$p['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $c['depth']) ?><?= vp_esc($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($isMart): ?>
            <div class="admin-field-row">
                <div><label>Unit</label>
                    <input type="text" name="unit" value="<?= vp_esc($p['unit']) ?>" placeholder="kg / litre / pack">
                </div>
                <div><label>Tag</label>
                    <input type="text" name="tag" value="<?= vp_esc($p['tag']) ?>" placeholder="New!">
                </div>
            </div>
            <?php else: ?>
            <div class="admin-field-row">
                <div><label>Phone</label>
                    <input type="text" name="phone" value="<?= vp_esc($p['phone']) ?>" placeholder="98XXXXXXXX">
                </div>
                <div><label>Tag</label>
                    <input type="text" name="tag" value="<?= vp_esc($p['tag']) ?>" placeholder="Best Seller">
                </div>
            </div>
            <?php endif; ?>
            <label>Image <span class="muted">(optional)</span></label>
            <input type="file" name="img_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
            <?php if (!empty($p['img'])): ?>
            <div class="img-preview"><img src="<?= vp_esc($p['img']) ?>" alt="Current image"></div>
            <label class="delete-check"><input type="checkbox" name="remove_img" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
            <?php endif; ?>
            <label>Description</label>
            <textarea name="desc" rows="2"><?= vp_esc($p['desc']) ?></textarea>
            <button type="submit" name="product_save" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save Product</button>
            <label class="delete-check"><input type="checkbox" name="product_delete" value="1" onchange="if(confirm('Delete this product?'))this.form.submit()"> <i class="fa-solid fa-trash-can"></i> Delete this product</label>
        </form>
        <?php endforeach; ?>

        <form action="vendor_products" method="POST" enctype="multipart/form-data" class="admin-card admin-add-card">
            <h3><i class="fa-solid fa-plus"></i> Add New Product</h3>
            <input type="hidden" name="csrf_token" value="<?= vp_esc(delivery_csrf_token()) ?>">
            <input type="hidden" name="id" value="0">
            <label>Product Name</label><input type="text" name="name" placeholder="<?= $isMart ? 'e.g. Fresh Apples' : 'e.g. Chicken Momo' ?>" required>
            <div class="admin-field-row">
                <div><label>Price (Rs.)</label><input type="number" min="1" step="1" name="price" placeholder="250" required></div>
                <div><label>Category</label>
                    <select name="category_id">
                        <option value="0">— No category —</option>
                        <?php foreach ($catsFlat as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= str_repeat('— ', $c['depth']) ?><?= vp_esc($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($isMart): ?>
            <label>Unit</label><input type="text" name="unit" placeholder="kg / litre / pack">
            <?php else: ?>
            <label>Phone</label><input type="text" name="phone" placeholder="98XXXXXXXX">
            <?php endif; ?>
            <label>Tag</label><input type="text" name="tag" placeholder="New! / Best Seller">
            <label>Image <span class="muted">(optional)</span></label>
            <input type="file" name="img_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
            <label>Description</label><textarea name="desc" rows="2" placeholder="Short description..."></textarea>
            <button type="submit" name="product_save" class="btn btn-primary btn-block"><i class="fa-solid fa-plus"></i> Publish Product</button>
        </form>
    </div>
</div>
<?php
delivery_footer();
