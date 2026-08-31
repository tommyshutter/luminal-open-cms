<?php
/**
 * @file admin/modules/AdminThemes/AdminThemes.php
 * @desc Admin theme picker + per-user Display & Accessibility controls.
 *       Picking applies live (admin-themes.js) and persists per user.
 *       v2 — rewritten simple: native controls + inline styles, no fancy
 *       panel classes that could collide/hide. Get it rendering, polish later.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();
require_once __DIR__ . '/theme_lib.inc.php';

$AT_AVAIL    = admin_themes_available();
$AT_PREFS    = admin_themes_load_prefs();
$AT_ACTIVE   = admin_themes_resolve();
$AT_DEFAULT  = $AT_PREFS['site_default'] ?? 'luminal';
$AT_ISADMIN  = in_array($_SESSION['user_role'] ?? '', ['admin', 'superadmin'], true) || !empty($_SESSION['admin_logged_in']);
$AT_DISP     = admin_themes_resolve_display();
$AT_PALETTES = admin_themes_palettes();
$AT_FONTS    = admin_themes_fonts();
$AT_CUSTOM   = (isset($AT_PREFS['custom_themes']) && is_array($AT_PREFS['custom_themes'])) ? $AT_PREFS['custom_themes'] : [];

$AT_PREVIEW = [
    'luminal' => ['bg' => 'linear-gradient(150deg,#0b0b0f,#10162e)', 'accent' => '#2e4fd6', 'controls' => 'none',    'desc' => 'The default Luminal admin look — dark glassmorphic blue.'],
    'macos'   => ['bg' => 'linear-gradient(150deg,#48267a,#0a84ff)', 'accent' => '#0a84ff', 'controls' => 'mac',     'desc' => 'macOS graphite — traffic-light controls, menubar, Dock.'],
    'windows' => ['bg' => 'linear-gradient(160deg,#143c6e,#4cc2ff)', 'accent' => '#4cc2ff', 'controls' => 'windows', 'desc' => 'Windows 11 dark Mica — Segoe type, taskbar, Start.'],
    'linux'   => ['bg' => 'linear-gradient(160deg,#2c001e,#e95420)', 'accent' => '#e95420', 'controls' => 'linux',   'desc' => 'Ubuntu / GNOME (Yaru) — aubergine + orange header bars.'],
];

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<?php $AT_GS = (isset($AT_PREFS['display']['site_default']) && is_array($AT_PREFS['display']['site_default'])) ? $AT_PREFS['display']['site_default'] : []; ?>
<?php if ($AT_ISADMIN): ?>
<!-- ================= GLOBAL SETTINGS · CMS-wide defaults (superadmin) ================= -->
<div id="atg" style="border:1px solid rgba(255,255,255,.2);border-radius:12px;background:linear-gradient(145deg,rgba(40,26,66,.55),rgba(15,20,40,.45));padding:15px 20px;margin:22px 0 22px;">
  <h2 style="margin:0 0 3px;font-size:1.15rem;font-weight:700;">🌐 Global Settings
    <span style="font-weight:400;font-size:11px;opacity:.55;">CMS-wide defaults · superadmin</span>
    <span id="atg-saved" style="font-size:12px;font-weight:600;color:#34d399;opacity:0;transition:opacity .25s;margin-left:6px;">✓ Saved</span>
  </h2>
  <p style="margin:0 0 13px;opacity:.8;font-size:12.5px;line-height:1.5;">Seeds <strong>every user on this site</strong> (the <code>site&nbsp;default</code> layer). Users can still override in their personal panel&nbsp;&rarr;.</p>
  <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-end;">
    <div style="display:flex;flex-direction:column;gap:6px;">
      <span style="font-size:12px;font-weight:600;">Default accent</span>
      <span style="display:flex;gap:6px;align-items:center;"><input type="color" id="atg-accent" value="<?= htmlspecialchars($AT_GS['accent'] ?? '#2e4fd6') ?>" style="width:44px;height:30px;"><button type="button" id="atg-accent-clear" style="padding:5px 11px;border-radius:7px;background:rgba(255,255,255,.1);color:inherit;border:1px solid rgba(255,255,255,.25);cursor:pointer;font-weight:600;font-size:12px;">Clear</button></span>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;">
      <span style="font-size:12px;font-weight:600;">Semantic palette</span>
      <span style="display:flex;gap:10px;align-items:center;">
        <span style="display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px;opacity:.6;"><input type="color" id="atg-go" value="<?= htmlspecialchars($AT_GS['go'] ?? '#22c55e') ?>" style="width:36px;height:28px;">go</span>
        <span style="display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px;opacity:.6;"><input type="color" id="atg-warn" value="<?= htmlspecialchars($AT_GS['warn'] ?? '#f5b301') ?>" style="width:36px;height:28px;">warn</span>
        <span style="display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px;opacity:.6;"><input type="color" id="atg-danger" value="<?= htmlspecialchars($AT_GS['danger'] ?? '#dc2626') ?>" style="width:36px;height:28px;">danger</span>
        <span style="display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px;opacity:.6;"><input type="color" id="atg-surface" value="<?= htmlspecialchars($AT_GS['surface'] ?? '#14141c') ?>" style="width:36px;height:28px;">surface</span>
      </span>
    </div>
    <button type="button" id="atg-save" style="padding:9px 18px;border-radius:8px;background:#4c6fff;border:1px solid #4c6fff;color:#fff;cursor:pointer;font-weight:700;font-size:13px;">💾 Save site defaults</button>
  </div>
</div>
<?php endif; ?>

<!-- two-column layout: themes (left) · personal Display & Accessibility (right) -->
<div class="at-layout">

<!-- ================= DISPLAY & ACCESSIBILITY (right column) ================= -->
<div id="adx" style="border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(0,0,0,.28);padding:18px 20px;margin:0 0 26px;max-width:620px;">
  <h2 style="margin:0 0 4px;font-size:1.3rem;font-weight:700;">🔆 Display &amp; Accessibility <span id="adx-saved" style="font-size:12px;font-weight:600;color:#34d399;opacity:0;transition:opacity .25s;">✓ Saved</span></h2>
  <p style="margin:0 0 16px;opacity:.8;font-size:13px;line-height:1.5;">Your personal settings — they apply <strong>instantly and save automatically</strong> to your account (no Save button needed), on top of any theme.</p>

  <div style="display:grid;grid-template-columns:130px 1fr;gap:13px 16px;align-items:center;">

    <label for="adx-scale">Text size</label>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <button type="button" id="adx-minus">A&minus;</button>
      <input type="range" id="adx-scale" min="0.85" max="1.6" step="0.01" value="<?= htmlspecialchars((string)$AT_DISP['scale']) ?>" style="flex:1;min-width:200px;max-width:340px;">
      <button type="button" id="adx-plus">A+</button>
      <span id="adx-scaleval" style="min-width:46px;font-weight:600;"><?= round($AT_DISP['scale'] * 100) ?>%</span>
    </div>

    <label for="adx-contrast">Contrast</label>
    <select id="adx-contrast">
      <option value="normal">Normal</option>
      <option value="high">High contrast</option>
    </select>

    <label for="adx-density">Density</label>
    <select id="adx-density">
      <option value="compact">Compact</option>
      <option value="cozy">Cozy</option>
      <option value="roomy">Roomy</option>
    </select>

    <label for="adx-tactile">Tactile UI</label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="adx-tactile"> <span style="opacity:.75;font-size:12.5px;">drop shadows + bold hovers</span></label>

    <label for="adx-font">Typeface</label>
    <select id="adx-font">
      <option value="">Default (system)</option>
      <?php foreach ($AT_FONTS as $fk => $f): ?>
      <option value="<?= htmlspecialchars($fk) ?>"><?= htmlspecialchars($f['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="adx-palette">Background</label>
    <select id="adx-palette">
      <option value="">Default (dark)</option>
      <?php foreach ($AT_PALETTES as $pk => $p): ?>
      <option value="<?= htmlspecialchars($pk) ?>"><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="adx-accent">Accent <span class="adx-sub">tabs · focus · highlights</span></label>
    <div style="display:flex;align-items:center;gap:8px;">
      <input type="color" id="adx-accent" value="<?= htmlspecialchars($AT_DISP['accent'] ?: '#2e4fd6') ?>" style="width:46px;height:30px;">
      <button type="button" id="adx-accent-clear">Clear</button>
    </div>

    <label for="adx-btnbg">Buttons <span class="adx-sub">background &amp; text</span></label>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-btnbg" value="<?= htmlspecialchars($AT_DISP['btnbg'] ?: '#2e4fd6') ?>" style="width:42px;height:30px;"><span class="adx-sub">bg</span></span>
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-btntext" value="<?= htmlspecialchars($AT_DISP['btntext'] ?: '#ffffff') ?>" style="width:42px;height:30px;"><span class="adx-sub">text</span></span>
      <button type="button" id="adx-btn-clear">Clear</button>
    </div>

    <label for="adx-go">Semantic <span class="adx-sub">go · caution · danger · surface</span></label>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-go" value="<?= htmlspecialchars($AT_DISP['go'] ?: '#22c55e') ?>" style="width:38px;height:30px;"><span class="adx-sub">go</span></span>
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-warn" value="<?= htmlspecialchars($AT_DISP['warn'] ?: '#f5b301') ?>" style="width:38px;height:30px;"><span class="adx-sub">warn</span></span>
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-danger" value="<?= htmlspecialchars($AT_DISP['danger'] ?: '#dc2626') ?>" style="width:38px;height:30px;"><span class="adx-sub">danger</span></span>
      <span style="display:flex;align-items:center;gap:5px;"><input type="color" id="adx-surface" value="<?= htmlspecialchars($AT_DISP['surface'] ?: '#14141c') ?>" style="width:38px;height:30px;"><span class="adx-sub">surface</span></span>
      <button type="button" id="adx-sem-clear">Clear</button>
    </div>

    <label>Presets</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" class="adx-preset" data-preset="comfort">Comfort</button>
      <button type="button" class="adx-preset" data-preset="large">Large + Contrast</button>
      <button type="button" class="adx-preset" data-preset="compact">Compact</button>
      <button type="button" class="adx-preset" data-preset="default">Default</button>
    </div>

    <?php if ($AT_ISADMIN): ?>
    <label>Save look <span class="adx-sub">as a reusable theme</span></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" id="adx-save-theme" style="background:#4c6fff;border-color:#4c6fff;color:#fff;font-weight:700;">💾 Save as new theme…</button>
    </div>
    <?php endif; ?>

    <label>Theme file <span class="adx-sub">portable JSON</span></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" id="adx-export">⬇ Export JSON</button>
      <button type="button" id="adx-import">⬆ Import JSON</button>
    </div>

    <label></label>
    <div style="margin-top:2px;">
      <button type="button" id="adx-save" style="background:#34d399;border-color:#34d399;color:#04210f;font-weight:700;padding:8px 18px;">💾 Save settings</button>
      <span style="opacity:.6;font-size:12px;margin-left:8px;">changes also auto-save</span>
    </div>

  </div>
</div>

<!-- ================= THEME PICKER (left column) ================= -->
<div class="at-col-themes">
<h2 style="margin:0 0 10px;font-size:1.3rem;font-weight:700;">🎨 Theme</h2>
<div class="at-picker-intro" style="margin:0 0 16px;opacity:.85;max-width:760px;line-height:1.6;">
  Choose how <strong>your</strong> admin panel looks — saved to your account. Click a theme to apply it instantly.
  <?php if ($AT_ISADMIN): ?><br><span style="opacity:.75;">As an admin you can also set the <em>site default</em>.</span><?php endif; ?>
</div>

<div class="at-grid">
<?php foreach ($AT_AVAIL as $slug => $meta):
    $pv = $AT_PREVIEW[$slug] ?? $AT_PREVIEW['luminal'];
    $isActive  = ($slug === $AT_ACTIVE);
    $isDefault = ($slug === $AT_DEFAULT);
?>
  <div class="at-card<?= $isActive ? ' active' : '' ?>" data-slug="<?= htmlspecialchars($slug) ?>">
    <div class="at-card-preview" style="background:<?= $pv['bg'] ?>;">
      <div class="at-mock-window">
        <div class="at-mock-titlebar at-ctl-<?= $pv['controls'] ?>">
          <span class="at-mock-ctl c1"></span><span class="at-mock-ctl c2"></span><span class="at-mock-ctl c3"></span>
          <span class="at-mock-title">Dashboard</span>
        </div>
        <div class="at-mock-body">
          <span class="at-mock-line w70"></span><span class="at-mock-line w90"></span><span class="at-mock-line w50"></span>
          <span class="at-mock-chip" style="background:<?= $pv['accent'] ?>;"></span>
        </div>
      </div>
    </div>
    <div class="at-card-foot">
      <div class="at-card-name">
        <?= htmlspecialchars($meta['label']) ?>
        <?php if ($isDefault): ?><span class="at-badge">site default</span><?php endif; ?>
      </div>
      <div class="at-card-desc"><?= htmlspecialchars($pv['desc']) ?></div>
      <div class="at-card-actions">
        <button type="button" class="at-use<?= $isActive ? ' is-active' : '' ?>" data-slug="<?= htmlspecialchars($slug) ?>"><?= $isActive ? '✓ Active' : 'Use' ?></button>
        <?php if ($AT_ISADMIN): ?>
        <button type="button" class="at-default<?= $isDefault ? ' is-default' : '' ?>" data-slug="<?= htmlspecialchars($slug) ?>"><?= $isDefault ? 'Default' : 'Set default' ?></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if (!empty($AT_CUSTOM)): ?>
<h2 style="margin:24px 0 10px;font-size:1.05rem;font-weight:700;">💾 Saved themes</h2>
<div class="at-cgrid">
<?php foreach ($AT_CUSTOM as $cid => $ct):
    $cd = is_array($ct['display'] ?? null) ? $ct['display'] : [];
    $sw = function ($v, $def) { $v = (string)$v; return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $def; };
?>
  <div class="at-ccard" data-id="<?= htmlspecialchars($cid, ENT_QUOTES) ?>" data-base="<?= htmlspecialchars($ct['base'] ?? 'luminal', ENT_QUOTES) ?>" data-display='<?= htmlspecialchars(json_encode($cd), ENT_QUOTES) ?>'>
    <div class="at-ccard-name"><?= htmlspecialchars($ct['name'] ?? 'Untitled') ?></div>
    <div class="at-ccard-swatches">
      <span class="at-ccard-sw" title="accent" style="background:<?= $sw($cd['accent'] ?? '', '#2e4fd6') ?>;"></span>
      <span class="at-ccard-sw" title="go" style="background:<?= $sw($cd['go'] ?? '', '#22c55e') ?>;"></span>
      <span class="at-ccard-sw" title="warn" style="background:<?= $sw($cd['warn'] ?? '', '#f5b301') ?>;"></span>
      <span class="at-ccard-sw" title="danger" style="background:<?= $sw($cd['danger'] ?? '', '#dc2626') ?>;"></span>
    </div>
    <div class="at-ccard-actions">
      <button type="button" class="at-cuse">Use</button>
      <?php if ($AT_ISADMIN): ?><button type="button" class="at-cdel" title="Delete">✕</button><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- .at-col-themes -->
</div><!-- .at-layout -->

<style>
/* Display panel native controls — scoped, minimal */
#adx label { font-weight:600; font-size:13.5px; }
#adx .adx-sub { font-weight:400; font-size:11px; opacity:.55; }
#adx select, #adx button, #adx input[type=range] { font:inherit; }
#adx select { padding:6px 9px; border-radius:7px; background:rgba(255,255,255,.08); color:inherit; border:1px solid rgba(255,255,255,.25); min-width:180px; }
#adx button { padding:6px 13px; border-radius:7px; background:rgba(255,255,255,.1); color:inherit; border:1px solid rgba(255,255,255,.25); cursor:pointer; font-weight:600; }
#adx button:hover { background:rgba(255,255,255,.2); border-color:#4c6fff; }
#adx #adx-minus, #adx #adx-plus { padding:4px 10px; font-weight:700; }
#adx input[type=range] { accent-color:#4c6fff; }

/* Theme cards */
.at-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:18px; margin-bottom:30px; }
.at-card { border:1px solid rgba(255,255,255,.12); border-radius:14px; overflow:hidden; background:rgba(255,255,255,.04); transition:.15s; cursor:pointer; }
.at-card:hover { transform:translateY(-3px); box-shadow:0 16px 40px rgba(0,0,0,.4); }
.at-card.active { border-color:#4c6fff; box-shadow:0 0 0 2px rgba(76,111,255,.5); }
.at-card-preview { height:150px; padding:20px 20px 0; display:flex; align-items:flex-end; justify-content:center; cursor:pointer; }
.at-mock-window { width:100%; max-width:230px; background:rgba(20,20,24,.9); border-radius:9px 9px 0 0; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.45); border:1px solid rgba(255,255,255,.14); border-bottom:none; }
.at-mock-titlebar { height:26px; display:flex; align-items:center; gap:6px; padding:0 9px; background:rgba(255,255,255,.10); position:relative; }
.at-mock-ctl { width:9px; height:9px; border-radius:50%; background:rgba(255,255,255,.35); }
.at-mock-title { font-size:10px; opacity:.8; margin-left:8px; }
.at-ctl-mac .c1{background:#ff5f57}.at-ctl-mac .c2{background:#febc2e}.at-ctl-mac .c3{background:#28c840}
.at-ctl-mac .at-mock-title{ position:absolute; left:50%; transform:translateX(-50%); }
.at-ctl-windows .at-mock-ctl{ width:14px;height:14px;border-radius:0;background:transparent;order:2 }
.at-ctl-windows .c1{order:3}.at-ctl-windows .c3{order:1}
.at-ctl-windows .at-mock-title{ order:0; margin-left:0; margin-right:auto }
.at-ctl-windows{ justify-content:flex-end }
.at-ctl-linux .c1,.at-ctl-linux .c2{display:none}
.at-ctl-linux .c3{ margin-left:auto; width:14px;height:14px;background:rgba(255,255,255,.25) }
.at-ctl-none .at-mock-ctl{ background:rgba(255,255,255,.25) }
.at-mock-body { padding:12px 11px; display:flex; flex-direction:column; gap:7px; background:rgba(255,255,255,.05); }
.at-mock-line { height:7px; border-radius:4px; background:rgba(255,255,255,.18); }
.at-mock-line.w70{width:70%}.at-mock-line.w90{width:90%}.at-mock-line.w50{width:50%}
.at-mock-chip { height:16px; width:54px; border-radius:5px; margin-top:4px; }
.at-card-foot { padding:14px 16px 16px; display:flex; flex-direction:column; gap:10px; }
.at-card-name { font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px; }
.at-badge { font-size:10px; text-transform:uppercase; letter-spacing:.06em; background:rgba(76,111,255,.25); color:#9db4ff; padding:2px 7px; border-radius:999px; }
.at-card-desc { font-size:12.5px; opacity:.72; line-height:1.5; }
.at-card-actions { display:flex; gap:8px; }
.at-card-actions button { flex:1; padding:8px 10px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:600; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.06); color:#fff; }
.at-card-actions button:hover { border-color:#4c6fff; }
.at-use.is-active { background:#34d399; border-color:#34d399; color:#04210f; cursor:default; }
.at-default.is-default { background:rgba(76,111,255,.2); border-color:#4c6fff; color:#9db4ff; cursor:default; }

/* ---- Two-column layout: themes (left) · Display & Accessibility (right) ---- */
.at-layout { display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; }
.at-layout:first-child { margin-top:22px; }   /* breathing room when no global panel above */
.at-layout #adx { order:2; flex:1 1 420px; max-width:none; margin:0; }
.at-col-themes { order:1; flex:0 0 300px; max-width:320px; }
.at-col-themes h2 { font-size:1.15rem; }
.at-col-themes .at-grid { grid-template-columns:1fr; gap:20px; margin-bottom:0; }
.at-col-themes .at-card-preview { height:92px; padding:12px 14px 0; }
.at-col-themes .at-card-foot { padding:10px 13px 12px; gap:7px; }
.at-col-themes .at-card-name { font-size:14px; }
.at-col-themes .at-card-desc { font-size:11.5px; }
/* Saved (custom) themes */
.at-cgrid { display:grid; grid-template-columns:1fr; gap:12px; }
.at-ccard { border:1px solid rgba(255,255,255,.12); border-radius:12px; background:rgba(255,255,255,.04); padding:12px 13px; cursor:pointer; transition:.15s; display:flex; flex-direction:column; gap:9px; }
.at-ccard:hover { border-color:#4c6fff; transform:translateY(-2px); box-shadow:0 10px 26px rgba(0,0,0,.35); }
.at-ccard-name { font-size:14px; font-weight:600; }
.at-ccard-swatches { display:flex; gap:5px; }
.at-ccard-sw { width:20px; height:20px; border-radius:5px; border:1px solid rgba(255,255,255,.2); }
.at-ccard-actions { display:flex; gap:7px; align-items:center; }
.at-ccard-actions .at-cuse { flex:1; padding:6px 10px; border-radius:7px; cursor:pointer; font-size:12px; font-weight:600; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.06); color:#fff; }
.at-ccard-actions .at-cuse:hover { border-color:#4c6fff; }
.at-ccard-actions .at-cdel { padding:6px 9px; border-radius:7px; cursor:pointer; font-size:12px; border:1px solid rgba(255,255,255,.15); background:rgba(220,38,38,.12); color:#fca5a5; }
.at-ccard-actions .at-cdel:hover { background:rgba(220,38,38,.25); }
@media (max-width:860px) { .at-cgrid { grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); } }
@media (max-width:860px) {
  .at-layout { flex-direction:column; }
  .at-layout #adx { order:1; width:100%; }
  .at-col-themes { order:2; flex-basis:auto; max-width:none; width:100%; }
  .at-col-themes .at-grid { grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); }
}
</style>

<script>
(function () {
  /* ---- theme picker ---- */
  function apply(slug) {
    if (window.AdminThemes && typeof window.AdminThemes.select === 'function') window.AdminThemes.select(slug);
    else { fetch('/admin/modules/AdminThemes/api.php?action=set', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'theme='+encodeURIComponent(slug) }).then(function(){ location.reload(); }); }
    document.querySelectorAll('.at-card').forEach(function (c) {
      var on = c.dataset.slug === slug; c.classList.toggle('active', on);
      var b = c.querySelector('.at-use'); if (b) { b.classList.toggle('is-active', on); b.textContent = on ? '✓ Active' : 'Use'; }
    });
  }
  // Whole card selects the theme — preview, name, description, and Use button
  // all count. Only the separate "Set default" button opts out (its own action).
  document.querySelectorAll('.at-card').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('.at-default')) return;
      apply(card.dataset.slug);
    });
  });
  document.querySelectorAll('.at-default').forEach(function (b) {
    b.addEventListener('click', function () {
      var slug = b.dataset.slug;
      fetch('/admin/modules/AdminThemes/api.php?action=set_default', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'theme='+encodeURIComponent(slug) })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j.ok) { alert('Could not set default: ' + (j.error || 'error')); return; }
          document.querySelectorAll('.at-default').forEach(function (x) { var on = x.dataset.slug === slug; x.classList.toggle('is-default', on); x.textContent = on ? 'Default' : 'Set default'; });
        });
    });
  });

  /* ---- display & accessibility (wires to the working AdminThemes engine) ---- */
  var AT = window.AdminThemes || {};
  function g(id) { return document.getElementById(id); }
  function flashSaved() { var s = g('adx-saved'); if (s) { s.style.opacity = '1'; clearTimeout(s._t); s._t = setTimeout(function () { s.style.opacity = '0'; }, 1400); } }
  function set(p) { if (AT && typeof AT.setDisplay === 'function') AT.setDisplay(p); flashSaved(); }
  function cur() { return (AT && AT.display) || {}; }
  function toast(msg, good) {
    var t = g('adx-toast');
    if (!t) { t = document.createElement('div'); t.id = 'adx-toast'; t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(24px);padding:13px 26px;border-radius:11px;font-weight:700;font-size:14px;box-shadow:0 12px 34px rgba(0,0,0,.55);z-index:2147483600;opacity:0;transition:opacity .22s,transform .22s;border:1px solid rgba(255,255,255,.18);'; document.body.appendChild(t); }
    t.textContent = msg; t.style.background = good === false ? '#7f1d1d' : '#15803d'; t.style.color = '#fff';
    t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._h); t._h = setTimeout(function () { t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(24px)'; }, 2200);
  }
  function saveNow() {
    var d = cur();
    var body = 'scale=' + encodeURIComponent(d.scale || 1) + '&contrast=' + encodeURIComponent(d.contrast || 'normal') +
      '&tactile=' + (d.tactile ? '1' : '0') + '&density=' + encodeURIComponent(d.density || 'cozy') +
      '&accent=' + encodeURIComponent(d.accent || '') + '&palette=' + encodeURIComponent(d.palette || '') + '&font=' + encodeURIComponent(d.font || '') +
      '&btnbg=' + encodeURIComponent(d.btnbg || '') + '&btntext=' + encodeURIComponent(d.btntext || '') +
      '&go=' + encodeURIComponent(d.go || '') + '&warn=' + encodeURIComponent(d.warn || '') + '&danger=' + encodeURIComponent(d.danger || '') + '&surface=' + encodeURIComponent(d.surface || '');
    fetch('/admin/modules/AdminThemes/api.php?action=set_display', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body })
      .then(function (r) { return r.json(); }).then(function (j) { toast(j && j.ok ? '✓ Settings saved' : 'Save failed', !!(j && j.ok)); })
      .catch(function () { toast('Saved locally (offline)'); });
  }

  function refresh() {
    var d = cur();
    if (g('adx-scale')) g('adx-scale').value = d.scale || 1;
    if (g('adx-scaleval')) g('adx-scaleval').textContent = Math.round((d.scale || 1) * 100) + '%';
    if (g('adx-contrast')) g('adx-contrast').value = d.contrast || 'normal';
    if (g('adx-density')) g('adx-density').value = d.density || 'cozy';
    if (g('adx-tactile')) g('adx-tactile').checked = !!d.tactile;
    if (g('adx-font')) g('adx-font').value = d.font || '';
    if (g('adx-palette')) g('adx-palette').value = d.palette || '';
    if (g('adx-accent') && d.accent) g('adx-accent').value = d.accent;
    if (g('adx-btnbg') && d.btnbg) g('adx-btnbg').value = d.btnbg;
    if (g('adx-btntext') && d.btntext) g('adx-btntext').value = d.btntext;
    if (g('adx-go') && d.go) g('adx-go').value = d.go;
    if (g('adx-warn') && d.warn) g('adx-warn').value = d.warn;
    if (g('adx-danger') && d.danger) g('adx-danger').value = d.danger;
    if (g('adx-surface') && d.surface) g('adx-surface').value = d.surface;
  }
  refresh();

  if (g('adx-scale')) g('adx-scale').addEventListener('input', function () { set({ scale: parseFloat(this.value) }); if (g('adx-scaleval')) g('adx-scaleval').textContent = Math.round(this.value * 100) + '%'; });
  function bump(dl) { var s = Math.round(((cur().scale || 1) + dl) * 100) / 100; if (s < 0.85) s = 0.85; if (s > 1.6) s = 1.6; set({ scale: s }); refresh(); }
  if (g('adx-minus')) g('adx-minus').addEventListener('click', function () { bump(-0.02); });
  if (g('adx-plus')) g('adx-plus').addEventListener('click', function () { bump(0.02); });
  if (g('adx-contrast')) g('adx-contrast').addEventListener('change', function () { set({ contrast: this.value }); });
  if (g('adx-density')) g('adx-density').addEventListener('change', function () { set({ density: this.value }); });
  if (g('adx-tactile')) g('adx-tactile').addEventListener('change', function () { set({ tactile: this.checked }); });
  if (g('adx-font')) g('adx-font').addEventListener('change', function () { set({ font: this.value }); });
  if (g('adx-palette')) g('adx-palette').addEventListener('change', function () { set({ palette: this.value }); });
  if (g('adx-accent')) g('adx-accent').addEventListener('input', function () { set({ accent: this.value }); });
  if (g('adx-accent-clear')) g('adx-accent-clear').addEventListener('click', function () { set({ accent: '' }); });
  if (g('adx-btnbg')) g('adx-btnbg').addEventListener('input', function () { set({ btnbg: this.value }); });
  if (g('adx-btntext')) g('adx-btntext').addEventListener('input', function () { set({ btntext: this.value }); });
  if (g('adx-btn-clear')) g('adx-btn-clear').addEventListener('click', function () { set({ btnbg: '', btntext: '' }); refresh(); });
  if (g('adx-go')) g('adx-go').addEventListener('input', function () { set({ go: this.value }); });
  if (g('adx-warn')) g('adx-warn').addEventListener('input', function () { set({ warn: this.value }); });
  if (g('adx-danger')) g('adx-danger').addEventListener('input', function () { set({ danger: this.value }); });
  if (g('adx-surface')) g('adx-surface').addEventListener('input', function () { set({ surface: this.value }); });
  if (g('adx-sem-clear')) g('adx-sem-clear').addEventListener('click', function () { set({ go: '', warn: '', danger: '', surface: '' }); refresh(); });

  /* ---- Global Settings (site_default layer · superadmin) ---- */
  function atgFlash() { var s = g('atg-saved'); if (s) { s.style.opacity = '1'; clearTimeout(s._t); s._t = setTimeout(function () { s.style.opacity = '0'; }, 1600); } }
  function atgPost(accent) {
    function v(id) { return g(id) ? g(id).value : ''; }
    var body = 'accent=' + encodeURIComponent(accent) +
      '&go=' + encodeURIComponent(v('atg-go')) + '&warn=' + encodeURIComponent(v('atg-warn')) +
      '&danger=' + encodeURIComponent(v('atg-danger')) + '&surface=' + encodeURIComponent(v('atg-surface'));
    fetch('/admin/modules/AdminThemes/api.php?action=set_global', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { atgFlash(); toast('✓ Site defaults saved'); } else { toast('Save failed', false); } })
      .catch(function () { toast('Save failed', false); });
  }
  if (g('atg-save')) g('atg-save').addEventListener('click', function () { atgPost(g('atg-accent') ? g('atg-accent').value : ''); });
  if (g('atg-accent-clear')) g('atg-accent-clear').addEventListener('click', function () { atgPost(''); });

  /* ---- Export / Import theme as portable JSON ---- */
  if (g('adx-export')) g('adx-export').addEventListener('click', function () {
    var theme = (window.AdminThemes && window.AdminThemes.active) || 'luminal';
    var payload = { luminal_theme: 1, theme: theme, display: cur() };
    var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'luminal-theme-' + theme + '.json'; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 100);
    toast('✓ Theme exported');
  });
  function importClose() { var m = g('adx-import-modal'); if (m) m.style.display = 'none'; var t = g('adx-import-text'); if (t) t.value = ''; var f = g('adx-import-file'); if (f) f.value = ''; }
  if (g('adx-import')) g('adx-import').addEventListener('click', function () { var m = g('adx-import-modal'); if (m) m.style.display = 'flex'; });
  if (g('adx-import-cancel')) g('adx-import-cancel').addEventListener('click', importClose);
  if (g('adx-import-modal')) g('adx-import-modal').addEventListener('click', function (e) { if (e.target === this) importClose(); });
  if (g('adx-import-file')) g('adx-import-file').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return;
    var r = new FileReader(); r.onload = function () { var t = g('adx-import-text'); if (t) t.value = String(r.result); }; r.readAsText(f);
  });
  if (g('adx-import-apply')) g('adx-import-apply').addEventListener('click', function () {
    var t = g('adx-import-text'); var txt = t ? t.value.trim() : '';
    if (!txt) { toast('Paste JSON or choose a file', false); return; }
    var obj; try { obj = JSON.parse(txt); } catch (e) { toast('Invalid JSON', false); return; }
    var disp = (obj && typeof obj.display === 'object') ? obj.display : obj;
    if (!disp || typeof disp !== 'object') { toast('No display settings found in JSON', false); return; }
    if (AT && typeof AT.setDisplay === 'function') AT.setDisplay(disp);   // applies + persists (server re-sanitizes)
    if (obj && obj.theme && window.AdminThemes && typeof window.AdminThemes.select === 'function') window.AdminThemes.select(obj.theme);
    refresh();
    toast('✓ Theme imported');
    importClose();
  });

  /* ---- Save current look as a reusable theme card ---- */
  if (g('adx-save-theme')) g('adx-save-theme').addEventListener('click', function () {
    var name = prompt('Name this theme:');
    if (name === null) return;
    name = name.trim();
    if (!name) { toast('Give it a name', false); return; }
    var d = cur();
    var base = (window.AdminThemes && window.AdminThemes.active) || 'luminal';
    var body = 'name=' + encodeURIComponent(name) + '&base=' + encodeURIComponent(base) +
      '&scale=' + encodeURIComponent(d.scale || 1) + '&contrast=' + encodeURIComponent(d.contrast || 'normal') +
      '&tactile=' + (d.tactile ? '1' : '0') + '&density=' + encodeURIComponent(d.density || 'cozy') +
      '&accent=' + encodeURIComponent(d.accent || '') + '&palette=' + encodeURIComponent(d.palette || '') + '&font=' + encodeURIComponent(d.font || '') +
      '&btnbg=' + encodeURIComponent(d.btnbg || '') + '&btntext=' + encodeURIComponent(d.btntext || '') +
      '&go=' + encodeURIComponent(d.go || '') + '&warn=' + encodeURIComponent(d.warn || '') + '&danger=' + encodeURIComponent(d.danger || '') + '&surface=' + encodeURIComponent(d.surface || '');
    fetch('/admin/modules/AdminThemes/api.php?action=save_custom_theme', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.ok) { toast('✓ Saved "' + j.name + '"'); setTimeout(function () { location.reload(); }, 500); }
        else { toast('Save failed' + (j && j.error ? ': ' + j.error : ''), false); }
      }).catch(function () { toast('Save failed', false); });
  });

  /* ---- Saved theme cards: click/Use applies base + display; ✕ deletes ---- */
  document.querySelectorAll('.at-ccard').forEach(function (card) {
    function applyCustom() {
      var base = card.getAttribute('data-base') || 'luminal';
      var disp = {}; try { disp = JSON.parse(card.getAttribute('data-display') || '{}'); } catch (e) {}
      if (window.AdminThemes && typeof window.AdminThemes.select === 'function') window.AdminThemes.select(base);
      if (AT && typeof AT.setDisplay === 'function') AT.setDisplay(disp);
      refresh();
      var nm = card.querySelector('.at-ccard-name');
      toast('✓ Applied "' + (nm ? nm.textContent : 'theme') + '"');
    }
    card.addEventListener('click', function (e) {
      if (e.target.closest('.at-cdel')) return;
      applyCustom();
    });
    var del = card.querySelector('.at-cdel');
    if (del) del.addEventListener('click', function (e) {
      e.stopPropagation();
      var nm = card.querySelector('.at-ccard-name');
      if (!confirm('Delete saved theme "' + (nm ? nm.textContent : '') + '"?')) return;
      fetch('/admin/modules/AdminThemes/api.php?action=delete_custom_theme', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + encodeURIComponent(card.getAttribute('data-id')) })
        .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { card.remove(); toast('Deleted'); } else { toast('Delete failed', false); } })
        .catch(function () { toast('Delete failed', false); });
    });
  });
  if (g('adx-save')) g('adx-save').addEventListener('click', saveNow);
  document.querySelectorAll('.adx-preset').forEach(function (b) {
    b.addEventListener('click', function () { if (AT && AT.applyPreset) AT.applyPreset(b.getAttribute('data-preset')); setTimeout(refresh, 60); });
  });
  document.addEventListener('admin-display-changed', refresh);
})();
</script>

<!-- Import JSON theme modal (fixed overlay; escapes any backdrop-filter ancestor) -->
<div id="adx-import-modal" style="display:none;position:fixed;inset:0;z-index:2147483600;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px;">
  <div style="background:#14141c;border:1px solid rgba(255,255,255,.2);border-radius:12px;max-width:520px;width:100%;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
    <h3 style="margin:0 0 8px;font-size:1.1rem;font-weight:700;">⬆ Import JSON theme</h3>
    <p style="margin:0 0 10px;opacity:.75;font-size:12.5px;line-height:1.5;">Paste an exported theme's JSON, or choose a <code>.json</code> file. Applies to <strong>your</strong> account (auto-saves).</p>
    <textarea id="adx-import-text" placeholder='{ "luminal_theme":1, "theme":"macos", "display":{ … } }' style="width:100%;min-height:150px;box-sizing:border-box;background:rgba(0,0,0,.4);color:#e8e8e8;border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:10px;font-family:ui-monospace,Menlo,monospace;font-size:12px;resize:vertical;"></textarea>
    <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
      <input type="file" id="adx-import-file" accept="application/json,.json" style="font-size:12px;max-width:200px;">
      <span style="flex:1;"></span>
      <button type="button" id="adx-import-cancel" style="padding:8px 14px;border-radius:8px;background:rgba(255,255,255,.1);color:inherit;border:1px solid rgba(255,255,255,.25);cursor:pointer;font-weight:600;">Cancel</button>
      <button type="button" id="adx-import-apply" style="padding:8px 16px;border-radius:8px;background:#34d399;border:1px solid #34d399;color:#04210f;cursor:pointer;font-weight:700;">Apply</button>
    </div>
  </div>
</div>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
