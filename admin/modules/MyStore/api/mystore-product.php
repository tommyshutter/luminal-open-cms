<?php
/**
 * PayPal Shopping Cart System - Product API
 * 
 * File: /admin/paypal/api/paypal-product.php
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
            paypal_json_response(false, null, 'Invalid CSRF token', 403);
        }
    }
    
    switch ($method) {
        case 'GET':
            handleGetProduct();
            break;
            
        case 'POST':
            handleCreateProduct();
            break;
            
        case 'PUT':
        case 'PATCH':
            handleUpdateProduct();
            break;
            
        case 'DELETE':
            handleDeleteProduct();
            break;
            
        default:
            paypal_json_response(false, null, 'Method not allowed', 405);
            break;
    }
} catch (Exception $e) {
    paypal_log('Product API error: ' . $e->getMessage(), 'error', [
        'method' => $method,
        'user' => paypal_get_current_user()['email'] ?? 'guest'
    ]);
    paypal_json_response(false, null, 'Internal server error', 500);
}

function handleGetProduct() {
    $sku = $_GET['sku'] ?? '';
    
    if (empty($sku)) {
        // Return all products
        $products = paypal_load_products();
        
        // Add image information for each product
        foreach ($products as &$product) {
            $product['images'] = paypal_get_product_images($product['sku']);
        }
        
        paypal_json_response(true, $products, 'Products retrieved successfully');
        return;
    }
    
    $product = paypal_get_product_by_sku($sku);
    
    if (!$product) {
        paypal_json_response(false, null, 'Product not found', 404);
        return;
    }
    
    // Add image gallery information
    $product['images'] = paypal_get_product_images($sku);
    
    paypal_json_response(true, $product, 'Product retrieved successfully');
}

function handleCreateProduct() {
    // Check permissions
    if (!paypal_has_permission('create')) {
        paypal_json_response(false, null, 'Insufficient permissions', 403);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        paypal_json_response(false, null, 'Invalid JSON input', 400);
        return;
    }
    
    // Validate required fields
    $required = ['name', 'price', 'desc'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            paypal_json_response(false, null, "Field '$field' is required", 400);
            return;
        }
    }
    
    $products = paypal_load_products();
    
    // Generate SKU if not provided
    $sku = $input['sku'] ?? paypal_generate_sku();
    
    // Check if SKU already exists
    if (paypal_get_product_by_sku($sku)) {
        paypal_json_response(false, null, 'Product with this SKU already exists', 409);
        return;
    }
    
    // Validate price
    if (!is_numeric($input['price']) || floatval($input['price']) < 0) {
        paypal_json_response(false, null, 'Invalid price format', 400);
        return;
    }
    
    // Create new product
    $product = [
        'sku' => $sku,
        'enabled' => filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'name' => paypal_sanitize($input['name']),
        'price' => number_format(floatval($input['price']), 2, '.', ''),
        'desc' => paypal_sanitize($input['desc']),
        'image' => paypal_sanitize($input['image'] ?? ''),
        'images' => $input['images'] ?? [],
        'category' => paypal_sanitize($input['category'] ?? ''),
        'variations' => $input['variations'] ?? ['groups' => []],
        'mtime' => time()
    ];
    
    $products[] = $product;
    
    if (paypal_save_products($products)) {
        paypal_log("Product created: {$product['sku']}", 'info', ['name' => $product['name']]);
        paypal_json_response(true, $product, 'Product created successfully');
    } else {
        paypal_json_response(false, null, 'Failed to save product', 500);
    }
}

function handleUpdateProduct() {
    // Check permissions
    if (!paypal_has_permission('update')) {
        paypal_json_response(false, null, 'Insufficient permissions', 403);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['sku'])) {
        paypal_json_response(false, null, 'SKU is required', 400);
        return;
    }
    
    $products = paypal_load_products();
    $productIndex = -1;
    
    foreach ($products as $index => $product) {
        if ($product['sku'] === $input['sku']) {
            $productIndex = $index;
            break;
        }
    }
    
    if ($productIndex === -1) {
        paypal_json_response(false, null, 'Product not found', 404);
        return;
    }
    
    // Update product fields
    $updatedProduct = $products[$productIndex];
    
    $updateableFields = ['enabled', 'name', 'price', 'desc', 'image', 'images', 'category', 'variations'];
    foreach ($updateableFields as $field) {
        if (array_key_exists($field, $input)) {
            if ($field === 'price') {
                if (!is_numeric($input[$field]) || floatval($input[$field]) < 0) {
                    paypal_json_response(false, null, 'Invalid price format', 400);
                    return;
                }
                $updatedProduct[$field] = number_format(floatval($input[$field]), 2, '.', '');
            } elseif ($field === 'enabled') {
                $updatedProduct[$field] = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($field, ['name', 'desc', 'image', 'category'])) {
                $updatedProduct[$field] = paypal_sanitize($input[$field]);
            } else {
                $updatedProduct[$field] = $input[$field];
            }
        }
    }
    
    $updatedProduct['mtime'] = time();
    $products[$productIndex] = $updatedProduct;
    
    if (paypal_save_products($products)) {
        paypal_log("Product updated: {$updatedProduct['sku']}", 'info', ['name' => $updatedProduct['name']]);
        paypal_json_response(true, $updatedProduct, 'Product updated successfully');
    } else {
        paypal_json_response(false, null, 'Failed to update product', 500);
    }
}

function handleDeleteProduct() {
    // Check permissions
    if (!paypal_has_permission('delete')) {
        paypal_json_response(false, null, 'Insufficient permissions', 403);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['sku'])) {
        paypal_json_response(false, null, 'SKU is required', 400);
        return;
    }
    
    $sku = $input['sku'];
    $product = paypal_get_product_by_sku($sku);
    
    if (!$product) {
        paypal_json_response(false, null, 'Product not found', 404);
        return;
    }
    
    if (paypal_delete_product($sku)) {
        paypal_json_response(true, null, 'Product deleted successfully');
    } else {
        paypal_json_response(false, null, 'Failed to delete product', 500);
    }
}
?>