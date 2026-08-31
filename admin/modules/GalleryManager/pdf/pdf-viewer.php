<?php
/**
 * @appname   Luminal CMS
 * @file      /admin/modules/GalleryManager/pdf/pdf-viewer.php  (evac'd from /panels/ 2026-06-08)
 * @version   2025.09.25.1912.00-EST (fit-to-width default + robust sizing)
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Same-origin PDF viewer shell for the lightbox iframe.
 *   - Embeds pdf-proxy.php?src=... with a hash that forces page-width zoom.
 *   - Works in Chrome/Edge/Safari/Firefox; falls back gracefully.
 */

declare(strict_types=1);

// Depth-robust SITE_ROOT (survives relocation)
if (!defined('SITE_ROOT')) {
  $d = __DIR__;
  while ($d !== '/' && $d !== '' && !(is_dir($d . '/media') && is_dir($d . '/admin'))) { $d = dirname($d); }
  define('SITE_ROOT', $d);
}

$src = isset($_GET['src']) ? (string)$_GET['src'] : '';
if ($src === '') {
  http_response_code(400);
  echo 'Missing src';
  exit;
}

$proxied = '/admin/modules/GalleryManager/pdf/pdf-proxy.php?src=' . rawurlencode($src);

/* -------- viewer hint: favor "page width" --------
 * Chrome/Edge/Firefox honor #zoom=page-width
 * Safari is inconsistent but generally respects it too.
 * We also include view=FitH as a fallback in some engines.
 */
$hintParts = [
  'zoom=page-width',
  'pagemode=none'
];
$hash = '#' . implode('&', $hintParts);

// Loosen embedding for THIS html (CSP wins over any XFO)
if (function_exists('header_remove')) {
  @header_remove('X-Frame-Options');
  @header_remove('Content-Security-Policy');
}
header("Content-Security-Policy: frame-ancestors 'self'");

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Document</title>
  <style>
    /* Fill the iframe completely */
    html,body{height:100%;margin:0;background:#111;}
    .pdf-wrap{position:fixed;inset:0;}
    .pdf-embed{
      position:absolute; inset:0;
      width:100% !important; height:100% !important;
      border:0; display:block; background:#111;
    }
    /* optional edge shade */
    .shade{position:absolute; left:0; right:0; top:0; height:24px; background:linear-gradient(rgba(0,0,0,.55),transparent);}
  </style>
</head>
<body>
  <div class="pdf-wrap">
    <div class="shade" aria-hidden="true"></div>
    <embed id="pdfFrame" class="pdf-embed" type="application/pdf"
           src="<?php echo htmlspecialchars($proxied . $hash, ENT_QUOTES); ?>">
  </div>

  <script>
    // Belt & suspenders: if a viewer ignores our initial hash, re-apply page-width once.
    (function(){
      const frame = document.getElementById('pdfFrame');
      if (!frame) return;
      // Re-assert page-width zoom shortly after load
      setTimeout(() => {
        try {
          const u = new URL(frame.src, location.origin);
          const currentHash = u.hash || '';
          if (!/zoom=page-width/i.test(currentHash)) {
            u.hash = (currentHash ? currentHash.replace(/^#?/, '#') + '&' : '#') + 'zoom=page-width';
            frame.src = u.toString();
          }
        } catch (e) { /* no-op */ }
      }, 350);
    })();
  </script>

</body>
</html>
