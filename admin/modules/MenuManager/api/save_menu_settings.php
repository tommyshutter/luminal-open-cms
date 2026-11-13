<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//
//  This endpoint WRITES the site's navigation and had no authentication of any kind:
//  no guard, no session check, no CSRF token. Anonymous requests reached the JSON parse
//  and, with a well-formed body, would have rewritten the menu on any of the 30 sites.
//  Found by the web-exposure audit, which flagged the file as reachable.
//
//  guard_require_auth() answers API requests with 401 JSON and browser requests with a
//  redirect to the login page, so this is safe for both callers.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$MENU_DIR  = SITE_ROOT . '/admin/data/menu';
$DATA_FILE = $MENU_DIR . '/menu_settings.json'; // → /admin/data/menu/menu_settings.json

// Ensure menu directory exists
if (!is_dir($MENU_DIR)) {
  @mkdir($MENU_DIR, 0755, true);
}

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
if ($raw === false) { echo json_encode(['success'=>false,'error'=>'no_input']); exit; }

$in = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($in)) {
  echo json_encode(['success'=>false,'error'=>'bad_json']); exit;
}

// All menu styling fields
$out = [
  'menu_font_family'              => (string)($in['menu_font_family'] ?? 'Inter, system-ui, sans-serif'),
  'menu_font_size'                => (int)($in['menu_font_size'] ?? 15),
  'menu_font_weight'              => (int)($in['menu_font_weight'] ?? 500),
  'menu_font_style'               => (string)($in['menu_font_style'] ?? 'normal'),
  'menu_font_color'               => (string)($in['menu_font_color'] ?? '#ffffff'),
  'menu_font_hover_color'         => (string)($in['menu_font_hover_color'] ?? '#60a5fa'),
  'menu_current_item_font_color'  => (string)($in['menu_current_item_font_color'] ?? '#ffffff'),
  'menu_bg_color'                 => (string)($in['menu_bg_color'] ?? '#0a0a0a'),
  'menu_bg_opacity'               => (float)($in['menu_bg_opacity'] ?? 0.4),
  'menu_item_bg_color'            => (string)($in['menu_item_bg_color'] ?? '#ffffff'),
  'menu_item_bg_opacity'          => (float)($in['menu_item_bg_opacity'] ?? 0.03),
  'menu_item_hover_bg_color'      => (string)($in['menu_item_hover_bg_color'] ?? '#3b82f6'),
  'menu_item_hover_bg_opacity'    => (float)($in['menu_item_hover_bg_opacity'] ?? 0.2),
  'menu_current_item_bg_color'    => (string)($in['menu_current_item_bg_color'] ?? '#ffffff'),
  'menu_current_item_bg_opacity'  => (float)($in['menu_current_item_bg_opacity'] ?? 0.1),
  'menu_alignment'                => (string)($in['menu_alignment'] ?? 'left'),
  'menu_disabled'                 => (bool)($in['menu_disabled'] ?? false),
];

// Merge with existing (don’t clobber unrelated keys)
$existing = [];
if (is_file($DATA_FILE)) {
  $rawOld = @file_get_contents($DATA_FILE);
  if ($rawOld !== false) {
    $j = json_decode($rawOld, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) $existing = $j;
  }
}
$merged = array_merge($existing, $out);

// Atomic write
$tmp = $DATA_FILE . '.tmp';
if (@file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) {
  echo json_encode(['success'=>false,'error'=>'temp_write_failed']); exit;
}
@chmod($tmp, 0664);  // 664, not 666 — admin/data perms law
if (!@rename($tmp, $DATA_FILE)) {
  echo json_encode(['success'=>false,'error'=>'write_failed']); exit;
}

echo json_encode(['success'=>true]);
?>