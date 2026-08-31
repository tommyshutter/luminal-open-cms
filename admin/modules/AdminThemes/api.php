<?php
/**
 * @file admin/modules/AdminThemes/api.php
 * @desc Per-user admin theme persistence.
 *   GET  ?action=get                      -> current state for this user
 *   POST  action=set&theme=<slug>         -> save this user's theme
 *   POST  action=set_default&theme=<slug> -> (admin) set site-wide default
 * Data: admin/data/AdminThemes/prefs.json  { site_default, users:{uid:slug} }
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();
require_once __DIR__ . '/theme_lib.inc.php';

header('Content-Type: application/json');

function at_out(array $a): void { echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }

$action = $_REQUEST['action'] ?? 'get';
$avail  = admin_themes_available();
$key    = admin_themes_current_user_key();

if ($action === 'get') {
    $prefs = admin_themes_load_prefs();
    at_out([
        'ok'           => true,
        'active'       => admin_themes_resolve(),
        'site_default' => $prefs['site_default'] ?? 'luminal',
        'userKey'      => $key,
        'available'    => array_map(
            fn($k, $m) => ['slug' => $k, 'label' => $m['label'], 'group' => $m['group']],
            array_keys($avail), array_values($avail)
        ),
        'display'      => admin_themes_resolve_display(),
        'palettes'     => admin_themes_palettes(),
    ]);
}

if ($action === 'set_display') {
    // JS posts the full display state each time; sanitize handles all bounds.
    $prefs = admin_themes_load_prefs();
    $clean = admin_themes_sanitize_display([
        'scale'    => $_REQUEST['scale']    ?? 1.0,
        'contrast' => $_REQUEST['contrast'] ?? 'normal',
        'tactile'  => $_REQUEST['tactile']  ?? '0',
        'density'  => $_REQUEST['density']  ?? 'cozy',
        'accent'   => $_REQUEST['accent']   ?? '',
        'palette'  => $_REQUEST['palette']  ?? '',
        'font'     => $_REQUEST['font']     ?? '',
        'btnbg'    => $_REQUEST['btnbg']    ?? '',
        'btntext'  => $_REQUEST['btntext']  ?? '',
        'go'       => $_REQUEST['go']       ?? '',
        'warn'     => $_REQUEST['warn']     ?? '',
        'danger'   => $_REQUEST['danger']   ?? '',
        'surface'  => $_REQUEST['surface']  ?? '',
    ]);
    if (!isset($prefs['display']) || !is_array($prefs['display'])) $prefs['display'] = ['users' => []];
    if (!isset($prefs['display']['users']) || !is_array($prefs['display']['users'])) $prefs['display']['users'] = [];
    $prefs['display']['users'][$key] = $clean;
    $ok = admin_themes_save_prefs($prefs);
    at_out(['ok' => $ok, 'display' => admin_themes_resolve_display()]);
}

if ($action === 'set_global') {
    // Superadmin only — writes the site_default display layer, which seeds every
    // user who has no personal override (resolve_display: user ?? site_default ?? base).
    $isAdmin = in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true) || !empty($_SESSION['admin_logged_in']);
    if (!$isAdmin) { http_response_code(403); at_out(['ok' => false, 'error' => 'forbidden']); }
    $prefs = admin_themes_load_prefs();
    $clean = admin_themes_sanitize_display([
        'accent'  => $_REQUEST['accent']  ?? '',
        'go'      => $_REQUEST['go']      ?? '',
        'warn'    => $_REQUEST['warn']    ?? '',
        'danger'  => $_REQUEST['danger']  ?? '',
        'surface' => $_REQUEST['surface'] ?? '',
    ]);
    // The global layer only governs these colour fields — never stamp per-user
    // ergonomics (scale/density/…) onto everyone. Empty = clear that key.
    $sd = (isset($prefs['display']['site_default']) && is_array($prefs['display']['site_default']))
        ? $prefs['display']['site_default'] : [];
    foreach (['accent', 'go', 'warn', 'danger', 'surface'] as $k) {
        if ($clean[$k] !== '') $sd[$k] = $clean[$k]; else unset($sd[$k]);
    }
    if (!isset($prefs['display']) || !is_array($prefs['display'])) $prefs['display'] = ['users' => []];
    $prefs['display']['site_default'] = $sd;
    $ok = admin_themes_save_prefs($prefs);
    at_out(['ok' => $ok, 'site_default' => $sd]);
}

if ($action === 'save_custom_theme') {
    // Snapshot the current display config as a named, reusable site theme card.
    $isAdmin = in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true) || !empty($_SESSION['admin_logged_in']);
    if (!$isAdmin) { http_response_code(403); at_out(['ok' => false, 'error' => 'forbidden']); }
    $name = trim((string)($_REQUEST['name'] ?? ''));
    if ($name === '') at_out(['ok' => false, 'error' => 'name required']);
    $name = mb_substr($name, 0, 60);
    $base = (string)($_REQUEST['base'] ?? 'luminal');
    if (!isset($avail[$base])) $base = 'luminal';
    $display = admin_themes_sanitize_display([
        'scale'    => $_REQUEST['scale']    ?? 1.0,   'contrast' => $_REQUEST['contrast'] ?? 'normal',
        'tactile'  => $_REQUEST['tactile']  ?? '0',   'density'  => $_REQUEST['density']  ?? 'cozy',
        'accent'   => $_REQUEST['accent']   ?? '',    'palette'  => $_REQUEST['palette']  ?? '',
        'font'     => $_REQUEST['font']     ?? '',    'btnbg'    => $_REQUEST['btnbg']    ?? '',
        'btntext'  => $_REQUEST['btntext']  ?? '',    'go'       => $_REQUEST['go']       ?? '',
        'warn'     => $_REQUEST['warn']     ?? '',    'danger'   => $_REQUEST['danger']   ?? '',
        'surface'  => $_REQUEST['surface']  ?? '',
    ]);
    $prefs = admin_themes_load_prefs();
    if (!isset($prefs['custom_themes']) || !is_array($prefs['custom_themes'])) $prefs['custom_themes'] = [];
    $id = 'ct_' . bin2hex(random_bytes(6));
    $prefs['custom_themes'][$id] = ['name' => $name, 'base' => $base, 'display' => $display, 'created' => time(), 'by' => $key];
    $ok = admin_themes_save_prefs($prefs);
    at_out(['ok' => $ok, 'id' => $id, 'name' => $name]);
}

if ($action === 'delete_custom_theme') {
    $isAdmin = in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true) || !empty($_SESSION['admin_logged_in']);
    if (!$isAdmin) { http_response_code(403); at_out(['ok' => false, 'error' => 'forbidden']); }
    $id = (string)($_REQUEST['id'] ?? '');
    $prefs = admin_themes_load_prefs();
    if (isset($prefs['custom_themes'][$id])) {
        unset($prefs['custom_themes'][$id]);
        at_out(['ok' => admin_themes_save_prefs($prefs)]);
    }
    at_out(['ok' => false, 'error' => 'not found']);
}

if ($action === 'set') {
    $theme = (string)($_POST['theme'] ?? $_REQUEST['theme'] ?? '');
    if (!isset($avail[$theme])) at_out(['ok' => false, 'error' => 'unknown_theme']);
    $prefs = admin_themes_load_prefs();
    $prefs['users'][$key] = $theme;
    $ok = admin_themes_save_prefs($prefs);
    at_out(['ok' => $ok, 'active' => $theme]);
}

if ($action === 'set_default') {
    $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin') || !empty($_SESSION['admin_logged_in']);
    if (!$isAdmin) at_out(['ok' => false, 'error' => 'forbidden']);
    $theme = (string)($_POST['theme'] ?? $_REQUEST['theme'] ?? '');
    if (!isset($avail[$theme])) at_out(['ok' => false, 'error' => 'unknown_theme']);
    $prefs = admin_themes_load_prefs();
    $prefs['site_default'] = $theme;
    $ok = admin_themes_save_prefs($prefs);
    at_out(['ok' => $ok, 'site_default' => $theme]);
}

at_out(['ok' => false, 'error' => 'unknown_action']);
