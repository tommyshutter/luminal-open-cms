<?php
/**
 * @file    /admin/includes/panels_catalog.php
 * @version 2025.09.23.12:55-EST
 * @purpose Return a filtered list of panel PHP files in /panels, honoring panels_whitelist.json (or legacy).
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));

function pm_load_panel_whitelist(): array {
  $candidates = [
    SITE_ROOT . '/admin/data/panels_whitelist.json',
    SITE_ROOT . '/admin/data/panel-whitelist.json',
  ];
  foreach ($candidates as $p) {
    if (!is_file($p)) continue;
    $raw = @file_get_contents($p);
    $j = json_decode($raw ?? '', true);
    if (!is_array($j)) return [];
    if (array_is_list($j)) return array_values(array_filter(array_map('strval', $j)));
    if (isset($j['allowed']) && is_array($j['allowed'])) return array_values(array_filter(array_map('strval', $j['allowed'])));
    return [];
  }
  return [];
}

/**
 * Returns array of panel filenames (basename.php) filtered by whitelist if present.
 * If no whitelist exists, returns ALL *.php in /panels.
 */
function pm_list_available_panels(): array {
  $dir = SITE_ROOT . '/panels';
  if (!is_dir($dir)) return [];
  $files = glob($dir . '/*.php') ?: [];
  $names = array_map('basename', $files);

  $wl = pm_load_panel_whitelist();
  if (!empty($wl)) {
    // Only include what the whitelist allows
    $names = array_values(array_filter($names, fn($n) => in_array($n, $wl, true)));
  }

  sort($names, SORT_NATURAL|SORT_FLAG_CASE);
  return $names;
}

/* Example usage (server-side rendering):
$panels = pm_list_available_panels();
foreach ($panels as $p) {
  echo '<li class="pm-panel-item" data-panel="'.htmlspecialchars($p).'">'.htmlspecialchars($p).'</li>';
} 
*/
?>