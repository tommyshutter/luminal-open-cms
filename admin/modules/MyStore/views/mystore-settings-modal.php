<?php
/**
 * My Store — Complete Settings Modal
 *
 * File: /admin/modules/MyStore/views/mystore-settings-modal.php
 * Purpose: Full-featured settings modal for store configuration
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

// Load current settings
$settings = paypal_get_all_settings();
?>

<!-- Settings Modal -->
<div id="settings-modal" class="modal" style="display: none;">
    <div class="modal-content glass-modal" style="max-width: 1000px; max-height: 90vh; overflow-y: auto;">
        <!-- Modal Header -->
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem; border-bottom: 1px solid var(--border);">
            <h2 style="margin: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--foreground);">
                <i class="fas fa-cogs"></i>
                Store Settings
            </h2>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span id="unsaved-badge" class="badge badge-secondary" style="display: none; font-size: 0.75rem;">
                    Unsaved Changes
                </span>
                <button class="btn btn-ghost" onclick="closeSettingsModal()" style="padding: 0.5rem; min-width: auto;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="modal-body" style="padding: 1.5rem;">
            <!-- Tab Navigation -->
            <div class="tabs">
                <div class="tab-list" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.25rem; margin-bottom: 1.5rem; background: var(--muted); padding: 0.25rem; border-radius: 0.5rem;">
                    <button class="tab-trigger active" onclick="showTab('general')" data-tab="general" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: var(--primary); color: var(--primary-foreground);">General</button>
                    <button class="tab-trigger" onclick="showTab('display')" data-tab="display" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Display</button>
                    <button class="tab-trigger" onclick="showTab('seller')" data-tab="seller" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Seller</button>
                    <button class="tab-trigger" onclick="showTab('merchant')" data-tab="merchant" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Merchant</button>
                    <button class="tab-trigger" onclick="showTab('messages')" data-tab="messages" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Messages</button>
                    <button class="tab-trigger" onclick="showTab('features')" data-tab="features" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Features</button>
                    <button class="tab-trigger" onclick="showTab('advanced')" data-tab="advanced" style="padding: 0.5rem; font-size: 0.75rem; text-align: center; border-radius: 0.25rem; border: none; background: transparent; color: var(--muted-foreground);">Advanced</button>
                </div>

                <!-- General Tab -->
                <div class="tab-content" id="tab-general" style="display: block;">
                    <form id="settings-form">
                        <div style="margin-bottom: 2rem;">
                            <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Store Information</h3>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                <div>
                                    <label for="storeName" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Store Name</label>
                                    <input type="text" id="storeName" name="storeName" value="<?= esc_attr($settings['storeName'] ?? 'My Store') ?>" class="form-input" onchange="markUnsaved()" placeholder="Your Store Name">
                                </div>
                                <div>
                                    <label for="currency" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Currency</label>
                                    <select id="currency" name="currency" class="form-input" onchange="markUnsaved()">
                                        <option value="USD" <?= ($settings['currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                        <option value="EUR" <?= ($settings['currency'] ?? 'USD') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                        <option value="GBP" <?= ($settings['currency'] ?? 'USD') === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                                        <option value="CAD" <?= ($settings['currency'] ?? 'USD') === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar</option>
                                        <option value="AUD" <?= ($settings['currency'] ?? 'USD') === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label for="storeDescription" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Store Description</label>
                                <textarea id="storeDescription" name="storeDescription" rows="3" class="form-input" onchange="markUnsaved()" placeholder="Brief description of your store"><?= esc_textarea($settings['storeDescription'] ?? 'Premium apparel and accessories') ?></textarea>
                            </div>
                        </div>

                        <div>
                            <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Pricing & Shipping</h3>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                <div>
                                    <label for="taxRate" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Tax Rate (%)</label>
                                    <input type="number" id="taxRate" name="taxRate" step="0.01" min="0" max="100" value="<?= esc_attr($settings['taxRate'] ?? 0) ?>" class="form-input" onchange="markUnsaved()">
                                </div>
                                <div>
                                    <label for="shippingCost" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Shipping Cost</label>
                                    <input type="number" id="shippingCost" name="shippingCost" step="0.01" min="0" value="<?= esc_attr($settings['shippingCost'] ?? 5.99) ?>" class="form-input" onchange="markUnsaved()">
                                </div>
                                <div>
                                    <label for="freeShippingThreshold" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Free Shipping Threshold</label>
                                    <input type="number" id="freeShippingThreshold" name="freeShippingThreshold" step="0.01" min="0" value="<?= esc_attr($settings['freeShippingThreshold'] ?? 50) ?>" class="form-input" onchange="markUnsaved()">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Display Tab -->
                <div class="tab-content" id="tab-display" style="display: none;">
                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Visibility</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="showStoreTitle" name="showStoreTitle" <?= !empty($settings['showStoreTitle']) || !isset($settings['showStoreTitle']) ? 'checked' : '' ?> onchange="markUnsaved()">
                                <span>Show Store Title</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="showStoreDescription" name="showStoreDescription" <?= !empty($settings['showStoreDescription']) || !isset($settings['showStoreDescription']) ? 'checked' : '' ?> onchange="markUnsaved()">
                                <span>Show Description</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="showStatsBar" name="showStatsBar" <?= !empty($settings['showStatsBar']) || !isset($settings['showStatsBar']) ? 'checked' : '' ?> onchange="markUnsaved()">
                                <span>Show Stats Bar</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Fonts</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="storeTitleFont" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Title Font</label>
                                <select id="storeTitleFont" name="storeTitleFont" class="form-input" onchange="markUnsaved()">
                                    <option value="" <?= empty($settings['storeTitleFont']) ? 'selected' : '' ?>>System Default</option>
                                    <option value="Inter" <?= ($settings['storeTitleFont'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                                    <option value="Georgia, serif" <?= ($settings['storeTitleFont'] ?? '') === 'Georgia, serif' ? 'selected' : '' ?>>Georgia</option>
                                    <option value="'Playfair Display', serif" <?= ($settings['storeTitleFont'] ?? '') === "'Playfair Display', serif" ? 'selected' : '' ?>>Playfair Display</option>
                                    <option value="'Oswald', sans-serif" <?= ($settings['storeTitleFont'] ?? '') === "'Oswald', sans-serif" ? 'selected' : '' ?>>Oswald</option>
                                    <option value="'Montserrat', sans-serif" <?= ($settings['storeTitleFont'] ?? '') === "'Montserrat', sans-serif" ? 'selected' : '' ?>>Montserrat</option>
                                    <option value="'Bebas Neue', sans-serif" <?= ($settings['storeTitleFont'] ?? '') === "'Bebas Neue', sans-serif" ? 'selected' : '' ?>>Bebas Neue</option>
                                </select>
                            </div>
                            <div>
                                <label for="storeTitleSize" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Title Size</label>
                                <select id="storeTitleSize" name="storeTitleSize" class="form-input" onchange="markUnsaved()">
                                    <option value="1.5rem" <?= ($settings['storeTitleSize'] ?? '2rem') === '1.5rem' ? 'selected' : '' ?>>Small</option>
                                    <option value="2rem" <?= ($settings['storeTitleSize'] ?? '2rem') === '2rem' ? 'selected' : '' ?>>Medium</option>
                                    <option value="2.5rem" <?= ($settings['storeTitleSize'] ?? '2rem') === '2.5rem' ? 'selected' : '' ?>>Large</option>
                                    <option value="3rem" <?= ($settings['storeTitleSize'] ?? '2rem') === '3rem' ? 'selected' : '' ?>>Extra Large</option>
                                </select>
                            </div>
                            <div>
                                <label for="storeBodyFont" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Body Font</label>
                                <select id="storeBodyFont" name="storeBodyFont" class="form-input" onchange="markUnsaved()">
                                    <option value="" <?= empty($settings['storeBodyFont']) ? 'selected' : '' ?>>System Default</option>
                                    <option value="Inter" <?= ($settings['storeBodyFont'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                                    <option value="Georgia, serif" <?= ($settings['storeBodyFont'] ?? '') === 'Georgia, serif' ? 'selected' : '' ?>>Georgia</option>
                                    <option value="'Lato', sans-serif" <?= ($settings['storeBodyFont'] ?? '') === "'Lato', sans-serif" ? 'selected' : '' ?>>Lato</option>
                                    <option value="'Open Sans', sans-serif" <?= ($settings['storeBodyFont'] ?? '') === "'Open Sans', sans-serif" ? 'selected' : '' ?>>Open Sans</option>
                                    <option value="'Roboto', sans-serif" <?= ($settings['storeBodyFont'] ?? '') === "'Roboto', sans-serif" ? 'selected' : '' ?>>Roboto</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Background & Colors</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="storeBgColor" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Store Background</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="color" id="storeBgColor" name="storeBgColor" value="<?= esc_attr($settings['storeBgColor'] ?? '#000000') ?>" onchange="markUnsaved()" style="width: 40px; height: 36px; border: none; cursor: pointer; background: transparent;">
                                    <input type="text" value="<?= esc_attr($settings['storeBgColor'] ?? '') ?>" class="form-input" style="flex:1;" placeholder="#000000" oninput="document.getElementById('storeBgColor').value=this.value; markUnsaved();">
                                </div>
                            </div>
                            <div>
                                <label for="productCardBg" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Product Card Background</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="color" id="productCardBg" name="productCardBg" value="<?= esc_attr($settings['productCardBg'] ?? '#252525') ?>" onchange="markUnsaved()" style="width: 40px; height: 36px; border: none; cursor: pointer; background: transparent;">
                                    <input type="text" value="<?= esc_attr($settings['productCardBg'] ?? '') ?>" class="form-input" style="flex:1;" placeholder="rgba(37,37,37,0.8)" oninput="document.getElementById('productCardBg').value=this.value; markUnsaved();">
                                </div>
                            </div>
                            <div>
                                <label for="productCardTextColor" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Product Card Text</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="color" id="productCardTextColor" name="productCardTextColor" value="<?= esc_attr($settings['productCardTextColor'] ?? '#ffffff') ?>" onchange="markUnsaved()" style="width: 40px; height: 36px; border: none; cursor: pointer; background: transparent;">
                                    <input type="text" value="<?= esc_attr($settings['productCardTextColor'] ?? '') ?>" class="form-input" style="flex:1;" placeholder="#ffffff" oninput="document.getElementById('productCardTextColor').value=this.value; markUnsaved();">
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="storeBgImage" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Background Image URL</label>
                            <input type="text" id="storeBgImage" name="storeBgImage" value="<?= esc_attr($settings['storeBgImage'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="/media/images/background.jpg (leave empty for none)">
                        </div>
                    </div>
                </div>

                <!-- Seller Tab -->
                <div class="tab-content" id="tab-seller" style="display: none;">
                    <div>
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Business Information</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="businessName" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Business Name *</label>
                                <input type="text" id="businessName" name="businessName" value="<?= esc_attr($settings['businessName'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="Your Business Name">
                            </div>
                            <div>
                                <label for="businessEmail" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Business Email *</label>
                                <input type="email" id="businessEmail" name="businessEmail" value="<?= esc_attr($settings['businessEmail'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="business@company.com">
                            </div>
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <label for="businessAddress" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Business Address</label>
                            <input type="text" id="businessAddress" name="businessAddress" value="<?= esc_attr($settings['businessAddress'] ?? '123 Business Street') ?>" class="form-input" onchange="markUnsaved()" placeholder="123 Business Street">
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="businessCity" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">City</label>
                                <input type="text" id="businessCity" name="businessCity" value="<?= esc_attr($settings['businessCity'] ?? 'City') ?>" class="form-input" onchange="markUnsaved()" placeholder="City">
                            </div>
                            <div>
                                <label for="businessState" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">State/Province</label>
                                <input type="text" id="businessState" name="businessState" value="<?= esc_attr($settings['businessState'] ?? 'State') ?>" class="form-input" onchange="markUnsaved()" placeholder="State">
                            </div>
                            <div>
                                <label for="businessZip" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">ZIP/Postal Code</label>
                                <input type="text" id="businessZip" name="businessZip" value="<?= esc_attr($settings['businessZip'] ?? '12345') ?>" class="form-input" onchange="markUnsaved()" placeholder="12345">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="businessCountry" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Country</label>
                                <select id="businessCountry" name="businessCountry" class="form-input" onchange="markUnsaved()">
                                    <option value="United States" <?= ($settings['businessCountry'] ?? 'United States') === 'United States' ? 'selected' : '' ?>>United States</option>
                                    <option value="Canada" <?= ($settings['businessCountry'] ?? 'United States') === 'Canada' ? 'selected' : '' ?>>Canada</option>
                                    <option value="United Kingdom" <?= ($settings['businessCountry'] ?? 'United States') === 'United Kingdom' ? 'selected' : '' ?>>United Kingdom</option>
                                    <option value="Australia" <?= ($settings['businessCountry'] ?? 'United States') === 'Australia' ? 'selected' : '' ?>>Australia</option>
                                    <option value="Germany" <?= ($settings['businessCountry'] ?? 'United States') === 'Germany' ? 'selected' : '' ?>>Germany</option>
                                    <option value="France" <?= ($settings['businessCountry'] ?? 'United States') === 'France' ? 'selected' : '' ?>>France</option>
                                    <option value="Other" <?= ($settings['businessCountry'] ?? 'United States') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="businessPhone" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Business Phone</label>
                                <input type="tel" id="businessPhone" name="businessPhone" value="<?= esc_attr($settings['businessPhone'] ?? '(555) 123-4567') ?>" class="form-input" onchange="markUnsaved()" placeholder="(555) 123-4567">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="businessTaxId" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Tax ID (Optional)</label>
                                <input type="text" id="businessTaxId" name="businessTaxId" value="<?= esc_attr($settings['businessTaxId'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="Tax ID or EIN">
                            </div>
                            <div>
                                <label for="businessWebsite" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Business Website</label>
                                <input type="url" id="businessWebsite" name="businessWebsite" value="<?= esc_attr($settings['businessWebsite'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="https://yourwebsite.com">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Merchant Tab -->
                <div class="tab-content" id="tab-merchant" style="display: none;">
                    <div>
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Merchant Account Settings</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="merchantId" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Merchant ID</label>
                                <input type="text" id="merchantId" name="merchantId" value="<?= esc_attr($settings['merchantId'] ?? '') ?>" class="form-input" onchange="markUnsaved()" placeholder="Your Merchant ID">
                                <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">
                                    Find this in your payment provider account
                                </p>
                            </div>
                            <div>
                                <label for="merchantAccountType" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Account Type</label>
                                <select id="merchantAccountType" name="merchantAccountType" class="form-input" onchange="markUnsaved()">
                                    <option value="business" <?= ($settings['merchantAccountType'] ?? 'business') === 'business' ? 'selected' : '' ?>>Business</option>
                                    <option value="personal" <?= ($settings['merchantAccountType'] ?? 'business') === 'personal' ? 'selected' : '' ?>>Personal</option>
                                    <option value="nonprofit" <?= ($settings['merchantAccountType'] ?? 'business') === 'nonprofit' ? 'selected' : '' ?>>Non-Profit</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="merchantStatus" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Merchant Status</label>
                                <select id="merchantStatus" name="merchantStatus" class="form-input" onchange="markUnsaved()">
                                    <option value="active" <?= ($settings['merchantStatus'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="pending" <?= ($settings['merchantStatus'] ?? 'active') === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
                                    <option value="limited" <?= ($settings['merchantStatus'] ?? 'active') === 'limited' ? 'selected' : '' ?>>Limited</option>
                                    <option value="suspended" <?= ($settings['merchantStatus'] ?? 'active') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                            </div>
                            <div>
                                <label for="processingFees" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Processing Fees (%)</label>
                                <input type="number" id="processingFees" name="processingFees" step="0.1" min="0" max="10" value="<?= esc_attr($settings['processingFees'] ?? 2.9) ?>" class="form-input" onchange="markUnsaved()">
                                <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">
                                    Payment processing fee percentage
                                </p>
                            </div>
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <label for="refundPolicy" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Refund Policy</label>
                            <textarea id="refundPolicy" name="refundPolicy" rows="3" class="form-input" onchange="markUnsaved()" placeholder="Describe your refund policy..."><?= esc_textarea($settings['refundPolicy'] ?? 'Returns accepted within 30 days of purchase') ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="returnPolicyDays" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Return Policy (Days)</label>
                                <input type="number" id="returnPolicyDays" name="returnPolicyDays" min="0" max="365" value="<?= esc_attr($settings['returnPolicyDays'] ?? 30) ?>" class="form-input" onchange="markUnsaved()">
                            </div>
                            <div>
                                <label for="disputeHandling" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Dispute Handling</label>
                                <select id="disputeHandling" name="disputeHandling" class="form-input" onchange="markUnsaved()">
                                    <option value="auto" <?= ($settings['disputeHandling'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Automatic</option>
                                    <option value="manual" <?= ($settings['disputeHandling'] ?? 'auto') === 'manual' ? 'selected' : '' ?>>Manual Review</option>
                                    <option value="escalate" <?= ($settings['disputeHandling'] ?? 'auto') === 'escalate' ? 'selected' : '' ?>>Always Escalate</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <label class="switch">
                                <input type="checkbox" id="fraudProtection" name="fraudProtection" <?= ($settings['fraudProtection'] ?? true) ? 'checked' : '' ?> onchange="markUnsaved()">
                                <span class="slider round"></span>
                            </label>
                            <label for="fraudProtection" style="font-weight: 500;">Enable Fraud Protection</label>
                        </div>
                    </div>
                </div>

                <!-- Messages Tab -->
                <div class="tab-content" id="tab-messages" style="display: none;">
                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Customer Messages</h3>
                        
                        <div style="margin-bottom: 1rem;">
                            <label for="thankYouMessage" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Thank You Message</label>
                            <textarea id="thankYouMessage" name="thankYouMessage" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Thank you message after purchase"><?= esc_textarea($settings['thankYouMessage'] ?? 'Thank you for your purchase! Your order has been received and is being processed.') ?></textarea>
                        </div>

                        <div style="margin-bottom: 1rem;">
                            <label for="orderPlacedMessage" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Order Placed Message</label>
                            <textarea id="orderPlacedMessage" name="orderPlacedMessage" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Message when order is placed"><?= esc_textarea($settings['orderPlacedMessage'] ?? 'Your order #{{ORDER_ID}} has been placed successfully. You will receive a confirmation email shortly.') ?></textarea>
                            <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">
                                Use {{ORDER_ID}} for dynamic order number
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="paymentSuccessMessage" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Payment Success</label>
                                <textarea id="paymentSuccessMessage" name="paymentSuccessMessage" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Payment successful message"><?= esc_textarea($settings['paymentSuccessMessage'] ?? 'Payment successful! Thank you for your business.') ?></textarea>
                            </div>
                            <div>
                                <label for="paymentFailedMessage" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Payment Failed</label>
                                <textarea id="paymentFailedMessage" name="paymentFailedMessage" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Payment failed message"><?= esc_textarea($settings['paymentFailedMessage'] ?? 'Payment failed. Please check your payment details and try again.') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Email Notifications</h3>
                        
                        <div style="margin-bottom: 1rem;">
                            <label for="orderConfirmationEmail" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Order Confirmation Email</label>
                            <textarea id="orderConfirmationEmail" name="orderConfirmationEmail" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Order confirmation email template"><?= esc_textarea($settings['orderConfirmationEmail'] ?? 'Your order #{{ORDER_ID}} has been confirmed. Estimated delivery: {{DELIVERY_DATE}}') ?></textarea>
                            <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">
                                Variables: {{ORDER_ID}}, {{DELIVERY_DATE}}
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="shippingNotificationEmail" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Shipping Notification</label>
                                <textarea id="shippingNotificationEmail" name="shippingNotificationEmail" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Shipping notification email"><?= esc_textarea($settings['shippingNotificationEmail'] ?? 'Your order #{{ORDER_ID}} has been shipped. Tracking number: {{TRACKING_NUMBER}}') ?></textarea>
                                <p style="font-size: 0.875rem; color: var(--muted-foreground); margin-top: 0.25rem;">
                                    Variables: {{TRACKING_NUMBER}}
                                </p>
                            </div>
                            <div>
                                <label for="deliveryConfirmationEmail" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Delivery Confirmation</label>
                                <textarea id="deliveryConfirmationEmail" name="deliveryConfirmationEmail" rows="2" class="form-input" onchange="markUnsaved()" placeholder="Delivery confirmation email"><?= esc_textarea($settings['deliveryConfirmationEmail'] ?? 'Your order #{{ORDER_ID}} has been delivered. Thank you for shopping with us!') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="padding: 1rem; background: rgba(255, 255, 255, 0.05); border-radius: 0.5rem;">
                        <h4 style="font-weight: 500; margin-bottom: 0.5rem;">Available Variables</h4>
                        <div style="font-size: 0.875rem; color: var(--muted-foreground); display: grid; grid-template-columns: 1fr 1fr; gap: 0.25rem;">
                            <p style="margin: 0;"><code>{{ORDER_ID}}</code> - Order number</p>
                            <p style="margin: 0;"><code>{{CUSTOMER_NAME}}</code> - Customer name</p>
                            <p style="margin: 0;"><code>{{CUSTOMER_EMAIL}}</code> - Customer email</p>
                            <p style="margin: 0;"><code>{{ORDER_TOTAL}}</code> - Order total amount</p>
                            <p style="margin: 0;"><code>{{DELIVERY_DATE}}</code> - Estimated delivery date</p>
                            <p style="margin: 0;"><code>{{TRACKING_NUMBER}}</code> - Shipping tracking number</p>
                            <p style="margin: 0;"><code>{{REFUND_AMOUNT}}</code> - Refund amount</p>
                            <p style="margin: 0;"><code>{{STORE_NAME}}</code> - Your store name</p>
                        </div>
                    </div>
                </div>

                <!-- Features Tab -->
                <div class="tab-content" id="tab-features" style="display: none;">
                    <div>
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Store Features</h3>
                        
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border: 1px solid var(--border); border-radius: 0.5rem;">
                                <div>
                                    <label for="enableInventoryTracking" style="font-weight: 500; display: block; margin-bottom: 0.25rem;">Inventory Tracking</label>
                                    <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0;">Track stock levels for products</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="enableInventoryTracking" name="enableInventoryTracking" <?= ($settings['enableInventoryTracking'] ?? false) ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="slider round"></span>
                                </label>
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border: 1px solid var(--border); border-radius: 0.5rem;">
                                <div>
                                    <label for="enableReviews" style="font-weight: 500; display: block; margin-bottom: 0.25rem;">Product Reviews</label>
                                    <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0;">Allow customers to leave reviews</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="enableReviews" name="enableReviews" <?= ($settings['enableReviews'] ?? true) ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="slider round"></span>
                                </label>
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border: 1px solid var(--border); border-radius: 0.5rem;">
                                <div>
                                    <label for="enableWishlist" style="font-weight: 500; display: block; margin-bottom: 0.25rem;">Wishlist Feature</label>
                                    <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0;">Let customers save products for later</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="enableWishlist" name="enableWishlist" <?= ($settings['enableWishlist'] ?? true) ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="slider round"></span>
                                </label>
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border: 1px solid var(--border); border-radius: 0.5rem;">
                                <div>
                                    <label for="emailNotifications" style="font-weight: 500; display: block; margin-bottom: 0.25rem;">Email Notifications</label>
                                    <p style="font-size: 0.875rem; color: var(--muted-foreground); margin: 0;">Send order confirmations and updates</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="emailNotifications" name="emailNotifications" <?= ($settings['emailNotifications'] ?? true) ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="slider round"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div class="tab-content" id="tab-advanced" style="display: none;">
                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Advanced Settings</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="orderPrefix" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Order Number Prefix</label>
                                <input type="text" id="orderPrefix" name="orderPrefix" value="<?= esc_attr($settings['orderPrefix'] ?? 'ORD') ?>" class="form-input" onchange="markUnsaved()" placeholder="ORD">
                            </div>
                            <div>
                                <label for="defaultCategory" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Default Category</label>
                                <input type="text" id="defaultCategory" name="defaultCategory" value="<?= esc_attr($settings['defaultCategory'] ?? 'General') ?>" class="form-input" onchange="markUnsaved()" placeholder="General">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="imageQuality" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Image Quality (%)</label>
                                <input type="number" id="imageQuality" name="imageQuality" min="1" max="100" value="<?= esc_attr($settings['imageQuality'] ?? 80) ?>" class="form-input" onchange="markUnsaved()">
                            </div>
                            <div>
                                <label for="maxFileSize" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Max File Size (MB)</label>
                                <input type="number" id="maxFileSize" name="maxFileSize" min="1" max="100" value="<?= esc_attr($settings['maxFileSize'] ?? 10) ?>" class="form-input" onchange="markUnsaved()">
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Settings Management</h3>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline" onclick="exportSettings()" style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-download"></i>
                                Export Settings
                            </button>
                            
                            <div style="position: relative;">
                                <input type="file" id="import-settings" accept=".json" onchange="importSettings(this)" style="position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;">
                                <button type="button" class="btn btn-outline" style="display: flex; align-items: center; gap: 0.5rem; pointer-events: none;">
                                    <i class="fas fa-upload"></i>
                                    Import Settings
                                </button>
                            </div>
                            
                            <button type="button" class="btn btn-outline" onclick="resetSettings()" style="display: flex; align-items: center; gap: 0.5rem; color: var(--destructive);">
                                <i class="fas fa-trash"></i>
                                Reset to Defaults
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Product Data Export</h3>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline" onclick="exportProducts('json')" style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-download"></i>
                                Export Products as JSON
                            </button>
                            
                            <button type="button" class="btn btn-outline" onclick="exportProducts('csv')" style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-file-csv"></i>
                                Export Products as CSV
                            </button>
                        </div>
                    </div>

                    <div>
                        <h3 style="margin-bottom: 1rem; font-size: 1.125rem; font-weight: 500;">Danger Zone</h3>
                        
                        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.5rem; padding: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0; color: rgb(239, 68, 68);">Clear All Products</h4>
                            <p style="margin: 0 0 1rem 0; font-size: 0.875rem; color: var(--muted-foreground);">
                                This will permanently delete all product data. This action cannot be undone.
                            </p>
                            <button type="button" class="btn btn-outline" onclick="clearAllProducts()" style="display: flex; align-items: center; gap: 0.5rem; color: var(--destructive); border-color: var(--destructive);">
                                <i class="fas fa-trash"></i>
                                Clear All Products
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="display: flex; gap: 0.75rem; padding: 1.5rem; border-top: 1px solid var(--border);">
            <button type="button" class="btn btn-primary" onclick="saveSettings()" id="save-btn" style="display: flex; align-items: center; gap: 0.5rem;" disabled>
                <i class="fas fa-save"></i>
                Save Settings
            </button>
            <button type="button" class="btn btn-outline" onclick="closeSettingsModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Switch styles -->
<style>
.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--muted);
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
}

input:checked + .slider {
    background-color: var(--primary);
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.slider.round {
    border-radius: 34px;
}

.slider.round:before {
    border-radius: 50%;
}

.tab-trigger.active {
    background: var(--primary) !important;
    color: var(--primary-foreground) !important;
}

.tab-trigger:hover {
    background: rgba(255, 255, 255, 0.1) !important;
}
</style>

<script>
let hasUnsavedChanges = false;

// Tab switching
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.style.display = 'none';
    });
    
    // Remove active class from all tab triggers
    document.querySelectorAll('.tab-trigger').forEach(trigger => {
        trigger.classList.remove('active');
        trigger.style.background = 'transparent';
        trigger.style.color = 'var(--muted-foreground)';
    });
    
    // Show selected tab content
    document.getElementById('tab-' + tabName).style.display = 'block';
    
    // Add active class to selected tab trigger
    const activeTrigger = document.querySelector(`[data-tab="${tabName}"]`);
    if (activeTrigger) {
        activeTrigger.classList.add('active');
        activeTrigger.style.background = 'var(--primary)';
        activeTrigger.style.color = 'var(--primary-foreground)';
    }
}

// Mark settings as unsaved
function markUnsaved() {
    hasUnsavedChanges = true;
    document.getElementById('unsaved-badge').style.display = 'inline-block';
    document.getElementById('save-btn').disabled = false;
}

// Save settings
function saveSettings() {
    const form = document.getElementById('settings-form');
    const formData = new FormData(form);
    
    // Get all form elements including those outside the main form
    const allInputs = document.querySelectorAll('#settings-modal input, #settings-modal select, #settings-modal textarea');
    const settings = {};
    
    allInputs.forEach(input => {
        if (input.name) {
            if (input.type === 'checkbox') {
                settings[input.name] = input.checked;
            } else {
                settings[input.name] = input.value;
            }
        }
    });
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=save_settings&settings=${encodeURIComponent(JSON.stringify(settings))}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            hasUnsavedChanges = false;
            document.getElementById('unsaved-badge').style.display = 'none';
            document.getElementById('save-btn').disabled = true;
            showToast(data.message || 'Settings saved successfully', 'success');
        } else {
            showToast(data.message || 'Failed to save settings', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to save settings', 'error');
    });
}

// Export settings
function exportSettings() {
    const settings = {};
    document.querySelectorAll('#settings-modal input, #settings-modal select, #settings-modal textarea').forEach(input => {
        if (input.name) {
            if (input.type === 'checkbox') {
                settings[input.name] = input.checked;
            } else {
                settings[input.name] = input.value;
            }
        }
    });
    
    const dataStr = JSON.stringify(settings, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `mystore-settings-${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    showToast('Settings exported successfully', 'success');
}

// Import settings
function importSettings(input) {
    const file = input.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const imported = JSON.parse(e.target.result);
            
            // Populate form fields
            Object.keys(imported).forEach(key => {
                const input = document.querySelector(`[name="${key}"]`);
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = !!imported[key];
                    } else {
                        input.value = imported[key];
                    }
                }
            });
            
            markUnsaved();
            showToast('Settings imported successfully', 'success');
        } catch (error) {
            console.error('Failed to import settings:', error);
            showToast('Failed to import settings - invalid file format', 'error');
        }
    };
    reader.readAsText(file);
    
    // Reset the input
    input.value = '';
}

// Reset settings
function resetSettings() {
    if (confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reset_settings'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Settings reset to defaults', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast('Failed to reset settings', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to reset settings', 'error');
        });
    }
}

// Product export functions
function exportProducts(format) {
    fetch('?action=get_all_products')
        .then(response => response.json())
        .then(products => {
            if (format === 'json') {
                const dataStr = JSON.stringify(products, null, 2);
                downloadFile(dataStr, 'products.json', 'application/json');
            } else if (format === 'csv') {
                const csv = convertToCSV(products);
                downloadFile(csv, 'products.csv', 'text/csv');
            }
            showToast(`Products exported as ${format.toUpperCase()}`, 'success');
        })
        .catch(error => {
            console.error('Export error:', error);
            showToast('Failed to export products', 'error');
        });
}

function convertToCSV(data) {
    const headers = ['SKU', 'Name', 'Price', 'Category', 'Description', 'Enabled', 'Image'];
    const csvRows = [headers.join(',')];
    
    data.forEach(product => {
        const row = [
            product.sku,
            `"${product.name.replace(/"/g, '""')}"`,
            product.price,
            product.category || '',
            `"${(product.desc || '').replace(/"/g, '""')}"`,
            product.enabled ? 'TRUE' : 'FALSE',
            product.image || ''
        ];
        csvRows.push(row.join(','));
    });
    
    return csvRows.join('\n');
}

function downloadFile(content, filename, contentType) {
    const blob = new Blob([content], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
}

function clearAllProducts() {
    if (confirm('Are you sure you want to delete ALL products? This action cannot be undone.')) {
        if (confirm('This will permanently delete all product data. Are you absolutely sure?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=clear_all_products'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('All products deleted successfully', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Failed to delete products: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to delete products', 'error');
            });
        }
    }
}

// Close modal function
function closeSettingsModal() {
    if (hasUnsavedChanges) {
        if (confirm('You have unsaved changes. Are you sure you want to close?')) {
            if (typeof window.hideModal === 'function') {
                window.hideModal('settings-modal');
            } else {
                document.getElementById('settings-modal').style.display = 'none';
            }
            hasUnsavedChanges = false;
            document.getElementById('unsaved-badge').style.display = 'none';
            document.getElementById('save-btn').disabled = true;
        }
    } else {
        if (typeof window.hideModal === 'function') {
            window.hideModal('settings-modal');
        } else {
            document.getElementById('settings-modal').style.display = 'none';
        }
    }
}

// Make functions globally accessible
window.showTab = showTab;
window.markUnsaved = markUnsaved;
window.saveSettings = saveSettings;
window.exportSettings = exportSettings;
window.importSettings = importSettings;
window.resetSettings = resetSettings;
window.exportProducts = exportProducts;
window.clearAllProducts = clearAllProducts;
window.closeSettingsModal = closeSettingsModal;

// Initialize first tab
document.addEventListener('DOMContentLoaded', function() {
    showTab('general');
});
</script>