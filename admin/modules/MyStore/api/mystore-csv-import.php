<?php
/**
 * PayPal Shopping Cart System - CSV Import API
 * Handles CSV file import to filesystem-based JSON structure
 * 
 * File: /admin/paypal/api/paypal-csv-import.php
 * Version: 2025.10.03.0945 r1
 * Build Date: Friday, October 3, 2025 - 9:45 AM EST
 */

// Define system constant
define('PAYPAL_SYSTEM', true);

// Include configuration and functions
require_once dirname(__DIR__) . '/includes/paypal-config.php';
require_once dirname(__DIR__) . '/includes/paypal-functions.php';
require_once dirname(__DIR__) . '/includes/admin-import.php';

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
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Verify CSRF token for non-GET requests
    if ($method !== 'GET') {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!paypal_verify_csrf_token($csrfToken)) {
            paypal_json_error('Invalid CSRF token', 403);
        }
    }
    
    switch ($method) {
        case 'POST':
            handleCsvImport();
            break;
            
        case 'GET':
            handleGetImportStatus();
            break;
            
        default:
            paypal_json_error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    paypal_log('CSV Import API Error: ' . $e->getMessage(), 'ERROR');
    paypal_json_error('Internal server error: ' . $e->getMessage(), 500);
}

/**
 * Handle CSV file import
 */
function handleCsvImport() {
    // Check if file was uploaded
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        paypal_json_error('No CSV file uploaded or upload error occurred');
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file type
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExtension !== 'csv') {
        paypal_json_error('Invalid file type. Only CSV files are allowed.');
    }
    
    // Validate file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        paypal_json_error('File too large. Maximum size is 10MB.');
    }
    
    try {
        // Process the CSV import using the new filesystem functions
        $result = paypal_handle_csv_import($file);
        
        if ($result['success']) {
            paypal_json_success($result['message'], [
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'] ?? [],
                'products' => $result['products'] ?? []
            ]);
        } else {
            paypal_json_error($result['message'], 400, [
                'errors' => $result['errors'],
                'warnings' => $result['warnings'] ?? []
            ]);
        }
        
    } catch (Exception $e) {
        paypal_log('CSV Import Error: ' . $e->getMessage(), 'ERROR');
        paypal_json_error('Import failed: ' . $e->getMessage());
    }
}

/**
 * Handle getting import status/info
 */
function handleGetImportStatus() {
    // Get current product count
    $products = paypal_get_products();
    $productCount = count($products);
    
    // Get available backup files
    $backups = paypal_get_backup_files();
    
    // Get filesystem info
    $filesystemInfo = [
        'products_directory' => PAYPAL_PRODUCTS_PATH,
        'products_count' => $productCount,
        'data_path' => PAYPAL_DATA_PATH,
        'backup_count' => count($backups),
        'filesystem_readable' => is_readable(PAYPAL_PRODUCTS_PATH),
        'filesystem_writable' => is_writable(PAYPAL_PRODUCTS_PATH)
    ];
    
    paypal_json_success('Import status retrieved', [
        'filesystem' => $filesystemInfo,
        'recent_backups' => array_slice($backups, 0, 5) // Last 5 backups
    ]);
}

/**
 * Create admin activity log function if it doesn't exist
 */
if (!function_exists('paypal_log_admin_activity')) {
    function paypal_log_admin_activity($action, $details = '') {
        paypal_log("Admin Activity - $action: $details", 'INFO');
    }
}

/**
 * Add upload size constants if they don't exist
 */
if (!defined('ADMIN_MAX_UPLOAD_SIZE')) {
    define('ADMIN_MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
}

/**
 * Format bytes utility
 */
if (!function_exists('paypal_format_bytes')) {
    function paypal_format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
?>