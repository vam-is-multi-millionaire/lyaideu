<?php
session_start();

const ADMIN_PASS_HASH = '$2y$12$gKimlVM8pqaeijJcHazLGOfF2Qbse0Obz29rRt4hUt/FLUFAmvrPa'; // Default demo password: admin123

if (!isset($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    $tokenValid = hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '');

    if (!$tokenValid) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (password_verify($password, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
        header('Location: admin.php');
        exit;
    }

    $error = 'Wrong password!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['is_admin'])) {
    $safeError = isset($error) ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title><link rel="stylesheet" href="css/style.css"></head>
    <body style="display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--green-50); padding:1rem;">
        <div class="admin-login-box"><h2 class="display">🔒 LyaiDeu Admin</h2>';
    if ($safeError !== '') echo "<p style='color:#c93a3a; font-weight:bold; margin-top:.8rem;'>$safeError</p>";
    echo '<form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_admin'], ENT_QUOTES, 'UTF-8') . '">
        <input type="password" name="password" placeholder="Enter Password" required autocomplete="current-password" style="width:100%; padding:12px; margin:15px 0; border:2px solid var(--green-200); border-radius:8px; font-size:1rem;">
        <button type="submit" name="login" class="btn btn-primary btn-block">Login to Dashboard</button>
    </form></div></body></html>';
    exit;
}

require_once __DIR__ . '/db.php';

$orderCounts = ['Pending' => 0, 'Confirmed' => 0, 'Preparing' => 0, 'Out for delivery' => 0, 'Delivered' => 0, 'Cancelled' => 0];
$totalSales = 0;
$totalOrders = 0;
$userCount = 0;
$dishCount = 0;

try {
    $totalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> 'Cancelled'")->fetchColumn();
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $dishCount = (int)$pdo->query('SELECT COUNT(*) FROM dishes')->fetchColumn();

    foreach ($pdo->query('SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status') as $row) {
        if (isset($orderCounts[$row['status']])) {
            $orderCounts[$row['status']] = (int)$row['cnt'];
        }
    }

    $dishes = $pdo->query(
        'SELECT id, name, hotel, cat, price, phone, tag, `desc`, img FROM dishes ORDER BY id'
    )->fetchAll();
    $hotels = $pdo->query(
        'SELECT id, name, type, phone, emoji FROM hotels ORDER BY id'
    )->fetchAll();
    $contacts = $pdo->query(
        'SELECT id, role, person, phone, note, ico FROM contacts ORDER BY id'
    )->fetchAll();
    $users = $pdo->query(
        'SELECT id, name, email, phone, dob, created_at FROM users ORDER BY created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load admin data.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel | LyaiDeu</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body class="admin-body">
<div class="admin-header">
    <h1 class="display" style="color:white; margin:0;">🛵 LyaiDeu Admin</h1>
    <div class="admin-actions">
        <a href="index.php" target="_blank" class="btn btn-outline">View Website</a>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_admin'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="logout" class="btn btn-primary" style="background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Logout</button>
        </form>
    </div>
</div>

<div class="admin-container">
    <div class="admin-stats">
        <div><strong><?= $totalOrders ?></strong><span>Total Orders</span></div>
        <div><strong>Rs. <?= number_format($totalSales) ?></strong><span>Order Value</span></div>
        <div><strong><?= $orderCounts['Pending'] ?></strong><span>Pending</span></div>
        <div><strong><?= $userCount ?></strong><span>Registered Users</span></div>
        <div><strong><?= $dishCount ?></strong><span>Menu Items</span></div>
    </div>
    <div class="admin-section admin-quick-actions">
        <h2>⚡ Quick Actions</h2>
        <div class="hero-actions"><a class="btn btn-primary" href="admin_orders.php">📦 Manage Orders</a><a class="btn btn-outline" href="index.php#menu" target="_blank">🍽️ Preview Menu</a></div>
    </div>
    <?php if (isset($_GET['saved'])): ?>
        <div class="flash-banner flash-success" style="margin-bottom:20px; text-align:center;">✅ Changes saved successfully.</div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="flash-banner flash-error" style="margin-bottom:20px; text-align:center;">❌ <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form action="admin_save.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_admin'], ENT_QUOTES, 'UTF-8') ?>">

        <!-- ============ DISHES ============ -->
        <section class="admin-section">
            <h2>🍽️ Menu Items (Dishes)</h2>
            <div class="admin-grid">
                <?php foreach ($dishes as $i => $d): ?>
                <div class="admin-card">
                    <h3><?= htmlspecialchars($d['name']) ?></h3>
                    <input type="hidden" name="dishes[<?= $i ?>][id]" value="<?= (int)$d['id'] ?>">
                    <label>Dish Name</label>
                    <input type="text" name="dishes[<?= $i ?>][name]" value="<?= htmlspecialchars($d['name']) ?>" required>
                    <label>Hotel Name</label>
                    <input type="text" name="dishes[<?= $i ?>][hotel]" value="<?= htmlspecialchars($d['hotel']) ?>" required>
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Category</label>
                            <select name="dishes[<?= $i ?>][cat]">
                                <?php foreach (['momo','pizza','chowmein','snacks','beverages','dinner'] as $c): ?>
                                <option value="<?= $c ?>" <?= $d['cat']===$c?'selected':'' ?>><?= ucfirst($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1;"><label>Price</label>
                            <input type="number" min="0" step="1" name="dishes[<?= $i ?>][price]" value="<?= (int)$d['price'] ?>" required>
                        </div>
                    </div>
                    <label>Image URL</label>
                    <input type="url" name="dishes[<?= $i ?>][img]" value="<?= htmlspecialchars($d['img']) ?>">
                    <label>Description</label>
                    <textarea name="dishes[<?= $i ?>][desc]"><?= htmlspecialchars($d['desc']) ?></textarea>
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label>
                            <input type="text" name="dishes[<?= $i ?>][phone]" value="<?= htmlspecialchars($d['phone']) ?>">
                        </div>
                        <div style="flex:1;"><label>Tag</label>
                            <input type="text" name="dishes[<?= $i ?>][tag]" value="<?= htmlspecialchars($d['tag']) ?>">
                        </div>
                    </div>
                    <label class="delete-check"><input type="checkbox" name="dishes[<?= $i ?>][delete]" value="1"> 🗑️ Delete this dish</label>
                </div>
                <?php endforeach; ?>

                <div class="admin-card admin-add-card">
                    <h3>➕ Add New Dish</h3>
                    <label>Dish Name</label><input type="text" name="new_dish[name]" placeholder="e.g. Paneer Tikka">
                    <label>Hotel Name</label><input type="text" name="new_dish[hotel]" placeholder="e.g. Spice Garden">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Category</label>
                            <select name="new_dish[cat]">
                                <option value="momo">Momo</option><option value="pizza">Pizza</option>
                                <option value="chowmein">Chowmein</option><option value="snacks">Snacks</option>
                                <option value="beverages">Beverages</option><option value="dinner">Dinner</option>
                            </select>
                        </div>
                        <div style="flex:1;"><label>Price</label><input type="number" min="0" step="1" name="new_dish[price]" placeholder="250"></div>
                    </div>
                    <label>Image URL</label><input type="url" name="new_dish[img]" placeholder="https://...">
                    <label>Description</label><textarea name="new_dish[desc]" placeholder="Short tasty description..."></textarea>
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label><input type="text" name="new_dish[phone]" placeholder="98XXXXXXXX"></div>
                        <div style="flex:1;"><label>Tag</label><input type="text" name="new_dish[tag]" placeholder="New!"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ HOTELS ============ -->
        <section class="admin-section">
            <h2>🏨 Partner Hotels</h2>
            <div class="admin-grid">
                <?php foreach ($hotels as $i => $h): ?>
                <div class="admin-card">
                    <h3><?= htmlspecialchars($h['name']) ?></h3>
                    <input type="hidden" name="hotels[<?= $i ?>][id]" value="<?= (int)$h['id'] ?>">
                    <label>Hotel Name</label><input type="text" name="hotels[<?= $i ?>][name]" value="<?= htmlspecialchars($h['name']) ?>" required>
                    <label>Type / Location</label><input type="text" name="hotels[<?= $i ?>][type]" value="<?= htmlspecialchars($h['type']) ?>">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label><input type="text" name="hotels[<?= $i ?>][phone]" value="<?= htmlspecialchars($h['phone']) ?>"></div>
                        <div style="flex:1;"><label>Emoji</label><input type="text" name="hotels[<?= $i ?>][emoji]" value="<?= htmlspecialchars($h['emoji']) ?>"></div>
                    </div>
                    <label class="delete-check"><input type="checkbox" name="hotels[<?= $i ?>][delete]" value="1"> 🗑️ Delete this hotel</label>
                </div>
                <?php endforeach; ?>

                <div class="admin-card admin-add-card">
                    <h3>➕ Add New Hotel</h3>
                    <label>Hotel Name</label><input type="text" name="new_hotel[name]" placeholder="e.g. Spice Garden">
                    <label>Type / Location</label><input type="text" name="new_hotel[type]" placeholder="e.g. Indian · Pokhara Rd">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label><input type="text" name="new_hotel[phone]" placeholder="98XXXXXXXX"></div>
                        <div style="flex:1;"><label>Emoji</label><input type="text" name="new_hotel[emoji]" placeholder="🏨"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ CONTACTS ============ -->
        <section class="admin-section">
            <h2>☎️ Service Contacts</h2>
            <div class="admin-grid">
                <?php foreach ($contacts as $i => $c): ?>
                <div class="admin-card">
                    <h3><?= htmlspecialchars($c['role']) ?></h3>
                    <input type="hidden" name="contacts[<?= $i ?>][id]" value="<?= (int)$c['id'] ?>">
                    <label>Role</label><input type="text" name="contacts[<?= $i ?>][role]" value="<?= htmlspecialchars($c['role']) ?>" required>
                    <label>Person / Dept</label><input type="text" name="contacts[<?= $i ?>][person]" value="<?= htmlspecialchars($c['person']) ?>">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label><input type="text" name="contacts[<?= $i ?>][phone]" value="<?= htmlspecialchars($c['phone']) ?>"></div>
                        <div style="flex:1;"><label>Emoji</label><input type="text" name="contacts[<?= $i ?>][ico]" value="<?= htmlspecialchars($c['ico']) ?>"></div>
                    </div>
                    <label>Note</label><input type="text" name="contacts[<?= $i ?>][note]" value="<?= htmlspecialchars($c['note']) ?>">
                    <label class="delete-check"><input type="checkbox" name="contacts[<?= $i ?>][delete]" value="1"> 🗑️ Delete this contact</label>
                </div>
                <?php endforeach; ?>

                <div class="admin-card admin-add-card">
                    <h3>➕ Add New Contact</h3>
                    <label>Role</label><input type="text" name="new_contact[role]" placeholder="e.g. Complaints">
                    <label>Person / Dept</label><input type="text" name="new_contact[person]" placeholder="e.g. Support Team">
                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;"><label>Phone</label><input type="text" name="new_contact[phone]" placeholder="98XXXXXXXX"></div>
                        <div style="flex:1;"><label>Emoji</label><input type="text" name="new_contact[ico]" placeholder="📞"></div>
                    </div>
                    <label>Note</label><input type="text" name="new_contact[note]" placeholder="e.g. 7 AM – 10 PM">
                </div>
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-block admin-save-btn">💾 Save All Changes</button>
    </form>

    <section class="admin-section">
        <h2>👥 Registered Users (<?= count($users) ?>)</h2>
        <div class="admin-grid">
            <?php foreach ($users as $u): ?>
            <div class="admin-card">
                <h3><?= htmlspecialchars($u['name']) ?></h3>
                <label>Email</label><input type="text" value="<?= htmlspecialchars($u['email']) ?>" readonly>
                <label>Phone</label><input type="text" value="+977 <?= htmlspecialchars($u['phone']) ?>" readonly>
                <label>Date of Birth</label><input type="text" value="<?= htmlspecialchars($u['dob']) ?>" readonly>
                <label>Joined</label><input type="text" value="<?= htmlspecialchars($u['created_at']) ?>" readonly>
            </div>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
                <p style="grid-column:1/-1; text-align:center; color:var(--muted); padding:2rem;">No users registered yet.</p>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>
