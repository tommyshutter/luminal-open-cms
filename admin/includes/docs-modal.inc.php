<?php
/**
 * Luminal CMS — Documentation Help Modal
 * Self-contained include: HTML shell + CSS + JS
 * Fetches the docs index from this site's own /admin/api/docs.php, renders card grid.
 * Clicking a card loads doc content IN the modal (no external navigation).
 * Collapsible h2 sections for structured reading.
 * @file /admin/includes/docs-modal.inc.php
 */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<div id="ldm-overlay" class="ldm-overlay" style="display:none"></div>
<div id="ldm-modal" class="ldm-modal" style="display:none">
  <div class="ldm-header">
    <div class="ldm-header-left">
      <button class="ldm-back" id="ldmBack" title="Back to index" style="display:none">
        <i class="fa-solid fa-arrow-left"></i> <span>All Topics</span>
      </button>
      <i class="fa-solid fa-book-open" id="ldmHeaderIcon"></i>
      <span class="ldm-title" id="ldmTitle">Luminal CMS Documentation</span>
    </div>
    <div class="ldm-header-right">
      <button class="ldm-expand-all" id="ldmExpandAll" title="Expand / collapse all sections" style="display:none">
        <i class="fa-solid fa-bars-staggered"></i> <span>Expand All</span>
      </button>
      <button class="ldm-close" id="ldmClose" title="Exit Documentation">
        <span>Exit</span> <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
  <div class="ldm-body" id="ldmBody">
    <div class="ldm-loading" id="ldmLoading">
      <div class="ldm-shimmer-grid">
        <div class="ldm-shimmer-card"></div>
        <div class="ldm-shimmer-card"></div>
        <div class="ldm-shimmer-card"></div>
        <div class="ldm-shimmer-card"></div>
        <div class="ldm-shimmer-card"></div>
        <div class="ldm-shimmer-card"></div>
      </div>
    </div>
    <div class="ldm-grid" id="ldmGrid" style="display:none"></div>
    <div class="ldm-content" id="ldmContent" style="display:none"></div>
    <div class="ldm-error" id="ldmError" style="display:none">
      <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;color:#b91c1c;margin-bottom:0.75rem"></i>
      <p style="margin:0 0 0.75rem;color:#78716c">Could not load documentation. Please try again.</p>
    </div>
  </div>
  <div class="ldm-footer">
    <span style="color:#a8a29e;font-size:0.72rem;font-family:'Inter',system-ui,sans-serif;letter-spacing:.5px">Powered by</span>
    <svg viewBox="0 0 100 18" width="72" style="vertical-align:middle;margin-left:4px">
      <text x="50" y="14" text-anchor="middle" font-family="Inter,system-ui,sans-serif" font-size="13" font-weight="700" letter-spacing="3.5" fill="#78716c">LUMINAL</text>
    </svg>
  </div>
</div>

<style>
/* ── Luminal Docs Modal — O'Reilly Book Theme (ldm- prefix) ──────── */
:root {
  --ldm-font: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --ldm-mono: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  --ldm-cream: #faf7f2;
  --ldm-paper: #fffdf9;
  --ldm-ink: #1c1917;
  --ldm-ink-heading: #0c0a09;
  --ldm-ink-body: #292524;
  --ldm-ink-muted: #78716c;
  --ldm-ink-faint: #a8a29e;
  --ldm-border: rgba(120,113,108,.18);
  --ldm-accent: #92400e;
  --ldm-accent-light: rgba(146,64,14,.06);
  --ldm-code-bg: #f5f0e8;
  --ldm-code-text: #78350f;
  --ldm-tip-bg: rgba(22,101,52,.05);
  --ldm-tip-border: #166534;
  --ldm-warn-bg: rgba(153,27,27,.04);
  --ldm-warn-border: #991b1b;
}

.ldm-overlay{position:fixed;inset:0;background:rgba(28,25,23,.5);backdrop-filter:blur(3px);z-index:10002;opacity:0;transition:opacity .25s ease}
.ldm-overlay.ldm-visible{opacity:1}

.ldm-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(.97);z-index:10003;width:min(900px,93vw);max-height:88vh;background:var(--ldm-cream);border:1px solid var(--ldm-border);border-radius:6px;box-shadow:0 12px 48px rgba(28,25,23,.2),0 1px 3px rgba(28,25,23,.08);display:flex;flex-direction:column;opacity:0;transition:opacity .25s ease,transform .25s ease}
.ldm-modal.ldm-visible{opacity:1;transform:translate(-50%,-50%) scale(1)}

/* ── Header ──────────────────────────────────────────────────────── */
.ldm-header{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;border-bottom:1px solid var(--ldm-border);min-height:48px;background:var(--ldm-cream)}
.ldm-header-left{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.ldm-header-right{display:flex;align-items:center;gap:6px;flex-shrink:0}
.ldm-title{font-family:var(--ldm-font);font-size:1rem;font-weight:600;color:var(--ldm-ink-heading);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#ldmHeaderIcon{color:var(--ldm-accent);font-size:.9rem}

.ldm-back{display:inline-flex;align-items:center;gap:5px;background:var(--ldm-accent-light);border:1px solid rgba(146,64,14,.15);color:var(--ldm-accent);font-family:var(--ldm-font);font-size:.8rem;font-weight:500;cursor:pointer;padding:5px 12px;border-radius:4px;transition:all .15s;flex-shrink:0}
.ldm-back:hover{border-color:rgba(146,64,14,.3);background:rgba(146,64,14,.1)}
.ldm-back i{font-size:.7rem}

.ldm-expand-all{display:inline-flex;align-items:center;gap:5px;background:transparent;border:1px solid var(--ldm-border);color:var(--ldm-ink-muted);font-family:var(--ldm-font);font-size:.78rem;font-weight:500;cursor:pointer;padding:5px 10px;border-radius:4px;transition:all .15s}
.ldm-expand-all:hover{border-color:var(--ldm-accent);color:var(--ldm-accent)}


.ldm-close{display:inline-flex;align-items:center;gap:4px;background:transparent;border:1px solid var(--ldm-border);color:var(--ldm-ink-muted);font-family:var(--ldm-font);font-size:.8rem;font-weight:500;cursor:pointer;padding:5px 12px;border-radius:4px;transition:all .15s;line-height:1.2}
.ldm-close:hover{background:rgba(153,27,27,.05);border-color:rgba(153,27,27,.2);color:#991b1b}
.ldm-close i{font-size:.75rem}

/* ── Body ────────────────────────────────────────────────────────── */
.ldm-body{flex:1;overflow-y:auto;padding:28px 40px;background:var(--ldm-paper);scrollbar-width:thin;scrollbar-color:rgba(120,113,108,.15) transparent}
.ldm-body::-webkit-scrollbar{width:5px}
.ldm-body::-webkit-scrollbar-thumb{background:rgba(120,113,108,.15);border-radius:3px}

/* ── Footer ──────────────────────────────────────────────────────── */
.ldm-footer{display:flex;align-items:center;justify-content:center;padding:8px 24px;border-top:1px solid var(--ldm-border);gap:4px;background:var(--ldm-cream)}

/* ── Card Grid ───────────────────────────────────────────────────── */
.ldm-grid{display:block}
.ldm-group{margin-bottom:22px}
.ldm-group:last-child{margin-bottom:4px}
.ldm-group-head{display:flex;align-items:center;gap:8px;font-family:var(--ldm-font);font-weight:700;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--ldm-ink-muted);padding:0 2px 8px;margin-bottom:12px;border-bottom:1px solid var(--ldm-border)}
.ldm-group-head i{color:var(--ldm-accent);font-size:.78rem}
.ldm-group-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.ldm-card{display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--ldm-paper);border:1px solid var(--ldm-border);border-radius:4px;text-decoration:none;color:var(--ldm-ink-body);transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;cursor:pointer;position:relative}
.ldm-card:hover{transform:translateY(-2px);border-color:rgba(146,64,14,.25);box-shadow:0 4px 16px rgba(28,25,23,.08)}
.ldm-card-header{display:flex;align-items:center;gap:10px}
.ldm-card-icon{width:34px;height:34px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.ldm-card-title{font-family:var(--ldm-font);font-weight:600;font-size:.875rem;line-height:1.3;color:var(--ldm-ink-heading)}
.ldm-card-desc{font-family:var(--ldm-font);font-size:.78rem;color:var(--ldm-ink-muted);line-height:1.5;flex:1}
.ldm-card-arrow{font-size:.7rem;color:var(--ldm-ink-faint);text-align:right;transition:color .2s}
.ldm-card:hover .ldm-card-arrow{color:var(--ldm-accent)}

/* ── Doc Content View — Book Typography ──────────────────────────── */
.ldm-content{color:var(--ldm-ink-body);font-family:var(--ldm-font) !important;font-size:.9rem;line-height:1.75}

.ldm-content h1{font-family:var(--ldm-font) !important;font-size:1.5rem;color:var(--ldm-ink-heading);margin:0 0 .5rem;font-weight:700;letter-spacing:-.02em;line-height:1.3}
.ldm-content h1::after{content:'';display:block;width:48px;height:2px;background:var(--ldm-accent);margin-top:10px;opacity:.5}

/* ── Collapsible h2 sections ─────────────────────────────────────── */
.ldm-content h2{font-family:var(--ldm-font) !important;font-size:1.1rem;color:var(--ldm-ink-heading);margin:0;font-weight:600;padding:12px 0;cursor:pointer;display:flex;align-items:center;gap:8px;user-select:none;border-top:1px solid var(--ldm-border);line-height:1.4}
.ldm-content h2:first-of-type{border-top:none}
.ldm-content h2::before{content:'\f054';font-family:'Font Awesome 6 Free';font-weight:900;font-size:.6rem;color:var(--ldm-ink-faint);transition:transform .2s;flex-shrink:0;width:12px}
.ldm-content h2.ldm-open::before{transform:rotate(90deg);color:var(--ldm-accent)}
.ldm-content h2:hover{color:var(--ldm-accent)}

.ldm-section-body{overflow:hidden;transition:max-height .3s ease,opacity .2s ease;max-height:0;opacity:0;padding-left:20px}
.ldm-section-body.ldm-expanded{max-height:none;opacity:1;padding-bottom:8px}

.ldm-content h3{font-family:var(--ldm-font) !important;font-size:.95rem;color:var(--ldm-ink-heading);margin:1.25rem 0 .4rem;font-weight:600;line-height:1.4}
.ldm-content p{margin:0 0 .75rem}
.ldm-content ul,.ldm-content ol{margin:0 0 .75rem;padding-left:1.5rem}
.ldm-content li{margin-bottom:.3rem;line-height:1.7}

.ldm-content code{background:var(--ldm-code-bg);color:var(--ldm-code-text);padding:1px 5px;border-radius:3px;font-size:.8em;font-family:var(--ldm-mono)}
.ldm-content pre{background:#f7f3eb;border:1px solid var(--ldm-border);border-radius:4px;padding:14px 18px;overflow-x:auto;margin:0 0 .75rem;font-size:.78rem}
.ldm-content pre code{background:none;padding:0;color:var(--ldm-ink-body)}

.ldm-content strong{color:var(--ldm-ink-heading);font-weight:600}
.ldm-content a{color:var(--ldm-accent);text-decoration:underline;text-decoration-color:rgba(146,64,14,.25);text-underline-offset:2px;transition:text-decoration-color .15s}
.ldm-content a:hover{text-decoration-color:var(--ldm-accent)}

/* ── Callout boxes ───────────────────────────────────────────────── */
.ldm-content .doc-tip,.ldm-content .doc-warn{border-radius:0 4px 4px 0;padding:12px 16px;margin:0 0 .75rem;font-size:.86rem}
.ldm-content .doc-tip{background:var(--ldm-tip-bg);border-left:3px solid var(--ldm-tip-border)}
.ldm-content .doc-warn{background:var(--ldm-warn-bg);border-left:3px solid var(--ldm-warn-border)}
.ldm-content .doc-tip p,.ldm-content .doc-warn p{margin:0}

.ldm-content .doc-breadcrumb{display:none}
.ldm-content .doc-lead{color:var(--ldm-ink-muted);font-size:.92rem;margin:0 0 1.25rem;line-height:1.65;font-style:italic;padding-bottom:1rem;border-bottom:1px solid var(--ldm-border)}

/* ── Nav links ───────────────────────────────────────────────────── */
.ldm-content .doc-nav{display:flex;gap:8px;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--ldm-border);flex-wrap:wrap}
.ldm-content .doc-nav a{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;background:var(--ldm-cream);border:1px solid var(--ldm-border);border-radius:4px;color:var(--ldm-ink-heading);font-family:var(--ldm-font);font-size:.8rem;font-weight:500;text-decoration:none;transition:all .2s;cursor:pointer}
.ldm-content .doc-nav a:hover{border-color:var(--ldm-accent);color:var(--ldm-accent);background:var(--ldm-accent-light);text-decoration:none}
.ldm-content .doc-nav a i{font-size:.7rem;color:var(--ldm-accent)}

/* ── Override any per-page serif fonts ───────────────────────────── */
.ldm-content .doc-page,
.ldm-content .doc-page h1,
.ldm-content .doc-page h2,
.ldm-content .doc-page h3,
.ldm-content .doc-page p,
.ldm-content .doc-page li,
.ldm-content .doc-page a,
.ldm-content .doc-page .doc-nav a{font-family:var(--ldm-font) !important}

/* ── Screenshots ─────────────────────────────────────────────────── */
.ldm-content .doc-screenshot{display:block;width:100%;margin:1rem 0;border:1px solid var(--ldm-border);border-radius:4px;background:var(--ldm-cream);overflow:hidden}
.ldm-content .doc-screenshot img{display:block;width:100%;height:auto}
.ldm-content .doc-screenshot figcaption{text-align:center;font-size:.78rem;font-style:italic;color:var(--ldm-ink-muted);padding:6px 12px;border-top:1px solid var(--ldm-border)}

/* ── Shimmer ─────────────────────────────────────────────────────── */
.ldm-shimmer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.ldm-shimmer-card{height:100px;background:linear-gradient(90deg,rgba(120,113,108,.06) 25%,rgba(120,113,108,.12) 50%,rgba(120,113,108,.06) 75%);background-size:200% 100%;border-radius:4px;animation:ldm-shimmer 1.5s ease infinite}
@keyframes ldm-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

.ldm-content-loading{display:flex;flex-direction:column;gap:12px;padding:10px 0}
.ldm-content-loading .ldm-shimmer-line{height:14px;border-radius:3px;background:linear-gradient(90deg,rgba(120,113,108,.06) 25%,rgba(120,113,108,.12) 50%,rgba(120,113,108,.06) 75%);background-size:200% 100%;animation:ldm-shimmer 1.5s ease infinite}
.ldm-content-loading .ldm-shimmer-line:nth-child(1){width:55%}
.ldm-content-loading .ldm-shimmer-line:nth-child(2){width:100%}
.ldm-content-loading .ldm-shimmer-line:nth-child(3){width:80%}
.ldm-content-loading .ldm-shimmer-line:nth-child(4){width:92%}
.ldm-content-loading .ldm-shimmer-line:nth-child(5){width:65%}

/* ── Error ────────────────────────────────────────────────────────── */
.ldm-error{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:180px;text-align:center;font-family:var(--ldm-font)}

/* ── Responsive ──────────────────────────────────────────────────── */
@media(max-width:640px){
  .ldm-modal{width:96vw;max-height:92vh}
  .ldm-body{padding:20px 18px}
  .ldm-group-grid{grid-template-columns:1fr}
  .ldm-shimmer-grid{grid-template-columns:1fr}
  .ldm-title{font-size:.88rem}
  .ldm-content h1{font-size:1.25rem}
  .ldm-content h2{font-size:1rem}
  .ldm-section-body{padding-left:12px}
  .ldm-expand-all span{display:none}
}
</style>

<script>
(function(){
  var API_URL = '/admin/api/docs.php';
  var overlay  = document.getElementById('ldm-overlay');
  var modal    = document.getElementById('ldm-modal');
  var grid     = document.getElementById('ldmGrid');
  var content  = document.getElementById('ldmContent');
  var loading  = document.getElementById('ldmLoading');
  var error    = document.getElementById('ldmError');
  var closeBtn = document.getElementById('ldmClose');
  var backBtn  = document.getElementById('ldmBack');
  var titleEl  = document.getElementById('ldmTitle');
  var iconEl   = document.getElementById('ldmHeaderIcon');
  var expandBtn= document.getElementById('ldmExpandAll');
  var body     = document.getElementById('ldmBody');

  var indexFetched = false;
  var docsCache    = null;
  var pageCache    = {};
  var currentView  = 'index';
  var allExpanded  = false;

  /* ── Relocate to document.body (escape stacking context) ── */
  document.body.appendChild(overlay);
  document.body.appendChild(modal);

  /* ── Open / Close ── */
  function openDocsModal(){
    overlay.style.display = 'block';
    modal.style.display   = 'flex';
    requestAnimationFrame(function(){
      overlay.classList.add('ldm-visible');
      modal.classList.add('ldm-visible');
    });
    if(!indexFetched) loadIndex();
  }

  function closeDocsModal(){
    overlay.classList.remove('ldm-visible');
    modal.classList.remove('ldm-visible');
    setTimeout(function(){
      overlay.style.display = 'none';
      modal.style.display   = 'none';
    }, 260);
  }

  /* ── Show index view ── */
  function showIndex(){
    currentView = 'index';
    titleEl.textContent = 'Luminal CMS Documentation';
    iconEl.className = 'fa-solid fa-book-open';
    iconEl.style.color = '';
    iconEl.style.display = '';
    backBtn.style.display = 'none';
    expandBtn.style.display = 'none';
    content.style.display = 'none';
    error.style.display   = 'none';

    if(docsCache){
      grid.style.display = 'grid';
      loading.style.display = 'none';
    } else {
      loadIndex();
    }
    body.scrollTop = 0;
  }

  /* ── Fetch docs index ── */
  function loadIndex(){
    loading.style.display = '';
    grid.style.display    = 'none';
    content.style.display = 'none';
    error.style.display   = 'none';

    fetch(API_URL)
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data.ok || !data.docs || !data.docs.length) throw new Error('empty');
        docsCache = data.docs;
        renderCards(data.docs, data.groups);
        indexFetched = true;
      })
      .catch(function(){
        loading.style.display = 'none';
        error.style.display   = 'flex';
      });
  }

  function cardHtml(d){
    var bgColor = hexToRgba(d.color, 0.08);
    return '<div class="ldm-card" data-slug="' + esc(d.slug) + '" data-url="' + esc(d.url) + '" data-color="' + esc(d.color) + '" data-icon="' + esc(d.icon) + '">'
         + '<div class="ldm-card-header">'
         + '  <div class="ldm-card-icon" style="background:' + bgColor + ';color:' + esc(d.color) + '"><i class="' + esc(d.icon) + '"></i></div>'
         + '  <div class="ldm-card-title">' + esc(d.title) + '</div>'
         + '</div>'
         + '<div class="ldm-card-desc">' + esc(d.description) + '</div>'
         + '<div class="ldm-card-arrow"><i class="fa-solid fa-chevron-right"></i></div>'
         + '</div>';
  }

  function renderCards(docs, groups){
    var html = '';
    if (groups && groups.length) {
      // Grouped, tiered layout: one titled section per group, in group order.
      var byGroup = {};
      docs.forEach(function(d){ (byGroup[d.group] = byGroup[d.group] || []).push(d); });
      groups.forEach(function(g){
        var list = byGroup[g.key] || [];
        if (!list.length) return;
        html += '<div class="ldm-group">'
              + '<div class="ldm-group-head"><i class="' + esc(g.icon || '') + '"></i><span>' + esc(g.title) + '</span></div>'
              + '<div class="ldm-group-grid">' + list.map(cardHtml).join('') + '</div>'
              + '</div>';
      });
    } else {
      // Fallback: flat grid (older API without groups)
      html = '<div class="ldm-group-grid">' + docs.map(cardHtml).join('') + '</div>';
    }
    grid.innerHTML = html;
    loading.style.display = 'none';
    grid.style.display    = 'block';

    grid.querySelectorAll('.ldm-card').forEach(function(card){
      card.addEventListener('click', function(){
        var slug  = card.getAttribute('data-slug');
        var url   = card.getAttribute('data-url');
        var color = card.getAttribute('data-color');
        var icon  = card.getAttribute('data-icon');
        loadPage(slug, url, color, icon);
      });
    });
  }

  /* ── Load individual doc page ── */
  function loadPage(slug, url, color, icon){
    currentView = 'page';
    grid.style.display    = 'none';
    error.style.display   = 'none';
    backBtn.style.display = '';
    expandBtn.style.display = '';
    iconEl.className = icon;
    iconEl.style.color = color;

    if(pageCache[slug]){
      renderPage(pageCache[slug]);
      return;
    }

    content.style.display = 'block';
    content.innerHTML = '<div class="ldm-content-loading">'
      + '<div class="ldm-shimmer-line"></div>'
      + '<div class="ldm-shimmer-line"></div>'
      + '<div class="ldm-shimmer-line"></div>'
      + '<div class="ldm-shimmer-line"></div>'
      + '<div class="ldm-shimmer-line"></div>'
      + '</div>';

    fetch(API_URL + '?slug=' + encodeURIComponent(slug))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data.ok) throw new Error(data.error || 'Unknown');
        pageCache[slug] = data;
        renderPage(data);
      })
      .catch(function(err){
        content.innerHTML = '<div style="text-align:center;padding:2rem">'
          + '<i class="fa-solid fa-circle-exclamation" style="font-size:1.5rem;color:#991b1b;margin-bottom:.5rem"></i>'
          + '<p style="color:#78716c;margin:.5rem 0">Could not load this page. Please try again.</p>'
          + '</div>';
      });
  }

  function renderPage(data){
    titleEl.textContent = data.title || 'Documentation';
    var html = data.content || '<p style="color:#78716c">No content available.</p>';

    content.innerHTML = html;
    content.style.display = 'block';
    body.scrollTop = 0;
    allExpanded = false;
    expandBtn.querySelector('span').textContent = 'Expand All';

    /* ── Wrap h2 sections in collapsible containers ── */
    wrapH2Sections();
  }

  /* ── Collapsible h2 sections ── */
  function wrapH2Sections(){
    var h2s = content.querySelectorAll('h2');
    if(!h2s.length) return;

    h2s.forEach(function(h2, idx){
      /* Collect all siblings between this h2 and the next h2 (or end) */
      var wrapper = document.createElement('div');
      wrapper.className = 'ldm-section-body';

      var next = h2.nextElementSibling;
      while(next && next.tagName !== 'H2'){
        var move = next;
        next = next.nextElementSibling;
        wrapper.appendChild(move);
      }

      h2.parentNode.insertBefore(wrapper, h2.nextSibling);

      /* First section starts expanded */
      if(idx === 0){
        h2.classList.add('ldm-open');
        wrapper.classList.add('ldm-expanded');
      }

      /* Toggle on click */
      h2.addEventListener('click', function(){
        h2.classList.toggle('ldm-open');
        wrapper.classList.toggle('ldm-expanded');
      });
    });
  }

  /* ── Expand / Collapse All ── */
  expandBtn.addEventListener('click', function(){
    allExpanded = !allExpanded;
    var h2s = content.querySelectorAll('h2');
    h2s.forEach(function(h2){
      var wrapper = h2.nextElementSibling;
      if(!wrapper || !wrapper.classList.contains('ldm-section-body')) return;
      if(allExpanded){
        h2.classList.add('ldm-open');
        wrapper.classList.add('ldm-expanded');
      } else {
        h2.classList.remove('ldm-open');
        wrapper.classList.remove('ldm-expanded');
      }
    });
    expandBtn.querySelector('span').textContent = allExpanded ? 'Collapse All' : 'Expand All';
  });

  function hexToRgba(hex, alpha){
    hex = hex.replace('#','');
    var r = parseInt(hex.substring(0,2),16);
    var g = parseInt(hex.substring(2,4),16);
    var b = parseInt(hex.substring(4,6),16);
    return 'rgba('+r+','+g+','+b+','+alpha+')';
  }

  function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

  function findDoc(slug){
    if(!docsCache) return null;
    for(var i=0;i<docsCache.length;i++){
      if(docsCache[i].slug === slug) return docsCache[i];
    }
    return null;
  }

  /* ── Event wiring ── */
  closeBtn.addEventListener('click', closeDocsModal);
  overlay.addEventListener('click', closeDocsModal);
  backBtn.addEventListener('click', showIndex);

  content.addEventListener('click', function(e){
    var a = e.target.closest('a');
    if(!a) return;
    var href = a.getAttribute('href') || '';
    var m = href.match(/page\.php\?p=(docs(?:-[a-z0-9\-]+)?)/);
    if(!m) return;
    e.preventDefault();
    e.stopPropagation();
    var slug = m[1];
    if(slug === 'docs'){ showIndex(); return; }
    var doc = findDoc(slug);
    var url   = '/page.php?p=' + slug;
    var color = doc ? doc.color : '#92400e';
    var icon  = doc ? doc.icon  : 'fa-solid fa-book-open';
    loadPage(slug, url, color, icon);
  });

  document.addEventListener('keydown', function(e){
    if(modal.style.display !== 'flex') return;
    if(e.key === 'Escape'){
      if(currentView === 'page') showIndex();
      else closeDocsModal();
    }
  });

  window._luminalDocsModal = { open: openDocsModal, close: closeDocsModal };
})();
</script>
