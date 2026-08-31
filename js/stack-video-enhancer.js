/**
 * @appname     RI Stack Video Enhancer
 * @file        SITE-ROOT/js/stack-video-enhancer.js
 * @version     2025.09.10.2338.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Inline motion tiles for MP4 + YouTube, with lightbox on click.
 *   • MP4: inject <video> if the tile lacks a usable poster (muted/loop/inline).
 *   • YouTube/Shorts: inject iframe that auto-plays muted when visible.
 *   • NEW: when injecting a player, remove any .ri-video-ph placeholder
 *           and mark the anchor to prevent future placeholders.
 */

(function(){
  const VID_RE = /\.(mp4|webm|og[gv]|m4v)(\?.*)?$/i;
  const YT_RE  = /(youtube\.com|youtu\.be)\//i;
  const DBG = document.body.getAttribute('data-stack-video-debug') === '1';
  const log = (...a)=>{ if (DBG) try{ console.log('[stack-video]',...a);}catch(_){} };

  /* ---------------- YouTube helpers ---------------- */
  function ytId(url){
    try{
      const u = new URL(url, location.origin);
      if (/youtu\.be$/i.test(u.hostname)) return u.pathname.split('/')[1] || null;
      if (/youtube\.com$/i.test(u.hostname)){
        if (u.pathname.startsWith('/watch'))  return u.searchParams.get('v');
        if (u.pathname.startsWith('/embed/')) return u.pathname.split('/')[2] || null;
        if (u.pathname.startsWith('/shorts/'))return u.pathname.split('/')[2] || null;
      }
    }catch(_){}
    const m = String(url).match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([A-Za-z0-9_\-]{6,})/);
    return m ? m[1] : null;
  }
  function ytSrcFor(id){
    return `https://www.youtube.com/embed/${id}` +
           `?autoplay=1&mute=1&controls=0&rel=0&playsinline=1&modestbranding=1` +
           `&enablejsapi=1&iv_load_policy=3&loop=1&playlist=${id}`;
  }
  function postYT(ifr, cmd){
    try{
      if (!ifr || !ifr.contentWindow) return;
      const msg = JSON.stringify({ event:'command', func:cmd, args:[] });
      ifr.contentWindow.postMessage(msg, '*');
    }catch(_){}
  }

  /* ---------------- utilities ---------------- */
  function removePlaceholders(a){
    a.querySelectorAll('.ri-video-ph').forEach(n=>n.remove());
  }

  /* ---------------- autoplay observers ---------------- */
  const ioVideo = new IntersectionObserver(async entries=>{
    for (const en of entries){
      const v = en.target;
      if (!(v instanceof HTMLVideoElement)) continue;
      if (en.isIntersecting){
        try{
          v.muted = true; v.setAttribute('muted','');
          v.setAttribute('playsinline',''); v.playsInline = true;
          v.autoplay = true; v.setAttribute('autoplay','');
          v.loop = true; v.setAttribute('loop','');
          if (!v.getAttribute('preload')) v.setAttribute('preload','metadata');
          await v.play();
          v.classList.add('ri-video-playing');
        }catch(err){ log('mp4 autoplay blocked', err); }
      } else {
        try{ v.pause(); }catch(_){}
        v.classList.remove('ri-video-playing');
      }
    }
  }, {threshold: 0.25});

  const ioYT = new IntersectionObserver(entries=>{
    for (const en of entries){
      const ifr = en.target;
      if (!(ifr instanceof HTMLIFrameElement)) continue;
      if (en.isIntersecting) postYT(ifr,'playVideo');
      else                   postYT(ifr,'pauseVideo');
    }
  }, {threshold: 0.25});

  function enhanceVideoEl(v){
    if (!(v instanceof HTMLVideoElement)) return;
    v.muted = true; v.setAttribute('muted','');
    v.setAttribute('playsinline',''); v.playsInline = true;
    if (!v.getAttribute('preload')) v.setAttribute('preload','metadata');
    ioVideo.observe(v);
  }

  /* ---------------- MP4 tiles ---------------- */
  function injectInlineMP4(a, href, imgToHide){
    removePlaceholders(a);
    const v = document.createElement('video');
    v.className = 'ri-inline-video';
    v.muted = true; v.setAttribute('muted','');
    v.setAttribute('playsinline',''); v.playsInline = true;
    v.autoplay = true; v.setAttribute('autoplay','');
    v.loop = true; v.setAttribute('loop','');
    v.setAttribute('preload','metadata');
    const s = document.createElement('source'); s.src = href; s.type = 'video/mp4'; v.appendChild(s);
    v.style.width = '100%';
    if (imgToHide) { imgToHide.style.display='none'; imgToHide.classList.add('ri-thumb-missing'); }
    a.appendChild(v);
    a.dataset.inlinePlayer = 'mp4';
    a.classList.add('ri-inlined');
    enhanceVideoEl(v);
    log('inline mp4 injected', href);
  }

  function mp4TileEnsureVisual(a){
    const href = a.getAttribute('href')||'';
    if (!VID_RE.test(href.toLowerCase())) return;
    if (a.dataset.inlinePlayer) return;                      // already upgraded
    const existingVideo = a.querySelector('video.ri-inline-video, video');
    if (existingVideo){ a.dataset.inlinePlayer='mp4'; enhanceVideoEl(existingVideo); return; }

    const img = a.querySelector('img');
    const imgSrc = img ? (img.getAttribute('src')||'') : '';
    if (!img || VID_RE.test(imgSrc.toLowerCase()) || (img.complete && img.naturalWidth<2)){
      injectInlineMP4(a, href, img);
    }
  }

  /* ---------------- YouTube tiles ---------------- */
  function injectInlineYouTube(a, href, imgToHide){
    removePlaceholders(a);
    const id = ytId(href); if (!id) return;

    const crop = parseFloat(a.getAttribute('data-cropscale')||'') || 1.08;
    const wrap = document.createElement('div');
    wrap.className = 'ri-inline-yt';
    wrap.style.setProperty('--stack-yt-crop', String(crop));

    const ifr = document.createElement('iframe');
    ifr.className = 'ri-inline-yt-frame';
    ifr.src = ytSrcFor(id);
    ifr.setAttribute('allow','autoplay; fullscreen; picture-in-picture');
    ifr.setAttribute('loading','lazy');
    ifr.setAttribute('title','YouTube video');
    ifr.style.pointerEvents = 'none'; // click goes to <a> → lightbox

    wrap.appendChild(ifr);
    if (imgToHide){ imgToHide.style.display='none'; imgToHide.classList.add('ri-thumb-missing'); }
    a.appendChild(wrap);
    a.dataset.inlinePlayer = 'youtube';
    a.classList.add('ri-inlined');

    ioYT.observe(ifr);
    log('inline youtube injected', id);
  }

  function ytTileEnsureVisual(a){
    const href = a.getAttribute('href')||'';
    if (!YT_RE.test(href)) return;
    if (a.dataset.inlinePlayer) return;                      // already upgraded
    if (a.querySelector('.ri-inline-yt, iframe.ri-inline-yt-frame')){ a.dataset.inlinePlayer='youtube'; return; }
    const img = a.querySelector('img');
    // If you prefer static thumbs by default, early-return here when img is good.
    injectInlineYouTube(a, href, img);
  }

  /* ---------------- scan + observe ---------------- */
  function scan(root=document){
    root.querySelectorAll('video[data-stack-video], .stack video, [data-stack] video, .col-left video, .col-right video, .ri-card video')
      .forEach(enhanceVideoEl);
    root.querySelectorAll('a[href$=".mp4" i], a[href*=".mp4?" i]')
      .forEach(mp4TileEnsureVisual);
    root.querySelectorAll('a[data-type="youtube"], a[href*="youtube.com/"], a[href*="youtu.be/"]')
      .forEach(ytTileEnsureVisual);
  }

  document.addEventListener('DOMContentLoaded', ()=>scan(document));
  scan(document);

  const mo = new MutationObserver(muts=>{
    for (const m of muts){
      for (const n of m.addedNodes){
        if (!(n instanceof HTMLElement)) continue;
        if (n.matches && (n.matches('video') || n.matches('a[href]'))) scan(n);
        if (n.querySelector) scan(n);
      }
    }
  });
  mo.observe(document.documentElement,{childList:true,subtree:true});
})();
