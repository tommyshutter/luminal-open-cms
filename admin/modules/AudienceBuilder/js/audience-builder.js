/**
 * AudienceBuilder — Admin UI Logic (v1.0)
 * Namespace: AB
 *
 * Dual-mode: hub (full config + leads) or node (sent leads + hub status).
 * Default tab = Leads.
 * Lead viewer: lightbox-style iframe viewer.
 */
(function() {
'use strict';

const API = window.AB_BOOT.apiUrl;
let _mode = 'hub';
let _hubUrl = null;

// ── Helpers ──

function $(sel, ctx) { return (ctx || document).querySelector(sel); }
function $$(sel, ctx) { return [...(ctx || document).querySelectorAll(sel)]; }

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

async function api(action, data) {
    const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    return res.json();
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function fmtDay(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-US', { weekday: 'short' });
}

// Extract first message/inquiry-like field from lead fields
const MSG_KEYS = ['message','inquiry','description','comments','details','body','notes','question'];
function getMsg(fields) {
    if (!fields) return '';
    for (const mk of MSG_KEYS) {
        for (const k of Object.keys(fields)) {
            if (k.toLowerCase() === mk && (fields[k] || '').trim()) return fields[k].trim();
        }
    }
    return '';
}
function truncMsg(s, max) {
    if (!s) return '<span class="ab-muted">—</span>';
    return s.length > (max || 50) ? esc(s.substring(0, max || 50)) + '…' : esc(s);
}

// ── Tab switching ──

$$('.ab-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        $$('.ab-tab').forEach(t => t.classList.remove('active'));
        $$('.ab-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const panel = $('#ab-' + tab.dataset.tab);
        if (panel) panel.classList.add('active');

        if (tab.dataset.tab === 'leads') {
            loadStats();
            loadLeads(1);
        } else if (tab.dataset.tab === 'sent') {
            loadSentStats();
            loadSent(1);
        }
    });
});

// ══════════════════════════════════════════════════════
// MODE DETECTION & INIT
// ══════════════════════════════════════════════════════

async function initMode() {
    // Relocate viewer to document.body to escape backdrop-filter stacking context
    const viewer = document.getElementById('abViewer');
    if (viewer && viewer.parentNode !== document.body) {
        document.body.appendChild(viewer);
    }

    const r = await api('get_mode');
    if (!r.ok) return;
    _mode = r.mode;
    _hubUrl = r.hub_url;

    if (_mode === 'node') {
        // Show Sent tab
        const sentTab = $('#abSentTab');
        if (sentTab) sentTab.style.display = '';

        // Hide domain filter (only meaningful on hub)
        const domFilter = $('#abFilterDomain');
        if (domFilter) domFilter.style.display = 'none';

        // Show mode indicator on config tab
        const modeBar = $('#abModeBar');
        if (modeBar) {
            modeBar.style.display = '';
            $('#abModeText').innerHTML = 'This site is in <strong>node mode</strong> — leads are forwarded to <strong>' + esc(_hubUrl) + '</strong>';
        }
    } else {
        // Hub mode: show Source column + filter
        $$('.ab-source-col').forEach(el => el.style.display = '');
        const srcFilter = $('#abFilterSource');
        if (srcFilter) srcFilter.style.display = '';
    }
}

// ══════════════════════════════════════════════════════
// CONFIG TAB
// ══════════════════════════════════════════════════════

async function loadConfig() {
    const r = await api('get_config');
    if (!r.ok) return;
    const c = r.config;

    // Mailgun
    $('#abNotifyEnabled').checked = !!c.mailgun?.notify_enabled;
    $('#abNotifyTo').value = c.mailgun?.notify_to || '';
    $('#abNotifySubject').value = c.mailgun?.notify_subject || 'New Lead from {domain}';

    // Toggles
    $('#abEnabled').checked = c.enabled !== false;
    $('#abStoreLeads').checked = c.store_leads !== false;

    // Hub forwarding
    $('#abHubEnabled').checked = !!c.hub_enabled;
    $('#abHubUrl').value = c.hub_url || '';
    updateHubToggleUI();

    // Status
    const dot = $('.ab-status-dot', $('#abStatusBar'));
    const txt = $('.ab-status-text', $('#abStatusBar'));
    if (c.enabled !== false) {
        dot.className = 'ab-status-dot ok';
        txt.textContent = _mode === 'node'
            ? 'Node mode — forwarding to hub'
            : 'Hub mode — collecting leads locally';
    } else {
        dot.className = 'ab-status-dot warn';
        txt.textContent = 'Module disabled — enable on the Configuration tab';
    }

    // Populate filter dropdowns
    const domains = c._domains || [];
    const domSel = $('#abFilterDomain');
    domSel.innerHTML = '<option value="">All Domains</option>';
    domains.forEach(d => {
        domSel.innerHTML += `<option value="${esc(d)}">${esc(d)}</option>`;
    });
}

// ── Hub/Node toggle UI ──

function updateHubToggleUI() {
    const isNode = $('#abHubEnabled').checked;
    const hubUrlField = $('#abHubUrlField');
    const label = $('#abHubToggleLabel');

    if (isNode) {
        if (hubUrlField) hubUrlField.style.display = '';
        label.textContent = 'Node mode — forwarding leads to hub (uncheck to set as Hub)';
    } else {
        if (hubUrlField) hubUrlField.style.display = 'none';
        label.textContent = 'Set as Node — forward leads to a hub site';
    }
}

$('#abHubEnabled').addEventListener('change', updateHubToggleUI);

// Save config
$('#abSaveBtn').addEventListener('click', async () => {
    const btn = $('#abSaveBtn');
    const st = $('#abSaveStatus');
    btn.disabled = true;
    st.textContent = 'Saving…';
    st.className = 'ab-save-status';

    const payload = {
        mailgun: {
            notify_enabled: $('#abNotifyEnabled').checked,
            notify_to: $('#abNotifyTo').value.trim(),
            notify_subject: $('#abNotifySubject').value.trim() || 'New Lead from {domain}',
        },
        enabled: $('#abEnabled').checked,
        store_leads: $('#abStoreLeads').checked,
        hub_url: $('#abHubUrl').value.trim(),
        hub_enabled: $('#abHubEnabled').checked,
    };

    const r = await api('save_config', payload);
    btn.disabled = false;

    if (r.ok) {
        st.textContent = 'Saved';
        st.className = 'ab-save-status ok';
        loadConfig();
    } else {
        st.textContent = r.error || 'Save failed';
        st.className = 'ab-save-status err';
    }
});

// ══════════════════════════════════════════════════════
// LEADS TAB
// ══════════════════════════════════════════════════════

let _currentPage = 1;
let _currentLeadId = null;
let _currentLeadType = 'lead';
let _searchTimeout = null;

async function loadStats() {
    const r = await api('stats');
    if (!r.ok) return;
    $('#abStatTotal').textContent = r.total ?? 0;
    $('#abStatMonth').textContent = r.this_month ?? 0;
    $('#abStatStored').textContent = r.by_status?.stored ?? 0;
    $('#abStatPending').textContent = r.by_status?.pending ?? 0;

    // Populate source filter for hub mode
    if (_mode === 'hub' && r.by_source) {
        const srcSel = $('#abFilterSource');
        if (srcSel) {
            srcSel.innerHTML = '<option value="">All Sources</option>';
            Object.keys(r.by_source).sort().forEach(src => {
                srcSel.innerHTML += `<option value="${esc(src)}">${esc(src)} (${r.by_source[src]})</option>`;
            });
        }
    }
}

async function loadLeads(page) {
    _currentPage = page || 1;
    const body = $('#abLeadBody');
    const colCount = _mode === 'hub' ? 8 : 7;
    body.innerHTML = `<tr><td colspan="${colCount}" class="ab-muted">Loading…</td></tr>`;

    const params = { page: _currentPage };
    const domFilter = $('#abFilterDomain').value;
    const srcFilter = _mode === 'hub' ? ($('#abFilterSource')?.value || '') : '';
    const search = $('#abFilterSearch')?.value?.trim() || '';
    if (domFilter) params.domain = domFilter;
    if (srcFilter) params.source = srcFilter;
    if (search) params.search = search;

    const r = await api('list_leads', params);
    if (!r.ok || !r.leads.length) {
        body.innerHTML = `<tr><td colspan="${colCount}" class="ab-muted">No leads found.</td></tr>`;
        $('#abPagination').innerHTML = '';
        return;
    }

    body.innerHTML = r.leads.map(l => {
        const sourceCol = _mode === 'hub'
            ? `<td class="ab-source-col">${l.source_site && l.source_site !== l.domain ? '<span class="ab-source-badge">' + esc(l.source_site) + '</span>' : '<span class="ab-source-badge ab-source-direct">direct</span>'}</td>`
            : '';
        return `
        <tr data-id="${esc(l.id)}" data-type="lead">
            <td class="ab-day-col">${esc(fmtDay(l.submitted_at))}</td>
            <td>${esc(fmtDate(l.submitted_at))}</td>
            ${sourceCol}
            <td>${esc(l.fields?.name || '—')}</td>
            <td>${esc(l.fields?.email || '—')}</td>
            <td>${esc(l.fields?.phone || '—')}</td>
            <td class="ab-msg-col">${truncMsg(getMsg(l.fields), 40)}</td>
            <td><span class="ab-badge ab-badge-${l.status || 'stored'}">${esc(l.status || 'stored')}</span></td>
        </tr>`;
    }).join('');

    // Pagination
    const pag = $('#abPagination');
    if (r.pages <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= r.pages; i++) {
        html += `<button class="ab-page-btn ${i === r.page ? 'active' : ''}" onclick="AB.goPage(${i})">${i}</button>`;
    }
    pag.innerHTML = html;
}

// Filters
$('#abFilterDomain').addEventListener('change', () => loadLeads(1));
$('#abFilterSource')?.addEventListener('change', () => loadLeads(1));
$('#abRefreshBtn').addEventListener('click', () => { loadStats(); loadLeads(_currentPage); });

// Search with debounce
$('#abFilterSearch')?.addEventListener('input', () => {
    clearTimeout(_searchTimeout);
    _searchTimeout = setTimeout(() => loadLeads(1), 400);
});

// ══════════════════════════════════════════════════════
// SENT TAB (node mode)
// ══════════════════════════════════════════════════════

let _sentPage = 1;

async function loadSentStats() {
    const r = await api('sent_stats');
    if (!r.ok) return;
    $('#abSentTotal').textContent = r.total ?? 0;
    $('#abSentMonth').textContent = r.this_month ?? 0;
    $('#abSentDelivered').textContent = r.by_status?.delivered ?? 0;
    $('#abSentFailed').textContent = (r.by_status?.hub_failed ?? 0) + (r.by_status?.hub_error ?? 0);
    $('#abSentPending').textContent = r.by_status?.pending ?? 0;
}

async function loadSent(page) {
    _sentPage = page || 1;
    const body = $('#abSentBody');
    body.innerHTML = '<tr><td colspan="7" class="ab-muted">Loading…</td></tr>';

    const params = { page: _sentPage };
    const statFilter = $('#abSentFilterStatus').value;
    if (statFilter) params.status = statFilter;

    const r = await api('list_sent', params);
    if (!r.ok || !r.sent.length) {
        body.innerHTML = '<tr><td colspan="7" class="ab-muted">No sent leads found.</td></tr>';
        $('#abSentPagination').innerHTML = '';
        return;
    }

    body.innerHTML = r.sent.map(s => {
        const retryBadge = (s.retries ?? 0) > 0 ? ` <span style="font-size:10px;color:rgba(255,255,255,.4)">(${s.retries})</span>` : '';
        return `
        <tr data-id="${esc(s.id)}" data-type="sent">
            <td class="ab-day-col">${esc(fmtDay(s.submitted_at))}</td>
            <td>${esc(fmtDate(s.submitted_at))}</td>
            <td>${esc(s.fields?.name || '—')}</td>
            <td>${esc(s.fields?.email || '—')}</td>
            <td>${esc(s.fields?.phone || '—')}</td>
            <td class="ab-msg-col">${truncMsg(getMsg(s.fields), 40)}</td>
            <td><span class="ab-badge ab-badge-${s.hub_status || 'pending'}">${esc(s.hub_status || 'pending')}</span>${retryBadge}</td>
        </tr>`;
    }).join('');

    // Pagination
    const pag = $('#abSentPagination');
    if (r.pages <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= r.pages; i++) {
        html += `<button class="ab-page-btn ${i === r.page ? 'active' : ''}" onclick="AB.goSentPage(${i})">${i}</button>`;
    }
    pag.innerHTML = html;
}

$('#abSentFilterStatus')?.addEventListener('change', () => loadSent(1));
$('#abSentRefreshBtn')?.addEventListener('click', () => { loadSentStats(); loadSent(_sentPage); });

// ══════════════════════════════════════════════════════
// LEAD VIEWER (lightbox-style)
// ══════════════════════════════════════════════════════

async function viewLead(id, type) {
    _currentLeadId = id;
    _currentLeadType = type || 'lead';

    const viewer = $('#abViewer');
    const frame  = $('#abViewerFrame');
    const title  = $('#abViewerTitle');

    viewer.style.display = '';
    document.body.style.overflow = 'hidden';
    frame.src = 'about:blank';
    title.textContent = 'Loading…';

    const r = await api('lead_print_html', { id, type: _currentLeadType });
    if (!r.ok) {
        frame.srcdoc = '<html><body style="font-family:sans-serif;padding:40px;color:#666"><h2>Error</h2><p>' + esc(r.error || 'Failed to load') + '</p></body></html>';
        title.textContent = 'Error';
        return;
    }

    frame.srcdoc = r.html;
    title.textContent = r.title || id;

    // Configure action buttons based on type
    const retryBtn = $('#abViewerRetry');
    const deleteBtn = $('#abViewerDelete');
    if (_currentLeadType === 'sent') {
        retryBtn.title = 'Retry to Hub';
        retryBtn.onclick = () => retryToHubFromViewer();
        deleteBtn.onclick = () => deleteFromViewer('sent');
    } else {
        retryBtn.style.display = _mode === 'node' ? 'none' : '';
        retryBtn.title = 'Retry';
        retryBtn.onclick = () => retryToHubFromViewer();
        deleteBtn.onclick = () => deleteFromViewer('lead');
    }
}

function closeViewer() {
    $('#abViewer').style.display = 'none';
    document.body.style.overflow = '';
    $('#abViewerFrame').src = 'about:blank';
    _currentLeadId = null;
}

$('#abViewerClose').addEventListener('click', closeViewer);
$('#abViewer').addEventListener('click', e => {
    if (e.target === $('#abViewer')) closeViewer();
});

// Print from viewer
$('#abViewerPrint').addEventListener('click', () => {
    const frame = $('#abViewerFrame');
    if (frame.contentWindow) frame.contentWindow.print();
});

// Retry to hub from viewer (node mode)
async function retryToHubFromViewer() {
    if (!_currentLeadId) return;
    const btn = $('#abViewerRetry');
    btn.disabled = true;
    const r = await api('retry_to_hub', { id: _currentLeadId });
    btn.disabled = false;
    if (r.ok) {
        viewLead(_currentLeadId, _currentLeadType);
        if (_currentLeadType === 'sent') {
            loadSent(_sentPage);
            loadSentStats();
        } else {
            loadLeads(_currentPage);
            loadStats();
        }
    } else {
        alert(r.error || 'Retry failed');
    }
}

// Delete from viewer
async function deleteFromViewer(type) {
    if (!_currentLeadId) return;
    if (!confirm('Delete this record permanently?')) return;
    const action = type === 'sent' ? 'delete_sent' : 'delete_lead';
    const r = await api(action, { id: _currentLeadId });
    if (r.ok) {
        closeViewer();
        if (type === 'sent') {
            loadSent(_sentPage);
            loadSentStats();
        } else {
            loadLeads(_currentPage);
            loadStats();
        }
    } else {
        alert(r.error || 'Delete failed');
    }
}

// ESC key closes viewer
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && $('#abViewer').style.display !== 'none') {
        closeViewer();
    }
});

// ── Flush Sent Queue (node mode) ──

$('#abSentFlushBtn')?.addEventListener('click', flushSentQueue);

async function flushSentQueue() {
    const btn = $('#abSentFlushBtn');
    const st  = $('#abSentFlushStatus');
    if (btn.disabled) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    st.textContent = '';

    const r = await api('flush_sent_queue');

    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Forward to Hub';
    btn.disabled = false;

    if (r.ok) {
        const parts = [];
        if (r.delivered) parts.push(r.delivered + ' delivered');
        if (r.failed)    parts.push(r.failed + ' still failed');
        st.textContent = parts.length ? parts.join(', ') : 'Nothing to retry';
        st.className = 'ab-flush-status ' + (r.failed ? 'err' : 'ok');
    } else {
        st.textContent = r.error || 'Flush failed';
        st.className = 'ab-flush-status err';
    }

    loadSentStats();
    loadSent(_sentPage);
}

// ── Export ──

async function exportLeads(format) {
    const params = { format };
    const domFilter = $('#abFilterDomain').value;
    const search = $('#abFilterSearch')?.value?.trim() || '';
    if (domFilter) params.domain = domFilter;
    if (search)    params.search = search;

    const r = await api('export_leads', params);
    if (!r.ok) { alert(r.error || 'Export failed'); return; }

    downloadBlob(r.content, `ab-leads-${new Date().toISOString().slice(0,10)}`, format);
}

async function exportSent(format) {
    const params = { format };
    const statFilter = $('#abSentFilterStatus')?.value;
    if (statFilter) params.status = statFilter;

    const r = await api('export_sent', params);
    if (!r.ok) { alert(r.error || 'Export failed'); return; }

    downloadBlob(r.content, `ab-sent-${new Date().toISOString().slice(0,10)}`, format);
}

function downloadBlob(content, basename, format) {
    const ext  = format === 'md' ? 'md' : 'csv';
    const mime = format === 'md' ? 'text/markdown' : 'text/csv';
    const blob = new Blob([content], { type: mime });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `${basename}.${ext}`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Clickable table rows — open viewer on click ──

document.addEventListener('click', e => {
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    const id   = row.dataset.id;
    const type = row.dataset.type || 'lead';
    viewLead(id, type);
});

// ── Public API ──

window.AB = {
    viewLead,
    goPage: loadLeads,
    goSentPage: loadSent,
    flushSentQueue,
    exportLeads,
    exportSent,
};

// ── Init ──

(async function init() {
    await initMode();
    loadConfig();
    loadStats();
    loadLeads(1);

    if (_mode === 'node') {
        loadSentStats();
        loadSent(1);
    }
})();

})();
