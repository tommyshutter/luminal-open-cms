<?php
/**
 * Feed Read API — returns article-archive contents as JSON.
 * Public GET endpoint (read-only, no auth needed).
 *
 * Query params:
 *   limit  — max articles to return (default: 20, max: 100)
 *   type   — filter by article_type (optional)
 *   since  — ISO timestamp; only return articles newer than this (for polling)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

$limit      = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$typeFilter = preg_replace('/[^a-z_]/', '', strtolower($_GET['type'] ?? ''));
$since      = $_GET['since'] ?? '';
$sinceTs    = ($since !== '') ? strtotime($since) : 0;

$archiveDir = SITE_ROOT . '/admin/data/article-archive';
if (!is_dir($archiveDir)) {
    echo json_encode(['ok' => true, 'articles' => [], 'count' => 0]);
    exit;
}

$files = glob($archiveDir . '/*.json') ?: [];
if (empty($files)) {
    echo json_encode(['ok' => true, 'articles' => [], 'count' => 0]);
    exit;
}

// Sort newest first by filename (date-prefixed)
rsort($files);

$articles = [];
foreach ($files as $file) {
    if (count($articles) >= $limit) break;

    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || empty($data['title'])) continue;

    // Type filter
    if ($typeFilter !== '' && ($data['article_type'] ?? '') !== $typeFilter) continue;

    // Since filter (for polling — skip articles older than timestamp)
    if ($sinceTs > 0) {
        $articleTs = strtotime($data['generated_at'] ?? '');
        if ($articleTs && $articleTs <= $sinceTs) continue;
    }

    // Return safe subset (no raw HTML in lightweight poll mode if since is set)
    $entry = [
        'title'        => $data['title'] ?? '',
        'slug'         => $data['slug'] ?? '',
        'article_type' => $data['article_type'] ?? '',
        'generated_at' => $data['generated_at'] ?? '',
        'domain'       => $data['domain'] ?? '',
        'source'       => $data['source_pipeline'] ?? '',
    ];

    // Include HTML only if not in poll-since mode (full load vs incremental)
    if ($sinceTs === 0) {
        $entry['html'] = $data['html'] ?? '';
    } else {
        $entry['html'] = $data['html'] ?? '';
        // Always include for incremental — the JS needs it to render new cards
    }

    $articles[] = $entry;
}

echo json_encode([
    'ok'       => true,
    'articles' => $articles,
    'count'    => count($articles),
    'as_of'    => date('c'),
]);
