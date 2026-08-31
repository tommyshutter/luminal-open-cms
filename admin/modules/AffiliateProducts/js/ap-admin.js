/**
 * AffiliateProducts Admin — AP namespace
 * @file admin/modules/AffiliateProducts/js/ap-admin.js
 */
(function(){
  'use strict';

  var API = window.AP_BOOT.apiUrl;
  var AI_API = window.AP_BOOT.aiApiUrl;
  var products = [];
  var categories = [];
  var currentCategory = '';
  var aiSuggestions = [];

  /* ── init ──────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function(){
    initTabs();
    loadProductsTab();
    loadConfig();
    loadAiProviders();

    // Reload Products tab whenever it's clicked (counts may have changed)
    document.querySelectorAll('.ap-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        if (tab.dataset.tab === 'products') loadProductsTab();
      });
    });

    // Show-ignored toggle
    var showIg = document.getElementById('ap-prod-show-ignored');
    if (showIg) showIg.addEventListener('change', function(){
      showIgnored = showIg.checked;
      renderGrid();
    });
    // Show-unavailable toggle
    var showUn = document.getElementById('ap-prod-show-unavail');
    if (showUn) showUn.addEventListener('change', function(){
      showUnavail = showUn.checked;
      renderGrid();
    });

    // Sort dropdown
    var sortSel = document.getElementById('ap-prod-sort');
    if (sortSel) sortSel.addEventListener('change', function(){
      sortMode = sortSel.value;
      renderGrid();
    });

    // Search box (debounced)
    var searchEl = document.getElementById('ap-prod-search');
    if (searchEl) {
      var t;
      searchEl.addEventListener('input', function(){
        clearTimeout(t);
        t = setTimeout(function(){
          searchQuery = (searchEl.value || '').trim();
          renderGrid();
        }, 180);
      });
    }

    // Relocate modals to document.body (escape stacking context)
    ['ap-product-modal','ap-ai-modal','ap-prompt-modal','ap-veto-modal','ap-quick-prompt-modal'].forEach(function(id){
      var el = document.getElementById(id);
      if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    // Wire prompt-editor recurrence toggle
    var recur = document.getElementById('ap-pr-recur');
    if (recur) recur.addEventListener('change', toggleRecurFields);
  });

  /* ── tabs ──────────────────────────────────────────────────────── */
  function initTabs(){
    document.querySelectorAll('.ap-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        document.querySelectorAll('.ap-tab').forEach(function(t){ t.classList.remove('active'); });
        document.querySelectorAll('.ap-tab-content').forEach(function(c){ c.classList.remove('active'); });
        tab.classList.add('active');
        var target = document.getElementById('ap-tab-' + tab.dataset.tab);
        if (target) target.classList.add('active');
      });
    });
  }

  /* ── Products tab — store-driven loader ────────────────────────── */
  var currentSite = '';
  var showIgnored  = false;
  var showUnavail  = false;
  var sortMode     = 'default';
  var searchQuery  = '';

  function loadProductsTab(){
    // Populate store pulldown from registry
    Promise.all([
      fetch(API + '?action=list_stores').then(function(r){ return r.json(); })
    ]).then(function(res){
      var registry = (res[0].ok ? res[0].data.stores : []) || [];
      var attached = registry.filter(function(s){ return s.site && s.enabled; });
      var sel = document.getElementById('ap-prod-store-select');
      if (!sel) return;
      if (!attached.length) {
        sel.innerHTML = '<option value="">— no stores attached to sites yet —</option>';
        document.getElementById('ap-product-grid').innerHTML = '<div class="ap-empty"><i class="fa-solid fa-link-slash"></i>Attach a store to a site on the Dashboard to start managing its products.</div>';
        return;
      }
      // Preserve previous selection if still valid
      var prev = currentSite;
      sel.innerHTML = attached.map(function(s){
        var patSuffix = s.page_pattern ? ('  ' + s.page_pattern) : '';
        var label = s.tag + ' → ' + s.site + patSuffix + '  (' + (s.product_count || 0) + ')';
        return '<option value="' + esc(s.site) + '" data-tag="' + esc(s.tag) + '" data-pattern="' + esc(s.page_pattern || '') + '">' + esc(label) + '</option>';
      }).join('');
      if (prev && [].slice.call(sel.options).some(function(o){ return o.value === prev; })) {
        sel.value = prev;
      } else {
        sel.value = attached[0].site;
      }
      sel.onchange = function(){ loadSiteProductsIntoTab(sel.value); };
      loadSiteProductsIntoTab(sel.value);
    });
  }

  var siteClicks30d = 0;
  var currentStoreTag = '';   // the registry tag currently selected in the Products tab dropdown

  function loadSiteProductsIntoTab(site){
    currentSite = site;
    if (!site) return;
    var meta = document.getElementById('ap-prod-store-meta');
    if (meta) meta.textContent = 'Loading…';

    // Capture the selected store tag from the dropdown (used for prompt editor)
    var sel = document.getElementById('ap-prod-store-select');
    var opt = sel && sel.selectedOptions[0];
    currentStoreTag = opt ? (opt.dataset.tag || '') : '';

    // Update the Visit link to point at this site (+ pattern prefix if any)
    updateVisitLink(site, opt ? (opt.dataset.pattern || '') : '');

    fetch(API + '?action=list_site_products&site=' + encodeURIComponent(site))
      .then(function(r){ return r.json(); })
      .then(function(j){
        var grid = document.getElementById('ap-product-grid');
        if (!j.ok) { grid.innerHTML = '<div class="ap-empty">' + esc(j.message || 'Failed to load') + '</div>'; return; }
        products = j.data.products || [];
        categories = j.data.categories || [];
        siteClicks30d = j.data.clicks_30d || 0;
        renderCategoryBar();
        renderGrid();
        loadInlinePipelinePanel(currentStoreTag);
        refreshVetoCount();
      });
  }

  function updateVisitLink(site, pattern){
    var link = document.getElementById('ap-prod-visit-link');
    var txt  = link && link.querySelector('.ap-visit-text');
    if (!link) return;
    if (!site) { link.style.display = 'none'; return; }
    // Strip wildcard from pattern (e.g. /articles/* → /articles/), keep concrete prefix
    var path = (pattern || '').replace(/\*+$/, '');
    var url = 'https://' + site + (path || '/');
    link.href = url;
    if (txt) txt.textContent = 'Visit ' + site + (path || '');
    link.style.display = '';
  }

  function loadInlinePipelinePanel(tag){
    var panel = document.getElementById('ap-pipeline-panel');
    if (!panel) return;
    if (!tag) { panel.style.display = 'none'; return; }
    panel.style.display = '';

    fetch(API + '?action=prompt_load&tag=' + encodeURIComponent(tag))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) {
          document.getElementById('ap-pipeline-task').textContent = '(unable to load)';
          document.getElementById('ap-pipeline-prompt').textContent = j.message || '';
          return;
        }
        var t = j.data.task;
        var taskEl = document.getElementById('ap-pipeline-task');
        var nextEl = document.getElementById('ap-pipeline-next');
        var promptEl = document.getElementById('ap-pipeline-prompt');
        if (!t) {
          taskEl.textContent = '(no task linked yet)';
          nextEl.textContent = '';
          promptEl.innerHTML = 'No prompt authored yet — click <strong>Edit</strong> to author one for this store.';
          return;
        }
        taskEl.textContent = t.id;
        var next = t.next_run ? new Date(t.next_run).toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
        nextEl.textContent = next ? ('next run: ' + next) : '';
        var theme = (t.config && t.config.theme) || '';
        promptEl.textContent = theme || '(no prompt body — click Edit to add one)';
      });
  }

  function openPromptForCurrentStore(){
    if (!currentStoreTag) { alert('Pick a store first'); return; }
    openPromptEditor(currentStoreTag);
  }

  function runCurrentStoreLive(){
    if (!currentStoreTag) { showToast('Pick a store first'); return; }
    if (!confirm('Run pipeline NOW and write to the live products.json on ' + currentSite + '?')) return;
    fetch(API + '?action=prompt_load&tag=' + encodeURIComponent(currentStoreTag))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Load failed'); return; }
        if (!j.data.task) { showToast('No prompt yet — click Edit Prompt first'); return; }
        var fd = new FormData();
        fd.append('action', 'prompt_run');
        fd.append('task_id', j.data.task.id);
        fd.append('mode', 'normal');
        fetch(API, { method:'POST', body:fd })
          .then(function(r){ return r.json(); })
          .then(function(rj){ showToast(rj.ok ? '⚡ ' + rj.message : ('Run failed: ' + (rj.message || 'unknown'))); });
      });
  }

  /* Sandbox preview flow ─────────────────────────────────────────── */
  var sandboxRunId = null;
  var sandboxPollTimer = null;

  function runCurrentStoreSandbox(){
    if (!currentStoreTag) { showToast('Pick a store first'); return; }
    var fd = new FormData();
    fd.append('action', 'sandbox_start');
    fd.append('tag', currentStoreTag);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Could not start sandbox'); return; }
        sandboxRunId = j.data.run_id;
        showSandboxRunning();
        showToast('Sandbox started — running prompt now…');
        sandboxPollTimer = setInterval(pollSandbox, 3000);
      });
  }

  function showSandboxRunning(){
    var grid = document.getElementById('ap-product-grid');
    var state = document.getElementById('ap-sandbox-state');
    if (grid) grid.style.display = 'none';
    if (state) {
      state.style.display = '';
      state.innerHTML = ''
        + '<div class="ap-sbx-running">'
        + '  <div class="ap-sbx-spinner"></div>'
        + '  <h3>Running prompt — generating products now…</h3>'
        + '  <p>This usually takes 30–60 seconds. The pipeline calls the AI, validates Amazon images for every product, and writes to a sandbox file. Live products are <strong>not touched</strong>.</p>'
        + '  <p class="ap-sbx-runid">run id: <code>' + esc(sandboxRunId || '?') + '</code></p>'
        + '  <button class="ap-btn" onclick="AP.cancelSandbox()">Cancel & return to live</button>'
        + '</div>';
    }
  }

  function pollSandbox(){
    if (!sandboxRunId) return;
    fetch(API + '?action=sandbox_status&run_id=' + encodeURIComponent(sandboxRunId))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        if (j.data.status === 'done') {
          clearInterval(sandboxPollTimer); sandboxPollTimer = null;
          renderSandboxResults(j.data.data);
        }
      });
  }

  function renderSandboxResults(data){
    var state = document.getElementById('ap-sandbox-state');
    if (!state) return;
    var products = (data && data.products) || [];

    var header = ''
      + '<div class="ap-sbx-header">'
      +   '<div>'
      +     '<h3 style="margin:0">Sandbox — ' + products.length + ' products generated</h3>'
      +     '<p style="margin:4px 0 0;color:#888;font-size:0.84rem">Curate below: <strong>Promote</strong> sends to live, <strong>Veto</strong> permanently blocks, <strong>Discard</strong> drops just from this sandbox.</p>'
      +   '</div>'
      +   '<div style="display:flex;gap:8px;flex-wrap:wrap">'
      +     '<button class="ap-btn" onclick="AP.exitSandbox()"><i class="fa-solid fa-arrow-left"></i> Back to live</button>'
      +     (products.length ? '<button class="ap-btn ap-btn-primary" onclick="AP.promoteAllSandbox()" title="Promote every product"><i class="fa-solid fa-check-double"></i> Promote All</button>' : '')
      +   '</div>'
      + '</div>';

    if (!products.length) {
      state.innerHTML = header + '<div class="ap-empty"><i class="fa-solid fa-box-open"></i>Pipeline produced no products. Try tweaking the prompt and run again.</div>';
      return;
    }

    var cards = products.map(function(p){
      var img = p.image
        ? '<a class="ap-card-image-wrap" href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow sponsored"><img class="ap-card-image" src="' + esc(p.image) + '" alt="" loading="lazy"></a>'
        : '<div class="ap-card-image-placeholder"><i class="fa-solid fa-image"></i></div>';
      return '<div class="ap-product-card" data-pid="' + esc(p.id) + '">'
        + img
        + '<div class="ap-card-body">'
        + '  <div class="ap-card-title">' + (p.url ? '<a class="ap-card-title-link" href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow sponsored">' + esc(p.title || '') + '</a>' : esc(p.title || '')) + '</div>'
        + '  ' + (p.description ? '<div class="ap-card-desc">' + esc(p.description) + '</div>' : '')
        + '  <div class="ap-card-meta">'
        + '    ' + (p.price ? '<span class="ap-card-price">' + esc(p.price) + '</span>' : '<span></span>')
        + '    ' + (p.rating ? '<span class="ap-card-rating">&#9733; ' + esc(p.rating) + '</span>' : '')
        + '  </div>'
        + '</div>'
        + '<div class="ap-card-footer">'
        + '  <span class="ap-source-badge ap-source-amazon">amazon</span>'
        + '  <div class="ap-card-actions">'
        + '    <button class="ap-card-action ap-card-promote" onclick="AP.promoteSandbox(\'' + esc(p.id) + '\')" title="Add to live products"><i class="fa-solid fa-check"></i></button>'
        + '    <button class="ap-card-action ap-card-veto" onclick="AP.vetoSandbox(\'' + esc(p.id) + '\')" title="Veto: permanently block"><i class="fa-solid fa-ban"></i></button>'
        + '    <button class="ap-card-action delete" onclick="AP.discardSandbox(\'' + esc(p.id) + '\')" title="Discard from this sandbox"><i class="fa-solid fa-xmark"></i></button>'
        + '  </div>'
        + '</div>'
        + '</div>';
    }).join('');

    state.innerHTML = header + '<div class="ap-grid">' + cards + '</div>';
  }

  function promoteSandbox(pid){
    var fd = new FormData();
    fd.append('action', 'sandbox_promote');
    fd.append('run_id', sandboxRunId);
    fd.append('product_id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Promote failed'); return; }
        showToast('✓ Promoted to live');
        // Refresh sandbox view
        fetch(API + '?action=sandbox_status&run_id=' + encodeURIComponent(sandboxRunId))
          .then(function(r){ return r.json(); })
          .then(function(rj){ if (rj.ok) renderSandboxResults(rj.data.data); });
      });
  }

  function discardSandbox(pid){
    var fd = new FormData();
    fd.append('action', 'sandbox_discard');
    fd.append('run_id', sandboxRunId);
    fd.append('product_id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Discard failed'); return; }
        showToast('Discarded');
        var card = document.querySelector('.ap-product-card[data-pid="' + pid + '"]');
        if (card) card.remove();
      });
  }

  function vetoSandbox(pid){
    var reason = prompt('Veto this sandbox suggestion?\n\nIt will be added to the permanent veto list — pipeline never suggests it again.\n\nOptional reason:');
    if (reason === null) return;
    var fd = new FormData();
    fd.append('action', 'sandbox_veto');
    fd.append('run_id', sandboxRunId);
    fd.append('product_id', pid);
    fd.append('reason', reason || '');
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Veto failed'); return; }
        showToast('Vetoed (' + j.data.veto_count + ' total)');
        var card = document.querySelector('.ap-product-card[data-pid="' + pid + '"]');
        if (card) card.remove();
        refreshVetoCount();
      });
  }

  function promoteAllSandbox(){
    if (!confirm('Promote ALL remaining sandbox products to live?')) return;
    var cards = document.querySelectorAll('.ap-sandbox-state .ap-product-card');
    var ids = Array.from(cards).map(function(c){ return c.dataset.pid; });
    var done = 0;
    function next(){
      if (!ids.length) {
        showToast('Promoted ' + done + ' products');
        loadSiteProductsIntoTab(currentSite);
        exitSandbox();
        return;
      }
      var pid = ids.shift();
      var fd = new FormData();
      fd.append('action', 'sandbox_promote');
      fd.append('run_id', sandboxRunId);
      fd.append('product_id', pid);
      fetch(API, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(j){ if (j.ok) done++; next(); });
    }
    next();
  }

  function cancelSandbox(){
    if (sandboxPollTimer) { clearInterval(sandboxPollTimer); sandboxPollTimer = null; }
    sandboxRunId = null;
    exitSandbox();
    showToast('Sandbox cancelled');
  }

  function exitSandbox(){
    var state = document.getElementById('ap-sandbox-state');
    var grid = document.getElementById('ap-product-grid');
    if (state) { state.style.display = 'none'; state.innerHTML = ''; }
    if (grid) grid.style.display = '';
    if (sandboxPollTimer) { clearInterval(sandboxPollTimer); sandboxPollTimer = null; }
    sandboxRunId = null;
  }

  /* Quick prompt editor (theme only) ─────────────────────────────── */
  function openQuickPrompt(){
    if (!currentStoreTag) { showToast('Pick a store first'); return; }
    var modal = document.getElementById('ap-quick-prompt-modal');
    document.getElementById('ap-qp-tag').textContent = currentStoreTag;
    document.getElementById('ap-qp-theme').value = '';
    modal.classList.add('show');

    fetch(API + '?action=prompt_load&tag=' + encodeURIComponent(currentStoreTag))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        var t = j.data.task;
        document.getElementById('ap-qp-theme').value = (t && t.config && t.config.theme) || '';
        setTimeout(function(){ document.getElementById('ap-qp-theme').focus(); }, 50);
      });
  }
  function closeQuickPrompt(){
    document.getElementById('ap-quick-prompt-modal').classList.remove('show');
  }
  function saveQuickPrompt(){
    var fd = new FormData();
    fd.append('action', 'prompt_quick_save');
    fd.append('tag', currentStoreTag);
    fd.append('theme', document.getElementById('ap-qp-theme').value);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Save failed'); return; }
        showToast('✓ Prompt saved');
        closeQuickPrompt();
        loadInlinePipelinePanel(currentStoreTag);   // refresh display
      });
  }

  function renderCategoryBar(){
    var bar = document.querySelector('#ap-tab-products .ap-category-bar');
    if (!bar) return;
    var html = '<button class="ap-cat-pill' + (currentCategory === '' ? ' active' : '') + '" data-category="">All</button>';
    categories.forEach(function(cat){
      html += '<button class="ap-cat-pill' + (currentCategory === cat ? ' active' : '') + '" data-category="' + esc(cat) + '">' + esc(cat) + '</button>';
    });
    bar.innerHTML = html;
    bar.querySelectorAll('.ap-cat-pill').forEach(function(pill){
      pill.addEventListener('click', function(){
        currentCategory = pill.dataset.category;
        bar.querySelectorAll('.ap-cat-pill').forEach(function(p){ p.classList.remove('active'); });
        pill.classList.add('active');
        renderGrid();
      });
    });
    var dl = document.getElementById('ap-cat-datalist');
    if (dl) {
      dl.innerHTML = '';
      categories.forEach(function(cat){
        var opt = document.createElement('option');
        opt.value = cat;
        dl.appendChild(opt);
      });
    }
  }

  function priceToNumber(p){
    if (!p) return null;
    var s = String(p).replace(/[^\d.]/g, '');
    var n = parseFloat(s);
    return isNaN(n) ? null : n;
  }

  function applyProductFiltersAndSort(){
    var list = products.slice();

    // Category
    if (currentCategory) list = list.filter(function(p){ return p.category === currentCategory; });

    // Ignored
    if (!showIgnored) list = list.filter(function(p){ return !p.ignored; });

    // Unavailable (default off — same idea as ignored, since renderer will skip these)
    if (!showUnavail) list = list.filter(function(p){ return !p.unavailable; });

    // Mandatory: a product MUST have an image. No exceptions, no toggle.
    list = list.filter(function(p){ return p.image && String(p.image).trim() !== ''; });

    // Search
    if (searchQuery) {
      var q = searchQuery.toLowerCase();
      list = list.filter(function(p){
        return (p.title||'').toLowerCase().indexOf(q) !== -1
          || (p.description||'').toLowerCase().indexOf(q) !== -1
          || (p.asin||'').toLowerCase().indexOf(q) !== -1;
      });
    }

    // Sort
    switch (sortMode) {
      case 'price-asc':
        list.sort(function(a,b){ var pa=priceToNumber(a.price), pb=priceToNumber(b.price);
          if (pa===null && pb===null) return 0;
          if (pa===null) return 1; if (pb===null) return -1;
          return pa - pb;
        }); break;
      case 'price-desc':
        list.sort(function(a,b){ var pa=priceToNumber(a.price), pb=priceToNumber(b.price);
          if (pa===null && pb===null) return 0;
          if (pa===null) return 1; if (pb===null) return -1;
          return pb - pa;
        }); break;
      case 'title-asc':
        list.sort(function(a,b){ return (a.title||'').localeCompare(b.title||''); }); break;
      case 'title-desc':
        list.sort(function(a,b){ return (b.title||'').localeCompare(a.title||''); }); break;
      case 'newest':
        list.sort(function(a,b){ return String(b.added_at||'').localeCompare(String(a.added_at||'')); }); break;
      case 'rating-desc':
        list.sort(function(a,b){ return (parseFloat(b.rating)||0) - (parseFloat(a.rating)||0); }); break;
      default:
        list.sort(function(a,b){ return (a.sort_order ?? 999) - (b.sort_order ?? 999); });
    }
    return list;
  }

  function renderGrid(){
    var grid = document.getElementById('ap-product-grid');
    if (!grid) return;
    var filtered = applyProductFiltersAndSort();

    // Update count meta — "X shown / Y total · Z clicks (30d)"
    var meta = document.getElementById('ap-prod-store-meta');
    if (meta) {
      var totals = products.length;
      var parts = [];
      parts.push(filtered.length + (filtered.length !== totals ? ' shown / ' + totals : '') + ' product' + (totals === 1 ? '' : 's'));
      parts.push((siteClicks30d || 0) + ' clicks (30d)');
      meta.textContent = parts.join(' · ');
    }

    if (!filtered.length) {
      grid.innerHTML = '<div class="ap-empty"><i class="fa-solid fa-box-open"></i>No products match your filters.</div>';
      return;
    }

    var html = '';
    filtered.forEach(function(p){
      var imgSrc = p.image
        ? (String(p.image).indexOf('http') === 0 ? p.image : '/' + String(p.image).replace(/^\//, ''))
        : '';
      var imgInner = imgSrc
        ? '<img class="ap-card-image" src="' + esc(imgSrc) + '" alt="" loading="lazy" onerror="this.parentElement.innerHTML=\'<div class=ap-card-image-placeholder><i class=&quot;fa-solid fa-image&quot;></i></div>\'">'
        : '<div class="ap-card-image-placeholder"><i class="fa-solid fa-image"></i></div>';
      var imgHtml = p.url
        ? '<a class="ap-card-image-wrap" href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow sponsored" title="Open on Amazon">' + imgInner + '</a>'
        : '<div class="ap-card-image-wrap">' + imgInner + '</div>';
      var ratingHtml = p.rating ? '<span class="ap-card-rating">&#9733; ' + esc(p.rating) + '</span>' : '';
      var aiHtml = p.ai_generated ? '<span class="ap-ai-badge">AI</span>' : '';
      var ignoredCls = p.ignored ? ' ap-card-ignored' : '';
      var disabledCls = (p.enabled === false) ? ' disabled' : '';
      var ignoreBtn = p.ignored
        ? '<button class="ap-card-action ap-card-unignore" onclick="AP.toggleIgnore(\'' + esc(p.id) + '\')" title="Re-enable on site"><i class="fa-solid fa-eye"></i></button>'
        : '<button class="ap-card-action" onclick="AP.toggleIgnore(\'' + esc(p.id) + '\')" title="Hide from site (keep off public pages)"><i class="fa-solid fa-eye-slash"></i></button>';
      var unavailBtn = p.unavailable
        ? '<button class="ap-card-action ap-card-unignore" onclick="AP.toggleUnavailable(\'' + esc(p.id) + '\')" title="Mark available again"><i class="fa-solid fa-circle-check"></i></button>'
        : '<button class="ap-card-action" onclick="AP.toggleUnavailable(\'' + esc(p.id) + '\')" title="Mark as unavailable on Amazon"><i class="fa-solid fa-circle-exclamation"></i></button>';
      var checkBtn = '<button class="ap-card-action" onclick="AP.checkProduct(\'' + esc(p.id) + '\')" title="Check Amazon availability now"><i class="fa-solid fa-rotate"></i></button>';
      var vetoBtn = '<button class="ap-card-action ap-card-veto" onclick="AP.vetoProduct(\'' + esc(p.id) + '\')" title="Veto: permanently exclude from this site (will NOT come back, even on next refresh)"><i class="fa-solid fa-ban"></i></button>';
      var viewBtn = p.url ? '<a class="ap-card-action" href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow sponsored" title="Open on Amazon"><i class="fa-solid fa-up-right-from-square"></i></a>' : '';
      var embedBtn = '<button class="ap-card-action" onclick="AP.copyEmbedCode(\'' + esc(p.id) + '\',\'' + esc(p.category||'') + '\')" title="Copy embed shortcode"><i class="fa-solid fa-code"></i></button>';

      // Make title clickable too
      var titleHtml = p.url
        ? '<a class="ap-card-title-link" href="' + esc(p.url) + '" target="_blank" rel="noopener nofollow sponsored">' + esc(p.title || '') + '</a>'
        : esc(p.title || '');
      var unavailCls = p.unavailable ? ' ap-card-unavailable' : '';

      html += '<div class="ap-product-card' + disabledCls + ignoredCls + unavailCls + '" data-id="' + esc(p.id) + '">'
        + (p.unavailable ? '<div class="ap-card-unavail-badge"><i class="fa-solid fa-triangle-exclamation"></i> Currently unavailable</div>' : '')
        + (p.ignored ? '<div class="ap-card-ignored-badge"><i class="fa-solid fa-ban"></i> Hidden from site</div>' : '')
        + imgHtml
        + '<div class="ap-card-body">'
        + '<div class="ap-card-title">' + titleHtml + '</div>'
        + (p.description ? '<div class="ap-card-desc">' + esc(p.description) + '</div>' : '')
        + '<div class="ap-card-meta">'
        + (p.price ? '<span class="ap-card-price">' + esc(p.price) + '</span>' : '<span></span>')
        + ratingHtml
        + '</div>'
        + '</div>'
        + '<div class="ap-card-footer">'
        + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
        + '<span class="ap-source-badge ap-source-' + esc(p.source || 'generic') + '">' + esc(p.source || 'generic') + '</span>'
        + aiHtml
        + (p.category ? '<span class="ap-cat-tag">' + esc(p.category) + '</span>' : '')
        + '</div>'
        + '<div class="ap-card-actions">'
        + viewBtn
        + embedBtn
        + checkBtn
        + unavailBtn
        + ignoreBtn
        + vetoBtn
        + '<button class="ap-card-action delete" onclick="AP.deleteSiteProduct(\'' + esc(p.id) + '\')" title="Delete (without veto — pipeline may re-suggest)"><i class="fa-solid fa-trash"></i></button>'
        + '</div>'
        + '</div>'
        + '</div>';
    });
    grid.innerHTML = html;
  }

  function toggleIgnore(pid){
    if (!currentSite) return;
    var p = products.find(function(x){ return x.id === pid; }) || {};
    var fd = new FormData();
    fd.append('action', 'toggle_product_ignore');
    fd.append('site', currentSite);
    fd.append('id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Failed'); return; }
        showToast(p.ignored ? 'Re-enabled' : 'Hidden from site');
        loadSiteProductsIntoTab(currentSite);
      });
  }

  function toggleUnavailable(pid){
    if (!currentSite) return;
    var p = products.find(function(x){ return x.id === pid; }) || {};
    var fd = new FormData();
    fd.append('action', 'toggle_product_unavailable');
    fd.append('site', currentSite);
    fd.append('id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Failed'); return; }
        showToast(p.unavailable ? 'Marked available' : 'Marked unavailable');
        loadSiteProductsIntoTab(currentSite);
      });
  }

  function checkProduct(pid){
    if (!currentSite) return;
    showToast('Checking Amazon…');
    var fd = new FormData();
    fd.append('action', 'check_product_availability');
    fd.append('site', currentSite);
    fd.append('id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { showToast(j.message || 'Check failed'); return; }
        showToast(j.message || 'Checked');
        loadSiteProductsIntoTab(currentSite);
      });
  }

  function checkSiteAvailability(){
    if (!currentSite) { showToast('Pick a store first'); return; }
    if (!confirm('Check Amazon availability for all products on ' + currentSite + '? This may take a minute or two and will hit Amazon once per product.')) return;
    showToast('Checking availability… this takes a minute');
    var fd = new FormData();
    fd.append('action', 'check_site_availability');
    fd.append('site', currentSite);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        showToast(j.ok ? j.message : ('Failed: ' + (j.message || 'unknown')));
        if (j.ok) loadSiteProductsIntoTab(currentSite);
      });
  }

  function copyEmbedCode(pid, category){
    // Show a small picker so user can choose between single-product or category-grid shortcode
    var picker = document.createElement('div');
    picker.className = 'ap-embed-picker';
    picker.innerHTML =
      '<div class="ap-embed-row">'
      +   '<code>[[affiliate-product:slug=' + esc(pid) + ']]</code>'
      +   '<button class="ap-btn ap-btn-sm" data-shortcode="[[affiliate-product:slug=' + esc(pid) + ']]"><i class="fa-solid fa-copy"></i> Copy single</button>'
      + '</div>'
      + (category ? '<div class="ap-embed-row">'
      +   '<code>[[affiliate-products category=&quot;' + esc(category) + '&quot;]]</code>'
      +   '<button class="ap-btn ap-btn-sm" data-shortcode="[[affiliate-products category=&quot;' + esc(category) + '&quot;]]"><i class="fa-solid fa-copy"></i> Copy category</button>'
      + '</div>' : '')
      + '<button class="ap-embed-close" onclick="this.parentNode.remove()">&times;</button>';

    // Position over the page
    picker.style.position = 'fixed';
    picker.style.top = '50%'; picker.style.left = '50%';
    picker.style.transform = 'translate(-50%,-50%)';
    document.body.appendChild(picker);

    picker.querySelectorAll('button[data-shortcode]').forEach(function(b){
      b.addEventListener('click', function(){
        var code = b.dataset.shortcode.replace(/&quot;/g, '"');
        navigator.clipboard.writeText(code).then(function(){
          showToast('Copied: ' + code);
          picker.remove();
        }, function(){
          // Fallback: select via temporary textarea
          var ta = document.createElement('textarea');
          ta.value = code; document.body.appendChild(ta);
          ta.select(); document.execCommand('copy'); ta.remove();
          showToast('Copied: ' + code);
          picker.remove();
        });
      });
    });
  }

  function showToast(msg){
    var t = document.createElement('div');
    t.className = 'ap-toast'; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function(){ t.classList.add('ap-toast-hide'); setTimeout(function(){ t.remove(); }, 400); }, 1800);
  }

  function vetoProduct(pid){
    if (!currentSite) return;
    var p = products.find(function(x){ return x.id === pid; }) || {};
    var reason = prompt('Veto "' + (p.title || 'this product') + '"?\n\nIt will be removed from this site AND added to the permanent block list — the pipeline will never suggest it again.\n\nOptional reason (for your records):');
    if (reason === null) return;   // cancel
    var fd = new FormData();
    fd.append('action', 'veto_product');
    fd.append('site', currentSite);
    fd.append('id', pid);
    fd.append('reason', reason || '');
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Veto failed'); return; }
        showToast('Vetoed (' + (j.data.veto_count || 0) + ' total on this site)');
        loadSiteProductsIntoTab(currentSite);
        refreshVetoCount();
      });
  }

  function refreshVetoCount(){
    if (!currentSite) {
      var pill = document.getElementById('ap-veto-pill');
      if (pill) pill.style.display = 'none';
      return;
    }
    fetch(API + '?action=list_veto&site=' + encodeURIComponent(currentSite))
      .then(function(r){ return r.json(); })
      .then(function(j){
        var pill = document.getElementById('ap-veto-pill');
        var cnt = document.getElementById('ap-veto-count');
        var n = (j.ok && j.data.veto && j.data.veto.length) || 0;
        if (pill) pill.style.display = n > 0 ? '' : 'none';
        if (cnt) cnt.textContent = n;
      });
  }

  function openVetoList(){
    if (!currentSite) return;
    document.getElementById('ap-veto-modal-site').textContent = currentSite;
    document.getElementById('ap-veto-modal-body').innerHTML = '<div style="text-align:center;color:#888;padding:30px">Loading…</div>';
    document.getElementById('ap-veto-modal').classList.add('show');
    fetch(API + '?action=list_veto&site=' + encodeURIComponent(currentSite))
      .then(function(r){ return r.json(); })
      .then(function(j){
        var body = document.getElementById('ap-veto-modal-body');
        if (!j.ok) { body.innerHTML = '<div class="ap-empty">' + esc(j.message || 'Failed') + '</div>'; return; }
        var v = j.data.veto || [];
        if (!v.length) { body.innerHTML = '<div class="ap-empty">Nothing vetoed yet for this site.</div>'; return; }
        body.innerHTML = '<table class="ap-veto-table"><thead><tr>'
          + '<th>ASIN</th><th>Title</th><th>Category</th><th>Reason</th><th>Vetoed</th><th></th>'
          + '</tr></thead><tbody>'
          + v.map(function(x){
              var when = x.vetoed_at ? new Date(x.vetoed_at).toLocaleDateString() : '';
              return '<tr>'
                + '<td><code>' + esc(x.asin || '?') + '</code></td>'
                + '<td>' + (x.url ? '<a href="' + esc(x.url) + '" target="_blank" rel="noopener nofollow">' + esc(x.title || '') + '</a>' : esc(x.title || '')) + '</td>'
                + '<td><small>' + esc(x.category || '') + '</small></td>'
                + '<td><small>' + esc(x.reason || '') + '</small></td>'
                + '<td><small>' + when + '</small></td>'
                + '<td><button class="ap-card-action" onclick="AP.unvetoProduct(\'' + esc(x.asin || '') + '\',\'' + esc(x.url || '').replace(/'/g, "\\'") + '\')" title="Remove from veto list — pipeline can suggest again"><i class="fa-solid fa-rotate-left"></i></button></td>'
                + '</tr>';
            }).join('')
          + '</tbody></table>';
      });
  }

  function unvetoProduct(asin, url){
    if (!currentSite) return;
    if (!confirm('Remove from veto list? The pipeline will be allowed to suggest this product again.')) return;
    var fd = new FormData();
    fd.append('action', 'unveto_product');
    fd.append('site', currentSite);
    fd.append('asin', asin || '');
    fd.append('url', url || '');
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Failed'); return; }
        openVetoList();   // refresh list
        refreshVetoCount();
      });
  }

  function closeVetoList(){
    document.getElementById('ap-veto-modal').classList.remove('show');
  }

  function deleteSiteProduct(pid){
    if (!currentSite) return;
    if (!confirm('Delete this product from ' + currentSite + '? It will be removed from products.json. The next pipeline run may re-pull it.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_site_product');
    fd.append('site', currentSite);
    fd.append('id', pid);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Failed'); return; }
        loadSiteProductsIntoTab(currentSite);
      });
  }

  /* ── Product Modal ────────────────────────────────────────────── */
  function openProductModal(product){
    var modal = document.getElementById('ap-product-modal');
    document.getElementById('ap-modal-title').textContent = product ? 'Edit Product' : 'Add Product';
    document.getElementById('ap-prod-id').value          = product ? product.id : '';
    document.getElementById('ap-prod-url').value         = product ? product.url : '';
    document.getElementById('ap-prod-title').value       = product ? product.title : '';
    document.getElementById('ap-prod-description').value = product ? product.description : '';
    document.getElementById('ap-prod-price').value       = product ? product.price : '';
    document.getElementById('ap-prod-category').value    = product ? product.category : '';
    document.getElementById('ap-prod-rating').value      = product ? (product.rating||'') : '';
    document.getElementById('ap-prod-asin').value        = product ? (product.asin||'') : '';
    document.getElementById('ap-prod-tag').value         = product ? (product.affiliate_tag||'') : '';
    document.getElementById('ap-prod-image').value       = product ? product.image : '';
    document.getElementById('ap-prod-source').value      = product ? (product.source || 'amazon') : 'amazon';
    document.getElementById('ap-prod-enabled').checked   = product ? product.enabled !== false : true;
    document.getElementById('ap-resolve-status').innerHTML = '';

    if (product) {
      // Edit existing — skip resolve, show resolved view directly with image preview
      showResolvedStep(product);
    } else {
      // Add new — show paste-URL step
      showResolveStep();
    }
    modal.classList.add('show');
    setTimeout(function(){ document.getElementById('ap-prod-url').focus(); }, 80);
  }

  function showResolveStep(){
    document.getElementById('ap-resolve-step').style.display = '';
    document.getElementById('ap-resolved-step').style.display = 'none';
    document.getElementById('ap-modal-save').style.display = 'none';
    document.getElementById('ap-modal-back').style.display = 'none';
  }

  function showResolvedStep(p){
    document.getElementById('ap-resolve-step').style.display = 'none';
    document.getElementById('ap-resolved-step').style.display = '';
    document.getElementById('ap-modal-save').style.display = '';
    // Only show "Back" when adding new (so user can re-resolve a different URL)
    var isNew = !document.getElementById('ap-prod-id').value;
    document.getElementById('ap-modal-back').style.display = isNew ? '' : 'none';

    document.getElementById('ap-resolved-img').src = p.image || '';
    document.getElementById('ap-resolved-title').textContent = p.title || '';
    document.getElementById('ap-resolved-price').textContent = p.price || '';
    document.getElementById('ap-resolved-asin').textContent = p.asin ? ('ASIN ' + p.asin) : '';
    var link = document.getElementById('ap-resolved-url-link');
    link.href = p.url || '#';
  }

  function resolveBack(){ showResolveStep(); }

  function resolveProductUrl(){
    var url = document.getElementById('ap-prod-url').value.trim();
    if (!url) { alert('Paste an Amazon URL first'); return; }

    var btn = document.getElementById('ap-resolve-btn');
    var status = document.getElementById('ap-resolve-status');
    btn.disabled = true;
    btn.innerHTML = '<span class="ap-spinner"></span> Resolving…';
    status.innerHTML = '<span style="color:#888"><i class="fa-solid fa-spinner fa-spin"></i> Fetching product page…</span>';

    var fd = new FormData();
    fd.append('action', 'resolve_product_url');
    fd.append('url', url);

    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Resolve';
        if (!j.ok) {
          status.innerHTML = '<div class="ap-resolve-error"><i class="fa-solid fa-circle-xmark"></i> ' + esc(j.message || 'Could not resolve') + '</div>';
          return;
        }
        var d = j.data;
        // Populate hidden + visible fields
        document.getElementById('ap-prod-title').value  = d.title || '';
        document.getElementById('ap-prod-price').value  = d.price || '';
        document.getElementById('ap-prod-image').value  = d.image || '';
        document.getElementById('ap-prod-asin').value   = d.asin || '';
        document.getElementById('ap-prod-tag').value    = d.affiliate_tag || '';
        document.getElementById('ap-prod-rating').value = d.rating || '';
        document.getElementById('ap-prod-source').value = 'amazon';
        document.getElementById('ap-prod-url').value    = d.url || url;
        showResolvedStep(d);
      })
      .catch(function(err){
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Resolve';
        status.innerHTML = '<div class="ap-resolve-error">Request failed: ' + esc(err.message) + '</div>';
      });
  }

  function closeProductModal(){
    document.getElementById('ap-product-modal').classList.remove('show');
  }

  function saveProduct(){
    var fd = new FormData();
    fd.append('action', 'save_product');
    fd.append('id', document.getElementById('ap-prod-id').value);
    fd.append('url', document.getElementById('ap-prod-url').value);
    fd.append('title', document.getElementById('ap-prod-title').value);
    fd.append('description', document.getElementById('ap-prod-description').value);
    fd.append('price', document.getElementById('ap-prod-price').value);
    fd.append('category', document.getElementById('ap-prod-category').value);
    fd.append('rating', document.getElementById('ap-prod-rating').value);
    fd.append('asin', document.getElementById('ap-prod-asin').value);
    fd.append('affiliate_tag', document.getElementById('ap-prod-tag').value);
    fd.append('image', document.getElementById('ap-prod-image').value);
    fd.append('source', document.getElementById('ap-prod-source').value);
    fd.append('enabled', document.getElementById('ap-prod-enabled').checked ? '1' : '0');

    fetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Save failed'); return; }
        closeProductModal();
        loadProducts();
      });
  }

  function editProduct(id){
    var p = products.find(function(x){ return x.id === id; });
    if (p) openProductModal(p);
  }

  function deleteProduct(id){
    if (!confirm('Delete this product?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_product');
    fd.append('id', id);
    fetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Delete failed'); return; }
        loadProducts();
      });
  }


  /* ── Config ───────────────────────────────────────────────────── */
  function loadConfig(){
    fetch(API + '?action=get_config')
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        var d = j.data;
        document.getElementById('ap-cfg-creators-client-id').value     = d.amazon_creators_client_id || '';
        document.getElementById('ap-cfg-creators-client-secret').value  = d.amazon_creators_client_secret || '';
        document.getElementById('ap-cfg-associate-tag').value           = d.amazon_associate_tag || '';
        document.getElementById('ap-cfg-marketplace').value             = d.amazon_marketplace || 'www.amazon.com';
        document.getElementById('ap-cfg-access-key').value              = d.amazon_access_key || '';
        document.getElementById('ap-cfg-secret-key').value              = d.amazon_secret_key || '';
        document.getElementById('ap-cfg-walmart-api-key').value = d.walmart_api_key || '';
        document.getElementById('ap-cfg-walmart-publisher-id').value = d.walmart_publisher_id || '';
        document.getElementById('ap-cfg-walmart-link-id').value = d.walmart_link_id || '';
        document.getElementById('ap-cfg-bestbuy-api-key').value = d.bestbuy_api_key || '';
        document.getElementById('ap-cfg-bestbuy-affiliate-id').value = d.bestbuy_affiliate_id || '';
        document.getElementById('ap-cfg-default-tag').value = d.default_affiliate_tag || '';
        document.getElementById('ap-cfg-ai-enabled').checked = !!d.ai_enabled;
        updateApiStatuses(d);
      });
  }

  function saveConfig(){
    var fd = new FormData();
    fd.append('action', 'save_config');
    fd.append('amazon_creators_client_id',     document.getElementById('ap-cfg-creators-client-id').value);
    fd.append('amazon_creators_client_secret', document.getElementById('ap-cfg-creators-client-secret').value);
    fd.append('amazon_associate_tag',          document.getElementById('ap-cfg-associate-tag').value);
    fd.append('amazon_marketplace',            document.getElementById('ap-cfg-marketplace').value);
    fd.append('amazon_access_key',             document.getElementById('ap-cfg-access-key').value);
    fd.append('amazon_secret_key',             document.getElementById('ap-cfg-secret-key').value);
    fd.append('walmart_api_key', document.getElementById('ap-cfg-walmart-api-key').value);
    fd.append('walmart_publisher_id', document.getElementById('ap-cfg-walmart-publisher-id').value);
    fd.append('walmart_link_id', document.getElementById('ap-cfg-walmart-link-id').value);
    fd.append('bestbuy_api_key', document.getElementById('ap-cfg-bestbuy-api-key').value);
    fd.append('bestbuy_affiliate_id', document.getElementById('ap-cfg-bestbuy-affiliate-id').value);
    fd.append('default_affiliate_tag', document.getElementById('ap-cfg-default-tag').value);
    fd.append('ai_enabled', document.getElementById('ap-cfg-ai-enabled').checked ? '1' : '0');

    fetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        var status = document.getElementById('ap-save-status');
        status.textContent = j.ok ? 'Saved!' : (j.message || 'Error');
        status.style.color = j.ok ? '#10b981' : '#ef4444';
        setTimeout(function(){ status.textContent = ''; }, 3000);
      });
  }

  /* ── API Status Indicators ────────────────────────────────────── */
  function updateApiStatuses(d){
    var amzOk = (d.amazon_creators_client_id && d.amazon_creators_client_secret && d.amazon_associate_tag);
    setStatus('ap-amazon-status', amzOk);
    setStatus('ap-walmart-status', d.walmart_api_key);
    setStatus('ap-bestbuy-status', d.bestbuy_api_key);
  }

  function testAmazon(){
    var status = document.getElementById('ap-amazon-status');
    status.innerHTML = '<span style="color:#888"><i class="fa-solid fa-spinner fa-spin"></i> Testing…</span>';
    fetch(API + '?action=amazon_test')
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok) {
          status.innerHTML = '<span style="color:#10b981"><i class="fa-solid fa-circle-check"></i> Connected — ' + (j.message || 'OK') + '</span>';
        } else {
          status.innerHTML = '<span style="color:#ef4444"><i class="fa-solid fa-circle-xmark"></i> ' + (j.message || 'Failed') + '</span>';
        }
      })
      .catch(function(){ status.innerHTML = '<span style="color:#ef4444">Request failed</span>'; });
  }
  function setStatus(id, hasKeys){
    var el = document.getElementById(id);
    if (!el) return;
    if (hasKeys) {
      el.innerHTML = '<span style="color:#10b981"><i class="fa-solid fa-circle-check"></i> Configured</span>';
    } else {
      el.innerHTML = '<span style="color:#666"><i class="fa-solid fa-circle-minus"></i> Not configured</span>';
    }
  }

  /* ── AI Discovery ─────────────────────────────────────────────── */
  function loadAiProviders(){
    fetch(AI_API + '?action=get_state')
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        var sel = document.getElementById('ap-ai-provider');
        var providers = (j.data && j.data.providers) || [];
        providers.forEach(function(p){
          var opt = document.createElement('option');
          opt.value = p.key || '';
          opt.textContent = (p.label || p.type || p.key) + (p.is_active ? ' (active)' : '');
          sel.appendChild(opt);
        });
      })
      .catch(function(){});
  }

  function openAiModal(){
    aiSuggestions = [];
    document.getElementById('ap-ai-prompt').value = '';
    document.getElementById('ap-ai-results').style.display = 'none';
    document.getElementById('ap-ai-approve-btn').style.display = 'none';
    document.getElementById('ap-ai-grid').innerHTML = '';
    document.getElementById('ap-ai-modal').classList.add('show');
  }

  function closeAiModal(){
    document.getElementById('ap-ai-modal').classList.remove('show');
  }

  function aiGenerate(){
    var prompt = document.getElementById('ap-ai-prompt').value.trim();
    if (!prompt) { alert('Enter a prompt'); return; }

    var btn = document.getElementById('ap-ai-generate-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="ap-spinner"></span> Generating...';

    var fd = new FormData();
    fd.append('action', 'ai_discover');
    fd.append('prompt', prompt);
    fd.append('category', document.getElementById('ap-ai-category').value);
    fd.append('count', document.getElementById('ap-ai-count').value);
    fd.append('provider', document.getElementById('ap-ai-provider').value);

    fetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Generate Suggestions';
        if (!j.ok) { alert(j.message || 'AI generation failed'); return; }

        aiSuggestions = j.data.suggestions || [];
        var model = j.data.model || '';
        document.getElementById('ap-ai-results-count').textContent = aiSuggestions.length + ' suggestions';
        document.getElementById('ap-ai-model').textContent = model;
        document.getElementById('ap-ai-results').style.display = 'block';
        document.getElementById('ap-ai-approve-btn').style.display = aiSuggestions.length ? '' : 'none';
        renderAiGrid();
      })
      .catch(function(err){
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Generate Suggestions';
        alert('Request failed: ' + err.message);
      });
  }

  function renderAiGrid(){
    var grid = document.getElementById('ap-ai-grid');
    if (!aiSuggestions.length) {
      grid.innerHTML = '<div class="ap-empty">No suggestions returned</div>';
      return;
    }
    var html = '';
    aiSuggestions.forEach(function(s, i){
      html += '<div class="ap-ai-item" data-index="' + i + '">'
        + '<input type="checkbox" class="ap-ai-item-check" checked data-index="' + i + '">'
        + '<div class="ap-ai-item-info">'
        + '<div class="ap-ai-item-title">' + esc(s.title || '') + '</div>'
        + '<div class="ap-ai-item-desc">' + esc(s.description || '') + '</div>'
        + '<div class="ap-ai-item-meta">'
        + '<span class="price">' + esc(s.price || '') + '</span>'
        + '<span class="rating">&#9733; ' + esc(s.rating || '') + '</span>'
        + '<span class="ap-source-badge ap-source-' + esc(s.source || 'generic') + '">' + esc(s.source || 'generic') + '</span>'
        + '</div>'
        + '</div>'
        + '</div>';
    });
    grid.innerHTML = html;

    // Toggle excluded class on uncheck
    grid.querySelectorAll('.ap-ai-item-check').forEach(function(cb){
      cb.addEventListener('change', function(){
        var item = cb.closest('.ap-ai-item');
        if (item) item.classList.toggle('excluded', !cb.checked);
      });
    });
  }

  function aiApprove(){
    var checks = document.querySelectorAll('.ap-ai-item-check:checked');
    var selected = [];
    checks.forEach(function(cb){
      var idx = parseInt(cb.dataset.index, 10);
      if (aiSuggestions[idx]) selected.push(aiSuggestions[idx]);
    });
    if (!selected.length) { alert('No products selected'); return; }

    var fd = new FormData();
    fd.append('action', 'ai_approve');
    fd.append('items', JSON.stringify(selected));
    fd.append('category', document.getElementById('ap-ai-category').value);

    fetch(API, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Approve failed'); return; }
        closeAiModal();
        loadProducts();
      });
  }

  /* ── helpers ──────────────────────────────────────────────────── */
  function esc(s){
    if (!s) return '';
    var el = document.createElement('span');
    el.textContent = String(s);
    return el.innerHTML;
  }

  /* ── Amazon Stores Registry — inline editable table ─────────────── */
  var stores = [];
  var candidateSites = [];   // [{ domain, attached_to }]

  function loadStores(){
    Promise.all([
      fetch(API + '?action=list_stores').then(function(r){ return r.json(); }),
      fetch(API + '?action=list_candidate_sites').then(function(r){ return r.json(); })
    ]).then(function(res){
      stores = (res[0].ok ? res[0].data.stores : []) || [];
      candidateSites = (res[1].ok ? res[1].data.sites : []) || [];
      renderStoresTable();
    });
  }

  function refreshKnownSitesDatalist(){
    // Datalist suggestions: candidate sites + sites currently in registry
    var dl = document.getElementById('ap-known-sites');
    if (!dl) return;
    var known = {};
    candidateSites.forEach(function(c){ known[c.domain] = true; });
    stores.forEach(function(s){ if (s.site) known[s.site] = true; });
    dl.innerHTML = Object.keys(known).sort().map(function(d){
      return '<option value="' + esc(d) + '">';
    }).join('');
  }

  function renderStoresTable(){
    var tbody = document.querySelector('#ap-stores-table tbody');
    if (!tbody) return;
    refreshKnownSitesDatalist();
    if (!stores.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;padding:18px">No stores yet — click <strong>Bulk Add</strong> to import your Amazon Store IDs.</td></tr>';
      return;
    }
    tbody.innerHTML = stores.map(function(s){
      var dim = s.enabled ? '' : ' class="ap-row-disabled"';
      var tag = s.tag || '';
      var prodCount = s.product_count || 0;
      var clicks = s.clicks_30d || 0;
      var prodCell = s.site && prodCount > 0
        ? '<a href="javascript:;" onclick="AP.openSiteProducts(\'' + esc(s.site) + '\')">' + prodCount + ' &rarr;</a>'
        : ('<span style="color:#666">' + prodCount + '</span>');
      var canEdit = !!s.site;
      var visitBtn = canEdit
        ? '<a class="ap-card-action" href="https://' + esc(s.site) + (s.page_pattern ? esc((s.page_pattern || '').replace(/\*+$/, '')) : '/') + '" target="_blank" rel="noopener" title="Visit live page"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>'
        : '';
      var promptBtn = canEdit
        ? '<button class="ap-card-action" onclick="AP.openPromptEditor(\'' + esc(tag) + '\')" title="Edit pipeline / prompt"><i class="fa-solid fa-wand-magic-sparkles"></i></button>'
        : '<button class="ap-card-action" disabled title="Attach a site first"><i class="fa-solid fa-wand-magic-sparkles"></i></button>';
      return '<tr' + dim + ' data-orig-tag="' + esc(tag) + '">'
        + '<td><input type="text" class="ap-cell-input ap-tag-input" data-orig-tag="' + esc(tag) + '" data-field="tag" value="' + esc(tag) + '" placeholder="e.g. yourtag-20"></td>'
        + '<td><input type="text" class="ap-cell-input ap-site-input" list="ap-known-sites" data-orig-tag="' + esc(tag) + '" data-field="site" value="' + esc(s.site || '') + '" placeholder="e.g. mysite.com"></td>'
        + '<td><input type="text" class="ap-cell-input ap-pattern-input" data-orig-tag="' + esc(tag) + '" data-field="page_pattern" value="' + esc(s.page_pattern || '') + '" placeholder="e.g. /articles/*"></td>'
        + '<td style="text-align:right;font-weight:600">' + clicks + '</td>'
        + '<td style="text-align:right;font-weight:600">' + prodCell + '</td>'
        + '<td style="text-align:center"><label class="ap-toggle-mini"><input type="checkbox" class="ap-cell-input" data-orig-tag="' + esc(tag) + '" data-field="enabled"' + (s.enabled !== false ? ' checked' : '') + '><span></span></label></td>'
        + '<td style="text-align:right;white-space:nowrap">' + visitBtn + promptBtn
        + '<button class="ap-card-action delete" onclick="AP.deleteStore(\'' + esc(tag) + '\')" title="Delete"><i class="fa-solid fa-trash"></i></button></td>'
        + '</tr>';
    }).join('');

    tbody.querySelectorAll('.ap-cell-input').forEach(function(el){
      var evt = el.type === 'checkbox' ? 'change' : 'blur';
      el.addEventListener(evt, function(){ saveRow(el); });
    });
  }

  function saveRow(el){
    var tr = el.closest('tr');
    if (!tr) return;
    var origTag = tr.dataset.origTag || '';
    var tagInput     = tr.querySelector('input[data-field=tag]');
    var siteInput    = tr.querySelector('input[data-field=site]');
    var patternInput = tr.querySelector('input[data-field=page_pattern]');
    var enabledChk   = tr.querySelector('input[data-field=enabled]');

    var newTag = (tagInput.value || '').trim();
    if (!newTag) {
      if (!origTag) { tr.remove(); return; }
      deleteStore(origTag);
      return;
    }

    var fd = new FormData();
    fd.append('action', 'save_store');
    fd.append('original_tag', origTag);
    fd.append('tag', newTag);
    fd.append('site', (siteInput.value || '').trim());
    fd.append('page_pattern', (patternInput.value || '').trim());
    fd.append('enabled', enabledChk.checked ? '1' : '0');

    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) {
          showRowFlash(tr, j.message || 'Save failed', 'err');
          alert(j.message || 'Save failed');
          return;
        }
        tr.dataset.origTag = newTag;
        [tagInput, siteInput, patternInput, enabledChk].forEach(function(x){ if (x) x.dataset.origTag = newTag; });
        showRowFlash(tr, '✓ saved', 'ok');
        setTimeout(loadStores, 600);
      })
      .catch(function(err){ showRowFlash(tr, 'Network error', 'err'); });
  }

  function showRowFlash(tr, msg, type){
    tr.classList.add(type === 'ok' ? 'ap-row-flash-ok' : 'ap-row-flash-err');
    setTimeout(function(){ tr.classList.remove('ap-row-flash-ok','ap-row-flash-err'); }, 1200);
  }

  function addStoreRow(){
    var tbody = document.querySelector('#ap-stores-table tbody');
    if (!tbody) return;
    if (tbody.querySelector('td[colspan]')) tbody.innerHTML = '';
    var newRow = document.createElement('tr');
    newRow.dataset.origTag = '';
    newRow.innerHTML = '<td><input type="text" class="ap-cell-input ap-tag-input" data-orig-tag="" data-field="tag" placeholder="e.g. yourtag-20"></td>'
      + '<td><input type="text" class="ap-cell-input ap-site-input" list="ap-known-sites" data-orig-tag="" data-field="site" placeholder="e.g. mysite.com"></td>'
      + '<td><input type="text" class="ap-cell-input ap-pattern-input" data-orig-tag="" data-field="page_pattern" placeholder="e.g. /articles/*"></td>'
      + '<td style="text-align:right;color:#666">0</td>'
      + '<td style="text-align:right;color:#666">0</td>'
      + '<td style="text-align:center"><label class="ap-toggle-mini"><input type="checkbox" class="ap-cell-input" data-orig-tag="" data-field="enabled" checked><span></span></label></td>'
      + '<td></td>';
    tbody.appendChild(newRow);
    newRow.querySelectorAll('.ap-cell-input').forEach(function(el){
      var evt = el.type === 'checkbox' ? 'change' : 'blur';
      el.addEventListener(evt, function(){ saveRow(el); });
    });
    var input = newRow.querySelector('input[data-field=tag]');
    if (input) input.focus();
  }

  function deleteStore(tag){
    if (!tag) return;
    if (!confirm('Delete store "' + tag + '"? The site attached to it will keep its current config; the tracking ID just won\'t be in the registry anymore.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_store');
    fd.append('tag', tag);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert(j.message || 'Delete failed'); return; }
        loadStores();
      });
  }

  function bulkSeedStores(){
    var input = prompt('Paste Amazon Store IDs, one per line (or comma/space-separated). Existing IDs will be skipped.');
    if (!input || !input.trim()) return;
    var fd = new FormData();
    fd.append('action', 'seed_stores');
    fd.append('ids', input);
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        alert(j.ok ? j.message : (j.message || 'Seed failed'));
        if (j.ok) loadStores();
      });
  }

  function gotoStoresSettings(){
    var settingsTab = document.querySelector('[data-tab="settings"]');
    if (settingsTab) settingsTab.click();
    setTimeout(function(){
      var card = document.getElementById('ap-stores-card');
      if (card) card.scrollIntoView({ behavior:'smooth', block:'start' });
    }, 50);
  }

  /* ── Prompt Editor (per-store pipeline editor) ─────────────────── */
  function openPromptEditor(tag){
    document.getElementById('ap-prompt-modal').classList.add('show');
    document.getElementById('ap-pr-tag').value = tag;
    document.getElementById('ap-pr-status').innerHTML = '';
    setPromptStatus('Loading…');

    fetch(API + '?action=prompt_load&tag=' + encodeURIComponent(tag))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { setPromptStatus(j.message || 'Load failed', 'err'); return; }
        var d = j.data;
        var s = d.store;
        var t = d.task;

        // Banner
        document.getElementById('ap-pr-title').textContent = 'Pipeline for ' + tag + ' → ' + (s.site || '(no site)');
        document.getElementById('ap-pr-banner-tag').textContent = tag;
        document.getElementById('ap-pr-banner-site').textContent = s.site || '(unattached)';
        document.getElementById('ap-pr-banner-pattern').textContent = s.page_pattern || 'site default';
        document.getElementById('ap-pr-banner-task').textContent = t ? t.id : 'will create: ' + d.suggested_id;

        // Form values — from existing task or defaults
        var c = (t && t.config) || {};
        var sched = (t && t.schedule) || {};
        document.getElementById('ap-pr-task-id').value = t ? t.id : d.suggested_id;
        document.getElementById('ap-pr-theme').value = c.theme || '';
        document.getElementById('ap-pr-search').value = c.search_terms || '';
        document.getElementById('ap-pr-category').value = c.category || (s.site && s.site.split('.')[0] + '-picks');
        document.getElementById('ap-pr-pageurl').value = c.page_url || (s.page_pattern || '').replace('/*','') || '';
        document.getElementById('ap-pr-xnum').value = c.x_num || 12;
        document.getElementById('ap-pr-rating').value = (c.min_rating != null) ? c.min_rating : 4.0;
        document.getElementById('ap-pr-prune').value = (c.prune_old != null) ? c.prune_old : 12;
        document.getElementById('ap-pr-pmin').value = c.price_min || '';
        document.getElementById('ap-pr-pmax').value = c.price_max || '';
        document.getElementById('ap-pr-recur').value = (t && t.recurrence) || 'recurring';
        document.getElementById('ap-pr-day').value = sched.day || 'wednesday';
        document.getElementById('ap-pr-hour').value = (sched.hour != null) ? sched.hour : 2;
        document.getElementById('ap-pr-minute').value = (sched.minute != null) ? sched.minute : 5;
        document.getElementById('ap-pr-enabled').checked = t ? !!t.enabled : true;
        toggleRecurFields();
        setPromptStatus('');
      });
  }

  function closePromptEditor(){
    document.getElementById('ap-prompt-modal').classList.remove('show');
  }

  function toggleRecurFields(){
    var recur = document.getElementById('ap-pr-recur').value;
    var dayCol = document.getElementById('ap-pr-day-col');
    var timeCol = document.getElementById('ap-pr-time-col');
    if (dayCol) dayCol.style.display = recur === 'recurring' ? '' : 'none';
    if (timeCol) timeCol.style.display = recur === 'recurring' ? '' : 'none';
  }

  function setPromptStatus(msg, type){
    var el = document.getElementById('ap-pr-status');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = type === 'err' ? '#fca5a5' : (type === 'ok' ? '#10b981' : '#aaa');
  }

  function buildPromptFD(action){
    var fd = new FormData();
    fd.append('action', action);
    fd.append('tag', document.getElementById('ap-pr-tag').value);
    fd.append('task_id', document.getElementById('ap-pr-task-id').value);
    fd.append('theme', document.getElementById('ap-pr-theme').value);
    fd.append('search_terms', document.getElementById('ap-pr-search').value);
    fd.append('category', document.getElementById('ap-pr-category').value);
    fd.append('page_url', document.getElementById('ap-pr-pageurl').value);
    fd.append('x_num', document.getElementById('ap-pr-xnum').value);
    fd.append('min_rating', document.getElementById('ap-pr-rating').value);
    fd.append('prune_old', document.getElementById('ap-pr-prune').value);
    fd.append('price_min', document.getElementById('ap-pr-pmin').value);
    fd.append('price_max', document.getElementById('ap-pr-pmax').value);
    fd.append('recurrence', document.getElementById('ap-pr-recur').value);
    fd.append('day', document.getElementById('ap-pr-day').value);
    fd.append('hour', document.getElementById('ap-pr-hour').value);
    fd.append('minute', document.getElementById('ap-pr-minute').value);
    fd.append('enabled', document.getElementById('ap-pr-enabled').checked ? '1' : '0');
    return fd;
  }

  function savePromptTask(){
    setPromptStatus('Saving…');
    fetch(API, { method:'POST', body: buildPromptFD('prompt_save') })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { setPromptStatus(j.message || 'Save failed', 'err'); return; }
        setPromptStatus('✓ ' + (j.message || 'Saved'), 'ok');
        // Update banner
        if (j.data.task) {
          document.getElementById('ap-pr-banner-task').textContent = j.data.task.id;
          document.getElementById('ap-pr-task-id').value = j.data.task.id;
        }
      });
  }

  function runPromptLive(){
    if (!confirm('Run pipeline NOW and write directly to the LIVE products.json on the spoke? Existing products will be appended/pruned per the task config.')) return;
    setPromptStatus('Saving…');
    fetch(API, { method:'POST', body: buildPromptFD('prompt_save') })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { setPromptStatus(j.message || 'Save failed', 'err'); return; }
        var taskId = j.data.task.id;
        document.getElementById('ap-pr-task-id').value = taskId;
        setPromptStatus('Saved. Launching live run…');
        var fd = new FormData();
        fd.append('action', 'prompt_run');
        fd.append('task_id', taskId);
        fd.append('mode', 'normal');
        return fetch(API, { method:'POST', body:fd }).then(function(r){ return r.json(); });
      })
      .then(function(j){
        if (!j) return;
        if (!j.ok) { setPromptStatus(j.message || 'Run failed', 'err'); return; }
        setPromptStatus('✓ ' + j.message, 'ok');
      });
  }

  function runPromptSandbox(){
    setPromptStatus('Saving…');
    fetch(API, { method:'POST', body: buildPromptFD('prompt_save') })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { setPromptStatus(j.message || 'Save failed', 'err'); return; }
        var taskId = j.data.task.id;
        document.getElementById('ap-pr-task-id').value = taskId;
        setPromptStatus('Saved. Launching sandbox run (review before promote)…');
        var fd = new FormData();
        fd.append('action', 'prompt_run');
        fd.append('task_id', taskId);
        fd.append('mode', 'sandbox');
        return fetch(API, { method:'POST', body:fd }).then(function(r){ return r.json(); });
      })
      .then(function(j){
        if (!j) return;
        if (!j.ok) { setPromptStatus(j.message || 'Run failed', 'err'); return; }
        setPromptStatus('✓ Sandbox run launched. Review results in AgentScheduler → Sandbox.', 'ok');
      });
  }

  /* ── Per-site products drill-down ──────────────────────────────── */
  function openSiteProducts(site){
    document.getElementById('ap-sp-title').textContent = 'Products on ' + site;
    document.getElementById('ap-sp-body').innerHTML = '<div style="text-align:center;color:#888;padding:30px">Loading…</div>';
    document.getElementById('ap-site-products-modal').classList.add('show');

    fetch(API + '?action=list_site_products&site=' + encodeURIComponent(site))
      .then(function(r){ return r.json(); })
      .then(function(j){
        var body = document.getElementById('ap-sp-body');
        if (!j.ok) { body.innerHTML = '<div class="ap-empty">' + esc(j.message || 'No data') + '</div>'; return; }
        var d = j.data;
        if (!d.products.length) {
          body.innerHTML = '<div class="ap-empty">No products yet for this site. Pipeline run on Wed will populate.</div>';
          return;
        }
        var byCat = {};
        d.products.forEach(function(p){
          var c = p.category || 'uncategorized';
          if (!byCat[c]) byCat[c] = [];
          byCat[c].push(p);
        });
        var html = '<div style="margin-bottom:12px;color:#aaa;font-size:0.85rem">'
          + '<strong>' + d.count + '</strong> products · '
          + '<strong>' + (d.clicks_30d || 0) + '</strong> clicks (30d)'
          + '</div>';
        Object.keys(byCat).sort().forEach(function(cat){
          html += '<h4 style="margin:14px 0 8px;color:#a78bfa;font-size:0.95rem">' + esc(cat) + ' <span style="color:#666;font-weight:normal">(' + byCat[cat].length + ')</span></h4>';
          html += '<div class="ap-sp-grid">';
          byCat[cat].forEach(function(p){
            var img = p.image ? '<img src="' + esc(p.image) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<div class="ap-sp-noimg">no image</div>';
            html += '<div class="ap-sp-item">'
              + img
              + '<div class="ap-sp-meta"><div class="ap-sp-title">' + esc(p.title || '') + '</div>'
              + '<div class="ap-sp-row"><span>' + esc(p.price || '') + '</span>'
              + (p.rating ? '<span style="color:#fbbf24">&#9733; ' + esc(p.rating) + '</span>' : '')
              + '</div>'
              + (p.url ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" class="ap-sp-link">View &rarr;</a>' : '')
              + '</div></div>';
          });
          html += '</div>';
        });
        body.innerHTML = html;
      });
  }

  function closeSiteProducts(){
    document.getElementById('ap-site-products-modal').classList.remove('show');
  }

  function bulkSeedFromTextarea(){
    var ta = document.getElementById('ap-amzn-bulk-ids');
    if (!ta) return;
    var ids = (ta.value || '').trim();
    if (!ids) { alert('Paste some IDs first'); return; }
    var fd = new FormData();
    fd.append('action', 'seed_stores');
    fd.append('ids', ids);
    var status = document.getElementById('ap-amzn-bulk-status');
    status.textContent = 'Importing…';
    fetch(API, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        status.textContent = j.message || (j.ok ? 'Imported' : 'Failed');
        status.style.color = j.ok ? '#10b981' : '#ef4444';
        if (j.ok) { ta.value = ''; loadStores(); }
      });
  }

  // Stores load on Dashboard tab open (and on initial mount)
  document.addEventListener('DOMContentLoaded', function(){
    loadStores();
    document.querySelectorAll('.ap-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        if (tab.dataset.tab === 'dashboard') loadStores();
      });
    });
    // Relocate the per-site products modal to body
    var sm = document.getElementById('ap-site-products-modal');
    if (sm && sm.parentNode !== document.body) document.body.appendChild(sm);
  });

  /* ── public API ───────────────────────────────────────────────── */
  window.AP = {
    openProductModal: function(){ openProductModal(null); },
    closeProductModal: closeProductModal,
    saveProduct: saveProduct,
    editProduct: editProduct,
    deleteProduct: deleteProduct,
    toggleIgnore: toggleIgnore,
    toggleUnavailable: toggleUnavailable,
    checkProduct: checkProduct,
    checkSiteAvailability: checkSiteAvailability,
    vetoProduct: vetoProduct,
    unvetoProduct: unvetoProduct,
    openVetoList: openVetoList,
    closeVetoList: closeVetoList,
    deleteSiteProduct: deleteSiteProduct,
    resolveProductUrl: resolveProductUrl,
    resolveBack: resolveBack,
    saveConfig: saveConfig,
    openAiModal: openAiModal,
    closeAiModal: closeAiModal,
    aiGenerate: aiGenerate,
    aiApprove: aiApprove,
    testAmazon: testAmazon,
    // Stores (inline-edit registry)
    addStoreRow: addStoreRow,
    deleteStore: deleteStore,
    bulkSeedStores: bulkSeedStores,
    bulkSeedFromTextarea: bulkSeedFromTextarea,
    gotoStoresSettings: gotoStoresSettings,
    openSiteProducts: openSiteProducts,
    closeSiteProducts: closeSiteProducts,
    runTask: runTask,
    deleteTask: deleteTask,
    // Prompt editor
    openPromptEditor: openPromptEditor,
    closePromptEditor: closePromptEditor,
    savePromptTask: savePromptTask,
    runPromptLive: runPromptLive,
    runPromptSandbox: runPromptSandbox,
    copyEmbedCode: copyEmbedCode,
    // Inline pipeline panel quick-actions
    openPromptForCurrentStore: openPromptForCurrentStore,
    runCurrentStoreLive: runCurrentStoreLive,
    runCurrentStoreSandbox: runCurrentStoreSandbox,
    // Sandbox preview
    cancelSandbox: cancelSandbox,
    exitSandbox: exitSandbox,
    promoteSandbox: promoteSandbox,
    discardSandbox: discardSandbox,
    vetoSandbox: vetoSandbox,
    promoteAllSandbox: promoteAllSandbox,
    // Quick prompt
    openQuickPrompt: openQuickPrompt,
    closeQuickPrompt: closeQuickPrompt,
    saveQuickPrompt: saveQuickPrompt
  };

  /* ── Refresh Schedule task actions ───────────────────────────── */
  function runTask(taskId){
    if (!confirm('Run task "' + taskId + '" now? It will run in the background.')) return;
    var fd = new FormData();
    fd.append('action', 'run_task_bg');
    fd.append('id', taskId);
    fetch('/admin/modules/AgentScheduler/api.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        alert(j.ok ? ('Task started in background: ' + taskId + '\n\nWatch progress in AgentScheduler.') : ('Run failed: ' + (j.message || j.error || 'unknown error')));
      })
      .catch(function(err){ alert('Network error: ' + err.message); });
  }

  function deleteTask(taskId){
    if (!confirm('Delete task "' + taskId + '"? This removes the recurring schedule entirely.')) return;
    var fd = new FormData();
    fd.append('action', 'delete_task');
    fd.append('id', taskId);
    fetch('/admin/modules/AgentScheduler/api.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) { alert('Delete failed: ' + (j.message || j.error || 'unknown')); return; }
        if (window.AP_loadDashboard) window.AP_loadDashboard();
      });
  }

})();
