/**
 * @appname     RI Thumb Autobuild
 * @file        SITE-ROOT/js/thumb-autobuild.js
 * @version     2025.09.10.2246.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Builds posters for MP4 links that lack a usable image thumb.
 *              Now SAFE: skips .ri-gallery-videos (those already have posters)
 *              and skips any link that already has a real image loaded.
 */
(function(){
  const DBG = document.body.getAttribute('data-thumb-debug') === '1';
  const log = (...a)=>{ if (DBG) try{ console.log('[thumb-autobuild]',...a);}catch(_){} };
  const IMG_RE = /\.(png|jpe?g|webp|gif|avif)(\?.*)?$/i;
  const VID_RE = /\.(mp4|webm|og[gv]|m4v)(\?.*)?$/i;

  function tryLoad(url){
    return new Promise(res=>{
      if(!url) return res(null);
      const im=new Image();
      im.onload = ()=>res(url);
      im.onerror= ()=>res(null);
      im.src = url + (url.includes('?')?'&':'?')+'v='+Date.now();
    });
  }

  function posterCandidatesFromHref(href){
    try{
      const u=new URL(href,location.origin), p=u.pathname;
      const base=p.replace(/\.[^.]+$/,''); const name=base.split('/').pop(); const dir=p.slice(0,p.lastIndexOf('/'));
      const list=[`${base}.jpg`,`${base}.png`,`${base}.webp`,
                  `${dir}/thumbs/${name}.jpg`,`${dir}/thumbs/${name}.png`,
                  `/media/thumbs/${name}.jpg`, `/media/thumbs/${name}.png`];
      return list.map(x=>u.origin+x);
    }catch(_){
      const base=href.replace(/\.[^.]+$/,''); const name=base.split('/').pop(); const dir=href.slice(0,href.lastIndexOf('/'));
      return [`${base}.jpg`,`${base}.png`,`${base}.webp`,
              `${dir}/thumbs/${name}.jpg`,`${dir}/thumbs/${name}.png`,
              `/media/thumbs/${name}.jpg`, `/media/thumbs/${name}.png`];
    }
  }

  function alreadyHasGoodImg(a){
    const img=a.querySelector('img');
    if(!img) return false;
    const src=(img.getAttribute('src')||'').toLowerCase();
    if(IMG_RE.test(src) && (img.complete ? img.naturalWidth>1 : true)) return true;
    return false;
  }

  async function buildForAnchor(a){
    if (a.closest('.ri-gallery-videos')) return;               // NEW: hard skip in video galleries
    if (a.dataset.thumbBuilt === '1') return;
    const href=(a.getAttribute('href')||'').toLowerCase();
    if(!VID_RE.test(href)) return;

    // If a usable <img> already exists, do nothing
    if (alreadyHasGoodImg(a)) { a.dataset.thumbBuilt='1'; return; }

    // Prefer explicit data attributes first
    const dataPoster = a.getAttribute('data-poster') || a.getAttribute('data-thumb');
    if (dataPoster){
      const ok = await tryLoad(dataPoster);
      if (ok){ attachImg(a, ok); return; }
    }

    // Derive from filename
    const cands = posterCandidatesFromHref(href);
    for (const c of cands){
      const ok = await tryLoad(c);
      if (ok){ attachImg(a, ok); return; }
    }

    // give up quietly; fallbacks.js may handle placeholder
    a.dataset.thumbBuilt='1';
    log('no poster found', href);
  }

  function attachImg(a, src){
    let img=a.querySelector('img');
    if(!img){
      img=document.createElement('img');
      a.insertBefore(img, a.firstChild);
    }
    img.src=src; img.removeAttribute('srcset');
    img.classList.remove('ri-thumb-missing');
    a.dataset.thumbBuilt='1';
    log('poster attached', src);
  }

  function scan(root=document){
    const links=root.querySelectorAll('a[href$=".mp4" i], a[href*=".mp4?" i]');
    links.forEach(buildForAnchor);
  }

  document.addEventListener('DOMContentLoaded', ()=>scan(document));
  scan(document);

  const mo=new MutationObserver(muts=>{
    muts.forEach(m=>{
      m.addedNodes.forEach(n=>{
        if(!(n instanceof HTMLElement)) return;
        if(n.matches && n.matches('a[href]')) scan(n);
        if(n.querySelector) scan(n);
      });
    });
  });
  mo.observe(document.documentElement,{childList:true,subtree:true});
})();
