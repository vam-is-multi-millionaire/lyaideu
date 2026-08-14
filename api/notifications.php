<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../site_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$recipientType = '';
$recipientId = 0;

// The page passes its role (?role=vendor|rider) so we always read the right
// session cookie even when several delivery cookies coexist in the browser.
$roleParam = (string)($_GET['role'] ?? '');
if (session_status() !== PHP_SESSION_ACTIVE) {
    if ($roleParam === 'vendor' && isset($_COOKIE['LYAIDEU_VENDOR'])) {
        session_name('LYAIDEU_VENDOR');
        session_start();
    } elseif ($roleParam === 'rider' && isset($_COOKIE['LYAIDEU_RIDER'])) {
        session_name('LYAIDEU_RIDER');
        session_start();
    } else {
        session_name('PHPSESSID');
        session_start();
    }
}

if (!empty($_SESSION['delivery_role']) && $_SESSION['delivery_role'] === 'rider' && !empty($_SESSION['delivery_user']['id'])) {
    $recipientType = 'rider';
    $recipientId = (int)$_SESSION['delivery_user']['id'];
} elseif (!empty($_SESSION['delivery_role']) && $_SESSION['delivery_role'] === 'vendor' && !empty($_SESSION['delivery_user']['id'])) {
    $recipientType = 'vendor';
    $recipientId = (int)$_SESSION['delivery_user']['id'];
} elseif (!empty($_SESSION['user']['id'])) {
    $recipientType = 'user';
    $recipientId = (int)$_SESSION['user']['id'];
}

if ($recipientType === '' || $recipientId <= 0) {
    echo json_encode(['unread' => 0, 'items' => []]);
    exit;
}

$pdo = lyaideu_load_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = file_get_contents('php://input');
    $ids = [];
    if (trim((string)$body) !== '' && $body[0] === '[') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $ids = $decoded;
        }
    } else {
        $ids = $_POST['ids'] ?? [];
    }
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if ($ids) {
        $marks = [];
        foreach ($ids as $id) {
            $marks[] = (int)$id;
        }
        $in = implode(',', $marks);
        try {
            $pdo->prepare(
                "UPDATE notifications SET is_read = 1
                 WHERE recipient_type = ? AND recipient_id = ? AND id IN ($in)"
            )->execute([$recipientType, $recipientId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

try {
    $items = $pdo->prepare(
        'SELECT id, order_id, message, link, is_read, created_at
         FROM notifications
         WHERE recipient_type = ? AND recipient_id = ?
         ORDER BY id DESC LIMIT 40'
    );
    $items->execute([$recipientType, $recipientId]);
    $rows = $items->fetchAll();

    $unread = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications
         WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0'
    );
    $unread->execute([$recipientType, $recipientId]);
    $unreadCount = (int)$unread->fetchColumn();

    echo json_encode([
        'unread' => $unreadCount,
        'items' => array_reverse($rows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['unread' => 0, 'items' => []]);
}