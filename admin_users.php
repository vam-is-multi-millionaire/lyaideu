<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('users');
require_once __DIR__ . '/db.php';

lyaideu_ensure_kyc_tables();
try { if (function_exists('lyaideu_ensure_users_block_column')) lyaideu_ensure_users_block_column(); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid token'); }
    if (!admin_can('users') && !admin_is_superadmin()) { http_response_code(403); exit('No permission'); }
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0) {
        try {
            $st = $pdo->prepare('SELECT is_blocked FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $cur = $st->fetchColumn();
            if ($cur !== false) {
                $new = ((int)$cur === 1) ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_blocked = ? WHERE id = ?')->execute([$new, $uid]);
                try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity($new ? 'user.block' : 'user.unblock','user',$uid,['by'=>admin_display_name()]); } catch (Throwable $e) {}
                header('Location: admin_users?' . ($new ? 'saved=1' : 'saved=1'));
                exit;
            }
        } catch (Throwable $e) {}
    }
    header('Location: admin_users?error=' . urlencode('Could not update user.'));
    exit;
}

try {
    $users = $pdo->query(
        'SELECT id, name, email, phone, dob, avatar, address, kyc_status, is_blocked, created_at FROM users ORDER BY created_at DESC'
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
                <span class="pm-price" style="display:flex;flex-direction:column;gap:.35rem;align-items:flex-end;min-width:110px">
                    <span class="order-status-pill <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'kyc-rejected' : 'kyc-approved' ?>" style="font-size:.72rem"><?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Blocked' : 'Active' ?></span>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" name="toggle_block" value="1" class="btn <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'btn-outline' : 'btn-primary' ?> btn-sm" style="<?= (int)($u['is_blocked'] ?? 0) === 1 ? '' : 'background:#c93a3a;border-color:#c93a3a;box-shadow:0 3px 0 #a02a2a' ?>" onclick="return confirm('<?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Unblock' : 'Block' ?> <?= $ce($u['name']) ?>?')"><i class="fa-solid <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'fa-unlock' : 'fa-ban' ?>"></i> <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Unblock' : 'Block' ?></button>
                    </form>
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