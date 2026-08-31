/**
 * @file admin/modules/AdminThemes/js/admin-themes.js
 * @desc Builds OS chrome (menubar / dock / window controls / quick switcher)
 *       for the active admin theme and handles live, per-user theme switching.
 *       No dependencies. Safe to load twice.
 */
(function () {
  if (window.__adminThemesInit) return;
  window.__adminThemesInit = true;

  var CFG = window.AdminThemes || { active: 'luminal', available: [], base: '/admin/modules/AdminThemes' };
  var html = document.documentElement;

  /* ---- tiny helpers ---- */
  function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function cssNum(name) { var v = getComputedStyle(html).getPropertyValue(name).trim(); return parseFloat(v) || 0; }

  var LOGOS = {
    apple: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M16.4 12.9c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.8-3.5.8s-1.8-.8-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.2 0 2-1.1 2.8-2.2.9-1.3 1.2-2.5 1.2-2.6-.1 0-2.4-.9-2.6-3.6zM14.2 6.3c.6-.8 1-1.9.9-3-1 0-2.1.7-2.8 1.5-.6.7-1.1 1.8-1 2.8 1.1.1 2.2-.5 2.9-1.3z"/></svg>',
    win: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.5 10.5 4.4V11H3V5.5zM11.5 4.3 21 3v8h-9.5V4.3zM3 12h7.5v6.6L3 17.5V12zm8.5 0H21v9l-9.5-1.3V12z"/></svg>',
    gnome: '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><circle cx="7" cy="7" r="3"/><circle cx="16" cy="7" r="3"/><circle cx="7" cy="16" r="3"/><circle cx="16" cy="16" r="3"/></svg>'
  };

  /* ---- remove any previously-built chrome (for live re-theme) ---- */
  function clearChrome() {
    ['.at-topbar', '.at-dock', '.at-thememenu'].forEach(function (s) {
      var n = $(s); if (n) n.remove();
    });
    var tb = $('.at-window-titlebar'); if (tb) tb.remove();
    html.classList.remove('at-has-window-title');
  }

  /* ---- window title bar inside .content-area ---- */
  function buildWindow(theme) {
    var area = $('.content-area');
    if (!area) return;
    // derive title
    var h1 = $('.content-area h1') || $('.admin-container h1') || $('h1');
    var title = (h1 && h1.textContent.trim()) ||
                (document.title || '').replace(/\s*-\s*Admin Utils.*$/i, '').trim() || 'Admin';

    var bar = el('div', 'at-window-titlebar');
    // Fake OS window controls (min/max/close) removed — they were decorative and did
    // nothing. Keep empty left/right zones so the title stays centered in the bar.
    var left = el('div', 'at-winctl at-winctl-left');
    var right = el('div', 'at-winctl at-winctl-right');
    var t = el('div', 'at-wt-title', title);
    bar.appendChild(left);
    bar.appendChild(t);
    bar.appendChild(right);
    area.insertBefore(bar, area.firstChild);
    html.classList.add('at-has-window-title');
  }

  /* ---- top bar (mac / gnome; skipped when --at-topbar-h is 0) ---- */
  function buildTopbar(theme) {
    if (cssNum('--at-topbar-h') <= 0) return;
    var host = (CFG.host) || location.hostname;
    var bar = el('div', 'at-topbar');
    var logo = theme === 'linux' ? LOGOS.gnome : LOGOS.apple;
    var brandLabel = theme === 'linux' ? 'Activities' : host;
    var brand = el('div', 'at-tb-brand', '<span class="at-tb-logo">' + logo + '</span>' + brandLabel);
    bar.appendChild(brand);

    bar.appendChild(el('div', 'at-tb-spacer'));

    var right = el('div', 'at-tb-right');
    var pill = el('div', 'at-tb-theme', '◉ Theme');
    pill.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(pill); });
    var clock = el('div', 'at-tb-clock', '');
    right.appendChild(pill);
    right.appendChild(clock);
    bar.appendChild(right);
    document.body.appendChild(bar);
    startClock(clock);
  }

  /* ---- dock / taskbar ---- */
  function buildDock(theme) {
    if (cssNum('--at-dock-h') <= 0) return;
    var items = [
      ['🏠', 'Dashboard', '/admin/modules/Dashboard/Dashboard.php'],
      ['📄', 'Pages', '/admin/modules/PageManager/PageManager.php'],
      ['🗂️', 'Files', '/admin/modules/FileManager/FileManager.php'],
      ['🖼️', 'Media', '/admin/modules/GalleryManager/GalleryManager.php'],
      ['⚙️', 'Settings', '/admin/modules/SiteSettings/SiteSettings.php']
    ];
    var dock = el('div', 'at-dock');

    if (theme === 'windows') {
      var start = el('a', 'at-dock-item at-start');
      start.innerHTML = LOGOS.win; start.title = 'Start';
      start.href = 'javascript:void(0)';
      start.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(start); });
      dock.appendChild(start);
      dock.appendChild(el('div', 'at-dock-sep'));
    }

    items.forEach(function (it) {
      var a = el('a', 'at-dock-item', it[0]);
      a.href = it[2]; a.title = it[1];
      dock.appendChild(a);
    });
    dock.appendChild(el('div', 'at-dock-sep'));
    var themeBtn = el('a', 'at-dock-item', '🎨');
    themeBtn.href = 'javascript:void(0)'; themeBtn.title = 'Themes';
    themeBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(themeBtn); });
    dock.appendChild(themeBtn);

    document.body.appendChild(dock);
  }

  /* ---- quick theme menu ---- */
  var menuEl = null;
  var SWATCH = { luminal: '#2e4fd6', macos: 'linear-gradient(135deg,#48267a,#0a84ff)', windows: 'linear-gradient(135deg,#143c6e,#4cc2ff)', linux: 'linear-gradient(135deg,#2c001e,#e95420)' };
  function buildMenu() {
    var m = el('div', 'at-thememenu');
    m.appendChild(el('div', 'at-tm-group', 'Admin Theme'));
    (CFG.available || []).forEach(function (t) {
      var item = el('div', 'at-tm-item' + (t.slug === CFG.active ? ' active' : ''));
      item.setAttribute('data-slug', t.slug);
      var sw = el('span', 'at-tm-swatch'); sw.style.background = SWATCH[t.slug] || '#444';
      item.appendChild(sw);
      item.appendChild(el('span', 'at-tm-label', t.label));
      item.appendChild(el('span', 'at-tm-check', '✓'));
      item.addEventListener('click', function () { selectTheme(t.slug); });
      m.appendChild(item);
    });
    document.body.appendChild(m);
    return m;
  }
  function toggleMenu(anchor) {
    if (!menuEl) menuEl = buildMenu();
    if (menuEl.classList.contains('open')) { menuEl.classList.remove('open'); return; }
    var r = anchor.getBoundingClientRect();
    menuEl.style.visibility = 'hidden'; menuEl.classList.add('open');
    var mw = menuEl.offsetWidth, mh = menuEl.offsetHeight;
    var top = r.bottom + 6, left = r.left;
    if (top + mh > window.innerHeight) top = r.top - mh - 6;        // flip up (dock)
    if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
    menuEl.style.top = Math.max(8, top) + 'px';
    menuEl.style.left = Math.max(8, left) + 'px';
    menuEl.style.visibility = 'visible';
  }
  document.addEventListener('click', function () { if (menuEl) menuEl.classList.remove('open'); });

  /* ---- clock ---- */
  function startClock(node) {
    function tick() {
      var d = new Date();
      var hh = d.getHours(), mm = ('0' + d.getMinutes()).slice(-2), ss = ('0' + d.getSeconds()).slice(-2);
      var ap = hh >= 12 ? 'PM' : 'AM'; var h12 = hh % 12 || 12;
      var day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getDay()];
      node.textContent = day + '  ' + h12 + ':' + mm + ':' + ss + ' ' + ap;
    }
    tick(); setInterval(tick, 1000);
  }

  /* ---- theme css loader (for live switching) ---- */
  function ensureThemeCss(slug) {
    if (slug === 'luminal') return;
    var id = 'at-theme-css-' + slug;
    if (document.getElementById(id)) return;
    var l = document.createElement('link');
    l.id = id; l.rel = 'stylesheet';
    l.href = CFG.base + '/themes/' + slug + '.css?v=1.0.0';
    document.head.appendChild(l);
  }

  /* ---- switch + persist ---- */
  function selectTheme(slug) {
    if (slug === CFG.active && html.getAttribute('data-admin-theme') === slug) { return; }
    ensureThemeCss(slug);
    CFG.active = slug;
    html.setAttribute('data-admin-theme', slug);
    try { localStorage.setItem('admin_theme_v2', slug); } catch (e) {}
    // rebuild chrome for the new OS
    requestAnimationFrame(function () { rebuild(slug); });
    // persist to server (per-user)
    fetch(CFG.base + '/api.php?action=set', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'theme=' + encodeURIComponent(slug)
    }).then(function (r) { return r.json(); }).then(function (j) {
      toast(j && j.ok ? ('Theme saved — ' + slug) : 'Saved locally');
    }).catch(function () { toast('Saved locally (offline)'); });
  }

  function toast(msg) {
    var t = $('.at-toast') || (function () { var n = el('div', 'at-toast'); document.body.appendChild(n); return n; })();
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 1800);
  }

  /* ---- full (re)build ---- */
  function rebuild(theme) {
    clearChrome();
    if (theme === 'luminal') { if (menuEl) { menuEl.remove(); menuEl = null; } return; }
    if (menuEl) { menuEl.remove(); menuEl = null; }   // rebuild menu with fresh active state
    buildWindow(theme);
    buildTopbar(theme);
    buildDock(theme);
  }

  /* ================================================================= *
   * Display & Accessibility — font scale, contrast, tactile, density,
   * accent, palette. Per-user, applies on ANY theme incl. default.
   * ================================================================= */
  CFG.display = CFG.display || { scale: 1, contrast: 'normal', tactile: false, density: 'cozy', accent: '', palette: '', font: '', btnbg: '', btntext: '', go: '', warn: '', danger: '', surface: '' };

  var PRESETS = {
    'default': { scale: 1.0,  contrast: 'normal', tactile: false, density: 'cozy',    accent: '', palette: '', font: '', btnbg: '', btntext: '', go: '', warn: '', danger: '', surface: '' },
    'comfort': { scale: 1.15, contrast: 'normal', tactile: true,  density: 'roomy',   accent: '', palette: '' },
    'large':   { scale: 1.35, contrast: 'high',   tactile: true,  density: 'roomy',   accent: '', palette: '' },
    'compact': { scale: 0.9,  contrast: 'normal', tactile: false, density: 'compact', accent: '', palette: '' }
  };

  function applyDisplay(d) {
    html.setAttribute('data-admin-contrast', d.contrast || 'normal');
    html.setAttribute('data-admin-tactile', d.tactile ? 'on' : 'off');
    html.setAttribute('data-admin-density', d.density || 'cozy');
    html.setAttribute('data-admin-palette', d.palette || '');
    html.setAttribute('data-admin-accent', d.accent ? 'on' : 'off');
    html.setAttribute('data-admin-font', d.font || '');
    html.setAttribute('data-admin-btn', d.btnbg ? 'on' : 'off');
    html.style.setProperty('--admin-fscale', d.scale || 1);
    if (d.accent) html.style.setProperty('--admin-accent', d.accent);
    else html.style.removeProperty('--admin-accent');
    if (d.btnbg) html.style.setProperty('--admin-btnbg', d.btnbg);
    else html.style.removeProperty('--admin-btnbg');
    html.style.setProperty('--admin-btntext', d.btntext || '#ffffff');
    ['go', 'warn', 'danger', 'surface'].forEach(function (k) {
      if (d[k]) html.style.setProperty('--lum-' + k, d[k]);
      else html.style.removeProperty('--lum-' + k);
    });
  }

  var _pdTimer = null;
  function persistDisplay(d) {
    clearTimeout(_pdTimer);
    _pdTimer = setTimeout(function () {
      var body = 'scale=' + encodeURIComponent(d.scale) +
        '&contrast=' + encodeURIComponent(d.contrast) +
        '&tactile=' + (d.tactile ? '1' : '0') +
        '&density=' + encodeURIComponent(d.density) +
        '&accent=' + encodeURIComponent(d.accent || '') +
        '&palette=' + encodeURIComponent(d.palette || '') +
        '&font=' + encodeURIComponent(d.font || '') +
        '&btnbg=' + encodeURIComponent(d.btnbg || '') +
        '&btntext=' + encodeURIComponent(d.btntext || '') +
        '&go=' + encodeURIComponent(d.go || '') +
        '&warn=' + encodeURIComponent(d.warn || '') +
        '&danger=' + encodeURIComponent(d.danger || '') +
        '&surface=' + encodeURIComponent(d.surface || '');
      fetch(CFG.base + '/api.php?action=set_display', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
      }).then(function (r) { return r.json(); }).catch(function () {});
    }, 350);
  }

  function setDisplay(partial, opts) {
    CFG.display = Object.assign({}, CFG.display, partial || {});
    applyDisplay(CFG.display);
    persistDisplay(CFG.display);
    updateQuick();
    if (!(opts && opts.silent)) {
      document.dispatchEvent(new CustomEvent('admin-display-changed', { detail: CFG.display }));
    }
    return CFG.display;
  }

  function applyPreset(name) {
    var p = PRESETS[name]; if (!p) return;
    setDisplay(Object.assign({}, p));
    toast('Preset: ' + name);
  }

  /* header A− / A+ quick control */
  function bumpScale(delta) {
    var s = Math.round(((CFG.display.scale || 1) + delta) * 100) / 100;
    if (s < 0.85) s = 0.85; if (s > 1.6) s = 1.6;
    setDisplay({ scale: s });
    toast('Text size ' + Math.round(s * 100) + '%');
  }
  function updateQuick() {
    var v = $('#admin-fscale-quick .afq-val');
    if (v) v.textContent = Math.round((CFG.display.scale || 1) * 100) + '%';
  }
  function buildQuickControl() {
    if ($('#admin-fscale-quick')) return;
    var wrap = el('span'); wrap.id = 'admin-fscale-quick';
    var small = el('button', 'afq-a-small', 'A&minus;'); small.title = 'Smaller text';
    var val = el('span', 'afq-val', '');
    var big = el('button', 'afq-a-big', 'A+'); big.title = 'Larger text';
    small.addEventListener('click', function (e) { e.preventDefault(); bumpScale(-0.1); });
    big.addEventListener('click', function (e) { e.preventDefault(); bumpScale(0.1); });
    wrap.appendChild(small); wrap.appendChild(val); wrap.appendChild(big);
    // Always a FIXED, always-on-top control appended to <body> — never inside
    // the collapsible sidebar (which hid it on load → "appeared then vanished").
    document.body.appendChild(wrap);
    updateQuick();
  }

  // Public hooks (used by the AdminThemes picker page)
  CFG.select = selectTheme;
  CFG.openMenu = toggleMenu;
  CFG.setDisplay = setDisplay;
  CFG.applyPreset = applyPreset;
  CFG.PRESETS = PRESETS;

  function init() {
    rebuild(CFG.active || 'luminal');
    applyDisplay(CFG.display);   // reaffirm (head hook already set pre-paint)
    buildQuickControl();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
