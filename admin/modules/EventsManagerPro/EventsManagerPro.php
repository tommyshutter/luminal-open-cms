<?php
/**
 * Luminal CMS — Events Manager Pro Module
 *
 * Comprehensive event management system with calendar, recurring events,
 * social media integration (Facebook/Instagram/X), email notifications,
 * and ticket/RSVP handling.
 *
 * @package    LuminalCMS
 * @module     EventsManagerPro
 * @version    1.0.0
 * @file       /admin/modules/EventsManagerPro/EventsManagerPro.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

$moduleBase = sc_asset('/admin/modules/EventsManagerPro');

// Include Luminal admin header
require_once __DIR__ . '/../../admin_header.php';
?>

<h1 class="panel_header_h1">Events Manager Pro</h1>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= $moduleBase ?>/css/events-manager.css?v=<?= time() ?>">

<div class="emp-wrapper">
    <div class="emp-panel">
        <div class="lp-tabs">
            <div class="lp-tab active" onclick="setLeftTab('editor')">EDITOR</div>
            <div class="lp-tab" onclick="setLeftTab('venues')">VENUES</div>
            <div class="lp-tab" onclick="setLeftTab('creds')">ACCOUNTS</div>
            <div class="lp-tab" onclick="setLeftTab('settings')">SETTINGS</div>
        </div>

        <div id="view_editor" class="lp-content active">
            <div style="display:flex;justify-content:space-between;margin-bottom:15px;">
                <h3 style="margin:0;">Event Details</h3>
                <button onclick="clearEventForm()" class="emp-btn emp-btn-sm">+ New Event</button>
            </div>

            <input type="hidden" id="evt_id">
            <input type="hidden" id="evt_remove_image" value="false">
            <input type="hidden" id="imported_image_url">
            <input type="hidden" id="final_image_path">
            <input type="hidden" id="venue_id">

            <div class="emp-mb">
                <label>Venue</label>
                <div class="venue-rack" id="venue_rack"><div style="color:#666;">Loading...</div></div>
                <div class="venue-info-card" id="venue_info_card" style="display:none;">
                    <div class="venue-info-row" id="venue_info_address"><i class="fa-solid fa-location-dot"></i> <span></span></div>
                    <div class="venue-info-row" id="venue_info_phone"><i class="fa-solid fa-phone"></i> <span></span></div>
                    <div class="venue-info-row" id="venue_info_website"><i class="fa-solid fa-globe"></i> <a href="#" target="_blank"></a></div>
                </div>
            </div>

            <div class="emp-mb">
                <label>Event / Band Name</label>
                <input type="text" id="evt_name" placeholder="e.g. The Rolling Stones">
            </div>

            <div class="row-4">
                <div><label>Start Date</label><input type="text" id="evt_start_date" class="flatpickr-date" placeholder="Select date..."></div>
                <div><label>Start Time</label><input type="text" id="evt_start_time" class="flatpickr-time" placeholder="8:00 PM"></div>
                <div><label>End Date</label><input type="text" id="evt_end_date" class="flatpickr-date" placeholder="Optional"></div>
                <div><label>End Time</label><input type="text" id="evt_end_time" class="flatpickr-time" placeholder="Optional"></div>
            </div>

            <div class="row-2">
                <div><label>FB Event Link</label><input type="url" id="evt_fb" placeholder="Facebook event URL"></div>
                <div><label>Map Link</label><input type="url" id="evt_map" placeholder="Google Maps URL"></div>
            </div>

            <div class="emp-mb">
                <label>CTA / Tickets Link <span style="font-size:0.7rem;color:var(--emp-accent);">(included in social posts)</span></label>
                <input type="url" id="evt_cta_url" placeholder="https://yoursite.com/tickets or landing page URL">
                <div style="margin-top:4px;font-size:0.7rem;color:var(--emp-fg-dim);">This link appears in Facebook posts as the call-to-action</div>
            </div>

            <div class="emp-mb">
                <label>SEO Title</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="evt_title" placeholder="Click 'Generate' or enter manually..." style="flex:1;color:var(--emp-accent);">
                    <button type="button" onclick="generateSeoTitle(true)" class="emp-btn emp-btn-sm" style="white-space:nowrap;border-color:var(--emp-accent);color:var(--emp-accent);" title="Generate SEO title from event details">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                    </button>
                </div>
                <div style="margin-top:5px;font-size:0.75rem;color:var(--emp-fg-dim);">Format: BAND NAME - LIVE - DATE - VENUE - CITY ZIP</div>
            </div>

            <div class="emp-mb">
                <label>Poster</label>
                <div class="preview-container" id="preview_container" style="display:none;">
                    <div class="preview-honest"><img id="img_preview" src=""></div>
                    <div class="preview-actions">
                        <button type="button" class="emp-btn emp-btn-sm btn-optimize" onclick="optimizeCurrentImage()" title="Optimize for Social Platforms">
                            <i class="fa-solid fa-compress"></i> Optimize
                        </button>
                        <label style="font-size:0.75rem;margin:0 8px;display:inline-flex;align-items:center;gap:4px;cursor:pointer;" title="Center-crop to 1:1 square ratio">
                            <input type="checkbox" id="crop_square" style="margin:0;"> Square
                        </label>
                        <button onclick="markImageRemove()" class="emp-btn emp-btn-sm" style="color:var(--emp-danger);border-color:var(--emp-danger);">Remove</button>
                    </div>
                </div>
                <div class="upload-box" id="drop_zone">
                    <input type="file" id="evt_image_file" accept="image/*" hidden onchange="uploadFile(this.files[0], 'poster')">
                    <span onclick="document.getElementById('evt_image_file').click()">Click/Drop Poster Here</span>
                    <div class="progress-bar-wrap" id="progress_wrap"><div class="progress-bar-fill" id="progress_fill"></div></div>
                </div>
            </div>

            <div class="emp-mb">
                <label style="color:var(--emp-warning);">YouTube Embed</label>
                <div style="display:flex;gap:5px;">
                    <input type="url" id="evt_yt" placeholder="https://youtube.com/watch?v=...">
                    <button onclick="importYt()" class="emp-btn emp-btn-sm">Resolve</button>
                </div>
                <input type="hidden" id="evt_yt_thumb">
                <div id="yt_preview_div" style="margin-top:5px;color:var(--emp-accent);display:none;">Video Linked</div>
            </div>

            <div class="emp-mb"><label>Description</label><textarea id="evt_content" rows="4"></textarea></div>

            <div class="action-bar">
                <div class="row-2">
                    <button onclick="saveEvent('published')" class="btn-main btn-pub">PUBLISH</button>
                    <button onclick="saveEvent('draft')" class="btn-main btn-draft">DRAFT</button>
                </div>
                <button onclick="saveEvent('archived')" class="btn-main btn-arch" style="margin-top:5px;">ARCHIVE</button>
            </div>
            <div id="status_msg" style="text-align:center;margin-top:5px;"></div>
        </div>

        <div id="view_venues" class="lp-content">
            <h3>Venue Manager</h3>
            <div class="venue-form-box">
                <input type="hidden" id="v_id">
                <input type="hidden" id="v_image_path">
                <div class="row-2">
                    <div><label>Name</label><input type="text" id="v_name"></div>
                    <div><label>Default Time</label><input type="text" id="v_default_time" class="flatpickr-time"></div>
                </div>
                <div class="emp-mb"><label>Address</label><input type="text" id="v_address"></div>
                <div class="row-2">
                    <div><label>Phone</label><input type="text" id="v_phone" placeholder="Optional"></div>
                    <div><label>Website</label><input type="url" id="v_website" placeholder="Optional"></div>
                </div>
                <div class="emp-mb">
                    <label>Logo</label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <img id="v_preview" src="" style="width:50px;height:50px;background:#000;display:none;">
                        <input type="file" id="v_file" style="width:auto;" onchange="uploadFile(this.files[0], 'venue')">
                    </div>
                </div>
                <button onclick="saveVenue()" class="btn-main btn-pub">SAVE VENUE</button>
                <button onclick="clearVenueForm()" class="emp-btn emp-btn-sm" style="margin-top:5px;width:100%;">Clear</button>
            </div>
            <div id="venue_list_manager"></div>
        </div>

        <div id="view_creds" class="lp-content">
            <h3>Platform Credentials</h3>
            <div class="emp-mb">
                <label>FB App ID <span style="font-size:0.75rem;color:var(--emp-fg-dim);">(for Share Dialog)</span></label>
                <input type="text" id="fb_app_id" placeholder="Required for advanced sharing">
                <div style="font-size:0.75rem;color:var(--emp-fg-dim);margin-top:3px;">
                    Get from <a href="https://developers.facebook.com/apps" target="_blank" style="color:var(--emp-accent);">developers.facebook.com</a>
                </div>
            </div>
            <div class="emp-mb">
                <label>FB Page ID <span style="font-size:0.75rem;color:var(--emp-fg-dim);">(for Direct Page Posts)</span></label>
                <input type="text" id="fb_page_id" placeholder="Your Facebook Page numeric ID">
            </div>
            <div class="emp-mb">
                <label>FB Page Token <span style="font-size:0.75rem;color:var(--emp-fg-dim);">(Page Access Token)</span></label>
                <input type="text" id="fb_token" placeholder="Must have pages_manage_posts permission">
                <div style="font-size:0.75rem;color:var(--emp-fg-dim);margin-top:3px;">
                    Required: <code style="background:var(--emp-input-bg);padding:2px 4px;border-radius:3px;">pages_manage_posts</code>, <code style="background:var(--emp-input-bg);padding:2px 4px;border-radius:3px;">pages_read_engagement</code>
                </div>
            </div>
            <div class="emp-mb"><label>IG ID</label><input type="text" id="insta_id"></div>
            <div class="emp-mb"><label>IG Token</label><input type="text" id="insta_token"></div>
            <div class="emp-mb"><label>X API Key</label><input type="text" id="x_api_key"></div>
            <div class="emp-mb"><label>X Secret</label><input type="text" id="x_secret"></div>
            <button onclick="saveCreds()" class="btn-main btn-pub">SAVE CREDENTIALS</button>
        </div>

        <div id="view_settings" class="lp-content">
            <h3>Settings</h3>
            <div class="setting-row" style="flex-direction:column;align-items:flex-start;gap:8px;">
                <label>Default Band / Artist Name</label>
                <input type="text" id="sets_default_band" placeholder="e.g. Tommy Morgan" style="width:100%;">
                <span style="font-size:0.8rem;color:var(--emp-fg-dim);">Pre-fills the Event/Band Name field for new events</span>
            </div>
            <div class="setting-row">
                <label>Grid Cols</label>
                <input type="range" id="sets_cols" min="1" max="4" value="3" oninput="document.getElementById('cols_val').innerText=this.value">
                <span id="cols_val">3</span>
            </div>
            <div class="setting-row"><label>Show Desc</label><input type="checkbox" id="sets_desc"></div>
            <div class="setting-row"><label>Show Venue Link</label><input type="checkbox" id="sets_venue_link"></div>
            <div class="setting-row"><label>Share FB</label><input type="checkbox" id="sets_share_fb"></div>
            <div class="setting-row"><label>Share X</label><input type="checkbox" id="sets_share_x"></div>
            <div class="setting-row"><label>Share Email</label><input type="checkbox" id="sets_share_email"></div>
            <button onclick="saveSettings()" class="btn-main btn-pub">SAVE SETTINGS</button>

            <div style="margin-top:30px;padding-top:20px;border-top:1px solid var(--emp-border);">
                <h3 style="margin-bottom:15px;">Image Optimization</h3>
                <div class="setting-row" style="flex-direction:column;align-items:flex-start;gap:10px;">
                    <label style="margin:0;">Bulk Optimize All Event Images</label>
                    <span style="font-size:0.8rem;color:var(--emp-fg-dim);margin-bottom:10px;">Resize oversized images (&gt;1200px or &gt;900KB) for social sharing. Originals replaced.</span>
                    <button type="button" id="btn_bulk_optimize" class="emp-btn emp-btn-sm btn-optimize" onclick="bulkOptimize()">
                        <i class="fa-solid fa-images"></i> Optimize All Event Images
                    </button>
                    <div id="bulk_optimize_result" style="font-size:0.85rem;color:var(--emp-accent);margin-top:5px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="emp-panel">
        <div class="rp-tabs">
            <div class="rp-tab active" onclick="setRightTab('upcoming')">UPCOMING</div>
            <div class="rp-tab" onclick="setRightTab('drafts')">DRAFTS</div>
            <div class="rp-tab" onclick="setRightTab('archived')">ARCHIVE</div>
            <div class="rp-tab" onclick="setRightTab('trash')">TRASH</div>
        </div>
        <div id="events_list" class="event-list"></div>
    </div>
</div>

<script>
window.EMP_BOOT = {
  endpoints: {
    api: '<?= $moduleBase ?>/api.php',
    emailShare: '<?= $moduleBase ?>/EmailShare.php'
  }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="/admin/shared/upload-progress/upload-progress.css?v=<?= @filemtime(SITE_ROOT . '/admin/shared/upload-progress/upload-progress.css') ?: time() ?>">
<script src="/admin/shared/upload-progress/upload-progress.js?v=<?= @filemtime(SITE_ROOT . '/admin/shared/upload-progress/upload-progress.js') ?: time() ?>"></script>
<script src="<?= $moduleBase ?>/js/events-manager.js?v=<?= time() ?>"></script>

<div id="fb-root"></div>
<script>
window.fbAsyncInit = function() {
    var appId = window.FB_APP_ID || '';
    if (appId) {
        FB.init({ appId: appId, cookie: true, xfbml: true, version: 'v19.0' });
    }
};
(function(d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) return;
    js = d.createElement(s); js.id = id;
    js.src = "https://connect.facebook.net/en_US/sdk.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));
</script>
