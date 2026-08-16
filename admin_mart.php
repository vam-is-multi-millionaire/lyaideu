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
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load mart items.');
}

$catNameById = [];
foreach ($martCatsFlat as $mc) {
    $catNameById[(int)$mc['id']] = $mc['name'];
}

$vendorNameById = [];
foreach ($martVendors as $v) {
    $vendorNameById[(int)$v['id']] = $v['name'];
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function mart_category_options(array $flat, ?int $selected = null): string {
    global $ce;
    $html = '<option value="0">— No category —</option>';
    foreach ($flat as $mc) {
        $sel = $selected !== null && (int)$mc['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . (int)$mc['id'] . '"' . $sel . '>' . str_repeat('— ', (int)$mc['depth']) . $ce($mc['name']) . '</option>';
    }
    return $html;
}

function mart_vendor_options(array $vendors, ?int $selected = null): string {
    $html = '';
    foreach ($vendors as $v) {
        $sel = $selected !== null && (int)$v['id'] === (int)$selected ? ' selected' : '';
        $html .= '<option value="' . (int)$v['id'] . '"' . $sel . '>' . htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function mart_thumb_html(string $img): string {
    if ($img !== '') {
        return '<span class="pm-thumb"><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    return '<span class="pm-thumb pm-thumb-empty"><i class="fa-solid fa-basket-shopping"></i></span>';
}

admin_page_start('Mart', 'mart', 'Mart');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Manage your grocery items. Use <strong>Edit</strong> to change an item, <strong>Delete</strong> to remove one, and <strong>Add New Item</strong> to create one. Store cards &amp; vendor logins live under <strong>Stores &amp; Vendors</strong>.</p>
        <span class="admin-count-badge"><?= count($items) ?> items</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="martSearch" placeholder="Search items by name, vendor, category or tag…" aria-label="Search mart items">
    <button type="button" class="btn btn-primary" id="addItemBtn"><i class="fa-solid fa-plus"></i> Add New Item</button>
</div>

<div class="pm-add-panel" id="addItemPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Item</h3>
    <form action="admin_save" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="section" value="mart">
        <div class="admin-field-row">
            <div><label>Item Name</label><input type="text" name="new_mart[name]" placeholder="e.g. Garlic" required></div>
            <div><label>Vendor</label><select name="new_mart[vendor_id]"><?= mart_vendor_options($martVendors) ?></select></div>
        </div>
        <div class="admin-field-row">
            <div><label>Category</label><select name="new_mart[category_id]"><?= mart_category_options($martCatsFlat) ?></select></div>
            <div><label>Unit</label><input type="text" name="new_mart[unit]" placeholder="kg / litre / pack"></div>
        </div>
        <div class="admin-field-row">
            <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="new_mart[price]" placeholder="60"></div>
            <div><label>Tag</label><input type="text" name="new_mart[tag]" placeholder="e.g. New!"></div>
        </div>
        <label>Image <span style="text-transform:none;font-weight:700;">(optional — a category icon is shown if empty)</span></label>
        <div class="pm-img-field">
            <input type="file" name="new_mart[img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        </div>
        <label>Description</label>
        <textarea name="new_mart[desc]" placeholder="Short description..."></textarea>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Item</button>
            <button type="button" class="btn btn-outline" id="addItemCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="martList">
        <?php foreach ($items as $i => $m): $id = (int)$m['id']; ?>
        <?php $catName = $catNameById[(int)$m['category_id']] ?? ''; $vendorName = $vendorNameById[(int)$m['vendor_id']] ?? ''; ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$m['name'] . ' ' . $vendorName . ' ' . $catName . ' ' . $m['unit'] . ' ' . $m['tag'])) ?>">
            <div class="pm-item">
                <?= mart_thumb_html((string)$m['img']) ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($m['name']) ?></span>
                    <span class="pm-meta"><?= $vendorName !== '' ? $ce($vendorName) : '' ?><?= $vendorName !== '' && $catName !== '' ? ' · ' : '' ?><?= $ce($catName) ?></span>
                </span>
                <span class="pm-price">Rs. <?= (int)$m['price'] ?><?php if ($m['unit'] !== ''): ?><span class="pm-unit"> / <?= $ce($m['unit']) ?></span><?php endif; ?><?php if ($m['tag'] !== ''): ?><span class="pm-tag"><?= $ce($m['tag']) ?></span><?php endif; ?></span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_save" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="section" value="mart">
                        <input type="hidden" name="mart[<?= $i ?>][id]" value="<?= $id ?>">
                        <input type="hidden" name="mart[<?= $i ?>][delete]" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete "' . $m['name'] . '" from the Mart? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="mart">
                <input type="hidden" name="mart[<?= $i ?>][id]" value="<?= $id ?>">
                <input type="hidden" name="mart[<?= $i ?>][img]" value="<?= $ce($m['img']) ?>">
                <div class="admin-field-row">
                    <div><label>Item Name</label><input type="text" name="mart[<?= $i ?>][name]" value="<?= $ce($m['name']) ?>" required></div>
                    <div><label>Vendor</label><select name="mart[<?= $i ?>][vendor_id]"><?= mart_vendor_options($martVendors, (int)$m['vendor_id']) ?></select></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Category</label><select name="mart[<?= $i ?>][category_id]"><?= mart_category_options($martCatsFlat, (int)$m['category_id']) ?></select></div>
                    <div><label>Unit</label><input type="text" name="mart[<?= $i ?>][unit]" value="<?= $ce($m['unit']) ?>" placeholder="kg / litre / pack"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Price (Rs.)</label><input type="number" min="0" step="1" name="mart[<?= $i ?>][price]" value="<?= (int)$m['price'] ?>" required></div>
                    <div><label>Tag</label><input type="text" name="mart[<?= $i ?>][tag]" value="<?= $ce($m['tag']) ?>"></div>
                </div>
                <label>Image <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <div class="pm-img-field">
                    <span class="pm-img-preview"<?= $m['img'] !== '' ? '' : ' style="display:none"' ?>><img src="<?= $ce($m['img']) ?>" alt=""></span>
                    <input type="file" name="mart[<?= $i ?>][img_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                </div>
                <?php if ($m['img'] !== ''): ?>
                <label class="pm-remove-img"><input type="checkbox" name="mart[<?= $i ?>][remove_img]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove image</label>
                <?php endif; ?>
                <label>Description</label>
                <textarea name="mart[<?= $i ?>][desc]"><?= $ce($m['desc']) ?></textarea>
                <div class="pm-edit-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Item</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="martEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No items match your search.</p>
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

  var search=document.getElementById('martSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('martEmpty');
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
<?php
admin_page_end();