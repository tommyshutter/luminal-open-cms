<?php
/**
 * @appname     RI Admin — Media Scan
 * @file        SITE-ROOT/admin/ajax/scan_folders.php
 * @version     2025.09.10.2259.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Lists folders recursively and (optionally) lists items in a folder.
 *              Self-contained (no external includes). Only scans /media/{images,videos,pdfs}.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
$allowed = [
  'images' => SITE_ROOT . '/media/images',
  'videos' => SITE_ROOT . '/media/videos',
  'pdfs'   => SITE_ROOT . '/media/pdfs',
];

$type    = $_GET['type']   ?? 'images'; // images|videos|pdfs
$action  = $_GET['action'] ?? 'folders'; // folders|list
$dirWeb  = $_GET['directory'] ?? '';

function fail($msg, $code=400) { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
function ok($data=[]) { echo json_encode(['success'=>true]+$data); exit; }

if (!isset($allowed[$type])) fail('Bad type');

$baseAbs = $allowed[$type];
$baseWeb = '/media/' . $type;

function webToAbs(string $web, string $baseAbs, string $baseWeb) {
  $web = str_replace('\\','/',$web);
  if (strpos($web, $baseWeb)!==0) return false;
  $abs = realpath(SITE_ROOT . $web) ?: SITE_ROOT . $web;
  $abs = str_replace('\\','/',$abs);
  $mediaRoot = realpath(SITE_ROOT . '/media');
  if ($mediaRoot===false) return false;
  if (strpos($abs, $mediaRoot)!==0) return false;
  return $abs;
}

function listFolders(string $rootAbs, string $rootWeb): array {
  $out = [$rootWeb];
  $it = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($rootAbs, FilesystemIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST);
  foreach ($it as $p) {
    if ($p->isDir()) {
      $rel = substr(str_replace('\\','/',$p->getPathname()), strlen(SITE_ROOT));
      $out[] = $rel;
    }
  }
  $out = array_values(array_unique($out));
  sort($out, SORT_NATURAL|SORT_FLAG_CASE);
  return $out;
}

function listItems(string $abs, string $web): array {
  $out = [];
  if (!is_dir($abs)) return $out;
  foreach (scandir($abs) as $f) {
    if ($f==='.'||$f==='..'||$f[0]==='.') continue;
    $fp = $abs . '/' . $f;
    if (!is_file($fp)) continue;
    $st = @stat($fp);
    $out[] = [
      'name'  => $f,
      'path'  => $web . '/' . $f,
      'size'  => $st ? (int)$st['size'] : 0,
      'mtime' => $st ? (int)$st['mtime'] : 0,
    ];
  }
  usort($out, fn($a,$b)=>strcmp(strtolower($a['name']), strtolower($b['name'])));
  return $out;
}

if ($action === 'list') {
  $abs = webToAbs($dirWeb, $baseAbs, $baseWeb);
  if ($abs === false) fail('Bad directory');
  ok(['items'=>listItems($abs, $dirWeb)]);
} else {
  ok(['folders'=>listFolders($baseAbs, $baseWeb)]);
}
?>