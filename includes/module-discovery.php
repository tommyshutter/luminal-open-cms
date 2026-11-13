<?php
/**
 * @appname   Luminal CMS
 * @file      /includes/module-discovery.php
 * @purpose   Let installed modules add their own public pages to sitemap.xml and llms.txt / llms-full.txt.
 *
 * Same pattern as includes/module-renderers.php: a module MAY ship
 *     {module}/includes/discovery/*.discovery.php
 * that returns an array of optional callables:
 *     'sitemap' => fn(string $base): array   entries {loc, lastmod?, changefreq?, priority?, image?}
 *     'llms'    => fn(string $base, bool $full): array   sections {title, items:[{title, url, desc?, body?}]}
 *     'mtime'   => fn(): int                  newest change, for llms staleness
 * A module is "installed" iff its directory is present, so core carries no knowledge of any module.
 * A contributor that throws is skipped (logged) and never breaks the sitemap or the briefing.
 */
declare(strict_types=1);

if (!function_exists('lum_module_discovery')) {
  /** Every installed module's discovery contribution (loaded once per request). */
  function lum_module_discovery(): array {
    static $list = null;
    if ($list !== null) return $list;
    $list = [];
    if (!defined('SITE_ROOT')) return $list;
    foreach (glob(SITE_ROOT . '/admin/modules/*/includes/discovery/*.discovery.php') ?: [] as $file) {
      try {
        $r = (static function (string $f) { return require $f; })($file);
        if (is_array($r)) $list[] = $r;
      } catch (\Throwable $e) {
        error_log('module-discovery: ' . $file . ': ' . $e->getMessage());
      }
    }
    return $list;
  }

  /** Call one kind ('sitemap' | 'llms' | 'mtime') on every contributor; returns the list of results. */
  function lum_discovery_call(string $kind, ...$args): array {
    $out = [];
    foreach (lum_module_discovery() as $c) {
      if (!isset($c[$kind]) || !is_callable($c[$kind])) continue;
      try { $out[] = $c[$kind](...$args); }
      catch (\Throwable $e) { error_log('module-discovery ' . ($c['id'] ?? '?') . ' ' . $kind . ': ' . $e->getMessage()); }
    }
    return $out;
  }
}
