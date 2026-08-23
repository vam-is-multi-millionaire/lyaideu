<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('contacts');
require_once __DIR__ . '/db.php';

try {
    $contacts = $pdo->query(
        'SELECT id, role, person, phone, note, ico FROM contacts ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load contacts.');
}

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function contact_thumb_html(string $ico): string {
    $icon = preg_replace('/[^a-z0-9-]/', '', $ico);
    if ($icon === '') {
        $icon = 'fa-phone';
    }
    return '<span class="pm-thumb pm-thumb-empty"><i class="fa-solid ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>';
}

admin_page_start('Contacts', 'contacts', 'Service Contacts');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Update phone numbers and details shown in the Contact section on the website. Use <strong>Edit</strong> to change a contact, <strong>Delete</strong> to remove one, and <strong>Add New Contact</strong> to create one.</p>
        <span class="admin-count-badge"><?= count($contacts) ?> contacts</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="contactSearch" placeholder="Search contacts by role, person, phone or note…" aria-label="Search contacts">
    <button type="button" class="btn btn-primary" id="addContactBtn"><i class="fa-solid fa-plus"></i> Add New Contact</button>
</div>

<div class="pm-add-panel" id="addContactPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add New Contact</h3>
    <form action="admin_save" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="section" value="contacts">
        <div class="admin-field-row">
            <div><label>Role</label><input type="text" name="new_contact[role]" placeholder="e.g. Complaints" required></div>
            <div><label>Person / Dept</label><input type="text" name="new_contact[person]" placeholder="e.g. Support Team"></div>
        </div>
        <div class="admin-field-row">
            <div><label>Phone</label><input type="text" name="new_contact[phone]" placeholder="98XXXXXXXX"></div>
            <div><label>Icon</label><input type="text" name="new_contact[ico]" placeholder="fa-phone"></div>
        </div>
        <label>Note</label><input type="text" name="new_contact[note]" placeholder="e.g. 7 AM – 10 PM">
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Contact</button>
            <button type="button" class="btn btn-outline" id="addContactCancel">Cancel</button>
        </div>
    </form>
</div>

<section class="admin-section">
    <div class="pm-list" id="contactList">
        <?php foreach ($contacts as $i => $c): $id = (int)$c['id']; ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$c['role'] . ' ' . $c['person'] . ' ' . $c['phone'] . ' ' . $c['note'])) ?>">
            <div class="pm-item">
                <?= contact_thumb_html((string)$c['ico']) ?>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($c['role']) ?></span>
                    <span class="pm-meta"><?= $ce($c['person']) ?><?= $c['person'] !== '' ? ' · ' : '' ?><?= $ce($c['phone']) ?></span>
                </span>
                <span class="pm-price pm-vendor-row"><?= $ce($c['note']) ?></span>
                <span class="pm-actions">
                    <button type="button" class="pm-act pm-edit" data-target="pm-edit-<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <form class="pm-del-inline" action="admin_save" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="section" value="contacts">
                        <input type="hidden" name="contacts[<?= $i ?>][id]" value="<?= $id ?>">
                        <input type="hidden" name="contacts[<?= $i ?>][delete]" value="1">
                        <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete the "' . $c['role'] . '" contact? This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                    </form>
                </span>
            </div>

            <form class="pm-quick-edit" id="pm-edit-<?= $id ?>" action="admin_save" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                <input type="hidden" name="section" value="contacts">
                <input type="hidden" name="contacts[<?= $i ?>][id]" value="<?= $id ?>">
                <div class="admin-field-row">
                    <div><label>Role</label><input type="text" name="contacts[<?= $i ?>][role]" value="<?= $ce($c['role']) ?>" required></div>
                    <div><label>Person / Dept</label><input type="text" name="contacts[<?= $i ?>][person]" value="<?= $ce($c['person']) ?>"></div>
                </div>
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="contacts[<?= $i ?>][phone]" value="<?= $ce($c['phone']) ?>"></div>
                    <div><label>Icon</label><input type="text" name="contacts[<?= $i ?>][ico]" value="<?= $ce($c['ico']) ?>" placeholder="fa-phone"></div>
                </div>
                <label>Note</label><input type="text" name="contacts[<?= $i ?>][note]" value="<?= $ce($c['note']) ?>">
                <div class="pm-edit-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Update Contact</button>
                    <button type="button" class="btn btn-outline pm-cancel">Cancel</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="pm-empty" id="contactEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No contacts match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addContactBtn'),addPanel=document.getElementById('addContactPanel'),addCancel=document.getElementById('addContactCancel');
  function setAdd(open){
    if(!addPanel)return;
    addPanel.style.display=open?'block':'none';
    if(addBtn)addBtn.classList.toggle('active',open);
  }
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none');});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('contactSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('contactEmpty');
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
<?php
admin_page_end();