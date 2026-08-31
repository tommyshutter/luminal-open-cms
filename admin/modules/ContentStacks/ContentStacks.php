<?php
/**
 * Luminal CMS — Content Stacks Module
 *
 * Reusable content blocks with drag-and-drop media, YouTube/Facebook embeds,
 * and multi-column layouts. Frontend rendering via shortcode [[panel:content-stack-{N}.php]].
 * Sidebar = the shared Insert Explorer (media + shortcode catalog, one iframe).
 *
 * @package    LuminalCMS
 * @module     ContentStacks
 * @version    1.1.0
 * @file       /admin/modules/ContentStacks/ContentStacks.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';

guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';
?>
<?php /* Embed mode: Page Manager loads this page in an iframe (?edit=<slug>&embed=1).
        Hide the admin chrome + card grid so only the stack builder shows, full-frame. */ ?>
<?php if (!empty($_GET['embed'])): ?>
<style id="cs-embed-css">
  body.cs-embed .admin-container > *:not(.content-area) { display: none !important; }
  body.cs-embed .content-area { margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
  body.cs-embed .cs-wrap, body.cs-embed .panel_header_h1 { display: none !important; }
  body.cs-embed .cs-modal-overlay { display: block !important; position: static !important; background: transparent !important; }
  body.cs-embed .cs-modal { width: 100% !important; min-height: 100vh !important; max-width: 100% !important; margin: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
</style>
<script>document.body.classList.add('cs-embed');</script>
<?php endif; ?>

<h1 class="panel_header_h1" style="color:white">Content Stacks</h1>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/ContentStacks/css/content-stacks.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/shared/markdown-editor.css') ?>">

<div class="cs-wrap">
  <div class="cs-top-bar">
    <button type="button" class="cs-new-stack-btn" id="cs-new-stack-top">
      <span class="cs-new-stack-btn-plus">+</span> New Stack
    </button>
    <div class="cs-top-bar-spacer"></div>
  </div>
  <div class="cs-card-grid" id="cs-card-grid"></div>
</div>

<div class="cs-modal-overlay" id="cs-modal-overlay">
  <div class="cs-modal">
    <div class="cs-modal-header">
      <button class="cs-btn cs-back-btn" id="cs-modal-back" title="Back to Content Stacks list">
        <i class="fa-solid fa-arrow-left"></i> Back to Content Stacks
      </button>
      <h2 class="cs-modal-title" id="cs-modal-title"></h2>
      <div class="cs-modal-header-controls">
        <button class="cs-btn cs-rename-btn" id="cs-modal-rename" title="Rename">✎ Rename</button>
        <button class="cs-btn cs-delete-btn" id="cs-modal-delete" title="Delete stack">✕ Delete</button>
        <button class="cs-modal-close" id="cs-modal-close">&times;</button>
      </div>
    </div>
    <div class="cs-modal-body">
      <div class="cs-browser-panel">
        <iframe src="/admin/shared/explorer/media-explorer.php?host=contentstacks&types=inserts,images,videos,pdfs,audio" id="cs-media-browser-frame"></iframe>
      </div>
      <div class="cs-editor-panel">
        <!-- Top actions: URL add + Save pinned to the top so they're always reachable -->
        <div class="cs-top-actions">
          <input type="text" class="cs-link-input" id="cs-modal-link-input"
                 placeholder="Paste YouTube, Facebook (event/reel/video/live), TikTok, Vimeo, or X.com URL…">
          <button class="cs-btn cs-add-link-btn" id="cs-modal-add-link"><i class="fas fa-plus"></i> Add Link</button>
          <button class="cs-btn cs-save-btn cs-save-btn--top" id="cs-modal-save"><i class="fas fa-save"></i> Save</button>
        </div>
        <div class="cs-modal-toolbar">
          <label>Layout
            <select class="cs-column-select" id="cs-modal-columns">
              <option value="1">1 Col</option>
              <option value="2">2 Col</option>
              <option value="3">3 Col</option>
            </select>
          </label>
          <button class="cs-btn cs-html-btn" id="cs-html-add"><i class="fas fa-code"></i> Add HTML Block</button>
          <button class="cs-btn cs-html-btn" id="cs-md-add" style="border-color:#059669;color:#34d399;"><i class="fas fa-file-code"></i> Add MD Block</button>
          <div class="cs-shortcode-hint" id="cs-modal-shortcode"></div>
        </div>
        <div class="cs-playlist" id="cs-modal-playlist">
          <p class="cs-empty">Loading...</p>
        </div>
        <div class="cs-bucket-row">
          <details class="cs-bucket-details">
            <summary><i class="fas fa-palette"></i> Stack CSS</summary>
            <textarea id="cs-stack-css" class="cs-bucket-ta" placeholder="/* Custom CSS for this stack only */"></textarea>
          </details>
          <details class="cs-bucket-details">
            <summary><i class="fas fa-code"></i> Stack JS</summary>
            <textarea id="cs-stack-js" class="cs-bucket-ta" placeholder="// Custom JS for this stack only"></textarea>
          </details>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="cs-toast" class="cs-toast"></div>

<style>
.cs-bucket-row { display:flex; gap:8px; padding:6px 0 4px; }
.cs-bucket-details { flex:1; background:rgba(15,23,42,.5); border:1px solid rgba(255,255,255,.08); border-radius:6px; padding:4px 8px; }
.cs-bucket-details summary { font-size:.72rem; font-weight:600; color:#94a3b8; cursor:pointer; user-select:none; list-style:none; display:flex; align-items:center; gap:5px; }
.cs-bucket-details summary::-webkit-details-marker { display:none; }
.cs-bucket-ta { display:block; width:100%; margin-top:6px; min-height:80px; max-height:200px; background:#0d1726; border:1px solid rgba(255,255,255,.1); border-radius:4px; color:#a0c4ff; font-family:monospace; font-size:.72rem; padding:6px 8px; box-sizing:border-box; resize:vertical; }
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="<?= sc_asset('/admin/shared/markdown-editor.js') ?>"></script>
<script src="<?= sc_asset('/admin/modules/ContentStacks/js/content-stacks.js') ?>"></script>
<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
