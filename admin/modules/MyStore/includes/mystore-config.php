<?php
/**
 * My Store System - Configuration
 * Dark Theme Configuration Settings
 *
 * File: /admin/modules/MyStore/includes/mystore-config.php
 * Version: 3.0.0
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

// Application Configuration
define('PAYPAL_VERSION', '3.0.0');
define('PAYPAL_NAME', 'My Store');
define('PAYPAL_DESCRIPTION', 'Product Store System');

// Path Configuration
define('PAYPAL_ROOT', dirname(dirname(__FILE__)));
define('PAYPAL_URL', '/admin/modules/MyStore');

// Data paths — use mystore if it exists, fall back to legacy paypal
$_mystore_data = $_SERVER['DOCUMENT_ROOT'] . '/admin/data/mystore';
$_mystore_media = '/media/store';
if (!is_dir($_mystore_data) && is_dir($_SERVER['DOCUMENT_ROOT'] . '/admin/data/paypal')) {
    $_mystore_data = $_SERVER['DOCUMENT_ROOT'] . '/admin/data/paypal';
}
if (!is_dir($_SERVER['DOCUMENT_ROOT'] . $_mystore_media) && is_dir($_SERVER['DOCUMENT_ROOT'] . '/media/paypal')) {
    $_mystore_media = '/media/paypal';
}

define('PAYPAL_DATA_PATH', $_mystore_data);
define('PAYPAL_PRODUCTS_PATH', PAYPAL_DATA_PATH . '/products');
define('PAYPAL_MEDIA_URL', $_mystore_media);
define('PAYPAL_MEDIA_PATH', $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL);

// Authentication Configuration (handled by guard.php in admin shell)
define('PAYPAL_AUTH_ENABLED', false);
define('PAYPAL_DEFAULT_USER', [
    'id' => 1,
    'name' => 'Admin User',
    'email' => 'admin@example.com',
    'role' => 'admin'
]);

// Database Configuration (for future use)
define('PAYPAL_USE_DATABASE', false);
define('PAYPAL_DB_HOST', 'localhost');
define('PAYPAL_DB_NAME', 'mystore');
define('PAYPAL_DB_USER', 'mystore_user');
define('PAYPAL_DB_PASS', 'mystore_pass');

// Security Configuration
define('PAYPAL_SESSION_NAME', 'mystore_session');
define('PAYPAL_CSRF_TOKEN_NAME', 'mystore_csrf_token');

// File Upload Configuration
define('PAYPAL_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('PAYPAL_ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Logging Configuration
define('PAYPAL_LOG_ENABLED', true);
define('PAYPAL_LOG_FILE', PAYPAL_ROOT . '/logs/mystore.log');
define('PAYPAL_LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR

// Default Product Settings
define('PAYPAL_DEFAULT_CURRENCY', 'USD');
define('PAYPAL_CURRENCY_SYMBOL', '$');

// Error Reporting (for development)
if (defined('PAYPAL_DEBUG') && PAYPAL_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(PAYPAL_SESSION_NAME);
    session_start();
}

// Initialize CSRF token
if (!isset($_SESSION[PAYPAL_CSRF_TOKEN_NAME])) {
    $_SESSION[PAYPAL_CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

// Create necessary directories
$directories = [
    PAYPAL_DATA_PATH,
    PAYPAL_PRODUCTS_PATH,
    PAYPAL_DATA_PATH . '/uploads',
    PAYPAL_DATA_PATH . '/backups',
    PAYPAL_DATA_PATH . '/settings',
    dirname(PAYPAL_LOG_FILE)
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Set timezone
date_default_timezone_set('America/New_York');
?>
