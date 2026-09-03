<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout'); exit; }
if (!hash_equals($_SESSION['csrf_order'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid checkout token.'); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_delivery_tables();
lyaideu_ensure_kyc_tables();
lyaideu_ensure_location_columns();
lyaideu_ensure_variant_tables();

function clean_text($v): string { return trim(strip_tags((string)$v)); }
function clean_phone($v): string { return preg_replace('/[^0-9]/','',(string)$v); }
function flash_checkout(string $msg): void { $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg]; header('Location: checkout'); exit; }

$kycUser = lyaideu_user_profile((int)$_SESSION['user']['id']);
$kycStatus = $kycUser ? (string)$kycUser['kyc_status'] : 'none';
/* Control Panel toggle: when KYC is switched OFF, everyone can order. */
if (lyaideu_kyc_required() && $kycStatus !== 'approved') {
    if ($kycStatus === 'pending') {
        $msg = 'Your KYC documents are still under review. You can order once an admin verifies your identity.';
    } elseif ($kycStatus === 'rejected') {
        $msg = 'Your KYC was rejected. Please update your documents and resubmit from your profile.';
    } else {
        $msg = 'Please complete your profile and KYC verification before placing an order.';
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg];
    header('Location: profile');
    exit;
}

$cart = json_decode($_POST['cart_json'] ?? '[]', true);
if (!is_array($cart) || empty($cart)) {
    flash_checkout('Your cart is empty.');
}

$items = [];
$subtotal = 0;
/* Control Panel: items inside switched-off category subtrees can't be
   ordered — silently dropped like items that no longer exist. */
function order_item_available(array $d): bool {
    $cid = (int)($d['category_id'] ?? 0);
    return $cid <= 0 || lyaideu_category_is_active($cid);
}

$dishStmt = $pdo->prepare('SELECT id, name, hotel, price, discount_percent, vendor_id, has_variants, category_id FROM dishes WHERE id = ? LIMIT 1');
$martStmt = $pdo->prepare('SELECT id, name, price, discount_percent, has_variants, category_id FROM mart_items WHERE id = ? LIMIT 1');
$otherStmt = $pdo->prepare('SELECT id, name, price, discount_percent, has_variants, category_id FROM other_items WHERE id = ? LIMIT 1');
$beverageStmt = $pdo->prepare('SELECT id, name, price, discount_percent, has_variants, category_id FROM beverage_items WHERE id = ? LIMIT 1');

function resolve_variant_price(PDO $pdo, string $type, int $id, string $variant): ?int {
    $st = $pdo->prepare('SELECT price FROM product_variants WHERE item_type = ? AND item_id = ? AND label = ? LIMIT 1');
    $st->execute([$type, $id, $variant]);
    $price = $st->fetchColumn();
    return ($price === false) ? null : (int)$price;
}

foreach ($cart as $row) {
    $id = (int)($row['id'] ?? 0);
    $qty = max(1, min(20, (int)($row['qty'] ?? 1)));
    $rawType = (string)($row['type'] ?? 'dish');
    $type = in_array($rawType, ['mart', 'other', 'beverage'], true) ? $rawType : 'dish';
    $variant = trim(strip_tags((string)($row['variant'] ?? '')));
    if ($id <= 0) {
        continue;
    }

    $item = null;
    if ($type === 'mart') {
        $martStmt->execute([$id]);
        $d = $martStmt->fetch();
        if (!$d || !order_item_available($d)) {
            continue;
        }
        $item = [
            'dish_id' => null,
            'name' => $d['name'],
            'hotel' => lyaideu_mart_store_name($id),
            'price' => (int)$d['price'],
            'vendor_id' => lyaideu_resolve_mart_vendor($id),
            'has_variants' => (int)($d['has_variants'] ?? 0),
        ];
    } elseif ($type === 'other') {
        lyaideu_ensure_other_table();
        $otherStmt->execute([$id]);
        $d = $otherStmt->fetch();
        if (!$d || !order_item_available($d)) {
            continue;
        }
        $item = [
            'dish_id' => null,
            'name' => $d['name'],
            'hotel' => lyaideu_other_store_name($id),
            'price' => (int)$d['price'],
            'vendor_id' => lyaideu_resolve_other_vendor($id),
            'has_variants' => (int)($d['has_variants'] ?? 0),
        ];
    } elseif ($type === 'beverage') {
        lyaideu_ensure_beverage_table();
        $beverageStmt->execute([$id]);
        $d = $beverageStmt->fetch();
        if (!$d || !order_item_available($d)) {
            continue;
        }
        $item = [
            'dish_id' => null,
            'name' => $d['name'],
            'hotel' => lyaideu_beverage_store_name($id),
            'price' => (int)$d['price'],
            'vendor_id' => lyaideu_resolve_beverage_vendor($id),
            'has_variants' => (int)($d['has_variants'] ?? 0),
        ];
    } else {
        $dishStmt->execute([$id]);
        $d = $dishStmt->fetch();
        if (!$d || !order_item_available($d)) {
            continue;
        }
        $vid = (int)($d['vendor_id'] ?? 0);
        if ($vid <= 0) {
            $vid = lyaideu_resolve_dish_vendor((int)$d['id']);
        }
        $item = [
            'dish_id' => (int)$d['id'],
            'name' => $d['name'],
            'hotel' => $d['hotel'],
            'price' => (int)$d['price'],
            'vendor_id' => $vid,
            'has_variants' => (int)($d['has_variants'] ?? 0),
        ];
    }

    $price = (int)$item['price'];
    if (!empty($item['has_variants'])) {
        if ($variant === '') {
            flash_checkout('Please choose a size / quantity option for "' . $item['name'] . '" before placing your order.');
        }
        $variantPrice = resolve_variant_price($pdo, $type, $id, $variant);
        if ($variantPrice === null) {
            flash_checkout('"' . $item['name'] . '" no longer has the selected option. Please pick an available option in your cart.');
        }
        $price = $variantPrice;
    }

    /* Apply the product's discount percent server-side so stored order prices
       always match the discounted price shown in the storefront. */
    $price = lyaideu_deal_price($price, (int)($d['discount_percent'] ?? 0));

    $line = $price * $qty;
    $subtotal += $line;
    $items[] = [
        'dish_id' => $item['dish_id'],
        'name' => $item['name'],
        'hotel' => $item['hotel'],
        'price' => $price,
        'qty' => $qty,
        'line_total' => $line,
        'vendor_id' => $item['vendor_id'] ?? 0,
        'variant' => $variant,
    ];
}

if (!$items) {
    flash_checkout('No valid items were found in your cart.');
}

$shopNames = [];
$hasHotel = false;
foreach ($items as $it) {
    $shopNames[$it['hotel']] = true;
    if (!empty($it['dish_id'])) {
        $hasHotel = true;
    }
}
$shopCount = count($shopNames);
$delivery = lyaideu_delivery_fee($shopCount);
$eta = lyaideu_delivery_eta($shopCount, $hasHotel);

/* Promo codes are re-validated authoritatively here — the browser's copy is
   never trusted. Runs BEFORE beginTransaction so its table-ensure can never
   trigger DDL inside the transaction below. */
lyaideu_ensure_promo_table();
$promo = strtoupper(trim(clean_text($_POST['promo'] ?? '')));
$discount = 0;
if ($promo !== '') {
    $promoCheck = lyaideu_promo_evaluate($promo, $subtotal, (int)$_SESSION['user']['id']);
    if (!empty($promoCheck['ok']) && !empty($promoCheck['promo'])) {
        $discount = (int)$promoCheck['promo']['discount'];
        if (!empty($promoCheck['promo']['free_delivery'])) {
            $delivery = 0;
        }
    } else {
        /* Invalid/expired/limit-reached codes are silently dropped from the
           stored order — totals below always reflect what was actually paid. */
        $promo = '';
    }
}
$total = max(0, $subtotal + $delivery - $discount);

$order = [
    'user_id' => (int)$_SESSION['user']['id'],
    'customer_name' => clean_text($_POST['customer_name'] ?? $_SESSION['user']['name']),
    'phone' => clean_phone($_POST['phone'] ?? $_SESSION['user']['phone']),
    'address' => clean_text($_POST['address'] ?? ''),
    'note' => clean_text($_POST['note'] ?? ''),
    'payment' => clean_text($_POST['payment'] ?? 'Cash on Delivery'),
    'promo' => $promo,
    'subtotal' => $subtotal,
    'delivery_fee' => $delivery,
    'eta_minutes' => $eta,
    'discount' => $discount,
    'total' => $total,
    'status' => 'Pending',
    'created_at' => date('Y-m-d H:i:s'),
];
$order['updated_at'] = $order['created_at'];

if ($order['address'] === '') {
    flash_checkout('Please enter your delivery address.');
}

$deliveryLat = null;
$deliveryLng = null;
$rawLat = trim((string)($_POST['delivery_lat'] ?? ''));
$rawLng = trim((string)($_POST['delivery_lng'] ?? ''));
if ($rawLat !== '' || $rawLng !== '') {
    if (!lyaideu_valid_coord($rawLat, true) || !lyaideu_valid_coord($rawLng, false)) {
        flash_checkout('The delivery location on the map is invalid. Please set it again on the checkout map.');
    }
    $deliveryLat = (float)$rawLat;
    $deliveryLng = (float)$rawLng;
}

try {
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'INSERT INTO orders
            (user_id, customer_name, phone, address, note, payment, promo, subtotal, delivery_fee, eta_minutes, discount, total, delivery_lat, delivery_lng, status, created_at, updated_at)
         VALUES
            (:user_id, :customer_name, :phone, :address, :note, :payment, :promo, :subtotal, :delivery_fee, :eta_minutes, :discount, :total, :delivery_lat, :delivery_lng, :status, :created_at, :updated_at)'
    );
    $orderStmt->execute([
        ':user_id' => $order['user_id'],
        ':customer_name' => $order['customer_name'],
        ':phone' => $order['phone'],
        ':address' => $order['address'],
        ':note' => $order['note'],
        ':payment' => $order['payment'],
        ':promo' => $order['promo'],
        ':subtotal' => $order['subtotal'],
        ':delivery_fee' => $order['delivery_fee'],
        ':eta_minutes' => $order['eta_minutes'],
        ':discount' => $order['discount'],
        ':total' => $order['total'],
        ':delivery_lat' => $deliveryLat,
        ':delivery_lng' => $deliveryLng,
        ':status' => $order['status'],
        ':created_at' => $order['created_at'],
        ':updated_at' => $order['updated_at'],
    ]);

    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, dish_id, name, hotel, price, qty, line_total, vendor_id, variant)
         VALUES (:order_id, :dish_id, :name, :hotel, :price, :qty, :line_total, :vendor_id, :variant)'
    );

    foreach ($items as $item) {
        $itemStmt->execute([
            ':order_id' => $orderId,
            ':dish_id' => $item['dish_id'],
            ':name' => $item['name'],
            ':hotel' => $item['hotel'],
            ':price' => $item['price'],
            ':qty' => $item['qty'],
            ':line_total' => $item['line_total'],
            ':vendor_id' => $item['vendor_id'] > 0 ? $item['vendor_id'] : null,
            ':variant' => $item['variant'] ?? '',
        ]);
    }

    if ($promo !== '') {
        $pdo->prepare(
            'UPDATE promo_codes SET used_count = used_count + 1 WHERE code = :c AND (usage_limit = 0 OR used_count < usage_limit)'
        )->execute([':c' => $promo]);
    }

    $pdo->commit();
    try { if (function_exists('lyaideu_log_activity')) lyaideu_log_activity('order.create','order',(int)$orderId,['total'=>$order['total'],'subtotal'=>$order['subtotal'],'delivery_fee'=>$order['delivery_fee']]); } catch (Throwable $e) {}
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('Could not save order.');
}

lyaideu_auto_assign_vendor($orderId);
lyaideu_seed_order_vendor_status($orderId);

$vendorSummary = lyaideu_order_vendor_summary($orderId);
foreach (lyaideu_order_vendor_ids($orderId) as $vid) {
    lyaideu_notify($orderId, 'vendor', $vid, 'New order #' . $orderId . ' — ' . lyaideu_order_vendor_summary($orderId, (int)$vid) . ' · Rs. ' . $total, 'vendor');
}
lyaideu_notify_riders($orderId, 'New order #' . $orderId . ' — ' . $vendorSummary . ' · Rs. ' . $total . '. Check your queue.', 'rider');

$_SESSION['last_order_id'] = $orderId;
header('Location: order_success?id=' . $orderId);
exit;