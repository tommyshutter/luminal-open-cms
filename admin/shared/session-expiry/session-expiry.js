/**
 * Session-expiry notifier — tell the truth when the session died.
 *
 * @file /admin/shared/session-expiry/session-expiry.js
 *
 * THE PROBLEM (found 2026-07-19 chasing a phantom "Save Failed" in Podcast Manager →
 * Upload Defaults): guard_require_auth() answers an unauthenticated XHR with HTTP 401 and
 *   {"ok": false, "error": "unauthorized"}
 * but module JS almost universally tests `r.success`. That key is absent, so the response
 * evaluates falsy and the module reports its own generic failure — "Save failed", "Delete
 * failed" — with no hint that the real cause is an expired session. 15 modules share this
 * pattern, so the misdiagnosis is fleet-wide: you hunt a save bug that does not exist.
 *
 * THE FIX: one passive observer, loaded once by admin_footer.php, that watches for HTTP 401
 * on any admin XHR/fetch and surfaces an honest banner. It is deliberately READ-ONLY — it
 * inspects `status` only, never reads, clones, or rewrites a response body, and never
 * suppresses the module's own handler. Worst case it does nothing; it cannot change what a
 * module receives.
 *
 * Only 401 is treated as expiry (that is exactly what the guard emits). 403 is a permission
 * problem on a live session and is left alone.
 */
(function () {
    'use strict';
    if (window.__lumSessionExpiryInit) return;   // idempotent — module pages can re-include
    window.__lumSessionExpiryInit = true;

    var shown = false;

    function banner() {
        if (shown) return;
        shown = true;
        var el = document.createElement('div');
        el.id = 'lum-session-expired';
        el.setAttribute('role', 'alert');
        el.innerHTML =
            '<span class="lse-icon">&#128274;</span>' +
            '<span class="lse-text"><b>Your session expired.</b> You are signed out, so changes on this page ' +
            'will not save. Reload to sign in again &mdash; unsaved edits on screen will be lost.</span>' +
            '<button type="button" class="lse-btn" id="lse-reload">Reload &amp; sign in</button>' +
            '<button type="button" class="lse-x" id="lse-dismiss" aria-label="Dismiss">&times;</button>';
        (document.body || document.documentElement).appendChild(el);
        var r = document.getElementById('lse-reload');
        if (r) r.addEventListener('click', function () { window.location.reload(); });
        var d = document.getElementById('lse-dismiss');
        if (d) d.addEventListener('click', function () { el.remove(); });
    }

    function check(status) { if (status === 401) banner(); }

    // ---- XMLHttpRequest: attach a listener, never replace onload/onerror ----
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function () {
        try {
            this.addEventListener('load', function () { check(this.status); });
        } catch (e) { /* never break the request */ }
        return origOpen.apply(this, arguments);
    };

    // ---- fetch: inspect status on the way past, return the untouched response ----
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function () {
            return origFetch.apply(this, arguments).then(function (res) {
                try { check(res.status); } catch (e) { /* ignore */ }
                return res;   // same object, unread and unmodified
            });
        };
    }
})();
