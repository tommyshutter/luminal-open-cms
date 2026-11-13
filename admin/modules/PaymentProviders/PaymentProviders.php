<?php
/**
 * Payment Methods — Gateway Configuration
 * @module PaymentProviders
 * @version 1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Payment Methods</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/PaymentProviders/css/payment-providers.css') ?>">

<div class="pp-wrap">

  <!-- Header -->
  <div class="pp-header">
    <div class="pp-header-left">
      <p class="pp-subtitle">Configure payment gateways for your store</p>
    </div>
    <div class="pp-header-right">
      <div class="pp-health" id="pp-health">
        <span class="pp-health-dot" id="pp-health-dot"></span>
        <span id="pp-health-text">Loading...</span>
      </div>
    </div>
  </div>

  <!-- Payment Gateways Section -->
  <section class="pp-section">
    <div class="pp-section-head">
      <h2>Payment Gateways</h2>
      <div class="pp-settings-row">
        <label>Currency</label>
        <select id="pp-currency" onchange="PP.saveCurrency(this.value)">
          <option value="USD">USD ($)</option>
          <option value="EUR">EUR (&euro;)</option>
          <option value="GBP">GBP (&pound;)</option>
          <option value="CAD">CAD (C$)</option>
          <option value="AUD">AUD (A$)</option>
        </select>
      </div>
    </div>
    <div class="pp-card-grid" id="pp-gateway-grid">
      <div class="pp-loading">Loading gateways...</div>
    </div>
  </section>

</div>

<!-- Configure Gateway Modal -->
<div class="pp-modal-backdrop" id="pp-modal" style="display:none">
  <div class="pp-modal">
    <div class="pp-modal-head">
      <h3 id="pp-modal-title">Configure Gateway</h3>
      <button class="pp-modal-close" onclick="PP.closeModal()">&times;</button>
    </div>
    <div class="pp-modal-body" id="pp-modal-body"></div>
    <div class="pp-modal-foot">
      <button class="pp-btn" onclick="PP.closeModal()">Cancel</button>
      <button class="pp-btn pp-btn-primary" onclick="PP.saveGateway()">Save</button>
    </div>
  </div>
</div>

<div class="pp-toast" id="pp-toast"></div>

<script>
const PP_BOOT = {
  apiUrl: '<?= sc_asset('/admin/modules/PaymentProviders/api.php') ?>',
};
</script>
<script src="<?= sc_asset('/admin/modules/PaymentProviders/js/payment-providers.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
