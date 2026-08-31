/**
 * @appname     RI Lightbox PDF
 * @file        SITE-ROOT/js/lightbox-pdf.js
 * @version     2025.09.10.2120.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description PDF lightbox via viewer/proxy with Safari fallback.
 *              Selectors: data-lightbox="pdf" | data-type="pdf" | data-lightbox="1"[href*=.pdf]
 *              Legacy:    .lightbox / rel~="lightbox"[href*=.pdf]
 *              Viewer/proxy links accepted. 
 *              Optional: <body data-lightbox-overlay-pdf="0"> to remove backdrop for PDFs too.
 */

(function(){
  const PDF_RE = /\.pdf(\?.*)?$/i;
  const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent || '');

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
            margin:auto; width:min(96vw,1200px); height:min(92vh,92vh);
            border-radius:12px; overflow:hidden; position:relative;
            display:grid; place-items:center; box-shadow:0 20px 80px rgba(0,0,0,.55);
            background:#000;
          }
          #ri-modal .x{
            position:absolute; top:12px; right:12px; z-index:2; cursor:pointer;
            width:36px;height:36px;border-radius:999px;background:rgba(0,0,0,.55);
            color:#fff; border:1px solid rgba(255,255,255,.18); display:grid;place-items:center;
            font-size:18px; line-height:1
          }
          #ri-modal .x:hover{background:rgba(0,0,0,.75)}
          #ri-modal iframe,#ri-modal embed{width:100%; height:100%; border:0; background:#000}
          /* No-overlay toggle for PDFs */
          html.ri-no-overlay #ri-modal{background:transparent}
          html.ri-no-overlay #ri-modal .dlg{box-shadow:none;background:transparent}
        </style>
        <div class="dlg">
          <button class="x" aria-label="Close">✕</button>
          <iframe src="about:blank" title="PDF" allow="fullscreen"></iframe>
        </div>`;
      document.body.appendChild(m);
      const close=()=>{ 
        m.classList.remove('open'); 
        document.documentElement.classList.remove('ri-modal-open','ri-no-overlay'); 
        document.body.classList.remove('ri-modal-open','ri-no-overlay'); 
        setTimeout(()=>{ const f=m.querySelector('iframe'); if(f) f.src='about:blank'; const e=m.querySelector('embed'); if(e) e.remove(); },110); 
      };
      m.querySelector('.x').addEventListener('click', close);
      m.addEventListener('click', e=>{ if(e.target===m) close(); });
      document.addEventListener('keydown', e=>{ if(e.key==='Escape' && m.classList.contains('open')) close(); });
    }
    return m;
  }

  function wantsLightbox(a){
    if (a.matches('a[data-lightbox="pdf"], a[data-type="pdf"]')) return true;

    const dl=(a.getAttribute('data-lightbox')||'').toLowerCase();
    if ((dl==='1'||dl==='true') && PDF_RE.test((a.getAttribute('href')||'').toLowerCase())) return true;

    const rel=a.getAttribute('rel')||'';
    if ((a.classList.contains('lightbox')||/\blightbox\b/i.test(rel)) && PDF_RE.test((a.getAttribute('href')||'').toLowerCase())) return true;

    if (document.body && document.body.getAttribute('data-lightbox-auto')==='1') {
      const href=(a.getAttribute('href')||'').toLowerCase();
      if (PDF_RE.test(href) && a.querySelector('img, .ri-thumb, .ri-card, .thumbnail, .thumb, .preview')) return true;
    }

    const href=(a.getAttribute('href')||'').toLowerCase();
    if (/\/panels\/pdf-(viewer|proxy)\.php/.test(href)) return true;

    return false;
  }

  function extractSrcParam(u){
    try{ const q=new URL(u, location.origin).searchParams; return q.get('src'); }catch(_){ return null; }
  }

  function chooseViewerURL(href){
    if (/\/panels\/pdf-viewer\.php/i.test(href)) return href;
    return `/admin/modules/GalleryManager/pdf/pdf-viewer.php?src=${encodeURIComponent(href)}`;
  }

  function chooseDirectPDF(href){
    if (/\/panels\/pdf-proxy\.php/i.test(href)) return href;
    const fromViewer = extractSrcParam(href);
    if (fromViewer) return fromViewer;
    if (PDF_RE.test(href)) return href;
    return href;
  }

  document.addEventListener('click', function(e){
    const a = e.target && e.target.closest ? e.target.closest('a') : null; if (!a) return;
    if (!wantsLightbox(a)) return;

    const href = a.getAttribute('href') || ''; if (!href) return;

    e.preventDefault();

    const m=ensureModal(); 
    let frame=m.querySelector('iframe'); const existingEmbed = m.querySelector('embed'); if (existingEmbed) existingEmbed.remove();

    // Optional no-overlay for PDFs
    const noOverlay = document.body.getAttribute('data-lightbox-overlay-pdf') === '0';
    document.documentElement.classList.add('ri-modal-open');
    document.body.classList.add('ri-modal-open');
    if (noOverlay){ 
      document.documentElement.classList.add('ri-no-overlay'); 
      document.body.classList.add('ri-no-overlay'); 
    } else {
      document.documentElement.classList.remove('ri-no-overlay'); 
      document.body.classList.remove('ri-no-overlay'); 
    }

    const viewer = chooseViewerURL(href);
    frame.src='about:blank';
    let fallbackTimer=null, loaded=false;

    function useSafariEmbedFallback(){
      try{
        const direct = chooseDirectPDF(href);
        const emb = document.createElement('embed');
        emb.type = 'application/pdf';
        emb.src = direct;
        emb.style.width='100%'; emb.style.height='100%';
        frame.replaceWith(emb);
      }catch(_){}
    }

    if (isSafari) {
      frame.addEventListener('load', ()=>{ loaded=true; });
      requestAnimationFrame(()=>{ frame.src=viewer; });

      fallbackTimer = setTimeout(()=>{ if (!loaded) { useSafariEmbedFallback(); } }, 1100);
      setTimeout(()=>clearTimeout(fallbackTimer), 4000);
    } else {
      requestAnimationFrame(()=>{ frame.src=viewer; });
    }

    m.classList.add('open');
  });
})();
