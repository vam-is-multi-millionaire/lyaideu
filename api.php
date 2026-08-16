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

    lyaideu_ensure_stores();

    $hotels = $pdo->query(
        "SELECT h.id, h.name, h.type, h.phone, h.emoji, h.logo, h.kind, h.`desc`,
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
        'SELECT m.id, m.name, m.cat, m.unit, m.price, m.tag, m.`desc`, m.img, m.category_id, m.name_slug AS slug,
                COALESCE(h.name, \'\') AS hotel
         FROM mart_items m
         LEFT JOIN vendors v ON v.id = m.vendor_id
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY m.id'
    )->fetchAll();

    lyaideu_ensure_other_table();

    $others = $pdo->query(
        'SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug AS slug,
                COALESCE(h.name, \'\') AS hotel
         FROM other_items oi
         LEFT JOIN vendors v ON v.id = oi.vendor_id
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY oi.id'
    )->fetchAll();

    foreach ($dishes as &$d) {
        $d['cats'] = lyaideu_item_cats((int)($d['category_id'] ?? 0), (string)$d['cat']);
    }
    unset($d);

    foreach ($mart as &$m) {
        $m['cats'] = lyaideu_item_cats((int)($m['category_id'] ?? 0), (string)$m['cat']);
    }
    unset($m);

    foreach ($others as &$o) {
        $o['cats'] = lyaideu_item_cats((int)($o['category_id'] ?? 0), (string)$o['cat']);
    }
    unset($o);

    echo json_encode([
        'dishes' => $dishes,
        'hotels' => $hotels,
        'contacts' => $contacts,
        'mart' => $mart,
        'others' => $others,
        'categories' => lyaideu_categories(),
        'delivery' => lyaideu_delivery_config(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load catalog data.']);
}
