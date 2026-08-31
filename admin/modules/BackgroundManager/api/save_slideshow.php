<?php
/**
 * @file        /admin/ajax/save_slideshow.php
 * @version     2025.08.05.DEFINITIVE
 * @description The single, definitive script for saving and updating slideshow playlists.
 * This consolidates all previous slideshow save logic.
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

if ($action === 'delete') {
    $initial_count = count($playlists);
    $playlists = array_values(array_filter($playlists, function($p) use ($playlist_name) {
        return isset($p['name']) && $p['name'] !== $playlist_name;
    }));
    if (count($playlists) < $initial_count) {
        if (saveJsonData('background_slide_shows.json', $playlists)) {
            echo json_encode(['success' => true, 'message' => "Playlist '{$playlist_name}' deleted successfully."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to write to the playlist file after deletion.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Playlist '{$playlist_name}' not found for deletion."]);
    }
} else { // Default action is 'save'
    if (!isset($input['images'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data provided. Missing images for save action.']);
        exit;
    }
    
    $playlist_exists = false;
    foreach ($playlists as &$playlist) {
        if (isset($playlist['name']) && $playlist['name'] === $playlist_name) {
            $playlist['images'] = $input['images'];
            $playlist_exists = true;
            break;
        }
    }
    unset($playlist);

    if (!$playlist_exists) {
        $playlists[] = [
            'name' => $playlist_name,
            'images' => $input['images']
        ];
    }

    if (saveJsonData('background_slide_shows.json', $playlists)) {
        echo json_encode(['success' => true, 'message' => "Playlist '{$playlist_name}' saved successfully."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to write to the playlist file. Check server permissions.']);
    }
}
?>