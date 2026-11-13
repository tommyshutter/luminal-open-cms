<?php
/**
 * AffiliateProducts API
 * @file admin/modules/AffiliateProducts/api.php
 *
 * Actions: list_products, get_product, save_product, delete_product,
 *          reorder_products, save_config, get_config, parse_url,
 *          ai_discover, ai_approve
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

header('Content-Type: application/json; charset=utf-8');

/* ── helpers ────────────────────────────────────────────────────────── */

function ap_json(bool $ok, $data = null, string $msg = ''): never {
    echo json_encode(['ok' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

function ap_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data/AffiliateProducts';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @chown($dir, 'www-data'); @chgrp($dir, 'www-data'); }
    return $dir;
}

function ap_load_products(): array {
    $file = ap_data_dir() . '/products.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ap_save_products(array $products): void {
    $file = ap_data_dir() . '/products.json';
    file_put_contents($file, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
}

function ap_load_config(): array {
    $file = ap_data_dir() . '/config.json';
    $defaults = [
        // Amazon Creators API (v3.1 — LwA OAuth2)
        'amazon_creators_client_id'     => '',
        'amazon_creators_client_secret' => '',
        'amazon_associate_tag'          => '',   // per-site store / tracking ID
        'amazon_marketplace'            => 'www.amazon.com',
        // Legacy PA-API v5 (deprecated May 2026)
        'amazon_access_key' => '',
        'amazon_secret_key' => '',
        'amazon_region'     => 'us-east-1',
        // Walmart
        'walmart_api_key'      => '',
        'walmart_publisher_id' => '',
        'walmart_link_id'      => '',
        // Best Buy
        'bestbuy_api_key'      => '',
        'bestbuy_affiliate_id' => '',
        // General
        'default_affiliate_tag' => '',
        'ai_enabled'            => false,
    ];
    if (!is_file($file)) return $defaults;
    $data = function_exists('cred_load_json') ? cred_load_json($file) : json_decode((string)file_get_contents($file), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function ap_save_config(array $config): void {
    $file = ap_data_dir() . '/config.json';
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
}

function ap_mask(string $val): string {
    if (strlen($val) <= 8) return $val ? '****' : '';
    return substr($val, 0, 4) . str_repeat('*', strlen($val) - 8) . substr($val, -4);
}

function ap_generate_id(): string {
    return 'ap-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/* ── Amazon Store Registry ─────────────────────────────────────────────
 *
 * Central directory of Amazon Associate Store IDs and the sites each
 * one serves. Replaces scattered per-site `amazon_associate_tag` config
 * with a single source of truth that the AgentScheduler pipeline + admin
 * dashboards can read.
 *
 * Schema (store_registry.json):
 *   [
 *     {
 *       "tag": "miam-20",                  // Amazon Store ID, also unique key
 *       "site": "example.com",             // single attached site (one-to-one)
 *       "label": "Miami Tom",              // optional display label
 *       "enabled": true,
 *       "created_at": "...", "updated_at": "..."
 *     }, ...
 *   ]
 *
 * Marketplace assumed www.amazon.com (US). One Store ID maps to one Site.
 */

function ap_registry_file(): string {
    return ap_data_dir() . '/store_registry.json';
}

function ap_load_registry(): array {
    $f = ap_registry_file();
    if (!is_file($f)) return [];
    $data = json_decode((string)file_get_contents($f), true);
    if (!is_array($data)) return [];
    $migrated = [];
    $needsMigration = false;
    foreach ($data as $row) {
        // Legacy multi-site format → one row per site
        if (isset($row['sites']) && is_array($row['sites'])) {
            $needsMigration = true;
            $sites = array_filter(array_map('trim', $row['sites']));
            if (empty($sites)) {
                $migrated[] = ap_normalize_store([
                    'tag'   => $row['associate_tag'] ?? $row['key'] ?? '',
                    'site'  => '',
                    'label' => $row['label'] ?? '',
                    'enabled' => $row['enabled'] ?? true,
                ]);
            } else {
                foreach ($sites as $site) {
                    $migrated[] = ap_normalize_store([
                        'tag'   => $row['associate_tag'] ?? $row['key'] ?? '',
                        'site'  => $site,
                        'label' => $row['label'] ?? '',
                        'enabled' => $row['enabled'] ?? true,
                    ]);
                }
            }
        } else {
            // Default-fill page_pattern for older single-site records
            if (!array_key_exists('page_pattern', $row)) {
                $needsMigration = true;
            }
            $migrated[] = ap_normalize_store($row);
        }
    }
    if ($needsMigration) ap_save_registry($migrated);
    return $migrated;
}

function ap_save_registry(array $stores): void {
    $f = ap_registry_file();
    file_put_contents($f, json_encode(array_values($stores), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chown($f, 'www-data');
    @chgrp($f, 'www-data');
}

function ap_resolve_store_for_domain(string $domain): ?array {
    // Site default = the entry with empty page_pattern for this domain
    return ap_resolve_store_for_url($domain, '/__site_default__');
}

function ap_normalize_store(array $in): array {
    $now = date('c');
    // Accept legacy keys: associate_tag, key, sites[]
    $tag = trim($in['tag'] ?? $in['associate_tag'] ?? $in['key'] ?? '');
    $tag = strtolower($tag);
    $site = trim($in['site'] ?? '');
    if (!$site && !empty($in['sites']) && is_array($in['sites'])) {
        $site = (string)($in['sites'][0] ?? '');
    }
    // Page pattern — empty means "site default", otherwise a glob like /articles/*
    $pattern = trim($in['page_pattern'] ?? '');
    if ($pattern !== '' && $pattern[0] !== '/') $pattern = '/' . $pattern;
    return [
        'tag'          => $tag,
        'site'         => strtolower($site),
        'page_pattern' => $pattern,
        'label'        => trim($in['label'] ?? ''),
        'enabled'      => array_key_exists('enabled', $in) ? (bool)$in['enabled'] : true,
        'created_at'   => $in['created_at'] ?? $now,
        'updated_at'   => $now,
    ];
}

/**
 * Resolve store for a given (site, path). Page-pattern matches win over the
 * site default. Specificity: longest matching pattern wins.
 */
function ap_resolve_store_for_url(string $site, string $path = '/'): ?array {
    $site = strtolower(trim($site));
    if (!$site) return null;
    if ($path === '') $path = '/';

    $registry = ap_load_registry();
    $best = null; $bestLen = -1;
    $siteDefault = null;

    foreach ($registry as $s) {
        if (empty($s['enabled'])) continue;
        if (strtolower($s['site'] ?? '') !== $site) continue;
        $pat = trim($s['page_pattern'] ?? '');
        if ($pat === '') {
            $siteDefault = $s;
            continue;
        }
        if (fnmatch($pat, $path) && strlen($pat) > $bestLen) {
            $best = $s;
            $bestLen = strlen($pat);
        }
    }
    return $best ?: $siteDefault;
}

/**
 * Sum clicks for a given site over the last $days days.
 * Reads the site's referral_log/daily.json (lives next to its products.json).
 */
function ap_clicks_for_site(string $site, int $days = 30): int {
    $site = strtolower(trim($site));
    if (!$site) return 0;
    $candidates = [
        "/var/www/vhosts/{$site}/admin/data/AffiliateProducts/referral_log/daily.json",
        ap_data_dir() . '/../AgentScheduler/staging/' . $site . '/AffiliateProducts/referral_log/daily.json',
    ];
    foreach ($candidates as $f) {
        if (!is_file($f)) continue;
        $data = json_decode((string)file_get_contents($f), true);
        if (!is_array($data)) continue;
        $cutoff = date('Y-m-d', strtotime("-" . max(1, $days - 1) . " days"));
        $sum = 0;
        foreach ($data as $day => $rec) {
            if ($day < $cutoff) continue;
            $sum += (int)($rec['clicks'] ?? 0);
        }
        return $sum;
    }
    return 0;
}

function ap_products_for_site(string $site): int {
    [$products] = ap_load_site_products($site);
    return count($products);
}

/**
 * Load products.json for a remote site, returning [products[], sourcePath].
 * Looks in the live vhost first, then the hub-side staging mirror.
 * Returns [[], ''] if no file found.
 */
function ap_load_site_products(string $site): array {
    $site = strtolower(trim($site));
    if (!$site) return [[], ''];
    $candidates = [
        "/var/www/vhosts/{$site}/admin/data/AffiliateProducts/products.json",
        ap_data_dir() . '/../AgentScheduler/staging/' . $site . '/AffiliateProducts/products.json',
    ];
    foreach ($candidates as $cf) {
        if (is_file($cf)) {
            $arr = json_decode((string)file_get_contents($cf), true);
            if (is_array($arr)) return [$arr, $cf];
        }
    }
    return [[], ''];
}

/**
 * Per-site veto list — products the operator has permanently rejected.
 * Survives product deletion + pipeline runs. Pipeline reads this and:
 *   - Adds vetoed ASINs to dedup/reject set
 *   - Includes vetoed titles in the AI's "AVOID" examples
 * Schema: [{asin, title, url, vetoed_at, reason}, ...]
 *
 * Stored in BOTH the hub-side staging dir (so pipeline can read it on hub)
 * AND mirrored to the spoke (so it's part of site backups).
 */
function ap_veto_file(string $site): string {
    $site = strtolower(trim($site));
    return ap_data_dir() . '/../AgentScheduler/staging/' . $site . '/AffiliateProducts/veto_list.json';
}

function ap_load_veto(string $site): array {
    $f = ap_veto_file($site);
    if (!is_file($f)) return [];
    $arr = json_decode((string)file_get_contents($f), true);
    return is_array($arr) ? $arr : [];
}

function ap_save_veto(string $site, array $list): void {
    $f = ap_veto_file($site);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents($f, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chown($f, 'www-data'); @chgrp($f, 'www-data');
}

/* ── Amazon CDN / image validation ───────────────────────────────────
 *
 * Affiliate products MUST have a real image URL served by Amazon's CDN.
 * No placeholders, no hallucinations. If we can't resolve a real image,
 * we refuse to save the product.
 */

function ap_is_amazon_cdn(string $url): bool {
    if (!$url) return false;
    $h = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if (!$h) return false;
    static $allow = [
        'm.media-amazon.com',
        'images-na.ssl-images-amazon.com',
        'images-eu.ssl-images-amazon.com',
        'images-amazon.com',
        'ecx.images-amazon.com',
        'images-fe.ssl-images-amazon.com',
    ];
    foreach ($allow as $a) {
        if ($h === $a || str_ends_with($h, '.' . $a)) return true;
    }
    return false;
}

function ap_head_check_image(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LuminalCMS/1.0)',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $size = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    // Amazon serves a 43-byte placeholder for invalid ASINs at HTTP 200.
    // Real product images are 5KB+. Anything under 500 bytes is suspect.
    $ok = $code >= 200 && $code < 400
          && stripos((string)$type, 'image/') === 0
          && $size >= 500;
    return [
        'ok'   => $ok,
        'code' => $code,
        'type' => $type,
        'size' => $size,
    ];
}

function ap_fetch_url(string $url, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
        ],
    ]);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

/**
 * Build a deterministic Amazon CDN image URL from an ASIN.
 *
 * Amazon hosts a canonical product image at a predictable path keyed only on
 * ASIN. This sidesteps scraping (Amazon anti-bots most server-side fetches)
 * and gives us a guaranteed-real provider image with no hallucinations.
 *
 * The .01.LZZZZZZZ.jpg suffix returns the largest available "main" view.
 */
function ap_amazon_canonical_image(string $asin): string {
    $asin = strtoupper(trim($asin));
    if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) return '';
    return "https://images-na.ssl-images-amazon.com/images/P/{$asin}.01.LZZZZZZZ.jpg";
}

/**
 * Best-effort extraction of a product title from an Amazon URL slug.
 * URL pattern: https://www.amazon.com/Apple-AirPods-Pro/dp/B07ZPC9QD4
 *                                      ^^^^^^^^^^^^^^^^
 * The slug between domain and /dp/ is dash-separated title words.
 */
function ap_amazon_title_from_url(string $url): string {
    if (!preg_match('#amazon\.[^/]+/([^/]+)/dp/#i', $url, $m)) return '';
    $slug = urldecode($m[1]);
    if ($slug === 'dp' || strlen($slug) < 3) return '';
    // De-dash, title-case
    $words = preg_split('/[-_]+/', $slug);
    $words = array_map(fn($w) => ucfirst(strtolower($w)), array_filter($words));
    return implode(' ', $words);
}

/**
 * Tri-state availability check: 'unavailable' | 'available' | 'unknown'
 *
 *   'unavailable' = Amazon's product page contains an unavailability phrase,
 *                   OR the deterministic image returns the 43-byte placeholder
 *                   (delisted/invalid ASIN).
 *   'available'   = product page loaded with a buy-box marker (Add to Cart,
 *                   buybox, priceblock, etc.) and no unavailability phrase.
 *   'unknown'     = Amazon blocked the fetch (503, robot check, or empty buy
 *                   signals). DO NOT clear an existing unavailable flag in
 *                   this state — we just don't know.
 */
function ap_check_amazon_availability(string $url, string $asin): string {
    if (!$url) return 'unknown';

    $unavailablePhrases = [
        'Currently unavailable',
        'Temporarily out of stock',
        'Out of stock',
        "We don't know when or if this item will be back in stock",
        'This item is no longer available',
        'has been discontinued',
        'Sorry, this item is unavailable',
        'Currently out of stock',
    ];
    $availSignals = [
        'add-to-cart-button',
        'buy-now-button',
        'priceblock_ourprice',
        'apex_desktop',
        'corePriceDisplay_desktop',
        'a-button-buynow',
        'buyboxBlockClass',
    ];
    $blockSignals = [
        '503 - Service Unavailable',
        '<title>Robot Check',
        'Sorry, we just need to make sure',
        '/errors/validateCaptcha',
    ];

    $fetched = ap_fetch_url($url, 12);
    $body = (string)($fetched['body'] ?? '');

    if ($fetched['code'] === 200 && $body) {
        // First check for anti-bot markers — if blocked, treat as unknown
        foreach ($blockSignals as $b) {
            if (stripos($body, $b) !== false) {
                return ap_check_via_image_fallback($asin);
            }
        }
        // Unavailability wins over anything else
        foreach ($unavailablePhrases as $phrase) {
            if (stripos($body, $phrase) !== false) return 'unavailable';
        }
        // Look for buy-box markers to confirm "available"
        foreach ($availSignals as $a) {
            if (stripos($body, $a) !== false) return 'available';
        }
        // Page loaded but no clear signal — treat as unknown rather than claim available
        return ap_check_via_image_fallback($asin);
    }

    // Fetch failed — fall back to image probe
    return ap_check_via_image_fallback($asin);
}

function ap_check_via_image_fallback(string $asin): string {
    if (!$asin) return 'unknown';
    $img = ap_amazon_canonical_image($asin);
    if (!$img) return 'unknown';
    $h = ap_head_check_image($img);
    // 43-byte placeholder GIF = invalid/delisted ASIN
    if (!$h['ok'] && $h['size'] > 0 && $h['size'] < 500) return 'unavailable';
    if ($h['ok']) return 'unknown';   // image works but we can't confirm purchasability
    return 'unknown';
}

// Back-compat shim
function ap_check_amazon_unavailable(string $url, string $asin): bool {
    return ap_check_amazon_availability($url, $asin) === 'unavailable';
}

function ap_extract_amazon_meta(string $html): array {
    $out = ['title' => '', 'image' => '', 'price' => '', 'rating' => '', 'asin' => ''];

    // Image extraction — Amazon doesn't expose og:image anymore. Try in order:
    //   1. "hiRes":"https://..." (gallery JSON, highest quality)
    //   2. data-a-dynamic-image attribute (HTML-encoded JSON map: url => [w,h])
    //   3. og:image (legacy / some categories)
    if (preg_match('/"hiRes"\s*:\s*"(https:\\\\\/\\\\\/[^"]+)"/i', $html, $m)) {
        $out['image'] = stripslashes($m[1]);
    } elseif (preg_match('/"hiRes"\s*:\s*"(https:\/\/[^"]+)"/i', $html, $m)) {
        $out['image'] = $m[1];
    }
    if (!$out['image'] && preg_match('/data-a-dynamic-image=["\']({[^"\']+})["\']/i', $html, $m)) {
        // Decode HTML entities and parse the JSON map
        $jsonStr = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $map = json_decode($jsonStr, true);
        if (is_array($map)) {
            // Pick the largest image (highest first dim)
            $best = ''; $bestSize = 0;
            foreach ($map as $url => $dim) {
                $size = is_array($dim) ? (int)($dim[0] ?? 0) : 0;
                if ($size > $bestSize) { $best = $url; $bestSize = $size; }
            }
            if ($best) $out['image'] = $best;
        }
    }
    if (!$out['image'] && preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $out['image'] = trim($m[1]);
    }
    // og:title (strip "Amazon.com:" prefix)
    if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        $title = preg_replace('/^Amazon\.com\s*:\s*/i', '', $title);
        $out['title'] = $title;
    }
    // Fallback to <title> tag
    if (!$out['title'] && preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        $title = preg_replace('/\s*-\s*Amazon\.com.*$/i', '', $title);
        $title = preg_replace('/^Amazon\.com\s*:\s*/i', '', $title);
        $out['title'] = trim($title);
    }
    // ASIN from canonical or /dp/ in any URL on the page
    if (preg_match('#<link\s+rel=["\']canonical["\']\s+href=["\'][^"\']*?/dp/([A-Z0-9]{10})#i', $html, $m)) {
        $out['asin'] = strtoupper($m[1]);
    } elseif (preg_match('#"asin"\s*:\s*"([A-Z0-9]{10})"#i', $html, $m)) {
        $out['asin'] = strtoupper($m[1]);
    }
    // Price: try JSON-LD first
    if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\']>(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[1] as $jsonStr) {
            $j = json_decode(trim($jsonStr), true);
            if (!is_array($j)) continue;
            // Walk for offers.price
            $stack = [$j];
            while ($stack) {
                $cur = array_pop($stack);
                if (!is_array($cur)) continue;
                if (isset($cur['offers']) && is_array($cur['offers'])) {
                    $off = $cur['offers'];
                    if (isset($off['price'])) { $out['price'] = '$' . $off['price']; break 2; }
                    if (isset($off[0]['price'])) { $out['price'] = '$' . $off[0]['price']; break 2; }
                }
                if (isset($cur['aggregateRating']['ratingValue'])) {
                    $out['rating'] = (string)$cur['aggregateRating']['ratingValue'];
                }
                foreach ($cur as $v) if (is_array($v)) $stack[] = $v;
            }
        }
    }
    // Fallback price scrape
    if (!$out['price']) {
        if (preg_match('/<span class=["\']a-offscreen["\']>(\$[\d.,]+)<\/span>/i', $html, $m)) {
            $out['price'] = $m[1];
        }
    }
    return $out;
}

/* ── URL parsing ────────────────────────────────────────────────────── */

function ap_parse_affiliate_url(string $url): array {
    $parsed = parse_url($url);
    $host = strtolower($parsed['host'] ?? '');
    $path = $parsed['path'] ?? '';
    $result = ['url' => $url, 'source' => 'generic', 'asin' => '', 'affiliate_tag' => ''];

    // Amazon
    if (preg_match('/(amazon\.(com|co\.uk|ca|de|fr|it|es|com\.au|co\.jp|in|com\.br|com\.mx)|amzn\.(to|com))/', $host)) {
        $result['source'] = 'amazon';
        // Extract ASIN from /dp/XXXX or /gp/product/XXXX
        if (preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $path, $m)) {
            $result['asin'] = strtoupper($m[1]);
        }
        // Extract tag= parameter
        parse_str($parsed['query'] ?? '', $qs);
        if (!empty($qs['tag'])) {
            $result['affiliate_tag'] = $qs['tag'];
        }
    }
    // Walmart
    elseif (strpos($host, 'walmart.com') !== false) {
        $result['source'] = 'walmart';
    }
    // Best Buy
    elseif (strpos($host, 'bestbuy.com') !== false) {
        $result['source'] = 'bestbuy';
    }
    // Target
    elseif (strpos($host, 'target.com') !== false) {
        $result['source'] = 'target';
    }

    return $result;
}

/* ── Amazon Creators API v3.1 (LwA OAuth2) ─────────────────────────── */

function ap_amazon_token(?array &$diag = null): string {
    $cfg       = ap_load_config();
    $clientId  = $cfg['amazon_creators_client_id']     ?? '';
    $clientSec = $cfg['amazon_creators_client_secret'] ?? '';
    if (!$clientId || !$clientSec) {
        if ($diag !== null) $diag = ['error' => 'Client ID or Secret is empty in config'];
        return '';
    }

    $cacheFile = ap_data_dir() . '/amazon_token.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['access_token'] ?? '';
        }
    }

    // The Amazon Creators API requires a specific scope on the LwA security
    // profile. Try a few known scopes — the right one depends on which
    // affiliate program(s) the LwA profile is approved for.
    $scopesToTry = ['creators-api::access', 'affiliate::associates', 'affiliate-program::api'];
    $lastError = '';
    $lastBody = '';
    $lastCode = 0;

    foreach ($scopesToTry as $scope) {
        $ch = curl_init('https://api.amazon.com/auth/o2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSec,
                'scope'         => $scope,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200) {
            $tok = json_decode($body, true);
            if (!empty($tok['access_token'])) {
                $tok['expires_at'] = time() + (int)($tok['expires_in'] ?? 3600);
                $tok['scope_used'] = $scope;
                @file_put_contents($cacheFile, json_encode($tok));
                @chmod($cacheFile, 0600);
                if ($diag !== null) $diag = ['scope_used' => $scope];
                return $tok['access_token'];
            }
        }

        $lastCode = $code;
        $lastBody = $body;
        $j = json_decode($body, true);
        if (is_array($j)) {
            $lastError = ($j['error'] ?? '') . ': ' . ($j['error_description'] ?? '');
        } else {
            $lastError = "HTTP $code";
        }
    }

    if ($diag !== null) {
        $diag = [
            'error'      => $lastError,
            'http_code'  => $lastCode,
            'response'   => substr($lastBody, 0, 400),
            'tried'      => $scopesToTry,
            'hint'       => 'Your LwA Security Profile must be approved for the Creators API scope. Check Amazon Associates Central → Tools → API or contact Amazon Affiliate Support to enable Creators API access for client ID ' . substr($clientId, 0, 20) . '...',
        ];
    }
    return '';
}

function ap_amazon_api(string $endpoint, array $payload): array {
    $token = ap_amazon_token();
    if (!$token) return ['error' => 'No Amazon access token — check Creators API credentials'];

    $url = 'https://affiliate-program.amazon.com/creatorsapi/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode($body, true);
    if ($code !== 200) {
        return ['error' => 'Amazon API error ' . $code, 'raw' => substr($body, 0, 300)];
    }
    return $data ?: ['error' => 'Empty response'];
}

function ap_amazon_normalize_item(array $item, string $tag): array {
    $info  = $item['ItemInfo']  ?? [];
    $imgs  = $item['Images']    ?? [];
    $offer = $item['Offers']['Listings'][0] ?? [];
    $links = $item['DetailPageURL'] ?? '';

    $price = '';
    if (!empty($offer['Price']['DisplayAmount'])) {
        $price = $offer['Price']['DisplayAmount'];
    }
    $image = $imgs['Primary']['Medium']['URL'] ?? $imgs['Primary']['Large']['URL'] ?? '';
    $title = $info['Title']['DisplayValue'] ?? '';
    $asin  = $item['ASIN'] ?? '';

    // Build affiliate URL with tag
    if ($links && $tag) {
        $sep = str_contains($links, '?') ? '&' : '?';
        $links .= $sep . 'tag=' . urlencode($tag);
    }

    return [
        'title'         => $title,
        'description'   => '',
        'price'         => $price,
        'image'         => $image,
        'url'           => $links,
        'source'        => 'amazon',
        'asin'          => $asin,
        'affiliate_tag' => $tag,
        'rating'        => (string)($item['CustomerReviews']['StarRating']['Value'] ?? ''),
    ];
}

/* ── route ──────────────────────────────────────────────────────────── */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    /* ── list products ──────────────────────────────────────────────── */
    case 'list_products':
        $products = ap_load_products();
        $category = trim($_GET['category'] ?? $_POST['category'] ?? '');
        if ($category !== '') {
            $products = array_values(array_filter($products, fn($p) => ($p['category'] ?? '') === $category));
        }
        // Sort by sort_order
        usort($products, fn($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));
        // Collect unique categories
        $allProducts = ap_load_products();
        $categories = array_values(array_unique(array_filter(array_column($allProducts, 'category'))));
        sort($categories);
        ap_json(true, ['products' => $products, 'categories' => $categories]);

    /* ── get single product ─────────────────────────────────────────── */
    case 'get_product':
        $id = trim($_GET['id'] ?? $_POST['id'] ?? '');
        if (!$id) ap_json(false, null, 'Missing product ID');
        $products = ap_load_products();
        foreach ($products as $p) {
            if ($p['id'] === $id) ap_json(true, $p);
        }
        ap_json(false, null, 'Product not found');

    /* ── save product (create or update) ────────────────────────────── */
    case 'save_product':
        $id = trim($_POST['id'] ?? '');
        $products = ap_load_products();

        $product = [
            'id' => $id ?: ap_generate_id(),
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'price' => trim($_POST['price'] ?? ''),
            'image' => trim($_POST['image'] ?? ''),
            'url' => trim($_POST['url'] ?? ''),
            'source' => trim($_POST['source'] ?? 'generic'),
            'category' => trim($_POST['category'] ?? ''),
            'asin' => trim($_POST['asin'] ?? ''),
            'affiliate_tag' => trim($_POST['affiliate_tag'] ?? ''),
            'rating' => trim($_POST['rating'] ?? ''),
            'sort_order' => (int)($_POST['sort_order'] ?? 999),
            'added_at' => '',
            'ai_generated' => (bool)($_POST['ai_generated'] ?? false),
            'enabled' => ($_POST['enabled'] ?? '1') !== '0',
        ];

        if (!$product['title']) ap_json(false, null, 'Title is required');
        if (!$product['url']) ap_json(false, null, 'URL is required');
        // Hard rule: every product must have a real image — no exceptions.
        // Image must be set AND must be on Amazon CDN (or another retailer's CDN — same idea).
        if (!$product['image']) {
            ap_json(false, null, 'Image is required. Products must carry a real provider image — no exceptions.');
        }
        if ($product['source'] === 'amazon' && !ap_is_amazon_cdn($product['image'])) {
            ap_json(false, null, 'Amazon products must use an Amazon CDN image. Refusing: ' . $product['image']);
        }

        // Upsert
        $found = false;
        foreach ($products as $i => $p) {
            if ($p['id'] === $product['id']) {
                $product['added_at'] = $p['added_at'] ?? date('c');
                $products[$i] = $product;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $product['added_at'] = date('c');
            $products[] = $product;
        }

        ap_save_products($products);
        ap_json(true, $product, $found ? 'Product updated' : 'Product created');

    /* ── delete product ─────────────────────────────────────────────── */
    case 'delete_product':
        $id = trim($_POST['id'] ?? '');
        if (!$id) ap_json(false, null, 'Missing product ID');
        $products = ap_load_products();
        $before = count($products);
        $products = array_values(array_filter($products, fn($p) => $p['id'] !== $id));
        if (count($products) === $before) ap_json(false, null, 'Product not found');
        ap_save_products($products);
        ap_json(true, null, 'Product deleted');

    /* ── reorder products ───────────────────────────────────────────── */
    case 'reorder_products':
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) ap_json(false, null, 'Invalid order data');
        $products = ap_load_products();
        foreach ($products as &$p) {
            $idx = array_search($p['id'], $order, true);
            $p['sort_order'] = $idx !== false ? $idx : 999;
        }
        unset($p);
        usort($products, fn($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));
        ap_save_products($products);
        ap_json(true, null, 'Order updated');

    /* ── get config ─────────────────────────────────────────────────── */
    case 'get_config':
        $config = ap_load_config();
        $masked = $config;
        $masked['amazon_creators_client_id']     = ap_mask($config['amazon_creators_client_id']);
        $masked['amazon_creators_client_secret'] = ap_mask($config['amazon_creators_client_secret']);
        $masked['amazon_access_key']             = ap_mask($config['amazon_access_key']);
        $masked['amazon_secret_key']             = ap_mask($config['amazon_secret_key']);
        $masked['walmart_api_key']               = ap_mask($config['walmart_api_key']);
        $masked['bestbuy_api_key']               = ap_mask($config['bestbuy_api_key']);
        ap_json(true, $masked);

    /* ── save config ────────────────────────────────────────────────── */
    case 'save_config':
        $config = ap_load_config();
        // Sensitive fields (mask-aware — never overwrite with a masked value)
        $sensitiveFields = [
            'amazon_creators_client_id', 'amazon_creators_client_secret',
            'amazon_access_key', 'amazon_secret_key',
            'walmart_api_key', 'bestbuy_api_key',
        ];
        // Plain text fields
        $plainFields = [
            'amazon_associate_tag', 'amazon_marketplace', 'amazon_region',
            'walmart_publisher_id', 'walmart_link_id',
            'bestbuy_affiliate_id', 'default_affiliate_tag',
        ];
        foreach ($sensitiveFields as $f) {
            $val = trim($_POST[$f] ?? '');
            if ($val !== '' && !preg_match('/^\*+$/', $val) && strpos($val, '****') === false) {
                $config[$f] = $val;
            } elseif ($val === '' && isset($_POST[$f])) {
                $config[$f] = '';
            }
        }
        foreach ($plainFields as $f) {
            if (isset($_POST[$f])) $config[$f] = trim($_POST[$f]);
        }
        $config['ai_enabled'] = ($_POST['ai_enabled'] ?? '0') === '1';
        // Clear cached token if credentials changed
        @unlink(ap_data_dir() . '/amazon_token.json');
        ap_save_config($config);
        ap_json(true, null, 'Configuration saved');

    /* ── parse URL ──────────────────────────────────────────────────── */
    case 'parse_url':
        $url = trim($_POST['url'] ?? '');
        if (!$url) ap_json(false, null, 'No URL provided');
        if (!filter_var($url, FILTER_VALIDATE_URL)) ap_json(false, null, 'Invalid URL');
        $result = ap_parse_affiliate_url($url);
        // Fill default affiliate tag if none found
        if (!$result['affiliate_tag']) {
            $config = ap_load_config();
            $result['affiliate_tag'] = $config['default_affiliate_tag'];
        }
        ap_json(true, $result);

    /* ── AI discover ────────────────────────────────────────────────── */
    case 'ai_discover':
        $prompt = trim($_POST['prompt'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $count = max(1, min(20, (int)($_POST['count'] ?? 5)));
        $providerKey = trim($_POST['provider'] ?? '');

        if (!$prompt) ap_json(false, null, 'Prompt is required');

        $regFile = SITE_ROOT . '/admin/modules/AIResources/providers/ProviderRegistry.php';
        if (!is_file($regFile)) ap_json(false, null, 'AIResources module not available');

        require_once $regFile;
        // Also load any provider dependencies (GoogleProvider, ClaudeProvider, etc.)
        foreach (glob(dirname($regFile) . '/*.php') as $_pf) { @require_once $_pf; }
        $reg = new ProviderRegistry(SITE_ROOT . '/admin/data/AIResources/config.json');
        $provider = $providerKey ? $reg->getProviderByKey($providerKey) : $reg->getActiveProvider();
        if (!$provider) ap_json(false, null, 'No AI provider configured');

        $systemPrompt = <<<SYS
You are a product research assistant. Return ONLY valid JSON — no markdown, no code fences, no explanatory text.
Return a JSON array of product objects. Each object must have these exact fields:
- title (string): Product name
- description (string): 1-2 sentence description
- price (string): Estimated price with dollar sign, e.g. "$49.99"
- url (string): Direct product URL on Amazon, Walmart, Best Buy, or the manufacturer's site
- source (string): One of "amazon", "walmart", "bestbuy", or "generic"
- rating (string): Estimated rating out of 5, e.g. "4.5"

Return exactly $count products. Focus on real, currently available products with accurate URLs.
SYS;

        $userMsg = $prompt;
        if ($category) $userMsg .= "\n\nCategory: $category";
        $userMsg .= "\n\nReturn exactly $count products as a JSON array.";

        try {
            $result = $provider->generate($systemPrompt, $userMsg, [], null);
            $content = $result['content'] ?? '';
            // Strip markdown fences if present
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $suggestions = json_decode($content, true);
            if (!is_array($suggestions)) {
                ap_json(false, null, 'AI returned invalid JSON. Raw: ' . substr($content, 0, 200));
            }
            ap_json(true, [
                'suggestions' => $suggestions,
                'model' => $result['model'] ?? 'unknown',
                'usage' => $result['usage'] ?? null,
            ]);
        } catch (\Throwable $e) {
            ap_json(false, null, 'AI error: ' . $e->getMessage());
        }

    /* ── AI approve (save AI suggestions) ───────────────────────────── */
    case 'ai_approve':
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || empty($items)) ap_json(false, null, 'No items to approve');

        $products = ap_load_products();
        $category = trim($_POST['category'] ?? '');
        $config = ap_load_config();
        $added = 0;

        foreach ($items as $item) {
            $url = trim($item['url'] ?? '');
            if (!$url || !($item['title'] ?? '')) continue;

            $parsed = ap_parse_affiliate_url($url);
            $product = [
                'id' => ap_generate_id(),
                'title' => trim($item['title'] ?? ''),
                'description' => trim($item['description'] ?? ''),
                'price' => trim($item['price'] ?? ''),
                'image' => '',
                'url' => $url,
                'source' => $parsed['source'],
                'category' => $category ?: trim($item['category'] ?? ''),
                'asin' => $parsed['asin'],
                'affiliate_tag' => $parsed['affiliate_tag'] ?: ($config['default_affiliate_tag'] ?? ''),
                'rating' => trim($item['rating'] ?? ''),
                'sort_order' => count($products) + $added,
                'added_at' => date('c'),
                'ai_generated' => true,
                'enabled' => true,
            ];
            $products[] = $product;
            $added++;
        }

        ap_save_products($products);
        ap_json(true, ['added' => $added], "$added products added");

    /* ── reseller IDs ─────────────────────────────────────────────── */
    case 'get_resellers':
        $rFile = ap_data_dir() . '/resellers.json';
        $resellers = is_file($rFile) ? (json_decode(file_get_contents($rFile), true) ?: []) : [];
        ap_json(true, $resellers);

    case 'save_reseller':
        $rFile = ap_data_dir() . '/resellers.json';
        $resellers = is_file($rFile) ? (json_decode(file_get_contents($rFile), true) ?: []) : [];

        $id   = trim($_POST['reseller_id'] ?? '');
        if (!$id) ap_json(false, null, 'Reseller ID key is required');

        $resellers[$id] = [
            'label'       => trim($_POST['label'] ?? $id),
            'resellerId'  => trim($_POST['reseller_id_value'] ?? ''),
            'referralUrl' => trim($_POST['referral_url'] ?? ''),
            'linkText'    => trim($_POST['link_text'] ?? ''),
            'enabled'     => ($_POST['enabled'] ?? '1') !== '0',
            'updated'     => date('c'),
        ];

        file_put_contents($rFile, json_encode($resellers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($rFile, 0664); @chown($rFile, 'www-data'); @chgrp($rFile, 'www-data');
        ap_json(true, $resellers[$id], 'Reseller saved');

    case 'delete_reseller':
        $rFile = ap_data_dir() . '/resellers.json';
        $resellers = is_file($rFile) ? (json_decode(file_get_contents($rFile), true) ?: []) : [];
        $id = trim($_POST['reseller_id'] ?? '');
        if (!$id || !isset($resellers[$id])) ap_json(false, null, 'Reseller not found');
        unset($resellers[$id]);
        file_put_contents($rFile, json_encode($resellers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($rFile, 0664); @chown($rFile, 'www-data'); @chgrp($rFile, 'www-data');
        ap_json(true, null, 'Reseller deleted');

    /* ── Amazon Creators API — keyword search ──────────────────────── */
    case 'amazon_search':
        $keywords = trim($_POST['keywords'] ?? $_GET['keywords'] ?? '');
        $count    = max(1, min(10, (int)($_POST['count'] ?? 5)));
        if (!$keywords) ap_json(false, null, 'Keywords required');

        $cfg = ap_load_config();
        $tag = $cfg['amazon_associate_tag'] ?? '';
        $mkt = $cfg['amazon_marketplace']   ?? 'www.amazon.com';

        $result = ap_amazon_api('searchItems', [
            'marketplace' => $mkt,
            'partnerTag'  => $tag,
            'keywords'    => $keywords,
            'itemCount'   => $count,
            'resources'   => [
                'ItemInfo.Title',
                'Offers.Listings.Price',
                'Images.Primary.Medium',
                'Images.Primary.Large',
                'DetailPageURL',
                'CustomerReviews.StarRating',
            ],
        ]);

        if (isset($result['error'])) ap_json(false, null, $result['error']);

        $items = $result['SearchResult']['Items'] ?? [];
        ap_json(true, [
            'items'       => array_map(fn($i) => ap_amazon_normalize_item($i, $tag), $items),
            'total_count' => $result['SearchResult']['TotalResultCount'] ?? count($items),
        ]);

    /* ── Amazon Creators API — lookup by ASIN(s) ───────────────────── */
    case 'amazon_get_items':
        $asins = array_filter(array_map('trim', explode(',', $_POST['asins'] ?? $_GET['asins'] ?? '')));
        if (empty($asins)) ap_json(false, null, 'At least one ASIN required');

        $cfg = ap_load_config();
        $tag = $cfg['amazon_associate_tag'] ?? '';
        $mkt = $cfg['amazon_marketplace']   ?? 'www.amazon.com';

        $result = ap_amazon_api('getItems', [
            'marketplace' => $mkt,
            'partnerTag'  => $tag,
            'itemIds'     => array_slice($asins, 0, 10),
            'resources'   => [
                'ItemInfo.Title',
                'Offers.Listings.Price',
                'Images.Primary.Medium',
                'Images.Primary.Large',
                'DetailPageURL',
                'CustomerReviews.StarRating',
            ],
        ]);

        if (isset($result['error'])) ap_json(false, null, $result['error']);

        $items = $result['ItemsResult']['Items'] ?? [];
        ap_json(true, [
            'items' => array_map(fn($i) => ap_amazon_normalize_item($i, $tag), $items),
        ]);

    /* ── Amazon Creators API — test credentials ─────────────────────── */
    case 'amazon_test':
        $diag = null;
        $token = ap_amazon_token($diag);
        if (!$token) {
            $msg = 'Amazon OAuth failed. ' . ($diag['error'] ?? 'unknown error') . ' (HTTP ' . ($diag['http_code'] ?? '?') . '). ' . ($diag['hint'] ?? '');
            ap_json(false, $diag, $msg);
        }
        ap_json(true, ['token_prefix' => substr($token, 0, 12) . '…', 'scope' => $diag['scope_used'] ?? null], 'Amazon credentials OK (scope: ' . ($diag['scope_used'] ?? 'unknown') . ')');

    /* ── referral + pixel stats ─────────────────────────────────────── */
    case 'get_referral_stats':
        $refLog = ap_data_dir() . '/referral_log/daily.json';
        $pixLog = ap_data_dir() . '/pixel_events/daily.json';
        $referrals = is_file($refLog) ? (json_decode((string)file_get_contents($refLog), true) ?: []) : [];
        $pixels    = is_file($pixLog) ? (json_decode((string)file_get_contents($pixLog), true) ?: []) : [];

        // Last 30 days only
        $cutoff = date('Y-m-d', strtotime('-29 days'));
        $referrals = array_filter($referrals, fn($k) => $k >= $cutoff, ARRAY_FILTER_USE_KEY);
        $pixels    = array_filter($pixels,    fn($k) => $k >= $cutoff, ARRAY_FILTER_USE_KEY);

        // Aggregate totals
        $totalClicks    = array_sum(array_column($referrals, 'clicks'));
        $totalPageviews = array_sum(array_column($pixels,    'pageviews'));
        $totalOutbound  = array_sum(array_column($pixels,    'outbound_clicks'));

        // Top products (last 30 days)
        $productTotals = [];
        foreach ($referrals as $day => $d) {
            foreach ($d['products'] ?? [] as $pid => $cnt) {
                $productTotals[$pid] = ($productTotals[$pid] ?? 0) + $cnt;
            }
        }
        arsort($productTotals);
        $topProducts = array_slice($productTotals, 0, 10, true);

        ap_json(true, [
            'referrals'      => $referrals,
            'pixels'         => $pixels,
            'totals'         => [
                'clicks'          => $totalClicks,
                'pageviews'       => $totalPageviews,
                'outbound_clicks' => $totalOutbound,
            ],
            'top_products'   => $topProducts,
        ]);

    /* ── prompt editor: load existing task for a store ──────────────── */
    case 'prompt_load':
        $tag = strtolower(trim($_GET['tag'] ?? $_POST['tag'] ?? ''));
        if (!$tag) ap_json(false, null, 'tag required');

        $registry = ap_load_registry();
        $store = null;
        foreach ($registry as $s) {
            if (strtolower($s['tag'] ?? '') === $tag) { $store = $s; break; }
        }
        if (!$store) ap_json(false, null, 'Store not found in registry');

        // Find linked task — try store.task_id first, then domain match
        $tasksDir = SITE_ROOT . '/admin/data/AgentScheduler/tasks';
        $task = null;

        $explicitId = trim($store['task_id'] ?? '');
        if ($explicitId && is_file("$tasksDir/$explicitId.json")) {
            $task = json_decode((string)file_get_contents("$tasksDir/$explicitId.json"), true);
        }
        if (!$task && !empty($store['site'])) {
            // Look up by target_domain — pick first matching amazon-affil task
            foreach (glob("$tasksDir/amazon-affil-*.json") as $tf) {
                $t = json_decode((string)file_get_contents($tf), true);
                if (!is_array($t)) continue;
                if (strtolower($t['config']['target_domain'] ?? '') === strtolower($store['site'])) {
                    $task = $t;
                    break;
                }
            }
        }

        // Suggested defaults for new tasks
        $defaultId = 'amazon-affil-' . preg_replace('/[^a-z0-9-]/', '', $tag);
        ap_json(true, [
            'store' => $store,
            'task'  => $task,                 // null if no task yet
            'suggested_id' => $defaultId,
        ]);

    /* ── prompt editor: save task ───────────────────────────────────── */
    case 'prompt_save':
        $tag = strtolower(trim($_POST['tag'] ?? ''));
        if (!$tag) ap_json(false, null, 'tag required');

        $registry = ap_load_registry();
        $store = null; $storeIdx = -1;
        foreach ($registry as $i => $s) {
            if (strtolower($s['tag'] ?? '') === $tag) { $store = $s; $storeIdx = $i; break; }
        }
        if (!$store) ap_json(false, null, 'Store not found');
        if (empty($store['site'])) ap_json(false, null, 'Store must be attached to a site first');

        // Build task payload
        $taskId = trim($_POST['task_id'] ?? '') ?: ('amazon-affil-' . preg_replace('/[^a-z0-9-]/', '', $tag));

        $hour = max(0, min(23, (int)($_POST['hour'] ?? 2)));
        $minute = max(0, min(59, (int)($_POST['minute'] ?? 5)));
        $day = strtolower(trim($_POST['day'] ?? 'wednesday'));
        $recurrence = ($_POST['recurrence'] ?? 'recurring') === 'once' ? 'once' : 'recurring';

        $task = [
            'id'         => $taskId,
            'name'       => trim($_POST['name'] ?? "Amazon Affiliate Refresh — {$store['site']} ({$tag})"),
            'pipeline'   => 'affiliate_product_run',
            'enabled'    => ($_POST['enabled'] ?? '1') !== '0',
            'recurrence' => $recurrence,
            'schedule'   => $recurrence === 'recurring'
                ? ['type' => 'weekly', 'day' => $day, 'hour' => $hour, 'minute' => $minute]
                : ['type' => 'once'],
            'config' => [
                'target_domain'    => $store['site'],
                'amazon_tag'       => $tag,                              // explicit override
                'page_url'         => trim($_POST['page_url'] ?? ''),    // for split-test pattern lookup
                'category'         => trim($_POST['category'] ?? 'general'),
                'theme'            => trim($_POST['theme'] ?? ''),
                'search_terms'     => trim($_POST['search_terms'] ?? ''),
                'retailers'        => 'amazon',
                'x_num'            => max(1, min(20, (int)($_POST['x_num'] ?? 12))),
                'min_rating'       => max(0, min(5, (float)($_POST['min_rating'] ?? 4.0))),
                'price_min'        => trim($_POST['price_min'] ?? ''),
                'price_max'        => trim($_POST['price_max'] ?? ''),
                'replace_category' => false,
                'strict_image'     => true,    // hardcoded — see pipeline
                'no_fallback'      => true,
                'prune_old'        => max(0, (int)($_POST['prune_old'] ?? 12)),
            ],
        ];

        // Save via AgentEngine
        require_once SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
        $engine = new \AgentEngine(SITE_ROOT . '/admin/data/AgentScheduler');
        if (!$engine->saveTask($task)) ap_json(false, null, 'AgentEngine saveTask failed');

        // Link store → task
        $registry[$storeIdx]['task_id'] = $taskId;
        $registry[$storeIdx]['updated_at'] = date('c');
        ap_save_registry($registry);

        ap_json(true, ['task' => $engine->getTask($taskId)], 'Task saved');

    /* ── prompt editor: quick prompt-only update ────────────────────── */
    case 'prompt_quick_save':
        $tag    = strtolower(trim($_POST['tag'] ?? ''));
        $theme  = trim($_POST['theme'] ?? '');
        if (!$tag) ap_json(false, null, 'tag required');

        $registry = ap_load_registry();
        $store = null;
        foreach ($registry as $s) {
            if (strtolower($s['tag'] ?? '') === $tag) { $store = $s; break; }
        }
        if (!$store) ap_json(false, null, 'Store not found');
        if (empty($store['site'])) ap_json(false, null, 'Store must be attached to a site first');

        // Find or create task
        require_once SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
        $engine = new \AgentEngine(SITE_ROOT . '/admin/data/AgentScheduler');

        $taskId = trim($store['task_id'] ?? '');
        $task = $taskId ? $engine->getTask($taskId) : null;
        if (!$task) {
            // Look up by domain
            $tasksDir = SITE_ROOT . '/admin/data/AgentScheduler/tasks';
            foreach (glob("$tasksDir/amazon-affil-*.json") as $tf) {
                $t = json_decode((string)file_get_contents($tf), true);
                if (!is_array($t)) continue;
                if (strtolower($t['config']['target_domain'] ?? '') === strtolower($store['site'])) {
                    $task = $t;
                    $taskId = $task['id'];
                    break;
                }
            }
        }
        if (!$task) {
            // Create new with sensible defaults
            $taskId = 'amazon-affil-' . preg_replace('/[^a-z0-9-]/', '', $tag);
            $task = [
                'id' => $taskId,
                'name' => "Amazon Affiliate Refresh — {$store['site']} ({$tag})",
                'pipeline' => 'affiliate_product_run',
                'enabled' => true,
                'recurrence' => 'recurring',
                'schedule' => ['type' => 'weekly', 'day' => 'wednesday', 'hour' => 2, 'minute' => 5],
                'config' => [
                    'target_domain' => $store['site'],
                    'amazon_tag'    => $tag,
                    'category'      => 'general',
                    'retailers'     => 'amazon',
                    'x_num'         => 12,
                    'min_rating'    => 4.0,
                    'replace_category' => false,
                    'strict_image'  => true,
                    'no_fallback'   => true,
                    'prune_old'     => 12,
                ],
            ];
        }

        // Update only the theme
        $task['config']['theme'] = $theme;
        if (!$engine->saveTask($task)) ap_json(false, null, 'saveTask failed');

        // Link store → task
        foreach ($registry as $i => $s) {
            if (strtolower($s['tag'] ?? '') === $tag) {
                $registry[$i]['task_id'] = $taskId;
                $registry[$i]['updated_at'] = date('c');
                ap_save_registry($registry);
                break;
            }
        }
        ap_json(true, ['task_id' => $taskId], 'Prompt saved');

    /* ── sandbox: start a preview run ────────────────────────────────── */
    case 'sandbox_start':
        $tag = strtolower(trim($_POST['tag'] ?? ''));
        if (!$tag) ap_json(false, null, 'tag required');

        $registry = ap_load_registry();
        $store = null;
        foreach ($registry as $s) {
            if (strtolower($s['tag'] ?? '') === $tag) { $store = $s; break; }
        }
        if (!$store) ap_json(false, null, 'Store not found');
        if (empty($store['site'])) ap_json(false, null, 'Store must be attached to a site first');

        require_once SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
        $engine = new \AgentEngine(SITE_ROOT . '/admin/data/AgentScheduler');

        $taskId = trim($store['task_id'] ?? '');
        $task = $taskId ? $engine->getTask($taskId) : null;
        if (!$task) ap_json(false, null, 'No task linked to this store yet — author the prompt first.');

        // Generate a sandbox run id + output path
        $runId   = 'sbx-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $sbxDir  = SITE_ROOT . '/admin/data/AffiliateProducts/sandbox';
        if (!is_dir($sbxDir)) { @mkdir($sbxDir, 0775, true); @chown($sbxDir, 'www-data'); @chgrp($sbxDir, 'www-data'); }
        $sbxFile = "$sbxDir/{$runId}.json";

        // Patch task config with sandbox output path (one-shot, doesn't persist)
        $task['config']['_sandbox_output_path'] = $sbxFile;
        $task['config']['_sandbox_run_id']     = $runId;
        $engine->saveTask($task);   // persist the patched config briefly

        // Spawn bg-runner
        $bgScript = SITE_ROOT . '/admin/modules/AgentScheduler/bg-runner.php';
        $cmd = sprintf('php %s %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($bgScript),
            escapeshellarg($taskId),
            escapeshellarg('normal'),
            escapeshellarg('{}')
        );
        exec($cmd);

        // Write a marker file so status endpoint knows what to poll
        $markerFile = "$sbxDir/{$runId}.marker.json";
        file_put_contents($markerFile, json_encode([
            'run_id'    => $runId,
            'tag'       => $tag,
            'site'      => $store['site'],
            'task_id'   => $taskId,
            'started_at'=> date('c'),
            'output'    => $sbxFile,
        ]));
        @chown($markerFile, 'www-data'); @chgrp($markerFile, 'www-data');

        ap_json(true, ['run_id' => $runId, 'task_id' => $taskId], 'Sandbox run started');

    /* ── sandbox: poll status ───────────────────────────────────────── */
    case 'sandbox_status':
        $runId = trim($_GET['run_id'] ?? $_POST['run_id'] ?? '');
        if (!$runId) ap_json(false, null, 'run_id required');
        $sbxDir  = SITE_ROOT . '/admin/data/AffiliateProducts/sandbox';
        $sbxFile = "$sbxDir/{$runId}.json";
        if (!is_file($sbxFile)) {
            // Still running — check the lock file from AgentScheduler for context
            ap_json(true, ['status' => 'running', 'run_id' => $runId]);
        }
        $data = json_decode((string)file_get_contents($sbxFile), true);
        ap_json(true, ['status' => 'done', 'run_id' => $runId, 'data' => $data]);

    /* ── sandbox: promote a product to live ─────────────────────────── */
    case 'sandbox_promote':
        $runId = trim($_POST['run_id'] ?? '');
        $pid   = trim($_POST['product_id'] ?? '');
        if (!$runId || !$pid) ap_json(false, null, 'run_id + product_id required');
        $sbxFile = SITE_ROOT . '/admin/data/AffiliateProducts/sandbox/' . $runId . '.json';
        if (!is_file($sbxFile)) ap_json(false, null, 'Sandbox run not found');
        $sbx = json_decode((string)file_get_contents($sbxFile), true);
        $site = $sbx['site'] ?? '';
        if (!$site) ap_json(false, null, 'Sandbox missing site context');

        $target = null;
        $remaining = [];
        foreach (($sbx['products'] ?? []) as $p) {
            if (($p['id'] ?? '') === $pid) $target = $p;
            else $remaining[] = $p;
        }
        if (!$target) ap_json(false, null, 'Product not found in sandbox');

        // Append to live products.json (hub-side mirror + spoke if reachable)
        [$liveProducts, $source] = ap_load_site_products($site);
        if (!$source) {
            // Bootstrap path
            $source = ap_data_dir() . '/../AgentScheduler/staging/' . $site . '/AffiliateProducts/products.json';
            @mkdir(dirname($source), 0775, true);
            $liveProducts = [];
        }
        // Dedup against existing
        foreach ($liveProducts as $lp) {
            if (($lp['id'] ?? '') === ($target['id'] ?? '')
                || (!empty($target['asin']) && strtoupper($lp['asin'] ?? '') === strtoupper($target['asin']))) {
                ap_json(false, null, 'Already live — duplicate');
            }
        }
        $liveProducts[] = $target;
        file_put_contents($source, json_encode($liveProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');

        // Update sandbox file (remove promoted item)
        $sbx['products'] = $remaining;
        $sbx['count'] = count($remaining);
        file_put_contents($sbxFile, json_encode($sbx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        ap_json(true, ['promoted' => $target['id'], 'remaining' => count($remaining)], 'Promoted to live');

    /* ── sandbox: discard a single product ──────────────────────────── */
    case 'sandbox_discard':
        $runId = trim($_POST['run_id'] ?? '');
        $pid   = trim($_POST['product_id'] ?? '');
        if (!$runId || !$pid) ap_json(false, null, 'run_id + product_id required');
        $sbxFile = SITE_ROOT . '/admin/data/AffiliateProducts/sandbox/' . $runId . '.json';
        if (!is_file($sbxFile)) ap_json(false, null, 'Sandbox not found');
        $sbx = json_decode((string)file_get_contents($sbxFile), true);
        $sbx['products'] = array_values(array_filter(($sbx['products'] ?? []), fn($p) => ($p['id'] ?? '') !== $pid));
        $sbx['count'] = count($sbx['products']);
        file_put_contents($sbxFile, json_encode($sbx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ap_json(true, ['remaining' => count($sbx['products'])], 'Discarded');

    /* ── sandbox: veto a product (move to veto list, remove from sbx) ─ */
    case 'sandbox_veto':
        $runId  = trim($_POST['run_id'] ?? '');
        $pid    = trim($_POST['product_id'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if (!$runId || !$pid) ap_json(false, null, 'run_id + product_id required');
        $sbxFile = SITE_ROOT . '/admin/data/AffiliateProducts/sandbox/' . $runId . '.json';
        if (!is_file($sbxFile)) ap_json(false, null, 'Sandbox not found');
        $sbx = json_decode((string)file_get_contents($sbxFile), true);
        $site = $sbx['site'] ?? '';

        $target = null; $remaining = [];
        foreach (($sbx['products'] ?? []) as $p) {
            if (($p['id'] ?? '') === $pid) $target = $p;
            else $remaining[] = $p;
        }
        if (!$target) ap_json(false, null, 'Product not in sandbox');

        // Add to veto list
        $veto = ap_load_veto($site);
        $asin = strtoupper(trim($target['asin'] ?? ''));
        $veto[] = [
            'asin'      => $asin,
            'title'     => $target['title']    ?? '',
            'url'       => $target['url']      ?? '',
            'category'  => $target['category'] ?? '',
            'reason'    => $reason ?: 'Vetoed from sandbox preview',
            'vetoed_at' => date('c'),
        ];
        ap_save_veto($site, $veto);

        $sbx['products'] = $remaining;
        $sbx['count'] = count($remaining);
        file_put_contents($sbxFile, json_encode($sbx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ap_json(true, ['veto_count' => count($veto), 'remaining' => count($remaining)], 'Vetoed');

    /* ── prompt editor: run task now (live or sandbox) ──────────────── */
    case 'prompt_run':
        $taskId = trim($_POST['task_id'] ?? '');
        $mode   = ($_POST['mode'] ?? 'normal') === 'sandbox' ? 'sandbox' : 'normal';
        if (!$taskId) ap_json(false, null, 'task_id required');

        require_once SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
        $engine = new \AgentEngine(SITE_ROOT . '/admin/data/AgentScheduler');
        $task = $engine->getTask($taskId);
        if (!$task) ap_json(false, null, 'Task not found: ' . $taskId);

        // Check lock
        $lockFile = SITE_ROOT . '/admin/data/AgentScheduler/locks/' . $taskId . '.json';
        if (is_file($lockFile)) {
            $lock = json_decode((string)file_get_contents($lockFile), true);
            if (($lock['status'] ?? '') === 'running') {
                ap_json(false, null, 'Task is already running in background');
            }
        }

        // Mark running + spawn bg-runner (mirrors AgentScheduler's run_task_bg)
        $task['status'] = 'running';
        $engine->saveTask($task);

        $bgScript = SITE_ROOT . '/admin/modules/AgentScheduler/bg-runner.php';
        $cmd = sprintf('php %s %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($bgScript),
            escapeshellarg($taskId),
            escapeshellarg($mode),
            escapeshellarg('{}')
        );
        exec($cmd);

        ap_json(true, ['task_id' => $taskId, 'mode' => $mode], "Task launched ($mode mode) — watch AgentScheduler for progress");

    /* ── store registry: list ───────────────────────────────────────── */
    case 'list_stores':
        $stores = ap_load_registry();
        foreach ($stores as &$s) {
            $s['clicks_30d']    = ap_clicks_for_site($s['site'] ?? '', 30);
            $s['product_count'] = ap_products_for_site($s['site'] ?? '');
        }
        unset($s);
        // Sort: attached first, alphabetical by tag
        usort($stores, function($a, $b) {
            $aHas = !empty($a['site']); $bHas = !empty($b['site']);
            if ($aHas !== $bHas) return $bHas - $aHas;
            return strcmp($a['tag'] ?? '', $b['tag'] ?? '');
        });
        ap_json(true, ['stores' => $stores]);

    /* ── store registry: save one row (create or update by tag) ─────── */
    case 'save_store':
        $original = trim($_POST['original_tag'] ?? '');
        $payload  = ap_normalize_store([
            'tag'          => $_POST['tag']          ?? '',
            'site'         => $_POST['site']         ?? '',
            'page_pattern' => $_POST['page_pattern'] ?? '',
            'label'        => $_POST['label']        ?? '',
            'enabled'      => ($_POST['enabled'] ?? '1') !== '0',
        ]);
        if (!$payload['tag']) ap_json(false, null, 'Store ID required');

        $stores = ap_load_registry();
        $matchTag = $original ?: $payload['tag'];
        $found    = false;
        foreach ($stores as $i => $s) {
            if (strtolower($s['tag'] ?? '') === strtolower($matchTag)) {
                $payload['created_at'] = $s['created_at'] ?? date('c');
                $stores[$i] = $payload;
                $found = true;
                break;
            }
        }
        if (!$found) {
            foreach ($stores as $s) {
                if (strtolower($s['tag'] ?? '') === strtolower($payload['tag'])) {
                    ap_json(false, null, "Store ID '{$payload['tag']}' already exists");
                }
            }
            $stores[] = $payload;
        }

        // Reject duplicate (site, page_pattern) — different stores can't share
        // the same site+pattern combo. Empty pattern = site default; same site
        // can have many distinct patterns.
        if ($payload['site']) {
            foreach ($stores as $s) {
                if (strtolower($s['tag']) === strtolower($payload['tag'])) continue;
                if (strtolower($s['site'] ?? '') !== strtolower($payload['site'])) continue;
                if (($s['page_pattern'] ?? '') === ($payload['page_pattern'] ?? '')) {
                    $where = $payload['page_pattern'] ?: '(site default)';
                    ap_json(false, null, "Site {$payload['site']} already has a store assigned for pattern {$where} — store {$s['tag']}. Pick a different pattern, or move/delete that other store first.");
                }
            }
        }

        ap_save_registry($stores);

        // Push tag + page_overrides → site's AffiliateProducts/config.json
        $pushed = '';
        if ($payload['site']) {
            $domain = $payload['site'];
            $candidates = [
                "/var/www/vhosts/$domain/admin/data/AffiliateProducts/config.json",
                ap_data_dir() . '/../AgentScheduler/staging/' . $domain . '/AffiliateProducts/config.json',
            ];
            foreach ($candidates as $cf) {
                $dir = dirname($cf);
                if (!is_dir($dir)) {
                    if (str_contains($cf, '/staging/')) @mkdir($dir, 0775, true);
                    else continue;
                }
                $cur = is_file($cf) ? (json_decode((string)file_get_contents($cf), true) ?: []) : [];
                // Site default tag
                $defaultStore = ap_resolve_store_for_domain($domain);
                if ($defaultStore && !empty($defaultStore['tag'])) {
                    $cur['amazon_associate_tag']  = $defaultStore['tag'];
                    $cur['default_affiliate_tag'] = $defaultStore['tag'];
                }
                // All patterned overrides
                $overrides = [];
                foreach ($stores as $s) {
                    if (empty($s['enabled'])) continue;
                    if (strtolower($s['site'] ?? '') !== strtolower($domain)) continue;
                    $pat = trim($s['page_pattern'] ?? '');
                    if ($pat === '') continue;
                    $overrides[] = ['pattern' => $pat, 'tag' => $s['tag']];
                }
                $cur['page_overrides']     = $overrides;
                $cur['amazon_marketplace'] = 'www.amazon.com';
                file_put_contents($cf, json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                @chown($cf, 'www-data'); @chgrp($cf, 'www-data');
                $pushed = $domain;
                break;
            }
        }
        ap_json(true, ['store' => $payload, 'pushed_to' => $pushed], $found ? 'Store updated' : 'Store created');

    /* ── store registry: delete ─────────────────────────────────────── */
    case 'delete_store':
        $tag = trim($_POST['tag'] ?? '');
        if (!$tag) ap_json(false, null, 'Missing store ID');
        $stores = ap_load_registry();
        $before = count($stores);
        $stores = array_values(array_filter($stores, fn($s) => strtolower($s['tag'] ?? '') !== strtolower($tag)));
        if (count($stores) === $before) ap_json(false, null, 'Store not found');
        ap_save_registry($stores);
        ap_json(true, null, 'Store deleted');

    /* ── store registry: bulk seed from list of IDs ─────────────────── */
    case 'seed_stores':
        $ids  = $_POST['ids'] ?? '';
        $tags = array_filter(array_map('trim', preg_split('/[\s,;\r\n]+/', $ids)));
        if (empty($tags)) ap_json(false, null, 'No store IDs provided');

        $existing = ap_load_registry();
        $byTag = [];
        foreach ($existing as $s) $byTag[strtolower($s['tag'] ?? '')] = true;

        $added = 0;
        foreach ($tags as $tag) {
            $tagLow = strtolower($tag);
            if (isset($byTag[$tagLow])) continue;
            $existing[] = ap_normalize_store(['tag' => $tag, 'site' => '', 'label' => '', 'enabled' => true]);
            $byTag[$tagLow] = true;
            $added++;
        }
        ap_save_registry($existing);
        ap_json(true, ['added' => $added, 'total' => count($existing)], "Seeded $added new store IDs");

    /* ── list products for a single site ──────────────────────────── */
    case 'list_site_products':
        $site = strtolower(trim($_GET['site'] ?? $_POST['site'] ?? ''));
        if (!$site) ap_json(false, null, 'site required');
        [$products, $source] = ap_load_site_products($site);
        $cats = array_values(array_unique(array_filter(array_map(fn($p) => $p['category'] ?? '', $products))));
        sort($cats);
        $clicks = ap_clicks_for_site($site, 30);
        ap_json(true, [
            'site'        => $site,
            'products'    => $products,
            'categories'  => $cats,
            'count'       => count($products),
            'clicks_30d'  => $clicks,
            'source'      => $source,
        ]);

    /* ── toggle a product's "unavailable" flag ──────────────────────── */
    case 'toggle_product_unavailable':
        $site = strtolower(trim($_POST['site'] ?? ''));
        $pid  = trim($_POST['id'] ?? '');
        if (!$site || !$pid) ap_json(false, null, 'site + id required');
        [$products, $source] = ap_load_site_products($site);
        if (!$source) ap_json(false, null, 'No products file found for ' . $site);
        $found = false;
        foreach ($products as &$p) {
            if (($p['id'] ?? '') === $pid) {
                $p['unavailable'] = !($p['unavailable'] ?? false);
                $p['unavailable_at'] = $p['unavailable'] ? date('c') : '';
                $found = true; break;
            }
        }
        unset($p);
        if (!$found) ap_json(false, null, 'Product not found');
        file_put_contents($source, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');
        ap_json(true, ['products' => $products], 'Updated');

    /* ── auto-check Amazon availability for one product ─────────────── */
    case 'check_product_availability':
        $site = strtolower(trim($_POST['site'] ?? ''));
        $pid  = trim($_POST['id'] ?? '');
        if (!$site || !$pid) ap_json(false, null, 'site + id required');
        [$products, $source] = ap_load_site_products($site);
        $target = null;
        foreach ($products as &$p) {
            if (($p['id'] ?? '') === $pid) { $target = &$p; break; }
        }
        if (!$target) ap_json(false, null, 'Product not found');

        $state = ap_check_amazon_availability($target['url'] ?? '', $target['asin'] ?? '');
        $target['availability_checked_at'] = date('c');
        $target['availability_state'] = $state;
        if ($state === 'unavailable') {
            $target['unavailable'] = true;
            $target['unavailable_at'] = $target['unavailable_at'] ?? date('c');
        } elseif ($state === 'available') {
            $target['unavailable'] = false;
            $target['unavailable_at'] = '';
        }
        // If 'unknown' → leave existing flag alone
        unset($target);

        file_put_contents($source, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');
        $msgs = [
            'unavailable' => 'Marked unavailable',
            'available'   => 'Confirmed available',
            'unknown'     => 'Could not determine — Amazon blocked the check. Please verify manually.',
        ];
        ap_json(true, ['state' => $state], $msgs[$state]);

    /* ── auto-check ALL products for a site ─────────────────────────── */
    case 'check_site_availability':
        $site = strtolower(trim($_POST['site'] ?? $_GET['site'] ?? ''));
        if (!$site) ap_json(false, null, 'site required');
        [$products, $source] = ap_load_site_products($site);
        if (!$source) ap_json(false, null, 'No products file found for ' . $site);

        $checked = 0; $marked = 0; $cleared = 0; $confirmed = 0; $unknown = 0;
        foreach ($products as &$p) {
            if (($p['source'] ?? '') !== 'amazon') continue;
            $wasUnavail = !empty($p['unavailable']);
            $state = ap_check_amazon_availability($p['url'] ?? '', $p['asin'] ?? '');
            $p['availability_checked_at'] = date('c');
            $p['availability_state'] = $state;
            if ($state === 'unavailable') {
                $p['unavailable'] = true;
                if (!$wasUnavail) $marked++;
                $p['unavailable_at'] = $p['unavailable_at'] ?? date('c');
            } elseif ($state === 'available') {
                $p['unavailable'] = false;
                if ($wasUnavail) $cleared++;
                $p['unavailable_at'] = '';
                $confirmed++;
            } else {
                // 'unknown' → leave existing flag alone, just note we couldn't verify
                $unknown++;
            }
            $checked++;
            // Throttle to avoid Amazon ratelimit
            usleep(400000); // 0.4s
        }
        unset($p);
        file_put_contents($source, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');
        $msg = "Checked $checked: $marked newly unavailable, $confirmed confirmed available, $cleared cleared, $unknown couldn't verify (Amazon blocked).";
        ap_json(true, [
            'checked' => $checked, 'newly_marked' => $marked, 'cleared' => $cleared,
            'confirmed' => $confirmed, 'unknown' => $unknown,
        ], $msg);

    /* ── toggle a product's "ignored" flag (keep it off the site) ──── */
    case 'toggle_product_ignore':
        $site = strtolower(trim($_POST['site'] ?? ''));
        $pid  = trim($_POST['id'] ?? '');
        if (!$site || !$pid) ap_json(false, null, 'site + id required');
        [$products, $source] = ap_load_site_products($site);
        if (!$source) ap_json(false, null, 'No products file found for ' . $site);

        $found = false;
        foreach ($products as &$p) {
            if (($p['id'] ?? '') === $pid) {
                $p['ignored'] = !($p['ignored'] ?? false);
                $p['ignored_at'] = $p['ignored'] ? date('c') : '';
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) ap_json(false, null, 'Product not found');

        file_put_contents($source, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');
        ap_json(true, ['products' => $products], 'Updated');

    /* ── veto a product (remove + add to permanent blocklist) ──────── */
    case 'veto_product':
        $site   = strtolower(trim($_POST['site'] ?? ''));
        $pid    = trim($_POST['id'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if (!$site || !$pid) ap_json(false, null, 'site + id required');
        [$products, $source] = ap_load_site_products($site);
        if (!$source) ap_json(false, null, 'No products file found for ' . $site);

        // Find the product
        $target = null; $newProducts = [];
        foreach ($products as $p) {
            if (($p['id'] ?? '') === $pid) { $target = $p; }
            else $newProducts[] = $p;
        }
        if (!$target) ap_json(false, null, 'Product not found');

        // Extract ASIN — fallback to URL parse if asin field missing
        $asin = strtoupper(trim($target['asin'] ?? ''));
        if (!$asin && !empty($target['url']) && preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $target['url'], $m)) {
            $asin = strtoupper($m[1]);
        }

        // Add to veto list (dedup by ASIN+url)
        $veto = ap_load_veto($site);
        $alreadyVetoed = false;
        foreach ($veto as $v) {
            if (($asin && strtoupper($v['asin'] ?? '') === $asin)
                || (!$asin && ($v['url'] ?? '') === ($target['url'] ?? ''))) {
                $alreadyVetoed = true; break;
            }
        }
        if (!$alreadyVetoed) {
            $veto[] = [
                'asin'      => $asin,
                'title'     => $target['title']    ?? '',
                'url'       => $target['url']      ?? '',
                'category'  => $target['category'] ?? '',
                'reason'    => $reason,
                'vetoed_at' => date('c'),
            ];
            ap_save_veto($site, $veto);
        }

        // Remove from products file
        file_put_contents($source, json_encode($newProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');

        ap_json(true, ['veto_count' => count($veto)],
            $alreadyVetoed ? 'Already on veto list — product removed' : 'Vetoed and removed');

    /* ── un-veto a product ──────────────────────────────────────────── */
    case 'unveto_product':
        $site = strtolower(trim($_POST['site'] ?? ''));
        $asin = strtoupper(trim($_POST['asin'] ?? ''));
        $url  = trim($_POST['url'] ?? '');
        if (!$site || (!$asin && !$url)) ap_json(false, null, 'site + asin or url required');
        $veto = ap_load_veto($site);
        $before = count($veto);
        $veto = array_values(array_filter($veto, function($v) use ($asin, $url) {
            if ($asin && strtoupper($v['asin'] ?? '') === $asin) return false;
            if ($url  && ($v['url'] ?? '') === $url) return false;
            return true;
        }));
        if (count($veto) === $before) ap_json(false, null, 'Not found in veto list');
        ap_save_veto($site, $veto);
        ap_json(true, ['veto_count' => count($veto)], 'Removed from veto list — pipeline can now suggest it again');

    /* ── list site veto entries ─────────────────────────────────────── */
    case 'list_veto':
        $site = strtolower(trim($_GET['site'] ?? $_POST['site'] ?? ''));
        if (!$site) ap_json(false, null, 'site required');
        ap_json(true, ['veto' => ap_load_veto($site), 'site' => $site]);

    /* ── delete a product on a remote site ─────────────────────────── */
    case 'delete_site_product':
        $site = strtolower(trim($_POST['site'] ?? ''));
        $pid  = trim($_POST['id'] ?? '');
        if (!$site || !$pid) ap_json(false, null, 'site + id required');
        [$products, $source] = ap_load_site_products($site);
        if (!$source) ap_json(false, null, 'No products file found for ' . $site);

        $before = count($products);
        $products = array_values(array_filter($products, fn($p) => ($p['id'] ?? '') !== $pid));
        if (count($products) === $before) ap_json(false, null, 'Product not found');

        file_put_contents($source, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($source, 'www-data'); @chgrp($source, 'www-data');
        ap_json(true, null, 'Product deleted');

    /* ── store registry: which sites are unattached? ────────────────── */
    case 'list_candidate_sites':
        // Build the universe of known sites from AgentScheduler tasks +
        // staging dirs + vhosts dirs, minus those already attached.
        $known = [];
        $tasksDir = SITE_ROOT . '/admin/data/AgentScheduler/tasks';
        foreach (glob($tasksDir . '/*.json') as $tf) {
            $t = json_decode((string)file_get_contents($tf), true);
            $d = $t['config']['target_domain'] ?? '';
            if ($d) $known[strtolower($d)] = true;
        }
        $stagingDir = SITE_ROOT . '/admin/data/AgentScheduler/staging';
        if (is_dir($stagingDir)) {
            foreach (scandir($stagingDir) as $d) {
                if ($d[0] !== '.' && is_dir("$stagingDir/$d")) $known[strtolower($d)] = true;
            }
        }
        if (is_dir('/var/www/vhosts')) {
            foreach (scandir('/var/www/vhosts') as $d) {
                if ($d[0] !== '.' && is_dir("/var/www/vhosts/$d")) $known[strtolower($d)] = true;
            }
        }
        // Subtract attached
        $attached = [];
        foreach (ap_load_registry() as $s) {
            foreach (($s['sites'] ?? []) as $sd) $attached[strtolower($sd)] = ($s['key'] ?? '');
        }
        $sites = [];
        foreach (array_keys($known) as $d) {
            $sites[] = ['domain' => $d, 'attached_to' => $attached[$d] ?? null];
        }
        sort($sites);
        usort($sites, fn($a, $b) => strcmp($a['domain'], $b['domain']));
        ap_json(true, ['sites' => $sites]);

    /* ── dashboard summary ──────────────────────────────────────────── */
    case 'dashboard_summary':
        $stores = ap_load_registry();
        $totalProducts = 0;
        $totalSites    = 0;
        $coverage      = [];   // one row per store — Tag | Site | Clicks | Products
        foreach ($stores as $s) {
            $site = $s['site'] ?? '';
            $sCnt = $site ? ap_products_for_site($site) : 0;
            $clicks = $site ? ap_clicks_for_site($site, 30) : 0;
            if ($site) $totalSites++;
            $totalProducts += $sCnt;
            $coverage[] = [
                'tag'            => $s['tag'],
                'label'          => $s['label'],
                'site'           => $site,
                'enabled'        => $s['enabled'] ?? true,
                'product_count'  => $sCnt,
                'clicks_30d'     => $clicks,
            ];
        }

        // Refresh task status
        $tasks = [];
        $tasksDir = SITE_ROOT . '/admin/data/AgentScheduler/tasks';
        foreach (glob($tasksDir . '/amazon-affil-*.json') as $tf) {
            $t = json_decode((string)file_get_contents($tf), true);
            if (!is_array($t)) continue;
            $tasks[] = [
                'id'           => $t['id'] ?? basename($tf, '.json'),
                'name'         => $t['name'] ?? '',
                'enabled'      => !empty($t['enabled']),
                'target_domain'=> $t['config']['target_domain'] ?? '',
                'category'     => $t['config']['category'] ?? '',
                'next_run'     => $t['next_run'] ?? '',
                'last_run'     => $t['last_run'] ?? '',
                'last_status'  => $t['last_status'] ?? '',
            ];
        }
        usort($tasks, fn($a, $b) => strcmp($a['next_run'] ?? '', $b['next_run'] ?? ''));

        // 30-day click rollup (uses existing referral log if present)
        $refLog = ap_data_dir() . '/referral_log/daily.json';
        $referrals = is_file($refLog) ? (json_decode((string)file_get_contents($refLog), true) ?: []) : [];
        $cutoff = date('Y-m-d', strtotime('-29 days'));
        $referrals = array_filter($referrals, fn($k) => $k >= $cutoff, ARRAY_FILTER_USE_KEY);
        $clicks30 = array_sum(array_column($referrals, 'clicks'));
        $sparkline = [];
        for ($i = 29; $i >= 0; $i--) {
            $k = date('Y-m-d', strtotime("-$i days"));
            $sparkline[] = ['date' => $k, 'clicks' => (int)($referrals[$k]['clicks'] ?? 0)];
        }

        // Top products (last 30d)
        $productTotals = [];
        foreach ($referrals as $day => $d) {
            foreach (($d['products'] ?? []) as $pid => $cnt) {
                $productTotals[$pid] = ($productTotals[$pid] ?? 0) + $cnt;
            }
        }
        arsort($productTotals);
        $topProducts = array_slice($productTotals, 0, 5, true);

        ap_json(true, [
            'kpis' => [
                'total_stores'   => count($stores),
                'total_sites'    => $totalSites,
                'total_products' => $totalProducts,
                'clicks_30d'     => $clicks30,
            ],
            'coverage'    => $coverage,
            'tasks'       => $tasks,
            'sparkline'   => $sparkline,
            'top_products'=> $topProducts,
        ]);

    /* ── self-resolving product URL ─────────────────────────────────── */
    case 'resolve_product_url':
        $url = trim($_POST['url'] ?? '');
        if (!$url) ap_json(false, null, 'URL required');
        if (!filter_var($url, FILTER_VALIDATE_URL)) ap_json(false, null, 'Invalid URL');

        $parsed = ap_parse_affiliate_url($url);
        if ($parsed['source'] !== 'amazon') {
            ap_json(false, null, 'Only Amazon URLs are supported in self-resolving mode (source detected: ' . $parsed['source'] . ')');
        }
        $asin = $parsed['asin'] ?? '';
        if (!$asin) {
            ap_json(false, null, 'Could not extract ASIN from URL. Make sure the URL contains /dp/<ASIN> or /gp/product/<ASIN>.');
        }

        $resolved = [
            'url'           => $url,
            'source'        => 'amazon',
            'asin'          => $asin,
            'affiliate_tag' => '',
            'title'         => '',
            'description'   => '',
            'price'         => '',
            'image'         => '',
            'rating'        => '',
        ];
        $diagnostics = [];

        $cfg = ap_load_config();
        $defaultTag = $cfg['default_affiliate_tag'] ?? $cfg['amazon_associate_tag'] ?? '';

        // ── Step 1: deterministic Amazon CDN image (always works for valid ASINs)
        $detImage = ap_amazon_canonical_image($asin);
        if ($detImage) {
            $imgCheck = ap_head_check_image($detImage);
            if ($imgCheck['ok']) {
                $resolved['image'] = $detImage;
                $diagnostics[] = 'Image: deterministic CDN (HTTP 200)';
            } else {
                $diagnostics[] = "Image: deterministic CDN miss (HTTP {$imgCheck['code']})";
            }
        }

        // ── Step 2: Creators API for title/price (when creds work) ────
        $usedApi = false;
        if (!empty($cfg['amazon_creators_client_id']) && !empty($cfg['amazon_creators_client_secret'])) {
            $apiResult = ap_amazon_api('getItems', [
                'marketplace' => $cfg['amazon_marketplace'] ?? 'www.amazon.com',
                'partnerTag'  => $defaultTag,
                'itemIds'     => [$asin],
                'resources'   => ['ItemInfo.Title', 'Offers.Listings.Price', 'Images.Primary.Large', 'DetailPageURL', 'CustomerReviews.StarRating'],
            ]);
            if (!isset($apiResult['error'])) {
                $items = $apiResult['ItemsResult']['Items'] ?? [];
                if (!empty($items)) {
                    $norm = ap_amazon_normalize_item($items[0], $defaultTag);
                    if (!empty($norm['title']))  $resolved['title']  = $norm['title'];
                    if (!empty($norm['price']))  $resolved['price']  = $norm['price'];
                    if (!empty($norm['rating'])) $resolved['rating'] = $norm['rating'];
                    if (!empty($norm['image']) && ap_is_amazon_cdn($norm['image'])) {
                        // Prefer API-provided image over deterministic if higher quality
                        $resolved['image'] = $norm['image'];
                    }
                    if (!empty($norm['url'])) $resolved['url'] = $norm['url'];
                    $diagnostics[] = 'Creators API: ok';
                    $usedApi = true;
                }
            } else {
                $diagnostics[] = 'Creators API failed: ' . ($apiResult['error'] ?? 'unknown');
            }
        }

        // ── Step 3: scrape page for title/price (best-effort) ─────────
        if (!$resolved['title'] || !$resolved['price']) {
            $fetched = ap_fetch_url($url);
            if ($fetched['code'] === 200 && $fetched['body']) {
                $ext = ap_extract_amazon_meta($fetched['body']);
                if (!$resolved['title']  && !empty($ext['title']))  $resolved['title']  = $ext['title'];
                if (!$resolved['price']  && !empty($ext['price']))  $resolved['price']  = $ext['price'];
                if (!$resolved['rating'] && !empty($ext['rating'])) $resolved['rating'] = $ext['rating'];
                $diagnostics[] = 'Page scrape: title=' . ($ext['title'] ? 'hit' : 'miss')
                              . ' price=' . ($ext['price'] ? 'hit' : 'miss');
            } else {
                $diagnostics[] = "Page scrape failed (HTTP {$fetched['code']})";
            }
        }

        // ── Step 4: title from URL slug as final fallback ─────────────
        if (!$resolved['title']) {
            $slugTitle = ap_amazon_title_from_url($url);
            if ($slugTitle) {
                $resolved['title'] = $slugTitle;
                $diagnostics[] = 'Title: from URL slug';
            }
        }

        // ── Validate ──────────────────────────────────────────────────
        if (!$resolved['image']) {
            ap_json(false, null, 'Could not resolve a real Amazon CDN image for this product. Check the ASIN. ' . implode(' / ', $diagnostics));
        }
        if (!ap_is_amazon_cdn($resolved['image'])) {
            // This shouldn't ever happen given step 1 builds an Amazon CDN URL,
            // but enforce the rule defensively.
            ap_json(false, null, 'Resolved image is not on an Amazon CDN host. Refusing: ' . $resolved['image']);
        }
        if (!$resolved['title']) {
            ap_json(false, null, 'Could not derive a title for this product. ' . implode(' / ', $diagnostics));
        }

        $resolved['affiliate_tag'] = $defaultTag;
        $resolved['_diagnostics']  = $diagnostics;
        ap_json(true, $resolved);

    default:
        ap_json(false, null, "Unknown action: $action");
}
