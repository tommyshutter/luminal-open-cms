<?php
/**
 * ---------------------------------------------------------------------------------------
 * @appname     Luminal Open CMS
 * @file        /includes/lightbox-bootstrap.inc.php
 * @version     2025.09.25.1426.00-EST
 * @author      ChatGPT
 * @license     Business Source License
 * @description Server-side bootstrap for the unified lightbox host.
 *              Ensures #ri-modal exists ONCE and carries baseline overlay CSS.
 * ---------------------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!function_exists('ri_emit_lightbox_shell')) {
  function ri_emit_lightbox_shell(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Minimal, conflict-free CSS for #ri-modal overlay.
    echo <<<HTML
<style id="ri-modal-baseline-css">
  /* Baseline overlay so we never fall back to top-left inline placement */
  #ri-modal{
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.86);
    z-index: 2147483000;
    padding: 2rem;
  }
  #ri-modal.open{ display:flex; }
  #ri-modal .dlg{
    margin: auto;
    max-width: 96vw; max-height: 92vh;
    border-radius: 12px; overflow: hidden; position: relative;
    box-shadow: 0 20px 80px rgba(0,0,0,.55);
    background: transparent;
  }
  #ri-modal .content{ width:100%; height:100%; display:block; background:transparent; }
  #ri-modal .content img, #ri-modal .content video, #ri-modal .content iframe{
    width:100%; height:100%; object-fit: contain; display:block; border:0; background:#000;
  }
  /* e.g., in /includes/lightbox-bootstrap.inc.php CSS block */
#ri-modal .dlg { width:96vw !important; max-width:96vw !important; height:92vh !important; max-height:92vh !important; }

</style>
<div id="ri-modal" role="dialog" aria-modal="true" aria-label="Lightbox" style="display:none"></div>
HTML;
  }
}

ri_emit_lightbox_shell();

// (Optional) If your footer doesn't already include these, you may enable:
// echo "<script src=\"/js/lightbox-core.js\"></script>";
// echo "<script src=\"/js/lightbox-pdf.js\"></script>";
?>