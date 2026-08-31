/**
 * FontManager — Frontend JS
 * Module version for Luminal CMS module system
 *
 * @file /admin/modules/FontManager/js/font-manager.js
 * @version 1.0.0
 */

document.addEventListener('DOMContentLoaded', function() {
  // ── Install a Google Font (self-hosted) ────────────────────────────────
  var gfBtn = document.getElementById('gf-install');
  if (gfBtn) {
    gfBtn.addEventListener('click', function () {
      var fam = (document.getElementById('gf-family').value || '').trim();
      var ital = document.getElementById('gf-italics').checked;
      var out = document.getElementById('gf-status');
      if (!fam) { out.textContent = 'Enter a font name first.'; return; }
      gfBtn.disabled = true;
      out.textContent = 'Fetching ' + fam + ' from Google and saving it locally\u2026';
      var body = new FormData();
      body.append('family', fam);
      body.append('weights', '400,500,600,700');
      if (ital) body.append('italics', '1');
      fetch('/admin/modules/FontManager/api.php?action=install_google', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          gfBtn.disabled = false;
          if (!d.ok) { out.textContent = 'Could not install: ' + (d.error || 'unknown error'); return; }
          if (d.already_installed) { out.textContent = fam + ' is already installed.'; return; }
          out.textContent = 'Installed ' + d.family + ' \u2014 ' + d.files.length +
                            ' file(s), ' + d.blocks + ' face(s). Reloading\u2026';
          setTimeout(function () { location.reload(); }, 1200);
        })
        .catch(function (e) { gfBtn.disabled = false; out.textContent = 'Request failed: ' + e; });
    });
  }

  var form  = document.getElementById('font-upload-form');
  var input = document.getElementById('fontfile');
  var zone  = form;
  var statusBox = document.getElementById('upload-status');

  if (!form || !input) return;

  function setStatus(msg, level){
    if (statusBox) { statusBox.textContent = msg; statusBox.className = level || ''; }
  }
  function allow(e){ e.preventDefault(); e.stopPropagation(); }

  // global + zone drag states
  ['dragenter','dragover'].forEach(function(evt){
    document.addEventListener(evt, function(e){ allow(e); zone.classList.add('dragging'); }, false);
    zone.addEventListener(evt, function(e){ allow(e); zone.classList.add('dragging'); }, false);
  });
  ['dragleave','drop'].forEach(function(evt){
    document.addEventListener(evt, function(e){ allow(e); zone.classList.remove('dragging'); }, false);
    zone.addEventListener(evt, function(e){ allow(e); zone.classList.remove('dragging'); }, false);
  });

  zone.addEventListener('drop', function(e){
    allow(e);
    var files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) { setStatus('No file dropped', 'error'); return; }
    input.files = files;
    upload(files[0]); // one fell swoop
  });

  // Click zone opens file picker (no separate upload click)
  zone.addEventListener('click', function(e){
    if (e.target && e.target.closest('button')) return; // ignore if a button is clicked
    input.click();
  });

  input.addEventListener('change', function(){
    if (input.files && input.files.length) upload(input.files[0]);
  });

  // Keep submit as safety (not required)
  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (!input.files || !input.files.length) { setStatus('No file selected', 'error'); return; }
    upload(input.files[0]);
  });

  function upload(file){
    if (!file) { setStatus('No file selected', 'error'); return; }
    var data = new FormData();
    data.append('fontfile', file, file.name);
    setStatus('Uploading ' + file.name + '…', 'info');

    fetch('/admin/modules/FontManager/api.php?action=upload', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || resp.ok !== true) {
        setStatus('Upload failed: ' + (resp && resp.error ? resp.error : 'unknown'), 'error');
        return;
      }
      if (resp.duplicate) {
        setStatus('Already installed: ' + (resp.name || file.name), 'success');
        // Optionally highlight existing row if present
        try {
          var sel = '.font-list li[data-file="'+ CSS.escape(resp.name || file.name) +'"]';
          var li = document.querySelector(sel);
          if (li) { li.style.outline = '2px solid #49f'; li.scrollIntoView({block:'center'}); }
        } catch(_) {}
        return; // no reload
      }
      setStatus('Upload successful: ' + (resp.name || file.name), 'success');
      window.location.reload(); // load new @font-face + list
    })
    .catch(function(err){
      setStatus('Upload error: ' + err, 'error');
    });
  }
});
