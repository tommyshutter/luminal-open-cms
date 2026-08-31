<?php
/**
 * Media Thumbnails & Cache Utility – Admin Panel
 * @file   admin/panels/media-cache-panels.php
 * @ver    2025.09.05.23.59.00-EST
 * @desc   UI for scanning, flushing, and rebuilding media thumbnails/caches.
 */
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Media Thumbnails & Cache Utility</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- same CSS as before -->
</head>
<body>
  <h1>Media Thumbnails & Cache Utility</h1>
  <div class="toolbar">
    <button id="btn-rescan">Rescan</button>
    <button id="btn-flush-all">Flush All Caches</button>
    <button id="btn-rebuild-all">Rebuild All Thumbnails</button>
    <span id="meta" class="muted"></span>
  </div>
  <div class="progress" id="prog" style="display:none"><div class="bar" id="bar"></div></div>
  <div id="cards" class="cards"><div class="card muted">Scanning…</div></div>

<script>
const API = '/admin/modules/MediaThumbs/api/media-cache.php';

// same JS logic as before, only difference is API path above
</script>
</body>
</html>
