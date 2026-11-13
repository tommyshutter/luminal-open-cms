<?php
if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 4));
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Members — PiP</title>
<link rel="stylesheet" href="/admin/css/admin-pip-panel.css">
<style>
.pip-member-row { display:flex; gap:8px; align-items:center; padding:8px 10px; border-bottom:1px solid var(--border); cursor:pointer; transition:background 0.15s; }
.pip-member-row:hover { background:var(--surface-2); }
.pip-member-avatar { width:28px; height:28px; border-radius:50%; background:var(--surface-2); display:flex; align-items:center; justify-content:center; font-size:0.7rem; color:var(--text-muted); flex-shrink:0; }
.pip-member-name { font-size:0.78rem; font-weight:600; color:var(--text); }
.pip-member-meta { font-size:0.62rem; color:var(--text-muted); }
.pip-member-badge-free { font-size:0.55rem; padding:1px 5px; border-radius:3px; background:rgba(100,116,139,0.2); color:#94a3b8; text-transform:uppercase; }
.pip-member-badge-paid { font-size:0.55rem; padding:1px 5px; border-radius:3px; background:rgba(234,179,8,0.2); color:#facc15; text-transform:uppercase; }
.pip-stat-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; padding:8px 10px; border-bottom:1px solid var(--border); }
.pip-stat-card { background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:6px; text-align:center; }
.pip-stat-label { font-size:0.55rem; color:var(--text-muted); text-transform:uppercase; }
.pip-stat-value { font-size:1rem; font-weight:700; color:var(--gold); margin-top:2px; }
</style>
</head>
<body class="pip-panel" data-panel-id="members">
<div class="pip-panel-header">
    <span class="pip-panel-title">&#128100; Members</span>
    <span class="pip-panel-status" id="pipStatus">loading...</span>
</div>
<div class="pip-panel-body" id="pipBody">
    <div class="pip-loading">Loading members...</div>
</div>
<script src="/admin/js/admin-pip-panel.js"></script>
<script>
(function(){
var API = '/admin/modules/MembershipManager/api.php';

function loadMembers() {
    PiPPanel.fetch(API + '?action=list_members')
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok) { document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#128100;</div>Could not load members</div>'; return; }
        var members = d.members || [];
        var stats = d.stats || {};

        var html = '';

        // Stats
        html += '<div class="pip-stat-row">' +
            '<div class="pip-stat-card"><div class="pip-stat-label">Total</div><div class="pip-stat-value">' + (stats.total || members.length) + '</div></div>' +
            '<div class="pip-stat-card"><div class="pip-stat-label">Paid</div><div class="pip-stat-value">' + (stats.paid || 0) + '</div></div>' +
            '<div class="pip-stat-card"><div class="pip-stat-label">Free</div><div class="pip-stat-value">' + (stats.free || 0) + '</div></div>' +
        '</div>';

        if (!members.length) {
            html += '<div class="pip-empty"><div class="pip-empty-icon">&#128100;</div>No members yet</div>';
        } else {
            for (var i = 0; i < members.length; i++) {
                var m = members[i];
                var initials = ((m.name || m.anon_handle || '?')[0] || '?').toUpperCase();
                var badgeClass = m.membership_type === 'paid' ? 'pip-member-badge-paid' : 'pip-member-badge-free';
                var joinedStr = m.joined_at ? PiPPanel.timeAgo(m.joined_at) : '';
                html += '<div class="pip-member-row" onclick="PiPPanel.navigateMain(\'/admin/modules/MembershipManager/?member=' + encodeURIComponent(m.id || '') + '\');return false">' +
                    '<div class="pip-member-avatar">' + initials + '</div>' +
                    '<div style="flex:1">' +
                        '<div class="pip-member-name">' + (m.name || m.anon_handle || 'Member') + '</div>' +
                        '<div class="pip-member-meta">' + (m.email || '') + ' &middot; ' + joinedStr + '</div>' +
                    '</div>' +
                    '<span class="' + badgeClass + '">' + (m.membership_type || 'free') + '</span>' +
                '</div>';
            }
        }
        document.getElementById('pipBody').innerHTML = html;
        document.getElementById('pipStatus').textContent = (stats.total || members.length) + ' members';
    })
    .catch(function(e) {
        if (e.message !== 'Session expired')
            document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#9888;</div>Error loading members</div>';
    });
}

PiPPanel.autoRefresh(loadMembers, 60000);
})();
</script>
</body>
</html>
