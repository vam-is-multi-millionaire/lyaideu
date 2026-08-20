<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_beverage_table();
lyaideu_ensure_categories_table();
lyaideu_ensure_stores();
lyaideu_ensure_variant_tables();
$beverageCatsFlat = lyaideu_categories_flat('beverage');

try {
    $items = $pdo->query(
        'SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, vendor_id, has_variants FROM beverage_items ORDER BY id'
    )->fetchAll();
    $beverageVendors = $pdo->query("SELECT id, name FROM vendors WHERE scope = 'beverage' AND is_active = 1 ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load beverage items.');
}

$catNameById = [];
foreach ($beverageCatsFlat as $mc) {
    $catNameById[(int)$mc['id']] = $mc['name'];
}

$vendorNameById = [];
foreach ($beverageVendors as $v) {
    $vendorNameById[(int)$v['id']] = $v['name'];
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function beverage_category_options(array $flat, ?int $selected = null): string {
    global $ce;
    $html = '<option value="0">— No category —</option>';
    foreach ($flat as $mc) {
        $sel = $selected !== null && (int)$mc['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . (int)$mc['id'] . '"' . $sel . '>' . str_repeat('— ', (int)$mc['depth']) . $ce($mc['name']) . '</option>';
    }
    return $html;
}

function beverage_vendor_options(array $vendors, ?int $selected = null): string {
    $html = '';
    foreach ($vendors as $v) {
        $sel = $selected !== null && (int)$v['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . (int)$v['id'] . '"' . $sel . '>' . htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function beverage_thumb_html(string $img): string {
    if ($img !== '') {
        return '<span class="pm-thumb"><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    return '<span class="pm-thumb pm-thumb-empty"><i class="fa-solid fa-glass-water"></i></span>';
}

admin_page_start('Beverages', 'beverages', 'Beverages');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Manage your beverage items. Use <strong>Edit</strong> to change an item, <strong>Delete</strong> to remove one, and <strong>Add New Item</strong> to create one. Store cards &amp; vendor logins live under <strong>Stores &amp; Vendors</strong>.</p>
        <span class="admin-count-badge"><?= count($items) ?> items</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="beverageSearch" placeholder="Search items by name, vendor, category or tag…" aria-label="Search beverage items">
    <button type="button" class="btn btn-primary" id="addItemBtn"><i class="fa-solid fa-plus"></i> Add New Item</button>
</div>

<div class="pm-add-panel" id="addItemPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Item</h3>
    <form action="admin_save" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="section" value="beverages">
        <div class="admin-field-row">
            <div><label>Item Name</label><input type="text" name="new_beverages[name]" placeholder="e.g. Coca-Cola 500ml" required></div>
            <div><label>Vendor</label><select name="new_beverages[vendor_id]"><?= beverage_vendor_options($beverageVendors) ?></select></div>
        </div>
        <div class="admin-field-row">
            <div><label>Category</label><select name="new_beverages[category_id]"><?= beverage_category_options($beverageCatsFlat) ?></select></div>
            <div><label>Unit</label><input type="text" name="new_beverages[unit]" placeholder="500ml / bottle / can"></div>
        </div>
        <div class="admin-field-row">
            <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_beverages[price]" placeholder="80"></div>
            <div><label>Tag</label><input type="text" name="new_beverages[tag]" placeholder="e.g. Chilled!"></div>
        </div>
        <label>Image <span style="text-transform:none;font-weight:700;">(optional — a category icon is shown if empty)</span></label>
        <div class="pm-img-field">
            <input type="file" name="new_beverages[img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        </div>
        <label>Description</label>
        <textarea name="new_beverages[desc]" placeholder="Short description..."></textarea>
        <?= lyaideu_variants_editor_html('new_beverages') ?>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Item</button>
            <button type="button" class="btn btn-outline" id="addItemCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="beverageList">
        <?php foreach ($items as $i => $m): $id = (int)$m['id']; ?>
        <?php $catName = $catNameById[(int)$m['category_id']] ?? ''; $vendorName = $vendorNameById[(int)$m['vendor_id']] ?? ''; ?>
        <?php $itemVariants = lyaideu_item_variants('beverage', $id); ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$m['name'] . ' ' . $vendorName . ' ' . $catName . ' ' . $m['unit'] . ' ' . $m['tag'])) ?>">
            <div class="pm-item">
                <?= beverage_thumb_html((string)$m['img']) ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($m['name']) ?></span>
                    <span class="pm-meta"><?= $vendorName !== '' ? $ce($vendorName) : '' ?><?= $vendorName !== '' && $catName !== '' ? ' · ' : '' ?><?= $ce($catName) ?></span>
                </span>
                <span class="pm-price">Rs. <?= (int)$m['price'] ?><?php if ($m['unit'] !== ''): ?><span class="pm-unit"> / <?= $ce($m['unit']) ?></span><?php endif; ?><?php if ($m['tag'] !== ''): ?><span class="pm-tag"><?= $ce($m['tag']) ?></span><?php endif; ?></span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_save" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="section" value="beverages">
                        <input type="hidden" name="beverages[<?= $i ?>][id]" value="<?= $id ?>">
                        <input type="hidden" name="beverages[<?= $i ?>][delete]" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete "' . $m['name'] . '" from the Beverages? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="beverages">
                <input type="hidden" name="beverages[<?= $i ?>][id]" value="<?= $id ?>">
                <input type="hidden" name="beverages[<?= $i ?>][img]" value="<?= $ce($m['img']) ?>">
                <div class="admin-field-row">
                    <div><label>Item Name</label><input type="text" name="beverages[<?= $i ?>][name]" value="<?= $ce($m['name']) ?>" required></div>
                    <div><label>Vendor</label><select name="beverages[<?= $i ?>][vendor_id]"><?= beverage_vendor_options($beverageVendors, (int)$m['vendor_id']) ?></select></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Category</label><select name="beverages[<?= $i ?>][category_id]"><?= beverage_category_options($beverageCatsFlat, (int)$m['category_id']) ?></select></div>
                    <div><label>Unit</label><input type="text" name="beverages[<?= $i ?>][unit]" value="<?= $ce($m['unit']) ?>" placeholder="500ml / bottle / can"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="beverages[<?= $i ?>][price]" value="<?= (int)$m['price'] ?>" required></div>
                    <div><label>Tag</label><input type="text" name="beverages[<?= $i ?>][tag]" value="<?= $ce($m['tag']) ?>"></div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <div class="pm-img-field">
                    <span class="pm-img-preview"<?= $m['img'] !== '' ? '' : ' style="display:none"' ?>><img src="<?= $ce($m['img']) ?>" alt=""></span>
                    <input type="file" name="beverages[<?= $i ?>][img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                </div>
                <?php if ($m['img'] !== ''): ?>
                <label class="pm-remove-img"><input type="checkbox" name="beverages[<?= $i ?>][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                <?php endif; ?>
                <label>Description</label>
                <textarea name="beverages[<?= $i ?>][desc]"><?= $ce($m['desc']) ?></textarea>
                <?= lyaideu_variants_editor_html('beverages[' . $i . ']', $itemVariants, (bool)$m['has_variants']) ?>
                <div class="pm-edit-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Item</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="beverageEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No items match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addItemBtn'),addPanel=document.getElementById('addItemPanel'),addCancel=document.getElementById('addItemCancel');
  function setAdd(open){
    if(!addPanel)return;
    addPanel.style.display=open?'block':'none';
    if(addBtn)addBtn.classList.toggle('active',open);
  }
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none');});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('beverageSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('beverageEmpty');
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
<script src="js/admin-variants.js?v=2"></script>
<?php
admin_page_end();