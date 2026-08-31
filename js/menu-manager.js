/**
 * Menu Manager Preview Binder
 * @file    /admin/js/menu-manager.js
 * @version 2025.09.10.r2
 *
 * Purpose:
 * - Instantly mirror control changes into the Demo Menu (no save)
 * - Use same tiny engine for Body Text preview
 * - Never touches :root; only writes CSS vars on local preview scopes
 *
 * HTML requirements (IDs):
 *   Menu scope:  #mmPreviewScope.preview-scope
 *   Body scope:  #bodyPreviewScope.preview-scope
 *   Optional var echo: #mmVarEcho
 *
 *   Menu inputs:
 *     #mmFontFamily (select or text)
 *     #mmFontSize   (number/text; value in rem will be composed)
 *     #mmFontColor  (color/text)
 *     #mmTextTransform (select: inherit/uppercase/lowercase/capitalize)
 *     #mmBgTransparent (checkbox)
 *     #mmBgColor    (color/text)
 *     #mmBgOpacity  (range 0..1 or 0..100; handles both)
 *
 *   Body inputs:
 *     #bodyFontFamily
 *     #bodyColor
 *     #bodyLinkColor
 *     #bodyVisitedColor
 *     #bodyLineHeight
 *     #bodyTextTransform
 */

(function(){
  function $(id){ return document.getElementById(id); }

  // Expand #rgb / #rrggbb + alpha to rgba(r,g,b,a)
  function toRgba(hex, a){
    if (!hex) return 'rgba(0,0,0,0)';
    hex = String(hex).trim().replace('#','');
    const dup = s => s.length===1 ? s+s : s;
    let r,g,b;
    if (hex.length===3){
      r = parseInt(dup(hex[0]),16);
      g = parseInt(dup(hex[1]),16);
      b = parseInt(dup(hex[2]),16);
    } else {
      r = parseInt(hex.slice(0,2),16);
      g = parseInt(hex.slice(2,4),16);
      b = parseInt(hex.slice(4,6),16);
    }
    // Allow 0..1 or 0..100 sliders
    let A = parseFloat(a||0);
    if (A>1) A = A/100;
    A = Math.max(0, Math.min(1, A));
    return `rgba(${r},${g},${b},${A})`;
  }

  function bindPreview(opts){
    const scopeEl = document.querySelector(opts.scope);
    if (!scopeEl) return;
    const echoEl  = opts.echo ? document.querySelector(opts.echo) : null;

    // Collect elements (safe if missing)
    const els = {};
    Object.keys(opts.map).forEach(id => els[id] = $(id));

    function apply(){
      const style = scopeEl.style;
      const lines = [];

      for (const id in opts.map){
        const conf = opts.map[id];
        const el = els[id];
        if (!el) continue;

        const raw = (el.type === 'checkbox') ? el.checked : el.value;

        if (Array.isArray(conf)){
          const [varName, transform] = conf;
          const v = (typeof transform === 'function') ? transform(raw, els) : raw;
          style.setProperty(varName, v);
          lines.push(`${varName}: ${v}`);
        } else {
          style.setProperty(conf, raw);
          lines.push(`${conf}: ${raw}`);
        }
      }

      if (echoEl) echoEl.textContent = lines.join('; ') + ';';
    }

    // Repaint on input
    Object.values(els).forEach(el => el && el.addEventListener('input', apply));
    // First paint
    apply();
  }

  // Kick in when DOM is ready
  document.addEventListener('DOMContentLoaded', function(){

    // MENU preview
    bindPreview({
      scope: '#mmPreviewScope',
      map: {
        mmFontFamily:    '--menu-font-family',
        mmFontSize:      ['--menu-font-size', v => `${(+v||1).toFixed(2)}rem`],
        mmFontColor:     '--menu-font-color',
        mmTextTransform: '--menu-text-transform',
        // rgba composer for background
        mmBgTransparent: ['--menu-bar-bg', (checked, els) =>
          checked ? 'rgba(0,0,0,0)' : toRgba(els.mmBgColor?.value, els.mmBgOpacity?.value)
        ],
        mmBgColor:       ['--menu-bar-bg', (_v, els) =>
          (els.mmBgTransparent?.checked ? 'rgba(0,0,0,0)' : toRgba(els.mmBgColor?.value, els.mmBgOpacity?.value))
        ],
        mmBgOpacity:     ['--menu-bar-bg', (_v, els) =>
          (els.mmBgTransparent?.checked ? 'rgba(0,0,0,0)' : toRgba(els.mmBgColor?.value, els.mmBgOpacity?.value))
        ],
      },
      echo: '#mmVarEcho'
    });

    // BODY preview
    bindPreview({
      scope: '#bodyPreviewScope',
      map: {
        bodyFontFamily:    '--body-font-family',
        bodyColor:         '--body-color',
        bodyLinkColor:     '--body-link-color',
        bodyVisitedColor:  '--body-visited-color',
        bodyLineHeight:    '--body-line-height',
        bodyTextTransform: '--body-text-transform',
      }
    });

  });
})();
