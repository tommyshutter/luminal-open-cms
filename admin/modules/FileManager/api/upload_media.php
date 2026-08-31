<?php
/**
 * @appname     Media Manager
 * @file        SITE-ROOT/admin/ajax/upload_media.php
 * @version     2025.09.11.1305.25-EST
 * @author      ChatGPT
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Single-file, dependency-light upload endpoint for the Media Manager.
 *              - Works for BOTH standard multi-file uploads and chunked uploads.
 *              - No external library dependencies. Auth is optional (auto-detect).
 *              - Writes into /media/{images|videos|pdfs}/<optional subfolders>.
 *              - Sanitizes filenames (fixes "string did not match pattern" issues).
 *              - Never throws a raw 500: returns JSON with a precise error message.
 *
 * Accepted fields (multipart/form-data):
 *   type        : images | videos | pdfs      (default: images)
 *   folder      : relative subfolder path     (e.g. "backgrounds" or "site/posters")
 *   files[]     : one or more uploaded files  (standard mode)
 *
 * Chunked mode (send one chunk per request):
 *   uploadId    : arbitrary string to identify this logical file (required)
 *   chunkIndex  : 0-based integer for this chunk
 *   totalChunks : total chunk count (>1)
 *   chunkSize   : size per chunk in bytes (optional)
 *   fileName    : original file name (will be sanitized)
 *   file        : the chunk payload (binary)
 *
 * JSON responses use: { success: bool, message: string, ... }
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Output as JSON, trap errors to prevent raw 500s
// -----------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
set_error_handler(function($severity, $message, $file, $line) {
    respond(['success'=>false, 'message'=>"PHP error: $message", 'where'=>basename($file).":$line"], 500);
});
set_exception_handler(function($ex){
    respond(['success'=>false, 'message'=>"Exception: ".$ex->getMessage()], 500);
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR))) {
        respond(['success'=>false, 'message'=>"Fatal: {$e['message']}", 'where'=>basename($e['file']).":{$e['line']}"], 500);
    }
});

// -----------------------------------------------------------------------------
// Utilities
// -----------------------------------------------------------------------------
function respond(array $payload, int $status = 200): never {
    if (!headers_sent()) http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** Convert shorthand like "128M" to bytes */
function toBytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $last = strtolower(substr($val, -1));
    $num  = (int)$val;
    return match ($last) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => (int)$val,
    };
}

/** SITE_ROOT autodetect (prefer the top-level that has /media) */
function detect_site_root(): string {
    if (defined('SITE_ROOT')) return SITE_ROOT;
    $cur = realpath(__DIR__);
    $best = null;
    while ($cur && $cur !== dirname($cur)) {
        if (is_dir($cur . '/media')) {
            $best = $cur;
            // prefer non-admin parent if it also has media
            $parent = dirname($cur);
            if ($parent && is_dir($parent.'/media') && basename($cur) === 'admin') {
                $best = $parent;
            }
        }
        $parent = dirname($cur);
        if ($parent === $cur || $parent === '' || $parent === '/') break;
        $cur = $parent;
    }
    return $best ?: realpath(dirname(__DIR__,4)) ?: dirname(__DIR__,4);
}

/** Sanitize a filename base (no directories, no extension) */
function sanitize_base(string $name): string {
    $name = preg_replace('/\s+/u', ' ', $name);          // squeeze spaces
    $name = preg_replace('/[^A-Za-z0-9._\\- ]+/', '_', $name); // safe chars
    $name = preg_replace('/ +/', '-', $name);            // spaces -> dash
    $name = preg_replace('/[\\._\\- ]+$/', '', $name);   // trim trailing junk
    $name = ltrim($name, '.');                           // no leading dot
    return $name !== '' ? $name : 'file';
}

/** Sanitize subfolder path (relative) */
function sanitize_folder(string $rel): string {
    $rel = trim($rel);
    if ($rel === '' || $rel === '/') return '';
    $parts = array_values(array_filter(explode('/', $rel), fn($p)=>$p!==''));
    $clean = [];
    foreach ($parts as $p) {
        $p = preg_replace('/[^A-Za-z0-9._\\-]+/', '_', $p);
        $p = trim($p, '.-_');
        if ($p === '' || $p === '.' || $p === '..') continue;
        $clean[] = $p;
    }
    return implode('/', $clean);
}

/** Allowed extensions per type */
function allowed_extensions(string $type): array {
    $map = [
        'images' => ['jpg','jpeg','png','gif','webp','svg'],
        'videos' => ['mp4','mov','webm','ogg','ogv','m4v'],
        'pdfs'   => ['pdf'],
    ];
    return $map[$type] ?? $map['images'];
}

/** Resolve target directories and ensure existence */
function resolve_target(string $root, string $type, string $folder): array {
    $type = in_array($type, ['images','videos','pdfs'], true) ? $type : 'images';
    $disk = rtrim($root, '/') . "/media/{$type}";
    if (!is_dir($disk)) @mkdir($disk, 0755, true);
    $web  = "/media/{$type}";
    if ($folder !== '') {
        $disk .= '/' . $folder;
        $web  .= '/' . $folder;
    }
    if (!is_dir($disk)) @mkdir($disk, 0755, true);
    return ['type'=>$type, 'disk'=>$disk, 'web'=>$web];
}

/** Next available path avoiding collisions (adds -1, -2, ...) */
function collision_path(string $dir, string $base, string $ext): string {
    $candidate = rtrim($dir,'/') . "/{$base}.{$ext}";
    $n = 1;
    while (file_exists($candidate) && $n < 1000) {
        $candidate = rtrim($dir,'/') . "/{$base}-{$n}.{$ext}";
        $n++;
    }
    return $candidate;
}

// -----------------------------------------------------------------------------
// Optional auth include (won't 500 if absent)
// -----------------------------------------------------------------------------
$auth = detect_site_root() . '/admin/auth.php';
if (is_file($auth)) {
    require_once $auth;
    if (function_exists('requireAuth')) {
        requireAuth();
    }
}

// -----------------------------------------------------------------------------
// Guard against POST too large (PHP will empty $_FILES/$_POST)
// -----------------------------------------------------------------------------
$clen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$pmax = toBytes((string)ini_get('post_max_size'));
if ($pmax > 0 && $clen > $pmax) {
    respond(['success'=>false, 'message'=>"Request exceeds post_max_size (".ini_get('post_max_size').")."], 413);
}

// -----------------------------------------------------------------------------
// Inputs
// -----------------------------------------------------------------------------
$SITE_ROOT = detect_site_root();
$type   = strtolower((string)($_POST['type'] ?? 'images'));
$folder = sanitize_folder((string)($_POST['folder'] ?? ''));

// Chunked mode fields
$uploadId    = isset($_POST['uploadId']) ? (string)$_POST['uploadId'] : null;
$chunkIndex  = isset($_POST['chunkIndex']) ? (int)$_POST['chunkIndex'] : null;
$totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : null;
$fileName    = isset($_POST['fileName']) ? (string)$_POST['fileName'] : null;

// Target dir
$target = resolve_target($SITE_ROOT, $type, $folder);
if (!is_writable($target['disk'])) {
    respond(['success'=>false, 'message'=>"Destination not writable: {$target['disk']}"], 500);
}

// Temporary chunk directory (inside admin/data/tmp to avoid /tmp cleaners)
$tmpDir = $SITE_ROOT . '/admin/data/tmp/chunks';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

// Max caps (informational)
$capUpload = toBytes((string)ini_get('upload_max_filesize'));
$capPost   = toBytes((string)ini_get('post_max_size'));
$maxCap    = min(array_filter([$capUpload, $capPost], fn($x)=>$x>0)) ?: ($capUpload ?: $capPost);

// -----------------------------------------------------------------------------
// CHUNKED MODE
// -----------------------------------------------------------------------------
if ($uploadId !== null && $totalChunks !== null && $fileName !== null && isset($_FILES['file'])) {
    $ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $base = sanitize_base(pathinfo($fileName, PATHINFO_FILENAME));
    if (!in_array($ext, allowed_extensions($type), true)) {
        respond(['success'=>false,'message'=>"Disallowed type: .$ext"], 400);
    }

    $uDir = $tmpDir . '/' . preg_replace('/[^A-Za-z0-9_\\-]/', '_', $uploadId);
    if (!is_dir($uDir)) @mkdir($uDir, 0755, true);

    $chunkPath = $uDir . "/chunk-{$chunkIndex}.part";
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath)) {
        respond(['success'=>false,'message'=>"Failed to store chunk {$chunkIndex}."], 500);
    }

    // If last chunk, assemble
    if ($chunkIndex + 1 === $totalChunks) {
        $finalPath = collision_path($target['disk'], $base, $ext);
        $out = @fopen($finalPath, 'wb');
        if (!$out) respond(['success'=>false, 'message'=>'Failed to create final file.'], 500);

        for ($i = 0; $i < $totalChunks; $i++) {
            $part = $uDir . "/chunk-{$i}.part";
            $in = @fopen($part, 'rb');
            if (!$in) { fclose($out); respond(['success'=>false,'message'=>"Missing chunk {$i} during assembly."], 500); }
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($part);
        }
        fclose($out);
        @rmdir($uDir);

        respond([
            'success' => true,
            'message' => 'Upload complete (chunked).',
            'uploaded' => [[
                'name'  => basename($finalPath),
                'path'  => ($folder !== '' ? $folder.'/' : '') . basename($finalPath),
                'url'   => rtrim($target['web'],'/') . '/' . rawurlencode(basename($finalPath)),
                'size'  => @filesize($finalPath),
                'mtime' => @filemtime($finalPath),
            ]],
            'limits' => ['upload_max_filesize'=>ini_get('upload_max_filesize'), 'post_max_size'=>ini_get('post_max_size')],
        ]);
    } else {
        respond(['success'=>true, 'message'=>"Chunk {$chunkIndex} received." ]);
    }
}

// -----------------------------------------------------------------------------
// STANDARD MULTI-FILE MODE
// -----------------------------------------------------------------------------
if (!isset($_FILES['files']) && !isset($_FILES['file'])) {
    respond(['success'=>false, 'message'=>'No files received.'], 400);
}
$filesSpec = $_FILES['files'] ?? $_FILES['file'];

$names = is_array($filesSpec['name']) ? $filesSpec['name'] : [$filesSpec['name']];
$tmpns = is_array($filesSpec['tmp_name']) ? $filesSpec['tmp_name'] : [$filesSpec['tmp_name']];
$errs  = is_array($filesSpec['error']) ? $filesSpec['error'] : [$filesSpec['error']];
$szs   = is_array($filesSpec['size']) ? $filesSpec['size'] : [$filesSpec['size']];

$uploaded = [];
$errors   = [];

for ($i=0; $i<count($names); $i++) {
    $orig = (string)$names[$i];
    $tmp  = (string)$tmpns[$i];
    $err  = (int)$errs[$i];
    $size = (int)$szs[$i];

    if ($err !== UPLOAD_ERR_OK) { $errors[] = "{$orig}: upload error code {$err}"; continue; }
    if ($maxCap > 0 && $size > $maxCap) { $errors[] = "{$orig}: exceeds server upload limit."; continue; }
    if (!is_uploaded_file($tmp)) { $errors[] = "{$orig}: not a valid uploaded file."; continue; }

    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $base = sanitize_base(pathinfo($orig, PATHINFO_FILENAME));
    if (!in_array($ext, allowed_extensions($type), true)) { $errors[] = "{$orig}: disallowed type."; continue; }

    $final = collision_path($target['disk'], $base, $ext);
    if (!@move_uploaded_file($tmp, $final)) { $errors[] = "{$orig}: failed to move uploaded file."; continue; }

    $uploaded[] = [
        'name'  => basename($final),
        'path'  => ($folder !== '' ? $folder.'/' : '') . basename($final),
        'url'   => rtrim($target['web'],'/') . '/' . rawurlencode(basename($final)),
        'size'  => @filesize($final),
        'mtime' => @filemtime($final),
    ];
}

// Final response
if ($errors && !$uploaded) {
    respond(['success'=>false, 'message'=>'Upload failed.', 'errors'=>$errors], 400);
} elseif ($errors) {
    respond(['success'=>true, 'message'=>'Uploaded with some errors.', 'uploaded'=>$uploaded, 'errors'=>$errors], 207);
} else {
    respond(['success'=>true, 'message'=>'Upload complete.', 'uploaded'=>$uploaded,
        'limits'=>['upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size')]
    ]);
}
?>