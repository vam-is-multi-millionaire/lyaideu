<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
if (!isset($_SESSION['csrf_order'])) $_SESSION['csrf_order'] = bin2hex(random_bytes(32));
$user = $_SESSION['user'];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout | LyaiDeu</title><link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
</head><body class="checkout-body">
<header class="topbar"><nav class="nav"><a class="brand" href="index.php"><img class="brand-logo" src="logo.png" alt="LyaiDeu"></a><a class="btn btn-outline" href="index.php#menu"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a></nav></header>
<main class="checkout-page container">
  <div class="section-head"><p class="kicker"><i class="fa-solid fa-receipt"></i> Secure checkout</p><h1 class="display">Almost there, <?= htmlspecialchars($user['name']) ?>!</h1><p class="section-sub">Review your items, add delivery details, and place the order.</p></div>
  <?php if ($flash): ?><div class="flash-banner flash-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div><?php endif; ?>
  <div id="checkoutEmpty" class="empty-state"><span class="big"><i class="fa-solid fa-cart-shopping"></i></span><p>Your cart is empty.</p><a class="btn btn-primary" href="index.php#menu">Browse Menu</a></div>
  <form id="checkoutForm" action="order_save.php" method="POST" class="checkout-grid">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_order']) ?>">
    <input type="hidden" name="cart_json" id="cartJson"><input type="hidden" name="promo" id="promoHidden">
    <section class="checkout-card"><h2><i class="fa-solid fa-location-dot"></i> Delivery details</h2>
      <label>Full Name<input name="customer_name" required value="<?= htmlspecialchars($user['name']) ?>"></label>
      <label>Phone<input name="phone" required value="<?= htmlspecialchars($user['phone']) ?>" inputmode="numeric"></label>
      <label>Delivery Address<textarea name="address" required placeholder="House / street / area / landmark"></textarea></label>
      <label>Order Note <span class="muted">(optional)</span><textarea name="note" placeholder="Less spicy, call on arrival, etc."></textarea></label>
      <label>Payment Method<select name="payment" required><option value="Cash on Delivery">Cash on Delivery</option><option value="eSewa / Khalti on delivery">eSewa / Khalti on delivery</option></select></label>
    </section>
    <section class="checkout-card"><h2><i class="fa-solid fa-cart-shopping"></i> Your order</h2><div id="checkoutItems"></div>
      <div class="summary-row"><span>Subtotal</span><strong id="coSubtotal">Rs. 0</strong></div>
      <div class="summary-row"><span>Delivery</span><strong id="coDelivery">Rs. 50</strong></div>
      <div class="promo-box"><input id="promoInput" type="text" placeholder="Promo code"><button type="button" class="btn btn-outline" id="promoBtn">Apply</button></div>
      <p id="promoMsg" class="small-note"></p>
      <div class="summary-row total"><span>Total</span><strong id="coTotal">Rs. 0</strong></div>
      <button class="btn btn-primary btn-block" type="submit" id="placeOrderBtn"><i class="fa-solid fa-rocket"></i> Place Order</button>
      <p class="small-note">Demo payment flow: no real payment is processed.</p>
    </section>
  </form>
</main><script src="js/script.js"></script></body></html>