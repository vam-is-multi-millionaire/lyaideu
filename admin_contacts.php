<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

try {
    $contacts = $pdo->query(
        'SELECT id, role, person, phone, note, ico FROM contacts ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load contacts.');
}

admin_page_start('Contacts', 'contacts', 'Service Contacts');
?>
<form action="admin_save" method="POST" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="section" value="contacts">

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Update phone numbers and details for the Contact section on the website.</p>
            <span class="admin-count-badge"><?= count($contacts) ?> contacts</span>
        </div>
        <div class="admin-grid">
            <?php foreach ($contacts as $i => $c): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($c['role']) ?></h3>
                <input type="hidden" name="contacts[<?= $i ?>][id]" value="<?= (int)$c['id'] ?>">
                <label>Role</label><input type="text" name="contacts[<?= $i ?>][role]" value="<?= htmlspecialchars($c['role']) ?>" required>
                <label>Person / Dept</label><input type="text" name="contacts[<?= $i ?>][person]" value="<?= htmlspecialchars($c['person']) ?>">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="contacts[<?= $i ?>][phone]" value="<?= htmlspecialchars($c['phone']) ?>"></div>
                    <div><label>Icon</label><input type="text" name="contacts[<?= $i ?>][ico]" value="<?= htmlspecialchars($c['ico']) ?>" placeholder="fa-phone"></div>
                </div>
                <label>Note</label><input type="text" name="contacts[<?= $i ?>][note]" value="<?= htmlspecialchars($c['note']) ?>">
                <label class="delete-check"><input type="checkbox" name="contacts[<?= $i ?>][delete]" value="1"> <i class="fa-solid fa-trash-can"></i> Delete this contact</label>
            </div>
            <?php endforeach; ?>

            <div class="admin-card admin-add-card">
                <h3><i class="fa-solid fa-plus"></i> Add New Contact</h3>
                <label>Role</label><input type="text" name="new_contact[role]" placeholder="e.g. Complaints">
                <label>Person / Dept</label><input type="text" name="new_contact[person]" placeholder="e.g. Support Team">
                <div class="admin-field-row">
                    <div><label>Phone</label><input type="text" name="new_contact[phone]" placeholder="98XXXXXXXX"></div>
                    <div><label>Icon</label><input type="text" name="new_contact[ico]" placeholder="fa-phone"></div>
                </div>
                <label>Note</label><input type="text" name="new_contact[note]" placeholder="e.g. 7 AM – 10 PM">
            </div>
        </div>
    </section>

    <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Contact Changes</button>
</form>
<?php
admin_page_end();
