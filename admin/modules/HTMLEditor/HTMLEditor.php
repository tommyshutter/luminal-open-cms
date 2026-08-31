<?php
/**
 * Luminal CMS — Native HTML WYSIWYG Editor
 *
 * Full-featured rich text editor built on contenteditable.
 * No third-party editor dependencies — 100% custom code.
 *
 * @package    LuminalCMS
 * @module     HTMLEditor
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

<h1 class="panel_header_h1" style="color:white">HTML Editor</h1>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/HTMLEditor/css/html-editor.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/shared/page-browser.css') ?>">

<div class="he-wrap">
  <!-- Document Bar -->
  <div class="he-doc-bar">
    <div class="he-doc-bar-left">
      <select id="he-doc-select" class="he-select" title="Load document">
        <option value="">— New Document —</option>
      </select>
      <input type="text" id="he-doc-title" class="he-input" placeholder="Document title..." value="">
      <span id="he-dirty" class="he-dirty" title="Unsaved changes" style="display:none;">&#9679;</span>
    </div>
    <div class="he-doc-bar-right">
      <button id="he-save" class="he-dbtn he-dbtn-primary" title="Save (Ctrl+S)">Save</button>
      <button id="he-export" class="he-dbtn" title="Export as .html">Export</button>
      <button id="he-delete" class="he-dbtn he-dbtn-danger" title="Delete document">Delete</button>
      <button id="he-close" class="he-dbtn" title="Close editor" style="margin-left:8px;font-size:1.1rem;padding:4px 8px;line-height:1;">&times;</button>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="he-toolbar" id="he-toolbar">
    <!-- Row 1: Main formatting -->
    <div class="he-tb-row">
      <div class="he-tb-group">
        <button class="he-tb-btn" data-cmd="undo" title="Undo (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
        <button class="he-tb-btn" data-cmd="redo" title="Redo (Ctrl+Y)"><i class="fa-solid fa-rotate-right"></i></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
        <button class="he-tb-btn" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
        <button class="he-tb-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        <button class="he-tb-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <select class="he-tb-select" data-cmd="formatBlock" title="Paragraph format">
          <option value="p">Paragraph</option>
          <option value="h1">Heading 1</option>
          <option value="h2">Heading 2</option>
          <option value="h3">Heading 3</option>
          <option value="h4">Heading 4</option>
          <option value="h5">Heading 5</option>
          <option value="h6">Heading 6</option>
          <option value="pre">Preformatted</option>
          <option value="blockquote">Blockquote</option>
        </select>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <select class="he-tb-select he-tb-font" data-cmd="fontName" title="Font family">
          <option value="">Font</option>
          <option value="Arial, sans-serif">Arial</option>
          <option value="Georgia, serif">Georgia</option>
          <option value="'Times New Roman', serif">Times New Roman</option>
          <option value="'Courier New', monospace">Courier New</option>
          <option value="Verdana, sans-serif">Verdana</option>
          <option value="Tahoma, sans-serif">Tahoma</option>
          <option value="'Trebuchet MS', sans-serif">Trebuchet MS</option>
          <option value="Impact, sans-serif">Impact</option>
          <option value="'Comic Sans MS', cursive">Comic Sans MS</option>
        </select>
        <select class="he-tb-select he-tb-fontsize" data-cmd="fontSize" title="Font size">
          <option value="">Size</option>
          <option value="1">8pt</option>
          <option value="2">10pt</option>
          <option value="3">12pt</option>
          <option value="4">14pt</option>
          <option value="5">18pt</option>
          <option value="6">24pt</option>
          <option value="7">36pt</option>
        </select>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <label class="he-tb-color-wrap" title="Text color">
          <span class="he-tb-color-icon">A</span>
          <input type="color" class="he-tb-color" data-cmd="foreColor" value="#ffffff">
        </label>
        <label class="he-tb-color-wrap" title="Background color">
          <span class="he-tb-color-icon he-tb-hilite-icon"><i class="fa-solid fa-fill-drip"></i></span>
          <input type="color" class="he-tb-color" data-cmd="hiliteColor" value="#000000">
        </label>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn" data-cmd="justifyLeft" title="Align left"><i class="fa-solid fa-align-left"></i></button>
        <button class="he-tb-btn" data-cmd="justifyCenter" title="Align center"><i class="fa-solid fa-align-center"></i></button>
        <button class="he-tb-btn" data-cmd="justifyRight" title="Align right"><i class="fa-solid fa-align-right"></i></button>
        <button class="he-tb-btn" data-cmd="justifyFull" title="Justify"><i class="fa-solid fa-align-justify"></i></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn" data-cmd="insertUnorderedList" title="Bullet list"><i class="fa-solid fa-list-ul"></i></button>
        <button class="he-tb-btn" data-cmd="insertOrderedList" title="Numbered list"><i class="fa-solid fa-list-ol"></i></button>
        <button class="he-tb-btn" data-cmd="indent" title="Increase indent"><i class="fa-solid fa-indent"></i></button>
        <button class="he-tb-btn" data-cmd="outdent" title="Decrease indent"><i class="fa-solid fa-outdent"></i></button>
      </div>
    </div>
    <!-- Row 2: Insert & tools -->
    <div class="he-tb-row">
      <div class="he-tb-group">
        <button class="he-tb-btn" data-action="link" title="Insert/edit link"><i class="fa-solid fa-link"></i></button>
        <button class="he-tb-btn" data-cmd="unlink" title="Remove link"><i class="fa-solid fa-link-slash"></i></button>
        <button class="he-tb-btn" data-action="image" title="Insert image"><i class="fa-solid fa-image"></i></button>
        <button class="he-tb-btn" data-action="video" title="Embed video"><i class="fa-solid fa-film"></i></button>
        <button class="he-tb-btn" data-action="media-browser" title="Media browser"><i class="fa-solid fa-photo-film"></i></button>
        <button class="he-tb-btn" data-action="browse-pages" title="Browse CMS pages"><i class="fa-solid fa-folder-open"></i></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn" data-action="table" title="Insert table"><i class="fa-solid fa-table"></i></button>
        <button class="he-tb-btn" data-cmd="insertHorizontalRule" title="Horizontal rule"><i class="fa-solid fa-minus"></i></button>
        <button class="he-tb-btn" data-action="special-char" title="Special characters"><i class="fa-solid fa-omega"></i></button>
        <button class="he-tb-btn" data-action="fa-icon" title="Font Awesome icon"><i class="fa-solid fa-icons"></i></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn" data-action="find-replace" title="Find & Replace"><i class="fa-solid fa-magnifying-glass"></i></button>
        <button class="he-tb-btn" data-cmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
        <button class="he-tb-btn" data-action="fullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn he-tb-source-btn" data-action="source-toggle" title="Toggle source code view"><i class="fa-solid fa-code"></i> Source</button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn he-tb-source-btn" data-action="prettify" title="Prettify HTML (indent)"><i class="fa-solid fa-wand-magic-sparkles"></i> Prettify</button>
        <button class="he-tb-btn he-tb-source-btn" data-action="minify" title="Minify HTML (compress)"><i class="fa-solid fa-compress"></i> Minify</button>
      </div>
      <div class="he-tb-sep"></div>
      <div class="he-tb-group">
        <button class="he-tb-btn he-tb-preview-btn" data-action="preview-toggle" title="Live Preview"><i class="fa-solid fa-eye"></i> Preview</button>
        <button class="he-tb-btn he-tb-mute-btn" data-action="mute-bg" title="Mute background videos" style="display:none;"><i class="fa-solid fa-volume-xmark"></i></button>
      </div>
    </div>
  </div>

  <!-- Editor Area -->
  <div class="he-editor-area" id="he-editor-area">
    <div class="he-content" id="he-content" contenteditable="true" spellcheck="true"></div>
    <textarea class="he-source" id="he-source" style="display:none;" spellcheck="false"></textarea>
    <iframe id="he-preview" class="he-preview" sandbox="allow-same-origin allow-scripts" style="display:none;"></iframe>
  </div>

  <!-- Status bar -->
  <div class="he-status" id="he-status">
    <span id="he-word-count">0 words</span>
    <span class="he-status-sep">|</span>
    <span id="he-char-count">0 chars</span>
    <span class="he-status-sep">|</span>
    <span id="he-element-path">body</span>
  </div>
</div>

<!-- Modals -->
<div class="he-modal-overlay" id="he-modal-overlay" style="display:none;">
  <div class="he-modal" id="he-modal">
    <div class="he-modal-header">
      <h3 id="he-modal-title"></h3>
      <button class="he-modal-close" id="he-modal-close">&times;</button>
    </div>
    <div class="he-modal-body" id="he-modal-body"></div>
    <div class="he-modal-footer" id="he-modal-footer"></div>
  </div>
</div>

<!-- Media Browser Iframe (hidden, for picker) -->
<iframe id="he-media-frame" style="display:none;" src="about:blank"></iframe>

<div id="he-toast" class="he-toast"></div>

<script src="<?= sc_asset('/admin/shared/page-browser.js') ?>"></script>
<script src="<?= sc_asset('/admin/modules/HTMLEditor/js/html-editor-init.js') ?>"></script>
<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
