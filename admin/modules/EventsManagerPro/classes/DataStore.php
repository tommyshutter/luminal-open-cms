<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/DataStore.php
 *
 * Simple JSON file I/O helper — load/save arrays as pretty-printed JSON.
 */
declare(strict_types=1);

class DataStore {
    public static function load(string $filepath): array {
        if (file_exists($filepath)) {
            $content = file_get_contents($filepath);
            $data = json_decode($content, true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public static function save(string $filepath, array $data): bool {
        return file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    }
}
