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

/* Control Panel: global KYC gate. ON = only users with an APPROVED KYC can
   place orders (original behaviour, the default). OFF = anyone can order.
   Stored in the settings table under the key `kyc_required`. */
function lyaideu_kyc_required(): bool {
    return site_setting('kyc_required', '1') === '1';
}

function lyaideu_set_kyc_required(bool $required): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    lyaideu_ensure_settings_table();
    $st = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (:skey, :sval)
                         ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    $st->execute([':skey' => 'kyc_required', ':sval' => $required ? '1' : '0']);
    lyaideu_settings_clear();
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
    static $done = false;
    if ($done) {
        return true;
    }
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
                    discount_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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
        $done = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensures the `other_items` table exists (products sold on the Others page:
 * flowers, candles, achar, gifts, etc.) and seeds a small default catalog.
 * Idempotent — safe to call on every request.
 */
function lyaideu_ensure_other_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (!empty($GLOBALS['__lyaideu_other_table_ready'])) {
        return true;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'other_items'");
        $exists = $stmt && (bool)$stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec(
                'CREATE TABLE other_items (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    cat VARCHAR(50) NOT NULL,
                    category_id INT UNSIGNED NULL,
                    name_slug VARCHAR(120) NOT NULL DEFAULT \'\',
                    unit VARCHAR(50) NOT NULL DEFAULT \'\',
                    price INT UNSIGNED NOT NULL DEFAULT 0,
                    discount_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    tag VARCHAR(100) NOT NULL DEFAULT \'\',
                    `desc` TEXT NOT NULL,
                    img VARCHAR(500) NOT NULL DEFAULT \'\',
                    vendor_id INT UNSIGNED NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_other_items_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $seed = $pdo->prepare(
                'INSERT INTO other_items (name, cat, unit, price, tag, `desc`, img)
                 VALUES (:name, :cat, :unit, :price, :tag, :descr, :img)'
            );
            $defaults = [
                ['Rose Bouquet', 'flowers', 'bunch', 1200, 'Best Seller', 'A dozen fresh red roses, hand-wrapped in premium paper.', ''],
                ['Assorted Flower Bunch', 'flowers', 'bunch', 800, '', 'A cheerful mix of seasonal blooms, tied fresh on order.', ''],
                ['Marigold Garland', 'flowers', 'strand', 350, 'Traditional', 'Hand-strung marigold mala, perfect for puja and festivals.', ''],
                ['Scented Candle Set', 'candles', 'set', 650, '', 'Three soy-wax candles in calming lavender, vanilla and rose.', ''],
                ['Tealight Candles (Pack of 12)', 'candles', 'pack', 250, '', 'Long-burning tealights for cosy evenings and celebrations.', ''],
                ['Birthday Cake Candles', 'candles', 'pack', 120, '', 'Colourful twist candles to light up any birthday cake.', ''],
                ['Timur Achar', 'achar', 'jar', 380, 'Spicy', 'Homemade Szechuan pepper pickle with a bold, numbing kick.', ''],
                ['Mango Achar', 'achar', 'jar', 420, '', 'Tangy green-mango pickle, slow-cooked the traditional way.', ''],
                ['Gift Hamper Box', 'gifts', 'box', 1500, 'Premium', 'A curated hamper of treats, candles and a handwritten card.', ''],
                ['Celebration Gift Bag', 'gifts', 'bag', 550, '', 'A ready-to-give bag with assorted goodies inside.', ''],
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
        lyaideu_ensure_product_slugs();
        $GLOBALS['__lyaideu_other_table_ready'] = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensures the `beverage_items` table exists (products sold on the Beverages
 * page: cold drinks, alcohol, water, etc.) and seeds a small default catalog.
 * Idempotent — safe to call on every request.
 */
function lyaideu_ensure_beverage_table(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (!empty($GLOBALS['__lyaideu_beverage_table_ready'])) {
        return true;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'beverage_items'");
        $exists = $stmt && (bool)$stmt->fetchColumn();
        if (!$exists) {
            $pdo->exec(
                'CREATE TABLE beverage_items (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    cat VARCHAR(50) NOT NULL,
                    category_id INT UNSIGNED NULL,
                    name_slug VARCHAR(120) NOT NULL DEFAULT \'\',
                    unit VARCHAR(50) NOT NULL DEFAULT \'\',
                    price INT UNSIGNED NOT NULL DEFAULT 0,
                    discount_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    tag VARCHAR(100) NOT NULL DEFAULT \'\',
                    `desc` TEXT NOT NULL,
                    img VARCHAR(500) NOT NULL DEFAULT \'\',
                    vendor_id INT UNSIGNED NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_beverage_items_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $seed = $pdo->prepare(
                'INSERT INTO beverage_items (name, cat, unit, price, tag, `desc`, img)
                 VALUES (:name, :cat, :unit, :price, :tag, :descr, :img)'
            );
            $defaults = [
                ['Coca-Cola 500ml', 'cold-drinks', 'bottle', 90, '', 'Ice-cold Coca-Cola straight from the cooler.', ''],
                ['Fanta Orange 500ml', 'cold-drinks', 'bottle', 90, 'Best Seller', 'Fizzy orange Fanta, chilled and ready to enjoy.', ''],
                ['Sprite 500ml', 'cold-drinks', 'bottle', 90, '', 'Crisp, lemon-lime Sprite to beat the heat.', ''],
                ['7UP 500ml', 'cold-drinks', 'bottle', 90, '', 'Refreshing lemon 7UP, served ice cold.', ''],
                ['Fresh Mango Juice', 'cold-drinks', 'glass', 180, 'Fresh', 'Squeezed from ripe local mangoes, no added sugar.', ''],
                ['Mixed Fruit Juice', 'cold-drinks', 'glass', 190, '', 'A bright blend of seasonal fruits, made to order.', ''],
                ['Cold Coffee (Frappé)', 'cold-drinks', 'cup', 220, 'New!', 'Frosty blended coffee topped with cream.', ''],
                ['Chocolate Milkshake', 'cold-drinks', 'glass', 200, '', 'Thick and creamy chocolate shake with whipped topping.', ''],
                ['Gorkha Strong Beer 650ml', 'alcohol', 'bottle', 320, '', 'Nepal\'s favourite strong lager, chilled to perfection.', ''],
                ['Tuborg Beer 330ml', 'alcohol', 'bottle', 210, '', 'Light and crisp Danish-style lager.', ''],
                ['Khukuri Rum 750ml', 'alcohol', 'bottle', 1400, 'Premium', 'Smooth aged rum with a bold Nepali character.', ''],
                ['Old Durbar Whisky 750ml', 'alcohol', 'bottle', 1850, '', 'Rich blended whisky for evenings and celebrations.', ''],
                ['Vodka 750ml', 'alcohol', 'bottle', 1600, '', 'Clean, neutral spirit for your favourite cocktails.', ''],
                ['Bisleri Water 1L', 'water', 'bottle', 40, '', 'Purified drinking water, sealed fresh.', ''],
                ['Himalayan Spring Water 1L', 'water', 'bottle', 55, '', 'Natural spring water from the Himalayas.', ''],
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
        lyaideu_ensure_product_slugs();
        $GLOBALS['__lyaideu_beverage_table_ready'] = true;
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
                image VARCHAR(255) NOT NULL DEFAULT \'\',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cat_slug_type (slug, type),
                KEY idx_cat_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $catImageCol = $pdo->query("SHOW COLUMNS FROM categories LIKE 'image'")->fetchAll();
        if (!$catImageCol) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN image VARCHAR(255) NOT NULL DEFAULT ''");
        }

        // Control Panel toggle: 1 = live, 0 = hidden (subtree + products hide too).
        $catActiveCol = $pdo->query("SHOW COLUMNS FROM categories LIKE 'is_active'")->fetchAll();
        if (!$catActiveCol) {
            $pdo->exec('ALTER TABLE categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
        }

        lyaideu_ensure_other_table();
        lyaideu_ensure_beverage_table();

        foreach (['dishes', 'mart_items', 'other_items', 'beverage_items'] as $table) {
            $col = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'category_id'")->fetchAll();
            if (!$col) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN category_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_{$table}_category (category_id)");
            }
        }

        $GLOBALS['__lyaideu_categories_ready'] = true;

        /* Product -> category backfill runs ONLY when seeding actually added
           categories this request (first install, or a built-in type that was
           missing until now). Running it on every request would silently
           re-categorize items the admin deliberately saved as "No category". */
        $seeded = false;
        $catCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        if ($catCount === 0) {
            lyaideu_seed_categories();
            $seeded = true;
        } else {
            // Seed any category types added after the first setup (e.g. 'beverage').
            $present = [];
            foreach ($pdo->query('SELECT DISTINCT type FROM categories') as $r) {
                $present[$r['type']] = true;
            }
            foreach (['menu', 'mart', 'other', 'beverage'] as $t) {
                if (!isset($present[$t])) {
                    lyaideu_seed_categories([$t]);
                    $seeded = true;
                }
            }
        }
        if ($seeded) {
            lyaideu_assign_products_to_categories();
        }
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

function lyaideu_seed_categories(array $onlyTypes = []): void {
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
        'other' => [
            ['Bouquets & Flowers', 'flowers', 'fa-bouquet', [
                ['Fresh Bouquets', 'fresh-bouquets', 'fa-bouquet'],
                ['Garlands & Strands', 'garlands', 'fa-fan'],
            ]],
            ['Candles & Decor', 'candles', 'fa-candle-holder', [
                ['Candles', 'candles', 'fa-candle-holder'],
                ['Decor Items', 'decor', 'fa-wand-magic-sparkles'],
            ]],
            ['Achar & Pickles', 'achar', 'fa-jar', [
                ['Spicy Achar', 'spicy-achar', 'fa-pepper-hot'],
                ['Sweet Achar', 'sweet-achar', 'fa-jar'],
            ]],
            ['Gifts', 'gifts', 'fa-gift', [
                ['Gift Boxes & Hampers', 'gift-boxes', 'fa-gift'],
                ['Occasion Gifts', 'occasion-gifts', 'fa-champagne-glasses'],
            ]],
        ],
        'beverage' => [
            ['Cold Drinks', 'cold-drinks', 'fa-glass-water', [
                ['Sodas & Colas', 'sodas', 'fa-mug-saucer'],
                ['Juices & Shakes', 'juices', 'fa-glass-water'],
            ]],
            ['Alcohol', 'alcohol', 'fa-champagne-glasses', [
                ['Beer', 'beer', 'fa-champagne-glasses'],
                ['Spirits & Whisky', 'spirits', 'fa-whiskey-glass'],
            ]],
            ['Water', 'water', 'fa-faucet-drip', [
                ['Bottled Water', 'bottled-water', 'fa-faucet-drip'],
                ['Sparkling Water', 'sparkling', 'fa-glass-water'],
            ]],
        ],
    ];

    foreach ($tree as $type => $items) {
        if ($onlyTypes !== [] && !in_array($type, $onlyTypes, true)) {
            continue;
        }
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
        'other' => [
            'flowers' => [['fresh-bouquets', ['bouquet', 'bunch', 'flower']], ['garlands', ['garland', 'mala', 'strand']]],
            'candles' => [['candles', ['candle', 'tealight']], ['decor', ['decor', 'decoration', 'ornament']]],
            'achar' => [['spicy-achar', ['timur', 'spicy']], ['sweet-achar', ['mango', 'sweet', 'achar']]],
            'gifts' => [['gift-boxes', ['hamper', 'box']], ['occasion-gifts', ['gift', 'celebration', 'occasion']]],
        ],
        'beverage' => [
            'cold-drinks' => [['sodas', ['coca', 'pepsi', 'fanta', 'sprite', '7up', 'soda water', 'soft drink']], ['juices', ['juice', 'milkshake', 'shake', 'frappe', 'coffee', 'lassi']]],
            'alcohol' => [['beer', ['beer', 'lager', 'strong']], ['spirits', ['rum', 'whisky', 'vodka', 'gin']]],
            'water' => [['bottled-water', ['water', 'bisleri', 'spring']], ['sparkling', ['sparkling', 'soda water']]],
        ],
    ];

    $tables = ['dishes' => 'dishes', 'mart' => 'mart_items', 'other' => 'other_items', 'beverage' => 'beverage_items'];
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
            $cid = lyaideu_category_id_by_slug($target, $key === 'dishes' ? 'menu' : $key);
            if ($cid > 0) {
                $upd->execute([':cid' => $cid, ':id' => (int)$row['id']]);
            }
        }
    }
}

function lyaideu_categories(?string $type = null): array {
    static $cache = [];
    $cacheKey = (string)$type;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $sql = 'SELECT id, name, slug, type, parent_id, sort_order, icon, image, is_active FROM categories';
    $params = [];
    if ($type !== null && $type !== '') {
        $sql .= ' WHERE type = :type';
        $params[':type'] = $type;
    }
    $sql .= ' ORDER BY type, sort_order, name';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $cache[$cacheKey] = $rows;
    return $rows;
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
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        foreach (lyaideu_categories() as $row) {
            $byId[(int)$row['id']] = $row;
        }
    }
    if ($categoryId <= 0) {
        return [];
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

/**
 * Control Panel toggle support. A category is effectively active only when it
 * AND every ancestor are is_active=1 — turning a parent off hides the whole
 * subtree. Categories missing from the table (deleted / legacy rows) count as
 * active so unassigned products never disappear unexpectedly.
 */
function lyaideu_category_is_active(int $categoryId): bool {
    static $activeMap = null;
    if ($activeMap === null) {
        $activeMap = [];
        foreach (lyaideu_categories() as $row) {
            $activeMap[(int)$row['id']] = !empty($row['is_active']);
        }
    }
    if ($categoryId <= 0) {
        return true;
    }
    foreach (lyaideu_category_path($categoryId) as $cat) {
        $flag = $activeMap[(int)$cat['id']] ?? true;
        if (!$flag) {
            return false;
        }
    }
    return true;
}

/**
 * Public-safe category list: same shape/order as lyaideu_categories() but with
 * every category whose subtree is switched off (self or an ancestor) removed.
 */
function lyaideu_visible_categories(?string $type = null): array {
    $out = [];
    foreach (lyaideu_categories($type) as $cat) {
        if (lyaideu_category_is_active((int)$cat['id'])) {
            $out[] = $cat;
        }
    }
    return $out;
}

/**
 * Returns the stored category image (an `uploads/...` relative path) or an
 * empty string when the category has no image yet. Relative paths resolve
 * against the site <base> tag, so they work on every page.
 */
function lyaideu_category_image_url(array $cat): string {
    $img = (string)($cat['image'] ?? '');
    return $img !== '' ? $img : '';
}

/**
 * Custom storefront sections (beyond the four built-in ones). Admin-managed
 * via admin_sections.php. Idempotent — safe to call on every request.
 */
function lyaideu_ensure_sections_tables(): bool {
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS category_sections (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(40) NOT NULL,
                name VARCHAR(80) NOT NULL,
                icon VARCHAR(60) NOT NULL DEFAULT \'\',
                `desc` VARCHAR(190) NOT NULL DEFAULT \'\',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_section_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS section_item_links (
                item_type VARCHAR(10) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (item_type, item_id, category_id),
                KEY idx_sil_category (category_id),
                KEY idx_sil_item (item_type, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Rows from `category_sections`. $onlyActive=true returns live-only sections
 * in display order; results are cached per request.
 */
function lyaideu_custom_sections(bool $onlyActive = false): array {
    static $cache = [];
    $key = $onlyActive ? 'active' : 'all';
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $out = [];
    $pdo = lyaideu_load_pdo();
    if ($pdo instanceof PDO && lyaideu_ensure_sections_tables()) {
        try {
            $sql = 'SELECT id, slug, name, icon, `desc`, sort_order, is_active FROM category_sections';
            if ($onlyActive) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY sort_order, name';
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['sort_order'] = (int)$r['sort_order'];
                $r['is_active'] = (int)$r['is_active'];
            }
            unset($r);
            $out = $rows;
        } catch (Throwable $e) {
            $out = [];
        }
    }
    $cache[$key] = $out;
    return $out;
}

/**
 * Single source of truth for the section groups shown on the Categories page.
 * The four built-in sections keep their exact legacy configuration; custom
 * sections are appended in admin-chosen order and point at section.php.
 */
function lyaideu_section_groups(): array {
    $groups = [
        'menu'     => ['label' => 'Menu',            'icon' => 'fa-utensils',        'param' => 'cat',  'page' => 'menu',      'desc' => 'Dishes from our partner kitchens', 'pool' => 'dishes',    'custom' => false],
        'mart'     => ['label' => 'Mart',            'icon' => 'fa-basket-shopping', 'param' => 'mcat', 'page' => 'mart',      'desc' => 'Fresh groceries & daily essentials', 'pool' => 'mart',   'custom' => false],
        'other'    => ['label' => 'Other Products',  'icon' => 'fa-gift',            'param' => 'ocat', 'page' => 'others',    'desc' => 'Flowers, decor, achar & gifts', 'pool' => 'others',     'custom' => false],
        'beverage' => ['label' => 'Beverages',       'icon' => 'fa-glass-water',     'param' => 'bcat', 'page' => 'beverages', 'desc' => 'Cold drinks, water & more', 'pool' => 'beverages', 'custom' => false],
    ];
    foreach (lyaideu_custom_sections(true) as $s) {
        $groups[$s['slug']] = [
            'label'      => (string)$s['name'],
            'icon'       => (string)$s['icon'] !== '' ? (string)$s['icon'] : 'fa-layer-group',
            'param'      => 'cat',
            'page'       => 'section?s=' . rawurlencode((string)$s['slug']),
            'desc'       => (string)$s['desc'],
            'pool'       => '',
            'custom'     => true,
            'section_id' => (int)$s['id'],
        ];
    }
    return $groups;
}

/** Category types that may be assigned: built-ins + active custom slugs. */
function lyaideu_valid_category_types(): array {
    $types = ['menu', 'mart', 'other', 'beverage'];
    foreach (lyaideu_custom_sections(true) as $s) {
        $types[] = (string)$s['slug'];
    }
    return $types;
}

/** Slugs a custom section may never take (route/type collisions). */
function lyaideu_section_slug_reserved(string $slug): bool {
    $reserved = [
        'menu', 'mart', 'other', 'others', 'beverage', 'beverages', 'section', 'sections',
        'index', 'admin', 'api', 'login', 'logout', 'auth', 'orders', 'checkout', 'product',
        'profile', 'store', 'stores', 'hotels', 'hotel', 'contact', 'faq', 'terms', 'categories',
        'category', 'vendor', 'vendors', 'rider', 'riders', 'uploads', 'css', 'js', 'migrate',
    ];
    return in_array(strtolower(trim($slug)), $reserved, true);
}

/** Item-type whitelist for section links (maps 1:1 onto the product tables). */
function lyaideu_link_item_types(): array {
    return ['dish', 'mart', 'other', 'beverage'];
}

/**
 * Public-safe section links: every row whose target category belongs to an
 * ACTIVE custom section and is itself visible (Control Panel aware).
 * Shape: [ ['t' => 'dish', 'id' => 5, 'c' => 12], … ].
 */
function lyaideu_public_section_links(): array {
    lyaideu_ensure_sections_tables();
    $sections = lyaideu_custom_sections(true);
    if (!$sections) {
        return [];
    }
    $slugs = array_column($sections, 'slug');
    $visibleIds = [];
    foreach (lyaideu_visible_categories() as $c) {
        if (in_array((string)$c['type'], $slugs, true)) {
            $visibleIds[(int)$c['id']] = true;
        }
    }
    if (!$visibleIds) {
        return [];
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $out = [];
    try {
        $validTypes = lyaideu_link_item_types();
        $chunk = array_chunk(array_keys($visibleIds), 200);
        foreach ($chunk as $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT item_type, item_id, category_id FROM section_item_links WHERE category_id IN ($placeholders)");
            $rows->execute($ids);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = (string)$r['item_type'];
                if (!in_array($t, $validTypes, true) || !isset($visibleIds[(int)$r['category_id']])) {
                    continue;
                }
                $out[] = ['t' => $t, 'id' => (int)$r['item_id'], 'c' => (int)$r['category_id']];
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/** Raw link rows for one category (admin side, no visibility filtering). */
function lyaideu_links_for_category(int $categoryId): array {
    lyaideu_ensure_sections_tables();
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $categoryId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT item_type, item_id FROM section_item_links WHERE category_id = ?');
        $st->execute([$categoryId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Removes every link pointing at the given categories (cascade cleanup). */
function lyaideu_purge_category_links(array $categoryIds): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
    if (!$ids) {
        return;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $st = $pdo->prepare('DELETE FROM section_item_links WHERE category_id = ?');
        foreach ($ids as $id) {
            $st->execute([$id]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Removes every link pointing at one product (cascade cleanup). */
function lyaideu_purge_item_links(string $itemType, int $itemId): void {
    if ($itemId <= 0 || !in_array($itemType, lyaideu_link_item_types(), true)) {
        return;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $st = $pdo->prepare('DELETE FROM section_item_links WHERE item_type = ? AND item_id = ?');
        $st->execute([$itemType, $itemId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** Builds the href for a category card inside a section group. */
function lyaideu_group_category_href(array $group, string $slug): string {
    if (!empty($group['custom'])) {
        return htmlspecialchars($group['page'] . '&cat=' . rawurlencode($slug), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($group['page'] . '?' . $group['param'] . '=' . rawurlencode($slug), ENT_QUOTES, 'UTF-8');
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
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        foreach (['dishes', 'mart_items', 'other_items', 'beverage_items'] as $table) {
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
    $done = true;
}

/**
 * Returns a unique name slug for a new/renamed product in a given table.
 */
function lyaideu_sync_item_slug(string $table, int $id, string $name): void {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || !in_array($table, ['dishes', 'mart_items', 'other_items', 'beverage_items'], true) || $id <= 0) {
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
    static $done = false;
    if ($done) {
        return true;
    }
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

        $otherStore = (int)$pdo->query("SELECT COUNT(*) FROM hotels WHERE kind = 'other'")->fetchColumn();
        if ($otherStore === 0) {
            $pdo->prepare(
                "INSERT INTO hotels (name, type, phone, emoji, logo, kind) VALUES (?, ?, ?, ?, ?, 'other')"
            )->execute(['LyaiDeu Others', 'Flowers, candles, achar & gifts', '', 'fa-gift', '']);
        }

        $beverageStore = (int)$pdo->query("SELECT COUNT(*) FROM hotels WHERE kind = 'beverage'")->fetchColumn();
        if ($beverageStore === 0) {
            $pdo->prepare(
                "INSERT INTO hotels (name, type, phone, emoji, logo, kind) VALUES (?, ?, ?, ?, ?, 'beverage')"
            )->execute(['LyaiDeu Beverages', 'Cold drinks, alcohol & water', '', 'fa-champagne-glasses', '']);
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

        // Link each 'other' vendor to its other store (matched by name) so a
        // store's page shows only that store's products.
        try {
            $hasVendors = (bool)$pdo->query("SHOW TABLES LIKE 'vendors'")->fetchColumn();
            if ($hasVendors) {
                $otherStores = $pdo->query("SELECT id, name FROM hotels WHERE kind = 'other' ORDER BY id")->fetchAll();
                $storeByName = [];
                foreach ($otherStores as $os) {
                    $storeByName[lyaideu_normalize_name((string)$os['name'])] = (int)$os['id'];
                }
                if ($storeByName) {
                    $link = $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ? AND (hotel_id IS NULL OR hotel_id = 0)');
                    foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'other'") as $ov) {
                        if (!empty($ov['hotel_id'])) {
                            continue;
                        }
                        $key = lyaideu_normalize_name((string)$ov['name']);
                        if ($key !== '' && isset($storeByName[$key])) {
                            $link->execute([$storeByName[$key], (int)$ov['id']]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore linking errors.
        }
        $done = true;
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
        lyaideu_ensure_column($pdo, 'riders', 'avatar', "VARCHAR(255) NOT NULL DEFAULT ''");

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

        // Every 'other' store also gets a vendor account (same rule as hotels
        // and marts) so its products always reach a vendor when ordered.
        $linkedOtherHotelIds = [];
        $otherVendorByName = [];
        foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'other'") as $ov) {
            if (!empty($ov['hotel_id'])) {
                $linkedOtherHotelIds[(int)$ov['hotel_id']] = (int)$ov['id'];
            }
            $otherVendorByName[lyaideu_normalize_name((string)$ov['name'])] = (int)$ov['id'];
        }
        $insOtherVendor = $pdo->prepare(
            'INSERT INTO vendors (name, email, phone, pass, scope, hotel_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        foreach ($pdo->query("SELECT id, name FROM hotels WHERE kind = 'other' ORDER BY id") as $os) {
            $oid = (int)$os['id'];
            if (isset($linkedOtherHotelIds[$oid])) {
                continue;
            }
            $on = lyaideu_normalize_name((string)$os['name']);
            $existingVendorId = ($on !== '' && isset($otherVendorByName[$on])) ? (int)$otherVendorByName[$on] : 0;
            if ($existingVendorId > 0) {
                $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ?')->execute([$oid, $existingVendorId]);
                continue;
            }
            $insOtherVendor->execute([
                (string)$os['name'],
                'othervendor' . $oid . '@lyaideu.local',
                '9' . str_pad((string)$oid, 9, '0', STR_PAD_LEFT),
                $defaultVendorPass,
                'other',
                $oid,
                date('Y-m-d H:i:s'),
            ]);
        }

        // Every 'beverage' store also gets a vendor account (same rule as the
        // other stores) so its products always reach a vendor when ordered.
        $linkedBeverageHotelIds = [];
        $beverageVendorByName = [];
        foreach ($pdo->query("SELECT id, name, hotel_id FROM vendors WHERE scope = 'beverage'") as $bv) {
            if (!empty($bv['hotel_id'])) {
                $linkedBeverageHotelIds[(int)$bv['hotel_id']] = (int)$bv['id'];
            }
            $beverageVendorByName[lyaideu_normalize_name((string)$bv['name'])] = (int)$bv['id'];
        }
        $insBeverageVendor = $pdo->prepare(
            'INSERT INTO vendors (name, email, phone, pass, scope, hotel_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        foreach ($pdo->query("SELECT id, name FROM hotels WHERE kind = 'beverage' ORDER BY id") as $bs) {
            $bid = (int)$bs['id'];
            if (isset($linkedBeverageHotelIds[$bid])) {
                continue;
            }
            $bn = lyaideu_normalize_name((string)$bs['name']);
            $existingVendorId = ($bn !== '' && isset($beverageVendorByName[$bn])) ? (int)$beverageVendorByName[$bn] : 0;
            if ($existingVendorId > 0) {
                $pdo->prepare('UPDATE vendors SET hotel_id = ? WHERE id = ?')->execute([$bid, $existingVendorId]);
                continue;
            }
            $insBeverageVendor->execute([
                (string)$bs['name'],
                'beveragevendor' . $bid . '@lyaideu.local',
                '9' . str_pad((string)$bid, 9, '0', STR_PAD_LEFT),
                $defaultVendorPass,
                'beverage',
                $bid,
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
 * Human-readable per-vendor summary of an order's items, e.g.
 * "4 to 9 cafe: Chicken Momo ×2, Veg Thukpa ×1 · Subodh Mart: Coke ×1".
 * Pass $onlyVendorId to describe just that vendor's portion (used for the
 * vendor's own new-order notification). Always returns a safe, capped string.
 */
function lyaideu_order_vendor_summary(int $orderId, ?int $onlyVendorId = null): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $orderId <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare(
            'SELECT oi.vendor_id, v.name AS vendor_name, oi.hotel, oi.name, oi.variant, oi.qty
             FROM order_items oi
             LEFT JOIN vendors v ON v.id = oi.vendor_id
             WHERE oi.order_id = ?
             ORDER BY oi.vendor_id IS NULL, oi.vendor_id, oi.id'
        );
        $st->execute([$orderId]);
        $groups = [];
        $other = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $vid = (int)$it['vendor_id'];
            $variant = (string)($it['variant'] ?? '');
            $part = (string)$it['name'] . ($variant !== '' ? ' (' . $variant . ')' : '') . ' ×' . (int)$it['qty'];
            if ($onlyVendorId !== null && $vid !== $onlyVendorId) {
                continue;
            }
            if ($vid > 0) {
                $label = (string)$it['vendor_name'] !== '' ? (string)$it['vendor_name'] : (string)$it['hotel'];
                if (!isset($groups[$vid])) {
                    $groups[$vid] = ['label' => $label, 'parts' => []];
                }
                $groups[$vid]['parts'][] = $part;
            } else {
                $other[] = $part;
            }
        }
        $chunks = [];
        foreach ($groups as $g) {
            $chunks[] = ($g['label'] !== '' ? $g['label'] : 'Vendor') . ': ' . implode(', ', $g['parts']);
        }
        if ($other) {
            $chunks[] = 'Other: ' . implode(', ', $other);
        }
        $summary = implode(' · ', $chunks);
        if (mb_strlen($summary) > 180) {
            $summary = mb_substr($summary, 0, 180) . '…';
        }
        return $summary;
    } catch (Throwable $e) {
        return '';
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

        $items = $pdo->prepare('SELECT name, price, qty, line_total, variant, vendor_id FROM order_items WHERE order_id = ? ORDER BY id');
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
        $variant = (string)($it['variant'] ?? '');
        $h .= '<div class="vendor-product"><div class="vp-main">'
            . '<span class="vp-vendor"><i class="fa-solid ' . $ico . '"></i> ' . htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="vp-name">' . htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') . ($variant !== '' ? ' <em class="vp-variant">(' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . ')</em>' : '') . ' × ' . (int)$it['qty'] . '</span>'
            . '<span class="vp-line">Rs. ' . (int)$it['line_total'] . '</span>'
            . '</div>' . lyaideu_order_vendor_progress_html($v['status']) . '</div>';
    }
    return $h . '</div></div>';
}

function lyaideu_order_other_html(array $items): string {
    $h = '<div class="order-vendor-row other"><div class="vendor-row-head"><strong class="vendor-name">Other items</strong><span class="order-status-pill status-cancelled">Not fulfilled</span></div><div class="vendor-products">';
    foreach ($items as $it) {
        $variant = (string)($it['variant'] ?? '');
        $h .= '<div class="vendor-product"><div class="vp-main"><span class="vp-name">' . htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') . ($variant !== '' ? ' <em class="vp-variant">(' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . ')</em>' : '') . ' × ' . (int)$it['qty'] . '</span><span class="vp-line">Rs. ' . (int)$it['line_total'] . '</span></div></div>';
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
 * Ensures the variant system exists: the `product_variants` table, a
 * `has_variants` toggle on every product table, and the `variant` snapshot
 * column on `order_items`. Idempotent — safe to call on every request.
 */
function lyaideu_ensure_variant_tables(): bool {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS product_variants (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                item_type VARCHAR(20) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                label VARCHAR(150) NOT NULL,
                price INT UNSIGNED NOT NULL DEFAULT 0,
                info VARCHAR(255) NOT NULL DEFAULT \'\',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_variants_item (item_type, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        lyaideu_ensure_column($pdo, 'dishes', 'has_variants', 'TINYINT(1) NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'mart_items', 'has_variants', 'TINYINT(1) NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'other_items', 'has_variants', 'TINYINT(1) NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'beverage_items', 'has_variants', 'TINYINT(1) NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'order_items', 'variant', "VARCHAR(255) NOT NULL DEFAULT ''");

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ensures every product table carries a `discount_percent` column so items can
 * be shown with a "-X%" badge and a struck-through original price.
 * Idempotent — safe to call on every request.
 */
function lyaideu_ensure_discount_columns(): bool {
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        lyaideu_ensure_column($pdo, 'dishes', 'discount_percent', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'mart_items', 'discount_percent', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'other_items', 'discount_percent', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        lyaideu_ensure_column($pdo, 'beverage_items', 'discount_percent', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        $done = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Normalized discount percent (0–95) for a product row; 0 when none. */
function lyaideu_deal_percent($value): int {
    return max(0, min(95, (int)$value));
}

/**
 * Price after the discount percent is applied. Uses plain rounding that matches
 * JavaScript's Math.round so client cart totals always equal server-side math.
 */
function lyaideu_deal_price(int $price, int $pct): int {
    $pct = lyaideu_deal_percent($pct);
    if ($pct <= 0 || $price <= 0) {
        return max(0, $price);
    }
    return (int)round($price * (100 - $pct) / 100);
}

/* ============================================================
   Promo codes: admin-created discount codes validated at
   checkout. Types: percent off, fixed Rs. off, free delivery.
   Rules: optional minimum order, optional max-discount cap for
   percent promos, "first N customers" global usage limit and a
   hard once-per-customer rule enforced via order history.
   ============================================================ */
function lyaideu_ensure_promo_table(): bool {
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS promo_codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(40) NOT NULL,
                type VARCHAR(12) NOT NULL DEFAULT \'percent\',
                value SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                min_order INT UNSIGNED NOT NULL DEFAULT 0,
                max_discount INT UNSIGNED NOT NULL DEFAULT 0,
                usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
                used_count INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_promo_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** All promo codes, newest first (admin listing). */
function lyaideu_promo_codes(): array {
    lyaideu_ensure_promo_table();
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT id, code, type, value, min_order, max_discount, usage_limit, used_count, expires_at, is_active, created_at
             FROM promo_codes ORDER BY id DESC'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['value'] = (int)$r['value'];
            $r['min_order'] = (int)$r['min_order'];
            $r['max_discount'] = (int)$r['max_discount'];
            $r['usage_limit'] = (int)$r['usage_limit'];
            $r['used_count'] = (int)$r['used_count'];
            $r['is_active'] = (int)$r['is_active'];
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function lyaideu_promo_types(): array {
    return ['percent', 'fixed', 'freedelivery'];
}

/**
 * Single source of truth for promo validation — used by api/promo.php for the
 * live checkout check and re-run authoritatively by order_save.php before an
 * order is stored. Returns:
 *   ['ok'=>bool, 'msg'=>string, 'promo'=>['code','type','value','discount','free_delivery']]
 */
function lyaideu_promo_evaluate(string $code, int $subtotal, int $userId): array {
    lyaideu_ensure_promo_table();
    $fail = static function (string $msg): array {
        return ['ok' => false, 'msg' => $msg, 'promo' => null];
    };
    $code = strtoupper(trim($code));
    if ($code === '') {
        return $fail('Enter a promo code.');
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return $fail('Could not check this code right now. Please try again.');
    }
    try {
        $st = $pdo->prepare('SELECT code, type, value, min_order, max_discount, usage_limit, used_count, expires_at, is_active FROM promo_codes WHERE code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $fail('Could not check this code right now. Please try again.');
    }
    if (!$row) {
        return $fail('This code doesn\'t exist.');
    }
    if (!(int)$row['is_active']) {
        return $fail('This code is no longer active.');
    }
    if (!empty($row['expires_at'])) {
        $ts = strtotime((string)$row['expires_at']);
        if ($ts && $ts < time()) {
            return $fail('This code expired on ' . date('M j, Y', $ts) . '.');
        }
    }
    if ((int)$row['usage_limit'] > 0 && (int)$row['used_count'] >= (int)$row['usage_limit']) {
        return $fail('This code has been fully redeemed.');
    }
    if ($userId > 0) {
        try {
            $used = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :u AND promo = :c');
            $used->execute([':u' => $userId, ':c' => $code]);
            if ((int)$used->fetchColumn() > 0) {
                return $fail('You have already used this code.');
            }
        } catch (Throwable $e) {
            // Order lookup failure should never block checkout; skip per-user rule.
        }
    }
    if ((int)$row['min_order'] > 0 && $subtotal < (int)$row['min_order']) {
        return $fail('Add Rs. ' . number_format((int)$row['min_order'] - $subtotal) . ' more to use this code.');
    }

    $type = in_array((string)$row['type'], lyaideu_promo_types(), true) ? (string)$row['type'] : 'percent';
    $value = max(0, (int)$row['value']);
    $discount = 0;
    $freeDelivery = false;
    if ($type === 'freedelivery') {
        $freeDelivery = true;
    } elseif ($type === 'fixed') {
        $discount = $value;
    } else {
        $discount = $subtotal > 0 ? (int)round($subtotal * $value / 100) : 0;
        if ((int)$row['max_discount'] > 0) {
            $discount = min($discount, (int)$row['max_discount']);
        }
    }
    $discount = min($discount, max(0, $subtotal));

    $label = $type === 'freedelivery'
        ? 'Free delivery applied!'
        : ($type === 'fixed'
            ? 'Rs. ' . number_format($discount) . ' off applied!'
            : $value . '% off applied!' . ((int)$row['max_discount'] > 0 ? ' (up to Rs. ' . number_format((int)$row['max_discount']) . ')' : ''));
    return [
        'ok' => true,
        'msg' => $label,
        'promo' => [
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'min_order' => (int)$row['min_order'],
            'discount' => $discount,
            'free_delivery' => $freeDelivery,
        ],
    ];
}

/**
 * Ordered variant options for a single product. Empty when the product has none.
 */
function lyaideu_item_variants(string $type, int $itemId): array {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0 || !in_array($type, ['dish', 'mart', 'other', 'beverage'], true)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, label, price, info, is_default
             FROM product_variants
             WHERE item_type = ? AND item_id = ?
             ORDER BY sort_order, id'
        );
        $st->execute([$type, $itemId]);
        return array_map(static function (array $v): array {
            $v['price'] = (int)$v['price'];
            $v['is_default'] = (int)$v['is_default'];
            return $v;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Attaches `has_variants` + `variants` to an array of product rows in place
 * using a single grouped query per product type. Expects each row to already
 * carry `has_variants` from its SELECT.
 */
function lyaideu_attach_variants(array &$rows, string $type): void {
    if (!$rows || !in_array($type, ['dish', 'mart', 'other', 'beverage'], true)) {
        return;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'SELECT item_id, label, price, info, is_default
             FROM product_variants
             WHERE item_type = ?
             ORDER BY item_id, sort_order, id'
        );
        $st->execute([$type]);
        $byItem = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $byItem[(int)$v['item_id']][] = [
                'label' => (string)$v['label'],
                'price' => (int)$v['price'],
                'info' => (string)$v['info'],
                'is_default' => (int)$v['is_default'],
            ];
        }
        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $row['has_variants'] = !empty($row['has_variants']) ? 1 : 0;
            $row['variants'] = $byItem[$id] ?? [];
        }
        unset($row);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Saves a product's variant configuration: sets its `has_variants` toggle and
 * replaces all option rows (delete + re-insert). Options without a label are
 * skipped. The first row marked `default` becomes the preselected option.
 */
function lyaideu_save_item_variants(PDO $pdo, string $type, int $itemId, $hasVariants, array $options): void {
    if ($itemId <= 0 || !in_array($type, ['dish', 'mart', 'other', 'beverage'], true)) {
        return;
    }
    $table = $type === 'mart' ? 'mart_items' : ($type === 'other' ? 'other_items' : ($type === 'beverage' ? 'beverage_items' : 'dishes'));
    try {
        $pdo->prepare("UPDATE `$table` SET has_variants = ? WHERE id = ?")->execute([$hasVariants ? 1 : 0, $itemId]);
        $del = $pdo->prepare('DELETE FROM product_variants WHERE item_type = ? AND item_id = ?');
        $del->execute([$type, $itemId]);

        $ins = $pdo->prepare(
            'INSERT INTO product_variants (item_type, item_id, label, price, info, is_default, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        $defaultSet = false;
        $firstRowId = 0;
        foreach ($options as $opt) {
            $label = trim(strip_tags((string)($opt['label'] ?? '')));
            if ($label === '') {
                continue;
            }
            $price = max(0, (int)($opt['price'] ?? 0));
            $info = trim(strip_tags((string)($opt['info'] ?? '')));
            $isDefault = (!$defaultSet && !empty($opt['default'])) ? 1 : 0;
            if ($isDefault) {
                $defaultSet = true;
            }
            $ins->execute([$type, $itemId, $label, $price, $info, $isDefault, $sort]);
            if ($firstRowId === 0) {
                $firstRowId = (int)$pdo->lastInsertId();
            }
            $sort++;
        }
        // Never leave a variants-enabled product without a preselected option:
        // the customer side always shows one as chosen, so default to the first.
        if (!$defaultSet && $firstRowId > 0) {
            $pdo->prepare('UPDATE product_variants SET is_default = 1 WHERE id = ?')->execute([$firstRowId]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Removes all variant rows for a product (used when a product is deleted).
 */
function lyaideu_delete_item_variants(PDO $pdo, string $type, int $itemId): void {
    if ($itemId <= 0 || !in_array($type, ['dish', 'mart', 'other', 'beverage'], true)) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM product_variants WHERE item_type = ? AND item_id = ?')->execute([$type, $itemId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Admin editor markup for the variant system: a toggle checkbox plus a
 * repeatable list of option rows (label, price, optional info, default marker,
 * reorder + remove). Rows are named under `$prefix` so the whole editor drops
 * into any product add/edit form. Repeater behaviour lives in
 * js/admin-variants.js.
 */
function lyaideu_variants_editor_html(string $prefix, array $variants = [], bool $hasVariants = false, bool $syncPrice = false): string {
    $p = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    $h = '<div class="pm-variants"' . ($syncPrice ? ' data-sync-price="1"' : '') . '>'
        . '<label class="pm-variant-toggle">'
        . '<input type="checkbox" class="pv-toggle" name="' . $p . '[has_variants]" value="1"' . ($hasVariants ? ' checked' : '') . '>'
        . '<span><i class="fa-solid fa-layer-group"></i> Enable size / quantity options</span>'
        . '<small>Customers pick an option (e.g. 0.5 kg / 1 kg) with its own price. Add at least one option below.</small>'
        . '</label>'
        . '<div class="pv-list"' . ($hasVariants ? '' : ' style="display:none"') . '>';
    $variants = array_values($variants);
    if (!$variants) {
        $h .= lyaideu_variant_row_html($p, 0, null);
    } else {
        foreach ($variants as $i => $v) {
            $h .= lyaideu_variant_row_html($p, $i, $v);
        }
    }
    $h .= '<button type="button" class="btn btn-outline pv-add"><i class="fa-solid fa-plus"></i> Add option</button>';
    return $h . '</div></div>';
}

/**
 * Single option row used by lyaideu_variants_editor_html().
 */
function lyaideu_variant_row_html(string $p, int $i, ?array $v): string {
    $label = $v ? htmlspecialchars((string)($v['label'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
    $price = $v ? (int)($v['price'] ?? 0) : '';
    $info = $v ? htmlspecialchars((string)($v['info'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
    $def = $v && !empty($v['is_default']) ? ' checked' : '';
    return '<div class="pv-row">'
        . '<div class="pv-fields">'
        . '<input type="text" class="pv-label" name="' . $p . '[variants][' . $i . '][label]" placeholder="Option, e.g. 0.5 kg" value="' . $label . '">'
        . '<input type="number" min="0" step="1" class="pv-price" name="' . $p . '[variants][' . $i . '][price]" placeholder="Price (Rs.)" value="' . $price . '">'
        . '<input type="text" class="pv-info" name="' . $p . '[variants][' . $i . '][info]" placeholder="Info (optional)" value="' . $info . '">'
        . '</div>'
        . '<div class="pv-tools">'
        . '<label class="pv-default" title="Preselect this option by default"><input type="checkbox" class="pv-default-input" name="' . $p . '[variants][' . $i . '][default]" value="1"' . $def . '> Default</label>'
        . '<button type="button" class="pm-act pv-up" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>'
        . '<button type="button" class="pm-act pv-down" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>'
        . '<button type="button" class="pm-act pm-del-btn pv-del" title="Remove option"><i class="fa-solid fa-trash-can"></i></button>'
        . '</div></div>';
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

        // Other items belong to an 'other'-scope vendor (via the store's
        // vendor link). Assign only items that have no owner yet.
        try {
            $otherVendors = $pdo->query(
                "SELECT v.id, v.hotel_id FROM vendors v
                 WHERE v.scope = 'other' AND v.is_active = 1 AND v.hotel_id IS NOT NULL
                 ORDER BY v.id"
            )->fetchAll();
            if ($otherVendors) {
                $unassigned = $pdo->query('SELECT id, vendor_id FROM other_items WHERE vendor_id IS NULL')->fetchAll();
                $byStore = [];
                foreach ($otherVendors as $ov) {
                    $byStore[(int)$ov['hotel_id']] = (int)$ov['id'];
                }
                $upd = $pdo->prepare('UPDATE other_items SET vendor_id = ? WHERE id = ?');
                foreach ($unassigned as $oi) {
                    if (!empty($oi['vendor_id'])) {
                        continue;
                    }
                    $vid = $byStore[1] ?? ($otherVendors[0]['id'] ?? 0);
                    if ($vid > 0) {
                        $upd->execute([$vid, (int)$oi['id']]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Best-effort.
        }

        // Beverage items belong to a 'beverage'-scope vendor (via the store's
        // vendor link). Assign only items that have no owner yet.
        try {
            lyaideu_ensure_beverage_table();
            $beverageVendors = $pdo->query(
                "SELECT v.id, v.hotel_id FROM vendors v
                 WHERE v.scope = 'beverage' AND v.is_active = 1 AND v.hotel_id IS NOT NULL
                 ORDER BY v.id"
            )->fetchAll();
            if ($beverageVendors) {
                $unassigned = $pdo->query('SELECT id, vendor_id FROM beverage_items WHERE vendor_id IS NULL')->fetchAll();
                $byStore = [];
                foreach ($beverageVendors as $bv) {
                    $byStore[(int)$bv['hotel_id']] = (int)$bv['id'];
                }
                $upd = $pdo->prepare('UPDATE beverage_items SET vendor_id = ? WHERE id = ?');
                foreach ($unassigned as $bi) {
                    if (!empty($bi['vendor_id'])) {
                        continue;
                    }
                    $vid = $byStore[1] ?? ($beverageVendors[0]['id'] ?? 0);
                    if ($vid > 0) {
                        $upd->execute([$vid, (int)$bi['id']]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Best-effort.
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
function lyaideu_resolve_other_vendor(int $itemId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT vendor_id FROM other_items WHERE id = ?');
        $st->execute([$itemId]);
        $existing = (int)$st->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }
        // Fall back to the first active 'other'-scope vendor (usually the
        // default "LyaiDeu Others" store) so the item still reaches a vendor.
        $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'other' AND is_active = 1 ORDER BY id LIMIT 1");
        $st->execute();
        $vendorId = (int)$st->fetchColumn();
        $upd = $pdo->prepare('UPDATE other_items SET vendor_id = ? WHERE id = ?');
        $upd->execute([$vendorId > 0 ? $vendorId : null, $itemId]);
        return $vendorId;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolves the store (hotel) name that owns an other item, via the item's
 * vendor and that vendor's linked store. Falls back to 'LyaiDeu Others'.
 */
function lyaideu_other_store_name(int $itemId): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 'LyaiDeu Others';
    }
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(h.name, \'\')
             FROM other_items oi
             LEFT JOIN vendors v ON v.id = oi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE oi.id = ?'
        );
        $st->execute([$itemId]);
        $name = (string)$st->fetchColumn();
        return $name !== '' ? $name : 'LyaiDeu Others';
    } catch (Throwable $e) {
        return 'LyaiDeu Others';
    }
}
function lyaideu_resolve_beverage_vendor(int $itemId): int {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT vendor_id FROM beverage_items WHERE id = ?');
        $st->execute([$itemId]);
        $existing = (int)$st->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }
        // Fall back to the first active 'beverage'-scope vendor (usually the
        // default "LyaiDeu Beverages" store) so the item still reaches a vendor.
        $st = $pdo->prepare("SELECT id FROM vendors WHERE scope = 'beverage' AND is_active = 1 ORDER BY id LIMIT 1");
        $st->execute();
        $vendorId = (int)$st->fetchColumn();
        $upd = $pdo->prepare('UPDATE beverage_items SET vendor_id = ? WHERE id = ?');
        $upd->execute([$vendorId > 0 ? $vendorId : null, $itemId]);
        return $vendorId;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolves the store (hotel) name that owns a beverage item, via the item's
 * vendor and that vendor's linked store. Falls back to 'LyaiDeu Beverages'.
 */
function lyaideu_beverage_store_name(int $itemId): string {
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO || $itemId <= 0) {
        return 'LyaiDeu Beverages';
    }
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(h.name, \'\')
             FROM beverage_items bi
             LEFT JOIN vendors v ON v.id = bi.vendor_id
             LEFT JOIN hotels h ON h.id = v.hotel_id
             WHERE bi.id = ?'
        );
        $st->execute([$itemId]);
        $name = (string)$st->fetchColumn();
        return $name !== '' ? $name : 'LyaiDeu Beverages';
    } catch (Throwable $e) {
        return 'LyaiDeu Beverages';
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

/**
 * Renders the shared website footer. All of the contact info, opening hours,
 * blurb and copyright text come from the `settings` table (editable from the
 * admin panel) and fall back to sensible defaults. Use {{year}} inside the
 * copyright to print the current year automatically.
 */
function lyaideu_footer_html(): string {
    $esc = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $logo = $esc(site_logo_url());
    $blurb = $esc(site_setting('footer_blurb', "Nepal's friendliest food delivery service — connecting you to the best hotels in the valley."));
    $address = $esc(site_setting('footer_address', 'Lazimpat, Kathmandu'));
    $email = $esc(site_setting('footer_email', 'hello@lyaideu.com.np'));
    $phone = $esc(site_setting('footer_phone', '9800000001'));
    $hoursWeekday = $esc(site_setting('footer_hours_weekday', 'Sun – Fri: 7 AM – 10 PM'));
    $hoursSaturday = $esc(site_setting('footer_hours_saturday', 'Saturday: 8 AM – 10 PM'));
    $hoursNote = $esc(site_setting('footer_hours_note', 'Deliveries every day!'));
    $copyright = str_replace('{{year}}', date('Y'), site_setting('footer_copyright', '© {{year}} LyaiDeu · All rights reserved.'));

    return '<footer class="footer">
    <div class="footer-grid">
        <div><p class="footer-brand"><img class="brand-logo" src="' . $logo . '" alt="LyaiDeu"></p><p class="footer-blurb">' . $blurb . '</p></div>
        <div><h4>Quick Links</h4><ul><li><a href="index">Home</a></li><li><a href="menu">Menu</a></li><li><a href="categories">Categories</a></li><li><a href="store">Stores</a></li><li><a href="mart">Mart</a></li><li><a href="others">Others</a></li><li><a href="contact">Contact</a></li><li><a href="faq">FAQ &amp; Privacy</a></li><li><a href="terms">Terms of Service</a></li></ul></div>
        <div><h4>Get In Touch</h4><ul><li><i class="fa-solid fa-location-dot"></i> ' . $address . '</li><li><i class="fa-solid fa-envelope"></i> ' . $email . '</li><li><i class="fa-solid fa-phone"></i> ' . $phone . '</li></ul></div>
        <div><h4>Opening Hours</h4><ul><li>' . $hoursWeekday . '</li><li>' . $hoursSaturday . '</li><li><i class="fa-solid fa-motorcycle"></i> ' . $hoursNote . '</li></ul></div>
    </div>
    <div class="footer-bottom">' . $copyright . '</div>
</footer>';
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

function lyaideu_from_home(): bool {
    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '') {
        return false;
    }
    $path = rtrim((string)(parse_url($ref, PHP_URL_PATH) ?: ''), '/');
    $base = rtrim(parse_url(lyaideu_base_url(), PHP_URL_PATH) ?: '', '/');
    if ($path === '' || $path === $base) {
        return true;
    }
    $leaf = substr($path, strrpos($path, '/') + 1);
    return $leaf === 'index' || $leaf === 'index.php';
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

/**
 * Admin staff accounts (superadmin / admin / manager) with per-page access.
 * Creates the tables and seeds the first superadmin from the legacy single
 * admin credentials stored in `settings` (username + bcrypt hash), so the
 * existing login keeps working. Runs once per request.
 */
function lyaideu_ensure_admin_users_tables(): bool {
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = lyaideu_load_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(100) NOT NULL,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(190) DEFAULT NULL,
                pass_hash VARCHAR(255) NOT NULL,
                role ENUM(\'superadmin\',\'admin\',\'manager\') NOT NULL DEFAULT \'manager\',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                last_login DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_users_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_user_pages (
                admin_id INT UNSIGNED NOT NULL,
                page_key VARCHAR(40) NOT NULL,
                PRIMARY KEY (admin_id, page_key),
                CONSTRAINT fk_admin_pages_user FOREIGN KEY (admin_id)
                    REFERENCES admin_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($count === 0) {
            lyaideu_ensure_settings_table();
            /* Migrate the legacy credentials (settings table) into the first
               superadmin account. Falls back to admin/admin123 if nothing
               usable is stored yet. */
            $username = trim(site_setting('admin_username', ''));
            $hash = trim(site_setting('admin_pass_hash', ''));
            if ($username === '') {
                $username = 'admin';
            }
            if (!preg_match('/^\$2[aby]\$/', $hash)) {
                $hash = password_hash('admin123', PASSWORD_DEFAULT);
            }
            $ins = $pdo->prepare(
                'INSERT INTO admin_users (username, name, email, pass_hash, role, is_active, created_at)
                 VALUES (:u, :n, NULL, :p, \'superadmin\', 1, :c)'
            );
            $ins->execute([
                ':u' => $username,
                ':n' => 'Super Admin',
                ':p' => $hash,
                ':c' => date('Y-m-d H:i:s'),
            ]);
            /* Remove the now-superseded legacy credential keys so passwords
               live only in admin_users from here on. */
            try {
                $pdo->exec("DELETE FROM settings WHERE skey IN ('admin_username', 'admin_pass_hash')");
            } catch (Throwable $e2) {
                /* non-fatal */
            }
            lyaideu_settings_clear();
        }
        $done = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
