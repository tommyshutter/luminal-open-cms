/**
 * @appname  Earl's / RI Admin
 * @file     /admin/menu-manager/menu-manager.js
 * @version  2025.10.02.2006.00-EST r1202
 * @desc     Drag-to-reorder, remove, quick-add pills, save to JSON. Absolute endpoints.
 */

(function(){
  var list      = document.getElementById('menu-list');
  var saveBtn   = document.getElementById('mm-save');
  var statusBox = document.getElementById('mm-status');
  var pillsBox  = document.getElementById('pill-list');

  if (!list) return;

  function setStatus(msg, level){
    if (!statusBox) return;
    statusBox.textContent = msg;
    statusBox.className = 'mm-status ' + (level || '');
  }

  /* ---------- Drag & Drop reorder ---------- */
  var draggingEl = null;

  list.addEventListener('dragstart', function(e){
    var li = e.target.closest('.mm-item');
    if (!li) return;
    draggingEl = li;
    e.dataTransfer.effectAllowed = 'move';
    li.classList.add('dragging');
  });

  list.addEventListener('dragend', function(e){
    if (draggingEl) draggingEl.classList.remove('dragging');
    draggingEl = null;
  });

  list.addEventListener('dragover', function(e){
    e.preventDefault();
    var after = getDragAfterElement(list, e.clientY);
    if (!draggingEl) return;
    if (after == null) {
      list.appendChild(draggingEl);
    } else {
      list.insertBefore(draggingEl, after);
    }
  });

  function getDragAfterElement(container, y){
    var items = [].slice.call(container.querySelectorAll('.mm-item:not(.dragging)'));
    var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    items.forEach(function(child){
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height/2;
      if (offset < 0 && offset > closest.offset) {
        closest = { offset: offset, element: child };
      }
    });
    return closest.element;
  }

  /* ---------- Remove button ---------- */
  list.addEventListener('click', function(e){
    if (e.target && e.target.classList.contains('mm-remove')) {
      var li = e.target.closest('.mm-item');
      if (li) li.parentNode.removeChild(li);
      setStatus('Removed item', 'warn');
    }
  });

  /* ---------- Pills: quick add ---------- */
  if (pillsBox) {
    pillsBox.addEventListener('click', function(e){
      var pill = e.target.closest('.mm-pill');
      if (!pill) return;
      var slug  = pill.getAttribute('data-slug') || '';
      var url   = pill.getAttribute('data-url')  || '';
      var title = pill.getAttribute('data-title')|| '';
      if (!slug || alreadyInMenu(slug)) {
        setStatus('Already in menu: ' + title, 'info');
        return;
      }
      var li = document.createElement('li');
      li.className = 'mm-item';
      li.setAttribute('draggable', 'true');
      li.setAttribute('data-slug', slug);
      li.setAttribute('data-url',  url);
      li.setAttribute('data-title', title);
      li.innerHTML =
        '<span class="mm-drag-handle" aria-hidden="true">↕</span>'
        + '<a class="mm-item-title" href="'+url+'" target="_blank" rel="noopener">'+escapeHtml(title)+'</a>'
        + '<code class="mm-item-slug">/'+escapeHtml(slug)+'</code>'
        + '<button class="mm-btn mm-btn-danger mm-remove" title="Remove">Remove</button>';
      // Append at bottom
      list.appendChild(li);
      // Remove pill (optional) so it can’t be added twice
      pill.parentNode.removeChild(pill);
      setStatus('Added: ' + title, 'success');
    });
  }

  function alreadyInMenu(slug){
    return !!list.querySelector('.mm-item[data-slug="'+cssEscape(slug)+'"]');
  }

  /* ---------- Save ---------- */
  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      var items = [].slice.call(list.querySelectorAll('.mm-item'));
      var payload = items.map(function(li){
        return {
          title: li.getAttribute('data-title') || li.querySelector('.mm-item-title')?.textContent?.trim() || '',
          url:   li.getAttribute('data-url')   || '',
          slug:  li.getAttribute('data-slug')  || ''
        };
      }).filter(function(row){
        return row.title && row.url && row.slug;
      });

      setStatus('Saving…', 'info');

      fetch('/admin/modules/MenuManager/menu-manager.save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (resp && resp.ok) {
          setStatus('Saved ('+ (resp.count||0) +' items)', 'success');
        } else {
          setStatus('Save failed: ' + (resp && resp.error ? resp.error : 'unknown'), 'error');
        }
      })
      .catch(function(err){
        setStatus('Save error: ' + err, 'error');
      });
    });
  }

  /* ---------- utils ---------- */
  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function cssEscape(s){
    // minimal escaper for attribute selector usage
    return (s||'').replace(/(["\\])/g, '\\$1');
  }
})();
