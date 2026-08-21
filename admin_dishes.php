<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_categories_table();
lyaideu_ensure_variant_tables();
$dishCatsFlat = lyaideu_categories_flat('menu');

try {
    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, has_variants FROM dishes ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load dishes.');
}

$catNameById = [];
foreach ($dishCatsFlat as $dc) {
    $catNameById[(int)$dc['id']] = $dc['name'];
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function dish_category_options(array $flat, ?int $selected = null): string {
    global $ce;
    $html = '<option value="0">— No category —</option>';
    foreach ($flat as $dc) {
        $sel = $selected !== null && (int)$dc['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . (int)$dc['id'] . '"' . $sel . '>' . str_repeat('— ', (int)$dc['depth']) . $ce($dc['name']) . '</option>';
    }
    return $html;
}

function dish_thumb_html(string $img): string {
    if ($img !== '') {
        return '<span class="pm-thumb"><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    return '<span class="pm-thumb pm-thumb-empty"><i class="fa-solid fa-utensils"></i></span>';
}

admin_page_start('Menu Items', 'dishes', 'Menu Items');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Manage your menu. Use <strong>Edit</strong> to change a dish, <strong>Delete</strong> to remove one, and <strong>Add New Dish</strong> to create a menu item. Changes appear on the live website instantly.</p>
        <span class="admin-count-badge"><?= count($dishes) ?> items</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="dishSearch" placeholder="Search dishes by name, hotel or tag…" aria-label="Search dishes">
    <button type="button" class="btn btn-primary" id="addDishBtn"><i class="fa-solid fa-plus"></i> Add New Dish</button>
</div>

<div class="pm-add-panel" id="addDishPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Dish</h3>
    <form action="admin_save" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="section" value="dishes">
        <div class="admin-field-row">
            <div><label>Dish Name</label><input type="text" name="new_dish[name]" placeholder="e.g. Paneer Tikka" required></div>
            <div><label>Hotel Name</label><input type="text" name="new_dish[hotel]" placeholder="e.g. Spice Garden" required></div>
        </div>
        <div class="admin-field-row">
            <div><label>Category</label><select name="new_dish[category_id]"><?= dish_category_options($dishCatsFlat) ?></select></div>
            <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_dish[price]" placeholder="250"></div>
        </div>
        <div class="admin-field-row">
            <div><label>Phone</label><input type="text" name="new_dish[phone]" placeholder="98XXXXXXXX"></div>
            <div><label>Tag</label><input type="text" name="new_dish[tag]" placeholder="e.g. Best Seller"></div>
        </div>
        <label>Image <span style="text-transform:none;font-weight:700;">(optional)</span></label>
        <div class="pm-img-field">
            <input type="file" name="new_dish[img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        </div>
        <label>Description</label>
        <textarea name="new_dish[desc]" placeholder="Short tasty description..."></textarea>
        <?= lyaideu_variants_editor_html('new_dish') ?>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Dish</button>
            <button type="button" class="btn btn-outline" id="addDishCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="dishList">
        <?php foreach ($dishes as $i => $d): $id = (int)$d['id']; ?>
        <?php $catName = $catNameById[(int)$d['category_id']] ?? ''; ?>
        <?php $itemVariants = lyaideu_item_variants('dish', $id); ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$d['name'] . ' ' . $d['hotel'] . ' ' . $d['tag'])) ?>">
            <div class="pm-item">
                <?= dish_thumb_html((string)$d['img']) ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($d['name']) ?></span>
                    <span class="pm-meta"><?= $ce($d['hotel']) ?><?= $catName !== '' ? ' · ' . $ce($catName) : '' ?></span>
                </span>
                <span class="pm-price">Rs. <?= (int)$d['price'] ?><?php if ($d['tag'] !== ''): ?><span class="pm-tag"><?= $ce($d['tag']) ?></span><?php endif; ?></span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_save" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="section" value="dishes">
                        <input type="hidden" name="dishes[<?= $i ?>][id]" value="<?= $id ?>">
                        <input type="hidden" name="dishes[<?= $i ?>][delete]" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete "' . $d['name'] . '" from the menu? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="dishes">
                <input type="hidden" name="dishes[<?= $i ?>][id]" value="<?= $id ?>">
                <input type="hidden" name="dishes[<?= $i ?>][img]" value="<?= $ce($d['img']) ?>">
                <div class="admin-field-row">
                    <div><label>Dish Name</label><input type="text" name="dishes[<?= $i ?>][name]" value="<?= $ce($d['name']) ?>" required></div>
                    <div><label>Hotel Name</label><input type="text" name="dishes[<?= $i ?>][hotel]" value="<?= $ce($d['hotel']) ?>" required></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Category</label><select name="dishes[<?= $i ?>][category_id]"><?= dish_category_options($dishCatsFlat, (int)$d['category_id']) ?></select></div>
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="dishes[<?= $i ?>][price]" value="<?= (int)$d['price'] ?>" required></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="dishes[<?= $i ?>][phone]" value="<?= $ce($d['phone']) ?>"></div>
                    <div><label>Tag</label><input type="text" name="dishes[<?= $i ?>][tag]" value="<?= $ce($d['tag']) ?>"></div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <div class="pm-img-field">
                    <span class="pm-img-preview"<?= $d['img'] !== '' ? '' : ' style="display:none"' ?>><img src="<?= $ce($d['img']) ?>" alt=""></span>
                    <input type="file" name="dishes[<?= $i ?>][img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                </div>
                <?php if ($d['img'] !== ''): ?>
                <label class="pm-remove-img"><input type="checkbox" name="dishes[<?= $i ?>][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                <?php endif; ?>
                <label>Description</label>
                <textarea name="dishes[<?= $i ?>][desc]"><?= $ce($d['desc']) ?></textarea>
                <?= lyaideu_variants_editor_html('dishes[' . $i . ']', $itemVariants, (bool)$d['has_variants']) ?>
                <div class="pm-edit-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Dish</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="dishEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No dishes match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addDishBtn'),addPanel=document.getElementById('addDishPanel'),addCancel=document.getElementById('addDishCancel');
  function setAdd(open){
    if(!addPanel)return;
    addPanel.style.display=open?'block':'none';
    if(addBtn)addBtn.classList.toggle('active',open);
  }
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none');});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('dishSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('dishEmpty');
      if(empty)empty.style.display=any?'none':'block';
    });
  }

  document.addEventListener('click',function(e){
    var edit=e.target.closest('.pm-edit');
    if(edit){
      var form=document.getElementById(edit.getAttribute('data-target'));
      var row=edit.closest('.pm-row');
      if(!form||!row)return;
      var opening=form.style.display==='none';
      document.querySelectorAll('.pm-quick-edit').forEach(function(f){f.style.display='none';});
      document.querySelectorAll('.pm-item').forEach(function(it){it.style.display='';});
      if(opening){
        row.querySelector('.pm-item').style.display='none';
        form.style.display='block';
      }
      return;
    }
    var cancel=e.target.closest('.pm-cancel');
    if(cancel){
      var f=cancel.closest('.pm-quick-edit');
      if(f){
        f.style.display='none';
        var it=f.closest('.pm-row').querySelector('.pm-item');
        if(it)it.style.display='';
      }
    }
  });

  document.querySelectorAll('.pm-del-inline').forEach(function(form){
    form.addEventListener('submit',function(e){
      var btn=form.querySelector('.pm-del-btn');
      if(btn&&!window.confirm(btn.getAttribute('data-confirm')))e.preventDefault();
    });
  });

  document.addEventListener('change',function(e){
    var input=e.target;
    if(!input||!input.files||!input.files[0])return;
    var field=input.closest('.pm-img-field');
    if(!field)return;
    var prev=field.querySelector('.pm-img-preview');
    if(!prev)return;
    prev.style.display='block';
    var img=prev.querySelector('img');
    if(img)img.src=URL.createObjectURL(input.files[0]);
  });
})();
</script>
<script src="js/admin-variants.js?v=4"></script>
<?php
admin_page_end();