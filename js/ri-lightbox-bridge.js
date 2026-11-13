/**
 * ---------------------------------------------------------------------------------------
 * @appname   Luminal CMS
 * @file      /js/ri-lightbox-bridge.js
 * @version   2025.09.25.1426.00-EST (overlay enforcement + coexistence)
 * @author    ChatGPT
 * @license   Business Source License
 * @description
 *   Bridge to enforce lightbox behavior and provide MP4 micro-controls.
 *   Ensures #ri-modal always has overlay styling, even if pre-inserted bare.
 * ---------------------------------------------------------------------------------------
 */

(function () {
  if (window.__RI_LB_BRIDGE__) return;
  window.__RI_LB_BRIDGE__ = true;

  function ensureOverlay(host){
    // Always enforce overlay properties on the host (existing or new)
    const css = [
      'position:fixed','inset:0','display:flex','align-items:center','justify-content:center',
      'background:rgba(0,0,0,.86)','z-index:2147483000','padding:2rem'
    ];
    host.style.cssText = css.map(x=>x+';').join('');
    // Ensure inner container exists for consistent layout (idempotent)
    if (!host.querySelector('.dlg')){
      host.innerHTML = "<div class='dlg'><button class='x' aria-label='Close'>✕</button><div class='content'></div></div>";
      const close = ()=>{ host.style.display='none'; host.classList.remove('open'); };
      host.querySelector('.x').addEventListener('click', close);
      host.addEventListener('click', (e)=>{ if(e.target===host) close(); });
    }
  }

  function getHost(){
    let m = document.getElementById('ri-modal');
    if (!m) {
      m = document.createElement('div');
      m.id = 'ri-modal';
      document.body.appendChild(m);
    }
    ensureOverlay(m);
    m.style.display = 'flex';
    m.classList.add('open');
    return { host: m, box: m.querySelector('.content'), dlg: m.querySelector('.dlg') };
  }

  function openLightbox(type, href, group, opts) {
    // If a site-level lightbox API exists, prefer it.
    if (window.RILightbox && typeof window.RILightbox.open === 'function') {
      return window.RILightbox.open({ type, href, group, ...opts });
    }
    // Fallback: render directly into #ri-modal
    const {host,box,dlg} = getHost();
    box.innerHTML = '';
    let el;
    if (type === 'image' || type === 'images') {
      el = document.createElement('img'); el.src = href; el.alt = ''; el.style.maxWidth='90%'; el.style.maxHeight='90%';
    } else if (type === 'pdf') {
      el = document.createElement('iframe'); el.src = href; el.style.width='90%'; el.style.height='90%'; el.setAttribute('allow','fullscreen');
    } else {
      el = document.createElement('video'); el.src = href; el.controls = true; el.autoplay = true; el.style.maxWidth='90%'; el.style.maxHeight='90%';
    }
    box.appendChild(el);
  }

  // Let your existing core handle anchors if present; otherwise we’ll handle.
  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[data-lightbox]');
    if (!a) return;

    const type  = (a.getAttribute('data-lightbox') || 'image').toLowerCase();
    const href  = a.getAttribute('href') || '';
    const group = a.getAttribute('data-lb-group') || '';

    if (!href) return;

    // If a first-party core registered (e.g., Mayorga’s lightbox-core), it likely also intercepts.
    // We only take over when it doesn’t prevent default.
    e.preventDefault();
    openLightbox(type, href, group, {});
  }, true);

  /* -------- Video micro-controls (inline / lightbox / fullscreen) -------- */
  function renderInlinePlayer(container, src, poster) {
    container.innerHTML = '';
    const v = document.createElement('video');
    v.src = src; v.controls = true; v.autoplay = true; if (poster) v.poster = poster;
    v.style.width = '100%'; v.style.height = 'auto';
    container.appendChild(v);
    v.focus();
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-ri-video-mode]');
    if (!btn) return;

    const figure = btn.closest('figure.gv');
    if (!figure) return;

    const anchor = figure.querySelector('a[data-lightbox]');
    if (!anchor) return;

    const mode = btn.getAttribute('data-ri-video-mode');
    const href = anchor.getAttribute('href') || '';
    const poster = (figure.querySelector('img')||{}).src || '';

    if (!href) return;
    e.preventDefault();

    if (mode === 'inline') return renderInlinePlayer(figure, href, poster);
    if (mode === 'lightbox') return openLightbox('video', href, anchor.getAttribute('data-lb-group') || '', {});
    if (mode === 'fullscreen') {
      const v = document.createElement('video'); v.src = href; v.controls = true; v.autoplay = true;
      v.style.position='fixed'; v.style.left='-9999px'; document.body.appendChild(v);
      const goFS = v.requestFullscreen || v.webkitRequestFullscreen || v.msRequestFullscreen;
      if (goFS) {
        goFS.call(v).then(()=>{ v.play().catch(()=>{}); }).catch(()=>{ openLightbox('video', href, '', {}); }).finally(()=>{
          const cleanup=()=>{ v.remove(); document.removeEventListener('fullscreenchange', cleanup); document.removeEventListener('webkitfullscreenchange', cleanup); };
          document.addEventListener('fullscreenchange', cleanup); document.addEventListener('webkitfullscreenchange', cleanup);
        });
      } else {
        openLightbox('video', href, '', {}); v.remove();
      }
    }
  }, true);
})();
