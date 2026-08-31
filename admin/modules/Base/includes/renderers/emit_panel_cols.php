<?php
/**
 * Emit Panel Column CSS Vars (Left/Right) from Home _manager settings
 * @appname   Luminal Open CMS
 * @file      /includes/ri/emit_panel_cols.php
 * @version   2025.09.28.1138.40-EST — r1205
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Reads manager JSON (left/right) from several likely paths and emits a <style>
 *   that sets --ri-cols for #panel-left and #panel-right. No side effects if files
 *   are missing. Additive-only per contract.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

function _ri_pick(array $arr, array $keys, $default = null) {
  foreach ($keys as $k) {
    if (array_key_exists($k, $arr)) return $arr[$k];
  }
  return $default;
}

function _ri_read_json(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false) return [];
  $dec = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE && is_array($dec)) ? $dec : [];
}

function _ri_find_cols(array $data, int $fallback): int {
  if (!$data) return $fallback;

  // If data has nested options/settings blocks, merge those first
  $blocks = [];
  if (isset($data['options'])  && is_array($data['options']))  $blocks[] = $data['options'];
  if (isset($data['settings']) && is_array($data['settings'])) $blocks[] = $data['settings'];
  $blocks[] = $data;

  $cols = $fallback;
  foreach ($blocks as $blk) {
    $v = _ri_pick($blk, ['columns','cols','col_count','colcount','num_columns','numCols'], null);
    if ($v !== null && is_numeric($v)) $cols = max(1, (int)$v);
  }
  return $cols;
}

// Candidate paths your managers might write to
$leftCandidates = [
  SITE_ROOT . '/admin/data/panels/home_left_manager.json',
  SITE_ROOT . '/admin/data/home_left_manager.json',
  SITE_ROOT . '/admin/data/panels/home-left.json',
  SITE_ROOT . '/admin/data/panels/left.json',
  SITE_ROOT . '/admin/data/panel-settings/home_left.json',
];

$rightCandidates = [
  SITE_ROOT . '/admin/data/panels/home_right_manager.json',
  SITE_ROOT . '/admin/data/home_right_manager.json',
  SITE_ROOT . '/admin/data/panels/home-right.json',
  SITE_ROOT . '/admin/data/panels/right.json',
  SITE_ROOT . '/admin/data/panel-settings/home_right.json',
];

// Read first existing file in each list
$leftData = [];
foreach ($leftCandidates as $p) { if (is_file($p)) { $leftData = _ri_read_json($p); break; } }
$rightData = [];
foreach ($rightCandidates as $p) { if (is_file($p)) { $rightData = _ri_read_json($p); break; } }

// Defaults if not found
$leftCols  = _ri_find_cols($leftData, 1);
$rightCols = _ri_find_cols($rightData, 1);

// Emit one small <style>. We support both IDs and data attributes in case markup varies.
$leftCols  = max(1, (int)$leftCols);
$rightCols = max(1, (int)$rightCols);

// safe HTML
?>
<style>
  #panel-left,
  [data-panel="left"] { --ri-cols: <?php echo $leftCols; ?>; }

  #panel-right,
  [data-panel="right"] { --ri-cols: <?php echo $rightCols; ?>; }
</style>
