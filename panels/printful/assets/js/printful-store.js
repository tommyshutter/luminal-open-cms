/**
 * Printful Store Panel - Public JavaScript
 * 
 * File: SITE_ROOT/panels/printful/assets/js/printful-store.js
 * Version: 2025.11.09.1600
 * 
 * Handles all client-side store interactions.
 * Product filtering, cart management, and modal display.
 */

(function() {
    'use strict';
    
    // ===== GLOBALS =====
    let cart = [];
    let products = window.PRINTFUL_PRODUCTS || [];
    
    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', function() {
        initCart();
        initSearch();
        initFilters();
        initProductCards();
        loadCartFromStorage();
    });
    
    // ===== CART MANAGEMENT =====
    function initCart() {
        const cartToggle = document.getElementById('cart-toggle');
        const cartClose = document.getElementById('cart-close');
        const floatingCart = document.getElementById('floating-cart');
        
        if (cartToggle) {
            cartToggle.addEventListener('click', function() {
                floatingCart.style.display = 'flex';
            });
        }
        
        if (cartClose) {
            cartClose.addEventListener('click', function() {
                floatingCart.style.display = 'none';
            });
        }
    }
    
    function addToCart(product, variant) {
        const cartItem = {
            productId: product.id,
            productName: product.name,
            variantId: variant ? variant.id : null,
            variantName: variant ? variant.name : null,
            price: variant ? variant.price : product.price || 0,
            image: product.thumbnail_url,
            quantity: 1
        };
        
        // Check if item already in cart
        const existingIndex = cart.findIndex(item => 
            item.productId === cartItem.productId && 
            item.variantId === cartItem.variantId
        );
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
        } else {
            cart.push(cartItem);
        }
        
        updateCartDisplay();
        saveCartToStorage();
        
        // Show success message
        showNotification('Added to cart!');
    }
    
    function removeFromCart(index) {
        cart.splice(index, 1);
        updateCartDisplay();
        saveCartToStorage();
    }
    
    function updateCartDisplay() {
        const cartItemsElem = document.getElementById('cart-items');
        const cartCountElem = document.querySelector('.cart-count');
        const cartTotalElem = document.getElementById('cart-total');
        
        // Update cart count
        const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
        if (cartCountElem) {
            cartCountElem.textContent = totalItems;
        }
        
        // Update cart items
        if (cart.length === 0) {
            cartItemsElem.innerHTML = '<p class="cart-empty">Your cart is empty</p>';
            if (cartTotalElem) {
                cartTotalElem.textContent = '$0.00';
            }
            return;
        }
        
        let html = '';
        let total = 0;
        
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            total += itemTotal;
            
            html += `
                <div class="cart-item">
                    <img src="${item.image}" alt="${item.productName}" class="cart-item-image">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${item.productName}</div>
                        ${item.variantName ? `<div class="cart-item-variant">${item.variantName}</div>` : ''}
                        <div class="cart-item-price">$${item.price.toFixed(2)} × ${item.quantity}</div>
                    </div>
                    <button class="cart-item-remove" onclick="window.printfulStore.removeFromCart(${index})">×</button>
                </div>
            `;
        });
        
        cartItemsElem.innerHTML = html;
        
        if (cartTotalElem) {
            cartTotalElem.textContent = '$' + total.toFixed(2);
        }
    }
    
    function saveCartToStorage() {
        try {
            localStorage.setItem('printful_cart', JSON.stringify(cart));
        } catch (e) {
            console.error('Error saving cart:', e);
        }
    }
    
    function loadCartFromStorage() {
        try {
            const savedCart = localStorage.getItem('printful_cart');
            if (savedCart) {
                cart = JSON.parse(savedCart);
                updateCartDisplay();
            }
        } catch (e) {
            console.error('Error loading cart:', e);
        }
    }
    
    // ===== PRODUCT DISPLAY =====
    function initProductCards() {
        const productCards = document.querySelectorAll('.store-product-card');
        const viewButtons = document.querySelectorAll('.btn-view-product');
        
        productCards.forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking the button
                if (e.target.classList.contains('btn-view-product')) {
                    return;
                }
                const productId = this.dataset.productId;
                showProductModal(productId);
            });
        });
        
        viewButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const productId = this.dataset.productId;
                showProductModal(productId);
            });
        });
    }
    
    function showProductModal(productId) {
        const product = products.find(p => p.id == productId);
        
        if (!product) {
            console.error('Product not found:', productId);
            return;
        }
        
        const modal = document.getElementById('product-modal');
        const modalBody = document.getElementById('modal-body');
        const modalOverlay = document.getElementById('modal-overlay');
        const modalClose = document.getElementById('modal-close');
        
        let variantsHtml = '';
        if (product.variants && product.variants.length > 0) {
            variantsHtml = `
                <div class="modal-variants">
                    <h3>Select Option:</h3>
                    ${product.variants.map((variant, index) => `
                        <div class="variant-option ${index === 0 ? 'selected' : ''}" data-variant-index="${index}">
                            <strong>${variant.name}</strong>
                            ${variant.price ? ` - $${variant.price}` : ''}
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        modalBody.innerHTML = `
            <div class="modal-product-grid">
                <div>
                    <img src="${product.thumbnail_url || 'https://via.placeholder.com/400'}" alt="${product.name}" class="modal-product-image">
                </div>
                <div class="modal-product-info">
                    <h2>${product.name}</h2>
                    <div class="modal-product-description">
                        ${product.description || 'No description available.'}
                    </div>
                    ${variantsHtml}
                    <button class="btn-add-to-cart" id="modal-add-to-cart">Add to Cart</button>
                </div>
            </div>
        `;
        
        modal.style.display = 'block';
        
        // Handle variant selection
        const variantOptions = modalBody.querySelectorAll('.variant-option');
        variantOptions.forEach(option => {
            option.addEventListener('click', function() {
                variantOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
            });
        });
        
        // Handle add to cart
        const addToCartBtn = document.getElementById('modal-add-to-cart');
        addToCartBtn.addEventListener('click', function() {
            const selectedVariantElem = modalBody.querySelector('.variant-option.selected');
            let variant = null;
            
            if (selectedVariantElem) {
                const variantIndex = selectedVariantElem.dataset.variantIndex;
                variant = product.variants[variantIndex];
            }
            
            addToCart(product, variant);
            modal.style.display = 'none';
        });
        
        // Handle modal close
        modalClose.addEventListener('click', function() {
            modal.style.display = 'none';
        });
        
        modalOverlay.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }
    
    // ===== SEARCH & FILTER =====
    function initSearch() {
        const searchInput = document.getElementById('store-search');
        
        if (searchInput) {
            searchInput.addEventListener('input', filterProducts);
        }
    }
    
    function initFilters() {
        const filterSelect = document.getElementById('store-category-filter');
        
        if (filterSelect) {
            filterSelect.addEventListener('change', filterProducts);
        }
    }
    
    function filterProducts() {
        const searchTerm = document.getElementById('store-search').value.toLowerCase();
        const category = document.getElementById('store-category-filter').value.toLowerCase();
        const productCards = document.querySelectorAll('.store-product-card');
        
        productCards.forEach(card => {
            const productName = card.querySelector('.product-name').textContent.toLowerCase();
            const matchesSearch = searchTerm === '' || productName.includes(searchTerm);
            const matchesCategory = category === '' || card.dataset.category === category;
            
            if (matchesSearch && matchesCategory) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // ===== NOTIFICATIONS =====
    function showNotification(message) {
        // Create notification element
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 3000;
            animation: slideIn 0.3s ease;
        `;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 2000);
    }
    
    // ===== CHECKOUT =====
    function initCheckout() {
        const checkoutBtn = document.getElementById('btn-checkout');
        
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', function() {
                if (cart.length === 0) {
                    showNotification('Your cart is empty!');
                    return;
                }
                
                // Here you would integrate with Printful's checkout API
                // For now, just show a message
                alert('Checkout functionality would connect to Printful API here.');
            });
        }
    }
    
    // Expose functions to global scope for inline event handlers
    window.printfulStore = {
        removeFromCart: removeFromCart,
        addToCart: addToCart
    };
    
})();
