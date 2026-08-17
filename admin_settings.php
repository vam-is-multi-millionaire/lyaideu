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

    if (isset($_POST['save_delivery'])) {
        $feeArr = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string)($_POST['delivery_fee_schedule'] ?? ''))), function ($v) {
            return $v >= 0;
        }));
        $timeArr = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string)($_POST['delivery_time_schedule'] ?? ''))), function ($v) {
            return $v >= 0;
        }));

        $martMinutes = max(1, (int)($_POST['delivery_mart_minutes'] ?? 15));
        $timeMin = max(1, (int)($_POST['delivery_time_min'] ?? 45));
        $timeMax = max(1, (int)($_POST['delivery_time_max'] ?? 60));
        if ($timeMax < $timeMin) {
            $timeMax = $timeMin;
        }

        if (!$feeArr || !$timeArr) {
            settings_redirect(false, 'Enter at least one delivery fee and one delivery time.');
        }

        try {
            $update = $pdo->prepare(
                'INSERT INTO settings (skey, sval) VALUES (:skey, :sval)
                 ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
            );
            $update->execute([':skey' => 'delivery_fee_schedule', ':sval' => json_encode($feeArr)]);
            $update->execute([':skey' => 'delivery_time_schedule', ':sval' => json_encode($timeArr)]);
            $update->execute([':skey' => 'delivery_mart_minutes', ':sval' => (string)$martMinutes]);
            $update->execute([':skey' => 'delivery_time_min', ':sval' => (string)$timeMin]);
            $update->execute([':skey' => 'delivery_time_max', ':sval' => (string)$timeMax]);
        } catch (Throwable $e) {
            settings_redirect(false, 'Could not save the delivery settings.');
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
$heroUrls = [];
for ($i = 1; $i <= 4; $i++) {
    $heroUrls[$i] = site_setting('hero_slide_' . $i, '');
}
$deliveryCfg = lyaideu_delivery_config();
$deliveryFeeStr = implode(', ', $deliveryCfg['fee_schedule']);
$deliveryTimeStr = implode(', ', $deliveryCfg['time_schedule']);
$deliveryMartMinutes = (int)$deliveryCfg['mart_minutes'];
$deliveryTimeMin = (int)$deliveryCfg['time_min'];
$deliveryTimeMax = (int)$deliveryCfg['time_max'];

admin_page_start('Settings', 'settings', 'Settings');
?>
<script>document.body.classList.add('settings-js');</script>

    <nav class="admin-tabs settings-tabs" id="settingsTabs" aria-label="Settings sections">
        <button type="button" class="admin-tab active" data-settings-tab="branding" aria-selected="true"><i class="fa-solid fa-palette"></i> Branding</button>
        <button type="button" class="admin-tab" data-settings-tab="hero" aria-selected="false"><i class="fa-solid fa-images"></i> Hero Slider</button>
        <button type="button" class="admin-tab" data-settings-tab="credentials" aria-selected="false"><i class="fa-solid fa-user-shield"></i> Login &amp; Security</button>
        <button type="button" class="admin-tab" data-settings-tab="delivery" aria-selected="false"><i class="fa-solid fa-motorcycle"></i> Delivery</button>
    </nav>

    <form action="admin_settings" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_branding" value="1">

        <section class="admin-section settings-pane active" data-settings-pane="branding">
            <div class="admin-section-top">
                <div>
                    <h2 class="settings-section-title"><i class="fa-solid fa-palette"></i> Branding</h2>
                    <p class="section-sub">Upload a new logo or favicon — changes apply instantly across the whole website. Everything is optional; leave a field blank to keep the current image.</p>
                </div>
                <span class="admin-count-badge">1 of 4</span>
            </div>
            <div class="admin-grid">
                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-image"></i> Website Logo</h3>
                    <div class="settings-preview" data-preview data-empty-icon="fa-image" data-empty-label="No logo yet">
                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current website logo">
                    </div>
                    <label for="site_logo_file">Logo image</label>
                    <input type="file" name="site_logo_file" id="site_logo_file" class="settings-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" data-preview-input>
                    <small class="small-note">PNG with a transparent background works best. Max 2 MB.</small>
                </div>

                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-bolt"></i> Favicon</h3>
                    <div class="settings-preview preview-sm" data-preview data-empty-icon="fa-bolt" data-empty-label="No favicon yet">
                        <img src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current favicon">
                    </div>
                    <label for="site_favicon_file">Favicon image</label>
                    <input type="file" name="site_favicon_file" id="site_favicon_file" class="settings-file-input" accept="image/png,image/x-icon,image/svg+xml" data-preview-input>
                    <small class="small-note">A 32×32 PNG or an .ico shows in the browser tab.</small>
                </div>

                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-mobile-screen"></i> Apple Touch Icon</h3>
                    <div class="settings-preview preview-sm" data-preview data-empty-icon="fa-mobile-screen" data-empty-label="No icon yet">
                        <img src="<?= htmlspecialchars($appleUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current Apple touch icon">
                    </div>
                    <label for="site_apple_file">Apple icon image</label>
                    <input type="file" name="site_apple_file" id="site_apple_file" class="settings-file-input" accept="image/png,image/jpeg" data-preview-input>
                    <small class="small-note">Shown when saving the site to an iPhone home screen (180×180 PNG).</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Branding</button>
        </section>
    </form>

    <form action="admin_settings" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_hero" value="1">

        <section class="admin-section settings-pane" data-settings-pane="hero">
            <div class="admin-section-top">
                <div>
                    <h2 class="settings-section-title"><i class="fa-solid fa-images"></i> Hero Slider</h2>
                    <p class="section-sub">Change the images that slide on the homepage banner. Upload up to 4 images — leave a slot blank to keep the current slide. Recommended size: <strong>1200×900 px (4:3)</strong>.</p>
                </div>
                <span class="admin-count-badge">2 of 4</span>
            </div>
            <div class="admin-grid">
                <?php for ($i = 1; $i <= 4; $i++):
                    $heroUrl = $heroUrls[$i] ?? '';
                ?>
                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-images"></i> Hero Slide <?= $i ?></h3>
                    <div class="settings-preview" data-preview data-empty-icon="fa-images" data-empty-label="No slide yet">
                        <img src="<?= $heroUrl !== '' ? htmlspecialchars($heroUrl, ENT_QUOTES, 'UTF-8') : '' ?>" alt="Hero slide <?= $i ?> preview">
                    </div>
                    <label for="hero_slide_<?= $i ?>_file">Slide image</label>
                    <input type="file" name="hero_slide_<?= $i ?>_file" id="hero_slide_<?= $i ?>_file" class="settings-file-input" accept="image/png,image/jpeg,image/webp,image/gif" data-preview-input>
                    <small class="small-note">4:3 images look best (e.g. <strong>1200×900</strong>). Any size works — images always show fully with no cropping or gaps. Max 2 MB.</small>
                </div>
                <?php endfor; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Hero Slider</button>
        </section>
    </form>

    <form action="admin_settings" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_creds" value="1">

        <section class="admin-section settings-pane" data-settings-pane="credentials">
            <div class="admin-section-top">
                <div>
                    <h2 class="settings-section-title"><i class="fa-solid fa-user-shield"></i> Login &amp; Security</h2>
                    <p class="section-sub">Change the username and password used to sign in to this admin panel. Default: <strong>admin</strong> / <strong>admin123</strong>.</p>
                </div>
                <span class="admin-count-badge">3 of 4</span>
            </div>
            <div class="admin-grid">
                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-user-pen"></i> Update Credentials</h3>
                    <label for="admin_username">Admin username</label>
                    <input type="text" name="admin_username" id="admin_username" value="<?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username" placeholder="admin">

                    <label for="current_password">Current password</label>
                    <div class="password-wrap">
                        <input type="password" name="current_password" id="current_password" required autocomplete="current-password" placeholder="Your current password">
                        <button type="button" class="password-toggle" data-target="current_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>

                    <label for="new_password">New password</label>
                    <div class="password-wrap">
                        <input type="password" name="new_password" id="new_password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <span class="password-strength" id="passwordStrength" role="status">Min 8 characters</span>

                    <label for="new_password_confirm">Confirm new password</label>
                    <div class="password-wrap">
                        <input type="password" name="new_password_confirm" id="new_password_confirm" required autocomplete="new-password" placeholder="Repeat the new password">
                        <button type="button" class="password-toggle" data-target="new_password_confirm" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <p class="small-note password-match-note" id="passwordMatchNote"></p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-key"></i> Save Credentials</button>
        </section>
    </form>

    <form action="admin_settings" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="save_delivery" value="1">

        <section class="admin-section settings-pane" data-settings-pane="delivery">
            <div class="admin-section-top">
                <div>
                    <h2 class="settings-section-title"><i class="fa-solid fa-motorcycle"></i> Delivery &amp; Fees</h2>
                    <p class="section-sub">Delivery fee and estimated delivery time by vendor count. Entry #1 = 1 vendor, #2 = 2 vendors, and so on. When customers mix items from several hotels / the Mart, the fee and time scale up automatically and they are shown a notice before checkout.</p>
                </div>
                <span class="admin-count-badge">4 of 4</span>
            </div>
            <div class="admin-grid">
                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-wallet"></i> Delivery fees (Rs.)</h3>
                    <label for="delivery_fee_schedule">Fee per vendor count <small class="small-note">comma-separated</small></label>
                    <input type="text" name="delivery_fee_schedule" id="delivery_fee_schedule" value="<?= htmlspecialchars($deliveryFeeStr, ENT_QUOTES, 'UTF-8') ?>" placeholder="50, 90, 120, 140, 160, 180">
                    <small class="small-note">1 vendor = Rs. 50 · 2 vendors = Rs. 90 · 3 = Rs. 120 · 4 = Rs. 140. Past the last entry, the final increase repeats.</small>
                </div>
                <div class="admin-card settings-card">
                    <h3><i class="fa-solid fa-clock"></i> Estimated delivery (minutes)</h3>
                    <label for="delivery_time_schedule">Minutes per vendor count <small class="small-note">comma-separated, hotel orders only</small></label>
                    <input type="text" name="delivery_time_schedule" id="delivery_time_schedule" value="<?= htmlspecialchars($deliveryTimeStr, ENT_QUOTES, 'UTF-8') ?>" placeholder="45, 50, 55, 60, 60, 60">
                    <small class="small-note">Food/hotel orders: 1 vendor = 45 min · 2 = 50 min · 3 = 55 min · 4+ = 60 min. Past the last entry, the final increase repeats.</small>
                    <div class="settings-number-row">
                        <div>
                            <label for="delivery_mart_minutes">Mart-only delivery (min)</label>
                            <input type="number" name="delivery_mart_minutes" id="delivery_mart_minutes" value="<?= (int)$deliveryMartMinutes ?>" min="1" step="1">
                        </div>
                        <div>
                            <label for="delivery_time_min">Hotel minimum (min)</label>
                            <input type="number" name="delivery_time_min" id="delivery_time_min" value="<?= (int)$deliveryTimeMin ?>" min="1" step="1">
                        </div>
                        <div>
                            <label for="delivery_time_max">Hotel maximum (min)</label>
                            <input type="number" name="delivery_time_max" id="delivery_time_max" value="<?= (int)$deliveryTimeMax ?>" min="1" step="1">
                        </div>
                    </div>
                    <small class="small-note">Mart-only orders are ready-made and take the mart time. Orders with hotel/food items always take at least the minimum and never more than the maximum.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block admin-save-btn"><i class="fa-solid fa-motorcycle"></i> Save Delivery Settings</button>
        </section>
    </form>

    <script>
    (function(){
        var SKEY = 'lyaideu_settings_tab';
        var savedTab = null;
        try { savedTab = sessionStorage.getItem(SKEY); } catch (e) {}

        var tabs = Array.prototype.slice.call(document.querySelectorAll('.settings-tabs .admin-tab'));
        var panes = Array.prototype.slice.call(document.querySelectorAll('.settings-pane'));
        if (tabs.length && panes.length) {
            function show(name) {
                tabs.forEach(function (t) {
                    var on = t.getAttribute('data-settings-tab') === name;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                panes.forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-settings-pane') === name);
                });
                try { sessionStorage.setItem(SKEY, name); } catch (e) {}
            }
            tabs.forEach(function (t) {
                t.addEventListener('click', function () { show(t.getAttribute('data-settings-tab')); });
            });
            var first = tabs.filter(function (t) { return t.classList.contains('active'); })[0] || tabs[0];
            var initial = savedTab && tabs.some(function (t) { return t.getAttribute('data-settings-tab') === savedTab; })
                ? savedTab
                : first.getAttribute('data-settings-tab');
            show(initial);
        }

        document.querySelectorAll('[data-preview]').forEach(function (preview) {
            var img = preview.querySelector('img');
            var input = preview.parentElement.querySelector('[data-preview-input]');
            var icon = preview.getAttribute('data-empty-icon') || 'fa-image';
            var label = preview.getAttribute('data-empty-label') || 'No image';
            var emptyEl = null;
            function setEmpty() {
                preview.classList.add('preview-placeholder');
                if (img) img.removeAttribute('src');
                if (!emptyEl) {
                    emptyEl = document.createElement('span');
                    emptyEl.className = 'preview-empty';
                    preview.appendChild(emptyEl);
                }
                emptyEl.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + label;
            }
            function setImage(src) {
                preview.classList.remove('preview-placeholder');
                if (emptyEl) { emptyEl.remove(); emptyEl = null; }
                if (img) img.setAttribute('src', src);
            }
            if (img) {
                img.addEventListener('error', setEmpty);
                var rawSrc = (img.getAttribute('src') || '').trim();
                if (rawSrc === '' || (img.complete && img.naturalWidth === 0)) setEmpty();
            }
            if (input) {
                input.addEventListener('change', function () {
                    var f = input.files && input.files[0];
                    if (!f) return;
                    setImage(URL.createObjectURL(f));
                });
            }
        });

        document.querySelectorAll('.password-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-target'));
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = show ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        });

        var np = document.getElementById('new_password');
        var cp = document.getElementById('new_password_confirm');
        var meter = document.getElementById('passwordStrength');
        var note = document.getElementById('passwordMatchNote');
        if (np && meter) {
            np.addEventListener('input', function () {
                var v = np.value;
                var s = 0;
                if (v.length >= 8) s++;
                if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
                if (/\d/.test(v)) s++;
                if (/[^A-Za-z0-9]/.test(v)) s++;
                if (v === '') {
                    meter.textContent = 'Min 8 characters';
                    meter.className = 'password-strength';
                } else {
                    var labels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
                    meter.textContent = labels[s] + (s >= 2 && v.length < 8 ? ' — needs 8 characters' : '');
                    meter.className = 'password-strength s' + s;
                }
            });
        }
        if (np && cp && note) {
            function checkMatch() {
                if (cp.value === '') {
                    note.textContent = '';
                    cp.classList.remove('password-mismatch');
                    return;
                }
                if (np.value !== cp.value) {
                    note.textContent = 'Passwords do not match';
                    note.style.color = '#c93a3a';
                    cp.classList.add('password-mismatch');
                } else {
                    note.textContent = 'Passwords match';
                    note.style.color = '#166534';
                    cp.classList.remove('password-mismatch');
                }
            }
            np.addEventListener('input', checkMatch);
            cp.addEventListener('input', checkMatch);
        }
    })();
    </script>
<?php
admin_page_end();