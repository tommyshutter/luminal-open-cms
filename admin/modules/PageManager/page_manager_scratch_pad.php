<?php
/**
 * Page Manager — Scratch Pad (tri-mode, inline modal)
 * Path: /admin/ajax/page_manager_scratch_pad.php
 *
 * Modes:
 *   • INCLUDED (default via include): renders a button + inline modal editor (no iframe).
 *     – Loads/saves via this same file (GET/POST JSON), no headers sent, safe in sidebars.
 *     – Dark/Light theme toggle (persists in localStorage).
 *   • DIRECT GET ?mode=widget: standalone editor page (optional).
 *   • DIRECT JSON API:
 *       - GET  → { ok, content }
 *       - POST → { ok } ; body {content} (JSON or form or raw)
 *
 * Auth: direct access requires /admin/auth.php; include relies on caller’s auth.
 * Version: 2025.09.24.r2
 */

declare(strict_types=1);

/* ---------- paths ---------- */
if (!defined('SITE_ROOT')) {
  $adminDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
  define('SITE_ROOT', dirname($adminDir));
}
$STORE = SITE_ROOT . '/admin/data/page-manager-scratch-pad.json';
$SELF_WEB = '/admin/ajax/' . basename(__FILE__); // absolute endpoint for client JS

/* ---------- include vs direct ---------- */
$IS_DIRECT   = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__));
$WIDGET_MODE = isset($_GET['mode']) && $_GET['mode'] === 'widget';

/* ---------- auth for direct ---------- */
if ($IS_DIRECT) {
  @require_once dirname(__DIR__, 2) . '/auth.php';
  if (function_exists('requireAuth')) { requireAuth(); }
}

/* ---------- utils ---------- */
if (!function_exists('sp_json_read')) {
  function sp_json_read(string $p): array {
    if (!is_file($p)) return ['content' => ''];
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') return ['content' => ''];
    $j = json_decode($raw, true);
    if (is_array($j) && array_key_exists('content', $j)) return $j;
    // Back-compat: if file had plain text or different shape, keep it
    if (!is_array($j)) return ['content' => (string)$raw];
    // unknown object → stringify
    return ['content' => json_encode($j, JSON_UNESCAPED_SLASHES)];
  }
}
if (!function_exists('sp_json_write')) {
  function sp_json_write(string $p, array $data): bool {
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return (bool)@file_put_contents($p, $json, LOCK_EX);
  }
}

/* =======================================================================
 * 1) INCLUDED: Button + inline modal editor (no iframe, no headers)
 * ======================================================================= */
if (!$IS_DIRECT) {
  $uid     = substr(md5(__FILE__ . microtime(true)), 0, 8);
  $BTN_ID  = "spBtn_$uid";
  $MOD_ID  = "spModal_$uid";
  $TXT_ID  = "spText_$uid";
  $SAVE_ID = "spSave_$uid";
  $STAT_ID = "spStat_$uid";
  $THEME_ID= "spTheme_$uid";
  ?>
  <style>
    /* theme tokens */
    .sp-modal-root{ --bg:#0b1216; --bg2:#0f171c; --fg:#e6eef6; --muted:#9fb2c2; --line:#23343e; --accent:#3c82f6; }
    .sp-modal-root.light{ --bg:#f7f9fc; --bg2:#ffffff; --fg:#0b1216; --muted:#46535e; --line:#d8e0e6; --accent:#2b5fd6; }

    .sp-btn{appearance:none;border:1px solid #23343e;background:#121e26;color:#e6eef6;padding:7px 11px;border-radius:8px;cursor:pointer}
    .sp-btn:hover{background:#16232c}

    .sp-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:9998}
    .sp-modal.show{display:flex}
    .sp-modal .sp-overlay{position:absolute;inset:0;background:rgba(0,0,0,.65)}
    .sp-modal .sp-dialog{
      position:relative;width:min(1100px,96vw);height:min(85vh,96vh);
      background:var(--bg2);border:1px solid var(--line);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.55);
      display:flex;flex-direction:column;overflow:hidden
    }
    .sp-modal header{background:var(--bg);border-bottom:1px solid var(--line);padding:10px 12px;display:flex;align-items:center;gap:10px;justify-content:space-between}
    .sp-modal header h3{margin:0;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--muted)}
    .sp-head-actions{display:flex;gap:8px;align-items:center}
    .sp-switch{display:inline-flex;align-items:center;gap:6px;font:12px system-ui;color:var(--muted)}
    .sp-switch input{appearance:none;width:38px;height:20px;border-radius:999px;background:#34424d;position:relative;outline:none;cursor:pointer;border:1px solid var(--line)}
    .sp-switch input:after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#dfe8ef;transition:transform .18s ease}
    .sp-modal-root.light .sp-switch input{background:#b8c4cf}
    .sp-switch input:checked:after{ transform:translateX(18px); }
    .sp-close{appearance:none;background:transparent;color:#cbd6e2;border:0;font-size:18px;line-height:1;cursor:pointer;padding:4px 6px}
    .sp-modal .sp-body{flex:1;background:var(--bg)}
    .sp-modal textarea{
      width:100%;height:100%;display:block;box-sizing:border-box;
      padding:12px 14px;border:0;outline:none;resize:none;background:var(--bg);color:var(--fg);
      font:14px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    }
    .sp-modal footer{border-top:1px solid var(--line);background:var(--bg2);padding:8px 12px;display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px}
    .sp-save{appearance:none;border:1px solid var(--line);background:#17305c;color:#e6eef6;padding:6px 10px;border-radius:8px;cursor:pointer}
    .sp-save:hover{background:#1a376a}
  </style>

  <div class="sp-modal-root" id="spRoot_<?= htmlspecialchars($uid,ENT_QUOTES) ?>">
    <button class="sp-btn" id="<?= htmlspecialchars($BTN_ID, ENT_QUOTES) ?>" type="button">Open Scratch Pad</button>

    <div class="sp-modal" id="<?= htmlspecialchars($MOD_ID, ENT_QUOTES) ?>">
      <div class="sp-overlay" data-close="1"></div>
      <div class="sp-dialog">
        <header>
          <h3>Scratch Pad</h3>
          <div class="sp-head-actions">
            <label class="sp-switch" title="Toggle light theme">
              <span>Dark</span>
              <input type="checkbox" id="<?= htmlspecialchars($THEME_ID, ENT_QUOTES) ?>">
              <span>Light</span>
            </label>
            <button class="sp-close" type="button" data-close="1">×</button>
          </div>
        </header>
        <div class="sp-body">
          <textarea id="<?= htmlspecialchars($TXT_ID, ENT_QUOTES) ?>" spellcheck="false" placeholder="Type notes here…"></textarea>
        </div>
        <footer>
          <div id="<?= htmlspecialchars($STAT_ID, ENT_QUOTES) ?>">Loaded.</div>
          <button class="sp-save" id="<?= htmlspecialchars($SAVE_ID, ENT_QUOTES) ?>" type="button">Save (Ctrl/Cmd+S)</button>
        </footer>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const ROOT   = document.getElementById(<?= json_encode("spRoot_$uid") ?>);
    const BTN    = document.getElementById(<?= json_encode($BTN_ID) ?>);
    const MOD    = document.getElementById(<?= json_encode($MOD_ID) ?>);
    const TXT    = document.getElementById(<?= json_encode($TXT_ID) ?>);
    const SAVE   = document.getElementById(<?= json_encode($SAVE_ID) ?>);
    const STAT   = document.getElementById(<?= json_encode($STAT_ID) ?>);
    const THEME  = document.getElementById(<?= json_encode($THEME_ID) ?>);
    const ENDPT  = <?= json_encode($SELF_WEB) ?>; // absolute endpoint (fixes included-mode bug)

    // theme persistence (dark default)
    const key='ri_sp_theme';
    function applyTheme(v){ ROOT.classList.toggle('light', v==='light'); if(THEME) THEME.checked = (v==='light'); }
    applyTheme(localStorage.getItem(key)||'dark');
    if (THEME) THEME.addEventListener('change', ()=>{ const v=THEME.checked?'light':'dark'; localStorage.setItem(key,v); applyTheme(v); });

    function toast(level,msg){
      try{ if(window.adminToaster && adminToaster.push) adminToaster.push({level, msg}); }catch(e){}
    }

    async function openModal(){
      try{
        const r = await fetch(ENDPT, {headers:{'Accept':'application/json'}, cache:'no-store', credentials:'same-origin'});
        if (!r.ok) throw new Error(r.status + ' ' + r.statusText);
        const j = await r.json();
        if (j && typeof j.content === 'string') TXT.value = j.content;
        STAT.textContent = 'Loaded.';
      }catch(e){ STAT.textContent='Load failed.'; toast('error','Scratch Pad load failed'); }
      MOD.classList.add('show');
      try{ document.documentElement.style.overflow='hidden'; }catch(e){}
      setTimeout(()=>TXT && TXT.focus(), 50);
    }
    function closeModal(){
      MOD.classList.remove('show');
      try{ document.documentElement.style.overflow=''; }catch(e){}
    }

    let timer, dirty=false;
    async function save(now=false){
      clearTimeout(timer);
      const doSave = async () => {
        try{
          const r = await fetch(ENDPT, {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            credentials:'same-origin',
            body: JSON.stringify({content: TXT.value})
          });
          if (!r.ok) throw new Error(r.status + ' ' + r.statusText);
          const j = await r.json();
          if (j && j.ok){ STAT.textContent='Saved.'; toast('info','Scratch Pad saved'); dirty=false; }
          else { STAT.textContent='Save failed.'; toast('error','Scratch Pad failed to save'); }
        }catch(e){ STAT.textContent='Save error.'; toast('error','Scratch Pad error: ' + (e && e.message ? e.message : e)); }
      };
      if (now) return doSave();
      timer = setTimeout(doSave, 600);
    }

    BTN.addEventListener('click', openModal);
    MOD.addEventListener('click', (ev)=>{ if (ev.target && ev.target.getAttribute('data-close')==='1') closeModal(); });
    window.addEventListener('keydown', (ev)=>{
      if (ev.key==='Escape' && MOD.classList.contains('show')) closeModal();
      if ((ev.ctrlKey||ev.metaKey) && ev.key.toLowerCase()==='s' && MOD.classList.contains('show')){ ev.preventDefault(); save(true); }
    });
    TXT.addEventListener('input', ()=>{ dirty=true; STAT.textContent='Editing…'; save(); });
    SAVE.addEventListener('click', ()=>save(true));

    window.addEventListener('beforeunload', ()=>{
      if (!dirty || !TXT) return;
      try{
        const blob = new Blob([JSON.stringify({content: TXT.value})], {type:'application/json'});
        navigator.sendBeacon && navigator.sendBeacon(ENDPT, blob);
      }catch(e){}
    });
  })();
  </script>
  <?php
  return;
}

/* =======================================================================
 * 2) DIRECT WIDGET PAGE (optional; sets XFO/CSP for same-origin if framed)
 * ======================================================================= */
if ($WIDGET_MODE) {
  header('X-Frame-Options: SAMEORIGIN');
  header("Content-Security-Policy: frame-ancestors 'self'");
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  $data = sp_json_read($STORE);
  $content = (string)($data['content'] ?? '');
  ?><!doctype html><html><head><meta charset="utf-8"><title>Scratch Pad</title>
  <style>body{margin:0;background:#0b1216;color:#e6eef6;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
  .wrap{padding:16px;max-width:1100px;margin:0 auto}
  textarea{width:100%;min-height:60vh;padding:12px 14px;border:1px solid #23343e;border-radius:10px;background:#0f171c;color:#e6eef6;outline:none;resize:vertical}
  .row{display:flex;gap:10px;align-items:center;margin-top:10px;color:#9fb2c2}
  .btn{appearance:none;border:1px solid #23343e;background:#121e26;color:#e6eef6;padding:6px 10px;border-radius:8px;cursor:pointer}
  .btn:hover{background:#16232c}</style></head><body>
  <div class="wrap"><h1>Scratch Pad</h1>
  <textarea id="t" spellcheck="false"><?= htmlspecialchars($content,ENT_QUOTES,'UTF-8') ?></textarea>
  <div class="row"><button id="s" class="btn" type="button">Save</button><span id="st">Loaded.</span></div></div>
  <script>
  (function(){
    const t=document.getElementById('t'), s=document.getElementById('s'), st=document.getElementById('st'), ep=<?= json_encode($SELF_WEB) ?>;
    let timer;
    async function save(){ try{ const r=await fetch(ep,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({content:t.value})}); const j=await r.json(); st.textContent=j&&j.ok?'Saved.':'Save failed.'; }catch(e){ st.textContent='Save error.'; } }
    t.addEventListener('input',()=>{ st.textContent='Editing…'; clearTimeout(timer); timer=setTimeout(save,600); }); s.addEventListener('click',save);
  })();
  </script></body></html><?php
  exit;
}

/* =======================================================================
 * 3) DIRECT JSON API (no output before this point)
 * ======================================================================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($method === 'GET') {
  $data = sp_json_read($STORE);
  echo json_encode(['ok'=>true, 'content'=> (string)($data['content'] ?? '')], JSON_UNESCAPED_SLASHES);
  exit;
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input') ?: '';
  $content = '';
  $asJson = json_decode($raw, true);
  if (is_array($asJson) && array_key_exists('content', $asJson)) $content = (string)$asJson['content'];
  elseif (isset($_POST['content'])) $content = (string)$_POST['content'];
  else $content = $raw;
  $ok = sp_json_write($STORE, ['content'=>$content, 'updated_at'=>date('c')]);
  echo json_encode(['ok'=>$ok], JSON_UNESCAPED_SLASHES);
  exit;
}

http_response_code(405);
echo json_encode(['ok'=>false, 'error'=>'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
?>