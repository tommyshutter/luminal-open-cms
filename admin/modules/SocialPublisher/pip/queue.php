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
<title>Social Queue — PiP</title>
<link rel="stylesheet" href="/admin/css/admin-pip-panel.css">
<style>
.pip-post-row { padding:8px 10px; border-bottom:1px solid var(--border); }
.pip-post-content { font-size:0.75rem; color:var(--text); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.pip-post-meta { font-size:0.62rem; color:var(--text-muted); margin-top:4px; display:flex; gap:6px; align-items:center; }
.pip-provider-badge { font-size:0.55rem; padding:1px 5px; border-radius:3px; text-transform:uppercase; letter-spacing:0.5px; }
.pip-provider-facebook { background:rgba(59,130,246,0.15); color:#60a5fa; }
.pip-provider-twitter { background:rgba(96,165,250,0.15); color:#93c5fd; }
.pip-provider-x { background:rgba(96,165,250,0.15); color:#93c5fd; }
.pip-post-status-posted { color:var(--gain); }
.pip-post-status-failed { color:var(--loss); }
.pip-post-status-draft { color:var(--warn); }
.pip-provider-section { padding:8px 10px; border-bottom:1px solid var(--border); }
.pip-provider-card { display:flex; align-items:center; gap:8px; padding:6px 0; }
.pip-provider-name { font-size:0.75rem; font-weight:600; color:var(--text); }
.pip-provider-status { font-size:0.62rem; }
</style>
</head>
<body class="pip-panel" data-panel-id="social">
<div class="pip-panel-header">
    <span class="pip-panel-title">&#128226; Social Queue</span>
    <span class="pip-panel-status" id="pipStatus">loading...</span>
</div>
<div class="pip-panel-body" id="pipBody">
    <div class="pip-loading">Loading social queue...</div>
</div>
<script src="/admin/js/admin-pip-panel.js"></script>
<script>
(function(){
var API = '/admin/modules/SocialPublisher/api.php';

function loadQueue() {
    Promise.all([
        PiPPanel.fetch(API + '?action=list_providers').then(function(r){ return r.json(); }).catch(function(){ return {ok:false}; }),
        PiPPanel.fetch(API + '?action=list_posts&per_page=15').then(function(r){ return r.json(); }).catch(function(){ return {ok:false}; })
    ])
    .then(function(results) {
        var provData = results[0];
        var postData = results[1];
        var html = '';

        // Providers section
        var providers = (provData.ok ? provData.providers : null) || [];
        html += '<div class="pip-provider-section">';
        html += '<div style="font-size:0.62rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:6px">Connected Accounts</div>';
        if (!providers.length) {
            html += '<div style="font-size:0.72rem;color:var(--text-muted)">No social accounts configured. <a href="#" onclick="PiPPanel.navigateMain(\'/admin/modules/SocialPublisher/\');return false" style="color:var(--info)">Set up accounts</a></div>';
        } else {
            for (var i = 0; i < providers.length; i++) {
                var prov = providers[i];
                var platClass = 'pip-provider-' + (prov.platform || 'x');
                var statusColor = prov.enabled ? 'var(--gain)' : 'var(--text-muted)';
                html += '<div class="pip-provider-card">' +
                    '<span class="pip-provider-badge ' + platClass + '">' + (prov.platform || 'unknown') + '</span>' +
                    '<span class="pip-provider-name">' + (prov.name || prov.id) + '</span>' +
                    '<span class="pip-provider-status" style="color:' + statusColor + '">' + (prov.enabled ? 'Active' : 'Disabled') + '</span>' +
                '</div>';
            }
        }
        html += '</div>';

        // Recent posts
        var posts = (postData.ok ? postData.posts : null) || [];
        html += '<div style="padding:6px 10px;font-size:0.62rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;border-bottom:1px solid var(--border)">Recent Posts</div>';
        if (!posts.length) {
            html += '<div class="pip-empty"><div class="pip-empty-icon">&#128226;</div>No posts yet</div>';
        } else {
            for (var j = 0; j < posts.length; j++) {
                var post = posts[j];
                var statusClass = 'pip-post-status-' + (post.status || 'draft');
                var timeStr = post.created_at ? PiPPanel.timeAgo(post.created_at) : '';
                var provBadges = '';
                if (post.providers && post.providers.length) {
                    for (var k = 0; k < post.providers.length; k++) {
                        provBadges += '<span class="pip-provider-badge pip-provider-' + post.providers[k] + '">' + post.providers[k] + '</span> ';
                    }
                }
                html += '<div class="pip-post-row">' +
                    '<div class="pip-post-content">' + ((post.content || '').substring(0, 120)) + '</div>' +
                    '<div class="pip-post-meta">' +
                        provBadges +
                        '<span class="' + statusClass + '">' + (post.status || 'draft') + '</span>' +
                        '<span>' + timeStr + '</span>' +
                    '</div>' +
                '</div>';
            }
        }

        document.getElementById('pipBody').innerHTML = html;
        document.getElementById('pipStatus').textContent = posts.length + ' posts';
    })
    .catch(function(e) {
        if (e.message !== 'Session expired')
            document.getElementById('pipBody').innerHTML = '<div class="pip-empty"><div class="pip-empty-icon">&#9888;</div>Error loading social queue</div>';
    });
}

PiPPanel.autoRefresh(loadQueue, 30000);
})();
</script>
</body>
</html>
