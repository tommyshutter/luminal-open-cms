<?php
/**
 * PayPal Shopping Cart System - Product Duplicate API
 * 
 * File: /admin/paypal/api/paypal-duplicate.php
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
    if (!paypal_has_permission('create')) {
        paypal_json_response(false, null, 'Insufficient permissions', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['sku'])) {
        paypal_json_response(false, null, 'SKU is required', 400);
    }
    
    $result = paypal_duplicate_product($input['sku']);
    
    if ($result['success']) {
        paypal_json_response(true, $result['product'], $result['message'] ?? 'Product duplicated successfully');
    } else {
        paypal_json_response(false, null, $result['message'] ?? 'Failed to duplicate product', 400);
    }
    
} catch (Exception $e) {
    paypal_log('Product duplicate API error: ' . $e->getMessage(), 'error', [
        'method' => $method,
        'user' => paypal_get_current_user()['email'] ?? 'guest'
    ]);
    paypal_json_response(false, null, 'Internal server error', 500);
}
?>