(function(){
  'use strict';

  // ---- State ----
  let currentSlug = '';
  let leftContent = '';
  let rightContent = '';
  let cssContent = '';
  let jsContent = '';
  let editingColumn = null; // 'left', 'right', or 'css'
  let editingBlockSlug = '';   // set while editType==='block' (Edit-in-place for [[html-block]])
  let pmReturnTo = null;       // {slug,type} to re-open after closing a nested block edit
  let autosaveTimer = null;

  // New page state
  let newLeftContent = '';
  let newRightContent = '';
  let newCssContent = '';
  let newJsContent = '';
  let newEditingColumn = null;

  // ---- Refs ----
  const editOverlay   = document.getElementById('pm-edit-overlay');
  const editTitle     = document.getElementById('edit-page-title');
  const editLeftPct   = document.getElementById('edit-left-pct');
  const editLeftLbl   = document.getElementById('edit-left-lbl');
  const editRightLbl  = document.getElementById('edit-right-lbl');
  const editRightEn   = document.getElementById('edit-right-enabled');
  const editLumText   = document.getElementById('edit-lum-text');
  // Text Style controls (opt-in reading surface; off by default)
  const editTsEnabled = document.getElementById('edit-ts-enabled');
  const editTsControls= document.getElementById('edit-ts-controls');
  const editTsPreset  = document.getElementById('edit-ts-preset');
  const editTsOpacity = document.getElementById('edit-ts-opacity');
  const editTsRadius  = document.getElementById('edit-ts-radius');
  const editTsBlur    = document.getElementById('edit-ts-blur');
  const editTsShadow  = document.getElementById('edit-ts-shadow');
  const editTsGlow    = document.getElementById('edit-ts-glow');
  const TS_PRESETS = {
    base:  { opacity: 45, radius: 8,  blur: 0,  shadow: false, glow: false },
    apple: { opacity: 55, radius: 18, blur: 14, shadow: true,  glow: false },
    auria: { opacity: 50, radius: 16, blur: 14, shadow: true,  glow: true  }
  };
  function tsSync(){ if (editTsControls) editTsControls.style.display = (editTsEnabled && editTsEnabled.checked) ? '' : 'none'; }
  if (editTsEnabled) editTsEnabled.addEventListener('change', tsSync);
  if (editTsPreset) editTsPreset.addEventListener('change', function(){
    var p = TS_PRESETS[editTsPreset.value]; if (!p) return;
    editTsOpacity.value = p.opacity; editTsRadius.value = p.radius; editTsBlur.value = p.blur;
    editTsShadow.checked = p.shadow; editTsGlow.checked = p.glow;
  });
  [editTsOpacity, editTsRadius, editTsBlur, editTsShadow, editTsGlow].forEach(function(el){
    if (el) el.addEventListener('input', function(){ if (editTsPreset) editTsPreset.value = 'custom'; });
  });
  const editBtnSave   = document.getElementById('edit-btn-save');
  const editBtnView   = document.getElementById('edit-btn-view');
  const editBtnDelete = document.getElementById('edit-btn-delete');
  const editBtnHome    = document.getElementById('edit-btn-home');
  const editBtnAddMenu = document.getElementById('edit-btn-add-menu');
  const editBtnSeo     = document.getElementById('edit-btn-seo');
  const editBtnClose   = document.getElementById('edit-btn-close');
  const editBtnLeft   = document.getElementById('edit-btn-left');
  const editBtnRight  = document.getElementById('edit-btn-right');
  const editBtnCss    = document.getElementById('edit-btn-css');
  const editBtnBack   = document.getElementById('edit-btn-back');
  const editPreview   = document.getElementById('edit-preview-pane');
  const editEditor    = document.getElementById('edit-editor-pane');
  const editStylePane = document.getElementById('edit-style-pane');
  const editIframe    = document.getElementById('edit-preview-iframe');
  const editTextarea  = document.getElementById('edit-textarea');
  const editStatus    = document.getElementById('edit-status');
  const pmHtmlToolbar = document.getElementById('pm-html-toolbar');
  const pmEditFrame   = document.getElementById('pm-edit-frame');
  const pmSourceToggleBtn = pmHtmlToolbar.querySelector('.pm-ht-source-toggle');
  // Default to SOURCE mode (raw textarea) on open.
  // Why: the contenteditable visual editor (iframe) silently injects
  // <div><br></div> on every Enter keypress, which accumulates cruft around
  // shortcodes and panels, breaking page layout fleetwide. Opening in source
  // mode lets operators see the raw HTML + shortcodes and edit them as text.
  // Visual mode is still available via the source-toggle button in the toolbar.
  let pmSourceMode = true;
  if (pmSourceToggleBtn) pmSourceToggleBtn.classList.add('active');
  // Tracks latest content reported by the edit-frame iframe, keyed by column.
  const pmFrameBuf = { left: '', right: '', css: '' };
  let pmFrameReady = false;
  let pmFrameCurrentCol = null;
  let pmFrameCurrentType = 'page';
  let pmFrameCurrentSlug = '';
  // Content to seed into the iframe once it signals pm-ready. Used when the
  // user toggles Source→Visual before the frame has ever loaded (the default
  // source-mode boot path) — the textarea contents need to end up in the
  // iframe even though they diverge from the on-disk JSON the frame loads.
  let pmFramePendingSeed = null;

  // Listen for postMessage from the edit iframe (content sync + ready).
  window.addEventListener('message', function(ev){
    const d = ev && ev.data;
    if (!d || typeof d !== 'object' || !d.type) return;
    if (d.type === 'pm-ready') {
      pmFrameReady = true;
      if (pmFramePendingSeed !== null) {
        pmPostToFrame({ type: 'pm-set', html: pmFramePendingSeed });
        if (pmFrameCurrentCol && pmFrameBuf.hasOwnProperty(pmFrameCurrentCol)) {
          pmFrameBuf[pmFrameCurrentCol] = pmFramePendingSeed;
        }
        pmFramePendingSeed = null;
      }
      return;
    }
    if (d.type === 'pm-content' && typeof d.html === 'string') {
      if (d.col && pmFrameBuf.hasOwnProperty(d.col)) pmFrameBuf[d.col] = d.html;
      // Mirror into state + legacy buffers so save/switch paths see latest.
      if (d.col === 'left')  { leftContent  = d.html; }
      if (d.col === 'right') { rightContent = d.html; }
      if (d.col === 'css')   { cssContent   = d.html; }
    }
  });

  function pmPostToFrame(msg){
    if (pmEditFrame && pmEditFrame.contentWindow) {
      try { pmEditFrame.contentWindow.postMessage(msg, '*'); } catch(_){}
    }
  }
  function pmLoadFrame(col){
    if (!pmEditFrame || !pmFrameCurrentSlug) return;
    pmFrameReady = false;
    pmFrameCurrentCol = col;
    const url = '/admin/modules/PageManager/edit_frame.php'
      + '?slug=' + encodeURIComponent(pmFrameCurrentSlug)
      + '&col='  + encodeURIComponent(col)
      + '&type=' + encodeURIComponent(pmFrameCurrentType || 'page')
      + '&_t='   + Date.now();
    pmEditFrame.src = url;
  }
  function pmFlushFrameContent(){
    // Request a fresh snapshot from the iframe (synchronous on postMessage return).
    if (pmFrameReady) pmPostToFrame({ type: 'pm-get' });
  }

  /* ----------------------------------------------------------------
   *  pmHtmlEditor compatibility shim
   *  The legacy contenteditable <div id="pm-html-editor"> is gone;
   *  editing now happens inside an iframe. This surrogate exposes the
   *  same API the rest of this file already uses:
   *    pmHtmlEditor.innerHTML (get/set/+=)
   *    pmHtmlEditor.focus()
   *    pmHtmlEditor.classList  — proxied to the iframe <iframe> element
   *    pmHtmlEditor.addEventListener('input', fn) — input events come
   *      via postMessage so we forward listeners to the message channel.
   * ---------------------------------------------------------------- */
  const pmHtmlEditor = (function(){
    const inputListeners = [];
    window.addEventListener('message', function(ev){
      const d = ev && ev.data;
      if (d && d.type === 'pm-content') {
        // Fire registered input listeners for autosave, etc.
        for (let i = 0; i < inputListeners.length; i++) {
          try { inputListeners[i](new Event('input')); } catch(_){}
        }
      }
    });
    return {
      get innerHTML(){
        const c = editingColumn;
        // Prefer the iframe's current buffer; fall back to the state var the
        // modal loaded on open (source toggle fires before any keystroke).
        if (c && pmFrameBuf.hasOwnProperty(c) && pmFrameBuf[c]) return pmFrameBuf[c];
        if (c === 'left')  return leftContent  || '';
        if (c === 'right') return rightContent || '';
        if (c === 'css')   return cssContent   || '';
        return '';
      },
      set innerHTML(v){
        const c = editingColumn;
        if (c && pmFrameBuf.hasOwnProperty(c)) pmFrameBuf[c] = String(v);
        pmPostToFrame({ type: 'pm-set', html: String(v) });
      },
      focus(){ pmPostToFrame({ type: 'pm-focus' }); },
      get classList(){ return pmEditFrame ? pmEditFrame.classList : { add(){}, remove(){}, toggle(){} }; },
      addEventListener(type, fn){
        if (type === 'input' && typeof fn === 'function') inputListeners.push(fn);
      }
    };
  })();

  const newOverlay    = document.getElementById('pm-new-overlay');
  const newTitle      = document.getElementById('new-page-title');
  const newLeftPct    = document.getElementById('new-left-pct');
  const newLeftLbl    = document.getElementById('new-left-lbl');
  const newRightLbl   = document.getElementById('new-right-lbl');
  const newRightEn    = document.getElementById('new-right-enabled');
  const newLumText    = document.getElementById('new-lum-text');
  const newBtnCreate  = document.getElementById('new-btn-create');
  const newBtnClose   = document.getElementById('new-btn-close');
  const newBtnLeft    = document.getElementById('new-btn-left');
  const newBtnRight   = document.getElementById('new-btn-right');
  const newBtnCss     = document.getElementById('new-btn-css');
  const newEditorArea = document.getElementById('new-editor-area');
  const newTextarea   = document.getElementById('new-textarea');
  const newStatus     = document.getElementById('new-status');

  const callout = document.getElementById('pm-autosave-callout');

  // ---- Toast ----
  function showToast(message, isError){
    const toast = document.getElementById('toast-notification');
    if(!toast) return;
    if(toast._hideTimeout) clearTimeout(toast._hideTimeout);
    toast.textContent = message;
    toast.className = isError ? 'error' : 'success';
    void toast.offsetWidth;
    toast.classList.add('show');
    toast._hideTimeout = setTimeout(()=> toast.classList.remove('show'), 3000);
  }

  function showCallout(msg){
    if(!callout) return;
    callout.textContent = msg;
    callout.classList.add('show');
    setTimeout(()=> callout.classList.remove('show'), 2000);
  }

  // ---- Slider helpers ----
  function syncEditSlider(){
    const v = editLeftPct.value;
    editLeftLbl.textContent = v + '%';
    editRightLbl.textContent = (100 - v) + '%';
  }
  function syncNewSlider(){
    const v = newLeftPct.value;
    newLeftLbl.textContent = v + '%';
    newRightLbl.textContent = (100 - v) + '%';
  }
  editLeftPct.addEventListener('input', syncEditSlider);
  newLeftPct.addEventListener('input', syncNewSlider);

  // ---- Right column toggle for toolbar buttons ----
  function syncEditRightBtn(){
    editBtnRight.classList.toggle('disabled', !editRightEn.checked);
  }
  editRightEn.addEventListener('change', syncEditRightBtn);
  function syncNewRightBtn(){
    newBtnRight.classList.toggle('disabled', !newRightEn.checked);
  }
  newRightEn.addEventListener('change', syncNewRightBtn);

  // ---- Open Edit Modal ----
  async function openEditModal(slug){
    const overlayEl = document.getElementById('pm-edit-overlay');
    if (overlayEl) overlayEl.setAttribute('data-edit-type', 'page');
    document.getElementById('edit-page-type').value = 'page';
    currentSlug = slug;
    pmFrameCurrentSlug = slug;
    pmFrameCurrentType = 'page';
    pmFrameCurrentCol  = null;
    pmFrameReady = false;
    pmFrameBuf.left = pmFrameBuf.right = pmFrameBuf.css = '';
    editingColumn = null;
    leftContent = '';
    rightContent = '';
    cssContent = '';
    jsContent = '';

    const mlP = document.getElementById('edit-mode-label'); if (mlP) mlP.textContent = 'PAGE EDITOR';
    editTitle.placeholder = 'Page Title';
    // Show modal with preview
    editOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    showPreview();

    // Load page data
    try {
      const res = await fetch('?__load=1&slug=' + encodeURIComponent(slug));
      const data = await res.json();

      editTitle.value = data.page_title || '';
      const pex = document.getElementById('edit-page-excerpt'); if (pex) pex.value = data.meta_description || '';
      leftContent = data.components?.main_content?.content ?? data.left_content ?? '';
      rightContent = data.components?.right_column?.content ?? data.right_content ?? '';
      cssContent = data._page_css || '';
      jsContent = data._page_js || '';
      editRightEn.checked = data.right_column_enabled !== false;
      if (editLumText) editLumText.checked = data.lum_text_off !== true;
      pmPWLoad(data.page_max_width, data.page_pad);   // per-page width/padding overrides
      (function(){
        var ia=document.getElementById('edit-inject-affiliate'); if(ia) ia.value = data.inject_affiliate || 'inherit';
        var iu=document.getElementById('edit-inject-ucs');       if(iu) iu.value = data.inject_ucs || 'inherit';
        var ic=document.getElementById('edit-inject-columns');   if(ic) ic.value = String(data.inject_columns || 0);
      })();
      editLeftPct.value = data.left_width || 65;
      // Text Style (opt-in) — populate from data.text_style; stays off unless a real preset is set.
      (function(){
        var ts = data.text_style, on = ts && ts.preset && ts.preset !== 'none';
        if (editTsEnabled) editTsEnabled.checked = !!on;
        if (on) {
          var known = ['base','apple','auria','custom'];
          if (editTsPreset)  editTsPreset.value  = known.indexOf(ts.preset) >= 0 ? ts.preset : 'custom';
          if (editTsOpacity) editTsOpacity.value = Math.round((ts.opacity != null ? ts.opacity : 0.45) * 100);
          if (editTsRadius)  editTsRadius.value  = ts.radius != null ? ts.radius : 8;
          if (editTsBlur)    editTsBlur.value    = ts.blur   != null ? ts.blur   : 0;
          if (editTsShadow)  editTsShadow.checked = !!ts.shadow;
          if (editTsGlow)    editTsGlow.checked   = !!ts.glow;
        }
        tsSync();
      })();
      const ogEl = document.getElementById('edit-page-og'); if (ogEl) ogEl.value = data.og_image || '';
      syncEditSlider();
      syncEditRightBtn();

      editBtnView.href = '/page.php?p=' + slug;
      editBtnView.style.display = 'inline-block';

      // Load iframe preview
      editIframe.src = '/page.php?p=' + encodeURIComponent(slug);
    } catch(e){
      showToast('Failed to load page: ' + e.message, true);
    }
  }

  function closeEditModal(){
    // Flush iframe content into state before closing (post-back is async,
    // but the latest pm-content message already populated leftContent/etc).
    if(editingColumn === 'left')  leftContent  = pmSourceMode ? editTextarea.value : (pmFrameBuf.left  || leftContent);
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : (pmFrameBuf.right || rightContent);
    editingColumn = null;

    editOverlay.classList.remove('open');
    document.body.style.overflow = '';
    editIframe.src = 'about:blank';
    if (pmEditFrame) pmEditFrame.src = 'about:blank';
    editStylePane.classList.add('hidden');
    currentSlug = '';
    pmFrameCurrentSlug = '';
    pmFrameCurrentCol  = null;
    if(autosaveTimer) clearTimeout(autosaveTimer);
  }

  let showPreview = function(){
    // Sync current editor content back
    if(editingColumn === 'left')  leftContent  = pmSourceMode ? editTextarea.value : (pmFrameBuf.left  || leftContent);
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : (pmFrameBuf.right || rightContent);
    editingColumn = null;

    editPreview.classList.remove('hidden');
    editEditor.classList.add('hidden');
    editBtnBack.style.display = 'none';
    editBtnLeft.classList.remove('active');
    editBtnRight.classList.remove('active');
    pmSetBlockChrome(false);   // restore page-only buttons + Save label after any block edit
    editBtnCss.classList.remove('active');
    pmHtmlToolbar.classList.add('hidden');
    if (pmEditFrame) pmEditFrame.classList.add('hidden');
    document.getElementById('edit-sidebar').style.display = '';
  };

  let showEditor = function(col){
    // Save current editing column first (from source textarea OR iframe buffer)
    if(editingColumn === 'left')  leftContent  = pmSourceMode ? editTextarea.value : (pmFrameBuf.left  || leftContent);
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : (pmFrameBuf.right || rightContent);

    editingColumn = col;
    if(col === 'css') {
      editTextarea.value = cssContent;
      editTextarea.placeholder = 'Page CSS — custom styles for this page...';
      pmHtmlToolbar.classList.add('hidden');
      if (pmEditFrame) pmEditFrame.classList.add('hidden');
      editTextarea.classList.remove('hidden');
    } else {
      const content = col === 'left' ? leftContent : rightContent;
      editTextarea.placeholder = col === 'left' ? 'Left Column — shortcodes, HTML...' : 'Right Column — shortcodes, HTML...';
      pmHtmlToolbar.classList.remove('hidden');
      if(pmSourceMode) {
        // Source mode: raw textarea
        editTextarea.value = content;
        editTextarea.classList.remove('hidden');
        if (pmEditFrame) pmEditFrame.classList.add('hidden');
      } else {
        // Visual mode: iframe loads edit_frame.php with real site CSS
        editTextarea.classList.add('hidden');
        if (pmEditFrame) pmEditFrame.classList.remove('hidden');
        pmLoadFrame(col);
      }
    }

    // Hide sidebar when editing CSS (no shortcodes needed)
    document.getElementById('edit-sidebar').style.display = col === 'css' ? 'none' : '';

    editPreview.classList.add('hidden');
    editEditor.classList.remove('hidden');
    if (editMdPane) editMdPane.classList.add('hidden');
    if (editBtnMd) editBtnMd.classList.remove('active');
    editBtnBack.style.display = '';
    editBtnLeft.classList.toggle('active', col === 'left');
    editBtnRight.classList.toggle('active', col === 'right');
    editBtnCss.classList.toggle('active', col === 'css');

    if(col === 'css' || pmSourceMode) {
      setTimeout(()=> editTextarea.focus(), 100);
    }
    setTimeout(pmSCUpdateTarget, 120);
  };

  // Undo-safe range replace: uses execCommand('insertText') so Ctrl/Cmd-Z works
  // (setting textarea.value directly WIPES the native undo stack — that was the bug).
  function pmReplaceRange(ta, start, end, text){
    ta.focus();
    try { ta.setSelectionRange(start, end); } catch (_) {}
    let ok = false;
    try { ok = document.execCommand('insertText', false, text); } catch (_) {}
    if (!ok) {                                   // fallback (loses undo, but never fails)
      ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
      try { ta.setSelectionRange(start + text.length, start + text.length); } catch (_) {}
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    return start + text.length;                  // caret index after the inserted text
  }

  // Shared: the [[kind:slug]] shortcode the caret sits inside (used by Edit / Unwind /
  // the target readout so all three agree on exactly which shortcode is in play).
  function pmShortcodeAt(val, pos){
    const re = /\[\[([a-z0-9_-]+):([a-z0-9_-]+)[^\]]*\]\]/gi; let m;
    while ((m = re.exec(val))) {
      if (m.index <= pos && re.lastIndex >= pos) {
        return { kind: m[1].toLowerCase(), slug: m[2], start: m.index, end: re.lastIndex, raw: m[0] };
      }
    }
    return null;
  }

  // ---- Edit-target indicators (prove what Edit Shortcode / Unwind will act on) ----
  // Indicator 1: a live readout beneath the toolbar naming the targeted shortcode.
  function pmSCUpdateTarget(){
    const el = document.getElementById('pm-sc-target');
    const eb = document.getElementById('pm-edit-shortcode');
    if (!el) return;
    const et = editOverlay.getAttribute('data-edit-type');
    if (et === 'block' || !pmSourceMode || (editingColumn !== 'left' && editingColumn !== 'right')) {
      el.style.display = 'none'; if (eb) eb.classList.remove('pm-armed'); return;
    }
    el.style.display = '';
    const t = pmShortcodeAt(editTextarea.value, editTextarea.selectionStart);
    const _editable = t && (t.kind === 'html-block' || t.kind === 'stack' || t.kind === 'content-stack');
    if (eb) eb.classList.toggle('pm-armed', !!_editable);   // light up when actionable
    el.classList.remove('has-target', 'other');
    delete el.dataset.start; delete el.dataset.end; delete el.dataset.kind; delete el.dataset.slug;
    if (_editable) {
      el.innerHTML = '<span class="pm-sc-target-lbl">✎ Edits</span> <span class="pm-sc-target-code">[[' + t.kind + ':' + t.slug + ']]</span>';
      el.dataset.start = t.start; el.dataset.end = t.end; el.dataset.kind = t.kind; el.dataset.slug = t.slug; el.classList.add('has-target');
    } else if (t) {
      el.innerHTML = '<span class="pm-sc-target-lbl other">At cursor</span> <span class="pm-sc-target-code">[[' + t.kind + ':' + t.slug + ']]</span> <span class="pm-sc-target-note">— not editable in place, but Unwind works</span>';
      el.dataset.start = t.start; el.dataset.end = t.end; el.classList.add('other');
    } else {
      el.innerHTML = '<span class="pm-sc-target-none">Cursor is not inside a shortcode</span>';
    }
  }
  // Indicator 2: hovering the readout OR the Edit button selects + reveals that exact
  // token in the editor (a real colour change on the shortcode), restoring the caret on leave.
  let _pmSCPrevSel = null;
  function pmSCFlashToken(){
    const el = document.getElementById('pm-sc-target');
    if (!el || el.dataset.start === undefined) return;
    const ta = editTextarea; _pmSCPrevSel = [ta.selectionStart, ta.selectionEnd];
    const s = +el.dataset.start, e = +el.dataset.end;
    ta.focus(); try { ta.setSelectionRange(s, e); } catch (_) {}
    try { const line = ta.value.substring(0, s).split('\n').length; ta.scrollTop = Math.max(0, (line - 4) * 18); } catch (_) {}
  }
  function pmSCUnflash(){
    if (!_pmSCPrevSel) return;
    try { editTextarea.setSelectionRange(_pmSCPrevSel[0], _pmSCPrevSel[1]); } catch (_) {}
    _pmSCPrevSel = null;
  }

  // ---- Per-page Width & Padding controls (Base Settings tab) ----
  function pmPWEls(){
    return {
      wDef: document.getElementById('edit-pw-width-default'),
      wSl:  document.getElementById('edit-pw-width'),
      wVal: document.getElementById('edit-pw-width-val'),
      fl:   document.getElementById('edit-pw-fluid'),
      pDef: document.getElementById('edit-pw-pad-default'),
      pSl:  document.getElementById('edit-pw-pad'),
      pVal: document.getElementById('edit-pw-pad-val'),
    };
  }
  // Reflect state → enabled/disabled + readouts (site-default checked = slider off).
  function pmPWSync(){
    const e = pmPWEls(); if (!e.wDef) return;
    const wOverride = !e.wDef.checked;
    e.fl.disabled = !wOverride;
    e.wSl.disabled = !wOverride || e.fl.checked;
    e.pSl.disabled = e.pDef.checked;
    e.wVal.textContent = (wOverride && e.fl.checked) ? 'Fluid' : (e.wSl.value + 'px');
    e.wVal.classList.toggle('pw-inherit', !wOverride);
    e.pVal.textContent = e.pDef.checked ? 'default' : (e.pSl.value + 'px');
    e.pVal.classList.toggle('pw-inherit', e.pDef.checked);
  }
  // Populate from a page's stored values ('' = inherit site default, 'fluid' = full).
  function pmPWLoad(pmw, pad){
    const e = pmPWEls(); if (!e.wDef) return;
    const hasW = pmw !== undefined && pmw !== null && pmw !== '';
    const fluid = (pmw === 'fluid' || pmw === 'full');
    e.wDef.checked = !hasW;
    e.fl.checked = fluid;
    if (hasW && !fluid && !isNaN(parseInt(pmw, 10))) e.wSl.value = parseInt(pmw, 10);
    const hasP = pad !== undefined && pad !== null && pad !== '';
    e.pDef.checked = !hasP;
    if (hasP && !isNaN(parseInt(pad, 10))) e.pSl.value = parseInt(pad, 10);
    pmPWSync();
  }
  // Append the page's width/padding to a save (empty string = clear → inherit site default).
  function pmPWCollect(fd){
    const e = pmPWEls(); if (!e.wDef) return;
    fd.append('page_max_width', (!e.wDef.checked) ? (e.fl.checked ? 'fluid' : String(e.wSl.value)) : '');
    fd.append('page_pad',       (!e.pDef.checked) ? String(e.pSl.value) : '');
  }

  // Block-edit chrome: page-only shortcode buttons make no sense while editing a
  // block (their currentSlug is the block, not a page → they 404). Hide them, hide the
  // target readout, and relabel Save → "Save Block" so the save path is unmistakable.
  function pmSetBlockChrome(on){
    ['pm-convert-block','pm-edit-shortcode','pm-unwind-shortcode'].forEach(id => {
      const b = document.getElementById(id); if (b) b.style.display = on ? 'none' : '';
    });
    editBtnRight.style.display = on ? 'none' : '';
    const se = document.getElementById('edit-btn-save-exit'); if (se) se.style.display = on ? '' : 'none';
    const st = document.querySelector('[data-action="pm-source-toggle"]'); if (st) st.style.display = on ? 'none' : '';  // block edit is source-only
    const tgt = document.getElementById('pm-sc-target'); if (tgt && on) tgt.style.display = 'none';
    if (editBtnSave) {
      if (on) { if (!editBtnSave.dataset.pageHtml) editBtnSave.dataset.pageHtml = editBtnSave.innerHTML;
                editBtnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Block'; }
      else if (editBtnSave.dataset.pageHtml) { editBtnSave.innerHTML = editBtnSave.dataset.pageHtml; }
    }
    if (on && editBtnBack) editBtnBack.style.display = '';   // "Back to Preview" returns to the page
  }

  // ---- Edit-in-place for [[html-block:slug]] ----
  // Reuses THIS modal (same solid-state editor as Pages/Articles). Opening from a
  // page session autosaves the page first and stashes pmReturnTo so closing the
  // block returns you exactly where you were. v1 = HTML editing (css/js pass through).
  async function openEditBlockModal(slug){
    editOverlay.setAttribute('data-edit-type', 'block');
    const pt = document.getElementById('edit-page-type'); if (pt) pt.value = 'block';
    currentSlug = slug; editingBlockSlug = slug; editingColumn = null;
    leftContent = rightContent = cssContent = jsContent = '';
    editOverlay.classList.add('open'); document.body.style.overflow = 'hidden';
    pmSetBlockChrome(true);   // hide page-only buttons, relabel Save → "Save Block"
    editStatus.textContent = 'Loading block…';
    try {
      const res  = await fetch('/admin/modules/PageManager/api/html-block-io.php?action=get&slug=' + encodeURIComponent(slug), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) { showToast('Block not found: ' + slug, true); return; }
      const b = data.block || {};
      editTitle.value = b.title || slug;
      leftContent = b.html || '';
      cssContent  = b.css  || '';   // preserved verbatim, re-saved unchanged in v1
      jsContent   = b.js   || '';
      pmSourceMode = true;   // block editing is SOURCE-ONLY — the visual iframe is page-bound
                             // (loading/saving it would write PAGE content into the block).
      showEditor('left');
      editStatus.textContent = 'Editing block: ' + slug;
    } catch (err) { showToast('Load error: ' + err.message, true); }
  }

  async function saveEditBlock(){
    leftContent = editTextarea.value;   // block editing is source-only — always the textarea, never the page-bound iframe
    editStatus.textContent = 'Saving block…';
    try {
      const fd = new FormData();
      fd.append('action', 'save');
      fd.append('slug',  editingBlockSlug);
      fd.append('title', editTitle.value || editingBlockSlug);
      fd.append('html',  leftContent);
      fd.append('css',   cssContent);
      fd.append('js',    jsContent);
      const res  = await fetch('/admin/modules/PageManager/api/html-block-io.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok) { showToast('Block saved: ' + editingBlockSlug + (pmReturnTo ? ' — “Back” returns to the page' : '')); editStatus.textContent = 'Saved'; }
      else showToast('Block save failed: ' + ((data && data.error) || res.status), true);
    } catch (err) { showToast('Save error: ' + err.message, true); }
  }

  // Detect the [[kind:slug]] shortcode the caret sits inside; open html-blocks in place.
  async function pmEditShortcodeAtCursor(){
    const et = editOverlay.getAttribute('data-edit-type');
    if (et === 'block') { showToast('Already editing a block.', true); return; }
    if (!pmSourceMode || (editingColumn !== 'left' && editingColumn !== 'right')) {
      showToast('Put your cursor inside a shortcode (Source mode).', true); return;
    }
    const t = pmShortcodeAt(editTextarea.value, editTextarea.selectionStart);
    if (!t) { showToast('No editable shortcode at the cursor — click inside an [[html-block:…]], or right-click one.', true); return; }
    if (t.kind === 'stack' || t.kind === 'content-stack') { pmOpenStackEditor(t.slug); return; }
    if (t.kind !== 'html-block') { showToast('“' + t.kind + '” isn’t editable in place yet.', true); return; }
    pmOpenBlockFrom(t.slug);
  }

  // ---- Content Stacks in-place editor (iframe modal) ----
  // Loads the real ContentStacks builder for one stack (?edit=<slug>&embed=1) and
  // listens for its save/close postMessages.
  function pmOpenStackEditor(slug){
    const ov = document.getElementById('pm-cs-overlay');
    const fr = document.getElementById('pm-cs-frame');
    const sl = document.getElementById('pm-cs-slug');
    const url = '/admin/modules/ContentStacks/ContentStacks.php?edit=' + encodeURIComponent(slug) + '&embed=1';
    if (!ov || !fr) { window.open(url.replace('&embed=1',''), '_blank'); return; }
    if (sl) sl.textContent = slug;
    fr.src = url;
    ov.style.display = 'flex';
  }
  function pmCloseStackEditor(){
    const ov = document.getElementById('pm-cs-overlay');
    const fr = document.getElementById('pm-cs-frame');
    if (ov) ov.style.display = 'none';
    if (fr) fr.src = 'about:blank';
  }
  function pmRefreshPreviewAfterStack(){
    try {
      const pf = document.getElementById('edit-preview-iframe');
      if (pf && pf.src && pf.src !== 'about:blank') pf.src = pf.src;         // reload the live preview
      const ef = document.getElementById('pm-edit-frame');
      if (ef && !ef.classList.contains('hidden') && ef.src && ef.src !== 'about:blank') ef.src = ef.src;  // + the WYSIWYG frame if open
    } catch (_) {}
  }
  document.getElementById('pm-cs-close')?.addEventListener('click', pmCloseStackEditor);
  document.getElementById('pm-cs-overlay')?.addEventListener('click', function(e){ if (e.target === this) pmCloseStackEditor(); });
  window.addEventListener('message', function(ev){
    const d = ev.data; if (!d) return;
    if (d.source === 'contentstacks') {
      if (d.type === 'cs-saved') { showToast('Stack saved.'); pmRefreshPreviewAfterStack(); }
      else if (d.type === 'cs-close') { pmCloseStackEditor(); }
    } else if (d.type === 'pm-edit-stack' && d.slug) {
      pmOpenStackEditor(d.slug);   // WYSIWYG chip ✎ asked us to open the builder
    }
  });

  // Open a block for editing FROM a page session: autosave the page, stash the return,
  // then open the block. Used by caret-Edit, the right-click menu, and the readout click.
  async function pmOpenBlockFrom(slug){
    const et = editOverlay.getAttribute('data-edit-type');
    if (et === 'block') return;
    pmReturnTo = { slug: currentSlug, type: et };
    try { await saveEditPage({ autosave: true }); } catch (_) {}
    openEditBlockModal(slug);
  }

  // Unwind a specific [[kind:slug]] occupying [start,end] in the textarea.
  //
  // Two routes, because the two shortcode families are not the same thing:
  //
  //  html-block  → pull the AUTHORED html + css out of the library. Lossless,
  //                and the library copy is untouched.
  //  everything  → render the shortcode server-side (the same engine the page
  //  else          itself runs) and paste the OUTPUT. That is a snapshot: the
  //                stack keeps existing, but this copy stops tracking it.
  //
  // Stacks were refused outright until 2026-08-17 ("Only html-block can be
  // unwound for now"), which is what the "not yet implemented" report was.
  async function pmUnwindRange(slug, start, end, kind){
    kind = kind || 'html-block';
    const tag = '[[' + kind + ':' + slug + ']]';

    if (kind === 'html-block') {
      if (!confirm('Unwind ' + tag + ' back into inline HTML here? (The block stays in the library.)')) return;
      try {
        const ta = editTextarea;
        const res  = await fetch('/admin/modules/PageManager/api/html-block-io.php?action=get&slug=' + encodeURIComponent(slug), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data || !data.ok) { showToast('Block not found: ' + slug, true); return; }
        const b = data.block || {};
        const inline = (b.css && b.css.trim() ? '<style>\n' + b.css.trim() + '\n</style>\n' : '') + (b.html || '');
        pmReplaceRange(ta, start, end, inline);   // undo-safe
        ta.focus();
        try { ta.setSelectionRange(start, start + inline.length); } catch (_) {}
        showToast('Unwound ' + tag + ' → inline HTML' + (b.css ? ' (+ <style>)' : ''));
      } catch (err) { showToast('Unwind error: ' + err.message, true); }
      return;
    }

    // Say plainly what is being traded away — this is a one-way flatten.
    if (!confirm(
      'Unwind ' + tag + ' into its rendered HTML here?\n\n' +
      'This pastes a SNAPSHOT of what the shortcode renders right now.\n' +
      '• The ' + kind + ' itself is not deleted — other pages keep using it.\n' +
      '• THIS page stops following it: later edits to the ' + kind + ' will not\n' +
      '  reach this copy, and the markup here will not be re-rendered.\n' +
      '• Live embeds (video players, feeds) are captured as they stand now.\n\n' +
      'Undo (Ctrl+Z) restores the shortcode if you change your mind.'
    )) return;

    const btn = document.getElementById('pm-unwind-shortcode');
    if (btn) btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('code', tag);
      const res  = await fetch('/admin/modules/PageManager/api/render-shortcode.php',
                               { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) { showToast('Unwind failed: ' + ((data && data.error) || res.status), true); return; }
      // rendered=false means the engine handed the token back unchanged — an
      // unknown shortcode, or one that only resolves in a page context. Pasting
      // the token back would look like success and change nothing.
      if (!data.rendered || !data.html || data.html === tag) {
        showToast('Nothing to unwind — ' + tag + ' did not render outside the page (it may need page context).', true);
        return;
      }
      const ta = editTextarea;
      pmReplaceRange(ta, start, end, data.html);   // undo-safe
      ta.focus();
      try { ta.setSelectionRange(start, start + data.html.length); } catch (_) {}
      showToast('Unwound ' + tag + ' → ' + data.html.length.toLocaleString() + ' chars of inline HTML');
    } catch (err) {
      showToast('Unwind error: ' + err.message, true);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // Map a pointer (x,y) to a character index in the textarea (best-effort, Chrome-first).
  function pmSCPointToIndex(x, y){
    let node = null, off = -1;
    if (document.caretPositionFromPoint) { const c = document.caretPositionFromPoint(x, y); if (c) { node = c.offsetNode; off = c.offset; } }
    else if (document.caretRangeFromPoint) { const r = document.caretRangeFromPoint(x, y); if (r) { node = r.startContainer; off = r.startOffset; } }
    if (off < 0) return -1;
    if (node === editTextarea) return off;                       // Chrome: offsetNode IS the textarea
    if (node && editTextarea.contains(node)) return off;         // some engines: inner text node
    return -1;
  }

  // Unwind: dissolve [[html-block:slug]] back into inline HTML on the page (the
  // block stays in the library). CSS rides along as an inline <style> so it renders.
  async function pmUnwindShortcodeAtCursor(){
    if (editOverlay.getAttribute('data-edit-type') === 'block') {
      showToast('You’re editing a block — use “Save Block” / “Back to Preview”.', true); return;
    }
    if (!pmSourceMode || (editingColumn !== 'left' && editingColumn !== 'right')) {
      showToast('Put your cursor inside a shortcode (Source mode).', true); return;
    }
    const t = pmShortcodeAt(editTextarea.value, editTextarea.selectionStart);
    if (!t) { showToast('No shortcode at the cursor.', true); return; }
    pmUnwindRange(t.slug, t.start, t.end, t.kind);
  }

  // ---- Save (edit modal) ----
  async function saveEditPage(opts){
    // Sync editor
    if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;

    // Article mode: save via ArticleStore instead of pages/ JSON.
    const overlayEl = document.getElementById('pm-edit-overlay');
    const editType  = overlayEl ? overlayEl.getAttribute('data-edit-type') : 'page';
    if (editType === 'article') return saveEditArticle();
    if (editType === 'block')   return saveEditBlock();

    const fd = new FormData();
    fd.append('__save', '1');
    // Autosaves get a rolling buffer + never clobber deliberate revisions or the origin.
    if (opts && opts.autosave) fd.append('autosave', '1');
    fd.append('page_name', currentSlug);
    fd.append('page_title', editTitle.value);
    fd.append('page_excerpt', (document.getElementById('edit-page-excerpt')?.value || '').trim());
    fd.append('left_width', editLeftPct.value);
    pmPWCollect(fd);   // per-page width/padding
    if(editRightEn.checked) fd.append('right_column_enabled', '1');
    if (editLumText && !editLumText.checked) fd.append('lum_text_off', '1');
    fd.append('inject_affiliate', (document.getElementById('edit-inject-affiliate')||{}).value || 'inherit');
    fd.append('inject_ucs',       (document.getElementById('edit-inject-ucs')||{}).value || 'inherit');
    fd.append('inject_columns',   (document.getElementById('edit-inject-columns')||{}).value || '0');
    fd.append('components[main_content][content]', leftContent);
    fd.append('components[right_column][content]', rightContent);
    fd.append('page_css', cssContent);
    fd.append('page_js', jsContent);
    // Text Style — send the object when enabled, else an explicit 'none' (removes the surface).
    if (editTsEnabled && editTsEnabled.checked) {
      fd.append('text_style', JSON.stringify({
        preset:  editTsPreset ? editTsPreset.value : 'custom',
        opacity: (parseInt(editTsOpacity && editTsOpacity.value, 10) || 0) / 100,
        radius:  parseInt(editTsRadius && editTsRadius.value, 10) || 0,
        blur:    parseInt(editTsBlur && editTsBlur.value, 10) || 0,
        shadow:  !!(editTsShadow && editTsShadow.checked),
        glow:    !!(editTsGlow && editTsGlow.checked),
        surface: 'dark'
      }));
    } else {
      fd.append('text_style', JSON.stringify({ preset: 'none' }));
    }
    fd.append('og_image', (document.getElementById('edit-page-og')?.value || '').trim());

    try {
      const res = await fetch('', { method: 'POST', body: fd });
      const result = await res.json();
      if(result.ok){
        showToast('Page saved successfully!');
        if(result.slug) currentSlug = result.slug;
        editBtnView.href = '/page.php?p=' + currentSlug;
        editBtnView.style.display = 'inline-block';
        // Refresh iframe
        editIframe.src = '/page.php?p=' + encodeURIComponent(currentSlug);
        editStatus.textContent = 'Saved ' + new Date().toLocaleTimeString();
      } else {
        throw new Error(result.error || 'Unknown error');
      }
    } catch(e){
      showToast('Save failed: ' + e.message, true);
      editStatus.textContent = 'Save failed';
    }
  }

  // URL-safe slug from a title (mirrors ArticlesManager's server slugger).
  function pmSlugify(s){
    const base = String(s || '').toLowerCase().trim()
      .replace(/['"’]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 80);
    return base || ('article-' + Date.now());
  }

  async function saveEditArticle(){
    // A title is REQUIRED — ArticleStore::save() throws without a slug, and a new
    // article has no slug until we derive one from the title. Prompt instead of
    // letting the save fail silently.
    const title = editTitle.value.trim();
    if (!title) {
      showToast('Please enter an article title first', true);
      editTitle.focus();
      editStatus.textContent = 'Needs a title';
      return;
    }
    const fd = new FormData();
    fd.append('__save_article', '1');
    // The server-side article route accepts plain fields mapped to ArticleStore.
    fd.append('original_slug', currentSlug);
    // Derive the slug from the title for brand-new articles (no existing slug).
    let newSlug = (document.getElementById('edit-art-slug-hidden')?.value || currentSlug || '').trim();
    if (!newSlug) newSlug = pmSlugify(title);
    fd.append('slug', newSlug || currentSlug);
    fd.append('page_title', editTitle.value);
    fd.append('body_html', leftContent);
    fd.append('eyebrow',   document.getElementById('edit-art-eyebrow').value);
    fd.append('category',  document.getElementById('edit-art-category').value);
    fd.append('author',    document.getElementById('edit-art-author').value);
    fd.append('hero_image',document.getElementById('edit-art-hero').value);
    fd.append('tags',      document.getElementById('edit-art-tags').value);
    fd.append('excerpt',   document.getElementById('edit-art-excerpt').value);
    fd.append('date',      document.getElementById('edit-art-date').value);
    if (document.getElementById('edit-art-published').checked) fd.append('published','1');
    if (document.getElementById('edit-art-pinned').checked)    fd.append('pinned','1');
    fd.append('inject_affiliate', (document.getElementById('edit-inject-affiliate')||{}).value || 'inherit');
    fd.append('inject_ucs',       (document.getElementById('edit-inject-ucs')||{}).value || 'inherit');
    fd.append('inject_columns',   (document.getElementById('edit-inject-columns')||{}).value || '0');
    // featured_home intentionally NOT sent here. It has its own dedicated endpoint fired
    // only on user change of the checkbox (see onchange handler). The regular save path
    // (including autosave) cannot touch the featured flag. This is the architectural fix
    // for the "opening any article silently marks it featured" bug.

    try {
      const res = await fetch('', { method: 'POST', body: fd });
      const result = await res.json();
      if (result.ok) {
        showToast('Article saved');
        if (result.slug) currentSlug = result.slug;
        pmFrameCurrentSlug = currentSlug;
        // Keep the rename-tracking hidden field in sync with the now-saved slug.
        const sh = document.getElementById('edit-art-slug-hidden'); if (sh) sh.value = currentSlug;
        // No longer a brand-new article: it's viewable, and the header reflects edit mode.
        const ml = document.getElementById('edit-mode-label'); if (ml) ml.textContent = 'ARTICLE EDITOR';
        editBtnView.href = '/articles/' + currentSlug;
        editBtnView.style.display = 'inline-block';
        // Preview iframe still shows the live article render.
        editIframe.src = '/articles/' + encodeURIComponent(currentSlug);
        editStatus.textContent = 'Saved ' + new Date().toLocaleTimeString();
      } else {
        throw new Error(result.error || 'save failed');
      }
    } catch (e) {
      showToast('Article save failed: ' + e.message, true);
      editStatus.textContent = 'Save failed';
    }
  }

  // ---- Open Edit Modal for an ARTICLE (loads via ArticleStore) ----
  async function openEditArticleModal(slug){
    const overlayEl = document.getElementById('pm-edit-overlay');
    currentSlug = slug;
    pmFrameCurrentSlug = slug;
    pmFrameCurrentType = 'article';
    pmFrameCurrentCol  = null;
    pmFrameReady = false;
    pmFrameBuf.left = pmFrameBuf.right = pmFrameBuf.css = '';
    editingColumn = null;
    leftContent = rightContent = cssContent = jsContent = '';

    if (overlayEl) overlayEl.setAttribute('data-edit-type', 'article');
    document.getElementById('edit-page-type').value = 'article';
    const mlA = document.getElementById('edit-mode-label'); if (mlA) mlA.textContent = 'ARTICLE EDITOR';
    editTitle.placeholder = 'Article title';
    editOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    showPreview();

    // Hard-reset every text input/checkbox FIRST so stale state from a previous article
    // cannot leak in even if the fetch below fails or returns partial data.
    ['edit-art-eyebrow','edit-art-category','edit-art-author','edit-art-hero','edit-art-tags','edit-art-excerpt','edit-art-date'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    if (window.pmUpdateHeroThumb) window.pmUpdateHeroThumb();
    const pinReset = document.getElementById('edit-art-pinned');    if (pinReset) pinReset.checked = false;
    const pubReset = document.getElementById('edit-art-published'); if (pubReset) pubReset.checked = true;
    // Featured button: show loading state until we know the server-side truth
    const fbReset = document.getElementById('edit-art-featured-btn');
    if (fbReset) {
      fbReset.dataset.state = 'unknown';
      fbReset.disabled = true;
      fbReset.style.display = ''; // visible in article mode
      const lab = document.getElementById('edit-art-featured-label');
      if (lab) lab.textContent = '…';
    }

    try {
      const res = await fetch('?__load_article=1&slug=' + encodeURIComponent(slug));
      const data = await res.json();
      if (!data || data.ok === false) throw new Error(data && data.error || 'not found');

      editTitle.value = data.page_title || '';
      leftContent = data.components?.main_content?.content ?? '';
      // Populate article meta fields
      const a = data._article || {};
      document.getElementById('edit-art-eyebrow').value   = a.eyebrow   || '';
      document.getElementById('edit-art-category').value  = a.category  || '';
      document.getElementById('edit-art-author').value    = a.author    || '';
      document.getElementById('edit-art-hero').value      = a.hero_image|| '';
      if (window.pmUpdateHeroThumb) window.pmUpdateHeroThumb();
      document.getElementById('edit-art-tags').value      = Array.isArray(a.tags) ? a.tags.join(', ') : (a.tags || '');
      document.getElementById('edit-art-excerpt').value   = a.excerpt   || '';
      document.getElementById('edit-art-date').value      = a.date ? a.date.slice(0,16) : '';
      const pubEl = document.getElementById('edit-art-published'); if (pubEl) pubEl.checked = !!a.published;
      const pinEl = document.getElementById('edit-art-pinned');    if (pinEl) pinEl.checked = !!a.pinned;
      // Featured button: render label based on the SERVER'S truth (from _article payload)
      renderFeaturedButton(!!a.featured_home);
      // RIGHT COLUMN injection override (from the page JSON, surfaced by __load_article)
      (function(){
        var ia=document.getElementById('edit-inject-affiliate'); if(ia) ia.value = data.inject_affiliate || 'inherit';
        var iu=document.getElementById('edit-inject-ucs');       if(iu) iu.value = data.inject_ucs || 'inherit';
        var ic=document.getElementById('edit-inject-columns');   if(ic) ic.value = String(data.inject_columns || 0);
      })();
      // Track the original slug so renames work.
      let hidden = document.getElementById('edit-art-slug-hidden');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.id = 'edit-art-slug-hidden';
        document.body.appendChild(hidden);
      }
      hidden.value = a.slug || slug;

      editBtnView.href = '/articles/' + slug;
      editBtnView.style.display = 'inline-block';
      editIframe.src = '/articles/' + encodeURIComponent(slug);
    } catch (e) {
      showToast('Failed to load article: ' + e.message, true);
    }
  }
  // Expose for other modules (ArticlesManager) to open the editor.
  window.PageManagerEditor = window.PageManagerEditor || {};
  window.PageManagerEditor.openArticle = openEditArticleModal;
  window.PageManagerEditor.openPage    = openEditModal;

  // ─── FEATURED ON HOME — button (not checkbox) ─────────────────────────
  // Why a button instead of a checkbox: a checkbox's `.checked` property persists across
  // opens and is read by every form-submit path. Any stale value could silently mark an
  // article featured. A button has no state property — it renders label from the server's
  // truth on load, and click → immediate one-shot AJAX → success.
  function renderFeaturedButton(isFeatured) {
    const btn = document.getElementById('edit-art-featured-btn');
    const lab = document.getElementById('edit-art-featured-label');
    if (!btn) return;
    btn.disabled = false;
    if (isFeatured) {
      btn.dataset.state   = 'on';
      btn.style.background = '#ffd080';
      btn.style.color     = '#1a1a1a';
      btn.style.borderColor = '#ffd080';
      if (lab) lab.textContent = 'Featured — click to unfeature';
    } else {
      btn.dataset.state   = 'off';
      btn.style.background = '';
      btn.style.color     = '';
      btn.style.borderColor = '';
      if (lab) lab.textContent = 'Set as Featured';
    }
  }
  const fhBtn = document.getElementById('edit-art-featured-btn');
  if (fhBtn) {
    fhBtn.addEventListener('click', async () => {
      if (!currentSlug) { showToast('Save the article first', true); return; }
      const current = fhBtn.dataset.state === 'on';
      const desired = !current;
      fhBtn.disabled = true;
      const fhLab = document.getElementById('edit-art-featured-label');
      if (fhLab) fhLab.textContent = '… Saving';
      try {
        const fd = new FormData();
        fd.append('__toggle_featured', '1');
        fd.append('slug', currentSlug);
        fd.append('value', desired ? '1' : '0');
        const r = await fetch('', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'toggle failed');
        renderFeaturedButton(desired);
        showToast(desired ? 'Featured: ' + currentSlug : 'Unfeatured');
      } catch (err) {
        renderFeaturedButton(current);
        showToast('Featured toggle failed: ' + err.message, true);
      }
    });
  }

  // Auto-open via query string: ?edit_article=slug | ?new_article=1 | ?edit=slug
  (function autoOpenFromQuery(){
    try {
      const qs = new URLSearchParams(window.location.search);
      const art = qs.get('edit_article');
      const newArt = qs.get('new_article');
      const pg  = qs.get('edit');
      if (art) {
        setTimeout(() => openEditArticleModal(art), 200);
      } else if (newArt) {
        setTimeout(() => {
          const overlayEl = document.getElementById('pm-edit-overlay');
          if (overlayEl) overlayEl.setAttribute('data-edit-type', 'article');
          document.getElementById('edit-page-type').value = 'article';
          currentSlug = '';
          pmFrameCurrentSlug = '';
          pmFrameCurrentType = 'article';
          editTitle.value = '';
          editTitle.placeholder = 'New article title…';
          leftContent = rightContent = cssContent = jsContent = '';
          ['edit-art-eyebrow','edit-art-category','edit-art-author','edit-art-hero','edit-art-tags','edit-art-excerpt','edit-art-date'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
          if (window.pmUpdateHeroThumb) window.pmUpdateHeroThumb();
          // Clear any leftover rename-tracking slug from a prior edit so this
          // article's slug is derived fresh from its title on first save.
          const shNew = document.getElementById('edit-art-slug-hidden'); if (shNew) shNew.value = '';
          const mlNew = document.getElementById('edit-mode-label'); if (mlNew) mlNew.textContent = 'NEW ARTICLE';
          // Checkbox defaults — null-guarded: featured_home has no field in the
          // edit form (dedicated card toggle), so its element is absent. Setting
          // .checked on that null previously threw and aborted the open silently.
          [['edit-art-published', true], ['edit-art-pinned', false], ['edit-art-featured-home', false]]
            .forEach(([id, val]) => { const el = document.getElementById(id); if (el) el.checked = val; });
          editOverlay.classList.add('open');
          document.body.style.overflow = 'hidden';
          // Land in the editor, NOT the preview: a brand-new article has no slug,
          // so the preview iframe would be about:blank (white screen). Source mode
          // is the default on load, so this shows an empty, immediately-editable
          // left-column textarea (no frame load, which pmLoadFrame skips w/o a slug).
          showEditor('left');
        }, 200);
      } else if (pg) {
        setTimeout(() => openEditModal(pg), 200);
      }
    } catch (_) {}
  })();

  // ---- Delete from edit modal ----
  async function deleteEditPage(){
    if(!currentSlug) return;
    if(!confirm('Delete page "' + currentSlug + '"? This will move it to trash.')) return;
    try {
      const fd = new FormData();
      fd.append('__pm_action', 'delete_page');
      fd.append('slug', currentSlug);
      const res = await fetch('', { method: 'POST', body: fd });
      const result = await res.json();
      if(result.ok){
        showToast('Page "' + currentSlug + '" deleted');
        // Remove card from DOM
        const card = document.querySelector('.pm-preview-card[data-page="' + currentSlug + '"]');
        if(card) card.remove();
        closeEditModal();
      } else {
        throw new Error(result.error || 'Delete failed');
      }
    } catch(e){
      showToast('Delete failed: ' + e.message, true);
    }
  }

  // ---- Edit modal event listeners ----
  editBtnClose.addEventListener('click', closeEditModal);
  editBtnSave.addEventListener('click', saveEditPage);
  document.getElementById('edit-btn-save-exit')?.addEventListener('click', pmSaveBlockAndExit);
  // View stays VISIBLE always. On an unsaved item its href is still "#", which would
  // jump the page to the top ("jumps out and back in") — intercept and say so plainly.
  editBtnView.addEventListener('click', function(e){
    const href = editBtnView.getAttribute('href') || '';
    if (!currentSlug || href === '' || href === '#') {
      e.preventDefault();
      showToast('Save first — then View opens the live page', true);
    }
  });

  // Page Settings drawer: collapse/expand after it's open.
  document.getElementById('pm-settings-collapse')?.addEventListener('click', function(){
    const body = document.getElementById('pm-settings-body');
    const open = this.getAttribute('aria-expanded') === 'true';
    this.setAttribute('aria-expanded', open ? 'false' : 'true');
    this.classList.toggle('collapsed', open);
    if (body) body.classList.toggle('pm-collapsed', open);
  });

  // Width/padding controls: live readouts + enable/disable
  function pmPWSnap(val, marks, thresh){ for (const m of marks){ if (Math.abs(val - m) <= thresh && val !== m) return m; } return null; }
  (function(){
    const e = pmPWEls();
    [e.wDef, e.pDef, e.fl].forEach(el => el && el.addEventListener('change', pmPWSync));
    [e.wSl, e.pSl].forEach(el => el && el.addEventListener('input', pmPWSync));
    // Detent chips: click a typical value → set the slider + auto-enable the override.
    document.querySelectorAll('.pm-pw-chip').forEach(chip => chip.addEventListener('click', () => {
      const v = parseInt(chip.dataset.v, 10);
      if (chip.dataset.pw === 'width') { e.wDef.checked = false; e.fl.checked = false; e.wSl.value = v; }
      else { e.pDef.checked = false; e.pSl.value = v; }
      pmPWSync();
    }));
    // Snap onto a nearby typical value when the slider settles (on release).
    if (e.wSl) e.wSl.addEventListener('change', () => { const s = pmPWSnap(+e.wSl.value, [1200,1480,1760,2000], 28); if (s !== null) { e.wSl.value = s; pmPWSync(); } });
    if (e.pSl) e.pSl.addEventListener('change', () => { const s = pmPWSnap(+e.pSl.value, [0,16,24,32,48,64], 5); if (s !== null) { e.pSl.value = s; pmPWSync(); } });
  })();
  editBtnDelete.addEventListener('click', deleteEditPage);
  if (editBtnHome) editBtnHome.addEventListener('click', ()=> setHomePage(currentSlug));
  if (editBtnAddMenu) editBtnAddMenu.addEventListener('click', ()=> addToMenu(currentSlug, editTitle ? editTitle.value : currentSlug));
  if (editBtnSeo) editBtnSeo.addEventListener('click', async () => {
    if (!currentSlug) { alert('No page loaded'); return; }
    const origLabel = editBtnSeo.innerHTML;
    editBtnSeo.disabled = true;
    editBtnSeo.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
    try {
      const fd = new FormData();
      fd.append('action', 'seo_generate_one');
      fd.append('slug', currentSlug);
      const r = await fetch('/admin/modules/SiteSettings/api.php', { method: 'POST', body: fd });
      const data = await r.json();
      if (data.ok && data.data) {
        const d = data.data;
        if (d.status === 'ok') {
          editBtnSeo.innerHTML = '<i class="fa-solid fa-check"></i> SEO Generated';
          alert('Meta description generated and saved:\n\n' + d.description);
        } else if (d.status === 'skipped') {
          editBtnSeo.innerHTML = '<i class="fa-solid fa-check"></i> Already OK';
          alert('SEO already good for this page (' + d.reason + ')');
        } else {
          editBtnSeo.innerHTML = origLabel;
          alert('Failed: ' + (d.reason || 'unknown'));
        }
      } else {
        editBtnSeo.innerHTML = origLabel;
        alert('Error: ' + (data.error || 'unknown'));
      }
    } catch (err) {
      editBtnSeo.innerHTML = origLabel;
      alert('Request failed: ' + err.message);
    }
    setTimeout(() => { editBtnSeo.disabled = false; editBtnSeo.innerHTML = origLabel; }, 4000);
  });
  editBtnLeft.addEventListener('click', ()=> showEditor('left'));
  editBtnRight.addEventListener('click', ()=>{
    if(!editRightEn.checked) return;
    showEditor('right');
  });
  editBtnCss.addEventListener('click', ()=> showEditor('css'));
  document.getElementById('edit-btn-swap').addEventListener('click', ()=>{
    // Sync current editor to state
    if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    // cssContent is managed by style pane controls directly
    // Swap
    const tmp = leftContent;
    leftContent = rightContent;
    rightContent = tmp;
    // Reload editor if viewing a column
    if(editingColumn === 'left' || editingColumn === 'right'){
      const content = editingColumn === 'left' ? leftContent : rightContent;
      if(pmSourceMode) editTextarea.value = content;
      else pmHtmlEditor.innerHTML = content;
    }
    showToast('Columns swapped — save to keep changes.');
  });
  editBtnBack.addEventListener('click', ()=> showPreview());

  // ---- Version history (previous-version picker) ----
  const pmVersionsBtn     = document.getElementById('edit-btn-versions');
  const pmVersionsOverlay = document.getElementById('pm-versions-overlay');
  const pmVersionsClose   = document.getElementById('pm-versions-close');
  const pmVersionsList    = document.getElementById('pm-versions-list');

  function pmParseRevTimestamp(fname){
    // Accepts committed "home.20260422-052331.rev.json", autosaves "...auto.rev.json",
    // and the pinned origin "...origin.rev.json" (bare "20260422-052331.rev.json" too).
    const m = fname.match(/(\d{8})-(\d{6})(?:\.(auto|origin))?\.rev\.json$/);
    if(!m) return { label: fname, ts: 0, type: 'committed' };
    const s = m[1] + m[2]; // YYYYMMDDHHMMSS
    const d = new Date(
      +s.slice(0,4), +s.slice(4,6)-1, +s.slice(6,8),
      +s.slice(8,10), +s.slice(10,12), +s.slice(12,14)
    );
    return { label: d.toLocaleString(), ts: d.getTime(), type: m[3] || 'committed' };
  }

  async function pmOpenVersions(){
    if(!currentSlug){ showToast('Open a page first', true); return; }
    pmVersionsOverlay.style.display = 'block';
    pmVersionsList.innerHTML = '<div style="color:#666;text-align:center;padding:30px">Loading…</div>';
    try {
      const res = await fetch('?__revs=1&slug=' + encodeURIComponent(currentSlug));
      const revs = await res.json();
      if(!Array.isArray(revs) || revs.length === 0){
        pmVersionsList.innerHTML = '<div style="color:#888;text-align:center;padding:30px">No previous versions yet — they accrue as you save.</div>';
        return;
      }
      // Filter to rev.json entries only, newest first
      const items = revs
        .filter(f => /\.rev\.json$/.test(f))
        .map(f => ({ file: f, ...pmParseRevTimestamp(f) }))
        .sort((a,b) => b.ts - a.ts);
      if(items.length === 0){
        pmVersionsList.innerHTML = '<div style="color:#888;text-align:center;padding:30px">No revision snapshots found.</div>';
        return;
      }
      const revBadge = t => (
        t === 'origin' ? ' <span style="color:#f5c542;font-size:.7rem;margin-left:6px;border:1px solid #f5c542;border-radius:3px;padding:0 5px" title="First version — always retained"><i class="fa-solid fa-thumbtack"></i> ORIGIN</span>' :
        t === 'auto'   ? ' <span style="color:#8a93a6;font-size:.7rem;margin-left:6px;border:1px solid #444;border-radius:3px;padding:0 5px">auto</span>' :
                         ' <span style="color:#6580eb;font-size:.7rem;margin-left:6px;border:1px solid #6580eb;border-radius:3px;padding:0 5px">saved</span>'
      );
      pmVersionsList.innerHTML = items.map((r, i) => (
        '<div class="pm-rev-row" data-rev="' + encodeURIComponent(r.file) + '" ' +
            'style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;margin-bottom:6px;border:1px solid #333;border-radius:6px;background:#11111a;cursor:pointer">' +
          '<div>' +
            '<div style="color:#e8edf5;font-weight:600">' + r.label + revBadge(r.type) + (i===0 ? ' <span style="color:#34d399;font-size:.75rem;margin-left:6px">← current</span>' : '') + '</div>' +
            '<div style="color:#666;font-size:.75rem;font-family:monospace">' + r.file + '</div>' +
          '</div>' +
          '<button class="pm-rev-restore" data-rev="' + encodeURIComponent(r.file) + '" ' +
                  'style="background:#1a1a3e;border:1px solid #6580eb;color:#6580eb;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:600">' +
            '<i class="fa-solid fa-rotate-left"></i> Load' +
          '</button>' +
        '</div>'
      )).join('');
    } catch(e){
      pmVersionsList.innerHTML = '<div style="color:#e05252;text-align:center;padding:30px">Load failed: ' + e.message + '</div>';
    }
  }

  async function pmRestoreRevision(revFile){
    if(!currentSlug || !revFile){ return; }
    try {
      const res = await fetch('?__rev_get=1&slug=' + encodeURIComponent(currentSlug) + '&rev=' + encodeURIComponent(revFile));
      const data = await res.json();
      if(!data || typeof data !== 'object'){ showToast('Revision unreadable', true); return; }
      // Reset the edit-frame buffers + editing state BEFORE applying the revision. Without this,
      // showPreview() re-reads pmFrameBuf back over the freshly-loaded revision (leftContent =
      // pmFrameBuf.left || leftContent) → the restore silently no-op'd and Save re-committed the
      // OLD content. Clearing the buffers makes showPreview render the REVISION into the editor.
      pmFrameBuf.left = pmFrameBuf.right = pmFrameBuf.css = '';
      pmFrameReady = false;
      editingColumn = null;
      // Apply to in-memory edit state (mirror of the __load flow)
      editTitle.value = data.page_title || editTitle.value || '';
      leftContent  = data.components?.main_content?.content ?? data.left_content  ?? '';
      rightContent = data.components?.right_column?.content ?? data.right_content ?? '';
      cssContent = (typeof data._page_css === 'string') ? data._page_css : cssContent;
      jsContent  = (typeof data._page_js  === 'string') ? data._page_js  : jsContent;
      if (typeof data.right_column_enabled === 'boolean') editRightEn.checked = data.right_column_enabled;
      if (pmSourceMode && editTextarea) editTextarea.value = leftContent;
      pmVersionsOverlay.style.display = 'none';
      // Force the editor to re-render into the revision content.
      showPreview();
      showToast('Loaded revision into the editor — review, then click Save to commit it.');
    } catch(e){
      showToast('Restore failed: ' + e.message, true);
    }
  }

  if(pmVersionsBtn)   pmVersionsBtn.addEventListener('click', pmOpenVersions);
  if(pmVersionsClose) pmVersionsClose.addEventListener('click', ()=> pmVersionsOverlay.style.display = 'none');
  if(pmVersionsOverlay) pmVersionsOverlay.addEventListener('click', e => {
    if(e.target === pmVersionsOverlay){ pmVersionsOverlay.style.display = 'none'; return; }
    const btn = e.target.closest('.pm-rev-restore') || e.target.closest('.pm-rev-row');
    if(btn && btn.dataset.rev){
      pmRestoreRevision(decodeURIComponent(btn.dataset.rev));
    }
  });

  // Click overlay to close
  editOverlay.addEventListener('click', e=>{
    if(e.target === editOverlay) closeEditModal();
  });

  // ---- Autosave in edit modal ----
  function scheduleAutosave(){
    if(autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(()=>{
      if(!currentSlug) return;
      saveEditPage({autosave:true}).then(()=> showCallout('Autosaved'));
    }, 5000);
  }
  editTextarea.addEventListener('input', scheduleAutosave);

  // ---- Shortcode pill insertion (works in both modals) ----
  // Track the last-focused content textarea so pill-clicks insert into the
  // field the user was actually editing — NOT the first textarea in the
  // overlay (which used to be Excerpt).
  let lastFocusedTextarea = null;
  document.addEventListener('focusin', e => {
    const t = e.target;
    if (t && t.tagName === 'TEXTAREA' && (t.id === 'edit-textarea' || t.id === 'new-textarea')) {
      lastFocusedTextarea = t;
    }
  });

  document.addEventListener('click', e=>{
    if(!e.target.classList.contains('js-modal-insert')) return;
    e.preventDefault();
    e.stopPropagation();
    const code = e.target.dataset.code;
    if(!code) return;
    const overlay = e.target.closest('.pm-edit-overlay, .pm-new-overlay, .pm-new-editor-area');
    if(!overlay) return;

    // Insert into the active editor (contenteditable in visual mode, textarea in source/CSS mode)
    const isEditOverlay = overlay.classList.contains('pm-edit-overlay');
    if (isEditOverlay && !pmSourceMode && editingColumn !== 'css') {
      // Visual mode: forward insert to the edit-frame iframe
      pmPostToFrame({ type: 'pm-exec', cmd: 'insertHTML', arg: code });
    } else {
      // Source mode or new-page modal: insert into the correct content textarea
      // (NOT the first textarea in the overlay, which is Excerpt).
      let ta = null;
      if (lastFocusedTextarea && overlay.contains(lastFocusedTextarea)) {
        ta = lastFocusedTextarea;
      } else if (isEditOverlay) {
        ta = document.getElementById('edit-textarea');
      } else {
        ta = document.getElementById('new-textarea');
      }
      if(!ta) return;
      // Block shortcodes get one clean blank line above/below so codes never jam
      // ( ]]][[[ ); inline opt-out via data-inline on the pill. Also normalizes any
      // existing whitespace jam right at the caret.
      const isInline = e.target.dataset.inline === '1' || e.target.dataset.inline === 'true';
      const start = ta.selectionStart, end = ta.selectionEnd, val = ta.value;
      let lead = '', trail = '', rStart = start, rEnd = end;
      if (!isInline) {
        const trimL = val.substring(0, start).match(/[ \t\r\n]+$/); if (trimL) rStart = start - trimL[0].length;
        const trimR = val.substring(end).match(/^[ \t\r\n]+/);      if (trimR) rEnd = end + trimR[0].length;
        lead  = rStart === 0        ? '' : '\n\n';
        trail = rEnd >= val.length  ? '' : '\n\n';
      }
      const caretStart = rStart + lead.length;
      pmReplaceRange(ta, rStart, rEnd, lead + code + trail);   // undo-safe
      ta.focus();
      // Landing zone: flash-select where it landed, then collapse the caret after it.
      try { ta.setSelectionRange(caretStart, caretStart + code.length); } catch(_){}
      setTimeout(()=>{ try { ta.setSelectionRange(caretStart + code.length, caretStart + code.length); } catch(_){} }, 650);
      lastFocusedTextarea = ta;
    }
    // Flash
    e.target.style.background = '#00d2ff';
    e.target.style.color = '#000';
    setTimeout(()=>{ e.target.style.background=''; e.target.style.color=''; }, 200);
  });

  // ---- Insert Explorer: shared insertion plumbing ----
  // One path for everything the Explorer iframe emits (media files,
  // shortcodes) and for drag-and-drop into the editors.

  // Build the right HTML tag for a media path (video/audio/pdf/img).
  function pmMediaTag(path){
    const ext = path.split('.').pop().toLowerCase();
    const videoExts = ['mp4','webm','mov','ogg','m4v'];
    const audioExts = ['mp3','wav','ogg','m4a','aac'];
    if (videoExts.indexOf(ext) >= 0) return '<video src="' + path + '" controls style="max-width:100%;border-radius:6px"></video>';
    if (audioExts.indexOf(ext) >= 0) return '<audio src="' + path + '" controls style="width:100%"></audio>';
    if (ext === 'pdf')               return '<a href="' + path + '" target="_blank">' + path.split('/').pop() + '</a>';
    return '<img src="' + path + '" alt="" style="max-width:100%">';
  }

  // Insert a snippet into the active editor: visual frame in WYSIWYG mode,
  // otherwise the relevant content textarea (at the caret).
  function pmInsertContent(content, targetTa){
    const isEdit = editOverlay.classList.contains('open');
    if (isEdit && !pmSourceMode && editingColumn !== 'css' && !targetTa) {
      pmPostToFrame({ type: 'pm-exec', cmd: 'insertHTML', arg: content });
      return;
    }
    const ta = targetTa
             || (isEdit ? editTextarea
                        : (newOverlay.style.display !== 'none' ? document.getElementById('new-textarea') : null));
    if(!ta) return;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    // Shortcodes get a carriage return above and below so they never collide with adjacent
    // HTML or other shortcodes (without stacking blank lines when one's already there).
    let toInsert = content;
    if (/^\s*\[\[[\s\S]*\]\]\s*$/.test(content)) {
      const before = ta.value.substring(0, start);
      const afterTxt = ta.value.substring(end);
      toInsert = content.trim();
      if (before !== '' && !/\n[ \t]*$/.test(before)) toInsert = '\n' + toInsert;
      if (afterTxt !== '' && !/^[ \t]*\n/.test(afterTxt)) toInsert = toInsert + '\n';
    }
    ta.value = ta.value.substring(0, start) + toInsert + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + toInsert.length;
    ta.focus();
    lastFocusedTextarea = ta;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ---- Explorer iframe messages → insert into active editor ----
  window.addEventListener('message', function(evt){
    if(!evt.data) return;

    // Standard media file → insert appropriate tag based on file type
    if(evt.data.type === 'mediaSelected' && evt.data.path){
      pmInsertContent(pmMediaTag(evt.data.path));
      return;
    }

    // Insert Explorer shortcode (Inserts tab lightbox "Insert" button)
    if(evt.data.type === 'insertSelected' && evt.data.code){
      pmInsertContent(evt.data.code);
      return;
    }

    // HTML Block from library → insert raw HTML
    if(evt.data.type === 'htmlBlockSelected' && evt.data.html){
      pmInsertContent(evt.data.html);
      return;
    }
  });

  // ---- Drag-to-editor: accept Explorer drags (x-mb-drag) on the textareas ----
  // Media items drop as their built tag; shortcode items drop as their code.
  // preventDefault stops the browser's native text/plain insertion (which
  // would paste the raw sentinel payload for media drags).
  function pmDragContent(dt){
    let raw = dt.getData('application/x-mb-drag');
    if (!raw) {
      const tp = dt.getData('text/plain') || '';
      if (tp.indexOf('__mb_drag__\n') === 0) raw = tp.slice('__mb_drag__\n'.length);
      else return null;   // not an Explorer drag — let the browser handle it
    }
    try {
      const p = JSON.parse(raw);
      if (p.kind === 'shortcode' && p.code) return p.code;
      if (p.paths && p.paths.length) return p.paths.map(function(path){ return pmMediaTag('/' + String(path).replace(/^\//,'')); }).join('\n');
    } catch(e) {}
    return null;
  }
  function pmWireEditorDrop(ta){
    if (!ta || ta.__pmDropWired) return;
    ta.__pmDropWired = true;
    ta.addEventListener('dragover', function(e){
      if (e.dataTransfer && (e.dataTransfer.types.indexOf('application/x-mb-drag') >= 0)) {
        e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; ta.classList.add('pm-drop-armed');
      }
    });
    ta.addEventListener('dragleave', function(){ ta.classList.remove('pm-drop-armed'); });
    ta.addEventListener('drop', function(e){
      ta.classList.remove('pm-drop-armed');
      const content = e.dataTransfer ? pmDragContent(e.dataTransfer) : null;
      if (content === null) return;          // native drop (plain text etc.)
      e.preventDefault();
      // Drop at the pointer's caret when the browser supports it
      if (typeof ta.setSelectionRange === 'function' && typeof document.caretPositionFromPoint === 'function') {
        try {
          const cp = document.caretPositionFromPoint(e.clientX, e.clientY);
          if (cp && cp.offsetNode === ta) ta.setSelectionRange(cp.offset, cp.offset);
        } catch(err) {}
      }
      pmInsertContent(content, ta);
    });
  }
  pmWireEditorDrop(document.getElementById('edit-textarea'));
  pmWireEditorDrop(document.getElementById('new-textarea'));

  // ---- Sidebar resizer: drag the divider to widen the Insert Explorer ----
  // Width persists in localStorage (shared by both modals). While dragging,
  // iframes get pointer-events:none — otherwise the Explorer iframe swallows
  // the mousemove and the drag dies the moment the cursor crosses it.
  (function initSidebarResize(){
    const KEY = 'pm_sidebar_w';
    const MIN = 280;
    const saved = parseInt(localStorage.getItem(KEY) || '0', 10);
    const sidebars = ['edit-sidebar', 'new-sidebar'].map(id => document.getElementById(id)).filter(Boolean);
    if (saved >= MIN) sidebars.forEach(sb => { sb.style.width = saved + 'px'; });

    document.querySelectorAll('.pm-sidebar-resizer').forEach(rz => {
      const sb = document.getElementById(rz.dataset.resizeFor);
      if (!sb) return;
      rz.addEventListener('mousedown', e => {
        e.preventDefault();
        const startX = e.clientX;
        const startW = sb.getBoundingClientRect().width;
        const maxW = Math.round(window.innerWidth * 0.7);
        rz.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        document.querySelectorAll('iframe').forEach(f => { f.style.pointerEvents = 'none'; });
        const move = ev => {
          const w = Math.min(maxW, Math.max(MIN, Math.round(startW + (ev.clientX - startX))));
          sidebars.forEach(s => { s.style.width = w + 'px'; });
        };
        const up = () => {
          document.removeEventListener('mousemove', move);
          document.removeEventListener('mouseup', up);
          rz.classList.remove('dragging');
          document.body.style.cursor = '';
          document.body.style.userSelect = '';
          document.querySelectorAll('iframe').forEach(f => { f.style.pointerEvents = ''; });
          localStorage.setItem(KEY, String(Math.round(sb.getBoundingClientRect().width)));
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
      });
      // Double-click the divider = reset to the default width
      rz.addEventListener('dblclick', () => {
        sidebars.forEach(s => { s.style.width = ''; });
        localStorage.removeItem(KEY);
      });
    });
  })();

  // ---- Field picker: 📁 buttons open the Explorer (mode=picker) to fill
  // a path input (hero image, og image). Uses the media-pick contract the
  // Explorer already emits from its lightbox "Use This File" button.
  let pmFieldPickTarget = null, pmFieldPickOverlay = null;
  function pmOpenFieldPicker(input){
    pmFieldPickTarget = input;
    if (!pmFieldPickOverlay) {
      pmFieldPickOverlay = document.createElement('div');
      pmFieldPickOverlay.className = 'pm-fieldpick-overlay';
      pmFieldPickOverlay.innerHTML =
          '<div class="pm-fieldpick-modal">'
        +   '<div class="pm-fieldpick-head"><span>Pick an image</span><button type="button" class="pm-fieldpick-close" title="Close">&times;</button></div>'
        +   '<iframe class="pm-fieldpick-frame" src="/admin/shared/explorer/media-explorer.php?host=pagemanager-field&mode=picker&types=images" title="Media picker"></iframe>'
        + '</div>';
      document.body.appendChild(pmFieldPickOverlay);
      pmFieldPickOverlay.addEventListener('click', e => { if (e.target === pmFieldPickOverlay) pmCloseFieldPicker(); });
      pmFieldPickOverlay.querySelector('.pm-fieldpick-close').addEventListener('click', pmCloseFieldPicker);
    }
    pmFieldPickOverlay.style.display = 'flex';
  }
  function pmCloseFieldPicker(){
    if (pmFieldPickOverlay) pmFieldPickOverlay.style.display = 'none';
    pmFieldPickTarget = null;
  }
  document.addEventListener('click', e => {
    const btn = e.target.closest('.pm-pick-btn');
    if (!btn) return;
    e.preventDefault();
    const input = document.getElementById(btn.dataset.pickTarget || '');
    if (input) pmOpenFieldPicker(input);
  });
  window.addEventListener('message', evt => {
    if (!evt.data || evt.data.type !== 'media-pick' || !pmFieldPickTarget) return;
    pmFieldPickTarget.value = '/' + String(evt.data.path || '').replace(/^\//, '');
    pmFieldPickTarget.dispatchEvent(new Event('input', { bubbles: true }));
    pmCloseFieldPicker();
  });

  // Hero image live thumbnail — mirrors the hero URL input (typed OR picked).
  (function(){
    const hero = document.getElementById('edit-art-hero');
    const thumb = document.getElementById('edit-art-hero-thumb');
    if (!hero || !thumb) return;
    window.pmUpdateHeroThumb = function(){
      const v = (hero.value || '').trim();
      if (v) {
        thumb.style.backgroundImage = 'url("' + v.replace(/"/g, '%22') + '")';
        thumb.classList.add('has-img');
        thumb.innerHTML = '';
      } else {
        thumb.style.backgroundImage = '';
        thumb.classList.remove('has-img');
        thumb.innerHTML = '<span class="pm-art-hero-empty">No image</span>';
      }
    };
    hero.addEventListener('input', window.pmUpdateHeroThumb);
    window.pmUpdateHeroThumb();
  })();

  // ---- Card click handlers ----
  document.addEventListener('click', e=>{
    // Edit button or card click
    const editBtn = e.target.closest('.js-card-edit');
    const card = e.target.closest('.pm-preview-card');

    if(editBtn){
      e.stopPropagation();
      openEditModal(editBtn.dataset.page);
      return;
    }

    // HTML Edit button — open in HTMLEditor
    const htmlBtn = e.target.closest('.js-card-html-edit');
    if(htmlBtn){
      e.stopPropagation();
      window.location.href = '/admin/modules/HTMLEditor/HTMLEditor.php?page=' + encodeURIComponent(htmlBtn.dataset.page);
      return;
    }

    // Delete button on card
    const delBtn = e.target.closest('.js-card-delete');
    if(delBtn){
      e.stopPropagation();
      const slug = delBtn.dataset.page;
      const title = delBtn.dataset.title || slug;
      if(!confirm('Delete page "' + title + '"? This will move it to trash.')) return;
      const fd = new FormData();
      fd.append('__pm_action', 'delete_page');
      fd.append('slug', slug);
      fetch('', { method: 'POST', body: fd }).then(r=>r.json()).then(result=>{
        if(result.ok){
          showToast('Page "' + title + '" deleted');
          const c = delBtn.closest('.pm-preview-card');
          if(c) c.remove();
        } else {
          showToast('Delete failed: ' + (result.error||'unknown'), true);
        }
      }).catch(err=> showToast('Delete failed: ' + err.message, true));
      return;
    }

    // Preview button
    const prevBtn = e.target.closest('.js-card-preview');
    if(prevBtn){
      e.stopPropagation();
      if(card && card.dataset.type === 'aip'){
        window.open('/admin/data/AIP_Pages/' + encodeURIComponent(prevBtn.dataset.page) + '/index.html', '_blank', 'width=1100,height=800');
      } else {
        openEditModal(prevBtn.dataset.page);
      }
      return;
    }

    // Set as Home icon on card
    const setHomeBtn = e.target.closest('.js-card-set-home');
    if(setHomeBtn){
      e.stopPropagation();
      setHomePage(setHomeBtn.dataset.page);
      return;
    }

    // Add to Menu icon on card
    const addMenuBtn = e.target.closest('.js-card-add-menu');
    if(addMenuBtn){
      e.stopPropagation();
      addToMenu(addMenuBtn.dataset.page, addMenuBtn.dataset.title || addMenuBtn.dataset.page);
      return;
    }

    // Convert Page ⇄ Article — toggles ArticlesManager membership only; page content untouched.
    const convBtn = e.target.closest('.js-card-convert-type');
    if(convBtn){
      e.stopPropagation();
      const slug = convBtn.dataset.slug;
      const title = convBtn.dataset.title || slug;
      const to = convBtn.dataset.current === 'article' ? 'page' : 'article';
      const msg = to === 'article'
        ? 'Convert "' + title + '" to an ARTICLE?\n\nIt will be managed in Articles Manager and hidden from the Pages list by default. The page content is untouched.'
        : 'Convert "' + title + '" back to a PAGE?\n\nIt will be removed from Articles Manager (page content untouched).';
      if(!confirm(msg)) return;
      const fd = new FormData();
      fd.append('__toggle_article_type', '1');
      fd.append('slug', slug);
      fd.append('to', to);
      fetch('', { method: 'POST', body: fd }).then(r=>r.json()).then(result=>{
        if(result.ok){
          showToast('"' + title + '" is now ' + (result.type === 'article' ? 'an Article' : 'a Page'));
          setTimeout(()=> location.reload(), 550);
        } else {
          showToast('Convert failed: ' + (result.error||'unknown'), true);
        }
      }).catch(err=> showToast('Convert failed: ' + err.message, true));
      return;
    }

    // Card body click (not on action buttons) — skip for AIP cards
    if(card && !e.target.closest('.pm-preview-card-actions')){
      if(card.dataset.type === 'aip'){
        window.open('/admin/data/AIP_Pages/' + encodeURIComponent(card.dataset.page) + '/index.html', '_blank', 'width=1100,height=800');
      } else {
        openEditModal(card.dataset.page);
      }
    }
  });

  // ---- NEW PAGE MODAL ----
  function openNewModal(){
    newLeftContent = '';
    newRightContent = '';
    newCssContent = '';
    newJsContent = '';
    newEditingColumn = null;
    newTitle.value = '';
    newLeftPct.value = 65;
    syncNewSlider();
    newRightEn.checked = true;
    if (newLumText) newLumText.checked = true;
    syncNewRightBtn();
    newEditorArea.classList.remove('open');
    newTextarea.value = '';
    newOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(()=> newTitle.focus(), 100);
  }

  function closeNewModal(){
    if(newEditingColumn === 'left') newLeftContent = newTextarea.value;
    if(newEditingColumn === 'right') newRightContent = newTextarea.value;
    if(newEditingColumn === 'css') newCssContent = newTextarea.value;
    newEditingColumn = null;
    newOverlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  function openNewEditor(col){
    if(newEditingColumn === 'left') newLeftContent = newTextarea.value;
    if(newEditingColumn === 'right') newRightContent = newTextarea.value;
    if(newEditingColumn === 'css') newCssContent = newTextarea.value;
    newEditingColumn = col;
    if(col === 'css') {
      newTextarea.value = newCssContent;
      newTextarea.placeholder = 'Page CSS — custom styles for this page...';
    } else {
      newTextarea.value = col === 'left' ? newLeftContent : newRightContent;
      newTextarea.placeholder = col === 'left' ? 'Left Column — shortcodes, HTML...' : 'Right Column — shortcodes, HTML...';
    }
    // Hide sidebar when editing CSS
    document.getElementById('new-sidebar').style.display = col === 'css' ? 'none' : '';
    newEditorArea.classList.add('open');
    newBtnLeft.classList.toggle('active', col === 'left');
    newBtnRight.classList.toggle('active', col === 'right');
    newBtnCss.classList.toggle('active', col === 'css');
    setTimeout(()=> newTextarea.focus(), 100);
  }

  async function createNewPage(){
    if(newEditingColumn === 'left') newLeftContent = newTextarea.value;
    if(newEditingColumn === 'right') newRightContent = newTextarea.value;
    if(newEditingColumn === 'css') newCssContent = newTextarea.value;

    const title = newTitle.value.trim();
    if(!title){ showToast('Please enter a page title', true); newTitle.focus(); return; }

    const fd = new FormData();
    fd.append('__save', '1');
    fd.append('page_name', '');
    fd.append('page_title', title);
    fd.append('left_width', newLeftPct.value);
    if(newRightEn.checked) fd.append('right_column_enabled', '1');
    if (newLumText && !newLumText.checked) fd.append('lum_text_off', '1');
    fd.append('components[main_content][content]', newLeftContent);
    fd.append('components[right_column][content]', newRightContent);
    fd.append('page_css', newCssContent);
    fd.append('page_js', newJsContent);

    try {
      const res = await fetch('', { method: 'POST', body: fd });
      const result = await res.json();
      if(result.ok){
        showToast('Page created!');
        closeNewModal();
        // Reload to show new card
        location.reload();
      } else {
        throw new Error(result.error || 'Unknown error');
      }
    } catch(e){
      showToast('Create failed: ' + e.message, true);
    }
  }

  document.getElementById('btn-new-page').addEventListener('click', openNewModal);
  newBtnClose.addEventListener('click', closeNewModal);
  newBtnCreate.addEventListener('click', createNewPage);
  newBtnLeft.addEventListener('click', ()=> openNewEditor('left'));
  newBtnRight.addEventListener('click', ()=>{
    if(!newRightEn.checked) return;
    openNewEditor('right');
  });
  newBtnCss.addEventListener('click', ()=> openNewEditor('css'));
  newOverlay.addEventListener('click', e=>{
    if(e.target === newOverlay) closeNewModal();
  });

  // ---- CSS Variable Parser / Style Pane Engine ----

  // Parse :root { --var: value; } block from CSS string
  function parseCssRoot(css) {
    const vars = [];
    const rootMatch = css.match(/:root\s*\{([^}]*)\}/s);
    if (!rootMatch) return vars;
    const block = rootMatch[1];
    const re = /--([\w-]+)\s*:\s*([^;]+)/g;
    let m;
    while ((m = re.exec(block)) !== null) {
      vars.push({ name: '--' + m[1], value: m[2].trim() });
    }
    return vars;
  }

  // Detect value type for rendering the right control
  function detectValueType(value) {
    if (/^#[0-9a-fA-F]{3,8}$/.test(value)) return 'color';
    if (/^(rgb|hsl)a?\(/.test(value)) return 'color-func';
    if (/^linear-gradient|^radial-gradient|^conic-gradient/.test(value)) return 'gradient';
    if (/^-?\d+(\.\d+)?\s*(px|rem|em|%|vw|vh|pt|ch|ex|vmin|vmax)$/.test(value)) return 'size';
    if (/^-?\d+(\.\d+)?$/.test(value)) return 'number';
    return 'text';
  }

  // Classify variable by name patterns into groups
  function classifyVariable(name) {
    const n = name.toLowerCase();
    if (/color|bg|background|border|shadow|text|bright|muted|danger|cloud|purple|blue|cyan|gradient/.test(n)) return 'colors';
    if (/gap|margin|padding|radius|width|height|size|space|offset/.test(n)) return 'spacing';
    if (/font|type|letter|line-height|weight/.test(n)) return 'typography';
    return 'other';
  }

  const groupLabels = {
    colors: { label: 'Colors & Gradients', icon: 'fa-droplet' },
    spacing: { label: 'Sizes & Spacing', icon: 'fa-ruler-combined' },
    typography: { label: 'Typography', icon: 'fa-font' },
    other: { label: 'Other', icon: 'fa-sliders' }
  };

  // Update a single variable value in the CSS string
  function updateCssVariable(css, varName, newValue) {
    const rootMatch = css.match(/([\s\S]*?:root\s*\{)([\s\S]*?)(\}[\s\S]*)/);
    if (!rootMatch) return css;
    const before = rootMatch[1];
    let block = rootMatch[2];
    const after = rootMatch[3];
    const escaped = varName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp('(' + escaped + '\\s*:\\s*)([^;]+)');
    block = block.replace(re, '$1' + newValue);
    return before + block + after;
  }

  // Add a new variable to the :root block (or create :root if none)
  function addCssVariable(css, varName, value) {
    const rootMatch = css.match(/([\s\S]*?:root\s*\{)([\s\S]*?)(\}[\s\S]*)/);
    if (rootMatch) {
      const before = rootMatch[1];
      let block = rootMatch[2];
      const after = rootMatch[3];
      block = block.trimEnd() + '\n    ' + varName + ': ' + value + ';\n';
      return before + block + after;
    }
    return ':root {\n    ' + varName + ': ' + value + ';\n}\n\n' + css;
  }

  // Remove a variable from the :root block
  function removeCssVariable(css, varName) {
    const escaped = varName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp('\\s*' + escaped + '\\s*:[^;]*;', 'g');
    return css.replace(re, '');
  }

  // Try to convert a color value to hex for the picker
  function colorToHex(value) {
    if (/^#[0-9a-fA-F]{6}$/.test(value)) return value;
    if (/^#[0-9a-fA-F]{3}$/.test(value)) {
      return '#' + value[1]+value[1] + value[2]+value[2] + value[3]+value[3];
    }
    // For rgba/hsla, try canvas conversion
    try {
      const ctx = document.createElement('canvas').getContext('2d');
      ctx.fillStyle = value;
      const computed = ctx.fillStyle;
      if (computed.startsWith('#')) return computed;
    } catch(e){}
    return '#888888';
  }

  // Parse size value into number + unit
  function parseSize(value) {
    const m = value.match(/^(-?\d+(?:\.\d+)?)\s*(px|rem|em|%|vw|vh|pt|ch|ex|vmin|vmax)?$/);
    if (m) return { num: m[1], unit: m[2] || 'px' };
    return { num: value, unit: '' };
  }

  // Build a control row for a variable
  function buildControl(v) {
    const type = detectValueType(v.value);
    const row = document.createElement('div');
    row.className = 'pm-style-row';
    row.dataset.varName = v.name;

    const label = document.createElement('span');
    label.className = 'pm-style-label';
    label.textContent = v.name;
    label.title = v.name;
    row.appendChild(label);

    const ctrl = document.createElement('div');
    ctrl.className = 'pm-style-control';

    if (type === 'color') {
      const wrap = document.createElement('div');
      wrap.className = 'pm-style-color-wrap';
      const picker = document.createElement('input');
      picker.type = 'color';
      picker.className = 'pm-style-color-input';
      picker.value = colorToHex(v.value);
      const hex = document.createElement('input');
      hex.type = 'text';
      hex.className = 'pm-style-hex-input';
      hex.value = v.value;
      picker.addEventListener('input', ()=> {
        hex.value = picker.value;
        cssContent = updateCssVariable(cssContent, v.name, picker.value);
        styleRawCss.value = cssContent;
      });
      hex.addEventListener('change', ()=> {
        if (/^#[0-9a-fA-F]{3,8}$/.test(hex.value)) picker.value = colorToHex(hex.value);
        cssContent = updateCssVariable(cssContent, v.name, hex.value);
        styleRawCss.value = cssContent;
      });
      wrap.appendChild(picker);
      wrap.appendChild(hex);
      ctrl.appendChild(wrap);
    } else if (type === 'size') {
      const s = parseSize(v.value);
      const wrap = document.createElement('div');
      wrap.className = 'pm-style-size-wrap';
      const num = document.createElement('input');
      num.type = 'number';
      num.className = 'pm-style-size-num';
      num.value = s.num;
      num.step = 'any';
      const unit = document.createElement('select');
      unit.className = 'pm-style-size-unit';
      ['px','rem','em','%','vw','vh','pt','ch'].forEach(u => {
        const o = document.createElement('option');
        o.value = u; o.textContent = u;
        if (u === s.unit) o.selected = true;
        unit.appendChild(o);
      });
      const update = ()=> {
        cssContent = updateCssVariable(cssContent, v.name, num.value + unit.value);
        styleRawCss.value = cssContent;
      };
      num.addEventListener('input', update);
      unit.addEventListener('change', update);
      wrap.appendChild(num);
      wrap.appendChild(unit);
      ctrl.appendChild(wrap);
    } else if (isFontVariable(v.name, v.value)) {
      // Font-family variable → the FontManager-backed font picker (v2)
      ctrl.appendChild(buildFontSelect(v.value, function (val) {
        cssContent = updateCssVariable(cssContent, v.name, val);
        styleRawCss.value = cssContent;
      }));
    } else if (isShadowVariable(v.name, v.value)) {
      // Shadow variable → the visual shadow builder (v2 — closes the :root gap)
      ctrl.appendChild(buildShadowControl(
        { prop: /text/i.test(v.name) ? 'text-shadow' : 'box-shadow', value: v.value },
        function (val) {
          cssContent = updateCssVariable(cssContent, v.name, val);
          styleRawCss.value = cssContent;
        }
      ));
    } else {
      const inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'pm-style-text-input';
      inp.value = v.value;
      inp.addEventListener('change', ()=> {
        cssContent = updateCssVariable(cssContent, v.name, inp.value);
        styleRawCss.value = cssContent;
      });
      ctrl.appendChild(inp);
    }

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'pm-style-delete-btn';
    del.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    del.title = 'Remove variable';
    del.addEventListener('click', ()=> {
      cssContent = removeCssVariable(cssContent, v.name);
      renderStylePane();
    });
    ctrl.appendChild(del);

    row.appendChild(ctrl);
    return row;
  }

  /* ================= Style Controls — tune ARBITRARY selectors (not just :root vars) ==============
   * "Styles detected you can manage here." Curated registry of adjustable properties → control kind.
   * Surgical write-back (replace ONE value in ONE selector block) — never a full CSS re-serialize,
   * so hand-written CSS / comments / unknown rules are preserved. */
  const PM_ADJUSTABLE = {
    'color':'color','background-color':'color','background':'color','border-color':'color',
    'font-family':'font',
    'font-size':'size','line-height':'size','letter-spacing':'size','border-radius':'size',
    'padding':'size','margin':'size','opacity':'number',
    'box-shadow':'shadow','text-shadow':'shadow'
  };

  // Site fonts from FontManager (custom @font-face families + /media/fonts files).
  // Fetched once, cached; any font <select> already rendered gets back-filled when it lands.
  let PM_SITE_FONTS = null;            // [{family, sources}] once loaded
  const PM_FONT_SELECT_QUEUE = [];     // selects awaiting the font list
  function loadSiteFonts() {
    if (PM_SITE_FONTS !== null) return;
    PM_SITE_FONTS = [];                // guard against duplicate fetches
    fetch('/admin/modules/FontManager/api/list-fonts.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (list) {
        PM_SITE_FONTS = Array.isArray(list) ? list : [];
        PM_FONT_SELECT_QUEUE.splice(0).forEach(function (sel) { injectSiteFonts(sel); });
      })
      .catch(function () { PM_SITE_FONTS = []; });
  }
  // Add a "Site fonts (FontManager)" optgroup to a font <select>, once, at the top.
  function injectSiteFonts(sel) {
    if (!sel || sel.dataset.siteFonts === '1') return;
    if (PM_SITE_FONTS === null || !PM_SITE_FONTS.length) return;
    sel.dataset.siteFonts = '1';
    const og = document.createElement('optgroup'); og.label = 'Site fonts (FontManager)';
    PM_SITE_FONTS.forEach(function (f) {
      if (!f || !f.family) return;
      const stack = '"' + f.family + '", system-ui, sans-serif';
      const o = document.createElement('option');
      o.value = stack; o.textContent = f.family;
      o.style.fontFamily = stack;                 // preview in the dropdown
      if (sel.value === stack || sel.value === f.family) o.selected = true;
      og.appendChild(o);
    });
    sel.insertBefore(og, sel.firstChild);
  }

  // Parse top-level selector blocks (skip :root and @-rules). → [{selector,[{prop,value}]}]
  function parseCssSelectors(css) {
    const out = [];
    const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const re = /([^{}]+)\{([^{}]*)\}/g; let m;
    while ((m = re.exec(clean)) !== null) {
      const sel = m[1].trim();
      if (!sel || sel === ':root' || sel.charAt(0) === '@') continue;
      const decls = [];
      m[2].split(';').forEach(d => {
        const i = d.indexOf(':'); if (i < 0) return;
        const prop = d.slice(0, i).trim().toLowerCase();
        const value = d.slice(i + 1).trim();
        if (prop && value && PM_ADJUSTABLE[prop]) decls.push({ prop: prop, value: value });
      });
      if (decls.length) out.push({ selector: sel, decls: decls });
    }
    return out;
  }

  // Surgically replace ONE property's value inside ONE selector block.
  function updateCssDeclaration(css, selector, prop, newValue) {
    const selEsc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const blockRe = new RegExp('(' + selEsc + '\\s*\\{)([^{}]*)(\\})');
    return css.replace(blockRe, function (_, before, body, after) {
      const propEsc = prop.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const declRe = new RegExp('(^|;|\\s)(' + propEsc + '\\s*:\\s*)([^;]+)');
      const nb = body.replace(declRe, '$1$2' + newValue);
      return before + nb + after;
    });
  }

  const PM_FONT_STACKS = ['inherit','Arial, sans-serif','"Arial Narrow", sans-serif','Georgia, serif',
    '"Times New Roman", serif','"Courier New", monospace','Verdana, sans-serif','Tahoma, sans-serif',
    '"Trebuchet MS", sans-serif','Impact, sans-serif','system-ui, sans-serif'];

  // ---- Shadow builder (box-shadow / text-shadow) ----
  // Parse a SINGLE shadow layer: [inset] off-x off-y [blur] [spread] color.
  function parseShadow(val) {
    let s = String(val || '').trim();
    const inset = /(^|\s)inset(\s|$)/i.test(s);
    s = s.replace(/(^|\s)inset(\s|$)/ig, ' ').trim();
    let color = '';
    const cm = s.match(/(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))/);
    if (cm) { color = cm[0]; s = (s.slice(0, cm.index) + ' ' + s.slice(cm.index + cm[0].length)).trim(); }
    const px = function (t) { return t == null ? 0 : parseFloat(t) || 0; };
    const n = s.split(/\s+/).filter(Boolean);
    return { inset: inset, x: px(n[0]), y: px(n[1]), blur: px(n[2]), spread: px(n[3]), color: color || '#000000' };
  }
  function composeShadow(p, hasSpread) {
    const parts = [];
    if (p.inset) parts.push('inset');
    parts.push(p.x + 'px', p.y + 'px', p.blur + 'px');
    if (hasSpread) parts.push(p.spread + 'px');
    parts.push(p.color);
    return parts.join(' ');
  }
  // Shared font <select>: web-safe stacks + the site's FontManager families,
  // used by both :root font variables and detected font-family declarations.
  function buildFontSelect(currentValue, onChange) {
    const sel = document.createElement('select'); sel.className = 'pm-style-font-select';
    const og = document.createElement('optgroup'); og.label = 'Web-safe';
    let found = false;
    PM_FONT_STACKS.forEach(function (f) { const o = document.createElement('option'); o.value = f; o.textContent = f.split(',')[0].replace(/["']/g, ''); if (f === currentValue) { o.selected = true; found = true; } og.appendChild(o); });
    sel.appendChild(og);
    if (!found && currentValue) { const o = document.createElement('option'); o.value = currentValue; o.textContent = currentValue.split(',')[0].replace(/["']/g, ''); o.selected = true; sel.insertBefore(o, sel.firstChild); }
    sel.addEventListener('change', function () { onChange(sel.value); });
    if (PM_SITE_FONTS === null) { PM_FONT_SELECT_QUEUE.push(sel); loadSiteFonts(); }
    else injectSiteFonts(sel);
    return sel;
  }
  // Does this :root variable hold a font-family (vs a size/weight)?
  function isFontVariable(name, val) {
    if (/font/i.test(name) && !/(size|weight|height|spacing|line|style)/i.test(name)) return true;
    return /\b(serif|sans-serif|monospace|cursive|system-ui|ui-sans-serif|ui-monospace)\b/i.test(String(val || ''));
  }
  // Does this :root variable hold a shadow (box/text-shadow)? Requires two px offsets so
  // multi-length shorthands (radius/margin) aren't mistaken; then a colour or a shadow-named var.
  // Skips var()/gradient/url values (can't safely round-trip through the builder).
  function isShadowVariable(name, val) {
    var s = String(val || '');
    if (/url\(|gradient|var\(/i.test(s)) return false;
    if (!/(^|\s)(inset\s+)?-?\d[\d.]*(px)?\s+-?\d[\d.]*(px)?/.test(s)) return false;
    return /#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(/.test(s) || /shadow/i.test(name);
  }

  function buildShadowControl(decl, writeBack) {
    const hasSpread = decl.prop === 'box-shadow';
    // Multi-layer shadows (comma-separated) → don't risk data loss; plain text input.
    if (String(decl.value).indexOf(',') >= 0) {
      const inp = document.createElement('input'); inp.type = 'text'; inp.className = 'pm-style-text-input'; inp.value = decl.value;
      inp.title = 'Multiple shadow layers — edit as text'; inp.addEventListener('change', function () { writeBack(inp.value); });
      return inp;
    }
    const s = parseShadow(decl.value);
    const wrap = document.createElement('div'); wrap.className = 'pm-style-shadow';
    const emit = function () { writeBack(composeShadow(s, hasSpread)); };
    const numField = function (key, lbl) {
      const f = document.createElement('label'); f.className = 'pm-shadow-f';
      const cap = document.createElement('span'); cap.textContent = lbl;
      const i = document.createElement('input'); i.type = 'number'; i.step = '1'; i.value = s[key];
      i.addEventListener('input', function () { s[key] = parseFloat(i.value) || 0; emit(); });
      f.appendChild(cap); f.appendChild(i); return f;
    };
    wrap.appendChild(numField('x', 'X'));
    wrap.appendChild(numField('y', 'Y'));
    wrap.appendChild(numField('blur', 'Blur'));
    if (hasSpread) wrap.appendChild(numField('spread', 'Spread'));
    // colour
    const cf = document.createElement('label'); cf.className = 'pm-shadow-f pm-shadow-color';
    const ccap = document.createElement('span'); ccap.textContent = 'Color';
    const cp = document.createElement('input'); cp.type = 'color'; cp.value = colorToHex(s.color);
    cp.addEventListener('input', function () { s.color = cp.value; emit(); });
    cf.appendChild(ccap); cf.appendChild(cp); wrap.appendChild(cf);
    // inset (box-shadow only)
    if (hasSpread) {
      const inf = document.createElement('label'); inf.className = 'pm-shadow-f pm-shadow-inset';
      const chk = document.createElement('input'); chk.type = 'checkbox'; chk.checked = s.inset;
      const icap = document.createElement('span'); icap.textContent = 'Inset';
      chk.addEventListener('change', function () { s.inset = chk.checked; emit(); });
      inf.appendChild(chk); inf.appendChild(icap); wrap.appendChild(inf);
    }
    return wrap;
  }

  // Build a control row for a selector declaration (color picker / font select / size / number / text).
  function buildDeclControl(selector, decl) {
    const kind = PM_ADJUSTABLE[decl.prop] || 'text';
    const row = document.createElement('div'); row.className = 'pm-style-row';
    const label = document.createElement('span'); label.className = 'pm-style-label';
    label.textContent = decl.prop; label.title = selector + ' { ' + decl.prop + ' }';
    row.appendChild(label);
    const ctrl = document.createElement('div'); ctrl.className = 'pm-style-control';
    const writeBack = function (val) {
      cssContent = updateCssDeclaration(cssContent, selector, decl.prop, val);
      styleRawCss.value = cssContent; decl.value = val;
    };
    if (kind === 'color') {
      const wrap = document.createElement('div'); wrap.className = 'pm-style-color-wrap';
      const picker = document.createElement('input'); picker.type = 'color'; picker.className = 'pm-style-color-input'; picker.value = colorToHex(decl.value);
      const hex = document.createElement('input'); hex.type = 'text'; hex.className = 'pm-style-hex-input'; hex.value = decl.value;
      picker.addEventListener('input', function () { hex.value = picker.value; writeBack(picker.value); });
      hex.addEventListener('change', function () { if (/^#[0-9a-fA-F]{3,8}$/.test(hex.value)) picker.value = colorToHex(hex.value); writeBack(hex.value); });
      wrap.appendChild(picker); wrap.appendChild(hex); ctrl.appendChild(wrap);
    } else if (kind === 'font') {
      ctrl.appendChild(buildFontSelect(decl.value, writeBack));
    } else if (kind === 'shadow') {
      ctrl.appendChild(buildShadowControl(decl, writeBack));
    } else if (kind === 'size') {
      const s = parseSize(decl.value);
      const wrap = document.createElement('div'); wrap.className = 'pm-style-size-wrap';
      const num = document.createElement('input'); num.type = 'number'; num.className = 'pm-style-size-num'; num.value = s.num; num.step = 'any';
      const unit = document.createElement('select'); unit.className = 'pm-style-size-unit';
      ['px','rem','em','%','vw','vh','pt','ch'].forEach(function (u) { const o = document.createElement('option'); o.value = u; o.textContent = u; if (u === s.unit) o.selected = true; unit.appendChild(o); });
      const upd = function () { writeBack(num.value + unit.value); };
      num.addEventListener('input', upd); unit.addEventListener('change', upd);
      wrap.appendChild(num); wrap.appendChild(unit); ctrl.appendChild(wrap);
    } else if (kind === 'number') {
      const num = document.createElement('input'); num.type = 'number'; num.className = 'pm-style-size-num'; num.value = decl.value; num.step = '0.05'; num.min = '0'; num.max = '1';
      num.addEventListener('input', function () { writeBack(num.value); });
      ctrl.appendChild(num);
    } else {
      const inp = document.createElement('input'); inp.type = 'text'; inp.className = 'pm-style-text-input'; inp.value = decl.value;
      inp.addEventListener('change', function () { writeBack(inp.value); });
      ctrl.appendChild(inp);
    }
    row.appendChild(ctrl);
    return row;
  }

  // Shorten a (possibly multi-part) selector for a compact row label.
  function shortSel(sel) {
    var s = String(sel).replace(/\s*,\s*/g, ', ').replace(/\s+/g, ' ').trim();
    return s.length > 42 ? s.slice(0, 40) + '…' : s;
  }

  // Curated cross-selector group: pull every decl of one control-kind (e.g. 'font' / 'shadow')
  // out of ALL selectors into one labelled group, so high-value controls are discoverable
  // instead of buried in the full per-selector "Detected Styles" dump.
  function renderCuratedGroup(container, sels, kind, icon, labelText) {
    const rows = [];
    (sels || []).forEach(function (item) {
      item.decls.forEach(function (decl) {
        if ((PM_ADJUSTABLE[decl.prop] || 'text') === kind) rows.push({ selector: item.selector, decl: decl });
      });
    });
    if (!rows.length) return;
    const hd = document.createElement('div'); hd.className = 'pm-style-group-header';
    hd.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + labelText + ' <span style="color:#475569;font-weight:400">(' + rows.length + ')</span>';
    container.appendChild(hd);
    const grp = document.createElement('div'); grp.className = 'pm-style-group pm-style-curated';
    rows.forEach(function (r) {
      const row = buildDeclControl(r.selector, r.decl);
      // Group header already names the kind → show the SELECTOR on the row label instead of the prop.
      const lbl = row.querySelector('.pm-style-label');
      if (lbl) { lbl.textContent = shortSel(r.selector); lbl.title = r.selector + ' { ' + r.decl.prop + ' }'; }
      grp.appendChild(row);
    });
    container.appendChild(grp);
  }

  // Render the "Detected Styles" section (one group per selector) into the panel.
  // excludeKinds = control-kinds already surfaced in curated groups (skip to avoid dupes).
  function renderStyleSelectors(container, sels, excludeKinds) {
    excludeKinds = excludeKinds || [];
    const filtered = (sels || []).map(function (item) {
      return { selector: item.selector, decls: item.decls.filter(function (d) { return excludeKinds.indexOf(PM_ADJUSTABLE[d.prop] || 'text') < 0; }) };
    }).filter(function (item) { return item.decls.length; });
    if (!filtered.length) return;
    const hd = document.createElement('div'); hd.className = 'pm-style-group-header';
    hd.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Detected Styles <span style="color:#475569;font-weight:400">(' + filtered.length + ')</span>';
    container.appendChild(hd);
    filtered.forEach(function (item) {
      const grp = document.createElement('div'); grp.className = 'pm-style-group pm-style-selgroup';
      const sh = document.createElement('div'); sh.className = 'pm-style-sel-header'; sh.textContent = item.selector; sh.title = item.selector;
      grp.appendChild(sh);
      item.decls.forEach(function (decl) { grp.appendChild(buildDeclControl(item.selector, decl)); });
      container.appendChild(grp);
    });
  }

  // Render the full style pane from current cssContent (populates textareas + right column)
  function renderStylePane() {
    // Populate raw textareas
    styleRawCss.value = cssContent;
    styleRawJs.value = jsContent;
    // Render right column variables
    renderStyleVariables();
  }

  // ---- Add Variable ----
  let addFormVisible = false;
  document.getElementById('style-add-var').addEventListener('click', ()=> {
    if (addFormVisible) return;
    addFormVisible = true;
    const container = document.getElementById('style-variables-container');
    const form = document.createElement('div');
    form.className = 'pm-style-add-form';
    form.innerHTML = '<input type="text" class="pm-style-add-name" placeholder="--my-variable" spellcheck="false">' +
      '<input type="text" class="pm-style-add-value" placeholder="value (e.g. #ff0000, 16px)" spellcheck="false">' +
      '<button type="button" class="pm-style-add-confirm"><i class="fa-solid fa-check"></i></button>' +
      '<button type="button" class="pm-style-add-cancel"><i class="fa-solid fa-xmark"></i></button>';
    container.appendChild(form);
    const nameInput = form.querySelector('.pm-style-add-name');
    const valueInput = form.querySelector('.pm-style-add-value');
    nameInput.focus();
    form.querySelector('.pm-style-add-confirm').addEventListener('click', ()=> {
      let name = nameInput.value.trim();
      const val = valueInput.value.trim();
      if (!name || !val) return;
      if (!name.startsWith('--')) name = '--' + name;
      cssContent = addCssVariable(cssContent, name, val);
      form.remove();
      addFormVisible = false;
      renderStylePane();
    });
    form.querySelector('.pm-style-add-cancel').addEventListener('click', ()=> {
      form.remove();
      addFormVisible = false;
    });
    // Enter to confirm
    form.addEventListener('keydown', e => {
      if (e.key === 'Enter') form.querySelector('.pm-style-add-confirm').click();
      if (e.key === 'Escape') form.querySelector('.pm-style-add-cancel').click();
    });
  });

  // ---- Inline Raw CSS/JS Editor (2-column left pane) ----
  const styleRawCss = document.getElementById('style-raw-css');
  const styleRawJs = document.getElementById('style-raw-js');
  let styleActiveTab = 'css';

  // Render only the right-column variables (without touching textareas to avoid cursor jump)
  function renderStyleVariables() {
    const container = document.getElementById('style-variables-container');
    container.innerHTML = '';
    const vars = parseCssRoot(cssContent);
    const sels = parseCssSelectors(cssContent);
    if (vars.length === 0 && sels.length === 0) {
      container.innerHTML = '<div class="pm-style-empty"><i class="fa-solid fa-palette"></i>No styles detected yet<br><small>Add CSS on the left (variables or selectors) and controls appear here.</small></div>';
      return;
    }
    // CSS variables (existing :root controls)
    const groups = { colors: [], spacing: [], typography: [], other: [] };
    vars.forEach(v => { groups[classifyVariable(v.name)].push(v); });
    for (const [key, items] of Object.entries(groups)) {
      if (items.length === 0) continue;
      const group = document.createElement('div');
      group.className = 'pm-style-group';
      const header = document.createElement('div');
      header.className = 'pm-style-group-header';
      header.innerHTML = '<i class="fa-solid ' + groupLabels[key].icon + '"></i> ' + groupLabels[key].label + ' <span style="color:#475569;font-weight:400">(' + items.length + ')</span>';
      group.appendChild(header);
      items.forEach(v => group.appendChild(buildControl(v)));
      container.appendChild(group);
    }
    // Curated cross-selector groups — surface fonts + shadows so they're discoverable
    // instead of buried among the full per-selector list.
    renderCuratedGroup(container, sels, 'font', 'fa-font', 'Fonts');
    renderCuratedGroup(container, sels, 'shadow', 'fa-wand-magic-sparkles', 'Shadows &amp; Effects');
    // Detected selector styles (colors / sizes) — font & shadow surfaced above, so excluded here.
    renderStyleSelectors(container, sels, ['font', 'shadow']);
  }

  // Debounced textarea input → update state + re-render right column
  let styleDebounce = null;
  styleRawCss.addEventListener('input', () => {
    cssContent = styleRawCss.value;
    clearTimeout(styleDebounce);
    styleDebounce = setTimeout(() => renderStyleVariables(), 300);
  });
  styleRawJs.addEventListener('input', () => {
    jsContent = styleRawJs.value;
  });

  // Tab switching
  editStylePane.querySelectorAll('.pm-style-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      styleActiveTab = tab.dataset.stab;
      editStylePane.querySelectorAll('.pm-style-tab').forEach(t => t.classList.toggle('active', t.dataset.stab === styleActiveTab));
      styleRawCss.classList.toggle('hidden', styleActiveTab !== 'css');
      styleRawJs.classList.toggle('hidden', styleActiveTab !== 'js');
    });
  });

  // ---- Column HTML Toolbar (WYSIWYG for left/right columns) ----

  // Prevent mousedown on toolbar from stealing focus/selection from editor
  pmHtmlToolbar.addEventListener('mousedown', e => {
    const el = e.target;
    // Allow interaction with SELECT elements (dropdowns need focus)
    if (el.tagName === 'SELECT' || el.closest('select')) return;
    e.preventDefault();
  });

  // Helper: insert text at cursor position in a textarea
  function pmInsertAtCursor(textarea, before, after) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selected = textarea.value.substring(start, end);
    const replacement = before + selected + (after || '');
    textarea.setRangeText(replacement, start, end, 'end');
    textarea.focus();
    scheduleAutosave();
  }

  // Map execCommand names to HTML tag pairs for source mode
  const sourceTagMap = {
    'bold':                { before: '<strong>', after: '</strong>' },
    'italic':              { before: '<em>', after: '</em>' },
    'underline':           { before: '<u>', after: '</u>' },
    'insertUnorderedList': { before: '<ul>\n<li>', after: '</li>\n</ul>' },
    'insertOrderedList':   { before: '<ol>\n<li>', after: '</li>\n</ol>' },
    'removeFormat':        null // no-op in source mode
  };

  // Toolbar button clicks
  pmHtmlToolbar.addEventListener('click', e => {
    const btn = e.target.closest('[data-cmd]');
    if (btn && btn.tagName !== 'SELECT') {
      e.preventDefault();
      const cmd = btn.dataset.cmd;
      if (pmSourceMode) {
        // Source mode: insert HTML tags around selection in textarea
        const mapping = sourceTagMap[cmd];
        if (mapping) {
          pmInsertAtCursor(editTextarea, mapping.before, mapping.after);
        }
      } else {
        // Visual mode: forward to edit-frame iframe (document.execCommand in
        // parent doc has no target now that editing lives in an iframe).
        pmPostToFrame({ type: 'pm-exec', cmd: cmd, arg: null });
      }
      return;
    }
    const action = e.target.closest('[data-action]');
    if (action) {
      e.preventDefault();
      if (action.dataset.action === 'pm-source-toggle') pmToggleSource();
      if (action.dataset.action === 'pm-link') pmInsertLink();
      if (action.dataset.action === 'pm-pretty') pmPrettyFormat();
      if (action.dataset.action === 'pm-minify') pmMinifyHtml();
      if (action.dataset.action === 'pm-convert-block') pmConvertToBlock();
      if (action.dataset.action === 'pm-edit-shortcode') pmEditShortcodeAtCursor();
      if (action.dataset.action === 'pm-unwind-shortcode') pmUnwindShortcodeAtCursor();
    }
  });

  // Closing/going-back from a nested block edit returns to the page you came from.
  function pmExitBlock(){
    const rt = pmReturnTo; pmReturnTo = null; editingBlockSlug = '';
    editOverlay.setAttribute('data-edit-type', rt ? rt.type : 'page');   // ALWAYS clear block state
    pmSetBlockChrome(false);
    if (rt) { if (rt.type === 'article') openEditArticleModal(rt.slug); else openEditModal(rt.slug); }
    else { closeEditModal(); }
  }
  function pmMaybeReturnFromBlock(e){
    if (editOverlay.getAttribute('data-edit-type') !== 'block') return;   // not in a block → default handler
    e.preventDefault(); e.stopImmediatePropagation();
    pmExitBlock();
  }
  // "Save Block & Exit" — write the block, then collapse straight back to the shortcode.
  async function pmSaveBlockAndExit(){ await saveEditBlock(); pmExitBlock(); }
  editBtnBack.addEventListener('click', pmMaybeReturnFromBlock, true);
  editBtnClose.addEventListener('click', pmMaybeReturnFromBlock, true);

  // Edit-target readout: follow the caret; hover the readout or the Edit button to
  // select+reveal the exact shortcode token in the editor (two synced indicators).
  ['keyup','click','input','mouseup'].forEach(ev => editTextarea.addEventListener(ev, pmSCUpdateTarget));
  (function(){
    const tgt = document.getElementById('pm-sc-target');
    if (tgt) {
      // Flash the token only when hovering the READOUT itself — not the toolbar button
      // (passing the mouse over the toolbar shouldn't hijack your selection).
      tgt.addEventListener('mouseenter', pmSCFlashToken);
      tgt.addEventListener('mouseleave', pmSCUnflash);
      tgt.addEventListener('click', () => { if (tgt.classList.contains('has-target')) pmEditShortcodeAtCursor(); });
    }

    // Double-click a shortcode → select the WHOLE [[…]] (native dblclick only grabs a word),
    // lighting it up for Cut/Copy AND arming the Edit Shortcode button.
    editTextarea.addEventListener('dblclick', () => {
      const t = pmShortcodeAt(editTextarea.value, editTextarea.selectionStart);
      if (t) { try { editTextarea.setSelectionRange(t.start, t.end); } catch (_) {} pmSCUpdateTarget(); }
    });

    // Right-click a shortcode → context menu (Edit / Unwind) for THAT exact one.
    editTextarea.addEventListener('contextmenu', (e) => {
      const et = editOverlay.getAttribute('data-edit-type');
      if (et === 'block' || !pmSourceMode) return;                 // let the native menu show
      const idx = pmSCPointToIndex(e.clientX, e.clientY);
      const t = pmShortcodeAt(editTextarea.value, idx >= 0 ? idx : editTextarea.selectionStart);
      if (!t) return;
      e.preventDefault();
      try { editTextarea.setSelectionRange(t.start, t.end); } catch (_) {}   // light it up
      pmSCUpdateTarget();
      pmShowSCMenu(e.clientX, e.clientY, t);
    });
  })();

  // Tiny context menu for a shortcode.
  let _pmSCMenu = null;
  function pmHideSCMenu(){ if (_pmSCMenu) { _pmSCMenu.remove(); _pmSCMenu = null; } }
  function pmShowSCMenu(x, y, t){
    pmHideSCMenu();
    // Editable in place: html-blocks open the block editor, stacks open the
    // ContentStacks builder. Unwind is offered for ANY [[kind:slug]] — it goes
    // through the render engine, which knows far more shortcodes than this menu.
    const isBlock = t.kind === 'html-block';
    const isStack = t.kind === 'stack' || t.kind === 'content-stack';
    const m = document.createElement('div');
    m.className = 'pm-sc-menu';
    m.innerHTML =
      '<div class="pm-sc-menu-head">[[' + t.kind + ':' + t.slug + ']]</div>' +
      (isBlock ? '<button type="button" data-a="edit"><i class="fa-solid fa-pen-to-square"></i> Edit block</button>' : '') +
      (isStack ? '<button type="button" data-a="editstack"><i class="fa-solid fa-layer-group"></i> Edit stack</button>' : '') +
      '<button type="button" data-a="unwind"><i class="fa-solid fa-box-open"></i> Unwind to inline</button>';
    m.style.left = Math.min(x, window.innerWidth  - 230) + 'px';
    m.style.top  = Math.min(y, window.innerHeight - 130) + 'px';
    document.body.appendChild(m);
    _pmSCMenu = m;
    m.addEventListener('click', (ev) => {
      const b = ev.target.closest('button'); if (!b || b.disabled) return;
      const a = b.dataset.a; pmHideSCMenu();
      if (a === 'edit') pmOpenBlockFrom(t.slug);
      else if (a === 'editstack') pmOpenStackEditor(t.slug);
      else if (a === 'unwind') pmUnwindRange(t.slug, t.start, t.end, t.kind);
    });
    setTimeout(() => document.addEventListener('mousedown', pmHideSCMenu, { once: true }), 0);
  }

  // ---- Convert the selected HTML (or whole column) into an [[html-block]] ----
  // Source-mode only for v1 (exact textarea selection). Server captures the CSS
  // self-contained (residual-scope engine) and returns the shortcode + a report.
  async function pmConvertToBlock() {
    if (editOverlay.getAttribute('data-edit-type') === 'block') {
      showToast('You’re editing a block — click “Save Block” to write it, or “Back to Preview” to return to the page.', true); return;
    }
    if (editingColumn !== 'left' && editingColumn !== 'right') {
      showToast('Open a Left/Right column to convert.', true); return;
    }
    if (!pmSourceMode) {
      showToast('Switch to Source mode (the “Source” button), then select the HTML to convert.', true); return;
    }
    const ta = editTextarea;
    let s = ta.selectionStart, en = ta.selectionEnd;
    let selection = ta.value.substring(s, en);
    if (!selection.trim()) {
      if (!confirm('Nothing selected — convert the ENTIRE ' + editingColumn + ' column into one HTML Block?')) return;
      s = 0; en = ta.value.length; selection = ta.value;
    }
    const mainC  = editingColumn === 'left'  ? ta.value : leftContent;
    const rightC = editingColumn === 'right' ? ta.value : rightContent;

    const btn = document.getElementById('pm-convert-block');
    if (btn) btn.disabled = true;
    showToast('Converting… capturing CSS');
    try {
      const fd = new FormData();
      fd.append('slug', currentSlug);
      fd.append('selection', selection);
      fd.append('main_content', mainC);
      fd.append('right_column', rightC);
      fd.append('column', editingColumn);
      const res  = await fetch('/admin/modules/PageManager/api/convert-to-html-block.php',
                               { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) { showToast('Convert failed: ' + ((data && data.error) || res.status), true); return; }

      // Replace the selection with the shortcode, with clean newline air (undo-safe).
      const code = data.shortcode, val = ta.value;
      let rStart = s, rEnd = en;
      const trimL = val.substring(0, s).match(/[ \t\r\n]+$/); if (trimL) rStart = s - trimL[0].length;
      const trimR = val.substring(en).match(/^[ \t\r\n]+/);   if (trimR) rEnd = en + trimR[0].length;
      const lead = rStart === 0 ? '' : '\n\n', trail = rEnd >= val.length ? '' : '\n\n';
      const caretStart = rStart + lead.length;
      pmReplaceRange(ta, rStart, rEnd, lead + code + trail);
      ta.focus();
      try { ta.setSelectionRange(caretStart, caretStart + code.length); } catch (_) {}
      setTimeout(() => { try { ta.setSelectionRange(caretStart + code.length, caretStart + code.length); } catch (_) {} }, 800);

      const st = (data.report && data.report.stats) || {};
      let msg = 'Converted → ' + code + '  ·  ' + (st.captured || 0) + ' CSS rules';
      if (st.vars)  msg += ', ' + st.vars + ' tokens';
      if (st.moved) msg += '  ·  ' + st.moved + ' movable';
      showToast(msg);
    } catch (err) {
      showToast('Convert error: ' + err.message, true);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // Format select (heading dropdown)
  pmHtmlToolbar.querySelector('select[data-cmd="formatBlock"]').addEventListener('change', function() {
    const tag = this.value;
    if (pmSourceMode) {
      // Source mode: wrap selection in block tag
      pmInsertAtCursor(editTextarea, '<' + tag + '>', '</' + tag + '>');
    } else {
      pmPostToFrame({ type: 'pm-exec', cmd: 'formatBlock', arg: '<' + tag + '>' });
    }
    this.value = 'p'; // reset dropdown
  });

  function pmToggleSource() {
    pmSourceMode = !pmSourceMode;
    if (pmSourceMode) {
      // Visual → Source
      editTextarea.value = pmHtmlEditor.innerHTML;
      pmHtmlEditor.classList.add('hidden');
      editTextarea.classList.remove('hidden');
    } else {
      // Source → Visual. If the iframe was never primed (source-mode default
      // boot) or is currently on a different column, load it now and queue
      // the textarea content to seed once pm-ready fires. Otherwise push
      // directly to the already-ready frame.
      editTextarea.classList.add('hidden');
      pmHtmlEditor.classList.remove('hidden');
      const needsLoad = !pmFrameReady || pmFrameCurrentCol !== editingColumn;
      if (needsLoad && editingColumn && editingColumn !== 'css') {
        pmFramePendingSeed = editTextarea.value;
        pmLoadFrame(editingColumn);
      } else {
        pmHtmlEditor.innerHTML = editTextarea.value;
      }
    }
    pmSourceToggleBtn.classList.toggle('active', pmSourceMode);
    // Update the big visible mode-indicator pill
    var _pmModeEl = document.getElementById('pm-ht-mode-indicator');
    if (_pmModeEl) {
      _pmModeEl.textContent = pmSourceMode ? 'ACTIVE: SOURCE MODE' : 'ACTIVE: WYSIWYG MODE';
      _pmModeEl.classList.toggle('pm-ht-mode-source', pmSourceMode);
      _pmModeEl.classList.toggle('pm-ht-mode-visual', !pmSourceMode);
    }
    // Flip the button label/icon to show what a click switches TO
    // (in Source mode → offer WYSIWYG; in Visual mode → offer Source).
    if (pmSourceToggleBtn) {
      var _lbl = pmSourceToggleBtn.querySelector('.pm-src-lbl');
      var _ico = pmSourceToggleBtn.querySelector('.pm-src-ico');
      if (_lbl) _lbl.textContent = pmSourceMode ? 'WYSIWYG' : 'Source';
      if (_ico) _ico.className = 'pm-src-ico fa-solid ' + (pmSourceMode ? 'fa-eye' : 'fa-code');
    }
  }

  function pmPrettyFormat() {
    if (!pmSourceMode) { editTextarea.value = pmHtmlEditor.innerHTML; pmToggleSource(); }
    editTextarea.value = pmPrettyHtml(editTextarea.value);
  }

  function pmMinifyHtml() {
    if (!pmSourceMode) { editTextarea.value = pmHtmlEditor.innerHTML; pmToggleSource(); }
    editTextarea.value = pmMinifyHtmlStr(editTextarea.value);
  }

  // HTML prettifier: tokenizes tags/text, tracks nesting depth, indents 2sp.
  // Keeps void elements (br, img, hr, input, meta, link, etc.) self-closing.
  // Collapses interior whitespace between tags before re-indenting, so
  // repeated runs are idempotent.
  function pmPrettyHtml(html) {
    if (!html) return '';
    var VOID = /^<(br|img|hr|input|meta|link|source|area|base|col|embed|param|track|wbr)\b/i;
    var INLINE_TEXT_OK = /^(p|h[1-6]|li|figcaption|blockquote|span|strong|em|b|i|a|code|small|sub|sup|u|s|kbd|mark|time|abbr|label|button|caption|td|th|summary|title|option)$/i;
    html = html.replace(/>\s+</g, '><').trim();
    var parts = html.split(/(<[^>]+>)/).filter(Boolean);
    var tab = '  ';
    var out = '';
    var depth = 0;
    for (var i = 0; i < parts.length; i++) {
      var tk = parts[i];
      if (tk[0] === '<' && tk[1] === '!') {
        out += tab.repeat(depth) + tk + '\n';
      } else if (tk.startsWith('</')) {
        depth = Math.max(0, depth - 1);
        out += tab.repeat(depth) + tk + '\n';
      } else if (tk[0] === '<') {
        var tag = tk.match(/^<\s*([a-zA-Z0-9-]+)/);
        var name = tag ? tag[1] : '';
        out += tab.repeat(depth) + tk;
        if (INLINE_TEXT_OK.test(name) && parts[i+1] && parts[i+1][0] !== '<') {
          var textTk = parts[i+1];
          var closeTk = parts[i+2];
          var closeRe = new RegExp('^</\\s*' + name + '\\s*>', 'i');
          if (closeTk && closeRe.test(closeTk)) {
            out += textTk + closeTk + '\n';
            i += 2;
            continue;
          }
        }
        out += '\n';
        if (!tk.endsWith('/>') && !VOID.test(tk)) depth++;
      } else {
        var text = tk.trim();
        if (text) out += tab.repeat(depth) + text + '\n';
      }
    }
    return out.trimEnd();
  }

  function pmMinifyHtmlStr(html) {
    if (!html) return '';
    return html.replace(/>\s+</g, '><').replace(/^\s+|\s+$/g, '').replace(/\n+/g, ' ');
  }

function pmInsertLink() {
    const url = prompt('Enter URL:');
    if (url) {
      if (pmSourceMode) {
        // Source mode: insert <a> tag around selection
        const start = editTextarea.selectionStart;
        const end = editTextarea.selectionEnd;
        const selected = editTextarea.value.substring(start, end) || 'link text';
        const tag = '<a href="' + url + '">' + selected + '</a>';
        editTextarea.setRangeText(tag, start, end, 'end');
        editTextarea.focus();
        scheduleAutosave();
      } else {
        pmPostToFrame({ type: 'pm-exec', cmd: 'createLink', arg: url });
      }
    }
  }

  // Autosave from contenteditable
  pmHtmlEditor.addEventListener('input', scheduleAutosave);

  // ---- Keyboard shortcuts ----
  document.addEventListener('keydown', e=>{
    // Escape
    if(e.key === 'Escape'){
      if(editOverlay.classList.contains('open')){ closeEditModal(); return; }
      if(newOverlay.classList.contains('open')){ closeNewModal(); return; }
    }
    // Ctrl+S
    if((e.ctrlKey || e.metaKey) && e.key === 's'){
      e.preventDefault();
      if(editOverlay.classList.contains('open')){
        saveEditPage();
      } else if(newOverlay.classList.contains('open')){
        createNewPage();
      }
    }
  });

  // ---- Debug toggle ----
  const dbgEl = document.getElementById('pm-debug-toggle');
  if(dbgEl){
    fetch('/admin/data/site-settings.json',{cache:'no-store'})
      .then(r=>r.ok?r.json():{}).then(j=>{dbgEl.checked=!!(j&&j.debug_toaster);}).catch(()=>{});
    dbgEl.addEventListener('change', function(){
      fetch('/admin/modules/PageManager/api/set-debug-flag.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({debug_toaster:!!dbgEl.checked})})
        .then(r=>r.json()).then(j=>{if(!j||!j.ok) throw new Error(j?.error||'unknown');})
        .catch(err=>{alert('Toggle failed: '+err);dbgEl.checked=!dbgEl.checked;});
    });
  }

  // ---- AI Assist Panel ----
  const editBtnAi     = document.getElementById('edit-btn-ai');
  const editAiPane    = document.getElementById('edit-ai-pane');
  const aiPrompt      = document.getElementById('ai-prompt');
  const aiTone        = document.getElementById('ai-tone');
  const aiType        = document.getElementById('ai-type');
  const aiProvider    = document.getElementById('ai-provider');
  const aiWordcount   = document.getElementById('ai-wordcount');
  const aiWcLabel     = document.getElementById('ai-wordcount-lbl');
  const aiGenerateBtn = document.getElementById('ai-generate-btn');
  const aiStatus      = document.getElementById('ai-status');
  const aiResultActions = document.getElementById('ai-result-actions');
  const aiPreviewIframe = document.getElementById('ai-preview-iframe');
  const aiSourceCode  = document.getElementById('ai-source-code');
  const aiToggleSource = document.getElementById('ai-toggle-source');
  const aiIncludeImages = document.getElementById('ai-include-images');
  const aiImagesBadge  = document.getElementById('ai-images-badge');
  const aiImgOptions   = document.getElementById('ai-img-options');
  const aiImgCountVal  = document.getElementById('ai-imgcount-val');
  const aiImgRelevant  = document.getElementById('ai-img-relevant');
  let aiImageCount = 3;
  let aiImageLayout = 'mixed';
  let aiGeneratedHtml = '';
  let aiPendingImages = []; // Populated during two-phase flow
  const aiTokenBadge = document.getElementById('ai-token-badge');
  let _providerMaxTokens = 10000; // Updated from provider config

  aiWordcount.addEventListener('input', ()=> aiWcLabel.textContent = aiWordcount.value);

  // Toggle image options panel
  aiIncludeImages.addEventListener('change', ()=> {
    aiImagesBadge.style.display = aiIncludeImages.checked ? '' : 'none';
    aiImgOptions.style.display = aiIncludeImages.checked ? '' : 'none';
  });

  // Image count stepper
  document.getElementById('ai-imgcount-dec').addEventListener('click', ()=> {
    if (aiImageCount > 1) { aiImageCount--; aiImgCountVal.textContent = aiImageCount; }
  });
  document.getElementById('ai-imgcount-inc').addEventListener('click', ()=> {
    if (aiImageCount < 8) { aiImageCount++; aiImgCountVal.textContent = aiImageCount; }
  });

  // Layout style toggle
  document.getElementById('ai-img-layout-btns').addEventListener('click', e=> {
    const btn = e.target.closest('.pm-ai-img-layout-btn');
    if (!btn) return;
    document.querySelectorAll('.pm-ai-img-layout-btn').forEach(b=> b.classList.remove('active'));
    btn.classList.add('active');
    aiImageLayout = btn.dataset.layout;
  });

  // Load available providers + populate info bar
  const _typeIcons = { anthropic: 'A', openai: 'O', google: 'G', custom: 'C' };
  const _typeLabels = { anthropic: 'Anthropic', openai: 'OpenAI', google: 'Google', custom: 'Custom' };

  (function loadProviders(){
    fetch('/admin/modules/AIResources/api.php?action=get_state')
      .then(r=>r.json()).then(d=>{
        if(!d.ok) return;
        const config = d.data.config || {};
        const providers = config.providers || {};
        const activeId = config.activeProvider || '';
        aiProvider.innerHTML = '<option value="">Default</option>';
        for(const [key, conf] of Object.entries(providers)){
          if(conf.enabled === false) continue;
          const label = conf.label || _typeLabels[conf.type] || conf.type || key;
          const model = conf.model || '';
          aiProvider.innerHTML += '<option value="'+key+'">'+label+(model?' ('+model+')':'')+'</option>';
        }
        // Populate active provider info bar
        const bar = document.getElementById('ai-provider-bar');
        if(activeId && providers[activeId]) {
          const ap = providers[activeId];
          const type = ap.type || 'custom';
          bar.style.display = '';
          document.getElementById('ai-pbar-icon').className = 'pm-ai-provider-bar-icon ' + type;
          document.getElementById('ai-pbar-icon').textContent = _typeIcons[type] || '?';
          document.getElementById('ai-pbar-title').textContent = ap.label || activeId;
          document.getElementById('ai-pbar-tag').textContent = 'ACTIVE';
          document.getElementById('ai-pbar-type').textContent = (_typeLabels[type] || type) + (ap.label ? ' (' + activeId + ')' : '');
          document.getElementById('ai-pbar-model').textContent = ap.model || '—';
          document.getElementById('ai-pbar-key').textContent = ap.apiKey || '(not set)';
          _providerMaxTokens = ap.maxTokens || 10000;
        } else {
          bar.style.display = 'none';
        }
        // Image provider availability — show/hide "Include AI Images" row
        const imgRow = document.getElementById('ai-images-row');
        const imgProviders = config.imageProviders || {};
        const defaultImgProv = config.defaultImageProvider || 'none';
        const activeImgConf = imgProviders[defaultImgProv];
        const hasImgProvider = activeImgConf && activeImgConf.enabled && activeImgConf.apiKey && activeImgConf.apiKey !== '(not set)';
        if (hasImgProvider) {
          imgRow.style.display = '';
          const provLabel = defaultImgProv === 'dalle' ? 'DALL-E' : defaultImgProv === 'flux' ? 'Flux' : defaultImgProv.toUpperCase();
          aiImagesBadge.textContent = provLabel;
        } else {
          imgRow.style.display = 'none';
          aiIncludeImages.checked = false;
        }

        // Populate NLM API bar
        const nlm = config.notebookLM || {};
        const nlmDot  = document.getElementById('nlm-panel-dot');
        const nlmAcct = document.getElementById('nlm-panel-acct');
        if(nlmDot && nlmAcct) {
          const hasKey = !!(nlm.serviceAccountKey && nlm.serviceAccountKey.client_email);
          if(hasKey && nlm.enabled) {
            nlmDot.className = 'pm-nlm-api-dot ok';
            nlmAcct.textContent = nlm.serviceAccountKey.client_email;
            nlmAcct.title = 'Service Account: ' + nlm.serviceAccountKey.client_email;
          } else if(hasKey) {
            nlmDot.className = 'pm-nlm-api-dot unconfigured';
            nlmAcct.textContent = 'Disabled';
          } else {
            nlmDot.className = 'pm-nlm-api-dot error';
            nlmAcct.textContent = 'Not configured';
            nlmAcct.title = 'Configure in AI Resources → NotebookLM';
          }
        }
      }).catch(()=>{});
  })();

  // Update info bar when provider dropdown changes
  aiProvider.addEventListener('change', ()=>{
    const bar = document.getElementById('ai-provider-bar');
    const tag = document.getElementById('ai-pbar-tag');
    if(aiProvider.value) {
      tag.textContent = 'SELECTED';
      tag.style.background = 'rgba(139,92,246,.15)';
      tag.style.color = '#a78bfa';
    } else {
      tag.textContent = 'ACTIVE';
      tag.style.background = '';
      tag.style.color = '';
    }
  });

  // Presets
  document.getElementById('ai-presets').addEventListener('click', e=>{
    const btn = e.target.closest('.pm-ai-preset');
    if(!btn) return;
    aiPrompt.value = btn.dataset.prompt || '';
    aiTone.value = btn.dataset.tone || 'professional';
    aiType.value = btn.dataset.type || 'article';
    document.querySelectorAll('.pm-ai-preset').forEach(b=> b.classList.remove('selected'));
    btn.classList.add('selected');
    aiPrompt.focus();
  });

  // Show AI pane
  let showAiPane = function(){
    // Save current editor content first
    if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    // cssContent is managed by style pane controls directly
    editingColumn = null;

    editPreview.classList.add('hidden');
    editEditor.classList.add('hidden');
    editAiPane.classList.remove('hidden');
    editStylePane.classList.add('hidden');
    if (editMdPane) editMdPane.classList.add('hidden');
    if (editBtnMd) editBtnMd.classList.remove('active');
    editBtnBack.style.display = '';
    editBtnLeft.classList.remove('active');
    editBtnRight.classList.remove('active');
    editBtnCss.classList.remove('active');
    editBtnAi.classList.add('active');
    setTimeout(()=> aiPrompt.focus(), 100);
  };

  editBtnAi.addEventListener('click', showAiPane);

  // ---- Markdown Editor Overlay ----
  const editBtnMd = document.getElementById('edit-btn-md');
  const editMdPane = document.getElementById('edit-md-pane');
  const mdEditorContainer = document.getElementById('pm-md-editor-container');
  let pmMdEditor = null;

  let showMdPane = function(){
    if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    // cssContent is managed by style pane controls directly

    editPreview.classList.add('hidden');
    editEditor.classList.add('hidden');
    editAiPane.classList.add('hidden');
    editStylePane.classList.add('hidden');
    editMdPane.classList.remove('hidden');
    editBtnBack.style.display = '';
    editBtnLeft.classList.remove('active');
    editBtnRight.classList.remove('active');
    editBtnCss.classList.remove('active');
    editBtnAi.classList.remove('active');
    editBtnMd.classList.add('active');

    if (!pmMdEditor && window.MarkdownEditor) {
      pmMdEditor = new MarkdownEditor(mdEditorContainer, {
        value: '',
        height: '100%',
        preview: true,
        toolbar: true,
        placeholder: 'Write markdown here... Click "Insert as HTML" to convert and insert into your page.',
      });
    }
    if (pmMdEditor) pmMdEditor.focus();
  };

  editBtnMd.addEventListener('click', showMdPane);

  // MD insert as HTML
  document.getElementById('md-insert-html').addEventListener('click', function(){
    if (!pmMdEditor) return;
    const html = pmMdEditor.getHTML();
    if (!html.trim()) { showToast('Nothing to insert', true); return; }
    // Determine target column
    const targetCol = editingColumn === 'right' ? 'right' : 'left';
    if (targetCol === 'left') leftContent += html;
    else rightContent += html;
    showToast('Inserted HTML into ' + targetCol + ' column');
    // Switch to editor view
    editMdPane.classList.add('hidden');
    editBtnMd.classList.remove('active');
    showEditor(targetCol);
  });

  // MD insert as raw markdown
  document.getElementById('md-insert-raw').addEventListener('click', function(){
    if (!pmMdEditor) return;
    const md = pmMdEditor.getValue();
    if (!md.trim()) { showToast('Nothing to insert', true); return; }
    const targetCol = editingColumn === 'right' ? 'right' : 'left';
    if (targetCol === 'left') leftContent += md;
    else rightContent += md;
    showToast('Inserted markdown into ' + targetCol + ' column');
    editMdPane.classList.add('hidden');
    editBtnMd.classList.remove('active');
    showEditor(targetCol);
  });

  // MD close
  document.getElementById('md-close').addEventListener('click', function(){
    editMdPane.classList.add('hidden');
    editBtnMd.classList.remove('active');
    showPreview();
  });

  // Override showPreview to also hide AI pane + style pane
  const _origShowPreview = showPreview;
  showPreview = function(){
    if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
    // cssContent is managed by style pane controls directly
    editingColumn = null;

    editPreview.classList.remove('hidden');
    editEditor.classList.add('hidden');
    editAiPane.classList.add('hidden');
    editStylePane.classList.add('hidden');
    if (editMdPane) editMdPane.classList.add('hidden');
    if (editBtnMd) editBtnMd.classList.remove('active');
    editBtnBack.style.display = 'none';
    editBtnLeft.classList.remove('active');
    editBtnRight.classList.remove('active');
    editBtnCss.classList.remove('active');
    editBtnAi.classList.remove('active');
    document.getElementById('edit-sidebar').style.display = '';
  };

  // Override showEditor to also hide AI pane + style pane (or show style pane for CSS)
  const _origShowEditor = showEditor;
  showEditor = function(col){
    editAiPane.classList.add('hidden');
    editBtnAi.classList.remove('active');
    if(col === 'css') {
      // Show style pane instead of textarea for CSS
      if(editingColumn === 'left') leftContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
      if(editingColumn === 'right') rightContent = pmSourceMode ? editTextarea.value : pmHtmlEditor.innerHTML;
      // cssContent is managed by style pane controls directly
      editingColumn = 'css';

      editPreview.classList.add('hidden');
      editEditor.classList.add('hidden');
      editStylePane.classList.remove('hidden');
      if (editMdPane) editMdPane.classList.add('hidden');
      if (editBtnMd) editBtnMd.classList.remove('active');
      editBtnBack.style.display = '';
      editBtnLeft.classList.remove('active');
      editBtnRight.classList.remove('active');
      editBtnCss.classList.add('active');
      renderStylePane();
    } else {
      editStylePane.classList.add('hidden');
      _origShowEditor(col);
    }
  };

  // Helper: build iframe srcdoc wrapper
  function aiIframeSrcdoc(html) {
    return '<html><head><style>body{background:#111;color:#e6eef6;font:14px/1.6 system-ui;padding:20px;margin:0}img{max-width:100%;height:auto;border-radius:6px}a{color:#60a5fa}</style></head><body>' + html + '</body></html>';
  }

  // Helper: build token cost string
  function aiCostString(model, usage) {
    const inTok = usage.input_tokens || 0;
    const outTok = usage.output_tokens || 0;
    const totalTok = inTok + outTok;
    const costTable = {
      'claude-opus-4-6':[15,75],'claude-sonnet-4-5-20250929':[3,15],'claude-haiku-4-5-20251001':[1,5],
      'gpt-4o':[2.5,10],'gpt-4o-mini':[0.15,0.6],'gpt-4-turbo':[10,30],'o1':[15,60],
      'gemini-2.0-flash':[0.1,0.4],'gemini-1.5-pro':[1.25,5],'gemini-1.5-flash':[0.075,0.3],
    };
    let costStr = '';
    const rates = costTable[model];
    if (rates && totalTok > 0) {
      const costUsd = (inTok * rates[0] + outTok * rates[1]) / 1_000_000;
      costStr = costUsd < 0.01 ? ' &middot; <$0.01' : ' &middot; ~$' + costUsd.toFixed(3);
    }
    return { totalTok, inTok, outTok, costStr };
  }

  // Helper: render token usage badge
  function aiShowTokenBadge(usage, model) {
    const inTok = usage.input_tokens || 0;
    const outTok = usage.output_tokens || 0;
    const totalTok = inTok + outTok;
    if (totalTok === 0) { aiTokenBadge.style.display = 'none'; return; }

    const maxTok = _providerMaxTokens;
    const pct = maxTok > 0 ? Math.round((outTok / maxTok) * 100) : 0;
    const isNearLimit = pct >= 90;

    let html = '<span class="pm-ai-token-pill"><span class="lbl">In</span> ' + inTok.toLocaleString() + '</span>'
      + '<span class="pm-ai-token-pill"><span class="lbl">Out</span> ' + outTok.toLocaleString() + '</span>'
      + '<span class="pm-ai-token-pill total"><span class="lbl">Total</span> ' + totalTok.toLocaleString() + '</span>';

    if (isNearLimit) {
      html += '<span class="pm-ai-token-pill warn" title="Output used ' + pct + '% of max tokens (' + maxTok.toLocaleString() + '). Content may have been truncated.">'
        + '<i class="fa-solid fa-triangle-exclamation"></i> ' + pct + '% of limit &mdash; may be truncated</span>';
    }

    aiTokenBadge.innerHTML = html;
    aiTokenBadge.style.display = 'flex';
  }

  // Helper: replace [ai-image ...] shortcodes in HTML with <img> tags
  function aiReplaceShortcodes(html, resolvedImages) {
    let result = html;
    for (const img of resolvedImages) {
      if (img.src) {
        const alt = (img.alt || img.prompt || '').replace(/"/g, '&quot;');
        const imgTag = '<img src="' + img.src + '" alt="' + alt + '" style="max-width:100%;height:auto;border-radius:6px;" loading="lazy">';
        result = result.replace(img.raw, imgTag);
      }
    }
    return result;
  }

  // Generate
  aiGenerateBtn.addEventListener('click', async ()=>{
    const prompt = aiPrompt.value.trim();
    if(!prompt){ aiStatus.textContent = 'Please enter a prompt.'; aiStatus.style.color='#f87171'; return; }

    aiGenerateBtn.disabled = true;
    aiGenerateBtn.classList.add('loading');
    aiGenerateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
    aiStatus.textContent = 'Sending to AI...';
    aiStatus.style.color = '#818cf8';
    aiResultActions.style.display = 'none';
    aiTokenBadge.style.display = 'none';
    aiPendingImages = [];

    try {
      const payload = {
        prompt: prompt,
        tone: aiTone.value,
        contentType: aiType.value,
        wordCount: parseInt(aiWordcount.value),
        pageTitle: editTitle.value || '',
        existingContent: leftContent.substring(0, 1000),
        provider: aiProvider.value || '',
        includeImages: aiIncludeImages.checked,
        imageCount: aiIncludeImages.checked ? aiImageCount : 0,
        imageLayout: aiIncludeImages.checked ? aiImageLayout : '',
        imageRelevant: aiIncludeImages.checked ? aiImgRelevant.checked : false,
      };

      // ── Phase 1: Generate content ──
      const res = await fetch('/admin/modules/AIResources/api.php?action=generate_fragment', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify(payload)
      });
      const result = await res.json();
      if(!result.ok) throw new Error(result.error || 'Generation failed');

      const model = result.data.model || 'unknown';
      const usage = result.data.usage || {};
      const { totalTok, inTok, outTok, costStr } = aiCostString(model, usage);
      const images = result.data.images || [];
      const genId  = result.data.genId || '';

      // If no images, standard flow
      if (images.length === 0) {
        aiGeneratedHtml = result.data.html;
        aiPreviewIframe.srcdoc = aiIframeSrcdoc(aiGeneratedHtml);
        aiSourceCode.textContent = aiGeneratedHtml;
        aiResultActions.style.display = 'flex';
        aiStatus.innerHTML = '<span style="color:#34d399">Generated</span> &middot; ' + model
          + ' &middot; <span title="Input: ' + inTok + ' / Output: ' + outTok + '">' + totalTok.toLocaleString() + ' tokens</span>'
          + '<span style="color:#fbbf24">' + costStr + '</span>';
        aiStatus.style.color = '#7b8fa3';
        aiShowTokenBadge(usage, model);
        // Auto-save draft
        _currentGenId = new Date().toISOString().replace(/[-:T]/g,'').substring(0,15) + '_' + Math.random().toString(36).substring(2,10);
        aiSaveDraft({
          genId: _currentGenId, prompt: prompt, html: aiGeneratedHtml, previewHtml: '',
          images: [], settings: { tone: aiTone.value, contentType: aiType.value, wordCount: parseInt(aiWordcount.value) },
          provider: aiProvider.value || '', model: model, usage: usage,
          pageSlug: currentSlug || '', pageTitle: editTitle.value || '', domain: location.hostname,
          created_at: new Date().toISOString(), inserted: false,
        });
        return;
      }

      // ── Phase 2: Show preview with placeholders, then generate images one by one ──
      const rawHtml = result.data.html;
      const previewHtml = result.data.previewHtml || rawHtml;
      aiPreviewIframe.srcdoc = aiIframeSrcdoc(previewHtml);
      aiSourceCode.textContent = rawHtml;

      aiStatus.innerHTML = '<span style="color:#34d399">Content generated</span> &middot; ' + model
        + ' &middot; ' + totalTok.toLocaleString() + ' tokens'
        + '<span style="color:#fbbf24">' + costStr + '</span>'
        + '<br><span style="color:#818cf8"><i class="fa-solid fa-images"></i> Generating images (0/' + images.length + ')...</span>';
      aiStatus.style.color = '#7b8fa3';

      // Show insert buttons (user can insert with placeholders if desired)
      aiResultActions.style.display = 'flex';
      aiGeneratedHtml = rawHtml; // Will be updated as images resolve

      // Auto-save draft (initial — before images resolve)
      _currentGenId = genId || (new Date().toISOString().replace(/[-:T]/g,'').substring(0,15) + '_' + Math.random().toString(36).substring(2,10));
      aiSaveDraft({
        genId: _currentGenId, prompt: prompt, html: rawHtml, previewHtml: previewHtml,
        images: images, settings: { tone: aiTone.value, contentType: aiType.value, wordCount: parseInt(aiWordcount.value) },
        provider: aiProvider.value || '', model: model, usage: usage,
        pageSlug: currentSlug || '', pageTitle: editTitle.value || '', domain: location.hostname,
        created_at: new Date().toISOString(), inserted: false,
      });

      let resolved = 0;
      const resolvedImages = [...images]; // Copy to track resolved state

      for (let i = 0; i < images.length; i++) {
        const img = images[i];
        try {
          aiGenerateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Image ' + (i+1) + '/' + images.length;

          const imgRes = await fetch('/admin/modules/AIResources/api.php?action=generate_single_image', {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({
              prompt: img.prompt,
              genId: genId,
              idx: img.idx,
              style: img.style,
              domain: location.hostname,
            })
          });
          const imgResult = await imgRes.json();

          if (imgResult.ok && imgResult.data) {
            resolvedImages[i] = { ...resolvedImages[i], src: imgResult.data.src, status: 'resolved' };
            resolved++;

            // Update preview — replace this image's placeholder with actual <img>
            const finalHtml = aiReplaceShortcodes(rawHtml, resolvedImages);
            aiGeneratedHtml = finalHtml;
            aiPreviewIframe.srcdoc = aiIframeSrcdoc(finalHtml);
            aiSourceCode.textContent = finalHtml;
          } else {
            resolvedImages[i] = { ...resolvedImages[i], status: 'failed', error: imgResult.error || 'Unknown error' };
          }
        } catch(imgErr) {
          resolvedImages[i] = { ...resolvedImages[i], status: 'failed', error: imgErr.message };
        }

        // Update progress
        const failed = resolvedImages.filter(x => x.status === 'failed').length;
        let progressHtml = '<span style="color:#34d399">Content generated</span> &middot; ' + model
          + ' &middot; ' + totalTok.toLocaleString() + ' tokens'
          + '<span style="color:#fbbf24">' + costStr + '</span>'
          + '<br><span style="color:#818cf8"><i class="fa-solid fa-images"></i> Images: <span class="count">' + resolved + '</span>/' + images.length + ' resolved</span>';
        if (failed > 0) {
          progressHtml += '<span style="color:#f87171"> (' + failed + ' failed)</span>';
        }
        aiStatus.innerHTML = progressHtml;
      }

      // Final status
      const failed = resolvedImages.filter(x => x.status === 'failed').length;
      let finalStatus = '<span style="color:#34d399">Complete</span> &middot; ' + model
        + ' &middot; ' + totalTok.toLocaleString() + ' tokens'
        + '<span style="color:#fbbf24">' + costStr + '</span>';
      if (resolved > 0) {
        finalStatus += '<br><span style="color:#34d399"><i class="fa-solid fa-check"></i> ' + resolved + ' image' + (resolved > 1 ? 's' : '') + ' generated</span>';
      }
      if (failed > 0) {
        finalStatus += '<span style="color:#f87171"> &middot; ' + failed + ' failed</span>';
      }
      aiStatus.innerHTML = finalStatus;
      aiPendingImages = resolvedImages;
      aiShowTokenBadge(usage, model);

      // Update draft with resolved images
      aiSaveDraft({
        genId: _currentGenId, prompt: prompt, html: aiGeneratedHtml, previewHtml: '',
        images: resolvedImages, settings: { tone: aiTone.value, contentType: aiType.value, wordCount: parseInt(aiWordcount.value) },
        provider: aiProvider.value || '', model: model, usage: usage,
        pageSlug: currentSlug || '', pageTitle: editTitle.value || '', domain: location.hostname,
        created_at: new Date().toISOString(), inserted: false,
      });

    } catch(e){
      aiStatus.textContent = 'Error: ' + e.message;
      aiStatus.style.color = '#f87171';
    } finally {
      aiGenerateBtn.disabled = false;
      aiGenerateBtn.classList.remove('loading');
      aiGenerateBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
    }
  });

  // ── Prompt Commons ──
  const PC_API = '/admin/modules/AIResources/api.php';
  const pcSelect = document.getElementById('ai-prompt-commons-select');
  const pcSaveBtn = document.getElementById('ai-prompt-save-btn');
  const pcDeleteBtn = document.getElementById('ai-prompt-delete-btn');
  let _pcPrompts = [];

  async function pcLoadList() {
    try {
      const res = await fetch(PC_API + '?action=prompt_commons_list', {
        headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
      });
      const j = await res.json();
      _pcPrompts = j.prompts || [];
      pcSelect.innerHTML = '<option value="">Prompt Commons (' + _pcPrompts.length + ')...</option>';
      _pcPrompts.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        pcSelect.appendChild(opt);
      });
    } catch(e) { /* silent */ }
  }

  pcSelect.addEventListener('change', () => {
    const id = pcSelect.value;
    if (!id) { pcDeleteBtn.style.display = 'none'; return; }
    const p = _pcPrompts.find(x => x.id === id);
    if (!p) return;
    aiPrompt.value = p.prompt;
    aiTone.value = p.tone || 'professional';
    aiType.value = p.contentType || 'article';
    const wc = document.getElementById('ai-wordcount');
    const wcLbl = document.getElementById('ai-wordcount-lbl');
    wc.value = p.wordCount || 300;
    wcLbl.textContent = wc.value;
    pcDeleteBtn.style.display = '';
    // Track usage
    fetch(PC_API + '?action=prompt_commons_use', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({ action: 'prompt_commons_use', id })
    }).catch(() => {});
  });

  pcSaveBtn.addEventListener('click', async () => {
    const prompt = aiPrompt.value.trim();
    if (!prompt) { showToast('Enter a prompt first'); return; }
    const name = window.prompt('Name for this prompt:');
    if (!name || !name.trim()) return;
    try {
      const res = await fetch(PC_API + '?action=prompt_commons_save', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({
          action: 'prompt_commons_save',
          name: name.trim(),
          prompt: prompt,
          tone: aiTone.value,
          contentType: aiType.value,
          wordCount: parseInt(document.getElementById('ai-wordcount').value),
        })
      });
      const j = await res.json();
      if (j.ok) { showToast('Saved to Prompt Commons'); pcLoadList(); }
      else showToast('Error: ' + (j.error || 'Save failed'));
    } catch(e) { showToast('Error: ' + e.message); }
  });

  pcDeleteBtn.addEventListener('click', async () => {
    const id = pcSelect.value;
    if (!id) return;
    const p = _pcPrompts.find(x => x.id === id);
    if (!confirm('Delete prompt "' + (p?.name || id) + '"?')) return;
    try {
      await fetch(PC_API + '?action=prompt_commons_delete', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ action: 'prompt_commons_delete', id })
      });
      showToast('Prompt deleted');
      pcSelect.value = '';
      pcDeleteBtn.style.display = 'none';
      pcLoadList();
    } catch(e) { showToast('Error: ' + e.message); }
  });

  // Load on init
  pcLoadList();

  // Insert buttons
  aiResultActions.addEventListener('click', e=>{
    const btn = e.target.closest('.pm-ai-insert-btn');
    if(!btn || !aiGeneratedHtml) return;
    const target = btn.dataset.target;

    if(target === 'nlm'){
      // Send generated content to NotebookLM as source
      nlmExtraContent = aiGeneratedHtml.replace(/<[^>]*>/g, '').trim();
      const noteEl = document.getElementById('nlm-source-note');
      const noteText = document.getElementById('nlm-source-note-text');
      noteEl.style.display = '';
      const wordCount = nlmExtraContent.split(/\s+/).length;
      noteText.textContent = 'AI content added as source (' + wordCount + ' words)';
      // Scroll to NLM section
      const nlmLabel = document.querySelector('.pm-nlm-label');
      if(nlmLabel) nlmLabel.scrollIntoView({behavior:'smooth', block:'start'});
      showToast('Content sent to NotebookLM — click Generate Podcast');
      return;
    }

    // Strip any unresolved [ai-image ...] shortcodes before inserting into page content
    const cleanHtml = aiGeneratedHtml.replace(/\[ai-image\s+[^\]]*\]/gi, '');

    if(target === 'left'){
      leftContent += '\n' + cleanHtml;
      showToast('Inserted into Left Column');
    } else if(target === 'right'){
      rightContent += '\n' + cleanHtml;
      showToast('Inserted into Right Column');
    } else if(target === 'replace-left'){
      if(!confirm('Replace all content in the Left Column?')) return;
      leftContent = cleanHtml;
      showToast('Replaced Left Column');
    }

    // Mark draft as inserted
    if (_currentGenId) {
      aiSaveDraft({ genId: _currentGenId, inserted: true, html: cleanHtml });
    }

    // Switch to editor showing the column we just inserted into
    const editCol = target === 'right' ? 'right' : 'left';
    editAiPane.classList.add('hidden');
    editBtnAi.classList.remove('active');
    showEditor(editCol);
    scheduleAutosave();
  });

  // Toggle source view
  aiToggleSource.addEventListener('click', ()=>{
    aiSourceCode.classList.toggle('hidden');
    aiToggleSource.textContent = aiSourceCode.classList.contains('hidden') ? 'View Source' : 'Hide Source';
  });

  // ── AI Drafts System ──
  const DRAFT_API = '/admin/modules/AIResources/api.php';
  const aiDraftsPanel = document.getElementById('ai-drafts-panel');
  const aiDraftsList = document.getElementById('ai-drafts-list');
  const aiDraftsCount = document.getElementById('ai-drafts-count');
  const aiToggleDrafts = document.getElementById('ai-toggle-drafts');
  let _currentGenId = '';

  // Auto-save a draft to the server
  async function aiSaveDraft(data) {
    try {
      await fetch(DRAFT_API + '?action=save_ai_draft', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify(data)
      });
    } catch(e) { /* silent — draft save is best-effort */ }
  }

  // Load drafts list
  async function aiLoadDrafts() {
    try {
      const res = await fetch(DRAFT_API + '?action=list_ai_drafts', {
        headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
      });
      const j = await res.json();
      const drafts = j.drafts || [];
      aiDraftsCount.textContent = drafts.length;

      if (drafts.length === 0) {
        aiDraftsList.innerHTML = '<div class="pm-ai-drafts-empty">No drafts yet. Generate content to auto-save drafts.</div>';
        return;
      }

      aiDraftsList.innerHTML = '';
      drafts.forEach(d => {
        const card = document.createElement('div');
        card.className = 'pm-ai-draft-card' + (d.genId === _currentGenId ? ' active' : '');
        card.dataset.genId = d.genId;

        const timeStr = d.created_at ? new Date(d.created_at).toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
        const tokTotal = (d.usage?.input_tokens || 0) + (d.usage?.output_tokens || 0);

        let tags = '<span class="pm-ai-draft-tag">' + (d.model || 'unknown') + '</span>';
        if (tokTotal > 0) tags += '<span class="pm-ai-draft-tag">' + tokTotal.toLocaleString() + ' tok</span>';
        if (d.inserted) tags += '<span class="pm-ai-draft-tag inserted">Inserted</span>';
        if (d.hasImages) tags += '<span class="pm-ai-draft-tag has-images">Images</span>';
        if (d.pageSlug) tags += '<span class="pm-ai-draft-tag">' + d.pageSlug + '</span>';

        card.innerHTML = '<div class="pm-ai-draft-body">'
          + '<div class="pm-ai-draft-prompt">' + (d.prompt || '(no prompt)').replace(/</g,'&lt;') + '</div>'
          + '<div class="pm-ai-draft-meta">' + tags + '<span class="pm-ai-draft-time">' + timeStr + '</span></div>'
          + '</div>'
          + '<div class="pm-ai-draft-actions">'
          + '<button class="pm-ai-draft-action load" data-gen-id="' + d.genId + '" title="Load into preview">Load</button>'
          + '<button class="pm-ai-draft-action delete" data-gen-id="' + d.genId + '" title="Delete draft">&times;</button>'
          + '</div>';

        aiDraftsList.appendChild(card);
      });
    } catch(e) { /* silent */ }
  }

  // Toggle drafts panel
  aiToggleDrafts.addEventListener('click', ()=> {
    const isHidden = aiDraftsPanel.classList.toggle('hidden');
    aiToggleDrafts.classList.toggle('active', !isHidden);
    if (isHidden) return; // just closed the panel
    aiLoadDrafts();
  });

  // Draft actions (load / delete)
  aiDraftsList.addEventListener('click', async (e)=> {
    const loadBtn = e.target.closest('.pm-ai-draft-action.load');
    const deleteBtn = e.target.closest('.pm-ai-draft-action.delete');

    if (loadBtn) {
      const genId = loadBtn.dataset.genId;
      try {
        loadBtn.textContent = '...';
        const res = await fetch(DRAFT_API + '?action=get_ai_draft&genId=' + encodeURIComponent(genId), {
          headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        const j = await res.json();
        if (!j.ok || !j.draft) { showToast('Draft not found'); loadBtn.textContent = 'Load'; return; }

        const draft = j.draft;
        aiGeneratedHtml = draft.html || '';
        aiPreviewIframe.srcdoc = aiIframeSrcdoc(aiGeneratedHtml);
        aiSourceCode.textContent = aiGeneratedHtml;
        aiResultActions.style.display = 'flex';
        _currentGenId = genId;

        const usage = draft.usage || {};
        const model = draft.model || 'unknown';
        const { totalTok, inTok, outTok, costStr } = aiCostString(model, usage);
        aiStatus.innerHTML = '<span style="color:#60a5fa">Loaded from draft</span> &middot; ' + model
          + ' &middot; ' + totalTok.toLocaleString() + ' tokens'
          + '<span style="color:#fbbf24">' + costStr + '</span>';
        aiStatus.style.color = '#7b8fa3';
        aiShowTokenBadge(usage, model);

        // Highlight active card
        aiDraftsList.querySelectorAll('.pm-ai-draft-card').forEach(c => c.classList.remove('active'));
        const card = loadBtn.closest('.pm-ai-draft-card');
        if (card) card.classList.add('active');

        showToast('Draft loaded — use Insert buttons to apply');
      } catch(err) {
        showToast('Error loading draft');
      }
      loadBtn.textContent = 'Load';
    }

    if (deleteBtn) {
      const genId = deleteBtn.dataset.genId;
      if (!confirm('Delete this draft?')) return;
      try {
        await fetch(DRAFT_API + '?action=delete_ai_draft', {
          method: 'POST',
          headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({ genId })
        });
        const card = deleteBtn.closest('.pm-ai-draft-card');
        if (card) card.remove();
        aiLoadDrafts();
        showToast('Draft deleted');
      } catch(e) { showToast('Error deleting draft'); }
    }
  });

  // ---- NotebookLM Podcast ----
  const NLM_API = '/admin/modules/AIResources/api.php';
  let nlmSelectedLength = 'STANDARD';
  let nlmSelectedType = 'CONVERSATION';
  let nlmPollTimer = null;
  let nlmExtraContent = ''; // AI-generated content to include as source

  // Persistent operation tracking (survives modal close/reopen)
  const NLM_STORAGE_KEY = 'nlm_pending_op';
  function nlmSavePending(opName, slug, notebookName, title, focus, length, format) {
    sessionStorage.setItem(NLM_STORAGE_KEY, JSON.stringify({
      opName, slug, notebookName, title, focus, length, format, started: Date.now()
    }));
  }
  function nlmClearPending() { sessionStorage.removeItem(NLM_STORAGE_KEY); }
  function nlmGetPending() {
    try { return JSON.parse(sessionStorage.getItem(NLM_STORAGE_KEY)); } catch(e) { return null; }
  }

  // Length toggle
  document.querySelectorAll('.pm-nlm-len-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.pm-nlm-len-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      nlmSelectedLength = btn.dataset.len;
    });
  });

  // Format type toggle
  document.querySelectorAll('.pm-nlm-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.pm-nlm-type-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      nlmSelectedType = btn.dataset.type;
    });
  });

  // Load podcast history for current page
  async function nlmLoadHistory(slug) {
    const container = document.getElementById('nlm-history');
    if (!slug) { container.innerHTML = '<div class="pm-nlm-empty">Save the page first to see podcasts.</div>'; return; }

    try {
      const res = await fetch(NLM_API + '?action=nlm_list_podcasts', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ action: 'nlm_list_podcasts', pageSlug: slug })
      });
      const j = await res.json();
      if (!j.ok || !j.podcasts || j.podcasts.length === 0) {
        container.innerHTML = '<div class="pm-nlm-empty">No podcasts generated yet.</div>';
        return;
      }

      container.innerHTML = j.podcasts.map(p => {
        const date = p.created_at ? new Date(p.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
        const sizeKB = p.file_size ? Math.round(p.file_size / 1024) + ' KB' : '';
        const focusHtml = p.focus ? '<div class="pm-nlm-podcast-focus" title="' + nlmEsc(p.focus) + '">' + nlmEsc(p.focus) + '</div>' : '';

        return '<div class="pm-nlm-podcast" data-notebook="' + nlmEsc(p.notebook) + '" data-filename="' + nlmEsc(p.filename) + '">'
          + '<div class="pm-nlm-podcast-head">'
          + '<span class="pm-nlm-podcast-title">' + nlmEsc(p.title || 'Podcast') + '<span class="pm-nlm-badge">' + nlmEsc(p.length || 'STANDARD') + '</span>' + (p.format && p.format !== 'CONVERSATION' ? '<span class="pm-nlm-badge">' + nlmEsc(p.format) + '</span>' : '') + '</span>'
          + '<span class="pm-nlm-podcast-meta">' + nlmEsc(date) + (sizeKB ? ' &middot; ' + sizeKB : '') + '</span>'
          + '</div>'
          + focusHtml
          + '<div class="pm-nlm-player"><audio controls preload="none" src="' + nlmEsc(p.audioUrl) + '"></audio></div>'
          + '<div class="pm-nlm-actions">'
          + '<a class="pm-nlm-action-btn" href="' + nlmEsc(p.audioUrl) + '" download><i class="fa-solid fa-download"></i> Download</a>'
          + '<button type="button" class="pm-nlm-action-btn" onclick="NLM.copyShortcode(\'' + nlmEsc(p.notebook) + '\')"><i class="fa-solid fa-copy"></i> Shortcode</button>'
          + '<button type="button" class="pm-nlm-action-btn podcast-mgr" onclick="NLM.addToPodcastManager(\'' + nlmEsc(p.notebook) + '\',\'' + nlmEsc(p.filename) + '\',\'' + nlmEsc((p.title || 'Podcast').replace(/'/g, '\\&#39;')) + '\')"><i class="fa-solid fa-podcast"></i> Add to Podcasts</button>'
          + '<button type="button" class="pm-nlm-action-btn danger" onclick="NLM.deletePodcast(\'' + nlmEsc(p.notebook) + '\',\'' + nlmEsc(p.filename) + '\')"><i class="fa-solid fa-trash"></i> Delete</button>'
          + '</div></div>';
      }).join('');
    } catch(e) {
      container.innerHTML = '<div class="pm-nlm-empty">Error loading history: ' + nlmEsc(e.message) + '</div>';
    }
  }

  function nlmEsc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  // Shared poll function — used by both initial generate and auto-resume
  function nlmStartPolling(opName, slug, notebookName, title, focus, length, format, startedAt) {
    const btn = document.getElementById('nlm-generate-btn');
    const progress = document.getElementById('nlm-progress');
    const statusEl = document.getElementById('nlm-status');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
    progress.style.display = 'block';
    progress.classList.add('shimmer');

    const elapsed0 = Date.now() - (startedAt || Date.now());
    const maxWait = 600000; // 10 minutes from original start
    const pollInterval = 5000;
    let checks = 0;

    function updateElapsed() {
      const totalMs = elapsed0 + (checks * pollInterval);
      const mins = Math.floor(totalMs / 60000);
      const secs = Math.floor((totalMs % 60000) / 1000);
      statusEl.textContent = 'Generating podcast... ' + (mins ? mins + 'm ' : '') + secs + 's elapsed — come back in a few minutes';
      statusEl.style.color = '#a78bfa';
    }
    updateElapsed();

    nlmPollTimer = setInterval(async () => {
      checks++;
      const totalMs = elapsed0 + (checks * pollInterval);
      if (totalMs > maxWait) {
        clearInterval(nlmPollTimer);
        nlmPollTimer = null;
        nlmClearPending();
        nlmResetBtn();
        statusEl.textContent = 'Timed out — generation took too long. Try again later.';
        statusEl.style.color = '#f87171';
        return;
      }

      try {
        const checkRes = await fetch(NLM_API + '?action=nlm_check_status', {
          method: 'POST',
          headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({ action: 'nlm_check_status', operationName: opName })
        });
        const checkData = await checkRes.json();

        if (checkData.error && checkData.error !== null) {
          clearInterval(nlmPollTimer);
          nlmPollTimer = null;
          nlmClearPending();
          nlmResetBtn();
          statusEl.textContent = 'Error: ' + (typeof checkData.error === 'string' ? checkData.error : JSON.stringify(checkData.error));
          statusEl.style.color = '#f87171';
          return;
        }

        if (checkData.done) {
          clearInterval(nlmPollTimer);
          nlmPollTimer = null;
          nlmClearPending();
          statusEl.textContent = 'Downloading and saving audio...';
          statusEl.style.color = '#818cf8';

          const saveRes = await fetch(NLM_API + '?action=nlm_save_audio', {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({
              action: 'nlm_save_audio',
              operationName: opName,
              pageSlug: slug,
              notebookName: notebookName,
              title: title,
              focus: focus,
              length: length,
              format: format,
            })
          });
          const saveData = await saveRes.json();

          nlmResetBtn();
          if (saveData.ok) {
            const sizeKB = Math.round((saveData.size || 0) / 1024);
            statusEl.textContent = 'Podcast saved! (' + sizeKB + ' KB)';
            statusEl.style.color = '#34d399';
            nlmLoadHistory(slug);
          } else {
            statusEl.textContent = 'Save error: ' + (saveData.error || 'unknown');
            statusEl.style.color = '#f87171';
          }
        } else {
          updateElapsed();
        }
      } catch(pollErr) {
        statusEl.textContent = 'Checking status... (network hiccup, retrying)';
        statusEl.style.color = '#a78bfa';
      }
    }, pollInterval);
  }

  // Generate podcast
  document.getElementById('nlm-generate-btn').addEventListener('click', async () => {
    const slug = currentSlug;
    if (!slug) { document.getElementById('nlm-status').textContent = 'Save the page first.'; return; }

    const title = editTitle.value || slug;
    let content = (leftContent + '\n' + rightContent).replace(/<[^>]*>/g, '').trim();
    if (nlmExtraContent) {
      content = content ? content + '\n\n--- Additional Context ---\n' + nlmExtraContent : nlmExtraContent;
    }
    if (!content) { document.getElementById('nlm-status').textContent = 'Page has no content to generate from.'; return; }

    const focus = document.getElementById('nlm-focus').value.trim();
    const notebookName = document.getElementById('nlm-notebook-name').value.trim() || slug;

    const btn = document.getElementById('nlm-generate-btn');
    const statusEl = document.getElementById('nlm-status');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting...';
    statusEl.textContent = 'Sending to NotebookLM...';
    statusEl.style.color = '#a78bfa';
    nlmExtraContent = '';
    document.getElementById('nlm-source-note').style.display = 'none';

    try {
      const genRes = await fetch(NLM_API + '?action=nlm_generate_podcast', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({
          action: 'nlm_generate_podcast',
          pageSlug: slug,
          pageTitle: title,
          pageContent: content,
          focus: focus,
          length: nlmSelectedLength,
          format: nlmSelectedType,
        })
      });
      const genData = await genRes.json();
      if (!genData.ok) throw new Error(genData.error || 'Generation failed');

      const opName = genData.operationName;
      if (!opName) throw new Error('No operation name returned');

      // Save pending op for auto-resume
      nlmSavePending(opName, slug, notebookName, title, focus, nlmSelectedLength, nlmSelectedType);

      // Start polling
      nlmStartPolling(opName, slug, notebookName, title, focus, nlmSelectedLength, nlmSelectedType, Date.now());

    } catch(e) {
      nlmResetBtn();
      statusEl.textContent = 'Error: ' + e.message;
      statusEl.style.color = '#f87171';
    }
  });

  function nlmResetBtn() {
    const btn = document.getElementById('nlm-generate-btn');
    const progress = document.getElementById('nlm-progress');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-podcast"></i> Generate Podcast';
    progress.style.display = 'none';
    progress.classList.remove('shimmer');
  }

  // Auto-resume polling if there's a pending operation
  function nlmCheckPendingOp() {
    if (nlmPollTimer) return; // already polling
    const pending = nlmGetPending();
    if (!pending || !pending.opName) return;

    // If started more than 12 minutes ago, it's stale
    const age = Date.now() - pending.started;
    if (age > 720000) {
      nlmClearPending();
      return;
    }

    // Quick check if it's done before starting full poll
    const statusEl = document.getElementById('nlm-status');
    statusEl.textContent = 'Checking pending podcast generation...';
    statusEl.style.color = '#a78bfa';

    fetch(NLM_API + '?action=nlm_check_status', {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({ action: 'nlm_check_status', operationName: pending.opName })
    }).then(r => r.json()).then(checkData => {
      if (checkData.done) {
        // Already finished! Download immediately
        statusEl.textContent = 'Podcast ready! Downloading...';
        statusEl.style.color = '#34d399';
        const btn = document.getElementById('nlm-generate-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Downloading...';

        fetch(NLM_API + '?action=nlm_save_audio', {
          method: 'POST',
          headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
          body: JSON.stringify({
            action: 'nlm_save_audio',
            operationName: pending.opName,
            pageSlug: pending.slug,
            notebookName: pending.notebookName,
            title: pending.title,
            focus: pending.focus,
            length: pending.length,
            format: pending.format,
          })
        }).then(r => r.json()).then(saveData => {
          nlmClearPending();
          nlmResetBtn();
          if (saveData.ok) {
            const sizeKB = Math.round((saveData.size || 0) / 1024);
            statusEl.textContent = 'Podcast saved! (' + sizeKB + ' KB)';
            statusEl.style.color = '#34d399';
            nlmLoadHistory(pending.slug);
          } else {
            statusEl.textContent = 'Save error: ' + (saveData.error || 'unknown');
            statusEl.style.color = '#f87171';
          }
        }).catch(e => {
          nlmClearPending();
          nlmResetBtn();
          statusEl.textContent = 'Error saving: ' + e.message;
          statusEl.style.color = '#f87171';
        });
      } else if (checkData.error) {
        nlmClearPending();
        statusEl.textContent = 'Previous generation failed: ' + (typeof checkData.error === 'string' ? checkData.error : JSON.stringify(checkData.error));
        statusEl.style.color = '#f87171';
      } else {
        // Still generating — resume polling
        nlmStartPolling(pending.opName, pending.slug, pending.notebookName, pending.title, pending.focus, pending.length, pending.format, pending.started);
      }
    }).catch(() => {
      // Network error — try resuming poll anyway
      nlmStartPolling(pending.opName, pending.slug, pending.notebookName, pending.title, pending.focus, pending.length, pending.format, pending.started);
    });
  }

  // Copy shortcode
  function nlmCopyShortcode(notebook) {
    const code = '[[nlm-podcast:' + notebook + ']]';
    navigator.clipboard.writeText(code).then(() => {
      showToast('Copied: ' + code);
    }).catch(() => {
      prompt('Copy this shortcode:', code);
    });
  }

  // Delete podcast
  async function nlmDeletePodcast(notebook, filename) {
    if (!confirm('Delete this podcast? This cannot be undone.')) return;
    try {
      const res = await fetch(NLM_API + '?action=nlm_delete_podcast', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ action: 'nlm_delete_podcast', notebookName: notebook, filename: filename })
      });
      const j = await res.json();
      if (j.ok) {
        showToast('Podcast deleted');
        nlmLoadHistory(currentSlug);
      } else {
        showToast('Error: ' + (j.error || 'Delete failed'));
      }
    } catch(e) { showToast('Error: ' + e.message); }
  }

  // Add to Podcast Manager
  async function nlmAddToPodcastManager(notebook, filename, title) {
    const btn = event?.target?.closest('.pm-nlm-action-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...'; }
    try {
      const res = await fetch(NLM_API + '?action=nlm_add_to_podcast_manager', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ action: 'nlm_add_to_podcast_manager', notebookName: notebook, filename: filename, title: title })
      });
      const j = await res.json();
      if (j.ok) {
        showToast('Added to Podcast Manager! Episode: ' + (j.filename || ''));
        if (btn) { btn.innerHTML = '<i class="fa-solid fa-check"></i> Added'; btn.classList.add('success'); }
      } else {
        showToast('Error: ' + (j.error || 'Failed to add'));
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-podcast"></i> Add to Podcasts'; }
      }
    } catch(e) {
      showToast('Error: ' + e.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-podcast"></i> Add to Podcasts'; }
    }
  }

  // Public NLM API
  window.NLM = {
    copyShortcode: nlmCopyShortcode,
    deletePodcast: nlmDeletePodcast,
    addToPodcastManager: nlmAddToPodcastManager,
  };

  // Hook into showAiPane to load NLM history, set notebook name, and auto-resume
  const _origShowAiPane = showAiPane;
  showAiPane = function() {
    _origShowAiPane();
    // Set notebook name default
    const nbInput = document.getElementById('nlm-notebook-name');
    if (nbInput && !nbInput.value && currentSlug) {
      nbInput.placeholder = currentSlug + ' (default)';
    }
    nlmLoadHistory(currentSlug);
    // Auto-resume if there's a pending podcast generation
    nlmCheckPendingOp();
  };

  // ---- Purge Trash ----
  const purgeBtn = document.getElementById('btn-purge-trash');
  if(purgeBtn) purgeBtn.addEventListener('click', function(){
    if(!confirm('Permanently delete ALL trashed pages? This cannot be undone.')) return;
    purgeBtn.disabled = true; purgeBtn.textContent = 'Purging\u2026';
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'__pm_action=purge_trash'})
      .then(r=>r.json()).then(d=>{
        if(d.ok){ alert('Purged '+d.purged+' item(s).'); location.reload(); }
        else { alert('Error: '+(d.error||'unknown')); purgeBtn.disabled=false; purgeBtn.textContent='\u26A0 Purge All Trash'; }
      }).catch(e=>{ alert('Network error'); purgeBtn.disabled=false; purgeBtn.textContent='\u26A0 Purge All Trash'; });
  });


  // ── Set Home Page ──
  async function setHomePage(slug) {
    if (!slug) return;
    const fd = new FormData();
    fd.append('__pm_action', 'set_home_page');
    fd.append('slug', slug);
    try {
      const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok) {
        window.PM_HOME_SLUG = slug;
        showToast('Home page set to /' + slug);
        // Update card badges
        document.querySelectorAll('.pm-preview-card').forEach(c => {
          c.classList.toggle('is-home', c.dataset.page === slug);
          var badge = c.querySelector('.pm-home-badge');
          if (c.dataset.page === slug && !badge) {
            var titleEl = c.querySelector('.pm-preview-card-title');
            if (titleEl) titleEl.insertAdjacentHTML('afterbegin', '<span class="pm-home-badge"><i class="fa-solid fa-house"></i></span> ');
          } else if (c.dataset.page !== slug && badge) {
            badge.nextSibling && badge.nextSibling.nodeType === 3 ? badge.nextSibling.remove() : null;
            badge.remove();
          }
        });
        // Update dropdown
        var sel = document.getElementById('pm-home-select');
        if (sel) sel.value = slug;
        // Update toolbar button if in editor
        if (editBtnHome) {
          editBtnHome.innerHTML = '<i class="fa-solid fa-house"></i> Home Page \u2713';
          editBtnHome.style.background = '#059669';
          setTimeout(() => { editBtnHome.innerHTML = '<i class="fa-solid fa-house"></i> Set as Home'; editBtnHome.style.background = ''; }, 2000);
        }
      } else {
        showToast(d.error || 'Failed to set home page', true);
      }
    } catch(e) {
      showToast('Error: ' + e.message, true);
    }
  }

  // ── Add to Site Menu ──
  async function addToMenu(slug, title) {
    if (!slug) return;
    const fd = new FormData();
    fd.append('__pm_action', 'add_to_menu');
    fd.append('slug', slug);
    fd.append('title', title || slug);
    try {
      const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const d = await r.json();
      if (d.ok) {
        showToast(d.msg || 'Added to site menu');
        // Flash toolbar button if in editor
        if (editBtnAddMenu) {
          editBtnAddMenu.innerHTML = '<i class="fa-solid fa-check"></i> In Menu';
          editBtnAddMenu.style.background = '#059669';
          setTimeout(() => { editBtnAddMenu.innerHTML = '<i class="fa-solid fa-bars"></i> Add to Menu'; editBtnAddMenu.style.background = ''; }, 2000);
        }
        // Flash card icon button
        document.querySelectorAll('.js-card-add-menu[data-page="' + CSS.escape(slug) + '"]').forEach(btn => {
          btn.innerHTML = '<i class="fa-solid fa-check"></i>';
          btn.style.background = '#059669';
          setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-bars"></i>'; btn.style.background = ''; }, 2000);
        });
      } else {
        showToast(d.error || 'Failed to add to menu', true);
      }
    } catch(e) {
      showToast('Error: ' + e.message, true);
    }
  }

  // ── Minimize / De-minimize toggle ──
  (function() {
    var btn = document.getElementById('pm-minimize-toggle');
    var grid = document.getElementById('pm-cards-grid');
    if (!btn || !grid) return;
    var minimized = false;
    btn.addEventListener('click', function() {
      minimized = !minimized;
      grid.classList.toggle('pm-cards-minimized', minimized);
      btn.innerHTML = minimized
        ? '<i class="fa-solid fa-maximize"></i> De-minimize'
        : '<i class="fa-solid fa-minimize"></i> Minimize';
    });
  })();

  // ── Sort pulldown — reorders cards client-side by the chosen mode ──
  (function() {
    var sel  = document.getElementById('pm-sort-select');
    var grid = document.getElementById('pm-cards-grid');
    if (!sel || !grid) return;
    function apply(mode) {
      var cards = Array.from(grid.querySelectorAll('.pm-preview-card'));
      cards.sort(function(a, b) {
        switch (mode) {
          case 'oldest': return (+a.dataset.mtime || 0) - (+b.dataset.mtime || 0);
          case 'az':     return (a.dataset.title || '').localeCompare(b.dataset.title || '', undefined, {sensitivity:'base'});
          case 'za':     return (b.dataset.title || '').localeCompare(a.dataset.title || '', undefined, {sensitivity:'base'});
          case 'slug':   return (a.dataset.slug || '').localeCompare(b.dataset.slug || '', undefined, {sensitivity:'base'});
          case 'recent':
          default:       return (+b.dataset.mtime || 0) - (+a.dataset.mtime || 0);
        }
      });
      cards.forEach(function(c) { grid.appendChild(c); });
      try { localStorage.setItem('pm-sort-mode', mode); } catch (_) {}
    }
    sel.addEventListener('change', function() { apply(sel.value); });
    // Restore preference
    var saved = null; try { saved = localStorage.getItem('pm-sort-mode'); } catch (_) {}
    if (saved) { sel.value = saved; apply(saved); }
    else apply('recent');
  })();

  // Home page dropdown on main listing
  var homeSelect = document.getElementById('pm-home-select');
  if (homeSelect) {
    homeSelect.addEventListener('change', function() {
      setHomePage(this.value);
    });
  }

  // Auto-open edit modal from URL params (?page=slug or ?action=edit_aip&slug=...)
  (function() {
    var params = new URLSearchParams(window.location.search);
    var pageSlug = params.get('page');
    var aipSlug = params.get('slug');
    var action = params.get('action');

    if (pageSlug) {
      setTimeout(function() { openEditModal(pageSlug); }, 300);
    } else if (action === 'edit_aip' && aipSlug) {
      setTimeout(function() { openEditModal(aipSlug); }, 300);
    }
  })();

})();

/* ── Tab Switching: Pages / AI Pages / All ── */
function pmTabSwitch(tab) {
  document.querySelectorAll('.pm-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('#pm-cards-grid .pm-preview-card').forEach(card => {
    const type = card.dataset.type;
    let show;
    if (tab === 'all') show = true;
    else if (tab === 'aip') show = (type === 'aip');
    else /* pages */ show = (type === 'page' || type === 'article');
    card.style.display = show ? '' : 'none';
  });
  // Articles are hidden/shown by the .pm-articles-hidden class on the grid (CSS !important),
  // independent of the tab — so 'Display Articles' works from any tab and never affects AIP.
}

/* ── Display Articles filter (articles are stored as pages; hidden by default) ── */
(function initPmArticleFilter(){
  var cb = document.getElementById('pm-show-articles');
  var grid = document.getElementById('pm-cards-grid');
  if (!cb || !grid) return;
  var on = false;
  try { on = localStorage.getItem('pm_show_articles') === '1'; } catch(e){}
  cb.checked = on;
  grid.classList.toggle('pm-articles-hidden', !on);
  cb.addEventListener('change', function(){
    try { localStorage.setItem('pm_show_articles', cb.checked ? '1' : '0'); } catch(e){}
    grid.classList.toggle('pm-articles-hidden', !cb.checked);
  });
})();

/* ── Global page width — site-wide max content width for every page + article ── */
(function initPmGlobalWidth(){
  var sel = document.getElementById('edit-global-page-width');
  var px  = document.getElementById('edit-global-page-width-px');
  var st  = document.getElementById('edit-global-page-width-status');
  if (!sel) return;
  function flash(msg, ok){
    if (!st) return;
    st.textContent = msg;
    st.className = 'pm-global-width-status ' + (ok ? 'is-ok' : 'is-error');
    setTimeout(function(){ st.textContent = ''; st.className = 'pm-global-width-status'; }, 2200);
  }
  function save(value){
    var fd = new FormData();
    fd.append('__pm_action', 'set_page_width');
    fd.append('value', value);
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ flash(d.ok ? 'Saved — applies to every page + article' : ('Error: ' + (d.error || 'failed')), !!d.ok); })
      .catch(function(){ flash('Network error', false); });
  }
  sel.addEventListener('change', function(){
    if (sel.value === 'custom'){ if (px){ px.hidden = false; px.focus(); } return; }
    if (px) px.hidden = true;
    save(sel.value);
  });
  if (px){
    var commit = function(){ var v = parseInt(px.value, 10); if (!v) return; save(String(v)); };
    px.addEventListener('change', commit);
    px.addEventListener('blur', commit);
  }
})();

/* ── Content-injection defaults (right-column providers per content type) ── */
(function initPmInjectionDefaults(){
  var tbl = document.getElementById('pm-ci-defaults');
  var st  = document.getElementById('pm-ci-status');
  if (!tbl) return;
  function flash(msg, ok){
    if (!st) return;
    st.textContent = msg;
    st.className = 'pm-global-width-status ' + (ok ? 'is-ok' : 'is-error');
    setTimeout(function(){ st.textContent = ''; st.className = 'pm-global-width-status'; }, 2200);
  }
  tbl.addEventListener('change', function(e){
    var ctl = e.target.closest('.pm-ci-cb, .pm-ci-cols'); if (!ctl) return;
    var row = ctl.closest('tr[data-ctype]'); if (!row) return;
    var ctype = row.dataset.ctype;
    var aff = row.querySelector('.pm-ci-cb[data-prov="affiliate"]').checked;
    var ucs = row.querySelector('.pm-ci-cb[data-prov="ucs"]').checked;
    var colsEl = row.querySelector('.pm-ci-cols');
    var cols = colsEl ? colsEl.value : '1';
    var fd = new FormData();
    fd.append('__pm_action', 'set_injection_defaults');
    fd.append('ctype', ctype);
    fd.append('affiliate', aff ? '1' : '0');
    fd.append('ucs', ucs ? '1' : '0');
    fd.append('columns', cols);
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ flash(d.ok ? (ctype.charAt(0).toUpperCase()+ctype.slice(1)+' defaults saved') : ('Error: '+(d.error||'failed')), !!d.ok); })
      .catch(function(){ flash('Network error', false); });
  });
  // UCS stack selector — which content stack the UCS provider injects
  var ucsSel = document.getElementById('pm-ucs-slug');
  var ucsSt  = document.getElementById('pm-ucs-slug-status');
  if (ucsSel) ucsSel.addEventListener('change', function(){
    var fd = new FormData(); fd.append('__pm_action', 'set_ucs_slug'); fd.append('slug', ucsSel.value);
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ if (ucsSt){ ucsSt.textContent = d.ok ? 'UCS stack saved' : ('Error: '+(d.error||'failed')); ucsSt.className = 'pm-global-width-status ' + (d.ok?'is-ok':'is-error'); setTimeout(function(){ ucsSt.textContent=''; ucsSt.className='pm-global-width-status'; }, 2200); } })
      .catch(function(){ if (ucsSt){ ucsSt.textContent='Network error'; ucsSt.className='pm-global-width-status is-error'; } });
  });
})();

/* ── Per-card injection quick-toggle (🛒 affiliate / 🧩 UCS) — no editor round-trip ── */
document.addEventListener('click', function(e){
  var chip = e.target.closest('.js-inject-toggle'); if (!chip) return;
  e.preventDefault(); e.stopPropagation();
  var next = chip.classList.contains('is-on') ? 'off' : 'on';
  chip.disabled = true;
  var fd = new FormData();
  fd.append('__pm_action', 'set_item_injection');
  fd.append('slug', chip.dataset.slug);
  fd.append('field', chip.dataset.field);
  fd.append('value', next);
  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.ok) chip.classList.toggle('is-on', next === 'on'); })
    .catch(function(){})
    .then(function(){ chip.disabled = false; });
});

/* ── AIP Page Delete ── */
document.querySelectorAll('.js-aip-delete').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    const slug = this.dataset.slug;
    const title = this.dataset.title;
    if (!confirm('Delete AI page "' + title + '"? This cannot be undone.')) return;
    fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'delete_aip_page', slug: slug})
    })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        this.closest('.pm-preview-card').remove();
        const aipCount = document.querySelectorAll('#pm-cards-grid .pm-preview-card[data-type="aip"]').length;
        const pageCount = document.querySelectorAll('#pm-cards-grid .pm-preview-card[data-type="page"]').length;
        document.querySelector('.pm-tab-btn[data-tab="aip"]').textContent = 'AI Pages (' + aipCount + ')';
        document.querySelector('.pm-tab-btn[data-tab="pages"]').textContent = 'Pages (' + pageCount + ')';
        document.querySelector('.pm-tab-btn[data-tab="all"]').textContent = 'All (' + (aipCount + pageCount) + ')';
      } else {
        alert('Error: ' + (d.error || 'Unknown'));
      }
    })
    .catch(() => alert('Network error'));
  });
});
