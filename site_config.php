<?php
/**
 * LyaiDeu site settings — logo, favicon & admin credentials.
 * Values live in the `settings` table and fall back to defaults.
 */

function lyaideu_load_pdo(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
        return $pdo;
    }

    if (!defined('LYAIDEU_DB_THROW')) {
        define('LYAIDEU_DB_THROW', true);
    }
    try {
        require_once __DIR__ . '/db.php';
        // db.php executes in this function's scope, so its $pdo lands on our static.
        if ($pdo instanceof PDO) {
            $GLOBALS['pdo'] = $pdo;
        }
        $pdo = $GLOBALS['pdo'] ?? $pdo ?? null;
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

function lyaideu_settings(): array {
    if (!isset($GLOBALS['__lyaideu_settings_loaded'])) {
        $GLOBALS['__lyaideu_settings'] = [];
        $GLOBALS['__lyaideu_settings_loaded'] = true;
        $pdo = lyaideu_load_pdo();
        if ($pdo instanceof PDO) {
            try {
                foreach ($pdo->query('SELECT skey, sval FROM settings') as $row) {
                    $GLOBALS['__lyaideu_settings'][$row['skey']] = (string)$row['sval'];
                }
            } catch (Throwable $e) {
                $GLOBALS['__lyaideu_settings'] = [];
            }
        }
    }
    return $GLOBALS['__lyaideu_settings'];
}

function lyaideu_settings_clear(): void {
    unset($GLOBALS['__lyaideu_settings_loaded'], $GLOBALS['__lyaideu_settings']);
}

function site_setting(string $key, ?string $default = null): string {
    $settings = lyaideu_settings();
    $value = $settings[$key] ?? null;
    if ($value === null || $value === '') {
        return (string)$default;
    }
    return $value;
}

function lyaideu_ensure_settings_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                skey VARCHAR(100) NOT NULL PRIMARY KEY,
                sval TEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_ensure_mart_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'mart_items'");
        $exists = $stmt && (bool)$stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec(
                'CREATE TABLE mart_items (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    cat VARCHAR(50) NOT NULL,
                    unit VARCHAR(50) NOT NULL DEFAULT \'\',
                    price INT UNSIGNED NOT NULL DEFAULT 0,
                    tag VARCHAR(100) NOT NULL DEFAULT \'\',
                    `desc` TEXT NOT NULL,
                    img VARCHAR(500) NOT NULL DEFAULT \'\',
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $seed = $pdo->prepare(
                'INSERT INTO mart_items (name, cat, unit, price, tag, `desc`, img)
                 VALUES (:name, :cat, :unit, :price, :tag, :descr, :img)'
            );
            $defaults = [
                ['Fresh Potatoes', 'vegetables', 'kg', 60, '', 'Locally grown potatoes, perfect for aloo tareko.', ''],
                ['Onions', 'vegetables', 'kg', 55, '', 'Sweet red onions for everyday cooking.', ''],
                ['Ripe Tomatoes', 'vegetables', '500 g', 45, '', 'Juicy vine-ripened tomatoes straight from the farm.', ''],
                ['Red Apples', 'fruits', 'kg', 240, '', 'Crisp and sweet red apples, great for the whole family.', ''],
                ['Bananas', 'fruits', 'dozen', 120, '', 'Naturally ripe bananas, ready to eat.', ''],
                ['Fresh Milk', 'dairy', 'litre', 95, '', 'Farm-fresh full cream milk delivered daily.', ''],
                ['Curd (Dahi)', 'dairy', '500 g', 150, '', 'Thick, creamy set dahi made every morning.', ''],
                ['Basmati Rice', 'staples', 'kg', 185, '', 'Premium aged basmati, long grain and aromatic.', ''],
                ['Cooking Oil', 'oils', 'litre', 220, '', 'Pure refined sunflower oil for all your cooking.', ''],
                ['Parle-G Biscuits', 'snacks', 'pack', 40, '', 'The classic crunchy glucose biscuit everyone loves.', ''],
            ];
            foreach ($defaults as $row) {
                $seed->execute([
                    ':name' => $row[0],
                    ':cat' => $row[1],
                    ':unit' => $row[2],
                    ':price' => $row[3],
                    ':tag' => $row[4],
                    ':descr' => $row[5],
                    ':img' => $row[6],
                ]);
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_slugify(string $value): string {
    $slug = strtolower(trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8')));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug === '' ? 'category' : $slug;
}

/**
 * Ensures the `categories` table exists, adds `category_id` to product tables,
 * seeds the default menu & mart category tree and assigns existing products.
 */
function lyaideu_ensure_categories_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (!empty($GLOBALS['__lyaideu_categories_ready'])) {
        return true;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS categories (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT \'menu\',
                parent_id INT UNSIGNED NULL,
                sort_order INT NOT NULL DEFAULT 0,
                icon VARCHAR(60) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                UNIQUE KEY uq_cat_slug_type (slug, type),
                KEY idx_cat_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach (['dishes', 'mart_items'] as $table) {
            $col = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'category_id'")->fetchAll();
            if (!$col) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN category_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_{$table}_category (category_id)");
            }
        }

        $GLOBALS['__lyaideu_categories_ready'] = true;

        lyaideu_seed_categories();
        lyaideu_assign_products_to_categories();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lyaideu_upsert_category(string $name, string $slug, string $type, ?int $parentId, int $sort, string $icon): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO categories (name, slug, type, parent_id, sort_order, icon)
         VALUES (:name, :slug, :type, :parent_id, :sort_order, :icon)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            parent_id = VALUES(parent_id),
            sort_order = VALUES(sort_order),
            icon = VALUES(icon)'
    );
    $st->execute([
        ':name' => $name,
        ':slug' => $slug,
        ':type' => $type,
        ':parent_id' => $parentId,
        ':sort_order' => $sort,
        ':icon' => $icon,
    ]);
}

function lyaideu_category_id_by_slug(string $slug, string $type): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return 0;
    }
    $st = $pdo->prepare('SELECT id FROM categories WHERE slug = :s AND type = :t');
    $st->execute([':s' => $slug, ':t' => $type]);
    return (int)$st->fetchColumn();
}

function lyaideu_seed_categories(): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }

    $tree = [
        'menu' => [
            ['Momos', 'momo', 'fa-drumstick-bite', [
                ['Steamed Momos', 'steamed-momo', 'fa-drumstick-bite'],
                ['Fried Momos', 'fried-momo', 'fa-fire'],
                ['Jhol Momos', 'jhol-momo', 'fa-pepper-hot'],
            ]],
            ['Pizza', 'pizza', 'fa-pizza-slice', [
                ['Veggie Pizza', 'veggie-pizza', 'fa-pizza-slice'],
                ['Chicken & Meat Pizza', 'chicken-pizza', 'fa-bacon'],
            ]],
            ['Chowmein', 'chowmein', 'fa-bowl-rice', [
                ['Veg Chowmein', 'veg-chowmein', 'fa-carrot'],
                ['Chicken Chowmein', 'chicken-chowmein', 'fa-drumstick-bite'],
                ['Schezwan Chowmein', 'schezwan-chowmein', 'fa-pepper-hot'],
            ]],
            ['Snacks', 'snacks', 'fa-cookie', [
                ['Burgers', 'burgers', 'fa-burger'],
                ['Fries & Wedges', 'fries-wedges', 'fa-bowl-rice'],
                ['Fried Chicken', 'fried-chicken', 'fa-drumstick-bite'],
                ['Traditional Snacks', 'traditional-snacks', 'fa-utensils'],
            ]],
            ['Beverages', 'beverages', 'fa-mug-saucer', [
                ['Hot Drinks', 'hot-drinks', 'fa-mug-hot'],
                ['Cool Drinks & Shakes', 'cool-drinks', 'fa-glass-water'],
            ]],
            ['Dinner & Thali', 'dinner', 'fa-bowl-food', [
                ['Thali Sets', 'thali-sets', 'fa-bowl-food'],
                ['Rice & Curry', 'rice-curry', 'fa-bowl-rice'],
                ['Grills & Skewers', 'grills-skewers', 'fa-fire'],
            ]],
        ],
        'mart' => [
            ['Vegetables', 'vegetables', 'fa-carrot', [
                ['Root Vegetables', 'root-vegetables', 'fa-carrot'],
                ['Leafy & Pod Veggies', 'leafy-pod', 'fa-leaf'],
            ]],
            ['Fruits', 'fruits', 'fa-apple-whole', [
                ['Local Fruits', 'local-fruits', 'fa-apple-whole'],
                ['Imported Fruits', 'imported-fruits', 'fa-apple-whole'],
            ]],
            ['Dairy', 'dairy', 'fa-cow', [
                ['Milk & Curd', 'milk-curd', 'fa-cow'],
                ['Paneer & Butter', 'paneer-butter', 'fa-cheese'],
            ]],
            ['Staples', 'staples', 'fa-bowl-rice', [
                ['Grains & Rice', 'grains-rice', 'fa-bowl-rice'],
                ['Pantry Essentials', 'pantry', 'fa-basket-shopping'],
            ]],
            ['Oils & Spices', 'oils', 'fa-mortar-pestle', [
                ['Cooking Oils', 'cooking-oils', 'fa-mortar-pestle'],
                ['Spices & Masala', 'spices', 'fa-mortar-pestle'],
            ]],
            ['Snacks', 'snacks', 'fa-cookie', [
                ['Chips & Biscuits', 'chips-biscuits', 'fa-cookie'],
                ['Chocolates', 'chocolates', 'fa-chocolate-bar'],
            ]],
        ],
    ];

    foreach ($tree as $type => $items) {
        $sort = 1;
        foreach ($items as $item) {
            [$name, $slug, $icon, $children] = $item;
            lyaideu_upsert_category($name, $slug, $type, null, $sort, $icon);
            $parentId = lyaideu_category_id_by_slug($slug, $type);
            $childSort = 1;
            foreach ($children as $child) {
                lyaideu_upsert_category($child[0], $child[1], $type, $parentId ?: null, $childSort, $child[2]);
                $childSort++;
            }
            $sort++;
        }
    }
}

function lyaideu_assign_products_to_categories(): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }

    $rules = [
        'dishes' => [
            'momo' => [['steamed-momo', ['cheese', 'steam', 'chilli']], ['fried-momo', ['fried']], ['jhol-momo', ['jhol']]],
            'pizza' => [['veggie-pizza', ['veggie', 'margherita', 'double cheese', 'vegetable']], ['chicken-pizza', ['chicken', 'bbq', 'pepperoni']]],
            'chowmein' => [['veg-chowmein', ['veg', 'hakka']], ['chicken-chowmein', ['chicken']], ['schezwan-chowmein', ['schezwan']]],
            'snacks' => [['burgers', ['burger']], ['fries-wedges', ['fries', 'wedges', 'potato']], ['fried-chicken', ['lollipop', 'popcorn']], ['traditional-snacks', ['samay', 'spring roll', 'pakoda']]],
            'beverages' => [['hot-drinks', ['chai', 'coffee', 'mocha']], ['cool-drinks', ['shake', 'soda', 'lassi', 'frappe']]],
            'dinner' => [['thali-sets', ['thali']], ['grills-skewers', ['sekuwa', 'steak', 'mutton']], ['rice-curry', ['biryani', 'curry', 'fried rice', 'dal bhat']]],
        ],
        'mart' => [
            'vegetables' => [['root-vegetables', ['potato', 'onion', 'carrot', 'cauliflower', 'garlic', 'ginger']], ['leafy-pod', ['tomato', 'bean', 'spinach', 'cabbage', 'cucumber']]],
            'fruits' => [['local-fruits', ['mango', 'orange', 'banana', 'papaya', 'watermelon']], ['imported-fruits', ['apple', 'grape', 'kiwi', 'strawberry']]],
            'dairy' => [['milk-curd', ['milk', 'curd', 'dahi', 'cream', 'ghee']], ['paneer-butter', ['paneer', 'butter']]],
            'staples' => [['grains-rice', ['rice', 'wheat', 'flour', 'daal', 'dal']], ['pantry', ['salt', 'sugar', 'tea']]],
            'oils' => [['cooking-oils', ['oil']], ['spices', ['spice', 'masala', 'turmeric', 'chilli powder']]],
            'snacks' => [['chips-biscuits', ['chips', 'biscuit', 'parle']], ['chocolates', ['chocolate']]],
        ],
    ];

    $tables = ['dishes' => 'dishes', 'mart' => 'mart_items'];
    foreach ($tables as $key => $table) {
        $rows = $pdo->query("SELECT id, name, cat, category_id FROM `$table`")->fetchAll();
        $upd = $pdo->prepare("UPDATE `$table` SET category_id = :cid WHERE id = :id");
        foreach ($rows as $row) {
            if ((int)$row['category_id'] > 0) {
                continue;
            }
            $cat = strtolower(trim((string)$row['cat']));
            $name = strtolower((string)$row['name']);
            $target = '';
            foreach (($rules[$key][$cat] ?? []) as [$slug, $keywords]) {
                foreach ($keywords as $kw) {
                    if (str_contains($name, $kw)) {
                        $target = $slug;
                        break 2;
                    }
                }
            }
            if ($target === '') {
                $target = $cat !== '' ? $cat : 'category';
            }
            $cid = lyaideu_category_id_by_slug($target, $key === 'dishes' ? 'menu' : 'mart');
            if ($cid > 0) {
                $upd->execute([':cid' => $cid, ':id' => (int)$row['id']]);
            }
        }
    }
}

function lyaideu_categories(?string $type = null): array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $sql = 'SELECT id, name, slug, type, parent_id, sort_order, icon FROM categories';
    $params = [];
    if ($type !== null && $type !== '') {
        $sql .= ' WHERE type = :type';
        $params[':type'] = $type;
    }
    $sql .= ' ORDER BY type, sort_order, name';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function lyaideu_categories_flat(string $type, int $excludeId = 0): array {
    $all = lyaideu_categories($type);
    if ($excludeId > 0) {
        $skip = [];
        $frontier = [$excludeId];
        while ($frontier) {
            $cur = array_shift($frontier);
            $skip[] = $cur;
            foreach ($all as $c) {
                if ((int)$c['parent_id'] === $cur) {
                    $frontier[] = (int)$c['id'];
                }
            }
        }
        $all = array_filter($all, fn($c) => !in_array((int)$c['id'], $skip, true));
    }
    $byParent = [];
    foreach ($all as $c) {
        $byParent[(int)$c['parent_id']][] = $c;
    }
    $out = [];
    $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
        foreach (($byParent[$parentId] ?? []) as $c) {
            $c['depth'] = $depth;
            $out[] = $c;
            $walk((int)$c['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

function lyaideu_category_path(int $categoryId): array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $categoryId <= 0) {
        return [];
    }
    $byId = [];
    foreach ($pdo->query('SELECT id, name, slug, type, parent_id, icon FROM categories') as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $path = [];
    $cur = $byId[$categoryId] ?? null;
    $guard = 0;
    while ($cur && $guard++ < 12) {
        $path[] = $cur;
        $cur = isset($byId[(int)$cur['parent_id']]) ? $byId[(int)$cur['parent_id']] : null;
    }
    return array_reverse($path);
}

function lyaideu_item_cats(?int $categoryId, string $fallbackCat): array {
    $slugs = [];
    foreach (lyaideu_category_path((int)$categoryId) as $c) {
        $slugs[] = $c['slug'];
    }
    if (!$slugs && $fallbackCat !== '') {
        $slugs = [$fallbackCat];
    }
    return $slugs;
}

function lyaideu_cat_name(?int $categoryId): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || !$categoryId) {
        return '';
    }
    $st = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $st->execute([(int)$categoryId]);
    return (string)$st->fetchColumn();
}

/**
 * Adds a unique `name_slug` column to dishes/mart_items and backfills unique
 * slugs (name-slug, with "-2", "-3" ... suffixes for duplicate names).
 */
function lyaideu_ensure_product_slugs(): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        foreach (['dishes', 'mart_items'] as $table) {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'name_slug'")->fetchAll();
            if (!$cols) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN name_slug VARCHAR(120) NOT NULL DEFAULT ''");
            }
            $used = $pdo->query("SELECT name_slug FROM `$table` WHERE name_slug <> ''")->fetchAll(PDO::FETCH_COLUMN);
            $used = array_flip($used);
            $update = $pdo->prepare("UPDATE `$table` SET name_slug = :s WHERE id = :id");
            $st = $pdo->query("SELECT id, name FROM `$table` WHERE name_slug = '' ORDER BY id");
            foreach ($st as $row) {
                $base = lyaideu_slugify((string)$row['name']);
                if ($base === '' || $base === 'category') {
                    $base = 'item';
                }
                $slug = $base;
                $n = 2;
                while (isset($used[$slug])) {
                    $slug = $base . '-' . $n++;
                }
                $used[$slug] = true;
                $update->execute([':s' => $slug, ':id' => (int)$row['id']]);
            }
        }
    } catch (Throwable $e) {
        // Best-effort; never break the page because of it.
    }
}

/**
 * Returns a unique name slug for a new/renamed product in a given table.
 */
function lyaideu_sync_item_slug(string $table, int $id, string $name): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || !in_array($table, ['dishes', 'mart_items'], true) || $id <= 0) {
        return;
    }
    try {
        $base = lyaideu_slugify($name);
        if ($base === '' || $base === 'category') {
            $base = 'item';
        }
        $used = $pdo->query("SELECT name_slug FROM `$table` WHERE id <> " . (int)$id . " AND name_slug <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $used = array_flip($used);
        $slug = $base;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $n++;
        }
        $st = $pdo->prepare("UPDATE `$table` SET name_slug = :s WHERE id = :id");
        $st->execute([':s' => $slug, ':id' => $id]);
    } catch (Throwable $e) {
        // Best-effort.
    }
}

/**
 * Ensures the `hotels` table acts as a generic partner-stores table.
 * Adds the `kind` column (hotel / mart / other) if missing and seeds a
 * default Mart store so it shows up on the homepage & Stores page.
 * Idempotent — safe to call on every request.
 */
function lyaideu_ensure_stores(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        lyaideu_ensure_column($pdo, 'hotels', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'hotel'");
        lyaideu_ensure_column($pdo, 'hotels', 'desc', 'TEXT NOT NULL');
        $martStore = (int)$pdo->query("SELECT COUNT(*) FROM hotels WHERE kind = 'mart'")->fetchColumn();
        if ($martStore === 0) {
            $pdo->prepare(
                "INSERT INTO hotels (name, type, phone, emoji, logo, kind) VALUES (?, ?, ?, ?, ?, 'mart')"
            )->execute(['LyaiDeu Mart', 'Grocery & daily essentials', '', 'fa-basket-shopping', '']);
        }

        // Link each mart vendor to its mart store (matched by name) so a
        // store's page shows only that store's products.
        try {
            $hasVendors = (bool)$pdo->query("SHOW TABLES LIKE 'vendors'")->fetchColumn();
            if ($hasVendors) {
                $martStores = $pdo->query("SELECT id, name FROM hotels WHERE kind = 'mart' ORDER BY id")->fetchAll();
                $storeByName = [];
                foreach ($martStores as $ms) {
                    $storeByName[lyaideu_normalize_name((string)$ms['name'])] = (int)$ms['id'];
                }
                if ($storeByName) {
                    $link = $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ? AND (hotel_id IS NULL OR hotel_id = 0)');
                    foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'mart'") as $mv) {
                        if (!empty($mv['hotel_id'])) {
                            continue;
                        }
                        $key = lyaideu_normalize_name((string)$mv['name']);
                        if ($key !== '' && isset($storeByName[$key])) {
                            $link->execute([$storeByName[$key], (int)$mv['id']]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore linking errors.
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensures a generous catalog exists (dishes, mart items, hotels).
 * Idempotent — inserts any missing rows by name, safe on existing installs.
 */
function lyaideu_seed_catalog(): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        lyaideu_ensure_mart_table();
        lyaideu_ensure_categories_table();
        lyaideu_ensure_product_slugs();

        $dishes = [
            ['Chicken Chowmein', 'Wok Star Kitchen', 'chowmein', 220, '', 'Street Style', 'Wok-tossed noodles with tender chicken strips and crunchy veggies.', ''],
            ['Jhol Momo', 'Himalayan Momo House', 'momo', 280, '', 'Spicy 🌶', 'Steamed chicken momos drowned in a fiery soupy jhol achar.', ''],
            ['Cheese Momo', 'Momo Junction', 'momo', 280, '', '', 'Steamed momos filled with a gooey three-cheese blend.', ''],
            ['Fried Momo', 'New Road Noodle Bar', 'momo', 250, '', 'Crispy', 'Golden-fried momos served with classic tomato achar.', ''],
            ['Pepperoni Pizza', 'Slice of Kathmandu', 'pizza', 780, '', 'Best Seller', 'Classic pepperoni with bubbling mozzarella on a crisp base.', ''],
            ['Veggie Supreme Pizza', 'Fire & Dough Pizza Co.', 'pizza', 690, '', '', 'Loaded with bell peppers, olives, onion and mushroom.', ''],
            ['Double Cheese Pizza', 'Slice of Kathmandu', 'pizza', 720, '', 'Cheese Lovers', 'Extra mozzarella and cheddar for serious cheese fans.', ''],
            ['Veg Hakka Noodles', 'New Road Noodle Bar', 'chowmein', 190, '', '', 'Classic Indian-Chinese hakka noodles, vegetable style.', ''],
            ['Schezwan Chowmein', 'Dragon Wok', 'chowmein', 230, '', 'Spicy 🌶', 'Fiery schezwan sauce tossed with noodles and garden veggies.', ''],
            ['Chicken Popcorn', 'Burger Hub', 'snacks', 320, '', '', 'Crispy bite-sized fried chicken with a dipping sauce.', ''],
            ['Potato Wedges', 'Burger Hub', 'snacks', 190, '', '', 'Seasoned golden wedges with a spicy mayo dip.', ''],
            ['Paneer Pakoda', 'Ghar Ghar Rasoee', 'snacks', 260, '', 'Veg', 'Spiced paneer fritters served with fresh mint chutney.', ''],
            ['French Fries', 'Wok Star Kitchen', 'snacks', 140, '', '', 'Golden crispy fries with a side of ketchup.', ''],
            ['Chicken Lollipop', 'Dragon Wok', 'snacks', 340, '', 'Popular', 'Juicy fried chicken lollipops with a garlic dip.', ''],
            ['Fresh Lime Soda', 'Sweet Valley Café', 'beverages', 150, '', '', 'Effervescent lime soda, sweet or salty, served ice cold.', ''],
            ['Masala Chai', 'Kathmandu Brew House', 'beverages', 120, '', '', 'Spiced Nepali milk tea, strong, comforting and aromatic.', ''],
            ['Oreo Shake', 'Sweet Valley Café', 'beverages', 320, '', '', 'Creamy oreo-cookie milkshake topped with whipped cream.', ''],
            ['Iced Mocha', 'Kathmandu Brew House', 'beverages', 300, '', '', 'Espresso, chocolate and cold milk shaken over ice.', ''],
            ['Chicken Biryani', 'Ghar Ghar Rasoee', 'dinner', 420, '', 'Chef Pick', 'Fragrant basmati rice layered with spiced chicken.', ''],
            ['Mutton Sekuwa', 'Thakali Kitchen', 'dinner', 520, '', 'Traditional', 'Char-grilled, heavily spiced mutton skewers with beaten rice.', ''],
            ['Vegetable Curry Thali', 'Thakali Kitchen', 'dinner', 320, '', 'Veg', 'A hearty veg thali — dal, rice, seasonal curries and achar.', ''],
            ['Dal Bhat Power', 'Ghar Ghar Rasoee', 'dinner', 350, '', 'Homestyle', 'The ultimate dal bhat with seasonal tarkari and a spoon of ghee.', ''],
            ['Butter Chicken & Naan', 'Ghar Ghar Rasoee', 'dinner', 460, '', 'Best Seller', 'Creamy tomato-butter chicken with soft butter naan.', ''],
            ['Chicken Fried Rice', 'Dragon Wok', 'dinner', 280, '', '', 'Wok-fired rice with egg, chicken and spring onion.', ''],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO dishes (name, hotel, cat, price, phone, tag, `desc`, img)
             VALUES (:name, :hotel, :cat, :price, :phone, :tag, :descr, :img)'
        );
        $existing = $pdo->query('SELECT name FROM dishes')->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_flip($existing);
        foreach ($dishes as $d) {
            if (isset($existing[$d[0]])) {
                continue;
            }
            $stmt->execute([
                ':name' => $d[0],
                ':hotel' => $d[1],
                ':cat' => $d[2],
                ':price' => (int)$d[3],
                ':phone' => $d[4],
                ':tag' => $d[5],
                ':descr' => $d[6],
                ':img' => $d[7],
            ]);
        }

        $mart = [
            ['Cauliflower', 'vegetables', 'head', 70, '', 'Fresh white cauliflower for aloo-cauli.', ''],
            ['Carrots', 'vegetables', 'kg', 90, '', 'Sweet tender carrots, great raw or cooked.', ''],
            ['Green Beans', 'vegetables', 'kg', 110, '', 'Snap-fresh beans for everyday cooking.', ''],
            ['Fresh Oranges', 'fruits', 'kg', 190, '', 'Juicy, tart-sweet Nepali oranges.', ''],
            ['Mangoes', 'fruits', 'kg', 230, '', 'Sweet local mangoes while the season lasts.', ''],
            ['Paneer', 'dairy', '250 g', 160, '', 'Soft fresh paneer for curries and pakodas.', ''],
            ['Butter', 'dairy', '100 g', 120, '', 'Creamy dairy butter for roti and cooking.', ''],
            ['Salt', 'staples', 'kg', 35, '', 'Iodised table salt, an everyday essential.', ''],
            ['Sugar', 'staples', 'kg', 90, '', 'Fine white sugar for tea and cooking.', ''],
            ['Tea Leaves', 'staples', '250 g', 240, '', 'Nepali black tea for a strong morning cup.', ''],
            ['Mustard Oil', 'oils', 'litre', 260, '', 'Traditional mustard oil with a warm kick.', ''],
            ['Potato Chips', 'snacks', 'pack', 50, '', 'Crunchy potato chips in a popular local flavour.', ''],
            ['Chocolate Bar', 'snacks', 'pack', 80, '', 'Milk chocolate bar for sweet cravings.', ''],
        ];
        $stmt2 = $pdo->prepare(
            'INSERT INTO mart_items (name, cat, unit, price, tag, `desc`, img)
             VALUES (:name, :cat, :unit, :price, :tag, :descr, :img)'
        );
        $existingMart = $pdo->query('SELECT name FROM mart_items')->fetchAll(PDO::FETCH_COLUMN);
        $existingMart = array_flip($existingMart);
        foreach ($mart as $m) {
            if (isset($existingMart[$m[0]])) {
                continue;
            }
            $stmt2->execute([
                ':name' => $m[0],
                ':cat' => $m[1],
                ':unit' => $m[2],
                ':price' => (int)$m[3],
                ':tag' => $m[4],
                ':descr' => $m[5],
                ':img' => $m[6],
            ]);
        }

        $hotels = [
            ['Curry House', 'Dinner · Lazimpat', '9845566771', 'fa-bowl-food'],
            ['Chill Out Café', 'Café & Snacks · New Baneshwor', '9845566772', 'fa-mug-saucer'],
            ['Rara Restaurant', 'Dinner · Kalanki', '9845566773', 'fa-bowl-food'],
            ['Buff Corner', 'Fast Food · Gaushala', '9845566774', 'fa-burger'],
            ['Adda Café', 'Beverages · Kupondole', '9845566775', 'fa-mug-saucer'],
            ['Chiya Ghar', 'Tea House · Patan', '9845566776', 'fa-mug-saucer'],
        ];
        $stmt3 = $pdo->prepare(
            'INSERT INTO hotels (name, type, phone, emoji)
             VALUES (:name, :type, :phone, :emoji)'
        );
        $existingHotels = $pdo->query('SELECT name FROM hotels')->fetchAll(PDO::FETCH_COLUMN);
        $existingHotels = array_flip($existingHotels);
        foreach ($hotels as $h) {
            if (isset($existingHotels[$h[0]])) {
                continue;
            }
            $stmt3->execute([
                ':name' => $h[0],
                ':type' => $h[1],
                ':phone' => $h[2],
                ':emoji' => $h[3],
            ]);
        }
    } catch (Throwable $e) {
        // Seeding is best-effort; never break the page because of it.
    }
}

/**
 * Ensures the delivery system tables exist (vendors, riders), adds the
 * vendor/rider assignment columns to the `orders` table, and adds the
 * vendor -> hotel / product ownership columns. Runs a one-time backfill so
 * existing vendors and products are linked on first upgrade.
 */
/**
 * Delivery pricing & time configuration, editable by the admin.
 * Schedules are 1-based arrays: index 0 = cost/time for 1 vendor,
 * index 1 = 2 vendors, and so on. Falls back to sensible defaults.
 */
function lyaideu_delivery_config(): array {
    $defaultFees = [50, 90, 120, 140, 160, 180];
    $defaultTimes = [45, 50, 55, 60, 60, 60];

    $fees = json_decode((string)site_setting('delivery_fee_schedule', ''), true);
    $times = json_decode((string)site_setting('delivery_time_schedule', ''), true);

    if (!is_array($fees) || !$fees) {
        $fees = $defaultFees;
    }
    if (!is_array($times) || !$times) {
        $times = $defaultTimes;
    }

    $martMinutes = (int)site_setting('delivery_mart_minutes', '15');
    $timeMin = (int)site_setting('delivery_time_min', '45');
    $timeMax = (int)site_setting('delivery_time_max', '60');

    return [
        'fee_schedule' => array_map('intval', array_values($fees)),
        'time_schedule' => array_map('intval', array_values($times)),
        'mart_minutes' => max(1, $martMinutes),
        'time_min' => max(1, $timeMin),
        'time_max' => max(1, $timeMax),
    ];
}

/**
 * Returns the delivery fee for an order serving `$shops` vendors.
 * Uses the schedule when available, otherwise continues the last increment.
 */
function lyaideu_delivery_fee(int $shops): int {
    $cfg = lyaideu_delivery_config();
    $fee = $cfg['fee_schedule'];
    $n = max(1, $shops);
    if ($n <= count($fee)) {
        return (int)$fee[$n - 1];
    }
    $last = (int)end($fee);
    $prev = (int)$fee[count($fee) - 2];
    $delta = $last - $prev;
    return max(0, $last + ($n - count($fee)) * $delta);
}

/**
 * Returns the estimated delivery minutes for an order serving `$shops` vendors.
 * Mart-only orders (no hotel/food items) use a shorter flat time (`mart_minutes`).
 * Orders that include hotel/food items always take at least `time_min` minutes
 * and never more than `time_max`, with extra vendors adding prep time via the schedule.
 */
function lyaideu_delivery_eta(int $shops, bool $hasHotel = true): int {
    $cfg = lyaideu_delivery_config();
    if (!$hasHotel) {
        return max(1, (int)$cfg['mart_minutes']);
    }
    $time = $cfg['time_schedule'];
    $n = max(1, $shops);
    if ($n <= count($time)) {
        $eta = (int)$time[$n - 1];
    } else {
        $last = (int)end($time);
        $prev = (int)$time[count($time) - 2];
        $delta = $last - $prev;
        $eta = max(0, $last + ($n - count($time)) * $delta);
    }
    return max((int)$cfg['time_min'], min((int)$cfg['time_max'], $eta));
}

function lyaideu_ensure_delivery_tables(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS vendors (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(255) NOT NULL DEFAULT \'\',
                phone VARCHAR(20) NOT NULL DEFAULT \'\',
                pass VARCHAR(255) NOT NULL DEFAULT \'\',
                scope VARCHAR(20) NOT NULL DEFAULT \'hotel\',
                hotel_id INT UNSIGNED NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vendor_phone (phone),
                UNIQUE KEY uq_vendor_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS riders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(255) NOT NULL DEFAULT \'\',
                phone VARCHAR(20) NOT NULL DEFAULT \'\',
                pass VARCHAR(255) NOT NULL DEFAULT \'\',
                vehicle VARCHAR(80) NOT NULL DEFAULT \'\',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rider_phone (phone),
                UNIQUE KEY uq_rider_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $cols = array_column($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(), 'Field');
        foreach ([
            'vendor_id INT UNSIGNED NULL DEFAULT NULL',
            'rider_id INT UNSIGNED NULL DEFAULT NULL',
            'eta_minutes INT UNSIGNED NULL DEFAULT NULL',
        ] as $def) {
            $field = substr($def, 0, strpos($def, ' '));
            if (!in_array($field, $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN $def");
            }
        }

        lyaideu_ensure_column($pdo, 'order_items', 'vendor_id', 'INT UNSIGNED NULL DEFAULT NULL');

        $changed = false;
        $changed = lyaideu_ensure_column($pdo, 'vendors', 'scope', "VARCHAR(20) NOT NULL DEFAULT 'hotel'") || $changed;
        $changed = lyaideu_ensure_column($pdo, 'vendors', 'hotel_id', 'INT UNSIGNED NULL DEFAULT NULL') || $changed;
        $dishColAdded = lyaideu_ensure_column($pdo, 'dishes', 'vendor_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $martColAdded = lyaideu_ensure_column($pdo, 'mart_items', 'vendor_id', 'INT UNSIGNED NULL DEFAULT NULL');

        // A mart-scope vendor is required for mart orders to route anywhere.
        static $defaultVendorPass = null;
        if ($defaultVendorPass === null) {
            $defaultVendorPass = password_hash('vendor123', PASSWORD_DEFAULT);
        }
        $martVendorId = (int)$pdo->query("SELECT id FROM vendors WHERE scope = 'mart' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($martVendorId > 0) {
            $pdo->prepare("UPDATE vendors SET pass = ? WHERE id = ? AND pass = ''")->execute([$defaultVendorPass, $martVendorId]);
        } else {
            $pdo->prepare(
                'INSERT INTO vendors (name, email, phone, pass, scope, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?)'
            )->execute(['LyaiDeu Mart', 'mart@lyaideu.local', '9000000000', $defaultVendorPass, 'mart', date('Y-m-d H:i:s')]);
        }

        // Every hotel gets a vendor account so its dishes always reach a vendor
        // the moment a customer orders them.
        $linkedHotelIds = [];
        $vendorByName = [];
        foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'hotel'") as $vr) {
            if (!empty($vr['hotel_id'])) {
                $linkedHotelIds[(int)$vr['hotel_id']] = (int)$vr['id'];
            }
            $vendorByName[lyaideu_normalize_name((string)$vr['name'])] = (int)$vr['id'];
        }
        $insHotelVendor = $pdo->prepare(
            'INSERT INTO vendors (name, email, phone, pass, scope, hotel_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        lyaideu_ensure_column($pdo, 'hotels', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'hotel'");
        foreach ($pdo->query("SELECT id, name FROM hotels WHERE kind = 'hotel' ORDER BY id") as $h) {
            $hid = (int)$h['id'];
            if (isset($linkedHotelIds[$hid])) {
                continue;
            }
            $hn = lyaideu_normalize_name((string)$h['name']);
            $existingVendorId = ($hn !== '' && isset($vendorByName[$hn])) ? (int)$vendorByName[$hn] : 0;
            if ($existingVendorId > 0) {
                $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ?')->execute([$hid, $existingVendorId]);
                continue;
            }
            $insHotelVendor->execute([
                (string)$h['name'],
                'vendor' . $hid . '@lyaideu.local',
                '9' . str_pad((string)$hid, 9, '0', STR_PAD_LEFT),
                $defaultVendorPass,
                'hotel',
                $hid,
                date('Y-m-d H:i:s'),
            ]);
        }

        // Every mart store also gets a vendor account (same rule as hotels)
        // so its products always reach a vendor the moment a customer orders.
        $linkedMartHotelIds = [];
        $martVendorByName = [];
        foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'mart'") as $mv) {
            if (!empty($mv['hotel_id'])) {
                $linkedMartHotelIds[(int)$mv['hotel_id']] = (int)$mv['id'];
            }
            $martVendorByName[lyaideu_normalize_name((string)$mv['name'])] = (int)$mv['id'];
        }
        $insMartVendor = $pdo->prepare(
            'INSERT INTO vendors (name, email, phone, pass, scope, hotel_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        foreach ($pdo->query("SELECT id, name FROM hotels WHERE kind = 'mart' ORDER BY id") as $ms) {
            $mid = (int)$ms['id'];
            if (isset($linkedMartHotelIds[$mid])) {
                continue;
            }
            $mn = lyaideu_normalize_name((string)$ms['name']);
            $existingVendorId = ($mn !== '' && isset($martVendorByName[$mn])) ? (int)$martVendorByName[$mn] : 0;
            if ($existingVendorId > 0) {
                $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ?')->execute([$mid, $existingVendorId]);
                continue;
            }
            $insMartVendor->execute([
                (string)$ms['name'],
                'martvendor' . $mid . '@lyaideu.local',
                '9' . str_pad((string)$mid, 9, '0', STR_PAD_LEFT),
                $defaultVendorPass,
                'mart',
                $mid,
                date('Y-m-d H:i:s'),
            ]);
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id INT UNSIGNED NULL DEFAULT NULL,
                recipient_type VARCHAR(10) NOT NULL,
                recipient_id INT UNSIGNED NOT NULL DEFAULT 0,
                message VARCHAR(255) NOT NULL,
                link VARCHAR(255) NOT NULL DEFAULT \'\',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_notif_target (recipient_type, recipient_id, is_read),
                KEY idx_notif_target_time (recipient_type, recipient_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_vendor_status (
                order_id INT UNSIGNED NOT NULL,
                vendor_id INT UNSIGNED NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT \'Pending\',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (order_id, vendor_id),
                KEY idx_ovs_vendor (vendor_id, status),
                KEY idx_ovs_vendor_time (vendor_id, status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Backfill per-vendor status rows for active orders that predate this table.
        $activeOrders = $pdo->query('SELECT id FROM orders WHERE status IN ("Pending","Accepted","Preparing","Ready for pickup")')->fetchAll(PDO::FETCH_COLUMN);
        $getVendors = $pdo->prepare('SELECT vendor_id FROM order_vendor_status WHERE order_id = ?');
        $getStatus = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $insOv = $pdo->prepare('INSERT IGNORE INTO order_vendor_status (order_id, vendor_id, status, updated_at) VALUES (?, ?, ?, ?)');
        foreach ($activeOrders as $oid) {
            $oid = (int)$oid;
            if ($oid <= 0) {
                continue;
            }
            $getVendors->execute([$oid]);
            $have = array_map('intval', array_column($getVendors->fetchAll(), 'vendor_id'));
            $getStatus->execute([$oid]);
            $cur = (string)$getStatus->fetchColumn();
            if (!in_array($cur, ['Pending', 'Accepted', 'Preparing', 'Ready for pickup'], true)) {
                $cur = 'Pending';
            }
            foreach (lyaideu_order_vendor_ids($oid) as $vid) {
                if (!in_array($vid, $have, true)) {
                    $insOv->execute([$oid, $vid, $cur, date('Y-m-d H:i:s')]);
                }
            }
        }

        lyaideu_reindex_item_vendors();

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Writes a notification row for one recipient. Failures are silently ignored so
 * a notification problem never breaks the checkout/status flow.
 */
function lyaideu_notify(int $orderId, string $recipientType, int $recipientId, string $message, string $link = ''): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || !in_array($recipientType, ['user', 'vendor', 'rider'], true) || $recipientId <= 0) {
        return;
    }
    try {
        $pdo->prepare(
            'INSERT INTO notifications (order_id, recipient_type, recipient_id, message, link, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute([
            $orderId,
            $recipientType,
            $recipientId,
            mb_substr($message, 0, 255),
            $link,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Notifies every active rider (broadcast). Used for new orders and ready-to-accept.
 */
function lyaideu_notify_riders(int $orderId, string $message, string $link = ''): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        foreach ($pdo->query('SELECT id FROM riders WHERE is_active = 1') as $r) {
            lyaideu_notify($orderId, 'rider', (int)$r['id'], $message, $link);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Distinct vendor ids that own an order (via orders.vendor_id or order_items.vendor_id).
 */
function lyaideu_order_vendor_ids(int $orderId): array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return [];
    }
    $ids = [];
    try {
        $st = $pdo->prepare('SELECT vendor_id FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $vid = (int)$st->fetchColumn();
        if ($vid > 0) {
            $ids[$vid] = true;
        }
        $st = $pdo->prepare('SELECT vendor_id FROM order_items WHERE order_id = ?');
        $st->execute([$orderId]);
        foreach ($st->fetchAll() as $row) {
            $iv = (int)$row['vendor_id'];
            if ($iv > 0) {
                $ids[$iv] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return array_keys($ids);
}

/**
 * Seeds one 'Pending' status row per owning vendor for a new order. Safe to
 * call more than once (existing rows are left untouched).
 */
function lyaideu_seed_order_vendor_status(int $orderId): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return;
    }
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO order_vendor_status (order_id, vendor_id, status, updated_at)
             VALUES (?, ?, ?, ?)'
        );
        foreach (lyaideu_order_vendor_ids($orderId) as $vid) {
            $ins->execute([$orderId, $vid, 'Pending', date('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Recomputes the aggregate orders.status from every vendor's per-vendor
 * status row. A vendor who rejected is excluded; if none remain the order is
 * cancelled. Returns the new status, or the current value when the order has
 * already moved past the vendor stage.
 */
function lyaideu_recompute_order_status(int $orderId): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return 'Pending';
    }
    try {
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $current = (string)$st->fetchColumn();
        if (!in_array($current, ['Pending', 'Accepted', 'Preparing', 'Ready for pickup'], true)) {
            return $current;
        }
        $st = $pdo->prepare('SELECT status FROM order_vendor_status WHERE order_id = ?');
        $st->execute([$orderId]);
        $rank = ['Pending' => 0, 'Accepted' => 1, 'Preparing' => 2, 'Ready for pickup' => 3];
        $active = [];
        foreach ($st->fetchAll() as $row) {
            $s = (string)$row['status'];
            if (isset($rank[$s])) {
                $active[] = $rank[$s];
            }
        }
        if (!$active) {
            $new = 'Cancelled';
        } else {
            $new = array_search(min($active), $rank, true);
        }
        $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$new, date('Y-m-d H:i:s'), $orderId]);
        return $new;
    } catch (Throwable $e) {
        return 'Pending';
    }
}

/**
 * Relative human time for the order tracker ("just now", "2 min ago", ...).
 */
function lyaideu_reltime(string $datetime): string {
    $ts = strtotime($datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int)round($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return (int)round($diff / 3600) . ' hr ago';
    }
    return date('M j', $ts);
}

/**
 * Full order-tracking view for one order: per-vendor statuses and their items
 * (each product shows its owning vendor and that vendor's progression), rider
 * info and delivery details. Used by orders.php, order_success.php and
 * api/orders.php so every surface renders the same data.
 */
function lyaideu_order_tracking(int $orderId): array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT o.id, o.status, o.created_at, o.updated_at, o.total, o.subtotal, o.delivery_fee,
                    o.discount, o.eta_minutes, o.payment, o.address, o.delivery_lat, o.delivery_lng,
                    o.rider_id, r.name AS rider_name, r.phone AS rider_phone
             FROM orders o
             LEFT JOIN riders r ON r.id = o.rider_id
             WHERE o.id = ? LIMIT 1'
        );
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) {
            return [];
        }
        $o['id'] = (int)$o['id'];
        $o['total'] = (int)$o['total'];
        $o['subtotal'] = (int)$o['subtotal'];
        $o['delivery_fee'] = (int)$o['delivery_fee'];
        $o['discount'] = (int)$o['discount'];
        $o['eta_minutes'] = (int)$o['eta_minutes'];
        $o['rider'] = ($o['rider_id'] !== null && $o['rider_id'] !== '')
            ? ['name' => (string)$o['rider_name'], 'phone' => (string)$o['rider_phone']]
            : null;
        unset($o['rider_id'], $o['rider_name'], $o['rider_phone']);

        $vendors = [];
        $vs = $pdo->prepare(
            'SELECT ovs.vendor_id, ovs.status, ovs.updated_at, v.name
             FROM order_vendor_status ovs
             LEFT JOIN vendors v ON v.id = ovs.vendor_id
             WHERE ovs.order_id = ?'
        );
        $vs->execute([$orderId]);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vid = (int)$row['vendor_id'];
            $vendors[$vid] = [
                'vendor_id' => $vid,
                'name' => (string)$row['name'],
                'status' => (string)$row['status'],
                'updated_at' => (string)$row['updated_at'],
                'items' => [],
            ];
        }

        $items = $pdo->prepare('SELECT name, price, qty, line_total, vendor_id FROM order_items WHERE order_id = ? ORDER BY id');
        $items->execute([$orderId]);
        $other = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $it['price'] = (int)$it['price'];
            $it['qty'] = (int)$it['qty'];
            $it['line_total'] = (int)$it['line_total'];
            $vid = (int)$it['vendor_id'];
            if ($vid > 0 && isset($vendors[$vid])) {
                $vendors[$vid]['items'][] = $it;
            } else {
                $other[] = $it;
            }
        }

        $rank = ['Pending' => 0, 'Accepted' => 1, 'Preparing' => 2, 'Ready for pickup' => 3, 'Rejected' => 4];
        uasort($vendors, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['status']] ?? 9;
            $rb = $rank[$b['status']] ?? 9;
            return ($ra !== $rb) ? ($ra <=> $rb) : ($a['vendor_id'] <=> $b['vendor_id']);
        });

        $o['vendors'] = array_values($vendors);
        $o['other_items'] = $other;
        return $o;
    } catch (Throwable $e) {
        return [];
    }
}

function lyaideu_order_pill_class(string $status): string {
    return [
        'Pending' => 'pending',
        'Confirmed' => 'confirmed',
        'Accepted' => 'confirmed',
        'Preparing' => 'preparing',
        'Ready for pickup' => 'ready',
        'Out for delivery' => 'delivery',
        'Delivered' => 'delivered',
        'Cancelled' => 'cancelled',
    ][$status] ?? 'pending';
}

function lyaideu_order_track_html(string $status): string {
    if ($status === 'Cancelled') {
        return '<div class="order-track cancelled"><div class="track-step cancelled"><i class="fa-solid fa-ban"></i><span>Order cancelled</span></div></div>';
    }
    $cur = ['Pending' => 0, 'Accepted' => 1, 'Preparing' => 1, 'Ready for pickup' => 2, 'Out for delivery' => 3, 'Delivered' => 4][$status] ?? 0;
    $steps = [
        ['Placed', 'fa-circle-check'],
        ['Preparing', 'fa-utensils'],
        ['Ready', 'fa-box-open'],
        ['On the way', 'fa-motorcycle'],
        ['Delivered', 'fa-house-circle-check'],
    ];
    $h = '<div class="order-track">';
    foreach ($steps as $i => $s) {
        $cls = $i < $cur ? 'done' : ($i === $cur ? 'active' : '');
        $short = ($s[0] === 'On the way') ? ' data-short="OTW"' : '';
        $h .= '<div class="track-step ' . $cls . '"' . $short . '><i class="fa-solid ' . $s[1] . '"></i><span>' . $s[0] . '</span></div>';
    }
    return $h . '</div>';
}

function lyaideu_order_vendor_icon(string $name): string {
    return (stripos($name, 'mart') !== false || stripos($name, 'store') !== false) ? 'fa-basket-shopping' : 'fa-store';
}

function lyaideu_order_vendor_progress_html(string $status): string {
    $steps = ['Waiting', 'Accepted', 'Preparing', 'Ready'];
    $cur = ['Pending' => 0, 'Accepted' => 1, 'Preparing' => 2, 'Ready for pickup' => 3][$status] ?? -1;
    $h = '<div class="vp-progress">';
    foreach ($steps as $i => $s) {
        $cls = $status === 'Rejected' ? 'cancelled' : ($i < $cur ? 'done' : ($i === $cur ? 'active' : ''));
        $h .= '<span class="vp-step ' . $cls . '">' . $s . '</span>';
    }
    return $h . '</div>';
}

function lyaideu_order_vendor_html(array $v): string {
    $ico = lyaideu_order_vendor_icon($v['name']);
    $h = '<div class="order-vendor-row">'
        . '<div class="vendor-row-head">'
        . '<span class="vendor-ico"><i class="fa-solid ' . $ico . '"></i></span>'
        . '<strong class="vendor-name">' . htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') . '</strong>'
        . '<span class="order-status-pill status-' . lyaideu_order_pill_class($v['status']) . '">' . htmlspecialchars($v['status'], ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="vendor-updated">updated ' . lyaideu_reltime($v['updated_at']) . '</span>'
        . '</div><div class="vendor-products">';
    foreach ($v['items'] as $it) {
        $h .= '<div class="vendor-product"><div class="vp-main">'
            . '<span class="vp-vendor"><i class="fa-solid ' . $ico . '"></i> ' . htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="vp-name">' . htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') . ' × ' . (int)$it['qty'] . '</span>'
            . '<span class="vp-line">Rs. ' . (int)$it['line_total'] . '</span>'
            . '</div>' . lyaideu_order_vendor_progress_html($v['status']) . '</div>';
    }
    return $h . '</div></div>';
}

function lyaideu_order_other_html(array $items): string {
    $h = '<div class="order-vendor-row other"><div class="vendor-row-head"><strong class="vendor-name">Other items</strong><span class="order-status-pill status-cancelled">Not fulfilled</span></div><div class="vendor-products">';
    foreach ($items as $it) {
        $h .= '<div class="vendor-product"><div class="vp-main"><span class="vp-name">' . htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') . ' × ' . (int)$it['qty'] . '</span><span class="vp-line">Rs. ' . (int)$it['line_total'] . '</span></div></div>';
    }
    return $h . '</div></div>';
}

function lyaideu_order_delivery_html(array $o): string {
    $status = $o['status'];
    if ($status === 'Cancelled') {
        return '<div class="order-delivery cancelled"><i class="fa-solid fa-circle-xmark"></i> This order was cancelled.</div>';
    }
    if ($status === 'Delivered') {
        return '<div class="order-delivery done"><i class="fa-solid fa-circle-check"></i> Delivered' . (!empty($o['rider']['name']) ? ' by ' . htmlspecialchars($o['rider']['name'], ENT_QUOTES, 'UTF-8') : '') . '.</div>';
    }
    if ($status === 'Out for delivery') {
        return '<div class="order-delivery onway"><i class="fa-solid fa-motorcycle"></i> ' . (!empty($o['rider']['name']) ? htmlspecialchars($o['rider']['name'], ENT_QUOTES, 'UTF-8') : 'Your rider') . ' is delivering your order — it\'s on the way!</div>';
    }
    if ($status === 'Ready for pickup') {
        return '<div class="order-delivery waiting"><span class="pulse-dot"></span> Waiting for a delivery partner… a rider will pick up your order soon.</div>';
    }
    return '<div class="order-delivery"><i class="fa-solid fa-hourglass-half"></i> Vendors are preparing your order.</div>';
}

/**
 * Adds a column to a table if it does not already exist.
 * Returns true when the column was newly added.
 */
function lyaideu_ensure_column(PDO $pdo, string $table, string $column, string $definition): bool {
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
        if (!in_array($column, $cols, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/**
 * Re-syncs product -> vendor links: dishes are owned by the vendor assigned to
 * their hotel, mart items are owned by the mart-scope vendor. Also links vendors
 * to hotels by matching names (one-time migration for pre-existing accounts).
 */
function lyaideu_reindex_item_vendors(): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        // Link hotel-scope vendors to their hotel by matching name.
        $vendors = $pdo->query("SELECT id, name FROM vendors WHERE scope = 'hotel' AND hotel_id IS NULL")->fetchAll();
        $hotels = $pdo->query('SELECT id, name FROM hotels')->fetchAll();
        foreach ($vendors as $v) {
            $vn = lyaideu_normalize_name((string)$v['name']);
            if ($vn === '') {
                continue;
            }
            $hit = 0;
            $score = 0;
            foreach ($hotels as $h) {
                $hn = lyaideu_normalize_name((string)$h['name']);
                if ($hn === '') {
                    continue;
                }
                if ($hn === $vn) {
                    $hit = (int)$h['id'];
                    $score = 100;
                    break;
                }
                if ($score < 10 && (strpos($hn, $vn) !== false || strpos($vn, $hn) !== false)) {
                    $hit = (int)$h['id'];
                    $score = 10;
                }
            }
            if ($hit > 0) {
                $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ?')->execute([$hit, (int)$v['id']]);
            }
        }

        // Resolve each dish's vendor via its hotel (handles encoded names too).
        $hotelVendors = [];
        foreach ($pdo->query(
            "SELECT h.name AS hname, v.id AS vid
             FROM hotels h
             JOIN vendors v ON v.hotel_id = h.id AND v.scope = 'hotel' AND v.is_active = 1"
        ) as $row) {
            $hotelVendors[lyaideu_normalize_name((string)$row['hname'])] = (int)$row['vid'];
        }
        $vendorNames = [];
        foreach ($pdo->query("SELECT id, name FROM vendors WHERE scope = 'hotel' AND is_active = 1") as $v) {
            $vendorNames[lyaideu_normalize_name((string)$v['name'])] = (int)$v['id'];
        }
        foreach ($pdo->query('SELECT id, hotel FROM dishes') as $d) {
            $hn = lyaideu_normalize_name((string)$d['hotel']);
            $vid = $hn !== '' ? (int)($hotelVendors[$hn] ?? 0) : 0;
            if ($vid === 0 && $hn !== '') {
                foreach ($vendorNames as $key => $mapVid) {
                    if (strpos($key, $hn) !== false || strpos($hn, $key) !== false) {
                        $vid = $mapVid;
                        break;
                    }
                }
            }
            $pdo->prepare('UPDATE dishes SET vendor_id = ? WHERE id = ?')->execute([$vid > 0 ? $vid : null, (int)$d['id']]);
        }

        // Mart items belong to the mart-scope vendor. Only assign items that
        // have no owner yet so per-vendor ownership is never overwritten.
        $martVendor = (int)$pdo->query("SELECT id FROM vendors WHERE scope = 'mart' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($martVendor > 0) {
            $pdo->exec('UPDATE mart_items SET vendor_id = ' . $martVendor . ' WHERE vendor_id IS NULL');
        }

        // Backfill order items so pre-existing orders route to vendors too.
        $pdo->exec(
            'UPDATE order_items oi
             JOIN dishes d ON d.id = oi.dish_id
             SET oi.vendor_id = d.vendor_id
             WHERE oi.vendor_id IS NULL AND oi.dish_id IS NOT NULL'
        );
        if ($martVendor > 0) {
            $pdo->exec('UPDATE order_items SET vendor_id = ' . $martVendor . ' WHERE vendor_id IS NULL AND dish_id IS NULL');
        }
    } catch (Throwable $e) {
        // Best-effort migration; never break the page because of it.
    }
}

/**
 * Sets a dish's owning vendor based on the hotel the dish belongs to.
 * Returns the resolved vendor id (0 when none).
 */
/**
 * Normalizes a hotel/vendor name for fuzzy matching: decodes HTML entities,
 * lowercases and strips everything that is not a letter or digit.
 */
function lyaideu_normalize_name(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    return (string)preg_replace('/[^a-z0-9]+/u', '', $s);
}

function lyaideu_resolve_dish_vendor(int $dishId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $dishId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT hotel FROM dishes WHERE id = ?');
        $st->execute([$dishId]);
        $hotel = (string)$st->fetchColumn();
        $vendorId = 0;
        if ($hotel !== '') {
            $st = $pdo->prepare(
                "SELECT v.id FROM vendors v
                 JOIN hotels h ON h.id = v.hotel_id
                 WHERE v.scope = 'hotel' AND v.is_active = 1 AND h.name = :h
                 ORDER BY v.id LIMIT 1"
            );
            $st->execute([':h' => $hotel]);
            $vendorId = (int)$st->fetchColumn();
        }
        if ($vendorId === 0) {
            // Fallback: match a vendor whose name resembles the hotel name.
            $norm = lyaideu_normalize_name($hotel);
            if ($norm !== '') {
                $rows = $pdo->query("SELECT id, name FROM vendors WHERE scope = 'hotel' AND is_active = 1")->fetchAll();
                $exact = 0;
                foreach ($rows as $row) {
                    if (lyaideu_normalize_name((string)$row['name']) === $norm) {
                        $exact = (int)$row['id'];
                        break;
                    }
                }
                if ($exact === 0) {
                    foreach ($rows as $row) {
                        $vn = lyaideu_normalize_name((string)$row['name']);
                        if ($vn !== '' && (strpos($vn, $norm) !== false || strpos($norm, $vn) !== false)) {
                            $exact = (int)$row['id'];
                            break;
                        }
                    }
                }
                $vendorId = $exact;
            }
        }
        $upd = $pdo->prepare('UPDATE dishes SET vendor_id = ? WHERE id = ?');
        $upd->execute([$vendorId > 0 ? $vendorId : null, $dishId]);
        return $vendorId;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Sets a mart item's owning vendor. Prefers the item's current vendor; when
 * the item has no owner yet it falls back to the first mart-scope vendor.
 * Returns the effective vendor id (0 when none).
 */
function lyaideu_resolve_mart_vendor(int $itemId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT vendor_id FROM mart_items WHERE id = ?');
        $st->execute([$itemId]);
        $existing = (int)$st->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }
        $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'mart' AND is_active = 1 ORDER BY id LIMIT 1");
        $st->execute();
        $vendorId = (int)$st->fetchColumn();
        $upd = $pdo->prepare('UPDATE mart_items SET vendor_id = ? WHERE id = ?');
        $upd->execute([$vendorId > 0 ? $vendorId : null, $itemId]);
        return $vendorId;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolves the mart store (hotel) name that owns a mart item, via the item's
 * vendor and that vendor's linked store. Falls back to 'LyaiDeu Mart' when no
 * vendor/store link exists.
 */
function lyaideu_mart_store_name(int $itemId): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 'LyaiDeu Mart';
    }
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(h.name, \'\')
             FROM mart_items m
             LEFT JOIN vendors v ON v.id = m.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE m.id = ?'
        );
        $st->execute([$itemId]);
        $name = (string)$st->fetchColumn();
        return $name !== '' ? $name : 'LyaiDeu Mart';
    } catch (Throwable $e) {
        return 'LyaiDeu Mart';
    }
}
function lyaideu_handle_item_image(string $existingImg, array $post, ?array $file, string $prefix): string {
    $img = $existingImg;

    if (!empty($post['remove_img'])) {
        if ($img !== '' && str_starts_with($img, 'uploads/')) {
            @unlink(__DIR__ . '/' . $img);
        }
        return '';
    }

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $img;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image is too large (max 2 MB).');
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
        throw new RuntimeException('Image must be a PNG, JPG, WebP, GIF or SVG image.');
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the uploads folder.');
        }
    }

    $ext = $allowed[$mime];
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    if ($img !== '' && str_starts_with($img, 'uploads/')) {
        @unlink(__DIR__ . '/' . $img);
    }

    return 'uploads/' . $filename;
}

/**
 * Returns an error message if a phone/email is already registered to an account
 * in the other delivery role (a vendor vs a rider), so a single set of login
 * credentials can never open both dashboards. Returns null when the value is free.
 */
function lyaideu_delivery_credential_conflict(string $role, string $phone, string $email, int $excludeId = 0): ?string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    $other = $role === 'vendor' ? 'rider' : 'vendor';
    $table = $other === 'vendor' ? 'vendors' : 'riders';
    try {
        $stmt = $pdo->prepare(
            "SELECT name FROM `$table`
             WHERE (phone = :p OR (email <> '' AND email = :e)) AND id <> :id
             LIMIT 1"
        );
        $stmt->execute([
            ':p' => $phone,
            ':e' => strtolower($email),
            ':id' => $excludeId,
        ]);
        $found = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if ($found) {
        $label = $other === 'vendor' ? 'vendor' : 'rider';
        return 'This phone or email is already used by the ' . $label . ' account "' . $found['name'] . '". '
             . 'A vendor and a rider cannot share the same login. Please use a different phone/email.';
    }
    return null;
}

/**
 * Auto-assigns the vendor for an order. Food items are routed to the vendor
 * that owns the dish's hotel; a mart-only order goes to the mart-scope vendor.
 * Never falls back to "the first vendor" — if no linked vendor exists the
 * order is left unassigned for the admin to handle.
 */
function lyaideu_auto_assign_vendor(int $orderId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return 0;
    }
    try {
        $itemStmt = $pdo->prepare('SELECT dish_id, vendor_id FROM order_items WHERE order_id = ?');
        $itemStmt->execute([$orderId]);
        $rows = $itemStmt->fetchAll();

        $vendorIds = [];
        $hasFood = false;
        $hasMart = false;

        foreach ($rows as $row) {
            if ((int)$row['dish_id'] > 0) {
                $hasFood = true;
            } else {
                $hasMart = true;
            }
            $vid = (int)$row['vendor_id'];
            if ($vid > 0) {
                $vendorIds[$vid] = true;
            }
        }

        if (count($vendorIds) === 1) {
            $vendorId = (int)array_keys($vendorIds)[0];
            $pdo->prepare('UPDATE orders SET vendor_id = ? WHERE id = ?')->execute([$vendorId, $orderId]);
            return $vendorId;
        }

        if (count($vendorIds) > 1) {
            // Multi-vendor order: route per item via order_items.vendor_id.
            $pdo->prepare('UPDATE orders SET vendor_id = NULL WHERE id = ?')->execute([$orderId]);
            return 0;
        }

        if (!$hasFood && $hasMart) {
            $martVendor = (int)$pdo->query(
                "SELECT id FROM vendors WHERE scope = 'mart' AND is_active = 1 ORDER BY id LIMIT 1"
            )->fetchColumn();
            if ($martVendor > 0) {
                $pdo->prepare('UPDATE orders SET vendor_id = ? WHERE id = ?')->execute([$martVendor, $orderId]);
                return $martVendor;
            }
        }

        return 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Auto-assigns the least-busy active rider to an order (fewest open
 * deliveries), so work spreads across all riders instead of one.
 */
function lyaideu_auto_assign_rider(int $orderId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT rider_id FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        if ((int)$st->fetchColumn() > 0) {
            return 0;
        }

        $riderId = (int)$pdo->query(
            "SELECT r.id
             FROM riders r
             LEFT JOIN orders o ON o.rider_id = r.id
                 AND o.status IN ('Pending','Accepted','Preparing','Ready for pickup','Out for delivery')
             WHERE r.is_active = 1
             GROUP BY r.id
             ORDER BY COUNT(o.id) ASC, r.id ASC
             LIMIT 1"
        )->fetchColumn();

        if ($riderId > 0) {
            $pdo->prepare('UPDATE orders SET rider_id = ? WHERE id = ?')->execute([$riderId, $orderId]);
        }
        return $riderId;
    } catch (Throwable $e) {
        return 0;
    }
}

function lyaideu_ensure_messages_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(20) NOT NULL DEFAULT \'\',
                subject VARCHAR(255) NOT NULL DEFAULT \'\',
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'unread\',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_messages_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function site_logo_url(): string {
    return site_setting('site_logo', 'logo.png');
}

function lyaideu_base_url(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $docRoot = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: (string)($_SERVER['DOCUMENT_ROOT'] ?? '/'));
    $dir = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
    $rel = ltrim(str_replace('\\', '/', substr($dir, strlen($docRoot))), '/');
    $base = '/' . ($rel !== '' ? $rel . '/' : '');
    return $base;
}

function lyaideu_base_tag(): string {
    return '<base href="' . htmlspecialchars(lyaideu_base_url(), ENT_QUOTES, 'UTF-8') . '">';
}


function site_favicon_url(): string {
    return site_setting('site_favicon', 'favicon.ico');
}

function site_apple_icon_url(): string {
    return site_setting('site_apple_icon', 'apple-touch-icon.png');
}

function site_hero_slides(): array {
    $defaults = [
        'uploads/mart_img_20260812_131222_24f22173.jpg',
        'uploads/mart_img_20260812_131222_4639f9bb.jpg',
        'uploads/hotel_logo_20260812_104849_51266950.jpg',
        'uploads/mart_img_20260812_131222_31e223f3.webp',
    ];
    $slides = [];
    for ($i = 1; $i <= 4; $i++) {
        $slide = site_setting('hero_slide_' . $i, '');
        if ($slide !== '') {
            $slides[] = $slide;
        }
    }
    return $slides ?: $defaults;
}

function site_icon_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'ico':
            return 'image/x-icon';
        case 'svg':
            return 'image/svg+xml';
        case 'webp':
            return 'image/webp';
        case 'gif':
            return 'image/gif';
        case 'jpeg':
        case 'jpg':
            return 'image/jpeg';
        default:
            return 'image/png';
    }
}

function site_head_icons(): string {
    $fav = htmlspecialchars(site_favicon_url(), ENT_QUOTES, 'UTF-8');
    $apple = htmlspecialchars(site_apple_icon_url(), ENT_QUOTES, 'UTF-8');
    $favType = site_icon_type(site_favicon_url());
    return '<link rel="icon" type="' . $favType . '" href="' . $fav . '">'
        . "\n" . '<link rel="apple-touch-icon" href="' . $apple . '">';
}

/**
 * Idempotent KYC/profile schema: adds profile + verification columns to `users`
 * and creates the `user_documents` table for uploaded ID documents.
 */
function lyaideu_ensure_kyc_tables(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        lyaideu_ensure_column($pdo, 'users', 'avatar', "VARCHAR(500) NOT NULL DEFAULT ''");
        lyaideu_ensure_column($pdo, 'users', 'address', "VARCHAR(500) NOT NULL DEFAULT ''");
        lyaideu_ensure_column($pdo, 'users', 'kyc_status', "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'");
        lyaideu_ensure_column($pdo, 'users', 'kyc_reason', "VARCHAR(500) NOT NULL DEFAULT ''");
        lyaideu_ensure_column($pdo, 'users', 'kyc_submitted_at', 'DATETIME NULL DEFAULT NULL');
        lyaideu_ensure_column($pdo, 'users', 'kyc_reviewed_at', 'DATETIME NULL DEFAULT NULL');
        lyaideu_ensure_column($pdo, 'users', 'kyc_reviewer', "VARCHAR(150) NOT NULL DEFAULT ''");

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                doc_type VARCHAR(50) NOT NULL DEFAULT \'\',
                file VARCHAR(500) NOT NULL DEFAULT \'\',
                uploaded_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_udocs_user (user_id),
                CONSTRAINT fk_udocs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensures the location columns used for home pins and order delivery pins.
 */
function lyaideu_ensure_location_columns(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        lyaideu_ensure_column($pdo, 'users', 'home_lat', 'DECIMAL(10,7) NULL DEFAULT NULL');
        lyaideu_ensure_column($pdo, 'users', 'home_lng', 'DECIMAL(10,7) NULL DEFAULT NULL');
        lyaideu_ensure_column($pdo, 'users', 'home_address', "VARCHAR(500) NOT NULL DEFAULT ''");
        lyaideu_ensure_column($pdo, 'orders', 'delivery_lat', 'DECIMAL(10,7) NULL DEFAULT NULL');
        lyaideu_ensure_column($pdo, 'orders', 'delivery_lng', 'DECIMAL(10,7) NULL DEFAULT NULL');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validates a latitude/longitude value. Returns true when the value is a
 * finite number within the expected geographic range.
 */
function lyaideu_valid_coord($v, bool $isLat): bool {
    if ($v === null || $v === '') {
        return false;
    }
    $n = (float)$v;
    if (!is_finite($n)) {
        return false;
    }
    return $isLat ? ($n >= -90 && $n <= 90) : ($n >= -180 && $n <= 180);
}

/**
 * Returns a user's full profile including KYC and home-location fields, or
 * null when missing.
 */
function lyaideu_user_profile(int $userId): ?array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $userId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, name, email, phone, dob, avatar, address,
                    home_lat, home_lng, home_address,
                    kyc_status, kyc_reason, kyc_submitted_at, kyc_reviewed_at, kyc_reviewer
             FROM users WHERE id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Uploads a KYC identity document (image or PDF) into uploads/kyc.
 * Returns the stored path, or '' when no file was provided.
 */
function lyaideu_handle_kyc_document(string $existing, ?array $file, string $prefix): string {
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Document upload failed. Please try again.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Document is too large (max 5 MB).');
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Documents must be PNG, JPG, WebP, GIF or PDF files.');
    }

    $uploadDir = __DIR__ . '/uploads/kyc';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create the uploads folder.');
        }
    }

    $ext = $allowed[$mime];
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        throw new RuntimeException('Could not save the uploaded document.');
    }

    if ($existing !== '' && str_starts_with($existing, 'uploads/kyc/')) {
        @unlink(__DIR__ . '/' . $existing);
    }

    return 'uploads/kyc/' . $filename;
}

/**
 * Deletes a stored KYC document file from disk (best effort).
 */
function lyaideu_delete_upload(string $path): void {
    if ($path !== '' && str_starts_with($path, 'uploads/') && file_exists(__DIR__ . '/' . $path)) {
        @unlink(__DIR__ . '/' . $path);
    }
}
