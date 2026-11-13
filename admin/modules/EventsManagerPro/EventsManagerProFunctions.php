<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: EventsManagerProFunctions.php
 *
 * Configuration constants and path helpers for Events Manager Pro.
 * Replaces original config.php with module-relative data paths.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';

// --- Data directory (site-level admin/data/) ---
function emp_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// --- JSON file paths ---
define('EMP_FILE_MASTER',      emp_data_dir() . '/events_master.json');
define('EMP_FILE_PUBLIC',      emp_data_dir() . '/events_data_published.json');
define('EMP_FILE_VENUES',      emp_data_dir() . '/venues_master.json');
define('EMP_FILE_SOCIALS',     emp_data_dir() . '/social_credentials.json');
define('EMP_FILE_FB_SETTINGS', emp_data_dir() . '/facebook_events_settings.json');
define('EMP_FILE_SETTINGS',    emp_data_dir() . '/events_settings.json');

// --- Media paths ---
// Media lives under the SITE_ROOT, not the module directory.
// SITE_ROOT is set by site_config.php (the AdminPanel's host site).
define('EMP_PATH_MEDIA_REL', '/media/images/');
define('EMP_PATH_MEDIA_ABS', rtrim(sc_get('site_root', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . EMP_PATH_MEDIA_REL);

// Ensure poster/venue subdirectories exist
if (!is_dir(EMP_PATH_MEDIA_ABS . 'posters/')) @mkdir(EMP_PATH_MEDIA_ABS . 'posters/', 0755, true);
if (!is_dir(EMP_PATH_MEDIA_ABS . 'venues/'))  @mkdir(EMP_PATH_MEDIA_ABS . 'venues/',  0755, true);

/**
 * Return SITE_ROOT for absolute path building.
 * Falls back to DOCUMENT_ROOT if site_config doesn't define it.
 */
function emp_site_root(): string {
    return rtrim(sc_get('site_root', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
}
