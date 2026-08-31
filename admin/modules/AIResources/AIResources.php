<?php
/**
 * AIResources — Card-Based AI Resource Management
 * Visual cards for AI providers, MCP servers, and image generation APIs.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">AI Resources</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/AIResources/css/ai-resources.css') ?>">

<div class="air-wrap">

    <!-- Header -->
    <div class="air-header">
        <div class="air-header-left">
            <p class="air-subtitle">Manage AI providers, MCP tool servers, and image generation APIs</p>
            <p class="air-scope-note">Settings apply to <strong><?= htmlspecialchars(basename(SITE_ROOT)) ?></strong> only</p>
        </div>
        <div class="air-header-right">
            <button class="air-btn air-btn-outline" id="airPushGlobal" title="Copy these settings to all sites on this server">Push to All Sites</button>
            <button class="air-btn air-btn-outline air-log-btn" id="airLogToggle" title="Activity Log">
                <span class="air-log-icon">&#9776;</span> Log
                <span class="air-log-badge" id="airLogBadge" style="display:none">0</span>
            </button>
            <div class="air-health" id="airHealth">
                <span class="air-health-dot" id="airHealthDot"></span>
                <span class="air-health-text" id="airHealthText">Loading...</span>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════ -->
    <!-- SECTION 1: AI Providers            -->
    <!-- ═══════════════════════════════════ -->
    <section class="air-section">
        <div class="air-section-head">
            <h2>AI Providers</h2>
            <button class="air-btn air-btn-primary" id="airAddProvider">+ Add Provider</button>
        </div>
        <div class="air-card-grid" id="airProviderGrid">
            <div class="air-loading">Loading providers...</div>
        </div>
    </section>

    <!-- ═══════════════════════════════════ -->
    <!-- SECTION 2: MCP Servers             -->
    <!-- ═══════════════════════════════════ -->
    <section class="air-section">
        <div class="air-section-head">
            <h2>MCP Servers</h2>
            <button class="air-btn air-btn-primary" id="airAddServer">+ Add Server</button>
        </div>
        <div class="air-card-grid" id="airServerGrid">
            <div class="air-loading">Loading servers...</div>
        </div>
    </section>

    <!-- ═══════════════════════════════════ -->
    <!-- SECTION 3: Image Providers         -->
    <!-- ═══════════════════════════════════ -->
    <section class="air-section">
        <div class="air-section-head">
            <h2>Image Providers</h2>
        </div>
        <div class="air-card-grid" id="airImageGrid">
            <div class="air-loading">Loading image providers...</div>
        </div>
    </section>

    <!-- ═══════════════════════════════════ -->
    <!-- SECTION 4: NotebookLM Podcast      -->
    <!-- ═══════════════════════════════════ -->
    <section class="air-section nlm-hero-section">
        <div class="air-section-head nlm-section-head">
            <div class="nlm-section-brand">
                <svg class="nlm-logo" viewBox="0 0 24 24" width="28" height="28" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="24" height="24" rx="6" fill="url(#nlmGrad)"/>
                    <path d="M7 8.5C7 7.67 7.67 7 8.5 7H11v10H8.5C7.67 17 7 16.33 7 15.5v-7z" fill="#fff" opacity=".9"/>
                    <path d="M13 7h2.5C16.33 7 17 7.67 17 8.5v7c0 .83-.67 1.5-1.5 1.5H13V7z" fill="#fff" opacity=".7"/>
                    <circle cx="9.5" cy="11" r="1" fill="url(#nlmGrad2)"/>
                    <circle cx="14.5" cy="11" r="1" fill="url(#nlmGrad2)"/>
                    <path d="M9 14h6" stroke="url(#nlmGrad2)" stroke-width="1.2" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="nlmGrad" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#7c3aed"/><stop offset="1" stop-color="#ec4899"/></linearGradient>
                        <linearGradient id="nlmGrad2" x1="7" y1="10" x2="17" y2="14"><stop stop-color="#7c3aed"/><stop offset="1" stop-color="#ec4899"/></linearGradient>
                    </defs>
                </svg>
                <div>
                    <h2 class="nlm-section-title">NotebookLM Podcast</h2>
                    <span class="nlm-section-subtitle">AI-powered podcast generation from page content</span>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span class="air-tag" id="airNlmBadge">Checking...</span>
                <span class="nlm-api-status" id="nlmApiStatus" title="API connection status">
                    <span class="air-status-dot untested" id="nlmApiDot"></span>
                    <span id="nlmApiLabel">Checking...</span>
                </span>
            </div>
        </div>
        <div class="air-card-grid">
            <div class="air-card" style="max-width:520px">
                <div class="air-card-head">
                    <div class="air-card-icon nlm">N</div>
                    <div class="air-card-head-text">
                        <div class="air-card-title">Google NotebookLM</div>
                        <div class="air-card-type">Standalone Podcast API</div>
                    </div>
                    <div class="air-card-status">
                        <a href="#" id="nlmInfoToggle" class="nlm-info-link" title="Setup instructions">How to set up</a>
                    </div>
                </div>

                <!-- Setup info (collapsed by default) -->
                <div class="nlm-info-panel" id="nlmInfoPanel" style="display:none">
                    <ol>
                        <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a></li>
                        <li>Create or select a project</li>
                        <li>Enable the <strong>Discovery Engine API</strong> (Vertex AI Agent Builder):
                            <ul style="margin:4px 0 2px;font-size:11px;color:#94a3b8">
                                <li>In the console, go to <strong>APIs &amp; Services &rarr; Library</strong></li>
                                <li>Search for <em>"Discovery Engine API"</em></li>
                                <li>Click the result and press <strong>Enable</strong></li>
                            </ul>
                        </li>
                        <li>Go to <strong>IAM &amp; Admin &rarr; Service Accounts</strong></li>
                        <li>Create a service account with <strong>Discovery Engine Editor</strong> role:
                            <ul style="margin:4px 0 2px;font-size:11px;color:#94a3b8">
                                <li>Click <strong>+ Create Service Account</strong></li>
                                <li>Name it (e.g. <em>notebooklm-podcast</em>), click <strong>Create and Continue</strong></li>
                                <li>Under "Grant this service account access to project", select role: <strong>Discovery Engine &rarr; Discovery Engine Editor</strong></li>
                                <li>Click <strong>Done</strong></li>
                            </ul>
                        </li>
                        <li>Create a JSON key for the service account:
                            <ul style="margin:4px 0 2px;font-size:11px;color:#94a3b8">
                                <li>In the Service Accounts list, click the account you just created</li>
                                <li>Go to the <strong>Keys</strong> tab</li>
                                <li>Click <strong>Add Key &rarr; Create new key</strong></li>
                                <li>Select <strong>JSON</strong> format, click <strong>Create</strong></li>
                                <li>A <code>.json</code> file will download automatically &mdash; save it securely</li>
                                <li>Open the downloaded file in a text editor &mdash; you'll see fields like <code>client_email</code>, <code>private_key</code>, <code>project_id</code>, etc.</li>
                            </ul>
                        </li>
                        <li>Paste the <strong>entire contents</strong> of the downloaded JSON file into the text area below</li>
                    </ol>
                </div>

                <!-- Active account display -->
                <div class="nlm-active-account" id="nlmActiveAccount" style="display:none">
                    <div class="nlm-account-row">
                        <span class="nlm-account-label">Active Account</span>
                        <span class="nlm-account-value" id="nlmAccountEmail">—</span>
                    </div>
                    <div class="nlm-account-row">
                        <span class="nlm-account-label">Project</span>
                        <span class="nlm-account-value" id="nlmAccountProject">—</span>
                    </div>
                </div>

                <div class="air-card-body">
                    <div class="air-form-group">
                        <label>
                            <input type="checkbox" id="nlmEnabled" style="width:auto;margin-right:6px">
                            Enable NotebookLM Podcast generation
                        </label>
                    </div>
                    <div class="air-form-group">
                        <label for="nlmProjectId">Project ID</label>
                        <input type="text" id="nlmProjectId" placeholder="my-gcp-project-123">
                        <small>Auto-filled from service account key if left empty</small>
                    </div>
                    <div class="air-form-group">
                        <label for="nlmServiceKey">Service Account Key (JSON)</label>
                        <textarea id="nlmServiceKey" rows="5" placeholder='Paste full service account JSON key...' style="font:12px ui-monospace,monospace"></textarea>
                        <small>Download from Google Cloud Console &rarr; IAM &rarr; Service Accounts &rarr; Keys</small>
                    </div>
                    <div class="air-form-group" style="display:flex;gap:8px">
                        <button class="air-btn" id="nlmTest">Test Connection</button>
                        <button class="air-btn air-btn-primary" id="nlmSave">Save</button>
                    </div>
                    <div id="nlmStatus" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font:13px system-ui"></div>
                </div>
            </div>
        </div>
    </section>

</div>

<!-- ═══════════════════════════════════════ -->
<!-- Provider Modal                          -->
<!-- ═══════════════════════════════════════ -->
<div class="air-modal-backdrop" id="airProviderModal" style="display:none">
    <div class="air-modal">
        <div class="air-modal-head">
            <h3 id="airProviderModalTitle">Add Provider</h3>
            <button class="air-modal-close" data-close="airProviderModal">&times;</button>
        </div>
        <div class="air-modal-body">
            <input type="hidden" id="pmEditId">
            <div class="air-form-group">
                <label for="pmType">Provider Type</label>
                <select id="pmType">
                    <option value="">Select type...</option>
                    <option value="anthropic">Anthropic (Claude)</option>
                    <option value="openai">OpenAI (GPT)</option>
                    <option value="google">Google (Gemini)</option>
                    <option value="xai">xAI (Grok)</option>
                    <option value="zai">Z.ai (Zhipu GLM — Free Tier)</option>
                    <option value="custom">Custom (OpenAI-compatible)</option>
                </select>
                <small id="pmTypeHint" style="display:none">Groq, Together AI, Mistral, Ollama, LM Studio, vLLM, etc.</small>
            </div>
            <div class="air-form-group">
                <label for="pmId">Provider ID</label>
                <input type="text" id="pmId" placeholder="e.g. claude, gpt4, gemini, groq">
                <small>Unique identifier — cannot change after creation</small>
            </div>
            <div class="air-form-group">
                <label for="pmLabel">Label</label>
                <input type="text" id="pmLabel" placeholder="e.g. Mary's Google API, Companywide Key">
                <small>Friendly display name — shown in dropdowns and cards</small>
            </div>
            <div class="air-form-group" id="pmBaseUrlGroup" style="display:none">
                <label for="pmBaseUrl">Base URL</label>
                <input type="url" id="pmBaseUrl" placeholder="https://api.groq.com/openai/v1">
                <small>OpenAI-compatible chat completions endpoint (without /chat/completions)</small>
            </div>
            <div class="air-form-group">
                <label for="pmModel">Model</label>
                <div class="air-model-combo">
                    <select id="pmModel">
                        <option value="">Select model...</option>
                    </select>
                    <input type="text" id="pmModelCustom" placeholder="Or type model ID..." style="display:none">
                    <button type="button" class="air-btn air-btn-small" id="pmModelToggle" title="Switch to text input">...</button>
                </div>
            </div>
            <div class="air-form-group">
                <label for="pmKey">API Key</label>
                <input type="password" id="pmKey" placeholder="sk-ant-..." autocomplete="off">
            </div>
            <div class="air-form-group">
                <label for="pmTokens">Max Tokens</label>
                <input type="number" id="pmTokens" value="4096" min="256" max="32768">
            </div>
        </div>
        <div class="air-modal-foot">
            <button class="air-btn" data-close="airProviderModal">Cancel</button>
            <button class="air-btn air-btn-primary" id="pmSave">Save Provider</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- MCP Server Modal                        -->
<!-- ═══════════════════════════════════════ -->
<div class="air-modal-backdrop" id="airServerModal" style="display:none">
    <div class="air-modal">
        <div class="air-modal-head">
            <h3 id="airServerModalTitle">Add MCP Server</h3>
            <button class="air-modal-close" data-close="airServerModal">&times;</button>
        </div>
        <div class="air-modal-body">
            <input type="hidden" id="smEditId">
            <div class="air-form-group">
                <label for="smName">Server Name</label>
                <input type="text" id="smName" placeholder="e.g. My Context Server">
            </div>
            <div class="air-form-group">
                <label for="smType">Type</label>
                <select id="smType">
                    <option value="stdio">stdio (Local Process)</option>
                    <option value="http">HTTP (Remote)</option>
                </select>
            </div>
            <div id="smStdioFields">
                <div class="air-form-group">
                    <label for="smCommand">Command</label>
                    <input type="text" id="smCommand" placeholder="e.g. php, node, python">
                </div>
                <div class="air-form-group">
                    <label for="smArgs">Arguments (one per line)</label>
                    <textarea id="smArgs" rows="3" placeholder="/path/to/server.php"></textarea>
                </div>
            </div>
            <div id="smHttpFields" style="display:none">
                <div class="air-form-group">
                    <label for="smUrl">URL</label>
                    <input type="url" id="smUrl" placeholder="https://api.example.com/mcp">
                </div>
                <div class="air-form-group">
                    <label for="smAuthType">Auth Type</label>
                    <select id="smAuthType">
                        <option value="">None</option>
                        <option value="bearer">Bearer Token</option>
                        <option value="basic">Basic Auth</option>
                    </select>
                </div>
                <div class="air-form-group" id="smAuthKeyGroup" style="display:none">
                    <label for="smAuthKey">Auth Token/Key</label>
                    <input type="password" id="smAuthKey" autocomplete="off">
                </div>
            </div>
            <div class="air-form-group">
                <label for="smRoles">Roles (comma-separated)</label>
                <input type="text" id="smRoles" placeholder="context, tools">
            </div>
            <div class="air-form-group">
                <label for="smDesc">Description</label>
                <input type="text" id="smDesc" placeholder="What this server does">
            </div>
            <div class="air-form-group">
                <label>
                    <input type="checkbox" id="smEnabled" checked style="width:auto;margin-right:6px">
                    Enabled
                </label>
            </div>
        </div>
        <div class="air-modal-foot">
            <button class="air-btn" data-close="airServerModal">Cancel</button>
            <button class="air-btn air-btn-primary" id="smSave">Save Server</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- Image Provider Modal                    -->
<!-- ═══════════════════════════════════════ -->
<div class="air-modal-backdrop" id="airImageModal" style="display:none">
    <div class="air-modal">
        <div class="air-modal-head">
            <h3 id="airImageModalTitle">Edit Image Provider</h3>
            <button class="air-modal-close" data-close="airImageModal">&times;</button>
        </div>
        <div class="air-modal-body" id="airImageModalBody">
            <!-- Populated dynamically -->
        </div>
        <div class="air-modal-foot">
            <button class="air-btn" data-close="airImageModal">Cancel</button>
            <button class="air-btn air-btn-primary" id="imSave">Save</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- Activity Log Modal                      -->
<!-- ═══════════════════════════════════════ -->
<div class="air-modal-backdrop" id="airLogModal" style="display:none">
    <div class="air-modal air-log-modal">
        <div class="air-modal-head">
            <h3>Activity Log</h3>
            <div style="display:flex;align-items:center;gap:8px">
                <button class="air-btn air-btn-small" id="airLogClear" title="Clear log">Clear</button>
                <button class="air-modal-close" data-close="airLogModal">&times;</button>
            </div>
        </div>
        <div class="air-log-entries" id="airLogEntries">
            <div class="air-log-empty">No activity yet</div>
        </div>
    </div>
</div>

<script src="<?= sc_asset('/admin/modules/AIResources/js/ai-resources.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
