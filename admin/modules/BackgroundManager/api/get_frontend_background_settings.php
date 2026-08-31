<?php
/**
 * @file: /admin/ajax/get_frontend_background_settings.php
 * @version: 2025.08.07.FIXED
 * @description: The definitive frontend API endpoint. This version correctly parses the RGBA color string from the settings file into separate hex color and opacity values, ensuring the overlay applies correctly on the live site.
 * @change: Added RGBA parsing logic.
 */

header('Content-Type: application/json');

require_once dirname(__DIR__, 4) . '/utils.php';

$response = [
    'success' => false,
    'message' => 'Failed to load background settings.'
];

try {
    $bg_settings = loadJsonData('background_settings.json');
    if (!$bg_settings) {
        throw new Exception('background_settings.json could not be loaded or is empty.');
    }

    // --- FIX START: Parse RGBA color and opacity from the saved setting ---
    $overlay_color_hex = '#000000';
    $overlay_opacity = 0.5;
    if (isset($bg_settings['overlay']['color_rgba']) && preg_match('/rgba\((\d+),(\d+),(\d+),([\d\.]+)\)/', $bg_settings['overlay']['color_rgba'], $matches)) {
        $overlay_color_hex = sprintf("#%02x%02x%02x", $matches[1], $matches[2], $matches[3]);
        $overlay_opacity = floatval($matches[4]);
    }
    // --- FIX END ---

    $response = [
        'success' => true,
        'message' => 'Settings loaded successfully.',
        'background_mode' => $bg_settings['active_mode'] ?? 'none',
        'background_sizing' => $bg_settings['background_sizing'] ?? 'cover',
        'overlay_color' => $overlay_color_hex,
        'overlay_opacity' => $overlay_opacity,
        'slideshow_interval' => $bg_settings['slideshow']['duration'] ?? 5000,
        'selected_images' => [],
        'selected_videos' => []
    ];

    if ($response['background_mode'] === 'image' && !empty($bg_settings['single']['image_url'])) {
        $response['selected_images'][] = ['id' => 'bg_image', 'url' => $bg_settings['single']['image_url']];
    }
    elseif ($response['background_mode'] === 'slideshow' && !empty($bg_settings['slideshow']['playlist_name'])) {
        $all_slideshows = loadJsonData('background_slide_shows.json');
        if ($all_slideshows) {
            foreach ($all_slideshows as $playlist) {
                if (isset($playlist['name']) && $playlist['name'] === $bg_settings['slideshow']['playlist_name']) {
                    // Master settings duration is authoritative (set via Save Settings button).
                    // Playlist-level duration is only used as fallback if master has no value.
                    if (empty($bg_settings['slideshow']['duration']) && !empty($playlist['duration'])) {
                        $response['slideshow_interval'] = (int)$playlist['duration'];
                    }
                    // Legacy image-only playlists
                    if (!empty($playlist['images'])) {
                        foreach ($playlist['images'] as $index => $imageUrl) {
                            $response['selected_images'][] = ['id' => 'slide_' . $index, 'url' => $imageUrl];
                        }
                    }
                    // Combined playlists with slides array (images + videos)
                    if (!empty($playlist['slides'])) {
                        foreach ($playlist['slides'] as $index => $slide) {
                            $type = $slide['type'] ?? 'image';
                            $url  = $slide['path'] ?? $slide['source_path'] ?? '';
                            if ($type === 'video') {
                                $vtype = (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) ? 'youtube' : 'local_video';
                                $response['selected_videos'][] = ['id' => 'slide_v_' . $index, 'type' => $vtype, 'url' => $url];
                            } else {
                                $response['selected_images'][] = ['id' => 'slide_' . $index, 'url' => $url];
                            }
                        }
                    }
                    break;
                }
            }
        }
    }
    elseif ($response['background_mode'] === 'video' && isset($bg_settings['video'])) {
        $video_settings = $bg_settings['video'];
        $video_data = [ 'id' => 'bg_video', 'type' => $video_settings['source_type'] ?? 'local', 'url' => '' ];
        if ($video_data['type'] === 'youtube' && !empty($video_settings['youtube_id'])) {
            $video_data['url'] = 'https://www.youtube.com/watch?v=' . $video_settings['youtube_id'];
        } else {
            $video_data['url'] = $video_settings['url'] ?? '';
        }
        $response['selected_videos'][] = $video_data;
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>