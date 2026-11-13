/**
 * Printful Admin JavaScript
 * 
 * File: SITE_ROOT/admin/printful-mgr/assets/js/printful-admin.js
 * Version: 2025.11.09.1700
 * 
 * Handles all admin interface interactions
 * Tab switching, AJAX requests, form handling
 */

(function() {
    'use strict';
    
    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        initForms();
        checkAutoLoad();
    });
    
    // ===== TAB SYSTEM =====
    function initTabs() {
        const tabButtons = document.querySelectorAll('.tab-btn');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                switchTab(tabName);
            });
        });
    }
    
    function switchTab(tabName) {
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
        
        // Update content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`${tabName}-tab`).classList.add('active');
    }
    
    // ===== FORM HANDLERS =====
    function initForms() {
        // API Key Form
        const apiKeyForm = document.getElementById('api-key-form');
        if (apiKeyForm) {
            apiKeyForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveApiKey();
            });
        }
        
        // Settings Form
        const settingsForm = document.getElementById('settings-form');
        if (settingsForm) {
            settingsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveSettings();
            });
        }
    }
    
    // ===== API KEY MANAGEMENT =====
    window.testConnection = function() {
        const apiKey = document.getElementById('api-key').value;
        
        if (!apiKey) {
            showToast('Please enter an API key', 'error');
            return;
        }
        
        showToast('Testing connection...', 'info');
        
        const formData = new FormData();
        formData.append('api_key', apiKey);
        
        fetch('?action=test_connection', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            const statusDiv = document.getElementById('connection-status');
            if (result.success) {
                statusDiv.innerHTML = '<div class="alert alert-success">✓ Connection successful!</div>';
                showToast('Connection successful!', 'success');
            } else {
                statusDiv.innerHTML = `<div class="alert alert-error">✗ ${result.message}</div>`;
                showToast('Connection failed', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Connection test failed', 'error');
        });
    };
    
    function saveApiKey() {
        const apiKey = document.getElementById('api-key').value;
        
        if (!apiKey) {
            showToast('Please enter an API key', 'error');
            return;
        }
        
        showToast('Saving API key...', 'info');
        
        const formData = new FormData();
        formData.append('api_key', apiKey);
        
        fetch('?action=save_api_key', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast('✓ API key saved successfully', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('✗ ' + result.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to save API key', 'error');
        });
    }
    
    // ===== PRODUCT MANAGEMENT =====
    window.syncProducts = function() {
        showToast('Syncing products...', 'info');
        
        fetch('?action=sync_products')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast(`✓ ${result.product_count} products synced`, 'success');
                    loadProducts();
                } else {
                    showToast('✗ ' + result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Sync failed', 'error');
            });
    };
    
    window.loadProducts = function() {
        fetch('?action=get_products')
            .then(response => response.json())
            .then(result => {
                const productsDiv = document.getElementById('products-list');
                
                if (result.success && result.products.length > 0) {
                    let html = '';
                    result.products.forEach(product => {
                        const thumbnail = product.thumbnail_url || 'https://via.placeholder.com/200';
                        const price = product.retail_price || 'N/A';
                        html += `
                            <div class="product-card">
                                <img src="${thumbnail}" alt="${product.name}">
                                <h3>${product.name}</h3>
                                <p class="price">$${price}</p>
                            </div>
                        `;
                    });
                    productsDiv.innerHTML = html;
                    showToast('Products loaded', 'success');
                } else {
                    productsDiv.innerHTML = '<p class="text-muted">No products found. Please sync first.</p>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to load products', 'error');
            });
    };
    
    // ===== ORDER MANAGEMENT =====
    window.loadOrders = function() {
        showToast('Loading orders...', 'info');
        
        fetch('?action=get_orders')
            .then(response => response.json())
            .then(result => {
                const ordersDiv = document.getElementById('orders-list');
                
                if (result.success && result.orders.length > 0) {
                    let html = '';
                    result.orders.forEach(order => {
                        const statusClass = order.status.toLowerCase().replace(' ', '-');
                        html += `
                            <div class="order-card">
                                <div class="order-header">
                                    <span class="order-id">Order #${order.id}</span>
                                    <span class="order-status ${statusClass}">${order.status}</span>
                                </div>
                                <p><strong>Date:</strong> ${order.created}</p>
                                <p><strong>Total:</strong> $${order.retail_costs.total}</p>
                                <p><strong>Recipient:</strong> ${order.recipient.name}</p>
                            </div>
                        `;
                    });
                    ordersDiv.innerHTML = html;
                    showToast(`${result.orders.length} orders loaded`, 'success');
                } else {
                    ordersDiv.innerHTML = '<p class="text-muted">No orders found.</p>';
                    showToast('No orders found', 'info');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to load orders', 'error');
            });
    };
    
    // ===== SETTINGS =====
    function saveSettings() {
        showToast('Saving settings...', 'info');
        
        const formData = new FormData(document.getElementById('settings-form'));
        formData.append('action', 'update_settings');
        
        fetch('?action=update_settings', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast('✓ Settings saved', 'success');
            } else {
                showToast('✗ Failed to save settings', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to save settings', 'error');
        });
    }
    
    // ===== CACHE MANAGEMENT =====
    window.clearCache = function() {
        if (!confirm('Are you sure you want to clear the product cache?')) {
            return;
        }
        
        showToast('Clearing cache...', 'info');
        
        fetch('?action=clear_cache')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast('✓ Cache cleared', 'success');
                    document.getElementById('products-list').innerHTML = '<p class="text-muted">Cache cleared. Please sync products again.</p>';
                } else {
                    showToast('✗ Failed to clear cache', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to clear cache', 'error');
            });
    };
    
    // ===== AUTO-LOAD =====
    function checkAutoLoad() {
        // Auto-load products if on products tab
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'products') {
            switchTab('products');
            loadProducts();
        }
    }
    
    // ===== TOAST NOTIFICATIONS =====
    function showToast(message, type = 'info') {
        // Remove existing toasts
        document.querySelectorAll('.toast-notification').forEach(toast => toast.remove());
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        
        const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ';
        
        toast.innerHTML = `
            <span class="toast-icon">${icon}</span>
            <span class="toast-message">${message}</span>
        `;
        
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);
        
        // Auto-remove
        const delay = type === 'error' ? 5000 : 3000;
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, delay);
    }
    
})();
