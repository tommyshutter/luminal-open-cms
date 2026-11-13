<?php
/**
 * Menu Settings API Handler
 * File: /admin/menu-manager/handler.php
 * Version: 2025.10.24.2100
 * 
 * Handles loading and saving menu settings to /admin/data/menu/menu_settings.json
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

if (!defined('SITE_ROOT')) { 
    define('SITE_ROOT', dirname(__DIR__, 3)); 
}

// Data paths
$MENU_DIR = SITE_ROOT . '/admin/data/menu';
$MENU_SETTINGS_JSON = $MENU_DIR . '/menu_settings.json';

// Ensure directory exists
if (!is_dir($MENU_DIR)) {
    mkdir($MENU_DIR, 0755, true);
}

// Default settings
$defaults = [
    'menu_bg_color' => '#000000',
    'menu_bg_opacity' => 0.95,
    'menu_item_bg_color' => '#1a1a1a',
    'menu_item_hover_bg_color' => '#007bff',
    'menu_font_color' => '#ffffff',
    'menu_font_hover_color' => '#ffffff',
    'menu_font_family' => 'Arial, sans-serif',
    'menu_font_size' => 16,
    'menu_font_weight' => 400,
    'menu_font_style' => 'normal'
];

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

// GET - Load settings
if ($method === 'GET') {
    $settings = $defaults;
    
    if (is_file($MENU_SETTINGS_JSON)) {
        $raw = @file_get_contents($MENU_SETTINGS_JSON);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $settings = array_merge($defaults, $data);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    exit;
}

// POST - Save settings
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
        exit;
    }
    
    // Validate and sanitize settings
    $settings = [];
    
    // Colors (hex format)
    foreach (['menu_bg_color', 'menu_item_bg_color', 'menu_item_hover_bg_color', 
              'menu_font_color', 'menu_font_hover_color'] as $key) {
        if (isset($data[$key])) {
            $value = trim($data[$key]);
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $settings[$key] = $value;
            }
        }
    }
    
    // Opacity (0-1)
    if (isset($data['menu_bg_opacity'])) {
        $val = (float)$data['menu_bg_opacity'];
        $settings['menu_bg_opacity'] = max(0, min(1, $val));
    }
    
    // Font family
    if (isset($data['menu_font_family'])) {
        $settings['menu_font_family'] = trim($data['menu_font_family']);
    }
    
    // Font size (10-32 px)
    if (isset($data['menu_font_size'])) {
        $val = (int)$data['menu_font_size'];
        $settings['menu_font_size'] = max(10, min(32, $val));
    }
    
    // Font weight (100-900)
    if (isset($data['menu_font_weight'])) {
        $val = (int)$data['menu_font_weight'];
        $val = max(100, min(900, $val));
        $settings['menu_font_weight'] = (int)(round($val / 100) * 100);
    }
    
    // Font style
    if (isset($data['menu_font_style'])) {
        $val = trim($data['menu_font_style']);
        if (in_array($val, ['normal', 'italic'])) {
            $settings['menu_font_style'] = $val;
        }
    }
    
    // Merge with current settings
    $current = $defaults;
    if (is_file($MENU_SETTINGS_JSON)) {
        $raw = @file_get_contents($MENU_SETTINGS_JSON);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $current = array_merge($defaults, $data);
            }
        }
    }
    
    $finalSettings = array_merge($current, $settings);
    
    // Save to file
    $json = json_encode($finalSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $result = file_put_contents($MENU_SETTINGS_JSON, $json);
    
    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save settings']);
        exit;
    }
    
    // Create backup
    $backupPath = $MENU_DIR . '/menu_settings.backup.' . date('YmdHis') . '.json';
    @file_put_contents($backupPath, $json);
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully',
        'settings' => $finalSettings
    ]);
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
