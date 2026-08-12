<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$user = $_SESSION['user'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$parts = preg_split('/\s+/', trim($user['name']));
$firstName = $parts[0];
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
require_once __DIR__ . '/site_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms of Service | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=3">
</head>
<body>

<div class="promo-ticker"><div class="ticker-track">
    <span><i class="fa-solid fa-fire"></i> Free delivery on your first order — code LYAIDEU</span>
    <span><i class="fa-solid fa-drumstick-bite"></i> Momo Festival: 20% off</span>
    <span><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</span>
    <span><i class="fa-solid fa-heart"></i> 25+ hotels across the city</span>
    <span><i class="fa-solid fa-fire"></i> Free delivery on your first order — code LYAIDEU</span>
    <span><i class="fa-solid fa-drumstick-bite"></i> Momo Festival: 20% off</span>
    <span><i class="fa-solid fa-motorcycle"></i> Fast Delivery · Surkhet Valley</span>
    <span><i class="fa-solid fa-heart"></i> 25+ hotels across the city</span>
</div></div>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index.php"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"> Lyai<span>Deu</span></a>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index.php" class="nav-a">Home</a></li>
            <li><a href="menu.php" class="nav-a">Menu</a></li>
            <li><a href="hotels.php" class="nav-a">Hotels</a></li>
            <li><a href="mart.php" class="nav-a">Mart</a></li>
            <li><a href="contact.php" class="nav-a">Contact</a></li>
            <li><a href="orders.php" class="nav-a"><i class="fa-solid fa-box"></i> Orders</a></li>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($user['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($user['phone']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($user['dob']) ?></p>
                        <?php if (!empty($_SESSION['is_admin'])): ?>
                            <a class="btn btn-outline btn-block" href="admin.php"><i class="fa-solid fa-gear"></i> Admin Panel</a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-block" href="orders.php" style="margin-top:.5rem;"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a class="btn btn-primary btn-block" href="logout.php" style="margin-top:.5rem; background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Log Out</a>
                    </div>
                </div>
            </li>
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
                <p>We aim to deliver fast across the Surkhet Valley, usually within 30 minutes, but delivery times are estimates and not guaranteed. A flat delivery fee may apply. Risk and responsibility for the food pass to you upon delivery.</p>

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

<footer class="footer">
    <div class="footer-grid">
        <div><p class="footer-brand"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"></p><p class="footer-blurb">Nepal's friendliest food delivery service — connecting you to the best hotels in the valley.</p></div>
        <div><h4>Quick Links</h4><ul><li><a href="index.php">Home</a></li><li><a href="menu.php">Menu</a></li><li><a href="hotels.php">Hotels</a></li><li><a href="mart.php">Mart</a></li><li><a href="contact.php">Contact</a></li><li><a href="faq.php">FAQ &amp; Privacy</a></li><li><a href="terms.php">Terms of Service</a></li><li><a href="demo.html"><i class="fa-solid fa-film"></i> Product Demo</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> Birendranagar, Surkhet</li><li><i class="fa-solid fa-envelope"></i> hello@lyaideu.com.np</li><li><i class="fa-solid fa-phone"></i> 9800000001</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>Sun – Fri: 7 AM – 10 PM</li><li>Saturday: 8 AM – 10 PM</li><li><i class="fa-solid fa-motorcycle"></i> Deliveries every day!</li></ul></div>
    </div>
    <div class="footer-bottom">© <span id="year">2026</span> LyaiDeu · All rights reserved.</div>
</footer>

<script src="js/script.js?v=3"></script>
</body>
</html>