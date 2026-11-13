<?php
/**
 * Luminal CMS — Standalone Markdown Editor
 *
 * Full-page markdown editor wrapping the shared MarkdownEditor component
 * with document save/load/export capabilities.
 *
 * @package    LuminalCMS
 * @module     MDEditor
 * @version    1.0.0
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

<h1 class="panel_header_h1" style="color:white">Markdown Editor</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/shared/markdown-editor.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/MDEditor/css/md-editor-standalone.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/shared/page-browser.css') ?>">

<div class="mds-wrap">
  <div class="mds-doc-bar">
    <div class="mds-doc-bar-left">
      <select id="mds-doc-select" class="mds-select" title="Load document">
        <option value="">— New Document —</option>
      </select>
      <input type="text" id="mds-doc-title" class="mds-input" placeholder="Document title..." value="">
      <span id="mds-dirty" class="mds-dirty" title="Unsaved changes" style="display:none;">●</span>
    </div>
    <div class="mds-doc-bar-right">
      <button id="mds-browse" class="mds-btn" title="Browse CMS pages"><i class="fa-solid fa-folder-open"></i> Pages</button>
      <button id="mds-save" class="mds-btn mds-btn-primary" title="Save (Ctrl+S)">Save</button>
      <button id="mds-export-md" class="mds-btn" title="Export as .md">Export .md</button>
      <button id="mds-export-html" class="mds-btn" title="Export as .html">Export .html</button>
      <button id="mds-delete" class="mds-btn mds-btn-danger" title="Delete document">Delete</button>
      <button id="mds-close" class="mds-btn" title="Close editor" style="margin-left:8px;font-size:1.1rem;padding:4px 8px;line-height:1;">&times;</button>
    </div>
  </div>
  <div id="mds-editor-container" class="mds-editor-container"></div>
</div>

<div id="mds-toast" class="mds-toast"></div>

<script src="<?= sc_asset('/admin/shared/markdown-editor.js') ?>"></script>
<script src="<?= sc_asset('/admin/shared/page-browser.js') ?>"></script>
<script src="<?= sc_asset('/admin/modules/MDEditor/js/md-editor-standalone.js') ?>"></script>
<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
