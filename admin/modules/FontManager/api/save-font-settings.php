<?php
/**
 * Save Font Settings (menu + basic body text)
 * @file    /admin/ajax/save-font-settings.php
 * @version 2025.09.09.r7
 *
 * Writes:
 *  - /admin/data/menu_settings.json (menu_* fields)
 *  - /admin/data/site-settings.json (body_* + link colors)
 *
 * Output: { ok: true } or { ok: false, error: "msg" }
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

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$dataDir = SITE_ROOT . '/admin/data';
$menuFile = $dataDir . '/menu_settings.json';
$siteFile = $dataDir . '/site-settings.json';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['ok'=>false,'error'=>'bad json']); exit; }

$readJson = function(string $path): array {
  clearstatcache(true, $path);
  if (!is_file($path)) return [];
  $r = @file_get_contents($path);
  $j = json_decode($r, true);
  return is_array($j) ? $j : [];
};
$writeJson = function(string $path, array $j): bool {
  $tmp = $path . '.tmp';
  if (@file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) return false;
  @chmod($tmp, 0666);
  return @rename($tmp, $path);
};

/* ---- menu_settings.json ---- */
$menu = $readJson($menuFile);
$menu['menu_font_family']     = (string)($in['menu_font_family'] ?? ($menu['menu_font_family'] ?? 'system-ui'));
$menu['menu_font_size']       = (float) ($in['menu_font_size']   ?? ($menu['menu_font_size'] ?? 1.55));
$menu['menu_font_color']      = (string)($in['menu_font_color']  ?? ($menu['menu_font_color'] ?? '#eeeeee'));
$menu['menu_bg_transparent']  = !empty($in['menu_bg_transparent']);
$menu['menu_bg_color']        = (string)($in['menu_bg_color']    ?? ($menu['menu_bg_color'] ?? '#000000'));
$menu['menu_bg_opacity']      = (float) ($in['menu_bg_opacity']  ?? ($menu['menu_bg_opacity'] ?? 0.0));
$menu['menu_text_transform']  = (string)($in['menu_text_transform'] ?? ($menu['menu_text_transform'] ?? 'inherit'));

if (!$writeJson($menuFile, $menu)) { echo json_encode(['ok'=>false,'error'=>'menu write']); exit; }

/* ---- site-settings.json (body + link colors) ---- */
$site = $readJson($siteFile);
$site['body_font_family']     = (string)($in['body_font_family'] ?? ($site['body_font_family'] ?? 'system-ui'));
$site['body_font_color']      = (string)($in['body_font_color']  ?? ($site['body_font_color'] ?? '#ffffff'));
$site['link_color']           = (string)($in['link_color']       ?? ($site['link_color'] ?? '#8bd9ff'));
$site['visited_color']        = (string)($in['visited_color']    ?? ($site['visited_color'] ?? '#9aa8ff'));
$site['body_line_height']     = (float) ($in['body_line_height'] ?? ($site['body_line_height'] ?? 1.45));
$site['body_text_transform']  = (string)($in['body_text_transform'] ?? ($site['body_text_transform'] ?? 'inherit'));

if (!$writeJson($siteFile, $site)) { echo json_encode(['ok'=>false,'error'=>'site write']); exit; }

echo json_encode(['ok'=>true]);
?>