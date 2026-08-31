<?php
/**
 * AffiliateProducts — Click Pixel + Redirect
 * @file admin/modules/AffiliateProducts/go.php
 *
 * Public endpoint. Logs the click to referral_log/daily.json then 302s to
 * the affiliate URL. Format of the log file matches what api.php's
 * get_referral_stats action reads:
 *
 *   {
 *     "2026-04-25": {
 *       "clicks": 17,
 *       "products": { "ap-20260425-aabbccdd": 5, ... }
 *     },
 *     ...
 *   }
 *
 * Usage: /admin/modules/AffiliateProducts/go.php?p=<product_id>
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}

$pid = $_GET['p'] ?? '';
$pid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$pid);
if (!$pid) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing product id\n";
    exit;
}

// Resolve product
$dataDir = SITE_ROOT . '/admin/data/AffiliateProducts';
$productsFile = $dataDir . '/products.json';
if (!is_file($productsFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No product catalog\n";
    exit;
}
$products = json_decode((string)file_get_contents($productsFile), true);
if (!is_array($products)) {
    http_response_code(500);
    exit;
}

$product = null;
foreach ($products as $p) {
    if (($p['id'] ?? '') === $pid) { $product = $p; break; }
}
if (!$product || empty($product['enabled'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Product not found\n";
    exit;
}

$dest = trim($product['url'] ?? '');
if (!$dest || !filter_var($dest, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Product has no destination URL\n";
    exit;
}

// ── Log the click (best-effort — never block the redirect on log failure) ──
try {
    $logDir = $dataDir . '/referral_log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
        @chown($logDir, 'www-data'); @chgrp($logDir, 'www-data');
    }
    $logFile = $logDir . '/daily.json';

    // Lock + read-modify-write
    $fp = @fopen($logFile, 'c+');
    if ($fp && @flock($fp, LOCK_EX)) {
        $sz = filesize($logFile) ?: 0;
        $current = $sz ? (json_decode((string)fread($fp, $sz), true) ?: []) : [];
        $today = date('Y-m-d');
        if (!isset($current[$today])) {
            $current[$today] = ['clicks' => 0, 'products' => []];
        }
        $current[$today]['clicks'] = ($current[$today]['clicks'] ?? 0) + 1;
        $current[$today]['products'][$pid] = ($current[$today]['products'][$pid] ?? 0) + 1;

        // Salt IP for unique-visitor estimation across days. Salt is per-site
        // so logs can't be correlated across sites. Generated lazily.
        $saltFile = $dataDir . '/.click_salt';
        if (!is_file($saltFile)) {
            @file_put_contents($saltFile, bin2hex(random_bytes(16)));
            @chmod($saltFile, 0640);
            @chown($saltFile, 'www-data'); @chgrp($saltFile, 'www-data');
        }
        $salt = (string)@file_get_contents($saltFile);
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
        $ipHash = $ip ? substr(hash('sha256', $salt . $ip), 0, 12) : '';

        if ($ipHash) {
            if (!isset($current[$today]['unique_ips'])) $current[$today]['unique_ips'] = [];
            $current[$today]['unique_ips'][$ipHash] = ($current[$today]['unique_ips'][$ipHash] ?? 0) + 1;
        }

        // Compact older days' unique_ips to a count to keep the file small
        $cutoff = date('Y-m-d', strtotime('-7 days'));
        foreach ($current as $day => &$rec) {
            if ($day < $cutoff && isset($rec['unique_ips']) && is_array($rec['unique_ips'])) {
                $rec['unique_visitors'] = count($rec['unique_ips']);
                unset($rec['unique_ips']);
            }
        }
        unset($rec);

        // Drop entries older than 90 days
        $cutoff90 = date('Y-m-d', strtotime('-90 days'));
        $current = array_filter($current, fn($k) => $k >= $cutoff90, ARRAY_FILTER_USE_KEY);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
} catch (\Throwable $e) {
    // Never block the redirect
    error_log('[AffiliateProducts] click log error: ' . $e->getMessage());
}

// ── Append affiliate tag if missing in destination URL ────────────────
//
// Tag selection:
//   1. Parse HTTP_REFERER → path
//   2. Match path against `page_overrides[]` from local config.json (longest
//      pattern wins) → use that tag if matched
//   3. Else fall back to site-default `amazon_associate_tag`
//
// page_overrides + default tag are pushed by the hub Stores registry and
// kept in sync with each save_store action.
$tag = '';
$cfgFile = $dataDir . '/config.json';
if (is_file($cfgFile)) {
    $cfg = json_decode((string)file_get_contents($cfgFile), true) ?: [];

    // Try referrer path against overrides
    $refPath = '';
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref) {
        $refPath = parse_url($ref, PHP_URL_PATH) ?: '';
    }
    if ($refPath && !empty($cfg['page_overrides']) && is_array($cfg['page_overrides'])) {
        $bestLen = -1;
        foreach ($cfg['page_overrides'] as $ov) {
            $pat = (string)($ov['pattern'] ?? '');
            $ovTag = (string)($ov['tag'] ?? '');
            if (!$pat || !$ovTag) continue;
            if (fnmatch($pat, $refPath) && strlen($pat) > $bestLen) {
                $tag = $ovTag;
                $bestLen = strlen($pat);
            }
        }
    }
    if (!$tag) {
        $tag = $cfg['amazon_associate_tag'] ?? $cfg['default_affiliate_tag'] ?? '';
    }
}
if ($tag && stripos($dest, 'tag=') === false) {
    $sep = str_contains($dest, '?') ? '&' : '?';
    $dest .= $sep . 'tag=' . urlencode($tag);
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ' . $dest, true, 302);
exit;
