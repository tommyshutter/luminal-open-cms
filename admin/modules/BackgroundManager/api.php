<?php
/**
 * Luminal CMS — Background Manager API
 *
 * @package    LuminalCMS
 * @module     BackgroundManager
 * @file       /admin/modules/BackgroundManager/api.php
 */
declare(strict_types=1);

header('Content-Type: application/json');
require_once dirname(__DIR__, 3) . '/utils.php';
require_once dirname(__DIR__, 2) . '/auth.php';
requireAuth();

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

/*
 * Detect an oversized upload: when a multipart/form-data body exceeds
 * post_max_size, PHP empties both $_POST and $_FILES but still reports
 * a non-zero CONTENT_LENGTH. Only trigger this branch for multipart —
 * a plain application/json POST also has empty $_POST by design (the
 * body is in php://input), and the old check was misfiring on every
 * JSON action (delete_media, save_master_settings, clear_thumb_cache)
 * with a misleading "Upload too large" message.
 */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipartPost = stripos($contentType, 'multipart/form-data') !== false;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $isMultipartPost
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $maxPost = ini_get('post_max_size');
    echo json_encode(['success' => false, 'message' => "Upload too large. Server limit is {$maxPost}. Check upload_max_filesize and post_max_size in php.ini."]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_POST['action'] ?? $data['action'] ?? null;

if ($action === 'save_master_settings') {
    try {
        $current_settings = loadJsonData('background_settings.json') ?? [];

        if (isset($data['settings'])) {
            $new_settings = $data['settings'];
            
            $current_settings['active_mode'] = $new_settings['active_mode'] ?? $current_settings['active_mode'];
            $current_settings['background_sizing'] = $new_settings['background_sizing'] ?? $current_settings['background_sizing'];
            $current_settings['overlay'] = array_merge($current_settings['overlay'] ?? [], $new_settings['overlay'] ?? []);

            $active_mode = $current_settings['active_mode'];
            if (isset($new_settings[$active_mode])) {
                $current_settings[$active_mode] = $new_settings[$active_mode];
            }
        }
        
        if (saveJsonData('background_settings.json', $current_settings)) {
            $response = ['success' => true, 'message' => 'Master settings saved successfully!', 'new_settings' => $current_settings];
        } else {
            throw new Exception('Could not write to background_settings.json.');
        }

    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
} elseif ($action === 'delete_media') {
    $path = $data['path'] ?? '';
    $type = $data['type'] ?? '';
    $siteRoot = dirname(__DIR__, 3);

    if (!$path) {
        $response = ['success' => false, 'message' => 'Missing path.'];
    } elseif ($type === 'youtube') {
        $videosFile = $siteRoot . '/admin/data/videos.json';
        $videos = is_file($videosFile) ? (json_decode(file_get_contents($videosFile), true) ?: []) : [];
        $before = count($videos);
        $videos = array_values(array_filter($videos, fn($v) => ($v['path'] ?? '') !== $path));
        if (count($videos) < $before) {
            file_put_contents($videosFile, json_encode($videos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            @chown($videosFile, 'www-data');
            @chgrp($videosFile, 'www-data');
            $response = ['success' => true, 'message' => 'YouTube video removed from gallery.'];
        } else {
            $response = ['success' => false, 'message' => 'Video not found in gallery.'];
        }
    } else {
        $fullPath = realpath($siteRoot . $path);
        $mediaDir = realpath($siteRoot . '/media/');
        if (!$fullPath || !$mediaDir || strpos($fullPath, $mediaDir) !== 0) {
            $response = ['success' => false, 'message' => 'Invalid path.'];
        } elseif (!is_file($fullPath)) {
            $response = ['success' => false, 'message' => 'File not found.'];
        } else {
            @unlink($fullPath);
            $cacheThumb = dirname($fullPath) . '/.cache/' . pathinfo(basename($fullPath), PATHINFO_FILENAME) . '.jpg';
            if (is_file($cacheThumb)) @unlink($cacheThumb);
            $response = ['success' => true, 'message' => 'Media file deleted.'];
        }
    }
} elseif ($action === 'upload_media') {
    // Handle drag-and-drop uploads to backgrounds directories
    $type = $_POST['type'] ?? $data['type'] ?? '';
    $siteRoot = dirname(__DIR__, 3);

    $imageExts = ['jpg','jpeg','png','gif','webp'];
    $videoExts = ['mp4','webm','ogv','mov','m4v'];
    $allAllowed = array_merge($imageExts, $videoExts);

    if (!in_array($type, ['images', 'videos'])) {
        $response = ['success' => false, 'message' => 'Invalid upload type. Use "images" or "videos".'];
    } elseif (!isset($_FILES['files'])) {
        $response = ['success' => false, 'message' => 'No files received.'];
    } else {
        require_once __DIR__ . '/includes/image_optimizer.php';

        $uploaded = [];
        $errors = [];
        $fileCount = count($_FILES['files']['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            $fileName = basename($_FILES['files']['name'][$i]);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allAllowed)) {
                $errors[] = "{$fileName}: invalid file type (.{$ext}).";
                continue;
            }

            // Auto-route: determine actual type by extension regardless of which dropzone was used
            $fileType = in_array($ext, $videoExts) ? 'videos' : 'images';
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "{$fileName}: upload error code " . $_FILES['files']['error'][$i];
                continue;
            }

            $uploadDir = $siteRoot . '/media/' . $fileType . '/backgrounds/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $cacheDir = $uploadDir . '.cache/';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

            $targetFile = $uploadDir . $fileName;
            if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetFile)) {
                $errors[] = "{$fileName}: failed to move uploaded file.";
                continue;
            }
            @chown($targetFile, 'www-data');
            @chgrp($targetFile, 'www-data');

            // Generate thumbnail
            $thumbErrors = [];
            $webPath = '/media/' . $fileType . '/backgrounds/' . $fileName;
            $thumbUrl = null;
            $optimizedUrl = $webPath;

            if ($fileType === 'images') {
                $optimizedUrl = create_cached_image($targetFile, $cacheDir, $fileName, 1440) ?: $webPath;
                $thumbUrl = $optimizedUrl;
            } else {
                $thumbUrl = create_video_thumbnail($targetFile, $cacheDir, $fileName, $thumbErrors);
                if (!$thumbUrl) $thumbUrl = '/admin/background_mgr/video-placeholder.png';
            }

            $uploaded[] = [
                'path' => $webPath,
                'thumb' => $thumbUrl,
                'optimized' => $optimizedUrl,
                'title' => $fileName,
                'media_type' => $fileType,
            ];
        }

        if (!empty($uploaded)) {
            $msg = count($uploaded) . ' file(s) uploaded successfully.';
            if (!empty($errors)) $msg .= ' ' . count($errors) . ' failed.';
            $response = ['success' => true, 'message' => $msg, 'uploaded' => $uploaded, 'errors' => $errors];
        } else {
            $response = ['success' => false, 'message' => 'No files were uploaded.', 'errors' => $errors];
        }
    }
} elseif ($action === 'clear_thumb_cache') {
    // Wipe image and video thumbnail caches so they regenerate fresh on next page load.
    // Fixes corrupt/out-of-sync gallery thumbnails after MediaThumbs runs.
    $siteRoot = dirname(__DIR__, 3);
    $cacheDirs = [
        $siteRoot . '/media/images/backgrounds/.cache/',
        $siteRoot . '/media/videos/backgrounds/.cache/',
    ];
    $deleted = 0;
    $errors  = [];
    foreach ($cacheDirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $dir . $f;
            if (is_file($fp)) {
                if (@unlink($fp)) $deleted++;
                else $errors[] = "Could not delete: $f";
            }
        }
    }
    if (empty($errors)) {
        $response = ['success' => true, 'message' => "Cache cleared — $deleted file(s) removed. Gallery will regenerate on reload."];
    } else {
        $response = ['success' => false, 'message' => 'Partial clear. Errors: ' . implode(', ', $errors), 'deleted' => $deleted];
    }
} else {
    $response['message'] = 'Invalid action specified.';
}

echo json_encode($response);
?>