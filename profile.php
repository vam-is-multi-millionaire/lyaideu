<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login?next=' . urlencode('profile'));
    exit;
}
$user = $_SESSION['user'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_config.php';

lyaideu_ensure_kyc_tables();
lyaideu_ensure_location_columns();

$uid = (int)$user['id'];
$profile = lyaideu_user_profile($uid);
if ($profile === null) {
    $profile = array_merge([
        'id' => $uid, 'name' => $user['name'] ?? '', 'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '', 'dob' => $user['dob'] ?? '', 'avatar' => '',
        'address' => '', 'home_lat' => null, 'home_lng' => null, 'home_address' => '',
        'kyc_status' => 'none', 'kyc_reason' => '',
        'kyc_submitted_at' => null, 'kyc_reviewed_at' => null, 'kyc_reviewer' => '',
    ], $user);
}

if (!isset($_SESSION['csrf_profile'])) {
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_profile'];

$docTypes = ['Citizenship', 'NID Card', 'Passport', 'Birth Certificate', 'Driving License', 'Voter ID', 'Other'];
$kycStatusLabels = [
    'none' => 'Not submitted', 'pending' => 'Under review',
    'approved' => 'Verified', 'rejected' => 'Rejected',
];

function profile_flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: profile');
    exit;
}
function profile_valid_name(string $name): bool {
    return mb_strlen($name) >= 3 && preg_match('/^[\p{L}\s\'.]+$/u', $name);
}
function profile_valid_phone(string $phone): bool {
    return preg_match('/^9[78]\d{8}$/', $phone);
}
function profile_valid_dob(string $dob): bool {
    $today = new DateTimeImmutable('today');
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
    if (!$birth || $birth->format('Y-m-d') !== $dob || $birth > $today) {
        return false;
    }
    $age = $today->diff($birth)->y;
    return $age >= 10 && $age <= 80;
}

$kycDocs = [];
try {
    $docStmt = $pdo->prepare('SELECT id, doc_type, file, uploaded_at FROM user_documents WHERE user_id = ? ORDER BY id');
    $docStmt->execute([$uid]);
    $kycDocs = $docStmt->fetchAll();
} catch (Throwable $e) {
    $kycDocs = [];
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    profile_flash('error', 'Invalid security token. Please refresh and try again.');
}

if ($post && isset($_POST['save_profile'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? ''));
    $address = trim(strip_tags((string)($_POST['address'] ?? '')));

    $errors = [];
    if (!profile_valid_name($name)) {
        $errors[] = 'Full name must be at least 3 letters (only letters and spaces).';
    }
    if (!profile_valid_phone($phone)) {
        $errors[] = 'Contact number must be exactly 10 digits and start with 97 or 98.';
    }
    if (!profile_valid_dob($dob)) {
        $errors[] = 'Please enter a valid date of birth (10 to 80 years old).';
    }
    if (empty($errors)) {
        try {
            $chk = $pdo->prepare('SELECT id FROM users WHERE phone = :phone AND id <> :id LIMIT 1');
            $chk->execute([':phone' => $phone, ':id' => $uid]);
            if ($chk->fetch()) {
                $errors[] = 'This contact number is already used by another account.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not check your phone number right now.';
        }
    }

    if (empty($errors)) {
        try {
            $upd = $pdo->prepare('UPDATE users SET name = :name, phone = :phone, dob = :dob, address = :address WHERE id = :id');
            $upd->execute([
                ':name' => $name, ':phone' => $phone, ':dob' => $dob,
                ':address' => mb_substr($address, 0, 500), ':id' => $uid,
            ]);
            $_SESSION['user'] = array_merge($_SESSION['user'], [
                'name' => $name, 'phone' => $phone, 'dob' => $dob, 'address' => $address,
            ]);
            profile_flash('success', 'Your profile has been updated.');
        } catch (Throwable $e) {
            $errors[] = 'Could not save your profile. Please try again.';
        }
    }

    if (!empty($errors)) {
        profile_flash('error', implode('<br>', $errors));
    }
}

if ($post && isset($_POST['save_avatar'])) {
    $errors = [];
    $avatar = $profile['avatar'];
    try {
        $avatar = lyaideu_handle_item_image($profile['avatar'], $_POST, $_FILES['avatar_file'] ?? null, 'user_avatar');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    if (empty($errors)) {
        try {
            $pdo->prepare('UPDATE users SET avatar = :avatar WHERE id = :id')->execute([':avatar' => $avatar, ':id' => $uid]);
            $_SESSION['user']['avatar'] = $avatar;
            profile_flash('success', 'Profile photo updated.');
        } catch (Throwable $e) {
            $errors[] = 'Could not save your profile photo. Please try again.';
        }
    }
    if (!empty($errors)) {
        profile_flash('error', implode('<br>', $errors));
    }
}

if ($post && isset($_POST['remove_avatar'])) {
    try {
        if ($profile['avatar'] !== '') {
            lyaideu_delete_upload($profile['avatar']);
            $pdo->prepare('UPDATE users SET avatar = \'\' WHERE id = ?')->execute([$uid]);
        }
        $_SESSION['user']['avatar'] = '';
        profile_flash('success', 'Profile photo removed.');
    } catch (Throwable $e) {
        profile_flash('error', 'Could not remove your profile photo.');
    }
}

if ($post && isset($_POST['save_home'])) {
    $rawLat = trim((string)($_POST['home_lat'] ?? ''));
    $rawLng = trim((string)($_POST['home_lng'] ?? ''));
    $homeAddress = trim(strip_tags((string)($_POST['home_address'] ?? '')));
    $errors = [];

    if ($rawLat === '' && $rawLng === '') {
        try {
            $pdo->prepare('UPDATE users SET home_lat = NULL, home_lng = NULL, home_address = ? WHERE id = ?')
                ->execute([mb_substr($homeAddress, 0, 500), $uid]);
            profile_flash('success', 'Home location cleared.');
        } catch (Throwable $e) {
            profile_flash('error', 'Could not save your home location. Please try again.');
        }
    } else {
        if (!lyaideu_valid_coord($rawLat, true) || !lyaideu_valid_coord($rawLng, false)) {
            $errors[] = 'The map pin is outside a valid range. Drop the pin again and save.';
        }
        if (empty($errors)) {
            try {
                $upd = $pdo->prepare('UPDATE users SET home_lat = :lat, home_lng = :lng, home_address = :addr WHERE id = :id');
                $upd->execute([
                    ':lat' => (float)$rawLat,
                    ':lng' => (float)$rawLng,
                    ':addr' => mb_substr($homeAddress, 0, 500),
                    ':id' => $uid,
                ]);
                profile_flash('success', 'Home location saved. It will be pre-filled at checkout.');
            } catch (Throwable $e) {
                $errors[] = 'Could not save your home location. Please try again.';
            }
        }
        if (!empty($errors)) {
            profile_flash('error', implode('<br>', $errors));
        }
    }
}

if ($post && isset($_POST['kyc_remove_doc'])) {
    $docId = (int)($_POST['kyc_remove_doc'] ?? 0);
    $locked = ($profile['kyc_status'] === 'approved' || $profile['kyc_status'] === 'pending');
    if ($docId > 0 && !$locked) {
        try {
            $st = $pdo->prepare('SELECT file FROM user_documents WHERE id = ? AND user_id = ? LIMIT 1');
            $st->execute([$docId, $uid]);
            $doc = $st->fetch();
            if ($doc) {
                lyaideu_delete_upload((string)$doc['file']);
                $pdo->prepare('DELETE FROM user_documents WHERE id = ? AND user_id = ?')->execute([$docId, $uid]);
                profile_flash('success', 'Document removed.');
            }
        } catch (Throwable $e) {
            profile_flash('error', 'Could not remove that document.');
        }
    }
    profile_flash('error', $locked ? 'Documents cannot be changed while your KYC is under review.' : 'Could not remove that document.');
}

if ($post && isset($_POST['submit_kyc'])) {
    if ($profile['kyc_status'] === 'approved' || $profile['kyc_status'] === 'pending') {
        profile_flash('error', 'Your KYC is already ' . $kycStatusLabels[$profile['kyc_status']] . '.');
    }

    $errors = [];
    if (trim((string)($profile['avatar'] ?? '')) === '') {
        $errors[] = 'Upload your profile photo (a clear photo of your face) — it is compulsory for verification.';
    }
    if (!profile_valid_phone((string)$profile['phone'])) {
        $errors[] = 'Your contact number must be a valid 10-digit number starting with 97 or 98. Update it in Personal info first.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $types = $_POST['kyc_doc_type'] ?? [];
            $files = $_FILES['kyc_doc_file']['name'] ?? [];

            if (!is_array($types)) {
                $types = [];
            }
            $uploadedAny = false;
            foreach ($types as $i => $rawType) {
                $type = trim((string)$rawType);
                if ($type === '') {
                    continue;
                }
                $file = [
                    'name' => (string)($files[$i] ?? ''),
                    'type' => (string)($_FILES['kyc_doc_file']['type'][$i] ?? ''),
                    'tmp_name' => (string)($_FILES['kyc_doc_file']['tmp_name'][$i] ?? ''),
                    'error' => (int)($_FILES['kyc_doc_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($_FILES['kyc_doc_file']['size'][$i] ?? 0),
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE || $file['tmp_name'] === '') {
                    continue;
                }
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('One of the document uploads failed. Please try again.');
                }
                $path = lyaideu_handle_kyc_document('', $file, 'kyc_doc');
                if ($path !== '') {
                    $ins = $pdo->prepare('INSERT INTO user_documents (user_id, doc_type, file, uploaded_at) VALUES (?, ?, ?, ?)');
                    $ins->execute([$uid, mb_substr($type, 0, 50), $path, date('Y-m-d H:i:s')]);
                    $uploadedAny = true;
                }
            }

            $cntStmt = $pdo->query('SELECT COUNT(*) FROM user_documents WHERE user_id = ' . (int)$uid);
            $docCount = (int)$cntStmt->fetchColumn();

            if (!$uploadedAny && $docCount === 0) {
                throw new RuntimeException('Attach at least one ID document (Citizenship, NID card, birth certificate, etc.).');
            }

            $upd = $pdo->prepare(
                'UPDATE users SET kyc_status = \'pending\', kyc_reason = \'\', kyc_submitted_at = ?, kyc_reviewed_at = NULL, kyc_reviewer = \'\' WHERE id = ?'
            );
            $upd->execute([date('Y-m-d H:i:s'), $uid]);

            $pdo->commit();
            $_SESSION['user']['kyc_status'] = 'pending';
            profile_flash('success', 'Your KYC documents were submitted for review. We\'ll verify them shortly.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            profile_flash('error', $e->getMessage());
        }
    } else {
        profile_flash('error', implode('<br>', $errors));
    }
}

// Reload fresh data after any write above.
$profile = lyaideu_user_profile($uid) ?? $profile;
try {
    $docStmt = $pdo->prepare('SELECT id, doc_type, file, uploaded_at FROM user_documents WHERE user_id = ? ORDER BY id');
    $docStmt->execute([$uid]);
    $kycDocs = $docStmt->fetchAll();
} catch (Throwable $e) {
    $kycDocs = [];
}
$profile = array_merge($user, $profile);

$parts = preg_split('/\s+/', trim($profile['name']));
$firstName = $parts[0] ?? '';
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
$avatarUrl = htmlspecialchars((string)$profile['avatar'], ENT_QUOTES, 'UTF-8');
$kycStatus = (string)($profile['kyc_status'] ?? 'none');
$kycPillClass = 'kyc-' . htmlspecialchars($kycStatus, ENT_QUOTES, 'UTF-8');
$kycLocked = ($kycStatus === 'approved' || $kycStatus === 'pending');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= lyaideu_base_tag() ?>
<title>My Profile | LyaiDeu</title>
<?= site_head_icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="css/style.css?v=42">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body class="profile-body">

<!-- Mobile-only compact header: replaces the topbar on phones (desktop keeps the standard nav) -->
<header class="profile-mheader">
    <a class="pmh-btn" id="pmhBack" href="index" aria-label="Go back"><i class="fa-solid fa-arrow-left"></i></a>
    <span class="pmh-title">My Profile</span>
    <button class="pmh-btn" id="pmhSettings" type="button" aria-label="Edit photo and personal info" aria-expanded="false" aria-controls="profileEditGroup"><i class="fa-solid fa-gear"></i></button>
</header>

<header class="topbar">
    <nav class="nav">
        <a class="brand" href="index"><img class="brand-logo" src="<?= htmlspecialchars(site_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="LyaiDeu">Lyai<span>Deu</span></a>
        <form class="nav-search" action="menu" method="get" role="search"><span class="search-ico"><i class="fa-solid fa-magnifying-glass"></i></span><input type="search" name="q" placeholder="Search in LyaiDeu" aria-label="Search the menu"></form>
        <button class="nav-toggle" id="navToggle"><span></span><span></span><span></span></button>
        <ul class="nav-links" id="navLinks">
            <li><a href="index" class="nav-a">Home</a></li>
            <li><a href="menu" class="nav-a">Menu</a></li>
            <li><a href="mart" class="nav-a">Mart</a></li>
            <li><a href="beverages" class="nav-a">Beverages</a></li>
            <li><a href="others" class="nav-a">Others</a></li>
            <li><a href="store" class="nav-a">Stores</a></li>
            <li>
                <div class="profile-wrap">
                    <button class="profile-chip" id="profileChip" type="button">
                        <span class="avatar"<?= $avatarUrl !== '' ? ' style="background-image:url(\'' . $avatarUrl . '\')"' : '' ?>><?= $avatarUrl === '' ? htmlspecialchars($initials) : '' ?></span>
                        <span class="chip-name"><?= htmlspecialchars($firstName) ?></span>
                        <span class="caret"><i class="fa-solid fa-chevron-down"></i></span>
                    </button>
                    <div class="profile-menu" id="profileMenu">
                        <p class="pm-name"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($profile['name']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($profile['email']) ?></p>
                        <p class="pm-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($profile['phone']) ?></p>
                        <a class="btn btn-outline btn-block" href="profile" style="margin-top:.5rem;"><i class="fa-solid fa-user-gear"></i> My Profile</a>
                        <a class="btn btn-outline btn-block" href="orders" style="margin-top:.5rem;"><i class="fa-solid fa-box"></i> My Orders</a>
                        <a class="btn btn-primary btn-block" href="logout" style="margin-top:.5rem; background:#c93a3a; box-shadow:0 5px 0 #a02a2a;">Log Out</a>
                    </div>
                </div>
            </li>
        </ul>
    </nav>
</header>

<main class="profile-page container">
    <!-- Mobile-only identity card (desktop shows this info in the topbar dropdown) -->
    <section class="profile-idcard" id="profileIdCard">
        <span class="avatar pidc-avatar"<?= $avatarUrl !== '' ? ' style="background-image:url(\'' . $avatarUrl . '\')"' : '' ?>><?= $avatarUrl === '' ? htmlspecialchars($initials) : '' ?></span>
        <div class="pidc-meta">
            <strong class="pidc-name"><?= htmlspecialchars($profile['name']) ?></strong>
            <span class="pidc-line"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($profile['email']) ?></span>
            <span class="pidc-line"><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($profile['phone']) ?></span>
        </div>
        <span class="order-status-pill <?= $kycPillClass ?>"><?= htmlspecialchars($kycStatusLabels[$kycStatus] ?? $kycStatus, ENT_QUOTES, 'UTF-8') ?></span>
    </section>

    <!-- Mobile-only quick link: My Orders lives here instead of the header -->
    <a class="orders-row" href="orders">
        <span class="or-ico"><i class="fa-solid fa-box"></i></span>
        <span class="or-txt"><strong>My Orders</strong><small>Track your khaja, mart &amp; beverage orders</small></span>
        <i class="fa-solid fa-chevron-right or-chev" aria-hidden="true"></i>
    </a>

    <div class="section-head">
        <h1 class="display">My Profile</h1>
        <p class="section-sub">Keep your details up to date and complete the KYC verification so you can order.</p>
    </div>

    <?php if ($flash): ?><div class="flash-banner flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= $flash['msg'] ?></div><?php endif; ?>

    <?php if ($kycStatus !== 'approved'): ?>
    <div class="kyc-gate-banner <?= $kycStatus === 'rejected' ? 'is-rejected' : '' ?>">
        <?php if ($kycStatus === 'none'): ?>
            <i class="fa-solid fa-shield-halved"></i> <b>Identity verification required.</b> Complete your KYC below to start ordering.
        <?php elseif ($kycStatus === 'pending'): ?>
            <i class="fa-solid fa-hourglass-half"></i> <b>KYC under review.</b> You can't place orders until an admin verifies your identity.
        <?php else: ?>
            <i class="fa-solid fa-triangle-exclamation"></i> <b>KYC was rejected.</b> <?= htmlspecialchars((string)$profile['kyc_reason'], ENT_QUOTES, 'UTF-8') ?: 'Please correct the documents and resubmit.' ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="profile-grid">
        <!-- Photo + personal info live inside this group. Desktop renders it in
             the grid as usual; on phones it becomes a bottom-sheet overlay
             opened by the gear button in the header. -->
        <div class="profile-edit-group" id="profileEditGroup">
            <div class="peg-chrome">
                <strong class="peg-title"><i class="fa-solid fa-user-pen"></i> Edit your details</strong>
                <button type="button" class="peg-close" id="peClose" aria-label="Close editor">&times;</button>
            </div>
            <form class="profile-card" method="POST" enctype="multipart/form-data" action="profile">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <h2><i class="fa-solid fa-camera"></i> Profile photo</h2>
                <div class="avatar-upload">
                    <div class="avatar-preview" id="avatarPreview"<?= $avatarUrl !== '' ? ' style="background-image:url(\'' . $avatarUrl . '\')" data-lightbox="' . $avatarUrl . '" data-lightbox-caption="' . htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= $avatarUrl === '' ? htmlspecialchars($initials) : '' ?></div>
                    <label class="btn btn-outline" for="avatarFile" style="cursor:pointer;"><i class="fa-solid fa-upload"></i> Upload photo</label>
                    <input type="file" id="avatarFile" name="avatar_file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                    <p class="small-note">A clear photo of your face. Compulsory for KYC verification.</p>
                    <?php if ($avatarUrl !== ''): ?>
                    <button type="submit" name="remove_avatar" value="1" class="btn btn-outline btn-sm"><i class="fa-solid fa-trash"></i> Remove photo</button>
                    <?php endif; ?>
                    <button type="submit" name="save_avatar" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save photo</button>
                </div>
            </form>

            <form class="profile-card" method="POST" action="profile">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <h2><i class="fa-solid fa-user"></i> Personal information</h2>
                <label>Full Name<input name="name" value="<?= htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Gmail <span class="muted">(login email)</span><input value="<?= htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8') ?>" readonly disabled></label>
                <label>Phone<input name="phone" value="<?= htmlspecialchars($profile['phone'], ENT_QUOTES, 'UTF-8') ?>" required inputmode="numeric" maxlength="10"></label>
                <label>Date of Birth<input type="date" name="dob" value="<?= htmlspecialchars($profile['dob'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Location / Address<input name="address" value="<?= htmlspecialchars($profile['address'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Area / street / tole"></label>
                <button type="submit" name="save_profile" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
            </form>
        </div>

        <form class="profile-card" method="POST" action="profile">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <h2><i class="fa-solid fa-house-chimney"></i> Home location</h2>
            <p class="small-note">Drop a pin on the map for your home — or tap <b>Use my current location</b>. We'll pre-fill it at checkout and your rider will see it.</p>
            <div id="homeMap" class="loc-map"></div>
            <input type="hidden" name="home_lat" id="homeLat" value="<?= htmlspecialchars((string)($profile['home_lat'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="home_lng" id="homeLng" value="<?= htmlspecialchars((string)($profile['home_lng'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label>Home address<input name="home_address" id="homeAddr" value="<?= htmlspecialchars((string)$profile['home_address'], ENT_QUOTES, 'UTF-8') ?>" placeholder="House / street / area / landmark"></label>
            <div class="map-actions">
                <button type="button" class="btn btn-outline" id="homeLocBtn"><i class="fa-solid fa-crosshairs"></i> Use my current location</button>
            </div>
            <p class="small-note" id="homeLocMsg"></p>
            <button type="submit" name="save_home" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-location-dot"></i> Save home location</button>
        </form>

        <form class="profile-card profile-card-wide" method="POST" enctype="multipart/form-data" action="profile">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <h2><i class="fa-solid fa-shield-halved"></i> KYC verification</h2>
            <div class="kyc-status-row">
                <span class="order-status-pill <?= $kycPillClass ?>"><?= htmlspecialchars($kycStatusLabels[$kycStatus] ?? $kycStatus, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($kycStatus === 'approved'): ?>
                    <p class="kyc-ok-note"><i class="fa-solid fa-circle-check"></i> You're verified — you can place orders.</p>
                <?php elseif ($kycStatus === 'pending'): ?>
                    <p class="small-note">Submitted <?= htmlspecialchars((string)$profile['kyc_submitted_at'], ENT_QUOTES, 'UTF-8') ?>. We'll review your documents shortly.</p>
                <?php elseif ($kycStatus === 'rejected'): ?>
                    <p class="kyc-reject-note"><i class="fa-solid fa-triangle-exclamation"></i> Reason: <?= htmlspecialchars((string)$profile['kyc_reason'], ENT_QUOTES, 'UTF-8') ?: 'No reason given.' ?></p>
                <?php endif; ?>
            </div>

            <div class="kyc-checks">
                <div class="kyc-check <?= $avatarUrl !== '' ? 'done' : '' ?>"><i class="fa-solid fa-user"></i> Profile photo (face) — <?= $avatarUrl !== '' ? 'uploaded' : '<b>required</b>' ?></div>
                <div class="kyc-check <?= profile_valid_phone((string)$profile['phone']) ? 'done' : '' ?>"><i class="fa-solid fa-mobile-screen"></i> Valid phone number — <?= profile_valid_phone((string)$profile['phone']) ? 'verified' : '<b>invalid</b>' ?></div>
                <div class="kyc-check <?= count($kycDocs) > 0 ? 'done' : '' ?>"><i class="fa-solid fa-file-shield"></i> ID documents — <?= count($kycDocs) > 0 ? count($kycDocs) . ' attached' : '<b>required</b>' ?></div>
            </div>

            <h3 class="kyc-sub"><i class="fa-solid fa-paperclip"></i> Your documents</h3>
            <?php if ($kycDocs): ?>
                <ul class="kyc-doc-list">
                <?php foreach ($kycDocs as $doc):
                    $docFile = htmlspecialchars((string)$doc['file'], ENT_QUOTES, 'UTF-8');
                    $isPdf = strtolower(pathinfo((string)$doc['file'], PATHINFO_EXTENSION)) === 'pdf';
                ?>
                    <li>
                        <span class="kyc-doc-ico"><?= $isPdf ? '<i class="fa-solid fa-file-pdf"></i>' : '<i class="fa-solid fa-file-image"></i>' ?></span>
                        <span class="kyc-doc-name"><?= htmlspecialchars((string)$doc['doc_type'], ENT_QUOTES, 'UTF-8') ?> <small><?= htmlspecialchars((string)$doc['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></small></span>
                        <?php if ($isPdf): ?>
                        <a class="btn btn-outline btn-sm" href="<?= $docFile ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> View</a>
                        <?php else: ?>
                        <a class="btn btn-outline btn-sm" href="<?= $docFile ?>" data-lightbox="<?= $docFile ?>" data-lightbox-caption="<?= htmlspecialchars((string)$doc['doc_type'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-expand"></i> View</a>
                        <?php endif; ?>
                        <?php if (!$kycLocked): ?>
                        <button type="submit" name="kyc_remove_doc" value="<?= (int)$doc['id'] ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-trash"></i> Remove</button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small-note">No documents uploaded yet.</p>
            <?php endif; ?>

            <?php if (!$kycLocked): ?>
                <h3 class="kyc-sub"><i class="fa-solid fa-plus"></i> Add ID documents</h3>
                <p class="small-note">Upload your Citizenship, NID card, Passport, Birth Certificate, Driving License, Voter ID or other valid ID (image or PDF, max 5 MB each).</p>
                <div id="kycDocRows">
                    <div class="kyc-doc-row">
                        <select name="kyc_doc_type[]">
                            <?php foreach ($docTypes as $dt): ?><option value="<?= htmlspecialchars($dt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dt, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                        <input type="file" name="kyc_doc_file[]" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" required>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" id="kycAddDoc"><i class="fa-solid fa-plus"></i> Add another document</button>
                <button type="submit" name="submit_kyc" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> Submit for review</button>
            <?php elseif ($kycStatus === 'approved'): ?>
                <p class="kyc-ok-note"><i class="fa-solid fa-lock"></i> Verification complete — documents are locked.</p>
            <?php else: ?>
                <p class="small-note"><i class="fa-solid fa-lock"></i> Documents are locked while your KYC is under review.</p>
            <?php endif; ?>
        </form>
    </div>

    <a class="btn btn-primary btn-block profile-mobile-logout" href="logout" style="background:#c93a3a;border-color:#c93a3a;box-shadow:0 5px 0 #a02a2a;margin-top:1.4rem;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out</a>
</main>

<!-- Mobile-only backdrop for the edit bottom sheet -->
<div class="pe-backdrop" id="peBackdrop"></div>

<script>
(function () {
    var backBtn = document.getElementById('pmhBack');
    if (backBtn && window.history) {
        var sameOriginRef = false;
        try {
            sameOriginRef = !!document.referrer && new URL(document.referrer).origin === location.origin;
        } catch (err) { sameOriginRef = false; }
        backBtn.addEventListener('click', function (e) {
            if (sameOriginRef && window.history.length > 1) {
                e.preventDefault();
                window.history.back();
            }
        });
    }
    var gear = document.getElementById('pmhSettings'),
        sheet = document.getElementById('profileEditGroup'),
        backdrop = document.getElementById('peBackdrop'),
        closeBtn = document.getElementById('peClose');
    function sheetOpen() { return !!(sheet && sheet.classList.contains('open')); }
    function openSheet() {
        if (!sheet) return;
        sheet.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        document.body.classList.add('pe-lock');
        if (gear) gear.setAttribute('aria-expanded', 'true');
    }
    function closeSheet() {
        if (!sheet) return;
        sheet.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.classList.remove('pe-lock');
        if (gear) gear.setAttribute('aria-expanded', 'false');
    }
    if (gear && sheet) {
        gear.addEventListener('click', function () {
            if (sheetOpen()) { closeSheet(); } else { openSheet(); }
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeSheet);
    if (backdrop) backdrop.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });
    var av = document.getElementById('avatarPreview');
    var af = document.getElementById('avatarFile');
    if (av && af) {
        af.addEventListener('change', function () {
            var f = af.files && af.files[0];
            if (!f) return;
            var r = new FileReader();
            r.onload = function () { av.style.backgroundImage = 'url(' + r.result + ')'; av.textContent = ''; };
            r.readAsDataURL(f);
        });
    }
    var wrap = document.getElementById('kycDocRows');
    var addBtn = document.getElementById('kycAddDoc');
    if (wrap && addBtn) {
        var types = <?= json_encode(array_values($docTypes)) ?>;
        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'kyc-doc-row';
            var opts = types.map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('');
            row.innerHTML = '<select name="kyc_doc_type[]">' + opts + '</select>' +
                '<input type="file" name="kyc_doc_file[]" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" required>' +
                '<button type="button" class="btn btn-outline btn-sm kyc-row-remove"><i class="fa-solid fa-xmark"></i></button>';
            wrap.appendChild(row);
            row.querySelector('.kyc-row-remove').addEventListener('click', function () { row.remove(); });
        });
    }
})();
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var mapEl = document.getElementById('homeMap');
    if (!mapEl || typeof L === 'undefined') return;
    var latIn = document.getElementById('homeLat'),
        lngIn = document.getElementById('homeLng'),
        addrIn = document.getElementById('homeAddr'),
        msg = document.getElementById('homeLocMsg'),
        btn = document.getElementById('homeLocBtn');
    var startLat = parseFloat(latIn.value) || 28.5967,
        startLng = parseFloat(lngIn.value) || 81.6166;
    var map = L.map('homeMap', { scrollWheelZoom: false, attributionControl: false }).setView([startLat, startLng], latIn.value ? 15 : 14);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    var marker = L.marker([startLat, startLng], { draggable: true }).addTo(map).bindPopup('Drop the pin to set your home');
    function setPos(lat, lng, reverse) {
        marker.setLatLng([lat, lng]);
        map.panTo([lat, lng]);
        latIn.value = lat.toFixed(7);
        lngIn.value = lng.toFixed(7);
        if (reverse && window.fetch && addrIn) {
            fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + lat + '&lon=' + lng, { headers: { 'Accept-Language': 'en' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var a = d && d.display_name;
                    if (a && !addrIn.value.trim()) addrIn.value = a.split(',').slice(0, 3).join(',');
                })
                .catch(function () {});
        }
    }
    marker.on('dragend', function () {
        var ll = marker.getLatLng();
        setPos(ll.lat, ll.lng, true);
    });
    if (btn) btn.addEventListener('click', function () {
        var b = this;
        b.disabled = true;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating…';
        window.LYAIDEU_LOC.request(function (err, pos) {
            b.disabled = false;
            b.innerHTML = '<i class="fa-solid fa-crosshairs"></i> Use my current location';
            if (err) {
                if (msg) msg.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Could not get your location. Check the browser permission and try again.';
                return;
            }
            if (msg) msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> Current location set — drag the pin to fine-tune.';
            setPos(pos.lat, pos.lng, true);
        });
    });
})();
</script>
<script src="js/lightbox.js?v=2"></script>
<script src="js/script.js?v=26"></script>
<script src="js/scroll-memory.js?v=5"></script>
<script src="js/notify.js?v=6"></script>
</body>
</html>