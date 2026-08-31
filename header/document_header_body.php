<?php
/**
 * Document Header + Body Shell (Clean - No Inline CSS)
 * @file    /header/document_header_body.php
 * @version 2025.10.13.18.15.00
 *
 * CHANGELOG:
 * - Removed inline CSS (moved to /css/header.css for proper separation of concerns)
 * - Added .menu-toggle hamburger button for mobile navigation
 * - Added mobile-specific classes to enable responsive menu
 * - Cleaner separation: PHP handles structure, CSS handles presentation
 * 
 * Requires: header_core.php (already included by page.php)
 * Uses: $artistName, $homeSlug, $MENU_ITEMS
 * Styles: /css/header.css (imported via styles.css)
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

$h = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ---------- Brand ----------
$artistName = isset($GLOBALS['artistName']) && trim((string)$GLOBALS['artistName']) !== ''
  ? trim((string)$GLOBALS['artistName'])
  : ($_SERVER['HTTP_HOST'] ?? 'Site');

$homeSlug = isset($GLOBALS['homeSlug']) && trim((string)$GLOBALS['homeSlug']) !== ''
  ? trim((string)$GLOBALS['homeSlug'])
  : 'home';

// ---------- Logo & Title Settings ----------
$ss = $GLOBALS['SITE_SETTINGS'] ?? [];
$enableLogo   = !empty($ss['enable_logo']);
$logoPath     = trim((string)($ss['logo_path'] ?? ''));
$logoMaxWidth = isset($ss['logo_max_width']) ? (int)$ss['logo_max_width'] : 0;
$logoMb       = isset($ss['logo_margin_bottom']) ? (float)$ss['logo_margin_bottom'] : 0;
// Show title only if site_name is non-empty AND show_site_title is enabled.
// show_site_title defaults to true when key is absent (backward compat).
$rawSiteName  = trim((string)($ss['site_name'] ?? ''));
$showTitleOpt = !array_key_exists('show_site_title', $ss) || !empty($ss['show_site_title']);
$showTitle    = ($rawSiteName !== '' && $showTitleOpt);

$requested = isset($_GET['p']) ? preg_replace('/[^a-z0-9_\-]/i','',(string)$_GET['p']) : $homeSlug;

// ---------- Menu (no candidates, single known path) ----------
$menuItems = [];
if (isset($GLOBALS['MENU_ITEMS']) && is_array($GLOBALS['MENU_ITEMS'])) {
  $menuItems = $GLOBALS['MENU_ITEMS'];
}
if (empty($menuItems)) {
  $menuPath = SITE_ROOT . '/admin/data/menu/menu_items.json';
  $raw = @file_get_contents($menuPath);
  if ($raw !== false) {
    $j = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
      $source = isset($j['items']) && is_array($j['items']) ? $j['items']
              : (isset($j['menu']) && is_array($j['menu']) ? $j['menu']
              : (array_is_list($j) ? $j : []));
      $menuItems = $source;
    }
  }
}
// normalize strictly
$normalized = [];
foreach ($menuItems as $it) {
  if (!is_array($it)) continue;
  $title = trim((string)($it['title'] ?? $it['label'] ?? $it['text'] ?? ''));
  $slug  = trim((string)($it['slug']  ?? ''));
  $url   = trim((string)($it['url']   ?? $it['href'] ?? ''));
  if ($url === '' && $slug !== '') $url = '/page.php?p=' . rawurlencode($slug);
  if ($title === '' && $url  !== '') $title = $url;
  if ($title === '' || $url === '') continue;
  $normalized[] = [
    'title'=>$title,
    'slug'=>$slug,
    'url'=>$url,
    'active'=>($slug !== '' && $slug === $requested),
  ];
}
$menuItems = $normalized;

// ---------- SHF (CardManager) — inject My Profile link for logged-in members ----------
// Hoisted from the user-menu block below so the main menu can react to login state.
$shfViewer = [];
$_shfAuthPath = SITE_ROOT . '/admin/modules/CardManager/lib/auth.php';
if (is_file($_shfAuthPath)) {
  require_once $_shfAuthPath;
  $shfViewer = shf_logged_in() ? shf_member() : [];
  if (!empty($shfViewer['username'])) {
    // Drop guest-only entries (case-insensitive) and inject a Dashboard pointing at the user's profile
    $hideGuestTitles = ['sign in','log in','login','join','join the crew','register','sign up'];
    $menuItems = array_values(array_filter($menuItems, fn($mi) => !in_array(mb_strtolower(trim((string)$mi['title'])), $hideGuestTitles, true)));
    $menuItems[] = [
      'title'  => 'Dashboard',
      'slug'   => '',
      'url'    => '/trader/' . rawurlencode((string)$shfViewer['username']),
      'active' => false,
    ];
  }
}

// ---------- Menu Disabled Check ----------
$menuDisabled = isset($GLOBALS['MENU_DISABLED']) ? (bool)$GLOBALS['MENU_DISABLED'] : false;
if (!$menuDisabled && isset($GLOBALS['MENU_SETTINGS']['menu_disabled'])) {
  $menuDisabled = (bool)$GLOBALS['MENU_SETTINGS']['menu_disabled'];
}

// ---------- Styles for alignment ----------
$__ss = SITE_ROOT . '/admin/site-settings/site-style.php';
if (is_file($__ss)) { include $__ss; }

?>
<!-- DHB:BEGIN -->
<!-- Background scaffolding -->
<div id="background-container" aria-hidden="true"><video id="background-video" autoplay muted loop playsinline></video></div>
<div id="background-overlay" aria-hidden="true"></div>

<header class="header-shell header-menu is-fullwidth" id="dhb-header" data-ri="header">
  <div class="header-inner" id="dhb-header-inner" data-ri="header-inner">

    <div class="brand-wrap" id="dhb-brand-wrap" data-ri="brand-wrap">
      <?php if ($enableLogo && $logoPath): ?>
      <a class="brand-logo" id="dhb-brand-logo" data-ri="brand-logo"
         href="/page.php?p=<?php echo $h($homeSlug); ?>" aria-label="Home">
        <img id="dhb-brand-img" data-ri="brand-img"
             src="/<?php echo $h(ltrim($logoPath, '/')); ?>"
             alt="<?php echo $h($artistName); ?> logo"
             <?php if ($logoMaxWidth): ?>style="max-width:min(<?php echo $logoMaxWidth; ?>px,45vw);height:auto;<?php if ($logoMb): ?>margin-bottom:<?php echo $logoMb; ?>rem;<?php endif; ?>"<?php endif; ?>>
      </a>
      <?php endif; ?>
      <?php if ($showTitle): ?>
      <h1 class="brand-title" id="dhb-brand-title" data-ri="brand-title">
        <a class="brand-link" id="dhb-brand-link" data-ri="brand-link"
           href="/page.php?p=<?php echo $h($homeSlug); ?>">
          <?php echo $h($artistName); ?>
        </a>
      </h1>
      <?php endif; ?>
    </div>

    <?php if (!$menuDisabled): ?>
    <nav class="nav-main main-navigation" aria-label="Main Menu" id="dhb-nav-main" data-ri="nav-main">
      <ul class="site-menu" id="dhb-site-menu" data-ri="site-menu">
        <?php foreach ($menuItems as $mi): ?>
          <li class="<?php echo !empty($mi['active']) ? 'active' : ''; ?>" data-ri="menu-li" data-slug="<?php echo $h($mi['slug']); ?>">
            <a href="<?php echo $h($mi['url']); ?>" data-ri="menu-a">
              <span class="txt"><?php echo $h($mi['title']); ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <!-- HAMBURGER MENU BUTTON for mobile -->
    <button class="menu-toggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="dhb-nav-main">
      <span class="hamburger"></span>
    </button>
    <?php endif; ?>

    <?php
    // Printful cart icon — auto-rendered when the module is installed.
    // Badge count syncs from localStorage via global_cart.php (footer).
    if (is_file(SITE_ROOT . '/admin/modules/PrintfulManager/module.json')):
    ?>
    <a href="#" class="pfs-cart-icon" id="dhb-cart-icon" aria-label="Shopping cart" onclick="event.preventDefault();var __ms=document.getElementById('cart-sidebar');if(__ms){__ms.classList.toggle('ms-open');return;}if(window.toggleCart){toggleCart();return;}if(window.pfsToggleCart){pfsToggleCart();}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="9" cy="21" r="1"></circle>
        <circle cx="20" cy="21" r="1"></circle>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
      </svg>
      <span class="pfs-cart-badge empty" id="pfsCartCount">0</span>
    </a>
    <script>
    // Sync the header cart badge to MyStore's localStorage cart (mystore_cart)
    // since the icon now opens MyStore's drawer. Falls back to Printful's
    // (pfs_cart) only when MyStore isn't on the page.
    (function(){
      function readCount(){
        try {
          var ms = JSON.parse(localStorage.getItem('mystore_cart') || '[]');
          if (Array.isArray(ms) && ms.length) {
            return ms.reduce(function(s,i){ return s + (parseInt(i.quantity,10) || 1); }, 0);
          }
          var pf = JSON.parse(localStorage.getItem('pfs_cart') || '[]');
          if (Array.isArray(pf) && pf.length) {
            return pf.reduce(function(s,i){ return s + (parseInt(i.qty,10) || 0); }, 0);
          }
        } catch(e){}
        return 0;
      }
      function paint(){
        var el = document.getElementById('pfsCartCount');
        if (!el) return;
        var n = readCount();
        el.textContent = n;
        el.classList.toggle('empty', n === 0);
      }
      paint();
      // Cross-tab updates
      window.addEventListener('storage', function(e){
        if (!e.key || e.key === 'mystore_cart' || e.key === 'pfs_cart') paint();
      });
      // Same-tab updates — MyStore dispatches this after every cart mutation
      window.addEventListener('mystore:cart-changed', paint);
      // Repaint shortly after load in case MyStore writes the cart on init
      setTimeout(paint, 200);
    })();
    </script>
    <?php endif; ?>

    <?php
    // SHF (CardManager) — search + user account menu, right of cart.
    // $shfViewer is already populated above (where Dashboard menu injection happens).
    // Gated by site-settings#card_manager.public_header_widget so the dev copy on
    // a dev copy doesn't render the public widget.
    if (is_file(SITE_ROOT . '/admin/modules/CardManager/module.json')
        && !empty($ss['card_manager']['public_header_widget'])):
    ?>
    <style>
    .shf-search { position:relative; margin-left:10px; display:flex; align-items:center; }
    .shf-search-input { width:220px; padding:8px 36px 8px 14px; background:rgba(0,0,0,.35); border:1px solid rgba(243,156,18,.25); border-radius:18px; color:#e8dfc8; font:inherit; font-size:.84rem; transition:width .18s, border-color .12s, background .12s; }
    .shf-search-input::placeholder { color:#7a8696; }
    .shf-search-input:focus { outline:none; width:300px; border-color:rgba(243,156,18,.55); background:rgba(0,0,0,.5); }
    .shf-search-icon { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#a8b1bc; pointer-events:none; font-size:.95rem; }
    .shf-search-icon-btn { display:none; background:transparent; border:1px solid rgba(243,156,18,.25); color:#a8b1bc; border-radius:50%; width:34px; height:34px; cursor:pointer; }
    .shf-search-icon-btn:hover { background:rgba(243,156,18,.1); color:#f39c12; }

    .shf-search-results { position:absolute; top:calc(100% + 6px); right:0; width:380px; max-width:90vw; background:#0f1726; border:1px solid rgba(243,156,18,.25); border-radius:10px; padding:6px; box-shadow:0 8px 24px rgba(0,0,0,.55); z-index:1500; max-height:420px; overflow-y:auto; }
    .shf-search-results[hidden] { display:none; }
    .shf-search-group { padding:4px 0; }
    .shf-search-group + .shf-search-group { border-top:1px solid rgba(255,255,255,.05); margin-top:4px; padding-top:8px; }
    .shf-search-group h4 { margin:0 8px 4px; font-size:.66rem; text-transform:uppercase; letter-spacing:.5px; color:#7a8696; font-weight:700; }
    .shf-search-row { display:flex; gap:9px; padding:7px 9px; border-radius:6px; color:#e8dfc8; text-decoration:none; align-items:center; font-size:.86rem; cursor:pointer; }
    .shf-search-row:hover, .shf-search-row.active { background:rgba(243,156,18,.12); color:#f39c12; }
    .shf-search-row .av { width:30px; height:30px; flex-shrink:0; }
    .shf-search-row .thumb { width:24px; height:32px; border-radius:3px; object-fit:cover; flex-shrink:0; background:#112240; }
    .shf-search-row .info { flex:1; min-width:0; }
    .shf-search-row .name { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .shf-search-row .sub { font-size:.72rem; color:#8899aa; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .shf-search-row .price { font-size:.74rem; color:#22c55e; font-weight:700; flex-shrink:0; }
    .shf-search-empty { padding:18px 12px; text-align:center; font-size:.82rem; color:#8899aa; }
    .shf-search-foot { padding:9px 12px; font-size:.74rem; color:#8899aa; border-top:1px solid rgba(255,255,255,.06); }
    .shf-search-foot kbd { background:rgba(255,255,255,.08); padding:1px 6px; border-radius:3px; font-family:inherit; font-size:.76em; }

    @media (max-width: 720px) {
      .shf-search { margin-left:6px; }
      .shf-search-input { display:none; width:160px; padding-right:30px; }
      .shf-search.open .shf-search-input { display:inline-block; width:200px; }
      .shf-search.open .shf-search-icon-btn { display:none; }
      .shf-search-icon-btn { display:inline-flex; align-items:center; justify-content:center; }
      .shf-search-icon { display:none; }
      .shf-search.open .shf-search-icon { display:block; }
      .shf-search-results { right:-8px; width:calc(100vw - 24px); }
    }
    </style>
    <div class="shf-search" id="shfSearch">
      <input class="shf-search-input" type="search" placeholder="Search cards, @traders, sets…" id="shfSearchInput" autocomplete="off" spellcheck="false">
      <span class="shf-search-icon">🔍</span>
      <button class="shf-search-icon-btn" type="button" id="shfSearchToggle" aria-label="Open search">🔍</button>
      <div class="shf-search-results" id="shfSearchResults" hidden></div>
    </div>
    <script>
    (function(){
      const root = document.getElementById('shfSearch');
      if (!root) return;
      const input = document.getElementById('shfSearchInput');
      const results = document.getElementById('shfSearchResults');
      const toggle = document.getElementById('shfSearchToggle');
      const API = '/admin/modules/CardManager/member_api.php';
      let debounceT, lastQ = '', activeIdx = -1;
      const flat = [];

      function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
      function avHtml(u, url){
        const init = String(u || '?').charAt(0).toUpperCase();
        if (url) return `<span class="shf-av shf-av-img av"><img src="${escHtml(url)}" alt=""></span>`;
        return `<span class="shf-av shf-av-initial av">${escHtml(init)}</span>`;
      }

      function paint(d){
        flat.length = 0; activeIdx = -1;
        const r = (d && d.results) || { cards:[], traders:[], sets:[] };
        if (!r.cards.length && !r.traders.length && !r.sets.length) {
          results.innerHTML = '<div class="shf-search-empty">No results for "' + escHtml(d.q) + '".</div>';
          results.hidden = false;
          return;
        }
        let html = '';
        if (r.traders.length) {
          html += '<div class="shf-search-group"><h4>Traders</h4>';
          r.traders.forEach(t => {
            const idx = flat.length; flat.push({ kind:'trader', href:'/trader/' + encodeURIComponent(t.username) });
            html += `<a class="shf-search-row" data-idx="${idx}" href="/trader/${encodeURIComponent(t.username)}">${avHtml(t.username, t.avatar_url)}<div class="info"><div class="name">${escHtml(t.display_name || t.username)}</div><div class="sub">@${escHtml(t.username)}</div></div></a>`;
          });
          html += '</div>';
        }
        if (r.cards.length) {
          html += '<div class="shf-search-group"><h4>Cards</h4>';
          r.cards.forEach(c => {
            const idx = flat.length; flat.push({ kind:'card', href:'/trades?card=' + encodeURIComponent(c.id) });
            const sub = [c.set, c.condition, '@' + c.owner_username].filter(Boolean).join(' · ');
            const price = (c.price && c.price > 0) ? `<span class="price">$${Number(c.price).toFixed(2)}</span>` : '';
            html += `<a class="shf-search-row" data-idx="${idx}" href="/trades?card=${encodeURIComponent(c.id)}"><img class="thumb" src="${escHtml(c.image)}" alt="" onerror="this.style.display='none'"><div class="info"><div class="name">${escHtml(c.name)}</div><div class="sub">${escHtml(sub)}</div></div>${price}</a>`;
          });
          html += '</div>';
        }
        if (r.sets.length) {
          html += '<div class="shf-search-group"><h4>Sets</h4>';
          r.sets.forEach(s => {
            const idx = flat.length; flat.push({ kind:'set', href:'/trades?q=' + encodeURIComponent(s) });
            html += `<a class="shf-search-row" data-idx="${idx}" href="/trades?q=${encodeURIComponent(s)}"><div class="av" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;color:#8899aa">📦</div><div class="info"><div class="name">${escHtml(s)}</div><div class="sub">All cards from this set</div></div></a>`;
          });
          html += '</div>';
        }
        html += `<div class="shf-search-foot">Press <kbd>Enter</kbd> for all results · <kbd>Esc</kbd> to close</div>`;
        results.innerHTML = html;
        results.hidden = false;
      }

      async function doSearch(q){
        if (q === lastQ) return;
        lastQ = q;
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        try {
          const r = await fetch(API + '?action=search&q=' + encodeURIComponent(q));
          const d = await r.json();
          if (lastQ === q) paint(d);
        } catch(e){}
      }

      input.addEventListener('input', () => {
        clearTimeout(debounceT);
        const q = input.value.trim();
        debounceT = setTimeout(() => doSearch(q), 220);
      });
      input.addEventListener('focus', () => { if (input.value.trim().length >= 2) results.hidden = false; });
      input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { input.blur(); results.hidden = true; root.classList.remove('open'); return; }
        if (e.key === 'Enter')  {
          if (activeIdx >= 0 && flat[activeIdx]) { location.href = flat[activeIdx].href; e.preventDefault(); return; }
          const q = input.value.trim();
          if (q) location.href = '/trades?q=' + encodeURIComponent(q);
          e.preventDefault(); return;
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (!flat.length) return;
          activeIdx = e.key === 'ArrowDown'
            ? Math.min(activeIdx + 1, flat.length - 1)
            : Math.max(activeIdx - 1, 0);
          results.querySelectorAll('.shf-search-row').forEach((el, i) => el.classList.toggle('active', i === activeIdx));
          const el = results.querySelector(`[data-idx="${activeIdx}"]`);
          if (el) el.scrollIntoView({ block:'nearest' });
        }
      });

      // Mobile: tap icon to expand
      if (toggle) toggle.addEventListener('click', e => {
        e.stopPropagation();
        root.classList.add('open');
        setTimeout(() => input.focus(), 30);
      });

      document.addEventListener('click', e => {
        if (!root.contains(e.target)) { results.hidden = true; root.classList.remove('open'); }
      });
    })();
    </script>
    <?php /* SHF user menu continues — same CardManager guard */ ?>
    <style>
    .shf-usermenu { position:relative; margin-left:10px; }
    .shf-usermenu-trigger { background:transparent; border:none; padding:0; cursor:pointer; display:flex; align-items:center; gap:6px; }
    .shf-usermenu .shf-av { width:34px; height:34px; font-size:15px; border:2px solid rgba(243,156,18,.4); }
    .shf-usermenu-trigger:hover .shf-av { border-color:#f39c12; }
    .shf-usermenu-avwrap { position:relative; display:inline-flex; }
    .shf-usermenu-msgbadge { position:absolute; top:-4px; right:-4px; min-width:18px; height:18px; padding:0 5px; box-sizing:border-box; background:#c0392b; color:#fff; border:2px solid #0f1726; border-radius:10px; font-size:.62rem; font-weight:800; display:none; align-items:center; justify-content:center; line-height:1; box-shadow:0 0 0 1px rgba(0,0,0,.4); animation:shfMsgPulse 2.4s ease-in-out infinite; }
    .shf-usermenu-msgbadge.show { display:inline-flex; }
    @keyframes shfMsgPulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.12); } }
    .shf-usermenu-panel a .msgct { margin-left:auto; background:#c0392b; color:#fff; border-radius:10px; padding:1px 8px; font-size:.7rem; font-weight:700; display:none; }
    .shf-usermenu-panel a .msgct.show { display:inline-block; }
    .shf-usermenu-caret { color:#a8b1bc; font-size:.7rem; transition:transform .15s; }
    .shf-usermenu.open .shf-usermenu-caret { transform:rotate(180deg); }
    .shf-usermenu-panel { position:absolute; top:calc(100% + 6px); right:0; min-width:200px; background:#0f1726; border:1px solid rgba(243,156,18,.25); border-radius:10px; padding:6px; box-shadow:0 8px 24px rgba(0,0,0,.5); z-index:1500; display:none; }
    .shf-usermenu.open .shf-usermenu-panel { display:block; }
    .shf-usermenu-panel a { display:flex; align-items:center; gap:9px; padding:9px 12px; color:#e8dfc8; text-decoration:none; border-radius:6px; font-size:.86rem; }
    .shf-usermenu-panel a:hover { background:rgba(243,156,18,.1); color:#f39c12; }
    .shf-usermenu-panel .ico { width:18px; text-align:center; opacity:.7; }
    .shf-usermenu-panel .divider { height:1px; background:rgba(255,255,255,.06); margin:4px 0; }
    .shf-usermenu-panel .header { padding:9px 12px 6px; font-size:.74rem; color:#8899aa; }
    .shf-usermenu-panel .header strong { color:#f39c12; font-size:.88rem; display:block; }
    .shf-signin-pill { margin-left:10px; padding:6px 14px; background:transparent; border:1px solid rgba(243,156,18,.35); color:#f39c12; border-radius:18px; text-decoration:none; font-size:.78rem; font-weight:700; }
    .shf-signin-pill:hover { background:rgba(243,156,18,.1); }
    </style>
    <?php if (!empty($shfViewer)): ?>
    <div class="shf-usermenu" id="shfUserMenu">
      <button class="shf-usermenu-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
        <span class="shf-usermenu-avwrap">
          <?php echo shf_avatar_html($shfViewer, 34); ?>
          <span class="shf-usermenu-msgbadge" id="shfMsgBadge" aria-label="Unread messages">0</span>
        </span>
        <span class="shf-usermenu-caret">▾</span>
      </button>
      <div class="shf-usermenu-panel" role="menu">
        <div class="header">
          Signed in as
          <strong>@<?php echo htmlspecialchars($shfViewer['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <div class="divider"></div>
        <a href="/account" role="menuitem"><span class="ico">⚙️</span> Account</a>
        <a href="/trader/<?php echo htmlspecialchars($shfViewer['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" role="menuitem"><span class="ico">🏴‍☠️</span> My Trader Profile</a>
        <a href="/binder" role="menuitem"><span class="ico">📚</span> My Binder</a>
        <a href="/messages" role="menuitem"><span class="ico">💬</span> Messages <span class="msgct" id="shfMsgMenuCount">0</span></a>
        <a href="#" id="shfBundleMenuLink" role="menuitem" style="display:none"><span class="ico">📦</span> My Bundle <span style="margin-left:auto;background:#c0392b;color:#fff;border-radius:10px;padding:1px 8px;font-size:.7rem;font-weight:700" id="shfBundleMenuCount">0</span></a>
        <div class="divider"></div>
        <a href="/logout" role="menuitem"><span class="ico">🚪</span> Sign Out</a>
      </div>
    </div>
    <script>
    (function(){
      const root = document.getElementById('shfUserMenu');
      if (!root) return;
      const trigger = root.querySelector('.shf-usermenu-trigger');
      function close(){ root.classList.remove('open'); trigger.setAttribute('aria-expanded','false'); }
      trigger.addEventListener('click', e => {
        e.stopPropagation();
        const open = root.classList.toggle('open');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', e => { if (!root.contains(e.target)) close(); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

      // Bundle entry — lives in the user menu, surfaces sessionStorage bundle
      function paintBundleEntry(){
        const link  = document.getElementById('shfBundleMenuLink');
        const count = document.getElementById('shfBundleMenuCount');
        if (!link) return;
        let b = null;
        try { b = JSON.parse(sessionStorage.getItem('shf_bundle_v1') || 'null'); } catch(e){}
        if (b && Array.isArray(b.cards) && b.cards.length) {
          count.textContent = b.cards.length;
          link.style.display = 'flex';
        } else {
          link.style.display = 'none';
        }
      }
      const bundleLink = document.getElementById('shfBundleMenuLink');
      if (bundleLink) bundleLink.addEventListener('click', e => {
        e.preventDefault();
        close();
        if (window.shfCardUI && typeof window.shfCardUI.openBundle === 'function') {
          window.shfCardUI.openBundle();
        } else {
          location.href = '/trades';
        }
      });
      paintBundleEntry();
      window.addEventListener('storage', paintBundleEntry);
      // Also repaint when the dropdown opens (sessionStorage doesn't fire on same-tab writes)
      trigger.addEventListener('click', () => setTimeout(paintBundleEntry, 0));

      // Unread-message badge — polls /admin/modules/CardManager/member_api.php?action=unread_count.
      // Hidden when count is 0; pulses red when there's anything new.
      const badge    = document.getElementById('shfMsgBadge');
      const menuCt   = document.getElementById('shfMsgMenuCount');
      let lastCount  = -1;
      function paintMsgBadge(n){
        if (typeof n !== 'number' || n < 0) n = 0;
        const display = n > 99 ? '99+' : String(n);
        if (badge) {
          badge.textContent = display;
          badge.classList.toggle('show', n > 0);
        }
        if (menuCt) {
          menuCt.textContent = display;
          menuCt.classList.toggle('show', n > 0);
        }
      }
      async function refreshMsgBadge(){
        try {
          const r = await fetch('/admin/modules/CardManager/member_api.php?action=unread_count', { credentials:'same-origin' });
          if (!r.ok) return;
          const d = await r.json();
          if (d && d.ok && typeof d.count === 'number') {
            if (d.count !== lastCount) { lastCount = d.count; paintMsgBadge(d.count); }
          }
        } catch(e){}
      }
      refreshMsgBadge();
      setInterval(refreshMsgBadge, 60000);
      window.addEventListener('focus', refreshMsgBadge);
      // Allow other code (e.g. messages page after marking read) to force-refresh
      window.shfRefreshMsgBadge = refreshMsgBadge;
    })();
    </script>
    <?php endif; ?>
    <?php
    // Emit shf_card_ui_once() globally so the bundle chip + lightbox + modals
    // are available on every page (not just shf_* shortcodes). Site-gated:
    // CardManager (trading platform) is an optional add-on module.
    if (is_file(SITE_ROOT . '/admin/modules/CardManager/lib/auth.php')) {
      require_once SITE_ROOT . '/includes/module-renderers.php';
      if (function_exists('shf_card_ui_once')) echo shf_card_ui_once();
    }
    ?>
    <?php if (empty($shfViewer)): ?>
    <a href="/login" class="shf-signin-pill">Sign In</a>
    <?php endif; ?>
    <?php endif; ?>

  </div>
</header>
<!-- DHB:END -->
