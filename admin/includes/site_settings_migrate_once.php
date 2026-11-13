<?php
/**
 * @file /admin/includes/site_settings_migrate_once.php
 * @version 2025.09.05.min
 * One-shot, silent migration to /admin/data/site-settings.json
 * - If unified already exists, we merge INTO it (keeping existing keys).
 * - We read legacy files if present and fill any missing settings.
 * - We also ensure /admin/data/pages/home/home.json exists (basic skeleton).
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/..') ? dirname(__DIR__) : dirname(__DIR__));

$DATA_DIR   = SITE_ROOT . '/admin/data';
$UNIFIED    = $DATA_DIR . '/site-settings.json';
$PAGES_DIR  = $DATA_DIR . '/pages';

$legacy_candidates = [
  $DATA_DIR,                             // normal
  SITE_ROOT . '/admin/data',             // same as above; keep for safety
  dirname($DATA_DIR) . '/admin/data',    // guard for odd deployments
];

$legacy_map = [
  'meta_settings.json'       => ['site_title','site_name','meta_description','meta_keywords','meta_author','og_image_url','og_url','site_favicon','favicon_url','header_font_family','header_font_size','header_font_color','header_font_bold','header_font_italic','body_font_family','body_font_size','body_font_color','body_font_bold','body_font_italic','epk_content'],
  'logo_settings.json'       => ['enable_logo','logo','logo_path','logo_max_width','logo_margin_bottom'],
  'header_settings.json'     => ['full_width_header','sticky_menu','sticky_menu_hide_on_scroll','home_page_slug'],
  'layout_settings.json'     => ['left_col_disabled','left_col_width','right_col_width','main_content_margin_top'],
  'menu.json'                => ['items','menu_bg_color','menu_bg_opacity','menu_item_bg_color','menu_item_bg_opacity','menu_item_hover_bg_color','menu_item_hover_bg_opacity','menu_font_family','menu_font_size','menu_font_color','menu_font_hover_color','menu_current_item_bg_color','menu_current_item_bg_opacity','menu_current_item_font_color'],
  'background_settings.json' => ['background_video','background_images','background_opacity'],
  // in case an older unified (underscore) exists:
  'site_settings.json'       => ['*'] // take all keys
];

function _ri_load_json($p){ if(!is_file($p)) return []; $j=json_decode((string)@file_get_contents($p),true); return (json_last_error()===JSON_ERROR_NONE && is_array($j))?$j:[]; }
function _ri_save_json($p,$a){ $d=dirname($p); if(!is_dir($d)) @mkdir($d,0775,true); $tmp=tempnam($d,'tmp'); @file_put_contents($tmp,json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); @rename($tmp,$p); }
function _ri_slug($s){ return strtolower(preg_replace('/[^A-Za-z0-9_\-]/','',$s)); }

try {
  // Start with whatever the current unified file already has (preserve hero_* etc.)
  $merged = _ri_load_json($UNIFIED);

  // Sweep legacy
  foreach ($legacy_candidates as $root) {
    if (!is_dir($root)) continue;
    foreach ($legacy_map as $file => $keys) {
      $path = $root . '/' . $file;
      if (!is_file($path)) continue;
      $src = _ri_load_json($path);
      if (!$src) continue;

      if ($file === 'menu.json' && isset($src['items'])) {
        if (!isset($merged['menu_items'])) $merged['menu_items'] = $src['items'];
      }

      if ($keys === ['*']) {
        // copy everything that isn't already present
        foreach ($src as $k=>$v) if (!array_key_exists($k,$merged)) $merged[$k] = $v;
      } else {
        foreach ($keys as $k) {
          if (array_key_exists($k,$src) && !array_key_exists($k,$merged)) {
            $merged[$k] = $src[$k];
          }
        }
      }
    }
  }

  // Normalize common aliases
  if (isset($merged['logo']) && !isset($merged['logo_path'])) $merged['logo_path'] = $merged['logo'];
  if (isset($merged['items']) && !isset($merged['menu_items'])) $merged['menu_items'] = $merged['items'];
  if (empty($merged['home_page_slug'])) $merged['home_page_slug'] = 'home';

  // Reasonable defaults (only if still absent)
  $host = $_SERVER['HTTP_HOST'] ?? 'Site';
  $defaults = [
    'site_title'=>$host, 'site_name'=>$host, 'meta_description'=>'',
    'header_font_family'=>'Inter','header_font_size'=>'1.6','header_font_color'=>'#ffffff','header_font_bold'=>false,'header_font_italic'=>false,
    'body_font_family'=>'Inter','body_font_size'=>'1.0','body_font_color'=>'#ffffff','body_font_bold'=>false,'body_font_italic'=>false,
    'menu_font_family'=>'Inter','menu_font_size'=>'1.00','menu_font_color'=>'#ffffff',
    'menu_bg_color'=>'transparent','menu_bg_opacity'=>0,
    'menu_item_bg_color'=>'transparent','menu_item_bg_opacity'=>0,
    'menu_item_hover_bg_color'=>'transparent','menu_item_hover_bg_opacity'=>0,
    'menu_font_hover_color'=>'#ffffff',
    'menu_current_item_bg_color'=>'transparent','menu_current_item_bg_opacity'=>0,
    'menu_current_item_font_color'=>'#ffffff',
    'enable_logo'=>true,'logo_path'=>'logos/logo.png','logo_max_width'=>120,'logo_margin_bottom'=>0,
    'full_width_header'=>true,'sticky_menu'=>true,'sticky_menu_hide_on_scroll'=>false,
    'left_col_disabled'=>false,'left_col_width'=>30,'right_col_width'=>70,'main_content_margin_top'=>120,
    'menu_items'=>[]
  ];
  foreach ($defaults as $k=>$v) if (!array_key_exists($k,$merged)) $merged[$k] = $v;

  // Write unified file
  _ri_save_json($UNIFIED, $merged);

  // Ensure a basic /pages/home/home.json exists (so "home" resolves)
  $homeDir  = $PAGES_DIR . '/home';
  $homeFile = $homeDir . '/home.json';
  if (!is_dir($homeDir)) @mkdir($homeDir, 0775, true);
  if (!is_file($homeFile)) {
    $seed = [
      "page_title"=>"Home",
      "right_column_enabled"=>true,
      "left_width"=>50, "right_width"=>50,
      "use_wrapper"=>true, "include_bg"=>true,
      "components"=>[
        "main_content"=>["content"=>"\r\n[[stack:1]]\r\n"],
        "right_column"=>["content"=>"\r\n[[stack:2]]\r\n"]
      ],
      "saved_at"=>gmdate('c')
    ];
    _ri_save_json($homeFile, $seed);
  }

} catch (\Throwable $t) {
  // Silent; header should never fatal because of migration. If something explodes, we just no-op.
  // Optional: write a tiny breadcrumb (won't break rendering).
  @file_put_contents($DATA_DIR.'/_migrate_once_error.log', '['.date('c').'] '.$t->getMessage().' @ '.$t->getFile().':'.$t->getLine()."\n", FILE_APPEND);
}
?>