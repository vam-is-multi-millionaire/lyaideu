<?php
session_start();

if (!isset($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please reload the admin panel and try again.');
}

require_once __DIR__ . '/db.php';

function clean_text($value): string {
    return trim(strip_tags((string)$value));
}

function clean_phone($value): string {
    return preg_replace('/[^0-9+]/', '', (string)$value);
}

function clean_url($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(https?://|/|\./|\.\./)#i', $value)) {
        return $value;
    }
    return '';
}

function valid_category($value): string {
    $allowed = ['momo', 'pizza', 'chowmein', 'snacks', 'beverages', 'dinner'];
    return in_array($value, $allowed, true) ? $value : 'snacks';
}

try {
    $pdo->beginTransaction();

    $deleteDish = $pdo->prepare('DELETE FROM dishes WHERE id = ?');
    $updateDish = $pdo->prepare(
        'UPDATE dishes
         SET name = :name, hotel = :hotel, cat = :cat, price = :price, phone = :phone,
             tag = :tag, `desc` = :descr, img = :img
         WHERE id = :id'
    );
    $insertDish = $pdo->prepare(
        'INSERT INTO dishes (name, hotel, cat, price, phone, tag, `desc`, img)
         VALUES (:name, :hotel, :cat, :price, :phone, :tag, :descr, :img)'
    );

    foreach (($_POST['dishes'] ?? []) as $d) {
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!empty($d['delete'])) {
            $deleteDish->execute([$id]);
            continue;
        }

        $name = clean_text($d['name'] ?? '');
        $hotel = clean_text($d['hotel'] ?? '');
        if ($name === '' || $hotel === '') {
            continue;
        }

        $updateDish->execute([
            ':id' => $id,
            ':name' => $name,
            ':hotel' => $hotel,
            ':cat' => valid_category($d['cat'] ?? 'snacks'),
            ':price' => max(0, (int)($d['price'] ?? 0)),
            ':phone' => clean_phone($d['phone'] ?? ''),
            ':tag' => clean_text($d['tag'] ?? ''),
            ':descr' => clean_text($d['desc'] ?? ''),
            ':img' => clean_url($d['img'] ?? ''),
        ]);
    }

    $newDish = $_POST['new_dish'] ?? [];
    if (clean_text($newDish['name'] ?? '') !== '') {
        $insertDish->execute([
            ':name' => clean_text($newDish['name'] ?? ''),
            ':hotel' => clean_text($newDish['hotel'] ?? ''),
            ':cat' => valid_category($newDish['cat'] ?? 'snacks'),
            ':price' => max(0, (int)($newDish['price'] ?? 0)),
            ':phone' => clean_phone($newDish['phone'] ?? ''),
            ':tag' => clean_text($newDish['tag'] ?? ''),
            ':descr' => clean_text($newDish['desc'] ?? ''),
            ':img' => clean_url($newDish['img'] ?? ''),
        ]);
    }

    $deleteHotel = $pdo->prepare('DELETE FROM hotels WHERE id = ?');
    $updateHotel = $pdo->prepare(
        'UPDATE hotels SET name = :name, type = :type, phone = :phone, emoji = :emoji WHERE id = :id'
    );
    $insertHotel = $pdo->prepare(
        'INSERT INTO hotels (name, type, phone, emoji) VALUES (:name, :type, :phone, :emoji)'
    );

    foreach (($_POST['hotels'] ?? []) as $h) {
        $id = (int)($h['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!empty($h['delete'])) {
            $deleteHotel->execute([$id]);
            continue;
        }

        $name = clean_text($h['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $updateHotel->execute([
            ':id' => $id,
            ':name' => $name,
            ':type' => clean_text($h['type'] ?? ''),
            ':phone' => clean_phone($h['phone'] ?? ''),
            ':emoji' => clean_text($h['emoji'] ?? ''),
        ]);
    }

    $newHotel = $_POST['new_hotel'] ?? [];
    if (clean_text($newHotel['name'] ?? '') !== '') {
        $insertHotel->execute([
            ':name' => clean_text($newHotel['name'] ?? ''),
            ':type' => clean_text($newHotel['type'] ?? ''),
            ':phone' => clean_phone($newHotel['phone'] ?? ''),
            ':emoji' => clean_text($newHotel['emoji'] ?? '🏨'),
        ]);
    }

    $deleteContact = $pdo->prepare('DELETE FROM contacts WHERE id = ?');
    $updateContact = $pdo->prepare(
        'UPDATE contacts SET role = :role, person = :person, phone = :phone, note = :note, ico = :ico WHERE id = :id'
    );
    $insertContact = $pdo->prepare(
        'INSERT INTO contacts (role, person, phone, note, ico) VALUES (:role, :person, :phone, :note, :ico)'
    );

    foreach (($_POST['contacts'] ?? []) as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!empty($c['delete'])) {
            $deleteContact->execute([$id]);
            continue;
        }

        $role = clean_text($c['role'] ?? '');
        if ($role === '') {
            continue;
        }

        $updateContact->execute([
            ':id' => $id,
            ':role' => $role,
            ':person' => clean_text($c['person'] ?? ''),
            ':phone' => clean_phone($c['phone'] ?? ''),
            ':note' => clean_text($c['note'] ?? ''),
            ':ico' => clean_text($c['ico'] ?? '📞'),
        ]);
    }

    $newContact = $_POST['new_contact'] ?? [];
    if (clean_text($newContact['role'] ?? '') !== '') {
        $insertContact->execute([
            ':role' => clean_text($newContact['role'] ?? ''),
            ':person' => clean_text($newContact['person'] ?? ''),
            ':phone' => clean_phone($newContact['phone'] ?? ''),
            ':note' => clean_text($newContact['note'] ?? ''),
            ':ico' => clean_text($newContact['ico'] ?? '📞'),
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: admin.php?error=' . urlencode('Could not save changes. Please try again.'));
    exit;
}

header('Location: admin.php?saved=1');
exit;
