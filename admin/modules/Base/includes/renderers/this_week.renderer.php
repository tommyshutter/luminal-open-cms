<?php
/**
 * [[this-week]] shortcode renderer
 *
 * Source of truth: **Facebook Events API** (per site's own FacebookEvents config).
 * Cache: flat file at admin/data/FacebookEvents/cache/this-week-events.json,
 *        15 minute TTL, stale-on-error fallback (use expired cache if FB call fails).
 *
 * Previous sources (bookings.json, EventsManagerPro) removed 2026-04-15 —
 * they required manual sync and drifted from FB. FB is now the single upstream.
 */

if (!function_exists('_this_week_fetch_fb_events')) {
    /**
     * Delegate to the FacebookEvents module. The module owns FB auth,
     * config, caching (cache_duration from config), and API semantics.
     * This renderer is a consumer, not a reimplementation.
     */
    function _this_week_fetch_fb_events(string $siteRoot): array {
        $fnFile = $siteRoot . '/admin/modules/FacebookEvents/FacebookEventsFunctions.php';
        if (!is_file($fnFile)) return [];
        require_once $fnFile;
        if (!function_exists('fbe_load_config') || !function_exists('fbe_fetch_events')) return [];
        $cfg = fbe_load_config();
        if (empty($cfg['page_id']) || empty($cfg['access_token'])) return [];
        $log = [];
        return fbe_fetch_events($cfg, $log);
    }
}

if (!function_exists('render_this_week_widget')) {
    function render_this_week_widget(array $attrs = []): string {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__);
        $days = intval($attrs['days'] ?? 7);
        $limit = intval($attrs['limit'] ?? 20);
        $title = $attrs['title'] ?? 'This Week at Earl\'s';
        $style = $attrs['style'] ?? 'dark'; // dark | light | minimal

        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $allUpcoming = [];
        $events = [];

        // Source: Facebook Events (single source of truth)
        $fbEvents = _this_week_fetch_fb_events($siteRoot);
        $tz = new DateTimeZone('America/New_York');
        foreach ($fbEvents as $ev) {
            $startRaw = $ev['start_time'] ?? '';
            if (!$startRaw) continue;
            try {
                $dt = new DateTime($startRaw);
                $dt->setTimezone($tz);
            } catch (\Throwable $e) {
                continue;
            }
            $d = $dt->format('Y-m-d');
            if ($d < $today) continue; // past events
            $entry = [
                'date'       => $d,
                'time'       => $dt->format('g:i A'),         // e.g. "6:00 PM"
                'name'       => $ev['name'] ?? '(unnamed)',
                'notes'      => '',
                'fb_status'  => 'posted',
                'fb_post_id' => $ev['id'] ?? null,
                'artist_slug'=> null,
            ];
            $allUpcoming[] = $entry;
            if ($d <= $endDate) $events[] = $entry;
        }

        // Auto-expand: if nothing in the window, show next upcoming events
        if (empty($events) && !empty($allUpcoming)) {
            usort($allUpcoming, function($a, $b) {
                $aKey = $a['date'] . ' ' . date('H:i', strtotime($a['time'] ?: '00:00'));
                $bKey = $b['date'] . ' ' . date('H:i', strtotime($b['time'] ?: '00:00'));
                return strcmp($aKey, $bKey);
            });
            $events = array_slice($allUpcoming, 0, $limit);
            $title = $attrs['title'] ?? 'Upcoming Shows';
        }

        // Sort by date + time (convert 12hr to 24hr for correct ordering)
        usort($events, function($a, $b) {
            $aKey = $a['date'] . ' ' . date('H:i', strtotime($a['time'] ?: '00:00'));
            $bKey = $b['date'] . ' ' . date('H:i', strtotime($b['time'] ?: '00:00'));
            return strcmp($aKey, $bKey);
        });
        $events = array_slice($events, 0, $limit);

        // Group by date, track months
        $grouped = [];
        foreach ($events as $e) {
            $grouped[$e['date']][] = $e;
        }
        $lastMonth = '';

        // Render
        $bgColor = $style === 'light' ? '#f8f9fa' : ($style === 'minimal' ? 'transparent' : '#13151a');
        $textColor = $style === 'light' ? '#1a1a2e' : '#e0e0e0';
        $accentColor = $style === 'light' ? '#d97706' : '#f59e0b';
        $mutedColor = $style === 'light' ? '#6b7280' : '#888';
        $borderColor = $style === 'light' ? 'rgba(0,0,0,0.08)' : 'rgba(255,255,255,0.06)';
        $cardBg = $style === 'light' ? 'rgba(0,0,0,0.03)' : 'rgba(255,255,255,0.03)';

        $html = '<div class="tw-widget" style="background:' . $bgColor . ';border-radius:12px;padding:20px 24px;font-family:system-ui,-apple-system,sans-serif;color:' . $textColor . '">';
        $html .= '<h3 style="font-size:1.2rem;font-weight:800;color:' . $accentColor . ';margin:0 0 16px;text-transform:uppercase;letter-spacing:0.03em">' . htmlspecialchars($title) . '</h3>';

        if (empty($events)) {
            $html .= '<p style="font-size:0.88rem;color:' . $mutedColor . ';font-style:italic;margin:0">No upcoming shows scheduled. Check back soon!</p>';
        } else {
            foreach ($grouped as $date => $dayEvents) {
                $dayLabel = date('l, M j', strtotime($date));
                $thisMonth = date('F Y', strtotime($date));
                $isToday = $date === $today;

                // Month header — collapsible, next month starts collapsed
                if ($thisMonth !== $lastMonth) {
                    if ($lastMonth !== '') {
                        // Close previous month div
                        $html .= '</div>';
                    }
                    $isFirstMonth = ($lastMonth === '');
                    $monthId = 'tw-' . strtolower(date('M-Y', strtotime($date)));
                    $html .= '<div style="margin-top:' . ($isFirstMonth ? '0' : '16px') . '">';
                    $html .= '<div onclick="var el=document.getElementById(\'' . $monthId . '\');el.style.display=el.style.display===\'none\'?\'\':\'none\';this.querySelector(\'span\').textContent=el.style.display===\'none\'?\'&#9654;\':\'&#9660;\'" style="cursor:pointer;display:flex;align-items:center;gap:8px;margin-bottom:10px;user-select:none">';
                    $html .= '<span style="font-size:0.6rem;color:' . $mutedColor . '">' . ($isFirstMonth ? '&#9660;' : '&#9654;') . '</span>';
                    $html .= '<span style="font-size:0.85rem;font-weight:800;color:' . $accentColor . ';letter-spacing:0.03em">' . htmlspecialchars($thisMonth) . '</span>';
                    $html .= '</div>';
                    $html .= '<div id="' . $monthId . '"' . ($isFirstMonth ? '' : ' style="display:none"') . '>';
                    $lastMonth = $thisMonth;
                }

                $html .= '<div style="margin-bottom:14px">';
                $html .= '<div style="font-size:0.72rem;font-weight:700;color:' . $mutedColor . ';text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid ' . $borderColor . '">';
                $html .= htmlspecialchars($dayLabel);
                if ($isToday) $html .= ' <span style="color:' . $accentColor . ';font-weight:800">TONIGHT</span>';
                $html .= '</div>';
                foreach ($dayEvents as $ev) {
                    $hasFb = !empty($ev['fb_post_id']) && ($ev['fb_status'] ?? '') === 'posted';
                    $fbUrl = $hasFb ? 'https://www.facebook.com/events/' . htmlspecialchars($ev['fb_post_id']) : '';
                    $html .= '<div style="display:flex;align-items:center;gap:12px;padding:8px 10px;background:' . $cardBg . ';border-radius:8px;margin-bottom:4px">';
                    $html .= '<div style="font-size:0.78rem;color:' . $accentColor . ';font-weight:700;white-space:nowrap;min-width:70px">' . htmlspecialchars($ev['time']) . '</div>';
                    $html .= '<div style="flex:1">';
                    if ($hasFb) {
                        $html .= '<a href="' . $fbUrl . '" target="_blank" rel="noopener" style="font-size:0.9rem;font-weight:600;color:' . $textColor . ';text-decoration:none;display:inline-flex;align-items:center;gap:6px">';
                        $html .= htmlspecialchars($ev['name']);
                        $html .= ' <span style="font-size:0.65rem;color:#4267B2" title="View on Facebook">&#9679; FB</span>';
                        $html .= '</a>';
                    } else {
                        $html .= '<div style="font-size:0.9rem;font-weight:600;color:' . $textColor . '">' . htmlspecialchars($ev['name']) . '</div>';
                    }
                    if (!empty($ev['notes'])) {
                        $html .= '<div style="font-size:0.72rem;color:' . $mutedColor . ';margin-top:2px">' . htmlspecialchars($ev['notes']) . '</div>';
                    }
                    $html .= '</div></div>';
                }
                $html .= '</div>';
            }
        }

        if ($lastMonth !== '') $html .= '</div></div>'; // close last month content + wrapper
        $html .= '</div>';
        return $html;
    }
}
