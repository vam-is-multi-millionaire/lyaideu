<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_kyc_tables();

try {
    $users = $pdo->query(
        'SELECT id, name, email, phone, dob, avatar, address, kyc_status, created_at FROM users ORDER BY created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load users.');
}

$statusLabels = ['none' => 'Not submitted', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$statusClasses = ['none' => 'kyc-none', 'pending' => 'kyc-pending', 'approved' => 'kyc-approved', 'rejected' => 'kyc-rejected'];

admin_page_start('Users', 'users', 'Registered Users');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Customer accounts registered through the website. Approve or reject their KYC from the <a href="admin_kyc"><strong>KYC page</strong></a> — only verified users can place orders.</p>
        <span class="admin-count-badge"><?= count($users) ?> users</span>
    </div>
    <div class="admin-grid">
        <?php foreach ($users as $u):
            $parts = preg_split('/\s+/', trim((string)$u['name']));
            $ini = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            $av = htmlspecialchars((string)$u['avatar'], ENT_QUOTES, 'UTF-8');
            $st = (string)($u['kyc_status'] ?? 'none');
        ?>
        <div class="admin-card">
            <div class="kyc-card-head">
                <div class="avatar-preview"<?= $av !== '' ? ' style="background-image:url(\'' . $av . '\')" data-lightbox="' . $av . '" data-lightbox-caption="' . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= $av === '' ? htmlspecialchars($ini) : '' ?></div>
                <div>
                    <h3><?= htmlspecialchars($u['name']) ?></h3>
                    <span class="order-status-pill <?= $statusClasses[$st] ?? 'kyc-none' ?>"><?= htmlspecialchars($statusLabels[$st] ?? $st) ?></span>
                </div>
            </div>
            <label>Email</label><input type="text" value="<?= htmlspecialchars($u['email']) ?>" readonly>
            <label>Phone</label><input type="text" value="+977 <?= htmlspecialchars($u['phone']) ?>" readonly>
            <label>Date of Birth</label><input type="text" value="<?= htmlspecialchars($u['dob']) ?>" readonly>
            <label>Location</label><input type="text" value="<?= htmlspecialchars((string)$u['address'] ?: '—') ?>" readonly>
            <label>Joined</label><input type="text" value="<?= htmlspecialchars($u['created_at']) ?>" readonly>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <p class="admin-empty-note">No users registered yet.</p>
        <?php endif; ?>
    </div>
</section>
<script src="js/lightbox.js?v=2"></script>
<?php
admin_page_end();