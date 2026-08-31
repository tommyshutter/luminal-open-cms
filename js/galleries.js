/**
 * @file SITE-ROOT/js/galleries.js
 * @version 2025.08.29.2335
 * Matches shortcodes output (anchors with .ri-g-item) and keeps video modal.
 */
(function(){
  // -------- Video overlay --------
  const vModal = document.createElement('div');
  vModal.id = 'ri-v-modal';
  vModal.innerHTML = `<div class="wrap"><button class="close">Close</button><video controls></video></div>`;
  document.body.appendChild(vModal);
  const vEl = vModal.querySelector('video'), vClose = vModal.querySelector('.close');
  vClose.onclick = ()=>{ vEl.pause(); vModal.style.display='none'; vEl.src=''; };
  vModal.addEventListener('click', e=>{ if(e.target===vModal) vClose.click(); });

  document.addEventListener('click', (e)=>{
    const box = e.target.closest('.ri-video'); if(!box) return;
    const src = box.querySelector('source, video, a')?.getAttribute('src') || box.querySelector('video')?.currentSrc;
    if(!src) return;
    vEl.src = src; vModal.style.display='flex'; vEl.play?.();
  }, false);

  // -------- Image overlay (match .ri-g-item from shortcodes) --------
  const iModal = document.createElement('div');
  iModal.id = 'ri-img-modal';
  iModal.style.display = 'none';
  iModal.innerHTML = `
    <div id="ri-img-modal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.85);z-index:9999">
      <div class="wrap" style="max-width:92vw;max-height:92vh;position:relative">
        <button id="ri-img-close" style="position:absolute;right:-8px;top:-44px">Close</button>
        <img id="ri-img-view" alt="" style="max-width:92vw;max-height:92vh;display:block;border-radius:10px"/>
      </div>
    </div>`;
  // Use a real element tree for click handling
  const shadow = document.createElement('div');
  shadow.innerHTML = iModal.innerHTML;
  const imgModal = shadow.firstElementChild;
  document.body.appendChild(imgModal);
  const imgView  = imgModal.querySelector('#ri-img-view');
  const imgClose = imgModal.querySelector('#ri-img-close');
  imgClose.onclick = ()=>{ imgModal.style.display='none'; imgView.src=''; };
  imgModal.addEventListener('click', e=>{ if(e.target===imgModal) imgClose.click(); });

  document.addEventListener('click', (e)=>{
    // Support both the new (.ri-g-item) and any legacy (.ri-tile.ri-img) anchors
    const a = e.target.closest('a.ri-g-item, a.ri-tile.ri-img'); if(!a) return;
    // Only image clicks (ignore PDFs/videos)
    const href = a.getAttribute('href') || '';
    if (!href.match(/\.(png|jpe?g|gif|webp|avif)(\?.*)?$/i)) return;
    e.preventDefault();
    imgView.src = href;
    imgModal.style.display = 'flex';
  }, false);
})();
