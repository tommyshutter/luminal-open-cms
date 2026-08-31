<?php
/**
 * @appname     RI Admin — Media Actions
 * @file        SITE-ROOT/admin/ajax/handle_media_actions.php
 * @version     2025.09.10.2259.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Folder ops + bulk delete for the Media Manager.
 *              Self-contained & sandboxed under /media only.
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
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));

$roots = [
  '/media/images' => SITE_ROOT . '/media/images',
  '/media/videos' => SITE_ROOT . '/media/videos',
  '/media/pdfs'   => SITE_ROOT . '/media/pdfs',
];

function fail($m,$c=400){ http_response_code($c); echo json_encode(['success'=>false,'message'=>$m]); exit; }
function ok($d=[]){ echo json_encode(['success'=>true]+$d); exit; }

function webToAbsStrict(string $web) {
  $web = str_replace('\\','/',$web);
  if ($web==='' || $web[0]!=='/') return false;
  if (strpos($web, '..')!==false) return false;
  $abs = realpath(SITE_ROOT . $web) ?: (SITE_ROOT . $web);
  $abs = str_replace('\\','/',$abs);
  $media = realpath(SITE_ROOT . '/media');
  if ($media===false) return false;
  if (strpos($abs, $media)!==0) return false;
  return $abs;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
  case 'add_folder': {
    $dirWeb = $_POST['directory'] ?? '';
    $name   = $_POST['name'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_\- ]{1,64}$/', $name)) fail('Invalid folder name');
    $dirAbs = webToAbsStrict($dirWeb);
    if ($dirAbs===false) fail('Bad base directory');
    $rootOk = false;
    foreach ($roots as $w=>$a) if (strpos($dirAbs, $a)===0) { $rootOk=true; break; }
    if (!$rootOk) fail('Not inside /media');
    $target = $dirAbs . '/' . $name;
    if (is_dir($target)) ok(['message'=>'Folder exists','folder'=>$dirWeb.'/'.$name]);
    if (!@mkdir($target, 0775, true)) fail('Failed to create folder',500);
    ok(['folder'=>$dirWeb.'/'.$name]);
  }

  case 'delete_folder': {
    $dirWeb = $_POST['directory'] ?? '';
    if ($dirWeb==='/media/images' || $dirWeb==='/media/videos' || $dirWeb==='/media/pdfs') fail('Refuse to delete a root');
    $dirAbs = webToAbsStrict($dirWeb);
    if ($dirAbs===false) fail('Bad directory');
    // must be empty
    foreach (scandir($dirAbs) as $f) {
      if ($f==='.'||$f==='..') continue;
      if ($f[0]==='.') continue;
      fail('Folder not empty');
    }
    if (!@rmdir($dirAbs)) fail('Failed to delete folder',500);
    ok();
  }

  case 'bulk_delete': {
    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths) || !count($paths)) fail('No paths');
    $deleted = 0;
    foreach ($paths as $web) {
      $abs = webToAbsStrict($web);
      if ($abs===false || !is_file($abs)) continue;
      // safety: must be under one of our roots
      $ok=false; foreach ($roots as $w=>$a) if (strpos($abs,$a)===0) { $ok=true; break; }
      if (!$ok) continue;
      if (@unlink($abs)) $deleted++;
      // try poster too if exists (.cache/*.jpg)
      if (preg_match('/\.(mp4|webm|mov)$/i', $web)) {
        $parts = explode('/', $abs); $file = array_pop($parts);
        $poster = implode('/', $parts).'/.cache/'.preg_replace('/\.(mp4|webm|mov)$/i','.jpg',$file);
        if (is_file($poster)) @unlink($poster);
      }
    }
    ok(['deleted'=>$deleted]);
  }

  default:
    fail('Unknown action');
}
?>