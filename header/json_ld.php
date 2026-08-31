<?php
/**
 * JSON-LD Structured Data — SEO/AI visibility layer
 *
 * Outputs Organization, Article, and Product schemas as appropriate.
 * Included from page.php inside <head>.
 *
 * Variables expected from page.php scope:
 *   $GLOBALS['SITE_SETTINGS']  — site config array
 *   $GLOBALS['artistName']     — site name
 *   $PAGE_DATA                 — current page data array
 *   $requested                 — current slug string
 *   $isHomePage                — bool
 *   $fullTitle                 — full page title string
 *
 * @file    /header/json_ld.php
 * @version 1.0.0
 * @date    2026-04-07
 */

if (!defined('SITE_ROOT')) return;

$_jld_domain  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_jld_ss      = $GLOBALS['SITE_SETTINGS'] ?? [];
$_jld_name    = trim((string)(($GLOBALS['artistName'] ?: '') ?: ($GLOBALS['siteTitle'] ?: '') ?: ($_SERVER['HTTP_HOST'] ?? 'Site')));
if ($_jld_name === '') $_jld_name = $_SERVER['HTTP_HOST'] ?? 'Site';
$_jld_desc    = (string)($_jld_ss['meta_description'] ?? '');
$_jld_pageUrl = $_jld_domain . ($_SERVER['REQUEST_URI'] ?? '/');

// Logo detection
$_jld_logo = '';
$_jld_logo_candidates = [
    SITE_ROOT . '/admin/data/site-assets/logo.png',
    SITE_ROOT . '/admin/data/site-assets/logo.svg',
    SITE_ROOT . '/media/images/logo.png',
    SITE_ROOT . '/media/images/logo.svg',
];
foreach ($_jld_logo_candidates as $_jld_c) {
    if (is_file($_jld_c)) {
        $_jld_logo = $_jld_domain . str_replace(SITE_ROOT, '', $_jld_c);
        break;
    }
}

// --- 1. Organization schema (always) ---
$_jld_org = [
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => $_jld_name,
    'url'      => $_jld_domain . '/',
];
if ($_jld_desc)  $_jld_org['description'] = $_jld_desc;
if ($_jld_logo)  $_jld_org['logo'] = ['@type' => 'ImageObject', 'url' => $_jld_logo];

$_jld_schemas = [$_jld_org];

// --- 2. Article schema ---
// Detect: page data has pub_date/author/eyebrow, OR slug matches Articles/index.json entry
$_jld_is_article  = false;
$_jld_article_src = null;

if (!empty($PAGE_DATA) && (
    !empty($PAGE_DATA['pub_date']) ||
    !empty($PAGE_DATA['eyebrow'])  ||
    !empty($PAGE_DATA['author'])
)) {
    $_jld_is_article  = true;
    $_jld_article_src = $PAGE_DATA;
}

if (!$_jld_is_article && !empty($requested) && !$isHomePage) {
    // Check Articles/index.json
    $_jld_art_idx = SITE_ROOT . '/admin/data/Articles/index.json';
    if (is_file($_jld_art_idx)) {
        $raw = @file_get_contents($_jld_art_idx);
        $arts = $raw ? (json_decode($raw, true) ?: []) : [];
        // Writers store {"articles":[...]}; normalise like llms.php does.
        $arts = $arts['articles'] ?? $arts;
        foreach ((array)$arts as $art) {
            if (($art['slug'] ?? '') === $requested) {
                $_jld_is_article  = true;
                $_jld_article_src = $art;
                break;
            }
        }
    }
    // Check per-article JSON
    if (!$_jld_is_article) {
        $_jld_art_file = SITE_ROOT . '/admin/data/Articles/' . $requested . '.json';
        if (is_file($_jld_art_file)) {
            $raw = @file_get_contents($_jld_art_file);
            $art = $raw ? (json_decode($raw, true) ?: []) : [];
            if (!empty($art['title'])) {
                $_jld_is_article  = true;
                $_jld_article_src = $art;
            }
        }
    }
}

if ($_jld_is_article && is_array($_jld_article_src)) {
    $art = $_jld_article_src;
    $artTitle  = (string)($art['title']   ?? $fullTitle ?? '');
    $artDesc   = (string)($art['excerpt'] ?? $art['description'] ?? $art['eyebrow'] ?? $_jld_desc);
    $artDate   = (string)($art['pub_date']   ?? $art['date'] ?? date('Y-m-d'));
    $artAuthor = (string)($art['author'] ?? $_jld_name);

    $artImage = '';
    if (!empty($art['image'])) {
        $artImage = preg_match('~^https?://~i', $art['image'])
            ? $art['image']
            : $_jld_domain . $art['image'];
    } elseif (!empty($GLOBALS['PAGE_OG_IMAGE'])) {
        $img = $GLOBALS['PAGE_OG_IMAGE'];
        $artImage = preg_match('~^https?://~i', $img) ? $img : $_jld_domain . $img;
    }

    $artSchema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        'headline'         => $artTitle,
        'description'      => $artDesc,
        'datePublished'    => $artDate,
        'dateModified'     => $artDate,
        'author'           => ['@type' => 'Person', 'name' => $artAuthor],
        'publisher'        => ['@type' => 'Organization', 'name' => $_jld_name],
        'url'              => $_jld_pageUrl,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $_jld_pageUrl],
    ];
    if ($artImage) $artSchema['image'] = ['@type' => 'ImageObject', 'url' => $artImage];
    $_jld_schemas[] = $artSchema;
}

// --- 3. Product schema ---
// Detect: slug matches a MyStore product SKU, or ?product= param
$_jld_is_product  = false;
$_jld_product_src = null;

// Check slug → MyStore product
if (!empty($requested)) {
    $_jld_prod_file = SITE_ROOT . '/admin/data/mystore/products/' . $requested . '.json';
    if (is_file($_jld_prod_file)) {
        $raw = @file_get_contents($_jld_prod_file);
        $prod = $raw ? (json_decode($raw, true) ?: []) : [];
        if (!empty($prod['name'])) {
            $_jld_is_product  = true;
            $_jld_product_src = $prod;
        }
    }
}

// Check ?product= param
if (!$_jld_is_product && !empty($_GET['product'])) {
    $prodSku = preg_replace('/[^a-z0-9_\-]/i', '', (string)$_GET['product']);
    $_jld_prod_file = SITE_ROOT . '/admin/data/mystore/products/' . $prodSku . '.json';
    if (is_file($_jld_prod_file)) {
        $raw = @file_get_contents($_jld_prod_file);
        $prod = $raw ? (json_decode($raw, true) ?: []) : [];
        if (!empty($prod['name'])) {
            $_jld_is_product  = true;
            $_jld_product_src = $prod;
        }
    }
}

if ($_jld_is_product && is_array($_jld_product_src)) {
    $prod      = $_jld_product_src;
    $prodName  = (string)($prod['name']       ?? '');
    $prodDesc  = (string)($prod['desc']       ?? $prod['description'] ?? $prod['short_desc'] ?? '');
    $prodSku   = (string)($prod['sku']        ?? $requested);
    $prodPrice = isset($prod['price']) ? (string)$prod['price'] : '';
    $prodAvail = !empty($prod['enabled'])
        ? 'https://schema.org/InStock'
        : 'https://schema.org/OutOfStock';

    $prodImage = '';
    if (!empty($prod['image'])) {
        $prodImage = preg_match('~^https?://~i', $prod['image'])
            ? $prod['image']
            : $_jld_domain . $prod['image'];
    }

    $prodSchema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $prodName,
        'description' => $prodDesc,
        'sku'         => $prodSku,
        'url'         => $_jld_pageUrl,
        'brand'       => ['@type' => 'Brand', 'name' => $_jld_name],
        'offers'      => [
            '@type'        => 'Offer',
            'availability' => $prodAvail,
            'priceCurrency'=> 'USD',
            'url'          => $_jld_pageUrl,
        ],
    ];
    if ($prodPrice !== '') $prodSchema['offers']['price'] = (float)$prodPrice;
    if ($prodImage)        $prodSchema['image'] = $prodImage;
    $_jld_schemas[] = $prodSchema;
}

// --- Output ---
foreach ($_jld_schemas as $_jld_s) {
    echo "\n  <script type=\"application/ld+json\">\n";
    echo json_encode($_jld_s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    echo "\n  </script>";
}

// Clean up
unset($_jld_domain, $_jld_ss, $_jld_name, $_jld_desc, $_jld_pageUrl,
      $_jld_logo, $_jld_logo_candidates, $_jld_c, $_jld_org, $_jld_schemas,
      $_jld_is_article, $_jld_article_src, $_jld_art_idx, $_jld_art_file,
      $_jld_is_product, $_jld_product_src, $_jld_prod_file);
