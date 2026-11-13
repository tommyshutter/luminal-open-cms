<?php
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json; charset=utf-8');

$root = realpath(dirname(__DIR__, 4));
$dir  = $root . '/panels';
$deny = ['background_loader.php','diag_hero.php','load-panel.php','panel-layout-controller.php',
        'panel-left.php','panel-right.php','pdf-proxy.php','img-proxy.php','pdf-viewer.php',
        'panel-loader.js','docs-api.php','docs-viewer.php','ab-submit.php','lead-collect.php'];
// Site-specific panel prefixes — only show when the related site/module data exists locally.
// Miami-* panels live in the shared /panels/ dir but only make sense for Miami-themed sites
// (they auto-include on home via page.php when AffiliateProducts/editorial.json opts in).
$sitePrefixes = ['miami-'];

$out=[];
if (is_dir($dir)){
  foreach (glob($dir.'/*.php') as $f){
    $b = basename($f);
    if (in_array($b,$deny,true)) continue;
    $skip = false;
    foreach ($sitePrefixes as $pfx) {
      if (strpos($b, $pfx) === 0) { $skip = true; break; }
    }
    if ($skip) continue;
    $out[] = $b;
  }
}
echo json_encode(['ok'=>true,'panels'=>$out]);
?>