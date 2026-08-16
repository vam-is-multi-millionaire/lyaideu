<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_stores();

try {
    $hotels = $pdo->query(
        'SELECT id, name, type, phone, emoji, logo, kind FROM hotels ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load stores.');
}

admin_page_start('Stores', 'hotels', 'Partner Stores');
?>
<?php
$storeKinds = ['hotel' => 'Hotel / Restaurant', 'mart' => 'Mart', 'other' => 'Other business'];
?>
<form action="admin_save" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="hotels">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Manage partner stores — hotels, the Mart and other businesses shown on the Stores page of the website.</p>
            <span class="admin-count-badge"><?= count($hotels) ?> stores</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($hotels as $i => $h): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($h['name']) ?></h3>
                <input type="hidden" name="hotels[<?= $i ?>][id]" value="<?= (int)$h['id'] ?>">
                <label>Store Name</label>
                <input type="text" name="hotels[<?= $i ?>][name]" value="<?= htmlspecialchars($h['name']) ?>" required>
                <label>Kind of business</label>
                <select name="hotels[<?= $i ?>][kind]">
                    <?php foreach ($storeKinds as $sk => $skLabel): ?>
                    <option value="<?= $sk ?>" <?= ($h['kind'] ?? 'hotel') === $sk ? 'selected' : '' ?>><?= htmlspecialchars($skLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Type / Location</label>
                <input type="text" name="hotels[<?= $i ?>][type]" value="<?= htmlspecialchars($h['type']) ?>">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="hotels[<?= $i ?>][phone]" value="<?= htmlspecialchars($h['phone']) ?>"></div>
                    <div><label>Logo image</label><input type="file" name="hotels[<?= $i ?>][logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"></div>
                </div>
                <input type="hidden" name="hotels[<?= $i ?>][logo]" value="<?= htmlspecialchars($h['logo'] ?? '') ?>">
                <?php if (!empty($h['logo'])): ?>
                    <div class="hotel-logo-preview"><img src="<?= htmlspecialchars($h['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Current logo"></div>
                <?php endif; ?>
                <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][remove_logo]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>
                <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this store</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Store</h3>
                <label>Store Name</label><input type="text" name="new_hotel[name]" placeholder="e.g. Spice Garden">
                <label>Kind of business</label>
                <select name="new_hotel[kind]">
                    <?php foreach ($storeKinds as $sk => $skLabel): ?>
                    <option value="<?= $sk ?>"><?= htmlspecialchars($skLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Type / Location</label><input type="text" name="new_hotel[type]" placeholder="e.g. Indian · Pokhara Rd">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="new_hotel[phone]" placeholder="98XXXXXXXX"></div>
                    <div><label>Logo image</label><input type="file" name="new_hotel[logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"></div>
                </div>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Store Changes</button>
</form>
<?php
admin_page_end();
