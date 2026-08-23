<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('hotels');
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();
lyaideu_ensure_stores();

$storeKinds = ['hotel' => 'Hotel / Restaurant', 'mart' => 'Mart', 'other' => 'Other business', 'beverage' => 'Beverages'];
$kindIcons = ['hotel' => 'fa-utensils', 'mart' => 'fa-basket-shopping', 'other' => 'fa-gift', 'beverage' => 'fa-champagne-glasses'];

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

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function store_kind_options(array $kinds, ?string $selected = null): string {
    $html = '';
    foreach ($kinds as $sk => $skLabel) {
        $sel = $selected !== null && $selected === $sk ? ' selected' : '';
        $html .= '<option value="' . $sk . '"' . $sel . '>' . htmlspecialchars((string)$skLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function store_thumb_html(array $h, array $kindIcons): string {
    if (($h['logo'] ?? '') !== '') {
        return '<span class="pm-thumb"><img src="' . htmlspecialchars((string)$h['logo'], ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    $icon = preg_replace('/[^a-z0-9-]/', '', (string)($h['emoji'] ?? ''));
    if ($icon === '') {
        $icon = $kindIcons[$h['kind'] ?? 'hotel'] ?? 'fa-utensils';
    }
    return '<span class="pm-thumb pm-thumb-empty"><i class="fa-solid ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>';
}

admin_page_start('Stores & Vendors', 'stores', 'Stores & Vendors');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Manage partner stores — hotels, the Mart and other businesses — and their vendor login accounts. Every store gets a vendor automatically; the vendor logs in at <strong>/vendor</strong> to manage products and confirm orders.</p>
        <span class="admin-count-badge"><?= count($stores) ?> stores</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="storeSearch" placeholder="Search stores or vendors…" aria-label="Search stores">
    <button type="button" class="btn btn-primary" id="addStoreBtn"><i class="fa-solid fa-plus"></i> Add New Store</button>
</div>

<div class="pm-add-panel" id="addStorePanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Store</h3>
    <form action="admin_save" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="section" value="hotels">
        <div class="admin-field-row">
            <div><label>Store Name</label><input type="text" name="new_hotel[name]" placeholder="e.g. Spice Garden" required></div>
            <div><label>Kind of business</label><select name="new_hotel[kind]"><?= store_kind_options($storeKinds) ?></select></div>
        </div>
        <label>Type / Location</label><input type="text" name="new_hotel[type]" placeholder="e.g. Indian · Pokhara Rd">
        <div class="admin-field-row">
            <div><label>Phone</label><input type="text" name="new_hotel[phone]" placeholder="98XXXXXXXX"></div>
            <div><label>Icon class</label><input type="text" name="new_hotel[emoji]" placeholder="fa-basket-shopping"></div>
        </div>
        <label>Logo <span style="text-transform:none;font-weight:700;">(optional — icon shown if empty)</span></label>
        <div class="pm-img-field">
            <input type="file" name="new_hotel[logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        </div>
        <div class="pm-vendor-block">
            <h4 class="pm-vendor-title"><i class="fa-solid fa-user-tie"></i> Vendor Account <span class="small-note">created automatically with the default password <code>vendor123</code> unless set below</span></h4>
            <div class="admin-field-row">
                <div><label>Vendor Name</label><input type="text" name="new_hotel[vendor_name]" placeholder="e.g. Spice Garden Kitchen"></div>
                <div><label>Phone</label><input type="text" name="new_hotel[vendor_phone]" placeholder="98XXXXXXXX"></div>
            </div>
            <div class="admin-field-row">
                <div><label>Email</label><input type="email" name="new_hotel[vendor_email]" placeholder="kitchen@example.com"></div>
                <div><label>Password</label><input type="password" name="new_hotel[vendor_password]" autocomplete="new-password"></div>
            </div>
        </div>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Store</button>
            <button type="button" class="btn btn-outline" id="addStoreCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="storeList">
        <?php foreach ($stores as $i => $h): $id = (int)$h['id']; $kind = $h['kind'] ?? 'hotel'; $vendorActive = (bool)($h['vendor_active'] ?? 1); ?>
        <?php $vendorLabel = $storeKinds[$kind] ?? 'Hotel / Restaurant'; ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$h['name'] . ' ' . $h['type'] . ' ' . $h['phone'] . ' ' . ($h['vendor_name'] ?? '') . ' ' . $vendorLabel)) ?>">
            <div class="pm-item">
                <?= store_thumb_html($h, $kindIcons) ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($h['name']) ?><span class="store-kind-badge store-kind-<?= $ce($kind) ?>"><?= $ce($vendorLabel) ?></span></span>
                    <span class="pm-meta"><?= $ce($h['type']) ?><?= $h['type'] !== '' && $h['phone'] !== '' ? ' · ' : '' ?><?= $ce($h['phone']) ?></span>
                </span>
                <span class="pm-price pm-vendor-row">
                    <span class="store-vendor-pill<?= $vendorActive ? ' is-active' : '' ?>"><i class="fa-solid fa-user-tie"></i> <?= $vendorActive ? 'Vendor active' : 'Vendor off' ?></span>
                </span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_save" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="section" value="hotels">
                        <input type="hidden" name="hotels[<?= $i ?>][id]" value="<?= $id ?>">
                        <input type="hidden" name="hotels[<?= $i ?>][vendor_id]" value="<?= (int)($h['vendor_id'] ?? 0) ?>">
                        <input type="hidden" name="hotels[<?= $i ?>][delete]" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete "' . $h['name'] . '" and its vendor account? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="hotels">
                <input type="hidden" name="hotels[<?= $i ?>][id]" value="<?= $id ?>">
                <input type="hidden" name="hotels[<?= $i ?>][vendor_id]" value="<?= (int)($h['vendor_id'] ?? 0) ?>">
                <input type="hidden" name="hotels[<?= $i ?>][logo]" value="<?= $ce($h['logo'] ?? '') ?>">
                <div class="admin-field-row">
                    <div><label>Store Name</label><input type="text" name="hotels[<?= $i ?>][name]" value="<?= $ce($h['name']) ?>" required></div>
                    <div><label>Kind of business</label><select name="hotels[<?= $i ?>][kind]"><?= store_kind_options($storeKinds, $kind) ?></select></div>
                </div>
                <label>Type / Location</label><input type="text" name="hotels[<?= $i ?>][type]" value="<?= $ce($h['type']) ?>">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="hotels[<?= $i ?>][phone]" value="<?= $ce($h['phone']) ?>"></div>
                    <div><label>Icon class</label><input type="text" name="hotels[<?= $i ?>][emoji]" value="<?= $ce($h['emoji'] ?? '') ?>" placeholder="fa-basket-shopping"></div>
                </div>
                <label>Logo <span style="text-transform:none;font-weight:700;">(upload — optional)</span></label>
                <div class="pm-img-field">
                    <span class="pm-img-preview"<?= ($h['logo'] ?? '') !== '' ? '' : ' style="display:none"' ?>><img src="<?= $ce($h['logo'] ?? '') ?>" alt=""></span>
                    <input type="file" name="hotels[<?= $i ?>][logo_file]" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                </div>
                <?php if (($h['logo'] ?? '') !== ''): ?>
                <label class="pm-remove-img"><input type="checkbox" name="hotels[<?= $i ?>][remove_logo]" value="1"> <i class="fa-solid fa-trash-can"></i> Remove logo</label>
                <?php endif; ?>
                <div class="pm-vendor-block">
                    <h4 class="pm-vendor-title"><i class="fa-solid fa-user-tie"></i> Vendor Account <span class="small-note">logs in at /vendor to manage products &amp; confirm orders</span></h4>
                    <div class="admin-field-row">
                        <div><label>Vendor Name</label><input type="text" name="hotels[<?= $i ?>][vendor_name]" value="<?= $ce($h['vendor_name'] ?? '') ?>" placeholder="<?= $ce($h['name']) ?>"></div>
                        <div><label>Phone</label><input type="text" name="hotels[<?= $i ?>][vendor_phone]" value="<?= $ce($h['vendor_phone'] ?? '') ?>"></div>
                    </div>
                    <div class="admin-field-row">
                        <div><label>Email</label><input type="email" name="hotels[<?= $i ?>][vendor_email]" value="<?= $ce($h['vendor_email'] ?? '') ?>"></div>
                        <div><label>New Password <span class="small-note">(blank keeps current)</span></label><input type="password" name="hotels[<?= $i ?>][vendor_password]" autocomplete="new-password"></div>
                    </div>
                    <label class="pm-remove-img" style="color:var(--orange-800)!important;"><input type="checkbox" name="hotels[<?= $i ?>][vendor_active]" value="1" <?= $vendorActive ? 'checked' : '' ?>> <i class="fa-solid fa-circle-check"></i> Vendor account active</label>
                </div>
                <div class="pm-edit-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Store</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="storeEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No stores match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addStoreBtn'),addPanel=document.getElementById('addStorePanel'),addCancel=document.getElementById('addStoreCancel');
  function setAdd(open){
    if(!addPanel)return;
    addPanel.style.display=open?'block':'none';
    if(addBtn)addBtn.classList.toggle('active',open);
  }
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none');});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('storeSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('storeEmpty');
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