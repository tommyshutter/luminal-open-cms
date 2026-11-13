<?php
/**
 * Media Thumb Health (PDFs set aside: counted, never missing)
 * @file   /admin/media-thumb-health.php
 * @since  2025-09-06
 */

declare(strict_types=1);
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/includes/thumbs.php';

function ri_admin_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$artistName = ri_admin_h($_SERVER['HTTP_HOST'] ?? 'Site');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Media Thumb Health</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/admin/css/admin.css">
  <link rel="stylesheet" href="/admin/css/admin-styles.css">
  <style>
    .admin-container{ max-width:1200px; margin:0 auto; padding:16px;}
    .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:16px 0;}
    .kpi{ background:#0b0f19; border:1px solid #1f2937; border-radius:12px; padding:14px;}
    .kpi h3{ margin:0 0 6px; font-size:1rem; color:#94a3b8;}
    .kpi .num{ font-size:1.6rem; font-weight:700;}
    .toolbar{ display:flex; gap:10px; align-items:center; margin:10px 0 18px; flex-wrap:wrap;}
    .btn{ padding:8px 12px; border-radius:8px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; cursor:pointer;}
    .btn:hover{ background:#111827;}
    .grid{ display:grid; grid-template-columns:1fr 1fr; gap:14px;}
    .card{ background:#0b0f19; border:1px solid #1f2937; border-radius:12px; padding:14px;}
    .card h3{ margin:0 0 10px;}
    table{ width:100%; border-collapse:collapse;}
    th,td{ text-align:left; padding:8px 6px; border-bottom:1px solid #1f2937; font-size:.95rem;}
    .right{ text-align:right;}
    .muted{ color:#94a3b8;}
    .small{ font-size:.85rem;}
    .ok{ color:#10b981;}
    .warn{ color:#f59e0b;}
    .bad{ color:#ef4444;}
    .input{ padding:8px 10px; border-radius:8px; border:1px solid #334155; background:#0b1220; color:#e5e7eb;}
    .note{ color:#94a3b8; font-size:.9rem; margin-top:8px;}
    .actions .btn{ padding:6px 8px; font-size:.85rem;}
    .chips{ display:flex; gap:6px; flex-wrap:wrap;}
    .chip{ background:#111827; border:1px solid #334155; color:#e5e7eb; padding:3px 8px; border-radius:999px; font-size:.8rem;}
    @media (max-width:980px){ .grid{ grid-template-columns:1fr; } .kpis{ grid-template-columns:repeat(2,1fr);} }
  </style>
</head>
<body>
  <header class="admin-header">
    <div class="admin-header-inner">
      <h1 class="site-name"><?php echo $artistName; ?></h1>
      <span class="admin-subtitle">Admin</span>
    </div>
  </header>
  <?php include SITE_ROOT . '/admin/admin_menu.php'; ?>

  <main class="admin-container">
    <h2>Media Thumb Health</h2>

    <div class="toolbar">
      <label class="muted">Base:</label>
      <select id="base" class="input">
        <option value="/media">/media</option>
        <option value="/media/images">/media/images</option>
        <option value="/media/videos">/media/videos</option>
        <option value="/media/pdfs">/media/pdfs</option>
      </select>
      <label class="muted">Kind:</label>
      <select id="kind" class="input">
        <option value="all">All</option>
        <option value="image">Images</option>
        <option value="video">Videos</option>
        <option value="pdf">PDFs</option>
      </select>
      <input id="q" class="input" placeholder="Search path…" style="min-width:220px">
      <button class="btn" id="btnRescan">Rescan</button>
      <button class="btn" id="btnRebuildAll">Rebuild All (images+videos)</button>
    </div>

    <div class="kpis" id="kpis">
      <div class="kpi"><h3>Total scanned</h3><div class="num" id="k_total">—</div></div>
      <div class="kpi"><h3>Thumbs OK</h3><div class="num ok" id="k_ok">—</div></div>
      <div class="kpi"><h3>Missing thumbs (IMG/VID)</h3><div class="num bad" id="k_miss">—</div></div>
      <div class="kpi"><h3>By kind</h3><div class="chips" id="k_kinds"></div><div class="note">PDF thumbs are <strong>N/A</strong> and never counted as missing.</div></div>
    </div>

    <div class="grid">
      <div class="card">
        <h3>Folders with missing thumbs (images + videos)</h3>
        <table id="tblFolders">
          <thead><tr><th>Folder</th><th class="right">Missing</th><th class="right">Total</th><th class="right">Actions</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="note">“Rebuild” runs the generator for that folder (PDFs ignored).</div>
      </div>

      <div class="card">
        <h3>Missing items (sample — images/videos only)</h3>
        <table id="tblMissing">
          <thead><tr><th>Kind</th><th>Source</th><th>Expected</th><th class="right">Actions</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="note">“Relink” stores a mapping in <code>.cache/_thumbs.json</code>. “Placeholder” writes a local file.</div>
      </div>
    </div>
  </main>

  <script>
  (function(){
    const $  = (s, r=document)=>r.querySelector(s);
    const baseSel = $('#base');
    const kindSel = $('#kind');
    const qInp    = $('#q');

    const k_total = $('#k_total');
    const k_ok    = $('#k_ok');
    const k_miss  = $('#k_miss');
    const k_kinds = $('#k_kinds');
    const tbF     = document.querySelector('#tblFolders tbody');
    const tbM     = document.querySelector('#tblMissing tbody');

    function toast(msg, ok=true){
      const d = document.createElement('div');
      d.textContent = msg;
      Object.assign(d.style,{position:'fixed',right:'16px',top:'16px',background: ok?'#064e3b':'#7f1d1d',color:'#fff',border:'1px solid #334155',padding:'8px 12px',borderRadius:'10px',zIndex:5000});
      document.body.appendChild(d);
      setTimeout(()=>{d.style.opacity='0'; d.style.transition='opacity .25s'; d.addEventListener('transitionend',()=>d.remove());}, 2500);
    }

    async function scan(){
      tbF.innerHTML = '<tr><td colspan="4" class="muted">Scanning…</td></tr>';
      tbM.innerHTML = '<tr><td colspan="4" class="muted">Scanning…</td></tr>';
      k_total.textContent = '—'; k_ok.textContent='—'; k_miss.textContent='—'; k_kinds.innerHTML='';

      const p = new URLSearchParams({
        base: baseSel.value,
        kind: kindSel.value,
        q: qInp.value,
        limit: '500'
      });
      const r = await fetch('/admin/modules/MediaThumbs/api/scan-thumbs.php?'+p.toString(), {credentials:'same-origin'});
      if (!r.ok){ tbF.innerHTML=''; tbM.innerHTML=''; toast('Scan failed (HTTP '+r.status+')', false); return; }
      const j = await r.json().catch(()=>null);
      if (!j || !j.success){ tbF.innerHTML=''; tbM.innerHTML=''; toast(j && j.message ? j.message : 'Scan failed', false); return; }

      k_total.textContent = j.totals.scanned;
      k_ok.textContent    = j.totals.ok;
      k_miss.textContent  = j.totals.missing;

      k_kinds.innerHTML = '';
      ['image','video','pdf'].forEach(k=>{
        const t = j.totals.byKind[k]?.total || 0;
        const m = j.totals.byKind[k]?.missing || 0; // pdf missing always 0
        const span = document.createElement('span');
        span.className='chip';
        if (k === 'pdf') {
          span.textContent = `PDF: ${t} (thumbs N/A)`;
        } else {
          span.textContent = `${k.toUpperCase()}: ${(t-m)}/${t}`;
        }
        k_kinds.appendChild(span);
      });

      tbF.innerHTML = '';
      (j.folders||[]).forEach(f=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="small">${f.folder}</td>
                        <td class="right bad">${f.missing}</td>
                        <td class="right muted">${f.total}</td>
                        <td class="right actions">
                           <button class="btn" data-act="rebuild" data-folder="${f.folder}">Rebuild</button>
                           <button class="btn" data-act="rescan-folder" data-folder="${f.folder}">Rescan</button>
                        </td>`;
        tbF.appendChild(tr);
      });
      if (!tbF.children.length) tbF.innerHTML = '<tr><td colspan="4" class="ok">No missing thumbs 🎉</td></tr>';

      tbM.innerHTML = '';
      (j.missing||[]).forEach(mi=>{
        const tr = document.createElement('tr');
        const exp = (mi.first_candidate||'').replaceAll('<','&lt;').replaceAll('>','&gt;');
        const src = mi.src_web.replaceAll('<','&lt;').replaceAll('>','&gt;');
        tr.innerHTML = `<td class="small">${mi.kind.toUpperCase()}</td>
                        <td class="small">${src}</td>
                        <td class="small muted">${exp||'(no candidates)'}</td>
                        <td class="right actions">
                          <button class="btn" data-act="relink" data-kind="${mi.kind}" data-src="${mi.src_web}">Relink…</button>
                          <button class="btn" data-act="placeholder" data-kind="${mi.kind}" data-src="${mi.src_web}">Placeholder</button>
                        </td>`;
        tbM.appendChild(tr);
      });
      if (!tbM.children.length) tbM.innerHTML = '<tr><td colspan="4" class="ok">No missing items in sample 🎉</td></tr>';
    }

    async function rebuildFolder(folder){
      // images+videos only; PDFs ignored in handler
      const p = new URLSearchParams({action:'rebuild_folder', folder, ignore_pdfs:'1'});
      let res;
      try {
        res = await fetch('/admin/modules/MediaThumbs/api/media-cache.php', {method:'POST', body:p, credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}});
      } catch(e){ toast('Rebuild error: '+e.message, false); return; }
      if (!res.ok){ toast('Rebuild failed (HTTP '+res.status+')', false); return; }
      const j = await res.json().catch(()=>null);
      if (!j || j.success === false){ toast(j&&j.message ? j.message : 'Rebuild failed', false); return; }
      toast(j.message || 'Rebuild started');
      scan();
    }

    async function relink(kind, src){
      const thumb = prompt('Enter thumbnail web path (e.g., /media/images/.../.cache/thumb.png):');
      if (!thumb) return;
      const fd = new FormData();
      fd.append('action','set');
      fd.append('kind', kind);
      fd.append('src',  src);
      fd.append('thumb',thumb);
      const r = await fetch('/admin/modules/MediaThumbs/api/thumbs-map.php', {method:'POST', body: fd, credentials:'same-origin'});
      if (!r.ok){ toast('Relink failed (HTTP '+r.status+')', false); return; }
      const j = await r.json().catch(()=>null);
      if (!j || !j.success){ toast(j&&j.message?j.message:'Relink failed', false); return; }
      toast('Relinked.');
      scan();
    }

    async function placeholder(kind, src){
      const fd = new FormData();
      fd.append('kind', kind);
      fd.append('src',  src);
      const r = await fetch('/admin/modules/MediaThumbs/api/placeholder-thumb.php', {method:'POST', body: fd, credentials:'same-origin'});
      if (!r.ok){ toast('Placeholder failed (HTTP '+r.status+')', false); return; }
      const j = await r.json().catch(()=>null);
      if (!j || !j.success){ toast(j&&j.message?j.message:'Placeholder failed', false); return; }
      toast(j.message || 'Placeholder written.');
      scan();
    }

    // Events
    document.getElementById('btnRescan').addEventListener('click', scan);
    document.getElementById('btnRebuildAll').addEventListener('click', ()=> rebuildFolder(baseSel.value));
    document.addEventListener('click', (e)=>{
      const b = e.target.closest('button.btn'); if (!b) return;
      const act = b.dataset.act;
      if (act === 'rebuild') rebuildFolder(b.dataset.folder);
      if (act === 'rescan-folder') { qInp.value = b.dataset.folder; scan(); }
      if (act === 'relink') relink(b.dataset.kind, b.dataset.src);
      if (act === 'placeholder') placeholder(b.dataset.kind, b.dataset.src);
    });

    // Initial
    scan();
  })();
  </script>
</body>
</html>
