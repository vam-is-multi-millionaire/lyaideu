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

function valid_other_category($value): string {
    $allowed = ['flowers', 'candles', 'achar', 'gifts'];
    return in_array($value, $allowed, true) ? $value : 'gifts';
}

function valid_beverage_category($value): string {
    $allowed = ['cold-drinks', 'alcohol', 'water'];
    return in_array($value, $allowed, true) ? $value : 'cold-drinks';
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

/* Base price for a variant product: prefer the option marked Default, then
   the first priced option. Keeps card listings in sync with the preselected
   option when the main price field is left at 0. */
function variant_base_price(int $price, array $row): int {
    if ($price > 0 || empty($row['has_variants'])) {
        return $price;
    }
    $first = 0;
    foreach (($row['variants'] ?? []) as $opt) {
        $p = max(0, (int)($opt['price'] ?? 0));
        if ($p <= 0) {
            continue;
        }
        if (!empty($opt['default'])) {
            return $p;
        }
        if ($first === 0) {
            $first = $p;
        }
    }
    return $first;
}

$section = trim($_POST['section'] ?? '');
$allowedSections = ['categories', 'category_reorder', 'sections', 'section_reorder', 'section_links', 'dishes', 'mart', 'others', 'beverages', 'hotels', 'contacts'];

if (!in_array($section, $allowedSections, true)) {
    header('Location: admin?error=' . urlencode('Unknown section.'));
    exit;
}

require_once __DIR__ . '/site_config.php';

/* Ensure tables/columns BEFORE opening the transaction: MySQL DDL (CREATE
   TABLE / ALTER TABLE) implicitly commits the current transaction, which
   would make the later commit() fail with "There is no active transaction".
   The ensure_* calls inside the handlers below then short-circuit via their
   request guards. */
if ($section === 'categories' || $section === 'category_reorder') {
    lyaideu_ensure_categories_table();
} elseif ($section === 'sections' || $section === 'section_reorder' || $section === 'section_links') {
    lyaideu_ensure_sections_tables();
    lyaideu_ensure_categories_table();
} elseif ($section === 'others') {
    lyaideu_ensure_other_table();
} elseif ($section === 'beverages') {
    lyaideu_ensure_beverage_table();
}
lyaideu_ensure_variant_tables();
lyaideu_ensure_discount_columns();
/* The sections helpers (valid_category_types / custom_sections / link purges) can be reached from ANY save branch inside the transaction below, and their first call per request would otherwise run CREATE TABLE (DDL), silently committing everything done so far and breaking commit(). */
lyaideu_ensure_sections_tables();

try {
    $pdo->beginTransaction();

    if ($section === 'dishes') {
        $deleteDish = $pdo->prepare('DELETE FROM dishes WHERE id = ?');
        $updateDish = $pdo->prepare(
            'UPDATE dishes
             SET name = :name, hotel = :hotel, cat = :cat, category_id = :category_id,
                 price = :price, discount_percent = :discount, phone = :phone, tag = :tag, `desc` = :descr, img = :img
             WHERE id = :id'
        );
        $insertDish = $pdo->prepare(
            'INSERT INTO dishes (name, hotel, cat, category_id, price, discount_percent, phone, tag, `desc`, img)
             VALUES (:name, :hotel, :cat, :category_id, :price, :discount, :phone, :tag, :descr, :img)'
        );

        foreach (($_POST['dishes'] ?? []) as $i => $d) {
            $id = (int)($d['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($d['delete'])) {
                $deleteDish->execute([$id]);
                lyaideu_delete_item_variants($pdo, 'dish', $id);
                lyaideu_purge_item_links('dish', $id);
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
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':price' => variant_base_price(max(0, (int)($d['price'] ?? 0)), $d),
                ':discount' => lyaideu_deal_percent($d['discount'] ?? 0),
                ':phone' => clean_phone($d['phone'] ?? ''),
                ':tag' => clean_text($d['tag'] ?? ''),
                ':descr' => clean_text($d['desc'] ?? ''),
                ':img' => $img,
            ]);
            lyaideu_sync_item_slug('dishes', $id, $name);
            lyaideu_resolve_dish_vendor($id);
            lyaideu_save_item_variants($pdo, 'dish', $id, !empty($d['has_variants']), $d['variants'] ?? []);
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
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':price' => variant_base_price(max(0, (int)($newDish['price'] ?? 0)), $newDish),
                ':discount' => lyaideu_deal_percent($newDish['discount'] ?? 0),
                ':phone' => clean_phone($newDish['phone'] ?? ''),
                ':tag' => clean_text($newDish['tag'] ?? ''),
                ':descr' => clean_text($newDish['desc'] ?? ''),
                ':img' => $img,
            ]);
            $newDishId = (int)$pdo->lastInsertId();
            lyaideu_sync_item_slug('dishes', $newDishId, clean_text($newDish['name'] ?? ''));
            lyaideu_resolve_dish_vendor($newDishId);
            lyaideu_save_item_variants($pdo, 'dish', $newDishId, !empty($newDish['has_variants']), $newDish['variants'] ?? []);
        }
    }

    if ($section === 'mart') {
        $martVendorId = function ($value): int {
            $vid = (int)($value ?? 0);
            if ($vid > 0) {
                $pdo = $GLOBALS['pdo'] ?? null;
                if (!$pdo instanceof PDO) {
                    return 0;
                }
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
             SET name = :name, cat = :cat, category_id = :category_id, unit = :unit, price = :price, discount_percent = :discount, tag = :tag,
                 `desc` = :descr, img = :img, vendor_id = :vendor_id
             WHERE id = :id'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO mart_items (name, cat, category_id, unit, price, discount_percent, tag, `desc`, img, vendor_id)
             VALUES (:name, :cat, :category_id, :unit, :price, :discount, :tag, :descr, :img, :vendor_id)'
        );

        foreach (($_POST['mart'] ?? []) as $i => $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($m['delete'])) {
                $deleteItem->execute([$id]);
                lyaideu_delete_item_variants($pdo, 'mart', $id);
                lyaideu_purge_item_links('mart', $id);
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
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($m['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($m['price'] ?? 0)), $m),
                ':discount' => lyaideu_deal_percent($m['discount'] ?? 0),
                ':tag' => clean_text($m['tag'] ?? ''),
                ':descr' => clean_text($m['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            lyaideu_sync_item_slug('mart_items', $id, $name);
            lyaideu_save_item_variants($pdo, 'mart', $id, !empty($m['has_variants']), $m['variants'] ?? []);
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
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($newItem['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($newItem['price'] ?? 0)), $newItem),
                ':discount' => lyaideu_deal_percent($newItem['discount'] ?? 0),
                ':tag' => clean_text($newItem['tag'] ?? ''),
                ':descr' => clean_text($newItem['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            $newItemId = (int)$pdo->lastInsertId();
            lyaideu_sync_item_slug('mart_items', $newItemId, clean_text($newItem['name'] ?? ''));
            if ($vid <= 0) {
                lyaideu_resolve_mart_vendor($newItemId);
            }
            lyaideu_save_item_variants($pdo, 'mart', $newItemId, !empty($newItem['has_variants']), $newItem['variants'] ?? []);
        }
    }

    if ($section === 'others') {
        lyaideu_ensure_other_table();
        $otherVendorId = function ($value): int {
            $vid = (int)($value ?? 0);
            if ($vid > 0) {
                $pdo = $GLOBALS['pdo'] ?? null;
                if (!$pdo instanceof PDO) {
                    return 0;
                }
                $st = $pdo->prepare("SELECT id FROM vendors WHERE id = :id AND scope = 'other' AND is_active = 1");
                $st->execute([':id' => $vid]);
                if ($st->fetchColumn()) {
                    return $vid;
                }
            }
            return 0;
        };

        $deleteItem = $pdo->prepare('DELETE FROM other_items WHERE id = ?');
        $updateItem = $pdo->prepare(
            'UPDATE other_items
             SET name = :name, cat = :cat, category_id = :category_id, unit = :unit, price = :price, discount_percent = :discount, tag = :tag,
                 `desc` = :descr, img = :img, vendor_id = :vendor_id
             WHERE id = :id'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO other_items (name, cat, category_id, unit, price, discount_percent, tag, `desc`, img, vendor_id)
             VALUES (:name, :cat, :category_id, :unit, :price, :discount, :tag, :descr, :img, :vendor_id)'
        );

        foreach (($_POST['others'] ?? []) as $i => $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($m['delete'])) {
                $deleteItem->execute([$id]);
                lyaideu_delete_item_variants($pdo, 'other', $id);
                lyaideu_purge_item_links('other', $id);
                continue;
            }

            $name = clean_text($m['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $vid = $otherVendorId($m['vendor_id'] ?? 0);
            if ($vid <= 0) {
                $vid = lyaideu_resolve_other_vendor($id);
            }

            $img = handle_item_image((string)($m['img'] ?? ''), $m, uploaded_file_field('others', $i, 'img_file'), 'other_img');
            $catRes = resolve_product_category((int)($m['category_id'] ?? 0), 'other');

            $updateItem->execute([
                ':id' => $id,
                ':name' => $name,
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($m['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($m['price'] ?? 0)), $m),
                ':discount' => lyaideu_deal_percent($m['discount'] ?? 0),
                ':tag' => clean_text($m['tag'] ?? ''),
                ':descr' => clean_text($m['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            lyaideu_sync_item_slug('other_items', $id, $name);
            lyaideu_save_item_variants($pdo, 'other', $id, !empty($m['has_variants']), $m['variants'] ?? []);
        }

        $newItem = $_POST['new_others'] ?? [];
        if (clean_text($newItem['name'] ?? '') !== '') {
            $newFile = $_FILES['new_others'] ?? null;
            $newImgFile = (isset($newFile['name']['img_file']))
                ? [
                    'name' => $newFile['name']['img_file'],
                    'type' => $newFile['type']['img_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['img_file'],
                    'error' => $newFile['error']['img_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['img_file'] ?? 0,
                ]
                : null;
            $vid = $otherVendorId($newItem['vendor_id'] ?? 0);
            $img = handle_item_image('', $newItem, $newImgFile, 'other_img');
            $catRes = resolve_product_category((int)($newItem['category_id'] ?? 0), 'other');

            $insertItem->execute([
                ':name' => clean_text($newItem['name'] ?? ''),
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($newItem['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($newItem['price'] ?? 0)), $newItem),
                ':discount' => lyaideu_deal_percent($newItem['discount'] ?? 0),
                ':tag' => clean_text($newItem['tag'] ?? ''),
                ':descr' => clean_text($newItem['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            $newItemId = (int)$pdo->lastInsertId();
            lyaideu_sync_item_slug('other_items', $newItemId, clean_text($newItem['name'] ?? ''));
            if ($vid <= 0) {
                lyaideu_resolve_other_vendor($newItemId);
            }
            lyaideu_save_item_variants($pdo, 'other', $newItemId, !empty($newItem['has_variants']), $newItem['variants'] ?? []);
        }
    }

    if ($section === 'beverages') {
        lyaideu_ensure_beverage_table();
        $beverageVendorId = function ($value): int {
            $vid = (int)($value ?? 0);
            if ($vid > 0) {
                $pdo = $GLOBALS['pdo'] ?? null;
                if (!$pdo instanceof PDO) {
                    return 0;
                }
                $st = $pdo->prepare("SELECT id FROM vendors WHERE id = :id AND scope = 'beverage' AND is_active = 1");
                $st->execute([':id' => $vid]);
                if ($st->fetchColumn()) {
                    return $vid;
                }
            }
            return 0;
        };

        $deleteItem = $pdo->prepare('DELETE FROM beverage_items WHERE id = ?');
        $updateItem = $pdo->prepare(
            'UPDATE beverage_items
             SET name = :name, cat = :cat, category_id = :category_id, unit = :unit, price = :price, discount_percent = :discount, tag = :tag,
                 `desc` = :descr, img = :img, vendor_id = :vendor_id
             WHERE id = :id'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO beverage_items (name, cat, category_id, unit, price, discount_percent, tag, `desc`, img, vendor_id)
             VALUES (:name, :cat, :category_id, :unit, :price, :discount, :tag, :descr, :img, :vendor_id)'
        );

        foreach (($_POST['beverages'] ?? []) as $i => $m) {
            $id = (int)($m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($m['delete'])) {
                $deleteItem->execute([$id]);
                lyaideu_delete_item_variants($pdo, 'beverage', $id);
                lyaideu_purge_item_links('beverage', $id);
                continue;
            }

            $name = clean_text($m['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $vid = $beverageVendorId($m['vendor_id'] ?? 0);
            if ($vid <= 0) {
                $vid = lyaideu_resolve_beverage_vendor($id);
            }

            $img = handle_item_image((string)($m['img'] ?? ''), $m, uploaded_file_field('beverages', $i, 'img_file'), 'beverage_img');
            $catRes = resolve_product_category((int)($m['category_id'] ?? 0), 'beverage');

            $updateItem->execute([
                ':id' => $id,
                ':name' => $name,
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($m['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($m['price'] ?? 0)), $m),
                ':discount' => lyaideu_deal_percent($m['discount'] ?? 0),
                ':tag' => clean_text($m['tag'] ?? ''),
                ':descr' => clean_text($m['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            lyaideu_sync_item_slug('beverage_items', $id, $name);
            lyaideu_save_item_variants($pdo, 'beverage', $id, !empty($m['has_variants']), $m['variants'] ?? []);
        }

        $newItem = $_POST['new_beverages'] ?? [];
        if (clean_text($newItem['name'] ?? '') !== '') {
            $newFile = $_FILES['new_beverages'] ?? null;
            $newImgFile = (isset($newFile['name']['img_file']))
                ? [
                    'name' => $newFile['name']['img_file'],
                    'type' => $newFile['type']['img_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['img_file'],
                    'error' => $newFile['error']['img_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['img_file'] ?? 0,
                ]
                : null;
            $vid = $beverageVendorId($newItem['vendor_id'] ?? 0);
            $img = handle_item_image('', $newItem, $newImgFile, 'beverage_img');
            $catRes = resolve_product_category((int)($newItem['category_id'] ?? 0), 'beverage');

            $insertItem->execute([
                ':name' => clean_text($newItem['name'] ?? ''),
                ':cat' => $catRes[1],
                ':category_id' => $catRes[0] ?: null,
                ':unit' => clean_text($newItem['unit'] ?? ''),
                ':price' => variant_base_price(max(0, (int)($newItem['price'] ?? 0)), $newItem),
                ':discount' => lyaideu_deal_percent($newItem['discount'] ?? 0),
                ':tag' => clean_text($newItem['tag'] ?? ''),
                ':descr' => clean_text($newItem['desc'] ?? ''),
                ':img' => $img,
                ':vendor_id' => $vid > 0 ? $vid : null,
            ]);
            $newItemId = (int)$pdo->lastInsertId();
            lyaideu_sync_item_slug('beverage_items', $newItemId, clean_text($newItem['name'] ?? ''));
            if ($vid <= 0) {
                lyaideu_resolve_beverage_vendor($newItemId);
            }
            lyaideu_save_item_variants($pdo, 'beverage', $newItemId, !empty($newItem['has_variants']), $newItem['variants'] ?? []);
        }
    }

    if ($section === 'hotels') {
        $validKinds = ['hotel', 'mart', 'other', 'beverage'];
        $deleteHotel = $pdo->prepare('DELETE FROM hotels WHERE id = ?');
        $unlinkDishes = $pdo->prepare('UPDATE dishes SET vendor_id = NULL WHERE vendor_id = ?');
        $unlinkMart = $pdo->prepare('UPDATE mart_items SET vendor_id = NULL WHERE vendor_id = ?');
        $unlinkOther = $pdo->prepare('UPDATE other_items SET vendor_id = NULL WHERE vendor_id = ?');
        $unlinkBeverage = $pdo->prepare('UPDATE beverage_items SET vendor_id = NULL WHERE vendor_id = ?');
        $deleteVendor = $pdo->prepare('DELETE FROM vendors WHERE id = ?');
        $updateHotel = $pdo->prepare(
            'UPDATE hotels SET name = :name, type = :type, phone = :phone, emoji = :emoji, logo = :logo, kind = :kind WHERE id = :id'
        );
        $insertHotel = $pdo->prepare(
            'INSERT INTO hotels (name, type, phone, emoji, logo, kind) VALUES (:name, :type, :phone, :emoji, :logo, :kind)'
        );
        $updateVendor = $pdo->prepare(
            'UPDATE vendors SET name = :name, email = :email, phone = :phone, scope = :scope, hotel_id = :hotel_id, is_active = :is_active WHERE id = :id'
        );
        $updateVendorPass = $pdo->prepare(
            'UPDATE vendors SET name = :name, email = :email, phone = :phone, scope = :scope, hotel_id = :hotel_id, pass = :pass, is_active = :is_active WHERE id = :id'
        );
        $insertVendor = $pdo->prepare(
            'INSERT INTO vendors (name, email, phone, scope, hotel_id, pass, is_active, created_at)
             VALUES (:name, :email, :phone, :scope, :hotel_id, :pass, :is_active, :created_at)'
        );

        $vendorPassDefault = 'vendor123';

        foreach (($_POST['hotels'] ?? []) as $i => $h) {
            $id = (int)($h['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $vendorId = (int)($h['vendor_id'] ?? 0);

            if (!empty($h['delete'])) {
                if ($vendorId > 0) {
                    $unlinkDishes->execute([$vendorId]);
                    $unlinkMart->execute([$vendorId]);
                    $unlinkOther->execute([$vendorId]);
                    $unlinkBeverage->execute([$vendorId]);
                    $deleteVendor->execute([$vendorId]);
                }
                $deleteHotel->execute([$id]);
                continue;
            }

            $name = clean_text($h['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $kind = in_array(clean_text($h['kind'] ?? 'hotel'), $validKinds, true) ? clean_text($h['kind']) : 'hotel';
            $logo = handle_hotel_logo((string)($h['logo'] ?? ''), $h, uploaded_file_field('hotels', $i, 'logo_file'));
            $emoji = preg_replace('/[^a-z0-9-]/', '', clean_text($h['emoji'] ?? ''));

            $updateHotel->execute([
                ':id' => $id,
                ':name' => $name,
                ':type' => clean_text($h['type'] ?? ''),
                ':phone' => clean_phone($h['phone'] ?? ''),
                ':emoji' => $emoji !== '' ? $emoji : ($kind === 'mart' ? 'fa-basket-shopping' : ($kind === 'other' ? 'fa-gift' : ($kind === 'beverage' ? 'fa-champagne-glasses' : ''))),
                ':logo' => $logo,
                ':kind' => $kind,
            ]);

            // Hotels, marts, 'other' and 'beverage' stores always own a vendor account.
            if ($kind === 'hotel' || $kind === 'mart' || $kind === 'other' || $kind === 'beverage') {
                $vName = clean_text($h['vendor_name'] ?? '');
                if ($vName === '') {
                    $vName = $name;
                }
                $vEmail = strtolower(trim((string)($h['vendor_email'] ?? '')));
                $vPhone = preg_replace('/[^0-9]/', '', (string)($h['vendor_phone'] ?? ''));
                $vActive = !empty($h['vendor_active']) ? 1 : 0;

                if ($vendorId > 0) {
                    $vPass = (string)($h['vendor_password'] ?? '');
                    if ($vPass !== '') {
                        $updateVendorPass->execute([
                            ':name' => $vName,
                            ':email' => $vEmail,
                            ':phone' => $vPhone,
                            ':scope' => $kind,
                            ':hotel_id' => $id,
                            ':pass' => password_hash($vPass, PASSWORD_DEFAULT),
                            ':is_active' => $vActive,
                            ':id' => $vendorId,
                        ]);
                    } else {
                        $updateVendor->execute([
                            ':name' => $vName,
                            ':email' => $vEmail,
                            ':phone' => $vPhone,
                            ':scope' => $kind,
                            ':hotel_id' => $id,
                            ':is_active' => $vActive,
                            ':id' => $vendorId,
                        ]);
                    }
                } else {
                    if ($vEmail === '') {
                        $vEmail = 'vendor' . $id . '@lyaideu.local';
                    }
                    $insertVendor->execute([
                        ':name' => $vName,
                        ':email' => $vEmail,
                        ':phone' => $vPhone,
                        ':scope' => $kind,
                        ':hotel_id' => $id,
                        ':pass' => password_hash($vendorPassDefault, PASSWORD_DEFAULT),
                        ':is_active' => $vActive,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } elseif ($vendorId > 0) {
                // Store switched away from hotel/mart: its vendor is no longer needed.
                $unlinkDishes->execute([$vendorId]);
                $unlinkMart->execute([$vendorId]);
                $unlinkOther->execute([$vendorId]);
                $unlinkBeverage->execute([$vendorId]);
                $deleteVendor->execute([$vendorId]);
            }
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
            $kind = in_array(clean_text($newHotel['kind'] ?? 'hotel'), $validKinds, true) ? clean_text($newHotel['kind']) : 'hotel';
            $logo = handle_hotel_logo('', $newHotel, $newLogoFile);
            $emoji = preg_replace('/[^a-z0-9-]/', '', clean_text($newHotel['emoji'] ?? ''));

            $insertHotel->execute([
                ':name' => clean_text($newHotel['name'] ?? ''),
                ':type' => clean_text($newHotel['type'] ?? ''),
                ':phone' => clean_phone($newHotel['phone'] ?? ''),
                ':emoji' => $emoji !== '' ? $emoji : ($kind === 'mart' ? 'fa-basket-shopping' : ($kind === 'other' ? 'fa-gift' : ($kind === 'beverage' ? 'fa-champagne-glasses' : ''))),
                ':logo' => $logo,
                ':kind' => $kind,
            ]);
            $newStoreId = (int)$pdo->lastInsertId();

            if ($kind === 'hotel' || $kind === 'mart' || $kind === 'other' || $kind === 'beverage') {
                $vName = clean_text($newHotel['vendor_name'] ?? '');
                if ($vName === '') {
                    $vName = clean_text($newHotel['name'] ?? '');
                }
                $vEmail = strtolower(trim((string)($newHotel['vendor_email'] ?? '')));
                if ($vEmail === '') {
                    $vEmail = 'vendor' . $newStoreId . '@lyaideu.local';
                }
                $vPhone = preg_replace('/[^0-9]/', '', (string)($newHotel['vendor_phone'] ?? ''));
                $vPass = (string)($newHotel['vendor_password'] ?? '');
                if ($vPass === '') {
                    $vPass = $vendorPassDefault;
                }

                $insertVendor->execute([
                    ':name' => $vName,
                    ':email' => $vEmail,
                    ':phone' => $vPhone,
                    ':scope' => $kind,
                    ':hotel_id' => $newStoreId,
                    ':pass' => password_hash($vPass, PASSWORD_DEFAULT),
                    ':is_active' => 1,
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        lyaideu_reindex_item_vendors();
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
             SET name = :name, slug = :slug, parent_id = :parent_id, sort_order = :sort_order, icon = :icon, image = :image
             WHERE id = :id'
        );
        $insertCat = $pdo->prepare(
            'INSERT INTO categories (name, slug, type, parent_id, sort_order, icon, image)
             VALUES (:name, :slug, :type, :parent_id, :sort_order, :icon, :image)'
        );
        $nullDish = $pdo->prepare('UPDATE dishes SET category_id = NULL WHERE category_id = ?');
        $nullMart = $pdo->prepare('UPDATE mart_items SET category_id = NULL WHERE category_id = ?');
        $nullOther = $pdo->prepare('UPDATE other_items SET category_id = NULL WHERE category_id = ?');
        $nullBeverage = $pdo->prepare('UPDATE beverage_items SET category_id = NULL WHERE category_id = ?');
        $reparent = $pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?');
        $deleteCat = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $dupeCat = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND type = :type AND id <> :id');

        foreach (($_POST['categories'] ?? []) as $i => $cat) {
            $id = (int)($cat['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($cat['delete'])) {
                $ids = array_merge([$id], $descOf($id));
                lyaideu_purge_category_links($ids);
                foreach ($ids as $did) {
                    $nullDish->execute([$did]);
                    $nullMart->execute([$did]);
                    $nullOther->execute([$did]);
                    $nullBeverage->execute([$did]);
                    $stImg = $pdo->prepare('SELECT image FROM categories WHERE id = ?');
                    $stImg->execute([$did]);
                    $oldImg = (string)$stImg->fetchColumn();
                    if ($oldImg !== '' && str_starts_with($oldImg, 'uploads/')) {
                        @unlink(__DIR__ . '/' . $oldImg);
                    }
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
            $validTypes = lyaideu_valid_category_types();
            $type = in_array(clean_text($cat['type'] ?? 'menu'), $validTypes, true) ? clean_text($cat['type']) : 'menu';
            $slug = lyaideu_slugify(clean_text($cat['slug'] ?? ''));
            if ($slug === '' || $slug === 'category') {
                $slug = lyaideu_slugify($name);
            }
            $parentId = (int)($cat['parent_id'] ?? 0);
            if ($parentId > 0) {
                /* Never silently drop a requested parent — a dropped parent
                   would turn a sub-category into a top-level one. */
                if (!isset($byId[$parentId]) || $byId[$parentId]['type'] !== $type) {
                    throw new RuntimeException('The selected parent category does not match this category type. Nothing was saved.');
                }
                /* A category may sit under any category EXCEPT itself or its
                   own descendants (that would create a circle). Its current
                   parent is of course still allowed. */
                if ($parentId === $id || in_array($parentId, $descOf($id), true)) {
                    throw new RuntimeException('A category cannot be placed under its own sub-category. Nothing was saved.');
                }
            }
            $sort = max(0, (int)($cat['sort_order'] ?? 0));
            $icon = preg_replace('/[^a-z0-9-]/', '', clean_text($cat['icon'] ?? ''));
            $img = lyaideu_handle_item_image((string)($cat['image'] ?? ''), $cat, uploaded_file_field('categories', $i, 'image_file'), 'cat_img');

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
                ':image' => $img,
                ':id' => $id,
            ]);
        }

        $newCat = $_POST['new_category'] ?? [];
        if (clean_text($newCat['name'] ?? '') !== '') {
            $name = clean_text($newCat['name']);
            $validTypesNew = lyaideu_valid_category_types();
            $type = in_array(clean_text($newCat['type'] ?? 'menu'), $validTypesNew, true) ? clean_text($newCat['type']) : 'menu';
            $slug = lyaideu_slugify(clean_text($newCat['slug'] ?? ''));
            if ($slug === '' || $slug === 'category') {
                $slug = lyaideu_slugify($name);
            }
            $parentId = (int)($newCat['parent_id'] ?? 0);
            if ($parentId > 0) {
                /* Same rule as updates: a requested parent is never silently
                   dropped (that would create a top-level category by accident). */
                if (!isset($byId[$parentId]) || $byId[$parentId]['type'] !== $type) {
                    throw new RuntimeException('The selected parent category does not match the selected type. Pick a parent of the same type.');
                }
            }
            $sort = max(0, (int)($newCat['sort_order'] ?? 0));
            $icon = preg_replace('/[^a-z0-9-]/', '', clean_text($newCat['icon'] ?? ''));
            $newFile = $_FILES['new_category'] ?? null;
            $newImgFile = (isset($newFile['name']['image_file']))
                ? [
                    'name' => $newFile['name']['image_file'],
                    'type' => $newFile['type']['image_file'] ?? '',
                    'tmp_name' => $newFile['tmp_name']['image_file'],
                    'error' => $newFile['error']['image_file'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $newFile['size']['image_file'] ?? 0,
                ]
                : null;
            $img = lyaideu_handle_item_image('', $newCat, $newImgFile, 'cat_img');

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
                ':image' => $img,
            ]);
        }
    }

    if ($section === 'category_reorder') {
        lyaideu_ensure_categories_table();

        $ids = [];
        foreach ((array)($_POST['order'] ?? []) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0 && !in_array($cid, $ids, true)) {
                $ids[] = $cid;
            }
        }
        if ($ids) {
            /* The sibling ids arrive already in their new display order —
               just rewrite clean sequential positions (0, 1, 2, …). */
            $updOrder = $pdo->prepare('UPDATE categories SET sort_order = :o WHERE id = :id');
            foreach (array_values($ids) as $pos => $cid) {
                $updOrder->execute([':o' => $pos, ':id' => $cid]);
            }
        }
    }

    if ($section === 'sections') {
        lyaideu_ensure_sections_tables();

        $iconSafe = static function ($value): string {
            return preg_replace('/[^a-z0-9-]/', '', clean_text((string)$value));
        };
        $normalizeSlug = static function (string $raw, string $name): string {
            /* lyaideu_slugify() never returns an empty string (it falls back
               to "category" on its own), so an untouched/empty input must be
               detected BEFORE calling it and re-derived from the name. */
            $raw = trim($raw);
            $slug = ($raw === '') ? '' : lyaideu_slugify($raw);
            if ($slug === '' || $slug === 'section' || $slug === 'category') {
                $slug = lyaideu_slugify($name);
            }
            return $slug;
        };

        $updSection = $pdo->prepare(
            'UPDATE category_sections SET slug = :slug, name = :name, icon = :icon, `desc` = :descr, is_active = :active WHERE id = :id'
        );
        $delSection = $pdo->prepare('DELETE FROM category_sections WHERE id = :id');
        $dupeSection = $pdo->prepare('SELECT id FROM category_sections WHERE slug = :slug AND id <> :id');
        $slugOf = $pdo->prepare('SELECT slug FROM category_sections WHERE id = :id');

        foreach (($_POST['sections'] ?? []) as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!empty($s['delete'])) {
                $slugOf->execute([':id' => $id]);
                $oldSlug = (string)$slugOf->fetchColumn();
                if ($oldSlug !== '') {
                    $stCats = $pdo->prepare('SELECT id, image FROM categories WHERE type = :t');
                    $stCats->execute([':t' => $oldSlug]);
                    $rows = $stCats->fetchAll(PDO::FETCH_ASSOC);
                    $catIds = array_map('intval', array_column($rows, 'id'));
                    foreach ($rows as $row) {
                        $oldImg = (string)$row['image'];
                        if ($oldImg !== '' && str_starts_with($oldImg, 'uploads/')) {
                            @unlink(__DIR__ . '/' . $oldImg);
                        }
                    }
                    if ($catIds) {
                        lyaideu_purge_category_links($catIds);
                        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                        $pdo->prepare("DELETE FROM categories WHERE id IN ($placeholders)")->execute($catIds);
                    }
                }
                $delSection->execute([':id' => $id]);
                continue;
            }

            $name = clean_text($s['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $slug = $normalizeSlug(clean_text($s['slug'] ?? ''), $name);
            if (lyaideu_section_slug_reserved($slug)) {
                throw new RuntimeException('The section URL "' . $slug . '" is reserved by the system. Pick a different URL.');
            }
            $dupeSection->execute([':slug' => $slug, ':id' => $id]);
            if ($dupeSection->fetchColumn()) {
                throw new RuntimeException('A section with the URL "' . $slug . '" already exists.');
            }

            $slugOf->execute([':id' => $id]);
            $oldSlug = (string)$slugOf->fetchColumn();

            $updSection->execute([
                ':slug' => $slug,
                ':name' => mb_substr($name, 0, 80),
                ':icon' => $iconSafe($s['icon'] ?? '') ?: 'fa-layer-group',
                ':descr' => mb_substr(clean_text($s['desc'] ?? ''), 0, 190),
                ':active' => !empty($s['is_active']) ? 1 : 0,
                ':id' => $id,
            ]);

            /* Categories store their section's slug as `type`, so a renamed
               section drags its whole category tree along. A failure here means
               some category slug already exists inside the new type key. */
            if ($oldSlug !== '' && $oldSlug !== $slug) {
                try {
                    $pdo->prepare('UPDATE categories SET type = :new WHERE type = :old')
                        ->execute([':new' => $slug, ':old' => $oldSlug]);
                } catch (Throwable $e) {
                    throw new RuntimeException('Could not rename the section: one of its category URLs already exists under "' . $slug . '". Nothing was saved.');
                }
            }
        }

        $newSection = $_POST['new_section'] ?? [];
        if (clean_text($newSection['name'] ?? '') !== '') {
            $name = mb_substr(clean_text($newSection['name']), 0, 80);
            $slug = $normalizeSlug(clean_text($newSection['slug'] ?? ''), $name);
            if (lyaideu_section_slug_reserved($slug)) {
                throw new RuntimeException('The section URL "' . $slug . '" is reserved by the system. Pick a different URL.');
            }
            $dupeNew = $pdo->prepare('SELECT id FROM category_sections WHERE slug = :slug');
            $dupeNew->execute([':slug' => $slug]);
            if ($dupeNew->fetchColumn()) {
                throw new RuntimeException('A section with the URL "' . $slug . '" already exists.');
            }
            $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), -1) FROM category_sections')->fetchColumn();
            $pdo->prepare(
                'INSERT INTO category_sections (slug, name, icon, `desc`, sort_order, is_active, created_at)
                 VALUES (:slug, :name, :icon, :descr, :sort, 1, :created)'
            )->execute([
                ':slug' => $slug,
                ':name' => $name,
                ':icon' => $iconSafe($newSection['icon'] ?? '') ?: 'fa-layer-group',
                ':descr' => mb_substr(clean_text($newSection['desc'] ?? ''), 0, 190),
                ':sort' => $maxSort + 1,
                ':created' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    if ($section === 'section_reorder') {
        lyaideu_ensure_sections_tables();

        $ids = [];
        foreach ((array)($_POST['order'] ?? []) as $sid) {
            $sid = (int)$sid;
            if ($sid > 0 && !in_array($sid, $ids, true)) {
                $ids[] = $sid;
            }
        }
        if ($ids) {
            $updOrder = $pdo->prepare('UPDATE category_sections SET sort_order = :o WHERE id = :id');
            foreach (array_values($ids) as $pos => $sid) {
                $updOrder->execute([':o' => $pos, ':id' => $sid]);
            }
        }
    }

    if ($section === 'section_links') {
        lyaideu_ensure_sections_tables();

        $cid = (int)($_POST['category_id'] ?? 0);
        $stCat = $pdo->prepare('SELECT id, type FROM categories WHERE id = :id');
        $stCat->execute([':id' => $cid]);
        $catRow = $stCat->fetch(PDO::FETCH_ASSOC);
        $builtinTypes = ['menu', 'mart', 'other', 'beverage'];
        if (!$catRow || !in_array((string)$catRow['type'], lyaideu_valid_category_types(), true) || in_array((string)$catRow['type'], $builtinTypes, true)) {
            throw new RuntimeException('Pick a category that belongs to one of your custom sections.');
        }

        $pdo->prepare('DELETE FROM section_item_links WHERE category_id = :cid')->execute([':cid' => $cid]);

        $tableOf = ['dish' => 'dishes', 'mart' => 'mart_items', 'other' => 'other_items', 'beverage' => 'beverage_items'];
        $validTypes = lyaideu_link_item_types();
        $seen = [];
        $insLink = $pdo->prepare('INSERT IGNORE INTO section_item_links (item_type, item_id, category_id) VALUES (:t, :iid, :cid)');
        foreach ((array)($_POST['assign'] ?? []) as $token) {
            $parts = explode(':', (string)$token, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $t = trim($parts[0]);
            $iid = (int)$parts[1];
            if (!in_array($t, $validTypes, true) || $iid <= 0) {
                continue;
            }
            $key = $t . ':' . $iid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chk = $pdo->prepare("SELECT id FROM `{$tableOf[$t]}` WHERE id = :id");
            $chk->execute([':id' => $iid]);
            if (!$chk->fetchColumn()) {
                continue;
            }
            $insLink->execute([':t' => $t, ':iid' => $iid, ':cid' => $cid]);
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
