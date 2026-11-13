<?php
/**
 * Menu Manager - Save Menu Items
 * POST endpoint for saving menu item order and content
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//
//  This endpoint WRITES the site's navigation and had no authentication of any kind:
//  no guard, no session check, no CSRF token. Anonymous requests reached the JSON parse
//  and, with a well-formed body, would have rewritten the menu on any of the 30 sites.
//  Found by the web-exposure audit, which flagged the file as reachable.
//
//  guard_require_auth() answers API requests with 401 JSON and browser requests with a
//  redirect to the login page, so this is safe for both callers.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../_runtime/guard.php';
guard_require_auth();

header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 3));
}

$MENU_DIR = SITE_ROOT . '/admin/data/menu';
$MENU_ITEMS_FILE = $MENU_DIR . '/menu_items.json';

// Ensure directory exists
if (!is_dir($MENU_DIR)) {
    @mkdir($MENU_DIR, 0755, true);
}

// Read POST data
$raw = file_get_contents('php://input');
if ($raw === false) {
    echo json_encode(['ok' => false, 'error' => 'no_input']);
    exit;
}

$items = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Normalize and validate items
$normalized = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;

    $title = trim((string)($item['title'] ?? ''));
    $url = trim((string)($item['url'] ?? ''));
    $slug = trim((string)($item['slug'] ?? ''));
    $type = trim((string)($item['type'] ?? 'page'));

    if ($title === '' || $url === '') continue;

    $normalized[] = [
        'title' => $title,
        'url' => $url,
        'slug' => $slug,
        'type' => $type
    ];
}

// Atomic write
$tmp = $MENU_ITEMS_FILE . '.tmp';
$json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (@file_put_contents($tmp, $json) === false) {
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}

@chmod($tmp, 0664);  // 664, not 666 — admin/data perms law

if (!@rename($tmp, $MENU_ITEMS_FILE)) {
    @unlink($tmp);
    echo json_encode(['ok' => false, 'error' => 'rename_failed']);
    exit;
}

echo json_encode(['ok' => true, 'count' => count($normalized)]);
