<?php
/**
 * Printful Store Panel - Public Entry Point
 * 
 * File: SITE_ROOT/panels/printful/panel-printful.php
 * Version: 2025.11.09.1600
 * 
 * Public-facing Printful store display.
 * Called via [PANEL:printful] shortcode in page manager.
 */

// Load public API handler
require_once __DIR__ . '/includes/printful-public-api.php';

// Initialize public API
$publicAPI = new PrintfulPublicAPI();

// Single-product mode via ?product=ID (used by shortcode query overlay)
$singleProductId = $_GET['product'] ?? null;

if ($singleProductId) {
    $singleResult = $publicAPI->getProduct($singleProductId);
    $products = ($singleResult['success'] && $singleResult['product']) ? [$singleResult['product']] : [];
    $isSingleProduct = true;
} else {
    $productsResult = $publicAPI->getProducts();
    $products = $productsResult['products'] ?? [];
    $isSingleProduct = false;
}

?>
<div class="printful-store-panel">
    <div class="store-header">
        <h2 class="store-title">Our Store</h2>
        <div class="store-cart-icon" id="cart-toggle">
            🛒 <span class="cart-count">0</span>
        </div>
    </div>

    <?php if (empty($products)): ?>
        <div class="store-message">
            <p>No products available at this time. Please check back soon!</p>
        </div>
    <?php else: ?>
        <?php if (empty($isSingleProduct)): ?>
        <div class="store-filters">
            <input 
                type="text" 
                id="store-search" 
                class="store-search-input" 
                placeholder="Search products..."
            >
            <select id="store-category-filter" class="store-filter-select">
                <option value="">All Categories</option>
                <option value="apparel">Apparel</option>
                <option value="accessories">Accessories</option>
                <option value="home">Home & Living</option>
            </select>
        </div>
        <?php endif; ?>

        <div class="store-products-grid<?php echo !empty($isSingleProduct) ? ' single-product' : ''; ?>" id="products-grid">
            <?php foreach ($products as $product): ?>
                <?php
                    $productId = $product['id'] ?? '';
                    $productName = $product['name'] ?? 'Untitled Product';
                    $thumbnail = $product['thumbnail_url'] ?? 'https://via.placeholder.com/300';
                    $variants = $product['variants'] ?? 0;
                    $variantCount = is_array($variants) ? count($variants) : (int)$variants;
                    $hasVariants = $variantCount > 0;
                ?>
                <div class="store-product-card" data-product-id="<?php echo htmlspecialchars($productId); ?>">
                    <div class="product-image-wrapper">
                        <img 
                            src="<?php echo htmlspecialchars($thumbnail); ?>" 
                            alt="<?php echo htmlspecialchars($productName); ?>"
                            class="product-image"
                        >
                    </div>
                    <div class="product-info">
                        <h3 class="product-name"><?php echo htmlspecialchars($productName); ?></h3>
                        <?php if ($hasVariants): ?>
                            <p class="product-variants"><?php echo $variantCount; ?> options available</p>
                        <?php endif; ?>
                        <button class="btn-view-product" data-product-id="<?php echo htmlspecialchars($productId); ?>">
                            View Details
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Floating Cart Widget -->
    <div class="floating-cart" id="floating-cart" style="display: none;">
        <div class="cart-header">
            <h3>Shopping Cart</h3>
            <button class="cart-close" id="cart-close">×</button>
        </div>
        <div class="cart-items" id="cart-items">
            <p class="cart-empty">Your cart is empty</p>
        </div>
        <div class="cart-footer">
            <div class="cart-total">
                Total: <span id="cart-total">$0.00</span>
            </div>
            <button class="btn-checkout" id="btn-checkout">Proceed to Checkout</button>
        </div>
    </div>

    <!-- Product Detail Modal -->
    <div class="product-modal" id="product-modal" style="display: none;">
        <div class="modal-overlay" id="modal-overlay"></div>
        <div class="modal-content">
            <button class="modal-close" id="modal-close">×</button>
            <div class="modal-body" id="modal-body">
                <!-- Product details loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Include panel CSS and JS -->
<link rel="stylesheet" href="/panels/printful/assets/css/printful-store.css">
<script src="/panels/printful/assets/js/printful-store.js"></script>

<script>
// Initialize store with product data
window.PRINTFUL_PRODUCTS = <?php echo json_encode($products); ?>;
</script>
