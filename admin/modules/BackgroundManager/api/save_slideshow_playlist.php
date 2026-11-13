<?php
/**
 * @file        /admin/ajax/save_slideshow_playlist.php
 * @version     2025.08.05.FINAL
 * @description The single, definitive script for saving, updating, and deleting slideshow playlists.
 * @change: Added logic to handle the 'delete' action and save the 'duration' setting.
 */
header('Content-Type: application/json');

// Bootstrap necessary files
require_once dirname(__DIR__, 4) . '/utils.php';
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();

// Get the POST data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input) || !isset($input['name'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data. Missing playlist name.']);
    exit;
}

$playlist_name = trim($input['name']);

if (empty($playlist_name)) {
    echo json_encode(['success' => false, 'message' => 'Playlist name cannot be empty.']);
    exit;
}

$action = $input['action'] ?? 'save';
$playlists = loadJsonData('background_slide_shows.json') ?? [];

// --- THIS IS THE NEW DELETE LOGIC ---
if ($action === 'delete') {
    $initial_count = count($playlists);
    // Filter out the playlist that matches the name to be deleted.
    $playlists = array_values(array_filter($playlists, function($p) use ($playlist_name) {
        return isset($p['name']) && $p['name'] !== $playlist_name;
    }));

    if (count($playlists) < $initial_count) { // Check if a playlist was actually removed
        if (saveJsonData('background_slide_shows.json', $playlists)) {
            echo json_encode(['success' => true, 'message' => "Playlist '{$playlist_name}' deleted successfully."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to write to the playlist file after deletion.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Playlist '{$playlist_name}' not found for deletion."]);
    }
    exit; // Stop execution after handling delete
}

// --- EXISTING SAVE/UPDATE LOGIC ---
$playlist_exists = false;
$new_data = [
    'images'   => $input['images'] ?? [],
    'duration' => $input['duration'] ?? 5000,
];
if (isset($input['slides']) && is_array($input['slides'])) {
    $new_data['slides'] = $input['slides'];
}

foreach ($playlists as &$playlist) {
    if (isset($playlist['name']) && $playlist['name'] === $playlist_name) {
        $playlist = array_merge($playlist, $new_data);
        $playlist_exists = true;
        break;
    }
}
unset($playlist);

if (!$playlist_exists) {
    $playlists[] = array_merge(['name' => $playlist_name], $new_data);
}

// Save the updated playlists back to the file
if (saveJsonData('background_slide_shows.json', $playlists)) {
    echo json_encode(['success' => true, 'message' => "Playlist '{$playlist_name}' saved successfully."]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write to the playlist file. Check server permissions.']);
}
?>