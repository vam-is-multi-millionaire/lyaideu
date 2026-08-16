<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_other_table();
lyaideu_ensure_categories_table();
lyaideu_ensure_stores();
$otherCatsFlat = lyaideu_categories_flat('other');

try {
    $items = $pdo->query(
        'SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, vendor_id FROM other_items ORDER BY id'
    )->fetchAll();
    $otherVendors = $pdo->query("SELECT id, name FROM vendors WHERE scope = 'other' AND is_active = 1 ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load other items.');
}

admin_page_start('Others', 'others', 'Others');
?>
<p class="section-sub" style="margin-bottom:1rem;">Bouquets, candles, achar, gifts and other non-food items sold from the Other store(s). Store cards and their vendor logins are managed under <strong>Stores &amp; Vendors</strong>.</p>

<form action="admin_save" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="others">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Edit existing other items or add new ones. Changes appear live on the Others page.</p>
            <span class="admin-count-badge"><?= count($items) ?> items</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($items as $i => $m): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($m['name']) ?></h3>
                <input type="hidden" name="others[<?= $i ?>][id]" value="<?= (int)$m['id'] ?>">
                <label>Item Name</label>
                <input type="text" name="others[<?= $i ?>][name]" value="<?= htmlspecialchars($m['name']) ?>" required>
                <label>Vendor</label>
                <select name="others[<?= $i ?>][vendor_id]">
                    <?php foreach ($otherVendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$m['vendor_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="others[<?= $i ?>][category_id]">
                            <option value="0">— No category —</option>
                            <?php foreach ($otherCatsFlat as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>" <?= (int)$m['category_id'] === (int)$mc['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $mc['depth']) ?><?= htmlspecialchars($mc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Unit</label>
                        <input type="text" name="others[<?= $i ?>][unit]" value="<?= htmlspecialchars($m['unit']) ?>" placeholder="piece / set / bunch">
                    </div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label>
                        <input type="number" min="0" step="1" name="others[<?= $i ?>][price]" value="<?= (int)$m['price'] ?>" required>
                    </div>
                    <div><label>Tag</label>
                        <input type="text" name="others[<?= $i ?>][tag]" value="<?= htmlspecialchars($m['tag']) ?>">
                    </div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional, a category icon is shown if empty)</span></label>
                <input type="file" name="others[<?= $i ?>][img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <input type="hidden" name="others[<?= $i ?>][img]" value="<?= htmlspecialchars($m['img']) ?>">
                <?php if (!empty($m['img'])): ?>
                    <div class="img-preview"><img src="<?= htmlspecialchars($m['img'], ENT_QUOTES, 'UTF-8') ?>" alt="Current image"></div>
                <?php endif; ?>
                <label class="delete-check"><input type="checkbox" name="others[<?= $i ?>][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                <label>Description</label>
                <textarea name="others[<?= $i ?>][desc]"><?= htmlspecialchars($m['desc']) ?></textarea>
                <label class="delete-check"><input type="checkbox" name="others[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this item</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Item</h3>
                <label>Item Name</label><input type="text" name="new_others[name]" placeholder="e.g. Rose Bouquet">
                <label>Vendor</label>
                <select name="new_others[vendor_id]">
                    <?php foreach ($otherVendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="new_others[category_id]">
                            <option value="0">— No category —</option>
                            <?php foreach ($otherCatsFlat as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>"><?= str_repeat('— ', $mc['depth']) ?><?= htmlspecialchars($mc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Unit</label><input type="text" name="new_others[unit]" placeholder="piece / set / bunch"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_others[price]" placeholder="500"></div>
                    <div><label>Tag</label><input type="text" name="new_others[tag]" placeholder="New!"></div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional, a category icon is shown if empty)</span></label>
                <input type="file" name="new_others[img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <label>Description</label><textarea name="new_others[desc]" placeholder="Short description..."></textarea>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Others Changes</button>
</form>
<?php
admin_page_end();