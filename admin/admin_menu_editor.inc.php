<?php
/**
 * Admin Menu Editor (panel for Menu Manager)
 * @file /admin/admin_menu_editor.inc.php
 * UI: simple JSON editor with validate + save (no redesign, just a card-like block)
 */
if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__));
?>
<style>
  .am-card{ border:1px solid #23343e; background:#0f171c; border-radius:12px; margin:12px 0; overflow:hidden; }
  .am-card h2{ margin:0; padding:10px 12px; color:#9fb2c2; font-size:13px; border-bottom:1px solid #23343e; }
  .am-card .body{ padding:12px; }
  .am-text{ width:100%; min-height:280px; padding:12px 14px; border:1px solid #23343e; border-radius:10px; background:#0b1216; color:#e6eef6; outline:none; resize:vertical; font:13px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
  .am-row{ display:flex; gap:8px; align-items:center; margin-top:10px; }
  .am-btn{ appearance:none; border:1px solid #23343e; background:#121e26; color:#e6eef6; padding:7px 11px; border-radius:8px; cursor:pointer; }
  .am-btn:hover{ background:#16232c; }
  .am-status{ font-size:12px; color:#9fb2c2; }
  .am-help{ font-size:12px; color:#9fb2c2; margin-top:8px; }
</style>
<section class="am-card">
  <h2>Admin Menu (Editor)</h2>
  <div class="body">
    <textarea id="am-json" class="am-text" spellcheck="false" placeholder='{"items":[ ... ]}'></textarea>
    <div class="am-row">
      <button class="am-btn" id="am-validate" type="button">Validate</button>
      <button class="am-btn" id="am-save" type="button">Save</button>
      <span class="am-status" id="am-status">Ready.</span>
    </div>
    <div class="am-help">
      <p><strong>Format</strong>: <code>{"items":[{ "label":"...", "url":"/admin/...php", "key":"...php", "color":"#hex", "children":[...] }, { "type":"separator", "label":"Section" }]}</code></p>
      <p>Colors are optional. Use <code>"type":"separator"</code> for section headings. Nest items under <code>"children"</code> for sub-menus.</p>
    </div>
  </div>
</section>
<script>
(function(){
  const EP = '/admin/modules/MenuManager/api/admin_menu_config.php';
  const ta = document.getElementById('am-json');
  const st = document.getElementById('am-status');
  const v  = document.getElementById('am-validate');
  const s  = document.getElementById('am-save');

  function msg(m){ st.textContent = m; }
  function toast(level,msg){ try{ if(window.adminToaster) adminToaster.push({level, msg}); }catch(e){} }

  async function load(){
    try{
      const r = await fetch(EP, {cache:'no-store'});
      const j = await r.json();
      if (j && j.ok && j.data) ta.value = JSON.stringify(j.data, null, 2);
      msg('Loaded.');
    }catch(e){ msg('Load failed.'); }
  }

  v.addEventListener('click', ()=>{
    try{ JSON.parse(ta.value); msg('Valid JSON.'); toast('success','Admin menu JSON is valid'); }
    catch(e){ msg('Invalid JSON: ' + e.message); toast('error','Invalid JSON'); }
  });

  s.addEventListener('click', async ()=>{
    let data;
    try{ data = JSON.parse(ta.value); }
    catch(e){ msg('Fix JSON before saving.'); toast('error','Fix JSON before saving'); return; }
    try{
      const r = await fetch(EP, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)});
      const j = await r.json();
      if (j && j.ok){ msg('Saved. Refresh to see changes.'); toast('info','Admin menu saved'); }
      else { msg('Save failed.'); toast('error','Save failed'); }
    }catch(e){ msg('Save error.'); toast('error','Save error'); }
  });

  load();
})();
</script>
