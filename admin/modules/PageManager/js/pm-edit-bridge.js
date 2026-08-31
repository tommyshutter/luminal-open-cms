/**
 * Page Manager — Edit Frame Bridge
 *
 * Runs inside /admin/modules/PageManager/edit_frame.php. Turns the content
 * zone into a contenteditable region, disables navigation, and proxies
 * edits + commands between the edit iframe and the Page Manager modal.
 *
 * Parent → iframe messages:
 *   { type: 'pm-get' }                           → reply with current html
 *   { type: 'pm-set',  html }                    → replace zone html
 *   { type: 'pm-exec', cmd, arg }                → document.execCommand
 *   { type: 'pm-focus' }                         → focus zone
 *
 * Iframe → parent messages:
 *   { type: 'pm-ready', col, slug }              → on load
 *   { type: 'pm-content', col, slug, html }      → reply to pm-get + autosync
 *   { type: 'pm-dirty',  col, slug }             → on any input (throttled)
 */
(function () {
  'use strict';

  var zone = document.getElementById('pmef-zone');
  if (!zone) return;
  var body = document.body;
  var slug = body.getAttribute('data-pm-edit-slug') || '';
  var col  = body.getAttribute('data-pm-edit-col')  || 'left';

  /* ---- Disable navigation (clicks to anchors, form submits) ---- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (a) { e.preventDefault(); e.stopPropagation(); }
  }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  /* ===== Page-Builder: [[section]] → live widget + collapsible control bar =====
   * Decorate on load (source → widget), serialize on read (widget → source). Sections only,
   * so nested shortcodes stay as raw text inside and can never be corrupted. The border/bar
   * are editor-only chrome (CSS in edit_frame.php); the surface mirrors the live render. */
  var PMEF_PRESETS = {
    base:  { op: 0.45, r: 8,  blur: 0 },
    apple: { op: 0.55, r: 18, blur: 14 },
    auria: { op: 0.50, r: 16, blur: 14 }
  };
  function pmefEnc(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/\[/g,'&#91;').replace(/\]/g,'&#93;'); }
  function pmefDec(s){ return String(s).replace(/&#91;/g,'[').replace(/&#93;/g,']').replace(/&quot;/g,'"').replace(/&amp;/g,'&'); }
  function pmefAttrs(str){ var a={}, re=/([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/g, m; while ((m = re.exec(str))) a[m[1].toLowerCase()] = m[2]; return a; }
  function pmefAttrStr(a){ var s=''; ['preset','opacity','radius','blur','shadow','shadow_color','shadow_opacity','shadow_height','shadow_angle','glow','border','border_color','width','class'].forEach(function(k){ if (a[k]!=null && a[k]!=='') s+=' '+k+'="'+String(a[k]).replace(/"/g,'')+'"'; }); return s.trim(); }
  function pmefBool(v){ return v!=null && v!=='' && ['0','false','no','off'].indexOf(String(v).toLowerCase())===-1; }
  function pmefHexRgba(hex, a){
    hex = String(hex||'#000000').replace('#','');
    if (hex.length===3) hex = hex.charAt(0)+hex.charAt(0)+hex.charAt(1)+hex.charAt(1)+hex.charAt(2)+hex.charAt(2);
    var r=parseInt(hex.substr(0,2),16)||0, g=parseInt(hex.substr(2,2),16)||0, b=parseInt(hex.substr(4,2),16)||0;
    return 'rgba('+r+','+g+','+b+','+a+')';
  }
  // Mirror of PHP text_style_shadow_value() — keep the two in lockstep.
  function pmefShadowValue(a, presetName){
    var sh = (a.shadow!=null && a.shadow!=='') ? pmefBool(a.shadow) : (presetName==='apple' || presetName==='auria');
    if (!sh) return '';
    var custom = (a.shadow_color||a.shadow_height||a.shadow_angle||a.shadow_opacity);
    if (!custom) return '0 10px 34px rgba(0,0,0,0.45)';
    var col = /^#[0-9a-fA-F]{3,8}$/.test(a.shadow_color||'') ? a.shadow_color : '#000000';
    var sop = (a.shadow_opacity!=null && a.shadow_opacity!=='') ? (parseFloat(a.shadow_opacity)>1?parseFloat(a.shadow_opacity)/100:parseFloat(a.shadow_opacity)) : 0.45;
    var h   = (a.shadow_height!=null && a.shadow_height!=='') ? parseInt(a.shadow_height,10) : 10;
    var ang = (a.shadow_angle!=null && a.shadow_angle!=='') ? parseInt(a.shadow_angle,10) : 135;
    var rad = ang*Math.PI/180;
    var ox=Math.round(h*Math.cos(rad)), oy=Math.round(h*Math.sin(rad)), bl=Math.round(h*2.4);
    return ox+'px '+oy+'px '+bl+'px '+pmefHexRgba(col, sop);
  }
  function pmefSurface(a){
    var pn = (a.preset||'base').toLowerCase();
    var p  = PMEF_PRESETS[pn] || PMEF_PRESETS.base;
    var op = (a.opacity!=null && a.opacity!=='') ? (parseFloat(a.opacity)>1?parseFloat(a.opacity)/100:parseFloat(a.opacity)) : p.op;
    var r  = (a.radius!=null && a.radius!=='') ? parseInt(a.radius,10) : p.r;
    var bl = (a.blur!=null && a.blur!=='') ? parseInt(a.blur,10) : (p.blur||0);
    var s = 'background:rgba(0,0,0,'+op+');border-radius:'+r+'px;padding:clamp(14px,3vw,32px)';
    if (bl>0) s += ';backdrop-filter:blur('+bl+'px);-webkit-backdrop-filter:blur('+bl+'px)';
    var sv = pmefShadowValue(a, pn);
    if (sv) s += ';box-shadow:'+sv;
    if (pmefBool(a.border)) s += ';border:1px solid '+(a.border_color||'#8899aa');   // OFF by default
    if (a.width){ var w = /^\d+$/.test(a.width) ? a.width+'px' : a.width; s += ';max-width:'+w+';margin-inline:auto'; }
    return s;
  }
  function pmefWidget(a, inner){
    var bp = (a.preset||'base').toLowerCase();
    var opPct = (a.opacity!=null && a.opacity!=='') ? Math.round((parseFloat(a.opacity)>1?parseFloat(a.opacity)/100:parseFloat(a.opacity))*100) : Math.round((PMEF_PRESETS[bp]||PMEF_PRESETS.base).op*100);
    var shOn = (a.shadow!=null && a.shadow!=='') ? pmefBool(a.shadow) : (bp==='apple'||bp==='auria');
    var bdOn = pmefBool(a.border);
    var bcHex = (a.border_color && /^#[0-9a-fA-F]{3,8}$/.test(a.border_color)) ? a.border_color : '#8899aa';
    var shcHex = (a.shadow_color && /^#[0-9a-fA-F]{3,8}$/.test(a.shadow_color)) ? a.shadow_color : '#000000';
    var shoPct = (a.shadow_opacity!=null && a.shadow_opacity!=='') ? Math.round((parseFloat(a.shadow_opacity)>1?parseFloat(a.shadow_opacity)/100:parseFloat(a.shadow_opacity))*100) : 45;
    var shH   = (a.shadow_height!=null && a.shadow_height!=='') ? parseInt(a.shadow_height,10) : 10;
    var shAng = (a.shadow_angle!=null && a.shadow_angle!=='') ? parseInt(a.shadow_angle,10) : 135;
    return '<div class="pmef-sc pmef-section" contenteditable="false" data-sc-attrs="'+pmefEnc(pmefAttrStr(a))+'" style="'+pmefSurface(a)+'">'
      + '<div class="pmef-sc-bar" contenteditable="false">'
        + '<span class="pmef-sc-tag">SECTION</span>'
        + '<span class="pmef-sc-tools">'
          + '<select class="pmef-t-preset" title="Preset"><option value="base">Base</option><option value="apple">Apple</option><option value="auria">Auria</option></select>'
          + '<label>Op <input type="range" class="pmef-t-op" min="0" max="100" value="'+opPct+'"><span class="pmef-t-opv">'+opPct+'%</span></label>'
          + '<label>W <input type="text" class="pmef-t-w" size="5" placeholder="auto" value="'+(a.width||'')+'"></label>'
          + '<label><input type="checkbox" class="pmef-t-sh"'+(shOn?' checked':'')+'> Shadow</label>'
          + '<span class="pmef-sh-group"'+(shOn?'':' style="display:none"')+' title="Drop-shadow shaping">'
            + '<input type="color" class="pmef-t-shc" value="'+shcHex+'" title="Shadow color">'
            + '<label title="Shadow opacity">&#945; <input type="range" class="pmef-t-sho" min="0" max="100" value="'+shoPct+'"><span class="pmef-t-shov">'+shoPct+'%</span></label>'
            + '<label title="Shadow height / distance">H <input type="range" class="pmef-t-shh" min="0" max="60" value="'+shH+'"><span class="pmef-t-shhv">'+shH+'</span></label>'
            + '<label title="Shadow angle (135&deg; = SW, below-left)">&#8736; <input type="range" class="pmef-t-sha" min="0" max="360" value="'+shAng+'"><span class="pmef-t-shav">'+shAng+'&deg;</span></label>'
          + '</span>'
          + '<label><input type="checkbox" class="pmef-t-bd"'+(bdOn?' checked':'')+'> Border</label>'
          + '<input type="color" class="pmef-t-bc" value="'+bcHex+'" title="Border color">'
        + '</span>'
      + '</div>'
      + '<div class="pmef-sc-inner" contenteditable="true">'+inner+'</div>'
      + '</div>';
  }
  function pmefSyncWidget(w){
    var a = pmefAttrs(pmefDec(w.getAttribute('data-sc-attrs')||''));
    var pr = w.querySelector('.pmef-t-preset'); if (pr) a.preset = pr.value;
    var op = w.querySelector('.pmef-t-op');     if (op) a.opacity = op.value;
    var wd = w.querySelector('.pmef-t-w');       if (wd) { if (wd.value.trim()) a.width = wd.value.trim(); else delete a.width; }
    var sh = w.querySelector('.pmef-t-sh');       if (sh) a.shadow = sh.checked ? '1' : '0';
    var bd = w.querySelector('.pmef-t-bd');       if (bd) a.border = bd.checked ? '1' : '0';
    var bc = w.querySelector('.pmef-t-bc');       if (bc) a.border_color = bc.value;
    // Shadow shaping — only serialize when Shadow is on (keeps attrs clean when off).
    var shc = w.querySelector('.pmef-t-shc'), sho = w.querySelector('.pmef-t-sho'),
        shh = w.querySelector('.pmef-t-shh'), sha = w.querySelector('.pmef-t-sha');
    if (sh && sh.checked) {
      if (shc) a.shadow_color   = shc.value;
      if (sho) a.shadow_opacity = sho.value;
      if (shh) a.shadow_height  = shh.value;
      if (sha) a.shadow_angle   = sha.value;
    } else {
      delete a.shadow_color; delete a.shadow_opacity; delete a.shadow_height; delete a.shadow_angle;
    }
    var grp = w.querySelector('.pmef-sh-group'); if (grp && sh) grp.style.display = sh.checked ? '' : 'none';
    var shov = w.querySelector('.pmef-t-shov'); if (shov && sho) shov.textContent = sho.value + '%';
    var shhv = w.querySelector('.pmef-t-shhv'); if (shhv && shh) shhv.textContent = shh.value;
    var shav = w.querySelector('.pmef-t-shav'); if (shav && sha) shav.textContent = sha.value + '°';
    var opv = w.querySelector('.pmef-t-opv');     if (opv && op) opv.textContent = op.value + '%';
    w.setAttribute('data-sc-attrs', pmefEnc(pmefAttrStr(a)));
    w.setAttribute('style', pmefSurface(a));   // re-apply box surface (bar keeps its own layout)
    scheduleDirty();
  }
  function pmefWire(){
    var list = zone.querySelectorAll('.pmef-section');
    for (var i=0;i<list.length;i++){ (function(w){
      if (w.__pmefWired) return; w.__pmefWired = true;
      var a = pmefAttrs(pmefDec(w.getAttribute('data-sc-attrs')||''));
      var pr = w.querySelector('.pmef-t-preset'); if (pr && a.preset) pr.value = a.preset;
      ['.pmef-t-preset','.pmef-t-op','.pmef-t-w','.pmef-t-sh','.pmef-t-shc','.pmef-t-sho','.pmef-t-shh','.pmef-t-sha','.pmef-t-bd','.pmef-t-bc'].forEach(function(sel){
        var el = w.querySelector(sel); if (!el) return;
        el.addEventListener('input',  function(){ pmefSyncWidget(w); });
        el.addEventListener('change', function(){ pmefSyncWidget(w); });
      });
    })(list[i]); }
  }
  /* ===== Self-contained shortcodes → locked "chip" (label + on-demand live preview) =====
   * Every [[name …]] that isn't [[section]] becomes a contenteditable=false chip so it reads
   * as an object, not raw text. 👁 fetches the real render inline; ✎ un-chips to edit source;
   * ✕ removes it. Serialize turns chips back into the exact raw token. */
  var PMEF_SC_ICONS = {
    'html-block':'▣','htmlblock':'▣','block':'▣',
    'youtube-playlist':'▶','yt-playlist':'▶','youtube-video':'▶','yt-video':'▶',
    'image-gallery':'▦','imagegallery':'▦','video-gallery':'▦','videogallery':'▦','pdf-gallery':'▦','pdfgallery':'▦','gallery':'▦',
    'articles-grid':'▤','article-showcase':'▤','articles-magazine':'▤',
    'podcast-feed':'♫','podcasts':'♫','podcast-episodes':'♫','podradio':'♫','podradio-station':'♫',
    'event':'◷','upcoming-shows':'◷','emp-events':'◷','upcoming-events':'◷',
    'stack':'▥','content-stack':'▥','panel':'▥',
    'artist-directory':'☷','aip':'✦','dashboard-stats':'▧'
  };
  function pmefScParse(tok){
    var m = /^\[\[\s*([a-z0-9][a-z0-9_-]*)((?::[a-z0-9_-]+)?)([^\]]*)\]\]$/i.exec(tok);
    if (!m) return { name: tok, sub:'', attrs:'' };
    return { name: m[1].toLowerCase(), sub: (m[2]||'').replace(/^:/,''), attrs: (m[3]||'').trim() };
  }
  function pmefChipMeta(p){
    var bits = [];
    if (p.sub) bits.push(p.sub);
    var a = pmefAttrs(p.attrs);
    ['id','slug','cols','preset','name','category','tag'].forEach(function(k){ if (a[k] && bits.indexOf(a[k])===-1) bits.push(k+'='+a[k]); });
    return bits.slice(0,3).join(' · ');
  }
  function pmefChipHtml(tok){
    var p = pmefScParse(tok);
    var ico = PMEF_SC_ICONS[p.name] || PMEF_SC_ICONS[(p.name+'').replace(/s$/,'')] || '◈';
    var meta = pmefChipMeta(p);
    return '<span class="pmef-chip" contenteditable="false" data-sc="'+pmefEnc(tok)+'" title="Shortcode: '+p.name+(p.sub?':'+p.sub:'')+'">'
      + '<span class="pmef-chip-h">'
        + '<span class="pmef-chip-ico">'+ico+'</span>'
        + '<span class="pmef-chip-name">'+p.name+(p.sub?'<span class="pmef-chip-sub">:'+p.sub+'</span>':'')+'</span>'
        + (meta?'<span class="pmef-chip-meta">'+meta.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</span>':'')
        + '<span class="pmef-chip-btns">'
          + '<button type="button" class="pmef-chip-eye"  title="Toggle live preview">&#128065;</button>'
          + '<button type="button" class="pmef-chip-edit" title="Edit as source text">&#9998;</button>'
          + '<button type="button" class="pmef-chip-del"  title="Remove">&#10005;</button>'
        + '</span>'
      + '</span>'
      + '<span class="pmef-chip-render" hidden></span>'
      + '</span>';
  }
  var PMEF_CHIP_RE = /\[\[(?!\s*\/)(?!\s*section\b)\s*[a-z0-9][a-z0-9_-]*(?::[a-z0-9_-]+)?[^\]]*\]\]/gi;
  function pmefChipRender(chip){
    var box = chip.querySelector('.pmef-chip-render'); if (!box) return;
    if (!box.hidden){ box.hidden = true; box.innerHTML = ''; chip.classList.remove('pmef-chip-open'); return; }
    var tok = pmefDec(chip.getAttribute('data-sc')||'');
    box.innerHTML = '<span class="pmef-chip-loading">rendering…</span>';
    box.hidden = false; chip.classList.add('pmef-chip-open');
    try {
      var fd = new FormData(); fd.append('code', tok);
      fetch('/admin/modules/PageManager/api/render-shortcode.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.ok && j.rendered && j.html){ box.innerHTML = j.html; }
          else { box.innerHTML = '<span class="pmef-chip-empty">No inline preview — this one renders from live/page context. Use the Preview pane.</span>'; }
        })
        .catch(function(){ box.innerHTML = '<span class="pmef-chip-empty">Preview failed.</span>'; });
    } catch(_){ box.innerHTML = '<span class="pmef-chip-empty">Preview unavailable.</span>'; }
  }
  function pmefChipEdit(chip){
    var tok = pmefDec(chip.getAttribute('data-sc')||'');
    var p = pmefScParse(tok);
    // Stacks open the real ContentStacks builder in the parent (in-place iframe modal).
    if ((p.name === 'stack' || p.name === 'content-stack') && p.sub){ post({ type: 'pm-edit-stack', slug: p.sub }); return; }
    // Everything else: un-chip to raw source for inline editing.
    chip.parentNode.replaceChild(document.createTextNode(tok), chip);
    scheduleDirty();
  }
  function pmefChipWire(){
    var chips = zone.querySelectorAll('.pmef-chip');
    for (var i=0;i<chips.length;i++){ (function(chip){
      if (chip.__pmefWired) return; chip.__pmefWired = true;
      var eye = chip.querySelector('.pmef-chip-eye'), ed = chip.querySelector('.pmef-chip-edit'), del = chip.querySelector('.pmef-chip-del');
      if (eye) eye.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); pmefChipRender(chip); });
      if (ed)  ed.addEventListener('click',  function(e){ e.preventDefault(); e.stopPropagation(); pmefChipEdit(chip); });
      if (del) del.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); chip.parentNode.removeChild(chip); scheduleDirty(); });
    })(chips[i]); }
  }
  function pmefDecorate(){
    if (col === 'css') return;
    var html = zone.innerHTML, touched = false;
    if (html.indexOf('[[section') !== -1){
      html = html.replace(/\[\[section\b([^\]]*)\]\]([\s\S]*?)\[\[\/section\]\]/gi, function(_, at, inner){
        return pmefWidget(pmefAttrs(at), inner);
      });
      touched = true;
    }
    PMEF_CHIP_RE.lastIndex = 0;
    if (PMEF_CHIP_RE.test(html)){
      PMEF_CHIP_RE.lastIndex = 0;
      html = html.replace(PMEF_CHIP_RE, function(m){ return pmefChipHtml(m); });
      touched = true;
    }
    if (!touched) return;
    zone.innerHTML = html;
    pmefWire();
    pmefChipWire();
  }
  function pmefSerialize(){
    var clone = zone.cloneNode(true);
    // chips → exact raw shortcode tokens (leaf-first, so tokens inside section inners restore too)
    var chips = clone.querySelectorAll('.pmef-chip');
    for (var c=0;c<chips.length;c++){
      chips[c].parentNode.replaceChild(document.createTextNode(pmefDec(chips[c].getAttribute('data-sc')||'')), chips[c]);
    }
    var srcs = [];
    var secs = clone.querySelectorAll('.pmef-section');
    for (var i=0;i<secs.length;i++){
      var w = secs[i];
      var attrs = pmefDec(w.getAttribute('data-sc-attrs')||'');
      var inner = w.querySelector('.pmef-sc-inner');
      var src = '[[section'+(attrs?' '+attrs:'')+']]\n' + (inner ? inner.innerHTML : '') + '\n[[/section]]';
      var marker = 'S'+srcs.length+''; srcs.push(src);
      w.parentNode.replaceChild(document.createTextNode(marker), w);
    }
    var out = clone.innerHTML, guard = 0;
    while (/S\d+/.test(out) && guard++ < 60) {
      out = out.replace(/S(\d+)/g, function(_, i){ return srcs[+i]; });
    }
    return out;
  }

  /* ---- Get/send helpers ---- */
  function currentHtml() {
    if (col === 'css') return zone.textContent || '';
    return pmefSerialize();
  }
  function post(msg) {
    try { parent.postMessage(msg, '*'); } catch (_) {}
  }

  /* ---- Dirty notifications (debounced) ---- */
  var dirtyTimer = null;
  function scheduleDirty() {
    if (dirtyTimer) clearTimeout(dirtyTimer);
    dirtyTimer = setTimeout(function () {
      post({ type: 'pm-content', col: col, slug: slug, html: currentHtml() });
    }, 180);
  }
  zone.addEventListener('input', scheduleDirty);
  zone.addEventListener('paste', function () { setTimeout(scheduleDirty, 0); });
  zone.addEventListener('keyup',  scheduleDirty);

  /* Decorate any [[section]]s present on initial (server-rendered) load. */
  try { pmefDecorate(); } catch (_) {}

  /* ---- Parent → iframe commands ---- */
  window.addEventListener('message', function (ev) {
    var d = ev && ev.data;
    if (!d || typeof d !== 'object' || !d.type) return;
    switch (d.type) {
      case 'pm-get':
        post({ type: 'pm-content', col: col, slug: slug, html: currentHtml() });
        break;
      case 'pm-set':
        if (typeof d.html === 'string') {
          if (col === 'css') zone.textContent = d.html;
          else { zone.innerHTML = d.html; pmefDecorate(); }
        }
        break;
      case 'pm-exec':
        if (col === 'css') return;
        try {
          zone.focus();
          document.execCommand(d.cmd || '', false, d.arg || null);
          scheduleDirty();
        } catch (_) {}
        break;
      case 'pm-focus':
        try { zone.focus(); } catch (_) {}
        break;
    }
  });

  /* ---- Explorer drag-and-drop → insert at the drop point ----
   * Accepts the Insert Explorer's x-mb-drag payloads: media files become
   * the right tag (img/video/audio/pdf link), shortcode catalog items
   * insert their [[code]]. preventDefault stops the browser's native
   * text/plain drop (raw sentinel payload). CSS column: text only. */
  function mediaTag(path) {
    var ext = path.split('.').pop().toLowerCase();
    if (['mp4','webm','mov','ogg','m4v'].indexOf(ext) >= 0) return '<video src="' + path + '" controls style="max-width:100%;border-radius:6px"></video>';
    if (['mp3','wav','m4a','aac'].indexOf(ext) >= 0)        return '<audio src="' + path + '" controls style="width:100%"></audio>';
    if (ext === 'pdf')                                       return '<a href="' + path + '" target="_blank">' + path.split('/').pop() + '</a>';
    return '<img src="' + path + '" alt="" style="max-width:100%">';
  }
  function dragHtml(dt) {
    var raw = dt.getData('application/x-mb-drag');
    if (!raw) {
      var tp = dt.getData('text/plain') || '';
      if (tp.indexOf('__mb_drag__\n') === 0) raw = tp.slice(11);
      else return null;                       // not an Explorer drag
    }
    try {
      var p = JSON.parse(raw);
      if (p.kind === 'shortcode' && p.code) return p.code;
      if (p.paths && p.paths.length) return p.paths.map(function (path) { return mediaTag('/' + String(path).replace(/^\//, '')); }).join('\n');
    } catch (_) {}
    return null;
  }
  function caretToPoint(x, y) {
    try {
      var range = null;
      if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(x, y);
      else if (document.caretPositionFromPoint) {
        var cp = document.caretPositionFromPoint(x, y);
        if (cp) { range = document.createRange(); range.setStart(cp.offsetNode, cp.offset); range.collapse(true); }
      }
      if (range && zone.contains(range.startContainer)) {
        var sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);
        return true;
      }
    } catch (_) {}
    return false;
  }
  zone.addEventListener('dragover', function (e) {
    if (e.dataTransfer && e.dataTransfer.types.indexOf('application/x-mb-drag') >= 0) {
      e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; zone.classList.add('pmef-drop-armed');
    }
  });
  zone.addEventListener('dragleave', function () { zone.classList.remove('pmef-drop-armed'); });
  zone.addEventListener('drop', function (e) {
    zone.classList.remove('pmef-drop-armed');
    var html = e.dataTransfer ? dragHtml(e.dataTransfer) : null;
    if (html === null) return;                 // let native drops behave
    e.preventDefault();
    zone.focus();
    caretToPoint(e.clientX, e.clientY);        // best effort — falls back to current caret
    try {
      if (col === 'css') document.execCommand('insertText', false, html);
      else document.execCommand('insertHTML', false, html);
      scheduleDirty();
    } catch (_) {}
  });

  /* ---- Per-media Lightbox toggle (right-click a manually-added image/video) ----
   * Enabling wraps the <img>/<video> in <a data-lightbox="image|video" href="{src}">
   * — the exact contract the public lightbox.js binds to ([data-lightbox]). The
   * wrap lives in zone.innerHTML, so it persists on save. No public lightbox JS
   * runs inside this editor, so there is no collision with the front-end behavior.
   * In the editor the only <img>/<video>s present are manually-added ones (gallery
   * shortcodes stay literal [[…]] text until render), so this is always safe.
   * For video, inline controls are stripped on enable (restored on disable) so the
   * whole anchor is one lightbox trigger — otherwise the player swallows clicks. */
  var LB_MENU_ID = 'pmef-img-menu';

  function closeImgMenu() {
    var m = document.getElementById(LB_MENU_ID);
    if (m && m.parentNode) m.parentNode.removeChild(m);
    document.removeEventListener('mousedown', onDocDownCloseMenu, true);
    document.removeEventListener('keydown', onMenuKey, true);
    window.removeEventListener('scroll', closeImgMenu, true);
  }
  function onDocDownCloseMenu(e) {
    var m = document.getElementById(LB_MENU_ID);
    if (m && !m.contains(e.target)) closeImgMenu();
  }
  function onMenuKey(e) { if (e.key === 'Escape') closeImgMenu(); }

  function lbAnchorFor(el) {
    var p = el.parentNode;
    return (p && p.tagName === 'A') ? p : null;
  }
  function isLightboxOn(el) {
    var a = lbAnchorFor(el);
    return !!(a && a.hasAttribute('data-lightbox'));
  }
  function lbType(el) { return el.tagName === 'VIDEO' ? 'video' : 'image'; }

  function enableLightbox(el) {
    var src = el.getAttribute('src') || '';
    var type = lbType(el);
    var a = lbAnchorFor(el);
    if (a) {
      // Media already sits in a link — keep the link, add lightbox behavior.
      if (!a.getAttribute('href')) a.setAttribute('href', src);
      a.setAttribute('data-lightbox', type);
    } else {
      a = document.createElement('a');
      a.className = 'pm-lightbox';            // our marker → safe to unwrap later
      a.setAttribute('href', src);
      a.setAttribute('data-lightbox', type);
      el.parentNode.insertBefore(a, el);
      a.appendChild(el);
    }
    if (type === 'video') {
      // Turn the inline player into a click-to-open poster so the whole anchor
      // is one lightbox trigger (inline controls would swallow the click).
      el.removeAttribute('controls');
      el.setAttribute('preload', 'metadata');
      el.setAttribute('muted', '');
      el.muted = true;
      el.setAttribute('playsinline', '');
    }
    scheduleDirty();
  }
  function disableLightbox(el) {
    var a = lbAnchorFor(el);
    if (!a) return;
    if (el.tagName === 'VIDEO') {
      el.setAttribute('controls', '');       // restore inline playback
      el.removeAttribute('muted');
      el.muted = false;
    }
    if (a.classList.contains('pm-lightbox')) {
      // We created this wrapper solely for the lightbox — unwrap back to the media.
      a.parentNode.insertBefore(el, a);
      a.parentNode.removeChild(a);
    } else {
      // Pre-existing link — keep the link, just drop the lightbox behavior.
      a.removeAttribute('data-lightbox');
    }
    scheduleDirty();
  }

  function openMediaMenu(el, x, y) {
    closeImgMenu();
    var on = isLightboxOn(el);
    var menu = document.createElement('div');
    menu.id = LB_MENU_ID;
    menu.className = 'pmef-img-menu';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pmef-img-menu-item';
    btn.textContent = on ? '🔦  Disable Lightbox' : '🔦  Enable Lightbox';
    btn.addEventListener('click', function () {
      if (on) disableLightbox(el); else enableLightbox(el);
      closeImgMenu();
    });
    menu.appendChild(btn);
    document.body.appendChild(menu);
    var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    menu.style.left = Math.max(4, Math.min(x, vw - mw - 8)) + 'px';
    menu.style.top  = Math.max(4, Math.min(y, vh - mh - 8)) + 'px';
    setTimeout(function () {
      document.addEventListener('mousedown', onDocDownCloseMenu, true);
      document.addEventListener('keydown', onMenuKey, true);
      window.addEventListener('scroll', closeImgMenu, true);
    }, 0);
  }

  zone.addEventListener('contextmenu', function (e) {
    var t = e.target;
    var media = (t && (t.tagName === 'IMG' || t.tagName === 'VIDEO')) ? t : null;
    if (!media && t && t.closest) {
      var a = t.closest('a');
      if (a) media = a.querySelector('img, video');
    }
    if (!media) return;   // not media → allow the native context menu
    e.preventDefault();
    openMediaMenu(media, e.clientX, e.clientY);
  });

  /* ---- Announce readiness + prime parent's buffer with initial content ---- */
  post({ type: 'pm-ready', col: col, slug: slug });
  post({ type: 'pm-content', col: col, slug: slug, html: currentHtml() });
})();
