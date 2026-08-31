<?php
/**
 * PayPal Shopping Cart System - Admin Settings Include
 * Handles admin-specific settings and configuration
 * 
 * File: /admin/paypal/includes/admin-settings.php
 * Version: 2025.10.01
 */

// Prevent direct access
if (!defined('PAYPAL_SYSTEM')) {
    exit('Direct access denied');
}

// Admin settings configuration
define('ADMIN_ITEMS_PER_PAGE', 12); // 3 rows x 4 columns grid
define('ADMIN_SESSION_TIMEOUT', 3600); // 1 hour
define('ADMIN_MAX_UPLOAD_SIZE', 10485760); // 10MB
define('ADMIN_ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

/**
 * Initialize admin session and security
 */
function paypal_init_admin_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate CSRF token if not exists
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    // Check session timeout
    if (isset($_SESSION['admin_last_activity'])) {
        if (time() - $_SESSION['admin_last_activity'] > ADMIN_SESSION_TIMEOUT) {
            session_destroy();
            header('Location: ' . PAYPAL_URL . '/index.php?view=login&timeout=1');
            exit;
        }
    }
    
    $_SESSION['admin_last_activity'] = time();
}

/**
 * Verify CSRF token
 */
function paypal_verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token for forms
 */
function paypal_get_csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Check if user is admin
 */
function paypal_is_admin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Admin authentication
 */
function paypal_admin_login($username, $password) {
    // Simple authentication - in production, use proper password hashing
    $admin_users = [
        'admin' => password_hash('admin123', PASSWORD_DEFAULT),
        'manager' => password_hash('manager123', PASSWORD_DEFAULT)
    ];
    
    if (isset($admin_users[$username]) && password_verify($password, $admin_users[$username])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_login_time'] = time();
        return true;
    }
    
    return false;
}

/**
 * Admin logout
 */
function paypal_admin_logout() {
    session_destroy();
    header('Location: ' . PAYPAL_URL . '/index.php?view=login&logout=1');
    exit;
}

/**
 * Get admin statistics
 */
function paypal_get_admin_stats() {
    $products = paypal_load_products();
    $enabled_count = 0;
    
    foreach ($products as $product) {
        if ($product['enabled'] ?? false) {
            $enabled_count++;
        }
    }
    
    return [
        'total_products' => count($products),
        'enabled_products' => $enabled_count,
        'disabled_products' => count($products) - $enabled_count,
        'total_categories' => count(array_unique(array_column($products, 'category'))),
        'storage_used' => paypal_get_storage_usage(),
        'last_backup' => paypal_get_last_backup_time()
    ];
}

/**
 * Get storage usage
 */
function paypal_get_storage_usage() {
    $data_dir = PAYPAL_DATA_PATH;
    $media_dir = PAYPAL_MEDIA_PATH;
    $size = 0;
    
    if (is_dir($data_dir)) {
        $size += paypal_get_directory_size($data_dir);
    }
    
    if (is_dir($media_dir)) {
        $size += paypal_get_directory_size($media_dir);
    }
    
    return paypal_format_bytes($size);
}

/**
 * Get directory size recursively
 */
function paypal_get_directory_size($dir) {
    $size = 0;
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}

/**
 * Format bytes to human readable
 */
function paypal_format_bytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Get last backup time
 */
function paypal_get_last_backup_time() {
    $backup_file = PAYPAL_DATA_PATH . '/backup.json';
    if (file_exists($backup_file)) {
        return filemtime($backup_file);
    }
    return null;
}

/**
 * Log admin activity
 */
function paypal_log_admin_activity($action, $details = '') {
    if (!defined('PAYPAL_ENABLE_LOGGING') || !PAYPAL_ENABLE_LOGGING) {
        return;
    }
    
    $log_entry = [
        'timestamp' => time(),
        'user' => $_SESSION['admin_username'] ?? 'unknown',
        'action' => $action,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    $log_file = PAYPAL_DATA_PATH . '/admin.log';
    $log_line = date('Y-m-d H:i:s') . ' - ' . json_encode($log_entry) . PHP_EOL;
    
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
}

/**
 * Sanitize input for admin forms
 */
function paypal_sanitize_admin_input($input, $type = 'text') {
    switch ($type) {
        case 'text':
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        case 'number':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'json':
            return json_decode($input, true);
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Validate admin permissions for specific actions
 */
function paypal_check_admin_permission($action) {
    if (!paypal_is_admin()) {
        return false;
    }
    
    // Define permission levels (expandable for role-based access)
    $permissions = [
        'view_products' => true,
        'edit_products' => true,
        'delete_products' => true,
        'import_data' => true,
        'export_data' => true,
        'view_settings' => true,
        'edit_settings' => ($_SESSION['admin_username'] ?? '') === 'admin'
    ];
    
    return $permissions[$action] ?? false;
}

// Initialize admin session
paypal_init_admin_session();
?>