<?php
/**
 * My Store System - Comprehensive Settings Management
 * Complete PHP implementation for store settings
 *
 * File: /admin/modules/MyStore/includes/mystore-settings.php
 * Version: 3.0.0
 */

// Prevent direct access
if (!defined('PAYPAL_SYSTEM')) {
    exit('Direct access denied');
}

/**
 * Default store settings — generic (no PayPal-specific fields)
 */
function paypal_get_default_settings() {
    return [
        // General Settings
        'storeName' => 'My Store',
        'storeDescription' => 'Premium products and accessories',
        'currency' => 'USD',

        // Display Settings
        'storeTitleFont' => '',
        'storeTitleSize' => '2rem',
        'storeBodyFont' => '',
        'showStoreTitle' => true,
        'showStoreDescription' => true,
        'showStatsBar' => true,
        'productCardBg' => '',
        'productCardTextColor' => '',
        'storeBgColor' => '',
        'storeBgImage' => '',
        'taxRate' => 0,
        'shippingCost' => 5.99,
        'freeShippingThreshold' => 50,

        // Feature Settings
        'enableInventoryTracking' => false,
        'enableReviews' => true,
        'enableWishlist' => true,
        'emailNotifications' => true,

        // Advanced Settings
        'orderPrefix' => 'ORD',
        'defaultCategory' => 'General',
        'imageQuality' => 80,
        'maxFileSize' => 10,

        // Business / Seller Settings
        'businessName' => '',
        'businessAddress' => '',
        'businessCity' => '',
        'businessState' => '',
        'businessZip' => '',
        'businessCountry' => 'United States',
        'businessPhone' => '',
        'businessEmail' => '',
        'businessTaxId' => '',
        'businessWebsite' => '',

        // Policy Settings
        'processingFees' => 2.9,
        'refundPolicy' => 'Returns accepted within 30 days of purchase',
        'returnPolicyDays' => 30,
        'disputeHandling' => 'auto',
        'fraudProtection' => true,

        // Response Messages
        'thankYouMessage' => 'Thank you for your purchase! Your order has been received and is being processed.',
        'orderPlacedMessage' => 'Your order #{{ORDER_ID}} has been placed successfully. You will receive a confirmation email shortly.',
        'paymentSuccessMessage' => 'Payment successful! Thank you for your business.',
        'paymentFailedMessage' => 'Payment failed. Please check your payment details and try again.',
        'orderConfirmationEmail' => 'Your order #{{ORDER_ID}} has been confirmed. Estimated delivery: {{DELIVERY_DATE}}',
        'shippingNotificationEmail' => 'Your order #{{ORDER_ID}} has been shipped. Tracking number: {{TRACKING_NUMBER}}',
        'deliveryConfirmationEmail' => 'Your order #{{ORDER_ID}} has been delivered. Thank you for shopping with us!',
        'refundNotificationEmail' => 'Your refund for order #{{ORDER_ID}} has been processed. Amount: {{REFUND_AMOUNT}}',
        'checkoutWelcomeMessage' => 'Welcome to our secure checkout. Please review your order below.',
        'cartEmptyMessage' => 'Your cart is empty. Browse our products to get started!',
        'productUnavailableMessage' => 'Sorry, this product is currently unavailable.',
        'outOfStockMessage' => 'This item is currently out of stock. Please check back later.'
    ];
}

/**
 * Load store settings from file
 */
function paypal_load_settings() {
    $settings_file = PAYPAL_DATA_PATH . '/settings.json';

    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        if (is_array($settings)) {
            // Merge with defaults to ensure all keys exist
            return array_merge(paypal_get_default_settings(), $settings);
        }
    }

    return paypal_get_default_settings();
}

/**
 * Save store settings to file
 */
function paypal_save_settings($settings) {
    $settings_file = PAYPAL_DATA_PATH . '/settings.json';

    // Ensure directory exists
    if (!is_dir(PAYPAL_DATA_PATH)) {
        mkdir(PAYPAL_DATA_PATH, 0755, true);
    }

    // Validate and sanitize settings
    $sanitized_settings = paypal_sanitize_settings($settings);

    // Save to file
    $result = file_put_contents($settings_file, json_encode($sanitized_settings, JSON_PRETTY_PRINT));

    if ($result !== false) {
        paypal_log_admin_activity('settings_updated', 'Store settings updated');
        return true;
    }

    return false;
}

/**
 * Sanitize settings data
 */
function paypal_sanitize_settings($settings) {
    $defaults = paypal_get_default_settings();
    $sanitized = [];

    foreach ($defaults as $key => $default_value) {
        if (isset($settings[$key])) {
            $value = $settings[$key];

            // Sanitize based on key type
            switch ($key) {
                case 'taxRate':
                case 'shippingCost':
                case 'freeShippingThreshold':
                case 'processingFees':
                    $sanitized[$key] = max(0, floatval($value));
                    break;

                case 'imageQuality':
                    $sanitized[$key] = max(1, min(100, intval($value)));
                    break;

                case 'maxFileSize':
                    $sanitized[$key] = max(1, min(100, intval($value)));
                    break;

                case 'returnPolicyDays':
                    $sanitized[$key] = max(0, min(365, intval($value)));
                    break;

                case 'businessEmail':
                    $sanitized[$key] = filter_var($value, FILTER_SANITIZE_EMAIL);
                    break;

                case 'businessWebsite':
                    $sanitized[$key] = filter_var($value, FILTER_SANITIZE_URL);
                    break;

                case 'enableInventoryTracking':
                case 'enableReviews':
                case 'enableWishlist':
                case 'emailNotifications':
                case 'fraudProtection':
                case 'showStoreTitle':
                case 'showStoreDescription':
                case 'showStatsBar':
                    $sanitized[$key] = (bool)$value;
                    break;

                default:
                    $sanitized[$key] = sanitize_text_field($value);
                    break;
            }
        } else {
            $sanitized[$key] = $default_value;
        }
    }

    return $sanitized;
}

/**
 * Export settings to JSON file
 */
function paypal_export_settings() {
    $settings = paypal_load_settings();
    $filename = 'mystore-settings-' . date('Y-m-d') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

    echo json_encode($settings, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Import settings from JSON file
 */
function paypal_import_settings($file) {
    $result = [
        'success' => false,
        'message' => '',
        'errors' => []
    ];

    // Validate file
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        $result['message'] = 'No file uploaded';
        return $result;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['message'] = 'File upload error: ' . $file['error'];
        return $result;
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'json') {
        $result['message'] = 'Invalid file type. Only JSON files are allowed.';
        return $result;
    }

    // Parse JSON
    $json_content = file_get_contents($file['tmp_name']);
    $imported_settings = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $result['message'] = 'Invalid JSON format: ' . json_last_error_msg();
        return $result;
    }

    if (!is_array($imported_settings)) {
        $result['message'] = 'Invalid settings format';
        return $result;
    }

    // Merge with current settings
    $current_settings = paypal_load_settings();
    $merged_settings = array_merge($current_settings, $imported_settings);

    // Save merged settings
    if (paypal_save_settings($merged_settings)) {
        $result['success'] = true;
        $result['message'] = 'Settings imported successfully';
        paypal_log_admin_activity('settings_imported', 'Settings imported from ' . $file['name']);
    } else {
        $result['message'] = 'Failed to save imported settings';
    }

    return $result;
}

/**
 * Reset settings to defaults
 */
function paypal_reset_settings() {
    $defaults = paypal_get_default_settings();

    if (paypal_save_settings($defaults)) {
        paypal_log_admin_activity('settings_reset', 'Settings reset to defaults');
        return true;
    }

    return false;
}

/**
 * Get currency options
 */
function paypal_get_currency_options() {
    return [
        'USD' => 'USD - US Dollar',
        'EUR' => 'EUR - Euro',
        'GBP' => 'GBP - British Pound',
        'CAD' => 'CAD - Canadian Dollar',
        'AUD' => 'AUD - Australian Dollar'
    ];
}

/**
 * Get country options
 */
function paypal_get_country_options() {
    return [
        'United States' => 'United States',
        'Canada' => 'Canada',
        'United Kingdom' => 'United Kingdom',
        'Australia' => 'Australia',
        'Germany' => 'Germany',
        'France' => 'France',
        'Other' => 'Other'
    ];
}

/**
 * Get account type options
 */
function paypal_get_account_type_options() {
    return [
        'business' => 'Business',
        'personal' => 'Personal',
        'nonprofit' => 'Non-Profit'
    ];
}

/**
 * Get dispute handling options
 */
function paypal_get_dispute_handling_options() {
    return [
        'auto' => 'Automatic',
        'manual' => 'Manual Review',
        'escalate' => 'Always Escalate'
    ];
}

/**
 * Get available message variables
 */
function paypal_get_message_variables() {
    return [
        '{{ORDER_ID}}' => 'Order number',
        '{{CUSTOMER_NAME}}' => 'Customer name',
        '{{CUSTOMER_EMAIL}}' => 'Customer email',
        '{{ORDER_TOTAL}}' => 'Order total amount',
        '{{DELIVERY_DATE}}' => 'Estimated delivery date',
        '{{TRACKING_NUMBER}}' => 'Shipping tracking number',
        '{{REFUND_AMOUNT}}' => 'Refund amount',
        '{{STORE_NAME}}' => 'Your store name'
    ];
}

/**
 * Process variable substitutions in messages
 */
function paypal_process_message_variables($message, $variables = []) {
    $defaults = [
        '{{ORDER_ID}}' => 'ORD-' . date('Ymd') . '-' . rand(1000, 9999),
        '{{CUSTOMER_NAME}}' => 'John Doe',
        '{{CUSTOMER_EMAIL}}' => 'customer@example.com',
        '{{ORDER_TOTAL}}' => '$0.00',
        '{{DELIVERY_DATE}}' => date('Y-m-d', strtotime('+5 days')),
        '{{TRACKING_NUMBER}}' => 'TRK' . rand(100000, 999999),
        '{{REFUND_AMOUNT}}' => '$0.00',
        '{{STORE_NAME}}' => paypal_load_settings()['storeName'] ?? 'My Store'
    ];

    $variables = array_merge($defaults, $variables);

    return str_replace(array_keys($variables), array_values($variables), $message);
}

/**
 * Get setting by key with default fallback
 */
function paypal_get_setting($key, $default = null) {
    $settings = paypal_load_settings();
    return $settings[$key] ?? $default;
}

/**
 * Update a single setting
 */
function paypal_update_setting($key, $value) {
    $settings = paypal_load_settings();
    $settings[$key] = $value;
    return paypal_save_settings($settings);
}

/**
 * Handle AJAX settings requests
 */
function paypal_handle_settings_ajax() {
    if (!paypal_is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'load_settings':
            echo json_encode([
                'success' => true,
                'settings' => paypal_load_settings()
            ]);
            break;

        case 'save_settings':
            $settings = json_decode($_POST['settings'] ?? '{}', true);
            if (paypal_save_settings($settings)) {
                echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save settings']);
            }
            break;

        case 'reset_settings':
            if (paypal_reset_settings()) {
                echo json_encode(['success' => true, 'message' => 'Settings reset to defaults']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to reset settings']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }

    exit;
}
?>
