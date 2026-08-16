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

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

admin_page_start('Users', 'users', 'Registered Users');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Customer accounts registered through the website. Approve or reject their KYC from the <a href="admin_kyc"><strong>KYC page</strong></a> — only verified users can place orders.</p>
        <span class="admin-count-badge"><?= count($users) ?> users</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="userSearch" placeholder="Search users by name, email or phone…" aria-label="Search users">
</div>

<section class="admin-section">
    <div class="pm-list" id="userList">
        <?php foreach ($users as $u):
            $parts = preg_split('/\s+/', trim((string)$u['name']));
            $ini = strtoupper(substr((string)$parts[0], 0, 1) . (isset($parts[1]) ? substr((string)$parts[1], 0, 1) : ''));
            $av = $ce((string)$u['avatar']);
            $st = (string)($u['kyc_status'] ?? 'none');
            $cl = $statusClasses[$st] ?? 'kyc-none';
            $lb = $av !== '' ? ' data-lightbox="' . $av . '" data-lightbox-caption="' . $ce($u['name']) . '"' : '';
        ?>
        <div class="pm-row" data-search="<?= $ce(strtolower((string)$u['name'] . ' ' . $u['email'] . ' ' . $u['phone'] . ' ' . $u['address'])) ?>">
            <div class="pm-item">
                <span class="pm-thumb pm-avatar"<?= $lb ?> style="<?= $av !== '' ? "background-image:url('$av')" : '' ?>"><?= $av === '' ? $ce($ini) : '' ?></span>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($u['name']) ?></span>
                    <span class="pm-meta"><?= $ce($u['email']) ?> · +977 <?= $ce($u['phone']) ?></span>
                </span>
                <span class="pm-price pm-vendor-row">
                    <span class="order-status-pill <?= $ce($cl) ?>"><?= $ce($statusLabels[$st] ?? $st) ?></span>
                </span>
                <span class="pm-user-meta pm-meta">
                    <span><?= $ce($u['dob']) ?></span>
                    <span><?= $ce((string)$u['address'] ?: '—') ?></span>
                    <span><?= $ce($u['created_at']) ?></span>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <p class="pm-empty" style="display:block"><i class="fa-solid fa-users"></i> No users registered yet.</p>
        <?php endif; ?>
    </div>
    <p class="pm-empty" id="userEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No users match your search.</p>
</section>

<script>
(function(){
  var search=document.getElementById('userSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('.pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('userEmpty');
      if(empty)empty.style.display=any?'none':'block';
    });
  }
})();
</script>
<script src="js/lightbox.js?v=2"></script>
<?php
admin_page_end();