<?php
/**
 * Stat Pixel — lightweight 1x1 tracking endpoint for article/page views.
 *
 * Usage: <img src="/panels/stat-pixel.php?t=article&s=slug-here" width="1" height="1" alt="">
 *
 * Params:
 *   t  — type: article, page, podcast, share_click
 *   s  — slug (article slug, page slug, episode slug)
 *   r  — referrer override (optional, defaults to HTTP_REFERER)
 *   c  — campaign/source tag (optional)
 *
 * Logs to: /admin/data/pixel_stats/YYYY-MM-DD.jsonl (one JSON line per hit)
 * No auth required — public endpoint (like a tracking pixel should be).
 *
 * @package LuminalCMS
 * @version 1.0.0
 */
declare(strict_types=1);

// Serve 1x1 transparent GIF immediately — don't make the browser wait
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
// 1x1 transparent GIF (43 bytes)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// Flush to client so the pixel loads instantly
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) ob_end_flush();
    flush();
}

// ── Log the hit (after response is sent) ──
$siteRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
$logDir   = $siteRoot . '/admin/data/pixel_stats';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

$today   = date('Y-m-d');
$logFile = $logDir . '/' . $today . '.jsonl';

$type    = substr(preg_replace('/[^a-z_]/', '', strtolower($_GET['t'] ?? 'page')), 0, 30);
$slug    = substr(preg_replace('/[^a-zA-Z0-9._\-]/', '', $_GET['s'] ?? ''), 0, 120);
$referer = $_GET['r'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$campaign = substr(preg_replace('/[^a-zA-Z0-9._\-]/', '', $_GET['c'] ?? ''), 0, 50);

$entry = [
    'ts'   => date('c'),
    'type' => $type,
    'slug' => $slug,
    'ip'   => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    'ref'  => substr($referer, 0, 500),
    'host' => $_SERVER['HTTP_HOST'] ?? '',
];
if ($campaign) $entry['campaign'] = $campaign;

@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
@chmod($logFile, 0664);
