<?php
/**
 * Feed Push API — accepts article JSON via POST and writes to article-archive.
 *
 * Auth: HMAC-SHA256 signature using site_key from update-config.json
 * Message format: "feed_push:{domain}:{nonce}"
 *
 * POST body (JSON):
 *   signature  — HMAC-SHA256 hex digest
 *   nonce      — unique per-request string
 *   title      — article title (required)
 *   html       — article HTML body (required)
 *   article_type — spotlight|roundup|highlight (optional, default: spotlight)
 *   source     — free-form source identifier (optional)
 *   max_items  — prune archive to this many items after write (optional, default: 50)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$sig   = (string)($input['signature'] ?? '');
$nonce = (string)($input['nonce'] ?? '');

if ($sig === '' || $nonce === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing signature or nonce']);
    exit;
}

// Load site key
$cfgFile = SITE_ROOT . '/admin/data/update-config.json';
if (!is_file($cfgFile)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Site not configured']);
    exit;
}
$cfg     = json_decode((string)file_get_contents($cfgFile), true) ?: [];
$siteKey = $cfg['site_key'] ?? '';
$domain  = $cfg['domain'] ?? ($_SERVER['SERVER_NAME'] ?? '');

if ($siteKey === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No site_key']);
    exit;
}

// Verify HMAC
$expected = hash_hmac('sha256', "feed_push:{$domain}:{$nonce}", $siteKey);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

// ── Validate payload ──────────────────────────────────────

$title = trim((string)($input['title'] ?? ''));
$html  = trim((string)($input['html'] ?? ''));

if ($title === '' || $html === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title and html are required']);
    exit;
}

$articleType = preg_replace('/[^a-z_]/', '', strtolower($input['article_type'] ?? 'spotlight')) ?: 'spotlight';
$source      = (string)($input['source'] ?? 'feed_push');
$maxItems    = max(5, (int)($input['max_items'] ?? 50));

// ── Write article ─────────────────────────────────────────

$archiveDir = SITE_ROOT . '/admin/data/article-archive';
if (!is_dir($archiveDir)) {
    @mkdir($archiveDir, 0775, true);
}

$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
$slug = trim($slug, '-');
if (strlen($slug) > 80) $slug = substr($slug, 0, 80);
$filename = date('Ymd-His') . '-' . $slug . '.json';

$article = [
    'title'           => $title,
    'slug'            => $slug,
    'html'            => $html,
    'source_pipeline' => $source,
    'generated_at'    => date('c'),
    'domain'          => $domain,
    'article_type'    => $articleType,
];

$path = $archiveDir . '/' . $filename;
$written = file_put_contents($path, json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($written === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Write failed']);
    exit;
}
@chmod($path, 0664);
@chown($path, 'www-data');

// ── Prune old articles ───────────────────────────────────

$files = glob($archiveDir . '/*.json') ?: [];
if (count($files) > $maxItems) {
    // Sort oldest first (filenames are date-prefixed)
    sort($files);
    $toDelete = array_slice($files, 0, count($files) - $maxItems);
    foreach ($toDelete as $old) {
        @unlink($old);
    }
}

echo json_encode([
    'ok'       => true,
    'filename' => $filename,
    'pruned'   => count($toDelete ?? []),
]);
