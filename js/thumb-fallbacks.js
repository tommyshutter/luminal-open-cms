/**
 * @appname     RI Thumb Fallbacks
 * @file        SITE-ROOT/js/thumb-fallbacks.js
 * @version     2025.09.10.2338.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Graceful placeholder when no poster can be built.
 *              SAFE: skips .ri-gallery-videos AND any anchor already inlined.
 */

(function(){
  const VID_RE = /\.(mp4|webm|og[gv]|m4v)(\?.*)?$/i;

  function alreadyInlined(a){
    return a.dataset.inlinePlayer || a.classList.contains('ri-inlined') ||
           a.querySelector('video, .ri-inline-yt, iframe.ri-inline-yt-frame');
  }

  function ensurePlaceholder(a){
    if (a.closest('.ri-gallery-videos')) return; // galleries already provide posters
    if (alreadyInlined(a)) return;

    const href=a.getAttribute('href')||'';
    if(!VID_RE.test(href.toLowerCase())) return;

    const img=a.querySelector('img');
    if (img && img.complete && img.naturalWidth>1) return; // has good image

    if (!a.querySelector('.ri-video-ph')){
      const ph=document.createElement('div');
      ph.className='ri-video-ph';
      ph.textContent='VIDEO';
      a.appendChild(ph);
      if (img) img.classList.add('ri-thumb-missing');
    }
  }

  function scan(root=document){
    root.querySelectorAll('a[href$=".mp4" i], a[href*=".mp4?" i]').forEach(ensurePlaceholder);
  }

  document.addEventListener('DOMContentLoaded', ()=>scan(document));
  scan(document);

  // If the enhancer later inlines a player, placeholders disappear automatically.
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
