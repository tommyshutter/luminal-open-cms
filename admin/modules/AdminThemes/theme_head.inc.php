<?php
/**
 * @file admin/modules/AdminThemes/theme_head.inc.php
 * @desc Core theming hook. Included (guarded) inside <head> by admin_header.php.
 *       Resolves the logged-in user's admin theme server-side and emits:
 *         1. an inline pre-paint script setting <html data-admin-theme> (no FOUC)
 *         2. the shared chrome stylesheet + the active skin stylesheet
 *         3. the chrome builder script
 *
 *       Default theme is "luminal" (the existing look) which loads NO skin file —
 *       so an absent/garbled pref can never break the admin panel.
 */
require_once __DIR__ . '/theme_lib.inc.php';

$__at_theme = admin_themes_resolve();
$__at_avail = admin_themes_available();
$__at_disp  = admin_themes_resolve_display();
$__at_base  = '/admin/modules/AdminThemes';
// mtime-based cache bust — any edit to the JS/CSS/this file forces fresh assets.
$__at_v     = (string)max(
    (int)@filemtime(__DIR__ . '/js/admin-themes.js'),
    (int)@filemtime(__DIR__ . '/css/admin-display.css'),
    (int)@filemtime(__DIR__ . '/css/admin-themes.css'),
    (int)@filemtime(__FILE__)
);
// Embed JSON for a <script> context: JSON_HEX_TAG/AMP neutralise </script> and
// entity breakout WITHOUT htmlspecialchars (entities are NOT decoded inside a
// <script>, so htmlspecialchars here produced invalid JS — the v1.0 bug).
$__at_json  = json_encode([
    'active'    => $__at_theme,
    'available' => array_map(
        fn($k, $m) => ['slug' => $k, 'label' => $m['label'], 'group' => $m['group']],
        array_keys($__at_avail), array_values($__at_avail)
    ),
    'base'      => $__at_base,
    'host'      => $_SERVER['HTTP_HOST'] ?? '',
    'userKey'   => admin_themes_current_user_key(),
    'display'   => $__at_disp,
    'palettes'  => admin_themes_palettes(),
    'fonts'     => admin_themes_fonts(),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!-- AdminThemes: pre-paint theme + display/accessibility attributes (FOUC guard) -->
<script>(function(){var e=document.documentElement,d=<?= json_encode($__at_disp, JSON_UNESCAPED_SLASHES) ?>;
e.setAttribute('data-admin-theme', <?= json_encode($__at_theme) ?>);
e.setAttribute('data-admin-contrast', d.contrast||'normal');
e.setAttribute('data-admin-tactile', d.tactile?'on':'off');
e.setAttribute('data-admin-density', d.density||'cozy');
e.setAttribute('data-admin-palette', d.palette||'');
e.setAttribute('data-admin-accent', d.accent?'on':'off');
e.setAttribute('data-admin-font', d.font||'');
e.setAttribute('data-admin-btn', d.btnbg?'on':'off');
e.style.setProperty('--admin-fscale', d.scale||1);
if(d.accent){e.style.setProperty('--admin-accent', d.accent);}
if(d.btnbg){e.style.setProperty('--admin-btnbg', d.btnbg);}
e.style.setProperty('--admin-btntext', d.btntext||'#ffffff');
if(d.go){e.style.setProperty('--lum-go', d.go);}
if(d.warn){e.style.setProperty('--lum-warn', d.warn);}
if(d.danger){e.style.setProperty('--lum-danger', d.danger);}
if(d.surface){e.style.setProperty('--lum-surface', d.surface);}})();</script>
<link rel="stylesheet" href="<?= $__at_base ?>/css/admin-themes.css?v=<?= $__at_v ?>">
<?php if (!empty($__at_avail[$__at_theme]['css'])): ?>
<link rel="stylesheet" href="<?= $__at_base ?>/themes/<?= htmlspecialchars($__at_theme, ENT_QUOTES) ?>.css?v=<?= $__at_v ?>">
<?php endif; ?>
<!-- display/accessibility loads LAST so per-user prefs win over theme styling -->
<link rel="stylesheet" href="<?= $__at_base ?>/css/admin-display.css?v=<?= $__at_v ?>">
<script>window.AdminThemes = <?= $__at_json ?>;</script>
<script defer src="<?= $__at_base ?>/js/admin-themes.js?v=<?= $__at_v ?>"></script>
