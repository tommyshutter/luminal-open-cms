<?php
/**
 * Settings Loader (site + menu)
 * @file    /admin/includes/settings-loader.php
 * @version 2025.09.06.r6
 *
 * PURPOSE
 *  - Centralize loading of site (non-menu) settings and menu settings.
 *  - Prefer /admin/data/menu_settings.json for menu structure & presentation.
 *  - Backfill legacy "menu_items" that may still live inside site-settings.json (read-only fallback).
 *  - Provide small helpers for header rendering and active-state logic.
 *
 * OUTPUT (functions)
 *  - ri_load_site_settings(): array
 *  - ri_load_menu_settings(): array
 *  - ri_get_menu_items(): array   // always returns the authoritative items
 *  - ri_get_menu_presentation(): array // menu UI vars (font family/size/colors/sticky)
 *  - ri_menu_active(string $url, ?string $currentPath = null): bool
 *  - ri_normalize_url(string $url): string
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/* ---------------------------
 * Utilities
 * --------------------------- */
if (!function_exists('ri__is_assoc')) {
    function ri__is_assoc(array $a): bool {
        foreach (array_keys($a) as $k) {
            if (!is_int($k)) return true;
        }
        return false;
    }
}
if (!function_exists('ri__load_json')) {
    function ri__load_json(string $path): array {
        if (!is_file($path)) return [];
        clearstatcache(true, $path);
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return [];
        $j = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : [];
    }
}
if (!function_exists('ri_normalize_url')) {
    function ri_normalize_url(string $url): string {
        $u = trim($url);
        if ($u === '') return '/';
        // If it already looks absolute (http/https), keep it.
        if (preg_match('~^https?://~i', $u)) return $u;
        // Ensure leading slash so relative links don’t break from subdirs
        if ($u[0] !== '/') $u = '/' . $u;
        // Collapse double slashes
        $u = preg_replace('~//+~', '/', $u);
        return $u;
    }
}

/* ---------------------------
 * Paths
 * --------------------------- */
$__DATA_DIR  = SITE_ROOT . '/admin/data';
$__SITE_JSON = $__DATA_DIR . '/site-settings.json';
$__MENU_JSON = $__DATA_DIR . '/menu_settings.json';

/* ---------------------------
 * Cached singletons
 * --------------------------- */
$__RI_SITE_CACHE = null;
$__RI_MENU_CACHE = null;

/* ---------------------------
 * Public: load Site (non-menu) Settings
 * --------------------------- */
if (!function_exists('ri_load_site_settings')) {
    function ri_load_site_settings(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $sitePath = SITE_ROOT . '/admin/data/site-settings.json';
        $site = ri__load_json($sitePath);

        // Hard defaults to keep templates from emitting notices
        $HOST = $_SERVER['HTTP_HOST'] ?? 'Site';
        $defaults = [
            'site_title' => $HOST,
            'site_name'  => $HOST,
            'meta_description' => '',
            'meta_keywords'    => '',
            'meta_author'      => '',
            'header_font_family' => 'Inter',
            'header_font_size'   => '1.5',
            'header_font_color'  => '#ffffff',
            'header_font_bold'   => false,
            'header_font_italic' => false,
            'body_font_family' => 'Inter',
            'body_font_size'   => '1.0',
            'body_font_color'  => '#e7edf5',
            'enable_logo'      => true,
            'logo_path'        => '',
            'logo_max_width'   => 180,
            'logo_margin_bottom' => 0,
            'full_width_header' => true,
            'main_content_margin_top' => 145,
            'left_col_disabled' => false,
            'home_page_slug'    => '',
        ];

        // Strip any menu_* keys that might still be lingering
        foreach (array_keys($site) as $k) {
            if (stripos($k, 'menu_') === 0 || $k === 'menu_items' || $k === 'items' || $k === 'sticky_menu') {
                unset($site[$k]);
            }
        }

        $cache = array_replace($defaults, $site);
        return $cache;
    }
}

/* ---------------------------
 * Public: load Menu Settings (authoritative)
 * --------------------------- */
if (!function_exists('ri_load_menu_settings')) {
    function ri_load_menu_settings(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $menuPath = SITE_ROOT . '/admin/data/menu_settings.json';
        $menu = ri__load_json($menuPath);

        // Presentation defaults for menu UI (kept minimal & safe)
        $defaults = [
            'menu_font_family' => 'Inter',
            'menu_font_size'   => '1.0',
            'menu_font_color'  => '#eeeeee',
            'menu_bg_color'                => 'transparent',
            'menu_bg_opacity'              => 0.0,
            'menu_item_bg_color'           => 'transparent',
            'menu_item_bg_opacity'         => 0.0,
            'menu_item_hover_bg_color'     => 'transparent',
            'menu_item_hover_bg_opacity'   => 0.0,
            'menu_current_item_bg_color'   => 'transparent',
            'menu_current_item_bg_opacity' => 0.0,
            'menu_current_item_font_color' => '#ffffff',
            'sticky_menu' => true,
            'menu_items'  => [], // authoritative items live here
        ];

        // If no menu_settings.json yet, fall back to any legacy items inside site file
        if (empty($menu) || !isset($menu['menu_items'])) {
            $site = ri_load_site_settings();
            $legacy = [];
            if (!empty($site['menu_items']) && is_array($site['menu_items']))       $legacy = $site['menu_items'];
            elseif (!empty($site['items']) && is_array($site['items']))             $legacy = $site['items'];

            $menu = array_replace($defaults, ['menu_items' => $legacy]);
        } else {
            $menu = array_replace($defaults, $menu);
        }

        // Normalize each menu item
        $norm = [];
        foreach (($menu['menu_items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $label = trim((string)($item['label'] ?? $item['title'] ?? ''));
            $url   = (string)($item['url'] ?? '');
            $type  = (string)($item['type'] ?? '');
            if ($label === '' && $url === '') continue;

            // If type is "page" but url is missing, derive it from label/slug
            if ($type === 'page' && $url === '') {
                $slug = strtolower(preg_replace('/[^A-Za-z0-9_\-]+/','-', $label));
                $url  = "/page.php?p={$slug}";
            }
            $norm[] = [
                'label' => $label,
                'url'   => ri_normalize_url($url),
                'type'  => $type ?: 'link',
            ];
        }
        $menu['menu_items'] = $norm;

        $cache = $menu;
        return $cache;
    }
}

/* ---------------------------
 * Public helpers for renderers
 * --------------------------- */
if (!function_exists('ri_get_menu_items')) {
    function ri_get_menu_items(): array {
        $m = ri_load_menu_settings();
        return isset($m['menu_items']) && is_array($m['menu_items']) ? $m['menu_items'] : [];
    }
}
if (!function_exists('ri_get_menu_presentation')) {
    /**
     * Returns presentational settings the header might need (font/colors/sticky).
     */
    function ri_get_menu_presentation(): array {
        $m = ri_load_menu_settings();
        return [
            'font_family' => (string)($m['menu_font_family'] ?? 'Inter'),
            'font_size'   => (string)($m['menu_font_size'] ?? '1.0'),
            'font_color'  => (string)($m['menu_font_color'] ?? '#eeeeee'),
            'bg_color'    => (string)($m['menu_bg_color'] ?? 'transparent'),
            'bg_opacity'  => (float)($m['menu_bg_opacity'] ?? 0.0),
            'item_bg_color'  => (string)($m['menu_item_bg_color'] ?? 'transparent'),
            'item_bg_opacity'=> (float)($m['menu_item_bg_opacity'] ?? 0.0),
            'hover_bg_color' => (string)($m['menu_item_hover_bg_color'] ?? 'transparent'),
            'hover_bg_opacity'=> (float)($m['menu_item_hover_bg_opacity'] ?? 0.0),
            'current_bg_color' => (string)($m['menu_current_item_bg_color'] ?? 'transparent'),
            'current_bg_opacity'=> (float)($m['menu_current_item_bg_opacity'] ?? 0.0),
            'current_font_color'=> (string)($m['menu_current_item_font_color'] ?? '#ffffff'),
            'sticky_menu'       => !empty($m['sticky_menu']),
        ];
    }
}
if (!function_exists('ri_menu_active')) {
    /**
     * Basic "active" detector for menu items.
     * - If item URL is absolute, compare its path to current.
     * - Else compare normalized local paths.
     */
    function ri_menu_active(string $itemUrl, ?string $currentPath = null): bool {
        $iu = ri_normalize_url($itemUrl);

        if ($currentPath === null) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            // strip query to compare paths
            $currentPath = parse_url($uri, PHP_URL_PATH) ?: '/';
        }

        // If comparing page by slug (e.g., /page.php?p=about-us), allow match by query p=
        $isSamePath = (strcasecmp($iu, $currentPath) === 0);
        if ($isSamePath) return true;

        $itemQuery = parse_url($iu, PHP_URL_QUERY) ?: '';
        $currQuery = parse_url($currentPath, PHP_URL_QUERY) ?: '';

        // Quick check for ?p=slug equivalence
        parse_str($itemQuery, $iq);
        parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '', $cq);
        if (!empty($iq['p']) && !empty($cq['p'])) {
            return strcasecmp((string)$iq['p'], (string)$cq['p']) === 0;
        }

        return false;
    }
}
?>