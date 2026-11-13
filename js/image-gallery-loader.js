/**
 * Image Gallery Loader (safety wrapper)
 * @file    /js/image-gallery-loader.js
 * @version 2025.09.06.r2
 *
 * Purpose:
 *  - Prevent "root.querySelectorAll is not a function" when DOMContentLoaded passes an Event.
 *  - Wrap existing global/document-level `scan` without changing your gallery logic.
 *  - Coerce any non-Node argument (e.g., the DOM event) to `document` before delegating.
 *
 * What it does NOT do:
 *  - It does NOT rewrite or remove your original scanning/rendering code.
 *  - It does NOT remove existing event listeners.
 *
 * Strategy:
 *  - If `window.scan` or `document.scan` exists, wrap it with a guard.
 *  - If not yet defined, wait for DOMContentLoaded, then wrap and ensure a final
 *    manual call to `scan(document)` so galleries initialize even if a prior
 *    listener invoked `scan(event)` before our wrapper was in place.
 */

(function () {
  'use strict';

  // Convert any input into a Node (Document/Element) that exposes querySelectorAll.
  function coerceRoot(root) {
    // Event object? (has .type + .target) → use document
    if (root && typeof root === 'object' && ('type' in root) && ('target' in root)) {
      return document;
    }
    if (!root || typeof root.querySelectorAll !== 'function') {
      return document;
    }
    return root;
  }

  // Wrap an existing scan function so it always gets a valid root Node.
  function makeSafeScan(fn) {
    if (typeof fn !== 'function') return null;
    var wrapped = function safeScan(root) {
      try {
        return fn.call(this, coerceRoot(root));
      } catch (err) {
        console.error('[image-gallery-loader] scan error:', err);
        return undefined;
      }
    };
    try { Object.defineProperty(wrapped, 'name', { value: 'scan', configurable: true }); } catch (_) {}
    return wrapped;
  }

  // Attempt to wrap whichever scan reference exists now.
  function wrapExistingScanIfAny() {
    var wrapped = false;

    if (typeof window.scan === 'function') {
      var origWin = window.scan;
      var safeWin = makeSafeScan(origWin);
      if (safeWin) {
        window.scan = safeWin;
        wrapped = true;
      }
    }

    if (typeof document.scan === 'function') {
      var origDoc = document.scan;
      var safeDoc = makeSafeScan(origDoc);
      if (safeDoc) {
        document.scan = safeDoc;
        wrapped = true;
      }
    }

    return wrapped;
  }

  // Try to wrap immediately (in case script order already defined scan). 
  var didWrapNow = wrapExistingScanIfAny();

  // If not yet defined, wait for DOM ready and attempt again; then force init.
  if (!didWrapNow) {
    document.addEventListener('DOMContentLoaded', function onReadyOnce(evt) {
      wrapExistingScanIfAny();

      // Final safety net: ensure initialization even if previous listeners
      // invoked scan(event) before our wrapper existed.
      try {
        if (typeof window.scan === 'function') {
          window.scan(document);
        } else if (typeof document.scan === 'function') {
          document.scan(document);
        }
      } catch (err) {
        console.error('[image-gallery-loader] fallback init error:', err);
      }
    }, { once: true });
  }

})();
