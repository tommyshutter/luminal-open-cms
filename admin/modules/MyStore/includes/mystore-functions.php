<?php
/**
 * My Store System - Core Functions
 * Dark Theme Helper Functions
 *
 * File: /admin/modules/MyStore/includes/mystore-functions.php
 * Version: 3.0.0
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

/**
 * Utility Functions
 */
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($string) {
        return htmlspecialchars(trim(strip_tags($string)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Authentication Functions
 */
function paypal_is_logged_in() {
    if (!PAYPAL_AUTH_ENABLED) {
        return true; // Skip authentication for development
    }

    return isset($_SESSION['paypal_user_id']);
}

function paypal_get_current_user() {
    if (!PAYPAL_AUTH_ENABLED) {
        return PAYPAL_DEFAULT_USER;
    }

    if (!paypal_is_logged_in()) {
        return null;
    }

    return $_SESSION['paypal_user'] ?? null;
}

function paypal_login_user($user) {
    $_SESSION['paypal_user_id'] = $user['id'];
    $_SESSION['paypal_user'] = $user;

    paypal_log("User logged in: " . $user['email'], 'INFO');
}

function paypal_logout_user() {
    $user = paypal_get_current_user();
    if ($user) {
        paypal_log("User logged out: " . $user['email'], 'INFO');
    }

    unset($_SESSION['paypal_user_id']);
    unset($_SESSION['paypal_user']);
}

/**
 * CSRF Protection
 */
function paypal_generate_csrf_token() {
    return $_SESSION[PAYPAL_CSRF_TOKEN_NAME] ?? '';
}

function paypal_verify_csrf_token($token) {
    return hash_equals($_SESSION[PAYPAL_CSRF_TOKEN_NAME] ?? '', $token);
}

/**
 * Logging Functions
 */
function paypal_log($message, $level = 'INFO', $context = []) {
    if (!PAYPAL_LOG_ENABLED) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
    $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

    // Ensure log directory exists
    $logDir = dirname(PAYPAL_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents(PAYPAL_LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Admin activity logging helper
 */
if (!function_exists('paypal_log_admin_activity')) {
    function paypal_log_admin_activity($action, $message) {
        paypal_log("[$action] $message", 'INFO');
    }
}

/**
 * Product Management Functions (Filesystem-based JSON)
 */
function paypal_get_products() {
    $products = [];
    $productsDir = PAYPAL_DATA_PATH . '/products';

    if (!is_dir($productsDir)) {
        paypal_log("Products directory not found: $productsDir", 'WARNING');
        return $products;
    }

    $files = scandir($productsDir);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
            continue;
        }

        $jsonFile = $productsDir . '/' . $file;

        if (file_exists($jsonFile)) {
            $json = file_get_contents($jsonFile);
            $product = json_decode($json, true);

            if (is_array($product) && !empty($product['sku'])) {
                // Auto-detect main image if not set
                if (empty($product['image'])) {
                    $product['image'] = paypal_auto_detect_main_image($product['sku']);
                }

                $products[] = $product;
            }
        }
    }

    // Sort by SKU for consistent ordering
    usort($products, function($a, $b) {
        return strcasecmp($a['sku'], $b['sku']);
    });

    return $products;
}

function paypal_save_products($products) {
    $successCount = 0;
    $failCount = 0;

    foreach ($products as $product) {
        if (paypal_save_product_to_filesystem($product)) {
            $successCount++;
        } else {
            $failCount++;
        }
    }

    if ($failCount === 0) {
        paypal_log("All products saved successfully", 'INFO', ['count' => $successCount]);
        return true;
    } else {
        paypal_log("Some products failed to save", 'ERROR', ['success' => $successCount, 'failed' => $failCount]);
        return $failCount < $successCount; // Return true if more succeeded than failed
    }
}

function paypal_find_product($sku) {
    $jsonFile = PAYPAL_DATA_PATH . '/products/' . $sku . '.json';

    if (file_exists($jsonFile)) {
        $json = file_get_contents($jsonFile);
        $product = json_decode($json, true);

        if (is_array($product) && !empty($product['sku'])) {
            // Auto-detect main image if not set
            if (empty($product['image'])) {
                $product['image'] = paypal_auto_detect_main_image($sku);
            }
            return $product;
        }
    }

    return null;
}

function paypal_add_product($productData) {
    $products = paypal_get_products();

    // Check if SKU already exists
    if (paypal_find_product($productData['sku'])) {
        return false;
    }

    // Add timestamps
    $productData['mtime'] = time();
    $productData['created'] = time();

    $products[] = $productData;

    return paypal_save_products($products);
}

function paypal_update_product($sku, $productData) {
    $products = paypal_get_products();

    foreach ($products as $index => $product) {
        if ($product['sku'] === $sku) {
            // Preserve creation time if it exists
            if (isset($product['created'])) {
                $productData['created'] = $product['created'];
            }

            // Update modification time
            $productData['mtime'] = time();

            $products[$index] = array_merge($product, $productData);

            return paypal_save_products($products);
        }
    }

    return false;
}

function paypal_delete_product($sku) {
    $jsonFile = PAYPAL_DATA_PATH . '/products/' . $sku . '.json';

    if (!file_exists($jsonFile)) {
        paypal_log("Cannot delete product: JSON file not found for SKU '$sku'", 'ERROR');
        return false;
    }

    // Create backup before deletion
    $backupDir = PAYPAL_DATA_PATH . '/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir . '/deleted_' . $sku . '_' . date('Y-m-d_H-i-s') . '.json';
    @copy($jsonFile, $backupFile);

    // Delete the JSON file
    $result = @unlink($jsonFile);

    if ($result) {
        paypal_log("Product deleted: $sku", 'INFO');
        return true;
    } else {
        paypal_log("Failed to delete product: $sku", 'ERROR');
        return false;
    }
}

/**
 * Recursively delete a directory and its contents
 */
function paypal_delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            paypal_delete_directory_recursive($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}

/**
 * Save product to filesystem (individual JSON files)
 */
function paypal_save_product_to_filesystem($productData) {
    if (empty($productData['sku'])) {
        paypal_log('Cannot save product: SKU is empty', 'ERROR');
        return false;
    }

    $sku = sanitize_text_field($productData['sku']);

    // Product JSON path
    $jsonFile = PAYPAL_DATA_PATH . '/products/' . $sku . '.json';
    $jsonDir = dirname($jsonFile);

    // Ensure JSON directory exists
    if (!is_dir($jsonDir)) {
        if (!@mkdir($jsonDir, 0755, true)) {
            paypal_log("Failed to create products directory: $jsonDir", 'ERROR');
            return false;
        }
    }

    // Ensure media directory exists
    $mediaDir = $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL . '/' . $sku . '/images';
    if (!is_dir($mediaDir)) {
        if (!@mkdir($mediaDir, 0755, true)) {
            paypal_log("Failed to create media directory: $mediaDir", 'ERROR');
            // Continue anyway - media directory creation is not critical for JSON saving
        }
    }

    // Create backup if file exists
    if (file_exists($jsonFile)) {
        $backupDir = PAYPAL_DATA_PATH . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        $backupFile = $backupDir . '/' . $sku . '_' . date('Y-m-d_H-i-s') . '.json';
        @copy($jsonFile, $backupFile);
    }

    // Set timestamps
    $productData['mtime'] = time();
    if (!isset($productData['created'])) {
        $productData['created'] = time();
    }

    // Ensure required structure
    if (!isset($productData['variations'])) {
        $productData['variations'] = ['groups' => []];
    }

    // Ensure boolean fields are properly set
    $productData['enabled'] = isset($productData['enabled']) ? (bool)$productData['enabled'] : true;

    // Save product JSON
    $json = json_encode($productData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $result = file_put_contents($jsonFile, $json, LOCK_EX);

    if ($result !== false) {
        paypal_log("Product saved successfully: $sku to $jsonFile", 'INFO');
        return true;
    } else {
        paypal_log("Failed to save product: $sku to $jsonFile", 'ERROR');
        return false;
    }
}

/**
 * Save product (add new or update existing)
 * This is a unified function that handles both add and update
 */
function paypal_save_product($productData) {
    if (empty($productData['sku'])) {
        return false;
    }

    return paypal_save_product_to_filesystem($productData);
}

/**
 * Settings Management Functions
 */
function paypal_get_all_settings() {
    // Canonical settings file is the data-root settings.json — what the admin UI
    // saves to and what the live storefront (store-api.php / mystore-store.php)
    // reads. Fall back to the legacy settings/ subfolder copy for compatibility.
    $settingsFile = PAYPAL_DATA_PATH . '/settings.json';
    if (!file_exists($settingsFile)) {
        $legacy = PAYPAL_DATA_PATH . '/settings/settings.json';
        if (file_exists($legacy)) $settingsFile = $legacy;
    }

    if (!file_exists($settingsFile)) {
        return paypal_get_default_settings();
    }

    $json = file_get_contents($settingsFile);
    $settings = json_decode($json, true);

    if (!is_array($settings)) {
        return paypal_get_default_settings();
    }

    // Merge with defaults to ensure all keys exist
    return array_merge(paypal_get_default_settings(), $settings);
}

/**
 * Image Management Functions
 */
function paypal_get_product_images($sku) {
    $imagePath = PAYPAL_MEDIA_PATH . '/' . $sku;

    if (!is_dir($imagePath)) {
        return [];
    }

    $images = [];
    $files = scandir($imagePath);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, PAYPAL_ALLOWED_IMAGE_TYPES)) {
            $images[] = PAYPAL_MEDIA_URL . '/' . $sku . '/' . $file;
        }
    }

    return $images;
}

function paypal_upload_product_image($sku, $uploadedFile) {
    if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
        return false;
    }

    // Validate file size
    if ($uploadedFile['size'] > PAYPAL_MAX_FILE_SIZE) {
        return false;
    }

    // Validate file type
    $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, PAYPAL_ALLOWED_IMAGE_TYPES)) {
        return false;
    }

    // Create product directory
    $productDir = PAYPAL_MEDIA_PATH . '/' . $sku;
    if (!is_dir($productDir)) {
        @mkdir($productDir, 0755, true);
    }

    // Generate unique filename
    $filename = uniqid() . '.' . $extension;
    $targetPath = $productDir . '/' . $filename;

    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        $imageUrl = PAYPAL_MEDIA_URL . '/' . $sku . '/' . $filename;
        paypal_log("Image uploaded: {$imageUrl}", 'INFO');
        return $imageUrl;
    }

    return false;
}

/**
 * Get the main product image path
 */
function paypal_get_main_image_path($sku) {
    $baseUrl = PAYPAL_MEDIA_URL . '/' . $sku . '/images/';

    // Try different common image extensions
    $extensions = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($extensions as $ext) {
        $imagePath = $baseUrl . $sku . '.' . $ext;
        $localPath = PAYPAL_MEDIA_PATH . '/' . $sku . '/images/' . $sku . '.' . $ext;

        // Check if file exists locally
        if (file_exists($localPath)) {
            return $imagePath;
        }
    }

    // Return null if no image found
    return null;
}

/**
 * Get all gallery images for a product
 */
function paypal_get_gallery_images($sku) {
    $imagesDir = PAYPAL_MEDIA_PATH . '/' . $sku . '/images/';
    $baseUrl = PAYPAL_MEDIA_URL . '/' . $sku . '/images/';
    $images = [];

    if (!is_dir($imagesDir)) {
        return $images;
    }

    $files = scandir($imagesDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;

        $filePath = $imagesDir . $file;
        if (is_file($filePath)) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $images[] = [
                    'url' => $baseUrl . $file,
                    'filename' => $file,
                    'is_main' => (pathinfo($file, PATHINFO_FILENAME) === $sku)
                ];
            }
        }
    }

    // Sort so main image comes first
    usort($images, function($a, $b) {
        if ($a['is_main']) return -1;
        if ($b['is_main']) return 1;
        return strcmp($a['filename'], $b['filename']);
    });

    return $images;
}

/**
 * Utility Functions
 */
function paypal_sanitize_sku($sku) {
    // Remove invalid characters and convert to lowercase
    $sku = strtolower(trim($sku));
    $sku = preg_replace('/[^a-z0-9\-_]/', '', $sku);

    return $sku;
}

function paypal_format_price($price) {
    return PAYPAL_CURRENCY_SYMBOL . number_format((float)$price, 2);
}

function paypal_validate_product_data($data) {
    $errors = [];

    // Required fields
    if (empty($data['sku'])) {
        $errors[] = 'SKU is required';
    }

    if (empty($data['name'])) {
        $errors[] = 'Product name is required';
    }

    if (empty($data['price']) || !is_numeric($data['price'])) {
        $errors[] = 'Valid price is required';
    }

    // Validate SKU format
    if (!empty($data['sku'])) {
        $cleanSku = paypal_sanitize_sku($data['sku']);
        if ($cleanSku !== $data['sku']) {
            $errors[] = 'SKU contains invalid characters';
        }
    }

    // Validate variations JSON if provided
    if (!empty($data['variations'])) {
        if (is_string($data['variations'])) {
            $decoded = json_decode($data['variations'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Invalid variations JSON format';
            }
        }
    }

    return $errors;
}

/**
 * JSON Response Functions
 */
function paypal_json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function paypal_json_success($message = 'Success', $data = []) {
    paypal_json_response(array_merge([
        'success' => true,
        'message' => $message
    ], $data));
}

function paypal_json_error($message = 'Error', $statusCode = 400, $data = []) {
    paypal_json_response(array_merge([
        'success' => false,
        'message' => $message
    ], $data), $statusCode);
}

/**
 * Security Functions
 */
function paypal_escape_html($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function paypal_validate_request_method($expectedMethod) {
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        http_response_code(405);
        die('Method Not Allowed');
    }
}

/**
 * Import/Export Functions
 */
function paypal_export_products($format = 'json') {
    $products = paypal_get_products();

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.json"');
        echo json_encode($products, JSON_PRETTY_PRINT);
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // CSV Headers
        fputcsv($output, ['SKU', 'Name', 'Price', 'Category', 'Description', 'Enabled', 'Image']);

        // CSV Data
        foreach ($products as $product) {
            fputcsv($output, [
                $product['sku'] ?? '',
                $product['name'] ?? '',
                $product['price'] ?? '',
                $product['category'] ?? '',
                $product['desc'] ?? '',
                $product['enabled'] ? 'TRUE' : 'FALSE',
                $product['image'] ?? ''
            ]);
        }

        fclose($output);
    }

    exit;
}

function paypal_import_products_from_csv($csvData) {
    $lines = str_getcsv($csvData, "\n");
    $header = str_getcsv(array_shift($lines));

    $imported = [];
    $errors = [];

    foreach ($lines as $lineNumber => $line) {
        $data = str_getcsv($line);

        if (count($data) !== count($header)) {
            $errors[] = "Line " . ($lineNumber + 2) . ": Column count mismatch";
            continue;
        }

        $product = array_combine($header, $data);

        // Convert enabled field
        $product['enabled'] = in_array(strtoupper($product['Enabled'] ?? ''), ['TRUE', '1', 'YES']);

        // Map CSV columns to product fields
        $productData = [
            'sku' => paypal_sanitize_sku($product['SKU'] ?? ''),
            'name' => $product['Name'] ?? '',
            'price' => $product['Price'] ?? '0.00',
            'category' => $product['Category'] ?? '',
            'desc' => $product['Description'] ?? '',
            'enabled' => $product['enabled'],
            'image' => $product['Image'] ?? '',
            'variations' => ['groups' => []]
        ];

        // Validate product data
        $validationErrors = paypal_validate_product_data($productData);
        if (!empty($validationErrors)) {
            $errors[] = "Line " . ($lineNumber + 2) . ": " . implode(', ', $validationErrors);
            continue;
        }

        $imported[] = $productData;
    }

    return [
        'imported' => $imported,
        'errors' => $errors
    ];
}

/**
 * Migrate from old JSON format to filesystem-based format
 */
function paypal_migrate_to_filesystem($oldProductsData = null) {
    if ($oldProductsData === null) {
        // Try to load from old products.json file
        $oldFile = PAYPAL_DATA_PATH . '/products/products.json';
        if (file_exists($oldFile)) {
            $json = file_get_contents($oldFile);
            $oldProductsData = json_decode($json, true);
        } else {
            return ['success' => false, 'message' => 'No data to migrate'];
        }
    }

    if (!is_array($oldProductsData)) {
        return ['success' => false, 'message' => 'Invalid data format'];
    }

    $migrated = 0;
    $errors = [];

    foreach ($oldProductsData as $product) {
        if (empty($product['sku'])) {
            $errors[] = 'Product without SKU skipped';
            continue;
        }

        if (paypal_save_product_to_filesystem($product)) {
            $migrated++;
        } else {
            $errors[] = "Failed to migrate product: " . $product['sku'];
        }
    }

    $result = [
        'success' => true,
        'migrated' => $migrated,
        'errors' => $errors,
        'message' => "Successfully migrated $migrated products to filesystem"
    ];

    if (!empty($errors)) {
        $result['message'] .= ' with ' . count($errors) . ' errors';
    }

    return $result;
}

/**
 * CSV to Filesystem Converter
 */
function paypal_convert_csv_to_filesystem($csvFile) {
    $result = [
        'success' => false,
        'message' => '',
        'imported' => 0,
        'updated' => 0,
        'errors' => []
    ];

    if (!file_exists($csvFile) || !is_readable($csvFile)) {
        $result['message'] = 'CSV file not found or not readable';
        return $result;
    }

    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        $result['message'] = 'Could not open CSV file';
        return $result;
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        $result['message'] = 'Invalid CSV file - no headers found';
        fclose($handle);
        return $result;
    }

    // Create header mapping
    $header_map = [];
    foreach ($headers as $index => $header) {
        $header_map[strtolower(trim($header))] = $index;
    }

    $line_number = 1;
    $imported_count = 0;
    $updated_count = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $line_number++;

        try {
            $product = paypal_parse_csv_row($row, $header_map, $line_number);

            if ($product && !empty($product['sku'])) {
                $existingProduct = paypal_find_product($product['sku']);

                if ($existingProduct) {
                    // Update existing product
                    $product['mtime'] = time();
                    if (paypal_save_product_to_filesystem($product)) {
                        $updated_count++;
                    } else {
                        $result['errors'][] = "Line $line_number: Failed to update product {$product['sku']}";
                    }
                } else {
                    // Add new product
                    $product['mtime'] = time();
                    if (paypal_save_product_to_filesystem($product)) {
                        $imported_count++;
                    } else {
                        $result['errors'][] = "Line $line_number: Failed to save product {$product['sku']}";
                    }
                }
            } else {
                $result['errors'][] = "Line $line_number: Invalid product data or missing SKU";
            }
        } catch (Exception $e) {
            $result['errors'][] = "Line $line_number: " . $e->getMessage();
        }
    }

    fclose($handle);

    $result['success'] = true;
    $result['imported'] = $imported_count;
    $result['updated'] = $updated_count;

    if ($imported_count > 0 && $updated_count > 0) {
        $result['message'] = "CSV import complete: $imported_count added, $updated_count updated";
    } elseif ($imported_count > 0) {
        $result['message'] = "Successfully imported $imported_count products";
    } elseif ($updated_count > 0) {
        $result['message'] = "Successfully updated $updated_count products";
    } else {
        $result['message'] = "No products were imported or updated";
    }

    if (!empty($result['errors'])) {
        $result['message'] .= ' with ' . count($result['errors']) . ' errors';
    }

    paypal_log("CSV to filesystem conversion completed", 'INFO', [
        'imported' => $imported_count,
        'updated' => $updated_count,
        'errors' => count($result['errors'])
    ]);

    return $result;
}

/**
 * Auto-detect main product image
 */
function paypal_auto_detect_main_image($sku) {
    $imageDir = $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL . '/' . $sku . '/images';

    if (!is_dir($imageDir)) {
        return '';
    }

    // Look for common main image names first
    $commonNames = [$sku . '.jpg', $sku . '.png', $sku . '.webp', 'main.jpg', 'main.png', 'image.jpg', 'product.jpg'];

    foreach ($commonNames as $filename) {
        $imagePath = $imageDir . '/' . $filename;
        if (file_exists($imagePath)) {
            return PAYPAL_MEDIA_URL . '/' . $sku . '/images/' . $filename;
        }
    }

    // If no common names found, get first image file
    $files = array_diff(scandir($imageDir), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            return PAYPAL_MEDIA_URL . '/' . $sku . '/images/' . $file;
        }
    }

    return '';
}

/**
 * Duplicate product functionality
 */
function paypal_duplicate_product($originalSku) {
    // Load original product
    $original = paypal_find_product($originalSku);
    if (!$original) {
        paypal_log("Cannot duplicate product: Original SKU '$originalSku' not found", 'ERROR');
        return false;
    }

    // Generate new SKU
    $newSku = $originalSku . '-copy';
    $counter = 1;

    // Ensure unique SKU
    while (paypal_find_product($newSku)) {
        $newSku = $originalSku . '-copy-' . $counter;
        $counter++;
    }

    // Create duplicated product data
    $duplicate = $original;
    $duplicate['sku'] = $newSku;
    $duplicate['name'] = $original['name'] . ' (Copy)';
    $duplicate['created'] = time();
    $duplicate['mtime'] = time();

    // Clear image path so it gets auto-detected or can be set later
    $duplicate['image'] = '';

    // Save duplicated product
    $result = paypal_save_product_to_filesystem($duplicate);

    if ($result) {
        paypal_log("Product duplicated successfully: $originalSku -> $newSku", 'INFO');

        // Optionally copy media files
        paypal_copy_product_media($originalSku, $newSku);

        return $newSku;
    } else {
        paypal_log("Failed to duplicate product: $originalSku", 'ERROR');
        return false;
    }
}

/**
 * Copy media files from one product to another
 */
function paypal_copy_product_media($fromSku, $toSku) {
    $fromDir = $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL . '/' . $fromSku;
    $toDir = $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL . '/' . $toSku;

    if (!is_dir($fromDir)) {
        return false; // No media to copy
    }

    // Create destination directory
    if (!is_dir($toDir)) {
        @mkdir($toDir, 0755, true);
    }

    // Copy recursively
    return paypal_copy_directory_recursive($fromDir, $toDir);
}

/**
 * Recursively copy directory
 */
function paypal_copy_directory_recursive($src, $dst) {
    if (!is_dir($src)) {
        return false;
    }

    if (!is_dir($dst)) {
        @mkdir($dst, 0755, true);
    }

    $dir = opendir($src);
    if (!$dir) {
        return false;
    }

    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcFile = $src . '/' . $file;
        $dstFile = $dst . '/' . $file;

        if (is_dir($srcFile)) {
            paypal_copy_directory_recursive($srcFile, $dstFile);
        } else {
            @copy($srcFile, $dstFile);
        }
    }

    closedir($dir);
    return true;
}

/**
 * Handle file uploads for products
 */
function paypal_handle_product_image_upload($sku, $uploadedFile) {
    if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        paypal_log("Invalid file type for product image: $mimeType", 'ERROR');
        return false;
    }

    // Create media directory
    $mediaDir = $_SERVER['DOCUMENT_ROOT'] . PAYPAL_MEDIA_URL . '/' . $sku . '/images';
    if (!is_dir($mediaDir)) {
        if (!@mkdir($mediaDir, 0755, true)) {
            paypal_log("Failed to create media directory: $mediaDir", 'ERROR');
            return false;
        }
    }

    // Generate filename
    $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
    $filename = $sku . '.' . $extension;
    $targetPath = $mediaDir . '/' . $filename;

    // Move uploaded file
    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        $webPath = PAYPAL_MEDIA_URL . '/' . $sku . '/images/' . $filename;
        paypal_log("Product image uploaded: $webPath", 'INFO');
        return $webPath;
    } else {
        paypal_log("Failed to move uploaded file for product: $sku", 'ERROR');
        return false;
    }
}

/**
 * Save all settings
 */
function paypal_save_all_settings($settings) {
    // Write to the canonical data-root settings.json (single source of truth,
    // shared with the admin UI and the live storefront).
    $settingsFile = PAYPAL_DATA_PATH . '/settings.json';
    $settingsDir = dirname($settingsFile);

    // Ensure settings directory exists
    if (!is_dir($settingsDir)) {
        if (!@mkdir($settingsDir, 0755, true)) {
            paypal_log("Failed to create settings directory: $settingsDir", 'ERROR');
            return false;
        }
    }

    // Sanitize settings
    $cleanSettings = [];
    foreach ($settings as $key => $value) {
        if (is_string($value)) {
            $cleanSettings[$key] = sanitize_text_field($value);
        } elseif (is_bool($value)) {
            $cleanSettings[$key] = $value;
        } elseif (is_numeric($value)) {
            $cleanSettings[$key] = $value;
        } else {
            $cleanSettings[$key] = $value; // Keep as-is for arrays/objects
        }
    }

    // Add timestamp
    $cleanSettings['last_updated'] = time();

    // Save settings
    $json = json_encode($cleanSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $result = file_put_contents($settingsFile, $json, LOCK_EX);

    if ($result !== false) {
        paypal_log("Settings saved successfully", 'INFO');
        return true;
    } else {
        paypal_log("Settings failed to save", 'ERROR');
        return false;
    }
}

/**
 * Enhanced cart functions for checkout process
 */
function paypal_get_cart_items() {
    if (!isset($_SESSION)) session_start();
    return $_SESSION['mystore_cart'] ?? $_SESSION['paypal_cart'] ?? [];
}

function paypal_get_cart_total_fixed() {
    $cart = paypal_get_cart_items();
    $total = 0;

    foreach ($cart as $item) {
        $total += floatval($item['price']) * intval($item['quantity']);
    }

    return $total;
}

function paypal_get_cart_count_fixed() {
    $cart = paypal_get_cart_items();
    $count = 0;

    foreach ($cart as $item) {
        $count += intval($item['quantity']);
    }

    return $count;
}

/**
 * Checkout process functions
 */
function paypal_create_order($cartItems, $customerInfo = []) {
    $orderData = [
        'order_id' => 'ORD-' . date('Ymd') . '-' . substr(uniqid(), -6),
        'created' => time(),
        'status' => 'pending',
        'customer' => $customerInfo,
        'items' => $cartItems,
        'subtotal' => paypal_get_cart_total_fixed(),
        'tax' => 0, // Calculate based on settings
        'shipping' => 0, // Calculate based on settings
        'total' => paypal_get_cart_total_fixed()
    ];

    // Save order
    $ordersDir = PAYPAL_DATA_PATH . '/orders';
    if (!is_dir($ordersDir)) {
        @mkdir($ordersDir, 0755, true);
    }

    $orderFile = $ordersDir . '/' . $orderData['order_id'] . '.json';
    $json = json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($orderFile, $json, LOCK_EX)) {
        paypal_log("Order created: " . $orderData['order_id'], 'INFO');
        return $orderData;
    } else {
        paypal_log("Failed to create order", 'ERROR');
        return false;
    }
}

function paypal_update_order_status($orderId, $status, $paymentData = []) {
    $orderFile = PAYPAL_DATA_PATH . '/orders/' . $orderId . '.json';

    if (!file_exists($orderFile)) {
        return false;
    }

    $json = file_get_contents($orderFile);
    $order = json_decode($json, true);

    if (!$order) {
        return false;
    }

    $order['status'] = $status;
    $order['updated'] = time();

    if (!empty($paymentData)) {
        $order['payment_data'] = $paymentData;
    }

    $json = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($orderFile, $json, LOCK_EX) !== false;
}

paypal_log("My Store functions loaded successfully", 'INFO');
?>
