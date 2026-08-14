<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

$orderCounts = ['Pending' => 0, 'Confirmed' => 0, 'Preparing' => 0, 'Ready for pickup' => 0, 'Out for delivery' => 0, 'Delivered' => 0, 'Cancelled' => 0];
$totalSales = 0;
$totalOrders = 0;
$userCount = 0;
$dishCount = 0;
$hotelCount = 0;
$contactCount = 0;
$messageCount = 0;
$unreadMessageCount = 0;
$categoryCount = 0;
$vendorCount = 0;
$riderCount = 0;

try {
    lyaideu_ensure_mart_table();
    lyaideu_ensure_categories_table();
    lyaideu_ensure_delivery_tables();

    $totalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> 'Cancelled'")->fetchColumn();
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $dishCount = (int)$pdo->query('SELECT COUNT(*) FROM dishes')->fetchColumn();
    $martCount = (int)$pdo->query('SELECT COUNT(*) FROM mart_items')->fetchColumn();
    $hotelCount = (int)$pdo->query('SELECT COUNT(*) FROM hotels')->fetchColumn();
    $contactCount = (int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $categoryCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    $vendorCount = (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn();
    $riderCount = (int)$pdo->query('SELECT COUNT(*) FROM riders')->fetchColumn();

    if (lyaideu_ensure_messages_table()) {
        $messageCount = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $unreadMessageCount = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'unread'")->fetchColumn();
    }

    foreach ($pdo->query('SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status') as $row) {
        if (isset($orderCounts[$row['status']])) {
            $orderCounts[$row['status']] = (int)$row['cnt'];
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load dashboard data.');
}

$navItems = admin_nav_items();

admin_page_start('Dashboard', 'dashboard', 'Dashboard');
?>
    <div class="admin-stats">
        <div><strong><?= $totalOrders ?></strong><span>Total Orders</span></div>
        <div><strong>Rs.&nbsp;<?= number_format($totalSales) ?></strong><span>Order Value</span></div>
        <div><strong><?= $orderCounts['Pending'] ?></strong><span>Pending</span></div>
        <div><strong><?= $userCount ?></strong><span>Registered Users</span></div>
        <div><strong><?= $dishCount ?></strong><span>Menu Items</span></div>
        <div><strong><?= $vendorCount ?></strong><span>Vendors</span></div>
        <div><strong><?= $riderCount ?></strong><span>Riders</span></div>
        <div><strong><?= $unreadMessageCount ?></strong><span>Unread Messages</span></div>
    </div>

    <section class="admin-section">
        <h2><i class="fa-solid fa-bolt"></i> Manage Sections</h2>
        <div class="admin-hub-grid">
            <?php
            $cards = [
                'categories' => ['count' => $categoryCount, 'desc' => 'Build menu & mart category trees'],
                'orders' => ['count' => $totalOrders, 'desc' => 'Track and update customer orders'],
                'dishes' => ['count' => $dishCount, 'desc' => 'Add, edit, or remove menu items'],
                'mart' => ['count' => $martCount, 'desc' => 'Manage grocery items on the Mart page'],
                'hotels' => ['count' => $hotelCount, 'desc' => 'Manage partner restaurant listings'],
                'vendors' => ['count' => $vendorCount, 'desc' => 'Manage kitchen accounts that log in at /vendor'],
                'riders' => ['count' => $riderCount, 'desc' => 'Manage delivery riders that log in at /rider'],
                'contacts' => ['count' => $contactCount, 'desc' => 'Update service team phone numbers'],
                'messages' => ['count' => $unreadMessageCount, 'desc' => 'Read messages from the Contact page'],
                'users' => ['count' => $userCount, 'desc' => 'View registered customer accounts'],
            ];
            foreach ($cards as $key => $card):
                $item = $navItems[$key];
            ?>
            <a class="admin-hub-card" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="admin-hub-icon"><?= $item['icon'] ?></span>
                <h3><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                <strong><?= (int)$card['count'] ?></strong>
                <p><?= htmlspecialchars($card['desc'], ENT_QUOTES, 'UTF-8') ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-section admin-quick-actions">
        <h2><i class="fa-solid fa-globe"></i> Website</h2>
        <div class="hero-actions">
            <a class="btn btn-primary" href="menu" target="_blank"><i class="fa-solid fa-utensils"></i> Preview Menu</a>
            <a class="btn btn-outline" href="index" target="_blank">Open Website</a>
            <a class="btn btn-outline" href="admin_settings"><i class="fa-solid fa-gear"></i> Settings</a>
        </div>
    </section>
<?php
admin_page_end();
