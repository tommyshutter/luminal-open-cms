<?php
/**
 * @appname    RI Admin — HostGator Compat Shim
 * @file       /admin/includes/hostgator_compat.inc.php
 * @version    2025.10.02.1428.00-EST r1202
 * @author     ChatGPT
 * @license    Business Source License
 * @description Admin-only bootstrap to surface errors on cPanel and provide safe fallbacks
 *              for environments where SPL/DirectoryIterator or ini settings differ.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/* --- Surface fatal errors in admin context (without changing global php.ini) --- */
@ini_set('display_errors', '1');       // Admin-only
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

/* Pretty inline error renderer (so blanks become actionable) */
if (!function_exists('ri_admin_inline_error')) {
  function ri_admin_inline_error(string $msg): void {
    $safe = htmlspecialchars($msg, ENT_QUOTES);
    echo '<div style="margin:16px;padding:12px;border-radius:8px;border:1px solid #600;background:#200;color:#fbb;">'
       . '<strong>Admin Error:</strong> ' . $safe . '</div>';
  }
}

/* Safe JSON loader (quietly returns [] on failure) */
if (!function_exists('ri_safe_json')) {
  function ri_safe_json(string $path): array {
    clearstatcache(true, $path);
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
}

/* Fallback page lister if SPL/DirectoryIterator is missing or restricted */
if (!function_exists('ri_list_pages_scandir')) {
  function ri_list_pages_scandir(string $pages_dir): array {
    $out = [];
    if (!is_dir($pages_dir)) return $out;
    $entries = @scandir($pages_dir) ?: [];
    foreach ($entries as $slug) {
      if ($slug === '.' || $slug === '..') continue;
      $dir = $pages_dir . '/' . $slug;
      if (!is_dir($dir)) continue;
      $json = $dir . '/' . $slug . '.json';
      $title = $slug;
      $j = ri_safe_json($json);
      if (!empty($j['page_title'])) $title = (string)$j['page_title'];
      $out[] = [
        'slug'  => (string)$slug,
        'title' => (string)$title,
        'url'   => '/page.php?p=' . rawurlencode((string)$slug),
      ];
    }
    return $out;
  }
}
?>