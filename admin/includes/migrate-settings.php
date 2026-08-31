<?php
/**
 * @file /admin/includes/migrate-settings.php
 * @version 2025.09.05.one-shot
 * Reads legacy JSONs and writes/updates /admin/data/site-settings.json with merged values.
 * Safe to run multiple times. Does NOT delete legacy files.
 *
 * Visit: /admin/includes/migrate-settings.php
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__));
header('Content-Type: text/plain');

require_once __DIR__ . '/settings-loader.php';

$unifiedPath = SITE_ROOT . '/admin/data/site-settings.json';
$merged      = ri_load_site_settings();

$backup = $unifiedPath . '.' . date('Ymd_His') . '.bak';
if (is_file($unifiedPath)) {
    @copy($unifiedPath, $backup);
    echo "Backed up current site-settings.json to: $backup\n";
}

$ok = @file_put_contents($unifiedPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($ok === false) {
    http_response_code(500);
    echo "ERROR: Could not write $unifiedPath\n";
    exit;
}

echo "Unified settings written to: $unifiedPath\n";
echo "Done.\n";
?>