<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FileManager v1.0.0
 * File: FileManagerConfig.php
 *
 * Reads data/config.json and defines FM_* constants with sensible defaults.
 * All existing function code referencing these constants works unchanged.
 */
declare(strict_types=1);

$_fm_data_dir = SITE_ROOT . '/admin/data/FileManager';
if (!is_dir($_fm_data_dir)) @mkdir($_fm_data_dir, 0775, true);
$_fm_cfg_path = $_fm_data_dir . '/config.json';
$_fm_cfg = file_exists($_fm_cfg_path)
    ? (json_decode(file_get_contents($_fm_cfg_path), true) ?: [])
    : [];

define('FM_BASE_ROOT',            $_fm_cfg['base_root'] ?? SITE_ROOT);
define('FM_START_PATH',           $_fm_cfg['start_path'] ?? '');
define('FM_ALLOW_DELETE',         $_fm_cfg['allow_delete'] ?? true);
define('FM_ALLOW_UPLOAD',         $_fm_cfg['allow_upload'] ?? true);
define('FM_ALLOW_ZIP',            $_fm_cfg['allow_zip'] ?? true);
define('FM_ALLOW_TEXT_EDIT',      $_fm_cfg['allow_text_edit'] ?? true);
define('FM_ENABLE_VIDEO_THUMBS',  $_fm_cfg['enable_video_thumbs'] ?? true);
define('FM_MAX_UPLOAD_BYTES',     $_fm_cfg['max_upload_bytes'] ?? 256 * 1024 * 1024);
define('FM_EDIT_MAX_BYTES',       $_fm_cfg['edit_max_bytes'] ?? 2 * 1024 * 1024);
define('FM_SHARED_SECRET',        '');
define('FM_FFMPEG',               $_fm_cfg['ffmpeg_path'] ?? '');
define('FM_BACKUP_DIR',           $_fm_cfg['backup_dir'] ?? '/var/www/backups');
define('FM_BACKUP_DEFAULT_FORMAT',$_fm_cfg['backup_default_format'] ?? 'zip');
define('FM_ENABLE_CHOWN',         $_fm_cfg['enable_chown'] ?? true);
define('FM_CHOWN_USER',           $_fm_cfg['chown_user'] ?? 'www-data');
define('FM_CHOWN_GROUP',          $_fm_cfg['chown_group'] ?? 'www-data');
define('FM_CHOWN_METHOD',         $_fm_cfg['chown_method'] ?? 'auto');
define('FM_CHOWN_USE_SUDO',       $_fm_cfg['chown_use_sudo'] ?? true);
define('FM_DEBUG',                $_fm_cfg['debug'] ?? false);
define('FM_LIGHTBOX_MODE',        'internal');
define('FM_TIMEZONE',             $_fm_cfg['timezone'] ?? 'America/New_York');
define('FM_TIME_24H',             $_fm_cfg['time_24h'] ?? true);
define('FM_STAMP_FORMAT',         $_fm_cfg['stamp_format'] ?? 'Ymd.Hi.s');
define('FM_ARCHIVE_PREF',         $_fm_cfg['archive_pref'] ?? 'tar');
define('FM_ARCHIVE_PREFIX',       preg_replace('/[^a-z0-9.\-]+/i', '_', $_SERVER['HTTP_HOST'] ?? 'site'));

$GLOBALS['FM_INLINE_PREVIEW_ALLOW'] = [
    'text/plain','text/markdown','text/css','text/javascript','application/json',
    'application/xml','image/png','image/jpeg','image/gif','image/webp','image/svg+xml',
    'text/html','video/mp4','video/webm','video/ogg','application/pdf',
];

$GLOBALS['FM_HIDDEN_PATTERNS'] = [
    '/^\.(git|svn|DS_Store|htaccess|env)/i',
    '/^node_modules$/i',
];

$GLOBALS['FM_BACKUP_EXCLUDES'] = [
    'app'   => ['/admin/data', '/media'],
    'media' => ['/media/.cache','/media/cache','/media/_cache','/media/thumbnails','/media/thumbs'],
];

$GLOBALS['FM_CHOWN_EXCLUDES'] = [
    '/admin/data/FileManager',
];

function FM_nowstamp(?string $fmt = null): string {
    $fmt = $fmt ?: FM_STAMP_FORMAT;
    $tz  = new DateTimeZone(FM_TIMEZONE ?: 'UTC');
    $dt  = new DateTime('now', $tz);
    return $dt->format($fmt);
}

unset($_fm_cfg_path, $_fm_cfg);
