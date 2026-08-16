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
<a class="btn btn-outline" href="vendor" style="margin-bottom:1rem;"><i class="fa-solid fa-arrow-left"></i> Back to Order Queue</a>

<?php if ($msg): ?>
<div class="flash-banner flash-success delivery-flash"><i class="fa-solid fa-circle-check"></i> <?= delivery_esc($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash-banner flash-error delivery-flash"><i class="fa-solid fa-circle-xmark"></i> <?= delivery_esc($error) ?></div>
<?php endif; ?>

<div class="delivery-section">
    <?php if (!$store): ?>
    <div class="admin-card">
        <h3>No linked store</h3>
        <p class="small-note">Your vendor account is not linked to a store yet. Ask the administrator to link your account to your store so you can edit its information here.</p>
    </div>
    <?php else: ?>
    <p class="small-note" style="margin-bottom:1rem;">
        <?php if ($isMart): ?>
        <i class="fa-solid fa-basket-shopping"></i> This is the Mart store shown on the <strong>Stores</strong> page. Its items appear on the <strong>Mart</strong> page.
        <?php else: ?>
        <i class="fa-solid fa-hotel"></i> This information appears on your store's own page — open it from the <strong>Stores</strong> page.
        <?php endif; ?>
    </p>

    <form action="vendor_store" method="POST" enctype="multipart/form-data" class="admin-card">
        <h3><i class="fa-solid fa-store"></i> <?= delivery_esc($store['name']) ?></h3>
        <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$store['id'] ?>">
        <label>Store Name</label>
        <input type="text" name="name" value="<?= delivery_esc($store['name']) ?>" required>
        <label>Tagline / Type</label>
        <input type="text" name="type" value="<?= delivery_esc($store['type']) ?>" placeholder="e.g. Momo · New Baneshwor">
        <div class="admin-field-row">
            <div><label>Phone</label><input type="text" name="phone" value="<?= delivery_esc($store['phone']) ?>" placeholder="98XXXXXXXX"></div>
            <div><label>Logo <span class="muted">(optional)</span></label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"></div>
        </div>
        <?php if (!empty($store['logo'])): ?>
        <div class="img-preview"><img src="<?= delivery_esc($store['logo']) ?>" alt="Current logo"></div>
        <label class="delete-check"><input type="checkbox" name="remove_img" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>
        <?php endif; ?>
        <label>About the store</label>
        <textarea name="desc" rows="4" placeholder="Tell customers what makes your store special..."><?= delivery_esc($store['desc']) ?></textarea>
        <button type="submit" name="store_save" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save Store Information</button>
    </form>
    <?php endif; ?>
</div>
<?php
delivery_footer();