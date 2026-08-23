<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('messages');
require_once __DIR__ . '/db.php';

lyaideu_ensure_messages_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $id = (int)($_POST['message_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id > 0) {
        try {
            switch ($action) {
                case 'mark_read':
                    $pdo->prepare("UPDATE messages SET status = 'read' WHERE id = ?")->execute([$id]);
                    break;
                case 'mark_unread':
                    $pdo->prepare("UPDATE messages SET status = 'unread' WHERE id = ?")->execute([$id]);
                    break;
                case 'delete':
                    $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
                    break;
            }
        } catch (Throwable $e) {
            // Fall through to redirect on failure.
        }
    }

    if ($action === 'delete') {
        header('Location: admin_messages?deleted=1');
    } else {
        header('Location: admin_messages?saved=1');
    }
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$filter = in_array($filter, ['all', 'unread', 'read'], true) ? $filter : 'all';

$sql = 'SELECT m.id, m.name, m.email, m.phone, m.subject, m.body, m.status, m.created_at,
               u.name AS user_name
        FROM messages m
        LEFT JOIN users u ON u.id = m.user_id';
if ($filter === 'unread') {
    $sql .= " WHERE m.status = 'unread'";
} elseif ($filter === 'read') {
    $sql .= " WHERE m.status = 'read'";
}
$sql .= ' ORDER BY m.created_at DESC, m.id DESC';

try {
    $messages = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load messages.');
}

$unreadCount = 0;
foreach ($messages as $m) {
    if ($m['status'] === 'unread') {
        $unreadCount++;
    }
}

admin_page_start('Messages', 'messages', 'Contact Messages');
?>
    <div class="admin-stats">
        <div><strong><?= count($messages) ?></strong><span>Total Messages</span></div>
        <div><strong><?= $unreadCount ?></strong><span>Unread</span></div>
        <div><strong><?= count($messages) - $unreadCount ?></strong><span>Read</span></div>
    </div>

    <nav class="admin-tabs" aria-label="Filter messages">
        <a class="admin-tab <?= $filter === 'all' ? 'active' : '' ?>" href="admin_messages?filter=all">All</a>
        <a class="admin-tab <?= $filter === 'unread' ? 'active' : '' ?>" href="admin_messages?filter=unread">Unread (<?= $unreadCount ?>)</a>
        <a class="admin-tab <?= $filter === 'read' ? 'active' : '' ?>" href="admin_messages?filter=read">Read</a>
    </nav>

    <section class="admin-section">
        <div class="admin-section-top">
            <p class="section-sub">Messages sent through the Contact page. Reply or get in touch with the sender when they need help.</p>
            <span class="admin-count-badge" id="messageShown"><?= count($messages) ?> messages</span>
        </div>
        <div class="admin-order-tools">
            <div class="admin-order-search">
                <span><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" id="messageSearch" placeholder="Search by sender, subject or message…" autocomplete="off">
            </div>
        </div>
        <div class="admin-order-list">
            <?php if (!$messages): ?>
            <div class="admin-card">
                <h3>No messages here.</h3>
                <p class="small-note">When customers use <strong>Send Us a Message</strong> on the Contact page, their messages appear here.</p>
            </div>
            <?php endif; ?>

            <?php foreach ($messages as $m):
                $searchTxt = mb_strtolower(implode(' ', [$m['name'], $m['email'], $m['phone'], $m['subject'], $m['body'], $m['status']]));
            ?>
            <article class="admin-order-card message-card <?= $m['status'] === 'unread' ? 'message-unread' : '' ?>" data-search="<?= htmlspecialchars($searchTxt, ENT_QUOTES, 'UTF-8') ?>">
                <div class="order-card-head">
                    <div>
                        <h2><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($m['subject']) ?>
                            <?php if ($m['status'] === 'unread'): ?><span class="message-badge">New</span><?php endif; ?>
                        </h2>
                        <p><?= htmlspecialchars($m['created_at']) ?> · <?= htmlspecialchars($m['name']) ?> · <i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($m['email']) ?><?php if ($m['phone']): ?> · <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($m['phone']) ?><?php endif; ?></p>
                    </div>
                    <div class="message-actions">
                        <a class="btn btn-outline" href="mailto:<?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?>?subject=Re: <?= rawurlencode($m['subject']) ?>"><i class="fa-solid fa-reply"></i> Reply</a>
                        <form method="POST" class="status-form message-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                            <?php if ($m['status'] === 'unread'): ?>
                                <button class="btn btn-outline" type="submit" name="action" value="mark_read"><i class="fa-solid fa-check"></i> Mark Read</button>
                            <?php else: ?>
                                <button class="btn btn-outline" type="submit" name="action" value="mark_unread"><i class="fa-solid fa-eye"></i> Mark Unread</button>
                            <?php endif; ?>
                            <button class="btn btn-danger message-delete" type="submit" name="action" value="delete" onclick="return confirm('Delete this message?');"><i class="fa-solid fa-trash-can"></i></button>
                        </form>
                    </div>
                </div>
                <div class="message-body"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
            </article>
            <?php endforeach; ?>
            <div class="admin-card" id="messageSearchEmpty" style="display:none"><h3>No messages match your search.</h3><p class="small-note">Try a different name, subject or keyword.</p></div>
        </div>
    </section>
<script>
(function () {
    var input = document.getElementById('messageSearch');
    if (!input) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.message-card'));
    var shown = document.getElementById('messageShown');
    var emptyEl = document.getElementById('messageSearchEmpty');
    function apply() {
        var q = input.value.trim().toLowerCase();
        var n = 0;
        cards.forEach(function (card) {
            var hay = (card.getAttribute('data-search') || '').toLowerCase();
            var show = !q || hay.indexOf(q) !== -1;
            card.style.display = show ? '' : 'none';
            if (show) n++;
        });
        if (shown) shown.textContent = n + ' messages';
        if (emptyEl) emptyEl.style.display = (cards.length > 0 && n === 0) ? '' : 'none';
    }
    input.addEventListener('input', apply);
})();
</script>
<?php
admin_page_end();