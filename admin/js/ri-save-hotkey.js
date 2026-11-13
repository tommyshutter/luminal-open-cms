/* RI Save Hotkey — Cmd/Ctrl+S to Save
   - Looks for a save control in this priority:
     1) First visible element with [data-ri-save]
     2) #btn-save
     3) .js-save
     4) [type=submit] inside the first visible form
   - Dispatches custom events: 'ri:save' before, 'ri:save:after' after.
*/
(function(){
  if (window.__RI_SAVE_HOTKEY_BOUND__) return;
  window.__RI_SAVE_HOTKEY_BOUND__ = true;

  const isVisible = el => !!(el && el.offsetParent !== null && !el.disabled);

  function findSaveTarget(){
    // Highest confidence: opt-in attribute
    let el = Array.from(document.querySelectorAll('[data-ri-save]')).find(isVisible);
    if (el) return el;

    // Known IDs/classes in your admin
    el = document.querySelector('#btn-save');
    if (isVisible(el)) return el;

    el = Array.from(document.querySelectorAll('.js-save')).find(isVisible);
    if (el) return el;

    // Last resort: a visible submit button in the first visible form
    const form = Array.from(document.querySelectorAll('form')).find(isVisible);
    if (form) {
      el = Array.from(form.querySelectorAll('button[type="submit"],input[type="submit"]')).find(isVisible);
      if (el) return el;
      return form; // fallback: submit the form directly
    }
    return null;
  }

  let lastSaveAt = 0;

  function triggerSave(){
    const now = Date.now();
    if (now - lastSaveAt < 400) return; // simple de-bounce guard
    lastSaveAt = now;

    const target = findSaveTarget();
    const detail = { target };

    document.dispatchEvent(new CustomEvent('ri:save', { bubbles:true, detail }));

    if (!target) {
      if (window.RIToaster && RIToaster.warn) RIToaster.warn('Save: no target found on this page');
      return;
    }

    try {
      // If target is a form, submit it
      if (target.tagName === 'FORM') {
        target.requestSubmit ? target.requestSubmit() : target.submit();
      } else {
        // If not a form, click it
        target.click();
      }
      if (window.RIToaster && RIToaster.info) RIToaster.info('Save triggered via hotkey');
    } catch (e) {
      if (window.RIToaster && RIToaster.error) RIToaster.error('Save failed: ' + e);
      console.error(e);
    } finally {
      document.dispatchEvent(new CustomEvent('ri:save:after', { bubbles:true, detail }));
    }
  }

  window.addEventListener('keydown', function(e){
    // Cmd+S (Mac) or Ctrl+S (Win/Linux)
    const key = (e.key || '').toLowerCase();
    if (key !== 's') return;
    if (!(e.metaKey || e.ctrlKey)) return;

    // Always prevent the browser "Save Page" dialog
    e.preventDefault();
    e.stopPropagation();

    triggerSave();
  });

  // Breadcrumb
  if (window.RIToaster && RIToaster.debug) RIToaster.debug('RI Save Hotkey attached (Cmd/Ctrl+S)');
})();
