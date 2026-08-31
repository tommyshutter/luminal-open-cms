/**
 * PDF Gallery Loader (safety wrapper)
 * @file    /js/pdf-gallery-loader.js
 * @version 2025.09.06.r2
 *
 * What this does:
 *  - Prevents "root.querySelectorAll is not a function" when DOMContentLoaded passes an Event.
 *  - Wraps the existing global/document-level `scan` without removing or rewriting your logic.
 *  - Coerces any non-Node argument (e.g., Event) to `document` before delegating.
 *
 * What it does NOT do:
 *  - It does NOT change your gallery scanning logic.
 *  - It does NOT remove existing functions or event listeners.
 *
 * How it works:
 *  - If `window.scan` or `document.scan` exists, we wrap it with a guard.
 *  - If neither exists yet at script evaluation time, we install a one-time
 *    DOMContentLoaded hook that will wrap whichever `scan` becomes available by then.
 *  - As a final safety net, we also add a post-DOMContentLoaded microtask that
 *    manually calls `scan(document)` if a naked `scan(event)` listener elsewhere
 *    still produced an error before our wrapper ran, ensuring galleries still initialize.
 */

(function () {
  'use strict';

  /**
   * Coerce any input to a Node-root suitable for querySelectorAll.
   * - If it's a DOM Event, return `document`.
   * - If it's falsy or lacks querySelectorAll, return `document`.
   * - Else, return it as-is.
   */
  function coerceRoot(root) {
    // If it's an Event (has type + target), treat as DOMContentLoaded/other event
    if (root && typeof root === 'object' && ('type' in root) && ('target' in root)) {
      return document;
    }
    // If not a Node with querySelectorAll, fall back to document
    if (!root || typeof root.querySelectorAll !== 'function') {
      return document;
    }
    return root;
  }

  /**
   * Wrap a given scan function so it always receives a Node (Document/Element).
   */
  function makeSafeScan(fn) {
    if (typeof fn !== 'function') return null;
    // Preserve original name in stack traces when possible
    var wrapped = function safeScan(root) {
      try {
        return fn.call(this, coerceRoot(root));
      } catch (err) {
        // Never crash page rendering; surface in console for devs
        console.error('[pdf-gallery-loader] scan error:', err);
        return undefined;
      }
    };
    try { Object.defineProperty(wrapped, 'name', { value: 'scan', configurable: true }); } catch (_) {}
    return wrapped;
  }

  /**
   * Install wrappers around existing scan function(s) if present.
   * Returns true if a wrapper was applied.
   */
  function wrapExistingScanIfAny() {
    var wrappedSomething = false;

    // Case A: global scan on window
    if (typeof window.scan === 'function') {
      var origWinScan = window.scan;
      var safeWinScan = makeSafeScan(origWinScan);
      if (safeWinScan) {
        window.scan = safeWinScan;
        wrappedSomething = true;
      }
    }

    // Case B: scan attached on document (e.g., called as HTMLDocument.scan in stacks)
    // Some stacks attach functions to document; respect and wrap.
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

  // Try to wrap immediately (if the script ordering already defined scan).
  var didWrapNow = wrapExistingScanIfAny();

  // If scan isn't defined yet, wait for DOMContentLoaded and try again.
  if (!didWrapNow) {
    document.addEventListener('DOMContentLoaded', function onReadyOnce(evt) {
      // Attempt wrapping again in case scan was defined later in the pipeline.
      wrapExistingScanIfAny();

      // FINAL SAFETY NET:
      // If a different listener invoked scan(event) and errored before we could wrap,
      // ensure we still initialize by manually calling whichever scan exists now
      // with a proper `document` root. This preserves behavior without removing
      // the original listener or changing its code.
      try {
        if (typeof window.scan === 'function') {
          window.scan(document);
        } else if (typeof document.scan === 'function') {
          document.scan(document);
        }
      } catch (err) {
        console.error('[pdf-gallery-loader] fallback init error:', err);
      }
    }, { once: true });
  }

})();
