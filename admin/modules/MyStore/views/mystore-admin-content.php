<?php
/**
 * My Store — Admin Content View
 *
 * HTML body content for the admin interface. No document wrapper —
 * rendered inside the admin shell via MyStoreAdmin.php.
 *
 * Variables available: $products, $settings, $view (from mystore-admin-functions.php)
 */
?>

<div class="ps-container">
    <!-- Navigation -->
    <div class="ps-nav">
        <button class="ps-btn <?= $view === 'products' ? 'ps-btn-primary' : 'ps-btn-outline' ?>"
                onclick="PS.setView('products')">
            <i class="fas fa-box"></i> Products
        </button>
        <a class="ps-btn ps-btn-outline" href="orders.php" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
            <i class="fas fa-clipboard-list"></i> Orders
        </a>
        <button class="ps-btn" onclick="PS.showSettingsModal()"
                style="background:rgba(34,139,34,0.15);border:1px solid rgba(34,139,34,0.5);color:#4ade80">
            <i class="fas fa-cogs"></i> Settings
        </button>
    </div>

    <!-- Header -->
    <div class="ps-header">
        <div>
            <h2 class="ps-text-2xl ps-font-bold ps-mb-2">
                <span id="ps-view-title">Product Manager</span>
            </h2>
            <p class="ps-text-muted" id="ps-view-description">
                Manage your store products
            </p>
        </div>

        <div class="ps-flex ps-gap-2 ps-flex-wrap">
            <!-- Import, Settings, Theme moved to Layout Controls bar -->
            <button class="ps-btn ps-btn-primary" onclick="PS.showAddProductModal()">
                <i class="fas fa-plus"></i> Add Product
            </button>
        </div>
    </div>

    <!-- Products View -->
    <div id="ps-products-view">
        <!-- Layout Controls -->
        <div class="ps-layout-controls ps-glass-card">
            <div class="ps-controls-header" style="cursor:pointer" onclick="var p=document.getElementById('ps-layout-controls-panel');p.style.display=p.style.display==='none'?'':'none'">
                <h3><i class="fas fa-sliders-h"></i> Layout Controls</h3>
                <span class="ps-text-muted ps-text-sm" id="ps-layout-toggle-hint">&#9660;</span>
            </div>

            <div id="ps-layout-controls-panel" class="ps-controls-panel" style="display:flex;flex-wrap:wrap;gap:12px;padding:10px 0 4px;align-items:flex-end">
                <div class="ps-control-group" style="flex:1;min-width:120px">
                    <label style="font-size:0.68rem">Columns: <strong id="ps-columns-value"><?= intval($settings['grid_columns'] ?? 4) ?></strong></label>
                    <input type="range" id="ps-grid_columns" min="2" max="6" value="<?= intval($settings['grid_columns'] ?? 4) ?>" oninput="PS.updateGridLayout();PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="flex:1;min-width:120px">
                    <label style="font-size:0.68rem">Per Page: <strong id="ps-per-page-value"><?= intval($settings['per_page'] ?? 0) ?: 'All' ?></strong></label>
                    <input type="range" id="ps-per_page" min="0" max="48" step="4" value="<?= intval($settings['per_page'] ?? 0) ?>" oninput="var v=parseInt(this.value);document.getElementById('ps-per-page-value').textContent=v||'All';PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:80px">
                    <label style="font-size:0.68rem">Card BG</label>
                    <input type="color" id="ps-store_card_bg" value="<?= htmlspecialchars($settings['store_card_bg'] ?? '#1a1a2e') ?>" style="width:100%;height:28px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:80px">
                    <label style="font-size:0.68rem">Page BG</label>
                    <input type="color" id="ps-store_page_bg" value="<?= htmlspecialchars($settings['store_page_bg'] ?? '#0f1114') ?>" style="width:100%;height:28px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:80px">
                    <label style="font-size:0.68rem">Accent</label>
                    <input type="color" id="ps-store_accent" value="<?= htmlspecialchars($settings['store_accent'] ?? '#f59e0b') ?>" style="width:100%;height:28px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:100px">
                    <label style="font-size:0.68rem">BG Opacity: <strong id="ps-opacity-val"><?= intval($settings['store_bg_opacity'] ?? 100) ?>%</strong></label>
                    <input type="range" id="ps-store_bg_opacity" min="0" max="100" step="5" value="<?= intval($settings['store_bg_opacity'] ?? 100) ?>" oninput="document.getElementById('ps-opacity-val').textContent=this.value+'%';PS.livePreviewColors();PS.markUnsaved()">
                </div>
                <div style="display:flex;gap:6px;margin-left:auto">
                    <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.showImportModal()" style="height:28px;font-size:0.72rem">
                        <i class="fas fa-upload"></i> Import
                    </button>
                    <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.showSettingsModal()" style="height:28px;font-size:0.72rem">
                        <i class="fas fa-cogs"></i> Settings
                    </button>
                    <button class="ps-btn ps-btn-primary ps-btn-sm" onclick="PS.saveSettings()" style="height:28px;font-size:0.72rem">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </div>
            <!-- Typography row -->
            <div style="display:flex;flex-wrap:wrap;gap:10px;padding:8px 0 4px;border-top:1px solid rgba(255,255,255,0.04);margin-top:8px;align-items:flex-end">
                <div class="ps-control-group" style="min-width:70px">
                    <label style="font-size:0.62rem">Title Color</label>
                    <input type="color" id="ps-store_title_color" value="<?= htmlspecialchars($settings['store_title_color'] ?? '#f0f0f0') ?>" style="width:100%;height:24px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:65px">
                    <label style="font-size:0.62rem">Title <strong id="ps-title-sz-val"><?= $settings['store_title_size'] ?? '0.88' ?></strong>rem</label>
                    <input type="range" id="ps-store_title_size" min="0.7" max="1.4" step="0.02" value="<?= $settings['store_title_size'] ?? '0.88' ?>" oninput="document.getElementById('ps-title-sz-val').textContent=this.value;PS.livePreviewColors();PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:70px">
                    <label style="font-size:0.62rem">Price Color</label>
                    <input type="color" id="ps-store_price_color" value="<?= htmlspecialchars($settings['store_price_color'] ?? '#f59e0b') ?>" style="width:100%;height:24px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:65px">
                    <label style="font-size:0.62rem">Price <strong id="ps-price-sz-val"><?= $settings['store_price_size'] ?? '1.05' ?></strong>rem</label>
                    <input type="range" id="ps-store_price_size" min="0.8" max="1.6" step="0.05" value="<?= $settings['store_price_size'] ?? '1.05' ?>" oninput="document.getElementById('ps-price-sz-val').textContent=this.value;PS.livePreviewColors();PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:70px">
                    <label style="font-size:0.62rem">Desc Color</label>
                    <input type="color" id="ps-store_desc_color" value="<?= htmlspecialchars($settings['store_desc_color'] ?? '#888888') ?>" style="width:100%;height:24px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:70px">
                    <label style="font-size:0.62rem">Meta Color</label>
                    <input type="color" id="ps-store_meta_color" value="<?= htmlspecialchars($settings['store_meta_color'] ?? '#666666') ?>" style="width:100%;height:24px;cursor:pointer;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:none" oninput="PS.livePreviewColors()" onchange="PS.markUnsaved()">
                </div>
                <div class="ps-control-group" style="min-width:65px">
                    <label style="font-size:0.62rem">Font <strong id="ps-font-val"><?= $settings['store_font'] ?? 'system' ?></strong></label>
                    <select id="ps-store_font" style="height:24px;font-size:0.65rem;padding:0 4px;border:1px solid rgba(255,255,255,0.12);border-radius:4px;background:#1a1a2e;color:#ccc;cursor:pointer" onchange="document.getElementById('ps-font-val').textContent=this.value;PS.livePreviewColors();PS.markUnsaved()">
                        <option value="system" <?= ($settings['store_font'] ?? 'system') === 'system' ? 'selected' : '' ?>>System</option>
                        <option value="Inter" <?= ($settings['store_font'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                        <option value="Georgia" <?= ($settings['store_font'] ?? '') === 'Georgia' ? 'selected' : '' ?>>Georgia</option>
                        <option value="Courier" <?= ($settings['store_font'] ?? '') === 'Courier' ? 'selected' : '' ?>>Courier</option>
                        <option value="inherit" <?= ($settings['store_font'] ?? '') === 'inherit' ? 'selected' : '' ?>>Site Font</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Search and Bulk Actions -->
        <div class="ps-search-section">
            <div class="ps-search-container">
                <input type="text" id="ps-product-search" placeholder="Search products..." class="ps-search-input">
                <i class="fas fa-search ps-search-icon"></i>
            </div>

            <div class="ps-bulk-actions">
                <label class="ps-bulk-selectall" title="Select all products">
                    <input type="checkbox" id="ps-product-select-all" onchange="PS.toggleSelectAllProducts(this.checked)">
                    <span>Select All</span>
                </label>
                <span class="ps-bulk-count" id="ps-bulk-count">0 selected</span>
                <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.bulkEnable()">
                    <i class="fas fa-power-off"></i> Enable All
                </button>
                <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.bulkDisable()">
                    <i class="fas fa-ban"></i> Disable All
                </button>
                <button class="ps-btn ps-btn-destructive ps-btn-sm ps-bulk-delete-btn" id="ps-bulk-delete-btn" onclick="PS.bulkDeleteSelected()" disabled>
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
            </div>
        </div>

        <!-- Products Grid -->
        <div id="ps-products-grid" class="ps-products-grid">
            <?php if (empty($products)): ?>
                <div class="ps-empty-state" style="grid-column: 1 / -1;">
                    <i class="fas fa-box-open"></i>
                    <h3 class="ps-text-xl ps-font-bold ps-mb-2">No Products Found</h3>
                    <p>Products will appear here when JSON files are added to /admin/data/mystore/products/</p>
                    <button class="ps-btn ps-btn-primary ps-mt-4" onclick="PS.showAddProductModal()">
                        <i class="fas fa-plus"></i> Add Your First Product
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($products as $index => $product): ?>
                    <?php
                    $imagePath = $product['image'] ?? '';
                    if (empty($imagePath)) {
                        $imagePath = getProductImagePath($product['sku']);
                    }
                    if (empty($imagePath)) {
                        $imagePath = null;
                    }
                    ?>
                    <div class="ps-product-card ps-glass-card" data-sku="<?= htmlspecialchars($product['sku']) ?>" onclick="if(!event.target.closest('.ps-product-actions')&&!event.target.closest('.ps-product-select-wrap'))PS.editProduct(<?= $index ?>)" style="cursor:pointer">
                        <label class="ps-product-select-wrap" onclick="event.stopPropagation()" title="Select for bulk actions">
                            <input type="checkbox" class="ps-product-select" data-sku="<?= htmlspecialchars($product['sku']) ?>" onchange="PS.onProductSelectChange()">
                        </label>
                        <div class="ps-product-image-container">
                            <?php if ($imagePath && file_exists(SITE_ROOT . $imagePath)): ?>
                                <img src="<?= htmlspecialchars($imagePath) ?>"
                                     alt="<?= htmlspecialchars($product['name']) ?>"
                                     class="ps-product-image">
                            <?php else: ?>
                                <div class="ps-image-placeholder">
                                    <i class="fas fa-image"></i>
                                    <div>No Image</div>
                                    <div class="ps-text-sm"><?= htmlspecialchars($product['sku']) ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="ps-status-badge">
                                <span class="ps-badge <?= ($product['enabled'] ?? true) ? 'ps-badge-success' : 'ps-badge-destructive' ?>">
                                    <?= ($product['enabled'] ?? true) ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>
                        </div>

                        <div class="ps-product-info">
                            <div class="ps-mb-2">
                                <h3 class="ps-font-bold ps-text-lg"><?= htmlspecialchars($product['name']) ?></h3>
                                <p class="ps-text-sm ps-text-muted"><?= htmlspecialchars($product['sku']) ?></p>
                            </div>

                            <div class="ps-flex ps-items-center ps-justify-between ps-mb-2">
                                <div class="ps-text-xl ps-font-bold ps-text-primary">$<?= number_format(floatval($product['price'] ?? 0), 2) ?></div>
                                <?php if (!empty($product['category'])): ?>
                                    <span class="ps-badge ps-badge-secondary"><?= htmlspecialchars($product['category']) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($product['desc'])): ?>
                                <p class="ps-text-sm ps-text-muted ps-mb-4">
                                    <?= htmlspecialchars(substr($product['desc'], 0, 100)) ?>
                                    <?= strlen($product['desc']) > 100 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>

                            <div class="ps-product-actions">
                                <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.editProduct(<?= $index ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.duplicateProduct('<?= htmlspecialchars($product['sku']) ?>')">
                                    <i class="fas fa-copy"></i> Duplicate
                                </button>
                                <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.toggleProduct('<?= htmlspecialchars($product['sku']) ?>')">
                                    <i class="fas fa-<?= ($product['enabled'] ?? true) ? 'eye-slash' : 'eye' ?>"></i>
                                    <?= ($product['enabled'] ?? true) ? 'Disable' : 'Enable' ?>
                                </button>
                                <button class="ps-btn ps-btn-destructive ps-btn-sm" onclick="PS.deleteProduct('<?= htmlspecialchars($product['sku']) ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Orders View -->
    <div id="ps-orders-view" class="ps-hidden">
<?php
    $ordersFile = SITE_ROOT . $MYSTORE_DATA_BASE . 'orders.json';
    $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
?>
        <!-- Controls Bar -->
        <div class="ps-glass-card ps-p-4 ps-mb-4" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <select id="ps-order-status-filter" onchange="PS.ordersFilterChanged()" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#ccc;padding:6px 10px;border-radius:6px;font-size:0.78rem">
                <option value="all">All Statuses</option>
                <option value="completed">Completed</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="on-hold">On-hold</option>
                <option value="failed">Failed</option>
                <option value="refunded">Refunded</option>
                <option value="cancelled">Cancelled</option>
                <option value="archived">Archived</option>
            </select>
            <input type="text" id="ps-order-search" placeholder="Search name, email, order ID..." oninput="PS.ordersFilterChanged()" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#ccc;padding:6px 10px;border-radius:6px;font-size:0.78rem;min-width:200px;flex:1;max-width:320px">
            <select id="ps-orders-per-page" onchange="PS.ordersFilterChanged()" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#ccc;padding:6px 10px;border-radius:6px;font-size:0.78rem">
                <option value="10">10 per page</option>
                <option value="20" selected>20 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
            <button class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.exportOrdersCsv()" style="white-space:nowrap"><i class="fas fa-download"></i> Export CSV</button>
            <div style="display:flex;gap:6px;align-items:center;margin-left:auto">
                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:0.75rem;color:#888">
                    <input type="checkbox" id="ps-order-select-all" onchange="PS.toggleSelectAllOrders(this.checked)" style="cursor:pointer"> Select All
                </label>
                <select id="ps-order-bulk-action" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#ccc;padding:6px 10px;border-radius:6px;font-size:0.78rem">
                    <option value="">Bulk Actions</option>
                    <option value="archive_selected">Archive Selected</option>
                    <option value="delete_selected">Delete Selected</option>
                    <option value="mark_completed">Mark Completed</option>
                    <option value="mark_processing">Mark Processing</option>
                </select>
                <button class="ps-btn ps-btn-primary ps-btn-sm" onclick="PS.applyBulkOrderAction()">Apply</button>
            </div>
        </div>

        <!-- Stats Cards (live-filter aware, updated by JS) -->
        <div class="ps-grid ps-grid-cols-2 ps-gap-4 ps-mb-6" style="grid-template-columns:repeat(4,1fr)">
            <div class="ps-glass-card ps-p-4">
                <div class="ps-text-2xl ps-font-bold" id="ps-orders-stat-total">0</div>
                <p class="ps-text-muted ps-text-sm">Total Orders</p>
            </div>
            <div class="ps-glass-card ps-p-4">
                <div class="ps-text-2xl ps-font-bold ps-text-primary" id="ps-orders-stat-revenue">$0.00</div>
                <p class="ps-text-muted ps-text-sm">Revenue</p>
            </div>
            <div class="ps-glass-card ps-p-4">
                <div class="ps-text-2xl ps-font-bold" style="color:#22c55e" id="ps-orders-stat-completed">0</div>
                <p class="ps-text-muted ps-text-sm">Completed</p>
            </div>
            <div class="ps-glass-card ps-p-4">
                <div class="ps-text-2xl ps-font-bold" style="color:#f59e0b" id="ps-orders-stat-pending">0</div>
                <p class="ps-text-muted ps-text-sm">Pending</p>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="ps-glass-card" style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.82rem" id="ps-orders-table">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.08);text-align:left">
                        <th style="padding:10px 8px;width:30px"></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;cursor:pointer" onclick="PS.sortOrders('customer')">Customer <span class="ps-sort-icon" data-col="customer">&#8597;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('order')">Order <span class="ps-sort-icon" data-col="order">&#8597;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('date')">Date <span class="ps-sort-icon" data-col="date">&#9660;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('items')">Items <span class="ps-sort-icon" data-col="items">&#8597;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('total')">Total <span class="ps-sort-icon" data-col="total">&#8597;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('payment')">Payment <span class="ps-sort-icon" data-col="payment">&#8597;</span></th>
                        <th style="padding:10px 12px;color:#888;font-size:0.7rem;text-transform:uppercase;cursor:pointer" onclick="PS.sortOrders('status')">Status <span class="ps-sort-icon" data-col="status">&#8597;</span></th>
                    </tr>
                </thead>
                <tbody id="ps-orders-tbody">
                    <!-- Rendered by JS -->
                </tbody>
                <tfoot id="ps-orders-tfoot">
                    <tr style="border-top:2px solid rgba(255,255,255,0.1)">
                        <td style="padding:10px 8px"></td>
                        <td style="padding:10px 12px;font-weight:700;color:#f0f0f0" id="ps-orders-foot-count">0 orders</td>
                        <td style="padding:10px 12px"></td>
                        <td style="padding:10px 12px"></td>
                        <td style="padding:10px 12px;color:#888;font-size:0.75rem" id="ps-orders-foot-items">0 items sold</td>
                        <td style="padding:10px 12px;font-weight:800;color:#f59e0b" id="ps-orders-foot-revenue">$0.00</td>
                        <td style="padding:10px 12px"></td>
                        <td style="padding:10px 12px;font-size:0.72rem;color:#888" id="ps-orders-foot-completed">0 completed</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Pagination -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:0.78rem;color:#888" id="ps-orders-pagination">
            <span id="ps-orders-showing">Showing 0 of 0 orders</span>
            <div id="ps-orders-page-buttons" style="display:flex;gap:4px;align-items:center"></div>
        </div>
    </div>
</div>

<!-- ========================================================================
     Add/Edit Product Modal
     ======================================================================== -->
<div id="ps-product-modal" class="ps-modal">
    <div class="ps-modal-content ps-glass-modal" style="max-width: 960px;">
        <div class="ps-modal-header">
            <h3 id="ps-modal-title">Add Product</h3>
            <button onclick="PS.closeModal('ps-product-modal')" class="ps-btn ps-btn-outline ps-btn-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="ps-modal-body">
            <form id="ps-product-form">
                <div class="ps-settings-section">
                    <h4>Basic Information</h4>

                    <div class="ps-form-row ps-mb-4">
                        <div class="ps-form-group">
                            <label for="ps-product-sku">SKU *</label>
                            <input type="text" id="ps-product-sku" name="sku" required class="ps-form-input" placeholder="Unique product identifier">
                            <small class="ps-text-muted">Must be unique</small>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-product-name">Product Name *</label>
                            <input type="text" id="ps-product-name" name="name" required class="ps-form-input" placeholder="Product name">
                        </div>
                    </div>

                    <div class="ps-form-row-3 ps-mb-4">
                        <div class="ps-form-group">
                            <label for="ps-product-price">Price *</label>
                            <input type="number" id="ps-product-price" name="price" step="0.01" min="0" required class="ps-form-input" placeholder="0.00" oninput="PS.updateVariantFinals()">
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-product-category">Category</label>
                            <input type="text" id="ps-product-category" name="category" class="ps-form-input" placeholder="Product category">
                        </div>
                        <div class="ps-form-group">
                            <label class="ps-checkbox-label" style="margin-top: 1.5rem;">
                                <input type="checkbox" id="ps-product-enabled" name="enabled" checked>
                                <span>Enabled</span>
                            </label>
                        </div>
                    </div>

                    <div class="ps-form-group">
                        <label for="ps-product-description">Description</label>
                        <textarea id="ps-product-description" name="description" rows="3" class="ps-form-input" placeholder="Product description"></textarea>
                    </div>
                </div>

                <div class="ps-settings-section">
                    <h4>Product Images</h4>
                    <input type="hidden" id="ps-product-image" name="image" value="">

                    <!-- Main image + gallery in a two-column layout -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px" id="ps-img-layout">
                        <!-- Main Image -->
                        <div>
                            <label class="ps-text-sm ps-font-bold ps-text-muted" style="display:block;margin-bottom:4px">Main Image</label>
                            <div id="ps-main-image-box" style="aspect-ratio:1;border:2px dashed rgba(255,255,255,0.1);border-radius:10px;overflow:hidden;position:relative;background:rgba(255,255,255,0.02);display:flex;align-items:center;justify-content:center;cursor:pointer"
                                 onclick="document.getElementById('ps-image-upload-main').click()">
                                <img id="ps-main-image-preview" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none">
                                <div id="ps-main-image-empty" style="text-align:center;color:#555">
                                    <i class="fas fa-image" style="font-size:2rem;margin-bottom:6px;display:block"></i>
                                    <div style="font-size:0.75rem">Click or drop to set main image</div>
                                </div>
                            </div>
                            <input type="file" id="ps-image-upload-main" accept="image/*" style="display:none" onchange="PS.uploadMainImage(this.files[0])">
                        </div>

                        <!-- Gallery -->
                        <div>
                            <label class="ps-text-sm ps-font-bold ps-text-muted" style="display:block;margin-bottom:4px">Gallery</label>
                            <div id="ps-gallery-dropzone" style="min-height:120px;border:2px dashed rgba(255,255,255,0.1);border-radius:10px;padding:8px;transition:border-color 0.2s">
                                <div id="ps-gallery-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px"></div>
                                <div id="ps-gallery-empty" style="text-align:center;padding:20px;color:#555;font-size:0.75rem">
                                    <i class="fas fa-images" style="font-size:1.5rem;margin-bottom:4px;display:block"></i>
                                    Drop images here to add to gallery
                                </div>
                            </div>
                            <input type="file" id="ps-image-upload-gallery" accept="image/*" multiple style="display:none" onchange="PS.uploadGalleryImages(this.files)">
                            <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="document.getElementById('ps-image-upload-gallery').click()" style="margin-top:8px;width:100%">
                                <i class="fas fa-plus"></i> Add Images
                            </button>
                        </div>
                    </div>
                </div>

                <div class="ps-settings-section">
                    <h4>Product Variations (Optional)</h4>
                    <div class="ps-p-4" style="background: var(--ps-muted); border-radius: var(--ps-radius);">
                        <p class="ps-text-sm ps-text-muted ps-mb-4">
                            Add variations like sizes, colors, etc. Leave empty if product has no variations.
                        </p>
                        <div id="ps-variations-container"></div>
                        <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.addVariationGroup()">
                            <i class="fas fa-plus"></i> Add Variation Group
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="ps-modal-footer">
            <button type="button" class="ps-btn ps-btn-outline" onclick="PS.closeModal('ps-product-modal')">Cancel</button>
            <button type="button" class="ps-btn ps-btn-primary" onclick="PS.saveProduct()">
                <i class="fas fa-save"></i>
                <span id="ps-save-btn-text">Save Product</span>
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================
     Settings Modal
     ======================================================================== -->
<div id="ps-settings-modal" class="ps-modal">
    <div class="ps-modal-content ps-glass-modal" style="width: min(1000px, 92vw); height: min(94vh, 1400px); background: #14162a; backdrop-filter: none; -webkit-backdrop-filter: none;">
        <div class="ps-modal-header">
            <h3><i class="fas fa-cogs"></i> Store Settings</h3>
            <div class="ps-flex ps-items-center ps-gap-2">
                <span id="ps-unsaved-badge" class="ps-badge ps-badge-secondary" style="display: none;">Unsaved Changes</span>
                <button onclick="PS.closeModal('ps-settings-modal')" class="ps-btn ps-btn-outline ps-btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div class="ps-modal-body">
            <div class="ps-tabs">
                <div class="ps-tab-list">
                    <button class="ps-tab-trigger active" onclick="PS.showTab('general')" data-tab="general">General</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('shipping')" data-tab="shipping">Shipping</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('seller')" data-tab="seller">Seller</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('payments')" data-tab="payments">Payments</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('merchant')" data-tab="merchant">Merchant</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('messages')" data-tab="messages">Messages</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('features')" data-tab="features">Features</button>
                    <button class="ps-tab-trigger" onclick="PS.showTab('advanced')" data-tab="advanced">Advanced</button>
                </div>

                <!-- General Tab -->
                <div class="ps-tab-content active" id="ps-tab-general">
                    <div class="ps-settings-section">
                        <h4>Store Information</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-storeName">Store Name</label>
                                <input type="text" id="ps-storeName" value="<?= htmlspecialchars($settings['storeName']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-currency">Currency</label>
                                <select id="ps-currency" class="ps-form-input" onchange="PS.markUnsaved()">
                                    <option value="USD" <?= ($settings['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                    <option value="EUR" <?= ($settings['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                    <option value="GBP" <?= ($settings['currency'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                                    <option value="CAD" <?= ($settings['currency'] ?? '') === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar</option>
                                    <option value="AUD" <?= ($settings['currency'] ?? '') === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar</option>
                                </select>
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-storeDescription">Store Description</label>
                            <textarea id="ps-storeDescription" rows="3" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['storeDescription']) ?></textarea>
                        </div>
                    </div>
                    <div class="ps-settings-section">
                        <h4>Storefront Display</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-grid_columns">Columns</label>
                                <input type="range" id="ps-grid_columns" min="2" max="6" step="1" value="<?= intval($settings['grid_columns'] ?? 4) ?>" class="ps-form-input" oninput="document.getElementById('ps-grid_columns_val').textContent=this.value;PS.markUnsaved()" style="padding:4px 0">
                                <small class="ps-text-muted">Product grid columns: <strong id="ps-grid_columns_val"><?= intval($settings['grid_columns'] ?? 4) ?></strong></small>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-per_page">Products Per Page</label>
                                <input type="number" id="ps-per_page" min="0" max="100" value="<?= intval($settings['per_page'] ?? 0) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                                <small class="ps-text-muted">0 = show all (no pagination)</small>
                            </div>
                        </div>
                    </div>
                    <div class="ps-settings-section">
                        <h4>Pricing & Shipping</h4>
                        <div class="ps-form-row-3">
                            <div class="ps-form-group">
                                <label for="ps-taxRate">Tax Rate (%)</label>
                                <input type="number" id="ps-taxRate" step="0.01" min="0" max="100" value="<?= $settings['taxRate'] ?? '' ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                        <small class="ps-text-muted">Shipping rates are configured in the <strong>Shipping</strong> tab.</small>
                    </div>
                </div>

<?php
                // ── Shipping tab: resolve current config (with legacy fallback) ──
                $shipCfg      = (isset($settings['shipping']) && is_array($settings['shipping'])) ? $settings['shipping'] : [];
                $shipMode     = $shipCfg['mode'] ?? 'flat';
                $shipFlat     = $shipCfg['flatRate'] ?? ($settings['shippingCost'] ?? '');
                $shipTiers    = (isset($shipCfg['tiers']) && is_array($shipCfg['tiers'])) ? $shipCfg['tiers'] : [];
                $shipAboveMax = $shipCfg['aboveMax'] ?? 'cap';
                $shipPerAdd   = $shipCfg['perAdditional'] ?? '2.00';
                $shipFree     = (isset($shipCfg['freeShipping']) && is_array($shipCfg['freeShipping'])) ? $shipCfg['freeShipping'] : [];
                $freeEnabled  = !empty($shipFree['enabled']);
                $freeType     = $shipFree['type'] ?? 'threshold';
                $freeThresh   = $shipFree['threshold'] ?? ($settings['freeShippingThreshold'] ?? '');
                if (empty($shipTiers)) { $shipTiers = [['qty' => '', 'rate' => '']]; } // one blank starter row
?>
                <!-- Shipping Tab -->
                <div class="ps-tab-content" id="ps-tab-shipping">
                    <div class="ps-settings-section">
                        <h4>Shipping Method</h4>
                        <div class="ps-form-group">
                            <label for="ps-shippingMode">How is shipping charged?</label>
                            <select id="ps-shippingMode" class="ps-form-input" onchange="PS.markUnsaved();PS.shippingModeChanged()">
                                <option value="flat" <?= $shipMode === 'flat' ? 'selected' : '' ?>>Flat rate — one price per order</option>
                                <option value="per_quantity" <?= $shipMode === 'per_quantity' ? 'selected' : '' ?>>Per quantity — price by number of items</option>
                            </select>
                        </div>
                        <div class="ps-form-group" id="ps-ship-flat-wrap" style="<?= $shipMode === 'per_quantity' ? 'display:none' : '' ?>">
                            <label for="ps-shippingFlatRate">Flat shipping rate ($)</label>
                            <input type="number" id="ps-shippingFlatRate" step="0.01" min="0" value="<?= htmlspecialchars((string)$shipFlat) ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="5.99">
                        </div>
                    </div>

                    <div class="ps-settings-section" id="ps-ship-tiers-wrap" style="<?= $shipMode === 'per_quantity' ? '' : 'display:none' ?>">
                        <h4>Per-Quantity Rates</h4>
                        <small class="ps-text-muted">Add a rate for each item count. An order is charged the rate for the highest item-count that is less than or equal to the number of items in the cart.</small>
                        <table class="ps-ship-tier-table" style="width:100%;margin-top:10px;border-collapse:collapse">
                            <thead>
                                <tr>
                                    <th style="text-align:left;font-size:0.7rem;color:#888;text-transform:uppercase;padding:4px 6px">Items</th>
                                    <th style="text-align:left;font-size:0.7rem;color:#888;text-transform:uppercase;padding:4px 6px">Shipping Rate ($)</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="ps-ship-tier-rows">
<?php foreach ($shipTiers as $t): ?>
                                <tr class="ps-ship-tier-row">
                                    <td style="padding:4px 6px"><input type="number" min="1" step="1" class="ps-tier-qty ps-form-input" value="<?= htmlspecialchars((string)($t['qty'] ?? '')) ?>" onchange="PS.markUnsaved()" placeholder="1"></td>
                                    <td style="padding:4px 6px"><input type="number" min="0" step="0.01" class="ps-tier-rate ps-form-input" value="<?= htmlspecialchars((string)($t['rate'] ?? '')) ?>" onchange="PS.markUnsaved()" placeholder="6.95"></td>
                                    <td style="padding:4px 6px;text-align:center"><button type="button" class="ps-btn-icon" title="Remove" onclick="PS.removeShippingTier(this)" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:1rem">&times;</button></td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="ps-btn ps-btn-secondary" onclick="PS.addShippingTier()" style="margin-top:8px">+ Add Tier</button>

                        <div class="ps-form-group" style="margin-top:16px">
                            <label for="ps-shippingAboveMax">Orders larger than the highest tier</label>
                            <select id="ps-shippingAboveMax" class="ps-form-input" onchange="PS.markUnsaved();PS.shippingAboveMaxChanged()">
                                <option value="cap" <?= $shipAboveMax === 'cap' ? 'selected' : '' ?>>Charge the highest tier's rate (cap)</option>
                                <option value="increment" <?= $shipAboveMax === 'increment' ? 'selected' : '' ?>>Add a per-item amount for each extra item</option>
                            </select>
                        </div>
                        <div class="ps-form-group" id="ps-ship-peradd-wrap" style="<?= $shipAboveMax === 'increment' ? '' : 'display:none' ?>">
                            <label for="ps-shippingPerAdditional">Each additional item beyond the top tier ($)</label>
                            <input type="number" id="ps-shippingPerAdditional" step="0.01" min="0" value="<?= htmlspecialchars((string)$shipPerAdd) ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="2.00">
                        </div>
                    </div>

                    <div class="ps-settings-section">
                        <h4>Free Shipping Rule</h4>
                        <div class="ps-form-group">
                            <label class="ps-checkbox-label"><input type="checkbox" id="ps-shippingFreeEnabled" <?= $freeEnabled ? 'checked' : '' ?> onchange="PS.markUnsaved();PS.shippingFreeChanged()"> Enable free shipping</label>
                        </div>
                        <div id="ps-ship-free-wrap" style="<?= $freeEnabled ? '' : 'display:none' ?>">
                            <div class="ps-form-group">
                                <label for="ps-shippingFreeType">When?</label>
                                <select id="ps-shippingFreeType" class="ps-form-input" onchange="PS.markUnsaved();PS.shippingFreeChanged()">
                                    <option value="threshold" <?= $freeType === 'threshold' ? 'selected' : '' ?>>When the order subtotal reaches a threshold</option>
                                    <option value="always" <?= $freeType === 'always' ? 'selected' : '' ?>>Always (every order ships free)</option>
                                </select>
                            </div>
                            <div class="ps-form-group" id="ps-ship-freethresh-wrap" style="<?= $freeType === 'threshold' ? '' : 'display:none' ?>">
                                <label for="ps-shippingFreeThreshold">Free when subtotal is at least ($)</label>
                                <input type="number" id="ps-shippingFreeThreshold" step="0.01" min="0" value="<?= htmlspecialchars((string)$freeThresh) ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="75.00">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seller Tab -->
                <div class="ps-tab-content" id="ps-tab-seller">
                    <div class="ps-settings-section">
                        <h4>Business Information</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-businessName">Business Name *</label>
                                <input type="text" id="ps-businessName" value="<?= htmlspecialchars($settings['businessName']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-businessEmail">Business Email *</label>
                                <input type="email" id="ps-businessEmail" value="<?= htmlspecialchars($settings['businessEmail']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-businessAddress">Business Address</label>
                            <input type="text" id="ps-businessAddress" value="<?= htmlspecialchars($settings['businessAddress']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                        </div>
                        <div class="ps-form-row-3">
                            <div class="ps-form-group">
                                <label for="ps-businessCity">City</label>
                                <input type="text" id="ps-businessCity" value="<?= htmlspecialchars($settings['businessCity']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-businessState">State/Province</label>
                                <input type="text" id="ps-businessState" value="<?= htmlspecialchars($settings['businessState']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-businessZip">ZIP/Postal Code</label>
                                <input type="text" id="ps-businessZip" value="<?= htmlspecialchars($settings['businessZip']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-businessCountry">Country</label>
                                <select id="ps-businessCountry" class="ps-form-input" onchange="PS.markUnsaved()">
                                    <option value="United States" <?= ($settings['businessCountry'] ?? '') === 'United States' ? 'selected' : '' ?>>United States</option>
                                    <option value="Canada" <?= ($settings['businessCountry'] ?? '') === 'Canada' ? 'selected' : '' ?>>Canada</option>
                                    <option value="United Kingdom" <?= ($settings['businessCountry'] ?? '') === 'United Kingdom' ? 'selected' : '' ?>>United Kingdom</option>
                                    <option value="Australia" <?= ($settings['businessCountry'] ?? '') === 'Australia' ? 'selected' : '' ?>>Australia</option>
                                    <option value="Germany" <?= ($settings['businessCountry'] ?? '') === 'Germany' ? 'selected' : '' ?>>Germany</option>
                                    <option value="France" <?= ($settings['businessCountry'] ?? '') === 'France' ? 'selected' : '' ?>>France</option>
                                    <option value="Other" <?= ($settings['businessCountry'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-businessPhone">Business Phone</label>
                                <input type="tel" id="ps-businessPhone" value="<?= htmlspecialchars($settings['businessPhone']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-businessTaxId">Tax ID (Optional)</label>
                                <input type="text" id="ps-businessTaxId" value="<?= htmlspecialchars($settings['businessTaxId']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-businessWebsite">Business Website</label>
                                <input type="url" id="ps-businessWebsite" value="<?= htmlspecialchars($settings['businessWebsite']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payments Tab -->
                <div class="ps-tab-content" id="ps-tab-payments">
                    <div class="ps-settings-section">
                        <h4><i class="fab fa-paypal" style="color:#0070ba"></i> PayPal</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-paypal_client_id">Client ID</label>
                                <input type="text" id="ps-paypal_client_id" value="<?= htmlspecialchars($settings['paypal_client_id'] ?? '') ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="AYmx...">
                                <small class="ps-text-muted">From PayPal Developer Dashboard → Apps</small>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-paypal_secret">Secret Key</label>
                                <input type="password" id="ps-paypal_secret" value="<?= htmlspecialchars($settings['paypal_secret'] ?? '') ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="EJ...">
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-paypal_mode">Mode</label>
                            <select id="ps-paypal_mode" class="ps-form-input" onchange="PS.markUnsaved()">
                                <option value="sandbox" <?= ($settings['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Testing)</option>
                                <option value="live" <?= ($settings['paypal_mode'] ?? 'sandbox') === 'live' ? 'selected' : '' ?>>Live (Production)</option>
                            </select>
                        </div>
                    </div>
                    <div class="ps-settings-section">
                        <h4><i class="fab fa-stripe" style="color:#635bff"></i> Stripe</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-stripe_public_key">Publishable Key</label>
                                <input type="text" id="ps-stripe_public_key" value="<?= htmlspecialchars($settings['stripe_public_key'] ?? '') ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="pk_test_...">
                                <small class="ps-text-muted">From Stripe Dashboard → Developers → API keys</small>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-stripe_secret_key">Secret Key</label>
                                <input type="password" id="ps-stripe_secret_key" value="<?= htmlspecialchars($settings['stripe_secret_key'] ?? '') ?>" class="ps-form-input" onchange="PS.markUnsaved()" placeholder="sk_test_...">
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-stripe_mode">Mode</label>
                            <select id="ps-stripe_mode" class="ps-form-input" onchange="PS.markUnsaved()">
                                <option value="test" <?= ($settings['stripe_mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test Mode</option>
                                <option value="live" <?= ($settings['stripe_mode'] ?? 'test') === 'live' ? 'selected' : '' ?>>Live Mode</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Merchant Tab -->
                <div class="ps-tab-content" id="ps-tab-merchant">
                    <div class="ps-settings-section">
                        <h4>Merchant Account Settings</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-merchantId">Merchant ID</label>
                                <input type="text" id="ps-merchantId" value="<?= htmlspecialchars($settings['merchantId']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                                <small class="ps-text-muted">Find this in your payment provider account</small>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-merchantAccountType">Account Type</label>
                                <select id="ps-merchantAccountType" class="ps-form-input" onchange="PS.markUnsaved()">
                                    <option value="business" <?= ($settings['merchantAccountType'] ?? '') === 'business' ? 'selected' : '' ?>>Business</option>
                                    <option value="personal" <?= ($settings['merchantAccountType'] ?? '') === 'personal' ? 'selected' : '' ?>>Personal</option>
                                    <option value="nonprofit" <?= ($settings['merchantAccountType'] ?? '') === 'nonprofit' ? 'selected' : '' ?>>Non-Profit</option>
                                </select>
                            </div>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-merchantStatus">Merchant Status</label>
                                <select id="ps-merchantStatus" class="ps-form-input" onchange="PS.markUnsaved()">
                                    <option value="active" <?= ($settings['merchantStatus'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="pending" <?= ($settings['merchantStatus'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
                                    <option value="limited" <?= ($settings['merchantStatus'] ?? '') === 'limited' ? 'selected' : '' ?>>Limited</option>
                                    <option value="suspended" <?= ($settings['merchantStatus'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-processingFees">Processing Fees (%)</label>
                                <input type="number" id="ps-processingFees" step="0.1" min="0" max="10" value="<?= $settings['processingFees'] ?? '' ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                                <small class="ps-text-muted">Payment processing fee percentage</small>
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-refundPolicy">Refund Policy</label>
                            <textarea id="ps-refundPolicy" rows="3" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['refundPolicy']) ?></textarea>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-returnPolicyDays">Return Policy (Days)</label>
                                <input type="number" id="ps-returnPolicyDays" min="0" max="365" value="<?= $settings['returnPolicyDays'] ?? '' ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-disputeHandling">Dispute Handling</label>
                                <select id="ps-disputeHandling" class="ps-form-input" onchange="PS.markUnsaved()">
                                    <option value="auto" <?= ($settings['disputeHandling'] ?? '') === 'auto' ? 'selected' : '' ?>>Automatic</option>
                                    <option value="manual" <?= ($settings['disputeHandling'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual Review</option>
                                    <option value="escalate" <?= ($settings['disputeHandling'] ?? '') === 'escalate' ? 'selected' : '' ?>>Always Escalate</option>
                                </select>
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label class="ps-checkbox-label">
                                <input type="checkbox" id="ps-fraudProtection" <?= $settings['fraudProtection'] ? 'checked' : '' ?> onchange="PS.markUnsaved()">
                                <span>Enable Fraud Protection</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Messages Tab -->
                <div class="ps-tab-content" id="ps-tab-messages">
                    <div class="ps-settings-section">
                        <h4>Customer Messages</h4>
                        <div class="ps-form-group">
                            <label for="ps-thankYouMessage">Thank You Message</label>
                            <textarea id="ps-thankYouMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['thankYouMessage']) ?></textarea>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-orderPlacedMessage">Order Placed Message</label>
                            <textarea id="ps-orderPlacedMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['orderPlacedMessage']) ?></textarea>
                            <small class="ps-text-muted">Use {{ORDER_ID}} for dynamic order number</small>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-paymentSuccessMessage">Payment Success</label>
                                <textarea id="ps-paymentSuccessMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['paymentSuccessMessage']) ?></textarea>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-paymentFailedMessage">Payment Failed</label>
                                <textarea id="ps-paymentFailedMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['paymentFailedMessage']) ?></textarea>
                            </div>
                        </div>
                        <div class="ps-form-group">
                            <label for="ps-checkoutWelcomeMessage">Checkout Welcome Message</label>
                            <textarea id="ps-checkoutWelcomeMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['checkoutWelcomeMessage']) ?></textarea>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-cartEmptyMessage">Cart Empty Message</label>
                                <textarea id="ps-cartEmptyMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['cartEmptyMessage']) ?></textarea>
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-outOfStockMessage">Out of Stock Message</label>
                                <textarea id="ps-outOfStockMessage" rows="2" class="ps-form-input" onchange="PS.markUnsaved()"><?= htmlspecialchars($settings['outOfStockMessage']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Features Tab -->
                <div class="ps-tab-content" id="ps-tab-features">
                    <div class="ps-settings-section">
                        <h4>Store Features</h4>
                        <div class="ps-grid ps-grid-cols-2 ps-gap-4">
                            <div class="ps-form-group">
                                <label class="ps-checkbox-label">
                                    <input type="checkbox" id="ps-enableInventoryTracking" <?= $settings['enableInventoryTracking'] ? 'checked' : '' ?> onchange="PS.markUnsaved()">
                                    <span>Inventory Tracking</span>
                                </label>
                            </div>
                            <div class="ps-form-group">
                                <label class="ps-checkbox-label">
                                    <input type="checkbox" id="ps-enableReviews" <?= $settings['enableReviews'] ? 'checked' : '' ?> onchange="PS.markUnsaved()">
                                    <span>Product Reviews</span>
                                </label>
                            </div>
                            <div class="ps-form-group">
                                <label class="ps-checkbox-label">
                                    <input type="checkbox" id="ps-enableWishlist" <?= $settings['enableWishlist'] ? 'checked' : '' ?> onchange="PS.markUnsaved()">
                                    <span>Wishlist</span>
                                </label>
                            </div>
                            <div class="ps-form-group">
                                <label class="ps-checkbox-label">
                                    <input type="checkbox" id="ps-emailNotifications" <?= $settings['emailNotifications'] ? 'checked' : '' ?> onchange="PS.markUnsaved()">
                                    <span>Email Notifications</span>
                                </label>
                            </div>
                        </div>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-orderPrefix">Order Prefix</label>
                                <input type="text" id="ps-orderPrefix" value="<?= htmlspecialchars($settings['orderPrefix']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-defaultCategory">Default Category</label>
                                <input type="text" id="ps-defaultCategory" value="<?= htmlspecialchars($settings['defaultCategory']) ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div class="ps-tab-content" id="ps-tab-advanced">
                    <div class="ps-settings-section">
                        <h4>Advanced Settings</h4>
                        <div class="ps-form-row">
                            <div class="ps-form-group">
                                <label for="ps-imageQuality">Image Quality (%): <span id="ps-quality-value"><?= $settings['imageQuality'] ?></span></label>
                                <input type="range" id="ps-imageQuality" min="10" max="100" step="10" value="<?= $settings['imageQuality'] ?? '' ?>" oninput="document.getElementById('ps-quality-value').textContent=this.value; PS.markUnsaved();" style="width: 100%;">
                            </div>
                            <div class="ps-form-group">
                                <label for="ps-maxFileSize">Max File Size (MB)</label>
                                <input type="number" id="ps-maxFileSize" min="1" max="50" value="<?= $settings['maxFileSize'] ?? '' ?>" class="ps-form-input" onchange="PS.markUnsaved()">
                            </div>
                        </div>
                        <div class="ps-flex ps-gap-4 ps-mt-4">
                            <button type="button" onclick="PS.exportSettings()" class="ps-btn ps-btn-outline">
                                <i class="fas fa-download"></i> Export Settings
                            </button>
                            <button type="button" onclick="document.getElementById('ps-import-file').click()" class="ps-btn ps-btn-outline">
                                <i class="fas fa-upload"></i> Import Settings
                            </button>
                            <input type="file" id="ps-import-file" accept=".json" style="display: none;" onchange="PS.importSettings(this)">
                            <button type="button" onclick="PS.resetAllSettings()" class="ps-btn ps-btn-destructive">
                                <i class="fas fa-trash"></i> Reset All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ps-modal-footer">
            <div class="ps-flex ps-gap-2">
                <button type="button" onclick="PS.exportSettings()" class="ps-btn ps-btn-outline ps-btn-sm">
                    <i class="fas fa-download"></i> Export
                </button>
                <button type="button" onclick="PS.resetAllSettings()" class="ps-btn ps-btn-destructive ps-btn-sm">
                    <i class="fas fa-trash"></i> Reset
                </button>
            </div>
            <div class="ps-flex ps-gap-2">
                <button type="button" onclick="PS.closeModal('ps-settings-modal')" class="ps-btn ps-btn-outline">Cancel</button>
                <button type="button" onclick="PS.saveSettings()" class="ps-btn ps-btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Background Customizer — saves to store settings -->
<div id="ps-bg-customizer" class="ps-bg-customizer ps-glass-card ps-hidden">
    <div class="ps-p-4">
        <div class="ps-flex ps-items-center ps-justify-between ps-mb-4">
            <h3 class="ps-font-bold ps-text-primary">
                <i class="fas fa-palette"></i> Storefront Theme
            </h3>
            <button onclick="PS.toggleBgCustomizer()" class="ps-btn ps-btn-outline ps-btn-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="ps-mb-4">
            <label class="ps-block ps-mb-2 ps-text-sm ps-font-bold">Card Background</label>
            <input type="color" id="ps-store_card_bg" value="<?= htmlspecialchars($settings['store_card_bg'] ?? '#1a1a2e') ?>" style="width:100%;height:32px;cursor:pointer;" onchange="PS.markUnsaved()">
        </div>
        <div class="ps-mb-4">
            <label class="ps-block ps-mb-2 ps-text-sm ps-font-bold">Page Background</label>
            <input type="color" id="ps-store_page_bg" value="<?= htmlspecialchars($settings['store_page_bg'] ?? '#0f1114') ?>" style="width:100%;height:32px;cursor:pointer;" onchange="PS.markUnsaved()">
        </div>
        <div class="ps-mb-4">
            <label class="ps-block ps-mb-2 ps-text-sm ps-font-bold">Accent Color</label>
            <input type="color" id="ps-store_accent" value="<?= htmlspecialchars($settings['store_accent'] ?? '#f59e0b') ?>" style="width:100%;height:32px;cursor:pointer;" onchange="PS.markUnsaved()">
        </div>
        <p class="ps-text-muted" style="font-size:0.72rem;margin-top:8px">Changes save with Settings &rarr; Save</p>
    </div>
</div>

<script>
/* Settings modal scroll affordance: "⌄ more below" pill + edge glow when a tab continues below */
(function(){
  var m=document.getElementById('ps-settings-modal'); if(!m) return;
  var content=m.querySelector('.ps-modal-content'), body=m.querySelector('.ps-modal-body'), footer=m.querySelector('.ps-modal-footer');
  if(!content||!body) return;
  var el=content.querySelector('.ps-scroll-more');
  if(!el){ el=document.createElement('div'); el.className='ps-scroll-more'; el.setAttribute('aria-hidden','true'); el.innerHTML='<span>&#9662; more below</span>'; content.appendChild(el); }
  function upd(){
    if(footer) content.style.setProperty('--ps-footer-h', footer.offsetHeight+'px');
    el.classList.toggle('ps-show', (body.scrollHeight - body.scrollTop - body.clientHeight) > 10);
  }
  body.addEventListener('scroll', upd, {passive:true});
  m.addEventListener('click', function(e){ if(e.target.closest && e.target.closest('.ps-tab-trigger')) setTimeout(upd,40); });
  try{ new MutationObserver(function(){ setTimeout(upd,60); }).observe(m,{attributes:true,attributeFilter:['class','style']}); }catch(e){}
  window.addEventListener('resize', upd);
  setTimeout(upd,250);
})();
</script>
