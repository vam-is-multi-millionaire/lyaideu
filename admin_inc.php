<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/site_config.php';

function admin_csrf_token(): string {
    if (!isset($_SESSION['csrf_admin'])) {
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_admin'];
}

function admin_is_logged_in(): bool {
    return !empty($_SESSION['is_admin']) && !empty($_SESSION['admin_id']);
}

/**
 * Full registry of admin panel sections (page key → label/href/icon).
 * Use admin_visible_nav_items() for anything rendered to the current user.
 */
function admin_nav_items(): array {
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'admin', 'icon' => '<i class="fa-solid fa-chart-simple"></i>'],
        'control' => ['label' => 'Control Panel', 'href' => 'admin_control', 'icon' => '<i class="fa-solid fa-sliders"></i>'],
        'categories' => ['label' => 'Categories', 'href' => 'admin_categories', 'icon' => '<i class="fa-solid fa-tags"></i>'],
        'sections' => ['label' => 'Sections', 'href' => 'admin_sections', 'icon' => '<i class="fa-solid fa-layer-group"></i>'],
        'promos' => ['label' => 'Promo Codes', 'href' => 'admin_promocodes', 'icon' => '<i class="fa-solid fa-ticket"></i>'],
        'orders' => ['label' => 'Orders', 'href' => 'admin_orders', 'icon' => '<i class="fa-solid fa-box"></i>'],
        'dishes' => ['label' => 'Menu Items', 'href' => 'admin_dishes', 'icon' => '<i class="fa-solid fa-utensils"></i>'],
        'mart' => ['label' => 'Mart', 'href' => 'admin_mart', 'icon' => '<i class="fa-solid fa-basket-shopping"></i>'],
        'beverages' => ['label' => 'Beverages', 'href' => 'admin_beverages', 'icon' => '<i class="fa-solid fa-glass-water"></i>'],
        'others' => ['label' => 'Others', 'href' => 'admin_others', 'icon' => '<i class="fa-solid fa-gift"></i>'],
        'hotels' => ['label' => 'Stores & Vendors', 'href' => 'admin_vendors', 'icon' => '<i class="fa-solid fa-store"></i>'],
        'riders' => ['label' => 'Riders', 'href' => 'admin_riders', 'icon' => '<i class="fa-solid fa-motorcycle"></i>'],
        'contacts' => ['label' => 'Contacts', 'href' => 'admin_contacts', 'icon' => '<i class="fa-solid fa-phone"></i>'],
        'messages' => ['label' => 'Messages', 'href' => 'admin_messages', 'icon' => '<i class="fa-solid fa-envelope"></i>'],
        'users' => ['label' => 'Users', 'href' => 'admin_users', 'icon' => '<i class="fa-solid fa-users"></i>'],
        'team' => ['label' => 'Staff & Roles', 'href' => 'admin_team', 'icon' => '<i class="fa-solid fa-user-shield"></i>'],
        'kyc' => ['label' => 'KYC', 'href' => 'admin_kyc', 'icon' => '<i class="fa-solid fa-shield-halved"></i>'],
        'settings' => ['label' => 'Settings', 'href' => 'admin_settings', 'icon' => '<i class="fa-solid fa-gear"></i>'],
    ];
}

/**
 * Page keys that can be granted to admin / manager accounts.
 * Dashboard is always available and Staff & Roles is superadmin-only,
 * so neither appears in this grantable list.
 */
function admin_grantable_page_keys(): array {
    return [
        'control', 'categories', 'sections', 'promos', 'orders', 'dishes',
        'mart', 'beverages', 'others', 'hotels', 'riders', 'contacts',
        'messages', 'users', 'kyc', 'settings',
    ];
}

/**
 * Row of the signed-in staff member from admin_users, or null when the
 * session is not a valid active staff account. Cached per request.
 */
function admin_current_user(): ?array {
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (!admin_is_logged_in()) {
        return null;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT id, username, name, email, role, is_active FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute([':id' => (int)$_SESSION['admin_id']]);
        $row = $st->fetch();
        if ($row && (int)$row['is_active'] === 1) {
            $user = $row;
        }
    } catch (Throwable $e) {
        $user = null;
    }
    return $user;
}

function admin_role(): string {
    $u = admin_current_user();
    return $u ? (string)$u['role'] : '';
}

function admin_is_superadmin(): bool {
    return admin_role() === 'superadmin';
}

/** Display name of the signed-in staff member (used for audit fields). */
function admin_display_name(): string {
    $u = admin_current_user();
    return $u ? (string)$u['name'] : 'Admin';
}

/**
 * Page keys explicitly granted to the current non-superadmin user.
 * Superadmins get everything implicitly (empty array + role check).
 */
function admin_granted_pages(): array {
    static $pages = null;
    static $loaded = false;
    if ($loaded) {
        return $pages ?? [];
    }
    $loaded = true;
    $pages = [];
    $u = admin_current_user();
    if (!$u || ($u['role'] ?? '') === 'superadmin') {
        return $pages;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return $pages;
    }
    try {
        $st = $pdo->prepare('SELECT page_key FROM admin_user_pages WHERE admin_id = :id');
        $st->execute([':id' => (int)$u['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (in_array($key, admin_grantable_page_keys(), true)) {
                $pages[] = (string)$key;
            }
        }
    } catch (Throwable $e) {
        $pages = [];
    }
    return $pages;
}

/**
 * May the current staff member open this admin page?
 * superadmin → always; dashboard → every signed-in staff; team → superadmin only.
 */
function admin_can(string $pageKey): bool {
    if (!admin_is_logged_in()) {
        return false;
    }
    if ($pageKey === 'dashboard') {
        return true;
    }
    if (!in_array($pageKey, admin_grantable_page_keys(), true)) {
        return $pageKey === 'team' ? admin_is_superadmin() : false;
    }
    if (admin_is_superadmin()) {
        return true;
    }
    return in_array($pageKey, admin_granted_pages(), true);
}

/** Nav items filtered by what the current staff member may open. */
function admin_visible_nav_items(): array {
    $out = [];
    foreach (admin_nav_items() as $key => $item) {
        if (admin_can($key)) {
            $out[$key] = $item;
        }
    }
    return $out;
}

function admin_handle_auth(): ?string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $tokenValid = hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '');

        if (!$tokenValid) {
            return 'Invalid security token. Please refresh and try again.';
        }
        if ($username === '' || $password === '') {
            return 'Enter your username and password.';
        }
        if (!lyaideu_ensure_admin_users_tables()) {
            return 'Database unavailable. Please try again later.';
        }
        $pdo = lyaideu_load_pdo();
        if (!$pdo instanceof PDO) {
            return 'Database unavailable. Please try again later.';
        }
        try {
            $st = $pdo->prepare('SELECT id, username, name, pass_hash, role, is_active FROM admin_users WHERE username = :u LIMIT 1');
            $st->execute([':u' => $username]);
            $row = $st->fetch();
        } catch (Throwable $e) {
            $row = false;
        }
        if (!$row || (int)$row['is_active'] !== 1 || !password_verify($password, (string)$row['pass_hash'])) {
            return 'Wrong username or password!';
        }
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_role'] = (string)$row['role'];
        $_SESSION['admin_name'] = (string)$row['name'];
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
        try {
            $pdo->prepare('UPDATE admin_users SET last_login = :t WHERE id = :id')
                ->execute([':t' => date('Y-m-d H:i:s'), ':id' => (int)$row['id']]);
        } catch (Throwable $e2) {
            /* non-fatal */
        }
        header('Location: admin');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
        if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('Invalid security token.');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: admin');
        exit;
    }

    return null;
}

function admin_show_login(?string $error = null): void {
    admin_csrf_token();
    $safeError = $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
    $logo = htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>Admin Login | LyaiDeu</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=62"></head>
    <body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--orange-50); padding:1rem;">
        <div class="admin-login-box"><div class="brand-mark" style="margin:0 auto 1.2rem"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"></div><h2 class="display"><i class="fa-solid fa-lock"></i> Admin Login</h2>';
    if ($safeError !== '') {
        echo "<p style='color:#c93a3a; font-weight:bold; margin-top:.8rem;'>$safeError</p>";
    }
    echo '<form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">
        <input type="text" name="username" placeholder="Username" required autocomplete="username" style="width:100%; padding:12px; margin:5px 0 0; border:2px solid var(--orange-200); border-radius:8px; font-size:1rem; box-sizing:border-box;">
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password" style="width:100%; padding:12px; margin:15px 0; border:2px solid var(--orange-200); border-radius:8px; font-size:1rem; box-sizing:border-box;">
        <button type="submit" name="login" class="btn btn-primary btn-block">Login to Dashboard</button>
    </form></div></body></html>';
    exit;
}

function admin_require_login(): void {
    lyaideu_ensure_admin_users_tables();
    $error = admin_handle_auth();
    if (!admin_is_logged_in()) {
        admin_show_login($error);
    }
    /* The signed-in staff member must still exist and stay active. */
    if (!admin_current_user()) {
        unset($_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_name']);
        admin_show_login('Your account is no longer active. Please sign in again.');
    }
}

/**
 * Guard for a specific admin page. Call right after admin_require_login().
 * Renders an "Access denied" screen instead of the page when the current
 * staff member lacks permission for $pageKey.
 */
function admin_require_page(string $pageKey): void {
    if (admin_can($pageKey)) {
        return;
    }
    http_response_code(403);
    $logo = htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>Access denied | LyaiDeu Admin</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=62"></head>
    <body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--orange-50); padding:1rem;">
        <div class="admin-login-box" style="text-align:center"><div class="brand-mark" style="margin:0 auto 1.2rem"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"></div>
            <h2 class="display"><i class="fa-solid fa-ban"></i> Access denied</h2>
            <p style="margin:.7rem 0 1.2rem;">Your account does not have permission to open this page.<br>Ask a superadmin to grant you access from <strong>Staff &amp; Roles</strong>.</p>
            <a class="btn btn-primary btn-block" href="admin"><i class="fa-solid fa-chart-simple"></i> Back to Dashboard</a>
        </div></body></html>';
    exit;
}

function admin_logout_button(): string {
    return '<form method="POST" class="admin-logout-form">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">
        <button type="submit" name="logout" class="btn btn-primary admin-logout-btn">Logout</button>
    </form>';
}

function admin_flash_banner(): void {
    if (isset($_GET['saved'])) {
        echo '<div class="flash-banner flash-success admin-flash"><i class="fa-solid fa-circle-check"></i> Changes saved successfully.</div>';
    } elseif (isset($_GET['deleted'])) {
        echo '<div class="flash-banner flash-success admin-flash"><i class="fa-solid fa-circle-check"></i> Message deleted.</div>';
    } elseif (isset($_GET['error'])) {
        echo '<div class="flash-banner flash-error admin-flash"><i class="fa-solid fa-circle-xmark"></i> ' . htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function admin_page_start(string $pageTitle, string $activeNav, ?string $heading = null): void {
    $heading = $heading ?? $pageTitle;
    $navItems = admin_visible_nav_items();
    $logo = htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' | LyaiDeu Admin</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=62"></head><body class="admin-body">';
    echo '<header class="admin-header"><div class="admin-header-brand"><button type="button" class="admin-nav-toggle" id="adminNavToggle" aria-label="Toggle admin menu" aria-expanded="false"><span></span><span></span><span></span></button><a href="admin" class="admin-brand-link"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"><h1 class="display">LyaiDeu Admin</h1></a></div>';
    $roleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'manager' => 'Manager'];
    echo '<div class="admin-actions"><span style="display:inline-flex;align-items:center;gap:.45rem;background:var(--orange-100);color:var(--orange-800);font-weight:800;font-size:.8rem;padding:.42rem .8rem;border-radius:999px;white-space:nowrap;" title="Signed in as ' . htmlspecialchars(admin_display_name(), ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-circle-user"></i> ' . htmlspecialchars(admin_display_name(), ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($roleLabels[admin_role()] ?? ucfirst(admin_role()), ENT_QUOTES, 'UTF-8') . '</span><a href="index" target="_blank" class="btn btn-outline">View Website</a>';
    echo admin_logout_button();
    echo '</div></header>';
    echo '<div class="admin-nav-backdrop" id="adminNavBackdrop"></div>';
    echo '<div class="admin-shell"><aside class="admin-sidebar" id="adminSidebar"><nav class="admin-nav" aria-label="Admin sections">';
    foreach ($navItems as $key => $item) {
        $active = $key === $activeNav ? ' active' : '';
        echo '<a class="admin-nav-link' . $active . '" href="' . htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<span class="admin-nav-icon">' . $item['icon'] . '</span><span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    echo '</nav></aside><main class="admin-main"><div class="admin-container">';
    $activeIcon = $navItems[$activeNav]['icon'] ?? '';
    echo '<div class="admin-page-head"><h2 class="display admin-page-title">' . $activeIcon . ' ' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2></div>';
    admin_flash_banner();
}

function admin_page_end(): void {
    echo '</div></main></div>';
    echo '<script>(function(){var t=document.getElementById("adminNavToggle"),s=document.getElementById("adminSidebar"),b=document.getElementById("adminNavBackdrop");if(!t||!s)return;var h=document.querySelector(".admin-header"),SKEY="lyaideu_admin_sidebar_scroll:1";function isMobile(){return window.innerWidth<=900}function pos(){var ht=h?h.offsetHeight:64;s.style.top=ht+"px";if(b)b.style.top=ht+"px"}function setOpen(o){s.classList.toggle("open",o);t.classList.toggle("open",o);t.setAttribute("aria-expanded",o?"true":"false");if(b)b.classList.toggle("show",o)}function saveScroll(){try{sessionStorage.setItem(SKEY,String(s.scrollTop))}catch(e){}}function restoreScroll(){try{var n=parseInt(sessionStorage.getItem(SKEY),10);if(isFinite(n)&&n>0)s.scrollTop=n}catch(e){}}pos();restoreScroll();window.addEventListener("resize",function(){pos();if(!isMobile()&&s.classList.contains("open"))setOpen(false)});window.addEventListener("beforeunload",saveScroll);t.addEventListener("click",function(){if(isMobile())setOpen(!s.classList.contains("open"))});if(b)b.addEventListener("click",function(){setOpen(false)});s.addEventListener("click",function(e){if(e.target.closest("a")){saveScroll();setOpen(false)}})})();</script>';
    echo '<script src="js/scroll-memory.js?v=6"></script>';
    echo '</body></html>';
}

function admin_section_redirect(string $section, bool $saved, ?string $error = null): void {
    $routes = [
        'categories' => 'admin_categories',
        'category_reorder' => 'admin_categories',
        'sections' => 'admin_sections',
        'section_reorder' => 'admin_sections',
        'section_links' => 'admin_sections',
        'promos' => 'admin_promocodes',
        'dishes' => 'admin_dishes',
        'mart' => 'admin_mart',
        'beverages' => 'admin_beverages',
        'others' => 'admin_others',
        'hotels' => 'admin_vendors',
        'contacts' => 'admin_contacts',
    ];

    $target = $routes[$section] ?? 'admin';
    if ($saved) {
        header('Location: ' . $target . '?saved=1');
    } else {
        header('Location: ' . $target . '?error=' . urlencode($error ?? 'Could not save changes.'));
    }
    exit;
}
