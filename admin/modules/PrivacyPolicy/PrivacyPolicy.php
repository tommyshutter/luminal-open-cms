<?php
/**
 * PrivacyPolicy — Admin Panel
 * Luminal CMS Module
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();
guard_require_role('admin');

$page_title = 'Privacy Policy';
require_once SITE_ROOT . '/admin/admin_header.php';
?>
<style>
.pp-wrap { max-width: 860px; }
.pp-tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid var(--border-color, #333); padding-bottom: 0; }
.pp-tab { padding: 8px 18px; border-radius: 6px 6px 0 0; font-size: 0.82rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; border-bottom: none; color: var(--text-muted, #888); background: none; transition: all 0.15s; margin-bottom: -1px; }
.pp-tab.active { background: var(--panel-bg, #1e1e1e); border-color: var(--border-color, #333); color: var(--text-color, #eee); }
.pp-panel { display: none; }
.pp-panel.active { display: block; }
.flag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.flag-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--input-bg, #252525); border: 1px solid var(--border-color, #333); border-radius: 8px; cursor: pointer; }
.flag-item input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--accent-color, #3b82f6); }
.flag-label { font-size: 0.82rem; color: var(--text-color, #eee); }
.preview-frame { width: 100%; height: 500px; border: 1px solid var(--border-color, #333); border-radius: 8px; background: #fff; }
.pub-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 6px; color: #60a5fa; font-size: 0.78rem; text-decoration: none; }
.pub-link:hover { background: rgba(59,130,246,0.2); }
.saved-badge { display: none; align-items: center; gap: 6px; color: #4ade80; font-size: 0.8rem; }
</style>

<div class="admin-content pp-wrap">
    <div class="admin-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <div>
            <h1 class="admin-page-title">Privacy Policy</h1>
            <p class="admin-page-subtitle" style="font-size:0.82rem;color:var(--text-muted,#888);margin-top:4px">
                Editable policy with configurable fields. Published at
                <a href="/privacy-policy" target="_blank" class="pub-link" style="margin-left:4px">
                    /privacy-policy &#x2197;
                </a>
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <span id="savedBadge" class="saved-badge">&#x2714; Saved</span>
            <button class="btn btn-primary" onclick="savePolicy()">Save Policy</button>
        </div>
    </div>

    <div class="pp-tabs">
        <button class="pp-tab active" onclick="switchTab('info', this)">Site Info</button>
        <button class="pp-tab" onclick="switchTab('config', this)">Policy Config</button>
        <button class="pp-tab" onclick="switchTab('flags', this)">Feature Flags</button>
        <button class="pp-tab" onclick="switchTab('custom', this)">Custom HTML</button>
        <button class="pp-tab" onclick="switchTab('preview', this)">Preview</button>
    </div>

    <!-- Site Info -->
    <div id="tab-info" class="pp-panel active">
        <div class="admin-panel">
            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label class="form-label">Website / Business Name</label>
                    <input type="text" id="website_name" class="form-control" placeholder="My Site">
                </div>
                <div class="form-group">
                    <label class="form-label">Website URL</label>
                    <input type="text" id="website_url" class="form-control" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Legal Company Name</label>
                    <input type="text" id="company_name" class="form-control" placeholder="My Company LLC">
                </div>
                <div class="form-group">
                    <label class="form-label">Contact Email</label>
                    <input type="email" id="contact_email" class="form-control" placeholder="privacy@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Contact Phone <span style="color:var(--text-muted,#888);font-weight:400">(optional)</span></label>
                    <input type="text" id="contact_phone" class="form-control" placeholder="+1 555-000-0000">
                </div>
                <div class="form-group">
                    <label class="form-label">Mailing Address <span style="color:var(--text-muted,#888);font-weight:400">(optional)</span></label>
                    <input type="text" id="company_address" class="form-control" placeholder="123 Main St, City, ST 00000">
                </div>
                <div class="form-group">
                    <label class="form-label">State / Province</label>
                    <input type="text" id="state_province" class="form-control" placeholder="Florida">
                </div>
                <div class="form-group">
                    <label class="form-label">Jurisdiction / Country</label>
                    <input type="text" id="jurisdiction" class="form-control" placeholder="United States">
                </div>
            </div>
        </div>
    </div>

    <!-- Policy Config -->
    <div id="tab-config" class="pp-panel">
        <div class="admin-panel">
            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label class="form-label">Effective Date</label>
                    <input type="text" id="effective_date" class="form-control" placeholder="January 1, 2025">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Updated</label>
                    <input type="text" id="last_updated" class="form-control" placeholder="Auto-set on save">
                    <small style="color:var(--text-muted,#888)">Updated automatically every time you save.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Data Retention Period</label>
                    <input type="text" id="data_retention_period" class="form-control" placeholder="2 years">
                </div>
                <div class="form-group">
                    <label class="form-label">Minimum Age</label>
                    <input type="text" id="minimum_age" class="form-control" placeholder="13">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Services Description</label>
                    <input type="text" id="services_description" class="form-control" placeholder="website and related services">
                    <small style="color:var(--text-muted,#888)">Used in intro: "when you visit ... and use our [services_description]"</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Feature Flags -->
    <div id="tab-flags" class="pp-panel">
        <div class="admin-panel">
            <p style="font-size:0.82rem;color:var(--text-muted,#888);margin-bottom:16px">
                Enable the sections that apply to your site. Disabled sections are hidden from the public policy.
            </p>
            <div class="flag-grid">
                <label class="flag-item">
                    <input type="checkbox" id="uses_cookies">
                    <span class="flag-label">Uses Cookies &amp; Tracking</span>
                </label>
                <label class="flag-item">
                    <input type="checkbox" id="uses_google_analytics">
                    <span class="flag-label">Uses Google Analytics</span>
                </label>
                <label class="flag-item">
                    <input type="checkbox" id="uses_payment_processing">
                    <span class="flag-label">Processes Payments</span>
                </label>
                <label class="flag-item">
                    <input type="checkbox" id="uses_email_marketing">
                    <span class="flag-label">Email Marketing</span>
                </label>
                <label class="flag-item">
                    <input type="checkbox" id="uses_social_media">
                    <span class="flag-label">Social Media Links</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Custom HTML Override -->
    <div id="tab-custom" class="pp-panel">
        <div class="admin-panel">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
                <div>
                    <h3 style="font-size:0.95rem;font-weight:600;margin:0">Custom HTML Override</h3>
                    <p style="font-size:0.78rem;color:var(--text-muted,#888);margin:4px 0 0">
                        If set, replaces the template entirely. Leave blank to use the auto-generated policy.
                    </p>
                </div>
                <button class="btn btn-sm btn-secondary" onclick="clearCustomHtml()" style="font-size:0.75rem">Clear Override</button>
            </div>
            <textarea id="custom_html" class="form-control" rows="20"
                style="font-family:monospace;font-size:0.82rem;resize:vertical"
                placeholder="Paste full HTML here to override the auto-generated policy. Leave blank to use the template."></textarea>
        </div>
    </div>

    <!-- Preview -->
    <div id="tab-preview" class="pp-panel">
        <div class="admin-panel" style="padding:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <span style="font-size:0.82rem;color:var(--text-muted,#888)">Preview renders saved policy.</span>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-sm btn-secondary" onclick="refreshPreview()">Refresh</button>
                    <a href="/privacy-policy" target="_blank" class="btn btn-sm btn-primary">Open Live Page &#x2197;</a>
                </div>
            </div>
            <iframe id="previewFrame" class="preview-frame" src="/privacy-policy?preview=1"></iframe>
        </div>
    </div>
</div>

<script>
const fields = ['website_name','website_url','company_name','contact_email','contact_phone',
                 'company_address','state_province','jurisdiction','effective_date','last_updated',
                 'data_retention_period','minimum_age','services_description','custom_html'];
const flags  = ['uses_cookies','uses_google_analytics','uses_payment_processing','uses_email_marketing','uses_social_media'];

// Load on init
fetch('api.php?action=load').then(r=>r.json()).then(({data})=>{
    fields.forEach(k => { const el = document.getElementById(k); if(el && data[k]!==undefined) el.value = data[k]||''; });
    flags.forEach(k  => { const el = document.getElementById(k); if(el && data[k]!==undefined) el.checked = !!data[k]; });
});

function switchTab(name, btn) {
    document.querySelectorAll('.pp-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.pp-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    if (name === 'preview') refreshPreview();
}

function refreshPreview() {
    const f = document.getElementById('previewFrame');
    f.src = '/privacy-policy?preview=1&t=' + Date.now();
}

function clearCustomHtml() {
    document.getElementById('custom_html').value = '';
}

function savePolicy() {
    const data = {};
    fields.forEach(k => { const el = document.getElementById(k); if(el) data[k] = el.value; });
    flags.forEach(k  => { const el = document.getElementById(k); if(el) data[k] = el.checked; });

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('data', JSON.stringify(data));

    fetch('api.php', { method:'POST', body: fd })
        .then(r=>r.json())
        .then(res => {
            if (res.ok) {
                const badge = document.getElementById('savedBadge');
                badge.style.display = 'flex';
                if (res.last_updated) document.getElementById('last_updated').value = res.last_updated;
                setTimeout(()=>{ badge.style.display='none'; }, 3000);
            } else {
                alert('Save failed: ' + (res.error||'unknown'));
            }
        });
}
</script>

<?php require_once SITE_ROOT . '/admin/partials/admin_footer.php'; ?>
