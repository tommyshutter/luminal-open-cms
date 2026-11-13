<?php
/**
 * SiteTextReceiver — Admin Panel UI
 *
 * Card grid showing all defined content slots.
 * Each card: key name, last updated, updated by, version count, content preview.
 * Inline editor + version history with revert.
 *
 * @package  LuminalCMS
 * @module   SiteTextReceiver
 * @version  1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';

$apiUrl = '/admin/modules/SiteTextReceiver/api.php';
?>

<h1 class="panel_header_h1" style="color:white">Site Text <span style="font-size:0.55em;color:#888;font-weight:400">Content Slots</span></h1>

<style>
/* SiteTextReceiver Admin Styles */
.str-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding: 12px 16px;
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
}
.str-toolbar .str-count { color: #888; font-size: 0.85rem; }
.str-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 1px solid rgba(245,158,11,0.4);
    background: rgba(245,158,11,0.1); color: #f59e0b;
    border-radius: 6px; cursor: pointer; font-size: 0.85rem;
    transition: all 0.2s;
}
.str-btn:hover { background: rgba(245,158,11,0.25); border-color: #f59e0b; }
.str-btn-danger { border-color: rgba(239,68,68,0.4); background: rgba(239,68,68,0.1); color: #ef4444; }
.str-btn-danger:hover { background: rgba(239,68,68,0.25); border-color: #ef4444; }
.str-btn-sm { padding: 5px 10px; font-size: 0.8rem; }

.str-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
}
.str-card {
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px; padding: 16px; transition: border-color 0.2s;
}
.str-card:hover { border-color: rgba(245,158,11,0.3); }
.str-card-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 10px;
}
.str-card-key {
    font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.95rem;
    color: #f59e0b; font-weight: 600;
}
.str-card-label {
    font-size: 0.8rem; color: #aaa; margin-top: 2px;
}
.str-card-version {
    background: rgba(245,158,11,0.15); color: #f59e0b;
    padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;
    white-space: nowrap;
}
.str-card-meta {
    display: flex; gap: 16px; font-size: 0.78rem; color: #777;
    margin-bottom: 10px; flex-wrap: wrap;
}
.str-card-preview {
    background: rgba(0,0,0,0.2); border-radius: 6px; padding: 10px;
    font-size: 0.82rem; color: #bbb; max-height: 80px; overflow: hidden;
    position: relative; line-height: 1.5;
}
.str-card-preview::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0;
    height: 30px; background: linear-gradient(transparent, rgba(0,0,0,0.4));
}
.str-card-actions {
    display: flex; gap: 8px; margin-top: 12px;
}

/* Modal / Editor overlay */
.str-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.7);
    display: none; z-index: 9000; justify-content: center; align-items: flex-start;
    overflow-y: auto; padding: 3vh 0;
}
.str-overlay.active { display: flex; }
.str-modal {
    background: #1a1a2e; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px; width: 90%; max-width: 700px; max-height: 94vh;
    overflow-y: auto; padding: 24px;
}
.str-modal h2 { color: #f59e0b; margin: 0 0 16px; font-size: 1.1rem; }
.str-modal label { display: block; color: #aaa; font-size: 0.82rem; margin-bottom: 4px; }
.str-modal input[type="text"],
.str-modal textarea {
    width: 100%; padding: 10px; background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;
    color: #eee; font-size: 0.9rem; margin-bottom: 12px; box-sizing: border-box;
    font-family: inherit;
}
.str-modal textarea { min-height: 600px; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.82rem; resize: vertical; }
.str-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }

/* History panel */
.str-history-item {
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 6px; padding: 10px; margin-bottom: 8px;
}
.str-history-meta { font-size: 0.78rem; color: #888; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
.str-history-preview { font-size: 0.8rem; color: #aaa; max-height: 60px; overflow: hidden; }

.str-empty {
    text-align: center; padding: 60px 20px; color: #666;
}
.str-empty h3 { color: #888; margin-bottom: 8px; }
</style>

<div class="str-toolbar">
    <span class="str-count" id="strCount">Loading...</span>
    <button class="str-btn" onclick="strNewSlot()">+ New Slot</button>
</div>

<div class="str-grid" id="strGrid"></div>

<!-- Editor Overlay -->
<div class="str-overlay" id="strEditorOverlay">
    <div class="str-modal">
        <h2 id="strEditorTitle">Edit Slot</h2>
        <input type="hidden" id="strEditMode" value="new">
        <div>
            <label>Key (slug, lowercase, hyphens/underscores only)</label>
            <input type="text" id="strEditKey" placeholder="this-week">
        </div>
        <div>
            <label>Label (human-friendly name)</label>
            <input type="text" id="strEditLabel" placeholder="This Week at the Venue">
        </div>
        <div>
            <label>HTML Content</label>
            <textarea id="strEditHtml" placeholder="<div>Your content here...</div>"></textarea>
        </div>
        <div>
            <label>Source (who is pushing this)</label>
            <input type="text" id="strEditSource" value="human:admin" placeholder="human:admin or agent:content-ops">
        </div>
        <div class="str-modal-actions">
            <button class="str-btn" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#aaa" onclick="strCloseEditor()">Cancel</button>
            <button class="str-btn" onclick="strSaveSlot()">Save</button>
        </div>
    </div>
</div>

<!-- History Overlay -->
<div class="str-overlay" id="strHistoryOverlay">
    <div class="str-modal">
        <h2 id="strHistoryTitle">Version History</h2>
        <div id="strHistoryList"></div>
        <div class="str-modal-actions">
            <button class="str-btn" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.15);color:#aaa" onclick="strCloseHistory()">Close</button>
        </div>
    </div>
</div>

<script>
const STR_API = <?= json_encode($apiUrl) ?>;

async function strApi(action, params = {}) {
    let url = STR_API + '?action=' + encodeURIComponent(action);
    const opts = { headers: {} };
    if (['push'].includes(action)) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(params);
    } else {
        Object.keys(params).forEach(k => url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    }
    const res = await fetch(url, opts);
    return res.json();
}

function strEsc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function strTruncate(html, max = 120) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    const text = tmp.textContent || tmp.innerText || '';
    return text.length > max ? text.substring(0, max) + '...' : text;
}

function strTimeAgo(iso) {
    if (!iso) return 'never';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

async function strLoad() {
    const data = await strApi('list');
    if (!data.ok) { document.getElementById('strCount').textContent = 'Error loading slots'; return; }
    const slots = data.slots || [];
    document.getElementById('strCount').textContent = slots.length + ' slot' + (slots.length !== 1 ? 's' : '');

    const grid = document.getElementById('strGrid');
    if (slots.length === 0) {
        grid.innerHTML = '<div class="str-empty"><h3>No content slots yet</h3><p>Click "+ New Slot" to create your first key-value content slot.</p><p style="font-size:0.8rem;color:#555">Use <code style="color:#f59e0b">[[site-text:key-name]]</code> in any page to render a slot.</p></div>';
        return;
    }

    grid.innerHTML = slots.map(s => `
        <div class="str-card">
            <div class="str-card-header">
                <div>
                    <div class="str-card-key">${strEsc(s.key)}</div>
                    ${s.label && s.label !== s.key ? `<div class="str-card-label">${strEsc(s.label)}</div>` : ''}
                </div>
                <span class="str-card-version">v${s.version}</span>
            </div>
            <div class="str-card-meta">
                <span title="${strEsc(s.updated_at)}">Updated ${strTimeAgo(s.updated_at)}</span>
                <span>by ${strEsc(s.updated_by)}</span>
            </div>
            <div class="str-card-preview" id="strPreview-${strEsc(s.key)}">Loading...</div>
            <div class="str-card-actions">
                <button class="str-btn str-btn-sm" onclick="strEditSlot('${strEsc(s.key)}')">Edit</button>
                <button class="str-btn str-btn-sm" onclick="strShowHistory('${strEsc(s.key)}')">History</button>
                <button class="str-btn str-btn-sm str-btn-danger" onclick="strDeleteSlot('${strEsc(s.key)}')">Delete</button>
            </div>
        </div>
    `).join('');

    // Load previews
    slots.forEach(async s => {
        const d = await strApi('get', { key: s.key });
        const el = document.getElementById('strPreview-' + s.key);
        if (el && d.ok) {
            el.textContent = strTruncate(d.slot.html, 150);
        }
    });
}

function strNewSlot() {
    document.getElementById('strEditMode').value = 'new';
    document.getElementById('strEditorTitle').textContent = 'New Slot';
    document.getElementById('strEditKey').value = '';
    document.getElementById('strEditKey').readOnly = false;
    document.getElementById('strEditLabel').value = '';
    document.getElementById('strEditHtml').value = '';
    document.getElementById('strEditSource').value = 'human:admin';
    document.getElementById('strEditorOverlay').classList.add('active');
}

async function strEditSlot(key) {
    const data = await strApi('get', { key });
    if (!data.ok) { alert('Failed to load slot'); return; }
    const s = data.slot;
    document.getElementById('strEditMode').value = 'edit';
    document.getElementById('strEditorTitle').textContent = 'Edit: ' + key;
    document.getElementById('strEditKey').value = s.key;
    document.getElementById('strEditKey').readOnly = true;
    document.getElementById('strEditLabel').value = s.label || '';
    document.getElementById('strEditHtml').value = s.html || '';
    document.getElementById('strEditSource').value = 'human:admin';
    document.getElementById('strEditorOverlay').classList.add('active');
}

function strCloseEditor() {
    document.getElementById('strEditorOverlay').classList.remove('active');
}

async function strSaveSlot() {
    const key = document.getElementById('strEditKey').value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
    if (!key) { alert('Key is required'); return; }
    const data = await strApi('push', {
        key,
        label: document.getElementById('strEditLabel').value.trim(),
        html: document.getElementById('strEditHtml').value,
        source: document.getElementById('strEditSource').value.trim() || 'human:admin'
    });
    if (!data.ok) { alert('Save failed: ' + (data.error || 'unknown error')); return; }
    strCloseEditor();
    strLoad();
}

async function strShowHistory(key) {
    document.getElementById('strHistoryTitle').textContent = 'History: ' + key;
    const data = await strApi('history', { key });
    const list = document.getElementById('strHistoryList');
    if (!data.ok || !data.history.length) {
        list.innerHTML = '<p style="color:#666;text-align:center;padding:20px">No version history yet.</p>';
    } else {
        list.innerHTML = data.history.map(h => `
            <div class="str-history-item">
                <div class="str-history-meta">
                    <span>v${h.version} &mdash; ${strEsc(h.updated_by)} &mdash; ${strTimeAgo(h.updated_at)}</span>
                    <button class="str-btn str-btn-sm" onclick="strRevert('${strEsc(key)}', ${h.version})">Revert</button>
                </div>
                <div class="str-history-preview">${strTruncate(h.html, 200)}</div>
            </div>
        `).join('');
    }
    document.getElementById('strHistoryOverlay').classList.add('active');
}

function strCloseHistory() {
    document.getElementById('strHistoryOverlay').classList.remove('active');
}

async function strRevert(key, version) {
    if (!confirm('Revert to version ' + version + '?')) return;
    const data = await strApi('revert', { key, version });
    if (!data.ok) { alert('Revert failed: ' + (data.error || 'unknown')); return; }
    strCloseHistory();
    strLoad();
}

async function strDeleteSlot(key) {
    if (!confirm('Delete slot "' + key + '"? This cannot be undone.')) return;
    const data = await strApi('delete', { key });
    if (!data.ok) { alert('Delete failed: ' + (data.error || 'unknown')); return; }
    strLoad();
}

// Close overlays on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        strCloseEditor();
        strCloseHistory();
    }
});

// Init
strLoad();
</script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
