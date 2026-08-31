<?php
/**
 * Video Gallery Renderer — JSON/opts with Lightbox & Layout Fixes
 * @appname   Luminal Open CMS
 * @file      /includes/renderers/video_gallery.renderer.php
 * @version   2025.10.13.17.00.00-EST — Fixed ghost placeholders, lightbox, layout, duplicates
 * @author    Claude (Anthropic)
 * @license   Business Source License
 * @description
 *   Renders a video gallery using ONLY the manager JSON:
 *     /admin/data/galleries/videos/{slug}.json
 *   or explicit $opts passed by caller/shortcode.
 *
 *   FIXES:
 *   - Ghost placeholder only shows when NO thumbnail exists
 *   - Lightbox properly configured for modal viewing
 *   - No duplicate thumbnails or play badges
 *   - Respects JSON layout settings (masonry, grid, rows)
 *   - Respects columns, gap, rowheight from JSON
 *   - No captions/titles displayed (per user request)
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

@include_once __DIR__ . '/_sort_tools.inc.php';   // front-end sort toolbar (opt-in)

if (!function_exists('__vg_h')) {
  function __vg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('__vg_norm')) {
  function __vg_norm(?string $s): string {
    $s = (string)($s ?? '');
    return trim($s);
  }
}
if (!function_exists('__vg_abs')) {
  function __vg_abs(string $p): string {
    if (preg_match('~^https?://~i', $p)) return $p;
    if ($p === '') return '';
    $p = '/' . ltrim($p, '/');
    return $p;
  }
}
if (!function_exists('__vg_src')) {
  /** Accepts item as string or array; returns canonical src/url */
  function __vg_src($it): string {
    if (is_string($it)) return $it;
    if (is_array($it)) {
      $src = (string)($it['src'] ?? $it['url'] ?? $it['path'] ?? '');
      return $src;
    }
    return '';
  }
}
if (!function_exists('__vg_thumb')) {
  /** Returns thumbnail path if provided */
  function __vg_thumb($it): string {
    if (is_array($it)) {
      return (string)($it['thumb'] ?? $it['thumbnail'] ?? '');
    }
    return '';
  }
}
if (!function_exists('__vg_is_youtube')) {
  /** Check if URL is a YouTube video */
  function __vg_is_youtube(string $url): bool {
    return (bool)preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)~i', $url);
  }
}
if (!function_exists('__vg_get_youtube_id')) {
  /** Extract YouTube video ID from URL */
  function __vg_get_youtube_id(string $url): string {
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_\-]{6,})~i', $url, $m)) {
      return $m[1];
    }
    return '';
  }
}
if (!function_exists('__vg_get_youtube_thumb')) {
  /** Get YouTube thumbnail URL from video URL */
  function __vg_get_youtube_thumb(string $url): string {
    $id = __vg_get_youtube_id($url);
    if ($id !== '') {
      return 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    }
    return '';
  }
}

if (!function_exists('render_video_gallery')) {
  /**
   * @param string $slug  Gallery slug (filename without .json)
   * @param array  $opts  Optional overrides: layout, columns, gap, rowheight, fit, class
   * @return string HTML
   */
  function render_video_gallery(string $slug, array $opts = []): string {
    $slug = trim($slug);
    if ($slug === '') return '';

    $json = SITE_ROOT . "/admin/data/galleries/videos/{$slug}.json";
    $items = [];
    $meta  = [];
    $data  = [];

    if (is_file($json)) {
      $dec = json_decode((string)@file_get_contents($json), true);
      if (is_array($dec)) {
        $data  = $dec;
        $items = $data['items'] ?? $data['files'] ?? $data['videos'] ?? [];
        if (!is_array($items)) $items = [];
        // collect known meta if present
        foreach (['layout','columns','gap','rowheight','fit','class'] as $k) {
          if (array_key_exists($k, $data)) $meta[$k] = $data[$k];
        }
      }
    }

    // Front-end sort tools (opt-in via gallery setting or shortcode attr)
    $stOn  = function_exists('st_enabled') && st_enabled($data, $opts);
    $stDef = $stOn ? st_default($data, $opts) : 'newest';

    // Merge opts (opts take precedence if provided)
    $merged = $meta;
    foreach (['layout','columns','gap','rowheight','fit','class'] as $k) {
      if (array_key_exists($k, $opts)) $merged[$k] = $opts[$k];
    }

    // Build CSS variables (only if provided; no defaults)
    $styleParts = [];
    if (isset($merged['columns'])) {
      $cols = (int)$merged['columns'];
      if ($cols > 0) $styleParts[] = '--cols:' . $cols;
    }
    if (isset($merged['gap'])) {
      $g = (string)$merged['gap'];
      if (preg_match('~^\d+$~', $g)) $g .= 'px';
      $styleParts[] = '--gap:' . __vg_h($g);
    }
    if (isset($merged['rowheight'])) {
      $rh = (string)$merged['rowheight'];
      if (preg_match('~^\d+$~', $rh)) $rh .= 'px';
      $styleParts[] = '--row-h:' . __vg_h($rh);
    }
    if (isset($merged['fit'])) {
      $fit = strtolower((string)$merged['fit']);
      if (in_array($fit, ['cover','contain','fill','scale-down','none'], true)) {
        $styleParts[] = '--fit:' . __vg_h($fit);
      }
    }

    $classes = 'gallery-videos';
    if (!empty($merged['class'])) {
      $classes .= ' ' . preg_replace('~[^a-z0-9_\-\s]~i', '', (string)$merged['class']);
    }

    $layoutAttr = '';
    if (isset($merged['layout'])) {
      $v = strtolower((string)$merged['layout']);
      if (in_array($v, ['grid','masonry','rows','list'], true)) {
        $layoutAttr = ' data-layout="' . __vg_h($v) . '"';
      }
    }

    $style = $styleParts ? ' style="' . implode(';', $styleParts) . '"' : '';

    $out  = '<div class="' . __vg_h($classes) . '" data-gallery="videos" data-lb-group="' . __vg_h($slug) . '"' . $layoutAttr . $style . '>';

    $count = 0;
    foreach ($items as $it) {
      $href  = __vg_abs(__vg_norm(__vg_src($it)));
      if ($href === '') continue;

      $thumb = __vg_abs(__vg_norm(__vg_thumb($it)));
      
      // Enhanced thumbnail fallback system
      // 1. Check if explicit thumbnail is provided
      // 2. If YouTube, auto-generate thumbnail
      // 3. Otherwise leave empty (will show placeholder via CSS)
      if ($thumb === '') {
        if (__vg_is_youtube($href)) {
          $thumb = __vg_get_youtube_thumb($href);
        }
      }
      
      $title = is_array($it) ? (string)($it['title'] ?? $it['caption'] ?? $it['label'] ?? '') : '';
      
      // ALWAYS use lightbox for galleries
      // This ensures modal viewing with borderless, minimal controls
      
      // Build thumbnail or placeholder
      if ($thumb !== '') {
        // We have a real thumbnail - use it
        $thumbTag = "<img src='" . __vg_h($thumb) . "' alt='Video thumbnail' loading='lazy' class='video-thumb'>";
      } else {
        // No thumbnail available - show placeholder ONLY
        $thumbTag = "<span class='video-thumb-fallback' aria-label='Video thumbnail'></span>";
      }

      // Single play badge
      $playBadge = "<span class='ri-play-badge' aria-hidden='true'>▶</span>";
      
      // NO CAPTIONS - per user request, we don't display titles/captions
      
      // Lightbox mode ALWAYS for proper modal viewing
      $out .= "<figure class='gv'" . ($stOn ? st_item_attrs($href, $title) : '') . ">"
           .    "<a href='" . __vg_h($href) . "' class='lightbox-trigger' data-lightbox='video' data-lb-group='" . __vg_h($slug) . "'>"
           .      $thumbTag
           .      $playBadge
           .    "</a>"
           .  "</figure>";
      
      $count++;
    }

    $out .= '</div>';
    if ($stOn && $count) $out = st_wrap($out, $slug, $stDef);

    // Return empty string if no videos were rendered
    return $count ? $out : '';
  }
}
?>
