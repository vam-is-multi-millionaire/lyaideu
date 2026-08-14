<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();

$errors = [];
$saved = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (isset($_POST['vendor_save'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Vendor name is required.';
        }
        if ($phone === '') {
            $errors[] = 'Vendor phone is required.';
        }
        if ($id === 0 && strlen($pass) < 6) {
            $errors[] = 'Password must be at least 6 characters for a new vendor.';
        }

        if (!$errors) {
            $conflict = lyaideu_delivery_credential_conflict('vendor', $phone, $email, $id);
            if ($conflict) {
                $errors[] = $conflict;
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    if ($pass !== '') {
                        $st = $pdo->prepare('UPDATE vendors SET name = ?, email = ?, phone = ?, pass = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $isActive, $id]);
                    } else {
                        $st = $pdo->prepare('UPDATE vendors SET name = ?, email = ?, phone = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, $isActive, $id]);
                    }
                } else {
                    $st = $pdo->prepare('INSERT INTO vendors (name, email, phone, pass, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                    $st->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $isActive, date('Y-m-d H:i:s')]);
                }
                header('Location: admin_vendors?saved=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Could not save vendor. ' . (str_contains(strtolower($e->getMessage()), 'duplicate') ? 'A vendor with this phone or email may already exist.' : '');
            }
        }
    }

    if (isset($_POST['vendor_delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM vendors WHERE id = ?')->execute([$id]);
            header('Location: admin_vendors?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not delete vendor.';
        }
    }
}

$vendors = [];
try {
    $vendors = $pdo->query('SELECT id, name, email, phone, is_active, created_at FROM vendors ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $vendors = [];
}

admin_page_start('Vendors', 'vendors', 'Vendor Management');
?>
<?php if ($saved): ?><div class="flash-banner flash-success admin-flash"><i class="fa-solid fa-circle-check"></i> Changes saved successfully.</div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="flash-banner flash-error admin-flash"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Partner kitchens who prepare and confirm orders. They log in at <strong>/vendor</strong>.</p>
        <span class="admin-count-badge"><?= count($vendors) ?> vendors</span>
    </div>
    <div class="admin-grid">
        <?php foreach ($vendors as $v): ?>
        <form action="admin_vendors" method="POST" class="admin-card">
            <h3><?= htmlspecialchars($v['name']) ?></h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <label>Vendor Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($v['name']) ?>" required>
            <div class="admin-field-row">
                <div><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($v['phone']) ?>" required></div>
                <div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($v['email']) ?>"></div>
            </div>
            <label>New Password <span class="muted">(leave blank to keep)</span></label>
            <input type="password" name="password" autocomplete="new-password">
            <label class="delete-check"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> <i class="fa-solid fa-circle-check"></i> Active</label>
            <button type="submit" name="vendor_save" class="btn btn-primary btn-block">Save Vendor</button>
            <label class="delete-check"><input type="checkbox" name="vendor_delete" value="1" onchange="if(confirm('Delete this vendor?'))this.form.submit()"> <i class="fa-solid fa-trash-can"></i> Delete this vendor</label>
        </form>
        <?php endforeach; ?>

        <form action="admin_vendors" method="POST" class="admin-card admin-add-card">
            <h3><i class="fa-solid fa-plus"></i> Add New Vendor</h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <label>Vendor Name</label><input type="text" name="name" placeholder="e.g. Himalayan Momo House">
            <div class="admin-field-row">
                <div><label>Phone</label><input type="text" name="phone" placeholder="98XXXXXXXX"></div>
                <div><label>Email</label><input type="email" name="email" placeholder="kitchen@example.com"></div>
            </div>
            <label>Password</label><input type="password" name="password" placeholder="Set a password (min 6 chars)">
            <label class="delete-check"><input type="checkbox" name="is_active" value="1" checked> <i class="fa-solid fa-circle-check"></i> Active</label>
            <button type="submit" name="vendor_save" class="btn btn-primary btn-block"><i class="fa-solid fa-plus"></i> Create Vendor</button>
        </form>
    </div>
</section>
<?php
admin_page_end();