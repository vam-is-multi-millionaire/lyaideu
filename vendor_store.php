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

    if (!$store) {
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