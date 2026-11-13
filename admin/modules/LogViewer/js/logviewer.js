/**
 * LogViewer — JavaScript
 *
 * Dashboard stats, log viewer with tail, threat timeline.
 * Borrows proven log parsing from StatsCaptain's log_viewer.js
 *
 * @package  LuminalCMS
 * @module   LogViewer
 * @version  1.0.0
 */
(function() {
    'use strict';

    const API = window.LV_CONFIG?.apiUrl || '/admin/modules/LogViewer/api.php';
    let currentRange = 'today';
    let currentTab = 'dashboard';

    // Log viewer state
    let currentLogPath = null;
    let currentLogName = null;
    let liveTailInterval = null;
    let lastLogSize = 0;
    let displayObjects = [];
    let snippetPage = 0;
    const LINES_PER_PAGE = 200;
    const resolvedCache = {};
    let currentSort = { key: 'datetime', order: 'desc' };

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------
    function esc(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function fmt(n) {
        return (n || 0).toLocaleString();
    }

    function fmtBytes(b) {
        if (!b) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    async function api(action, params = {}) {
        const url = new URL(API, location.origin);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        const r = await fetch(url);
        return r.json();
    }

    async function apiPost(action, body) {
        const url = new URL(API, location.origin);
        url.searchParams.set('action', action);
        const r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return r.json();
    }

    function $(id) { return document.getElementById(id); }

    // ---------------------------------------------------------------
    //  Tab switching
    // ---------------------------------------------------------------
    document.querySelectorAll('.lv-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.lv-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.lv-tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            const target = tab.dataset.tab;
            const content = $('tab-' + target);
            if (content) content.classList.add('active');
            currentTab = target;

            if (target === 'dashboard') loadDashboard();
            else if (target === 'errors') loadErrors();
        });
    });

    // ---------------------------------------------------------------
    //  Range switching
    // ---------------------------------------------------------------
    document.querySelectorAll('.lv-range-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.lv-range-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentRange = btn.dataset.range;
            if (currentTab === 'dashboard') loadDashboard();
            else if (currentTab === 'errors') loadErrors();
        });
    });

    // ---------------------------------------------------------------
    //  Filter state & patterns
    // ---------------------------------------------------------------
    let activeFilter = null; // null | 'threats' | '4xx' | '5xx' | 'admin'
    const threatFilterPatterns = [
        /\/wp-(?:admin|login|content|includes|config|json|cron)/i,
        /\/xmlrpc\.php/i,
        /\.env/i,
        /\/\.git/i,
        /(?:UNION\s+SELECT|DROP\s+TABLE)/i,
        /<script/i,
        /\.\.\//,
        /\/(?:c99|r57|shell|cmd)\.php/i,
        /\/phpmyadmin/i,
        /\/backup/i,
        /\/config\.(json|yml|yaml|ini|xml|php\.bak)/i,
        /\/monitoring/i,
    ];

    // Switch to Access Log tab with a filter applied
    function switchToAccessWithFilter(filter) {
        document.querySelectorAll('.lv-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lv-tab-content').forEach(c => c.classList.remove('active'));
        const accessTab = document.querySelector('.lv-tab[data-tab="access"]');
        if (accessTab) accessTab.classList.add('active');
        const accessContent = $('tab-access');
        if (accessContent) accessContent.classList.add('active');
        currentTab = 'access';

        // Set filter state
        activeFilter = filter || null;

        // Get log path from first file in sidebar
        const firstLog = document.querySelector('#lv-access-files .lv-log-file-item');
        if (!firstLog) return;
        document.querySelectorAll('.lv-log-file-item').forEach(i => i.classList.remove('active'));
        firstLog.classList.add('active');
        const path = firstLog.dataset.logpath;
        const name = firstLog.dataset.logname;
        currentLogPath = path;
        currentLogName = name;
        const titleEl = $('lv-current-log-title');
        if (titleEl) titleEl.textContent = name;

        if (filter) {
            // Use server-side filtered fetch — returns only matching lines
            fetchFilteredLines(path, filter);
        } else {
            // No filter — normal load
            fetchLogSnippet(name, path, false, false);
        }
    }

    // Fetch server-side filtered lines — renders them directly, no gates
    async function fetchFilteredLines(path, filter) {
        const outputEl = $('lv-log-output');
        const tailBtn = $('lv-tail-btn');
        const olderBtn = $('lv-load-older');
        if (olderBtn) olderBtn.style.display = 'none';
        if (tailBtn) tailBtn.disabled = true;
        stopTail();

        outputEl.innerHTML = '<div class="lv-log-line"><span class="lv-muted">Scanning log...</span></div>';

        try {
            const res = await api('get_filtered_lines', { logpath: path, filter: filter, limit: 500 });
            if (!res.success) throw new Error(res.message || 'Failed');

            displayObjects = res.data.lines.map(parseLine);
            lastLogSize = res.data.current_size || 0;

            // Sort
            displayObjects.sort((a, b) => {
                let va = a.datetimeParsed?.getTime() || -Infinity;
                let vb = b.datetimeParsed?.getTime() || -Infinity;
                return vb - va; // newest first
            });

            // Clear filter so sortAndRender won't re-filter
            activeFilter = null;

            // Render directly — just the lines, no banner, no clicking
            renderLines(displayObjects, 'full');

            // Show a small back-link in the toolbar title
            const titleEl = $('lv-current-log-title');
            if (titleEl) {
                let label = filterLabels[filter];
                if (!label && filter.startsWith('threat_')) {
                    const catName = threatCategoryNames[filter.substring(7)] || filter.substring(7);
                    label = catName;
                }
                const total = res.data.total_matched || displayObjects.length;
                titleEl.innerHTML = esc(label || filter) + ' <span style="color:var(--lv-muted);font-weight:400">(' + total + ' entries)</span> &nbsp;<a href="#" id="lv-back-to-all" style="font-size:12px;color:var(--lv-accent)">Back to full log</a>';
                const backLink = $('lv-back-to-all');
                if (backLink) backLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    fetchLogSnippet(currentLogName, currentLogPath, false, false);
                });
            }

            if (tailBtn) tailBtn.disabled = false;
        } catch (err) {
            outputEl.innerHTML = `<div class="lv-log-line"><span style="color:var(--lv-red)">Error: ${esc(err.message)}</span></div>`;
        }
    }

    // Scroll to a dashboard element
    function scrollToDashboardEl(id) {
        const el = $(id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Card click handlers
    const cardActions = {
        'lv-stat-requests':  () => switchToAccessWithFilter(null),
        'lv-stat-visitors':  () => switchToAccessWithFilter(null),
        'lv-stat-pageviews': () => scrollToDashboardEl('lv-top-pages'),
        'lv-stat-bandwidth': () => switchToAccessWithFilter(null),
        'lv-stat-4xx':       () => switchToAccessWithFilter('4xx'),
        'lv-stat-5xx':       () => switchToAccessWithFilter('5xx'),
        'lv-threat-card':    () => switchToAccessWithFilter('threats'),
        'lv-admin-hits':     () => switchToAccessWithFilter('admin'),
    };

    Object.entries(cardActions).forEach(([id, handler]) => {
        // For cards, the clickable element is the parent .lv-card
        const el = $(id);
        if (!el) return;
        const card = el.closest('.lv-card') || el;
        card.style.cursor = 'pointer';
        card.addEventListener('click', handler);
    });

    // Refresh
    const refreshBtn = $('lv-refresh');
    if (refreshBtn) refreshBtn.addEventListener('click', () => {
        if (currentTab === 'dashboard') loadDashboard(true);
        else if (currentTab === 'errors') loadErrors();
    });

    // ---------------------------------------------------------------
    //  Dashboard
    // ---------------------------------------------------------------
    let dashboardLoadedRange = null; // tracks which range was last loaded
    let dashboardLastFetch = 0;     // timestamp of last successful fetch

    async function loadDashboard(force = false) {
        // Skip re-fetch if same range was loaded recently (within 30s) and not forced
        const now = Date.now();
        if (!force && dashboardLoadedRange === currentRange && (now - dashboardLastFetch) < 30000) {
            return; // already have fresh data displayed
        }

        const params = { range: currentRange };
        if (force) params.force = '1';
        const res = await api('dashboard', params);
        if (!res.success || !res.data) return;

        dashboardLoadedRange = currentRange;
        dashboardLastFetch = now;

        const d = res.data;
        const t = d.totals || {};

        $('lv-stat-requests').textContent = fmt(t.requests);
        $('lv-stat-visitors').textContent = fmt(t.unique_visitors);
        $('lv-stat-pageviews').textContent = fmt(t.page_views);
        $('lv-stat-bandwidth').textContent = fmtBytes(t.bandwidth);
        $('lv-stat-4xx').textContent = fmt(t.client_errors);
        $('lv-stat-5xx').textContent = fmt(t.server_errors);
        $('lv-stat-threats').textContent = fmt(t.threats);

        // Admin hits badge
        const adminBadge = $('lv-admin-hits');
        if (adminBadge) adminBadge.textContent = fmt(t.admin_hits || 0);

        // Threat scoreboard
        const threatEl = $('lv-threat-categories');
        const scoreTotal = $('lv-scoreboard-total');
        if (scoreTotal) scoreTotal.textContent = fmt(t.threats);

        if (d.top_threats && d.top_threats.length) {
            threatEl.innerHTML = d.top_threats.map(th => `
                <div class="lv-threat-card" data-category="${esc(th.category)}" style="cursor:pointer" title="Click to view ${esc(th.name)} entries">
                    <div class="lv-threat-card-icon sev-${th.severity}"><i class="${esc(th.icon)}"></i></div>
                    <div class="lv-threat-card-info">
                        <div class="lv-threat-card-name">${esc(th.name)}</div>
                        <div class="lv-threat-card-sev sev-${th.severity}">${th.severity} severity</div>
                    </div>
                    <div class="lv-threat-card-count sev-${th.severity}">${fmt(th.count)}</div>
                </div>
            `).join('');
            // Click a threat category card → filter access log to that category
            threatEl.querySelectorAll('.lv-threat-card[data-category]').forEach(card => {
                card.addEventListener('click', () => {
                    const cat = card.dataset.category;
                    switchToAccessWithFilter('threat_' + cat);
                });
            });
        } else {
            threatEl.innerHTML = '<div class="lv-threat-allclear">&#10004; No threats detected — all clear!</div>';
        }

        // Top pages
        const pagesEl = $('lv-top-pages');
        if (d.top_pages && d.top_pages.length) {
            pagesEl.innerHTML = d.top_pages.map((p, i) => `
                <div class="lv-top-row">
                    <div class="lv-top-rank">${i + 1}</div>
                    <div class="lv-top-label" title="${esc(p.url)}">${esc(p.url)}</div>
                    <div class="lv-top-count">${fmt(p.count)}</div>
                </div>
            `).join('');
        } else {
            pagesEl.innerHTML = '<div class="lv-muted" style="padding:12px">No page views recorded.</div>';
        }

        // Recent threats — compact for narrow panel
        const recentEl = $('lv-recent-threats');
        if (d.recent_threats && d.recent_threats.length) {
            recentEl.innerHTML = d.recent_threats.map(th => {
                const sc = String(th.status);
                const statusClass = sc.startsWith('4') ? 'lv-status-4xx' : sc.startsWith('5') ? 'lv-status-5xx' : '';
                const timeParts = th.time.match(/(\d{2}:\d{2}:\d{2})/);
                const shortTime = timeParts ? timeParts[1] : th.time.substring(0, 8);
                return `
                    <div class="lv-threat-row" title="${esc(th.name)}: ${esc(th.url)}">
                        <div class="lv-threat-row-time">${shortTime}</div>
                        <div class="lv-threat-row-ip">${esc(th.ip)}</div>
                        <div class="lv-threat-row-url">${esc(th.url)}</div>
                        <div class="lv-threat-row-status ${statusClass}">${sc}</div>
                    </div>
                `;
            }).join('');
        } else {
            recentEl.innerHTML = '<div class="lv-muted" style="padding:12px">No recent threats.</div>';
        }

        // Top IPs
        const ipsEl = $('lv-top-ips');
        if (d.top_ips && d.top_ips.length) {
            ipsEl.innerHTML = d.top_ips.map((ip, i) => `
                <div class="lv-top-row">
                    <div class="lv-top-rank">${i + 1}</div>
                    <div class="lv-top-label">${esc(ip.ip)}</div>
                    <div class="lv-top-count">${fmt(ip.count)}</div>
                </div>
            `).join('');
        } else {
            ipsEl.innerHTML = '<div class="lv-muted" style="padding:12px">No offending IPs.</div>';
        }

        // Status codes
        const statusEl = $('lv-status-codes');
        if (d.status_codes && d.status_codes.length) {
            statusEl.innerHTML = d.status_codes.map(s => {
                const sc = String(s.code);
                const cls = sc.startsWith('2') ? 'lv-status-2xx' : sc.startsWith('3') ? 'lv-status-3xx' : sc.startsWith('4') ? 'lv-status-4xx' : sc.startsWith('5') ? 'lv-status-5xx' : '';
                return `
                    <div class="lv-status-item">
                        <span class="lv-status-code ${cls}">${sc}</span>
                        <span class="lv-status-count">${fmt(s.count)}</span>
                    </div>
                `;
            }).join('');
        } else {
            statusEl.innerHTML = '<div class="lv-muted">No data.</div>';
        }
    }

    // ---------------------------------------------------------------
    //  Error Log Tab — columnar with sort & filter
    // ---------------------------------------------------------------
    let errorData = [];
    let errorSort = { key: 'time', order: 'desc' };
    let errorLevelFilter = '';

    async function loadErrors() {
        const res = await api('errors', { range: currentRange });
        if (!res.success || !res.data) return;
        const d = res.data;

        // Summary badges
        const summaryEl = $('lv-error-summary');
        const levels = d.by_level || {};
        const badges = Object.entries(levels).map(([level, count]) => {
            const cls = (level === 'error' || level === 'crit' || level === 'alert' || level === 'emerg') ? 'level-error' :
                        (level === 'warn' || level === 'warning') ? 'level-warn' :
                        (level === 'notice') ? 'level-notice' : 'level-other';
            return `<div class="lv-error-badge ${cls}" style="cursor:pointer" data-elevel="${esc(level)}"><span>${level}</span><strong>${fmt(count)}</strong></div>`;
        });
        if (badges.length) {
            summaryEl.innerHTML = `<div class="lv-error-badge level-other"><span>Total</span><strong>${fmt(d.total)}</strong></div>` + badges.join('');
            // Click badge to filter
            summaryEl.querySelectorAll('[data-elevel]').forEach(b => {
                b.addEventListener('click', () => {
                    const lf = $('lv-error-level-filter');
                    const lvl = b.dataset.elevel;
                    if (lf) lf.value = (lvl === 'warn' || lvl === 'warning') ? 'warn' : lvl;
                    errorLevelFilter = lvl;
                    renderErrors();
                });
            });
        } else {
            summaryEl.innerHTML = '<div class="lv-muted">No errors in this period.</div>';
        }

        // Store error data for sorting/filtering
        errorData = (d.errors || []).map(e => ({
            level: e.level || 'error',
            ip: e.ip || '-',
            time: e.time || '',
            message: e.message || '',
            raw: e.raw || e.message || '',
        }));

        renderErrors();
    }

    function renderErrors() {
        const listEl = $('lv-error-list');
        if (!listEl) return;

        let items = errorData.slice();

        // Filter by level
        if (errorLevelFilter) {
            items = items.filter(e => {
                if (errorLevelFilter === 'warn') return e.level === 'warn' || e.level === 'warning';
                return e.level === errorLevelFilter;
            });
        }

        // Sort
        items.sort((a, b) => {
            let va, vb;
            if (errorSort.key === 'time') {
                va = a.time; vb = b.time;
            } else if (errorSort.key === 'level') {
                va = a.level; vb = b.level;
            } else if (errorSort.key === 'ip') {
                va = a.ip; vb = b.ip;
            } else {
                va = a.message; vb = b.message;
            }
            const cmp = String(va).localeCompare(String(vb));
            return errorSort.order === 'asc' ? cmp : -cmp;
        });

        if (!items.length) {
            listEl.innerHTML = '<div class="lv-muted" style="padding:12px">No errors found. Clean bill of health!</div>';
            return;
        }

        listEl.innerHTML = items.map(e => {
            const lvlCls = (e.level === 'error' || e.level === 'crit' || e.level === 'alert' || e.level === 'emerg') ? 'err' :
                           (e.level === 'warn' || e.level === 'warning') ? 'warn' :
                           (e.level === 'notice') ? 'notice' : 'other';
            return `<div class="lv-error-row" title="${esc(e.raw)}">
                <div class="lv-ecol-level"><span class="lv-error-level-badge ${lvlCls}">${esc(e.level)}</span></div>
                <div class="lv-ecol-ip">${esc(e.ip)}</div>
                <div class="lv-ecol-time">${esc(e.time)}</div>
                <div class="lv-ecol-msg">${esc(e.message)}</div>
            </div>`;
        }).join('');
    }

    // Error sort dropdown
    const errorSortSel = $('lv-error-sort');
    if (errorSortSel) errorSortSel.addEventListener('change', () => {
        const [key, order] = errorSortSel.value.split('_');
        errorSort = { key, order };
        renderErrors();
    });

    // Error level filter dropdown
    const errorLevelSel = $('lv-error-level-filter');
    if (errorLevelSel) errorLevelSel.addEventListener('change', () => {
        errorLevelFilter = errorLevelSel.value;
        renderErrors();
    });

    // Error column header click sort
    document.querySelectorAll('.lv-error-header > div[data-esort]').forEach(col => {
        col.addEventListener('click', () => {
            const key = col.dataset.esort;
            if (errorSort.key === key) {
                errorSort.order = errorSort.order === 'asc' ? 'desc' : 'asc';
            } else {
                errorSort = { key, order: 'asc' };
            }
            if (errorSortSel) errorSortSel.value = `${key}_${errorSort.order}`;
            renderErrors();
        });
    });

    // ---------------------------------------------------------------
    //  Log Viewer — File selection
    // ---------------------------------------------------------------
    document.querySelectorAll('.lv-log-file-item').forEach(item => {
        item.addEventListener('click', () => {
            document.querySelectorAll('.lv-log-file-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            const path = item.dataset.logpath;
            const name = item.dataset.logname;
            if (path && name) {
                currentSort = { key: 'datetime', order: 'desc' };
                const sortSel = $('lv-sort-select');
                if (sortSel) sortSel.value = 'datetime_desc';
                fetchLogSnippet(name, path);
            }
        });
    });

    // ---------------------------------------------------------------
    //  Log Viewer — Snippet fetch & render
    // ---------------------------------------------------------------
    async function fetchLogSnippet(name, path, isPaging = false, preserveFilter = false) {
        stopTail();
        if (!isPaging && !preserveFilter) activeFilter = null;
        currentLogPath = path;
        currentLogName = name;
        const outputEl = $('lv-log-output');
        const titleEl = $('lv-current-log-title');
        const tailBtn = $('lv-tail-btn');
        const olderBtn = $('lv-load-older');

        if (!isPaging) {
            snippetPage = 0;
            displayObjects = [];
            outputEl.innerHTML = '<div class="lv-log-line"><span class="lv-muted">Loading ' + esc(name) + '...</span></div>';
        }
        if (titleEl) titleEl.textContent = name;

        try {
            const res = await api('get_snippet', { logpath: path, lines: LINES_PER_PAGE, page: snippetPage });
            if (!res.success) throw new Error(res.message || 'Failed');
            lastLogSize = res.data.current_size;

            const newObjs = res.data.lines.map(parseLine);
            if (isPaging) {
                displayObjects = newObjs.concat(displayObjects);
            } else {
                displayObjects = newObjs;
            }
            sortAndRender();
            snippetPage++;

            if (tailBtn) tailBtn.disabled = false;
            if (olderBtn) olderBtn.style.display = (res.data.total_lines_in_file > displayObjects.length) ? '' : 'none';
        } catch (err) {
            outputEl.innerHTML = `<div class="lv-log-line"><span style="color:var(--lv-red)">Error: ${esc(err.message)}</span></div>`;
            if (tailBtn) tailBtn.disabled = true;
            if (olderBtn) olderBtn.style.display = 'none';
        }
    }

    // Load older
    const olderBtn = $('lv-load-older');
    if (olderBtn) olderBtn.addEventListener('click', () => {
        if (currentLogPath && currentLogName) fetchLogSnippet(currentLogName, currentLogPath, true);
    });

    // ---------------------------------------------------------------
    //  Log Viewer — Parse line (CLF + error log)
    // ---------------------------------------------------------------
    function parseLine(raw) {
        const obj = {
            raw, ip: '-', datetimeRaw: '', datetimeParsed: null,
            method: '', url: '', status: '', logEntry: raw, isError: false
        };

        // Apache error log
        const errRx = /^\[([^\]]+)\]\s*(?:\[.*?\]\s*)*(\[client\s+([^\]]+)\])?\s*(.*)/i;
        let m = raw.match(errRx);
        if (m) {
            obj.datetimeRaw = m[1];
            try { obj.datetimeParsed = new Date(m[1].replace(/\.\d+$/, '')); } catch (e) {}
            if (m[3]) obj.ip = m[3].split(':')[0];
            obj.logEntry = m[4] || raw;
            obj.isError = true;
            return obj;
        }

        // CLF
        const clfRx = /^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+)\s+(\S+)\s+HTTP\/[\d.]+" (\d{3}) (\S+)\s*"([^"]*)"\s*"([^"]*)"/;
        m = raw.match(clfRx);
        if (m) {
            obj.ip = m[1];
            obj.datetimeRaw = m[2];
            try {
                obj.datetimeParsed = new Date(Date.parse(m[2].replace(':', ' ').replace(/\//g, ' ')));
            } catch (e) {}
            obj.method = m[3];
            obj.url = m[4];
            obj.status = m[5];
            obj.userAgent = m[8] || '';
            obj.logEntry = raw.substring(raw.indexOf('] "') + 3);
            return obj;
        }

        return obj;
    }

    // ---------------------------------------------------------------
    //  Log Viewer — Render
    // ---------------------------------------------------------------
    // Column widths (resizable, persisted)
    let colWidthIP = parseInt(localStorage.getItem('lv_colIP')) || 190;
    let colWidthTime = parseInt(localStorage.getItem('lv_colTime')) || 170;

    function renderLines(objs, mode = 'full') {
        const el = $('lv-log-output');
        if (!el) return;

        const frag = document.createDocumentFragment();

        objs.forEach(obj => {
            const line = document.createElement('div');
            line.className = 'lv-log-line';

            // IP / Resolved Name column
            const ipEl = document.createElement('span');
            ipEl.className = 'lv-col-ip';
            ipEl.style.flexBasis = colWidthIP + 'px';
            ipEl.textContent = obj.ip;
            if (obj.ip && obj.ip !== '-') resolveIp(obj.ip, ipEl);
            if (obj.userAgent && /bot|spider|crawler|slurp/i.test(obj.userAgent)) ipEl.classList.add('bot');

            // Date/Time column
            const timeEl = document.createElement('span');
            timeEl.className = 'lv-col-time';
            timeEl.style.flexBasis = colWidthTime + 'px';
            timeEl.textContent = obj.datetimeRaw || '';

            // Log Entry column (full request line, like original)
            const entryEl = document.createElement('span');
            entryEl.className = 'lv-col-entry';
            entryEl.textContent = obj.logEntry || obj.raw || '';

            // Color coding
            if (obj.status) {
                if (obj.status.startsWith('5')) entryEl.classList.add('error');
                else if (obj.status.startsWith('4')) entryEl.classList.add('warning');
            }
            if (obj.isError) entryEl.classList.add('error');

            // Threat highlight
            const urlText = obj.url || obj.logEntry || '';
            for (const pat of threatFilterPatterns) {
                if (pat.test(urlText)) { entryEl.classList.add('threat'); break; }
            }

            line.appendChild(ipEl);
            line.appendChild(timeEl);
            line.appendChild(entryEl);
            frag.appendChild(line);
        });

        if (mode === 'full') el.innerHTML = '';
        if (mode === 'prepend') el.prepend(frag);
        else el.appendChild(frag);

        if (el.children.length === 0) {
            el.innerHTML = '<div class="lv-log-line"><span class="lv-muted">No entries.</span></div>';
        }
    }

    // --- Column resizer logic (matches original Vstats) ---
    (function initResizers() {
        const resizerIP = $('resizerIP');
        const resizerTime = $('resizerTime');
        const hdrIP = $('hdrIP');
        const hdrTime = $('hdrTime');
        let startX, startW, activeResizer;

        function onMouseDown(e, hdr, key) {
            e.preventDefault();
            startX = e.clientX;
            startW = hdr.offsetWidth;
            activeResizer = { hdr, key };
            document.body.classList.add('lv-resizing');
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        }
        function onMouseMove(e) {
            if (!activeResizer) return;
            const newW = Math.max(80, Math.min(startW + (e.clientX - startX), 500));
            activeResizer.hdr.style.flexBasis = newW + 'px';
            const cls = activeResizer.key === 'ip' ? 'lv-col-ip' : 'lv-col-time';
            document.querySelectorAll('.' + cls).forEach(el => { el.style.flexBasis = newW + 'px'; });
            if (activeResizer.key === 'ip') colWidthIP = newW;
            else colWidthTime = newW;
        }
        function onMouseUp() {
            if (activeResizer) {
                localStorage.setItem('lv_col' + (activeResizer.key === 'ip' ? 'IP' : 'Time'), activeResizer.key === 'ip' ? colWidthIP : colWidthTime);
            }
            activeResizer = null;
            document.body.classList.remove('lv-resizing');
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }

        if (resizerIP && hdrIP) resizerIP.addEventListener('mousedown', e => onMouseDown(e, hdrIP, 'ip'));
        if (resizerTime && hdrTime) resizerTime.addEventListener('mousedown', e => onMouseDown(e, hdrTime, 'time'));

        // Apply saved widths to headers
        if (hdrIP) hdrIP.style.flexBasis = colWidthIP + 'px';
        if (hdrTime) hdrTime.style.flexBasis = colWidthTime + 'px';
    })();

    // Per-category threat patterns (matches PHP lv_get_threat_patterns)
    const threatCategoryPatterns = {
        wordpress_probing: [/\/wp-(?:admin|login|content|includes|config|json|api|cron|signup|activate|blog-header|comments-post)/i, /\/xmlrpc\.php/i],
        env_file_hunting: [/\.env(?:\.|$)/i],
        git_repo_probing: [/\/\.git(?:\/|$)/i, /\/\.svn(?:\/|$)/i],
        sql_injection: [/(?:UNION\s+(?:ALL\s+)?SELECT|SELECT\s+.*FROM\s|DROP\s+TABLE|INSERT\s+INTO)/i],
        xss_attack: [/<script/i, /javascript:/i],
        path_traversal: [/\.\.\//],
        shell_upload: [/\/(?:c99|r57|wso|b374k|alfa|shell|cmd|eval|exec)\.php/i],
        admin_probing: [/\/(?:phpmyadmin|adminer|administrator|manager\/html|cpanel|plesk|webmail)/i],
        config_probing: [/\/(?:config|configuration|settings|database)\.(?:php|yml|yaml|ini|json)/i, /\/\.htpasswd/i],
        backup_hunting: [/\.(?:sql|bak|backup|dump|old|orig|save|swp|sav)$/i, /\/(?:backup|dump)\/?$/i],
        scanner_bot: [/(?:sqlmap|nikto|nmap|dirbuster|nuclei|wpscan|acunetix)/i],
        suspicious_extensions: [/\.(?:asp|aspx|jsp|cgi|exe|dll|bat|cmd|com)$/i],
    };

    function matchesFilter(obj, filter) {
        if (!filter) return true;
        const url = obj.url || obj.logEntry || '';

        if (filter === 'threats') {
            for (const pat of threatFilterPatterns) { if (pat.test(url)) return true; }
            return false;
        }
        if (filter.startsWith('threat_')) {
            const cat = filter.substring(7);
            const pats = threatCategoryPatterns[cat];
            if (!pats) return false;
            for (const pat of pats) { if (pat.test(url)) return true; }
            // Also check user agent for scanner_bot
            if (cat === 'scanner_bot' && obj.userAgent) {
                for (const pat of pats) { if (pat.test(obj.userAgent)) return true; }
            }
            return false;
        }
        if (filter === '4xx') return obj.status && obj.status.startsWith('4');
        if (filter === '5xx') return obj.status && obj.status.startsWith('5');
        if (filter === 'admin') return url.includes('/admin/') || url === '/admin';
        return true;
    }

    const threatCategoryNames = {
        wordpress_probing: 'WordPress Probing', env_file_hunting: '.env File Hunting',
        git_repo_probing: 'Git/SVN Probing', sql_injection: 'SQL Injection',
        xss_attack: 'XSS / Script Injection', path_traversal: 'Path Traversal',
        shell_upload: 'Shell / Backdoor', admin_probing: 'Admin Panel Probing',
        config_probing: 'Config File Probing', backup_hunting: 'Backup Hunting',
        scanner_bot: 'Scanner / Bot', suspicious_extensions: 'Suspicious Extensions',
    };

    const filterLabels = {
        'threats': '&#128737; Showing all threats',
        '4xx': '&#9888; Showing client errors (4xx)',
        '5xx': '&#10060; Showing server errors (5xx)',
        'admin': '&#128736; Showing admin hits',
    };

    function sortAndRender() {
        if (!displayObjects.length) return renderLines([], 'full');

        let toRender = displayObjects;

        toRender.sort((a, b) => {
            let va, vb;
            if (currentSort.key === 'datetime') {
                va = a.datetimeParsed?.getTime() || (currentSort.order === 'asc' ? Infinity : -Infinity);
                vb = b.datetimeParsed?.getTime() || (currentSort.order === 'asc' ? Infinity : -Infinity);
            } else if (currentSort.key === 'hostname') {
                va = (resolvedCache[a.ip] || a.ip || '').toLowerCase();
                vb = (resolvedCache[b.ip] || b.ip || '').toLowerCase();
            } else {
                va = (a.logEntry || '').toLowerCase();
                vb = (b.logEntry || '').toLowerCase();
            }
            if (typeof va === 'string') return currentSort.order === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            return currentSort.order === 'asc' ? va - vb : vb - va;
        });
        renderLines(toRender, 'full');
    }

    // Sort controls
    const sortSel = $('lv-sort-select');
    if (sortSel) sortSel.addEventListener('change', () => {
        const [key, order] = sortSel.value.split('_');
        currentSort = { key, order };
        sortAndRender();
    });

    // Column header click sort
    document.querySelectorAll('.lv-log-header > div[data-sort]').forEach(col => {
        col.addEventListener('click', () => {
            const key = col.dataset.sort;
            if (currentSort.key === key) {
                currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = { key, order: 'asc' };
            }
            if (sortSel) sortSel.value = `${key}_${currentSort.order}`;
            sortAndRender();
        });
    });

    // ---------------------------------------------------------------
    //  Log Viewer — Tail
    // ---------------------------------------------------------------
    const tailBtn = $('lv-tail-btn');
    const stopBtn = $('lv-stop-tail-btn');

    if (tailBtn) tailBtn.addEventListener('click', startTail);
    if (stopBtn) stopBtn.addEventListener('click', stopTail);

    function startTail() {
        if (!currentLogPath) return;
        stopTail();
        if (tailBtn) tailBtn.style.display = 'none';
        if (stopBtn) stopBtn.style.display = '';
        if (olderBtn) olderBtn.style.display = 'none';

        liveTailInterval = setInterval(async () => {
            if (!currentLogPath) return stopTail();
            try {
                const res = await api('get_new_lines', { logpath: currentLogPath, offset: lastLogSize });
                if (res.success && res.data?.new_lines?.length) {
                    const newObjs = res.data.new_lines.map(parseLine);
                    displayObjects = displayObjects.concat(newObjs);
                    renderLines(newObjs, 'append');
                    const el = $('lv-log-output');
                    if (el) el.scrollTop = el.scrollHeight;
                }
                if (res.data) lastLogSize = res.data.new_offset_suggested;
            } catch (e) { /* quiet */ }
        }, 2500);
    }

    function stopTail() {
        if (liveTailInterval) clearInterval(liveTailInterval);
        liveTailInterval = null;
        if (tailBtn && currentLogPath) tailBtn.style.display = '';
        if (stopBtn) stopBtn.style.display = 'none';
    }

    // ---------------------------------------------------------------
    //  IP Resolution
    // ---------------------------------------------------------------
    async function resolveIp(ip, el) {
        if (!ip || ip === '-' || !el) return;
        if (resolvedCache[ip]) {
            el.textContent = resolvedCache[ip];
            el.title = `IP: ${ip} | Host: ${resolvedCache[ip]}`;
            if (resolvedCache[ip] !== ip) el.classList.add('resolved');
            return;
        }
        el.classList.add('resolving');
        el.textContent = ip;
        try {
            const res = await api('resolve_ip', { ip });
            const host = (res.success && res.hostname) ? res.hostname : ip;
            resolvedCache[ip] = host;
            el.textContent = host;
            el.title = `IP: ${ip} | Host: ${host}`;
            if (host !== ip) el.classList.add('resolved');
        } catch (e) {
            resolvedCache[ip] = ip;
        } finally {
            el.classList.remove('resolving');
        }
    }

    // ---------------------------------------------------------------
    //  Settings
    // ---------------------------------------------------------------
    const saveBtn = $('lv-save-config');
    if (saveBtn) saveBtn.addEventListener('click', async () => {
        const ttl = parseInt($('lv-cfg-ttl')?.value || '300');
        const res = await apiPost('save_config', { cache_ttl: ttl });
        if (res.success) {
            saveBtn.textContent = 'Saved!';
            setTimeout(() => { saveBtn.textContent = 'Save Settings'; }, 2000);
        }
    });

    const redetectBtn = $('lv-redetect');
    if (redetectBtn) redetectBtn.addEventListener('click', async () => {
        redetectBtn.textContent = 'Detecting...';
        const res = await apiPost('save_config', { redetect: true });
        if (res.success) {
            redetectBtn.textContent = 'Done — reloading...';
            setTimeout(() => location.reload(), 1000);
        } else {
            redetectBtn.textContent = 'Re-detect from Apache';
        }
    });

    const clearCacheBtn = $('lv-clear-cache');
    if (clearCacheBtn) clearCacheBtn.addEventListener('click', async () => {
        const res = await api('clear_cache');
        if (res.success) {
            clearCacheBtn.textContent = 'Cleared!';
            setTimeout(() => { clearCacheBtn.textContent = 'Clear Cache'; }, 2000);
        }
    });

    // ---------------------------------------------------------------
    //  Auto-load first access log, dashboard on init
    // ---------------------------------------------------------------
    loadDashboard();

    const firstLog = document.querySelector('#lv-access-files .lv-log-file-item');
    if (firstLog) {
        // Pre-select first log but don't load until user switches to Access tab
    }

})();
