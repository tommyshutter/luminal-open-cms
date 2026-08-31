/**
 * PaymentProviders — Admin JS
 */
window.PP = (() => {
    const API = PP_BOOT.apiUrl;
    let state = { gateways: [], defaultGateway: null, currency: 'USD' };
    let editingGateway = null;

    async function api(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        for (const [k, v] of Object.entries(data)) fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
        const r = await fetch(API, { method: 'POST', body: fd });
        return r.json();
    }

    function toast(msg, type = 'info') {
        const el = document.getElementById('pp-toast');
        el.textContent = msg;
        el.className = 'pp-toast show ' + type;
        setTimeout(() => el.classList.remove('show'), 3000);
    }

    function render() {
        const grid = document.getElementById('pp-gateway-grid');
        const currencyEl = document.getElementById('pp-currency');

        if (currencyEl) currencyEl.value = state.currency;

        // Health indicator
        const enabledCount = state.gateways.filter(g => g.enabled && g.configured).length;
        const dot = document.getElementById('pp-health-dot');
        const text = document.getElementById('pp-health-text');
        if (enabledCount > 0) {
            dot.className = 'pp-health-dot active';
            text.textContent = `${enabledCount} active`;
        } else {
            dot.className = 'pp-health-dot';
            text.textContent = 'No gateways active';
        }

        grid.innerHTML = state.gateways.map(g => `
            <div class="pp-card ${g.enabled ? 'active' : ''}">
                <div class="pp-card-head">
                    <div class="pp-card-icon ${g.icon_class}">${g.icon}</div>
                    <div class="pp-card-head-text">
                        <h3 class="pp-card-title">${g.name}</h3>
                        <div class="pp-card-type">${g.sandbox ? 'Sandbox' : 'Live'} Mode</div>
                    </div>
                    <div class="pp-card-status">
                        <span class="pp-status-dot ${g.status}"></span>
                    </div>
                </div>
                <div class="pp-card-body">
                    <div class="pp-card-row">
                        <span class="pp-card-label">Status</span>
                        <span>${g.enabled ? '<span class="pp-tag pp-tag-active">Enabled</span>' : '<span class="pp-tag pp-tag-disabled">Disabled</span>'}</span>
                    </div>
                    <div class="pp-card-row">
                        <span class="pp-card-label">Configured</span>
                        <span>${g.configured ? '<span class="pp-tag pp-tag-active">Yes</span>' : '<span class="pp-tag pp-tag-disabled">No</span>'}</span>
                    </div>
                    ${g.isDefault ? '<div class="pp-card-row"><span class="pp-card-label">Role</span><span class="pp-role-pill">Default</span></div>' : ''}
                </div>
                <div class="pp-card-actions">
                    <button class="pp-btn pp-btn-primary" onclick="PP.configure('${g.id}')"><i class="fas fa-cog"></i> Configure</button>
                    <button class="pp-btn ${g.enabled ? 'pp-btn-danger' : 'pp-btn-success'}" onclick="PP.toggle('${g.id}', ${!g.enabled})">${g.enabled ? 'Disable' : 'Enable'}</button>
                    ${g.configured ? `<button class="pp-btn" onclick="PP.test('${g.id}')"><i class="fas fa-plug"></i> Test</button>` : ''}
                    ${!g.isDefault && g.enabled ? `<button class="pp-btn" onclick="PP.setDefault('${g.id}')"><i class="fas fa-star"></i> Set Default</button>` : ''}
                </div>
            </div>
        `).join('');
    }

    async function load() {
        const res = await api('get_state');
        if (res.ok) {
            state = res.data;
            render();
        }
    }

    function configure(id) {
        const gw = state.gateways.find(g => g.id === id);
        if (!gw) return;
        editingGateway = id;

        document.getElementById('pp-modal-title').textContent = `Configure ${gw.name}`;
        document.getElementById('pp-modal-body').innerHTML = `
            <div class="pp-form-group">
                <label>Mode</label>
                <select id="pp-field-sandbox">
                    <option value="true" ${gw.sandbox ? 'selected' : ''}>Sandbox / Test</option>
                    <option value="false" ${!gw.sandbox ? 'selected' : ''}>Live / Production</option>
                </select>
                <small>Use sandbox mode for testing before going live</small>
            </div>
            ${gw.fields.map(f => `
                <div class="pp-form-group">
                    <label>${f.label} ${f.required ? '*' : ''}</label>
                    <input type="${f.type}" id="pp-field-${f.key}" placeholder="Enter ${f.label.toLowerCase()}" value="" autocomplete="off">
                    ${f.type === 'password' ? '<small>Leave blank to keep existing value</small>' : ''}
                </div>
            `).join('')}
            ${gw.docs_url ? `<p style="margin-top:12px;font-size:12px;color:var(--pp-muted)"><a href="${gw.docs_url}" target="_blank" style="color:var(--pp-primary)">View documentation &rarr;</a></p>` : ''}
        `;
        document.getElementById('pp-modal').style.display = 'flex';
    }

    async function saveGateway() {
        if (!editingGateway) return;
        const gw = state.gateways.find(g => g.id === editingGateway);
        const config = {
            sandbox: document.getElementById('pp-field-sandbox').value === 'true',
        };
        gw.fields.forEach(f => {
            const val = document.getElementById('pp-field-' + f.key)?.value?.trim();
            if (val) config[f.key] = val;
        });

        const res = await api('update_gateway', { gateway_id: editingGateway, config });
        if (res.ok) {
            state.gateways = res.data.gateways;
            render();
            closeModal();
            toast('Gateway configured', 'success');
        } else {
            toast(res.message || 'Save failed', 'error');
        }
    }

    async function toggle(id, enable) {
        const res = await api('enable_gateway', { gateway_id: id, enabled: String(enable) });
        if (res.ok) {
            state.gateways = res.data.gateways;
            render();
            toast(enable ? 'Gateway enabled' : 'Gateway disabled', 'success');
        }
    }

    async function test(id) {
        toast('Testing connection...', 'info');
        const res = await api('test_connection', { gateway_id: id });
        if (res.ok) {
            state.gateways = res.data.gateways;
            render();
            toast(res.message, 'success');
        } else {
            toast(res.message || 'Test failed', 'error');
        }
    }

    async function setDefault(id) {
        const res = await api('set_default', { gateway_id: id });
        if (res.ok) {
            state.gateways = res.data.gateways;
            state.defaultGateway = res.data.defaultGateway;
            render();
            toast('Default gateway updated', 'success');
        }
    }

    function saveCurrency(val) {
        api('set_currency', { currency: val }).then(res => {
            if (res.ok) {
                state.currency = val;
                toast('Currency updated', 'success');
            }
        });
    }

    function closeModal() {
        document.getElementById('pp-modal').style.display = 'none';
        editingGateway = null;
    }

    // Init
    document.addEventListener('DOMContentLoaded', load);

    // ESC closes modal
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });

    return { configure, saveGateway, toggle, test, setDefault, saveCurrency, closeModal, load };
})();
