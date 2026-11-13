<?php
/**
 * Image Gallery Renderer — pure JSON/opts (no fallbacks)
 * @appname   Luminal CMS
 * @file      /includes/renderers/image_gallery.renderer.php
 * @version   2025.09.28.1419.20-EST — r1207 (no defaults, no sidecars) // r1207
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Renders an image gallery using ONLY the manager JSON:
 *     /admin/data/galleries/images/{slug}.json
 *   or explicit $opts passed by caller/shortcode.
 *   No hard-coded defaults, no sidecar lookups, no inference.
 *
 *   Recognized keys (if present): layout, columns, gap, rowheight, square,
 *   fit, radius, shadow, class, max, items|images.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

@include_once __DIR__ . '/_sort_tools.inc.php';   // front-end sort toolbar (opt-in)

/* helpers */
if (!function_exists('__ig_h')) {
  function __ig_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('__ig_abs')) {
  function __ig_abs(string $v): bool { return (bool)preg_match('~^(?:https?://|/)~i', $v); }
}
if (!function_exists('__ig_norm')) {
  function __ig_norm(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return __ig_abs($v) ? $v : '/' . ltrim($v, '/');
  }
}
if (!function_exists('__ig_pick')) {
  function __ig_pick($it): string {
    if (is_string($it)) return $it;
    if (is_array($it)) {
      foreach (['src','path','url','image','file'] as $k) {
        if (!empty($it[$k]) && is_string($it[$k])) return $it[$k];
      }
    }
    return '';
  }
}

/** Normalize only what is explicitly provided. No defaults. */
if (!function_exists('__ig_normalize_no_defaults')) {
  function __ig_normalize_no_defaults(array $raw): array {
    $out = [];
    $get = function(array $keys) use ($raw) {
      foreach ($keys as $k) if (array_key_exists($k, $raw)) return $raw[$k];
      return null;
    };
    $map = [
      'cols'   => ['columns','cols','col'],
      'gap'    => ['gap','gutter','spacing','space'],
      'row_h'  => ['rowheight','rowHeight','row_height','masonry_row_height','row_h'],
      'square' => ['square','force_square','squareTiles','square_tiles'],
      'layout' => ['layout','mode','type'],
      'fit'    => ['fit','object_fit','objectFit'],
      'radius' => ['radius','corner_radius','rounding','border_radius'],
      'shadow' => ['shadow','drop_shadow','elevation'],
      'max'    => ['max','limit'],
      'class'  => ['class','className','cls'],
    ];
    foreach ($map as $norm => $cands) {
      $v = $get($cands);
      if ($v !== null) $out[$norm] = $v;
    }
    return $out;
  }
}

/**
 * Render an image gallery by slug.
 * Uses ONLY JSON + $opts that the caller provides. If a value is absent, it is not set.
 */
if (!function_exists('render_image_gallery')) {
  function render_image_gallery(string $slug, array $opts = []): string {
    $slug = preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    if ($slug === '') return '';

    $jsonPath = SITE_ROOT . "/admin/data/galleries/images/{$slug}.json";
    $data = [];
    if (is_file($jsonPath)) {
      $raw = @file_get_contents($jsonPath);
      if ($raw !== false) {
        $dec = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) $data = $dec;
      }
    }

    // Items only from provided JSON keys
    $items = [];
    if (isset($data['items'])  && is_array($data['items']))  $items = $data['items'];
    elseif (isset($data['images']) && is_array($data['images'])) $items = $data['images'];

    // Options: only pass-through from JSON and/or explicit $opts
    $merged = [];
    foreach (['options','settings'] as $k) {
      if (isset($data[$k]) && is_array($data[$k])) {
        $merged = array_replace($merged, __ig_normalize_no_defaults($data[$k]));
      }
    }
    $merged = array_replace($merged, __ig_normalize_no_defaults($data));
    if (!empty($opts)) $merged = array_replace($merged, __ig_normalize_no_defaults($opts));

    // Optional limit
    if (isset($merged['max']) && is_numeric($merged['max']) && (int)$merged['max'] > 0) {
      $items = array_slice($items, 0, (int)$merged['max']);
    }

    if (empty($items)) return ''; // nothing to show

    // Front-end sort tools (opt-in via gallery setting or shortcode attr)
    $stOn  = function_exists('st_enabled') && st_enabled($data, $opts);
    $stDef = $stOn ? st_default($data, $opts) : 'newest';

    // Build inline style with ONLY provided variables
    $styleParts = [];
    if (isset($merged['cols']))   $styleParts[] = '--cols:' . (int)$merged['cols'];
    if (isset($merged['gap']))    $styleParts[] = '--gap:' . __ig_h((string)$merged['gap']);
    if (isset($merged['row_h'])) {
      $row = (string)$merged['row_h'];
      if (preg_match('~^\d+$~', $row)) $row .= 'px';
      $styleParts[] = '--row-h:' . __ig_h($row);
    }
    if (isset($merged['square'])) $styleParts[] = '--square:' . ((int)$merged['square'] ? 1 : 0);
    if (isset($merged['fit']))    $styleParts[] = '--fit:' . (__ig_h(strtolower((string)$merged['fit'])) === 'contain' ? 'contain' : 'cover');
    if (isset($merged['radius'])) {
      $r = (string)$merged['radius'];
      if (preg_match('~^\d+$~', $r)) $r .= 'px';
      $styleParts[] = '--radius:' . __ig_h($r);
    }
    if (isset($merged['shadow'])) $styleParts[] = '--shadow:' . ((int)$merged['shadow'] ? 1 : 0);

    $classes = 'gallery-images';
    if (!empty($merged['class'])) $classes .= ' ' . preg_replace('~[^a-z0-9_\-\s]~i', '', (string)$merged['class']);

    $layoutAttr = '';
    if (isset($merged['layout'])) {
      $v = strtolower((string)$merged['layout']);
      if (in_array($v, ['grid','masonry','list'], true)) {
        $layoutAttr = ' data-layout="' . __ig_h($v) . '"';
      }
    }

    $style = $styleParts ? ' style="' . implode(';', $styleParts) . '"' : '';

    $out  = '<div class="' . __ig_h($classes) . '" data-gallery="' . __ig_h($slug) . '" data-lightbox="image" data-lb-group="' . __ig_h($slug) . '"' . $layoutAttr . $style . '>';

    $count = 0;
    foreach ($items as $it) {
      $src = __ig_norm(__ig_pick($it));
      if ($src === '') continue;
      $alt = is_array($it) ? (string)($it['alt'] ?? $it['title'] ?? $it['caption'] ?? '') : '';
      $out .= "<figure class='gi'" . ($stOn ? st_item_attrs($src, $alt) : '') . ">"
            .   "<a href='" . __ig_h($src) . "' data-lightbox='image' data-lb-group='" . __ig_h($slug) . "'>"
            .     "<img src='" . __ig_h($src) . "' alt='" . __ig_h($alt) . "'>"
            .   "</a>"
            . "</figure>";
      $count++;
    }

    $out .= '</div>';
    if ($stOn && $count) $out = st_wrap($out, $slug, $stDef);
    return $count ? $out : '';
  }
}
?>