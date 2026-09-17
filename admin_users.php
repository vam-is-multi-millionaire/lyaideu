<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('users');
require_once __DIR__ . '/db.php';

lyaideu_ensure_kyc_tables();
try { if (function_exists('lyaideu_ensure_users_block_column')) lyaideu_ensure_users_block_column(); } catch (Throwable $e) {}

function admin_user_password_errors(string $pass, string $confirm, string $name, string $phone): array {
    $errors = [];
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $pass)) {
        $errors[] = 'Password must contain at least 1 capital letter.';
    } elseif (!preg_match('/[0-9]/', $pass)) {
        $errors[] = 'Password must contain at least 1 number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $pass)) {
        $errors[] = 'Password must contain at least 1 symbol (e.g. @, #, $).';
    } else {
        $nameParts = array_filter(explode(' ', strtolower($name)), fn($p) => strlen($p) >= 3);
        $passLow = strtolower($pass);
        foreach ($nameParts as $part) {
            if (strpos($passLow, $part) !== false) {
                $errors[] = 'Password must NOT contain the user\'s name.';
                break;
            }
        }
        if ($phone !== '' && strpos($pass, $phone) !== false) {
            $errors[] = 'Password must NOT contain the user\'s contact number.';
        }
    }
    if ($pass !== $confirm) {
        $errors[] = 'New password and confirm password do not match.';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid token'); }
    if (!admin_can('users') && !admin_is_superadmin()) { http_response_code(403); exit('No permission'); }
    $uid = (int)($_POST['user_id'] ?? 0);
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');
    if ($uid > 0) {
        try {
            $tStmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ? LIMIT 1');
            $tStmt->execute([$uid]);
            $target = $tStmt->fetch();
            if ($target) {
                $tName = (string)($target['name'] ?? '');
                $tPhone = preg_replace('/[^0-9]/', '', (string)($target['phone'] ?? ''));
                $pwErrors = admin_user_password_errors($newPass, $confirmPass, $tName, (string)$tPhone);
                if (empty($pwErrors)) {
                    $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
                    try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity('user.password_reset','user',$uid,['by'=>admin_display_name()]); } catch (Throwable $e) {}
                    try { if (function_exists('lyaideu_remember_forget_all')) lyaideu_remember_forget_all('user', (int)$uid); } catch (Throwable $e) {}
                    header('Location: admin_users?saved=1');
                    exit;
                } else {
                    header('Location: admin_users?error=' . urlencode(implode(' ', $pwErrors)));
                    exit;
                }
            }
        } catch (Throwable $e) {}
    }
    header('Location: admin_users?error=' . urlencode('Could not reset password.'));
    exit;
}

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
                if ($new === 1) {
                    try { if (function_exists('lyaideu_remember_forget_all')) lyaideu_remember_forget_all('user', (int)$uid); } catch (Throwable $e) {}
                }
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
                    <span><?= $ce(lyaideu_np_time($u['created_at'])) ?></span>
                </span>
                <span class="pm-price" style="display:flex;flex-direction:column;gap:.35rem;align-items:flex-end;min-width:110px">
                    <span class="order-status-pill <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'kyc-rejected' : 'kyc-approved' ?>" style="font-size:.72rem"><?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Blocked' : 'Active' ?></span>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" name="toggle_block" value="1" class="btn <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'btn-outline' : 'btn-primary' ?> btn-sm" style="<?= (int)($u['is_blocked'] ?? 0) === 1 ? '' : 'background:#c93a3a;border-color:#c93a3a;box-shadow:0 3px 0 #a02a2a' ?>" onclick="return confirm('<?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Unblock' : 'Block' ?> <?= $ce($u['name']) ?>?')"><i class="fa-solid <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'fa-unlock' : 'fa-ban' ?>"></i> <?= (int)($u['is_blocked'] ?? 0) === 1 ? 'Unblock' : 'Block' ?></button>
                    </form>
                    <details style="width:100%;max-width:230px">
                        <summary class="btn btn-outline btn-sm" style="cursor:pointer;list-style:none;text-align:center"><i class="fa-solid fa-key"></i> Reset password</summary>
                        <form method="POST" style="margin:.45rem 0 0;display:flex;flex-direction:column;gap:.35rem" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <span class="password-wrap" style="display:block">
                                <input type="password" name="new_password" id="apw_new_<?= (int)$u['id'] ?>" placeholder="New password" required minlength="8" autocomplete="new-password" style="width:100%;padding:.5rem 2.6rem .5rem .6rem;border:2px solid var(--orange-200);border-radius:8px;font:inherit;font-size:.85rem;box-sizing:border-box">
                                <button type="button" class="password-toggle" data-target="apw_new_<?= (int)$u['id'] ?>" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </span>
                            <span class="password-wrap" style="display:block">
                                <input type="password" name="confirm_password" id="apw_confirm_<?= (int)$u['id'] ?>" placeholder="Confirm new password" required minlength="8" autocomplete="new-password" style="width:100%;padding:.5rem 2.6rem .5rem .6rem;border:2px solid var(--orange-200);border-radius:8px;font:inherit;font-size:.85rem;box-sizing:border-box">
                                <button type="button" class="password-toggle" data-target="apw_confirm_<?= (int)$u['id'] ?>" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                            </span>
                            <button type="submit" name="reset_password" value="1" class="btn btn-primary btn-sm" onclick="return confirm('Set a new password for <?= $ce($u['name']) ?>? Tell them the new password securely.')"><i class="fa-solid fa-floppy-disk"></i> Save password</button>
                            <span class="password-strength" style="font-size:.7rem">Min 8 chars, 1 capital, 1 number, 1 symbol. Works even for Google accounts.</span>
                        </form>
                    </details>
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
  document.querySelectorAll('.password-toggle').forEach(function(btn){
    btn.addEventListener('click',function(){
      var input=document.getElementById(btn.getAttribute('data-target'));
      if(!input)return;
      var show=input.type==='password';
      input.type=show?'text':'password';
      btn.innerHTML=show?'<i class="fa-solid fa-eye-slash"></i>':'<i class="fa-solid fa-eye"></i>';
    });
  });
})();
</script>
<script src="js/lightbox.js?v=2"></script>
<?php
admin_page_end();