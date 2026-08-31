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
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$STORE = SITE_ROOT . '/admin/data/page-manager-scratch-pad.json';
$SELF_WEB = '/admin/modules/PageManager/api/' . basename(__FILE__);

/* ---------- ScratchPad FILES (drop-zone) — PRIVATE per-user store ----------
 * Lifecycle (all private, admin/data 403 — the public web never sees these):
 *   Active (admin/data/ScratchPad/{user}/) → 5 days idle → _trash (recoverable)
 *   _trash → 5 more days → permanently deleted. Both stages run lazily on open.
 * The ONLY public path is the deliberate "Send to Media" promote → media/images. */
$SP_USER = 'shared';
foreach (['user_id', 'luminal_user', 'username', 'user'] as $__k) {
  if (!empty($_SESSION[$__k])) { $SP_USER = (string)$_SESSION[$__k]; break; }
}
$SP_USER       = preg_replace('/[^A-Za-z0-9_-]/', '', $SP_USER) ?: 'shared';
$SP_DIR        = SITE_ROOT . '/admin/data/ScratchPad/' . $SP_USER;   // active drops (private)
$SP_TRASH      = $SP_DIR . '/_trash';                                // trashed (private, recoverable)
$SP_MEDIA      = SITE_ROOT . '/media/images/scratchpad-saved';       // deliberate promote target (public)
$SP_EVICT_DAYS = 5;                                                  // active → trash
$SP_PURGE_DAYS = 5;                                                  // trash → permanent delete
$SP_MAX_BYTES  = 25 * 1024 * 1024;                                   // 25 MB / file
$SP_OK_EXT = ['png','jpg','jpeg','gif','webp','bmp','svg','pdf','txt','md','csv','json','doc','docx','xls','xlsx','zip'];

if (!function_exists('sp_slug')) {
  function sp_slug(string $n): string { return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $n), '-.') ?: 'file'; }
}
if (!function_exists('sp_kind')) {
  function sp_kind(string $ext): string {
    return in_array(strtolower($ext), ['png','jpg','jpeg','gif','webp','bmp','svg'], true) ? 'image' : 'doc';
  }
}
if (!function_exists('sp_list_dir')) {
  function sp_list_dir(string $dir, string $endpoint, string $bucket): array {
    $out = [];
    foreach (glob($dir . '/*') ?: [] as $f) {
      if (!is_file($f)) continue;
      $b = basename($f); $ext = strtolower(pathinfo($b, PATHINFO_EXTENSION));
      $out[] = [
        'name' => $b, 'size' => filesize($f), 'mtime' => filemtime($f),
        'kind' => sp_kind($ext), 'ext' => $ext, 'bucket' => $bucket,
        'url'  => $endpoint . '?action=sp_file&bucket=' . $bucket . '&name=' . rawurlencode($b),
      ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
  }
}

/* ---------- include vs direct ---------- */
$IS_DIRECT   = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__));
$WIDGET_MODE = isset($_GET['mode']) && $_GET['mode'] === 'widget';

/* ---------- auth for direct ---------- */
if ($IS_DIRECT) {
  @require_once dirname(__DIR__, 3) . '/auth.php';
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

    .sp-modal-root .sp-btn{appearance:none;border:1px solid #23343e;background:#121e26;color:#e6eef6;padding:7px 11px;border-radius:8px;cursor:pointer}
    .sp-modal-root .sp-btn:hover{background:#16232c}

    .sp-modal-root .sp-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:100000}
    .sp-modal-root .sp-modal.show{display:flex}
    .sp-modal-root .sp-modal .sp-overlay{position:absolute;inset:0;background:rgba(0,0,0,.65)}
    .sp-modal-root .sp-modal .sp-dialog{
      position:relative;width:min(1100px,96vw);height:min(85vh,96vh);
      background:var(--bg2);border:1px solid var(--line);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.55);
      display:flex;flex-direction:column;overflow:hidden
    }
    .sp-modal-root .sp-modal header{position:relative;background:var(--bg);border-bottom:1px solid var(--line);padding:10px 12px;display:flex;align-items:center;gap:10px;justify-content:space-between}
    .sp-modal-root .sp-clear{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);appearance:none;border:1px solid rgba(240,165,0,.55);background:linear-gradient(180deg,#f5b21f,#d98c00);color:#241800;font:700 12px/1 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;letter-spacing:.02em;padding:7px 18px;border-radius:999px;cursor:pointer;box-shadow:0 2px 10px rgba(240,165,0,.38);white-space:nowrap;z-index:1}
    .sp-modal-root .sp-clear:hover{filter:brightness(1.08)}
    .sp-modal-root .sp-clear:active{transform:translate(-50%,-50%) scale(.95)}
    .sp-modal-root .sp-modal header h3{margin:0;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--muted)}
    .sp-modal-root .sp-head-actions{display:flex;gap:8px;align-items:center}
    .sp-modal-root .sp-switch{display:inline-flex;align-items:center;gap:6px;font:12px system-ui;color:var(--muted)}
    .sp-modal-root .sp-switch input{appearance:none;width:38px;height:20px;border-radius:999px;background:#34424d;position:relative;outline:none;cursor:pointer;border:1px solid var(--line)}
    .sp-modal-root .sp-switch input:after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#dfe8ef;transition:transform .18s ease}
    .sp-modal-root.light .sp-switch input{background:#b8c4cf}
    .sp-modal-root .sp-switch input:checked:after{ transform:translateX(18px); }
    .sp-modal-root .sp-close{appearance:none;background:transparent;color:#cbd6e2;border:0;font-size:18px;line-height:1;cursor:pointer;padding:4px 6px}
    .sp-modal-root .sp-modal .sp-body{flex:1;background:var(--bg)}
    .sp-modal-root .sp-modal textarea{
      width:100%;height:100%;display:block;box-sizing:border-box;
      padding:12px 14px;border:0;outline:none;resize:none;background:var(--bg);color:var(--fg);
      font:14px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    }
    .sp-modal-root .sp-modal footer{border-top:1px solid var(--line);background:var(--bg2);padding:8px 12px;display:flex;align-items:center;justify-content:space-between;color:var(--muted);font-size:12px}
    .sp-modal-root .sp-save{appearance:none;border:1px solid var(--line);background:#17305c;color:#e6eef6;padding:6px 10px;border-radius:8px;cursor:pointer}
    .sp-modal-root .sp-save:hover{background:#1a376a}

    /* ---- drop-zone + file strip ---- */
    .sp-modal-root .sp-files{border-top:1px solid var(--line);background:var(--bg2);max-height:320px;overflow:auto;padding:10px 12px;display:flex;flex-direction:column;gap:9px}
    .sp-modal-root .sp-drop{border:2px dashed var(--line);border-radius:12px;min-height:96px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:16px;text-align:center;color:var(--muted);font-size:13px;line-height:1.4;cursor:pointer;transition:border-color .12s,background .12s,color .12s}
    .sp-modal-root .sp-drop .sp-drop-icon{font-size:26px;line-height:1;opacity:.85;transition:transform .12s,opacity .12s}
    .sp-modal-root .sp-drop:hover,.sp-modal-root .sp-drop.drag{border-color:#22d3ee;background:rgba(56,189,248,.08);color:var(--fg)}
    .sp-modal-root .sp-drop.drag .sp-drop-icon{opacity:1;transform:translateY(-2px) scale(1.08)}
    .sp-modal-root .sp-files-hd{display:flex;align-items:center;justify-content:space-between;font:700 11px/1.2 system-ui;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
    .sp-modal-root .sp-files-hd button{appearance:none;border:1px solid var(--line);background:transparent;color:var(--muted);font-size:10px;border-radius:5px;padding:2px 8px;cursor:pointer}
    .sp-modal-root .sp-files-hd button:hover{color:var(--fg);border-color:var(--accent)}
    .sp-modal-root .sp-strip{display:flex;flex-wrap:wrap;gap:8px}
    .sp-modal-root .sp-tile{position:relative;width:96px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--bg);display:flex;flex-direction:column}
    .sp-modal-root .sp-tile a{display:block;line-height:0}
    .sp-modal-root .sp-thumb{width:96px;height:70px;object-fit:cover;display:block;background:rgba(0,0,0,.25)}
    .sp-modal-root .sp-doc{width:96px;height:70px;display:flex;align-items:center;justify-content:center;font:700 12px system-ui;color:var(--muted);text-transform:uppercase}
    .sp-modal-root .sp-thumb{cursor:zoom-in}
    /* Lightbox overlay (mounts on <body>, so NOT scoped to .sp-modal-root) */
    .sp-lb{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.82);backdrop-filter:blur(3px);cursor:zoom-out}
    .sp-lb-inner{display:flex;flex-direction:column;align-items:center;gap:10px;cursor:default}
    .sp-lb-inner img{max-width:92vw;max-height:82vh;object-fit:contain;border-radius:8px;box-shadow:0 20px 60px rgba(0,0,0,.6);background:#111}
    .sp-lb-cap{font:600 12px system-ui;color:#cbd5e1;max-width:92vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .sp-lb-x{position:fixed;top:14px;right:20px;background:none;border:0;color:#fff;font-size:34px;line-height:1;cursor:pointer;opacity:.85}
    .sp-lb-x:hover{opacity:1}
    .sp-modal-root .sp-fn{font-size:10px;color:var(--muted);padding:3px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sp-modal-root .sp-acts{position:absolute;top:2px;right:2px;display:none;gap:2px}
    .sp-modal-root .sp-tile:hover .sp-acts{display:flex}
    .sp-modal-root .sp-acts button{appearance:none;border:0;border-radius:4px;background:rgba(0,0,0,.62);color:#fff;font-size:11px;line-height:1;padding:3px 5px;cursor:pointer}
    .sp-modal-root .sp-acts button:hover{background:var(--accent)}
    .sp-modal-root .sp-trash-wrap{display:none;opacity:.85}
    .sp-modal-root .sp-trash-wrap.show{display:flex}
  </style>

  <div class="sp-modal-root" id="spRoot_<?= htmlspecialchars($uid,ENT_QUOTES) ?>">
    <button class="sp-btn" id="<?= htmlspecialchars($BTN_ID, ENT_QUOTES) ?>" type="button">Open Scratch Pad</button>

    <div class="sp-modal" id="<?= htmlspecialchars($MOD_ID, ENT_QUOTES) ?>">
      <div class="sp-overlay" data-close="1"></div>
      <div class="sp-dialog">
        <header>
          <h3>Scratch Pad</h3>
          <button class="sp-clear" id="spClear_<?= $uid ?>" type="button" title="Clear all notes on the pad">🧹 Clear the Board</button>
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
        <div class="sp-files">
          <div class="sp-drop" id="spDrop_<?= $uid ?>">
            <span class="sp-drop-icon">📎</span>
            <span>Drop images or files here, or paste from the clipboard<br><b>— or click to browse —</b></span>
            <input type="file" id="spInput_<?= $uid ?>" multiple hidden>
          </div>
          <div class="sp-files-hd"><span>Files</span><span id="spCount_<?= $uid ?>"></span></div>
          <div class="sp-strip" id="spStrip_<?= $uid ?>"></div>
          <div class="sp-files-hd" id="spTrashHd_<?= $uid ?>" style="display:none">
            <span>🗑 Trash <span id="spTrashCount_<?= $uid ?>"></span> · <small style="font-weight:400;text-transform:none">auto-deletes after 5 days</small></span>
            <button type="button" id="spEmpty_<?= $uid ?>">Empty now</button>
          </div>
          <div class="sp-strip sp-trash-wrap" id="spTrash_<?= $uid ?>"></div>
        </div>
        <footer>
          <div id="<?= htmlspecialchars($STAT_ID, ENT_QUOTES) ?>">Loaded.</div>
          <button class="sp-save" id="<?= htmlspecialchars($SAVE_ID, ENT_QUOTES) ?>" type="button" data-cms-save>Save (Ctrl/Cmd+S)</button>
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

    // Escape the rail's backdrop-filter/transform jail: teleport the whole widget to <body>
    // so the fixed modal positions against the viewport instead of being caged in the rail.
    if (ROOT && ROOT.parentNode !== document.body) document.body.appendChild(ROOT);

    // theme persistence (dark default)
    const key='ri_sp_theme';
    function applyTheme(v){ ROOT.classList.toggle('light', v==='light'); if(THEME) THEME.checked = (v==='light'); }
    applyTheme(localStorage.getItem(key)||'dark');
    if (THEME) THEME.addEventListener('change', ()=>{ const v=THEME.checked?'light':'dark'; localStorage.setItem(key,v); applyTheme(v); });

    function toast(level,msg){
      try{ if(window.adminToaster && adminToaster.push) adminToaster.push({level, msg}); }catch(e){}
    }

    async function loadText(){
      try{
        const r = await fetch(ENDPT, {headers:{'Accept':'application/json'}, cache:'no-store', credentials:'same-origin'});
        if (!r.ok) throw new Error(r.status + ' ' + r.statusText);
        const j = await r.json();
        if (j && typeof j.content === 'string') TXT.value = j.content;
        STAT.textContent = 'Loaded.';
      }catch(e){ STAT.textContent='Load failed.'; toast('error','Scratch Pad load failed'); }
    }
    function hydrate(){ loadText(); loadFiles(); }   // loadFiles is hoisted from the files block
    function openModal(){
      MOD.classList.add('show');
      try{ document.documentElement.style.overflow='hidden'; }catch(e){}
    }
    function closeModal(){
      MOD.classList.remove('show');
      try{ document.documentElement.style.overflow=''; }catch(e){}
    }
    // Hydrate whenever the pad OPENS — however it's triggered. The rail button adds .show
    // directly (bypassing openModal), so without this the text + files render empty after a
    // hard refresh even though everything is safe on the server.
    var _spHydrated = false;
    new MutationObserver(function(){
      if (MOD.classList.contains('show')) {
        if (!_spHydrated) { _spHydrated = true; hydrate(); setTimeout(function(){ TXT && TXT.focus(); }, 60); }
      } else { _spHydrated = false; }
    }).observe(MOD, {attributes:true, attributeFilter:['class']});

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
      // Ctrl/Cmd+S handled globally by save-hotkey.js → clicks [data-cms-save] (the Save button).
    });
    TXT.addEventListener('input', ()=>{ dirty=true; STAT.textContent='Editing…'; save(); });
    SAVE.addEventListener('click', ()=>save(true));

    /* ---- ScratchPad FILES (drop-zone) ---- */
    const UID = <?= json_encode($uid) ?>;
    const el = (p)=>document.getElementById(p+UID);
    const DROP=el('spDrop_'), INPUT=el('spInput_'), STRIP=el('spStrip_'), COUNT=el('spCount_'),
          TRASH=el('spTrash_'), TRASHHD=el('spTrashHd_'), TRASHCOUNT=el('spTrashCount_'), EMPTY=el('spEmpty_');
    function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    function fbytes(n){return n<1024?n+' B':n<1048576?(n/1024).toFixed(0)+' KB':(n/1048576).toFixed(1)+' MB';}
    function tile(f){
      const thumb=f.kind==='image'?'<img class="sp-thumb" src="'+esc(f.url)+'" alt="" loading="lazy">':'<div class="sp-doc">'+esc(f.ext||'file')+'</div>';
      const primary=f.bucket==='trash'?'<button data-act="restore" title="Restore to files">↩</button>':'<button data-act="promote" title="Send to Media Library">📤</button>';
      const del=f.bucket==='trash'?'<button data-act="del" title="Delete permanently">✕</button>':'<button data-act="trash" title="Move to trash">✕</button>';
      const link = f.kind==='image'
        ? '<a href="'+esc(f.url)+'" data-lightbox="1">'+thumb+'</a>'   // in-page lightbox
        : '<a href="'+esc(f.url)+'" target="_blank" rel="noopener">'+thumb+'</a>';
      return '<div class="sp-tile" data-name="'+esc(f.name)+'" data-bucket="'+esc(f.bucket)+'" title="'+esc(f.name)+' · '+fbytes(f.size)+'">'
        +link
        +'<div class="sp-fn">'+esc(f.name)+'</div>'
        +'<div class="sp-acts">'+primary+del+'</div></div>';
    }
    async function loadFiles(){
      try{
        const r=await fetch(ENDPT+'?action=sp_list',{credentials:'same-origin',cache:'no-store'});
        const j=await r.json(); if(!j||!j.ok) return;
        const a=j.active||[], tr=j.trash||[];
        STRIP.innerHTML=a.length?a.map(tile).join(''):'<span style="color:var(--muted);font-size:12px">No files yet — drop one above.</span>';
        COUNT.textContent=a.length?a.length+' file(s)':'';
        TRASH.innerHTML=tr.map(tile).join('');
        TRASHHD.style.display=tr.length?'flex':'none'; TRASH.classList.toggle('show',tr.length>0);
        TRASHCOUNT.textContent='('+tr.length+')';
        if(j.just_evicted) toast('info',j.just_evicted+' file(s) evicted from ScratchPad → Trash');
        if(j.just_purged) toast('info',j.just_purged+' old trash file(s) permanently deleted');
      }catch(e){}
    }
    function uploadFiles(list){
      if(!list||!list.length) return;
      STAT.textContent='Uploading '+list.length+' file(s)…';
      // LumUpload — the shared per-file progress meter (Explorer/GalleryManager use it too).
      if(window.LumUpload && window.LumUpload.uploadEach){
        window.LumUpload.uploadEach({
          url: ENDPT+'?action=sp_upload',
          files: list,
          field: 'files[]',
          okWhen: function(res,st){ return st>=200 && st<300 && res && res.ok; },
          onAllDone: function(results){
            var okc=(results||[]).filter(function(r){ return r && r.ok; }).length;
            STAT.textContent = okc ? ('Added '+okc+' file(s).') : 'Upload failed.';
            loadFiles();
          }
        });
        return;
      }
      // Fallback (meter unavailable): one multipart fetch, no per-file bar.
      const fd=new FormData(); [...list].forEach(f=>fd.append('files[]',f));
      fetch(ENDPT+'?action=sp_upload',{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json()).then(j=>{
          STAT.textContent=(j&&j.saved&&j.saved.length)?('Added '+j.saved.length+' file(s).'):'Upload failed.';
          if(j&&j.errors&&j.errors.length) toast('error','ScratchPad: '+j.errors.join('; '));
          loadFiles();
        }).catch(()=>{ STAT.textContent='Upload error.'; });
    }
    async function fileAct(act,name,bucket){
      const map={trash:'sp_trash',del:'sp_delete',restore:'sp_restore',promote:'sp_promote'};
      if(!map[act]) return;
      // No confirm gate — active ✕ moves to Trash (recoverable); Trash ✕ is permanent.
      try{
        const r=await fetch(ENDPT,{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:new URLSearchParams({action:map[act],name:name,bucket:bucket})});
        const j=await r.json();
        if(act==='promote'&&j&&j.ok) toast('info','Sent to Media Library');
        loadFiles();
      }catch(e){}
    }
    if(DROP){
      DROP.addEventListener('click',()=>INPUT&&INPUT.click());
      if(INPUT) INPUT.addEventListener('change',()=>uploadFiles(INPUT.files));
      ['dragenter','dragover'].forEach(ev=>DROP.addEventListener(ev,e=>{e.preventDefault();DROP.classList.add('drag');}));
      ['dragleave','dragend'].forEach(ev=>DROP.addEventListener(ev,e=>{e.preventDefault();DROP.classList.remove('drag');}));
      DROP.addEventListener('drop',e=>{e.preventDefault();DROP.classList.remove('drag');if(e.dataTransfer&&e.dataTransfer.files)uploadFiles(e.dataTransfer.files);});
    }
    MOD.addEventListener('paste',e=>{
      const items=e.clipboardData&&e.clipboardData.items; if(!items) return;
      const files=[]; for(const it of items){ if(it.kind==='file'){ const f=it.getAsFile(); if(f) files.push(f); } }
      if(files.length){ e.preventDefault(); uploadFiles(files); }
    });
    function openLightbox(url,name){
      const ov=document.createElement('div'); ov.className='sp-lb';
      ov.innerHTML='<button class="sp-lb-x" type="button" aria-label="Close">&times;</button>'
        +'<div class="sp-lb-inner"><img src="'+esc(url)+'" alt=""><div class="sp-lb-cap">'+esc(name||'')+'</div></div>';
      const close=()=>{ ov.remove(); document.removeEventListener('keydown',onk); };
      const onk=(ev)=>{ if(ev.key==='Escape') close(); };
      ov.addEventListener('click',ev=>{ if(!ev.target.closest('.sp-lb-inner') || ev.target.closest('.sp-lb-x')) close(); });
      document.addEventListener('keydown',onk);
      document.body.appendChild(ov);
    }
    [STRIP,TRASH].forEach(box=>{ if(!box) return; box.addEventListener('click',e=>{
      const lb=e.target.closest('a[data-lightbox]');   // image thumbnail → in-page lightbox (not a new tab)
      if(lb){ e.preventDefault(); const t0=e.target.closest('.sp-tile'); openLightbox(lb.getAttribute('href'), t0?t0.getAttribute('data-name'):''); return; }
      const b=e.target.closest('button[data-act]'); if(!b) return;
      const t=e.target.closest('.sp-tile'); if(!t) return; e.preventDefault();
      fileAct(b.getAttribute('data-act'),t.getAttribute('data-name'),t.getAttribute('data-bucket'));
    }); });
    if(EMPTY) EMPTY.addEventListener('click',async()=>{
      if(!confirm('Permanently delete all trashed files?')) return;
      await fetch(ENDPT+'?action=sp_empty_trash',{method:'POST',credentials:'same-origin'});
      loadFiles();
    });
    // (Files + text now hydrate via the MutationObserver on modal open — see above.)

    // Clear the Board — wipes the NOTES only (files keep their own delete/auto-evict).
    const CLEAR = el('spClear_');
    if (CLEAR) CLEAR.addEventListener('click', ()=>{
      if ((TXT.value||'').trim() && !confirm('Clear all notes on the scratch pad?\n(Your files are not affected.)')) return;
      TXT.value=''; dirty=false; save(true); STAT.textContent='Board cleared.';
      try{ if(window.adminToaster&&adminToaster.push) adminToaster.push({level:'info',msg:'Board cleared'}); }catch(_){}
      TXT.focus();
    });

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

/* ---- ScratchPad FILE actions (drop-zone). Text-content GET/POST fall through below. ---- */
$SP_ACTION = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($SP_ACTION !== '') {
  @mkdir($SP_DIR, 0775, true); @mkdir($SP_TRASH, 0775, true);
  $sp_bucket_dir = fn($b) => ($b === 'trash') ? $SP_TRASH : $SP_DIR;

  // Serve a PRIVATE file inline (auth already required above) — thumbs/previews for the strip.
  if ($SP_ACTION === 'sp_file') {
    $dir  = $sp_bucket_dir($_GET['bucket'] ?? 'active');
    $name = basename((string)($_GET['name'] ?? ''));
    $path = $dir . '/' . $name;
    if ($name === '' || !is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
              'bmp'=>'image/bmp','svg'=>'image/svg+xml','pdf'=>'application/pdf','txt'=>'text/plain','md'=>'text/plain',
              'csv'=>'text/csv','json'=>'application/json'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
  }

  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  if ($SP_ACTION === 'sp_list') {
    // Stage 1 — evict active drops older than N days → trash (moved, recoverable).
    $evictCut = time() - $SP_EVICT_DAYS * 86400; $justEvicted = 0;
    foreach (glob($SP_DIR . '/*') ?: [] as $f) {
      if (is_file($f) && filemtime($f) < $evictCut && @rename($f, $SP_TRASH . '/' . basename($f))) $justEvicted++;
    }
    // Stage 2 — permanently delete trash older than N days (only stage that destroys).
    $purgeCut = time() - $SP_PURGE_DAYS * 86400; $justPurged = 0;
    foreach (glob($SP_TRASH . '/*') ?: [] as $f) {
      if (is_file($f) && filemtime($f) < $purgeCut && @unlink($f)) $justPurged++;
    }
    echo json_encode(['ok'=>true, 'just_evicted'=>$justEvicted, 'just_purged'=>$justPurged,
      'evict_days'=>$SP_EVICT_DAYS, 'purge_days'=>$SP_PURGE_DAYS,
      'active'=> sp_list_dir($SP_DIR, $SELF_WEB, 'active'),
      'trash' => sp_list_dir($SP_TRASH, $SELF_WEB, 'trash')], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_upload' && $method === 'POST') {
    $saved = []; $errs = [];
    $files = $_FILES['files'] ?? null;
    if ($files && is_array($files['name'] ?? null)) {
      for ($i = 0, $n = count($files['name']); $i < $n; $i++) {
        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { $errs[] = 'upload error'; continue; }
        $orig = (string)$files['name'][$i];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $SP_OK_EXT, true)) { $errs[] = "type .$ext not allowed"; continue; }
        if (($files['size'][$i] ?? 0) > $SP_MAX_BYTES) { $errs[] = "$orig too big"; continue; }
        $fn = sp_slug(pathinfo($orig, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        if (@move_uploaded_file($files['tmp_name'][$i], $SP_DIR . '/' . $fn)) $saved[] = $fn;
        else $errs[] = "save failed: $orig";
      }
    }
    echo json_encode(['ok'=>count($saved) > 0, 'saved'=>$saved, 'errors'=>$errs], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_trash' && $method === 'POST') {    // active → _trash (soft, recoverable)
    $name = basename((string)($_POST['name'] ?? ''));
    $src = $SP_DIR . '/' . $name; $dest = $SP_TRASH . '/' . $name;
    echo json_encode(['ok'=> ($name !== '' && is_file($src)) ? @rename($src, $dest) : false], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_delete' && $method === 'POST') {   // hard-delete one file (active or trash)
    $dir = $sp_bucket_dir($_POST['bucket'] ?? 'active');
    $name = basename((string)($_POST['name'] ?? ''));
    $path = $dir . '/' . $name;
    echo json_encode(['ok'=> ($name !== '' && is_file($path)) ? @unlink($path) : false], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_restore' && $method === 'POST') {  // trash → active
    $name = basename((string)($_POST['name'] ?? ''));
    $src = $SP_TRASH . '/' . $name; $dest = $SP_DIR . '/' . $name;
    echo json_encode(['ok'=> ($name !== '' && is_file($src)) ? @rename($src, $dest) : false], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_empty_trash' && $method === 'POST') {
    $n = 0; foreach (glob($SP_TRASH . '/*') ?: [] as $f) { if (is_file($f) && @unlink($f)) $n++; }
    echo json_encode(['ok'=>true, 'purged'=>$n], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($SP_ACTION === 'sp_promote' && $method === 'POST') {  // Send to Media Library — deliberate, the only public path
    $dir = $sp_bucket_dir($_POST['bucket'] ?? 'active');
    $name = basename((string)($_POST['name'] ?? ''));
    $src = $dir . '/' . $name;
    if ($name === '' || !is_file($src)) { echo json_encode(['ok'=>false, 'error'=>'not found']); exit; }
    @mkdir($SP_MEDIA, 0775, true);
    $dest = $SP_MEDIA . '/' . $name; $i = 1;
    while (is_file($dest)) {
      $dest = $SP_MEDIA . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . ($i++) . '.' . pathinfo($name, PATHINFO_EXTENSION);
    }
    echo json_encode(['ok'=> @rename($src, $dest), 'media'=>'/media/images/scratchpad-saved/' . basename($dest)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'unknown action'], JSON_UNESCAPED_SLASHES);
  exit;
}

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