<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FileManager v1.0.0
 * File: api.php
 *
 * JSON API — all file operations (list, dirs, mkdir, rename, delete, upload,
 * download, preview, copy, move, zip, unzip, stat, read, write, thumb,
 * backup, chown_fix, list_backups, disk_usage).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/FileManagerFunctions.php';
require_once __DIR__ . '/../MediaThumbs/lib/audio-cover.php'; // shared cover-art extractor

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$rel    = $_POST['path']   ?? $_GET['path']   ?? '';

// Full Tree View toggle — superadmin-only, no path. Must precede fm_guard_path
// (which requires a valid path). Server-validates the role so a non-superadmin
// cannot expand their own scope even by forging this call.
if ($action === 'set_view_scope') {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (fm_is_superadmin()) {
        $_SESSION['fm_full_tree'] = ((($_POST['full_tree'] ?? $_GET['full_tree'] ?? '0')) === '1');
    }
    fm_json(['ok' => true, 'full_tree' => fm_full_tree_active()]);
}

[$ok, $real, $base] = fm_guard_path((string)$rel);
if (!$ok) fm_json(['ok' => false, 'error' => 'Invalid path'], 400);

switch ($action) {
    case 'list':
        if (!is_dir($real)) fm_json(['ok' => false, 'error' => 'Not a directory'], 400);
        fm_json(['ok' => true, 'items' => fm_list_dir($real), 'cwd' => $rel, 'base' => FM_BASE_ROOT]);

    case 'dirs':
        if (!is_dir($real)) fm_json(['ok' => false, 'error' => 'Not a directory'], 400);
        fm_json(['ok' => true, 'dirs' => fm_dirs_only($real), 'cwd' => $rel]);

    case 'mkdir':
        $name = fm_safename((string)($_POST['name'] ?? ''));
        if ($name === '') fm_json(['ok' => false, 'error' => 'Missing name'], 400);
        fm_json(['ok' => @mkdir($real . DIRECTORY_SEPARATOR . $name, 0777, true)]);

    case 'rename':
        $new = fm_safename((string)($_POST['name'] ?? ''));
        if ($new === '') fm_json(['ok' => false, 'error' => 'Missing name'], 400);
        fm_json(['ok' => @rename($real, dirname($real) . DIRECTORY_SEPARATOR . $new)]);

    case 'delete':
        if (!FM_ALLOW_DELETE) fm_json(['ok' => false, 'error' => 'Delete disabled'], 403);
        fm_json(['ok' => fm_delete_recursive($real)]);

    case 'upload':
        if (!FM_ALLOW_UPLOAD) fm_json(['ok' => false, 'error' => 'Upload disabled'], 403);
        if (!is_dir($real) || !is_writable($real)) fm_json(['ok' => false, 'error' => 'Target not writable'], 400);
        $files = $_FILES['files'] ?? null;
        if (!$files) fm_json(['ok' => false, 'error' => 'No files'], 400);
        $cnt = is_array($files['name']) ? count($files['name']) : 1;
        $saved = [];
        for ($i = 0; $i < $cnt; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $size = is_array($files['size']) ? (int)$files['size'][$i] : (int)$files['size'];
            if ($size > FM_MAX_UPLOAD_BYTES) continue;
            $safe = fm_safename($name);
            $to   = $real . DIRECTORY_SEPARATOR . $safe;
            if (@move_uploaded_file($tmp, $to)) $saved[] = $safe;
        }
        fm_json(['ok' => true, 'saved' => $saved]);

    case 'download':
        if (!is_file($real) || !is_readable($real)) fm_json(['ok' => false, 'error' => 'Not a readable file'], 400);
        $mime = mime_content_type($real) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        readfile($real);
        exit;

    case 'preview':
        if (!is_file($real) || !is_readable($real)) fm_json(['ok' => false, 'error' => 'Not a readable file'], 400);
        $mime = mime_content_type($real) ?: 'application/octet-stream';
        if (!in_array($mime, $GLOBALS['FM_INLINE_PREVIEW_ALLOW'], true)) fm_json(['ok' => false, 'error' => 'Preview not allowed for this type'], 400);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        readfile($real);
        exit;

    case 'copy':
    case 'move':
        $toRel = (string)($_POST['to'] ?? '');
        [$ok2, $toReal] = fm_guard_path($toRel);
        if (!$ok2 || !is_dir($toReal)) fm_json(['ok' => false, 'error' => 'Invalid destination'], 400);
        $dst = $toReal . DIRECTORY_SEPARATOR . basename($real);
        fm_json(['ok' => fm_copy_move($real, $dst, $action === 'move')]);

    case 'zip':
        if (!FM_ALLOW_ZIP) fm_json(['ok' => false, 'error' => 'ZIP disabled'], 403);
        $items   = $_POST['items'] ?? [];
        $zipName = fm_safename((string)($_POST['name'] ?? 'archive')) . '.zip';
        $targets = [];
        foreach ((array)$items as $r) {
            [$okI, $realI] = fm_guard_path((string)$r);
            if ($okI && $realI) $targets[] = $realI;
        }
        if (!$targets) fm_json(['ok' => false, 'error' => 'No items to zip'], 400);
        $zipPath = $real . DIRECTORY_SEPARATOR . $zipName;
        fm_json(['ok' => fm_zip_create($zipPath, $targets, realpath(FM_BASE_ROOT) ?: ''), 'zip' => $zipName]);

    case 'unzip':
        if (!FM_ALLOW_ZIP) fm_json(['ok' => false, 'error' => 'ZIP disabled'], 403);
        if (!is_file($real)) fm_json(['ok' => false, 'error' => 'Not a zip file'], 400);
        $destRel = (string)($_POST['to'] ?? $rel);
        [$okD, $destReal] = fm_guard_path($destRel);
        if (!$okD || !is_dir($destReal)) fm_json(['ok' => false, 'error' => 'Bad destination'], 400);
        $zip = new ZipArchive();
        $okX = ($zip->open($real) === true) && $zip->extractTo($destReal) && $zip->close() === true;
        fm_json(['ok' => $okX]);

    case 'stat':
        $isDir = is_dir($real);
        [$own, $grp] = fm_owner_group($real);
        fm_json(['ok' => true, 'stat' => [
            'name'  => basename($real), 'isDir' => $isDir,
            'mime'  => $isDir ? 'inode/directory' : (mime_content_type($real) ?: 'application/octet-stream'),
            'size'  => $isDir ? 0 : (filesize($real) ?: 0), 'mtime' => filemtime($real) ?: 0,
            'read'  => is_readable($real), 'write' => is_writable($real), 'path' => $rel,
            'owner' => $own, 'group' => $grp,
        ]]);

    case 'read':
        if (!is_file($real) || !is_readable($real)) fm_json(['ok' => false, 'error' => 'Not a readable file'], 400);
        $mime = mime_content_type($real) ?: 'application/octet-stream';
        if (!fm_is_text_mime($mime)) fm_json(['ok' => false, 'error' => 'Not a text-like file'], 400);
        $size = filesize($real) ?: 0;
        if ($size > FM_EDIT_MAX_BYTES) fm_json(['ok' => false, 'error' => 'File too large for editor'], 400);
        fm_json(['ok' => true, 'mime' => $mime, 'size' => $size, 'content' => file_get_contents($real), 'path' => $rel]);

    case 'write':
        if (!FM_ALLOW_TEXT_EDIT) fm_json(['ok' => false, 'error' => 'Editing disabled'], 403);
        if (!is_file($real) || !is_writable($real)) fm_json(['ok' => false, 'error' => 'Not writable'], 400);
        $content = (string)($_POST['content'] ?? '');
        if (strlen($content) > FM_EDIT_MAX_BYTES) fm_json(['ok' => false, 'error' => 'Content too large'], 400);
        fm_json(['ok' => (bool)@file_put_contents($real, $content), 'bytes' => strlen($content)]);

    case 'thumb_video':
        if (!FM_ENABLE_VIDEO_THUMBS) fm_json(['ok' => false, 'error' => 'Video thumbs disabled'], 403);
        if (!is_file($real)) fm_json(['ok' => false, 'error' => 'File missing'], 404);
        $mime = mime_content_type($real) ?: '';
        if (!str_starts_with($mime, 'video/')) fm_json(['ok' => false, 'error' => 'Not a video'], 400);
        $ff = fm_ffmpeg_path();
        if ($ff === null) fm_json(['ok' => false, 'error' => 'ffmpeg not found'], 400);
        $cache = fm_cache_dir('video');
        $hash  = md5($real);
        $jpg   = "$cache/vid_$hash.jpg";
        if (!file_exists($jpg) || filemtime($jpg) + 86400 < time()) {
            $cmd = escapeshellcmd($ff) . ' -ss 00:00:01 -i ' . escapeshellarg($real) . ' -frames:v 1 -vf "scale=320:-1" -y ' . escapeshellarg($jpg) . ' 2>&1';
            @shell_exec($cmd);
        }
        if (!file_exists($jpg)) fm_json(['ok' => false, 'error' => 'Thumb generation failed'], 500);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($jpg));
        readfile($jpg);
        exit;

    case 'thumb_audio':
        // Embedded cover-art thumbnail (ID3/APIC, no ffmpeg needed for MP3).
        if (!is_file($real)) fm_json(['ok' => false, 'error' => 'File missing'], 404);
        if (!mt_audio_is_supported($real)) fm_json(['ok' => false, 'error' => 'Not an audio file'], 400);
        $cache = fm_cache_dir('audio');
        $hash  = md5($real);
        $jpg   = "$cache/aud_$hash.jpg";
        if (!file_exists($jpg) || filemtime($jpg) < filemtime($real)) {
            try { mt_gen_audio_thumb($real, $jpg, 320); }
            catch (Throwable $e) { fm_json(['ok' => false, 'error' => 'No cover art'], 404); }
        }
        if (!file_exists($jpg)) fm_json(['ok' => false, 'error' => 'Thumb generation failed'], 500);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($jpg));
        readfile($jpg);
        exit;

    case 'backup':
        $kind   = (string)($_POST['kind'] ?? 'app');
        $format = (string)($_POST['format'] ?? FM_BACKUP_DEFAULT_FORMAT);
        $baseRoot = realpath(FM_BASE_ROOT) ?: '';
        if (!is_dir(FM_BACKUP_DIR)) @mkdir(FM_BACKUP_DIR, 0777, true);
        $stamp    = date('Ymd-His');
        $basename = "backup-{$kind}-{$stamp}";
        if ($kind === 'data') {
            $targets = [SITE_ROOT . '/admin/data'];
        } elseif ($kind === 'media') {
            $targets = [SITE_ROOT . '/media'];
        } else {
            $targets = [SITE_ROOT];
        }
        $ex = $GLOBALS['FM_BACKUP_EXCLUDES'][$kind] ?? [];
        $collect = function (array $roots, array $excl) use ($baseRoot): array {
            $out = [];
            foreach ($roots as $root) {
                $root = realpath($root);
                if (!$root) continue;
                if (is_dir($root)) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($it as $f) {
                        $rp = $f->getRealPath();
                        if ($rp === false) continue;
                        $rel = '/' . fm_rel_from_base($rp, $baseRoot);
                        $skip = false;
                        foreach ($excl as $e) {
                            if (str_starts_with($rel, $e)) { $skip = true; break; }
                        }
                        if (!$skip) $out[] = $rp;
                    }
                } else {
                    $out[] = $root;
                }
            }
            return $out;
        };
        $files = $collect($targets, $ex);
        if ($format === 'tar' && class_exists('PharData')) {
            $dest = FM_BACKUP_DIR . '/' . $basename . '.tar.gz';
            $okB  = fm_tar_create($dest, $files, $baseRoot);
        } else {
            $dest = FM_BACKUP_DIR . '/' . $basename . '.zip';
            $okB  = fm_zip_create($dest, $files, $baseRoot);
        }
        $archiveRel = fm_rel_from_base($dest, $baseRoot);
        fm_json(['ok' => $okB, 'archive' => $archiveRel]);

    case 'chown_fix':
        if (!FM_ENABLE_CHOWN) fm_json(['ok' => false, 'error' => 'Ownership fix disabled'], 403);
        $method = (string)($_POST['method'] ?? FM_CHOWN_METHOD);
        $res = fm_chown_tree(FM_BASE_ROOT, FM_CHOWN_USER, FM_CHOWN_GROUP, $GLOBALS['FM_CHOWN_EXCLUDES'] ?? [], $method);
        fm_json($res);

    case 'list_backups':
        $dir = FM_BACKUP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $out = [];
        foreach (glob($dir . '/*.{zip,tar.gz}', GLOB_BRACE) ?: [] as $p) {
            $out[] = [
                'name'  => basename($p),
                'path'  => fm_rel_from_base($p, FM_BASE_ROOT),
                'size'  => filesize($p) ?: 0,
                'mtime' => filemtime($p) ?: time(),
            ];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        fm_json(['ok' => true, 'items' => $out]);

    case 'disk_usage':
        $kind = (string)($_GET['kind'] ?? 'app');
        $calc = function (array $roots, array $ex) {
            $sum = 0;
            foreach ($roots as $root) {
                $root = realpath($root);
                if (!$root) continue;
                if (is_dir($root)) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($it as $f) {
                        $rp = $f->getRealPath();
                        if (!$rp) continue;
                        $rel = fm_rel_from_base($rp, FM_BASE_ROOT);
                        $skip = false;
                        foreach ($ex as $e) {
                            if (str_starts_with($rel, ltrim($e, '/'))) { $skip = true; break; }
                        }
                        if (!$skip && $f->isFile()) $sum += $f->getSize();
                    }
                } elseif (is_file($root)) {
                    $sum += filesize($root) ?: 0;
                }
            }
            return $sum;
        };
        if ($kind === 'data') {
            $roots = [SITE_ROOT . '/admin/data'];
        } elseif ($kind === 'media') {
            $roots = [SITE_ROOT . '/media'];
        } else {
            $roots = [SITE_ROOT];
        }
        $ex = $GLOBALS['FM_BACKUP_EXCLUDES'][$kind] ?? [];
        fm_json(['ok' => true, 'bytes' => $calc($roots, $ex)]);

    default:
        fm_json(['ok' => false, 'error' => 'Unknown action'], 400);
}
