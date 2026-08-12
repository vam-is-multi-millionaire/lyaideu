<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please reload the admin panel and try again.');
}

require_once __DIR__ . '/db.php';

function clean_text($value): string {
    return trim(strip_tags((string)$value));
}

function clean_phone($value): string {
    return preg_replace('/[^0-9+]/', '', (string)$value);
}

function clean_url($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(https?://|/|\./|\.\./)#i', $value)) {
        return $value;
    }
    return '';
}

function valid_category($value): string {
    $allowed = ['momo', 'pizza', 'chowmein', 'snacks', 'beverages', 'dinner'];
    return in_array($value, $allowed, true) ? $value : 'snacks';
}

function uploaded_file_field(string $group, string|int $index, string $field): ?array {
    $files = $_FILES[$group] ?? null;
    if (!is_array($files) || !isset($files['name'][$index][$field])) {
        return null;
    }
    return [
        'name' => $files['name'][$index][$field],
        'type' => $files['type'][$index][$field] ?? '',
        'tmp_name' => $files['tmp_name'][$index][$field],
        'error' => $files['error'][$index][$field] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index][$field] ?? 0,
    ];
}

function handle_hotel_logo(string $existingLogo, array $post, ?array $file): string {
    $logo = $existingLogo;

    if (!empty($post['remove_logo'])) {
        if ($logo !== '' && str_starts_with($logo, 'uploads/')) {
            @unlink(__DIR__ . '/' . $logo);
        }
        return '';
    }

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $logo;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo is too large (max 2 MB).');
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Logo must be a PNG, JPG, WebP, GIF or SVG image.');
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the uploads folder.');
        }
    }

    $ext = $allowed[$mime];
    $filename = 'hotel_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        throw new RuntimeException('Could not save the uploaded logo.');
    }

    if ($logo !== '' && str_starts_with($logo, 'uploads/')) {
        @unlink(__DIR__ . '/' . $logo);
    }

    return 'uploads/' . $filename;
}

$section = trim($_POST['section'] ?? '');
$allowedSections = ['dishes', 'hotels', 'contacts'];

if (!in_array($section, $allowedSections, true)) {
    header('Location: admin.php?error=' . urlencode('Unknown section.'));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($section === 'dishes') {
        $deleteDish = $pdo->prepare('DELETE FROM dishes WHERE id = ?');
        $updateDish = $pdo->prepare(
            'UPDATE dishes
             SET name = :name, hotel = :hotel, cat = :cat, price = :price, phone = :phone,
                 tag = :tag, `desc` = :descr, img = :img
             WHERE id = :id'
        );
        $insertDish = $pdo->prepare(
            'INSERT INTO dishes (name, hotel, cat, price, phone, tag, `desc`, img)
             VALUES (:name, :hotel, :cat, :price, :phone, :tag, :descr, :img)'
        );

        foreach (($_POST['dishes'] ?? []) as $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($d['delete'])) {
                $deleteDish->execute([$id]);
                continue;
            }

            $name = clean_text($d['name'] ?? '');
            $hotel = clean_text($d['hotel'] ?? '');
            if ($name === '' || $hotel === '') {
                continue;
            }

            $updateDish->execute([
                ':id' => $id,
                ':name' => $name,
                ':hotel' => $hotel,
                ':cat' => valid_category($d['cat'] ?? 'snacks'),
                ':price' => max(0, (int)($d['price'] ?? 0)),
                ':phone' => clean_phone($d['phone'] ?? ''),
                ':tag' => clean_text($d['tag'] ?? ''),
                ':descr' => clean_text($d['desc'] ?? ''),
                ':img' => clean_url($d['img'] ?? ''),
            ]);
        }

        $newDish = $_POST['new_dish'] ?? [];
        if (clean_text($newDish['name'] ?? '') !== '') {
            $insertDish->execute([
                ':name' => clean_text($newDish['name'] ?? ''),
                ':hotel' => clean_text($newDish['hotel'] ?? ''),
                ':cat' => valid_category($newDish['cat'] ?? 'snacks'),
                ':price' => max(0, (int)($newDish['price'] ?? 0)),
                ':phone' => clean_phone($newDish['phone'] ?? ''),
                ':tag' => clean_text($newDish['tag'] ?? ''),
                ':descr' => clean_text($newDish['desc'] ?? ''),
                ':img' => clean_url($newDish['img'] ?? ''),
            ]);
        }
    }

    if ($section === 'hotels') {
        $deleteHotel = $pdo->prepare('DELETE FROM hotels WHERE id = ?');
        $updateHotel = $pdo->prepare(
            'UPDATE hotels SET name = :name, type = :type, phone = :phone, logo = :logo WHERE id = :id'
        );
        $insertHotel = $pdo->prepare(
            'INSERT INTO hotels (name, type, phone, logo) VALUES (:name, :type, :phone, :logo)'
        );

        foreach (($_POST['hotels'] ?? []) as $i => $h) {
            $id = (int)($h['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($h['delete'])) {
                $deleteHotel->execute([$id]);
                continue;
            }

            $name = clean_text($h['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $logo = handle_hotel_logo((string)($h['logo'] ?? ''), $h, uploaded_file_field('hotels', $i, 'logo_file'));

            $updateHotel->execute([
                ':id' => $id,
                ':name' => $name,
                ':type' => clean_text($h['type'] ?? ''),
                ':phone' => clean_phone($h['phone'] ?? ''),
                ':logo' => $logo,
            ]);
        }

        $newHotel = $_POST['new_hotel'] ?? [];
        if (clean_text($newHotel['name'] ?? '') !== '') {
            $newFile = $_FILES['new_hotel'] ?? null;
            $newLogoFile = (isset($newFile['name']['logo_file']))
                ? [
                    'name' => $newFile['name']['logo_file'],
                    'type' => $newFile['type']['logo_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['logo_file'],
                    'error' => $newFile['error']['logo_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['logo_file'] ?? 0,
                ]
                : null;
            $logo = handle_hotel_logo('', $newHotel, $newLogoFile);

            $insertHotel->execute([
                ':name' => clean_text($newHotel['name'] ?? ''),
                ':type' => clean_text($newHotel['type'] ?? ''),
                ':phone' => clean_phone($newHotel['phone'] ?? ''),
                ':logo' => $logo,
            ]);
        }
    }

    if ($section === 'contacts') {
        $deleteContact = $pdo->prepare('DELETE FROM contacts WHERE id = ?');
        $updateContact = $pdo->prepare(
            'UPDATE contacts SET role = :role, person = :person, phone = :phone, note = :note, ico = :ico WHERE id = :id'
        );
        $insertContact = $pdo->prepare(
            'INSERT INTO contacts (role, person, phone, note, ico) VALUES (:role, :person, :phone, :note, :ico)'
        );

        foreach (($_POST['contacts'] ?? []) as $c) {
            $id = (int)($c['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($c['delete'])) {
                $deleteContact->execute([$id]);
                continue;
            }

            $role = clean_text($c['role'] ?? '');
            if ($role === '') {
                continue;
            }

            $updateContact->execute([
                ':id' => $id,
                ':role' => $role,
                ':person' => clean_text($c['person'] ?? ''),
                ':phone' => clean_phone($c['phone'] ?? ''),
                ':note' => clean_text($c['note'] ?? ''),
                ':ico' => clean_text($c['ico'] ?? 'fa-phone'),
            ]);
        }

        $newContact = $_POST['new_contact'] ?? [];
        if (clean_text($newContact['role'] ?? '') !== '') {
            $insertContact->execute([
                ':role' => clean_text($newContact['role'] ?? ''),
                ':person' => clean_text($newContact['person'] ?? ''),
                ':phone' => clean_phone($newContact['phone'] ?? ''),
                ':note' => clean_text($newContact['note'] ?? ''),
                ':ico' => clean_text($newContact['ico'] ?? 'fa-phone'),
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = ($e instanceof RuntimeException && $e->getMessage() !== '')
        ? $e->getMessage()
        : 'Could not save changes. Please try again.';
    admin_section_redirect($section, false, $message);
}

admin_section_redirect($section, true);
