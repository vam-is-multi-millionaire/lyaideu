<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

try {
    $hotels = $pdo->query(
        'SELECT id, name, type, phone, emoji, logo FROM hotels ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load hotels.');
}

admin_page_start('Hotels', 'hotels', 'Partner Hotels');
?>
<form action="admin_save.php" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="hotels">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Manage partner restaurants shown on the Hotels section of the website.</p>
            <span class="admin-count-badge"><?= count($hotels) ?> hotels</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($hotels as $i => $h): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($h['name']) ?></h3>
                <input type="hidden" name="hotels[<?= $i ?>][id]" value="<?= (int)$h['id'] ?>">
                <label>Hotel Name</label>
                <input type="text" name="hotels[<?= $i ?>][name]" value="<?= htmlspecialchars($h['name']) ?>" required>
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
                <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this hotel</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Hotel</h3>
                <label>Hotel Name</label><input type="text" name="new_hotel[name]" placeholder="e.g. Spice Garden">
                <label>Type / Location</label><input type="text" name="new_hotel[type]" placeholder="e.g. Indian · Pokhara Rd">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="new_hotel[phone]" placeholder="98XXXXXXXX"></div>
                    <div><label>Logo image</label><input type="file" name="new_hotel[logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"></div>
                </div>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Hotel Changes</button>
</form>
<?php
admin_page_end();
