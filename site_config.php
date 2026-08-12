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
