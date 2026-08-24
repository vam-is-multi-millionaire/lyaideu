<?php
/**
 * LyaiDeu SEO engine — titles, meta descriptions, keywords, canonical URLs,
 * Open Graph / Twitter cards and JSON-LD structured data.
 *
 * Every public page calls lyaideu_seo_page() once inside <head>; it prints the
 * <title> plus all supporting tags using absolute lyaideu.com URLs so link
 * previews (Facebook, Messenger, WhatsApp, X) always show logo.png.
 */
require_once __DIR__ . '/site_config.php';

const LYAIDEU_CANONICAL_BASE = 'https://lyaideu.com';
const LYAIDEU_SOCIAL_FACEBOOK = 'https://www.facebook.com/profile.php?id=61593068734255';
const LYAIDEU_SOCIAL_INSTAGRAM = 'https://www.instagram.com/lyaideu.np/';

/** Absolute site URL for a pretty path ('' → homepage). */
function lyaideu_seo_abs(string $path = ''): string {
    $path = trim(str_replace(' ', '+', $path), "/ \t\n\r");
    return $path === '' ? LYAIDEU_CANONICAL_BASE . '/' : LYAIDEU_CANONICAL_BASE . '/' . $path;
}

/** Absolute URL of the configured logo — used as the default share image. */
function lyaideu_seo_logo_abs(): string {
    $logo = ltrim((string)site_setting('site_logo', 'logo.png'), '/');
    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }
    return lyaideu_seo_abs($logo);
}

/** Business phone in E.164 (+977…) built from the admin-managed footer phone. */
function lyaideu_seo_phone_e164(): string {
    $raw = preg_replace('/\D+/', '', (string)site_setting('footer_phone', ''));
    if ($raw === '') {
        return '+9779800000001';
    }
    if (str_starts_with($raw, '977')) {
        return '+' . $raw;
    }
    return '+977' . $raw;
}

/** Keyword list; base brand + local terms merged with page-specific extras. */
function lyaideu_seo_keywords(array $extra = []): string {
    $base = [
        'lyaideu', 'lyai deu', 'lyaideu.com', 'lyaideu surkhet',
        'surkhet delivery website', 'birendranagar delivery platform',
        'anything delivery birendranagar', 'delivery in birendranagar',
        'delivery in surkhet', 'online delivery surkhet',
        'food delivery birendranagar', 'grocery delivery surkhet',
        'flower delivery birendranagar', 'gift delivery surkhet',
        'lyai deu nepal',
    ];
    $all = array_values(array_unique(array_merge($extra, $base)));
    return htmlspecialchars(implode(', ', $all), ENT_QUOTES, 'UTF-8');
}

/** Absolute URL for a stored media path (uploads/… or full http URL). */
function lyaideu_seo_media_abs(?string $path): string {
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path !== '' ? $path : lyaideu_seo_logo_abs();
    }
    return lyaideu_seo_abs($path);
}

/** [width, height] of a local image file, or null when unknowable. */
function lyaideu_seo_image_size(string $absUrl): ?array {
    $rel = preg_replace('#^' . preg_quote(LYAIDEU_CANONICAL_BASE, '#') . '/#', '', $absUrl) ?? '';
    if ($rel === '' || str_starts_with($rel, 'http')) {
        return null;
    }
    $file = realpath(__DIR__ . '/' . $rel);
    if (!$file) {
        return null;
    }
    $info = @getimagesize($file);
    return is_array($info) && !empty($info[0]) && !empty($info[1]) ? [(int)$info[0], (int)$info[1]] : null;
}

/** Organization + WebSite + DeliveryService graph for the homepage. */
function lyaideu_seo_jsonld_home(string $desc): array {
    $base = LYAIDEU_CANONICAL_BASE;
    $phone = lyaideu_seo_phone_e164();
    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => $base . '/#organization',
                'name' => 'LyaiDeu',
                'url' => $base . '/',
                'logo' => ['@type' => 'ImageObject', 'url' => lyaideu_seo_logo_abs()],
                'sameAs' => [LYAIDEU_SOCIAL_FACEBOOK, LYAIDEU_SOCIAL_INSTAGRAM],
                'contactPoint' => [[
                    '@type' => 'ContactPoint',
                    'telephone' => $phone,
                    'contactType' => 'customer service',
                    'areaServed' => 'NP',
                ]],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $base . '/#website',
                'url' => $base . '/',
                'name' => 'LyaiDeu',
                'description' => $desc,
                'publisher' => ['@id' => $base . '/#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $base . '/index?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type' => 'DeliveryService',
                '@id' => $base . '/#service',
                'name' => 'LyaiDeu Anything Delivery',
                'url' => $base . '/',
                'description' => $desc,
                'telephone' => $phone,
                'image' => lyaideu_seo_logo_abs(),
                'provider' => ['@id' => $base . '/#organization'],
                'serviceType' => 'Food, grocery & essentials delivery',
                'areaServed' => [
                    ['@type' => 'City', 'name' => 'Birendranagar'],
                    ['@type' => 'AdministrativeArea', 'name' => 'Surkhet Valley, Karnali Province, Nepal'],
                ],
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => (string)site_setting('footer_address', 'Birendranagar'),
                    'addressLocality' => 'Birendranagar',
                    'addressRegion' => 'Karnali Province',
                    'addressCountry' => 'NP',
                ],
                'openingHoursSpecification' => [[
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                    'opens' => '07:00',
                    'closes' => '22:00',
                ]],
            ],
        ],
    ];
}

/** Product + Offer structured data for a product detail page. */
function lyaideu_seo_jsonld_product(array $item, string $typeKey, string $canonicalPath, int $finalPriceNpr): array {
    $img = lyaideu_seo_media_abs((string)($item['img'] ?? ''));
    $desc = trim(html_entity_decode(strip_tags((string)($item['desc'] ?? '')), ENT_QUOTES, 'UTF-8'));
    if ($desc === '') {
        $desc = 'Order ' . (string)$item['name'] . ' online on LyaiDeu — anything delivered in Birendranagar, Surkhet.';
    }
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => (string)$item['name'],
        'image' => [$img],
        'description' => mb_substr($desc, 0, 300, 'UTF-8'),
        'sku' => ucfirst($typeKey) . '-' . (string)($item['id'] ?? ''),
        'brand' => ['@type' => 'Brand', 'name' => 'LyaiDeu'],
        'offers' => [
            '@type' => 'Offer',
            'url' => lyaideu_seo_abs($canonicalPath),
            'priceCurrency' => 'NPR',
            'price' => (string)max(0, $finalPriceNpr),
            'availability' => 'https://schema.org/InStock',
            'seller' => ['@type' => 'Organization', 'name' => 'LyaiDeu'],
        ],
    ];
}

/** LocalBusiness structured data for one partner store. */
function lyaideu_seo_jsonld_store(array $store, string $kindLabel, string $canonicalPath): array {
    $phoneDigits = preg_replace('/\D+/', '', (string)($store['phone'] ?? ''));
    $out = [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => (string)$store['name'],
        'url' => lyaideu_seo_abs($canonicalPath),
        'image' => lyaideu_seo_media_abs((string)($store['logo'] ?? '')),
        'description' => trim((string)($store['type'] ?? '' )) !== ''
            ? (string)$store['type'] . ' on LyaiDeu — delivering across Birendranagar, Surkhet.'
            : $kindLabel . ' partner store on LyaiDeu — delivering across Birendranagar, Surkhet.',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'Birendranagar',
            'addressRegion' => 'Surkhet, Karnali Province',
            'addressCountry' => 'NP',
        ],
    ];
    if ($phoneDigits !== '') {
        $out['telephone'] = str_starts_with($phoneDigits, '977') ? '+' . $phoneDigits : '+977' . $phoneDigits;
    }
    return $out;
}

/** FAQPage structured data from [['q'=>…,'a'=>…], …]. */
function lyaideu_seo_jsonld_faq(array $faqs): array {
    $entities = [];
    foreach ($faqs as $f) {
        $entities[] = [
            '@type' => 'Question',
            'name' => (string)$f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string)$f['a']],
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
}

function lyaideu_seo_jsonld_script(array $ld): string {
    $nodes = [];
    foreach ($ld as $node) {
        unset($node['@context']);
        /* A node carrying its own @graph (e.g. the homepage bundle) is spliced
           flat so the final script has a single top-level @graph array. */
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $inner) {
                $nodes[] = $inner;
            }
            continue;
        }
        $nodes[] = $node;
    }
    return '<script type="application/ld+json">' . json_encode(
        ['@context' => 'https://schema.org', '@graph' => array_values($nodes)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) . '</script>';
}

/**
 * Prints the complete SEO <head> block for a public page:
 * title, description, keywords, canonical, robots, geo tags,
 * Open Graph, Twitter card and JSON-LD structured data.
 *
 * Keys: title, desc, path (pretty URL w/o leading slash), og_type,
 * image (share image override), robots, keywords (array of extras),
 * jsonld (list of schema.org node arrays).
 */
function lyaideu_seo_page(array $o = []): string {
    $title = trim((string)($o['title'] ?? 'LyaiDeu · Anything Delivery in Birendranagar, Surkhet'));
    $desc = trim((string)($o['desc'] ?? 'LyaiDeu delivers anything you need in Birendranagar, Surkhet Valley — hot food from local hotels, fresh groceries, cold beverages, flowers & gifts at your door in 15–60 minutes. Pay with eSewa, Khalti or cash.'));
    $path = trim((string)($o['path'] ?? ''), '/');
    $canonical = lyaideu_seo_abs($path);
    $robots = trim((string)($o['robots'] ?? 'index, follow'));
    $ogType = trim((string)($o['og_type'] ?? 'website'));
    $imgAbs = array_key_exists('image', $o) && (string)$o['image'] !== ''
        ? lyaideu_seo_media_abs((string)$o['image'])
        : lyaideu_seo_logo_abs();
    $eTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $eDesc = htmlspecialchars(mb_substr($desc, 0, 165, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    $eCanonical = htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8');
    $eImg = htmlspecialchars($imgAbs, ENT_QUOTES, 'UTF-8');

    $lines = [
        '<title>' . $eTitle . '</title>',
        '<meta name="description" content="' . $eDesc . '">',
        '<meta name="keywords" content="' . lyaideu_seo_keywords((array)($o['keywords'] ?? [])) . '">',
        '<meta name="author" content="LyaiDeu">',
        '<link rel="canonical" href="' . $eCanonical . '">',
        '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . '">',
        '<meta name="geo.region" content="NP-P5">',
        '<meta name="geo.placename" content="Birendranagar, Surkhet, Nepal">',
    ];

    $lines[] = '<meta property="og:site_name" content="LyaiDeu">';
    $lines[] = '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') . '">';
    $lines[] = '<meta property="og:title" content="' . $eTitle . '">';
    $lines[] = '<meta property="og:description" content="' . $eDesc . '">';
    $lines[] = '<meta property="og:url" content="' . $eCanonical . '">';
    $lines[] = '<meta property="og:image" content="' . $eImg . '">';
    $lines[] = '<meta property="og:image:alt" content="LyaiDeu — Anything Delivery in Birendranagar, Surkhet">';
    $lines[] = '<meta property="og:locale" content="en_US">';
    $size = lyaideu_seo_image_size($imgAbs);
    if ($size) {
        $lines[] = '<meta property="og:image:width" content="' . $size[0] . '">';
        $lines[] = '<meta property="og:image:height" content="' . $size[1] . '">';
    }

    $lines[] = '<meta name="twitter:card" content="summary_large_image">';
    $lines[] = '<meta name="twitter:title" content="' . $eTitle . '">';
    $lines[] = '<meta name="twitter:description" content="' . $eDesc . '">';
    $lines[] = '<meta name="twitter:image" content="' . $eImg . '">';

    $jsonld = (array)($o['jsonld'] ?? []);
    if ($jsonld) {
        $lines[] = lyaideu_seo_jsonld_script($jsonld);
    }
    return implode("\n", $lines);
}
