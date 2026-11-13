/**
 * Luminal CMS — Shared Upload Progress meter.
 *
 * A stacked, self-peeling progress list shared by every admin upload surface
 * (GalleryManager/Explorer, PageManager, FileManager, PodcastManager, …) so the
 * look & feel is consistent everywhere. One row per file: name · size · percent
 * with a bar; on completion the row flips to "completed" and peels off; failures
 * linger (click to dismiss).
 *
 * Renders in the TOP-MOST same-origin window, so a meter triggered from inside
 * an Explorer iframe is never clipped — it floats over the whole admin shell.
 *
 * Transparent / glassy surfaces only (BackgroundManager rules).
 *
 * Public API (window.LumUpload):
 *   uploadEach(opts) -> Promise<results[]>   // one XHR per file = TRUE per-file progress
 *       opts = { url, files, field='files[]', data={}, concurrency=3, anchor,
 *                okWhen=(res,status)=>bool, onItem, onAllDone }
 *       okWhen lets a caller whose JSON uses a non-standard success flag (e.g. {ok:…}
 *       instead of {success:…}, often returned at HTTP 200) drive the row's pass/fail
 *       colour honestly. Defaults to: 2xx AND res.success !== false.
 *       result item = { ok:bool, file:File, data:parsedJSON|null, status:int }
 *   add(name, size) -> handle                // low-level manual row
 *       handle = { progress(loaded,total), done(label?), fail(msg) }
 *   fmtSize(bytes) -> "128 MB"
 *
 * @module  shared/upload-progress
 * @version 1.1.0
 * @file    /admin/shared/upload-progress/upload-progress.js
 */
(function () {
  'use strict';
  if (window.LumUpload && window.LumUpload.__real) return; // singleton per document

  /* ---- helpers ---------------------------------------------------------- */
  function fmtSize(b) {
    if (b == null || isNaN(b)) return '';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, n = Number(b);
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (n >= 10 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
  }

  /* Resolve the rendering host = the topmost same-origin LumUpload instance, so
   * uploads fired from an iframe surface their meter on the parent admin shell. */
  function host() {
    try {
      if (window.top && window.top !== window &&
          window.top.LumUpload && window.top.LumUpload.__real) {
        return window.top.LumUpload;
      }
    } catch (e) { /* cross-origin — fall back to self */ }
    return PUB;
  }

  /* ---- DOM: global corner stack (fallback) + anchored point-of-use panel - */
  var stack = null;
  function ensureStack() {
    if (stack && document.body.contains(stack)) return stack;
    stack = document.getElementById('lum-upload-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'lum-upload-stack';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('role', 'status');
    }
    if (!document.body.contains(stack)) document.body.appendChild(stack);
    return stack;
  }

  /* Anchored panel — a momentary, self-expanding progress panel rendered right
   * at the upload control ("in the piece it's used in") instead of the global
   * corner stack. Lives in THIS document next to the anchor, so an in-iframe
   * uploader shows progress beside its own control. Expands when active,
   * collapses + removes itself once every row has peeled. */
  function resolveAnchor(a) {
    if (!a) return null;
    if (typeof a === 'string') return document.querySelector(a);
    return (a && a.nodeType === 1) ? a : null;
  }
  function ensureAnchoredPanel(anchorEl) {
    var panel = anchorEl.nextElementSibling;
    if (!panel || !panel.classList || !panel.classList.contains('lum-up-panel')) {
      panel = document.createElement('div');
      panel.className = 'lum-up-panel';
      panel.setAttribute('aria-live', 'polite');
      panel.innerHTML = '<div class="lum-up-panel-head"><span class="lum-up-panel-count"></span></div>' +
                        '<div class="lum-up-panel-rows"></div>';
      if (anchorEl.parentNode) anchorEl.parentNode.insertBefore(panel, anchorEl.nextSibling);
    }
    requestAnimationFrame(function () { panel.classList.add('lum-up-panel-open'); });  // momentary expand
    return panel;
  }
  function panelRefresh(panel) {
    var all  = panel.querySelectorAll('.lum-up-panel-rows .lum-up-row');
    var done = panel.querySelectorAll('.lum-up-panel-rows .lum-up-row.done, .lum-up-panel-rows .lum-up-row.error').length;
    var cnt  = panel.querySelector('.lum-up-panel-count');
    if (cnt) cnt.textContent = all.length ? ((done >= all.length ? 'Done — ' : 'Uploading ') + Math.min(done, all.length) + ' / ' + all.length) : '';
    if (!all.length) {                                  // collapse + self-remove when empty
      panel.classList.remove('lum-up-panel-open');
      setTimeout(function () {
        if (panel.parentNode && !panel.querySelectorAll('.lum-up-row').length) panel.parentNode.removeChild(panel);
      }, 350);
    }
  }

  /* Add one row; opts.anchor → anchored point-of-use panel, else global stack.
   * Returns a handle { progress, done, fail } the caller drives. */
  function rowAdd(name, size, opts) {
    var anchorEl = opts && resolveAnchor(opts.anchor);
    var panel = null, container;
    if (anchorEl) { panel = ensureAnchoredPanel(anchorEl); container = panel.querySelector('.lum-up-panel-rows'); }
    else { container = ensureStack(); }

    var row = document.createElement('div');
    row.className = 'lum-up-row';
    row.innerHTML =
      '<div class="lum-up-meta">' +
        '<span class="lum-up-name"></span>' +
        '<span class="lum-up-stat"></span>' +
      '</div>' +
      '<div class="lum-up-track"><div class="lum-up-bar"></div></div>';
    row.querySelector('.lum-up-name').textContent = name || 'file';
    var bar  = row.querySelector('.lum-up-bar');
    var stat = row.querySelector('.lum-up-stat');
    var sz   = fmtSize(size);
    stat.textContent = (sz ? sz + ' · ' : '') + '0%';
    container.appendChild(row);
    if (panel) panelRefresh(panel);

    var settled = false;
    function done() { if (panel) panelRefresh(panel); }
    return {
      progress: function (loaded, total) {
        if (settled) return;
        var pct = total ? Math.min(100, Math.round(loaded / total * 100)) : 0;
        bar.style.width = pct + '%';
        stat.textContent = (sz ? sz + ' · ' : '') + pct + '%';
      },
      done: function (label) {
        if (settled) return; settled = true;
        bar.style.width = '100%';
        row.classList.add('done');
        stat.textContent = label || 'completed';
        if (panel) panelRefresh(panel);
        setTimeout(function () { peel(row, done); }, 1800);
      },
      fail: function (msg) {
        if (settled) return; settled = true;
        row.classList.add('error');
        stat.textContent = msg || 'failed';
        row.title = 'Click to dismiss';
        if (panel) panelRefresh(panel);
        row.addEventListener('click', function () { peel(row, done); });
      }
    };
  }

  function peel(row, after) {
    row.classList.add('peel');
    setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); if (after) after(); }, 300);
  }

  /* ---- one XHR per file = true per-file progress ------------------------ */
  function sendOne(url, file, field, data, anchor, okWhen) {
    return new Promise(function (resolve) {
      var handle = anchor ? PUB.add(file.name, file.size, { anchor: anchor })
                          : host().add(file.name, file.size);
      var fd = new FormData();
      Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
      fd.append(field, file, file.name);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) handle.progress(e.loaded, e.total);
      };
      xhr.onload = function () {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) {}
        var http2xx = xhr.status >= 200 && xhr.status < 300;
        var ok = http2xx && (okWhen ? !!okWhen(res, xhr.status) : (!res || res.success !== false));
        if (ok) { handle.done('completed'); resolve({ ok: true, file: file, data: res, status: xhr.status }); }
        else {
          handle.fail((res && (res.message || res.error)) || ('HTTP ' + xhr.status));
          resolve({ ok: false, file: file, data: res, status: xhr.status });
        }
      };
      xhr.onerror = function () { handle.fail('network error'); resolve({ ok: false, file: file, status: 0 }); };
      xhr.send(fd);
    });
  }

  /* Upload a set of files, one request each, bounded concurrency. */
  function uploadEach(opts) {
    opts = opts || {};
    var files = [].slice.call(opts.files || []);
    var url = opts.url, field = opts.field || 'files[]', data = opts.data || {};
    var conc = Math.max(1, opts.concurrency || 3);
    var anchor = opts.anchor || null;   // point-of-use panel; null = global stack
    var okWhen = (typeof opts.okWhen === 'function') ? opts.okWhen : null;
    var results = [], idx = 0, active = 0;

    return new Promise(function (resolve) {
      if (!files.length) return resolve(results);
      function pump() {
        while (active < conc && idx < files.length) {
          active++;
          sendOne(url, files[idx++], field, data, anchor, okWhen).then(function (r) {
            results.push(r); active--;
            if (opts.onItem) { try { opts.onItem(r); } catch (e) {} }
            if (results.length === files.length) {
              if (opts.onAllDone) { try { opts.onAllDone(results); } catch (e) {} }
              resolve(results);
            } else { pump(); }
          });
        }
      }
      pump();
    });
  }

  var PUB = {
    __real: true,
    add: rowAdd,
    uploadEach: uploadEach,
    fmtSize: fmtSize
  };
  window.LumUpload = PUB;
})();
