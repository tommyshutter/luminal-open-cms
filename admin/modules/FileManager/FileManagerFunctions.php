<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FileManager v1.0.0
 * File: FileManagerFunctions.php
 *
 * Merged from: includes/common.php + includes/zip.php
 */
declare(strict_types=1);

require_once __DIR__ . '/FileManagerConfig.php';

/* ======================================================================= */
/* Response / Auth Helpers                                                 */
/* ======================================================================= */

require_once dirname(__DIR__, 2) . '/shared/explorer/explorer-core.php';   // shared media-ops core (ex_*)

function fm_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fm_json(array $p, int $c = 200): void {
    http_response_code($c);
    header('Content-Type: application/json');
    echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fm_get_user_role(): string {
    $user = guard_user();
    return $user['role'] ?? 'staff';
}

/* ======================================================================= */
/* Role-Based Access                                                       */
/* ======================================================================= */

function fm_load_role_config(): array {
    $dir = SITE_ROOT . '/admin/data/FileManager';
    $file = $dir . '/config.json';
    if (is_file($file)) {
        $cfg = json_decode(file_get_contents($file), true);
        if (is_array($cfg) && !empty($cfg['role_paths'])) return $cfg;
    }
    // Sensible defaults when config.json is missing — admin gets full access
    return [
        'role_paths' => [
            'superadmin' => ['allowed' => ['*']],
            'admin'      => ['allowed' => ['*']],
            'staff'      => ['allowed' => ['/media']],
        ],
        'default_role' => 'staff',
    ];
}

function fm_get_allowed_paths(string $role): array {
    $cfg = fm_load_role_config();
    $rolePaths = $cfg['role_paths'][$role] ?? $cfg['role_paths'][$cfg['default_role'] ?? 'staff'] ?? [];
    $allowed = $rolePaths['allowed'] ?? ['/media'];
    if (in_array('*', $allowed, true)) return ['*'];
    return $allowed;
}

/* True superadmin only — guard_user() normalizes superadmin→'admin' in ['role'],
   but preserves the real role in ['luminal_role']. Full Tree View is gated here. */
function fm_is_superadmin(): bool {
    $u = guard_user();
    return is_array($u) && (($u['luminal_role'] ?? '') === 'superadmin');
}

/* Full Tree View = opt-in, SUPERADMIN-ONLY toggle (session-flagged via the
   set_view_scope api action). Default view for everyone — including admins — is
   jailed to /media, so all other top-level folders are invisible. */
function fm_full_tree_active(): bool {
    return fm_is_superadmin() && !empty($_SESSION['fm_full_tree']);
}

/* The allowed paths in effect for THIS request, after the scope toggle.
   Default = /media only; full tree (the role grant) only when a superadmin has
   opted in. fm_guard_path() (every op's chokepoint) uses this. */
function fm_effective_allowed_paths(): array {
    if (fm_full_tree_active()) {
        return fm_get_allowed_paths(fm_get_user_role());  // role grant (['*'] for admin/superadmin)
    }
    return ['/media'];
}

function fm_is_path_allowed(string $relPath, string $role): bool {
    $allowed = fm_effective_allowed_paths();   // scope-aware (default /media, full-tree=superadmin opt-in)
    if (in_array('*', $allowed, true)) return true;

    $relPath = '/' . ltrim($relPath, '/');
    foreach ($allowed as $ap) {
        $ap = '/' . ltrim($ap, '/');
        if ($relPath === $ap || str_starts_with($relPath, $ap . '/')) return true;
    }
    return false;
}

/* ======================================================================= */
/* Path Guards                                                             */
/* ======================================================================= */

function fm_guard_path(string $relPath): array {
    $rel    = ltrim($relPath, '/');
    $start  = FM_START_PATH === '' ? '' : (trim(FM_START_PATH, '/') . '/');
    $joined = rtrim(FM_BASE_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $start . $rel;
    $real   = realpath($joined);
    $base   = realpath(FM_BASE_ROOT);

    if ($real === false || $base === false || !str_starts_with($real, $base)) {
        return [false, null, null];
    }

    $role = fm_get_user_role();
    if (!fm_is_path_allowed($rel, $role)) {
        return [false, null, null];
    }

    return [true, $real, $base];
}

function fm_rel_from_base(string $abs, string $base = ''): string {
    $b = $base ?: (realpath(FM_BASE_ROOT) ?: '');
    return ltrim(str_replace($b, '', $abs), DIRECTORY_SEPARATOR);
}

/* ======================================================================= */
/* Utility Functions                                                       */
/* ======================================================================= */

function fm_hsize(int $bytes): string {
    return ex_hsize($bytes);   // canonical impl in explorer-core
}

function fm_is_text_mime(string $m): bool {
    $m = strtolower($m);
    return str_starts_with($m, 'text/') || str_contains($m, 'json') || str_contains($m, 'xml') ||
           str_contains($m, 'x-httpd-php') || str_contains($m, 'x-php') ||
           str_contains($m, 'javascript') || str_contains($m, 'css') ||
           str_contains($m, 'markdown') || str_contains($m, 'svg');
}

function fm_cache_dir(string $sub): string {
    $root = SITE_ROOT . '/admin/data/FileManager/.cache';
    $p = $root . '/' . trim($sub, '/');
    if (!is_dir($p)) @mkdir($p, 0775, true);
    return $p;
}

function fm_ffmpeg_path(): ?string {
    $c = [];
    if (FM_FFMPEG !== '') $c[] = FM_FFMPEG;
    $c[] = trim((string)@shell_exec('command -v ffmpeg'));
    foreach ($c as $p) {
        if ($p && file_exists($p) && is_executable($p)) return $p;
    }
    return null;
}

function fm_safename(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name) ?? $name;
    return $name === '' ? 'untitled' : $name;
}

function fm_owner_group(string $path): array {
    $uid = @fileowner($path);
    $gid = @filegroup($path);
    $owner = is_int($uid) && function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? (string)$uid) : (string)$uid;
    $group = is_int($gid) && function_exists('posix_getgrgid') ? (posix_getgrgid($gid)['name'] ?? (string)$gid) : (string)$gid;

    if (($owner === '' || $group === '') || ($owner === '0' && $group === '0')) {
        $out = trim((string)@shell_exec('stat -c %U:%G ' . escapeshellarg($path) . ' 2>/dev/null'));
        if ($out === '') $out = trim((string)@shell_exec('stat -f %Su:%Sg ' . escapeshellarg($path) . ' 2>/dev/null'));
        if ($out !== '') { [$owner, $group] = array_pad(explode(':', $out, 2), 2, ''); }
    }
    return [$owner ?: '?', $group ?: '?'];
}

/* ======================================================================= */
/* Directory Listing                                                       */
/* ======================================================================= */

function fm_list_dir(string $realDir): array {
    $items = [];
    $dir = @opendir($realDir);
    if ($dir === false) return $items;

    while (($name = readdir($dir)) !== false) {
        if ($name === '.' || $name === '..') continue;
        foreach ($GLOBALS['FM_HIDDEN_PATTERNS'] as $rx) {
            if (preg_match($rx, $name)) continue 2;
        }
        $path  = $realDir . DIRECTORY_SEPARATOR . $name;
        $isDir = is_dir($path);
        $size  = $isDir ? 0 : (filesize($path) ?: 0);
        $mtime = filemtime($path) ?: 0;
        $mime  = $isDir ? 'inode/directory' : (mime_content_type($path) ?: 'application/octet-stream');
        [$own, $grp] = fm_owner_group($path);
        $items[] = ['name' => $name, 'isDir' => $isDir, 'size' => $size, 'mtime' => $mtime, 'mime' => $mime, 'owner' => $own, 'group' => $grp];
    }
    closedir($dir);
    usort($items, function ($a, $b) {
        if ($a['isDir'] && !$b['isDir']) return -1;
        if (!$a['isDir'] && $b['isDir']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function fm_dirs_only(string $realDir): array {
    $out = [];
    $h = @opendir($realDir);
    if ($h === false) return $out;

    while (($name = readdir($h)) !== false) {
        if ($name === '.' || $name === '..') continue;
        foreach ($GLOBALS['FM_HIDDEN_PATTERNS'] as $rx) {
            if (preg_match($rx, $name)) continue 2;
        }
        $p = $realDir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($p)) continue;
        $has = false;
        $s = @opendir($p);
        if ($s !== false) {
            while (($n = readdir($s)) !== false) {
                if ($n === '.' || $n === '..') continue;
                if (is_dir("$p/$n")) { $has = true; break; }
            }
            closedir($s);
        }
        $out[] = ['name' => $name, 'hasChildren' => $has, 'mtime' => filemtime($p) ?: 0];
    }
    closedir($h);
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/* ======================================================================= */
/* File Operations                                                         */
/* ======================================================================= */

function fm_copy_move(string $src, string $dst, bool $move = false): bool {
    return ex_copy_move($src, $dst, $move);   // canonical impl in explorer-core
}

function fm_delete_recursive(string $p): bool {
    return ex_delete_recursive($p);   // canonical impl in explorer-core
}

/* ======================================================================= */
/* Ownership Fix                                                           */
/* ======================================================================= */

function fm_chown_tree(string $rootAbs, string $user, string $group, array $excludes, string $method = 'auto'): array {
    $base = realpath(FM_BASE_ROOT) ?: '';
    $root = realpath($rootAbs) ?: '';
    if ($root === '' || !str_starts_with($root, $base)) {
        return ['ok' => false, 'error' => 'Out of bounds', 'method' => $method, 'changed' => 0, 'errors' => 0];
    }

    $skip = function (string $abs) use ($base, $excludes): bool {
        $rel = '/' . ltrim(str_replace($base, '', $abs), '/');
        foreach ($excludes as $ex) { if (str_starts_with($rel, $ex)) return true; }
        return false;
    };

    $prefer = $method === 'auto'
        ? (function_exists('chown') && function_exists('chgrp') ? 'php' : 'shell')
        : $method;

    if ($prefer === 'php') {
        $changed = 0;
        $errors  = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $paths = [$root];
        foreach ($it as $f) { $rp = $f->getRealPath(); if ($rp !== false) $paths[] = $rp; }
        foreach ($paths as $p) {
            if ($skip($p)) continue;
            $ok1 = @chown($p, $user);
            $ok2 = @chgrp($p, $group);
            if ($ok1 && $ok2) $changed++; else $errors++;
        }
        return ['ok' => $errors === 0, 'changed' => $changed, 'errors' => $errors, 'method' => 'php'];
    }

    $chownBin = trim((string)@shell_exec('command -v chown'));
    if ($chownBin === '') return ['ok' => false, 'error' => 'chown not available', 'method' => 'shell', 'changed' => 0, 'errors' => 1];

    $cmd = (FM_CHOWN_USE_SUDO ? 'sudo ' : '') . escapeshellcmd($chownBin) .
           ' -R ' . escapeshellarg($user . ':' . $group) . ' ' . escapeshellarg($root) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    return ['ok' => $code === 0, 'changed' => 0, 'errors' => $code, 'method' => 'shell', 'exit' => $code, 'output' => implode("\n", $out)];
}

/* ======================================================================= */
/* ZIP / TAR Helpers                                                       */
/* ======================================================================= */

function fm_zip_create(string $zipPath, array $absPaths, string $baseRoot): bool {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    foreach ($absPaths as $p) {
        $real = realpath($p);
        if ($real === false || !str_starts_with($real, $baseRoot)) continue;
        $local = ltrim(str_replace($baseRoot, '', $real), DIRECTORY_SEPARATOR);
        if (is_dir($real)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $rf = $file->getRealPath();
                if ($rf === false) continue;
                $entry = ltrim(str_replace($baseRoot, '', $rf), DIRECTORY_SEPARATOR);
                if ($file->isDir()) $zip->addEmptyDir($entry);
                else $zip->addFile($rf, $entry);
            }
        } else {
            $zip->addFile($real, $local);
        }
    }
    return $zip->close();
}

function fm_tar_create(string $tgzPath, array $absPaths, string $baseRoot): bool {
    if (!class_exists('PharData')) return false;
    $tarPath = preg_replace('/\.tar\.gz$/', '.tar', $tgzPath);
    if (file_exists($tarPath)) @unlink($tarPath);
    if (file_exists($tgzPath)) @unlink($tgzPath);
    try {
        $tar = new PharData($tarPath);
        foreach ($absPaths as $p) {
            $real = realpath($p);
            if ($real === false || !str_starts_with($real, $baseRoot)) continue;
            $local = ltrim(str_replace($baseRoot, '', $real), DIRECTORY_SEPARATOR);
            if (is_dir($real)) $tar->buildFromDirectory($real, '/.*/');
            else $tar->addFile($real, $local);
        }
        $tar->compress(Phar::GZ);
        unset($tar);
        if (file_exists($tarPath)) @unlink($tarPath);
        return file_exists($tgzPath);
    } catch (Throwable $e) {
        return false;
    }
}
