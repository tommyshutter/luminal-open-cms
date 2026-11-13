<?php
/**
 * My Store — Cart API
 *
 * File: /admin/modules/MyStore/api/mystore-cart.php
 */

// Define system constant
define('MYSTORE_SYSTEM', true);
if (!defined('PAYPAL_SYSTEM')) define('PAYPAL_SYSTEM', true); // backward compat

// Include configuration and functions
require_once dirname(__DIR__) . '/includes/mystore-config.php';
require_once dirname(__DIR__) . '/includes/mystore-functions.php';
require_once dirname(__DIR__) . '/includes/mystore-shipping.php';

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
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
            paypal_json_response(false, null, 'Invalid CSRF token', 403);
        }
    }
    
    if ($method === 'GET') {
        handleGetCart();
    } else if ($method === 'POST') {
        handleCartAction();
    } else {
        paypal_json_response(false, null, 'Method not allowed', 405);
    }
} catch (Exception $e) {
    paypal_log('Cart API error: ' . $e->getMessage(), 'error', [
        'method' => $method,
        'user' => paypal_get_current_user()['email'] ?? 'guest'
    ]);
    paypal_json_response(false, null, 'Internal server error', 500);
}

function handleGetCart() {
    $action = $_GET['action'] ?? 'get';
    
    switch ($action) {
        case 'get':
            $cartItems = paypal_get_cart_items();
            $subtotal = paypal_get_cart_total();
            
            // Format cart items for response
            $formattedItems = [];
            foreach ($cartItems as $cartKey => $item) {
                $formattedItems[] = [
                    'cartKey' => $cartKey,
                    'product' => $item['product'],
                    'quantity' => $item['quantity'],
                    'variations' => $item['variations'],
                    'added_at' => $item['added_at']
                ];
            }
            
            $cartQty   = array_sum(array_column($cartItems, 'quantity'));
            $psettings = function_exists('paypal_get_all_settings') ? paypal_get_all_settings() : [];
            if (!is_array($psettings)) $psettings = [];
            $taxRate   = floatval($psettings['taxRate'] ?? 0);
            $taxAmt    = round($subtotal * ($taxRate / 100), 2);
            $shipAmt   = mystore_calc_shipping((int)$cartQty, (float)$subtotal, $psettings);
            paypal_json_response(true, [
                'items' => $formattedItems,
                'subtotal' => $subtotal,
                'count' => $cartQty,
                'tax' => $taxAmt,
                'shipping' => $shipAmt,
                'total' => round($subtotal + $taxAmt + $shipAmt, 2)
            ], 'Cart retrieved successfully');
            break;
            
        case 'count':
            $cartItems = paypal_get_cart_items();
            $count = array_sum(array_column($cartItems, 'quantity'));
            paypal_json_response(true, ['count' => $count], 'Cart count retrieved');
            break;
            
        default:
            paypal_json_response(false, null, 'Invalid action', 400);
            break;
    }
}

function handleCartAction() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['action'])) {
        paypal_json_response(false, null, 'Action is required', 400);
        return;
    }
    
    switch ($input['action']) {
        case 'add':
            handleAddToCart($input);
            break;
            
        case 'update':
            handleUpdateCart($input);
            break;
            
        case 'remove':
            handleRemoveFromCart($input);
            break;
            
        case 'clear':
            handleClearCart();
            break;
            
        default:
            paypal_json_response(false, null, 'Invalid action', 400);
            break;
    }
}

function handleAddToCart($input) {
    if (empty($input['sku'])) {
        paypal_json_response(false, null, 'SKU is required', 400);
        return;
    }
    
    $sku = $input['sku'];
    $quantity = max(1, intval($input['quantity'] ?? 1));
    $variations = $input['variations'] ?? [];
    
    // Verify product exists and is enabled
    $product = paypal_get_product_by_sku($sku);
    if (!$product || !($product['enabled'] ?? false)) {
        paypal_json_response(false, null, 'Product not found or not available', 404);
        return;
    }
    
    // Validate required variations
    if (!empty($product['variations']['groups'])) {
        foreach ($product['variations']['groups'] as $group) {
            if (!empty($group['options']) && empty($variations[$group['id']])) {
                paypal_json_response(false, null, "Please select {$group['name']}", 400);
                return;
            }
        }
    }
    
    // Validate quantity
    if ($quantity < 1 || $quantity > 99) {
        paypal_json_response(false, null, 'Invalid quantity (1-99)', 400);
        return;
    }
    
    paypal_add_to_cart($sku, $quantity, $variations);
    
    paypal_json_response(true, null, 'Product added to cart successfully');
}

function handleUpdateCart($input) {
    if (empty($input['cartKey'])) {
        paypal_json_response(false, null, 'Cart key is required', 400);
        return;
    }
    
    $cartKey = $input['cartKey'];
    $quantity = max(1, intval($input['quantity'] ?? 1));
    
    if (!isset($_SESSION['mystore_cart'][$cartKey])) {
        paypal_json_response(false, null, 'Cart item not found', 404);
        return;
    }
    
    // Validate quantity
    if ($quantity < 1 || $quantity > 99) {
        paypal_json_response(false, null, 'Invalid quantity (1-99)', 400);
        return;
    }
    
    $_SESSION['mystore_cart'][$cartKey]['quantity'] = $quantity;
    
    paypal_log("Cart item updated", 'info', [
        'cartKey' => $cartKey,
        'quantity' => $quantity
    ]);
    
    paypal_json_response(true, null, 'Cart updated successfully');
}

function handleRemoveFromCart($input) {
    if (empty($input['cartKey'])) {
        paypal_json_response(false, null, 'Cart key is required', 400);
        return;
    }
    
    $cartKey = $input['cartKey'];
    
    if (!isset($_SESSION['mystore_cart'][$cartKey])) {
        paypal_json_response(false, null, 'Cart item not found', 404);
        return;
    }
    
    paypal_remove_from_cart($cartKey);
    
    paypal_json_response(true, null, 'Item removed from cart successfully');
}

function handleClearCart() {
    $cartCount = count($_SESSION['mystore_cart'] ?? []);
    paypal_clear_cart();
    
    paypal_log("Cart cleared", 'info', ['items_removed' => $cartCount]);
    
    paypal_json_response(true, null, 'Cart cleared successfully');
}
?>