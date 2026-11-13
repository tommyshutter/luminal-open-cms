<?php
/**
 * html-block-io.php — read/write an HTML Block from inside Page Manager, so a
 * [[html-block:slug]] shortcode can be edited in place without leaving the page
 * editor (no round-trip to the HTMLBlocks module, no cross-module CSRF).
 *
 *   GET  ?action=get&slug=…            → { ok, block }
 *   POST  action=save  slug,title,html,css,js  → { ok, slug, block }
 *
 * Auth: the admin session (requireAuth), same as every other PageManager api.
 * @file admin/modules/PageManager/api/html-block-io.php
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/html_block_store.php';

$SITE_ROOT = realpath(dirname(__DIR__, 4));
$DIR = hbstore_dir($SITE_ROOT);

function io_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

if ($action === 'get') {
    $slug = (string)($_GET['slug'] ?? '');
    if (!hbstore_slug_ok($slug)) io_fail('Invalid slug.');
    $block = hbstore_get($DIR, $slug);
    if ($block === null) io_fail('Block not found: ' . htmlspecialchars($slug), 404);
    echo json_encode(['ok' => true, 'block' => $block]);
    exit;
}

if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') io_fail('POST required.', 405);
    $slug = (string)($_POST['slug'] ?? '');
    if (!hbstore_slug_ok($slug)) io_fail('Invalid slug.');
    $block = hbstore_upsert($DIR, $slug, [
        'title' => trim((string)($_POST['title'] ?? '')) ?: $slug,
        'html'  => (string)($_POST['html'] ?? ''),
        'css'   => (string)($_POST['css']  ?? ''),
        'js'    => (string)($_POST['js']   ?? ''),
    ]);
    if ($block === null) io_fail('Write failed.', 500);
    echo json_encode(['ok' => true, 'slug' => $slug, 'block' => $block]);
    exit;
}

io_fail('Unknown action.');
