<?php
/**
 * Luminal CMS — Gallery Manager Module
 *
 * Unified gallery management for images, videos, PDFs, and combined galleries.
 *
 * @package    LuminalCMS
 * @module     GalleryManager
 * @version    1.0.0
 * @file       /admin/modules/GalleryManager/GalleryManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/auth.php';

requireAuth();

$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Artist', ENT_QUOTES, 'UTF-8');

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Gallery Manager</h1>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/GalleryManager/css/gallery-manager.css') ?>">

<div class="gm-wrap">
    <div class="gm-topbar">
      <div class="gm-tabs">
        <div class="gm-tab active" data-filter="all">All</div>
        <div class="gm-tab" data-filter="images">Image</div>
        <div class="gm-tab" data-filter="videos">Video</div>
        <div class="gm-tab" data-filter="pdfs">PDF</div>
        <div class="gm-tab" data-filter="combined">Combined</div>
      </div>
      <button class="gm-btn" id="btn-new-gallery"><i class="fa fa-plus"></i> New Gallery</button>
    </div>
    <div class="gm-chooser" id="gm-chooser">
      <div class="gm-chooser-bar">
        <div class="gm-chooser-title">Your Galleries</div>
        <div class="gm-chooser-search">
          <span>&#128269;</span>
          <input type="text" id="chooser-filter" placeholder="Filter galleries…" spellcheck="false">
        </div>
        <div class="gm-viewtoggle" title="View mode">
          <button class="gm-vt-btn" id="chooser-view-thumbs" title="Thumbnails"><span>&#9638;</span></button>
          <button class="gm-vt-btn" id="chooser-view-list" title="List"><span>&#9776;</span></button>
        </div>
      </div>
      <div class="gm-chooser-body" id="chooser-body"></div>
    </div>
    <div class="gm-main">
      <div class="gm-browser">
        <iframe src="/admin/shared/explorer/media-explorer.php?host=gallery" id="media-browser-frame"></iframe>
      </div>

      <div class="gm-editor" id="editor-panel">
        <div class="gm-field">
          <label>Gallery Title</label>
          <input type="text" id="ed-title" placeholder="My Gallery">
        </div>
        <div class="gm-field-row">
          <div class="gm-field">
            <label>Type</label>
            <select id="ed-type">
              <option value="images">Image</option>
              <option value="videos">Video</option>
              <option value="pdfs">PDF</option>
              <option value="combined">Combined</option>
            </select>
          </div>
          <div class="gm-field">
            <label>Layout</label>
            <select id="ed-layout">
              <option value="grid">Grid</option>
              <option value="masonry">Masonry</option>
              <option value="rows">Rows</option>
            </select>
          </div>
          <div class="gm-field">
            <label>Columns</label>
            <input type="range" id="ed-columns" min="1" max="8" value="3">
            <span id="ed-columns-val" style="font-size:.75rem;color:var(--gm-muted);">3</span>
          </div>
          <div class="gm-field">
            <label>Row Height</label>
            <input type="range" id="ed-rowheight" min="80" max="600" value="240" step="10">
            <span id="ed-rowheight-val" style="font-size:.75rem;color:var(--gm-muted);">240px</span>
          </div>
        </div>

        <div class="gm-field-row">
          <div class="gm-field" style="flex-direction:row;align-items:center;gap:.5rem;flex:2;">
            <input type="checkbox" id="ed-sort-tools" style="width:auto;margin:0;">
            <label for="ed-sort-tools" style="margin:0;cursor:pointer;text-transform:none;">Show sort tools on the front end</label>
          </div>
          <div class="gm-field">
            <label>Default sort</label>
            <select id="ed-sort-default">
              <option value="newest">Most Recent</option>
              <option value="oldest">Oldest</option>
              <option value="az">A &rarr; Z</option>
              <option value="za">Z &rarr; A</option>
              <option value="custom">As arranged</option>
            </select>
          </div>
        </div>

        <div class="gm-tray-label">Gallery Items (<span id="tray-count">0</span>)</div>
        <div class="gm-tray" id="editor-tray"></div>

        <!-- sticky footer: Save/actions stay pinned even when the tray is tall -->
        <div class="gm-editor-footer">
          <div class="gm-shortcode" id="shortcode-display" style="display:none;">
            <span id="shortcode-text"></span>
            <button id="btn-copy-shortcode">Copy</button>
          </div>

          <div class="gm-actions">
            <button class="gm-btn gm-btn-green" id="btn-save"><i class="fa fa-save"></i> Save</button>
            <button class="gm-btn gm-btn-outline" id="btn-clear"><i class="fa fa-eraser"></i> Clear</button>
            <button class="gm-btn gm-btn-amber" id="btn-regen-thumbs" style="display:none;" title="Regenerate missing thumbnails for items in this gallery"><i class="fa fa-images"></i> Regen Thumbs</button>
            <button class="gm-btn gm-btn-red" id="btn-delete" style="display:none;"><i class="fa fa-trash"></i> Delete</button>
          </div>
        </div>
      </div>
    </div>
</div>

<div class="gm-toast" id="gm-toast"></div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function(){
'use strict';

const API = '/admin/modules/GalleryManager/api/gallery-manager-api.php';
const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

// State
let galleries = [];
let currentFilter = 'all';
let editingSlug = '';
let editingType = '';
let trayItems = []; // [{path, mediaType}]

// Elements
const chooserBody   = $('#chooser-body');
const chooserFilter = $('#chooser-filter');
let chooserView     = localStorage.getItem('gm_chooser_view') || 'thumbs';
let chooserQuery    = '';
const tray          = $('#editor-tray');
const trayCount     = $('#tray-count');
const edTitle       = $('#ed-title');
const edType        = $('#ed-type');
const edLayout      = $('#ed-layout');
const edColumns     = $('#ed-columns');
const edColumnsVal  = $('#ed-columns-val');
const edRowheight   = $('#ed-rowheight');
const edRowheightVal= $('#ed-rowheight-val');
const edSortTools   = $('#ed-sort-tools');
const edSortDefault = $('#ed-sort-default');
const shortcodeDisp = $('#shortcode-display');
const shortcodeText = $('#shortcode-text');
const btnDelete     = $('#btn-delete');
const toastEl       = $('#gm-toast');

// Helpers
function toast(msg, type='info'){
  toastEl.textContent = msg;
  toastEl.className = 'gm-toast show ' + type;
  setTimeout(()=> toastEl.classList.remove('show'), 3000);
}

function mediaTypeFromPath(path){
  const ext = path.split('.').pop().toLowerCase();
  if (['jpg','jpeg','png','gif','webp','avif','svg'].includes(ext)) return 'image';
  if (['mp4','webm','mov','m4v','ogv'].includes(ext)) return 'video';
  if (ext === 'pdf') return 'pdf';
  return 'unknown';
}

function filenameFromPath(p){ return p.split('/').pop(); }

// Sliders
edColumns.addEventListener('input', ()=> edColumnsVal.textContent = edColumns.value);
edRowheight.addEventListener('input', ()=> edRowheightVal.textContent = edRowheight.value + 'px');

// ── API calls ──
async function apiCall(action, opts={}){
  const isGet = opts.method === 'GET';
  let url = API + '?action=' + action;
  if (isGet && opts.params) {
    for (const [k,v] of Object.entries(opts.params)) url += '&' + k + '=' + encodeURIComponent(v);
  }
  const fetchOpts = {};
  if (!isGet) {
    fetchOpts.method = 'POST';
    fetchOpts.headers = {'Content-Type':'application/json'};
    fetchOpts.body = JSON.stringify(Object.assign({action}, opts.body || {}));
  }
  const res = await fetch(url, fetchOpts);
  return res.json();
}

// ── Load galleries ──
async function loadGalleries(){
  const data = await apiCall('list_galleries', {method:'GET'});
  if (data.success) galleries = data.galleries || [];
  renderChooser();
}

// ── Gallery chooser (List / Thumbs) ──
const GM_TYPE_LABELS = {images:'Image',videos:'Video',pdfs:'PDF',combined:'Combined'};
const GM_TYPE_COLORS = {images:'#38bdf8',videos:'#a78bfa',pdfs:'#f43f5e',combined:'#fbbf24'};
const GM_IMG_EXT = ['jpg','jpeg','png','gif','webp','avif','svg'];

function gmFmtBytes(b){ b=b||0; if(b<1024) return b+' B'; const u=['KB','MB','GB']; let i=-1; do{b/=1024;i++;}while(b>=1024&&i<u.length-1); return b.toFixed(1)+' '+u[i]; }
function gmFmtDate(s){ if(!s) return ''; const d=new Date(s); if(isNaN(d.getTime())) return ''; return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'}); }

function gmCoverHtml(g){
  const cover = g.cover || '';
  const iconFor = t => t==='videos'?'🎬':(t==='pdfs'?'📄':'🖼️');
  if(!cover){ return `<div class="gm-cover-fallback">${iconFor(g.type)}</div>`; }
  const url = cover.startsWith('/') ? cover : '/'+cover;
  const ext = (cover.split('.').pop()||'').toLowerCase();
  if(GM_IMG_EXT.includes(ext)){
    return `<img class="gm-cover" loading="lazy" src="${url}" alt="" onerror="this.outerHTML='<div class=gm-cover-fallback>🖼️</div>'">`;
  }
  const dir = url.substring(0, url.lastIndexOf('/'));
  const base = cover.split('/').pop().replace(/\.[^.]+$/,'');
  const thumb = dir+'/.cache/'+base+'.jpg';
  return `<img class="gm-cover" loading="lazy" src="${thumb}" alt="" onerror="this.outerHTML='<div class=gm-cover-fallback>${iconFor(g.type)}</div>'">`;
}

function renderChooser(){
  // Type tab + text filter
  let list = galleries.slice();
  if(currentFilter !== 'all') list = list.filter(g => g.type === currentFilter);
  const q = chooserQuery.trim().toLowerCase();
  if(q) list = list.filter(g => (g.title||'').toLowerCase().includes(q) || (g.slug||'').toLowerCase().includes(q));

  document.getElementById('chooser-view-thumbs').classList.toggle('active', chooserView==='thumbs');
  document.getElementById('chooser-view-list').classList.toggle('active', chooserView==='list');

  if(list.length === 0){
    chooserBody.className = 'gm-chooser-body';
    chooserBody.innerHTML = `<div class="gm-chooser-empty">${galleries.length ? 'No galleries match this filter.' : 'No galleries yet — pick media on the right and Save to create one.'}</div>`;
    return;
  }

  chooserBody.className = 'gm-chooser-body ' + chooserView;
  chooserBody.innerHTML = list.map(g => {
    const active = (editingSlug===g.slug && editingType===g.type) ? ' active' : '';
    const color  = GM_TYPE_COLORS[g.type] || '#94a3b8';
    const n      = g.itemCount;
    const meta   = `${GM_TYPE_LABELS[g.type]||g.type} · ${n} item${n===1?'':'s'}`
                 + `${g.bytes ? ' · '+gmFmtBytes(g.bytes) : ''}`
                 + `${g.updated ? ' · '+gmFmtDate(g.updated) : ''}`;
    const sc = buildShortcode(g.type, g.slug);
    return `<div class="gm-gcard${active}" data-slug="${esc(g.slug)}" data-type="${g.type}">
        <div class="gm-gcard-thumb">${gmCoverHtml(g)}<span class="gm-gcard-badge" style="background:${color}">${(GM_TYPE_LABELS[g.type]||'').substring(0,3)}</span></div>
        <div class="gm-gcard-info">
          <div class="gm-gcard-title" title="${esc(g.title)}">${esc(g.title)}</div>
          <div class="gm-gcard-meta">${esc(meta)}</div>
          <div class="gm-gcard-sc">${esc(sc)}</div>
        </div>
        <div class="gm-gcard-actions">
          <button class="gm-ga gm-ga-edit" title="Edit"><i class="fa fa-pen"></i></button>
          <button class="gm-ga gm-ga-copy" title="Copy shortcode"><i class="fa fa-copy"></i></button>
          <button class="gm-ga gm-ga-del" title="Delete"><i class="fa fa-trash"></i></button>
        </div>
      </div>`;
  }).join('');

  chooserBody.querySelectorAll('.gm-gcard').forEach(card => {
    const type = card.dataset.type, slug = card.dataset.slug;
    card.addEventListener('click', e => { if(e.target.closest('.gm-ga')) return; loadGalleryToEditor(type, slug); });
    card.querySelector('.gm-ga-edit').addEventListener('click', e => { e.stopPropagation(); loadGalleryToEditor(type, slug); });
    card.querySelector('.gm-ga-copy').addEventListener('click', e => { e.stopPropagation(); const sc=buildShortcode(type,slug); navigator.clipboard.writeText(sc).then(()=>toast('Copied: '+sc,'success')); });
    card.querySelector('.gm-ga-del').addEventListener('click', e => { e.stopPropagation(); deleteGallery(type, slug); });
  });
}

function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

async function deleteGallery(type, slug) {
  if (!confirm('Delete gallery "' + slug + '"?')) return;
  const data = await apiCall('delete_gallery', {body:{type, slug}});
  if (data.success) {
    toast('Deleted', 'success');
    if (editingSlug === slug && editingType === type) clearEditor();
    await loadGalleries();
  } else {
    toast(data.message || 'Delete failed', 'error');
  }
}

function buildShortcode(type, slug){
  if (type === 'combined') return '[[gallery:' + slug + ']]';
  const map = {images:'image-gallery',videos:'video-gallery',pdfs:'pdf-gallery'};
  return '[[' + (map[type]||type) + ':' + slug + ']]';
}

// ── Load gallery into editor ──
async function loadGalleryToEditor(type, slug){
  const data = await apiCall('get_gallery', {method:'GET', params:{type, slug}});
  if (!data.success){ toast(data.message||'Failed to load','error'); return; }
  const g = data.gallery;
  editingSlug = slug;
  editingType = type;
  edTitle.value = g.title || slug;
  edType.value = type;
  edType.disabled = true;
  edLayout.value = g.layout || 'grid';
  edColumns.value = g.columns || 3;
  edColumnsVal.textContent = edColumns.value;
  edRowheight.value = g.rowheight || 240;
  edRowheightVal.textContent = edRowheight.value + 'px';
  edSortTools.checked = !!g.sort_tools;
  edSortDefault.value = g.sort_default || 'newest';

  trayItems = [];
  (g.items || []).forEach(item => {
    if (typeof item === 'string') {
      trayItems.push({path: item, mediaType: mediaTypeFromPath(item)});
    } else if (item && item.path) {
      trayItems.push({path: item.path, mediaType: item.mediaType || mediaTypeFromPath(item.path)});
    }
  });
  renderTray();
  showShortcode(type, slug);
  btnDelete.style.display = '';
  document.getElementById('btn-regen-thumbs').style.display = '';
  renderChooser();
  toast('Loaded: ' + (g.title||slug), 'info');
}

function showShortcode(type, slug){
  const sc = buildShortcode(type, slug);
  shortcodeText.textContent = sc;
  shortcodeDisp.style.display = '';
}

// ── Render tray ──
function renderTray(){
  tray.innerHTML = '';
  trayItems.forEach((item, idx) => {
    const el = document.createElement('div');
    el.className = 'gm-tray-item';
    el.dataset.index = idx;
    const fname = filenameFromPath(item.path);
    const dir = item.path.substring(0, item.path.lastIndexOf('/'));
    const base = fname.replace(/\.[^.]+$/, '');
    const cacheThumb = '/' + dir + '/.cache/' + base + '.jpg';
    let content = '';
    if (item.mediaType === 'image') {
      content = `<img src="/${esc(item.path)}" alt="${esc(fname)}">`;
    } else if (item.mediaType === 'video') {
      content = `<img src="${esc(cacheThumb)}" alt="${esc(fname)}" onerror="this.outerHTML='<div class=tray-video><i class=fa\\ fa-play-circle></i></div>'">`;
    } else if (item.mediaType === 'pdf') {
      // First-page thumb (Explorer/MediaThumbs keeps these in .cache/)
      content = `<img src="${esc(cacheThumb)}" alt="${esc(fname)}" onerror="this.outerHTML='<div class=tray-pdf><i class=fa\\ fa-file-pdf></i></div>'">`;
    } else {
      content = `<div class="tray-pdf"><i class="fa fa-file"></i></div>`;
    }
    el.innerHTML = content + `<div class="tray-label">${esc(fname)}</div><button class="tray-remove" data-idx="${idx}">&times;</button>`;
    tray.appendChild(el);
  });
  if (!trayItems.length) {
    tray.innerHTML = '<div class="gm-tray-empty">Drag files here from the browser<br>— or click one and use “Add to Gallery”</div>';
  }
  trayCount.textContent = trayItems.length;

  // Remove buttons — resolve index from current DOM position, not data-idx
  // (Sortable reorders the DOM without re-rendering, so data-idx goes stale)
  tray.querySelectorAll('.tray-remove').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const itemEl = btn.closest('.gm-tray-item');
      const idx = Array.prototype.indexOf.call(tray.querySelectorAll('.gm-tray-item'), itemEl);
      if (idx >= 0) { trayItems.splice(idx, 1); renderTray(); }
    });
  });
}

// Sortable tray
new Sortable(tray, {
  animation: 150,
  ghostClass: 'drag-over',
  filter: '.gm-tray-empty',
  onEnd: (evt) => {
    const moved = trayItems.splice(evt.oldIndex, 1)[0];
    trayItems.splice(evt.newIndex, 0, moved);
    // renderTray(); — Sortable handles DOM reorder; re-rendering causes drag flicker
    trayCount.textContent = trayItems.length;
  }
});

// ── Add media to the tray (shared by drag-drop + lightbox Add button) ──
function addMediaToTray(path){
  if (!path) return;
  // Avoid duplicates
  if (trayItems.some(it => it.path === path)) {
    toast('Already in tray', 'info');
    return;
  }
  const mt = mediaTypeFromPath(path);
  trayItems.push({path, mediaType: mt});
  renderTray();
  toast('Added: ' + filenameFromPath(path), 'success');

  // Auto-detect gallery type (only if creating new, not editing)
  if (!edType.disabled) {
    const typeMap = {image:'images', video:'videos', pdf:'pdfs'};
    const mediaTypes = new Set(trayItems.map(it => it.mediaType));
    if (mediaTypes.size > 1) {
      // Mixed media → combined
      if (edType.value !== 'combined') {
        edType.value = 'combined';
        toast('Type auto-set to Combined (mixed media)', 'info');
      }
    } else if (trayItems.length === 1 && typeMap[mt]) {
      edType.value = typeMap[mt];
      toast('Type auto-set to ' + mt.charAt(0).toUpperCase() + mt.slice(1), 'info');
    }
  }
}

// ── Receive media from iframe (lightbox "Add to Gallery" button) ──
window.addEventListener('message', e => {
  if (!e.data) return;
  if (e.data.type === 'mediaSelected') { addMediaToTray(e.data.path); return; }
  // Explorer broadcasts the drag lifecycle — light the tray up as the target
  if (e.data.type === 'mb-drag-start') tray.classList.add('gm-tray-armed');
  if (e.data.type === 'mb-drag-end')   tray.classList.remove('gm-tray-armed');
});

// ── Drag-and-drop from the Explorer iframe into the tray ──
function mbDragPayload(dt){
  let raw = '';
  try { raw = dt.getData('application/x-mb-drag') || ''; } catch(_) {}
  if (!raw) {
    let t = ''; try { t = dt.getData('text/plain') || ''; } catch(_) {}
    if (t.startsWith('__mb_drag__\n')) raw = t.slice('__mb_drag__\n'.length);
  }
  if (!raw) return null;
  try { return JSON.parse(raw); } catch(_) { return null; }
}
['dragenter','dragover'].forEach(ev => tray.addEventListener(ev, e => {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'copy';
  tray.classList.add('drag-over');
}));
tray.addEventListener('dragleave', e => {
  if (!tray.contains(e.relatedTarget)) tray.classList.remove('drag-over');
});
tray.addEventListener('drop', e => {
  e.preventDefault();
  tray.classList.remove('drag-over');
  tray.classList.remove('gm-tray-armed');
  const payload = mbDragPayload(e.dataTransfer);
  if (!payload || !Array.isArray(payload.paths)) return;
  payload.paths.forEach(p => addMediaToTray(p));
});

// ── Tab filter ──
$$('.gm-tab').forEach(tab => {
  tab.addEventListener('click', ()=>{
    $$('.gm-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentFilter = tab.dataset.filter;
    renderChooser();
  });
});

// ── New Gallery ──
$('#btn-new-gallery').addEventListener('click', ()=>{
  clearEditor();
  edType.disabled = false;
  toast('New gallery - pick items and save', 'info');
});

// ── Clear ──
$('#btn-clear').addEventListener('click', clearEditor);
function clearEditor(){
  editingSlug = '';
  editingType = '';
  edTitle.value = '';
  edType.value = 'images';
  edType.disabled = false;
  edLayout.value = 'grid';
  edColumns.value = 3;
  edColumnsVal.textContent = '3';
  edRowheight.value = 240;
  edRowheightVal.textContent = '240px';
  edSortTools.checked = false;
  edSortDefault.value = 'newest';
  trayItems = [];
  renderTray();
  shortcodeDisp.style.display = 'none';
  btnDelete.style.display = 'none';
  document.getElementById('btn-regen-thumbs').style.display = 'none';
  renderChooser();
}

// ── Save ──
$('#btn-save').addEventListener('click', async ()=>{
  const title = edTitle.value.trim();
  if (!title){ toast('Title required','error'); return; }
  if (trayItems.length === 0){ toast('Add items first','error'); return; }

  const type = edType.value;
  // For non-combined, store items as plain string arrays for backward compat
  let items;
  if (type === 'combined') {
    items = trayItems.map(it => ({path: it.path, mediaType: it.mediaType}));
  } else {
    items = trayItems.map(it => it.path);
  }

  const body = {
    type,
    title,
    slug: editingSlug || undefined,
    layout: edLayout.value,
    columns: parseInt(edColumns.value),
    rowheight: parseInt(edRowheight.value),
    sort_tools: edSortTools.checked,
    sort_default: edSortDefault.value,
    items
  };

  toast('Saving...','info');
  const data = await apiCall('save_gallery', {body});
  if (data.success){
    editingSlug = data.slug;
    editingType = type;
    edType.disabled = true;
    showShortcode(type, data.slug);
    btnDelete.style.display = '';
    toast('Saved! ' + (data.shortcode||''), 'success');
    if (data.thumbs) {
      const t = data.thumbs;
      if (t.generated > 0) toast('Generated ' + t.generated + ' thumbnails', 'info');
    }
    await loadGalleries();
  } else {
    toast(data.message || 'Save failed', 'error');
  }
});

// ── Delete ──
$('#btn-delete').addEventListener('click', async ()=>{
  if (!editingSlug || !editingType) return;
  if (!confirm('Delete gallery "' + edTitle.value + '"?')) return;
  const data = await apiCall('delete_gallery', {body:{type: editingType, slug: editingSlug}});
  if (data.success){
    toast('Deleted','success');
    clearEditor();
    await loadGalleries();
  } else {
    toast(data.message||'Delete failed','error');
  }
});

// ── Regenerate Thumbnails ──
$('#btn-regen-thumbs').addEventListener('click', async ()=>{
  if (!trayItems.length) { toast('No items in tray','error'); return; }
  const btn = document.getElementById('btn-regen-thumbs');
  btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Regenerating...';
  let generated = 0, failed = 0, skipped = 0;
  for (const item of trayItems) {
    const kind = item.mediaType || 'image';
    try {
      const r = await fetch('/admin/modules/MediaThumbs/api/media-cache.php?action=ensure_thumb&kind=' + encodeURIComponent(kind) + '&src=' + encodeURIComponent(item.path));
      const d = await r.json();
      if (d.success) generated++; else skipped++;
    } catch(e) { failed++; }
  }
  btn.disabled = false; btn.innerHTML = '<i class="fa fa-images"></i> Regen Thumbs';
  toast('Thumbnails: ' + generated + ' generated, ' + skipped + ' skipped' + (failed ? ', ' + failed + ' failed' : ''), generated > 0 ? 'success' : 'info');
  renderTray(); // refresh to show new thumbs
});

// ── Copy shortcode ──
$('#btn-copy-shortcode').addEventListener('click', ()=>{
  navigator.clipboard.writeText(shortcodeText.textContent).then(()=> toast('Copied!','success'));
});

// ── Chooser view toggle + filter ──
document.getElementById('chooser-view-thumbs').addEventListener('click', ()=>{ chooserView='thumbs'; localStorage.setItem('gm_chooser_view','thumbs'); renderChooser(); });
document.getElementById('chooser-view-list').addEventListener('click', ()=>{ chooserView='list'; localStorage.setItem('gm_chooser_view','list'); renderChooser(); });
chooserFilter.addEventListener('input', ()=>{ chooserQuery = chooserFilter.value; renderChooser(); });

// ── Init ──
loadGalleries();

})();
</script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
