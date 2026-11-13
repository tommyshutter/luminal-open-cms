<?php
/**
 * Luminal CMS — Community Manager
 *
 * Admin panel: Members, Comments moderation, Settings.
 *
 * @package    LuminalCMS
 * @module     CommunityManager
 * @version    1.0.0
 * @file       /admin/modules/CommunityManager/CommunityManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/CommunityManager/css/community-manager.css') ?>">

<div class="cm-wrap">

    <!-- Header -->
    <div class="cm-header">
        <h1 class="cm-title">Community Manager</h1>
    </div>

    <!-- Tabs -->
    <div class="cm-tabs">
        <button class="cm-tab cm-tab--active" data-tab="members" onclick="cmSwitchTab('members', this)">Members</button>
        <button class="cm-tab" data-tab="comments" onclick="cmSwitchTab('comments', this)">Comments</button>
        <button class="cm-tab" data-tab="settings" onclick="cmSwitchTab('settings', this)">Settings</button>
    </div>

    <!-- Tab: Members ─────────────────────────────────────────────────────── -->
    <div class="cm-panel" id="cm-panel-members">

        <div class="cm-panel-header">
            <span class="cm-panel-count" id="cm-member-count">Loading…</span>
        </div>

        <div class="cm-table-wrap">
            <div class="cm-table-head cm-members-grid">
                <div>Name</div>
                <div>Email</div>
                <div>Tier</div>
                <div>Joined</div>
                <div>Posts</div>
                <div>Status</div>
                <div>Actions</div>
            </div>
            <div id="cm-member-list">
                <div class="cm-loading">Loading members…</div>
            </div>
        </div>

        <!-- Edit member flyout -->
        <div class="cm-flyout" id="cm-member-flyout" style="display:none">
            <div class="cm-flyout-header">
                <h3 class="cm-flyout-title" id="cm-member-flyout-title">Edit Member</h3>
                <button class="cm-btn cm-btn--ghost" onclick="cmCloseMemberEdit()">✕</button>
            </div>
            <form id="cm-member-form" onsubmit="cmSaveMember(event)">
                <input type="hidden" id="cm-mf-id" name="member_id">
                <div class="cm-field">
                    <label class="cm-label">Display Name</label>
                    <input type="text" id="cm-mf-name" name="display_name" class="cm-input">
                </div>
                <div class="cm-field">
                    <label class="cm-label">Tier</label>
                    <select id="cm-mf-tier" name="tier" class="cm-input">
                        <option value="free">Member (Free)</option>
                        <option value="supporter">Supporter</option>
                        <option value="vip">VIP</option>
                    </select>
                </div>
                <div class="cm-field cm-field--check">
                    <label class="cm-check-label">
                        <input type="checkbox" id="cm-mf-banned" name="banned" value="1">
                        <span>Banned</span>
                    </label>
                </div>
                <div class="cm-form-actions">
                    <button type="submit" class="cm-btn cm-btn--amber">Save</button>
                    <button type="button" class="cm-btn cm-btn--ghost" onclick="cmCloseMemberEdit()">Cancel</button>
                    <span class="cm-save-status" id="cm-member-status"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab: Comments ────────────────────────────────────────────────────── -->
    <div class="cm-panel" id="cm-panel-comments" style="display:none">

        <div class="cm-panel-header">
            <div class="cm-filter-row">
                <label class="cm-label">Filter:</label>
                <select id="cm-comment-filter" class="cm-input cm-input--sm" onchange="cmLoadComments()">
                    <option value="all">All</option>
                    <option value="visible">Visible</option>
                    <option value="hidden">Hidden</option>
                    <option value="redacted">Redacted</option>
                </select>
            </div>
            <span class="cm-panel-count" id="cm-comment-count"></span>
        </div>

        <div class="cm-table-wrap">
            <div class="cm-table-head cm-comments-grid">
                <div>Thread</div>
                <div>Member</div>
                <div>Comment</div>
                <div>Date</div>
                <div>Status</div>
                <div>Action</div>
            </div>
            <div id="cm-comment-list">
                <div class="cm-loading">Loading comments…</div>
            </div>
        </div>
    </div>

    <!-- Tab: Settings ────────────────────────────────────────────────────── -->
    <div class="cm-panel" id="cm-panel-settings" style="display:none">
        <form id="cm-settings-form" onsubmit="cmSaveSettings(event)">

            <div class="cm-settings-grid">

                <div class="cm-field">
                    <label class="cm-label">Community Name</label>
                    <input type="text" id="cm-s-name" name="community_name" class="cm-input" placeholder="Community">
                </div>

                <div class="cm-field cm-field--check">
                    <label class="cm-check-label">
                        <input type="checkbox" id="cm-s-tiers" name="tiers_enabled" value="1">
                        <span>Enable Paid Tiers</span>
                    </label>
                </div>

                <div class="cm-field cm-field--check">
                    <label class="cm-check-label">
                        <input type="checkbox" id="cm-s-open" name="registration_open" value="1">
                        <span>Registration Open</span>
                    </label>
                </div>

                <div class="cm-field cm-field--check">
                    <label class="cm-check-label">
                        <input type="checkbox" id="cm-s-verify" name="require_email_verify" value="1">
                        <span>Require Email Verification</span>
                    </label>
                </div>

                <div class="cm-field">
                    <label class="cm-label">Who Can Comment</label>
                    <select id="cm-s-who" name="who_can_comment" class="cm-input">
                        <option value="members">Members only</option>
                        <option value="all">Everyone (including guests)</option>
                    </select>
                </div>

            </div>

            <!-- Tier config -->
            <div class="cm-section">
                <h3 class="cm-section-title">Tier Configuration</h3>
                <div id="cm-tier-rows" class="cm-tier-list"></div>
            </div>

            <!-- Content rules -->
            <div class="cm-section">
                <h3 class="cm-section-title">Content Rules</h3>
                <div class="cm-settings-grid">

                    <div class="cm-field">
                        <label class="cm-label">Allowed Link Domains <small style="font-weight:400;text-transform:none">(one per line — approved users only)</small></label>
                        <textarea id="cm-cr-domains" class="cm-input" rows="4" placeholder="instagram.com&#10;facebook.com&#10;youtube.com&#10;youtu.be"></textarea>
                    </div>

                    <div class="cm-field">
                        <label class="cm-label">Free-Tier Max Images</label>
                        <input type="number" id="cm-cr-maximg" class="cm-input" min="0" max="10" value="1">
                        <small class="cm-hint">Images free members can embed per comment (0 = none)</small>
                    </div>

                    <div class="cm-field">
                        <label class="cm-label">Approved Tier Minimum</label>
                        <select id="cm-cr-aptier" class="cm-input">
                            <option value="supporter">Supporter</option>
                            <option value="vip">VIP</option>
                        </select>
                        <small class="cm-hint">Members at this tier or above may post links without the "Approved" flag</small>
                    </div>

                </div>
            </div>

            <div class="cm-form-actions">
                <button type="submit" class="cm-btn cm-btn--amber">Save Settings</button>
                <span class="cm-save-status" id="cm-settings-status"></span>
            </div>

        </form>
    </div>

</div><!-- /cm-wrap -->

<script>
(function() {
    'use strict';

    const API = '/admin/modules/CommunityManager/api.php';
    let _config = {};

    // ── Init ──────────────────────────────────────────────────────────────
    function init() {
        cmLoadMembers();
        loadConfig();
    }

    // ── Tab switching ─────────────────────────────────────────────────────
    window.cmSwitchTab = function(tab, btn) {
        document.querySelectorAll('.cm-panel').forEach(p => p.style.display = 'none');
        document.querySelectorAll('.cm-tab').forEach(t => t.classList.remove('cm-tab--active'));
        document.getElementById('cm-panel-' + tab).style.display = 'block';
        btn.classList.add('cm-tab--active');
        if (tab === 'comments') cmLoadComments();
        if (tab === 'settings') loadConfig();
    };

    // ── Members ───────────────────────────────────────────────────────────
    function cmLoadMembers() {
        fetch(API + '?action=list_members')
            .then(r => r.json())
            .then(d => {
                if (!d.success) { renderMemberError(d.error); return; }
                const members = d.members || [];
                document.getElementById('cm-member-count').textContent = members.length + ' member' + (members.length !== 1 ? 's' : '');
                renderMemberList(members);
            })
            .catch(e => renderMemberError(e.message));
    }

    function renderMemberList(members) {
        const el = document.getElementById('cm-member-list');
        if (!members.length) {
            el.innerHTML = '<div class="cm-empty">No members yet.</div>';
            return;
        }
        el.innerHTML = members.map(m => {
            const joined = m.joined_at ? new Date(m.joined_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—';
            const tierColor = tierColorFor(m.tier);
            return `
            <div class="cm-row cm-members-grid">
                <div><span class="cm-member-name">${esc(m.display_name)}</span></div>
                <div class="cm-muted cm-sm">${esc(m.email)}</div>
                <div><span class="cm-tier-badge" style="background:${tierColor}22;color:${tierColor};border-color:${tierColor}44">${esc(m.tier)}</span></div>
                <div class="cm-muted cm-sm">${joined}</div>
                <div class="cm-muted cm-sm">${m.post_count}</div>
                <div><span class="cm-badge ${m.banned ? 'cm-badge--banned' : 'cm-badge--active'}">${m.banned ? 'Banned' : 'Active'}</span></div>
                <div class="cm-actions">
                    <button class="cm-btn cm-btn--sm cm-btn--ghost" onclick="cmEditMember(${JSON.stringify(m)})">Edit</button>
                    <button class="cm-btn cm-btn--sm ${m.banned ? 'cm-btn--amber' : 'cm-btn--danger'}" onclick="cmToggleBan('${m.id}', ${m.banned})">${m.banned ? 'Unban' : 'Ban'}</button>
                </div>
            </div>`;
        }).join('');
    }

    function renderMemberError(msg) {
        document.getElementById('cm-member-list').innerHTML = '<div class="cm-error">Error: ' + esc(msg) + '</div>';
    }

    window.cmEditMember = function(m) {
        document.getElementById('cm-member-flyout-title').textContent = 'Edit: ' + m.display_name;
        document.getElementById('cm-mf-id').value   = m.id;
        document.getElementById('cm-mf-name').value = m.display_name;
        document.getElementById('cm-mf-tier').value = m.tier;
        document.getElementById('cm-mf-banned').checked = !!m.banned;
        document.getElementById('cm-member-status').textContent = '';
        document.getElementById('cm-member-flyout').style.display = 'block';
    };

    window.cmCloseMemberEdit = function() {
        document.getElementById('cm-member-flyout').style.display = 'none';
    };

    window.cmSaveMember = function(e) {
        e.preventDefault();
        const status = document.getElementById('cm-member-status');
        status.textContent = 'Saving…';
        const form = document.getElementById('cm-member-form');
        const payload = {
            action:       'update_member',
            member_id:    form.member_id.value,
            display_name: form.display_name.value.trim(),
            tier:         form.tier.value,
            banned:       form.banned.checked,
        };
        apiPost(payload).then(d => {
            if (!d.success) { status.textContent = '✗ ' + (d.error || 'Error'); return; }
            status.textContent = '✓ Saved';
            cmLoadMembers();
            setTimeout(() => { cmCloseMemberEdit(); }, 800);
        });
    };

    window.cmToggleBan = function(member_id, currently_banned) {
        apiPost({ action: 'update_member', member_id, banned: !currently_banned })
            .then(d => { if (d.success) cmLoadMembers(); });
    };

    // ── Comments ──────────────────────────────────────────────────────────
    window.cmLoadComments = function() {
        const filter = document.getElementById('cm-comment-filter').value;
        fetch(API + '?action=list_comments&status=' + encodeURIComponent(filter))
            .then(r => r.json())
            .then(d => {
                if (!d.success) { document.getElementById('cm-comment-list').innerHTML = '<div class="cm-error">' + esc(d.error) + '</div>'; return; }
                const comments = d.comments || [];
                document.getElementById('cm-comment-count').textContent = comments.length + ' comment' + (comments.length !== 1 ? 's' : '');
                renderCommentList(comments);
            });
    };

    function renderCommentList(comments) {
        const el = document.getElementById('cm-comment-list');
        if (!comments.length) {
            el.innerHTML = '<div class="cm-empty">No comments found.</div>';
            return;
        }
        el.innerHTML = comments.map(c => {
            const date = c.posted_at ? new Date(c.posted_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—';
            const snippet = esc(c.body.substring(0, 120)) + (c.body.length > 120 ? '…' : '');
            return `
            <div class="cm-row cm-comments-grid">
                <div class="cm-muted cm-sm">${esc(c.thread_id)}</div>
                <div class="cm-sm">${esc(c.display_name)}</div>
                <div class="cm-comment-body cm-sm">${snippet}</div>
                <div class="cm-muted cm-sm">${date}</div>
                <div><span class="cm-badge cm-badge--${c.status}">${c.status}</span></div>
                <div>
                    <select class="cm-input cm-input--xs" onchange="cmModerate('${esc(c.id)}','${esc(c.thread_id)}',this.value,this)" data-cid="${esc(c.id)}">
                        <option value="">Moderate…</option>
                        <option value="visible">Visible</option>
                        <option value="hidden">Hidden</option>
                        <option value="redacted">Redacted</option>
                    </select>
                </div>
            </div>`;
        }).join('');
    }

    window.cmModerate = function(comment_id, thread_id, status, el) {
        if (!status) return;
        apiPost({ action: 'moderate_comment', comment_id, thread_id, status })
            .then(d => {
                el.value = '';
                if (d.success) cmLoadComments();
                else alert('Error: ' + d.error);
            });
    };

    // ── Settings ──────────────────────────────────────────────────────────
    function loadConfig() {
        fetch(API + '?action=get_config')
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                _config = d.config;
                renderSettings(_config);
            });
    }

    function renderSettings(cfg) {
        document.getElementById('cm-s-name').value  = cfg.community_name || '';
        document.getElementById('cm-s-tiers').checked = !!cfg.tiers_enabled;
        document.getElementById('cm-s-open').checked  = !!cfg.registration_open;
        document.getElementById('cm-s-verify').checked = !!cfg.require_email_verify;
        document.getElementById('cm-s-who').value = cfg.who_can_comment || 'members';
        renderTierRows(cfg.tiers || []);
        // Content rules
        const cr = cfg.content_rules || {};
        document.getElementById('cm-cr-domains').value = (cr.allowed_link_domains || ['instagram.com','facebook.com','youtube.com','youtu.be']).join('\n');
        document.getElementById('cm-cr-maximg').value  = cr.free_tier_max_images ?? 1;
        document.getElementById('cm-cr-aptier').value  = cr.approved_tier_min    || 'supporter';
    }

    function renderTierRows(tiers) {
        const el = document.getElementById('cm-tier-rows');
        el.innerHTML = tiers.map((t, i) => `
            <div class="cm-tier-row">
                <input type="text" class="cm-input cm-input--sm" placeholder="Tier ID" value="${esc(t.id)}" oninput="updateTier(${i},'id',this.value)">
                <input type="text" class="cm-input cm-input--sm" placeholder="Name" value="${esc(t.name)}" oninput="updateTier(${i},'name',this.value)">
                <input type="number" class="cm-input cm-input--sm" placeholder="$/mo" value="${t.price_monthly}" oninput="updateTier(${i},'price_monthly',parseFloat(this.value)||0)" min="0" step="0.01">
                <input type="color" class="cm-color-input" value="${t.color}" oninput="updateTier(${i},'color',this.value)">
            </div>
        `).join('');
    }

    window.updateTier = function(i, key, val) {
        if (!_config.tiers) return;
        _config.tiers[i][key] = val;
    };

    window.cmSaveSettings = function(e) {
        e.preventDefault();
        const status = document.getElementById('cm-settings-status');
        const form = document.getElementById('cm-settings-form');
        status.textContent = 'Saving…';
        const payload = {
            action:                'save_config',
            community_name:        form.community_name.value.trim(),
            tiers_enabled:         form.tiers_enabled.checked,
            registration_open:     form.registration_open.checked,
            require_email_verify:  form.require_email_verify.checked,
            who_can_comment:       form.who_can_comment.value,
            tiers:                 _config.tiers || [],
            content_rules: {
                allowed_link_domains: document.getElementById('cm-cr-domains').value.split('\n').map(s => s.trim()).filter(Boolean),
                free_tier_max_images: parseInt(document.getElementById('cm-cr-maximg').value, 10) || 0,
                approved_tier_min:    document.getElementById('cm-cr-aptier').value,
            },
        };
        apiPost(payload).then(d => {
            status.textContent = d.success ? '✓ Saved' : '✗ ' + (d.error || 'Error');
            status.className = 'cm-save-status ' + (d.success ? 'cm-save-status--ok' : 'cm-save-status--error');
        });
    };

    // ── Helpers ───────────────────────────────────────────────────────────
    function apiPost(payload) {
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json());
    }

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function tierColorFor(tier) {
        if (!_config.tiers) return '#6b7280';
        const t = (_config.tiers || []).find(x => x.id === tier);
        return t ? t.color : '#6b7280';
    }

    document.addEventListener('DOMContentLoaded', init);
})();
</script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
