/**
 * SocialPublisher — Admin Dashboard JS
 * Provider management, compose posts, AI generation, media browser, post history
 */
(function(){
  'use strict';

  const API = '/admin/modules/SocialPublisher/api.php';
  const $ = id => document.getElementById(id);

  let providers = [];
  let historyPage = 1;

  // ── API Helper ────────────────────────────────────────────

  async function api(action, data) {
    const opts = { method: 'POST', headers: { 'Accept': 'application/json' } };
    if (data instanceof FormData) {
      opts.body = data;
    } else if (data && typeof data === 'object') {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
    const url = API + '?action=' + encodeURIComponent(action);
    const res = await fetch(url, opts);
    return res.json();
  }

  async function apiGet(action, params) {
    const qs = new URLSearchParams({ action, ...params });
    const res = await fetch(API + '?' + qs.toString(), { headers: { 'Accept': 'application/json' } });
    return res.json();
  }

  // ── Utility ───────────────────────────────────────────────

  function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
      ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  function platformIcon(platform) {
    if (platform === 'facebook') return '<span class="sp-platform-icon facebook">f</span>';
    if (platform === 'twitter') return '<span class="sp-platform-icon twitter">𝕏</span>';
    return '<span class="sp-platform-icon">?</span>';
  }

  function platformBadge(platform) {
    const label = platform === 'facebook' ? 'FB' : (platform === 'twitter' ? 'X' : platform);
    return '<span class="sp-platform-badge ' + esc(platform) + '">' + esc(label) + '</span>';
  }

  // ── Tab Switching ─────────────────────────────────────────

  document.querySelectorAll('.sp-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.sp-tab').forEach(t => t.classList.remove('sp-tab-active'));
      tab.classList.add('sp-tab-active');
      const target = tab.getAttribute('data-tab');
      document.querySelectorAll('[data-panel]').forEach(p => {
        p.style.display = p.getAttribute('data-panel') === target ? '' : 'none';
      });
      if (target === 'history') loadHistory();
      if (target === 'compose') renderComposeProviders();
    });
  });

  // ── Providers ─────────────────────────────────────────────

  async function loadProviders() {
    const res = await apiGet('list_providers');
    providers = res.ok ? (res.providers || []) : [];
    renderProviders();
  }

  function renderProviders() {
    const grid = $('spProviderGrid');
    if (!providers.length) {
      grid.innerHTML = '<div class="sp-empty">No providers configured. Click "+ Add Provider" to get started.</div>';
      return;
    }

    grid.innerHTML = providers.map(p => {
      const statusBadge = p.enabled
        ? '<span class="sp-badge sp-badge-enabled">Enabled</span>'
        : '<span class="sp-badge sp-badge-disabled">Disabled</span>';
      const lastPost = p.last_post_at ? fmtDate(p.last_post_at) : 'Never';

      return '<div class="sp-provider-card" data-id="' + esc(p.id) + '">' +
        '<div class="sp-provider-card-header">' +
          '<h4>' + platformIcon(p.platform) + ' ' + esc(p.name) + '</h4>' +
          statusBadge +
        '</div>' +
        '<div class="sp-provider-card-meta">' +
          '<span>Platform: ' + esc(p.platform) + '</span>' +
          '<span>Last post: ' + esc(lastPost) + '</span>' +
        '</div>' +
        '<div class="sp-provider-card-actions">' +
          '<button class="sp-btn sp-btn-sm" onclick="spEditProvider(\'' + esc(p.id) + '\')">Edit</button>' +
          '<button class="sp-btn sp-btn-sm sp-btn-test" onclick="spTestProvider(\'' + esc(p.id) + '\',this)">Test</button>' +
          '<button class="sp-btn sp-btn-sm sp-btn-danger" onclick="spDeleteProvider(\'' + esc(p.id) + '\')">Delete</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  // ── Provider Modal ────────────────────────────────────────

  const providerModal = $('spProviderModal');

  function openProviderModal(provider) {
    $('spProviderModalTitle').textContent = provider ? 'Edit Provider' : 'Add Provider';
    $('spProvId').value = provider?.id || '';
    $('spProvPlatform').value = provider?.platform || '';
    $('spProvName').value = provider?.name || '';
    $('spTestResult').textContent = '';
    $('spTestResult').className = 'sp-test-result';

    // Platform fields
    syncPlatformFields();

    if (provider) {
      const c = provider.credentials || {};
      if (provider.platform === 'facebook') {
        $('spFbPageId').value = c.page_id || '';
        $('spFbAccessToken').value = c.access_token || '';
        $('spFbPageName').value = c.page_name || '';
      } else if (provider.platform === 'twitter') {
        $('spTwApiKey').value = c.api_key || '';
        $('spTwApiSecret').value = c.api_secret || '';
        $('spTwAccessToken').value = c.access_token || '';
        $('spTwAccessTokenSecret').value = c.access_token_secret || '';
      }
    } else {
      // Clear all credential fields
      ['spFbPageId','spFbAccessToken','spFbPageName','spTwApiKey','spTwApiSecret','spTwAccessToken','spTwAccessTokenSecret']
        .forEach(id => { if ($(id)) $(id).value = ''; });
    }

    providerModal.classList.add('open');
  }

  function closeProviderModal() {
    providerModal.classList.remove('open');
  }

  function syncPlatformFields() {
    const platform = $('spProvPlatform').value;
    $('spFbFields').style.display = platform === 'facebook' ? '' : 'none';
    $('spTwFields').style.display = platform === 'twitter' ? '' : 'none';
  }

  $('spProvPlatform').addEventListener('change', syncPlatformFields);

  $('spAddProvider').addEventListener('click', () => openProviderModal(null));
  $('spProviderModalClose').addEventListener('click', closeProviderModal);
  $('spProviderCancel').addEventListener('click', closeProviderModal);

  // Save provider
  $('spProviderSave').addEventListener('click', async () => {
    const platform = $('spProvPlatform').value;
    const name = $('spProvName').value.trim();
    if (!platform) { alert('Select a platform'); return; }
    if (!name) { alert('Enter a display name'); return; }

    let credentials = {};
    if (platform === 'facebook') {
      credentials = {
        page_id: $('spFbPageId').value.trim(),
        access_token: $('spFbAccessToken').value.trim(),
        page_name: $('spFbPageName').value.trim(),
      };
      if (!credentials.page_id || !credentials.access_token) { alert('Page ID and Access Token are required'); return; }
    } else if (platform === 'twitter') {
      credentials = {
        api_key: $('spTwApiKey').value.trim(),
        api_secret: $('spTwApiSecret').value.trim(),
        access_token: $('spTwAccessToken').value.trim(),
        access_token_secret: $('spTwAccessTokenSecret').value.trim(),
      };
      if (!credentials.api_key || !credentials.api_secret || !credentials.access_token || !credentials.access_token_secret) {
        alert('All four OAuth credentials are required'); return;
      }
    }

    const data = {
      id: $('spProvId').value || '',
      name,
      platform,
      credentials,
      enabled: true,
    };

    const res = await api('save_provider', data);
    if (res.ok) {
      closeProviderModal();
      loadProviders();
    } else {
      alert('Error: ' + (res.error || 'Save failed'));
    }
  });

  // Test provider (from modal)
  $('spTestProvider').addEventListener('click', async () => {
    const id = $('spProvId').value;
    if (!id) {
      // Must save first before testing
      alert('Save the provider first, then test.');
      return;
    }
    const resultEl = $('spTestResult');
    resultEl.textContent = 'Testing...';
    resultEl.className = 'sp-test-result sp-test-loading';

    const res = await api('test_provider', { id });
    resultEl.textContent = res.message || (res.ok ? 'Connected!' : 'Failed');
    resultEl.className = 'sp-test-result ' + (res.ok ? 'sp-test-ok' : 'sp-test-err');
  });

  // Global functions for inline onclick handlers
  window.spEditProvider = function(id) {
    const p = providers.find(x => x.id === id);
    if (p) openProviderModal(p);
  };

  window.spTestProvider = async function(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Testing...';
    const res = await api('test_provider', { id });
    btn.textContent = res.ok ? 'OK!' : 'Failed';
    btn.style.color = res.ok ? '#81c784' : '#ef9a9a';
    setTimeout(() => { btn.textContent = 'Test'; btn.style.color = ''; btn.disabled = false; }, 2000);
  };

  window.spDeleteProvider = async function(id) {
    if (!confirm('Delete this provider?')) return;
    const res = await api('delete_provider', { id });
    if (res.ok) loadProviders();
  };

  // ── Compose Tab ───────────────────────────────────────────

  function renderComposeProviders() {
    const container = $('spComposeProviders');
    const enabledProviders = providers.filter(p => p.enabled);
    if (!enabledProviders.length) {
      container.innerHTML = '<span class="sp-muted">No enabled providers. Add one in the Providers tab.</span>';
      return;
    }
    container.innerHTML = enabledProviders.map(p =>
      '<label class="sp-provider-check">' +
        '<input type="checkbox" value="' + esc(p.id) + '" checked>' +
        platformIcon(p.platform) + ' ' + esc(p.name) +
      '</label>'
    ).join('');
  }

  // Character counter
  $('spContent').addEventListener('input', updateCharCount);
  function updateCharCount() {
    const len = $('spContent').value.length;
    const el = $('spCharCount');
    el.textContent = len + ' chars';
    el.className = 'sp-char-count' + (len > 280 ? ' sp-char-warn' : '') + (len > 500 ? ' sp-char-over' : '');
  }

  // AI Toggle
  $('spAiToggle').addEventListener('change', () => {
    $('spAiSection').style.display = $('spAiToggle').checked ? '' : 'none';
  });

  // AI Generate Content
  $('spAiGenerate').addEventListener('click', async () => {
    const prompt = $('spAiPrompt').value.trim();
    if (!prompt) { alert('Enter an AI prompt'); return; }
    const btn = $('spAiGenerate');
    btn.disabled = true;
    btn.textContent = 'Generating...';

    const res = await api('generate_content', { prompt, tone: $('spAiTone').value });
    if (res.ok && res.content) {
      $('spContent').value = res.content;
      updateCharCount();
    } else {
      alert('AI generation failed: ' + (res.error || 'Unknown error'));
    }
    btn.disabled = false;
    btn.textContent = 'Generate';
  });

  // AI Image toggle
  $('spAiImage').addEventListener('click', () => {
    const section = $('spAiImageSection');
    section.style.display = section.style.display === 'none' ? '' : 'none';
  });

  // AI Image Generate
  $('spAiImageGenerate').addEventListener('click', async () => {
    const prompt = $('spAiImagePrompt').value.trim();
    if (!prompt) { alert('Enter an image prompt'); return; }
    const btn = $('spAiImageGenerate');
    btn.disabled = true;
    btn.textContent = 'Generating...';

    const res = await api('generate_image', { prompt });
    if (res.ok && res.path) {
      setImagePreview(res.path);
      $('spAiImageSection').style.display = 'none';
    } else {
      alert('Image generation failed: ' + (res.error || 'Unknown error'));
    }
    btn.disabled = false;
    btn.textContent = 'Generate Image';
  });

  // Image preview management
  let currentImagePath = '';

  function setImagePreview(path) {
    currentImagePath = path;
    $('spImageUrl').value = '';
    $('spPreviewImg').src = path;
    $('spImagePreview').style.display = '';
    $('spDropZone').style.display = 'none';
  }

  function clearImagePreview() {
    currentImagePath = '';
    $('spPreviewImg').src = '';
    $('spImagePreview').style.display = 'none';
    $('spDropZone').style.display = '';
    $('spImageUrl').value = '';
  }

  $('spRemoveImage').addEventListener('click', clearImagePreview);

  // Image URL input — preview on blur if URL entered
  $('spImageUrl').addEventListener('change', () => {
    const url = $('spImageUrl').value.trim();
    if (url && /^https?:\/\//i.test(url)) {
      currentImagePath = '';
      $('spPreviewImg').src = url;
      $('spImagePreview').style.display = '';
      $('spDropZone').style.display = 'none';
    }
  });

  // ── Drag & Drop ───────────────────────────────────────────

  const dropZone = $('spDropZone');
  let dropCounter = 0;

  dropZone.addEventListener('dragenter', (e) => {
    e.preventDefault();
    dropCounter++;
    dropZone.classList.add('sp-drop-active');
  });

  dropZone.addEventListener('dragleave', () => {
    dropCounter--;
    if (dropCounter <= 0) { dropZone.classList.remove('sp-drop-active'); dropCounter = 0; }
  });

  dropZone.addEventListener('dragover', (e) => e.preventDefault());

  dropZone.addEventListener('drop', async (e) => {
    e.preventDefault();
    dropCounter = 0;
    dropZone.classList.remove('sp-drop-active');

    const file = e.dataTransfer.files[0];
    if (!file || !file.type.startsWith('image/')) { alert('Please drop an image file'); return; }

    dropZone.innerHTML = '<span class="sp-spinner"></span> Uploading...';

    const fd = new FormData();
    fd.append('image', file);
    const res = await api('upload_image', fd);

    dropZone.innerHTML = '<span>Drag & drop an image here</span>';

    if (res.ok && res.path) {
      setImagePreview(res.path);
    } else {
      alert('Upload failed: ' + (res.error || 'Unknown error'));
    }
  });

  // ── Media Browser ─────────────────────────────────────────

  const mediaModal = $('spMediaModal');

  $('spBrowseMedia').addEventListener('click', () => {
    $('spMediaFrame').src = '/admin/shared/explorer/media-explorer.php?host=socialpublisher';
    mediaModal.classList.add('open');
  });

  $('spMediaModalClose').addEventListener('click', () => {
    mediaModal.classList.remove('open');
    $('spMediaFrame').src = '';
  });

  // Listen for PostMessage from media browser iframe
  window.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'mediaSelected' && e.data.path) {
      setImagePreview(e.data.path);
      mediaModal.classList.remove('open');
      $('spMediaFrame').src = '';
    }
  });

  // ── Post Now ──────────────────────────────────────────────

  $('spPostNow').addEventListener('click', async () => {
    const content = $('spContent').value.trim();
    if (!content) { alert('Content is required'); return; }

    const checks = document.querySelectorAll('#spComposeProviders input[type="checkbox"]:checked');
    const providerIds = Array.from(checks).map(c => c.value);
    if (!providerIds.length) { alert('Select at least one provider'); return; }

    const btn = $('spPostNow');
    btn.disabled = true;
    btn.innerHTML = '<span class="sp-spinner"></span> Posting...';

    const imageUrl = $('spImageUrl').value.trim();
    const data = {
      provider_ids: providerIds,
      content,
      image_url: imageUrl || '',
      image_path: currentImagePath || '',
      link_url: $('spLinkUrl').value.trim(),
    };

    const res = await api('compose_post', data);
    const resultEl = $('spPostResult');
    resultEl.style.display = '';

    if (res.ok) {
      const results = res.results || [];
      const okCount = results.filter(r => r.ok).length;
      const failCount = results.length - okCount;

      if (failCount === 0) {
        resultEl.className = 'sp-post-result sp-result-ok';
        resultEl.innerHTML = 'Posted successfully to ' + okCount + ' platform(s)!';
      } else if (okCount > 0) {
        resultEl.className = 'sp-post-result sp-result-partial';
        resultEl.innerHTML = 'Partially posted: ' + okCount + ' succeeded, ' + failCount + ' failed.<br>' +
          results.filter(r => !r.ok).map(r => esc(r.provider_name) + ': ' + esc(r.message)).join('<br>');
      } else {
        resultEl.className = 'sp-post-result sp-result-err';
        resultEl.innerHTML = 'All posts failed:<br>' +
          results.map(r => esc(r.provider_name) + ': ' + esc(r.message)).join('<br>');
      }
    } else {
      resultEl.className = 'sp-post-result sp-result-err';
      resultEl.textContent = 'Error: ' + (res.error || 'Post failed');
    }

    btn.disabled = false;
    btn.innerHTML = 'Post Now';
  });

  // ── Post History ──────────────────────────────────────────

  async function loadHistory(page) {
    if (page) historyPage = page;
    const res = await apiGet('list_posts', { page: historyPage });
    if (!res.ok) {
      $('spHistoryBody').innerHTML = '<tr><td colspan="5" class="sp-empty">Error loading history</td></tr>';
      return;
    }

    const posts = res.posts || [];
    if (!posts.length) {
      $('spHistoryBody').innerHTML = '<tr><td colspan="5" class="sp-empty">No posts yet</td></tr>';
      $('spHistoryPagination').innerHTML = '';
      return;
    }

    $('spHistoryBody').innerHTML = posts.map(p => {
      const results = p.results || [];
      const platforms = results.map(r => platformBadge(r.platform || 'unknown')).join(' ');
      const statusClass = p.status === 'posted' ? 'sp-status-posted' : (p.status === 'partial' ? 'sp-status-partial' : 'sp-status-failed');
      const content = (p.content || '').substring(0, 80) + ((p.content || '').length > 80 ? '...' : '');

      return '<tr onclick="spViewPost(\'' + esc(p.id) + '\')">' +
        '<td>' + esc(fmtDate(p.created_at)) + '</td>' +
        '<td><div class="sp-platform-badges">' + platforms + '</div></td>' +
        '<td><div class="sp-content-preview">' + esc(content) + '</div></td>' +
        '<td><span class="sp-status-badge ' + statusClass + '">' + esc(p.status || 'unknown') + '</span></td>' +
        '<td><span class="sp-source-badge">' + esc(p.source || '') + '</span></td>' +
      '</tr>';
    }).join('');

    // Pagination
    const total = res.total || 0;
    const perPage = res.per_page || 20;
    const totalPages = Math.ceil(total / perPage);
    if (totalPages <= 1) {
      $('spHistoryPagination').innerHTML = '';
      return;
    }
    let pag = '';
    for (let i = 1; i <= totalPages; i++) {
      pag += '<button class="sp-page-btn' + (i === historyPage ? ' active' : '') + '" onclick="spHistoryPage(' + i + ')">' + i + '</button>';
    }
    $('spHistoryPagination').innerHTML = pag;
  }

  window.spHistoryPage = function(p) { loadHistory(p); };

  // ── Post Detail Modal ─────────────────────────────────────

  const postDetailModal = $('spPostDetailModal');

  window.spViewPost = async function(id) {
    const res = await apiGet('list_posts');
    if (!res.ok) return;
    const post = (res.posts || []).find(p => p.id === id);
    if (!post) return;

    let html = '<div class="sp-detail-row"><label>Date</label><div class="sp-detail-val">' + esc(fmtDate(post.created_at)) + '</div></div>';
    html += '<div class="sp-detail-row"><label>Source</label><div class="sp-detail-val">' + esc(post.source || '—') + '</div></div>';
    html += '<div class="sp-detail-row"><label>Content</label><div class="sp-detail-val">' + esc(post.content || '') + '</div></div>';

    if (post.image) {
      html += '<div class="sp-detail-row"><label>Image</label><div class="sp-detail-val"><img class="sp-detail-image" src="' + esc(post.image) + '"></div></div>';
    }
    if (post.link_url) {
      html += '<div class="sp-detail-row"><label>Link</label><div class="sp-detail-val"><a href="' + esc(post.link_url) + '" target="_blank" style="color:#4fc3f7">' + esc(post.link_url) + '</a></div></div>';
    }

    html += '<div class="sp-detail-row"><label>Results</label>';
    (post.results || []).forEach(r => {
      const icon = r.ok ? '<span class="sp-result-ok-icon">&#10004;</span>' : '<span class="sp-result-err-icon">&#10008;</span>';
      html += '<div class="sp-result-item">' + icon + ' ' + platformBadge(r.platform || '') + ' ' + esc(r.provider_name || r.provider_id || '') + ' — ' + esc(r.message || '') +
        (r.post_id ? ' <span class="sp-muted">(ID: ' + esc(r.post_id) + ')</span>' : '') + '</div>';
    });
    html += '</div>';

    $('spPostDetailBody').innerHTML = html;
    postDetailModal.classList.add('open');
  };

  $('spPostDetailClose').addEventListener('click', () => postDetailModal.classList.remove('open'));

  $('spRefreshHistory').addEventListener('click', () => loadHistory());

  // ── Modal Relocation ──────────────────────────────────────
  // Move modals to body to escape any stacking context issues
  [providerModal, mediaModal, postDetailModal].forEach(el => {
    if (el && el.parentNode !== document.body) document.body.appendChild(el);
  });

  // Close modals on ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      [providerModal, mediaModal, postDetailModal].forEach(m => m.classList.remove('open'));
      $('spMediaFrame').src = '';
    }
  });

  // Close modals on overlay click
  [providerModal, mediaModal, postDetailModal].forEach(modal => {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.classList.remove('open');
        if (modal === mediaModal) $('spMediaFrame').src = '';
      }
    });
  });

  // ── Init ──────────────────────────────────────────────────

  loadProviders();

})();
