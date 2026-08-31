<?php

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
/**
 * /admin/ajax/save_background_settings.php
 *
 * @description   Handles saving the master background settings.
 * UPDATED: This version implements a robust merging logic that prevents
 * settings for inactive modes from being erased. This is the definitive
 * fix for the "settings don't stick" issue.
 * @version       2025.07.30.7
 */

header('Content-Type: application/json');

// Define the path to the settings file securely.
$settings_file = dirname(__FILE__, 5) . '/admin/data/background_settings.json';
$response = ['success' => false, 'message' => 'Invalid request.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($payload) && isset($payload['active_mode'])) {
        
        // Step 1: Load the complete, existing settings from the file.
        $current_settings = [];
        if (file_exists($settings_file)) {
            $current_settings = json_decode(file_get_contents($settings_file), true);
            if (!is_array($current_settings)) {
                $current_settings = []; // Fallback if file is corrupt
            }
        }

        // Step 2: Update the top-level settings from the payload.
        $current_settings['active_mode'] = $payload['active_mode'];
        $current_settings['background_sizing'] = $payload['background_sizing'];
        if (isset($payload['overlay'])) {
            $current_settings['overlay'] = $payload['overlay'];
        }

        // Step 3: **Crucially, only update the section for the ACTIVE mode.**
        switch ($payload['active_mode']) {
            case 'image':
                if (isset($payload['single'])) {
                    $current_settings['single'] = $payload['single'];
                }
                break;
            case 'video':
                if (isset($payload['video'])) {
                    $current_settings['video'] = $payload['video'];
                }
                break;
            case 'slideshow':
                if (isset($payload['slideshow'])) {
                    $current_settings['slideshow'] = $payload['slideshow'];
                }
                break;
        }

        // Step 4: Write the carefully updated settings back to the file.
        if (file_put_contents($settings_file, json_encode($current_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            $response['success'] = true;
            $response['message'] = 'Master settings have been saved successfully!';
            // Return the new, correct state to the browser.
            $response['new_settings'] = $current_settings;
        } else {
            $response['message'] = 'ERROR: Could not write to settings file. Please check server permissions for /admin/data/';
        }
    } else {
        $response['message'] = 'ERROR: Invalid data sent to server.';
    }
}

echo json_encode($response);
?>