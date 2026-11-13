<?php
/**
 * GoogleAuth — Settings Panel
 *
 * Configure Google OAuth 2.0 credentials for "Sign in with Google" on the login page.
 * Superadmin only.
 *
 * @package  LuminalCMS
 * @module   GoogleAuth
 * @version  1.0.0
 */
require_once __DIR__ . '/../../auth.php';
requireAuth();
requirePermission('GoogleAuth', 'Google Auth Settings');
?>
<link rel="stylesheet" href="css/google-auth.css">

<div class="ga-container">
    <div class="ga-header">
        <h2>Google Auth Settings</h2>
        <p class="ga-subtitle">Configure Google OAuth 2.0 for passwordless sign-in across this site.</p>
    </div>

    <div id="ga-status" class="ga-status" style="display:none;"></div>

    <!-- Enable toggle -->
    <div class="ga-card">
        <div class="ga-card-header">
            <h3>Status</h3>
            <label class="ga-toggle">
                <input type="checkbox" id="gaEnabled">
                <span class="ga-toggle-slider"></span>
            </label>
        </div>
        <p class="ga-hint">When enabled, a "Sign in with Google" button appears on the login page.</p>
    </div>

    <!-- Google Cloud Credentials -->
    <div class="ga-card">
        <div class="ga-card-header">
            <h3>Google Cloud Credentials</h3>
        </div>
        <p class="ga-hint">Create an OAuth 2.0 Client ID in the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>. Set the application type to "Web application".</p>

        <div class="ga-field">
            <label for="gaClientId">Client ID</label>
            <input type="text" id="gaClientId" placeholder="123456789-abc.apps.googleusercontent.com" spellcheck="false">
        </div>

        <div class="ga-field">
            <label for="gaClientSecret">Client Secret</label>
            <div class="ga-secret-wrap">
                <input type="password" id="gaClientSecret" placeholder="GOCSPX-..." spellcheck="false">
                <button type="button" class="ga-eye-btn" onclick="gaToggleSecret()">Show</button>
            </div>
        </div>

        <div class="ga-field">
            <label>Authorized Redirect URI</label>
            <div class="ga-readonly-field" id="gaRedirectUri">Loading...</div>
            <p class="ga-hint">Copy this URI into your Google Cloud Console under "Authorized redirect URIs".</p>
        </div>
    </div>

    <!-- Access Control -->
    <div class="ga-card">
        <div class="ga-card-header">
            <h3>Access Control</h3>
        </div>

        <div class="ga-field">
            <label for="gaAllowedDomains">Allowed Email Domains <span class="ga-optional">(optional)</span></label>
            <input type="text" id="gaAllowedDomains" placeholder="example.com, company.org">
            <p class="ga-hint">Comma-separated. Leave empty to allow any Google account. Restrict to specific domains for tighter control.</p>
        </div>

        <div class="ga-field">
            <label class="ga-checkbox-label">
                <input type="checkbox" id="gaAutoCreate" checked>
                Auto-create users on first Google sign-in
            </label>
            <p class="ga-hint">When disabled, a user must already exist in UserManager before they can sign in with Google.</p>
        </div>

        <div class="ga-field">
            <label for="gaDefaultRole">Default Role for New Users</label>
            <select id="gaDefaultRole">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
                <option value="superadmin">Superadmin</option>
            </select>
        </div>
    </div>

    <!-- Actions -->
    <div class="ga-actions">
        <button class="ga-btn ga-btn-primary" onclick="gaSave()">Save Settings</button>
        <button class="ga-btn ga-btn-secondary" onclick="gaTest()">Test Configuration</button>
    </div>

    <!-- Admin Session. Values live in site-settings.json
         and are written through the Site Settings API (read-merge-write, so nothing else changes). -->
    <div class="ga-card" id="ga-session-card">
        <div class="ga-card-header"><h3>🔐 Admin Session</h3></div>
        <p class="ga-hint">Two limits, and <b>whichever comes first wins</b>. The idle cutoff is the one that usually ends a session. (Separate from the IP whitelist.)</p>
        <div class="ga-field">
            <label for="gaSessHours">Stay signed in for (total ceiling)</label>
            <select id="gaSessHours">
                <option value="1">1 hour</option><option value="2">2 hours</option><option value="4">4 hours</option>
                <option value="8">8 hours</option><option value="12">12 hours (default)</option><option value="24">24 hours</option>
            </select>
        </div>
        <div class="ga-field">
            <label for="gaSessIdle">Sign out after inactivity (idle cutoff)</label>
            <select id="gaSessIdle">
                <option value="15">15 minutes (default)</option><option value="30">30 minutes</option><option value="60">1 hour</option>
                <option value="120">2 hours</option><option value="240">4 hours</option><option value="480">8 hours</option>
                <option value="1440">24 hours (effectively off)</option>
            </select>
        </div>
        <p class="ga-hint">Raising these leaves an unattended browser signed in for longer. Fine on a machine only you use; think twice on a site with <b>staff</b> accounts.</p>
        <div class="ga-actions" style="margin-top:10px">
            <button class="ga-btn ga-btn-primary" type="button" id="gaSessSave">Save session limits</button>
            <span id="gaSessMsg" class="ga-hint" style="align-self:center"></span>
        </div>
    </div>

    <!-- Setup Guide -->
    <div class="ga-card ga-card-guide">
        <div class="ga-card-header">
            <h3>Setup Guide</h3>
        </div>
        <ol class="ga-guide-steps">
            <li>Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console &rarr; Credentials</a></li>
            <li>Create a new <strong>OAuth 2.0 Client ID</strong> (Web application type)</li>
            <li>Add your site's domain to <strong>Authorized JavaScript origins</strong></li>
            <li>Copy the <strong>Redirect URI</strong> shown above into <strong>Authorized redirect URIs</strong></li>
            <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields above</li>
            <li>Enable the toggle and save</li>
            <li>The login page will now show a "Sign in with Google" button</li>
        </ol>
    </div>
</div>

<script src="js/google-auth.js"></script>
<script>
(function(){
    var API = '/admin/modules/SiteSettings/api.php';
    var h = document.getElementById('gaSessHours'), i = document.getElementById('gaSessIdle'),
        b = document.getElementById('gaSessSave'), m = document.getElementById('gaSessMsg');
    if (!h || !i || !b) return;
    function j(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e){ return {ok:false,error:'HTTP '+r.status}; } }); }
    fetch(API + '?action=load&file=site-settings.json').then(j).then(function(d){
        var s = (d && d.ok && d.data) || {};
        h.value = String(s.admin_session_hours || 12);
        i.value = String(s.admin_idle_minutes || 15);
    });
    b.addEventListener('click', function(){
        b.disabled = true; m.textContent = 'Saving…';
        var f1 = new FormData(); f1.append('action','set_session_hours'); f1.append('hours', h.value);
        fetch(API, {method:'POST', body:f1}).then(j).then(function(r1){
            if (!r1.ok) throw new Error(r1.error || 'hours not saved');
            var f2 = new FormData(); f2.append('action','save'); f2.append('file','site-settings.json');
            f2.append('data', JSON.stringify({admin_idle_minutes: parseInt(i.value,10) || 15}));
            return fetch(API, {method:'POST', body:f2}).then(j);
        }).then(function(r2){
            if (!r2.ok) throw new Error(r2.error || 'idle cutoff not saved');
            m.textContent = '✓ Saved. The total ceiling applies now; the idle cutoff from the next page load.';
        }).catch(function(e){ m.textContent = '✗ ' + e.message; })
          .then(function(){ b.disabled = false; });
    });
})();
</script>
