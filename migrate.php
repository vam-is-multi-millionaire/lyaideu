<?php

define('LYAIDEU_DB_THROW', true);

$results = [];

function add_result(string $table, bool $ok, string $message): void {
    global $results;
    $results[] = ['table' => $table, 'ok' => $ok, 'message' => $message];
}

function json_datetime($value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($dt && $dt->format($format) === $value) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

try {
    require_once __DIR__ . '/db.php';
} catch (Throwable $e) {
    add_result('database', false, $e->getMessage());
    $pdo = null;
}

$dataFile = __DIR__ . '/data.json';
$data = [];

if (!is_file($dataFile)) {
    add_result('data.json', false, 'data.json was not found.');
} else {
    $decoded = json_decode(file_get_contents($dataFile), true);
    if (!is_array($decoded)) {
        add_result('data.json', false, 'data.json could not be decoded.');
    } else {
        $data = $decoded + ['dishes' => [], 'hotels' => [], 'contacts' => [], 'users' => [], 'orders' => []];
        add_result('data.json', true, 'Loaded existing JSON data.');
    }
}

if ($pdo instanceof PDO && $data) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO dishes (id, name, hotel, cat, price, phone, tag, `desc`, img)
             VALUES (:id, :name, :hotel, :cat, :price, :phone, :tag, :descr, :img)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                hotel = VALUES(hotel),
                cat = VALUES(cat),
                price = VALUES(price),
                phone = VALUES(phone),
                tag = VALUES(tag),
                `desc` = VALUES(`desc`),
                img = VALUES(img)'
        );

        foreach ($data['dishes'] as $dish) {
            $stmt->execute([
                ':id' => (int)($dish['id'] ?? 0),
                ':name' => (string)($dish['name'] ?? ''),
                ':hotel' => (string)($dish['hotel'] ?? ''),
                ':cat' => (string)($dish['cat'] ?? ''),
                ':price' => (int)($dish['price'] ?? 0),
                ':phone' => (string)($dish['phone'] ?? ''),
                ':tag' => (string)($dish['tag'] ?? ''),
                ':descr' => (string)($dish['desc'] ?? ''),
                ':img' => (string)($dish['img'] ?? ''),
            ]);
        }
        add_result('dishes', true, count($data['dishes']) . ' rows migrated.');
    } catch (Throwable $e) {
        add_result('dishes', false, $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hotels (id, name, type, phone, emoji)
             VALUES (:id, :name, :type, :phone, :emoji)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                type = VALUES(type),
                phone = VALUES(phone),
                emoji = VALUES(emoji)'
        );

        foreach ($data['hotels'] as $i => $hotel) {
            $stmt->execute([
                ':id' => (int)($hotel['id'] ?? ($i + 1)),
                ':name' => (string)($hotel['name'] ?? ''),
                ':type' => (string)($hotel['type'] ?? ''),
                ':phone' => (string)($hotel['phone'] ?? ''),
                ':emoji' => (string)($hotel['emoji'] ?? ''),
            ]);
        }
        add_result('hotels', true, count($data['hotels']) . ' rows migrated.');
    } catch (Throwable $e) {
        add_result('hotels', false, $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO contacts (id, role, person, phone, note, ico)
             VALUES (:id, :role, :person, :phone, :note, :ico)
             ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                person = VALUES(person),
                phone = VALUES(phone),
                note = VALUES(note),
                ico = VALUES(ico)'
        );

        foreach ($data['contacts'] as $i => $contact) {
            $stmt->execute([
                ':id' => (int)($contact['id'] ?? ($i + 1)),
                ':role' => (string)($contact['role'] ?? ''),
                ':person' => (string)($contact['person'] ?? ''),
                ':phone' => (string)($contact['phone'] ?? ''),
                ':note' => (string)($contact['note'] ?? ''),
                ':ico' => (string)($contact['ico'] ?? ''),
            ]);
        }
        add_result('contacts', true, count($data['contacts']) . ' rows migrated.');
    } catch (Throwable $e) {
        add_result('contacts', false, $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, name, email, phone, dob, pass, created_at)
             VALUES (:id, :name, :email, :phone, :dob, :pass, :created_at)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                email = VALUES(email),
                phone = VALUES(phone),
                dob = VALUES(dob),
                pass = VALUES(pass),
                created_at = VALUES(created_at)'
        );

        foreach ($data['users'] as $user) {
            $stmt->execute([
                ':id' => (int)($user['id'] ?? 0),
                ':name' => (string)($user['name'] ?? ''),
                ':email' => (string)($user['email'] ?? ''),
                ':phone' => (string)($user['phone'] ?? ''),
                ':dob' => (string)($user['dob'] ?? ''),
                ':pass' => (string)($user['pass'] ?? ''),
                ':created_at' => json_datetime($user['created_at'] ?? $user['created'] ?? '') ?? date('Y-m-d H:i:s'),
            ]);
        }
        add_result('users', true, count($data['users']) . ' rows migrated.');
    } catch (Throwable $e) {
        add_result('users', false, $e->getMessage());
    }

    try {
        $userIds = array_fill_keys(
            array_map('intval', $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN)),
            true
        );
        $dishIds = array_fill_keys(
            array_map('intval', $pdo->query('SELECT id FROM dishes')->fetchAll(PDO::FETCH_COLUMN)),
            true
        );

        $orderStmt = $pdo->prepare(
            'INSERT INTO orders
                (id, user_id, customer_name, phone, address, note, payment, promo, subtotal, delivery_fee, discount, total, status, created_at, updated_at)
             VALUES
                (:id, :user_id, :customer_name, :phone, :address, :note, :payment, :promo, :subtotal, :delivery_fee, :discount, :total, :status, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                customer_name = VALUES(customer_name),
                phone = VALUES(phone),
                address = VALUES(address),
                note = VALUES(note),
                payment = VALUES(payment),
                promo = VALUES(promo),
                subtotal = VALUES(subtotal),
                delivery_fee = VALUES(delivery_fee),
                discount = VALUES(discount),
                total = VALUES(total),
                status = VALUES(status),
                created_at = VALUES(created_at),
                updated_at = VALUES(updated_at)'
        );
        $deleteItems = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, dish_id, name, hotel, price, qty, line_total)
             VALUES (:order_id, :dish_id, :name, :hotel, :price, :qty, :line_total)'
        );

        $itemCount = 0;
        $pdo->beginTransaction();
        foreach ($data['orders'] as $order) {
            $orderId = (int)($order['id'] ?? 0);
            $createdAt = json_datetime($order['created_at'] ?? $order['created'] ?? '') ?? date('Y-m-d H:i:s');
            $updatedAt = json_datetime($order['updated_at'] ?? $order['updated'] ?? '') ?? $createdAt;
            $userId = (int)($order['user_id'] ?? 0);

            $orderStmt->execute([
                ':id' => $orderId,
                ':user_id' => isset($userIds[$userId]) ? $userId : null,
                ':customer_name' => (string)($order['customer_name'] ?? ''),
                ':phone' => (string)($order['phone'] ?? ''),
                ':address' => (string)($order['address'] ?? ''),
                ':note' => (string)($order['note'] ?? ''),
                ':payment' => (string)($order['payment'] ?? ''),
                ':promo' => (string)($order['promo'] ?? ''),
                ':subtotal' => (float)($order['subtotal'] ?? 0),
                ':delivery_fee' => (float)($order['delivery_fee'] ?? 0),
                ':discount' => (float)($order['discount'] ?? 0),
                ':total' => (float)($order['total'] ?? 0),
                ':status' => (string)($order['status'] ?? 'Pending'),
                ':created_at' => $createdAt,
                ':updated_at' => $updatedAt,
            ]);

            $deleteItems->execute([$orderId]);
            foreach (($order['items'] ?? []) as $item) {
                $dishId = (int)($item['dish_id'] ?? 0);
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':dish_id' => isset($dishIds[$dishId]) ? $dishId : null,
                    ':name' => (string)($item['name'] ?? ''),
                    ':hotel' => (string)($item['hotel'] ?? ''),
                    ':price' => (float)($item['price'] ?? 0),
                    ':qty' => (int)($item['qty'] ?? 1),
                    ':line_total' => (float)($item['line_total'] ?? 0),
                ]);
                $itemCount++;
            }
        }
        $pdo->commit();
        add_result('orders', true, count($data['orders']) . ' orders and ' . $itemCount . ' items migrated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        add_result('orders', false, $e->getMessage());
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LyaiDeu MySQL Migration</title>
<style>
body{font-family:Arial,sans-serif;background:#faf3eb;margin:0;padding:2rem;color:#3b2413}
main{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e8dccb;border-radius:8px;padding:1.5rem}
h1{margin-top:0}
.row{display:flex;gap:1rem;justify-content:space-between;border-top:1px solid #f2e8d8;padding:.9rem 0}
.ok{color:#19733a;font-weight:700}
.fail{color:#b42318;font-weight:700}
.note{color:#7d634b}
</style>
</head>
<body>
<main>
<h1>LyaiDeu MySQL Migration</h1>
<p class="note">This imports records from <code>data.json</code> into <code>lyaideudb</code>. Delete this file after a successful migration.</p>
<?php foreach ($results as $result): ?>
    <div class="row">
        <strong><?= htmlspecialchars($result['table'], ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="<?= $result['ok'] ? 'ok' : 'fail' ?>"><?= $result['ok'] ? 'OK' : 'ERROR' ?></span>
    </div>
    <p class="note"><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endforeach; ?>
</main>
</body>
</html>
