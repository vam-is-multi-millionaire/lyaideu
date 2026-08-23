<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
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
<title>Terms of Service | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=60">
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
    <section class="section">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-file-contract"></i> Know the rules</p>
                <h1 class="display">Terms of Service <i class="fa-solid fa-file-contract"></i></h1>
                <p class="section-sub">Last updated: <?= date('F Y') ?>. Please read these terms carefully before using LyaiDeu.</p>
            </div>
            <div class="policy-body">
                <h3>1. Acceptance of terms</h3>
                <p>By creating an account, browsing the website or placing an order on LyaiDeu, you agree to these Terms of Service and our Privacy Policy. If you do not agree, please do not use our service.</p>

                <h3>2. Our service</h3>
                <p>LyaiDeu is a food delivery platform that connects you with partner hotels across the Surkhet Valley. We help you discover dishes, place orders and arrange fast delivery to your address. We are not the kitchen — restaurants prepare and package your food.</p>

                <h3>3. Accounts &amp; eligibility</h3>
                <p>You must be at least 10 years old to create an account. You agree to provide accurate information (name, phone number, email, date of birth) and to keep your login details secure. You are responsible for all activity under your account.</p>

                <h3>4. Placing orders</h3>
                <p>When you place an order you are making a real purchase request. Order details such as items, price, delivery fee and total are shown before you confirm. Once a hotel confirms your order, it cannot be changed or cancelled from your account alone — contact the hotel or our support team for help.</p>

                <h3>5. Prices &amp; payment</h3>
                <p>Prices are listed in Nepali Rupees and may change without notice. We accept Cash on Delivery and digital wallets (eSewa / Khalti) where supported. Promo codes are subject to their stated rules and may be discontinued at any time.</p>

                <h3>6. Delivery</h3>
                <p>We aim to deliver fast across the Surkhet Valley — Mart-only orders in as little as 15 minutes, and freshly cooked hotel orders in 45–60 minutes — but delivery times are estimates and not guaranteed. A delivery fee may apply and varies with how many vendors your order mixes in. Risk and responsibility for the food pass to you upon delivery.</p>

                <h3>7. Refunds &amp; complaints</h3>
                <p>If your order is late, wrong or unsatisfactory, contact our Delivery Support team as soon as possible. Depending on the situation we will offer a re-delivery, replacement or refund. Refunds are issued back the same way the payment was made.</p>

                <h3>8. Acceptable use</h3>
                <p>You agree not to misuse the website — for example, providing false information, attempting to access other users' accounts, abusing support staff, or using the service for any unlawful purpose.</p>

                <h3>9. Partner hotels</h3>
                <p>Partner hotels operate independently. LyaiDeu is not liable for food quality, availability or preparation, but we will always help you resolve issues with an order.</p>

                <h3>10. Limitation of liability</h3>
                <p>To the maximum extent permitted by law, LyaiDeu is not liable for indirect or consequential damages, including loss of profits or data, arising from your use of the service.</p>

                <h3>11. Changes to these terms</h3>
                <p>We may update these Terms from time to time. The latest version is always available on this page, and continued use of the service after changes means you accept the new terms.</p>

                <h3>12. Contact</h3>
                <p>Questions about these Terms? Reach us at <strong>hello@lyaideu.com.np</strong> or call <strong>9800000001</strong>.</p>
            </div>
        </div>
    </section>
</main>

<?= lyaideu_footer_html() ?>

<script src="js/script.js?v=32"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
</body>
</html>