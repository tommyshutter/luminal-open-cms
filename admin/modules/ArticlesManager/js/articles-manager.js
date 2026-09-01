(function () {
  const API = '/admin/modules/ArticlesManager/api.php';
  const $ = (id) => document.getElementById(id);
  let allArticles = [];

  async function call(action, params = {}, opts = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const init = { method: opts.method || 'GET' };
    if (opts.body) {
      init.method = 'POST';
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    const r = await fetch(API + '?' + qs.toString(), init);
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Request failed');
    return j.data;
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }

  function fmtDate(s) {
    if (!s) return '';
    const d = new Date(s);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function renderGrid(items) {
    const grid = $('amGrid');
    if (!items.length) {
      grid.innerHTML = '<div class="am-loading">No articles match the current filter.</div>';
      return;
    }
    grid.innerHTML = items.map(a => {
      const hero = a.hero_image ? `background-image:url('${escapeHtml(a.hero_image)}')` : '';
      const badges = [];
      if (a.published) badges.push('<span class="am-badge am-badge--pub">live</span>');
      else badges.push('<span class="am-badge am-badge--draft">draft</span>');
      // Pin badge is a live toggle: always rendered, click flips the flag
      // right here (no PageManager round-trip). Unpinned = ghost style.
      badges.push('<button type="button" class="am-badge am-badge--pin am-pin-toggle' + (a.pinned ? ' is-pinned' : '')
        + '" data-slug="' + escapeHtml(a.slug) + '" data-pinned="' + (a.pinned ? '1' : '0')
        + '" title="' + (a.pinned ? 'Click to unpin' : 'Click to pin') + '">\u{1F4CC} ' + (a.pinned ? 'pinned' : 'pin') + '</button>');
      return `
        <div class="am-acard" data-slug="${escapeHtml(a.slug)}">
          <div class="am-acard-hero" style="${hero}">
            <div class="am-acard-badges">${badges.join('')}</div>
          </div>
          <div class="am-acard-body">
            ${a.eyebrow ? `<div class="am-acard-eyebrow">${escapeHtml(a.eyebrow)}</div>` : ''}
            <h3 class="am-acard-title">${escapeHtml(a.title || '(untitled)')}</h3>
            <div class="am-acard-slug" title="${escapeHtml(a.slug)}">/${escapeHtml(a.slug)}</div>
            ${a.excerpt ? `<p class="am-acard-excerpt">${escapeHtml(a.excerpt).slice(0, 180)}</p>` : ''}
          </div>
          <div class="am-acard-footer">
            <span>${fmtDate(a.date)}</span>
            <span class="am-inject-chips">
              <button type="button" class="am-inject-chip am-inject-toggle${a._inject && a._inject.affiliate ? ' is-on' : ''}" data-slug="${escapeHtml(a.slug)}" data-field="affiliate" title="Affiliate right column — toggle for this article">🛒</button>
              <button type="button" class="am-inject-chip am-inject-toggle${a._inject && a._inject.ucs ? ' is-on' : ''}" data-slug="${escapeHtml(a.slug)}" data-field="ucs" title="Universal Content Stack right column — toggle for this article">🧩</button>
            </span>
          </div>
        </div>
      `;
    }).join('');
    Array.from(grid.querySelectorAll('.am-acard')).forEach(el => {
      el.addEventListener('click', () => openEdit(el.dataset.slug));
    });
    // Pin toggle — must not bubble into the card's open-edit click.
    Array.from(grid.querySelectorAll('.am-pin-toggle')).forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const slug = btn.dataset.slug;
        const next = btn.dataset.pinned !== '1';
        btn.disabled = true;
        try {
          await call('pin', { slug, pinned: next ? '1' : 'false' });
          const art = allArticles.find(x => x.slug === slug);
          if (art) art.pinned = next;
          applyFilter();                      // re-render grid with new state
          const stats = await call('stats');  // keep the Pinned counter honest
          $('amStatPinned').textContent = stats.pinned;
        } catch (err) {
          btn.disabled = false;
          alert('Pin toggle failed: ' + err.message);
        }
      });
    });
    // Per-card injection quick-toggles (🛒 affiliate / 🧩 UCS) — must not open the editor.
    Array.from(grid.querySelectorAll('.am-inject-toggle')).forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault(); e.stopPropagation();
        const next = btn.classList.contains('is-on') ? 'off' : 'on';
        btn.disabled = true;
        try {
          await call('set_item_injection', { slug: btn.dataset.slug, field: btn.dataset.field, value: next });
          btn.classList.toggle('is-on', next === 'on');
        } catch (err) { alert('Toggle failed: ' + err.message); }
        btn.disabled = false;
      });
    });
  }


  /* ---- List mode + bulk selection -------------------------------------
     Sites are heading past 30 articles; a grid of cards cannot be scanned or
     acted on in bulk. List mode is the same data as a table, with selection.
     Selection survives filtering (it is keyed by slug), so you can search,
     tick, search again, and delete the union. */
  let viewMode  = (function () { try { return localStorage.getItem('amViewMode') || 'grid'; } catch (e) { return 'grid'; } })();
  let lastItems = [];
  const selected = new Set();

  function renderList(items) {
    const grid = $('amGrid');
    if (!items.length) {
      grid.innerHTML = '<div class="am-loading">No articles match the current filter.</div>';
      return;
    }
    const rows = items.map(a => {
      const sl = escapeHtml(a.slug);
      const on = selected.has(a.slug);
      return `
        <tr data-slug="${sl}"${on ? ' class="is-selected"' : ''}>
          <td class="am-l-check"><input type="checkbox" class="am-l-cb" data-slug="${sl}"${on ? ' checked' : ''}></td>
          <td><span class="am-l-title" data-slug="${sl}">${escapeHtml(a.title || '(untitled)')}</span>
              <div class="am-l-slug">/${sl}</div></td>
          <td class="am-l-status">${a.published
              ? '<span class="am-badge am-badge--pub">live</span>'
              : '<span class="am-badge am-badge--draft">draft</span>'}${a.pinned ? ' \u{1F4CC}' : ''}</td>
          <td class="am-l-date">${fmtDate(a.date)}</td>
        </tr>`;
    }).join('');
    grid.innerHTML = `<table class="am-list">
        <thead><tr><th class="am-l-check"></th><th>Title</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>${rows}</tbody></table>`;
  }

  function syncBulkBar() {
    const bar = $('amBulkBar');
    if (!bar) return;
    bar.hidden = (viewMode !== 'list');
    const n = selected.size;
    const c = $('amBulkCount'); if (c) c.textContent = n + ' selected';
    const b = $('amBulkDelete'); if (b) b.disabled = (n === 0);
    const all = $('amSelectAll');
    if (all) all.checked = lastItems.length > 0 && lastItems.every(a => selected.has(a.slug));
  }

  function setViewMode(mode) {
    viewMode = mode;
    try { localStorage.setItem('amViewMode', mode); } catch (e) {}
    const g = $('amViewGrid'), l = $('amViewList');
    if (g && l) {
      g.classList.toggle('is-active', mode === 'grid');
      l.classList.toggle('is-active', mode === 'list');
      g.setAttribute('aria-pressed', mode === 'grid');
      l.setAttribute('aria-pressed', mode === 'list');
    }
    applyFilter();
  }

  async function bulkDelete() {
    const slugs = [...selected];
    if (!slugs.length) return;
    if (!confirm(`Delete ${slugs.length} article${slugs.length === 1 ? '' : 's'}?\n\n`
               + `They are archived, not permanently removed.`)) return;
    try {
      const r = await call('delete_bulk', { slugs: slugs.join(',') });
      selected.clear();
      if (r && r.not_found && r.not_found.length) {
        alert(`Deleted ${r.count}. Not found: ${r.not_found.join(', ')}`);
      }
      await loadAll();
    } catch (e) {
      alert('Bulk delete failed: ' + e.message);
    }
  }

  function applyFilter() {
    const q = $('amSearch').value.toLowerCase();
    const status = $('amFilterStatus').value;
    let items = allArticles;
    if (status === 'published') items = items.filter(a => a.published);
    else if (status === 'draft') items = items.filter(a => !a.published);
    if (q) {
      items = items.filter(a =>
        (a.title || '').toLowerCase().includes(q) ||
        (a.eyebrow || '').toLowerCase().includes(q) ||
        (a.excerpt || '').toLowerCase().includes(q) ||
        (a.tags || []).some(t => (t || '').toLowerCase().includes(q))
      );
    }
    lastItems = items;
    if (viewMode === 'list') renderList(items); else renderGrid(items);
    syncBulkBar();
  }

  async function loadAll() {
    try {
      allArticles = await call('list');
      applyFilter();
      const stats = await call('stats');
      $('amStatTotal').textContent = stats.total;
      $('amStatPublished').textContent = stats.published;
      $('amStatDraft').textContent = stats.draft;
      $('amStatPinned').textContent = stats.pinned;
    } catch (e) {
      $('amGrid').innerHTML = `<div class="am-loading">Error: ${escapeHtml(e.message)}</div>`;
    }
  }

  // Editing lives in Page Manager now — redirect there with ?edit_article=slug
  // so PM's modal auto-opens via its normal flow. Keeps a single editor
  // surface for pages + articles and retains all PM features (iframe preview,
  // WYSIWYG, shortcode picker, autosave) for articles too.
  const PM_URL = '/admin/modules/PageManager/PageManager.php';
  function openEdit(slug) {
    window.location.href = PM_URL + '?edit_article=' + encodeURIComponent(slug);
  }

  function openNew() {
    window.location.href = PM_URL + '?new_article=1';
  }

  function closeModal() { $('amModal').classList.remove('open'); }

  async function saveArticle() {
    const body = {
      slug: $('amSlug').value.trim(),
      title: $('amTitle').value.trim(),
      eyebrow: $('amEyebrow').value.trim(),
      excerpt: $('amExcerpt').value.trim(),
      body_html: $('amBody').value,
      hero_image: $('amHero').value.trim(),
      category: $('amCategory').value.trim(),
      tags: $('amTags').value.split(',').map(t => t.trim()).filter(Boolean),
      author: $('amAuthor').value.trim(),
      published: $('amPublished').checked,
      pinned: $('amPinned').checked,
      source: 'manual',
    };
    if (!body.slug || !body.title) { alert('Slug and title are required.'); return; }

    // If the slug was renamed, delete the old record first (rare case)
    const orig = $('amOriginalSlug').value;
    if (orig && orig !== body.slug) {
      if (!confirm('You changed the slug. This will DELETE the old record and create a new one. Continue?')) return;
      try { await call('delete', { slug: orig }); } catch (e) { /* ignore */ }
    }

    try {
      await call('save', {}, { body });
      closeModal();
      await loadAll();
    } catch (e) {
      alert('Save failed: ' + e.message);
    }
  }

  async function deleteArticle() {
    const slug = $('amOriginalSlug').value;
    if (!slug) return;
    if (!confirm(`Delete article "${slug}"? It will be archived, not permanently removed.`)) return;
    try {
      await call('delete', { slug });
      closeModal();
      await loadAll();
    } catch (e) {
      alert('Delete failed: ' + e.message);
    }
  }

  // ── Article Right-Column Defaults (global; writes content_injection.article) ──
  async function openInject() {
    const st = $('amInjStatus'); if (st) { st.textContent = ''; st.className = 'am-universal-status'; }
    try {
      const d = await call('article_injection_get');
      const c = d.config || {};
      $('amInjAffiliate').checked = !!c.affiliate;
      $('amInjUcs').checked = !!c.ucs;
      $('amInjColumns').value = String(c.columns || 1);
      const sel = $('amInjUcsSlug');
      sel.innerHTML = (d.stacks || []).map(s => `<option value="${escapeHtml(s.slug)}">${escapeHtml(s.label)} (${escapeHtml(s.slug)})</option>`).join('') || '<option value="">— no content stacks yet —</option>';
      sel.value = d.ucs_slug || '';
      $('amInjectModal').classList.add('open');
    } catch (e) { alert('Could not load article defaults: ' + e.message); }
  }
  function closeInject() { $('amInjectModal').classList.remove('open'); }
  async function saveInject() {
    const st = $('amInjStatus');
    try {
      const body = { affiliate: $('amInjAffiliate').checked, ucs: $('amInjUcs').checked, columns: $('amInjColumns').value, ucs_slug: $('amInjUcsSlug').value };
      await call('article_injection_save', {}, { body });
      st.textContent = 'Saved — applies to all articles.'; st.className = 'am-universal-status is-ok';
      setTimeout(closeInject, 1000);
      loadAll();   // refresh cards so the chips reflect the new default
    } catch (e) { st.textContent = 'Save failed: ' + e.message; st.className = 'am-universal-status is-error'; }
  }

  async function migrateLegacy() {
    if (!confirm('Migrate existing articles from Articles/index.json, page bodies, article-archive, and the hand-curated /articles page into ArticlesManager? Safe to re-run.')) return;
    try {
      const r = await call('migrate');
      alert(`Migrated ${r.total_saved} articles.\npipeline_index=${r.pipeline_index}, page_bodies=${r.page_bodies}, article_archive=${r.article_archive}, hand_curated=${r.hand_curated}`);
      await loadAll();
      const btn = $('amMigrateBtn');
      if (btn) btn.style.display = 'none';
    } catch (e) {
      alert('Migration failed: ' + e.message);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('amSearch').addEventListener('input', applyFilter);
    $('amFilterStatus').addEventListener('change', applyFilter);
    $('amNewBtn').addEventListener('click', openNew);
    $('amModalClose').addEventListener('click', closeModal);
    $('amCancelBtn').addEventListener('click', closeModal);
    $('amSaveBtn').addEventListener('click', saveArticle);
    $('amDeleteBtn').addEventListener('click', deleteArticle);
    const mig = $('amMigrateBtn');
    if (mig) mig.addEventListener('click', migrateLegacy);
    $('amInjectBtn').addEventListener('click', openInject);
    $('amInjectClose').addEventListener('click', closeInject);
    $('amInjCancel').addEventListener('click', closeInject);
    $('amInjSave').addEventListener('click', saveInject);
    loadAll();
  });
})();

  // list-mode wiring
  (function () {
    const g = $('amViewGrid'), l = $('amViewList');
    if (g) g.addEventListener('click', () => setViewMode('grid'));
    if (l) l.addEventListener('click', () => setViewMode('list'));
    const bd = $('amBulkDelete'); if (bd) bd.addEventListener('click', bulkDelete);
    const bc = $('amBulkClear');  if (bc) bc.addEventListener('click', () => { selected.clear(); applyFilter(); });
    const sa = $('amSelectAll');
    if (sa) sa.addEventListener('change', () => {
      if (sa.checked) lastItems.forEach(a => selected.add(a.slug));
      else lastItems.forEach(a => selected.delete(a.slug));
      applyFilter();
    });
    const grid = $('amGrid');
    if (grid) grid.addEventListener('click', (ev) => {
      const cb = ev.target.closest('.am-l-cb');
      if (cb) {
        if (cb.checked) selected.add(cb.dataset.slug); else selected.delete(cb.dataset.slug);
        const tr = cb.closest('tr'); if (tr) tr.classList.toggle('is-selected', cb.checked);
        syncBulkBar();
        return;
      }
      const t = ev.target.closest('.am-l-title');
      if (t) openEdit(t.dataset.slug);
    });
    setViewMode(viewMode);
  })();
