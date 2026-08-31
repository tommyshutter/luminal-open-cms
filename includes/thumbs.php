<?php
/**
 * Unified thumbnail resolver for images, videos, PDFs.
 * @file   /includes/thumbs.php
 * @since  2025-09-06
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

if (!function_exists('ri_h')) {
    function ri_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ri_norm_web')) {
    function ri_norm_web(string $p): string {
        $p = trim($p);
        if ($p === '') return $p;
        if (preg_match('~^https?://~i', $p)) return $p; // leave absolute
        if ($p[0] !== '/') $p = '/' . ltrim($p, '/');   // site-absolute
        return $p;
    }
}
if (!function_exists('ri_exists_web')) {
    function ri_exists_web(string $webPath): bool {
        if ($webPath === '' || preg_match('~^https?://~i', $webPath)) return false;
        $abs = SITE_ROOT . $webPath;
        clearstatcache(true, $abs);
        return is_file($abs);
    }
}
if (!function_exists('ri_try_candidates')) {
    function ri_try_candidates(array $cands): ?string {
        foreach ($cands as $c) {
            $n = ri_norm_web($c);
            if (ri_exists_web($n)) return $n;
        }
        return null;
    }
}
if (!function_exists('ri_safe_add')) {
    function ri_safe_add(array &$out, string $p): void {
        $n = ri_norm_web($p);
        if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
    }
}
if (!function_exists('ri_read_cache_map')) {
    function ri_read_cache_map(string $dirWeb): array {
        $candidates = [
            $dirWeb . '/.cache/_thumbs.json',
            $dirWeb . '/.cache/thumbs.json',
            $dirWeb . '/.cache/index.json',
        ];
        foreach ($candidates as $web) {
            $abs = SITE_ROOT . $web;
            clearstatcache(true, $abs);
            if (!is_file($abs)) continue;
            $raw = @file_get_contents($abs);
            if ($raw === false) continue;
            $j = json_decode($raw, true);
            if (!is_array($j)) continue;

            $map = [];
            foreach ($j as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $map[$k] = ri_norm_web(strpos($v, '/') === 0 ? $v : ($dirWeb . '/.cache/' . ltrim($v, '/')));
                } elseif (is_string($k) && is_array($v) && isset($v['thumb'])) {
                    $map[$k] = ri_norm_web(strpos($v['thumb'], '/') === 0 ? $v['thumb'] : ($dirWeb . '/.cache/' . ltrim($v['thumb'], '/')));
                }
            }
            if ($map) return $map;
        }
        return [];
    }
}
if (!function_exists('ri_basename_variants')) {
    function ri_basename_variants(string $base, string $ext): array {
        $variants = [];
        $clean = preg_replace('~\s+~', ' ', $base);
        $variants[] = $base;
        if ($clean !== $base) $variants[] = $clean;
        $variants[] = str_replace(' ', '_', $clean);
        $variants[] = str_replace(' ', '-', $clean);
        $variants[] = "{$base}-{$ext}";
        $variants[] = str_replace(' ', '_', "{$base}-{$ext}");
        $variants[] = str_replace(' ', '-', "{$base}-{$ext}");
        return array_values(array_unique($variants));
    }
}

if (!function_exists('ri_thumb_candidates_for')) {
    /**
     * @param string $kind image|video|pdf
     * @param string $srcWeb site-absolute or absolute URL
     * @param ?string $hintThumb
     * @return array web-path candidates (in order)
     */
    function ri_thumb_candidates_for(string $kind, string $srcWeb, ?string $hintThumb = null): array {
        $srcWeb = ri_norm_web($srcWeb);
        // include svg last so placeholders written as .svg also work
        $exts = ['jpg','jpeg','png','webp','svg'];
        $out  = [];

        if ($hintThumb && trim($hintThumb) !== '') {
            ri_safe_add($out, $hintThumb);
            if ($hintThumb[0] !== '/' && !preg_match('~^https?://~i', $hintThumb)) {
                ri_safe_add($out, '/media/' . ltrim($hintThumb, '/'));
            }
        }

        if (!preg_match('~^https?://~i', $srcWeb) && $srcWeb !== '') {
            $dirWeb  = rtrim(dirname($srcWeb), '/');
            $file    = basename($srcWeb);
            $base    = pathinfo($file, PATHINFO_FILENAME);
            $srcExt  = strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: '');

            $map = ri_read_cache_map($dirWeb);
            if ($map) {
                if (isset($map[$file]))             ri_safe_add($out, $map[$file]);
                if (isset($map[$base]))             ri_safe_add($out, $map[$base]);
                if (isset($map[$base.'.'.$srcExt])) ri_safe_add($out, $map[$base.'.'.$srcExt]);
            }

            $variants = ri_basename_variants($base, $srcExt);
            foreach ($variants as $v) foreach ($exts as $e) ri_safe_add($out, "{$dirWeb}/.cache/{$v}.{$e}");

            $central = [
                'image' => '/media/images/.cache/',
                'video' => '/media/videos/.cache/',
                'pdf'   => '/media/pdfs/.cache/',
            ][$kind] ?? null;

            if ($central) {
                foreach ($variants as $v) foreach ($exts as $e) ri_safe_add($out, "{$central}{$v}.{$e}");
            }
        }

        return array_values(array_unique($out));
    }
}

if (!function_exists('ri_thumb_for_image')) {
    function ri_thumb_for_image(string $srcWeb, ?string $hintThumb = null): ?string {
        return ri_try_candidates(ri_thumb_candidates_for('image', $srcWeb, $hintThumb));
    }
}
if (!function_exists('ri_thumb_for_video')) {
    function ri_thumb_for_video(string $srcWeb, ?string $hintThumb = null): ?string {
        return ri_try_candidates(ri_thumb_candidates_for('video', $srcWeb, $hintThumb));
    }
}
if (!function_exists('ri_thumb_for_pdf')) {
    function ri_thumb_for_pdf(string $srcWeb, ?string $hintThumb = null): ?string {
        return ri_try_candidates(ri_thumb_candidates_for('pdf', $srcWeb, $hintThumb));
    }
}
?>