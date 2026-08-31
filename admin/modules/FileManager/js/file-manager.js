/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FileManager v1.0.0
 * File: js/file-manager.js
 *
 * Dual-pane file browser — IIFE with FM_BOOT config injection.
 */
(function(){
'use strict';

/* ===== STATE ===== */
const THUMB_SIZES = [48, 64, 72, 96, 120, 160, 200];
const THUMB_DEFAULT = 2; // index into THUMB_SIZES (72px)
/**
 * State is split into per-pane bundles (paneA, paneB) so dual-pane mode
 * can track two independent directory listings. Back-compat: existing
 * code uses S.cwd / S.items / S.sel / etc. — those are defined as
 * getters/setters below that route to whichever pane is currently active.
 * Shared state (tree, clipboard, theme, sidebar width) lives directly on S.
 */
function makePane() {
  return {
    cwd: '',
    items: [],
    sel: new Set(),
    sort: { col: localStorage.getItem('fm_sort_col') || 'name', dir: localStorage.getItem('fm_sort_dir') || 'asc' },
    view: localStorage.getItem('fm_view') || 'list',
    thumbIdx: parseInt(localStorage.getItem('fm_thumb_idx')) || THUMB_DEFAULT,
    thumbFit: localStorage.getItem('fm_thumb_fit') || 'cover',
    history: [],
    histIdx: -1,
    lastClickIdx: -1,
  };
}

const S = {
  // Shared
  tree: {},          // path -> [{name, hasChildren}]
  treeOpen: new Set(),
  clipboard: null,   // {action:'copy'|'cut', cwd, names:[]}
  theme: localStorage.getItem('fm_theme') || 'dark',
  fontPref: localStorage.getItem('fm_font_pref') || 'site',
  sidebarW: parseInt(localStorage.getItem('fm_sidebar_w')) || 240,

  // Panes
  paneA: makePane(),
  paneB: null,                     // lazy-created on first entry to dual mode
  activePane: 'A',
  dualMode: localStorage.getItem('fm_dual_mode') === '1',
};

/* Active-pane compatibility accessors — every existing reference to
 * S.cwd / S.items / S.sel / etc. transparently reads or writes the
 * currently active pane's state bundle. No call sites need changing. */
const PANE_FIELDS = ['cwd','items','sel','sort','view','thumbIdx','thumbFit','history','histIdx','lastClickIdx'];
PANE_FIELDS.forEach(f => {
  Object.defineProperty(S, f, {
    get() { return this[this.activePane === 'A' ? 'paneA' : 'paneB'][f]; },
    set(v) { this[this.activePane === 'A' ? 'paneA' : 'paneB'][f] = v; },
    configurable: true, enumerable: true
  });
});

/* ===== HELPERS ===== */
const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);
const API_URL = (window.FM_BOOT && FM_BOOT.endpoints) ? FM_BOOT.endpoints.api : '';

// View root — the tree + navigation are anchored here. Default '' is the full
// site tree; when the server scopes us to /media (the default view, full tree
// off) it is 'media' and everything above is invisible/uncreachable.
const ROOT = ((window.FM_BOOT && FM_BOOT.startPath) ? FM_BOOT.startPath : '').replace(/^\/+|\/+$/g, '');
function withinRoot(p) {
  p = (p || '').replace(/^\/+|\/+$/g, '');
  if (!ROOT) return true;
  return p === ROOT || (p + '/').startsWith(ROOT + '/');
}

function hsize(b) {
  const u = ['B','KB','MB','GB','TB']; let i = 0; let n = b;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return n.toFixed(1) + ' ' + u[i];
}
function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}
function ext(name) {
  const i = name.lastIndexOf('.'); return i > 0 ? name.slice(i+1).toLowerCase() : '';
}
function joinPath(a, b) {
  a = a.replace(/\/+$/, ''); b = b.replace(/^\/+/, '');
  return a ? a + '/' + b : b;
}
function fmtDate(ts) {
  if (!ts) return '';
  const d = new Date(ts * 1000);
  return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
}

/* ===== ICONS ===== */
const ICON_MAP = {
  folder: '\u{1F4C1}', default: '\u{1F4C4}',
  jpg:'\u{1F5BC}', jpeg:'\u{1F5BC}', png:'\u{1F5BC}', gif:'\u{1F5BC}', webp:'\u{1F5BC}', svg:'\u{1F5BC}', avif:'\u{1F5BC}', bmp:'\u{1F5BC}',
  mp4:'\u{1F3AC}', webm:'\u{1F3AC}', ogg:'\u{1F3AC}', ogv:'\u{1F3AC}', m4v:'\u{1F3AC}', mov:'\u{1F3AC}', avi:'\u{1F3AC}',
  mp3:'\u{1F3B5}', wav:'\u{1F3B5}', flac:'\u{1F3B5}', aac:'\u{1F3B5}', m4a:'\u{1F3B5}',
  pdf:'\u{1F4D5}', zip:'\u{1F4E6}', tar:'\u{1F4E6}', gz:'\u{1F4E6}', rar:'\u{1F4E6}',
  php:'\u{1F4DD}', js:'\u{1F4DD}', css:'\u{1F4DD}', html:'\u{1F4DD}', htm:'\u{1F4DD}', json:'\u{1F4DD}', xml:'\u{1F4DD}', md:'\u{1F4DD}', txt:'\u{1F4DD}', sql:'\u{1F4DD}', log:'\u{1F4DD}', ini:'\u{1F4DD}', conf:'\u{1F4DD}', sh:'\u{1F4DD}',
};
function fileIcon(item) {
  if (item.isDir) return ICON_MAP.folder;
  return ICON_MAP[ext(item.name)] || ICON_MAP.default;
}
function isImage(name) { return /\.(jpe?g|png|gif|webp|svg|avif|bmp)$/i.test(name); }
function isVideo(name) { return /\.(mp4|webm|ogg|ogv|m4v|mov|avi)$/i.test(name); }
function isText(name)  { return /\.(php|js|css|html?|json|xml|md|txt|sql|log|ini|conf|sh|htaccess|env|csv|yml|yaml|toml|py|rb|pl|c|h|cpp|java|ts|tsx|jsx|scss|less|svg|tpl|twig|blade\.php)$/i.test(name); }
function isZip(name)   { return /\.zip$/i.test(name); }
function isAudio(name) { return /\.(mp3|wav|ogg|m4a|aac|flac|wma)$/i.test(name); }

// Robust video thumbnail: if the server can't render a frame (no ffmpeg on
// shared hosting), grab one client-side from a hidden <video> → canvas.
// Exposed on window so the grid's inline onerror can reach it from global scope.
window.__fmVideoPoster = function (img, encPath) {
  const path = decodeURIComponent(encPath);
  const v = document.createElement('video');
  v.muted = true; v.preload = 'metadata'; v.crossOrigin = 'anonymous'; v.playsInline = true;
  let done = false;
  const parent = img.parentNode;
  const icon = () => { if (done) return; done = true; try { v.removeAttribute('src'); v.load(); } catch (e) {} if (parent) parent.innerHTML = '\u{1F3AC}'; };
  v.addEventListener('error', icon);
  v.addEventListener('loadeddata', () => { try { v.currentTime = Math.min(1, (v.duration || 2) / 2); } catch (e) { icon(); } });
  v.addEventListener('seeked', () => {
    if (done) return;
    try {
      const w = v.videoWidth, h = v.videoHeight;
      if (!w || !h) { icon(); return; }
      const s = Math.min(1, 320 / w);
      const c = document.createElement('canvas');
      c.width = Math.round(w * s); c.height = Math.round(h * s);
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      done = true; try { v.removeAttribute('src'); v.load(); } catch (e) {}
      img.src = c.toDataURL('image/jpeg', 0.82);
    } catch (e) { icon(); }
  });
  setTimeout(icon, 8000);
  v.src = API.previewUrl(path);   // same-origin stream endpoint
};

/* ===== API LAYER ===== */
const API = {
  _get(action, params = {}) {
    const u = new URL(API_URL, location.origin);
    u.searchParams.set('action', action);
    for (const [k,v] of Object.entries(params)) u.searchParams.set(k, v);
    return fetch(u).then(r => r.json());
  },
  _post(action, body = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k,v] of Object.entries(body)) {
      if (Array.isArray(v)) v.forEach(i => fd.append(k + '[]', i));
      else fd.append(k, v);
    }
    return fetch(API_URL, { method: 'POST', body: fd }).then(r => r.json());
  },
  list(p)           { return this._get('list', {path: p}); },
  dirs(p)           { return this._get('dirs', {path: p}); },
  stat(p)           { return this._get('stat', {path: p}); },
  mkdir(p, name)    { return this._post('mkdir', {path: p, name}); },
  rename(p, name)   { return this._post('rename', {path: p, name}); },
  del(p)            { return this._post('delete', {path: p}); },
  copy(p, to)       { return this._post('copy', {path: p, to}); },
  move(p, to)       { return this._post('move', {path: p, to}); },
  read(p)           { return this._get('read', {path: p}); },
  write(p, content) { return this._post('write', {path: p, content}); },
  zip(p, items, name){ return this._post('zip', {path: p, items, name}); },
  unzip(p, to)      { return this._post('unzip', {path: p, to}); },
  backup(kind)      { return this._post('backup', {kind}); },
  listBackups()     { return this._get('list_backups'); },
  chownFix()        { return this._post('chown_fix'); },
  diskUsage(kind)   { return this._get('disk_usage', {kind: kind || 'app'}); },

  downloadUrl(p)    { return API_URL + '?action=download&path=' + encodeURIComponent(p); },
  previewUrl(p)     { return API_URL + '?action=preview&path=' + encodeURIComponent(p); },
  thumbUrl(p)       { return API_URL + '?action=thumb_video&path=' + encodeURIComponent(p); },
  thumbAudioUrl(p)  { return API_URL + '?action=thumb_audio&path=' + encodeURIComponent(p); },

  upload(path, files, onProgress) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('path', path);
      for (let i = 0; i < files.length; i++) fd.append('files[]', files[i]);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL);
      if (onProgress) xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      xhr.onload = () => { try { resolve(JSON.parse(xhr.responseText)); } catch(e) { reject(e); } };
      xhr.onerror = () => reject(new Error('Upload failed'));
      xhr.send(fd);
    });
  }
};

/* ===== TOAST ===== */
const Toast = {
  show(msg, type = 'info', ms = 3000) {
    const el = document.createElement('div');
    el.className = 'fm-toast fm-toast-' + type;
    el.textContent = msg;
    $('#fm-toasts').appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); }, ms);
  }
};

/* ===== MODAL ===== */
const Modal = {
  show(title, bodyHTML, buttons = []) {
    $('#fm-modal-title').textContent = title;
    $('#fm-modal-body').innerHTML = bodyHTML;
    const footer = $('#fm-modal-footer');
    footer.innerHTML = '';
    buttons.forEach(b => {
      const btn = document.createElement('button');
      btn.className = 'fm-btn ' + (b.cls || '');
      btn.textContent = b.label;
      btn.onclick = () => { if (b.action) b.action(); if (b.close !== false) Modal.hide(); };
      footer.appendChild(btn);
    });
    $('#fm-modal').style.display = 'flex';
    const inp = $('#fm-modal-body input[type="text"]');
    if (inp) { inp.focus(); inp.select(); }
  },
  hide() { $('#fm-modal').style.display = 'none'; },

  confirm(msg, onYes) {
    this.show('Confirm', '<p>' + esc(msg) + '</p>', [
      { label: 'Cancel' },
      { label: 'OK', cls: 'fm-btn-primary', action: onYes }
    ]);
  },
  // Like confirm() but takes pre-built HTML (caller escapes) — used by the
  // verbose delete dialog that lists the exact files being removed.
  confirmHtml(title, bodyHTML, onYes, yesLabel = 'OK') {
    this.show(title, bodyHTML, [
      { label: 'Cancel' },
      { label: yesLabel, cls: 'fm-btn-primary', action: onYes }
    ]);
  },
  prompt(title, label, val, onOk) {
    this.show(title, '<label>' + esc(label) + '</label><input type="text" id="fm-prompt-val" value="' + esc(val) + '">', [
      { label: 'Cancel' },
      { label: 'OK', cls: 'fm-btn-primary', action: () => onOk($('#fm-prompt-val').value.trim()) }
    ]);
    const inp = $('#fm-prompt-val');
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); onOk(inp.value.trim()); Modal.hide(); }});
  },
  preview(path, name) {
    const isImg = isImage(name);
    const isVid = isVideo(name);
    const isPdf = /\.pdf$/i.test(name);
    const url = API.previewUrl(path);
    let mediaHTML = '';
    if (isImg) {
      mediaHTML = '<img src="' + esc(url) + '" class="fm-preview-media" alt="' + esc(name) + '">';
    } else if (isVid) {
      mediaHTML = '<video src="' + esc(url) + '" class="fm-preview-media" controls autoplay></video>';
    } else if (isPdf) {
      mediaHTML = '<iframe src="' + esc(url) + '" class="fm-preview-pdf"></iframe>';
    }
    $('#fm-modal-title').textContent = name;
    $('#fm-modal-body').innerHTML = '<div class="fm-preview-wrap">' + mediaHTML + '</div>';
    const footer = $('#fm-modal-footer');
    footer.innerHTML = '';
    const btnClose = document.createElement('button');
    btnClose.className = 'fm-btn';
    btnClose.textContent = 'Close';
    btnClose.onclick = () => Modal.hide();
    const btnOpen = document.createElement('button');
    btnOpen.className = 'fm-btn';
    btnOpen.textContent = 'Open in Tab';
    btnOpen.onclick = () => window.open(url, '_blank');
    footer.appendChild(btnOpen);
    footer.appendChild(btnClose);
    const box = $('.fm-modal-box');
    box.classList.add('fm-preview-box');
    $('#fm-modal').style.display = 'flex';
    const origHide = Modal.hide;
    Modal.hide = () => { box.classList.remove('fm-preview-box'); origHide.call(Modal); Modal.hide = origHide; };
  },
  playAudio(path, name) {
    const url = API.previewUrl(path);
    $('#fm-modal-title').textContent = name;
    $('#fm-modal-body').innerHTML = '<div style="text-align:center;padding:20px 0">' +
      '<div style="font-size:2.5rem;margin-bottom:12px">&#127925;</div>' +
      '<div style="font-size:0.9rem;font-weight:700;color:#e0e0e0;margin-bottom:16px">' + esc(name) + '</div>' +
      '<audio id="fm-audio-player" controls autoplay style="width:100%;max-width:400px;margin:0 auto;display:block">' +
      '<source src="' + esc(url) + '" type="audio/mpeg">Your browser does not support audio.</audio>' +
      '</div>';
    const footer = $('#fm-modal-footer');
    footer.innerHTML = '';
    const btnDl = document.createElement('button');
    btnDl.className = 'fm-btn';
    btnDl.textContent = 'Download';
    btnDl.onclick = () => { const a = document.createElement('a'); a.href = url; a.download = name; a.click(); };
    const btnClose = document.createElement('button');
    btnClose.className = 'fm-btn';
    btnClose.textContent = 'Close';
    btnClose.onclick = () => { const p = $('#fm-audio-player'); if (p) p.pause(); Modal.hide(); };
    footer.appendChild(btnDl);
    footer.appendChild(btnClose);
    $('#fm-modal').style.display = 'flex';
    const origHide = Modal.hide;
    Modal.hide = () => { const p = $('#fm-audio-player'); if (p) p.pause(); origHide.call(Modal); Modal.hide = origHide; };
  },
  editor(path, content) {
    const fileName = path.split('/').pop();
    const fileExt = ext(fileName).toUpperCase() || 'TEXT';
    $('#fm-modal-title').textContent = '';
    $('#fm-modal-body').innerHTML =
      '<div class="fm-editor-header">' +
        '<span class="fm-editor-path" title="' + esc(path) + '">' + esc(path) + '</span>' +
        '<span class="fm-editor-type">' + fileExt + '</span>' +
      '</div>' +
      '<textarea class="fm-editor-textarea" id="fm-editor-ta" spellcheck="false" wrap="off"></textarea>' +
      '<div class="fm-editor-status" id="fm-editor-status">Ready &middot; <kbd>Ctrl+S</kbd> save &middot; <kbd>Esc</kbd> save &amp; close &middot; autosaves after 1.5s idle</div>';
    const footer = $('#fm-modal-footer');
    footer.innerHTML = '';

    // Autosave state — debounced save 1.5s after last keystroke. Same
    // pattern as PageManager's scratch pad so editors feel consistent.
    let autosaveTimer = null;
    let savingNow = false;

    async function doSave() {
      const ta = $('#fm-editor-ta');
      if (!ta || ta.dataset.dirty !== '1' || savingNow) return;
      savingNow = true;
      const status = $('#fm-editor-status');
      if (status) status.innerHTML = 'Saving\u2026';
      try {
        await Ops.writeFile(path, ta.value);
        ta.dataset.dirty = '0';
        if (status) status.innerHTML = 'Saved ' + new Date().toLocaleTimeString() + ' &middot; <kbd>Ctrl+S</kbd> save &middot; <kbd>Esc</kbd> save &amp; close';
      } finally {
        savingNow = false;
      }
    }
    function scheduleAutosave() {
      if (autosaveTimer) clearTimeout(autosaveTimer);
      autosaveTimer = setTimeout(doSave, 1500);
    }
    async function saveAndClose() {
      if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
      await doSave();
      Modal.hide();
    }

    const btnCancel = document.createElement('button');
    btnCancel.className = 'fm-btn';
    btnCancel.textContent = 'Close';
    btnCancel.onclick = saveAndClose;   // "Close" now saves first (matches Esc)

    const btnSave = document.createElement('button');
    btnSave.className = 'fm-btn fm-btn-primary';
    btnSave.textContent = 'Save';
    btnSave.onclick = doSave;

    footer.appendChild(btnCancel);
    footer.appendChild(btnSave);

    const box = $('.fm-modal-box');
    box.classList.add('fm-editor-box');
    $('#fm-modal').style.display = 'flex';

    const ta = $('#fm-editor-ta');
    ta.value = content;
    ta.dataset.dirty = '0';
    ta.focus();

    // track dirty state + autosave debounce
    ta.addEventListener('input', () => {
      ta.dataset.dirty = '1';
      const status = $('#fm-editor-status');
      if (status) status.innerHTML = 'Editing\u2026 &middot; autosaving in 1.5s';
      scheduleAutosave();
    });

    // Ctrl+S / Cmd+S save, Esc save + close, Tab inserts 2 spaces
    ta.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        doSave();
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();  // stop the global Esc handler from also firing (would close without save)
        saveAndClose();
        return;
      }
      if (e.key === 'Tab') {
        e.preventDefault();
        const start = ta.selectionStart, end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + '  ' + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + 2;
        ta.dataset.dirty = '1';
        scheduleAutosave();
      }
    });

    // cleanup class on close + flush pending autosave
    const origHide = Modal.hide;
    Modal.hide = () => {
      if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
      // If user closes by clicking the overlay or pressing Esc via global handler,
      // still flush the pending save synchronously-ish (best-effort).
      const ta2 = $('#fm-editor-ta');
      if (ta2 && ta2.dataset.dirty === '1' && !savingNow) doSave();
      box.classList.remove('fm-editor-box');
      origHide.call(Modal);
      Modal.hide = origHide;
    };
  }
};

/* ===== NAVIGATION ===== */
const Nav = {
  go(path, pushHistory = true) {
    path = (path || '').replace(/^\/+/, '').replace(/\/+$/, '');
    S.cwd = path;
    S.sel.clear();
    S.lastClickIdx = -1;
    if (pushHistory) {
      S.history = S.history.slice(0, S.histIdx + 1);
      S.history.push(path);
      S.histIdx = S.history.length - 1;
    }
    $('#fm-address').value = path || '/';
    // Mirror pane B's cwd into its own address readout when dual mode is on
    if (S.dualMode) {
      const bAddr = $('#fm-address-b');
      if (bAddr) bAddr.value = (S.activePane === 'B' ? path : (S.paneB && S.paneB.cwd) || '') || '/';
    }
    // Persist per-pane cwd so reload restores both panes (was losing pane B on refresh).
    try {
      localStorage.setItem('fm_cwd_' + S.activePane, path);
      if (S.paneB) localStorage.setItem('fm_cwd_B', S.paneB.cwd || '');
    } catch(_){}
    Files.load(path);
    Tree.highlight(path);
    Toolbar.update();
  },
  back()    { if (S.histIdx > 0) { S.histIdx--; Nav.go(S.history[S.histIdx], false); } },
  forward() { if (S.histIdx < S.history.length - 1) { S.histIdx++; Nav.go(S.history[S.histIdx], false); } },
  up()      { const parts = S.cwd.split('/').filter(Boolean); parts.pop(); let p = parts.join('/'); if (!withinRoot(p)) p = ROOT; Nav.go(p); },
  home()    { Nav.go(ROOT); },

  /**
   * Arrow-key / Home / End / PgUp / PgDn navigation through the current
   * listing. Without shift: moves selection to the new index. With shift:
   * extends the selection range from S.lastClickIdx to the new index (range
   * selection behavior that matches file managers in the wild).
   *
   * Left/Right behave column-aware in grid view (using the rendered width);
   * in list view they behave like Up/Down.
   */
  moveSelection(key, shift) {
    const total = S.items.length;
    if (!total) return;
    const anchor = [...S.sel].pop();
    const anchorIdx = anchor ? S.items.findIndex(i => i.name === anchor) : -1;
    let cur = anchorIdx >= 0 ? anchorIdx : 0;

    // Grid column count: derive from rendered items' offsetTop deltas
    let colCount = 1;
    if (S.view === 'grid') {
      const grid = document.querySelectorAll('.fm-grid-item');
      if (grid.length >= 2) {
        const firstTop = grid[0].offsetTop;
        let c = 0;
        for (const el of grid) { if (el.offsetTop !== firstTop) break; c++; }
        colCount = Math.max(1, c);
      }
    }

    const viewportStep = S.view === 'grid' ? colCount * 4 : 10;
    let next = cur;
    switch (key) {
      case 'ArrowUp':    next = cur - (S.view === 'grid' ? colCount : 1); break;
      case 'ArrowDown':  next = cur + (S.view === 'grid' ? colCount : 1); break;
      case 'ArrowLeft':  next = cur - 1; break;
      case 'ArrowRight': next = cur + 1; break;
      case 'Home':       next = 0; break;
      case 'End':        next = total - 1; break;
      case 'PageUp':     next = cur - viewportStep; break;
      case 'PageDown':   next = cur + viewportStep; break;
    }
    next = Math.max(0, Math.min(total - 1, next));
    const nextName = S.items[next].name;

    if (shift && S.lastClickIdx >= 0) {
      // Range select from anchor to next
      S.sel.clear();
      const lo = Math.min(S.lastClickIdx, next), hi = Math.max(S.lastClickIdx, next);
      for (let i = lo; i <= hi; i++) S.sel.add(S.items[i].name);
    } else {
      S.sel.clear();
      S.sel.add(nextName);
      S.lastClickIdx = next;
    }
    Sel.update();

    // Scroll the focused row/tile into view
    const sel = '[data-name="' + CSS.escape(nextName) + '"]';
    const el = document.querySelector('.fm-file-row' + sel) || document.querySelector('.fm-grid-item' + sel);
    if (el) el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
};

/* ===== TREE ===== */
const Tree = {
  async load(path) {
    try {
      const r = await API.dirs(path);
      if (r.ok) { S.tree[path] = r.dirs; this.render(); }
    } catch(e) { console.error('Tree load error:', e); }
  },
  render() {
    const root = $('#fm-tree');
    root.innerHTML = '';
    const ul = this._buildLevel(ROOT, 0);
    root.appendChild(ul);
  },
  _buildLevel(path, depth) {
    const ul = document.createElement('ul');
    const dirs = S.tree[path] || [];
    dirs.forEach(d => {
      const fullPath = joinPath(path, d.name);
      const li = document.createElement('li');
      const row = document.createElement('div');
      row.className = 'fm-tree-item' + (fullPath === S.cwd ? ' active' : '');
      row.style.setProperty('--depth', depth);
      row.dataset.path = fullPath;

      const caret = document.createElement('span');
      caret.className = 'fm-tree-caret' + (S.treeOpen.has(fullPath) ? ' open' : '') + (d.hasChildren ? '' : ' empty');
      caret.textContent = '\u25B6';
      caret.onclick = e => { e.stopPropagation(); this.toggle(fullPath); };

      const icon = document.createElement('span');
      icon.className = 'fm-tree-icon';
      icon.textContent = '\u{1F4C1}';

      const label = document.createElement('span');
      label.className = 'fm-tree-label';
      label.textContent = d.name;

      row.appendChild(caret);
      row.appendChild(icon);
      row.appendChild(label);

      // click: navigate into folder + expand tree
      row.onclick = () => {
        Nav.go(fullPath);
        if (!S.treeOpen.has(fullPath)) this.toggle(fullPath);
      };

      // right-click: folder context menu
      row.addEventListener('contextmenu', e => {
        e.preventDefault();
        e.stopPropagation();
        Ctx.show(e.clientX, e.clientY, { anchorEl: row, treeTarget: { name: d.name, path: fullPath } });
      });

      // drag-drop target
      row.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; row.classList.add('drag-over'); });
      row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
      row.addEventListener('drop', e => { e.preventDefault(); row.classList.remove('drag-over'); DnD.handleMoveDrop(fullPath); });

      li.appendChild(row);

      if (S.treeOpen.has(fullPath) && S.tree[fullPath]) {
        li.appendChild(this._buildLevel(fullPath, depth + 1));
      }
      ul.appendChild(li);
    });
    return ul;
  },
  async toggle(path) {
    if (S.treeOpen.has(path)) {
      S.treeOpen.delete(path);
    } else {
      S.treeOpen.add(path);
      if (!S.tree[path]) await this.load(path);
    }
    this.render();
  },
  highlight(path) {
    // ensure ancestors are open
    const parts = path.split('/').filter(Boolean);
    let acc = '';
    for (let i = 0; i < parts.length; i++) {
      acc = i === 0 ? parts[i] : acc + '/' + parts[i];
      if (i < parts.length) {
        const parent = i === 0 ? '' : parts.slice(0, i).join('/');
        if (!S.treeOpen.has(parent) && parent !== '' && S.tree[parent] === undefined) {
          S.treeOpen.add(parent);
        }
      }
    }
    // load any missing ancestors
    this._ensureAncestors(path).then(() => this.render());
  },
  async _ensureAncestors(path) {
    const parts = path.split('/').filter(Boolean);
    let acc = '';
    for (let i = 0; i < parts.length; i++) {
      const parent = i === 0 ? '' : parts.slice(0, i).join('/');
      if (!S.tree[parent]) await this.load(parent);
      S.treeOpen.add(parent);
      acc = i === 0 ? parts[i] : acc + '/' + parts[i];
    }
  }
};

/* ===== FILES ===== */
const Files = {
  async load(path) {
    try {
      const r = await API.list(path);
      if (!r.ok) { Toast.show(r.error || 'Failed to list', 'error'); return; }
      S.items = r.items || [];
      this.sort();
    } catch(e) { Toast.show('Network error', 'error'); }
  },
  sort() {
    const {col, dir} = S.sort;
    const mult = dir === 'asc' ? 1 : -1;
    S.items.sort((a, b) => {
      // dirs always first
      if (a.isDir && !b.isDir) return -1;
      if (!a.isDir && b.isDir) return 1;
      let va, vb;
      switch(col) {
        case 'name': va = a.name.toLowerCase(); vb = b.name.toLowerCase(); return va < vb ? -1*mult : va > vb ? mult : 0;
        case 'size': return (a.size - b.size) * mult;
        case 'mtime': return (a.mtime - b.mtime) * mult;
        case 'type': va = ext(a.name); vb = ext(b.name); return va < vb ? -1*mult : va > vb ? mult : 0;
        default: return 0;
      }
    });
    this.render();
  },
  /**
   * Active-pane elements — which DOM pane the active state renders into.
   * When dual-mode is on, both panes exist; active routes to A or B.
   */
  _activeEl() { return $(S.activePane === 'A' ? '#fm-content' : '#fm-content-b'); },
  _inactiveEl() { return $(S.activePane === 'A' ? '#fm-content-b' : '#fm-content'); },
  render() {
    // Render active pane using live S (getters route to active pane state).
    this._renderPane(S, this._activeEl(), true);
    // If dual mode, also render the inactive pane from its snapshot.
    if (S.dualMode) {
      const other = S.activePane === 'A' ? S.paneB : S.paneA;
      if (other) this._renderPane(other, this._inactiveEl(), false);
    }
    this.updateStatus();
  },
  _renderPane(state, el, isActive) {
    if (!el) return;
    if (state.view === 'grid') this._renderGridInto(state, el, isActive);
    else this._renderListInto(state, el, isActive);
  },
  _renderListInto(state, el, isActive) {
    const cols = [
      { key: 'name',  label: 'Name' },
      { key: 'size',  label: 'Size' },
      { key: 'mtime', label: 'Modified' },
      { key: 'type',  label: 'Type' },
    ];
    let html = '<table class="fm-file-table"><thead><tr>';
    cols.forEach(c => {
      const arrow = state.sort.col === c.key ? (state.sort.dir === 'asc' ? ' \u25B2' : ' \u25BC') : '';
      html += '<th data-col="' + c.key + '">' + c.label + '<span class="sort-arrow">' + arrow + '</span></th>';
    });
    html += '</tr></thead><tbody>';
    state.items.forEach((item, idx) => {
      const sel = state.sel.has(item.name) ? ' selected' : '';
      html += '<tr class="fm-file-row' + sel + '" data-name="' + esc(item.name) + '" data-idx="' + idx + '" draggable="true">';
      html += '<td><div class="fm-name-cell"><span class="fm-icon">' + fileIcon(item) + '</span><span class="fm-fname">' + esc(item.name) + '</span></div></td>';
      html += '<td>' + (item.isDir ? '' : hsize(item.size || 0)) + '</td>';
      html += '<td>' + fmtDate(item.mtime) + '</td>';
      html += '<td>' + (item.isDir ? 'Folder' : (ext(item.name).toUpperCase() || 'File')) + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
    this._bindListInto(el, isActive);
  },
  _renderGridInto(state, el, isActive) {
    const sz = THUMB_SIZES[state.thumbIdx] || 72;
    const fit = state.thumbFit === 'contain' ? 'contain' : 'cover';
    const cellMin = Math.max(sz + 40, 80);
    let html = '<div class="fm-file-grid" style="grid-template-columns:repeat(auto-fill,minmax(' + cellMin + 'px,1fr))">';
    state.items.forEach((item, idx) => {
      const sel = state.sel.has(item.name) ? ' selected' : '';
      const path = joinPath(state.cwd, item.name);
      let thumb = '';
      if (!item.isDir && isImage(item.name)) {
        thumb = '<img src="' + esc(API.previewUrl(path)) + '" loading="lazy" style="object-fit:' + fit + '" onerror="this.parentNode.innerHTML=\'' + fileIcon(item) + '\'">';
      } else if (!item.isDir && isVideo(item.name)) {
        thumb = '<img src="' + esc(API.thumbUrl(path)) + '" loading="lazy" style="object-fit:' + fit + '" onerror="__fmVideoPoster(this,\'' + encodeURIComponent(path) + '\')">';
      } else if (!item.isDir && isAudio(item.name)) {
        thumb = '<img src="' + esc(API.thumbAudioUrl(path)) + '" loading="lazy" style="object-fit:' + fit + '" onerror="this.parentNode.innerHTML=\'' + fileIcon(item) + '\'">';
      } else {
        thumb = fileIcon(item);
      }
      const iconSz = Math.max(Math.round(sz * 0.55), 24);
      html += '<div class="fm-grid-item' + sel + '" data-name="' + esc(item.name) + '" data-idx="' + idx + '" draggable="true">';
      html += '<div class="fm-grid-thumb" style="width:' + sz + 'px;height:' + sz + 'px;font-size:' + iconSz + 'px">' + thumb + '</div>';
      html += '<div class="fm-grid-name">' + esc(item.name) + '</div>';
      html += '</div>';
    });
    html += '</div>';
    el.innerHTML = html;
    this._bindGridInto(el, isActive);
  },
  // Legacy aliases (kept so external callers still work if any exist).
  renderList() { this._renderListInto(S, this._activeEl(), true); },
  renderGrid() { this._renderGridInto(S, this._activeEl(), true); },
  _bindListInto(el, isActive) {
    // header sorting — only active pane responds (inactive is read-only view)
    el.querySelectorAll('.fm-file-table th').forEach(th => {
      th.onclick = () => {
        if (!isActive) { Pane.switch(el === $('#fm-content') ? 'A' : 'B'); return; }
        const col = th.dataset.col;
        if (S.sort.col === col) S.sort.dir = S.sort.dir === 'asc' ? 'desc' : 'asc';
        else { S.sort.col = col; S.sort.dir = 'asc'; }
        localStorage.setItem('fm_sort_col', S.sort.col);
        localStorage.setItem('fm_sort_dir', S.sort.dir);
        this.sort();
      };
    });
    el.querySelectorAll('.fm-file-row').forEach(row => {
      row.addEventListener('click', e => {
        if (!isActive) { Pane.switch(el === $('#fm-content') ? 'A' : 'B'); return; }
        Sel.click(row.dataset.name, +row.dataset.idx, e);
      });
      row.addEventListener('dblclick', () => { if (isActive) this._open(row.dataset.name); });
      row.addEventListener('contextmenu', e => { e.preventDefault(); if (!isActive) Pane.switch(el === $('#fm-content') ? 'A' : 'B'); Sel.ensureSelected(row.dataset.name); Ctx.show(e.clientX, e.clientY, { anchorEl: row }); });
      row.addEventListener('dragstart', e => { if (isActive) DnD.startMove(e, row.dataset.name); });
      if (S.items[+row.dataset.idx]?.isDir || (!isActive && (S.activePane === 'A' ? S.paneB : S.paneA).items[+row.dataset.idx]?.isDir)) {
        row.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; row.classList.add('drag-over'); });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', e => { e.preventDefault(); row.classList.remove('drag-over');
          const paneState = isActive ? S : (S.activePane === 'A' ? S.paneB : S.paneA);
          DnD.handleMoveDrop(joinPath(paneState.cwd, row.dataset.name));
        });
      }
    });
    // Empty-area click — clear selection on active pane; switch pane if click on inactive
    if (!el._fmEmptyBound) {
      el._fmEmptyBound = true;
      el.addEventListener('click', e => {
        const isEmpty = e.target === el || (e.target.closest('.fm-file-table') && !e.target.closest('.fm-file-row'));
        if (!isEmpty) return;
        if (!isActive) { Pane.switch(el === $('#fm-content') ? 'A' : 'B'); return; }
        S.sel.clear(); Sel.update();
      });
      el.addEventListener('contextmenu', e => {
        if (e.target.closest('.fm-file-row')) return;
        e.preventDefault();
        if (!isActive) Pane.switch(el === $('#fm-content') ? 'A' : 'B');
        // Do NOT clear the selection on right-click: an off-target right-click
        // (easy in grid-gap whitespace) was nuking multi-selects and degrading
        // the menu to New Folder/Upload only. Ctx.show resolves S.sel, so a
        // live selection gets the FULL menu (Delete (n), Zip (n), Cut/Copy);
        // a genuinely empty selection gets the folder menu. Left-click on
        // empty space still clears the selection, as before.
        Ctx.show(e.clientX, e.clientY);
      });
    }
  },
  _bindGridInto(el, isActive) {
    el.querySelectorAll('.fm-grid-item').forEach(item => {
      item.addEventListener('click', e => {
        if (!isActive) { Pane.switch(el === $('#fm-content') ? 'A' : 'B'); return; }
        Sel.click(item.dataset.name, +item.dataset.idx, e);
      });
      item.addEventListener('dblclick', () => { if (isActive) this._open(item.dataset.name); });
      item.addEventListener('contextmenu', e => { e.preventDefault(); if (!isActive) Pane.switch(el === $('#fm-content') ? 'A' : 'B'); Sel.ensureSelected(item.dataset.name); Ctx.show(e.clientX, e.clientY, { anchorEl: item }); });
      item.addEventListener('dragstart', e => { if (isActive) DnD.startMove(e, item.dataset.name); });
      if (S.items[+item.dataset.idx]?.isDir || (!isActive && (S.activePane === 'A' ? S.paneB : S.paneA).items[+item.dataset.idx]?.isDir)) {
        item.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; item.classList.add('drag-over'); });
        item.addEventListener('dragleave', () => item.classList.remove('drag-over'));
        item.addEventListener('drop', e => { e.preventDefault(); item.classList.remove('drag-over');
          const paneState = isActive ? S : (S.activePane === 'A' ? S.paneB : S.paneA);
          DnD.handleMoveDrop(joinPath(paneState.cwd, item.dataset.name));
        });
      }
    });
    if (!el._fmEmptyBound) {
      el._fmEmptyBound = true;
      el.addEventListener('click', e => {
        const isEmpty = e.target === el || (e.target.closest('.fm-file-grid') && !e.target.closest('.fm-grid-item'));
        if (!isEmpty) return;
        if (!isActive) { Pane.switch(el === $('#fm-content') ? 'A' : 'B'); return; }
        S.sel.clear(); Sel.update();
      });
      el.addEventListener('contextmenu', e => {
        if (e.target.closest('.fm-grid-item')) return;
        e.preventDefault();
        if (!isActive) Pane.switch(el === $('#fm-content') ? 'A' : 'B');
        // Do NOT clear the selection on right-click: an off-target right-click
        // (easy in grid-gap whitespace) was nuking multi-selects and degrading
        // the menu to New Folder/Upload only. Ctx.show resolves S.sel, so a
        // live selection gets the FULL menu (Delete (n), Zip (n), Cut/Copy);
        // a genuinely empty selection gets the folder menu. Left-click on
        // empty space still clears the selection, as before.
        Ctx.show(e.clientX, e.clientY);
      });
    }
  },
  // Legacy aliases
  _bindList() { this._bindListInto(this._activeEl(), true); },
  _bindGrid() { this._bindGridInto(this._activeEl(), true); },
  _open(name) {
    const item = S.items.find(i => i.name === name);
    if (!item) return;
    if (item.isDir) { Nav.go(joinPath(S.cwd, name)); return; }
    const path = joinPath(S.cwd, name);
    if (isImage(name) || isVideo(name) || /\.pdf$/i.test(name)) {
      Modal.preview(path, name);
    } else if (isText(name)) {
      Ops.editFile(path);
    } else {
      window.open(API.downloadUrl(path));
    }
  },
  updateStatus() {
    const dirs = S.items.filter(i => i.isDir).length;
    const files = S.items.length - dirs;
    $('#fm-status-items').textContent = dirs + ' folders, ' + files + ' files';
    $('#fm-status-sel').textContent = S.sel.size ? S.sel.size + ' selected' : '';
  }
};

/* ===== SELECTION ===== */
const Sel = {
  click(name, idx, e) {
    if (e.ctrlKey || e.metaKey) {
      if (S.sel.has(name)) S.sel.delete(name); else S.sel.add(name);
    } else if (e.shiftKey && S.lastClickIdx >= 0) {
      const lo = Math.min(S.lastClickIdx, idx), hi = Math.max(S.lastClickIdx, idx);
      for (let i = lo; i <= hi; i++) S.sel.add(S.items[i].name);
    } else {
      S.sel.clear();
      S.sel.add(name);
    }
    S.lastClickIdx = idx;
    this.update();
  },
  ensureSelected(name) {
    if (!S.sel.has(name)) { S.sel.clear(); S.sel.add(name); this.update(); }
  },
  selectAll() {
    S.items.forEach(i => S.sel.add(i.name));
    this.update();
  },
  update() {
    // update DOM
    $$('.fm-file-row, .fm-grid-item').forEach(el => {
      el.classList.toggle('selected', S.sel.has(el.dataset.name));
    });
    Files.updateStatus();
    Toolbar.update();
  }
};

/* ===== TOOLBAR ===== */
const Toolbar = {
  update() {
    const n = S.sel.size;
    const hasClip = !!S.clipboard;
    const selItems = [...S.sel].map(name => S.items.find(i => i.name === name)).filter(Boolean);
    const singleFile = n === 1 && selItems[0] && !selItems[0].isDir;
    const singleZip = singleFile && isZip(selItems[0].name);

    $('#tb-back').disabled = S.histIdx <= 0;
    $('#tb-fwd').disabled = S.histIdx >= S.history.length - 1;
    $('#tb-up').disabled = !S.cwd;
    $('#tb-cut').disabled = !n;
    $('#tb-copy').disabled = !n;
    $('#tb-paste').disabled = !hasClip;
    $('#tb-rename').disabled = n !== 1;
    $('#tb-delete').disabled = !n;
    $('#tb-download').disabled = !singleFile;
    $('#tb-zip').disabled = !n;
    $('#tb-unzip').disabled = !singleZip;

    // view toggle
    $('#tb-list').classList.toggle('active', S.view === 'list');
    $('#tb-grid').classList.toggle('active', S.view === 'grid');

    // grid controls — show/hide based on view mode
    const isGrid = S.view === 'grid';
    $('#tb-zoom-out').style.display = isGrid ? '' : 'none';
    $('#tb-zoom-in').style.display  = isGrid ? '' : 'none';
    $('#tb-fit').style.display      = isGrid ? '' : 'none';
    if (isGrid) {
      $('#tb-zoom-out').disabled = S.thumbIdx <= 0;
      $('#tb-zoom-in').disabled  = S.thumbIdx >= THUMB_SIZES.length - 1;
      $('#tb-fit').textContent   = S.thumbFit === 'cover' ? '\u25A3' : '\u25A1';
      $('#tb-fit').title         = S.thumbFit === 'cover' ? 'Natural Dimensions' : 'Square Crop';
    }

    // Clipboard pill — visible whenever something's cut/copied. Tone shifts
    // blue for cut, amber for copy so the mode is obvious at a glance.
    const pill = $('#fm-clip-pill');
    if (pill) {
      if (hasClip) {
        const cb = S.clipboard;
        const count = cb.names.length;
        const verb = cb.action === 'cut' ? 'cut' : 'copied';
        pill.style.display = 'inline-flex';
        pill.classList.toggle('cut-mode', cb.action === 'cut');
        pill.innerHTML = '<span>' + (cb.action === 'cut' ? '\u2702' : '\u2398') + '</span>'
                       + '<span>' + count + ' item' + (count > 1 ? 's' : '') + ' ' + verb
                       + ' \u00B7 <strong>Ctrl+V</strong> to paste</span>'
                       + '<span class="fm-clip-x">\u00D7</span>';
      } else {
        pill.style.display = 'none';
      }
    }
  }
};

/* ===== OPERATIONS ===== */
const Ops = {
  mkdir() {
    Modal.prompt('New Folder', 'Folder name:', '', async name => {
      if (!name) return;
      const r = await API.mkdir(S.cwd, name);
      if (r.ok) { Toast.show('Folder created', 'success'); Nav.go(S.cwd); Tree.load(S.cwd); }
      else Toast.show(r.error || 'Failed', 'error');
    });
  },
  rename() {
    if (S.sel.size !== 1) return;
    const oldName = [...S.sel][0];
    const item = S.items.find(i => i.name === oldName);
    Modal.prompt('Rename', 'New name:', oldName, async newName => {
      if (!newName || newName === oldName) return;
      const r = await API.rename(joinPath(S.cwd, oldName), newName);
      if (r.ok) { Toast.show('Renamed', 'success'); Nav.go(S.cwd); if (item?.isDir) Tree.load(S.cwd); }
      else Toast.show(r.error || 'Rename failed', 'error');
    });
  },
  async del() {
    if (!S.sel.size) return;
    const names = [...S.sel];
    // Verbose confirm — name exactly what gets deleted, never a bare count.
    const listed = names.slice(0, 25).map(n => '<li>' + esc(n) + '</li>').join('')
      + (names.length > 25 ? '<li>…and ' + (names.length - 25) + ' more</li>' : '');
    const body = names.length === 1
      ? '<p>Delete “<strong>' + esc(names[0]) + '</strong>”?</p>'
      : '<p>Delete these <strong>' + names.length + '</strong> items?</p><ul class="fm-del-list">' + listed + '</ul>';
    Modal.confirmHtml('Confirm Delete', body, async () => {
      Progress.show('Deleting ' + names.length + ' item' + (names.length > 1 ? 's' : '') + '\u2026');
      let ok = 0, fail = 0;
      for (const name of names) {
        Progress.show('Deleting ' + (ok + fail + 1) + ' / ' + names.length + '\u2026');
        const r = await API.del(joinPath(S.cwd, name));
        if (r.ok) ok++; else fail++;
      }
      Progress.hide();
      Toast.show('Deleted ' + ok + (fail ? ', ' + fail + ' failed' : ''), ok && !fail ? 'success' : 'warn');
      Nav.go(S.cwd);
      Tree.load(S.cwd);
    });
  },
  cut()  { S.clipboard = { action: 'cut',  cwd: S.cwd, names: [...S.sel] }; Toast.show('Cut ' + S.sel.size + ' item(s)', 'info'); Toolbar.update(); },
  copy() { S.clipboard = { action: 'copy', cwd: S.cwd, names: [...S.sel] }; Toast.show('Copied ' + S.sel.size + ' item(s)', 'info'); Toolbar.update(); },
  async paste() { return this.pasteInto(S.cwd); },
  async pasteInto(destPath) {
    if (!S.clipboard) return;
    const { action, cwd, names } = S.clipboard;
    Progress.show((action === 'cut' ? 'Moving ' : 'Copying ') + names.length + ' item' + (names.length > 1 ? 's' : '') + '\u2026');
    let ok = 0, fail = 0;
    for (const name of names) {
      Progress.show((action === 'cut' ? 'Moving ' : 'Copying ') + (ok + fail + 1) + ' / ' + names.length + '\u2026');
      const src = joinPath(cwd, name);
      const r = action === 'cut' ? await API.move(src, destPath) : await API.copy(src, destPath);
      if (r.ok) ok++; else fail++;
    }
    Progress.hide();
    if (action === 'cut') S.clipboard = null;
    Toast.show((action === 'cut' ? 'Moved ' : 'Copied ') + ok + (fail ? ', ' + fail + ' failed' : ''), ok && !fail ? 'success' : 'warn');
    // Refresh — navigate to dest if we're not already there, plus refresh tree for both source + dest
    if (destPath === S.cwd) Nav.go(S.cwd); else { Tree.load(destPath); Nav.go(S.cwd); }
    Tree.load(S.cwd);
    if (action === 'cut') Tree.load(cwd);
    Toolbar.update();
  },
  download() {
    if (S.sel.size !== 1) return;
    const name = [...S.sel][0];
    window.open(API.downloadUrl(joinPath(S.cwd, name)));
  },
  upload() {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.multiple = true;
    inp.onchange = () => { if (inp.files.length) this._doUpload(inp.files); };
    inp.click();
  },
  async _doUpload(files) {
    // Shared per-file progress meter (LumUpload): one XHR per file = true
    // per-file progress, rendered as a momentary anchored panel at the toolbar
    // Upload control. Falls back to the legacy single-request bar if the shared
    // component isn't loaded.
    const LU = (window.LumUpload && window.LumUpload.__real) ? window.LumUpload : null;
    if (LU && LU.uploadEach) {
      LU.uploadEach({
        url: API_URL, files: files, field: 'files[]',
        data: { action: 'upload', path: S.cwd },
        anchor: '.fm-address-bar',   // full-width strip above the listing (not the flex toolbar)
        okWhen: res => !res || res.ok !== false,    // FileManager returns {ok:bool}
        onAllDone: results => {
          const ok = results.filter(r => r.ok).length, bad = results.length - ok;
          if (bad) Toast.show(bad + ' upload(s) failed' + (ok ? ', ' + ok + ' ok' : ''), 'error');
          else Toast.show('Uploaded ' + ok + ' file(s)', 'success');
          Nav.go(S.cwd);
        }
      });
      return;
    }

    // Legacy fallback (no progress component present).
    const bar = document.createElement('div');
    bar.className = 'fm-upload-bar';
    bar.innerHTML = '<div class="fm-upload-bar-fill" id="fm-upload-fill"></div><span class="fm-upload-bar-text" id="fm-upload-text">Uploading... 0%</span>';
    $('#fm-content').appendChild(bar);
    try {
      const r = await API.upload(S.cwd, files, pct => {
        const fill = $('#fm-upload-fill');
        const txt = $('#fm-upload-text');
        const p = (pct * 100).toFixed(0);
        if (fill) fill.style.width = p + '%';
        if (txt) txt.textContent = 'Uploading... ' + p + '%';
      });
      if (r.ok) Toast.show('Uploaded ' + (r.saved?.length || 0) + ' file(s)', 'success');
      else Toast.show(r.error || 'Upload failed', 'error');
    } catch(e) { Toast.show('Upload error', 'error'); }
    bar.remove();
    Nav.go(S.cwd);
  },
  async zip() {
    if (!S.sel.size) return;
    const names = [...S.sel];
    Modal.prompt('Zip Archive', 'Archive name:', 'archive', async zipName => {
      if (!zipName) return;
      Progress.show('Zipping ' + names.length + ' item' + (names.length > 1 ? 's' : '') + '\u2026');
      const items = names.map(n => joinPath(S.cwd, n));
      const r = await API.zip(S.cwd, items, zipName);
      Progress.hide();
      if (r.ok) Toast.show('Created ' + (r.zip || zipName + '.zip'), 'success');
      else Toast.show(r.error || 'Zip failed', 'error');
      Nav.go(S.cwd);
    });
  },
  async unzip() {
    if (S.sel.size !== 1) return;
    const name = [...S.sel][0];
    Progress.show('Extracting ' + name + '\u2026');
    const r = await API.unzip(joinPath(S.cwd, name), S.cwd);
    Progress.hide();
    if (r.ok) Toast.show('Extracted', 'success');
    else Toast.show(r.error || 'Unzip failed', 'error');
    Nav.go(S.cwd);
    Tree.load(S.cwd);
  },
  async editFile(path) {
    const r = await API.read(path);
    if (!r.ok) { Toast.show(r.error || 'Cannot open file', 'error'); return; }
    Modal.editor(path, r.content);
  },
  async writeFile(path, content) {
    const status = $('#fm-editor-status');
    if (status) status.textContent = 'Saving...';
    const r = await API.write(path, content);
    if (r.ok) {
      Toast.show('Saved: ' + path.split('/').pop(), 'success');
      if (status) status.textContent = 'Saved';
      const ta = $('#fm-editor-ta');
      if (ta) ta.dataset.dirty = '0';
    } else {
      Toast.show(r.error || 'Save failed', 'error');
      if (status) status.textContent = 'Save failed!';
    }
  },
  backup() {
    Modal.show('Backup', '<p>Create a backup archive:</p>' +
      '<div style="display:flex;gap:8px;margin-top:12px">' +
        '<button class="fm-btn" onclick="this.disabled=true;this.textContent=\'Working...\';window._fmBackup(\'data\')">Data</button>' +
        '<button class="fm-btn" onclick="this.disabled=true;this.textContent=\'Working...\';window._fmBackup(\'media\')">Media</button>' +
        '<button class="fm-btn" onclick="this.disabled=true;this.textContent=\'Working...\';window._fmBackup(\'app\')">App</button>' +
      '</div>',
    [{ label: 'Close' }]);
    window._fmBackup = async kind => {
      const r = await API.backup(kind);
      if (r.ok) Toast.show('Backup created: ' + (r.archive || kind), 'success');
      else Toast.show(r.error || 'Backup failed', 'error');
      Modal.hide();
    };
  },
  async chownFix() {
    Modal.confirm('Fix file ownership (chown www-data)?', async () => {
      Toast.show('Fixing ownership...', 'info', 5000);
      const r = await API.chownFix();
      if (r.ok) Toast.show('Ownership fixed. Changed: ' + (r.changed || 0), 'success');
      else Toast.show(r.error || 'Failed', 'error');
    });
  },
  async loadDiskUsage() {
    try {
      const r = await API.diskUsage('app');
      if (r.ok) $('#fm-status-disk').textContent = 'Disk: ' + hsize(r.bytes);
    } catch(e) {}
  }
};

/* ===== CONTEXT MENU ===== */
const Ctx = {
  _anchor(el, menuEl) {
    // Position at the element's left edge, vertically centered
    const r = el.getBoundingClientRect();
    const mW = menuEl.offsetWidth;
    const mH = menuEl.offsetHeight;
    let x = r.left;
    let y = r.top + r.height / 2;
    // Keep on screen
    if (x + mW > window.innerWidth - 4) x = Math.max(4, window.innerWidth - mW - 4);
    if (y + mH > window.innerHeight - 4) y = Math.max(4, window.innerHeight - mH - 4);
    menuEl.style.left = x + 'px';
    menuEl.style.top = y + 'px';
  },
  /**
   * Unified context menu — handles every right-click in the file manager.
   *   show(x, y, { anchorEl, treeTarget })
   *     anchorEl    — optional DOM node to anchor the menu against
   *     treeTarget  — { name, path } when the right-click came from the
   *                   folder tree (operations target the specific folder
   *                   regardless of current cwd or selection)
   *
   *   If no treeTarget is given, the menu operates on S.sel in S.cwd.
   *   The always-available bottom section (New Folder, Upload, Paste Here)
   *   appears in every context so users never hit a "tiny" menu on empty
   *   space or in the tree — everything is reachable from one right-click.
   */
  show(x, y, opts) {
    opts = opts || {};
    const anchorEl = opts.anchorEl || null;
    const treeTarget = opts.treeTarget || null;

    // Resolve the operating context. Tree targets override the selection.
    let selItems, selCwd, selName, n;
    if (treeTarget) {
      selItems = [{ name: treeTarget.name, isDir: true }];
      selCwd   = treeTarget.path.includes('/') ? treeTarget.path.substring(0, treeTarget.path.lastIndexOf('/')) : '';
      selName  = treeTarget.name;
      n        = 1;
    } else {
      selItems = [...S.sel].map(name => S.items.find(i => i.name === name)).filter(Boolean);
      selCwd   = S.cwd;
      n        = selItems.length;
      selName  = n === 1 ? selItems[0].name : null;
    }
    const singleFile  = n === 1 && selItems[0] && !selItems[0].isDir;
    const singleDir   = n === 1 && selItems[0] && selItems[0].isDir;
    const singleZip   = singleFile && isZip(selItems[0].name);
    const singleText  = singleFile && isText(selItems[0].name);
    const singleImage = singleFile && isImage(selItems[0].name);
    const singleVideo = singleFile && isVideo(selItems[0].name);
    const targetFolderPath = treeTarget ? treeTarget.path : (singleDir ? joinPath(selCwd, selName) : selCwd);

    const items = [];

    /* --- Open / View / Edit --- */
    if (singleDir) items.push({ label: 'Open Folder', action: () => Nav.go(targetFolderPath) });
    if (singleDir) items.push({ label: 'Download Folder', action: async () => {
      Toast.show('Zipping ' + selName + '...', 'info');
      const r = await API.zip(selCwd, [selName], selName);
      if (r.ok && r.zip_path) {
        const dlUrl = API.previewUrl(r.zip_path);
        const a = document.createElement('a'); a.href = dlUrl; a.download = selName + '.zip'; a.click();
        Toast.show('Download started', 'success');
      } else { Toast.show(r.error || 'Zip failed', 'error'); }
    }});
    if (singleImage) items.push({ label: 'View Image', action: () => Modal.preview(joinPath(selCwd, selName), selName) });
    if (singleVideo) items.push({ label: 'View Video', action: () => Modal.preview(joinPath(selCwd, selName), selName) });
    if (singleFile && isAudio(selName)) items.push({ label: '\u25B6 Play Audio', action: () => Modal.playAudio(joinPath(selCwd, selName), selName) });
    if (singleText)  items.push({ label: 'Edit', action: () => Ops.editFile(joinPath(selCwd, selName)), key: 'Enter' });
    if (singleFile)  items.push({ label: 'Download', action: () => Ops.download() });

    /* --- Cut / Copy / Paste (include Paste Into for tree folders) --- */
    if (n) items.push({ sep: true });
    if (n) items.push({ label: 'Cut',  action: () => {
      if (treeTarget) { S.clipboard = { action: 'cut',  cwd: selCwd, names: [selName] }; Toast.show('Cut: ' + selName, 'info'); Toolbar.update(); }
      else Ops.cut();
    }, key: 'Ctrl+X' });
    if (n) items.push({ label: 'Copy', action: () => {
      if (treeTarget) { S.clipboard = { action: 'copy', cwd: selCwd, names: [selName] }; Toast.show('Copied: ' + selName, 'info'); Toolbar.update(); }
      else Ops.copy();
    }, key: 'Ctrl+C' });
    if (treeTarget && S.clipboard) items.push({ label: 'Paste Into', action: () => Ops.pasteInto(targetFolderPath) });

    /* --- Rename / Delete --- */
    if (n) items.push({ sep: true });
    if (n === 1) items.push({ label: 'Rename', action: () => {
      if (treeTarget) {
        Modal.prompt('Rename Folder', 'New name:', selName, async newName => {
          if (!newName || newName === selName) return;
          const r = await API.rename(treeTarget.path, newName);
          if (r.ok) { Toast.show('Renamed', 'success'); Tree.load(selCwd); Nav.go(S.cwd); }
          else Toast.show(r.error || 'Rename failed', 'error');
        });
      } else Ops.rename();
    }, key: 'F2' });
    if (n) items.push({ label: 'Delete ' + (n > 1 ? '(' + n + ' items)' : ''), action: () => {
      if (treeTarget) {
        Modal.confirm('Delete folder "' + selName + '" and all contents?', async () => {
          const r = await API.del(treeTarget.path);
          if (r.ok) { Toast.show('Deleted: ' + selName, 'success'); Tree.load(selCwd); if (S.cwd.startsWith(treeTarget.path)) Nav.go(selCwd); else Nav.go(S.cwd); }
          else Toast.show(r.error || 'Delete failed', 'error');
        });
      } else Ops.del();
    }, key: 'Del' });

    /* --- Zip / Unzip (selection-only — skipped for tree) --- */
    if (n && !treeTarget) items.push({ sep: true });
    if (n && !treeTarget) items.push({ label: 'Zip ' + (n > 1 ? '(' + n + ' items)' : ''), action: () => Ops.zip() });
    if (singleZip && !treeTarget) items.push({ label: 'Unzip Here', action: () => Ops.unzip() });

    /* --- Always available (identical across every right-click context) --- */
    items.push({ sep: true });
    items.push({ label: treeTarget ? 'New Subfolder' : 'New Folder', action: () => {
      if (treeTarget) {
        Modal.prompt('New Folder', 'Folder name:', '', async name => {
          if (!name) return;
          const r = await API.mkdir(targetFolderPath, name);
          if (r.ok) { Toast.show('Created', 'success'); Tree.load(targetFolderPath); }
          else Toast.show(r.error || 'Failed', 'error');
        });
      } else Ops.mkdir();
    }});
    items.push({ label: 'Upload Files', action: () => Ops.upload() });
    if (S.clipboard && !treeTarget) items.push({ label: 'Paste Here', action: () => Ops.paste(), key: 'Ctrl+V' });

    const el = $('#fm-ctx');
    el.innerHTML = items.map(i => {
      if (i.sep) return '<div class="fm-ctx-sep"></div>';
      return '<div class="fm-ctx-item">' + esc(i.label) + (i.key ? '<span class="fm-ctx-key">' + i.key + '</span>' : '') + '</div>';
    }).join('');
    el.style.display = 'block';
    if (anchorEl) {
      this._anchor(anchorEl, el);
    } else {
      const rect = el.getBoundingClientRect();
      const maxX = window.innerWidth - rect.width - 4, maxY = window.innerHeight - rect.height - 4;
      el.style.left = Math.max(0, Math.min(x, maxX)) + 'px';
      el.style.top  = Math.max(0, Math.min(y, maxY)) + 'px';
    }
    const actionItems = items.filter(i => !i.sep);
    el.querySelectorAll('.fm-ctx-item').forEach((row, idx) => {
      row.onclick = () => { Ctx.hide(); actionItems[idx].action(); };
    });
  },
  hide() { $('#fm-ctx').style.display = 'none'; }
};

/* ===== KEYBOARD HELP MODAL ===== */
const Help = {
  _shortcuts: {
    'Navigation': [
      ['Arrow keys',          'Move selection'],
      ['Home / End',          'First / last item'],
      ['Page Up / Down',      'Jump by viewport'],
      ['Shift + Arrow',       'Extend selection'],
      ['Enter',               'Open / edit'],
      ['Backspace',           'Up to parent'],
    ],
    'Selection': [
      ['Click',               'Select one'],
      ['Ctrl / Cmd + Click',  'Toggle additive'],
      ['Shift + Click',       'Range select'],
      ['Ctrl / Cmd + A',      'Select all'],
      ['Esc',                 'Clear selection'],
    ],
    'Clipboard': [
      ['Ctrl / Cmd + X',      'Cut'],
      ['Ctrl / Cmd + C',      'Copy'],
      ['Ctrl / Cmd + V',      'Paste here'],
    ],
    'File ops': [
      ['F2',                  'Rename'],
      ['Delete',              'Delete selected'],
      ['Right-click',         'Context menu'],
      ['?',                   'This help'],
    ],
  },
  show() {
    const renderKeys = (keys) => keys.split(/\s+\+\s+/).map(part =>
      part.split(/\s*\/\s*/).map(k => '<kbd>' + esc(k) + '</kbd>').join(' / ')
    ).join(' + ');
    const body = Object.entries(this._shortcuts).map(([group, rows]) =>
      '<div class="fm-help-col"><h4>' + esc(group) + '</h4>' +
      rows.map(([keys, desc]) =>
        '<div class="fm-help-row"><span>' + esc(desc) + '</span>' +
        '<span class="fm-help-keys">' + renderKeys(keys) + '</span></div>'
      ).join('') +
      '</div>'
    ).join('');
    Modal.show('Keyboard Shortcuts', '<div class="fm-help-modal">' + body + '</div>', [
      { label: 'Close', cls: 'fm-btn-primary' }
    ]);
  },
  hide() { Modal.hide(); }
};

/* ===== PREFERENCES ===== */
const Prefs = {
  THEMES: [
    { key: 'dark',  label: 'Luminal Native',  preview: 'preview-dark',  desc: 'Dark glass — default' },
    { key: 'light', label: 'Luminal Light',   preview: 'preview-light', desc: 'Light glass' },
    { key: 'win95', label: 'Windows 95',      preview: 'preview-win95', desc: 'Chiseled bevels, grey, navy titlebars' },
    { key: 'wfw',   label: 'Windows For Workgroups 3.11', preview: 'preview-wfw', desc: 'Program Manager vibes' },
  ],
  FONTS: [
    { key: 'site',    label: 'Site Body',    desc: 'Inherit from the site' },
    { key: 'proport', label: 'Proportional', desc: 'System UI sans' },
    { key: 'fixed',   label: 'Fixed',        desc: 'Monospace' },
  ],
  EDITOR_MODES: [
    { key: 'dark',  label: 'White on Black', desc: 'Light text on dark — default' },
    { key: 'light', label: 'Black on White', desc: 'Classic document look' },
  ],
  load() {
    S.theme      = localStorage.getItem('fm_theme')       || 'dark';
    S.fontPref   = localStorage.getItem('fm_font_pref')   || 'site';
    S.editorMode = localStorage.getItem('fm_editor_mode') || 'dark';
    this.apply();
  },
  apply() {
    document.documentElement.setAttribute('data-theme', S.theme);
    const app = $('#fm-app'); if (app) {
      app.setAttribute('data-fm-font-pref', S.fontPref);
      app.setAttribute('data-fm-editor-mode', S.editorMode);
    }
  },
  setTheme(key) {
    S.theme = key; localStorage.setItem('fm_theme', key); this.apply();
    if ($('#fm-modal').style.display === 'flex') this.show();
  },
  setFont(key) {
    S.fontPref = key; localStorage.setItem('fm_font_pref', key); this.apply();
    if ($('#fm-modal').style.display === 'flex') this.show();
  },
  setEditorMode(key) {
    S.editorMode = key; localStorage.setItem('fm_editor_mode', key); this.apply();
    if ($('#fm-modal').style.display === 'flex') this.show();
  },
  show() {
    const themeCards = this.THEMES.map(t =>
      '<div class="fm-prefs-card ' + (S.theme === t.key ? 'active' : '') + '" data-fm-theme="' + t.key + '">' +
        '<div class="fm-prefs-preview ' + t.preview + '"></div>' +
        '<div>' + esc(t.label) + '</div>' +
        '<small style="opacity:.7;font-weight:400">' + esc(t.desc) + '</small>' +
      '</div>'
    ).join('');
    const fontCards = this.FONTS.map(f =>
      '<div class="fm-prefs-card ' + (S.fontPref === f.key ? 'active' : '') + '" data-fm-font="' + f.key + '">' +
        '<div>' + esc(f.label) + '</div>' +
        '<small style="opacity:.7;font-weight:400">' + esc(f.desc) + '</small>' +
      '</div>'
    ).join('');
    const editorCards = this.EDITOR_MODES.map(e =>
      '<div class="fm-prefs-card ' + (S.editorMode === e.key ? 'active' : '') + '" data-fm-editor="' + e.key + '">' +
        '<div class="fm-prefs-preview" style="background:' + (e.key === 'light' ? '#fff' : '#000') + ';border:1px solid #888;color:' + (e.key === 'light' ? '#000' : '#fff') + ';font-family:monospace;font-size:9px;display:flex;align-items:center;justify-content:center">Aa</div>' +
        '<div>' + esc(e.label) + '</div>' +
        '<small style="opacity:.7;font-weight:400">' + esc(e.desc) + '</small>' +
      '</div>'
    ).join('');
    Modal.show('File Manager Preferences',
      '<div class="fm-prefs">' +
        '<div class="fm-prefs-section"><h4>Theme</h4><div class="fm-prefs-grid" style="grid-template-columns:repeat(2,1fr)">' + themeCards + '</div></div>' +
        '<div class="fm-prefs-section"><h4>Font</h4><div class="fm-prefs-grid">' + fontCards + '</div></div>' +
        '<div class="fm-prefs-section"><h4>Editor contrast</h4><div class="fm-prefs-grid" style="grid-template-columns:repeat(2,1fr)">' + editorCards + '</div></div>' +
      '</div>',
      [ { label: 'Close', cls: 'fm-btn-primary' } ]
    );
    // Delegate click handling for the just-rendered cards
    const body = $('#fm-modal-body');
    body.querySelectorAll('[data-fm-theme]').forEach(el => {
      el.onclick = () => Prefs.setTheme(el.getAttribute('data-fm-theme'));
    });
    body.querySelectorAll('[data-fm-font]').forEach(el => {
      el.onclick = () => Prefs.setFont(el.getAttribute('data-fm-font'));
    });
    body.querySelectorAll('[data-fm-editor]').forEach(el => {
      el.onclick = () => Prefs.setEditorMode(el.getAttribute('data-fm-editor'));
    });
  }
};

/* ===== DUAL-PANE =====
 * Two independent listings side-by-side. Each pane has its own cwd,
 * selection, view mode, sort, and history. Shared: tree, clipboard,
 * theme. Toolbar + keyboard drive the ACTIVE pane; right-clicking or
 * interacting with the inactive pane switches focus to it first.
 * Drag between panes = move (Ctrl+drag = copy).
 */
const Pane = {
  toggle() {
    S.dualMode = !S.dualMode;
    localStorage.setItem('fm_dual_mode', S.dualMode ? '1' : '0');
    if (S.dualMode && !S.paneB) {
      S.paneB = makePane();
      S.paneB.cwd = S.paneA.cwd; // start B in the same dir as A
    }
    this.updateLayout();
    // Load content for the (possibly-new) other pane
    if (S.dualMode) {
      const other = S.activePane === 'A' ? S.paneB : S.paneA;
      this._loadInto(other);
    }
    Files.render();
  },
  switch(target) {
    if (!S.dualMode) return;
    if (target === S.activePane) return;
    S.activePane = target;
    // Address bar + page state follow the active pane
    $('#fm-address').value = S.cwd || '/';
    Tree.highlight(S.cwd);
    this.updateLayout();
    Files.render();
    Toolbar.update();
  },
  /** Load listing for a non-active pane (doesn't clobber live S). */
  async _loadInto(pane) {
    try {
      const r = await API.list(pane.cwd);
      if (r.ok) { pane.items = r.items || []; this._sortPane(pane); Files.render(); }
    } catch(e) {}
  },
  _sortPane(pane) {
    const {col, dir} = pane.sort;
    const mult = dir === 'asc' ? 1 : -1;
    pane.items.sort((a, b) => {
      if (a.isDir && !b.isDir) return -1;
      if (!a.isDir && b.isDir) return 1;
      let va, vb;
      switch(col) {
        case 'name':  va = a.name.toLowerCase(); vb = b.name.toLowerCase(); return va < vb ? -1*mult : va > vb ? mult : 0;
        case 'size':  return (a.size - b.size) * mult;
        case 'mtime': return (a.mtime - b.mtime) * mult;
        case 'type':  va = ext(a.name); vb = ext(b.name); return va < vb ? -1*mult : va > vb ? mult : 0;
        default: return 0;
      }
    });
  },
  /** Refresh the inactive pane — call after operations that may have
   *  changed its directory (e.g. paste across panes, delete). */
  refreshInactive() {
    if (!S.dualMode) return;
    const other = S.activePane === 'A' ? S.paneB : S.paneA;
    if (other) this._loadInto(other);
  },
  /** Toggle classes + display for dual-mode layout and active indicator. */
  updateLayout() {
    const main = $('.fm-main');
    const a    = $('#fm-content');
    const b    = $('#fm-content-b');
    const bHandle = $('#fm-pane-resize');
    const bPane = $('#fm-pane-b-wrap');
    if (main) main.classList.toggle('fm-dual', S.dualMode);
    if (bPane) bPane.style.display = S.dualMode ? 'flex' : 'none';
    if (bHandle) bHandle.style.display = S.dualMode ? '' : 'none';
    if (a) a.classList.toggle('fm-pane-active', S.activePane === 'A');
    if (b) b.classList.toggle('fm-pane-active', S.activePane === 'B');
    // Toolbar toggle state
    const tog = $('#tb-dual');
    if (tog) tog.classList.toggle('active', S.dualMode);
  }
};

/* ===== RUBBER-BAND DRAG-SELECT =====
 *   Click-and-drag over empty space in either content pane to draw a
 *   selection box. Items whose bounding rect intersects the box become
 *   selected; Shift adds to existing selection, Ctrl toggles.
 */
const RubberBand = {
  _box: null, _startX: 0, _startY: 0, _active: false, _additive: false, _toggle: false, _pane: null,
  attach(el) {
    if (el._rbBound) return;
    el._rbBound = true;
    el.addEventListener('mousedown', e => {
      // Left button, on empty area only (not on rows/items)
      if (e.button !== 0) return;
      if (e.target.closest('.fm-file-row, .fm-grid-item, th, .sort-arrow')) return;
      const isActive = el.classList.contains('fm-pane-active');
      if (!isActive) return; // only drag-select in the active pane
      e.preventDefault();
      this._start(e, el);
    });
  },
  _start(e, el) {
    this._active = true;
    this._pane = el;
    this._additive = e.shiftKey;
    this._toggle = e.ctrlKey || e.metaKey;
    const rect = el.getBoundingClientRect();
    this._startX = e.clientX - rect.left + el.scrollLeft;
    this._startY = e.clientY - rect.top + el.scrollTop;
    this._snapshot = this._additive || this._toggle ? new Set(S.sel) : new Set();
    if (!this._additive && !this._toggle) { S.sel.clear(); Sel.update(); }

    const box = document.createElement('div');
    box.className = 'fm-rubber-band';
    el.appendChild(box);
    this._box = box;
    this._positionBox(e);

    const onMove = ev => this._onMove(ev);
    const onUp   = () => { this._end(); document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp); };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  },
  _positionBox(e) {
    const el = this._pane;
    const rect = el.getBoundingClientRect();
    const curX = e.clientX - rect.left + el.scrollLeft;
    const curY = e.clientY - rect.top + el.scrollTop;
    const x = Math.min(this._startX, curX), y = Math.min(this._startY, curY);
    const w = Math.abs(curX - this._startX), h = Math.abs(curY - this._startY);
    Object.assign(this._box.style, { left: x+'px', top: y+'px', width: w+'px', height: h+'px' });
    return { x, y, w, h, rect };
  },
  _onMove(e) {
    if (!this._active) return;
    const { rect } = this._positionBox(e);
    // Compute hit-testing in viewport coordinates (simpler)
    const boxRect = this._box.getBoundingClientRect();
    const items = this._pane.querySelectorAll('.fm-file-row, .fm-grid-item');
    S.sel.clear();
    this._snapshot.forEach(n => S.sel.add(n));
    items.forEach(node => {
      const r = node.getBoundingClientRect();
      const intersects = !(r.right < boxRect.left || r.left > boxRect.right || r.bottom < boxRect.top || r.top > boxRect.bottom);
      const name = node.dataset.name;
      if (intersects) {
        if (this._toggle) { if (this._snapshot.has(name)) S.sel.delete(name); else S.sel.add(name); }
        else S.sel.add(name);
      }
    });
    Sel.update();
  },
  _end() {
    this._active = false;
    if (this._box && this._box.parentNode) this._box.parentNode.removeChild(this._box);
    this._box = null;
    this._pane = null;
  }
};

/* ===== PROGRESS OVERLAY =====
 * Lightweight overlay shown during bulk ops (zip, unzip, bulk delete).
 * Not real progress since server ops are single-shot — just a "working"
 * indicator so users don't wonder if the click registered.
 */
const Progress = {
  _el: null,
  show(label) {
    if (!this._el) {
      this._el = document.createElement('div');
      this._el.className = 'fm-progress-overlay';
      this._el.innerHTML = '<div class="fm-progress-card"><div class="fm-progress-spinner"></div><div class="fm-progress-label"></div></div>';
      document.body.appendChild(this._el);
    }
    this._el.querySelector('.fm-progress-label').textContent = label || 'Working\u2026';
    this._el.style.display = 'flex';
  },
  hide() { if (this._el) this._el.style.display = 'none'; }
};

/* ===== DRAG & DROP ===== */
const DnD = {
  _dragNames: [],
  startMove(e, name) {
    Sel.ensureSelected(name);
    this._dragNames = [...S.sel];
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', name);
  },
  handleMoveDrop(destPath) {
    const names = this._dragNames;
    if (!names.length) return;
    (async () => {
      let ok = 0;
      for (const n of names) {
        const r = await API.move(joinPath(S.cwd, n), destPath);
        if (r.ok) ok++;
      }
      Toast.show('Moved ' + ok + ' item(s)', 'success');
      Nav.go(S.cwd);
      Tree.load(S.cwd);
      Tree.load(destPath);
    })();
  },
  initUploadDrop() {
    const app = $('#fm-app');
    const overlay = $('#fm-drop-overlay');
    let dragCount = 0;
    app.addEventListener('dragenter', e => {
      if (e.dataTransfer.types.includes('Files')) { dragCount++; overlay.style.display = 'flex'; }
    });
    app.addEventListener('dragleave', () => {
      dragCount--;
      if (dragCount <= 0) { dragCount = 0; overlay.style.display = 'none'; }
    });
    app.addEventListener('dragover', e => {
      if (e.dataTransfer.types.includes('Files')) e.preventDefault();
    });
    app.addEventListener('drop', e => {
      dragCount = 0; overlay.style.display = 'none';
      if (e.dataTransfer.files.length) { e.preventDefault(); Ops._doUpload(e.dataTransfer.files); }
    });
  }
};

/* ===== RESIZE ===== */
const Resize = {
  init() {
    const handle = $('#fm-resize');
    const sidebar = $('#fm-sidebar');
    let dragging = false, startX = 0, startW = 0;
    document.documentElement.style.setProperty('--fm-sidebar-w', S.sidebarW + 'px');
    sidebar.style.width = S.sidebarW + 'px';

    handle.addEventListener('mousedown', e => {
      dragging = true; startX = e.clientX; startW = sidebar.offsetWidth;
      handle.classList.add('active');
      e.preventDefault();
    });
    document.addEventListener('mousemove', e => {
      if (!dragging) return;
      let w = startW + (e.clientX - startX);
      w = Math.max(150, Math.min(400, w));
      sidebar.style.width = w + 'px';
      document.documentElement.style.setProperty('--fm-sidebar-w', w + 'px');
    });
    document.addEventListener('mouseup', () => {
      if (!dragging) return;
      dragging = false;
      handle.classList.remove('active');
      S.sidebarW = sidebar.offsetWidth;
      localStorage.setItem('fm_sidebar_w', S.sidebarW);
    });
  }
};

/* ===== KEYBOARD ===== */
function setupKeyboard() {
  document.addEventListener('keydown', e => {
    // ignore if typing in input/textarea
    if (e.target.matches('input, textarea, select')) return;
    if ($('#fm-modal').style.display === 'flex' && e.key === 'Escape') { Modal.hide(); return; }

    if (e.key === 'Delete' || e.key === 'Backspace') { if (S.sel.size) { e.preventDefault(); Ops.del(); } }
    else if (e.key === 'F2') { if (S.sel.size === 1) { e.preventDefault(); Ops.rename(); } }
    else if (e.key === 'Enter') {
      if (S.sel.size === 1) { e.preventDefault(); Files._open([...S.sel][0]); }
    }
    else if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); Sel.selectAll(); }
    else if ((e.ctrlKey || e.metaKey) && e.key === 'c') { if (S.sel.size) { e.preventDefault(); Ops.copy(); } }
    else if ((e.ctrlKey || e.metaKey) && e.key === 'x') { if (S.sel.size) { e.preventDefault(); Ops.cut(); } }
    else if ((e.ctrlKey || e.metaKey) && e.key === 'v') { if (S.clipboard) { e.preventDefault(); Ops.paste(); } }
    else if (e.key === '?' || (e.shiftKey && e.key === '/')) { e.preventDefault(); Help.show(); }
    else if (e.key === 'Escape') { Ctx.hide(); Help.hide(); }
    else if (e.key === 'F11') { e.preventDefault(); Pane.toggle(); }
    else if (e.key === 'Tab' && S.dualMode) { e.preventDefault(); Pane.switch(S.activePane === 'A' ? 'B' : 'A'); }
    // Arrow-key navigation through the current listing
    else if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Home','End','PageUp','PageDown'].includes(e.key)) {
      if (!S.items || !S.items.length) return;
      e.preventDefault();
      Nav.moveSelection(e.key, e.shiftKey);
    }
  });
}

/* ===== THEME =====
 * Legacy two-state toggle kept for the dark/light quick-toggle button.
 * The Prefs module now drives the richer theme+font system; toggleTheme
 * just cycles between dark and light without touching Win95/WFW state.
 */
function applyTheme() {
  document.documentElement.setAttribute('data-theme', S.theme);
}
function toggleTheme() {
  // Cycle dark → light → win95 → wfw → dark
  const order = ['dark','light','win95','wfw'];
  const cur = order.indexOf(S.theme);
  const next = order[(cur + 1) % order.length];
  Prefs.setTheme(next);
}

/* ===== VIEW TOGGLE ===== */
function setView(v) {
  S.view = v;
  localStorage.setItem('fm_view', v);
  Files.render();
  Toolbar.update();
}
function thumbZoom(dir) {
  S.thumbIdx = Math.max(0, Math.min(THUMB_SIZES.length - 1, S.thumbIdx + dir));
  localStorage.setItem('fm_thumb_idx', S.thumbIdx);
  if (S.view === 'grid') Files.render();
  Toolbar.update();
}
function thumbFitToggle() {
  S.thumbFit = S.thumbFit === 'cover' ? 'contain' : 'cover';
  localStorage.setItem('fm_thumb_fit', S.thumbFit);
  if (S.view === 'grid') Files.render();
  Toolbar.update();
}

/* ===== INIT ===== */
function init() {
  Prefs.load();   // Reads theme + font-pref from localStorage and applies
  Resize.init();

  // toolbar buttons
  $('#tb-back').onclick    = () => Nav.back();
  $('#tb-fwd').onclick     = () => Nav.forward();
  $('#tb-up').onclick      = () => Nav.up();
  $('#tb-home').onclick    = () => Nav.home();
  $('#tb-mkdir').onclick   = () => Ops.mkdir();
  $('#tb-upload').onclick  = () => Ops.upload();
  $('#tb-cut').onclick     = () => Ops.cut();
  $('#tb-copy').onclick    = () => Ops.copy();
  $('#tb-paste').onclick   = () => Ops.paste();
  $('#tb-rename').onclick  = () => Ops.rename();
  $('#tb-delete').onclick  = () => Ops.del();
  $('#tb-download').onclick= () => Ops.download();
  $('#tb-zip').onclick     = () => Ops.zip();
  $('#tb-unzip').onclick   = () => Ops.unzip();
  $('#tb-backup').onclick  = () => Ops.backup();
  $('#tb-chown').onclick   = () => Ops.chownFix();
  $('#tb-list').onclick    = () => setView('list');
  $('#tb-grid').onclick    = () => setView('grid');
  $('#tb-zoom-out').onclick = () => thumbZoom(-1);
  $('#tb-zoom-in').onclick  = () => thumbZoom(1);
  $('#tb-fit').onclick      = () => thumbFitToggle();
  $('#tb-theme').onclick   = () => toggleTheme();
  const prefsBtn = $('#tb-prefs'); if (prefsBtn) prefsBtn.onclick = () => Prefs.show();
  const helpBtn  = $('#tb-help');  if (helpBtn)  helpBtn.onclick  = () => Help.show();
  const dualBtn  = $('#tb-dual');  if (dualBtn)  dualBtn.onclick  = () => Pane.toggle();

  // Pane B address bar — click to activate that pane
  const addrB = $('#fm-address-b');
  if (addrB) addrB.addEventListener('click', () => Pane.switch('B'));
  // Rubber-band drag-select on both content panes
  RubberBand.attach($('#fm-content'));
  const contentB = $('#fm-content-b'); if (contentB) RubberBand.attach(contentB);
  // Restore per-pane cwds (persisted across reloads)
  const savedCwdA = localStorage.getItem('fm_cwd_A') || '';
  const savedCwdB = localStorage.getItem('fm_cwd_B') || '';
  if (savedCwdA && withinRoot(savedCwdA)) S.paneA.cwd = savedCwdA;
  // Apply initial layout if dual mode was persisted on
  if (S.dualMode) {
    if (!S.paneB) { S.paneB = makePane(); }
    S.paneB.cwd = (savedCwdB && withinRoot(savedCwdB)) ? savedCwdB : S.paneA.cwd;
    Pane.updateLayout();
    // Load pane B's listing so its content is populated on reload
    Pane._loadInto(S.paneB);
    // Reflect pane B's cwd in its address readout
    if (addrB) addrB.value = S.paneB.cwd || '/';
  }

  // Clipboard pill: click to clear
  const clipPill = $('#fm-clip-pill');
  if (clipPill) clipPill.onclick = () => { S.clipboard = null; Toast.show('Clipboard cleared', 'info'); Toolbar.update(); };

  // modal close
  $('#fm-modal-close').onclick = () => Modal.hide();
  $('.fm-modal-overlay').onclick = () => Modal.hide();

  // Relocate context menu to body — escapes .content-area backdrop-filter stacking context
  const ctxEl = $('#fm-ctx');
  if (ctxEl && ctxEl.parentNode !== document.body) document.body.appendChild(ctxEl);

  // close context menu
  document.addEventListener('click', e => { if (!e.target.closest('.fm-ctx')) Ctx.hide(); });
  document.addEventListener('contextmenu', e => { if (!e.target.closest('#fm-app')) Ctx.hide(); });

  // address bar
  $('#fm-address').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); Nav.go($('#fm-address').value.replace(/^\/+/, '')); }
  });

  // keyboard shortcuts
  setupKeyboard();

  // drag-drop upload
  DnD.initUploadDrop();

  // Full Tree View toggle (Superadmin only) — flips the server-side scope, then
  // reloads so the page re-renders rooted at the new scope.
  const ftCb = $('#fm-fulltree-cb');
  if (ftCb) ftCb.addEventListener('change', async () => {
    ftCb.disabled = true;
    try { await API._post('set_view_scope', { full_tree: ftCb.checked ? '1' : '0' }); } catch (_) {}
    try { localStorage.removeItem('fm_cwd_A'); localStorage.removeItem('fm_cwd_B'); } catch (_) {}
    location.reload();
  });

  // initial load — anchored at ROOT (default /media; full tree when opted in)
  const startCwd = (S.paneA.cwd && withinRoot(S.paneA.cwd)) ? S.paneA.cwd : ROOT;
  Tree.load(ROOT);
  Nav.go(startCwd);
  Ops.loadDiskUsage();
}

document.addEventListener('DOMContentLoaded', init);

})();
