<?php
/**
 * MyStore Shopping Cart System - Embeddable Storefront
 * PROPER VERSION - Reads from Actual Data Sources
 *
 * File: /panels/store/storefront.php
 * Version: 2025.10.05.1800 r3 FIXED
 * Build Date: Sunday, October 5, 2025 - 6:00 PM EST
 * Purpose: Embeddable storefront with side drawer functionality
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Data directory paths - EXACT match to admin system
$PRODUCTS_DIR = '/admin/data/mystore/products/';
$MEDIA_DIR = '/media/store/';
$SETTINGS_DIR = '/admin/data/mystore/';

/**
 * Load products from actual JSON files (matching admin exactly)
 * Also loads cached Printful products if available.
 */
if (!function_exists('storefront_get_products')) {
function storefront_get_products() {
    global $PRODUCTS_DIR;
    $products = [];
    $docRoot = $_SERVER['DOCUMENT_ROOT'];

    // ── Custom products from MyStore ────────────────────────────────
    $productsPath = $docRoot . $PRODUCTS_DIR;
    if (is_dir($productsPath)) {
        $files = glob($productsPath . '*.json');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $product = json_decode($content, true);
                if ($product && isset($product['enabled']) && $product['enabled']) {
                    if (isset($product['sku'])) {
                        $imagePath = "/media/store/{$product['sku']}/images/{$product['sku']}.jpg";
                        if (file_exists($docRoot . $imagePath)) {
                            $product['image'] = $imagePath;
                        } else {
                            $imagePath = "/media/store/{$product['sku']}/images/{$product['sku']}.png";
                            if (file_exists($docRoot . $imagePath)) {
                                $product['image'] = $imagePath;
                            }
                        }
                    }
                    $product['source'] = 'custom';
                    $product['fulfillment'] = 'self';
                    $products[] = $product;
                }
            }
        }
    }

    // ── Printful products (cached by PrintfulManager sync) ──────────
    $printfulFile = $docRoot . '/admin/data/printful/products.json';
    if (is_file($printfulFile)) {
        $pf = json_decode(file_get_contents($printfulFile), true);
        if (is_array($pf)) {
            foreach ($pf as $pfProd) {
                if (!isset($pfProd['id'])) continue;
                $products[] = [
                    'sku'         => 'PF-' . $pfProd['id'],
                    'name'        => $pfProd['name'] ?? 'Printful Product',
                    'desc'        => $pfProd['description'] ?? '',
                    'price'       => floatval($pfProd['retail_price'] ?? $pfProd['price'] ?? 0),
                    'image'       => $pfProd['thumbnail_url'] ?? $pfProd['image'] ?? null,
                    'category'    => 'Print on Demand',
                    'enabled'     => true,
                    'source'      => 'printful',
                    'fulfillment' => 'printful',
                    'printful_id' => $pfProd['id'],
                    'variants'    => intval($pfProd['variants'] ?? 0),
                    'created'     => 0,
                ];
            }
        }
    }

    // Sort by creation time (newest first; Printful products sort to end)
    usort($products, function($a, $b) {
        return ($b['created'] ?? 0) - ($a['created'] ?? 0);
    });

    return $products;
}
} // end function_exists storefront_get_products

/**
 * Load settings from JSON file (matching admin exactly)
 */
if (!function_exists('storefront_get_settings')) {
function storefront_get_settings() {
    global $SETTINGS_DIR;

    $settingsFile = $_SERVER['DOCUMENT_ROOT'] . $SETTINGS_DIR . 'settings.json';

    // Default settings (matching admin exactly)
    $defaultSettings = [
                'storeName' => (function() {            $ss = @json_decode(@file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/data/site-settings.json'), true);            return ($ss['site_name'] ?? 'Our') . ' Store';        })(),
        'storeDescription' => 'Premium apparel and accessories',
        'currency' => 'USD',
        'taxRate' => 0,
        'shippingCost' => 5.99,
        'freeShippingThreshold' => 50,
        'cartEmptyMessage' => 'Your cart is empty. Browse our products to get started!',
        'showStoreTitle' => true,
        'showStoreDescription' => true,
        'showStatsBar' => true,
        'storeTitleFont' => '',
        'storeTitleSize' => '2rem',
        'storeBodyFont' => '',
        'storeBgColor' => '',
        'storeBgImage' => '',
        'productCardBg' => '',
        'productCardTextColor' => ''
    ];

    if (file_exists($settingsFile)) {
        $content = file_get_contents($settingsFile);
        if ($content !== false) {
            $settings = json_decode($content, true);
            if ($settings) {
                return array_merge($defaultSettings, $settings);
            }
        }
    }

    return $defaultSettings;
}
} // end function_exists storefront_get_settings

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'add_to_cart':
            $sku = $_POST['sku'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 1);

            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            if (isset($_SESSION['cart'][$sku])) {
                $_SESSION['cart'][$sku] += $quantity;
            } else {
                $_SESSION['cart'][$sku] = $quantity;
            }

            $cartCount = array_sum($_SESSION['cart']);
            echo json_encode(['success' => true, 'cart_count' => $cartCount]);
            exit;

        case 'get_cart':
            $cart = [];
            $total = 0;

            if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
                $products = storefront_get_products();
                $productMap = [];
                foreach ($products as $product) {
                    $productMap[$product['sku']] = $product;
                }

                foreach ($_SESSION['cart'] as $sku => $quantity) {
                    if (isset($productMap[$sku])) {
                        $product = $productMap[$sku];
                        $itemTotal = floatval($product['price']) * $quantity;
                        $total += $itemTotal;

                        $cart[] = [
                            'sku' => $sku,
                            'name' => $product['name'],
                            'price' => $product['price'],
                            'quantity' => $quantity,
                            'image' => $product['image'] ?? null
                        ];
                    }
                }
            }

            echo json_encode(['success' => true, 'cart' => $cart, 'total' => $total]);
            exit;

        case 'update_cart_quantity':
            $sku = $_POST['sku'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 0);

            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            if ($quantity <= 0) {
                unset($_SESSION['cart'][$sku]);
            } else {
                $_SESSION['cart'][$sku] = $quantity;
            }

            $cartCount = array_sum($_SESSION['cart']);
            echo json_encode(['success' => true, 'cart_count' => $cartCount]);
            exit;

        case 'remove_from_cart':
            $sku = $_POST['sku'] ?? '';

            if (isset($_SESSION['cart'][$sku])) {
                unset($_SESSION['cart'][$sku]);
            }

            $cartCount = array_sum($_SESSION['cart']);
            echo json_encode(['success' => true, 'cart_count' => $cartCount]);
            exit;

        case 'clear_cart':
            $_SESSION['cart'] = [];
            echo json_encode(['success' => true]);
            exit;

        case 'process_checkout':
            // Route through checkout-gateway for payment provider integration
            $gatewayFile = __DIR__ . '/checkout-gateway.php';
            $gatewayId = $_POST['gateway_id'] ?? '';
            $customerData = $_POST['customer_data'] ?? '{}';

            if (is_file($gatewayFile)) {
                // Use checkout-gateway to create order
                if (!defined('SITE_ROOT')) {
                    define('SITE_ROOT', $_SERVER['DOCUMENT_ROOT']);
                }
                require_once $gatewayFile;
                $result = cg_create_order($gatewayId, $customerData);
                if ($result['ok']) {
                    $_SESSION['cart'] = []; // Clear cart after order
                }
                echo json_encode(['success' => $result['ok'], 'orderId' => $result['data']['order_id'] ?? '', 'data' => $result['data'] ?? null, 'message' => $result['message'] ?? '']);
            } else {
                // Fallback: simple mock order
                $orderId = 'ORD-' . date('Ymd') . '-' . bin2hex(random_bytes(3));
                $_SESSION['cart'] = [];
                echo json_encode(['success' => true, 'orderId' => $orderId]);
            }
            exit;
    }
}

// Get data
$products = storefront_get_products();
$settings = storefront_get_settings();

// Get current cart count
$cartCount = 0;
if (isset($_SESSION['cart'])) {
    $cartCount = array_sum($_SESSION['cart']);
}

// Filter by search and category
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'All';

if (!empty($search)) {
    $products = array_filter($products, function($product) use ($search) {
        return stripos($product['name'], $search) !== false ||
               stripos($product['desc'] ?? '', $search) !== false;
    });
}

if ($category !== 'All') {
    $products = array_filter($products, function($product) use ($category) {
        return ($product['category'] ?? '') === $category;
    });
}

// Get unique categories
$categories = array_unique(array_map(function($product) {
    return $product['category'] ?? '';
}, storefront_get_products()));
$categories = array_filter($categories); // Remove empty categories
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['storeName']); ?> - Store</title>

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Drawer styles -->
    <link rel="stylesheet" href="storefront.css">

    <style>
        :root {
            --background: #000000;
            --foreground: #ffffff;
            --card: rgba(37, 37, 37, 0.8);
            --card-foreground: #ffffff;
            --popover: rgba(37, 37, 37, 0.95);
            --popover-foreground: #ffffff;
            --primary: #fbbf24;
            --primary-foreground: #000000;
            --secondary: #374151;
            --secondary-foreground: #ffffff;
            --muted: #374151;
            --muted-foreground: #9ca3af;
            --accent: #374151;
            --accent-foreground: #ffffff;
            --destructive: #ef4444;
            --destructive-foreground: #ffffff;
            --border: rgba(255, 255, 255, 0.1);
            --input: rgba(68, 68, 68, 0.6);
            --ring: #9ca3af;
            --radius: 0.625rem;

            --glass-bg: rgba(0, 0, 0, 0.2);
            --glass-border: rgba(255, 255, 255, 0.1);
            --glass-backdrop-blur: 20px;
            --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 12px 40px rgba(0, 0, 0, 0.4);
            --modal-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }

        /* Dynamic display settings */
        <?php if (!empty($settings['storeTitleFont'])): ?>
        .store-title { font-family: <?php echo htmlspecialchars($settings['storeTitleFont']); ?>; }
        <?php endif; ?>
        <?php if (!empty($settings['storeTitleSize'])): ?>
        .store-title { font-size: <?php echo htmlspecialchars($settings['storeTitleSize']); ?>; }
        <?php endif; ?>
        <?php if (!empty($settings['storeBodyFont'])): ?>
        body { font-family: <?php echo htmlspecialchars($settings['storeBodyFont']); ?>; }
        <?php endif; ?>
        <?php if (!empty($settings['storeBgColor'])): ?>
        body, .storefront-container { background: <?php echo htmlspecialchars($settings['storeBgColor']); ?>; }
        <?php endif; ?>
        <?php if (!empty($settings['storeBgImage'])): ?>
        .storefront-container { background-image: url('<?php echo htmlspecialchars($settings['storeBgImage']); ?>'); background-size: cover; background-position: center; background-repeat: no-repeat; }
        <?php endif; ?>
        <?php if (!empty($settings['productCardBg'])): ?>
        .product-card { background: <?php echo htmlspecialchars($settings['productCardBg']); ?>; }
        <?php endif; ?>
        <?php if (!empty($settings['productCardTextColor'])): ?>
        .product-card { color: <?php echo htmlspecialchars($settings['productCardTextColor']); ?>; }
        .product-card .product-title, .product-card .product-description { color: <?php echo htmlspecialchars($settings['productCardTextColor']); ?>; }
        <?php endif; ?>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--background);
            color: var(--foreground);
            line-height: 1.6;
        }

        .storefront-container {
            min-height: 100vh;
            padding: 1rem;
            position: relative;
        }

        .cart-button {
            position: fixed;
            top: 1rem;
            right: 1.5rem;
            z-index: 50;
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: var(--primary);
            color: var(--primary-foreground);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }

        .cart-button:hover {
            background: #f59e0b;
            transform: scale(1.05);
            box-shadow: var(--card-shadow-hover);
        }

        .cart-badge {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            background: var(--destructive);
            color: var(--destructive-foreground);
            border-radius: 50%;
            width: 1.25rem;
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding-top: 2rem;
        }

        .store-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: var(--glass-bg);
            backdrop-filter: blur(var(--glass-backdrop-blur));
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
        }

        .store-title {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .store-description {
            color: var(--muted-foreground);
        }

        .search-filters {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .search-filters {
                flex-direction: row;
                align-items: center;
            }
        }

        .search-container {
            position: relative;
            flex: 1;
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-foreground);
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            background: var(--input);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-button {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--foreground);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            text-decoration: none;
        }

        .filter-button.active,
        .filter-button:hover {
            background: var(--primary);
            color: var(--primary-foreground);
            border-color: var(--primary);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .products-grid {
                gap: 2rem;
            }
        }

        @media (min-width: 1024px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            }
        }

        .product-card {
            background: var(--glass-bg);
            backdrop-filter: blur(var(--glass-backdrop-blur));
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 1rem;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            min-height: 420px;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--card-shadow-hover);
        }

        .product-image {
            width: 100%;
            aspect-ratio: 1;
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--muted);
            margin-bottom: 1rem;
            position: relative;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .product-card:hover .product-image img {
            transform: scale(1.05);
        }

        .image-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--muted-foreground);
            font-size: 0.875rem;
        }

        .image-placeholder i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        .product-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .product-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .product-title {
            font-weight: 600;
            margin: 0;
            flex: 1;
            font-size: 1.125rem;
        }

        .category-badge {
            background: var(--secondary);
            color: var(--secondary-foreground);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            display: inline-block;
            flex-shrink: 0;
        }

        .product-description {
            color: var(--muted-foreground);
            font-size: 0.875rem;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 0.5rem;
            margin-top: auto;
        }

        .price {
            font-size: 1.125rem;
            font-weight: bold;
            color: var(--primary);
        }

        .add-button {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: var(--primary-foreground);
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .add-button:hover {
            background: #f59e0b;
            transform: translateY(-1px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(var(--glass-backdrop-blur));
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--muted-foreground);
            font-size: 0.875rem;
        }

        .hidden {
            display: none !important;
        }
    </style>
</head>
<body class="dark">
    <div class="storefront-container">
        <!-- Cart Button -->
        <button class="cart-button" onclick="toggleCart()">
            <i class="fas fa-shopping-cart"></i>
            <?php if ($cartCount > 0): ?>
                <span class="cart-badge"><?php echo $cartCount; ?></span>
            <?php endif; ?>
        </button>

        <div class="main-content">
            <!-- Store Header -->
            <div class="store-header">
                <?php if (!empty($settings['showStoreTitle'])): ?>
                <h1 class="store-title"><?php echo htmlspecialchars($settings['storeName']); ?></h1>
                <?php endif; ?>
                <?php if (!empty($settings['showStoreDescription'])): ?>
                <p class="store-description"><?php echo htmlspecialchars($settings['storeDescription']); ?></p>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <?php if (!empty($settings['showStatsBar'])): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(storefront_get_products()); ?></div>
                    <div class="stat-label">Products Available</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($categories); ?></div>
                    <div class="stat-label">Categories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $cartCount; ?></div>
                    <div class="stat-label">Items in Cart</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search and Filters -->
            <div class="search-filters">
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search products..."
                           value="<?php echo htmlspecialchars($search); ?>"
                           onchange="updateFilters(this.value, '<?php echo htmlspecialchars($category); ?>')">
                </div>

                <div class="filter-buttons">
                    <a href="?category=All&search=<?php echo urlencode($search); ?>"
                       class="filter-button <?php echo $category === 'All' ? 'active' : ''; ?>">
                        All
                    </a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="?category=<?php echo urlencode($cat); ?>&search=<?php echo urlencode($search); ?>"
                           class="filter-button <?php echo $category === $cat ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Products Grid -->
            <?php if (!empty($products)): ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php if (isset($product['image'])): ?>
                                    <img src="<?php echo htmlspecialchars($product['image']); ?>"
                                         alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <?php else: ?>
                                    <div class="image-placeholder">
                                        <i class="fas fa-image"></i>
                                        <span>No Image</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="product-info">
                                <div class="product-header">
                                    <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                    <?php if (!empty($product['category'])): ?>
                                        <span class="category-badge"><?php echo htmlspecialchars($product['category']); ?></span>
                                    <?php endif; ?>
                                    <?php if (($product['source'] ?? 'custom') === 'printful'): ?>
                                        <span class="category-badge" style="background: rgba(99,102,241,0.2); color: #818cf8;">POD</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($product['desc'])): ?>
                                    <p class="product-description">
                                        <?php echo htmlspecialchars($product['desc']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="product-footer">
                                    <div class="price">$<?php echo number_format(floatval($product['price']), 2); ?></div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button class="add-button" onclick="showProductDetails('<?php echo htmlspecialchars($product['sku']); ?>')" style="flex: 1;">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </button>
                                        <button class="add-button" onclick="addToCart('<?php echo htmlspecialchars($product['sku']); ?>')">
                                            <i class="fas fa-plus"></i>
                                            Add
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h3>No products found</h3>
                    <p>Try adjusting your search or filters</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cart Drawer - Instacart Style -->
    <div id="cart-drawer-overlay" class="drawer-overlay" onclick="toggleCart()"></div>
    <div id="cart-drawer" class="drawer">
        <div class="drawer-header">
            <div class="drawer-title">
                <i class="fas fa-shopping-cart"></i>
                Shopping Cart
                <span id="cart-count-badge" style="background: var(--primary); color: var(--primary-foreground); padding: 0.25rem 0.5rem; border-radius: 1rem; font-size: 0.75rem; margin-left: 0.5rem; display: none;">0</span>
            </div>
            <button class="drawer-close" onclick="toggleCart()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="drawer-content">
            <div id="cart-items">
                <!-- Cart items will be loaded here -->
            </div>

            <div id="cart-empty" class="drawer-empty-state">
                <div class="drawer-empty-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="drawer-empty-title">Your cart is empty</div>
                <div class="drawer-empty-description"><?php echo htmlspecialchars($settings['cartEmptyMessage']); ?></div>
            </div>
        </div>

        <div id="cart-total" class="drawer-footer" style="display: none;">
            <div style="margin-bottom: 1rem;">
                <div class="checkout-summary-item">
                    <span>Subtotal (<span id="item-count">0</span> items)</span>
                    <span id="subtotal-amount">$0.00</span>
                </div>
                <div class="checkout-summary-item">
                    <span>Tax</span>
                    <span id="tax-amount">$0.00</span>
                </div>
                <div class="checkout-summary-item">
                    <span>Shipping</span>
                    <span id="shipping-amount">$<?= number_format(floatval($settings['shippingCost'] ?? 9.99), 2) ?></span>
                </div>
                <div class="checkout-summary-total">
                    <span>Total</span>
                    <span id="total-amount" style="color: var(--primary); font-size: 1.25rem;">$0.00</span>
                </div>
                <div id="free-shipping-notice" style="text-align: center; font-size: 0.75rem; color: var(--muted-foreground); margin-top: 0.5rem; display: none;">
                    Add $<span id="free-shipping-needed">0.00</span> more for free shipping
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <button class="checkout-button" onclick="checkout()">
                    <i class="fas fa-credit-card"></i>
                    Proceed to Checkout
                </button>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="checkout-button checkout-button-secondary" onclick="clearCart()" style="flex: 1;">
                        Clear Cart
                    </button>
                    <button class="checkout-button checkout-button-secondary" onclick="toggleCart()" style="flex: 1;">
                        Continue Shopping
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Checkout Drawer - Instacart Style -->
    <div id="checkout-drawer-overlay" class="drawer-overlay" onclick="toggleCheckout()"></div>
    <div id="checkout-drawer" class="drawer">
        <div class="drawer-header">
            <div class="drawer-title">
                <i class="fas fa-lock"></i>
                Secure Checkout
            </div>
            <button class="drawer-close" onclick="toggleCheckout()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="drawer-content">
            <form id="checkout-form" onsubmit="processCheckout(event)">
                <!-- Customer Information -->
                <div class="checkout-section">
                    <div class="checkout-section-title">
                        <i class="fas fa-user"></i>
                        Customer Information
                    </div>

                    <div class="checkout-form-row two-cols">
                        <div class="checkout-form-group">
                            <label class="checkout-form-label">Full Name *</label>
                            <input type="text" name="name" required class="checkout-form-input" placeholder="John Doe">
                        </div>
                        <div class="checkout-form-group">
                            <label class="checkout-form-label">Email Address *</label>
                            <input type="email" name="email" required class="checkout-form-input" placeholder="john@example.com">
                        </div>
                    </div>

                    <div class="checkout-form-group">
                        <label class="checkout-form-label">Phone Number</label>
                        <input type="tel" name="phone" class="checkout-form-input" placeholder="(555) 123-4567">
                    </div>
                </div>

                <!-- Shipping Address -->
                <div class="checkout-section">
                    <div class="checkout-section-title">
                        <i class="fas fa-map-marker-alt"></i>
                        Delivery Address
                    </div>

                    <div class="checkout-form-group">
                        <label class="checkout-form-label">Street Address *</label>
                        <input type="text" name="address" required class="checkout-form-input" placeholder="123 Main Street">
                    </div>

                    <div class="checkout-form-row three-cols">
                        <div class="checkout-form-group">
                            <label class="checkout-form-label">City *</label>
                            <input type="text" name="city" required class="checkout-form-input" placeholder="New York">
                        </div>
                        <div class="checkout-form-group">
                            <label class="checkout-form-label">State *</label>
                            <input type="text" name="state" required class="checkout-form-input" placeholder="NY">
                        </div>
                        <div class="checkout-form-group">
                            <label class="checkout-form-label">ZIP *</label>
                            <input type="text" name="zip" required class="checkout-form-input" placeholder="12345">
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="checkout-section">
                    <div class="checkout-section-title">
                        <i class="fas fa-list"></i>
                        Order Summary
                    </div>
                    <div id="checkout-summary">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="checkout-section">
                    <div class="checkout-section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment Method
                    </div>
                    <div id="payment-methods-container">
                        <div id="payment-methods-loading" style="text-align: center; padding: 2rem; color: var(--muted-foreground);">
                            <i class="fas fa-spinner fa-spin"></i> Loading payment methods...
                        </div>
                        <div id="payment-methods-list" style="display:none;">
                            <!-- Dynamically populated with enabled gateways -->
                        </div>
                        <div id="payment-methods-none" style="display:none; text-align: center; padding: 2rem; background: var(--muted); border-radius: var(--radius);">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">&#x1F4B3;</div>
                            <p style="color: var(--muted-foreground);">No payment gateways configured. Order will be recorded for manual processing.</p>
                        </div>
                    </div>
                    <!-- Gateway-specific payment UI loads here -->
                    <div id="gateway-payment-ui" style="display:none; margin-top: 1rem;"></div>
                </div>

                <!-- Terms -->
                <div style="display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0;">
                    <input type="checkbox" name="terms" required>
                    <label style="font-size: 0.875rem;">I agree to the Terms of Service and Privacy Policy</label>
                </div>
            </form>
        </div>

        <div class="drawer-footer">
            <div style="margin-bottom: 1rem;">
                <div class="checkout-summary-total">
                    <span>Total</span>
                    <span id="checkout-total-amount" style="color: var(--primary); font-size: 1.25rem;">$0.00</span>
                </div>
            </div>

            <button type="submit" form="checkout-form" class="checkout-button">
                <i class="fas fa-lock"></i>
                Place Secure Order
            </button>

            <div style="display: flex; align-items: center; justify-content: center; gap: 0.25rem; margin-top: 0.75rem; font-size: 0.75rem; color: var(--muted-foreground);">
                <i class="fas fa-shield-alt"></i>
                <span>Secure checkout</span>
            </div>
        </div>
    </div>

    <script>
        // Store settings for tax/shipping calculations
        const STORE_CONFIG = {
            taxRate: <?= floatval($settings['taxRate'] ?? 0) / 100 ?>,
            shippingCost: <?= floatval($settings['shippingCost'] ?? 9.99) ?>,
            freeShippingThreshold: <?= floatval($settings['freeShippingThreshold'] ?? 50) ?>
        };

        // Product data for JavaScript access
        const productData = <?php echo json_encode(storefront_get_products()); ?>;

        function findProductBySku(sku) {
            return productData.find(p => p.sku === sku);
        }

        function showProductDetails(sku) {
            const product = findProductBySku(sku);
            if (!product) return;

            // Create and show product detail modal
            createProductDetailModal(product);
        }

        function createProductDetailModal(product) {
            // Check if modal already exists and remove it
            const existingModal = document.getElementById('product-detail-modal');
            if (existingModal) {
                existingModal.remove();
            }

            // Get main image and mini-gallery images
            const mainImage = product.image || `/media/store/${product.sku}/images/${product.sku}.jpg`;
            const miniGalleryImages = [];

            // Check for mini-gallery images (001-005)
            for (let i = 1; i <= 5; i++) {
                const imgNum = i.toString().padStart(3, '0');
                miniGalleryImages.push(`/media/store/${product.sku}/images/${product.sku}.minigallery.${imgNum}.jpg`);
            }

            // Build variations HTML
            let variationsHtml = '';
            if (product.variations && product.variations.groups && product.variations.groups.length > 0) {
                variationsHtml = `
                    <div class="variations-section">
                        <h4 style="font-weight: 600; margin-bottom: 1rem;">Product Options</h4>
                        ${product.variations.groups.map((group, groupIndex) => `
                            <div class="variation-group">
                                <label class="variation-group-title">${group.name}:</label>
                                <div class="variation-options">
                                    ${(group.options || []).map((option, optionIndex) => `
                                        <button type="button" class="variation-option"
                                                onclick="selectVariation('${group.name}', '${option}', this)">
                                            ${option}
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            }

            // Build mini-gallery HTML
            const galleryHtml = `
                <div class="mini-gallery">
                    <div class="gallery-main-image" id="main-product-image">
                        <img src="${mainImage}" alt="${product.name}"
                             onerror="this.parentElement.innerHTML='<div class=\\'gallery-placeholder\\'>No Image Available</div>'">
                    </div>
                    <div class="gallery-thumbnails">
                        <div class="gallery-thumbnail active" onclick="changeMainImage('${mainImage}')">
                            <img src="${mainImage}" alt="${product.name}"
                                 onerror="this.parentElement.innerHTML='<div class=\\'gallery-placeholder\\'>Main</div>'">
                        </div>
                        ${miniGalleryImages.map((img, index) => `
                            <div class="gallery-thumbnail" onclick="changeMainImage('${img}')">
                                <img src="${img}" alt="${product.name} ${index + 2}"
                                     onerror="this.parentElement.innerHTML='<div class=\\'gallery-placeholder\\'>${index + 2}</div>'">
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;

            // Create modal HTML
            const modalHtml = `
                <div id="product-detail-modal" class="modal active">
                    <div class="modal-content">
                        <button class="modal-close" onclick="closeProductDetails()">
                            <i class="fas fa-times"></i>
                        </button>

                        <div class="product-detail-content">
                            <div class="product-detail-grid">
                                <!-- Image Section -->
                                <div>
                                    ${galleryHtml}
                                </div>

                                <!-- Product Info -->
                                <div class="product-detail-info">
                                    <div class="product-detail-header">
                                        <h2 class="product-detail-title">${product.name}</h2>
                                        ${product.category ? `<span class="product-detail-category">${product.category}</span>` : ''}
                                    </div>

                                    <div class="product-detail-price">${parseFloat(product.price).toFixed(2)}</div>

                                    ${product.desc ? `<p class="product-detail-description">${product.desc}</p>` : ''}

                                    ${variationsHtml}

                                    <div class="add-to-cart-section">
                                        <button class="add-to-cart-button" onclick="addToCartFromModal('${product.sku}')">
                                            <i class="fas fa-plus"></i>
                                            Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }

        function closeProductDetails() {
            const modal = document.getElementById('product-detail-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => modal.remove(), 300);
            }
        }

        function changeMainImage(src) {
            const mainImg = document.querySelector('#main-product-image img');
            if (mainImg) {
                mainImg.src = src;
            }

            // Update active thumbnail
            document.querySelectorAll('.gallery-thumbnail').forEach(thumb => {
                thumb.classList.remove('active');
                const img = thumb.querySelector('img');
                if (img && img.src === src) {
                    thumb.classList.add('active');
                }
            });
        }

        let selectedVariations = {};

        function selectVariation(groupName, option, button) {
            selectedVariations[groupName] = option;

            // Update button styles in the same group
            const buttons = button.parentNode.querySelectorAll('button');
            buttons.forEach(btn => {
                btn.classList.remove('active');
            });

            button.classList.add('active');
        }

        function addToCartFromModal(sku) {
            addToCart(sku, 1);
            closeProductDetails();
            showNotification('Product added to cart!', 'success');
        }

        // JavaScript for cart functionality
        function addToCart(sku, quantity = 1) {
            const formData = new FormData();
            formData.append('action', 'add_to_cart');
            formData.append('sku', sku);
            formData.append('quantity', quantity);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    updateCartBadge(result.cart_count);
                    showNotification('Product added to cart!', 'success');
                } else {
                    showNotification('Failed to add to cart', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error adding to cart', 'error');
            });
        }

        function toggleCart() {
            const drawer = document.getElementById('cart-drawer');
            const overlay = document.getElementById('cart-drawer-overlay');

            if (drawer.classList.contains('active')) {
                drawer.classList.remove('active');
                overlay.classList.remove('active');
            } else {
                drawer.classList.add('active');
                overlay.classList.add('active');
                loadCart();
            }
        }

        function loadCart() {
            const formData = new FormData();
            formData.append('action', 'get_cart');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    displayCart(result.cart, result.total);
                }
            })
            .catch(error => {
                console.error('Error loading cart:', error);
            });
        }

        function displayCart(cart, total) {
            const cartItems = document.getElementById('cart-items');
            const cartTotal = document.getElementById('cart-total');
            const cartEmpty = document.getElementById('cart-empty');
            const totalAmount = document.getElementById('total-amount');
            const subtotalAmount = document.getElementById('subtotal-amount');
            const taxAmount = document.getElementById('tax-amount');
            const shippingAmount = document.getElementById('shipping-amount');
            const itemCount = document.getElementById('item-count');
            const cartCountBadge = document.getElementById('cart-count-badge');
            const freeShippingNotice = document.getElementById('free-shipping-notice');
            const freeShippingNeeded = document.getElementById('free-shipping-needed');

            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
            const subtotal = parseFloat(total);
            const tax = subtotal * STORE_CONFIG.taxRate;
            const shipping = (STORE_CONFIG.freeShippingThreshold > 0 && subtotal > STORE_CONFIG.freeShippingThreshold) ? 0 : STORE_CONFIG.shippingCost;
            const finalTotal = subtotal + tax + shipping;

            // Update cart count badge
            if (totalItems > 0) {
                cartCountBadge.textContent = totalItems;
                cartCountBadge.style.display = 'inline-block';
            } else {
                cartCountBadge.style.display = 'none';
            }

            if (cart.length === 0) {
                cartItems.innerHTML = '';
                cartTotal.style.display = 'none';
                cartEmpty.style.display = 'flex';
            } else {
                cartEmpty.style.display = 'none';
                cartTotal.style.display = 'block';

                let html = '';
                cart.forEach(item => {
                    const itemImage = item.image || `/media/store/${item.sku}/images/${item.sku}.jpg`;
                    html += `
                        <div class="cart-drawer-item">
                            <div class="cart-drawer-image">
                                <img src="${itemImage}" alt="${item.name}" onerror="this.parentElement.innerHTML='<div style=\\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--muted);color:var(--muted-foreground)\\'><i class=\\'fas fa-image\\'></i></div>'">
                            </div>
                            <div class="cart-drawer-info">
                                <div class="cart-drawer-name">${item.name}</div>
                                <div class="cart-drawer-price">$${parseFloat(item.price).toFixed(2)} each</div>
                                <div class="cart-drawer-controls">
                                    <button onclick="updateQuantity('${item.sku}', ${item.quantity - 1})">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <span class="cart-drawer-quantity">${item.quantity}</span>
                                    <button onclick="updateQuantity('${item.sku}', ${item.quantity + 1})">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="cart-drawer-actions">
                                <div class="cart-drawer-total">$${(parseFloat(item.price) * item.quantity).toFixed(2)}</div>
                                <button class="cart-drawer-remove" onclick="removeFromCart('${item.sku}')">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });

                cartItems.innerHTML = html;

                // Update totals
                itemCount.textContent = totalItems;
                subtotalAmount.textContent = '$' + subtotal.toFixed(2);
                taxAmount.textContent = '$' + tax.toFixed(2);

                if (shipping === 0) {
                    shippingAmount.innerHTML = '<span style="color: var(--primary); font-weight: 600;">FREE</span>';
                    freeShippingNotice.style.display = 'none';
                } else {
                    shippingAmount.textContent = '$' + shipping.toFixed(2);
                    const needed = (STORE_CONFIG.freeShippingThreshold - subtotal).toFixed(2);
                    freeShippingNeeded.textContent = needed;
                    freeShippingNotice.style.display = 'block';
                }

                totalAmount.textContent = '$' + finalTotal.toFixed(2);
            }
        }

        function updateQuantity(sku, quantity) {
            const formData = new FormData();
            formData.append('action', 'update_cart_quantity');
            formData.append('sku', sku);
            formData.append('quantity', quantity);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    updateCartBadge(result.cart_count);
                    loadCart();
                }
            })
            .catch(error => {
                console.error('Error updating quantity:', error);
            });
        }

        function removeFromCart(sku) {
            const formData = new FormData();
            formData.append('action', 'remove_from_cart');
            formData.append('sku', sku);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    updateCartBadge(result.cart_count);
                    loadCart();
                    showNotification('Item removed from cart', 'success');
                }
            })
            .catch(error => {
                console.error('Error removing item:', error);
            });
        }

        function clearCart() {
            if (!confirm('Are you sure you want to clear your cart?')) return;

            const formData = new FormData();
            formData.append('action', 'clear_cart');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    updateCartBadge(0);
                    loadCart();
                    showNotification('Cart cleared', 'success');
                }
            })
            .catch(error => {
                console.error('Error clearing cart:', error);
            });
        }

        function updateCartBadge(count) {
            const cartButton = document.querySelector('.cart-button');
            let badge = cartButton.querySelector('.cart-badge');

            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'cart-badge';
                    cartButton.appendChild(badge);
                }
                badge.textContent = count;
            } else if (badge) {
                badge.remove();
            }
        }

        function updateFilters(search, category) {
            const url = new URL(window.location);
            url.searchParams.set('search', search);
            url.searchParams.set('category', category);
            window.location.href = url.toString();
        }

        function checkout() {
            // Close cart drawer
            document.getElementById('cart-drawer').classList.remove('active');
            document.getElementById('cart-drawer-overlay').classList.remove('active');

            // Load checkout summary
            updateCheckoutSummary();

            // Show checkout drawer
            document.getElementById('checkout-drawer').classList.add('active');
            document.getElementById('checkout-drawer-overlay').classList.add('active');
        }

        function toggleCheckout() {
            const drawer = document.getElementById('checkout-drawer');
            const overlay = document.getElementById('checkout-drawer-overlay');

            if (drawer.classList.contains('active')) {
                drawer.classList.remove('active');
                overlay.classList.remove('active');
            } else {
                updateCheckoutSummary();
                drawer.classList.add('active');
                overlay.classList.add('active');
            }
        }

        function updateCheckoutSummary() {
            const formData = new FormData();
            formData.append('action', 'get_cart');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    displayCheckoutSummary(result.cart, result.total);
                }
            })
            .catch(error => {
                console.error('Error loading checkout summary:', error);
            });
        }

        function displayCheckoutSummary(cart, total) {
            const summaryDiv = document.getElementById('checkout-summary');
            const checkoutTotalAmount = document.getElementById('checkout-total-amount');

            if (cart.length === 0) {
                summaryDiv.innerHTML = '<p style="text-align: center; color: var(--muted-foreground);">Your cart is empty</p>';
                if (checkoutTotalAmount) checkoutTotalAmount.textContent = '$0.00';
                return;
            }

            const subtotal = parseFloat(total);
            const tax = subtotal * STORE_CONFIG.taxRate;
            const shipping = (STORE_CONFIG.freeShippingThreshold > 0 && subtotal > STORE_CONFIG.freeShippingThreshold) ? 0 : STORE_CONFIG.shippingCost;
            const finalTotal = subtotal + tax + shipping;

            let html = '';

            // Items list
            cart.forEach(item => {
                const itemImage = item.image || `/media/store/${item.sku}/images/${item.sku}.jpg`;
                html += `
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; padding: 0.75rem; background: var(--card/50); border: 1px solid var(--border/50); border-radius: var(--radius);">
                        <div style="width: 3rem; height: 3rem; background: var(--muted); border-radius: var(--radius); overflow: hidden; flex-shrink: 0;">
                            <img src="${itemImage}" alt="${item.name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.parentElement.innerHTML='<div style=\\'display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:var(--muted-foreground)\\'><i class=\\'fas fa-image\\'></i></div>'">
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 500; font-size: 0.875rem; margin-bottom: 0.25rem;">${item.name}</div>
                            <div style="font-size: 0.75rem; color: var(--muted-foreground);">Qty: ${item.quantity}</div>
                        </div>
                        <div style="font-weight: 600; font-size: 0.875rem;">
                            $${(parseFloat(item.price) * item.quantity).toFixed(2)}
                        </div>
                    </div>
                `;
            });

            // Totals breakdown
            html += `
                <div style="border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; font-size: 0.875rem;">
                        <span>Subtotal</span>
                        <span>$${subtotal.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; font-size: 0.875rem;">
                        <span>Tax</span>
                        <span>$${tax.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; font-size: 0.875rem;">
                        <span>Shipping</span>
                        <span>${shipping === 0 ? '<span style="color: var(--primary); font-weight: 600;">FREE</span>' : '$' + shipping.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 1rem; font-weight: 600; border-top: 1px solid var(--border); padding-top: 1rem;">
                        <span>Total</span>
                        <span style="color: var(--primary);">$${finalTotal.toFixed(2)}</span>
                    </div>
                </div>
            `;

            summaryDiv.innerHTML = html;

            // Update checkout footer total
            if (checkoutTotalAmount) {
                checkoutTotalAmount.textContent = '$' + finalTotal.toFixed(2);
            }
        }

        function processCheckout(event) {
            event.preventDefault();

            const form = event.target;
            const formData = new FormData(form);

            // Validate required fields
            const name = formData.get('name');
            const email = formData.get('email');
            const address = formData.get('address');
            const city = formData.get('city');
            const state = formData.get('state');
            const zip = formData.get('zip');
            const terms = formData.get('terms');

            if (!name || !email || !address || !city || !state || !zip) {
                showNotification('Please fill in all required fields', 'error');
                return;
            }

            if (!terms) {
                showNotification('Please accept the terms and conditions', 'error');
                return;
            }

            // Show processing state
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner loading-spinner" style="margin-right: 0.5rem;"></i>Processing...';
            submitBtn.disabled = true;

            // Prepare customer data
            const customerData = {
                name: name,
                email: email,
                phone: formData.get('phone') || '',
                address: {
                    street: address,
                    city: city,
                    state: state,
                    zip: zip,
                    country: 'United States'
                }
            };

            // Submit checkout with selected payment gateway
            const checkoutData = new FormData();
            checkoutData.append('action', 'process_checkout');
            checkoutData.append('customer_data', JSON.stringify(customerData));
            if (selectedGateway) {
                checkoutData.append('gateway_id', selectedGateway);
            }

            fetch('', {
                method: 'POST',
                body: checkoutData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showNotification(`Order placed successfully! Order ID: ${result.orderId}`, 'success');
                    updateCartBadge(0);

                    // Close drawers
                    document.getElementById('checkout-drawer').classList.remove('active');
                    document.getElementById('checkout-drawer-overlay').classList.remove('active');
                    document.getElementById('cart-drawer').classList.remove('active');
                    document.getElementById('cart-drawer-overlay').classList.remove('active');

                    // Reset form
                    form.reset();

                    // Show success message
                    setTimeout(() => {
                        alert(`Thank you for your order!\n\nOrder ID: ${result.orderId}\n\nYou will receive a confirmation email shortly.`);
                    }, 1000);
                } else {
                    showNotification('Order failed: ' + result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Checkout error:', error);
                showNotification('Checkout failed. Please try again.', 'error');
            })
            .finally(() => {
                // Restore button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        function showNotification(message, type) {
            // Simple notification system
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 1rem;
                left: 50%;
                transform: translateX(-50%);
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 0.75rem 1.5rem;
                border-radius: 0.5rem;
                z-index: 9999;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            `;
            notification.textContent = message;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(-50%) translateY(-20px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Close drawer when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('drawer-overlay')) {
                e.target.classList.remove('active');
                const drawerId = e.target.id.replace('-overlay', '');
                document.getElementById(drawerId).classList.remove('active');
            }

            // Close modal when clicking outside
            if (e.target.classList.contains('modal')) {
                closeProductDetails();
            }
        });

        // ESC key closes drawers and modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Close drawers
                document.querySelectorAll('.drawer.active').forEach(drawer => {
                    drawer.classList.remove('active');
                });
                document.querySelectorAll('.drawer-overlay.active').forEach(overlay => {
                    overlay.classList.remove('active');
                });

                // Close modals
                closeProductDetails();
            }
        });

        // Add spinner animation
        const style = document.createElement('style');
        style.textContent = `
            .loading-spinner {
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            /* Payment method selector */
            .payment-method-option {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 0.875rem 1rem;
                background: var(--glass-bg);
                border: 2px solid var(--border);
                border-radius: var(--radius);
                cursor: pointer;
                transition: all 0.2s ease;
                margin-bottom: 0.5rem;
            }
            .payment-method-option:hover {
                border-color: rgba(251, 191, 36, 0.4);
                background: rgba(251, 191, 36, 0.05);
            }
            .payment-method-option.selected {
                border-color: var(--primary);
                background: rgba(251, 191, 36, 0.1);
            }
            .payment-method-option input[type="radio"] {
                accent-color: var(--primary);
                width: 16px;
                height: 16px;
                flex-shrink: 0;
            }
            .pm-icon {
                width: 36px;
                height: 36px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 13px;
                flex-shrink: 0;
            }
            .pm-icon.paypal { background: rgba(217, 119, 6, 0.15); color: #d97706; }
            .pm-icon.stripe { background: rgba(129, 140, 248, 0.15); color: #818cf8; }
            .pm-icon.square { background: rgba(16, 185, 129, 0.15); color: #10b981; }
            .pm-info { flex: 1; }
            .pm-name { font-weight: 600; font-size: 0.9375rem; }
            .pm-mode { font-size: 0.75rem; color: var(--muted-foreground); }
            .pm-default-badge {
                font-size: 0.625rem;
                text-transform: uppercase;
                background: rgba(251, 191, 36, 0.15);
                color: var(--primary);
                padding: 2px 8px;
                border-radius: 4px;
                font-weight: 600;
                letter-spacing: 0.5px;
            }
        `;
        document.head.appendChild(style);

        /* ── Payment Gateway Integration ─────────────────────────────────── */
        let checkoutGateways = [];
        let selectedGateway = null;

        async function loadPaymentGateways() {
            const loadingEl = document.getElementById('payment-methods-loading');
            const listEl = document.getElementById('payment-methods-list');
            const noneEl = document.getElementById('payment-methods-none');

            try {
                const fd = new FormData();
                fd.append('action', 'get_checkout_config');
                const resp = await fetch('/panels/store/checkout-gateway.php', { method: 'POST', body: fd });
                const result = await resp.json();

                loadingEl.style.display = 'none';

                if (result.ok && result.data.gateways && result.data.gateways.length > 0) {
                    checkoutGateways = result.data.gateways;
                    const defaultGw = result.data.defaultGateway;

                    let html = '';
                    checkoutGateways.forEach((gw, i) => {
                        const isDefault = (gw.id === defaultGw);
                        const iconMap = { paypal: 'PP', stripe: 'S', square: 'Sq' };
                        html += `
                            <label class="payment-method-option ${isDefault ? 'selected' : ''}" id="pm-option-${gw.id}" onclick="selectPaymentMethod('${gw.id}')">
                                <input type="radio" name="payment_gateway" value="${gw.id}" ${isDefault ? 'checked' : ''}>
                                <div class="pm-icon ${gw.id}">${iconMap[gw.id] || gw.id.charAt(0).toUpperCase()}</div>
                                <div class="pm-info">
                                    <div class="pm-name">${gw.name}</div>
                                    <div class="pm-mode">${gw.sandbox ? 'Sandbox' : 'Live'} mode</div>
                                </div>
                                ${isDefault ? '<span class="pm-default-badge">Default</span>' : ''}
                            </label>
                        `;
                        if (isDefault) selectedGateway = gw.id;
                    });

                    // If no default, select first
                    if (!selectedGateway && checkoutGateways.length > 0) {
                        selectedGateway = checkoutGateways[0].id;
                    }

                    listEl.innerHTML = html;
                    listEl.style.display = 'block';

                    // Ensure first is visually selected if no default
                    if (selectedGateway) {
                        selectPaymentMethod(selectedGateway);
                    }
                } else {
                    // No gateways configured
                    noneEl.style.display = 'block';
                    selectedGateway = null;
                }
            } catch (err) {
                console.error('Failed to load payment gateways:', err);
                loadingEl.style.display = 'none';
                noneEl.style.display = 'block';
                selectedGateway = null;
            }
        }

        function selectPaymentMethod(gatewayId) {
            selectedGateway = gatewayId;

            // Update visual selection
            document.querySelectorAll('.payment-method-option').forEach(el => {
                el.classList.remove('selected');
            });
            const selected = document.getElementById('pm-option-' + gatewayId);
            if (selected) {
                selected.classList.add('selected');
                const radio = selected.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            }
        }

        // Override checkout() to also load gateways
        const _origCheckout = checkout;
        checkout = function() {
            _origCheckout();
            loadPaymentGateways();
        };
    </script>
</body>
</html>