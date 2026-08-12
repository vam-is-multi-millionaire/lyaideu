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

function site_favicon_url(): string {
    return site_setting('site_favicon', 'favicon.ico');
}

function site_apple_icon_url(): string {
    return site_setting('site_apple_icon', 'apple-touch-icon.png');
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
