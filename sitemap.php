<?php
/**
 * Luminal CMS — Dynamic Sitemap Generator
 *
 * Generates sitemap.xml from published pages, articles, products, and podcast feed.
 * Called via /sitemap.xml (htaccess rewrite → sitemap.php).
 *
 * @package  LuminalCMS
 * @version  2.0.0
 * @file     sitemap.php
 * @date     2026-04-07
 */
declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');

if (!defined('SITE_ROOT')) define('SITE_ROOT', __DIR__);

$domain   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$dataDir  = SITE_ROOT . '/admin/data';
$pagesDir = $dataDir . '/pages';

$urls = [];

// the home page is "/" (its slug and /home 301 there); sign-in, payment and preview pages
// are not content; a page with redirect_to is an old address.
$_sm_ss   = json_decode((string)@file_get_contents($dataDir . '/site-settings.json'), true) ?: [];
$_sm_home = (string)(($_sm_ss['home_page_slug'] ?? '') ?: 'home');
$_sm_skip = ['home', $_sm_home, 'login', 'logout', 'signin', 'sign-in', 'signup', 'sign-up', 'register', 'join',
             'pay', 'pay-success', 'pay-cancel', 'checkout', 'reset-password', 'forgot-password', 'verify',
             'dashboard', 'messages', 'account', 'theme-preview', 'events-test-page', 'podcast-feed'];

// --- Homepage ---
$urls[] = [
    'loc'        => $domain . '/',
    'priority'   => '1.0',
    'changefreq' => 'daily',
];

// --- Published pages ---
if (is_dir($pagesDir)) {
    foreach (scandir($pagesDir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..' || $slug === 'pages_trash') continue;
        $pageDir = $pagesDir . '/' . $slug;
        if (!is_dir($pageDir)) continue;

        $jsonFile = $pageDir . '/' . $slug . '.json';
        if (!is_file($jsonFile)) $jsonFile = $pageDir . '/page.json';
        if (!is_file($jsonFile)) continue;
        if (in_array($slug, $_sm_skip, true) || preg_match('/-preview$/', $slug)) continue;

        $modified = date('Y-m-d', @filemtime($jsonFile) ?: time());
        $entry = [
            'loc'        => $domain . '/' . rawurlencode($slug),
            'lastmod'    => $modified,
            'priority'   => '0.7',
            'changefreq' => 'weekly',
        ];

        // Image sitemap: check page JSON for og_image or featured_image
        $pageData = json_decode((string)@file_get_contents($jsonFile), true) ?: [];
        if (!empty($pageData['redirect_to'])) continue;   // a moved page's old address
        $imgUrl = '';
        foreach (['og_image', 'featured_image', 'image'] as $k) {
            if (!empty($pageData[$k])) { $imgUrl = $pageData[$k]; break; }
        }
        if ($imgUrl && !preg_match('~^https?://~i', $imgUrl)) $imgUrl = $domain . $imgUrl;
        if ($imgUrl) $entry['image'] = $imgUrl;

        $urls[] = $entry;
    }
}

// --- Articles ---
// ArticlesManager/articles.json is the CANONICAL store; Articles/index.json is a
// derived legacy copy that is absent on most sites and drifts where it exists.
// Canonical is therefore the source of truth here and the legacy index only
// enriches it — the reverse of how this used to work, which meant sites with no
// legacy index published zero articles even when they had dozens.
$_sm_records = [];
$_sm_canon = $dataDir . '/ArticlesManager/articles.json';
if (is_file($_sm_canon)) {
    $_sm_d = json_decode((string)@file_get_contents($_sm_canon), true) ?: [];
    foreach ((array)($_sm_d['articles'] ?? $_sm_d) as $_sm_a) {
        if (!is_array($_sm_a)) continue;
        if (trim((string)($_sm_a['body_html'] ?? '')) === '') continue;   // empty body = 404
        if (isset($_sm_a['published']) && !$_sm_a['published']) continue;
        $_sm_slug = (string)($_sm_a['slug'] ?? '');
        if ($_sm_slug === '') continue;
        $_sm_records[$_sm_slug] = $_sm_a;
    }
}

// Legacy index: may supply pub_date / image that canonical lacks. It can NEVER
// introduce a slug of its own — entries that exist only here are stale and 404,
// which is exactly what put dead links in the sitemap before.
$artIdx = $dataDir . '/Articles/index.json';
if (is_file($artIdx)) {
    $articles = json_decode((string)@file_get_contents($artIdx), true) ?: [];
    // Writers store {"articles":[...]}; older copies are a flat list.
    $articles = $articles['articles'] ?? $articles;
    foreach ((array)$articles as $_sm_l) {
        if (!is_array($_sm_l)) continue;
        $_sm_slug = (string)($_sm_l['slug'] ?? '');
        if ($_sm_slug === '' || !isset($_sm_records[$_sm_slug])) continue;
        $_sm_records[$_sm_slug] = $_sm_records[$_sm_slug] + $_sm_l;  // canonical wins, legacy fills gaps
    }
}

if ($_sm_records) {
    foreach ($_sm_records as $art) {
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', (string)($art['slug'] ?? ''));
        if ($slug === '') continue;
        $artDate = (string)($art['pub_date'] ?? $art['date'] ?? date('Y-m-d'));
        // Normalize date to Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $artDate, $m)) $artDate = $m[0];
        $entry = [
            'loc'        => $domain . '/blog/' . rawurlencode($slug),   // /article/ is NOT a route; .htaccess defines /blog/ and /articles/
            'lastmod'    => $artDate,
            'priority'   => '0.8',
            'changefreq' => 'monthly',
        ];
        $imgUrl = '';
        if (!empty($art['image'])) $imgUrl = $art['image'];
        if ($imgUrl && !preg_match('~^https?://~i', $imgUrl)) $imgUrl = $domain . $imgUrl;
        if ($imgUrl) $entry['image'] = $imgUrl;
        $urls[] = $entry;
    }
}

// --- MyStore products (enabled only) ---
$productsDir = $dataDir . '/mystore/products';
if (is_dir($productsDir)) {
    foreach (glob($productsDir . '/*.json') ?: [] as $pf) {
        $prod = json_decode((string)@file_get_contents($pf), true) ?: [];
        if (empty($prod['enabled'])) continue;
        $sku = preg_replace('/[^a-z0-9_\-]/i', '', (string)($prod['sku'] ?? ''));
        if ($sku === '') $sku = preg_replace('/[^a-z0-9_\-]/i', '', pathinfo($pf, PATHINFO_FILENAME));
        if ($sku === '') continue;
        $entry = [
            'loc'        => $domain . '/shop/' . rawurlencode($sku),
            'lastmod'    => date('Y-m-d', @filemtime($pf) ?: time()),
            'priority'   => '0.9',
            'changefreq' => 'weekly',
        ];
        if (!empty($prod['image'])) {
            $img = $prod['image'];
            if (!preg_match('~^https?://~i', $img)) $img = $domain . $img;
            $entry['image'] = $img;
        }
        $urls[] = $entry;
    }
}

// --- Affiliate/Editorial products ---
$affProd = $dataDir . '/AffiliateProducts/products.json';
if (is_file($affProd)) {
    $affItems = json_decode((string)@file_get_contents($affProd), true) ?: [];
    foreach ($affItems as $item) {
        if (empty($item['asin']) && empty($item['url'])) continue;
        // These are external products — include them in sitemap only if they have a local page
        $sku = preg_replace('/[^a-z0-9_\-]/i', '', (string)($item['asin'] ?? $item['sku'] ?? ''));
        if ($sku === '') continue;
        $pageFile = $pagesDir . '/' . $sku . '/' . $sku . '.json';
        if (!is_file($pageFile)) continue; // only include if there's a real page for it
        $urls[] = [
            'loc'        => $domain . '/' . rawurlencode($sku),
            'lastmod'    => date('Y-m-d', @filemtime($pageFile) ?: time()),
            'priority'   => '0.6',
            'changefreq' => 'weekly',
        ];
    }
}

// an article's page copy (/slug) and its /blog/slug are the same text; list only /blog/
if ($_sm_records) {
    $urls = array_values(array_filter($urls, function ($u) use ($domain, $_sm_records) {
        $p = substr((string)$u['loc'], strlen($domain) + 1);
        return $p === '' || strpos($p, '/') !== false || !isset($_sm_records[rawurldecode($p)]);
    }));
}

// --- Podcast feed ---
$podcastFeed = SITE_ROOT . '/admin/modules/PodcastManager/podcast_feed.php';
$_sm_eps = json_decode((string)@file_get_contents($dataDir . '/podcasts/episodes.json'), true);
$_sm_eps = is_array($_sm_eps) ? ($_sm_eps['episodes'] ?? $_sm_eps) : [];
if (is_file($podcastFeed) && is_array($_sm_eps) && count($_sm_eps) > 0) {
    $urls[] = [
        'loc'        => $domain . '/podcast-feed',
        'priority'   => '0.6',
        'changefreq' => 'daily',
    ];
}

// --- Machine-readable AI/LLM briefing (llmstxt.org) ---
// not pages — robots.txt and the <head> already point to them, so they stay out of the sitemap
foreach ([] as $llmsFile) {
    if (is_file(SITE_ROOT . $llmsFile)) {
        $urls[] = [
            'loc'        => $domain . $llmsFile,
            'lastmod'    => date('Y-m-d', @filemtime(SITE_ROOT . $llmsFile) ?: time()),
            'priority'   => '0.5',
            'changefreq' => 'weekly',
        ];
    }
}

// --- Installed modules' own public pages (includes/module-discovery.php) ---
// Only same-site addresses are taken; a module that fails is skipped.
if (is_file(SITE_ROOT . '/includes/module-discovery.php')) {
    require_once SITE_ROOT . '/includes/module-discovery.php';
    foreach (lum_discovery_call('sitemap', $domain) as $_sm_list) {
        foreach ((array)$_sm_list as $_sm_u) {
            if (is_array($_sm_u) && strpos((string)($_sm_u['loc'] ?? ''), $domain . '/') === 0) $urls[] = $_sm_u;
        }
    }
}

// --- Output XML ---
$hasImages = false;
foreach ($urls as $u) { if (!empty($u['image'])) { $hasImages = true; break; } }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
if ($hasImages) {
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
} else {
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
}

foreach ($urls as $u) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($u['loc']) . "</loc>\n";
    if (!empty($u['lastmod']))    echo "    <lastmod>" . htmlspecialchars($u['lastmod']) . "</lastmod>\n";
    echo "    <changefreq>" . htmlspecialchars($u['changefreq'] ?? 'weekly') . "</changefreq>\n";
    echo "    <priority>" . htmlspecialchars($u['priority'] ?? '0.5') . "</priority>\n";
    if (!empty($u['image'])) {
        echo "    <image:image>\n";
        echo "      <image:loc>" . htmlspecialchars($u['image']) . "</image:loc>\n";
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
