<?php
/**
 * Luminal CMS — File Manager Module
 *
 * Dual-pane file browser with upload, edit, zip, backup, and ownership management.
 * Role-based access control for admin/staff file operations.
 *
 * @package    LuminalCMS
 * @module     FileManager
 * @version    1.3.0
 * @file       /admin/modules/FileManager/FileManager.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/FileManagerFunctions.php';

$userRole     = fm_get_user_role();
$canFullTree  = fm_is_superadmin();              // true superadmin only (luminal_role)
$fullTree     = fm_full_tree_active();           // superadmin + session opt-in
$allowedPaths = fm_effective_allowed_paths();    // default /media, full tree only when opted in
$startPath    = $fullTree ? '' : 'media';        // default view roots at /media
$moduleBase   = sc_asset('/admin/modules/FileManager');

// Include Luminal admin header
require_once __DIR__ . '/../../admin_header.php';
?>

<h1 class="panel_header_h1">File Manager</h1>

<link rel="stylesheet" href="<?= $moduleBase ?>/css/file-manager.css?v=<?= time() ?>">

<div id="fm-app" style="position:relative">
  <div class="fm-toolbar">
    <div class="fm-tb-group">
      <button id="tb-back" title="Back" class="fm-tb-btn" disabled>&#9664;</button>
      <button id="tb-fwd" title="Forward" class="fm-tb-btn" disabled>&#9654;</button>
      <button id="tb-up" title="Up" class="fm-tb-btn">&#9650;</button>
      <button id="tb-home" title="Home" class="fm-tb-btn">&#8962;</button>
    </div>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <button id="tb-mkdir" title="New Folder" class="fm-tb-btn">&#128193;+</button>
      <button id="tb-upload" title="Upload" class="fm-tb-btn">&#8679;</button>
    </div>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <button id="tb-cut" title="Cut" class="fm-tb-btn" disabled>&#9988;</button>
      <button id="tb-copy" title="Copy" class="fm-tb-btn" disabled>&#128203;</button>
      <button id="tb-paste" title="Paste" class="fm-tb-btn" disabled>&#128203;&#8595;</button>
    </div>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <button id="tb-rename" title="Rename" class="fm-tb-btn" disabled>&#9998;</button>
      <button id="tb-delete" title="Delete" class="fm-tb-btn" disabled>&#128465;</button>
      <button id="tb-download" title="Download" class="fm-tb-btn" disabled>&#8681;</button>
    </div>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <button id="tb-zip" title="Zip Selected" class="fm-tb-btn" disabled>&#128230;</button>
      <button id="tb-unzip" title="Unzip" class="fm-tb-btn" disabled>&#128194;</button>
    </div>
    <div class="fm-tb-spacer"></div>
    <div class="fm-tb-group">
      <button id="tb-backup" title="Backup" class="fm-tb-btn">&#128190;</button>
      <button id="tb-chown" title="Fix Ownership" class="fm-tb-btn">&#128272;</button>
    </div>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <button id="tb-list" title="List View" class="fm-tb-btn active">&#9776;</button>
      <button id="tb-grid" title="Grid View" class="fm-tb-btn">&#9638;</button>
      <button id="tb-zoom-out" title="Smaller Thumbnails" class="fm-tb-btn" style="display:none">&#8722;</button>
      <button id="tb-zoom-in" title="Larger Thumbnails" class="fm-tb-btn" style="display:none">&#43;</button>
      <button id="tb-fit" title="Square Crop" class="fm-tb-btn" style="display:none">&#9635;</button>
      <button id="tb-theme" title="Toggle Theme" class="fm-tb-btn">&#9681;</button>
      <button id="tb-dual"  title="Toggle dual-pane view (F11)" class="fm-tb-btn">&#9707;</button>
      <button id="tb-prefs" title="Preferences" class="fm-tb-btn">&#9881;</button>
      <button id="tb-help"  title="Keyboard shortcuts (?)" class="fm-tb-btn">?</button>
    </div>
<?php if ($canFullTree): ?>
    <div class="fm-tb-sep"></div>
    <div class="fm-tb-group">
      <label class="fm-fulltree-toggle" title="Show the entire site tree (Superadmin only). When off, the File Manager is scoped to /media and all other folders are hidden.">
        <input type="checkbox" id="fm-fulltree-cb"<?= $fullTree ? ' checked' : '' ?>>
        <span>Full&nbsp;Tree</span>
      </label>
    </div>
<?php endif; ?>
    <!-- Clipboard pill — shows when items are cut/copied; click to clear -->
    <div id="fm-clip-pill" class="fm-clip-pill" style="display:none" title="Click to clear clipboard"></div>
  </div>

  <div class="fm-address-bar">
    <span class="fm-addr-label">/</span>
    <input type="text" id="fm-address" class="fm-addr-input" value="" spellcheck="false">
  </div>

  <div class="fm-main">
    <div class="fm-sidebar" id="fm-sidebar">
      <div class="fm-tree" id="fm-tree"></div>
    </div>
    <div class="fm-resize-handle" id="fm-resize"></div>
    <div class="fm-content fm-pane-active" id="fm-content"></div>
    <!-- Pane B — hidden until dual-mode is enabled via ⊛ or F11 -->
    <div class="fm-resize-handle fm-pane-resize" id="fm-pane-resize" style="display:none"></div>
    <div class="fm-pane-b-wrap" id="fm-pane-b-wrap" style="display:none">
      <div class="fm-pane-b-address"><input type="text" id="fm-address-b" class="fm-addr-input fm-addr-b" value="/" readonly title="Click pane to activate, then type/edit"></div>
      <div class="fm-content" id="fm-content-b"></div>
    </div>
  </div>

  <div class="fm-statusbar">
    <span id="fm-status-items"></span>
    <span id="fm-status-sel"></span>
    <span class="fm-sb-spacer"></span>
    <span class="fm-sb-hint"><kbd>?</kbd> for shortcuts &middot; <kbd>Ctrl</kbd>+<kbd>X</kbd>/<kbd>C</kbd>/<kbd>V</kbd> cut/copy/paste &middot; <kbd>F2</kbd> rename</span>
    <span id="fm-status-disk"></span>
  </div>

  <div id="fm-drop-overlay" class="fm-drop-overlay" style="display:none">
    <div class="fm-drop-text">Drop files to upload</div>
  </div>
  <button class="as-pip-corner" onclick="window.open('/admin/shared/explorer/media-explorer.php','pip_browser','width=760,height=560,resizable=yes')" title="Pop out File Browser">&#9632; PiP</button>
</div>

<div id="fm-ctx" class="fm-ctx" style="display:none"></div>

<div id="fm-modal" class="fm-modal" style="display:none">
  <div class="fm-modal-overlay"></div>
  <div class="fm-modal-box">
    <div class="fm-modal-header">
      <span id="fm-modal-title"></span>
      <button class="fm-modal-close" id="fm-modal-close">&times;</button>
    </div>
    <div class="fm-modal-body" id="fm-modal-body"></div>
    <div class="fm-modal-footer" id="fm-modal-footer"></div>
  </div>
</div>

<div id="fm-toasts" class="fm-toasts"></div>

<script>
window.FM_BOOT = {
  endpoints: { api: '<?= $moduleBase ?>/api.php' },
  userRole: <?= json_encode($userRole) ?>,
  allowedPaths: <?= json_encode($allowedPaths) ?>,
  canFullTree: <?= $canFullTree ? 'true' : 'false' ?>,
  fullTree: <?= $fullTree ? 'true' : 'false' ?>,
  startPath: <?= json_encode($startPath) ?>
};
</script>
<link rel="stylesheet" href="/admin/shared/upload-progress/upload-progress.css?v=<?= @filemtime(SITE_ROOT . '/admin/shared/upload-progress/upload-progress.css') ?: time() ?>">
<script src="/admin/shared/upload-progress/upload-progress.js?v=<?= @filemtime(SITE_ROOT . '/admin/shared/upload-progress/upload-progress.js') ?: time() ?>"></script>
<script src="<?= $moduleBase ?>/js/file-manager.js?v=<?= time() ?>"></script>
