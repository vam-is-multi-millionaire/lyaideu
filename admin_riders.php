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

    if (isset($_POST['rider_save'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $vehicle = trim((string)($_POST['vehicle'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Rider name is required.';
        }
        if ($phone === '') {
            $errors[] = 'Rider phone is required.';
        }
        if ($id === 0 && strlen($pass) < 6) {
            $errors[] = 'Password must be at least 6 characters for a new rider.';
        }

        if (!$errors) {
            $conflict = lyaideu_delivery_credential_conflict('rider', $phone, $email, $id);
            if ($conflict) {
                $errors[] = $conflict;
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    if ($pass !== '') {
                        $st = $pdo->prepare('UPDATE riders SET name = ?, email = ?, phone = ?, vehicle = ?, pass = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, $vehicle, password_hash($pass, PASSWORD_DEFAULT), $isActive, $id]);
                    } else {
                        $st = $pdo->prepare('UPDATE riders SET name = ?, email = ?, phone = ?, vehicle = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, $vehicle, $isActive, $id]);
                    }
                } else {
                    $st = $pdo->prepare('INSERT INTO riders (name, email, phone, vehicle, pass, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $st->execute([$name, $email, $phone, $vehicle, password_hash($pass, PASSWORD_DEFAULT), $isActive, date('Y-m-d H:i:s')]);
                }
                header('Location: admin_riders?saved=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Could not save rider. ' . (str_contains(strtolower($e->getMessage()), 'duplicate') ? 'A rider with this phone or email may already exist.' : '');
            }
        }
    }

    if (isset($_POST['rider_delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM riders WHERE id = ?')->execute([$id]);
            header('Location: admin_riders?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not delete rider.';
        }
    }
}

$riders = [];
try {
    $riders = $pdo->query('SELECT id, name, email, phone, vehicle, is_active, created_at FROM riders ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $riders = [];
}

admin_page_start('Riders', 'riders', 'Rider Management');
?>
<?php if ($saved): ?><div class="flash-banner flash-success admin-flash"><i class="fa-solid fa-circle-check"></i> Changes saved successfully.</div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="flash-banner flash-error admin-flash"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Delivery riders who pick up and deliver orders. They log in at <strong>/rider</strong>.</p>
        <span class="admin-count-badge"><?= count($riders) ?> riders</span>
    </div>
    <div class="admin-grid">
        <?php foreach ($riders as $r): ?>
        <form action="admin_riders" method="POST" class="admin-card">
            <h3><?= htmlspecialchars($r['name']) ?></h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label>Rider Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($r['name']) ?>" required>
            <div class="admin-field-row">
                <div><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($r['phone']) ?>" required></div>
                <div><label>Vehicle</label><input type="text" name="vehicle" value="<?= htmlspecialchars($r['vehicle']) ?>" placeholder="e.g. Bike"></div>
            </div>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($r['email']) ?>">
            <label>New Password <span class="muted">(leave blank to keep)</span></label>
            <input type="password" name="password" autocomplete="new-password">
            <label class="delete-check"><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>> <i class="fa-solid fa-circle-check"></i> Active</label>
            <button type="submit" name="rider_save" class="btn btn-primary btn-block">Save Rider</button>
            <label class="delete-check"><input type="checkbox" name="rider_delete" value="1" onchange="if(confirm('Delete this rider?'))this.form.submit()"> <i class="fa-solid fa-trash-can"></i> Delete this rider</label>
        </form>
        <?php endforeach; ?>

        <form action="admin_riders" method="POST" class="admin-card admin-add-card">
            <h3><i class="fa-solid fa-plus"></i> Add New Rider</h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <label>Rider Name</label><input type="text" name="name" placeholder="e.g. Bikash Rai">
            <div class="admin-field-row">
                <div><label>Phone</label><input type="text" name="phone" placeholder="98XXXXXXXX"></div>
                <div><label>Vehicle</label><input type="text" name="vehicle" placeholder="Bike / Scooter"></div>
            </div>
            <label>Email</label><input type="email" name="email" placeholder="rider@example.com">
            <label>Password</label><input type="password" name="password" placeholder="Set a password (min 6 chars)">
            <label class="delete-check"><input type="checkbox" name="is_active" value="1" checked> <i class="fa-solid fa-circle-check"></i> Active</label>
            <button type="submit" name="rider_save" class="btn btn-primary btn-block"><i class="fa-solid fa-plus"></i> Create Rider</button>
        </form>
    </div>
</section>
<?php
admin_page_end();