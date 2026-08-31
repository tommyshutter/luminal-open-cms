/**
 * Luminal CMS — MD Editor Standalone Controller
 *
 * Document bar logic: save/load/export/delete, dirty state, Ctrl+S.
 * Wraps the shared MarkdownEditor component.
 *
 * @module  MDEditor
 * @version 1.0.0
 */
(function () {
  'use strict';

  var API = '/admin/modules/MDEditor/api.php';
  var editor, currentSlug = '', dirty = false;

  var $select  = document.getElementById('mds-doc-select');
  var $title   = document.getElementById('mds-doc-title');
  var $dirty   = document.getElementById('mds-dirty');
  var $save    = document.getElementById('mds-save');
  var $expMd   = document.getElementById('mds-export-md');
  var $expHtml = document.getElementById('mds-export-html');
  var $delete  = document.getElementById('mds-delete');
  var $toast   = document.getElementById('mds-toast');

  function toast(msg, type) {
    $toast.textContent = msg;
    $toast.className = 'mds-toast mds-toast-show' + (type === 'error' ? ' mds-toast-error' : '');
    setTimeout(function () { $toast.className = 'mds-toast'; }, 3000);
  }

  function markDirty() {
    if (!dirty) { dirty = true; $dirty.style.display = ''; }
  }

  function clearDirty() {
    dirty = false; $dirty.style.display = 'none';
  }

  // ── Init editor ──────────────────────────────
  editor = new MarkdownEditor(document.getElementById('mds-editor-container'), {
    value: '',
    preview: true,
    toolbar: true,
    height: '100%',
    darkMode: true,
    placeholder: 'Start writing markdown...',
    onChange: function () { markDirty(); }
  });

  // ── List documents ───────────────────────────
  function loadDocList(selectSlug) {
    fetch(API + '?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        $select.innerHTML = '<option value="">— New Document —</option>';
        data.docs.forEach(function (d) {
          var opt = document.createElement('option');
          opt.value = d.slug;
          opt.textContent = d.title;
          $select.appendChild(opt);
        });
        if (selectSlug) $select.value = selectSlug;
      });
  }

  // ── Load document ────────────────────────────
  function loadDoc(slug) {
    if (!slug) {
      currentSlug = '';
      $title.value = '';
      editor.setValue('');
      clearDirty();
      return;
    }
    fetch(API + '?action=load&slug=' + encodeURIComponent(slug))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) { toast(data.message || 'Load failed', 'error'); return; }
        currentSlug = data.doc.slug;
        $title.value = data.doc.title || '';
        editor.setValue(data.doc.markdown || '');
        clearDirty();
      });
  }

  $select.addEventListener('change', function () {
    if (dirty && !confirm('You have unsaved changes. Discard?')) {
      $select.value = currentSlug || '';
      return;
    }
    loadDoc(this.value);
  });

  // ── Save ─────────────────────────────────────
  function saveDoc() {
    var title = $title.value.trim();
    if (!title) { toast('Enter a document title', 'error'); $title.focus(); return; }
    var body = new FormData();
    body.append('action', 'save');
    body.append('title', title);
    body.append('markdown', editor.getValue());

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

  // ── Browse CMS pages ──────────────────────────
  var $browse = document.getElementById('mds-browse');
  if ($browse && typeof PageBrowser !== 'undefined') {
    $browse.addEventListener('click', function () {
      PageBrowser.open(function (slug, title, html) {
        editor.setValue(htmlToMarkdown(html));
        $title.value = title;
        markDirty();
        toast('Loaded page: ' + title);
      });
    });
  }

  function htmlToMarkdown(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return nodeToMd(tmp).replace(/\n{3,}/g, '\n\n').trim();
  }

  function nodeToMd(node) {
    var out = '';
    for (var i = 0; i < node.childNodes.length; i++) {
      var n = node.childNodes[i];
      if (n.nodeType === 3) { out += n.textContent; continue; }
      if (n.nodeType !== 1) continue;
      var tag = n.nodeName.toLowerCase();
      var inner = nodeToMd(n);
      switch (tag) {
        case 'h1': out += '\n\n# ' + inner.trim() + '\n\n'; break;
        case 'h2': out += '\n\n## ' + inner.trim() + '\n\n'; break;
        case 'h3': out += '\n\n### ' + inner.trim() + '\n\n'; break;
        case 'h4': out += '\n\n#### ' + inner.trim() + '\n\n'; break;
        case 'h5': out += '\n\n##### ' + inner.trim() + '\n\n'; break;
        case 'h6': out += '\n\n###### ' + inner.trim() + '\n\n'; break;
        case 'p': case 'div': out += '\n\n' + inner.trim() + '\n\n'; break;
        case 'br': out += '\n'; break;
        case 'strong': case 'b': out += '**' + inner + '**'; break;
        case 'em': case 'i':
          if (n.classList && n.classList.contains('fa-solid')) { out += ''; break; }
          out += '*' + inner + '*'; break;
        case 'a':
          var href = n.getAttribute('href') || '';
          out += '[' + inner + '](' + href + ')'; break;
        case 'img':
          var alt = n.getAttribute('alt') || '';
          var src = n.getAttribute('src') || '';
          out += '![' + alt + '](' + src + ')'; break;
        case 'ul': case 'ol': out += '\n\n' + inner + '\n\n'; break;
        case 'li':
          var parent = n.parentNode ? n.parentNode.nodeName.toLowerCase() : 'ul';
          if (parent === 'ol') {
            var idx = Array.prototype.indexOf.call(n.parentNode.children, n) + 1;
            out += idx + '. ' + inner.trim() + '\n';
          } else {
            out += '- ' + inner.trim() + '\n';
          }
          break;
        case 'blockquote': out += '\n\n> ' + inner.trim().replace(/\n/g, '\n> ') + '\n\n'; break;
        case 'pre': case 'code':
          if (tag === 'pre') { out += '\n\n```\n' + (n.textContent || '') + '\n```\n\n'; }
          else { out += '`' + inner + '`'; }
          break;
        case 'hr': out += '\n\n---\n\n'; break;
        default: out += inner; break;
      }
    }
    return out;
  }

  // ── Ctrl+S ───────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      saveDoc();
    }
  });

  // ── Export .md ───────────────────────────────
  $expMd.addEventListener('click', function () {
    var md = editor.getValue();
    if (!md) { toast('Nothing to export', 'error'); return; }
    download(($title.value.trim() || 'document') + '.md', md, 'text/markdown');
  });

  // ── Export .html ─────────────────────────────
  $expHtml.addEventListener('click', function () {
    var html = editor.getHTML();
    if (!html) { toast('Nothing to export', 'error'); return; }
    var full = '<!DOCTYPE html>\n<html lang="en">\n<head><meta charset="UTF-8"><title>'
             + escHtml($title.value.trim() || 'Document')
             + '</title></head>\n<body>\n' + html + '\n</body>\n</html>';
    download(($title.value.trim() || 'document') + '.html', full, 'text/html');
  });

  function download(name, content, mime) {
    var blob = new Blob([content], { type: mime });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Close / Exit without saving ────────────
  var $close = document.getElementById('mds-close');
  if ($close) {
    $close.addEventListener('click', function () {
      if (dirty && !confirm('Document will not be saved. Close anyway?')) return;
      window.history.length > 1 ? window.history.back() : (window.location.href = '/admin/');
    });
  }

  // ── Delete ───────────────────────────────────
  $delete.addEventListener('click', function () {
    if (!currentSlug) { toast('No document selected', 'error'); return; }
    if (!confirm('Delete "' + ($title.value || currentSlug) + '"?')) return;
    var body = new FormData();
    body.append('action', 'delete');
    body.append('slug', currentSlug);

    fetch(API, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          currentSlug = '';
          $title.value = '';
          editor.setValue('');
          clearDirty();
          loadDocList();
          toast('Deleted');
        } else {
          toast(data.message || 'Delete failed', 'error');
        }
      });
  });

  // ── Title change = dirty ─────────────────────
  $title.addEventListener('input', markDirty);

  // ── Auto-save (localStorage draft) ──────────
  var AUTOSAVE_KEY = 'mds_autosave_draft';
  var AUTOSAVE_INTERVAL = 15000;

  function autosaveDraft() {
    if (!dirty) return;
    try {
      localStorage.setItem(AUTOSAVE_KEY, JSON.stringify({
        title: $title.value,
        markdown: editor.getValue(),
        slug: currentSlug,
        ts: Date.now()
      }));
    } catch (e) {}
  }

  function clearAutosaveDraft() {
    try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
  }

  setInterval(autosaveDraft, AUTOSAVE_INTERVAL);

  // Extend clearDirty to also clear autosave
  var _origClearDirty = clearDirty;
  clearDirty = function () {
    _origClearDirty();
    clearAutosaveDraft();
  };

  // ── Unsaved changes warning ─────────────────
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // ── Boot ─────────────────────────────────────
  // Check for autosaved draft
  try {
    var draft = JSON.parse(localStorage.getItem(AUTOSAVE_KEY) || 'null');
    if (draft && draft.markdown && draft.ts > Date.now() - 86400000) {
      editor.setValue(draft.markdown);
      $title.value = draft.title || '';
      currentSlug = draft.slug || '';
      markDirty();
      toast('Restored autosaved draft');
    }
  } catch (e) {}

  loadDocList(currentSlug || undefined);

  // ── Load CMS page via ?page=slug URL param ────
  var urlParams = new URLSearchParams(window.location.search);
  var pageSlug = urlParams.get('page');
  if (pageSlug && typeof PageBrowser !== 'undefined') {
    PageBrowser.fetchPage(pageSlug, function (slug, title, html) {
      editor.setValue(htmlToMarkdown(html));
      $title.value = title;
      markDirty();
      toast('Loaded page: ' + title);
    });
  }
})();
