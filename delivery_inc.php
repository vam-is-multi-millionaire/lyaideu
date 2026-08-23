<?php
/**
 * Shared helpers for the /vendor and /rider delivery dashboards.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Each dashboard role gets its own session cookie so logging into the
    // vendor panel never logs out the rider panel (and vice versa), and the
    // main site's PHPSESSID can never clobber delivery logins.
    $deliveryPage = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    session_name($deliveryPage === 'rider.php' ? 'LYAIDEU_RIDER' : 'LYAIDEU_VENDOR');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_delivery_tables();

function delivery_esc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function delivery_role(): string {
    return (string)($_SESSION['delivery_role'] ?? '');
}

function delivery_user(): ?array {
    $role = delivery_role();
    if (!in_array($role, ['vendor', 'rider'], true)) {
        return null;
    }
    return $_SESSION['delivery_user'] ?? null;
}

function delivery_vendor_avatar_url(int $vendorId): string {
    try {
        $pdo = lyaideu_load_pdo();
        if (!$pdo instanceof PDO) {
            return '';
        }
        $st = $pdo->prepare('SELECT scope, hotel_id, name FROM vendors WHERE id = ? LIMIT 1');
        $st->execute([$vendorId]);
        $v = $st->fetch();
        if (!$v) {
            return '';
        }
        $logo = '';
        if ((int)$v['hotel_id'] > 0) {
            $s = $pdo->prepare('SELECT logo FROM hotels WHERE id = ? LIMIT 1');
            $s->execute([(int)$v['hotel_id']]);
            $logo = (string)$s->fetchColumn();
        }
        if ($logo === '') {
            $vn = lyaideu_normalize_name((string)$v['name']);
            $kind = (string)$v['scope'] === 'mart' ? 'mart' : ((string)$v['scope'] === 'other' ? 'other' : ((string)$v['scope'] === 'beverage' ? 'beverage' : 'hotel'));
            $rows = $pdo->prepare('SELECT name, logo FROM hotels WHERE kind = ? ORDER BY id');
            $rows->execute([$kind]);
            foreach ($rows->fetchAll() as $h) {
                if ($vn !== '' && lyaideu_normalize_name((string)$h['name']) === $vn) {
                    $logo = (string)$h['logo'];
                    break;
                }
            }
        }
        return $logo;
    } catch (Throwable $e) {
        return '';
    }
}

function delivery_require_login(string $role): void {
    if (delivery_role() !== $role) {
        delivery_show_login($role);
    }
}

function delivery_login_attempt(string $role): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['delivery_login'])) {
        return;
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $tokenValid = hash_equals($_SESSION['csrf_delivery'] ?? '', $_POST['csrf_token'] ?? '');
    if (!$tokenValid) {
        $_SESSION['delivery_login_error'] = 'Invalid security token. Please refresh and try again.';
        header('Location: ' . $role);
        exit;
    }
    if ($username === '' || $password === '') {
        $_SESSION['delivery_login_error'] = 'Please enter your username and password.';
        header('Location: ' . $role);
        exit;
    }

    $table = $role === 'vendor' ? 'vendors' : 'riders';
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        $_SESSION['delivery_login_error'] = 'Could not connect to the database.';
        header('Location: ' . $role);
        exit;
    }

    try {
        $cols = $role === 'rider' ? 'id, name, email, phone, vehicle, avatar, pass, is_active'
                                  : 'id, name, email, phone, pass, is_active';
        $stmt = $pdo->prepare(
            "SELECT $cols FROM `$table`
             WHERE LOWER(name) = LOWER(:n) OR phone = :p OR LOWER(email) = LOWER(:e) LIMIT 1"
        );
        $stmt->execute([
            ':n' => $username,
            ':p' => $username,
            ':e' => strtolower($username),
        ]);
        $u = $stmt->fetch();
    } catch (Throwable $e) {
        $_SESSION['delivery_login_error'] = 'Could not look up your account right now.';
        header('Location: ' . $role);
        exit;
    }

    if (!$u || !password_verify($password, (string)$u['pass'])) {
        $_SESSION['delivery_login_error'] = 'Invalid username or password.';
        header('Location: ' . $role);
        exit;
    }

    $otherTable = $role === 'vendor' ? 'riders' : 'vendors';
    try {
        $dup = $pdo->prepare("SELECT id FROM `$otherTable` WHERE phone = :p OR (email <> '' AND email = :e) LIMIT 1");
        $dup->execute([':p' => (string)$u['phone'], ':e' => (string)$u['email']]);
        if ($dup->fetch()) {
            $_SESSION['delivery_login_error'] = 'This phone or email is registered to both a vendor and a rider. Contact the administrator to fix your account.';
            header('Location: ' . $role);
            exit;
        }
    } catch (Throwable $e) {
        // Ignore lookup errors.
    }

    if (!(int)$u['is_active']) {
        $_SESSION['delivery_login_error'] = 'Your account has been deactivated. Please contact the administrator.';
        header('Location: ' . $role);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['delivery_role'] = $role;
    $_SESSION['delivery_user'] = [
        'id' => (int)$u['id'],
        'name' => (string)$u['name'],
        'email' => (string)$u['email'],
        'phone' => (string)$u['phone'],
        'vehicle' => (string)($u['vehicle'] ?? ''),
        'avatar' => (string)($u['avatar'] ?? ''),
    ];
    $_SESSION['csrf_delivery'] = bin2hex(random_bytes(32));
    header('Location: ' . $role);
    exit;
}

function delivery_csrf_token(): string {
    if (!isset($_SESSION['csrf_delivery'])) {
        $_SESSION['csrf_delivery'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_delivery'];
}

function delivery_logout(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivery_logout'])) {
        if (hash_equals($_SESSION['csrf_delivery'] ?? '', $_POST['csrf_token'] ?? '')) {
            $role = delivery_role();
            unset($_SESSION['delivery_role'], $_SESSION['delivery_user']);
            header('Location: ' . ($role === 'rider' ? 'rider' : 'vendor'));
            exit;
        }
    }
}

function delivery_show_login(string $role): void {
    delivery_login_attempt($role);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Location: ' . $role);
        exit;
    }
    delivery_csrf_token();
    $safeError = '';
    if (isset($_SESSION['delivery_login_error'])) {
        $safeError = delivery_esc($_SESSION['delivery_login_error']);
        unset($_SESSION['delivery_login_error']);
    }
    $title = $role === 'vendor' ? 'Vendor Login' : 'Rider Login';
    $label = $role === 'vendor' ? 'Partner Kitchen' : 'Delivery Rider';
    $icon = $role === 'vendor' ? 'fa-store' : 'fa-motorcycle';
    $logo = delivery_esc(site_logo_url());
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>' . $title . ' | LyaiDeu</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=55"></head>
    <body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--orange-50); padding:1rem;">
        <div class="admin-login-box"><div class="brand-mark" style="margin:0 auto 1.2rem"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"></div>
        <p class="kicker" style="text-align:center"><i class="fa-solid ' . $icon . '"></i> ' . $label . '</p>
        <h2 class="display" style="text-align:center">' . $title . '</h2>';
    if ($safeError !== '') {
        echo "<p style='color:#c93a3a; font-weight:bold; margin-top:.8rem;'>$safeError</p>";
    }
    echo '<form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="' . delivery_esc(delivery_csrf_token()) . '">
        <input type="text" name="username" placeholder="Name, phone or email" required autocomplete="username" style="width:100%; padding:12px; margin:5px 0 0; border:2px solid var(--orange-200); border-radius:8px; font-size:1rem; box-sizing:border-box;">
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password" style="width:100%; padding:12px; margin:15px 0; border:2px solid var(--orange-200); border-radius:8px; font-size:1rem; box-sizing:border-box;">
        <button type="submit" name="delivery_login" value="1" class="btn btn-primary btn-block">Login to ' . ($role === 'vendor' ? 'Vendor' : 'Rider') . ' Dashboard</button>
    </form>
    <p class="small-note" style="text-align:center; margin-top:1rem;"><a href="index" style="color:var(--orange-700); font-weight:800;">&larr; Back to website</a></p>
    </div></body></html>';
    exit;
}

function delivery_header(string $title, string $heading, string $icon, string $role): void {
    $user = delivery_user();
    $logo = delivery_esc(site_logo_url());
    $name = $user ? delivery_esc($user['name']) : '';
    $avatarUrl = '';
    if ($user) {
        $avatarUrl = $role === 'vendor'
            ? delivery_vendor_avatar_url((int)$user['id'])
            : (string)($user['avatar'] ?? '');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo lyaideu_base_tag();
    echo '<title>' . delivery_esc($title) . ' | LyaiDeu</title>';
    echo site_head_icons();
    echo '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=55">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"></head><body class="delivery-body">
<header class="delivery-topbar"><a class="brand" href="index"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu">Lyai<span>Deu</span></a><a class="delivery-role-badge" href="' . ($role === 'vendor' ? 'vendor' : 'rider') . '" title="' . ($role === 'vendor' ? 'Go to Your Kitchen Queue' : 'Go to Delivery Queue') . '"><i class="fa-solid ' . $icon . '"></i> ' . ($role === 'vendor' ? 'Vendor' : 'Rider') . '</a>
<div class="delivery-user">
  <span class="avatar"' . ($avatarUrl !== '' ? ' style="background-image:url(\'' . delivery_esc($avatarUrl) . '\')"' : '') . '>' . ($avatarUrl === '' ? delivery_esc(substr($user['name'] ?? '', 0, 1)) : '') . '</span>
  <div><strong>' . $name . '</strong><small>' . delivery_esc($user['phone'] ?? '') . '</small></div>
  ' . ($role === 'vendor' ? '<a class="btn btn-outline btn-sm" href="vendor_store"><i class="fa-solid fa-store"></i> My Store</a><a class="btn btn-outline btn-sm" href="vendor_products"><i class="fa-solid fa-box-open"></i> My Products</a>' : '<a class="btn btn-outline btn-sm" href="rider?tab=profile"><i class="fa-solid fa-user-pen"></i> My Profile</a>') . '
  <form method="POST"><input type="hidden" name="csrf_token" value="' . delivery_esc(delivery_csrf_token()) . '"><button type="submit" name="delivery_logout" class="btn btn-outline btn-sm">Log out</button></form>
</div></header>
<main class="delivery-main container"><div class="section-head"><p class="kicker"><i class="fa-solid ' . $icon . '"></i> ' . ($role === 'vendor' ? 'Kitchen orders' : 'Delivery queue') . '</p><h1 class="display">' . delivery_esc($heading) . '</h1><p class="section-sub"><span class="live-indicator" data-live-indicator>? Live updates</span> New orders appear here automatically.</p></div>';
}

function delivery_footer(): void {
    echo '</main>
<script>
(function(){
  function esc(v){return String(v==null?"":v).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;"}[c]})}
  function refresh(){
    fetch(location.pathname+location.search,{headers:{"X-Requested-With":"fetch"},cache:"no-store"})
      .then(function(r){return r.text()})
      .then(function(html){var d=new DOMParser().parseFromString(html,"text/html");var next=d.querySelector("#deliveryQueue");var cur=document.querySelector("#deliveryQueue");if(next&&cur&&next.innerHTML!==cur.innerHTML){cur.replaceWith(next);}var b=document.querySelector("[data-live-indicator]");if(b)b.classList.add("live-on");})
      .catch(function(){});
  }
  refresh();setInterval(refresh,6000);
})();
</script>
<script>window.LYAIDEU_NOTIFY_ROLE = ' . json_encode(delivery_role()) . ';</script>
<script src="js/notify.js?v=6"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* Delivery-spot maps: initialised lazily so freshly refreshed order cards get a map too. */
(function(){
  if(typeof L==="undefined")return;
  function initMaps(){
    document.querySelectorAll(".rider-map").forEach(function(el){
      if(el.getAttribute("data-map-ready")==="1")return;
      var lat=parseFloat(el.getAttribute("data-lat")),lng=parseFloat(el.getAttribute("data-lng"));
      if(isNaN(lat)||isNaN(lng))return;
      var map=L.map(el,{scrollWheelZoom:false,attributionControl:false}).setView([lat,lng],15);
      L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:19}).addTo(map);
      L.marker([lat,lng]).addTo(map);
      el.setAttribute("data-map-ready","1");
      setTimeout(function(){map.invalidateSize()},60);
    });
  }
  initMaps();setInterval(initMaps,2000);
})();
</script>
<script src="js/scroll-memory.js?v=5"></script>
</body></html>';
}
