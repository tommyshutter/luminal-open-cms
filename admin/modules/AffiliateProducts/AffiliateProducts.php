<?php
/**
 * AffiliateProducts — Admin UI
 * @file admin/modules/AffiliateProducts/AffiliateProducts.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$moduleBase = sc_asset('/admin/modules/AffiliateProducts');
require_once SITE_ROOT . '/admin/admin_header.php';
?>
<link rel="stylesheet" href="<?= $moduleBase ?>/css/ap-admin.css">

<div class="ap-page">
  <!-- Tab Bar -->
  <div class="ap-tabs">
    <button class="ap-tab active" data-tab="dashboard"><i class="fa-solid fa-gauge-high"></i> Dashboard</button>
    <button class="ap-tab" data-tab="products"><i class="fa-solid fa-boxes-stacked"></i> Products</button>
    <button class="ap-tab" data-tab="stats"><i class="fa-solid fa-chart-line"></i> Stats</button>
    <button class="ap-tab" data-tab="amazon"><i class="fa-brands fa-amazon" style="color:#ff9900"></i> Amazon</button>
    <button class="ap-tab" data-tab="settings"><i class="fa-solid fa-gear"></i> Settings</button>
  </div>

  <!-- ═══════════ DASHBOARD TAB ═══════════ -->
  <div class="ap-tab-content active" id="ap-tab-dashboard">
    <!-- KPI strip -->
    <div class="ap-kpi-row">
      <div class="ap-kpi"><div class="ap-kpi-val" id="ap-kpi-stores">—</div><div class="ap-kpi-lbl"><i class="fa-brands fa-amazon"></i> Amazon Stores</div></div>
      <div class="ap-kpi"><div class="ap-kpi-val" id="ap-kpi-sites">—</div><div class="ap-kpi-lbl"><i class="fa-solid fa-globe"></i> Attached Sites</div></div>
      <div class="ap-kpi"><div class="ap-kpi-val" id="ap-kpi-products">—</div><div class="ap-kpi-lbl"><i class="fa-solid fa-boxes-stacked"></i> Live Products</div></div>
      <div class="ap-kpi"><div class="ap-kpi-val" id="ap-kpi-clicks">—</div><div class="ap-kpi-lbl"><i class="fa-solid fa-arrow-up-right-from-square"></i> Clicks (30d)</div></div>
    </div>

    <!-- Store Coverage (inline-editable registry) — full width -->
    <div class="ap-card ap-card-full" id="ap-stores-card">
      <div class="ap-card-header">
        <span><i class="fa-solid fa-sitemap"></i> Store Coverage</span>
        <div style="display:flex;gap:8px;align-items:center">
          <button class="ap-btn ap-btn-sm" onclick="AP.bulkSeedStores()" title="Paste a list of Amazon Store IDs to bulk-add"><i class="fa-solid fa-list"></i> Bulk Add</button>
          <button class="ap-btn ap-btn-primary ap-btn-sm" onclick="AP.addStoreRow()"><i class="fa-solid fa-plus"></i> Add Row</button>
        </div>
      </div>
      <div class="ap-card-body">
        <p class="ap-hint" style="margin-bottom:10px">One Amazon Store ID per row. Each ID attaches to a single site. Edit a cell and tab/click out to save. Click a Products count to drill down.</p>
        <table class="ap-stores-table" id="ap-stores-table">
          <thead><tr>
            <th style="width:22%">Store ID</th>
            <th style="width:24%">Site</th>
            <th style="width:18%">Page Pattern <small style="color:#666;font-weight:400">(blank = whole site)</small></th>
            <th style="width:11%;text-align:right">Clicks (30d)</th>
            <th style="width:11%;text-align:right">Products</th>
            <th style="width:6%;text-align:center">On</th>
            <th style="width:6%"></th>
          </tr></thead>
          <tbody><tr><td colspan="7" style="text-align:center;color:#888;padding:18px">Loading…</td></tr></tbody>
        </table>
        <datalist id="ap-known-sites"></datalist>
      </div>
    </div>

    <!-- Sparkline -->
    <div class="ap-card">
      <div class="ap-card-header"><i class="fa-solid fa-chart-line"></i> Clicks · Last 30 Days</div>
      <div class="ap-card-body" style="padding:8px 12px"><canvas id="ap-dash-sparkline" height="80"></canvas></div>
    </div>

    <!-- Refresh schedule + Top products side-by-side -->
    <div class="ap-dash-row">
      <div class="ap-card">
        <div class="ap-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Refresh Schedule</div>
        <div class="ap-card-body" style="padding:0">
          <table class="ap-stats-table" id="ap-tasks-table">
            <thead><tr><th>Task</th><th>Site</th><th>Next Run</th><th>Last</th><th></th></tr></thead>
            <tbody><tr><td colspan="5" style="text-align:center;color:#888;padding:18px">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="ap-card">
        <div class="ap-card-header"><i class="fa-solid fa-trophy"></i> Top Products (30d)</div>
        <div class="ap-card-body" style="padding:0">
          <table class="ap-stats-table" id="ap-dash-top-products">
            <thead><tr><th>#</th><th>Product ID</th><th style="text-align:right">Clicks</th></tr></thead>
            <tbody><tr><td colspan="3" style="text-align:center;color:#888;padding:18px">No click data yet</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════ PRODUCTS TAB ═══════════ -->
  <div class="ap-tab-content" id="ap-tab-products">
    <!-- Toolbar -->
    <div class="ap-toolbar">
      <div class="ap-toolbar-left" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label class="ap-label" style="margin:0;font-size:0.8rem;color:#aaa">Store:</label>
        <select class="ap-input ap-store-pulldown" id="ap-prod-store-select" style="width:auto;min-width:240px"></select>
        <a id="ap-prod-visit-link" href="#" target="_blank" rel="noopener" class="ap-visit-btn" style="display:none">
          <i class="fa-solid fa-arrow-up-right-from-square"></i>
          <span class="ap-visit-text">Visit</span>
        </a>
        <span id="ap-prod-store-meta" style="font-size:0.78rem;color:#888;margin-left:6px"></span>
      </div>
      <div class="ap-toolbar-right">
        <button class="ap-btn ap-btn-ai" onclick="AP.openAiModal()"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Discover</button>
        <button class="ap-btn ap-btn-primary" onclick="AP.openProductModal()"><i class="fa-solid fa-plus"></i> Add Product</button>
      </div>
    </div>

    <!-- Inline Pipeline panel (shown when a store is selected) -->
    <div class="ap-pipeline-panel" id="ap-pipeline-panel" style="display:none">
      <div class="ap-pipeline-head">
        <span class="ap-pipeline-label"><i class="fa-solid fa-wand-magic-sparkles"></i> Pipeline</span>
        <span class="ap-pipeline-task" id="ap-pipeline-task">—</span>
        <span class="ap-pipeline-next" id="ap-pipeline-next"></span>
        <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
          <button class="ap-btn ap-btn-sm ap-btn-primary" onclick="AP.openQuickPrompt()" title="Quick edit just the prompt"><i class="fa-solid fa-pen"></i> Edit Prompt</button>
          <button class="ap-btn ap-btn-sm" onclick="AP.openPromptForCurrentStore()" title="Edit full pipeline (schedule, filters, etc)"><i class="fa-solid fa-sliders"></i> Edit Pipeline</button>
          <button class="ap-btn ap-btn-sm ap-btn-ai" onclick="AP.runCurrentStoreSandbox()" title="Run prompt → preview for curation (does NOT touch live)"><i class="fa-solid fa-flask"></i> Sandbox</button>
          <button class="ap-btn ap-btn-sm ap-btn-danger" onclick="AP.runCurrentStoreLive()" title="Run prompt now → writes to live"><i class="fa-solid fa-bolt"></i> Get Products</button>
        </div>
      </div>
      <div class="ap-pipeline-prompt" id="ap-pipeline-prompt">No prompt set yet — click <strong>Edit Prompt</strong> to author one.</div>
    </div>

    <!-- Sandbox preview state (replaces grid when sandbox running/done) -->
    <div class="ap-sandbox-state" id="ap-sandbox-state" style="display:none"></div>

    <!-- Quick prompt editor (lightweight inline modal) -->
    <div class="ap-modal-overlay" id="ap-quick-prompt-modal">
      <div class="ap-modal">
        <div class="ap-modal-header">
          <span><i class="fa-solid fa-pen"></i> Edit Prompt — <span id="ap-qp-tag"></span></span>
          <button class="ap-modal-close" onclick="AP.closeQuickPrompt()">&times;</button>
        </div>
        <div class="ap-modal-body">
          <p class="ap-hint">Edit only the prompt body. For schedule, category, and filters, use <strong>Edit Pipeline</strong>.</p>
          <textarea class="ap-input ap-textarea" id="ap-qp-theme" rows="10" placeholder="What should the agent look for? Be specific — ‘premium coffee gear and beans’ vs ‘random kitchen stuff’."></textarea>
        </div>
        <div class="ap-modal-footer">
          <button class="ap-btn" onclick="AP.closeQuickPrompt()">Cancel</button>
          <button class="ap-btn ap-btn-primary" onclick="AP.saveQuickPrompt()"><i class="fa-solid fa-floppy-disk"></i> Save Prompt</button>
        </div>
      </div>
    </div>

    <!-- Sort/search/filter row -->
    <div class="ap-prod-filterbar">
      <div class="ap-prod-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="ap-prod-search" placeholder="Search title or description…" autocomplete="off">
      </div>
      <select id="ap-prod-sort" class="ap-input ap-prod-sort">
        <option value="default">Sort: default order</option>
        <option value="price-asc">Price ↑ (low → high)</option>
        <option value="price-desc">Price ↓ (high → low)</option>
        <option value="title-asc">Title A → Z</option>
        <option value="title-desc">Title Z → A</option>
        <option value="newest">Newest first</option>
        <option value="rating-desc">Rating ↓</option>
      </select>
      <label class="ap-toggle-label" style="font-size:0.8rem">
        <input type="checkbox" id="ap-prod-show-ignored"> <span>Show ignored</span>
      </label>
      <label class="ap-toggle-label" style="font-size:0.8rem">
        <input type="checkbox" id="ap-prod-show-unavail"> <span>Show unavailable</span>
      </label>
      <button class="ap-btn ap-btn-sm" onclick="AP.checkSiteAvailability()" title="Hit Amazon, mark any out-of-stock products"><i class="fa-solid fa-rotate"></i> Check availability</button>
      <button class="ap-btn ap-btn-sm ap-veto-pill" id="ap-veto-pill" onclick="AP.openVetoList()" title="Show vetoed products for this site" style="display:none">
        <i class="fa-solid fa-ban"></i> Veto list <span id="ap-veto-count">0</span>
      </button>
    </div>

    <!-- Category pills (per-site) -->
    <div class="ap-category-bar" style="margin-top:10px"></div>

    <!-- Product Grid -->
    <div class="ap-grid" id="ap-product-grid">
      <div class="ap-loading">Select a store to load products…</div>
    </div>
  </div>

  <!-- ═══════════ STATS TAB ═══════════ -->
  <div class="ap-tab-content" id="ap-tab-stats">
    <!-- Summary cards -->
    <div class="ap-stats-summary">
      <div class="ap-stat-card">
        <div class="ap-stat-val" id="ap-stat-clicks">—</div>
        <div class="ap-stat-lbl">Outbound Clicks <span class="ap-stat-range">30d</span></div>
      </div>
      <div class="ap-stat-card">
        <div class="ap-stat-val" id="ap-stat-pageviews">—</div>
        <div class="ap-stat-lbl">Pageviews <span class="ap-stat-range">30d</span></div>
      </div>
      <div class="ap-stat-card">
        <div class="ap-stat-val" id="ap-stat-outbound">—</div>
        <div class="ap-stat-lbl">Pixel Outbound <span class="ap-stat-range">30d</span></div>
      </div>
    </div>

    <!-- Charts row -->
    <div class="ap-stats-charts">
      <div class="ap-card ap-chart-card">
        <div class="ap-card-header"><i class="fa-solid fa-arrow-up-right-from-square"></i> Daily Clicks (referral log)</div>
        <div class="ap-card-body" style="padding:8px 12px">
          <canvas id="ap-clicks-chart" height="120"></canvas>
        </div>
      </div>
      <div class="ap-card ap-chart-card">
        <div class="ap-card-header"><i class="fa-solid fa-eye"></i> Daily Pageviews (pixel)</div>
        <div class="ap-card-body" style="padding:8px 12px">
          <canvas id="ap-views-chart" height="120"></canvas>
        </div>
      </div>
    </div>

    <!-- Top products table -->
    <div class="ap-card" style="margin-top:18px">
      <div class="ap-card-header"><i class="fa-solid fa-trophy"></i> Top Products by Clicks (30d)</div>
      <div class="ap-card-body" style="padding:0">
        <table class="ap-stats-table" id="ap-top-products-table">
          <thead><tr><th>#</th><th>Product ID</th><th>Clicks</th></tr></thead>
          <tbody><tr><td colspan="3" style="text-align:center;color:var(--text-muted,#888);padding:18px">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════ AMAZON TAB ═══════════ -->
  <div class="ap-tab-content" id="ap-tab-amazon">
    <div class="ap-settings-grid">

      <div class="ap-card ap-card-full">
        <div class="ap-card-header"><i class="fa-brands fa-amazon" style="color:#ff9900"></i> Amazon Creators API <span style="font-size:0.7rem;background:rgba(255,153,0,0.15);color:#ff9900;padding:2px 7px;border-radius:10px;margin-left:8px">v3.1</span></div>
        <div class="ap-card-body">
          <p class="ap-hint">Login with Amazon (LwA) credentials. These are <strong>shared</strong> across the application — per-site Store IDs live in the <em>Store Coverage</em> table on the Dashboard.</p>
          <div class="ap-form-row">
            <div class="ap-form-col">
              <label class="ap-label">Client ID <span class="ap-hint-inline">(Credential ID)</span></label>
              <input type="text" class="ap-input" id="ap-cfg-creators-client-id" placeholder="amzn1.application-oa2-client.…" autocomplete="off">
            </div>
            <div class="ap-form-col">
              <label class="ap-label">Client Secret</label>
              <input type="password" class="ap-input" id="ap-cfg-creators-client-secret" placeholder="amzn1.oa2-cs.v1.…" autocomplete="off">
            </div>
          </div>
          <input type="hidden" id="ap-cfg-associate-tag">
          <input type="hidden" id="ap-cfg-marketplace" value="www.amazon.com">
          <div style="display:flex;gap:10px;margin-top:12px;align-items:center;flex-wrap:wrap">
            <button class="ap-btn ap-btn-sm" onclick="AP.testAmazon()"><i class="fa-solid fa-plug-circle-check"></i> Test Credentials</button>
            <span class="ap-api-status" id="ap-amazon-status"></span>
          </div>
          <p class="ap-hint" style="margin-top:14px;border-left:3px solid #6366f1;padding-left:10px">
            <strong>Note:</strong> Even when this API isn't connecting, the Add Product flow still works — we use Amazon's deterministic CDN image URL (derived from ASIN) and never hallucinate. The Creators API is only needed for live price/title lookups.
          </p>
        </div>
      </div>

      <div class="ap-card">
        <div class="ap-card-header" style="opacity:0.6"><i class="fa-brands fa-amazon"></i> PA-API v5 <span style="font-size:0.7rem;background:rgba(239,68,68,0.15);color:#ef4444;padding:2px 7px;border-radius:10px;margin-left:6px">Deprecated May 2026</span></div>
        <div class="ap-card-body">
          <p class="ap-hint">Legacy Product Advertising API — being retired. Kept here for any sites still on the old flow.</p>
          <label class="ap-label">Access Key</label>
          <input type="text" class="ap-input" id="ap-cfg-access-key" placeholder="AKIA…">
          <label class="ap-label">Secret Key</label>
          <input type="password" class="ap-input" id="ap-cfg-secret-key" placeholder="Secret key">
        </div>
      </div>

      <div class="ap-card">
        <div class="ap-card-header"><i class="fa-solid fa-list"></i> Bulk Add Store IDs</div>
        <div class="ap-card-body">
          <p class="ap-hint">Paste your full list of Amazon Associate Store IDs here — one per line, comma- or space-separated. Existing IDs are skipped. Sites are attached separately on the Dashboard's Store Coverage table.</p>
          <textarea class="ap-input ap-textarea" id="ap-amzn-bulk-ids" rows="6" placeholder="yourtag-20&#10;othertag-20&#10;…"></textarea>
          <button class="ap-btn ap-btn-primary" style="margin-top:10px" onclick="AP.bulkSeedFromTextarea()"><i class="fa-solid fa-cloud-arrow-up"></i> Import IDs</button>
          <span class="ap-save-status" id="ap-amzn-bulk-status"></span>
        </div>
      </div>

      <div class="ap-save-bar">
        <button class="ap-btn ap-btn-primary" onclick="AP.saveConfig()"><i class="fa-solid fa-floppy-disk"></i> Save Amazon Settings</button>
        <span class="ap-save-status" id="ap-amzn-save-status"></span>
      </div>
    </div>
  </div>

  <!-- ═══════════ SETTINGS TAB ═══════════ -->
  <div class="ap-tab-content" id="ap-tab-settings">
    <div class="ap-settings-grid">

      <!-- Walmart Affiliate API Card -->
      <div class="ap-card">
        <div class="ap-card-header"><span class="ap-retailer-icon" style="color:#0071dc">W</span> Walmart Affiliate API</div>
        <div class="ap-card-body">
          <p class="ap-hint">Walmart Affiliate Program — access product catalog, pricing, and earn commissions on referred sales.</p>
          <label class="ap-label">API Key</label>
          <input type="text" class="ap-input" id="ap-cfg-walmart-api-key" placeholder="API key from affiliate dashboard">
          <label class="ap-label">Publisher ID</label>
          <input type="text" class="ap-input" id="ap-cfg-walmart-publisher-id" placeholder="Publisher / Impact Radius ID">
          <label class="ap-label">Link ID</label>
          <input type="text" class="ap-input" id="ap-cfg-walmart-link-id" placeholder="Impact Radius link ID (optional)">
          <div class="ap-api-status" id="ap-walmart-status"></div>
        </div>
      </div>

      <!-- Best Buy Affiliate API Card -->
      <div class="ap-card">
        <div class="ap-card-header"><span class="ap-retailer-icon" style="color:#0046be">BB</span> Best Buy Affiliate API</div>
        <div class="ap-card-body">
          <p class="ap-hint">Best Buy Products API — search electronics catalog and earn commissions via affiliate program.</p>
          <label class="ap-label">API Key</label>
          <input type="text" class="ap-input" id="ap-cfg-bestbuy-api-key" placeholder="Products API key">
          <label class="ap-label">Affiliate ID</label>
          <input type="text" class="ap-input" id="ap-cfg-bestbuy-affiliate-id" placeholder="CJ / Impact affiliate ID (optional)">
          <div class="ap-api-status" id="ap-bestbuy-status"></div>
        </div>
      </div>

      <!-- Default Settings Card -->
      <div class="ap-card">
        <div class="ap-card-header"><i class="fa-solid fa-link"></i> Default Settings</div>
        <div class="ap-card-body">
          <label class="ap-label">Default Affiliate Tag</label>
          <input type="text" class="ap-input" id="ap-cfg-default-tag" placeholder="mysite-20">
          <p class="ap-hint">Applied to new products when no tag is detected from the URL.</p>

          <label class="ap-label" style="margin-top:12px">AI Discovery</label>
          <label class="ap-toggle-label">
            <input type="checkbox" id="ap-cfg-ai-enabled">
            <span>Enable AI-powered product discovery</span>
          </label>
        </div>
      </div>
    </div>

    <div class="ap-save-bar">
      <button class="ap-btn ap-btn-primary" onclick="AP.saveConfig()"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
      <span class="ap-save-status" id="ap-save-status"></span>
    </div>
  </div>
</div>

<!-- ═══════════ ADD/EDIT PRODUCT MODAL ═══════════ -->
<div class="ap-modal-overlay" id="ap-product-modal">
  <div class="ap-modal">
    <div class="ap-modal-header">
      <span id="ap-modal-title">Add Product</span>
      <button class="ap-modal-close" onclick="AP.closeProductModal()">&times;</button>
    </div>
    <div class="ap-modal-body">
      <input type="hidden" id="ap-prod-id">
      <input type="hidden" id="ap-prod-source" value="amazon">
      <input type="hidden" id="ap-prod-asin">
      <input type="hidden" id="ap-prod-tag">
      <input type="hidden" id="ap-prod-rating">

      <!-- Step 1: paste URL + resolve -->
      <div id="ap-resolve-step">
        <label class="ap-label">Amazon Product URL <span class="required">*</span></label>
        <div class="ap-url-row">
          <input type="url" class="ap-input" id="ap-prod-url" placeholder="Paste an Amazon product link (https://www.amazon.com/dp/...)">
          <button class="ap-btn ap-btn-primary" id="ap-resolve-btn" onclick="AP.resolveProductUrl()"><i class="fa-solid fa-bolt"></i> Resolve</button>
        </div>
        <p class="ap-hint" style="margin-top:6px">We'll fetch the product page, pull the official Amazon image + title + price, and verify the image actually resolves. If the provider doesn't give us a real image, we won't add the product.</p>
        <div id="ap-resolve-status" class="ap-resolve-status"></div>
      </div>

      <!-- Step 2: resolved preview + category -->
      <div id="ap-resolved-step" style="display:none">
        <div class="ap-resolved-card">
          <img id="ap-resolved-img" class="ap-resolved-img" src="" alt="">
          <div class="ap-resolved-info">
            <div class="ap-resolved-title" id="ap-resolved-title"></div>
            <div class="ap-resolved-meta">
              <span class="ap-resolved-price" id="ap-resolved-price"></span>
              <span class="ap-resolved-asin" id="ap-resolved-asin"></span>
            </div>
            <a id="ap-resolved-url-link" href="" target="_blank" class="ap-resolved-link"><i class="fa-solid fa-up-right-from-square"></i> View on Amazon</a>
          </div>
        </div>

        <div class="ap-form-row" style="margin-top:14px">
          <div class="ap-form-col">
            <label class="ap-label">Category <span class="required">*</span></label>
            <input type="text" class="ap-input" id="ap-prod-category" placeholder="e.g. studio-gear" list="ap-cat-datalist">
            <datalist id="ap-cat-datalist"></datalist>
          </div>
          <div class="ap-form-col">
            <label class="ap-label">Title <small style="color:#888">(editable)</small></label>
            <input type="text" class="ap-input" id="ap-prod-title">
          </div>
        </div>

        <label class="ap-label">Description <small style="color:#888">(optional, your own copy)</small></label>
        <textarea class="ap-input ap-textarea" id="ap-prod-description" placeholder="Short description" rows="2"></textarea>

        <input type="hidden" id="ap-prod-price">
        <input type="hidden" id="ap-prod-image">

        <label class="ap-toggle-label" style="margin-top:10px">
          <input type="checkbox" id="ap-prod-enabled" checked>
          <span>Enabled (visible on frontend)</span>
        </label>
      </div>
    </div>
    <div class="ap-modal-footer">
      <button class="ap-btn" onclick="AP.closeProductModal()">Cancel</button>
      <button class="ap-btn" id="ap-modal-back" onclick="AP.resolveBack()" style="display:none"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="ap-btn ap-btn-primary" id="ap-modal-save" onclick="AP.saveProduct()" style="display:none"><i class="fa-solid fa-floppy-disk"></i> Save Product</button>
    </div>
  </div>
</div>

<!-- ═══════════ AI DISCOVER MODAL ═══════════ -->
<div class="ap-modal-overlay" id="ap-ai-modal">
  <div class="ap-modal ap-modal-wide">
    <div class="ap-modal-header">
      <span><i class="fa-solid fa-wand-magic-sparkles"></i> AI Product Discovery</span>
      <button class="ap-modal-close" onclick="AP.closeAiModal()">&times;</button>
    </div>
    <div class="ap-modal-body">
      <label class="ap-label">Prompt</label>
      <textarea class="ap-input ap-textarea" id="ap-ai-prompt" rows="3" placeholder="e.g. Top 10 studio microphones under $200 for podcasting"></textarea>

      <div class="ap-form-row">
        <div class="ap-form-col">
          <label class="ap-label">Category</label>
          <input type="text" class="ap-input" id="ap-ai-category" placeholder="studio-gear" list="ap-cat-datalist">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">Count</label>
          <input type="number" class="ap-input" id="ap-ai-count" min="1" max="20" value="5">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">AI Provider</label>
          <select class="ap-input" id="ap-ai-provider">
            <option value="">Default</option>
          </select>
        </div>
      </div>

      <button class="ap-btn ap-btn-ai" id="ap-ai-generate-btn" onclick="AP.aiGenerate()" style="margin:8px 0">
        <i class="fa-solid fa-bolt"></i> Generate Suggestions
      </button>

      <!-- AI Results -->
      <div class="ap-ai-results" id="ap-ai-results" style="display:none">
        <div class="ap-ai-results-header">
          <span id="ap-ai-results-count">0 suggestions</span>
          <span class="ap-ai-model" id="ap-ai-model"></span>
        </div>
        <div class="ap-ai-grid" id="ap-ai-grid"></div>
      </div>
    </div>
    <div class="ap-modal-footer">
      <button class="ap-btn" onclick="AP.closeAiModal()">Cancel</button>
      <button class="ap-btn ap-btn-primary" id="ap-ai-approve-btn" onclick="AP.aiApprove()" style="display:none">
        <i class="fa-solid fa-check"></i> Approve & Add Selected
      </button>
    </div>
  </div>
</div>

<!-- ═══════════ VETO LIST MODAL ═══════════ -->
<div class="ap-modal-overlay" id="ap-veto-modal">
  <div class="ap-modal ap-modal-wide">
    <div class="ap-modal-header">
      <span><i class="fa-solid fa-ban"></i> Veto List — <span id="ap-veto-modal-site"></span></span>
      <button class="ap-modal-close" onclick="AP.closeVetoList()">&times;</button>
    </div>
    <div class="ap-modal-body" id="ap-veto-modal-body" style="max-height:75vh;overflow-y:auto">
      <div style="text-align:center;color:#888;padding:30px">Loading…</div>
    </div>
    <div class="ap-modal-footer">
      <span style="font-size:0.78rem;color:#888;margin-right:auto">Vetoed products are permanently excluded from pipeline suggestions and never re-pulled.</span>
      <button class="ap-btn" onclick="AP.closeVetoList()">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════ PROMPT EDITOR MODAL ═══════════ -->
<div class="ap-modal-overlay" id="ap-prompt-modal">
  <div class="ap-modal ap-modal-wide">
    <div class="ap-modal-header">
      <span><i class="fa-solid fa-wand-magic-sparkles"></i> <span id="ap-pr-title">Pipeline Editor</span></span>
      <button class="ap-modal-close" onclick="AP.closePromptEditor()">&times;</button>
    </div>
    <div class="ap-modal-body" id="ap-pr-body" style="max-height:78vh;overflow-y:auto">
      <input type="hidden" id="ap-pr-tag">
      <input type="hidden" id="ap-pr-task-id">

      <!-- Header banner: store info -->
      <div class="ap-pr-banner">
        <div><span class="ap-pr-banner-label">Store ID</span><code id="ap-pr-banner-tag"></code></div>
        <div><span class="ap-pr-banner-label">Site</span><span id="ap-pr-banner-site"></span></div>
        <div><span class="ap-pr-banner-label">Pattern</span><span id="ap-pr-banner-pattern">site default</span></div>
        <div><span class="ap-pr-banner-label">Task</span><span id="ap-pr-banner-task">none yet</span></div>
      </div>

      <!-- Theme / brief -->
      <label class="ap-label" style="margin-top:14px">Theme / Creative Brief <small style="color:#888">— what should the agent look for?</small></label>
      <textarea class="ap-input ap-textarea" id="ap-pr-theme" rows="6" placeholder="e.g. Premium coffee gear and beans — stainless steel espresso pots, burr grinders, pour-over setups. Stuff Tom would actually recommend — the tool that lasts, the bean that drinks clean. Rotate weekly: brewing equipment, grinders, beans, accessories."></textarea>

      <!-- Search keywords -->
      <label class="ap-label">Amazon Search Keywords <small style="color:#888">— space- or comma-separated</small></label>
      <textarea class="ap-input ap-textarea" id="ap-pr-search" rows="2" placeholder="espresso maker moka pot French press AeroPress pour over grinder kettle beans"></textarea>

      <!-- Category + page URL row -->
      <div class="ap-form-row">
        <div class="ap-form-col">
          <label class="ap-label">Category <span class="required">*</span></label>
          <input type="text" class="ap-input" id="ap-pr-category" placeholder="e.g. coffee-picks">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">Target Page URL <small style="color:#888">— optional, for split-test patterns</small></label>
          <input type="text" class="ap-input" id="ap-pr-pageurl" placeholder="e.g. /articles/espresso-guide">
        </div>
      </div>

      <!-- Filters row -->
      <div class="ap-form-row">
        <div class="ap-form-col">
          <label class="ap-label"># Products / Run</label>
          <input type="number" class="ap-input" id="ap-pr-xnum" value="12" min="1" max="20">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">Min Rating</label>
          <input type="number" class="ap-input" id="ap-pr-rating" value="4.0" min="0" max="5" step="0.1">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">Prune Old <small style="color:#888">— keep newest N</small></label>
          <input type="number" class="ap-input" id="ap-pr-prune" value="12" min="0" max="100">
        </div>
      </div>

      <div class="ap-form-row">
        <div class="ap-form-col">
          <label class="ap-label">Min Price ($)</label>
          <input type="text" class="ap-input" id="ap-pr-pmin" placeholder="e.g. 15">
        </div>
        <div class="ap-form-col">
          <label class="ap-label">Max Price ($)</label>
          <input type="text" class="ap-input" id="ap-pr-pmax" placeholder="e.g. 500">
        </div>
      </div>

      <!-- Schedule -->
      <h4 style="margin:18px 0 6px;color:#a78bfa;font-size:0.95rem"><i class="fa-solid fa-clock"></i> Schedule</h4>
      <div class="ap-form-row">
        <div class="ap-form-col">
          <label class="ap-label">Recurrence</label>
          <select class="ap-input" id="ap-pr-recur">
            <option value="recurring">Recurring weekly</option>
            <option value="once">One-off (no schedule)</option>
          </select>
        </div>
        <div class="ap-form-col" id="ap-pr-day-col">
          <label class="ap-label">Day</label>
          <select class="ap-input" id="ap-pr-day">
            <option value="monday">Monday</option>
            <option value="tuesday">Tuesday</option>
            <option value="wednesday" selected>Wednesday</option>
            <option value="thursday">Thursday</option>
            <option value="friday">Friday</option>
            <option value="saturday">Saturday</option>
            <option value="sunday">Sunday</option>
          </select>
        </div>
        <div class="ap-form-col" id="ap-pr-time-col">
          <label class="ap-label">Time (UTC)</label>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="number" class="ap-input" id="ap-pr-hour" value="2" min="0" max="23" style="width:64px">
            <span style="color:#888">:</span>
            <input type="number" class="ap-input" id="ap-pr-minute" value="5" min="0" max="59" style="width:64px">
          </div>
        </div>
      </div>

      <label class="ap-toggle-label" style="margin-top:14px">
        <input type="checkbox" id="ap-pr-enabled" checked>
        <span>Enabled (will run on schedule)</span>
      </label>

      <div id="ap-pr-status" class="ap-pr-status" style="margin-top:12px"></div>
    </div>
    <div class="ap-modal-footer ap-pr-footer">
      <button class="ap-btn" onclick="AP.closePromptEditor()">Cancel</button>
      <button class="ap-btn ap-btn-primary" onclick="AP.savePromptTask()" title="Save the task — runs on schedule">
        <i class="fa-solid fa-floppy-disk"></i> Save Task
      </button>
      <button class="ap-btn ap-btn-ai" onclick="AP.runPromptSandbox()" title="Run now → sandbox for review (does not write to live)">
        <i class="fa-solid fa-flask"></i> Run → Sandbox
      </button>
      <button class="ap-btn ap-btn-danger" onclick="AP.runPromptLive()" title="Run now → writes directly to live products.json">
        <i class="fa-solid fa-bolt"></i> Run → Live
      </button>
    </div>
  </div>
</div>

<!-- ═══════════ PER-SITE PRODUCTS MODAL (drill-down from Stores table) ═══════════ -->
<div class="ap-modal-overlay" id="ap-site-products-modal">
  <div class="ap-modal ap-modal-wide">
    <div class="ap-modal-header">
      <span id="ap-sp-title">Products</span>
      <button class="ap-modal-close" onclick="AP.closeSiteProducts()">&times;</button>
    </div>
    <div class="ap-modal-body" id="ap-sp-body" style="max-height:70vh;overflow-y:auto">
      <div style="text-align:center;color:#888;padding:30px">Loading…</div>
    </div>
    <div class="ap-modal-footer">
      <button class="ap-btn" onclick="AP.closeSiteProducts()">Close</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
window.AP_BOOT = {
  apiUrl: '<?= $moduleBase ?>/api.php',
  aiApiUrl: '<?= sc_asset('/admin/modules/AIResources/api.php') ?>'
};
</script>
<script src="<?= $moduleBase ?>/js/ap-admin.js"></script>
<script>
(function(){
  var _statsLoaded = false;
  var _clicksChart = null;
  var _viewsChart  = null;

  function loadStats(){
    if (_statsLoaded) return;
    fetch(AP_BOOT.apiUrl + '?action=get_referral_stats')
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.ok) return;
        var d = res.data;
        document.getElementById('ap-stat-clicks').textContent    = (d.totals.clicks || 0).toLocaleString();
        document.getElementById('ap-stat-pageviews').textContent = (d.totals.pageviews || 0).toLocaleString();
        document.getElementById('ap-stat-outbound').textContent  = (d.totals.outbound_clicks || 0).toLocaleString();

        // Build 30-day label array
        var labels = [], clickData = [], viewData = [];
        var now = new Date();
        for (var i = 29; i >= 0; i--) {
          var dt = new Date(now); dt.setDate(dt.getDate() - i);
          var key = dt.toISOString().slice(0,10);
          labels.push(key.slice(5)); // MM-DD
          clickData.push((d.referrals[key] || {clicks:0}).clicks);
          viewData.push((d.pixels[key]    || {pageviews:0}).pageviews);
        }

        var cOpts = { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} };

        if (_clicksChart) _clicksChart.destroy();
        _clicksChart = new Chart(document.getElementById('ap-clicks-chart'), {
          type:'bar', data:{ labels:labels, datasets:[{label:'Clicks', data:clickData, backgroundColor:'rgba(99,102,241,0.7)'}] }, options:cOpts
        });

        if (_viewsChart) _viewsChart.destroy();
        _viewsChart = new Chart(document.getElementById('ap-views-chart'), {
          type:'bar', data:{ labels:labels, datasets:[{label:'Pageviews', data:viewData, backgroundColor:'rgba(34,197,94,0.7)'}] }, options:cOpts
        });

        // Top products table
        var tbody = document.querySelector('#ap-top-products-table tbody');
        var rows = Object.entries(d.top_products);
        if (rows.length === 0) {
          tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-muted,#888);padding:18px">No click data yet</td></tr>';
        } else {
          tbody.innerHTML = rows.map(function(e, i){
            return '<tr><td>' + (i+1) + '</td><td>' + e[0] + '</td><td>' + e[1] + '</td></tr>';
          }).join('');
        }

        _statsLoaded = true;
      })
      .catch(function(e){ console.warn('Stats load failed', e); });
  }

  // Hook into tab click
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.ap-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        if (tab.dataset.tab === 'stats') loadStats();
      });
    });
  });
})();

/* ═══════════ DASHBOARD TAB ═══════════ */
(function(){
  var _sparkChart = null;

  function load(){
    fetch(AP_BOOT.apiUrl + '?action=dashboard_summary')
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.ok) return;
        var d = res.data;

        // KPIs
        document.getElementById('ap-kpi-stores').textContent   = d.kpis.total_stores;
        document.getElementById('ap-kpi-sites').textContent    = d.kpis.total_sites;
        document.getElementById('ap-kpi-products').textContent = d.kpis.total_products;
        document.getElementById('ap-kpi-clicks').textContent   = (d.kpis.clicks_30d || 0).toLocaleString();

        // Sparkline
        var labels = d.sparkline.map(function(s){ return s.date.slice(5); });
        var data   = d.sparkline.map(function(s){ return s.clicks; });
        if (_sparkChart) _sparkChart.destroy();
        _sparkChart = new Chart(document.getElementById('ap-dash-sparkline'), {
          type: 'line',
          data: { labels: labels, datasets: [{ label:'Clicks', data: data, borderColor:'#6366f1', backgroundColor:'rgba(99,102,241,0.18)', tension:0.3, fill:true, pointRadius:0 }] },
          options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
        });

        // Tasks
        var ttbody = document.querySelector('#ap-tasks-table tbody');
        if (!d.tasks.length) {
          ttbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#888;padding:18px">No refresh tasks scheduled</td></tr>';
        } else {
          ttbody.innerHTML = d.tasks.map(function(t){
            var status = t.last_status === 'success' ? '<span style="color:#10b981">✓</span>' : (t.last_status === 'error' ? '<span style="color:#ef4444">✗</span>' : '<span style="color:#888">—</span>');
            var nextRun = t.next_run ? new Date(t.next_run).toLocaleString(undefined, {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : '—';
            var dim = t.enabled ? '' : ' style="opacity:0.45"';
            var actions = '<button class="ap-card-action" onclick="AP.runTask(\'' + esc(t.id) + '\')" title="Run now"><i class="fa-solid fa-play"></i></button>'
                       + '<button class="ap-card-action delete" onclick="AP.deleteTask(\'' + esc(t.id) + '\')" title="Delete task"><i class="fa-solid fa-trash"></i></button>';
            return '<tr' + dim + '>'
              + '<td><small>' + esc(t.id) + '</small></td>'
              + '<td>' + esc(t.target_domain) + '</td>'
              + '<td>' + nextRun + '</td>'
              + '<td>' + status + '</td>'
              + '<td style="text-align:right;white-space:nowrap">' + actions + '</td>'
              + '</tr>';
          }).join('');
        }

        // Top products
        var ptbody = document.querySelector('#ap-dash-top-products tbody');
        var topRows = Object.entries(d.top_products);
        if (!topRows.length) {
          ptbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#888;padding:18px">No click data yet</td></tr>';
        } else {
          ptbody.innerHTML = topRows.map(function(e, i){
            return '<tr><td>' + (i+1) + '</td><td><small>' + esc(e[0]) + '</small></td><td style="text-align:right">' + e[1] + '</td></tr>';
          }).join('');
        }
      })
      .catch(function(e){ console.warn('Dashboard load failed', e); });
  }

  function esc(s){ var el = document.createElement('span'); el.textContent = String(s == null ? '' : s); return el.innerHTML; }

  // Expose for AP.deleteTask reload hook
  window.AP_loadDashboard = load;

  document.addEventListener('DOMContentLoaded', function(){
    load();
    document.querySelectorAll('.ap-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        if (tab.dataset.tab === 'dashboard') load();
      });
    });
  });
})();
</script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
