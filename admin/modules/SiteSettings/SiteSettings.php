<?php
/**
 * Luminal CMS — Site Settings Module
 *
 * Manage site identity, SEO, typography, logo, landing page settings,
 * footer configuration, and maintenance mode for Luminal CMS sites.
 *
 * @package    LuminalCMS
 * @module     SiteSettings
 * @version    1.0.0
 * @file       /admin/modules/SiteSettings/SiteSettings.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$user = guard_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<!doctype html><html><body style="background:#1a1a2e;color:#f87171;padding:40px;font-family:sans-serif"><h1>Access Denied</h1><p>Admin access required.</p></body></html>';
    exit;
}

// Include Luminal admin header
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Site Settings</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/SiteSettings/css/site-settings.css') ?>">
<link rel="stylesheet" href="/css/custom-fonts.css">

<div class="ss-page">
    <!-- ss-title removed 2026-07-18 — duplicated the admin shell's panel_header_h1 above. -->
    <p class="ss-subtitle">Manage site identity, typography, logo, landing page, footer, and maintenance.</p>

    <div class="ss-cards">

        <section class="ss-card ss-card-large">
            <h2 class="ss-card-heading">Site Identity &amp; SEO</h2>
            <div class="ss-preview-box">
                <div id="live-preview-text">SITE NAME</div>
            </div>
            <div class="ss-form-group">
                <label>Site Name</label>
                <input type="text" id="site_name" class="ss-input ss-input-full">
                <label class="ss-checkbox-label" style="margin-top:6px;font-size:13px">
                    <input type="checkbox" id="show_site_title" checked>
                    Show site name in header
                    <span style="color:var(--ss-text-muted);font-size:12px">(unchecking uses logo only)</span>
                </label>
            </div>
            <div class="ss-form-group">
                <label>Default Page Title</label>
                <input type="text" id="site_title" class="ss-input ss-input-full">
            </div>
            <div class="ss-form-group">
                <label>Meta Description</label>
                <textarea id="meta_description" class="ss-input ss-input-full ss-textarea" rows="2"></textarea>
            </div>
            <div class="ss-form-group">
                <label>Meta Keywords</label>
                <input type="text" id="meta_keywords" class="ss-input ss-input-full">
            </div>
        </section>

        <section class="ss-card ss-card-large" id="seo-optimizer-card">
            <h2 class="ss-card-heading">SEO Optimizer <span class="ss-badge-ai">AI</span></h2>
            <p style="color:var(--ss-text-muted);font-size:13px;margin:0 0 14px">Scan pages for missing or weak meta descriptions, then generate AI suggestions.</p>
            <div class="ss-btn-row" style="margin-bottom:14px">
                <button id="seo-scan-btn" class="ss-btn ss-btn-primary">Scan Pages</button>
                <button id="seo-generate-btn" class="ss-btn ss-btn-secondary" disabled>Generate AI Suggestions</button>
                <button id="seo-apply-all-btn" class="ss-btn ss-btn-secondary" disabled>Apply All</button>
            </div>
            <div id="seo-results" class="seo-results-container" style="display:none"></div>
        </section>

        <section class="ss-card ss-card-large" id="vault-card">
            <h2 class="ss-card-heading">🗄️ The Vault — AI Search <span class="ss-badge-ai">llms.txt</span></h2>
            <p style="color:var(--ss-text-muted);font-size:13px;margin:0 0 14px">Publishes an AI-readable briefing at <code>/llms.txt</code> + <code>/llms-full.txt</code> so ChatGPT, Claude, Perplexity &amp; Google AI understand and recommend this site. Built from your pages/articles + the About below + any connected sources. <strong>Auto-refreshes when your content changes.</strong></p>

            <!-- Vault freshness strip: last-generated + staleness + manual Refresh + auto-refresh opt-in -->
            <div id="vault-freshness" class="ss-form-group" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:10px 12px;background:var(--ss-surface-2,rgba(127,127,127,.08));border-radius:8px;margin-bottom:14px">
                <span id="vault-fresh-pill" style="font-size:12px;font-weight:600;padding:3px 9px;border-radius:99px;background:rgba(127,127,127,.2)">—</span>
                <span id="vault-fresh-meta" style="font-size:12px;color:var(--ss-text-muted)">Checking Vault status…</span>
                <button id="vault-refresh-btn" class="ss-btn ss-btn-secondary" type="button" style="margin-left:auto">🔄 Refresh now</button>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ss-text-muted);cursor:pointer">
                    <input type="checkbox" id="vault-auto-refresh" checked> Auto-refresh on content change
                </label>
            </div>

            <div class="ss-form-group">
                <label class="ss-label">Add Sources</label>
                <div class="ss-btn-row">
                    <button id="vault-import-fb-btn" class="ss-btn ss-btn-secondary" type="button">📘 Import Facebook Profile</button>
                    <span id="vault-fb-status" style="font-size:12px;color:var(--ss-text-muted);align-self:center"></span>
                </div>
                <p style="color:var(--ss-text-muted);font-size:12px;margin:6px 0 0">Pulls About, category, hours, location &amp; phone from the site's Facebook page (uses the Facebook Events connection). Ideal for event/venue sites with little on-page text.</p>
            </div>

            <div class="ss-form-group">
                <label class="ss-label" for="llms_about">About / Overview <span style="font-weight:400;color:var(--ss-text-muted)">— the briefing's lead paragraph</span></label>
                <textarea id="llms_about" class="ss-input ss-input-full ss-textarea" rows="5" placeholder="Who this site/business is, what it offers, key facts. This leads the AI briefing."></textarea>
                <div class="ss-btn-row" style="margin-top:8px">
                    <button id="vault-gen-about-btn" class="ss-btn ss-btn-secondary" type="button">✨ Generate About Text</button>
                    <button id="vault-save-regen-btn" class="ss-btn ss-btn-primary" type="button">💾 Save &amp; Regenerate Vault</button>
                    <span id="vault-status" style="font-size:12px;color:var(--ss-text-muted);align-self:center"></span>
                </div>
            </div>
            <p style="font-size:12px;margin:4px 0 0"><a id="vault-view-link" href="/llms.txt" target="_blank" rel="noopener" style="color:var(--ss-accent,#5b9bff)">View /llms.txt →</a> · <a href="/llms-full.txt" target="_blank" rel="noopener" style="color:var(--ss-accent,#5b9bff)">/llms-full.txt (full briefing) →</a></p>
        </section>

        <section class="ss-card ss-card-small">
            <h2 class="ss-card-heading">Header Font</h2>
            <div class="ss-form-group">
                <label>Font</label>
                <select id="header_font" class="ss-input ss-input-full"></select>
            </div>
            <div class="ss-form-group">
                <label>Size</label>
                <input type="range" id="header_size" class="ss-range" min="0.5" max="5" step="0.1" value="2.6">
                <div class="ss-range-val" id="header_size_val">2.6</div>
            </div>
            <div class="ss-form-group">
                <label>Color</label>
                <input type="color" id="header_color" class="ss-color" value="#00ffff">
            </div>
            <label class="ss-checkbox-label"><input type="checkbox" id="header_bold"> Bold</label>
            <label class="ss-checkbox-label"><input type="checkbox" id="header_italic"> Italic</label>
        </section>

        <section class="ss-card ss-card-small">
            <h2 class="ss-card-heading">Body Font</h2>
            <div class="ss-form-group">
                <label>Font</label>
                <select id="body_font" class="ss-input ss-input-full"></select>
            </div>
            <div class="ss-form-group">
                <label>Size</label>
                <input type="range" id="body_size" class="ss-range" min="0.5" max="3" step="0.1" value="1">
                <div class="ss-range-val" id="body_size_val">1</div>
            </div>
            <div class="ss-form-group">
                <label>Color</label>
                <input type="color" id="body_color" class="ss-color" value="#ffffff">
            </div>
            <div class="ss-preview-small" id="body-preview">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</div>
        </section>

        <section class="ss-card ss-card-medium">
            <h2 class="ss-card-heading">Site Logo</h2>
            <label class="ss-checkbox-label"><input type="checkbox" id="enable_logo" checked> Enable Logo</label>

            <div id="logo-drop-zone" class="ss-drop-zone">
                <div class="ss-logo-bg" id="logo-background"></div>
                <div class="ss-drop-overlay">
                    <p>Drop Logo Here</p>
                    <button type="button" id="choose-btn" class="ss-btn ss-btn-primary ss-btn-sm">Choose File</button>
                </div>
            </div>
            <input type="file" id="logo-file" accept="image/*" style="display:none">

            <div class="ss-form-group">
                <label>Width (px)</label>
                <input type="range" id="logo_width" class="ss-range" min="50" max="500" value="200">
                <div class="ss-range-val" id="logo_width_val">200</div>
            </div>
            <div class="ss-form-group">
                <label>Margin (rem)</label>
                <input type="range" id="logo_margin" class="ss-range" min="0" max="5" step="0.1" value="0">
                <div class="ss-range-val" id="logo_margin_val">0</div>
            </div>

            <button id="gen-og-btn" class="ss-btn ss-btn-secondary" style="width:100%">Generate OG Images</button>

            <label class="ss-checkbox-label" style="margin-top:10px"><input type="checkbox" id="full_width"> Full Width Header</label>
        </section>

        <section class="ss-card ss-card-tiny">
            <h2 class="ss-card-heading">Landing Page</h2>
            <div class="ss-form-group">
                <label>Select Page</label>
                <select id="landing_page" class="ss-input ss-input-full">
                    <option value="">Loading...</option>
                </select>
            </div>
            <div class="ss-form-group">
                <label>Header Gap (px)</label>
                <input type="range" id="content_gap" class="ss-range" min="0" max="200" value="0">
                <div class="ss-range-val" id="content_gap_val">0</div>
            </div>
        </section>

        <section class="ss-card ss-card-large">
            <h2 class="ss-card-heading">Footer Settings</h2>
            <div class="ss-form-group">
                <label>Footer Heading <span class="ss-hint">(business name shown above the contact details)</span></label>
                <input type="text" id="footer_title" class="ss-input ss-input-full" placeholder="Your Site Name">
            </div>
            <div class="ss-form-group">
                <label>Contact Email</label>
                <input type="email" id="footer_contact_email" class="ss-input ss-input-full" placeholder="contact@example.com">
            </div>
            <div class="ss-form-group">
                <label>Phone</label>
                <input type="text" id="footer_phone" class="ss-input ss-input-full" placeholder="+1 (555) 123-4567">
            </div>
            <div class="ss-form-group">
                <label>Address</label>
                <textarea id="footer_address" class="ss-input ss-input-full ss-textarea" rows="2" placeholder="123 Main St, City, State ZIP"></textarea>
            </div>
            <div class="ss-form-group">
                <label>Credits / Copyright</label>
                <textarea id="footer_credits" class="ss-input ss-input-full ss-textarea" rows="2" placeholder="&copy; 2026 Site Name. All rights reserved."></textarea>
            </div>
            <div class="ss-form-group">
                <label>Tagline <span class="ss-hint">(one line under the copyright)</span></label>
                <input type="text" id="footer_tagline" class="ss-input ss-input-full" placeholder="Voted Sebastian's Best Music Venue!">
            </div>

            <hr class="ss-divider">

            <label>Social Links</label>
            <p class="ss-hint ss-hint-block">Type the platform (Facebook, Instagram, YouTube, <b>TikTok</b>, X, Email) and its URL. The icon column defaults to <b>Auto</b>, which draws the matching icon that ships with every site &mdash; pick a specific file to override it, or <b>Text only</b> to show the name instead.</p>
            <div id="social-links-container"></div>
            <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm ss-btn-wide" onclick="SS.addSocialLink()">+ Add Social Link</button>

            <hr class="ss-divider">

            <label>Affiliate / Sponsor Links</label>
            <p class="ss-hint ss-hint-block">A logo image is optional &mdash; without one the label is shown as text. Tick <b>Mono</b> to flatten a wordmark to white; leave it off for colour artwork.</p>
            <div id="affiliate-links-container"></div>
            <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm ss-btn-wide" onclick="SS.addAffiliateLink()">+ Add Affiliate Link</button>

            <hr class="ss-divider">

            <label>Legal Links</label>
            <p class="ss-hint ss-hint-block">The small print row at the very bottom &mdash; Privacy Policy, Terms, Accessibility.</p>
            <div id="legal-links-container"></div>
            <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm ss-btn-wide" onclick="SS.addLegalLink()">+ Add Legal Link</button>

            <hr class="ss-divider">

            <div class="ss-form-group">
                <label>Custom Footer HTML <span class="ss-hint">(raw HTML, rendered at the bottom of the footer — badges, embeds, custom markup)</span></label>
                <textarea id="footer_custom_html" class="ss-input ss-input-full ss-textarea ss-mono" rows="6" spellcheck="false" placeholder="&lt;div class=&quot;footer-note&quot;&gt;Your custom footer markup&lt;/div&gt;"></textarea>
                <p class="ss-hint ss-hint-block">This field is <b>stored data, not a template</b> &mdash; a <code>&lt;?php &hellip; ?&gt;</code> block in here cannot run and is stripped when the page renders. Prefer the structured fields above; they are what the icon row, the sponsor row and the legal row are built from.</p>
            </div>

            <!-- Save readback: proves what actually landed on disk, so a failed
                 write can never look like a successful save again. -->
            <div id="footer-save-readback" class="ss-readback" hidden></div>
        </section>

        <!-- Consolidated utility panel — Admin Session · Floating Video · Dashboard Stats · Maintenance
             (merged from four small cards 2026-07-18; every field ID preserved so the JS is unchanged). -->
        <section class="ss-card ss-card-large">
            <h2 class="ss-card-heading">⚙️ System &amp; Utilities</h2>

            <h3 class="ss-subhead">🔐 Admin Session</h3>
            <p style="color:var(--ss-text-muted);font-size:12px;margin:0 0 10px">Two limits, and <b>whichever comes first wins</b>. The idle cutoff is the one that usually ends your session — it was previously fixed at 15 min and silently overrode the total below. (Separate from the IP whitelist.)</p>
            <div class="ss-form-group">
                <label>Stay signed in for <span style="font-weight:400;opacity:.7">— total ceiling</span></label>
                <select id="admin_session_hours" class="ss-input ss-input-full">
                    <option value="1">1 hour</option>
                    <option value="2">2 hours</option>
                    <option value="4">4 hours</option>
                    <option value="8">8 hours</option>
                    <option value="12">12 hours (default)</option>
                    <option value="24">24 hours</option>
                </select>
            </div>
            <div class="ss-form-group">
                <label>Sign out after inactivity <span style="font-weight:400;opacity:.7">— idle cutoff</span></label>
                <select id="admin_idle_minutes" class="ss-input ss-input-full">
                    <option value="15">15 minutes (default)</option>
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="480">8 hours</option>
                    <option value="1440">24 hours (effectively off)</option>
                </select>
                <p style="color:var(--ss-text-muted);font-size:12px;margin:6px 0 0">Raising this leaves an unattended browser signed in for longer. Fine on a machine only you use — think twice on a site with <b>staff</b> accounts.</p>
            </div>

            <hr class="ss-divider">

            <h3 class="ss-subhead">Floating Video Player</h3>
            <label class="ss-checkbox-label"><input type="checkbox" id="vf_enabled" checked> Enable Floating Player</label>
            <div class="ss-form-group" style="margin-top:10px">
                <label>Player Mode</label>
                <select id="vf_mode" class="ss-input ss-input-full">
                    <option value="both">Float + PiP (Both)</option>
                    <option value="float">Float Only (In-Browser)</option>
                    <option value="pip">PiP Only (OS Window)</option>
                </select>
            </div>

            <hr class="ss-divider">

            <h3 class="ss-subhead">Dashboard Stats &amp; Logs</h3>
            <p style="color:var(--ss-text-muted);font-size:13px;margin:0 0 14px">Configure web log path for traffic stats. Leave blank to use auto-detection.</p>
            <div class="ss-form-group">
                <label>Custom Log Path <span style="color:var(--ss-text-muted);font-weight:400;font-size:11px">(glob pattern, {domain} placeholder)</span></label>
                <input type="text" id="stats-log-path" class="ss-input ss-input-full"
                    placeholder="e.g. /home/myuser/logs/{domain}* or /var/log/vhosts/{domain}/*.access.log"
                    style="font-family:var(--ss-mono,'Courier New',monospace);font-size:12px">
            </div>
            <div class="ss-form-group" id="stats-cpanel-group" style="display:none">
                <label>cPanel Username <span style="color:var(--ss-text-muted);font-weight:400;font-size:11px">(for cPanel log path auto-detection)</span></label>
                <input type="text" id="stats-cpanel-user" class="ss-input" style="max-width:220px"
                    placeholder="e.g. myuser">
            </div>
            <div id="stats-detected" style="font-size:11px;color:var(--ss-text-muted);margin-bottom:10px;font-family:var(--ss-mono,'Courier New',monospace);display:none"></div>
            <div class="ss-btn-row">
                <button id="stats-save-btn" class="ss-btn ss-btn-primary">Save &amp; Reparse</button>
                <button id="stats-clear-cache-btn" class="ss-btn ss-btn-secondary">Clear Cache</button>
            </div>
            <pre class="ss-output" id="stats-output-box"></pre>

            <hr class="ss-divider">

            <h3 class="ss-subhead">Maintenance</h3>
            <div class="ss-btn-row">
                <button id="fix-perms-btn" class="ss-btn ss-btn-secondary">Fix Folder Permissions</button>
                <button id="recreate-btn" class="ss-btn ss-btn-secondary">Recreate Media Paths</button>
            </div>
            <pre class="ss-output" id="output-box"></pre>
        </section>

    </div><!-- /ss-cards -->

    <section class="ss-card ss-card-large" id="ss-age-gate-card">
        <h2 class="ss-card-heading">Age Verification Gate</h2>
        <p class="ss-card-sub" style="color:rgba(255,255,255,.55);margin:-4px 0 14px;font-size:.85rem;">Site-wide blocking overlay shown on page load until the visitor confirms their age (self-attestation). Remembered per visitor so it doesn't re-prompt every page — for hemp/cannabis/adult sites.</p>
        <label class="ss-toggle-label" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <input type="checkbox" id="ag_enabled"> <span>Enable site-wide age gate</span>
        </label>
        <div class="ss-field" style="max-width:140px;"><label for="ag_threshold">Minimum age</label><input type="number" id="ag_threshold" min="13" max="99" placeholder="21"></div>
        <div class="ss-field"><label for="ag_title">Title</label><input type="text" id="ag_title" placeholder="Age Verification"></div>
        <div class="ss-field"><label for="ag_prompt">Prompt</label><input type="text" id="ag_prompt" placeholder="You must be 21 or older to enter this site."></div>
        <div class="ss-field"><label for="ag_confirm">Confirm button text</label><input type="text" id="ag_confirm" placeholder="I am 21 or older"></div>
        <div class="ss-field"><label for="ag_deny">Deny button text</label><input type="text" id="ag_deny" placeholder="I am under 21"></div>
        <div class="ss-field"><label for="ag_deny_url">On deny — redirect URL</label><input type="text" id="ag_deny_url" placeholder="https://www.google.com (blank = show message instead)"></div>
        <div class="ss-field"><label for="ag_deny_msg">On deny — block message</label><input type="text" id="ag_deny_msg" placeholder="You must be 21 or older to enter this site."></div>
        <div class="ss-field" style="max-width:180px;"><label for="ag_remember">Remember (days)</label><input type="number" id="ag_remember" min="1" max="365" placeholder="30"></div>
        <div class="ss-field" style="max-width:240px;"><label for="ag_idle">Re-challenge after idle (minutes)</label><input type="number" id="ag_idle" min="0" max="240" placeholder="0 = off (15 recommended)"></div>
        <label class="ss-toggle-label" style="display:flex;align-items:center;gap:8px;margin:4px 0 14px;">
            <input type="checkbox" id="ag_audit"> <span>Keep a safety-audit log of gate decisions <span style="color:rgba(255,255,255,.45);font-size:.82rem;">(accepted / denied / idle re-lock — rotated after 3 days)</span></span>
        </label>
        <button id="ag-save-btn" class="ss-btn ss-btn-primary">Save Age Gate</button>
        <span id="ag-save-status" style="margin-left:10px;font-size:.85rem;color:rgba(255,255,255,.6);"></span>
    </section>

    <div class="ss-save-bar">
        <button id="save-all-btn" class="ss-btn ss-btn-primary ss-btn-lg">Save All Settings</button>
        <button id="open-site-btn" class="ss-btn ss-btn-secondary ss-btn-lg">Open Site</button>
    </div>
</div>

<div id="ss-toasts" class="ss-toasts"></div>

<script>
window.SS_BOOT = {
    endpoints: {
        api: '<?= str_replace("'", "\\'", sc_asset('/admin/modules/SiteSettings/api.php', false)) ?>'
    }
};
</script>
<script src="<?= sc_asset('/admin/modules/SiteSettings/js/site-settings.js') ?>" defer></script>
<script>
/* Age Verification Gate — self-contained load/save (merges age_gate into site-settings.json). */
(function () {
    var API = (window.SS_BOOT && window.SS_BOOT.endpoints && window.SS_BOOT.endpoints.api) || '';
    var $ = function (id) { return document.getElementById(id); };
    if (!$('ag-save-btn')) return;

    fetch(API + (API.indexOf('?') > -1 ? '&' : '?') + 'action=load&file=site-settings.json', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var ag = (j && j.data && j.data.age_gate) || {};
            $('ag_enabled').checked = !!ag.enabled;
            $('ag_threshold').value = ag.threshold || '';
            $('ag_title').value     = ag.title || '';
            $('ag_prompt').value    = ag.prompt || '';
            $('ag_confirm').value   = ag.confirm_text || '';
            $('ag_deny').value      = ag.deny_text || '';
            $('ag_deny_url').value  = ag.deny_url || '';
            $('ag_deny_msg').value  = ag.deny_message || '';
            $('ag_remember').value  = ag.remember_days || '';
            $('ag_idle').value      = ag.idle_minutes || '';
            $('ag_audit').checked   = !!ag.audit;
        }).catch(function () {});

    $('ag-save-btn').addEventListener('click', function () {
        var btn = $('ag-save-btn'), st = $('ag-save-status');
        btn.disabled = true; st.textContent = 'Saving…';
        var age_gate = {
            enabled: $('ag_enabled').checked,
            threshold: parseInt($('ag_threshold').value, 10) || 21,
            title: $('ag_title').value.trim(),
            prompt: $('ag_prompt').value.trim(),
            confirm_text: $('ag_confirm').value.trim(),
            deny_text: $('ag_deny').value.trim(),
            deny_url: $('ag_deny_url').value.trim(),
            deny_message: $('ag_deny_msg').value.trim(),
            remember_days: parseInt($('ag_remember').value, 10) || 30,
            idle_minutes: parseInt($('ag_idle').value, 10) || 0,
            audit: $('ag_audit').checked
        };
        var fd = new FormData();
        fd.append('action', 'save');
        fd.append('file', 'site-settings.json');
        fd.append('data', JSON.stringify({ age_gate: age_gate }));
        fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { btn.disabled = false; st.textContent = (j && j.ok) ? 'Saved ✓' : ('Failed: ' + ((j && j.error) || '?')); setTimeout(function () { st.textContent = ''; }, 2500); })
            .catch(function () { btn.disabled = false; st.textContent = 'Save failed'; });
    });
})();
</script>
