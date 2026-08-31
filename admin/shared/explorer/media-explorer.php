<?php
/**
 * Luminal CMS — Media Explorer (iframe host)
 * @file /admin/shared/explorer/media-explorer.php
 *
 * Thin host page for the reusable Explorer engine, scoped to /media. Drop-in
 * replacement for /admin/shared/media_browser.php — same backend
 * (ajax_media_handler.php) and the same parent-window contract
 * (mediaSelected / media-pick / mb-drag-start|end).
 *
 * Query params (all optional):
 *   host=contentstacks   click = select-only (drag inserts)
 *   mode=picker          click emits media-pick (HTMLEditor)
 *   types=images,videos  restrict the visible tabs
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireAuth();

$host  = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['host'] ?? ''));
$mode  = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['mode'] ?? ''));
$types = preg_replace('/[^a-z0-9_,\-]/i', '', (string)($_GET['types'] ?? 'images,videos,pdfs,audio'));
$v     = max((int)@filemtime(__DIR__ . '/explorer.js'), (int)@filemtime(__DIR__ . '/explorer.css')) ?: time();   // bust on either asset
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Media Explorer</title>
  <link rel="stylesheet" href="/admin/shared/explorer/explorer.css?v=<?= $v ?>">
  <link rel="stylesheet" href="/admin/shared/upload-progress/upload-progress.css">
</head>
<body class="ex-body" id="ex-root">

  <div class="ex-toolbar">
    <div class="ex-tabs" id="ex-tabs"></div>
    <div class="ex-sep"></div>

    <div class="ex-viewtoggle" title="View mode">
      <button class="ex-btn" id="ex-view-grid" title="Thumbnails"><span class="ex-btn-ico">&#9638;</span></button>
      <button class="ex-btn" id="ex-view-list" title="List / details"><span class="ex-btn-ico">&#9776;</span></button>
    </div>
    <div class="ex-zoom" id="ex-zoom" title="Thumbnail size">
      <span class="ex-zoom-ico">&#9638;</span>
      <input type="range" id="ex-zoom-range" min="90" max="320" step="10" aria-label="Thumbnail size">
      <span class="ex-zoom-ico big">&#9638;</span>
      <button class="ex-btn" id="ex-fit" title="Square crop / natural">&#9635;</button>
    </div>

    <div class="ex-sort" title="Sort">
      <select id="ex-sort">
        <option value="name:asc">Name A–Z</option>
        <option value="name:desc">Name Z–A</option>
        <option value="mtime:desc">Newest</option>
        <option value="mtime:asc">Oldest</option>
        <option value="size:desc">Largest</option>
        <option value="size:asc">Smallest</option>
      </select>
    </div>

    <div class="ex-search">
      <span class="ex-search-ico">&#128269;</span>
      <input type="text" id="ex-search-input" placeholder="Filter…" spellcheck="false">
    </div>

    <div class="ex-spacer"></div>

    <button class="ex-btn" id="ex-selectall">Select All</button>
    <button class="ex-btn" id="ex-move" disabled title="Move selected">&#8644; Move</button>
    <button class="ex-btn danger" id="ex-delete" disabled title="Delete selected">&#128465; Delete</button>
    <div class="ex-sep"></div>
    <button class="ex-btn" id="ex-newfolder" title="New folder">&#128193;+ Folder</button>
    <button class="ex-btn primary" id="ex-upload" title="Upload files">&#8679; Upload</button>
    <input type="file" id="ex-fileinput" multiple style="display:none">
  </div>

  <?php if ($host === 'pagemanager'): ?>
    <div class="ex-hint">Click to preview &bull; Drag into the editor to insert (media or shortcode) &bull; Inserts tab = stacks, galleries, widgets &amp; more &bull; Right-click media for Rename / Move / Delete</div>
  <?php elseif ($host === 'contentstacks'): ?>
    <div class="ex-hint">Click to preview &bull; Drag into the stack to insert (media or shortcode) &bull; Inserts tab = stacks, galleries, widgets &amp; more &bull; Right-click media for Rename / Move / Delete</div>
  <?php elseif ($host === 'gallery'): ?>
    <div class="ex-hint">Click to preview &bull; Drag into the tray to add (or use the preview&rsquo;s Add button) &bull; Drag onto a folder to move &bull; Ctrl/Click to multi-select &bull; Right-click for Rename / Move / Delete</div>
  <?php elseif ($mode === 'picker' || $host !== ''): ?>
    <div class="ex-hint">Click to preview, then &ldquo;Use This File&rdquo; &bull; Drag onto a folder to move &bull; Ctrl/Click to multi-select &bull; Right-click for Rename / Move / Delete</div>
  <?php endif; ?>

  <div class="ex-status" id="ex-status"></div>
  <div class="ex-body-scroll" id="ex-body"></div>

  <script>
    window.EXPLORER_BOOT = {
      handler: '/admin/shared/ajax_media_handler.php',
      host: <?= json_encode($host) ?>,
      mode: <?= json_encode($mode) ?>,
      types: <?= json_encode($types) ?>
    };
  </script>
  <script src="/admin/shared/upload-progress/upload-progress.js"></script>
  <script src="/admin/shared/explorer/explorer.js?v=<?= $v ?>"></script>
</body>
</html>
