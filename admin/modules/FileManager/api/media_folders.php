<?php
/**
 * @appname     RI Admin — Media Folder Ops
 * @file        SITE-ROOT/admin/ajax/media_folders.php
 * @version     2025.09.11.1357.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Create and delete folders only under /media/{images,videos,pdfs}
 *              (Delete requires empty dir). Emits JSON; logs to /admin/data/logs.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
header('Content-Type: application/json; charset=utf-8');

/* --- root autodetect (same strategy as uploader) --- */
function ri_find_site_root(string $start, int $maxUp = 8): string {
  $cur = realpath($start) ?: $start;
  $best = null; $bestTop = null;
  for ($i=0; $i<=$maxUp; $i++) {
    $try = $cur;
    if (is_dir($try.'/media')) {
      $isAdminNamed = (basename($try) === 'admin');
      $hasMarkers = (is_file($try.'/page.php') || is_dir($try.'/panels') || is_dir($try.'/includes'));
      if (!$isAdminNamed) {
        if ($hasMarkers) $bestTop = $try;
        if ($best === null) $best = $try;
      } else {
        $parent = dirname($try);
        if ($parent && $parent !== $try && is_dir($parent.'/media') && basename($parent) !== 'admin') {
          $bestTop = $parent;
        }
      }
    }
    $parent = dirname($cur);
    if ($parent === $cur || $parent === '' || $parent === '/') break;
    $cur = $parent;
  }
  return ($bestTop ?: $best) ?: (realpath(dirname($start, 4)) ?: dirname($start, 4));
}
if (!defined('SITE_ROOT')) define('SITE_ROOT', ri_find_site_root(__DIR__));

/* --- logging --- */
$LOG_DIR = rtrim(SITE_ROOT,'/').'/admin/data/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$LOG_FILE = (is_dir($LOG_DIR) && is_writable($LOG_DIR))
  ? $LOG_DIR.'/php-media-upload.log'
  : (is_writable(sys_get_temp_dir()) ? rtrim(sys_get_temp_dir(),'/').'/php-media-upload.log' : null);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1'); if ($LOG_FILE) ini_set('error_log',$LOG_FILE);
function _ulog(string $m){ global $LOG_FILE; ($LOG_FILE?@file_put_contents($LOG_FILE,'['.date('c').'] '.$m.PHP_EOL,FILE_APPEND):@error_log($m)); }
function jfail(string $m,int $code=400,array $extra=[]){ http_response_code($code); _ulog("FOLDER_FAIL($code): $m ".json_encode($extra)); echo json_encode(['ok'=>false,'success'=>false,'message'=>$m]+$extra); exit; }
function jok(array $out=[]){ _ulog('FOLDER_OK '.json_encode($out)); echo json_encode(['ok'=>true,'success'=>true]+$out); exit; }

/* --- path guards --- */
function safe_media_base(string $web) {
  $web = str_replace('\\','/',$web);
  if ($web==='' || $web[0] !== '/' || strpos($web,'..')!==false) return false;
  $allow = ['/media/images','/media/videos','/media/pdfs','/media']; // allow root of /media too
  $ok=false; foreach ($allow as $p){ if (strpos($web,$p)===0) { $ok=true; break; } }
  if (!$ok) return false;
  $abs = rtrim(SITE_ROOT,'/').$web;
  $realAbs = realpath(is_dir($abs)?$abs:dirname($abs));
  $realMed = realpath(rtrim(SITE_ROOT,'/').'/media');
  if ($realAbs===false || $realMed===false) return false;
  if (strpos($realAbs, $realMed)!==0) return false;
  return rtrim(SITE_ROOT,'/').$web;
}

/* --- inputs --- */
$action = $_POST['action'] ?? '';
$folder = $_POST['folder'] ?? '';
$name   = $_POST['name']   ?? '';

if (!$action) jfail('Missing action');
if (!$folder) jfail('Missing folder');

$base = safe_media_base($folder);
if (!$base) jfail('Invalid base folder: '.$folder);

if ($action === 'mkdir') {
  $name = preg_replace('/[^A-Za-z0-9_\-]/','-', trim((string)$name));
  $name = preg_replace('/-+/','-',$name);
  $name = trim($name,'-_');
  if ($name==='') jfail('Empty folder name');

  $target = rtrim($base,'/').'/'.$name;
  if (is_dir($target)) jfail('Folder already exists', 409, ['path'=>$target]);
  if (!@mkdir($target, 0775, true)) jfail('Failed to create folder', 500, ['path'=>$target]);
  jok(['message'=>'created','path'=>str_replace(SITE_ROOT,'',$target)]);
}
elseif ($action === 'rmdir') {
  $dir = rtrim($base,'/');
  if (!is_dir($dir)) jfail('Folder not found', 404, ['path'=>$dir]);
  $scan = @scandir($dir) ?: [];
  $items = array_values(array_diff($scan, ['.','..']));
  if (count($items) > 0) jfail('Folder not empty', 400, ['path'=>$dir,'count'=>count($items)]);
  if (!@rmdir($dir)) jfail('Failed to delete folder', 500, ['path'=>$dir]);
  jok(['message'=>'deleted','path'=>str_replace(SITE_ROOT,'',$dir)]);
}
else {
  jfail('Unknown action: '.$action);
}
?>