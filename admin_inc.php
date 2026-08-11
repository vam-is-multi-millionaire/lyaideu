<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ADMIN_PASS_HASH = '$2y$12$gKimlVM8pqaeijJcHazLGOfF2Qbse0Obz29rRt4hUt/FLUFAmvrPa'; // Default demo password: admin123

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
        'dashboard' => ['label' => 'Dashboard', 'href' => 'admin.php', 'icon' => '<i class="fa-solid fa-chart-simple"></i>'],
        'orders' => ['label' => 'Orders', 'href' => 'admin_orders.php', 'icon' => '<i class="fa-solid fa-box"></i>'],
        'dishes' => ['label' => 'Menu Items', 'href' => 'admin_dishes.php', 'icon' => '<i class="fa-solid fa-utensils"></i>'],
        'hotels' => ['label' => 'Hotels', 'href' => 'admin_hotels.php', 'icon' => '<i class="fa-solid fa-hotel"></i>'],
        'contacts' => ['label' => 'Contacts', 'href' => 'admin_contacts.php', 'icon' => '<i class="fa-solid fa-phone"></i>'],
        'users' => ['label' => 'Users', 'href' => 'admin_users.php', 'icon' => '<i class="fa-solid fa-users"></i>'],
    ];
}

function admin_handle_auth(): ?string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $password = $_POST['password'] ?? '';
        $tokenValid = hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '');

        if (!$tokenValid) {
            return 'Invalid security token. Please refresh and try again.';
        }
        if (password_verify($password, ADMIN_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
            header('Location: admin.php');
            exit;
        }
        return 'Wrong password!';
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
        header('Location: admin.php');
        exit;
    }

    return null;
}

function admin_show_login(?string $error = null): void {
    admin_csrf_token();
    $safeError = $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login | LyaiDeu</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css"></head>
    <body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--orange-50); padding:1rem;">
        <div class="admin-login-box"><h2 class="display"><i class="fa-solid fa-lock"></i> LyaiDeu Admin</h2>';
    if ($safeError !== '') {
        echo "<p style='color:#c93a3a; font-weight:bold; margin-top:.8rem;'>$safeError</p>";
    }
    echo '<form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">
        <input type="password" name="password" placeholder="Enter Password" required autocomplete="current-password" style="width:100%; padding:12px; margin:15px 0; border:2px solid var(--orange-200); border-radius:8px; font-size:1rem;">
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
    } elseif (isset($_GET['error'])) {
        echo '<div class="flash-banner flash-error admin-flash"><i class="fa-solid fa-circle-xmark"></i> ' . htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function admin_page_start(string $pageTitle, string $activeNav, ?string $heading = null): void {
    $heading = $heading ?? $pageTitle;
    $navItems = admin_nav_items();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' | LyaiDeu Admin</title>';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css"></head><body class="admin-body">';
    echo '<header class="admin-header"><div class="admin-header-brand"><a href="admin.php" class="admin-brand-link"><h1 class="display"><i class="fa-solid fa-motorcycle"></i> LyaiDeu Admin</h1></a></div>';
    echo '<div class="admin-actions"><a href="index.php" target="_blank" class="btn btn-outline">View Website</a>';
    echo admin_logout_button();
    echo '</div></header>';
    echo '<div class="admin-shell"><aside class="admin-sidebar"><nav class="admin-nav" aria-label="Admin sections">';
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
    echo '</div></main></div></body></html>';
}

function admin_section_redirect(string $section, bool $saved, ?string $error = null): void {
    $routes = [
        'dishes' => 'admin_dishes.php',
        'hotels' => 'admin_hotels.php',
        'contacts' => 'admin_contacts.php',
    ];

    $target = $routes[$section] ?? 'admin.php';
    if ($saved) {
        header('Location: ' . $target . '?saved=1');
    } else {
        header('Location: ' . $target . '?error=' . urlencode($error ?? 'Could not save changes.'));
    }
    exit;
}
