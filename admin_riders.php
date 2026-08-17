<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();

$errors = [];
$saved = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    if (isset($_POST['rider_save'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $vehicle = trim((string)($_POST['vehicle'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Rider name is required.';
        }
        if ($phone === '') {
            $errors[] = 'Rider phone is required.';
        }
        if ($id === 0 && strlen($pass) < 6) {
            $errors[] = 'Password must be at least 6 characters for a new rider.';
        }

        if (!$errors) {
            $conflict = lyaideu_delivery_credential_conflict('rider', $phone, $email, $id);
            if ($conflict) {
                $errors[] = $conflict;
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    if ($pass !== '') {
                        $st = $pdo->prepare('UPDATE riders SET name = ?, email = ?, phone = ?, vehicle = ?, pass = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, $vehicle, password_hash($pass, PASSWORD_DEFAULT), $isActive, $id]);
                    } else {
                        $st = $pdo->prepare('UPDATE riders SET name = ?, email = ?, phone = ?, vehicle = ?, is_active = ? WHERE id = ?');
                        $st->execute([$name, $email, $phone, $vehicle, $isActive, $id]);
                    }
                } else {
                    $st = $pdo->prepare('INSERT INTO riders (name, email, phone, vehicle, pass, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $st->execute([$name, $email, $phone, $vehicle, password_hash($pass, PASSWORD_DEFAULT), $isActive, date('Y-m-d H:i:s')]);
                }
                header('Location: admin_riders?saved=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Could not save rider. ' . (str_contains(strtolower($e->getMessage()), 'duplicate') ? 'A rider with this phone or email may already exist.' : '');
            }
        }
    }

    if (isset($_POST['rider_delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare('SELECT avatar FROM riders WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $oldAvatar = (string)$st->fetchColumn();
            $pdo->prepare('DELETE FROM riders WHERE id = ?')->execute([$id]);
            if ($oldAvatar !== '') {
                lyaideu_delete_upload($oldAvatar);
            }
            header('Location: admin_riders?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not delete rider.';
        }
    }
}

$riders = [];
try {
    $riders = $pdo->query('SELECT id, name, email, phone, vehicle, avatar, is_active, created_at FROM riders ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $riders = [];
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

admin_page_start('Riders', 'riders', 'Rider Management');
?>
<?php if ($saved): ?><div class="flash-banner flash-success admin-flash"><i class="fa-solid fa-circle-check"></i> Changes saved successfully.</div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="flash-banner flash-error admin-flash"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Delivery riders who pick up and deliver orders. Use <strong>Edit</strong> to update a rider, <strong>Delete</strong> to remove one, and <strong>Add New Rider</strong> to create one. Riders log in at <strong>/rider</strong>.</p>
        <span class="admin-count-badge"><?= count($riders) ?> riders</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="riderSearch" placeholder="Search riders by name, phone, email or vehicle…" aria-label="Search riders">
    <button type="button" class="btn btn-primary" id="addRiderBtn"><i class="fa-solid fa-plus"></i> Add New Rider</button>
</div>

<div class="pm-add-panel" id="addRiderPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Rider</h3>
    <form action="admin_riders" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <div class="admin-field-row">
            <div><label>Rider Name</label><input type="text" name="name" placeholder="e.g. Bikash Rai" required></div>
            <div><label>Phone</label><input type="text" name="phone" placeholder="98XXXXXXXX" required></div>
        </div>
        <div class="admin-field-row">
            <div><label>Vehicle</label><input type="text" name="vehicle" placeholder="Bike / Scooter"></div>
            <div><label>Email</label><input type="email" name="email" placeholder="rider@example.com"></div>
        </div>
        <label>Password <span style="text-transform:none;font-weight:700;">(min 6 characters)</span></label>
        <input type="password" name="password" placeholder="Set a password">
        <label class="pm-remove-img" style="color:var(--orange-800)!important;"><input type="checkbox" name="is_active" value="1" checked> <i class="fa-solid fa-circle-check"></i> Active</label>
        <div class="pm-add-actions">
            <button type="submit" name="rider_save" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Rider</button>
            <button type="button" class="btn btn-outline" id="addRiderCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="riderList">
        <?php foreach ($riders as $r): $id = (int)$r['id']; $active = (bool)$r['is_active'];
            $av = $ce((string)($r['avatar'] ?? ''));
        ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$r['name'] . ' ' . $r['phone'] . ' ' . $r['email'] . ' ' . $r['vehicle'])) ?>">
            <div class="pm-item">
                <?php if ($av !== ''): ?><span class="pm-thumb pm-avatar" data-lightbox="<?= $av ?>" data-lightbox-caption="<?= $ce($r['name']) ?>" style="background-image:url('<?= $av ?>')"></span>
                <?php else: ?><span class="pm-thumb pm-thumb-empty"><i class="fa-solid fa-motorcycle"></i></span><?php endif; ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($r['name']) ?></span>
                    <span class="pm-meta"><?= $ce($r['phone']) ?><?= $r['vehicle'] !== '' ? ' · ' . $ce($r['vehicle']) : '' ?></span>
                </span>
                <span class="pm-price pm-vendor-row">
                    <span class="pm-status-pill<?= $active ? ' is-active' : '' ?>"><i class="fa-solid fa-user-tie"></i> <?= $active ? 'Active' : 'Off' ?></span>
                </span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_riders" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="rider_delete" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete rider "' . $r['name'] . '"? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_riders" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="admin-field-row">
                    <div><label>Rider Name</label><input type="text" name="name" value="<?= $ce($r['name']) ?>" required></div>
                    <div><label>Phone</label><input type="text" name="phone" value="<?= $ce($r['phone']) ?>" required></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Vehicle</label><input type="text" name="vehicle" value="<?= $ce($r['vehicle']) ?>" placeholder="e.g. Bike"></div>
                    <div><label>Email</label><input type="email" name="email" value="<?= $ce($r['email']) ?>"></div>
                </div>
                <label>New Password <span style="text-transform:none;font-weight:700;">(leave blank to keep current)</span></label>
                <input type="password" name="password" autocomplete="new-password">
                <label class="pm-remove-img" style="color:var(--orange-800)!important;"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> <i class="fa-solid fa-circle-check"></i> Active</label>
                <div class="pm-edit-actions">
                    <button type="submit" name="rider_save" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Rider</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="riderEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No riders match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addRiderBtn'),addPanel=document.getElementById('addRiderPanel'),addCancel=document.getElementById('addRiderCancel');
  function setAdd(open){
    if(!addPanel)return;
    addPanel.style.display=open?'block':'none';
    if(addBtn)addBtn.classList.toggle('active',open);
  }
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none');});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('riderSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('riderEmpty');
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
})();
</script>
<script src="js/lightbox.js?v=2"></script>
<?php
admin_page_end();