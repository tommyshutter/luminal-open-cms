<?php
/**
 * YouTube Playlist Shortcode Renderer
 * @file includes/renderers/youtube_playlist.renderer.php
 *
 * Usage: [[youtube-playlist:slug]] or [[yt-playlist:slug]]
 * Attributes: limit="N", columns="N", description="true|false"
 *
 * Loads config from admin/data/youtube_playlists/{slug}.json
 * Caches YouTube API responses in admin/data/youtube_playlists/cache/{slug}.json (15-min TTL)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

/**
 * Fetch YouTube playlist items via API.
 * Shared helper — also used by api.php and the legacy panel.
 */
if (!function_exists('fetch_youtube_playlist_data')) {
    /**
     * Fetch playlist items. YouTube API caps at 50 items per request; we honor that as
     * the hard ceiling. $max is the user's per_page setting (1..50).
     */
    function fetch_youtube_playlist_data(string $playlist_id, string $api_key, int $max = 50): array {
        // Self-heal records saved before playlist_id was normalized: a pasted URL stored
        // verbatim makes YouTube return an opaque "Invalid Value" 400 that looks like a
        // bad API key. Derive the bare ID at read time rather than migrating the store.
        $playlist_id = trim($playlist_id);
        if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~i', $playlist_id, $m) && !preg_match('~^RD~', $m[1])) {
            $playlist_id = $m[1];
        } elseif (preg_match('~[/?&=:]~', $playlist_id)) {
            return ['error' => 'Playlist ID is not a valid YouTube playlist link (needs a ?list=… ID).'];
        }
        if ($playlist_id === '' || $api_key === '') {
            return ['error' => 'Playlist ID or API Key is not configured.'];
        }
        $pageSize = max(1, min(50, $max));
        $url = "https://www.googleapis.com/youtube/v3/playlistItems"
             . "?part=snippet,contentDetails&maxResults={$pageSize}"
             . "&playlistId=" . urlencode($playlist_id)
             . "&key=" . urlencode($api_key);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => 'luminal-cms/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response  = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            return ['error' => "Failed to fetch playlist. HTTP $http_code"];
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['items'])) {
            return ['error' => 'Could not parse playlist data or playlist is empty.'];
        }
        return $data['items'];
    }
}

/**
 * Render a YouTube playlist player + grid.
 *
 * @param array $attrs Shortcode attributes: slug, limit, columns, description
 * @return string HTML output
 */
function render_youtube_playlist(array $attrs = []): string {
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($attrs['slug'] ?? $attrs['name'] ?? '')));
    // No slug → default to the first available playlist ("default" if present, else the
    // first *.json). Matches the retired panel-youtube_playlist.php bridge so bare
    // [[youtube-playlist]] renders the same thing the panel did.
    if (!$slug) {
        $ypDir = SITE_ROOT . '/admin/data/youtube_playlists';
        if (is_file("$ypDir/default.json")) {
            $slug = 'default';
        } else {
            foreach (glob("$ypDir/*.json") ?: [] as $ypf) {
                $slug = basename($ypf, '.json');
                break;
            }
        }
    }
    if (!$slug) return '<!-- youtube-playlist: no playlist configured -->';

    $configFile = SITE_ROOT . "/admin/data/youtube_playlists/$slug.json";
    if (!is_file($configFile)) {
        return "<!-- youtube-playlist: playlist '$slug' not found -->";
    }

    if (!function_exists('cred_load_json') && defined('SITE_ROOT') && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) require_once SITE_ROOT . '/admin/config/cred_vault.php';
    $config = function_exists('cred_load_json') ? cred_load_json($configFile) : json_decode(file_get_contents($configFile), true);
    // Single-video records live in this same store (type="video", no playlist_id/api_key).
    // If a [[youtube-playlist:slug]] tag points at one, delegate to the video renderer
    // instead of bailing "not configured" — so either shortcode tag works for either record.
    if (is_array($config) && (($config['type'] ?? '') === 'video' || (empty($config['playlist_id']) && !empty($config['video_id'])))) {
        if (!function_exists('render_youtube_video')) require_once __DIR__ . '/youtube_video.renderer.php';
        return function_exists('render_youtube_video')
            ? render_youtube_video($attrs)
            : "<!-- youtube-playlist: '$slug' is a single video but the video renderer is unavailable -->";
    }
    // Channel records store only channel_id. Their uploads playlist — the thing that
    // is actually renderable — is derived here rather than stored, so everything below
    // (keyless player, API grid, caching, paging) applies to a channel unchanged.
    // UC… → UU… is YouTube's own rule, not ours.
    if (is_array($config) && ($config['type'] ?? '') === 'channel' && empty($config['playlist_id'])) {
        $ytChId = trim((string)($config['channel_id'] ?? ''));
        if (!preg_match('~^UC[A-Za-z0-9_-]{22}$~', $ytChId)) {
            return "<!-- youtube-playlist: '$slug' is a channel with no usable channel ID -->";
        }
        $config['playlist_id'] = 'UU' . substr($ytChId, 2);
    }

    if (!is_array($config) || empty($config['playlist_id'])) {
        return "<!-- youtube-playlist: '$slug' not configured -->";
    }

    // ---- NO API KEY? Still render the playlist. --------------------------------------
    // A key is needed only to LIST the videos (Data API playlistItems) for the card grid.
    // Playing a playlist needs no key at all — YouTube's own embed takes ?list=… and
    // handles ordering, next/previous and autoplay itself. Refusing to render anything
    // without a key made an API key look mandatory for every playlist, which it is not.
    // (Tom, 2026-08-18: "the YT URLs should not have to have an API key by default.")
    // Singles have never needed one — their metadata comes from noembed at save time.
    if (empty($config['api_key'])) {
        $klId = trim((string)$config['playlist_id']);
        if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~i', $klId, $mk)) $klId = $mk[1];
        $klId = preg_replace('/[^A-Za-z0-9_-]/', '', $klId);
        if ($klId === '') return "<!-- youtube-playlist: '$slug' has no usable playlist ID -->";

        $klLoop = $config['loop_playlist'] ?? false;
        if (is_string($klLoop)) $klLoop = !in_array(strtolower($klLoop), ['false','0','no','off',''], true);
        $klMax  = max(30, min(100, (int)($config['player_max'] ?? 100)));
        $klSrc  = 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode($klId)
                . ($klLoop ? '&loop=1' : '');
        $klTitle = htmlspecialchars((string)($config['title'] ?? 'YouTube playlist'), ENT_QUOTES, 'UTF-8');

        static $klCss = false;
        $css = '';
        if (!$klCss) {
            $klCss = true;
            $css = '<style>.ytpl-keyless{margin:0 auto}'
                 . '.ytpl-keyless iframe{width:100%;aspect-ratio:16/9;display:block;border:0;border-radius:8px}'
                 . '</style>';
        }
        return $css . '<div class="ytpl-container ytpl-keyless" style="max-width:' . $klMax . '%">'
             . '<div class="ytpl-player">'
             . '<iframe src="' . htmlspecialchars($klSrc, ENT_QUOTES, 'UTF-8') . '"'
             . ' title="' . $klTitle . '"'
             . ' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
             . ' referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy"></iframe>'
             . '</div></div>'
             . '<!-- youtube-playlist: rendered without an API key (player only; add a key for the video grid) -->';
    }

    // Shortcode attribute overrides
    $perPage = (int)($attrs['limit'] ?? $config['per_page'] ?? 24);
    $perPage = max(1, min(50, $perPage));
    $columns = (int)($attrs['columns'] ?? $config['columns'] ?? 4);
    $columns = max(1, min(8, $columns));
    // Hero player size — percent of the container width (attr 'player' overrides config).
    $playerMax = (int)($attrs['player'] ?? $config['player_max'] ?? 100);
    $playerMax = max(30, min(100, $playerMax));
    // Match card grid width to the player (attr 'grid_match' overrides config).
    $gridMatch = $attrs['grid_match'] ?? ($config['grid_match'] ?? false);
    if (is_string($gridMatch)) $gridMatch = !in_array(strtolower($gridMatch), ['false', '0', 'no', ''], true);
    // Space between cards — emitted INLINE on the grid so the site's cascade can't override it
    // (the CSS var --ytpl-gap was being clobbered by site stylesheets). Attr 'gap' overrides config.
    $cardGap = (int)($attrs['gap'] ?? $config['card_gap'] ?? 10);
    $cardGap = max(0, min(80, $cardGap));
    $showDesc = $attrs['description'] ?? ($config['show_description'] ?? false);
    if (is_string($showDesc)) $showDesc = !in_array(strtolower($showDesc), ['false', '0', 'no'], true);

    // ---- Auto-advance / loop ----------------------------------------------------------
    // Without a list= context the embed is a lone video, so nothing plays after it ends.
    // Handing YouTube the playlist ID lets IT advance natively — no IFrame API, no polling,
    // and it keeps working when the user goes fullscreen. Default ON: a playlist that stops
    // dead after one video is nobody's intent. Attr 'advance' / 'loop' override config.
    $autoAdvance = $attrs['advance'] ?? ($config['auto_advance'] ?? true);
    if (is_string($autoAdvance)) $autoAdvance = !in_array(strtolower($autoAdvance), ['false', '0', 'no', 'off'], true);
    $loopAll = $attrs['loop'] ?? ($config['loop_playlist'] ?? false);
    if (is_string($loopAll)) $loopAll = !in_array(strtolower($loopAll), ['false', '0', 'no', 'off', ''], true);

    // Bare list ID for the embed (records may still hold a pasted URL — normalize here too).
    $embedListId = trim((string)($config['playlist_id'] ?? ''));
    if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~i', $embedListId, $mList)) $embedListId = $mList[1];

    // Shared embed suffix, used by the initial iframe AND the card-click swap in JS.
    $embedExtra = '';
    if ($autoAdvance && $embedListId !== '') {
        $embedExtra .= '&list=' . rawurlencode($embedListId);
        // loop=1 needs the list context to loop the whole playlist rather than one video.
        if ($loopAll) $embedExtra .= '&loop=1';
    }

    // ---- Load items (with caching) ----
    $cacheDir  = SITE_ROOT . '/admin/data/youtube_playlists/cache';
    $cacheFile = "$cacheDir/$slug.json";
    $cacheTTL  = 900; // 15 minutes
    $items     = null;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            $items = $cached;
        }
    }

    if ($items === null) {
        // Pull up to max_pull (default 50, capped at 50 — YouTube's per-request ceiling).
        // per_page controls display page size, not fetch size — we need the full set to
        // paginate through it.
        $maxPull = max(1, min(50, (int)($config['max_pull'] ?? 50)));
        $items = fetch_youtube_playlist_data($config['playlist_id'], $config['api_key'], $maxPull);
        if (isset($items['error'])) {
            return "<!-- youtube-playlist: {$items['error']} -->";
        }
        // Write cache
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
        @file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_SLASHES));
        @chown($cacheFile, 'www-data');
        @chgrp($cacheFile, 'www-data');
    }

    if (!is_array($items) || empty($items)) {
        return '<!-- youtube-playlist: no items -->';
    }

    // per_page = display page size (1..50). Pages computed from total fetched.
    $totalItems = count($items);
    $pages      = max(1, (int)ceil($totalItems / $perPage));
    $page       = max(1, min($pages, (int)($_GET['pg'] ?? 1)));
    $pageItems  = array_slice($items, ($page - 1) * $perPage, $perPage);

    // ---- Active video ----
    $activeVid = $_GET['v'] ?? ($items[0]['contentDetails']['videoId'] ?? null);
    $activeTitle = '...';
    $activeDesc  = '';
    foreach ($items as $it) {
        if (($it['contentDetails']['videoId'] ?? null) === $activeVid) {
            $activeTitle = $it['snippet']['title'] ?? '...';
            $activeDesc  = $it['snippet']['description'] ?? '';
            break;
        }
    }

    // ---- Build base URL for pagination/card links ----
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $urlParts   = parse_url($requestUri);
    $basePath   = $urlParts['path'] ?? '/';
    parse_str($urlParts['query'] ?? '', $qsBase);

    // Unique ID for multiple playlists on same page
    static $instanceCount = 0;
    $instanceCount++;
    $uid = "ytpl-{$slug}-{$instanceCount}";
    $eUid = htmlspecialchars($uid, ENT_QUOTES, 'UTF-8');

    // ---- CSS (inject once) ----
    static $cssInjected = false;
    $css = '';
    if (!$cssInjected) {
        $cssInjected = true;
        $css = <<<'YTCSS'
<style>
.ytpl-container{ --ytpl-card-min:190px; --ytpl-gap:10px; }
.ytpl-container .ytpl-player{ margin:0 0 10px 0; }
.ytpl-container .ytpl-player iframe{ width:100%; aspect-ratio:16/9; display:block; border:0; border-radius:8px; }
.ytpl-container .ytpl-content{ display:flex; flex-direction:column; gap:12px; }
.ytpl-desc{ background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.12);
  border-radius:12px; padding:10px 12px; }
.ytpl-desc.collapsed .ytpl-desc-inner{ max-height:0; overflow:hidden; opacity:0; margin-top:0; }
.ytpl-desc .ytpl-desc-inner{ transition:max-height .4s ease, opacity .25s ease; }
.ytpl-desc h2{ font-size:1rem; margin:0 0 6px; color:#fff; }
.ytpl-desc p{ font-size:0.88rem; color:#ccc; line-height:1.5; margin:6px 0 0; white-space:pre-line; }
.ytpl-desc-toggle{ background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.15);
  color:#aaa; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:0.78rem; margin-top:6px; }
.ytpl-grid{
  display:grid; grid-template-columns:repeat(auto-fill, minmax(var(--ytpl-card-min), 1fr));
  grid-auto-rows:auto; gap:var(--ytpl-gap);
}
.ytpl-item{ background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.12);
  border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
.ytpl-item-link{ text-decoration:none; color:inherit; display:block; }
.ytpl-item img{ width:100%; aspect-ratio:16/9; object-fit:cover; display:block; }
.ytpl-item .ytpl-vid-title{ padding:8px 10px; font-size:.85rem; line-height:1.2; color:#ddd; }
.ytpl-pager{ display:flex; gap:10px; align-items:center; justify-content:center; margin:12px 0 4px; }
.ytpl-pg{ display:inline-block; padding:6px 10px; border-radius:8px;
  background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.12); text-decoration:none; color:#fff; font-size:0.85rem; }
.ytpl-pg.disabled{ opacity:.45; pointer-events:none; }
@media(max-width:1024px){ .ytpl-container{ --ytpl-card-min:160px; } }
@media(max-width:768px){
  .ytpl-item-link{ display:block !important; min-height:auto !important;
    align-items:stretch !important; justify-content:stretch !important; }
  .ytpl-item img{ width:100% !important; height:auto !important; aspect-ratio:16/9 !important;
    object-fit:cover !important; display:block !important; }
  .ytpl-desc-toggle{ width:auto !important; min-height:36px !important;
    display:inline-block !important; padding:6px 12px !important; }
  .ytpl-pg{ display:inline-block !important; width:auto !important;
    min-height:36px !important; padding:8px 12px !important; }
}
@media(max-width:640px){
  .ytpl-container{ --ytpl-card-min:100%; --ytpl-gap:12px; }
  .ytpl-grid{ grid-template-columns:1fr !important; }
  .ytpl-item .ytpl-vid-title{ font-size:.9rem; padding:10px 12px; }
  .ytpl-player iframe{ border-radius:4px; }
  .ytpl-desc{ border-radius:8px; padding:8px 10px; }
}
</style>
YTCSS;
    }

    // ---- Helper ----
    $esc = function(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };

    // ---- Build HTML ----
    $html = $css;
    // Explicit column count wins. Inline style on the grid forces exactly N columns.
    // The auto-fill fallback in the base CSS only kicks in if this inline style is absent.
    // Stacks to 1 column on narrow viewports via the mobile media query further down.
    $gridColsStyle = 'grid-template-columns:repeat(' . $columns . ',minmax(0,1fr))';
    // data-embed-extra carries the list/loop params so the card-click handler rebuilds the
    // src exactly the way PHP first rendered it — one source of truth for the embed URL.
    $html .= '<div class="ytpl-container" id="' . $eUid . '" data-embed-extra="' . $esc($embedExtra) . '">' . "\n";

    // Player
    if ($activeVid) {
        $playerStyle = $playerMax < 100 ? ' style="max-width:' . $playerMax . '%;margin-inline:auto"' : '';
        $html .= '  <div class="ytpl-player"' . $playerStyle . '>' . "\n";
        $html .= '    <iframe id="' . $eUid . '-player" src="https://www.youtube.com/embed/' . $esc($activeVid) . '?autoplay=0&rel=0' . $esc($embedExtra) . '" allow="autoplay; fullscreen" allowfullscreen loading="lazy"></iframe>' . "\n";
        $html .= '  </div>' . "\n";
    }

    $html .= '  <div class="ytpl-content">' . "\n";

    // Description
    $collClass = $showDesc ? '' : ' collapsed';
    $html .= '    <div class="ytpl-desc' . $collClass . '" id="' . $eUid . '-desc"' . $playerStyle . '>' . "\n";
    $html .= '      <h2 id="' . $eUid . '-title">' . $esc($activeTitle) . '</h2>' . "\n";
    $html .= '      <div class="ytpl-desc-inner" id="' . $eUid . '-desc-inner">' . "\n";
    $html .= '        <p id="' . $eUid . '-desc-text">' . nl2br($esc($activeDesc)) . '</p>' . "\n";
    $html .= '      </div>' . "\n";
    $html .= '      <button class="ytpl-desc-toggle" data-uid="' . $eUid . '">Show More</button>' . "\n";
    $html .= '    </div>' . "\n";

    // Grid
    $gridWidthStyle = ($gridMatch && $playerMax < 100) ? ';max-width:' . $playerMax . '%;margin-inline:auto' : '';
    $html .= '    <div class="ytpl-grid" id="' . $eUid . '-grid" style="' . $gridColsStyle . ';gap:' . $cardGap . 'px' . $gridWidthStyle . '">' . "\n";
    foreach ($pageItems as $it) {
        $vid   = $it['contentDetails']['videoId'] ?? '';
        if (!$vid) continue;
        $title = $it['snippet']['title'] ?? '';
        $desc  = $it['snippet']['description'] ?? '';
        // Trim description for data attribute (full text loaded via JS if needed)
        $descShort = mb_strlen($desc) > 300 ? mb_substr($desc, 0, 300) . '...' : $desc;
        $thumb = $it['snippet']['thumbnails']['high']['url'] ?? $it['snippet']['thumbnails']['default']['url'] ?? '';

        $qsCard = $qsBase;
        $qsCard['v'] = $vid;
        $link = $basePath . '?' . http_build_query($qsCard);

        $html .= '      <a href="' . $esc($link) . '" class="ytpl-item-link" data-video-id="' . $esc($vid) . '" data-title="' . $esc($title) . '" data-desc="' . $esc($descShort) . '" data-uid="' . $eUid . '">' . "\n";
        $html .= '        <div class="ytpl-item">' . "\n";
        if ($thumb) {
            $html .= '          <img src="' . $esc($thumb) . '" alt="" loading="lazy">' . "\n";
        }
        $html .= '          <div class="ytpl-vid-title">' . $esc($title) . '</div>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '      </a>' . "\n";
    }
    $html .= '    </div>' . "\n";

    // Pagination
    if ($pages > 1) {
        $mkLink = function(int $pg) use ($basePath, $qsBase): string {
            $q = $qsBase;
            $q['pg'] = $pg;
            unset($q['v']); // jumping pages clears the active video
            return $basePath . '?' . http_build_query($q);
        };
        $html .= '    <nav class="ytpl-pager" aria-label="Playlist pagination">' . "\n";
        $prevCls = $page <= 1 ? ' disabled' : '';
        $nextCls = $page >= $pages ? ' disabled' : '';
        $html .= '      <a class="ytpl-pg' . $prevCls . '" href="' . $esc($mkLink(max(1, $page - 1))) . '" rel="prev">&laquo; Prev</a>' . "\n";
        $html .= '      <span class="ytpl-pg ytpl-pg-status">Page ' . $page . ' / ' . $pages . '</span>' . "\n";
        $html .= '      <a class="ytpl-pg' . $nextCls . '" href="' . $esc($mkLink(min($pages, $page + 1))) . '" rel="next">&raquo; Next</a>' . "\n";
        $html .= '    </nav>' . "\n";
    }

    $html .= '  </div>' . "\n";
    $html .= '</div>' . "\n";

    // ---- JS (inject once) ----
    static $jsInjected = false;
    if (!$jsInjected) {
        $jsInjected = true;
        $html .= <<<'YTJS'
<script>
document.addEventListener('DOMContentLoaded', function(){
  // Card click → swap player, update description
  document.addEventListener('click', function(e){
    const link = e.target.closest('.ytpl-item-link');
    if (!link) return;
    e.preventDefault();
    const uid = link.dataset.uid;
    const vid = link.dataset.videoId;
    const title = link.dataset.title || '';
    const desc = link.dataset.desc || '';
    const player = document.getElementById(uid + '-player');
    const titleEl = document.getElementById(uid + '-title');
    const descEl = document.getElementById(uid + '-desc-text');
    if (player && vid) {
      // Carry the list/loop context through, so clicking a card keeps auto-advance alive
      // instead of dropping the player back to a single isolated video.
      const extra = document.getElementById(uid)?.dataset.embedExtra || '';
      player.src = 'https://www.youtube.com/embed/' + vid + '?autoplay=1&rel=0' + extra;
      if (titleEl) titleEl.textContent = title;
      if (descEl) descEl.innerHTML = desc.replace(/\n/g, '<br>');
      history.pushState({videoId: vid}, title, link.href);
      document.getElementById(uid)?.querySelector('.ytpl-player')?.scrollIntoView({behavior:'smooth', block:'start'});
    }
  });
  // Description toggle
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.ytpl-desc-toggle');
    if (!btn) return;
    const uid = btn.dataset.uid;
    const wrap = document.getElementById(uid + '-desc');
    if (wrap) {
      const collapsed = wrap.classList.toggle('collapsed');
      btn.textContent = collapsed ? 'Show More' : 'Show Less';
    }
  });
});
</script>
YTJS;
    }

    return $html;
}
