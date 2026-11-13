<?php
/**
 * Combined Gallery Renderer
 * @file /includes/renderers/combined_gallery.renderer.php
 * @version 2026.01.31
 * @description Renders a combined (mixed-media) gallery by delegating to
 *   existing image/video/pdf renderers for each item.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

@include_once __DIR__ . '/_sort_tools.inc.php';   // front-end sort toolbar (opt-in)

if (!function_exists('render_combined_gallery')) {
  function render_combined_gallery(string $slug, array $opts = []): string {
    $slug = preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    if ($slug === '') return '';

    // Search combined folder first, then all other folders
    $searchDirs = ['combined', 'images', 'videos', 'pdfs'];
    $data = null;
    $foundType = '';
    foreach ($searchDirs as $dir) {
      $path = SITE_ROOT . "/admin/data/galleries/{$dir}/{$slug}.json";
      if (is_file($path)) {
        $raw = @file_get_contents($path);
        $dec = json_decode($raw ?: '', true);
        if (is_array($dec)) { $data = $dec; $foundType = $dir; break; }
      }
    }
    if (!$data) return '';

    $items = $data['items'] ?? [];
    if (!is_array($items) || empty($items)) return '';

    // If found in a typed folder (not combined), delegate to the typed renderer
    if ($foundType === 'images' && function_exists('render_image_gallery')) {
      return render_image_gallery($slug, $opts);
    }
    if ($foundType === 'videos' && function_exists('render_video_gallery')) {
      return render_video_gallery($slug, $opts);
    }
    if ($foundType === 'pdfs' && function_exists('render_pdf_gallery')) {
      return render_pdf_gallery($slug, $opts);
    }

    // Combined gallery rendering
    // Front-end sort tools (opt-in via gallery setting or shortcode attr)
    $stOn  = function_exists('st_enabled') && st_enabled($data, $opts);
    $stDef = $stOn ? st_default($data, $opts) : 'newest';

    $columns  = isset($data['columns'])   ? (int)$data['columns']   : 3;
    $rowheight= isset($data['rowheight']) ? (int)$data['rowheight'] : 240;
    $layout   = $data['layout'] ?? 'grid';
    $max      = isset($opts['max']) && is_numeric($opts['max']) ? (int)$opts['max'] : 0;
    if ($max > 0) $items = array_slice($items, 0, $max);

    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $styleParts = [];
    if ($columns > 0) $styleParts[] = '--cols:' . $columns;
    if ($rowheight > 0) $styleParts[] = '--row-h:' . $rowheight . 'px';
    $style = $styleParts ? ' style="' . implode(';', $styleParts) . '"' : '';
    $layoutAttr = in_array($layout, ['grid','masonry','rows','list'], true)
      ? ' data-layout="' . $h($layout) . '"' : '';

    $out = '<div class="gallery-combined" data-gallery="' . $h($slug) . '"'
         . ' data-lb-group="' . $h($slug) . '"' . $layoutAttr . $style . '>';

    foreach ($items as $item) {
      $path = '';
      $mediaType = '';
      if (is_string($item)) {
        $path = $item;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','avif','svg'])) $mediaType = 'image';
        elseif (in_array($ext, ['mp4','webm','mov','m4v','ogv'])) $mediaType = 'video';
        elseif ($ext === 'pdf') $mediaType = 'pdf';
      } elseif (is_array($item)) {
        $path = $item['path'] ?? '';
        $mediaType = $item['mediaType'] ?? '';
      }
      if ($path === '') continue;

      // Normalize path — ensure leading slash only
      if ($path !== '' && $path[0] !== '/' && !preg_match('~^https?://~i', $path)) {
        $path = '/' . ltrim($path, '/');
      }

      $stAttr = $stOn ? st_item_attrs($path) : '';
      if ($mediaType === 'image') {
        $out .= "<figure class='gi'" . $stAttr . ">"
              .   "<a href='" . $h($path) . "' data-lightbox='image' data-lb-group='" . $h($slug) . "'>"
              .     "<img src='" . $h($path) . "' alt='' loading='lazy'>"
              .   "</a>"
              . "</figure>";

      } elseif ($mediaType === 'video') {
        // Try .cache thumbnail
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $thumbUrl = $dir . '/.cache/' . $base . '.jpg';

        $out .= "<figure class='gv'" . $stAttr . ">"
              .   "<a href='" . $h($path) . "' class='lightbox-trigger' data-lightbox='video' data-lb-group='" . $h($slug) . "'>";
        $out .=     "<img src='" . $h($thumbUrl) . "' alt='Video' loading='lazy'"
              .       " onerror=\"this.outerHTML='<span class=video-thumb-fallback></span>'\">";
        $out .=     "<span class='ri-play-badge' aria-hidden='true'>&#9654;</span>";
        $out .=   "</a></figure>";

      } elseif ($mediaType === 'pdf') {
        $viewer = '/admin/modules/GalleryManager/pdf/pdf-viewer.php?src=' . rawurlencode($path);
        $label  = preg_replace('~\.[Pp][Dd][Ff]$~', '', basename($path));
        // Check for cached PDF thumbnail
        $pdfDir = dirname($path);
        $pdfBase = pathinfo($path, PATHINFO_FILENAME);
        $pdfThumbUrl = $pdfDir . '/.cache/' . $pdfBase . '.jpg';
        $pdfThumbFs = SITE_ROOT . $pdfThumbUrl;
        $hasThumb = is_file($pdfThumbFs);

        $out .= '<div class="gallery-item pdf-item" data-path="' . $h($path) . '"' . $stAttr . '>'
              .   '<a class="thumbnail-wrapper" href="' . $h($viewer) . '" data-lightbox="pdf" data-lb-group="' . $h($slug) . '">';
        if ($hasThumb) {
          $out .=   '<img src="' . $h($pdfThumbUrl) . '" alt="' . $h($label) . '" loading="lazy" style="width:100%;height:auto;display:block;">';
        } else {
          $out .=   '<i class="fas fa-file-pdf pdf-icon" aria-hidden="true"></i>';
        }
        $out .=     '<span class="item-title">' . $h($label) . '</span>'
              .   '</a>'
              . '</div>';
      }
    }

    $out .= '</div>';
    if ($stOn) $out = st_wrap($out, $slug, $stDef);
    return $out;
  }
}
