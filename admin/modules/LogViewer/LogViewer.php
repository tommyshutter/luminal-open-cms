<?php
/**
 * LogViewer — Single-Site Log Viewer & Threat Dashboard
 *
 * Three tabs:
 *   1. Dashboard — threat cards, status codes, top pages, top offenders
 *   2. Access Log — paginated viewer with tail, IP resolution, sorting
 *   3. Error Log — error log viewer with level filtering
 *
 * @package  LuminalCMS
 * @module   LogViewer
 * @version  1.0.0
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/LogViewerFunctions.php';

$moduleBase = sc_asset('/admin/modules/LogViewer');
$cfg        = lv_load_config();
$apiUrl     = '/admin/modules/LogViewer/api.php';
$domain     = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'unknown');
$logs       = lv_discover_logs();

require_once __DIR__ . '/../../admin_header.php';
?>

<h1 class="panel_header_h1">Log Viewer <span style="font-size:0.55em;color:#888;font-weight:400">&mdash; <?= htmlspecialchars($domain) ?></span></h1>

<link rel="stylesheet" href="<?= $moduleBase ?>/css/logviewer.css?v=<?= filemtime(__DIR__ . '/css/logviewer.css') ?>">

<div class="lv-wrapper">

    <!-- Tab Bar -->
    <div class="lv-tab-bar">
        <button class="lv-tab active" data-tab="dashboard">Dashboard</button>
        <button class="lv-tab" data-tab="access">Access Log</button>
        <button class="lv-tab" data-tab="errors">Error Log</button>
        <button class="lv-tab" data-tab="settings">Settings</button>
        <div class="lv-tab-actions">
            <div class="lv-range-buttons">
                <button class="lv-range-btn active" data-range="today">Today</button>
                <button class="lv-range-btn" data-range="24h">24h</button>
                <button class="lv-range-btn" data-range="7d">7 Days</button>
                <button class="lv-range-btn" data-range="30d">30 Days</button>
            </div>
            <button class="lv-btn lv-btn-ghost" id="lv-refresh" title="Force refresh">&#8635;</button>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  TAB: Dashboard                                               -->
    <!-- ============================================================ -->
    <div class="lv-tab-content active" id="tab-dashboard">
        <!-- Metric Cards -->
        <div class="lv-cards" id="lv-metric-cards">
            <div class="lv-card">
                <div class="lv-card-label">Requests</div>
                <div class="lv-card-value" id="lv-stat-requests">—</div>
            </div>
            <div class="lv-card">
                <div class="lv-card-label">Unique Visitors</div>
                <div class="lv-card-value" id="lv-stat-visitors">—</div>
            </div>
            <div class="lv-card">
                <div class="lv-card-label">Page Views</div>
                <div class="lv-card-value" id="lv-stat-pageviews">—</div>
            </div>
            <div class="lv-card">
                <div class="lv-card-label">Bandwidth</div>
                <div class="lv-card-value" id="lv-stat-bandwidth">—</div>
            </div>
            <div class="lv-card lv-card-warn">
                <div class="lv-card-label">Client Errors (4xx)</div>
                <div class="lv-card-value" id="lv-stat-4xx">—</div>
            </div>
            <div class="lv-card lv-card-error">
                <div class="lv-card-label">Server Errors (5xx)</div>
                <div class="lv-card-value" id="lv-stat-5xx">—</div>
            </div>
            <div class="lv-card lv-card-threat" id="lv-threat-card" title="View threat entries">
                <div class="lv-card-label">Threats Detected</div>
                <div class="lv-card-value" id="lv-stat-threats">—</div>
            </div>
            <div class="lv-card lv-card-admin">
                <div class="lv-card-label">Admin Hits Ignored</div>
                <div class="lv-card-value" id="lv-admin-hits">—</div>
            </div>
        </div>

        <!-- Threat Scoreboard — the hero section -->
        <div class="lv-threat-scoreboard" id="lv-threat-scoreboard">
            <div class="lv-threat-scoreboard-header">
                <div class="lv-threat-scoreboard-title">
                    <span class="shield-icon">&#128737;</span> Attacks Blocked &amp; Ignored
                </div>
                <div class="lv-threat-total-badge"><strong id="lv-scoreboard-total">—</strong> threats</div>
            </div>
            <div class="lv-threat-grid" id="lv-threat-categories">
                <div class="lv-muted">Loading threat analysis...</div>
            </div>
        </div>

        <!-- Three columns: Top Pages + Recent Threats + Top IPs -->
        <div class="lv-grid-3col">
            <div class="lv-panel">
                <h3 class="lv-panel-title">Top Pages</h3>
                <div id="lv-top-pages" class="lv-top-list">
                    <div class="lv-muted">Loading...</div>
                </div>
            </div>
            <div class="lv-panel">
                <h3 class="lv-panel-title">Recent Threats</h3>
                <div id="lv-recent-threats" class="lv-threat-timeline">
                    <div class="lv-muted">Loading...</div>
                </div>
            </div>
            <div class="lv-panel">
                <h3 class="lv-panel-title">Top Offending IPs</h3>
                <div id="lv-top-ips" class="lv-top-list">
                    <div class="lv-muted">Loading...</div>
                </div>
            </div>
        </div>

        <!-- Status Codes -->
        <div class="lv-panel">
            <h3 class="lv-panel-title">HTTP Status Codes</h3>
            <div id="lv-status-codes" class="lv-status-bar-chart">
                <div class="lv-muted">Loading...</div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  TAB: Access Log Viewer                                       -->
    <!-- ============================================================ -->
    <div class="lv-tab-content" id="tab-access">
        <div class="lv-log-layout">
            <!-- Log file selector -->
            <div class="lv-log-sidebar">
                <h4 class="lv-sidebar-title">Log Files</h4>
                <div class="lv-log-file-list" id="lv-access-files">
                    <?php if (!empty($logs['access'])): ?>
                        <?php foreach ($logs['access'] as $i => $log): ?>
                            <div class="lv-log-file-item<?= $i === 0 ? ' active' : '' ?>"
                                 data-logpath="<?= htmlspecialchars($log['path']) ?>"
                                 data-logname="<?= htmlspecialchars($log['name']) ?>">
                                <i class="fas fa-file-alt"></i>
                                <span class="lv-log-fname"><?= htmlspecialchars($log['name']) ?></span>
                                <span class="lv-log-fsize"><?= lv_fmt_size($log['size']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="lv-muted" style="padding:12px">No access logs found. Check settings.</div>
                    <?php endif; ?>
                </div>

                <h4 class="lv-sidebar-title" style="margin-top:16px">Error Logs</h4>
                <div class="lv-log-file-list" id="lv-error-files">
                    <?php if (!empty($logs['error'])): ?>
                        <?php foreach ($logs['error'] as $log): ?>
                            <div class="lv-log-file-item"
                                 data-logpath="<?= htmlspecialchars($log['path']) ?>"
                                 data-logname="<?= htmlspecialchars($log['name']) ?>">
                                <i class="fas fa-exclamation-circle" style="color:var(--lv-red)"></i>
                                <span class="lv-log-fname"><?= htmlspecialchars($log['name']) ?></span>
                                <span class="lv-log-fsize"><?= lv_fmt_size($log['size']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="lv-muted" style="padding:12px">No error logs found.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Log content area -->
            <div class="lv-log-content">
                <div class="lv-log-toolbar">
                    <h4 id="lv-current-log-title" class="lv-log-title">Select a log file</h4>
                    <div class="lv-log-actions">
                        <select id="lv-sort-select" class="lv-select">
                            <option value="datetime_desc">Date/Time (Newest)</option>
                            <option value="datetime_asc">Date/Time (Oldest)</option>
                            <option value="hostname_asc">Hostname (A-Z)</option>
                            <option value="hostname_desc">Hostname (Z-A)</option>
                            <option value="logEntry_asc">Log Entry (A-Z)</option>
                            <option value="logEntry_desc">Log Entry (Z-A)</option>
                        </select>
                        <button class="lv-btn lv-btn-green" id="lv-tail-btn" disabled><i class="fas fa-stream"></i> Tail</button>
                        <button class="lv-btn lv-btn-red" id="lv-stop-tail-btn" style="display:none"><i class="fas fa-stop-circle"></i> Stop</button>
                    </div>
                </div>

                <!-- Column headers (flex, resizable — matches original Vstats log viewer) -->
                <div class="lv-log-header" id="logOutputHeader">
                    <div class="lv-hdr-ip" id="hdrIP" data-sort="hostname">Resolved Name/IP <i class="fas fa-sort"></i></div><div class="lv-col-resizer" id="resizerIP"></div>
                    <div class="lv-hdr-time" id="hdrTime" data-sort="datetime">Date/Time <i class="fas fa-sort"></i></div><div class="lv-col-resizer" id="resizerTime"></div>
                    <div class="lv-hdr-entry" id="hdrEntry" data-sort="logEntry">Log Entry <i class="fas fa-sort"></i></div>
                </div>

                <!-- Log output -->
                <div class="lv-log-output" id="lv-log-output">
                    <div class="lv-log-line"><span class="lv-muted">Select a log file from the sidebar.</span></div>
                </div>

                <div class="lv-log-footer">
                    <button class="lv-btn lv-btn-ghost" id="lv-load-older" style="display:none">
                        <i class="fas fa-angle-double-up"></i> Load Older
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  TAB: Error Log                                               -->
    <!-- ============================================================ -->
    <div class="lv-tab-content" id="tab-errors">
        <div class="lv-error-summary" id="lv-error-summary">
            <div class="lv-muted">Loading error analysis...</div>
        </div>
        <div class="lv-error-log-viewer">
            <div class="lv-error-toolbar">
                <h4 class="lv-log-title">Error Log</h4>
                <div class="lv-log-actions">
                    <select id="lv-error-sort" class="lv-select">
                        <option value="time_desc">Newest First</option>
                        <option value="time_asc">Oldest First</option>
                        <option value="level_asc">Level A-Z</option>
                        <option value="ip_asc">IP A-Z</option>
                        <option value="message_asc">Message A-Z</option>
                    </select>
                    <select id="lv-error-level-filter" class="lv-select">
                        <option value="">All Levels</option>
                        <option value="error">Error</option>
                        <option value="warn">Warning</option>
                        <option value="notice">Notice</option>
                    </select>
                </div>
            </div>
            <div class="lv-error-header">
                <div class="lv-ecol-level" data-esort="level">Level <i class="fas fa-sort"></i></div>
                <div class="lv-ecol-ip" data-esort="ip">IP / Client <i class="fas fa-sort"></i></div>
                <div class="lv-ecol-time" data-esort="time">Date/Time <i class="fas fa-sort"></i></div>
                <div class="lv-ecol-msg" data-esort="message">Message <i class="fas fa-sort"></i></div>
            </div>
            <div class="lv-error-list" id="lv-error-list">
                <div class="lv-muted" style="padding:12px">Loading...</div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  TAB: Settings                                                -->
    <!-- ============================================================ -->
    <div class="lv-tab-content" id="tab-settings">
        <div class="lv-panel" style="max-width:600px">
            <h3 class="lv-panel-title">LogViewer Settings</h3>
            <div class="lv-settings-grid">
                <div class="lv-field">
                    <label class="lv-label">Detected Log Files</label>
                    <div class="lv-hint" style="font-family:monospace;font-size:0.8rem;line-height:1.6">
                        <?php
                        // Show discovered logs (from sidebar discovery, not just config)
                        $settingsAccessLogs = !empty($logs['access']) ? array_column($logs['access'], 'path') : ($cfg['access_logs'] ?? []);
                        $settingsErrorLogs  = !empty($logs['error'])  ? array_column($logs['error'], 'path')  : ($cfg['error_logs'] ?? []);
                        if ($settingsAccessLogs || $settingsErrorLogs) {
                            foreach ($settingsAccessLogs as $p) echo '<div style="color:#34d399">Access: ' . htmlspecialchars($p) . '</div>';
                            foreach ($settingsErrorLogs as $p) echo '<div style="color:#fbbf24">Error: ' . htmlspecialchars($p) . '</div>';
                        } else {
                            echo '<div style="color:#f87171">No logs detected — click Re-detect or check Apache vhost config</div>';
                        }
                        ?>
                    </div>
                    <button class="lv-btn lv-btn-ghost" id="lv-redetect" style="margin-top:6px">Re-detect from Apache</button>
                </div>
                <div class="lv-field">
                    <label class="lv-label">Dashboard Cache TTL (seconds)</label>
                    <input type="number" class="lv-input" id="lv-cfg-ttl" value="<?= (int) ($cfg['cache_ttl'] ?? 300) ?>" min="60" step="60">
                </div>
            </div>
            <div class="lv-settings-actions">
                <button class="lv-btn lv-btn-primary" id="lv-save-config">Save Settings</button>
                <button class="lv-btn lv-btn-ghost" id="lv-clear-cache">Clear Cache</button>
            </div>
        </div>
    </div>

</div>

<script>
    window.LV_CONFIG = {
        apiUrl: '<?= $apiUrl ?>',
        moduleBase: '<?= $moduleBase ?>',
        accessLogs: <?= json_encode($cfg['access_logs'] ?? []) ?>,
        errorLogs: <?= json_encode($cfg['error_logs'] ?? []) ?>,
        domain: <?= json_encode($domain) ?>
    };
</script>
<script src="<?= $moduleBase ?>/js/logviewer.js?v=<?= filemtime(__DIR__ . '/js/logviewer.js') ?>"></script>

<?php require_once __DIR__ . '/../../admin_footer.php'; ?>
