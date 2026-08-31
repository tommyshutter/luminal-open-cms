<?php
/**
 * @appname  Earl’s CMS
 * @file     /admin/includes/admin-bg-apply.inc.php
 * @version  2025.09.24.1612.00-EST
 * @author   ChatGPT
 * @license  Business Source License
 * @description
 * Applies a full-viewport admin background image with dark overlay and now
 * continues UNDER the admin menu area (keeps UI above via z-index; makes header
 * backgrounds transparent so overlay shows through). Listens for live updates.
 */

declare(strict_types=1);
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

$DATA_DIR = SITE_ROOT . '/admin/data/admin_bg';
$SETTINGS = $DATA_DIR . '/settings.json';

$cfg = ['image' => '', 'opacity' => 0.5];
if (is_file($SETTINGS)) {
  $raw = file_get_contents($SETTINGS);
  if ($raw !== false) { $j = json_decode($raw, true); if (is_array($j)) { $cfg = array_merge($cfg, $j); } }
}

$bgUrl = '';
if (!empty($cfg['image'])) { $bgUrl = '/admin/data/admin_bg/' . rawurlencode($cfg['image']); }
$opacity = max(0.1, min(0.9, floatval($cfg['opacity'])));
?>
<style>
  /* === Admin Background Applicator (scoped, additive) === */
  :root { --admin-bg-opacity: <?php echo number_format($opacity,2,'.',''); ?>; }

  /* Two fixed layers behind ALL admin UI (including the menu) */
  #admin-bg-layer, #admin-bg-overlay {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
  }
  #admin-bg-layer {
    background: <?php echo $bgUrl ? "url('{$bgUrl}')" : '#000'; ?> center/cover no-repeat fixed;
    background-color: #000;
    filter: saturate(90%) contrast(105%);
  }
  #admin-bg-overlay { background: rgba(0,0,0, var(--admin-bg-opacity)); }

  /* Ensure all admin UI sits ABOVE the overlay */
  body, .admin-header-wrap, #toast-notification { position: relative; z-index: 1; }

  /* Make common admin header/menu wrappers transparent so the overlay shows through */
  .admin-header-wrap,
  .admin-header,
  #admin-menu,
  .admin-menu {
    background: transparent !important;
    backdrop-filter: none; /* keep crisp; toggle if you want a glass look */
  }
</style>
<div id="admin-bg-layer" aria-hidden="true"></div>
<div id="admin-bg-overlay" aria-hidden="true"></div>
<script>
(function(){
  // Live updates from the widget (no reload needed)
  window.addEventListener('admin-bg-updated', (ev)=>{
    if(!ev.detail) return;
    const d = ev.detail;
    if(d.opacity){ document.documentElement.style.setProperty('--admin-bg-opacity', (+d.opacity).toFixed(2)); }
    if(d.url){ const layer = document.getElementById('admin-bg-layer'); if(layer){ layer.style.backgroundImage = "url('"+d.url+"')"; } }
  }, {passive:true});
})();
</script>
