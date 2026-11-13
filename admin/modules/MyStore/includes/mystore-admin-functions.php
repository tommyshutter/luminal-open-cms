<?php
/**
 * My Store — Admin Functions
 *
 * Product CRUD, image upload, settings, and AJAX POST handler.
 * Extracted from the standalone index.php for admin shell integration.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 4));
}

// Data directory paths — with backward-compat fallback for legacy paypal dirs
$MYSTORE_DATA_BASE = '/admin/data/mystore/';
$MYSTORE_MEDIA_BASE = '/media/store/';

// Backward compatibility: if mystore dir doesn't exist but paypal does, use old path
if (!is_dir(SITE_ROOT . $MYSTORE_DATA_BASE) && is_dir(SITE_ROOT . '/admin/data/paypal/')) {
    $MYSTORE_DATA_BASE = '/admin/data/paypal/';
}
if (!is_dir(SITE_ROOT . $MYSTORE_MEDIA_BASE) && is_dir(SITE_ROOT . '/media/paypal/')) {
    $MYSTORE_MEDIA_BASE = '/media/paypal/';
}

$PRODUCTS_DIR = $MYSTORE_DATA_BASE . 'products/';
$MEDIA_DIR = $MYSTORE_MEDIA_BASE;

// Auto-fix permissions on data dirs so web server can write
foreach ([SITE_ROOT . $MYSTORE_DATA_BASE, SITE_ROOT . $MYSTORE_DATA_BASE . 'products/'] as $_d) {
    if (is_dir($_d) && !is_writable($_d)) @chmod($_d, 0777);
}
$_sf = SITE_ROOT . $MYSTORE_DATA_BASE . 'settings.json';
if (is_file($_sf) && !is_writable($_sf)) @chmod($_sf, 0666);

/**
 * Normalize order data to consistent format for display.
 * Handles legacy WooCommerce imports, PayPal IPN, manual entry, and new checkout orders.
 */
function mystore_normalize_order(array $o): array {
    // Ensure customer.name exists
    $c = $o['customer'] ?? [];
    if (empty($c['name'])) {
        $first = $c['first_name'] ?? '';
        $last = $c['last_name'] ?? '';
        $c['name'] = trim("$first $last") ?: ($c['email'] ?? 'Unknown');
    }
    // Ensure customer.email exists
    if (empty($c['email'])) $c['email'] = '';
    // Ensure customer.phone exists
    if (empty($c['phone'])) $c['phone'] = '';
    // Ensure customer.address is an object
    if (empty($c['address']) && !empty($o['shipping'])) {
        $c['address'] = $o['shipping'];
    }
    if (is_string($c['address'] ?? null)) {
        $c['address'] = ['street' => $c['address']];
    }
    if (!is_array($c['address'] ?? null)) {
        $c['address'] = [];
    }
    $o['customer'] = $c;

    // Ensure .total exists at root level
    if (!isset($o['total']) || $o['total'] === null) {
        if (isset($o['totals']['total'])) {
            $o['total'] = floatval($o['totals']['total']);
        } else {
            // Calculate from items
            $sum = 0;
            foreach ($o['items'] ?? [] as $item) {
                $sum += floatval($item['price'] ?? $item['total'] ?? 0) * intval($item['quantity'] ?? 1);
            }
            $o['total'] = $sum;
        }
    }
    $o['total'] = round(floatval($o['total']), 2);

    // Ensure .totals exists
    if (empty($o['totals'])) {
        $o['totals'] = [
            'subtotal' => $o['subtotal'] ?? $o['total'],
            'shipping' => $o['shipping'] ?? 0,
            'tax' => $o['tax'] ?? 0,
            'total' => $o['total'],
        ];
    }

    // Ensure created_at exists
    if (empty($o['created_at'])) {
        $o['created_at'] = $o['created'] ?? $o['date'] ?? date('c');
    }

    // Ensure updated_at exists
    if (empty($o['updated_at'])) {
        $o['updated_at'] = $o['updated'] ?? $o['created_at'];
    }

    // Ensure status exists
    if (empty($o['status'])) $o['status'] = 'processing';

    // Ensure payment_method exists
    if (empty($o['payment_method'])) $o['payment_method'] = 'unknown';

    // Ensure order_id exists
    if (empty($o['order_id'])) {
        $o['order_id'] = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(json_encode($o)), 0, 8));
    }

    // Normalize items
    foreach ($o['items'] ?? [] as &$item) {
        if (!isset($item['total'])) {
            $item['total'] = round(floatval($item['price'] ?? 0) * intval($item['quantity'] ?? 1), 2);
        }
        if (empty($item['name'])) $item['name'] = 'Unknown Product';
        if (!isset($item['quantity'])) $item['quantity'] = 1;
        if (!isset($item['price'])) $item['price'] = $item['total'];
    }
    unset($item);

    if (!isset($o['items'])) $o['items'] = [];

    // Ensure notes exists
    if (!isset($o['notes'])) $o['notes'] = '';

    return $o;
}

/**
 * Load all orders, normalized
 */
function mystore_load_all_orders(): array {
    global $MYSTORE_DATA_BASE;
    $file = SITE_ROOT . $MYSTORE_DATA_BASE . 'orders.json';
    // Auto-migrate: if not in mystore path but exists in legacy paypal path, move it over
    if (!is_file($file)) {
        $legacy = SITE_ROOT . '/admin/data/paypal/orders.json';
        if (is_file($legacy)) {
            $dir = dirname($file);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            if (is_dir($dir) && @copy($legacy, $file)) {
                @chmod($file, 0666);
                @rename($legacy, $legacy . '.migrated-' . date('Ymd'));
            } else {
                $file = $legacy; // fallback if copy fails
            }
        }
    }
    if (!is_file($file)) return [];
    $orders = json_decode(file_get_contents($file), true);
    if (!is_array($orders)) return [];
    return array_map('mystore_normalize_order', $orders);
}

/**
 * Load products from actual JSON files
 */
function loadProductsFromFiles(): array {
    global $PRODUCTS_DIR;
    $products = [];

    $fullPath = SITE_ROOT . $PRODUCTS_DIR;
    if (!is_dir($fullPath)) {
        return $products;
    }

    $files = glob($fullPath . '*.json');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $product = json_decode($content, true);
            if ($product && isset($product['sku'])) {
                $product['enabled'] = $product['enabled'] ?? true;
                $product['variations'] = $product['variations'] ?? ['groups' => []];
                $product['created'] = $product['created'] ?? filemtime($file);
                $product['mtime'] = $product['mtime'] ?? filemtime($file);
                $products[] = $product;
            }
        }
    }

    usort($products, function($a, $b) {
        return ($b['created'] ?? 0) - ($a['created'] ?? 0);
    });

    return $products;
}

/**
 * Save product to JSON file
 */
function saveProductToFile(array $product): bool {
    global $PRODUCTS_DIR;

    $fullPath = SITE_ROOT . $PRODUCTS_DIR;
    if (!is_dir($fullPath)) {
        if (!mkdir($fullPath, 0777, true)) {
            error_log("Failed to create directory: $fullPath");
            return false;
        }
    }
    if (!is_writable($fullPath)) @chmod($fullPath, 0777);

    $filename = $fullPath . $product['sku'] . '.json';
    if (is_file($filename) && !is_writable($filename)) @chmod($filename, 0666);
    $product['mtime'] = time();

    $json = json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $result = file_put_contents($filename, $json);

    if ($result !== false) {
        @chmod($filename, 0666);
    } else {
        error_log("Failed to write file: $filename");
    }

    return $result !== false;
}

/**
 * Validate SKU — must be a flat filename-safe token. Rejects path traversal.
 */
function sku_is_safe(string $sku): bool {
    if ($sku === '' || strlen($sku) > 128) return false;
    if (strpos($sku, '..') !== false) return false;
    return (bool)preg_match('/^[A-Za-z0-9_.-]+$/', $sku);
}

/**
 * CSRF gate for every state-changing MyStore POST. Accepts the token from
 * EITHER an `X-CSRF-Token` HTTP header OR a `csrf_token` form field — JS is
 * inconsistent about how it sends it and we support both. Emits a 403 JSON
 * response and exits on failure so callers can't accidentally proceed.
 *
 * Session token is minted once per session in MyStoreAdmin.php shell.
 */
function mystore_require_csrf(): void {
    $expected = (string)($_SESSION['ms_csrf'] ?? '');
    $provided = (string)(
        $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? $_POST['_csrf']
            ?? ''
    );
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'CSRF token missing or invalid. Reload the admin page and retry.',
        ]);
        exit;
    }
}

/**
 * Delete product file. Path-traversal safe.
 */
function deleteProductFile(string $sku): bool {
    global $PRODUCTS_DIR;
    if (!sku_is_safe($sku)) return false;
    $filename = SITE_ROOT . $PRODUCTS_DIR . $sku . '.json';
    return file_exists($filename) && unlink($filename);
}

/**
 * Get image path for product
 */
function getProductImagePath(string $sku): ?string {
    global $MEDIA_DIR;

    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $basePath = $MEDIA_DIR . $sku . '/images/' . $sku;

    foreach ($extensions as $ext) {
        $imagePath = $basePath . '.' . $ext;
        $fullPath = SITE_ROOT . $imagePath;
        if (file_exists($fullPath)) {
            return $imagePath;
        }
    }

    return null;
}

/**
 * Handle file uploads for product images
 */
function handleImageUpload(string $sku, array $uploadedFile): array {
    global $MEDIA_DIR;

    if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
        return ['success' => false, 'message' => 'No valid file uploaded'];
    }

    if ($uploadedFile['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 10MB)'];
    }

    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, WebP, or GIF images only.'];
    }

    $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        $extension = 'jpg';
    }

    $imageDir = SITE_ROOT . $MEDIA_DIR . $sku . '/images/';
    if (!is_dir($imageDir)) {
        if (!mkdir($imageDir, 0755, true)) {
            return ['success' => false, 'message' => 'Failed to create image directory'];
        }
    }

    $filename = $sku . '.' . $extension;
    $fullPath = $imageDir . $filename;
    $webPath = $MEDIA_DIR . $sku . '/images/' . $filename;

    if (move_uploaded_file($uploadedFile['tmp_name'], $fullPath)) {
        return [
            'success' => true,
            'message' => 'Image uploaded successfully',
            'imagePath' => $webPath,
            'filename' => $filename
        ];
    } else {
        return ['success' => false, 'message' => 'Failed to save uploaded file'];
    }
}

// ============================================================================
// Handle AJAX POST requests (image upload + product CRUD)
// ============================================================================

// Handle image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');
    mystore_require_csrf();

    $sku = trim($_POST['sku'] ?? '');
    if (empty($sku)) {
        echo json_encode(['success' => false, 'message' => 'SKU is required for image upload']);
        exit;
    }
    if (!sku_is_safe($sku)) {
        echo json_encode(['success' => false, 'message' => 'Invalid SKU']);
        exit;
    }

    $result = handleImageUpload($sku, $_FILES['image']);
    echo json_encode($result);
    exit;
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    mystore_require_csrf();

    try {
        $products = loadProductsFromFiles();

        switch ($_POST['action']) {
            case 'add_product':
            case 'edit_product':
                $productData = [
                    'sku' => trim($_POST['sku'] ?? ''),
                    'name' => trim($_POST['name'] ?? ''),
                    'price' => $_POST['price'] ?? '0',
                    'category' => trim($_POST['category'] ?? ''),
                    'desc' => trim($_POST['description'] ?? ''),
                    'image' => trim($_POST['image'] ?? ''),
                    'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === 'true',
                    'variations' => json_decode($_POST['variations'] ?? '{"groups":[]}', true) ?: ['groups' => []],
                    'mtime' => time()
                ];

                if (empty($productData['image'])) {
                    $autoImage = getProductImagePath($productData['sku']);
                    if ($autoImage) {
                        $productData['image'] = $autoImage;
                    }
                }

                if ($_POST['action'] === 'add_product') {
                    $productData['created'] = time();
                    $existingFile = SITE_ROOT . $PRODUCTS_DIR . $productData['sku'] . '.json';
                    if (file_exists($existingFile)) {
                        echo json_encode(['success' => false, 'message' => 'SKU already exists']);
                        exit;
                    }
                }

                if (empty($productData['sku']) || empty($productData['name']) || empty($productData['price'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields (SKU, name, or price)']);
                    break;
                }

                if (saveProductToFile($productData)) {
                    $message = $_POST['action'] === 'add_product' ? 'Product added successfully' : 'Product updated successfully';
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    $dirPath = SITE_ROOT . $PRODUCTS_DIR;
                    $filePath = $dirPath . $productData['sku'] . '.json';
                    $error = 'Failed to save product';
                    if (!is_dir($dirPath)) {
                        $error .= ' - Directory does not exist: ' . $dirPath;
                    } elseif (!is_writable($dirPath)) {
                        $error .= ' - Directory not writable: ' . $dirPath;
                    } elseif (file_exists($filePath) && !is_writable($filePath)) {
                        $error .= ' - File not writable: ' . $filePath;
                    }
                    echo json_encode(['success' => false, 'message' => $error]);
                }
                break;

            case 'delete_product':
                $sku = $_POST['sku'] ?? '';
                if (deleteProductFile($sku)) {
                    echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found or could not be deleted']);
                }
                break;

            case 'duplicate_product':
                $sku = $_POST['sku'] ?? '';
                $originalFile = SITE_ROOT . $PRODUCTS_DIR . $sku . '.json';

                if (file_exists($originalFile)) {
                    $original = json_decode(file_get_contents($originalFile), true);
                    $newSku = $sku . '-copy';
                    $counter = 1;
                    while (file_exists(SITE_ROOT . $PRODUCTS_DIR . $newSku . '.json')) {
                        $newSku = $sku . '-copy-' . $counter;
                        $counter++;
                    }
                    $duplicate = $original;
                    $duplicate['sku'] = $newSku;
                    $duplicate['name'] = $original['name'] . ' (Copy)';
                    $duplicate['created'] = time();
                    $duplicate['mtime'] = time();

                    if (saveProductToFile($duplicate)) {
                        echo json_encode(['success' => true, 'message' => "Product duplicated as $newSku"]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to duplicate product']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Original product not found']);
                }
                break;

            case 'toggle_product':
                $sku = $_POST['sku'] ?? '';
                $filename = SITE_ROOT . $PRODUCTS_DIR . $sku . '.json';

                if (file_exists($filename)) {
                    $product = json_decode(file_get_contents($filename), true);
                    $product['enabled'] = !($product['enabled'] ?? true);
                    $product['mtime'] = time();

                    if (saveProductToFile($product)) {
                        $status = $product['enabled'] ? 'enabled' : 'disabled';
                        echo json_encode(['success' => true, 'message' => "Product $status"]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update product']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                }
                break;

            case 'bulk_enable':
            case 'bulk_disable':
                $enableState = $_POST['action'] === 'bulk_enable';
                $count = 0;
                foreach ($products as $product) {
                    $product['enabled'] = $enableState;
                    if (saveProductToFile($product)) {
                        $count++;
                    }
                }
                $action = $enableState ? 'enabled' : 'disabled';
                echo json_encode(['success' => true, 'message' => "$count products $action"]);
                break;

            case 'bulk_delete_selected':
                $raw = $_POST['skus'] ?? '';
                $skus = is_array($raw) ? $raw : json_decode((string)$raw, true);
                if (!is_array($skus) || empty($skus)) {
                    echo json_encode(['success' => false, 'message' => 'No products selected']);
                    break;
                }

                // Pre-validate every SKU: reject the whole batch on any unsafe
                // token rather than silently skipping. Failing fast surfaces
                // tampering instead of letting it go unnoticed.
                $rejected = [];
                foreach ($skus as $s) {
                    $s = trim((string)$s);
                    if ($s === '') continue;
                    if (!sku_is_safe($s)) $rejected[] = $s;
                }
                if (!empty($rejected)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Rejected ' . count($rejected) . ' unsafe SKU(s); no products deleted',
                        'rejected' => $rejected,
                    ]);
                    break;
                }

                $deleted = 0;
                $failed  = [];
                foreach ($skus as $sku) {
                    $sku = trim((string)$sku);
                    if ($sku === '') continue;
                    if (deleteProductFile($sku)) {
                        $deleted++;
                    } else {
                        $failed[] = $sku;
                    }
                }
                // Partial failures are NOT success. Callers checking a boolean
                // success flag would silently miss the failed subset otherwise.
                if ($deleted > 0 && empty($failed)) {
                    echo json_encode([
                        'success' => true,
                        'message' => "$deleted product" . ($deleted === 1 ? '' : 's') . ' deleted',
                        'deleted' => $deleted,
                    ]);
                } elseif ($deleted > 0) {
                    echo json_encode([
                        'success' => false,
                        'partial' => true,
                        'message' => "$deleted deleted, " . count($failed) . ' failed',
                        'deleted' => $deleted,
                        'failed'  => $failed,
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'No products were deleted',
                        'failed'  => $failed,
                    ]);
                }
                break;

            case 'save_settings':
                $settings = json_decode($_POST['settings'] ?? '{}', true);
                $settingsFile = SITE_ROOT . $MYSTORE_DATA_BASE . 'settings.json';
                $settingsDir = dirname($settingsFile);
                if (!is_dir($settingsDir)) {
                    mkdir($settingsDir, 0777, true);
                }
                if (!is_writable($settingsDir)) @chmod($settingsDir, 0777);
                if (is_file($settingsFile) && !is_writable($settingsFile)) @chmod($settingsFile, 0666);
                if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
                    @chmod($settingsFile, 0666);
                    echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save settings — check file permissions']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// Load data for page rendering
// ============================================================================

$products = loadProductsFromFiles();

// Load settings — try mystore path first, fall back to legacy paypal path
$settings = [];
$settingsFile = SITE_ROOT . $MYSTORE_DATA_BASE . 'settings.json';
if (!file_exists($settingsFile)) {
    // Backward compat: check legacy path
    $legacySettingsFile = SITE_ROOT . '/admin/data/paypal/settings.json';
    if (file_exists($legacySettingsFile)) {
        $settingsFile = $legacySettingsFile;
    }
}
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}

$defaultSettings = [
    'storeName' => 'My Store',
    'storeDescription' => 'Premium products and accessories',
    'currency' => 'USD',
    'taxRate' => 0,
    'shippingCost' => 5.99,
    'freeShippingThreshold' => 50,
    'enableInventoryTracking' => false,
    'enableReviews' => true,
    'enableWishlist' => true,
    'emailNotifications' => true,
    'orderPrefix' => 'ORD',
    'defaultCategory' => 'General',
    'imageQuality' => 80,
    'maxFileSize' => 10,
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
    'processingFees' => 2.9,
    'refundPolicy' => 'Returns accepted within 30 days of purchase',
    'returnPolicyDays' => 30,
    'disputeHandling' => 'auto',
    'fraudProtection' => true,
    'thankYouMessage' => 'Thank you for your purchase! Your order has been received and is being processed.',
    'orderPlacedMessage' => 'Your order #{{ORDER_ID}} has been placed successfully. You will receive a confirmation email shortly.',
    'paymentSuccessMessage' => 'Payment successful! Thank you for your business.',
    'paymentFailedMessage' => 'Payment failed. Please check your payment details and try again.',
    'checkoutWelcomeMessage' => 'Welcome to our secure checkout. Please review your order below.',
    'cartEmptyMessage' => 'Your cart is empty. Browse our products to get started!',
    'outOfStockMessage' => 'This item is currently out of stock. Please check back later.'
];

$settings = array_merge($defaultSettings, $settings);

$view = $_GET['view'] ?? 'products';
