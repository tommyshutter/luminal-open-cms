<?php
/**
 * Luminal Open CMS — Module Loader
 *
 * Discovery is glob-first: the loader scans admin/modules/ and loads every
 * subdirectory that contains a module.json. Drop a module directory in and it
 * appears; remove the directory and it disappears. No registration step, and no
 * central list to edit.
 *
 * An optional registry path exists for managed multi-site installs. If a site has
 * admin/deployment_mgr/site_distribution.json naming a distribution, and a matching
 * admin/modules/registry/{distribution}.json lists module paths, those are used
 * instead — which lets an operator authorise a fixed module set per site. Neither
 * file ships with Luminal Open CMS, so the glob path is the default and, if the
 * registry ever yields nothing, the loader falls back to globbing anyway.
 *
 * Module data belongs in admin/data/{Module}/, never inside the module directory —
 * an update may replace the module directory wholesale.
 */
declare(strict_types=1);

/**
 * Read this site's distribution config from deployment_mgr/site_distribution.json.
 * Returns null if the file doesn't exist (triggers glob fallback).
 */
function lm_get_site_distribution(): ?array {
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2);
    $distFile = $siteRoot . '/admin/deployment_mgr/site_distribution.json';
    if (!is_file($distFile)) return null;
    $data = json_decode(file_get_contents($distFile), true);
    if (empty($data['distribution'])) return null;
    return $data;
}

/**
 * Load the registry JSON for a given distribution name.
 * Returns the flat modules map (name => relative path) or null if not found.
 */
function lm_load_registry(string $distribution): ?array {
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2);
    $registryFile = $siteRoot . '/admin/modules/registry/' . $distribution . '.json';
    if (!is_file($registryFile)) return null;
    $data = json_decode(file_get_contents($registryFile), true);
    return $data['modules'] ?? null;
}

/**
 * Discover all modules for this site.
 *
 * Registry path: reads site_distribution.json, loads registry/{distribution}.json,
 *   merges extension_modules, loads each module.json from declared path.
 *
 * Fallback path: glob over admin/modules dir for module.json files (original behavior).
 *
 * Results are cached in a static variable for the request lifetime.
 *
 * @return array<string, array> Module manifests keyed by module name
 */
function lm_discover_modules(): array {
    static $modules = null;
    if ($modules !== null) return $modules;

    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2);
    $modules  = [];

    // ── Registry path ────────────────────────────────────────────────────────
    $siteDist = lm_get_site_distribution();
    if ($siteDist !== null) {
        $modulePaths = lm_load_registry($siteDist['distribution']) ?? [];

        // Merge per-site extension modules (e.g. financial_platform modules on hub)
        if (!empty($siteDist['extension_modules']) && is_array($siteDist['extension_modules'])) {
            foreach ($siteDist['extension_modules'] as $name => $relPath) {
                $modulePaths[$name] = $relPath;
            }
        }

        foreach ($modulePaths as $name => $relPath) {
            $manifestPath = $siteRoot . '/' . $relPath . '/module.json';
            if (!is_file($manifestPath)) continue;

            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!is_array($manifest) || empty($manifest['name'])) continue;

            $manifest['_dir']       = dirname($manifestPath);
            $manifest['_installed'] = true;
            $manifest['_registry']  = true;
            $modules[$manifest['name']] = $manifest;

            // Ensure required data directories exist. These are created under
            // admin/data/ — NEVER inside the module directory, which an update may
            // replace wholesale, taking the site's data with it.
            foreach (($manifest['requires_data_dirs'] ?? []) as $dir) {
                $fullDir = $siteRoot . '/admin/data/' . ltrim($dir, '/');
                if (!is_dir($fullDir)) {
                    @mkdir($fullDir, 0775, true);
                    @chown($fullDir, 'www-data');
                    @chgrp($fullDir, 'www-data');
                }
            }
        }

        // Resilience (2026-07-06): a PRESENT site_distribution.json whose registry is
        // missing/empty must NOT blank the rail. Return the registry result only if it
        // actually produced modules; otherwise fall through to glob discovery so the rail
        // self-composes from whatever is installed (a missing registry becomes a cache
        // miss, not an empty menu; remove a module and glob drops it silently).
        if (!empty($modules)) return $modules;
    }

    // ── Glob fallback (no site_distribution.json, OR registry yielded nothing) ──
    $modulesDir = $siteRoot . '/admin/modules';
    foreach (glob($modulesDir . '/*/module.json') ?: [] as $manifestPath) {
        $raw      = file_get_contents($manifestPath);
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || empty($manifest['name'])) continue;

        $manifest['_dir']       = dirname($manifestPath);
        $manifest['_installed'] = true;
        $manifest['_registry']  = false;
        $modules[$manifest['name']] = $manifest;

        // Created under admin/data/, never inside the module directory.
        foreach (($manifest['requires_data_dirs'] ?? []) as $dir) {
            $fullDir = $siteRoot . '/admin/data/' . ltrim($dir, '/');
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0775, true);
                @chown($fullDir, 'www-data');
                @chgrp($fullDir, 'www-data');
            }
        }
    }

    return $modules;
}

/**
 * Get menu entries from all discovered modules.
 * Returns entries compatible with Luminal's admin_menu.php format.
 *
 * @return array Menu entries sorted by order
 */
function lm_get_menu_entries(): array {
    $modules = lm_discover_modules();
    $entries = [];

    foreach ($modules as $name => $manifest) {
        $menuItems = [];
        if (!empty($manifest['menu_entries']) && is_array($manifest['menu_entries'])) {
            $menuItems = $manifest['menu_entries'];
        } elseif (!empty($manifest['menu_entry'])) {
            $menuItems = [$manifest['menu_entry']];
        }

        foreach ($menuItems as $menu) {
            if (empty($menu['title']) || empty($menu['href'])) continue;

            $href = $menu['href'];
            if (strpos($href, '/') !== 0) {
                // Build href using the module's actual _dir (supports subdirectory moves)
                $modulesDir = defined('SITE_ROOT')
                    ? SITE_ROOT . '/admin/modules'
                    : dirname(__DIR__);
                $relDir = str_replace($modulesDir . '/', '', $manifest['_dir']);
                $href = '/admin/modules/' . $relDir . '/' . $href;
            }

            $entries[] = [
                'label'  => $menu['title'],
                'url'    => $href,
                'key'    => $menu['key'] ?? $name,
                'role'   => $menu['role'] ?? 'admin',
                'order'  => $menu['order'] ?? 50,
                'module' => $name,
                'group'  => $manifest['menu_group'] ?? null,
                'icon'   => $menu['icon'] ?? null,
                // Per-entry section override (self-registration). When set, this single
                // entry lands in the named section instead of the module's central
                // menu_sections mapping — lets one module place different entries in
                // different sections. Ignored if the section isn't present on the site;
                // the module must still be allowlisted for ANY entry to show.
                'section' => $menu['section'] ?? null,
                // Optional per-item rail text-color token (e.g. "orange","red","light").
                'color'  => $menu['color'] ?? null,
                // Agnostic opt-in: a platform-extension control panel is visible to
                // platform admins regardless of CMS role (see am_filter_menu_item).
                'platform_extension' => !empty($menu['platform_extension']),
            ];

            // Dynamic self-registration: a module may ship menu_state.php returning
            // ['color'=>, 'count'=>] to override its rail entry live (e.g. chartreuse when
            // live credentials exist). Only included when the file is present (cheap).
            $_msf = ($manifest['_dir'] ?? '') . '/menu_state.php';
            if (is_file($_msf)) {
                $_st = @include $_msf;
                if (is_array($_st)) {
                    $_i = count($entries) - 1;
                    if (!empty($_st['color']))  $entries[$_i]['color'] = (string)$_st['color'];
                    if (isset($_st['count']))   $entries[$_i]['count'] = (int)$_st['count'];
                }
            }
        }
    }

    usort($entries, fn($a, $b) => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));
    return $entries;
}

/**
 * Get the manifest.json module groups.
 *
 * @return array Module groups with labels and module lists
 */
function lm_get_module_groups(): array {
    $manifestFile = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2)) . '/admin/modules/manifest.json';
    if (!is_file($manifestFile)) return [];

    $manifest = json_decode(file_get_contents($manifestFile), true);
    return $manifest['module_groups'] ?? [];
}

/**
 * Check if a specific module is installed and authorised for this site.
 *
 * @param string $name Module name (e.g., 'SiteSettings')
 * @return bool
 */
function lm_is_module_installed(string $name): bool {
    $modules = lm_discover_modules();
    return isset($modules[$name]);
}

/**
 * Get a single module's manifest.
 *
 * @param string $name Module name
 * @return array|null Manifest or null if not found
 */
function lm_get_module(string $name): ?array {
    $modules = lm_discover_modules();
    return $modules[$name] ?? null;
}

/**
 * Return which distribution this site is running, or 'unknown' if no registry.
 */
function lm_get_distribution(): string {
    $dist = lm_get_site_distribution();
    return $dist['distribution'] ?? 'unknown';
}
