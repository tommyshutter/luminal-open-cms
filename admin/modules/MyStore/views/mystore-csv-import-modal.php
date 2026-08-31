<?php
/**
 * PayPal Shopping Cart System - Complete CSV Import Modal
 * 100% React PayPalImporter.tsx Feature Parity
 * 
 * File: /admin/paypal/views/paypal-csv-import-modal.php
 * Version: 2025.10.03.0945 r1
 * Build Date: Friday, October 3, 2025 - 9:45 AM EST
 * Purpose: Full-featured CSV import with all React features
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}
?>

<!-- CSV Import Modal -->
<div id="csv-import-modal" class="modal" style="display: none;">
    <div class="modal-content glass-modal" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <!-- Modal Header -->
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem; border-bottom: 1px solid var(--border);">
            <h2 style="margin: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--foreground);">
                <i class="fas fa-upload"></i>
                Import Products from CSV
            </h2>
            <button class="btn btn-ghost" onclick="closeCsvImportModal()" style="padding: 0.5rem; min-width: auto;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="modal-body" style="padding: 1.5rem;">
            <!-- Initial Upload View -->
            <div id="upload-view">
                <!-- Instructions -->
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">CSV Format Requirements</h3>
                    <div style="font-size: 0.875rem; color: var(--muted-foreground);">
                        <p style="margin-bottom: 0.5rem;">Your CSV file should include the following columns:</p>
                        <ul style="margin: 0 0 0 1.5rem; padding: 0;">
                            <li><strong>SKU</strong> (required) - Unique product identifier</li>
                            <li><strong>Name/Title</strong> (required) - Product name</li>
                            <li><strong>Price</strong> (required) - Product price (numbers only)</li>
                            <li><strong>Category</strong> (optional) - Product category</li>
                            <li><strong>Description</strong> (optional) - Product description</li>
                            <li><strong>Image</strong> (optional) - Image URL or path</li>
                            <li><strong>Enabled</strong> (optional) - true/false, yes/no, 1/0</li>
                        </ul>
                    </div>
                </div>

                <!-- Sample Download -->
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; background: rgba(255, 255, 255, 0.05); border-radius: 0.5rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-file-csv" style="color: var(--primary); font-size: 1.25rem;"></i>
                        <span style="font-size: 0.875rem;">Need a template?</span>
                    </div>
                    <button
                        class="btn btn-outline btn-sm"
                        onclick="downloadSampleCSV()"
                        style="display: flex; align-items: center; gap: 0.5rem;"
                    >
                        <i class="fas fa-download"></i>
                        Download Sample CSV
                    </button>
                </div>

                <!-- Upload Area -->
                <div
                    id="drop-zone"
                    class="upload-drop-zone"
                    style="border: 2px dashed var(--border); border-radius: 0.5rem; padding: 2rem; text-align: center; transition: all 0.3s; cursor: pointer;"
                    ondragover="handleDragOver(event)"
                    ondragleave="handleDragLeave(event)"
                    ondrop="handleDrop(event)"
                    onclick="document.getElementById('file-input').click()"
                >
                    <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--muted-foreground); margin-bottom: 1rem; display: block;"></i>
                    <div style="margin-bottom: 0.5rem;">
                        <p style="font-size: 1.125rem; font-weight: 500; margin: 0;">
                            Drop your CSV file here
                        </p>
                        <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0.5rem 0;">
                            or <span style="color: var(--primary);">browse files</span>
                        </p>
                        <p style="font-size: 0.75rem; color: var(--muted-foreground); margin: 0;">
                            Supports CSV files only
                        </p>
                    </div>
                </div>
                
                <input type="file" id="file-input" accept=".csv,text/csv" onchange="handleFileSelect(event)" style="display: none;">

                <!-- Progress -->
                <div id="import-progress" style="display: none; margin-top: 1.5rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.875rem;">Processing CSV...</span>
                        <span id="progress-text" style="font-size: 0.875rem;">0%</span>
                    </div>
                    <div style="width: 100%; background: var(--muted); border-radius: 9999px; height: 8px;">
                        <div id="progress-bar" style="background: var(--primary); height: 100%; border-radius: 9999px; width: 0%; transition: width 0.3s;"></div>
                    </div>
                </div>
            </div>

            <!-- Results View -->
            <div id="results-view" style="display: none;">
                <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Import Results</h3>
                
                <!-- Summary Stats -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="text-align: center; padding: 1rem; background: rgba(34, 197, 94, 0.2); border-radius: 0.5rem;">
                        <div id="products-count" style="font-size: 1.5rem; font-weight: bold; color: #22c55e;">0</div>
                        <div style="font-size: 0.875rem; color: var(--muted-foreground);">Products</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: rgba(239, 68, 68, 0.2); border-radius: 0.5rem;">
                        <div id="errors-count" style="font-size: 1.5rem; font-weight: bold; color: #ef4444;">0</div>
                        <div style="font-size: 0.875rem; color: var(--muted-foreground);">Errors</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: rgba(245, 158, 11, 0.2); border-radius: 0.5rem;">
                        <div id="warnings-count" style="font-size: 1.5rem; font-weight: bold; color: #f59e0b;">0</div>
                        <div style="font-size: 0.875rem; color: var(--muted-foreground);">Warnings</div>
                    </div>
                </div>

                <!-- Errors Section -->
                <div id="errors-section" style="display: none; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; color: #ef4444;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span style="font-weight: 500;">Errors</span>
                    </div>
                    <div id="errors-list" style="max-height: 8rem; overflow-y: auto;"></div>
                </div>

                <!-- Warnings Section -->
                <div id="warnings-section" style="display: none; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; color: #f59e0b;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span style="font-weight: 500;">Warnings</span>
                    </div>
                    <div id="warnings-list"></div>
                </div>

                <!-- Products Preview -->
                <div id="products-section" style="display: none;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; color: #22c55e;">
                        <i class="fas fa-check-circle"></i>
                        <span style="font-weight: 500;">Products to Import</span>
                    </div>
                    <div id="products-list" style="max-height: 12rem; overflow-y: auto;"></div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="display: flex; gap: 0.75rem; padding: 1.5rem; border-top: 1px solid var(--border);">
            <div id="upload-actions" style="display: flex; gap: 0.75rem; width: 100%;">
                <button type="button" class="btn btn-outline" onclick="closeCsvImportModal()" style="margin-left: auto;">
                    Cancel
                </button>
            </div>
            <div id="results-actions" style="display: none; gap: 0.75rem; width: 100%;">
                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="confirmImport()"
                    id="import-btn"
                    style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;"
                    disabled
                >
                    <i class="fas fa-upload"></i>
                    <span id="import-btn-text">Import 0 Products</span>
                </button>
                <button type="button" class="btn btn-outline" onclick="resetImport()">
                    Try Again
                </button>
                <button type="button" class="btn btn-outline" onclick="closeCsvImportModal()">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.upload-drop-zone.drag-active {
    border-color: var(--primary);
    background: rgba(251, 191, 36, 0.1);
}

.upload-drop-zone:hover {
    border-color: rgba(251, 191, 36, 0.5);
}

.error-item {
    font-size: 0.875rem;
    color: #ef4444;
    background: rgba(239, 68, 68, 0.1);
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 0.25rem;
}

.warning-item {
    font-size: 0.875rem;
    color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 0.25rem;
}

.product-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 0.25rem;
    margin-bottom: 0.5rem;
}

.product-item-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
    border: 1px solid var(--border);
    background: transparent;
}
</style>

<script>
let importInProgress = false;
let importResult = null;
let dragActive = false;

// File handling
function handleDragOver(e) {
    e.preventDefault();
    e.stopPropagation();
    if (!dragActive) {
        dragActive = true;
        document.getElementById('drop-zone').classList.add('drag-active');
    }
}

function handleDragLeave(e) {
    e.preventDefault();
    e.stopPropagation();
    if (e.target === document.getElementById('drop-zone')) {
        dragActive = false;
        document.getElementById('drop-zone').classList.remove('drag-active');
    }
}

function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    dragActive = false;
    document.getElementById('drop-zone').classList.remove('drag-active');
    
    const files = Array.from(e.dataTransfer.files);
    const csvFiles = files.filter(file => 
        file.name.toLowerCase().endsWith('.csv') || 
        file.type === 'text/csv'
    );
    
    if (csvFiles.length > 0) {
        processFile(csvFiles[0]);
    } else {
        showToast('Please drop a CSV file', 'error');
    }
}

function handleFileSelect(e) {
    const files = Array.from(e.target.files);
    if (files.length > 0) {
        processFile(files[0]);
    }
}

function downloadSampleCSV() {
    const sampleData = [
        ['SKU', 'Name', 'Price', 'Category', 'Description', 'Image', 'Enabled'],
        ['hat-001', 'Classic Baseball Cap', '24.99', 'Hats', 'Comfortable cotton baseball cap', '/media/store/hat-001/main.jpg', 'true'],
        ['shirt-001', 'Premium T-Shirt', '19.99', 'T-Shirts', 'Soft cotton t-shirt with premium fit', '/media/store/shirt-001/main.jpg', 'true'],
        ['mug-001', 'Coffee Mug', '12.99', 'Mugs', 'Ceramic coffee mug with handle', '/media/store/mug-001/main.jpg', 'false']
    ];

    const csvContent = sampleData.map(row => 
        row.map(cell => `"${cell}"`).join(',')
    ).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sample-products.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    showToast('Sample CSV downloaded', 'success');
}

// File processing
async function processFile(file) {
    importInProgress = true;
    showProgress(true);
    updateProgress(0);
    
    try {
        const csvText = await file.text();
        const result = await processCSV(csvText);
        showResults(result);
    } catch (error) {
        console.error('Import error:', error);
        showToast('Failed to process CSV file', 'error');
        resetImport();
    } finally {
        importInProgress = false;
        showProgress(false);
    }
}

async function processCSV(csvText) {
    const lines = csvText.split('\n').filter(line => line.trim());
    
    if (lines.length < 2) {
        throw new Error('CSV file must contain at least a header row and one data row');
    }
    
    const headers = parseCSVLine(lines[0]);
    const dataRows = lines.slice(1);
    
    const result = {
        success: true,
        products: [],
        errors: [],
        warnings: []
    };
    
    // Find column indices
    const skuIndex = findColumnIndex(headers, ['sku']);
    const nameIndex = findColumnIndex(headers, ['name', 'title', 'product']);
    const priceIndex = findColumnIndex(headers, ['price', 'cost', 'amount']);
    const categoryIndex = findColumnIndex(headers, ['category', 'cat']);
    const descIndex = findColumnIndex(headers, ['description', 'desc', 'details']);
    const imageIndex = findColumnIndex(headers, ['image', 'photo', 'picture', 'img']);
    const enabledIndex = findColumnIndex(headers, ['enabled', 'active', 'status']);
    
    // Process each row
    for (let i = 0; i < dataRows.length; i++) {
        updateProgress(((i + 1) / dataRows.length) * 100);
        
        const row = parseCSVLine(dataRows[i]);
        const rowNumber = i + 2; // +2 because of header and 0-based index
        
        const productResult = validateRow(row, {
            skuIndex, nameIndex, priceIndex, categoryIndex, 
            descIndex, imageIndex, enabledIndex
        }, rowNumber);
        
        if (productResult.product) {
            result.products.push(productResult.product);
        }
        
        if (productResult.errors.length > 0) {
            result.errors.push(...productResult.errors);
        }
        
        // Small delay to show progress
        await new Promise(resolve => setTimeout(resolve, 10));
    }
    
    // Check for duplicate SKUs
    const skus = result.products.map(p => p.sku);
    const duplicates = skus.filter((sku, index) => skus.indexOf(sku) !== index);
    if (duplicates.length > 0) {
        result.warnings.push(`Duplicate SKUs found: ${duplicates.join(', ')}`);
    }
    
    return result;
}

function parseCSVLine(line) {
    const result = [];
    let current = '';
    let inQuotes = false;
    
    for (let i = 0; i < line.length; i++) {
        const char = line[i];
        
        if (char === '"') {
            if (inQuotes && line[i + 1] === '"') {
                current += '"';
                i++; // Skip next quote
            } else {
                inQuotes = !inQuotes;
            }
        } else if (char === ',' && !inQuotes) {
            result.push(current.trim());
            current = '';
        } else {
            current += char;
        }
    }
    
    result.push(current.trim());
    return result;
}

function findColumnIndex(headers, possibleNames) {
    for (const name of possibleNames) {
        const index = headers.findIndex(h => h.toLowerCase().includes(name.toLowerCase()));
        if (index !== -1) return index;
    }
    return -1;
}

function validateRow(row, indices, rowNumber) {
    const errors = [];
    const product = {};
    
    // Required: SKU
    if (indices.skuIndex === -1 || !row[indices.skuIndex]?.trim()) {
        errors.push(`Row ${rowNumber}: Missing SKU`);
    } else {
        product.sku = row[indices.skuIndex].trim();
    }
    
    // Required: Name
    if (indices.nameIndex === -1 || !row[indices.nameIndex]?.trim()) {
        errors.push(`Row ${rowNumber}: Missing product name`);
    } else {
        product.name = row[indices.nameIndex].trim();
    }
    
    // Required: Price
    if (indices.priceIndex === -1 || !row[indices.priceIndex]?.trim()) {
        errors.push(`Row ${rowNumber}: Missing price`);
    } else {
        const price = parseFloat(row[indices.priceIndex].replace(/[^0-9.]/g, ''));
        if (isNaN(price) || price < 0) {
            errors.push(`Row ${rowNumber}: Invalid price format`);
        } else {
            product.price = price.toFixed(2);
        }
    }
    
    // Optional fields
    if (indices.categoryIndex !== -1 && row[indices.categoryIndex]) {
        product.category = row[indices.categoryIndex].trim();
    }
    
    if (indices.descIndex !== -1 && row[indices.descIndex]) {
        product.desc = row[indices.descIndex].trim();
    }
    
    if (indices.imageIndex !== -1 && row[indices.imageIndex]) {
        product.image = row[indices.imageIndex].trim();
    }
    
    if (indices.enabledIndex !== -1) {
        const enabledValue = row[indices.enabledIndex]?.toLowerCase().trim();
        product.enabled = enabledValue === 'true' || enabledValue === '1' || enabledValue === 'yes' || enabledValue === 'active';
    } else {
        product.enabled = true;
    }
    
    // Default values
    product.variations = { groups: [] };
    product.mtime = Date.now();
    product.created = Date.now();
    
    return {
        product: errors.length === 0 ? product : null,
        errors
    };
}

// UI functions
function showProgress(show) {
    document.getElementById('import-progress').style.display = show ? 'block' : 'none';
}

function updateProgress(percent) {
    document.getElementById('progress-bar').style.width = percent + '%';
    document.getElementById('progress-text').textContent = Math.round(percent) + '%';
}

function showResults(result) {
    importResult = result;
    
    // Hide upload view, show results
    document.getElementById('upload-view').style.display = 'none';
    document.getElementById('results-view').style.display = 'block';
    document.getElementById('upload-actions').style.display = 'none';
    document.getElementById('results-actions').style.display = 'flex';
    
    // Update counts
    document.getElementById('products-count').textContent = result.products.length;
    document.getElementById('errors-count').textContent = result.errors.length;
    document.getElementById('warnings-count').textContent = result.warnings.length;
    
    // Show/hide sections
    const errorsSection = document.getElementById('errors-section');
    const warningsSection = document.getElementById('warnings-section');
    const productsSection = document.getElementById('products-section');
    
    if (result.errors.length > 0) {
        errorsSection.style.display = 'block';
        const errorsList = document.getElementById('errors-list');
        errorsList.innerHTML = result.errors.map(error => 
            `<div class="error-item">${error}</div>`
        ).join('');
    } else {
        errorsSection.style.display = 'none';
    }
    
    if (result.warnings.length > 0) {
        warningsSection.style.display = 'block';
        const warningsList = document.getElementById('warnings-list');
        warningsList.innerHTML = result.warnings.map(warning => 
            `<div class="warning-item">${warning}</div>`
        ).join('');
    } else {
        warningsSection.style.display = 'none';
    }
    
    if (result.products.length > 0) {
        productsSection.style.display = 'block';
        const productsList = document.getElementById('products-list');
        const previewProducts = result.products.slice(0, 10);
        productsList.innerHTML = previewProducts.map(product => 
            `<div class="product-item">
                <div class="product-item-info">
                    <span class="badge">${product.sku}</span>
                    <span style="font-size: 0.875rem;">${product.name}</span>
                </div>
                <span style="font-size: 0.875rem; font-weight: 500;">$${product.price}</span>
            </div>`
        ).join('');
        
        if (result.products.length > 10) {
            productsList.innerHTML += `<div style="text-align: center; font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.5rem;">... and ${result.products.length - 10} more products</div>`;
        }
        
        // Enable import button
        document.getElementById('import-btn').disabled = false;
        document.getElementById('import-btn-text').textContent = `Import ${result.products.length} Products`;
    } else {
        productsSection.style.display = 'none';
        document.getElementById('import-btn').disabled = true;
        document.getElementById('import-btn-text').textContent = 'No Products to Import';
    }
    
    // Show success/error messages
    if (result.products.length > 0) {
        showToast(`Successfully processed ${result.products.length} products`, 'success');
    }
    
    if (result.errors.length > 0) {
        showToast(`${result.errors.length} errors found during import`, 'error');
    }
}

function resetImport() {
    importResult = null;
    document.getElementById('upload-view').style.display = 'block';
    document.getElementById('results-view').style.display = 'none';
    document.getElementById('upload-actions').style.display = 'flex';
    document.getElementById('results-actions').style.display = 'none';
    document.getElementById('file-input').value = '';
    updateProgress(0);
}

function confirmImport() {
    if (!importResult || importResult.products.length === 0) {
        showToast('No products to import', 'error');
        return;
    }
    
    const importData = new FormData();
    importData.append('action', 'import_csv_products');
    importData.append('products', JSON.stringify(importResult.products));
    
    document.getElementById('import-btn').disabled = true;
    document.getElementById('import-btn-text').textContent = 'Importing...';
    
    fetch('', {
        method: 'POST',
        body: importData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`Imported ${importResult.products.length} products successfully`, 'success');
            closeCsvImportModal();
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message || 'Failed to import products', 'error');
            document.getElementById('import-btn').disabled = false;
            document.getElementById('import-btn-text').textContent = `Import ${importResult.products.length} Products`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to import products', 'error');
        document.getElementById('import-btn').disabled = false;
        document.getElementById('import-btn-text').textContent = `Import ${importResult.products.length} Products`;
    });
}

// Modal functions
function showCsvImportModal() {
    if (typeof window.showModal === 'function') {
        window.showModal('csv-import-modal');
    } else {
        document.getElementById('csv-import-modal').style.display = 'flex';
    }
    resetImport();
}

function closeCsvImportModal() {
    if (importInProgress) {
        if (confirm('Import is in progress. Are you sure you want to close?')) {
            if (typeof window.hideModal === 'function') {
                window.hideModal('csv-import-modal');
            } else {
                document.getElementById('csv-import-modal').style.display = 'none';
            }
            resetImport();
        }
    } else {
        if (typeof window.hideModal === 'function') {
            window.hideModal('csv-import-modal');
        } else {
            document.getElementById('csv-import-modal').style.display = 'none';
        }
        resetImport();
    }
}

// Make functions globally accessible
window.showCsvImportModal = showCsvImportModal;
window.closeCsvImportModal = closeCsvImportModal;
window.downloadSampleCSV = downloadSampleCSV;
window.handleDragOver = handleDragOver;
window.handleDragLeave = handleDragLeave;
window.handleDrop = handleDrop;
window.handleFileSelect = handleFileSelect;
window.resetImport = resetImport;
window.confirmImport = confirmImport;
</script>