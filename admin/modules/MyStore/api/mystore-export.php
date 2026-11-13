<?php
/**
 * PayPal Shopping Cart System - Export API
 * 
 * File: /admin/paypal/api/paypal-export.php
 * Version: 2025.09.30.18.35
 */

// Define system constant
define('PAYPAL_SYSTEM', true);

// Include configuration and functions
require_once dirname(__DIR__) . '/includes/paypal-config.php';
require_once dirname(__DIR__) . '/includes/paypal-functions.php';

// Check authentication
if (!paypal_is_logged_in()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Check permissions
if (!paypal_has_permission('read')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Insufficient permissions');
}

try {
    // Export products to CSV
    paypal_export_products_to_csv();
} catch (Exception $e) {
    paypal_log('Export error: ' . $e->getMessage(), 'error', [
        'user' => paypal_get_current_user()['email'] ?? 'guest'
    ]);
    header('HTTP/1.1 500 Internal Server Error');
    exit('Export failed');
}
?>