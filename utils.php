<?php
/**
 * Shared helpers
 * @file /utils.php
 * @version 2025.09.05.u2
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__) ?: __DIR__);
}

/** Normalize a path to /admin/data/<basename> */
function adminDataPath(string $basename): string {
  return SITE_ROOT . '/admin/data/' . ltrim($basename, '/');
}

/** Optional fallback /data/<basename> */
function siteDataPath(string $basename): string {
  return SITE_ROOT . '/admin/data/' . ltrim($basename, '/');
}

function ensureAdminDataDir(): void {
  @mkdir(SITE_ROOT . '/admin/data', 0777, true);
}

/** Make JSON a little more forgiving (BOM, trailing commas) */
function sanitize_json_string(string $raw): string {
  // Remove UTF-8 BOM
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  // Remove trailing commas before } or ]
  $raw = preg_replace('/,(\s*[}\]])/m', '$1', $raw);
  return $raw;
}

/** Load JSON from /admin/data then /data (first match wins) */
function loadJsonData(string $basename): array {
  $paths = [adminDataPath($basename), siteDataPath($basename)];
  foreach ($paths as $p) {
    if (is_file($p)) {
      $raw = @file_get_contents($p);
      if ($raw === false) continue;
      $raw = sanitize_json_string($raw);
      $j = json_decode($raw, true);
      if (is_array($j)) return $j;
    }
  }
  return [];
}

/** Save JSON to /admin/data/<basename> (pretty) */
function saveJsonData(string $basename, array $data): bool {
  ensureAdminDataDir();
  $p = adminDataPath($basename);
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  return @file_put_contents($p, $json) !== false;
}

/** Quick bool getter from array */
function arr_bool(array $a, string $k, bool $default=false): bool {
  return isset($a[$k]) ? (bool)$a[$k] : $default;
}

/** Ensure /admin/data/pages exists (the preferred pages dir) */
function ensurePagesDir(): string {
  $pref = SITE_ROOT . '/admin/data/pages';
  if (!is_dir($pref)) @mkdir($pref, 0775, true);
  if (is_dir($pref)) return $pref;
  $fb = SITE_ROOT . '/data/pages';
  if (!is_dir($fb)) @mkdir($fb, 0775, true);
  return $fb;
}

/** Find all page roots */
function pageRoots(): array {
  $roots = [];
  foreach ([SITE_ROOT.'/admin/data/pages', SITE_ROOT.'/data/pages', SITE_ROOT.'/admin/pages', SITE_ROOT.'/pages'] as $d) {
    if (is_dir($d)) $roots[] = $d;
  }
  foreach (glob(SITE_ROOT.'/admin/*/pages') ?: [] as $g) if (is_dir($g)) $roots[] = $g;
  foreach (glob(SITE_ROOT.'/admin/*/*/pages') ?: [] as $g) if (is_dir($g)) $roots[] = $g;
  foreach (glob(SITE_ROOT.'/*/pages') ?: [] as $g) if (is_dir($g)) $roots[] = $g;
  $u=[]; $out=[]; foreach ($roots as $r) if (!isset($u[$r])) { $u[$r]=1; $out[]=$r; }
  return $out;
}

/** Enumerate pages for dropdowns */
function listPages(): array {
  $roots = pageRoots();
  $pagesBySlug = [];
  foreach ($roots as $prio => $root) {
    try {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
      );
      foreach ($it as $info) {
        if (!$info->isFile()) continue;
        if (strtolower($info->getExtension()) !== 'json') continue;
        $slug  = $info->getBasename('.json');
        $data  = json_decode(sanitize_json_string((string)@file_get_contents($info->getPathname())), true) ?: [];
        $title = $data['page_title'] ?? $slug;
        if (!isset($pagesBySlug[$slug])) {
          $pagesBySlug[$slug] = ['slug'=>$slug, 'title'=>$title, 'source'=>$root, 'prio'=>$prio];
        }
      }
    } catch (Throwable $e) {}
  }
  $out = array_values($pagesBySlug);
  usort($out, fn($a,$b)=> strcasecmp($a['title'],$b['title']));
  return $out;
}
?>