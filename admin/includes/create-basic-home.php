<?php
/**
 * Create Basic Home (seed pages + set as home) — lives in /admin/includes
 * @file /admin/includes/create-basic-home.php
 * @version 2025.09.05.seed.v2-includes
 */

declare(strict_types=1);

// Optional auth
$auth = dirname(__DIR__) . '/auth.php'; // /admin/auth.php
if (is_file($auth)) { require_once $auth; if (function_exists('requireAuth')) requireAuth(); }

// SITE_ROOT: two levels up from /admin/includes
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

/* --- tiny JSON helpers (no external deps) --- */
function ri_admin_data_path(string $basename): string {
  return SITE_ROOT . '/admin/data/' . $basename;
}
function ri_data_path(string $basename): string {
  return SITE_ROOT . '/data/' . $basename;
}
function ri_load_json_any(string $basename): array {
  foreach ([ri_admin_data_path($basename), ri_data_path($basename)] as $p) {
    if (is_file($p)) {
      $raw = @file_get_contents($p);
      if ($raw !== false) {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
      }
    }
  }
  return [];
}
function ri_save_json_admin(string $basename, array $data): bool {
  $path = ri_admin_data_path($basename);
  @mkdir(dirname($path), 0775, true);
  return (bool)@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* --- ensure pages dir (prefer /admin/data/pages) --- */
function ri_pages_dir(): string {
  $prefer = SITE_ROOT . '/admin/data/pages';
  if (!is_dir($prefer)) @mkdir($prefer, 0775, true);
  if (is_dir($prefer) && is_writable($prefer)) return $prefer;

  $fallback = SITE_ROOT . '/data/pages';
  if (!is_dir($fallback)) @mkdir($fallback, 0775, true);
  return $fallback;
}

/* --- seed payloads (your provided JSON) --- */
$HOME_JSON = <<<JSON
{
  "page_title": "Home",
  "right_column_enabled": true,
  "left_width": 50,
  "right_width": 50,
  "use_wrapper": true,
  "include_bg": true,
  "components": {
    "main_content": {
      "content": "\\r\\n[[stack:1]]\\r\\n"
    },
    "right_column": {
      "content": "\\r\\n[[stack:2]]\\r\\n"
    }
  },
  "saved_at": "2025-09-05T20:06:09+00:00"
}
JSON;

$LEFT_JSON = <<<JSON
{
  "column_count": 1,
  "content": [
    {
      "type": "image",
      "path": "/media/images/left_panel/placeholder.image",
      "thumbnail": "/media/images/left_panel/placeholder.image",
      "title": "LCC.png",
      "author": "",
      "url": "",
      "lightbox": true
    }
  ]
}
JSON;

$RIGHT_JSON = <<<JSON
{
  "column_count": 1,
  "content": [
    {
      "type": "image",
      "path": "/media/images/right_panel/placeholder.image",
      "thumbnail": "/media/images/right_panel/placeholder.image",
      "title": "RCC.png",
      "author": "",
      "url": "",
      "lightbox": true
    }
  ]
}
JSON;

/* --- perform work --- */
$ok = true; $err = '';

$pagesDir = ri_pages_dir(); // creates /admin/data/pages if missing
if (!is_dir($pagesDir) || !is_writable($pagesDir)) {
  $ok = false; $err = 'Pages directory is not writable: ' . $pagesDir;
}

if ($ok) {
  $homeFile = rtrim($pagesDir, '/') . '/home.json';
  if (@file_put_contents($homeFile, $HOME_JSON) === false) {
    $ok = false; $err = 'Failed writing ' . $homeFile;
  }
}

if ($ok) {
  // Ensure /admin/data exists
  @mkdir(SITE_ROOT . '/admin/data', 0775, true);
  if (@file_put_contents(SITE_ROOT . '/admin/data/content-stack-1.json', $LEFT_JSON) === false) {
    $ok = false; $err = 'Failed writing admin/data/content-stack-1.json';
  }
}
if ($ok) {
  if (@file_put_contents(SITE_ROOT . '/admin/data/content-stack-2.json', $RIGHT_JSON) === false) {
    $ok = false; $err = 'Failed writing admin/data/content-stack-2.json';
  }
}
if ($ok) {
  // Register stack1/stack2 so the seeded [[stack:1]]/[[stack:2]] resolve (panels retired 2026-06-08).
  // Only create if absent — never clobber an existing registry.
  $regFile = SITE_ROOT . '/admin/data/content-stacks-registry.json';
  if (!is_file($regFile)) {
    $REG_JSON = '[{"id":"stack1","slug":"stack-1","label":"Stack 1"},{"id":"stack2","slug":"stack-2","label":"Stack 2"}]';
    if (@file_put_contents($regFile, $REG_JSON) === false) {
      $ok = false; $err = 'Failed writing admin/data/content-stacks-registry.json';
    }
  }
}

if ($ok) {
  // Set as home in meta
  $meta = ri_load_json_any('meta_settings.json');
  $meta['home_page_slug'] = 'home';
  if (!ri_save_json_admin('meta_settings.json', $meta)) {
    $ok = false; $err = 'Could not update admin/data/meta_settings.json';
  }
}

/* --- bounce back to the panel --- */
$dest = '/admin/site-settings.php';
$dest .= $ok ? '?seed=1' : ('?err=' . rawurlencode($err));
header('Location: ' . $dest);
exit;
?>