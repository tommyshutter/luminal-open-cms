<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: SiteSettings v1.0.0
 * File: SiteSettingsFunctions.php
 */
declare(strict_types=1);

/** Valid settings files that may be loaded/saved */
define('SS_ALLOWED_FILES', ['site-settings.json', 'header_settings.json', 'menu_settings.json']);

/* ======================================================================= */
/* Settings I/O                                                            */
/* ======================================================================= */

function ss_data_dir(): string {
    return SITE_ROOT . '/admin/data';
}

function ss_validate_file(string $file): string {
    $file = basename($file);
    if (!in_array($file, SS_ALLOWED_FILES, true)) {
        throw new \InvalidArgumentException('Invalid settings file');
    }
    return $file;
}

function ss_settings_path(string $file): string {
    return ss_data_dir() . '/' . ss_validate_file($file);
}

function ss_load_settings(string $file): array {
    $path = ss_settings_path($file);
    if (!file_exists($path)) {
        return [];
    }
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function ss_save_settings(string $file, string $jsonData): void {
    $decoded = json_decode($jsonData, true);
    if ($decoded === null && $jsonData !== 'null') {
        throw new \InvalidArgumentException('Invalid JSON data');
    }

    $path = ss_settings_path($file);
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Read-merge-write: preserve keys owned by other modules
    $existing = [];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $existing = json_decode($raw, true) ?: [];
        }
    }
    $merged = array_merge($existing, $decoded ?? []);

    $tmp = $path . '.tmp';
    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json) === false) {
        throw new \RuntimeException('Failed to write settings file');
    }
    @chmod($tmp, 0664);
    rename($tmp, $path);
}

/* ======================================================================= */
/* Logo Upload                                                             */
/* ======================================================================= */

function ss_upload_logo(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Upload error code: ' . $file['error']);
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($file['type'], $allowed, true)) {
        throw new \InvalidArgumentException('Invalid file type. Allowed: JPG, PNG, GIF, WEBP, SVG');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new \InvalidArgumentException('File too large (max 5 MB)');
    }

    $logoDir = SITE_ROOT . '/media/images/logos';
    if (!is_dir($logoDir)) {
        mkdir($logoDir, 0775, true);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo.' . $ext;
    $dest     = $logoDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new \RuntimeException('Failed to move uploaded file');
    }

    chmod($dest, 0644);

    return [
        'path'     => 'media/images/logos/' . $filename,
        'filename' => $filename,
    ];
}

/* ======================================================================= */
/* OG Image Generation                                                     */
/* ======================================================================= */

function ss_generate_og(string $logoPath): array {
    if ($logoPath === '') {
        throw new \InvalidArgumentException('No logo path provided');
    }

    $fullPath = SITE_ROOT . '/' . $logoPath;
    if (!file_exists($fullPath)) {
        throw new \RuntimeException('Logo file not found');
    }

    $siteAssetsDir = SITE_ROOT . '/admin/data/site-assets';
    if (!is_dir($siteAssetsDir)) {
        mkdir($siteAssetsDir, 0775, true);
    }

    $ogDir = SITE_ROOT . '/media/images/og';

    /* Defensive assertion — past incidents produced a nested .../media/images/og/og/
     * directory from deploy cross-contamination. If the resolved path ever ends in
     * /og/og (in any form), hard-fail rather than silently generate duplicates in
     * the wrong location. Also log the resolved path on every run so future stray
     * directories are traceable via admin/data/og-generate.log. */
    $normalizedOgDir = rtrim(str_replace('\\', '/', $ogDir), '/');
    if (preg_match('~/og/og/?$~', $normalizedOgDir) || strpos($normalizedOgDir, '/og/og/') !== false) {
        throw new \RuntimeException("ss_generate_og: refusing to write to nested og path: {$ogDir}");
    }
    @file_put_contents(
        SITE_ROOT . '/admin/data/og-generate.log',
        date('c') . " resolved_og_dir={$ogDir} logo_path={$logoPath}\n",
        FILE_APPEND | LOCK_EX
    );

    if (!is_dir($ogDir)) {
        mkdir($ogDir, 0775, true);
    }

    $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));

    $source = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
        'png'         => @imagecreatefrompng($fullPath),
        'gif'         => @imagecreatefromgif($fullPath),
        'webp'        => @imagecreatefromwebp($fullPath),
        default       => throw new \InvalidArgumentException('Unsupported image format: ' . $ext),
    };

    if (!$source) {
        throw new \RuntimeException('Failed to load image');
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

    $sizes = [
        ['name' => 'facebook',  'w' => 1200, 'h' => 630],
        ['name' => 'twitter',   'w' => 1200, 'h' => 600],
        ['name' => 'instagram', 'w' => 600,  'h' => 314],
    ];

    $favicons = [
        ['name' => 'favicon-32x32', 'w' => 32, 'h' => 32],
        ['name' => 'favicon-16x16', 'w' => 16, 'h' => 16],
    ];

    $generated = [];
    $domain    = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'site');

    /* --- Social / OG images (black background, centred logo) ---------- */
    foreach ($sizes as $s) {
        $canvas = imagecreatetruecolor($s['w'], $s['h']);
        $bg     = imagecolorallocate($canvas, 0, 0, 0);
        imagefill($canvas, 0, 0, $bg);
        imagealphablending($canvas, true);

        $scale = min($s['w'] / $srcW, $s['h'] / $srcH) * 0.8;
        $nw    = (int)($srcW * $scale);
        $nh    = (int)($srcH * $scale);
        $x     = (int)(($s['w'] - $nw) / 2);
        $y     = (int)(($s['h'] - $nh) / 2);

        imagecopyresampled($canvas, $source, $x, $y, 0, 0, $nw, $nh, $srcW, $srcH);

        $filename   = 'og-' . $s['name'] . '-' . $s['w'] . 'x' . $s['h'] . '.png';
        $outputPath = $ogDir . '/' . $filename;

        if (imagepng($canvas, $outputPath, 9)) {
            chmod($outputPath, 0644);
            $generated[] = $filename;
        }

        if ($s['name'] === 'facebook') {
            // Write to legacy site-assets path (blocked by most .htaccess configs but kept for
            // backward compat with older sites whose .htaccess allows it)
            $mainOg = $siteAssetsDir . '/og.' . $domain . '.png';
            if (imagepng($canvas, $mainOg, 9)) {
                chmod($mainOg, 0644);
                $generated[] = 'og.' . $domain . '.png (site-assets)';
            }
            $genericOg = $siteAssetsDir . '/og.png';
            if (imagepng($canvas, $genericOg, 9)) {
                chmod($genericOg, 0644);
                $generated[] = 'og.png (site-assets)';
            }
            // Write to public path (preferred — reachable by social scrapers)
            $mainOgPub = $ogDir . '/og-' . $domain . '.png';
            if (imagepng($canvas, $mainOgPub, 9)) {
                chmod($mainOgPub, 0644);
                $generated[] = 'og-' . $domain . '.png (public)';
            }
            $genericOgPub = $ogDir . '/og.png';
            if (imagepng($canvas, $genericOgPub, 9)) {
                chmod($genericOgPub, 0644);
                $generated[] = 'og.png (public)';
            }
        }

        imagedestroy($canvas);
    }

    /* --- Favicons (transparent background) ----------------------------- */
    foreach ($favicons as $f) {
        $canvas = imagecreatetruecolor($f['w'], $f['h']);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $f['w'], $f['h'], $srcW, $srcH);

        $filename   = $f['name'] . '.png';
        $outputPath = $ogDir . '/' . $filename;
        if (imagepng($canvas, $outputPath)) {
            chmod($outputPath, 0644);
            $generated[] = $filename;
        }

        $safn = $siteAssetsDir . '/' . ($f['w'] === 32 ? 'favicon-32.png' : 'favicon-16.png');
        imagepng($canvas, $safn);
        chmod($safn, 0644);

        imagedestroy($canvas);
    }

    /* --- Apple touch icon (180x180, black bg) -------------------------- */
    $ac  = imagecreatetruecolor(180, 180);
    $abg = imagecolorallocate($ac, 0, 0, 0);
    imagefill($ac, 0, 0, $abg);
    imagealphablending($ac, true);

    $as = min(180 / $srcW, 180 / $srcH) * 0.8;
    $aw = (int)($srcW * $as);
    $ah = (int)($srcH * $as);
    $ax = (int)((180 - $aw) / 2);
    $ay = (int)((180 - $ah) / 2);

    imagecopyresampled($ac, $source, $ax, $ay, 0, 0, $aw, $ah, $srcW, $srcH);
    $applePath = $siteAssetsDir . '/apple-touch-icon.png';
    imagepng($ac, $applePath);
    chmod($applePath, 0644);
    $generated[] = 'apple-touch-icon.png';
    imagedestroy($ac);

    imagedestroy($source);

    return [
        'generated' => $generated,
        'og_path'   => '/admin/data/site-assets/og.' . $domain . '.png',
    ];
}

/* ======================================================================= */
/* Maintenance Utilities                                                   */
/* ======================================================================= */

function ss_fix_permissions(): string {
    $script = SITE_ROOT . '/admin/scripts/fix-perms.sh';

    if (!file_exists($script)) {
        // Fallback: legacy chmod on key directories
        $output = [];
        $paths  = [SITE_ROOT . '/media', SITE_ROOT . '/admin/data', SITE_ROOT . '/admin/modules'];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                exec(sprintf('find %s -type d -not -perm 0775 -exec chmod 0775 {} + 2>&1', escapeshellarg($path)));
                exec(sprintf('find %s -type f -not -perm 0664 -exec chmod 0664 {} + 2>&1', escapeshellarg($path)));
                $output[] = "OK: Fixed permissions on $path";
            }
        }
        $output[] = '=== COMPLETE (legacy fallback) ===';
        return implode("\n", $output);
    }

    $cmd = sprintf('bash %s %s 2>&1', escapeshellarg($script), escapeshellarg(SITE_ROOT));
    $result = shell_exec($cmd) ?? '';
    return $result ?: '=== COMPLETE ===';
}

function ss_recreate_paths(): string {
    $base = SITE_ROOT . '/media';
    $dirs = [
        'images/backgrounds', 'images/left_panel', 'images/logos',
        'images/photo_gallery', 'images/posters', 'images/right_panel',
        'images/site', 'images/sponsors', 'images/og',
        'videos/backgrounds', 'videos/left_panel', 'videos/posters',
        'videos/right_panel', 'videos/site', 'videos/video_gallery',
        'pdfs', 'fonts',
    ];

    $output = ['=== Creating Media Directory Structure ==='];

    foreach ($dirs as $dir) {
        $full = $base . '/' . $dir;
        if (!is_dir($full)) {
            mkdir($full, 0775, true);
            $output[] = "CREATED: $full";
        } else {
            $output[] = "EXISTS: $full";
        }
    }

    $adminData = SITE_ROOT . '/admin/data';
    if (!is_dir($adminData)) {
        mkdir($adminData, 0775, true);
        $output[] = "CREATED: $adminData";
    }

    $output[] = '=== Directory Creation Complete ===';
    return implode("\n", $output);
}

/* ======================================================================= */
/* Page Listing                                                            */
/* ======================================================================= */

function ss_get_pages(): array {
    $pagesDir = SITE_ROOT . '/admin/data/pages';
    $pages    = [];

    if (is_dir($pagesDir)) {
        foreach (scandir($pagesDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $full = $pagesDir . '/' . $dir;
            if (is_dir($full) && file_exists($full . '/' . $dir . '.json')) {
                $pages[] = $dir;
            }
        }
    }

    if (!in_array('home', $pages, true)) {
        $pages[] = 'home';
    }
    sort($pages);
    return $pages;
}
