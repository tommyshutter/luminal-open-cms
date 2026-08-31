<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: api.php
 *
 * JSON API — event CRUD, venues, social posting, image optimization, cache ops.
 * Auth hardened: source had NO authentication on any endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

$user = guard_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

require_once __DIR__ . '/EventsManagerProFunctions.php';
require_once __DIR__ . '/classes/DataStore.php';
require_once __DIR__ . '/classes/VenueManager.php';
require_once __DIR__ . '/classes/SocialPoster.php';
require_once __DIR__ . '/classes/EventManager.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

function ok(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function bad(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        // --- Event Ops ---
        case 'fetch_data':
            $mgr = new EventManager();
            ok(['data' => $mgr->fetch($_POST['view'] ?? 'upcoming')]);

        case 'save_event':
            $mgr = new EventManager();
            $result = $mgr->save($_POST, $_FILES ?? []);
            if ($result['ok'] ?? false) ok();
            bad($result['error'] ?? 'Save failed');

        case 'delete_event':
            $mgr = new EventManager();
            $result = $mgr->delete($_POST['id'] ?? '');
            if ($result['ok'] ?? false) ok();
            bad($result['error'] ?? 'Delete failed');

        case 'duplicate_event':
            $mgr = new EventManager();
            $result = $mgr->duplicate($_POST['id'] ?? '');
            if ($result['ok'] ?? false) ok();
            bad($result['error'] ?? 'Duplicate failed');

        case 'set_status':
            $mgr = new EventManager();
            $result = $mgr->setStatus($_POST['id'] ?? '', $_POST['new_status'] ?? '');
            if ($result['ok'] ?? false) ok();
            bad($result['error'] ?? 'Status update failed');

        case 'upload_file':
            $mgr = new EventManager();
            $result = $mgr->upload($_FILES['file'] ?? [], $_POST['upload_type'] ?? 'poster');
            if ($result['ok'] ?? false) ok(['path' => $result['path'], 'url' => $result['url']]);
            bad($result['error'] ?? 'Upload failed');

        case 'import_yt':
            $mgr = new EventManager();
            $result = $mgr->importYt($_POST['url'] ?? '');
            if ($result['ok'] ?? false) ok(['data' => $result['data']]);
            bad($result['error'] ?? 'YouTube import failed');

        // --- Settings Ops ---
        case 'get_settings':
            $mgr = new EventManager();
            ok(['data' => $mgr->getSettings()]);

        case 'save_settings':
            $mgr = new EventManager();
            $result = $mgr->saveSettings($_POST);
            if ($result['ok'] ?? false) ok();
            bad('Settings save failed');

        // --- Venue Ops ---
        case 'get_venues':
            $vm = new VenueManager();
            ok(['data' => $vm->getAll()]);

        case 'save_venue':
            $vm = new VenueManager();
            $result = $vm->save($_POST);
            if ($result['ok'] ?? false) ok();
            bad('Venue save failed');

        case 'delete_venue':
            $vm = new VenueManager();
            $result = $vm->delete($_POST['id'] ?? '');
            if ($result['ok'] ?? false) ok();
            bad('Venue delete failed');

        // --- Social Ops ---
        case 'get_creds':
            $sp = new SocialPoster();
            ok(['data' => $sp->getCredentials()]);

        case 'save_creds':
            $sp = new SocialPoster();
            $result = $sp->saveCredentials($_POST);
            if ($result['ok'] ?? false) ok();
            bad('Credentials save failed');

        case 'push_social':
            $sp = new SocialPoster();
            $result = $sp->push($_POST['platform'] ?? '', $_POST['id'] ?? '');
            if ($result['ok'] ?? false) ok(['message' => $result['message'] ?? 'Posted']);
            bad($result['error'] ?? 'Post failed');

        // --- Image Optimization ---
        case 'optimize_image':
            require_once __DIR__ . '/classes/ImageOptimizer.php';
            require_once __DIR__ . '/classes/MediaCacheHelper.php';
            $imagePath = $_POST['path'] ?? '';
            if (!$imagePath) bad('No image path provided');

            $siteRoot = emp_site_root();
            $imagePath = ltrim($imagePath, '/');
            $absPath   = $siteRoot . '/' . $imagePath;
            if (!file_exists($absPath)) bad('Image not found');

            $oldSize  = filesize($absPath);
            $info     = ImageOptimizer::getImageInfo($absPath);
            $pathInfo = pathinfo($absPath);
            $newName  = $pathInfo['filename'] . '_opt.jpg';
            $newAbsPath = $pathInfo['dirname'] . '/' . $newName;
            $newWebPath = dirname($imagePath) . '/' . $newName;
            $cropSquare = ($_POST['crop_square'] ?? '0') === '1';

            $result = ImageOptimizer::optimize($absPath, $newAbsPath, $cropSquare);
            if ($result) {
                if ($absPath !== $newAbsPath && file_exists($absPath)) @unlink($absPath);
                MediaCacheHelper::deleteCachedThumbnail($imagePath);
                MediaCacheHelper::refreshThumbnail($newWebPath);
                $newSize = filesize($newAbsPath);
                ok([
                    'url'      => '/' . $newWebPath,
                    'path'     => $newWebPath,
                    'old_size' => ImageOptimizer::humanSize($oldSize),
                    'new_size' => ImageOptimizer::humanSize($newSize),
                    'saved'    => ImageOptimizer::humanSize($oldSize - $newSize),
                ]);
            }
            bad('Optimization failed');

        case 'bulk_optimize':
            require_once __DIR__ . '/classes/ImageOptimizer.php';
            require_once __DIR__ . '/classes/MediaCacheHelper.php';
            $siteRoot = emp_site_root();
            $mgr = new EventManager();
            $events = $mgr->fetchData('all');
            $optimized = 0; $savedBytes = 0;

            foreach ($events as $e) {
                if (!empty($e['image'])) {
                    $imgPath = ltrim($e['image'], '/');
                    $absPath = $siteRoot . '/' . $imgPath;
                    if (file_exists($absPath) && ImageOptimizer::needsOptimization($absPath)) {
                        $oldSize  = filesize($absPath);
                        $pathInfo = pathinfo($absPath);
                        $newName  = $pathInfo['filename'] . '_opt.jpg';
                        $newAbsPath = $pathInfo['dirname'] . '/' . $newName;
                        if (ImageOptimizer::optimize($absPath, $newAbsPath)) {
                            if ($absPath !== $newAbsPath) @unlink($absPath);
                            $savedBytes += $oldSize - filesize($newAbsPath);
                            $optimized++;
                            $newWebPath = dirname($imgPath) . '/' . $newName;
                            $mgr->updateImagePath($e['id'], $newWebPath);
                            MediaCacheHelper::deleteCachedThumbnail($imgPath);
                            MediaCacheHelper::refreshThumbnail($newWebPath);
                        }
                    }
                }
            }
            ok(['optimized' => $optimized, 'saved' => ImageOptimizer::humanSize($savedBytes)]);

        // --- Media Cache Ops ---
        case 'refresh_image_cache':
            require_once __DIR__ . '/classes/MediaCacheHelper.php';
            $imagePath = $_POST['path'] ?? '';
            if (!$imagePath) bad('No image path provided');
            $result = MediaCacheHelper::refreshThumbnail($imagePath);
            if ($result['success']) ok(['thumb_url' => $result['thumb_url'] ?? null]);
            bad($result['message'] ?? 'Failed');

        case 'flush_events_thumbs':
            require_once __DIR__ . '/classes/MediaCacheHelper.php';
            $result = MediaCacheHelper::flushEventsCache();
            ok(['message' => 'Flushed ' . $result['removed'] . ' cached thumbnails', 'removed' => $result['removed']]);

        case 'rebuild_events_thumbs':
            require_once __DIR__ . '/classes/MediaCacheHelper.php';
            $mgr = new EventManager();
            $events = $mgr->fetchData('all');
            $refreshed = 0; $failed = 0;
            foreach ($events as $e) {
                if (!empty($e['image'])) {
                    $r = MediaCacheHelper::refreshThumbnail($e['image']);
                    if ($r['success']) $refreshed++; else $failed++;
                }
            }
            ok(['message' => "Rebuilt {$refreshed} thumbnails" . ($failed > 0 ? " ({$failed} failed)" : ''), 'refreshed' => $refreshed, 'failed' => $failed]);

        default:
            bad('Unknown action');
    }
} catch (\Exception $e) {
    bad($e->getMessage(), 500);
}
