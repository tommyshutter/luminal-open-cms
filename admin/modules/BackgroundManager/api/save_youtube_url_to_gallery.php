<?php

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
/**
 * /admin/ajax/save_youtube_url_to_gallery.php
 *
 * @description   NEW AJAX HANDLER. Called when the "Add to Gallery" button for
 * a YouTube URL is clicked. It adds the video to the videos.json file.
 * @version       2025.07.30.9
 */

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid request.'];
$videos_file = dirname(__FILE__, 5) . '/admin/data/videos.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $url = $data['youtube_url'] ?? null;

    if (empty($url)) {
        $response['message'] = 'No YouTube URL provided.';
        echo json_encode($response);
        exit;
    }

    // Extract Video ID from URL
    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/', $url, $matches);
    $video_id = $matches[1] ?? null;

    if (!$video_id) {
        $response['message'] = 'Could not extract a valid YouTube Video ID from the URL.';
        echo json_encode($response);
        exit;
    }

    // Load existing videos
    $videos = [];
    if (file_exists($videos_file)) {
        $videos = json_decode(file_get_contents($videos_file), true);
        if (!is_array($videos)) {
            $videos = [];
        }
    }

    // Check if this video already exists
    foreach ($videos as $video) {
        if (strpos($video['path'], $video_id) !== false) {
            $response['success'] = true; // Technically success, as it exists
            $response['message'] = 'This video is already in your gallery.';
            echo json_encode($response);
            exit;
        }
    }

    // Add the new video
    $videos[] = [
        'path'   => 'https://www.youtube.com/watch?v=' . $video_id,
        'thumb'  => 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg',
        'title'  => 'YouTube Video (' . $video_id . ')', // Fetching real title is complex, use ID
        'source' => 'youtube',
        'mtime'  => time(),
    ];

    // Save the updated list
    if (file_put_contents($videos_file, json_encode($videos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        $response['success'] = true;
        $response['message'] = 'YouTube video successfully added to your gallery!';
    } else {
        $response['message'] = 'Error: Could not write to videos.json. Check server permissions.';
    }
}

echo json_encode($response);
?>