<?php
/**
 * FontManager — Helper Functions
 *
 * @file /admin/modules/FontManager/FontManagerFunctions.php
 */
declare(strict_types=1);

/**
 * Convert filename to safe CSS font-family name
 *
 * @param string $filename Font filename
 * @return string Safe font family name
 */
function fm_safe_family(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
    return $safe !== '' ? $safe : 'FontFamily';
}

/**
 * Get CSS format() string for font extension
 *
 * @param string $ext File extension
 * @return string CSS format() string
 */
function fm_src_format(string $ext): string {
    switch (strtolower($ext)) {
        case 'woff2': return "format('woff2')";
        case 'woff':  return "format('woff')";
        case 'otf':   return "format('opentype')";
        case 'ttf':   return "format('truetype')";
        default:      return '';
    }
}

/**
 * Get list of installed fonts
 *
 * @param string $fontsDir Fonts directory path
 * @return array Array of font filenames
 */
function fm_get_fonts(string $fontsDir): array {
    $fonts = [];
    if (!is_dir($fontsDir)) {
        return $fonts;
    }

    $items = scandir($fontsDir);
    foreach ($items ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $fontsDir . '/' . $f;
        if (!is_file($path)) continue;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['ttf', 'otf', 'woff', 'woff2'], true)) {
            $fonts[] = $f;
        }
    }

    return $fonts;
}

/**
 * Compute hash of file for duplicate detection
 *
 * @param string $path File path
 * @return string File hash (SHA256, SHA1, or MD5)
 */
function fm_hash_file(string $path): string {
    if (function_exists('hash_file')) {
        return hash_file('sha256', $path);
    }
    if (function_exists('sha1_file')) {
        return sha1_file($path);
    }
    if (function_exists('md5_file')) {
        return md5_file($path);
    }

    // Fallback: read file (okay for small fonts)
    $data = @file_get_contents($path);
    return $data !== false ? substr(hash('sha256', $data), 0, 64) : '';
}
