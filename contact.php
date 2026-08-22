<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['csrf_contact'])) $_SESSION['csrf_contact'] = bin2hex(random_bytes(32));
$user = $_SESSION['user'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$parts = $user ? preg_split('/\s+/', trim($user['name'])) : [];
$firstName = $parts[0] ?? '';
$initials = $user ? strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) : '';
require_once __DIR__ . '/site_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title>Contact | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=35">
</head>
<body>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
            <?php if ($user): ?>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"<?= !empty($user['avatar']) ? ' style="background-image:url(\'' . htmlspecialchars($user['avatar'], ENT_QUOTES, 'UTF-8') . '\')"' : '' ?>><?= empty($user['avatar']) ? htmlspecialchars($initials) : '' ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($user['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($user['phone']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($user['dob']) ?></p>
                        <?php if (!empty($_SESSION['is_admin'])): ?>
                            <a class="btn btn-outline btn-block" href="admin"><i class="fa-solid fa-gear"></i> Admin Panel</a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-block" href="profile" style="margin-top:.5rem;"><i class="fa-solid fa-user-gear"></i> My Profile</a>
                        <a class="btn btn-outline btn-block" href="orders" style="margin-top:.5rem;"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a class="btn btn-primary btn-block" href="logout" style="margin-top:.5rem; background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Log Out</a>
                    </div>
                </div>
            </li>
            <?php else: ?>
            <li><a class="nav-a nav-cta" href="login"><i class="fa-solid fa-right-to-bracket"></i> Login / Sign Up</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<?php if ($flash): ?>
    <div class="flash-banner flash-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<main>
    <section id="contact" class="section section-contact">
        <div class="container">
            <div class="contact-split">
                <div class="contact-split-left">
                    <div class="section-head">
                        <h1 class="display">Contact Our Service Team <i class="fa-solid fa-phone"></i></h1>
                    </div>
                    <div class="grid contact-grid" id="contact-grid"></div>
                    <div class="contact-map">
                        <iframe src="https://www.google.com/maps?q=Birendranagar%2C%20Surkhet%2C%20Nepal&output=embed" width="100%" height="280" style="border:0" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="LyaiDeu location — Birendranagar, Surkhet, Nepal"></iframe>
                    </div>
                </div>
                <div class="contact-split-right">
                    <div class="section-head">
                        <h2 class="display">Send Us a Message <i class="fa-solid fa-envelope"></i></h2>
                        <p class="section-sub">Questions, feedback or partnership ideas — drop us a line and our team will reply soon.</p>
                    </div>
                    <form action="contact_send" method="POST" class="contact-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_contact'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="contact-form-row">
                            <div><label>Your Name</label><input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" placeholder="Full name" required></div>
                            <div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="you@example.com" required></div>
                        </div>
                        <div class="contact-form-row">
                            <div><label>Phone</label><input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="98XXXXXXXX"></div>
                            <div><label>Subject</label><input type="text" name="subject" placeholder="e.g. Order help, feedback…"></div>
                        </div>
                        <label>Message</label>
                        <textarea name="message" rows="5" placeholder="Tell us how we can help…" minlength="10" required></textarea>
                        <button type="submit" class="btn btn-primary contact-submit"><i class="fa-solid fa-paper-plane"></i> Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>

<?= lyaideu_footer_html() ?>

<script src="js/script.js?v=28"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
</body>
</html>