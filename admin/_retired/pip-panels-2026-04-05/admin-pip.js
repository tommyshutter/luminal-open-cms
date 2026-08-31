/**
 * Luminal CMS — Admin PiP (Picture-in-Picture) Framework
 * Multi-window layout manager with crash recovery and layout persistence.
 * @version 1.0.0
 */
(function() {
'use strict';

var CHANNEL_NAME = 'luminal-pip';
var LS_SESSION   = 'pip_session_active';
var LS_LAYOUT    = 'pip_layout_state';
var LS_NAMED     = 'pip_named_layouts';
var HEARTBEAT_MS = 2000;
var SAVE_DEBOUNCE = 500;

var _windows = {};       // panelId -> {ref, url, x, y, w, h, opts}
var _registry = {};      // panelId -> {label, icon, url, defaultWidth, defaultHeight, group, description}
var _channel = null;
var _heartbeatTimer = null;
var _saveTimer = null;
var _positionPollTimer = null;

// BroadcastChannel setup
try {
    _channel = new BroadcastChannel(CHANNEL_NAME);
    _channel.onmessage = function(e) {
        var msg = e.data;
        if (!msg || !msg.type) return;
        switch (msg.type) {
            case 'pip-heartbeat-reply':
                if (_windows[msg.panelId]) _windows[msg.panelId]._alive = true;
                break;
            case 'pip-navigate':
                if (msg.url) window.location.href = msg.url;
                break;
            case 'pip-event':
                var evt = new CustomEvent('pip:' + msg.event, {detail: msg.data});
                document.dispatchEvent(evt);
                break;
            case 'pip-closed':
                if (_windows[msg.panelId]) {
                    delete _windows[msg.panelId];
                    _saveLayout();
                    _updateLauncherState();
                }
                break;
        }
    };
} catch (e) {
    // BroadcastChannel not supported — degrade gracefully
}

function _broadcast(msg) {
    if (_channel) try { _channel.postMessage(msg); } catch(e) {}
}

// ── Window Management ──

function openPip(panelId, url, opts) {
    opts = opts || {};
    var reg = _registry[panelId] || {};
    var w = opts.width  || reg.defaultWidth  || 420;
    var h = opts.height || reg.defaultHeight || 500;
    var x = opts.x != null ? opts.x : (screen.width - w - 40);
    var y = opts.y != null ? opts.y : 60;

    // If already open, focus it
    if (_windows[panelId] && !_windows[panelId].ref.closed) {
        _windows[panelId].ref.focus();
        return _windows[panelId].ref;
    }

    url = url || reg.url;
    if (!url) { console.warn('PiP: No URL for panel ' + panelId); return null; }

    var features = 'width=' + w + ',height=' + h + ',left=' + x + ',top=' + y +
                   ',resizable=yes,scrollbars=yes,status=no,menubar=no,toolbar=no,location=no';
    var ref = window.open(url, 'pip_' + panelId, features);
    if (!ref) {
        _showToast('Popup blocked! Allow popups for this site to use PiP panels.', 'warn');
        return null;
    }

    _windows[panelId] = {ref: ref, url: url, x: x, y: y, w: w, h: h, opts: opts, _alive: true};
    localStorage.setItem(LS_SESSION, 'true');
    _saveLayout();
    _updateLauncherState();
    _startPolling();
    return ref;
}

function closePip(panelId) {
    if (_windows[panelId] && !_windows[panelId].ref.closed) {
        _windows[panelId].ref.close();
    }
    delete _windows[panelId];
    _saveLayout();
    _updateLauncherState();
}

function closeAll() {
    Object.keys(_windows).forEach(function(id) {
        if (_windows[id].ref && !_windows[id].ref.closed) _windows[id].ref.close();
    });
    _windows = {};
    localStorage.removeItem(LS_SESSION);
    localStorage.removeItem(LS_LAYOUT);
    _updateLauncherState();
}

function getOpen() {
    return Object.keys(_windows).filter(function(id) {
        return _windows[id].ref && !_windows[id].ref.closed;
    });
}

function isOpen(panelId) {
    return _windows[panelId] && !_windows[panelId].ref.closed;
}

function togglePip(panelId) {
    if (isOpen(panelId)) {
        closePip(panelId);
    } else {
        openPip(panelId);
    }
}

// ── Panel Registry ──

function register(panelId, config) {
    _registry[panelId] = config;
}

function getRegistry() {
    return Object.assign({}, _registry);
}

// ── Layout Persistence ──

function _saveLayout() {
    if (_saveTimer) clearTimeout(_saveTimer);
    _saveTimer = setTimeout(function() {
        var layout = {};
        Object.keys(_windows).forEach(function(id) {
            var w = _windows[id];
            if (w.ref && !w.ref.closed) {
                try {
                    layout[id] = {
                        url: w.url,
                        x: w.ref.screenX || w.x,
                        y: w.ref.screenY || w.y,
                        w: w.ref.outerWidth || w.w,
                        h: w.ref.outerHeight || w.h,
                    };
                } catch(e) {
                    layout[id] = {url: w.url, x: w.x, y: w.y, w: w.w, h: w.h};
                }
            }
        });
        if (Object.keys(layout).length > 0) {
            localStorage.setItem(LS_LAYOUT, JSON.stringify(layout));
            localStorage.setItem(LS_SESSION, 'true');
        } else {
            localStorage.removeItem(LS_LAYOUT);
            localStorage.removeItem(LS_SESSION);
        }
    }, SAVE_DEBOUNCE);
}

function saveNamedLayout(name) {
    // Save current layout to server
    var layout = {};
    Object.keys(_windows).forEach(function(id) {
        var w = _windows[id];
        if (w.ref && !w.ref.closed) {
            try {
                layout[id] = {
                    url: w.url,
                    x: w.ref.screenX || w.x,
                    y: w.ref.screenY || w.y,
                    w: w.ref.outerWidth || w.w,
                    h: w.ref.outerHeight || w.h,
                };
            } catch(e) {
                layout[id] = {url: w.url, x: w.x, y: w.y, w: w.w, h: w.h};
            }
        }
    });
    // Save locally
    var named = {};
    try { named = JSON.parse(localStorage.getItem(LS_NAMED) || '{}'); } catch(e) {}
    named[name] = {layout: layout, saved_at: new Date().toISOString()};
    localStorage.setItem(LS_NAMED, JSON.stringify(named));

    // Save to server
    fetch('/admin/api/pip-layout-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'save_layout', name: name, layout: layout})
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) _showToast('Layout "' + name + '" saved', 'ok');
        else _showToast('Save failed: ' + (d.error || 'unknown'), 'warn');
    }).catch(function() { _showToast('Layout saved locally only', 'ok'); });
}

function loadNamedLayout(name) {
    var named = {};
    try { named = JSON.parse(localStorage.getItem(LS_NAMED) || '{}'); } catch(e) {}
    if (named[name]) {
        _restoreLayout(named[name].layout);
        return;
    }
    // Try server
    fetch('/admin/api/pip-layout-api.php?action=load_layout&name=' + encodeURIComponent(name))
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok && d.layout) _restoreLayout(d.layout);
        else _showToast('Layout not found', 'warn');
    }).catch(function() { _showToast('Could not load layout', 'warn'); });
}

function listNamedLayouts() {
    var named = {};
    try { named = JSON.parse(localStorage.getItem(LS_NAMED) || '{}'); } catch(e) {}
    return Object.keys(named);
}

function deleteNamedLayout(name) {
    var named = {};
    try { named = JSON.parse(localStorage.getItem(LS_NAMED) || '{}'); } catch(e) {}
    delete named[name];
    localStorage.setItem(LS_NAMED, JSON.stringify(named));
    fetch('/admin/api/pip-layout-api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete_layout', name: name})
    }).catch(function() {});
}

function _restoreLayout(layout) {
    // Close existing first
    Object.keys(_windows).forEach(function(id) {
        if (_windows[id].ref && !_windows[id].ref.closed) _windows[id].ref.close();
    });
    _windows = {};

    // Open each panel from layout
    var delay = 0;
    Object.keys(layout).forEach(function(id) {
        var l = layout[id];
        setTimeout(function() {
            openPip(id, l.url, {x: l.x, y: l.y, width: l.w, height: l.h});
        }, delay);
        delay += 200; // stagger to avoid popup blocker
    });
}

// ── Position Polling (no native onmove event) ──

function _startPolling() {
    if (_positionPollTimer) return;
    _positionPollTimer = setInterval(function() {
        var anyOpen = false;
        Object.keys(_windows).forEach(function(id) {
            var w = _windows[id];
            if (!w.ref || w.ref.closed) {
                delete _windows[id];
                _updateLauncherState();
                return;
            }
            anyOpen = true;
            try {
                var nx = w.ref.screenX, ny = w.ref.screenY;
                var nw = w.ref.outerWidth, nh = w.ref.outerHeight;
                if (nx !== w.x || ny !== w.y || nw !== w.w || nh !== w.h) {
                    w.x = nx; w.y = ny; w.w = nw; w.h = nh;
                    _saveLayout();
                }
            } catch(e) {}
        });
        if (!anyOpen) {
            clearInterval(_positionPollTimer);
            _positionPollTimer = null;
            localStorage.removeItem(LS_SESSION);
        }
    }, HEARTBEAT_MS);
}

// ── Crash Recovery ──

function checkCrashRecovery() {
    var wasActive = localStorage.getItem(LS_SESSION) === 'true';
    var layoutJson = localStorage.getItem(LS_LAYOUT);
    if (!wasActive || !layoutJson) return;

    var layout;
    try { layout = JSON.parse(layoutJson); } catch(e) { return; }
    if (!Object.keys(layout).length) return;

    // Check if any windows are actually open (they shouldn't be after a crash)
    var anyAlive = false;
    _broadcast({type: 'pip-heartbeat'});
    setTimeout(function() {
        if (anyAlive) return; // PIPs are still open, not a crash
        var count = Object.keys(layout).length;
        var names = Object.keys(layout).map(function(id) {
            return (_registry[id] && _registry[id].label) || id;
        }).join(', ');
        _showRestorePrompt(count, names, layout);
    }, 800);
}

function _showRestorePrompt(count, names, layout) {
    var el = document.createElement('div');
    el.id = 'pip-restore-prompt';
    el.innerHTML =
        '<div style="display:flex;align-items:center;gap:12px">' +
            '<span style="font-size:1.3rem">&#128268;</span>' +
            '<div style="flex:1">' +
                '<div style="font-weight:700;font-size:0.88rem;margin-bottom:2px">Restore PiP Session?</div>' +
                '<div style="font-size:0.75rem;opacity:0.8">' + count + ' panel' + (count > 1 ? 's' : '') + ': ' + names + '</div>' +
            '</div>' +
            '<button id="pipRestoreYes" style="padding:6px 16px;border:none;border-radius:6px;background:#0d9c5c;color:#fff;font-weight:700;font-size:0.8rem;cursor:pointer">Restore</button>' +
            '<button id="pipRestoreDismiss" style="padding:6px 12px;border:1px solid rgba(255,255,255,0.2);border-radius:6px;background:none;color:rgba(255,255,255,0.7);font-size:0.8rem;cursor:pointer">Dismiss</button>' +
        '</div>';
    el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;background:rgba(15,23,29,0.95);border:1px solid rgba(201,169,98,0.3);border-radius:10px;padding:14px 18px;max-width:420px;color:#e8e2d6;font-family:Inter,system-ui,sans-serif;box-shadow:0 8px 32px rgba(0,0,0,0.4);backdrop-filter:blur(8px);animation:pipSlideDown 0.3s ease-out';
    document.body.appendChild(el);

    document.getElementById('pipRestoreYes').onclick = function() {
        el.remove();
        _restoreLayout(layout);
    };
    document.getElementById('pipRestoreDismiss').onclick = function() {
        el.remove();
        localStorage.removeItem(LS_SESSION);
        localStorage.removeItem(LS_LAYOUT);
    };
    // Auto-dismiss after 15s
    setTimeout(function() { if (el.parentNode) el.remove(); }, 15000);
}

// ── Toast ──

function _showToast(msg, type) {
    var el = document.getElementById('toast-notification');
    if (!el) {
        el = document.createElement('div');
        el.id = 'pip-toast';
        el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99998;font-family:Inter,system-ui,sans-serif';
        document.body.appendChild(el);
    }
    var colors = {ok: '#0d9c5c', warn: '#f59e0b', error: '#d92b2b'};
    var toast = document.createElement('div');
    toast.style.cssText = 'background:rgba(15,23,29,0.95);border:1px solid ' + (colors[type] || colors.ok) + ';border-radius:8px;padding:10px 16px;color:#e8e2d6;font-size:0.82rem;margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,0.3)';
    toast.textContent = msg;
    el.appendChild(toast);
    setTimeout(function() { if (toast.parentNode) toast.remove(); }, 4000);
}

// ── Launcher State ──

function _updateLauncherState() {
    var openIds = getOpen();
    document.querySelectorAll('[data-pip-panel]').forEach(function(btn) {
        var id = btn.getAttribute('data-pip-panel');
        if (openIds.indexOf(id) >= 0) {
            btn.classList.add('pip-active');
            btn.title = (_registry[id] && _registry[id].label || id) + ' (open — click to focus)';
        } else {
            btn.classList.remove('pip-active');
            btn.title = (_registry[id] && _registry[id].label || id) + ' — click to open PiP';
        }
    });
    var countEl = document.getElementById('pip-open-count');
    if (countEl) {
        countEl.textContent = openIds.length || '';
        countEl.style.display = openIds.length ? '' : 'none';
    }
}

// ── Public API ──

window.LuminalPiP = {
    open: openPip,
    close: closePip,
    closeAll: closeAll,
    toggle: togglePip,
    getOpen: getOpen,
    isOpen: isOpen,
    register: register,
    getRegistry: getRegistry,
    saveLayout: _saveLayout,
    saveNamedLayout: saveNamedLayout,
    loadNamedLayout: loadNamedLayout,
    listNamedLayouts: listNamedLayouts,
    deleteNamedLayout: deleteNamedLayout,
    checkCrashRecovery: checkCrashRecovery,
    _updateLauncherState: _updateLauncherState,
};

// ── Auto-register built-in panels ──

register('agent-tasks', {
    label: 'Agent Tasks', icon: '&#9881;',
    url: '/admin/modules/AgentScheduler/pip/tasks.php',
    defaultWidth: 440, defaultHeight: 520, group: 'agents',
    description: 'Task list with run/pause controls'
});
register('execution-log', {
    label: 'Execution Log', icon: '&#128220;',
    url: '/admin/modules/AgentScheduler/pip/execution-log.php',
    defaultWidth: 520, defaultHeight: 400, group: 'agents',
    description: 'Live streaming task output'
});
register('schedule', {
    label: 'Upcoming Schedule', icon: '&#128339;',
    url: '/admin/modules/AgentScheduler/pip/schedule.php',
    defaultWidth: 380, defaultHeight: 420, group: 'agents',
    description: 'Next scheduled tasks with countdowns'
});
register('tickets', {
    label: 'Support Tickets', icon: '&#127915;',
    url: '/admin/modules/TechSupport/pip/queue.php',
    defaultWidth: 440, defaultHeight: 480, group: 'support',
    description: 'Ticket queue with status indicators'
});
register('server-monitor', {
    label: 'Server Monitor', icon: '&#128421;',
    url: '/admin/modules/ServerMonitor/pip/status.php',
    defaultWidth: 380, defaultHeight: 340, group: 'infra',
    description: 'Server health cards'
});
register('file-manager', {
    label: 'File Browser', icon: '&#128193;',
    url: '/admin/modules/FileManager/pip/browser.php',
    defaultWidth: 400, defaultHeight: 500, group: 'content',
    description: 'Quick file browser'
});
register('page-manager', {
    label: 'Pages', icon: '&#128196;',
    url: '/admin/modules/PageManager/pip/pages.php',
    defaultWidth: 380, defaultHeight: 460, group: 'content',
    description: 'Page list with edit links'
});
register('audience-leads', {
    label: 'Audience Leads', icon: '&#128101;',
    url: '/admin/modules/AudienceCollector/pip/leads.php',
    defaultWidth: 420, defaultHeight: 460, group: 'audience',
    description: 'Incoming leads with new badges'
});
register('site-stats', {
    label: 'Site Stats', icon: '&#128200;',
    url: '/admin/modules/SiteStats/pip/stats.php',
    defaultWidth: 400, defaultHeight: 380, group: 'analytics',
    description: 'Traffic snapshot and trends'
});
register('members', {
    label: 'Members', icon: '&#128100;',
    url: '/admin/modules/MembershipManager/pip/members.php',
    defaultWidth: 420, defaultHeight: 460, group: 'audience',
    description: 'Member list and activity'
});
register('social', {
    label: 'Social Queue', icon: '&#128226;',
    url: '/admin/modules/SocialPublisher/pip/queue.php',
    defaultWidth: 400, defaultHeight: 420, group: 'content',
    description: 'Scheduled and recent social posts'
});

// ── Init: check crash recovery after DOM ready ──

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(checkCrashRecovery, 500);
    });
} else {
    setTimeout(checkCrashRecovery, 500);
}

})();
