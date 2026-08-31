<?php
/**
 * ----------------------------------------------------------------------------------------
 * @appname     Site Panels
 * @file        SITE-ROOT/panels/load-panel.php
 * @version     2025.09.05.12.05.00-EST
 * @author      ChatGPT
 * @license     Business Source License
 * @description Secure, minimal panel loader. Includes a named panel and passes args.
 *              Supported: ?panel=pdf-gallery&name=<slug>
 *              Extend by adding other panel-*.php files and mapping them below.
 * ----------------------------------------------------------------------------------------
 */

declare(strict_types=1);
define('SITE_ROOT', realpath(__DIR__ . '/..'));

/**
 * Basic allowlist mapping panel key -> filename
 * Add new panels as needed; filenames must live in this /panels/ directory.
 */
$PANEL_MAP = [
  'pdf-gallery' => 'panel-pdf-gallery.php',
  'image-gallery' => 'panel-image-gallery.php', // example
  'video-gallery' => 'panel-video-gallery.php', // example
];

function safe_key(string $s): string { return preg_replace('/[^a-z0-9._-]/i', '', $s); }

$panelKey = isset($_GET['panel']) ? safe_key((string)$_GET['panel']) : '';
if ($panelKey === '' || !isset($PANEL_MAP[$panelKey])) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="render-error" style="color:#f66">Invalid or missing panel.</div>';
    exit;
}

// Collect args you intend to pass to the panel
$panelArgs = [
    'name' => isset($_GET['name']) ? safe_key((string)$_GET['name']) : '',
    // add more keys here if needed, always validated/sanitized
];

// Expose to the panel in a global to avoid refactoring existing includes
$GLOBALS['RI_PANEL_ARGS'] = $panelArgs;

$panelFile = __DIR__ . '/' . $PANEL_MAP[$panelKey];
if (!is_file($panelFile)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="render-error" style="color:#f66">Panel file not found.</div>';
    exit;
}

// Panels are expected to echo their HTML 
header('Content-Type: text/html; charset=UTF-8');
include $panelFile;
?>