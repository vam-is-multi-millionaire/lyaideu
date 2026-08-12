<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
if (!hash_equals($_SESSION['csrf_order'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid checkout token.'); }

require_once __DIR__ . '/db.php';

function clean_text($v): string { return trim(strip_tags((string)$v)); }
function clean_phone($v): string { return preg_replace('/[^0-9]/','',(string)$v); }
function flash_checkout(string $msg): void { $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg]; header('Location: checkout.php'); exit; }

$cart = json_decode($_POST['cart_json'] ?? '[]', true);
if (!is_array($cart) || empty($cart)) {
    flash_checkout('Your cart is empty.');
}

$items = [];
$subtotal = 0;
$dishStmt = $pdo->prepare('SELECT id, name, hotel, price FROM dishes WHERE id = ? LIMIT 1');
$martStmt = $pdo->prepare('SELECT id, name, price FROM mart_items WHERE id = ? LIMIT 1');

foreach ($cart as $row) {
    $id = (int)($row['id'] ?? 0);
    $qty = max(1, min(20, (int)($row['qty'] ?? 1)));
    $type = ($row['type'] ?? '') === 'mart' ? 'mart' : 'dish';
    if ($id <= 0) {
        continue;
    }

    if ($type === 'mart') {
        $martStmt->execute([$id]);
        $d = $martStmt->fetch();
        if (!$d) {
            continue;
        }
        $item = [
            'dish_id' => null,
            'name' => $d['name'],
            'hotel' => 'LyaiDeu Mart',
            'price' => (int)$d['price'],
        ];
    } else {
        $dishStmt->execute([$id]);
        $d = $dishStmt->fetch();
        if (!$d) {
            continue;
        }
        $item = [
            'dish_id' => (int)$d['id'],
            'name' => $d['name'],
            'hotel' => $d['hotel'],
            'price' => (int)$d['price'],
        ];
    }

    $line = $item['price'] * $qty;
    $subtotal += $line;
    $items[] = [
        'dish_id' => $item['dish_id'],
        'name' => $item['name'],
        'hotel' => $item['hotel'],
        'price' => $item['price'],
        'qty' => $qty,
        'line_total' => $line,
    ];
}

if (!$items) {
    flash_checkout('No valid items were found in your cart.');
}

$promo = strtoupper(trim(clean_text($_POST['promo'] ?? '')));
$delivery = 50;
$discount = ($promo === 'LYAIDEU' || $promo === 'FOODXPRESS') ? 50 : 0;
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
    'discount' => $discount,
    'total' => $total,
    'status' => 'Pending',
    'created_at' => date('Y-m-d H:i:s'),
];
$order['updated_at'] = $order['created_at'];

if ($order['address'] === '') {
    flash_checkout('Please enter your delivery address.');
}

try {
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'INSERT INTO orders
            (user_id, customer_name, phone, address, note, payment, promo, subtotal, delivery_fee, discount, total, status, created_at, updated_at)
         VALUES
            (:user_id, :customer_name, :phone, :address, :note, :payment, :promo, :subtotal, :delivery_fee, :discount, :total, :status, :created_at, :updated_at)'
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
        ':discount' => $order['discount'],
        ':total' => $order['total'],
        ':status' => $order['status'],
        ':created_at' => $order['created_at'],
        ':updated_at' => $order['updated_at'],
    ]);

    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, dish_id, name, hotel, price, qty, line_total)
         VALUES (:order_id, :dish_id, :name, :hotel, :price, :qty, :line_total)'
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
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('Could not save order.');
}

$_SESSION['last_order_id'] = $orderId;
header('Location: order_success.php?id=' . $orderId);
exit;