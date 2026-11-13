/**
 * MembershipManager — Invite-Only Admin Dashboard
 * Version 2.0.0
 */
const MM = (function() {
    'use strict';
    const API = '/admin/modules/MembershipManager/api.php';
    let currentTab = 'members';

    function init() {
        bindTabs();
        bindButtons();
        loadStats();
        loadMembers();
    }

    // ── API helper ──
    async function api(action, method, body) {
        let url = API + '?action=' + action;
        const opts = { method: method || 'GET' };
        if (body) { opts.headers = {'Content-Type':'application/json'}; opts.body = JSON.stringify(body); }
        const r = await fetch(url, opts);
        return r.json();
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function relTime(iso) {
        if (!iso) return '-';
        const d = new Date(iso), now = new Date(), diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm ago';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
        return d.toLocaleDateString();
    }

    // ── Tabs ──
    function bindTabs() {
        document.querySelectorAll('.mm-tab').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.mm-tab').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const tab = this.dataset.tab;
                currentTab = tab;
                document.querySelectorAll('.mm-panel').forEach(p => p.style.display = 'none');
                const panelMap = { members:'tabMembers', codes:'tabCodes', referrals:'tabReferrals', leaderboard:'tabLeaderboard', activity:'tabActivity' };
                const panel = document.getElementById(panelMap[tab]);
                if (panel) panel.style.display = '';
                loadTab(tab);
            });
        });
    }

    function loadTab(tab) {
        const loaders = { members: loadMembers, codes: loadCodes, referrals: loadReferrals, leaderboard: loadLeaderboard, activity: loadActivity };
        if (loaders[tab]) loaders[tab]();
    }

    // ── Buttons ──
    function bindButtons() {
        document.getElementById('mmGenerateCodes')?.addEventListener('click', showGenerateCodesPanel);
        document.getElementById('mmShowSettings')?.addEventListener('click', showSettings);
        document.getElementById('mmSettingsClose')?.addEventListener('click', hideSettings);
        document.getElementById('mmSettingsCancel')?.addEventListener('click', hideSettings);
        document.getElementById('mmSettingsSave')?.addEventListener('click', saveSettings);
    }

    // ── Stats ──
    async function loadStats() {
        const [membersRes, codesRes, referralsRes] = await Promise.all([
            api('list_members'), api('list_codes'), api('get_referrals')
        ]);
        if (membersRes.ok) {
            const m = membersRes.members || [];
            document.getElementById('statMembers').textContent = m.length;
            document.getElementById('statActive').textContent = m.filter(x => x.status === 'active').length;
            const totalRefs = m.reduce((sum, x) => sum + (x.referral_count || 0), 0);
            document.getElementById('statReferrals').textContent = totalRefs;
            const totalWins = m.reduce((sum, x) => sum + (x.wins || 0), 0);
            document.getElementById('statWins').textContent = totalWins;
        }
        if (codesRes.ok) {
            const available = (codesRes.codes || []).filter(c => !c.used_by);
            document.getElementById('statCodes').textContent = available.length;
        }
    }

    // ── Members Tab ──
    async function loadMembers() {
        const el = document.getElementById('mmMembersBody');
        el.innerHTML = '<div class="mm-loading">Loading members...</div>';
        const res = await api('list_members');
        if (!res.ok) { el.innerHTML = '<div class="mm-loading">Error loading members</div>'; return; }
        const members = res.members || [];
        if (!members.length) { el.innerHTML = '<div class="mm-empty">No members yet. Generate invite codes to get started.</div>'; return; }

        let html = '<table class="mm-table"><thead><tr><th>Handle</th><th>Email</th><th>Badge</th><th>Referrals</th><th>Joined</th><th>Status</th></tr></thead><tbody>';
        members.forEach(m => {
            const badge = m.badge_tier || 'none';
            const badgeClass = badge !== 'none' ? 'mm-badge-tier-' + badge.toLowerCase() : '';
            html += `<tr>
                <td class="mm-mono">${esc(m.anon_handle || '-')}</td>
                <td>${esc(m.email || '-')}</td>
                <td>${badge !== 'none' ? '<span class="mm-badge-pill ' + badgeClass + '">' + esc(badge) + '</span>' : '<span class="mm-muted">-</span>'}</td>
                <td>${m.referral_count || 0}</td>
                <td>${relTime(m.joined_at)}</td>
                <td><span class="mm-status-dot mm-status-${m.status || 'active'}"></span> ${esc(m.status || 'active')}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    // ── Invite Codes Tab ──
    async function loadCodes() {
        const el = document.getElementById('mmCodesBody');
        el.innerHTML = '<div class="mm-loading">Loading codes...</div>';
        const res = await api('list_codes');
        if (!res.ok) { el.innerHTML = '<div class="mm-loading">Error loading codes</div>'; return; }
        const codes = res.codes || [];
        if (!codes.length) { el.innerHTML = '<div class="mm-empty">No invite codes generated yet.</div>'; return; }

        // Split into available and used
        const available = codes.filter(c => !c.used_by);
        const used = codes.filter(c => c.used_by);

        let html = '';
        if (available.length) {
            html += '<h3 class="mm-section-title">Available Codes (' + available.length + ')</h3>';
            html += '<div class="mm-code-grid">';
            available.forEach(c => {
                html += `<div class="mm-code-card">
                    <div class="mm-code-value">${esc(c.code)}</div>
                    <div class="mm-code-meta">
                        <span>Owner: ${esc(c.generated_for || c.generated_by || 'system')}</span>
                        <span>${relTime(c.created_at)}</span>
                    </div>
                    <button class="mm-btn mm-btn-sm mm-btn-copy" data-code="${esc(c.code)}">
                        <i class="fa-solid fa-copy"></i> Copy
                    </button>
                </div>`;
            });
            html += '</div>';
        }

        if (used.length) {
            html += '<h3 class="mm-section-title" style="margin-top:24px">Used Codes (' + used.length + ')</h3>';
            html += '<table class="mm-table"><thead><tr><th>Code</th><th>Used By</th><th>Redeemed</th><th>Owner</th></tr></thead><tbody>';
            used.forEach(c => {
                html += `<tr class="mm-row-muted">
                    <td class="mm-mono mm-strikethrough">${esc(c.code)}</td>
                    <td>${esc(c.used_by || '-')}</td>
                    <td>${relTime(c.used_at)}</td>
                    <td>${esc(c.generated_for || c.generated_by || 'system')}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        }
        el.innerHTML = html;

        // Bind copy buttons
        el.querySelectorAll('.mm-btn-copy').forEach(btn => {
            btn.addEventListener('click', function() {
                const code = this.dataset.code;
                navigator.clipboard.writeText(code).then(() => {
                    this.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
                    setTimeout(() => { this.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; }, 2000);
                });
            });
        });
    }

    // ── Generate Codes Panel (admin generates for a specific user) ──
    async function showGenerateCodesPanel() {
        // Load members for the dropdown
        const res = await api('list_members');
        const members = res.ok ? (res.members || []) : [];

        const overlay = document.getElementById('mmSettingsOverlay');
        const body = document.getElementById('mmSettingsBody');
        const header = overlay.querySelector('.mm-modal-header h2');
        header.textContent = 'Generate Invite Codes';

        let optionsHtml = '<option value="">-- Select a member --</option>';
        optionsHtml += '<option value="__system__">System (unassigned)</option>';
        members.forEach(m => {
            const label = (m.anon_handle || 'Unknown') + (m.email ? ' (' + m.email + ')' : '');
            optionsHtml += `<option value="${esc(m.member_id || m.email || '')}">${esc(label)}</option>`;
        });

        body.innerHTML = `
            <div class="mm-field">
                <label>Generate codes for:</label>
                <div class="mm-search-select">
                    <input type="text" id="mmGenSearch" placeholder="Search members..." class="mm-input">
                    <select id="mmGenUser" class="mm-input" size="1">${optionsHtml}</select>
                </div>
            </div>
            <div class="mm-field">
                <label>Number of codes</label>
                <input type="number" id="mmGenCount" class="mm-input" value="3" min="1" max="20">
            </div>
            <p class="mm-hint">Each code is single-use. Once redeemed, it cannot be used again.</p>
        `;

        // Wire search filter
        const searchInput = document.getElementById('mmGenSearch');
        const select = document.getElementById('mmGenUser');
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            Array.from(select.options).forEach(opt => {
                if (opt.value === '' || opt.value === '__system__') { opt.style.display = ''; return; }
                opt.style.display = opt.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        // Override save button
        const saveBtn = document.getElementById('mmSettingsSave');
        saveBtn.textContent = 'Generate Codes';
        saveBtn.onclick = async function() {
            const userId = document.getElementById('mmGenUser').value;
            const count = parseInt(document.getElementById('mmGenCount').value) || 3;
            if (!userId) { alert('Please select a member or System.'); return; }

            saveBtn.disabled = true;
            saveBtn.textContent = 'Generating...';
            const genRes = await api('generate_codes', 'POST', {
                count: count,
                for_user: userId === '__system__' ? null : userId
            });
            saveBtn.disabled = false;
            saveBtn.textContent = 'Generate Codes';

            if (genRes.ok) {
                const codes = genRes.codes || [];
                body.innerHTML = `
                    <div class="mm-success-box">
                        <i class="fa-solid fa-check-circle"></i>
                        <strong>${codes.length} code(s) generated!</strong>
                    </div>
                    <div class="mm-code-grid">
                        ${codes.map(c => `<div class="mm-code-card">
                            <div class="mm-code-value">${esc(c)}</div>
                            <button class="mm-btn mm-btn-sm mm-btn-copy" data-code="${esc(c)}"><i class="fa-solid fa-copy"></i> Copy</button>
                        </div>`).join('')}
                    </div>
                `;
                body.querySelectorAll('.mm-btn-copy').forEach(btn => {
                    btn.addEventListener('click', function() {
                        navigator.clipboard.writeText(this.dataset.code).then(() => {
                            this.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
                            setTimeout(() => { this.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; }, 2000);
                        });
                    });
                });
                loadStats();
                if (currentTab === 'codes') loadCodes();
            } else {
                alert(genRes.error || 'Failed to generate codes');
            }
        };

        overlay.style.display = '';
    }

    // ── Referral Ledger Tab ──
    async function loadReferrals() {
        const el = document.getElementById('mmReferralsBody');
        el.innerHTML = '<div class="mm-loading">Loading referrals...</div>';
        const res = await api('get_referrals');
        if (!res.ok) { el.innerHTML = '<div class="mm-loading">Error loading referrals</div>'; return; }
        const refs = res.referrals || [];
        if (!refs.length) { el.innerHTML = '<div class="mm-empty">No referrals recorded yet.</div>'; return; }

        let html = '<table class="mm-table"><thead><tr><th>Referrer</th><th>New Member</th><th>Code Used</th><th>Points</th><th>Date</th></tr></thead><tbody>';
        refs.forEach(r => {
            html += `<tr>
                <td>${esc(r.referrer_handle || r.referrer || '-')}</td>
                <td>${esc(r.new_member_handle || r.new_member || '-')}</td>
                <td class="mm-mono">${esc(r.code || '-')}</td>
                <td class="mm-points">+${r.points || 10}</td>
                <td>${relTime(r.timestamp)}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    // ── Leaderboard Tab ──
    async function loadLeaderboard() {
        const el = document.getElementById('mmLeaderboardBody');
        el.innerHTML = '<div class="mm-loading">Loading leaderboard...</div>';
        const res = await api('get_leaderboard');
        if (!res.ok) { el.innerHTML = '<div class="mm-loading">Error loading leaderboard</div>'; return; }
        const board = res.leaderboard || [];
        if (!board.length) { el.innerHTML = '<div class="mm-empty">Leaderboard will populate as members join and earn referrals.</div>'; return; }

        let html = '<div class="mm-leaderboard">';
        board.forEach((entry, i) => {
            const rank = i + 1;
            const rankClass = rank <= 3 ? 'mm-rank-top' : '';
            const badge = entry.badge_tier || '';
            const badgeClass = badge ? 'mm-badge-tier-' + badge.toLowerCase() : '';
            html += `<div class="mm-lb-row ${rankClass}">
                <div class="mm-lb-rank">${rank <= 3 ? ['🥇','🥈','🥉'][rank-1] : '#' + rank}</div>
                <div class="mm-lb-info">
                    <div class="mm-lb-handle">${esc(entry.anon_handle || 'Anonymous')}</div>
                    ${badge ? '<span class="mm-badge-pill ' + badgeClass + '">' + esc(badge) + '</span>' : ''}
                </div>
                <div class="mm-lb-stats">
                    <span class="mm-lb-refs">${entry.referral_count || 0} referrals</span>
                    <span class="mm-lb-points">${entry.referral_points || 0} pts</span>
                </div>
            </div>`;
        });
        html += '</div>';
        el.innerHTML = html;
    }

    // ── Activity Log Tab ──
    async function loadActivity() {
        const el = document.getElementById('mmActivityBody');
        el.innerHTML = '<div class="mm-loading">Loading activity...</div>';
        const res = await api('get_activity');
        if (!res.ok) { el.innerHTML = '<div class="mm-loading">Error loading activity</div>'; return; }
        const logs = res.activity || [];
        if (!logs.length) { el.innerHTML = '<div class="mm-empty">No activity recorded yet.</div>'; return; }

        let html = '<div class="mm-activity-feed">';
        logs.slice(0, 100).forEach(a => {
            const icon = {
                'member_joined': 'fa-user-plus',
                'code_redeemed': 'fa-ticket',
                'code_generated': 'fa-wand-magic-sparkles',
                'referral_earned': 'fa-share-nodes',
                'badge_earned': 'fa-award',
                'settings_changed': 'fa-gear'
            }[a.type] || 'fa-circle-info';
            html += `<div class="mm-activity-item">
                <div class="mm-activity-icon"><i class="fa-solid ${icon}"></i></div>
                <div class="mm-activity-text">${esc(a.message || a.type)}</div>
                <div class="mm-activity-time">${relTime(a.timestamp)}</div>
            </div>`;
        });
        html += '</div>';
        el.innerHTML = html;
    }

    // ── Settings Modal ──
    async function showSettings() {
        const overlay = document.getElementById('mmSettingsOverlay');
        const body = document.getElementById('mmSettingsBody');
        const header = overlay.querySelector('.mm-modal-header h2');
        header.textContent = 'Membership Settings';
        const saveBtn = document.getElementById('mmSettingsSave');
        saveBtn.textContent = 'Save Settings';
        saveBtn.onclick = saveSettings;

        body.innerHTML = '<div class="mm-loading">Loading settings...</div>';
        overlay.style.display = '';

        const res = await api('get_config');
        if (!res.ok) { body.innerHTML = '<div class="mm-loading">Error loading config</div>'; return; }
        const c = res.config;

        body.innerHTML = `
            <div class="mm-field">
                <label class="mm-toggle-label"><input type="checkbox" id="mmCfgEnabled" ${c.enabled ? 'checked' : ''}> Memberships enabled</label>
            </div>
            <div class="mm-field">
                <label>Invite codes per new member</label>
                <input type="number" id="mmCfgCodesPerUser" class="mm-input" value="${c.invite_codes_per_user || 3}" min="1" max="20">
            </div>
            <div class="mm-field">
                <label>Referral points per invite</label>
                <input type="number" id="mmCfgPointsPerRef" class="mm-input" value="${c.referral_points_per_invite || 10}" min="1" max="100">
            </div>
            <div class="mm-field">
                <label>Legal Disclaimer</label>
                <textarea id="mmCfgDisclaimer" class="mm-input mm-textarea" rows="4">${esc(c.disclaimer || '')}</textarea>
            </div>
            <h3 class="mm-section-title" style="margin-top:20px">Hub Forwarding</h3>
            <div class="mm-field">
                <label class="mm-toggle-label"><input type="checkbox" id="mmCfgHubEnabled" ${c.hub_forwarding?.enabled ? 'checked' : ''}> Forward signups to hub</label>
            </div>
            <div class="mm-field">
                <label>Hub URL</label>
                <input type="text" id="mmCfgHubUrl" class="mm-input" value="${esc(c.hub_forwarding?.url || '')}" placeholder="https://hub.example.com">
            </div>
        `;
    }

    async function saveSettings() {
        const payload = {
            enabled: document.getElementById('mmCfgEnabled').checked,
            invite_codes_per_user: parseInt(document.getElementById('mmCfgCodesPerUser').value) || 3,
            referral_points_per_invite: parseInt(document.getElementById('mmCfgPointsPerRef').value) || 10,
            disclaimer: document.getElementById('mmCfgDisclaimer').value.trim(),
            hub_forwarding: {
                enabled: document.getElementById('mmCfgHubEnabled').checked,
                url: document.getElementById('mmCfgHubUrl').value.trim()
            }
        };
        const res = await api('save_config', 'POST', payload);
        if (res.ok) {
            hideSettings();
            loadStats();
        } else {
            alert(res.error || 'Save failed');
        }
    }

    function hideSettings() {
        document.getElementById('mmSettingsOverlay').style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', init);
    return { showSettings, hideSettings, saveSettings };
})();
