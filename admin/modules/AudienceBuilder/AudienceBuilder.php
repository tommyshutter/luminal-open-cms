<?php
/**
 * AudienceBuilder — Admin UI
 *
 * Dual-mode: Hub (full config + leads) or Node (sent leads + hub status).
 * Default tab = Leads. Mode detected via JS on init.
 *
 * @module  AudienceBuilder
 * @version 1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$moduleBase = sc_asset('/admin/modules/AudienceBuilder');

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1">Audience</h1>

<?php $abMt = fn(string $rel): string => '?v=' . (@filemtime(SITE_ROOT . '/admin/modules/AudienceBuilder/' . $rel) ?: '1'); ?>
<link rel="stylesheet" href="<?= $moduleBase ?>/css/audience-builder.css<?= $abMt('css/audience-builder.css') ?>">
<link rel="stylesheet" href="<?= $moduleBase ?>/css/ab-form-builder.css<?= $abMt('css/ab-form-builder.css') ?>">
<?php /* front-end form styles so the quickstart preview matches the live site */ ?>
<?php if (is_file(SITE_ROOT . '/css/ab-forms.css')): ?>
<link rel="stylesheet" href="/css/ab-forms.css?v=<?= @filemtime(SITE_ROOT . '/css/ab-forms.css') ?: '1' ?>">
<?php endif; ?>

<div class="ab-wrap">

    <!-- ── Tab nav ── -->
    <nav class="ab-tabs">
        <button class="ab-tab active" data-tab="leads"><i class="fa-solid fa-address-book"></i> Leads</button>
        <button class="ab-tab" data-tab="forms"><i class="fa-solid fa-wpforms"></i> Forms</button>
        <button class="ab-tab" data-tab="sent" id="abSentTab" style="display:none;"><i class="fa-solid fa-paper-plane"></i> Sent Leads</button>
        <button class="ab-tab" data-tab="config"><i class="fa-solid fa-gear"></i> Configuration</button>
    </nav>

    <!-- ══════════════════════════════════════════════════════════
         CONFIG TAB
         ══════════════════════════════════════════════════════════ -->
    <div class="ab-panel" id="ab-config">

        <!-- Status banner -->
        <div class="ab-status-bar" id="abStatusBar">
            <span class="ab-status-dot"></span>
            <span class="ab-status-text">Loading…</span>
        </div>

        <!-- Mode indicator (populated by JS) -->
        <div class="ab-mode-bar" id="abModeBar" style="display:none;">
            <i class="fa-solid fa-satellite-dish"></i>
            <span id="abModeText"></span>
        </div>

        <!-- Module Settings card -->
        <div class="ab-card">
            <div class="ab-card-head">
                <i class="fa-solid fa-toggle-on"></i> Module Settings
            </div>
            <div class="ab-card-body">
                <div class="ab-field ab-field-inline">
                    <label class="ab-toggle-label">
                        <input type="checkbox" id="abEnabled">
                        <span>Enable Audience Builder (inject form hook on frontend)</span>
                    </label>
                </div>
                <div class="ab-field ab-field-inline">
                    <label class="ab-toggle-label">
                        <input type="checkbox" id="abStoreLeads" checked>
                        <span>Store leads locally (viewable in Leads tab)</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Hub/Node Mode card -->
        <div class="ab-card" id="abHubCard">
            <div class="ab-card-head">
                <i class="fa-solid fa-server"></i> Hub / Node Mode
            </div>
            <div class="ab-card-body">
                <div class="ab-field ab-field-inline">
                    <label class="ab-toggle-label">
                        <input type="checkbox" id="abHubEnabled">
                        <span id="abHubToggleLabel">Set as Node — forward leads to a hub site</span>
                    </label>
                    <small style="display:block;margin-top:4px;color:rgba(255,255,255,.35)">Check = Node (forwards leads to hub). Uncheck = Hub (processes leads directly, stores locally).</small>
                </div>
                <div class="ab-field" id="abHubUrlField">
                    <label for="abHubUrl">Hub URL</label>
                    <input type="text" id="abHubUrl" placeholder="https://hub.example.com">
                    <small>URL of the hub site that receives forwarded leads.</small>
                </div>
            </div>
        </div>

        <!-- Email Notifications card -->
        <div class="ab-card" id="abEmailCard">
            <div class="ab-card-head">
                <i class="fa-solid fa-envelope"></i> Email Notifications
            </div>
            <div class="ab-card-body">
                <div class="ab-field ab-field-inline">
                    <label class="ab-toggle-label">
                        <input type="checkbox" id="abNotifyEnabled">
                        <span>Send email notification on new lead (via MailgunManager)</span>
                    </label>
                </div>
                <div class="ab-field">
                    <label for="abNotifyTo">Notification Email</label>
                    <input type="email" id="abNotifyTo" placeholder="admin@example.com">
                </div>
                <div class="ab-field">
                    <label for="abNotifySubject">Subject Template</label>
                    <input type="text" id="abNotifySubject" placeholder="New Lead from {domain}">
                    <small>Use <code>{domain}</code> for the source site domain</small>
                </div>
            </div>
        </div>

        <!-- Output Connectors card -->
        <div class="ab-card" id="abConnectorsCard">
            <div class="ab-card-head">
                <i class="fa-solid fa-plug-circle-bolt"></i> Output Connectors
            </div>
            <div class="ab-card-body">
                <div class="ab-connector-card ab-connector-active">
                    <div class="ab-connector-icon"><i class="fa-solid fa-database"></i></div>
                    <div class="ab-connector-info">
                        <div class="ab-connector-name">Local Storage</div>
                        <div class="ab-connector-desc">Leads stored in Luminal CMS data directory</div>
                    </div>
                    <span class="ab-connector-badge ab-connector-badge-active">Active</span>
                </div>
                <div class="ab-connector-card ab-connector-future">
                    <div class="ab-connector-icon"><i class="fa-solid fa-arrow-up-right-from-square"></i></div>
                    <div class="ab-connector-info">
                        <div class="ab-connector-name">Go High Level</div>
                        <div class="ab-connector-desc">Push leads to GHL CRM via API</div>
                    </div>
                    <span class="ab-connector-badge ab-connector-badge-soon">Coming Soon</span>
                </div>
                <div class="ab-connector-card ab-connector-future">
                    <div class="ab-connector-icon"><i class="fa-solid fa-arrow-up-right-from-square"></i></div>
                    <div class="ab-connector-info">
                        <div class="ab-connector-name">Webhook</div>
                        <div class="ab-connector-desc">POST leads to any external URL</div>
                    </div>
                    <span class="ab-connector-badge ab-connector-badge-soon">Coming Soon</span>
                </div>
            </div>
        </div>

        <!-- Save bar -->
        <div class="ab-save-bar">
            <button class="ab-btn ab-btn-save" id="abSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save Configuration</button>
            <span class="ab-save-status" id="abSaveStatus"></span>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════════
         LEADS TAB (hub mode — shows all leads with source column)
         ══════════════════════════════════════════════════════════ -->
    <div class="ab-panel active" id="ab-leads">

        <!-- Stats bar -->
        <div class="ab-stats" id="abStatsBar">
            <div class="ab-stat-card">
                <div class="ab-stat-val" id="abStatTotal">—</div>
                <div class="ab-stat-lbl">Total Leads</div>
            </div>
            <div class="ab-stat-card">
                <div class="ab-stat-val" id="abStatMonth">—</div>
                <div class="ab-stat-lbl">This Month</div>
            </div>
            <div class="ab-stat-card ab-stat-ok">
                <div class="ab-stat-val" id="abStatStored">—</div>
                <div class="ab-stat-lbl">Stored</div>
            </div>
            <div class="ab-stat-card ab-stat-queued">
                <div class="ab-stat-val" id="abStatPending">—</div>
                <div class="ab-stat-lbl">Pending</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="ab-filters">
            <select id="abFilterDomain"><option value="">All Domains</option></select>
            <select id="abFilterSource" style="display:none;"><option value="">All Sources</option></select>
            <input type="text" id="abFilterSearch" placeholder="Search name or email…" class="ab-filter-search">
            <button class="ab-btn ab-btn-sm" id="abRefreshBtn"><i class="fa-solid fa-rotate"></i> Refresh</button>
            <div class="ab-export-group">
                <button class="ab-btn ab-btn-sm ab-btn-export" onclick="AB.exportLeads('csv')" title="Export filtered leads as CSV"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button class="ab-btn ab-btn-sm ab-btn-export" onclick="AB.exportLeads('md')" title="Export filtered leads as Markdown"><i class="fa-solid fa-file-lines"></i> MD</button>
            </div>
        </div>

        <!-- Lead table (click row to view details) -->
        <div class="ab-table-wrap">
            <table class="ab-table ab-table-clickable" id="abLeadTable">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Date</th>
                        <th class="ab-source-col" style="display:none;">Source</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="abLeadBody">
                    <tr><td colspan="8" class="ab-muted">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="ab-pagination" id="abPagination"></div>

        <!-- Archive note -->
        <div class="ab-archive-note">
            <i class="fa-solid fa-shield-halved"></i>
            All leads are permanently stored locally as an archived backup. This data lives in your Luminal CMS ecosystem — you own it completely.
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         FORMS TAB
         ══════════════════════════════════════════════════════════ -->
    <div class="ab-panel" id="ab-forms">

        <!-- Quick start: create the two standard forms on demand, with a style + live preview -->
        <div class="ab-quickstart" id="abQuickstart">
            <div class="ab-qs-head">
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Quick start — standard forms</h3>
                <p>Generate a basic <strong>Contact</strong> form and an <strong>Email Signup</strong> form. Pick a style, preview below, then create — edit or restyle them anytime.</p>
            </div>
            <div class="ab-qs-controls">
                <label class="ab-qs-style">Style
                    <select id="abQsStyle">
                        <option value="default">Default</option>
                        <option value="glass">Glass</option>
                        <option value="bare">Bare</option>
                    </select>
                </label>
                <label class="ab-qs-pixel" title="Optional: stamp a conversion pixel ID on both forms">Pixel tracking
                    <input type="text" id="abQsPixel" placeholder="Pixel ID (optional)">
                </label>
                <button class="ab-btn ab-btn-save" id="abQsCreate"><i class="fa-solid fa-plus"></i> Create basic contact &amp; email forms</button>
            </div>
            <div class="ab-qs-previews" id="abQsPreviews"><!-- JS renders contact + signup previews + copy-shortcode here --></div>
        </div>

        <div id="abFormsGrid" class="ab-forms-grid">
            <div style="color:rgba(255,255,255,.4);padding:20px;">Click tab to load forms…</div>
        </div>
    </div>

    <!-- Form Editor Modal (relocated to body by JS to escape backdrop-filter) -->
    <div class="ab-form-editor-overlay" id="abFormEditorOverlay">
        <div class="ab-form-editor">
            <div class="ab-form-editor-header">
                <input type="text" id="abFeTitle" placeholder="Form title…">
                <input type="text" id="abFeSlug" class="ab-slug-field" placeholder="slug">
                <button class="ab-form-editor-close" id="abFeCloseBtn">&times;</button>
            </div>
            <div class="ab-form-editor-body">
                <div class="ab-form-settings">
                    <div class="ab-fe-card">
                        <div class="ab-fe-card-head">Submit Settings</div>
                        <div class="ab-fe-field-gap">
                            <label for="abFeSubmitText">Button Text</label>
                            <input type="text" id="abFeSubmitText" value="Submit">
                        </div>
                        <div>
                            <label for="abFeSuccessMsg">Success Message</label>
                            <textarea id="abFeSuccessMsg" rows="2">Thank you! Your submission has been received.</textarea>
                        </div>
                    </div>
                    <div class="ab-fe-card">
                        <div class="ab-fe-card-head">Appearance</div>
                        <div class="ab-fe-field-gap">
                            <label for="abFeFormStyle">Form Style</label>
                            <select id="abFeFormStyle">
                                <option value="default">Default (Clean)</option>
                                <option value="glass">Semitransparent Glass</option>
                                <option value="bare">Bare (Minimal)</option>
                                <option value="custom">Custom CSS</option>
                            </select>
                        </div>
                        <div class="ab-fe-field-gap">
                            <label for="abFeDisplayMode">Display</label>
                            <select id="abFeDisplayMode">
                                <option value="embedded">Embedded in page</option>
                                <option value="lightbox">Lightbox (button opens a popup)</option>
                            </select>
                        </div>
                        <div id="abFeTriggerLabelField" style="display:none;">
                            <label for="abFeTriggerLabel">Lightbox button text</label>
                            <input type="text" id="abFeTriggerLabel" placeholder="Contact Us">
                        </div>
                        <div id="abFeCustomCssField" style="display:none;">
                            <label for="abFeCustomCss">Custom CSS</label>
                            <textarea id="abFeCustomCss" rows="5" style="font-family:'Fira Code',monospace;font-size:12px;background:#0d0d1a;color:#a5d6ff;tab-size:2;" placeholder=".ab-form-wrap {&#10;    background: rgba(0,0,0,.5);&#10;}"></textarea>
                        </div>
                        <div>
                            <label for="abFeCssClass">Extra CSS Class</label>
                            <input type="text" id="abFeCssClass" placeholder="custom-class">
                        </div>
                    </div>
                    <div class="ab-fe-card ab-tracking-card">
                        <div class="ab-fe-card-head">Tracking <span class="ab-tracking-badge">Coming Soon</span></div>
                        <div class="ab-fe-field-gap">
                            <label>Pixel ID</label>
                            <input type="text" disabled placeholder="Facebook / Google pixel">
                        </div>
                        <div>
                            <label>Conversion Event</label>
                            <input type="text" disabled placeholder="Lead / Contact / Custom">
                        </div>
                    </div>
                </div>
                <div class="ab-form-field-list-wrap">
                    <div class="ab-form-field-list" id="abFeFieldList"></div>
                    <button type="button" class="ab-form-add-field-btn" id="abFeAddFieldBtn"><i class="fa-solid fa-plus"></i> Add Field</button>
                </div>
            </div>
            <div class="ab-form-editor-footer">
                <span class="ab-fe-status" id="abFeStatus"></span>
                <button class="ab-fe-btn ab-fe-btn-delete" id="abFeDeleteBtn" style="display:none;"><i class="fa-solid fa-trash"></i> Delete</button>
                <button class="ab-fe-btn ab-fe-btn-cancel" id="abFeCancelBtn">Cancel</button>
                <button class="ab-fe-btn ab-fe-btn-save" id="abFeSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save Form</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         SENT TAB (node mode — shows forwarded leads)
         ══════════════════════════════════════════════════════════ -->
    <div class="ab-panel" id="ab-sent">

        <!-- Sent stats bar -->
        <div class="ab-stats" id="abSentStatsBar">
            <div class="ab-stat-card">
                <div class="ab-stat-val" id="abSentTotal">—</div>
                <div class="ab-stat-lbl">Total Sent</div>
            </div>
            <div class="ab-stat-card">
                <div class="ab-stat-val" id="abSentMonth">—</div>
                <div class="ab-stat-lbl">This Month</div>
            </div>
            <div class="ab-stat-card ab-stat-ok">
                <div class="ab-stat-val" id="abSentDelivered">—</div>
                <div class="ab-stat-lbl">Delivered</div>
            </div>
            <div class="ab-stat-card ab-stat-err">
                <div class="ab-stat-val" id="abSentFailed">—</div>
                <div class="ab-stat-lbl">Failed</div>
            </div>
            <div class="ab-stat-card ab-stat-queued">
                <div class="ab-stat-val" id="abSentPending">—</div>
                <div class="ab-stat-lbl">Pending</div>
            </div>
        </div>

        <!-- Sent filters -->
        <div class="ab-filters">
            <select id="abSentFilterStatus">
                <option value="">All Statuses</option>
                <option value="delivered">Delivered</option>
                <option value="hub_failed">Hub Failed</option>
                <option value="hub_error">Hub Error</option>
                <option value="pending">Pending</option>
            </select>
            <button class="ab-btn ab-btn-sm" id="abSentRefreshBtn"><i class="fa-solid fa-rotate"></i> Refresh</button>
            <button class="ab-btn ab-btn-sm ab-btn-flush" id="abSentFlushBtn" title="Retry all pending/failed to hub">
                <i class="fa-solid fa-paper-plane"></i> Forward to Hub
            </button>
            <span class="ab-flush-status" id="abSentFlushStatus"></span>
            <div class="ab-export-group">
                <button class="ab-btn ab-btn-sm ab-btn-export" onclick="AB.exportSent('csv')" title="Export sent leads as CSV"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button class="ab-btn ab-btn-sm ab-btn-export" onclick="AB.exportSent('md')" title="Export sent leads as Markdown"><i class="fa-solid fa-file-lines"></i> MD</button>
            </div>
        </div>

        <!-- Sent table -->
        <div class="ab-table-wrap">
            <table class="ab-table ab-table-clickable" id="abSentTable">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Hub Status</th>
                    </tr>
                </thead>
                <tbody id="abSentBody">
                    <tr><td colspan="7" class="ab-muted">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Sent pagination -->
        <div class="ab-pagination" id="abSentPagination"></div>

        <div class="ab-archive-note">
            <i class="fa-solid fa-server"></i>
            This site operates in <strong>node mode</strong> — leads are forwarded to the hub. All sent leads are backed up locally regardless of hub delivery status.
        </div>
    </div>

</div>

<!-- Lead Viewer Lightbox (centered panel) -->
<div class="ab-viewer-overlay" id="abViewer" style="display:none;">
    <div class="ab-viewer-panel">
        <div class="ab-viewer-toolbar">
            <span class="ab-viewer-title" id="abViewerTitle">Lead Details</span>
            <div class="ab-viewer-actions">
                <button class="ab-viewer-btn" id="abViewerPrint" title="Print / Save as PDF"><i class="fa-solid fa-print"></i> Print</button>
                <button class="ab-viewer-btn" id="abViewerRetry" title="Retry"><i class="fa-solid fa-rotate-right"></i> Retry</button>
                <button class="ab-viewer-btn ab-viewer-btn-danger" id="abViewerDelete" title="Delete"><i class="fa-solid fa-trash"></i> Delete</button>
                <button class="ab-viewer-close" id="abViewerClose">&times;</button>
            </div>
        </div>
        <div class="ab-viewer-body">
            <iframe id="abViewerFrame" frameborder="0"></iframe>
        </div>
    </div>
</div>

<script>
window.AB_BOOT = {
    apiUrl: '<?= $moduleBase ?>/api.php'
};
</script>
<script src="<?= $moduleBase ?>/js/audience-builder.js<?= $abMt('js/audience-builder.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="<?= $moduleBase ?>/js/ab-form-builder.js<?= $abMt('js/ab-form-builder.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
