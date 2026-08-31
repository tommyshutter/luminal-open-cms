<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/EventManager.php
 *
 * Event CRUD, upload, import, settings, and cache rebuild.
 * path constants updated to EMP_* prefixes.
 */
declare(strict_types=1);

require_once __DIR__ . '/MediaCacheHelper.php';

class EventManager {

    // --- SETTINGS ---
    public function getSettings(): array {
        return DataStore::load(EMP_FILE_SETTINGS);
    }

    public function saveSettings(array $post): array {
        $s = [
            'default_band'    => $post['default_band'] ?? '',
            'cols_lg'         => $post['cols_lg'] ?? 3,
            'show_desc'       => $post['show_desc'] ?? 'false',
            'share_fb'        => $post['share_fb'] ?? 'false',
            'share_x'         => $post['share_x'] ?? 'false',
            'share_email'     => $post['share_email'] ?? 'false',
            'show_venue_link' => $post['show_venue_link'] ?? 'false',
        ];
        DataStore::save(EMP_FILE_SETTINGS, $s);
        return ['ok' => true];
    }

    // --- EVENTS ---
    public function fetch(string $view): array {
        $master = DataStore::load(EMP_FILE_MASTER);
        $venues = DataStore::load(EMP_FILE_VENUES);
        $venMap = [];
        foreach ($venues as $v) $venMap[$v['id']] = $v;

        $out = [];
        foreach ($master as $e) {
            $st = $e['status'] ?? 'draft';
            $e['venue_data'] = isset($e['venue_id']) ? ($venMap[$e['venue_id']] ?? null) : null;

            if ($view === 'drafts' && $st === 'draft') $out[] = $e;
            elseif ($view === 'archived' && $st === 'archived') $out[] = $e;
            elseif ($view === 'trash' && $st === 'trashed') $out[] = $e;
            elseif ($view === 'upcoming' && $st === 'published') $out[] = $e;
        }

        $desc = ($view === 'trash' || $view === 'archived');
        usort($out, function ($a, $b) use ($desc) {
            $ta = strtotime(($a['start_date'] ?? '') . ($a['start_time'] ?? ''));
            $tb = strtotime(($b['start_date'] ?? '') . ($b['start_time'] ?? ''));
            return $desc ? ($tb - $ta) : ($ta - $tb);
        });
        return $out;
    }

    public function save(array $post, array $files): array {
        $db = DataStore::load(EMP_FILE_MASTER);
        $id = !empty($post['id']) ? $post['id'] : uniqid('evt_');
        $status = $post['status'] ?? 'draft';

        $idx = -1;
        $currImg = null;
        foreach ($db as $k => $e) {
            if ($e['id'] === $id) { $idx = $k; $currImg = $e['image'] ?? null; break; }
        }

        $finalImg = !empty($post['final_image_path']) ? ltrim($post['final_image_path'], '/') : $currImg;
        if (!empty($post['imported_image_url']) && empty($post['final_image_path'])) {
            $remote = @file_get_contents($post['imported_image_url']);
            if ($remote) {
                $fname = uniqid('imp_') . '.jpg';
                if (file_put_contents(EMP_PATH_MEDIA_ABS . 'posters/' . $fname, $remote)) {
                    $finalImg = 'media/images/posters/' . $fname;
                }
            }
        }
        if ($finalImg) {
            $finalImg = ltrim($finalImg, '/');
        }
        if (($post['remove_image'] ?? '') === 'true') {
            if ($currImg) MediaCacheHelper::deleteCachedThumbnail($currImg);
            $finalImg = null;
        }
        if ($currImg && $finalImg && $currImg !== $finalImg) {
            MediaCacheHelper::deleteCachedThumbnail($currImg);
        }

        $evt = [
            'id'                => $id,
            'event_name'        => $post['event_name'] ?? '',
            'title'             => $post['title'] ?? 'Untitled',
            'disable_seo_title' => ($post['disable_seo_title'] ?? '0') === '1' ? '1' : '0',
            'venue_id'          => $post['venue_id'] ?? '',
            'start_date'        => $post['start_date'] ?? '',
            'start_time'        => $post['start_time'] ?? '',
            'end_date'          => $post['end_date'] ?? '',
            'end_time'          => $post['end_time'] ?? '',
            'start_time_full'   => ($post['start_date'] ?? '') . ' ' . ($post['start_time'] ?? ''),
            'fb_link'           => $post['fb_link'] ?? '',
            'map_link'          => $post['map_link'] ?? '',
            'cta_url'           => $post['cta_url'] ?? '',
            'yt_link'           => $post['yt_link'] ?? '',
            'yt_thumb'          => $post['yt_thumb'] ?? '',
            'image'             => $finalImg,
            'content_html'      => $post['content_html'] ?? '',
            'status'            => $status,
            'updated_at'        => time(),
        ];

        if ($idx >= 0) $db[$idx] = $evt; else $db[] = $evt;

        DataStore::save(EMP_FILE_MASTER, $db);
        $this->rebuildCache($db);
        return ['ok' => true];
    }

    public function delete(string $id): array {
        $db = DataStore::load(EMP_FILE_MASTER);
        $filtered = [];
        foreach ($db as $e) {
            if ((string)$e['id'] !== (string)$id) $filtered[] = $e;
        }
        DataStore::save(EMP_FILE_MASTER, $filtered);
        $this->rebuildCache($filtered);
        return ['ok' => true];
    }

    public function duplicate(string $id): array {
        $db = DataStore::load(EMP_FILE_MASTER);
        foreach ($db as $evt) {
            if ($evt['id'] === $id) {
                $clone = $evt;
                $clone['id'] = uniqid('evt_');
                $clone['status'] = 'draft';
                $clone['title'] .= ' (Copy)';
                $db[] = $clone;
                DataStore::save(EMP_FILE_MASTER, $db);
                $this->rebuildCache($db);
                return ['ok' => true];
            }
        }
        return ['ok' => false, 'error' => 'Source event not found'];
    }

    public function setStatus(string $id, string $newStatus): array {
        $db = DataStore::load(EMP_FILE_MASTER);
        foreach ($db as &$e) {
            if ($e['id'] === $id) $e['status'] = $newStatus;
        }
        DataStore::save(EMP_FILE_MASTER, $db);
        $this->rebuildCache($db);
        return ['ok' => true];
    }

    // --- UTILS ---
    public function upload(array $file, string $type): array {
        $targetDir = ($type === 'venue') ? EMP_PATH_MEDIA_ABS . 'venues/' : EMP_PATH_MEDIA_ABS . 'posters/';
        $webPath   = ($type === 'venue') ? 'media/images/venues/' : 'media/images/posters/';
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $isImage = in_array($ext, $imageExts);
        $finalExt = $isImage ? 'jpg' : $ext;
        $name = uniqid($type . '_') . '.' . $finalExt;

        $tempPath = $targetDir . 'temp_' . basename($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            return ['ok' => false, 'error' => 'Move failed'];
        }

        $finalPath = $targetDir . $name;

        if ($isImage) {
            require_once __DIR__ . '/ImageOptimizer.php';
            $optimized = ImageOptimizer::optimize($tempPath, $finalPath);
            @unlink($tempPath);
            if (!$optimized) {
                return ['ok' => false, 'error' => 'Image optimization failed'];
            }
        } else {
            if (!rename($tempPath, $finalPath)) {
                @unlink($tempPath);
                return ['ok' => false, 'error' => 'File save failed'];
            }
        }

        MediaCacheHelper::refreshThumbnail($webPath . $name);
        return ['ok' => true, 'path' => $webPath . $name, 'url' => '/' . $webPath . $name];
    }

    public function importYt(string $url): array {
        $api = "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json";
        $j = json_decode(@file_get_contents($api), true);
        return $j
            ? ['ok' => true, 'data' => ['title' => $j['title'], 'thumb' => $j['thumbnail_url']]]
            : ['ok' => false, 'error' => 'Could not resolve YouTube video'];
    }

    /**
     * Fetch raw event data for bulk operations.
     */
    public function fetchData(string $filter = 'all'): array {
        $master = DataStore::load(EMP_FILE_MASTER);
        if ($filter === 'all') return $master;
        return array_values(array_filter($master, fn($e) => ($e['status'] ?? '') === $filter));
    }

    /**
     * Update image path for an event (used by bulk optimization).
     */
    public function updateImagePath(string $id, string $newPath): bool {
        $db = DataStore::load(EMP_FILE_MASTER);
        foreach ($db as &$e) {
            if ($e['id'] === $id) {
                $e['image'] = ltrim($newPath, '/');
                DataStore::save(EMP_FILE_MASTER, $db);
                $this->rebuildCache($db);
                return true;
            }
        }
        return false;
    }

    private function rebuildCache(array $data): void {
        $pub = [];
        foreach ($data as $e) {
            if (($e['status'] ?? '') === 'published') $pub[] = $e;
        }
        usort($pub, function ($a, $b) {
            $ta = strtotime(($a['start_date'] ?? '') . ($a['start_time'] ?? ''));
            $tb = strtotime(($b['start_date'] ?? '') . ($b['start_time'] ?? ''));
            return $ta - $tb;
        });
        DataStore::save(EMP_FILE_PUBLIC, $pub);
    }
}
