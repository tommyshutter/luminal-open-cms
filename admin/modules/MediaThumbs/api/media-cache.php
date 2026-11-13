<?php
/**
 * @file /admin/ajax/media-cache.php
 * @version 2025.09.05.batch+progress.folder-local
 *
 * Actions (POST/GET):
 *   - ensure_thumb             &kind=image|video|pdf &src=/media/.../file.ext
 *   - flush_caches
 *   - rebuild_all              (SKIPS PDFs)
 *   - flush_folder             &folder=/media/…
 *   - rebuild_folder           &folder=/media/…      (SKIPS PDFs)
 *   - scan_targets             &scope=all|folder [&folder=/media/…] [&types=images,videos,pdfs]
 *   - build_batch              files[]=/media/.../a.jpg  (or files as JSON string)
 *   - folder_info              &folder=/media/…   -> {cache_size, counts:{images,videos,pdfs}}
 *
 * Thumbs are saved next to the source folder:
 *   /media/.../SOMEFOLDER/file.ext -> /media/.../SOMEFOLDER/.cache/file.jpg
 *
 * Notes:
 *   - Mass endpoints (rebuild_all, rebuild_folder, build_batch) SKIP PDFs by design
 *   - Single ensure_thumb supports pdf and will error cleanly if Imagick missing
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// Shared embedded-cover-art extractor (audio thumbnails).
require_once dirname(__DIR__) . '/lib/audio-cover.php';

// ── Auth gate (2026-06-05): admin session required. This is a fetch/XHR
// endpoint, so answer 401 JSON rather than requireAuth()'s login redirect —
// mirrors its checks (multi-user + active validation, legacy fallback).
require_once dirname(__DIR__, 3) . '/auth.php';
$mcAuthed = false;
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    $mcUser = findUserById($_SESSION['user_id']);
    $mcAuthed = $mcUser && (($mcUser['status'] ?? '') === 'active');
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $mcAuthed = true; // legacy single-user session
}
if (!$mcAuthed) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}
unset($mcAuthed, $mcUser);
// Release the session lock — thumb generation (ffmpeg/Imagick) can take
// seconds and would otherwise serialize every other request on this session.
session_write_close();

try {
    $ADMIN_DIR = realpath(dirname(__DIR__, 3)); // /admin
    if ($ADMIN_DIR === false) throw new RuntimeException('Cannot resolve admin path.');
    $SITE_BASE = dirname($ADMIN_DIR);       // site root

    // ---------- helpers ----------
    function ri_json_out(bool $ok, array $extra = []): void {
        echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    function ri_bad(string $msg, array $extra = []): void {
        http_response_code(400);
        ri_json_out(false, array_merge(['message' => $msg], $extra));
    }

    function ri_ext(string $p): string { return strtolower(pathinfo($p, PATHINFO_EXTENSION)); }
    function ri_base_no_ext(string $p): string { return preg_replace('/\.[^.]+$/', '', pathinfo($p, PATHINFO_BASENAME)); }
    function ri_ensure_dir(string $d): void { if (!is_dir($d)) @mkdir($d, 0775, true); }

    function ri_has_exec(string $bin): bool {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        foreach ($paths as $p) {
            $c = rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bin;
            if (is_file($c) && is_executable($c)) return true;
        }
        foreach (['/usr/local/bin','/usr/bin','/bin'] as $p) {
            $c = $p . DIRECTORY_SEPARATOR . $bin;
            if (is_file($c) && is_executable($c)) return true;
        }
        return false;
    }

    /** Convert site-absolute or same-host URL to FS path under /media */
    function ri_web_to_fs_under_media(string $in, string $SITE_BASE): array {
        $path = trim($in);
        if ($path === '') ri_bad('Missing path.');
        if (preg_match('~^https?://~i', $path)) {
            $u = parse_url($path);
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!isset($u['host']) || strcasecmp($u['host'], $host) !== 0) ri_bad('Cross-host not allowed.');
            $path = $u['path'] ?? '';
        }
        if ($path === '' || $path[0] !== '/') ri_bad('Path must be site-absolute.');
        if (strpos($path, '/media/') !== 0)    ri_bad('Path must be under /media/.');
        $fs = $SITE_BASE . $path;
        return [$path, $fs];
    }

    /** Decide thumb location next to source folder: /media/foo/bar.ext -> /media/foo/.cache/bar.jpg */
    function ri_cache_target_local(string $srcWeb, string $SITE_BASE): array {
        $dirWeb  = rtrim(dirname($srcWeb), '/');
        // Refuse to thumb anything already inside a .cache/ segment — prevents .cache/.cache/ nesting
        // when a scanner accidentally hands us a thumb path as its own source.
        if (str_contains($dirWeb . '/', '/.cache/')) {
            throw new RuntimeException('Refusing to thumb a path inside .cache/: ' . $srcWeb);
        }
        $dstDirW = $dirWeb . '/.cache';
        $dstDirF = $SITE_BASE . $dstDirW;
        ri_ensure_dir($dstDirF);
        $baseNo  = ri_base_no_ext($srcWeb);
        $dstFs   = $dstDirF . '/' . $baseNo . '.jpg';
        $dstWeb  = $dstDirW . '/' . $baseNo . '.jpg';
        return [$dstFs, $dstWeb];
    }

    // ---------- generators (throw exceptions; no exit here) ----------
    function ri_gen_image_thumb(string $srcFs, string $dstFs, int $maxW = 640): void {
        $e = ri_ext($srcFs);
        switch ($e) {
            case 'jpg': case 'jpeg': $im = @imagecreatefromjpeg($srcFs); break;
            case 'png':              $im = @imagecreatefrompng($srcFs);  break;
            case 'gif':              $im = @imagecreatefromgif($srcFs);  break;
            case 'webp':             $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcFs) : null; break;
            default: throw new RuntimeException('Unsupported image or GD missing.');
        }
        if (!$im) throw new RuntimeException('Failed to load image.');
        $w = imagesx($im); $h = imagesy($im);
        if ($w <= 0 || $h <= 0) { imagedestroy($im); throw new RuntimeException('Invalid image dimensions.'); }
        if ($w > $maxW) { $nw = $maxW; $nh = (int)round($h * ($maxW / $w)); } else { $nw = $w; $nh = $h; }
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $im, 0,0, 0,0, $nw,$nh, $w,$h);
        if (!@imagejpeg($out, $dstFs, 85)) { imagedestroy($im); imagedestroy($out); throw new RuntimeException('Failed to write image thumb.'); }
        imagedestroy($im); imagedestroy($out);
        @chmod($dstFs, 0664);
    }

    function ri_gen_video_thumb(string $srcFs, string $dstFs, int $sec = 1, int $width = 640): void {
        if (!ri_has_exec('ffmpeg')) throw new RuntimeException('ffmpeg not found on server.');
        $cmd = sprintf(
            '%s -hide_banner -loglevel error -y -ss %d -i %s -frames:v 1 -vf scale=%d:-1 %s',
            escapeshellcmd('ffmpeg'), max(0, $sec), escapeshellarg($srcFs), $width, escapeshellarg($dstFs)
        );
        exec($cmd, $o, $code);
        if ($code !== 0 || !is_file($dstFs)) throw new RuntimeException('ffmpeg failed to create thumbnail.');
        @chmod($dstFs, 0664);
    }

    function ri_gen_pdf_thumb(string $srcFs, string $dstFs, int $page = 0, int $width = 640): void {
        // Try Imagick first
        if (class_exists('Imagick')) {
            $im = new Imagick();
            $im->setResolution(144, 144);
            if ($im->readImage($srcFs . '['.$page.']')) {
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);
                $im->thumbnailImage($width, 0);
                if ($im->writeImage($dstFs)) { $im->clear(); $im->destroy(); @chmod($dstFs, 0664); return; }
            }
            $im->clear(); $im->destroy();
        }

        // Try pdftoppm (poppler-utils)
        if (ri_has_exec('pdftoppm')) {
            $tmpPrefix = sys_get_temp_dir() . '/ri_pdf_' . uniqid();
            $cmd = sprintf('pdftoppm -jpeg -f %d -l %d -scale-to %d %s %s',
                $page + 1, $page + 1, $width, escapeshellarg($srcFs), escapeshellarg($tmpPrefix));
            exec($cmd, $o, $code);
            // pdftoppm appends -01.jpg or -1.jpg
            $candidates = glob($tmpPrefix . '*.jpg');
            if ($code === 0 && !empty($candidates)) {
                rename($candidates[0], $dstFs);
                foreach ($candidates as $c) @unlink($c); // clean leftover
                @chmod($dstFs, 0664);
                return;
            }
        }

        // Try ghostscript
        if (ri_has_exec('gs')) {
            $cmd = sprintf('gs -dBATCH -dNOPAUSE -sDEVICE=jpeg -dFirstPage=%d -dLastPage=%d -r144 -dJPEGQ=85 -sOutputFile=%s %s 2>/dev/null',
                $page + 1, $page + 1, escapeshellarg($dstFs), escapeshellarg($srcFs));
            exec($cmd, $o, $code);
            if ($code === 0 && is_file($dstFs)) { @chmod($dstFs, 0664); return; }
        }

        // Fallback: generate a branded placeholder thumbnail using GD
        ri_gen_pdf_placeholder_thumb($srcFs, $dstFs, $width);
    }

    function ri_gen_pdf_placeholder_thumb(string $srcFs, string $dstFs, int $width = 640): void {
        $h = (int)round($width * 1.294); // A4 ratio
        $im = imagecreatetruecolor($width, $h);
        $bg     = imagecolorallocate($im, 30, 41, 59);    // dark slate
        $accent = imagecolorallocate($im, 244, 63, 94);   // red accent
        $white  = imagecolorallocate($im, 241, 245, 249);
        $muted  = imagecolorallocate($im, 148, 163, 184);
        imagefilledrectangle($im, 0, 0, $width, $h, $bg);

        // Red top bar
        imagefilledrectangle($im, 0, 0, $width, 8, $accent);

        // PDF icon area (large centered rectangle)
        $iconW = (int)($width * 0.35);
        $iconH = (int)($iconW * 1.3);
        $ix = (int)(($width - $iconW) / 2);
        $iy = (int)(($h - $iconH) / 2) - 30;
        // Document shape
        $docBg = imagecolorallocate($im, 45, 55, 72);
        imagefilledrectangle($im, $ix, $iy, $ix + $iconW, $iy + $iconH, $docBg);
        // Corner fold
        $foldSize = (int)($iconW * 0.2);
        $foldBg = imagecolorallocate($im, 60, 75, 95);
        imagefilledpolygon($im, [
            $ix + $iconW - $foldSize, $iy,
            $ix + $iconW, $iy + $foldSize,
            $ix + $iconW, $iy,
        ], 3, $foldBg);
        // "PDF" text
        $fontSize = 5; // GD built-in font
        $pdfLabel = 'PDF';
        $tw = imagefontwidth($fontSize) * strlen($pdfLabel);
        imagestring($im, $fontSize, (int)(($width - $tw) / 2), $iy + (int)($iconH / 2) - 8, $pdfLabel, $accent);

        // Filename at bottom
        $name = pathinfo($srcFs, PATHINFO_FILENAME);
        if (strlen($name) > 40) $name = substr($name, 0, 37) . '...';
        $tw2 = imagefontwidth(3) * strlen($name);
        imagestring($im, 3, (int)(($width - $tw2) / 2), $iy + $iconH + 20, $name, $muted);

        if (!@imagejpeg($im, $dstFs, 90)) { imagedestroy($im); throw new RuntimeException('Failed to write PDF placeholder thumb.'); }
        imagedestroy($im);
        @chmod($dstFs, 0664);
    }

    function ri_kind_from_ext(string $fsPath): ?string {
        $e = ri_ext($fsPath);
        if (in_array($e, ['jpg','jpeg','png','gif','webp'], true)) return 'image';
        if (in_array($e, ['mp4','mov','m4v','webm','ogg'], true))  return 'video';
        if ($e === 'pdf')                                          return 'pdf';
        return null;
    }

    function ri_ensure_thumb_local(string $srcWeb, string $srcFs, string $SITE_BASE, ?string $forceKind = null): array {
        $kind = $forceKind ?: ri_kind_from_ext($srcFs);
        if (!$kind) throw new RuntimeException('Unsupported type.');
        [$dstFs, $dstWeb] = ri_cache_target_local($srcWeb, $SITE_BASE);
        if (!is_file($dstFs)) {
            if     ($kind === 'image') ri_gen_image_thumb($srcFs, $dstFs, 640);
            elseif ($kind === 'video') ri_gen_video_thumb($srcFs, $dstFs, 1, 640);
            elseif ($kind === 'audio') mt_gen_audio_thumb($srcFs, $dstFs, 640);
            else                       ri_gen_pdf_thumb($srcFs, $dstFs, 0, 640);
        }
        return ['thumb_url' => $dstWeb];
    }

    // ---------- scanning / flushing ----------
    function ri_collect_under(string $folderFs, string $SITE_BASE): array {
        $out = ['images'=>[], 'videos'=>[], 'pdfs'=>[]];
        if (!is_dir($folderFs)) return $out;

        // RecursiveCallbackFilterIterator prunes subtree descent (vs $it->next() which only
        // skips the current entry). Without this, scanners walked into .cache/ and tried to
        // thumb existing thumbs, creating .cache/.cache/ nesting.
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($folderFs, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) { return $f->getFilename() !== '.cache'; }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $p => $info) {
            if ($info->isDir()) continue;
            $kind = ri_kind_from_ext($p);
            if (!$kind) continue;
            $web = str_replace($SITE_BASE, '', $p);
            if (strpos($web, '/media/') !== 0) continue;
            $out[$kind.'s'][] = $web;
        }
        return $out;
    }

    function ri_find_cache_dirs(string $rootFs): array {
        $dirs = [];
        if (!is_dir($rootFs)) return $dirs;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootFs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $p => $info) {
            if ($info->isDir() && basename($p) === '.cache') $dirs[] = $p;
        }
        return $dirs;
    }

    function ri_flush_dir(string $dirFs): array {
        $removed = 0; $failed = [];
        if (!is_dir($dirFs)) return ['removed'=>0, 'failed'=>[]];

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirFs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $sp) {
            $p2 = $sp->getPathname();
            $ok = $sp->isDir() ? @rmdir($p2) : @unlink($p2);
            if ($ok) $removed++; else $failed[] = $p2;
        }
        @rmdir($dirFs);
        return ['removed'=>$removed, 'failed'=>$failed];
    }

    function ri_flush_caches_under(string $rootFs): array {
        $dirs = ri_find_cache_dirs($rootFs);
        $totalRemoved = 0; $allFailed = [];
        foreach ($dirs as $cdir) {
            $res = ri_flush_dir($cdir);
            $totalRemoved += $res['removed'];
            $allFailed = array_merge($allFailed, $res['failed']);
        }
        return ['cache_dirs'=>$dirs, 'removed'=>$totalRemoved, 'failed'=>$allFailed];
    }

    function ri_counts_in_folder(string $folderFs): array {
        $c = ['images'=>0,'videos'=>0,'pdfs'=>0];
        if (!is_dir($folderFs)) return $c;
        $it = new FilesystemIterator($folderFs, FilesystemIterator::SKIP_DOTS);
        foreach ($it as $info) {
            if ($info->isDir()) continue;
            $k = ri_kind_from_ext($info->getPathname());
            if ($k === 'image') $c['images']++;
            elseif ($k === 'video') $c['videos']++;
            elseif ($k === 'pdf') $c['pdfs']++;
        }
        return $c;
    }

    function ri_cache_size_under(string $folderFs): int {
        $sum=0;
        if (!is_dir($folderFs)) return 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderFs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $p => $info) {
            if ($info->isFile() && strpos($p, DIRECTORY_SEPARATOR.'.cache'.DIRECTORY_SEPARATOR)!==false) {
                $sum += $info->getSize();
            }
        }
        return $sum;
    }

    // ---------- router ----------
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'ensure_thumb') {
        $kind = strtolower(trim((string)($_POST['kind'] ?? $_GET['kind'] ?? '')));
        if (!in_array($kind, ['image','video','pdf','audio'], true)) ri_bad('Invalid kind.');
        [$srcWeb, $srcFs] = ri_web_to_fs_under_media((string)($_POST['src'] ?? $_GET['src'] ?? ''), $SITE_BASE);
        if (!is_file($srcFs)) ri_bad('Source file not found.', ['src'=>$srcWeb]);
        try {
            $res = ri_ensure_thumb_local($srcWeb, $srcFs, $SITE_BASE, $kind);
            ri_json_out(true, $res);
        } catch (Throwable $e) {
            ri_bad($e->getMessage());
        }

    } elseif ($action === 'save_thumb') {
        // Persist a client-generated poster (e.g. a canvas frame from a <video>
        // on hosts without ffmpeg) into the .cache convention, so subsequent
        // loads serve it like any server-rendered thumb. POST only (data is large).
        [$srcWeb, $srcFs] = ri_web_to_fs_under_media((string)($_POST['src'] ?? ''), $SITE_BASE);
        $data = (string)($_POST['data'] ?? '');
        if (!preg_match('~^data:image/(jpeg|jpg|png|webp);base64,~i', $data)) ri_bad('Invalid image data URL.');
        $bytes = base64_decode(substr($data, strpos($data, ',') + 1), true);
        if ($bytes === false || $bytes === '') ri_bad('Could not decode image.');
        if (strlen($bytes) > 6 * 1024 * 1024) ri_bad('Poster too large.');
        [$dstFs, $dstWeb] = ri_cache_target_local($srcWeb, $SITE_BASE);
        // Re-encode through GD (mt_write_cover_jpeg) to sanitise arbitrary bytes.
        if (!mt_write_cover_jpeg($bytes, $dstFs, 640)) ri_bad('Could not write thumb.');
        ri_json_out(true, ['thumb_url' => $dstWeb]);

    } elseif ($action === 'flush_caches') {
        $res = ri_flush_caches_under($SITE_BASE . '/media');
        ri_json_out(true, ['message'=>'Caches flushed', 'details'=>$res]);

    } elseif ($action === 'rebuild_all') { // legacy mass; now skips PDFs
        $files = ri_collect_under($SITE_BASE . '/media', $SITE_BASE);
        $done=0; $fail=[];
        foreach ($files['images'] as $web) { try { ri_ensure_thumb_local($web, $SITE_BASE.$web, $SITE_BASE, 'image'); $done++; } catch(Throwable $e){ $fail[]=['file'=>$web,'error'=>$e->getMessage()]; } }
        foreach ($files['videos'] as $web) { try { ri_ensure_thumb_local($web, $SITE_BASE.$web, $SITE_BASE, 'video'); $done++; } catch(Throwable $e){ $fail[]=['file'=>$web,'error'=>$e->getMessage()]; } }
        ri_json_out(true, ['message'=>'Rebuild complete (PDFs skipped)','details'=>['generated'=>$done,'failed'=>$fail,'scanned'=>['images'=>count($files['images']),'videos'=>count($files['videos']),'pdfs'=>count($files['pdfs'])]]]);

    } elseif ($action === 'flush_folder') {
        [$folderWeb, $folderFs] = ri_web_to_fs_under_media((string)($_POST['folder'] ?? $_GET['folder'] ?? ''), $SITE_BASE);
        $res = ri_flush_caches_under($folderFs);
        ri_json_out(true, ['message'=>"Flushed caches under $folderWeb", 'details'=>$res]);

    } elseif ($action === 'rebuild_folder') { // legacy per-folder; now skips PDFs
        [$folderWeb, $folderFs] = ri_web_to_fs_under_media((string)($_POST['folder'] ?? $_GET['folder'] ?? ''), $SITE_BASE);
        $files = ri_collect_under($folderFs, $SITE_BASE);
        $done=0; $fail=[];
        foreach ($files['images'] as $web) { try { ri_ensure_thumb_local($web, $SITE_BASE.$web, $SITE_BASE, 'image'); $done++; } catch(Throwable $e){ $fail[]=['file'=>$web,'error'=>$e->getMessage()]; } }
        foreach ($files['videos'] as $web) { try { ri_ensure_thumb_local($web, $SITE_BASE.$web, $SITE_BASE, 'video'); $done++; } catch(Throwable $e){ $fail[]=['file'=>$web,'error'=>$e->getMessage()]; } }
        ri_json_out(true, ['message'=>"Rebuilt thumbs under $folderWeb (PDFs skipped)", 'details'=>['generated'=>$done,'failed'=>$fail,'scanned'=>['images'=>count($files['images']),'videos'=>count($files['videos']),'pdfs'=>count($files['pdfs'])]]]);

    } elseif ($action === 'scan_targets') {
        $scope  = strtolower($_POST['scope'] ?? $_GET['scope'] ?? 'all');
        $typesQ = strtolower($_POST['types'] ?? $_GET['types'] ?? 'images,videos'); // default: skip pdfs
        $wantImages = strpos($typesQ, 'images') !== false;
        $wantVideos = strpos($typesQ, 'videos') !== false;
        $wantPdfs   = strpos($typesQ, 'pdfs')   !== false;

        if ($scope === 'folder') {
            [$folderWeb, $folderFs] = ri_web_to_fs_under_media((string)($_POST['folder'] ?? $_GET['folder'] ?? ''), $SITE_BASE);
            $all = ri_collect_under($folderFs, $SITE_BASE);
        } else {
            $all = ri_collect_under($SITE_BASE . '/media', $SITE_BASE);
        }
        $files = [];
        if ($wantImages) $files = array_merge($files, $all['images']);
        if ($wantVideos) $files = array_merge($files, $all['videos']);
        if ($wantPdfs)   $files = array_merge($files, $all['pdfs']); // include only if explicitly requested

        ri_json_out(true, ['files' => $files, 'counts' => ['images'=>count($all['images']),'videos'=>count($all['videos']),'pdfs'=>count($all['pdfs'])]]);

    } elseif ($action === 'build_batch') {
        // accepts files[] multiple or files as JSON (string)
        $files = [];
        if (!empty($_POST['files']) && is_array($_POST['files'])) {
            $files = $_POST['files'];
        } elseif (!empty($_POST['files'])) {
            $decoded = json_decode((string)$_POST['files'], true);
            if (is_array($decoded)) $files = $decoded;
        }

        if (empty($files)) ri_bad('No files provided.');

        $generated=0; $failed=[];
        foreach ($files as $web) {
            try {
                [$srcWeb, $srcFs] = ri_web_to_fs_under_media($web, $SITE_BASE);
                $k = ri_kind_from_ext($srcFs);
                // skip pdfs by design for batch
                if ($k === 'pdf') { continue; }
                ri_ensure_thumb_local($srcWeb, $srcFs, $SITE_BASE, $k);
                $generated++;
            } catch (Throwable $e) {
                $failed[] = ['file' => (string)$web, 'error' => $e->getMessage()];
            }
        }
        ri_json_out(true, ['generated'=>$generated, 'failed'=>$failed]);

    } elseif ($action === 'folder_info') {
        [$folderWeb, $folderFs] = ri_web_to_fs_under_media((string)($_POST['folder'] ?? $_GET['folder'] ?? ''), $SITE_BASE);
        $size   = ri_cache_size_under($folderFs);
        $counts = ri_counts_in_folder($folderFs);
        ri_json_out(true, ['folder'=>$folderWeb, 'cache_size'=>$size, 'counts'=>$counts]);

    } else {
        ri_bad('Unknown or missing action.');
    }

} catch (Throwable $e) {
    http_response_code(500);
    ri_json_out(false, ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>