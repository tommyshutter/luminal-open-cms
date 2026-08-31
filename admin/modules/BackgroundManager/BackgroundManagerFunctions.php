<?php
/**
 * /admin/background_mgr/background_mgr_helper.php
 *
 * @version       2025.07.30.25 (Resilient)
 * @description   This helper now collects all errors during media scanning and
 * passes them to the front-end for display, ensuring the page never crashes.
 */

require_once __DIR__ . '/includes/image_optimizer.php';

// This is our new error log for the request.
$background_manager_errors = [];

function bgm_get_all_media($return_stats = false) {
    global $background_manager_errors; // Make the error log accessible
    
    $media = ['images' => [], 'videos' => []];
    $stats = [
        'images_found' => 0, 'images_cached' => 0,
        'videos_found' => 0, 'videos_thumbnailed' => 0,
        'gd_installed' => function_exists('imagecreatetruecolor')
    ];
    $base_dir = dirname(__FILE__, 4);
    $image_dir_path = $base_dir . '/media/images/backgrounds/';
    $cache_dir_path = $image_dir_path . '.cache/';
    $video_dir_path = $base_dir . '/media/videos/backgrounds/';
    $video_cache_dir = $video_dir_path . '.cache/';
    $videos_json_file = $base_dir . '/admin/data/videos.json';
    
    // --- 1. LOCAL IMAGES --- (No changes here)
    if (is_dir($image_dir_path)) {
        $image_files = array_diff(scandir($image_dir_path), ['.', '..', '.cache']);
        foreach ($image_files as $file) {
            if (is_file($image_dir_path . $file) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
                $stats['images_found']++;
                $optimized_url = create_cached_image($image_dir_path . $file, $cache_dir_path, $file, 1440);
                $media['images'][] = [
                    'path' => '/media/images/backgrounds/' . $file,
                    'thumb' => $optimized_url ?: '/media/images/backgrounds/' . $file,
                    'optimized' => $optimized_url ?: '/media/images/backgrounds/' . $file,
                    /* mtime powers Date-added sort in the gallery toolbar.
                     * filemtime returns unix seconds; rendered-as-is in JS. */
                    'mtime' => @filemtime($image_dir_path . $file) ?: 0,
                    'title' => $file,
                ];
            }
        }
    }

    // --- 2. LOCAL VIDEOS (MP4s) ---
    // **THE RESILIENT FIX IS HERE**
    $local_videos = [];
    if (is_dir($video_dir_path)) {
        $video_files = array_diff(scandir($video_dir_path), ['.', '..', '.cache']);
        foreach ($video_files as $file) {
            if (is_file($video_dir_path . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'mp4') {
                $stats['videos_found']++;
                
                // Attempt to create the thumbnail, passing the error log array by reference.
                $thumb_web_path = create_video_thumbnail(
                    $video_dir_path . $file,
                    $video_cache_dir,
                    $file,
                    $background_manager_errors // The magic variable
                );

                if ($thumb_web_path) {
                    $stats['videos_thumbnailed']++;
                }

                $local_videos[] = [
                    'path' => '/media/videos/backgrounds/' . $file,
                    /* Fallback placeholder — old path /admin/background_mgr/*.png returned
                     * 404 fleet-wide (module was renamed/relocated). The new SVG ships
                     * inside the module itself so the fallback is always reachable. */
                    'thumb' => $thumb_web_path ?: '/admin/modules/BackgroundManager/assets/video-placeholder.svg',
                    'title' => $file,
                    'source' => 'local',
                    'mtime' => @filemtime($video_dir_path . $file) ?: 0,
                ];
            }
        }
    }

    // --- 3. YOUTUBE & MERGE ---
    // Legacy videos.json entries lack 'source' / 'mtime'; backfill them here
    // so the filter/sort pulldowns can distinguish YouTube from local.
    $youtube_videos = [];
    if (file_exists($videos_json_file)) {
        $saved_videos = json_decode(file_get_contents($videos_json_file), true);
        if (is_array($saved_videos)) {
            foreach ($saved_videos as &$yv) {
                if (!isset($yv['source'])) $yv['source'] = 'youtube';
                if (!isset($yv['mtime']))  $yv['mtime']  = 0;
            }
            unset($yv);
            $youtube_videos = $saved_videos;
        }
    }
    $media['videos'] = array_merge($local_videos, $youtube_videos);

    if ($return_stats) { return ['stats' => $stats]; }
    return $media;
}


// This is the main data function for the page.
function bgm_get_all_data() {
    global $background_manager_errors;
    
    // **THE FIX IS HERE:** We now return the errors along with all the other data.
    return [
        'settings'  => bgm_get_settings(),
        'media'     => bgm_get_all_media(),
        'playlists' => bgm_get_playlists(),
        'errors'    => $background_manager_errors
    ];
}


// No changes needed for the functions below.
function bgm_get_playlists() {
    $playlists_file = dirname(__FILE__, 4) . '/admin/data/background_slide_shows.json';
    if (file_exists($playlists_file)) {
        return json_decode(file_get_contents($playlists_file), true) ?: [];
    }
    return [];
}

function bgm_get_settings() {
    $settings_file = dirname(__FILE__, 4) . '/admin/data/background_settings.json';
    $defaults = [
        'active_mode' => 'image', 'background_sizing' => 'cover',
        'overlay' => ['color_rgba' => 'rgba(0,0,0,0.25)'],
        'single' => ['image_url' => ''],
        'video' => ['source_type' => 'local', 'url' => '', 'youtube_id' => ''],
        'slideshow' => ['playlist_name' => '', 'duration' => 5000, 'transition' => 1500]
    ];
    if (file_exists($settings_file)) {
        $saved = json_decode(file_get_contents($settings_file), true);
        return array_replace_recursive($defaults, is_array($saved) ? $saved : []);
    }
    return $defaults;
}
?>