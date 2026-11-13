/**
 * Video Gallery Loader (safety wrapper)
 * @file    /js/video-gallery-loader.js
 * @version 2025.09.06.r2
 *
 * Purpose:
 *  - Prevent "root.querySelectorAll is not a function" when DOMContentLoaded passes an Event.
 *  - Wrap existing global/document-level `scan` without changing your gallery logic.
 *  - Coerce any non-Node argument (e.g., Event) to `document` before delegating.
 *
 * What it does NOT do:
 *  - It does NOT rewrite or remove your original scanning/rendering code.
 *  - It does NOT remove existing event listeners.
 */

(function () {
  'use strict';

  // Coerce unknown input to a Node (Document/Element) with querySelectorAll.
  function coerceRoot(root) {
    // If it's a DOM Event (has .type + .target), treat it as a signal to use document
    if (root && typeof root === 'object' && ('type' in root) && ('target' in root)) {
      return document;
    }
    if (!root || typeof root.querySelectorAll !== 'function') {
      return document;
    }
    return root;
  }

  // Wrap an existing scan so it always receives a valid root Node.
  function makeSafeScan(fn) {
    if (typeof fn !== 'function') return null;
    var wrapped = function safeScan(root) {
      try {
        return fn.call(this, coerceRoot(root));
      } catch (err) {
        console.error('[video-gallery-loader] scan error:', err);
        return undefined;
      }
    };
    try { Object.defineProperty(wrapped, 'name', { value: 'scan', configurable: true }); } catch (_) {}
    return wrapped;
  }

  // Attempt to wrap whichever scan reference exists now.
  function wrapExistingScanIfAny() {
    var wrappedSomething = false;

    if (typeof window.scan === 'function') {
      var origWinScan = window.scan;
      var safeWinScan = makeSafeScan(origWinScan);
      if (safeWinScan) {
        window.scan = safeWinScan;
        wrappedSomething = true;
      }
    }

    if (typeof document.scan === 'function') {
      var origDocScan = document.scan;
      var safeDocScan = makeSafeScan(origDocScan);
      if (safeDocScan) {
        document.scan = safeDocScan;
        wrappedSomething = true;
      }
    }

    return wrappedSomething;
  }

  // Try to wrap immediately.
  var didWrapNow = wrapExistingScanIfAny();

  // If scan isn't defined yet, wait for DOM ready, then wrap and force init.
  if (!didWrapNow) {
    document.addEventListener('DOMContentLoaded', function onReadyOnce(evt) {
      wrapExistingScanIfAny();

      // Ensure initialization even if earlier listeners invoked scan(event)
      try {
        if (typeof window.scan === 'function') {
          window.scan(document);
        } else if (typeof document.scan === 'function') {
          document.scan(document);
        }
      } catch (err) {
        console.error('[video-gallery-loader] fallback init error:', err);
      }
    }, { once: true });
  }

})();
