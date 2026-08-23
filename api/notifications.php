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
    $markAll = false;
    if (trim((string)$body) !== '' && $body[0] === '[') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $ids = $decoded;
        }
    } elseif (trim((string)$body) !== '' && $body[0] === '{') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !empty($decoded['all'])) {
            $markAll = true;
            $ids = is_array($decoded['ids'] ?? null) ? $decoded['ids'] : [];
        }
    } else {
        if (!empty($_POST['all'])) {
            $markAll = true;
        }
        $ids = $_POST['ids'] ?? [];
    }
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    try {
        $marked = 0;
        if ($markAll) {
            /* Mark EVERY unread row for this recipient, not just the ones
               currently rendered in the dropdown (the feed is capped at 40). */
            $st = $pdo->prepare(
                'UPDATE notifications SET is_read = 1
                 WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0'
            );
            $st->execute([$recipientType, $recipientId]);
            $marked = $st->rowCount();
        } elseif ($ids) {
            $marks = [];
            foreach ($ids as $id) {
                $marks[] = (int)$id;
            }
            $in = implode(',', $marks);
            $st = $pdo->prepare(
                "UPDATE notifications SET is_read = 1
                 WHERE recipient_type = ? AND recipient_id = ? AND id IN ($in)"
            );
            $st->execute([$recipientType, $recipientId]);
            $marked = $st->rowCount();
        }
        echo json_encode(['ok' => true, 'marked' => $marked]);
    } catch (Throwable $e) {
        /* Never lie about success: the UI relies on ok to decide whether
           to trust its own optimistic update. */
        echo json_encode(['ok' => false]);
    }
    exit;
}

$loadFeed = function () use ($pdo, $recipientType, $recipientId) {
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

    return [
        'unread' => (int)$unread->fetchColumn(),
        'items' => array_reverse($rows),
    ];
};

try {
    echo json_encode($loadFeed(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    /* Self-heal: if the table is missing (installer not run yet), create it
       and answer once instead of leaving the bell permanently broken. */
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id INT UNSIGNED NULL DEFAULT NULL,
                recipient_type VARCHAR(10) NOT NULL,
                recipient_id INT UNSIGNED NOT NULL DEFAULT 0,
                message VARCHAR(255) NOT NULL,
                link VARCHAR(255) NOT NULL DEFAULT \'\',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_notif_target (recipient_type, recipient_id, is_read),
                KEY idx_notif_target_time (recipient_type, recipient_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        echo json_encode($loadFeed(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e2) {
        echo json_encode(['unread' => 0, 'items' => []]);
    }
}