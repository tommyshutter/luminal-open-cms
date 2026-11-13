// Shortcodes Dock: caret-aware insert + copy (kept minimal)
(function () {
  const left  = document.getElementById('left_content');
  const right = document.getElementById('right_content');

  // default focus to left; update when either textarea gets focus
  let lastFocused = left || right || null;
  [left, right].forEach(el => {
    if (!el) return;
    el.addEventListener('focus', () => { lastFocused = el; });
  });

  function insertAtCursor(textarea, snippet) {
    if (!textarea) return;
    const start = textarea.selectionStart ?? textarea.value.length;
    const end   = textarea.selectionEnd   ?? textarea.value.length;
    const before = textarea.value.slice(0, start);
    const after  = textarea.value.slice(end);
    const needBeforeNL = before.length && !before.endsWith('\n');
    const needAfterNL  = after.length  && !after.startsWith('\n');
    const payload = (needBeforeNL ? '\n' : '') + snippet + (needAfterNL ? '\n' : '');
    textarea.value = before + payload + after;
    const caret = start + payload.length;
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = caret;
  }

  // insert buttons
  document.querySelectorAll('.js-sc-insert').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const code = btn.getAttribute('data-code') || '';
      insertAtCursor(lastFocused || left || right, code);
    });
  });

  // copy buttons
  document.querySelectorAll('.js-sc-copy').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const code = btn.getAttribute('data-code') || '';
      if (!code) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).catch(()=>{});
      } else {
        const t = document.createElement('textarea');
        t.value = code; document.body.appendChild(t);
        t.select(); try { document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(t);
      }
    });
  });
})();
