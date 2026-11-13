/**
 * Luminal CMS — PiP Panel Helper
 * Loaded inside each PiP window. Handles communication back to main admin.
 */
(function() {
'use strict';

var CHANNEL_NAME = 'luminal-pip';
var panelId = document.body.getAttribute('data-panel-id') || 'unknown';
var channel = null;

try {
    channel = new BroadcastChannel(CHANNEL_NAME);
    channel.onmessage = function(e) {
        var msg = e.data;
        if (!msg || !msg.type) return;
        if (msg.type === 'pip-heartbeat') {
            channel.postMessage({type: 'pip-heartbeat-reply', panelId: panelId});
        }
        if (msg.type === 'pip-close-all') {
            window.close();
        }
    };
} catch(e) {}

function broadcast(msg) {
    msg.panelId = panelId;
    if (channel) try { channel.postMessage(msg); } catch(e) {}
}

// Notify parent when this window closes
window.addEventListener('beforeunload', function() {
    broadcast({type: 'pip-closed'});
});

// Navigate the main admin window
function navigateMain(url) {
    broadcast({type: 'pip-navigate', url: url});
}

// Send custom event to main window
function sendEvent(eventName, data) {
    broadcast({type: 'pip-event', event: eventName, data: data});
}

// Auto-refresh helper
function autoRefresh(fn, intervalMs) {
    fn();
    return setInterval(fn, intervalMs);
}

// Session check — handle 401 gracefully
function pipFetch(url, opts) {
    return fetch(url, opts).then(function(r) {
        if (r.status === 401 || r.status === 403) {
            document.getElementById('pipBody').innerHTML =
                '<div style="text-align:center;padding:40px 20px;color:var(--text-muted)">' +
                '<div style="font-size:2rem;margin-bottom:8px">&#128274;</div>' +
                '<div style="font-weight:700;margin-bottom:4px">Session Expired</div>' +
                '<div style="font-size:0.8rem">Close this window and log in again.</div></div>';
            throw new Error('Session expired');
        }
        return r;
    });
}

// Format relative time
function timeAgo(dateStr) {
    var d = new Date(dateStr);
    var now = new Date();
    var diff = Math.floor((now - d) / 1000);
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

// Countdown timer
function countdown(targetDate) {
    var now = new Date();
    var target = new Date(targetDate);
    var diff = Math.max(0, Math.floor((target - now) / 1000));
    if (diff === 0) return 'now';
    var h = Math.floor(diff / 3600);
    var m = Math.floor((diff % 3600) / 60);
    var s = diff % 60;
    if (h > 24) return Math.floor(h/24) + 'd ' + (h%24) + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    if (m > 0) return m + 'm ' + s + 's';
    return s + 's';
}

// Status pill HTML
function statusPill(status) {
    var colors = {
        active: '#0d9c5c', running: '#3b82f6', completed: '#0d9c5c',
        failed: '#d92b2b', paused: '#f59e0b', pending: '#8e95a3',
        open: '#3b82f6', 'in-progress': '#f59e0b', resolved: '#0d9c5c',
        closed: '#8e95a3', scheduled: '#6366f1', idle: '#8e95a3',
        disabled: '#8e95a3'
    };
    var c = colors[status] || '#8e95a3';
    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.68rem;font-weight:600;color:' + c + '">' +
           '<span style="width:6px;height:6px;border-radius:50%;background:' + c + ';display:inline-block"></span>' +
           status + '</span>';
}

// Public API for panel scripts
window.PiPPanel = {
    id: panelId,
    navigateMain: navigateMain,
    sendEvent: sendEvent,
    autoRefresh: autoRefresh,
    fetch: pipFetch,
    timeAgo: timeAgo,
    countdown: countdown,
    statusPill: statusPill,
    broadcast: broadcast,
};

})();
