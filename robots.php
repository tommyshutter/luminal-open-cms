<?php
/**
 * robots.txt with absolute Sitemap and Llms addresses. Search engines ignore a
 * relative "Sitemap: /sitemap.xml". The text itself stays in robots.txt (one file for every site);
 * this only rewrites those two lines for the site being asked. Served by the site's .htaccess:
 *     RewriteRule ^robots\.txt$ robots.php [L]
 * A site without that rule keeps serving the static file, unchanged.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$h = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$h = (string)preg_replace('/^www\./', '', $h);
if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,252}$/', $h)) $h = '';
$txt = (string)@file_get_contents(__DIR__ . '/robots.txt');
if ($h !== '') {
    $base = 'https://' . $h;
    $txt = (string)preg_replace('/^(\s*(?:Sitemap|Llms):\s*)\/(\S*)/mi', '${1}' . $base . '/$2', $txt);
}
echo $txt;
