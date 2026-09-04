<?php
declare(strict_types=1);
/**
 * @file /admin/includes/admin_header.php
 * @desc Canonical admin header: domain badge, menu include, shared toaster.
 *       Include this once per admin page (immediately after <body> or right
 *       after </head> if that’s your convention).
 
 
 */
//if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__));

require_once __DIR__ . '/auth.php';
requireAuth();

// Lightweight perm fixer — ensures admin/data/ tree is writable by web server
// Runs once per session to avoid repeated filesystem scans
if (empty($_SESSION['_perms_checked'])) {
    $dataRoot = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__)) . '/admin/data';
    if (is_dir($dataRoot) && !is_writable($dataRoot)) @chmod($dataRoot, 0777);
    // One level deep — covers all module data dirs
    foreach (glob($dataRoot . '/*', GLOB_ONLYDIR) as $d) {
        if (!is_writable($d)) @chmod($d, 0777);
        // Two levels for nested dirs (products/, leads/, etc.)
        foreach (glob($d . '/*', GLOB_ONLYDIR) as $sd) {
            if (!is_writable($sd)) @chmod($sd, 0777);
        }
    }
    $_SESSION['_perms_checked'] = time();
}

function _eh_scheme(): string {
  $https = $_SERVER['HTTPS'] ?? '';
  $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  if ($https && strtolower($https) !== 'off') return 'https';
  if ($proto) return strtolower($proto);
  return 'http';
}

// Admin pages must not be cached by the browser — stale JS/HTML can show wrong toggle
// state after we push module updates. Content/data pages can still cache normally; this
// only runs inside the admin shell.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$ADMIN_HOST   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

$ADMIN_ORIGIN = _eh_scheme() . '://' . $ADMIN_HOST;

$reqUri   = $_SERVER['REQUEST_URI'] ?? '';
$basename = strtolower(basename(parse_url($reqUri, PHP_URL_PATH) ?: ''));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <meta charset="UTF-8">
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?php echo htmlspecialchars($ADMIN_HOST, ENT_QUOTES); ?>  - Admin Utils </title>
    
     <?php
       /* These two shipped as bare hrefs with no version query, so a browser held
          whatever it cached the first time it saw them. Every global admin CSS
          change was invisible to returning users until a manual hard refresh --
          including rules that had never existed before, which is the worst case:
          the page looks unstyled and the stylesheet looks broken. Module assets
          already went through sc_asset(); these did not.
          Guarded two ways: admin_header.php is included by pages that may not
          have loaded site_config.php yet, and a stylesheet that fails to link is
          a far worse outcome than one that is stale. */
       $__css = function (string $p): string {
           return (function_exists('sc_asset') && defined('SITE_ROOT')) ? sc_asset($p) : $p;
       };
     ?>
     <link rel="stylesheet" href="<?= htmlspecialchars($__css('/admin/css/admin-styles.css'), ENT_QUOTES) ?>">
     
     <link rel="stylesheet" href="<?= htmlspecialchars($__css('/admin/css/admin_menu.css'), ENT_QUOTES) ?>">

   <?php /* AdminThemes — per-user admin theming. Guarded so sites without the module are unaffected. */
   $__at_hook = SITE_ROOT . '/admin/modules/AdminThemes/theme_head.inc.php';
   if (is_file($__at_hook)) include $__at_hook; ?>
</head>
<body>

<?php include SITE_ROOT . '/admin/includes/admin-bg-apply.inc.php'; ?>
               
    <div class="admin-container">
                   
     <?php include SITE_ROOT . '/admin/admin_menu.php'; ?>
     
               <div id="admin-messages" style="display:none;"></div>
               <div id="toast-notification" aria-live="polite"></div>
               <div class="content-area">

