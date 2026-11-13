<?php
/**
 * Luminal CMS — HTML Blocks Module (Admin Shell Entry Point)
 *
 * First-class reusable HTML Blocks with per-block scoped CSS + JS.
 * Embed on any page or stack via [[html-block:slug]].
 *
 * @package    LuminalCMS
 * @module     HTMLBlocks
 * @version    1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

if (empty($_SESSION['hb_csrf'])) {
    $_SESSION['hb_csrf'] = bin2hex(random_bytes(16));
}
$hbCsrf = $_SESSION['hb_csrf'];
$moduleBase = defined('sc_asset') ? sc_asset('/admin/modules/HTMLBlocks') : '/admin/modules/HTMLBlocks';

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= $moduleBase ?>/css/html-blocks.css">

<div class="hbm-wrap">
  <div class="hbm-header">
    <h1 class="panel_header_h1" style="margin:0">HTML Blocks</h1>
    <div class="hbm-header-actions">
      <button type="button" class="hbm-btn hbm-btn-primary" id="hbm-new-btn">
        <i class="fa-solid fa-plus"></i> New Block
      </button>
    </div>
  </div>

  <p class="hbm-lede">Reusable HTML snippets with their own scoped CSS and JS.
  Embed anywhere via <code>[[html-block:slug]]</code> — on pages, inside content stacks, in right columns.</p>

  <!-- Bulk-operations toolbar -->
  <div class="hbm-bulkbar" id="hbm-bulkbar">
    <label class="hbm-bulk-selectall">
      <input type="checkbox" id="hbm-select-all" aria-label="Select all blocks">
      <span class="hbm-bulk-selectall-text">Select all</span>
    </label>
    <span class="hbm-bulk-count" id="hbm-bulk-count" aria-live="polite">0 selected</span>
    <div class="hbm-bulk-actions" id="hbm-bulk-actions">
      <input type="text" class="hbm-bulk-tags-input" id="hbm-bulk-tags" placeholder="tag1, tag2" aria-label="Tags for bulk tagging">
      <button type="button" class="hbm-btn hbm-btn-ghost" id="hbm-bulk-tag-add" disabled>
        <i class="fa-solid fa-tag"></i> Add tags
      </button>
      <button type="button" class="hbm-btn hbm-btn-ghost" id="hbm-bulk-tag-remove" disabled>
        <i class="fa-solid fa-tag-slash"></i> Remove tags
      </button>
      <button type="button" class="hbm-btn hbm-btn-danger" id="hbm-bulk-delete" disabled>
        <i class="fa-solid fa-trash"></i> Delete selected
      </button>
    </div>
  </div>

  <div class="hbm-grid" id="hbm-grid">
    <div class="hbm-empty">Loading…</div>
  </div>
</div>

<!-- Editor modal -->
<div class="hbm-modal" id="hbm-modal" aria-hidden="true">
  <div class="hbm-modal-card">
    <div class="hbm-modal-head">
      <input type="text" class="hbm-title-input" id="hbm-title" placeholder="Block title">
      <span class="hbm-slug-badge" id="hbm-slug-badge">slug: —</span>
      <span class="hbm-shortcode" id="hbm-shortcode" title="Click to copy">[[html-block:…]]</span>
      <button type="button" class="hbm-btn hbm-btn-ghost" id="hbm-cancel">
        <i class="fa-solid fa-xmark"></i> Close
      </button>
      <button type="button" class="hbm-btn hbm-btn-primary" id="hbm-save">
        <i class="fa-solid fa-floppy-disk"></i> Save
      </button>
    </div>
    <div class="hbm-modal-body">
      <div class="hbm-tabs">
        <button type="button" class="hbm-tab active" data-tab="html">HTML</button>
        <button type="button" class="hbm-tab" data-tab="css">CSS</button>
        <button type="button" class="hbm-tab" data-tab="js">JS</button>
        <button type="button" class="hbm-tab" data-tab="preview"><i class="fa-solid fa-eye"></i> Preview</button>
      </div>
      <div class="hbm-pane active" data-pane="html">
        <textarea id="hbm-html" spellcheck="false" placeholder="Block HTML (shortcodes allowed)"></textarea>
      </div>
      <div class="hbm-pane" data-pane="css">
        <div class="hbm-pane-hint">Scoped to <code>.hb-{id}</code> wrapper automatically. Write regular CSS — selectors get auto-prefixed.</div>
        <textarea id="hbm-css" spellcheck="false" placeholder="/* Scoped CSS */"></textarea>
      </div>
      <div class="hbm-pane" data-pane="js">
        <div class="hbm-pane-hint">Wrapped in an IIFE bound to the wrapper element (<code>root</code>). Runs once per rendered instance.</div>
        <textarea id="hbm-js" spellcheck="false" placeholder="// root === wrapper element"></textarea>
      </div>
      <div class="hbm-pane" data-pane="preview">
        <div class="hbm-pane-hint">Live render with the site's real styles (HTML + scoped CSS + JS). <button type="button" class="hbm-btn hbm-btn-ghost" id="hbm-preview-refresh" style="padding:2px 8px"><i class="fa-solid fa-rotate"></i> Refresh</button></div>
        <iframe id="hbm-preview" class="hbm-preview-frame" title="Block preview" scrolling="no" style="width:100%;height:200px;min-height:120px;border:1px solid rgba(255,255,255,.12);border-radius:8px;background:#0a0e14;overflow:hidden"></iframe>
      </div>
    </div>
    <div class="hbm-modal-foot">
      <div class="hbm-meta">
        <label>Slug <input type="text" id="hbm-slug" placeholder="auto from title"></label>
        <label>Tags <input type="text" id="hbm-tags" placeholder="comma, separated"></label>
      </div>
      <button type="button" class="hbm-btn hbm-btn-danger" id="hbm-delete">
        <i class="fa-solid fa-trash"></i> Delete
      </button>
    </div>
  </div>
</div>

<script>
  window.HBM_BOOT = {
    apiUrl: '<?= $moduleBase ?>/api.php',
    csrfToken: <?= json_encode($hbCsrf) ?>
  };
</script>
<script src="<?= $moduleBase ?>/js/html-blocks.js"></script>
