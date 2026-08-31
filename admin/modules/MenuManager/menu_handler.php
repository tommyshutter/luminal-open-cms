<?php
/**
 * @appname  Earl's / RI Admin
 * @file     /admin/menu-manager/menu_handler.php
 * @version  2025.10.02.2049.00-EST r1202-datafile-FIX
 * @desc     Read-only JSON for current menu from /admin/data/menu_items.json
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) { define('SITE_ROOT', dirname(__DIR__, 3)); }
$MENU_JSON = SITE_ROOT . '/admin/data/menu/menu_items.json';

if (!is_file($MENU_JSON)) { echo json_encode(['ok'=>true,'menu'=>[]]); exit; }

$raw  = @file_get_contents($MENU_JSON);
$data = json_decode((string)$raw, true);

$out = [];
if (is_array($data)) {
  if (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
  foreach ($data as $row) {
    if (!is_array($row)) continue;
    $title = trim((string)($row['title'] ?? $row['label'] ?? ''));
    $url   = trim((string)($row['url']   ?? $row['href']  ?? ''));
    $slug  = trim((string)($row['slug']  ?? ''));
    if ($slug === '' && $url !== '') {
      $parts = parse_url($url);
      if (!empty($parts['query'])) { parse_str($parts['query'], $q); if (!empty($q['p'])) $slug = (string)$q['p']; }
    }
    if ($title !== '' && $url !== '' && $slug !== '') { $out[] = ['title'=>$title,'url'=>$url,'slug'=>$slug]; }
  }
}

echo json_encode(['ok'=>true,'menu'=>$out]);
?>