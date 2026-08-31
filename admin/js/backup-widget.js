<script>
(function(){
  const CFG = {
    endpoint: '/admin/filemanager/api.php', // change if needed
  };

  function el(tag, attrs={}, kids=[]){
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k,v])=> (k==='style') ? (n.style.cssText=v) : n.setAttribute(k, v));
    kids.forEach(k => n.appendChild(typeof k==='string'?document.createTextNode(k):k));
    return n;
  }

  async function doBackup(kind, log){
    const box = el('div',{style:'padding:10px;background:#111;color:#eee;border-radius:10px;margin-top:8px;font:12px/1.4 monospace'});
    log.innerHTML=''; log.appendChild(box);
    box.textContent = 'Starting '+kind+' backup...';

    const fd = new FormData();
    fd.append('action','backup');
    fd.append('path','');  // API expects it
    fd.append('kind', kind);
    fd.append('format','zip');

    const t0=Date.now();
    let resp;
    try{
      resp = await fetch(CFG.endpoint, {method:'POST', body:fd, credentials:'same-origin'});
    }catch(e){
      box.textContent='Network error: '+e.message; return;
    }
    let j;
    try{ j = await resp.json(); }catch{
      box.textContent='Unexpected response (not JSON)'; return;
    }
    if(!j.ok){ box.textContent='Backup failed: '+(j.error||'unknown'); return; }

    const dt=((Date.now()-t0)/1000).toFixed(1);
    const a = el('a', {href: CFG.endpoint+'?action=download&path='+encodeURIComponent(j.archive), target:'_blank', style:'color:#9cf;text-decoration:underline'}, [j.archive]);
    box.innerHTML = 'Backup ready ('+dt+'s): '; box.appendChild(a);
  }

  function mount(where){
    const root = (typeof where==='string') ? document.querySelector(where) : where;
    if(!root) return;
    root.innerHTML='';
    const wrap = el('div',{style:'border:1px solid rgba(255,255,255,.15);padding:12px;border-radius:12px;background:#0e141b;color:#e8eef6;box-shadow:0 4px 18px rgba(0,0,0,.35)'});
    wrap.appendChild(el('div',{style:'font-weight:700;margin-bottom:8px'},['Backups']));
    const btnBar = el('div',{style:'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px'});
    const mkBtn=(label,kind)=>{ const b=el('button',{style:'padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:#1a2330;color:#fff;cursor:pointer'},[label]); b.onclick=()=>doBackup(kind, log); return b; };
    btnBar.append(mkBtn('Backup App','app'), mkBtn('Backup Data','data'), mkBtn('Backup Media','media'));
    const log = el('div');
    wrap.append(btnBar, log);
    root.appendChild(wrap);
  }

  // expose globally
  window.BackupWidget = { mount, config:CFG };
})();
</script>
