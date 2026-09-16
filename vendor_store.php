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
    $st = $pdo->prepare('SELECT id, name, email, phone, scope, hotel_id FROM vendors WHERE id = ?');
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

$store = null;
try {
    if ($isMart) {
        $martStores = $pdo->query("SELECT id, name, type, phone, emoji, logo, kind, `desc` FROM hotels WHERE kind = 'mart' ORDER BY id")->fetchAll();
        if ($martStores) {
            $vn = lyaideu_normalize_name((string)$vendor['name']);
            $store = $martStores[0];
            foreach ($martStores as $ms) {
                if ($vn !== '' && lyaideu_normalize_name((string)$ms['name']) === $vn) {
                    $store = $ms;
                    break;
                }
            }
        }
    } elseif ((int)$vendor['hotel_id'] > 0) {
        $st = $pdo->prepare('SELECT id, name, type, phone, emoji, logo, kind, `desc` FROM hotels WHERE id = ? LIMIT 1');
        $st->execute([(int)$vendor['hotel_id']]);
        $store = $st->fetch() ?: null;
    }
} catch (Throwable $e) {
    $store = null;
}

$msg = $_GET['msg'] ?? null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    /* Shop open/close + hours + hide-all-products (same power as admin). */
    if (isset($_POST['shop_action'])) {
        try {
            lyaideu_ensure_delivery_tables();
            $shopAction = (string)$_POST['shop_action'];
            if ($shopAction === 'toggle_open') {
                $cur = lyaideu_vendor_shop_row($vendorId);
                $next = empty($cur['is_open']) ? 1 : 0;
                $pdo->prepare('UPDATE vendors SET is_open = ? WHERE id = ?')->execute([$next, $vendorId]);
                try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity('vendor.shop_self', 'vendor', $vendorId, ['action' => $shopAction]); } catch (Throwable $e2) {}
                header('Location: vendor_store?msg=' . urlencode($next ? 'Your shop is now OPEN — customers can add your products to cart.' : 'Your shop is now CLOSED — products stay visible but cannot be ordered.'));
                exit;
            }
            if ($shopAction === 'toggle_hidden') {
                $cur = lyaideu_vendor_shop_row($vendorId);
                $next = empty($cur['products_hidden']) ? 1 : 0;
                $pdo->prepare('UPDATE vendors SET products_hidden = ? WHERE id = ?')->execute([$next, $vendorId]);
                try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity('vendor.shop_self', 'vendor', $vendorId, ['action' => $shopAction]); } catch (Throwable $e2) {}
                header('Location: vendor_store?msg=' . urlencode($next ? 'All your products are now HIDDEN from the website.' : 'All your products are now VISIBLE on the website.'));
                exit;
            }
            if ($shopAction === 'save_hours') {
                $openTime = lyaideu_sanitize_vendor_time($_POST['open_time'] ?? null);
                $closeTime = lyaideu_sanitize_vendor_time($_POST['close_time'] ?? null);
                $rawOpen = trim((string)($_POST['open_time'] ?? ''));
                $rawClose = trim((string)($_POST['close_time'] ?? ''));
                if (($rawOpen !== '' && $openTime === null) || ($rawClose !== '' && $closeTime === null)) {
                    $error = 'Use HH:MM for opening hours (e.g. 09:00), or leave blank for 24h open.';
                } else {
                    $pdo->prepare('UPDATE vendors SET open_time = ?, close_time = ? WHERE id = ?')->execute([$openTime, $closeTime, $vendorId]);
                    try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity('vendor.shop_self', 'vendor', $vendorId, ['action' => $shopAction]); } catch (Throwable $e2) {}
                    header('Location: vendor_store?msg=' . urlencode('Opening hours saved. They apply together with your shop switch.'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'Could not save shop status. Try again.';
        }
        if (!isset($error) || $error === null) {
            header('Location: vendor_store');
            exit;
        }
    }

    if (isset($_POST['shop_action'])) {
        /* Hours validation error above stays as-is; shop toggles already redirected. */
    } elseif (!$store) {
        $error = 'Your account is not linked to a store. Ask the admin to link one first.';
    } else {
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        $type = trim(strip_tags((string)($_POST['type'] ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $desc = trim(strip_tags((string)($_POST['desc'] ?? '')));

        if ($name === '') {
            $error = 'Store name is required.';
        }

        if (!$error) {
            try {
                $file = null;
                if (isset($_FILES['logo_file']) && ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['logo_file'];
                }
                $logo = lyaideu_handle_item_image((string)($store['logo'] ?? ''), $_POST, $file, 'hotel_logo');

                $pdo->prepare('UPDATE hotels SET name = ?, type = ?, phone = ?, logo = ?, `desc` = ? WHERE id = ?')
                    ->execute([$name, $type, $phone, $logo, $desc, (int)$store['id']]);

                // Keep the vendor account name and the dishes in step with the store name.
                $oldName = (string)$store['name'];
                if ($oldName !== $name) {
                    $pdo->prepare('UPDATE vendors SET name = ? WHERE id = ?')->execute([$name, $vendorId]);
                    $pdo->prepare('UPDATE dishes SET hotel = ? WHERE vendor_id = ? OR hotel = ?')
                        ->execute([$name, $vendorId, $oldName]);
                }

                header('Location: vendor_store?msg=' . urlencode('Store information saved. Your store page has been updated.'));
                exit;
            } catch (Throwable $e) {
                $error = ($e instanceof RuntimeException) ? $e->getMessage() : 'Could not save the store information.';
            }
        }
    }
}

delivery_header('My Store', 'Edit Your Store Information', 'fa-store', $role);
?>
<a class="btn btn-outline" href="vendor" style="margin-bottom:1.2rem;"><i class="fa-solid fa-arrow-left"></i> Back to Order Queue</a>

<?php if ($msg): ?>
<div class="flash-banner flash-success delivery-flash"><i class="fa-solid fa-circle-check"></i> <?= delivery_esc($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash-banner flash-error delivery-flash"><i class="fa-solid fa-circle-xmark"></i> <?= delivery_esc($error) ?></div>
<?php endif; ?>

<div class="delivery-section store-edit">
    <?php if (!$store): ?>
    <div class="admin-card">
        <h3>No linked store</h3>
        <p class="small-note">Your vendor account is not linked to a store yet. Ask the administrator to link your account to your store so you can edit its information here.</p>
    </div>
    <?php else: ?>

    <div class="store-hero">
        <div class="store-hero-logo">
            <?php if (!empty($store['logo'])): ?>
            <img src="<?= delivery_esc($store['logo']) ?>" alt="<?= delivery_esc($store['name']) ?> logo">
            <?php else: ?>
            <i class="fa-solid <?= $isMart ? 'fa-basket-shopping' : 'fa-store' ?>"></i>
            <?php endif; ?>
        </div>
        <div class="store-hero-info">
            <p class="kicker"><i class="fa-solid <?= $isMart ? 'fa-basket-shopping' : 'fa-hotel' ?>"></i> <?= $isMart ? 'Your Mart Store' : 'Your Store Profile' ?></p>
            <h2 class="display"><?= delivery_esc($store['name']) ?></h2>
            <?php if (!empty($store['type'])): ?><p class="store-hero-tag"><?= delivery_esc($store['type']) ?></p><?php endif; ?>
            <div class="store-hero-badges">
                <?php if (!empty($store['phone'])): ?>
                <span><i class="fa-solid fa-phone"></i> +977 <?= delivery_esc($store['phone']) ?></span>
                <?php endif; ?>
                <span><i class="fa-solid fa-eye"></i> Live on the <?= $isMart ? 'Mart' : 'Stores' ?> page</span>
            </div>
        </div>
    </div>

    <?php
    $shopRowS = lyaideu_vendor_shop_row($vendorId);
    $shopStatusS = lyaideu_vendor_is_orderable($vendorId);
    $shopOpenS = !empty($shopRowS['is_open']);
    $shopHiddenS = !empty($shopRowS['products_hidden']);
    $shopOpenTS = !empty($shopRowS['open_time']) ? substr((string)$shopRowS['open_time'], 0, 5) : '';
    $shopCloseTS = !empty($shopRowS['close_time']) ? substr((string)$shopRowS['close_time'], 0, 5) : '';
    ?>
    <style>
    .shop-card{background:#fff;border:1px solid var(--orange-100);border-radius:14px;padding:1rem;box-shadow:var(--shadow-sm);margin-bottom:1rem;}
    .shop-card-top{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;}
    .shop-card-top h3{font-size:1rem;font-weight:900;color:var(--orange-900);margin:0;}
    .shop-pill{font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:.22rem .6rem;white-space:nowrap;}
    .shop-pill-open{background:#e7f7ec;color:#1d7a3a;border:1px solid #bfe6cc;}
    .shop-pill-closed{background:#fdeaea;color:#c93a3a;border:1px solid #f5c2c2;}
    .shop-card-sub{font-size:.78rem;font-weight:700;color:var(--muted);margin:.4rem 0 0;line-height:1.5;}
    .shop-card-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem;align-items:center;}
    .shop-toggle{position:relative;width:52px;height:28px;border-radius:999px;border:0;cursor:pointer;flex:none;background:#d9d4cc;transition:background .2s ease;padding:0;}
    .shop-toggle .shop-knob{position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .2s ease;}
    .shop-toggle.on{background:var(--orange-600);}
    .shop-toggle.on .shop-knob{left:27px;}
    .shop-hours{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;}
    .shop-hours input[type="time"]{border:1px solid var(--orange-200);border-radius:8px;padding:.35rem .5rem;font-size:.8rem;font-weight:800;color:var(--orange-900);font-family:inherit;}
    @media (max-width:640px){.shop-card-actions{align-items:stretch;flex-direction:column;}.shop-hours{width:100%;}.shop-hours input[type="time"]{flex:1;}}
    </style>
    <section class="shop-card" aria-label="Shop status">
        <div class="shop-card-top"><i class="fa-solid fa-store" style="color:var(--orange-600);"></i><h3>Shop Status</h3>
        <?php if ($shopHiddenS): ?>
            <span class="shop-pill shop-pill-closed">Products hidden</span>
        <?php elseif (!empty($shopStatusS['open'])): ?>
            <span class="shop-pill shop-pill-open">Open now</span>
        <?php else: ?>
            <span class="shop-pill shop-pill-closed"><?= delivery_esc($shopStatusS['label'] !== '' ? $shopStatusS['label'] : 'Closed now') ?></span>
        <?php endif; ?>
        </div>
        <p class="shop-card-sub">OFF or outside hours = products stay visible but customers cannot add them to cart. “Hide products” removes everything at once. Times are Nepal time — blank = 24h open.</p>
        <div class="shop-card-actions">
            <form method="POST" action="vendor_store" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;"><input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>"><span class="small-note" style="font-weight:900;">Shop <?= $shopOpenS ? 'ON' : 'OFF' ?></span><button type="submit" name="shop_action" value="toggle_open" class="shop-toggle<?= $shopOpenS ? ' on' : '' ?>" aria-pressed="<?= $shopOpenS ? 'true' : 'false' ?>" aria-label="Turn shop <?= $shopOpenS ? 'off' : 'on' ?>" title="Turn shop <?= $shopOpenS ? 'off' : 'on' ?>"><span class="shop-knob"></span></button></form>
            <form method="POST" action="vendor_store" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;"><input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>"><span class="small-note" style="font-weight:900;">Products <?= $shopHiddenS ? 'hidden' : 'visible' ?></span><button type="submit" name="shop_action" value="toggle_hidden" class="shop-toggle<?= $shopHiddenS ? '' : ' on' ?>" aria-pressed="<?= $shopHiddenS ? 'false' : 'true' ?>" aria-label="<?= $shopHiddenS ? 'Show' : 'Hide' ?> all my products" title="<?= $shopHiddenS ? 'Show' : 'Hide' ?> all my products"><span class="shop-knob"></span></button></form>
            <form method="POST" action="vendor_store" class="shop-hours"><input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>"><input type="time" name="open_time" value="<?= delivery_esc($shopOpenTS) ?>" aria-label="Opening time"><span class="small-note">–</span><input type="time" name="close_time" value="<?= delivery_esc($shopCloseTS) ?>" aria-label="Closing time"><button type="submit" name="shop_action" value="save_hours" class="btn btn-outline btn-sm"><i class="fa-solid fa-clock"></i> Save hours</button></form>
        </div>
    </section>

    <form action="vendor_store" method="POST" enctype="multipart/form-data" class="admin-card store-form">
        <h3><i class="fa-solid fa-pen-to-square"></i> Store Information</h3>
        <p class="store-form-sub"><?= $isMart ? 'Items you publish appear on the <strong>Mart</strong> page.' : 'This information appears on your store\'s own page — open it from the <strong>Stores</strong> page.' ?></p>
        <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$store['id'] ?>">

        <div class="store-field">
            <label for="store-name">Store name</label>
            <div class="store-input">
                <i class="fa-solid fa-store"></i>
                <input type="text" id="store-name" name="name" value="<?= delivery_esc($store['name']) ?>" required>
            </div>
            <p class="field-hint">This is how customers see your store everywhere on the site.</p>
        </div>

        <div class="store-field-row">
            <div class="store-field">
                <label for="store-type">Tagline / type</label>
                <div class="store-input">
                    <i class="fa-solid fa-tag"></i>
                    <input type="text" id="store-type" name="type" value="<?= delivery_esc($store['type']) ?>" placeholder="e.g. Momo · New Baneshwor">
                </div>
                <p class="field-hint">A short line that tells customers what you serve.</p>
            </div>
            <div class="store-field">
                <label for="store-phone">Phone</label>
                <div class="store-input">
                    <i class="fa-solid fa-phone"></i>
                    <input type="text" id="store-phone" name="phone" value="<?= delivery_esc($store['phone']) ?>" placeholder="98XXXXXXXX">
                </div>
            </div>
        </div>

        <div class="store-field">
            <label for="store-logo">Logo <span class="muted">(optional)</span></label>
            <div class="store-logo-upload">
                <div class="img-preview">
                    <?php if (!empty($store['logo'])): ?>
                    <img src="<?= delivery_esc($store['logo']) ?>" alt="Current logo">
                    <?php else: ?>
                    <i class="fa-solid fa-store"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <input type="file" id="store-logo" name="logo_file" class="settings-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                    <?php if (!empty($store['logo'])): ?>
                    <label class="delete-check"><input type="checkbox" name="remove_img" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>
                    <?php endif; ?>
                </div>
            </div>
            <p class="field-hint">A square image works best. PNG, JPG, WebP, GIF or SVG.</p>
        </div>

        <div class="store-field">
            <label for="store-desc">About the store</label>
            <textarea id="store-desc" name="desc" rows="4" placeholder="Tell customers what makes your store special..."><?= delivery_esc($store['desc']) ?></textarea>
            <p class="field-hint">A few friendly sentences shown on your store page.</p>
        </div>

        <div class="store-form-actions">
            <a class="btn btn-outline" href="vendor"><i class="fa-solid fa-xmark"></i> Cancel</a>
            <button type="submit" name="store_save" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Store Information</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php
delivery_footer();