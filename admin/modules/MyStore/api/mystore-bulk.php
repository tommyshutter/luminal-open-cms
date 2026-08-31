<?php
/**
 * PayPal Shopping Cart System - Bulk Actions API
 * 
 * File: /admin/paypal/api/paypal-bulk.php
 * Version: 2025.09.30.18.35
 */

// Define system constant
define('PAYPAL_SYSTEM', true);

// Include configuration and functions
require_once dirname(__DIR__) . '/includes/paypal-config.php';
require_once dirname(__DIR__) . '/includes/paypal-functions.php';

// Set JSON content type
header('Content-Type: application/json');

// Handle CORS if needed
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Only allow POST requests
    if ($method !== 'POST') {
        paypal_json_response(false, null, 'Method not allowed', 405);
    }
    
    // Verify CSRF token
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!paypal_verify_csrf_token($csrfToken)) {
        paypal_json_response(false, null, 'Invalid CSRF token', 403);
    }
    
    // Check permissions
    if (!paypal_has_permission('update')) {
        paypal_json_response(false, null, 'Insufficient permissions', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['action'])) {
        paypal_json_response(false, null, 'Action is required', 400);
    }
    
    switch ($input['action']) {
        case 'enable_all':
            handleEnableAll();
            break;
            
        case 'disable_all':
            handleDisableAll();
            break;
            
        case 'delete_all':
            handleDeleteAll();
            break;
            
        default:
            paypal_json_response(false, null, 'Invalid action', 400);
            break;
    }
} catch (Exception $e) {
    paypal_log('Bulk actions API error: ' . $e->getMessage(), 'error', [
        'method' => $method,
        'user' => paypal_get_current_user()['email'] ?? 'guest'
    ]);
    paypal_json_response(false, null, 'Internal server error', 500);
}

function handleEnableAll() {
    $products = paypal_load_products();
    $updatedCount = 0;
    
    foreach ($products as &$product) {
        if (!($product['enabled'] ?? false)) {
            $product['enabled'] = true;
            $product['mtime'] = time();
            $updatedCount++;
        }
    }
    
    if (paypal_save_products($products)) {
        paypal_log("Bulk enable completed", 'info', ['updated_count' => $updatedCount]);
        paypal_json_response(true, ['updated_count' => $updatedCount], "$updatedCount products enabled successfully");
    } else {
        paypal_json_response(false, null, 'Failed to save products', 500);
    }
}

function handleDisableAll() {
    $products = paypal_load_products();
    $updatedCount = 0;
    
    foreach ($products as &$product) {
        if ($product['enabled'] ?? false) {
            $product['enabled'] = false;
            $product['mtime'] = time();
            $updatedCount++;
        }
    }
    
    if (paypal_save_products($products)) {
        paypal_log("Bulk disable completed", 'info', ['updated_count' => $updatedCount]);
        paypal_json_response(true, ['updated_count' => $updatedCount], "$updatedCount products disabled successfully");
    } else {
        paypal_json_response(false, null, 'Failed to save products', 500);
    }
}

function handleDeleteAll() {
    // Check for additional permission for delete all
    if (!paypal_has_permission('delete')) {
        paypal_json_response(false, null, 'Insufficient permissions for bulk delete', 403);
    }
    
    $products = paypal_load_products();
    $deletedCount = count($products);
    
    // Clear all products
    $emptyProducts = [];
    
    if (paypal_save_products($emptyProducts)) {
        // Clean up all media directories
        $mediaBaseDir = PAYPAL_MEDIA_PATH;
        if (file_exists($mediaBaseDir)) {
            $mediaFolders = scandir($mediaBaseDir);
            foreach ($mediaFolders as $folder) {
                if ($folder !== '.' && $folder !== '..' && is_dir($mediaBaseDir . '/' . $folder)) {
                    paypal_delete_directory($mediaBaseDir . '/' . $folder);
                }
            }
        }
        
        paypal_log("Bulk delete all completed", 'warning', ['deleted_count' => $deletedCount]);
        paypal_json_response(true, ['deleted_count' => $deletedCount], "$deletedCount products deleted successfully");
    } else {
        paypal_json_response(false, null, 'Failed to delete products', 500);
    }
}
?>