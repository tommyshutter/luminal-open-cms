<?php
/**
 * AJAX: Delete a page directory
 * @path   /admin/ajax/delete_page_dir.php
 * @return JSON { ok: bool, slug?: string, removed?: int, path?: string, error?: string }
 */

declare(strict_types=1);

// Hard guard: JSON output only
header('Content-Type: application/json; charset=UTF-8');

// Optional: suppress notices to avoid "headers already sent"
@ini_set('display_errors', '0');

// SITE_ROOT resolution (adjust if your admin lives elsewhere)
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}

// Where pages live
define('PAGES_ROOT', SITE_ROOT . '/admin/data/pages');

// Basic helper: JSON out + exit
function jexit(array $o){ echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }

// Require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jexit(['ok'=>false, 'error'=>'Invalid method']);
}

// Pull slug
$slug = isset($_POST['slug']) ? (string)$_POST['slug'] : '';

// Validate slug: only a-z 0-9 _ - (mirrors your slug sanitizer)
if ($slug === '' || !preg_match('/^[a-z0-9_\-]+$/i', $slug)) {
  jexit(['ok'=>false, 'error'=>'Bad slug']);
}

// For safety: do not allow deletion of certain “core” slugs
$PROTECT = ['home', 'index', 'default'];
if (in_array(strtolower($slug), $PROTECT, true)) {
  jexit(['ok'=>false, 'error'=>'Protected page']);
}

$dir = PAGES_ROOT . '/' . $slug;

// Must exist and be a directory
clearstatcache(true, $dir);
if (!is_dir($dir)) {
  jexit(['ok'=>false, 'error'=>'Page folder not found', 'slug'=>$slug, 'path'=>$dir]);
}

// Pre-flight: check if we can actually write to the parent directory
if (!is_writable(dirname($dir)) || !is_writable($dir)) {
  jexit(['ok'=>false, 'error'=>'Permission denied — page directory is not writable', 'slug'=>$slug]);
}

// Move to trash instead of hard-deleting (matches PageManager.php pattern)
$TRASH_DIR = PAGES_ROOT . '/pages_trash';
if (!is_dir($TRASH_DIR)) @mkdir($TRASH_DIR, 0775, true);

$trashName = $slug . '--' . date('Ymd-His');
$trashDst  = $TRASH_DIR . '/' . $trashName;

if (@rename($dir, $trashDst)) {
  jexit(['ok'=>true, 'slug'=>$slug, 'moved_to'=>$trashName]);
} else {
  $err = error_get_last();
  jexit(['ok'=>false, 'error'=>'Delete failed: ' . ($err['message'] ?? 'rename() failed'), 'slug'=>$slug]);
}
?>
