<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/site_config.php';

$pdo = lyaideu_load_pdo();
lyaideu_ensure_settings_table();

function settings_redirect(bool $saved, ?string $error = null): void {
    if ($saved) {
        header('Location: admin_settings?saved=1');
    } else {
        header('Location: admin_settings?error=' . urlencode($error ?? 'Could not save settings.'));
    }
    exit;
}

function save_site_image(string $fileKey, string $settingKey, string $friendlyName): bool {
    if (empty($_FILES[$fileKey]) || ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    if ($_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        settings_redirect(false, $friendlyName . ' upload failed. Please try again.');
    }

    $file = $_FILES[$fileKey];
    if ($file['size'] > 2 * 1024 * 1024) {
        settings_redirect(false, $friendlyName . ' is too large (max 2 MB).');
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        settings_redirect(false, $friendlyName . ' must be a PNG, JPG, WebP, GIF, SVG or ICO image.');
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            settings_redirect(false, 'Could not create the uploads folder.');
        }
    }

    $ext = $allowed[$mime];
    $filename = $settingKey . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        settings_redirect(false, 'Could not save the uploaded ' . $friendlyName . '.');
    }

    $relative = 'uploads/' . $filename;
    try {
        $update = lyaideu_load_pdo()->prepare(
            'INSERT INTO settings (skey, sval) VALUES (:skey, :sval)
             ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
        );
        $update->execute([':skey' => $settingKey, ':sval' => $relative]);
    } catch (Throwable $e) {
        @unlink($dest);
        settings_redirect(false, 'Could not store settings in the database.');
    }

    $old = site_setting($settingKey, '');
    lyaideu_settings_clear();
    if ($old !== '' && $old !== $relative && str_starts_with($old, 'uploads/')) {
        @unlink(__DIR__ . '/' . $old);
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please reload the admin panel and try again.');
    }

    if (!lyaideu_ensure_settings_table()) {
        settings_redirect(false, 'Could not access the database.');
    }

    if (isset($_POST['save_branding'])) {
        try {
            save_site_image('site_logo_file', 'site_logo', 'Logo');
            save_site_image('site_favicon_file', 'site_favicon', 'Favicon');
            save_site_image('site_apple_file', 'site_apple_icon', 'Apple Touch Icon');
            settings_redirect(true);
        } catch (Throwable $e) {
            settings_redirect(false, 'Could not save the branding changes.');
        }
    }

    if (isset($_POST['save_hero'])) {
        try {
            $defaults = site_hero_slides();
            for ($i = 1; $i <= 4; $i++) {
                $settingKey = 'hero_slide_' . $i;
                $hasUpload = !empty($_FILES['hero_slide_' . $i . '_file'])
                    && ($_FILES['hero_slide_' . $i . '_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $current = site_setting($settingKey, '');
                if (!$hasUpload && $current === '') {
                    $default = $defaults[$i - 1] ?? '';
                    if ($default !== '') {
                        $pdo->prepare(
                            'INSERT INTO settings (skey, sval) VALUES (:skey, :sval)
                             ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
                        )->execute([':skey' => $settingKey, ':sval' => $default]);
                    }
                } else {
                    save_site_image('hero_slide_' . $i . '_file', $settingKey, 'Hero Slide ' . $i);
                }
            }
            lyaideu_settings_clear();
            settings_redirect(true);
        } catch (Throwable $e) {
            settings_redirect(false, 'Could not save the hero slider images.');
        }
    }

    if (isset($_POST['save_creds'])) {
        $username = trim((string)($_POST['admin_username'] ?? ''));
        $current = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['new_password_confirm'] ?? '');

        if ($username === '') {
            settings_redirect(false, 'Admin username cannot be empty.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username)) {
            settings_redirect(false, 'Username must be 3–40 characters (letters, numbers, _ . -).');
        }
        if (!password_verify($current, site_setting('admin_pass_hash', ADMIN_PASS_HASH))) {
            settings_redirect(false, 'Current password is incorrect.');
        }
        if ($newPass !== $confirm) {
            settings_redirect(false, 'New passwords do not match.');
        }
        if (strlen($newPass) < 8) {
            settings_redirect(false, 'New password must be at least 8 characters long.');
        }

        try {
            $update = $pdo->prepare(
                'INSERT INTO settings (skey, sval) VALUES (:skey, :sval)
                 ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
            );
            $update->execute([':skey' => 'admin_username', ':sval' => $username]);
            $update->execute([':skey' => 'admin_pass_hash', ':sval' => password_hash($newPass, PASSWORD_DEFAULT)]);
        } catch (Throwable $e) {
            settings_redirect(false, 'Could not save the admin credentials.');
        }

        lyaideu_settings_clear();
        settings_redirect(true);
    }

    settings_redirect(false, 'Unknown action.');
}

$logoUrl = site_logo_url();
$faviconUrl = site_favicon_url();
$appleUrl = site_apple_icon_url();
$currentUser = site_setting('admin_username', 'admin');
$heroSlides = site_hero_slides();

admin_page_start('Settings', 'settings', 'Settings');
?>
    <form action="admin_settings" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_branding" value="1">

        <section class="admin-section">
            <div class="admin-section-top">
                <p class="section-sub">Upload a new logo or favicon — changes apply instantly across the whole website. Everything is optional; leave a field blank to keep the current image.</p>
            </div>
            <div class="admin-grid">
                <div class="admin-card">
                    <h3><i class="fa-solid fa-image"></i> Website Logo</h3>
                    <div class="settings-preview"><img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current logo"></div>
                    <label>Logo image</label>
                    <input type="file" name="site_logo_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                    <small class="small-note">PNG with a transparent background works best. Max 2 MB.</small>
                </div>

                <div class="admin-card">
                    <h3><i class="fa-solid fa-bolt"></i> Favicon</h3>
                    <div class="settings-preview preview-sm"><img src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current favicon"></div>
                    <label>Favicon image</label>
                    <input type="file" name="site_favicon_file" accept="image/png,image/x-icon,image/svg+xml">
                    <small class="small-note">A 32×32 PNG or an .ico in your browser tab.</small>
                </div>

                <div class="admin-card">
                    <h3><i class="fa-solid fa-mobile-screen"></i> Apple Touch Icon</h3>
                    <div class="settings-preview preview-sm"><img src="<?= htmlspecialchars($appleUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current apple icon"></div>
                    <label>Apple icon image</label>
                    <input type="file" name="site_apple_file" accept="image/png,image/jpeg">
                    <small class="small-note">Icon shown when saving to an iPhone home screen (180×180 PNG).</small>
                </div>
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Branding</button>
    </form>

    <form action="admin_settings" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_hero" value="1">

        <section class="admin-section">
            <div class="admin-section-top">
                <p class="section-sub">Change the images that slide on the homepage banner. Upload up to 4 images — leave a slot blank to keep the current slide. Recommended size: <strong>1200×900 px (4:3)</strong>.</p>
            </div>
            <div class="admin-grid">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                <div class="admin-card">
                    <h3><i class="fa-solid fa-images"></i> Hero Slide <?= $i ?></h3>
                    <div class="settings-preview"><img src="<?= htmlspecialchars($heroSlides[$i - 1] ?? 'logo.png', ENT_QUOTES, 'UTF-8') ?>" alt="Hero slide <?= $i ?>"></div>
                    <label>Slide image</label>
                    <input type="file" name="hero_slide_<?= $i ?>_file" accept="image/png,image/jpeg,image/webp,image/gif">
                    <small class="small-note">4:3 images look best (e.g. <strong>1200×900</strong>). Any size works — images always show fully with no cropping or gaps. Max 2 MB.</small>
                </div>
                <?php endfor; ?>
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Hero Slider</button>
    </form>

    <form action="admin_settings" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_creds" value="1">

        <section class="admin-section">
            <div class="admin-section-top">
                <p class="section-sub">Change the username and password used to sign in to this admin panel. Default: <strong>admin</strong> / <strong>admin123</strong>.</p>
            </div>
            <div class="admin-grid">
                <div class="admin-card">
                    <h3><i class="fa-solid fa-user-shield"></i> Admin Credentials</h3>
                    <label>Admin username</label>
                    <input type="text" name="admin_username" value="<?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username" placeholder="admin">
                    <label>Current password</label>
                    <input type="password" name="current_password" required autocomplete="current-password" placeholder="Your current password">
                    <label>New password</label>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
                    <label>Confirm new password</label>
                    <input type="password" name="new_password_confirm" required autocomplete="new-password" placeholder="Repeat the new password">
                </div>
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-key"></i> Save Credentials</button>
    </form>
<?php
admin_page_end();