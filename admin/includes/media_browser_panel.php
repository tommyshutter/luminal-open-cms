<?php
/**
 * @appname     Media Manager
 * @file        SITE-ROOT/admin/includes/media_browser_panel.php
 * @version     2025.09.11.1107.00-EST
 * @author      ChatGPT
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Embeds the Media Browser UI. Set $mediaType ('images'|'videos'|'pdfs') and
 *              optional $mediaFolder (relative subfolder) before including.
 */
if (!isset($mediaType) || !in_array($mediaType, ['images','videos','pdfs'], true)) $mediaType = 'images';
$mediaFolder = isset($mediaFolder) ? (string)$mediaFolder : '';
?>
<div class="media-browser-panel" style="margin:0;padding:0;">
  <iframe
    src="/admin/shared/explorer/media-explorer.php?host=panel&types=<?php echo htmlspecialchars($mediaType, ENT_QUOTES, 'UTF-8'); ?>"
    style="width:100%;height:720px;border:1px solid #253042;border-radius:12px;background:#0b0f19;"
    loading="lazy"
  ></iframe>
  <div style="color:#9ca3af;font-size:12px;margin-top:6px;">
    Drag &amp; drop supported. Use Sort, Ratio, and Folder controls to organize assets.
  </div>
</div>
