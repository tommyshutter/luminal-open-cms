/**
 * @file SITE-ROOT/admin/js/manager-tray-init.js
 * @version 2025.08.28.1725
 * @desc Thumbnails in editor trays + Sortable + drag-to-trash.
 */
(function(){
  const $ = s => document.querySelector(s);

  // global ref used for drag-to-trash from tray
  let currentDragEl = null;

  function thumbURL(type, path){
    if (type === 'video') {
      return 'ajax/media_thumb.php?type=video&path=' + encodeURIComponent(path);
    }
    return 'ajax/media_thumb_image.php?path=' + encodeURIComponent(path);
  }

  function makeTrayCard(type, path){
    const name = path.split('/').pop();
    const card = document.createElement('div');
    card.className = 'thumb-card';
    card.dataset.path = path;
    card.draggable = true;
    card.innerHTML = `
      <img src="${thumbURL(type, path)}" alt="">
      <div class="filename">${name}</div>
    `;
    card.addEventListener('dragstart', () => { currentDragEl = card; });
    card.addEventListener('dragend', () => { currentDragEl = null; });
    return card;
  }

  function initTray(sel, type, trashSel){
    const grid = $(sel);
    if (!grid || grid.dataset.sortableInit === '1') return;

    // Accept drops from the left media panel
    grid.addEventListener('dragover', e => e.preventDefault());
    grid.addEventListener('drop', e => {
      e.preventDefault();
      const path = e.dataTransfer.getData('text/plain');
      if (!path) return;
      grid.appendChild(makeTrayCard(type, path));
    });

    // Sort inside tray
    Sortable.create(grid, { animation: 150 });

    // Drag-to-trash
    const trash = $(trashSel);
    if (trash) {
      trash.addEventListener('dragover', e => e.preventDefault());
      trash.addEventListener('drop', e => {
        e.preventDefault();
        if (currentDragEl && grid.contains(currentDragEl)) {
          currentDragEl.remove();
          currentDragEl = null;
        }
      });
    }

    grid.dataset.sortableInit = '1';
  }

  // video tray
  initTray('#video-grid', 'video', '#videos-manager .trash-zone');
  // image tray
  initTray('#image-grid', 'image', '#images-manager .trash-zone');
})();
