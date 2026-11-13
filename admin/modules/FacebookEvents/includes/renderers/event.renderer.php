<?php
/**
 * Event Shortcode Renderer
 * @file      /includes/renderers/event.renderer.php
 * @version   2026.01.26.FacebookEnhanced
 * @purpose   Renders [[event:evt_xxx]] shortcodes from Events Manager
 * - Enhanced Facebook sharing with destination selection (Timeline, Groups, Pages)
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

if (!function_exists('render_event_shortcode')) {
    /**
     * Render a single event by ID
     * @param string $eventId The event ID (e.g., evt_6975521096d35)
     * @return string HTML output
     */
    function render_event_shortcode(string $eventId): string {
        if ($eventId === '') {
            return '<!-- event: no ID provided -->';
        }

        // Load events data
        $eventsFile = SITE_ROOT . '/admin/data/events_master.json';
        if (!is_file($eventsFile)) {
            return '<!-- event: events_master.json not found -->';
        }

        $raw = @file_get_contents($eventsFile);
        $events = json_decode($raw ?? '', true);
        if (!is_array($events)) {
            return '<!-- event: invalid events data -->';
        }

        // Find the event
        $event = null;
        foreach ($events as $e) {
            if (($e['id'] ?? '') === $eventId) {
                $event = $e;
                break;
            }
        }

        if (!$event) {
            return '<!-- event: ID ' . htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8') . ' not found -->';
        }

        // Load venue data if available
        $venue = null;
        if (!empty($event['venue_id'])) {
            $venuesFile = SITE_ROOT . '/admin/data/venues_master.json';
            if (is_file($venuesFile)) {
                $venuesRaw = @file_get_contents($venuesFile);
                $venues = json_decode($venuesRaw ?? '', true);
                if (is_array($venues)) {
                    foreach ($venues as $v) {
                        if (($v['id'] ?? '') === $event['venue_id']) {
                            $venue = $v;
                            break;
                        }
                    }
                }
            }
        }

        return render_event_card($event, $venue);
    }

    /**
     * Render the event card HTML
     */
    function render_event_card(array $event, ?array $venue): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $title = $h($event['title'] ?? 'Untitled Event');
        $status = $event['status'] ?? 'draft';

        // Build full URL for sharing
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $siteUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Format date/time
        $dateStr = 'TBA';
        if (!empty($event['start_date'])) {
            $dt = strtotime(($event['start_date'] ?? '') . ' ' . ($event['start_time'] ?? '00:00'));
            if ($dt) {
                $dateStr = date('l, F j, Y', $dt);
                if (!empty($event['start_time'])) {
                    $dateStr .= ' at ' . date('g:i A', $dt);
                }
            }
        }

        // End time
        $endStr = '';
        if (!empty($event['end_time']) && !empty($event['end_date'])) {
            $endDt = strtotime($event['end_date'] . ' ' . $event['end_time']);
            if ($endDt) {
                $endStr = date('g:i A', $endDt);
            }
        }

        // Image - build full URL for OG tags
        $image = '';
        $imageFullUrl = '';
        if (!empty($event['image'])) {
            $imgPath = '/' . ltrim($event['image'], '/');
            $imageFullUrl = $siteUrl . $imgPath;
            $image = '<div class="event-card-image"><img src="' . $h($imgPath) . '" alt="' . $title . '" loading="lazy"></div>';
        }

        // Venue info with clickable address and phone
        $venueHtml = '';
        if ($venue) {
            $venueName = $h($venue['name'] ?? '');
            $venueAddr = $venue['address'] ?? '';
            $venuePhone = $venue['phone'] ?? '';
            $venueWebsite = $venue['website'] ?? '';
            $venueLogo = !empty($venue['image']) ? '<img src="/' . ltrim($venue['image'], '/') . '" class="venue-logo" alt="' . $venueName . '">' : '';

            $venueHtml = '<div class="event-venue">' . $venueLogo . '<div class="venue-details"><span class="venue-name">' . $venueName . '</span>';

            // Address - clickable to Google Maps
            if ($venueAddr) {
                $mapsUrl = 'https://www.google.com/maps/search/' . urlencode($venueAddr);
                $venueHtml .= '<a href="' . $h($mapsUrl) . '" target="_blank" class="venue-address"><i class="fa-solid fa-location-dot"></i> ' . $h($venueAddr) . '</a>';
            }

            // Phone
            if ($venuePhone) {
                $phoneClean = preg_replace('/[^0-9+]/', '', $venuePhone);
                $venueHtml .= '<a href="tel:' . $h($phoneClean) . '" class="venue-phone"><i class="fa-solid fa-phone"></i> ' . $h($venuePhone) . '</a>';
            }

            // Website
            if ($venueWebsite) {
                $websiteDisplay = preg_replace('#^https?://#', '', rtrim($venueWebsite, '/'));
                $venueHtml .= '<a href="' . $h($venueWebsite) . '" target="_blank" class="venue-website"><i class="fa-solid fa-globe"></i> ' . $h($websiteDisplay) . '</a>';
            }

            $venueHtml .= '</div></div>';
        }

        // Links - including Facebook Share button and Email
        $links = '';

        // Facebook Share button - enhanced with modal for destination selection
        $eventShareUrl = $siteUrl . '/event.php?id=' . urlencode($event['id']);
        $shareText = urlencode($title . ' - ' . $dateStr);

        // Build share data for modal
        $fbShareData = $h(json_encode([
            'id' => $event['id'],
            'title' => $title,
            'date' => $dateStr,
            'image' => $imageFullUrl,
            'url' => $eventShareUrl,
            'cta_url' => $event['cta_url'] ?? '',
            'fb_link' => $event['fb_link'] ?? ''
        ]));
        $links .= '<button type="button" class="event-link event-link-share" title="Share on Facebook" onclick="openEventFbShareModal(this)" data-event=\'' . $fbShareData . '\'><i class="fa-brands fa-facebook"></i> Share</button>';

        // Email Share button - professional formatting
        $emailSubject = rawurlencode("You're Invited: " . $title);

        $emailBody = "Hey!\n\n";
        $emailBody .= "I wanted to share this event with you:\n\n";

        // Add event flyer image URL at the top so email clients may preview it
        if (!empty($event['image'])) {
            $imgPath = '/' . ltrim($event['image'], '/');
            $emailBody .= "EVENT FLYER\n";
            $emailBody .= $siteUrl . $imgPath . "\n\n";
        }

        $emailBody .= "========================================\n";
        $emailBody .= strtoupper($title) . "\n";
        $emailBody .= $dateStr . "\n";
        $emailBody .= "========================================\n\n";

        if ($venue) {
            $emailBody .= "VENUE: " . ($venue['name'] ?? '') . "\n";
            if (!empty($venue['address'])) {
                $emailBody .= $venue['address'] . "\n";
                $emailBody .= "Map: https://www.google.com/maps/search/" . rawurlencode($venue['address']) . "\n";
            }
            if (!empty($venue['website'])) {
                $emailBody .= "Website: " . $venue['website'] . "\n";
            }
            $emailBody .= "\n";
        }

        if (!empty($event['fb_link'])) {
            $emailBody .= "TICKETS / MORE INFO\n";
            $emailBody .= $event['fb_link'] . "\n\n";
        }

        $emailBody .= "VIEW FULL EVENT DETAILS\n";
        $emailBody .= $siteUrl . '/event.php?id=' . $event['id'] . "\n\n";
        $emailBody .= "Hope to see you there!";

        $emailBodyEncoded = rawurlencode($emailBody);
        $links .= '<a href="mailto:?subject=' . $emailSubject . '&body=' . $emailBodyEncoded . '" class="event-link event-link-email" title="Share via Email"><i class="fa-solid fa-envelope"></i></a>';

        if (!empty($event['fb_link'])) {
            $links .= '<a href="' . $h($event['fb_link']) . '" target="_blank" class="event-link event-link-fb" title="Tickets/Info"><i class="fa-solid fa-ticket"></i></a>';
        }

        // Map link - use stored map_link (if URL) or construct Google Maps URL from address
        $mapUrl = '';
        if (!empty($event['map_link'])) {
            // Check if map_link is already a URL or just an address
            if (preg_match('/^https?:\/\//i', $event['map_link'])) {
                $mapUrl = $event['map_link'];
            } else {
                // It's just an address, construct Google Maps URL
                $mapUrl = 'https://www.google.com/maps/search/' . urlencode($event['map_link']);
            }
        } elseif ($venue && !empty($venue['address'])) {
            $mapUrl = 'https://www.google.com/maps/search/' . urlencode($venue['address']);
        }
        if ($mapUrl) {
            $links .= '<a href="' . $h($mapUrl) . '" target="_blank" class="event-link event-link-map" title="View Map"><i class="fa-solid fa-map-marker-alt"></i></a>';
        }

        if (!empty($event['yt_link'])) {
            $links .= '<a href="' . $h($event['yt_link']) . '" target="_blank" class="event-link event-link-yt" title="YouTube"><i class="fa-brands fa-youtube"></i></a>';
        }

        // Description
        $desc = '';
        if (!empty($event['content_html'])) {
            $desc = '<div class="event-description">' . $event['content_html'] . '</div>';
        }

        // Build card
        $html = <<<HTML
<div class="event-card" data-event-id="{$h($event['id'])}" data-status="{$h($status)}">
    {$image}
    <div class="event-card-body">
        <h3 class="event-title">{$title}</h3>
        <div class="event-datetime">
            <i class="fa-regular fa-calendar"></i>
            <span>{$dateStr}</span>
HTML;

        if ($endStr) {
            $html .= '<span class="event-time-separator">-</span><span>' . $endStr . '</span>';
        }

        $html .= <<<HTML
        </div>
        {$venueHtml}
        {$desc}
HTML;

        if ($links) {
            $html .= '<div class="event-links">' . $links . '</div>';
        }

        $html .= <<<HTML
    </div>
</div>
HTML;

        // Include FB share modal and scripts only once per page
        static $fbShareModalIncluded = false;
        if (!$fbShareModalIncluded) {
            $fbShareModalIncluded = true;
            $html .= render_event_fb_share_modal();
        }

        return $html;
    }

    /**
     * Render the Facebook share modal (included once per page)
     */
    function render_event_fb_share_modal(): string {
        // Load FB App ID — module config wins, legacy is only a fallback.
        // This read the legacy file ONLY, so an App ID set in the admin UI
        // (which writes admin/data/FacebookEvents/config.json) never reached
        // the share modal.
        $fbAppId = '';
        foreach ([
            SITE_ROOT . '/admin/data/FacebookEvents/config.json',
            SITE_ROOT . '/admin/data/facebook_events_settings.json',
        ] as $fbSettingsFile) {
            if (!is_file($fbSettingsFile)) continue;
            $fbSettings = json_decode((string)@file_get_contents($fbSettingsFile), true);
            if (is_array($fbSettings) && !empty($fbSettings['fb_app_id'])) {
                $fbAppId = (string)$fbSettings['fb_app_id'];
                break;
            }
        }
        $fbAppIdJs = addslashes($fbAppId);

        return <<<HTML
<!-- Event Facebook Share Modal -->
<div class="ev-fb-share-modal-overlay" id="eventFbShareModal">
    <div class="ev-fb-share-modal">
        <div class="ev-fb-share-modal-header">
            <h3><i class="fa-brands fa-facebook"></i> Share to Facebook</h3>
            <button type="button" class="ev-fb-share-modal-close" onclick="closeEventFbShareModal()">&times;</button>
        </div>
        <div class="ev-fb-share-modal-body">
            <div class="ev-fb-share-event-preview">
                <img id="eventFbSharePreviewImg" src="" alt="" style="display:none;">
                <div class="ev-fb-share-no-img" id="eventFbShareNoImg"><i class="fa-solid fa-calendar"></i></div>
                <div>
                    <strong id="eventFbSharePreviewTitle"></strong>
                    <br><small id="eventFbSharePreviewDate"></small>
                </div>
            </div>

            <div class="ev-fb-share-options">
                <button onclick="eventFbShareAdvanced()" class="ev-fb-share-option ev-fb-share-option-primary" id="eventFbShareAdvancedBtn">
                    <i class="fa-solid fa-users"></i>
                    <div>
                        <strong>Share Dialog (Choose Destination)</strong>
                        <span>Post to your Timeline, Groups, or Pages you manage</span>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;opacity:0.5;"></i>
                </button>

                <button onclick="eventFbShareBasic()" class="ev-fb-share-option">
                    <i class="fa-solid fa-share"></i>
                    <div>
                        <strong>Quick Share (Your Timeline)</strong>
                        <span>Simple share to your personal feed</span>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;opacity:0.5;"></i>
                </button>

                <button onclick="eventFbOpenComposer()" class="ev-fb-share-option">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <div>
                        <strong>Copy & Open Facebook</strong>
                        <span>Paste anywhere - Groups, Messages, Pages</span>
                    </div>
                    <i class="fa-solid fa-external-link" style="margin-left:auto;opacity:0.5;"></i>
                </button>
            </div>

            <div class="ev-fb-share-notice" id="eventFbShareNotice" style="display:none;">
                <i class="fa-solid fa-info-circle"></i>
                <span>For advanced sharing options, the site admin needs to configure a Facebook App ID.</span>
            </div>
        </div>
    </div>
</div>

<style>
.ev-fb-share-modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); z-index:10001; align-items:center; justify-content:center; }
.ev-fb-share-modal-overlay.active { display:flex; }
.ev-fb-share-modal { background:#1a1a1a; border:1px solid #333; border-radius:12px; width:90%; max-width:480px; max-height:90vh; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.6); animation:evFbModalSlideIn 0.3s ease; }
@keyframes evFbModalSlideIn { from { opacity:0; transform:scale(0.95) translateY(-20px); } to { opacity:1; transform:scale(1) translateY(0); } }
.ev-fb-share-modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; background:#1877F2; color:white; }
.ev-fb-share-modal-header h3 { margin:0; display:flex; align-items:center; gap:10px; font-size:1.1rem; color:white; }
.ev-fb-share-modal-close { background:transparent; border:none; color:white; font-size:1.8rem; cursor:pointer; line-height:1; padding:0 5px; opacity:0.8; }
.ev-fb-share-modal-close:hover { opacity:1; }
.ev-fb-share-modal-body { padding:20px; overflow-y:auto; max-height:calc(90vh - 70px); }
.ev-fb-share-event-preview { display:flex; gap:15px; padding:15px; background:#111; border:1px solid #333; border-radius:8px; margin-bottom:20px; align-items:center; }
.ev-fb-share-event-preview img { width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #444; }
.ev-fb-share-no-img { width:60px; height:60px; background:#222; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#666; font-size:1.5rem; flex-shrink:0; }
.ev-fb-share-event-preview > div:last-child { flex:1; overflow:hidden; }
.ev-fb-share-event-preview strong { color:#fff; display:block; margin-bottom:5px; }
.ev-fb-share-event-preview small { color:#888; }
.ev-fb-share-options { display:flex; flex-direction:column; gap:10px; }
.ev-fb-share-option { display:flex; align-items:center; gap:15px; padding:15px; background:#222; border:1px solid #333; border-radius:8px; cursor:pointer; transition:all 0.2s ease; text-align:left; width:100%; color:#fff; font-family:inherit; font-size:1rem; }
.ev-fb-share-option:hover { background:#2a2a2a; border-color:#1877F2; }
.ev-fb-share-option:disabled { opacity:0.5; cursor:not-allowed; }
.ev-fb-share-option > i:first-child { font-size:1.3rem; width:30px; text-align:center; color:#1877F2; }
.ev-fb-share-option > div { flex:1; }
.ev-fb-share-option strong { display:block; color:#fff; margin-bottom:3px; font-size:0.95rem; }
.ev-fb-share-option span { display:block; color:#888; font-size:0.8rem; }
.ev-fb-share-option-primary { border-color:#1877F2; background:rgba(24,119,242,0.1); }
.ev-fb-share-option-primary:hover { background:rgba(24,119,242,0.2); }
.ev-fb-share-notice { display:flex; gap:10px; padding:12px 15px; background:rgba(243,156,18,0.1); border:1px solid rgba(243,156,18,0.3); border-radius:8px; margin-top:15px; color:#f39c12; font-size:0.85rem; align-items:flex-start; }
.ev-fb-share-notice i { margin-top:2px; flex-shrink:0; }
.event-link-share { cursor:pointer; border:none; font-family:inherit; font-size:inherit; }
</style>

<div id="fb-root"></div>
<script>
window.EVENT_FB_APP_ID = '{$fbAppIdJs}';

// Initialize Facebook SDK if not already done
if (!window.fbAsyncInit) {
    window.fbAsyncInit = function() {
        if (window.EVENT_FB_APP_ID) {
            FB.init({ appId: window.EVENT_FB_APP_ID, cookie: true, xfbml: true, version: 'v19.0' });
        }
    };
    (function(d, s, id) {
        var js, fjs = d.getElementsByTagName(s)[0];
        if (d.getElementById(id)) return;
        js = d.createElement(s); js.id = id;
        js.src = "https://connect.facebook.net/en_US/sdk.js";
        fjs.parentNode.insertBefore(js, fjs);
    }(document, 'script', 'facebook-jssdk'));
}

let currentEventFbShareData = null;

function openEventFbShareModal(btn) {
    currentEventFbShareData = JSON.parse(btn.dataset.event);
    const modal = document.getElementById('eventFbShareModal');

    const previewImg = document.getElementById('eventFbSharePreviewImg');
    const noImg = document.getElementById('eventFbShareNoImg');
    if (currentEventFbShareData.image) {
        previewImg.src = currentEventFbShareData.image;
        previewImg.style.display = 'block';
        noImg.style.display = 'none';
    } else {
        previewImg.style.display = 'none';
        noImg.style.display = 'flex';
    }
    document.getElementById('eventFbSharePreviewTitle').textContent = currentEventFbShareData.title;
    document.getElementById('eventFbSharePreviewDate').textContent = currentEventFbShareData.date;

    const advancedBtn = document.getElementById('eventFbShareAdvancedBtn');
    const notice = document.getElementById('eventFbShareNotice');
    if (!window.EVENT_FB_APP_ID) {
        advancedBtn.disabled = true;
        advancedBtn.querySelector('i:last-child').className = 'fa-solid fa-lock';
        advancedBtn.querySelector('i:last-child').style.color = '#f39c12';
        notice.style.display = 'flex';
    } else {
        advancedBtn.disabled = false;
        advancedBtn.querySelector('i:last-child').className = 'fa-solid fa-chevron-right';
        advancedBtn.querySelector('i:last-child').style.color = '';
        notice.style.display = 'none';
    }

    modal.classList.add('active');
}

function closeEventFbShareModal() {
    document.getElementById('eventFbShareModal').classList.remove('active');
}

function eventFbShareAdvanced() {
    if (!currentEventFbShareData) return;
    closeEventFbShareModal();
    // Add cache-busting to force Facebook to fetch fresh OG data
    const shareUrl = currentEventFbShareData.url + (currentEventFbShareData.url.includes('?') ? '&' : '?') + '_cb=' + Date.now();
    if (typeof FB !== 'undefined' && window.EVENT_FB_APP_ID) {
        FB.ui({ method: 'share', href: shareUrl, quote: currentEventFbShareData.title }, function(response) {
            if (response && response.error_message) {
                const errMsg = response.error_message.toLowerCase();
                if (errMsg.includes('opted out') || errMsg.includes('platform')) {
                    alert('Facebook Platform Access Required\\n\\nYou have disabled Facebook Platform in your privacy settings.\\n\\nTo fix: Go to Facebook Settings > Apps and Websites > Enable "Apps, websites and games"\\n\\nOr use "Quick Share" instead.');
                } else {
                    alert('Facebook Share Error: ' + response.error_message);
                }
            }
        });
    } else {
        const dialogUrl = 'https://www.facebook.com/dialog/share?app_id=' + encodeURIComponent(window.EVENT_FB_APP_ID) +
            '&href=' + encodeURIComponent(shareUrl) + '&quote=' + encodeURIComponent(currentEventFbShareData.title) +
            '&redirect_uri=' + encodeURIComponent(window.location.href);
        window.open(dialogUrl, 'fb-share-dialog', 'width=626,height=436,scrollbars=yes');
    }
}

function eventFbShareBasic() {
    if (!currentEventFbShareData) return;
    closeEventFbShareModal();
    // Add cache-busting to force Facebook to fetch fresh OG data
    const eventUrl = currentEventFbShareData.url + (currentEventFbShareData.url.includes('?') ? '&' : '?') + '_cb=' + Date.now();
    const shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(eventUrl) +
        '&quote=' + encodeURIComponent(currentEventFbShareData.title);
    window.open(shareUrl, 'fb-share', 'width=600,height=400,scrollbars=yes');
}

function eventFbOpenComposer() {
    if (!currentEventFbShareData) return;
    closeEventFbShareModal();
    let shareText = '📅 ' + currentEventFbShareData.title + '\\n🕒 ' + currentEventFbShareData.date + '\\n\\n';
    if (currentEventFbShareData.cta_url) shareText += '🎟️ Tickets/Info: ' + currentEventFbShareData.cta_url + '\\n';
    shareText += '🔗 ' + currentEventFbShareData.url;
    navigator.clipboard.writeText(shareText).then(() => {
        alert('Event details copied!\\n\\nPaste them anywhere on Facebook.');
        window.open('https://www.facebook.com/', '_blank');
    }).catch(() => {
        prompt('Copy this text to share on Facebook:', shareText);
        window.open('https://www.facebook.com/', '_blank');
    });
}

document.getElementById('eventFbShareModal').addEventListener('click', function(e) { if (e.target === this) closeEventFbShareModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeEventFbShareModal(); });
</script>
HTML;
    }
}
