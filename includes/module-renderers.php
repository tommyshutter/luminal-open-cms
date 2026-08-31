<?php
/**
 * @appname   Luminal CMS
 * @file      /includes/module-renderers.php
 * @purpose   Module renderer autoloader — the WordPress-plugin pattern.
 *
 * Every module MAY ship renderers in {module}/includes/renderers/*.renderer.php.
 * On each request we pull in the renderers of every INSTALLED module. A module
 * is "installed" iff its directory is physically present — and push-only deploy
 * guarantees a module is only ever on disk where its distribution places it.
 * So core carries ZERO knowledge of any extension's renderers: an extension
 * owns its own renderers and they load automatically wherever it lives.
 *
 * An absent module simply never contributes renderers → the shortcode / header
 * dispatch falls through its function_exists() guard → core never breaks.
 *
 * Idempotent: safe to require + call from multiple bootstrap points.
 *
 * FUTURE (Phase 2): consult a per-site activation overlay (admin/data/
 * extensions.json) so an installed extension can be deactivated without a
 * redeploy. Default today = active-if-installed (no behaviour change).
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

if (!function_exists('lum_load_module_renderers')) {
  /**
   * Include every installed module's *.renderer.php exactly once.
   * require_once dedupes against direct __DIR__ requires between renderers.
   */
  function lum_load_module_renderers(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $glob = SITE_ROOT . '/admin/modules/*/includes/renderers/*.renderer.php';
    foreach (glob($glob) ?: [] as $file) {
      require_once $file;
    }
  }
}

// Auto-run on include so a bare require_once wires everything up.
lum_load_module_renderers();
