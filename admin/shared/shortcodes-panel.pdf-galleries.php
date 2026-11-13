<?php
/**
 * @file admin/widgets/shortcodes-panel.pdf-galleries.php
 * @desc Lists saved PDF galleries (json), and falls back to listing raw /media/pdfs/*.pdf for quick embed.
 */
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/../..'));

$galDir = SITE_ROOT . '/admin/data/galleries/pdfs';
$pdfDir = SITE_ROOT . '/media/pdfs';

$galleries = [];
if (is_dir($galDir)) {
  foreach (glob($galDir.'/*.json') as $j) {
    $name = basename($j, '.json');
    $galleries[] = $name;
  }
  sort($galleries, SORT_NATURAL | SORT_FLAG_CASE);
}

$loosePdfs = [];
if (is_dir($pdfDir)) {
  foreach (glob($pdfDir.'/*.pdf') as $f) {
    $loosePdfs[] = basename($f);
  }
  sort($loosePdfs, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<div>
  <?php if ($galleries): ?>
    <div class="muted" style="margin-bottom:6px">Saved PDF Galleries</div>
    <?php foreach ($galleries as $name):
      $sc = '[pdf-gallery name="'.htmlspecialchars($name,ENT_QUOTES).'"]';
    ?>
      <div class="pm-row" style="justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.06);padding:6px 0">
        <div><strong><?php echo htmlspecialchars($name); ?></strong></div>
        <div>
          <a href="#" class="pill sc-insert" data-shortcode="<?php echo $sc; ?>">Insert</a>
          <a href="#" class="pill copy sc-copy" data-shortcode="<?php echo $sc; ?>">Copy</a>
        </div>
      </div>
    <?php endforeach; ?>
    <hr style="border-color:rgba(255,255,255,.08);margin:10px 0">
  <?php endif; ?>

  <div class="muted" style="margin-bottom:6px">Single PDFs</div>
  <?php if (!$loosePdfs): ?>
    <div class="muted">No PDFs found in /pdfs.</div>
  <?php else: foreach ($loosePdfs as $file):
    $path = '/media/pdfs/'.$file;
    $sc = '[pdf src="'.htmlspecialchars($path,ENT_QUOTES).'" title="'.htmlspecialchars(pathinfo($file,PATHINFO_FILENAME),ENT_QUOTES).'"]';
  ?>
    <div class="pm-row" style="justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.06);padding:6px 0">
      <div><strong><?php echo htmlspecialchars($file); ?></strong></div>
      <div>
        <a href="#" class="pill sc-insert" data-shortcode="<?php echo $sc; ?>">Insert</a>
        <a href="#" class="pill copy sc-copy" data-shortcode="<?php echo $sc; ?>">Copy</a>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
