<?php
/**
 * embed.php — serve ONE HTML Block, bare, for another site to frame.
 *
 *   https://SITE/embed.php?block=SLUG
 *
 * The block is the container (admin/data/html-blocks/SLUG.json); this file is only the window.
 * It is CMS code, not a page, so nothing in the admin can delete it by accident.
 *
 * Nothing is served unless THIS site lists the block in admin/data/html-blocks-embed.json:
 *   { "bg-starmap": { "origins": ["https://example.com"], "css_marker": "starmap" } }
 *   origins     the only sites allowed to frame it (CSP frame-ancestors)
 *   css_marker  optional: the block's styles live in admin/data/site-custom.css between
 *               ⟪NAME …⟫ and ⟪/NAME⟫ markers (plus the file's leading @imports for fonts)
 *
 * The page is see-through (the host's background shows behind the block), links open in the
 * host's window, and the frame's height is posted to the host so it can size the iframe.
 */
declare(strict_types=1);

define('SITE_ROOT', __DIR__);
require_once __DIR__ . '/admin/modules/HTMLBlocks/includes/renderers/html_block.renderer.php';

function emb_fail(int $code): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Frame-Options: DENY');
    echo $code === 404 ? "Not found\n" : "Not available\n";
    exit;
}

$slug = (string)($_GET['block'] ?? '');
if (!preg_match('/^[a-z0-9_-]{1,80}$/i', $slug)) emb_fail(404);

$allow = json_decode((string)@file_get_contents(SITE_ROOT . '/admin/data/html-blocks-embed.json'), true);
$cfg = is_array($allow) && is_array($allow[$slug] ?? null) ? $allow[$slug] : null;
if (!$cfg) emb_fail(404);
if (!is_file(SITE_ROOT . '/admin/data/html-blocks/' . $slug . '.json')) emb_fail(404);

$origins = [];
foreach ((array)($cfg['origins'] ?? []) as $o) {
    $o = rtrim((string)$o, '/');
    if (preg_match('~^https://[a-z0-9.-]+(:\d+)?$~i', $o)) $origins[] = $o;
}
if (!$origins) emb_fail(403);

/* The block's own styles, when they live in site-custom.css between markers. */
$imports = ''; $css = '';
$marker = (string)($cfg['css_marker'] ?? '');
if ($marker !== '' && preg_match('/^[a-z0-9_-]+$/i', $marker)) {
    $all = (string)@file_get_contents(SITE_ROOT . '/admin/data/site-custom.css');
    if (preg_match_all('/^\s*@import\s+url\([^)]*\)\s*;/mi', $all, $m)) $imports = implode("\n", array_map('trim', $m[0])) . "\n";
    $q = preg_quote($marker, '/');
    // The markers sit inside CSS comments: take what lies between the comment that holds the
    // opening marker and the comment that holds the closing one, never half a comment.
    if (preg_match('/\/\*[^*]*?\x{27EA}' . $q . '\b.*?\*\/(.*?)\/\*[^*]*?\x{27EA}\/' . $q . '\x{27EB}[^*]*\*\//su', $all, $m)) $css = $m[1];
}
$imports = preg_replace('~</style~i', '<\\/style', $imports) ?? '';
$css = preg_replace('~</style~i', '<\\/style', $css) ?? '';

header_remove('X-Frame-Options');
header("Content-Security-Policy: frame-ancestors 'self' " . implode(' ', $origins));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: public, max-age=300');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<base target="_top">
<title><?= htmlspecialchars($slug, ENT_QUOTES) ?></title>
<style>
<?= $imports /* @import must come first or the browser ignores it */ ?>
html, body { margin: 0; padding: 0; background: transparent !important; overflow: hidden; }
<?= $css ?>
</style>
</head>
<body class="ri-embed">
<?= render_html_block($slug) ?>
<script>
/* A #link to something that lives on the source site's page but not in this block (e.g. the
   directory under the map) would open this bare page full-screen: send it to the real page. */
(function () {
  var home = <?= json_encode('https://' . preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) . '/') ?>;
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    var id = a.getAttribute('href').slice(1);
    if (id && !document.getElementById(id)) a.setAttribute('href', home + '#' + id);
  });
})();
(function () {
  var origins = <?= json_encode($origins, JSON_UNESCAPED_SLASHES) ?>, last = 0;
  function post() {
    var h = Math.ceil(document.documentElement.scrollHeight);
    if (h === last) return;
    last = h;
    origins.forEach(function (o) {
      try { parent.postMessage({ luminalEmbed: <?= json_encode($slug) ?>, height: h }, o); } catch (e) {}
    });
  }
  if (window.ResizeObserver) new ResizeObserver(post).observe(document.body);
  window.addEventListener('load', post);
  post();
})();
</script>
</body>
</html>
