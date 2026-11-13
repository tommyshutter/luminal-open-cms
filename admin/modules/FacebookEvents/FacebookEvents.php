<?php
/**
 * Luminal CMS — Facebook Events Module
 *
 * Facebook Graph API integration for importing and syncing events
 * from Facebook Pages to Luminal CMS event system.
 *
 * @package    LuminalCMS
 * @module     FacebookEvents
 * @version    1.0.0
 * @file       /admin/modules/FacebookEvents/FacebookEvents.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

$moduleBase = sc_asset('/admin/modules/FacebookEvents');

// Include Luminal admin header
require_once __DIR__ . '/../../admin_header.php';
?>

<h1 class="panel_header_h1">Facebook Events</h1>

<link rel="stylesheet" href="<?= $moduleBase ?>/css/facebook-events.css?v=<?= time() ?>">

<div class="fbe-container">
  <div class="fbe-topbar">
    <div class="fbe-status" id="fbe-status"></div>
    <div class="fbe-actions">
      <button class="fbe-btn fbe-btn-primary" id="fbe-save">Save Settings</button>
      <button class="fbe-btn" id="fbe-clear-cache">Clear Cache</button>
      <button class="fbe-btn" id="fbe-validate">Validate</button>
      <button class="fbe-btn" id="fbe-export-json" title="Export config JSON for AgentScheduler">Export JSON</button>
      <div class="fbe-indicator" id="fbe-indicator" title="Not validated">?</div>
    </div>
  </div>

  <div class="fbe-grid">
    <div class="fbe-card">
      <div class="fbe-card-header">API Configuration</div>
      <div class="fbe-card-body">
        <div class="fbe-field">
          <label>Facebook App ID</label>
          <div class="fbe-input-row">
            <input type="text" id="fbe-app-id" placeholder="e.g. 1234567890123456">
            <span class="fbe-badge" id="fbe-app-badge"></span>
          </div>
          <span class="fbe-help">From <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a></span>
        </div>

        <div class="fbe-field">
          <label>Access Token</label>
          <div class="fbe-input-row">
            <input type="text" id="fbe-token" placeholder="Page access token">
            <span class="fbe-badge" id="fbe-token-badge"></span>
          </div>
        </div>

        <div class="fbe-field">
          <label>Page ID / URL</label>
          <div class="fbe-input-group">
            <input type="text" id="fbe-page-id" placeholder="e.g. 123456 or facebook.com/page">
            <button class="fbe-btn fbe-btn-sm" id="fbe-resolve">Resolve ID</button>
          </div>
        </div>

        <div class="fbe-resolved" id="fbe-resolved" style="display:none">
          <div class="fbe-resolved-row">
            <span class="fbe-resolved-label">Name:</span>
            <span class="fbe-resolved-value" id="fbe-res-name"></span>
          </div>
          <div class="fbe-resolved-row">
            <span class="fbe-resolved-label">Type:</span>
            <span class="fbe-resolved-value" id="fbe-res-type"></span>
          </div>
          <div class="fbe-resolved-row">
            <span class="fbe-resolved-label">Categories:</span>
            <span class="fbe-resolved-value" id="fbe-res-cats"></span>
          </div>
        </div>

        <div class="fbe-field" style="margin-top:16px;border-top:1px solid var(--fbe-border);padding-top:16px;">
          <label>Fallback Events URL</label>
          <input type="text" id="fbe-fallback" placeholder="https://www.facebook.com/your-page/events">
          <span class="fbe-help">Shown when API returns no events</span>
        </div>
      </div>
    </div>

    <div class="fbe-card">
      <div class="fbe-card-header">Display Settings</div>
      <div class="fbe-card-body">
        <div class="fbe-field-row">
          <label>Layout</label>
          <select id="fbe-layout">
            <option value="grid">Grid</option>
            <option value="list">List</option>
          </select>
        </div>

        <div class="fbe-field-row">
          <label>Columns</label>
          <select id="fbe-columns">
            <option value="auto">Auto (responsive)</option>
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="4">4</option>
          </select>
        </div>

        <div class="fbe-field-row">
          <label>Image Shape</label>
          <select id="fbe-image-shape">
            <option value="natural">Natural (full flyer)</option>
            <option value="portrait">Portrait</option>
            <option value="square">Square</option>
          </select>
        </div>

        <div class="fbe-field-row">
          <label>Events Per Page</label>
          <input type="number" id="fbe-per-page" min="1" max="50" value="10">
        </div>

        <div class="fbe-field-row">
          <label>Fetch Limit</label>
          <input type="number" id="fbe-limit" min="1" max="500" value="100">
        </div>

        <div class="fbe-field-row">
          <label>Time Frame</label>
          <select id="fbe-timeframe">
            <option value="upcoming">Upcoming</option>
            <option value="past">Past</option>
          </select>
        </div>

        <div class="fbe-field-row">
          <label>Cache Duration (sec)</label>
          <input type="number" id="fbe-cache-dur" min="0" max="86400" value="3600">
        </div>

        <div class="fbe-field-row">
          <label>Show Description</label>
          <input type="checkbox" id="fbe-show-desc">
        </div>

        <div class="fbe-field-row">
          <label>Show Location</label>
          <input type="checkbox" id="fbe-show-loc" checked>
        </div>

        <div class="fbe-field-row">
          <label>Show Time</label>
          <input type="checkbox" id="fbe-show-time" checked>
        </div>

        <div class="fbe-field-row">
          <label>Show Canceled</label>
          <input type="checkbox" id="fbe-show-cancel">
        </div>
      </div>
    </div>
  </div>

  <div class="fbe-grid fbe-grid-bottom">
    <div class="fbe-card">
      <div class="fbe-card-header">Event Preview</div>
      <div class="fbe-card-body" id="fbe-preview">
        <div class="fbe-empty">No preview yet. Click Validate to load.</div>
      </div>
    </div>

    <div class="fbe-card">
      <div class="fbe-card-header">API Flight Recorder</div>
      <div class="fbe-card-body fbe-console" id="fbe-log">
        <div class="fbe-log-line fbe-log-info">&gt; Ready. Waiting for action...</div>
      </div>
    </div>
  </div>
</div>

<div id="fbe-toasts" class="fbe-toasts"></div>

<script>
window.FBE_BOOT = {
  endpoints: { api: '<?= $moduleBase ?>/api.php' }
};
</script>
<script src="<?= $moduleBase ?>/js/facebook-events.js?v=<?= time() ?>"></script>
