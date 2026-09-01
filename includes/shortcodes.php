<?php
/**
 * @appname   Luminal CMS
 * @file      /includes/shortcodes.php
 * @version   2025.09.23.12:55-EST
 * @author    ChatGPT
 * @purpose   Shortcodes engine (stable, surgical):
 *            - Panels with whitelist + singleton guard (toaster + safe suppress)
 *            - Dispatch image/video/pdf galleries to renderer files
 *            - Backward-compatible shortcode syntax
 */

declare(strict_types=1);

/* ── env guard ─────────────────────────────────────────────────────────── */
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/* ── module renderer autoloader ────────────────────────────────────────────
 * Pull in every installed module's renderers (WordPress-plugin pattern). An
 * extension owns its own renderers; core dispatches by function_exists() only.
 * See includes/module-renderers.php. */
require_once __DIR__ . '/module-renderers.php';

/* ── helpers ───────────────────────────────────────────────────────────── */

if (!function_exists('sc_h')) {
  function sc_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sc_is_abs')) {
  function sc_is_abs(string $v): bool { return (bool)preg_match('~^(?:https?://|/)~i', $v); }
}
if (!function_exists('sc_norm_path')) {
  function sc_norm_path(string $v): string { $v = trim($v); return $v === '' ? '' : (sc_is_abs($v) ? $v : '/media/' . ltrim($v, '/')); }
}
if (!function_exists('sc_read_json')) {
  function sc_read_json(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : [];
  }
}

/**
 * Send a message to the admin toaster, if present on the page (also works inside Page Manager preview).
 * Accepts levels: debug|info|warn|error|success
 */
if (!function_exists('sc_toast')) {
  function sc_toast(string $level, string $msg): void {
    $lvl = in_array($level, ['debug','info','warn','error','success'], true) ? $level : 'info';
    $payload = json_encode(['level'=>$lvl,'msg'=>$msg], JSON_UNESCAPED_SLASHES);
    echo '<script>(function(w){try{var t=w.__ADMIN_TOASTER_ENABLED__?w.adminToaster:null;if(t&&t.push){t.push(' . $payload . ');} }catch(e){} })(window);</script>';
  }
}

/**
 * Parse shortcode inner attributes:
 *   key="value"  — quoted value
 *   key=value    — unquoted value (no spaces)
 *   lone token   → name
 */
if (!function_exists('sc_parse_attrs')) {
  function sc_parse_attrs(string $inner): array {
    $attrs = [];
    $inner = trim($inner);
    if ($inner === '') return $attrs;
    // Match key="value" (quoted) OR key=value (unquoted, no spaces/brackets)
    if (preg_match_all('/([a-zA-Z0-9_\-]+)\s*=\s*(?:"([^"]*)"|([^\s\]"]+))/', $inner, $m, PREG_SET_ORDER)) {
      foreach ($m as $set) {
        $attrs[strtolower($set[1])] = $set[2] !== '' ? (string)$set[2] : (string)$set[3];
      }
    } elseif (preg_match('/^\s*([^\s\]]+)\s*$/', $inner, $mm)) {
      $attrs['name'] = (string)$mm[1]; // lone token → name
    }
    return $attrs;
  }
}

/* ── panels: whitelist ─────────────────────────────────────────────────── */

/**
 * panels_whitelist.json (preferred): array OR { "allowed": [ ... ] }
 * panel-whitelist.json (legacy):     same shapes
 *
 * If file exists and contains a non-empty set, we ENFORCE it (allow-list).
 * If file absence or empty → allow all.
 */
if (!function_exists('sc_load_panel_whitelist')) {
  function sc_load_panel_whitelist(): array {
    $candidates = [
      SITE_ROOT . '/admin/data/panels_whitelist.json',
      SITE_ROOT . '/admin/data/panel-whitelist.json',
    ];
    foreach ($candidates as $p) {
      if (!is_file($p)) continue;
      $raw = @file_get_contents($p);
      $j = json_decode($raw ?? '', true);
      if (!is_array($j)) return [];
      if (array_is_list($j)) {
        return array_values(array_filter(array_map('strval', $j)));
      }
      if (isset($j['allowed']) && is_array($j['allowed'])) {
        return array_values(array_filter(array_map('strval', $j['allowed'])));
      }
      return [];
    }
    return [];
  }
}

/* ── panels: renderer with singleton guard ─────────────────────────────── */

if (!function_exists('sc_render_panel')) {
  function sc_render_panel(array $attrs): array {
    $name = (string)($attrs['name'] ?? '');
    if ($name === '') return [false, '<!-- panel name missing -->', 'name-missing'];

    // Allow query overlay: panel.php?foo=bar
    $filePart = $name; $qs = '';
    if (strpos($name, '?') !== false) { [$filePart, $qs] = explode('?', $name, 2); }
    $base = basename($filePart);

    if ($base === '' || !preg_match('/\.php$/i', $base)) {
      return [false, '<!-- panel must be .php: ' . sc_h($base) . ' -->', 'not-php'];
    }

    // Whitelist enforcement
    static $WL = null; if ($WL === null) $WL = sc_load_panel_whitelist();
    if (!empty($WL) && !in_array($base, $WL, true)) {
      return [false, '<!-- panel blocked by whitelist: ' . sc_h($base) . ' -->', 'not-whitelisted'];
    }

    // Singleton guard: prevent fatal redeclare if author places 2 copies
    // Add any other singletons that declare global helpers.
    static $SC_PANEL_SEEN = [];
    $singletons = [
      'panel-printful.php',
      'panel-events.php',
      'panel-store-csv.php',
      'panel-store-utils.php',
    ];
    if (in_array($base, $singletons, true)) {
      if (isset($SC_PANEL_SEEN[$base])) {
        sc_toast('error', "1201 - panel error: {$base} valid only 1 instance per page");
        return [false, '<!-- panel ' . sc_h($base) . ' suppressed (singleton) -->', 'singleton-suppressed'];
      }
      $SC_PANEL_SEEN[$base] = true;
    }

    // Resolve absolute path
    $abs = SITE_ROOT . '/panels/' . $base;
    if (!is_file($abs)) {
      sc_toast('warn', "panel not found: {$base}");
      return [false, '<!-- panel not found: ' . sc_h($base) . ' -->', 'missing'];
    }

    // Apply ?query overlay to $_GET (isolate changes)
    $origGet = $_GET;
    if ($qs !== '') { parse_str($qs, $qArr); if (is_array($qArr)) $_GET = array_merge($_GET, $qArr); }

    // Include the panel and capture output
    ob_start();
    try {
      include $abs;
      $html = (string)ob_get_clean();
      $_GET = $origGet;
      return [true, $html, 'ok'];
    } catch (Throwable $e) {
      ob_end_clean();
      $_GET = $origGet;
      sc_toast('error', 'panel exception: ' . $e->getMessage());
      return [false, '<!-- panel error: ' . sc_h($e->getMessage()) . ' -->', 'exception'];
    }
  }
}

/* ── named content stack resolver ──────────────────────────────────────── */

if (!function_exists('sc_render_named_stack')) {
  function sc_render_named_stack(string $slug, int $colsOverride = 0): string {
    if ($slug === '') return '<!-- stack: slug missing -->';
    $regFile = SITE_ROOT . '/admin/data/content-stacks-registry.json';
    $reg = is_file($regFile) ? (@json_decode((string)@file_get_contents($regFile), true) ?: []) : [];
    // Match by slug field first, then by label-derived slug, then by id
    $num = null; $resolvedSlug = null;
    foreach ($reg as $entry) {
      $entrySlug = $entry['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($entry['label'] ?? ''));
      $entryNum  = preg_replace('/[^0-9]/', '', $entry['id'] ?? '');
      if ($entrySlug === $slug || $entry['id'] === $slug || $entryNum === $slug) {
        $num = $entryNum; $resolvedSlug = $entrySlug; break;
      }
    }
    if ($num === null && $resolvedSlug === null) return '<!-- stack not found: ' . htmlspecialchars($slug, ENT_QUOTES) . ' -->';
    /* Prefer slug-named file (content-stack-{slug}.json). Fall back to
     * numeric (content-stack-{N}.json) for legacy stacks not yet migrated. */
    $dataFile = SITE_ROOT . '/admin/data/content-stack-' . $resolvedSlug . '.json';
    if (!is_file($dataFile) && $num !== '') {
      $dataFile = SITE_ROOT . '/admin/data/content-stack-' . $num . '.json';
    }
    if (!function_exists('render_content_stack')) return '<!-- content_stack renderer missing -->';
    return render_content_stack($dataFile, $colsOverride);
  }
}

/* ── include gallery renderers (no refactor) ───────────────────────────── */


/* Pass-through gallery shortcode attrs → renderer $opts (e.g. sort=1,
   sort_default=newest, max=12). Saved gallery JSON still drives defaults; an
   attr present here overrides it for that one placement. */
if (!function_exists('sc_gallery_opts')) {
  function sc_gallery_opts(array $attrs): array {
    $o = [];
    foreach (['sort','sort_tools','sort_default','max','layout'] as $k) {
      if (isset($attrs[$k])) $o[$k] = $attrs[$k];
    }
    return $o;
  }
}

/* ── Enclosing section shortcode ──────────────────────────────────────────
 * [[section preset="apple" opacity="40" radius="16" shadow="1" ...]] … [[/section]]
 * → a Luminal content-section container (.lum-section .lum-text) with the chosen surface
 * preset applied inline (reuses the Text Style engine). Section-level styling from the UI
 * with no hand-CSS. Inner content is left for the main scan (nested shortcodes still work). */
if (!function_exists('sc_render_sections')) {
  function sc_render_sections(string $html): string {
    $r = preg_replace_callback('/\[\[section\b([^\]]*)\]\](.*?)\[\[\/section\]\]/is', function ($m) {
      $attrs = sc_parse_attrs(trim($m[1]));
      $inner = $m[2];
      $ts = ['preset' => strtolower((string)($attrs['preset'] ?? $attrs['name'] ?? 'base'))];
      if (isset($attrs['opacity'])) { $o = (float)$attrs['opacity']; $ts['opacity'] = $o > 1 ? $o / 100 : $o; }
      foreach (['radius','blur'] as $k) if (isset($attrs[$k])) $ts[$k] = (int)$attrs[$k];
      foreach (['shadow','glow'] as $k) if (isset($attrs[$k])) $ts[$k] = !in_array(strtolower((string)$attrs[$k]), ['0','false','no',''], true);
      if (isset($attrs['surface'])) $ts['surface'] = $attrs['surface'];
      // Custom shadow shaping (optional): color / opacity / height / angle.
      foreach (['shadow_color','shadow_opacity','shadow_height','shadow_angle'] as $k) if (isset($attrs[$k])) $ts[$k] = $attrs[$k];
      $style  = '';
      $tsFile = __DIR__ . '/text_style.php';
      if (is_file($tsFile)) { require_once $tsFile; if (function_exists('text_style_inline')) $style = text_style_inline($ts); }
      // Border override (matches the section widget's Border toggle + color picker).
      if (isset($attrs['border']) && !in_array(strtolower((string)$attrs['border']), ['0','false','no',''], true)) {
        $bc = (isset($attrs['border_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$attrs['border_color'])) ? $attrs['border_color'] : '#8899aa';
        $style .= ($style !== '' ? '; ' : '') . 'border: 1px solid ' . $bc;
      }
      // width override — max-width + centered (e.g. width="800px" | "80%" | "800")
      if (!empty($attrs['width'])) {
        $w = trim((string)$attrs['width']);
        if (preg_match('/^\d+$/', $w)) $w .= 'px';
        if (preg_match('/^[0-9.]+(px|rem|em|%|vw|ch)$/', $w)) {
          $style .= ($style !== '' ? '; ' : '') . 'max-width: ' . $w . '; margin-inline: auto';
        }
      }
      $cls = 'lum-section lum-text';
      if (!empty($attrs['class'])) $cls .= ' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$attrs['class']);
      $styleAttr = $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '';
      return "\n<div class=\"" . $cls . "\"" . $styleAttr . ">\n" . $inner . "\n</div>\n";
    }, $html);
    return $r ?? $html;
  }
}

/* ── main: apply_shortcodes ───────────────────────────────────────────── */

if (!function_exists('apply_shortcodes')) {
  function apply_shortcodes(string $html): string {
    if ($html === '' || strpos($html, '[[') === false) return $html;

    // Protect <pre>...</pre> blocks from shortcode processing (docs pages, code examples)
    $preBlocks = [];
    $html = preg_replace_callback('/<pre\b[^>]*>.*?<\/pre>/si', function($m) use (&$preBlocks) {
      $key = '<!--SC_PRE_' . count($preBlocks) . '-->';
      $preBlocks[$key] = $m[0];
      return $key;
    }, $html);

    // Enclosing [[section ...]] … [[/section]] → styled content-section containers (before the main scan).
    if (stripos($html, '[[section') !== false) {
      $html = sc_render_sections($html);
    }

    $inLen  = strlen($html);
    $out    = $html;
    $offset = 0;

    while (true) {
      $start = strpos($out, '[[', $offset);
      if ($start === false) break;
      $end   = strpos($out, ']]', $start + 2);
      if ($end === false) break;

      $full  = substr($out, $start, $end - $start + 2);
      $inner = trim(substr($full, 2, -2));

      // Parse name + attrs
      $name = ''; $attrs = [];
      if (strpos($inner, ':') !== false) {
        [$n, $rest] = explode(':', $inner, 2);
        $name  = strtolower(str_replace('_','-', trim($n)));
        $attrs = sc_parse_attrs(trim($rest ?? ''));
        if (!isset($attrs['name']) && $rest !== '') $attrs['name'] = trim($rest);
      } else {
        if (preg_match('/^([a-zA-Z0-9_\-]+)\s*(.*)$/s', $inner, $m)) {
          $name  = strtolower(str_replace('_','-', trim($m[1])));
          $attrs = sc_parse_attrs(trim($m[2] ?? ''));
        }
      }

      $replacement = $full; // default passthrough

      switch ($name) {
        /* ── panels ───────────────────────────────────────────────────── */
        case 'panel':
          [$ok, $rep, $note] = sc_render_panel($attrs);
          $replacement = $rep;
          break;

        /* ── named content stacks ────────────────────────────────────── */
        case 'stack': case 'content-stack':
          $stackSlug = (string)($attrs[0] ?? $attrs['name'] ?? $attrs['id'] ?? '');
          // Colon form ([[stack:ucs cols=3]]) puts the whole "ucs cols=3" into name — the
          // slug is only the first token; trailing attrs (cols=N) are read from $attrs.
          $stackSlug = trim(preg_split('/\s+/', trim($stackSlug), 2)[0] ?? '');
          $stackCols = (int)($attrs['cols'] ?? $attrs['columns'] ?? 0);
          $replacement = sc_render_named_stack($stackSlug, $stackCols);
          break;

        /* ── live docs index ─────────────────────────────────────────
           Enumerates admin/data/docs/ at render time. Derived, never authored,
           so the index cannot fall out of step with the docs themselves. */
        case 'docs-index': case 'docsindex':
          $replacement = sc_render_docs_index();
          break;

        /* ── named HTML blocks (first-class reusable HTML+CSS+JS atoms) ── */
        case 'html-block': case 'htmlblock': case 'block':
          $hbSlug = (string)($attrs[0] ?? $attrs['name'] ?? $attrs['slug'] ?? $attrs['id'] ?? '');
          if (function_exists('render_html_block')) {
            $replacement = render_html_block($hbSlug);
          } else {
            $replacement = '<!-- html-block renderer missing -->';
          }
          break;

        /* ── galleries ───────────────────────────────────────────────── */
        case 'image-gallery': case 'imagegallery':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_image_gallery')) {
            $replacement = render_image_gallery($slug, sc_gallery_opts($attrs));
          } else {
            $replacement = '<!-- image-gallery renderer missing -->';
          }
          break;

        case 'video-gallery': case 'videogallery':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_video_gallery')) {
            $replacement = render_video_gallery($slug, sc_gallery_opts($attrs));
          } else {
            $replacement = '<!-- video-gallery renderer missing -->';
          }
          break;

        case 'pdf-gallery': case 'pdfgallery':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_pdf_gallery')) {
            $replacement = render_pdf_gallery($slug, sc_gallery_opts($attrs));
          } else {
            $replacement = '<!-- pdf-gallery renderer missing -->';
          }
          break;

        case 'gallery':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_combined_gallery')) {
            $replacement = render_combined_gallery($slug, sc_gallery_opts($attrs));
          } else {
            $replacement = '<!-- combined-gallery renderer missing -->';
          }
          break;

        /* ── events (from Events Manager) ─────────────────────────────── */
        case 'event':
          $eventId = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_event_shortcode')) {
            $replacement = render_event_shortcode($eventId);
          } else {
            $replacement = '<!-- event renderer missing -->';
          }
          break;

        /* ── upcoming shows / EPK events widget ────────────────────────── */
        case 'upcoming-shows': case 'shows-widget': case 'epk-events':
          if (function_exists('render_epk_events_widget')) {
            $replacement = render_epk_events_widget($attrs);
          } else {
            $replacement = '<!-- epk-events renderer not available -->';
          }
          break;

        /* ── EventsManagerPro card grid (FBE-style cards from EMP data) ── */
        case 'emp-events': case 'events-cards': case 'upcoming-events':
          if (function_exists('render_emp_events')) {
            $replacement = render_emp_events($attrs);
          } else {
            $replacement = '<!-- emp-events renderer not available -->';
          }
          break;

        /* ── AI Page Maker pages ──────────────────────────────────────── */
        case 'aip': case 'aip-page': case 'ai-page':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_aip_page')) {
            $replacement = render_aip_page($slug);
          } else {
            $replacement = '<!-- aip renderer missing -->';
          }
          break;

        /* ── Music-agency Artist Directory (VERTICALS/music-agency/pro) ──
           Renders the artist listing as a CMS page component by buffering the
           already-.dir-scoped /pro/directory-content.php. is_file-guarded so
           non-music-agency sites (no /pro dir) fall through to a harmless comment. */
        case 'artist-directory': case 'artists-directory': case 'artist-grid':
          $dcFile = SITE_ROOT . '/pro/directory-content.php';
          if (is_file($dcFile)) {
            ob_start();
            try { include $dcFile; } catch (\Throwable $e) { /* never break the page */ }
            $replacement = ob_get_clean();
          } else {
            $replacement = '<!-- artist-directory: /pro/directory-content.php not present on this site -->';
          }
          break;

        /* ── SouthFlorida platform extension (admin/modules/SouthFlorida) ──
           Live news pulse (lifestyle + business RSS) + featured articles block.
           Renderers autoload via module-renderers.php on SouthFlorida sites; the
           miami-* aliases keep legacy [[panel:miami-*]] migrations working. */
        case 'sf-pulse': case 'miami-pulse':
          $replacement = function_exists('render_sf_pulse') ? render_sf_pulse($attrs) : '<!-- sf-pulse: SouthFlorida module not installed -->';
          break;
        case 'sf-biz-pulse': case 'miami-biz-pulse':
          $replacement = function_exists('render_sf_biz_pulse') ? render_sf_biz_pulse($attrs) : '<!-- sf-biz-pulse: SouthFlorida module not installed -->';
          break;
        case 'sf-articles': case 'miami-articles':
          $replacement = function_exists('render_sf_articles') ? render_sf_articles($attrs) : '<!-- sf-articles: SouthFlorida module not installed -->';
          break;
        case 'article-showcase': case 'articles-magazine':
          $replacement = function_exists('render_article_showcase') ? render_article_showcase($attrs) : '<!-- article-showcase: ArticlesManager renderer not loaded -->';
          break;

        /* PodRadio — a host's live on-air station block (multi-tenant, by slug) */
        case 'podradio-station': case 'podradio':
          $replacement = function_exists('render_podradio_station') ? render_podradio_station($attrs) : '<!-- podradio-station: PodRadio module not installed -->';
          break;

        /* PodRadio — the network "Netflix" card grid w/ live feed validation */
        case 'podradio-network':
          $replacement = function_exists('render_podradio_network') ? render_podradio_network($attrs) : '<!-- podradio-network: PodRadio module not installed -->';
          break;

        /* PodRadio — the SomaFM-style lobby: station grid + popup player */
        case 'podradio-lobby': case 'podradio-dial':
          $replacement = function_exists('render_podradio_lobby') ? render_podradio_lobby($attrs) : '<!-- podradio-lobby: PodRadio module not installed -->';
          break;

        /* PodRadio — a station's public profile page ([[..]] + ?s=slug) */
        case 'podradio-station-profile': case 'podradio-profile':
          $replacement = function_exists('render_podradio_station_profile') ? render_podradio_station_profile($attrs) : '<!-- podradio-station-profile: PodRadio module not installed -->';
          break;

        /* ── podcast feed / episodes ──────────────────────────────────── */
        case 'podcast-feed': case 'podcasts': case 'podcast-cards': case 'podcast-episodes':
          $rendererFile = SITE_ROOT . '/admin/modules/PodcastManager/includes/podcast_feed.renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $replacement = render_podcast_feed($attrs);
          } else {
            $replacement = '<!-- podcast-feed renderer not available -->';
          }
          break;

        /* ── youtube playlist ─────────────────────────────────────────── */
        case 'youtube-playlist': case 'yt-playlist':
          if (function_exists('render_youtube_playlist')) {
            $replacement = render_youtube_playlist($attrs);
          } else {
            $replacement = '<!-- youtube-playlist renderer not available -->';
          }
          break;

        /* ── youtube channel (renders the channel's uploads feed) ─────── */
        case 'youtube-channel': case 'yt-channel':
          if (function_exists('render_youtube_playlist')) {
            $replacement = render_youtube_playlist($attrs);
          } else {
            $replacement = '<!-- youtube-channel renderer not available -->';
          }
          break;

        /* ── youtube single video ─────────────────────────────────────── */
        case 'youtube-video': case 'yt-video':
          if (function_exists('render_youtube_video')) {
            $replacement = render_youtube_video($attrs);
          } else {
            $replacement = '<!-- youtube-video renderer not available -->';
          }
          break;

        /* ── Straw Hat Fits — auth shortcodes ─────────────────────────── */
        case 'shf-join':
          $replacement = function_exists('render_shf_join') ? render_shf_join($attrs) : '<!-- shf-join missing -->';
          break;
        case 'shf-login':
          $replacement = function_exists('render_shf_login') ? render_shf_login($attrs) : '<!-- shf-login missing -->';
          break;
        case 'shf-verify':
          $replacement = function_exists('render_shf_verify') ? render_shf_verify($attrs) : '<!-- shf-verify missing -->';
          break;
        case 'shf-forgot-password':
          $replacement = function_exists('render_shf_forgot_password') ? render_shf_forgot_password($attrs) : '<!-- shf-forgot-password missing -->';
          break;
        case 'shf-reset-password':
          $replacement = function_exists('render_shf_reset_password') ? render_shf_reset_password($attrs) : '<!-- shf-reset-password missing -->';
          break;
        case 'shf-logout':
          $libAuth = SITE_ROOT . '/admin/modules/CardManager/lib/auth.php';
          if (is_file($libAuth)) {
            require_once $libAuth;
            shf_logout_session();
            $replacement = '';
            // Redirect immediately if not headers-sent (typical case)
            if (!headers_sent()) { header('Location: ' . ($attrs['next'] ?? '/login')); exit; }
          } else { $replacement = '<!-- shf-logout missing -->'; }
          break;

        case 'shf-dashboard':
          $replacement = function_exists('render_shf_dashboard') ? render_shf_dashboard($attrs) : '<!-- shf-dashboard missing -->';
          break;
        case 'shf-account':
          $replacement = function_exists('render_shf_account') ? render_shf_account($attrs) : '<!-- shf-account missing -->';
          break;
        case 'shf-binder':
          $replacement = function_exists('render_shf_binder') ? render_shf_binder($attrs) : '<!-- shf-binder missing -->';
          break;
        case 'shf-binder-add':
          $replacement = function_exists('render_shf_binder_add') ? render_shf_binder_add($attrs) : '<!-- shf-binder-add missing -->';
          break;
        case 'shf-trades':
          $replacement = function_exists('render_shf_trades') ? render_shf_trades($attrs) : '<!-- shf-trades missing -->';
          break;
        case 'shf-trader-profile':
          $replacement = function_exists('render_shf_trader_profile') ? render_shf_trader_profile($attrs) : '<!-- shf-trader-profile missing -->';
          break;
        case 'shf-trade-offer':
          $replacement = function_exists('render_shf_trade_offer') ? render_shf_trade_offer($attrs) : '<!-- shf-trade-offer missing -->';
          break;
        case 'shf-messages':
          $replacement = function_exists('render_shf_messages') ? render_shf_messages($attrs) : '<!-- shf-messages missing -->';
          break;
        case 'shf-pay':
          $replacement = function_exists('render_shf_pay') ? render_shf_pay($attrs) : '<!-- shf-pay missing -->';
          break;
        case 'shf-pay-success':
          $replacement = function_exists('render_shf_pay_success') ? render_shf_pay_success($attrs) : '<!-- shf-pay-success missing -->';
          break;
        case 'shf-terms':
          $replacement = function_exists('render_shf_terms') ? render_shf_terms($attrs) : '<!-- shf-terms missing -->';
          break;
        case 'shf-privacy':
          $replacement = function_exists('render_shf_privacy') ? render_shf_privacy($attrs) : '<!-- shf-privacy missing -->';
          break;

        /* ── site stats widget ────────────────────────────────────────── */
        // ALL stats-widget aliases render the canonical DashboardStatsOG stamp,
        // so the inserted widget always matches the actual stats (admin Dashboard)
        // on BOTH DO (raw logs) and cPanel (AWStats) sites. The legacy vstats/
        // stats-captain/stats-lord aliases used to render empty — that was the bug.
        case 'dashboard-stats': case 'site-stats-stamp': case 'stats-stamp':
        case 'site-stats': case 'sitestats': case 'stats-widget':
        case 'vstats': case 'vstats-widget': case 'stats-captain': case 'stats-lord':
          $replacement = '';
          $_dsogEng = SITE_ROOT . '/admin/modules/Dashboard/DashboardStatsOG.php';
          if (is_file($_dsogEng)) {
            if (!function_exists('dsog_get_stats')) require_once $_dsogEng;
            $_scDomain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            // Range: accept both `range` and the Insert Explorer's `period`.
            $_scRaw   = strtolower(trim((string)($attrs['range'] ?? $attrs['period'] ?? '7d')));
            $_scMap   = ['today'=>'today','7d'=>'7d','7'=>'7d','30d'=>'30d','30'=>'30d',
                         'month'=>'this_month','this_month'=>'this_month','this-month'=>'this_month'];
            $_scRange = $_scMap[$_scRaw] ?? '7d';
            // Theme: dark (default) | light | minimal.
            $_scStyle = strtolower(trim((string)($attrs['style'] ?? 'dark')));
            if (!in_array($_scStyle, ['dark','light','minimal'], true)) $_scStyle = 'dark';
            $_scStats = dsog_get_stats($_scDomain, $_scRange);
            $replacement = dsog_render_stamp($_scStats, $_scDomain, false, $_scStyle);
          }
          break;

        /* ── affiliate products grid ────────────────────────────────── */
        case 'affiliate-products': case 'affiliates':
          if (function_exists('render_affiliate_products')) {
            $replacement = render_affiliate_products($attrs);
          } else {
            $replacement = '<!-- affiliate-products renderer not available -->';
          }
          break;

        /* ── single affiliate product ──────────────────────────────── */
        case 'affiliate-product':
          if (function_exists('render_affiliate_product')) {
            $attrs['slug'] = $slug;
            $replacement = render_affiliate_product($attrs);
          } else {
            $replacement = '<!-- affiliate-product renderer not available -->';
          }
          break;

        /* ── audience builder form ────────────────────────────────────── */
        case 'ab-form': case 'audience-form':
          $slug = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if (function_exists('render_ab_form')) {
            $replacement = render_ab_form($slug, $attrs);
          } else {
            $replacement = '<!-- ab-form renderer not available -->';
          }
          break;

        /* ── artist count (music-agency vertical) ─────────────────────── */
        case 'artist-count': case 'artists-count':
          $__cnt = 0;
          $__ad = SITE_ROOT . '/admin/data/artists';
          if (is_dir($__ad)) {
            foreach (glob($__ad . '/*/profile.json') ?: [] as $__f) {
              $__slug = basename(dirname($__f));
              if (str_starts_with($__slug, '_')) continue;
              $__p = @json_decode(@file_get_contents($__f), true);
              if ($__p && !empty($__p['name']) && (($__p['visibility'] ?? 'public') !== 'hidden')) $__cnt++;
            }
          }
          $replacement = (string)$__cnt;
          break;

        /* ── this week events widget ─────────────────────────────────── */
        case 'this-week': case 'thisweek': case 'upcoming': case 'shows':
          if (function_exists('render_this_week_widget')) {
            $replacement = render_this_week_widget($attrs);
          } else {
            $replacement = '<!-- this-week renderer not available -->';
          }
          break;

        /* ── facebook events ─────────────────────────────────────────── */
        case 'facebook-events': case 'fb-events': case 'events':
          $rendererFile = SITE_ROOT . '/admin/modules/FacebookEvents/includes/events_renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $replacement = render_facebook_events($attrs);
          } else {
            // Legacy fallback — try old /fbe/ path
            $legacyFile = SITE_ROOT . '/fbe/facebook_events_core.php';
            if (is_file($legacyFile)) {
              ob_start();
              include $legacyFile;
              $replacement = (string)ob_get_clean();
            } else {
              $replacement = '<!-- facebook-events: module not installed -->';
            }
          }
          break;

        /* ── printful: full store (catalog + cart) ───────────────────── */
        case 'printful-store': case 'printful':
          $rendererFile = SITE_ROOT . '/admin/modules/PrintfulManager/includes/storefront_renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $replacement = render_printful_store($attrs);
          } else {
            $legacyFile = SITE_ROOT . '/panels/printful/panel-printful.php';
            if (is_file($legacyFile)) {
              ob_start();
              include $legacyFile;
              $replacement = (string)ob_get_clean();
            } else {
              $replacement = '<!-- printful-store: module not installed -->';
            }
          }
          break;

        /* ── printful: single product ────────────────────────────────── */
        case 'printful-product':
          $rendererFile = SITE_ROOT . '/admin/modules/PrintfulManager/includes/storefront_renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $pid = $attrs['id'] ?? ($attrs['product'] ?? '');
            $replacement = $pid
              ? render_printful_store(['product' => $pid, 'layout' => 'single'])
              : '<!-- printful-product: missing id attr -->';
          } else {
            $replacement = '<!-- printful-product: module not installed -->';
          }
          break;

        /* ── printful: grid of products (no cart/search chrome) ──────── */
        case 'printful-grid':
          $rendererFile = SITE_ROOT . '/admin/modules/PrintfulManager/includes/storefront_renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $replacement = render_printful_store(array_merge($attrs, ['layout' => 'grid']));
          } else {
            $replacement = '<!-- printful-grid: module not installed -->';
          }
          break;

        /* ── printful: vertical column (sidebar-style) ───────────────── */
        case 'printful-column':
          $rendererFile = SITE_ROOT . '/admin/modules/PrintfulManager/includes/storefront_renderer.php';
          if (is_file($rendererFile)) {
            require_once $rendererFile;
            $replacement = render_printful_store(array_merge($attrs, ['layout' => 'column']));
          } else {
            $replacement = '<!-- printful-column: module not installed -->';
          }
          break;

        /* ── store / mystore ─────────────────────────────────────────── */
        case 'store': case 'mystore': case 'shop':
          if (!defined('MYSTORE_SYSTEM')) define('MYSTORE_SYSTEM', true);
          $storeFile = SITE_ROOT . '/admin/modules/MyStore/views/mystore-store.php';
          if (is_file($storeFile)) {
            ob_start();
            include $storeFile;
            $replacement = (string)ob_get_clean();
          } else {
            $replacement = '<!-- MyStore module not installed -->';
          }
          break;

        /* ── nlm-podcast (NotebookLM audio) ──────────────────────────── */
        case 'nlm-podcast': case 'nlm-audio': case 'notebooklm':
          $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $slug);
          $nlmDir = SITE_ROOT . '/media/notebooklm/' . $safeName . '/assets';
          if (is_dir($nlmDir)) {
            $nlmMetas = glob($nlmDir . '/*.json');
            if ($nlmMetas) {
              // Sort newest first
              usort($nlmMetas, fn($a, $b) => filemtime($b) <=> filemtime($a));
              $limit = isset($attrs['limit']) ? max(1, (int)$attrs['limit']) : 1;
              $nlmMetas = array_slice($nlmMetas, 0, $limit);
              $replacement = '<div class="nlm-podcast-embed">';
              foreach ($nlmMetas as $metaPath) {
                $meta = json_decode((string)file_get_contents($metaPath), true) ?: [];
                $ts = basename($metaPath, '.json');
                $audioUrl = '/media/notebooklm/' . sc_h($safeName) . '/assets/' . sc_h($ts) . '.mp3';
                $pTitle = sc_h($meta['title'] ?? 'Podcast');
                $replacement .= '<div class="nlm-podcast-item" style="margin-bottom:16px">';
                $replacement .= '<div style="font:600 14px system-ui;margin-bottom:6px">' . $pTitle . '</div>';
                if (!empty($meta['focus'])) {
                  $replacement .= '<div style="font:13px system-ui;color:#888;margin-bottom:6px">' . sc_h($meta['focus']) . '</div>';
                }
                $replacement .= '<audio controls preload="none" src="' . $audioUrl . '" style="width:100%;max-width:600px"></audio>';
                $replacement .= '</div>';
              }
              $replacement .= '</div>';
            } else {
              $replacement = '<!-- nlm-podcast: no audio found for ' . sc_h($slug) . ' -->';
            }
          } else {
            $replacement = '<!-- nlm-podcast: notebook "' . sc_h($slug) . '" not found -->';
          }
          break;

        /* ── element tracker (ServerMonitor beacon) ─────────────────── */
        case 'tracker':
          $label = preg_replace('/[^a-zA-Z0-9_\-]/', '', $attrs['name'] ?? '');
          if ($label !== '') {
            $smDomain = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '', ENT_QUOTES);
            $smPage   = htmlspecialchars($GLOBALS['pageSlug'] ?? ($_GET['p'] ?? 'home'), ENT_QUOTES);
            $replacement = '<div class="sm-tracker" data-sm-label="' . htmlspecialchars($label, ENT_QUOTES) . '" data-sm-page="' . $smPage . '" data-sm-domain="' . $smDomain . '" style="height:0;overflow:hidden;pointer-events:none" aria-hidden="true"></div>';
          } else {
            $replacement = '<!-- tracker: missing label -->';
          }
          break;

        /* ── ArticlesManager: grid / latest / archive / single ─────────── */
        case 'articles-library': case 'articles-by-show':
        case 'articles-grid':
        case 'articles-latest':
        case 'article-archive': case 'articles':
        case 'article-embed': case 'article':
          $amRenderer = SITE_ROOT . '/admin/modules/ArticlesManager/lib/renderers.php';
          $amStoreFile = SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
          $amHasContent = false;
          // Quick check: does the AM store have ANY published articles?
          // If the store is empty or all-draft, fall through to the legacy
          // renderer so sites that haven't adopted AM yet keep working.
          if (is_file($amStoreFile) && is_file($amRenderer)) {
            require_once $amStoreFile;
            $_amTmp = new \ArticlesManager\ArticleStore(SITE_ROOT);
            $_amStats = $_amTmp->stats();
            $amHasContent = ($_amStats['published'] ?? 0) > 0;
          }

          if ($amHasContent) {
            require_once $amRenderer;
            if ($name === 'article-embed' || $name === 'article') {
              $replacement = render_article_embed($attrs);
            } elseif ($name === 'articles-library' || $name === 'articles-by-show') {
              $replacement = function_exists('render_articles_library') ? render_articles_library($attrs) : render_articles_grid($attrs);
            } elseif ($name === 'articles-latest') {
              $replacement = render_articles_latest($attrs);
            } else {
              $replacement = render_articles_grid($attrs);
            }
          } else {
            // Legacy fallback — the old per-file article-archive renderer
            if (function_exists('render_article_archive')) {
              $replacement = render_article_archive($attrs);
            } else {
              $replacement = '<!-- articles renderer not available -->';
            }
          }
          break;

        /* ── reseller link ──────────────────────────────────────────── */
        case 'reseller-link': case 'reseller': case 'affiliate-link':
          $resellerId = (string)($attrs['name'] ?? $attrs['id'] ?? '');
          if ($resellerId !== '') {
            $resellerFile = SITE_ROOT . '/admin/data/AffiliateProducts/resellers.json';
            $resellers = is_file($resellerFile) ? (json_decode(file_get_contents($resellerFile), true) ?: []) : [];
            $r = $resellers[$resellerId] ?? null;
            if ($r && !empty($r['referralUrl']) && !empty($r['enabled'])) {
              $linkText  = sc_h($attrs['text'] ?? $r['linkText'] ?? $r['label'] ?? $resellerId);
              $linkClass = sc_h($attrs['class'] ?? 'reseller-link');
              $url       = sc_h($r['referralUrl']);
              $replacement = '<a href="' . $url . '" target="_blank" rel="noopener sponsored" class="' . $linkClass . '">' . $linkText . '</a>';
            } else {
              $replacement = '<!-- reseller "' . sc_h($resellerId) . '" not configured or disabled -->';
            }
          } else {
            $replacement = '<!-- reseller-link: missing id -->';
          }
          break;

        /* ── pro-directory (Music Agency artist directory) ────────── */
        case 'pro-directory':
          $proDir = SITE_ROOT . '/pro/directory-content.php';
          if (is_file($proDir)) {
            if (!defined('SITE_ROOT_PRO')) define('SITE_ROOT_PRO', SITE_ROOT);
            ob_start();
            include $proDir;
            $replacement = ob_get_clean();
          } else {
            $replacement = '<!-- pro-directory: directory-content.php not found -->';
          }
          break;

        /* ── site-text (SiteTextReceiver content slots) ────────────── */
        case 'site-text': case 'sitetext':
          $stKey = $slug ?: ($attrs['key'] ?? '');
          if ($stKey) {
            $stFile = SITE_ROOT . '/admin/data/site-text/' . preg_replace('/[^a-z0-9\-_]/', '', strtolower($stKey)) . '.json';
            if (is_file($stFile)) {
              $stData = json_decode(file_get_contents($stFile), true);
              $stClass = 'site-text-slot' . (!empty($attrs['class']) ? ' ' . sc_h($attrs['class']) : '');
              $replacement = '<div class="' . $stClass . '" data-key="' . sc_h($stKey) . '">' . ($stData['html'] ?? '') . '</div>';
            } else {
              $replacement = '<div class="site-text-slot" data-key="' . sc_h($stKey) . '"><!-- site-text: slot "' . sc_h($stKey) . '" not yet created --></div>';
            }
          } else {
            $replacement = '<!-- site-text: missing key -->';
          }
          break;

        /* ── unknown → leave literal for author to notice ────────────── */
        default:
          // No toast to avoid noise during draft authoring
          $replacement = $full;
      }

      $out    = substr($out, 0, $start) . $replacement . substr($out, $start + strlen($full));
      $offset = $start + strlen($replacement);
    }

    // Strip any unresolved [ai-image ...] shortcodes (single-bracket) so they never render to end users
    $out = preg_replace('/\[ai-image\s+[^\]]*\]/i', '<!-- ai-image removed -->', $out);

    // Restore protected <pre> blocks
    if (!empty($preBlocks)) {
      $out = str_replace(array_keys($preBlocks), array_values($preBlocks), $out);
    }

    sc_toast('info', "shortcodes: applied (in={$inLen}, out=" . strlen($out) . ")");
    return $out;
  }
}

/**
 * Render a live index of the admin docs tree.
 *
 * Reads admin/data/docs/{slug}/{slug}.json and lists what is actually there.
 * Nothing is cached or copied: add a doc with a release and it appears here.
 */
function sc_render_docs_index(): string {
    $root = SITE_ROOT . '/admin/data/docs';
    if (!is_dir($root)) return '<!-- docs-index: no docs tree on this site -->';

    $items = [];
    foreach ((glob($root . '/*', GLOB_ONLYDIR) ?: []) as $dir) {
        $slug = basename($dir);
        if ($slug === 'docs') continue;                 // the index itself
        $json = $dir . '/' . $slug . '.json';
        if (!is_file($json)) continue;
        $d = json_decode((string)@file_get_contents($json), true);
        if (!is_array($d)) continue;
        $title = trim((string)($d['page_title'] ?? '')) ?: ucwords(str_replace(['docs-', '-'], ['', ' '], $slug));
        $desc  = trim((string)($d['meta_description'] ?? ''));
        $items[] = ['slug' => $slug, 'title' => $title, 'desc' => $desc];
    }
    if (!$items) return '<!-- docs-index: docs tree is empty -->';

    usort($items, fn($a, $b) => strcasecmp($a['title'], $b['title']));

    $out = '<div class="lm-docs-index">';
    foreach ($items as $it) {
        $out .= '<a class="lm-docs-card" href="/page.php?p=' . rawurlencode($it['slug']) . '">'
              . '<span class="lm-docs-title">' . htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') . '</span>';
        if ($it['desc'] !== '') {
            $out .= '<span class="lm-docs-desc">' . htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $out .= '</a>';
    }
    return $out . '</div>';
}

?>