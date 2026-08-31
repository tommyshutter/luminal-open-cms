<?php
/**
 * Toggle the front-page debug toaster from Page Manager.
 * @file /admin/ajax/set-debug-flag.php
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// --- (Optional) auth check: require admin session here ---
// session_start();
// if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('forbidden'); }

header('Content-Type: application/json; charset=utf-8');

$root = realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4);
$dataDir = $root . '/admin/data';
$siteFile = $dataDir . '/site-settings.json';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in) || !array_key_exists('debug_toaster', $in)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing debug_toaster']);
  exit;
}

$flag = !!$in['debug_toaster'];

// Read existing site-settings (if any)
$site = [];
if (is_file($siteFile)) {
  $s = @file_get_contents($siteFile);
  if ($s !== false) {
    $j = json_decode($s, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) $site = $j;
  }
}

// Update + write atomically
$site['debug_toaster'] = $flag;
$tmp = $siteFile . '.tmp';
$ok  = (@file_put_contents($tmp, json_encode($site, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) !== false)
   && (@rename($tmp, $siteFile));

if ($ok) {
  @chmod($siteFile, 0666);
  echo json_encode(['ok'=>true,'debug_toaster'=>$flag]);
} else {
  @unlink($tmp);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'write_failed']);
}
?>