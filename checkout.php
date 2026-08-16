<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login?next=' . urlencode('checkout')); exit; }
if (!isset($_SESSION['csrf_order'])) $_SESSION['csrf_order'] = bin2hex(random_bytes(32));
$user = $_SESSION['user'];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_kyc_tables();
lyaideu_ensure_location_columns();
$profile = lyaideu_user_profile((int)$user['id']);
$kycStatus = $profile ? (string)$profile['kyc_status'] : 'none';
$kycVerified = $kycStatus === 'approved';
$profileAddress = $profile ? (string)$profile['address'] : '';
$homeLat = $profile ? (string)$profile['home_lat'] : '';
$homeLng = $profile ? (string)$profile['home_lng'] : '';
$prefillAddress = ($profile && trim((string)$profile['home_address']) !== '') ? (string)$profile['home_address'] : $profileAddress;
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title>Checkout | LyaiDeu</title><?= site_head_icons() ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=25">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head><body class="checkout-body">
<header class="topbar"><nav class="nav"><a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a><form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form><a class="btn btn-outline" href="menu"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a></nav></header>
<main class="checkout-page container">
  <div class="section-head"><p class="kicker"><i class="fa-solid fa-receipt"></i> Secure checkout</p><h1 class="display">Almost there, <?= htmlspecialchars($user['name']) ?>!</h1><p class="section-sub">Review your items, add delivery details, and place the order.</p></div>
  <?php if ($flash): ?><div class="flash-banner flash-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div><?php endif; ?>
  <?php if (!$kycVerified): ?>
  <div class="kyc-gate-banner <?= $kycStatus === 'rejected' ? 'is-rejected' : '' ?>">
    <i class="fa-solid fa-shield-halved"></i> <b>Identity verification required.</b>
    <?php if ($kycStatus === 'pending'): ?>Your KYC documents are under review — you'll be able to order once an admin verifies you.<?php elseif ($kycStatus === 'rejected'): ?>Your KYC was rejected. <?= htmlspecialchars((string)($profile['kyc_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Please fix the documents' ?> — update and resubmit from your profile.<?php else: ?>Complete your profile and upload your KYC documents before placing an order.<?php endif; ?>
    <a class="btn btn-primary btn-sm" href="profile"><i class="fa-solid fa-id-card"></i> Go to Profile</a>
  </div>
  <?php endif; ?>
  <div id="checkoutEmpty" class="empty-state"><span class="big"><i class="fa-solid fa-cart-shopping"></i></span><p>Your cart is empty.</p><a class="btn btn-primary" href="menu">Browse Menu</a></div>
  <form id="checkoutForm" action="order_save" method="POST" class="checkout-grid" data-kyc-ok="<?= $kycVerified ? '1' : '0' ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_order']) ?>">
    <input type="hidden" name="cart_json" id="cartJson"><input type="hidden" name="promo" id="promoHidden">
    <section class="checkout-card"><h2><i class="fa-solid fa-location-dot"></i> Delivery details</h2>
      <label>Full Name<input name="customer_name" required value="<?= htmlspecialchars($user['name']) ?>"></label>
      <label>Phone<input name="phone" required value="<?= htmlspecialchars($user['phone']) ?>" inputmode="numeric"></label>
      <div class="co-location">
        <p class="co-location-title"><i class="fa-solid fa-map-pin"></i> Delivery spot <span class="muted">— drag the pin or use your current location</span></p>
        <input type="hidden" name="delivery_lat" id="deliveryLat" value="<?= htmlspecialchars($homeLat, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="delivery_lng" id="deliveryLng" value="<?= htmlspecialchars($homeLng, ENT_QUOTES, 'UTF-8') ?>">
        <div id="deliveryMap" class="loc-map" data-home-lat="<?= htmlspecialchars($homeLat, ENT_QUOTES, 'UTF-8') ?>" data-home-lng="<?= htmlspecialchars($homeLng, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="map-actions">
          <button type="button" class="btn btn-outline" id="deliveryLocBtn"><i class="fa-solid fa-crosshairs"></i> Use my current location</button>
          <button type="button" class="btn btn-primary" id="deliveryLocOk"><i class="fa-solid fa-circle-check"></i> This location is correct</button>
        </div>
        <p class="small-note" id="deliveryLocMsg"></p>
      </div>
      <label>Delivery Address<textarea name="address" required placeholder="House / street / area / landmark"><?= htmlspecialchars($prefillAddress, ENT_QUOTES, 'UTF-8') ?></textarea></label>
      <label>Order Note <span class="muted">(optional)</span><textarea name="note" placeholder="Less spicy, call on arrival, etc."></textarea></label>
      <label>Payment Method<select name="payment" required><option value="Cash on Delivery">Cash on Delivery</option><option value="eSewa / Khalti on delivery">eSewa / Khalti on delivery</option></select></label>
    </section>
    <section class="checkout-card"><h2><i class="fa-solid fa-cart-shopping"></i> Your order</h2><div id="checkoutItems"></div>
      <div id="coVendorNote" class="vendor-note" style="display:none"></div>
      <div class="summary-row"><span>Subtotal</span><strong id="coSubtotal">Rs. 0</strong></div>
      <div class="summary-row"><span>Delivery</span><strong id="coDelivery">Rs. 50</strong></div>
      <div class="summary-row"><span>Estimated delivery</span><strong id="coEta">about 45 minutes</strong></div>
      <div class="promo-box"><input id="promoInput" type="text" placeholder="Promo code"><button type="button" class="btn btn-outline" id="promoBtn">Apply</button></div>
      <p id="promoMsg" class="small-note"></p>
      <div class="summary-row total"><span>Total</span><strong id="coTotal">Rs. 0</strong></div>
      <?php if ($kycVerified): ?>
      <button class="btn btn-primary btn-block" type="submit" id="placeOrderBtn"><i class="fa-solid fa-rocket"></i> Place Order</button>
      <?php else: ?>
      <a class="btn btn-primary btn-block" href="profile"><i class="fa-solid fa-shield-halved"></i> Complete KYC to Order</a>
      <?php endif; ?>
      <p class="small-note">Demo payment flow: no real payment is processed.</p>
    </section>
  </form>
</main><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var mapEl = document.getElementById('deliveryMap');
    if (!mapEl || typeof L === 'undefined') return;
    var latIn = document.getElementById('deliveryLat'),
        lngIn = document.getElementById('deliveryLng'),
        addrIn = document.querySelector('#checkoutForm textarea[name=address]'),
        msg = document.getElementById('deliveryLocMsg'),
        locBtn = document.getElementById('deliveryLocBtn'),
        okBtn = document.getElementById('deliveryLocOk');
    var homeLat = parseFloat(mapEl.getAttribute('data-home-lat')),
        homeLng = parseFloat(mapEl.getAttribute('data-home-lng'));
    var saved = window.LYAIDEU_LOC && window.LYAIDEU_LOC.getSaved();
    var startLat, startLng, hasPin;
    if (!isNaN(homeLat) && !isNaN(homeLng)) { startLat = homeLat; startLng = homeLng; hasPin = true; }
    else if (saved) { startLat = saved.lat; startLng = saved.lng; hasPin = true; latIn.value = saved.lat.toFixed(7); lngIn.value = saved.lng.toFixed(7); }
    else { startLat = 28.5967; startLng = 81.6166; hasPin = false; }
    var map = L.map('deliveryMap', { scrollWheelZoom: false, attributionControl: false }).setView([startLat, startLng], hasPin ? 15 : 14);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    var marker = L.marker([startLat, startLng], { draggable: true }).addTo(map).bindPopup('Drag to your exact delivery spot');
    function setPos(lat, lng, reverse) {
        marker.setLatLng([lat, lng]);
        map.panTo([lat, lng]);
        latIn.value = lat.toFixed(7);
        lngIn.value = lng.toFixed(7);
        if (msg) msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> Delivery spot set — you can adjust it anytime.';
        if (reverse && window.fetch && addrIn) {
            fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + lat + '&lon=' + lng, { headers: { 'Accept-Language': 'en' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var a = d && d.display_name;
                    if (a && !addrIn.value.trim()) addrIn.value = a.split(',').slice(0, 3).join(',');
                })
                .catch(function () {});
        }
    }
    marker.on('dragend', function () { var ll = marker.getLatLng(); setPos(ll.lat, ll.lng, true); });
    if (locBtn) locBtn.addEventListener('click', function () {
        var b = this;
        b.disabled = true;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating…';
        window.LYAIDEU_LOC.request(function (err, pos) {
            b.disabled = false;
            b.innerHTML = '<i class="fa-solid fa-crosshairs"></i> Use my current location';
            if (err) {
                if (msg) msg.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Could not get your location. Check the browser permission or type the address below.';
                return;
            }
            setPos(pos.lat, pos.lng, true);
        });
    });
    if (okBtn) okBtn.addEventListener('click', function () {
        if (!latIn.value || !lngIn.value) {
            if (msg) msg.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Drop the pin or use your current location first.';
            return;
        }
        if (msg) msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> Delivery spot confirmed. You can now place your order.';
        okBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Location confirmed ✓';
    });
})();
</script>
<script src="js/script.js?v=18"></script>
<script src="js/scroll-memory.js?v=3"></script>
<script src="js/notify.js?v=4"></script></body></html>