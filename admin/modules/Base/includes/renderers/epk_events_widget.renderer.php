<?php
/**
 * EPK Events Widget Renderer
 *
 * Shortcode: [[upcoming-shows]] | [[shows-widget]] | [[epk-events]]
 * Attrs:  limit (default 10), style (dark|light), past (true|false)
 *
 * Reads events_master.json + venues_master.json, filters upcoming published
 * events, renders a compact table with date, venue, city, time, FB link.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

function render_epk_events_widget(array $attrs = []): string
{
    $limit = max(1, (int)($attrs['limit'] ?? 10));
    $style = ($attrs['style'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $showPast = ($attrs['past'] ?? 'false') === 'true';

    /* ── Load data ──────────────────────────────────────────────────── */
    $eventsFile = SITE_ROOT . '/admin/data/events_master.json';
    $venuesFile = SITE_ROOT . '/admin/data/venues_master.json';

    if (!is_file($eventsFile)) {
        return '<div class="epk-shows-empty">No upcoming shows scheduled.</div>';
    }

    $events = json_decode((string)file_get_contents($eventsFile), true);
    if (!is_array($events)) {
        return '<div class="epk-shows-empty">No upcoming shows scheduled.</div>';
    }

    $venues = [];
    if (is_file($venuesFile)) {
        $venueData = json_decode((string)file_get_contents($venuesFile), true);
        if (is_array($venueData)) {
            foreach ($venueData as $v) {
                if (!empty($v['id'])) {
                    $venues[$v['id']] = $v;
                }
            }
        }
    }

    /* ── Filter & sort ──────────────────────────────────────────────── */
    $today = date('Y-m-d');
    $filtered = [];

    foreach ($events as $evt) {
        if (($evt['status'] ?? '') !== 'published') continue;
        $startDate = $evt['start_date'] ?? '';
        if ($startDate === '') continue;

        if (!$showPast && $startDate < $today) continue;
        if ($showPast && $startDate >= $today) continue;

        $filtered[] = $evt;
    }

    // Sort by start_date ascending (upcoming), descending for past
    usort($filtered, function ($a, $b) use ($showPast) {
        $cmp = strcmp($a['start_date'], $b['start_date']);
        return $showPast ? -$cmp : $cmp;
    });

    $filtered = array_slice($filtered, 0, $limit);

    if (empty($filtered)) {
        $msg = $showPast ? 'No past shows to display.' : 'No upcoming shows scheduled.';
        return '<div class="epk-shows-empty">' . htmlspecialchars($msg) . '</div>';
    }

    /* ── Inject CSS (once) ──────────────────────────────────────────── */
    static $cssInjected = false;
    $css = '';
    if (!$cssInjected) {
        $cssInjected = true;
        $css = _epk_widget_css();
    }

    /* ── Build table ────────────────────────────────────────────────── */
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $html = $css;
    $html .= '<div class="epk-shows-widget epk-shows-' . $style . '">';
    $html .= '<table class="epk-shows-table">';
    $html .= '<thead><tr>';
    $html .= '<th>Date</th><th>Venue</th><th>City</th><th class="epk-col-time">Time</th><th></th>';
    $html .= '</tr></thead><tbody>';

    foreach ($filtered as $evt) {
        $date = '';
        if (!empty($evt['start_date'])) {
            $ts = strtotime($evt['start_date']);
            $date = $ts ? date('M j, Y', $ts) : $evt['start_date'];
        }

        $venueName = '';
        $city = '';
        $venueId = $evt['venue_id'] ?? '';
        if ($venueId && isset($venues[$venueId])) {
            $venue = $venues[$venueId];
            $venueName = $venue['name'] ?? '';
            $city = _epk_extract_city($venue['address'] ?? '');
        }

        $time = '';
        if (!empty($evt['start_time'])) {
            $tts = strtotime('2000-01-01 ' . $evt['start_time']);
            $time = $tts ? date('g:i A', $tts) : $evt['start_time'];
        }

        $fbLink = $evt['fb_link'] ?? '';

        $rowClass = $fbLink ? ' class="epk-row-linked"' : '';
        $rowClick = $fbLink ? ' data-href="' . $h($fbLink) . '"' : '';

        $html .= '<tr' . $rowClass . $rowClick . '>';
        $html .= '<td class="epk-col-date">' . $h($date) . '</td>';
        $html .= '<td>' . $h($venueName) . '</td>';
        $html .= '<td>' . $h($city) . '</td>';
        $html .= '<td class="epk-col-time">' . $h($time) . '</td>';
        $html .= '<td class="epk-col-link">';
        if ($fbLink) {
            $html .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12c0-5.523-4.477-10-10-10z"/></svg>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    // Row-click handler for linked rows
    $html .= '<script>document.addEventListener("click",function(e){var r=e.target.closest(".epk-row-linked");if(r&&r.dataset.href)window.open(r.dataset.href,"_blank","noopener")});</script>';

    return $html;
}

/**
 * Extract city/state from venue address via regex.
 * Tries patterns like "City, State ZIP" or "City, FL 33301"
 */
function _epk_extract_city(string $address): string
{
    if ($address === '') return '';

    // Try "City, State ZIP" pattern
    if (preg_match('/([A-Za-z\s.]+),\s*([A-Z]{2})\s*\d{0,5}/', $address, $m)) {
        return trim($m[1]) . ', ' . $m[2];
    }
    // Try "City, StateName" pattern
    if (preg_match('/([A-Za-z\s.]+),\s*([A-Za-z]+)\s*\d{0,5}\s*$/', $address, $m)) {
        return trim($m[1]) . ', ' . trim($m[2]);
    }
    // Fallback: last comma-separated segment
    $parts = array_map('trim', explode(',', $address));
    return count($parts) >= 2 ? $parts[count($parts) - 2] . ', ' . $parts[count($parts) - 1] : $address;
}

/**
 * Inline CSS for the widget (injected once per page).
 */
function _epk_widget_css(): string
{
    return '<style>
.epk-shows-widget {
    width: 100%;
    overflow-x: auto;
    border-radius: 8px;
    font-family: system-ui, -apple-system, sans-serif;
}
.epk-shows-dark {
    background: rgba(0, 0, 0, 0.6);
    color: #e2e8f0;
}
.epk-shows-light {
    background: rgba(255, 255, 255, 0.9);
    color: #1e293b;
}
.epk-shows-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.epk-shows-table thead th {
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    text-align: left;
    white-space: nowrap;
}
.epk-shows-dark thead th {
    background: rgba(255, 255, 255, 0.08);
    color: #94a3b8;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.epk-shows-light thead th {
    background: rgba(0, 0, 0, 0.04);
    color: #64748b;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}
.epk-shows-table tbody td {
    padding: 10px 14px;
    white-space: nowrap;
}
.epk-shows-dark tbody tr {
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.epk-shows-light tbody tr {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}
.epk-row-linked { cursor: pointer; }
.epk-shows-dark tbody tr:hover {
    background: rgba(255, 255, 255, 0.04);
}
.epk-shows-dark .epk-row-linked:hover {
    background: rgba(96, 165, 250, 0.1);
}
.epk-shows-light tbody tr:hover {
    background: rgba(0, 0, 0, 0.03);
}
.epk-shows-light .epk-row-linked:hover {
    background: rgba(37, 99, 235, 0.06);
}
.epk-col-date {
    font-weight: 600;
    min-width: 100px;
}
.epk-col-link {
    text-align: center;
    width: 40px;
}
.epk-col-link svg {
    vertical-align: middle;
    opacity: 0.6;
}
.epk-shows-dark .epk-col-link svg { color: #60a5fa; }
.epk-shows-light .epk-col-link svg { color: #2563eb; }
.epk-row-linked:hover .epk-col-link svg { opacity: 1; }
.epk-shows-empty {
    text-align: center;
    padding: 24px 16px;
    color: #94a3b8;
    font-style: italic;
    font-size: 14px;
}
@media (max-width: 640px) {
    .epk-col-time { display: none; }
    .epk-shows-table { font-size: 13px; }
    .epk-shows-table thead th,
    .epk-shows-table tbody td { padding: 8px 10px; }
}
</style>';
}
