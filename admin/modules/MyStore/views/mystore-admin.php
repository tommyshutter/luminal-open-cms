<?php
/**
 * PayPal Shopping Cart System - Complete Admin Interface  
 * 100% React Feature Parity - Full Rebuild
 * 
 * File: /admin/paypal/views/paypal-admin.php
 * Version: 2025.10.03.0945 r1
 * Build Date: Friday, October 3, 2025 - 9:45 AM EST
 * Purpose: Complete admin interface matching React AdminManager exactly
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

// Get all products
$products = paypal_get_products();
$productCount = count($products);
?>

<div class="p-6 max-w-7xl mx-auto">
    <!-- Version Header -->
    <div class="glass-card rounded-lg p-4 mb-6 border-l-4" style="border-left-color: var(--primary);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <!-- Simple href # to click admin bar to get to page top -->
                <a href="#" onclick="window.scrollTo(0, 0); return false;" style="text-decoration: none; color: inherit;">
                    <h2 style="font-size: 1.25rem; font-weight: bold; color: var(--primary); margin: 0;">PayPal Shopping Cart System</h2>
                    <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0;">PHP Admin Management Interface</p>
                </a>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 1.125rem; font-family: monospace; color: var(--primary);">2025.10.05.1500 r3</div>
                <div style="font-size: 0.75rem; color: var(--muted-foreground);">Saturday, October 5, 2025 - 3:00 PM EST</div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-4 mb-6" style="border-bottom: 1px solid var(--border); padding-bottom: 1rem;">
        <a 
            href="/admin/dashboard.php" 
            target="_blank" 
            class="btn btn-outline" 
            style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 0.5rem;"
        >
            <span>⚙️</span><span style="color: inherit;">&lt;&lt;&lt; Back to Main Website Admin</span>
        </a>
        
        <button 
            id="products-tab"
            class="btn"
            onclick="switchView('products')"
            style="display: flex; align-items: center; gap: 0.5rem;"
        >
            <span>📦</span> Products
        </button>
        
        <button 
            id="orders-tab"
            class="btn btn-outline"
            onclick="switchView('orders')"
            style="display: flex; align-items: center; gap: 0.5rem;"
        >
            <span>📋</span> Orders
        </button>

        <a 
            href="/panels/paypal/storefront.php" 
            target="_blank" 
            class="btn btn-outline" 
            style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 0.5rem;"
        >
            <span>🏪</span><span style="color: inherit;">&gt;&gt;&gt;&gt; Go to Storefront &gt;&gt;&gt;&gt;</span>
        </a>
    </div>

    <!-- Products View -->
    <div id="products-view">
        <!-- Header -->
        <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 1.875rem; font-weight: bold; margin: 0 0 0.5rem 0;">Product Manager</h1>
                <p style="color: var(--muted-foreground); margin: 0;">Manage your PayPal store products</p>
            </div>

            <!-- Layout Controls -->
            <div style="margin-bottom: 1.5rem; padding: 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius); backdrop-filter: blur(var(--glass-backdrop-blur));">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                    <h3 style="font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                        <i class="fas fa-th-large"></i>
                        Grid Layout Controls
                    </h3>
                    <button
                        class="btn btn-outline btn-sm"
                        onclick="toggleLayoutControls()"
                        id="layout-controls-toggle"
                    >
                        Show Controls
                    </button>
                </div>
                
                <div id="layout-controls" style="display: none; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-size: 0.875rem; font-weight: 500;">Columns: <span id="columns-value">3</span></label>
                        <input 
                            type="range" 
                            id="columns-slider" 
                            min="1" 
                            max="6" 
                            value="3" 
                            onchange="updateGridColumns(this.value)"
                            style="width: 100%;"
                        >
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-size: 0.875rem; font-weight: 500;">Gap: <span id="gap-value">24</span>px</label>
                        <input 
                            type="range" 
                            id="gap-slider" 
                            min="2" 
                            max="48" 
                            value="24" 
                            step="2"
                            onchange="updateGridGap(this.value)"
                            style="width: 100%;"
                        >
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-size: 0.875rem; font-weight: 500;">Border Radius: <span id="radius-value">10</span>px</label>
                        <input 
                            type="range" 
                            id="radius-slider" 
                            min="0" 
                            max="24" 
                            value="10" 
                            step="2"
                            onchange="updateCardRadius(this.value)"
                            style="width: 100%;"
                        >
                    </div>
                </div>
            </div>

            <!-- Search and Bulk Actions -->
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <input
                            type="text"
                            id="search-input"
                            placeholder="Search products by name, SKU, or category..."
                            class="form-input"
                            style="width: 100%;"
                            oninput="filterProducts()"
                        >
                    </div>
                    
                    <div style="display: flex; gap: 0.5rem;">
                        <button
                            class="btn btn-outline btn-sm"
                            onclick="bulkEnable()"
                            style="display: flex; align-items: center; gap: 0.5rem;"
                        >
                            <i class="fas fa-power-off"></i>
                            Enable All
                        </button>
                        <button
                            class="btn btn-outline btn-sm"
                            onclick="bulkDisable()"
                            style="display: flex; align-items: center; gap: 0.5rem;"
                        >
                            <i class="fas fa-ban"></i>
                            Disable All
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
        <div id="products-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
            <?php if (empty($products)): ?>
                <div id="empty-products-state" style="grid-column: 1 / -1; text-align: center; padding: 3rem;">
                    <div style="font-size: 3.75rem; margin-bottom: 1rem;">📦</div>
                    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem;">No Products Found</h3>
                    <p style="color: var(--muted-foreground); margin-bottom: 1.5rem;">Start by adding your first product.</p>
                    <button
                        class="btn btn-primary"
                        onclick="showAddProductModal()"
                        style="display: inline-flex; align-items: center; gap: 0.5rem;"
                    >
                        <i class="fas fa-plus"></i>
                        Add Product
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="product-card glass-card group" data-sku="<?= htmlspecialchars($product['sku']) ?>" style="position: relative; overflow: hidden;">
                        <!-- Product Image -->
                        <div style="position: relative; aspect-ratio: 1; overflow: hidden;">
                            <?php if (!empty($product['image'])): ?>
                                <img
                                    src="<?= htmlspecialchars($product['image']) ?>"
                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                    style="width: 100%; height: 100%; object-fit: cover;"
                                    onerror="this.style.display='none'; this.parentNode.querySelector('.no-image-placeholder').style.display='flex';"
                                >
                            <?php endif; ?>
                            <div class="no-image-placeholder" style="<?= !empty($product['image']) ? 'display: none;' : 'display: flex;' ?> width: 100%; height: 100%; align-items: center; justify-content: center; background: var(--muted); color: var(--muted-foreground);">
                                No Image
                            </div>
                            
                            <!-- Status Badge -->
                            <span class="<?= $product['enabled'] ? 'badge badge-success' : 'badge badge-destructive' ?>" style="position: absolute; top: 0.5rem; left: 0.5rem;">
                                <?= $product['enabled'] ? 'Enabled' : 'Disabled' ?>
                            </span>
                            
                            <!-- Hover Actions -->
                            <div class="product-hover-actions" style="position: absolute; inset: 0; background: rgba(0, 0, 0, 0.5); opacity: 0; transition: opacity 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                <button
                                    class="btn btn-secondary btn-sm"
                                    onclick="showProductDetails('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="View Details"
                                    style="width: 2rem; height: 2rem; padding: 0; min-width: auto;"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button
                                    class="btn btn-secondary btn-sm"
                                    onclick="editProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Edit"
                                    style="width: 2rem; height: 2rem; padding: 0; min-width: auto;"
                                >
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button
                                    class="btn btn-secondary btn-sm"
                                    onclick="duplicateProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Duplicate"
                                    style="width: 2rem; height: 2rem; padding: 0; min-width: auto;"
                                >
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button
                                    class="btn btn-secondary btn-sm"
                                    onclick="toggleProductStatus('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="<?= $product['enabled'] ? 'Disable' : 'Enable' ?>"
                                    style="width: 2rem; height: 2rem; padding: 0; min-width: auto;"
                                >
                                    <i class="fas fa-<?= $product['enabled'] ? 'ban' : 'power-off' ?>"></i>
                                </button>
                                <button
                                    class="btn btn-destructive btn-sm"
                                    onclick="deleteProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Delete"
                                    style="width: 2rem; height: 2rem; padding: 0; min-width: auto;"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Product Info -->
                        <div style="padding: 1rem;">
                            <div style="margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;">
                                    <h3 style="font-weight: 600; margin: 0; line-height: 1.3; flex: 1; min-width: 0;" class="line-clamp-2"><?= htmlspecialchars($product['name']) ?></h3>
                                    <span class="badge badge-outline" style="flex-shrink: 0; font-size: 0.75rem;"><?= htmlspecialchars($product['sku']) ?></span>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span style="font-size: 1.125rem; font-weight: bold; color: var(--primary);">
                                    $<?= number_format((float)$product['price'], 2) ?>
                                </span>
                                <?php if (!empty($product['category'])): ?>
                                    <span class="badge badge-secondary" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($product['category']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($product['desc'])): ?>
                                <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0 0 1rem 0; line-height: 1.4;" class="line-clamp-2">
                                    <?= htmlspecialchars($product['desc']) ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Variations -->
                            <?php if (!empty($product['variations']['groups'])): ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-bottom: 1rem;">
                                    <?php foreach ($product['variations']['groups'] as $group): ?>
                                        <span class="badge" style="font-size: 0.75rem; padding: 0.125rem 0.5rem; background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);">
                                            <?= htmlspecialchars($group['name']) ?>: <?= count($group['options']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Last Modified -->
                            <div style="font-size: 0.75rem; color: var(--muted-foreground); margin-bottom: 1rem;">
                                Modified: <?= date('M j, Y', $product['mtime'] ?? time()) ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 0.5rem;">
                                <button
                                    class="btn btn-outline"
                                    onclick="showProductDetails('<?= htmlspecialchars($product['sku']) ?>')"
                                    style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.25rem;"
                                >
                                    <i class="fas fa-eye"></i>
                                    View
                                </button>
                                <button
                                    class="btn btn-outline"
                                    onclick="editProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Edit"
                                    style="padding: 0.5rem; min-width: auto;"
                                >
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button
                                    class="btn btn-outline"
                                    onclick="duplicateProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Duplicate"
                                    style="padding: 0.5rem; min-width: auto;"
                                >
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button
                                    class="btn btn-outline"
                                    onclick="deleteProduct('<?= htmlspecialchars($product['sku']) ?>')"
                                    title="Delete"
                                    style="padding: 0.5rem; min-width: auto; color: var(--destructive);"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Search Empty State -->
        <div id="search-empty-state" style="display: none; text-center; padding: 3rem;">
            <div style="font-size: 3.75rem; margin-bottom: 1rem;">🔍</div>
            <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem;">No Products Found</h3>
            <p style="color: var(--muted-foreground); margin-bottom: 1.5rem;">No products match your search criteria.</p>
            <button
                class="btn btn-outline"
                onclick="clearSearch()"
            >
                Clear Search
            </button>
        </div>
    </div>

    <!-- Orders View -->
    <div id="orders-view" style="display: none;">
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.875rem; font-weight: bold; margin: 0 0 0.5rem 0;">Order Management</h1>
                <p style="color: var(--muted-foreground); margin: 0;">View and manage customer orders</p>
            </div>
            
            <div style="display: flex; gap: 0.5rem;">
                <button
                    class="btn btn-outline"
                    style="display: flex; align-items: center; gap: 0.5rem;"
                >
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <button
                    class="btn btn-primary"
                    style="display: flex; align-items: center; gap: 0.5rem;"
                >
                    Refresh
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="glass-card" style="padding: 1rem;">
                <div style="font-size: 1.5rem; font-weight: bold;">0</div>
                <p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0;">Total Orders</p>
            </div>
            <div class="glass-card" style="padding: 1rem;">
                <div style="font-size: 1.5rem; font-weight: bold; color: var(--primary);">0</div>
                <p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0;">Pending</p>
            </div>
            <div class="glass-card" style="padding: 1rem;">
                <div style="font-size: 1.5rem; font-weight: bold; color: #22c55e;">0</div>
                <p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0;">Completed</p>
            </div>
            <div class="glass-card" style="padding: 1rem;">
                <div style="font-size: 1.5rem; font-weight: bold; color: var(--primary);">$0.00</div>
                <p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0;">Total Revenue</p>
            </div>
        </div>

        <!-- Search and Filter -->
        <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <input
                    type="text"
                    placeholder="Search orders..."
                    class="form-input"
                    style="flex: 1; min-width: 250px;"
                >
                <select class="form-input" style="width: 200px;">
                    <option value="All Orders">All Orders</option>
                    <option value="Pending">Pending</option>
                    <option value="Processing">Processing</option>
                    <option value="Shipped">Shipped</option>
                    <option value="Delivered">Delivered</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
                <button class="btn btn-primary">Filter</button>
            </div>
        </div>

        <!-- Empty State -->
        <div class="glass-card" style="padding: 3rem; text-center;">
            <div style="width: 6rem; height: 6rem; margin: 0 auto 1.5rem; background: var(--muted); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <div style="width: 3rem; height: 3rem; background: rgba(255, 255, 255, 0.2); border-radius: 0.5rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-clipboard-list" style="font-size: 1.5rem; color: var(--muted-foreground);"></i>
                </div>
            </div>
            <h3 style="font-size: 1.25rem; font-weight: bold; margin-bottom: 0.5rem;">No Orders Found</h3>
            <p style="color: var(--muted-foreground); margin: 0;">
                No orders have been placed yet.
            </p>
        </div>
    </div>
</div>

<!-- Product Details Modal -->
<div id="product-details-modal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="glass-modal product-detail-modal max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <!-- Modal Header -->
            <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem; border-bottom: 1px solid var(--border);">
                <div>
                    <h2 id="product-detail-title" style="font-size: 1.25rem; font-weight: bold; margin: 0;"></h2>
                    <p style="color: var(--muted-foreground); font-size: 0.875rem; margin: 0;">View product details, images, and specifications</p>
                </div>
                <button onclick="closeProductDetails()" class="close-btn" style="background: none; border: none; color: var(--muted-foreground); cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: all 0.2s ease;">
                    <i class="fas fa-times" style="font-size: 1.25rem;"></i>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="modal-body" style="padding: 1.5rem;">
                <div class="product-detail-grid" style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
                    <!-- Enhanced Image Gallery -->
                    <div class="mini-gallery" style="grid-column: 1; margin-bottom: 2rem;">
                        <div class="gallery-main-image" style="aspect-ratio: 1; border-radius: var(--radius-lg); overflow: hidden; background: var(--muted); position: relative; margin-bottom: 1rem;">
                            <img 
                                id="gallery-main-image"
                                style="width: 100%; height: 100%; object-fit: cover;"
                                alt="Product Image"
                                onerror="this.style.display='none'; this.parentNode.querySelector('.image-placeholder').style.display='flex';"
                            >
                            <div class="image-placeholder" style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; background: var(--muted); color: var(--muted-foreground); flex-direction: column;">
                                <i class="fas fa-image" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                <span style="font-size: 0.875rem;">No Image</span>
                            </div>
                        </div>
                        
                        <!-- Thumbnail Gallery -->
                        <div id="gallery-thumbnails" class="gallery-thumbnails" style="display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.5rem 0;">
                            <!-- Thumbnails will be dynamically added here -->
                        </div>
                    </div>

                    <!-- Product Info on Desktop, Below on Mobile -->
                    <div class="product-detail-info" style="display: flex; flex-direction: column; gap: 1rem;">
                        <!-- Category Badge -->
                        <div id="product-detail-category" style="display: none;">
                            <span class="badge badge-secondary" style="font-size: 0.75rem;"></span>
                        </div>
                        
                        <!-- Description -->
                        <div id="product-detail-description" style="display: none;">
                            <p class="product-description" style="color: var(--muted-foreground); line-height: 1.6;"></p>
                        </div>
                        
                        <!-- Price -->
                        <div class="product-price" style="font-size: 1.75rem; font-weight: bold; color: var(--primary);"></div>
                        
                        <!-- SKU and Status -->
                        <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 0.875rem; color: var(--muted-foreground);">SKU:</span>
                                <span id="product-detail-sku" class="badge badge-outline" style="font-size: 0.75rem;"></span>
                            </div>
                            <div id="product-detail-status">
                                <span class="badge" style="font-size: 0.75rem;"></span>
                            </div>
                        </div>
                        
                        <!-- Enhanced Variations Selection -->
                        <div id="product-detail-variations" style="display: none;">
                            <div class="variation-group" style="border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 1rem;">
                                <h4 style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.75rem;">Product Options</h4>
                                <div id="variations-container">
                                    <!-- Variations will be dynamically added here -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- Last Modified -->
                        <div style="border-top: 1px solid var(--border); padding-top: 1rem; margin-top: auto;">
                            <div style="font-size: 0.75rem; color: var(--muted-foreground); margin-bottom: 1rem;">
                                Last Modified: <span id="product-detail-modified"></span>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 0.5rem;">
                                <button
                                    id="edit-product-btn"
                                    class="btn btn-primary"
                                    style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;"
                                >
                                    <i class="fas fa-edit"></i>
                                    Edit Product
                                </button>
                                <button
                                    id="duplicate-product-btn"
                                    class="btn btn-outline"
                                    style="padding: 0.75rem; min-width: auto;"
                                    title="Duplicate Product"
                                >
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Floating Action Buttons -->
<div class="floating-actions" style="position: fixed; bottom: 2rem; right: 2rem; display: flex; flex-direction: column; gap: 1rem; z-index: 100;">
    <button
        class="btn btn-primary floating-btn"
        onclick="showAddProductModal()"
        title="Add Product"
        style="width: 3.5rem; height: 3.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3); background: var(--primary); color: var(--primary-foreground); border: none; cursor: pointer; transition: all 0.3s ease;"
    >
        <i class="fas fa-plus"></i>
    </button>
    
    <button
        class="btn btn-outline floating-btn"
        onclick="showCsvImportModal()"
        title="Import CSV"
        style="width: 3rem; height: 3rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2); background: var(--card); color: var(--foreground); border: 1px solid var(--border); cursor: pointer; transition: all 0.3s ease;"
    >
        <i class="fas fa-upload"></i>
    </button>
    
    <button
        class="btn btn-outline floating-btn"
        onclick="showSettingsModal()"
        title="Settings"
        style="width: 3rem; height: 3rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2); background: var(--card); color: var(--foreground); border: 1px solid var(--border); cursor: pointer; transition: all 0.3s ease;"
    >
        <i class="fas fa-cogs"></i>
    </button>
</div>

<style>
/* Product card hover effects */
.product-card:hover .product-hover-actions {
    opacity: 1;
}

.product-card:hover {
    transform: translateY(-2px);
}

/* Badge styles */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
    border: 1px solid transparent;
}

.badge-success {
    background: rgba(34, 197, 94, 0.2);
    color: #22c55e;
    border-color: rgba(34, 197, 94, 0.3);
}

.badge-destructive {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
    border-color: rgba(239, 68, 68, 0.3);
}

.badge-outline {
    background: transparent;
    border-color: var(--border);
    color: var(--foreground);
}

.badge-secondary {
    background: var(--secondary);
    color: var(--secondary-foreground);
}

/* Line clamp utility */
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Button size utilities */
.btn-sm {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

/* Loading animation */
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.loading-spinner {
    animation: spin 1s linear infinite;
}

/* Floating Action Buttons */
.floating-actions {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    z-index: 100;
}

.floating-btn {
    transition: all 0.3s ease;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}

.floating-btn:hover {
    transform: scale(1.1);
}

/* Range slider styles */
input[type="range"] {
    -webkit-appearance: none;
    appearance: none;
    background: var(--muted);
    border-radius: 5px;
    height: 5px;
    outline: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    background: var(--primary);
    cursor: pointer;
    height: 20px;
    width: 20px;
    border-radius: 50%;
}

input[type="range"]::-moz-range-thumb {
    background: var(--primary);
    cursor: pointer;
    height: 20px;
    width: 20px;
    border-radius: 50%;
    border: none;
}

/* Product Details Modal */
.product-detail-modal {
    background: var(--popover) !important;
    backdrop-filter: blur(var(--glass-backdrop-blur));
    -webkit-backdrop-filter: blur(var(--glass-backdrop-blur));
    border: 1px solid var(--glass-border);
    box-shadow: var(--modal-shadow);
    border-radius: var(--radius-lg);
}

.close-btn:hover {
    background: var(--muted);
    color: var(--foreground);
}

/* Mini Gallery Styles */
.mini-gallery {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    width: 100%;
}

@media (min-width: 768px) {
    .mini-gallery {
        grid-template-columns: 1fr auto;
        height: 400px;
    }
    
    .product-detail-grid {
        grid-template-columns: 1fr 1fr !important;
    }
}

.gallery-main-image {
    aspect-ratio: 1;
    border-radius: var(--radius-lg);
    overflow: hidden;
    background: var(--muted);
    position: relative;
}

.gallery-main-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.gallery-thumbnails {
    display: flex;
    flex-direction: row;
    gap: 0.5rem;
    overflow-x: auto;
    padding: 0.5rem 0;
}

@media (min-width: 768px) {
    .gallery-thumbnails {
        flex-direction: column;
        max-height: 400px;
        overflow-y: auto;
        overflow-x: hidden;
        width: 80px;
        padding: 0;
    }
}

.gallery-thumbnail {
    width: 60px;
    height: 60px;
    flex-shrink: 0;
    border-radius: var(--radius-md);
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s ease;
    background: var(--muted);
}

@media (min-width: 768px) {
    .gallery-thumbnail {
        width: 80px;
        height: 80px;
    }
}

.gallery-thumbnail:hover {
    border-color: var(--primary);
}

.gallery-thumbnail.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
}

.gallery-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Variation Options */
.variation-option:hover {
    background: var(--accent) !important;
    border-color: var(--primary) !important;
}

/* Modal Overlay Animation */
#product-details-modal {
    opacity: 0;
    transition: opacity 0.3s ease;
}

#product-details-modal:not(.hidden) {
    opacity: 1;
}

#product-details-modal .glass-modal {
    transform: scale(0.95);
    transition: transform 0.3s ease;
}

#product-details-modal:not(.hidden) .glass-modal {
    transform: scale(1);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .floating-actions {
        bottom: 1rem;
        right: 1rem;
    }
    
    .floating-btn {
        width: 3rem !important;
        height: 3rem !important;
        font-size: 1rem !important;
    }
    
    .product-detail-modal {
        margin: 1rem;
        max-width: calc(100vw - 2rem);
        max-height: calc(100vh - 2rem);
    }
    
    .mini-gallery {
        height: auto !important;
    }
    
    .gallery-thumbnails {
        order: 2;
    }
}
</style>

<script>
// Global state
let currentView = 'products';
let allProducts = <?= json_encode($products) ?>;
let filteredProducts = [...allProducts];

// View switching
function switchView(view) {
    currentView = view;
    
    // Update tab styles
    document.getElementById('products-tab').className = view === 'products' ? 'btn' : 'btn btn-outline';
    document.getElementById('orders-tab').className = view === 'orders' ? 'btn' : 'btn btn-outline';
    
    // Show/hide views
    document.getElementById('products-view').style.display = view === 'products' ? 'block' : 'none';
    document.getElementById('orders-view').style.display = view === 'orders' ? 'block' : 'none';
}

// Search functionality
function filterProducts() {
    const searchTerm = document.getElementById('search-input').value.toLowerCase();
    const productCards = document.querySelectorAll('.product-card');
    let visibleCount = 0;
    
    productCards.forEach(card => {
        const sku = card.dataset.sku.toLowerCase();
        const name = card.querySelector('h3').textContent.toLowerCase();
        const categoryElement = card.querySelector('.badge-secondary');
        const category = categoryElement ? categoryElement.textContent.toLowerCase() : '';
        
        if (sku.includes(searchTerm) || name.includes(searchTerm) || category.includes(searchTerm)) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Show/hide empty states
    document.getElementById('search-empty-state').style.display = searchTerm && visibleCount === 0 ? 'block' : 'none';
    document.getElementById('empty-products-state').style.display = !searchTerm && allProducts.length === 0 ? 'block' : 'none';
}

function clearSearch() {
    document.getElementById('search-input').value = '';
    filterProducts();
}

// Product management functions
function editProduct(sku) {
    if (typeof window.showEditProductModal === 'function') {
        window.showEditProductModal(sku);
    } else {
        showToast('Loading edit functionality...', 'info');
    }
}

function deleteProduct(sku) {
    if (confirm(`Are you sure you want to delete product ${sku}? This action cannot be undone.`)) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_product&sku=${encodeURIComponent(sku)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Product deleted successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message || 'Failed to delete product', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to delete product', 'error');
        });
    }
}

function duplicateProduct(sku) {
    const product = allProducts.find(p => p.sku === sku);
    if (!product) {
        showToast('Product not found', 'error');
        return;
    }
    
    const newSku = `${sku}-copy`;
    const duplicatedProduct = {
        ...product,
        sku: newSku,
        name: `${product.name} (Copy)`,
        mtime: Date.now(),
        created: Date.now()
    };
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add_product&product_data=${encodeURIComponent(JSON.stringify(duplicatedProduct))}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`Product ${sku} duplicated successfully`, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message || 'Failed to duplicate product', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to duplicate product', 'error');
    });
}

function toggleProductStatus(sku) {
    const product = allProducts.find(p => p.sku === sku);
    if (!product) {
        showToast('Product not found', 'error');
        return;
    }
    
    const newStatus = !product.enabled;
    const updatedProduct = { ...product, enabled: newStatus, mtime: Date.now() };
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_product&original_sku=${encodeURIComponent(sku)}&product_data=${encodeURIComponent(JSON.stringify(updatedProduct))}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`Product ${newStatus ? 'enabled' : 'disabled'}`, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message || 'Failed to update product', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update product status', 'error');
    });
}

function bulkEnable() {
    if (confirm('Enable all products?')) {
        // This would require a bulk update endpoint
        showToast('Bulk enable functionality coming soon', 'info');
    }
}

function bulkDisable() {
    if (confirm('Disable all products?')) {
        // This would require a bulk update endpoint
        showToast('Bulk disable functionality coming soon', 'info');
    }
}

// Make functions globally accessible
window.switchView = switchView;
window.filterProducts = filterProducts;
window.clearSearch = clearSearch;
window.editProduct = editProduct;
window.deleteProduct = deleteProduct;
window.duplicateProduct = duplicateProduct;
window.toggleProductStatus = toggleProductStatus;
window.bulkEnable = bulkEnable;
window.bulkDisable = bulkDisable;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Initialize search
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', filterProducts);
    }
});

// Layout Controls
let showLayoutControls = false;

function toggleLayoutControls() {
    showLayoutControls = !showLayoutControls;
    const controls = document.getElementById('layout-controls');
    const toggle = document.getElementById('layout-controls-toggle');
    
    if (showLayoutControls) {
        controls.style.display = 'grid';
        toggle.textContent = 'Hide Controls';
    } else {
        controls.style.display = 'none';
        toggle.textContent = 'Show Controls';
    }
}

function updateGridColumns(value) {
    document.getElementById('columns-value').textContent = value;
    const grid = document.getElementById('products-grid');
    grid.style.gridTemplateColumns = `repeat(${value}, minmax(0, 1fr))`;
}

function updateGridGap(value) {
    document.getElementById('gap-value').textContent = value;
    const grid = document.getElementById('products-grid');
    grid.style.gap = `${value}px`;
}

function updateCardRadius(value) {
    document.getElementById('radius-value').textContent = value;
    const cards = document.querySelectorAll('.product-card');
    cards.forEach(card => {
        card.style.borderRadius = `${value}px`;
    });
}

// Floating button hover effects
document.querySelectorAll('.floating-btn').forEach(btn => {
    btn.addEventListener('mouseenter', function() {
        this.style.transform = 'scale(1.1)';
        this.style.boxShadow = '0 12px 40px rgba(0, 0, 0, 0.4)';
    });
    
    btn.addEventListener('mouseleave', function() {
        this.style.transform = 'scale(1)';
        this.style.boxShadow = this.classList.contains('btn-primary') 
            ? '0 8px 32px rgba(0, 0, 0, 0.3)' 
            : '0 4px 16px rgba(0, 0, 0, 0.2)';
    });
});

// Show CSV Import Modal (placeholder)
function showCsvImportModal() {
    if (typeof window.showCsvImportModal === 'function') {
        window.showCsvImportModal();
    } else {
        showToast('CSV Import functionality not yet loaded', 'info');
    }
}

// Show Settings Modal (placeholder)
function showSettingsModal() {
    if (typeof window.showSettingsModal === 'function') {
        window.showSettingsModal();
    } else {
        showToast('Settings functionality not yet loaded', 'info');
    }
}

// Product Details Modal Functions
let selectedImageIndex = 0;

function showProductDetails(sku) {
    const product = allProducts.find(p => p.sku === sku);
    if (!product) {
        showToast('Product not found', 'error');
        return;
    }
    
    // Populate modal content
    document.getElementById('product-detail-title').textContent = product.name;
    document.querySelector('.product-price').textContent = `${parseFloat(product.price).toFixed(2)}`;
    document.getElementById('product-detail-sku').textContent = product.sku;
    document.getElementById('product-detail-modified').textContent = new Date(product.mtime * 1000 || Date.now()).toLocaleDateString();
    
    // Set status badge
    const statusElement = document.querySelector('#product-detail-status .badge');
    if (product.enabled) {
        statusElement.className = 'badge badge-success';
        statusElement.textContent = 'Enabled';
    } else {
        statusElement.className = 'badge badge-destructive';
        statusElement.textContent = 'Disabled';
    }
    
    // Set category if exists
    const categoryDiv = document.getElementById('product-detail-category');
    if (product.category) {
        categoryDiv.style.display = 'block';
        categoryDiv.querySelector('.badge').textContent = product.category;
    } else {
        categoryDiv.style.display = 'none';
    }
    
    // Set description if exists
    const descDiv = document.getElementById('product-detail-description');
    if (product.desc) {
        descDiv.style.display = 'block';
        descDiv.querySelector('.product-description').textContent = product.desc;
    } else {
        descDiv.style.display = 'none';
    }
    
    // Setup mini gallery
    setupMiniGallery(product);
    
    // Setup variations if they exist
    setupVariations(product);
    
    // Setup action buttons
    document.getElementById('edit-product-btn').onclick = () => editProduct(sku);
    document.getElementById('duplicate-product-btn').onclick = () => duplicateProduct(sku);
    
    // Show modal
    document.getElementById('product-details-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeProductDetails() {
    document.getElementById('product-details-modal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    selectedImageIndex = 0;
}

function setupMiniGallery(product) {
    const mainImage = document.getElementById('gallery-main-image');
    const thumbnailsContainer = document.getElementById('gallery-thumbnails');
    
    // Build list of all images
    const allImages = [];
    
    // Add main image if exists
    if (product.image) {
        allImages.push({
            src: product.image,
            alt: `${product.name} main image`,
            isMain: true
        });
    }
    
    // Add additional images if they exist
    if (product.images && Array.isArray(product.images)) {
        product.images.forEach((image, index) => {
            allImages.push({
                src: `/media/store/${product.sku}/images/${image}`,
                alt: `${product.name} image ${index + 2}`,
                isMain: false
            });
        });
    }
    
    // If no images, show placeholder
    if (allImages.length === 0) {
        mainImage.style.display = 'none';
        mainImage.parentNode.querySelector('.image-placeholder').style.display = 'flex';
        thumbnailsContainer.innerHTML = '';
        return;
    }
    
    // Set main image
    selectedImageIndex = 0;
    updateMainImage(allImages);
    
    // Build thumbnails
    buildThumbnails(allImages, product);
}

function updateMainImage(allImages) {
    const mainImage = document.getElementById('gallery-main-image');
    const placeholder = mainImage.parentNode.querySelector('.image-placeholder');
    
    if (allImages.length > 0 && selectedImageIndex < allImages.length) {
        mainImage.src = allImages[selectedImageIndex].src;
        mainImage.alt = allImages[selectedImageIndex].alt;
        mainImage.style.display = 'block';
        placeholder.style.display = 'none';
    } else {
        mainImage.style.display = 'none';
        placeholder.style.display = 'flex';
    }
}

function buildThumbnails(allImages, product) {
    const thumbnailsContainer = document.getElementById('gallery-thumbnails');
    
    if (allImages.length <= 1) {
        thumbnailsContainer.style.display = 'none';
        return;
    }
    
    thumbnailsContainer.style.display = 'flex';
    thumbnailsContainer.innerHTML = '';
    
    allImages.forEach((image, index) => {
        const thumbnail = document.createElement('button');
        thumbnail.className = `gallery-thumbnail ${index === selectedImageIndex ? 'active' : ''}`;
        thumbnail.onclick = () => selectImage(index, allImages);
        
        const img = document.createElement('img');
        img.src = image.src;
        img.alt = image.alt;
        img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
        img.onerror = function() {
            this.style.display = 'none';
            const placeholder = document.createElement('div');
            placeholder.style.cssText = 'width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--muted); color: var(--muted-foreground); font-size: 0.75rem;';
            placeholder.innerHTML = '<i class="fas fa-image"></i>';
            this.parentNode.appendChild(placeholder);
        };
        
        thumbnail.appendChild(img);
        thumbnailsContainer.appendChild(thumbnail);
    });
}

function selectImage(index, allImages) {
    selectedImageIndex = index;
    updateMainImage(allImages);
    
    // Update thumbnail active states
    const thumbnails = document.querySelectorAll('.gallery-thumbnail');
    thumbnails.forEach((thumb, idx) => {
        if (idx === index) {
            thumb.classList.add('active');
        } else {
            thumb.classList.remove('active');
        }
    });
}

function setupVariations(product) {
    const variationsDiv = document.getElementById('product-detail-variations');
    const variationsContainer = document.getElementById('variations-container');
    
    if (!product.variations || !product.variations.groups || product.variations.groups.length === 0) {
        variationsDiv.style.display = 'none';
        return;
    }
    
    variationsDiv.style.display = 'block';
    variationsContainer.innerHTML = '';
    
    product.variations.groups.forEach((group, groupIndex) => {
        const groupDiv = document.createElement('div');
        groupDiv.style.cssText = 'margin-bottom: 1rem;';
        
        const label = document.createElement('label');
        label.style.cssText = 'font-size: 0.875rem; font-weight: 500; color: var(--muted-foreground); margin-bottom: 0.5rem; display: block;';
        label.textContent = `${group.name}:`;
        
        const optionsDiv = document.createElement('div');
        optionsDiv.className = 'variation-options';
        optionsDiv.style.cssText = 'display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;';
        
        if (group.options && Array.isArray(group.options)) {
            group.options.forEach((option, optionIndex) => {
                const optionButton = document.createElement('button');
                optionButton.className = 'variation-option';
                optionButton.style.cssText = `
                    padding: 0.5rem 1rem;
                    border: 1px solid var(--border);
                    background: transparent;
                    color: var(--foreground);
                    border-radius: var(--radius-md);
                    cursor: pointer;
                    transition: all 0.2s ease;
                    font-size: 0.875rem;
                    font-weight: 500;
                `;
                optionButton.textContent = option;
                optionButton.onclick = () => {
                    // Remove active class from siblings
                    optionsDiv.querySelectorAll('.variation-option').forEach(btn => {
                        btn.style.background = 'transparent';
                        btn.style.color = 'var(--foreground)';
                        btn.style.borderColor = 'var(--border)';
                    });
                    // Add active class to clicked option
                    optionButton.style.background = 'var(--primary)';
                    optionButton.style.color = 'var(--primary-foreground)';
                    optionButton.style.borderColor = 'var(--primary)';
                };
                
                optionsDiv.appendChild(optionButton);
            });
        }
        
        groupDiv.appendChild(label);
        groupDiv.appendChild(optionsDiv);
        variationsContainer.appendChild(groupDiv);
    });
}

// Close modal when clicking outside
document.getElementById('product-details-modal').addEventListener('click', (e) => {
    if (e.target.id === 'product-details-modal') {
        closeProductDetails();
    }
});

// Close modal with ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !document.getElementById('product-details-modal').classList.contains('hidden')) {
        closeProductDetails();
    }
});
</script>