<?php

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ob_start();

try {
    lyaideu_ensure_categories_table();
    lyaideu_ensure_variant_tables();

    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img, category_id, name_slug AS slug, has_variants
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
        'SELECT m.id, m.name, m.cat, m.unit, m.price, m.tag, m.`desc`, m.img, m.category_id, m.name_slug AS slug, m.has_variants,
                COALESCE(h.name, \'\') AS hotel
         FROM mart_items m
         LEFT JOIN vendors v ON v.id = m.vendor_id
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY m.id'
    )->fetchAll();

    lyaideu_ensure_other_table();

    $others = $pdo->query(
        'SELECT oi.id, oi.name, oi.cat, oi.unit, oi.price, oi.tag, oi.`desc`, oi.img, oi.category_id, oi.name_slug AS slug, oi.has_variants,
                COALESCE(h.name, \'\') AS hotel
         FROM other_items oi
         LEFT JOIN vendors v ON v.id = oi.vendor_id
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY oi.id'
    )->fetchAll();

    lyaideu_ensure_beverage_table();

    $beverages = $pdo->query(
        'SELECT bi.id, bi.name, bi.cat, bi.unit, bi.price, bi.tag, bi.`desc`, bi.img, bi.category_id, bi.name_slug AS slug, bi.has_variants,
                COALESCE(h.name, \'\') AS hotel
         FROM beverage_items bi
         LEFT JOIN vendors v ON v.id = bi.vendor_id
         LEFT JOIN hotels h ON h.id = v.hotel_id
         ORDER BY bi.id'
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

    foreach ($beverages as &$b) {
        $b['cats'] = lyaideu_item_cats((int)($b['category_id'] ?? 0), (string)$b['cat']);
    }
    unset($b);

    lyaideu_attach_variants($dishes, 'dish');
    lyaideu_attach_variants($mart, 'mart');
    lyaideu_attach_variants($others, 'other');
    lyaideu_attach_variants($beverages, 'beverage');

    echo json_encode([
        'dishes' => $dishes,
        'hotels' => $hotels,
        'contacts' => $contacts,
        'mart' => $mart,
        'others' => $others,
        'beverages' => $beverages,
        'categories' => lyaideu_categories(),
        'delivery' => lyaideu_delivery_config(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/api_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND);
    @ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Could not load catalog data.']);
}
$stray = ob_get_clean();
if ($stray !== '') {
    if (substr(trim($stray), 0, 1) !== '{') {
        @file_put_contents(__DIR__ . '/api_error.log', '[' . date('Y-m-d H:i:s') . '] STRAY OUTPUT: ' . $stray . PHP_EOL, FILE_APPEND);
    }
    echo $stray;
}
