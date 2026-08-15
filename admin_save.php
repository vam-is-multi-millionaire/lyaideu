<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin');
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

function valid_mart_category($value): string {
    $allowed = ['vegetables', 'fruits', 'dairy', 'staples', 'oils', 'snacks'];
    return in_array($value, $allowed, true) ? $value : 'vegetables';
}

function resolve_product_category(?int $value, string $type): array {
    if (!$value || $value <= 0) {
        return [0, ''];
    }
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return [0, ''];
    }
    $st = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND type = :t');
    $st->execute([':id' => $value, ':t' => $type]);
    if (!$st->fetchColumn()) {
        return [0, ''];
    }
    $path = lyaideu_category_path($value);
    $topSlug = $path ? $path[0]['slug'] : '';
    return [$value, $topSlug];
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

function handle_item_image(string $existingImg, array $post, ?array $file, string $prefix): string {
    return lyaideu_handle_item_image($existingImg, $post, $file, $prefix);
}

$section = trim($_POST['section'] ?? '');
$allowedSections = ['categories', 'dishes', 'mart', 'hotels', 'contacts'];

if (!in_array($section, $allowedSections, true)) {
    header('Location: admin?error=' . urlencode('Unknown section.'));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($section === 'dishes') {
        $deleteDish = $pdo->prepare('DELETE FROM dishes WHERE id = ?');
        $updateDish = $pdo->prepare(
            'UPDATE dishes
             SET name = :name, hotel = :hotel, cat = :cat, category_id = :category_id,
                 price = :price, phone = :phone, tag = :tag, `desc` = :descr, img = :img
             WHERE id = :id'
        );
        $insertDish = $pdo->prepare(
            'INSERT INTO dishes (name, hotel, cat, category_id, price, phone, tag, `desc`, img)
             VALUES (:name, :hotel, :cat, :category_id, :price, :phone, :tag, :descr, :img)'
        );

        foreach (($_POST['dishes'] ?? []) as $i => $d) {
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

            $img = handle_item_image((string)($d['img'] ?? ''), $d, uploaded_file_field('dishes', $i, 'img_file'), 'dish_img');
            $catRes = resolve_product_category((int)($d['category_id'] ?? 0), 'menu');

            $updateDish->execute([
                ':id' => $id,
                ':name' => $name,
                ':hotel' => $hotel,
                ':cat' => $catRes[1] !== '' ? $catRes[1] : valid_category($d['cat'] ?? 'snacks'),
                ':category_id' => $catRes[0] ?: null,
                ':price' => max(0, (int)($d['price'] ?? 0)),
                ':phone' => clean_phone($d['phone'] ?? ''),
                ':tag' => clean_text($d['tag'] ?? ''),
                ':descr' => clean_text($d['desc'] ?? ''),
                ':img' => $img,
            ]);
            lyaideu_sync_item_slug('dishes', $id, $name);
            lyaideu_resolve_dish_vendor($id);
        }

        $newDish = $_POST['new_dish'] ?? [];
        if (clean_text($newDish['name'] ?? '') !== '') {
            $newFile = $_FILES['new_dish'] ?? null;
            $newImgFile = (isset($newFile['name']['img_file']))
                ? [
                    'name' => $newFile['name']['img_file'],
                    'type' => $newFile['type']['img_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['img_file'],
                    'error' => $newFile['error']['img_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['img_file'] ?? 0,
                ]
                : null;
            $img = handle_item_image('', $newDish, $newImgFile, 'dish_img');
            $catRes = resolve_product_category((int)($newDish['category_id'] ?? 0), 'menu');

            $insertDish->execute([
                ':name' => clean_text($newDish['name'] ?? ''),
                ':hotel' => clean_text($newDish['hotel'] ?? ''),
                ':cat' => $catRes[1] !== '' ? $catRes[1] : valid_category($newDish['cat'] ?? 'snacks'),
                ':category_id' => $catRes[0] ?: null,
                ':price' => max(0, (int)($newDish['price'] ?? 0)),
                ':phone' => clean_phone($newDish['phone'] ?? ''),
                ':tag' => clean_text($newDish['tag'] ?? ''),
                ':descr' => clean_text($newDish['desc'] ?? ''),
                ':img' => $img,
            ]);
            lyaideu_sync_item_slug('dishes', (int)$pdo->lastInsertId(), clean_text($newDish['name'] ?? ''));
            lyaideu_resolve_dish_vendor((int)$pdo->lastInsertId());
        }
    }

    if ($section === 'mart') {
        $martVendorId = function ($value): int {
            $vid = (int)($value ?? 0);
            if ($vid > 0) {
                $st = $pdo->prepare("SELECT id FROM vendors WHERE id = :id AND scope = 'mart' AND is_active = 1");
                $st->execute([':id' => $vid]);
                if ($st->fetchColumn()) {
                    return $vid;
                }
            }
            return 0;
        };

        $deleteItem = $pdo->prepare('DELETE FROM mart_items WHERE id = ?');
        $updateItem = $pdo->prepare(
            'UPDATE mart_items
             SET name = :name, cat = :cat, category_id = :category_id, unit = :unit, price = :price, tag = :tag,
                 `desc` = :descr, img = :img, vendor_id = :vendor_id
             WHERE id = :id'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO mart_items (name, cat, category_id, unit, price, tag, `desc`, img, vendor_id)
             VALUES (:name, :cat, :category_id, :unit, :price, :tag, :descr, :img, :vendor_id)'
        );

        foreach (($_POST['mart'] ?? []) as $i => $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($m['delete'])) {
                $deleteItem->execute([$id]);
                continue;
            }

            $name = clean_text($m['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $vid = $martVendorId($m['vendor_id'] ?? 0);
            if ($vid <= 0) {
                $vid = lyaideu_resolve_mart_vendor($id);
            }

            $img = handle_item_image((string)($m['img'] ?? ''), $m, uploaded_file_field('mart', $i, 'img_file'), 'mart_img');
            $catRes = resolve_product_category((int)($m['category_id'] ?? 0), 'mart');

            $updateItem->execute([
                ':id' => $id,
                ':name' => $name,
                ':cat' => $catRes[1] !== '' ? $catRes[1] : valid_mart_category($m['cat'] ?? 'vegetables'),
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($m['unit'] ?? ''),
                ':price' => max(0, (int)($m['price'] ?? 0)),
                ':tag' => clean_text($m['tag'] ?? ''),
                ':descr' => clean_text($m['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            lyaideu_sync_item_slug('mart_items', $id, $name);
        }

        $newItem = $_POST['new_mart'] ?? [];
        if (clean_text($newItem['name'] ?? '') !== '') {
            $newFile = $_FILES['new_mart'] ?? null;
            $newImgFile = (isset($newFile['name']['img_file']))
                ? [
                    'name' => $newFile['name']['img_file'],
                    'type' => $newFile['type']['img_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['img_file'],
                    'error' => $newFile['error']['img_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['img_file'] ?? 0,
                ]
                : null;
            $vid = $martVendorId($newItem['vendor_id'] ?? 0);
            $img = handle_item_image('', $newItem, $newImgFile, 'mart_img');
            $catRes = resolve_product_category((int)($newItem['category_id'] ?? 0), 'mart');

            $insertItem->execute([
                ':name' => clean_text($newItem['name'] ?? ''),
                ':cat' => $catRes[1] !== '' ? $catRes[1] : valid_mart_category($newItem['cat'] ?? 'vegetables'),
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($newItem['unit'] ?? ''),
                ':price' => max(0, (int)($newItem['price'] ?? 0)),
                ':tag' => clean_text($newItem['tag'] ?? ''),
                ':descr' => clean_text($newItem['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            lyaideu_sync_item_slug('mart_items', (int)$pdo->lastInsertId(), clean_text($newItem['name'] ?? ''));
            if ($vid <= 0) {
                lyaideu_resolve_mart_vendor((int)$pdo->lastInsertId());
            }
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

    if ($section === 'categories') {
        lyaideu_ensure_categories_table();

        $allCats = $pdo->query('SELECT id, parent_id, type FROM categories')->fetchAll();
        $byId = [];
        foreach ($allCats as $c) {
            $byId[(int)$c['id']] = $c;
        }
        $descOf = function (int $id) use ($byId): array {
            $result = [];
            $frontier = [$id];
            while ($frontier) {
                $next = array_shift($frontier);
                foreach ($byId as $c) {
                    if ((int)$c['parent_id'] === $next) {
                        $result[] = (int)$c['id'];
                        $frontier[] = (int)$c['id'];
                    }
                }
            }
            return $result;
        };

        $updateCat = $pdo->prepare(
            'UPDATE categories
             SET name = :name, slug = :slug, parent_id = :parent_id, sort_order = :sort_order, icon = :icon
             WHERE id = :id'
        );
        $insertCat = $pdo->prepare(
            'INSERT INTO categories (name, slug, type, parent_id, sort_order, icon)
             VALUES (:name, :slug, :type, :parent_id, :sort_order, :icon)'
        );
        $nullDish = $pdo->prepare('UPDATE dishes SET category_id = NULL WHERE category_id = ?');
        $nullMart = $pdo->prepare('UPDATE mart_items SET category_id = NULL WHERE category_id = ?');
        $reparent = $pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?');
        $deleteCat = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $dupeCat = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND type = :type AND id <> :id');

        foreach (($_POST['categories'] ?? []) as $cat) {
            $id = (int)($cat['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($cat['delete'])) {
                $ids = array_merge([$id], $descOf($id));
                foreach ($ids as $did) {
                    $nullDish->execute([$did]);
                    $nullMart->execute([$did]);
                }
                $parent = (int)($byId[$id]['parent_id'] ?? 0);
                $reparent->execute([$parent ?: null, $id]);
                $deleteCat->execute([$id]);
                continue;
            }

            $name = clean_text($cat['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = in_array(clean_text($cat['type'] ?? 'menu'), ['menu', 'mart'], true) ? clean_text($cat['type']) : 'menu';
            $slug = lyaideu_slugify(clean_text($cat['slug'] ?? ''));
            if ($slug === '' || $slug === 'category') {
                $slug = lyaideu_slugify($name);
            }
            $parentId = (int)($cat['parent_id'] ?? 0);
            if ($parentId > 0) {
                if (!isset($byId[$parentId]) || $byId[$parentId]['type'] !== $type) {
                    $parentId = 0;
                } elseif ($parentId === $id || in_array($id, $descOf($parentId), true)) {
                    $parentId = 0;
                }
            }
            $sort = max(0, (int)($cat['sort_order'] ?? 0));
            $icon = preg_replace('/[^a-z0-9-]/', '', clean_text($cat['icon'] ?? ''));

            $dupeCat->execute([':slug' => $slug, ':type' => $type, ':id' => $id]);
            if ($dupeCat->fetchColumn()) {
                throw new RuntimeException('A category with the slug "' . $slug . '" already exists.');
            }

            $updateCat->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':parent_id' => $parentId ?: null,
                ':sort_order' => $sort,
                ':icon' => $icon,
                ':id' => $id,
            ]);
        }

        $newCat = $_POST['new_category'] ?? [];
        if (clean_text($newCat['name'] ?? '') !== '') {
            $name = clean_text($newCat['name']);
            $type = in_array(clean_text($newCat['type'] ?? 'menu'), ['menu', 'mart'], true) ? clean_text($newCat['type']) : 'menu';
            $slug = lyaideu_slugify(clean_text($newCat['slug'] ?? ''));
            if ($slug === '' || $slug === 'category') {
                $slug = lyaideu_slugify($name);
            }
            $parentId = (int)($newCat['parent_id'] ?? 0);
            if ($parentId > 0) {
                if (!isset($byId[$parentId]) || $byId[$parentId]['type'] !== $type) {
                    $parentId = 0;
                }
            }
            $sort = max(0, (int)($newCat['sort_order'] ?? 0));
            $icon = preg_replace('/[^a-z0-9-]/', '', clean_text($newCat['icon'] ?? ''));

            $dupeNew = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND type = :type');
            $dupeNew->execute([':slug' => $slug, ':type' => $type]);
            if ($dupeNew->fetchColumn()) {
                throw new RuntimeException('A category with the slug "' . $slug . '" already exists.');
            }

            $insertCat->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':type' => $type,
                ':parent_id' => $parentId ?: null,
                ':sort_order' => $sort,
                ':icon' => $icon,
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
