/*!
 * save-hotkey.js — CMS-wide Ctrl/Cmd + S save (loaded on every admin page via admin_footer.php).
 *
 *  1) Always preventDefault on Ctrl/Cmd+S in admin — kills the browser "save this page" dialog.
 *  2) Triggers the current context's save, resolved in priority order:
 *       a. an element tagged [data-cms-save] inside the topmost open modal
 *       b. a page-level [data-cms-save]
 *       c. a careful "Save" button heuristic (plain Save; ignores Save as/template/draft/…)
 *
 *  Opt out: put [data-cms-save-skip] on a container whose module handles Ctrl+S itself.
 *  Opt in : put data-cms-save on the real save control for precise, unambiguous targeting.
 */
(function () {
  'use strict';
  if (window.__cmsSaveHotkey) return;
  window.__cmsSaveHotkey = true;

  function visible(el) {
    if (!el || el.disabled) return false;
    if (el.getAttribute && el.getAttribute('aria-disabled') === 'true') return false;
    return !!(el.offsetWidth || el.offsetHeight || (el.getClientRects && el.getClientRects().length));
  }

  // The topmost open modal-ish container, or document if none is open.
  function activeScope() {
    var sels = ['.sp-modal.show', '[role="dialog"]', '.modal.show', '.modal.open',
                '.pm-edit-modal', '.as-modal', '.cs-modal-overlay', '.gm-editor'];
    var hits = [];
    sels.forEach(function (s) {
      try {
        [].forEach.call(document.querySelectorAll(s), function (e) { if (visible(e)) hits.push(e); });
      } catch (_) {}
    });
    return hits.length ? hits[hits.length - 1] : document;
  }

  function pick(scope) {
    // 1) explicit opt-in always wins
    var tagged = [].filter.call(scope.querySelectorAll('[data-cms-save]'), visible);
    if (tagged.length) return tagged[0];
    // 2) careful heuristic: a plain "Save" control — never "save as / template / draft / …"
    var bad  = /template|draft|\bas\b|copy|export|revision|version|preview|search|filter|delete|discard/i;
    var good = /^\s*(💾\s*)?save(\s*(page|changes|task|gallery|settings|now|note|&\s*close|and\s*close))?\s*(\(.*\))?\s*$/i;
    var cands = scope.querySelectorAll('button, input[type="submit"], a.btn, .btn, [role="button"]');
    var hit = [].filter.call(cands, function (el) {
      if (!visible(el)) return false;
      var t = (el.value || el.textContent || '').replace(/\s+/g, ' ').trim();
      return good.test(t) && !bad.test(t);
    });
    return hit[0] || null;
  }

  document.addEventListener('keydown', function (e) {
    if (e.altKey || !(e.ctrlKey || e.metaKey)) return;
    if ((e.key || '').toLowerCase() !== 's') return;
    // Region opted out (module runs its own Ctrl+S)?
    if (e.target && e.target.closest && e.target.closest('[data-cms-save-skip]')) return;

    e.preventDefault();                      // always eat the browser dialog in admin
    var scope = activeScope();
    var btn = pick(scope) || (scope !== document ? pick(document) : null);
    if (btn) {
      btn.click();                           // the module's own handler runs + shows its status
    } else {
      try {
        if (window.adminToaster && adminToaster.push) adminToaster.push({ level: 'info', msg: 'Nothing to save on this screen' });
      } catch (_) {}
    }
  }, false);
})();
