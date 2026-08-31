<?php
/**
 * @appname   Luminal CMS
 * @file      /admin/modules/GalleryManager/pdf/pdf-proxy.php  (evac'd from /panels/ 2026-06-08)
 * @version   2025.09.25.1458.00-EST (frame-ancestors + XFO override)
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Same-origin PDF proxy for lightbox iframes. THE PDF-in-lightbox engine — do not retire.
 *   Same-origin PDF proxy for lightbox iframes.
 *   - Accepts ?src= pointing to:
 *       • local: /media/... (.pdf)
 *       • allowlisted external HTTPS domains (passthrough bytes, strip remote headers)
 *   - Streams application/pdf with inline disposition.
 *   - Explicitly ALLOWS iframe embedding via CSP and overrides X-Frame-Options.
 */

declare(strict_types=1);

/* ---------- Env ---------- */
// Depth-robust SITE_ROOT: walk up to the dir that has media/ + admin/ (survives relocation)
if (!defined('SITE_ROOT')) {
  $d = __DIR__;
  while ($d !== '/' && $d !== '' && !(is_dir($d . '/media') && is_dir($d . '/admin'))) { $d = dirname($d); }
  define('SITE_ROOT', $d);
}

/* ---------- Input ---------- */
$src = isset($_GET['src']) ? (string)$_GET['src'] : '';
if ($src === '') {
  http_response_code(400);
  echo 'Missing src.';
  exit;
}

/* ---------- Allowlist (expand as needed) ---------- */
$ALLOW = [
  // Example: add an external host if PDFs live there
  // 'example.com',
  // 'cdn.example.com',
  // 'assets.example.com',
];

/* ---------- Helpers ---------- */
function is_pdf_path(string $s): bool {
  return (bool)preg_match('~\.pdf(?:$|\?)~i', $s);
}
function is_https(string $s): bool {
  return (bool)preg_match('~^https://~i', $s);
}
function allowed_host(string $host, array $allow): bool {
  $host = strtolower($host);
  foreach ($allow as $a) {
    if ($host === strtolower($a)) return true;
  }
  return false;
}

/**
 * Send headers that guarantee iframe embedding works even if server-level defaults
 * try to block it. Modern browsers give CSP frame-ancestors precedence.
 */
function emit_embed_headers(string $downloadName): void {
  // Attempt to clear conflicting headers first
  if (function_exists('header_remove')) {
    @header_remove('X-Frame-Options');
    @header_remove('Content-Security-Policy');
  }
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, max-age=600');
  header('Accept-Ranges: bytes');

  // Allow embedding from same-origin explicitly. (Overrides XFO in modern browsers.)
  header("Content-Security-Policy: frame-ancestors 'self'");

  // Optional hardening: CORP/COEP can be added if you deploy a PDF.js viewer.
  // header('Cross-Origin-Resource-Policy: same-origin');
}

/* ---------- Local file branch ---------- */
if ($src[0] === '/') {
  // Normalize and confine to /media
  $real = realpath(SITE_ROOT . $src);
  $mediaRoot = realpath(SITE_ROOT . '/media');
  if (!$real || !$mediaRoot || strpos($real, $mediaRoot) !== 0 || !is_pdf_path($real)) {
    http_response_code(403);
    echo 'Blocked.';
    exit;
  }
  if (!is_file($real) || !is_readable($real)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
  }

  emit_embed_headers(basename($real));
  // Content-Length helps some PDF plugins 
  $size = @filesize($real);
  if (is_int($size) && $size > 0) {
    header('Content-Length: ' . $size);
  }

  // Stream the file
  $fp = fopen($real, 'rb');
  if ($fp) {
    fpassthru($fp);
    fclose($fp);
  }
  exit;
}

/* ---------- External HTTPS passthrough (allowlisted) ---------- */
if (is_https($src)) {
  $parts = parse_url($src);
  $host  = $parts['host'] ?? '';
  if ($host === '' || !allowed_host($host, $ALLOW) || !is_pdf_path($src)) {
    http_response_code(403);
    echo 'Blocked.';
    exit;
  }

  // Stream without forwarding remote headers (we control ours).
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 10,
      'follow_location' => 1,
      'header' => "User-Agent: EarlProxy/1.0\r\n",
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ],
  ]);

  $fp  = @fopen($src, 'rb', false, $ctx);
  if (!$fp) {
    http_response_code(404);
    echo 'Not found.';
    exit;
  }

  $dlName = basename(parse_url($src, PHP_URL_PATH) ?? 'document.pdf');
  emit_embed_headers($dlName);

  // Chunked relay
  while (!feof($fp)) {
    $buf = fread($fp, 8192);
    if ($buf === false) break;
    echo $buf;
    // Optional: flush to keep UI snappy
    @flush();
  }
  fclose($fp);
  exit;
}

/* ---------- Fallback ---------- */
http_response_code(403);
echo 'Fuckin Blocked Bro ...';
?>