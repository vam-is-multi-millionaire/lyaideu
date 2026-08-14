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
<title>Frequently Asked Questions | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=6">
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
            <li><a href="hotels" class="nav-a">Hotels</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="orders" class="nav-a">Orders</a></li>
            <?php if ($user): ?>
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
                            <a class="btn btn-outline btn-block" href="admin"><i class="fa-solid fa-gear"></i> Admin Panel</a>
                        <?php endif; ?>
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
                <p class="kicker"><i class="fa-solid fa-circle-question"></i> Need a hand?</p>
                <h1 class="display">Frequently Asked Questions <i class="fa-solid fa-circle-question"></i></h1>
                <p class="section-sub">Everything you need to know about ordering, delivery and payments on LyaiDeu.</p>
            </div>

            <div class="faq-list">
                <details class="faq-item" open>
                    <summary>How do I place an order?</summary>
                    <p>Head over to the Menu page, add your favourite dishes to the cart, and check out with your delivery details. Your order goes straight to the partner hotel — we'll confirm it by phone if needed.</p>
                </details>
                <details class="faq-item">
                    <summary>How long does delivery take?</summary>
                    <p>We aim for fast delivery across the Surkhet Valley, usually within 30 minutes. Exact time depends on distance, traffic and how busy the kitchen is.</p>
                </details>
                <details class="faq-item">
                    <summary>How much is delivery?</summary>
                    <p>Delivery starts at Rs. 50 to anywhere in the valley, and goes up a little when your order mixes items from more than one hotel or the Mart (more stops = a bit more time and fee). Your first order is free with the code <strong>LYAIDEU</strong> — and promo codes like <strong>FOODXPRESS</strong> give you free delivery too.</p>
                </details>
                <details class="faq-item">
                    <summary>What payment methods do you accept?</summary>
                    <p>We accept Cash on Delivery and eSewa / Khalti on delivery. Please have the exact amount ready for a smooth handover.</p>
                </details>
                <details class="faq-item">
                    <summary>Can I track my order?</summary>
                    <p>Yes! Open the Orders page at any time to see your order status — from Pending and Preparing to Out for delivery and Delivered. The page refreshes live automatically.</p>
                </details>
                <details class="faq-item">
                    <summary>Can I cancel or change my order?</summary>
                    <p>Before the hotel confirms your order you can cancel it in the Orders page. Once confirmed, please call the hotel or our order hotline directly to make changes.</p>
                </details>
                <details class="faq-item">
                    <summary>What if my order is late or wrong?</summary>
                    <p>We're sorry about that! Call our Delivery Support line immediately and we'll fix it fast — re-delivery, replacement or a refund, whichever fits best.</p>
                </details>
                <details class="faq-item">
                    <summary>How do I keep my account secure?</summary>
                    <p>Never share your password. Your phone number and email are used only for delivery and order updates. You'll find full details in our Privacy Policy below.</p>
                </details>
            </div>
        </div>
    </section>

    <section class="section section-white">
        <div class="container">
            <div class="section-head">
                <p class="kicker"><i class="fa-solid fa-shield-halved"></i> Privacy first</p>
                <h2 class="display">Privacy Policy <i class="fa-solid fa-shield-halved"></i></h2>
                <p class="section-sub">Last updated: <?= date('F Y') ?>. Your trust matters — here's how we handle your data.</p>
            </div>
            <div class="policy-body">
                <h3>1. Information we collect</h3>
                <p>When you create an account or place an order we collect your name, email address, phone number, date of birth and delivery address. Order history and payment details are stored to fulfil and improve your experience.</p>

                <h3>2. How we use your information</h3>
                <p>Your details are used to deliver food to you, keep you updated about your orders, and send occasional offers (you can opt out at any time). We never sell your personal information to third parties.</p>

                <h3>3. Payment security</h3>
                <p>Cash is collected on delivery. Where digital wallets are used, payments are processed securely and we never store your wallet PIN or full payment credentials on our servers.</p>

                <h3>4. Data sharing</h3>
                <p>We share only what is needed — your name, address and order details — with the partner hotel preparing and delivering your order. We may share data with trusted service providers for fraud prevention or as required by law.</p>

                <h3>5. Cookies &amp; storage</h3>
                <p>We use a small session cookie to keep you signed in and remember preferences. Sensitive data like your password is stored using strong encryption, never in plain text.</p>

                <h3>6. Your rights</h3>
                <p>You can review your account details at any time, request a copy of your data, ask us to correct it, or ask us to delete your account. Contact our support team and we'll take care of it promptly.</p>

                <h3>7. Contact</h3>
                <p>Questions about privacy? Reach our support team at <strong>hello@lyaideu.com.np</strong> or call <strong>9800000001</strong>.</p>
            </div>
        </div>
    </section>

    <section class="section section-contact">
        <div class="container">
            <div class="faq-help-card">
                <span class="faq-help-ico"><i class="fa-solid fa-headset"></i></span>
                <h2 class="display">Still need help?</h2>
                <p class="section-sub">Our support team is ready to answer your questions.</p>
                <div class="faq-help-actions">
                    <a class="btn btn-primary" href="contact"><i class="fa-solid fa-phone"></i> Contact Us</a>
                    <button class="btn btn-outline cart-open-btn" type="button"><i class="fa-solid fa-cart-shopping"></i> Cart <span class="cart-count">0</span></button>
                </div>
            </div>
        </div>
    </section>
</main>

<button class="cart-fab cart-open-btn" type="button" aria-label="Open cart"><span class="cart-fab-icon"><i class="fa-solid fa-cart-shopping"></i></span><span class="cart-fab-label">Cart</span><span class="cart-count">0</span></button>
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-head"><h2><i class="fa-solid fa-cart-shopping"></i> Your Cart</h2><button type="button" class="cart-close" id="cartClose">×</button></div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Your cart is waiting for something tasty. <i class="fa-solid fa-pizza-slice"></i></div>
  <div class="cart-summary"><div class="summary-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0</strong></div><div class="summary-row"><span>Delivery</span><strong id="cartDelivery">Rs. 50</strong></div><div class="summary-row total"><span>Estimated total</span><strong id="cartTotal">Rs. 50</strong></div><a href="checkout" class="btn btn-primary btn-block" id="checkoutBtn">Checkout <i class="fa-solid fa-arrow-right"></i></a><button class="btn btn-outline btn-block" id="clearCart" type="button">Clear Cart</button></div>
</aside>
<footer class="footer">
    <div class="footer-grid">
        <div><p class="footer-brand"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu"></p><p class="footer-blurb">Nepal's friendliest food delivery service — connecting you to the best hotels in the valley.</p></div>
        <div><h4>Quick Links</h4><ul><li><a href="index">Home</a></li><li><a href="menu">Menu</a></li><li><a href="hotels">Hotels</a></li><li><a href="mart">Mart</a></li><li><a href="contact">Contact</a></li><li><a href="faq">FAQ &amp; Privacy</a></li><li><a href="terms">Terms of Service</a></li><li><a href="demo.html"><i class="fa-solid fa-film"></i> Product Demo</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> Birendranagar, Surkhet</li><li><i class="fa-solid fa-envelope"></i> hello@lyaideu.com.np</li><li><i class="fa-solid fa-phone"></i> 9800000001</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>Sun – Fri: 7 AM – 10 PM</li><li>Saturday: 8 AM – 10 PM</li><li><i class="fa-solid fa-motorcycle"></i> Deliveries every day!</li></ul></div>
    </div>
    <div class="footer-bottom">© <span id="year">2026</span> LyaiDeu · All rights reserved.</div>
</footer>

<script src="js/script.js?v=9"></script>
</body>
</html>