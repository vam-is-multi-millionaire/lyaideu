<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img
         FROM dishes
         ORDER BY id'
    )->fetchAll();

    $hotels = $pdo->query(
        'SELECT id, name, type, phone, emoji, logo
         FROM hotels
         ORDER BY id'
    )->fetchAll();

    $contacts = $pdo->query(
        'SELECT id, role, person, phone, note, ico
         FROM contacts
         ORDER BY id'
    )->fetchAll();

    echo json_encode([
        'dishes' => $dishes,
        'hotels' => $hotels,
        'contacts' => $contacts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load catalog data.']);
}
