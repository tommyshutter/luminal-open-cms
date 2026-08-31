<?php
/**
 * @appname  Earl’s CMS
 * @file     /admin/includes/admin-bg-widget.inc.php
 * @version  2025.09.24.1612.00-EST
 * @author   ChatGPT
 * @license  Business Source License
 * @description
 * Compact drag-drop + opacity slider for admin background image.
 * Now a collapsible panel with state remembered in localStorage.
 */

declare(strict_types=1);
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}
$API_URL = '/admin/modules/BackgroundManager/api/admin_bg_api.php';
?>
<div id="admin-bg-widget" style="font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#ddd; margin:10px 12px 6px 12px;">
  <!-- Header / toggle -->
  <button id="bg-toggle" type="button" aria-expanded="true" style="
      width:100%; display:flex; align-items:center; justify-content:space-between;
      background:#111; color:#ddd; border:1px solid #333; border-radius:8px; padding:6px 8px;
      font-size:12px; text-transform:uppercase; letter-spacing:.08em; cursor:pointer;">
    <span>Admin Background</span>
    <span id="bg-caret" style="font-size:12px; opacity:.8;">▾</span>
  </button>

  <div id="bg-content" style="margin-top:6px;">
    <div id="bg-drop" tabindex="0" aria-label="Drop image" style="
        width:150px; height:150px; border:1px dashed #444; border-radius:10px;
        display:flex; align-items:center; justify-content:center; cursor:pointer;
        background:#111; outline:none; position:relative; overflow:hidden;">
      <span id="bg-drop-hint" style="font-size:11px; color:#888; text-align:center; padding:8px; line-height:1.3;">
        Drop<br>image<br>here
      </span>
      <img id="bg-thumb" alt="" style="display:none; width:100%; height:100%; object-fit:cover;">
      <input id="bg-file" type="file" accept="image/*" style="display:none;">
    </div>

    <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
      <label for="bg-opacity" style="font-size:11px; color:#aaa; white-space:nowrap;">Overlay</label>
      <input id="bg-opacity" type="range" min="0.1" max="0.9" step="0.05" value="0.5" style="flex:1;">
      <span id="bg-opacity-val" style="font-size:11px; width:34px; text-align:right; color:#aaa;">0.50</span>
    </div>

    <div id="bg-status" role="status" style="margin-top:6px; font-size:11px; color:#7bd17b; display:none;">Saved</div>
    <div id="bg-error" role="alert" style="margin-top:6px; font-size:11px; color:#ff7676; display:none;"></div>
    <div id="bg-diag" style="margin-top:6px; font-size:10px; color:#999; display:none;"></div>
  </div>
</div>

<script>
(function(){
  const API = <?php echo json_encode($API_URL); ?>;
  const $toggle = document.getElementById('bg-toggle');
  const $caret  = document.getElementById('bg-caret');
  const $wrap   = document.getElementById('bg-content');

  const $drop  = document.getElementById('bg-drop');
  const $file  = document.getElementById('bg-file');
  const $hint  = document.getElementById('bg-drop-hint');
  const $thumb = document.getElementById('bg-thumb');
  const $rng   = document.getElementById('bg-opacity');
  const $rngVal= document.getElementById('bg-opacity-val');
  const $ok    = document.getElementById('bg-status');
  const $err   = document.getElementById('bg-error');
  const $diag  = document.getElementById('bg-diag');

  const LS_KEY = 'admin_bg_widget_collapsed';

  function setCollapsed(collapsed){
    if(collapsed){ $wrap.style.display='none'; $toggle.setAttribute('aria-expanded','false'); $caret.textContent='▸'; }
    else{ $wrap.style.display='block'; $toggle.setAttribute('aria-expanded','true'); $caret.textContent='▾'; }
    try{ localStorage.setItem(LS_KEY, collapsed ? '1':'0'); }catch(e){}
  }

  // init collapsed state
  try{ setCollapsed(localStorage.getItem(LS_KEY) === '1'); }catch(e){ setCollapsed(false); }
  $toggle.addEventListener('click', ()=> setCollapsed($wrap.style.display!=='none'));

  function show(el,msg){ el.textContent=msg; el.style.display='block'; }
  function hide(el){ el.style.display='none'; }
  function showSaved(msg='Saved'){ show($ok,msg); setTimeout(()=>hide($ok),1400); }
  function showError(msg){ show($err,msg); setTimeout(()=>hide($err),3500); }
  function setThumb(url){ if(!url){ $thumb.style.display='none'; $hint.style.display='block'; return; } $thumb.src=url; $thumb.onload=()=>{ $thumb.style.display='block'; $hint.style.display='none'; }; }

  async function apiGet(){
    try{ const r=await fetch(API+'?t='+Date.now(),{credentials:'same-origin'}); const t=await r.text(); let j=null; try{ j=JSON.parse(t);}catch(e){} if(!r.ok||!j){ showError('API GET '+r.status+': '+t.slice(0,120)); return null; } return j; }
    catch(e){ showError('API GET error: '+e); return null; }
  }
  async function apiPost(body,isJson){
    try{ const r=await fetch(API,{method:'POST',credentials:'same-origin',headers:isJson?{'Content-Type':'application/json'}:undefined,body}); const t=await r.text(); let j=null; try{ j=JSON.parse(t);}catch(e){} if(!r.ok||!j){ showError('API POST '+r.status+': '+t.slice(0,120)); return null; } return j; }
    catch(e){ showError('API POST error: '+e); return null; }
  }

  async function loadCurrent(){
    const j=await apiGet(); if(!j) return; hide($err);
    if(j.url) setThumb(j.url);
    if(j.opacity){ $rng.value=j.opacity; $rngVal.textContent=(+j.opacity).toFixed(2); }
    if(j.diag){ $diag.style.display='block'; $diag.textContent=j.diag; }
    window.dispatchEvent(new CustomEvent('admin-bg-updated',{detail:j}));
  }
  async function saveOpacity(){
    const j=await apiPost(JSON.stringify({opacity:$rng.value}),true); if(!j) return;
    showSaved('Opacity saved'); window.dispatchEvent(new CustomEvent('admin-bg-updated',{detail:j}));
  }
  async function uploadFile(file){
    const fd=new FormData(); fd.append('file',file); fd.append('opacity',$rng.value);
    const j=await apiPost(fd,false); if(!j) return; if(j.url) setThumb(j.url);
    showSaved('Background updated'); window.dispatchEvent(new CustomEvent('admin-bg-updated',{detail:j}));
  }

  $drop.addEventListener('click',()=> $file.click());
  $drop.addEventListener('keypress',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); $file.click(); }});
  $drop.addEventListener('dragover',e=>{ e.preventDefault(); $drop.style.borderColor='#777'; });
  $drop.addEventListener('dragleave',()=>{ $drop.style.borderColor='#444'; });
  $drop.addEventListener('drop',e=>{ e.preventDefault(); $drop.style.borderColor='#444'; const f=e.dataTransfer?.files?.[0]; if(f) uploadFile(f); else showError('No file detected.'); });
  $file.addEventListener('change',()=>{ if($file.files?.[0]) uploadFile($file.files[0]); });

  $rng.addEventListener('input',()=>{ $rngVal.textContent=(+$rng.value).toFixed(2); });
  $rng.addEventListener('change',saveOpacity);

  loadCurrent();
})();
</script>
