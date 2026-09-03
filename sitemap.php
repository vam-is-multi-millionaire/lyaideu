<?php
/**
 * Dynamic XML sitemap generator — served at https://lyaideu.com/sitemap.xml
 * (rewritten from sitemap.xml in .htaccess). Includes every static page plus
 * live categories, partner stores and products pulled from the database.
 */
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

require_once __DIR__ . '/seo.php';

$urls = [];
$add = function (string $loc, string $priority = '1.0', string $changefreq = 'daily') use (&$urls): void {
    $loc = htmlspecialchars(lyaideu_seo_abs($loc), ENT_QUOTES, 'UTF-8');
    $urls[$loc] = '  <url>'
        . '<loc>' . $loc . '</loc>'
        . '<changefreq>' . $changefreq . '</changefreq>'
        . '<priority>' . $priority . '</priority>'
        . '</url>';
};

/* Static storefront pages - all daily/1.0 for SEO. */
$add('', '1.0', 'daily');
$add('menu', '1.0', 'daily');
$add('mart', '1.0', 'daily');
$add('beverages', '1.0', 'daily');
$add('others', '1.0', 'daily');
$add('categories', '1.0', 'daily');
$add('store', '1.0', 'daily');
$add('contact', '1.0', 'daily');
$add('faq', '1.0', 'daily');
$add('terms', '1.0', 'daily');

/** Best-effort id/slug/category rows from one product table. */
function lyaideu_sitemap_product_rows(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $rows = [];
    if (!in_array($table, ['dishes', 'mart_items', 'other_items', 'beverage_items'], true)) {
        return $rows;
    }
    $pdo = lyaideu_load_pdo();
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query("SELECT id, name_slug, category_id FROM `$table` ORDER BY id")->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $cache[$table] = $rows;
}

/* Section URL builders per category type (custom sections use section.php). */
$builtinSectionOf = [
    'menu'     => ['menu', 'cat'],
    'mart'     => ['mart', 'mcat'],
    'other'    => ['others', 'ocat'],
    'beverage' => ['beverages', 'bcat'],
];
$productSectionOf = [
    'dishes' => 'menu', 'mart_items' => 'mart',
    'other_items' => 'others', 'beverage_items' => 'beverages',
];

try {
    lyaideu_ensure_categories_table();
    lyaideu_ensure_stores();
    lyaideu_ensure_other_table();
    lyaideu_ensure_beverage_table();

    /* Visible category pages. */
    foreach (lyaideu_visible_categories() as $cat) {
        $type = (string)$cat['type'];
        if ($type === '') {
            continue;
        }
        $slug = rawurlencode((string)$cat['slug']);
        if (isset($builtinSectionOf[$type])) {
            [$page, $param] = $builtinSectionOf[$type];
            $add($page . '?' . $param . '=' . $slug, '1.0', 'daily');
        } else {
            $add('section?s=' . rawurlencode($type) . '&cat=' . $slug, '1.0', 'daily');
        }
    }

    /* Product detail pages (pretty slug URLs). */
    foreach ($productSectionOf as $table => $section) {
        foreach (lyaideu_sitemap_product_rows($table) as $row) {
            if ((int)($row['category_id'] ?? 0) > 0 && !lyaideu_category_is_active((int)$row['category_id'])) {
                continue;
            }
            $slug = trim((string)($row['name_slug'] ?? ''));
            $loc = $section . '/' . ($slug !== '' ? rawurlencode($slug) : (string)$row['id']);
            $add($loc, '1.0', 'daily');
        }
    }

    /* Partner store pages. */
    $pdo = lyaideu_load_pdo();
    if ($pdo instanceof PDO) {
        foreach ($pdo->query('SELECT name FROM hotels ORDER BY id') as $hotel) {
            $add('store/' . rawurlencode(lyaideu_slugify((string)$hotel['name'])), '1.0', 'daily');
        }
    }
} catch (Throwable $e) {
    /* DB unavailable — the static page list above is still served. */
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
    . implode("\n", $urls) . "\n"
    . '</urlset>';
echo $xml;
