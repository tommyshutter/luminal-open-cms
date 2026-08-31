<?php
/**
 * @file: /admin/debug_diagnostics.php
 * @description: "Tricorder" - Runs server-side diagnostics for the Media Manager.
 * @version: 1.0 (2025-07-31)
 */

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

function run_diagnostics() {
    $output = '<h3><i class="fas fa-microchip"></i> Tricorder Diagnostics</h3><div class="diag-grid">';
    $all_checks_ok = true;

    // Check 1: SITE_ROOT constant
    $output .= '<div><h4>Core Pathing</h4>';
    if (defined('SITE_ROOT') && !empty(SITE_ROOT)) {
        $output .= '<p class="diag-success"><i class="fas fa-check-circle"></i> <strong>SITE_ROOT:</strong> <br><code>' . htmlspecialchars(SITE_ROOT) . '</code></p>';
    } else {
        $output .= '<p class="diag-error"><i class="fas fa-times-circle"></i> <strong>SITE_ROOT:</strong> Not defined or empty! This is critical. Define it in <strong>utils.php</strong>.</p>';
        $all_checks_ok = false;
    }
    $output .= '</div>';


    // Check 2: Media Directory Existence & Permissions
    $output .= '<div><h4>Media Directories</h4>';
    $dirs_to_check = ['/images', '/videos'];
    foreach ($dirs_to_check as $dir) {
        $full_path = SITE_ROOT . $dir;
        $output .= "<p><strong>Checking:</strong> <code>" . htmlspecialchars($full_path) . "</code></p>";
        if (is_dir($full_path)) {
            $output .= '<p class="diag-success"><i class="fas fa-folder"></i> Exists.</p>';
            if (is_readable($full_path) && is_writable($full_path)) {
                $output .= '<p class="diag-success"><i class="fas fa-check-circle"></i> Readable & Writable.</p>';
            } else {
                $output .= '<p class="diag-error"><i class="fas fa-times-circle"></i> Permissions Issue! Server needs read/write access. Check folder permissions (e.g., `chmod 775`).</p>';
                $all_checks_ok = false;
            }
        } else {
            $output .= '<p class="diag-error"><i class="fas fa-times-circle"></i> Directory does not exist!</p>';
            $all_checks_ok = false;
        }
    }
    $output .= '</div>';
    
    // Check 3: AJAX Handler Existence
    $output .= '<div><h4>API Endpoints</h4>';
    $files_to_check = ['/admin/modules/FileManager/api/scan_folders.php', '/admin/modules/FileManager/api/handle_media_actions.php', '/admin/modules/FileManager/api/upload_media.php'];
    foreach ($files_to_check as $file) {
        $full_path = SITE_ROOT . $file;
        if (file_exists($full_path)) {
            $output .= '<p class="diag-success"><i class="fas fa-file-code"></i> Found <code>' . htmlspecialchars($file) . '</code></p>';
        } else {
            $output .= '<p class="diag-error"><i class="fas fa-times-circle"></i> Missing <code>' . htmlspecialchars($file) . '</code></p>';
            $all_checks_ok = false;
        }
    }
    $output .= '</div>';
    
    $output .= '</div>'; // End diag-grid
    
    if($all_checks_ok) {
         $output .= '<p class="diag-summary diag-success"><i class="fas fa-shield-alt"></i> All systems nominal. If issues persist, check the browser\'s developer console (F12) for JavaScript errors.</p>';
    } else {
         $output .= '<p class="diag-summary diag-error"><i class="fas fa-exclamation-triangle"></i> Found critical errors! Please resolve the issues marked in red before proceeding.</p>';
    }

    return $output;
}
?>