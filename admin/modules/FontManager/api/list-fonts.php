<?php
/**
 * List Fonts (JSON)
 * @file    /admin/ajax/list-fonts.php
 * @version 2025.09.09.r1
 *
 * Returns: [{ family: "Name", sources: ["/media/fonts/Name.woff2", ...] }, ...]
 * Strategy:
 *   1) Parse /css/custom-fonts.css for font-family names
 *   2) Also scan /media/fonts for .ttf/.woff/.woff2 and add families by basename
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$FONTS_DIR = SITE_ROOT . '/media/fonts';
$CSS_FILE  = SITE_ROOT . '/css/custom-fonts.css';

$families = [];            // family => ['family'=>..., 'sources'=>[]]
$seen     = [];

$add = function(string $family, string $src = null) use (&$families, &$seen) {
  $k = strtolower(trim($family));
  if ($k === '') return;
  if (!isset($families[$k])) {
    $families[$k] = ['family' => $family, 'sources' => []];
  }
  if ($src && empty($seen[$k][$src])) {
    $families[$k]['sources'][] = $src;
    $seen[$k][$src] = true;
  }
};

// 1) Parse custom-fonts.css
if (is_file($CSS_FILE)) {
  $css = @file_get_contents($CSS_FILE) ?: '';
  if ($css !== '') {
    // Grab font-family:'Name' and nearby url('...') if present
    $re = '/font-family\s*:\s*([\'"])([^\'"]+)\1\s*;?/i';
    if (preg_match_all($re, $css, $m, PREG_SET_ORDER)) {
      foreach ($m as $row) {
        $family = trim($row[2]);
        $add($family);
      }
    }
    // Pair families with url(...) blocks
    $reBlock = '/@font-face\s*\{[^}]+\}/i';
    if (preg_match_all($reBlock, $css, $blocks)) {
      foreach ($blocks[0] as $block) {
        if (preg_match('/font-family\s*:\s*([\'"])([^\'"]+)\1/i', $block, $mm)) {
          $fam = trim($mm[2]);
          if (preg_match_all('/url\(([^)]+)\)/i', $block, $uu)) {
            foreach ($uu[1] as $raw) {
              $url = trim($raw, '\'" ');
              $add($fam, $url);
            }
          } else {
            $add($fam);
          }
        }
      }
    }
  }
}

// 2) Scan /media/fonts for files -> add as families by basename (no extension)
$exts = ['ttf','woff','woff2','otf'];
if (is_dir($FONTS_DIR)) {
  $dh = opendir($FONTS_DIR);
  if ($dh) {
    while (($f = readdir($dh)) !== false) {
      $p = $FONTS_DIR . '/' . $f;
      if (!is_file($p)) continue;
      $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
      if (!in_array($ext, $exts, true)) continue;
      $base = pathinfo($f, PATHINFO_FILENAME);
      $family = $base; // heuristic, good enough for preview/pick-list
      $add($family, '/media/fonts/' . $f);
    }
    closedir($dh);
  }
}

// Output
echo json_encode(array_values($families), JSON_UNESCAPED_SLASHES);
?>