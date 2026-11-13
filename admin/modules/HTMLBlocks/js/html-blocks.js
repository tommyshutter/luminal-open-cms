/**
 * HTML Blocks admin — card grid + modal editor
 * Shared renderer lives in /includes/renderers/html_block.renderer.php
 */
(function () {
  'use strict';

  var boot = window.HBM_BOOT || {};
  var API  = boot.apiUrl  || '/admin/modules/HTMLBlocks/api.php';
  var CSRF = boot.csrfToken || '';

  var $grid  = document.getElementById('hbm-grid');
  var $modal = document.getElementById('hbm-modal');
  var currentSlug = null;

  // Teleport the modal to <body>: it renders inside .content-area, whose
  // backdrop-filter creates a containing block that hijacks position:fixed —
  // the modal was trapped (short) inside the content frame and the toolbar
  // scrolled away while editing (Tom 2026-06-08). At body level, fixed is
  // truly viewport-fixed and the editor floats free at full height.
  if ($modal && $modal.parentNode !== document.body) {
    document.body.appendChild($modal);
  }

  // Bulk-selection state — set of selected slugs + known slug list for select-all.
  var selected = Object.create(null);
  var allSlugs = [];

  function toast(msg, isError) {
    // Prefer site RIToaster if present
    try {
      if (window.RIToaster && window.RIToaster[isError ? 'error' : 'info']) {
        window.RIToaster[isError ? 'error' : 'info'](msg);
        return;
      }
    } catch (e) {}
    console[isError ? 'error' : 'log']('[HBM]', msg);
    // Fallback: tiny toast
    var d = document.createElement('div');
    d.textContent = msg;
    d.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:12px 18px;background:' +
      (isError ? '#dc2626' : '#059669') + ';color:#fff;border-radius:6px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,.3)';
    document.body.appendChild(d);
    setTimeout(function(){ d.remove(); }, 2800);
  }

  function hbFetch(url, options) {
    options = options || {};
    var method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      options.headers = options.headers || {};
      if (!options.headers['X-CSRF-Token']) {
        options.headers['X-CSRF-Token'] = CSRF;
      }
    }
    return fetch(url, options);
  }

  /* ---------- LIST ---------- */
  function loadList() {
    $grid.innerHTML = '<div class="hbm-empty">Loading…</div>';
    hbFetch(API + '?action=list')
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success) { $grid.innerHTML = '<div class="hbm-empty">' + (r.message || 'Failed to load') + '</div>'; return; }
        renderList(r.blocks || []);
      })
      .catch(function(){ $grid.innerHTML = '<div class="hbm-empty">Network error</div>'; });
  }

  function renderList(blocks) {
    // Rebuild known-slug list and prune selections for blocks that vanished.
    allSlugs = blocks.map(function(b){ return b.slug; });
    Object.keys(selected).forEach(function(s){
      if (allSlugs.indexOf(s) === -1) delete selected[s];
    });

    if (!blocks.length) {
      $grid.innerHTML = '<div class="hbm-empty"><p>No HTML blocks yet.</p><button type="button" class="hbm-btn hbm-btn-primary" onclick="document.getElementById(\'hbm-new-btn\').click()"><i class="fa-solid fa-plus"></i> Create your first block</button></div>';
      syncBulkUI();
      return;
    }
    $grid.innerHTML = '';
    blocks.forEach(function(b){
      var card = document.createElement('div');
      card.className = 'hbm-card';
      card.dataset.slug = b.slug;
      if (selected[b.slug]) card.classList.add('hbm-card-selected');
      var tagsHtml = (b.tags && b.tags.length)
        ? '<div class="hbm-card-tags">' + b.tags.map(function(t){ return '<span class="hbm-tag">' + escapeHtml(t) + '</span>'; }).join('') + '</div>'
        : '';
      var shortcode = '[[html-block:' + b.slug + ']]';
      card.innerHTML =
        '<label class="hbm-card-check" title="Select for bulk action">' +
          '<input type="checkbox" class="hbm-card-checkbox"' + (selected[b.slug] ? ' checked' : '') + '>' +
        '</label>' +
        '<div class="hbm-card-head">' +
          '<div class="hbm-card-title">' + escapeHtml(b.title || b.slug) + '</div>' +
          '<div class="hbm-card-slug">/' + escapeHtml(b.slug) + '</div>' +
        '</div>' +
        tagsHtml +
        '<div class="hbm-card-shortcode" title="Click to copy"><code>' + escapeHtml(shortcode) + '</code></div>';
      // Checkbox toggles selection without opening the editor.
      card.querySelector('.hbm-card-checkbox').addEventListener('change', function(e){
        e.stopPropagation();
        toggleSelect(b.slug, this.checked);
        card.classList.toggle('hbm-card-selected', this.checked);
      });
      card.querySelector('.hbm-card-check').addEventListener('click', function(e){
        e.stopPropagation(); // clicking the checkbox label must not open the editor
      });
      card.addEventListener('click', function(e){
        if (e.target.closest('.hbm-card-check')) return;
        if (e.target.closest('.hbm-card-shortcode')) {
          navigator.clipboard.writeText(shortcode).then(function(){ toast('Shortcode copied'); });
          return;
        }
        openEditor(b.slug);
      });
      $grid.appendChild(card);
    });
    syncBulkUI();
  }

  /* ---------- BULK SELECTION ---------- */
  function toggleSelect(slug, on) {
    if (on) selected[slug] = true; else delete selected[slug];
    syncBulkUI();
  }

  function selectedSlugs() { return Object.keys(selected); }

  function syncBulkUI() {
    var n = selectedSlugs().length;
    var $count = document.getElementById('hbm-bulk-count');
    if ($count) $count.textContent = n + ' selected';
    var disabled = n === 0;
    ['hbm-bulk-delete', 'hbm-bulk-tag-add', 'hbm-bulk-tag-remove'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) el.disabled = disabled;
    });
    var $all = document.getElementById('hbm-select-all');
    if ($all) {
      var total = allSlugs.length;
      $all.checked = total > 0 && n === total;
      $all.indeterminate = n > 0 && n < total;
    }
  }

  function bulkDelete() {
    var slugs = selectedSlugs();
    if (!slugs.length) return;
    if (!confirm('Delete ' + slugs.length + ' block' + (slugs.length === 1 ? '' : 's') + '? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action', 'bulk_delete');
    fd.append('csrf_token', CSRF);
    slugs.forEach(function(s){ fd.append('slugs[]', s); });
    hbFetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success && !(r.deleted_count > 0)) { toast(r.message || 'Bulk delete failed', true); return; }
        toast(r.message || (r.deleted_count + ' deleted'), !r.success);
        selected = Object.create(null);
        loadList();
      })
      .catch(function(){ toast('Bulk delete request failed', true); });
  }

  function bulkTag(mode) {
    var slugs = selectedSlugs();
    if (!slugs.length) return;
    var tags = (document.getElementById('hbm-bulk-tags').value || '').trim();
    if (!tags) { toast('Enter one or more tags first', true); return; }
    var fd = new FormData();
    fd.append('action', 'bulk_tag');
    fd.append('csrf_token', CSRF);
    fd.append('mode', mode);
    fd.append('tags', tags);
    slugs.forEach(function(s){ fd.append('slugs[]', s); });
    hbFetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success && !(r.updated_count > 0)) { toast(r.message || 'Bulk tag failed', true); return; }
        toast(r.message || (r.updated_count + ' updated'), !r.success);
        loadList();
      })
      .catch(function(){ toast('Bulk tag request failed', true); });
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(c){
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  /* ---------- EDITOR ---------- */
  function openEditor(slug) {
    currentSlug = slug || null;
    document.getElementById('hbm-title').value = '';
    document.getElementById('hbm-slug').value  = '';
    document.getElementById('hbm-tags').value  = '';
    document.getElementById('hbm-html').value  = '';
    document.getElementById('hbm-css').value   = '';
    document.getElementById('hbm-js').value    = '';
    document.getElementById('hbm-slug-badge').textContent = 'slug: ' + (slug || '(new)');
    document.getElementById('hbm-shortcode').textContent = slug ? ('[[html-block:' + slug + ']]') : '[[html-block:…]]';
    document.getElementById('hbm-delete').style.display = slug ? '' : 'none';

    if (slug) {
      hbFetch(API + '?action=get&slug=' + encodeURIComponent(slug))
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (!r.success) { toast(r.message || 'Load failed', true); return; }
          var b = r.block || {};
          document.getElementById('hbm-title').value = b.title || '';
          document.getElementById('hbm-slug').value  = b.slug || '';
          document.getElementById('hbm-tags').value  = (b.tags || []).join(', ');
          document.getElementById('hbm-html').value  = b.html || '';
          document.getElementById('hbm-css').value   = b.css  || '';
          document.getElementById('hbm-js').value    = b.js   || '';
        });
    }
    showTab('html');
    $modal.classList.add('open');
  }

  function closeEditor() { $modal.classList.remove('open'); currentSlug = null; }

  function showTab(name) {
    document.querySelectorAll('.hbm-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab === name); });
    document.querySelectorAll('.hbm-pane').forEach(function(p){ p.classList.toggle('active', p.dataset.pane === name); });
    if (name === 'preview') renderPreview();
  }

  // Live-render the block's current (unsaved) HTML/CSS/JS with the site's real styles.
  // Grow the preview iframe to fit its rendered content so nothing scrolls
  // inside the frame. Re-measures a couple times to catch late layout (fonts/images).
  function hbmFitPreview(frame) {
    try {
      var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
      if (!doc || !doc.body) return;
      var h = Math.max(
        doc.body.scrollHeight, doc.documentElement.scrollHeight,
        doc.body.offsetHeight, doc.documentElement.offsetHeight
      );
      if (h > 0) frame.style.height = (h + 4) + 'px';
    } catch (_) {}
  }

  function renderPreview() {
    var frame = document.getElementById('hbm-preview');
    if (!frame) return;
    frame.onload = function () {
      hbmFitPreview(frame);
      setTimeout(function(){ hbmFitPreview(frame); }, 200);   // fonts/images settle
    };
    var fd = new FormData();
    fd.append('html', document.getElementById('hbm-html').value || '');
    fd.append('css',  document.getElementById('hbm-css').value  || '');
    fd.append('js',   document.getElementById('hbm-js').value   || '');
    fetch('/admin/modules/HTMLBlocks/preview.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.text(); })
      .then(function(html){ frame.srcdoc = html; })
      .catch(function(){ frame.srcdoc = '<p style="color:#f0a500;font:13px system-ui;padding:16px">Preview failed to load.</p>'; });
  }

  function saveBlock() {
    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('csrf_token', CSRF);
    fd.append('title', document.getElementById('hbm-title').value || '');
    fd.append('slug',  document.getElementById('hbm-slug').value  || '');
    fd.append('tags',  document.getElementById('hbm-tags').value  || '');
    fd.append('html',  document.getElementById('hbm-html').value  || '');
    fd.append('css',   document.getElementById('hbm-css').value   || '');
    fd.append('js',    document.getElementById('hbm-js').value    || '');
    hbFetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success) { toast(r.message || 'Save failed', true); return; }
        toast('Saved: ' + r.slug);
        currentSlug = r.slug;
        document.getElementById('hbm-shortcode').textContent = '[[html-block:' + r.slug + ']]';
        document.getElementById('hbm-slug-badge').textContent = 'slug: ' + r.slug;
        loadList();
      })
      .catch(function(){ toast('Save request failed', true); });
  }

  function deleteBlock() {
    if (!currentSlug) return;
    if (!confirm('Delete block "' + currentSlug + '"? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('slug', currentSlug);
    hbFetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success) { toast(r.message || 'Delete failed', true); return; }
        toast('Deleted');
        closeEditor();
        loadList();
      });
  }

  /* ---------- WIRE ---------- */
  document.getElementById('hbm-new-btn').addEventListener('click', function(){ openEditor(null); });

  // Bulk toolbar
  (function(){
    var $all = document.getElementById('hbm-select-all');
    if ($all) $all.addEventListener('change', function(){
      if (this.checked) { allSlugs.forEach(function(s){ selected[s] = true; }); }
      else { selected = Object.create(null); }
      // Reflect on rendered checkboxes + card highlight without a full reload.
      document.querySelectorAll('.hbm-card').forEach(function(card){
        var on = !!selected[card.dataset.slug];
        var cb = card.querySelector('.hbm-card-checkbox');
        if (cb) cb.checked = on;
        card.classList.toggle('hbm-card-selected', on);
      });
      syncBulkUI();
    });
    var $del = document.getElementById('hbm-bulk-delete');
    if ($del) $del.addEventListener('click', bulkDelete);
    var $tagAdd = document.getElementById('hbm-bulk-tag-add');
    if ($tagAdd) $tagAdd.addEventListener('click', function(){ bulkTag('add'); });
    var $tagRem = document.getElementById('hbm-bulk-tag-remove');
    if ($tagRem) $tagRem.addEventListener('click', function(){ bulkTag('remove'); });
  })();
  document.getElementById('hbm-cancel').addEventListener('click', closeEditor);
  document.getElementById('hbm-save').addEventListener('click', saveBlock);
  document.getElementById('hbm-delete').addEventListener('click', deleteBlock);
  document.querySelectorAll('.hbm-tab').forEach(function(b){
    b.addEventListener('click', function(){ showTab(b.dataset.tab); });
  });
  var hbmPrevRefresh = document.getElementById('hbm-preview-refresh');
  if (hbmPrevRefresh) hbmPrevRefresh.addEventListener('click', renderPreview);
  document.getElementById('hbm-shortcode').addEventListener('click', function(){
    var txt = this.textContent;
    if (!txt || txt.indexOf('…') !== -1) return;
    navigator.clipboard.writeText(txt).then(function(){ toast('Shortcode copied'); });
  });

  // Autofill slug from title when slug is empty
  document.getElementById('hbm-title').addEventListener('input', function(){
    var slugField = document.getElementById('hbm-slug');
    if (!currentSlug && !slugField.value) {
      slugField.placeholder = 'auto: ' + (this.value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    }
  });

  // Escape closes
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && $modal.classList.contains('open')) closeEditor();
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && $modal.classList.contains('open')) {
      e.preventDefault();
      saveBlock();
    }
  });

  loadList();
})();
