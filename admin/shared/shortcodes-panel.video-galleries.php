<?php
/**
 * @file admin/widgets/shortcodes-panel.video-galleries.php
 * @desc Lists saved video galleries with pill Insert / Copy next to each shortcode.
 */
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/../..'));
$dir = SITE_ROOT . '/admin/data/galleries/videos';
$items = [];
if (is_dir($dir)) {
  foreach (glob($dir.'/*.json') as $j) {
    $name = basename($j, '.json');
    $items[] = $name;
  }
  sort($items, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<div>
  <?php if (!$items): ?>
    <div class="muted">No video galleries saved yet.</div>
  <?php else: foreach ($items as $name):
    $sc = '[video-gallery name="'.htmlspecialchars($name,ENT_QUOTES).'"]';
  ?>
    <div class="pm-row" style="justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.06);padding:6px 0">
      <div><strong><?php echo htmlspecialchars($name); ?></strong></div>
      <div>
        <a href="#" class="pill sc-insert" data-shortcode="<?php echo $sc; ?>">Insert</a>
        <a href="#" class="pill copy sc-copy" data-shortcode="<?php echo $sc; ?>">Copy</a>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
