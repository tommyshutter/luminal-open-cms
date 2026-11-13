<?php
/**
 * /admin/background_mgr/image_optimizer.php
 *
 * @version       2025.07.30.26 (Final)
 * @description   This version contains the definitive fix for the ffmpeg command,
 * incorporating the `-update 1` flag as required by the server's ffmpeg version.
 * This should resolve the "Conversion failed!" errors.
 */

// ... the create_cached_image function is unchanged ...
function create_cached_image($original_path, $cache_dir, $original_filename, $max_width = 1440) {
    if (!file_exists($original_path) || !function_exists('imagecreatefromjpeg')) {
        return null;
    }

    if (!is_dir($cache_dir)) {
        if (!@mkdir($cache_dir, 0755, true)) { return null; } 
    }

    $cache_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '.cache.jpg';
    $cache_filepath = $cache_dir . '/' . $cache_filename;
    $cache_web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $cache_filepath);

    list($width, $height, $type) = getimagesize($original_path);

    if ($width <= $max_width) {
        if ($type == IMAGETYPE_JPEG) {
            if (copy($original_path, $cache_filepath)) { return $cache_web_path; }
        }
        $new_width = $width;
        $new_height = $height;
    } else {
        $aspect_ratio = $height / $width;
        $new_width = $max_width;
        $new_height = (int) ($new_width * $aspect_ratio);
    }

    $new_image = imagecreatetruecolor($new_width, $new_height);
    $source = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $source = imagecreatefromjpeg($original_path); break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($original_path);
            imagepalettetotruecolor($source);
            $white = imagecolorallocate($new_image, 255, 255, 255);
            imagefill($new_image, 0, 0, $white);
            imagecopyresampled($new_image, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
            break;
        case IMAGETYPE_GIF: $source = imagecreatefromgif($original_path); break;
        case IMAGETYPE_WEBP: $source = imagecreatefromwebp($original_path); break;
        default: return null;
    }

    if ($source === null) { return null; }
    
    if($type != IMAGETYPE_PNG){
        imagecopyresampled($new_image, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    }

    if (imagejpeg($new_image, $cache_filepath, 85)) {
        imagedestroy($source);
        imagedestroy($new_image);
        return $cache_web_path;
    }

    imagedestroy($source);
    imagedestroy($new_image);
    return null;
}


function create_video_thumbnail($video_path, $cache_dir, $video_filename, &$errors) {
    if (!function_exists('shell_exec') || in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        if (!isset($GLOBALS['shell_exec_disabled'])) {
            $errors[] = "CRITICAL: `shell_exec` is disabled on this server. Automatic video thumbnail generation is impossible.";
            $GLOBALS['shell_exec_disabled'] = true;
        }
        return null;
    }

    $ffmpeg_path = trim(@shell_exec('which ffmpeg 2>/dev/null'));
    if (empty($ffmpeg_path)) {
        if (!isset($GLOBALS['ffmpeg_not_found'])) {
            $errors[] = "WARNING: `ffmpeg` command not found. Please install ffmpeg on the server for thumbnail generation.";
            $GLOBALS['ffmpeg_not_found'] = true;
        }
        return null;
    }

    if (!is_dir($cache_dir)) {
        if (!@mkdir($cache_dir, 0755, true)) {
             $errors[] = "ERROR: Cannot create video cache directory at {$cache_dir}. Check folder permissions.";
             return null;
        }
    }

    $thumb_filename = pathinfo($video_filename, PATHINFO_FILENAME) . '.jpg';
    // **THE FIX IS HERE:** Corrected the path to prevent double slashes.
    $thumb_filepath = rtrim($cache_dir, '/') . '/' . $thumb_filename;
    
    $thumb_web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $thumb_filepath);

    if (file_exists($thumb_filepath)) {
        return $thumb_web_path;
    }

    // **THE COMMAND FIX:** Added `-update 1` to the ffmpeg command.
    $command = escapeshellarg($ffmpeg_path) 
             . " -i " . escapeshellarg($video_path) 
             . " -vframes 1 -q:v 2"
             . " -update 1 " // Tell ffmpeg to update a single file
             . escapeshellarg($thumb_filepath) 
             . " 2>&1"; // Redirect stderr to stdout
    
    $output = @shell_exec($command);
    
    if (!file_exists($thumb_filepath) || filesize($thumb_filepath) === 0) {
        $errors[] = "Failed to create thumbnail for `{$video_filename}`. FFMPEG output: " . htmlentities($output);
        return null;
    }

    return $thumb_web_path;
}
?>