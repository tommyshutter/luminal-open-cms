<?php
/**
 * PayPal Shopping Cart System - Add/Edit Product Modal
 * Complete product management interface matching React functionality
 * 
 * File: /admin/paypal/views/paypal-add-product-modal.php
 * Version: 2025.10.03.0945 r1
 * Build Date: Friday, October 3, 2025 - 9:45 AM EST
 * Purpose: Full-featured product add/edit modal
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}
?>

<!-- Add Product Modal -->
<div id="add-product-modal" class="modal" style="display: none;">
    <div class="modal-content glass-modal" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <!-- Modal Header -->
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem; border-bottom: 1px solid var(--border);">
            <h2 id="modal-title" style="margin: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--foreground);">
                <i class="fas fa-plus"></i>
                Add New Product
            </h2>
            <button class="btn btn-ghost" onclick="closeAddProductModal()" style="padding: 0.5rem; min-width: auto;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="modal-body" style="padding: 1.5rem;">
            <form id="product-form">
                <input type="hidden" id="product-mode" value="add">
                <input type="hidden" id="original-sku" value="">
                
                <!-- Basic Information -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin: 0 0 1rem 0; font-size: 1.125rem; font-weight: 500;">Basic Information</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="product-sku" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">SKU *</label>
                            <input type="text" id="product-sku" name="sku" required class="form-input" placeholder="Unique product identifier">
                            <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">Must be unique</p>
                        </div>
                        <div>
                            <label for="product-name" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Product Name *</label>
                            <input type="text" id="product-name" name="name" required class="form-input" placeholder="Product name">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label for="product-price" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Price *</label>
                            <input type="number" id="product-price" name="price" step="0.01" min="0" required class="form-input" placeholder="0.00">
                        </div>
                        <div>
                            <label for="product-category" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category</label>
                            <input type="text" id="product-category" name="category" class="form-input" placeholder="Product category">
                        </div>
                        <div>
                            <label for="product-enabled" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-top: 1.5rem;">
                                <input type="checkbox" id="product-enabled" name="enabled" checked style="margin: 0;">
                                <span>Enabled</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label for="product-description" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Description</label>
                        <textarea id="product-description" name="desc" rows="3" class="form-input" placeholder="Product description"></textarea>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label for="product-image" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Main Image URL</label>
                        <input type="url" id="product-image" name="image" class="form-input" placeholder="https://example.com/image.jpg">
                        <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">Enter image URL or upload to /media/store/{SKU}/</p>
                    </div>
                </div>

                <!-- Product Images Section -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin: 0 0 1rem 0; font-size: 1.125rem; font-weight: 500;">Product Images</h3>
                    
                    <!-- Drag & Drop Upload Area -->
                    <div id="mini-gallery-upload" class="drag-drop-area" style="margin-bottom: 1rem;">
                        <i class="fas fa-cloud-upload-alt drag-drop-icon"></i>
                        <div>
                            <p class="drag-drop-text">
                                Drag and drop images here, or 
                                <button type="button" class="upload-button" onclick="document.getElementById('mini-gallery-input').click()">
                                    browse files
                                </button>
                            </p>
                            <p class="drag-drop-subtext">
                                Mini-Gallery: Max 5 images • Auto-optimized at 95% • Max 1024x1024px<br>
                                Saves as: <span id="mini-gallery-path-preview">sku.minigallery.001.jpg - 005.jpg</span>
                            </p>
                        </div>
                        <input id="mini-gallery-input" type="file" accept="image/*" multiple style="display: none;" onchange="handleMiniGalleryUpload(this)">
                    </div>

                    <!-- Main Image Preview -->
                    <div id="image-preview" style="display: none; margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--muted-foreground);">Main Product Image</label>
                        <div style="width: 150px; height: 150px; border-radius: var(--radius); overflow: hidden; background: var(--muted); border: 1px solid var(--border);">
                            <img id="preview-image" src="" alt="Main product image" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    </div>

                    <!-- Mini Gallery Management -->
                    <div id="mini-gallery-management" style="display: none;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                            <label style="font-size: 0.875rem; font-weight: 500;">Mini-Gallery Images (<span id="gallery-count">0</span>/5)</label>
                            <button type="button" class="btn btn-outline btn-sm" onclick="clearMiniGallery()" style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-trash"></i>
                                Clear All
                            </button>
                        </div>
                        <p style="font-size: 0.75rem; color: var(--muted-foreground); margin-bottom: 1rem;">
                            Path: /media/store/<span id="sku-path-display">sku</span>/images/<span id="sku-path-display-2">sku</span>.minigallery.00x.jpg
                        </p>
                        
                        <!-- Mini Gallery Grid -->
                        <div class="gallery-grid" id="mini-gallery-grid">
                            <!-- Gallery items will be added here dynamically -->
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="testImageUrl()" style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-eye"></i>
                            Test Main Image
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="clearImage()" style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-times"></i>
                            Clear Main
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="setMainImageFromGallery()" style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-star"></i>
                            Set from Gallery
                        </button>
                    </div>
                </div>

                <!-- Product Variations -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin: 0 0 1rem 0; font-size: 1.125rem; font-weight: 500;">Product Variations (Optional)</h3>
                    
                    <div style="background: var(--muted); border-radius: 0.5rem; padding: 1rem;">
                        <p style="margin: 0 0 1rem 0; font-size: 0.875rem; color: var(--muted-foreground);">
                            Add variations like sizes, colors, etc. Leave empty if product has no variations.
                        </p>
                        
                        <div id="variations-container">
                            <!-- Variations will be added here dynamically -->
                        </div>
                        
                        <button type="button" class="btn btn-outline btn-sm" onclick="addVariationGroup()" style="display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-plus"></i>
                            Add Variation Group
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="display: flex; gap: 0.75rem; padding: 1.5rem; border-top: 1px solid var(--border);">
            <button type="button" class="btn btn-primary" onclick="saveProduct()" id="save-product-btn" style="display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-save"></i>
                <span id="save-btn-text">Add Product</span>
            </button>
            <button type="button" class="btn btn-outline" onclick="closeAddProductModal()">
                Cancel
            </button>
        </div>
    </div>
</div>

<style>
.variation-group {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
    background: var(--background);
}

.variation-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.variation-option input {
    flex: 1;
}

.form-input {
    width: 100%;
    padding: 0.75rem;
    background: var(--input-background);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--foreground);
    font-size: 0.875rem;
}

.form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
}
</style>

<script>
let variationGroupCount = 0;

function showAddProductModal() {
    resetProductForm();
    document.getElementById('modal-title').innerHTML = '<i class="fas fa-plus"></i> Add New Product';
    document.getElementById('save-btn-text').textContent = 'Add Product';
    document.getElementById('product-mode').value = 'add';
    showModal('add-product-modal');
}

function showEditProductModal(sku) {
    resetProductForm();
    document.getElementById('modal-title').innerHTML = '<i class="fas fa-edit"></i> Edit Product';
    document.getElementById('save-btn-text').textContent = 'Update Product';
    document.getElementById('product-mode').value = 'edit';
    document.getElementById('original-sku').value = sku;
    
    // Load product data
    loadProductData(sku);
    showModal('add-product-modal');
}

function closeAddProductModal() {
    hideModal('add-product-modal');
    resetProductForm();
}

function resetProductForm() {
    document.getElementById('product-form').reset();
    document.getElementById('product-enabled').checked = true;
    document.getElementById('image-preview').style.display = 'none';
    document.getElementById('variations-container').innerHTML = '';
    variationGroupCount = 0;
}

function loadProductData(sku) {
    // This would typically load from the server
    // For now, we'll use a placeholder
    fetch('?action=get_product&sku=' + encodeURIComponent(sku))
        .then(response => response.json())
        .then(product => {
            if (product.error) {
                showToast('Product not found', 'error');
                return;
            }
            
            document.getElementById('product-sku').value = product.sku;
            document.getElementById('product-name').value = product.name || '';
            document.getElementById('product-price').value = product.price || '';
            document.getElementById('product-category').value = product.category || '';
            document.getElementById('product-description').value = product.desc || '';
            document.getElementById('product-image').value = product.image || '';
            document.getElementById('product-enabled').checked = product.enabled !== false;
            
            if (product.image) {
                testImageUrl();
            }
            
            // Load variations if they exist
            if (product.variations && product.variations.groups) {
                loadVariations(product.variations.groups);
            }
        })
        .catch(error => {
            console.error('Error loading product:', error);
            showToast('Failed to load product data', 'error');
        });
}

function loadVariations(groups) {
    const container = document.getElementById('variations-container');
    container.innerHTML = '';
    variationGroupCount = 0;
    
    groups.forEach(group => {
        addVariationGroup(group);
    });
}

function testImageUrl() {
    const imageUrl = document.getElementById('product-image').value;
    if (!imageUrl) {
        showToast('Please enter an image URL first', 'error');
        return;
    }
    
    const img = new Image();
    img.onload = function() {
        document.getElementById('preview-image').src = imageUrl;
        document.getElementById('image-preview').style.display = 'block';
        showToast('Image loaded successfully', 'success');
    };
    img.onerror = function() {
        document.getElementById('image-preview').style.display = 'none';
        showToast('Failed to load image. Please check the URL.', 'error');
    };
    img.src = imageUrl;
}

function clearImage() {
    document.getElementById('product-image').value = '';
    document.getElementById('image-preview').style.display = 'none';
}

function addVariationGroup(existingGroup = null) {
    variationGroupCount++;
    const container = document.getElementById('variations-container');
    
    const groupDiv = document.createElement('div');
    groupDiv.className = 'variation-group';
    groupDiv.id = `variation-group-${variationGroupCount}`;
    
    const groupName = existingGroup ? existingGroup.name : '';
    const groupOptions = existingGroup ? existingGroup.options : [''];
    
    let optionsHtml = '';
    groupOptions.forEach((option, index) => {
        const optVal = (typeof option === 'object' && option !== null) ? (option.value || '') : option;
        const optPrice = (typeof option === 'object' && option !== null && option.price) ? option.price : '';
        optionsHtml += `
            <div class="variation-option">
                <input type="text" placeholder="Option value" value="${optVal}"
                       onchange="updateVariationData()" class="form-input" style="flex:2">
                <input type="number" placeholder="Price (optional)" value="${optPrice}" step="0.01" min="0"
                       onchange="updateVariationData()" class="form-input variation-price" style="flex:1;max-width:120px">
                <button type="button" class="btn btn-ghost btn-sm" onclick="removeVariationOption(this)"
                        style="padding: 0.25rem; min-width: auto;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
    });
    
    groupDiv.innerHTML = `
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <input type="text" placeholder="Variation name (e.g., Size, Color)" value="${groupName}"
                   onchange="updateVariationData()" class="form-input" style="flex: 1; margin-right: 1rem;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="removeVariationGroup(${variationGroupCount})"
                    style="padding: 0.25rem; min-width: auto;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="variation-options">
            ${optionsHtml}
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="addVariationOption(${variationGroupCount})"
                style="display: flex; align-items: center; gap: 0.25rem;">
            <i class="fas fa-plus"></i>
            Add Option
        </button>
    `;
    
    container.appendChild(groupDiv);
}

function addVariationOption(groupId) {
    const group = document.getElementById(`variation-group-${groupId}`);
    const optionsContainer = group.querySelector('.variation-options');
    
    const optionDiv = document.createElement('div');
    optionDiv.className = 'variation-option';
    optionDiv.innerHTML = `
        <input type="text" placeholder="Option value" onchange="updateVariationData()" class="form-input" style="flex:2">
        <input type="number" placeholder="Price (optional)" step="0.01" min="0"
               onchange="updateVariationData()" class="form-input variation-price" style="flex:1;max-width:120px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="removeVariationOption(this)"
                style="padding: 0.25rem; min-width: auto;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    optionsContainer.appendChild(optionDiv);
}

function removeVariationOption(button) {
    button.parentElement.remove();
    updateVariationData();
}

function removeVariationGroup(groupId) {
    const group = document.getElementById(`variation-group-${groupId}`);
    if (group) {
        group.remove();
        updateVariationData();
    }
}

function updateVariationData() {
    // This function can be used to update variation data in real-time
    // For now, it's just a placeholder
}

function getVariationsData() {
    const container = document.getElementById('variations-container');
    const groups = [];
    
    container.querySelectorAll('.variation-group').forEach(group => {
        const nameInput = group.querySelector('input[type="text"]');
        const optionRows = group.querySelectorAll('.variation-option');

        if (nameInput.value.trim()) {
            const options = [];
            optionRows.forEach(row => {
                const valInput = row.querySelector('input[type="text"]');
                const priceInput = row.querySelector('input.variation-price');
                if (valInput && valInput.value.trim()) {
                    const price = priceInput && priceInput.value !== '' ? parseFloat(priceInput.value) : null;
                    options.push({ value: valInput.value.trim(), price: price });
                }
            });

            if (options.length > 0) {
                groups.push({
                    name: nameInput.value.trim(),
                    options: options
                });
            }
        }
    });
    
    return { groups: groups };
}

function saveProduct() {
    const form = document.getElementById('product-form');
    const formData = new FormData(form);
    const mode = document.getElementById('product-mode').value;
    
    // Validate required fields
    const sku = document.getElementById('product-sku').value.trim();
    const name = document.getElementById('product-name').value.trim();
    const price = document.getElementById('product-price').value;
    
    if (!sku || !name || !price) {
        showToast('Please fill in all required fields', 'error');
        return;
    }
    
    // Prepare product data
    const productData = {
        sku: sku,
        name: name,
        price: parseFloat(price),
        category: document.getElementById('product-category').value.trim(),
        desc: document.getElementById('product-description').value.trim(),
        image: document.getElementById('product-image').value.trim(),
        enabled: document.getElementById('product-enabled').checked,
        variations: getVariationsData(),
        mtime: Date.now()
    };
    
    if (mode === 'add') {
        productData.created = Date.now();
    }
    
    // Send to server
    const actionData = new FormData();
    actionData.append('action', mode === 'add' ? 'add_product' : 'update_product');
    actionData.append('product_data', JSON.stringify(productData));
    
    if (mode === 'edit') {
        actionData.append('original_sku', document.getElementById('original-sku').value);
    }
    
    document.getElementById('save-product-btn').disabled = true;
    
    fetch('', {
        method: 'POST',
        body: actionData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('save-product-btn').disabled = false;
        
        if (data.success) {
            showToast(data.message || (mode === 'add' ? 'Product added successfully' : 'Product updated successfully'), 'success');
            closeAddProductModal();
            
            // Refresh the page to show updated product list
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message || 'Failed to save product', 'error');
        }
    })
    .catch(error => {
        document.getElementById('save-product-btn').disabled = false;
        console.error('Error saving product:', error);
        showToast('Failed to save product', 'error');
    });
}

// Mini Gallery Management
let miniGalleryImages = [];
let selectedGalleryImageIndex = 0;

function handleMiniGalleryUpload(input) {
    const files = Array.from(input.files);
    const imageFiles = files.filter(file => file.type.startsWith('image/'));
    
    if (imageFiles.length === 0) {
        showToast('Please select valid image files', 'error');
        return;
    }
    
    // Limit to 5 images total
    const remainingSlots = 5 - miniGalleryImages.length;
    const filesToProcess = imageFiles.slice(0, remainingSlots);
    
    if (filesToProcess.length < imageFiles.length) {
        showToast(`Only ${filesToProcess.length} images added. Maximum 5 images allowed.`, 'warning');
    }
    
    // Process each file
    filesToProcess.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const sku = document.getElementById('product-sku').value || 'new-product';
            const imageNum = (miniGalleryImages.length + 1).toString().padStart(3, '0');
            
            miniGalleryImages.push({
                file: file,
                url: e.target.result,
                name: `${sku}.minigallery.${imageNum}.jpg`
            });
            
            updateMiniGalleryDisplay();
            updateSkuPathDisplay();
        };
        reader.readAsDataURL(file);
    });
    
    // Clear input
    input.value = '';
}

function updateMiniGalleryDisplay() {
    const container = document.getElementById('mini-gallery-grid');
    const management = document.getElementById('mini-gallery-management');
    const counter = document.getElementById('gallery-count');
    
    if (miniGalleryImages.length === 0) {
        management.style.display = 'none';
        return;
    }
    
    management.style.display = 'block';
    counter.textContent = miniGalleryImages.length;
    
    container.innerHTML = '';
    
    miniGalleryImages.forEach((image, index) => {
        const item = document.createElement('div');
        item.className = `gallery-item ${selectedGalleryImageIndex === index ? 'selected' : ''}`;
        item.onclick = () => selectGalleryImage(index);
        
        item.innerHTML = `
            <img src="${image.url}" alt="${image.name}">
            <button type="button" class="gallery-item-remove" onclick="event.stopPropagation(); removeMiniGalleryImage(${index})">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(item);
    });
}

function selectGalleryImage(index) {
    selectedGalleryImageIndex = index;
    updateMiniGalleryDisplay();
}

function removeMiniGalleryImage(index) {
    miniGalleryImages.splice(index, 1);
    
    // Adjust selected index if necessary
    if (selectedGalleryImageIndex >= index && selectedGalleryImageIndex > 0) {
        selectedGalleryImageIndex--;
    }
    
    updateMiniGalleryDisplay();
    updateSkuPathDisplay();
    
    showToast('Image removed from mini-gallery', 'success');
}

function clearMiniGallery() {
    if (miniGalleryImages.length === 0) return;
    
    if (confirm('Clear all mini-gallery images?')) {
        miniGalleryImages = [];
        selectedGalleryImageIndex = 0;
        updateMiniGalleryDisplay();
        showToast('Mini-gallery cleared', 'success');
    }
}

function setMainImageFromGallery() {
    if (miniGalleryImages.length === 0) {
        showToast('No images in mini-gallery', 'error');
        return;
    }
    
    const selectedImage = miniGalleryImages[selectedGalleryImageIndex];
    const sku = document.getElementById('product-sku').value || 'new-product';
    const mainImagePath = `/media/store/${sku}/images/${sku}.jpg`;
    
    document.getElementById('product-image').value = mainImagePath;
    testImageUrl();
    
    showToast('Main image set from gallery', 'success');
}

function updateSkuPathDisplay() {
    const sku = document.getElementById('product-sku').value || 'sku';
    
    // Update path displays
    document.getElementById('sku-path-display').textContent = sku;
    document.getElementById('sku-path-display-2').textContent = sku;
    document.getElementById('mini-gallery-path-preview').textContent = `${sku}.minigallery.001.jpg - 005.jpg`;
}

// Update SKU path display when SKU changes
document.getElementById('product-sku').addEventListener('input', function() {
    updateSkuPathDisplay();
});

// Drag and drop functionality for mini-gallery upload area
const uploadArea = document.getElementById('mini-gallery-upload');

uploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadArea.classList.add('active');
});

uploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadArea.classList.remove('active');
});

uploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    e.stopPropagation();
    uploadArea.classList.remove('active');
    
    const files = Array.from(e.dataTransfer.files);
    const input = document.getElementById('mini-gallery-input');
    
    // Create a new FileList
    const dt = new DataTransfer();
    files.forEach(file => dt.items.add(file));
    input.files = dt.files;
    
    handleMiniGalleryUpload(input);
});

// Make functions globally accessible
window.showAddProductModal = showAddProductModal;
window.showEditProductModal = showEditProductModal;
window.closeAddProductModal = closeAddProductModal;
window.testImageUrl = testImageUrl;
window.clearImage = clearImage;
window.addVariationGroup = addVariationGroup;
window.addVariationOption = addVariationOption;
window.removeVariationOption = removeVariationOption;
window.removeVariationGroup = removeVariationGroup;
window.saveProduct = saveProduct;
window.handleMiniGalleryUpload = handleMiniGalleryUpload;
window.clearMiniGallery = clearMiniGallery;
window.setMainImageFromGallery = setMainImageFromGallery;
</script>