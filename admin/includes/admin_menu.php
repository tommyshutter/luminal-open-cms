<?php
/**
 * Luminal CMS - Admin Navigation (Transparent Pill Rail + Widgets)
 * @file     /admin/admin_menu.php
 * @version  2026.02.03.1200.EST
 * @description Admin navigation with role-based menu filtering
 */

$ADMIN_HOST   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 2));

// Include auth functions for role checking
require_once SITE_ROOT . '/admin/auth.php';

// Load module loader for thoht.io-compatible module discovery
require_once SITE_ROOT . '/admin/modules/module_loader.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

// Get current user info for display
$_currentUser = getCurrentUser();
$_userRole = getUserRole();
$_userDisplayName = $_currentUser['display_name'] ?? $_SESSION['user_display_name'] ?? 'Admin';

/* ---- gallery counts (unique JSON slugs) ---- */
function ri_count_json_slugs(array $dirs): int {
  $set = [];
  foreach ($dirs as $root) {
    if (!is_dir($root)) continue;
    try {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $fi) {
        if(!$fi->isFile() || strtolower($fi->getExtension())!=='json') continue;
        $base = basename($fi->getFilename(), '.json');
        if ($base==='index') continue;
        $set[$base] = true;
      }
    } catch (Throwable $e) { /* ignore */ }
  }
  return count($set);
}

$IMG_COUNT = ri_count_json_slugs([ SITE_ROOT.'/admin/data/galleries/images' ]);
$VID_COUNT = ri_count_json_slugs([ SITE_ROOT.'/admin/data/galleries/videos' ]);
$PDF_COUNT = ri_count_json_slugs([ SITE_ROOT.'/admin/data/galleries/pdfs' ]);
$CMB_COUNT = ri_count_json_slugs([ SITE_ROOT.'/admin/data/galleries/combined' ]);
$ALL_GALLERY_COUNT = $IMG_COUNT + $VID_COUNT + $PDF_COUNT + $CMB_COUNT;

/* ---- Addon filesystem detection (auto-hide if files not present) ---- */
$ADDON_DETECT = [
    'PodcastManager'   => SITE_ROOT . '/admin/modules/PodcastManager/',
    'YouTubePlaylist'  => SITE_ROOT . '/admin/modules/YouTubePlaylist/',
    'StatsCaptainOG'   => SITE_ROOT . '/admin/modules/StatsCaptainOG/',
    'Tools'            => SITE_ROOT . '/admin/modules/Tools/',
];

/* ---- MENU PROVIDERS: Load menu definitions from menu modules ---- */
// Menu modules (BaseMenu, ServerMenu) define which modules appear in which sections.
// Only modules listed in a menu provider's menu_sections.json will appear in the menu.
// This replaces the old manifest.json server_only_modules exclusion approach.
$_modulesDir = SITE_ROOT . '/admin/modules';
$MENU_SECTIONS = [];
$_allowedModules = [];  // module_name => section_id
$MENU_TOP = [];

foreach (glob($_modulesDir . '/*/menu_sections.json') as $_mspath) {
    $_msDef = json_decode(file_get_contents($_mspath), true);
    if (!is_array($_msDef)) continue;

    // Top-level modules (e.g., Dashboard)
    foreach ($_msDef['top_level'] ?? [] as $_mod) {
        $_allowedModules[$_mod] = '_top';
    }

    // Collapsible sections
    foreach ($_msDef['sections'] ?? [] as $_sec) {
        $MENU_SECTIONS[] = [
            'id'    => $_sec['id'],
            'title' => $_sec['title'],
            'color' => $_sec['color'],
            'items' => [],
        ];
        foreach ($_sec['modules'] ?? [] as $_mod) {
            $_allowedModules[$_mod] = $_sec['id'];
        }
    }
}

/* ---- MODULE SYSTEM: Get module menu entries and inject into sections ---- */
$MODULE_ENTRIES = lm_get_menu_entries();

foreach ($MODULE_ENTRIES as $entry) {
    $moduleName = $entry['module'] ?? '';
    $sectionId = $_allowedModules[$moduleName] ?? null;

    // Not listed in any menu provider — skip
    if ($sectionId === null) continue;

    // Per-entry section override (self-registration): an individual entry may redirect
    // itself into a different section than its module's central mapping. The allowlist
    // gate above still governs whether the MODULE appears at all — this only reroutes
    // which section an allowed entry lands in. Unknown section id ⇒ falls through the
    // injection loop and is silently dropped (never lost to _top by mistake).
    if (!empty($entry['section'])) {
        $sectionId = $entry['section'];
    }

    if ($sectionId === '_top') {
        $MENU_TOP[] = $entry;
        continue;
    }

    foreach ($MENU_SECTIONS as &$section) {
        if ($section['id'] === $sectionId) {
            $section['items'][] = $entry;
            break;
        }
    }
    unset($section);
}

// Sort top-level items by order field
usort($MENU_TOP, fn($a, $b) => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));

// Sort items within each section by order field
foreach ($MENU_SECTIONS as &$section) {
    usort($section['items'], function($a, $b) {
        return ($a['order'] ?? 50) <=> ($b['order'] ?? 50);
    });
}
unset($section);

// Drop sections whose modules are all absent (e.g. removed via extension_overrides)
$MENU_SECTIONS = array_values(array_filter($MENU_SECTIONS, fn($s) => !empty($s['items'])));

/* ---- Permission filter functions ---- */
function am_addon_installed(string $key): bool {
    global $ADDON_DETECT;
    if (!isset($ADDON_DETECT[$key])) return true; // not an addon — always show
    return file_exists($ADDON_DETECT[$key]);
}

function am_filter_menu_item($item) {
    global $_userRole;
    $key = $item['key'] ?? '';
    if (!am_addon_installed($key)) return false;
    if ($_userRole === 'superadmin') return true;
    // Platform-extension panels are visible to platform admins regardless of CMS role.
    if (!empty($item['platform_extension']) && is_platform_admin()) return true;
    return isMenuItemAllowed($key);
}

function am_filter_section($section) {
    global $_userRole;
    if ($_userRole === 'superadmin') return true;
    return isSectionAllowed($section['id'] ?? '');
}

function am_filter_section_item($item, $sectionId) {
    global $_userRole;
    $key = $item['key'] ?? '';

    // Addon filesystem detection — hide if addon files not present
    if (!am_addon_installed($key)) return false;

    if ($_userRole === 'superadmin') return true;

    // Platform-extension panels are visible to platform admins regardless of CMS role.
    if (!empty($item['platform_extension']) && is_platform_admin()) return true;

    // Check menu item permission
    if (!isMenuItemAllowed($key)) return false;

    // Check for system admin restrictions
    if ($sectionId === 'system-admin' && isSystemAdminItemRestricted($key)) {
        return false;
    }

    return true;
}

// Filter menu items based on user role
$MENU_TOP_FILTERED = array_filter($MENU_TOP, 'am_filter_menu_item');

// Filter sections and their items
$MENU_SECTIONS_FILTERED = [];
foreach ($MENU_SECTIONS as $section) {
    if (!am_filter_section($section)) continue;

    $filteredItems = [];
    foreach ($section['items'] as $item) {
        if (am_filter_section_item($item, $section['id'])) {
            $filteredItems[] = $item;
        }
    }

    // Only include section if it has visible items
    if (!empty($filteredItems)) {
        $section['items'] = $filteredItems;
        $MENU_SECTIONS_FILTERED[] = $section;
    }
}

function am_is_active($key, $currentPage, $currentDir): bool {
  if (!$key) return false;
  // Direct match on filename or directory
  if ($currentPage === $key || $currentDir === $key) return true;
  // Module match: key is module name, currentDir matches
  if ($currentDir === $key || $currentPage === $key . '.php') return true;
  return false;
}

$artistName = htmlspecialchars($_SERVER['HTTP_HOST']  ?? 'Artist', ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="/admin/css/admin_menu.css">
<link rel="stylesheet" href="/admin/css/admin-theme-auria.css">

     <button class="admin-hamburger" id="adminAsideToggle" aria-label="Toggle menu">&#9776;</button>
     <!-- Desktop collapse toggle — fixed to right edge of rail, hidden on mobile -->
     <button class="rail-collapse-btn" id="railCollapseBtn" title="Collapse / expand rail">&#10094;</button>
     <aside class="admin-aside" id="adminAside" aria-label="Admin Navigation">

          <section class="rail-widgets">

                    <?php
                    /* ── Stats stamp / logo identity block ── */
                    $_dsogStamp = SITE_ROOT . '/admin/shared/admin-dsog-stamp.php';
                    if (is_file($_dsogStamp)) include $_dsogStamp;
                    ?>

                    <?php if ($_currentUser):
                      $_initial = strtoupper(substr($_userDisplayName, 0, 1)); ?>
                    <div class="rail-user-block">
                      <div class="rail-avatar"><?= htmlspecialchars($_initial) ?></div>
                      <div class="rail-user-info">
                        <div class="rail-user-name"><?= htmlspecialchars($_userDisplayName) ?></div>
                        <div class="rail-user-role"><?= htmlspecialchars($_userRole) ?></div>
                      </div>
                    </div>
                    <?php endif; ?>

                    <div class="rail-actions">
                      <a class="am-row" href="/admin/logout.php">
                        <span class="am-row-icon">&#128682;</span>
                        <span class="label">Logout</span>
                      </a>
                      <a class="am-row" href="/" target="_blank">
                        <span class="am-row-icon">&#127760;</span>
                        <span class="label">View Site</span>
                      </a>
                    </div>

          </section>

          <!-- Scratch Pad modal (hidden, triggered by rail button) -->
          <div style="display:none" id="railScratchPadHost">
            <?php include SITE_ROOT . '/admin/modules/PageManager/api/page_manager_scratch_pad.php'; ?>
          </div>
          <!-- Tech Support rail link + modal REMOVED 2026-06-08 (Tom): the public
               contact FAB (includes/contact-fab.php, the red ?) covers support intake.
               TechSupport module page remains reachable via its menu section. -->
          <style>
            /* Hide the original widget buttons — we use rail am-row buttons instead */
            #railScratchPadHost .sp-btn,
            #railScratchPadHost > .sp-modal-root > button.sp-btn { display:none !important; }
            /* But show the modals themselves when triggered */
            #railScratchPadHost .sp-modal { display:none; }
            #railScratchPadHost .sp-modal.show { display:flex !important; }
            /* Ensure host divs don't eat layout space — only the modals (fixed) render */
            #railScratchPadHost { display:block !important; height:0; overflow:visible; }
            .admin-aside.collapsed .admin-rail-logo{ display:none; }
          </style>
          <script>
          document.addEventListener('DOMContentLoaded', function(){
            // Wire rail buttons to open the widget modals
            var spBtn = document.getElementById('railScratchPadBtn');
            if (spBtn) {
              spBtn.addEventListener('click', function(){
                // The widget teleports its modal to <body> (escapes the rail's containing-block
                // jail), so find it document-wide rather than inside the now-empty host.
                var modal = document.querySelector('.sp-modal');
                if (modal) modal.classList.add('show');
              });
            }
          });
          </script>

  <!-- MENU (pinned to top by flex order) -->
  <nav class="admin-nav">

              <?php
              /* ---- Render helper ---- */
              function am_render_item($it, $currentPage, $currentDir) {
                $label = (string)($it['label'] ?? '');
                $url   = (string)($it['url'] ?? '#');
                $key   = (string)($it['key'] ?? '');
                $icon  = (string)($it['icon'] ?? '');
                $count = isset($it['count']) ? (int)$it['count'] : null;
                $active = am_is_active($key, $currentPage, $currentDir) ? ' active' : '';
                $color = (string)($it['color'] ?? '');
                $cclass = $color !== '' ? ' am-c-'.preg_replace('/[^a-z0-9-]/', '', strtolower($color)) : '';
                $href  = htmlspecialchars($url ?: '#', ENT_QUOTES);
                $title = htmlspecialchars($label, ENT_QUOTES);
                echo '<a class="am-row'.$active.$cclass.'" href="'.$href.'" title="'.$title.'">';
                if ($icon !== '') echo '<span class="am-row-icon">'.htmlspecialchars($icon, ENT_QUOTES).'</span>';
                echo '<span class="label">'.$title.'</span>';
                if ($count !== null) echo '<span class="count">('.$count.')</span>';
                echo '</a>';
                return !empty($active);
              }

              /* ---- Top-level items (filtered by role) ---- */
              $_spTsInjected = false;
              foreach ($MENU_TOP_FILTERED as $it) {
                am_render_item($it, $currentPage, $currentDir);
                // After Dashboard (order 1), inject Scratch Pad
                // (Tech Support rail button removed 2026-06-08 — red ? contact FAB covers intake)
                if (!$_spTsInjected && ($it['key'] ?? '') === 'Dashboard') {
                  $_spTsInjected = true;
                  echo '<button class="am-row" type="button" id="railScratchPadBtn">';
                  echo '<span class="am-row-icon">&#128221;</span>';
                  echo '<span class="label">Scratch Pad</span>';
                  echo '</button>';
                }
              }
              // Fallback: if Dashboard wasn't in the list, inject at end
              if (!$_spTsInjected) {
                echo '<button class="am-row" type="button" id="railScratchPadBtn"><span class="am-row-icon">&#128221;</span><span class="label">Scratch Pad</span></button>';
              }

              /* ---- Music Agency (sites with /pro/ portal) ---- */
              if (is_dir(SITE_ROOT . '/pro/') && is_file(SITE_ROOT . '/pro/layout.php')) {
                echo '<a class="am-row" href="/pro/" target="_blank"><span class="icon">&#127928;</span><span class="label">Music Agency &#8599;</span></a>';
              }

              /* ---- Master expand/collapse toggle ---- */
              ?>
              <div class="am-nav-toggle">
                <button class="am-nav-toggle-btn" id="amNavToggleBtn" onclick="amToggleAllSections()" title="Expand / Collapse all sections">Collapse All</button>
              </div>
              <?php

              /* ---- Menu sections — flat headings, individually collapsible ---- */
              foreach ($MENU_SECTIONS_FILTERED as $sec):
                if (empty($sec['items'])) continue;
                $secId = htmlspecialchars($sec['id']);
              ?>
                <div class="am-section <?= htmlspecialchars($sec['color']) ?>" data-section="<?= $secId ?>" id="amsec-<?= $secId ?>">
                  <div class="am-section-heading" onclick="amToggleSection('<?= $secId ?>')">
                    <span><?= htmlspecialchars($sec['title']) ?></span>
                    <span class="am-sec-caret">▾</span>
                  </div>
                  <div class="am-section-items">
                    <?php foreach ($sec['items'] as $it) { am_render_item($it, $currentPage, $currentDir); } ?>
                  </div>
                </div>
              <?php endforeach; ?>
              <script>
              (function(){
                var STORE_KEY = 'luminal_sec_collapsed';
                function getCollapsed(){ try { return JSON.parse(localStorage.getItem(STORE_KEY)||'[]'); } catch(e){ return []; } }
                function setCollapsed(arr){ localStorage.setItem(STORE_KEY, JSON.stringify(arr)); }

                function amToggleSection(id) {
                  var el = document.getElementById('amsec-' + id);
                  if (!el) return;
                  var collapsed = getCollapsed();
                  if (el.classList.toggle('sec-collapsed')) {
                    if (!collapsed.includes(id)) collapsed.push(id);
                  } else {
                    collapsed = collapsed.filter(function(x){ return x !== id; });
                  }
                  setCollapsed(collapsed);
                  amUpdateToggleBtn();
                }
                window.amToggleSection = amToggleSection;

                function amToggleAllSections() {
                  var sections = document.querySelectorAll('.am-section[data-section]');
                  var anyExpanded = Array.from(sections).some(function(s){ return !s.classList.contains('sec-collapsed'); });
                  var newCollapsed = [];
                  sections.forEach(function(s){
                    var id = s.getAttribute('data-section');
                    if (anyExpanded) { s.classList.add('sec-collapsed'); newCollapsed.push(id); }
                    else             { s.classList.remove('sec-collapsed'); }
                  });
                  setCollapsed(newCollapsed);
                  amUpdateToggleBtn();
                }
                window.amToggleAllSections = amToggleAllSections;

                function amUpdateToggleBtn() {
                  var btn = document.getElementById('amNavToggleBtn');
                  if (!btn) return;
                  var sections = document.querySelectorAll('.am-section[data-section]');
                  var anyExpanded = Array.from(sections).some(function(s){ return !s.classList.contains('sec-collapsed'); });
                  btn.textContent = anyExpanded ? 'Collapse All' : 'Expand All';
                }

                // Restore collapsed state on load
                var collapsed = getCollapsed();
                collapsed.forEach(function(id){
                  var el = document.getElementById('amsec-' + id);
                  if (el) el.classList.add('sec-collapsed');
                });
                amUpdateToggleBtn();
              })();
              </script>

              <?php
              /* ---- Per-site menu extensions (survives CMS push) ---- */
              $localMenu = SITE_ROOT . '/admin/admin_menu_local.php';
              if (is_file($localMenu)) include $localMenu;
              ?>
            </nav>
          
          
          <?php include SITE_ROOT . '/admin/includes/rail-logo.inc.php'; ?>
          <?php include SITE_ROOT . '/admin/includes/docs-modal.inc.php'; ?>
          
          
          
   <!--/section-->


</aside>
<div class="admin-header-spacer"></div>

<!-- Admin Help ? button — injected here so it appears on ALL admin pages -->
<?php
$_adminHelpFab = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2)) . '/includes/contact-fab.php';
if (is_file($_adminHelpFab)) {
    echo '<style>.contact-fab{background:#dc2626 !important}.contact-fab:hover{background:#ef4444 !important}.contact-modal-header{color:#dc2626 !important}.contact-submit{background:#dc2626 !important}.contact-submit:hover{background:#ef4444 !important}</style>';
    include $_adminHelpFab;
}
?>
<script>
(function(){
  // Auria is the default ("Luminal") look. AdminThemes (html[data-admin-theme])
  // may override it with an OS skin — step aside when a non-default theme is active.
  if ((document.documentElement.getAttribute('data-admin-theme') || 'luminal') === 'luminal') {
    document.body.classList.add('theme-auria');
    document.body.classList.remove('light');
  } else {
    document.body.classList.remove('theme-auria', 'light');
  }

  const aside        = document.getElementById('adminAside');
  const toggle       = document.getElementById('adminAsideToggle');
  const collapseBtn  = document.getElementById('railCollapseBtn');

  function isMobile() { return window.innerWidth <= 960; }

  // Keep page content snug against the rail
  function pushContent(){
    if (isMobile()) {
      document.body.style.marginLeft = '0';
      const ac = document.querySelector('.admin-container');
      if (ac) ac.style.marginLeft = '0';
      return;
    }
    const w = getComputedStyle(document.documentElement).getPropertyValue('--admin-aside-w').trim() || '240px';
    document.body.style.marginLeft = w;
    const ac = document.querySelector('.admin-container');
    if (ac) ac.style.marginLeft = w;
  }
  pushContent();

  const k = 'admin_aside_collapsed';
  const setCollapsed = (yes) => {
    aside.classList.toggle('collapsed', !!yes);
    // Sync the CSS variable so collapse button tracks rail width
    document.documentElement.style.setProperty(
      '--admin-aside-w',
      yes ? 'var(--admin-aside-closed)' : 'var(--admin-aside-open)'
    );
    if (collapseBtn) collapseBtn.innerHTML = yes ? '&#10095;' : '&#10094;';
    localStorage.setItem(k, yes ? '1' : '0');
    pushContent();
  };
  // Restore desktop collapse state on load
  if (!isMobile()) setCollapsed(localStorage.getItem(k) === '1');

  // Hamburger: mobile drawer open/close
  toggle && toggle.addEventListener('click', () => {
    aside.classList.toggle('open');
  });

  // Desktop collapse toggle button inside the rail
  collapseBtn && collapseBtn.addEventListener('click', () => {
    if (!isMobile()) setCollapsed(!aside.classList.contains('collapsed'));
  });

  // Mobile: close drawer when a nav link is clicked
  aside && aside.addEventListener('click', (ev) => {
    if (!isMobile()) return;
    if (ev.target.closest('a.am-row')) {
      aside.classList.remove('open');
    }
  });

  // Mobile: close drawer when tapping outside
  document.addEventListener('click', (ev) => {
    if (!isMobile()) return;
    if (!aside.classList.contains('open')) return;
    if (ev.target.closest('#adminAside') || ev.target.closest('#adminAsideToggle')) return;
    aside.classList.remove('open');
  });

  window.addEventListener('resize', pushContent);
})();
</script>
