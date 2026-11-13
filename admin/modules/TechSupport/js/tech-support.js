/**
 * Tech Support Dashboard — JS
 * Version: 2026.03.03.r4
 * - Meta strip: compact full-width bar under request (replaces tall two-column layout)
 * - Decision tree reply actions: Send to Agent / Save Note / Approve & Apply
 * - Removed Resolve button
 * - Copy buttons on all conversation messages (agent responses, notes, resolution)
 * - Conversation-based detail layout (all content built via JS render functions)
 * - renderTicketTopBar, renderRequestBanner, renderTicketColumns, renderConvThread
 * - renderTicketFooter, renderDiagnosticsDrawer, wireDetailHandlers
 * - Stage action cards, lifecycle progress bar, verification checklists
 * - Inline reply: Reanalyze / Add As Note / Execute Solution + Reply
 */
(function(){
  'use strict';

  const API = '/admin/modules/TechSupport/api.php';
  let currentTicketId = null;
  let currentAgentTaskId = '';
  let allTickets = [];
  let analyzingTimer = null;
  let analyzingPoll = null;
  let tsSortField = 'date';
  let tsSortDir = 'desc';

  /* ── DOM refs ── */
  const $body        = document.getElementById('tsdTicketBody');
  const $search      = document.getElementById('tsdSearch');
  const $statusF     = document.getElementById('tsdStatusFilter');
  const $urgencyF    = document.getElementById('tsdUrgencyFilter');
  const $domainF     = document.getElementById('tsdDomainFilter');
  const $categoryF   = document.getElementById('tsdCategoryFilter');
  const $agentF      = document.getElementById('tsdAgentFilter');
  const $refresh     = document.getElementById('tsdRefresh');
  const $detailOverlay = document.getElementById('tsdDetailOverlay');
  const $detail      = document.getElementById('tsdDetailPanel');
  // Relocate detail overlay to document.body to escape backdrop-filter stacking
  if ($detailOverlay && $detailOverlay.parentNode !== document.body) {
    document.body.appendChild($detailOverlay);
  }

  // Stats
  const $statOpen     = document.getElementById('statOpen');
  const $statProgress = document.getElementById('statProgress');
  const $statFeedback = document.getElementById('statFeedback');
  const $statClosed   = document.getElementById('statClosed');
  const $statArchived  = document.getElementById('statArchived');
  const $statGraveyard = document.getElementById('statGraveyard');

  const AS_API = '/admin/modules/AgentScheduler/api.php';


  /* ── Manual Pickup ZIP Download ── */
  function downloadPickupZip(ticketId) {
    var url = API + '?action=download_pickup&id=' + encodeURIComponent(ticketId);
    var a = document.createElement('a');
    a.href = url;
    a.download = ticketId + '_pickup.zip';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  /* ── PDF Download (client-side via jsPDF — pure text, no canvas) ── */
  function downloadPickupPdf(ticket, plan, implContent) {
    var J = (window.jspdf && window.jspdf.jsPDF) || null;
    if (!J) { alert('PDF library not loaded. Please refresh and try again.'); return; }

    var doc = new J({ unit: 'mm', format: 'a4', orientation: 'portrait' });
    var pw = 210, ph = 297;
    var ml = 16, mr = 16, mt = 18, mb = 16;
    var cw = pw - ml - mr;
    var y = mt;

    var C = { blue: [30,64,175], dark: [15,23,42], muted: [100,116,139], body: [30,41,59], accent: [59,130,246] };

    function checkPage(need) { if (y + (need||6) > ph - mb) { doc.addPage(); y = mt; } }

    function wrText(text, x, opts) {
      opts = opts || {};
      doc.setFont('helvetica', opts.bold ? 'bold' : 'normal');
      doc.setFontSize(opts.size || 9);
      var c = opts.color || C.body;
      doc.setTextColor(c[0], c[1], c[2]);
      var maxW = opts.maxW || (cw - (x - ml));
      var lines = doc.splitTextToSize(String(text), maxW);
      var lh = (opts.size || 9) * 0.42;
      for (var i = 0; i < lines.length; i++) { checkPage(lh); doc.text(lines[i], x, y); y += lh; }
    }

    function heading(title) {
      checkPage(12); y += 3;
      doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
      doc.setTextColor(C.blue[0], C.blue[1], C.blue[2]);
      doc.text(title, ml, y); y += 1.5;
      doc.setDrawColor(C.accent[0], C.accent[1], C.accent[2]);
      doc.setLineWidth(0.4); doc.line(ml, y, ml + cw, y); y += 5;
    }

    function bullet(text, indent) {
      indent = indent || 0;
      checkPage(5);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
      doc.setTextColor(C.body[0], C.body[1], C.body[2]);
      doc.text('\u2022', ml + 2 + indent, y);
      var blines = doc.splitTextToSize(text, cw - 8 - indent);
      for (var i = 0; i < blines.length; i++) { checkPage(4); doc.text(blines[i], ml + 6 + indent, y); y += 3.8; }
    }

    function drawMd(md) {
      if (!md) return;
      var lines = md.split('\n');
      for (var i = 0; i < lines.length; i++) {
        var L = lines[i];
        var strip = function(s) { return s.replace(/`([^`]+)`/g,'$1').replace(/\*\*([^*]+)\*\*/g,'$1').replace(/\*([^*]+)\*/g,'$1'); };
        var hm = L.match(/^(#{1,4})\s+(.*)/);
        if (hm) { checkPage(8); y += 2; wrText(strip(hm[2]), ml, { size: 10, bold: true, color: C.dark }); y += 1; continue; }
        var bm = L.match(/^\s*[-*+]\s+(.*)/);
        if (bm) { bullet(strip(bm[1])); continue; }
        var nm = L.match(/^\s*(\d+)[.)]\s+(.*)/);
        if (nm) { checkPage(5); doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(C.body[0],C.body[1],C.body[2]); doc.text(nm[1]+'.', ml+1, y); var nl=doc.splitTextToSize(strip(nm[2]),cw-8); for(var ni=0;ni<nl.length;ni++){checkPage(4);doc.text(nl[ni],ml+6,y);y+=3.8;} continue; }
        if (L.trim().match(/^```/)) continue;
        if (L.trim() === '') { y += 2; continue; }
        wrText(strip(L), ml);
        y += 1;
      }
    }

    // ═══ TITLE ═══
    var cat = CAT_LABELS[ticket.category] || ticket.category || '';
    doc.setFont('helvetica', 'bold'); doc.setFontSize(17);
    doc.setTextColor(C.dark[0], C.dark[1], C.dark[2]);
    var tl = doc.splitTextToSize(ticket.subject || ticket.id, cw);
    for (var ti = 0; ti < tl.length; ti++) { doc.text(tl[ti], ml, y); y += 7; }

    // Meta line
    y += 1;
    var meta = [ticket.id, ticket.domain, cat, (ticket.urgency||'medium').charAt(0).toUpperCase()+(ticket.urgency||'medium').slice(1), (ticket.status||'').replace(/_/g,' ')];
    if (ticket.created_at) meta.push(new Date(ticket.created_at).toLocaleString());
    wrText(meta.filter(Boolean).join('  |  '), ml, { size: 8, color: C.muted });
    y += 2;
    doc.setDrawColor(C.accent[0], C.accent[1], C.accent[2]);
    doc.setLineWidth(0.6); doc.line(ml, y, ml + cw, y); y += 6;

    // ═══ DESCRIPTION ═══
    if (ticket.description) { heading('Description'); wrText(ticket.description, ml); y += 3; }

    // ═══ AGENT PLAN ═══
    if (plan) {
      heading('Agent Plan');
      if (plan.diagnosis) { wrText('Diagnosis', ml, { size: 10, bold: true, color: C.dark }); y += 1; wrText(plan.diagnosis, ml); y += 3; }
      if (plan.proposed_changes && plan.proposed_changes.length) {
        wrText('Proposed Changes (' + plan.proposed_changes.length + ')', ml, { size: 10, bold: true, color: C.dark }); y += 1;
        plan.proposed_changes.forEach(function(c) { bullet((c.file||c.path||'') + ' \u2014 ' + (c.description||c.change_description||'')); });
        y += 2;
      }
      if (plan.risk_assessment) { var rl = 'Risk: ' + plan.risk_assessment; if (plan.estimated_effort) rl += '  |  Effort: ' + plan.estimated_effort; wrText(rl, ml, { size: 9, bold: true, color: C.dark }); y += 3; }
    }

    // ═══ IMPLEMENTATION GUIDE ═══
    if (implContent) { heading('Implementation Guide'); drawMd(implContent); }

    // ═══ FOOTER on every page ═══
    var totalPages = doc.internal.getNumberOfPages();
    var footerText = 'Luminal CMS Tech Support  |  ' + new Date().toLocaleString();
    for (var p = 1; p <= totalPages; p++) {
      doc.setPage(p);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7);
      doc.setTextColor(C.muted[0], C.muted[1], C.muted[2]);
      doc.text(footerText, ml, ph - 8);
      doc.text('Page ' + p + ' of ' + totalPages, pw - mr, ph - 8, { align: 'right' });
    }

    doc.save(ticket.id + '_implementation.pdf');
  }

  /* ── Analyzing Timer & Poll ── */
  function clearAnalyzingTimer() {
    if (analyzingTimer) { clearInterval(analyzingTimer); analyzingTimer = null; }
    if (analyzingPoll) { clearInterval(analyzingPoll); analyzingPoll = null; }
  }

  function startAnalyzingTimer(startTimeStr) {
    if (analyzingTimer) { clearInterval(analyzingTimer); analyzingTimer = null; }
    var timerEl = document.getElementById('tsdAnalyzingTimer');
    if (!timerEl || !startTimeStr) return;
    var start = new Date(startTimeStr).getTime();
    function tick() {
      var elapsed = Math.max(0, Math.floor((Date.now() - start) / 1000));
      var m = Math.floor(elapsed / 60);
      var s = elapsed % 60;
      timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    }
    tick();
    analyzingTimer = setInterval(tick, 1000);
  }

  function startAnalyzingPoll(ticketId, taskId) {
    if (!taskId) return;
    if (analyzingPoll) { clearInterval(analyzingPoll); analyzingPoll = null; }
    var pollActive = true;
    analyzingPoll = setInterval(async function() {
      if (!pollActive) return;
      try {
        var res = await fetch(AS_API + '?action=get_task&id=' + encodeURIComponent(taskId))
          .then(function(r) { return r.json(); });
        if (!res.ok || !res.data.task) return;
        var task = res.data.task;
        // Update live token count
        var tokEl = document.getElementById('tsdAnalyzingTokens');
        if (tokEl && task.last_tokens) {
          tokEl.textContent = Number(task.last_tokens).toLocaleString();
        }
        // If task finished, refresh once then stop
        if (task.status !== 'running' && task.status !== 'pending') {
          pollActive = false;
          clearAnalyzingTimer();
          loadStats();
          loadTickets();
          if (currentTicketId === ticketId) showDetail(ticketId);
        }
      } catch(e) { /* silent */ }
    }, 8000);
  }

  function buildAnalyzingPanel(agentInfo) {
    var modelLabel = (agentInfo && agentInfo.ai_model) ? agentInfo.ai_model : 'AI Agent';
    var tokenCount = (agentInfo && agentInfo.ai_tokens) ? Number(agentInfo.ai_tokens).toLocaleString() : '0';
    return '<div class="tsd-analyzing-live">' +
      '<div class="tsd-analyzing-model-row">' +
        '<span class="tsd-analyzing-model-icon">&#129302;</span>' +
        '<span class="tsd-analyzing-model-name">' + esc(modelLabel) + '</span>' +
        '<span class="tsd-ap-spinner" style="margin-left:auto"></span>' +
      '</div>' +
      '<div class="tsd-analyzing-stats">' +
        '<div class="tsd-analyzing-stat">' +
          '<span class="tsd-analyzing-stat-val" id="tsdAnalyzingTimer">0:00</span>' +
          '<span class="tsd-analyzing-stat-label">Elapsed</span>' +
        '</div>' +
        '<div class="tsd-analyzing-stat">' +
          '<span class="tsd-analyzing-stat-val tsd-analyzing-tokens-val" id="tsdAnalyzingTokens">' + tokenCount + '</span>' +
          '<span class="tsd-analyzing-stat-label">Tokens</span>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  // Build post-execution results panel
  function buildExecutionResults(execRun, plan, agentInfo) {
    if (!execRun && !plan) return '';
    var html = '<div class="tsd-exec-results">';
    html += '<div class="tsd-exec-results-header">Execution Results</div>';

    // Status banner
    var runOk = execRun && execRun.status === 'success';
    if (execRun) {
      html += '<div class="tsd-exec-status ' + (runOk ? 'tsd-exec-status-ok' : 'tsd-exec-status-err') + '">' +
        '<span class="tsd-exec-status-icon">' + (runOk ? '&#10003;' : '&#10007;') + '</span>' +
        '<span>' + (runOk ? 'Execution completed successfully' : 'Execution failed') + '</span>' +
        (!runOk ? '<span class="tsd-exec-fail-actions">' +
          '<button class="tsd-btn tsd-btn-sm js-exec-send-cli" style="background:#e8b931;color:#000;font-weight:700;border-color:#e8b931;margin-left:12px">\uD83D\uDD27 Send to CLI</button>' +
          '<button class="tsd-btn tsd-btn-sm js-exec-open-ticket" style="margin-left:6px">Open Support Ticket</button>' +
        '</span>' : '') +
        '</div>';
    }

    // Stats row: duration, tokens, model, files
    var stats = [];
    if (execRun && execRun.duration_ms) {
      var dur = execRun.duration_ms;
      var durStr = dur < 1000 ? dur + 'ms' : (dur / 1000).toFixed(1) + 's';
      stats.push({ label: 'Duration', value: durStr });
    }
    if (agentInfo && agentInfo.ai_model) stats.push({ label: 'Model', value: esc(agentInfo.ai_model) });
    if (agentInfo && agentInfo.ai_tokens) stats.push({ label: 'Tokens', value: Number(agentInfo.ai_tokens).toLocaleString() });
    var fileCount = plan && plan.proposed_changes ? plan.proposed_changes.length : (plan && plan.affected_files ? plan.affected_files.length : 0);
    if (fileCount) stats.push({ label: 'Files', value: String(fileCount) });

    if (stats.length) {
      html += '<div class="tsd-exec-stats">';
      for (var si = 0; si < stats.length; si++) {
        html += '<div class="tsd-exec-stat"><span class="tsd-exec-stat-val">' + stats[si].value + '</span><span class="tsd-exec-stat-label">' + stats[si].label + '</span></div>';
      }
      html += '</div>';
    }

    // Files affected
    if (plan && plan.proposed_changes && plan.proposed_changes.length) {
      html += '<div class="tsd-exec-files">';
      html += '<div class="tsd-exec-files-title">Changes Applied</div>';
      for (var fi = 0; fi < plan.proposed_changes.length; fi++) {
        var ch = plan.proposed_changes[fi];
        var filePath = ch.file || ch.path || '';
        var desc = ch.description || ch.change_description || '';
        html += '<div class="tsd-exec-file-row">' +
          '<code class="tsd-exec-file-path">' + esc(filePath) + '</code>' +
          (desc ? '<span class="tsd-exec-file-desc">' + esc(desc) + '</span>' : '') +
          '</div>';
      }
      html += '</div>';
    }

    // Execution steps
    if (execRun && execRun.steps && execRun.steps.length) {
      html += '<div class="tsd-exec-steps">';
      html += '<div class="tsd-exec-steps-title">Pipeline Steps</div>';
      for (var sti = 0; sti < execRun.steps.length; sti++) {
        var s = execRun.steps[sti];
        var sOk = s.status === 'ok' || s.status === 'success';
        html += '<div class="tsd-exec-step">' +
          '<span class="tsd-exec-step-icon ' + (sOk ? 'tsd-step-ok' : 'tsd-step-err') + '">' + (sOk ? '&#10003;' : '&#10007;') + '</span>' +
          '<span class="tsd-exec-step-name">' + esc(s.step) + '</span>' +
          '<span class="tsd-exec-step-detail">' + esc(s.detail || '') + '</span>' +
          '</div>';
      }
      html += '</div>';
    }

    // Ready for curation banner
    if (runOk) {
      html += '<div class="tsd-exec-curation">Ready for curation &mdash; review the implementation guide below</div>';
    }

    html += '</div>';
    return html;
  }

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
        if (v) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
      }
    }

    const r = await fetch(url, opts);
    if (r.status === 401) throw new Error('Session expired — please log in again');
    if (r.status === 302 || r.redirected) throw new Error('Session expired — redirected to login');
    return r.json();
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '-';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'now';
    if (mins < 60) return mins + 'm';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h';
    const days = Math.floor(hrs / 24);
    if (days < 30) return days + 'd';
    return Math.floor(days / 30) + 'mo';
  }

  function formatDate(dateStr) {
    if (!dateStr) return '-';
    try {
      return new Date(dateStr).toLocaleString();
    } catch { return dateStr; }
  }

  /* ── Ticket sorting ── */
  const URGENCY_ORDER = { critical: 0, high: 1, medium: 2, low: 3 };

  function sortTickets(tickets, field, dir) {
    if (!tickets || !tickets.length) return tickets;
    var sorted = tickets.slice();
    sorted.sort(function(a, b) {
      var va, vb;
      switch (field) {
        case 'id':
          va = a.id || ''; vb = b.id || '';
          break;
        case 'subject':
          va = (a.subject || '').toLowerCase(); vb = (b.subject || '').toLowerCase();
          break;
        case 'domain':
          va = (a.domain || '').toLowerCase(); vb = (b.domain || '').toLowerCase();
          break;
        case 'category':
          va = a.category || ''; vb = b.category || '';
          break;
        case 'urgency':
          va = URGENCY_ORDER[a.urgency] ?? 9; vb = URGENCY_ORDER[b.urgency] ?? 9;
          return dir === 'asc' ? va - vb : vb - va;
        case 'status':
          va = a.lifecycle_stage || a.status || ''; vb = b.lifecycle_stage || b.status || '';
          break;
        case 'date':
          va = a.submitted_at || ''; vb = b.submitted_at || '';
          break;
        default:
          return 0;
      }
      if (typeof va === 'string') {
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ? 1 : -1;
        return 0;
      }
      return dir === 'asc' ? va - vb : vb - va;
    });
    return sorted;
  }

  function updateSortIndicators() {
    document.querySelectorAll('.tsd-sortable').forEach(function(th) {
      var field = th.dataset.sort;
      th.classList.toggle('tsd-sort-active', field === tsSortField);
      var icon = th.querySelector('.tsd-sort-icon');
      if (icon) {
        if (field === tsSortField) {
          icon.innerHTML = tsSortDir === 'asc' ? '&#9650;' : '&#9660;';
        } else {
          icon.innerHTML = '&#8597;';
        }
      }
    });
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  // Lightweight markdown→HTML for agent proposals (condensed, readable)
  function renderProposalMd(raw) {
    const lines = (raw || '').split('\n');
    let html = '';
    let inList = false;   // ul
    let inOl = false;     // ol
    let inCode = false;
    for (let i = 0; i < lines.length; i++) {
      let L = lines[i];
      // Code fences — collapse into a single styled block
      if (L.trim().startsWith('```')) {
        if (!inCode) { if (inList) { html += '</ul>'; inList = false; } if (inOl) { html += '</ol>'; inOl = false; } html += '<pre class="tsd-proposal-code">'; inCode = true; }
        else { html += '</pre>'; inCode = false; }
        continue;
      }
      if (inCode) { html += esc(L) + '\n'; continue; }
      // Blank line — close lists
      if (!L.trim()) { if (inList) { html += '</ul>'; inList = false; } if (inOl) { html += '</ol>'; inOl = false; } continue; }
      // Headers
      const hm = L.match(/^(#{1,4})\s+(.*)/);
      if (hm) { if (inList) { html += '</ul>'; inList = false; } if (inOl) { html += '</ol>'; inOl = false; } const lvl = Math.min(hm[1].length, 4); html += '<h' + (lvl+1) + ' class="tsd-proposal-h">' + inlineMd(hm[2]) + '</h' + (lvl+1) + '>'; continue; }
      // Numbered list
      const om = L.match(/^\s*(\d+)[.)]\s+(.*)/);
      if (om) { if (inList) { html += '</ul>'; inList = false; } if (!inOl) { html += '<ol class="tsd-proposal-list">'; inOl = true; } html += '<li>' + inlineMd(om[2]) + '</li>'; continue; }
      // Bullet list
      const bm = L.match(/^\s*[-*+]\s+(.*)/);
      if (bm) { if (inOl) { html += '</ol>'; inOl = false; } if (!inList) { html += '<ul class="tsd-proposal-list">'; inList = true; } html += '<li>' + inlineMd(bm[1]) + '</li>'; continue; }
      // Paragraph
      if (inList) { html += '</ul>'; inList = false; } if (inOl) { html += '</ol>'; inOl = false; }
      html += '<p class="tsd-proposal-p">' + inlineMd(L) + '</p>';
    }
    if (inList) html += '</ul>';
    if (inOl) html += '</ol>';
    if (inCode) html += '</pre>';
    return html;
  }
  function inlineMd(s) {
    return esc(s)
      .replace(/`([^`]+)`/g, '<code class="tsd-proposal-inline">$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g, '<em>$1</em>')
      .replace(/(https?:\/\/[^\s<"']+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:var(--tsd-accent2)">$1</a>');
  }

  // Alias for implementation guide rendering
  var renderSimpleMd = renderProposalMd;

  const ALL_STATUSES = [
    { value: 'open',              label: 'Open' },
    { value: 'in_progress',      label: 'In Progress' },
    { value: 'awaiting_feedback', label: 'Awaiting Feedback' },
    { value: 'resolved',          label: 'Resolved' },
    { value: 'closed',            label: 'Closed' },
    { value: 'wont_fix',          label: "Won't Fix" },
  ];

  function populateStatusDropdown(currentStatus) {
    $statusSelect.innerHTML = '';
    for (const s of ALL_STATUSES) {
      if (s.value === currentStatus) continue;
      const opt = document.createElement('option');
      opt.value = s.value;
      opt.textContent = s.label;
      $statusSelect.appendChild(opt);
    }
  }

  const CAT_LABELS = {
    'ui_issue': 'UI Issue',
    'ui_request': 'UI Request',
    'something_is_broken': 'Broken',
    'edit_page_html_css': 'Page Edit',
    'page_content': 'Page / Content',
    'feature_request': 'Feature Req',
    'module_request': 'Module Req',
    'question': 'Question',
  };

  function shortDomain(d) {
    return (d || '').replace(/^www\./, '').replace(/\.com$|\.pro$|\.us$|\.rocks$/, '');
  }

  /* ── Lifecycle Stage Labels & Config ── */
  // Detailed labels (used in detail view progress bar)
  const LC_LABELS_DETAIL = {
    submitted:          'Submitted',
    queued:             'Queued',
    analyzing:          'Analyzing',
    plan_ready:         'Plan Ready',
    approved:           'Approved',
    impl_ready:         'Impl Ready',
    applying:           'Applying',
    applied:            'Applied',
    verification:       'Verification',
    verified:           'Verified',
    resolved:           'Resolved',
    closed:             'Closed',
    error:              'Error',
    awaiting_feedback:  'Feedback',
    cli_pending:        'CLI Pending',
  };
  // Simplified labels (used in listing rows)
  const LC_LABELS = {
    submitted:          'Open',
    open:               'Open',
    queued:             'Open',
    triage:             'Open',
    analyzing:          'In Progress',
    in_progress:        'In Progress',
    plan_ready:         'In Progress',
    approved:           'In Progress',
    impl_ready:         'In Progress',
    applying:           'In Progress',
    applied:            'In Progress',
    verification:       'In Progress',
    verified:           'Resolved',
    resolved:           'Resolved',
    closed:             'Closed',
    error:              'Error',
    awaiting_feedback:  'Feedback',
    cli_pending:        'CLI Pending',
  };

  // Lifecycle steps in order (for progress bar)
  const LC_STEPS = [
    { key: 'submitted',     label: 'Submitted' },
    { key: 'queued',        label: 'Queued' },
    { key: 'analyzing',     label: 'Analyzing' },
    { key: 'plan_ready',    label: 'Plan Ready' },
    { key: 'impl_ready',    label: 'Impl Ready' },
    { key: 'applying',      label: 'Applying' },
    { key: 'verification',  label: 'Verification' },
    { key: 'resolved',      label: 'Resolved' },
  ];

  // Map lifecycle stage to step index (for progress bar fill)
  const LC_STEP_INDEX = { submitted: 0, queued: 1, analyzing: 2, plan_ready: 3, approved: 3, impl_ready: 4, applying: 5, applied: 6, verification: 6, verified: 6, resolved: 7, closed: 7 };

  // Steps requiring admin action (checkbox indicator)
  const LC_ACTION_STEPS = { plan_ready: true, impl_ready: true, verification: true };
  // Steps that are automated (spinner indicator)
  const LC_AUTO_STEPS = { queued: true, analyzing: true, approved: true, applying: true };

  function renderLifecycleBar(stage) {
    if (!$lifecycleBar) return;
    const idx = LC_STEP_INDEX[stage] ?? -1;
    const isError = (stage === 'error');
    const isFeedback = (stage === 'awaiting_feedback');

    let html = '';
    LC_STEPS.forEach((step, i) => {
      let cls = '';
      if (isError && i === Math.max(idx, 2)) cls = 'lc-error';
      else if (isFeedback && i === Math.max(idx, 0)) cls = 'lc-current';
      else if (i < idx) cls = 'lc-done';
      else if (i === idx) cls = 'lc-current';

      html += '<div class="tsd-lc-step ' + cls + '">';
      html += '<span class="tsd-lc-dot"></span>';
      html += '<span class="tsd-lc-label">' + esc(step.label) + '</span>';

      // Step indicator: checkbox for action, spinner for auto, check for done, warning for error
      if (cls === 'lc-done') {
        html += '<span class="tsd-lc-indicator">\u2611</span>';
      } else if (cls === 'lc-error') {
        html += '<span class="tsd-lc-indicator">\u26A0</span>';
      } else if (cls === 'lc-current') {
        if (LC_ACTION_STEPS[stage]) {
          html += '<span class="tsd-lc-indicator">\u2610</span>';
        } else if (LC_AUTO_STEPS[stage]) {
          html += '<span class="tsd-lc-indicator-spinner"></span>';
        }
      }

      if (i < LC_STEPS.length - 1) {
        html += '<span class="tsd-lc-line"></span>';
      }
      html += '</div>';
    });
    $lifecycleBar.innerHTML = html;
  }

  /* ── Conversation Thread ── */
  function renderThread(ticket) {
    if (!$thread) return;

    const entries = [];
    const userName = ticket.user?.display_name || ticket.user?.username || 'User';

    // 1. Original request
    entries.push({
      type: 'request',
      text: ticket.description || '(No description)',
      at: ticket.submitted_at,
      by: userName,
      category: ticket.category,
      urgency: ticket.urgency,
      areas: ticket.affected_areas,
      domain: ticket.domain,
      screenshot: ticket.screenshot,
    });

    // 2. Agent proposals (from notes with agent_proposal source)
    (ticket.notes || []).forEach(function(n) {
      const isAgent = n.source === 'agent_proposal' || n.by === 'AgentScheduler';
      if (isAgent) {
        entries.push({ type: 'agent', text: n.text || '', at: n.at, by: 'AI Agent' });
      } else {
        entries.push({ type: 'note', text: n.text || '', at: n.at, by: n.by || 'unknown' });
      }
    });

    // 3. Clarifications (human responses that trigger re-analysis)
    (ticket.clarifications || []).forEach(function(cl) {
      entries.push({ type: 'clarification', text: cl.text || '', at: cl.timestamp, by: cl.by || 'Admin' });
    });

    // Sort by timestamp
    entries.sort(function(a, b) { return new Date(a.at || 0) - new Date(b.at || 0); });

    let html = '';
    entries.forEach(function(e, idx) {
      var cls = 'tsd-thread-msg tsd-thread-msg--' + e.type;
      var icon = e.type === 'request' ? '&#128161;'
        : e.type === 'agent' ? '&#129302;'
        : e.type === 'clarification' ? '&#9851;'
        : '&#128172;';
      var label = e.type === 'request' ? 'Original Request'
        : e.type === 'agent' ? 'AI Agent Response'
        : e.type === 'clarification' ? 'Reanalysis Feedback'
        : 'Note';

      html += '<div class="' + cls + '">';
      html += '<div class="tsd-thread-msg-header">';
      html += '<span class="tsd-thread-msg-icon">' + icon + '</span>';
      html += '<span class="tsd-thread-msg-label">' + label + '</span>';
      html += '<span class="tsd-thread-msg-by">' + esc(e.by) + '</span>';
      html += '<span class="tsd-thread-msg-time">' + formatDate(e.at) + ' (' + timeAgo(e.at) + ')</span>';
      html += '<span class="tsd-thread-collapse-arrow">&#9654;</span>';
      html += '</div>';

      // Collapsible content wrapper — starts hidden
      html += '<div class="tsd-thread-msg-content" style="display:none">';

      if (e.type === 'request') {
        html += '<div class="tsd-thread-msg-body">' + esc(e.text) + '</div>';
        // Meta tags
        html += '<div class="tsd-thread-msg-meta">';
        html += '<span class="tsd-rm-tag">Category: <strong>' + esc(CAT_LABELS[e.category] || e.category) + '</strong></span>';
        html += '<span class="tsd-rm-tag"><span class="tsd-urg-dot ' + esc(e.urgency) + '"></span> <strong>' + esc(e.urgency) + '</strong></span>';
        if (e.areas && e.areas.length) html += '<span class="tsd-rm-tag">Areas: <strong>' + esc(e.areas.join(', ')) + '</strong></span>';
        html += '<span class="tsd-rm-tag">Source: <strong>' + esc(e.domain) + '</strong></span>';
        // Page content tags
        if (e.category === 'page_content' && ticket.page_content_details) {
          var pc = ticket.page_content_details;
          if (pc.page_title || pc.page_slug) html += '<span class="tsd-rm-tag">Page: <strong>' + esc(pc.page_title || pc.page_slug) + '</strong></span>';
          if (pc.change_types && pc.change_types.length) html += '<span class="tsd-rm-tag">Changes: <strong>' + esc(pc.change_types.map(function(v){return v.replace(/_/g,' ')}).join(', ')) + '</strong></span>';
        }
        html += '</div>';
        if (e.screenshot) {
          html += '<img class="tsd-thread-screenshot" src="' + API + '?action=screenshot&file=' + encodeURIComponent(e.screenshot) + '" alt="Screenshot" onclick="window.open(this.src)">';
        }
      } else if (e.type === 'agent') {
        // Agent proposals get markdown rendering
        var rendered = renderProposalMd(e.text);
        html += '<div class="tsd-thread-msg-body">' + rendered + '</div>';
      } else {
        html += '<div class="tsd-thread-msg-body">' + esc(e.text) + '</div>';
      }

      html += '</div>'; // close .tsd-thread-msg-content
      html += '</div>'; // close .tsd-thread-msg
    });

    // Resolution (if resolved)
    if (ticket.resolution) {
      html += '<div class="tsd-thread-msg tsd-thread-msg--resolution">';
      html += '<div class="tsd-thread-msg-header">';
      html += '<span class="tsd-thread-msg-icon">&#10003;</span>';
      html += '<span class="tsd-thread-msg-label">Resolved</span>';
      if (ticket.resolved_by) html += '<span class="tsd-thread-msg-by">' + esc(ticket.resolved_by) + '</span>';
      if (ticket.resolved_at) html += '<span class="tsd-thread-msg-time">' + formatDate(ticket.resolved_at) + '</span>';
      html += '<span class="tsd-thread-collapse-arrow">&#9654;</span>';
      html += '</div>';
      html += '<div class="tsd-thread-msg-content" style="display:none">';
      html += '<div class="tsd-thread-msg-body">' + esc(ticket.resolution) + '</div>';
      html += '</div>';
      html += '</div>';
    }

    // ── Stage Action Card (appended at end of thread) ──
    var stageResult = renderStageActionCard(ticket);
    html += stageResult.html;

    // ── Inline Reply Area (last element in thread) ──
    html += '<div class="tsd-thread-reply-area">' +
      '<textarea id="threadReplyInput" placeholder="Reply with corrections, new information, or execution instructions..." rows="2"></textarea>' +
      '<div class="tsd-thread-reply-actions">' +
      '<button class="tsd-btn tsd-btn-primary tsd-btn-sm" id="threadReplyReanalyze" type="button">Reanalyze</button>' +
      '<button class="tsd-btn tsd-btn-sm" id="threadReplyNote" type="button">Add As Note</button>' +
      '<button class="tsd-btn tsd-btn-execute-sm" id="threadReplyExecute" type="button">&#9654; Execute Solution + Reply</button>' +
      '</div></div>';

    $thread.innerHTML = html;

    // Wire thread message collapse/expand
    $thread.querySelectorAll('.tsd-thread-msg-header').forEach(function(hdr) {
      hdr.addEventListener('click', function() {
        var msg = hdr.closest('.tsd-thread-msg');
        var content = hdr.nextElementSibling;
        if (!msg || !content) return;
        var arrow = hdr.querySelector('.tsd-thread-collapse-arrow');
        if (msg.classList.contains('expanded')) {
          msg.classList.remove('expanded');
          content.style.display = 'none';
          if (arrow) arrow.innerHTML = '&#9654;';
        } else {
          msg.classList.add('expanded');
          content.style.display = '';
          if (arrow) arrow.innerHTML = '&#9660;';
        }
      });
    });

    // Wire up stage action card buttons
    if (stageResult.wireUp) {
      stageResult.wireUp($thread);
    }

    // Wire up inline reply handlers via event delegation on the thread
    wireThreadReplyHandlers(ticket);
  }

  /* ── Wire Reply Handlers (inline in thread) ── */
  function wireThreadReplyHandlers(ticket) {
    var replyInput = $thread.querySelector('#threadReplyInput');
    var reanalyzeBtn = $thread.querySelector('#threadReplyReanalyze');
    var noteBtn = $thread.querySelector('#threadReplyNote');
    var executeBtn = $thread.querySelector('#threadReplyExecute');

    if (reanalyzeBtn) {
      reanalyzeBtn.addEventListener('click', async function() {
        var text = replyInput ? replyInput.value.trim() : '';
        if (!text || !currentTicketId) { alert('Please enter your feedback or new information.'); return; }
        reanalyzeBtn.disabled = true;
        reanalyzeBtn.textContent = 'Re-analyzing...';
        try {
          var j = await apiCall('clarify_ticket', { id: currentTicketId, clarification: text }, 'POST');
          if (j.ok) {
            if (replyInput) replyInput.value = '';
            loadStats();
            loadTickets();
            showDetail(currentTicketId);
          } else {
            alert('Error: ' + (j.error || 'Clarification failed'));
          }
        } catch(e) {
          alert('Error: ' + e.message);
        } finally {
          reanalyzeBtn.disabled = false;
          reanalyzeBtn.textContent = 'Reanalyze';
        }
      });
    }

    if (noteBtn) {
      noteBtn.addEventListener('click', async function() {
        var note = replyInput ? replyInput.value.trim() : '';
        if (!note || !currentTicketId) { alert('Please enter a note.'); return; }
        noteBtn.disabled = true;
        try {
          var j = await apiCall('add_note', { id: currentTicketId, note: note }, 'POST');
          if (j.ok) {
            if (replyInput) replyInput.value = '';
            if (j.reopened) { loadStats(); loadTickets(); }
            showDetail(currentTicketId);
          } else {
            alert('Failed to save note: ' + (j.error || 'Unknown error'));
          }
        } finally { noteBtn.disabled = false; }
      });
    }

    if (executeBtn) {
      executeBtn.addEventListener('click', async function() {
        if (!currentTicketId || !currentAgentTaskId) {
          alert('No agent task linked to this ticket.');
          return;
        }
        executeBtn.disabled = true;
        executeBtn.textContent = 'Applying...';
        try {
          var text = replyInput ? replyInput.value.trim() : '';
          if (text) {
            await apiCall('clarify_ticket', { id: currentTicketId, clarification: text }, 'POST');
            if (replyInput) replyInput.value = '';
          }
          var r = await fetch(AS_API + '?action=approve_plan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentAgentTaskId }),
          }).then(function(r) { return r.json(); });
          if (r.ok) {
            loadStats();
            loadTickets();
            showDetail(currentTicketId);
          } else {
            alert('Error: ' + (r.error || 'Execution failed'));
          }
        } catch(e) {
          alert('Error: ' + e.message);
        } finally {
          executeBtn.disabled = false;
          executeBtn.innerHTML = '&#9654; Execute Solution + Reply';
        }
      });
    }
  }

  /* ── Render Stage Action Card (returns HTML + wireUp callback for thread insertion) ── */
  function renderStageActionCard(ticket) {
    var stage = ticket.lifecycle_stage || 'submitted';
    var agentTaskId = ticket.agent_task_id || (ticket.agent && ticket.agent.agent_task_id) || '';
    var agentInfo = ticket.agent || null;
    var hasAgent = !!agentTaskId;
    var isDeployed = stage === 'resolved' || stage === 'verified' || stage === 'closed';
    var isError = stage === 'error';

    // No card if no agent and submitted
    if (!hasAgent || stage === 'submitted') {
      // Still show early-lifecycle progress for queued/analyzing with no agent
      if (!hasAgent) {
        return { html: '', wireUp: null };
      }
    }

    // Attention dot helper
    function attDot(cls) { return '<span class="tsd-stage-attention ' + cls + '"></span>'; }

    // Determine title
    var title = '';
    if (isDeployed) {
      title = 'Solution \u2014 Completed &amp; Deployed';
    } else if (isError) {
      title = attDot('att-red') + 'Solution \u2014 Error';
    } else if (stage === 'analyzing' || stage === 'queued') {
      title = 'Solution \u2014 AI is Analyzing...';
    } else if (stage === 'plan_ready') {
      title = attDot('att-amber') + '<span class="tsd-approval-flash">&#9889; AWAITING YOUR APPROVAL</span>';
    } else if (stage === 'approved') {
      title = 'Solution \u2014 Generating Guide...';
    } else if (stage === 'applying') {
      title = 'Solution \u2014 Applying Implementation...';
    } else if (stage === 'applied') {
      title = attDot('att-green') + 'Solution \u2014 Verify Changes';
    } else if (stage === 'impl_ready') {
      title = attDot('att-blue') + 'Solution \u2014 Review Required';
    } else if (stage === 'verification') {
      title = attDot('att-green') + 'Solution \u2014 Verify Changes';
    } else {
      title = 'Solution \u2014 Processing';
    }

    // Agent signature footer HTML
    var footerHtml = '';
    if (agentInfo) {
      var footerParts = [];
      if (agentInfo.ai_model) footerParts.push(esc(agentInfo.ai_model));
      if (agentInfo.ai_tokens) footerParts.push(Number(agentInfo.ai_tokens).toLocaleString() + ' tokens');
      if (agentInfo.agent_last_run) footerParts.push(timeAgo(agentInfo.agent_last_run) + ' ago');
      if (footerParts.length) footerHtml = '<span class="tsd-sol-agent-sig">' + footerParts.join(' &middot; ') + '</span>';
    }

    // Build body + actions HTML per stage (synchronous parts)
    var bodyHtml = '';
    var actionsHtml = '';
    var needsAsync = false; // stages that need async data fetching

    // ── Analyzing / Queued ──
    if (stage === 'analyzing' || stage === 'queued') {
      bodyHtml = buildAnalyzingPanel(agentInfo);
    }

    // ── Approved ──
    else if (stage === 'approved') {
      bodyHtml = '<div style="font:600 12px system-ui;color:var(--tsd-accent);margin-bottom:10px">Plan approved \u2014 generating implementation guide...</div>' +
        buildAnalyzingPanel(agentInfo);
    }

    // ── Applying ──
    else if (stage === 'applying') {
      bodyHtml = '<div style="font:600 12px system-ui;color:var(--tsd-accent);margin-bottom:10px">Applying implementation...</div>' +
        buildAnalyzingPanel(agentInfo);
    }

    // Stages that need async loading show placeholder
    else if (stage === 'plan_ready' || stage === 'applied' || stage === 'impl_ready' || stage === 'verification' || isDeployed || isError) {
      bodyHtml = '<div style="color:var(--tsd-muted);font-size:12px">Loading...</div>';
      needsAsync = true;
    }

    // Default
    else {
      bodyHtml = '<div class="tsd-ap-status"><span class="tsd-ap-spinner"></span><span>Processing...</span></div>';
    }

    var cardHtml = '<div class="tsd-thread-stage-card stage-' + esc(stage) + '" id="threadStageCard">' +
      '<div class="tsd-stage-card-title js-stage-card-toggle" style="cursor:pointer;user-select:none">' + title + '<span class="tsd-collapse-arrow" style="margin-left:auto;font-size:10px">&#9660;</span></div>' +
      '<div class="tsd-stage-card-inner" id="stageCardInner">' +
      '<div class="tsd-stage-card-body" id="stageCardBody">' + bodyHtml + '</div>' +
      '<div class="tsd-stage-card-actions" id="stageCardActions">' + actionsHtml + '</div>' +
      '<div class="tsd-stage-card-footer">' + footerHtml + '</div>' +
      '</div></div>';

    // wireUp callback — runs after innerHTML is set
    function wireUp(container) {
      // Start timers for analyzing stages
      if (stage === 'analyzing') {
        startAnalyzingTimer((agentInfo && agentInfo.agent_last_run) || null);
        startAnalyzingPoll(ticket.id, agentTaskId);
      }
      if (stage === 'approved' || stage === 'applying') {
        startAnalyzingTimer((agentInfo && agentInfo.agent_last_run) || null);
        startAnalyzingPoll(ticket.id, agentTaskId);
      }

      // Async stages — fetch data and populate the card
      if (needsAsync) {
        loadStageCardAsync(ticket, stage, agentTaskId, agentInfo, isDeployed, isError);
      }

      // Stage card collapse/expand toggle
      var toggleEl = container ? container.querySelector('.js-stage-card-toggle') : document.querySelector('.js-stage-card-toggle');
      var innerEl = document.getElementById('stageCardInner');
      if (toggleEl && innerEl) {
        toggleEl.addEventListener('click', function() {
          var arrow = toggleEl.querySelector('.tsd-collapse-arrow');
          if (innerEl.style.display === 'none') {
            innerEl.style.display = '';
            if (arrow) arrow.innerHTML = '&#9660;';
          } else {
            innerEl.style.display = 'none';
            if (arrow) arrow.innerHTML = '&#9654;';
          }
        });
      }
    }

    return { html: cardHtml, wireUp: wireUp };
  }

  /* ── Async loader for stage card body/actions (called after DOM insert) ── */
  async function loadStageCardAsync(ticket, stage, agentTaskId, agentInfo, isDeployed, isError) {
    var $cardBody = document.getElementById('stageCardBody');
    var $cardActions = document.getElementById('stageCardActions');
    if (!$cardBody) return;

    // ── Plan Ready ──
    if (stage === 'plan_ready' && agentTaskId) {
      try {
        var planRes = await fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(agentTaskId)).then(function(r) { return r.json(); });
        if (planRes.ok && planRes.data.plan) {
          var plan = planRes.data.plan;
          var html = '';
          if (plan.diagnosis) html += '<div class="tsd-ap-plan-diagnosis">' + esc(plan.diagnosis) + '</div>';
          if (plan.proposed_changes && plan.proposed_changes.length) {
            html += '<div class="tsd-ap-plan-changes"><strong>' + plan.proposed_changes.length + ' proposed change(s):</strong><ul>';
            plan.proposed_changes.forEach(function(c) {
              html += '<li><code>' + esc(c.file || c.path || '') + '</code> &mdash; ' + esc(c.description || c.change_description || '') + '</li>';
            });
            html += '</ul></div>';
          }
          if (plan.risk_assessment) {
            var rc = plan.risk_assessment === 'low' ? 'var(--tsd-green)' : plan.risk_assessment === 'high' ? 'var(--tsd-red)' : 'var(--tsd-yellow)';
            html += '<div class="tsd-ap-plan-meta">Risk: <strong style="color:' + rc + '">' + esc(plan.risk_assessment) + '</strong>';
            if (plan.estimated_effort) html += ' &middot; Effort: <strong>' + esc(plan.estimated_effort) + '</strong>';
            html += '</div>';
          }
          // Asset bar
          var planExportId = 'tsdPlanExportMd_' + Math.random().toString(36).slice(2, 8);
          var planPickupId = 'tsdPlanPickup_' + Math.random().toString(36).slice(2, 8);
          var planPdfId = 'tsdPlanPdf_' + Math.random().toString(36).slice(2, 8);
          html += '<div class="tsd-asset-bar">' +
            '<div class="tsd-asset-bar-label">DOWNLOADS</div>' +
            '<div class="tsd-asset-bar-btns">' +
            '<button type="button" class="tsd-asset-btn tsd-asset-btn-pdf" id="' + planPdfId + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/><text x="4.5" y="12.5" font-size="5.5" font-weight="bold" fill="currentColor" font-family="system-ui">PDF</text></svg>Export PDF</button>' +
            '<button type="button" class="tsd-asset-btn tsd-asset-btn-md" id="' + planExportId + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/></svg>Export MD</button>' +
            '<button type="button" class="tsd-asset-btn tsd-asset-btn-zip" id="' + planPickupId + '"><svg width="15" height="15" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M8 1v9m0 0L5 7m3 3l3-3M2 12v1a2 2 0 002 2h8a2 2 0 002-2v-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Download ZIP</button>' +
            '</div></div>';
          $cardBody.innerHTML = html;
          // Wire asset buttons
          var btn = document.getElementById(planExportId);
          if (btn) btn.addEventListener('click', function() {
            var md = '# Agent Plan: ' + (ticket.subject || ticket.id) + '\n\n';
            md += '**Ticket:** ' + ticket.id + '\n**Domain:** ' + (ticket.domain || '') + '\n\n---\n\n';
            if (plan.diagnosis) md += '## Diagnosis\n\n' + plan.diagnosis + '\n\n';
            if (plan.proposed_changes && plan.proposed_changes.length) {
              md += '## Proposed Changes\n\n';
              plan.proposed_changes.forEach(function(c, i) { md += (i+1) + '. **`' + (c.file || c.path || '') + '`** \u2014 ' + (c.description || c.change_description || '') + '\n'; });
              md += '\n';
            }
            if (plan.risk_assessment) md += '**Risk:** ' + plan.risk_assessment + '\n';
            if (plan.estimated_effort) md += '**Effort:** ' + plan.estimated_effort + '\n';
            if (plan.ai_reasoning) md += '\n## Reasoning\n\n' + plan.ai_reasoning + '\n';
            navigator.clipboard.writeText(md).then(function() { btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = 'Export MD'; }, 2000); }).catch(function() { var w = window.open('', '_blank'); if (w) { w.document.write('<pre style="white-space:pre-wrap;font-family:monospace;padding:20px">' + md.replace(/</g,'&lt;') + '</pre>'); w.document.title = 'Plan \u2014 ' + ticket.id; } });
          });
          var pickupBtn2 = document.getElementById(planPickupId);
          if (pickupBtn2) pickupBtn2.addEventListener('click', function() { downloadPickupZip(ticket.id); });
          var pdfBtn2 = document.getElementById(planPdfId);
          if (pdfBtn2) pdfBtn2.addEventListener('click', function() { downloadPickupPdf(ticket, plan, null); });
        } else {
          $cardBody.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px">Plan data not available</div>';
        }
      } catch(e) {
        $cardBody.innerHTML = '<div style="color:var(--tsd-red);font-size:12px">Failed to load plan: ' + esc(e.message) + '</div>';
      }

      // Action buttons
      if ($cardActions) {
        $cardActions.innerHTML =
          '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">' +
          '<button class="tsd-btn tsd-btn-green tsd-btn-sm" id="solApproveApply" style="font-weight:700;padding:8px 20px"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M2 8l4 4L14 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Apply</button>' +
          '<button class="tsd-btn tsd-btn-sm" id="solResubmit" style="padding:8px 16px"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M1 4v4h4M15 12V8h-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 5.5A6 6 0 003 4l-2 2m16 4l-2 2a6 6 0 01-10.5-1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Resubmit</button>' +
          '<button class="tsd-btn tsd-btn-danger tsd-btn-sm" id="solRejectDir" style="padding:8px 16px"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M4 12L12 4M4 4l8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Reject + New Direction</button>' +
          '</div>' +
          '<div id="solDirectionPanel" style="display:none">' +
          '<div style="font:600 12px system-ui;color:var(--tsd-red);margin-bottom:6px">Rejected \u2014 Provide mandatory direction for the AI to follow:</div>' +
          '<textarea id="solDirectionText" class="tsd-reply-input" rows="4" placeholder="Tell the AI exactly what to do differently..." style="width:100%;box-sizing:border-box;margin-bottom:8px;font:13px system-ui"></textarea>' +
          '<div style="display:flex;gap:8px"><button class="tsd-btn tsd-btn-danger tsd-btn-sm" id="solDirectionSubmit" style="font-weight:700;padding:8px 20px">Implement New Direction</button><button class="tsd-btn tsd-btn-sm" id="solDirectionCancel" style="padding:8px 12px">Cancel</button></div></div>';

        // Wire Apply
        var applyBtn = document.getElementById('solApproveApply');
        if (applyBtn) {
          applyBtn.addEventListener('click', async function() {
            applyBtn.disabled = true; applyBtn.textContent = 'Applying...';
            try {
              var r = await fetch(AS_API + '?action=approve_plan', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: agentTaskId }) }).then(function(r) { return r.json(); });
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Apply failed: ' + (r.error || 'Unknown error')); applyBtn.disabled = false; applyBtn.textContent = 'Apply'; }
            } catch(e) { alert('Error: ' + e.message); applyBtn.disabled = false; applyBtn.textContent = 'Apply'; }
          });
        }
        // Wire Resubmit
        var resubBtn = document.getElementById('solResubmit');
        if (resubBtn) {
          resubBtn.addEventListener('click', async function() {
            resubBtn.disabled = true; resubBtn.textContent = 'Resubmitting...';
            try {
              var r = await apiCall('resubmit_ticket', { id: ticket.id }, 'POST');
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Error: ' + (r.error || 'Resubmit failed')); resubBtn.disabled = false; resubBtn.textContent = 'Resubmit'; }
            } catch(e) { alert('Error: ' + e.message); resubBtn.disabled = false; resubBtn.textContent = 'Resubmit'; }
          });
        }
        // Wire Reject + New Direction
        var rejectDirBtn = document.getElementById('solRejectDir');
        var dirPanel = document.getElementById('solDirectionPanel');
        var dirText = document.getElementById('solDirectionText');
        var dirSubmit = document.getElementById('solDirectionSubmit');
        var dirCancel = document.getElementById('solDirectionCancel');
        if (rejectDirBtn && dirPanel) {
          rejectDirBtn.addEventListener('click', function() { dirPanel.style.display = ''; rejectDirBtn.style.display = 'none'; if (dirText) dirText.focus(); });
        }
        if (dirCancel && dirPanel && rejectDirBtn) {
          dirCancel.addEventListener('click', function() { dirPanel.style.display = 'none'; rejectDirBtn.style.display = ''; });
        }
        if (dirSubmit && dirText) {
          dirSubmit.addEventListener('click', async function() {
            var direction = (dirText.value || '').trim();
            if (!direction) { dirText.focus(); return; }
            dirSubmit.disabled = true; dirSubmit.textContent = 'Submitting...';
            try {
              var r = await apiCall('reject_with_direction', { id: ticket.id, direction: direction }, 'POST');
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Error: ' + (r.error || 'Failed')); dirSubmit.disabled = false; dirSubmit.textContent = 'Implement New Direction'; }
            } catch(e) { alert('Error: ' + e.message); dirSubmit.disabled = false; dirSubmit.textContent = 'Implement New Direction'; }
          });
        }
      }
      return;
    }

    // ── Applied ──
    if (stage === 'applied') {
      try {
        var [manifestRes, implRes2] = await Promise.all([
          apiCall('get_apply_manifest', { id: ticket.id }),
          apiCall('get_implementation', { id: ticket.id })
        ]);
        var appliedHtml = '';
        if (manifestRes.ok && manifestRes.manifest) {
          var m = manifestRes.manifest;
          var summary = m.summary || {};
          var allFailed = summary.applied === 0 && summary.failed > 0;
          var partialFailed = summary.failed > 0 && summary.applied > 0;
          var bannerClass = allFailed ? 'tsd-apply-banner--error' : partialFailed ? 'tsd-apply-banner--warn' : 'tsd-apply-banner--success';
          var bannerLabel = allFailed ? 'Apply Failed' : partialFailed ? 'Partially Applied' : 'Changes Applied';
          appliedHtml += '<div class="tsd-apply-banner ' + bannerClass + '"><strong>' + bannerLabel + '</strong>' +
            ' <a href="#" class="tsd-link-actions js-scroll-to-actions" style="font-size:11px;margin-left:8px;color:var(--tsd-accent)">View Agentic Actions</a>' +
            '<div style="margin-top:6px;font-size:12px">' +
            summary.applied + '/' + summary.total + ' operations applied' +
            (summary.failed > 0 ? ' <span style="color:var(--tsd-red)">(' + summary.failed + ' failed)</span>' : '') +
            ' &middot; Target: <code>' + esc(m.target_site || '') + '</code> &middot; ' + timeAgo(m.applied_at || '') + ' ago</div></div>';
          var appliedOps = (m.operations || []).filter(function(o) { return o.status === 'applied'; });
          if (appliedOps.length) {
            appliedHtml += '<div class="tsd-apply-files"><strong>Modified files:</strong><ul>';
            appliedOps.forEach(function(o) { appliedHtml += '<li><code>' + esc(o.file || '') + '</code> <span style="color:var(--tsd-muted)">(' + esc(o.type || '') + ')</span></li>'; });
            appliedHtml += '</ul></div>';
          }
          if (m.errors && m.errors.length) {
            appliedHtml += '<div class="tsd-apply-errors"><strong style="color:var(--tsd-red)">Errors:</strong><ul>';
            m.errors.forEach(function(e) { appliedHtml += '<li style="color:var(--tsd-red)">' + esc(e) + '</li>'; });
            appliedHtml += '</ul></div>';
          }
          if (m.backups && m.backups.length) {
            appliedHtml += '<div style="font-size:11px;color:var(--tsd-muted);margin-top:8px">' + m.backups.length + ' file backup(s) stored for rollback</div>';
          }
        }
        var hasImpl2 = implRes2.ok && implRes2.content;
        if (hasImpl2) {
          var guideId2 = 'tsdAppliedGuide_' + Math.random().toString(36).slice(2, 8);
          var toggleId2 = 'tsdAppliedToggle_' + Math.random().toString(36).slice(2, 8);
          appliedHtml += '<div class="tsd-impl-section"><div class="tsd-impl-header" id="' + toggleId2 + '"><span class="tsd-impl-header-title">AGENTIC ACTIONS PERFORMED</span><span class="tsd-collapse-arrow">&#9654;</span></div><div class="tsd-impl-body" id="' + guideId2 + '" style="display:none"><div class="tsd-impl-rendered">' + renderSimpleMd(implRes2.content) + '</div></div></div>';
          setTimeout(function() {
            var tog = document.getElementById(toggleId2);
            var bod = document.getElementById(guideId2);
            if (tog && bod) tog.addEventListener('click', function() { var arrow = tog.querySelector('.tsd-collapse-arrow'); if (bod.style.display === 'none') { bod.style.display = ''; if (arrow) arrow.innerHTML = '&#9660;'; } else { bod.style.display = 'none'; if (arrow) arrow.innerHTML = '&#9654;'; } });
            // "View Agentic Actions" link — expand + scroll to guide
            var scrollLink = document.querySelector('.js-scroll-to-actions');
            if (scrollLink && tog && bod) {
              scrollLink.addEventListener('click', function(ev) {
                ev.preventDefault();
                if (bod.style.display === 'none') { bod.style.display = ''; var arrow = tog.querySelector('.tsd-collapse-arrow'); if (arrow) arrow.innerHTML = '&#9660;'; }
                tog.scrollIntoView({ behavior: 'smooth', block: 'start' });
              });
            }
          }, 100);
        }
        $cardBody.innerHTML = appliedHtml || '<div style="color:var(--tsd-muted)">Changes applied (no manifest details)</div>';
      } catch(e) {
        $cardBody.innerHTML = '<div style="color:var(--tsd-red);font-size:12px">Failed to load: ' + esc(e.message) + '</div>';
      }
      // Actions: Decision tree with Rollback + Verify
      if ($cardActions) {
        $cardActions.innerHTML =
          '<div class="tsd-decision-tree" style="margin-top:0">' +
          '<div class="tsd-dt-label">WHAT DO YOU WANT TO DO?</div>' +
          '<div class="tsd-dt-options">' +
          '<div class="tsd-dt-option">' +
            '<button class="tsd-dt-btn tsd-dt-btn--execute" id="tsdSaveVerification" disabled>' +
              '<span class="tsd-dt-btn-icon">&#10003;</span>' +
              '<span class="tsd-dt-btn-title">Mark as Verified</span>' +
            '</button>' +
            '<div class="tsd-dt-desc"><label style="cursor:pointer"><input type="checkbox" class="js-verify-check" data-key="reviewed" style="margin-right:6px;vertical-align:-1px"> I\'ve reviewed the changes</label> — then click to <strong>advance the ticket</strong> to verified.</div>' +
          '</div>' +
          '<div class="tsd-dt-option">' +
            '<button class="tsd-dt-btn tsd-dt-btn--primary" id="solRollback">' +
              '<span class="tsd-dt-btn-icon">&#8634;</span>' +
              '<span class="tsd-dt-btn-title">Rollback All Changes</span>' +
            '</button>' +
            '<div class="tsd-dt-desc">Restores all modified files from backup. <strong>Undoes every applied operation.</strong></div>' +
          '</div>' +
          '<div class="tsd-dt-option">' +
            '<button class="tsd-dt-btn tsd-dt-btn--cli" id="solSendToCli2" style="background:#e8b931;color:#000;border-color:#c9a020">' +
              '<span class="tsd-dt-btn-icon">\uD83D\uDD27</span>' +
              '<span class="tsd-dt-btn-title">Send to Claude CLI</span>' +
            '</button>' +
            '<div class="tsd-dt-desc">Forward this ticket to <strong>Claude Code CLI</strong> for hands-on deep work.</div>' +
          '</div>' +
          '</div></div>';
        wireSendToCli('solSendToCli2', ticket);
        var rollbackBtn = document.getElementById('solRollback');
        if (rollbackBtn) {
          rollbackBtn.addEventListener('click', async function() {
            if (!confirm('Rollback all applied changes? This will restore files from backup.')) return;
            rollbackBtn.disabled = true; rollbackBtn.querySelector('.tsd-dt-btn-title').textContent = 'Rolling back...';
            try {
              var r = await apiCall('rollback_changes', { id: ticket.id }, 'POST');
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Rollback failed: ' + (r.error || 'Unknown error')); rollbackBtn.disabled = false; rollbackBtn.querySelector('.tsd-dt-btn-title').textContent = 'Rollback All Changes'; }
            } catch(e) { alert('Rollback error: ' + e.message); rollbackBtn.disabled = false; rollbackBtn.querySelector('.tsd-dt-btn-title').textContent = 'Rollback All Changes'; }
          });
        }
        wireVerificationChecklist(ticket);
      }
      return;
    }

    // ── Impl Ready / Verification / Deployed ──
    if (stage === 'impl_ready' || stage === 'verification' || isDeployed) {
      var resultsHtml = '';
      try {
        var [runRes, planRes, implRes] = await Promise.all([
          fetch(AS_API + '?action=task_history&id=' + encodeURIComponent(agentTaskId) + '&limit=5').then(function(r) { return r.json(); }),
          fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(agentTaskId)).then(function(r) { return r.json(); }),
          apiCall('get_implementation', { id: ticket.id })
        ]);
        var execRun = null;
        if (runRes.ok && runRes.data && runRes.data.history) {
          for (var ri = 0; ri < runRes.data.history.length; ri++) {
            if (runRes.data.history[ri].pipeline === 'ticket_execute') { execRun = runRes.data.history[ri]; break; }
          }
        }
        var plan = (planRes.ok && planRes.data && planRes.data.plan) ? planRes.data.plan : null;
        resultsHtml = buildExecutionResults(execRun, plan, agentInfo);
        var todoHtml = '';
        if (plan) {
          todoHtml = '<div class="tsd-todo-list"><div class="tsd-todo-title">Admin To-Do</div>';
          if (plan.diagnosis) todoHtml += '<div class="tsd-todo-diagnosis">' + esc(plan.diagnosis) + '</div>';
          if (plan.proposed_changes && plan.proposed_changes.length) {
            todoHtml += '<ul class="tsd-todo-items">';
            for (var ti = 0; ti < plan.proposed_changes.length; ti++) {
              var ch = plan.proposed_changes[ti]; var fp = ch.file || ch.path || ''; var cd = ch.description || ch.change_description || '';
              todoHtml += '<li><code>' + esc(fp) + '</code>'; if (cd) todoHtml += ' &mdash; ' + esc(cd); todoHtml += '</li>';
            }
            todoHtml += '</ul>';
          }
          if (plan.risk_assessment) {
            var rc = plan.risk_assessment === 'low' ? 'var(--tsd-green)' : plan.risk_assessment === 'high' ? 'var(--tsd-red)' : 'var(--tsd-yellow)';
            todoHtml += '<div class="tsd-todo-meta">Risk: <strong style="color:' + rc + '">' + esc(plan.risk_assessment) + '</strong>';
            if (plan.estimated_effort) todoHtml += ' &middot; Effort: <strong>' + esc(plan.estimated_effort) + '</strong>';
            todoHtml += '</div>';
          }
          todoHtml += '</div>';
        }
        // Asset bar
        var assetIds = {
          zip: 'tsdAssetZip_' + Math.random().toString(36).slice(2, 8),
          md: 'tsdAssetMd_' + Math.random().toString(36).slice(2, 8),
          pdf: 'tsdAssetPdf_' + Math.random().toString(36).slice(2, 8),
          guide: 'tsdGuideBody_' + Math.random().toString(36).slice(2, 8),
          toggle: 'tsdGuideToggle_' + Math.random().toString(36).slice(2, 8),
        };
        var hasImpl = implRes.ok && implRes.content;
        var assetHtml = '<div class="tsd-asset-bar"><div class="tsd-asset-bar-label">DOWNLOADS</div><div class="tsd-asset-bar-btns">' +
          '<button type="button" class="tsd-asset-btn tsd-asset-btn-pdf" id="' + assetIds.pdf + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/><text x="4.5" y="12.5" font-size="5.5" font-weight="bold" fill="currentColor" font-family="system-ui">PDF</text></svg>Export PDF</button>';
        if (hasImpl) {
          assetHtml += '<button type="button" class="tsd-asset-btn tsd-asset-btn-md" id="' + assetIds.md + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/></svg>Export MD</button>';
        }
        assetHtml += '<button type="button" class="tsd-asset-btn tsd-asset-btn-zip" id="' + assetIds.zip + '"><svg width="15" height="15" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M8 1v9m0 0L5 7m3 3l3-3M2 12v1a2 2 0 002 2h8a2 2 0 002-2v-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Download ZIP</button></div></div>';
        // Implementation guide
        var implHtml = '';
        if (hasImpl) {
          implHtml = '<div class="tsd-impl-section"><div class="tsd-impl-header" id="' + assetIds.toggle + '"><span class="tsd-impl-header-title">AGENTIC ACTIONS PERFORMED</span><span class="tsd-collapse-arrow">&#9660;</span></div><div class="tsd-impl-body" id="' + assetIds.guide + '"><div class="tsd-impl-rendered">' + renderSimpleMd(implRes.content) + '</div></div></div>';
        }
        $cardBody.innerHTML = resultsHtml + todoHtml + assetHtml + implHtml;
        wireExecResultButtons($cardBody, ticket);
        // Wire asset buttons
        setTimeout(function() {
          var pdfBtn = document.getElementById(assetIds.pdf);
          if (pdfBtn) pdfBtn.addEventListener('click', function() { downloadPickupPdf(ticket, plan, hasImpl ? implRes.content : null); });
          var zipBtn = document.getElementById(assetIds.zip);
          if (zipBtn) zipBtn.addEventListener('click', function() { downloadPickupZip(ticket.id); });
          var mdBtn = document.getElementById(assetIds.md);
          if (mdBtn && hasImpl) {
            mdBtn.addEventListener('click', function() {
              var md = '# Agentic Actions: ' + (ticket.subject || ticket.id) + '\n\n**Ticket:** ' + ticket.id + '\n**Domain:** ' + (ticket.domain || '') + '\n**Category:** ' + (CAT_LABELS[ticket.category] || ticket.category) + '\n**Urgency:** ' + (ticket.urgency || '') + '\n\n---\n\n' + implRes.content;
              navigator.clipboard.writeText(md).then(function() { mdBtn.textContent = 'Copied!'; setTimeout(function() { mdBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/></svg>Export MD'; }, 2000); }).catch(function() { var w = window.open('', '_blank'); if (w) { w.document.write('<pre style="white-space:pre-wrap;font-family:monospace;padding:20px">' + md.replace(/</g, '&lt;') + '</pre>'); w.document.title = 'Implementation Guide \u2014 ' + ticket.id; } });
            });
          }
          var toggleEl = document.getElementById(assetIds.toggle);
          var bodyEl = document.getElementById(assetIds.guide);
          if (toggleEl && bodyEl) {
            toggleEl.addEventListener('click', function() { var arrow = toggleEl.querySelector('.tsd-collapse-arrow'); if (bodyEl.style.display === 'none') { bodyEl.style.display = ''; if (arrow) arrow.innerHTML = '&#9660;'; } else { bodyEl.style.display = 'none'; if (arrow) arrow.innerHTML = '&#9654;'; } });
          }
        }, 100);
      } catch(e) {
        $cardBody.innerHTML = resultsHtml + '<div style="color:var(--tsd-red);font-size:12px">Failed to load: ' + esc(e.message) + '</div>';
      }
      // Actions for non-deployed stages
      if ($cardActions && !isDeployed) {
        var actionsHtml = '';
        if (stage === 'impl_ready') {
          actionsHtml += '<div class="tsd-apply-actions" style="margin-bottom:12px"><button class="tsd-btn tsd-btn-green tsd-btn-sm" id="solApplyChanges" style="font-weight:700;padding:8px 20px"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M2 8l4 4L14 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Apply Implementation</button><span style="font-size:11px;color:var(--tsd-muted);margin-left:10px">or execute manually using the guide below</span></div>';
        }
        actionsHtml += '<div class="tsd-decision-tree" style="margin-top:0"><div class="tsd-dt-label">WHAT DO YOU WANT TO DO?</div><div class="tsd-dt-options">' +
          '<div class="tsd-dt-option"><button class="tsd-dt-btn tsd-dt-btn--execute" id="tsdSaveVerification" disabled><span class="tsd-dt-btn-icon">&#10003;</span><span class="tsd-dt-btn-title">Mark as Verified</span></button>' +
          '<div class="tsd-dt-desc"><label style="cursor:pointer"><input type="checkbox" class="js-verify-check" data-key="reviewed" style="margin-right:6px;vertical-align:-1px"> I\'ve reviewed the changes</label> — then click to <strong>advance the ticket</strong>.</div></div>' +
          '<div class="tsd-dt-option"><button class="tsd-dt-btn tsd-dt-btn--cli" id="solSendToCli3" style="background:#e8b931;color:#000;border-color:#c9a020"><span class="tsd-dt-btn-icon">\uD83D\uDD27</span><span class="tsd-dt-btn-title">Send to Claude CLI</span></button>' +
          '<div class="tsd-dt-desc">Forward to <strong>Claude Code CLI</strong> for hands-on deep work.</div></div>' +
          '</div></div>';
        $cardActions.innerHTML = actionsHtml;
        wireSendToCli('solSendToCli3', ticket);
        var applyBtn = document.getElementById('solApplyChanges');
        if (applyBtn) {
          applyBtn.addEventListener('click', async function() {
            applyBtn.disabled = true; applyBtn.textContent = 'Applying...';
            try {
              var r = await apiCall('apply_changes', { id: ticket.id }, 'POST');
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Apply failed: ' + (r.error || 'Unknown error')); applyBtn.disabled = false; applyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M2 8l4 4L14 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Apply Implementation'; }
            } catch(e) { alert('Apply error: ' + e.message); applyBtn.disabled = false; applyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M2 8l4 4L14 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Apply Implementation'; }
          });
        }
        wireVerificationChecklist(ticket);
      } else if ($cardActions) {
        $cardActions.innerHTML = '';
      }
      return;
    }

    // ── Error ──
    if (isError && agentTaskId) {
      try {
        var [errRunRes, errPlanRes, errImplRes] = await Promise.all([
          fetch(AS_API + '?action=task_history&id=' + encodeURIComponent(agentTaskId) + '&limit=10').then(function(r) { return r.json(); }),
          fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(agentTaskId)).then(function(r) { return r.json(); }),
          apiCall('get_implementation', { id: ticket.id })
        ]);
        var errExecRun = null;
        if (errRunRes.ok && errRunRes.data && errRunRes.data.history) {
          for (var eri = 0; eri < errRunRes.data.history.length; eri++) {
            if (errRunRes.data.history[eri].status === 'error') { errExecRun = errRunRes.data.history[eri]; break; }
          }
        }
        var errPlan = (errPlanRes.ok && errPlanRes.data && errPlanRes.data.plan) ? errPlanRes.data.plan : null;

        // ── Error Type Detection ──
        var errMsg = (agentInfo && agentInfo.last_error) || (errExecRun && errExecRun.error) || '';
        var errMsgLower = errMsg.toLowerCase();
        var errType = 'unknown';
        var errTypeLabel = '';
        if (/token.*(expired|incorrect|invalid)|api.*(key|error)|auth.*fail|unauthorized|403|401/i.test(errMsg)) {
          errType = 'credentials';
          errTypeLabel = 'CREDENTIALS / API KEY';
        } else if (/not found|no such file|file.*missing|path.*invalid|enoent/i.test(errMsg)) {
          errType = 'path';
          errTypeLabel = 'FILE PATH / NOT FOUND';
        } else if (/timeout|timed out|deadline|ETIMEDOUT/i.test(errMsg)) {
          errType = 'timeout';
          errTypeLabel = 'TIMEOUT / CONNECTIVITY';
        } else if (/quota|rate.limit|too many|429/i.test(errMsg)) {
          errType = 'quota';
          errTypeLabel = 'QUOTA / RATE LIMIT';
        } else if (/domain|dns|resolve|host/i.test(errMsg)) {
          errType = 'domain';
          errTypeLabel = 'DOMAIN / DNS';
        }

        // ── Two-Column Layout: Left (decision tree) + Right (pipeline steps + debug) ──
        var bodyHtml = '<div class="tsd-error-layout" style="display:flex;gap:16px;flex-wrap:wrap">';

        // LEFT COLUMN — Error status + escalation
        bodyHtml += '<div class="tsd-error-left" style="flex:1;min-width:220px">';
        bodyHtml += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px"><span style="color:var(--tsd-red);font-size:20px">&#10007;</span><span style="font:700 20px system-ui;color:var(--tsd-fg)">Execution failed</span></div>';

        // Error type notice
        if (errTypeLabel) {
          bodyHtml += '<div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;margin-bottom:12px;text-align:center">';
          bodyHtml += '<div style="font:700 11px system-ui;color:var(--tsd-red);text-transform:uppercase;letter-spacing:1px">Error type detected:</div>';
          bodyHtml += '<div style="font:700 14px system-ui;color:var(--tsd-fg);margin-top:4px">' + esc(errTypeLabel) + '</div>';
          bodyHtml += '</div>';
        }

        // Send to CLI
        bodyHtml += '<button class="tsd-btn" id="solSendToCli" style="background:#e8b931;color:#000;font-weight:700;border-color:#e8b931;width:100%;padding:10px;font-size:14px">\uD83D\uDD27 Send to Claude CLI</button>';
        bodyHtml += '</div>';

        // RIGHT COLUMN — Pipeline steps + Debug log
        bodyHtml += '<div class="tsd-error-right" style="flex:1.5;min-width:300px">';

        // Pipeline Steps — separate from debug, these are green/ok
        if (errExecRun && errExecRun.steps && errExecRun.steps.length) {
          bodyHtml += '<div style="margin-bottom:12px;padding:10px 12px;background:rgba(52,211,153,.04);border:1px solid rgba(52,211,153,.15);border-radius:8px"><div style="font:600 10px system-ui;color:var(--tsd-green,#34d399);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Pipeline Steps</div>';
          for (var dsi = 0; dsi < errExecRun.steps.length; dsi++) {
            var ds = errExecRun.steps[dsi]; var dsOk = ds.status === 'ok' || ds.status === 'success'; var dsWarn = ds.status === 'warn';
            var dsIcon = dsOk ? '&#10003;' : dsWarn ? '&#9888;' : '&#10007;'; var dsCls = dsOk ? 'tsd-step-ok' : dsWarn ? 'tsd-step-warn' : 'tsd-step-err';
            bodyHtml += '<div class="tsd-debug-step"><span class="tsd-debug-step-icon ' + dsCls + '">' + dsIcon + '</span><span class="tsd-debug-step-name" style="font-weight:600">' + esc(ds.step || ('Step ' + (dsi + 1))) + '</span><span class="tsd-debug-step-detail">' + esc(ds.detail || '') + '</span></div>';
          }
          bodyHtml += '</div>';
        }

        // Debug Log
        bodyHtml += '<div class="tsd-debug-log"><div class="tsd-debug-log-header">Debug Log</div>';
        if (agentInfo && agentInfo.last_error) {
          bodyHtml += '<div class="tsd-debug-error-box"><div class="tsd-debug-error-label">Error</div><div class="tsd-debug-error-msg">' + esc(agentInfo.last_error) + '</div>';
          if (agentInfo.last_failed_step) bodyHtml += '<div class="tsd-debug-error-step">Failed at: <code>' + esc(agentInfo.last_failed_step) + '</code></div>';
          bodyHtml += '</div>';
        }
        if (errExecRun) {
          bodyHtml += '<div class="tsd-debug-run-info"><span>Pipeline: <strong>' + esc(errExecRun.pipeline || '?') + '</strong></span><span>Run: ' + esc(errExecRun.run_id || '') + '</span>';
          if (errExecRun.started_at) bodyHtml += '<span>' + esc(errExecRun.started_at) + '</span>';
          if (errExecRun.duration_ms) { var dur = errExecRun.duration_ms; bodyHtml += '<span>' + (dur < 1000 ? dur + 'ms' : (dur / 1000).toFixed(1) + 's') + '</span>'; }
          bodyHtml += '</div>';
          if (errExecRun.error) bodyHtml += '<div class="tsd-debug-run-error">' + esc(errExecRun.error) + '</div>';
        }
        if (errRunRes.ok && errRunRes.data && errRunRes.data.history && errRunRes.data.history.length > 1) {
          bodyHtml += '<div class="tsd-debug-history-title">Run History</div><div class="tsd-debug-history">';
          var hist = errRunRes.data.history;
          for (var hi = 0; hi < Math.min(hist.length, 5); hi++) {
            var hr = hist[hi]; var hrOk = hr.status === 'success';
            bodyHtml += '<div class="tsd-debug-history-row"><span class="tsd-debug-step-icon ' + (hrOk ? 'tsd-step-ok' : 'tsd-step-err') + '">' + (hrOk ? '&#10003;' : '&#10007;') + '</span><span>' + esc(hr.pipeline || '?') + '</span><span>' + esc(hr.started_at || '') + '</span>' + (hr.error ? '<span class="tsd-debug-hist-err">' + esc(hr.error.substring(0, 80)) + '</span>' : '') + '</div>';
          }
          bodyHtml += '</div>';
        }
        bodyHtml += '</div>';

        // ── Inline Fix Controls ── contextual to error type
        bodyHtml += '<div class="tsd-err-fix-panel" id="tsdErrFixPanel">';
        bodyHtml += '<div style="font:700 11px system-ui;color:#e8b931;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">&#9881; Quick Fix</div>';

        if (errType === 'credentials' || errType === 'quota' || errType === 'timeout') {
          // Provider selector — loaded async
          bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">AI Provider</label>';
          bodyHtml += '<select id="tsdErrProvider" class="tsd-err-fix-input" style="width:100%;margin-bottom:8px"><option value="">Loading providers...</option></select>';
          if (errType === 'credentials') {
            bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">API Key / Endpoint</label>';
            bodyHtml += '<input type="text" id="tsdErrApiKey" class="tsd-err-fix-input" placeholder="Paste corrected API key or endpoint URL..." style="width:100%;margin-bottom:8px">';
            bodyHtml += '<div style="font-size:11px;opacity:.5;margin-bottom:10px">Leave blank to keep existing key. Or <a href="/admin/modules/AIResources/AIResources.php" style="color:#e8b931" target="_blank">open full AI Resources manager</a>.</div>';
          }
        }

        if (errType === 'path' || errType === 'domain') {
          var pathHint = '';
          // Try to extract the bad path/domain from error message
          var pathMatch = errMsg.match(/(?:path|file|directory)[:\s]*["']?([^\s"']+)/i);
          var domainMatch = errMsg.match(/(?:domain|host|url)[:\s]*["']?([^\s"']+)/i);
          if (errType === 'path') {
            pathHint = pathMatch ? pathMatch[1] : '';
            bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">Correct Path</label>';
            bodyHtml += '<input type="text" id="tsdErrPath" class="tsd-err-fix-input" placeholder="/var/www/vhosts/..." value="' + esc(pathHint) + '" style="width:100%;margin-bottom:8px">';
          } else {
            pathHint = domainMatch ? domainMatch[1] : (ticket.domain || '');
            bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">Correct Domain</label>';
            bodyHtml += '<input type="text" id="tsdErrDomain" class="tsd-err-fix-input" placeholder="example.com" value="' + esc(pathHint) + '" style="width:100%;margin-bottom:8px">';
          }
          // Also show provider selector for path/domain errors
          bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">AI Provider (optional switch)</label>';
          bodyHtml += '<select id="tsdErrProvider" class="tsd-err-fix-input" style="width:100%;margin-bottom:8px"><option value="">Loading providers...</option></select>';
        }

        if (errType === 'unknown') {
          bodyHtml += '<label style="font-size:12px;font-weight:600;color:var(--tsd-fg);display:block;margin-bottom:4px">AI Provider (try a different one)</label>';
          bodyHtml += '<select id="tsdErrProvider" class="tsd-err-fix-input" style="width:100%;margin-bottom:8px"><option value="">Loading providers...</option></select>';
        }

        bodyHtml += '<button class="tsd-btn" id="tsdErrFixResubmit" style="background:#e8b931;color:#000;font-weight:700;border-color:#e8b931;width:100%;padding:10px;font-size:13px;margin-top:4px">&#9881; FIX &amp; RESUBMIT</button>';
        bodyHtml += '</div>'; // end fix panel

        bodyHtml += '</div>'; // end right column
        bodyHtml += '</div>'; // end layout

        // Previous implementation guide (collapsible)
        var errImplHtml = '';
        var errHasImpl = errImplRes.ok && errImplRes.content;
        if (errHasImpl) {
          var errGuideId = 'tsdErrGuide_' + Math.random().toString(36).slice(2, 8);
          var errToggleId = 'tsdErrToggle_' + Math.random().toString(36).slice(2, 8);
          errImplHtml = '<div class="tsd-impl-section"><div class="tsd-impl-header" id="' + errToggleId + '"><span class="tsd-impl-header-title">PREVIOUS IMPLEMENTATION GUIDE</span><span class="tsd-collapse-arrow">&#9654;</span></div><div class="tsd-impl-body" id="' + errGuideId + '" style="display:none"><div class="tsd-impl-rendered">' + renderSimpleMd(errImplRes.content) + '</div></div></div>';
          setTimeout(function() {
            var tog = document.getElementById(errToggleId); var bod = document.getElementById(errGuideId);
            if (tog && bod) tog.addEventListener('click', function() { var arrow = tog.querySelector('.tsd-collapse-arrow'); if (bod.style.display === 'none') { bod.style.display = ''; if (arrow) arrow.innerHTML = '&#9660;'; } else { bod.style.display = 'none'; if (arrow) arrow.innerHTML = '&#9654;'; } });
          }, 100);
        }
        $cardBody.innerHTML = bodyHtml + errImplHtml;
        wireSendToCli('solSendToCli', ticket);

        // Load providers into dropdown
        loadErrProviderDropdown();

        // Wire "Fix & Resubmit" button
        wireErrFixResubmit(ticket, agentTaskId);

      } catch(e) {
        $cardBody.innerHTML = '<div style="color:var(--tsd-red);font-size:13px">Agent encountered an error during processing.</div>';
      }
      // Error actions — bottom bar
      if ($cardActions) {
        $cardActions.innerHTML =
          '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">' +
            '<button class="tsd-btn tsd-btn-danger" id="solResubmit" style="font-weight:700;padding:10px 20px;font-size:13px">ERRORS HAVE BEEN FIXED, RESUBMIT</button>' +
            '<button class="tsd-btn tsd-btn-clarify tsd-btn-sm" id="solErrorRetry">Provide Context &amp; Retry</button>' +
          '</div>' +
          '<div class="tsd-solution-modify-form" id="solErrorForm" style="display:none"><textarea class="tsd-ap-clarify-textarea" id="solErrorText" placeholder="Add context to help the agent succeed on retry..." rows="3"></textarea><div class="tsd-ap-clarify-submit-row"><button class="tsd-btn tsd-btn-primary tsd-btn-sm" id="solErrorSubmit">Submit &amp; Retry</button><button class="tsd-btn tsd-btn-sm" id="solErrorCancel">Cancel</button></div></div>';
        var resubBtn = document.getElementById('solResubmit');
        if (resubBtn) {
          resubBtn.addEventListener('click', async function() {
            resubBtn.disabled = true; resubBtn.textContent = 'Resubmitting...';
            try {
              var r = await apiCall('resubmit_ticket', { id: ticket.id }, 'POST');
              if (r.ok) { showDetail(ticket.id); loadTickets(); }
              else { alert('Error: ' + (r.error || 'Resubmit failed')); resubBtn.disabled = false; resubBtn.textContent = 'ERRORS HAVE BEEN FIXED, RESUBMIT'; }
            } catch(e) { alert('Error: ' + e.message); resubBtn.disabled = false; resubBtn.textContent = 'ERRORS HAVE BEEN FIXED, RESUBMIT'; }
          });
        }
        wireErrorRetry(ticket.id, agentTaskId);
      }
      return;
    }
  }

  /* ── Wire Error Retry in Solution Block ── */
  function wireExecResultButtons(container, ticket) {
    // "Send to CLI" button inside Execution Results
    var cliBtn = container.querySelector('.js-exec-send-cli');
    if (cliBtn) {
      cliBtn.addEventListener('click', function() {
        sendToCliWithOverlay(ticket.id);
      });
    }
    // "Open Support Ticket" button — auto-creates a new ticket from the failure
    var ticketBtn = container.querySelector('.js-exec-open-ticket');
    if (ticketBtn) {
      ticketBtn.addEventListener('click', async function() {
        ticketBtn.disabled = true; ticketBtn.textContent = 'Creating...';
        try {
          var desc = 'Auto-generated from failed execution of ticket ' + esc(ticket.id) + '.\n\n';
          desc += 'Original: ' + (ticket.subject || '') + '\n';
          desc += 'Error: ' + (ticket.last_error || ticket.agent_error || 'Execution failed') + '\n';
          desc += 'Domain: ' + (ticket.domain || '') + '\n';
          if (ticket.agent_task_id) desc += 'Agent Task: ' + ticket.agent_task_id;
          var r = await apiCall('submit_ticket', {
            subject: '[Escalation] ' + (ticket.subject || ticket.id),
            description: desc,
            domain: ticket.domain || '',
            category: ticket.category || 'bug',
            urgency: 'high',
            submitted_by: 'system',
            site_key: 'local',
            parent_ticket: ticket.id,
          }, 'POST');
          if (r.ok) {
            ticketBtn.textContent = '\u2705 Ticket Created';
            ticketBtn.style.background = '#059669'; ticketBtn.style.color = '#fff';
            loadTickets();
          } else {
            alert('Failed: ' + (r.error || 'Unknown'));
            ticketBtn.disabled = false; ticketBtn.textContent = 'Open Support Ticket';
          }
        } catch(e) { ticketBtn.disabled = false; ticketBtn.textContent = 'Open Support Ticket'; }
      });
    }
  }

  /* ── Send to CLI with full-screen overlay + navigate back ── */
  async function sendToCliWithOverlay(ticketId) {
    // Create overlay
    var ov = document.createElement('div');
    ov.className = 'tsd-cli-overlay';
    ov.innerHTML = '<div class="tsd-cli-overlay-box">' +
      '<div class="tsd-cli-spinner"></div>' +
      '<div class="tsd-cli-msg">Submitting to Claude Code CLI&hellip;</div>' +
      '</div>';
    document.body.appendChild(ov);

    try {
      var r = await apiCall('send_to_cli', { id: ticketId }, 'POST');
      if (r.ok) {
        // Show success
        ov.querySelector('.tsd-cli-spinner').style.display = 'none';
        ov.querySelector('.tsd-cli-msg').innerHTML = '<span style="color:#34d399;font-size:28px">&#10003;</span><br>' +
          '<strong>Received by CLI Inbox</strong><br>' +
          '<span style="font-size:13px;opacity:.7">Ticket is now pending Claude Code intervention</span>';
        // After brief pause, go back to listing
        setTimeout(function() {
          ov.remove();
          hideDetail();
          loadTickets();
        }, 1800);
      } else {
        ov.remove();
        alert('Send to CLI failed: ' + (r.error || 'Unknown error'));
      }
    } catch(e) {
      ov.remove();
      alert('Error: ' + e.message);
    }
  }

  function wireSendToCli(btnId, ticket) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function() {
      sendToCliWithOverlay(ticket.id);
    });
  }

  /* ── Load AI providers into error fix dropdown ── */
  async function loadErrProviderDropdown() {
    var sel = document.getElementById('tsdErrProvider');
    if (!sel) return;
    try {
      var r = await fetch('/admin/modules/AIResources/api.php?action=get_state', { headers: { 'Accept': 'application/json' } }).then(function(r) { return r.json(); });
      var providers = (r.ok && r.data && r.data.providers) ? r.data.providers : null;
      var activeId = (r.ok && r.data && r.data.config) ? (r.data.config.activeProvider || '') : '';
      if (!providers || !Object.keys(providers).length) { sel.innerHTML = '<option value="">(No providers found)</option>'; return; }
      sel.innerHTML = '<option value="">(Keep current provider)</option>';
      for (var pid in providers) {
        var p = providers[pid];
        var label = (p.label || pid) + ' (' + (p.type || '?') + ' / ' + (p.model || '?') + ')';
        var isActive = pid === activeId;
        sel.innerHTML += '<option value="' + esc(pid) + '"' + (isActive ? ' selected' : '') + '>' + esc(label) + (isActive ? ' [current]' : '') + '</option>';
      }
    } catch(e) {
      sel.innerHTML = '<option value="">(Failed to load providers)</option>';
    }
  }

  /* ── Wire "Fix & Resubmit" with inline corrections ── */
  function wireErrFixResubmit(ticket, agentTaskId) {
    var btn = document.getElementById('tsdErrFixResubmit');
    if (!btn) return;
    btn.addEventListener('click', async function() {
      var provider = (document.getElementById('tsdErrProvider') || {}).value || '';
      var apiKey = (document.getElementById('tsdErrApiKey') || {}).value || '';
      var fixPath = (document.getElementById('tsdErrPath') || {}).value || '';
      var fixDomain = (document.getElementById('tsdErrDomain') || {}).value || '';

      btn.disabled = true; btn.textContent = 'Applying fixes & resubmitting...';

      try {
        // If API key was provided, update the provider config
        if (apiKey && provider) {
          var provUpdates = {};
          provUpdates[provider] = { apiKey: apiKey };
          await fetch('/admin/modules/AIResources/api.php?action=save_config', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ providers: provUpdates })
          });
        }

        // If provider was changed, update the agent task config
        if (provider && agentTaskId) {
          var taskRes = await fetch(AS_API + '?action=get_task&id=' + encodeURIComponent(agentTaskId)).then(function(r) { return r.json(); });
          if (taskRes.ok && taskRes.data) {
            var taskData = taskRes.data;
            taskData.config = taskData.config || {};
            taskData.config.provider = provider;
            await fetch(AS_API + '?action=save_task', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(taskData)
            });
          }
        }

        // If domain was corrected, update the ticket
        if (fixDomain) {
          await apiCall('update_ticket', { id: ticket.id, domain: fixDomain }, 'POST');
        }

        // If path was corrected, add a note so the agent has context
        if (fixPath) {
          await apiCall('add_note', { id: ticket.id, note: 'Corrected path: ' + fixPath }, 'POST');
        }

        // Now resubmit
        var r = await apiCall('resubmit_ticket', { id: ticket.id }, 'POST');
        if (r.ok) {
          showDetail(ticket.id); loadTickets();
        } else {
          alert('Resubmit failed: ' + (r.error || 'Unknown error'));
          btn.disabled = false; btn.innerHTML = '&#9881; FIX &amp; RESUBMIT';
        }
      } catch(e) {
        alert('Error: ' + e.message);
        btn.disabled = false; btn.innerHTML = '&#9881; FIX &amp; RESUBMIT';
      }
    });
  }

  function wireErrorRetry(ticketId, taskId) {
    const retryBtn = document.getElementById('solErrorRetry');
    const form = document.getElementById('solErrorForm');
    const text = document.getElementById('solErrorText');
    const submit = document.getElementById('solErrorSubmit');
    const cancel = document.getElementById('solErrorCancel');

    if (retryBtn && form) {
      retryBtn.addEventListener('click', function() {
        form.style.display = '';
        retryBtn.style.display = 'none';
        if (text) text.focus();
      });
    }
    if (cancel && form && retryBtn) {
      cancel.addEventListener('click', function() {
        form.style.display = 'none';
        retryBtn.style.display = '';
      });
    }
    if (submit) {
      submit.addEventListener('click', async function() {
        const val = text ? text.value.trim() : '';
        if (!val) { alert('Please enter additional context.'); return; }
        submit.disabled = true;
        submit.textContent = 'Retrying...';
        try {
          const r = await apiCall('clarify_ticket', { id: ticketId, clarification: val }, 'POST');
          if (r.ok) { showDetail(ticketId); loadTickets(); }
          else { alert('Error: ' + (r.error || 'Retry failed')); submit.disabled = false; submit.textContent = 'Submit & Retry'; }
        } catch(e) { alert('Error: ' + e.message); submit.disabled = false; submit.textContent = 'Submit & Retry'; }
      });
    }
  }

  /* ── Agent Progress (rendered into thread stage card) ── */
  function renderAgentProgress(ticket, agentInfo, target) {
    if (!target) return;
    const stage = ticket.lifecycle_stage || 'submitted';
    const hasAgent = !!ticket.agent_exported;
    const agentTaskId = ticket.agent_task_id || (agentInfo && agentInfo.agent_task_id) || '';
    const clarifications = ticket.clarifications || [];

    if (!hasAgent && stage === 'submitted') {
      target.innerHTML = '<div class="tsd-ap-status">' +
        '<span class="tsd-ap-icon">&#9711;</span>' +
        '<span>Not queued for AI analysis</span>' +
        '</div>';
      return;
    }

    let statusHtml = '';
    let detailsHtml = '';
    let planHtml = '';
    let actionsHtml = '';
    let clarifyHtml = '';

    switch (stage) {
      case 'queued':
        statusHtml = '<span class="tsd-ap-spinner"></span>' +
          '<span>Queued for AI analysis &mdash; waiting for next worker run</span>';
        break;
      case 'analyzing':
        statusHtml = '<span class="tsd-ap-spinner"></span>' +
          '<span>AI is analyzing this ticket...</span>';
        detailsHtml = buildAnalyzingPanel(agentInfo);
        break;
      case 'plan_ready':
        statusHtml = '<span class="tsd-ap-icon" style="color:var(--tsd-yellow)">&#9679;</span>' +
          '<span>AI analysis complete &mdash; <strong>plan ready for review</strong></span>';
        break;
      case 'approved':
        statusHtml = '<span class="tsd-ap-spinner"></span>' +
          '<span>Plan approved &mdash; generating implementation guide...</span>';
        break;
      case 'applying':
        statusHtml = '<span class="tsd-ap-spinner"></span>' +
          '<span>Applying implementation...</span>';
        break;
      case 'applied':
        statusHtml = '<span class="tsd-ap-icon tsd-ap-icon-done">&#10003;</span>' +
          '<span><strong>Implementation Applied</strong> &mdash; verify on the live site</span>';
        break;
      case 'impl_ready':
      case 'verification':
        statusHtml = '<span class="tsd-ap-icon" style="color:var(--tsd-orange,#fb923c)">&#9888;</span>' +
          '<span><strong>Needs Verification</strong> &mdash; implementation guide generated, awaiting human QA</span>';
        detailsHtml = '<div class="tsd-ap-verification-banner">' +
          '<p>An AI implementation guide has been generated. <strong>Review the changes, test them, and verify before resolving.</strong></p>' +
          '<div class="tsd-verification-checklist" id="tsdVerificationChecklist">' +
          '<label><input type="checkbox" class="js-verify-check" data-key="reviewed"> Changes reviewed</label>' +
          '<label><input type="checkbox" class="js-verify-check" data-key="tested"> Changes tested</label>' +
          '<label><input type="checkbox" class="js-verify-check" data-key="no_regressions"> No regressions found</label>' +
          '</div>' +
          '<button class="tsd-btn tsd-btn-green tsd-btn-sm" id="tsdSaveVerification" disabled>Mark as Verified</button>' +
          '</div>';
        break;
      case 'verified':
        statusHtml = '<span class="tsd-ap-icon tsd-ap-icon-done">&#10003;</span>' +
          '<span><strong>Verified</strong> &mdash; QA complete, ready to resolve</span>';
        detailsHtml = '<div class="tsd-ap-completed-banner">' +
          '<p>All verification checks passed. Ticket can be <strong>resolved</strong> using the controls below.</p>' +
          '</div>';
        break;
      case 'resolved':
        statusHtml = '<span class="tsd-ap-icon tsd-ap-icon-done">&#10003;</span>' +
          '<span><strong>Resolved</strong></span>';
        break;
      case 'error':
        statusHtml = '<span class="tsd-ap-icon" style="color:var(--tsd-red)">&#10007;</span>' +
          '<span style="color:var(--tsd-red)">Agent encountered an error</span>';
        break;
      default:
        statusHtml = '<span class="tsd-ap-icon">&#9679;</span>' +
          '<span>In progress</span>';
    }

    // Agent metadata details (skip for analyzing — has its own live panel)
    if (agentInfo && stage !== 'analyzing') {
      const parts = [];
      if (agentInfo.ai_model) parts.push('Model: <strong>' + esc(agentInfo.ai_model) + '</strong>');
      if (agentInfo.ai_tokens) parts.push('Tokens: <strong>' + Number(agentInfo.ai_tokens).toLocaleString() + '</strong>');
      if (agentInfo.agent_last_run) parts.push('Last run: <strong>' + timeAgo(agentInfo.agent_last_run) + '</strong>');
      if (parts.length) {
        detailsHtml = '<div class="tsd-ap-details">' + parts.join('<span style="color:#2a3a48">|</span>') + '</div>';
      }
    }

    // Clarification history (show above plan if present)
    if (clarifications.length > 0) {
      clarifyHtml += '<div class="tsd-ap-clarifications">';
      clarifications.forEach(function(cl, idx) {
        clarifyHtml += '<div class="tsd-ap-clarification-item">';
        clarifyHtml += '<span class="tsd-ap-cl-icon">&#9851;</span>';
        clarifyHtml += '<div class="tsd-ap-cl-body">';
        clarifyHtml += '<div class="tsd-ap-cl-header">Clarification #' + (idx + 1) + ' <span class="tsd-ap-cl-time">' + timeAgo(cl.timestamp) + ' ago</span></div>';
        clarifyHtml += '<div class="tsd-ap-cl-text">' + esc(cl.text) + '</div>';
        clarifyHtml += '</div></div>';
      });
      clarifyHtml += '</div>';
    }

    // Inline plan summary + action buttons for plan_ready stage
    if (stage === 'plan_ready' && agentTaskId) {
      // Fetch plan inline (async, fills in after)
      planHtml = '<div class="tsd-ap-plan-summary" id="tsdApPlanSummary"><div style="color:var(--tsd-muted);font-size:12px">Loading plan...</div></div>';
      // Action buttons removed — use Solution section above for approve/reject/clarify
      actionsHtml = '<div class="tsd-ap-actions-hint" style="font:11px system-ui;color:var(--tsd-muted);padding:6px 0;text-align:center"><em>Use the Solution section above to approve, reject, or clarify</em></div>';
    }

    // Error state with clarify option
    if (stage === 'error' && agentTaskId) {
      // Error action buttons removed — use Solution section above for retry
      actionsHtml = '<div class="tsd-ap-actions-hint" style="font:11px system-ui;color:var(--tsd-muted);padding:6px 0;text-align:center"><em>Use the Solution section above for retry options</em></div>';
    }

    target.innerHTML =
      '<div class="tsd-ap-status">' + statusHtml + '</div>' +
      detailsHtml +
      clarifyHtml +
      planHtml +
      actionsHtml;

    // Note: analyzing timer + poll are started by loadSolutionSection only (single source)

    // Load plan summary display (action buttons are in Solution section)
    if (stage === 'plan_ready' && agentTaskId) {
      loadInlinePlan(agentTaskId);
    }

    // Error retry buttons are in Solution section only

    // Wire up verification checklist
    if (stage === 'verification' || stage === 'impl_ready') {
      wireVerificationChecklist(ticket);
    }
  }

  /* ── Wire Verification Checklist ── */
  function wireVerificationChecklist(ticket) {
    const checks = document.querySelectorAll('.js-verify-check');
    const saveBtn = document.getElementById('tsdSaveVerification');
    if (!checks.length || !saveBtn) return;

    // Pre-populate from ticket.verification if exists
    const existing = (ticket.verification && ticket.verification.checklist) || {};
    checks.forEach(function(cb) {
      if (existing[cb.dataset.key]) cb.checked = true;
    });

    function updateSaveBtn() {
      const allChecked = Array.from(checks).every(function(cb) { return cb.checked; });
      saveBtn.disabled = !allChecked;
    }
    updateSaveBtn();
    checks.forEach(function(cb) { cb.addEventListener('change', updateSaveBtn); });

    saveBtn.addEventListener('click', async function() {
      const checklist = {};
      checks.forEach(function(cb) { checklist[cb.dataset.key] = cb.checked; });
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
      try {
        const res = await apiCall('save_verification', { id: ticket.id, checklist: checklist }, 'POST');
        if (res.ok) {
          loadTickets();
          showDetail(ticket.id);
        } else {
          alert('Error: ' + (res.error || 'Save failed'));
          saveBtn.disabled = false;
          saveBtn.textContent = 'Mark as Verified';
        }
      } catch (e) {
        alert('Error: ' + e.message);
        saveBtn.disabled = false;
        saveBtn.textContent = 'Mark as Verified';
      }
    });
  }

  /* ── Load Plan Inline in Agent Progress ── */
  async function loadInlinePlan(taskId) {
    const container = document.getElementById('tsdApPlanSummary');
    if (!container) return;

    try {
      const planRes = await fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(taskId)).then(function(r) { return r.json(); });
      if (!planRes.ok || !planRes.data.plan) {
        container.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px">Plan data not available</div>';
        return;
      }

      const plan = planRes.data.plan;
      let html = '';

      if (plan.diagnosis) {
        html += '<div class="tsd-ap-plan-diagnosis">' + esc(plan.diagnosis) + '</div>';
      }

      if (plan.proposed_changes && plan.proposed_changes.length) {
        html += '<div class="tsd-ap-plan-changes">';
        html += '<strong>' + plan.proposed_changes.length + ' proposed change(s):</strong>';
        html += '<ul>';
        plan.proposed_changes.forEach(function(c) {
          html += '<li><code>' + esc(c.file || c.path || '') + '</code> &mdash; ' + esc(c.description || c.change_description || '') + '</li>';
        });
        html += '</ul></div>';
      }

      if (plan.risk_assessment || plan.estimated_effort) {
        const rc = plan.risk_assessment === 'low' ? 'var(--tsd-green)' : plan.risk_assessment === 'high' ? 'var(--tsd-red)' : 'var(--tsd-yellow)';
        html += '<div class="tsd-ap-plan-meta">';
        if (plan.risk_assessment) html += 'Risk: <strong style="color:' + rc + '">' + esc(plan.risk_assessment) + '</strong>';
        if (plan.estimated_effort) html += ' &middot; Effort: <strong>' + esc(plan.estimated_effort) + '</strong>';
        html += '</div>';
      }

      container.innerHTML = html || '<div style="color:var(--tsd-muted)">Plan generated (no details available)</div>';
    } catch(e) {
      container.innerHTML = '<div style="color:var(--tsd-red);font-size:12px">Failed to load plan: ' + esc(e.message) + '</div>';
    }
  }

  /* ── Wire Approval / Clarify / Reject Buttons ── */
  function wireApprovalButtons(ticketId, taskId) {
    const approveBtn = document.getElementById('tsdApApprove');
    const clarifyBtn = document.getElementById('tsdApClarify');
    const rejectBtn  = document.getElementById('tsdApReject');
    const clarifyForm = document.getElementById('tsdApClarifyForm');
    const clarifyText = document.getElementById('tsdApClarifyText');
    const clarifySubmit = document.getElementById('tsdApClarifySubmit');
    const clarifyCancel = document.getElementById('tsdApClarifyCancel');

    if (approveBtn) {
      approveBtn.addEventListener('click', async function() {
        approveBtn.disabled = true; approveBtn.textContent = 'Applying...';
        try {
          const r = await fetch(AS_API + '?action=approve_plan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: taskId }),
          }).then(function(r) { return r.json(); });
          if (r.ok) {
            showDetail(ticketId);
            loadTickets();
          } else {
            alert('Error: ' + (r.error || 'Approval failed'));
            approveBtn.disabled = false; approveBtn.textContent = 'Approve & Apply';
          }
        } catch(e) { approveBtn.disabled = false; approveBtn.textContent = 'Approve & Apply'; }
      });
    }

    if (clarifyBtn && clarifyForm) {
      clarifyBtn.addEventListener('click', function() {
        clarifyForm.style.display = '';
        clarifyBtn.style.display = 'none';
        if (clarifyText) clarifyText.focus();
      });
    }

    if (clarifyCancel && clarifyForm && clarifyBtn) {
      clarifyCancel.addEventListener('click', function() {
        clarifyForm.style.display = 'none';
        clarifyBtn.style.display = '';
      });
    }

    if (clarifySubmit) {
      clarifySubmit.addEventListener('click', async function() {
        const text = clarifyText ? clarifyText.value.trim() : '';
        if (!text) { alert('Please enter your clarification.'); return; }
        clarifySubmit.disabled = true;
        clarifySubmit.textContent = 'Re-analyzing...';
        try {
          const r = await apiCall('clarify_ticket', { id: ticketId, clarification: text }, 'POST');
          if (r.ok) {
            showDetail(ticketId);
            loadTickets();
          } else {
            alert('Error: ' + (r.error || 'Clarification failed'));
            clarifySubmit.disabled = false;
            clarifySubmit.textContent = 'Submit Clarification';
          }
        } catch(e) {
          alert('Error: ' + e.message);
          clarifySubmit.disabled = false;
          clarifySubmit.textContent = 'Submit Clarification';
        }
      });
    }

    if (rejectBtn) {
      rejectBtn.addEventListener('click', async function() {
        const reason = prompt('Rejection reason (optional):') || '';
        rejectBtn.disabled = true;
        try {
          const r = await fetch(AS_API + '?action=reject_plan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: taskId, reason: reason }),
          }).then(function(r) { return r.json(); });
          if (r.ok) { showDetail(ticketId); loadTickets(); }
          else { alert('Error: ' + (r.error || 'Rejection failed')); rejectBtn.disabled = false; }
        } catch(e) { rejectBtn.disabled = false; }
      });
    }
  }

  /* ── Wire Error Clarify Buttons ── */
  function wireErrorClarifyButtons(ticketId, taskId) {
    const clarifyBtn = document.getElementById('tsdApClarifyError');
    const clarifyForm = document.getElementById('tsdApClarifyFormError');
    const clarifyText = document.getElementById('tsdApClarifyTextError');
    const clarifySubmit = document.getElementById('tsdApClarifySubmitError');
    const clarifyCancel = document.getElementById('tsdApClarifyCancelError');

    if (clarifyBtn && clarifyForm) {
      clarifyBtn.addEventListener('click', function() {
        clarifyForm.style.display = '';
        clarifyBtn.style.display = 'none';
        if (clarifyText) clarifyText.focus();
      });
    }

    if (clarifyCancel && clarifyForm && clarifyBtn) {
      clarifyCancel.addEventListener('click', function() {
        clarifyForm.style.display = 'none';
        clarifyBtn.style.display = '';
      });
    }

    if (clarifySubmit) {
      clarifySubmit.addEventListener('click', async function() {
        const text = clarifyText ? clarifyText.value.trim() : '';
        if (!text) { alert('Please enter additional context.'); return; }
        clarifySubmit.disabled = true;
        clarifySubmit.textContent = 'Retrying...';
        try {
          // First add the clarification context to the task config
          const r = await apiCall('clarify_ticket', { id: ticketId, clarification: text }, 'POST');
          if (r.ok) {
            showDetail(ticketId);
            loadTickets();
          } else {
            alert('Error: ' + (r.error || 'Retry failed'));
            clarifySubmit.disabled = false;
            clarifySubmit.textContent = 'Submit & Retry';
          }
        } catch(e) {
          alert('Error: ' + e.message);
          clarifySubmit.disabled = false;
          clarifySubmit.textContent = 'Submit & Retry';
        }
      });
    }
  }

  /* ── AI Analysis (technical details) ── */
  async function loadAgentActivity(taskId) {
    if (!taskId || !$agentActSection) return;
    $agentActSection.style.display = '';
    // Reset to collapsed
    if ($agentActCollapsible) $agentActCollapsible.style.display = 'none';
    const arrow = $agentActToggle?.querySelector('.tsd-collapse-arrow');
    if (arrow) arrow.textContent = '\u25B6';
    $agentActBody.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px;padding:8px 0">Loading analysis...</div>';

    try {
      const [taskRes, histRes, planRes] = await Promise.all([
        fetch(AS_API + '?action=get_task&id=' + encodeURIComponent(taskId)).then(r => r.json()),
        fetch(AS_API + '?action=task_history&id=' + encodeURIComponent(taskId) + '&limit=20').then(r => r.json()),
        fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(taskId)).then(r => r.json()),
      ]);

      if (!taskRes.ok || !taskRes.data.task) {
        $agentActBody.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px">Agent task not found</div>';
        return;
      }

      const t = taskRes.data.task;
      const history = histRes.ok ? (histRes.data.history || []) : [];
      const plan = planRes.ok ? planRes.data.plan : null;

      let html = '';

      // Agent status summary
      const statusLabel = t.status === 'awaiting_approval' ? 'Plan Ready'
        : t.status === 'completed' ? 'Completed'
        : t.status === 'error' ? 'Error'
        : t.status === 'approved' ? 'Approved'
        : t.last_status === 'success' ? 'Success'
        : t.status || 'Pending';
      html += '<div class="tsd-agent-act-status">';
      html += '<span class="tsd-status-badge ' + esc(t.status || t.last_status || '') + '">' + esc(statusLabel) + '</span>';
      if (t.last_ai_model) html += '<span class="tsd-ai-tag">' + esc(t.last_ai_model) + '</span>';
      html += '<span style="color:var(--tsd-muted);font-size:12px">' + (t.run_count || 0) + ' run(s)</span>';
      if (t.last_run) html += '<span style="color:var(--tsd-muted);font-size:12px">Last: ' + timeAgo(t.last_run) + '</span>';
      if (t.last_duration_ms) {
        const dur = t.last_duration_ms < 1000 ? t.last_duration_ms + 'ms' : (t.last_duration_ms / 1000).toFixed(1) + 's';
        html += '<span style="color:var(--tsd-muted);font-size:12px">Duration: ' + dur + '</span>';
      }
      html += '</div>';

      // Error detail
      if (t.last_error) {
        html += '<div class="tsd-agent-act-error">';
        if (t.last_failed_step) html += '<div style="font-size:11px;color:var(--tsd-muted)">Failed at: <strong>' + esc(t.last_failed_step) + '</strong></div>';
        html += '<div style="font:12px monospace;color:var(--tsd-red);white-space:pre-wrap;max-height:80px;overflow-y:auto">' + esc(t.last_error) + '</div>';
        html += '</div>';
      }

      // Plan summary (if exists)
      if (plan) {
        html += '<div class="tsd-agent-act-plan">';
        html += '<strong style="font:600 10px system-ui;color:var(--tsd-accent);text-transform:uppercase;letter-spacing:1px">Plan</strong>';
        if (plan.diagnosis) html += '<div style="font:13px/1.5 system-ui;color:var(--tsd-fg);margin:6px 0;white-space:pre-wrap">' + esc(plan.diagnosis) + '</div>';
        if (plan.proposed_changes && plan.proposed_changes.length) {
          html += '<div style="margin:6px 0"><strong style="font-size:11px;color:var(--tsd-muted)">' + plan.proposed_changes.length + ' proposed change(s):</strong></div>';
          html += '<ul style="margin:4px 0 0 16px;padding:0;font-size:12px;color:var(--tsd-fg)">';
          plan.proposed_changes.forEach(function(c) {
            html += '<li style="margin-bottom:4px"><code style="color:var(--tsd-accent);font-size:11px">' + esc(c.file || c.path || '') + '</code> — ' + esc(c.description || c.change_description || '') + '</li>';
          });
          html += '</ul>';
        }
        if (plan.risk_assessment) {
          const rc = plan.risk_assessment === 'low' ? 'var(--tsd-green)' : plan.risk_assessment === 'high' ? 'var(--tsd-red)' : 'var(--tsd-yellow)';
          html += '<div style="font-size:11px;color:var(--tsd-muted);margin-top:6px">Risk: <strong style="color:' + rc + '">' + esc(plan.risk_assessment) + '</strong>';
          if (plan.estimated_effort) html += ' &middot; Effort: ' + esc(plan.estimated_effort);
          html += '</div>';
        }

        // Action buttons removed — use Solution section for approve/reject
        html += '</div>';
      }

      // Run history (collapsed runs)
      if (history.length > 0) {
        html += '<div class="tsd-agent-act-runs">';
        html += '<strong style="font:600 10px system-ui;color:var(--tsd-accent);text-transform:uppercase;letter-spacing:1px">Run History</strong>';
        history.forEach(function(run) {
          const badge = run.status === 'success' ? 'resolved'
            : run.status === 'error' ? 'open'
            : run.status === 'plan_generated' ? 'in_progress'
            : 'awaiting_feedback';
          const stepsHtml = (run.steps || []).map(function(s) {
            const cls = s.status === 'ok' ? 'color:var(--tsd-green)' : 'color:var(--tsd-red)';
            return '<div style="font:11px monospace;' + cls + ';padding:2px 0">[' + s.status + '] ' + esc(s.step) + ' — ' + esc(s.detail) + (s.duration_ms ? ' (' + s.duration_ms + 'ms)' : '') + '</div>';
          }).join('');
          html += '<div class="tsd-agent-run-item">';
          html += '<div class="tsd-agent-run-header">';
          html += '<span class="tsd-status-badge ' + badge + '" style="font-size:10px">' + esc(run.status) + '</span>';
          html += '<span style="font-size:11px;color:var(--tsd-muted)">' + (run.started_at ? formatDate(run.started_at) : '') + '</span>';
          if (run.duration_ms) html += '<span style="font-size:11px;color:var(--tsd-muted)">' + (run.duration_ms < 1000 ? run.duration_ms + 'ms' : (run.duration_ms/1000).toFixed(1) + 's') + '</span>';
          html += '</div>';
          if (stepsHtml) html += '<div class="tsd-agent-run-steps" style="display:none">' + stepsHtml + '</div>';
          if (run.error) html += '<div style="font:11px monospace;color:var(--tsd-red);margin-top:4px;max-height:60px;overflow-y:auto">' + esc(run.error) + '</div>';
          html += '</div>';
        });
        html += '</div>';
      }

      $agentActBody.innerHTML = html;

      // Wire up run header click to expand steps
      $agentActBody.querySelectorAll('.tsd-agent-run-header').forEach(function(hdr) {
        hdr.style.cursor = 'pointer';
        hdr.addEventListener('click', function() {
          const steps = hdr.nextElementSibling;
          if (steps && steps.classList.contains('tsd-agent-run-steps')) {
            steps.style.display = steps.style.display === 'none' ? '' : 'none';
          }
        });
      });

      // Wire up approve/reject buttons
      const approveBtn = document.getElementById('agentApproveBtn');
      const rejectBtn = document.getElementById('agentRejectBtn');
      if (approveBtn) {
        approveBtn.addEventListener('click', async function() {
          approveBtn.disabled = true; approveBtn.textContent = 'Applying...';
          try {
            const r = await fetch(AS_API + '?action=approve_plan', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: approveBtn.dataset.task }),
            }).then(function(r) { return r.json(); });
            if (r.ok) {
              showDetail(currentTicketId);
              loadTickets();
            } else {
              alert('Error: ' + (r.error || 'Approval failed'));
              approveBtn.disabled = false; approveBtn.textContent = 'Approve & Apply';
            }
          } catch(e) { approveBtn.disabled = false; approveBtn.textContent = 'Approve & Apply'; }
        });
      }
      if (rejectBtn) {
        rejectBtn.addEventListener('click', async function() {
          const reason = prompt('Rejection reason (optional):') || '';
          rejectBtn.disabled = true;
          try {
            const r = await fetch(AS_API + '?action=reject_plan', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: rejectBtn.dataset.task, reason: reason }),
            }).then(function(r) { return r.json(); });
            if (r.ok) { showDetail(currentTicketId); loadTickets(); }
            else { alert('Error: ' + (r.error || 'Rejection failed')); rejectBtn.disabled = false; }
          } catch(e) { rejectBtn.disabled = false; }
        });
      }

    } catch(e) {
      $agentActBody.innerHTML = '<div style="color:var(--tsd-red);font-size:12px">Failed to load agent activity: ' + esc(e.message) + '</div>';
    }
  }

  /* ── Load Stats ── */
  async function loadStats() {
    try {
      const j = await apiCall('stats');
      if (!j.ok) return;
      const sc = j.status_counts || {};
      $statOpen.textContent     = sc.open || 0;
      $statProgress.textContent = sc.in_progress || 0;
      if ($statFeedback) $statFeedback.textContent = sc.awaiting_feedback || 0;
      $statClosed.textContent   = (sc.closed || 0) + (sc.resolved || 0);
      if ($statArchived) $statArchived.textContent = j.total_archived || 0;
      if ($statGraveyard) $statGraveyard.textContent = j.total_graveyard || 0;
    } catch(e) { console.error('Stats load failed:', e); }
  }

  /* ── Load Ticket List ── */
  let domainOptionsPopulated = false;
  let categoryOptionsPopulated = false;

  async function loadTickets() {
    const status   = $statusF.value;
    const urgency  = $urgencyF.value;
    const domain   = $domainF ? $domainF.value : '';
    const category = $categoryF ? $categoryF.value : '';
    const agent    = $agentF ? $agentF.value : '';
    const search   = $search.value.trim();

    $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Loading...</td></tr>';

    try {
      const j = await apiCall('list_tickets', { status, urgency, domain, category, agent, search });
      if (!j.ok) { $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Error loading tickets</td></tr>'; return; }

      allTickets = j.tickets || [];

      // Populate domain/category dropdowns once
      if (j.domains && $domainF && !domainOptionsPopulated) {
        const curVal = $domainF.value;
        $domainF.innerHTML = '<option value="">All Sites</option>';
        j.domains.forEach(d => {
          $domainF.innerHTML += '<option value="' + esc(d) + '">' + esc(d) + '</option>';
        });
        $domainF.value = curVal;
        domainOptionsPopulated = true;
      }
      if (j.categories && $categoryF && !categoryOptionsPopulated) {
        const curVal = $categoryF.value;
        $categoryF.innerHTML = '<option value="">All Categories</option>';
        j.categories.forEach(c => {
          $categoryF.innerHTML += '<option value="' + esc(c) + '">' + esc(CAT_LABELS[c] || c) + '</option>';
        });
        $categoryF.value = curVal;
        categoryOptionsPopulated = true;
      }

      if (!allTickets.length) {
        $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">No tickets found</td></tr>';
        return;
      }

      var displayTickets = sortTickets(allTickets, tsSortField, tsSortDir);
      updateSortIndicators();
      $body.innerHTML = displayTickets.map(t => {
        const ownerClass = 'owner-' + (t.owner_type || 'submitter');
        let ownerHtml = '<span class="tsd-owner ' + ownerClass + '" title="' + esc(t.owner || '') + '">';
        if (t.owner_type === 'agent' || t.owner_type === 'agent_lost') {
          if (t.agent) {
            const led = t.agent.agent_led || 'running';
            ownerHtml += '<span class="tsd-agent-led led-' + esc(led) + '"></span>';
          }
          ownerHtml += ' ' + esc(t.owner || 'Agent');
          if (t.agent_sync === 'orphaned') ownerHtml += '<span class="tsd-sync-badge orphaned" title="Agent task missing">lost</span>';
          else if (t.agent_sync === 'broken') ownerHtml += '<span class="tsd-sync-badge broken" title="Export failed">err</span>';
        } else {
          const shortOwner = (t.owner || '').replace(/^usr_/, '').substring(0, 12);
          ownerHtml += esc(shortOwner || '\u2014');
        }
        ownerHtml += '</span>';

        // Lifecycle badge (replaces flat status badge) — cli_flagged overrides
        const lcStage = t.cli_flagged ? 'cli_pending' : (t.lifecycle_stage || t.status || 'submitted');
        const lcLabel = LC_LABELS[lcStage] || lcStage.replace(/_/g, ' ');

        // Description preview
        const descPreview = t.description || '';

        const rowDone = (t.status === 'resolved' || t.status === 'closed' || t.status === 'wont_fix' || lcStage === 'verified' || lcStage === 'resolved' || lcStage === 'closed');
        // Row tint: CLI (amber) > error (red) > done (green) > category color
        var rowTint = '';
        if (t.cli_flagged) rowTint = 'tsd-row-cli';
        else if (lcStage === 'error') rowTint = 'tsd-row-error';
        else if (rowDone) rowTint = 'tsd-row-done';
        else if (t.category === 'something_is_broken') rowTint = 'tsd-row-critical';
        else if (t.category === 'module_request') rowTint = 'tsd-row-module';
        else if (t.category === 'ui_issue' || t.category === 'ui_request') rowTint = 'tsd-row-ui';
        else if (t.category === 'page_content' || t.category === 'edit_page_html_css') rowTint = 'tsd-row-page';
        else if (t.category === 'feature_request') rowTint = 'tsd-row-feature';
        return `<tr data-id="${esc(t.id)}" class="${t.id === currentTicketId ? 'active' : ''} ${rowTint}">
          <td><code style="font-size:11px;color:var(--tsd-muted)">${rowDone ? '<span style="color:var(--tsd-green);margin-right:3px">&#10003;</span>' : ''}${esc(t.id.replace('TS-',''))}</code><span class="tsd-ticket-date">${t.submitted_at ? new Date(t.submitted_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'2-digit'}) : ''}</span></td>
          <td><span class="tsd-urg-dot ${esc(t.urgency)}" title="${esc(t.urgency)}"></span></td>
          <td>
            ${esc(t.subject)}
            ${t.has_screenshot ? '<span title="Has screenshot" style="opacity:.4;margin-left:4px">&#128247;</span>' : ''}
            ${t.notes_count > 0 ? '<span title="' + t.notes_count + ' note(s)" style="opacity:.4;margin-left:4px">&#128172; ' + t.notes_count + '</span>' : ''}
            ${descPreview ? '<span class="tsd-desc-preview">' + esc(descPreview) + '</span>' : ''}
          </td>
          <td><span class="tsd-domain" title="${esc(t.domain)}">${esc(shortDomain(t.domain))}</span></td>
          <td><span class="tsd-cat-badge ${esc(t.category)}">${esc(CAT_LABELS[t.category] || t.category)}</span></td>
          <td>${ownerHtml}</td>
          <td><span class="tsd-lifecycle-badge lc-${esc(lcStage)}">${esc(lcLabel)}</span>${t.cli_flagged ? '<span class="tsd-cli-badge">CLI</span>' : ''}</td>
          <td><span class="tsd-age">${timeAgo(t.submitted_at)}</span></td>
          <td><button class="tsd-btn tsd-btn-row-delete tsd-btn-sm" data-delete-id="${esc(t.id)}" title="Delete ticket ${esc(t.id)}" type="button">&#128465;</button></td>
        </tr>`;
      }).join('');

    } catch(e) {
      $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Error: ' + esc(e.message) + '</td></tr>';
    }
  }

  /* ═══════════════════════════════════════════════════════════════════
   * CONVERSATION LAYOUT — Render Functions
   * All detail content is built via JS and injected into #tsdDetailBody
   * ═══════════════════════════════════════════════════════════════════ */

  /* ── Render: Ticket Top Bar ── */
  function renderTicketTopBar(t) {
    const lcStage = t.lifecycle_stage || 'submitted';
    const lcLabel = LC_LABELS_DETAIL[lcStage] || lcStage.replace(/_/g, ' ');
    const urgCls = t.urgency || 'medium';

    let html = '<div class="tsd-conv-topbar">';
    html += '<span class="tsd-ticket-pill pill-' + esc(urgCls) + '">' + esc(t.subject || '(No subject)') + '</span>';
    html += '<span class="tsd-ticket-id-badge js-copy-id" data-id="' + esc(t.id) + '" title="Click to copy">' + esc(t.id) +
      ' <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="cursor:pointer;vertical-align:-1px;opacity:.6"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M3 11V3a1.5 1.5 0 011.5-1.5H11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>';
    html += '<span class="tsd-lifecycle-badge lc-' + esc(lcStage) + '">' + esc(lcLabel) + '</span>';
    if (t.cli_flagged) html += '<span style="background:#e8b931;color:#000;font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;margin-left:4px">\uD83D\uDD27 CLI</span>';
    html += '<span class="tsd-conv-topbar-meta">' + esc(t.domain || '') + '</span>';
    html += '<button class="tsd-conv-close js-detail-close" type="button" title="Close">&times;</button>';
    html += '</div>';
    return html;
  }

  /* ── Render: Lifecycle Bar (HTML string) ── */
  function renderLifecycleBarHtml(stage) {
    const idx = LC_STEP_INDEX[stage] ?? -1;
    const isError = (stage === 'error');
    const isFeedback = (stage === 'awaiting_feedback');
    let html = '<div class="tsd-lifecycle-bar">';
    LC_STEPS.forEach((step, i) => {
      let cls = '';
      if (isError && i === Math.max(idx, 2)) cls = 'lc-error';
      else if (isFeedback && i === Math.max(idx, 0)) cls = 'lc-current';
      else if (i < idx) cls = 'lc-done';
      else if (i === idx) cls = 'lc-current';
      html += '<div class="tsd-lc-step ' + cls + '">';
      html += '<span class="tsd-lc-dot"></span>';
      html += '<span class="tsd-lc-label">' + esc(step.label) + '</span>';
      if (cls === 'lc-done') html += '<span class="tsd-lc-indicator">\u2611</span>';
      else if (cls === 'lc-error') html += '<span class="tsd-lc-indicator">\u26A0</span>';
      else if (cls === 'lc-current') {
        if (LC_ACTION_STEPS[stage]) html += '<span class="tsd-lc-indicator">\u2610</span>';
        else if (LC_AUTO_STEPS[stage]) html += '<span class="tsd-lc-indicator-spinner"></span>';
      }
      if (i < LC_STEPS.length - 1) html += '<span class="tsd-lc-line"></span>';
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  /* ── Render: Request Banner (The Ask) ── */
  function renderRequestBanner(t) {
    const desc = t.description || '(No description)';
    const preview = desc.length > 200 ? desc.substring(0, 200) + '...' : desc;
    const cat = CAT_LABELS[t.category] || t.category || '';
    const userName = t.user?.display_name || t.user?.username || 'User';

    let html = '<div class="tsd-ask-banner js-ask-banner">';
    html += '<div class="tsd-ask-icon">&#128161;</div>';
    html += '<div class="tsd-ask-content">';
    html += '<div class="tsd-ask-label">THE REQUEST</div>';
    html += '<div class="tsd-ask-preview">' + esc(preview) + '</div>';
    if (desc.length > 200) {
      html += '<div class="tsd-ask-full" style="display:none">' + esc(desc) + '</div>';
    }
    html += '<div class="tsd-ask-meta">';
    html += '<span class="tsd-rm-tag">' + esc(cat) + '</span>';
    html += '<span class="tsd-rm-tag"><span class="tsd-urg-dot ' + esc(t.urgency || 'medium') + '"></span> ' + esc(t.urgency || 'medium') + '</span>';
    html += '<span class="tsd-rm-tag">' + esc(userName) + '</span>';
    html += '<span class="tsd-rm-tag">' + formatDate(t.submitted_at) + '</span>';
    if (t.affected_areas && t.affected_areas.length) {
      html += '<span class="tsd-rm-tag">Areas: ' + esc(t.affected_areas.join(', ')) + '</span>';
    }
    html += '</div>';
    if (t.screenshot) {
      html += '<img class="tsd-thread-screenshot" src="' + API + '?action=screenshot&file=' + encodeURIComponent(t.screenshot) + '" alt="Screenshot" style="max-width:300px;max-height:200px;border-radius:6px;margin-top:8px;cursor:pointer" onclick="window.open(this.src)">';
    }
    html += '</div></div>';
    return html;
  }

  /* ── Render: Meta Strip (compact full-width bar under the request) ── */
  function renderMetaStrip(t) {
    let html = '<div class="tsd-meta-strip">';
    html += '<span class="tsd-ms-item"><span class="tsd-ms-label">From</span> ' + esc(t.domain || '-') + '</span>';
    html += '<span class="tsd-ms-sep"></span>';
    html += '<span class="tsd-ms-item"><span class="tsd-ms-label">By</span> ' + esc(t.user?.display_name || t.user?.username || 'unknown') + '</span>';
    html += '<span class="tsd-ms-sep"></span>';
    html += '<span class="tsd-ms-item"><span class="tsd-ms-label">Cat</span> ' + esc(CAT_LABELS[t.category] || t.category || '-') + '</span>';
    html += '<span class="tsd-ms-sep"></span>';
    html += '<span class="tsd-ms-item"><span class="tsd-urg-dot ' + esc(t.urgency || 'medium') + '"></span> ' + esc(t.urgency || 'medium') + '</span>';
    html += '<span class="tsd-ms-sep"></span>';
    html += '<span class="tsd-ms-item">' + formatDate(t.submitted_at) + '</span>';
    if (t.cms_version) { html += '<span class="tsd-ms-sep"></span><span class="tsd-ms-item"><span class="tsd-ms-label">CMS</span> ' + esc(t.cms_version) + '</span>'; }
    if (t.php_version) { html += '<span class="tsd-ms-sep"></span><span class="tsd-ms-item"><span class="tsd-ms-label">PHP</span> ' + esc(t.php_version) + '</span>'; }
    // Agent library routing info
    if (t.agent && t.agent.category_agent_name) {
      html += '<span class="tsd-ms-sep"></span><span class="tsd-ms-item" style="color:var(--tsd-accent)"><span class="tsd-ms-label">Agent</span> ' + esc(t.agent.category_agent_name) + '</span>';
    } else if (t.agent && t.agent.triage_agent_id) {
      html += '<span class="tsd-ms-sep"></span><span class="tsd-ms-item" style="color:var(--tsd-accent)"><span class="tsd-ms-label">Agent</span> Ticket Triage</span>';
    }
    html += '</div>';

    // Category-specific details (full-width, only if present)
    if (t.category === 'module_request' && t.module_request_details) {
      const mr = t.module_request_details;
      html += '<div class="tsd-cat-details" style="border-left-color:#6c63ff;background:rgba(108,99,255,0.04)">';
      html += '<strong style="color:#c4b5fd;font-size:10px;text-transform:uppercase;letter-spacing:1px">Module Request</strong> &nbsp; ';
      const parts = [];
      if (mr.nature) parts.push(esc(mr.nature.replace(/_/g,' ')));
      if (mr.ai_integration) parts.push('AI: ' + esc(mr.ai_integration));
      if (mr.protocols?.length) parts.push(esc(mr.protocols.join(', ')));
      if (mr.target_audience) parts.push(esc(mr.target_audience.replace(/_/g,' ')));
      html += '<span style="color:var(--tsd-fg)">' + parts.join(' &middot; ') + '</span>';
      if (mr.brief) html += '<div style="color:var(--tsd-muted);margin-top:3px;font-size:11px">' + esc(mr.brief) + '</div>';
      html += '</div>';
    }
    if (t.category === 'page_content' && t.page_content_details) {
      const pc = t.page_content_details;
      html += '<div class="tsd-cat-details" style="border-left-color:#60a5fa;background:rgba(96,165,250,0.04)">';
      html += '<strong style="color:#93c5fd;font-size:10px;text-transform:uppercase;letter-spacing:1px">Page / Content</strong> &nbsp; ';
      const parts = [];
      if (pc.page_title) parts.push(esc(pc.page_title) + (pc.page_slug ? ' (' + esc(pc.page_slug) + ')' : ''));
      if (pc.site) parts.push(esc(pc.site));
      if (pc.change_types?.length) parts.push(esc(pc.change_types.map(v=>v.replace(/_/g,' ')).join(', ')));
      html += '<span style="color:var(--tsd-fg)">' + parts.join(' &middot; ') + '</span>';
      if (pc.description) html += '<div style="color:var(--tsd-muted);margin-top:3px;font-size:11px">' + esc(pc.description.substring(0, 200)) + (pc.description.length > 200 ? '...' : '') + '</div>';
      html += '</div>';
    }

    return html;
  }

  /* ── Render: Conversation Thread ── */
  function renderConvThread(t) {
    const entries = [];
    const userName = t.user?.display_name || t.user?.username || 'User';

    // Agent proposals + applied actions + notes
    (t.notes || []).forEach(function(n, idx) {
      const isAgentProposal = n.source === 'agent_proposal';
      const isAgentApplied = n.source === 'agent_applied';
      const isAgent = isAgentProposal || isAgentApplied || n.by === 'AgentScheduler';
      var entryType = 'note';
      if (isAgentApplied) entryType = 'applied';
      else if (isAgent) entryType = 'agent';
      entries.push({
        type: entryType,
        text: n.text || '',
        at: n.at,
        by: isAgent ? 'AI Agent' : (n.by || 'unknown'),
        star_rating: n.star_rating || 0,
        note_index: idx,
      });
    });
    // Clarifications
    (t.clarifications || []).forEach(function(cl) {
      entries.push({ type: 'clarification', text: cl.text || '', at: cl.timestamp, by: cl.by || 'Admin' });
    });
    entries.sort(function(a, b) { return new Date(a.at || 0) - new Date(b.at || 0); });

    let html = '';

    // Completion banner if resolved/closed
    const doneStage = t.lifecycle_stage || 'submitted';
    const doneStatus = t.status || 'open';
    const isDone = doneStatus === 'resolved' || doneStatus === 'closed' || doneStatus === 'wont_fix' ||
                   doneStage === 'resolved' || doneStage === 'verified' || doneStage === 'closed';
    if (isDone) {
      const bannerLabel = doneStatus === 'resolved' ? 'RESOLVED' : doneStatus === 'closed' ? 'CLOSED' : doneStatus === 'wont_fix' ? "WON'T FIX" : doneStage === 'verified' ? 'VERIFIED' : 'COMPLETED';
      html += '<div class="tsd-completion-banner"><div class="tsd-completion-banner-inner">' +
        '<span class="tsd-completion-check">&#10003;</span>' +
        '<span class="tsd-completion-text">' + bannerLabel + '</span>' +
        '</div><div class="tsd-banner-assets"><span class="tsd-banner-assets-label">DOWNLOADS</span>' +
        '<button type="button" class="tsd-asset-btn tsd-asset-btn-pdf js-pickup-pdf" data-id="' + esc(t.id) + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/><text x="4.5" y="12.5" font-size="5.5" font-weight="bold" fill="currentColor" font-family="system-ui">PDF</text></svg>Export PDF</button>' +
        '<button type="button" class="tsd-asset-btn tsd-asset-btn-md js-pickup-md" data-id="' + esc(t.id) + '"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/></svg>Export MD</button>' +
        '<button type="button" class="tsd-asset-btn tsd-asset-btn-zip js-pickup-zip" data-id="' + esc(t.id) + '"><svg width="15" height="15" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M8 1v9m0 0L5 7m3 3l3-3M2 12v1a2 2 0 002 2h8a2 2 0 002-2v-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Download ZIP</button>' +
        '</div></div>';
    }

    // Star rating helper
    function renderStars(rating, ticketId, noteIndex) {
      var h = '<span class="tsd-stars" data-ticket-id="' + esc(ticketId) + '" data-note-index="' + noteIndex + '">';
      for (var i = 1; i <= 5; i++) {
        h += '<span class="tsd-star' + (i <= (rating || 0) ? ' filled' : '') + '" data-star="' + i + '">&#9733;</span>';
      }
      h += '</span>';
      return h;
    }

    // Copy icon SVG (shared across all copy buttons)
    var copyIcon = '<svg width="13" height="13" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M3 11V3a1.5 1.5 0 011.5-1.5H11" stroke="currentColor" stroke-width="1.3" fill="none"/></svg>';

    // Latest agent response — promoted to top
    const agentEntries = entries.filter(e => e.type === 'agent');
    const latestAgent = agentEntries.length > 0 ? agentEntries[agentEntries.length - 1] : null;
    if (latestAgent) {
      html += '<div class="tsd-latest-result">';
      html += '<div class="tsd-latest-result-header">Latest Agent Response<button type="button" class="tsd-copy-btn js-copy-msg" title="Copy to clipboard">' + copyIcon + ' Copy</button></div>';
      html += '<div class="tsd-conv-msg-body">' + renderProposalMd(latestAgent.text) + '</div>';
      html += '<div class="tsd-conv-msg-footer">';
      html += '<span class="tsd-conv-msg-time">' + formatDate(latestAgent.at) + ' (' + timeAgo(latestAgent.at) + ')</span>';
      html += renderStars(latestAgent.star_rating, t.id, latestAgent.note_index);
      html += '</div>';
      html += '</div>';
    }

    // Stage Action Card
    var stageResult = renderStageActionCard(t);
    html += stageResult.html;

    // Previous messages (collapsible)
    const olderEntries = latestAgent ? entries.filter(e => e !== latestAgent) : entries;
    if (olderEntries.length > 0) {
      html += '<div class="tsd-prev-responses">';
      html += '<div class="tsd-prev-responses-toggle js-expand-prev"><span class="tsd-prev-chevron">&#9654;</span> Conversation History (' + olderEntries.length + ')</div>';
      html += '<div class="tsd-prev-responses-body" style="display:none">';
      olderEntries.forEach(function(e) {
        const isAgent = e.type === 'agent';
        const isApplied = e.type === 'applied';
        const isClarification = e.type === 'clarification';
        const msgCls = isApplied ? 'tsd-conv-msg--applied' : isAgent ? 'tsd-conv-msg--agent' : isClarification ? 'tsd-conv-msg--admin' : 'tsd-conv-msg--note';
        const icon = isApplied ? '&#9888;' : isAgent ? '&#129302;' : isClarification ? '&#9851;' : '&#128172;';
        const label = isApplied ? 'Actions Performed' : isAgent ? 'AI Agent Response' : isClarification ? 'Reanalysis Feedback' : 'Note';
        html += '<div class="tsd-conv-msg ' + msgCls + '">';
        html += '<div class="tsd-conv-msg-header"><span class="tsd-conv-msg-icon">' + icon + '</span><span class="tsd-conv-msg-label">' + label + '</span><span class="tsd-conv-msg-by">' + esc(e.by) + '</span><span class="tsd-conv-msg-time">' + formatDate(e.at) + ' (' + timeAgo(e.at) + ')</span><button type="button" class="tsd-copy-btn js-copy-msg" title="Copy to clipboard">' + copyIcon + '</button></div>';
        html += '<div class="tsd-conv-msg-body">' + ((isAgent || isApplied) ? renderProposalMd(e.text) : esc(e.text)) + '</div>';
        if (isAgent || isApplied) {
          html += '<div class="tsd-conv-msg-footer">' + renderStars(e.star_rating, t.id, e.note_index) + '</div>';
        }
        html += '</div>';
      });
      html += '</div></div>';
    }

    // Resolution
    if (t.resolution) {
      html += '<div class="tsd-conv-msg tsd-conv-msg--resolution"><div class="tsd-conv-msg-header">' +
        '<span class="tsd-conv-msg-icon">&#10003;</span><span class="tsd-conv-msg-label">Resolved</span>' +
        (t.resolved_by ? '<span class="tsd-conv-msg-by">' + esc(t.resolved_by) + '</span>' : '') +
        (t.resolved_at ? '<span class="tsd-conv-msg-time">' + formatDate(t.resolved_at) + '</span>' : '') +
        '<button type="button" class="tsd-copy-btn js-copy-msg" title="Copy to clipboard">' + copyIcon + '</button>' +
        '</div><div class="tsd-conv-msg-body">' + esc(t.resolution) + '</div></div>';
    }

    // Reply area — state-driven controls
    var hasAgentTask = !!(t.agent_task_id || (t.agent && t.agent.agent_task_id));
    var agentStatus = (t.agent && t.agent.status) || '';
    var hasPlan = agentStatus === 'awaiting_approval' || agentStatus === 'approved';
    var isTicketError = (t.lifecycle_stage === 'error') || agentStatus === 'error';
    html += '<div class="tsd-thread-reply-area">';
      html += '<textarea id="threadReplyInput" class="tsd-reply-input" placeholder="Write your feedback, corrections, or instructions here..." rows="3"></textarea>';

      html += '<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center">';

      // Send Reply — always available
      html += '<button class="tsd-btn tsd-btn-primary tsd-btn-sm" id="threadSendToAgent" type="button">&#9851; Send Reply to Agent</button>';

      // Approve & Apply — only when plan exists and NOT error
      if (hasAgentTask && hasPlan && !isTicketError) {
        html += '<button class="tsd-btn tsd-btn-green tsd-btn-sm" id="threadApplyPlan" type="button" style="font-weight:700">&#9654; Approve &amp; Apply Agent\'s Plan</button>';
      }

      // Send to CLI — always available
      html += '<button class="tsd-btn tsd-btn-sm" id="threadSendToCli" type="button" style="background:#e8b931;color:#000;font-weight:700;border-color:#e8b931">&#128736; Send to CLI</button>';

      html += '</div>';
      html += '</div>'; // .tsd-thread-reply-area

    return html;
  }

  /* ── Render: Ticket Footer (Admin Controls) ── */
  function renderTicketFooter(t) {
    const currentStatus = t.status || 'open';
    let statusOpts = '';
    for (const s of ALL_STATUSES) {
      if (s.value === currentStatus) continue;
      statusOpts += '<option value="' + esc(s.value) + '">' + esc(s.label) + '</option>';
    }
    const isArchived = (currentStatus === 'archived' || currentStatus === 'closed');
    const hasAgent = !!(t.agent_task_id || (t.agent && t.agent.agent_task_id));

    let html = '<div class="tsd-admin-footer">';
    html += '<div class="tsd-footer-row">';
    html += '<select class="tsd-filter" id="statusSelect">' + statusOpts + '</select>';
    html += '<button class="tsd-btn tsd-btn-sm" id="statusUpdate" type="button">Update Status</button>';
    if (!hasAgent) {
      html += '<button class="tsd-btn tsd-btn-sm" id="agentExport" type="button">Export to Agent</button>';
      html += '<input type="text" class="tsd-input" id="agentContext" placeholder="Additional context for agent..." style="flex:1">';
    }
    if (!isArchived) html += '<button class="tsd-btn tsd-btn-archive tsd-btn-sm" id="archiveBtn" type="button">Archive</button>';
    if (isArchived || currentStatus === 'resolved' || currentStatus === 'wont_fix') {
      html += ' <button class="tsd-btn tsd-btn-sm" id="interBtn" type="button" style="color:#888;border-color:rgba(255,255,255,0.1)">&#9760; Inter</button>';
    }
    html += '<button class="tsd-btn tsd-btn-danger tsd-btn-sm" id="deleteBtn" type="button">Delete</button>';
    html += '</div>';
    html += '</div>';
    return html;
  }

  /* ── Render: Diagnostics Drawer (disabled — will rebuild as stats panel) ── */
  function renderDiagnosticsDrawer(t) {
    return '';
    // Preserved for future stats panel rebuild
    const hasAgent = !!(t.agent_task_id || (t.agent && t.agent.agent_task_id));
    let html = '<div class="tsd-diagnostics-drawer">';

    // Agent Activity
    if (hasAgent) {
      html += '<div class="tsd-diag-section"><div class="tsd-diag-toggle js-diag-toggle" data-target="diagAgent">' +
        '<span class="tsd-collapse-arrow">&#9654;</span> AI Analysis</div>' +
        '<div class="tsd-diag-body" id="diagAgent" style="display:none"><div style="color:var(--tsd-muted);font-size:12px;padding:8px 0">Loading analysis...</div></div></div>';
    }
    // System Info
    html += '<div class="tsd-diag-section"><div class="tsd-diag-toggle js-diag-toggle" data-target="diagSysInfo">' +
      '<span class="tsd-collapse-arrow">&#9654;</span> System Info</div>' +
      '<div class="tsd-diag-body" id="diagSysInfo" style="display:none"><pre class="tsd-sysinfo-pre">' +
      'CMS Version: ' + esc(t.cms_version || '-') + '\n' +
      'PHP Version: ' + esc(t.php_version || '-') + '\n' +
      'User Agent:  ' + esc(t.user_agent || '-') + '</pre></div></div>';
    // Status History
    html += '<div class="tsd-diag-section"><div class="tsd-diag-toggle js-diag-toggle" data-target="diagHistory">' +
      '<span class="tsd-collapse-arrow">&#9654;</span> Status History</div>' +
      '<div class="tsd-diag-body" id="diagHistory" style="display:none">' +
      ((t.status_history || []).map(h =>
        '<div class="tsd-history-item"><span class="tsd-history-time">' + formatDate(h.at) + '</span>' +
        '<span class="tsd-history-status">' + esc((h.status||'').replace('_',' ')) + '</span>' +
        '<span>by ' + esc(h.by) + '</span>' +
        (h.note ? '<span style="color:var(--tsd-muted);font-size:11px;margin-left:4px">(' + esc(h.note.substring(0,80)) + ')</span>' : '') +
        '</div>'
      ).join('') || '<div style="color:var(--tsd-muted);font-size:12px">No history</div>') +
      '</div></div>';
    // Similar Resolved
    html += '<div class="tsd-diag-section"><div class="tsd-diag-toggle js-diag-toggle" data-target="diagSimilar">' +
      '<span class="tsd-collapse-arrow">&#9654;</span> Similar Resolved Issues <span class="tsd-similar-count" id="similarCount" style="display:none"></span></div>' +
      '<div class="tsd-diag-body" id="diagSimilar" style="display:none"><div id="similarResults"><div style="color:var(--tsd-muted);font-size:12px;padding:6px 0">Click to search...</div></div></div></div>';

    html += '</div>';
    return html;
  }

  /* ── Load Agent Activity (inline version) ── */
  async function loadAgentActivityInline(taskId, container) {
    if (!taskId || !container) return;
    container.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px;padding:8px 0">Loading analysis...</div>';
    try {
      const [taskRes, histRes, planRes] = await Promise.all([
        fetch(AS_API + '?action=get_task&id=' + encodeURIComponent(taskId)).then(r => r.json()),
        fetch(AS_API + '?action=task_history&id=' + encodeURIComponent(taskId) + '&limit=20').then(r => r.json()),
        fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(taskId)).then(r => r.json()),
      ]);
      if (!taskRes.ok || !taskRes.data.task) { container.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px">Agent task not found</div>'; return; }
      const tsk = taskRes.data.task;
      const history = histRes.ok ? (histRes.data.history || []) : [];
      const plan = planRes.ok ? planRes.data.plan : null;
      let h = '';
      const statusLabel = tsk.status === 'awaiting_approval' ? 'Plan Ready' : tsk.status === 'completed' ? 'Completed' : tsk.status === 'error' ? 'Error' : tsk.status === 'approved' ? 'Approved' : tsk.last_status === 'success' ? 'Success' : tsk.status || 'Pending';
      h += '<div class="tsd-agent-act-status"><span class="tsd-status-badge ' + esc(tsk.status || tsk.last_status || '') + '">' + esc(statusLabel) + '</span>';
      if (tsk.last_ai_model) h += '<span class="tsd-ai-tag">' + esc(tsk.last_ai_model) + '</span>';
      h += '<span style="color:var(--tsd-muted);font-size:12px">' + (tsk.run_count || 0) + ' run(s)</span>';
      if (tsk.last_run) h += '<span style="color:var(--tsd-muted);font-size:12px">Last: ' + timeAgo(tsk.last_run) + '</span>';
      if (tsk.last_duration_ms) { const dur = tsk.last_duration_ms < 1000 ? tsk.last_duration_ms + 'ms' : (tsk.last_duration_ms / 1000).toFixed(1) + 's'; h += '<span style="color:var(--tsd-muted);font-size:12px">Duration: ' + dur + '</span>'; }
      h += '</div>';
      if (tsk.last_error) {
        h += '<div class="tsd-agent-act-error">';
        if (tsk.last_failed_step) h += '<div style="font-size:11px;color:var(--tsd-muted)">Failed at: <strong>' + esc(tsk.last_failed_step) + '</strong></div>';
        h += '<div style="font:12px monospace;color:var(--tsd-red);white-space:pre-wrap;max-height:80px;overflow-y:auto">' + esc(tsk.last_error) + '</div></div>';
      }
      if (plan) {
        h += '<div class="tsd-agent-act-plan"><strong style="font:600 10px system-ui;color:var(--tsd-accent);text-transform:uppercase;letter-spacing:1px">Plan</strong>';
        if (plan.diagnosis) h += '<div style="font:13px/1.5 system-ui;color:var(--tsd-fg);margin:6px 0;white-space:pre-wrap">' + esc(plan.diagnosis) + '</div>';
        if (plan.proposed_changes?.length) {
          h += '<div style="margin:6px 0"><strong style="font-size:11px;color:var(--tsd-muted)">' + plan.proposed_changes.length + ' proposed change(s):</strong></div>';
          h += '<ul style="margin:4px 0 0 16px;padding:0;font-size:12px;color:var(--tsd-fg)">';
          plan.proposed_changes.forEach(function(c) { h += '<li style="margin-bottom:4px"><code style="color:var(--tsd-accent);font-size:11px">' + esc(c.file || c.path || '') + '</code> — ' + esc(c.description || c.change_description || '') + '</li>'; });
          h += '</ul>';
        }
        if (plan.risk_assessment) { const rc = plan.risk_assessment === 'low' ? 'var(--tsd-green)' : plan.risk_assessment === 'high' ? 'var(--tsd-red)' : 'var(--tsd-yellow)'; h += '<div style="font-size:11px;color:var(--tsd-muted);margin-top:6px">Risk: <strong style="color:' + rc + '">' + esc(plan.risk_assessment) + '</strong>' + (plan.estimated_effort ? ' &middot; Effort: ' + esc(plan.estimated_effort) : '') + '</div>'; }
        h += '</div>';
      }
      if (history.length > 0) {
        h += '<div class="tsd-agent-act-runs"><strong style="font:600 10px system-ui;color:var(--tsd-accent);text-transform:uppercase;letter-spacing:1px">Run History</strong>';
        history.forEach(function(run) {
          const badge = run.status === 'success' ? 'resolved' : run.status === 'error' ? 'open' : run.status === 'plan_generated' ? 'in_progress' : 'awaiting_feedback';
          const stepsHtml = (run.steps || []).map(function(s) {
            const cls = s.status === 'ok' ? 'color:var(--tsd-green)' : 'color:var(--tsd-red)';
            return '<div style="font:11px monospace;' + cls + ';padding:2px 0">[' + s.status + '] ' + esc(s.step) + ' — ' + esc(s.detail) + (s.duration_ms ? ' (' + s.duration_ms + 'ms)' : '') + '</div>';
          }).join('');
          h += '<div class="tsd-agent-run-item"><div class="tsd-agent-run-header" style="cursor:pointer">' +
            '<span class="tsd-status-badge ' + badge + '" style="font-size:10px">' + esc(run.status) + '</span>' +
            '<span style="font-size:11px;color:var(--tsd-muted)">' + (run.started_at ? formatDate(run.started_at) : '') + '</span>';
          if (run.duration_ms) h += '<span style="font-size:11px;color:var(--tsd-muted)">' + (run.duration_ms < 1000 ? run.duration_ms + 'ms' : (run.duration_ms/1000).toFixed(1) + 's') + '</span>';
          h += '</div>';
          if (stepsHtml) h += '<div class="tsd-agent-run-steps" style="display:none">' + stepsHtml + '</div>';
          if (run.error) h += '<div style="font:11px monospace;color:var(--tsd-red);margin-top:4px;max-height:60px;overflow-y:auto">' + esc(run.error) + '</div>';
          h += '</div>';
        });
        h += '</div>';
      }
      container.innerHTML = h;
      // Wire run header expand
      container.querySelectorAll('.tsd-agent-run-header').forEach(function(hdr) {
        hdr.addEventListener('click', function() {
          const steps = hdr.nextElementSibling;
          if (steps && steps.classList.contains('tsd-agent-run-steps')) steps.style.display = steps.style.display === 'none' ? '' : 'none';
        });
      });
    } catch(e) {
      container.innerHTML = '<div style="color:var(--tsd-red);font-size:12px">Failed to load agent activity: ' + esc(e.message) + '</div>';
    }
  }

  /* ── Wire Reply Handlers (inline version) ── */
  function wireThreadReplyHandlersInline(ticket, container) {
    if (!container) return;
    var replyInput = container.querySelector('#threadReplyInput');
    var sendToAgentBtn = container.querySelector('#threadSendToAgent');
    var applyPlanBtn = container.querySelector('#threadApplyPlan');
    var threadCliBtn = container.querySelector('#threadSendToCli');

    // "Send Reply to Agent"
    if (sendToAgentBtn) {
      sendToAgentBtn.addEventListener('click', async function() {
        var text = replyInput ? replyInput.value.trim() : '';
        if (!text || !currentTicketId) { alert('Please write your feedback in the text box above.'); return; }
        var origText = sendToAgentBtn.innerHTML;
        sendToAgentBtn.disabled = true; sendToAgentBtn.textContent = 'Sending...';
        try {
          var j = await apiCall('clarify_ticket', { id: currentTicketId, clarification: text }, 'POST');
          if (j.ok) { if (replyInput) replyInput.value = ''; loadStats(); loadTickets(); showDetail(currentTicketId); }
          else { alert('Error: ' + (j.error || 'Failed to send to agent')); }
        } catch(e) { alert('Error: ' + e.message); }
        finally { sendToAgentBtn.disabled = false; sendToAgentBtn.innerHTML = origText; }
      });
    }

    // "Approve & Apply Agent's Plan"
    if (applyPlanBtn) {
      applyPlanBtn.addEventListener('click', async function() {
        if (!currentTicketId || !currentAgentTaskId) { alert('No agent task linked to this ticket.'); return; }
        if (!confirm('This will approve the agent\'s current plan and apply file changes to the codebase.\n\nProceed?')) return;
        var origText = applyPlanBtn.innerHTML;
        applyPlanBtn.disabled = true; applyPlanBtn.textContent = 'Applying...';
        try {
          var text = replyInput ? replyInput.value.trim() : '';
          if (text) { await apiCall('add_note', { id: currentTicketId, note: text }, 'POST'); if (replyInput) replyInput.value = ''; }
          var r = await fetch(AS_API + '?action=approve_plan', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: currentAgentTaskId }) }).then(function(r) { return r.json(); });
          if (r.ok) { loadStats(); loadTickets(); showDetail(currentTicketId); }
          else { alert('Error: ' + (r.error || 'Execution failed')); }
        } catch(e) { alert('Error: ' + e.message); }
        finally { applyPlanBtn.disabled = false; applyPlanBtn.innerHTML = origText; }
      });
    }

    // "Send to CLI" in reply area
    if (threadCliBtn && ticket) {
      threadCliBtn.addEventListener('click', function() {
        sendToCliWithOverlay(ticket.id);
      });
    }
  }

  /* ── Wire All Detail Handlers (post-render) ── */
  function wireDetailHandlers(t) {
    const $db = document.getElementById('tsdDetailBody');
    if (!$db) return;
    const agentInfo = t.agent || null;
    const agentTaskId = t.agent_task_id || (agentInfo && agentInfo.agent_task_id) || '';

    // Close
    var closeBtn = $db.querySelector('.js-detail-close');
    if (closeBtn) closeBtn.addEventListener('click', hideDetail);

    // Copy ID
    var copyId = $db.querySelector('.js-copy-id');
    if (copyId) {
      copyId.style.cursor = 'pointer';
      copyId.addEventListener('click', function() {
        navigator.clipboard.writeText(copyId.dataset.id || '').then(function() {
          var orig = copyId.innerHTML;
          copyId.innerHTML = '<span style="color:var(--tsd-green)">Copied!</span>';
          setTimeout(function() { copyId.innerHTML = orig; }, 1500);
        });
      });
    }

    // Copy message buttons — copies the text content of the nearest .tsd-conv-msg-body
    $db.querySelectorAll('.js-copy-msg').forEach(function(btn) {
      btn.addEventListener('click', function(ev) {
        ev.stopPropagation();
        var parent = btn.closest('.tsd-latest-result') || btn.closest('.tsd-conv-msg');
        var body = parent ? parent.querySelector('.tsd-conv-msg-body') : null;
        if (!body) return;
        // Get plain text (strips HTML tags)
        var text = body.innerText || body.textContent || '';
        navigator.clipboard.writeText(text).then(function() {
          var orig = btn.innerHTML;
          btn.innerHTML = '<span style="color:var(--tsd-green)">Copied!</span>';
          setTimeout(function() { btn.innerHTML = orig; }, 1500);
        });
      });
    });

    // Star rating clicks
    $db.querySelectorAll('.tsd-stars').forEach(function(starsEl) {
      starsEl.querySelectorAll('.tsd-star').forEach(function(star) {
        star.addEventListener('click', async function(ev) {
          ev.stopPropagation();
          var starVal = parseInt(star.dataset.star);
          var ticketId = starsEl.dataset.ticketId;
          var noteIdx = starsEl.dataset.noteIndex;
          starsEl.style.opacity = '.5';
          try {
            var res = await apiCall('rate_response', { id: ticketId, note_index: parseInt(noteIdx), stars: starVal }, 'POST');
            if (res.ok) { showDetail(ticketId); }
            else { starsEl.style.opacity = ''; }
          } catch(e) { starsEl.style.opacity = ''; }
        });
      });
    });

    // Ask banner expand
    var askBanner = $db.querySelector('.js-ask-banner');
    if (askBanner) {
      askBanner.style.cursor = 'pointer';
      askBanner.addEventListener('click', function() {
        var full = askBanner.querySelector('.tsd-ask-full');
        var preview = askBanner.querySelector('.tsd-ask-preview');
        if (full) {
          if (full.style.display === 'none') { full.style.display = ''; if (preview) preview.style.display = 'none'; }
          else { full.style.display = 'none'; if (preview) preview.style.display = ''; }
        }
      });
    }

    // Stage action card wiring
    var stageResult = renderStageActionCard(t);
    if (stageResult.wireUp) stageResult.wireUp($db);

    // Previous responses toggle
    var prevToggle = $db.querySelector('.js-expand-prev');
    if (prevToggle) {
      prevToggle.style.cursor = 'pointer';
      prevToggle.addEventListener('click', function() {
        var body = prevToggle.nextElementSibling;
        var chevron = prevToggle.querySelector('.tsd-prev-chevron');
        if (body) {
          if (body.style.display === 'none') { body.style.display = ''; if (chevron) chevron.innerHTML = '&#9660;'; }
          else { body.style.display = 'none'; if (chevron) chevron.innerHTML = '&#9654;'; }
        }
      });
    }

    // Diagnostics drawer toggles
    $db.querySelectorAll('.js-diag-toggle').forEach(function(toggle) {
      toggle.style.cursor = 'pointer';
      toggle.addEventListener('click', function() {
        var targetId = toggle.dataset.target;
        var body = document.getElementById(targetId);
        var arrow = toggle.querySelector('.tsd-collapse-arrow');
        if (body) {
          if (body.style.display === 'none') {
            body.style.display = '';
            if (arrow) arrow.innerHTML = '&#9660;';
            if (targetId === 'diagAgent' && agentTaskId) loadAgentActivityInline(agentTaskId, body);
            if (targetId === 'diagSimilar') loadSimilarTickets(t.id);
          } else {
            body.style.display = 'none';
            if (arrow) arrow.innerHTML = '&#9654;';
          }
        }
      });
    });

    // Thread reply handlers
    wireThreadReplyHandlersInline(t, $db);

    // Footer controls
    var statusSelect = document.getElementById('statusSelect');
    var statusUpdate = document.getElementById('statusUpdate');
    var archiveBtn = document.getElementById('archiveBtn');
    var deleteBtn = document.getElementById('deleteBtn');
    var agentExport = document.getElementById('agentExport');

    if (statusUpdate) {
      statusUpdate.addEventListener('click', async function() {
        if (!currentTicketId || !statusSelect) return;
        statusUpdate.disabled = true;
        try {
          const j = await apiCall('update_status', { id: currentTicketId, status: statusSelect.value }, 'POST');
          if (j.ok) { loadStats(); loadTickets(); if (statusSelect.value === 'closed' || statusSelect.value === 'wont_fix') hideDetail(); else showDetail(currentTicketId); }
          else alert('Failed to update status: ' + (j.error || 'Unknown error'));
        } finally { statusUpdate.disabled = false; }
      });
    }
    if (archiveBtn) {
      archiveBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('Archive ticket ' + currentTicketId + '?')) return;
        archiveBtn.disabled = true;
        try {
          const j = await apiCall('delete_ticket', { id: currentTicketId }, 'POST');
          if (j.ok) { hideDetail(); loadStats(); loadTickets(); }
        } finally { archiveBtn.disabled = false; }
      });
    }
    var interBtn = document.getElementById('interBtn');
    if (interBtn) {
      interBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('Send ' + currentTicketId + ' to the Graveyard?\n\nKnowledge will be preserved but the original ticket is destroyed.')) return;
        interBtn.disabled = true;
        try {
          var r = await apiCall('inter_ticket', { id: currentTicketId }, 'POST');
          if (r.ok) {
            alert('Ticket interred.');
            currentTicketId = null;
            hideDetail();
            loadStats();
            loadTickets();
          } else {
            alert('Error: ' + (r.error || 'Unknown'));
          }
        } finally { interBtn.disabled = false; }
      });
    }
    if (deleteBtn) {
      deleteBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('PERMANENTLY DELETE ticket ' + currentTicketId + '?\nThis cannot be undone.')) return;
        deleteBtn.disabled = true;
        try {
          const j = await apiCall('permanently_delete_ticket', { id: currentTicketId }, 'POST');
          if (j.ok) { hideDetail(); loadStats(); loadTickets(); }
        } finally { deleteBtn.disabled = false; }
      });
    }
    if (agentExport) {
      agentExport.addEventListener('click', async function() {
        if (!currentTicketId) return;
        agentExport.disabled = true; agentExport.textContent = 'Analyzing...';
        try {
          const ctx = (document.getElementById('agentContext')?.value || '').trim();
          const j = await apiCall('export_agent_task', { id: currentTicketId, export_context: ctx }, 'POST');
          if (j.ok) { loadStats(); loadTickets(); showDetail(currentTicketId); }
          else { agentExport.textContent = 'Export Failed'; agentExport.disabled = false; }
        } catch(e) { agentExport.textContent = 'Export Failed'; agentExport.disabled = false; }
      });
    }

    // CLI-overlay action buttons (above the pointer-events:none glass)
    var cliDeleteBtn = document.getElementById('cliDeleteBtn');
    if (cliDeleteBtn) {
      cliDeleteBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('PERMANENTLY DELETE CLI ticket ' + currentTicketId + '?\nThis cannot be undone.')) return;
        cliDeleteBtn.disabled = true;
        try {
          const j = await apiCall('permanently_delete_ticket', { id: currentTicketId }, 'POST');
          if (j.ok) { hideDetail(); loadStats(); loadTickets(); }
        } finally { cliDeleteBtn.disabled = false; }
      });
    }
    var cliInterBtn = document.getElementById('cliInterBtn');
    if (cliInterBtn) {
      cliInterBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('Send ' + currentTicketId + ' to the Graveyard?\n\nKnowledge will be preserved but the original ticket is destroyed.')) return;
        cliInterBtn.disabled = true;
        try {
          var r = await apiCall('inter_ticket', { id: currentTicketId }, 'POST');
          if (r.ok) { alert('Ticket interred.'); currentTicketId = null; hideDetail(); loadStats(); loadTickets(); }
          else alert('Error: ' + (r.error || 'Unknown'));
        } finally { cliInterBtn.disabled = false; }
      });
    }
    var cliUnflagBtn = document.getElementById('cliUnflagBtn');
    if (cliUnflagBtn) {
      cliUnflagBtn.addEventListener('click', async function() {
        if (!currentTicketId) return;
        if (!confirm('Remove CLI flag from ' + currentTicketId + '?\nThis will make it editable again.')) return;
        cliUnflagBtn.disabled = true;
        try {
          const j = await apiCall('update_status', { id: currentTicketId, status: 'in_progress', unflag_cli: true }, 'POST');
          if (j.ok) { showDetail(currentTicketId); loadTickets(); }
          else alert('Error: ' + (j.error || 'Unknown'));
        } finally { cliUnflagBtn.disabled = false; }
      });
    }

    // Pickup buttons
    $db.querySelectorAll('.js-pickup-zip').forEach(function(btn) {
      btn.addEventListener('click', function() { downloadPickupZip(btn.dataset.id); });
    });
    $db.querySelectorAll('.js-pickup-pdf').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        btn.textContent = 'Generating...';
        try {
          var [planData, implData] = await Promise.all([
            fetch(AS_API + '?action=get_plan&id=' + encodeURIComponent(agentTaskId || '')).then(r => r.json()),
            apiCall('get_implementation', { id: t.id })
          ]);
          downloadPickupPdf(t, (planData.ok && planData.data?.plan) ? planData.data.plan : null, (implData.ok && implData.content) ? implData.content : null);
          btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="vertical-align:-2px;margin-right:5px"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M9 1v4h4" stroke="currentColor" stroke-width="1.3"/><text x="4.5" y="12.5" font-size="5.5" font-weight="bold" fill="currentColor" font-family="system-ui">PDF</text></svg>Export PDF';
        } catch(e) { btn.textContent = 'Error'; setTimeout(function(){ btn.textContent = 'Export PDF'; }, 2000); }
      });
    });
    $db.querySelectorAll('.js-pickup-md').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        btn.textContent = 'Loading...';
        try {
          var implData = await apiCall('get_implementation', { id: t.id });
          var md = '# ' + (t.subject || t.id) + '\n\n**Ticket:** ' + t.id + '\n**Domain:** ' + (t.domain || '') + '\n**Category:** ' + (CAT_LABELS[t.category] || t.category) + '\n\n---\n\n';
          if (t.description) md += '## Description\n\n' + t.description + '\n\n';
          if (implData.ok && implData.content) md += implData.content;
          navigator.clipboard.writeText(md).then(function() { btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = 'Export MD'; }, 2000); });
        } catch(e) { btn.textContent = 'Error'; setTimeout(function(){ btn.textContent = 'Export MD'; }, 2000); }
      });
    });
  }

  /* ── Show Ticket Detail (Conversation Layout) ── */
  async function showDetail(id) {
    clearAnalyzingTimer();
    currentTicketId = id;
    $detailOverlay.classList.add('open');

    document.querySelectorAll('.tsd-table tbody tr').forEach(tr => {
      tr.classList.toggle('active', tr.dataset.id === id);
    });

    const $db = document.getElementById('tsdDetailBody');
    if (!$db) return;
    $db.innerHTML = '<div style="text-align:center;padding:60px;color:var(--tsd-muted)"><span class="tsd-ap-spinner"></span> Loading ticket...</div>';

    try {
      const j = await apiCall('get_ticket', { id });
      if (!j.ok || !j.ticket) { $db.innerHTML = '<div style="color:var(--tsd-red);padding:20px">Ticket not found</div>'; return; }
      const t = j.ticket;
      const agentInfo = t.agent || null;
      const agentTaskId = t.agent_task_id || (agentInfo && agentInfo.agent_task_id) || '';
      currentAgentTaskId = agentTaskId;

      let html = '';
      html += renderTicketTopBar(t);
      html += renderLifecycleBarHtml(t.lifecycle_stage || 'submitted');

      // CLI-pending: semi-opaque overlay over the detail (read-only view)
      if (t.cli_flagged) {
        html += '<div class="tsd-cli-detail-wrap">';
        html += '<div class="tsd-cli-detail-glass">';
        html += '<div class="tsd-cli-detail-glass-inner">';
        html += '<div style="font-size:36px;margin-bottom:12px">&#128736;</div>';
        html += '<div style="font:700 18px system-ui;margin-bottom:6px">Claude Code CLI — In Progress</div>';
        html += '<div style="font-size:13px;opacity:.8;max-width:380px;margin:0 auto;line-height:1.5">';
        html += 'Escalated to CLI for hands-on intervention. Read-only view below.</div>';
        html += '<div style="font-size:11px;opacity:.4;margin-top:10px">' + (t.cli_flagged_at ? formatDate(t.cli_flagged_at) : '') + '</div>';
        html += '<div style="display:flex;gap:8px;margin-top:14px;justify-content:center">';
        html += '<button class="tsd-btn tsd-btn-sm" id="cliUnflagBtn" type="button" style="background:transparent;border-color:rgba(255,255,255,.3);color:#ccc">&#x21A9; Unflag CLI</button>';
        html += '<button class="tsd-btn tsd-btn-sm" id="cliInterBtn" type="button" style="color:#888;border-color:rgba(255,255,255,.2)">&#9760; Inter</button>';
        html += '<button class="tsd-btn tsd-btn-danger tsd-btn-sm" id="cliDeleteBtn" type="button">&#128465; Delete</button>';
        html += '</div>';
        html += '</div></div>';
        html += '<div class="tsd-cli-detail-beneath">';
      }

      html += renderRequestBanner(t);
      html += renderMetaStrip(t);
      html += '<div class="tsd-conv-thread">';
      html += renderConvThread(t);
      html += '</div>';
      html += renderTicketFooter(t);
      html += renderDiagnosticsDrawer(t);

      if (t.cli_flagged) {
        html += '</div></div>'; // close .tsd-cli-detail-beneath + .tsd-cli-detail-wrap
      }

      $db.innerHTML = html;
      wireDetailHandlers(t);

    } catch(e) {
      $db.innerHTML = '<div style="color:var(--tsd-red);padding:20px">Error loading ticket: ' + esc(e.message) + '</div>';
      console.error(e);
    }
  }

  async function loadSimilarTickets(ticketId) {
    var $results = document.getElementById('similarResults');
    var $count = document.getElementById('similarCount');
    if (!$results) return;
    $results.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px;padding:6px 0">Searching...</div>';
    if ($count) { $count.style.display = 'none'; $count.textContent = ''; }
    try {
      var j = await apiCall('similar_tickets', { id: ticketId, resolved_only: true, limit: 5 });
      if (!j.ok || !j.similar || j.similar.length === 0) {
        $results.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px;padding:6px 0">No similar resolved issues found</div>';
        return;
      }
      if ($count) { $count.style.display = 'inline'; $count.textContent = j.similar.length; }
      var html = '';
      j.similar.forEach(function(s) {
        var statusColor = s.status === 'archived' ? '#888' : s.status === 'resolved' ? '#4caf50' : '#ff9800';
        html += '<div class="tsd-similar-item" data-id="' + esc(s.id) + '">' +
          '<div class="tsd-similar-header">' +
            '<span class="tsd-similar-id">' + esc(s.id) + '</span>' +
            '<span class="tsd-similar-status" style="color:' + statusColor + '">' + esc(s.status) + '</span>' +
          '</div>' +
          '<div class="tsd-similar-subject">' + esc(s.subject) + '</div>' +
          (s.domain ? '<div class="tsd-similar-domain">' + esc(s.domain) + '</div>' : '') +
          (s.resolution ? '<div class="tsd-similar-resolution">' + esc(s.resolution.substring(0, 150)) + (s.resolution.length > 150 ? '...' : '') + '</div>' : '') +
          '</div>';
      });
      $results.innerHTML = html;

      // Click to open similar ticket
      $results.querySelectorAll('.tsd-similar-item').forEach(function(el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', function() { showDetail(el.dataset.id); });
      });
    } catch (e) {
      $results.innerHTML = '<div style="color:var(--tsd-muted);font-size:12px;padding:6px 0">Search unavailable</div>';
    }
  }

  function hideDetail() {
    clearAnalyzingTimer();
    currentTicketId = null;
    $detailOverlay.classList.remove('open');
    document.querySelectorAll('.tsd-table tbody tr.active').forEach(tr => tr.classList.remove('active'));
  }

  /* ── Event Listeners (list-level only; detail handlers in wireDetailHandlers) ── */

  // Table row click (with row-level delete interception)
  $body.addEventListener('click', async (e) => {
    // Row-level delete button
    const delBtn = e.target.closest('[data-delete-id]');
    if (delBtn) {
      e.stopPropagation();
      const tid = delBtn.dataset.deleteId;
      if (!confirm('Permanently delete ticket ' + tid + '?\nThis cannot be undone.')) return;
      delBtn.disabled = true;
      try {
        const j = await apiCall('permanently_delete_ticket', { id: tid }, 'POST');
        if (j.ok) {
          const tr = delBtn.closest('tr');
          if (tr) tr.remove();
          loadStats();
        } else {
          alert('Delete failed: ' + (j.error || 'Unknown error'));
        }
      } catch(err) {
        alert('Delete error: ' + err.message);
      } finally {
        delBtn.disabled = false;
      }
      return;
    }
    const tr = e.target.closest('tr[data-id]');
    if (tr) showDetail(tr.dataset.id);
  });

  // Close detail via overlay click or Escape
  $detailOverlay.addEventListener('click', (e) => {
    if (e.target === $detailOverlay) hideDetail();
  });
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && currentTicketId) hideDetail();
  });

  // Filters
  let filterTimer;
  function debouncedLoad() { clearTimeout(filterTimer); filterTimer = setTimeout(loadTickets, 300); }
  $search.addEventListener('input', debouncedLoad);
  $statusF.addEventListener('change', loadTickets);
  $urgencyF.addEventListener('change', loadTickets);
  if ($domainF) $domainF.addEventListener('change', loadTickets);
  if ($categoryF) $categoryF.addEventListener('change', loadTickets);
  if ($agentF) $agentF.addEventListener('change', loadTickets);
  $refresh.addEventListener('click', () => { loadStats(); loadTickets(); });

  // Sortable column headers
  document.querySelectorAll('.tsd-sortable').forEach(function(th) {
    th.addEventListener('click', function() {
      var field = th.dataset.sort;
      if (tsSortField === field) {
        tsSortDir = tsSortDir === 'asc' ? 'desc' : 'asc';
      } else {
        tsSortField = field;
        tsSortDir = (field === 'subject' || field === 'domain' || field === 'category') ? 'asc' : 'desc';
      }
      loadTickets();
    });
  });

  // Stat cards as filter shortcuts
  document.querySelectorAll('.tsd-stat-card[data-filter-status]').forEach(card => {
    card.addEventListener('click', () => {
      $statusF.value = card.dataset.filterStatus;
      loadTickets();
    });
  });

  /* ═══════════════════════════════════════════════════════
   * GRAVEYARD — Knowledge Base View
   * ═══════════════════════════════════════════════════════ */

  let graveyardMode = false;

  async function showGraveyard() {
    graveyardMode = true;
    if (!$body) return;

    $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Loading graveyard...</td></tr>';

    // Update heading
    var $heading = document.querySelector('.tsd-header h1');
    if ($heading) $heading.innerHTML = '&#9760; Knowledge Base (Graveyard) <button class="tsd-btn tsd-btn-sm" id="exitGraveyard" style="margin-left:12px;font-size:11px">&larr; Back to Tickets</button>';

    try {
      var j = await apiCall('list_graveyard');
      if (!j.ok || !j.entries || !j.entries.length) {
        $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Graveyard is empty &mdash; no interred tickets yet.</td></tr>';
        wireExitGraveyard();
        return;
      }

      $body.innerHTML = j.entries.map(function(e) {
        var desc = e.description || '';
        var res = e.resolution ? '<span style="color:var(--tsd-green);font-style:italic;font-size:11px"> &mdash; ' + esc(e.resolution).substring(0, 80) + '</span>' : '';
        return '<tr data-grave-id="' + esc(e.id) + '" style="cursor:pointer">' +
          '<td><code style="font-size:11px;color:var(--tsd-muted)">&#9760; ' + esc(e.id.replace('TS-','')) + '</code></td>' +
          '<td><span class="tsd-urg-dot ' + esc(e.domain ? 'medium' : 'low') + '"></span></td>' +
          '<td>' + esc(e.subject) + res + (desc ? '<span class="tsd-desc-preview">' + esc(desc) + '</span>' : '') + '</td>' +
          '<td><span class="tsd-domain">' + esc(e.domain || '') + '</span></td>' +
          '<td><span class="tsd-cat-badge ' + esc(e.category || '') + '">' + esc(e.category || '') + '</span></td>' +
          '<td><span style="color:var(--tsd-muted);font-size:11px">' + esc(e.final_status || '') + '</span></td>' +
          '<td><span style="font-size:11px;color:var(--tsd-muted)">Interred</span></td>' +
          '<td><span class="tsd-age">' + timeAgo(e.interred_at) + '</span></td>' +
          '<td><button class="tsd-btn tsd-btn-sm" data-clone-id="' + esc(e.id) + '" title="Reincarnate as new ticket" style="font-size:11px;padding:2px 8px">&#128260; Clone</button></td>' +
        '</tr>';
      }).join('');

      // Wire clone buttons
      $body.querySelectorAll('[data-clone-id]').forEach(function(btn) {
        btn.addEventListener('click', async function(ev) {
          ev.stopPropagation();
          var sid = btn.dataset.cloneId;
          if (!confirm('Reincarnate ' + sid + ' as a new ticket?')) return;
          try {
            var r = await apiCall('clone_from_graveyard', { source_id: sid }, 'POST');
            if (r.ok) {
              alert('New ticket created: ' + r.new_id);
              exitGraveyard();
            } else {
              alert('Error: ' + (r.error || 'Unknown'));
            }
          } catch(e2) { alert('Error: ' + e2.message); }
        });
      });

      // Wire row click for detail view
      $body.querySelectorAll('[data-grave-id]').forEach(function(row) {
        row.addEventListener('click', async function(ev) {
          if (ev.target.closest('[data-clone-id]')) return;
          var gid = row.dataset.graveId;
          try {
            var r = await apiCall('get_graveyard_entry', { id: gid });
            if (r.ok && r.entry) showGraveyardDetail(r.entry);
          } catch(e2) { alert('Error: ' + e2.message); }
        });
      });

      wireExitGraveyard();
    } catch(e) {
      $body.innerHTML = '<tr><td colspan="9" class="tsd-empty">Error: ' + esc(e.message) + '</td></tr>';
      wireExitGraveyard();
    }
  }

  function showGraveyardDetail(entry) {
    // Show in the detail overlay
    var $db = document.getElementById('tsdDetailBody');
    if (!$db) return;
    if ($detailOverlay) $detailOverlay.classList.add('active');

    var notesHtml = '';
    if (entry.notes && entry.notes.length) {
      notesHtml = '<div style="margin-top:16px"><h4 style="color:var(--tsd-accent);font-size:13px;margin-bottom:8px">Notes &amp; History</h4>';
      entry.notes.forEach(function(n) {
        notesHtml += '<div style="padding:8px 12px;margin-bottom:6px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:6px;font-size:12px">' +
          '<strong style="color:var(--tsd-muted)">' + esc(n.by || 'system') + '</strong>' +
          ' <span style="color:var(--tsd-muted);font-size:11px">' + (n.at ? timeAgo(n.at) : '') + '</span>' +
          '<div style="margin-top:4px;color:var(--tsd-fg)">' + esc(n.text || '') + '</div></div>';
      });
      notesHtml += '</div>';
    }

    var historyHtml = '';
    if (entry.status_history && entry.status_history.length) {
      historyHtml = '<div style="margin-top:16px"><h4 style="color:var(--tsd-accent);font-size:13px;margin-bottom:8px">Status Timeline</h4>';
      entry.status_history.forEach(function(h) {
        historyHtml += '<div style="font-size:11px;color:var(--tsd-muted);padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.04)">' +
          '<span style="color:var(--tsd-accent)">' + esc(h.status || '') + '</span> &mdash; ' +
          esc(h.by || '') + ' ' + (h.at ? timeAgo(h.at) : '') +
          (h.note ? ' &mdash; <em>' + esc(h.note) + '</em>' : '') + '</div>';
      });
      historyHtml += '</div>';
    }

    $db.innerHTML =
      '<div style="padding:20px">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">' +
        '<div style="display:flex;align-items:center;gap:12px">' +
          '<span style="font-size:1.8rem">&#9760;</span>' +
          '<div>' +
            '<div style="font-size:15px;font-weight:700;color:var(--tsd-accent)">' + esc(entry.subject || '') + '</div>' +
            '<div style="font-size:12px;color:var(--tsd-muted)">' + esc(entry.id) + ' &bull; ' + esc(entry.domain || '') + ' &bull; Interred ' + timeAgo(entry.interred_at) + '</div>' +
          '</div>' +
        '</div>' +
        '<button class="tsd-btn tsd-btn-sm" id="graveDetailClose" style="font-size:16px;line-height:1;padding:4px 10px">&times;</button>' +
      '</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;font-size:12px">' +
        '<div><span style="color:var(--tsd-muted)">Category:</span> <span style="color:var(--tsd-fg)">' + esc(entry.category || '') + '</span></div>' +
        '<div><span style="color:var(--tsd-muted)">Final Status:</span> <span style="color:var(--tsd-fg)">' + esc(entry.final_status || '') + '</span></div>' +
        '<div><span style="color:var(--tsd-muted)">Urgency:</span> <span style="color:var(--tsd-fg)">' + esc(entry.urgency || '') + '</span></div>' +
        '<div><span style="color:var(--tsd-muted)">Submitted:</span> <span style="color:var(--tsd-fg)">' + timeAgo(entry.submitted_at) + '</span></div>' +
      '</div>' +
      '<div style="margin-bottom:16px"><h4 style="color:var(--tsd-accent);font-size:13px;margin-bottom:8px">Description</h4>' +
        '<div style="font-size:13px;color:var(--tsd-fg);line-height:1.6;white-space:pre-wrap">' + esc(entry.description || '(none)') + '</div></div>' +
      (entry.resolution ? '<div style="margin-bottom:16px;padding:12px;background:rgba(52,211,153,0.06);border:1px solid rgba(52,211,153,0.15);border-radius:8px"><h4 style="color:var(--tsd-green);font-size:13px;margin-bottom:6px">Resolution</h4><div style="font-size:13px;color:var(--tsd-fg);line-height:1.6;white-space:pre-wrap">' + esc(entry.resolution) + '</div></div>' : '') +
      notesHtml +
      historyHtml +
      '<div style="margin-top:20px;text-align:center">' +
        '<button class="tsd-btn tsd-btn-sm" id="graveyardCloneBtn" style="padding:8px 20px">&#128260; Reincarnate as New Ticket</button>' +
      '</div>' +
      '</div>';

    // Wire close
    var closeBtn = document.getElementById('graveDetailClose');
    if (closeBtn) closeBtn.addEventListener('click', function() { if ($detailOverlay) $detailOverlay.classList.remove('active'); });

    // Wire clone button
    var cloneBtn = document.getElementById('graveyardCloneBtn');
    if (cloneBtn) {
      cloneBtn.addEventListener('click', async function() {
        if (!confirm('Reincarnate ' + entry.id + ' as a new ticket?')) return;
        try {
          var r = await apiCall('clone_from_graveyard', { source_id: entry.id }, 'POST');
          if (r.ok) {
            alert('New ticket created: ' + r.new_id);
            if ($detailOverlay) $detailOverlay.classList.remove('active');
            exitGraveyard();
          } else {
            alert('Error: ' + (r.error || 'Unknown'));
          }
        } catch(e) { alert('Error: ' + e.message); }
      });
    }
  }

  function wireExitGraveyard() {
    var btn = document.getElementById('exitGraveyard');
    if (btn) btn.addEventListener('click', exitGraveyard);
  }

  function exitGraveyard() {
    graveyardMode = false;
    var $heading = document.querySelector('.tsd-header h1');
    if ($heading) $heading.textContent = 'Tech Support Tickets';
    loadStats();
    loadTickets();
  }

  // Graveyard stat card click
  var $graveyardCard = document.getElementById('statGraveyardCard');
  if ($graveyardCard) $graveyardCard.addEventListener('click', showGraveyard);

  // Bulk inter button
  var $bulkInterBtn = document.getElementById('bulkInterBtn');
  if ($bulkInterBtn) {
    $bulkInterBtn.addEventListener('click', async function() {
      if (!confirm('Send ALL resolved, closed, and archived tickets to the Graveyard?\n\nThis extracts their knowledge and permanently removes the originals.')) return;
      try {
        var r = await apiCall('bulk_inter', {}, 'POST');
        if (r.ok) {
          alert('Interred ' + (r.interred || 0) + ' ticket(s) into the Graveyard.');
          loadStats();
          loadTickets();
        } else {
          alert('Error: ' + (r.error || 'Unknown'));
        }
      } catch(e) { alert('Error: ' + e.message); }
    });
  }

  /* ── Init ── */
  loadStats();
  loadTickets();

  // Auto-refresh every 60 seconds
  setInterval(() => { loadStats(); loadTickets(); }, 60000);

})();
