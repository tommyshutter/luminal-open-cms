<?php
/**
 * Font Manager — record of fonts the OPERATOR added to this site.
 * @file /admin/modules/FontManager/lib/user_fonts.php
 *
 * A font in media/fonts is one of two things: part of the stock set the CMS
 * shipped, or something the operator chose and put there. Only the first is ours
 * to sweep. This file is the record of the second.
 *
 * The purge tooling must treat everything listed here as untouchable regardless
 * of whether a usage scanner scores it "used" — an operator may upload a font
 * today and apply it next week, and a sweep in between must not delete it.
 * Licensing of an operator-supplied font is the operator's call, not ours.
 *
 * ⚠️ Do NOT try to derive this list by diffing against the stock set. Some fonts
 * an operator relies on are ALSO in the stock set, so "not in stock" misses
 * exactly the cases this record exists to protect. Membership is recorded when a
 * font is added, and seeded for sites that predate this file.
 *
 * Lives in admin/data/ per the module data law, so deploys never touch it.
 */
declare(strict_types=1);

if (!function_exists('fm_user_fonts_path')) {
    function fm_user_fonts_path(): string {
        $root = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4);
        return $root . '/admin/data/FontManager/user-fonts.json';
    }
}

if (!function_exists('fm_user_fonts_load')) {
    function fm_user_fonts_load(): array {
        $p = fm_user_fonts_path();
        if (!is_file($p)) return ['fonts' => []];
        $d = json_decode((string)@file_get_contents($p), true);
        if (!is_array($d) || !isset($d['fonts']) || !is_array($d['fonts'])) return ['fonts' => []];
        return $d;
    }
}

if (!function_exists('fm_user_fonts_files')) {
    /** @return string[] basenames of every operator-added font file */
    function fm_user_fonts_files(): array {
        $out = [];
        foreach (fm_user_fonts_load()['fonts'] as $f) {
            if (!empty($f['file'])) $out[] = (string)$f['file'];
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('fm_user_fonts_record')) {
    /**
     * Record one operator-added font. Idempotent on filename.
     * $source: 'upload' | 'google' | 'seeded'
     */
    function fm_user_fonts_record(string $file, string $family = '', string $source = 'upload'): bool {
        $file = basename(trim($file));
        if ($file === '') return false;

        $d = fm_user_fonts_load();
        foreach ($d['fonts'] as $f) {
            if (($f['file'] ?? '') === $file) return true;   // already recorded
        }
        $d['_doc'] = 'Fonts added by the operator of this site. The purge tooling never '
                   . 'removes these, whatever a usage scan reports. Written by Font Manager.';
        $d['fonts'][] = [
            'file'   => $file,
            'family' => $family,
            'source' => $source,
            'added'  => date('c'),
        ];

        $p   = fm_user_fonts_path();
        $dir = dirname($p);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;

        $tmp = $p . '.tmp';
        if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0664);
        return @rename($tmp, $p);   // atomic; a half-written record protects nothing
    }
}
