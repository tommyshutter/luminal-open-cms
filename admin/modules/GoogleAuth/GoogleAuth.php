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
