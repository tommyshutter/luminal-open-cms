<?php
/**
 * PiP Launcher Widget — Sidebar dock for opening PiP panels.
 * Include in admin_menu.php rail-widgets section.
 */
?>
<div class="pip-launcher" id="pipLauncher">
    <div class="pip-launcher-title">
        <span>&#128268; PiP Panels</span>
        <span class="pip-open-count" id="pip-open-count" style="display:none"></span>
    </div>
    <div class="pip-launcher-grid">
        <button class="pip-launcher-btn" data-pip-panel="agent-tasks" onclick="LuminalPiP.toggle('agent-tasks')">
            <span style="font-size:1.1rem">&#9881;</span>
            <span class="pip-launcher-label">Tasks</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="execution-log" onclick="LuminalPiP.toggle('execution-log')">
            <span style="font-size:1.1rem">&#128220;</span>
            <span class="pip-launcher-label">Log</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="schedule" onclick="LuminalPiP.toggle('schedule')">
            <span style="font-size:1.1rem">&#128339;</span>
            <span class="pip-launcher-label">Schedule</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="tickets" onclick="LuminalPiP.toggle('tickets')">
            <span style="font-size:1.1rem">&#127915;</span>
            <span class="pip-launcher-label">Tickets</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="server-monitor" onclick="LuminalPiP.toggle('server-monitor')">
            <span style="font-size:1.1rem">&#128421;</span>
            <span class="pip-launcher-label">Servers</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="file-manager" onclick="LuminalPiP.toggle('file-manager')">
            <span style="font-size:1.1rem">&#128193;</span>
            <span class="pip-launcher-label">Files</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="page-manager" onclick="LuminalPiP.toggle('page-manager')">
            <span style="font-size:1.1rem">&#128196;</span>
            <span class="pip-launcher-label">Pages</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="audience-leads" onclick="LuminalPiP.toggle('audience-leads')">
            <span style="font-size:1.1rem">&#128101;</span>
            <span class="pip-launcher-label">Leads</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="site-stats" onclick="LuminalPiP.toggle('site-stats')">
            <span style="font-size:1.1rem">&#128200;</span>
            <span class="pip-launcher-label">Stats</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="members" onclick="LuminalPiP.toggle('members')">
            <span style="font-size:1.1rem">&#128100;</span>
            <span class="pip-launcher-label">Members</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="social" onclick="LuminalPiP.toggle('social')">
            <span style="font-size:1.1rem">&#128226;</span>
            <span class="pip-launcher-label">Social</span>
        </button>
        <button class="pip-launcher-btn" data-pip-panel="_all" onclick="pipLaunchAll()" title="Open all panels">
            <span style="font-size:1.1rem">&#128640;</span>
            <span class="pip-launcher-label">All</span>
        </button>
    </div>
    <div class="pip-layout-bar">
        <button class="pip-layout-btn" onclick="pipSaveLayout()" title="Save current layout">&#128190; Save</button>
        <button class="pip-layout-btn" onclick="pipLoadLayout()" title="Load saved layout">&#128194; Load</button>
        <button class="pip-layout-btn pip-danger" onclick="LuminalPiP.closeAll()" title="Close all PiP windows">&#10005; Close All</button>
    </div>
</div>
<script>
function pipLaunchAll() {
    var reg = LuminalPiP.getRegistry();
    var delay = 0;
    Object.keys(reg).forEach(function(id) {
        if (!LuminalPiP.isOpen(id)) {
            setTimeout(function() { LuminalPiP.open(id); }, delay);
            delay += 300;
        }
    });
}
function pipSaveLayout() {
    var open = LuminalPiP.getOpen();
    if (!open.length) { alert('No PiP panels open to save.'); return; }
    var name = prompt('Layout name:', 'My Layout');
    if (!name) return;
    LuminalPiP.saveNamedLayout(name.trim());
}
function pipLoadLayout() {
    var layouts = LuminalPiP.listNamedLayouts();
    if (!layouts.length) { alert('No saved layouts. Open some panels and click Save first.'); return; }
    var name = prompt('Load layout:\n\nSaved: ' + layouts.join(', '));
    if (!name) return;
    LuminalPiP.loadNamedLayout(name.trim());
}
</script>
