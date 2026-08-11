<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

try {
    $users = $pdo->query(
        'SELECT id, name, email, phone, dob, created_at FROM users ORDER BY created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load users.');
}

admin_page_start('Users', 'users', '👥 Registered Users');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Customer accounts registered through the website. This view is read-only.</p>
        <span class="admin-count-badge"><?= count($users) ?> users</span>
    </div>
    <div class="admin-grid">
        <?php foreach ($users as $u): ?>
        <div class="admin-card">
            <h3><?= htmlspecialchars($u['name']) ?></h3>
            <label>Email</label><input type="text" value="<?= htmlspecialchars($u['email']) ?>" readonly>
            <label>Phone</label><input type="text" value="+977 <?= htmlspecialchars($u['phone']) ?>" readonly>
            <label>Date of Birth</label><input type="text" value="<?= htmlspecialchars($u['dob']) ?>" readonly>
            <label>Joined</label><input type="text" value="<?= htmlspecialchars($u['created_at']) ?>" readonly>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <p class="admin-empty-note">No users registered yet.</p>
        <?php endif; ?>
    </div>
</section>
<?php
admin_page_end();
