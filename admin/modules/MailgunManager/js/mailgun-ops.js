/**
 * Mailgun Manager — Frontend JS
 * Module version for Luminal CMS module system
 */
const mailgunMgr = (() => {
    const API = '/admin/modules/MailgunManager/api.php';

    async function apiGet(action) {
        const res = await fetch(`${API}?action=${action}`);
        const data = await res.json();
        if (!res.ok && data?.error) throw new Error(data.error);
        return data;
    }

    async function apiPost(action, body = {}) {
        const res = await fetch(`${API}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!res.ok && data?.error) throw new Error(data.error);
        return data;
    }

    function toast(msg, type = 'success') {
        let el = document.getElementById('mg-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mg-toast';
            el.className = 'mg-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.className = `mg-toast ${type} show`;
        clearTimeout(el._tid);
        el._tid = setTimeout(() => el.classList.remove('show'), 5000);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    /* ---- Load ---- */

    async function load() {
        try {
            const data = await apiGet('load');
            if (!data.ok) return;
            const cfg = data.config;
            document.getElementById('mg-api-key').value = cfg.api_key || '';
            document.getElementById('mg-domain').value = cfg.domain || '';
            document.getElementById('mg-from-name').value = cfg.from_name || '';
            document.getElementById('mg-from-email').value = cfg.from_email || '';
            document.getElementById('mg-region').value = cfg.region || 'us';

            // Update integration status based on whether config exists
            const hasConfig = cfg.api_key && cfg.domain;
            updateIntegrationStatus(hasConfig);
        } catch (e) {
            console.error('Mailgun load error:', e);
        }
    }

    function updateIntegrationStatus(hasConfig) {
        const items = ['mg-int-events', 'mg-int-password', 'mg-int-contact'];
        items.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            if (hasConfig) {
                el.textContent = 'Pending';
                el.className = 'mg-integration-status mg-status-pending';
            } else {
                el.textContent = 'No Config';
                el.className = 'mg-integration-status mg-status-error';
            }
        });
    }

    /* ---- Save ---- */

    async function save() {
        const cfg = {
            api_key:    document.getElementById('mg-api-key').value.trim(),
            domain:     document.getElementById('mg-domain').value.trim(),
            from_name:  document.getElementById('mg-from-name').value.trim(),
            from_email: document.getElementById('mg-from-email').value.trim(),
            region:     document.getElementById('mg-region').value,
        };
        try {
            const data = await apiPost('save', cfg);
            if (data.ok) {
                toast('Credentials saved');
                updateIntegrationStatus(cfg.api_key && cfg.domain);
            } else {
                toast(data.error || 'Save failed', 'error');
            }
        } catch (e) {
            toast('Save failed: ' + e.message, 'error');
        }
    }

    /* ---- Test Connection ---- */

    async function test() {
        const statusEl = document.getElementById('mg-status');
        statusEl.textContent = 'Testing connection...';
        statusEl.style.color = '#888';
        try {
            const data = await apiGet('test');
            if (data.ok) {
                statusEl.innerHTML = `<span style="color:#4ade80">Connected!</span> Domain: <strong>${esc(data.domain)}</strong> &mdash; State: <strong>${esc(data.state)}</strong>`;
                statusEl.style.color = '#ccc';
            } else {
                statusEl.textContent = 'Failed: ' + (data.error || 'Unknown error');
                statusEl.style.color = '#f87171';
            }
        } catch (e) {
            statusEl.textContent = 'Error: ' + e.message;
            statusEl.style.color = '#f87171';
        }
    }

    /* ---- Send Test Email ---- */

    async function sendTest() {
        const to = document.getElementById('mg-test-to').value.trim();
        const statusEl = document.getElementById('mg-test-status');
        if (!to) {
            toast('Enter a recipient email', 'error');
            return;
        }
        statusEl.textContent = 'Sending...';
        statusEl.style.color = '#888';
        try {
            const data = await apiPost('send_test', { to });
            if (data.ok) {
                statusEl.innerHTML = `<span style="color:#4ade80">Sent!</span> ${esc(data.message)}`;
                statusEl.style.color = '#ccc';
                toast('Test email sent!');
            } else {
                statusEl.textContent = 'Failed: ' + (data.error || 'Unknown');
                statusEl.style.color = '#f87171';
            }
        } catch (e) {
            statusEl.textContent = 'Error: ' + e.message;
            statusEl.style.color = '#f87171';
        }
    }

    /* ---- DNS Check ---- */

    async function checkDns() {
        const el = document.getElementById('mg-dns-results');
        el.innerHTML = '<div class="mg-dns-empty">Checking DNS records...</div>';
        try {
            const data = await apiGet('check_dns');
            if (!data.ok) {
                el.innerHTML = `<div class="mg-dns-empty" style="color:#f87171">${esc(data.error)}</div>`;
                return;
            }

            let html = `<div style="padding:8px 10px;font-size:.82rem;color:#ccc;">Domain: <strong>${esc(data.domain)}</strong> &mdash; State: <strong class="${data.state === 'active' ? 'mg-dns-ok' : 'mg-dns-fail'}">${esc(data.state)}</strong></div>`;

            const allRecords = [...(data.sending || []), ...(data.receiving || [])];
            if (allRecords.length === 0) {
                html += '<div class="mg-dns-empty">No DNS records returned.</div>';
            } else {
                for (const r of allRecords) {
                    const valid = r.valid === 'valid' || r.valid === true;
                    const icon = valid ? '<span class="mg-dns-ok">&#10003;</span>' : '<span class="mg-dns-fail">&#10007;</span>';
                    html += `<div class="mg-dns-record">`;
                    html += `<span class="mg-dns-type">${esc(r.record_type || r.type || '?')}</span>`;
                    html += `<span class="mg-dns-name">${icon} ${esc(r.name || '?')}</span>`;
                    html += `<span class="mg-dns-value">${esc(r.value || '?')}</span>`;
                    html += `</div>`;
                }
            }
            el.innerHTML = html;
        } catch (e) {
            el.innerHTML = `<div class="mg-dns-empty" style="color:#f87171">Error: ${esc(e.message)}</div>`;
        }
    }

    /* ---- Toggle API Key Visibility ---- */

    function toggleKey() {
        const input = document.getElementById('mg-api-key');
        const btn = document.getElementById('mg-toggle-key');
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'Hide';
        } else {
            input.type = 'password';
            btn.textContent = 'Show';
        }
    }

    /* ---- Init ---- */
    document.addEventListener('DOMContentLoaded', load);

    return { load, save, test, sendTest, checkDns, toggleKey };
})();
