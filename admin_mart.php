<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_mart_table();
lyaideu_ensure_categories_table();
lyaideu_ensure_stores();
$martCatsFlat = lyaideu_categories_flat('mart');

try {
    $items = $pdo->query(
        'SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, vendor_id FROM mart_items ORDER BY id'
    )->fetchAll();
    $martVendors = $pdo->query("SELECT id, name FROM vendors WHERE scope = 'mart' AND is_active = 1 ORDER BY id")->fetchAll();
    $martStores = $pdo->query("SELECT id, name, type, phone, emoji, logo FROM hotels WHERE kind = 'mart' ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load mart items.');
}

admin_page_start('Mart', 'mart', 'Mart');
?>
<form action="admin_save" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="mart_stores">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Manage the Mart store card shown on the homepage &amp; Stores page.</p>
            <span class="admin-count-badge"><?= count($martStores) ?> store<?= count($martStores) === 1 ? '' : 's' ?></span>
        </div>
        <div class="admin-grid">
            <?php foreach ($martStores as $i => $ms): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($ms['name']) ?></h3>
                <input type="hidden" name="mart_stores[<?= $i ?>][id]" value="<?= (int)$ms['id'] ?>">
                <label>Store Name</label>
                <input type="text" name="mart_stores[<?= $i ?>][name]" value="<?= htmlspecialchars($ms['name']) ?>" required>
                <label>Tagline / Type</label>
                <input type="text" name="mart_stores[<?= $i ?>][type]" value="<?= htmlspecialchars($ms['type']) ?>">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="mart_stores[<?= $i ?>][phone]" value="<?= htmlspecialchars($ms['phone']) ?>"></div>
                    <div><label>Icon class</label><input type="text" name="mart_stores[<?= $i ?>][emoji]" value="<?= htmlspecialchars($ms['emoji']) ?>" placeholder="fa-basket-shopping"></div>
                </div>
                <label>Logo <span style="text-transform:none;font-weight:700;">(upload — optional, icon shown if empty)</span></label>
                <input type="file" name="mart_stores[<?= $i ?>][logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <input type="hidden" name="mart_stores[<?= $i ?>][logo]" value="<?= htmlspecialchars($ms['logo'] ?? '') ?>">
                <?php if (!empty($ms['logo'])): ?>
                    <div class="img-preview"><img src="<?= htmlspecialchars($ms['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Current logo"></div>
                <?php endif; ?>
                <label class="delete-check"><input type="checkbox" name="mart_stores[<?= $i ?>][remove_logo]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>
                <label class="delete-check"><input type="checkbox" name="mart_stores[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this store</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Mart Store</h3>
                <label>Store Name</label><input type="text" name="new_mart_store[name]" placeholder="e.g. LyaiDeu Mart">
                <label>Tagline / Type</label><input type="text" name="new_mart_store[type]" placeholder="e.g. Grocery &amp; daily essentials">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="new_mart_store[phone]" placeholder="98XXXXXXXX"></div>
                    <div><label>Icon class</label><input type="text" name="new_mart_store[emoji]" value="fa-basket-shopping"></div>
                </div>
                <label>Logo <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <input type="file" name="new_mart_store[logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Mart Store</button>
</form>

<form action="admin_save" method="POST" enctype="multipart/form-data" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="mart">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Edit existing grocery items or add new ones. Changes appear live on the Mart page.</p>
            <span class="admin-count-badge"><?= count($items) ?> items</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($items as $i => $m): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($m['name']) ?></h3>
                <input type="hidden" name="mart[<?= $i ?>][id]" value="<?= (int)$m['id'] ?>">
                <label>Item Name</label>
                <input type="text" name="mart[<?= $i ?>][name]" value="<?= htmlspecialchars($m['name']) ?>" required>
                <label>Vendor</label>
                <select name="mart[<?= $i ?>][vendor_id]">
                    <?php foreach ($martVendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$m['vendor_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="mart[<?= $i ?>][category_id]">
                            <option value="0">— No category —</option>
                            <?php foreach ($martCatsFlat as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>" <?= (int)$m['category_id'] === (int)$mc['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $mc['depth']) ?><?= htmlspecialchars($mc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Unit</label>
                        <input type="text" name="mart[<?= $i ?>][unit]" value="<?= htmlspecialchars($m['unit']) ?>" placeholder="kg / litre / pack">
                    </div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label>
                        <input type="number" min="0" step="1" name="mart[<?= $i ?>][price]" value="<?= (int)$m['price'] ?>" required>
                    </div>
                    <div><label>Tag</label>
                        <input type="text" name="mart[<?= $i ?>][tag]" value="<?= htmlspecialchars($m['tag']) ?>">
                    </div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional, a category icon is shown if empty)</span></label>
                <input type="file" name="mart[<?= $i ?>][img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <input type="hidden" name="mart[<?= $i ?>][img]" value="<?= htmlspecialchars($m['img']) ?>">
                <?php if (!empty($m['img'])): ?>
                    <div class="img-preview"><img src="<?= htmlspecialchars($m['img'], ENT_QUOTES, 'UTF-8') ?>" alt="Current image"></div>
                <?php endif; ?>
                <label class="delete-check"><input type="checkbox" name="mart[<?= $i ?>][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                <label>Description</label>
                <textarea name="mart[<?= $i ?>][desc]"><?= htmlspecialchars($m['desc']) ?></textarea>
                <label class="delete-check"><input type="checkbox" name="mart[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this item</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Item</h3>
                <label>Item Name</label><input type="text" name="new_mart[name]" placeholder="e.g. Garlic">
                <label>Vendor</label>
                <select name="new_mart[vendor_id]">
                    <?php foreach ($martVendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="new_mart[category_id]">
                            <option value="0">— No category —</option>
                            <?php foreach ($martCatsFlat as $mc): ?>
                            <option value="<?= (int)$mc['id'] ?>"><?= str_repeat('— ', $mc['depth']) ?><?= htmlspecialchars($mc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Unit</label><input type="text" name="new_mart[unit]" placeholder="kg / litre / pack"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_mart[price]" placeholder="60"></div>
                    <div><label>Tag</label><input type="text" name="new_mart[tag]" placeholder="New!"></div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional, a category icon is shown if empty)</span></label>
                <input type="file" name="new_mart[img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                <label>Description</label><textarea name="new_mart[desc]" placeholder="Short description..."></textarea>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Mart Changes</button>
</form>
<?php
admin_page_end();