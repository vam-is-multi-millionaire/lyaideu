<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (isset($_SESSION['user'])) { header('Location: index'); exit; }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$old = $_SESSION['old'] ?? ['name'=>'','email'=>'','phone'=>'','dob'=>''];
unset($_SESSION['old']);
$activeTab = (($_GET['tab'] ?? '') === 'signup') ? 'signup' : 'login';
$next = trim((string)($_GET['next'] ?? ''));
if ($next !== '' && (!preg_match('#^[A-Za-z0-9_\-.?&=]+$#', $next) || str_starts_with($next, '//') || strpos($next, ':') !== false || strpos($next, '..') !== false)) {
    $next = '';
}
require_once __DIR__ . '/site_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title>LyaiDeu · Login / Sign Up</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=39">
</head>
<body class="auth-body">

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="index" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search dishes, mart &amp; hotels" aria-label="Search LyaiDeu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li class="auth-hide-desktop"><a href="categories" class="nav-a">Categories</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
            <li><a class="nav-a nav-cta" href="login"><i class="fa-solid fa-right-to-bracket"></i> Login / Sign Up</a></li>
        </ul>
    </nav>
</header>

<div class="auth-wrap">

<main class="auth-panel">
    <div class="auth-card">
        <div class="tabs" role="tablist">
            <button type="button" class="tab <?= $activeTab === 'login' ? 'active' : '' ?>" data-show="login">Login</button>
            <button type="button" class="tab <?= $activeTab === 'signup' ? 'active' : '' ?>" data-show="signup">Sign Up</button>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
        <?php endif; ?>

        <!-- LOGIN FORM -->
        <form class="auth-form <?= $activeTab === 'login' ? 'active' : '' ?>" id="form-login" action="auth" method="POST" novalidate>
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="field">
                <label for="li-user">Username</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-user"></i></span>
                    <input type="text" id="li-user" name="username" placeholder="Your full name OR 10-digit phone" data-validate="username" required>
                </div><small class="field-msg field-hint">Login with your name, phone number, or email</small>
                
            </div>

            <div class="field">
                <label for="li-pass">Password</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" id="li-pass" name="password" placeholder="Your password" data-validate="pass" required>
                    <button type="button" class="peek" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                </div>
                <small class="field-msg"></small>
            </div>

            <button class="btn btn-primary btn-block" type="submit">Log In <i class="fa-solid fa-arrow-right"></i></button>
            <p class="switch-line">New to LyaiDeu? <a href="#" data-show="signup">Create an account</a></p>
        </form>

        <!-- SIGNUP FORM -->
        <form class="auth-form <?= $activeTab === 'signup' ? 'active' : '' ?>" id="form-signup" action="auth" method="POST" novalidate>
            <input type="hidden" name="action" value="signup">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="field">
                <label for="su-name">Full Name</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-user"></i></span>
                    <input type="text" id="su-name" name="name" placeholder="e.g. Aarav Shrestha" value="<?= htmlspecialchars($old['name']) ?>" data-validate="name" required>
                </div>
                <small class="field-msg"></small>
            </div>

            <div class="field">
                <label for="su-email">Email</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" id="su-email" name="email" placeholder="you@gmail.com" value="<?= htmlspecialchars($old['email']) ?>" data-validate="email" required>
                </div>
                <small class="field-msg field-hint">Must end with @gmail.com</small>
            </div>

            <div class="field">
                <label for="su-phone">Contact Number</label>
                <div class="control">
                    <span class="prefix"><i class="fa-solid fa-flag"></i> +977</span>
                    <input type="tel" id="su-phone" name="phone" placeholder="98XXXXXXXX" maxlength="10" inputmode="numeric" value="<?= htmlspecialchars($old['phone']) ?>" data-validate="phone" required>
                </div>
                <small class="field-msg field-hint">Exactly 10 digits, starts with 97 or 98</small>
            </div>

            <div class="field">
                <label for="su-dob">Date of Birth</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-cake-candles"></i></span>
                    <input type="date" id="su-dob" name="dob" value="<?= htmlspecialchars($old['dob']) ?>" data-validate="dob" required>
                </div>
                <small class="field-msg field-hint">You must be between 10 and 80 years old</small>
            </div>

            <div class="field">
                <label for="su-pass">New Password</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" id="su-pass" name="password" placeholder="Min 8 chars, 1 capital, 1 symbol, 1 number" data-validate="strongpass" required>
                    <button type="button" class="peek" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                </div>
                <div class="pass-requirements" id="passReqs">
                    <span class="req" data-req="len"><i class="fa-solid fa-check"></i> 8+ characters</span>
                    <span class="req" data-req="cap"><i class="fa-solid fa-check"></i> 1 capital letter</span>
                    <span class="req" data-req="num"><i class="fa-solid fa-check"></i> 1 number</span>
                    <span class="req" data-req="sym"><i class="fa-solid fa-check"></i> 1 symbol</span>
                    <span class="req" data-req="info"><i class="fa-solid fa-check"></i> Not your name/phone</span>
                </div>
                <small class="field-msg"></small>
            </div>

            <div class="field">
                <label for="su-confirm">Confirm Password</label>
                <div class="control">
                    <span class="control-ico"><i class="fa-solid fa-key"></i></span>
                    <input type="password" id="su-confirm" name="confirm" placeholder="Re-enter the same password" data-validate="confirm" required>
                    <button type="button" class="peek" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                </div>
                <small class="field-msg"></small>
            </div>

            <div class="field terms-field">
                <label class="terms-label" for="su-terms">
                    <input type="checkbox" id="su-terms" name="terms" value="on" data-validate="terms" required>
                    <span class="terms-box"><i class="fa-solid fa-check"></i></span>
                    <span class="terms-text">I agree to the <a href="terms" target="_blank" rel="noopener">Terms &amp; Conditions</a> and <a href="faq" target="_blank" rel="noopener">Privacy Policy</a></span>
                </label>
                <small class="field-msg"></small>
            </div>

            <button class="btn btn-primary btn-block" type="submit">Create My Account <i class="fa-solid fa-arrow-right"></i></button>
            <p class="switch-line">Already have an account? <a href="#" data-show="login">Login</a></p>
        </form>
    </div>
    <p class="auth-foot">© <?= date('Y') ?> LyaiDeu · All rights reserved.</p>
</main>
</div>

<nav class="bottom-nav" id="bottomNav" aria-label="Primary mobile navigation">
    <a class="bn-item" data-nav="home" href="index"><span class="bn-ico"><i class="fa-solid fa-house"></i></span><span class="bn-label">Home</span></a>
    <a class="bn-item" data-nav="categories" href="categories"><span class="bn-ico"><i class="fa-solid fa-layer-group"></i></span><span class="bn-label">Categories</span></a>
    <a class="bn-item" data-nav="stores" href="store"><span class="bn-ico"><i class="fa-solid fa-store"></i></span><span class="bn-label">Stores</span></a>
    <button class="bn-item cart-open-btn" data-nav="cart" type="button" aria-label="Open cart"><span class="bn-ico"><i class="fa-solid fa-cart-shopping"></i><span class="bn-count cart-count">0</span></span><span class="bn-label">Cart</span></button>
    <a class="bn-item" data-nav="profile" href="profile"><span class="bn-ico"><i class="fa-solid fa-user"></i></span><span class="bn-label">Profile</span></a>
</nav>

<script src="js/script.js?v=26"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
<script>
// Emergency tab switcher (always fresh, never cached)
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-show]');
  if (!t) return;
  e.preventDefault();
  var which = t.getAttribute('data-show');
  document.querySelectorAll('.tab').forEach(function (b) {
    b.classList.toggle('active', b.getAttribute('data-show') === which);
  });
  document.querySelectorAll('.auth-form').forEach(function (f) {
    f.classList.toggle('active', f.id === 'form-' + which);
  });
});
</script>
</body>
</html>