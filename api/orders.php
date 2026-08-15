<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../site_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PHPSESSID');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['user']['id'])) {
    echo json_encode(['orders' => []]);
    exit;
}

$pdo = lyaideu_load_pdo();
try {
    $uid = (int)$_SESSION['user']['id'];
    $st = $pdo->prepare('SELECT id FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $st->execute([$uid]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $orders = [];
    foreach ($ids as $id) {
        $track = lyaideu_order_tracking($id);
        if ($track) {
            $orders[] = $track;
        }
    }
    echo json_encode(['orders' => $orders], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['orders' => []]);
}