<?php
/**
 * Diagnostic Tool for the Hero Panel
 * This file helps debug why the panel may not be rendering on the live site.
 *
 * @file-location /panels/diag_hero.php
 * @version 2025.07.12.1930
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo '<!DOCTYPE html><html lang="en"><head><title>Hero Panel Diagnostics</title><style>body{font-family:monospace; background:#1e1e1e; color:#d4d4d4; padding:20px;} h1,h2{color:#569cd6;} .success{color:#4ec9b0;} .fail{color:#f44747;}</style></head><body>';
echo '<h1>Hero Panel Diagnostics</h1>';

// --- Test 1: Check Server Document Root ---
echo '<h2>1. Server Path Check</h2>';
$doc_root = $_SERVER['DOCUMENT_ROOT'];
echo "Calculated Document Root: <strong>{$doc_root}</strong><br>";

// --- Test 2: Check if utils.php is reachable ---
echo '<h2>2. Utility File Check</h2>';
$utils_path = $doc_root . '/utils.php';
echo "Attempting to find utils.php at: {$utils_path}<br>";
if (file_exists($utils_path)) {
    echo '<strong class="success">SUCCESS:</strong> utils.php found.<br>';
    require_once $utils_path;
} else {
    echo '<strong class="fail">FAILURE:</strong> utils.php NOT FOUND at this path. This is a critical error.<br>';
}

// --- Test 3: Check if loadJsonData function exists ---
echo '<h2>3. Function Check</h2>';
if (function_exists('loadJsonData')) {
    echo '<strong class="success">SUCCESS:</strong> loadJsonData() function is available.<br>';
} else {
    echo '<strong class="fail">FAILURE:</strong> loadJsonData() function does not exist. Check utils.php.<br>';
}

// --- Test 4: Check if settings file is readable ---
echo '<h2>4. Settings File Check</h2>';
$settings_path = $doc_root . '/admin/data/panel-right-column-content.json';
echo "Attempting to read settings file at: {$settings_path}<br>";
if (file_exists($settings_path) && is_readable($settings_path)) {
    echo '<strong class="success">SUCCESS:</strong> Settings file is readable.<br>';
    $settings_content = file_get_contents($settings_path);
    $settings_data = json_decode($settings_content, true);

    echo '<h3>Raw Settings Data:</h3>';
    echo '<pre>';
    print_r($settings_data);
    echo '</pre>';

} else {
     echo '<strong class="fail">FAILURE:</strong> Could not read the settings JSON file. Check path and permissions.<br>';
}

// --- Test 5: Render the actual panel ---
echo '<h2>5. Final Panel Render</h2>';
echo '<div style="border:2px solid #569cd6; padding:10px; margin-top:20px;">';
echo '<h3>Attempting to include panel-hero.php:</h3>';
include __DIR__ . '/panel-hero.php';
echo '</div>';

echo '</body></html>';
?>