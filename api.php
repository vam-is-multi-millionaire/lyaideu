<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    lyaideu_ensure_categories_table();

    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug AS slug
         FROM dishes
         ORDER BY id'
    )->fetchAll();

    $hotels = $pdo->query(
        "SELECT h.id, h.name, h.type, h.phone, h.emoji, h.logo,
                v.id AS vendor_id, v.name AS vendor_name
         FROM hotels h
         LEFT JOIN vendors v ON v.hotel_id = h.id AND v.scope = 'hotel' AND v.is_active = 1
         ORDER BY h.id"
    )->fetchAll();

    $contacts = $pdo->query(
        'SELECT id, role, person, phone, note, ico
         FROM contacts
         ORDER BY id'
    )->fetchAll();

    $mart = $pdo->query(
        'SELECT id, name, cat, unit, price, tag, `desc`, img, category_id, name_slug AS slug
         FROM mart_items
         ORDER BY id'
    )->fetchAll();

    foreach ($dishes as &$d) {
        $d['cats'] = lyaideu_item_cats((int)($d['category_id'] ?? 0), (string)$d['cat']);
    }
    unset($d);

    foreach ($mart as &$m) {
        $m['cats'] = lyaideu_item_cats((int)($m['category_id'] ?? 0), (string)$m['cat']);
    }
    unset($m);

    echo json_encode([
        'dishes' => $dishes,
        'hotels' => $hotels,
        'contacts' => $contacts,
        'mart' => $mart,
        'categories' => lyaideu_categories(),
        'delivery' => lyaideu_delivery_config(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load catalog data.']);
}
