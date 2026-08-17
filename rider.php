<?php
require_once __DIR__ . '/delivery_inc.php';

$pdo = lyaideu_load_pdo();
$role = 'rider';
delivery_require_login($role);
$user = delivery_user();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($user) {
    delivery_logout();
    $riderId = (int)$user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $claimId = (int)$_POST['claim'];
            $upd = $pdo->prepare(
                "UPDATE orders SET rider_id = :rid, updated_at = :now
                 WHERE id = :oid AND rider_id IS NULL AND status = 'Ready for pickup'"
            );
            $upd->execute([
                ':rid' => $riderId,
                ':now' => date('Y-m-d H:i:s'),
                ':oid' => $claimId,
            ]);
            if ($upd->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Order #' . $claimId . ' is yours — go pick it up!'];
                try {
                    $st = $pdo->prepare('SELECT user_id, vendor_id FROM orders WHERE id = ? LIMIT 1');
                    $st->execute([$claimId]);
                    $o = $st->fetch();
                } catch (Throwable $e) {
                    $o = false;
                }
                if ($o) {
                    $riderName = (string)$user['name'];
                    $orderUserId = (int)$o['user_id'];
                    $orderVendorId = (int)$o['vendor_id'];
                    lyaideu_notify($claimId, 'user', $orderUserId, 'Rider ' . $riderName . ' will deliver your order #' . $claimId . '.', 'orders?id=' . $claimId);
                    if ($orderVendorId > 0) {
                        lyaideu_notify($claimId, 'vendor', $orderVendorId, 'Rider ' . $riderName . ' accepted order #' . $claimId . '.', 'vendor');
                    }
                    foreach ($pdo->query('SELECT id FROM riders WHERE is_active = 1 AND id <> ' . (int)$riderId) as $r) {
                        lyaideu_notify($claimId, 'rider', (int)$r['id'], 'Order #' . $claimId . ' was taken by another rider.', 'rider');
                    }
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That order was just taken by another rider.'];
            }
        }
        header('Location: rider');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_action'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $newStatus = trim((string)($_POST['order_action']));
            try {
                $st = $pdo->prepare('SELECT status, rider_id, user_id, vendor_id FROM orders WHERE id = ? LIMIT 1');
                $st->execute([$orderId]);
                $order = $st->fetch();
                $valid = $order && (int)$order['rider_id'] === $riderId;
                if ($valid && $newStatus === 'Out for delivery' && $order['status'] === 'Ready for pickup') {
                    $upd = $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?');
                    $upd->execute([$newStatus, date('Y-m-d H:i:s'), $orderId]);
                    if ($upd->rowCount() > 0) {
                        $riderName = (string)$user['name'];
                        lyaideu_notify($orderId, 'user', (int)$order['user_id'], 'Rider ' . $riderName . ' picked up your order #' . $orderId . ' — it\'s on the way!', 'orders?id=' . $orderId);
                        if ((int)$order['vendor_id'] > 0) {
                            lyaideu_notify($orderId, 'vendor', (int)$order['vendor_id'], 'Order #' . $orderId . ' is out for delivery.', 'vendor');
                        }
                    }
                } elseif ($valid && $newStatus === 'Delivered' && $order['status'] === 'Out for delivery') {
                    $upd = $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?');
                    $upd->execute([$newStatus, date('Y-m-d H:i:s'), $orderId]);
                    if ($upd->rowCount() > 0) {
                        $riderName = (string)$user['name'];
                        lyaideu_notify($orderId, 'user', (int)$order['user_id'], 'Your order #' . $orderId . ' was delivered by ' . $riderName . '. Enjoy!', 'orders?id=' . $orderId);
                        if ((int)$order['vendor_id'] > 0) {
                            lyaideu_notify($orderId, 'vendor', (int)$order['vendor_id'], 'Order #' . $orderId . ' was delivered.', 'vendor');
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore transition errors.
            }
        }
        header('Location: rider');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_save'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $vehicle = trim((string)($_POST['vehicle'] ?? ''));
            $errors = [];
            if ($name === '') {
                $errors[] = 'Your name is required.';
            }
            if ($phone === '') {
                $errors[] = 'Your phone number is required.';
            }
            if (!$errors) {
                try {
                    $dup = $pdo->prepare('SELECT id FROM riders WHERE (phone = :p OR (email <> "" AND email = :e)) AND id <> :id LIMIT 1');
                    $dup->execute([':p' => $phone, ':e' => $email, ':id' => $riderId]);
                    if ($dup->fetch()) {
                        $errors[] = 'This phone or email is already used by another rider.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not check your details right now.';
                }
            }
            if (!$errors) {
                $conflict = lyaideu_delivery_credential_conflict('rider', $phone, $email, $riderId);
                if ($conflict) {
                    $errors[] = $conflict;
                }
            }
            if ($errors) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => implode('<br>', $errors)];
            } else {
                try {
                    $pdo->prepare('UPDATE riders SET name = ?, email = ?, phone = ?, vehicle = ? WHERE id = ?')
                        ->execute([$name, $email, $phone, $vehicle, $riderId]);
                    $_SESSION['delivery_user'] = array_merge($_SESSION['delivery_user'], [
                        'name' => $name, 'email' => $email, 'phone' => $phone, 'vehicle' => $vehicle,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Your profile has been updated.'];
                } catch (Throwable $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Could not save your profile. Please try again.'];
                }
            }
        }
        header('Location: rider?tab=profile');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_save'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            try {
                $st = $pdo->prepare('SELECT avatar FROM riders WHERE id = ? LIMIT 1');
                $st->execute([$riderId]);
                $current = (string)$st->fetchColumn();
                $avatar = lyaideu_handle_item_image($current, $_POST, $_FILES['avatar_file'] ?? null, 'rider_avatar');
                $pdo->prepare('UPDATE riders SET avatar = ? WHERE id = ?')->execute([$avatar, $riderId]);
                $_SESSION['delivery_user']['avatar'] = $avatar;
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profile photo updated.'];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
        header('Location: rider?tab=profile');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_remove'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            try {
                $st = $pdo->prepare('SELECT avatar FROM riders WHERE id = ? LIMIT 1');
                $st->execute([$riderId]);
                $current = (string)$st->fetchColumn();
                if ($current !== '') {
                    lyaideu_delete_upload($current);
                    $pdo->prepare('UPDATE riders SET avatar = \'\' WHERE id = ?')->execute([$riderId]);
                }
                $_SESSION['delivery_user']['avatar'] = '';
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profile photo removed.'];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Could not remove your profile photo.'];
            }
        }
        header('Location: rider?tab=profile');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_password'])) {
        if (hash_equals(delivery_csrf_token(), $_POST['csrf_token'] ?? '')) {
            $current = (string)($_POST['current_password'] ?? '');
            $newPass = (string)($_POST['new_password'] ?? '');
            $errors = [];
            try {
                $st = $pdo->prepare('SELECT pass FROM riders WHERE id = ? LIMIT 1');
                $st->execute([$riderId]);
                $hash = (string)$st->fetchColumn();
                if ($hash === '' || !password_verify($current, $hash)) {
                    $errors[] = 'Your current password is incorrect.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not verify your password right now.';
            }
            if (strlen($newPass) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            }
            if ($errors) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => implode('<br>', $errors)];
            } else {
                try {
                    $pdo->prepare('UPDATE riders SET pass = ? WHERE id = ?')
                        ->execute([password_hash($newPass, PASSWORD_DEFAULT), $riderId]);
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Your password has been updated.'];
                } catch (Throwable $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Could not update your password. Please try again.'];
                }
            }
        }
        header('Location: rider?tab=profile');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Location: rider');
        exit;
    }

    if (isset($_GET['tab']) && $_GET['tab'] === 'profile') {
        $profile = $user;
        try {
            $st = $pdo->prepare('SELECT id, name, email, phone, vehicle, avatar FROM riders WHERE id = ? LIMIT 1');
            $st->execute([$riderId]);
            $prof = $st->fetch();
            if ($prof) {
                $profile = $prof;
            }
        } catch (Throwable $e) {
            $profile = $user;
        }

        $avatarUrl = delivery_esc((string)($profile['avatar'] ?? ''));
        $parts = preg_split('/\s+/', trim((string)($profile['name'] ?? '')));
        $ini = strtoupper(substr((string)($parts[0] ?? ''), 0, 1) . (isset($parts[1]) ? substr((string)$parts[1], 0, 1) : ''));

        delivery_header('Rider Profile', 'Your Profile', 'fa-user-pen', $role);

        if ($flash) {
            $ftype = ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success';
            $ficon = $ftype === 'error' ? 'fa-circle-xmark' : 'fa-circle-check';
            echo '<div class="delivery-toast flash-banner flash-' . $ftype . '" data-auto-dismiss="1" role="status"><i class="fa-solid ' . $ficon . '"></i> ' . delivery_esc($flash['msg']) . '</div>';
        }

        echo '<div class="profile-grid">';
        echo '<form class="profile-card" method="POST" enctype="multipart/form-data" action="rider">';
        echo '<input type="hidden" name="csrf_token" value="' . delivery_esc(delivery_csrf_token()) . '">';
        echo '<h2><i class="fa-solid fa-camera"></i> Profile photo</h2>';
        echo '<div class="avatar-upload">';
        echo '<div class="avatar-preview" id="riderAvatarPreview"' . ($avatarUrl !== '' ? ' style="background-image:url(\'' . $avatarUrl . '\')"' : '') . '>' . ($avatarUrl === '' ? delivery_esc($ini) : '') . '</div>';
        echo '<label class="btn btn-outline" for="riderAvatarFile" style="cursor:pointer;"><i class="fa-solid fa-upload"></i> Upload photo</label>';
        echo '<input type="file" id="riderAvatarFile" name="avatar_file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>';
        echo '<p class="small-note">A clear photo of your face so customers can recognise you at the door.</p>';
        if ($avatarUrl !== '') {
            echo '<button type="submit" name="avatar_remove" value="1" class="btn btn-outline btn-sm"><i class="fa-solid fa-trash"></i> Remove photo</button>';
        }
        echo '<button type="submit" name="avatar_save" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save photo</button>';
        echo '</div></form>';

        echo '<form class="profile-card" method="POST" action="rider">';
        echo '<input type="hidden" name="csrf_token" value="' . delivery_esc(delivery_csrf_token()) . '">';
        echo '<h2><i class="fa-solid fa-user"></i> Personal information</h2>';
        echo '<label>Full Name<input name="name" value="' . delivery_esc((string)($profile['name'] ?? '')) . '" required></label>';
        echo '<label>Phone<input name="phone" value="' . delivery_esc((string)($profile['phone'] ?? '')) . '" required inputmode="numeric" maxlength="10"></label>';
        echo '<label>Email<input name="email" type="email" value="' . delivery_esc((string)($profile['email'] ?? '')) . '" placeholder="rider@example.com"></label>';
        echo '<label>Vehicle<input name="vehicle" value="' . delivery_esc((string)($profile['vehicle'] ?? '')) . '" placeholder="e.g. Bike / Scooter"></label>';
        echo '<button type="submit" name="profile_save" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>';
        echo '</form>';

        echo '<form class="profile-card" method="POST" action="rider">';
        echo '<input type="hidden" name="csrf_token" value="' . delivery_esc(delivery_csrf_token()) . '">';
        echo '<h2><i class="fa-solid fa-lock"></i> Change password</h2>';
        echo '<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>';
        echo '<label>New password<input type="password" name="new_password" autocomplete="new-password" required></label>';
        echo '<p class="small-note">New password must be at least 6 characters.</p>';
        echo '<button type="submit" name="profile_password" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-key"></i> Update password</button>';
        echo '</form>';
        echo '</div>';

        echo '<script>
(function(){
  var av = document.getElementById("riderAvatarPreview");
  var af = document.getElementById("riderAvatarFile");
  if (av && af) {
    af.addEventListener("change", function () {
      var f = af.files && af.files[0];
      if (!f) return;
      var r = new FileReader();
      r.onload = function () { av.style.backgroundImage = "url(" + r.result + ")"; av.textContent = ""; };
      r.readAsDataURL(f);
    });
  }
  var toast = document.querySelector("[data-auto-dismiss]");
  if (toast) {
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add("show"); }); });
    setTimeout(function(){ toast.classList.add("hide"); setTimeout(function(){ toast.remove(); }, 350); }, 5000);
  }
})();
</script>';

        delivery_footer();
        exit;
    }

    $queue = [];
    $pool = [];
    $incoming = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.phone, o.address, o.note, o.payment, o.status, o.total,
                    o.created_at, o.rider_id, o.delivery_lat, o.delivery_lng
             FROM orders o
             WHERE (o.rider_id = :rid AND o.status IN ("Pending", "Accepted", "Preparing", "Ready for pickup", "Out for delivery"))
                OR (o.rider_id IS NULL AND o.status IN ("Pending", "Accepted", "Preparing", "Ready for pickup"))
             ORDER BY FIELD(o.status, "Pending", "Accepted", "Preparing", "Ready for pickup", "Out for delivery"), o.created_at ASC'
        );
        $rows->execute([':rid' => $riderId]);
        $orders = $rows->fetchAll();

        $itemStmt = $pdo->prepare(
            'SELECT oi.vendor_id, v.name AS vendor_name, v.phone AS vendor_phone,
                    ovs.status AS vendor_status, oi.hotel, oi.name, oi.qty, oi.line_total
             FROM order_items oi
             LEFT JOIN vendors v ON v.id = oi.vendor_id
             LEFT JOIN order_vendor_status ovs ON ovs.order_id = oi.order_id AND ovs.vendor_id = oi.vendor_id
             WHERE oi.order_id = ?
             ORDER BY oi.vendor_id IS NULL, oi.vendor_id, oi.id'
        );
        foreach ($orders as $row) {
            $itemStmt->execute([(int)$row['id']]);
            $vendors = [];
            $otherItems = [];
            foreach ($itemStmt->fetchAll() as $it) {
                $vid = (int)$it['vendor_id'];
                $line = ['name' => (string)$it['name'], 'qty' => (int)$it['qty'], 'line_total' => (int)$it['line_total']];
                if ($vid > 0) {
                    if (!isset($vendors[$vid])) {
                        $vendors[$vid] = [
                            'vendor_id' => $vid,
                            'name' => (string)$it['vendor_name'] !== '' ? (string)$it['vendor_name'] : (string)$it['hotel'],
                            'phone' => (string)$it['vendor_phone'],
                            'status' => (string)$it['vendor_status'],
                            'items' => [],
                        ];
                    }
                    $vendors[$vid]['items'][] = $line;
                } else {
                    $otherItems[] = $line;
                }
            }
            $row['vendors'] = array_values($vendors);
            $row['other_items'] = $otherItems;
            $unassigned = (int)$row['rider_id'] === 0 || $row['rider_id'] === null;
            $claimable = $unassigned && $row['status'] === 'Ready for pickup';
            $row['claimable'] = $claimable;
            if ($unassigned) {
                if ($claimable) {
                    $row['bucket'] = 'available';
                    $pool[] = $row;
                } else {
                    $row['bucket'] = 'incoming';
                    $incoming[] = $row;
                }
            } else {
                $row['bucket'] = 'mine';
                $queue[] = $row;
            }
        }
    } catch (Throwable $e) {
        $queue = [];
        $pool = [];
        $incoming = [];
    }

    delivery_header('Rider Dashboard', 'Your Delivery Queue', 'fa-motorcycle', $role);

    $historyCount = 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE rider_id = ? AND status = "Delivered"');
        $st->execute([$riderId]);
        $historyCount = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $historyCount = 0;
    }

    if ($flash) {
        $ftype = ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success';
        $ficon = $ftype === 'error' ? 'fa-circle-xmark' : 'fa-circle-check';
        echo '<div class="delivery-toast flash-banner flash-' . $ftype . '" data-auto-dismiss="1" role="status"><i class="fa-solid ' . $ficon . '"></i> ' . delivery_esc($flash['msg']) . '</div>';
    }

    $card = function (array $o) use ($riderId): void {
        $pill = match ($o['status']) {
            'Pending' => 'pending',
            'Accepted' => 'confirmed',
            'Preparing' => 'preparing',
            'Ready for pickup' => 'ready',
            default => 'delivery',
        };
        $claimable = !empty($o['claimable']);
        $statusFilter = (string)($o['bucket'] ?? 'mine');
        $statusIcon = match ($o['status']) {
            'Pending' => 'fa-bell',
            'Accepted' => 'fa-circle-check',
            'Preparing' => 'fa-fire-burner',
            'Ready for pickup' => 'fa-bag-shopping',
            default => 'fa-motorcycle',
        };
        $payIcon = $o['payment'] === 'Cash on Delivery' ? 'fa-money-bill-wave' : 'fa-wallet';
        $payClass = $o['payment'] === 'Cash on Delivery' ? 'cash' : 'wallet';
        $payLabel = $o['payment'] === 'Cash on Delivery' ? 'Cash on delivery' : 'Digital wallet';
        $itemCount = 0;
        $itemNames = [];
        foreach ($o['vendors'] as $v) {
            $itemNames[] = $v['name'];
            $itemCount += count($v['items']);
            foreach ($v['items'] as $it) {
                $itemNames[] = $it['name'];
            }
        }
        $itemCount += count($o['other_items']);
        foreach ($o['other_items'] as $it) {
            $itemNames[] = $it['name'];
        }
        $searchText = (int)$o['id'] . ' ' . $o['customer_name'] . ' ' . $o['phone'] . ' ' . $o['address'] . ' ' . $o['note'] . ' ' . $o['payment'] . ' ' . implode(' ', $itemNames);
        ?>
        <article class="delivery-card status-<?= $pill ?><?= $claimable ? ' claimable' : '' ?>"
                 data-order-id="<?= (int)$o['id'] ?>"
                 data-status="<?= $statusFilter ?>"
                 data-search="<?= delivery_esc(strtolower($searchText)) ?>">
            <div class="delivery-card-head">
                <div>
                    <h2>Order #<?= (int)$o['id'] ?>
                        <span class="order-status-pill status-<?= $pill ?>"><i class="fa-solid <?= $statusIcon ?>"></i> <?= delivery_esc($o['status']) ?></span>
                    </h2>
                    <p><span data-rel-time data-ts="<?= (int)strtotime((string)$o['created_at']) ?>"></span> · <?= delivery_esc($o['created_at']) ?></p>
                </div>
                <div class="delivery-total-wrap">
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                    <span class="delivery-pay <?= $payClass ?>"><i class="fa-solid <?= $payIcon ?>"></i> <?= $payLabel ?></span>
                </div>
            </div>
            <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <a href="tel:+977<?= delivery_esc($o['phone']) ?>">+977 <?= delivery_esc($o['phone']) ?></a></p>
            <p class="small-note"><i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?><?php if ($o['note']): ?> · <i class="fa-solid fa-note-sticky"></i> <?= delivery_esc($o['note']) ?><?php endif; ?></p>
            <?php if ($o['vendors']): ?>
            <div class="delivery-vendors">
                <?php foreach ($o['vendors'] as $v): ?>
                <div class="delivery-vendor">
                    <div class="delivery-vendor-head">
                        <span class="delivery-vendor-name"><i class="fa-solid <?= lyaideu_order_vendor_icon($v['name']) ?>"></i> <?= delivery_esc($v['name'] !== '' ? $v['name'] : 'Vendor') ?></span>
                        <?php if ($v['status'] !== ''): ?>
                        <span class="order-status-pill status-<?= $v['status'] === 'Rejected' ? 'cancelled' : lyaideu_order_pill_class($v['status']) ?>"><?= delivery_esc($v['status']) ?></span>
                        <?php endif; ?>
                        <?php if ($v['phone'] !== ''): ?>
                        <a class="delivery-vendor-call" href="tel:+977<?= delivery_esc($v['phone']) ?>"><i class="fa-solid fa-phone"></i> +977 <?= delivery_esc($v['phone']) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="delivery-items">
                        <?php foreach ($v['items'] as $it): ?>
                        <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                        <?php endforeach; ?>
                        <span class="delivery-item-count"><i class="fa-solid fa-receipt"></i> <?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($o['other_items']): ?>
                <div class="delivery-vendor">
                    <div class="delivery-vendor-head">
                        <span class="delivery-vendor-name"><i class="fa-solid fa-box"></i> Other items</span>
                    </div>
                    <div class="delivery-items">
                        <?php foreach ($o['other_items'] as $it): ?>
                        <span><?= delivery_esc($it['name']) ?> × <?= (int)$it['qty'] ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($o['delivery_lat'] !== null && $o['delivery_lat'] !== '' && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''): ?>
            <div class="rider-map" data-lat="<?= delivery_esc($o['delivery_lat']) ?>" data-lng="<?= delivery_esc($o['delivery_lng']) ?>"></div>
            <a class="btn btn-outline btn-sm" href="https://www.google.com/maps/dir/?api=1&destination=<?= delivery_esc($o['delivery_lat']) ?>,<?= delivery_esc($o['delivery_lng']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Get Directions</a>
            <?php endif; ?>
            <div class="delivery-actions">
                <?php if ($claimable): ?>
                <p class="delivery-waiting"><i class="fa-solid fa-bullhorn"></i> This order is ready — be the first rider to accept it.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                    <button type="submit" name="claim" value="<?= (int)$o['id'] ?>" class="btn btn-primary"><i class="fa-solid fa-hand-pointer"></i> Accept order</button>
                </form>
                <?php elseif ($o['status'] === 'Pending' || $o['status'] === 'Accepted' || $o['status'] === 'Preparing'): ?>
                <p class="delivery-waiting"><i class="fa-solid fa-hourglass-half"></i> Waiting for the vendor to prepare this order — you'll pick it up when it's ready.</p>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= delivery_esc(delivery_csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <?php if ($o['status'] === 'Ready for pickup'): ?>
                    <button type="submit" name="order_action" value="Out for delivery" class="btn btn-primary">Pick up & start delivery</button>
                    <?php else: ?>
                    <button type="submit" name="order_action" value="Delivered" class="btn btn-primary">Mark as delivered</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php
    };

    echo '<div class="delivery-toolbar">';
    echo '<div class="admin-order-search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" placeholder="Search order #, customer, phone, address or item…" aria-label="Search orders" data-order-search></div>';
    echo '<span class="delivery-count" data-result-count aria-live="polite"></span>';
    echo '</div>';

    echo '<div id="deliveryQueue">';
    echo '<div class="delivery-stats" role="group" aria-label="Filter orders by status">';
    $allCount = count($pool) + count($incoming) + count($queue);
    echo '<button type="button" class="delivery-stat stat-all active" data-stat-filter="all" aria-pressed="true"><span class="stat-ico"><i class="fa-solid fa-layer-group"></i></span><strong>' . $allCount . '</strong><span>All orders</span></button>';
    echo '<button type="button" class="delivery-stat stat-available" data-stat-filter="available" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-bullhorn"></i></span><strong>' . count($pool) . '</strong><span>Available</span></button>';
    echo '<button type="button" class="delivery-stat stat-incoming" data-stat-filter="incoming" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-clock"></i></span><strong>' . count($incoming) . '</strong><span>Incoming</span></button>';
    echo '<button type="button" class="delivery-stat stat-mine" data-stat-filter="mine" aria-pressed="false"><span class="stat-ico"><i class="fa-solid fa-motorcycle"></i></span><strong>' . count($queue) . '</strong><span>My deliveries</span></button>';
    echo '<button type="button" class="delivery-stat stat-history" data-history-scroll aria-label="View delivered orders"><span class="stat-ico"><i class="fa-solid fa-clock-rotate-left"></i></span><strong>' . $historyCount . '</strong><span>History</span></button>';
    echo '</div>';

    if (!$pool && !$incoming && !$queue) {
        echo '<div class="empty-state"><span class="big"><i class="fa-solid fa-motorcycle"></i></span><h3>Nothing on your plate</h3><p>No orders right now. As soon as a vendor marks an order ready, every rider is notified instantly — and the first to accept it picks it up.</p></div>';
    } else {
        echo '<div class="delivery-list-wrap"><div class="delivery-list">';
        foreach ($pool as $o) {
            $card($o);
        }
        foreach ($incoming as $o) {
            $card($o);
        }
        foreach ($queue as $o) {
            $card($o);
        }
        echo '</div>';
        echo '<div class="empty-state delivery-none" data-no-results style="display:none"><span class="big"><i class="fa-solid fa-filter-circle-xmark"></i></span><h3>No matching orders</h3><p>No orders match your filter or search. Try another status or clear your search.</p></div>';
        echo '</div>';
    }
    echo '</div>';

    $completed = [];
    try {
        $rows = $pdo->prepare(
            'SELECT o.id, o.customer_name, o.address, o.status, o.total, o.created_at, o.delivery_lat, o.delivery_lng
             FROM orders o
             WHERE o.rider_id = :rid AND o.status = "Delivered"
             ORDER BY o.created_at DESC LIMIT 20'
        );
        $rows->execute([':rid' => $riderId]);
        $completed = $rows->fetchAll();
    } catch (Throwable $e) {
        $completed = [];
    }

    if ($completed) {
        echo '<section class="delivery-section" id="deliveryHistory"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recently Delivered</h2><div class="delivery-list">';
        foreach ($completed as $o):
            ?>
            <article class="delivery-card status-delivered">
                <div class="delivery-card-head">
                    <div><h2>Order #<?= (int)$o['id'] ?> <span class="order-status-pill status-delivered"><?= delivery_esc($o['status']) ?></span></h2><p><?= delivery_esc($o['created_at']) ?></p></div>
                    <strong class="delivery-total">Rs. <?= (int)$o['total'] ?></strong>
                </div>
                <p class="delivery-customer"><i class="fa-solid fa-user"></i> <?= delivery_esc($o['customer_name']) ?> · <i class="fa-solid fa-location-dot"></i> <?= delivery_esc($o['address']) ?></p>
                <?php if ($o['delivery_lat'] !== null && $o['delivery_lat'] !== '' && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''): ?>
                <a class="btn btn-outline btn-sm" href="https://www.google.com/maps/dir/?api=1&destination=<?= delivery_esc($o['delivery_lat']) ?>,<?= delivery_esc($o['delivery_lng']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Get Directions</a>
                <?php endif; ?>
            </article>
        <?php endforeach;
        echo '</div></section>';
    }

    echo '<script>
(function(){
  var activeFilter = "all";
  try { var saved = localStorage.getItem("lyaidu_rider_filter"); if (saved) activeFilter = saved; } catch(e){}

  function searchValue(){
    var el = document.querySelector("[data-order-search]");
    return el ? (el.value || "").trim().toLowerCase() : "";
  }
  function timeAgo(ts){
    var s = Math.floor(Date.now()/1000) - ts;
    if (s < 45) return "just now";
    if (s < 90) return "1 min ago";
    if (s < 3600) return Math.floor(s/60) + " min ago";
    if (s < 86400) return Math.floor(s/3600) + " hr ago";
    return Math.floor(s/86400) + " days ago";
  }
  function showToast(msg){
    var el = document.createElement("div");
    el.className = "delivery-toast flash-banner flash-success delivery-flash";
    el.innerHTML = msg;
    document.body.appendChild(el);
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add("show"); }); });
    setTimeout(function(){ el.classList.add("hide"); setTimeout(function(){ el.remove(); }, 350); }, 3500);
  }
  function tickTimes(){
    document.querySelectorAll("[data-rel-time]").forEach(function(el){
      var ts = parseInt(el.getAttribute("data-ts") || "0", 10);
      if (ts) el.textContent = timeAgo(ts);
    });
  }
  function applyFilter(){
    var queue = document.getElementById("deliveryQueue");
    if (!queue) return;
    var cards = queue.querySelectorAll(".delivery-list .delivery-card[data-status]");
    var total = cards.length, visible = 0;
    var q = searchValue();
    cards.forEach(function(card){
      var show = activeFilter === "all" || card.getAttribute("data-status") === activeFilter;
      if (show && q) show = (card.getAttribute("data-search") || "").toLowerCase().indexOf(q) !== -1;
      card.style.display = show ? "" : "none";
      if (show) visible++;
    });
    var none = queue.querySelector("[data-no-results]");
    if (none) none.style.display = (total > 0 && visible === 0) ? "" : "none";
    var cnt = document.querySelector("[data-result-count]");
    if (cnt) cnt.innerHTML = visible === total
      ? "<b>" + total + "</b> order" + (total === 1 ? "" : "s")
      : "Showing <b>" + visible + "</b> of <b>" + total + "</b>";
    queue.querySelectorAll("[data-stat-filter]").forEach(function(b){
      var on = b.getAttribute("data-stat-filter") === activeFilter;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }

  document.addEventListener("click", function(e){
    var hbtn = e.target && e.target.closest ? e.target.closest("[data-history-scroll]") : null;
    if (hbtn) {
      var sec = document.getElementById("deliveryHistory");
      if (sec) {
        sec.scrollIntoView({ behavior: "smooth", block: "start" });
        sec.classList.remove("history-flash");
        void sec.offsetWidth;
        sec.classList.add("history-flash");
        setTimeout(function(){ sec.classList.remove("history-flash"); }, 2200);
      } else {
        showToast(\'<i class="fa-solid fa-clock-rotate-left"></i> No delivered orders yet.\');
      }
      return;
    }
    var btn = e.target && e.target.closest ? e.target.closest("[data-stat-filter]") : null;
    if (btn) {
      activeFilter = btn.getAttribute("data-stat-filter") || "all";
      try { localStorage.setItem("lyaidu_rider_filter", activeFilter); } catch(err){}
      applyFilter();
      return;
    }
    var rj = e.target && e.target.closest ? e.target.closest("[data-confirm]") : null;
    if (rj && !window.confirm(rj.getAttribute("data-confirm") || "Are you sure?")) e.preventDefault();
  });
  document.addEventListener("input", function(e){
    if (e.target && e.target.hasAttribute && e.target.hasAttribute("data-order-search")) applyFilter();
  });

  if (window.MutationObserver) {
    var queueEl = document.getElementById("deliveryQueue");
    var mo = new MutationObserver(function(){
      var cur = document.getElementById("deliveryQueue");
      if (cur !== queueEl) { queueEl = cur; applyFilter(); tickTimes(); }
    });
    mo.observe(document.body, { childList: true, subtree: true });
  }

  var toast = document.querySelector("[data-auto-dismiss]");
  if (toast) {
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add("show"); }); });
    setTimeout(function(){ toast.classList.add("hide"); setTimeout(function(){ toast.remove(); }, 350); }, 5000);
  }

  applyFilter();
  tickTimes();
  setInterval(tickTimes, 30000);
})();
</script>';

    delivery_footer();
    exit;
}
