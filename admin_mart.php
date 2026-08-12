<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_mart_table();

try {
    $items = $pdo->query(
        'SELECT id, name, cat, unit, price, tag, `desc`, img FROM mart_items ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load mart items.');
}

$martCategories = [
    'vegetables' => 'Vegetables',
    'fruits' => 'Fruits',
    'dairy' => 'Dairy',
    'staples' => 'Staples',
    'oils' => 'Oils & Spices',
    'snacks' => 'Snacks',
];

admin_page_start('Mart', 'mart', 'Mart');
?>
<form action="admin_save.php" method="POST" class="admin-form">
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
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="mart[<?= $i ?>][cat]">
                            <?php foreach ($martCategories as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $m['cat'] === $key ? 'selected' : '' ?>><?= $label ?></option>
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
                <label>Image URL <span style="text-transform:none;font-weight:700;">(optional — a category icon is shown if empty)</span></label>
                <input type="url" name="mart[<?= $i ?>][img]" value="<?= htmlspecialchars($m['img']) ?>">
                <label>Description</label>
                <textarea name="mart[<?= $i ?>][desc]"><?= htmlspecialchars($m['desc']) ?></textarea>
                <label class="delete-check"><input type="checkbox" name="mart[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this item</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Item</h3>
                <label>Item Name</label><input type="text" name="new_mart[name]" placeholder="e.g. Garlic">
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="new_mart[cat]">
                            <?php foreach ($martCategories as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Unit</label><input type="text" name="new_mart[unit]" placeholder="kg / litre / pack"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_mart[price]" placeholder="60"></div>
                    <div><label>Tag</label><input type="text" name="new_mart[tag]" placeholder="New!"></div>
                </div>
                <label>Image URL</label><input type="url" name="new_mart[img]" placeholder="https://... (optional)">
                <label>Description</label><textarea name="new_mart[desc]" placeholder="Short description..."></textarea>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Mart Changes</button>
</form>
<?php
admin_page_end();