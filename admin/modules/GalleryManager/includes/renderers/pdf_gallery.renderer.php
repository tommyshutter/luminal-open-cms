<?php
/**
 * @appname   Luminal CMS
 * @file      /includes/renderers/pdf_gallery.renderer.php
 * @version   2025.09.26.0006.00-EST (admin DOM + admin CSS + FA CDN; no inventions)
 * @author    ChatGPT
 * @description
 *   Public PDF gallery renderer that clones the Admin PDF Gallery Manager markup & styling.
 *   - Loads Font Awesome (CDN) and the SAME admin CSS files.
 *   - Emits the exact card DOM used in Admin (gallery-item/pdf-item/thumbnail-wrapper/overlay/pdf-icon/item-title).
 *   - Click opens /admin/modules/GalleryManager/pdf/pdf-viewer.php?src=... (viewer → proxy).
 */

declare(strict_types=1);

@include_once __DIR__ . '/_sort_tools.inc.php';   // front-end sort toolbar (opt-in)

/* helpers */
if (!function_exists('__pg_h'))  { function __pg_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('__pg_abs')){ function __pg_abs(string $v): bool { return (bool)preg_match('~^(?:https?://|/)~i', $v); } }
if (!function_exists('__pg_norm')){
  function __pg_norm(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return __pg_abs($v) ? $v : '/' . ltrim($v, '/');
  }
}
if (!function_exists('__pg_src')){
  function __pg_src($it): string {
    if (is_string($it)) return $it;
    if (is_array($it)) foreach (['src','url','file','path','href','pdf'] as $k) if (!empty($it[$k]) && is_string($it[$k])) return $it[$k];
    return '';
  }
}
if (!function_exists('__pg_filename_label')){
  function __pg_filename_label(string $href): string {
    $path = parse_url($href, PHP_URL_PATH) ?? '';
    $base = basename($path);
    return preg_replace('~\.[Pp][Dd][Ff]$~', '', $base); // strip .pdf only
  }
}

/* emit assets once (FA CDN + Admin CSS) */
if (!function_exists('ri_pdf_admin_skin_once')) {
  function ri_pdf_admin_skin_once(): string {
    static $done = false;
    if ($done) return '';
    $done = true;

    $faCdn = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';

    // IMPORTANT: these are the exact admin styles you told me to reuse.
    $links = [
      '<link rel="stylesheet" href="'.$faCdn.'" crossorigin="anonymous" referrerpolicy="no-referrer">',
      '<link rel="stylesheet" href="/admin/css/pdf-galleries.css">',
      '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-browser.css">',
      '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-editor.css">',
      '<link rel="stylesheet" href="/admin/css/pdf-gallery-manager-layout.css">',
    ];
    return implode("\n", $links) . "\n";
  }
}

/* renderer (admin DOM on public) */
if (!function_exists('render_pdf_gallery')){
  function render_pdf_gallery(string $slug, array $opts = []): string {
    $slug = preg_replace('~[^a-z0-9_\-]~i','', $slug);
    if ($slug === '') return '';

    $json = SITE_ROOT . "/admin/data/galleries/pdfs/{$slug}.json";
    if (!is_file($json)) return '';

    $data  = json_decode((string)@file_get_contents($json), true);
    $items = is_array($data) ? ($data['items'] ?? $data) : [];
    if (!is_array($items)) $items = [];

    $max   = isset($opts['max']) && is_numeric($opts['max']) ? max(0, (int)$opts['max']) : 0;
    $items = $max > 0 ? array_slice($items, 0, $max) : $items;

    // Front-end sort tools (opt-in via gallery setting or shortcode attr)
    $stData = is_array($data) ? $data : [];
    $stOn   = function_exists('st_enabled') && st_enabled($stData, $opts);
    $stDef  = $stOn ? st_default($stData, $opts) : 'newest';

    $skin = ri_pdf_admin_skin_once();

    // Wrapper structure mirrors admin ("Available PDFs") list:
    // <div id="available-pdfs-container" class="gallery-container"> ... </div>
    $out = '<div id="available-pdfs-container" class="gallery-container"'
          . ' data-gallery="'.__pg_h($slug).'" data-lightbox="pdf" data-lb-group="'.__pg_h($slug).'">';

    foreach ($items as $it) {
      $srcRaw = __pg_src($it);
      $href   = __pg_norm($srcRaw);
      if ($href === '') continue;

      $viewer = '/admin/modules/GalleryManager/pdf/pdf-viewer.php?src=' . rawurlencode($href);
      $label  = __pg_filename_label($href);

      // Check for cached PDF thumbnail
      $pdfDir = dirname($href);
      $pdfBase = pathinfo($href, PATHINFO_FILENAME);
      $pdfThumbUrl = $pdfDir . '/.cache/' . $pdfBase . '.jpg';
      $pdfThumbFs = SITE_ROOT . $pdfThumbUrl;
      $hasThumb = is_file($pdfThumbFs);

      $out .=
        '<div class="gallery-item pdf-item"'
          .' data-path="'.__pg_h($href).'"'
          .' data-name="'.__pg_h($label).'"'
          .' data-thumb="'.($hasThumb ? __pg_h($pdfThumbUrl) : '').'"'
          .($stOn ? st_mtime_attr($href) : '').'>'
            .'<a class="thumbnail-wrapper" href="'.__pg_h($viewer).'" data-lightbox="pdf" data-lb-group="'.__pg_h($slug).'">'
              .'<div class="overlay"></div>';
      if ($hasThumb) {
        $out .= '<img src="'.__pg_h($pdfThumbUrl).'" alt="'.__pg_h($label).'" loading="lazy" style="width:100%;height:auto;display:block;">';
      } else {
        $out .= '<i class="fas fa-file-pdf pdf-icon" aria-hidden="true"></i>';
      }
      $out .=   '<span class="item-title">'.__pg_h($label).'</span>'
            .'</a>'
        .'</div>';
    }

    $out .= '</div>';
    if ($stOn) $out = st_wrap($out, $slug, $stDef);
    return $skin . $out;
  }
}
?>