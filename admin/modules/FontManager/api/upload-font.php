<?php
/**
 * Upload Fonts
 * @file    /admin/ajax/upload-font.php
 * @version 2025.09.09.r1
 *
 * Accepts: files[] (.ttf/.woff/.woff2/.otf)
 * Saves to: /media/fonts
 * Appends minimal @font-face to /css/custom-fonts.css (skips if same URL already present)
 * Returns: { ok:1, added:[{family, file, url}], errors:[...]}
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
require_once __DIR__ . '/../lib/user_fonts.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$FONTS_DIR = SITE_ROOT . '/media/fonts';
$CSS_FILE  = SITE_ROOT . '/css/custom-fonts.css';

@mkdir($FONTS_DIR, 0775, true);  // 775, not 0777 — admin/data perms law

$allowed = ['ttf','woff','woff2','otf'];
$added   = [];
$errors  = [];

function css_has_url(string $css, string $url): bool {
  return (bool)preg_match('#url\(\s*[\'"]?' . preg_quote($url, '#') . '[\'"]?\s*\)#i', $css);
}

foreach (($_FILES['files']['name'] ?? []) as $i => $name) {
  $tmp  = $_FILES['files']['tmp_name'][$i] ?? '';
  $err  = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
  if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
    $errors[] = "Upload failed for $name (code $err)";
    continue;
  }
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true)) {
    $errors[] = "Unsupported extension: $name";
    continue;
  }
  $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);
  $dest = $FONTS_DIR . '/' . $safe;
  if (!move_uploaded_file($tmp, $dest)) {
    $errors[] = "move_uploaded_file failed: $name";
    continue;
  }
  @chmod($dest, 0666);

  $family = pathinfo($safe, PATHINFO_FILENAME);
  $public = '/media/fonts/' . $safe;

  // Append minimal @font-face if not already present
  $css = is_file($CSS_FILE) ? (@file_get_contents($CSS_FILE) ?: '') : '';
  if (!css_has_url($css, $public)) {
    $fmt = ($ext === 'woff2') ? 'woff2' : (($ext === 'woff') ? 'woff' : (($ext==='otf')?'opentype':'truetype'));
    $block = "\n@font-face{\n  font-family:'{$family}';\n  src:url('{$public}') format('{$fmt}');\n  font-weight:normal; font-style:normal; font-display:swap;\n}\n";
    if (!@file_put_contents($CSS_FILE, $css . $block)) {
      $errors[] = "Could not append to custom-fonts.css (saved file OK)";
    } else {
      @chmod($CSS_FILE, 0666);
    }
  }

  // The operator supplied this file, so it is never ours to sweep.
  fm_user_fonts_record($safe, $family, 'upload');

  $added[] = ['family'=>$family, 'file'=>$safe, 'url'=>$public];
}

echo json_encode(['ok'=>1, 'added'=>$added, 'errors'=>$errors], JSON_UNESCAPED_SLASHES);
?>