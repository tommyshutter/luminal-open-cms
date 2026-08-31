<?php
/**
 * Content Stack Renderer (Left/Right stacks & future Content Stack Manager)
 * @file    /includes/renderers/stack.renderer.php
 * @version 2025.09.09.r4
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

/* ---------- utils (unique names) ---------- */
if (!function_exists('sr_h')) {
  function sr_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sr_toast')) {
  function sr_toast(string $lvl, string $msg): void {
    $lvl  = preg_replace('/[^a-z]/i','',strtolower($lvl)) ?: 'info';
    $safe = sr_h($msg);
    echo '<script>(function(){try{(window.RIToaster&&RIToaster['.$lvl.']?RIToaster['.$lvl.']:console.log)("'.$safe.'");}catch(_){}})();</script>';
  }
}
if (!function_exists('sr_json_read')) {
  function sr_json_read(string $path): array {
    clearstatcache(true, $path);
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
}

/* ---------- media helpers ---------- */
if (!function_exists('sr_youtube_id')) {
  function sr_youtube_id(string $urlOrId): string {
    $u = trim($urlOrId);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $u)) return $u;
    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $u, $m)) return $m[1];
    return '';
  }
}
if (!function_exists('sr_guess_poster')) {
  function sr_guess_poster(string $publicPath): string {
    if ($publicPath === '' || $publicPath[0] !== '/') return '';
    $dir  = dirname($publicPath);
    $file = basename($publicPath);
    $candidates = [
      $dir.'/.cache/'.$file.'.poster.jpg',
      $dir.'/.cache/'.$file.'.jpg',
      $dir.'/.cache/'.pathinfo($file, PATHINFO_FILENAME).'.jpg',
    ];
    foreach ($candidates as $c) { if (is_file(SITE_ROOT.$c)) return $c; }
    return '';
  }
}

/* ---------- leaf renderers ---------- */
if (!function_exists('sr_render_stack_item')) {
  function sr_render_stack_item($item): string {
    if (is_string($item)) return $item;
    if (!is_array($item))  return '';

    $type = strtolower((string)($item['type'] ?? $item['kind'] ?? $item['widget'] ?? 'html'));

    switch ($type) {
      case 'html': case 'text':
        return (string)($item['html'] ?? $item['content'] ?? $item['value'] ?? '');

      case 'youtube': {
        $url  = (string)($item['url'] ?? $item['href'] ?? $item['id'] ?? '');
        // Playlist item → native videoseries player (plays the whole list, no API key).
        $pl = (string)($item['playlist_id'] ?? '');
        if ($pl !== '') {
          $t   = (string)($item['title'] ?? '');
          $src = 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode($pl) . '&rel=0';
          ob_start(); ?>
          <div class="stack-yt">
            <div class="video-responsive">
              <iframe src="<?php echo sr_h($src); ?>"
                      allow="encrypted-media" allowfullscreen loading="lazy"></iframe>
            </div>
            <?php if ($t!==''): ?><div class="stack-caption"><?php echo sr_h($t); ?></div><?php endif; ?>
          </div>
          <?php return (string)ob_get_clean();
        }
        $id   = sr_youtube_id($url);
        if ($id === '') return '';
        $title = (string)($item['title'] ?? '');
        ob_start(); ?>
          <div class="stack-yt">
            <div class="video-responsive">
              <iframe src="https://www.youtube.com/embed/<?php echo sr_h($id); ?>?rel=0&showinfo=0"
                      allow="autoplay; encrypted-media" allowfullscreen loading="lazy"></iframe>
            </div>
            <?php if ($title!==''): ?><div class="stack-caption"><?php echo sr_h($title); ?></div><?php endif; ?>
          </div>
        <?php return (string)ob_get_clean();
      }

      case 'image': {
        $src = (string)($item['src'] ?? $item['url'] ?? '');
        if ($src === '') return '';
        $cap = (string)($item['caption'] ?? $item['title'] ?? '');
        ob_start(); ?>
          <figure class="stack-img">
            <a class="ri-g-item" href="<?php echo sr_h($src); ?>" data-type="image">
              <img src="<?php echo sr_h($src); ?>" alt="">
            </a>
            <?php if ($cap!==''): ?><figcaption><?php echo sr_h($cap); ?></figcaption><?php endif; ?>
          </figure>
        <?php return (string)ob_get_clean();
      }

      case 'mp4': case 'video': {
        $src = (string)($item['src'] ?? $item['url'] ?? '');
        if ($src === '') return '';
        $poster = (string)($item['poster'] ?? '');
        if ($poster === '') $poster = sr_guess_poster($src);
        ob_start(); ?>
          <div class="stack-video">
            <video controls preload="metadata" <?php if ($poster!==''): ?>poster="<?php echo sr_h($poster); ?>"<?php endif; ?>>
              <source src="<?php echo sr_h($src); ?>" type="video/mp4">
            </video>
          </div>
        <?php return (string)ob_get_clean();
      }

      case 'facebook': {
        $url = (string)($item['url'] ?? $item['href'] ?? '');
        if ($url === '') return '';
        return '<div class="stack-fb"><a class="stack-fb-link" href="'.sr_h($url).'" target="_blank" rel="noopener">View on Facebook</a></div>';
      }

      default:
        if (!empty($item['html']))    return (string)$item['html'];
        if (!empty($item['content'])) return (string)$item['content'];
        if (!empty($item['src']))     return '<a href="'.sr_h((string)$item['src']).'">'.sr_h((string)$item['src']).'</a>';
        return '';
    }
  }
}

/* ---------- deep-scan fallback ---------- */
if (!function_exists('sr_collect_html_deep')) {
  function sr_collect_html_deep($node, array &$out): void {
    if (is_string($node)) {
      $s = trim($node); if ($s !== '') $out[] = $s;
      return;
    }
    if (!is_array($node)) return;

    // direct stringy keys
    foreach (['html','content','text','description','value','content_html'] as $k) {
      if (isset($node[$k]) && is_string($node[$k]) && trim($node[$k])!=='') $out[] = (string)$node[$k];
    }

    // look into known arrays
    foreach ([
      'items','blocks','sections','rows','cards','entries','list','stack',
      'left_items','right_items','widgets','modules','panels','content_blocks','content_items'
    ] as $k) {
      if (!empty($node[$k]) && is_array($node[$k])) {
        foreach ($node[$k] as $child) {
          $html = sr_render_stack_item($child);
          if ($html !== '') $out[] = $html; else sr_collect_html_deep($child, $out);
        }
      }
    }

    // components object
    if (!empty($node['components']) && is_array($node['components'])) {
      foreach ($node['components'] as $comp) {
        if (is_array($comp) && (!isset($comp['enabled']) || $comp['enabled'])) sr_collect_html_deep($comp, $out);
      }
    }

    // nested data.items
    if (!empty($node['data']) && is_array($node['data']) && !empty($node['data']['items']) && is_array($node['data']['items'])) {
      foreach ($node['data']['items'] as $child) {
        $html = sr_render_stack_item($child);
        if ($html !== '') $out[] = $html; else sr_collect_html_deep($child, $out);
      }
    }

    // special single strings
    foreach (['left_content','right_content'] as $k) {
      if (!empty($node[$k]) && is_string($node[$k])) $out[] = (string)$node[$k];
    }
  }
}

/* ---------- main render ---------- */
if (!function_exists('sr_render_stack_html')) {
  function sr_render_stack_html(array $data, array $opts = []): string {
    $wrapClass = $opts['wrap_class'] ?? 'pm-panel';

    // Telemetry: top-level keys
    $keys = implode(',', array_keys($data));
    sr_toast('debug', 'stack: top-level keys ['.$keys.']');

    $buf = '';

    // 1) Straightforward shapes
    if (!empty($data['html']) && is_string($data['html'])) {
      $buf .= (string)$data['html'];
    } elseif (!empty($data['content']) && is_string($data['content'])) {
      $buf .= (string)$data['content'];
    } elseif (!empty($data['blocks']) && is_array($data['blocks'])) {
      foreach ($data['blocks'] as $b) {
        if (is_string($b)) $buf .= $b;
        elseif (is_array($b) && !empty($b['html']))    $buf .= (string)$b['html'];
        elseif (is_array($b) && !empty($b['content'])) $buf .= (string)$b['content'];
      }
    } elseif (!empty($data['items']) && is_array($data['items'])) {
      foreach ($data['items'] as $it) $buf .= sr_render_stack_item($it);
    }
    // 2) Common stack aliases
    elseif (!empty($data['sections']) && is_array($data['sections'])) {
      foreach ($data['sections'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['rows']) && is_array($data['rows'])) {
      foreach ($data['rows'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['cards']) && is_array($data['cards'])) {
      foreach ($data['cards'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['entries']) && is_array($data['entries'])) {
      foreach ($data['entries'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['list']) && is_array($data['list'])) {
      foreach ($data['list'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['stack']) && is_array($data['stack'])) {
      foreach ($data['stack'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['left_items']) && is_array($data['left_items'])) {
      foreach ($data['left_items'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['right_items']) && is_array($data['right_items'])) {
      foreach ($data['right_items'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['data']['items']) && is_array($data['data']['items'])) {
      foreach ($data['data']['items'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['content_blocks']) && is_array($data['content_blocks'])) {
      foreach ($data['content_blocks'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['content_items']) && is_array($data['content_items'])) {
      foreach ($data['content_items'] as $it) $buf .= (sr_render_stack_item($it) ?: '');
    } elseif (!empty($data['left_content']) && is_string($data['left_content'])) {
      $buf .= (string)$data['left_content'];
    } elseif (!empty($data['right_content']) && is_string($data['right_content'])) {
      $buf .= (string)$data['right_content'];
    }
    // 3) Components bucket
    elseif (!empty($data['components']) && is_array($data['components'])) {
      foreach ($data['components'] as $comp) {
        if (is_array($comp) && ( !isset($comp['enabled']) || $comp['enabled'] )) {
          if (!empty($comp['content']) && is_string($comp['content'])) $buf .= $comp['content'];
          elseif (!empty($comp['html']) && is_string($comp['html']))   $buf .= $comp['html'];
          elseif (!empty($comp['items']) && is_array($comp['items'])) {
            foreach ($comp['items'] as $it) $buf .= sr_render_stack_item($it);
          }
        }
      }
    }

    // 4) Deep-scan fallback
    if ($buf === '') {
      $collected = [];
      sr_collect_html_deep($data, $collected);
      if ($collected) {
        $buf = implode("", $collected);
        sr_toast('debug', 'stack: deep-scan collected '.count($collected).' fragments');
      }
    }

    // Expand shortcodes if available
    if ($buf !== '' && function_exists('apply_shortcodes')) {
      try { $buf = apply_shortcodes($buf); }
      catch (Throwable $e) { sr_toast('warn', 'stack: apply_shortcodes() failed: '.$e->getMessage()); }
    }

    if ($buf === '') {
      sr_toast('warn', 'stack: nothing renderable in JSON (checked many shapes + deep-scan)');
      return '';
    }

    return '<div class="'.sr_h($wrapClass).'">'.$buf.'</div>';
  }
}

/* ---------- entry ---------- */
if (!function_exists('sr_render_stack_from_file')) {
  function sr_render_stack_from_file(string $jsonPath, string $wrapClass = 'pm-panel'): string {
    $exists = is_file($jsonPath);
    sr_toast('info', "stack: json={$jsonPath} (exists=".($exists?'yes':'no').")");
    if (!$exists) return '';
    $data = sr_json_read($jsonPath);
    if (!$data) { sr_toast('warn', 'stack: json parsed empty'); return ''; }
    return sr_render_stack_html($data, ['wrap_class'=>$wrapClass]);
  }
}
?>