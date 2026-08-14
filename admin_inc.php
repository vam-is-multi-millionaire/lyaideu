<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/site_config.php';

const ADMIN_PASS_HASH = '$2y$12$gKimlVM8pqaeijJcHazLGOfF2Qbse0Obz29rRt4hUt/FLUFAmvrPa'; // Default demo password: admin123

function admin_username(): string {
    return site_setting('admin_username', 'admin');
}

function admin_pass_hash(): string {
    return site_setting('admin_pass_hash', ADMIN_PASS_HASH);
}

function admin_csrf_token(): string {
    if (!isset($_SESSION['csrf_admin'])) {
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_admin'];
}

function admin_is_logged_in(): bool {
    return !empty($_SESSION['is_admin']);
}

function admin_nav_items(): array {
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'admin', 'icon' => '<i class="fa-solid fa-chart-simple"></i>'],
        'categories' => ['label' => 'Categories', 'href' => 'admin_categories', 'icon' => '<i class="fa-solid fa-tags"></i>'],
        'orders' => ['label' => 'Orders', 'href' => 'admin_orders', 'icon' => '<i class="fa-solid fa-box"></i>'],
        'dishes' => ['label' => 'Menu Items', 'href' => 'admin_dishes', 'icon' => '<i class="fa-solid fa-utensils"></i>'],
        'mart' => ['label' => 'Mart', 'href' => 'admin_mart', 'icon' => '<i class="fa-solid fa-basket-shopping"></i>'],
        'hotels' => ['label' => 'Hotels', 'href' => 'admin_hotels', 'icon' => '<i class="fa-solid fa-hotel"></i>'],
        'vendors' => ['label' => 'Vendors', 'href' => 'admin_vendors', 'icon' => '<i class="fa-solid fa-store"></i>'],
        'riders' => ['label' => 'Riders', 'href' => 'admin_riders', 'icon' => '<i class="fa-solid fa-motorcycle"></i>'],
        'contacts' => ['label' => 'Contacts', 'href' => 'admin_contacts', 'icon' => '<i class="fa-solid fa-phone"></i>'],
        'messages' => ['label' => 'Messages', 'href' => 'admin_messages', 'icon' => '<i class="fa-solid fa-envelope"></i>'],
        'users' => ['label' => 'Users', 'href' => 'admin_users', 'icon' => '<i class="fa-solid fa-users"></i>'],
        'settings' => ['label' => 'Settings', 'href' => 'admin_settings', 'icon' => '<i class="fa-solid fa-gear"></i>'],
    ];
}

function admin_handle_auth(): ?string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $tokenValid = hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '');

        if (!$tokenValid) {
            return 'Invalid security token. Please refresh and try again.';
        }
        if (hash_equals(admin_username(), $username) && password_verify($password, admin_pass_hash())) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
            header('Location: admin');
            exit;
        }
        return 'Wrong username or password!';
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
<link rel="stylesheet" href="css/style.css?v=6"></head>
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
    $error = admin_handle_auth();
    if (!admin_is_logged_in()) {
        admin_show_login($error);
    }
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
    $navItems = admin_nav_items();
    $logo = htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' | LyaiDeu Admin</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6"></head><body class="admin-body">';
    echo '<header class="admin-header"><div class="admin-header-brand"><button type="button" class="admin-nav-toggle" id="adminNavToggle" aria-label="Toggle admin menu" aria-expanded="false"><span></span><span></span><span></span></button><a href="admin" class="admin-brand-link"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"><h1 class="display">LyaiDeu Admin</h1></a></div>';
    echo '<div class="admin-actions"><a href="index" target="_blank" class="btn btn-outline">View Website</a>';
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
    echo '<script>(function(){var t=document.getElementById("adminNavToggle"),s=document.getElementById("adminSidebar"),b=document.getElementById("adminNavBackdrop");if(!t||!s)return;var h=document.querySelector(".admin-header");function isMobile(){return window.innerWidth<=900}function pos(){var ht=h?h.offsetHeight:64;s.style.top=ht+"px";if(b)b.style.top=ht+"px"}function setOpen(o){s.classList.toggle("open",o);t.classList.toggle("open",o);t.setAttribute("aria-expanded",o?"true":"false");if(b)b.classList.toggle("show",o)}pos();window.addEventListener("resize",function(){pos();if(!isMobile()&&s.classList.contains("open"))setOpen(false)});t.addEventListener("click",function(){if(isMobile())setOpen(!s.classList.contains("open"))});if(b)b.addEventListener("click",function(){setOpen(false)});s.addEventListener("click",function(e){if(e.target.closest("a"))setOpen(false)})})();</script>';
    echo '</body></html>';
}

function admin_section_redirect(string $section, bool $saved, ?string $error = null): void {
    $routes = [
        'categories' => 'admin_categories',
        'dishes' => 'admin_dishes',
        'mart' => 'admin_mart',
        'hotels' => 'admin_hotels',
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
