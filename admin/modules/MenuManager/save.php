<?php
/**
 * Menu Items Save Handler
 * File: /admin/menu-manager/save.php
 * Version: 2025.10.24.2100
 * 
 * Saves menu items to /admin/data/menu/menu_items.json
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
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!defined('SITE_ROOT')) { 
    define('SITE_ROOT', dirname(__DIR__, 3)); 
}

// Data paths
$MENU_DIR = SITE_ROOT . '/admin/data/menu';
$MENU_ITEMS_JSON = $MENU_DIR . '/menu_items.json';

// Ensure directory exists
if (!is_dir($MENU_DIR)) {
    mkdir($MENU_DIR, 0755, true);
}

// Get POST data
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate and normalize items
$items = [];
foreach ($data as $item) {
    if (!is_array($item)) continue;
    
    $title = trim($item['title'] ?? '');
    $url = trim($item['url'] ?? '');
    $slug = trim($item['slug'] ?? '');
    $type = trim($item['type'] ?? 'page');
    
    if ($title === '' || $url === '') continue;
    
    $items[] = [
        'title' => $title,
        'url' => $url,
        'slug' => $slug,
        'type' => $type
    ];
}

// Save to file
$json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$result = file_put_contents($MENU_ITEMS_JSON, $json);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save menu items']);
    exit;
}

// Also create backup
$backupPath = $MENU_DIR . '/menu_items.backup.' . date('YmdHis') . '.json';
@file_put_contents($backupPath, $json);

echo json_encode([
    'success' => true,
    'message' => 'Menu items saved successfully',
    'count' => count($items)
]);
