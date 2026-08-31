/* Page Manager: Delete binder
   Wires .js-del pills to /admin/modules/PageManager/api/delete_page_dir.php and removes the row on success.
*/
(function(){
  const LIST = document.getElementById('existing-pages') || document;

  function toast(level, msg){
    if (window.RIToaster && typeof RIToaster[level] === 'function') {
      RIToaster[level](msg);
    } else {
      (level === 'error' ? console.error : console.log)(msg);
    }
  }

  function findSlugFromNode(node){
    // 1) explicit data-page
    let slug = node.getAttribute('data-page');
    if (slug) return slug;

    // 2) nearest ancestor that carries the slug
    const holder = node.closest('[data-page], [data-slug]');
    if (holder) {
      slug = holder.getAttribute('data-page') || holder.getAttribute('data-slug');
      if (slug) return slug;
    }

    // 3) attempt from href (?p= or ?delete=)
    const href = node.getAttribute('href') || '';
    if (href && href.includes('?')) {
      const u = new URL(href, location.origin);
      return u.searchParams.get('p') || u.searchParams.get('delete') || '';
    }
    return '';
  }

  async function doDelete(slug, pill){
    if (!slug) { toast('error','Delete failed: missing slug'); return; }
    if (!confirm(`Delete page “${slug}”? This removes /admin/data/pages/${slug}`)) return;

    // Busy UI
    const prev = pill.textContent;
    pill.textContent = 'Deleting…';
    pill.classList.add('is-busy');
    pill.setAttribute('aria-busy','true');
    pill.disabled = true;

    try {
      const res = await fetch('/admin/modules/PageManager/api/delete_page_dir.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        credentials: 'same-origin',
        body: new URLSearchParams({ slug })
      });
      const json = await res.json().catch(()=>({ok:false,error:'Invalid JSON'}));

      if (json.ok) {
        toast('info', `Deleted ${slug}` + (json.moved_to ? ` (moved to trash)` : ''));
        // Remove row line
        const row = pill.closest('.row-line') || pill.closest('[data-page="'+slug+'"]') || pill.closest('[data-slug="'+slug+'"]');
        if (row) row.remove();
      } else {
        toast('error', `Delete failed: ${json.error || ('HTTP '+res.status)}`);
        pill.textContent = prev;
      }
    } catch (e) {
      toast('error', 'Delete error: ' + e);
      pill.textContent = prev;
    } finally {
      pill.classList.remove('is-busy');
      pill.removeAttribute('aria-busy');
      pill.disabled = false;
    }
  }

  LIST.addEventListener('click', function(e){
    const pill = e.target.closest('.js-del'); // your pill class in the HTML
    if (!pill) return;
    e.preventDefault();
    e.stopPropagation();
    const slug = findSlugFromNode(pill);
    doDelete(slug, pill);
  });

  // a tiny style so the pill shows "busy"
  const style = document.createElement('style');
  style.textContent = `
    .js-del.is-busy { opacity:.6; pointer-events:none; }
  `;
  document.head.appendChild(style);

  // breadcrumb
  if (window.RIToaster && RIToaster.debug) RIToaster.debug('Page Manager: delete binder attached');
})();
