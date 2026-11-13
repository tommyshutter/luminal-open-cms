<?php
/**
 * Preview Scopes (Menu + Body) — bind targets for live demo
 * @file    /admin/partials/mm-preview-scopes.php
 * @version 2025.09.10.r2
 *
 * What this does
 *  - Provides two minimal “scopes” with stable IDs:
 *      #mmPreviewScope   (Demo Menu preview)
 *      #bodyPreviewScope (Body Text preview)
 *  - The JS writes CSS variables on these elements only,
 *    so changes show instantly without touching :root.
 *
 * Requirements
 *  - CSS: append the preview rules to /admin/css/menu-manager.css
 *  - JS : replace /admin/js/menu-manager.js with the binder version
 *  - Controls elsewhere on the page must use these IDs:
 *      Menu: mmFontFamily, mmFontSize, mmFontColor, mmTextTransform,
 *            mmBgTransparent, mmBgColor, mmBgOpacity
 *      Body: bodyFontFamily, bodyColor, bodyLinkColor, bodyVisitedColor,
 *            bodyLineHeight, bodyTextTransform
 *
 * Safe to include anywhere in Font Manager’s right column.
 */
?>
<!-- ===== Demo Menu Preview Scope ===== -->
<div id="mmPreviewScope" class="preview-scope">
  <ul class="mm-menu-preview" id="mmMenuPreview" aria-label="Menu Preview">
    <li class="is-current"><a href="#">Home</a></li>
    <li><a href="#">Images</a></li>
    <li><a href="#">Videos</a></li>
    <li><a href="#">PDFs</a></li>
    <li><a href="#">Merch</a></li>
  </ul>

  <!-- Optional: current CSS vars echo (handy for debugging) -->
  <div id="mmVarEcho" class="mm-var-echo"></div>
</div>

<!-- ===== Body Text Preview Scope ===== -->
<div id="bodyPreviewScope" class="preview-scope">
  <div class="body-preview">
    The quick brown fox jumps over the lazy developer’s backlog. Vivamus pretium venenatis
    lorem, vitae dictum arcu. A <a href="#">link</a> looks like this. A visited link will
    look different.
  </div>
</div>
