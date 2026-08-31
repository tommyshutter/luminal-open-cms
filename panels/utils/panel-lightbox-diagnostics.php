<?php
/**
 * @appname     Lightbox Diagnostics Panel
 * @file        SITE-ROOT/panels/panel-lightbox-diagnostics.php
 * @version     2025.09.10.1958.00-EST
 * @author      
 * @license     Apache-2.0 — see LICENSE and NOTICE
 * @description Sandboxed “tricorder” to scan, probe, and EXPORT a text report with
 *              cache/version fingerprints. This build fixes a parser break caused by
 *              an unescaped </script> sequence in a JS template string.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/**
 * Return metadata for a SITE_ROOT-relative file:
 * - exists, rel, abs, mtime, size, md5short, version (from @version)
 */
function diag_file_info(string $rel): array {
    $abs = rtrim(SITE_ROOT, '/') . $rel;
    $info = [
        'rel'      => $rel,
        'exists'   => is_file($abs),
        'mtime'    => null,
        'size'     => null,
        'md5short' => null,
        'version'  => null,
    ];
    if (!$info['exists']) return $info;

    $info['mtime']    = date('Y-m-d H:i:s', @filemtime($abs));
    $info['size']     = @filesize($abs);
    $md5 = @md5_file($abs);
    if ($md5) $info['md5short'] = substr($md5, 0, 8);

    // Extract @version from file head
    $head = @file_get_contents($abs, false, null, 0, 8192);
    if (is_string($head) && preg_match('/@version\s+([^\r\n]+)/i', $head, $m)) {
        $info['version'] = trim($m[1]);
    }
    return $info;
}

// Inventory of files to fingerprint
$files_list = [
    '/js/lightbox-core.js',
    '/js/lightbox-pdf.js',
    '/admin/modules/ContentStacks/includes/renderers/content_stack.renderer.php',
    '/panels/pdf-viewer.php',
    '/panels/pdf-proxy.php',
];
$filemeta = array_map('diag_file_info', $files_list);
?>
<style>
  .ri-diag{font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#e8eef6;background:#0b1117;border:1px solid #1b2530;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.35);padding:12px;margin:10px 0}
  .ri-diag h3{margin:0 0 8px;font-size:16px}
  .ri-diag .row{display:flex;gap:10px;flex-wrap:wrap}
  .ri-diag .card{flex:1 1 360px;background:#0f1620;border:1px solid #1b2530;border-radius:10px;padding:10px}
  .ri-diag .btn{color:#fff;font-weight:bold;cursor:pointer;border:1px solid #2a3948;background:#777;border-radius:8px;padding:6px 10px}
  .ri-diag .btn:hover{background:#172231}
  .ri-diag table{width:100%;border-collapse:collapse;margin-top:8px}
  .ri-diag th,.ri-diag td{padding:6px;border-bottom:1px solid #1b2530;font-size:13px;vertical-align:top}
  .ri-diag .ok{color:#7be57b}
  .ri-diag .warn{color:#f7d774}
  .ri-diag .err{color:#ff7d7d}
  .ri-diag .log{background:#0f1620;border:1px solid #1b2530;border-radius:8px;padding:8px;max-height:220px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap}
  .ri-diag .pill{display:inline-block;padding:2px 6px;border-radius:999px;border:1px solid #2a3948;background:#121a24;margin-left:6px;font-size:12px}
  .ri-diag .muted{opacity:.8}
  .ri-diag .ri-hi{outline:2px solid #59aaff; outline-offset:2px; transition:outline-color .2s}
  .ri-diag .actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .ri-diag .field{display:flex;align-items:center;gap:6px}
  .ri-diag input[type="number"]{width:72px;background:#0d1420;border:1px solid #233244;border-radius:6px;color:#e8eef6;padding:4px 6px}
  .ri-diag textarea.report{width:100%;min-height:160px;background:#0d1420;border:1px solid #233244;border-radius:8px;color:#cfe2ff;padding:8px;resize:vertical;margin-top:8px}
  .ri-diag code{background:#0d1420;border:1px solid #233244;border-radius:4px;padding:1px 4px}
</style>

<div class="ri-diag" id="ri-diag">
  <h3>Lightbox Diagnostics <span class="pill">sandboxed + versioned</span></h3>

  <div class="row">
    <div class="card">
      <div><strong>File Fingerprints (cache/version)</strong> <span class="muted">(server-side)</span></div>
      <table>
        <thead><tr><th>Path</th><th>Status</th><th>@version</th><th>mtime</th><th>size</th><th>md5</th></tr></thead>
        <tbody>
          <?php foreach ($filemeta as $fm): ?>
          <tr>
            <td><?= htmlspecialchars($fm['rel']) ?></td>
            <td><?= $fm['exists'] ? '<span class="ok">present</span>' : '<span class="err">missing</span>' ?></td>
            <td class="muted"><?= htmlspecialchars($fm['version'] ?? '—') ?></td>
            <td class="muted"><?= htmlspecialchars($fm['mtime'] ?? '—') ?></td>
            <td class="muted"><?= $fm['size'] !== null ? number_format($fm['size']).' B' : '—' ?></td>
            <td class="muted"><?= htmlspecialchars($fm['md5short'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
      </table>
      <div class="muted" style="margin-top:6px">
        Tip: append <code>?v=&lt;@version or md5&gt;</code> on script/link URLs to bust caches.
      </div>

      <div class="actions" style="margin-top:10px">
        <button class="btn" data-act="scan">Scan Page</button>
        <div class="field"><label class="muted">Probe</label><input type="number" min="0" step="1" value="0" data-id="probe-count" title="0 = off; N = test-open first N"></div>
        <button class="btn" data-act="build">Build Report</button>
        <button class="btn" data-act="copy">Copy</button>
        <button class="btn" data-act="download">Download .txt</button>
        <button class="btn" data-act="clearhi">Clear Highlights</button>
      </div>
      <div data-id="summary" class="muted" style="margin-top:6px"></div>
      <textarea class="report" data-id="report" placeholder="Report will appear here…"></textarea>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong>Elements Found</strong>
        <span class="pill" data-id="modal-state">modal: <em>unknown</em></span>
      </div>
      <table>
        <thead><tr><th>#</th><th>Selector (truncated)</th><th>Type</th><th>Actions</th></tr></thead>
        <tbody data-id="table-body"><tr><td colspan="4" class="muted">Click “Scan Page”.</td></tr></tbody>
      </table>
      <div class="muted" style="margin-top:8px">
        <strong>Key Functions Under Test:</strong>
        <div>Core: <code>ensureModal</code>, <code>openWith</code>, <code>inferTypeFromHref</code>, <code>detectTypeFromAnchor</code>, <code>renderImage</code>, <code>renderVideo</code></div>
        <div>PDF: <code>wantsLightbox</code> (→ opens <code>/panels/pdf-viewer.php?src=…</code>)</div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:10px">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <strong>Event Log</strong>
      <div class="actions">
        <button class="btn" data-act="logclear">Clear</button>
      </div>
    </div>
    <div class="log" data-id="log"></div>
  </div>
</div>

<script>
(function(){
  const root = document.getElementById('ri-diag'); if (!root) return;

  // Server-side fingerprints (for the report)
  const FILEMETA = <?php echo json_encode($filemeta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;

  const el  = (s) => root.querySelector(s);
  const els = (s) => Array.from(root.querySelectorAll(s));
  const logBox     = el('[data-id="log"]');
  const tableBody  = el('[data-id="table-body"]');
  const summary    = el('[data-id="summary"]');
  const modalState = el('[data-id="modal-state"]');
  const reportBox  = el('[data-id="report"]');
  const probeCount = el('[data-id="probe-count"]');

  const btn = {
    scan:     el('[data-act="scan"]'),
    build:    el('[data-act="build"]'),
    copy:     el('[data-act="copy"]'),
    download: el('[data-act="download"]'),
    clearhi:  el('[data-act="clearhi"]'),
    logclear: el('[data-act="logclear"]'),
  };

  function log(msg, lvl='info'){
    const line = document.createElement('div');
    line.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
    if (lvl==='warn') line.style.color = '#f7d774';
    if (lvl==='err')  line.style.color = '#ff7d7d';
    logBox.appendChild(line);
    logBox.scrollTop = logBox.scrollHeight;
    try{ console.log('[diag]', msg); }catch(_){}
  }

  function modalOpen(){
    const m = document.getElementById('ri-modal');
    return !!(m && m.classList.contains('open'));
  }
  function setModalBadge(){
    modalState.innerHTML = 'modal: ' + (modalOpen() ? '<span class="ok">open</span>' : '<span class="muted">closed</span>');
  }

  // Discovery (explicit + legacy)
  function candidates(){
    const exp = Array.from(document.querySelectorAll('a[data-lightbox="image"], a[data-lightbox="video"], a[data-lightbox="pdf"]'));
    const legacy = Array.from(document.querySelectorAll('a.lightbox, a[rel~="lightbox"]'));
    const set = new Set(); const all = [];
    [...exp, ...legacy].forEach(a=>{ if(!set.has(a)){ set.add(a); all.push(a);} });
    return all;
  }
  function lbType(a){
    const explicit = (a.getAttribute('data-lightbox')||'').toLowerCase();
    if (explicit) return explicit;
    const href = (a.getAttribute('href')||'').toLowerCase();
    if (/\.pdf(\?.*)?$/.test(href)) return 'pdf';
    if (/\.(mp4|webm|og[gv]|m4v)(\?.*)?$/i.test(href) || /youtube\.com|youtu\.be|vimeo\.com/i.test(href)) return 'video';
    if (/\.(png|jpe?g|gif|webp|avif)(\?.*)?$/i.test(href)) return 'image';
    return 'unknown';
  }

  function clearHighlights(){ Array.from(document.querySelectorAll('.ri-hi')).forEach(n=>n.classList.remove('ri-hi')); }
  function hi(el){ el.classList.add('ri-hi'); setTimeout(()=>{el.classList.remove('ri-hi');},1200); }

  async function simulateOpen(a){
    a.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true, view:window}));
    await new Promise(r=>setTimeout(r, 350));
    const opened = modalOpen();
    if (opened) { const m=document.getElementById('ri-modal'); if (m) m.click(); await new Promise(r=>setTimeout(r,120)); }
    setModalBadge();
    return opened;
  }

  function renderList(){
    const list = candidates();
    tableBody.innerHTML = '';
    if (!list.length){
      tableBody.innerHTML = '<tr><td colspan="4" class="muted">No anchors with data-lightbox or legacy lightbox found on this page.</td></tr>';
      summary.innerHTML = '<span class="warn">0 candidates found.</span>';
      return;
    }
    // Stats
    const stats = {data_image:0,data_video:0,data_pdf:0, legacy_class:0, legacy_rel:0};
    list.forEach(a=>{
      if (a.hasAttribute('data-lightbox')) {
        const t=(a.getAttribute('data-lightbox')||'').toLowerCase();
        if (t==='image') stats.data_image++;
        else if (t==='video') stats.data_video++;
        else if (t==='pdf') stats.data_pdf++;
      }
      if (a.classList.contains('lightbox')) stats.legacy_class++;
      if ((a.getAttribute('rel')||'').split(/\s+/).includes('lightbox')) stats.legacy_rel++;
    });
    summary.innerHTML = `<span class="ok">${list.length} candidates</span> — data: img=${stats.data_image}, vid=${stats.data_video}, pdf=${stats.data_pdf}; legacy: class=${stats.legacy_class}, rel=${stats.legacy_rel}`;

    list.forEach((a,i)=>{
      const tr = document.createElement('tr');
      const html = a.outerHTML.replace(/\s+/g,' ').slice(0,160).replace(/</g,'&lt;');
      tr.innerHTML = `
        <td>${i+1}</td>
        <td class="muted">&lt;a …&gt; ${html}…</td>
        <td>${lbType(a)}</td>
        <td>
          <button class="btn" data-action="hi">Highlight</button>
          <button class="btn" data-action="open">Test Open</button>
        </td>`;
      tr.querySelector('[data-action="hi"]').addEventListener('click', ()=>hi(a));
      tr.querySelector('[data-action="open"]').addEventListener('click', async ()=>{
        const ok = await simulateOpen(a);
        log(`Test Open #${i+1}: ${ok?'OPEN ✅':'no open ❌'}`, ok?'info':'warn');
      });
      tableBody.appendChild(tr);
    });
    setModalBadge();
  }

  function buildReportText(results){
    const now = new Date().toISOString();
    const url = location.href;

    const list = candidates();
    let s = {data_image:0,data_video:0,data_pdf:0, legacy_class:0, legacy_rel:0};
    list.forEach(a=>{
      if (a.hasAttribute('data-lightbox')) {
        const t=(a.getAttribute('data-lightbox')||'').toLowerCase();
        if (t==='image') s.data_image++;
        else if (t==='video') s.data_video++;
        else if (t==='pdf') s.data_pdf++;
      }
      if (a.classList.contains('lightbox')) s.legacy_class++;
      if ((a.getAttribute('rel')||'').split(/\s+/).includes('lightbox')) s.legacy_rel++;
    });

    // Compose fingerprints
    const filesTxt = FILEMETA.map(f=>{
      const status = f.exists ? 'present' : 'missing';
      const ver = f.version || '—';
      const mt  = f.mtime || '—';
      const sz  = (f.size!=null) ? `${f.size} B` : '—';
      const md  = f.md5short || '—';
      return `  ${f.rel}\n    status=${status}  @version=${ver}  mtime=${mt}  size=${sz}  md5=${md}`;
    }).join('\n');

    // Build a safe <script> example WITHOUT literal script in source
    function scriptTagExample(src){
      return '<'+'script src="'+src+'"></'+'script>';
    }
    const verToken = (FILEMETA[0] && (FILEMETA[0].version || FILEMETA[0].md5short || '1')) || '1';

    const keyFuncs = [
      'ensureModal (core) — implied by #ri-modal being created',
      'openWith (core) — implied by modal opening on click',
      'inferTypeFromHref (core) — used when legacy anchors lack explicit type',
      'detectTypeFromAnchor (core) — resolves explicit/legacy/auto',
      'renderImage / renderVideo (core) — observed if modal shows image or iframe/video',
      'wantsLightbox (pdf) — opens /panels/pdf-viewer.php?src=…',
    ];

    const lines = [];
    lines.push(`Lightbox Diagnostics Report`);
    lines.push(`Generated: ${now}`);
    lines.push(`Page URL: ${url}`);
    lines.push(`Modal element present: ${document.getElementById('ri-modal') ? 'yes' : 'no'}`);
    lines.push(``);
    lines.push(`File Fingerprints (server-side):`);
    lines.push(filesTxt);
    lines.push(``);
    lines.push(`Selector Usage (candidates=${list.length}):`);
    lines.push(`  data-lightbox: image=${s.data_image}  video=${s.data_video}  pdf=${s.data_pdf}`);
    lines.push(`  legacy: class=.lightbox=${s.legacy_class}  rel~="lightbox"=${s.legacy_rel}`);
    lines.push(``);
    if (results && results.probed) {
      lines.push(`Probe results (tested=${results.probed.length}, opened=${results.openedCount}):`);
      results.probed.forEach(r=>{
        lines.push(`  [${String(r.index).padStart(3,' ')}] ${r.type} ${r.opened?'OPEN ✅':'no open ❌'}  href=${r.href}`);
      });
      lines.push(``);
    }
    lines.push(`Key Functions Under Test:`);
    keyFuncs.forEach(fn => lines.push(`  • ${fn}`));
    lines.push(``);
    lines.push(`Cache Busting Tips:`);
    lines.push(`  • Append ?v=<@version> or ?v=<md5> to <script>/<link> URLs to avoid stale browser caches.`);
    lines.push(`  • Example: ${scriptTagExample('/js/lightbox-core.js?v='+verToken)}`);
    lines.push(``);

    return lines.join('\n');
  }

  async function probeSome(n){
    const list = candidates();
    const limit = Math.max(0, Math.min(n|0, list.length));
    const probed = [];
    let openedCount = 0;
    for (let i=0;i<limit;i++){
      const a = list[i];
      const opened = await simulateOpen(a);
      openedCount += opened ? 1 : 0;
      probed.push({ index:i+1, type:lbType(a), href:a.getAttribute('href')||'', opened });
      await new Promise(r=>setTimeout(r, 160));
    }
    return {probed, openedCount};
  }

  // Wire (sandboxed)
  btn.scan.addEventListener('click', renderList);
  btn.clearhi.addEventListener('click', clearHighlights);
  btn.logclear.addEventListener('click', ()=>{ logBox.innerHTML=''; });

  btn.build.addEventListener('click', async ()=>{
    let probeRes = null;
    const n = Number(probeCount.value || 0);
    if (n > 0) {
      log(`Probing first ${n} candidate(s)...`);
      probeRes = await probeSome(n);
      log(`Probe done: opened ${probeRes.openedCount}/${probeRes.probed.length}`);
    }
    const text = buildReportText(probeRes);
    reportBox.value = text;
    log('Report built.');
  });

  btn.copy.addEventListener('click', async ()=>{
    try { await navigator.clipboard.writeText(reportBox.value || ''); }
    catch(_){ reportBox.select(); document.execCommand('copy'); }
    log('Report copied to clipboard.');
  });

  btn.download.addEventListener('click', ()=>{
    const blob = new Blob([reportBox.value || ''], {type: 'text/plain;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'lightbox-diagnostics.txt';
    document.body.appendChild(a);
    a.click();
    setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 100);
    log('Report downloaded.');
  });

  // Initial status
  setModalBadge();
})();
</script>
