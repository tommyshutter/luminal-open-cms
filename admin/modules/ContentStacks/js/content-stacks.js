/**
 * Luminal CMS — Content Stacks Module JS
 *
 * @package    LuminalCMS
 * @module     ContentStacks
 * @file       /admin/modules/ContentStacks/js/content-stacks.js
 */
$(function () {
  const API = '/admin/modules/ContentStacks/api.php';
  const RESOLVER = '/admin/modules/ContentStacks/api/resolve_url.php';
  let stacks = [];            // [{id, label}, ...]
  let stackData = {};         // stackId -> {column_count, content:[]}
  let currentStackId = null;  // stack open in modal

  /* ===== Embed mode: Page Manager loads this page in an iframe (?edit=<slug>&embed=1),
     auto-opens that stack's builder, and expects a postMessage on save/close. ===== */
  const CS_PARAMS = new URLSearchParams(location.search);
  const CS_EMBED  = CS_PARAMS.has('embed');
  function csPostParent(type, extra){
    if (!CS_EMBED) return;
    try { parent.postMessage(Object.assign({ source: 'contentstacks', type: type }, extra || {}), '*'); } catch (_) {}
  }
  let _csAutoEditDone = false;
  function csResolveEditId(param){
    if (!param) return null;
    const slugify = x => String(x || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    for (const s of stacks){
      const num  = String(s.id || '').replace('stack', '');
      const slug = s.slug || slugify(s.label) || ('stack-' + num);
      if (param === s.id || param === slug || param === ('stack-' + num)) return s.id;
    }
    return null;
  }
  function csAutoEditOnce(){
    if (_csAutoEditDone) return;
    const p = CS_PARAMS.get('edit');
    if (!p){ _csAutoEditDone = true; return; }
    const id = csResolveEditId(p);
    if (id){ _csAutoEditDone = true; openModal(id); }
  }

  /* ========== TOAST ========== */
  function toast(msg, isError) {
    const el = $('#cs-toast');
    el.text(msg).removeClass('success error show').addClass(isError ? 'error' : 'success').addClass('show');
    setTimeout(() => el.removeClass('show'), 3000);
  }

  /* ========== FILE TYPE DETECTION ========== */
  function fileType(path) {
    const ext = path.split('.').pop().toLowerCase();
    if (['jpg','jpeg','png','gif','webp','svg'].includes(ext)) return 'image';
    if (['mp4','webm','mov'].includes(ext)) return 'video';
    if (['mp3','ogg','wav'].includes(ext)) return 'audio';
    if (ext === 'pdf') return 'pdf';
    return 'file';
  }

  /* ================================================================
     CARD GRID
     ================================================================ */

  function renderCardGrid() {
    const $grid = $('#cs-card-grid').empty();
    stacks.forEach(s => {
      const data = stackData[s.id];
      const items = data ? (data.content || []) : [];
      const num = s.id.replace('stack', '');

      // Build thumbnail previews (up to 6)
      let previewHTML = '';
      if (items.length === 0) {
        previewHTML = '<div class="cs-card-empty">Empty — click to add content</div>';
      } else {
        const show = items.slice(0, 6);
        show.forEach(it => {
          if (it.type === 'facebook') {
            previewHTML += '<div class="cs-card-thumb-icon"><i class="fab fa-facebook" style="color:#1877f2"></i></div>';
          } else if (it.type === 'youtube') {
            if (it.thumbnail) {
              previewHTML += `<img class="cs-card-thumb" src="${encodeURI(it.thumbnail)}" alt="" onerror="this.style.display='none'">`;
            } else {
              previewHTML += '<div class="cs-card-thumb-icon"><i class="fab fa-youtube" style="color:#ff0000"></i></div>';
            }
          } else if (it.type === 'pdf') {
            previewHTML += '<div class="cs-card-thumb-icon"><i class="fas fa-file-pdf" style="color:#e05252"></i></div>';
          } else {
            const thumb = it.thumbnail || it.path || '';
            if (thumb) {
              previewHTML += `<img class="cs-card-thumb" src="${encodeURI(thumb)}" alt="" onerror="this.style.display='none'">`;
            }
          }
        });
        if (items.length > 6) {
          previewHTML += `<div class="cs-card-thumb-icon">+${items.length - 6}</div>`;
        }
      }

      const cols = data ? data.column_count || 1 : 1;
      const slug = s.slug || (s.label || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || ('stack-' + num);
      const shortcode = `[[stack:${slug}]]`;
      const slugBadge = $('<span>').text(slug).html();
      const $card = $(`
        <div class="cs-card" data-stack="${s.id}">
          <button type="button" class="cs-card-delete" title="Delete stack"><i class="fas fa-trash"></i></button>
          <div class="cs-card-title">${$('<span>').text(s.label).html()}</div>
          <div class="cs-card-meta">${slugBadge} · ${items.length} item${items.length !== 1 ? 's' : ''} · ${cols} col</div>
          <div class="cs-card-preview">${previewHTML}</div>
          <div class="cs-card-shortcode" title="Click to copy"><code>${shortcode}</code></div>
        </div>
      `);
      $card.find('.cs-card-shortcode').on('click', function(e) {
        e.stopPropagation();
        navigator.clipboard.writeText(shortcode).then(() => toast('Shortcode copied!'));
      });
      $card.find('.cs-card-delete').on('click', function(e) {
        e.stopPropagation();
        deleteStack(s.id, s.label);
      });
      $card.on('click', () => openModal(s.id));
      $grid.append($card);
    });
    csAutoEditOnce();   // embed: auto-open the ?edit=<slug> stack once stacks are loaded
  }

  /* ========== CARD DELETE (from main grid) ========== */
  function deleteStack(stackId, label) {
    if (!confirm('Delete "' + (label || stackId) + '"? This cannot be undone.')) return;
    $.post(API, { action: 'delete_stack', stack: stackId }, function (r) {
      if (r.success) {
        stacks = stacks.filter(x => x.id !== stackId);
        delete stackData[stackId];
        renderCardGrid();
        toast('Stack deleted');
      } else {
        toast(r.message || 'Delete failed', true);
      }
    }, 'json').fail(() => toast('Delete failed', true));
  }

  /* ================================================================
     MODAL
     ================================================================ */

  const $overlay = $('#cs-modal-overlay');
  const $playlist = $('#cs-modal-playlist');
  const $columns = $('#cs-modal-columns');

  function openModal(stackId) {
    currentStackId = stackId;
    const s = stacks.find(x => x.id === stackId);
    if (!s) return;
    const num = stackId.replace('stack', '');

    $('#cs-modal-title').text(s.label);
    const slug = s.slug || s.label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const sc = '[[stack:' + slug + ']]';
    $('#cs-modal-shortcode').html('<code class="cs-shortcode-copy" title="Click to copy">' + sc + '</code>');
    $('#cs-modal-shortcode .cs-shortcode-copy').off('click').on('click', function() {
      navigator.clipboard.writeText(sc).then(() => toast('Shortcode copied!'));
    });

    // Hide the card grid / page header, show modal inline
    $('.cs-wrap').hide();
    $('.panel_header_h1').hide();
    $overlay.addClass('open');

    // Set CSS var for remaining viewport height
    const offsetTop = $overlay.offset().top - $(window).scrollTop();
    $overlay[0].style.setProperty('--cs-header-offset', offsetTop + 'px');
    window.scrollTo(0, 0);

    loadStackIntoModal(stackId);
  }

  function closeModal() {
    $overlay.removeClass('open');
    $('.cs-wrap').show();
    $('.panel_header_h1').show();
    currentStackId = null;
  }

  function loadStackIntoModal(stackId) {
    $playlist.html('<p class="cs-empty">Loading...</p>');
    $.post(API, { action: 'get_content', stack: stackId }, function (r) {
      $playlist.empty();
      if (r.success && r.data) {
        $columns.val(r.data.column_count || 1);
        $('#cs-stack-css').val(r.data.css || '');
        $('#cs-stack-js').val(r.data.js || '');
        stackData[stackId] = r.data;
        const content = r.data.content || [];
        if (content.length) {
          content.forEach(item => $playlist.append(createItemHTML(item)));
        } else {
          $playlist.html('<p class="cs-empty">Click files in the browser or paste a link below.</p>');
        }
      }
      initSortable();
        // Initialize MarkdownEditor instances for markdown blocks
        $playlist.find('.cs-md-editor-wrap').each(function() {
          var wrap = this;
          var $item = $(wrap).closest('.cs-item');
          var mdSrc = $item.find('.cs-md-source').val() || '';
          if (window.MarkdownEditor) {
            var ed = new MarkdownEditor(wrap, {
              value: mdSrc,
              height: '200px',
              preview: false,
              toolbar: true,
              placeholder: 'Write markdown...',
            });
            wrap._csEditor = ed;
          }
        });
        // Hydrate library HTML into each html-block reference item.
        $playlist.find('.cs-item[data-type="html-block"]').each(function() {
          hydrateHtmlBlockItem($(this));
        });
    }, 'json').fail(() => toast('Failed to load stack', true));
  }

  function hydrateHtmlBlockItem($item) {
    const slug = decodeURI($item.data('slug') || '');
    if (!slug) return;
    const $ta = $item.find('.cs-hb-html-edit');
    // If the user starts typing before the GET returns, abandon the auto-populate.
    $ta.one('input', function() { $ta.removeAttr('data-hb-loading'); });
    $.getJSON('/admin/modules/HTMLBlocks/api.php', { action: 'get', slug: slug }, function(r) {
      if (!r.success || !r.block) {
        $ta.attr('placeholder', 'Library block "' + slug + '" not found — saving will recreate it.');
        $ta.removeAttr('data-hb-loading');
        return;
      }
      const b = r.block;
      // Only overwrite if user hasn't started editing (loading flag still set).
      if ($ta.attr('data-hb-loading') === '1') {
        $ta.val(b.html || '');
        $ta.attr('placeholder', 'Library block HTML — edits propagate everywhere this block is used');
        $ta.removeAttr('data-hb-loading');
      }
      // Stash canonical title + update the visible header.
      $item.attr('data-hb-title', b.title || slug);
      if (b.title) $item.find('.cs-hb-ref-title').text(b.title);
    }).fail(function() {
      $ta.attr('placeholder', 'Could not load library block — check connection');
      $ta.removeAttr('data-hb-loading');
    });
  }

  function saveCurrentStack() {
    if (!currentStackId) return;
    const stackId = currentStackId;
    const items = [];
    const libraryWrites = []; // html-block edits queued for library write-back
    $playlist.find('.cs-item').each(function () {
      const $el = $(this);
      const data = {
        type: $el.data('type'),
        path: decodeURI($el.data('path')),
        thumbnail: decodeURI($el.data('thumbnail')),
        title: decodeURI($el.data('title')),
        author: decodeURI($el.data('author')),
      };
      if (data.type === 'facebook') {
        data.og_type    = decodeURI($el.data('og-type') || '');
        data.fb_subtype = decodeURI($el.data('fb-subtype') || '');
        const evStart = decodeURI($el.data('event-start')    || '');
        const evEnd   = decodeURI($el.data('event-end')      || '');
        const evLoc   = decodeURI($el.data('event-location') || '');
        if (evStart || evEnd || evLoc) {
          data.event = { start_date: evStart, end_date: evEnd, location: evLoc };
        }
      }
      // Persist aspect ratio (from select if present, else from data attr)
      const aspectSel = $el.find('.cs-aspect').val();
      const aspect = aspectSel !== undefined ? aspectSel : decodeURI($el.data('aspect') || '');
      if (aspect) data.aspect = aspect;
      // Persist YouTube playlist id (native videoseries embed) if this item is a playlist
      const plId = decodeURI($el.data('playlist-id') || '');
      if (plId) data.playlist_id = plId;

      // Caption & link wrap
      const caption = $el.find('.cs-caption-input').val()?.trim() || '';
      const linkUrl = $el.find('.cs-linkurl-input').val()?.trim() || '';
      if (caption) data.caption = caption;
      if (linkUrl) data.linkUrl = linkUrl;

      if (data.type === 'html-block') {
        // Library-block reference. Stack JSON keeps only { type, slug } — the
        // canonical HTML + title live in admin/data/html-blocks/{slug}.json.
        // If the user edited the textarea, queue a library write-back so the
        // change propagates everywhere this slug is referenced.
        data.slug = decodeURI($el.data('slug') || '');
        const hbTitle = $el.attr('data-hb-title') || (data.title && !/^\s*</.test(String(data.title)) ? data.title : '') || data.slug;
        const $ta = $el.find('.cs-hb-html-edit');
        const editedHtml = $ta.length ? $ta.val() : null;
        if (data.slug && typeof editedHtml === 'string' && $ta.attr('data-hb-loading') !== '1') {
          libraryWrites.push({ slug: data.slug, title: hbTitle, html: editedHtml });
        }
        // Drop polluted title; the renderer reads title from the library file.
        delete data.title;
        delete data.thumbnail;
        delete data.author;
        delete data.path;
      } else if (data.type === 'markdown') {
        // Get markdown from editor instance or hidden input
        var mdWrap = $el.find('.cs-md-editor-wrap')[0];
        var mdEditor = mdWrap ? mdWrap._csEditor : null;
        data.markdown = mdEditor ? mdEditor.getValue() : ($el.find('.cs-md-source').val() || '');
        data.html = mdEditor ? mdEditor.getHTML() : '';
        data.title = data.markdown.length > 60 ? data.markdown.substring(0, 57) + '...' : (data.markdown || 'Markdown Block');
      } else if (data.type === 'html') {
        data.html = $el.find('.cs-html-edit').val() || decodeURI($el.data('html') || '');
        // Per-block CSS/JS (scoped to this block only; rendered by csr)
        var blockCss = ($el.find('.cs-item-css').val() || '').trim();
        var blockJs  = ($el.find('.cs-item-js').val() || '').trim();
        if (blockCss) data.css = blockCss;
        if (blockJs)  data.js  = blockJs;
        // Title = plain-text preview of the block (strip tags + collapse whitespace);
        // raw HTML in the title field breaks the editor's row markup.
        var plain = data.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        data.title = plain.length > 60 ? plain.substring(0, 57) + '...' : (plain || 'HTML Block');
      } else if (data.type === 'youtube' || data.type === 'facebook' || data.type === 'tiktok') {
        data.muted = $el.find('.cs-muted').is(':checked');
        data.lightbox = $el.find('.cs-lightbox').is(':checked');
      } else {
        data.url = $el.find('.cs-url-input').val()?.trim() || '';
        data.lightbox = $el.find('.cs-lb-check').is(':checked');
      }
      items.push(data);
    });
    const colCount = $columns.val();
    const label = stacks.find(s => s.id === stackId)?.label || stackId;
    $.post(API, {
      action: 'save_content',
      stack: stackId,
      content: JSON.stringify(items),
      column_count: colCount,
      css: $('#cs-stack-css').val() || '',
      js: $('#cs-stack-js').val() || ''
    }, function (r) {
      if (r.success) {
        // Update local cache so cards reflect changes
        stackData[stackId] = { column_count: parseInt(colCount), content: items };
        renderCardGrid();
        csPostParent('cs-saved', { stack: stackId, label: label });   // embed: refresh Page Manager preview
        // Push html-block edits back to the library (one POST per edited block).
        if (libraryWrites.length) {
          let remaining = libraryWrites.length;
          libraryWrites.forEach(function(w) {
            $.post('/admin/shared/ajax_media_handler.php', {
              action: 'save_html_block',
              edit_slug: w.slug,
              title: w.title,
              html: w.html,
              source: 'ContentStacks'
            }, function(rr) {
              if (!rr.success) toast('Library save failed for ' + w.slug + ': ' + (rr.message || 'error'), true);
              if (--remaining === 0) toast(label + ' saved (library updated)');
            }, 'json').fail(function() {
              if (--remaining === 0) toast('Library write failed for ' + w.slug, true);
            });
          });
        } else {
          toast(label + ' saved!');
        }
      } else {
        toast('Error: ' + r.message, true);
      }
    }, 'json').fail(() => toast('Save failed', true));
  }

  // Close
  function csCloseOrExit(){ if (CS_EMBED) { csPostParent('cs-close'); } else { closeModal(); } }
  $('#cs-modal-close').on('click', csCloseOrExit);
  $('#cs-modal-back').on('click', csCloseOrExit);
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && currentStackId) csCloseOrExit();
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (currentStackId) {
        saveCurrentStack();
      }
    }
  });

  // Save button
  $('#cs-modal-save').on('click', saveCurrentStack);

  // Rename
  $('#cs-modal-rename').on('click', function () {
    if (!currentStackId) return;
    const s = stacks.find(x => x.id === currentStackId);
    const newName = prompt('Rename stack:', s.label);
    if (!newName || !newName.trim() || newName.trim() === s.label) return;
    $.post(API, { action: 'rename_stack', stack: currentStackId, label: newName.trim() }, function (r) {
      if (r.success) {
        s.label = newName.trim();
        $('#cs-modal-title').text(s.label);
        renderCardGrid();
        toast('Renamed');
      } else {
        toast(r.message || 'Rename failed', true);
      }
    }, 'json');
  });

  // Delete
  $('#cs-modal-delete').on('click', function () {
    if (!currentStackId) return;
    const s = stacks.find(x => x.id === currentStackId);
    if (!confirm('Delete "' + s.label + '"? This cannot be undone.')) return;
    $.post(API, { action: 'delete_stack', stack: currentStackId }, function (r) {
      if (r.success) {
        stacks = stacks.filter(x => x.id !== currentStackId);
        delete stackData[currentStackId];
        closeModal();
        renderCardGrid();
        toast('Stack deleted');
      } else {
        toast(r.message || 'Delete failed', true);
      }
    }, 'json');
  });

  /* ========== TAB SWITCHING (browser / shortcodes) ========== */
  /* ========== CAPTION TOOLBAR ========== */
  $(document).on('click', '.cs-caption-toolbar button', function () {
    const tag = $(this).data('tag');
    if (!tag) return;
    const $input = $(this).closest('.cs-item-extras').find('.cs-caption-input')[0];
    if (!$input) return;
    const start = $input.selectionStart;
    const end = $input.selectionEnd;
    const val = $input.value;
    const selected = val.substring(start, end);
    const wrapped = '<' + tag + '>' + selected + '</' + tag + '>';
    $input.value = val.substring(0, start) + wrapped + val.substring(end);
    // Reposition cursor after closing tag
    const newPos = start + wrapped.length;
    $input.setSelectionRange(newPos, newPos);
    $input.focus();
  });

  // Remove item
  $(document).on('click', '.cs-item-remove', function () {
    $(this).closest('.cs-item').remove();
  });

  /* ========== HTML BLOCK STYLE PRESETS ========== */
  const HTML_PRESETS = {
    glass: {
      open:  '<div style="backdrop-filter:blur(14px) saturate(1.4);-webkit-backdrop-filter:blur(14px) saturate(1.4);background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:14px;padding:22px 24px;box-shadow:0 8px 24px rgba(0,0,0,.25);color:inherit;">',
      close: '</div>'
    },
    card: {
      open:  '<div style="background:#0f172a;border:1px solid #334155;border-radius:10px;padding:20px 22px;color:#e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,.3);">',
      close: '</div>'
    },
    callout: {
      open:  '<div style="border-left:4px solid #6580eb;background:rgba(101,128,235,0.08);padding:14px 18px;border-radius:4px 8px 8px 4px;color:inherit;">',
      close: '</div>'
    },
    pad: {
      open:  '<div style="padding:16px 20px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:inherit;">',
      close: '</div>'
    }
  };

  function stripOuterWrapper(html) {
    // Strip a single outer <div ...>...</div> if the entire HTML is exactly one such div.
    const m = html.trim().match(/^<div\b[^>]*>([\s\S]*)<\/div>\s*$/i);
    if (!m) return html;
    const inner = m[1];
    // Only strip if there's no sibling div at top level (heuristic: balanced removal)
    const opens = (inner.match(/<div\b/gi) || []).length;
    const closes = (inner.match(/<\/div>/gi) || []).length;
    if (opens === closes) return inner.trim();
    return html;
  }

  $(document).on('click', '.cs-preset-btn', function (e) {
    e.stopPropagation();
    const $btn = $(this);
    const preset = $btn.data('preset');
    const $ta = $btn.closest('.cs-item-controls').find('.cs-html-edit');
    if (!$ta.length) return;
    let val = $ta.val() || '';
    if (preset === 'unwrap') {
      const stripped = stripOuterWrapper(val);
      if (stripped === val) { toast('No outer wrapper to remove', true); return; }
      $ta.val(stripped);
      toast('Wrapper removed');
      return;
    }
    const tpl = HTML_PRESETS[preset];
    if (!tpl) return;
    // If there's already a wrapper, unwrap first so presets replace (not nest)
    const inner = stripOuterWrapper(val);
    $ta.val(tpl.open + (inner || '<!-- content -->') + tpl.close);
    toast(preset.charAt(0).toUpperCase() + preset.slice(1) + ' style applied');
  });

  // Save HTML block to library
  $(document).on('click', '.cs-save-to-lib', function (e) {
    e.stopPropagation();
    const $item = $(this).closest('.cs-item');
    const html = $item.find('.cs-html-edit').val() || '';
    if (!html.trim()) { toast('HTML block is empty', true); return; }
    const defaultTitle = html.replace(/<[^>]+>/g, '').trim().substring(0, 60) || 'Untitled Block';
    const title = prompt('Save to HTML Blocks library — enter a title:', defaultTitle);
    if (!title || !title.trim()) return;
    $.post('/admin/shared/ajax_media_handler.php', {
      action: 'save_html_block',
      title: title.trim(),
      html: html,
      source: 'ContentStacks'
    }, function (r) {
      if (r.success) {
        toast('Saved to HTML Blocks library');
      } else {
        toast(r.message || 'Save failed', true);
      }
    }, 'json').fail(() => toast('Save failed', true));
  });

  // URL input toggles lightbox
  $(document).on('input', '.cs-url-input', function () {
    $(this).siblings('label').find('.cs-lb-check').prop('checked', !$(this).val().trim());
  });

  // Add link
  $('#cs-modal-add-link').on('click', function () {
    const $input = $('#cs-modal-link-input');
    const url = $input.val().trim();
    if (!url) { toast('Enter a URL', true); return; }
    const $btn = $(this);
    $btn.text('Fetching...').prop('disabled', true);
    $.ajax({
      url: RESOLVER,
      type: 'POST',
      dataType: 'json',
      data: { url },
      success: function (r) {
        if (r.success) {
          $playlist.find('.cs-empty').remove();
          $playlist.append(createItemHTML(r));
          $input.val('');
        } else {
          toast('Could not resolve: ' + r.message, true);
        }
      },
      error: () => toast('Resolver failed', true),
      complete: () => $btn.text('Add Link').prop('disabled', false)
    });
  });

  // Add empty HTML block inline
  $('#cs-html-add').on('click', function () {
    if (!currentStackId) { toast('Open a stack first', true); return; }
    const item = { type: 'html', path: '', thumbnail: '', title: 'New HTML Block', html: '' };
    $playlist.find('.cs-empty').remove();
    $playlist.append(createItemHTML(item));
    toast('HTML block added — edit below');
    $playlist.find('.cs-item:last .cs-html-edit').focus();
  });

  // Add empty Markdown block
  $('#cs-md-add').on('click', function () {
    if (!currentStackId) { toast('Open a stack first', true); return; }
    const item = { type: 'markdown', path: '', thumbnail: '', title: 'New Markdown Block', markdown: '', html: '' };
    $playlist.find('.cs-empty').remove();
    $playlist.append(createItemHTML(item));
    toast('Markdown block added');
    // Initialize editor on the new block
    var newItem = $playlist.find('.cs-item:last');
    var wrap = newItem.find('.cs-md-editor-wrap')[0];
    if (wrap && window.MarkdownEditor) {
      var mdSrc = newItem.find('.cs-md-source').val() || '';
      var ed = new MarkdownEditor(wrap, {
        value: mdSrc,
        height: '200px',
        preview: false,
        toolbar: true,
        placeholder: 'Write markdown...',
      });
      wrap._csEditor = ed;
    }
  });

  /* ========== CREATE NEW STACK ========== */
  function createNewStack() {
    const name = prompt('Enter a name for the new stack:');
    if (!name || !name.trim()) return;
    $.post(API, { action: 'create_stack', label: name.trim() }, function (r) {
      if (r.success && r.stack) {
        stacks.push(r.stack);
        stackData[r.stack.id] = { column_count: 1, content: [] };
        renderCardGrid();
        openModal(r.stack.id);
        toast('Created ' + r.stack.label);
      } else {
        toast(r.message || 'Failed to create stack', true);
      }
    }, 'json').fail(() => toast('Create failed', true));
  }
  $('#cs-new-stack-top').on('click', createNewStack);

  /* ========== PLAYLIST ITEM RENDERING ========== */
  function csEscHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  function createItemHTML(item) {
    const rawLabel = item.title || (item.path.length > 40 ? '...' + item.path.slice(-37) : item.path);
    // Clamp so HTML-polluted titles don't sprawl the row + escape so they don't
    // break the surrounding markup.
    const displayPath = csEscHtml(String(rawLabel).length > 80 ? String(rawLabel).slice(0, 77) + '…' : rawLabel);
    const titleAttr = csEscHtml(item.title || item.path);
    let thumbHTML = '';
    const isPdf = item.type === 'pdf' || /\.pdf$/i.test(item.path);
    if (item.type === 'html') {
      thumbHTML = `<div class="cs-item-thumb cs-html-thumb"><i class="fas fa-code"></i></div>`;
    } else if (item.type === 'html-block') {
      // First-class library block reference — show a distinctive purple puzzle icon
      thumbHTML = `<div class="cs-item-thumb cs-hb-thumb" style="color:#a78bfa;background:rgba(167,139,250,0.08);border:1px solid rgba(167,139,250,0.3)"><i class="fas fa-puzzle-piece"></i></div>`;
    } else if (item.type === 'markdown') {
      thumbHTML = `<div class="cs-item-thumb cs-html-thumb" style="color:#34d399"><i class="fas fa-file-code"></i></div>`;
    } else if (isPdf) {
      thumbHTML = `<div class="cs-item-thumb cs-pdf-thumb"><i class="fas fa-file-pdf"></i></div>`;
    } else if (item.type === 'video') {
      const mime = item.path.endsWith('.webm') ? 'video/webm' : (item.path.endsWith('.mov') ? 'video/quicktime' : 'video/mp4');
      thumbHTML = `<video class="cs-item-thumb" muted loop playsinline autoplay><source src="${item.thumbnail || item.path}" type="${mime}"></video>`;
    } else if (item.type === 'facebook') {
      // Choose icon per FB subtype. Events, reels, live, video each get their own visual cue.
      const sub = (item.fb_subtype || 'generic').toLowerCase();
      const subIcon = { event:'fa-calendar-check', reel:'fa-video', live:'fa-tower-broadcast',
                        video:'fa-play', watch:'fa-play', post:'fa-facebook', photo:'fa-image' }[sub] || 'fa-facebook';
      thumbHTML = item.thumbnail
        ? `<img class="cs-item-thumb" src="${item.thumbnail}" alt="" onerror="this.style.display='none'"><span class="cs-fb-sub-badge"><i class="fas ${subIcon}"></i> ${sub}</span>`
        : `<div class="cs-item-thumb cs-fb-thumb"><i class="fab fa-facebook"></i><span class="cs-fb-sub-badge-text">${sub}</span></div>`;
    } else if (item.type === 'tiktok') {
      thumbHTML = item.thumbnail
        ? `<img class="cs-item-thumb" src="${item.thumbnail}" alt="" onerror="this.style.display='none'">`
        : `<div class="cs-item-thumb cs-tt-thumb"><i class="fab fa-tiktok"></i></div>`;
    } else {
      thumbHTML = `<img class="cs-item-thumb" src="${item.thumbnail || item.path}" alt="" onerror="this.style.display='none'">`;
    }

    let controls = '';
    if (item.type === 'markdown') {
      const safeMd = (item.markdown || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      controls = `<div class="cs-md-editor-wrap" style="min-height:200px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;overflow:hidden;"></div>
        <input type="hidden" class="cs-md-source" value="${safeMd}">`;
    } else if (item.type === 'html-block') {
      // Reference to a first-class library block. Render an editable HTML
      // textarea pre-loaded with the library copy; edits propagate back to
      // the library on save (so all other stacks referencing this slug pick
      // up the change). Stack JSON stays minimal: { type, slug }.
      const hbSlug = (item.slug || '').replace(/[^a-z0-9_-]/gi, '');
      // Don't trust item.title for html-block — legacy records have polluted
      // titles (whole HTML body got dumped in). Use slug as placeholder; the
      // live library title is populated after the load fetch.
      const hbTitleRaw = (item.title && !/^\s*</.test(String(item.title))) ? item.title : hbSlug;
      const hbTitle = String(hbTitleRaw).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      const hbShortcode = '[[html-block:' + hbSlug + ']]';
      const initialHtml = (item.html || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      controls = `<div class="cs-hb-ref-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
          <i class="fas fa-puzzle-piece" style="color:#a78bfa"></i>
          <span class="cs-hb-ref-label" style="font-size:.72rem;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.05em;">Library block</span>
          <strong class="cs-hb-ref-title">${hbTitle}</strong>
          <code class="cs-hb-ref-shortcode" title="Click to copy" style="font-size:.7rem;color:#94a3b8;cursor:pointer;">${hbShortcode}</code>
        </div>
        <textarea class="cs-html-edit cs-hb-html-edit" rows="3" placeholder="Loading library HTML…" data-hb-loading="1">${initialHtml}</textarea>
        <div class="cs-hb-ref-hint" style="font-size:.7rem;color:#64748b;margin-top:4px;"><i class="fas fa-info-circle"></i> Edits save back to the library and propagate to every page using this block.</div>
        <div class="cs-item-buttons">
          <a class="cs-hb-ref-open" href="/admin/modules/HTMLBlocks/HTMLBlocks.php?slug=${encodeURIComponent(hbSlug)}" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Open in Library</a>
          <button type="button" class="cs-hb-ref-inline" title="Detach from the library and convert to an inline HTML block (future library edits will no longer reach this stack)"><i class="fas fa-code-branch"></i> Detach to Inline</button>
        </div>`;
    } else if (item.type === 'html') {
      const safeHtml = (item.html || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      const safeCss  = (item.css  || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      const safeJs   = (item.js   || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      controls = `<div class="cs-html-presets" title="Wrap the HTML below in a pre-styled container (self-contained inline styles)">
          <span class="cs-preset-label">Style:</span>
          <button type="button" class="cs-preset-btn" data-preset="glass">Glass</button>
          <button type="button" class="cs-preset-btn" data-preset="card">Card</button>
          <button type="button" class="cs-preset-btn" data-preset="callout">Callout</button>
          <button type="button" class="cs-preset-btn" data-preset="pad">Pad</button>
          <button type="button" class="cs-preset-btn cs-preset-btn--unwrap" data-preset="unwrap" title="Remove outer wrapper">Unwrap</button>
        </div>
        <textarea class="cs-html-edit" rows="3" placeholder="Block HTML — shortcodes allowed">${safeHtml}</textarea>
        <div class="cs-item-buttons">
          <button type="button" class="cs-save-to-lib" title="Save to HTML Blocks library"><i class="fas fa-floppy-disk"></i> Save to Library</button>
          <button type="button" class="cs-edit-cssjs" title="Edit per-block CSS / JS"><i class="fas fa-code"></i> Edit CSS/JS</button>
        </div>
        <details class="cs-item-cssjs-panel">
          <summary style="display:none"></summary>
          <label class="cs-item-cssjs-label">Block CSS <span class="cs-hint">scoped to this block only</span></label>
          <textarea class="cs-item-css" rows="3" placeholder="/* CSS applied only to this block */">${safeCss}</textarea>
          <label class="cs-item-cssjs-label">Block JS <span class="cs-hint">runs once after block renders</span></label>
          <textarea class="cs-item-js" rows="3" placeholder="// JS tied to this block">${safeJs}</textarea>
        </details>`;
    } else if (item.type === 'youtube' || item.type === 'facebook' || item.type === 'tiktok') {
      const mc = (item.muted === undefined || item.muted) ? 'checked' : '';
      const lc = item.lightbox ? 'checked' : '';
      const curAspect = item.aspect || '';
      controls = `<div class="cs-yt-controls"><label><input type="checkbox" class="cs-muted" ${mc}> Muted</label><label><input type="checkbox" class="cs-lightbox" ${lc}> Lightbox</label><label>Aspect <select class="cs-aspect"><option value=""${curAspect===''?' selected':''}>Auto</option><option value="9:16"${curAspect==='9:16'?' selected':''}>9:16</option><option value="16:9"${curAspect==='16:9'?' selected':''}>16:9</option></select></label></div>`;
    } else if (isPdf) {
      const lb = (item.lightbox === undefined ? true : item.lightbox) ? 'checked' : '';
      controls = `<label><input type="checkbox" class="cs-lb-check" ${lb}> Lightbox (PDF viewer)</label>`;
    } else {
      const lb = (item.lightbox === undefined ? true : item.lightbox) ? 'checked' : '';
      controls = `<input type="text" class="cs-url-input" placeholder="Link URL (disables lightbox)" value="${item.url || ''}">
        <label><input type="checkbox" class="cs-lb-check" ${lb}> Lightbox</label>`;
    }

    const captionVal = (item.caption || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const linkUrlVal = (item.linkUrl || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    const ev = item.event || {};
    return `<div class="cs-item" data-type="${item.type}" data-path="${encodeURI(item.path)}"
                 data-thumbnail="${encodeURI(item.thumbnail || '')}"
                 data-title="${encodeURI(item.title || '')}"
                 data-author="${encodeURI(item.author || '')}"
                 data-og-type="${encodeURI(item.og_type || '')}"
                 data-fb-subtype="${encodeURI(item.fb_subtype || '')}"
                 data-event-start="${encodeURI(ev.start_date || '')}"
                 data-event-end="${encodeURI(ev.end_date || '')}"
                 data-event-location="${encodeURI(ev.location || '')}"
                 data-html="${encodeURI(item.html || '')}"
                 data-aspect="${encodeURI(item.aspect || '')}" data-markdown="${encodeURI(item.markdown || '')}"
                 data-playlist-id="${encodeURI(item.playlist_id || '')}"
                 data-slug="${encodeURI(item.slug || '')}">
      ${thumbHTML}
      <div class="cs-item-details">
        <div class="cs-item-path" title="${titleAttr}">${displayPath}</div>
        <div class="cs-item-controls">${controls}</div>
        <div class="cs-item-extras">
          <div class="cs-caption-toolbar">
            <button type="button" data-tag="b" title="Bold"><b>B</b></button>
            <button type="button" data-tag="i" title="Italic"><i>I</i></button>
            <button type="button" data-tag="u" title="Underline"><u>U</u></button>
            <button type="button" data-tag="h1" title="Heading 1">H1</button>
            <button type="button" data-tag="h2" title="Heading 2">H2</button>
            <button type="button" data-tag="h3" title="Heading 3">H3</button>
            <button type="button" data-tag="p" title="Paragraph">P</button>
          </div>
          <div style="display:flex;gap:6px;">
            <input type="text" class="cs-caption-input" placeholder="Caption (basic HTML)" value="${captionVal}" style="flex:1;font-size:.8rem;">
            <input type="text" class="cs-linkurl-input" placeholder="Wrap with link URL" value="${linkUrlVal}" style="flex:1;font-size:.8rem;">
          </div>
        </div>
      </div>
      <button type="button" class="cs-item-remove" title="Delete this block"><i class="fas fa-trash"></i></button>
    </div>`;
  }

  // Toggle the per-block CSS/JS panel on Edit CSS/JS click
  $(document).on('click', '.cs-edit-cssjs', function(){
    var $panel = $(this).closest('.cs-item').find('.cs-item-cssjs-panel');
    if (!$panel.length) return;
    var open = $panel.prop('open');
    $panel.prop('open', !open);
    $(this).toggleClass('active', !open);
  });

  // Copy html-block shortcode
  $(document).on('click', '.cs-hb-ref-shortcode', function(){
    var txt = $(this).text();
    navigator.clipboard.writeText(txt).then(function(){ toast('Shortcode copied'); });
  });

  // "Convert to Inline" — fetch the library block's current HTML and morph
  // this item back into a regular type:"html" item. Useful if an editor
  // wants to diverge this stack's copy from the library (breaking the link).
  $(document).on('click', '.cs-hb-ref-inline', function(){
    var $item = $(this).closest('.cs-item');
    var slug = decodeURI($item.data('slug') || '');
    if (!slug) { toast('No slug on this reference', true); return; }
    if (!confirm('Convert this reference into an editable inline copy?\n\nFuture edits to the library block will no longer flow into this stack.')) return;
    $.getJSON('/admin/modules/HTMLBlocks/api.php', { action: 'get', slug: slug }, function(r){
      if (!r.success || !r.block) { toast(r.message || 'Could not load block', true); return; }
      var b = r.block;
      // Rebuild this item as a regular html type with the block's current html/css/js
      var newItem = {
        type:   'html',
        path:   '',
        thumbnail: '',
        title:  b.title || slug,
        author: '',
        html:   b.html || '',
        css:    b.css  || '',
        js:     b.js   || '',
      };
      $item.replaceWith(createItemHTML(newItem));
      toast('Converted to inline — save the stack to persist');
    }).fail(function(){ toast('Request failed', true); });
  });

  /* ========== MEDIA BROWSER (iframe postMessage) ========== */
  window.addEventListener('message', function (e) {
    if (!e.data) return;

    // Standard media file selection
    if (e.data.type === 'mediaSelected') {
      if (!currentStackId) return;
      const path = e.data.path;
      if (!path) return;
      const type = fileType(path);
      if (type === 'file') { toast('Unsupported file type', true); return; }
      const fullUrl = '/' + path;
      const item = { type, path: fullUrl, thumbnail: fullUrl, title: path.split('/').pop() };
      $playlist.find('.cs-empty').remove();
      $playlist.append(createItemHTML(item));
      toast('Added: ' + item.title);
      return;
    }

    // HTML Block selected from library
    if (e.data.type === 'htmlBlockSelected') {
      if (!currentStackId) return;
      const item = { type: 'html', path: '', thumbnail: '', title: e.data.title || 'HTML Block', html: e.data.html || '' };
      $playlist.find('.cs-empty').remove();
      $playlist.append(createItemHTML(item));
      toast('Inserted: ' + (e.data.title || 'HTML Block'));
      return;
    }

    // Shortcode picked from the Insert Explorer catalog (lightbox "Insert" button)
    if (e.data.type === 'insertSelected') {
      if (!currentStackId) { toast('Open a stack first', true); return; }
      const code = e.data.code;
      if (!code) return;
      const item = { type: 'html', path: '', thumbnail: '', title: code, html: code };
      $playlist.find('.cs-empty').remove();
      $playlist.append(createItemHTML(item));
      toast('Added: ' + (e.data.label || code));
      return;
    }
  });

  function initSortable() {
    $playlist.sortable({
      placeholder: 'cs-placeholder',
      items: '.cs-item'
    });
  }

  /* ========== DRAG-FROM-BROWSER → DROP-ON-STACK ==========
     The Media Browser iframe sets 'application/x-mb-drag' (JSON) on dragstart
     and also posts an mb-drag-start window message. We accept drops on the
     playlist AND the surrounding editor panel; items insert at drop Y. */

  let mbDragActive = false;       // true while iframe reports a drag in progress
  let mbDragPayloadHint = null;   // cached payload from postMessage (fallback)

  window.addEventListener('message', function (e) {
    if (!e.data || typeof e.data !== 'object') return;
    if (e.data.type === 'mb-drag-start') {
      mbDragActive = true;
      mbDragPayloadHint = e.data.payload || null;
      $('.cs-editor-panel').addClass('cs-drop-ready');
    } else if (e.data.type === 'mb-drag-end') {
      mbDragActive = false;
      mbDragPayloadHint = null;
      $('.cs-editor-panel').removeClass('cs-drop-ready');
      $playlist.removeClass('cs-drop-active');
    }
  });

  function findDropTarget($pl, clientY) {
    let before = null;
    $pl.find('.cs-item').each(function() {
      const r = this.getBoundingClientRect();
      const mid = r.top + r.height / 2;
      if (clientY < mid && !before) before = this;
    });
    return before; // element to insert before, or null = append at end
  }

  function parseMbDrag(dt) {
    // Try custom MIME first
    let raw = '';
    try { raw = dt.getData('application/x-mb-drag') || ''; } catch (e) {}
    if (raw) { try { return JSON.parse(raw); } catch (e) {} }
    // Fall back to text/plain sentinel
    let txt = '';
    try { txt = dt.getData('text/plain') || ''; } catch (e) {}
    if (txt.startsWith('__mb_drag__\n')) {
      try { return JSON.parse(txt.slice('__mb_drag__\n'.length)); } catch (e) {}
    }
    return null;
  }

  function shouldAcceptDrag(e) {
    if (mbDragActive) return true;
    const types = Array.from(e.dataTransfer.types || []);
    return types.includes('application/x-mb-drag') || types.includes('text/plain');
  }

  function insertItemsAtDrop(payload, clientY) {
    if (!payload) return 0;
    if (!currentStackId) { toast('Open a stack first', true); return 0; }
    // Shortcode dragged from the Insert Explorer catalog → one HTML block at drop Y
    if (payload.kind === 'shortcode' && payload.code) {
      $playlist.find('.cs-empty').remove();
      const scBefore = findDropTarget($playlist, clientY);
      const scHtml = createItemHTML({ type: 'html', path: '', thumbnail: '', title: payload.code, html: payload.code });
      if (scBefore) $(scHtml).insertBefore(scBefore); else $playlist.append(scHtml);
      initSortable();
      return 1;
    }
    if (!Array.isArray(payload.items) || payload.items.length === 0) return 0;
    $playlist.find('.cs-empty').remove();
    const beforeEl = findDropTarget($playlist, clientY);
    payload.items.forEach(src => {
      const fullUrl = '/' + src.path;
      const item = {
        type: src.type,
        path: fullUrl,
        thumbnail: fullUrl,
        title: src.name || src.path.split('/').pop()
      };
      const html = createItemHTML(item);
      if (beforeEl) {
        $(html).insertBefore(beforeEl);
      } else {
        $playlist.append(html);
      }
    });
    initSortable();
    return payload.items.length;
  }

  const editorPanelEl = document.querySelector('.cs-editor-panel');
  const playlistEl = $playlist[0];

  function wireStackDropTarget(el) {
    if (!el) return;
    el.addEventListener('dragenter', e => {
      if (!shouldAcceptDrag(e)) return;
      e.preventDefault();
      $playlist.addClass('cs-drop-active');
    });
    el.addEventListener('dragover', e => {
      if (!shouldAcceptDrag(e)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      $playlist.addClass('cs-drop-active');
    });
    el.addEventListener('dragleave', e => {
      if (el.contains(e.relatedTarget)) return;
      $playlist.removeClass('cs-drop-active');
    });
    el.addEventListener('drop', e => {
      if (!shouldAcceptDrag(e)) return;
      e.preventDefault();
      e.stopPropagation();  // Prevent outer editor-panel handler from re-inserting
      $playlist.removeClass('cs-drop-active');
      let payload = parseMbDrag(e.dataTransfer) || mbDragPayloadHint;
      const count = insertItemsAtDrop(payload, e.clientY);
      if (count > 0) toast('Added ' + count + ' item' + (count === 1 ? '' : 's'));
    });
  }
  wireStackDropTarget(playlistEl);
  wireStackDropTarget(editorPanelEl);

  /* ========== INIT ========== */

  // Load all stacks, then fetch content for each to populate card previews
  $.getJSON(API, { action: 'list_stacks' }, function (r) {
    if (r.success && Array.isArray(r.stacks)) {
      stacks = r.stacks;
    } else {
      stacks = [{ id: 'stack1', label: 'Content Stack 1' }, { id: 'stack2', label: 'Content Stack 2' }];
    }
    // Fetch content for all stacks to build card previews
    let pending = stacks.length;
    if (pending === 0) { renderCardGrid(); return; }
    stacks.forEach(s => {
      $.post(API, { action: 'get_content', stack: s.id }, function (r) {
        if (r.success && r.data) {
          stackData[s.id] = r.data;
        } else {
          stackData[s.id] = { column_count: 1, content: [] };
        }
        if (--pending === 0) renderCardGrid();
      }, 'json').fail(function () {
        stackData[s.id] = { column_count: 1, content: [] };
        if (--pending === 0) renderCardGrid();
      });
    });
  }).fail(function () {
    stacks = [{ id: 'stack1', label: 'Content Stack 1' }, { id: 'stack2', label: 'Content Stack 2' }];
    renderCardGrid();
  });

});
