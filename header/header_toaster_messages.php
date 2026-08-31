<?php
/**
 * Header Toaster (Static Top Bar)
 * @file    /header/header_toaster_messages.php
 * @version 2025.09.07.r13
 *
 * Changes:
 *  - Renders as a normal block at the very top (NOT fixed/overlay).
 *  - Append-only log (no truncating last line), keeps up to tailSize entries (default 500).
 *  - Controls: Clear, Pause/Resume, Copy, Auto-Scroll toggle.
 *  - Small Arial font, wider/taller viewport.
 */

declare(strict_types=1);
?>
<style>
  /* Static top bar (full width, not overlay) */
  #ri-toaster-wrap {
    position: relative; /* normal flow */
    width: 100%;
    box-sizing: border-box;
    background: #0a0a0a;
    border-bottom: 1px solid rgba(255,255,255,.15);
    padding: 8px 10px 10px;
    margin: 0;          /* sits above page content */
    z-index: 3;         /* above page content, below overlays */
  }
  #ri-toaster-controls {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
    margin-bottom: 6px;
  }
  #ri-toaster-controls button,
  #ri-toaster-controls label {
    font-family: Arial; font-size: 12px; line-height: 1;
    background: #1e1e1e; color:#dcdcdc; border: 1px solid #333; border-radius: 6px;
    padding: 6px 10px; cursor: pointer;
  }
  #ri-toaster-controls input[type="number"]{
    width: 72px; padding: 4px 6px; background:#111; color:#eee; border:1px solid #333; border-radius:6px;
    font-family: Arial; font-size: 12px;
  }
  #ri-toaster-log {
    font-family: Arial;               /* per your request */
    font-size: 11px;
    line-height: 1.25;
    color:#e5e5e5;
    background:#0e0e0e;
    border:1px solid rgba(255,255,255,.12);
    border-radius: 8px;
    padding: 8px;
    max-height:200px;                /* taller default */
    overflow: auto;                   /* scroll to review */
    white-space: pre-wrap;            /* wrap long lines */
  }
  .ri-line { margin: 0; }
  .ri-info  { color:#cfe8ff; }
  .ri-warn  { color:#ffd479; }
  .ri-error { color:#ff9393; }
  .ri-debug { color:#9ad39a; }
</style>

<div id="ri-toaster-wrap">
  <div id="ri-toaster-controls">
    <button id="ri-btn-clear" type="button">Clear</button>
    <button id="ri-btn-pause" type="button" data-paused="false">Pause</button>
    <button id="ri-btn-copy"  type="button">Copy</button>
    <label><input type="checkbox" id="ri-autoscroll" checked> Auto-scroll</label>
    <label>Tail: <input id="ri-tail" type="number" min="10" max="5000" step="10" value="500"></label>
  </div>
  <div id="ri-toaster-log" aria-live="polite" aria-atomic="false"></div>
</div>

<script>
(function(){
  // Append-only toaster at top of page (non-overlay).
  const logEl = document.getElementById('ri-toaster-log');
  const btnClear = document.getElementById('ri-btn-clear');
  const btnPause = document.getElementById('ri-btn-pause');
  const btnCopy  = document.getElementById('ri-btn-copy');
  const cbAuto   = document.getElementById('ri-autoscroll');
  const tailInp  = document.getElementById('ri-tail');

  let paused = false;
  let tailSize = parseInt(tailInp.value||'500', 10);
  const buf = []; // append-only buffer

  function pad2(n){ return (n<10?'0':'')+n; }
  function ts(){
    const d = new Date();
    let h=d.getHours(), ampm='AM'; if(h>=12){ampm='PM';} if(h===0)h=12; if(h>12)h-=12;
    return `${pad2(h)}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())} ${ampm}`;
  }

  function cls(level){ return level==='error'?'ri-error':(level==='warn'?'ri-warn':(level==='debug'?'ri-debug':'ri-info')); }

  function renderAppendLine(lineHtml){
    // Create a div per line and append (no truncation of last line)
    const div = document.createElement('div');
    div.className = 'ri-line';
    div.innerHTML = lineHtml;
    logEl.appendChild(div);

    // Trim head when above tailSize
    while (buf.length > tailSize) {
      buf.shift();
      if (logEl.firstChild) logEl.removeChild(logEl.firstChild);
    }

    if (cbAuto.checked) {
      logEl.scrollTop = logEl.scrollHeight;
    }
  }

  function push(msg, type){
    try{
      const level = (type||'info').toLowerCase();
      const safe = String(msg).replace(/</g,'&lt;').replace(/>/g,'&gt;');
      const line = `[${level.toUpperCase()}] ${ts()} ${safe}`;
      buf.push(line);
      if (!paused) {
        renderAppendLine(`<span class="${cls(level)}">${line}</span>`);
      }
    }catch(_){}
  }

  // Public API
  window.RIToaster = {
    info:  (m)=>push(m,'info'),
    warn:  (m)=>push(m,'warn'),
    error: (m)=>push(m,'error'),
    debug: (m)=>push(m,'debug'),
    push
  };

  // Drain any pre-head queue
  if (window.__RI_TOAST_QUEUE && window.__RI_TOAST_QUEUE.length) {
    try {
      window.__RI_TOAST_QUEUE.forEach(([m,t])=>push(m,t));
      window.__RI_TOAST_QUEUE.length = 0;
    } catch(_){}
  }

  // Controls
  btnClear.addEventListener('click', function(){
    buf.length = 0;
    logEl.innerHTML = '';
  });
  btnPause.addEventListener('click', function(){
    paused = !paused;
    btnPause.textContent = paused ? 'Resume' : 'Pause';
    btnPause.setAttribute('data-paused', paused ? 'true' : 'false');
    if (!paused) {
      // Render any lines that arrived while paused
      logEl.innerHTML = '';
      buf.forEach(line => renderAppendLine(
        `<span class="${cls((line.match(/^\[(\w+)\]/)||['','INFO'])[1].toLowerCase())}">${line}</span>`
      ));
    }
  });
  btnCopy.addEventListener('click', async function(){
    try {
      await navigator.clipboard.writeText(buf.join('\n'));
      window.RIToaster.info(`Copied ${buf.length} lines to clipboard`);
    } catch(e){
      window.RIToaster.warn('Copy failed: ' + e);
    }
  });
  tailInp.addEventListener('change', function(){
    const v = parseInt(tailInp.value||'500',10);
    if (!isNaN(v) && v>0 && v<=5000) {
      tailSize = v;
      // Trim if current buffer too large
      while (buf.length > tailSize) {
        buf.shift();
        if (logEl.firstChild) logEl.removeChild(logEl.firstChild);
      }
    }
  });

  // Breadcrumb
  window.RIToaster.info('header_toaster_messages.php r13 mounted (static top, append-only)');
  window.RIToaster.info('Location: ' + window.location.href);
})();
</script>
