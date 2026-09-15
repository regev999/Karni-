<?php
declare(strict_types=1);

/**
 * Everything the page needs to be found: absolute URLs, a stable per-product
 * URL key, the head tags, the schema.org graph, and the two static files
 * (robots.txt, sitemap.xml) that are rewritten whenever the catalogue changes.
 */

/** Origin of the current request, always without a leading www. */
function origin(): string
{
    static $origin = null;
    if ($origin !== null) {
        return $origin;
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    // One host owns the ranking signals; www. and the bare name are the same site.
    $host = preg_replace('/^www\./i', '', $host) ?? $host;
    return $origin = ($https ? 'https://' : 'http://') . $host;
}

function abs_url(string $path = ''): string
{
    return origin() . '/' . ltrim($path, '/');
}

function current_host(): string
{
    return (string) preg_replace('~^https?://~', '', origin());
}

/** Lowercase, hyphenated, Hebrew letters kept as they are. */
function slugify(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return trim(preg_replace('/[^a-z0-9\x{0590}-\x{05FF}]+/u', '-', $s) ?? '', '-');
}

/**
 * The catalogue with a URL key on every row. The key comes from the name and
 * the SKU rather than the stored id, because the id is positional and would
 * move every product's URL the moment a row is added or removed.
 */
function with_slugs(array $rows): array
{
    $seen = [];
    foreach ($rows as &$p) {
        $base = slugify(($p['name'] ?? '') . '-' . ($p['sku'] ?? '')) ?: 'p';
        $slug = $base;
        $n = 1;
        while (isset($seen[$slug])) {
            $slug = $base . '-' . (++$n);
        }
        $seen[$slug] = true;
        $p['slug'] = $slug;
    }
    unset($p);
    return $rows;
}

function products_indexed(): array
{
    static $rows = null;
    return $rows ??= with_slugs(products_all());
}

function product_by_slug(string $slug): ?array
{
    if ($slug === '') {
        return null;
    }
    foreach (products_indexed() as $p) {
        if ($p['slug'] === $slug) {
            return $p;
        }
    }
    return null;
}

function product_url(array $p): string
{
    return abs_url() . '?p=' . rawurlencode($p['slug']);
}

/** Percentage off, or null when the row has no pair of prices to compare. */
function product_discount(array $p): ?int
{
    $before = (int) ($p['price_before'] ?? 0);
    $after  = (int) ($p['price_after'] ?? 0);
    return $before > 0 && $after > 0 && $before > $after
        ? (int) round(($before - $after) / $before * 100)
        : null;
}

function product_title(array $p, array $s): string
{
    $head = trim(($p['name'] ?? '') . ' ' . ($p['sku'] ?? ''));
    $after = (int) ($p['price_after'] ?? 0);
    return $head . ($after ? ' — ₪' . shekel($after) : '') . ' | קרני תכלת';
}

function product_description(array $p, array $s): string
{
    if (trim((string) ($p['description'] ?? '')) !== '') {
        return mb_substr(trim((string) $p['description']), 0, 300);
    }
    $parts = [];
    $parts[] = ($p['name'] ?? '') . ($p['sku'] ?? '' ? ', מק״ט ' . $p['sku'] : '');
    if (!empty($p['price_after'])) {
        $line = 'מחיר מתצוגה ₪' . shekel($p['price_after']);
        if (!empty($p['price_before'])) {
            $line .= ' במקום ₪' . shekel($p['price_before']);
            if ($pct = product_discount($p)) {
                $line .= ' (' . $pct . '% הנחה)';
            }
        }
        $parts[] = $line;
    }
    $parts[] = 'גוף תאורה מאולם התצוגה של קרני תכלת, ' . $s['address'];
    $parts[] = 'להזמנה השאירו פרטים או חייגו ' . $s['phone'] . '.';
    return implode('. ', $parts);
}

/** The head tags. $product is null on the catalogue page. */
function seo_head(array $s, ?array $product, string $rev): void
{
    $canonical = $product ? product_url($product) : abs_url();
    $title     = $product ? product_title($product, $s) : $s['site_title'];
    $desc      = $product ? product_description($product, $s) : $s['description'];
    $image     = abs_url($rev);

    $tags = [
        ['name', 'description', $desc],
        ['name', 'robots', !empty($s['noindex'])
            ? 'noindex, nofollow'
            : 'index, follow, max-image-preview:large, max-snippet:-1'],
        ['name', 'theme-color', '#00aeef'],
        ['property', 'og:type', $product ? 'product' : 'website'],
        ['property', 'og:site_name', 'קרני תכלת'],
        ['property', 'og:title', $title],
        ['property', 'og:description', $desc],
        ['property', 'og:url', $canonical],
        ['property', 'og:image', $image],
        ['property', 'og:image:width', '1200'],
        ['property', 'og:image:height', '630'],
        ['property', 'og:image:alt', 'קרני תכלת — מכירה מתצוגה של גופי תאורה, עד 70% הנחה'],
        ['property', 'og:locale', 'he_IL'],
        ['name', 'twitter:card', 'summary_large_image'],
        ['name', 'twitter:title', $title],
        ['name', 'twitter:description', $desc],
        ['name', 'twitter:image', $image],
    ];

    echo '<title>' . e($title) . "</title>\n";
    foreach ($tags as [$attr, $key, $value]) {
        echo '<meta ' . $attr . '="' . $key . '" content="' . e($value) . "\">\n";
    }
    echo '<link rel="canonical" href="' . e($canonical) . "\">\n";
}

/** The schema.org graph, as one JSON-LD block. */
function seo_jsonld(array $s, ?array $product, string $ogRev): void
{
    $home  = abs_url();
    $store = $home . '#store';

    // "הנחושת 4, רמת החייל, ת״א" -> street / district / city
    $bits   = array_map('trim', explode(',', (string) $s['address']));
    $street = $bits[0] ?? '';
    $city   = trim((string) end($bits));
    // A search engine matches the town by name, not by the way locals write it.
    $city = ['ת״א' => 'תל אביב', 'ת"א' => 'תל אביב', 'תל אביב יפו' => 'תל אביב',
             'תל-אביב' => 'תל אביב', 'ת״א-יפו' => 'תל אביב'][$city] ?? $city;

    $graph = [
        [
            '@type'      => 'WebSite',
            '@id'        => $home . '#website',
            'url'        => $home,
            'name'       => 'קרני תכלת',
            'inLanguage' => 'he-IL',
            'publisher'  => ['@id' => $store],
        ],
        [
            '@type'      => 'HomeGoodsStore',
            '@id'        => $store,
            'name'       => 'קרני תכלת',
            'description' => $s['description'],
            'url'        => $home,
            'image'      => abs_url($ogRev),
            'telephone'  => $s['phone'],
            'priceRange' => '₪₪',
            'currenciesAccepted' => 'ILS',
            'address'    => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $street,
                'addressLocality' => $city,
                'addressCountry'  => 'IL',
            ]),
            'areaServed' => ['@type' => 'Country', 'name' => 'ישראל'],
        ],
    ];

    if ($product) {
        $url = product_url($product);
        $graph[] = [
            '@type'       => 'ItemPage',
            '@id'         => $url . '#page',
            'url'         => $url,
            'name'        => product_title($product, $s),
            'description' => product_description($product, $s),
            'isPartOf'    => ['@id' => $home . '#website'],
            'inLanguage'  => 'he-IL',
        ];
        $graph[] = [
            '@type'            => 'BreadcrumbList',
            'itemListElement'  => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'מכירה מתצוגה', 'item' => $home],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $product['name']],
            ],
        ];
        $offer = array_filter([
            '@type'         => 'Offer',
            'url'           => $url,
            'priceCurrency' => 'ILS',
            'price'         => !empty($product['price_after']) ? (string) (int) $product['price_after'] : null,
            'availability'  => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'priceValidUntil' => date('Y-m-d', strtotime('+90 days')),
            'seller'        => ['@id' => $store],
        ], static fn($v) => $v !== null);
        $graph[] = array_filter([
            '@type'       => 'Product',
            '@id'         => $url . '#product',
            'name'        => (string) $product['name'],
            'sku'         => (string) ($product['sku'] ?? '') ?: null,
            'mpn'         => (string) ($product['sku'] ?? '') ?: null,
            'category'    => 'גופי תאורה',
            'description' => product_description($product, $s),
            'image'       => !empty($product['image'])
                ? [abs_url(UPLOAD_URL . '/' . rawurlencode((string) $product['image']))] : null,
            'offers'      => !empty($product['price_after']) ? $offer : null,
        ], static fn($v) => $v !== null);
    } else {
        $rows = products_indexed();
        $graph[] = [
            '@type'       => 'CollectionPage',
            '@id'         => $home . '#page',
            'url'         => $home,
            'name'        => $s['site_title'],
            'description' => $s['description'],
            'isPartOf'    => ['@id' => $home . '#website'],
            'about'       => ['@id' => $store],
            'inLanguage'  => 'he-IL',
        ];
        // Google's pattern for a listing page: positions and links here, the
        // full Product on each item's own page.
        $items = [];
        foreach ($rows as $i => $p) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => product_url($p)];
        }
        $graph[] = [
            '@type'           => 'ItemList',
            '@id'             => $home . '#catalog',
            'name'            => 'גופי תאורה במכירת חיסול',
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
    }

    echo '<script type="application/ld+json">'
        . json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "</script>\n";
}

/**
 * Keep robots.txt and sitemap.xml on disk as plain static files, rewritten when
 * the catalogue changes or the site answers on a new host. Serving them from
 * PHP would need a rewrite rule that does not survive a move between servers.
 */
function seo_refresh_static(): void
{
    $sitemap  = BASE . '/sitemap.xml';
    $robots   = BASE . '/robots.txt';
    $catalogue = DATA_DIR . '/products.json';
    $blocked  = !empty(settings()['noindex']);

    // Stale against the catalogue, the settings, this file (so a deploy that
    // changes what these say rewrites them) or the host the site answers on.
    $newest = max(
        is_file($catalogue) ? filemtime($catalogue) : 0,
        is_file(SETTINGS_FILE) ? filemtime(SETTINGS_FILE) : 0,
        filemtime(__FILE__)
    );
    $fresh = is_file($sitemap) && is_file($robots)
        && min(filemtime($sitemap), filemtime($robots)) >= $newest
        && str_contains((string) @file_get_contents($sitemap, false, null, 0, 512), origin() . '/');
    if ($fresh) {
        return;
    }

    $stamp = date('c', is_file($catalogue) ? (int) filemtime($catalogue) : time());
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $xml .= '  <url><loc>' . e(abs_url()) . '</loc><lastmod>' . $stamp
          . '</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>' . "\n";
    foreach (products_indexed() as $p) {
        $xml .= '  <url><loc>' . e(product_url($p)) . '</loc><lastmod>' . $stamp
              . '</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";

    // While the site is blocked the sitemap is still written, so it is ready the
    // moment the switch is turned off; robots.txt is what keeps crawlers away.
    // ASCII only: this file is read by machines, and a comment is not worth an
    // encoding question.
    $txt = $blocked
        ? "# Blocked from search on purpose. Turn off the indexing block in /admin/settings.php.\n"
          . "User-agent: *\n"
          . "Disallow: /\n"
        : "User-agent: *\n"
          . "Allow: /\n"
          . "Disallow: /admin/\n"
          . "Disallow: /api/\n"
          . "Disallow: /inc/\n"
          . "Disallow: /data/\n"
          . "Disallow: /tools/\n\n"
          . 'Sitemap: ' . abs_url('sitemap.xml') . "\n";

    @file_put_contents($sitemap, $xml, LOCK_EX);
    @file_put_contents($robots, $txt, LOCK_EX);
}
