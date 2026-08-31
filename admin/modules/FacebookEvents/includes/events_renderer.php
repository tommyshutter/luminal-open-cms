<?php
/**
 * FacebookEvents — Front-end Shortcode Renderer
 *
 * Renders Facebook events on public pages via [[facebook-events]] shortcode.
 * Replaces legacy /fbe/facebook_events_core.php + panel-events.php.
 *
 * Reads config from admin/data/FacebookEvents/config.json (the module's config,
 * not the old facebook_events_settings.json).
 *
 * Supports: grid/list layout, cover-image shape (natural/portrait/square),
 * cache, fallback URL, canceled event filtering. Events always render in calendar
 * order (earliest first, left-to-right).
 *
 * @package    LuminalCMS
 * @module     FacebookEvents
 * @version    2.2.0
 * @file       admin/modules/FacebookEvents/includes/events_renderer.php
 * @date       2026-03-20
 * @replaces   /fbe/facebook_events_core.php, panels/panel-events.php
 */

if (!function_exists('render_facebook_events')) {

function render_facebook_events(array $attrs = []): string {
    if (!defined('SITE_ROOT')) return '<!-- facebook-events: SITE_ROOT not defined -->';

    // Layered resolve: legacy < module config, module config wins per key.
    // This previously let the legacy file REPLACE the entire config whenever the
    // module config had no access_token, silently discarding page_id and every
    // display setting alongside it. Same rule as fbe_load_config().
    $config = [];
    $legacyFile = SITE_ROOT . '/admin/data/facebook_events_settings.json';
    if (is_file($legacyFile)) {
        $legacy = json_decode((string)@file_get_contents($legacyFile), true);
        if (is_array($legacy)) $config = $legacy;
    }
    $configFile = SITE_ROOT . '/admin/data/FacebookEvents/config.json';
    if (is_file($configFile)) {
        $primary = json_decode((string)@file_get_contents($configFile), true);
        if (is_array($primary)) $config = array_merge($config, $primary);
    }

    if (empty($config['access_token']) || empty($config['page_id'])) {
        // If fallback URL configured, show link card
        if (!empty($config['fallback_url'])) {
            return '<div class="fbe-front"><a href="' . htmlspecialchars($config['fallback_url']) . '" target="_blank" class="fbe-fallback-card">View Events on Facebook</a></div>';
        }
        return '<!-- facebook-events: not configured -->';
    }

    // Shortcode attrs
    // Layout: grid | list. Falls back to the legacy view_mode setting. (masonry was
    // dropped — CSS columns fill top-to-bottom, which breaks left-to-right calendar order.)
    $layout    = strtolower((string)($attrs['layout'] ?? $config['layout'] ?? $config['view_mode'] ?? 'grid'));
    if (!in_array($layout, ['grid', 'list'], true)) $layout = 'grid';
    $viewMode  = $layout; // keep legacy class naming in sync with the chosen layout
    // Cover-image shape: natural (default, full aspect — fits the portrait flyers),
    // portrait (uniform 4:5 tile), or square (uniform 1:1 tile).
    $shape     = strtolower((string)($attrs['shape'] ?? $config['image_shape'] ?? 'natural'));
    if (!in_array($shape, ['natural', 'portrait', 'square'], true)) $shape = 'natural';
    // Columns: auto (responsive auto-fill) or a fixed 1–4 (grid layout only).
    $cols      = strtolower((string)($attrs['columns'] ?? $config['columns'] ?? 'auto'));
    if (!in_array($cols, ['auto', '1', '2', '3', '4'], true)) $cols = 'auto';
    $limit     = (int)($attrs['limit'] ?? $config['limit_events'] ?? 50);
    $showDesc  = ($attrs['description'] ?? ($config['display_options']['show_description'] ?? false)) ? true : false;
    $showTime  = ($config['display_options']['show_time'] ?? true) ? true : false;
    $timeFrame = $attrs['time'] ?? $config['time_frame'] ?? 'upcoming';

    // Cache
    $cacheDir  = SITE_ROOT . '/admin/data/FacebookEvents/caches';
    $cacheFile = $cacheDir . '/events_front.cache';
    $cacheTTL  = (int)($config['cache_duration'] ?? 3600);

    $events = null;

    // Try cache first
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $events = json_decode(file_get_contents($cacheFile), true);
    }

    // Fetch from Facebook API if no cache
    if ($events === null) {
        $pageId = $config['page_id'];
        $token  = $config['access_token'];
        $url = "https://graph.facebook.com/v17.0/{$pageId}/events?"
            . http_build_query([
                'fields'      => 'id,name,description,start_time,end_time,cover,place,is_canceled',
                'access_token'=> $token,
                'limit'       => $limit,
                'time_filter' => $timeFrame,
            ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200 && $body) {
            $data = json_decode($body, true);
            $events = $data['data'] ?? [];
            // Sort by start_time
            usort($events, fn($a, $b) => strtotime($a['start_time'] ?? '0') - strtotime($b['start_time'] ?? '0'));
            // Cache
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            @file_put_contents($cacheFile, json_encode($events));
        } else {
            $events = [];
        }
    }

    // Filter canceled if configured
    if (empty($config['display_options']['show_canceled'])) {
        $events = array_filter($events, fn($e) => empty($e['is_canceled']));
        $events = array_values($events);
    }

    // Calendar order, left-to-right (earliest first). The cache may pre-date this
    // sort or arrive unordered, so always re-sort before render.
    usort($events, fn($a, $b) => strtotime($a['start_time'] ?? '0') - strtotime($b['start_time'] ?? '0'));

    // Fallback if no events
    if (empty($events)) {
        if (!empty($config['fallback_url'])) {
            return '<div class="fbe-front"><a href="' . htmlspecialchars($config['fallback_url']) . '" target="_blank" class="fbe-fallback-card" style="display:block;text-align:center;padding:24px;background:#1877F2;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:1.1rem">View Events on Facebook</a></div>';
        }
        return '<div class="fbe-front"><p style="color:#888;text-align:center;padding:20px">No upcoming events.</p></div>';
    }

    // Render — grid container carries the layout + cover-image shape.
    $grid = '<div class="fbe-grid fbe-' . htmlspecialchars($viewMode) . '" data-layout="' . htmlspecialchars($layout) . '" data-shape="' . htmlspecialchars($shape) . '" data-cols="' . htmlspecialchars($cols) . '">';

    foreach ($events as $event) {
        $id       = $event['id'] ?? '';
        $name     = htmlspecialchars($event['name'] ?? '');
        $cover    = $event['cover']['source'] ?? '';
        $canceled = !empty($event['is_canceled']);
        $link     = "https://www.facebook.com/events/{$id}/";

        // Format date
        $dateStr = '';
        if (!empty($event['start_time'])) {
            try {
                $dt = new DateTime($event['start_time']);
                $dateStr = $dt->format('F j, Y \a\t g:i A');
            } catch (Exception $e) {
                $dateStr = $event['start_time'];
            }
        }

        $place = '';
        if (!empty($event['place']['name'])) {
            $place = htmlspecialchars($event['place']['name']);
        }

        $grid .= '<a href="' . htmlspecialchars($link) . '" target="_blank" class="fbe-card-link">';
        $grid .= '<div class="fbe-card' . ($canceled ? ' fbe-canceled' : '') . '">';

        if ($canceled) {
            $grid .= '<div class="fbe-canceled-badge">CANCELED</div>';
        }

        if ($cover) {
            $grid .= '<div class="fbe-cover"><img src="' . htmlspecialchars($cover) . '" alt="' . $name . '" loading="lazy"></div>';
        }

        $grid .= '<div class="fbe-info">';
        $grid .= '<div class="fbe-title">' . $name . '</div>';
        if ($dateStr && $showTime) $grid .= '<div class="fbe-date">' . $dateStr . '</div>';
        if ($place) $grid .= '<div class="fbe-place">' . $place . '</div>';
        if ($showDesc && !empty($event['description'])) {
            $desc = htmlspecialchars(mb_substr($event['description'], 0, 150));
            $grid .= '<div class="fbe-desc">' . $desc . '...</div>';
        }
        $grid .= '</div></div></a>';
    }

    $grid .= '</div>'; // close .fbe-grid

    $html = '<style>' . _fbe_front_css() . '</style>';
    $html .= '<div class="fbe-front">' . $grid . '</div>';
    return $html;
}

function _fbe_front_css(): string {
    return <<<'CSS'
.fbe-front { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
/* Layout: grid (default) — responsive CSS grid, cards top-aligned so natural
   (variable-height) covers don't stretch their row-mates. */
.fbe-grid { display:grid; gap:16px; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); align-items:start; }
.fbe-grid[data-layout="grid"] { display:grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); align-items:start; }
/* Layout: list — single full-width column */
.fbe-grid.fbe-list,
.fbe-grid[data-layout="list"] { display:grid; grid-template-columns: 1fr; }
/* Fixed column count (grid layout only) — overrides the responsive auto-fill */
.fbe-grid[data-layout="grid"][data-cols="1"] { grid-template-columns: 1fr; }
.fbe-grid[data-layout="grid"][data-cols="2"] { grid-template-columns: repeat(2,1fr); }
.fbe-grid[data-layout="grid"][data-cols="3"] { grid-template-columns: repeat(3,1fr); }
.fbe-grid[data-layout="grid"][data-cols="4"] { grid-template-columns: repeat(4,1fr); }
.fbe-card-link { text-decoration:none; color:inherit; display:block; }
.fbe-card {
    background:rgba(30,30,30,0.9); border-radius:10px; overflow:hidden;
    box-shadow:0 4px 12px rgba(0,0,0,0.4); transition:transform 0.2s, box-shadow 0.2s;
    position:relative;
}
.fbe-card:hover { transform:translateY(-3px); box-shadow:0 8px 20px rgba(0,0,0,0.5); }
.fbe-canceled { opacity:0.6; }
.fbe-canceled-badge {
    position:absolute; top:10px; right:10px; background:#dc3545; color:#fff;
    padding:3px 10px; border-radius:4px; font-weight:700; font-size:0.75rem; z-index:2;
}
/* Cover image shaping. Default = natural: show the full flyer (now portrait) at its
   true aspect, no cropping. portrait/square give uniform cropped tiles. */
.fbe-cover { width:100%; overflow:hidden; }
.fbe-cover img { width:100%; display:block; }
.fbe-grid[data-shape="natural"] .fbe-cover img { height:auto; }
.fbe-grid[data-shape="portrait"] .fbe-cover { aspect-ratio:4/5; }
.fbe-grid[data-shape="portrait"] .fbe-cover img { height:100%; object-fit:cover; }
.fbe-grid[data-shape="square"] .fbe-cover { aspect-ratio:1/1; }
.fbe-grid[data-shape="square"] .fbe-cover img { height:100%; object-fit:cover; }
/* Browsers without aspect-ratio: fall back to a sensible fixed height for cropped shapes */
@supports not (aspect-ratio: 1/1) {
    .fbe-grid[data-shape="portrait"] .fbe-cover img { height:320px; object-fit:cover; }
    .fbe-grid[data-shape="square"] .fbe-cover img { height:250px; object-fit:cover; }
}
.fbe-info { padding:14px; }
.fbe-title { font-size:1.05rem; font-weight:700; color:#f0f0f0; margin-bottom:6px; }
.fbe-date { font-size:0.85rem; color:#4a9eff; font-weight:500; margin-bottom:4px; }
.fbe-place { font-size:0.82rem; color:#888; margin-bottom:6px; }
.fbe-desc { font-size:0.82rem; color:#aaa; line-height:1.5; }
/* Tablet: cap 3/4-column grids to 2 so portrait cards stay legible */
@media (max-width:768px) {
    .fbe-grid[data-layout="grid"][data-cols="3"],
    .fbe-grid[data-layout="grid"][data-cols="4"] { grid-template-columns:repeat(2,1fr); }
}
/* Phone: single column regardless of setting */
@media (max-width:480px) {
    .fbe-grid,
    .fbe-grid[data-layout="grid"],
    .fbe-grid[data-layout="grid"][data-cols="2"],
    .fbe-grid[data-layout="grid"][data-cols="3"],
    .fbe-grid[data-layout="grid"][data-cols="4"] { grid-template-columns:1fr; }
}
CSS;
}

} // end function_exists guard
