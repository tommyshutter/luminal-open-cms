<?php
/**
 * @file /admin/media-thumbs-utility.php
 * @version 2025.10.27.16.30
 *
 * IMPROVEMENTS:
 * - Prevents nested .cache folders (max 1 level deep)
 * - Exclusion list for folders to skip
 * - Bulk folder selection with checkboxes
 * - Better UI with select all/none
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
$SITE_BASE  = SITE_ROOT;
$MEDIA_ROOT = $SITE_BASE . '/media';
$site_host_domain_name = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Site', ENT_QUOTES, 'UTF-8');

// EXCLUSION LIST - folders to skip during scanning
$EXCLUDED_FOLDERS = [
    'admin',
    'backups', 
    'temp',
    'tmp',
    '.git',
    'node_modules'
];

function human_size(int $bytes): string {
  $u = ['B','KB','MB','GB','TB']; $i=0; $v=max(0,$bytes);
  while ($v>=1024 && $i<count($u)-1) { $v/=1024; $i++; }
  return sprintf('%.1f %s', $v, $u[$i]);
}

function is_excluded(string $path, array $excluded): bool {
    $basename = basename($path);
    return in_array($basename, $excluded, true);
}

function folder_cache_size(string $folderFs): int {
  $sum=0;
  if (!is_dir($folderFs)) return 0;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($folderFs, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $p => $info) {
    // Only count direct .cache folders (not nested)
    if ($info->isFile()) {
        $parts = explode(DIRECTORY_SEPARATOR, $p);
        $cacheCount = 0;
        foreach ($parts as $part) {
            if ($part === '.cache') $cacheCount++;
        }
        // Only count if .cache appears exactly once in path
        if ($cacheCount === 1) {
            $sum += $info->getSize();
        }
    }
  }
  return $sum;
}

function folder_counts(string $folderFs): array {
  $counts = ['images'=>0,'videos'=>0,'pdfs'=>0];
  if (!is_dir($folderFs)) return $counts;
  $it = new FilesystemIterator($folderFs, FilesystemIterator::SKIP_DOTS);
  foreach ($it as $info) {
    if ($info->isDir()) continue; // immediate files only for the card summary
    $e = strtolower(pathinfo($info->getFilename(), PATHINFO_EXTENSION));
    if (in_array($e, ['jpg','jpeg','png','gif','webp'], true)) $counts['images']++;
    elseif (in_array($e, ['mp4','mov','m4v','webm','ogg'], true)) $counts['videos']++;
    elseif ($e === 'pdf') $counts['pdfs']++;
  }
  return $counts;
}

function list_media_folders(string $rootFs, string $rootWeb='/media', array $excluded=[]): array {
  $cards = [];
  if (!is_dir($rootFs)) return $cards;

  $rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootFs, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($rii as $p => $info) {
    if ($info->isDir()) {
      // Skip .cache folders
      if (basename($p) === '.cache') { $rii->next(); continue; }
      
      // Skip excluded folders
      if (is_excluded($p, $excluded)) { $rii->next(); continue; }
      
      $web = $rootWeb . str_replace($rootFs, '', $p);
      if ($p === $rootFs) continue;
      $counts = folder_counts($p);
      $cache  = folder_cache_size($p);
      // Show if folder has media or has any cache
      if (($counts['images'] + $counts['videos'] + $counts['pdfs']) > 0 || $cache > 0) {
        $cards[] = [
          'web'    => $web,
          'fs'     => $p,
          'counts' => $counts,
          'cache'  => $cache,
        ];
      }
    }
  }
  return $cards;
}

$cards = list_media_folders($MEDIA_ROOT, '/media', $EXCLUDED_FOLDERS);
$global_cache = folder_cache_size($MEDIA_ROOT);

// Admin shell provides <html><head><body> + .admin-container + admin menu + content-area.
require_once SITE_ROOT . '/admin/admin_header.php';
?>
<style>
    .actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:10px 0 18px; }
    .btn { padding:8px 12px; border-radius:8px; border:1px solid #475569; background:#111827; color:#fff; cursor:pointer; }
    .btn:hover { background:#1f2937; }
    .btn.primary { border-color:#2563eb; background:#1e40af; }
    .btn.danger  { border-color:#ef4444; background:#7f1d1d; }
    .btn:disabled { opacity:0.5; cursor:not-allowed; }
    
    .selection-bar { display:flex; gap:8px; align-items:center; margin:10px 0; padding:10px; background:#0d1320; border:1px solid rgba(255,255,255,.08); border-radius:8px; }
    .selection-bar .count { margin-left:auto; opacity:0.8; }
    
    .cards { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px; }
    .card { background:#0d1320; border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; position:relative; }
    .card.selected { border-color:#2563eb; background:#0f1729; }
    .card .checkbox { position:absolute; top:10px; right:10px; width:20px; height:20px; cursor:pointer; }
    .card h3 { margin:0; padding:10px 12px 10px 12px; font-size:1rem; border-bottom:1px solid rgba(255,255,255,.06); background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,0)); }
    .card .body { padding:12px; display:flex; flex-direction:column; gap:8px; }
    .kv { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .small { opacity:.8; font-size:.9rem; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.78rem; line-height:1.8; border:1px solid #475569; background:#111827; }
    
    #toast-container{ position:fixed; top:16px; right:16px; z-index:5000; }
    .toast{ margin-top:8px; padding:8px 10px; border-radius:10px; color:#fff; border:1px solid transparent; background:#1f2937; border-color:#475569; opacity:.98; }
    .toast.success{ background:#065f46; border-color:#10b981; }
    .toast.error{   background:#7f1d1d; border-color:#ef4444; }
    
    .log { margin-top:14px; background:#0b0f17; border:1px solid rgba(255,255,255,.08); border-radius:8px; padding:10px; min-height:100px; max-height:300px; overflow-y:auto; white-space:pre-wrap; font-family:monospace; font-size:0.85rem; }
    
    /* progress */
    .progress-wrap { display:flex; align-items:center; gap:10px; margin:8px 0; }
    .progress-bar { position:relative; flex:1; height:10px; background:#111827; border:1px solid #334155; border-radius:999px; overflow:hidden; }
    .progress-bar > span { display:block; height:100%; width:0%; background:#2563eb; transition:width .2s ease; }
    .progress-label { min-width:140px; text-align:right; font-size:.85rem; opacity:.9; }
    
    .exclusion-list { margin:10px 0; padding:10px; background:#0d1320; border:1px solid rgba(255,255,255,.08); border-radius:8px; }
    .exclusion-list h4 { margin:0 0 8px 0; font-size:0.9rem; opacity:0.8; }
    .exclusion-list .tags { display:flex; flex-wrap:wrap; gap:6px; }
    .exclusion-list .tag { padding:4px 8px; background:#111827; border:1px solid #475569; border-radius:6px; font-size:0.8rem; }
  </style>

          <h1 class="panel_header_h1">Media Thumbnails Utility</h1>

          <!-- Exclusion List -->
          <div class="exclusion-list">
            <h4>📁 Excluded Folders (automatically skipped):</h4>
            <div class="tags">
              <?php foreach ($EXCLUDED_FOLDERS as $folder): ?>
                <span class="tag"><?php echo htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          
          <!-- Global Actions -->
          <div class="actions">
                <span class="badge">Global cache: <?php echo human_size($global_cache); ?></span>
                <button class="btn danger"  id="btn-flush-all">Flush All Caches</button>
                <button class="btn primary" id="btn-rebuild-all">Rebuild All Thumbs</button>
              </div>

              <div class="progress-wrap" id="global-progress" style="display:none;">
                <div class="progress-bar"><span id="global-progress-bar"></span></div>
                <div class="progress-label" id="global-progress-label">0 / 0</div>
              </div>
          
          <!-- Selection Bar for Bulk Operations -->
          <div class="selection-bar">
            <button class="btn" id="btn-select-all">Select All</button>
            <button class="btn" id="btn-select-none">Select None</button>
            <button class="btn danger" id="btn-flush-selected" disabled>Flush Selected</button>
            <button class="btn primary" id="btn-rebuild-selected" disabled>Rebuild Selected</button>
            <span class="count"><span id="selected-count">0</span> selected</span>
          </div>
    
    <div class="cards" id="cards">
      <?php foreach ($cards as $c): ?>
        <section class="card" data-folder="<?php echo htmlspecialchars($c['web'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="checkbox" class="checkbox folder-checkbox" data-folder="<?php echo htmlspecialchars($c['web'], ENT_QUOTES, 'UTF-8'); ?>">
          <h3><?php echo htmlspecialchars($c['web'], ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="body">
            <div class="kv"><span class="small">Images</span><strong class="count-images"><?php echo (int)$c['counts']['images']; ?></strong></div>
            <div class="kv"><span class="small">Videos</span><strong class="count-videos"><?php echo (int)$c['counts']['videos']; ?></strong></div>
            <div class="kv"><span class="small">PDFs</span><strong class="count-pdfs"><?php echo (int)$c['counts']['pdfs']; ?></strong></div>
            <div class="kv"><span class="small">Cache in folder</span><strong class="cache-size"><?php echo human_size((int)$c['cache']); ?></strong></div>

            <!-- Per-card progress -->
            <div class="progress-wrap" style="display:none;">
              <div class="progress-bar"><span class="bar"></span></div>
              <div class="progress-label">0 / 0</div>
            </div>

            <div class="actions">
              <button class="btn" data-act="flush-folder">Flush Cache</button>
              <button class="btn primary" data-act="rebuild-folder">Rebuild Thumbs</button>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="log" id="log">Ready. Cache depth limited to 1 level. Excluded folders: <?php echo implode(', ', $EXCLUDED_FOLDERS); ?></div>

  <div id="toast-container"></div>
  <script>
    const $  = s => document.querySelector(s);
    const $$ = s => Array.from(document.querySelectorAll(s));
    const toast = (m,t='info') => { const c=$('#toast-container'); const d=document.createElement('div'); d.className='toast '+(t==='success'?'success':t==='error'?'error':''); d.textContent=m; c.appendChild(d); setTimeout(()=>{d.style.opacity='0'; d.addEventListener('transitionend',()=>d.remove());}, 3500); };
    const log = msg => { const el=$('#log'); el.textContent += (el.textContent ? "\n" : "") + msg; el.scrollTop=el.scrollHeight; };

    // Checkbox selection management
    function updateSelectionUI() {
      const checkboxes = $$('.folder-checkbox');
      const checked = checkboxes.filter(cb => cb.checked);
      const count = checked.length;
      
      $('#selected-count').textContent = count;
      $('#btn-flush-selected').disabled = count === 0;
      $('#btn-rebuild-selected').disabled = count === 0;
      
      // Update card visual state
      checkboxes.forEach(cb => {
        const card = cb.closest('.card');
        if (cb.checked) card.classList.add('selected');
        else card.classList.remove('selected');
      });
    }

    // Selection buttons
    $('#btn-select-all').addEventListener('click', () => {
      $$('.folder-checkbox').forEach(cb => cb.checked = true);
      updateSelectionUI();
    });

    $('#btn-select-none').addEventListener('click', () => {
      $$('.folder-checkbox').forEach(cb => cb.checked = false);
      updateSelectionUI();
    });

    // Listen to checkbox changes
    document.addEventListener('change', e => {
      if (e.target.classList.contains('folder-checkbox')) {
        updateSelectionUI();
      }
    });

    async function callAjax(action, payload={}, useGet=false){
      if (useGet) {
        const params = new URLSearchParams({action, ...payload});
        const r = await fetch('/admin/modules/MediaThumbs/api/media-cache.php?' + params.toString(), { credentials:'same-origin' });
        if (!r.ok) throw new Error('HTTP '+r.status);
        return r.json();
      } else {
        const fd = new FormData(); fd.append('action', action);
        Object.entries(payload).forEach(([k,v]) => {
          if (Array.isArray(v)) v.forEach(x => fd.append(k+'[]', x));
          else fd.append(k, v);
        });
        const r = await fetch('/admin/modules/MediaThumbs/api/media-cache.php', { method:'POST', body: fd, credentials:'same-origin' });
        if (!r.ok) { const t = await r.text(); throw new Error(`HTTP ${r.status} ${t}`); }
        return r.json();
      }
    }

    function setProgress(barEl, labelEl, done, total) {
      const pct = total ? Math.round((done/total)*100) : 0;
      barEl.style.width = pct + '%';
      labelEl.textContent = `${done} / ${total}`;
    }

    // -------- Global buttons --------
    $('#btn-flush-all').addEventListener('click', async ()=>{
      try {
        toast('Flushing all caches…'); log('Flushing .cache under /media…');
        const j = await callAjax('flush_caches');
        if (j.success) { toast('Caches flushed','success'); log('Removed: '+((j.details&&j.details.removed)||0)); }
        else throw new Error(j.message||'Unknown error');
      } catch(e){ toast('Flush failed: '+e.message,'error'); log('Flush failed: '+e.message); }
    });

    $('#btn-rebuild-all').addEventListener('click', async ()=>{
      const wrap = $('#global-progress');
      const bar  = $('#global-progress-bar');
      const lab  = $('#global-progress-label');
      try {
        wrap.style.display='flex';
        setProgress(bar, lab, 0, 0);
        log('Scanning media (images+videos; PDFs skipped)…');
        const scan = await callAjax('scan_targets', {scope:'all', types:'images,videos'}, true);
        const files = scan.files || [];
        const total = files.length;
        if (!total) { setProgress(bar, lab, 0, 0); toast('Nothing to build.','success'); return; }

        let done=0, idx=0;
        const batchSize = 20;
        while (idx < total) {
          const batch = files.slice(idx, idx+batchSize);
          const res = await callAjax('build_batch', {files: JSON.stringify(batch)});
          done += (res.generated||0);
          setProgress(bar, lab, Math.min(done, total), total);
          idx += batchSize;
        }
        toast('Rebuild complete (PDFs skipped).','success');
        log(`Global rebuild done. Generated: ${done}.`);
      } catch(e) {
        toast('Rebuild failed: '+e.message,'error');
        log('Rebuild failed: '+e.message);
      } finally {
        setTimeout(()=>{ $('#global-progress').style.display='none'; }, 1200);
      }
    });

    // -------- Bulk Selected Operations --------
    $('#btn-flush-selected').addEventListener('click', async ()=>{
      const selected = $$('.folder-checkbox:checked').map(cb => cb.dataset.folder);
      if (!selected.length) return;
      
      if (!confirm(`Flush cache for ${selected.length} selected folder(s)?`)) return;
      
      try {
        toast(`Flushing ${selected.length} folders…`);
        log(`Flushing selected folders: ${selected.join(', ')}`);
        
        for (const folder of selected) {
          const j = await callAjax('flush_folder', { folder });
          if (j.success) {
            log(`✓ Flushed ${folder}`);
            // Update card cache display
            const card = $(`.card[data-folder="${folder}"]`);
            if (card) {
              const info = await callAjax('folder_info', { folder }, true);
              card.querySelector('.cache-size').textContent = humanSize(info.cache_size||0);
            }
          }
        }
        toast('Selected folders flushed!','success');
      } catch(e) {
        toast('Flush failed: '+e.message,'error');
        log('Flush failed: '+e.message);
      }
    });

    $('#btn-rebuild-selected').addEventListener('click', async ()=>{
      const selected = $$('.folder-checkbox:checked').map(cb => cb.dataset.folder);
      if (!selected.length) return;
      
      if (!confirm(`Rebuild thumbs for ${selected.length} selected folder(s)? (PDFs skipped)`)) return;
      
      try {
        toast(`Rebuilding ${selected.length} folders…`);
        log(`Rebuilding selected folders: ${selected.join(', ')}`);
        
        for (const folder of selected) {
          const card = $(`.card[data-folder="${folder}"]`);
          const progWrap = card.querySelector('.progress-wrap');
          const progBar = card.querySelector('.progress-wrap .bar');
          const progLab = card.querySelector('.progress-wrap .progress-label');
          
          progWrap.style.display='flex';
          
          const scan = await callAjax('scan_targets', {scope:'folder', folder, types:'images,videos'}, true);
          const files = scan.files || [];
          const total = files.length;
          
          if (total === 0) {
            log(`⚠ ${folder}: Nothing to build`);
            progWrap.style.display='none';
            continue;
          }
          
          let done=0, idx=0;
          const batchSize = 20;
          while (idx < total) {
            const batch = files.slice(idx, idx+batchSize);
            const res = await callAjax('build_batch', {files: JSON.stringify(batch)});
            done += (res.generated||0);
            setProgress(progBar, progLab, Math.min(done, total), total);
            idx += batchSize;
          }
          
          log(`✓ ${folder}: ${done} thumbs generated`);
          
          // Update card stats
          const info = await callAjax('folder_info', { folder }, true);
          card.querySelector('.cache-size').textContent = humanSize(info.cache_size||0);
          
          setTimeout(()=>{ progWrap.style.display='none'; }, 800);
        }
        
        toast('Selected folders rebuilt!','success');
      } catch(e) {
        toast('Rebuild failed: '+e.message,'error');
        log('Rebuild failed: '+e.message);
      }
    });

    // -------- Per-card buttons with per-card progress --------
    $('#cards').addEventListener('click', async (e)=>{
      const btn = e.target.closest('button[data-act]');
      if (!btn) return;
      const card   = e.target.closest('.card');
      const folder = card?.dataset.folder;
      if (!folder) return;

      const progWrap = card.querySelector('.progress-wrap');
      const progBar  = card.querySelector('.progress-wrap .bar');
      const progLab  = card.querySelector('.progress-wrap .progress-label');
      const cacheEl  = card.querySelector('.cache-size');
      const cImg     = card.querySelector('.count-images');
      const cVid     = card.querySelector('.count-videos');
      const cPdf     = card.querySelector('.count-pdfs');

      const act = btn.dataset.act;

      if (act === 'flush-folder') {
        try {
          toast(`Flushing ${folder}…`); log(`Flushing caches in ${folder}…`);
          const j = await callAjax('flush_folder', { folder });
          if (j.success) {
            toast('Folder flushed','success'); log(`Flushed ${folder}. Removed: ${ (j.details&&j.details.removed) || 0 }`);
            // refresh card info
            const info = await callAjax('folder_info', { folder }, true);
            cacheEl.textContent = humanSize(info.cache_size||0);
          } else throw new Error(j.message||'Unknown error');
        } catch(e){ toast('Flush failed: '+e.message,'error'); log('Flush failed: '+e.message); }
      }

      if (act === 'rebuild-folder') {
        try {
          progWrap.style.display='flex';
          setProgress(progBar, progLab, 0, 0);
          toast(`Rebuilding ${folder}…`); log(`Rebuilding thumbs in ${folder} (PDFs skipped)…`);
          const scan = await callAjax('scan_targets', {scope:'folder', folder, types:'images,videos'}, true);
          const files = scan.files || [];
          const total = files.length;
          if (!total) { setProgress(progBar, progLab, 0, 0); toast('Nothing to build.','success'); return; }

          let done=0, idx=0;
          const batchSize = 20;
          while (idx < total) {
            const batch = files.slice(idx, idx+batchSize);
            const res = await callAjax('build_batch', {files: JSON.stringify(batch)});
            done += (res.generated||0);
            setProgress(progBar, progLab, Math.min(done, total), total);
            idx += batchSize;
          }
          toast('Folder rebuild complete.','success');
          // refresh card stats
          const info = await callAjax('folder_info', { folder }, true);
          cacheEl.textContent = humanSize(info.cache_size||0);
          cImg.textContent = (info.counts && info.counts.images) || cImg.textContent;
          cVid.textContent = (info.counts && info.counts.videos) || cVid.textContent;
          cPdf.textContent = (info.counts && info.counts.pdfs)   || cPdf.textContent;
        } catch(e){
          toast('Rebuild failed: '+e.message,'error');
          log('Rebuild failed: '+e.message);
        } finally {
          setTimeout(()=>{ progWrap.style.display='none'; }, 1200);
        }
      }
    });

    // utils
    function humanSize(bytes){
      const u = ['B','KB','MB','GB','TB'];
      let i=0, v = Math.max(0, parseInt(bytes||0,10));
      while (v>=1024 && i<u.length-1){ v/=1024; i++; }
      return `${v.toFixed(1)} ${u[i]}`;
    }
    
    // Initialize
    updateSelectionUI();
  </script>
<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
