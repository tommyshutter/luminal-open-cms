<?php
/**
 * Luminal CMS — Explorer core (shared media-ops library)
 * @file /admin/shared/explorer/explorer-core.php
 *
 * THE single backend library for /media file operations. Consumed by
 * ajax_media_handler.php (the Explorer's endpoint) and FileManagerFunctions
 * (generic helpers). Pure functions, no output, no auth — callers gate.
 *
 * Scope: /media/{images,videos,pdfs} only. Every path-taking function jails
 * its input to those roots. The `.cache/` thumbnail convention
 * (/media/.../folder/.cache/{base}.jpg — see MediaThumbs media-cache.php)
 * is honored everywhere: rename/move CARRY the thumb, delete DROPS it.
 *
 * All functions return data (usually ['success'=>bool,'message'=>string,…]);
 * HTTP/JSON concerns stay in the endpoints.
 */

declare(strict_types=1);

if (!function_exists('ex_site_root')) {
function ex_site_root(): string {
    // /admin/shared/explorer → site root
    return dirname(__DIR__, 3);
}
}

if (!function_exists('ex_media_types')) {
/** Canonical media types and their allowed extensions. */
function ex_media_types(): array {
    return [
        'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'videos' => ['mp4', 'webm', 'ogv'],
        'pdfs'   => ['pdf'],
        'audio'  => ['mp3', 'm4a', 'aac', 'flac', 'ogg', 'wav', 'wma'],
    ];
}
}

if (!function_exists('ex_type_dir')) {
/** Absolute dir for a media type ('' on unknown type). Trailing slash. */
function ex_type_dir(string $type): string {
    if (!isset(ex_media_types()[$type])) return '';
    return ex_site_root() . '/media/' . $type . '/';
}
}

if (!function_exists('ex_guard')) {
/**
 * Jail a site-relative path to the /media type roots.
 * Returns the absolute path, or null if it escapes /media/{images,videos,pdfs}.
 * (String-prefix check like the historical handler — the file may not exist
 * yet, so realpath() on the leaf is not an option; '..' is rejected outright.)
 */
function ex_guard(string $rel): ?string {
    if (strpos($rel, '..') !== false) return null;
    $root = ex_site_root();
    $abs  = $root . '/' . ltrim($rel, '/');
    foreach (array_keys(ex_media_types()) as $t) {
        // Trailing slash — 'media/imagesEVIL/x' must NOT match 'media/images'
        if (strpos($abs, $root . '/media/' . $t . '/') === 0) return $abs;
    }
    return null;
}
}

if (!function_exists('ex_hsize')) {
/** Human-readable byte size ("3.2 MB"). */
function ex_hsize(int $bytes): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return sprintf('%.1f %s', $n, $u[$i]);
}
}

if (!function_exists('ex_safename')) {
/** Sanitize a filename: basename only, word/dash/dot/space chars. */
function ex_safename(string $name): string {
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name) ?? $name;
    return $name === '' ? 'untitled' : $name;
}
}

if (!function_exists('ex_cache_thumb')) {
/** The .cache thumbnail path for a media file (may not exist). */
function ex_cache_thumb(string $absFile): string {
    return dirname($absFile) . '/.cache/' . pathinfo($absFile, PATHINFO_FILENAME) . '.jpg';
}
}

if (!function_exists('ex_carry_cache_thumb')) {
/** Move a file's .cache thumb alongside it (rename or cross-folder move). */
function ex_carry_cache_thumb(string $oldAbs, string $newAbs): void {
    $old = ex_cache_thumb($oldAbs);
    if (!is_file($old)) return;
    $newDir = dirname($newAbs) . '/.cache';
    if (!is_dir($newDir)) @mkdir($newDir, 0775, true);
    @rename($old, $newDir . '/' . pathinfo($newAbs, PATHINFO_FILENAME) . '.jpg');
}
}

if (!function_exists('ex_drop_cache_thumb')) {
/** Remove a file's .cache thumb (after the file itself is deleted). */
function ex_drop_cache_thumb(string $absFile): void {
    $t = ex_cache_thumb($absFile);
    if (is_file($t)) @unlink($t);
}
}

if (!function_exists('ex_copy_move')) {
/** Recursive copy or move. Pure paths — caller guards scope. */
function ex_copy_move(string $src, string $dst, bool $move = false): bool {
    if (is_dir($src)) {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true)) return false;
        $ok = true;
        $h  = opendir($src);
        if ($h === false) return false;
        while (($f = readdir($h)) !== false) {
            if ($f === '.' || $f === '..') continue;
            $ok = $ok && ex_copy_move($src . '/' . $f, $dst . '/' . $f, $move);
        }
        closedir($h);
        if ($move) @rmdir($src);
        return $ok;
    }
    return $move ? @rename($src, $dst) : @copy($src, $dst);
}
}

if (!function_exists('ex_delete_recursive')) {
/** Recursive delete (file or dir). Pure paths — caller guards scope. */
function ex_delete_recursive(string $p): bool {
    if (is_dir($p)) {
        $h = opendir($p);
        if ($h === false) return false;
        while (($f = readdir($h)) !== false) {
            if ($f === '.' || $f === '..') continue;
            $pp = "$p/$f";
            if (is_dir($pp)) {
                if (!ex_delete_recursive($pp)) { closedir($h); return false; }
            } else {
                if (!@unlink($pp)) { closedir($h); return false; }
            }
        }
        closedir($h);
        return @rmdir($p);
    }
    return @unlink($p);
}
}

if (!function_exists('ex_list_media')) {
/**
 * List all media of a type, grouped by immediate parent folder.
 * Returns ['media'=>[{path,name,size,mtime,folder[,w,h]}], 'folders'=>[{label,count,totalSize}]]
 * — the exact shape the Explorer frontend consumes.
 */
function ex_list_media(string $type): array {
    $targetDir = ex_type_dir($type);
    $siteRoot  = ex_site_root();
    $typeLabel = strtoupper($type);
    $media = [];

    if ($targetDir === '' || !is_dir($targetDir)) return ['media' => [], 'folders' => []];

    $allowed = ex_media_types()[$type];
    $folderMap = []; // folderKey => {label, count, totalSize}

    // Pre-seed with every top-level subdirectory so freshly created empty
    // folders still appear in the browser.
    foreach (new DirectoryIterator($targetDir) as $sub) {
        if ($sub->isDot() || !$sub->isDir()) continue;
        if ($sub->getBasename() === '.cache') continue;
        $folderKey = $typeLabel . '/' . strtoupper($sub->getBasename());
        if (!isset($folderMap[$folderKey])) {
            $folderMap[$folderKey] = ['label' => $folderKey, 'count' => 0, 'totalSize' => 0];
        }
    }

    $directory = new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS);
    $iterator  = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);

    foreach ($iterator as $file) {
        if ($file->getBasename() === '.cache' || strpos($file->getPathname(), '/.cache/') !== false) continue;
        if (!in_array(strtolower($file->getExtension()), $allowed, true)) continue;

        $relativePath = str_replace('\\', '/', str_replace($siteRoot . '/', '', $file->getPathname()));

        // Folder key: first path segment under the type dir, or "(root)"
        $relToTarget = str_replace(str_replace('\\', '/', $targetDir), '', str_replace('\\', '/', $file->getPathname()));
        $parts = explode('/', $relToTarget);
        $folderKey = count($parts) > 1
            ? $typeLabel . '/' . strtoupper($parts[0])
            : $typeLabel . ' (root)';

        if (!isset($folderMap[$folderKey])) {
            $folderMap[$folderKey] = ['label' => $folderKey, 'count' => 0, 'totalSize' => 0];
        }

        $fileSize = $file->getSize();
        $folderMap[$folderKey]['count']++;
        $folderMap[$folderKey]['totalSize'] += $fileSize;

        $entry = [
            'path'   => $relativePath,
            'name'   => $file->getFilename(),
            'size'   => $fileSize,
            'mtime'  => $file->getMTime(),
            'folder' => $folderKey,
        ];
        // Image dimensions — richer metadata for the Explorer list view.
        if ($type === 'images') {
            $dim = @getimagesize($file->getPathname());
            if (is_array($dim)) { $entry['w'] = $dim[0]; $entry['h'] = $dim[1]; }
        }
        $media[] = $entry;
    }

    ksort($folderMap);
    $folders = [];
    foreach ($folderMap as $info) {
        $folders[] = ['label' => $info['label'], 'count' => $info['count'], 'totalSize' => $info['totalSize']];
    }

    return ['media' => $media, 'folders' => $folders];
}
}

if (!function_exists('ex_list_folders')) {
/** Subfolders of a media type: [['path'=>rel,'label'=>…], …] with a root entry first. */
function ex_list_folders(string $type): array {
    $baseDir = ex_type_dir($type);
    if ($baseDir === '' || !is_dir($baseDir)) return [];
    $folders = [['path' => '', 'label' => strtoupper($type) . ' (root)']];
    $it  = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);
    $rit = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($rit as $f) {
        if (!$f->isDir()) continue;
        if ($f->getFilename() === '.cache') continue;
        $rel = str_replace($baseDir, '', $f->getPathname());
        $folders[] = ['path' => $rel, 'label' => $rel];
    }
    return $folders;
}
}

if (!function_exists('ex_create_folder')) {
function ex_create_folder(string $type, string $name): array {
    $type = preg_replace('/[^a-z]/', '', $type);
    $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', trim($name));
    if ($name === '') return ['success' => false, 'message' => 'Folder name required'];
    $baseDir = ex_type_dir($type);
    if ($baseDir === '') return ['success' => false, 'message' => 'Invalid media type'];
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);
    $dir = $baseDir . $name . '/';
    if (is_dir($dir)) return ['success' => false, 'message' => 'Folder already exists'];
    if (@mkdir($dir, 0775, true)) {
        return ['success' => true, 'message' => 'Folder "' . $name . '" created'];
    }
    return ['success' => false, 'message' => 'Could not create folder — check permissions'];
}
}

if (!function_exists('ex_upload')) {
/**
 * Store uploaded files ($_FILES['files'] shape) into a media type dir/subfolder.
 * Returns ['success'=>bool,'message'=>string,'errors'=>string[]].
 */
function ex_upload(string $type, string $sub, array $files): array {
    $uploadDir = ex_type_dir($type);
    if ($uploadDir === '') return ['success' => false, 'message' => 'Invalid media type', 'errors' => []];

    // Append subfolder (traversal-stripped), verify it stays inside /media.
    if ($sub !== '') {
        $sub = trim(str_replace('..', '', $sub), '/');
        if ($sub !== '') {
            $uploadDir = rtrim($uploadDir, '/') . '/' . $sub . '/';
            $realBase = realpath(dirname(rtrim($uploadDir, '/')));
            if ($realBase === false || strpos($realBase, realpath(ex_site_root() . '/media')) !== 0) {
                return ['success' => false, 'message' => 'Invalid upload path.', 'errors' => []];
            }
        }
    }
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

    if (!isset($files['name'])) {
        return ['success' => false, 'message' => 'No files were uploaded.', 'errors' => []];
    }

    $errors = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $fileName = basename($files['name'][$i]);
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = "Error with file {$fileName}: " . $files['error'][$i];
            continue;
        }
        if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . $fileName)) {
            $errors[] = "Failed to move uploaded file {$fileName}.";
        }
    }

    return empty($errors)
        ? ['success' => true,  'message' => 'All files uploaded successfully.', 'errors' => []]
        : ['success' => false, 'message' => 'Some files could not be uploaded.', 'errors' => $errors];
}
}

if (!function_exists('ex_delete')) {
/** Delete media files by site-relative paths. Drops orphaned .cache thumbs. */
function ex_delete(array $rels): array {
    $errors = [];
    foreach ($rels as $rel) {
        $abs = ex_guard((string)$rel);
        if ($abs === null) { $errors[] = "Security error: deletion outside media directories for {$rel}."; continue; }
        if (!file_exists($abs)) { $errors[] = "File not found: {$rel}."; continue; }
        if (!@unlink($abs)) { $errors[] = "Could not delete {$rel}. Check permissions."; continue; }
        ex_drop_cache_thumb($abs);
    }
    return empty($errors)
        ? ['success' => true,  'message' => 'Selected files deleted successfully.']
        : ['success' => false, 'message' => 'Some files could not be deleted.', 'errors' => $errors];
}
}

if (!function_exists('ex_delete_folder')) {
/**
 * Recursively delete a media SUBFOLDER (and everything inside it, incl. .cache
 * thumbs). Jailed to /media/{type}; refuses to delete a type root itself.
 *   $type = images|videos|pdfs ; $sub = subfolder path under that type (e.g. "test")
 */
function ex_delete_folder(string $type, string $sub): array {
    $typeDir = ex_type_dir($type);
    if ($typeDir === '') return ['success' => false, 'message' => 'Unknown media type.'];

    $sub = trim(str_replace('..', '', $sub), '/');
    if ($sub === '') return ['success' => false, 'message' => 'Refusing to delete the media root folder.'];

    $abs = ex_guard('media/' . $type . '/' . $sub);
    if ($abs === null) return ['success' => false, 'message' => 'Security error: folder is outside the media directories.'];

    // Never the type root, even if a crafted $sub normalises back up to it.
    if (rtrim($abs, '/') . '/' === $typeDir) {
        return ['success' => false, 'message' => 'Refusing to delete the media root folder.'];
    }
    if (!is_dir($abs)) return ['success' => false, 'message' => 'Folder not found.'];

    if (!ex_delete_recursive($abs)) {
        return ['success' => false, 'message' => 'Could not delete the folder. Check permissions.'];
    }
    return ['success' => true, 'message' => 'Folder “' . basename($abs) . '” and its contents were deleted.'];
}
}

if (!function_exists('ex_move')) {
/** Move media files to a subfolder of their own type dir. Carries .cache thumbs. */
function ex_move(array $rels, string $dest): array {
    $siteRoot = ex_site_root();
    $dest = trim(str_replace('..', '', $dest), '/');
    $errors = [];
    $moved = 0;

    foreach ($rels as $rel) {
        $abs = ex_guard((string)$rel);
        if ($abs === null) { $errors[] = "Security error: {$rel} is outside media directories."; continue; }

        // The file's own type root is the move base (no cross-type moves).
        // Trailing slash — same jail rule as ex_guard.
        $mediaBase = null;
        foreach (array_keys(ex_media_types()) as $t) {
            if (strpos($abs, $siteRoot . '/media/' . $t . '/') === 0) { $mediaBase = $siteRoot . '/media/' . $t; break; }
        }
        if ($mediaBase === null) { $errors[] = "Security error: {$rel} is outside media directories."; continue; }

        $destDir = $mediaBase . ($dest !== '' ? '/' . $dest : '');
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $realDest  = realpath($destDir);
        $realMedia = realpath($siteRoot . '/media');
        if ($realDest === false || $realMedia === false || strpos($realDest, $realMedia) !== 0) {
            $errors[] = "Security error: destination outside media for {$rel}.";
            continue;
        }

        $destFull = $destDir . '/' . basename($abs);
        if (!file_exists($abs))   { $errors[] = "File not found: {$rel}."; continue; }
        if (file_exists($destFull)) { $errors[] = 'Already exists at destination: ' . basename($abs); continue; }

        if (@rename($abs, $destFull)) {
            ex_carry_cache_thumb($abs, $destFull);
            $moved++;
        } else {
            $errors[] = "Failed to move {$rel}.";
        }
    }

    if (empty($errors)) return ['success' => true, 'message' => "Moved {$moved} file(s) successfully."];
    return [
        'success' => count($errors) < count($rels),
        'message' => "Moved {$moved}, errors: " . count($errors),
        'errors'  => $errors,
    ];
}
}

if (!function_exists('ex_rename')) {
/** Rename a media file in place. Preserves dropped extension, carries .cache thumb. */
function ex_rename(string $rel, string $newName): array {
    if ($rel === '' || $newName === '') return ['success' => false, 'message' => 'Missing path or name.'];

    $abs = ex_guard($rel);
    if ($abs === null) return ['success' => false, 'message' => 'Security error: outside media directories.'];
    if (!is_file($abs)) return ['success' => false, 'message' => 'File not found.'];

    if (trim($newName, "/\\ \t") === '') return ['success' => false, 'message' => 'Invalid name.'];
    $newName = ex_safename($newName);
    if ($newName === '.' || $newName === '..') return ['success' => false, 'message' => 'Invalid name.'];
    // Preserve original extension if the user dropped it.
    $origExt = pathinfo($abs, PATHINFO_EXTENSION);
    if ($origExt !== '' && pathinfo($newName, PATHINFO_EXTENSION) === '') {
        $newName .= '.' . $origExt;
    }

    $destFull = dirname($abs) . '/' . $newName;
    if (file_exists($destFull)) {
        return ['success' => false, 'message' => 'A file named "' . $newName . '" already exists here.'];
    }
    if (@rename($abs, $destFull)) {
        ex_carry_cache_thumb($abs, $destFull);
        return ['success' => true, 'message' => 'Renamed.', 'path' => str_replace(ex_site_root() . '/', '', $destFull)];
    }
    return ['success' => false, 'message' => 'Rename failed — check permissions.'];
}
}
