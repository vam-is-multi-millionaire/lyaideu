<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_admin_users_tables();

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function acct_redirect(bool $saved, ?string $error = null): void {
    if ($saved) {
        header('Location: admin_account?saved=1');
    } else {
        header('Location: admin_account?error=' . urlencode($error ?? 'Could not save changes.'));
    }
    exit;
}

/* ------------------------- POST handlers ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please reload the admin panel and try again.');
    }

    try {
        if (isset($_POST['save_profile'])) {
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            if ($name === '' || mb_strlen($name) > 100) {
                acct_redirect(false, 'Please enter your display name (max 100 characters).');
            }
            if (!preg_match('/^[a-zA-Z0-9_.\-]{3,40}$/', $username)) {
                acct_redirect(false, 'Username must be 3-40 characters (letters, numbers, dot, dash, underscore).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                acct_redirect(false, 'That email address does not look valid.');
            }
            $dupe = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u AND id <> :id LIMIT 1');
            $dupe->execute([':u' => $username, ':id' => (int)$_SESSION['admin_id']]);
            if ($dupe->fetch()) {
                acct_redirect(false, 'That username is already taken.');
            }
            $upd = $pdo->prepare('UPDATE admin_users SET name = :n, username = :u, email = :e WHERE id = :id');
            $upd->execute([
                ':n' => $name,
                ':u' => $username,
                ':e' => $email !== '' ? $email : null,
                ':id' => (int)$_SESSION['admin_id'],
            ]);
            $_SESSION['admin_name'] = $name;
            acct_redirect(true);
        }

        if (isset($_POST['save_password'])) {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['new_password_confirm'] ?? '');

            $st = $pdo->prepare('SELECT pass_hash FROM admin_users WHERE id = :id LIMIT 1');
            $st->execute([':id' => (int)$_SESSION['admin_id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($current, (string)$row['pass_hash'])) {
                acct_redirect(false, 'Your current password is incorrect.');
            }
            if (strlen($new) < 8) {
                acct_redirect(false, 'New password must be at least 8 characters long.');
            }
            if ($new !== $confirm) {
                acct_redirect(false, 'New passwords do not match.');
            }
            $upd = $pdo->prepare('UPDATE admin_users SET pass_hash = :p WHERE id = :id');
            $upd->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int)$_SESSION['admin_id']]);
            session_regenerate_id(true);
            $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
            acct_redirect(true);
        }
    } catch (Throwable $e) {
        acct_redirect(false, 'Database error - nothing was saved.');
    }
    acct_redirect(false, 'Unknown action.');
}

/* ------------------------- Page data ------------------------- */

$st = $pdo->prepare('SELECT id, username, name, email, role, is_active, created_at, last_login FROM admin_users WHERE id = :id LIMIT 1');
$st->execute([':id' => (int)$_SESSION['admin_id']]);
$me = $st->fetch();
if (!$me || (int)$me['is_active'] !== 1) {
    unset($_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_name']);
    header('Location: admin');
    exit;
}

$roleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'manager' => 'Manager'];
$roleIcons = ['superadmin' => 'fa-crown', 'admin' => 'fa-user-gear', 'manager' => 'fa-user-tie'];

admin_page_start('My Account', 'account', 'My Account');
?>
<style>
.acct-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;align-items:start}
.acct-meta{display:flex;flex-direction:column;gap:.3rem;margin:.2rem 0 .9rem;font-size:.85rem;color:var(--muted);font-weight:700}
.acct-meta span i{color:var(--orange-600);width:1.1rem}
</style>

<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Update your own login details. These credentials are used only for this admin panel — customer accounts are separate.</p>
        <span class="team-role team-role-<?= $ce($me['role']) ?>"><i class="fa-solid <?= $ce($roleIcons[$me['role']] ?? 'fa-user') ?>"></i> <?= $ce($roleLabels[$me['role']] ?? ucfirst($me['role'])) ?></span>
    </div>
</section>

<div class="acct-grid">
    <form class="admin-card settings-card" action="admin_account" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <h3><i class="fa-solid fa-id-badge"></i> Profile &amp; Username</h3>
        <div class="acct-meta">
            <span><i class="fa-solid fa-calendar-plus"></i> Member since <?= $ce(substr((string)$me['created_at'], 0, 16)) ?></span>
            <span><i class="fa-solid fa-clock-rotate-left"></i> Last login: <?= $me['last_login'] !== null ? $ce(substr((string)$me['last_login'], 0, 16)) : 'never' ?></span>
        </div>
        <label>Display Name</label>
        <input type="text" name="name" maxlength="100" value="<?= $ce($me['name']) ?>" required>
        <label>Username</label>
        <input type="text" name="username" minlength="3" maxlength="40" value="<?= $ce($me['username']) ?>" required autocomplete="username">
        <label>Email <span style="text-transform:none;font-weight:700;">(optional)</span></label>
        <input type="email" name="email" value="<?= $ce((string)$me['email']) ?>" placeholder="you@example.com">
        <button type="submit" name="save_profile" value="1" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Profile</button>
    </form>

    <form class="admin-card settings-card" action="admin_account" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <h3><i class="fa-solid fa-key"></i> Change Password</h3>
        <label>Current password</label>
        <div class="password-wrap">
            <input type="password" name="current_password" id="acct_current_password" required autocomplete="current-password" placeholder="Your current password">
            <button type="button" class="password-toggle" data-target="acct_current_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
        </div>
        <label>New password</label>
        <div class="password-wrap">
            <input type="password" name="new_password" id="acct_new_password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
            <button type="button" class="password-toggle" data-target="acct_new_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
        </div>
        <span class="password-strength">Min 8 characters — pick something you don't use anywhere else.</span>
        <label>Confirm new password</label>
        <div class="password-wrap">
            <input type="password" name="new_password_confirm" id="acct_new_confirm" required minlength="8" autocomplete="new-password" placeholder="Repeat the new password">
            <button type="button" class="password-toggle" data-target="acct_new_confirm" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
        </div>
        <button type="submit" name="save_password" value="1" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-shield-halved"></i> Update Password</button>
    </form>
</div>

<script>
(function(){
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
<?php
admin_page_end();
