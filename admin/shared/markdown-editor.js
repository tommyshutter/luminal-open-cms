/**
 * Luminal CMS — Universal Markdown Editor Component
 *
 * Reusable split-pane markdown editor with live preview, toolbar, and keyboard shortcuts.
 * Lazy-loads marked.js from CDN on first instantiation.
 *
 * @package    LuminalCMS
 * @file       /admin/shared/markdown-editor.js
 * @version    1.0.0
 *
 * Usage:
 *   const editor = new MarkdownEditor(containerEl, {
 *     value: '# Hello',
 *     onChange: (md) => console.log(md),
 *     preview: true,
 *     toolbar: true,
 *     height: '400px',
 *     readOnly: false,
 *     darkMode: true,
 *     placeholder: 'Write markdown...',
 *   });
 */
(function () {
  'use strict';

  const MARKED_CDN = 'https://cdn.jsdelivr.net/npm/marked@12/marked.min.js';
  let markedReady = null; // shared promise

  function loadMarked() {
    if (window.marked) return Promise.resolve();
    if (markedReady) return markedReady;
    markedReady = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = MARKED_CDN;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Failed to load marked.js')); };
      document.head.appendChild(s);
    });
    return markedReady;
  }

  function escAttr(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function wordCount(text) {
    var t = (text || '').trim();
    if (!t) return 0;
    return t.split(/\s+/).length;
  }

  /* ── Toolbar definitions ─────────────────────────────────── */
  var TOOLBAR_ITEMS = [
    { id: 'bold',   label: 'B',   title: 'Bold (Ctrl+B)',         style: 'font-weight:bold' },
    { id: 'italic', label: 'I',   title: 'Italic (Ctrl+I)',       style: 'font-style:italic' },
    { id: 'h1',     label: 'H1',  title: 'Heading 1' },
    { id: 'h2',     label: 'H2',  title: 'Heading 2' },
    { id: 'h3',     label: 'H3',  title: 'Heading 3' },
    { id: 'link',   label: '&#128279;', title: 'Link (Ctrl+K)' },
    { id: 'image',  label: '&#128247;', title: 'Image' },
    { id: 'code',   label: '&lt;&gt;',  title: 'Code' },
    { id: 'list',   label: '&#8226;',   title: 'Bullet list' },
    { id: 'quote',  label: '&#10077;',  title: 'Block quote' },
  ];

  /* ── Constructor ─────────────────────────────────────────── */
  function MarkdownEditor(container, opts) {
    if (!(this instanceof MarkdownEditor)) return new MarkdownEditor(container, opts);
    opts = opts || {};

    this._container = container;
    this._onChange = opts.onChange || null;
    this._showPreview = opts.preview !== false;
    this._showToolbar = opts.toolbar !== false;
    this._readOnly = !!opts.readOnly;
    this._darkMode = opts.darkMode !== false;
    this._height = opts.height || '400px';
    this._placeholder = opts.placeholder || 'Write markdown...';
    this._destroyed = false;

    this._build();
    this.setValue(opts.value || '');

    var self = this;
    loadMarked().then(function () {
      if (!self._destroyed) self._renderPreview();
    });
  }

  /* ── Build DOM ───────────────────────────────────────────── */
  MarkdownEditor.prototype._build = function () {
    var wrap = document.createElement('div');
    wrap.className = 'mde-wrap' + (this._darkMode ? ' mde-dark' : ' mde-light');
    wrap.style.height = this._height;

    // Toolbar
    if (this._showToolbar) {
      var toolbar = document.createElement('div');
      toolbar.className = 'mde-toolbar';
      var self = this;
      TOOLBAR_ITEMS.forEach(function (item) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mde-tb-btn';
        btn.innerHTML = item.label;
        btn.title = item.title;
        if (item.style) btn.style.cssText = item.style;
        btn.dataset.action = item.id;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          self._toolbarAction(item.id);
        });
        toolbar.appendChild(btn);
      });

      // Preview toggle
      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'mde-tb-btn mde-tb-toggle';
      toggleBtn.innerHTML = '&#9881;';
      toggleBtn.title = 'Toggle preview';
      toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        self.togglePreview();
      });
      toolbar.appendChild(toggleBtn);

      wrap.appendChild(toolbar);
      this._toolbar = toolbar;
    }

    // Body (editor + preview)
    var body = document.createElement('div');
    body.className = 'mde-body';

    var editorPane = document.createElement('div');
    editorPane.className = 'mde-editor-pane';

    var textarea = document.createElement('textarea');
    textarea.className = 'mde-textarea';
    textarea.placeholder = this._placeholder;
    textarea.readOnly = this._readOnly;
    textarea.spellcheck = true;

    var self = this;
    textarea.addEventListener('input', function () {
      self._onInput();
    });
    textarea.addEventListener('keydown', function (e) {
      self._onKeydown(e);
    });
    // Tab key support
    textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        textarea.value = textarea.value.substring(0, start) + '  ' + textarea.value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + 2;
        self._onInput();
      }
    });

    editorPane.appendChild(textarea);
    body.appendChild(editorPane);

    this._textarea = textarea;
    this._editorPane = editorPane;

    // Preview pane
    var previewPane = document.createElement('div');
    previewPane.className = 'mde-preview-pane';
    if (!this._showPreview) previewPane.style.display = 'none';

    var previewContent = document.createElement('div');
    previewContent.className = 'mde-preview-content';
    previewPane.appendChild(previewContent);
    body.appendChild(previewPane);

    this._previewPane = previewPane;
    this._previewContent = previewContent;

    wrap.appendChild(body);
    this._body = body;

    // Status bar
    var status = document.createElement('div');
    status.className = 'mde-status';
    status.innerHTML = 'Markdown &middot; 0 words';
    wrap.appendChild(status);
    this._status = status;

    this._wrap = wrap;
    this._container.innerHTML = '';
    this._container.appendChild(wrap);
  };

  /* ── Input handling ──────────────────────────────────────── */
  MarkdownEditor.prototype._onInput = function () {
    var md = this._textarea.value;
    this._updateStatus(md);
    this._renderPreview();
    if (this._onChange) this._onChange(md);
  };

  MarkdownEditor.prototype._updateStatus = function (md) {
    var wc = wordCount(md);
    this._status.innerHTML = 'Markdown &middot; ' + wc + ' word' + (wc !== 1 ? 's' : '');
  };

  MarkdownEditor.prototype._renderPreview = function () {
    if (!window.marked || !this._previewContent) return;
    try {
      this._previewContent.innerHTML = window.marked.parse(this._textarea.value || '');
    } catch (e) {
      this._previewContent.innerHTML = '<p style="color:#f87171;">Preview error: ' + escAttr(e.message) + '</p>';
    }
  };

  /* ── Keyboard shortcuts ──────────────────────────────────── */
  MarkdownEditor.prototype._onKeydown = function (e) {
    if (this._readOnly) return;
    var ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && e.key === 'b') { e.preventDefault(); this._toolbarAction('bold'); }
    if (ctrl && e.key === 'i') { e.preventDefault(); this._toolbarAction('italic'); }
    if (ctrl && e.key === 'k') { e.preventDefault(); this._toolbarAction('link'); }
  };

  /* ── Toolbar actions ─────────────────────────────────────── */
  MarkdownEditor.prototype._toolbarAction = function (action) {
    if (this._readOnly) return;
    var ta = this._textarea;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value;
    var sel = text.substring(start, end);
    var before = text.substring(0, start);
    var after = text.substring(end);
    var insert, cursorPos;

    switch (action) {
      case 'bold':
        insert = '**' + (sel || 'bold text') + '**';
        cursorPos = sel ? start + insert.length : start + 2;
        break;
      case 'italic':
        insert = '*' + (sel || 'italic text') + '*';
        cursorPos = sel ? start + insert.length : start + 1;
        break;
      case 'h1':
        insert = this._prependLine(before, sel, '# ');
        cursorPos = start + insert.length;
        break;
      case 'h2':
        insert = this._prependLine(before, sel, '## ');
        cursorPos = start + insert.length;
        break;
      case 'h3':
        insert = this._prependLine(before, sel, '### ');
        cursorPos = start + insert.length;
        break;
      case 'link':
        if (sel) {
          insert = '[' + sel + '](url)';
          cursorPos = start + sel.length + 3; // position on "url"
        } else {
          insert = '[link text](url)';
          cursorPos = start + 1; // position on "link text"
        }
        break;
      case 'image':
        if (sel) {
          insert = '![' + sel + '](image-url)';
          cursorPos = start + sel.length + 4;
        } else {
          insert = '![alt text](image-url)';
          cursorPos = start + 2;
        }
        break;
      case 'code':
        if (sel && sel.indexOf('\n') !== -1) {
          insert = '```\n' + sel + '\n```';
          cursorPos = start + insert.length;
        } else {
          insert = '`' + (sel || 'code') + '`';
          cursorPos = sel ? start + insert.length : start + 1;
        }
        break;
      case 'list':
        insert = this._prependLine(before, sel, '- ');
        cursorPos = start + insert.length;
        break;
      case 'quote':
        insert = this._prependLine(before, sel, '> ');
        cursorPos = start + insert.length;
        break;
      default:
        return;
    }

    ta.value = before + insert + after;
    ta.selectionStart = ta.selectionEnd = cursorPos;
    ta.focus();
    this._onInput();
  };

  MarkdownEditor.prototype._prependLine = function (before, sel, prefix) {
    // If we're in the middle of a line, add newline first
    var needsNewline = before.length > 0 && before[before.length - 1] !== '\n';
    var result = (needsNewline ? '\n' : '') + prefix + (sel || '');
    return result;
  };

  /* ── Public API ──────────────────────────────────────────── */
  MarkdownEditor.prototype.getValue = function () {
    return this._textarea ? this._textarea.value : '';
  };

  MarkdownEditor.prototype.setValue = function (md) {
    if (!this._textarea) return;
    this._textarea.value = md || '';
    this._updateStatus(md);
    this._renderPreview();
  };

  MarkdownEditor.prototype.getHTML = function () {
    if (!window.marked) return '';
    try {
      return window.marked.parse(this._textarea.value || '');
    } catch (e) {
      return '';
    }
  };

  MarkdownEditor.prototype.togglePreview = function () {
    this._showPreview = !this._showPreview;
    if (this._previewPane) {
      this._previewPane.style.display = this._showPreview ? '' : 'none';
    }
    if (this._showPreview) this._renderPreview();
  };

  MarkdownEditor.prototype.setReadOnly = function (flag) {
    this._readOnly = !!flag;
    if (this._textarea) this._textarea.readOnly = this._readOnly;
    if (this._toolbar) this._toolbar.style.pointerEvents = flag ? 'none' : '';
    if (this._toolbar) this._toolbar.style.opacity = flag ? '0.5' : '';
  };

  MarkdownEditor.prototype.focus = function () {
    if (this._textarea) this._textarea.focus();
  };

  MarkdownEditor.prototype.destroy = function () {
    this._destroyed = true;
    if (this._wrap && this._wrap.parentNode) {
      this._wrap.parentNode.removeChild(this._wrap);
    }
    this._textarea = null;
    this._previewContent = null;
    this._previewPane = null;
    this._toolbar = null;
    this._status = null;
    this._wrap = null;
  };

  /* ── Export ──────────────────────────────────────────────── */
  window.MarkdownEditor = MarkdownEditor;
})();
