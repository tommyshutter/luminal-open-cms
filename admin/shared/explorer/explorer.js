/**
 * Luminal CMS — Explorer engine (reusable media browser)
 * @file /admin/shared/explorer/explorer.js
 *
 * Grid (thumbnails) ⇄ List (details) view modes, full filenames, rich
 * metadata, multi-select, upload, new-folder, move, delete, rename.
 * Talks to the existing /admin/shared/ajax_media_handler.php backend and
 * preserves the legacy iframe contract (mediaSelected / media-pick /
 * mb-drag-start|end) so it is a drop-in for every current host.
 *
 * Sources: besides the media types, a host can request the "inserts"
 * source (?types=…,inserts) — a catalog of every insertable shortcode on
 * the site (stacks, galleries, widgets, panels…) served by insertables.php.
 * Insert cards drag with the same x-mb-drag contract (payload.kind =
 * 'shortcode') and click to a lightbox that LIVE-RENDERS the shortcode via
 * shortcode-preview.php; the Add button emits `insertSelected` upward.
 */
(function () {
  'use strict';

  var BOOT = window.EXPLORER_BOOT || {};
  var HANDLER = BOOT.handler || '/admin/shared/ajax_media_handler.php';
  var THUMB_API = '/admin/modules/MediaThumbs/api/media-cache.php';
  var INSERTABLES_API = '/admin/shared/explorer/insertables.php';
  var PREVIEW_API = '/admin/shared/explorer/shortcode-preview.php';
  var inIframe = window.parent && window.parent !== window;
  var pickerMode = BOOT.mode === 'picker';          // HTMLEditor → media-pick
  var hostMode = BOOT.host || '';                    // host hint for labels/contract
  // Click = preview (lightbox). Insertion into the host is drag-based, with an
  // explicit "Add" button in the lightbox as the click path.
  var canEmit = inIframe;
  var emitLabel = hostMode === 'gallery' ? 'Add to Gallery'
    : (hostMode === 'contentstacks' ? 'Insert into Stack' : 'Use This File');

  // Which media tabs to show (host can restrict, e.g. ?types=images,videos)
  var TYPES = (BOOT.types ? String(BOOT.types).split(',') : ['images', 'videos', 'pdfs'])
    .map(function (s) { return s.trim(); }).filter(Boolean);

  var TAB_LABELS = { images: '🖼️ Images', videos: '🎬 Videos', pdfs: '📄 PDFs', audio: '🎵 Audio', inserts: '🧩 Inserts' };
  var TYPE_ICON = { images: '🖼️', videos: '🎬', pdfs: '📄', audio: '🎵', inserts: '🧩' };
  // The inserts source is a shortcode catalog, not files — no file ops.
  function isIns() { return state.type === 'inserts'; }

  // ── State ──────────────────────────────────────────────────────────
  var state = {
    type: TYPES[0] || 'images',
    view: localStorage.getItem('ex_view') || 'grid',          // 'grid' | 'list'
    cell: parseInt(localStorage.getItem('ex_cell') || '150'),  // grid cell px
    fit: localStorage.getItem('ex_fit') || 'contain',
    sortField: 'name', sortAsc: true,
    query: '',
    items: [],        // flat list for current type
    folders: [],      // [{label,count,totalSize}]
    visible: [],      // items in current render order (lightbox prev/next)
    selection: new Set(),
  };

  // ── DOM refs ───────────────────────────────────────────────────────
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var elTabs = $('#ex-tabs'), elBody = $('#ex-body'), elStatus = $('#ex-status'),
      elSearch = $('#ex-search-input'), elSortSel = $('#ex-sort'),
      elBtnGrid = $('#ex-view-grid'), elBtnList = $('#ex-view-list'),
      elZoom = $('#ex-zoom'), elZoomRange = $('#ex-zoom-range'), elFit = $('#ex-fit'),
      elBtnUpload = $('#ex-upload'), elBtnNewFolder = $('#ex-newfolder'),
      elBtnMove = $('#ex-move'), elBtnDelete = $('#ex-delete'), elBtnSelectAll = $('#ex-selectall'),
      elFileInput = $('#ex-fileinput'), elRoot = $('#ex-root');

  function root() {
    if (state.type === 'pdfs')   return 'media/pdfs';
    if (state.type === 'videos') return 'media/videos';
    if (state.type === 'audio')  return 'media/audio';
    return 'media/images';
  }

  // ── Helpers ────────────────────────────────────────────────────────
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
  function hsize(b) {
    b = b || 0;
    if (b < 1024) return b + ' B';
    var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
    return b.toFixed(1) + ' ' + u[i];
  }
  function fmtDate(ts) {
    if (!ts) return '';
    var d = new Date(ts * 1000), mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
    return mo + ' ' + d.getDate() + ' ' + d.getFullYear();
  }
  function fileFallbackIcon(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return '📄';
    if (['mp4', 'webm', 'mov', 'm4v', 'ogv'].indexOf(ext) >= 0) return '🎬';
    if (['mp3', 'm4a', 'aac', 'flac', 'ogg', 'wav', 'wma'].indexOf(ext) >= 0) return '🎵';
    return '🖼️';
  }
  // Thumbnail URL: images render directly; video/pdf use the .cache convention.
  function thumbUrl(item) {
    if (state.type === 'images') return '/' + item.path;
    var dir = item.path.substring(0, item.path.lastIndexOf('/'));
    var base = item.name.replace(/\.[^.]+$/, '');
    return '/' + dir + '/.cache/' + base + '.jpg';
  }
  function status(msg, kind) {
    elStatus.textContent = msg || '';
    elStatus.className = 'ex-status' + (kind ? ' ' + kind : '');
    if (kind === 'ok' || kind === 'err') setTimeout(function () { if (elStatus.textContent === msg) elStatus.textContent = ''; }, 5000);
  }

  // ── Data ───────────────────────────────────────────────────────────
  function load() {
    elBody.innerHTML = '<div class="ex-empty">Loading…</div>';
    state.selection.clear(); syncSelToolbar(); syncToolbarMode();
    if (isIns()) { loadInserts(); return; }
    fetch(HANDLER + '?action=fetch_media&type=' + encodeURIComponent(state.type))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Failed');
        state.items = d.media || [];
        state.folders = d.folders || [];
        render();
      })
      .catch(function (e) { elBody.innerHTML = '<div class="ex-empty" style="color:var(--ex-red)">Error: ' + esc(e.message) + '</div>'; });
  }

  // Inserts source: flatten the grouped shortcode catalog into the same
  // {items, folders} shape the renderer already understands. Each item
  // keeps its shortcode in .code; .path gets a synthetic 'ins:' id so
  // lightbox indexing and dataset wiring keep working unchanged.
  function loadInserts() {
    fetch(INSERTABLES_API)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error(d.message || 'Failed');
        var items = [], folders = [];
        (d.groups || []).forEach(function (g) {
          folders.push({ label: g.label, count: (g.items || []).length, totalSize: 0 });
          (g.items || []).forEach(function (it) {
            items.push({
              path: 'ins:' + it.id, name: it.label, folder: g.label,
              code: it.code, badge: it.badge || '', color: it.color || g.accent || '#6580eb',
              desc: it.desc || '', preview: !!it.preview, size: 0, mtime: 0,
            });
          });
        });
        state.items = items; state.folders = folders;
        render();
      })
      .catch(function (e) { elBody.innerHTML = '<div class="ex-empty" style="color:var(--ex-red)">Error: ' + esc(e.message) + '</div>'; });
  }

  function subfolderOf(folderKey) {
    // folderKey like "IMAGES/BANDS" or "IMAGES (root)" → subfolder path used by handler
    if (folderKey.indexOf('/') >= 0) return folderKey.split('/').slice(1).join('/').toLowerCase();
    return '';
  }

  function sortItems(arr) {
    var f = state.sortField, asc = state.sortAsc ? 1 : -1;
    return arr.slice().sort(function (a, b) {
      var va, vb;
      if (f === 'size') { va = a.size || 0; vb = b.size || 0; return (va - vb) * asc; }
      if (f === 'mtime') { va = a.mtime || 0; vb = b.mtime || 0; return (va - vb) * asc; }
      return (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase()) * asc;
    });
  }

  // ── Render ─────────────────────────────────────────────────────────
  function loadCollapsed() { try { return new Set(JSON.parse(localStorage.getItem('ex_collapsed_' + state.type) || '[]')); } catch (e) { return new Set(); } }
  function saveCollapsed(set) { localStorage.setItem('ex_collapsed_' + state.type, JSON.stringify(Array.from(set))); }   // Array.from: Set isn't array-like

  function applyCell() {
    elRoot.style.setProperty('--ex-cell', state.cell + 'px');
    elRoot.style.setProperty('--ex-thumb-h', Math.round(state.cell * 0.8) + 'px');
  }

  function render() {
    applyCell();
    elBtnGrid.classList.toggle('active', state.view === 'grid');
    elBtnList.classList.toggle('active', state.view === 'list');
    elZoom.style.display = state.view === 'grid' ? '' : 'none';
    elFit.style.display = state.view === 'grid' ? '' : 'none';

    var q = state.query.trim().toLowerCase();
    var groups = {};
    state.folders.forEach(function (f) { groups[f.label] = { label: f.label, files: [], size: 0 }; });
    state.items.forEach(function (it) {
      // Inserts also match on shortcode + badge so "stack" or "vstats" filters work
      var hay = it.name.toLowerCase() + (isIns() ? ' ' + (it.code || '').toLowerCase() + ' ' + (it.badge || '').toLowerCase() : '');
      if (q && hay.indexOf(q) < 0) return;
      var key = it.folder || 'Uncategorized';
      if (!groups[key]) groups[key] = { label: key, files: [], size: 0 };
      groups[key].files.push(it); groups[key].size += it.size || 0;
    });

    var keys = Object.keys(groups).sort();
    var anyFiles = keys.some(function (k) { return groups[k].files.length; });
    if (!anyFiles && !keys.length) { elBody.innerHTML = '<div class="ex-empty">No media in this category.</div>'; return; }
    if (q && !anyFiles) { elBody.innerHTML = '<div class="ex-empty">No matches for “' + esc(state.query) + '”.</div>'; return; }

    var collapsed = loadCollapsed();
    elBody.innerHTML = '';
    state.visible = [];
    keys.forEach(function (key) {
      var g = groups[key];
      if (q && !g.files.length) return; // hide empty folders while filtering
      var sub = subfolderOf(key);
      var section = document.createElement('div'); section.className = 'ex-group';

      var head = document.createElement('div');
      head.className = 'ex-group-head' + (collapsed.has(key) ? ' collapsed' : '');
      head.dataset.folder = sub;
      head.innerHTML = '<span class="ex-caret">▾</span><span>' + (isIns() ? '🧩 ' : '📁 ') + esc(key) + '</span>'
        + '<span class="ex-grp-count">(' + g.files.length + ')</span>'
        + (isIns() ? '' : '<button type="button" class="ex-grp-select" title="Select / deselect all in this folder"><span class="ex-grp-select-lbl">Select all</span></button>')
        + (isIns() ? '' : '<span class="ex-grp-size">' + hsize(g.size) + '</span>')
        + (!isIns() && sub ? '<button type="button" class="ex-grp-del" title="Delete this folder and its contents">🗑</button>' : '');
      section.appendChild(head);

      var bodyEl = document.createElement('div');
      bodyEl.className = 'ex-group-body ' + state.view + (collapsed.has(key) ? ' collapsed' : '');
      bodyEl.dataset.folder = sub;
      if (state.view === 'list' && g.files.length) bodyEl.appendChild(listHeader());
      sortItems(g.files).forEach(function (it) {
        state.visible.push(it);
        bodyEl.appendChild(state.view === 'grid' ? gridCard(it) : listRow(it));
      });
      section.appendChild(bodyEl);

      head.addEventListener('click', function () {
        var c = loadCollapsed();
        head.classList.toggle('collapsed'); bodyEl.classList.toggle('collapsed');
        if (head.classList.contains('collapsed')) c.add(key); else c.delete(key);
        saveCollapsed(c);
      });
      if (!isIns() && sub) {
        var grpDel = head.querySelector('.ex-grp-del');
        if (grpDel) grpDel.addEventListener('click', function (e) {
          e.stopPropagation();            // don't toggle collapse
          delFolder(sub);
        });
      }
      if (!isIns()) {
        var grpSel = head.querySelector('.ex-grp-select');
        if (grpSel) grpSel.addEventListener('click', function (e) {
          e.stopPropagation();            // don't toggle collapse
          toggleFolder(bodyEl);
        });
      }
      if (!isIns()) { wireDrop(head, sub); wireDrop(bodyEl, sub); }   // no file drops on a shortcode catalog
      elBody.appendChild(section);
    });
  }

  function metaLine(it) {
    if (isIns()) return it.desc || '';
    var bits = [hsize(it.size)];
    if (it.w && it.h) bits.push(it.w + '×' + it.h);
    if (it.mtime) bits.push(fmtDate(it.mtime));
    return bits.join(' · ');
  }

  function gridCard(it) {
    var card = document.createElement('div');
    card.className = 'ex-card' + (state.selection.has(it.path) ? ' selected' : '');
    card.dataset.path = it.path; card.dataset.folder = it.folder || ''; card.draggable = true;
    if (isIns()) {
      // Shortcode card — accent swatch instead of a thumbnail, code badge below
      card.classList.add('ex-card-ins');
      card.style.setProperty('--ins-accent', it.color);
      card.innerHTML = '<div class="ex-ins-swatch"><span class="ex-ins-badge">' + esc(it.badge || '⧉') + '</span></div>'
        + '<div class="ex-card-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
        + '<div class="ex-card-meta ex-ins-code" title="' + esc(it.code) + '">' + esc(it.code) + '</div>';
      return card;
    }
    card.innerHTML = '<span class="ex-card-check" title="Select">✓</span>'
      + '<img class="ex-thumb" data-fit="' + state.fit + '" loading="lazy" draggable="false" src="' + esc(thumbUrl(it)) + '" alt="' + esc(it.name) + '">'
      + '<div class="ex-card-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
      + '<div class="ex-card-meta">' + esc(metaLine(it)) + '</div>';
    wireThumb(card.querySelector('.ex-thumb'), it, 'ex-thumb-fallback');
    return card;
  }

  function listHeader() {
    var h = document.createElement('div'); h.className = 'ex-list-head';
    if (isIns()) {
      h.innerHTML = '<span></span>'
        + '<span data-sort="name">Name ' + sortCaret('name') + '</span>'
        + '<span class="ex-row-meta">Shortcode</span>'
        + '<span class="ex-row-meta r">Kind</span><span></span>';
    } else {
    h.innerHTML = '<span></span>'
      + '<span data-sort="name">Name ' + sortCaret('name') + '</span>'
      + '<span data-sort="size" class="ex-row-meta r">Size ' + sortCaret('size') + '</span>'
      + '<span data-sort="mtime" class="ex-row-meta r">Modified ' + sortCaret('mtime') + '</span>'
      + '<span class="ex-row-meta r">Dimensions</span>';
    }
    h.querySelectorAll('[data-sort]').forEach(function (s) {
      s.addEventListener('click', function () {
        var f = s.dataset.sort;
        if (state.sortField === f) state.sortAsc = !state.sortAsc; else { state.sortField = f; state.sortAsc = true; }
        render();
      });
    });
    return h;
  }
  function sortCaret(f) { return state.sortField === f ? (state.sortAsc ? '▲' : '▼') : ''; }

  function listRow(it) {
    var row = document.createElement('div');
    row.className = 'ex-row' + (state.selection.has(it.path) ? ' selected' : '');
    row.dataset.path = it.path; row.dataset.folder = it.folder || ''; row.draggable = true;
    if (isIns()) {
      row.classList.add('ex-row-ins');
      row.style.setProperty('--ins-accent', it.color);
      row.innerHTML = '<span class="ex-ins-dot"></span>'
        + '<div class="ex-row-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
        + '<div class="ex-row-meta ex-ins-code" title="' + esc(it.code) + '">' + esc(it.code) + '</div>'
        + '<div class="ex-row-meta r">' + esc(it.desc || '') + '</div><div></div>';
      return row;
    }
    row.innerHTML = '<img class="ex-row-thumb" loading="lazy" draggable="false" src="' + esc(thumbUrl(it)) + '" alt="">'
      + '<div class="ex-row-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
      + '<div class="ex-row-meta r">' + hsize(it.size) + '</div>'
      + '<div class="ex-row-meta r">' + (it.mtime ? fmtDate(it.mtime) : '—') + '</div>'
      + '<div class="ex-row-meta r">' + (it.w && it.h ? it.w + '×' + it.h : '—') + '</div>';
    wireThumb(row.querySelector('.ex-row-thumb'), it, 'ex-row-thumb fallback');
    return row;
  }

  // ── On-demand thumbnails (PDF first page / video frame via MediaThumbs) ──
  // A missing .cache thumb queues an ensure_thumb call (throttled) and swaps
  // the real thumbnail in when it lands; falls back to a type icon only if
  // the server genuinely can't render one.
  var thumbQueue = [], thumbActive = 0, thumbFailed = {}, thumbJobs = {};
  function wireThumb(img, it, fallbackClass) {
    if (!img || state.type === 'images') return;
    // Capture kind + thumb URL NOW — state.type may have changed (tab switch)
    // by the time the queued job actually runs.
    var kind = state.type === 'pdfs' ? 'pdf' : (state.type === 'audio' ? 'audio' : 'video');
    var url = thumbUrl(it);
    img.onerror = function () {
      img.onerror = null;
      if (thumbFailed[it.path]) { thumbFallback(img, it, fallbackClass); return; }
      var job = thumbJobs[it.path];
      if (job) { job.imgs.push({ img: img, cls: fallbackClass }); return; }  // already in flight — re-render dedup
      job = { it: it, kind: kind, url: url, imgs: [{ img: img, cls: fallbackClass }] };
      thumbJobs[it.path] = job;
      thumbQueue.push(job);
      pumpThumbs();
    };
  }
  function thumbFallback(img, it, cls) {
    var d = document.createElement('div');
    d.className = cls; d.textContent = fileFallbackIcon(it.name);
    if (img.parentNode) img.parentNode.replaceChild(d, img);
  }
  // Client-side poster fallback: on hosts without ffmpeg the server can't
  // render a video frame, so grab one in the browser from a hidden <video>
  // drawn to a canvas. Same-origin, so the canvas stays untainted.
  function clientVideoPoster(it) {
    return new Promise(function (resolve) {
      var v = document.createElement('video');
      v.muted = true; v.preload = 'metadata'; v.crossOrigin = 'anonymous';
      v.playsInline = true;
      var done = false;
      function finish(val) { if (done) return; done = true; try { v.removeAttribute('src'); v.load(); } catch (e) {} resolve(val); }
      v.addEventListener('error', function () { finish(null); });
      v.addEventListener('loadeddata', function () {
        try { v.currentTime = Math.min(1, (v.duration || 2) / 2); } catch (e) { finish(null); }
      });
      v.addEventListener('seeked', function () {
        try {
          var w = v.videoWidth, h = v.videoHeight;
          if (!w || !h) { finish(null); return; }
          var scale = Math.min(1, 640 / w);
          var c = document.createElement('canvas');
          c.width = Math.round(w * scale); c.height = Math.round(h * scale);
          c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
          finish(c.toDataURL('image/jpeg', 0.82));
        } catch (e) { finish(null); }   // tainted canvas / decode failure
      });
      setTimeout(function () { finish(null); }, 8000);
      v.src = '/' + it.path;
    });
  }
  // Server thumb failed for a video → try a client poster, show it, and persist
  // it back so it becomes a real .cache thumb for everyone next time.
  function videoPosterFallback(job) {
    clientVideoPoster(job.it).then(function (url) {
      if (!url) {
        thumbFailed[job.it.path] = 1;
        job.imgs.forEach(function (e) { thumbFallback(e.img, job.it, e.cls); });
        return;
      }
      job.imgs.forEach(function (e) { e.img.onerror = function () { thumbFallback(e.img, job.it, e.cls); }; e.img.src = url; });
      var fd = new FormData();
      fd.append('src', '/' + job.it.path);
      fd.append('data', url);
      fetch(THUMB_API + '?action=save_thumb', { method: 'POST', body: fd }).catch(function () {});
    });
  }
  function pumpThumbs() {
    while (thumbActive < 2 && thumbQueue.length) {
      (function (job) {
        thumbActive++;
        fetch(THUMB_API + '?action=ensure_thumb&kind=' + job.kind + '&src=' + encodeURIComponent('/' + job.it.path))
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.success) throw new Error(d.message || 'thumb failed');
            job.imgs.forEach(function (e) {
              e.img.onerror = function () { thumbFallback(e.img, job.it, e.cls); };
              e.img.src = job.url + '?t=' + Date.now();   // bust the cached 404
            });
          })
          .catch(function () {
            if (job.kind === 'video') { videoPosterFallback(job); return; }
            thumbFailed[job.it.path] = 1;
            job.imgs.forEach(function (e) { thumbFallback(e.img, job.it, e.cls); });
          })
          .finally(function () { delete thumbJobs[job.it.path]; thumbActive--; pumpThumbs(); });
      })(thumbQueue.shift());
    }
  }

  // ── Selection / preview ────────────────────────────────────────────
  // Click = preview in the lightbox (mainstream pattern). Selection is the
  // hover ✓ corner or Ctrl/Click; insertion into the host is drag-and-drop
  // or the lightbox "Add" button.
  elBody.addEventListener('click', function (e) {
    var el = e.target.closest('.ex-card, .ex-row'); if (!el) return;
    var path = el.dataset.path;
    if (isIns()) { openLightbox(path); return; }   // catalog: no selection, click = preview
    if (e.target.closest('.ex-card-check') || e.ctrlKey || e.metaKey || e.shiftKey) { toggleSel(path, el); return; }
    openLightbox(path);
  });
  function itemByPath(path) {
    for (var i = 0; i < state.items.length; i++) if (state.items[i].path === path) return state.items[i];
    return null;
  }
  function emitPick(path) {
    if (!inIframe) return;
    if (isIns()) {
      var it = itemByPath(path); if (!it) return;
      window.parent.postMessage({ type: 'insertSelected', code: it.code, label: it.name }, '*');
      return;
    }
    if (pickerMode) window.parent.postMessage({ type: 'media-pick', path: path, name: path.split('/').pop() }, '*');
    else window.parent.postMessage({ type: 'mediaSelected', path: path }, '*');
  }
  function toggleSel(path, el) {
    if (state.selection.has(path)) { state.selection.delete(path); el.classList.remove('selected'); }
    else { state.selection.add(path); el.classList.add('selected'); }
    syncSelToolbar();
  }
  function syncSelToolbar() {
    var n = state.selection.size;
    elBtnDelete.disabled = n === 0; elBtnMove.disabled = n === 0;
    elBtnSelectAll.textContent = n > 0 ? 'Deselect (' + n + ')' : 'Select All';
    refreshFolderToggles();
  }
  // Per-folder Select-all helpers. "All selected" is folder-scoped: every card
  // currently rendered in that folder's body is in the selection set.
  function folderCards(bodyEl) { return [].slice.call(bodyEl.querySelectorAll('.ex-card, .ex-row')); }
  function folderAllSelected(bodyEl) {
    var cards = folderCards(bodyEl);
    if (!cards.length) return false;
    for (var i = 0; i < cards.length; i++) if (!state.selection.has(cards[i].dataset.path)) return false;
    return true;
  }
  function toggleFolder(bodyEl) {
    var cards = folderCards(bodyEl); if (!cards.length) return;
    var all = folderAllSelected(bodyEl);
    cards.forEach(function (c) {
      if (all) { state.selection.delete(c.dataset.path); c.classList.remove('selected'); }
      else if (!state.selection.has(c.dataset.path)) { state.selection.add(c.dataset.path); c.classList.add('selected'); }
    });
    syncSelToolbar();
  }
  // Cheap: only flips the .on class (CSS swaps the ☐/☑ glyph) — safe to call
  // on every marquee mousemove.
  function refreshFolderToggles() {
    var groups = elBody.querySelectorAll('.ex-group');
    for (var i = 0; i < groups.length; i++) {
      var btn = groups[i].querySelector('.ex-grp-select'); if (!btn) continue;
      var body = groups[i].querySelector('.ex-group-body'); if (!body) continue;
      btn.classList.toggle('on', folderAllSelected(body));
    }
  }
  function clearAllSelected() {
    state.selection.clear();
    elBody.querySelectorAll('.ex-card.selected, .ex-row.selected').forEach(function (c) { c.classList.remove('selected'); });
  }
  // File-op chrome makes no sense on the shortcode catalog — hide it there.
  function syncToolbarMode() {
    var hide = isIns();
    [elBtnUpload, elBtnNewFolder, elBtnMove, elBtnDelete, elBtnSelectAll].forEach(function (b) {
      if (b) b.style.display = hide ? 'none' : '';
    });
  }

  // ── Drag (move within /media + signal parent for ContentStacks) ────
  elBody.addEventListener('dragstart', function (e) {
    var el = e.target.closest('.ex-card, .ex-row'); if (!el) return;
    if (isIns()) {
      // Shortcode drag: same x-mb-drag envelope, kind:'shortcode'. text/plain
      // carries the bare code so a plain textarea accepts the drop natively.
      var it = itemByPath(el.dataset.path); if (!it) return;
      var insPayload = { kind: 'shortcode', code: it.code, label: it.name, paths: [], items: [] };
      try {
        e.dataTransfer.setData('application/x-mb-drag', JSON.stringify(insPayload));
        e.dataTransfer.setData('text/plain', it.code);
      } catch (err) {}
      e.dataTransfer.effectAllowed = 'copy';
      el.classList.add('dragging');
      if (inIframe) try { window.parent.postMessage({ type: 'mb-drag-start', payload: insPayload }, '*'); } catch (err) {}
      return;
    }
    var srcs = el.classList.contains('selected') ? [].slice.call(elBody.querySelectorAll('.selected')) : [el];
    var typeHint = state.type === 'pdfs' ? 'pdf' : (state.type === 'videos' ? 'video' : 'image');
    var payload = {
      mediaType: state.type,
      paths: srcs.map(function (s) { return s.dataset.path; }),
      items: srcs.map(function (s) { return { path: s.dataset.path, name: s.dataset.path.split('/').pop(), type: typeHint, folder: s.dataset.folder || '' }; })
    };
    try {
      e.dataTransfer.setData('application/x-mb-drag', JSON.stringify(payload));
      e.dataTransfer.setData('text/plain', '__mb_drag__\n' + JSON.stringify(payload));
    } catch (err) {}
    e.dataTransfer.effectAllowed = 'copyMove';
    srcs.forEach(function (s) { s.classList.add('dragging'); });
    if (inIframe) try { window.parent.postMessage({ type: 'mb-drag-start', payload: payload }, '*'); } catch (err) {}
  });
  elBody.addEventListener('dragend', function () {
    elBody.querySelectorAll('.dragging').forEach(function (s) { s.classList.remove('dragging'); });
    if (inIframe) try { window.parent.postMessage({ type: 'mb-drag-end' }, '*'); } catch (err) {}
  });

  function wireDrop(el, sub) {
    var counter = 0;
    el.addEventListener('dragenter', function (e) { e.preventDefault(); if (++counter === 1) el.classList.add('drop-active'); });
    el.addEventListener('dragover', function (e) {
      e.preventDefault();
      var types = [].slice.call(e.dataTransfer.types || []);
      e.dataTransfer.dropEffect = types.indexOf('application/x-mb-drag') >= 0 ? 'move' : 'copy';
    });
    el.addEventListener('dragleave', function () { if (--counter <= 0) { counter = 0; el.classList.remove('drop-active'); } });
    el.addEventListener('drop', function (e) {
      e.preventDefault(); e.stopPropagation(); counter = 0; el.classList.remove('drop-active');
      var mb = e.dataTransfer.getData('application/x-mb-drag');
      if (mb) {
        try {
          var p = JSON.parse(mb);
          var paths = p.paths.filter(function (path) {
            var parent = path.split('/').slice(2, -1).join('/'); // strip media/<type>/
            return parent !== sub;
          });
          if (paths.length) moveTo(paths, sub);
        } catch (err) {}
        return;
      }
      if (e.dataTransfer.files && e.dataTransfer.files.length) uploadTo(e.dataTransfer.files, sub);
    });
  }

  // ── Operations ─────────────────────────────────────────────────────
  function uploadTo(files, sub) {
    // Shared per-file progress meter. Prefer the LOCAL LumUpload so the panel
    // renders at the point of use (anchored beside #ex-status in this iframe);
    // fall back to the top admin shell's global stack if local isn't loaded.
    var local = (window.LumUpload && window.LumUpload.__real) ? window.LumUpload : null;
    var LU = local;
    try { if (!LU && window.top && window.top.LumUpload && window.top.LumUpload.__real) LU = window.top.LumUpload; } catch (e) { LU = window.LumUpload || null; }

    var data = { action: 'upload_media', type: state.type };
    if (sub) data.path = sub;

    if (LU && LU.uploadEach) {
      status('Uploading ' + files.length + ' file(s)…', 'busy');
      var upOpts = {
        url: HANDLER, files: files, field: 'files[]', data: data,
        onAllDone: function (results) {
          var ok = results.filter(function (r) { return r.ok; }).length;
          var bad = results.length - ok;
          status(bad ? (ok + ' uploaded, ' + bad + ' failed') : (ok + ' file(s) uploaded'), bad ? 'err' : 'ok');
          load();
        }
      };
      if (LU === local && elStatus) upOpts.anchor = elStatus;  // point-of-use panel (local doc only)
      LU.uploadEach(upOpts);
      return;
    }

    // Legacy fallback (no progress component present).
    var fd = new FormData();
    fd.append('action', 'upload_media'); fd.append('type', state.type);
    if (sub) fd.append('path', sub);
    for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
    status('Uploading ' + files.length + ' file(s)…', 'busy');
    fetch(HANDLER, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Upload failed'); status(d.message, 'ok'); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  }
  function moveTo(paths, dest) {
    status('Moving ' + paths.length + ' item(s)…', 'busy');
    postJSON({ action: 'move_media', paths: paths, destination: dest })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Move failed'); status(d.message, 'ok'); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  }
  function del(paths) {
    if (!paths.length) return;
    // Verbose confirm: name the file(s) being deleted, never a bare count.
    var names = paths.map(function (p) { return p.split('/').pop(); });
    var msg;
    if (paths.length === 1) {
      msg = 'Delete “' + names[0] + '”? This cannot be undone.';
    } else {
      var listed = names.slice(0, 25).map(function (n) { return '  • ' + n; }).join('\n');
      if (names.length > 25) listed += '\n  …and ' + (names.length - 25) + ' more';
      msg = 'Delete these ' + names.length + ' files? This cannot be undone.\n\n' + listed;
    }
    if (!confirm(msg)) return;
    status('Deleting…', 'busy');
    postJSON({ action: 'delete_media', paths: paths })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Delete failed'); status(d.message, 'ok'); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  }
  function delFolder(sub) {
    var leaf = String(sub).split('/').pop();
    if (!confirm('Delete the entire folder “' + leaf + '” and EVERYTHING inside it?\n\nThis cannot be undone.')) return;
    status('Deleting folder…', 'busy');
    postJSON({ action: 'delete_folder', type: state.type, path: sub })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Folder delete failed'); status(d.message, 'ok'); state.selection.clear(); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  }
  function rename(path) {
    var cur = path.split('/').pop();
    var name = prompt('Rename to:', cur);
    if (!name || name.trim() === '' || name === cur) return;
    status('Renaming…', 'busy');
    postJSON({ action: 'rename_media', path: path, name: name.trim() })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Rename failed'); status('Renamed', 'ok'); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  }
  function postJSON(body) {
    return fetch(HANDLER, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }

  // ── Context menu (rename / move / delete) ──────────────────────────
  var ctx = null;
  function killCtx() { if (ctx) { ctx.remove(); ctx = null; } }
  document.addEventListener('click', killCtx);
  document.addEventListener('scroll', killCtx, true);
  elBody.addEventListener('contextmenu', function (e) {
    if (isIns()) return;   // no rename/move/delete on catalog entries
    var el = e.target.closest('.ex-card, .ex-row'); if (!el) return;
    e.preventDefault(); killCtx();
    var path = el.dataset.path, fname = path.split('/').pop();
    ctx = document.createElement('div'); ctx.className = 'ex-ctx';
    ctx.innerHTML = '<div class="ex-ctx-item" data-act="rename">✎ Rename “' + esc(fname) + '”</div>'
      + '<div class="ex-ctx-item" data-act="move">⇄ Move…</div>'
      + '<div class="ex-ctx-sep"></div>'
      + '<div class="ex-ctx-item danger" data-act="delete">🗑 Delete</div>';
    ctx.querySelector('[data-act="rename"]').onclick = function () { killCtx(); rename(path); };
    ctx.querySelector('[data-act="move"]').onclick = function () { killCtx(); openMoveModal([path]); };
    ctx.querySelector('[data-act="delete"]').onclick = function () { killCtx(); del([path]); };
    ctx.style.left = Math.min(e.clientX, window.innerWidth - 200) + 'px';
    ctx.style.top = Math.min(e.clientY, window.innerHeight - 140) + 'px';
    document.body.appendChild(ctx);
  });

  // ── Move modal ─────────────────────────────────────────────────────
  function openMoveModal(paths) {
    fetch(HANDLER + '?action=list_folders&type=' + encodeURIComponent(state.type)).then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) throw new Error('Could not list folders');
        var ov = document.createElement('div'); ov.className = 'ex-overlay';
        var opts = (d.folders || []).map(function (f) { return '<option value="' + esc(f.path) + '">' + esc(f.label || f.path || '(root)') + '</option>'; }).join('');
        ov.innerHTML = '<div class="ex-modal"><h3>Move ' + paths.length + ' item' + (paths.length > 1 ? 's' : '') + '</h3>'
          + '<label>Destination folder</label><select class="ex-move-sel">' + opts + '</select>'
          + '<label>…or new folder</label><input class="ex-move-new" placeholder="New folder name">'
          + '<div class="ex-modal-actions"><button class="ex-btn ex-move-cancel">Cancel</button><button class="ex-btn primary ex-move-go">Move</button></div></div>';
        document.body.appendChild(ov);
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        $('.ex-move-cancel', ov).onclick = function () { ov.remove(); };
        $('.ex-move-go', ov).onclick = function () {
          var dest = $('.ex-move-new', ov).value.trim() || $('.ex-move-sel', ov).value;
          ov.remove(); moveTo(paths, dest);
        };
      }).catch(function (e) { status('Error: ' + e.message, 'err'); });
  }

  // ── Lightbox (click-to-preview) ────────────────────────────────────
  var lbEl = null, lbIdx = -1;
  function lbItem() { return state.visible[lbIdx] || null; }
  function openLightbox(path) {
    var idx = -1;
    for (var i = 0; i < state.visible.length; i++) if (state.visible[i].path === path) { idx = i; break; }
    if (idx < 0) return;
    lbIdx = idx;
    if (!lbEl) buildLightbox();
    lbRender();
    lbEl.style.display = 'flex';
  }
  function closeLightbox() { if (lbEl) { lbEl.style.display = 'none'; $('.ex-lb-stage', lbEl).innerHTML = ''; } }
  function lbStep(d) {
    if (!state.visible.length) return;
    lbIdx = (lbIdx + d + state.visible.length) % state.visible.length;
    lbRender();
  }
  function buildLightbox() {
    lbEl = document.createElement('div'); lbEl.className = 'ex-lb';
    lbEl.innerHTML = '<button class="ex-lb-close" title="Close (Esc)">&times;</button>'
      + '<button class="ex-lb-nav ex-lb-prev" title="Previous (←)">&#8249;</button>'
      + '<div class="ex-lb-stage"></div>'
      + '<button class="ex-lb-nav ex-lb-next" title="Next (→)">&#8250;</button>'
      + '<div class="ex-lb-bar">'
      +   '<div class="ex-lb-info"><div class="ex-lb-name"></div><div class="ex-lb-meta"></div></div>'
      +   (canEmit ? '<button class="ex-btn primary ex-lb-add">&#10010; ' + esc(emitLabel) + '</button>' : '')
      + '</div>';
    document.body.appendChild(lbEl);
    $('.ex-lb-close', lbEl).onclick = closeLightbox;
    $('.ex-lb-prev', lbEl).onclick = function () { lbStep(-1); };
    $('.ex-lb-next', lbEl).onclick = function () { lbStep(1); };
    lbEl.addEventListener('click', function (e) { if (e.target === lbEl) closeLightbox(); });
    var add = $('.ex-lb-add', lbEl);
    if (add) add.onclick = function () {
      var it = lbItem(); if (!it) return;
      emitPick(it.path);
      add.textContent = isIns() ? '✓ Inserted' : '✓ Added';
      setTimeout(function () { add.innerHTML = '&#10010; ' + esc(isIns() ? 'Insert' : emitLabel); }, 900);
    };
  }
  function lbRender() {
    var it = lbItem(); if (!it) return;
    var stage = $('.ex-lb-stage', lbEl), url = '/' + it.path;
    if (isIns()) {
      // Live-render the shortcode through the real engine (site CSS included).
      // Items without a renderer get an info card instead of a broken frame.
      if (it.preview) {
        stage.innerHTML = '<iframe class="ex-lb-pdf ex-lb-ins" src="' + esc(PREVIEW_API + '?code=' + encodeURIComponent(it.code)) + '" title="' + esc(it.name) + '"></iframe>';
      } else {
        stage.innerHTML = '<div class="ex-lb-inscard" style="--ins-accent:' + esc(it.color) + '">'
          + '<div class="ex-lb-inscard-badge">' + esc(it.badge || '⧉') + '</div>'
          + '<div class="ex-lb-inscard-name">' + esc(it.name) + '</div>'
          + '<div class="ex-lb-inscard-desc">' + esc(it.desc || '') + '</div>'
          + '<code class="ex-lb-inscard-code">' + esc(it.code) + '</code>'
          + '<div class="ex-lb-inscard-note">No live preview for this item</div></div>';
      }
    } else if (state.type === 'videos') {
      stage.innerHTML = '<video class="ex-lb-media" controls preload="metadata" src="' + esc(url) + '"></video>';
    } else if (state.type === 'pdfs') {
      stage.innerHTML = '<iframe class="ex-lb-pdf" src="' + esc(url) + '#view=FitH" title="' + esc(it.name) + '"></iframe>';
    } else {
      stage.innerHTML = '<img class="ex-lb-media" src="' + esc(url) + '" alt="' + esc(it.name) + '">';
    }
    $('.ex-lb-name', lbEl).textContent = isIns() ? it.name + '   ' + it.code : it.name;
    var bits = [it.folder || '', metaLine(it)].filter(Boolean);
    $('.ex-lb-meta', lbEl).textContent = bits.join('  ·  ') + '   (' + (lbIdx + 1) + ' / ' + state.visible.length + ')';
    var add = $('.ex-lb-add', lbEl);
    if (add) add.innerHTML = '&#10010; ' + esc(isIns() ? 'Insert' : emitLabel);
  }

  // ── Toolbar wiring ─────────────────────────────────────────────────
  TYPES.forEach(function (t) {
    var tab = document.createElement('div');
    tab.className = 'ex-tab' + (t === state.type ? ' active' : '');
    tab.textContent = TAB_LABELS[t] || ((TYPE_ICON[t] || '') + ' ' + t);
    tab.onclick = function () {
      state.type = t; state.query = ''; elSearch.value = '';
      elTabs.querySelectorAll('.ex-tab').forEach(function (x) { x.classList.remove('active'); });
      tab.classList.add('active'); load();
    };
    elTabs.appendChild(tab);
  });

  elBtnGrid.onclick = function () { state.view = 'grid'; localStorage.setItem('ex_view', 'grid'); render(); };
  elBtnList.onclick = function () { state.view = 'list'; localStorage.setItem('ex_view', 'list'); render(); };
  // Thumbnail zoom slider — live while dragging (CSS vars only, no re-render)
  elZoomRange.value = state.cell;
  elZoomRange.addEventListener('input', function () { state.cell = parseInt(elZoomRange.value, 10); applyCell(); });
  elZoomRange.addEventListener('change', function () { localStorage.setItem('ex_cell', state.cell); });
  elFit.onclick = function () {
    state.fit = state.fit === 'contain' ? 'cover' : 'contain'; localStorage.setItem('ex_fit', state.fit);
    elFit.innerHTML = state.fit === 'contain' ? '&#9635;' : '&#9633;';
    elBody.querySelectorAll('.ex-thumb').forEach(function (img) { img.dataset.fit = state.fit; });
  };
  elSortSel.onchange = function () { var v = elSortSel.value.split(':'); state.sortField = v[0]; state.sortAsc = v[1] !== 'desc'; render(); };
  elSearch.oninput = function () { state.query = elSearch.value; render(); };

  elBtnUpload.onclick = function () { elFileInput.click(); };
  elFileInput.onchange = function () { if (elFileInput.files.length) uploadTo(elFileInput.files, ''); elFileInput.value = ''; };
  elBtnNewFolder.onclick = function () {
    var name = prompt('New folder name:'); if (!name || !name.trim()) return;
    status('Creating folder…', 'busy');
    postJSON({ action: 'create_folder', type: state.type, name: name.trim() })
      .then(function (d) { if (!d.success) throw new Error(d.message || 'Failed'); status(d.message, 'ok'); load(); })
      .catch(function (e) { status('Error: ' + e.message, 'err'); });
  };
  // NOTE: Array.from, NOT [].slice.call — a Set is not array-like, so slice
  // returned [] and made multi-select Delete/Move silent no-ops (Tom 2026-06-06).
  elBtnDelete.onclick = function () { del(Array.from(state.selection)); };
  elBtnMove.onclick = function () { if (state.selection.size) openMoveModal(Array.from(state.selection)); };
  elBtnSelectAll.onclick = function () {
    var cards = elBody.querySelectorAll('.ex-card, .ex-row');
    var all = state.selection.size === cards.length && cards.length > 0;
    state.selection.clear();
    cards.forEach(function (c) { if (all) c.classList.remove('selected'); else { state.selection.add(c.dataset.path); c.classList.add('selected'); } });
    syncSelToolbar();
  };

  // ── Marquee (rubber-band) drag-to-select ───────────────────────────
  // Drag across EMPTY space in the grid/list to sweep-select files. The
  // pointer must start OFF a card — cards start a move/insert drag instead,
  // so the two gestures never collide. Ctrl/⌘ while sweeping ADDS to the
  // current selection; otherwise the sweep replaces it. Coordinates live in
  // the scroll container's content space so the band stays glued to the
  // files as it auto-scrolls near the edges.
  var mq = { on: false, active: false, sx: 0, sy: 0, vx: 0, vy: 0, add: false, base: null, box: null, cards: null, raf: 0 };
  function mqContentPt(e) {
    var r = elBody.getBoundingClientRect();
    return { x: e.clientX - r.left + elBody.scrollLeft, y: e.clientY - r.top + elBody.scrollTop };
  }
  elBody.addEventListener('mousedown', function (e) {
    if (e.button !== 0 || isIns()) return;
    if (e.target.closest('.ex-card, .ex-row, .ex-group-head, .ex-list-head, button, a, input, select, textarea')) return;
    var p = mqContentPt(e);
    mq.on = true; mq.active = false; mq.sx = p.x; mq.sy = p.y; mq.vx = e.clientX; mq.vy = e.clientY;
    mq.add = e.ctrlKey || e.metaKey; mq.base = mq.add ? new Set(state.selection) : new Set();
    document.addEventListener('mousemove', mqMove);
    document.addEventListener('mouseup', mqUp);
  });
  function mqActivate() {
    mq.active = true;
    if (!mq.box) { mq.box = document.createElement('div'); mq.box.className = 'ex-marquee'; elBody.appendChild(mq.box); }
    elBody.classList.add('ex-marqueeing');
    if (!mq.add) clearAllSelected();
    // Snapshot card geometry once in content space (scroll-independent), so
    // the per-move hit test is plain arithmetic with no layout reads.
    var rb = elBody.getBoundingClientRect();
    mq.cards = [];
    elBody.querySelectorAll('.ex-card, .ex-row').forEach(function (c) {
      var b = c.getBoundingClientRect();
      if (!b.width && !b.height) return;   // collapsed / hidden folders
      mq.cards.push({ el: c, path: c.dataset.path,
        l: b.left - rb.left + elBody.scrollLeft, t: b.top - rb.top + elBody.scrollTop,
        r: b.right - rb.left + elBody.scrollLeft, btm: b.bottom - rb.top + elBody.scrollTop });
    });
    mqAutoScroll();
  }
  function mqMove(e) {
    if (!mq.on) return;
    mq.vx = e.clientX; mq.vy = e.clientY;
    var p = mqContentPt(e);
    if (!mq.active) {
      if (Math.abs(p.x - mq.sx) < 4 && Math.abs(p.y - mq.sy) < 4) return;   // ignore micro-jitter / plain clicks
      mqActivate();
    }
    e.preventDefault();
    mqDraw(p.x, p.y);
  }
  function mqDraw(cx, cy) {
    var L = Math.min(mq.sx, cx), T = Math.min(mq.sy, cy), R = Math.max(mq.sx, cx), B = Math.max(mq.sy, cy);
    mq.box.style.left = L + 'px'; mq.box.style.top = T + 'px';
    mq.box.style.width = (R - L) + 'px'; mq.box.style.height = (B - T) + 'px';
    for (var i = 0; i < mq.cards.length; i++) {
      var c = mq.cards[i];
      var hit = !(c.l > R || c.r < L || c.t > B || c.btm < T);
      if (hit || mq.base.has(c.path)) {
        if (!state.selection.has(c.path)) { state.selection.add(c.path); c.el.classList.add('selected'); }
      } else if (state.selection.has(c.path)) {
        state.selection.delete(c.path); c.el.classList.remove('selected');
      }
    }
    syncSelToolbar();
  }
  function mqAutoScroll() {
    if (mq.raf) return;
    var step = function () {
      if (!mq.active) { mq.raf = 0; return; }
      var r = elBody.getBoundingClientRect(), m = 42, sp = 0;
      if (mq.vy < r.top + m) sp = -Math.ceil((r.top + m - mq.vy) / 5);
      else if (mq.vy > r.bottom - m) sp = Math.ceil((mq.vy - (r.bottom - m)) / 5);
      if (sp) {
        var before = elBody.scrollTop; elBody.scrollTop += sp;
        if (elBody.scrollTop !== before) { var p = mqContentPt({ clientX: mq.vx, clientY: mq.vy }); mqDraw(p.x, p.y); }
      }
      mq.raf = requestAnimationFrame(step);
    };
    mq.raf = requestAnimationFrame(step);
  }
  function mqUp() {
    document.removeEventListener('mousemove', mqMove);
    document.removeEventListener('mouseup', mqUp);
    if (mq.raf) { cancelAnimationFrame(mq.raf); mq.raf = 0; }
    if (mq.box) { mq.box.remove(); mq.box = null; }
    elBody.classList.remove('ex-marqueeing');
    var wasActive = mq.active;
    mq.on = false; mq.active = false; mq.cards = null; mq.base = null;
    if (wasActive) {
      // Swallow the click that fires right after a real sweep (e.g. if the
      // mouseup lands on a card) so it doesn't open the lightbox.
      var kill = function (ev) { ev.stopPropagation(); ev.preventDefault(); };
      document.addEventListener('click', kill, true);
      setTimeout(function () { document.removeEventListener('click', kill, true); }, 0);
    }
  }

  // Whole-body drop → upload to type root
  elBody.addEventListener('dragover', function (e) { e.preventDefault(); });
  document.addEventListener('dragover', function (e) { e.preventDefault(); });
  document.addEventListener('drop', function (e) { e.preventDefault(); });

  // Keyboard: lightbox nav (modal — swallows all keys while open) + F2 rename
  document.addEventListener('keydown', function (e) {
    if (lbEl && lbEl.style.display === 'flex') {
      if (e.key === 'Escape') closeLightbox();
      else if (e.key === 'ArrowLeft') lbStep(-1);
      else if (e.key === 'ArrowRight') lbStep(1);
      return;   // modal: nothing else (F2 etc.) acts under the lightbox
    }
    if (e.key === 'F2' && state.selection.size === 1) rename(Array.from(state.selection)[0]);
  });

  // Reflect persisted view on first paint
  elSortSel.value = 'name:asc';
  load();
})();
