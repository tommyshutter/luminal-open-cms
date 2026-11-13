<?php
/**
 * Affiliate Products — public read-only JSON endpoint
 * Returns the curated products.json for use by frontend pages.
 * No auth required (read-only public data).
 *
 * GET /panels/affiliate-products-public.php
 */
declare(strict_types=1);

// When included inline (e.g. [[panel:affiliate-products-public.php]] shortcode), render HTML not JSON
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'affiliate-products-public.php') {
    $__afp_home = __DIR__ . '/affiliate-editorial.php';
    if (is_file($__afp_home)) { include_once $__afp_home; }
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__)) ?: dirname(__DIR__));

$file = SITE_ROOT . '/admin/data/AffiliateProducts/products.json';
if (!is_file($file)) {
    echo json_encode(['ok' => true, 'products' => []]);
    exit;
}

$products = json_decode((string)file_get_contents($file), true) ?: [];

// Only return enabled products with basic fields
$out = [];
foreach ($products as $p) {
    if (isset($p['enabled']) && !$p['enabled']) continue;
    $out[] = [
        'title'    => $p['title']    ?? $p['name'] ?? '',
        'price'    => $p['price']    ?? '',
        'image'    => $p['image']    ?? $p['img']  ?? '',
        'url'      => $p['url']      ?? $p['link'] ?? '',
        'category' => $p['category'] ?? $p['cat']  ?? '',
    ];
}

echo json_encode(['ok' => true, 'products' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
