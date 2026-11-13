<?php
/**
 * ArticlesManager — Article Showcase (reusable magazine layout).
 *
 * render_article_showcase() powers the [[article-showcase]] shortcode. Drop it into
 * any Page Manager page's main_content — CMS-native, no auto-inject. Reads the site's
 * own articles (admin/data/Articles/index.json), newest-first, so it works on any
 * article site. Content stays per-site;
 * this is the shared TEMPLATE.
 *
 * Attributes (all optional):
 *   hero     = "feature" (default) | "carousel"   big rotating feature vs. auto-playing strip
 *   grid     = "1" (default) | "0"                recency river below the hero
 *   count    = river card cap (default 9)
 *   featured = hero pool / carousel slide count (default 5)
 *   title    = section title (default "The Edit")
 *   eyebrow  = section eyebrow (default "Features")
 *   accent   = hex accent (default "#00ffff")
 *   more_url = optional "View all" link
 *   rotate   = "1" (default) | "0"                day-of-week rotation of the big feature
 *   autoplay = ms between carousel slides (default 5000; "0" disables)
 *   height   = hero height in px (default: tall, scales with the window; 320-900)
 *   radius   = corner radius in px (default 6)
 *   prefer_images = "1" puts articles that have an image first in the hero and river
 *                  ; default "0" = strictly newest first
 *
 * Images: resolved per article (convention file /media/images/articles/{slug}.{jpg,…}
 * → featured_image/og_image → first <img> in the page body). Cards without an image
 * degrade gracefully to the glassmorphic text card.
 *
 * @module ArticlesManager
 */
declare(strict_types=1);

if (!function_exists('render_article_showcase')) {

    function asx_esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A title as HTML: escaped, then the few inline tags a title legitimately
     * carries are let back in. Nothing with attributes survives.
     */
    function asx_title_html(string $t): string {
        $h = asx_esc($t);
        return (string)preg_replace('~&lt;(/?)(em|i|strong|b)&gt;~i', '<$1$2>', $h);
    }

    /** Where a card links: external_url wins, then /blog/{slug}, else the page route. */
    function asx_article_url(array $a, string $root): string {
        if (!empty($a['external_url'])) return (string)$a['external_url'];
        $slug = (string)($a['slug'] ?? '');
        if (!empty($a['__asx_store']) && is_file($root . '/article.php')) return '/blog/' . rawurlencode($slug);
        return '/?p=' . rawurlencode($slug);
    }

    /** Resolve a display image URL for an article. Static-cached per request. */
    function asx_article_image(string $root, string $slug, string $hero = ''): string {
        static $cache = [];
        if (isset($cache[$slug])) return $cache[$slug];

        // 0) The canonical store's own hero_image (external passes; local must exist).
        if ($hero !== '') {
            if (preg_match('~^https?://~i', $hero)) return $cache[$slug] = $hero;
            $rel = '/' . ltrim($hero, '/');
            if (is_file($root . $rel)) return $cache[$slug] = $rel;
        }

        // 1) Convention file: /media/images/articles/{slug}.{ext}
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $ext) {
            if (is_file($root . '/media/images/articles/' . $slug . '.' . $ext)) {
                return $cache[$slug] = '/media/images/articles/' . $slug . '.' . $ext;
            }
        }
        // 2) / 3) Page JSON: featured_image / og_image, else first <img> in body.
        $pf = $root . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
        if (is_file($pf)) {
            $pj  = json_decode((string)@file_get_contents($pf), true) ?: [];
            $img = $pj['featured_image'] ?? ($pj['og_image'] ?? '');
            if (is_string($img) && $img !== '') return $cache[$slug] = $img;
            $body = '';
            foreach (($pj['components'] ?? []) as $c) {
                if (is_array($c) && isset($c['content'])) $body .= (string)$c['content'];
            }
            if ($body !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
                $src = trim($m[1]);
                // External URLs pass through; local paths must exist on disk (skip broken refs).
                if (preg_match('~^https?://~i', $src)) {
                    return $cache[$slug] = $src;
                }
                $rel = '/' . ltrim($src, '/');
                if (is_file($root . $rel)) {
                    return $cache[$slug] = $rel;
                }
            }
        }
        return $cache[$slug] = '';
    }

    /** One article card. $variant: 'feature' | 'slide' | 'river'. */
    function asx_card(array $a, string $variant, string $root, int $pos = 0): string {
        $slug = (string)($a['slug'] ?? '');
        if ($slug === '') return '';
        $url     = asx_article_url($a, $root);
        $track   = $pos > 0 ? ' data-asx-slug="' . asx_esc($slug) . '" data-asx-pos="' . ($variant === 'river' ? 'card' : 'slide') . $pos . '"' : '';
        $eyebrow = (string)($a['eyebrow'] ?? '');
        $title   = (string)($a['title'] ?? $slug);
        $excerpt = (string)($a['excerpt'] ?? '');
        /* The publish date on every card: a visitor reads a
           recent date and knows at a glance that the site is current. */
        $ts      = !empty($a['date']) ? strtotime((string)$a['date']) : false;
        $dateIso = $ts ? date('Y-m-d', $ts) : '';
        $dateTxt = $ts ? date('M j, Y', $ts) : '';

        $img    = asx_article_image($root, $slug, (string)($a['hero_image'] ?? ''));
        $imgCls = $img !== '' ? ' asx-card--img' : '';
        $style  = $img !== '' ? ' style="--asx-img:url(&quot;' . asx_esc($img) . '&quot;)"' : '';

        ob_start(); ?>
        <a class="asx-card asx-card--<?= asx_esc($variant) . $imgCls ?>" href="<?= asx_esc($url) ?>"<?= $style . $track ?>>
          <span class="asx-card-scrim"></span>
          <span class="asx-card-body">
            <?php if ($eyebrow !== '' || $dateTxt !== ''): ?><span class="asx-card-eyebrow"><?= asx_esc($eyebrow) ?><?php if ($dateTxt !== ''): ?><time class="asx-card-date" datetime="<?= asx_esc($dateIso) ?>"><?= ($eyebrow !== '' ? ' &middot; ' : '') . asx_esc($dateTxt) ?></time><?php endif; ?></span><?php endif; ?>
            <span class="asx-card-title"><?= asx_title_html($title) ?></span>
            <?php if ($excerpt !== '' && $variant !== 'river'): ?><span class="asx-card-excerpt"><?= asx_title_html($excerpt) ?></span><?php endif; ?>
            <span class="asx-card-more">Read more &rarr;</span>
          </span>
        </a>
        <?php
        return ob_get_clean();
    }

    function render_article_showcase(array $attrs = []): string {
        if (!defined('SITE_ROOT')) return '';
        $root = SITE_ROOT;

        // ── Load articles, newest-first ─────────────────────────────────────
        // The canonical store first: published articles whose body exists (a
        // half-generated article must not become a slide that links to a shell).
        $articles = [];
        $storeLib = $root . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
        if (!class_exists('\\ArticlesManager\\ArticleStore') && is_file($storeLib)) require_once $storeLib;
        if (class_exists('\\ArticlesManager\\ArticleStore')) {
            try {
                $store = new \ArticlesManager\ArticleStore($root);
                foreach ($store->all(['published_only' => true]) as $a) {
                    if (!is_array($a) || trim((string)($a['body_html'] ?? '')) === '') continue;
                    $a['__asx_store'] = true;
                    $articles[] = $a;
                }
            } catch (\Throwable $e) { $articles = []; }
        }
        // Legacy index — only for a site the canonical store has nothing for.
        if (empty($articles)) {
            $index = $root . '/admin/data/Articles/index.json';
            if (is_file($index)) {
                $data     = json_decode((string)@file_get_contents($index), true) ?: [];
                $articles = $data['articles'] ?? $data;
            }
        }
        if (!is_array($articles) || empty($articles)) return '<!-- article-showcase: no articles -->';
        $articles = array_values(array_filter($articles, fn($a) => is_array($a) && !empty($a['slug'])));
        usort($articles, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        if (empty($articles)) return '<!-- article-showcase: no valid articles -->';

        // ── Attributes ──────────────────────────────────────────────────────
        $hero      = (($attrs['hero'] ?? 'feature') === 'carousel') ? 'carousel' : 'feature';
        $showGrid  = (($attrs['grid'] ?? '1') !== '0');
        $count     = max(1, (int)($attrs['count'] ?? 9));
        $featuredN = max(1, (int)($attrs['featured'] ?? 5));
        $title     = (string)($attrs['title']   ?? 'The Edit');
        $eyebrow   = (string)($attrs['eyebrow']  ?? 'Features');
        $accent    = (string)($attrs['accent']   ?? '#00ffff');
        $moreUrl   = (string)($attrs['more_url'] ?? '');
        $rotate    = (($attrs['rotate'] ?? '1') !== '0');
        $autoplay  = (int)($attrs['autoplay'] ?? 5000);
        $height    = isset($attrs['height']) ? max(320, min(900, (int)$attrs['height'])) : 0;
        $radius    = isset($attrs['radius']) ? max(0, min(40, (int)$attrs['radius'])) : 6;
        $preferImg = (($attrs['prefer_images'] ?? '0') === '1');
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#00ffff';

        $total = count($articles);
        // Strictly newest-first: the newest article IS the first slide.
        // prefer_images=1 keeps the older behaviour for a site whose newest
        // articles have no picture (imaged-first, each newest-first within).
        $poolSource = $articles;
        if ($preferImg) {
            $imaged = $textOnly = [];
            foreach ($articles as $a) {
                if (asx_article_image($root, (string)$a['slug'], (string)($a['hero_image'] ?? '')) !== '') $imaged[] = $a;
                else $textOnly[] = $a;
            }
            $poolSource = array_merge($imaged, $textOnly);
        }
        $pool = array_slice($poolSource, 0, min($featuredN, $total));

        // Which pool item is today's big feature (day-of-week rotation; server UTC).
        $heroIdx = 0;
        if ($rotate && count($pool) > 1) $heroIdx = ((int)date('N') - 1) % count($pool);

        // Slugs shown in the hero → excluded from the river (no dupes).
        $heroSlugs = [];
        if ($hero === 'carousel') {
            foreach ($pool as $p) $heroSlugs[(string)$p['slug']] = true;
        } else {
            $heroSlugs[(string)$pool[$heroIdx]['slug']] = true;
        }
        // River: the same order as the hero pool, hero slides excluded.
        $river = [];
        foreach ($poolSource as $a) {
            if (isset($heroSlugs[(string)$a['slug']])) continue;
            $river[] = $a;
            if (count($river) >= $count) break;
        }
        // the grid under the hero must never come out empty or thin. A site whose
        // articles all fit in the slider (five or fewer) got NO grid, so people added a second shortcode.
        // Under one full row (3) of unshown articles, the river lists every article, slider ones included.
        if (count($river) < 3) {
            $river = array_slice(array_values($poolSource), 0, $count);
        }

        // Assets emitted once per request even if the shortcode appears twice.
        static $assetsEmitted = false;

        ob_start();
        ?>
<section class="asx-showcase" style="--asx-accent:<?= asx_esc($accent) ?>;--asx-radius:<?= (int)$radius ?>px<?= $height ? ';--asx-hero-h:' . (int)$height . 'px' : '' ?>" aria-label="<?= asx_esc($title) ?>">
<?php if (!$assetsEmitted): $assetsEmitted = true; ?>
<style id="asx-showcase-css">
.asx-showcase{width:100%;box-sizing:border-box;padding:8px 0 40px;position:relative;z-index:1}
.asx-inner{max-width:none;margin:0 auto;padding:0 24px;box-sizing:border-box}
.asx-head{display:flex;align-items:baseline;gap:12px;margin:0 0 20px}
.asx-head-eyebrow{font-size:.6rem;letter-spacing:.22em;text-transform:uppercase;color:var(--asx-accent);opacity:.85}
.asx-head-title{font-size:clamp(1.2rem,2.5vw,1.7rem);color:#fff;font-weight:400;letter-spacing:.03em;line-height:1;margin:0}
.asx-head-more{font-size:.7rem;color:rgba(255,255,255,.45);letter-spacing:.06em;margin-left:auto;text-decoration:none;white-space:nowrap}
.asx-head-more:hover{color:var(--asx-accent)}
/* shared card */
.asx-card{position:relative;display:block;box-sizing:border-box;overflow:hidden;text-decoration:none;color:inherit;border:1px solid rgba(255,255,255,.1);border-radius:var(--asx-radius,6px);background:rgba(255,255,255,.04);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);transition:border-color .25s,box-shadow .25s,transform .25s}
.asx-card:hover{border-color:rgba(255,255,255,.2);box-shadow:0 12px 40px rgba(0,0,0,.32);transform:translateY(-2px)}
.asx-card--img{background-image:var(--asx-img);background-size:cover;background-position:center}
.asx-card-scrim{position:absolute;inset:0;pointer-events:none}
.asx-card--img .asx-card-scrim{background:linear-gradient(180deg,rgba(8,10,20,.12) 0%,rgba(8,10,20,.55) 52%,rgba(8,10,20,.92) 100%)}
.asx-card-body{position:relative;display:flex;flex-direction:column;justify-content:flex-end;height:100%;box-sizing:border-box}
.asx-card-eyebrow{font-size:.66rem;letter-spacing:.2em;text-transform:uppercase;color:var(--asx-accent);opacity:.92;margin-bottom:8px}
.asx-card-title{font-weight:400;line-height:1.16;color:#fff;text-shadow:0 1px 12px rgba(0,0,0,.4)}
.asx-card-excerpt{line-height:1.55;color:rgba(255,255,255,.72);margin-top:10px;display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;text-shadow:0 1px 10px rgba(0,0,0,.5)}
.asx-card-more{font-size:.64rem;letter-spacing:.12em;text-transform:uppercase;color:var(--asx-accent);opacity:0;margin-top:14px;transition:opacity .25s}
.asx-card:hover .asx-card-more{opacity:.9}
/* hero: single big feature */
.asx-feature{margin:0 0 14px}
.asx-card--feature{min-height:var(--asx-hero-h,clamp(420px,62vh,680px));padding:44px 44px 38px}
.asx-card--feature .asx-card-title{font-size:clamp(1.6rem,3.4vw,2.9rem)}
.asx-card--feature .asx-card-excerpt{font-size:.85rem;-webkit-line-clamp:3}
.asx-card--feature::before{content:"";position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--asx-accent),transparent);opacity:.35;z-index:2}
/* hero: carousel */
.asx-carousel-wrap{position:relative;margin:0 0 14px}
.asx-carousel{position:relative;display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;padding-bottom:4px;scrollbar-width:none}
.asx-carousel::-webkit-scrollbar{display:none}
/* ONE big slide at a time, tall — the hero is the page's main display. */
.asx-carousel .asx-card--slide{flex:0 0 100%;scroll-snap-align:center;min-height:var(--asx-hero-h,clamp(420px,62vh,680px));padding:44px 56px 38px}
.asx-carousel .asx-card--slide .asx-card-body{max-width:820px}
.asx-carousel .asx-card--slide .asx-card-title{font-size:clamp(1.6rem,3.4vw,2.9rem)}
.asx-carousel .asx-card--slide .asx-card-excerpt{font-size:.95rem;-webkit-line-clamp:3}
.asx-card-title em,.asx-card-title i{font-style:italic}
.asx-card-date{color:rgba(255,255,255,.78);letter-spacing:.14em}
.asx-cx-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:4;width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:rgba(10,12,22,.55);color:#fff;font-size:1.3rem;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);transition:background .2s,border-color .2s;padding:0}
.asx-cx-arrow:hover{background:rgba(10,12,22,.8);border-color:var(--asx-accent)}
.asx-cx-prev{left:6px}.asx-cx-next{right:6px}
.asx-cx-dots{display:flex;gap:8px;justify-content:center;margin-top:12px}
.asx-cx-dot{width:8px;height:8px;border-radius:50%;border:0;padding:0;background:rgba(255,255,255,.28);cursor:pointer;transition:width .25s,background .25s,border-radius .25s}
.asx-cx-dot.is-on{background:var(--asx-accent);width:22px;border-radius:4px}
@media(hover:none){.asx-cx-arrow{display:none}}
/* recency river */
.asx-river{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.asx-river .asx-card--river{min-height:180px;padding:20px 20px 18px}
.asx-river .asx-card--river .asx-card-title{font-size:1.02rem}
@media(max-width:900px){.asx-river{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.asx-river{grid-template-columns:1fr}.asx-card--feature,.asx-carousel .asx-card--slide{min-height:var(--asx-hero-h,clamp(320px,58vh,480px));padding:26px 22px 24px}.asx-inner{padding:0 12px}}
</style>
<script id="asx-showcase-js">
(function(){
  function initCarousel(cx){
    if(cx.__asxInit) return; cx.__asxInit=true;
    var wrap=cx.closest('.asx-carousel-wrap');
    var slides=Array.prototype.slice.call(cx.querySelectorAll('.asx-card--slide'));
    if(slides.length<2){ return; }
    var delay=parseInt(cx.getAttribute('data-asx-autoplay'),10)||0;
    var idx=0,timer=null,paused=false;
    var dots=wrap?wrap.querySelector('.asx-cx-dots'):null;
    if(dots){ slides.forEach(function(_,i){ var b=document.createElement('button'); b.type='button'; b.className='asx-cx-dot'; b.setAttribute('aria-label','Go to slide '+(i+1)); b.addEventListener('click',function(){ go(i); restart(); }); dots.appendChild(b); }); }
    function paint(){ if(dots){ Array.prototype.forEach.call(dots.children,function(d,i){ d.classList.toggle('is-on',i===idx); }); } }
    function go(i){ idx=(i+slides.length)%slides.length; var s=slides[idx]; if(s){ cx.scrollTo({left:s.offsetLeft,behavior:'smooth'}); } paint(); }
    function next(){ go(idx+1); } function prev(){ go(idx-1); }
    function start(){ stop(); if(delay>0){ timer=setInterval(function(){ if(!paused) next(); },delay); } }
    function stop(){ if(timer){ clearInterval(timer); timer=null; } }
    function restart(){ start(); }
    if(wrap){ var pv=wrap.querySelector('.asx-cx-prev'),nx=wrap.querySelector('.asx-cx-next'); if(pv)pv.addEventListener('click',function(){ prev(); restart(); }); if(nx)nx.addEventListener('click',function(){ next(); restart(); }); }
    cx.addEventListener('pointerenter',function(){ paused=true; });
    cx.addEventListener('pointerleave',function(){ paused=false; });
    cx.addEventListener('touchstart',function(){ paused=true; },{passive:true});
    document.addEventListener('visibilitychange',function(){ paused=document.hidden; });
    var st; cx.addEventListener('scroll',function(){ clearTimeout(st); st=setTimeout(function(){ var best=0,bd=1e9,sl=cx.scrollLeft; slides.forEach(function(s,i){ var d=Math.abs(s.offsetLeft-sl); if(d<bd){bd=d;best=i;} }); idx=best; paint(); },120); },{passive:true});
    paint(); start();
  }
  /* which slide / card was clicked, and where it sat. */
  document.addEventListener('click',function(ev){
    var a=ev.target&&ev.target.closest?ev.target.closest('.asx-card[data-asx-pos]'):null; if(!a) return;
    var pos=a.getAttribute('data-asx-pos')||'';
    var u='/panels/stat-pixel.php?t='+(pos.indexOf('slide')===0?'hero_click':'river_click')+'&s='+encodeURIComponent(a.getAttribute('data-asx-slug')||'')+'&c='+encodeURIComponent(pos);
    try{ if(navigator.sendBeacon){ navigator.sendBeacon(u); return; } }catch(e){}
    new Image().src=u;
  },true);
  function initAll(){ document.querySelectorAll('[data-asx-carousel]').forEach(initCarousel); }
  if(document.readyState!=='loading') initAll(); else document.addEventListener('DOMContentLoaded',initAll);
})();
</script>
<?php endif; ?>
  <div class="asx-inner">
    <div class="asx-head">
      <span class="asx-head-eyebrow"><?= asx_esc($eyebrow) ?></span>
      <h2 class="asx-head-title"><?= asx_esc($title) ?></h2>
      <?php if ($moreUrl !== ''): ?><a class="asx-head-more" href="<?= asx_esc($moreUrl) ?>">View all &rarr;</a><?php endif; ?>
    </div>

    <?php if ($hero === 'carousel'): ?>
    <div class="asx-carousel-wrap">
      <button class="asx-cx-arrow asx-cx-prev" type="button" aria-label="Previous">&lsaquo;</button>
      <div class="asx-carousel" data-asx-carousel data-asx-autoplay="<?= (int)$autoplay ?>" role="group" aria-label="Featured articles">
        <?php foreach ($pool as $i => $p) echo asx_card($p, 'slide', $root, $i + 1); ?>
      </div>
      <button class="asx-cx-arrow asx-cx-next" type="button" aria-label="Next">&rsaquo;</button>
      <div class="asx-cx-dots" aria-hidden="true"></div>
    </div>
    <?php else: ?>
    <div class="asx-feature">
      <?= asx_card($pool[$heroIdx], 'feature', $root, 1) ?>
    </div>
    <?php endif; ?>

    <?php if ($showGrid && !empty($river)): ?>
    <div class="asx-river">
      <?php foreach ($river as $i => $a) echo asx_card($a, 'river', $root, $i + 1); ?>
    </div>
    <?php endif; ?>
  </div>
</section>
        <?php
        return ob_get_clean();
    }
}
