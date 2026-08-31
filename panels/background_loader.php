<?php
/**
 * @file-location SITE-ROOT/panels/background_loader.php
 * @version 2025.08.05.1
 * @description Renders the site's background based on the centralized $settings object.
 * @change: Corrected local video source from 'path' to 'url' to match the saved data structure.
 */

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..'));
}

$bg_settings = $settings->get('background');

if (!$bg_settings) {
    return;
}

$active_mode = $bg_settings['active_mode'] ?? 'none';
$sizing_class = 'bg-' . ($bg_settings['background_sizing'] ?? 'cover');
$site_root_url = defined('SITE_ROOT_URL') ? SITE_ROOT_URL : '';

switch ($active_mode) {
    case 'image':
        $image_path = $bg_settings['single']['image_url'] ?? '';
        if (!empty($image_path)) {
            echo "<div class=\"background-media single-image {$sizing_class}\" style=\"background-image: url('{$site_root_url}{$image_path}');\"></div>";
        }
        break;

    case 'video':
        $video_settings = $bg_settings['video'] ?? [];
        $source_type = $video_settings['source_type'] ?? 'local';

        if ($source_type === 'youtube' && !empty($video_settings['youtube_id'])) {
            echo '<div id="youtube-background-player" class="background-media" data-youtube-id="' . htmlspecialchars($video_settings['youtube_id']) . '"></div>';
        } else {
            // --- THIS IS THE FIX ---
            // The video path is stored in the 'url' key, not 'path'.
            $video_path = $video_settings['url'] ?? ''; 
            $poster_path = $video_settings['poster'] ?? '';
            if (!empty($video_path)) {
                echo "<video class=\"background-media video-background\" poster=\"{$site_root_url}/{$poster_path}\" playsinline autoplay muted loop><source src=\"{$site_root_url}{$video_path}\" type=\"video/mp4\"></video>";
            }
        }
        break;

    case 'slideshow':
        $slideshow_name = $bg_settings['slideshow']['playlist_name'] ?? '';
        $transition = $bg_settings['slideshow']['transition'] ?? 'fade';
        $duration = $bg_settings['slideshow']['duration'] ?? 5000;

        $all_slideshows = function_exists('loadJsonData') ? loadJsonData('background_slide_shows.json') : null;
        $images = [];

        if ($all_slideshows && is_array($all_slideshows)) {
            foreach ($all_slideshows as $playlist) {
                if (isset($playlist['name']) && $playlist['name'] === $slideshow_name) {
                    $images = $playlist['images'] ?? [];
                    break;
                }
            }
        }

        if (!empty($images)) {
            echo "<div class=\"slideshow-background\" data-transition=\"{$transition}\" data-duration=\"{$duration}\">";
            foreach ($images as $image) {
                echo "<div class=\"slide {$sizing_class}\" style=\"background-image: url('{$site_root_url}{$image}');\"></div>";
            }
            echo "</div>";
        }
        break;
}

// Overlay logic
if (isset($bg_settings['overlay']['enabled']) && $bg_settings['overlay']['enabled']) {
    $overlay_color = $settings->hexToRgba($bg_settings['overlay']['color'] ?? '#000000', $bg_settings['overlay']['opacity'] ?? 0.5);
    echo "<div class=\"background-overlay-style\" style=\"background-color: {$overlay_color};\"></div>";
}
?>