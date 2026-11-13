<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/ImageOptimizer.php
 *
 * GD-based image resizing and JPEG compression for social platform sharing.
 * Max 1200px width, JPEG 85% quality, target <900KB.
 */
declare(strict_types=1);

class ImageOptimizer {
    const MAX_WIDTH = 1200;
    const QUALITY = 85;
    const MAX_SIZE_BYTES = 900000;

    public static function optimize(string $sourcePath, ?string $destPath = null, bool $cropSquare = false): ?string {
        if (!file_exists($sourcePath) || !function_exists('imagecreatefromjpeg')) return null;

        $destPath = $destPath ?: $sourcePath;
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) return null;

        [$width, $height, $type] = $imageInfo;

        $srcX = 0; $srcY = 0; $srcWidth = $width; $srcHeight = $height;
        if ($cropSquare && $width !== $height) {
            $size = min($width, $height);
            $srcX = (int)(($width - $size) / 2);
            $srcY = (int)(($height - $size) / 2);
            $srcWidth = $size;
            $srcHeight = $size;
        }

        if ($cropSquare) {
            $newWidth = min(self::MAX_WIDTH, $srcWidth);
            $newHeight = $newWidth;
        } elseif ($srcWidth > self::MAX_WIDTH) {
            $newWidth = self::MAX_WIDTH;
            $newHeight = (int)($newWidth * ($srcHeight / $srcWidth));
        } else {
            $newWidth = $srcWidth;
            $newHeight = $srcHeight;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF  => @imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default        => null,
        };
        if (!$source) return null;

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
            $white = imagecolorallocate($newImage, 255, 255, 255);
            imagefill($newImage, 0, 0, $white);
        }

        imagecopyresampled($newImage, $source, 0, 0, $srcX, $srcY, $newWidth, $newHeight, $srcWidth, $srcHeight);
        $success = imagejpeg($newImage, $destPath, self::QUALITY);
        imagedestroy($source);
        imagedestroy($newImage);

        if (!$success) return null;

        if (filesize($destPath) > self::MAX_SIZE_BYTES) {
            $source = @imagecreatefromjpeg($destPath);
            if ($source) {
                imagejpeg($source, $destPath, 75);
                imagedestroy($source);
            }
        }

        return $destPath;
    }

    public static function needsOptimization(string $filePath): bool {
        if (!file_exists($filePath)) return false;
        if (filesize($filePath) > self::MAX_SIZE_BYTES) return true;
        $info = @getimagesize($filePath);
        return $info && $info[0] > self::MAX_WIDTH;
    }

    public static function getImageInfo(string $filePath): array {
        if (!file_exists($filePath)) return ['error' => 'File not found'];
        $info = @getimagesize($filePath);
        if (!$info) return ['error' => 'Not a valid image'];
        return [
            'width'              => $info[0],
            'height'             => $info[1],
            'type'               => $info[2],
            'mime'               => $info['mime'],
            'size'               => filesize($filePath),
            'size_human'         => self::humanSize(filesize($filePath)),
            'needs_optimization' => self::needsOptimization($filePath),
        ];
    }

    public static function humanSize(int $bytes): string {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
