<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();
lyaideu_ensure_stores();

$storeKinds = ['hotel' => 'Hotel / Restaurant', 'mart' => 'Mart', 'other' => 'Other business'];

$stores = [];
try {
    $stores = $pdo->query(
        'SELECT h.id, h.name, h.type, h.phone, h.emoji, h.logo, h.kind,
                v.id AS vendor_id, v.name AS vendor_name, v.email AS vendor_email,
                v.phone AS vendor_phone, v.is_active AS vendor_active
         FROM hotels h
         LEFT JOIN vendors v ON v.hotel_id = h.id AND v.scope = h.kind
         ORDER BY h.id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load stores.');
}

admin_page_start('Stores & Vendors', 'stores', 'Stores & Vendors');
?>
<p class="section-sub" style="margin-bottom:1rem;">Manage partner stores — hotels, the Mart and other businesses — and their vendor login accounts. Every hotel or Mart store gets a vendor automatically; the vendor logs in at <strong>/vendor</strong> to confirm orders.</p>
<form action="admin_save" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="hotels">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Edit existing stores or add new ones. Changes appear live on the Stores page.</p>
            <span class="admin-count-badge"><?= count($stores) ?> stores</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($stores as $i => $h): ?>
            <?php $isMartStore = ($h['kind'] ?? 'hotel') === 'mart'; ?>
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
                    <div><label>Icon class</label><input type="text" name="hotels[<?= $i ?>][emoji]" value="<?= htmlspecialchars($h['emoji'] ?? '') ?>" placeholder="fa-basket-shopping"></div>
                </div>
                <label>Logo <span style="text-transform:none;font-weight:700;">(upload — optional, icon shown if empty)</span></label>
                <input type="file" name="hotels[<?= $i ?>][logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <input type="hidden" name="hotels[<?= $i ?>][logo]" value="<?= htmlspecialchars($h['logo'] ?? '') ?>">
                <?php if (!empty($h['logo'])): ?>
                    <div class="img-preview"><img src="<?= htmlspecialchars($h['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Current logo"></div>
                <?php endif; ?>
                <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][remove_logo]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>

                <div class="admin-vendor-block">
                    <h4><i class="fa-solid fa-user-tie"></i> Vendor Account</h4>
                    <p class="small-note"><?= $isMartStore ? 'Mart vendor — fulfils grocery orders.' : ($h['kind'] === 'other' ? 'This store has no vendor (no products).' : 'Hotel vendor — prepares &amp; confirms orders at /vendor.') ?></p>
                    <input type="hidden" name="hotels[<?= $i ?>][vendor_id]" value="<?= (int)($h['vendor_id'] ?? 0) ?>">
                    <label>Vendor Name</label>
                    <input type="text" name="hotels[<?= $i ?>][vendor_name]" value="<?= htmlspecialchars($h['vendor_name'] ?? '') ?>" placeholder="<?= htmlspecialchars($h['name']) ?>">
                    <div class="admin-field-row">
                        <div><label>Phone</label><input type="text" name="hotels[<?= $i ?>][vendor_phone]" value="<?= htmlspecialchars($h['vendor_phone'] ?? '') ?>"></div>
                        <div><label>Email</label><input type="email" name="hotels[<?= $i ?>][vendor_email]" value="<?= htmlspecialchars($h['vendor_email'] ?? '') ?>"></div>
                    </div>
                    <label>New Password <span class="muted">(leave blank to keep)</span></label>
                    <input type="password" name="hotels[<?= $i ?>][vendor_password]" autocomplete="new-password">
                    <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][vendor_active]" value="1" <?= ($h['vendor_active'] ?? 1) ? 'checked' : '' ?>> <i class="fa-solid fa-circle-check"></i> Vendor active</label>
                </div>

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
                    <div><label>Icon class</label><input type="text" name="new_hotel[emoji]" placeholder="fa-basket-shopping"></div>
                </div>
                <label>Logo <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <input type="file" name="new_hotel[logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">

                <div class="admin-vendor-block">
                    <h4><i class="fa-solid fa-user-tie"></i> Vendor Account</h4>
                    <p class="small-note">Created automatically for hotel &amp; Mart stores. Leave blank to use the store name and a default password.</p>
                    <label>Vendor Name</label><input type="text" name="new_hotel[vendor_name]" placeholder="e.g. Spice Garden Kitchen">
                    <div class="admin-field-row">
                        <div><label>Phone</label><input type="text" name="new_hotel[vendor_phone]" placeholder="98XXXXXXXX"></div>
                        <div><label>Email</label><input type="email" name="new_hotel[vendor_email]" placeholder="kitchen@example.com"></div>
                    </div>
                    <label>Password <span class="muted">(default: vendor123)</span></label>
                    <input type="password" name="new_hotel[vendor_password]" autocomplete="new-password">
                </div>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Stores &amp; Vendors</button>
</form>
<?php
admin_page_end();