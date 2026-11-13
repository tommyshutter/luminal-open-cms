/**
 * AIResources — Card-Based Resource Management JS
 * Version: 2026.02.20.2
 */
(function(){
  'use strict';

  const API = '/admin/modules/AIResources/api.php';

  // Model lists per provider type
  const MODELS = {
    anthropic: [
      { id: 'claude-opus-4-6', name: 'Claude Opus 4.6' },
      { id: 'claude-sonnet-4-5-20250929', name: 'Claude Sonnet 4.5' },
      { id: 'claude-haiku-4-5-20251001', name: 'Claude Haiku 4.5' },
    ],
    openai: [
      { id: 'gpt-4o', name: 'GPT-4o' },
      { id: 'gpt-4o-mini', name: 'GPT-4o Mini' },
      { id: 'gpt-4-turbo', name: 'GPT-4 Turbo' },
      { id: 'o1', name: 'o1' },
      { id: 'o1-mini', name: 'o1-mini' },
    ],
    google: [
      { id: 'gemini-2.5-flash', name: 'Gemini 2.5 Flash' },
      { id: 'gemini-2.5-pro', name: 'Gemini 2.5 Pro' },
      { id: 'gemini-2.0-flash', name: 'Gemini 2.0 Flash (retiring Jun 2026)' },
      { id: 'gemini-2.0-flash-lite', name: 'Gemini 2.0 Flash Lite (retiring Jun 2026)' },
    ],
    xai: [
      { id: 'grok-3', name: 'Grok 3' },
      { id: 'grok-3-mini', name: 'Grok 3 Mini' },
      { id: 'grok-2', name: 'Grok 2' },
    ],
    zai: [
      { id: 'glm-4.7-flash', name: 'GLM-4.7 Flash [FREE]' },
      { id: 'glm-4.5-flash', name: 'GLM-4.5 Flash [FREE]' },
      { id: 'glm-4.6v-flash', name: 'GLM-4.6V Flash [FREE/Vision]' },
      { id: 'glm-5', name: 'GLM-5 (Flagship $1/$3.2)' },
      { id: 'glm-5-code', name: 'GLM-5 Code ($1.2/$5)' },
      { id: 'glm-4.7', name: 'GLM-4.7 (200K $0.6/$2.2)' },
      { id: 'glm-4.7-flashx', name: 'GLM-4.7 FlashX ($0.07/$0.4)' },
      { id: 'glm-4.6', name: 'GLM-4.6 ($0.6/$2.2)' },
      { id: 'glm-4.6v', name: 'GLM-4.6V Vision ($0.3/$0.9)' },
      { id: 'glm-4.5v', name: 'GLM-4.5V Vision ($0.6/$1.8)' },
    ],
    custom: []
  };

  const TYPE_LABELS = {
    anthropic: 'Anthropic',
    openai: 'OpenAI',
    google: 'Google',
    xai: 'xAI',
    zai: 'Z.ai (Free Tier)',
    custom: 'Custom',
  };

  const TYPE_COLORS = {
    anthropic: { icon: 'A', css: 'anthropic', color: '#d97706' },
    openai:    { icon: 'O', css: 'openai',    color: '#10b981' },
    google:    { icon: 'G', css: 'google',    color: '#60a5fa' },
    xai:       { icon: 'X', css: 'xai',       color: '#e5e5e5' },
    zai:       { icon: 'Z', css: 'zai',       color: '#06b6d4' },
    custom:    { icon: 'C', css: 'custom',    color: '#a78bfa' },
  };

  // Pipeline routing removed — provider selection happens per-task in AgentScheduler

  const IMAGE_INFO = {
    flux: { label: 'Flux (Replicate)', icon: 'F', cssClass: 'flux',
      models: [
        { id: 'flux-schnell', name: 'FLUX.1 [schnell]' },
        { id: 'flux-dev', name: 'FLUX.1 [dev]' },
        { id: 'flux-pro', name: 'FLUX.1 [pro]' },
      ]
    },
    dalle: { label: 'DALL-E (OpenAI)', icon: 'D', cssClass: 'dalle',
      models: [
        { id: 'dall-e-3', name: 'DALL-E 3' },
        { id: 'dall-e-2', name: 'DALL-E 2' },
      ],
      sizes: ['1024x1024', '1792x1024', '1024x1792'],
      qualities: ['standard', 'hd'],
    },
    stability: { label: 'Stable Diffusion', icon: 'S', cssClass: 'stability',
      models: [
        { id: 'sd3-large', name: 'SD3 Large' },
        { id: 'sd3-medium', name: 'SD3 Medium' },
        { id: 'sdxl-1.0', name: 'SDXL 1.0' },
      ]
    }
  };

  /* ── Helpers ── */
  async function apiCall(action, data = {}, method = 'GET') {
    let url = API + '?action=' + action;
    const opts = {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    };

    if (method === 'POST') {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(Object.assign({ action }, data));
    } else {
      for (const [k, v] of Object.entries(data)) {
        if (v !== undefined && v !== null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
      }
    }

    const r = await fetch(url, opts);
    return r.json();
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  /* ── Activity Log System ── */
  const _logEntries = [];
  let _logUnread = 0;
  let _bannerTimer;

  const LOG_ICONS = { success: '\u2714', error: '\u2718', info: '\u2139', warning: '\u26A0' };

  function log(msg, type = 'info') {
    const now = new Date();
    const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    _logEntries.push({ msg, type, time });

    // Update log modal if open
    const entries = document.getElementById('airLogEntries');
    if (entries) {
      const empty = entries.querySelector('.air-log-empty');
      if (empty) empty.remove();
      const row = document.createElement('div');
      row.className = 'air-log-entry ' + type;
      row.innerHTML = `<span class="air-log-type">${LOG_ICONS[type] || LOG_ICONS.info}</span><span class="air-log-msg">${esc(msg)}</span><span class="air-log-time">${time}</span>`;
      entries.appendChild(row);
      row.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    // Update badge
    const modal = document.getElementById('airLogModal');
    if (!modal || modal.style.display === 'none') {
      _logUnread++;
      const badge = document.getElementById('airLogBadge');
      if (badge) {
        badge.textContent = _logUnread;
        badge.style.display = '';
      }
    }

    // Show brief banner notification
    showBanner(msg, type);

    // Auto-open log on errors
    if (type === 'error' && modal && modal.style.display === 'none') {
      modal.style.display = '';
      _logUnread = 0;
      const badge = document.getElementById('airLogBadge');
      if (badge) badge.style.display = 'none';
    }
  }

  function showBanner(msg, type) {
    let el = document.querySelector('.air-log-banner');
    if (!el) {
      el = document.createElement('div');
      el.className = 'air-log-banner';
      document.body.appendChild(el);
    }
    clearTimeout(_bannerTimer);
    el.textContent = msg;
    el.className = 'air-log-banner ' + type;
    requestAnimationFrame(() => el.classList.add('show'));
    _bannerTimer = setTimeout(() => el.classList.remove('show'), 3000);
  }

  // Backward compat alias
  const toast = log;

  // Track test status per provider
  const providerStatus = {};

  /* ══════════════════════════════════════ */
  /* PROVIDERS                              */
  /* ══════════════════════════════════════ */

  async function loadProviders() {
    const grid = document.getElementById('airProviderGrid');
    try {
      const j = await apiCall('providers');
      if (!j.ok) { grid.innerHTML = '<div class="air-loading">Error loading providers</div>'; return; }

      if (!j.providers.length) {
        grid.innerHTML = '<div class="air-loading">No providers configured. Click "+ Add Provider" to get started.</div>';
        return;
      }

      grid.innerHTML = j.providers.map(p => {
        // Cache for editing
        _providerDataCache[p.id] = p;

        const status = providerStatus[p.id] || 'untested';
        const tc = TYPE_COLORS[p.type] || TYPE_COLORS.custom;
        const typeLabel = TYPE_LABELS[p.type] || p.type;
        const displayName = p.label || p.id;

        return `
        <div class="air-card${p.active ? ' active' : ''}" data-provider-id="${esc(p.id)}" data-type="${esc(p.type)}">
          <div class="air-card-head">
            <div class="air-card-icon ${esc(tc.css)}">${esc(tc.icon)}</div>
            <div class="air-card-head-text">
              <div class="air-card-title">${esc(displayName)}</div>
              <div class="air-card-type">${esc(typeLabel)}${p.label ? ' &middot; ' + esc(p.id) : ''}${p.baseUrl ? ' &middot; ' + esc(new URL(p.baseUrl).hostname) : ''}</div>
            </div>
            <div class="air-card-status">
              <span class="air-status-dot ${esc(status)}" title="${esc(status)}"></span>
            </div>
          </div>
          <div class="air-card-body">
            <div class="air-card-row">
              <span class="air-card-label">Model</span>
              <span class="air-card-value">${esc(p.model)}</span>
            </div>
            <div class="air-card-row">
              <span class="air-card-label">API Key</span>
              <span class="air-card-value">${esc(p.apiKey)}</span>
            </div>
            <div class="air-card-row">
              <span class="air-card-label">Max Tokens</span>
              <span class="air-card-value">${p.maxTokens || 4096}</span>
            </div>
            <div class="air-card-row">
              <span class="air-card-label">Status</span>
              <span>${p.active ? '<span class="air-tag air-tag-active">DEFAULT</span>' : '<span class="air-tag air-tag-enabled">Ready</span>'}</span>
            </div>
          </div>
          <div class="air-card-actions">
            ${!p.active ? `<button class="air-btn air-btn-success" onclick="AIR.setActive('${esc(p.id)}')">Set Default</button>` : ''}
            <button class="air-btn" onclick="AIR.testProvider('${esc(p.id)}')">Test</button>
            <button class="air-btn" onclick="AIR.editProvider('${esc(p.id)}')">Edit</button>
            <button class="air-btn air-btn-danger" onclick="AIR.removeProvider('${esc(p.id)}')">Delete</button>
          </div>
        </div>`;
      }).join('');

    } catch(e) {
      grid.innerHTML = '<div class="air-loading">Error: ' + esc(e.message) + '</div>';
    }
  }

  async function loadHealth() {
    try {
      const j = await apiCall('overview');
      if (!j.ok) return;

      const dot = document.getElementById('airHealthDot');
      const text = document.getElementById('airHealthText');

      if (j.active_provider) {
        dot.className = 'air-health-dot active';
        text.textContent = `Active: ${j.active_model || j.active_type} | ${j.provider_count} provider(s) | ${j.server_count} server(s)`;
      } else if (j.provider_count > 0) {
        dot.className = 'air-health-dot warning';
        text.textContent = 'No active provider set';
      } else {
        dot.className = 'air-health-dot error';
        text.textContent = 'No providers configured';
      }
    } catch(e) { /* silent */ }
  }

  /* ── Provider Modal ── */
  let modelInputMode = 'select'; // 'select' or 'text'

  // Cache provider data for editing
  let _providerDataCache = {};

  function openProviderModal(editId) {
    const modal = document.getElementById('airProviderModal');
    const title = document.getElementById('airProviderModalTitle');
    const $id   = document.getElementById('pmId');
    const $editId = document.getElementById('pmEditId');
    const $type = document.getElementById('pmType');
    const $model = document.getElementById('pmModel');
    const $modelCustom = document.getElementById('pmModelCustom');
    const $key  = document.getElementById('pmKey');
    const $tokens = document.getElementById('pmTokens');
    const $baseUrl = document.getElementById('pmBaseUrl');
    const $label = document.getElementById('pmLabel');

    // Reset model input mode
    setModelInputMode('select');

    if (editId) {
      title.textContent = 'Edit Provider';
      $editId.value = editId;
      $id.value = editId;
      $id.disabled = true;

      // Use cached provider data
      const pData = _providerDataCache[editId] || {};
      const typeKey = pData.type || '';
      $type.value = typeKey;
      $label.value = pData.label || '';
      updateTypeUI(typeKey);
      updateModelSelect(typeKey);

      const modelVal = pData.model || '';
      if (MODELS[typeKey] && MODELS[typeKey].some(m => m.id === modelVal)) {
        $model.value = modelVal;
      } else {
        setModelInputMode('text');
        $modelCustom.value = modelVal;
      }

      $tokens.value = pData.maxTokens || 4096;
      $baseUrl.value = pData.baseUrl || '';
      $key.value = '';
      $key.placeholder = 'Leave blank to keep current key';
    } else {
      title.textContent = 'Add Provider';
      $editId.value = '';
      $id.value = '';
      $id.disabled = false;
      $type.value = '';
      $label.value = '';
      $model.innerHTML = '<option value="">Select model...</option>';
      $modelCustom.value = '';
      $key.value = '';
      $key.placeholder = 'sk-ant-...';
      $tokens.value = 4096;
      $baseUrl.value = '';
      updateTypeUI('');
    }

    modal.style.display = '';
  }

  function updateTypeUI(type) {
    const $hint = document.getElementById('pmTypeHint');
    const $baseGroup = document.getElementById('pmBaseUrlGroup');

    if (type === 'custom') {
      $hint.style.display = '';
      $baseGroup.style.display = '';
      // Custom types always use text model input
      setModelInputMode('text');
    } else {
      $hint.style.display = 'none';
      $baseGroup.style.display = 'none';
    }
  }

  function setModelInputMode(mode) {
    modelInputMode = mode;
    const $model = document.getElementById('pmModel');
    const $custom = document.getElementById('pmModelCustom');
    const $toggle = document.getElementById('pmModelToggle');

    if (mode === 'text') {
      $model.style.display = 'none';
      $custom.style.display = '';
      $toggle.textContent = '▤';
      $toggle.title = 'Switch to dropdown';
    } else {
      $model.style.display = '';
      $custom.style.display = 'none';
      $toggle.textContent = '...';
      $toggle.title = 'Switch to text input';
    }
  }

  function updateModelSelect(type) {
    const $model = document.getElementById('pmModel');
    const models = MODELS[type] || [];

    if (models.length === 0) {
      $model.innerHTML = '<option value="">Type model ID below</option>';
      setModelInputMode('text');
      return;
    }

    $model.innerHTML = models.map(m =>
      `<option value="${esc(m.id)}">${esc(m.name)}</option>`
    ).join('');
  }

  function getSelectedModel() {
    if (modelInputMode === 'text') {
      return document.getElementById('pmModelCustom').value.trim();
    }
    return document.getElementById('pmModel').value;
  }

  async function saveProvider() {
    const editId = document.getElementById('pmEditId').value;
    const id     = document.getElementById('pmId').value.trim();
    const type   = document.getElementById('pmType').value;
    const model  = getSelectedModel();
    const key    = document.getElementById('pmKey').value;
    const tokens = parseInt(document.getElementById('pmTokens').value) || 4096;
    const baseUrl = document.getElementById('pmBaseUrl').value.trim();
    const label  = document.getElementById('pmLabel').value.trim();

    if (!id) { toast('Provider ID is required', 'error'); return; }
    if (!type) { toast('Select a provider type', 'error'); return; }
    if (!model) { toast('Model is required', 'error'); return; }

    const action = editId ? 'update_provider' : 'add_provider';
    const data = { id, type, model, maxTokens: tokens, label };
    if (key) data.apiKey = key;
    if (type === 'custom' && baseUrl) data.baseUrl = baseUrl;

    try {
      const j = await apiCall(action, data, 'POST');
      if (j.ok) {
        toast(editId ? 'Provider updated' : 'Provider added', 'success');
        document.getElementById('airProviderModal').style.display = 'none';
        loadProviders();
        loadHealth();
      } else {
        toast(j.error || 'Failed to save', 'error');
      }
    } catch(e) {
      toast('Error: ' + e.message, 'error');
    }
  }

  async function setActive(id) {
    try {
      const j = await apiCall('set_active', { id }, 'POST');
      if (j.ok) {
        toast(id + ' is now the active provider', 'success');
        loadProviders();
        loadHealth();
      } else {
        toast(j.error || 'Failed', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }

  async function testProvider(id) {
    providerStatus[id] = 'testing';
    loadProviders();

    try {
      const j = await apiCall('test_provider', { id }, 'POST');
      if (j.ok && j.result?.ok) {
        providerStatus[id] = 'ok';
        toast(`${id}: Connection OK — ${j.result.model || 'ready'}`, 'success');
      } else {
        providerStatus[id] = 'error';
        toast(`${id}: ${j.error || j.result?.message || 'Test failed'}`, 'error');
      }
    } catch(e) {
      providerStatus[id] = 'error';
      toast('Test error: ' + e.message, 'error');
    }
    loadProviders();
  }

  async function removeProvider(id) {
    if (!confirm(`Delete provider "${id}"? This cannot be undone.`)) return;
    try {
      const j = await apiCall('remove_provider', { id }, 'POST');
      if (j.ok) {
        toast('Provider removed', 'success');
        delete providerStatus[id];
        loadProviders();
        loadHealth();
      } else {
        toast(j.error || 'Failed to remove', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }


  /* ══════════════════════════════════════ */
  /* Provider Routing removed — managed per-task in AgentScheduler */


  /* ══════════════════════════════════════ */
  /* MCP SERVERS                            */
  /* ══════════════════════════════════════ */

  async function loadServers() {
    const grid = document.getElementById('airServerGrid');
    try {
      const j = await apiCall('mcp_servers');
      if (!j.ok) { grid.innerHTML = '<div class="air-loading">Error loading servers</div>'; return; }

      if (!j.servers.length) {
        grid.innerHTML = '<div class="air-loading">No MCP servers configured.</div>';
        return;
      }

      grid.innerHTML = j.servers.map(s => `
        <div class="air-card" data-server-id="${esc(s.id)}">
          <div class="air-card-head">
            <div class="air-card-icon mcp-${esc(s.type)}">${s.type === 'stdio' ? '&#9881;' : '&#127760;'}</div>
            <div class="air-card-head-text">
              <div class="air-card-title">${esc(s.name)}</div>
              <div class="air-card-type">${esc(s.type.toUpperCase())}${s.builtin ? ' &middot; Built-in' : ''}</div>
            </div>
            <div class="air-card-status">
              ${s.enabled
                ? '<span class="air-tag air-tag-enabled">Enabled</span>'
                : '<span class="air-tag air-tag-disabled">Disabled</span>'}
            </div>
          </div>
          <div class="air-card-body">
            ${s.description ? `<div class="air-card-row"><span class="air-card-label">Description</span><span class="air-card-value" style="font-family:inherit;max-width:180px;overflow:hidden;text-overflow:ellipsis">${esc(s.description)}</span></div>` : ''}
            <div class="air-card-row">
              <span class="air-card-label">Roles</span>
              <span>${(s.roles || []).map(r => `<span class="air-role-pill">${esc(r)}</span>`).join('') || '<span class="air-card-value">-</span>'}</span>
            </div>
            ${s.builtin ? '<div class="air-card-row"><span class="air-card-label">Type</span><span class="air-tag air-tag-builtin">BUILT-IN</span></div>' : ''}
          </div>
          <div class="air-card-actions">
            <button class="air-btn" onclick="AIR.testServer('${esc(s.id)}')">Test</button>
            ${!s.builtin ? `<button class="air-btn" onclick="AIR.editServer('${esc(s.id)}')">Edit</button>` : ''}
            ${!s.builtin ? `<button class="air-btn air-btn-danger" onclick="AIR.removeServer('${esc(s.id)}')">Remove</button>` : ''}
          </div>
        </div>
      `).join('');
    } catch(e) {
      grid.innerHTML = '<div class="air-loading">Error: ' + esc(e.message) + '</div>';
    }
  }

  function openServerModal(editId) {
    const modal = document.getElementById('airServerModal');
    const title = document.getElementById('airServerModalTitle');
    const $editId = document.getElementById('smEditId');

    // Reset
    document.getElementById('smName').value = '';
    document.getElementById('smType').value = 'stdio';
    document.getElementById('smCommand').value = '';
    document.getElementById('smArgs').value = '';
    document.getElementById('smUrl').value = '';
    document.getElementById('smAuthType').value = '';
    document.getElementById('smAuthKey').value = '';
    document.getElementById('smRoles').value = '';
    document.getElementById('smDesc').value = '';
    document.getElementById('smEnabled').checked = true;
    toggleServerTypeFields('stdio');

    if (editId) {
      title.textContent = 'Edit MCP Server';
      $editId.value = editId;
    } else {
      title.textContent = 'Add MCP Server';
      $editId.value = '';
    }

    modal.style.display = '';
  }

  function toggleServerTypeFields(type) {
    document.getElementById('smStdioFields').style.display = type === 'stdio' ? '' : 'none';
    document.getElementById('smHttpFields').style.display = type === 'http' ? '' : 'none';
  }

  async function saveServer() {
    const editId = document.getElementById('smEditId').value;
    const name   = document.getElementById('smName').value.trim();
    const type   = document.getElementById('smType').value;

    if (!name) { toast('Server name is required', 'error'); return; }

    const data = {
      name,
      type,
      enabled: document.getElementById('smEnabled').checked,
      roles: document.getElementById('smRoles').value.split(',').map(r => r.trim()).filter(Boolean),
      description: document.getElementById('smDesc').value.trim(),
    };

    if (type === 'stdio') {
      data.command = document.getElementById('smCommand').value.trim();
      data.args = document.getElementById('smArgs').value.split('\n').map(a => a.trim()).filter(Boolean);
    } else if (type === 'http') {
      data.url = document.getElementById('smUrl').value.trim();
      const authType = document.getElementById('smAuthType').value;
      if (authType) {
        data.auth = { type: authType, key: document.getElementById('smAuthKey').value };
      }
    }

    const action = editId ? 'mcp_update' : 'mcp_add';
    if (editId) data.id = editId;

    try {
      const j = await apiCall(action, data, 'POST');
      if (j.ok) {
        toast(editId ? 'Server updated' : 'Server added', 'success');
        document.getElementById('airServerModal').style.display = 'none';
        loadServers();
        loadHealth();
      } else {
        toast(j.error || 'Failed to save', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }

  async function testServer(id) {
    toast('Testing server ' + id + '...', 'info');
    try {
      const j = await apiCall('mcp_test', { id }, 'POST');
      if (j.ok && j.result) {
        const tools = j.result.tools_count || j.result.tools || 0;
        toast(`${id}: OK — ${tools} tool(s) available`, 'success');
      } else {
        toast(`${id}: ${j.error || 'Test failed'}`, 'error');
      }
    } catch(e) { toast('Test error: ' + e.message, 'error'); }
  }

  async function removeServer(id) {
    if (!confirm(`Remove MCP server "${id}"?`)) return;
    try {
      const j = await apiCall('mcp_remove', { id }, 'POST');
      if (j.ok) {
        toast('Server removed', 'success');
        loadServers();
        loadHealth();
      } else {
        toast(j.error || 'Cannot remove', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }


  /* ══════════════════════════════════════ */
  /* IMAGE PROVIDERS                        */
  /* ══════════════════════════════════════ */

  let _imageData = {};
  let _imageDefault = 'flux';

  async function loadImageProviders() {
    const grid = document.getElementById('airImageGrid');
    try {
      const j = await apiCall('image_providers');
      if (!j.ok) { grid.innerHTML = '<div class="air-loading">Error loading image providers</div>'; return; }

      _imageDefault = j.default_provider || 'flux';

      if (!j.image_providers || !j.image_providers.length) {
        grid.innerHTML = '<div class="air-loading">No image providers configured.</div>';
        return;
      }

      grid.innerHTML = j.image_providers.map(ip => {
        _imageData[ip.id] = ip;
        const info = IMAGE_INFO[ip.id] || { label: ip.id, icon: '?', cssClass: '' };
        const isDefault = (ip.id === _imageDefault);

        return `
        <div class="air-card${isDefault ? ' active' : ''}" data-image-id="${esc(ip.id)}">
          <div class="air-card-head">
            <div class="air-card-icon ${esc(info.cssClass)}">${esc(info.icon)}</div>
            <div class="air-card-head-text">
              <div class="air-card-title">${esc(info.label)}</div>
              <div class="air-card-type">Image Generation</div>
            </div>
            <div class="air-card-status">
              ${ip.enabled
                ? '<span class="air-tag air-tag-enabled">Enabled</span>'
                : '<span class="air-tag air-tag-disabled">Disabled</span>'}
            </div>
          </div>
          <div class="air-card-body">
            <div class="air-card-row">
              <span class="air-card-label">Model</span>
              <span class="air-card-value">${esc(ip.model)}</span>
            </div>
            <div class="air-card-row">
              <span class="air-card-label">API Key</span>
              <span class="air-card-value">${esc(ip.apiKey)}</span>
            </div>
            ${ip.size ? `<div class="air-card-row"><span class="air-card-label">Size</span><span class="air-card-value">${esc(ip.size)}</span></div>` : ''}
            ${isDefault ? '<div class="air-card-row"><span class="air-card-label">Default</span><span class="air-tag air-tag-active">DEFAULT</span></div>' : ''}
          </div>
          <div class="air-card-actions">
            <button class="air-btn" onclick="AIR.editImage('${esc(ip.id)}')">Edit</button>
          </div>
        </div>`;
      }).join('');

    } catch(e) {
      grid.innerHTML = '<div class="air-loading">Error: ' + esc(e.message) + '</div>';
    }
  }

  function openImageModal(id) {
    const info = IMAGE_INFO[id];
    const ip = _imageData[id];
    if (!info || !ip) return;

    document.getElementById('airImageModalTitle').textContent = 'Edit ' + info.label;

    let html = `
      <input type="hidden" id="imEditId" value="${esc(id)}">
      <div class="air-form-group">
        <label>
          <input type="checkbox" id="imEnabled" ${ip.enabled ? 'checked' : ''} style="width:auto;margin-right:6px">
          Enabled
        </label>
      </div>
      <div class="air-form-group">
        <label for="imKey">API Key</label>
        <input type="password" id="imKey" value="" placeholder="Leave blank to keep current" autocomplete="off">
        <small>Current: ${esc(ip.apiKey)}</small>
      </div>
      <div class="air-form-group">
        <label for="imModel">Model</label>
        <select id="imModel">
          ${info.models.map(m => `<option value="${esc(m.id)}" ${m.id === ip.model ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}
        </select>
      </div>`;

    if (info.sizes) {
      html += `
      <div class="air-form-group">
        <label for="imSize">Image Size</label>
        <select id="imSize">
          ${info.sizes.map(s => `<option value="${esc(s)}" ${s === ip.size ? 'selected' : ''}>${esc(s)}</option>`).join('')}
        </select>
      </div>`;
    }

    if (info.qualities) {
      html += `
      <div class="air-form-group">
        <label for="imQuality">Quality</label>
        <select id="imQuality">
          ${info.qualities.map(q => `<option value="${esc(q)}" ${q === ip.quality ? 'selected' : ''}>${esc(q)}</option>`).join('')}
        </select>
      </div>`;
    }

    html += `
      <div class="air-form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(99,102,241,0.1)">
        <label>
          <input type="checkbox" id="imSetDefault" ${id === _imageDefault ? 'checked' : ''} style="width:auto;margin-right:6px">
          Set as default image provider
        </label>
      </div>`;

    document.getElementById('airImageModalBody').innerHTML = html;
    document.getElementById('airImageModal').style.display = '';
  }

  async function saveImageProvider() {
    const id = document.getElementById('imEditId').value;
    const data = {
      id,
      enabled: document.getElementById('imEnabled').checked,
      model: document.getElementById('imModel').value,
    };

    const key = document.getElementById('imKey').value;
    if (key) data.apiKey = key;

    const sizeEl = document.getElementById('imSize');
    if (sizeEl) data.size = sizeEl.value;

    const qualEl = document.getElementById('imQuality');
    if (qualEl) data.quality = qualEl.value;

    if (document.getElementById('imSetDefault').checked) {
      data.defaultImageProvider = id;
    }

    try {
      const j = await apiCall('save_image_provider', data, 'POST');
      if (j.ok) {
        toast('Image provider updated', 'success');
        document.getElementById('airImageModal').style.display = 'none';
        loadImageProviders();
      } else {
        toast(j.error || 'Failed to save', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }


  /* ══════════════════════════════════════ */
  /* MODAL RELOCATION                       */
  /* ══════════════════════════════════════ */

  // Relocate modals to document.body to escape backdrop-filter stacking context
  ['airProviderModal', 'airServerModal', 'airImageModal', 'airLogModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el && el.parentNode !== document.body) document.body.appendChild(el);
  });

  // Activity Log toggle
  document.getElementById('airLogToggle').addEventListener('click', () => {
    const modal = document.getElementById('airLogModal');
    modal.style.display = modal.style.display === 'none' ? '' : 'none';
    if (modal.style.display !== 'none') {
      _logUnread = 0;
      const badge = document.getElementById('airLogBadge');
      if (badge) badge.style.display = 'none';
      // Scroll to bottom
      const entries = document.getElementById('airLogEntries');
      if (entries) entries.scrollTop = entries.scrollHeight;
    }
  });

  // Clear log
  document.getElementById('airLogClear').addEventListener('click', () => {
    _logEntries.length = 0;
    const entries = document.getElementById('airLogEntries');
    if (entries) entries.innerHTML = '<div class="air-log-empty">No activity yet</div>';
  });


  /* ══════════════════════════════════════ */
  /* PUSH GLOBAL                            */
  /* ══════════════════════════════════════ */

  async function pushGlobal() {
    const includeMcp = confirm('Also push MCP server settings?\n\nOK = Push providers + MCP servers\nCancel = Push providers only');
    const btn = document.getElementById('airPushGlobal');
    btn.disabled = true;
    btn.textContent = 'Pushing...';

    try {
      const j = await apiCall('push_global', { include_mcp: includeMcp }, 'POST');
      btn.disabled = false;
      btn.textContent = 'Push to All Sites';

      if (j.ok) {
        let msg = `Settings pushed to ${j.updated} site(s) from ${j.source}.`;
        if (j.errors && j.errors.length) {
          msg += '\n\nErrors:\n' + j.errors.join('\n');
        }
        toast(msg, j.errors?.length ? 'warning' : 'success');
      } else {
        toast(j.error || 'Push failed', 'error');
      }
    } catch(e) {
      btn.disabled = false;
      btn.textContent = 'Push to All Sites';
      toast('Error: ' + e.message, 'error');
    }
  }


  /* ══════════════════════════════════════ */
  /* NOTEBOOKLM CONFIG                      */
  /* ══════════════════════════════════════ */

  async function loadNlmConfig() {
    const badge       = document.getElementById('airNlmBadge');
    const apiDot      = document.getElementById('nlmApiDot');
    const apiLabel    = document.getElementById('nlmApiLabel');
    const accountWrap = document.getElementById('nlmActiveAccount');
    const accountEmail = document.getElementById('nlmAccountEmail');
    const accountProject = document.getElementById('nlmAccountProject');

    try {
      const j = await apiCall('get_state');
      if (!j.ok) return;
      const nlm = j.data?.config?.notebookLM || {};
      const enabled = document.getElementById('nlmEnabled');
      const project = document.getElementById('nlmProjectId');

      if (enabled) enabled.checked = !!nlm.enabled;
      if (project) project.value = nlm.projectId || '';

      const hasKey = !!(nlm.serviceAccountKey && nlm.serviceAccountKey.client_email);
      const email  = hasKey ? nlm.serviceAccountKey.client_email : '';
      const projId = nlm.projectId || (hasKey ? nlm.serviceAccountKey.project_id : '') || '';

      // Badge
      if (badge) {
        if (!hasKey) {
          badge.textContent = 'Not Installed';
          badge.className = 'air-tag';
        } else if (nlm.enabled) {
          badge.textContent = 'Enabled';
          badge.className = 'air-tag enabled';
        } else {
          badge.textContent = 'Disabled';
          badge.className = 'air-tag';
        }
      }

      // Active account display
      if (accountWrap) {
        if (hasKey) {
          accountWrap.style.display = '';
          if (accountEmail) accountEmail.textContent = email;
          if (accountProject) accountProject.textContent = projId || '—';
        } else {
          accountWrap.style.display = 'none';
        }
      }

      // Key field hint
      const keyField = document.getElementById('nlmServiceKey');
      if (keyField && hasKey) {
        keyField.placeholder = 'Key set for ' + email + ' — paste new key to replace';
      }

      // Auto-test connection if key is installed
      if (hasKey && nlm.enabled) {
        if (apiDot) apiDot.className = 'air-status-dot testing';
        if (apiLabel) apiLabel.textContent = 'Validating...';

        try {
          const test = await apiCall('nlm_test_connection', {}, 'POST');
          const result = test.result || {};
          if (result.ok) {
            if (apiDot) apiDot.className = 'air-status-dot ok';
            if (apiLabel) apiLabel.textContent = 'API Valid';
          } else {
            if (apiDot) apiDot.className = 'air-status-dot error';
            if (apiLabel) { apiLabel.textContent = 'Invalid'; apiLabel.title = result.message || ''; }
          }
        } catch(e) {
          if (apiDot) apiDot.className = 'air-status-dot error';
          if (apiLabel) apiLabel.textContent = 'Error';
        }
      } else if (hasKey && !nlm.enabled) {
        if (apiDot) apiDot.className = 'air-status-dot untested';
        if (apiLabel) apiLabel.textContent = 'Disabled';
      } else {
        if (apiDot) apiDot.className = 'air-status-dot untested';
        if (apiLabel) apiLabel.textContent = 'Not Installed';
      }

    } catch(e) {
      if (apiDot) apiDot.className = 'air-status-dot error';
      if (apiLabel) apiLabel.textContent = 'Load Error';
    }
  }

  async function saveNlmConfig() {
    const enabled   = document.getElementById('nlmEnabled').checked;
    const projectId = document.getElementById('nlmProjectId').value.trim();
    const keyRaw    = document.getElementById('nlmServiceKey').value.trim();

    const data = { enabled, projectId };
    if (keyRaw) {
      try {
        JSON.parse(keyRaw);
        data.serviceAccountKey = keyRaw;
      } catch(e) {
        toast('Service account key must be valid JSON', 'error');
        return;
      }
    }

    try {
      const j = await apiCall('nlm_save_config', data, 'POST');
      if (j.ok) {
        toast('NotebookLM config saved — validating...', 'success');
        document.getElementById('nlmServiceKey').value = '';
        await loadNlmConfig();
      } else {
        toast(j.error || 'Failed to save', 'error');
      }
    } catch(e) { toast('Error: ' + e.message, 'error'); }
  }

  async function testNlmConnection() {
    const statusEl = document.getElementById('nlmStatus');
    const apiDot   = document.getElementById('nlmApiDot');
    const apiLabel = document.getElementById('nlmApiLabel');

    statusEl.style.display = '';
    statusEl.className = '';
    statusEl.textContent = 'Testing...';
    if (apiDot) apiDot.className = 'air-status-dot testing';
    if (apiLabel) apiLabel.textContent = 'Testing...';

    try {
      const j = await apiCall('nlm_test_connection', {}, 'POST');
      const result = j.result || {};
      if (result.ok) {
        statusEl.className = 'success';
        statusEl.textContent = result.message;
        if (apiDot) apiDot.className = 'air-status-dot ok';
        if (apiLabel) apiLabel.textContent = 'API Valid';
      } else {
        statusEl.className = 'error';
        statusEl.textContent = result.message || j.error || 'Test failed';
        if (apiDot) apiDot.className = 'air-status-dot error';
        if (apiLabel) apiLabel.textContent = 'Invalid';
      }
    } catch(e) {
      statusEl.className = 'error';
      statusEl.textContent = 'Error: ' + e.message;
      if (apiDot) apiDot.className = 'air-status-dot error';
      if (apiLabel) apiLabel.textContent = 'Error';
    }
  }


  /* ══════════════════════════════════════ */
  /* EVENT BINDINGS                         */
  /* ══════════════════════════════════════ */

  // Push global
  document.getElementById('airPushGlobal').addEventListener('click', () => {
    if (!confirm('Push AI provider settings from this site to ALL other sites on this server?')) return;
    pushGlobal();
  });

  // Provider modal
  document.getElementById('airAddProvider').addEventListener('click', () => openProviderModal(null));
  document.getElementById('pmSave').addEventListener('click', saveProvider);
  document.getElementById('pmType').addEventListener('change', (e) => {
    const type = e.target.value;
    updateTypeUI(type);
    updateModelSelect(type);
  });
  document.getElementById('pmModelToggle').addEventListener('click', () => {
    setModelInputMode(modelInputMode === 'select' ? 'text' : 'select');
  });

  // Server modal
  document.getElementById('airAddServer').addEventListener('click', () => openServerModal(null));
  document.getElementById('smSave').addEventListener('click', saveServer);
  document.getElementById('smType').addEventListener('change', (e) => toggleServerTypeFields(e.target.value));
  document.getElementById('smAuthType').addEventListener('change', (e) => {
    document.getElementById('smAuthKeyGroup').style.display = e.target.value ? '' : 'none';
  });

  // Image modal
  document.getElementById('imSave').addEventListener('click', saveImageProvider);

  // NotebookLM config
  document.getElementById('nlmSave').addEventListener('click', saveNlmConfig);
  document.getElementById('nlmTest').addEventListener('click', testNlmConnection);
  document.getElementById('nlmInfoToggle').addEventListener('click', (e) => {
    e.preventDefault();
    const panel = document.getElementById('nlmInfoPanel');
    const link = document.getElementById('nlmInfoToggle');
    if (panel.style.display === 'none') {
      panel.style.display = '';
      link.textContent = 'Hide setup info';
    } else {
      panel.style.display = 'none';
      link.textContent = 'How to set up';
    }
  });

  // Modal close buttons
  document.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.close);
      if (target) target.style.display = 'none';
    });
  });

  // Close modals on backdrop click
  document.querySelectorAll('.air-modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) backdrop.style.display = 'none';
    });
  });

  // Escape closes modals
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.air-modal-backdrop').forEach(m => m.style.display = 'none');
    }
  });


  /* ══════════════════════════════════════ */
  /* PUBLIC API (for onclick handlers)      */
  /* ══════════════════════════════════════ */

  window.AIR = {
    setActive,
    testProvider,
    editProvider: (id) => openProviderModal(id),
    removeProvider,
    testServer,
    editServer: (id) => openServerModal(id),
    removeServer,
    editImage: (id) => openImageModal(id),
  };


  /* ══════════════════════════════════════ */
  /* INIT                                   */
  /* ══════════════════════════════════════ */

  loadHealth();
  loadProviders();
  loadServers();
  loadImageProviders();
  loadNlmConfig();

})();
