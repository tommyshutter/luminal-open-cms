<?php
/**
 * @file admin/modules/AdminThemes/theme_lib.inc.php
 * @desc Pure helpers for AdminThemes — NO output. Shared by theme_head.inc.php
 *       (the render hook) and api.php (persistence). Function-guarded so it can
 *       be required more than once per request.
 */
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

if (!function_exists('admin_themes_available')) {
    /** Registry of installable themes. luminal = base look (no css file). */
    function admin_themes_available(): array {
        return [
            'luminal' => ['label' => 'Luminal',        'css' => false, 'group' => 'Luminal'],
            'macos'   => ['label' => 'macOS',          'css' => true,  'group' => 'Desktop'],
            'windows' => ['label' => 'Windows',        'css' => true,  'group' => 'Desktop'],
            'linux'   => ['label' => 'Ubuntu / GNOME', 'css' => true,  'group' => 'Desktop'],
        ];
    }
}

if (!function_exists('admin_themes_prefs_path')) {
    function admin_themes_prefs_path(): string {
        return SITE_ROOT . '/admin/data/AdminThemes/prefs.json';
    }
}

if (!function_exists('admin_themes_load_prefs')) {
    function admin_themes_load_prefs(): array {
        $p = admin_themes_prefs_path();
        $out = ['site_default' => 'luminal', 'users' => [], 'display' => ['users' => []]];
        if (is_file($p)) {
            $j = json_decode((string)file_get_contents($p), true);
            if (is_array($j)) {
                if (!empty($j['site_default'])) $out['site_default'] = (string)$j['site_default'];
                if (!empty($j['users']) && is_array($j['users'])) $out['users'] = $j['users'];
                if (!empty($j['display']) && is_array($j['display'])) {
                    $out['display'] = $j['display'];
                    if (!isset($out['display']['users']) || !is_array($out['display']['users'])) {
                        $out['display']['users'] = [];
                    }
                }
                if (!empty($j['custom_themes']) && is_array($j['custom_themes'])) {
                    $out['custom_themes'] = $j['custom_themes'];
                }
            }
        }
        return $out;
    }
}

if (!function_exists('admin_themes_save_prefs')) {
    function admin_themes_save_prefs(array $prefs): bool {
        $p   = admin_themes_prefs_path();
        $dir = dirname($p);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $p . '.tmp';
        $ok  = @file_put_contents($tmp, json_encode($prefs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($ok === false) return false;
        return @rename($tmp, $p);
    }
}

if (!function_exists('admin_themes_current_user_key')) {
    function admin_themes_current_user_key(): string {
        if (!empty($_SESSION['user_id']))  return (string)$_SESSION['user_id'];
        if (!empty($_SESSION['username'])) return 'u:' . (string)$_SESSION['username'];
        return 'admin';
    }
}

if (!function_exists('admin_themes_resolve')) {
    /** Resolve the active theme slug for the current user, validated against the registry. */
    function admin_themes_resolve(): string {
        $avail = admin_themes_available();
        $prefs = admin_themes_load_prefs();
        $key   = admin_themes_current_user_key();
        $theme = $prefs['users'][$key] ?? ($prefs['site_default'] ?? 'luminal');
        if (!isset($avail[$theme])) $theme = 'luminal';
        return $theme;
    }
}

/* ===================================================================== *
 * Display & Accessibility — per-user, layered on ANY theme incl. the
 * default "luminal" look. Font scale, contrast, tactile (shadows+hover),
 * density, accent color, and identity-palette presets.
 * ===================================================================== */

if (!function_exists('admin_themes_display_defaults')) {
    /** The neutral baseline = today's look, unchanged. */
    function admin_themes_display_defaults(): array {
        return [
            'scale'    => 1.0,       // 0.85 .. 1.6  (text/zoom magnification)
            'contrast' => 'normal',  // normal | high
            'tactile'  => false,     // drop shadows + bold hover states
            'density'  => 'cozy',    // compact | cozy | roomy
            'accent'   => '',        // '' = none; else #rrggbb (highlights: tabs/menu-bar/focus/native)
            'palette'  => '',        // '' = default bg; else a background palette slug
            'font'     => '',        // '' = system default; else a font key
            'btnbg'    => '',        // '' = theme default; else #rrggbb (button background)
            'btntext'  => '',        // '' = white; else #rrggbb (button text)
            'go'       => '',        // semantic token → --lum-go     (success/confirm/active)
            'warn'     => '',        // semantic token → --lum-warn    (caution/pending)
            'danger'   => '',        // semantic token → --lum-danger  (destructive/stop)
            'surface'  => '',        // semantic token → --lum-surface (panel surface tint)
        ];
    }
}

if (!function_exists('admin_themes_fonts')) {
    /** Typeface choices — dependency-free system/web-safe stacks (no web-font loads). */
    function admin_themes_fonts(): array {
        return [
            'sans'    => ['label' => 'Humanist Sans',  'stack' => '"Segoe UI", Roboto, Helvetica, Arial, sans-serif'],
            'serif'   => ['label' => 'Serif',          'stack' => 'Georgia, "Times New Roman", serif'],
            'rounded' => ['label' => 'Rounded',        'stack' => 'ui-rounded, "Segoe UI", "Trebuchet MS", sans-serif'],
            'mono'    => ['label' => 'Monospace',      'stack' => 'ui-monospace, Menlo, Consolas, "Liberation Mono", monospace'],
            'legible' => ['label' => 'High Legibility', 'stack' => 'Verdana, Tahoma, "DejaVu Sans", sans-serif'],
        ];
    }
}

if (!function_exists('admin_themes_palettes')) {
    /** Background palettes — soft glassmorphic backdrops for the admin panel.
     *  (Keys preserved from the old identity palettes for back-compat.) The
     *  gradients themselves live in admin-display.css keyed by data-admin-palette. */
    function admin_themes_palettes(): array {
        return [
            'manila'   => ['label' => 'Warm Manila'],
            'slate'    => ['label' => 'Soft Slate'],
            'aqua'     => ['label' => 'Deep Aqua'],
            'oracle'   => ['label' => 'Oxblood'],
            'emerald'  => ['label' => 'Forest'],
            'amethyst' => ['label' => 'Twilight'],
            'ember'    => ['label' => 'Ember'],
        ];
    }
}

if (!function_exists('admin_themes_sanitize_display')) {
    /** Clamp/validate an arbitrary display payload to safe values. */
    function admin_themes_sanitize_display(array $d): array {
        $scale = isset($d['scale']) ? (float)$d['scale'] : 1.0;
        if ($scale < 0.85) $scale = 0.85;
        if ($scale > 1.6)  $scale = 1.6;
        $scale    = round($scale, 3);
        $contrast = (($d['contrast'] ?? '') === 'high') ? 'high' : 'normal';
        $tactile  = !empty($d['tactile']) && $d['tactile'] !== 'false';
        $density  = in_array($d['density'] ?? '', ['compact','cozy','roomy'], true) ? $d['density'] : 'cozy';
        $accent   = (!empty($d['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$d['accent']))
                    ? strtolower((string)$d['accent']) : '';
        $palette  = (!empty($d['palette']) && isset(admin_themes_palettes()[$d['palette']])) ? (string)$d['palette'] : '';
        $font     = (!empty($d['font']) && isset(admin_themes_fonts()[$d['font']])) ? (string)$d['font'] : '';
        $hex      = function ($v) { return (!empty($v) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v)) ? strtolower((string)$v) : ''; };
        $btnbg    = $hex($d['btnbg'] ?? '');
        $btntext  = $hex($d['btntext'] ?? '');
        $go       = $hex($d['go'] ?? '');
        $warn     = $hex($d['warn'] ?? '');
        $danger   = $hex($d['danger'] ?? '');
        $surface  = $hex($d['surface'] ?? '');
        return compact('scale', 'contrast', 'tactile', 'density', 'accent', 'palette', 'font', 'btnbg', 'btntext', 'go', 'warn', 'danger', 'surface');
    }
}

if (!function_exists('admin_themes_resolve_display')) {
    /** The current user's effective display settings (defaults ← site_default ← user). */
    function admin_themes_resolve_display(): array {
        $prefs = admin_themes_load_prefs();
        $key   = admin_themes_current_user_key();
        $raw   = $prefs['display']['users'][$key] ?? $prefs['display']['site_default'] ?? [];
        $d     = admin_themes_sanitize_display(is_array($raw) ? $raw : []);
        // accent and palette(=background) are INDEPENDENT — accent is the user's
        // own signature colour and persists on its own; the background palette no
        // longer overrides it.
        return $d;
    }
}
