<?php
/**
 * AJAX handler for deleting a slideshow playlist.
 *
 * @version      2025.07.10.1530.EDT (New File)
 * @filelocation /admin/ajax/delete_slideshow_playlist.php
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];
$playlists_file = SITE_ROOT . '/admin/data/background_slide_shows.json';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Only POST requests are allowed.');
    }

    $playlist_id_to_delete = filter_var($_POST['id'] ?? '', FILTER_SANITIZE_STRING);

    if (empty($playlist_id_to_delete)) {
        throw new Exception('No playlist ID provided for deletion.');
    }

    // Load existing playlists
    $playlists = loadJsonData($playlists_file);
    if ($playlists === null) {
        throw new Exception('Could not read playlist data for deletion.');
    }

    $updated_playlists = [];
    $deleted_count = 0;
    foreach ($playlists as $playlist) {
        if (($playlist['id'] ?? '') === $playlist_id_to_delete) {
            $deleted_count++;
        } else {
            $updated_playlists[] = $playlist;
        }
    }

    if ($deleted_count > 0) {
        if (saveJsonData($playlists_file, $updated_playlists)) {
            $response = ['success' => true, 'message' => "Playlist deleted successfully."];
        } else {
            throw new Exception('Failed to save updated playlist data after deletion. Check file permissions.');
        }
    } else {
        $response = ['success' => false, 'message' => 'Playlist not found.'];
    }

} catch (Exception $e) {
    $response['message'] = 'Error deleting playlist: ' . $e->getMessage();
    error_log("DELETE_PLAYLIST_FATAL: " . $e->getMessage());
}

echo json_encode($response);
exit();