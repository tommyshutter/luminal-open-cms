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
<title>Support Tickets — PiP</title>
<link rel="stylesheet" href="/admin/css/admin-pip-panel.css">
<style>
.pip-urgency-critical { color: #ef4444; font-weight: 700; }
.pip-urgency-high { color: #f97316; font-weight: 600; }
.pip-urgency-medium { color: #eab308; }
.pip-urgency-low { color: var(--text-muted); }
.pip-ticket-row { display:flex; gap:8px; align-items:flex-start; padding:8px 10px; border-bottom:1px solid var(--border); cursor:pointer; transition:background 0.15s; }
.pip-ticket-row:hover { background:var(--surface-2); }
.pip-ticket-subject { font-size:0.78rem; font-weight:600; color:var(--text); flex:1; }
.pip-ticket-meta { font-size:0.62rem; color:var(--text-muted); margin-top:2px; }
.pip-ticket-urgency { font-size:0.6rem; text-transform:uppercase; letter-spacing:0.5px; white-space:nowrap; }
.pip-filter-bar { display:flex; gap:6px; padding:6px 10px; border-bottom:1px solid var(--border); align-items:center; }
.pip-filter-bar select { padding:2px 6px; border:1px solid var(--border); border-radius:4px; background:var(--surface); color:var(--text); font-size:0.65rem; font-family:var(--font); }
</style>
</head>
<body class="pip-panel" data-panel-id="tickets">
<div class="pip-panel-header">
    <span class="pip-panel-title">&#127915; Support Tickets</span>
    <span class="pip-panel-status" id="pipStatus">loading...</span>
</div>
<div class="pip-filter-bar">
    <select id="filterStatus" onchange="loadTickets()">
        <option value="active">Active</option>
        <option value="">All</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="resolved">Resolved</option>
    </select>
    <select id="filterUrgency" onchange="loadTickets()">
        <option value="">All Urgency</option>
        <option value="critical">Critical</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
    </select>
</div>
<div class="pip-panel-body" id="pipBody">
    <div class="pip-loading">Loading tickets...</div>
</div>
<script src="/admin/js/admin-pip-panel.js"></script>
<script>
(function(){
var API = '/admin/modules/TechSupport/api.php';

window.loadTickets = function() {
    var status = document.getElementById('filterStatus').value;
    var urgency = document.getElementById('filterUrgency').value;
    var url = API + '?action=list_tickets';
    if (status) url += '&status=' + status;
    if (urgency) url += '&urgency=' + urgency;

    PiPPanel.fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok) { document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#127915;</div>Could not load tickets</div>'; return; }
        var tickets = d.tickets || [];
        if (!tickets.length) { document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#127915;</div>No tickets found</div>'; return; }

        var html = '';
        for (var i = 0; i < tickets.length; i++) {
            var t = tickets[i];
            var urgClass = 'pip-urgency-' + (t.urgency || 'low');
            var timeStr = t.submitted_at ? PiPPanel.timeAgo(t.submitted_at) : '';
            html += '<div class="pip-ticket-row" onclick="PiPPanel.navigateMain(\'/admin/modules/TechSupport/?ticket=' + encodeURIComponent(t.id) + '\');return false">' +
                '<div style="flex:1">' +
                    '<div class="pip-ticket-subject">' + (t.subject || t.id) + '</div>' +
                    '<div class="pip-ticket-meta">' + (t.id || '') + ' &middot; ' + (t.domain || '') + ' &middot; ' + timeStr + '</div>' +
                '</div>' +
                '<div style="text-align:right">' +
                    '<div class="pip-ticket-urgency ' + urgClass + '">' + (t.urgency || 'low') + '</div>' +
                    '<div style="margin-top:3px">' + PiPPanel.statusPill(t.status || 'open') + '</div>' +
                '</div>' +
            '</div>';
        }
        document.getElementById('pipBody').innerHTML = html;
        document.getElementById('pipStatus').textContent = tickets.length + ' tickets';
    })
    .catch(function(e) {
        if (e.message !== 'Session expired')
            document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#9888;</div>Error loading tickets</div>';
    });
};

PiPPanel.autoRefresh(loadTickets, 30000);
})();
</script>
</body>
</html>
