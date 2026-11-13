<?php
/**
 * @file admin/modules/MediaThumbs/lib/audio-cover.php
 * @description Reusable embedded-cover-art extractor for audio files.
 *
 * Shared by both thumbnail surfaces:
 *   - MediaThumbs media-cache.php  (kind=audio → /media/.../.cache/{base}.jpg)
 *   - FileManager api.php          (action=thumb_audio → streamed JPEG)
 *
 * Strategy (host-agnostic, cPanel-safe):
 *   1. Pure-PHP ID3v2 APIC/PIC parser — needs NO exec(), works on locked-down
 *      shared hosting. Handles MP3 (the podcast pipeline's format; all our
 *      podcast MP3s embed front-cover art).
 *   2. ffmpeg fallback — for m4a/flac/mp4-audio whose cover lives in an
 *      attached-picture stream, on hosts where exec() + ffmpeg exist.
 *
 * Every function is function_exists()-guarded (opcache-safe, per project rule).
 */

if (!function_exists('mt_audio_is_supported')) {
/** Extensions we attempt cover extraction for. */
function mt_audio_is_supported(string $path): bool {
    $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($e, ['mp3', 'm4a', 'aac', 'flac', 'ogg', 'oga', 'wav', 'wma'], true);
}
}

if (!function_exists('mt_synchsafe')) {
/** Decode a 4-byte ID3 synchsafe integer (7 bits per byte). */
function mt_synchsafe(string $b): int {
    return ((ord($b[0]) & 0x7f) << 21) | ((ord($b[1]) & 0x7f) << 14)
         | ((ord($b[2]) & 0x7f) << 7)  |  (ord($b[3]) & 0x7f);
}
}

if (!function_exists('mt_id3_extract_apic')) {
/**
 * Parse the ID3v2 tag at the head of an MP3 and return the raw bytes of the
 * best embedded picture (prefers picture-type 0x03 = front cover), or null.
 * Pure PHP; reads only the tag region, not the whole file.
 */
function mt_id3_extract_apic(string $srcFs): ?string {
    $fh = @fopen($srcFs, 'rb');
    if (!$fh) return null;
    try {
        $hdr = fread($fh, 10);
        if ($hdr === false || strlen($hdr) < 10 || substr($hdr, 0, 3) !== 'ID3') return null;

        $verMajor = ord($hdr[3]);          // 2, 3 or 4
        $flags    = ord($hdr[5]);
        $tagSize  = mt_synchsafe(substr($hdr, 6, 4));
        if ($tagSize <= 0 || $tagSize > 50 * 1024 * 1024) return null; // sane cap

        $body = fread($fh, $tagSize);
        if ($body === false) return null;

        // Whole-tag unsynchronisation (ID3v2 flag 0x80): restore 0xFF 0x00 → 0xFF.
        if ($flags & 0x80) $body = str_replace("\xFF\x00", "\xFF", $body);

        $pos = 0;
        $len = strlen($body);

        // Skip an extended header if present (v2.3/v2.4 flag 0x40).
        if (($flags & 0x40) && $verMajor >= 3 && $pos + 4 <= $len) {
            $extSize = ($verMajor === 4)
                ? mt_synchsafe(substr($body, $pos, 4))
                : (int) unpack('N', substr($body, $pos, 4))[1];
            $pos += ($verMajor === 4) ? $extSize : $extSize + 4;
        }

        $best = null;        // fallback: first picture found
        $frontCover = null;   // preferred: picture type 0x03

        if ($verMajor === 2) {
            // v2.2: 3-byte frame id "PIC", 3-byte size, no flags.
            while ($pos + 6 <= $len) {
                $fid  = substr($body, $pos, 3);
                if (!preg_match('/^[A-Z0-9]{3}$/', $fid)) break; // padding / end
                $fsz  = (ord($body[$pos+3]) << 16) | (ord($body[$pos+4]) << 8) | ord($body[$pos+5]);
                $pos += 6;
                if ($fsz <= 0 || $pos + $fsz > $len) break;
                $frame = substr($body, $pos, $fsz);
                $pos  += $fsz;
                if ($fid === 'PIC') {
                    // enc(1) + imgfmt(3) + pictype(1) + desc(null-term) + data
                    if (strlen($frame) < 5) continue;
                    $enc     = ord($frame[0]);
                    $picType = ord($frame[4]);
                    $rest    = substr($frame, 5);
                    $img     = mt_strip_desc($rest, $enc);
                    if ($img === null || $img === '') continue;
                    if ($picType === 0x03) { $frontCover = $img; break; }
                    if ($best === null) $best = $img;
                }
            }
        } else {
            // v2.3 / v2.4: 4-byte id, 4-byte size, 2-byte flags.
            while ($pos + 10 <= $len) {
                $fid = substr($body, $pos, 4);
                if (!preg_match('/^[A-Z0-9]{4}$/', $fid)) break; // padding / end
                $rawSz = substr($body, $pos + 4, 4);
                $fsz   = ($verMajor === 4) ? mt_synchsafe($rawSz) : (int) unpack('N', $rawSz)[1];
                $fflags = substr($body, $pos + 8, 2);
                $pos  += 10;
                if ($fsz <= 0 || $pos + $fsz > $len) break;
                $frame = substr($body, $pos, $fsz);
                $pos  += $fsz;
                if ($fid !== 'APIC') continue;
                // Per-frame unsynchronisation (v2.4 frame flag 0x02 in the 2nd byte).
                if ($verMajor === 4 && (ord($fflags[1]) & 0x02)) $frame = str_replace("\xFF\x00", "\xFF", $frame);
                // enc(1) + mime(null-term ASCII) + pictype(1) + desc(null-term) + data
                $enc = ord($frame[0]);
                $p   = strpos($frame, "\x00", 1);
                if ($p === false) continue;
                $mime    = substr($frame, 1, $p - 1);
                if ($p + 1 >= strlen($frame)) continue;
                $picType = ord($frame[$p + 1]);
                $rest    = substr($frame, $p + 2);
                $img     = mt_strip_desc($rest, $enc);
                if ($img === null || $img === '') continue;
                // "-->" mime means the frame holds a URL, not image bytes — skip.
                if (strcasecmp(trim($mime), '-->') === 0) continue;
                if ($picType === 0x03) { $frontCover = $img; break; }
                if ($best === null) $best = $img;
            }
        }
        return $frontCover ?? $best;
    } finally {
        fclose($fh);
    }
}
}

if (!function_exists('mt_strip_desc')) {
/**
 * Given the bytes after the picture-type byte (description + image data),
 * strip the null-terminated description and return the image data.
 * Terminator width depends on the text encoding: 2 bytes for UTF-16 (1/2).
 */
function mt_strip_desc(string $rest, int $enc): ?string {
    if ($enc === 1 || $enc === 2) {
        // UTF-16 description terminated by 0x00 0x00 on an even boundary.
        $n = strlen($rest);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            if ($rest[$i] === "\x00" && $rest[$i+1] === "\x00") {
                return substr($rest, $i + 2);
            }
        }
        return null;
    }
    // ISO-8859-1 (0) / UTF-8 (3): single null terminator.
    $p = strpos($rest, "\x00");
    if ($p === false) return null;
    return substr($rest, $p + 1);
}
}

if (!function_exists('mt_ffmpeg_extract_cover')) {
/** ffmpeg fallback: copy the attached-picture stream to raw image bytes, or null. */
function mt_ffmpeg_extract_cover(string $srcFs): ?string {
    // Only usable where exec() is enabled and ffmpeg is on PATH.
    if (!function_exists('exec')) return null;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) return null;
    $ff = null;
    foreach (['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', '/bin/ffmpeg', 'ffmpeg'] as $c) {
        if ($c === 'ffmpeg' || (is_file($c) && is_executable($c))) { $ff = $c; break; }
    }
    if ($ff === null) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'mtcov') . '.jpg';
    $cmd = escapeshellcmd($ff) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($srcFs)
         . ' -an -map 0:v:0 -c:v mjpeg -frames:v 1 ' . escapeshellarg($tmp) . ' 2>/dev/null';
    @exec($cmd, $o, $code);
    if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
        $bytes = @file_get_contents($tmp);
        @unlink($tmp);
        return $bytes ?: null;
    }
    if (is_file($tmp)) @unlink($tmp);
    return null;
}
}

if (!function_exists('mt_write_cover_jpeg')) {
/** Resize raw image bytes to <= $maxW wide and write a JPEG to $dstFs. */
function mt_write_cover_jpeg(string $imgBytes, string $dstFs, int $maxW = 640): bool {
    $im = @imagecreatefromstring($imgBytes);
    if (!$im) return false;
    $w = imagesx($im); $h = imagesy($im);
    if ($w <= 0 || $h <= 0) { imagedestroy($im); return false; }
    if ($w > $maxW) { $nw = $maxW; $nh = (int) round($h * ($maxW / $w)); }
    else            { $nw = $w;    $nh = $h; }
    $out = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = @imagejpeg($out, $dstFs, 85);
    imagedestroy($im); imagedestroy($out);
    if ($ok) @chmod($dstFs, 0664);
    return (bool) $ok;
}
}

if (!function_exists('mt_gen_audio_placeholder')) {
/** Branded music-note placeholder for audio with no embedded art. */
function mt_gen_audio_placeholder(string $srcFs, string $dstFs, int $width = 640): bool {
    $im = imagecreatetruecolor($width, $width);
    $bg     = imagecolorallocate($im, 24, 24, 32);
    $accent = imagecolorallocate($im, 91, 255, 0);   // house --lum-go
    $muted  = imagecolorallocate($im, 148, 163, 184);
    imagefilledrectangle($im, 0, 0, $width, $width, $bg);
    imagefilledrectangle($im, 0, 0, $width, 8, $accent);
    // Simple note glyph: two filled ellipses + stems.
    $cx = (int)($width * 0.40); $cy = (int)($width * 0.62); $r = (int)($width * 0.09);
    imagefilledellipse($im, $cx, $cy, $r * 2, (int)($r * 1.5), $accent);
    imagefilledellipse($im, $cx + (int)($width * 0.22), $cy - (int)($width * 0.06), $r * 2, (int)($r * 1.5), $accent);
    imagesetthickness($im, max(3, (int)($width * 0.012)));
    imageline($im, $cx + $r, $cy, $cx + $r, (int)($cy - $width * 0.32), $accent);
    imageline($im, $cx + (int)($width * 0.22) + $r, $cy - (int)($width * 0.06), $cx + (int)($width * 0.22) + $r, (int)($cy - $width * 0.38), $accent);
    imagefilledrectangle($im, $cx + $r, (int)($cy - $width * 0.32), $cx + (int)($width * 0.22) + $r, (int)($cy - $width * 0.32) + max(4,(int)($width*0.03)), $accent);
    $name = pathinfo($srcFs, PATHINFO_FILENAME);
    if (strlen($name) > 40) $name = substr($name, 0, 37) . '...';
    $tw = imagefontwidth(3) * strlen($name);
    imagestring($im, 3, (int)(($width - $tw) / 2), (int)($width * 0.86), $name, $muted);
    $ok = @imagejpeg($im, $dstFs, 88);
    imagedestroy($im);
    if ($ok) @chmod($dstFs, 0664);
    return (bool) $ok;
}
}

if (!function_exists('mt_gen_audio_thumb')) {
/**
 * Top-level: produce a cover-art JPEG thumbnail for an audio file at $dstFs.
 * Returns 'cover' (embedded art used), 'placeholder' (no art → note glyph),
 * or throws only on a hard write failure.
 */
function mt_gen_audio_thumb(string $srcFs, string $dstFs, int $maxW = 640, bool $allowPlaceholder = true): string {
    $ext = strtolower(pathinfo($srcFs, PATHINFO_EXTENSION));
    $bytes = null;
    if ($ext === 'mp3') {
        $bytes = mt_id3_extract_apic($srcFs);           // primary, no-exec
        if ($bytes === null) $bytes = mt_ffmpeg_extract_cover($srcFs); // rare mp3 edge
    } else {
        $bytes = mt_ffmpeg_extract_cover($srcFs);        // m4a/flac/etc.
        if ($bytes === null) $bytes = mt_id3_extract_apic($srcFs);    // some carry ID3 too
    }
    if ($bytes !== null && mt_write_cover_jpeg($bytes, $dstFs, $maxW)) {
        return 'cover';
    }
    if ($allowPlaceholder && mt_gen_audio_placeholder($srcFs, $dstFs, min($maxW, 640))) {
        return 'placeholder';
    }
    throw new RuntimeException('No embedded cover art and placeholder disabled.');
}
}
