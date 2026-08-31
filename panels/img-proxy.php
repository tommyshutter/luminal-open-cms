<?php
/**
 * @file   SITE-ROOT/panels/img-proxy.php
 * @desc   Local-only image passthrough so .cache thumbs and other protected paths
 *         can be served to the browser. No cross-domain.
 * @ver    2025.09.05.22.05.00-EST
 */
declare(strict_types=1);

define('SITE_ROOT', realpath(__DIR__ . '/..'));
$DOCROOT = realpath($_SERVER['DOCUMENT_ROOT'] ?? SITE_ROOT) ?: SITE_ROOT;

function bad(string $msg, int $code=400): never {
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $msg; exit;
}
function to_local_path(string $src, string $docroot): ?string {
  // Accept site-relative path or absolute same-origin URL
  if (preg_match('#^https?://#i', $src)) {
    $u = parse_url($src);
    if (!$u || empty($u['host'])) return null;
    $reqHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $uh = strtolower(preg_replace('/:\d+$/','',trim($u['host'],'[]')));
    $rh = strtolower(preg_replace('/:\d+$/','',trim($reqHost,'[]')));
    if ($uh !== $rh) return null;        // no cross-origin
    $path = $u['path'] ?? '/';
  } else {
    $path = $src;
  }
  $path = urldecode($path);
  if ($path === '' || $path[0] !== '/') $path = '/' . $path;
  $full = realpath($docroot . $path);
  if ($full === false) return null;
  $doc = realpath($docroot) ?: $docroot;
  if (strpos($full, $doc) !== 0) return null;
  if (!is_file($full)) return null;
  $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return null;
  return $full;
}

$raw = $_GET['src'] ?? '';
if ($raw === '') bad('Missing src', 400);

$full = to_local_path($raw, $DOCROOT);
if (!$full) bad('Image not found or not same-origin', 404);

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = [
  'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
  'webp'=>'image/webp','gif'=>'image/gif'
][$ext] ?? 'application/octet-stream';

header('Content-Type: '.$mime);
header('Cache-Control: private, max-age=600');
header('X-Frame-Options: SAMEORIGIN');
$fp = @fopen($full, 'rb'); if(!$fp) bad('Open failed', 500);
fpassthru($fp); fclose($fp); exit;
?>