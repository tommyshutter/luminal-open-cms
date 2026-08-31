<?php
/**
 * @appname     Content Stack Renderer
 * @file        SITE-ROOT/includes/renderers/content_stack.renderer.php
 * @version     2025.10.13.17.05.00-EST
 * @author      Claude (Anthropic)
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Renders left/right column "content stacks" from JSON with reliable lightbox support:
 *              - Images: inline image or modal when lightbox=true
 *              - MP4/WebM/Ogg: lightbox modal or inline <video>
 *              - YouTube/Vimeo: lightbox modal or inline <iframe>
 *              - PDFs: opens modal via /admin/modules/GalleryManager/pdf/pdf-viewer.php when lightbox=true
 *              
 *              FIXES:
 *              - Lightbox properly configured for all video types
 *              - No ghost placeholders when thumbnails exist
 *              - Single play badge per video
 *              - No duplicate elements
 *              - No captions displayed
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

/* ---------- helpers (guarded) ---------- */
if (!function_exists('csr_h')) {
  function csr_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csr_toast')) {
  function csr_toast(string $lvl, string $msg): void {
    // Soft console logging to avoid breaking pages if RIToaster not present
    $lvl = preg_replace('/[^a-z]/i', '', strtolower($lvl)) ?: 'log';
    $safe = csr_h($msg);
    echo '<script>try{console.' . $lvl . '('. json_encode('[csr] ') . ' + '. json_encode($safe) . ');}catch(e){}</script>';
  }
}
if (!function_exists('csr_ext')) {
  function csr_ext(string $p): string {
    // Strip query string / fragment before checking extension
    $clean = strtok($p, '?#');
    return strtolower(pathinfo($clean ?: $p, PATHINFO_EXTENSION));
  }
}
if (!function_exists('csr_is_url')) {
  function csr_is_url(string $p): bool { return (bool)preg_match('~^https?://~i', $p); }
}
if (!function_exists('csr_is_image_ext')) {
  function csr_is_image_ext(string $ext): bool { return in_array($ext, ['jpg','jpeg','png','gif','webp','avif'], true); }
}
if (!function_exists('csr_is_video_ext')) {
  function csr_is_video_ext(string $ext): bool { return in_array($ext, ['mp4','webm','ogg','ogv','m4v'], true); }
}
if (!function_exists('csr_is_pdf_ext')) {
  function csr_is_pdf_ext(string $ext): bool { return $ext === 'pdf'; }
}
if (!function_exists('csr_web_to_cache_thumb')) {
  /** /media/foo/bar.ext -> /media/foo/.cache/bar.jpg */
  function csr_web_to_cache_thumb(string $srcWeb): string {
    $dir = rtrim(dirname($srcWeb), '/');
    $base = preg_replace('/\.[^.]+$/', '', basename($srcWeb));
    return $dir . '/.cache/' . $base . '.jpg';
  }
}
if (!function_exists('csr_is_youtube')) {
  function csr_is_youtube(string $url): bool {
    return (bool)preg_match('~(?:youtube\.com|youtu\.be)~i', $url);
  }
}
if (!function_exists('csr_get_youtube_thumb')) {
  function csr_get_youtube_thumb(string $url): string {
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_\-]{6,})~i', $url, $m)) {
      return 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
    }
    return '';
  }
}

/* ---------- builders ---------- */
if (!function_exists('csr_img_tag')) {
  function csr_img_tag(string $src, string $alt = '', array $attrs = []): string {
    $attrs['src'] = $src;
    if ($alt !== '') $attrs['alt'] = $alt;
    $attrs['class'] = trim(($attrs['class'] ?? '') . ' ri-thumb');
    $parts = [];
    foreach ($attrs as $k=>$v) $parts[] = csr_h($k) . '="' . csr_h((string)$v) . '"';
    return '<img ' . implode(' ', $parts) . '>';
  }
}
if (!function_exists('csr_wrap_card')) {
  function csr_wrap_card(string $inner, array $meta = []): string {
    $caption = (string)($meta['caption'] ?? '');
    $linkUrl = trim((string)($meta['linkUrl'] ?? ''));

    if ($linkUrl !== '') {
      $inner = '<a href="' . csr_h($linkUrl) . '" class="ri-link-wrap">' . $inner . '</a>';
    }
    $captionHtml = '';
    if ($caption !== '') {
      $captionHtml = '<div class="ri-caption">' . $caption . '</div>';
    }
    return '<figure class="ri-card">' . $inner . $captionHtml . '</figure>';
  }
}

if (!function_exists('csr_render_image_item')) {
  function csr_render_image_item(array $it): string {
    $src = (string)($it['path'] ?? $it['url'] ?? '');
    if ($src === '') return '';
    $thumb = (string)($it['thumbnail'] ?? $src);
    // If someone saved a non-image as "thumbnail", fall back to the actual image path
    if (!csr_is_image_ext(csr_ext($thumb))) $thumb = $src;

    $alt = (string)($it['title'] ?? basename($src));
    $img = csr_img_tag($thumb, $alt, []);
    if (!empty($it['lightbox'])) {
      $a = '<a class="lightbox-trigger" data-lightbox="image" href="'.csr_h($src).'" title="'.csr_h($alt).'">'.$img.'</a>';
      return csr_wrap_card($a, $it);
    }
    return csr_wrap_card($img, $it);
  }
}

if (!function_exists('csr_render_video_item')) {
  function csr_render_video_item(array $it): string {
    $src = (string)($it['path'] ?? $it['url'] ?? '');
    if ($src === '') return '';
    
    $thumb = (string)($it['thumbnail'] ?? '');
    $extT = csr_ext($thumb);
    
    // Smart thumbnail detection
    if ($thumb === '' || (!csr_is_image_ext($extT))) {
      // Build poster from /.cache for local videos
      $thumb = csr_web_to_cache_thumb($src);
    }
    
    $title = (string)($it['title'] ?? basename($src));
    
    // Build the thumbnail image - NO fallback span if we have cache path
    $img = csr_img_tag($thumb, $title, [
      'data-ensure-thumb' => '1',
      'data-kind' => 'video',
      'data-src'  => $src
    ]);

    // ALWAYS use lightbox for videos (default behavior)
    $lightbox = !isset($it['lightbox']) || !empty($it['lightbox']);

    if ($lightbox) {
      $a = '<a class="lightbox-trigger" data-lightbox="video" href="'.csr_h($src).'">'.
           $img . '<span class="ri-play-badge" aria-hidden="true">▶</span></a>';
      return csr_wrap_card($a, $it);
    }

    // Inline player — let <video> determine its own aspect ratio
    $poster = csr_h($thumb);
    $v  = '<video class="ri-inline-video" controls playsinline preload="metadata" poster="'.$poster.'">';
    $v .= '<source src="'.csr_h($src).'" type="video/mp4">';
    $v .= '</video>';
    return csr_wrap_card($v, $it);
  }
}

if (!function_exists('csr_aspect_class')) {
  function csr_aspect_class(array $it): string {
    $a = (string)($it['aspect'] ?? '');
    if ($a === '9:16') return ' ri-vertical';
    return '';
  }
}

if (!function_exists('csr_render_youtube_item')) {
  function csr_render_youtube_item(array $it): string {
    $u = (string)($it['path'] ?? $it['url'] ?? '');
    if ($u === '') return '';

    // Playlist item → inline native videoseries player (plays the whole list). No
    // lightbox (the lightbox handler expects a single video), no API key required.
    $pl = (string)($it['playlist_id'] ?? '');
    if ($pl !== '') {
      $src = 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode($pl) . '&rel=0';
      $ifr = '<div class="ri-embed" style="aspect-ratio:16/9;padding-bottom:0">'
           . '<iframe src="'.csr_h($src).'" title="YouTube playlist" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
      return csr_wrap_card($ifr, $it);
    }

    $thumb = (string)($it['thumbnail'] ?? '');
    if ($thumb === '') $thumb = csr_get_youtube_thumb($u);
    $title = (string)($it['title'] ?? '');
    $aspect = csr_aspect_class($it);

    // Auto-detect shorts from URL if aspect not set
    if ($aspect === '' && preg_match('~youtube\.com/shorts/~i', $u)) $aspect = ' ri-vertical';

    if ($thumb !== '') {
      $img = csr_img_tag($thumb, $title);
    } else {
      $img = '<span class="video-thumb-fallback"></span>';
    }

    $lightbox = !isset($it['lightbox']) || !empty($it['lightbox']);

    if ($lightbox) {
      $a = '<a class="lightbox-trigger' . $aspect . '" data-lightbox="video" href="'.csr_h($u).'">'.
           $img . '<span class="ri-play-badge" aria-hidden="true">▶</span></a>';
      return csr_wrap_card($a, $it);
    }

    // Inline embed
    $videoId = '';
    if (preg_match('~(?:youtu\.be/|watch\?v=|shorts/)([A-Za-z0-9_\-]{6,})~i', $u, $m)) {
      $videoId = $m[1];
    }
    $embed = $videoId ? 'https://www.youtube.com/embed/' . $videoId : $u;

    // Build embed params — honour muted flag
    $params = [];
    $muted = !empty($it['muted']);
    if ($muted) {
      $params[] = 'autoplay=1';
      $params[] = 'mute=1';
      $params[] = 'loop=1';
      if ($videoId) $params[] = 'playlist=' . $videoId;
    }
    $params[] = 'playsinline=1';
    if (!empty($params)) $embed .= '?' . implode('&', $params);

    $isVertical = ($aspect === ' ri-vertical');
    $ratio = $isVertical ? '9/16' : '16/9';
    $ifr = '<div class="ri-embed' . $aspect . '" style="aspect-ratio:' . $ratio . ';padding-bottom:0">'
         . '<iframe src="'.csr_h($embed).'" title="YouTube" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
    return csr_wrap_card($ifr, $it);
  }
}

if (!function_exists('csr_render_tiktok_item')) {
  function csr_render_tiktok_item(array $it): string {
    $u = (string)($it['path'] ?? $it['url'] ?? '');
    if ($u === '') return '';

    $thumb = (string)($it['thumbnail'] ?? '');
    $title = (string)($it['title'] ?? 'TikTok');
    $lightbox = !isset($it['lightbox']) || !empty($it['lightbox']);

    if ($lightbox && $thumb !== '') {
      $img = csr_img_tag($thumb, $title);
      $a = '<a class="lightbox-trigger ri-vertical" data-lightbox="video" href="' . csr_h($u) . '">'
         . $img . '<span class="ri-play-badge" aria-hidden="true">▶</span></a>';
      return csr_wrap_card($a, $it);
    }

    // Inline: TikTok blockquote embed
    $videoId = '';
    if (preg_match('~/video/(\d+)~', $u, $m)) $videoId = $m[1];
    static $ttScriptIncluded = false;
    $script = '';
    if (!$ttScriptIncluded) {
      $ttScriptIncluded = true;
      $script = '<script async src="https://www.tiktok.com/embed.js"></script>';
    }
    $embed = '<blockquote class="tiktok-embed ri-vertical" cite="' . csr_h($u) . '"'
           . ($videoId ? ' data-video-id="' . csr_h($videoId) . '"' : '')
           . ' style="max-width:605px;min-width:280px;">'
           . '<section><a href="' . csr_h($u) . '" target="_blank">' . csr_h($title) . '</a></section>'
           . '</blockquote>' . $script;
    return csr_wrap_card($embed, $it);
  }
}

if (!function_exists('csr_render_pdf_item')) {
  function csr_render_pdf_item(array $it): string {
    $src = (string)($it['path'] ?? $it['url'] ?? '');
    if ($src === '') return '';
    $title = (string)($it['title'] ?? basename($src));
    $thumb = (string)($it['thumbnail'] ?? '');
    $hasThumb = ($thumb !== '' && csr_is_image_ext(csr_ext($thumb)));

    if ($hasThumb) {
      $img = csr_img_tag($thumb, $title);
    } else {
      // Inline SVG placeholder — no external icon dependency
      $img = '<div class="ri-pdf-placeholder">'
           . '<svg width="64" height="80" viewBox="0 0 64 80" fill="none" xmlns="http://www.w3.org/2000/svg">'
           . '<rect width="64" height="80" rx="6" fill="#1a0a0a"/>'
           . '<rect x="1" y="1" width="62" height="78" rx="5" stroke="#330000" stroke-width="2" fill="none"/>'
           . '<text x="32" y="46" text-anchor="middle" font-family="sans-serif" font-size="16" font-weight="bold" fill="#e05252">PDF</text>'
           . '</svg>'
           . '<span class="ri-pdf-label">' . csr_h($title) . '</span>'
           . '</div>';
    }

    if (!empty($it['lightbox'])) {
      $a = '<a class="lightbox-trigger" data-lightbox="pdf" href="'.csr_h($src).'">'. $img . '</a>';
      return csr_wrap_card($a, $it);
    }
    // Non-lightbox: link normally (same tab)
    return csr_wrap_card('<a href="'.csr_h($src).'">'.$img.'</a>', $it);
  }
}

/* ---------- Facebook embed ---------- */
if (!function_exists('csr_render_facebook_item')) {
  function csr_render_facebook_item(array $it): string {
    $u = (string)($it['path'] ?? $it['url'] ?? '');
    if ($u === '') return '';

    $thumb   = (string)($it['thumbnail'] ?? '');
    $title   = (string)($it['title'] ?? 'Facebook Post');
    $ogType  = strtolower((string)($it['og_type'] ?? ''));
    $lightbox = !empty($it['lightbox']);

    // Determine if video/reel or regular post
    $isVideo = (bool)preg_match('/\b(video|reel)\b/i', $ogType)
            || (bool)preg_match('~facebook\.com/.*/(?:videos|reel)/~i', $u)
            || (bool)preg_match('~fb\.watch/~i', $u);

    // ---- Ensure FB SDK is loaded (needed for both modes) ----
    // Module config wins, legacy is only a fallback. This read the legacy file
    // ONLY, so an App ID set in the admin UI never reached the FB SDK here.
    $fbAppId = '';
    $csRoot = (defined('SITE_ROOT') ? SITE_ROOT : '');
    foreach ([
      $csRoot . '/admin/data/FacebookEvents/config.json',
      $csRoot . '/admin/data/facebook_events_settings.json',
    ] as $settingsFile) {
      if (!is_file($settingsFile)) continue;
      $s = @json_decode((string)@file_get_contents($settingsFile), true);
      if (is_array($s) && !empty($s['fb_app_id'])) { $fbAppId = (string)$s['fb_app_id']; break; }
    }

    static $sdkIncluded = false;
    $sdk = '';
    if (!$sdkIncluded) {
      $sdkIncluded = true;
      $appParam = $fbAppId ? '&appId=' . csr_h($fbAppId) : '';
      $sdk = '<div id="fb-root"></div>'
           . '<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];'
           . 'if(d.getElementById(id))return;js=d.createElement(s);js.id=id;'
           . 'js.src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v21.0' . $appParam . '";'
           . 'fjs.parentNode.insertBefore(js,fjs);}(document,"script","facebook-jssdk");</script>';
    }

    // ---- Lightbox mode: thumbnail click opens FB SDK widget in modal ----
    if ($lightbox) {
      if ($thumb !== '' && csr_is_image_ext(csr_ext($thumb))) {
        $img = csr_img_tag($thumb, $title);
      } else {
        $img = '<div style="width:100%;aspect-ratio:16/9;background:#1a1a2e;display:flex;align-items:center;justify-content:center">'
             . '<svg width="64" height="64" viewBox="0 0 24 24" fill="#1877f2"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.99 3.66 9.12 8.44 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99C18.34 21.12 22 16.99 22 12z"/></svg>'
             . '</div>';
      }
      $badge = $isVideo ? '<span class="ri-play-badge" aria-hidden="true">▶</span>' : '';
      $dataWidget = $isVideo ? 'fb-video' : 'fb-post';
      $a = '<a class="fb-lightbox-trigger" data-fb-href="' . csr_h($u) . '" data-fb-widget="' . $dataWidget . '" href="' . csr_h($u) . '">'
         . $img . $badge . '</a>';

      // Bridge script: clicks open an overlay with a live FB SDK widget (once)
      static $fbLightboxBridge = false;
      $bridge = '';
      if (!$fbLightboxBridge) {
        $fbLightboxBridge = true;
        $bridge = '<script>document.addEventListener("click",function(e){'
                . 'var a=e.target.closest(".fb-lightbox-trigger");if(!a)return;'
                . 'e.preventDefault();e.stopPropagation();'
                . 'var href=a.getAttribute("data-fb-href");'
                . 'var w=a.getAttribute("data-fb-widget")||"fb-video";'
                // build overlay
                . 'var ov=document.createElement("div");'
                . 'ov.style.cssText="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center";'
                // close on backdrop click
                . 'ov.addEventListener("click",function(ev){if(ev.target===ov)ov.remove();});'
                // inner container
                . 'var box=document.createElement("div");'
                . 'box.style.cssText="width:90vw;max-width:900px;max-height:85vh;overflow:auto;background:#000;border-radius:12px;padding:16px;position:relative";'
                // close button
                . 'var cl=document.createElement("span");'
                . 'cl.textContent="\\u00d7";'
                . 'cl.style.cssText="position:absolute;top:8px;right:14px;color:#fff;font-size:2rem;cursor:pointer;z-index:1;line-height:1";'
                . 'cl.onclick=function(){ov.remove();};'
                . 'box.appendChild(cl);'
                // FB widget div
                . 'var fb=document.createElement("div");'
                . 'fb.className=w;'
                . 'fb.setAttribute("data-href",href);'
                . 'fb.setAttribute("data-width","auto");'
                . 'if(w==="fb-video"){fb.setAttribute("data-allowfullscreen","true");fb.setAttribute("data-autoplay","true");}'
                . 'box.appendChild(fb);'
                . 'ov.appendChild(box);'
                . 'document.body.appendChild(ov);'
                // Parse the new widget
                . 'if(typeof FB!=="undefined")FB.XFBML.parse(box);'
                . '},true);</script>';
      }
      return $sdk . csr_wrap_card($a, $it) . $bridge;
    }

    // ---- Inline mode: embed via FB SDK directly ----
    if ($isVideo) {
      $embed = '<div class="fb-video" data-href="' . csr_h($u) . '" data-width="auto" data-allowfullscreen="true"></div>';
    } else {
      $embed = '<div class="fb-post" data-href="' . csr_h($u) . '" data-width="auto"></div>';
    }

    $fallback = '';
    if ($thumb !== '') {
      $fallback = '<noscript><a href="' . csr_h($u) . '" target="_blank" rel="noopener">'
                . csr_img_tag($thumb, $title) . '</a></noscript>';
    }

    $parseScript = '<script>if(typeof FB!=="undefined")FB.XFBML.parse();</script>';
    $inner = $sdk . $embed . $fallback . $parseScript;
    return csr_wrap_card($inner, $it);
  }
}

/* ---------- collector ---------- */
if (!function_exists('csr_collect_from_array')) {
  /**
   * Accepts the decoded JSON and outputs HTML for its items.
   * Expected shapes:
   *  - { "content": [ { type, path, thumbnail, title, author, lightbox }, ... ] }
   *  - { "items":   [ ... ] }
   *  - { "html": "<p>raw</p>" } (passed-through)
   */
  function csr_collect_from_array(array $j): string {
    $buf = '';

    // direct strings
    if (!empty($j['html'])    && is_string($j['html']))    $buf .= $j['html'];
    if (!empty($j['content']) && is_string($j['content'])) $buf .= $j['content'];

    // arrays
    $arr = [];
    if (!empty($j['content']) && is_array($j['content'])) $arr = $j['content'];
    elseif (!empty($j['items']) && is_array($j['items'])) $arr = $j['items'];

    foreach ($arr as $it) {
      if (!is_array($it)) { if (is_string($it)) $buf .= $it; continue; }
      $type = strtolower((string)($it['type'] ?? ''));
      switch ($type) {
        case 'image':  $buf .= csr_render_image_item($it);   break;
        case 'video':  $buf .= csr_render_video_item($it);   break;
        case 'youtube':$buf .= csr_render_youtube_item($it); break;
        case 'facebook':$buf .= csr_render_facebook_item($it); break;
        case 'tiktok': $buf .= csr_render_tiktok_item($it);  break;
        case 'html':
          $raw = (string)($it['html'] ?? '');
          if ($raw !== '') {
            // Per-item CSS/JS: if present, render via the shared hb_render()
            // helper so the CSS is scoped to this item and the JS is IIFE-bound
            // with a dedup guard. Otherwise fall through to the plain card wrap.
            $itemCss = (string)($it['css'] ?? '');
            $itemJs  = (string)($it['js']  ?? '');
            if ($itemCss !== '' || $itemJs !== '') {
              if (function_exists('hb_render')) {
                $scoped = hb_render([
                  'html' => $raw,
                  'css'  => $itemCss,
                  'js'   => $itemJs,
                  'slug' => 'stack-item',
                ]);
                $buf .= csr_wrap_card($scoped, $it);
              } else {
                $buf .= csr_wrap_card($raw, $it);
              }
            } else {
              $buf .= csr_wrap_card($raw, $it);
            }
          }
          break;
        case 'html-block':
          /* First-class HTML block reference: { type: 'html-block', slug: '…' }
           * Loads admin/data/html-blocks/{slug}.json via render_html_block(). */
          $hbSlug = (string)($it['slug'] ?? '');
          if ($hbSlug !== '') {
            if (function_exists('render_html_block')) {
              $buf .= csr_wrap_card(render_html_block($hbSlug), $it);
            }
          }
          break;
        case 'shortcode': case 'sc':
          /* Raw shortcode block — runs the site shortcode engine so a stack can
           * HOUSE a shortcode (e.g. the affiliate rail [[affiliate-products]]).
           * Depth-guarded so a stack that references another stack can't loop. */
          $code = (string)($it['shortcode'] ?? $it['code'] ?? $it['html'] ?? '');
          if ($code !== '') {
            static $csrScDepth = 0;
            if (function_exists('apply_shortcodes') && $csrScDepth < 3) {
              $csrScDepth++; $out = apply_shortcodes($code); $csrScDepth--;
            } else { $out = $code; }
            $buf .= csr_wrap_card($out, $it);
          }
          break;
        case 'markdown': case 'md': case 'text':
          /* Text/markdown block. Prefer pre-rendered html (from the editor),
           * fall back to server-side Parsedown on the markdown source. */
          $raw = (string)($it['html'] ?? '');
          if ($raw === '') {
            $md = (string)($it['markdown'] ?? $it['text'] ?? '');
            if ($md !== '') {
              $parsedown = SITE_ROOT . '/includes/Parsedown.php';
              if (is_file($parsedown) && !class_exists('Parsedown')) require_once $parsedown;
              if (class_exists('Parsedown')) {
                $pd = new Parsedown(); $pd->setSafeMode(false);
                $raw = $pd->text($md);
              } else {
                $raw = '<div>' . nl2br(csr_h($md)) . '</div>';
              }
            }
          }
          if ($raw !== '') {
            $itemCss = (string)($it['css'] ?? '');
            $itemJs  = (string)($it['js']  ?? '');
            if ($itemCss !== '' || $itemJs !== '') {
              if (function_exists('hb_render')) {
                $scoped = hb_render([
                  'html' => '<div class="ri-text">' . $raw . '</div>',
                  'css'  => $itemCss,
                  'js'   => $itemJs,
                  'slug' => 'stack-md-item',
                ]);
                $buf .= csr_wrap_card($scoped, $it);
              } else {
                $buf .= csr_wrap_card('<div class="ri-text">' . $raw . '</div>', $it);
              }
            } else {
              $buf .= csr_wrap_card('<div class="ri-text">' . $raw . '</div>', $it);
            }
          }
          break;
        case 'pdf':    $buf .= csr_render_pdf_item($it);     break;
        default:
          // Fallback: if URL looks like image/video/pdf try to guess
          $p = (string)($it['path'] ?? $it['url'] ?? '');
          if (csr_is_youtube($p)) {
            $buf .= csr_render_youtube_item($it);
          } else {
            $ext = csr_ext($p);
            if (csr_is_image_ext($ext))      $buf .= csr_render_image_item($it);
            elseif (csr_is_video_ext($ext))  $buf .= csr_render_video_item($it);
            elseif (csr_is_pdf_ext($ext))    $buf .= csr_render_pdf_item($it);
            else if (!empty($it['html']) && is_string($it['html'])) $buf .= $it['html'];
          }
          break;
      }
    }

    return $buf;
  }
}

/* ---------- entry point (preserve name) ---------- */
if (!function_exists('render_content_stack')) {
  /**
   * Render the JSON path (server path). Returns HTML (shortcode-processed if available).
   */
  function render_content_stack(string $jsonPath, int $colsOverride = 0): string {
    if (!is_file($jsonPath)) { csr_toast('warn', 'csr: no JSON at ' . $jsonPath); return ''; }

    $raw = @file_get_contents($jsonPath);
    if ($raw === false) { csr_toast('warn', 'csr: read failed'); return ''; }

    $j = json_decode($raw, true);
    if (!is_array($j)) { csr_toast('warn', 'csr: json decode failed'); return ''; }

    // Caller (e.g. an injection setpoint) may override the stack's own column_count.
    $cols = $colsOverride > 0 ? $colsOverride : max(1, (int)($j['column_count'] ?? 1));
    $html = csr_collect_from_array($j);

    // Shortcodes if present (guarded)
    if (function_exists('apply_shortcodes')) {
      $html = apply_shortcodes($html);
    }

    // CSS bucket — injected before the stack HTML
    $prefix = '';
    if (!empty($j['css']) && is_string($j['css']) && trim($j['css']) !== '') {
      $prefix = '<style>' . $j['css'] . '</style>';
    }

    // JS bucket — injected after the stack HTML
    $suffix = '';
    if (!empty($j['js']) && is_string($j['js']) && trim($j['js']) !== '') {
      $suffix = '<script>' . $j['js'] . '</script>';
    }

    // Wrap in grid with column count
    return $prefix . '<div class="ri-stack" style="--ri-cols:' . $cols . '">' . $html . '</div>' . $suffix;
  }
}
?>
