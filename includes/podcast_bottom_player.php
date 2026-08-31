<?php
/**
 * Persistent bottom-of-browser podcast player — PHASE 1 (resume-on-reload).
 * Self-contained: gated by admin/data/PodcastManager/bottom_player.json (enabled),
 * server-renders the latest feed episode + a playlist from episodes.json, and inlines
 * its own CSS/JS. Plain <audio> + range scrubber (NOT the Studio waveform decoder).
 * localStorage persists track+playhead so playback resumes after a normal page load.
 * Transparent rgba glass surface (fleet transparent-bg rule). Seamless PJAX = Phase 2.
 *
 * Included by page.php just before </body>.
 */
if (!defined('SITE_ROOT')) return;
if (defined('LUM_PBP_EMITTED')) return;   // emit once per page even if included from several renderers
define('LUM_PBP_EMITTED', true);
$pbpCfgFile = SITE_ROOT . '/admin/data/PodcastManager/bottom_player.json';
$pbpCfg = is_file($pbpCfgFile) ? (json_decode((string)@file_get_contents($pbpCfgFile), true) ?: []) : [];
if (empty($pbpCfg['enabled'])) return;

$pbpFeedFile = SITE_ROOT . '/admin/data/podcasts/episodes.json';
$pbpFeed = is_file($pbpFeedFile) ? (json_decode((string)@file_get_contents($pbpFeedFile), true) ?: []) : [];

// Newest first — by season/episode (reliable), pubDate only as a tiebreak.
$pbpList = [];
if (is_array($pbpFeed) && $pbpFeed) {
    usort($pbpFeed, function ($a, $b) {
        $se = [(int)($b['seasonNumber'] ?? 0), (int)($b['episodeNumber'] ?? 0)] <=> [(int)($a['seasonNumber'] ?? 0), (int)($a['episodeNumber'] ?? 0)];
        if ($se !== 0) return $se;
        return (strtotime($b['pubDate'] ?? '') ?: 0) <=> (strtotime($a['pubDate'] ?? '') ?: 0);
    });
    foreach ($pbpFeed as $e) {
        if (empty($e['audioUrl'])) continue;
        $pbpList[] = [
            'src'   => $e['audioUrl'],
            'title' => $e['title'] ?? 'Episode',
            'cover' => $e['coverUrl'] ?? '',
            'ep'    => 'S' . sprintf('%02d', (int)($e['seasonNumber'] ?? 0)) . 'E' . sprintf('%02d', (int)($e['episodeNumber'] ?? 0)),
        ];
    }
}

// Stations mode (opt-in): derive live radio stations from PodRadio at render —
// no frozen copy. Only audio-mountable stations become live sources (YT-only
// stations aren't real streams). Each is a track with live:true → its stream URL.
$pbpStations = [];
if (!empty($pbpCfg['stations_mode'])) {
    $prLib = SITE_ROOT . '/admin/modules/PodRadio/lib/podradio.php';
    if (is_file($prLib)) {
        require_once $prLib;
        if (function_exists('pr_all_stations')) {
            $base = rtrim($pbpCfg['station_base'] ?? '/stream/', '/') . '/';
            foreach (pr_all_stations() as $s) {
                $audio = false;
                foreach (($s['playlist'] ?? []) as $it) {
                    if (($it['type'] ?? '') === 'content' && ($it['source'] ?? '') === 'audio') { $audio = true; break; }
                }
                if (!$audio) continue;
                $pbpStations[] = [
                    'src'   => $base . $s['slug'],
                    'title' => $s['title'] ?? $s['slug'],
                    'cover' => $s['logo'] ?? '',
                    'ep'    => 'LIVE',
                    'live'  => true,
                ];
            }
        }
    }
}

$pbpAll = array_merge($pbpList, $pbpStations);   // episodes first (preserve default), live stations after
if (!$pbpAll) return;                            // neither episodes nor stations → no bar
$pbpBoot = ['show' => ($pbpCfg['show_title'] ?? ''), 'list' => $pbpAll, 'nowUrl' => ($pbpCfg['now_url'] ?? '')];
$pbpJson = json_encode($pbpBoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<style id="lum-pbp-css">
.lum-pbp{position:fixed;left:0;right:0;bottom:0;z-index:2147480000;display:flex;align-items:center;gap:14px;
  padding:10px 16px;color:#f4f6fb;font:500 13px/1.3 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  background:rgba(14,16,24,.82);backdrop-filter:blur(18px) saturate(1.2);-webkit-backdrop-filter:blur(18px) saturate(1.2);
  border-top:1px solid rgba(255,255,255,.12);box-shadow:0 -8px 30px -12px rgba(0,0,0,.7);
  transform:translateY(100%);transition:transform .28s cubic-bezier(.2,.8,.2,1)}
.lum-pbp.ready{transform:translateY(0)}
.lum-pbp *{box-sizing:border-box}
.lum-pbp-cover{flex:0 0 auto;width:56px;height:56px;border-radius:8px;object-fit:cover;background:rgba(255,255,255,.08);box-shadow:0 2px 10px rgba(0,0,0,.5)}
.lum-pbp-meta{flex:0 1 200px;min-width:0;display:flex;flex-direction:column;gap:2px}
.lum-pbp-title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lum-pbp-ep{font-size:11px;color:rgba(244,246,251,.6);letter-spacing:.4px}
.lum-pbp-btn{flex:0 0 auto;appearance:none;border:0;background:transparent;color:#f4f6fb;cursor:pointer;
  width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;transition:background .12s}
.lum-pbp-btn:hover{background:rgba(255,255,255,.14)}
.lum-pbp-play{width:44px;height:44px;background:rgba(255,255,255,.14);font-size:17px}
.lum-pbp-play:hover{background:rgba(255,255,255,.24)}
.lum-pbp-scrubwrap{flex:1 1 auto;display:flex;align-items:center;gap:9px;min-width:80px}
.lum-pbp-time{flex:0 0 auto;font:700 11px ui-monospace,monospace;color:rgba(244,246,251,.72);min-width:38px;text-align:center}
.lum-pbp-range{-webkit-appearance:none;appearance:none;flex:1 1 auto;height:5px;border-radius:4px;cursor:pointer;
  background:linear-gradient(90deg,#a78bfa var(--pbp-pct,0%),rgba(255,255,255,.18) var(--pbp-pct,0%))}
.lum-pbp-range::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.6);cursor:grab}
.lum-pbp-range::-moz-range-thumb{width:14px;height:14px;border:0;border-radius:50%;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.6);cursor:grab}
.lum-pbp-vol{flex:0 0 auto;display:flex;align-items:center;gap:6px}
.lum-pbp-vol .lum-pbp-range{width:78px;flex:0 0 auto}
/* Seek scrubber — aqua field, chartreuse played fill + chartreuse ball with a border */
.lum-pbp-seek{background:linear-gradient(90deg,#b6ff00 var(--pbp-pct,0%),rgba(64,224,208,.32) var(--pbp-pct,0%));border:1px solid rgba(64,224,208,.55)}
.lum-pbp-seek::-webkit-slider-thumb{-webkit-appearance:none;width:15px;height:15px;border-radius:50%;background:#b6ff00;border:2px solid #06202b;box-shadow:0 0 0 1px rgba(255,255,255,.75),0 1px 6px rgba(0,0,0,.6);cursor:grab}
.lum-pbp-seek::-moz-range-thumb{width:15px;height:15px;border:2px solid #06202b;border-radius:50%;background:#b6ff00;box-shadow:0 0 0 1px rgba(255,255,255,.75),0 1px 6px rgba(0,0,0,.6);cursor:grab}
/* Volume — reads as a real volume level: aqua fill + clear white thumb (icon sits beside it) */
.lum-pbp-vol .lum-pbp-range{height:6px;background:linear-gradient(90deg,#5fe6d6 var(--pbp-vol,80%),rgba(255,255,255,.14) var(--pbp-vol,80%))}
.lum-pbp-vol .lum-pbp-range::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;border-radius:50%;background:#fff;border:1px solid rgba(0,0,0,.45);box-shadow:0 1px 4px rgba(0,0,0,.5);cursor:grab}
.lum-pbp-vol .lum-pbp-range::-moz-range-thumb{width:12px;height:12px;border:1px solid rgba(0,0,0,.45);border-radius:50%;background:#fff;cursor:grab}
.lum-pbp-pl{position:relative}
.lum-pbp-list{position:absolute;right:0;bottom:52px;width:320px;max-height:min(58vh,440px);overflow:auto;
  background:rgba(18,20,30,.96);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.14);border-radius:12px;
  box-shadow:0 12px 40px -8px rgba(0,0,0,.7);padding:6px;display:none}
.lum-pbp-list.open{display:block}
.lum-pbp-li{display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:8px;cursor:pointer}
.lum-pbp-li:hover{background:rgba(255,255,255,.09)}
.lum-pbp-li.cur{background:rgba(167,139,250,.2)}
.lum-pbp-li img{width:38px;height:38px;border-radius:6px;object-fit:cover;flex:0 0 auto;background:rgba(255,255,255,.08)}
.lum-pbp-li-t{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12.5px}
.lum-pbp-li-ep{flex:0 0 auto;font:700 10px ui-monospace,monospace;color:rgba(244,246,251,.55)}
.lum-pbp-x{margin-left:2px}
@media (max-width:640px){
  .lum-pbp{gap:9px;padding:8px 10px}
  .lum-pbp-cover{width:44px;height:44px}
  .lum-pbp-meta{flex-basis:auto;max-width:38vw}
  .lum-pbp-vol,.lum-pbp-time.dur{display:none}
  .lum-pbp-list{width:min(92vw,320px)}
}
@media (prefers-reduced-motion:reduce){.lum-pbp{transition:none}}
/* live-mode: swap the scrubber for a LIVE badge (no duration on a stream) */
.lum-pbp-live{display:none;align-items:center;gap:7px;font:700 11px ui-monospace,monospace;color:#ff6a78;letter-spacing:.6px}
.lum-pbp-live::before{content:"";width:8px;height:8px;border-radius:50%;background:#ff3b4e;box-shadow:0 0 9px #ff3b4e;animation:lumpblink 2s infinite}
.lum-pbp.live .lum-pbp-seek,.lum-pbp.live .lum-pbp-time{display:none}
.lum-pbp.live .lum-pbp-live{display:inline-flex}
@keyframes lumpblink{70%{opacity:.25}}
@media (prefers-reduced-motion:reduce){.lum-pbp-live::before{animation:none}}
</style>
<div id="lum-pbp" class="lum-pbp" role="region" aria-label="Podcast player" hidden>
  <img class="lum-pbp-cover" alt="" src="" onerror="this.style.visibility='hidden'">
  <div class="lum-pbp-meta"><span class="lum-pbp-title">—</span><span class="lum-pbp-ep"></span></div>
  <button class="lum-pbp-btn lum-pbp-play" type="button" aria-label="Play" data-a="play">▶</button>
  <div class="lum-pbp-scrubwrap">
    <span class="lum-pbp-time cur">0:00</span>
    <input class="lum-pbp-range lum-pbp-seek" type="range" min="0" max="1000" value="0" aria-label="Seek">
    <span class="lum-pbp-time dur">0:00</span>
    <span class="lum-pbp-live">LIVE</span>
  </div>
  <div class="lum-pbp-vol">
    <button class="lum-pbp-btn" type="button" aria-label="Mute" data-a="mute">🔊</button>
    <input class="lum-pbp-range lum-pbp-volr" type="range" min="0" max="100" value="80" aria-label="Volume">
  </div>
  <div class="lum-pbp-pl">
    <button class="lum-pbp-btn" type="button" aria-label="Playlist" data-a="pl">☰</button>
    <div class="lum-pbp-list" role="listbox" aria-label="Episodes"></div>
  </div>
  <button class="lum-pbp-btn lum-pbp-x" type="button" aria-label="Hide player" data-a="close">✕</button>
  <audio class="lum-pbp-audio" preload="none"></audio>
</div>
<script id="lum-pbp-js">
(function(){
  var BOOT = <?php echo $pbpJson; ?>;
  var LS = 'lum_pbp_state_v1';
  var bar = document.getElementById('lum-pbp'); if(!bar || !BOOT.list || !BOOT.list.length) return;
  var au = bar.querySelector('.lum-pbp-audio'),
      cover = bar.querySelector('.lum-pbp-cover'), titleEl = bar.querySelector('.lum-pbp-title'),
      epEl = bar.querySelector('.lum-pbp-ep'), playBtn = bar.querySelector('.lum-pbp-play'),
      seek = bar.querySelector('.lum-pbp-seek'), curEl = bar.querySelector('.lum-pbp-time.cur'),
      durEl = bar.querySelector('.lum-pbp-time.dur'), muteBtn = bar.querySelector('[data-a=mute]'),
      volr = bar.querySelector('.lum-pbp-volr'), plBtn = bar.querySelector('[data-a=pl]'),
      plBox = bar.querySelector('.lum-pbp-list'), list = BOOT.list, cur = 0, seeking = false;

  function fmt(s){ s=Math.max(0,s||0); var m=Math.floor(s/60),ss=Math.floor(s%60); return m+':' + (ss<10?'0':'') + ss; }
  function save(){ try{ localStorage.setItem(LS, JSON.stringify({i:cur, t:au.currentTime||0, playing:!au.paused, vol:au.volume, muted:au.muted})); }catch(e){} }
  function paintSeek(){ var d=au.duration||0, p=d? (au.currentTime/d)*100:0; seek.value=Math.round(p*10); seek.style.setProperty('--pbp-pct', p+'%'); }
  function paintVol(){ var v=au.muted?0:au.volume; volr.value=Math.round(v*100); volr.style.setProperty('--pbp-vol', (v*100)+'%'); muteBtn.textContent = au.muted||v===0 ? '🔇' : '🔊'; }
  function renderList(){ plBox.innerHTML = list.map(function(t,i){ return '<div class="lum-pbp-li'+(i===cur?' cur':'')+'" data-i="'+i+'" role="option">'+
      (t.cover?'<img src="'+t.cover+'" alt="" onerror="this.style.visibility=\'hidden\'">':'<img alt="">')+'<span class="lum-pbp-li-t">'+esc(t.title)+'</span><span class="lum-pbp-li-ep">'+esc(t.ep||'')+'</span></div>'; }).join(''); }
  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

  // Live now-playing: browsers strip ICY metadata, so poll the station's public
  // now-playing endpoint and show the current episode on the ep line.
  var nowUrl = (BOOT.nowUrl||'');
  function slugOf(t){ return (t&&t.src)? t.src.split('?')[0].replace(/\/+$/,'').split('/').pop() : ''; }
  function pollNow(){ if(!nowUrl) return; var t=list[cur]; if(!t||!t.live||bar.hidden) return; var slug=slugOf(t);
    fetch(nowUrl,{cache:'no-store'}).then(function(r){return r.json();}).then(function(d){
      if(!d||!d.now) return; var t2=list[cur]; if(!t2||!t2.live||slugOf(t2)!==slug) return;   // still same live station
      var title=d.now[slug]||''; epEl.textContent=title; if(title) epEl.title=title;
    }).catch(function(){}); }
  setInterval(pollNow, 15000);

  function load(i, opts){ opts=opts||{}; if(i<0||i>=list.length) return; cur=i; var t=list[i];
    var live=!!t.live; bar.classList.toggle('live', live);
    au.src=t.src; cover.src=t.cover||''; cover.style.visibility=t.cover?'visible':'hidden';
    titleEl.textContent=t.title; titleEl.title=t.title; epEl.textContent=live?'':(t.ep||''); renderList();
    if(!live && opts.time){ au.currentTime = opts.time; }
    if(live) pollNow();
    if('mediaSession' in navigator){ try{ navigator.mediaSession.metadata=new MediaMetadata({title:t.title, artist:BOOT.show||'', artwork:t.cover?[{src:t.cover,sizes:'512x512',type:'image/jpeg'}]:[]}); }catch(e){} }
    if(opts.play){ au.play().catch(function(){}); }
    save();
  }
  function togglePlay(){ if(au.paused) au.play().catch(function(){}); else au.pause(); }

  // events
  playBtn.addEventListener('click', togglePlay);
  au.addEventListener('play', function(){ playBtn.textContent='⏸'; playBtn.setAttribute('aria-label','Pause'); save(); });
  au.addEventListener('pause', function(){ playBtn.textContent='▶'; playBtn.setAttribute('aria-label','Play'); save(); });
  au.addEventListener('loadedmetadata', function(){ durEl.textContent=fmt(au.duration); paintSeek(); });
  au.addEventListener('timeupdate', function(){ var lv=list[cur]&&list[cur].live; if(!seeking && !lv){ curEl.textContent=fmt(au.currentTime); paintSeek(); } if(!lv && ((au.currentTime|0)%5)===0) save(); });
  var reTO=null;
  function reconnect(){ if(reTO) return; reTO=setTimeout(function(){ reTO=null; var t=list[cur]; if(t&&t.live){ au.src=t.src; try{au.load();}catch(e){} au.play().catch(function(){}); } }, 2000); }
  au.addEventListener('ended', function(){ if(list[cur]&&list[cur].live){ reconnect(); return; } if(cur+1<list.length) load(cur+1,{play:true}); else save(); });
  au.addEventListener('error', function(){ if(list[cur]&&list[cur].live && !au.paused) reconnect(); });
  au.addEventListener('stalled', function(){ if(list[cur]&&list[cur].live && !au.paused){ try{ au.load(); au.play().catch(function(){}); }catch(e){} } });
  seek.addEventListener('input', function(){ seeking=true; var d=au.duration||0; curEl.textContent=fmt((seek.value/1000)*d); seek.style.setProperty('--pbp-pct',(seek.value/10)+'%'); });
  seek.addEventListener('change', function(){ var d=au.duration||0; au.currentTime=(seek.value/1000)*d; seeking=false; save(); });
  volr.addEventListener('input', function(){ au.muted=false; au.volume=Math.max(0,Math.min(1,volr.value/100)); paintVol(); save(); });
  muteBtn.addEventListener('click', function(){ au.muted=!au.muted; paintVol(); save(); });
  plBtn.addEventListener('click', function(e){ e.stopPropagation(); plBox.classList.toggle('open'); });
  document.addEventListener('click', function(e){ if(!bar.querySelector('.lum-pbp-pl').contains(e.target)) plBox.classList.remove('open'); });
  plBox.addEventListener('click', function(e){ var li=e.target.closest('.lum-pbp-li'); if(!li) return; load(+li.getAttribute('data-i'),{play:true,time:0}); plBox.classList.remove('open'); });
  bar.querySelector('[data-a=close]').addEventListener('click', function(){ au.pause(); bar.classList.remove('ready'); try{ document.documentElement.style.setProperty('--lum-pbp-h','0px'); }catch(e){} setTimeout(function(){ bar.hidden=true; },300); try{ localStorage.setItem(LS+'_hidden','1'); }catch(e){} });
  window.addEventListener('pagehide', save); window.addEventListener('beforeunload', save);
  if('mediaSession' in navigator){ try{ navigator.mediaSession.setActionHandler('play',togglePlay); navigator.mediaSession.setActionHandler('pause',togglePlay);
    navigator.mediaSession.setActionHandler('previoustrack',function(){ if(cur>0) load(cur-1,{play:true,time:0}); });
    navigator.mediaSession.setActionHandler('nexttrack',function(){ if(cur+1<list.length) load(cur+1,{play:true,time:0}); }); }catch(e){} }

  // Public API so on-page cards / station lists can drive THIS one audio element.
  // publish the bar height so other fixed players (PodRadio popup/PiP) can stack above it
  function setBarH(){ try{ document.documentElement.style.setProperty('--lum-pbp-h', (bar.hidden?0:bar.offsetHeight)+'px'); }catch(e){} }
  window.addEventListener('resize', setBarH);
  function reveal(){ bar.hidden=false; requestAnimationFrame(function(){ bar.classList.add('ready'); }); setBarH(); try{ localStorage.removeItem(LS+'_hidden'); }catch(e){} }
  window.LumPBP = {
    playSrc:function(src,meta){ meta=meta||{};
      for(var i=0;i<list.length;i++){ if(list[i].src===src){ if(meta.live!=null) list[i].live=!!meta.live; load(i,{play:true,time:0}); reveal(); return; } }
      list.unshift({src:src,title:meta.title||'Episode',cover:meta.cover||'',ep:meta.ep||(meta.live?'LIVE':''),live:!!meta.live}); load(0,{play:true,time:0}); reveal(); },
    playStation:function(url,meta){ meta=meta||{}; meta.live=true; if(meta.ep==null) meta.ep='LIVE'; this.playSrc(url,meta); },
    show:reveal };

  // Restore prior state (resume-on-reload) or default to the latest episode.
  var st={}; try{ st=JSON.parse(localStorage.getItem(LS)||'{}')||{}; }catch(e){}
  var startI = (typeof st.i==='number' && st.i>=0 && st.i<list.length) ? st.i : 0;
  au.volume = (typeof st.vol==='number') ? st.vol : 0.8; au.muted = !!st.muted; paintVol();
  load(startI, { time: st.t||0, play: !!st.playing });   // play() may be blocked until a gesture — the ▶ stays visible then
  var hidden=false; try{ hidden = localStorage.getItem(LS+'_hidden')==='1' && !st.playing; }catch(e){}
  if(!hidden){ bar.hidden=false; requestAnimationFrame(function(){ bar.classList.add('ready'); }); setBarH(); }
})();
</script>
