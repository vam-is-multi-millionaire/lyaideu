<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('team');
require_once __DIR__ . '/db.php';

lyaideu_ensure_admin_users_tables();

$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function team_page_groups(): array {
    return [
        'Catalog & Content' => ['categories', 'dishes', 'mart', 'beverages', 'others', 'sections'],
        'Storefront Control' => ['control', 'promos'],
        'Operations' => ['orders', 'hotels', 'riders', 'messages', 'contacts'],
        'People' => ['users', 'kyc'],
        'System' => ['activity', 'settings'],
    ];
}

function team_redirect(bool $saved, ?string $error = null): void {
    if ($saved) {
        header('Location: admin_team?saved=1');
    } else {
        header('Location: admin_team?error=' . urlencode($error ?? 'Could not save changes.'));
    }
    exit;
}

/** Number of ACTIVE superadmin accounts, excluding $excludeId. */
function team_active_super_count(PDO $pdo, int $excludeId = 0): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin' AND is_active = 1 AND id <> :x");
    $st->execute([':x' => $excludeId]);
    return (int)$st->fetchColumn();
}

/** Checkbox grid of grantable admin pages, grouped by area. */
function team_pages_grid(string $inputName, array $selected): string {
    $navItems = admin_nav_items();
    $html = '<div class="team-pages-grid">';
    foreach (team_page_groups() as $groupLabel => $keys) {
        $html .= '<div class="team-page-group"><h4>' . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . '</h4>';
        foreach ($keys as $key) {
            $item = $navItems[$key];
            $fieldId = $inputName . '-' . $key;
            $html .= '<label class="team-page-check" for="' . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="checkbox" id="' . htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8') . '[]" value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . (in_array($key, $selected, true) ? ' checked' : '') . '>'
                . '<span class="tpc-icon">' . $item['icon'] . '</span><span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></label>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/* ------------------------- POST handlers ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please reload the admin panel and try again.');
    }
    if (!admin_is_superadmin()) {
        team_redirect(false, 'Only superadmins can manage staff accounts.');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_staff' || $action === 'update_staff') {
            $username = trim((string)($_POST['username'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['password_confirm'] ?? '');
            $role = (string)($_POST['role'] ?? 'manager');
            $pages = array_values(array_filter(
                array_map('strval', (array)($_POST['pages'] ?? [])),
                fn($k) => in_array($k, admin_grantable_page_keys(), true)
            ));
            /* Superadmins implicitly open every page - grants are irrelevant. */
            if ($role === 'superadmin') {
                $pages = [];
            }

            if (!preg_match('/^[a-zA-Z0-9_.\-]{3,40}$/', $username)) {
                team_redirect(false, 'Username must be 3-40 characters (letters, numbers, dot, dash, underscore).');
            }
            if ($name === '' || mb_strlen($name) > 100) {
                team_redirect(false, 'Please enter a display name (max 100 characters).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                team_redirect(false, 'That email address does not look valid.');
            }
            if (!in_array($role, ['superadmin', 'admin', 'manager'], true)) {
                team_redirect(false, 'Please choose a valid role (Super Admin, Admin or Manager).');
            }
            if ($action === 'add_staff') {
                if (strlen($password) < 8) {
                    team_redirect(false, 'Password must be at least 8 characters long.');
                }
                if ($password !== $confirm) {
                    team_redirect(false, 'Passwords do not match.');
                }
            } else {
                if ($password !== '' && strlen($password) < 8) {
                    team_redirect(false, 'New password must be at least 8 characters long (leave blank to keep the current one).');
                }
                if ($password !== $confirm) {
                    team_redirect(false, 'New passwords do not match.');
                }
            }

            if ($action === 'add_staff') {
                $dupe = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
                $dupe->execute([':u' => $username]);
                if ($dupe->fetch()) {
                    team_redirect(false, 'That username is already taken.');
                }
                $ins = $pdo->prepare(
                    'INSERT INTO admin_users (username, name, email, pass_hash, role, is_active, created_at)
                     VALUES (:u, :n, :e, :p, :r, 1, :c)'
                );
                $ins->execute([
                    ':u' => $username,
                    ':n' => $name,
                    ':e' => $email !== '' ? $email : null,
                    ':p' => password_hash($password, PASSWORD_DEFAULT),
                    ':r' => $role,
                    ':c' => date('Y-m-d H:i:s'),
                ]);
                $targetId = (int)$pdo->lastInsertId();
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0 || $id === (int)$_SESSION['admin_id']) {
                    team_redirect(false, 'This account cannot be edited here.');
                }
                $cur = $pdo->prepare('SELECT id, role FROM admin_users WHERE id = :id LIMIT 1');
                $cur->execute([':id' => $id]);
                $row = $cur->fetch();
                if (!$row) {
                    team_redirect(false, 'Staff account not found.');
                }
                if ($row['role'] === 'superadmin' && $role !== 'superadmin'
                    && team_active_super_count($pdo, $id) === 0) {
                    team_redirect(false, 'At least one active Super Admin must remain - promote another Super Admin first.');
                }
                $dupe = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u AND id <> :id LIMIT 1');
                $dupe->execute([':u' => $username, ':id' => $id]);
                if ($dupe->fetch()) {
                    team_redirect(false, 'That username is already taken.');
                }
                if ($password !== '') {
                    $upd = $pdo->prepare('UPDATE admin_users SET username = :u, name = :n, email = :e, role = :r, pass_hash = :p WHERE id = :id');
                    $upd->execute([':u' => $username, ':n' => $name, ':e' => $email !== '' ? $email : null, ':r' => $role, ':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
                } else {
                    $upd = $pdo->prepare('UPDATE admin_users SET username = :u, name = :n, email = :e, role = :r WHERE id = :id');
                    $upd->execute([':u' => $username, ':n' => $name, ':e' => $email !== '' ? $email : null, ':r' => $role, ':id' => $id]);
                }
                $targetId = $id;
            }

            $del = $pdo->prepare('DELETE FROM admin_user_pages WHERE admin_id = :id');
            $del->execute([':id' => $targetId]);
            if ($pages) {
                $insPage = $pdo->prepare('INSERT INTO admin_user_pages (admin_id, page_key) VALUES (:id, :k)');
                foreach ($pages as $pageKey) {
                    $insPage->execute([':id' => $targetId, ':k' => $pageKey]);
                }
            }
            team_redirect(true);
        }

        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || $id === (int)$_SESSION['admin_id']) {
                team_redirect(false, 'You cannot change your own account status.');
            }
            $cur = $pdo->prepare('SELECT id, role, is_active FROM admin_users WHERE id = :id LIMIT 1');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                team_redirect(false, 'Staff account not found.');
            }
            if ($row['role'] === 'superadmin' && (int)$row['is_active'] === 1
                && team_active_super_count($pdo, $id) === 0) {
                team_redirect(false, 'At least one active Super Admin must stay enabled.');
            }
            $pdo->prepare('UPDATE admin_users SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
            team_redirect(true);
        }

        if ($action === 'delete_staff') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || $id === (int)$_SESSION['admin_id']) {
                team_redirect(false, 'You cannot delete your own account.');
            }
            $cur = $pdo->prepare('SELECT id, name, role, is_active FROM admin_users WHERE id = :id LIMIT 1');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                team_redirect(false, 'Staff account not found.');
            }
            if ($row['role'] === 'superadmin' && (int)$row['is_active'] === 1
                && team_active_super_count($pdo, $id) === 0) {
                team_redirect(false, 'At least one active Super Admin must remain.');
            }
            $pdo->prepare('DELETE FROM admin_user_pages WHERE admin_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $id]);
            header('Location: admin_team?deleted=1');
            exit;
        }

        team_redirect(false, 'Unknown action.');
    } catch (Throwable $e) {
        team_redirect(false, 'Database error - the change was not saved.');
    }
}

/* ------------------------- Page data ------------------------- */

try {
    $staff = $pdo->query(
        "SELECT id, username, name, email, pass_hash, role, is_active, created_at, last_login
         FROM admin_users
         ORDER BY FIELD(role, 'superadmin', 'admin', 'manager'), name ASC"
    )->fetchAll();
    $grants = [];
    foreach ($pdo->query('SELECT admin_id, page_key FROM admin_user_pages') as $g) {
        $grants[(int)$g['admin_id']][] = (string)$g['page_key'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load staff accounts.');
}

$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;
foreach ($staff as $s) {
    if ((int)$s['id'] === $editId && $s['role'] !== 'superadmin') {
        $editUser = $s;
        break;
    }
}

$roleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'manager' => 'Manager'];

admin_page_start('Staff & Roles', 'team', 'Staff & Roles');
?>
<style>
.team-role{display:inline-flex;align-items:center;gap:.3rem;font-size:.68rem;font-weight:900;padding:.22rem .6rem;border-radius:999px;margin-left:.5rem;text-transform:uppercase;letter-spacing:.04em}
.team-role-superadmin{background:#3b2413;color:#ffd9a8}
.team-role-admin{background:#dbeafe;color:#1d4ed8}
.team-role-manager{background:#fde9d6;color:#b4530a}
.team-pages-pill{font-size:.72rem;font-weight:800;color:var(--muted);white-space:nowrap}
.pm-user-meta{display:flex;flex-direction:column;gap:.05rem;font-size:.78rem;color:var(--muted);text-align:right}
@media(max-width:760px){.pm-user-meta{display:none}}
.team-pages-grid{display:flex;flex-wrap:wrap;gap:1rem;margin:.4rem 0 .9rem}
.team-page-group{flex:1 1 220px;background:var(--orange-50,#fdf6ee);border:2px solid var(--orange-100);border-radius:14px;padding:.75rem .85rem;min-width:200px}
.team-page-group h4{margin:0 0 .5rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--orange-800)}
.team-page-check{display:flex;align-items:center;gap:.45rem;padding:.28rem .15rem;font-weight:700;font-size:.86rem;cursor:pointer;border-radius:8px}
.team-page-check:hover{background:#fff}
.team-page-check input{width:16px;height:16px;accent-color:var(--orange-600)}
.tpc-icon{color:var(--orange-600);width:1.1rem;display:inline-flex;justify-content:center}
.team-lock{font-size:.74rem;font-weight:800;color:var(--muted);white-space:nowrap}
</style>

<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Create <strong>Super Admin</strong>, <strong>Admin</strong> and <strong>Manager</strong> logins and choose exactly which admin pages each person can open. Super Admins always see everything. Changes take effect the next time that person loads a page.</p>
        <span class="admin-count-badge"><?= count($staff) ?> staff</span>
    </div>
</section>

<div class="pm-toolbar">
    <input type="search" class="wp-cat-search pm-search" id="staffSearch" placeholder="Search staff by name, username or role…" aria-label="Search staff">
    <?php if (!$editUser): ?><button type="button" class="btn btn-primary" id="addStaffBtn"><i class="fa-solid fa-plus"></i> Add Staff</button><?php endif; ?>
</div>

<?php if ($editUser): ?>
<div class="pm-add-panel" style="display:block">
    <h3 class="pm-add-title"><i class="fa-solid fa-user-pen"></i> Edit “<?= $ce($editUser['name']) ?>”</h3>
    <form action="admin_team" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="update_staff">
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <div class="admin-field-row">
            <div><label>Display Name</label><input type="text" name="name" maxlength="100" value="<?= $ce($editUser['name']) ?>" required></div>
            <div><label>Username</label><input type="text" name="username" minlength="3" maxlength="40" value="<?= $ce($editUser['username']) ?>" required></div>
        </div>
        <div class="admin-field-row">
            <div><label>Email <span class="small-note">(optional)</span></label><input type="email" name="email" value="<?= $ce((string)$editUser['email']) ?>"></div>
            <div><label>Role</label><select name="role" class="team-role-select"><option value="manager"<?= $editUser['role'] === 'manager' ? ' selected' : '' ?>>Manager</option><option value="admin"<?= $editUser['role'] === 'admin' ? ' selected' : '' ?>>Admin</option><option value="superadmin"<?= $editUser['role'] === 'superadmin' ? ' selected' : '' ?>>Super Admin</option></select></div>
        </div>
        <div class="admin-field-row">
            <div><label>New Password <span class="small-note">(leave blank to keep current)</span></label><input type="password" name="password" minlength="8" autocomplete="new-password"></div>
            <div><label>Confirm New Password</label><input type="password" name="password_confirm" autocomplete="new-password"></div>
        </div>
        <label style="margin-top:.4rem">Page Access <span class="small-note">(Super Admins open every page)</span></label>
        <div class="team-pages-wrap"<?= $editUser['role'] === 'superadmin' ? ' style="display:none"' : '' ?>>
        <?= team_pages_grid('pages', isset($grants[(int)$editUser['id']]) ? $grants[(int)$editUser['id']] : []) ?>
        </div>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            <a class="btn btn-outline" href="admin_team">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="pm-add-panel" id="addStaffPanel">
    <h3 class="pm-add-title"><i class="fa-solid fa-plus"></i> Add Staff Account</h3>
    <form action="admin_team" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="add_staff">
        <div class="admin-field-row">
            <div><label>Display Name</label><input type="text" name="name" maxlength="100" placeholder="e.g. Ram Bahadur" required></div>
            <div><label>Username</label><input type="text" name="username" minlength="3" maxlength="40" placeholder="login name" required></div>
        </div>
        <div class="admin-field-row">
            <div><label>Email <span class="small-note">(optional)</span></label><input type="email" name="email" placeholder="staff@example.com"></div>
            <div><label>Role</label><select name="role" class="team-role-select"><option value="manager">Manager</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></div>
        </div>
        <div class="admin-field-row">
            <div><label>Password</label><input type="password" name="password" minlength="8" placeholder="Min 8 characters" required autocomplete="new-password"></div>
            <div><label>Confirm Password</label><input type="password" name="password_confirm" required autocomplete="new-password"></div>
        </div>
        <label style="margin-top:.4rem">Page Access <span class="small-note">(Super Admins open every page)</span></label>
        <div class="team-pages-wrap">
        <?= team_pages_grid('pages', []) ?>
        </div>
        <div class="pm-add-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Account</button>
            <button type="button" class="btn btn-outline" id="addStaffCancel">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<section class="admin-section">
    <div class="pm-list" id="staffList">
        <?php foreach ($staff as $s): $sid = (int)$s['id']; $isSuper = $s['role'] === 'superadmin'; $isSelf = $sid === (int)$_SESSION['admin_id'];
            $parts = preg_split('/\s+/', trim((string)$s['name']));
            $ini = strtoupper(substr((string)$parts[0], 0, 1) . (isset($parts[1]) ? substr((string)$parts[1], 0, 1) : ''));
            $pageCount = $isSuper ? 'All pages' : count($grants[$sid] ?? []) . ' page' . (count($grants[$sid] ?? []) === 1 ? '' : 's');
        ?>
        <div class="pm-row" data-search="<?= $ce(strtolower($s['name'] . ' ' . $s['username'] . ' ' . ($s['email'] ?? '') . ' ' . $roleLabels[$s['role']])) ?>">
            <div class="pm-item">
                <span class="pm-thumb pm-avatar"><?= $ce($ini) ?></span>
                <span class="pm-body">
                    <span class="pm-name"><?= $ce($s['name']) ?><?= $isSelf ? ' <span class="store-kind-badge">(you)</span>' : '' ?><span class="team-role team-role-<?= $ce($s['role']) ?>"><i class="fa-solid <?= $isSuper ? 'fa-crown' : ($s['role'] === 'admin' ? 'fa-user-gear' : 'fa-user-tie') ?>"></i> <?= $ce($roleLabels[$s['role']]) ?></span></span>
                    <span class="pm-meta">@<?= $ce($s['username']) ?><?= ($s['email'] ?? '') !== '' && $s['email'] !== null ? ' · ' . $ce($s['email']) : '' ?></span>
                </span>
                <span class="pm-price pm-vendor-row">
                    <span class="order-status-pill <?= (int)$s['is_active'] === 1 ? 'kyc-approved' : 'kyc-rejected' ?>"><?= (int)$s['is_active'] === 1 ? 'Active' : 'Disabled' ?></span>
                </span>
                <span class="pm-user-meta pm-meta">
                    <span><?= $ce($pageCount) ?></span>
                    <span>Last login: <?= $s['last_login'] !== null ? $ce(substr((string)$s['last_login'], 0, 16)) : 'never' ?></span>
                </span>
                <span class="pm-actions">
                    <?php if ($isSelf): ?>
                        <span class="team-lock"><i class="fa-solid fa-circle-user"></i> You — <a href="admin_account">My Account</a></span>
                    <?php elseif ($editId === $sid): ?>
                        <a class="pm-act pm-edit" href="admin_team"><i class="fa-solid fa-xmark"></i> Close</a>
                    <?php else: ?>
                        <a class="pm-act pm-edit" href="admin_team?edit=<?= $sid ?>"><i class="fa-solid fa-pen"></i> Edit &amp; Permissions</a>
                        <form class="pm-del-inline" action="admin_team" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $sid ?>">
                            <button type="submit" class="pm-act pm-edit"><?= (int)$s['is_active'] === 1 ? '<i class="fa-solid fa-toggle-on"></i> Disable' : '<i class="fa-solid fa-toggle-off"></i> Enable' ?></button>
                        </form>
                        <form class="pm-del-inline" action="admin_team" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $ce(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_staff">
                            <input type="hidden" name="id" value="<?= $sid ?>">
                            <button type="submit" class="pm-act pm-del-btn" data-confirm="<?= $ce('Delete "' . $s['name'] . '" (@' . $s['username'] . ')? Their access will be removed immediately. This cannot be undone.') ?>"><i class="fa-solid fa-trash-can"></i> Delete</button>
                        </form>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($staff) === 1): ?>
            <p class="pm-empty" style="display:block"><i class="fa-solid fa-user-plus"></i> Only the superadmin exists so far — add your first staff account above.</p>
        <?php endif; ?>
    </div>
    <p class="pm-empty" id="staffEmpty" style="display:none"><i class="fa-solid fa-magnifying-glass"></i> No staff match your search.</p>
</section>

<script>
(function(){
  var addBtn=document.getElementById('addStaffBtn'),addPanel=document.getElementById('addStaffPanel'),addCancel=document.getElementById('addStaffCancel');
  function setAdd(open){if(!addPanel)return;addPanel.style.display=open?'block':'none';if(addBtn)addBtn.classList.toggle('active',open);}
  if(addBtn)addBtn.addEventListener('click',function(){setAdd(addPanel.style.display==='none'||!addPanel.style.display);});
  if(addCancel)addCancel.addEventListener('click',function(){setAdd(false);});

  var search=document.getElementById('staffSearch');
  if(search){
    search.addEventListener('input',function(){
      var q=search.value.trim().toLowerCase(),any=false;
      document.querySelectorAll('#staffList .pm-row').forEach(function(row){
        var show=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;
        row.style.display=show?'':'none';
        if(show)any=true;
      });
      var empty=document.getElementById('staffEmpty');
      if(empty)empty.style.display=any?'none':'block';
    });
  }

  document.querySelectorAll('.pm-del-inline').forEach(function(form){
    form.addEventListener('submit',function(e){
      var btn=form.querySelector('.pm-del-btn');
      if(btn&&!window.confirm(btn.getAttribute('data-confirm')))e.preventDefault();
    });
  });

  document.querySelectorAll('.team-role-select').forEach(function(sel){
    var sync=function(){
      var f=sel.closest('form'),w=f?f.querySelector('.team-pages-wrap'):null;
      if(w)w.style.display=sel.value==='superadmin'?'none':'';
    };
    sel.addEventListener('change',sync);sync();
  });
})();
</script>
<?php
admin_page_end();
