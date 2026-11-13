/**
 * My Store — Admin JavaScript
 * Namespaced under window.PS to avoid global collisions inside the admin shell.
 * Extracted from inline <script> in index.php.
 */
(function() {
    'use strict';

    const boot = window.PS_BOOT || {};
    let products = boot.products || [];
    let currentView = boot.currentView || 'products';
    let isEditMode = false;
    let editingIndex = -1;
    let hasUnsavedChanges = false;
    let variationGroupCount = 0;
    const apiUrl = boot.apiUrl || '';

    // fetch wrapper that auto-injects the CSRF token on every POST to MyStore
    // endpoints. Server requires X-CSRF-Token on every state-changing action;
    // keeping this in one place means any new fetch call inherits the header
    // without each author having to remember.
    function psFetch(url, options) {
        options = options || {};
        var method = (options.method || 'GET').toUpperCase();
        if (method === 'POST' || method === 'PUT' || method === 'DELETE' || method === 'PATCH') {
            options.headers = options.headers || {};
            if (!options.headers['X-CSRF-Token'] && !options.headers['x-csrf-token']) {
                options.headers['X-CSRF-Token'] = (boot.csrfToken || '');
            }
        }
        return fetch(url, options);
    }
    // expose for any external callers
    if (typeof window !== 'undefined') window.psFetch = psFetch;

    // ========================================================================
    // Initialization
    // ========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Relocate modals to document.body to escape .content-area backdrop-filter stacking context
        ['ps-product-modal', 'ps-settings-modal'].forEach(function(id) {
            var modal = document.getElementById(id);
            if (modal && modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
        });

        PS.setView(currentView);
        PS.updateGridLayout();
        if (_allOrders.length || currentView === 'orders') renderOrders();

        const searchEl = document.getElementById('ps-product-search');
        if (searchEl) searchEl.addEventListener('input', PS.filterProducts);

        setupDragAndDrop();
        loadLayoutSettings();
    });

    // ========================================================================
    // View management
    // ========================================================================
    function setView(view) {
        currentView = view;

        document.querySelectorAll('.ps-nav .ps-btn').forEach(btn => {
            btn.classList.remove('ps-btn-primary');
            btn.classList.add('ps-btn-outline');
        });

        const idx = view === 'products' ? 0 : 1;
        const btns = document.querySelectorAll('.ps-nav .ps-btn');
        if (btns[idx]) {
            btns[idx].classList.remove('ps-btn-outline');
            btns[idx].classList.add('ps-btn-primary');
        }

        const pv = document.getElementById('ps-products-view');
        const ov = document.getElementById('ps-orders-view');
        if (pv) pv.classList.toggle('ps-hidden', view !== 'products');
        if (ov) ov.classList.toggle('ps-hidden', view !== 'orders');
        if (view === 'orders') renderOrders();

        const titles = {
            products: { title: 'Product Manager', description: 'Manage your store products' },
            orders:   { title: 'Order Management', description: 'View and manage customer orders' }
        };
        if (titles[view]) {
            const te = document.getElementById('ps-view-title');
            const de = document.getElementById('ps-view-description');
            if (te) te.textContent = titles[view].title;
            if (de) de.textContent = titles[view].description;
        }
    }

    // ========================================================================
    // Toast notifications
    // ========================================================================
    function showToast(message, type) {
        type = type || 'info';
        const toast = document.createElement('div');
        toast.className = 'ps-toast ps-toast-' + type;
        const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
        toast.innerHTML = '<div style="display:flex;align-items:center;gap:0.5rem;">' +
            '<i class="fas fa-' + (icons[type] || 'info-circle') + '"></i>' +
            '<span>' + message + '</span></div>';
        document.body.appendChild(toast);
        setTimeout(function() { toast.classList.add('show'); }, 100);
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() {
                if (document.body.contains(toast)) document.body.removeChild(toast);
            }, 300);
        }, 3000);
    }

    // ========================================================================
    // Modal functions
    // ========================================================================
    function showModal(modalId) {
        document.getElementById(modalId).classList.add('show');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    // ========================================================================
    // Product management
    // ========================================================================
    function showAddProductModal() {
        isEditMode = false;
        editingIndex = -1;
        document.getElementById('ps-modal-title').textContent = 'Add Product';
        document.getElementById('ps-save-btn-text').textContent = 'Add Product';
        document.getElementById('ps-product-form').reset();
        document.getElementById('ps-product-enabled').checked = true;
        document.getElementById('ps-product-sku').readOnly = false;
        document.getElementById('ps-product-image').value = '';
        document.getElementById('ps-variations-container').innerHTML = '';
        variationGroupCount = 0;
        // Clear image manager
        populateImageManager({image: '', images: []});
        showModal('ps-product-modal');
    }

    function editProduct(index) {
        const product = products[index];
        if (!product) { showToast('Product not found', 'error'); return; }

        isEditMode = true;
        editingIndex = index;
        document.getElementById('ps-modal-title').textContent = 'Edit Product';
        document.getElementById('ps-save-btn-text').textContent = 'Update Product';

        document.getElementById('ps-product-sku').value = product.sku;
        document.getElementById('ps-product-name').value = product.name;
        document.getElementById('ps-product-price').value = product.price;
        document.getElementById('ps-product-category').value = product.category || '';
        document.getElementById('ps-product-description').value = product.desc || '';
        document.getElementById('ps-product-image').value = product.image || '';
        document.getElementById('ps-product-enabled').checked = product.enabled !== false;
        document.getElementById('ps-product-sku').readOnly = true;

        // Populate image manager
        populateImageManager(product);

        loadVariations(product.variations && product.variations.groups ? product.variations.groups : []);
        showModal('ps-product-modal');
    }

    function saveProduct() {
        var form = document.getElementById('ps-product-form');
        var formData = new FormData(form);

        var sku = document.getElementById('ps-product-sku').value.trim();
        var name = document.getElementById('ps-product-name').value.trim();
        var price = document.getElementById('ps-product-price').value;

        if (!sku || !name || !price) {
            showToast('Please fill in all required fields', 'error');
            return;
        }

        var action = isEditMode ? 'edit_product' : 'add_product';
        formData.append('action', action);
        formData.append('enabled', document.getElementById('ps-product-enabled').checked ? 'true' : 'false');
        formData.append('variations', JSON.stringify(getVariationsData()));

        var saveBtn = document.getElementById('ps-save-btn-text');
        var originalText = saveBtn.textContent;
        saveBtn.innerHTML = '<i class="fas fa-spinner ps-loading-spinner"></i> Saving...';

        psFetch(apiUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                try {
                    var result = JSON.parse(text);
                    saveBtn.textContent = originalText;
                    if (result.success) {
                        showToast(result.message, 'success');
                        closeModal('ps-product-modal');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast('Failed: ' + result.message, 'error');
                    }
                } catch (e) {
                    saveBtn.textContent = originalText;
                    showToast('Server response error', 'error');
                }
            })
            .catch(function() {
                saveBtn.textContent = originalText;
                showToast('Failed to save product', 'error');
            });
    }

    function deleteProduct(sku) {
        if (!confirm('Are you sure you want to delete this product?')) return;
        var fd = new FormData();
        fd.append('action', 'delete_product');
        fd.append('sku', sku);
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(function() { location.reload(); }, 1000);
            })
            .catch(function() { showToast('Failed to delete product', 'error'); });
    }

    function duplicateProduct(sku) {
        if (!confirm('Duplicate this product?')) return;
        var fd = new FormData();
        fd.append('action', 'duplicate_product');
        fd.append('sku', sku);
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(function() { location.reload(); }, 1000);
            })
            .catch(function() { showToast('Failed to duplicate product', 'error'); });
    }

    function toggleProduct(sku) {
        var fd = new FormData();
        fd.append('action', 'toggle_product');
        fd.append('sku', sku);
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(function() { location.reload(); }, 1000);
            })
            .catch(function() { showToast('Failed to toggle product', 'error'); });
    }

    function bulkEnable() {
        if (!confirm('Enable all products?')) return;
        var fd = new FormData();
        fd.append('action', 'bulk_enable');
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(function() { location.reload(); }, 1000);
            })
            .catch(function() { showToast('Failed', 'error'); });
    }

    function bulkDisable() {
        if (!confirm('Disable all products?')) return;
        var fd = new FormData();
        fd.append('action', 'bulk_disable');
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(function() { location.reload(); }, 1000);
            })
            .catch(function() { showToast('Failed', 'error'); });
    }

    // ========================================================================
    // Bulk select + delete selected
    // ========================================================================
    function getProductCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.ps-product-select'));
    }
    function getSelectedSkus() {
        return getProductCheckboxes().filter(function(cb){ return cb.checked; })
            .map(function(cb){ return cb.getAttribute('data-sku'); })
            .filter(function(s){ return s; });
    }
    function onProductSelectChange() {
        var skus = getSelectedSkus();
        var count = skus.length;
        var totalBoxes = getProductCheckboxes().length;
        var countEl = document.getElementById('ps-bulk-count');
        var delBtn  = document.getElementById('ps-bulk-delete-btn');
        var selectAll = document.getElementById('ps-product-select-all');
        if (countEl) {
            countEl.textContent = count === 1 ? '1 selected' : count + ' selected';
            countEl.classList.toggle('has-selection', count > 0);
        }
        if (delBtn) delBtn.disabled = count === 0;
        if (selectAll) {
            selectAll.checked = (count > 0 && count === totalBoxes);
            selectAll.indeterminate = (count > 0 && count < totalBoxes);
        }
        // Highlight the selected cards
        getProductCheckboxes().forEach(function(cb){
            var card = cb.closest('.ps-product-card');
            if (card) card.classList.toggle('is-selected', cb.checked);
        });
    }
    function toggleSelectAllProducts(checked) {
        getProductCheckboxes().forEach(function(cb){ cb.checked = !!checked; });
        onProductSelectChange();
    }
    function bulkDeleteSelected() {
        var skus = getSelectedSkus();
        if (skus.length === 0) {
            showToast('No products selected', 'error');
            return;
        }
        var msg = skus.length === 1
            ? 'Delete 1 selected product? This cannot be undone.'
            : 'Delete ' + skus.length + ' selected products? This cannot be undone.';
        if (!confirm(msg)) return;

        var fd = new FormData();
        fd.append('action', 'bulk_delete_selected');
        fd.append('skus', JSON.stringify(skus));
        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                // Recognize the server's new response shape:
                //   success=true              → all deleted, toast success, reload
                //   partial=true, deleted > 0 → some worked, toast warn, reload to show state
                //   success=false (no partial) → hard failure, toast error, don't reload
                var level = result.success ? 'success' : (result.partial ? 'warn' : 'error');
                showToast(result.message || 'Done', level);
                if (result.success || result.partial) {
                    setTimeout(function() { location.reload(); }, 900);
                }
            })
            .catch(function() { showToast('Failed to delete selected products', 'error'); });
    }

    // ========================================================================
    // Grid layout controls
    // ========================================================================
    function toggleLayoutControls() {
        var panel = document.getElementById('ps-layout-controls-panel');
        var button = document.getElementById('ps-layout-toggle');
        if (panel.style.display === 'none') {
            panel.style.display = 'grid';
            button.textContent = 'Hide Controls';
        } else {
            panel.style.display = 'none';
            button.textContent = 'Show Controls';
        }
    }

    function updateGridLayout() {
        var cols = (document.getElementById('ps-grid_columns') || {}).value || 4;
        var gap  = (document.getElementById('ps-grid-gap') || {}).value || 24;
        var rad  = (document.getElementById('ps-card-radius') || {}).value || 12;

        var cv = document.getElementById('ps-columns-value');
        var gv = document.getElementById('ps-gap-value');
        var rv = document.getElementById('ps-radius-value');
        if (cv) cv.textContent = cols;
        if (gv) gv.textContent = gap;
        if (rv) rv.textContent = rad;

        var grid = document.getElementById('ps-products-grid');
        if (grid) {
            grid.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
            grid.style.gap = gap + 'px';
        }

        document.querySelectorAll('.ps-product-card').forEach(function(card) {
            card.style.borderRadius = rad + 'px';
        });

        localStorage.setItem('ps-layout-settings', JSON.stringify({ columns: cols, gap: gap, radius: rad }));
    }

    function loadLayoutSettings() {
        var saved = localStorage.getItem('ps-layout-settings');
        if (saved) {
            try {
                var layout = JSON.parse(saved);
                if (layout.columns) { var el = document.getElementById('ps-grid_columns'); if (el) el.value = layout.columns; }
                if (layout.gap) { var el = document.getElementById('ps-grid-gap'); if (el) el.value = layout.gap; }
                if (layout.radius) { var el = document.getElementById('ps-card-radius'); if (el) el.value = layout.radius; }
                updateGridLayout();
            } catch (e) { /* ignore */ }
        }
    }

    // ========================================================================
    // Search / filter
    // ========================================================================
    function filterProducts() {
        var term = document.getElementById('ps-product-search').value.toLowerCase();
        document.querySelectorAll('.ps-product-card').forEach(function(card) {
            var name = card.querySelector('h3') ? card.querySelector('h3').textContent.toLowerCase() : '';
            var sku = card.querySelector('.ps-text-muted') ? card.querySelector('.ps-text-muted').textContent.toLowerCase() : '';
            var cat = card.querySelector('.ps-badge-secondary') ? card.querySelector('.ps-badge-secondary').textContent.toLowerCase() : '';
            card.style.display = (name.includes(term) || sku.includes(term) || cat.includes(term)) ? '' : 'none';
        });
    }

    // ========================================================================
    // Variations
    // ========================================================================
    function addVariationGroup(existingGroup) {
        variationGroupCount++;
        var container = document.getElementById('ps-variations-container');
        var groupDiv = document.createElement('div');
        groupDiv.className = 'ps-p-4 ps-mb-4';
        groupDiv.style.border = '1px solid var(--ps-border)';
        groupDiv.style.borderRadius = 'var(--ps-radius)';
        groupDiv.id = 'ps-variation-group-' + variationGroupCount;

        var groupName = existingGroup ? existingGroup.name : '';
        var groupOptions = existingGroup ? existingGroup.options : [''];
        var optionsHtml = '';
        groupOptions.forEach(function(option) {
            var optVal   = (typeof option === 'object' && option !== null) ? (option.value || '') : option;
            var optAdj   = (typeof option === 'object' && option !== null && option.adj   != null) ? option.adj   : '';
            var optFixed = (typeof option === 'object' && option !== null && option.price != null) ? option.price : '';
            optionsHtml += PS.varOptionHtml(optVal, optAdj, optFixed);
        });

        var gid = variationGroupCount;
        groupDiv.innerHTML = '<div class="ps-flex ps-items-center ps-justify-between ps-mb-4">' +
            '<input type="text" placeholder="Variation name (e.g., Size, Color)" value="' + groupName + '" class="ps-form-input ps-flex-1 ps-mr-4">' +
            '<button type="button" class="ps-btn ps-btn-destructive ps-btn-sm" onclick="PS.removeVariationGroup(' + gid + ')"><i class="fas fa-trash"></i></button>' +
            '</div>' +
            '<div class="ps-flex ps-gap-2 ps-mb-1" style="font-size:0.6rem;color:var(--ps-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.05em;padding:0 2px">' +
            '<span style="flex:1">Option</span>' +
            '<div style="max-width:90px;width:90px"><div>± Adjust</div><div style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.7">adds/subtracts</div></div>' +
            '<div style="max-width:110px;width:110px"><div>Fixed $</div><div style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.7">exact price</div></div>' +
            '<div style="min-width:80px;width:80px"><div>= Final</div><div style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.7">what they pay</div></div>' +
            '<span style="min-width:36px"></span>' +
            '</div>' +
            '<div class="ps-variation-options">' + optionsHtml + '</div>' +
            '<button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="PS.addVariationOption(' + gid + ')"><i class="fas fa-plus"></i> Add Option</button>';

        container.appendChild(groupDiv);
    }

    function varOptionHtml(optVal, optAdj, optFixed) {
        return '<div class="ps-flex ps-items-center ps-gap-2 ps-mb-2 ps-var-row">' +
            '<input type="text" placeholder="e.g. XL" value="' + (optVal||'') + '" class="ps-form-input ps-flex-1">' +
            '<input type="number" placeholder="+2 or -1" value="' + (optAdj!==''?optAdj:'') + '" step="0.01" class="ps-form-input ps-var-adj" style="max-width:90px" oninput="PS.updateVariantFinals()" title="Add or subtract from base price">' +
            '<input type="number" placeholder="22.00" value="' + (optFixed!==''?optFixed:'') + '" step="0.01" min="0" class="ps-form-input ps-var-price" style="max-width:110px" oninput="PS.updateVariantFinals()" title="Exact price — overrides base and adjust">' +
            '<span class="ps-var-final" style="min-width:80px;width:80px;font-size:0.8rem;font-weight:700;color:var(--ps-primary);padding:0 4px">= $--</span>' +
            '<button type="button" class="ps-btn ps-btn-destructive ps-btn-sm" onclick="PS.removeVariationOption(this)"><i class="fas fa-times"></i></button>' +
            '</div>';
    }

    function addVariationOption(groupId) {
        var group = document.getElementById('ps-variation-group-' + groupId);
        var opts = group.querySelector('.ps-variation-options');
        var div = document.createElement('div');
        div.innerHTML = PS.varOptionHtml('', '', '');
        opts.appendChild(div.firstChild);
        PS.updateVariantFinals();
    }

    function updateVariantFinals() {
        var base = parseFloat(document.getElementById('ps-product-price').value) || 0;
        document.querySelectorAll('.ps-var-row').forEach(function(row) {
            var adjInp   = row.querySelector('.ps-var-adj');
            var fixedInp = row.querySelector('.ps-var-price');
            var finalEl  = row.querySelector('.ps-var-final');
            if (!finalEl) return;
            var adj   = adjInp   && adjInp.value   !== '' ? parseFloat(adjInp.value)   : null;
            var fixed = fixedInp && fixedInp.value !== '' ? parseFloat(fixedInp.value) : null;
            var final = fixed !== null ? fixed : (adj !== null ? base + adj : base);
            finalEl.textContent = '= $' + final.toFixed(2);
            finalEl.style.color = (fixed !== null || adj !== null) ? 'var(--ps-primary)' : 'var(--ps-muted)';
        });
    }

    function removeVariationOption(button) { button.parentElement.remove(); }

    function removeVariationGroup(groupId) {
        var g = document.getElementById('ps-variation-group-' + groupId);
        if (g) g.remove();
    }

    function getVariationsData() {
        var container = document.getElementById('ps-variations-container');
        var groups = [];
        container.querySelectorAll('[id^="ps-variation-group-"]').forEach(function(group) {
            var nameInput = group.querySelector('input[type="text"]');
            var optionRows = group.querySelectorAll('.ps-variation-options > div');
            if (nameInput && nameInput.value.trim()) {
                var options = [];
                optionRows.forEach(function(row) {
                    var valInput   = row.querySelector('input[type="text"]');
                    var adjInput   = row.querySelector('input.ps-var-adj');
                    var fixedInput = row.querySelector('input.ps-var-price');
                    if (valInput && valInput.value.trim()) {
                        var adjVal   = adjInput   && adjInput.value   !== '' ? parseFloat(adjInput.value)   : null;
                        var fixedVal = fixedInput && fixedInput.value !== '' ? parseFloat(fixedInput.value) : null;
                        options.push({ value: valInput.value.trim(), adj: adjVal, price: fixedVal });
                    }
                });
                if (options.length > 0) {
                    groups.push({ name: nameInput.value.trim(), options: options });
                }
            }
        });
        return { groups: groups };
    }

    function loadVariations(groups) {
        document.getElementById('ps-variations-container').innerHTML = '';
        variationGroupCount = 0;
        groups.forEach(function(g) { addVariationGroup(g); });
        setTimeout(updateVariantFinals, 50); // wait for price field to populate
    }

    // ========================================================================
    // Image Manager — main image + gallery with DnD
    // ========================================================================
    var _galleryImages = [];

    function populateImageManager(product) {
        var mainImg = product.image || '';
        var gallery = product.images || [];
        _galleryImages = gallery.filter(function(g) { return g !== mainImg; });

        // Main image
        var mainPrev = document.getElementById('ps-main-image-preview');
        var mainEmpty = document.getElementById('ps-main-image-empty');
        if (mainImg) {
            mainPrev.src = mainImg; mainPrev.style.display = 'block'; mainEmpty.style.display = 'none';
        } else {
            mainPrev.style.display = 'none'; mainEmpty.style.display = '';
        }

        renderGalleryGrid();
    }

    function renderGalleryGrid() {
        var grid = document.getElementById('ps-gallery-grid');
        var empty = document.getElementById('ps-gallery-empty');
        if (!_galleryImages.length) {
            grid.innerHTML = ''; empty.style.display = '';
            return;
        }
        empty.style.display = 'none';
        grid.innerHTML = _galleryImages.map(function(src, i) {
            return '<div style="position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;border:1px solid rgba(255,255,255,0.08)">'
                + '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display=\'none\'">'
                + '<div style="position:absolute;top:0;right:0;display:flex;gap:2px;padding:3px">'
                + '<button type="button" onclick="PS.setAsMain(' + i + ')" title="Set as main" style="background:rgba(0,0,0,0.7);border:none;color:#f59e0b;width:22px;height:22px;border-radius:4px;cursor:pointer;font-size:0.65rem">&#11088;</button>'
                + '<button type="button" onclick="PS.removeGalleryImage(' + i + ')" title="Remove" style="background:rgba(0,0,0,0.7);border:none;color:#dc2626;width:22px;height:22px;border-radius:4px;cursor:pointer;font-size:0.65rem">&times;</button>'
                + '</div></div>';
        }).join('');
    }

    function setAsMain(galleryIndex) {
        var newMain = _galleryImages[galleryIndex];
        var oldMain = document.getElementById('ps-product-image').value;
        // Swap
        document.getElementById('ps-product-image').value = newMain;
        document.getElementById('ps-main-image-preview').src = newMain;
        document.getElementById('ps-main-image-preview').style.display = 'block';
        document.getElementById('ps-main-image-empty').style.display = 'none';
        _galleryImages.splice(galleryIndex, 1);
        if (oldMain) _galleryImages.unshift(oldMain);
        renderGalleryGrid();
        showToast('Main image updated', 'success');
    }

    function removeGalleryImage(index) {
        _galleryImages.splice(index, 1);
        renderGalleryGrid();
    }

    function uploadMainImage(file) {
        if (!file || !file.type.startsWith('image/')) return;
        var sku = document.getElementById('ps-product-sku').value.trim();
        if (!sku) { showToast('Enter SKU first', 'error'); return; }
        var fd = new FormData(); fd.append('action', 'upload_image'); fd.append('sku', sku); fd.append('image', file);
        psFetch(apiUrl, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                var oldMain = document.getElementById('ps-product-image').value;
                if (oldMain) _galleryImages.unshift(oldMain);
                document.getElementById('ps-product-image').value = d.imagePath;
                document.getElementById('ps-main-image-preview').src = d.imagePath;
                document.getElementById('ps-main-image-preview').style.display = 'block';
                document.getElementById('ps-main-image-empty').style.display = 'none';
                renderGalleryGrid();
                showToast('Main image uploaded', 'success');
            } else { showToast(d.message || 'Upload failed', 'error'); }
        });
    }

    function uploadGalleryImages(files) {
        if (!files || !files.length) return;
        var sku = document.getElementById('ps-product-sku').value.trim();
        if (!sku) { showToast('Enter SKU first', 'error'); return; }
        var total = files.length, done = 0;
        for (var i = 0; i < total; i++) {
            (function(file) {
                var fd = new FormData(); fd.append('action', 'upload_image'); fd.append('sku', sku); fd.append('image', file);
                psFetch(apiUrl, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) {
                    done++;
                    if (d.success) {
                        _galleryImages.push(d.imagePath);
                        // If no main image yet, set first upload as main
                        if (!document.getElementById('ps-product-image').value) {
                            document.getElementById('ps-product-image').value = d.imagePath;
                            document.getElementById('ps-main-image-preview').src = d.imagePath;
                            document.getElementById('ps-main-image-preview').style.display = 'block';
                            document.getElementById('ps-main-image-empty').style.display = 'none';
                            _galleryImages.pop(); // remove from gallery since it's now main
                        }
                        renderGalleryGrid();
                    }
                    if (done >= total) showToast(done + ' image(s) uploaded', 'success');
                });
            })(files[i]);
        }
    }

    function setupDragAndDrop() {
        // Main image DnD
        var mainBox = document.getElementById('ps-main-image-box');
        if (mainBox) {
            ['dragenter','dragover','dragleave','drop'].forEach(function(e) { mainBox.addEventListener(e, function(ev) { ev.preventDefault(); ev.stopPropagation(); }); });
            mainBox.addEventListener('dragenter', function() { mainBox.style.borderColor = '#f59e0b'; });
            mainBox.addEventListener('dragleave', function(e) { if (!mainBox.contains(e.relatedTarget)) mainBox.style.borderColor = ''; });
            mainBox.addEventListener('drop', function(e) { mainBox.style.borderColor = ''; if (e.dataTransfer.files.length) uploadMainImage(e.dataTransfer.files[0]); });
        }
        // Gallery DnD
        var galDz = document.getElementById('ps-gallery-dropzone');
        if (galDz) {
            ['dragenter','dragover','dragleave','drop'].forEach(function(e) { galDz.addEventListener(e, function(ev) { ev.preventDefault(); ev.stopPropagation(); }); });
            galDz.addEventListener('dragenter', function() { galDz.style.borderColor = '#f59e0b'; });
            galDz.addEventListener('dragleave', function(e) { if (!galDz.contains(e.relatedTarget)) galDz.style.borderColor = ''; });
            galDz.addEventListener('drop', function(e) { galDz.style.borderColor = ''; if (e.dataTransfer.files.length) uploadGalleryImages(e.dataTransfer.files); });
        }
    }

    function handleFileSelect(e) { uploadGalleryImages(e.target.files); }

    function clearImagePreview() {
        document.getElementById('ps-image-preview').style.display = 'none';
        document.getElementById('ps-product-image').value = '';
    }

    // ========================================================================
    // Settings
    // ========================================================================
    function showSettingsModal() { showModal('ps-settings-modal'); }
    function showImportModal() { showToast('CSV Import feature coming soon', 'info'); }

    function markUnsaved() {
        hasUnsavedChanges = true;
        var badge = document.getElementById('ps-unsaved-badge');
        if (badge) badge.style.display = 'inline-block';
    }

    function showTab(tabName) {
        document.querySelectorAll('.ps-tab-content').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.ps-tab-trigger').forEach(function(t) { t.classList.remove('active'); });
        var tab = document.getElementById('ps-tab-' + tabName);
        if (tab) tab.classList.add('active');
        var trigger = document.querySelector('[data-tab="' + tabName + '"]');
        if (trigger) trigger.classList.add('active');
    }

    function saveSettings() {
        var settings = {};
        // Collect from settings modal AND layout controls bar
        document.querySelectorAll('#ps-settings-modal input, #ps-settings-modal select, #ps-settings-modal textarea, .ps-layout-controls input, .ps-layout-controls select').forEach(function(inp) {
            if (!inp.id || !inp.id.startsWith('ps-')) return;
            var key = inp.id.replace('ps-', '');
            if (inp.type === 'checkbox') { settings[key] = inp.checked; }
            else { settings[key] = inp.value; }
        });

        // Fold the Shipping tab's flat helper keys into a structured `shipping` block
        settings.shipping = collectShippingBlock(settings);
        ['shippingMode','shippingFlatRate','shippingAboveMax','shippingPerAdditional',
         'shippingFreeEnabled','shippingFreeType','shippingFreeThreshold'].forEach(function(k){ delete settings[k]; });

        var fd = new FormData();
        fd.append('action', 'save_settings');
        fd.append('settings', JSON.stringify(settings));

        psFetch(apiUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    hasUnsavedChanges = false;
                    var badge = document.getElementById('ps-unsaved-badge');
                    if (badge) badge.style.display = 'none';
                    showToast('Settings saved successfully', 'success');
                } else {
                    showToast('Failed to save settings', 'error');
                }
            })
            .catch(function() { showToast('Failed to save settings', 'error'); });
    }

    function exportSettings() {
        var settings = {};
        document.querySelectorAll('#ps-settings-modal input, #ps-settings-modal select, #ps-settings-modal textarea, .ps-layout-controls input, .ps-layout-controls select').forEach(function(inp) {
            if (!inp.id || !inp.id.startsWith('ps-')) { return; }
            var key = inp.id.replace('ps-', '');
            if (inp.type === 'checkbox') { settings[key] = inp.checked; }
            else { settings[key] = inp.value; }
        });
        var blob = new Blob([JSON.stringify(settings, null, 2)], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'mystore-settings-' + new Date().toISOString().split('T')[0] + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        showToast('Settings exported', 'success');
    }

    function importSettings(input) {
        var file = input.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var settings = JSON.parse(e.target.result);
                Object.keys(settings).forEach(function(key) {
                    var el = document.getElementById('ps-' + key);
                    if (el) {
                        if (el.type === 'checkbox') { el.checked = settings[key]; }
                        else { el.value = settings[key]; }
                    }
                });
                markUnsaved();
                showToast('Settings imported', 'success');
            } catch (err) {
                showToast('Failed to import — invalid file', 'error');
            }
        };
        reader.readAsText(file);
        input.value = '';
    }

    function resetAllSettings() {
        if (confirm('Reset all settings to defaults?')) {
            showToast('Settings reset', 'success');
            setTimeout(function() { location.reload(); }, 1000);
        }
    }

    // ========================================================================
    // Background customizer
    // ========================================================================
    // Live preview colors + typography in the admin product grid
    function livePreviewColors() {
        var grid = document.getElementById('ps-products-grid');
        if (!grid) return;

        function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }

        // Page background with opacity
        var pageBg = val('ps-store_page_bg');
        var opVal = val('ps-store_bg_opacity');
        if (pageBg) {
            var op = opVal ? parseInt(opVal) / 100 : 1;
            if (op < 1) {
                var r = parseInt(pageBg.substr(1,2),16), g = parseInt(pageBg.substr(3,2),16), b = parseInt(pageBg.substr(5,2),16);
                grid.style.background = 'rgba(' + r + ',' + g + ',' + b + ',' + op + ')';
            } else {
                grid.style.background = pageBg;
            }
            grid.style.borderRadius = '12px';
            grid.style.padding = '16px';
        }

        // Card backgrounds
        var cardBg = val('ps-store_card_bg');
        if (cardBg) {
            document.querySelectorAll('.ps-product-card').forEach(function(card) { card.style.background = cardBg; });
        }

        // Accent / price color
        var accent = val('ps-store_accent');
        var priceColor = val('ps-store_price_color') || accent;
        if (priceColor) {
            document.querySelectorAll('.ps-text-primary').forEach(function(el) { el.style.color = priceColor; });
        }

        // Title color + size
        var titleColor = val('ps-store_title_color');
        var titleSize = val('ps-store_title_size');
        document.querySelectorAll('.ps-product-info .ps-font-bold.ps-text-lg').forEach(function(el) {
            if (titleColor) el.style.color = titleColor;
            if (titleSize) el.style.fontSize = titleSize + 'rem';
        });

        // Price size
        var priceSize = val('ps-store_price_size');
        document.querySelectorAll('.ps-product-info .ps-text-xl.ps-font-bold.ps-text-primary').forEach(function(el) {
            if (priceSize) el.style.fontSize = priceSize + 'rem';
            if (priceColor) el.style.color = priceColor;
        });

        // Description color
        var descColor = val('ps-store_desc_color');
        if (descColor) {
            document.querySelectorAll('.ps-product-info .ps-text-sm.ps-text-muted.ps-mb-4').forEach(function(el) { el.style.color = descColor; });
        }

        // Meta color (SKU, category badge)
        var metaColor = val('ps-store_meta_color');
        if (metaColor) {
            document.querySelectorAll('.ps-product-info .ps-text-sm.ps-text-muted:not(.ps-mb-4)').forEach(function(el) { el.style.color = metaColor; });
            document.querySelectorAll('.ps-badge-secondary').forEach(function(el) { el.style.color = metaColor; });
        }

        // Font family
        var font = val('ps-store_font');
        if (font) {
            var ff = font === 'system' ? 'system-ui,-apple-system,sans-serif' : (font === 'inherit' ? 'inherit' : font + ',sans-serif');
            document.querySelectorAll('.ps-product-card').forEach(function(card) { card.style.fontFamily = ff; });
        }
    }

    function toggleBgCustomizer() {
        document.getElementById('ps-bg-customizer').classList.toggle('ps-hidden');
    }
    function updateBgColor(c) { document.documentElement.style.setProperty('--ps-bg', c); }
    function updateBgOpacity(v) { document.getElementById('ps-opacity-value').textContent = v + '%'; }
    function updateBgBlur(v) { document.getElementById('ps-blur-value').textContent = v + 'px'; }
    function resetBackground() {
        updateBgColor('#000000');
        updateBgOpacity(80);
        updateBgBlur(10);
        showToast('Background reset', 'success');
    }

    // ========================================================================
    // Global event listeners
    // ========================================================================
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('ps-modal')) e.target.classList.remove('show');
    });

    // ========================================================================
    // Orders Management
    // ========================================================================
    var _allOrders = boot.orders || [];
    var _filteredOrders = [];
    var _ordersPage = 1;
    var _ordersSortCol = 'date';
    var _ordersSortDir = 'desc';
    var _selectedOrderIds = {};
    var storeApiUrl = boot.storeApiUrl || '/admin/modules/MyStore/api/store-api.php';

    function getStatusColor(status) {
        var colors = {
            completed: '#22c55e', pending: '#f59e0b', processing: '#3b82f6',
            'on-hold': '#a855f7', failed: '#ef4444', refunded: '#6b7280',
            cancelled: '#dc2626', archived: '#4b5563'
        };
        return colors[status] || '#888';
    }

    function isRevenueStatus(status) {
        return status !== 'archived' && status !== 'failed' && status !== 'cancelled' && status !== 'refunded';
    }

    function ordersFilterChanged() {
        _ordersPage = 1;
        renderOrders();
    }

    function getFilteredOrders() {
        var statusFilter = document.getElementById('ps-order-status-filter').value;
        var searchTerm = (document.getElementById('ps-order-search').value || '').toLowerCase().trim();
        var result = _allOrders.filter(function(o) {
            if (statusFilter !== 'all' && (o.status || '') !== statusFilter) return false;
            if (searchTerm) {
                var name = ((o.customer && o.customer.name) || '').toLowerCase();
                var email = ((o.customer && o.customer.email) || '').toLowerCase();
                var oid = (o.order_id || '').toLowerCase();
                if (name.indexOf(searchTerm) === -1 && email.indexOf(searchTerm) === -1 && oid.indexOf(searchTerm) === -1) return false;
            }
            return true;
        });
        return result;
    }

    function sortOrdersArray(arr) {
        var col = _ordersSortCol;
        var dir = _ordersSortDir === 'asc' ? 1 : -1;
        arr.sort(function(a, b) {
            var va, vb;
            switch (col) {
                case 'customer': va = ((a.customer && a.customer.name) || '').toLowerCase(); vb = ((b.customer && b.customer.name) || '').toLowerCase(); break;
                case 'order': va = (a.order_id || '').toLowerCase(); vb = (b.order_id || '').toLowerCase(); break;
                case 'date': va = a.created_at || ''; vb = b.created_at || ''; break;
                case 'items': va = (a.items || []).reduce(function(s,i){return s+(i.quantity||0)},0); vb = (b.items || []).reduce(function(s,i){return s+(i.quantity||0)},0); break;
                case 'total': va = a.total || 0; vb = b.total || 0; break;
                case 'payment': va = (a.payment_method || '').toLowerCase(); vb = (b.payment_method || '').toLowerCase(); break;
                case 'status': va = (a.status || '').toLowerCase(); vb = (b.status || '').toLowerCase(); break;
                default: va = a.created_at || ''; vb = b.created_at || '';
            }
            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;
            return 0;
        });
        return arr;
    }

    function sortOrders(col) {
        if (_ordersSortCol === col) {
            _ordersSortDir = _ordersSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            _ordersSortCol = col;
            _ordersSortDir = (col === 'date' || col === 'total') ? 'desc' : 'asc';
        }
        // Update sort icons
        document.querySelectorAll('.ps-sort-icon').forEach(function(el) {
            el.innerHTML = el.getAttribute('data-col') === col ? (_ordersSortDir === 'asc' ? '&#9650;' : '&#9660;') : '&#8597;';
        });
        renderOrders();
    }

    function renderOrders() {
        _filteredOrders = sortOrdersArray(getFilteredOrders());
        var perPage = parseInt(document.getElementById('ps-orders-per-page').value) || 20;
        var totalPages = Math.max(1, Math.ceil(_filteredOrders.length / perPage));
        if (_ordersPage > totalPages) _ordersPage = totalPages;
        var start = (_ordersPage - 1) * perPage;
        var pageOrders = _filteredOrders.slice(start, start + perPage);

        // Update stats (based on filtered set)
        var totalCount = _filteredOrders.length;
        var revenue = 0, completedCount = 0, pendingCount = 0;
        _filteredOrders.forEach(function(o) {
            if (isRevenueStatus(o.status || '')) revenue += (o.total || 0);
            if ((o.status || '') === 'completed') completedCount++;
            if ((o.status || '') === 'pending') pendingCount++;
        });
        document.getElementById('ps-orders-stat-total').textContent = totalCount;
        document.getElementById('ps-orders-stat-revenue').textContent = '$' + revenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('ps-orders-stat-completed').textContent = completedCount;
        document.getElementById('ps-orders-stat-pending').textContent = pendingCount;

        // Render table body
        var tbody = document.getElementById('ps-orders-tbody');
        if (!pageOrders.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="padding:3rem;text-align:center;color:#666"><i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:0.5rem"></i>No orders found</td></tr>';
        } else {
            var html = '';
            pageOrders.forEach(function(ord) {
                var oid = ord.order_id || '';
                var safeOid = oid.replace(/'/g, "\\'");
                var detailId = 'ord_' + oid.replace(/[^a-zA-Z0-9]/g, '_');
                var statusColor = getStatusColor(ord.status || '');
                var itemNames = (ord.items || []).map(function(i){return i.name + ' x' + i.quantity}).join(', ');
                var d = new Date(ord.created_at || Date.now());
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                var h = d.getHours(); var ampm = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
                var dateStr = months[d.getMonth()] + ' ' + d.getDate() + ', ' + h + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes() + ' ' + ampm;
                var custName = (ord.customer && ord.customer.name) || '';
                var custEmail = (ord.customer && ord.customer.email) || '';
                var checked = _selectedOrderIds[oid] ? ' checked' : '';
                var itemQtyTotal = (ord.items || []).reduce(function(s,i){return s+(i.quantity||0)},0);

                html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer" onmouseover="this.style.background=\'rgba(255,255,255,0.03)\'" onmouseout="this.style.background=\'\'" onclick="PS.toggleOrderDetail(\'' + detailId + '\')">';
                html += '<td style="padding:10px 8px" onclick="event.stopPropagation()"><input type="checkbox" class="ps-order-cb" data-oid="' + oid + '" onchange="PS.orderCheckboxChanged(this)"' + checked + ' style="cursor:pointer"></td>';
                html += '<td style="padding:10px 12px"><div style="color:#f0f0f0;font-weight:500">' + escHtml(custName) + '</div><div style="color:#666;font-size:0.72rem">' + escHtml(custEmail) + '</div></td>';
                html += '<td style="padding:10px 12px;font-weight:600;color:#aaa;white-space:nowrap;font-size:0.72rem">' + escHtml(oid) + '</td>';
                html += '<td style="padding:10px 12px;color:#aaa;white-space:nowrap">' + dateStr + '</td>';
                html += '<td style="padding:10px 12px;color:#aaa;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escAttr(itemNames) + '">' + escHtml(itemNames) + '</td>';
                html += '<td style="padding:10px 12px;font-weight:700;color:#f59e0b;white-space:nowrap">$' + (ord.total || 0).toFixed(2) + '</td>';
                html += '<td style="padding:10px 12px;white-space:nowrap"><span style="font-size:0.7rem;padding:2px 6px;border-radius:4px;background:rgba(255,255,255,0.06);color:#aaa">' + escHtml(ucfirst(ord.payment_method || '')) + '</span></td>';
                html += '<td style="padding:10px 12px;white-space:nowrap"><span style="font-size:0.7rem;padding:2px 8px;border-radius:4px;background:' + statusColor + '20;color:' + statusColor + ';font-weight:600">' + escHtml(ucfirst(ord.status || 'unknown')) + '</span></td>';
                html += '</tr>';

                // Expandable detail row
                html += '<tr id="' + detailId + '" style="display:none"><td colspan="8" style="padding:0 12px 16px;background:rgba(255,255,255,0.02)">';
                html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:14px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);margin-top:4px;font-size:0.8rem">';
                // Items column
                html += '<div><div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin-bottom:6px;font-weight:700">Items</div>';
                (ord.items || []).forEach(function(item) {
                    var varStr = '';
                    if (item.variations && typeof item.variations === 'object') {
                        var parts = [];
                        for (var vk in item.variations) { if (item.variations.hasOwnProperty(vk)) parts.push(vk + ': ' + item.variations[vk]); }
                        if (parts.length) varStr = '<br><span style="font-size:0.68rem;color:#555">' + escHtml(parts.join(', ')) + '</span>';
                    }
                    html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.04)">';
                    html += '<span style="color:#ccc">' + escHtml(item.name) + ' <span style="color:#666">x' + (item.quantity || 0) + '</span>' + varStr + '</span>';
                    html += '<span style="color:#aaa;font-weight:600">$' + (item.total || 0).toFixed(2) + '</span></div>';
                });
                html += '<div style="margin-top:8px;padding-top:6px;border-top:1px solid rgba(255,255,255,0.08)">';
                html += '<div style="display:flex;justify-content:space-between;color:#888"><span>Subtotal</span><span>$' + (ord.subtotal || 0).toFixed(2) + '</span></div>';
                html += '<div style="display:flex;justify-content:space-between;color:#888"><span>Tax</span><span>$' + (ord.tax || 0).toFixed(2) + '</span></div>';
                html += '<div style="display:flex;justify-content:space-between;color:#888"><span>Shipping</span><span>' + ((ord.shipping || 0) == 0 ? 'FREE' : '$' + (ord.shipping || 0).toFixed(2)) + '</span></div>';
                html += '<div style="display:flex;justify-content:space-between;font-weight:700;color:#f59e0b;margin-top:4px"><span>Total</span><span>$' + (ord.total || 0).toFixed(2) + '</span></div>';
                html += '</div></div>';
                // Customer column
                html += '<div><div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin-bottom:6px;font-weight:700">Customer</div>';
                html += '<div style="color:#ccc;margin-bottom:4px">' + escHtml(custName) + '</div>';
                html += '<div style="color:#888;margin-bottom:4px">' + escHtml(custEmail) + '</div>';
                if (ord.customer && ord.customer.phone) html += '<div style="color:#888;margin-bottom:4px">' + escHtml(ord.customer.phone) + '</div>';
                var addr = (ord.customer && ord.customer.address) || {};
                html += '<div style="margin-top:8px;padding:8px 10px;background:rgba(255,255,255,0.03);border-radius:6px;font-size:0.78rem;line-height:1.6">';
                html += '<div style="font-size:0.65rem;color:#555;text-transform:uppercase;margin-bottom:4px;font-weight:700">Shipping Address</div>';
                html += '<div style="color:#ccc">' + escHtml(custName) + '</div>';
                if (addr.street) html += '<div style="color:#aaa">' + escHtml(addr.street) + '</div>';
                html += '<div style="color:#aaa">' + escHtml(((addr.city || '') + ', ' + (addr.state || '') + ' ' + (addr.zip || '')).replace(/^,\s*/, '').trim()) + '</div>';
                html += '</div>';
                html += '<div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin:12px 0 6px;font-weight:700">Payment</div>';
                html += '<div style="color:#aaa">' + escHtml(ucfirst(ord.payment_method || '')) + '</div>';
                if (ord.payment_id) html += '<div style="color:#555;font-size:0.72rem;font-family:monospace;margin-top:2px">' + escHtml(ord.payment_id) + '</div>';
                html += '<div style="color:#666;font-size:0.72rem;margin-top:4px">' + escHtml(ord.created_at || '') + '</div>';
                // Action buttons
                html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.06)">';
                html += '<button class="ps-btn ps-btn-outline ps-btn-sm" onclick="event.stopPropagation();PS.resendInvoice(\'' + safeOid + '\')"><i class="fas fa-envelope"></i> Send Invoice</button>';
                html += '<button class="ps-btn ps-btn-outline ps-btn-sm" onclick="event.stopPropagation();PS.printOrder(\'packing\',\'' + safeOid + '\')"><i class="fas fa-box"></i> Packing Slip</button>';
                html += '<button class="ps-btn ps-btn-outline ps-btn-sm" onclick="event.stopPropagation();PS.printOrder(\'label\',\'' + safeOid + '\')"><i class="fas fa-tag"></i> Shipping Label</button>';
                // Status change dropdown
                html += '<select onchange="event.stopPropagation();PS.updateOrderStatus(\'' + safeOid + '\',this.value);this.value=\'\'" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#ccc;padding:4px 8px;border-radius:4px;font-size:0.72rem;cursor:pointer">';
                html += '<option value="">Change Status...</option>';
                ['completed','pending','processing','on-hold','failed','refunded','cancelled'].forEach(function(s) {
                    if (s !== (ord.status || '')) html += '<option value="' + s + '">' + ucfirst(s) + '</option>';
                });
                html += '</select>';
                html += '<button class="ps-btn ps-btn-outline ps-btn-sm" style="color:#a855f7;border-color:rgba(168,85,247,0.3)" onclick="event.stopPropagation();PS.archiveOrder(\'' + safeOid + '\')"><i class="fas fa-archive"></i> Archive</button>';
                html += '<button class="ps-btn ps-btn-sm" style="background:rgba(220,38,38,0.15);color:#ef4444;border:1px solid rgba(220,38,38,0.3)" onclick="event.stopPropagation();PS.deleteOrder(\'' + safeOid + '\')"><i class="fas fa-trash"></i> Delete</button>';
                html += '</div></div></div></td></tr>';
            });
            tbody.innerHTML = html;
        }

        // Footer totals (based on filtered set)
        var totalItems = _filteredOrders.reduce(function(s,o){return s + (o.items||[]).reduce(function(s2,i){return s2+(i.quantity||0)},0)},0);
        document.getElementById('ps-orders-foot-count').textContent = totalCount + ' order' + (totalCount !== 1 ? 's' : '');
        document.getElementById('ps-orders-foot-items').textContent = totalItems + ' items sold';
        document.getElementById('ps-orders-foot-revenue').textContent = '$' + revenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('ps-orders-foot-completed').textContent = completedCount + ' completed';

        // Pagination
        var showStart = _filteredOrders.length ? start + 1 : 0;
        var showEnd = Math.min(start + perPage, _filteredOrders.length);
        document.getElementById('ps-orders-showing').textContent = 'Showing ' + showStart + '-' + showEnd + ' of ' + _filteredOrders.length + ' orders';
        renderOrdersPagination(totalPages);

        // Sync select-all checkbox
        var allCb = document.getElementById('ps-order-select-all');
        if (allCb) allCb.checked = false;
    }

    function renderOrdersPagination(totalPages) {
        var container = document.getElementById('ps-orders-page-buttons');
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        var html = '';
        var btnStyle = 'padding:4px 10px;border-radius:4px;cursor:pointer;font-size:0.78rem;border:1px solid rgba(255,255,255,0.1);';
        // Prev
        if (_ordersPage > 1) {
            html += '<button onclick="PS.goToOrdersPage(' + (_ordersPage - 1) + ')" style="' + btnStyle + 'background:rgba(255,255,255,0.06);color:#ccc">&laquo; Prev</button>';
        }
        // Page numbers (show max 7 centered on current)
        var startP = Math.max(1, _ordersPage - 3);
        var endP = Math.min(totalPages, startP + 6);
        if (endP - startP < 6) startP = Math.max(1, endP - 6);
        for (var p = startP; p <= endP; p++) {
            if (p === _ordersPage) {
                html += '<button style="' + btnStyle + 'background:var(--ps-primary,#f59e0b);color:#000;font-weight:700;border-color:var(--ps-primary,#f59e0b)">' + p + '</button>';
            } else {
                html += '<button onclick="PS.goToOrdersPage(' + p + ')" style="' + btnStyle + 'background:rgba(255,255,255,0.06);color:#ccc">' + p + '</button>';
            }
        }
        // Next
        if (_ordersPage < totalPages) {
            html += '<button onclick="PS.goToOrdersPage(' + (_ordersPage + 1) + ')" style="' + btnStyle + 'background:rgba(255,255,255,0.06);color:#ccc">Next &raquo;</button>';
        }
        container.innerHTML = html;
    }

    function goToOrdersPage(page) {
        _ordersPage = page;
        renderOrders();
        // Scroll table into view
        var table = document.getElementById('ps-orders-table');
        if (table) table.scrollIntoView({behavior:'smooth', block:'start'});
    }

    function toggleOrderDetail(detailId) {
        var d = document.getElementById(detailId);
        if (d) d.style.display = d.style.display === 'none' ? 'table-row' : 'none';
    }

    function toggleSelectAllOrders(checked) {
        _selectedOrderIds = {};
        if (checked) {
            _filteredOrders.forEach(function(o) { _selectedOrderIds[o.order_id] = true; });
        }
        document.querySelectorAll('.ps-order-cb').forEach(function(cb) { cb.checked = checked; });
    }

    function orderCheckboxChanged(cb) {
        var oid = cb.getAttribute('data-oid');
        if (cb.checked) { _selectedOrderIds[oid] = true; }
        else { delete _selectedOrderIds[oid]; }
    }

    function getSelectedOrderIds() {
        return Object.keys(_selectedOrderIds);
    }

    // ── Single order actions ──
    function resendInvoice(orderId) {
        if (!confirm('Send invoice to customer?')) return;
        var fd = new FormData();
        fd.append('ms_action', 'resend_invoice');
        fd.append('csrf_token', PS_BOOT.csrfToken || '');
        fd.append('order_id', orderId);
        psFetch(storeApiUrl, {method:'POST', body:fd})
            .then(function(r){return r.json()})
            .then(function(d) {
                showToast(d.ok ? 'Invoice sent!' : (d.error || 'Failed'), d.ok ? 'success' : 'error');
            });
    }

    function printOrder(type, orderId) {
        var fd = new FormData();
        fd.append('ms_action', 'print_order');
        fd.append('csrf_token', PS_BOOT.csrfToken || '');
        fd.append('order_id', orderId);
        fd.append('type', type);
        psFetch(storeApiUrl, {method:'POST', body:fd})
            .then(function(r){return r.json()})
            .then(function(d) {
                if (!d.ok) { showToast(d.error || 'Failed', 'error'); return; }
                var w = window.open('', 'print_' + orderId, 'width=500,height=700');
                w.document.write(d.html);
                w.document.close();
                setTimeout(function() { w.print(); }, 500);
            });
    }

    function updateOrderStatus(orderId, newStatus) {
        if (!newStatus) return;
        var fd = new FormData();
        fd.append('ms_action', 'update_order_status');
        fd.append('csrf_token', PS_BOOT.csrfToken || '');
        fd.append('order_id', orderId);
        fd.append('new_status', newStatus);
        psFetch(storeApiUrl, {method:'POST', body:fd})
            .then(function(r){return r.json()})
            .then(function(d) {
                if (d.ok) {
                    // Update local data
                    _allOrders.forEach(function(o) { if (o.order_id === orderId) o.status = newStatus; });
                    renderOrders();
                    showToast('Status updated to ' + ucfirst(newStatus), 'success');
                } else {
                    showToast(d.error || 'Failed to update status', 'error');
                }
            })
            .catch(function() { showToast('Failed to update status', 'error'); });
    }

    function archiveOrder(orderId) {
        if (!confirm('Archive this order? It will be excluded from revenue calculations.')) return;
        updateOrderStatus(orderId, 'archived');
    }

    function deleteOrder(orderId) {
        if (!confirm('Permanently delete this order? This cannot be undone.')) return;
        if (!confirm('Are you absolutely sure? This will permanently remove all order data.')) return;
        var fd = new FormData();
        fd.append('ms_action', 'delete_order');
        fd.append('csrf_token', PS_BOOT.csrfToken || '');
        fd.append('order_id', orderId);
        psFetch(storeApiUrl, {method:'POST', body:fd})
            .then(function(r){return r.json()})
            .then(function(d) {
                if (d.ok) {
                    _allOrders = _allOrders.filter(function(o) { return o.order_id !== orderId; });
                    delete _selectedOrderIds[orderId];
                    renderOrders();
                    showToast('Order deleted', 'success');
                } else {
                    showToast(d.error || 'Failed to delete order', 'error');
                }
            })
            .catch(function() { showToast('Failed to delete order', 'error'); });
    }

    // ── Bulk actions ──
    function applyBulkOrderAction() {
        var action = document.getElementById('ps-order-bulk-action').value;
        if (!action) { showToast('Select a bulk action first', 'error'); return; }
        var ids = getSelectedOrderIds();
        if (!ids.length) { showToast('No orders selected', 'error'); return; }
        var labels = {archive_selected:'Archive',delete_selected:'Delete',mark_completed:'mark as Completed',mark_processing:'mark as Processing'};
        if (!confirm(labels[action] + ' ' + ids.length + ' order(s)?')) return;
        if (action === 'delete_selected' && !confirm('This will permanently delete ' + ids.length + ' order(s). Are you sure?')) return;

        var fd = new FormData();
        fd.append('ms_action', 'bulk_orders');
        fd.append('csrf_token', PS_BOOT.csrfToken || '');
        fd.append('bulk_action', action);
        fd.append('order_ids', JSON.stringify(ids));
        psFetch(storeApiUrl, {method:'POST', body:fd})
            .then(function(r){return r.json()})
            .then(function(d) {
                if (d.ok) {
                    // Update local data
                    if (action === 'delete_selected') {
                        var idSet = {};
                        ids.forEach(function(id){idSet[id]=true});
                        _allOrders = _allOrders.filter(function(o){return !idSet[o.order_id]});
                    } else if (action === 'archive_selected') {
                        _allOrders.forEach(function(o){if(ids.indexOf(o.order_id)!==-1) o.status='archived'});
                    } else if (action === 'mark_completed') {
                        _allOrders.forEach(function(o){if(ids.indexOf(o.order_id)!==-1) o.status='completed'});
                    } else if (action === 'mark_processing') {
                        _allOrders.forEach(function(o){if(ids.indexOf(o.order_id)!==-1) o.status='processing'});
                    }
                    _selectedOrderIds = {};
                    document.getElementById('ps-order-bulk-action').value = '';
                    renderOrders();
                    showToast(d.message || 'Bulk action completed', 'success');
                } else {
                    showToast(d.error || 'Bulk action failed', 'error');
                }
            })
            .catch(function() { showToast('Bulk action failed', 'error'); });
    }

    // ── CSV export ──
    function exportOrdersCsv() {
        var orders = sortOrdersArray(getFilteredOrders());
        if (!orders.length) { showToast('No orders to export', 'error'); return; }
        var rows = [['Order ID','Date','Customer Name','Customer Email','Phone','Street','City','State','Zip','Items','Item Count','Subtotal','Tax','Shipping','Total','Payment Method','Payment ID','Status']];
        orders.forEach(function(o) {
            var cust = o.customer || {};
            var addr = cust.address || {};
            var itemNames = (o.items || []).map(function(i){return i.name + ' x' + i.quantity}).join('; ');
            var itemCount = (o.items || []).reduce(function(s,i){return s+(i.quantity||0)},0);
            rows.push([
                o.order_id || '', o.created_at || '', cust.name || '', cust.email || '', cust.phone || '',
                addr.street || '', addr.city || '', addr.state || '', addr.zip || '',
                itemNames, itemCount,
                (o.subtotal || 0).toFixed(2), (o.tax || 0).toFixed(2), (o.shipping || 0).toFixed(2), (o.total || 0).toFixed(2),
                o.payment_method || '', o.payment_id || '', o.status || ''
            ]);
        });
        var csv = rows.map(function(r){return r.map(function(c){return '"' + String(c).replace(/"/g,'""') + '"'}).join(',')}).join('\n');
        var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'orders-export-' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('Exported ' + orders.length + ' orders', 'success');
    }

    // ── Helpers ──
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
    function escAttr(s) {
        return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

    // Legacy global references (keep backwards compat)
    window.psResendInvoice = resendInvoice;
    window.psPrintOrder = printOrder;

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.ps-modal.show').forEach(function(m) { m.classList.remove('show'); });
            var bg = document.getElementById('ps-bg-customizer');
            if (bg) bg.classList.add('ps-hidden');
        }
        // Ctrl+S / Cmd+S — save settings
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveSettings();
            showToast('Settings saved', 'success');
        }
    });
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) { e.preventDefault(); e.returnValue = ''; }
    });

    // ========================================================================
    // Shipping settings (Shipping tab)
    // ========================================================================
    function shippingTierRowHtml(qty, rate) {
        return '<tr class="ps-ship-tier-row">' +
            '<td style="padding:4px 6px"><input type="number" min="1" step="1" class="ps-tier-qty ps-form-input" value="' + (qty || '') + '" onchange="PS.markUnsaved()" placeholder="1"></td>' +
            '<td style="padding:4px 6px"><input type="number" min="0" step="0.01" class="ps-tier-rate ps-form-input" value="' + (rate || '') + '" onchange="PS.markUnsaved()" placeholder="6.95"></td>' +
            '<td style="padding:4px 6px;text-align:center"><button type="button" class="ps-btn-icon" title="Remove" onclick="PS.removeShippingTier(this)" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:1rem">&times;</button></td>' +
            '</tr>';
    }
    function addShippingTier() {
        var tb = document.getElementById('ps-ship-tier-rows');
        if (!tb) return;
        tb.insertAdjacentHTML('beforeend', shippingTierRowHtml('', ''));
        markUnsaved();
    }
    function removeShippingTier(btn) {
        var row = btn.closest('.ps-ship-tier-row');
        if (row) { row.remove(); markUnsaved(); }
    }
    function shippingModeChanged() {
        var mode = (document.getElementById('ps-shippingMode') || {}).value;
        var flatWrap = document.getElementById('ps-ship-flat-wrap');
        var tiersWrap = document.getElementById('ps-ship-tiers-wrap');
        if (flatWrap) flatWrap.style.display = (mode === 'per_quantity') ? 'none' : '';
        if (tiersWrap) tiersWrap.style.display = (mode === 'per_quantity') ? '' : 'none';
    }
    function shippingAboveMaxChanged() {
        var v = (document.getElementById('ps-shippingAboveMax') || {}).value;
        var w = document.getElementById('ps-ship-peradd-wrap');
        if (w) w.style.display = (v === 'increment') ? '' : 'none';
    }
    function shippingFreeChanged() {
        var on = (document.getElementById('ps-shippingFreeEnabled') || {}).checked;
        var type = (document.getElementById('ps-shippingFreeType') || {}).value;
        var wrap = document.getElementById('ps-ship-free-wrap');
        var threshWrap = document.getElementById('ps-ship-freethresh-wrap');
        if (wrap) wrap.style.display = on ? '' : 'none';
        if (threshWrap) threshWrap.style.display = (on && type === 'threshold') ? '' : 'none';
    }
    // Build the nested `shipping` settings object from the Shipping tab DOM.
    function collectShippingBlock(flat) {
        var tiers = [];
        document.querySelectorAll('#ps-ship-tier-rows .ps-ship-tier-row').forEach(function(row) {
            var qEl = row.querySelector('.ps-tier-qty'), rEl = row.querySelector('.ps-tier-rate');
            var q = qEl ? parseInt(qEl.value, 10) : NaN;
            var r = rEl ? parseFloat(rEl.value) : NaN;
            if (!isNaN(q) && q >= 1 && !isNaN(r) && r >= 0) {
                tiers.push({ qty: q, rate: Math.round(r * 100) / 100 });
            }
        });
        var r2 = function(n){ return Math.round((parseFloat(n) || 0) * 100) / 100; };
        return {
            mode: flat.shippingMode || 'flat',
            flatRate: r2(flat.shippingFlatRate),
            tiers: tiers,
            aboveMax: flat.shippingAboveMax || 'cap',
            perAdditional: r2(flat.shippingPerAdditional),
            freeShipping: {
                enabled: !!flat.shippingFreeEnabled,
                type: flat.shippingFreeType || 'threshold',
                threshold: parseFloat(flat.shippingFreeThreshold) || 0
            }
        };
    }

    // ========================================================================
    // Public API (window.PS)
    // ========================================================================
    window.PS = {
        addShippingTier: addShippingTier,
        removeShippingTier: removeShippingTier,
        shippingModeChanged: shippingModeChanged,
        shippingAboveMaxChanged: shippingAboveMaxChanged,
        shippingFreeChanged: shippingFreeChanged,
        setView: setView,
        showToast: showToast,
        showModal: showModal,
        closeModal: closeModal,
        showAddProductModal: showAddProductModal,
        editProduct: editProduct,
        saveProduct: saveProduct,
        deleteProduct: deleteProduct,
        duplicateProduct: duplicateProduct,
        toggleProduct: toggleProduct,
        bulkEnable: bulkEnable,
        bulkDisable: bulkDisable,
        toggleSelectAllProducts: toggleSelectAllProducts,
        onProductSelectChange: onProductSelectChange,
        bulkDeleteSelected: bulkDeleteSelected,
        toggleLayoutControls: toggleLayoutControls,
        updateGridLayout: updateGridLayout,
        filterProducts: filterProducts,
        addVariationGroup: addVariationGroup,
        addVariationOption: addVariationOption,
        removeVariationOption: removeVariationOption,
        removeVariationGroup: removeVariationGroup,
        varOptionHtml: varOptionHtml,
        updateVariantFinals: updateVariantFinals,
        handleFileSelect: handleFileSelect,
        clearImagePreview: clearImagePreview,
        uploadMainImage: uploadMainImage,
        uploadGalleryImages: uploadGalleryImages,
        setAsMain: setAsMain,
        removeGalleryImage: removeGalleryImage,
        showSettingsModal: showSettingsModal,
        showImportModal: showImportModal,
        markUnsaved: markUnsaved,
        showTab: showTab,
        saveSettings: saveSettings,
        exportSettings: exportSettings,
        importSettings: importSettings,
        resetAllSettings: resetAllSettings,
        livePreviewColors: livePreviewColors,
        toggleBgCustomizer: toggleBgCustomizer,
        updateBgColor: updateBgColor,
        updateBgOpacity: updateBgOpacity,
        updateBgBlur: updateBgBlur,
        resetBackground: resetBackground,
        // Orders management
        ordersFilterChanged: ordersFilterChanged,
        sortOrders: sortOrders,
        renderOrders: renderOrders,
        goToOrdersPage: goToOrdersPage,
        toggleOrderDetail: toggleOrderDetail,
        toggleSelectAllOrders: toggleSelectAllOrders,
        orderCheckboxChanged: orderCheckboxChanged,
        resendInvoice: resendInvoice,
        printOrder: printOrder,
        updateOrderStatus: updateOrderStatus,
        archiveOrder: archiveOrder,
        deleteOrder: deleteOrder,
        applyBulkOrderAction: applyBulkOrderAction,
        exportOrdersCsv: exportOrdersCsv
    };
})();
