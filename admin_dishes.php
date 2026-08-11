<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

try {
    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img FROM dishes ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load dishes.');
}

admin_page_start('Menu Items', 'dishes', 'Menu Items');
?>
<form action="admin_save.php" method="POST" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="dishes">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Edit existing dishes or add new menu items. Changes appear on the live website.</p>
            <span class="admin-count-badge"><?= count($dishes) ?> items</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($dishes as $i => $d): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($d['name']) ?></h3>
                <input type="hidden" name="dishes[<?= $i ?>][id]" value="<?= (int)$d['id'] ?>">
                <label>Dish Name</label>
                <input type="text" name="dishes[<?= $i ?>][name]" value="<?= htmlspecialchars($d['name']) ?>" required>
                <label>Hotel Name</label>
                <input type="text" name="dishes[<?= $i ?>][hotel]" value="<?= htmlspecialchars($d['hotel']) ?>" required>
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="dishes[<?= $i ?>][cat]">
                            <?php foreach (['momo','pizza','chowmein','snacks','beverages','dinner'] as $c): ?>
                            <option value="<?= $c ?>" <?= $d['cat'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Price</label>
                        <input type="number" min="0" step="1" name="dishes[<?= $i ?>][price]" value="<?= (int)$d['price'] ?>" required>
                    </div>
                </div>
                <label>Image URL</label>
                <input type="url" name="dishes[<?= $i ?>][img]" value="<?= htmlspecialchars($d['img']) ?>">
                <label>Description</label>
                <textarea name="dishes[<?= $i ?>][desc]"><?= htmlspecialchars($d['desc']) ?></textarea>
                <div class="admin-field-row">
                    <div><label>Phone</label>
                        <input type="text" name="dishes[<?= $i ?>][phone]" value="<?= htmlspecialchars($d['phone']) ?>">
                    </div>
                    <div><label>Tag</label>
                        <input type="text" name="dishes[<?= $i ?>][tag]" value="<?= htmlspecialchars($d['tag']) ?>">
                    </div>
                </div>
                <label class="delete-check"><input type="checkbox" name="dishes[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this dish</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Dish</h3>
                <label>Dish Name</label><input type="text" name="new_dish[name]" placeholder="e.g. Paneer Tikka">
                <label>Hotel Name</label><input type="text" name="new_dish[hotel]" placeholder="e.g. Spice Garden">
                <div class="admin-field-row">
                    <div><label>Category</label>
                        <select name="new_dish[cat]">
                            <option value="momo">Momo</option><option value="pizza">Pizza</option>
                            <option value="chowmein">Chowmein</option><option value="snacks">Snacks</option>
                            <option value="beverages">Beverages</option><option value="dinner">Dinner</option>
                        </select>
                    </div>
                    <div><label>Price</label><input type="number" min="0" step="1" name="new_dish[price]" placeholder="250"></div>
                </div>
                <label>Image URL</label><input type="url" name="new_dish[img]" placeholder="https://...">
                <label>Description</label><textarea name="new_dish[desc]" placeholder="Short tasty description..."></textarea>
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="new_dish[phone]" placeholder="98XXXXXXXX"></div>
                    <div><label>Tag</label><input type="text" name="new_dish[tag]" placeholder="New!"></div>
                </div>
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Menu Changes</button>
</form>
<?php
admin_page_end();
