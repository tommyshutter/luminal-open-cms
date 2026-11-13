<?php
/**
 * @appname   luminal-cms
 * @file      /includes/site_settings_apply.inc.php
 * @version   2025.09.27.1018.10-EST | include-safe, no side effects if files missing
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Applies saved Site Settings without touching /header/*:
 *   - Link /admin/data/site-typography.css if present
 *   - DOM patch: update header logo <img> src + width/margin if enable_logo + logo_path exist
 *   - Apply top-content-gap to :root if present
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__) ?: __DIR__);
}

$cssFile = SITE_ROOT . '/admin/data/site-typography.css';
$jsonFile = SITE_ROOT . '/admin/data/site-settings.json';

if (is_file($cssFile)) {
    $href = '/admin/data/site-typography.css';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . "\n";
}

$settings = [];
if (is_file($jsonFile)) {
    $raw = @file_get_contents($jsonFile);
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) { $settings = $tmp; }
}

$enableLogo = isset($settings['enable_logo']) ? (bool)$settings['enable_logo'] : false;
$logoPath   = $settings['logo_path'] ?? '';
$logoMax    = isset($settings['logo_max_width']) ? (int)$settings['logo_max_width'] : null;
$logoMb     = isset($settings['logo_margin_bottom']) ? (int)$settings['logo_margin_bottom'] : null;
$topGap     = isset($settings['top_content_gap']) ? (int)$settings['top_content_gap'] : null;

?>
<script>
(function(){
  try {
    var ro = document.documentElement;
    <?php if ($topGap !== null): ?>
    ro.style.setProperty('--top-content-gap', String(<?php echo (int)$topGap; ?>) + 'px');
    <?php endif; ?>

    <?php if ($enableLogo && $logoPath): ?>
    // Try common header brand selectors without altering structure
    var img = document.querySelector('.brand-logo img, .site-brand img, header .brand img, .admin-brand img');
    if (img) {
      img.setAttribute('src', <?php echo json_encode($logoPath); ?>);
      <?php if ($logoMax !== null): ?>img.style.maxWidth = String(<?php echo (int)$logoMax; ?>) + 'px';<?php endif; ?>
      <?php if ($logoMb !== null): ?>img.style.marginBottom = String(<?php echo (int)$logoMb; ?>) + 'px';<?php endif; ?>
    }
    <?php endif; ?>
  } catch(e) { /* silent */ }
})();
</script>
