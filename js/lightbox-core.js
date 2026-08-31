/**
 * @appname     RI Lightbox Core
 * @file        SITE-ROOT/js/lightbox-core.js
 * @version     2025.10.06.0820.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Frameless, auto-sized lightbox for IMAGES + VIDEOS (MP4/YouTube/Vimeo).
 *              Adds "cover" mode for iframe videos to remove top/bottom letterbox.
 *              FIXED: Added error handling and exclusion for non-lightbox links.
 *
 * Authoring notes:
 *   • To force contain/cover per-link, add data-lightbox-fit="contain|cover" on the <a>.
 *   • To tweak the crop strength, add data-cropscale="1.0…1.4" (default 1.12).
 *   • To exclude a link from lightbox, add data-lightbox="false" or class="no-lightbox".
 *   • PDFs are handled by lightbox-pdf.js separately.
 */

(function(){
  /* ---------------- Minimal CSS ---------------- */
  function ensureModal(){
    let m=document.getElementById('ri-modal');
    if(!m){
      m=document.createElement('div'); m.id='ri-modal';
      m.innerHTML=`
        <style>
          html.ri-modal-open, body.ri-modal-open { overflow:hidden }
          #ri-modal{position:fixed;inset:0;background:rgba(0,0,0,.86);z-index:2147483000;display:none}
          #ri-modal.open{display:flex}
          #ri-modal .dlg{
            margin:auto; max-width:96vw; max-height:92vh; width:auto; height:auto;
            border-radius:12px; overflow:hidden; position:relative;
            display:block; box-shadow:0 20px 80px rgba(0,0,0,.55);
            background:transparent;
          }
          #ri-modal .content{width:100%;height:100%;display:block;background:transparent}
          #ri-modal .content img,
          #ri-modal .content video,
          #ri-modal .content iframe{
            width:100%; height:100%; object-fit:contain; display:block; border:0; background:#000;
          }
          #ri-modal .x{
            position:absolute; top:12px; right:12px; z-index:2; cursor:pointer;
            width:36px;height:36px;border-radius:999px;background:rgba(0,0,0,.55);
            color:#fff; border:1px solid rgba(255,255,255,.18); display:grid;place-items:center;
            font-size:18px; line-height:1
          }
          #ri-modal .x:hover{background:rgba(0,0,0,.75)}

          /* No-overlay mode for images/videos */
          html.ri-no-overlay #ri-modal{background:transparent}
          html.ri-no-overlay #ri-modal .dlg{box-shadow:none}

          /* --- NEW: Cover mode for iframes (YouTube/Vimeo) --- */
          #ri-modal .dlg.cover .content{ overflow:hidden; background:#000; }
          #ri-modal .dlg.cover iframe{
            width:100%; height:100%;
            /* crop letterbox by scaling up slightly (tunable via --ri-crop-scale) */
            transform: scale(var(--ri-crop-scale, 1.12));
            transform-origin: center center;
          }
        </style>
        <div class="dlg">
          <button class="x" aria-label="Close">X</button>
          <div class="content"></div>
        </div>`;
      document.body.appendChild(m);

      const close=()=>{ 
        m.classList.remove('open'); 
        document.documentElement.classList.remove('ri-modal-open','ri-no-overlay'); 
        document.body.classList.remove('ri-modal-open','ri-no-overlay'); 
        setTimeout(()=>{ const c=m.querySelector('.content'); if(c) c.innerHTML=''; m.removeAttribute('data-aspect'); },110);
        window.removeEventListener('resize', m._ri_onResize, {passive:true});
      };
      m.querySelector('.x').addEventListener('click', close);
      m.addEventListener('click', e=>{ if(e.target===m) close(); });
      document.addEventListener('keydown', e=>{ if(e.key==='Escape' && m.classList.contains('open')) close(); });

      m._ri_onResize = ()=>autoSizeFromStoredAspect();
    }
    return m;
  }

  function openWith(opts){
    const m=ensureModal();
    const dlg=m.querySelector('.dlg');
    const box=m.querySelector('.content');
    
    // CRITICAL FIX: Validate modal structure exists
    if(!dlg || !box){
      console.error('Lightbox: Modal structure is broken. Cannot open lightbox.');
      return null;
    }
    
    const noOverlay = !!(opts && opts.noOverlay);
    document.documentElement.classList.add('ri-modal-open');
    document.body.classList.add('ri-modal-open');
    if (noOverlay){ 
      document.documentElement.classList.add('ri-no-overlay'); 
      document.body.classList.add('ri-no-overlay'); 
    } else {
      document.documentElement.classList.remove('ri-no-overlay'); 
      document.body.classList.remove('ri-no-overlay'); 
    }
    box.innerHTML=''; m.classList.add('open');
    window.addEventListener('resize', m._ri_onResize, {passive:true});
    // reset cover state
    dlg.classList.remove('cover');
    dlg.style.removeProperty('--ri-crop-scale');
    return {m,dlg,box};
  }

  /* ---------------- Aspect-fitting logic ---------------- */
  function fitSizeForAspect(aspect){
    const maxW = Math.max(120, Math.floor(window.innerWidth  * 0.96));
    const maxH = Math.max(120, Math.floor(window.innerHeight * 0.92));
    let w = Math.min(maxW, Math.floor(maxH * aspect));
    let h = Math.floor(w / aspect);
    if (h > maxH){ h = maxH; w = Math.floor(h * aspect); }
    const minW = Math.min(maxW, 520), minH = Math.min(maxH, 320);
    if (w < minW && h < minH){
      const scale = Math.min(maxW/minW, maxH/minH);
      w = Math.min(maxW, Math.floor(minW * scale));
      h = Math.min(maxH, Math.floor(minH * scale));
      if (w / h > aspect){ w = Math.floor(h * aspect); } else { h = Math.floor(w / aspect); }
    }
    return {w,h};
  }
  function applySizeToDialog(aspect){
    const m = document.getElementById('ri-modal'); if (!m) return;
    const dlg = m.querySelector('.dlg'); if (!dlg) return;
    const {w,h} = fitSizeForAspect(aspect || 16/9);
    dlg.style.width  = w + 'px';
    dlg.style.height = h + 'px';
    m.dataset.aspect = String(aspect || 16/9);
  }
  function autoSizeFromStoredAspect(){
    const m = document.getElementById('ri-modal'); if (!m || !m.classList.contains('open')) return;
    const a = parseFloat(m.dataset.aspect || '') || 16/9;
    applySizeToDialog(a);
  }

  /* ---------------- Type detection ---------------- */
  const IMG_RE = /\.(png|jpe?g|gif|webp|avif)(\?.*)?$/i;
  const VID_RE = /\.(mp4|webm|og[gv]|m4v)(\?.*)?$/i;

  function yt(u){
    const m=u.match(/(?:youtu\.be\/|youtube\.com\/(?:(?:watch\?v=)|embed\/|shorts\/))([A-Za-z0-9_\-]{6,})/i);
    return m?`https://www.youtube.com/embed/${m[1]}?autoplay=1&rel=0&playsinline=1&modestbranding=1`:null;
  }
  function vimeo(u){ const m=u.match(/vimeo\.com\/(?:video\/)?(\d+)/i); return m?`https://player.vimeo.com/video/${m[1]}?autoplay=1&title=0&byline=0&portrait=0`:null; }

  function inferTypeFromHref(href){
    const h=(href||'').toLowerCase();
    if (IMG_RE.test(h)) return 'image';
    if (VID_RE.test(h) || /youtube\.com|youtu\.be|vimeo\.com/i.test(h)) return 'video';
    return null;
  }
  
  function detectTypeFromAnchor(a){
    // CRITICAL FIX: Check for explicit exclusion first
    if(a.classList.contains('no-lightbox')) return null;
    if(a.getAttribute('data-lightbox') === 'false' || a.getAttribute('data-lightbox') === '0') return null;
    if(a.getAttribute('target') === '_blank' && !a.hasAttribute('data-lightbox')) return null; // Respect target="_blank"
    
    const dl=(a.getAttribute('data-lightbox')||'').toLowerCase();
    if (dl==='image'||dl==='video') return dl;
    if (dl==='1'||dl==='true'){
      const dt=(a.getAttribute('data-type')||'').toLowerCase();
      return (dt==='image'||dt==='video') ? dt : inferTypeFromHref(a.getAttribute('href')||'');
    }
    const dt=(a.getAttribute('data-type')||'').toLowerCase();
    if (dt==='image'||dt==='video') return dt;

    if (a.classList.contains('lightbox') || /\blightbox\b/i.test(a.getAttribute('rel')||'')) {
      return inferTypeFromHref(a.getAttribute('href')||'');
    }
    const href=(a.getAttribute('href')||'').toLowerCase();
    
    // CRITICAL FIX: More specific detection for YouTube/Vimeo video links
    // Only treat as video if it has specific video-related classes or is explicitly a video link
    if (/(youtube\.com\/watch|youtube\.com\/embed|youtube\.com\/shorts|youtu\.be\/|vimeo\.com\/\d)/i.test(href) && 
        (a.querySelector('img, .ri-thumb, .thumbnail, .thumb') || a.classList.contains('video-link'))) {
      return 'video';
    }
    
    if ((document.body && document.body.getAttribute('data-lightbox-auto')==='1')
        && a.querySelector('img, .ri-thumb, .ri-card, .thumbnail, .thumb, .preview')) {
      return inferTypeFromHref(a.getAttribute('href')||'');
    }
    return null;
  }

  /* ---------------- Renderers ---------------- */
  function renderImage(href, title){
    const noOverlay = document.body.getAttribute('data-lightbox-overlay') === '0';
    const result = openWith({noOverlay});
    if(!result) return false; // CRITICAL FIX: Handle failure
    const {dlg,box} = result;
    const img=new Image();
    img.onload = function(){
      const w = img.naturalWidth || 16, h = img.naturalHeight || 9;
      const aspect = Math.max(0.05, w / Math.max(1, h));
      applySizeToDialog(aspect);
    };
    img.src=href; if(title) img.alt=title;
    box.appendChild(img);
    return true;
  }

  function aspectForYouTube(url){ return /youtube\.com\/shorts\//i.test(url) ? 9/16 : 16/9; }

  function renderVideo(href, anchor){
    const noOverlay = document.body.getAttribute('data-lightbox-overlay') === '0';
    const result = openWith({noOverlay});
    if(!result) return false; // CRITICAL FIX: Handle failure
    const {dlg,box} = result;
    const lower=(href||'').toLowerCase();

    // Per-link fit override (contain|cover); default cover for YT/Vimeo
    const fitAttr = anchor ? (anchor.getAttribute('data-lightbox-fit')||anchor.getAttribute('data-fit')) : '';
    const fitPref = (fitAttr||'').toLowerCase();

    if (VID_RE.test(lower)) {
      // Plain MP4
      const v=document.createElement('video'); v.controls=true; v.autoplay=true; v.playsInline=true; v.preload='metadata';
      v.addEventListener('loadedmetadata', ()=> {
        const vw = v.videoWidth || 16, vh = v.videoHeight || 9;
        const aspect = Math.max(0.05, vw / Math.max(1, vh));
        applySizeToDialog(aspect);
      }, {once:true});
      const s=document.createElement('source'); s.src=href; s.type='video/mp4'; v.appendChild(s);
      applySizeToDialog(16/9);
      box.appendChild(v);
      // MP4s use object-fit:contain; no cover zoom by default
    } else {
      // YouTube / Vimeo
      const y=yt(href), vm=vimeo(href);
      const src=y||vm||href;

      const assumedAspect = y ? aspectForYouTube(href) : 16/9;
      applySizeToDialog(assumedAspect);

      // Decide fit mode: default cover for hosted players unless explicitly "contain"
      const useCover = (fitPref === 'cover') || (!fitPref && (y || vm));
      if (useCover){
        dlg.classList.add('cover');
        // Per-link crop strength (1.0..1.4); default 1.12 is a gentle crop
        const crop = anchor && parseFloat(anchor.getAttribute('data-cropscale')) || 1.12;
        if (crop && crop > 1 && crop <= 1.5) dlg.style.setProperty('--ri-crop-scale', String(crop));
      }

      const ifr=document.createElement('iframe');
      ifr.src=src;
      ifr.allow='autoplay; fullscreen; picture-in-picture'; ifr.setAttribute('allowfullscreen','');
      box.appendChild(ifr);
    }
    return true;
  }

  /* ---------------- Click handling ---------------- */
  document.addEventListener('click', function(e){
    const a = e.target && e.target.closest ? e.target.closest('a') : null; if(!a) return;

    let type = null;
    if (a.matches('a[data-lightbox="image"]')) type='image';
    else if (a.matches('a[data-lightbox="video"]')) type='video';
    else type = detectTypeFromAnchor(a);

    if (!type || type==='pdf') return;

    const href = a.getAttribute('href') || ''; if (!href) return;
    
    // CRITICAL FIX: Try to render, but if it fails, allow default link behavior
    let success = false;
    try {
      e.preventDefault();
      if (type==='image') success = renderImage(href, a.getAttribute('title')||'');
      else if (type==='video') success = renderVideo(href, a);
      
      // If rendering failed, fallback to normal link behavior
      if(!success){
        console.warn('Lightbox: Failed to render. Opening link normally.');
        window.location.href = href;
      }
    } catch(err) {
      console.error('Lightbox: Error rendering lightbox:', err);
      // Allow the link to work normally if lightbox fails
      window.location.href = href;
    }
  });
})();
