/**
 * Printful Manager JavaScript - COMPLETE VERSION
 * 
 * File: SITE_ROOT/admin/printful-mgr/assets/js/printful-manager.js
 * Version: 2025.11.09.1730
 * 
 * Features:
 * - Toast notifications everywhere
 * - Store detection and selection
 * - Complete product sync
 * - Order management
 * - Real-time feedback
 */

(function() {
    'use strict';
    
    let currentTab = 'config';
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        initConfigForm();
        initProducts();
        initOrders();
        initSync();
        loadSyncStatus();
    });
    
    // ===== TAB NAVIGATION =====
    function initTabs() {
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                switchTab(this.dataset.tab);
            });
        });
    }
    
    function switchTab(tabName) {
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
        
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById(`${tabName}Tab`).classList.add('active');
        
        currentTab = tabName;
    }
    
    // ===== CONFIGURATION =====
    function initConfigForm() {
        const form = document.getElementById('configForm');
        const testBtn = document.getElementById('testConnection');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            saveConfig();
        });

        testBtn.addEventListener('click', testConnection);

        // Growth & Referrals form — merges into same save_config action
        const growthForm = document.getElementById('growthForm');
        if (growthForm) {
            growthForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveGrowthConfig();
            });
        }
    }

    function saveGrowthConfig() {
        // Pull current API config fields plus growth fields into one payload
        const mainForm = new FormData(document.getElementById('configForm'));
        const growthForm = new FormData(document.getElementById('growthForm'));

        // Merge growth fields into the main payload
        for (const [key, value] of growthForm.entries()) {
            mainForm.set(key, value);
        }
        mainForm.set('action', 'save_config');

        pfToast('💾 Saving growth settings...');

        fetch('', { method: 'POST', body: mainForm })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    pfToast('✓ Growth settings saved!');
                } else {
                    pfToast('✗ Failed to save');
                }
            })
            .catch(() => pfToast('✗ Save error'));
    }

    // Lightweight toast for growth form (doesn't exist in all versions)
    function pfToast(msg) {
        if (typeof showToast === 'function') { showToast(msg, 'success'); return; }
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e293b;color:#f1f5f9;padding:10px 18px;border-radius:8px;font-size:0.85rem;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.4)';
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2500);
    }
    
    function saveConfig() {
        const formData = new FormData(document.getElementById('configForm'));
        formData.append('action', 'save_config');
        
        showToast('💾 Saving configuration...', 'info');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('✓ Configuration saved!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('✗ Failed to save', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('✗ Save error', 'error');
        });
    }
    
    function testConnection() {
        const apiKey = document.getElementById('apiKey').value;
        
        if (!apiKey) {
            showToast('⚠️ Please enter an API key', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'test_connection');
        
        showToast('🔍 Testing connection...', 'info');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`✓ Connected! ${data.store_count} store(s) detected`, 'success');
                
                // If stores detected, reload to show store selector
                if (data.stores && data.stores.length > 0) {
                    setTimeout(() => location.reload(), 1500);
                }
            } else {
                showToast(`✗ Connection failed: ${data.message}`, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('✗ Connection error', 'error');
        });
    }
    
    // ===== PRODUCTS =====
    function initProducts() {
        const syncBtn = document.getElementById('syncProducts');
        const refreshBtn = document.getElementById('refreshProducts');
        
        if (syncBtn) syncBtn.addEventListener('click', syncProducts);
        if (refreshBtn) refreshBtn.addEventListener('click', loadProducts);
        
        loadProducts();
    }
    
    function syncProducts() {
        const formData = new FormData();
        formData.append('action', 'sync_products');
        
        showToast('🔄 Syncing products from Printful...', 'info');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(`✓ ${data.product_count} products synced!`, 'success');
                loadProducts();
                loadSyncStatus();
            } else {
                showToast(`✗ Sync failed: ${data.message}`, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('✗ Sync error', 'error');
        });
    }
    
    function loadProducts() {
        const formData = new FormData();
        formData.append('action', 'get_products');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('productsContainer');
            
            if (data.success && data.products && data.products.length > 0) {
                let html = '';
                data.products.forEach(product => {
                    const img = product.thumbnail_url || 'https://via.placeholder.com/150';
                    const name = (product.name || 'Unnamed Product').replace(/"/g, '&quot;');
                    const variants = (product.variants || []).length;
                    const pid = product.id;
                    const shortcode = `[[printful-product id="${pid}"]]`;

                    html += `
                        <div class="product-card">
                            <img src="${img}" alt="${name}" onerror="this.src='https://via.placeholder.com/150'">
                            <div class="product-name">${name}</div>
                            <div class="product-variants">${variants} variant(s)</div>
                            <div class="product-shortcode-row">
                                <code class="product-shortcode">${shortcode}</code>
                                <button class="copy-shortcode-btn" type="button" data-sc='${shortcode}' title="Copy shortcode">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
                showToast(`📦 ${data.products.length} products loaded`, 'success');
                // Wire up copy-shortcode buttons (delegated would be cleaner — bind once)
                if (!container._copyBound) {
                    container.addEventListener('click', (e) => {
                        const btn = e.target.closest('.copy-shortcode-btn');
                        if (!btn) return;
                        const sc = btn.dataset.sc;
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(sc).then(() => showToast('📋 Shortcode copied!', 'success'));
                        } else {
                            // Fallback
                            const ta = document.createElement('textarea');
                            ta.value = sc; document.body.appendChild(ta); ta.select();
                            try { document.execCommand('copy'); showToast('📋 Shortcode copied!', 'success'); }
                            catch(e) { showToast('Copy failed', 'error'); }
                            ta.remove();
                        }
                    });
                    container._copyBound = true;
                }
            } else {
                container.innerHTML = '<p class="loading-message">No products. Click "Sync Products" to load from Printful</p>';
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('productsContainer').innerHTML = '<p class="loading-message">Error loading products</p>';
        });
    }
    
    // ===== ORDERS =====
    function initOrders() {
        const refreshBtn = document.getElementById('refreshOrders');
        if (refreshBtn) refreshBtn.addEventListener('click', loadOrders);
    }
    
    function loadOrders() {
        const formData = new FormData();
        formData.append('action', 'get_orders');
        
        showToast('📋 Loading orders...', 'info');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('ordersContainer');
            
            if (data.success && data.orders && data.orders.length > 0) {
                let html = '';
                data.orders.forEach(order => {
                    const status = order.status || 'pending';
                    const total = order.costs ? order.costs.total : '0.00';
                    const date = order.created ? new Date(order.created * 1000).toLocaleDateString() : 'N/A';
                    
                    html += `
                        <div class="order-card">
                            <div class="order-header">
                                <div class="order-id">Order #${order.id}</div>
                                <div class="order-status ${status}">${status}</div>
                            </div>
                            <div class="order-details">
                                <div><strong>Total:</strong> $${total}</div>
                                <div><strong>Date:</strong> ${date}</div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
                showToast(`✓ ${data.orders.length} orders loaded`, 'success');
            } else {
                container.innerHTML = '<p class="loading-message">No orders found</p>';
            }
        })
        .catch(err => {
            console.error(err);
            showToast('✗ Error loading orders', 'error');
        });
    }
    
    // ===== SYNC STATUS =====
    function initSync() {
        const forceSyncBtn = document.getElementById('forceSyncNow');
        if (forceSyncBtn) forceSyncBtn.addEventListener('click', syncProducts);
    }
    
    function loadSyncStatus() {
        const formData = new FormData();
        formData.append('action', 'get_sync_status');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const lastSync = document.getElementById('lastSync');
                const productCount = document.getElementById('productCount');
                const activeStore = document.getElementById('activeStore');
                
                if (lastSync) lastSync.textContent = data.last_sync || 'Never';
                if (productCount) productCount.textContent = data.product_count || 0;
                if (activeStore) activeStore.textContent = data.store_id || 'None';
            }
        })
        .catch(err => console.error(err));
    }
    
    // ===== TOAST NOTIFICATIONS =====
    function showToast(message, type = 'info') {
        // Remove old toasts
        document.querySelectorAll('.toast').forEach(t => t.remove());
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // Add toast CSS dynamically
    const style = document.createElement('style');
    style.textContent = `
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            background: rgba(20, 24, 40, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.5);
            z-index: 10000;
            opacity: 0;
            transform: translateX(400px);
            transition: all 0.3s ease;
            max-width: 350px;
            font-weight: 500;
            color: #e0e0e0;
        }
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        .toast-success {
            border-left: 4px solid #2ecc71;
            color: #d5f5e3;
        }
        .toast-error {
            border-left: 4px solid #e74c3c;
            color: #fadbd8;
        }
        .toast-info {
            border-left: 4px solid #3498db;
            color: #d6eaf8;
        }
    `;
    document.head.appendChild(style);
    
})();
