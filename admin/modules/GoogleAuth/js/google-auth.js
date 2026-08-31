/**
 * GoogleAuth — Settings Panel JS
 * @module GoogleAuth
 */
(function() {
    'use strict';

    const API = '/admin/modules/GoogleAuth/api.php';

    function $(id) { return document.getElementById(id); }

    function showStatus(msg, type) {
        const el = $('ga-status');
        el.textContent = msg;
        el.className = 'ga-status ga-status-' + type;
        el.style.display = 'block';
        if (type === 'success') {
            setTimeout(() => { el.style.display = 'none'; }, 4000);
        }
    }

    // Load config on init
    function gaLoad() {
        fetch(API + '?action=get_config')
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { showStatus(data.error || 'Failed to load config', 'error'); return; }
                const c = data.config;
                $('gaEnabled').checked = !!c.enabled;
                $('gaClientId').value = c.client_id || '';
                $('gaClientSecret').value = c.client_secret_masked || c.client_secret || '';
                $('gaRedirectUri').textContent = c.redirect_uri || '';
                $('gaAllowedDomains').value = (c.allowed_domains || []).join(', ');
                $('gaAutoCreate').checked = c.auto_create_users !== false;
                $('gaDefaultRole').value = c.default_role || 'staff';
            })
            .catch(err => showStatus('Network error: ' + err.message, 'error'));
    }

    // Save config
    window.gaSave = function() {
        const domains = $('gaAllowedDomains').value
            .split(',')
            .map(d => d.trim())
            .filter(d => d.length > 0);

        const payload = {
            enabled: $('gaEnabled').checked,
            client_id: $('gaClientId').value.trim(),
            client_secret: $('gaClientSecret').value.trim(),
            allowed_domains: domains,
            auto_create_users: $('gaAutoCreate').checked,
            default_role: $('gaDefaultRole').value,
        };

        fetch(API + '?action=save_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                showStatus('Settings saved.', 'success');
                // Update masked secret display
                if (data.config && data.config.client_secret_masked) {
                    $('gaClientSecret').value = data.config.client_secret_masked;
                }
            } else {
                showStatus(data.error || 'Save failed', 'error');
            }
        })
        .catch(err => showStatus('Network error: ' + err.message, 'error'));
    };

    // Test config
    window.gaTest = function() {
        showStatus('Testing configuration...', 'info');
        fetch(API + '?action=test_config')
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    showStatus('Configuration looks good! Redirect URI: ' + data.redirect_uri, 'success');
                } else {
                    showStatus('Issues found: ' + (data.issues || []).join('; '), 'error');
                }
            })
            .catch(err => showStatus('Network error: ' + err.message, 'error'));
    };

    // Toggle secret visibility
    window.gaToggleSecret = function() {
        const inp = $('gaClientSecret');
        const btn = inp.nextElementSibling;
        if (inp.type === 'password') {
            inp.type = 'text';
            btn.textContent = 'Hide';
        } else {
            inp.type = 'password';
            btn.textContent = 'Show';
        }
    };

    // Init
    gaLoad();
})();
