<?php
/**
 * PayPal Shopping Cart System - Migration API
 * Handles migration from old JSON format to filesystem-based structure
 * 
 * File: /admin/paypal/api/paypal-migrate.php
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
            handleMigration();
            break;
            
        case 'GET':
            handleMigrationStatus();
            break;
            
        default:
            paypal_json_error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    paypal_log('Migration API Error: ' . $e->getMessage(), 'ERROR');
    paypal_json_error('Internal server error: ' . $e->getMessage(), 500);
}

/**
 * Handle migration from old format to filesystem
 */
function handleMigration() {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'migrate_from_session':
            handleMigrateFromSession();
            break;
            
        case 'migrate_from_file':
            handleMigrateFromFile();
            break;
            
        case 'migrate_from_json':
            handleMigrateFromJson($input);
            break;
            
        case 'convert_csv':
            handleConvertCsv($input);
            break;
            
        default:
            paypal_json_error('Invalid migration action');
    }
}

/**
 * Migrate products from session storage to filesystem
 */
function handleMigrateFromSession() {
    if (!isset($_SESSION['products']) || !is_array($_SESSION['products'])) {
        paypal_json_error('No products found in session');
    }
    
    $sessionProducts = $_SESSION['products'];
    
    $result = paypal_migrate_to_filesystem($sessionProducts);
    
    if ($result['success']) {
        paypal_json_success($result['message'], [
            'migrated' => $result['migrated'],
            'errors' => $result['errors']
        ]);
    } else {
        paypal_json_error($result['message'], 400, [
            'errors' => $result['errors'] ?? []
        ]);
    }
}

/**
 * Migrate from old products.json file
 */
function handleMigrateFromFile() {
    $result = paypal_migrate_to_filesystem();
    
    if ($result['success']) {
        paypal_json_success($result['message'], [
            'migrated' => $result['migrated'],
            'errors' => $result['errors']
        ]);
    } else {
        paypal_json_error($result['message'], 400, [
            'errors' => $result['errors'] ?? []
        ]);
    }
}

/**
 * Migrate from JSON data
 */
function handleMigrateFromJson($input) {
    if (!isset($input['products']) || !is_array($input['products'])) {
        paypal_json_error('Invalid JSON data - products array required');
    }
    
    $result = paypal_migrate_to_filesystem($input['products']);
    
    if ($result['success']) {
        paypal_json_success($result['message'], [
            'migrated' => $result['migrated'],
            'errors' => $result['errors']
        ]);
    } else {
        paypal_json_error($result['message'], 400, [
            'errors' => $result['errors'] ?? []
        ]);
    }
}

/**
 * Convert CSV to filesystem format
 */
function handleConvertCsv($input) {
    if (!isset($input['csv_path']) || !file_exists($input['csv_path'])) {
        paypal_json_error('CSV file not found');
    }
    
    $result = paypal_convert_csv_to_filesystem($input['csv_path']);
    
    if ($result['success']) {
        paypal_json_success($result['message'], [
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'errors' => $result['errors']
        ]);
    } else {
        paypal_json_error($result['message'], 400, [
            'errors' => $result['errors']
        ]);
    }
}

/**
 * Get migration status
 */
function handleMigrationStatus() {
    $status = [
        'filesystem_ready' => is_dir(PAYPAL_PRODUCTS_PATH) && is_writable(PAYPAL_PRODUCTS_PATH),
        'old_file_exists' => file_exists(PAYPAL_DATA_PATH . '/products/products.json'),
        'session_products' => isset($_SESSION['products']) ? count($_SESSION['products']) : 0,
        'filesystem_products' => count(paypal_get_products()),
        'products_path' => PAYPAL_PRODUCTS_PATH,
        'data_path' => PAYPAL_DATA_PATH,
        'media_path' => PAYPAL_MEDIA_PATH
    ];
    
    // Check for individual product directories
    $skuDirectories = [];
    if (is_dir(PAYPAL_PRODUCTS_PATH)) {
        $dirs = scandir(PAYPAL_PRODUCTS_PATH);
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir(PAYPAL_PRODUCTS_PATH . '/' . $dir)) {
                $jsonFile = PAYPAL_PRODUCTS_PATH . '/' . $dir . '/product.json';
                $skuDirectories[] = [
                    'sku' => $dir,
                    'has_json' => file_exists($jsonFile),
                    'path' => PAYPAL_PRODUCTS_PATH . '/' . $dir
                ];
            }
        }
    }
    
    $status['existing_directories'] = $skuDirectories;
    $status['directory_count'] = count($skuDirectories);
    
    paypal_json_success('Migration status retrieved', $status);
}

/**
 * Create sample product data for testing
 */
function createSampleProducts() {
    return [
        [
            'sku' => 'sample-001',
            'enabled' => true,
            'name' => 'Sample Product 1',
            'price' => '19.99',
            'desc' => 'This is a sample product for testing',
            'category' => 'Test',
            'image' => '',
            'variations' => ['groups' => []],
            'mtime' => time()
        ],
        [
            'sku' => 'sample-002',
            'enabled' => true,
            'name' => 'Sample Product 2',
            'price' => '29.99',
            'desc' => 'Another sample product for testing',
            'category' => 'Test',
            'image' => '',
            'variations' => ['groups' => []],
            'mtime' => time()
        ]
    ];
}
?>