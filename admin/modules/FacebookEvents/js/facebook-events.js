/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FacebookEvents v1.0.0
 * File: js/facebook-events.js
 *
 * IIFE — AJAX-driven UI for Facebook Events configuration.
 * Replaces original form-POST workflow with async fetch calls.
 */
(function(){
'use strict';

const $ = s => document.querySelector(s);
const API = (window.FBE_BOOT && FBE_BOOT.endpoints) ? FBE_BOOT.endpoints.api : '';

function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

/* ===== TOAST ===== */
function toast(msg, type) {
  type = type || 'info';
  const el = document.createElement('div');
  el.className = 'fbe-toast fbe-toast-' + type;
  el.textContent = msg;
  $('#fbe-toasts').appendChild(el);
  requestAnimationFrame(function() { el.classList.add('show'); });
  setTimeout(function() { el.classList.remove('show'); setTimeout(function() { el.remove(); }, 300); }, 3000);
}

/* ===== STATUS BAR ===== */
function showStatus(msg, type) {
  const el = $('#fbe-status');
  el.textContent = msg;
  el.className = 'fbe-status ' + type;
  setTimeout(function() { el.className = 'fbe-status'; }, 4000);
}

/* ===== API HELPERS ===== */
function post(action, params) {
  const fd = new FormData();
  fd.append('action', action);
  if (params) {
    Object.keys(params).forEach(function(k) { fd.append(k, params[k]); });
  }
  return fetch(API, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
}

function get(action) {
  return fetch(API + '?action=' + encodeURIComponent(action)).then(function(r) { return r.json(); });
}

/* ===== LOG RENDERING ===== */
function renderLog(entries) {
  var el = $('#fbe-log');
  if (!entries || !entries.length) return;
  var html = '';
  entries.forEach(function(e) {
    html += '<div class="fbe-log-line fbe-log-' + esc(e.type) + '">' + esc(e.msg) + '</div>';
  });
  el.innerHTML = html;
  el.scrollTop = el.scrollHeight;
}

function appendLog(entries) {
  var el = $('#fbe-log');
  if (!entries || !entries.length) return;
  entries.forEach(function(e) {
    var div = document.createElement('div');
    div.className = 'fbe-log-line fbe-log-' + e.type;
    div.textContent = e.msg;
    el.appendChild(div);
  });
  el.scrollTop = el.scrollHeight;
}

/* ===== POPULATE FORM ===== */
function applyConfig(cfg) {
  $('#fbe-app-id').value    = cfg.fb_app_id || '';
  // Keep current token if loaded (masked tokens shouldn't overwrite real input)
  if ($('#fbe-token').value === '' && cfg.access_token) {
    $('#fbe-token').value = cfg.access_token;
  }
  $('#fbe-page-id').value   = cfg.page_id || '';
  $('#fbe-fallback').value  = cfg.fallback_url || '';
  $('#fbe-layout').value      = cfg.layout || cfg.view_mode || 'grid';
  $('#fbe-columns').value     = cfg.columns || 'auto';
  $('#fbe-image-shape').value = cfg.image_shape || 'natural';
  $('#fbe-per-page').value  = cfg.events_per_page || 10;
  $('#fbe-limit').value     = cfg.limit_events || 100;
  $('#fbe-timeframe').value = cfg.time_frame || 'upcoming';
  $('#fbe-cache-dur').value = cfg.cache_duration || 3600;

  var opts = cfg.display_options || {};
  $('#fbe-show-desc').checked   = !!cfg.show_description;
  $('#fbe-show-loc').checked    = opts.show_location !== false;
  $('#fbe-show-time').checked   = opts.show_time !== false;
  $('#fbe-show-cancel').checked = !!opts.show_canceled;
}

function collectForm() {
  return {
    fb_app_id:       $('#fbe-app-id').value.trim(),
    access_token:    $('#fbe-token').value.trim(),
    page_id:         $('#fbe-page-id').value.trim(),
    fallback_url:    $('#fbe-fallback').value.trim(),
    layout:          $('#fbe-layout').value,
    view_mode:       $('#fbe-layout').value,
    columns:         $('#fbe-columns').value,
    image_shape:     $('#fbe-image-shape').value,
    events_per_page: $('#fbe-per-page').value,
    limit_events:    $('#fbe-limit').value,
    time_frame:      $('#fbe-timeframe').value,
    cache_duration:  $('#fbe-cache-dur').value,
    show_description:$('#fbe-show-desc').checked ? '1' : '0',
    show_location:   $('#fbe-show-loc').checked ? '1' : '0',
    show_time:       $('#fbe-show-time').checked ? '1' : '0',
    show_canceled:   $('#fbe-show-cancel').checked ? '1' : '0',
  };
}

/* ===== VALIDATION DISPLAY ===== */
function showValidation(v) {
  var ind = $('#fbe-indicator');
  if (v.token && v.page) {
    ind.textContent = '\u2714';
    ind.className = 'fbe-indicator ok';
    ind.title = 'Connected';
  } else {
    ind.textContent = '\u2718';
    ind.className = 'fbe-indicator fail';
    ind.title = v.error || 'Check configuration';
  }

  // Token badge
  var tb = $('#fbe-token-badge');
  tb.textContent = v.token ? 'VALID' : 'INVALID';
  tb.className = 'fbe-badge ' + (v.token ? 'valid' : 'invalid');

  // App ID badge
  var ab = $('#fbe-app-badge');
  if (v.app_id_match === true) {
    ab.textContent = 'MATCH'; ab.className = 'fbe-badge valid';
  } else if (v.app_id_match === false) {
    ab.textContent = 'MISMATCH'; ab.className = 'fbe-badge invalid';
  } else {
    ab.className = 'fbe-badge';
  }

  // Resolved entity info
  var res = $('#fbe-resolved');
  if (v.page && v.entity_name) {
    $('#fbe-res-name').textContent = v.entity_name;
    $('#fbe-res-type').textContent = v.entity_type + ' (ID: ' + v.entity_id + ')';
    $('#fbe-res-cats').textContent = (v.categories || []).join(', ');
    res.style.display = 'block';
  } else {
    res.style.display = 'none';
  }
}

/* ===== PREVIEW DISPLAY ===== */
function showPreview(event) {
  var el = $('#fbe-preview');
  if (!event) {
    el.innerHTML = '<div class="fbe-empty">No events returned by API.</div>';
    return;
  }
  var coverSrc = (event.cover && event.cover.source) ? event.cover.source : '';
  var startDate = event.start_time ? new Date(event.start_time).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '';
  var location = (event.place && event.place.name) ? event.place.name : 'Location N/A';

  var html = '<div class="fbe-preview-card">';
  if (coverSrc) html += '<img src="' + esc(coverSrc) + '" class="fbe-preview-img" alt="">';
  html += '<div>';
  html += '<div class="fbe-preview-title">' + esc(event.name || 'Untitled') + '</div>';
  html += '<div class="fbe-preview-date">' + esc(startDate) + '</div>';
  html += '<div class="fbe-preview-loc">' + esc(location) + '</div>';
  html += '</div></div>';
  el.innerHTML = html;
}

/* ===== ACTIONS ===== */
async function loadConfig() {
  try {
    var r = await get('load');
    if (r.ok) applyConfig(r.config);
    else toast(r.error || 'Load failed', 'error');
  } catch(e) { toast('Network error', 'error'); }
}

async function saveConfig() {
  try {
    showStatus('Saving...', 'info');
    var r = await post('save', collectForm());
    if (r.ok) {
      showStatus('Saved & cache cleared', 'success');
      toast('Settings saved', 'success');
      if (r.log) appendLog(r.log);
    } else {
      showStatus(r.error || 'Save failed', 'error');
      toast(r.error || 'Save failed', 'error');
    }
  } catch(e) { toast('Network error', 'error'); }
}

async function clearCache() {
  try {
    showStatus('Clearing cache and fetching fresh events...', 'info');
    var r = await post('clear_cache');
    if (r.ok) {
      // Now force a fresh pull to re-populate the cache
      var r2 = await get('preview');
      if (r2.ok) {
        showPreview(r2.event);
        showValidation(r2.validation);
        if (r2.log) renderLog(r2.log);
        showStatus('Cache cleared — fresh data pulled from Facebook', 'success');
        toast('Cache cleared — fresh events loaded from Facebook', 'success');
      } else {
        showStatus('Cache cleared — preview refresh failed', 'error');
        toast('Cache cleared but preview failed: ' + (r2.error || ''), 'error');
      }
    } else {
      toast(r.error || 'Failed', 'error');
    }
  } catch(e) { toast('Network error', 'error'); }
}

async function resolveId() {
  var pageId = $('#fbe-page-id').value.trim();
  var token = $('#fbe-token').value.trim();
  if (!pageId || !token) { toast('Enter page ID and token first', 'error'); return; }

  try {
    var r = await post('resolve_id', { page_id: pageId, access_token: token });
    if (r.ok) {
      $('#fbe-page-id').value = r.page_id;
      toast('Resolved: ' + r.page_id, 'success');
      if (r.log) appendLog(r.log);
    } else {
      toast(r.error || 'Resolve failed', 'error');
    }
  } catch(e) { toast('Network error', 'error'); }
}

async function validate() {
  try {
    showStatus('Validating...', 'info');
    var r = await get('preview');
    if (r.ok) {
      showValidation(r.validation);
      showPreview(r.event);
      if (r.log) renderLog(r.log);
      showStatus(r.validation.token && r.validation.page ? 'Connected' : 'Check configuration', r.validation.token && r.validation.page ? 'success' : 'error');
    } else {
      toast(r.error || 'Validation failed', 'error');
    }
  } catch(e) { toast('Network error', 'error'); }
}

/* ===== INIT ===== */
function init() {
  $('#fbe-save').onclick = saveConfig;
  $('#fbe-clear-cache').onclick = clearCache;
  $('#fbe-resolve').onclick = resolveId;
  $('#fbe-validate').onclick = validate;
  if ($('#fbe-export-json')) {
    $('#fbe-export-json').onclick = async function() {
      const res = await api('export_config');
      if (res.ok && res.config_json) {
        const json = JSON.stringify(res.config_json, null, 2);
        await navigator.clipboard.writeText(json);
        alert('FB config JSON copied to clipboard!\n\nPaste into AgentScheduler > FB Events task > Facebook Events Configuration field.');
      } else {
        alert('Export failed: ' + (res.error || 'Unknown error'));
      }
    };
  }

  loadConfig();
}

document.addEventListener('DOMContentLoaded', init);

})();
