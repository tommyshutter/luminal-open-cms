<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/VenueManager.php
 *
 * Venue CRUD — load, save, delete venues from EMP_FILE_VENUES.
 */
declare(strict_types=1);

class VenueManager {
    public function getAll(): array {
        return DataStore::load(EMP_FILE_VENUES);
    }

    public function save(array $post): array {
        $venues = $this->getAll();
        $id = !empty($post['v_id']) ? $post['v_id'] : uniqid('ven_');

        $imagePath = ltrim($post['v_image'] ?? '', '/');

        $newV = [
            'id'           => $id,
            'name'         => $post['v_name'] ?? '',
            'address'      => $post['v_address'] ?? '',
            'phone'        => $post['v_phone'] ?? '',
            'website'      => $post['v_website'] ?? '',
            'default_time' => $post['v_default_time'] ?? '',
            'image'        => $imagePath,
        ];

        $idx = -1;
        foreach ($venues as $k => $v) {
            if ($v['id'] === $id) { $idx = $k; break; }
        }
        if ($idx >= 0) $venues[$idx] = $newV; else $venues[] = $newV;

        DataStore::save(EMP_FILE_VENUES, $venues);
        return ['ok' => true];
    }

    public function delete(string $id): array {
        $venues = $this->getAll();
        $filtered = [];
        foreach ($venues as $v) {
            if ($v['id'] !== $id) $filtered[] = $v;
        }
        DataStore::save(EMP_FILE_VENUES, $filtered);
        return ['ok' => true];
    }
}
