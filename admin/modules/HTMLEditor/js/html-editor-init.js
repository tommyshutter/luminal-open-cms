/**
 * Luminal CMS — Native WYSIWYG HTML Editor
 *
 * 100% custom contenteditable editor. No third-party libraries.
 * All formatting via document.execCommand + custom actions.
 *
 * @module  HTMLEditor
 * @version 1.0.0
 */
(function () {
  'use strict';

  var API = '/admin/modules/HTMLEditor/api.php';

  /* ── DOM refs ────────────────────────────────── */
  var $content   = document.getElementById('he-content');
  var $source    = document.getElementById('he-source');
  var $toolbar   = document.getElementById('he-toolbar');
  var $docSelect = document.getElementById('he-doc-select');
  var $docTitle  = document.getElementById('he-doc-title');
  var $dirty     = document.getElementById('he-dirty');
  var $save      = document.getElementById('he-save');
  var $export    = document.getElementById('he-export');
  var $del       = document.getElementById('he-delete');
  var $toast     = document.getElementById('he-toast');
  var $wordCount = document.getElementById('he-word-count');
  var $charCount = document.getElementById('he-char-count');
  var $elemPath  = document.getElementById('he-element-path');
  var $overlay   = document.getElementById('he-modal-overlay');
  var $modal     = document.getElementById('he-modal');
  var $modalTitle = document.getElementById('he-modal-title');
  var $modalBody  = document.getElementById('he-modal-body');
  var $modalFooter = document.getElementById('he-modal-footer');
  var $modalClose  = document.getElementById('he-modal-close');
  var $mediaFrame  = document.getElementById('he-media-frame');

  var $preview   = document.getElementById('he-preview');
  var $editorArea = document.getElementById('he-editor-area');

  var currentSlug = '';
  var dirty = false;
  var sourceMode = false;
  var previewMode = false;
  var isFullscreen = false;
  var savedRange = null;
  var pageCss = '';
  var pageJs = '';
  var muteBg = false;

  /* ── Toast ───────────────────────────────────── */
  function toast(msg, type) {
    $toast.textContent = msg;
    $toast.className = 'he-toast he-toast-show' + (type === 'error' ? ' he-toast-error' : '');
    setTimeout(function () { $toast.className = 'he-toast'; }, 3000);
  }

  /* ── Dirty state ─────────────────────────────── */
  function markDirty() {
    if (!dirty) { dirty = true; $dirty.style.display = ''; }
  }
  function clearDirty() {
    dirty = false; $dirty.style.display = 'none';
  }

  /* ── Save / restore selection ────────────────── */
  function saveSelection() {
    var sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
      savedRange = sel.getRangeAt(0).cloneRange();
    }
  }

  function restoreSelection() {
    if (savedRange) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }
  }

  /* ── execCommand wrapper ─────────────────────── */
  function exec(cmd, val) {
    $content.focus();
    document.execCommand(cmd, false, val || null);
    updateStatus();
    markDirty();
  }

  /* ── Toolbar button handlers ─────────────────── */
  $toolbar.addEventListener('mousedown', function (e) {
    var btn = e.target.closest('.he-tb-btn');
    if (btn) e.preventDefault();
  });

  $toolbar.addEventListener('click', function (e) {
    var btn = e.target.closest('.he-tb-btn');
    if (!btn) return;
    e.preventDefault();

    var cmd = btn.dataset.cmd;
    var action = btn.dataset.action;

    if (cmd) {
      exec(cmd);
    } else if (action) {
      handleAction(action);
    }
  });

  // Select dropdowns
  $toolbar.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel.matches('.he-tb-select')) return;
    var cmd = sel.dataset.cmd;
    var val = sel.value;
    if (!cmd || !val) return;

    if (cmd === 'formatBlock') {
      exec(cmd, '<' + val + '>');
    } else {
      exec(cmd, val);
    }
  });

  // Color inputs
  $toolbar.addEventListener('input', function (e) {
    if (!e.target.matches('.he-tb-color')) return;
    var cmd = e.target.dataset.cmd;
    if (cmd) {
      restoreSelection();
      exec(cmd, e.target.value);
    }
  });

  // Save selection before color picker opens
  $toolbar.addEventListener('click', function (e) {
    if (e.target.matches('.he-tb-color') || e.target.closest('.he-tb-color-wrap')) {
      saveSelection();
    }
  });

  /* ── Custom actions ──────────────────────────── */
  function handleAction(action) {
    switch (action) {
      case 'link':         actionLink(); break;
      case 'image':        actionImage(); break;
      case 'video':        actionVideo(); break;
      case 'table':        actionTable(); break;
      case 'special-char': actionSpecialChar(); break;
      case 'fa-icon':      actionFAIcon(); break;
      case 'find-replace': actionFindReplace(); break;
      case 'source-toggle': toggleSource(); break;
      case 'fullscreen':   toggleFullscreen(); break;
      case 'media-browser': actionMediaBrowser(); break;
      case 'browse-pages': actionBrowsePages(); break;
      case 'prettify':     actionPrettify(); break;
      case 'minify':       actionMinify(); break;
      case 'preview-toggle': togglePreview(); break;
      case 'mute-bg':      toggleMuteBg(); break;
    }
  }

  /* ── Modal helpers ───────────────────────────── */
  function openModal(title, bodyHTML, footerHTML, opts) {
    opts = opts || {};
    $modalTitle.textContent = title;
    $modalBody.innerHTML = bodyHTML;
    $modalFooter.innerHTML = footerHTML || '';
    $modalFooter.style.display = footerHTML ? '' : 'none';
    $modal.style.width = opts.width || '';
    $modal.style.maxWidth = opts.maxWidth || '500px';
    $overlay.style.display = '';
    var inp = $modalBody.querySelector('input,textarea,select');
    if (inp) setTimeout(function () { inp.focus(); }, 50);
  }

  function closeModal() {
    $overlay.style.display = 'none';
    $modalBody.innerHTML = '';
    $modalFooter.innerHTML = '';
    $content.focus();
  }

  $modalClose.addEventListener('click', closeModal);
  $overlay.addEventListener('click', function (e) {
    if (e.target === $overlay) closeModal();
  });

  /* ── Link action ─────────────────────────────── */
  function actionLink() {
    saveSelection();
    var existing = '';
    var sel = window.getSelection();
    if (sel && sel.rangeCount) {
      var anchor = sel.anchorNode;
      while (anchor && anchor.nodeName !== 'A') anchor = anchor.parentNode;
      if (anchor && anchor.nodeName === 'A') existing = anchor.href || '';
    }

    openModal('Insert Link',
      '<div class="he-form-row"><label>URL</label><input type="url" id="he-link-url" class="he-form-input" placeholder="https://" value="' + escAttr(existing) + '"></div>' +
      '<div class="he-form-row"><label>Target</label><select id="he-link-target" class="he-form-input"><option value="">Same window</option><option value="_blank">New window</option></select></div>',
      '<button class="he-modal-btn he-modal-btn-primary" id="he-link-ok">Insert</button> <button class="he-modal-btn" id="he-link-cancel">Cancel</button>'
    );

    document.getElementById('he-link-ok').addEventListener('click', function () {
      var url = document.getElementById('he-link-url').value.trim();
      var target = document.getElementById('he-link-target').value;
      if (!url) { closeModal(); return; }
      restoreSelection();
      exec('createLink', url);
      if (target) {
        var newSel = window.getSelection();
        if (newSel && newSel.anchorNode) {
          var a = newSel.anchorNode;
          while (a && a.nodeName !== 'A') a = a.parentNode;
          if (a && a.nodeName === 'A') a.setAttribute('target', target);
        }
      }
      closeModal();
    });
    document.getElementById('he-link-cancel').addEventListener('click', closeModal);
  }

  /* ── Image action ────────────────────────────── */
  function actionImage() {
    saveSelection();
    openModal('Insert Image',
      '<div class="he-form-row"><label>Image URL</label><input type="url" id="he-img-url" class="he-form-input" placeholder="https://... or /media/images/..."></div>' +
      '<div class="he-form-row"><label>Alt text</label><input type="text" id="he-img-alt" class="he-form-input" placeholder="Description"></div>' +
      '<div class="he-form-row"><label>Width</label><input type="text" id="he-img-width" class="he-form-input" placeholder="e.g. 400 or 100%"></div>',
      '<button class="he-modal-btn he-modal-btn-primary" id="he-img-ok">Insert</button> <button class="he-modal-btn" id="he-img-cancel">Cancel</button>'
    );
    document.getElementById('he-img-ok').addEventListener('click', function () {
      var url = document.getElementById('he-img-url').value.trim();
      var alt = document.getElementById('he-img-alt').value.trim();
      var w   = document.getElementById('he-img-width').value.trim();
      if (!url) { closeModal(); return; }
      restoreSelection();
      var img = '<img src="' + escAttr(url) + '"';
      if (alt) img += ' alt="' + escAttr(alt) + '"';
      if (w)   img += ' width="' + escAttr(w) + '"';
      img += ' style="max-width:100%;height:auto;">';
      exec('insertHTML', img);
      closeModal();
    });
    document.getElementById('he-img-cancel').addEventListener('click', closeModal);
  }

  /* ── Video embed action ──────────────────────── */
  function actionVideo() {
    saveSelection();
    openModal('Embed Video',
      '<div class="he-form-row"><label>Video URL (YouTube, Vimeo, or direct)</label><input type="url" id="he-vid-url" class="he-form-input" placeholder="https://www.youtube.com/watch?v=..."></div>' +
      '<div class="he-form-row"><label>Width</label><input type="text" id="he-vid-w" class="he-form-input" value="560" placeholder="560"></div>' +
      '<div class="he-form-row"><label>Height</label><input type="text" id="he-vid-h" class="he-form-input" value="315" placeholder="315"></div>',
      '<button class="he-modal-btn he-modal-btn-primary" id="he-vid-ok">Insert</button> <button class="he-modal-btn" id="he-vid-cancel">Cancel</button>'
    );
    document.getElementById('he-vid-ok').addEventListener('click', function () {
      var url = document.getElementById('he-vid-url').value.trim();
      var w = document.getElementById('he-vid-w').value.trim() || '560';
      var h = document.getElementById('he-vid-h').value.trim() || '315';
      if (!url) { closeModal(); return; }
      restoreSelection();
      var embed = videoEmbed(url, w, h);
      exec('insertHTML', embed);
      closeModal();
    });
    document.getElementById('he-vid-cancel').addEventListener('click', closeModal);
  }

  function videoEmbed(url, w, h) {
    var ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/);
    if (ytMatch) {
      return '<iframe width="' + w + '" height="' + h + '" src="https://www.youtube.com/embed/' + ytMatch[1] + '" frameborder="0" allowfullscreen style="max-width:100%;"></iframe>';
    }
    var vmMatch = url.match(/vimeo\.com\/(\d+)/);
    if (vmMatch) {
      return '<iframe width="' + w + '" height="' + h + '" src="https://player.vimeo.com/video/' + vmMatch[1] + '" frameborder="0" allowfullscreen style="max-width:100%;"></iframe>';
    }
    return '<video width="' + w + '" height="' + h + '" controls style="max-width:100%;"><source src="' + escAttr(url) + '"></video>';
  }

  /* ── Table action ────────────────────────────── */
  function actionTable() {
    saveSelection();
    openModal('Insert Table',
      '<div class="he-form-row"><label>Rows</label><input type="number" id="he-tbl-rows" class="he-form-input" value="3" min="1" max="20"></div>' +
      '<div class="he-form-row"><label>Columns</label><input type="number" id="he-tbl-cols" class="he-form-input" value="3" min="1" max="10"></div>' +
      '<div class="he-form-row"><label><input type="checkbox" id="he-tbl-header" checked> Header row</label></div>',
      '<button class="he-modal-btn he-modal-btn-primary" id="he-tbl-ok">Insert</button> <button class="he-modal-btn" id="he-tbl-cancel">Cancel</button>'
    );
    document.getElementById('he-tbl-ok').addEventListener('click', function () {
      var rows = parseInt(document.getElementById('he-tbl-rows').value) || 3;
      var cols = parseInt(document.getElementById('he-tbl-cols').value) || 3;
      var header = document.getElementById('he-tbl-header').checked;
      rows = Math.min(rows, 20);
      cols = Math.min(cols, 10);

      var html = '<table style="border-collapse:collapse;width:100%;">';
      for (var r = 0; r < rows; r++) {
        html += '<tr>';
        for (var c = 0; c < cols; c++) {
          var tag = (r === 0 && header) ? 'th' : 'td';
          html += '<' + tag + ' style="border:1px solid rgba(255,255,255,0.2);padding:8px;">';
          html += (r === 0 && header) ? 'Header ' + (c + 1) : '&nbsp;';
          html += '</' + tag + '>';
        }
        html += '</tr>';
      }
      html += '</table><p>&nbsp;</p>';

      restoreSelection();
      exec('insertHTML', html);
      closeModal();
    });
    document.getElementById('he-tbl-cancel').addEventListener('click', closeModal);
  }

  /* ── Special characters ──────────────────────── */
  function actionSpecialChar() {
    saveSelection();
    var chars = [
      '&amp;', '&copy;', '&reg;', '&trade;', '&deg;', '&plusmn;', '&times;', '&divide;',
      '&frac14;', '&frac12;', '&frac34;', '&laquo;', '&raquo;', '&lsquo;', '&rsquo;',
      '&ldquo;', '&rdquo;', '&ndash;', '&mdash;', '&bull;', '&hellip;', '&euro;', '&pound;',
      '&yen;', '&cent;', '&sect;', '&para;', '&dagger;', '&Dagger;', '&larr;', '&rarr;',
      '&uarr;', '&darr;', '&harr;', '&hearts;', '&diams;', '&clubs;', '&spades;',
      '&check;', '&cross;', '&star;', '&infin;', '&ne;', '&le;', '&ge;', '&mu;', '&pi;',
      '&Omega;', '&alpha;', '&beta;', '&gamma;', '&delta;', '&epsilon;', '&lambda;', '&sigma;'
    ];
    var grid = '<div class="he-char-grid">';
    chars.forEach(function (ch) {
      grid += '<button class="he-char-btn" data-char="' + ch + '" title="' + ch + '">' + ch + '</button>';
    });
    grid += '</div>';

    openModal('Special Characters', grid, '', { maxWidth: '440px' });

    $modalBody.addEventListener('click', function (e) {
      var btn = e.target.closest('.he-char-btn');
      if (!btn) return;
      restoreSelection();
      exec('insertHTML', btn.dataset.char);
      closeModal();
    });
  }

  /* ── Font Awesome icon picker ────────────────── */
  function actionFAIcon() {
    saveSelection();
    var icons = [
      'house','user','envelope','phone','gear','star','heart','check','xmark','plus','minus',
      'magnifying-glass','bell','calendar','clock','camera','image','video','music','file',
      'folder','download','upload','share','link','lock','unlock','eye','eye-slash',
      'trash','pen','pencil','scissors','copy','paste','bookmark','flag','tag','tags',
      'comment','comments','quote-left','quote-right','code','terminal','bug','database',
      'server','cloud','wifi','bolt','fire','sun','moon','snowflake','umbrella',
      'map','location-dot','globe','plane','car','truck','ship','train','bicycle',
      'circle-play','circle-pause','circle-stop','forward','backward','volume-high',
      'microphone','headphones','tv','desktop','laptop','mobile','tablet',
      'cart-shopping','bag-shopping','credit-card','money-bill','receipt','chart-bar',
      'chart-line','chart-pie','trophy','medal','award','crown','gift','cake-candles',
      'champagne-glasses','graduation-cap','book','newspaper','palette','paint-brush',
      'wand-magic-sparkles','puzzle-piece','lightbulb','robot','shield-halved','key',
      'fingerprint','id-card','users','people-group','handshake','thumbs-up','thumbs-down'
    ];
    var grid = '<input type="text" id="he-fa-search" class="he-form-input" placeholder="Search icons..." style="margin-bottom:10px;">';
    grid += '<div class="he-icon-grid" id="he-icon-grid">';
    icons.forEach(function (name) {
      grid += '<button class="he-icon-btn" data-icon="' + name + '" title="' + name + '"><i class="fa-solid fa-' + name + '"></i></button>';
    });
    grid += '</div>';

    openModal('Font Awesome Icons', grid, '', { maxWidth: '520px' });

    var searchInput = document.getElementById('he-fa-search');
    searchInput.addEventListener('input', function () {
      var q = this.value.toLowerCase();
      var btns = document.querySelectorAll('#he-icon-grid .he-icon-btn');
      btns.forEach(function (b) {
        b.style.display = b.dataset.icon.indexOf(q) >= 0 ? '' : 'none';
      });
    });

    $modalBody.addEventListener('click', function (e) {
      var btn = e.target.closest('.he-icon-btn');
      if (!btn) return;
      restoreSelection();
      exec('insertHTML', '<i class="fa-solid fa-' + btn.dataset.icon + '"></i>&nbsp;');
      closeModal();
    });
  }

  /* ── Find & Replace ──────────────────────────── */
  function actionFindReplace() {
    openModal('Find & Replace',
      '<div class="he-form-row"><label>Find</label><input type="text" id="he-find-text" class="he-form-input" placeholder="Search text..."></div>' +
      '<div class="he-form-row"><label>Replace</label><input type="text" id="he-replace-text" class="he-form-input" placeholder="Replace with..."></div>' +
      '<div class="he-form-row"><label><input type="checkbox" id="he-find-case"> Case sensitive</label></div>',
      '<button class="he-modal-btn" id="he-find-next">Find Next</button> ' +
      '<button class="he-modal-btn he-modal-btn-primary" id="he-replace-one">Replace</button> ' +
      '<button class="he-modal-btn" id="he-replace-all">Replace All</button> ' +
      '<button class="he-modal-btn" id="he-find-close">Close</button>'
    );

    document.getElementById('he-find-next').addEventListener('click', function () {
      var text = document.getElementById('he-find-text').value;
      if (!text) return;
      window.find(text, document.getElementById('he-find-case').checked);
    });

    document.getElementById('he-replace-one').addEventListener('click', function () {
      var findText = document.getElementById('he-find-text').value;
      var replText = document.getElementById('he-replace-text').value;
      if (!findText) return;
      var sel = window.getSelection();
      if (sel && sel.toString() === findText) {
        exec('insertText', replText);
      }
      window.find(findText, document.getElementById('he-find-case').checked);
    });

    document.getElementById('he-replace-all').addEventListener('click', function () {
      var findText = document.getElementById('he-find-text').value;
      var replText = document.getElementById('he-replace-text').value;
      if (!findText) return;
      var html = $content.innerHTML;
      var caseSensitive = document.getElementById('he-find-case').checked;
      var flags = caseSensitive ? 'g' : 'gi';
      var regex = new RegExp(escRegex(findText), flags);
      $content.innerHTML = html.replace(regex, replText);
      markDirty();
      toast('Replaced all occurrences');
    });

    document.getElementById('he-find-close').addEventListener('click', closeModal);
  }

  /* ── Media browser ───────────────────────────── */
  function actionMediaBrowser() {
    saveSelection();
    openModal('Media Browser',
      '<iframe id="he-media-iframe" src="/admin/shared/explorer/media-explorer.php?mode=picker" style="width:100%;height:500px;border:none;border-radius:6px;"></iframe>',
      '<button class="he-modal-btn" id="he-media-close">Close</button>',
      { maxWidth: '900px' }
    );
    document.getElementById('he-media-close').addEventListener('click', closeModal);

    function onMediaPick(e) {
      if (!e.data || e.data.type !== 'media-pick') return;
      window.removeEventListener('message', onMediaPick);
      restoreSelection();
      var path = e.data.path || '';
      if (/\.(jpg|jpeg|png|gif|webp)$/i.test(path)) {
        exec('insertHTML', '<img src="/' + escAttr(path) + '" alt="" style="max-width:100%;height:auto;">');
      } else if (/\.(mp4|webm|ogv)$/i.test(path)) {
        exec('insertHTML', '<video controls style="max-width:100%;"><source src="/' + escAttr(path) + '"></video>');
      } else if (/\.pdf$/i.test(path)) {
        exec('insertHTML', '<a href="/' + escAttr(path) + '" target="_blank">' + escHtml(e.data.name || path) + '</a>');
      }
      closeModal();
    }
    window.addEventListener('message', onMediaPick);
  }

  /* ── Browse CMS pages ──────────────────────────── */
  function actionBrowsePages() {
    if (typeof PageBrowser === 'undefined') { toast('Page browser not loaded', 'error'); return; }
    PageBrowser.open(function (slug, title, html, css, js) {
      setHTML(html);
      $docTitle.value = title;
      pageCss = css || '';
      pageJs = js || '';
      markDirty();
      toast('Loaded page: ' + title);
    });
  }

  /* ── Prettify / Minify ─────────────────────────── */
  function prettifyHTML(html) {
    var indent = 0;
    var result = '';
    // Normalize: remove existing whitespace between tags
    html = html.replace(/>\s+</g, '><').trim();
    // Split on tags
    var tokens = html.match(/(<[^>]+>|[^<]+)/g) || [];
    var BLOCK = /^<\/?(?:div|p|h[1-6]|ul|ol|li|table|thead|tbody|tfoot|tr|th|td|blockquote|section|article|header|footer|nav|main|aside|figure|figcaption|pre|hr|br|iframe|video|audio|source|form|fieldset|legend|details|summary)\b/i;
    var SELF_CLOSING = /^<(?:br|hr|img|input|meta|link|source)\b[^>]*\/?>$/i;
    var CLOSING = /^<\//;

    tokens.forEach(function (token) {
      if (SELF_CLOSING.test(token)) {
        result += pad(indent) + token + '\n';
      } else if (CLOSING.test(token) && BLOCK.test(token)) {
        indent = Math.max(0, indent - 1);
        result += pad(indent) + token + '\n';
      } else if (BLOCK.test(token) && !CLOSING.test(token)) {
        result += pad(indent) + token + '\n';
        indent++;
      } else if (token.charAt(0) === '<') {
        result += pad(indent) + token + '\n';
      } else {
        // Text node
        var text = token.trim();
        if (text) result += pad(indent) + text + '\n';
      }
    });
    return result.replace(/\n{3,}/g, '\n\n').trim();
  }

  function pad(n) {
    var s = '';
    for (var i = 0; i < n; i++) s += '  ';
    return s;
  }

  function minifyHTML(html) {
    // Remove comments
    html = html.replace(/<!--[\s\S]*?-->/g, '');
    // Collapse whitespace between tags
    html = html.replace(/>\s+</g, '><');
    // Collapse internal whitespace runs
    html = html.replace(/\s{2,}/g, ' ');
    return html.trim();
  }

  function actionPrettify() {
    var html = getHTML();
    if (!html) { toast('Nothing to prettify', 'error'); return; }
    var pretty = prettifyHTML(html);
    if (sourceMode) {
      $source.value = pretty;
    } else {
      // Switch to source mode to show prettified code
      $source.value = pretty;
      $content.style.display = 'none';
      $source.style.display = '';
      sourceMode = true;
      var btn = $toolbar.querySelector('[data-action="source-toggle"]');
      if (btn) btn.classList.add('he-tb-active');
    }
    markDirty();
    toast('Prettified');
  }

  function actionMinify() {
    var html = getHTML();
    if (!html) { toast('Nothing to minify', 'error'); return; }
    var minified = minifyHTML(html);
    if (sourceMode) {
      $source.value = minified;
    } else {
      $content.innerHTML = minified;
    }
    markDirty();
    toast('Minified');
  }

  /* ── Live Preview ───────────────────────────── */
  function buildPreviewSrcdoc() {
    var html = getHTML();
    var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    doc += '<link rel="stylesheet" href="/styles.css">';
    doc += '<link rel="stylesheet" href="/css/custom-fonts.css">';
    doc += '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
    if (pageCss) {
      doc += '<style id="page-css">' + pageCss + '</style>';
    }
    if (muteBg) {
      doc += '<style>video{display:none!important}</style>';
    }
    doc += '</head><body class="ri-site"><main class="content-wrapper"><div class="content-inner">';
    doc += html;
    doc += '</div></main>';
    if (pageJs) {
      doc += '<script id="page-js">' + pageJs + '<\/script>';
    }
    doc += '</body></html>';
    return doc;
  }

  function togglePreview() {
    var previewBtn = $toolbar.querySelector('[data-action="preview-toggle"]');
    var muteBtn = $toolbar.querySelector('[data-action="mute-bg"]');

    if (previewMode) {
      // Exit preview
      previewMode = false;
      $editorArea.classList.remove('preview-mode');
      if (previewBtn) previewBtn.classList.remove('active');
      if (muteBtn) muteBtn.style.display = 'none';
      $preview.srcdoc = '';
    } else {
      // Exit source mode first if active
      if (sourceMode) toggleSource();
      previewMode = true;
      $editorArea.classList.add('preview-mode');
      $preview.srcdoc = buildPreviewSrcdoc();
      if (previewBtn) previewBtn.classList.add('active');
      if (muteBtn) muteBtn.style.display = '';
    }
  }

  function toggleMuteBg() {
    muteBg = !muteBg;
    var muteBtn = $toolbar.querySelector('[data-action="mute-bg"]');
    if (muteBtn) muteBtn.classList.toggle('active', muteBg);
    if (previewMode) {
      $preview.srcdoc = buildPreviewSrcdoc();
    }
  }

  /* ── Source toggle ───────────────────────────── */
  function toggleSource() {
    // Exit preview mode first if active
    if (previewMode) {
      previewMode = false;
      $editorArea.classList.remove('preview-mode');
      var pvBtn = $toolbar.querySelector('[data-action="preview-toggle"]');
      var muBtn = $toolbar.querySelector('[data-action="mute-bg"]');
      if (pvBtn) pvBtn.classList.remove('active');
      if (muBtn) muBtn.style.display = 'none';
      $preview.srcdoc = '';
    }
    sourceMode = !sourceMode;
    var btn = $toolbar.querySelector('[data-action="source-toggle"]');

    if (sourceMode) {
      $source.value = $content.innerHTML;
      $content.style.display = 'none';
      $source.style.display = '';
      if (btn) btn.classList.add('he-tb-active');
    } else {
      $content.innerHTML = $source.value;
      $source.style.display = 'none';
      $content.style.display = '';
      if (btn) btn.classList.remove('he-tb-active');
      markDirty();
    }
    updateStatus();
  }

  /* ── Fullscreen toggle ───────────────────────── */
  function toggleFullscreen() {
    isFullscreen = !isFullscreen;
    var wrap = document.querySelector('.he-wrap');
    wrap.classList.toggle('he-fullscreen', isFullscreen);
    var btn = $toolbar.querySelector('[data-action="fullscreen"] i');
    if (btn) {
      btn.className = isFullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
    }
  }

  /* ── Status bar updates ──────────────────────── */
  function updateStatus() {
    var text = $content.innerText || $content.textContent || '';
    var words = text.trim() ? text.trim().split(/\s+/).length : 0;
    var chars = text.length;
    $wordCount.textContent = words + ' word' + (words !== 1 ? 's' : '');
    $charCount.textContent = chars + ' char' + (chars !== 1 ? 's' : '');
    updateElementPath();
  }

  function updateElementPath() {
    var sel = window.getSelection();
    if (!sel || !sel.anchorNode) { $elemPath.textContent = 'body'; return; }
    var node = sel.anchorNode;
    if (node.nodeType === 3) node = node.parentNode;
    var path = [];
    while (node && node !== $content && node !== document.body) {
      var tag = node.nodeName.toLowerCase();
      if (node.className) tag += '.' + node.className.split(/\s+/).slice(0, 2).join('.');
      path.unshift(tag);
      node = node.parentNode;
    }
    $elemPath.textContent = path.join(' > ') || 'body';
  }

  /* ── Content events ──────────────────────────── */
  $content.addEventListener('input', function () {
    markDirty();
    updateStatus();
  });

  $content.addEventListener('click', updateStatus);
  $content.addEventListener('keyup', updateStatus);

  // Keyboard shortcuts
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      saveDoc();
    }
    if (e.key === 'Escape' && $overlay.style.display !== 'none') {
      closeModal();
    }
    if (e.key === 'Escape' && isFullscreen) {
      toggleFullscreen();
    }
  });

  // Paste handler — clean up Word/external paste
  $content.addEventListener('paste', function (e) {
    var clipboardData = e.clipboardData || window.clipboardData;
    if (!clipboardData) return;

    var html = clipboardData.getData('text/html');
    if (html) {
      e.preventDefault();
      html = cleanPastedHTML(html);
      exec('insertHTML', html);
    }
  });

  function cleanPastedHTML(html) {
    html = html.replace(/<!--\[if[\s\S]*?endif\]-->/gi, '');
    html = html.replace(/<!\[if[\s\S]*?<!\[endif\]>/gi, '');
    html = html.replace(/<o:p[\s\S]*?<\/o:p>/gi, '');
    html = html.replace(/<\/?(meta|link|xml|st\d+:|o:|v:|w:)[^>]*>/gi, '');
    html = html.replace(/ (class|style|lang|dir|align)="[^"]*"/gi, function (match, attr) {
      if (attr === 'style') {
        var style = match.replace(/mso-[^;]+;?/gi, '').replace(/style="[\s;]*"/gi, '');
        return style;
      }
      return '';
    });
    html = html.replace(/<span[^>]*>\s*<\/span>/gi, '');
    html = html.replace(/<p[^>]*><\/p>/gi, '');
    return html;
  }

  /* ── Document CRUD ───────────────────────────── */
  function getHTML() {
    return sourceMode ? $source.value : $content.innerHTML;
  }

  function setHTML(html) {
    $content.innerHTML = html || '';
    if (sourceMode) $source.value = html || '';
    updateStatus();
  }

  function loadDocList(selectSlug) {
    fetch(API + '?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        $docSelect.innerHTML = '<option value="">— New Document —</option>';
        data.docs.forEach(function (d) {
          var opt = document.createElement('option');
          opt.value = d.slug;
          opt.textContent = d.title;
          $docSelect.appendChild(opt);
        });
        if (selectSlug) $docSelect.value = selectSlug;
      });
  }

  function loadDoc(slug) {
    if (!slug) {
      currentSlug = '';
      $docTitle.value = '';
      setHTML('');
      clearDirty();
      return;
    }
    fetch(API + '?action=load&slug=' + encodeURIComponent(slug))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) { toast(data.message || 'Load failed', 'error'); return; }
        currentSlug = data.doc.slug;
        $docTitle.value = data.doc.title || '';
        setHTML(data.doc.html || '');
        clearDirty();
      });
  }

  $docSelect.addEventListener('change', function () {
    if (dirty && !confirm('You have unsaved changes. Discard?')) {
      $docSelect.value = currentSlug || '';
      return;
    }
    loadDoc(this.value);
  });

  function saveDoc() {
    var title = $docTitle.value.trim();
    if (!title) { toast('Enter a document title', 'error'); $docTitle.focus(); return; }
    var body = new FormData();
    body.append('action', 'save');
    body.append('title', title);
    body.append('html', getHTML());

    fetch(API, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          currentSlug = data.slug;
          clearDirty();
          loadDocList(data.slug);
          toast('Saved');
        } else {
          toast(data.message || 'Save failed', 'error');
        }
      });
  }

  $save.addEventListener('click', saveDoc);
  $docTitle.addEventListener('input', markDirty);

  // Export
  $export.addEventListener('click', function () {
    var html = getHTML();
    if (!html) { toast('Nothing to export', 'error'); return; }
    var full = '<!DOCTYPE html>\n<html lang="en">\n<head><meta charset="UTF-8"><title>'
             + escHtml($docTitle.value.trim() || 'Document')
             + '</title></head>\n<body>\n' + html + '\n</body>\n</html>';
    var blob = new Blob([full], { type: 'text/html' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = ($docTitle.value.trim() || 'document') + '.html';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // Close / Exit without saving
  var $close = document.getElementById('he-close');
  if ($close) {
    $close.addEventListener('click', function () {
      if (dirty && !confirm('Document will not be saved. Close anyway?')) return;
      window.history.length > 1 ? window.history.back() : (window.location.href = '/admin/');
    });
  }

  // Delete
  $del.addEventListener('click', function () {
    if (!currentSlug) { toast('No document selected', 'error'); return; }
    if (!confirm('Delete "' + ($docTitle.value || currentSlug) + '"?')) return;
    var body = new FormData();
    body.append('action', 'delete');
    body.append('slug', currentSlug);

    fetch(API, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          currentSlug = '';
          $docTitle.value = '';
          setHTML('');
          clearDirty();
          loadDocList();
          toast('Deleted');
        } else {
          toast(data.message || 'Delete failed', 'error');
        }
      });
  });

  /* ── Utilities ───────────────────────────────── */
  function escAttr(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function escHtml(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function escRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  /* ── Auto-save (localStorage draft) ──────────── */
  var AUTOSAVE_KEY = 'he_autosave_draft';
  var AUTOSAVE_INTERVAL = 15000;

  function autosaveDraft() {
    if (!dirty) return;
    var draft = {
      title: $docTitle.value,
      html: getHTML(),
      slug: currentSlug,
      ts: Date.now()
    };
    try { localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(draft)); } catch (e) {}
  }

  function loadAutosaveDraft() {
    try {
      var raw = localStorage.getItem(AUTOSAVE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) { return null; }
  }

  function clearAutosaveDraft() {
    try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
  }

  setInterval(autosaveDraft, AUTOSAVE_INTERVAL);

  var _origClearDirty = clearDirty;
  clearDirty = function () {
    _origClearDirty();
    clearAutosaveDraft();
  };

  /* ── Unsaved changes warning ───────────────── */
  window.addEventListener('beforeunload', function (e) {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  /* ── Custom paragraph/inline class styles ───── */
  var formatSelect = $toolbar.querySelector('[data-cmd="formatBlock"]');
  if (formatSelect) {
    var customFormats = [
      { value: 'div', label: 'DIV Container' }
    ];
    customFormats.forEach(function (f) {
      var opt = document.createElement('option');
      opt.value = f.value;
      opt.textContent = f.label;
      formatSelect.appendChild(opt);
    });
  }

  /* ── Boot ────────────────────────────────────── */
  var draft = loadAutosaveDraft();
  if (draft && draft.html && draft.ts > Date.now() - 86400000) {
    $content.innerHTML = draft.html;
    $docTitle.value = draft.title || '';
    currentSlug = draft.slug || '';
    markDirty();
    toast('Restored autosaved draft');
  } else {
    $content.innerHTML = '<p>Start typing here...</p>';
  }

  updateStatus();
  loadDocList(currentSlug || undefined);

  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}

  // ── Load CMS page via ?page=slug URL param ────
  var urlParams = new URLSearchParams(window.location.search);
  var pageSlug = urlParams.get('page');
  if (pageSlug && typeof PageBrowser !== 'undefined') {
    PageBrowser.fetchPage(pageSlug, function (slug, title, html, css, js) {
      setHTML(html);
      $docTitle.value = title;
      pageCss = css || '';
      pageJs = js || '';
      markDirty();
      toast('Loaded page: ' + title);
    });
  }

})();
