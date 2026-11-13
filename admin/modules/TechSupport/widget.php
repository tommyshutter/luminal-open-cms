<?php
/**
 * Tech Support — Client Widget (tri-mode, inline modal)
 * Path: /admin/modules/TechSupport/widget.php
 *
 * Modes:
 *   - INCLUDED (default via include): renders a button + inline modal with tabs:
 *       "New Ticket" form + "My Tickets" history/status view.
 *     Submits to central support server via HTTPS POST with site_key auth.
 *   - DIRECT JSON API:
 *       POST save_local → saves ticket locally
 *       GET  history    → returns local ticket history
 *
 * Auth: direct access requires /admin/auth.php; include relies on caller's auth.
 * Version: 2026.03.07.r2
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/hub.php';
/* ---------- paths ---------- */
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
$TS_LOCAL_DIR  = SITE_ROOT . '/admin/data/support_tickets';
$TS_SELF_WEB   = '/admin/modules/TechSupport/widget.php';

/* ---------- include vs direct ---------- */
$TS_IS_DIRECT = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__));

/* ---------- auth for direct ---------- */
if ($TS_IS_DIRECT) {
  @require_once dirname(__DIR__, 2) . '/auth.php';
  if (function_exists('requireAuth')) { requireAuth(); }
}

/* ---------- helpers ---------- */
if (!function_exists('ts_get_support_server')) {
  function ts_get_support_server(): string {
    $cfgFile = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/data/update-config.json';
    if (is_file($cfgFile)) {
      $cfg = json_decode(file_get_contents($cfgFile), true);
      if (!empty($cfg['deploy_server'])) {
        return rtrim($cfg['deploy_server'], '/');
      }
    }
    return lm_hub_url();
  }
}

if (!function_exists('ts_get_domain')) {
  function ts_get_domain(): string {
    return $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
  }
}

if (!function_exists('ts_get_site_key')) {
  function ts_get_site_key(): string {
    $cfgFile = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/data/update-config.json';
    if (is_file($cfgFile)) {
      $cfg = json_decode(file_get_contents($cfgFile), true);
      if (!empty($cfg['site_key'])) return $cfg['site_key'];
    }
    return '';
  }
}

if (!function_exists('ts_get_cms_version')) {
  function ts_get_cms_version(): string {
    $vf = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/data/cms-version.json';
    if (is_file($vf)) {
      $v = json_decode(file_get_contents($vf), true);
      return $v['commit'] ?? $v['version'] ?? 'unknown';
    }
    return 'unknown';
  }
}

if (!function_exists('ts_get_current_user_info')) {
  function ts_get_current_user_info(): array {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return [
      'username'     => $_SESSION['username'] ?? $_SESSION['user'] ?? 'unknown',
      'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'unknown',
      'role'         => $_SESSION['role'] ?? 'user',
    ];
  }
}

if (!function_exists('ts_get_installed_modules')) {
  function ts_get_installed_modules(): array {
    if (function_exists('lm_discover_modules')) {
      $mods = lm_discover_modules();
      $names = array_keys($mods);
      sort($names);
      return $names;
    }
    $dir = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/modules';
    $names = [];
    foreach (glob($dir . '/*/module.json') as $mf) {
      $m = json_decode(file_get_contents($mf), true);
      if (!empty($m['name'])) $names[] = $m['name'];
    }
    sort($names);
    return $names;
  }
}

if (!function_exists('ts_save_local')) {
  function ts_save_local(string $dir, array $ticket): bool {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . $ticket['id'] . '.json';
    $json = json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return (bool)@file_put_contents($file, $json, LOCK_EX);
  }
}

/* =======================================================================
 * 1) INCLUDED: Button + inline modal (tabs: New Ticket / My Tickets)
 * ======================================================================= */
if (!$TS_IS_DIRECT) {
  $tsUid       = substr(md5(__FILE__ . microtime(true)), 0, 8);
  $TS_BTN_ID   = "tsBtn_$tsUid";
  $TS_MOD_ID   = "tsModal_$tsUid";
  $TS_ROOT_ID  = "tsRoot_$tsUid";
  $TS_FORM_ID  = "tsForm_$tsUid";
  $TS_STAT_ID  = "tsStat_$tsUid";
  $TS_THEME_ID = "tsTheme_$tsUid";

  $tsModules     = ts_get_installed_modules();
  $tsDomain      = ts_get_domain();
  $tsUser        = ts_get_current_user_info();
  $tsCmsVersion  = ts_get_cms_version();
  $tsSupportUrl  = ts_get_support_server();
  $tsSiteKey     = ts_get_site_key();

  $tsStaticAreas = ['Dashboard', 'Login / Auth', 'Frontend (site)', 'Deployment / Updates'];
  $tsSkipModules = ['TechSupport', 'MediaThumbs', 'ExportMask', 'Tools'];
  $tsAreaModules = array_filter($tsModules, fn($m) => !in_array($m, $tsSkipModules));
  ?>
  <style>
    /* ── Tech Support widget ── */
    .ts-modal-root{ --ts-bg:#0b1216; --ts-bg2:#0f171c; --ts-fg:#e6eef6; --ts-muted:#9fb2c2;
      --ts-line:#23343e; --ts-accent:#a78bfa; --ts-accent2:#60a5fa;
      --ts-green:#34d399; --ts-yellow:#fbbf24; --ts-orange:#fb923c; --ts-red:#f87171; }
    .ts-modal-root.ts-light{ --ts-bg:#f7f9fc; --ts-bg2:#ffffff; --ts-fg:#0b1216; --ts-muted:#46535e;
      --ts-line:#d8e0e6; --ts-accent:#7c3aed; --ts-accent2:#2b5fd6;
      --ts-green:#059669; --ts-yellow:#d97706; --ts-orange:#ea580c; --ts-red:#dc2626; }

    .ts-btn{appearance:none;border:1px solid rgba(167,139,250,.45);
      background:linear-gradient(135deg,#3b2070,#2a1a5e);color:#ddd0ff;
      padding:9px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;
      font-family:system-ui,-apple-system,sans-serif;transition:all .15s;width:100%;
      text-align:center;margin-top:6px;letter-spacing:.3px}
    .ts-btn:hover{background:linear-gradient(135deg,#4c2d8a,#3b2070);border-color:rgba(167,139,250,.7)}

    .ts-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:9998}
    .ts-modal.show{display:flex}
    .ts-modal .ts-overlay{position:absolute;inset:0;background:rgba(0,0,0,.65)}
    .ts-modal .ts-dialog{
      position:relative;width:min(720px,96vw);max-height:min(90vh,96vh);
      background:var(--ts-bg2);border:1px solid var(--ts-line);border-radius:14px;
      box-shadow:0 20px 60px rgba(0,0,0,.55);display:flex;flex-direction:column;overflow:hidden}

    .ts-modal header{background:var(--ts-bg);border-bottom:1px solid var(--ts-line);padding:12px 16px;
      display:flex;align-items:center;gap:10px;justify-content:space-between}
    .ts-modal header h3{margin:0;font:600 15px/1.2 system-ui,-apple-system,sans-serif;
      color:var(--ts-accent);letter-spacing:.5px}
    .ts-head-actions{display:flex;gap:8px;align-items:center}
    .ts-switch{display:inline-flex;align-items:center;gap:6px;font:12px system-ui;color:var(--ts-muted)}
    .ts-switch input{appearance:none;width:38px;height:20px;border-radius:999px;background:#34424d;
      position:relative;outline:none;cursor:pointer;border:1px solid var(--ts-line)}
    .ts-switch input:after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;
      border-radius:50%;background:#dfe8ef;transition:transform .18s ease}
    .ts-modal-root.ts-light .ts-switch input{background:#b8c4cf}
    .ts-switch input:checked:after{transform:translateX(18px)}
    .ts-close{appearance:none;background:transparent;color:#cbd6e2;border:0;font-size:18px;
      line-height:1;cursor:pointer;padding:4px 6px}

    /* ── Tabs ── */
    .ts-tabs{display:flex;background:var(--ts-bg);border-bottom:1px solid var(--ts-line);padding:0 16px}
    .ts-tab{appearance:none;background:transparent;border:0;border-bottom:2px solid transparent;
      color:var(--ts-muted);padding:10px 16px;font:600 13px system-ui;cursor:pointer;transition:all .15s}
    .ts-tab:hover{color:var(--ts-fg)}
    .ts-tab.active{color:var(--ts-accent);border-bottom-color:var(--ts-accent)}
    .ts-tab-badge{display:inline-block;background:var(--ts-accent);color:#fff;font-size:10px;font-weight:700;
      padding:1px 6px;border-radius:10px;margin-left:6px;min-width:16px;text-align:center;vertical-align:middle}

    /* ── Tab Panels ── */
    .ts-panel{display:none;flex:1;overflow-y:auto;background:var(--ts-bg)}
    .ts-panel.active{display:block}
    .ts-panel-form{padding:20px 24px}
    .ts-panel-form::-webkit-scrollbar,.ts-panel-tickets::-webkit-scrollbar{width:6px}
    .ts-panel-form::-webkit-scrollbar-thumb,.ts-panel-tickets::-webkit-scrollbar-thumb{background:#2a3a48;border-radius:3px}

    .ts-section{margin-bottom:20px}
    .ts-section-label{display:block;font:600 12px/1 system-ui;color:var(--ts-accent);
      text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px}

    /* radios */
    .ts-radio-group{display:flex;flex-wrap:wrap;gap:8px}
    .ts-radio{display:none}
    .ts-radio-label{display:inline-block;padding:7px 14px;border:1px solid var(--ts-line);
      border-radius:8px;font-size:13px;color:var(--ts-muted);cursor:pointer;transition:all .15s;user-select:none}
    .ts-radio:checked + .ts-radio-label{background:var(--ts-accent);color:#fff;border-color:var(--ts-accent)}
    /* panic button */
    .ts-panic-btn{appearance:none;width:100%;box-sizing:border-box;padding:12px 16px;margin-bottom:20px;
      border:2px solid var(--ts-red);border-radius:10px;background:rgba(248,113,113,.08);
      color:var(--ts-red);font:700 14px/1.3 system-ui,-apple-system,sans-serif;cursor:pointer;
      text-align:center;transition:all .15s;letter-spacing:.3px}
    .ts-panic-btn:hover{background:rgba(248,113,113,.18);border-color:#ef4444}
    .ts-panic-btn.ts-panic-active{background:var(--ts-red);color:#fff;border-color:var(--ts-red)}
    .ts-panic-btn .ts-panic-icon{font-size:16px;margin-right:6px}
    .ts-panic-btn .ts-panic-sub{display:block;font-size:11px;font-weight:400;margin-top:2px;opacity:.8}

    /* checkboxes (legacy) */
    .ts-check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px}
    .ts-check{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--ts-muted);cursor:pointer}
    .ts-check input{accent-color:var(--ts-accent);width:15px;height:15px;cursor:pointer}

    /* multi-select pulldown */
    .ts-multiselect{position:relative}
    .ts-multiselect-btn{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--ts-line);
      border-radius:8px;background:var(--ts-bg);color:var(--ts-muted);font:14px/1.4 system-ui;
      cursor:pointer;text-align:left;display:flex;align-items:center;justify-content:space-between}
    .ts-multiselect-btn:hover{border-color:var(--ts-accent)}
    .ts-multiselect-btn .ts-ms-arrow{font-size:10px;opacity:.5}
    .ts-multiselect-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
      background:var(--ts-bg2);border:1px solid var(--ts-line);border-radius:8px;z-index:20;
      max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.4)}
    .ts-multiselect.open .ts-multiselect-drop{display:block}
    .ts-multiselect-drop label{display:flex;align-items:center;gap:8px;padding:7px 12px;
      font-size:13px;color:var(--ts-fg);cursor:pointer;transition:background .1s}
    .ts-multiselect-drop label:hover{background:rgba(167,139,250,.08)}
    .ts-multiselect-drop input{accent-color:var(--ts-accent);width:15px;height:15px;cursor:pointer}
    .ts-multiselect-drop .ts-ms-group{padding:6px 12px 2px;font:600 10px system-ui;
      color:var(--ts-accent);text-transform:uppercase;letter-spacing:1px}
    .ts-selected-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
    .ts-selected-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;
      font-size:11px;background:rgba(167,139,250,.12);color:var(--ts-accent)}
    .ts-selected-tag .ts-tag-x{cursor:pointer;opacity:.6;font-size:13px}
    .ts-selected-tag .ts-tag-x:hover{opacity:1}

    /* inputs */
    .ts-input,.ts-textarea{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--ts-line);
      border-radius:8px;background:var(--ts-bg);color:var(--ts-fg);font:14px/1.4 system-ui;outline:none}
    .ts-input:focus,.ts-textarea:focus{border-color:var(--ts-accent)}
    .ts-textarea{min-height:100px;resize:vertical;font-family:inherit}

    /* screenshot */
    .ts-drop-zone{border:2px dashed var(--ts-line);border-radius:10px;padding:16px;text-align:center;
      color:var(--ts-muted);font-size:13px;cursor:pointer;transition:border-color .15s}
    .ts-drop-zone:hover,.ts-drop-zone.dragover{border-color:var(--ts-accent)}
    .ts-drop-zone input{display:none}
    .ts-drop-preview{max-width:200px;max-height:120px;margin:8px auto 0;border-radius:6px;display:none}

    /* divider */
    .ts-divider{border:0;border-top:1px solid var(--ts-line);margin:0 0 20px}

    /* footer */
    .ts-modal footer{border-top:1px solid var(--ts-line);background:var(--ts-bg2);padding:10px 16px;
      display:flex;align-items:center;justify-content:space-between;color:var(--ts-muted);font-size:12px}
    .ts-footer-meta{display:flex;gap:12px;align-items:center;font-size:11px;color:var(--ts-muted);opacity:.7}
    .ts-submit{appearance:none;border:1px solid var(--ts-accent);background:var(--ts-accent);color:#fff;
      padding:8px 20px;border-radius:8px;cursor:pointer;font:600 13px system-ui;transition:all .15s}
    .ts-submit:hover{background:#9061f0}
    .ts-submit:disabled{opacity:.5;cursor:not-allowed}

    /* ── My Tickets panel ── */
    .ts-panel-tickets{padding:16px 20px}
    .ts-ticket-list{list-style:none;padding:0;margin:0}
    .ts-ticket-item{background:var(--ts-bg2);border:1px solid var(--ts-line);border-radius:10px;
      padding:14px 16px;margin-bottom:10px;cursor:pointer;transition:border-color .15s}
    .ts-ticket-item:hover{border-color:var(--ts-accent)}
    .ts-ticket-item.expanded{border-color:var(--ts-accent)}
    .ts-ticket-row{display:flex;align-items:center;gap:10px}
    .ts-ticket-urg{width:8px;height:8px;border-radius:50%;flex-shrink:0}
    .ts-ticket-urg.low{background:var(--ts-green)}
    .ts-ticket-urg.medium{background:var(--ts-yellow)}
    .ts-ticket-urg.high{background:var(--ts-orange)}
    .ts-ticket-urg.critical{background:var(--ts-red);box-shadow:0 0 6px var(--ts-red)}
    .ts-ticket-subject{flex:1;font:500 14px system-ui;color:var(--ts-fg);overflow:hidden;
      text-overflow:ellipsis;white-space:nowrap}
    .ts-ticket-status{font:600 11px system-ui;padding:3px 10px;border-radius:6px;text-transform:uppercase;
      letter-spacing:.5px;flex-shrink:0}
    .ts-ticket-status.open{background:rgba(96,165,250,.15);color:var(--ts-accent2)}
    .ts-ticket-status.in_progress{background:rgba(251,191,36,.15);color:var(--ts-yellow)}
    .ts-ticket-status.awaiting_feedback{background:rgba(167,139,250,.18);color:#a78bfa}
    .ts-ticket-status.resolved{background:rgba(52,211,153,.15);color:var(--ts-green)}
    .ts-ticket-status.submitted{background:rgba(167,139,250,.15);color:var(--ts-accent)}
    .ts-ticket-status.closed,.ts-ticket-status.wont_fix{background:rgba(123,143,163,.1);color:var(--ts-muted)}
    .ts-ticket-age{font-size:11px;color:var(--ts-muted);flex-shrink:0;min-width:30px;text-align:right}

    .ts-ticket-detail{display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--ts-line)}
    .ts-ticket-item.expanded .ts-ticket-detail{display:block}
    .ts-ticket-detail-id{font:11px ui-monospace,monospace;color:var(--ts-muted);margin-bottom:6px}
    .ts-ticket-detail-cat{font-size:12px;color:var(--ts-muted);margin-bottom:4px}
    .ts-ticket-detail-areas{font-size:12px;color:var(--ts-muted);margin-bottom:8px}
    .ts-ticket-detail-desc{font:13px/1.5 system-ui;color:var(--ts-fg);white-space:pre-wrap;word-break:break-word;
      background:var(--ts-bg);padding:10px 12px;border-radius:8px;border:1px solid var(--ts-line);margin-bottom:10px}
    .ts-ticket-detail-resolution{background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.2);
      border-radius:8px;padding:10px 12px;margin-bottom:10px}
    .ts-ticket-detail-resolution h5{margin:0 0 4px;font:600 11px system-ui;color:var(--ts-green);
      text-transform:uppercase;letter-spacing:1px}
    .ts-ticket-detail-resolution p{margin:0;font:13px/1.5 system-ui;color:var(--ts-fg)}
    .ts-resolved-by{margin-top:6px;padding-top:6px;border-top:1px solid rgba(52,211,153,.15);
      font:11px system-ui;color:var(--ts-green)}

    /* notes in ticket detail */
    .ts-ticket-notes{margin-top:10px}
    .ts-ticket-notes h5{font:600 11px system-ui;color:var(--ts-accent);text-transform:uppercase;
      letter-spacing:1px;margin:0 0 8px}
    .ts-ticket-note{background:var(--ts-bg);border:1px solid var(--ts-line);border-radius:8px;
      padding:8px 10px;margin-bottom:6px;font-size:13px;color:var(--ts-fg)}
    .ts-ticket-note-meta{font-size:10px;color:var(--ts-muted);margin-top:3px}

    .ts-empty{text-align:center;color:var(--ts-muted);padding:40px 20px;font-size:14px}
    .ts-loading{text-align:center;color:var(--ts-muted);padding:40px 20px;font-size:13px}
    .ts-refresh-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .ts-refresh-btn{appearance:none;border:1px solid var(--ts-line);background:var(--ts-bg2);
      color:var(--ts-accent2);padding:5px 12px;border-radius:6px;cursor:pointer;font:12px system-ui}
    .ts-refresh-btn:hover{background:var(--ts-bg)}
    .ts-refresh-time{font-size:11px;color:var(--ts-muted)}

    /* ── Form Sub-Tabs ── */
    .ts-form-tabs{display:flex;gap:0;border-bottom:1px solid var(--ts-line);margin-bottom:20px}
    .ts-form-tab{appearance:none;background:transparent;border:0;border-bottom:2px solid transparent;
      color:var(--ts-muted);padding:9px 14px;font:600 11px/1.2 system-ui,-apple-system,sans-serif;
      cursor:pointer;transition:all .15s;white-space:nowrap;text-transform:uppercase;letter-spacing:.8px}
    .ts-form-tab:hover{color:var(--ts-fg)}
    .ts-form-tab.active{color:var(--ts-accent);border-bottom-color:var(--ts-accent)}
    .ts-form-panel{display:none}
    .ts-form-panel.active{display:block}
    .ts-pc-site-display{padding:9px 12px;border:1px solid var(--ts-line);border-radius:8px;
      background:var(--ts-bg);color:var(--ts-fg);font-size:14px;opacity:.8}

    /* urgency row hidden — auto-determined now */
  </style>

  <div class="ts-modal-root" id="<?= htmlspecialchars($TS_ROOT_ID, ENT_QUOTES) ?>">
    <button class="ts-btn" id="<?= htmlspecialchars($TS_BTN_ID, ENT_QUOTES) ?>" type="button">Tech Support</button>

    <div class="ts-modal" id="<?= htmlspecialchars($TS_MOD_ID, ENT_QUOTES) ?>">
      <div class="ts-overlay" data-ts-close="1"></div>
      <div class="ts-dialog">
        <header>
          <h3>Tech Support</h3>
          <div class="ts-head-actions">
            <label class="ts-switch" title="Toggle light theme">
              <span>Dark</span>
              <input type="checkbox" id="<?= htmlspecialchars($TS_THEME_ID, ENT_QUOTES) ?>">
              <span>Light</span>
            </label>
            <button class="ts-close" type="button" data-ts-close="1">&times;</button>
          </div>
        </header>

        <!-- ── Tab Bar ── -->
        <div class="ts-tabs">
          <button class="ts-tab active" type="button" data-ts-tab="new" id="tsTabNew_<?= $tsUid ?>">New Ticket</button>
          <button class="ts-tab" type="button" data-ts-tab="mine" id="tsTabMine_<?= $tsUid ?>">My Tickets <span class="ts-tab-badge" id="tsTicketCount_<?= $tsUid ?>" style="display:none">0</span></button>
        </div>

        <!-- ── Panel: New Ticket ── -->
        <div class="ts-panel ts-panel-form active" id="tsPanelNew_<?= $tsUid ?>">
          <form id="<?= htmlspecialchars($TS_FORM_ID, ENT_QUOTES) ?>" autocomplete="off">

            <!-- ── Emergency Panic Button ── -->
            <button type="button" class="ts-panic-btn" id="tsPanic_<?= $tsUid ?>">
              <span class="ts-panic-icon">&#9888;</span> EMERGENCY: Site Down / SSL Broken
              <span class="ts-panic-sub">Click to report a critical outage</span>
            </button>

            <!-- ── Form Sub-Tabs ── -->
            <div class="ts-form-tabs" id="tsFormTabs_<?= $tsUid ?>">
              <button type="button" class="ts-form-tab active" data-ftab="report_issue">Report Issue</button>
              <button type="button" class="ts-form-tab" data-ftab="page_content">Page / Content</button>
              <button type="button" class="ts-form-tab" data-ftab="feature_module">Feature / Module</button>
              <button type="button" class="ts-form-tab" data-ftab="question">Question</button>
            </div>
            <input type="hidden" name="ts_category" id="tsCatHidden_<?= $tsUid ?>" value="ui_issue">

            <!-- ── Panel: Report Issue ── -->
            <div class="ts-form-panel active" data-fpanel="report_issue">
              <div class="ts-section">
                <span class="ts-section-label">Issue Type</span>
                <div class="ts-radio-group">
                  <span><input class="ts-radio" type="radio" name="ts_ri_type" id="tsRI1_<?= $tsUid ?>" value="ui_issue" checked>
                    <label class="ts-radio-label" for="tsRI1_<?= $tsUid ?>">UI Issue</label></span>
                  <span><input class="ts-radio" type="radio" name="ts_ri_type" id="tsRI2_<?= $tsUid ?>" value="something_is_broken">
                    <label class="ts-radio-label" for="tsRI2_<?= $tsUid ?>">Something Is Broken</label></span>
                </div>
              </div>
            </div>

            <!-- ── Panel: Page / Content ── -->
            <div class="ts-form-panel" data-fpanel="page_content">
              <div class="ts-section">
                <span class="ts-section-label">Site</span>
                <div class="ts-pc-site-display"><?= htmlspecialchars($tsDomain) ?></div>
                <input type="hidden" name="ts_pc_site" value="<?= htmlspecialchars($tsDomain) ?>">
              </div>

              <div class="ts-section">
                <span class="ts-section-label">Page</span>
                <select class="ts-input" name="ts_pc_page" id="tsPageSel_<?= $tsUid ?>">
                  <option value="">-- Select a page --</option>
                </select>
              </div>

              <div class="ts-section">
                <span class="ts-section-label">Change Request</span>
                <div class="ts-multiselect" id="tsChangeSelect_<?= $tsUid ?>">
                  <button type="button" class="ts-multiselect-btn">
                    <span class="ts-ms-label">Select change types...</span>
                    <span class="ts-ms-arrow">&#9660;</span>
                  </button>
                  <div class="ts-multiselect-drop">
                    <label><input type="checkbox" name="ts_pc_types[]" value="text_edit"> Text Edit</label>
                    <label><input type="checkbox" name="ts_pc_types[]" value="graphics"> Graphics</label>
                    <label><input type="checkbox" name="ts_pc_types[]" value="theme"> Theme</label>
                    <label><input type="checkbox" name="ts_pc_types[]" value="colors"> Colors</label>
                    <label><input type="checkbox" name="ts_pc_types[]" value="feels_moods"> Feels and Moods</label>
                  </div>
                  <div class="ts-selected-tags" id="tsChangeTags_<?= $tsUid ?>"></div>
                </div>
              </div>
            </div>

            <!-- ── Panel: Feature / Module ── -->
            <div class="ts-form-panel" data-fpanel="feature_module">
              <div class="ts-section">
                <span class="ts-section-label">Request Type</span>
                <div class="ts-radio-group">
                  <span><input class="ts-radio" type="radio" name="ts_fm_type" id="tsFM1_<?= $tsUid ?>" value="feature_request" checked>
                    <label class="ts-radio-label" for="tsFM1_<?= $tsUid ?>">Feature Request</label></span>
                  <span><input class="ts-radio" type="radio" name="ts_fm_type" id="tsFM2_<?= $tsUid ?>" value="ui_request">
                    <label class="ts-radio-label" for="tsFM2_<?= $tsUid ?>">UI Enhancement</label></span>
                  <span><input class="ts-radio" type="radio" name="ts_fm_type" id="tsFM3_<?= $tsUid ?>" value="module_request">
                    <label class="ts-radio-label" for="tsFM3_<?= $tsUid ?>">New Module</label></span>
                </div>
              </div>

              <!-- Module details captured in description field -->
            </div>

            <!-- ── Panel: Question ── -->
            <div class="ts-form-panel" data-fpanel="question">
              <p style="color:var(--ts-muted);font-size:13px;margin:0 0 10px">Just describe your question below &mdash; we&rsquo;ll get back to you.</p>
            </div>

            <!-- ── Shared fields ── -->
            <div class="ts-section">
              <span class="ts-section-label">What happened?</span>
              <textarea class="ts-textarea" name="ts_description" id="tsDesc_<?= $tsUid ?>" placeholder="What were you doing? What's not working? Describe it in your own words..." style="min-height:180px;font-size:14px"></textarea>
            </div>

            <!-- Triage mode: always AI (hidden) -->
            <input type="hidden" name="ts_triage_mode" id="tsTriageMode_<?= $tsUid ?>" value="ai">
            <!-- Urgency: auto-determined (hidden, set by panic button or defaults to low) -->
            <input type="hidden" name="ts_urgency" id="tsUrgHidden_<?= $tsUid ?>" value="low">

            <!-- ── Screenshot ── -->
            <div class="ts-section">
              <div class="ts-drop-zone" id="tsDrop_<?= $tsUid ?>" style="padding:10px;font-size:12px">
                <input type="file" accept="image/*" id="tsFile_<?= $tsUid ?>">
                Screenshot (optional) &mdash; click or drag
                <img class="ts-drop-preview" id="tsPreview_<?= $tsUid ?>" alt="Preview">
              </div>
            </div>

          </form>
        </div>

        <!-- ── Panel: My Tickets ── -->
        <div class="ts-panel ts-panel-tickets" id="tsPanelMine_<?= $tsUid ?>">
          <div class="ts-refresh-bar">
            <span class="ts-refresh-time" id="tsRefreshTime_<?= $tsUid ?>"></span>
            <button class="ts-refresh-btn" id="tsRefreshBtn_<?= $tsUid ?>" type="button">Refresh Status</button>
          </div>
          <ul class="ts-ticket-list" id="tsTicketList_<?= $tsUid ?>">
            <li class="ts-loading">Loading tickets...</li>
          </ul>
        </div>

        <footer>
          <div class="ts-footer-meta">
            <span><?= htmlspecialchars($tsDomain) ?></span>
            <span><?= htmlspecialchars($tsUser['display_name']) ?></span>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <span id="<?= htmlspecialchars($TS_STAT_ID, ENT_QUOTES) ?>" style="font-size:12px"></span>
            <button class="ts-submit" type="button" id="tsSubmit_<?= $tsUid ?>">Submit Ticket</button>
          </div>
        </footer>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const ROOT      = document.getElementById(<?= json_encode($TS_ROOT_ID) ?>);
    const BTN       = document.getElementById(<?= json_encode($TS_BTN_ID) ?>);
    const MOD       = document.getElementById(<?= json_encode($TS_MOD_ID) ?>);
    const FORM      = document.getElementById(<?= json_encode($TS_FORM_ID) ?>);
    const STAT      = document.getElementById(<?= json_encode($TS_STAT_ID) ?>);
    const THEME     = document.getElementById(<?= json_encode($TS_THEME_ID) ?>);
    const SUBMIT    = document.getElementById(<?= json_encode("tsSubmit_$tsUid") ?>);
    const DROP      = document.getElementById(<?= json_encode("tsDrop_$tsUid") ?>);
    const FFILE     = document.getElementById(<?= json_encode("tsFile_$tsUid") ?>);
    const PREV      = document.getElementById(<?= json_encode("tsPreview_$tsUid") ?>);
    const TRIAGE_MODE   = document.getElementById(<?= json_encode("tsTriageMode_$tsUid") ?>);
    const URG_HIDDEN    = document.getElementById(<?= json_encode("tsUrgHidden_$tsUid") ?>);
    const PANIC_BTN     = document.getElementById(<?= json_encode("tsPanic_$tsUid") ?>);

    // Main Tabs
    const TAB_NEW   = document.getElementById(<?= json_encode("tsTabNew_$tsUid") ?>);
    const TAB_MINE  = document.getElementById(<?= json_encode("tsTabMine_$tsUid") ?>);
    const PANEL_NEW = document.getElementById(<?= json_encode("tsPanelNew_$tsUid") ?>);
    const PANEL_MINE= document.getElementById(<?= json_encode("tsPanelMine_$tsUid") ?>);
    const BADGE     = document.getElementById(<?= json_encode("tsTicketCount_$tsUid") ?>);
    const TLIST     = document.getElementById(<?= json_encode("tsTicketList_$tsUid") ?>);
    const REFRESH_B = document.getElementById(<?= json_encode("tsRefreshBtn_$tsUid") ?>);
    const REFRESH_T = document.getElementById(<?= json_encode("tsRefreshTime_$tsUid") ?>);

    // Form sub-tabs
    const CAT_HIDDEN = document.getElementById(<?= json_encode("tsCatHidden_$tsUid") ?>);
    const PAGE_SEL   = document.getElementById(<?= json_encode("tsPageSel_$tsUid") ?>);
    const DESC_EL    = document.getElementById(<?= json_encode("tsDesc_$tsUid") ?>);
    const FORM_TABS  = FORM.querySelectorAll('.ts-form-tab');
    const FORM_PANELS= FORM.querySelectorAll('.ts-form-panel');

    const SUPPORT_URL = <?= json_encode($tsSupportUrl) ?>;
    const DOMAIN      = <?= json_encode($tsDomain) ?>;
    const SITE_KEY    = <?= json_encode($tsSiteKey) ?>;
    const USER        = <?= json_encode($tsUser) ?>;
    const CMS_VER     = <?= json_encode($tsCmsVersion) ?>;
    const LOCAL_EP    = <?= json_encode($TS_SELF_WEB) ?>;

    const CAT_LABELS = {ui_issue:'UI Issue',ui_request:'UI Request',something_is_broken:'Broken',
      edit_page_html_css:'Page Edit',page_content:'Page / Content',
      feature_request:'Feature Req',module_request:'Module Req',question:'Question'};

    let screenshotB64 = null;
    let activeTab = 'new';
    let cachedTickets = [];

    /* ── helpers ── */
    function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
    function timeAgo(d){ if(!d)return'-'; const m=Math.floor((Date.now()-new Date(d).getTime())/60000);
      if(m<1)return'now'; if(m<60)return m+'m'; const h=Math.floor(m/60);
      if(h<24)return h+'h'; const dy=Math.floor(h/24); return dy+'d'; }

    /* ── theme ── */
    const tkey='ri_ts_theme';
    function applyTheme(v){ROOT.classList.toggle('ts-light',v==='light');if(THEME)THEME.checked=(v==='light');}
    applyTheme(localStorage.getItem(tkey)||'dark');
    if(THEME) THEME.addEventListener('change',()=>{const v=THEME.checked?'light':'dark';localStorage.setItem(tkey,v);applyTheme(v);});

    function toast(level,msg){try{if(window.adminToaster&&adminToaster.push)adminToaster.push({level,msg});}catch(e){}}

    /* ── panic button ── */
    let panicActive = false;
    if(PANIC_BTN){
      PANIC_BTN.addEventListener('click', () => {
        panicActive = !panicActive;
        PANIC_BTN.classList.toggle('ts-panic-active', panicActive);
        if(panicActive){
          // Switch to Report Issue tab, set to "Something Is Broken"
          switchFormTab('report_issue');
          const brokenRadio = FORM.querySelector('input[name="ts_ri_type"][value="something_is_broken"]');
          if(brokenRadio) brokenRadio.checked = true;
          CAT_HIDDEN.value = 'something_is_broken';
          // Set urgency to critical
          if(URG_HIDDEN) URG_HIDDEN.value = 'critical';
          // Pre-fill description
          if(DESC_EL && !DESC_EL.value.trim()){
            DESC_EL.value = 'EMERGENCY: ';
            DESC_EL.focus();
            DESC_EL.setSelectionRange(DESC_EL.value.length, DESC_EL.value.length);
          } else if(DESC_EL){
            DESC_EL.focus();
          }
          PANIC_BTN.querySelector('.ts-panic-sub').textContent = 'ACTIVE \u2014 urgency set to critical. Describe the issue below.';
        } else {
          // Deactivate — reset urgency
          if(URG_HIDDEN) URG_HIDDEN.value = 'low';
          PANIC_BTN.querySelector('.ts-panic-sub').textContent = 'Click to report a critical outage';
        }
      });
    }

    /* ── tabs ── */
    function switchTab(tab){
      activeTab = tab;
      TAB_NEW.classList.toggle('active', tab==='new');
      TAB_MINE.classList.toggle('active', tab==='mine');
      PANEL_NEW.classList.toggle('active', tab==='new');
      PANEL_MINE.classList.toggle('active', tab==='mine');
      // Show submit button only on new ticket tab
      SUBMIT.style.display = tab==='new' ? '' : 'none';
      if(tab==='mine') loadMyTickets();
    }
    TAB_NEW.addEventListener('click', ()=>switchTab('new'));
    TAB_MINE.addEventListener('click', ()=>switchTab('mine'));

    /* ── open/close ── */
    function openModal(){
      MOD.classList.add('show');
      try{document.documentElement.style.overflow='hidden';}catch(e){}
    }
    function closeModal(){
      MOD.classList.remove('show');
      try{document.documentElement.style.overflow='';}catch(e){}
    }
    BTN.addEventListener('click', openModal);
    MOD.addEventListener('click',(ev)=>{if(ev.target&&ev.target.getAttribute('data-ts-close')==='1')closeModal();});
    window.addEventListener('keydown',(ev)=>{if(ev.key==='Escape'&&MOD.classList.contains('show'))closeModal();});

    /* ── Multi-select pulldowns ── */
    function initMultiSelect(containerEl, tagsEl) {
      if (!containerEl) return;
      const btn = containerEl.querySelector('.ts-multiselect-btn');
      const drop = containerEl.querySelector('.ts-multiselect-drop');
      const label = btn ? btn.querySelector('.ts-ms-label') : null;
      if (!btn || !drop) return;

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        // Close other open multiselects
        document.querySelectorAll('.ts-multiselect.open').forEach(ms => {
          if (ms !== containerEl) ms.classList.remove('open');
        });
        containerEl.classList.toggle('open');
      });

      // Close on outside click
      document.addEventListener('click', (e) => {
        if (!containerEl.contains(e.target)) containerEl.classList.remove('open');
      });

      function updateDisplay() {
        const checked = drop.querySelectorAll('input[type="checkbox"]:checked');
        const vals = Array.from(checked).map(cb => cb.parentElement.textContent.trim());
        if (label) label.textContent = vals.length ? vals.length + ' selected' : btn.dataset.placeholder || 'Select...';
        if (tagsEl) {
          tagsEl.innerHTML = vals.map((v, i) =>
            '<span class="ts-selected-tag">' + v + ' <span class="ts-tag-x" data-idx="' + i + '">&times;</span></span>'
          ).join('');
        }
      }
      btn.dataset.placeholder = label ? label.textContent : 'Select...';

      drop.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', updateDisplay);
      });

      if (tagsEl) {
        tagsEl.addEventListener('click', (e) => {
          const x = e.target.closest('.ts-tag-x');
          if (!x) return;
          const idx = parseInt(x.dataset.idx);
          const checked = Array.from(drop.querySelectorAll('input[type="checkbox"]:checked'));
          if (checked[idx]) { checked[idx].checked = false; updateDisplay(); }
        });
      }

      return { updateDisplay };
    }

    // Init multi-selects (change types + protocols — affected areas removed)
    initMultiSelect(
      document.getElementById(<?= json_encode("tsChangeSelect_$tsUid") ?>),
      document.getElementById(<?= json_encode("tsChangeTags_$tsUid") ?>)
    );

    /* ── Form sub-tab switching ── */
    let activeFormTab = 'report_issue';
    const DESC_PLACEHOLDERS = {
      report_issue:   'What were you doing? What\'s not working? Describe it in your own words...',
      page_content:   'Describe your request in natural language \u2014 the clearer you are the faster we can make the changes...',
      feature_module: 'Describe the feature or enhancement you need. What problem does it solve?',
      question:       'What would you like to know? We\'ll get back to you...'
    };

    function switchFormTab(tabKey){
      activeFormTab = tabKey;
      FORM_TABS.forEach(t => t.classList.toggle('active', t.dataset.ftab === tabKey));
      FORM_PANELS.forEach(p => p.classList.toggle('active', p.dataset.fpanel === tabKey));
      // Update description placeholder
      if(DESC_EL) DESC_EL.placeholder = DESC_PLACEHOLDERS[tabKey] || DESC_PLACEHOLDERS.report_issue;
      // Set hidden category value based on tab
      if(tabKey === 'report_issue'){
        const checked = FORM.querySelector('input[name="ts_ri_type"]:checked');
        CAT_HIDDEN.value = checked ? checked.value : 'ui_issue';
      } else if(tabKey === 'page_content'){
        CAT_HIDDEN.value = 'page_content';
        loadLocalPages();
      } else if(tabKey === 'feature_module'){
        const checked = FORM.querySelector('input[name="ts_fm_type"]:checked');
        CAT_HIDDEN.value = checked ? checked.value : 'feature_request';
      } else if(tabKey === 'question'){
        CAT_HIDDEN.value = 'question';
      }
    }
    FORM_TABS.forEach(t => t.addEventListener('click', () => switchFormTab(t.dataset.ftab)));

    // Report Issue sub-category updates hidden category
    FORM.querySelectorAll('input[name="ts_ri_type"]').forEach(r => {
      r.addEventListener('change', () => { if(activeFormTab==='report_issue') CAT_HIDDEN.value = r.value; });
    });

    // Feature/Module sub-category updates hidden category
    FORM.querySelectorAll('input[name="ts_fm_type"]').forEach(r => {
      r.addEventListener('change', () => {
        if(activeFormTab==='feature_module') CAT_HIDDEN.value = r.value;
      });
    });

    /* ── Page dropdown loader ── */
    let pagesLoaded = false;
    async function loadLocalPages(){
      if(pagesLoaded) return;
      try{
        const r = await fetch(LOCAL_EP + '?action=get_local_pages', {credentials:'same-origin'});
        const j = await r.json();
        if(j.ok && j.pages){
          PAGE_SEL.innerHTML = '<option value="">-- Select a page --</option>';
          j.pages.forEach(p => {
            const o = document.createElement('option');
            o.value = p.slug;
            o.textContent = p.title + ' (' + p.slug + ')';
            PAGE_SEL.appendChild(o);
          });
          pagesLoaded = true;
        }
      }catch(e){ /* silent */ }
    }

    /* ── screenshot ── */
    DROP.addEventListener('click',()=>FFILE.click());
    DROP.addEventListener('dragover',(e)=>{e.preventDefault();DROP.classList.add('dragover');});
    DROP.addEventListener('dragleave',()=>DROP.classList.remove('dragover'));
    DROP.addEventListener('drop',(e)=>{e.preventDefault();DROP.classList.remove('dragover');if(e.dataTransfer.files.length)handleFile(e.dataTransfer.files[0]);});
    FFILE.addEventListener('change',()=>{if(FFILE.files.length)handleFile(FFILE.files[0]);});
    function handleFile(f){
      if(!f.type.startsWith('image/')){toast('warn','Only image files');return;}
      if(f.size>2*1024*1024){toast('warn','Max 2 MB');return;}
      const r=new FileReader();r.onload=()=>{screenshotB64=r.result;PREV.src=screenshotB64;PREV.style.display='block';};r.readAsDataURL(f);
    }

    /* ── collect form ── */
    function collectTicket(){
      const fd=new FormData(FORM);
      const cat=CAT_HIDDEN.value||'ui_issue';
      const desc=(fd.get('ts_description')||'').trim();
      let subject='';

      // Auto-generate subject from description or context
      if(cat==='page_content'){
        const pageOpt = PAGE_SEL.options[PAGE_SEL.selectedIndex];
        const pageTitle = pageOpt && pageOpt.value ? pageOpt.textContent : '';
        const changeTypes = fd.getAll('ts_pc_types[]');
        subject = 'Page edit: ' + (pageTitle||'(unspecified)') + (changeTypes.length ? ' ['+changeTypes.map(v=>v.replace(/_/g,' ')).join(', ')+']' : '');
      } else if(panicActive){
        subject = 'EMERGENCY: ' + (desc.replace(/^EMERGENCY:\s*/i,'').substring(0,80) || 'Critical outage reported');
      } else {
        // First line or first 80 chars of description
        const firstLine = desc.split(/[\n\r]/)[0] || '';
        subject = firstLine.substring(0,80) || (CAT_LABELS[cat]||cat) + ' report';
      }

      // Insufficient Articulation — general description check (except page_content which has its own)
      if(cat !== 'page_content'){
        const desc = (fd.get('ts_description')||'').trim();
        if(cat !== 'question' && desc.length < 10){
          toast('warn','Insufficient Articulation \u2014 please describe the issue in more detail');
          STAT.textContent = 'Request lacks sufficient detail to execute properly';
          setTimeout(()=>{STAT.textContent='';},4000);
          return null;
        }
      }

      const ticket={category:cat,
        affected_areas: [],
        urgency: URG_HIDDEN ? URG_HIDDEN.value : 'low',
        triage_mode: 'ai',
        subject,description:desc,
        screenshot_base64:screenshotB64,user:USER,cms_version:CMS_VER,
        php_version:<?=json_encode(PHP_VERSION)?>,user_agent:navigator.userAgent};

      // Page/Content details + Insufficient Articulation check
      if(cat==='page_content'){
        const changeTypes = fd.getAll('ts_pc_types[]');
        const desc = (fd.get('ts_description')||'').trim();
        const pageSlug = fd.get('ts_pc_page')||'';

        // Insufficient Articulation validation (page is optional targeting info)
        const problems = [];
        if(!changeTypes.length) problems.push('check at least one change type');
        if(desc.length < 20) problems.push('provide a more detailed description (at least 20 characters)');
        if(problems.length){
          toast('warn','Insufficient Articulation \u2014 please ' + problems.join(', '));
          STAT.textContent = 'Change request lacks sufficient detail';
          setTimeout(()=>{STAT.textContent='';},4000);
          return null;
        }

        const pageOpt = PAGE_SEL.options[PAGE_SEL.selectedIndex];
        ticket.page_content_details={
          site:DOMAIN,
          page_slug:pageSlug,
          page_title:(pageOpt && pageOpt.value) ? pageOpt.textContent.replace(/\s*\([^)]+\)\s*$/,'') : '',
          change_types:changeTypes,
          description:desc
        };
        // Auto-set affected areas for page content tickets
        ticket.affected_areas = ['PageManager','Frontend (site)'];
      }

      return ticket;
    }

    /* ── submit ticket ── */
    async function submitTicket(){
      const ticket=collectTicket(); if(!ticket)return;
      SUBMIT.disabled=true; STAT.textContent='Submitting...';
      try{
        const ep=SUPPORT_URL+'/admin/modules/TechSupport/api.php';
        const r=await fetch(ep,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({action:'submit_ticket',domain:DOMAIN,site_key:SITE_KEY,ticket})});
        if(!r.ok)throw new Error(r.status+' '+r.statusText);
        const j=await r.json();
        if(j&&j.ok){
          STAT.textContent='Submitted!';
          toast('info','Ticket submitted: '+(j.ticket_id||''));
          // Save local
          try{await fetch(LOCAL_EP,{method:'POST',headers:{'Content-Type':'application/json'},
            credentials:'same-origin',body:JSON.stringify({action:'save_local',ticket_id:j.ticket_id,ticket})});}catch(e){}
          // Reset form completely (mobile-safe: explicit field clearing)
          FORM.reset();
          screenshotB64=null;
          PREV.style.display='none';PREV.src='';
          // Explicitly clear textarea and inputs (mobile browsers sometimes skip .reset())
          if(DESC_EL){ DESC_EL.value=''; }
          FORM.querySelectorAll('input[type="text"],textarea').forEach(el => { el.value=''; });
          FORM.querySelectorAll('select').forEach(el => { el.selectedIndex=0; });
          FORM.querySelectorAll('input[type="checkbox"]').forEach(el => { el.checked=false; });
          FORM.querySelectorAll('input[type="file"]').forEach(el => { try{el.value='';}catch(e){} });
          // Clear multi-select tag displays
          FORM.querySelectorAll('.ts-selected-tags').forEach(el => el.innerHTML = '');
          FORM.querySelectorAll('.ts-multiselect-btn .ts-ms-label').forEach(el => {
            const btn = el.closest('.ts-multiselect-btn');
            el.textContent = btn ? (btn.dataset.placeholder || 'Select...') : 'Select...';
          });
          // Reset form tab and defaults
          switchFormTab('report_issue');
          const ri1=FORM.querySelector('input[name="ts_ri_type"][value="ui_issue"]');if(ri1)ri1.checked=true;
          if(URG_HIDDEN) URG_HIDDEN.value='low';
          // Reset panic button
          panicActive=false;
          if(PANIC_BTN){
            PANIC_BTN.classList.remove('ts-panic-active');
            PANIC_BTN.querySelector('.ts-panic-sub').textContent='Click to report a critical outage';
          }
          // Switch to My Tickets to show the new one
          setTimeout(()=>{STAT.textContent='';switchTab('mine');},800);
        } else throw new Error(j.error||'Unknown error');
      }catch(e){STAT.textContent='Submit failed.';toast('error','Submission failed: '+(e.message||e));}
      finally{SUBMIT.disabled=false;}
    }
    SUBMIT.addEventListener('click',submitTicket);
    FORM.addEventListener('keydown',(ev)=>{if((ev.ctrlKey||ev.metaKey)&&ev.key==='Enter'&&MOD.classList.contains('show')){ev.preventDefault();submitTicket();}});

    /* ══════════════════════════════════════════════════════
     * MY TICKETS — load local history + poll central for status
     * ══════════════════════════════════════════════════════ */
    async function loadMyTickets(){
      TLIST.innerHTML='<li class="ts-loading">Loading...</li>';
      try{
        // 1) Get local ticket list
        const lr=await fetch(LOCAL_EP+'?action=history',{credentials:'same-origin'});
        const lj=await lr.json();
        let tickets=lj.ok?lj.tickets:[];

        // 2) If we have tickets, poll central for fresh status
        if(tickets.length){
          try{
            const ids=tickets.map(t=>t.id);
            const sr=await fetch(SUPPORT_URL+'/admin/modules/TechSupport/api.php',{
              method:'POST',headers:{'Content-Type':'application/json'},
              body:JSON.stringify({action:'check_status',domain:DOMAIN,site_key:SITE_KEY,ticket_ids:ids})});
            const sj=await sr.json();
            if(sj.ok&&sj.tickets){
              tickets=tickets.map(t=>{
                const remote=sj.tickets[t.id];
                if(remote){
                  t.status=remote.status;
                  t.updated_at=remote.updated_at;
                  t.resolution=remote.resolution;
                  t.resolved_by=remote.resolved_by||null;
                  t.resolved_at=remote.resolved_at||null;
                  t.notes=remote.notes||[];
                }
                return t;
              });
            }
          }catch(e){/* central unreachable — show local data */}
        }

        cachedTickets=tickets;
        renderTicketList(tickets);

        // Update badge
        const openCount=tickets.filter(t=>t.status==='open'||t.status==='in_progress'||t.status==='submitted').length;
        if(openCount>0){BADGE.textContent=openCount;BADGE.style.display='';}
        else{BADGE.style.display='none';}

        REFRESH_T.textContent='Updated '+new Date().toLocaleTimeString();

      }catch(e){
        TLIST.innerHTML='<li class="ts-empty">Could not load tickets.</li>';
      }
    }

    function renderTicketList(tickets){
      if(!tickets.length){
        TLIST.innerHTML='<li class="ts-empty">No tickets submitted yet.<br><small style="color:var(--ts-muted)">Switch to "New Ticket" to submit one.</small></li>';
        return;
      }
      TLIST.innerHTML=tickets.map(t=>{
        const statusLabel=(t.status||'unknown').replace(/_/g,' ');
        const catLabel=CAT_LABELS[t.category]||t.category||'';
        const areas=(t.affected_areas||[]).join(', ');
        const hasNotes=(t.notes&&t.notes.length>0);
        const hasRes=(t.resolution&&t.resolution.trim());
        return `<li class="ts-ticket-item" data-tid="${esc(t.id)}">
          <div class="ts-ticket-row">
            <span class="ts-ticket-urg ${esc(t.urgency)}" title="${esc(t.urgency)}"></span>
            <span class="ts-ticket-subject">${esc(t.subject)}</span>
            <span class="ts-ticket-status ${esc(t.status)}">${esc(statusLabel)}</span>
            <span class="ts-ticket-age">${timeAgo(t.submitted_at)}</span>
          </div>
          <div class="ts-ticket-detail">
            <div class="ts-ticket-detail-id">${esc(t.id)} &mdash; ${esc(catLabel)}</div>
            ${areas?'<div class="ts-ticket-detail-areas">Areas: '+esc(areas)+'</div>':''}
            ${t.description?'<div class="ts-ticket-detail-desc">'+esc(t.description)+'</div>':''}
            ${hasRes?`<div class="ts-ticket-detail-resolution"><h5>Resolution</h5><p>${esc(t.resolution)}</p>${t.resolved_by?`<div class="ts-resolved-by">Solved by <strong>${esc(t.resolved_by)}</strong>${t.resolved_at?' &middot; '+timeAgo(t.resolved_at):''}</div>`:''}</div>`:''}
            ${hasNotes?`<div class="ts-ticket-notes"><h5>Responses</h5>${t.notes.map(n=>`<div class="ts-ticket-note">${esc(n.text)}<div class="ts-ticket-note-meta">${esc(n.by)} &mdash; ${timeAgo(n.at)}</div></div>`).join('')}</div>`:''}
          </div>
        </li>`;
      }).join('');
    }

    // Expand/collapse ticket detail
    TLIST.addEventListener('click',(e)=>{
      const li=e.target.closest('.ts-ticket-item');
      if(!li)return;
      li.classList.toggle('expanded');
    });

    REFRESH_B.addEventListener('click',loadMyTickets);

    // Load ticket count on page load (lightweight — just badge)
    (async()=>{
      try{
        const lr=await fetch(LOCAL_EP+'?action=history',{credentials:'same-origin'});
        const lj=await lr.json();
        if(lj.ok&&lj.tickets){
          const ct=lj.tickets.filter(t=>t.status==='open'||t.status==='in_progress'||t.status==='submitted').length;
          if(ct>0){BADGE.textContent=ct;BADGE.style.display='';}
        }
      }catch(e){}
    })();
  })();
  </script>
  <?php
  return;
}

/* =======================================================================
 * 2) DIRECT JSON API — local save / history
 * ======================================================================= */
$tsMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($tsMethod === 'POST') {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  $action = $data['action'] ?? '';

  if ($action === 'save_local') {
    $ticketId = $data['ticket_id'] ?? '';
    $ticket   = $data['ticket'] ?? [];
    if ($ticketId && $ticket) {
      $ticket['id'] = $ticketId;
      $ticket['domain'] = ts_get_domain();
      $ticket['submitted_at'] = date('c');
      $ticket['status'] = 'submitted';
      $ok = ts_save_local($TS_LOCAL_DIR, $ticket);
      echo json_encode(['ok' => $ok]);
    } else {
      echo json_encode(['ok' => false, 'error' => 'Missing ticket_id or ticket data']);
    }
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action']);
  exit;
}

if ($tsMethod === 'GET') {
  $action = $_GET['action'] ?? 'history';

  /* ── get_local_pages — scan this site's pages for dropdown ── */
  if ($action === 'get_local_pages') {
    $pagesDir = SITE_ROOT . '/admin/data/pages';
    $pages = [];
    if (is_dir($pagesDir)) {
      foreach (glob($pagesDir . '/*/') as $dir) {
        $slug = basename($dir);
        if ($slug === 'pages_trash' || $slug[0] === '.') continue;
        $jsonFile = $dir . '/' . $slug . '.json';
        if (!is_file($jsonFile)) continue;
        $data = json_decode(file_get_contents($jsonFile), true);
        $pages[] = [
          'slug'          => $slug,
          'title'         => $data['page_title'] ?? ucwords(str_replace('-', ' ', $slug)),
          'last_modified' => date('c', filemtime($jsonFile)),
        ];
      }
      usort($pages, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    }
    echo json_encode(['ok' => true, 'pages' => $pages]);
    exit;
  }

  if ($action === 'history') {
    $tickets = [];
    if (is_dir($TS_LOCAL_DIR)) {
      foreach (glob($TS_LOCAL_DIR . '/TS-*.json') as $f) {
        $t = json_decode(file_get_contents($f), true);
        if ($t) $tickets[] = [
          'id'             => $t['id'] ?? basename($f, '.json'),
          'subject'        => $t['subject'] ?? '',
          'description'    => $t['description'] ?? '',
          'category'       => $t['category'] ?? '',
          'affected_areas' => $t['affected_areas'] ?? [],
          'urgency'        => $t['urgency'] ?? '',
          'status'         => $t['status'] ?? 'unknown',
          'submitted_at'   => $t['submitted_at'] ?? '',
        ];
      }
      usort($tickets, fn($a,$b) => strcmp($b['submitted_at'], $a['submitted_at']));
    }
    echo json_encode(['ok' => true, 'tickets' => $tickets]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action']);
  exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
