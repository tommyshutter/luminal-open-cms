<?php
/**
 * SocialPublisher — Admin Dashboard
 * 3 tabs: Providers, Compose Post, Post History
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$_currentUser = guard_user();
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Social Publisher</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/SocialPublisher/css/social-publisher.css') ?>">

<div class="sp-container" style="position:relative">

  <!-- Tabs -->
  <div class="sp-tabs">
    <button class="sp-tab sp-tab-active" data-tab="providers">Providers</button>
    <button class="sp-tab" data-tab="compose">Compose Post</button>
    <button class="sp-tab" data-tab="history">Post History</button>
  </div>

  <!-- ═══ Tab 1: Providers ═══ -->
  <div class="sp-panel" id="spProviders" data-panel="providers">

    <div class="sp-panel-header">
      <h3>Social Accounts</h3>
      <button class="sp-btn sp-btn-primary" id="spAddProvider" type="button">+ Add Provider</button>
    </div>

    <div class="sp-provider-grid" id="spProviderGrid">
      <div class="sp-empty">Loading providers...</div>
    </div>

  </div>

  <!-- ═══ Tab 2: Compose Post ═══ -->
  <div class="sp-panel" id="spCompose" data-panel="compose" style="display:none">

    <h3 class="sp-section-title">Compose Post</h3>

    <!-- Provider Selection -->
    <div class="sp-form-row">
      <label>Post to:</label>
      <div class="sp-provider-checks" id="spComposeProviders">
        <span class="sp-muted">Loading providers...</span>
      </div>
    </div>

    <!-- Content -->
    <div class="sp-form-row">
      <label>Content</label>
      <div class="sp-ai-toggle-row">
        <label class="sp-toggle-label">
          <input type="checkbox" id="spAiToggle">
          <span>AI Generate</span>
        </label>
        <span class="sp-char-count" id="spCharCount">0</span>
      </div>
      <textarea id="spContent" class="sp-input sp-textarea" rows="4" placeholder="Write your post content..."></textarea>
    </div>

    <!-- AI Prompt (hidden by default) -->
    <div class="sp-form-row sp-ai-section" id="spAiSection" style="display:none">
      <label>AI Prompt</label>
      <textarea id="spAiPrompt" class="sp-input" rows="3" placeholder="Describe what you want the AI to write..."></textarea>
      <div class="sp-ai-controls">
        <select id="spAiTone" class="sp-input sp-input-sm">
          <option value="professional">Professional</option>
          <option value="enthusiastic">Enthusiastic</option>
          <option value="friendly">Friendly</option>
          <option value="creative">Creative</option>
          <option value="casual">Casual</option>
        </select>
        <button class="sp-btn sp-btn-secondary" id="spAiGenerate" type="button">Generate</button>
      </div>
    </div>

    <!-- Image Section -->
    <div class="sp-form-row">
      <label>Image</label>
      <div class="sp-image-controls">
        <input type="text" id="spImageUrl" class="sp-input" placeholder="Image URL (https://...)">
        <button class="sp-btn sp-btn-sm" id="spBrowseMedia" type="button">Browse Media</button>
        <button class="sp-btn sp-btn-sm" id="spAiImage" type="button">AI Generate Image</button>
      </div>
      <div class="sp-drop-zone" id="spDropZone">
        <span>Drag &amp; drop an image here</span>
      </div>
      <div class="sp-image-preview" id="spImagePreview" style="display:none">
        <img id="spPreviewImg" src="" alt="Preview">
        <button class="sp-btn sp-btn-sm sp-btn-danger" id="spRemoveImage" type="button">&times; Remove</button>
      </div>
    </div>

    <!-- AI Image Prompt (hidden by default) -->
    <div class="sp-form-row" id="spAiImageSection" style="display:none">
      <label>AI Image Prompt</label>
      <textarea id="spAiImagePrompt" class="sp-input" rows="2" placeholder="Describe the image you want AI to generate..."></textarea>
      <button class="sp-btn sp-btn-secondary sp-btn-sm" id="spAiImageGenerate" type="button" style="margin-top:6px">Generate Image</button>
    </div>

    <!-- Link URL -->
    <div class="sp-form-row">
      <label>Link URL (optional)</label>
      <input type="text" id="spLinkUrl" class="sp-input" placeholder="https://...">
    </div>

    <!-- Post Button -->
    <div class="sp-form-row sp-post-actions">
      <button class="sp-btn sp-btn-primary sp-btn-lg" id="spPostNow" type="button">Post Now</button>
    </div>

    <!-- Post Result -->
    <div class="sp-post-result" id="spPostResult" style="display:none"></div>

  </div>

  <!-- ═══ Tab 3: Post History ═══ -->
  <div class="sp-panel" id="spHistory" data-panel="history" style="display:none">

    <div class="sp-panel-header">
      <h3>Post History</h3>
      <button class="sp-btn sp-btn-sm" id="spRefreshHistory" type="button">Refresh</button>
    </div>

    <table class="sp-table">
      <thead>
        <tr>
          <th style="width:150px">Date</th>
          <th style="width:120px">Platforms</th>
          <th>Content</th>
          <th style="width:80px">Status</th>
          <th style="width:80px">Source</th>
        </tr>
      </thead>
      <tbody id="spHistoryBody">
        <tr><td colspan="5" class="sp-empty">Loading...</td></tr>
      </tbody>
    </table>

    <div class="sp-pagination" id="spHistoryPagination"></div>

  </div>

</div>

<!-- Provider Modal -->
<div class="sp-modal-overlay" id="spProviderModal">
  <div class="sp-modal">
    <div class="sp-modal-header">
      <h3 id="spProviderModalTitle">Add Provider</h3>
      <button class="sp-modal-close" id="spProviderModalClose" type="button">&times;</button>
    </div>
    <div class="sp-modal-body">
      <input type="hidden" id="spProvId">
      <div class="sp-form-row">
        <label>Platform</label>
        <select id="spProvPlatform" class="sp-input">
          <option value="">-- Select --</option>
          <option value="facebook">Facebook Page</option>
          <option value="twitter">Twitter / X</option>
        </select>
      </div>
      <div class="sp-form-row">
        <label>Display Name</label>
        <input type="text" id="spProvName" class="sp-input" placeholder="e.g. Earl's Facebook Page">
      </div>

      <!-- Facebook Fields -->
      <div id="spFbFields" style="display:none">
        <div class="sp-form-row">
          <label>Page ID</label>
          <input type="text" id="spFbPageId" class="sp-input" placeholder="e.g. 123456789012345">
        </div>
        <div class="sp-form-row">
          <label>Page Access Token</label>
          <textarea id="spFbAccessToken" class="sp-input" rows="3" placeholder="Long-lived Page Access Token"></textarea>
        </div>
        <div class="sp-form-row">
          <label>Page Name (optional)</label>
          <input type="text" id="spFbPageName" class="sp-input" placeholder="e.g. Your Page Name">
        </div>
      </div>

      <!-- Twitter Fields -->
      <div id="spTwFields" style="display:none">
        <div class="sp-form-row">
          <label>API Key (Consumer Key)</label>
          <input type="text" id="spTwApiKey" class="sp-input" placeholder="API Key">
        </div>
        <div class="sp-form-row">
          <label>API Secret (Consumer Secret)</label>
          <input type="text" id="spTwApiSecret" class="sp-input" placeholder="API Secret">
        </div>
        <div class="sp-form-row">
          <label>Access Token</label>
          <input type="text" id="spTwAccessToken" class="sp-input" placeholder="Access Token">
        </div>
        <div class="sp-form-row">
          <label>Access Token Secret</label>
          <input type="text" id="spTwAccessTokenSecret" class="sp-input" placeholder="Access Token Secret">
        </div>
      </div>

      <div class="sp-form-row sp-provider-actions">
        <button class="sp-btn sp-btn-test" id="spTestProvider" type="button">Test Connection</button>
        <div class="sp-test-result" id="spTestResult"></div>
      </div>
    </div>
    <div class="sp-modal-footer">
      <button class="sp-btn" id="spProviderCancel" type="button">Cancel</button>
      <button class="sp-btn sp-btn-primary" id="spProviderSave" type="button">Save Provider</button>
    </div>
  </div>
</div>

<!-- Media Browser Modal -->
<div class="sp-modal-overlay" id="spMediaModal">
  <div class="sp-modal sp-modal-wide">
    <div class="sp-modal-header">
      <h3>Select Image</h3>
      <button class="sp-modal-close" id="spMediaModalClose" type="button">&times;</button>
    </div>
    <div class="sp-modal-body sp-media-body">
      <iframe id="spMediaFrame" src="" style="width:100%;height:500px;border:none;border-radius:6px"></iframe>
    </div>
  </div>
  <button class="as-pip-corner" onclick="window.open('/admin/modules/SocialPublisher/pip/queue.php','pip_queue','width=500,height=400,resizable=yes')" title="Pop out Queue">&#9632; PiP</button>
</div>

<!-- Post Detail Modal -->
<div class="sp-modal-overlay" id="spPostDetailModal">
  <div class="sp-modal">
    <div class="sp-modal-header">
      <h3>Post Details</h3>
      <button class="sp-modal-close" id="spPostDetailClose" type="button">&times;</button>
    </div>
    <div class="sp-modal-body" id="spPostDetailBody"></div>
  </div>
</div>

<script src="<?= sc_asset('/admin/modules/SocialPublisher/js/social-publisher.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
