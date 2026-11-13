<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/MediaCacheHelper.php
 *
 * Thumbnail cache synchronization — generates/invalidates .cache/ thumbnails
 * when Events Manager modifies images. Uses GD, 640px max width, JPEG 85%.
 * SITE_ROOT replaced with emp_site_root().
 */
declare(strict_types=1);

class MediaCacheHelper {

    public static function refreshThumbnail(string $imagePath): array {
        if (empty($imagePath)) return ['success' => false, 'message' => 'No image path provided'];

        $webPath = '/' . ltrim($imagePath, '/');
        if (strpos($webPath, '/media/') !== 0) {
            return ['success' => false, 'message' => 'Path not under /media/'];
        }

        self::deleteCachedThumbnail($webPath);
        return self::generateThumbnail($webPath);
    }

    public static function deleteCachedThumbnail(string $imagePath): bool {
        if (empty($imagePath)) return true;

        $siteRoot = emp_site_root();
        $webPath  = '/' . ltrim($imagePath, '/');
        $dirWeb   = rtrim(dirname($webPath), '/');
        $baseNoExt = pathinfo($webPath, PATHINFO_FILENAME);

        $deleted = false;
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $cachePath = $siteRoot . $dirWeb . '/.cache/' . $baseNoExt . '.' . $ext;
            if (file_exists($cachePath)) {
                @unlink($cachePath);
                $deleted = true;
            }
        }
        return $deleted;
    }

    public static function generateThumbnail(string $webPath): array {
        $siteRoot = emp_site_root();
        $absPath  = $siteRoot . $webPath;
        if (!file_exists($absPath)) return ['success' => false, 'message' => 'Source file not found'];

        $ext = strtolower(pathinfo($webPath, PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $imageExts)) {
            return ['success' => true, 'message' => 'Non-image file, thumbnail generated on-demand'];
        }

        $dirWeb   = rtrim(dirname($webPath), '/');
        $cacheDir = $siteRoot . $dirWeb . '/.cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        $baseNoExt = pathinfo($webPath, PATHINFO_FILENAME);
        $dstFs  = $cacheDir . '/' . $baseNoExt . '.jpg';
        $dstWeb = $dirWeb . '/.cache/' . $baseNoExt . '.jpg';

        try {
            self::createImageThumbnail($absPath, $dstFs, 640);
            return ['success' => true, 'thumb_url' => $dstWeb];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function createImageThumbnail(string $srcFs, string $dstFs, int $maxW = 640): void {
        $ext = strtolower(pathinfo($srcFs, PATHINFO_EXTENSION));

        $im = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($srcFs),
            'png'         => @imagecreatefrompng($srcFs),
            'gif'         => @imagecreatefromgif($srcFs),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcFs) : null,
            default       => throw new \Exception('Unsupported image format'),
        };

        if (!$im) throw new \Exception('Failed to load image');

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w <= 0 || $h <= 0) { imagedestroy($im); throw new \Exception('Invalid image dimensions'); }

        if ($w > $maxW) {
            $nw = $maxW;
            $nh = (int)round($h * ($maxW / $w));
        } else {
            $nw = $w;
            $nh = $h;
        }

        $out = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (!@imagejpeg($out, $dstFs, 85)) {
            imagedestroy($im);
            imagedestroy($out);
            throw new \Exception('Failed to write thumbnail');
        }

        imagedestroy($im);
        imagedestroy($out);
        @chmod($dstFs, 0664);
    }

    public static function batchRefresh(array $imagePaths): array {
        $success = 0; $failed = 0; $errors = [];
        foreach ($imagePaths as $path) {
            $result = self::refreshThumbnail($path);
            if ($result['success']) $success++; else { $failed++; $errors[] = ['path' => $path, 'error' => $result['message'] ?? 'Unknown']; }
        }
        return ['success' => $failed === 0, 'processed' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    public static function flushEventsCache(): array {
        $siteRoot = emp_site_root();
        $dirs = [
            $siteRoot . '/media/images/posters/.cache',
            $siteRoot . '/media/images/venues/.cache',
        ];
        $removed = 0;
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) { @unlink($file); $removed++; }
                }
            }
        }
        return ['success' => true, 'removed' => $removed];
    }
}
