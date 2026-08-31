<?php
/**
 * ArticlesManager — public shortcode renderers
 *
 * Exposed renderer functions:
 *   render_articles_grid($attrs)     — card grid, primary public view
 *   render_articles_latest($attrs)   — compact latest-N list
 *   render_article_embed($attrs)     — single inline article by slug
 *
 * Attrs (all optional unless noted):
 *   limit       — max items, default 12
 *   category    — filter by category slug
 *   tag         — filter by single tag
 *   featured    — if true, only pinned
 *   columns     — 1..4, default 3
 *   style       — "cards" | "list", default "cards"
 *   show_hero   — 1|0, default 1
 *   show_excerpt— 1|0, default 1
 *
 * @module  ArticlesManager
 * @version 1.0.0
 */
declare(strict_types=1);

require_once __DIR__ . '/ArticleStore.php';

if (!function_exists('render_articles_grid')) {
    function render_articles_grid(array $attrs = []): string
    {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4);
        $store    = new \ArticlesManager\ArticleStore($siteRoot);

        // Geometry first — rows × columns drives the default item count, so
        // [[articles-grid rows=2 columns=4]] fills exactly two rows. An
        // explicit limit attr still wins.
        $cols = max(1, min(4, (int)($attrs['columns'] ?? 3)));
        $rows = isset($attrs['rows']) ? max(1, (int)$attrs['rows']) : 0;
        $defaultLimit = $rows ? $rows * $cols : 12;

        $opts = [
            'limit'          => max(1, (int)($attrs['limit'] ?? $defaultLimit)),
            'category'       => (string)($attrs['category'] ?? ''),
            'tag'            => (string)($attrs['tag'] ?? ''),
            'published_only' => true,
        ];
        $items = $store->all($opts);

        // Featured-home hero — same single-column preview the library renders,
        // now above every grid/latest too (Tom 2026-06-06). Opt out with
        // show_featured=0. The hero article is pulled out of the card list.
        $featured = null;
        if (!isset($attrs['show_featured']) || (string)$attrs['show_featured'] !== '0') {
            $featured = $store->getFeaturedHome();
            if ($featured) {
                $items = array_values(array_filter($items, fn($a) => ($a['slug'] ?? '') !== $featured['slug']));
            }
        }

        if (!empty($attrs['featured'])) {
            $items = array_values(array_filter($items, fn($a) => !empty($a['pinned'])));
        }

        // Skip half-baked articles whose body never finished generating (e.g. Gemini
        // rate-limited mid-pipeline). Without this, dead cards link to empty shells.
        // Opt out with show_empty=1 if a stub-only listing is intentional.
        if (empty($attrs['show_empty'])) {
            $items = array_values(array_filter($items, fn($a) => trim((string)($a['body_html'] ?? '')) !== ''));
        }

        if (empty($items) && !$featured) {
            return '<!-- articles_grid: no articles -->';
        }

        $showHero  = !isset($attrs['show_hero']) || (string)$attrs['show_hero'] !== '0';
        $showExc   = !isset($attrs['show_excerpt']) || (string)$attrs['show_excerpt'] !== '0';
        // Sort bar can be suppressed with sort_controls=0 (e.g. when the grid is
        // a small "latest N" embed where reordering makes no sense).
        $showSort  = !isset($attrs['sort_controls']) || (string)$attrs['sort_controls'] !== '0';
        // A unique id ties the sort bar to its grid so multiple grids on one
        // page each control only their own cards.
        $gid = 'amgrid' . substr(md5((string)microtime(true) . serialize($attrs)), 0, 8);

        // $items already arrives newest-first (pinned override) from
        // ArticleStore::all() — that is the default the cards render in.
        $h = '<div class="am-grid-wrap" id="' . $gid . '">';
        if ($featured) $h .= _am_library_hero($featured);
        if ($showSort) {
            $h .= '<div class="articles-grid__sortbar" role="group" aria-label="Sort articles">';
            $h .= '<span class="articles-grid__sortlabel" id="' . $gid . 'lbl">Sort</span>';
            $h .= '<div class="articles-grid__sortbtns" role="tablist" aria-labelledby="' . $gid . 'lbl">';
            $h .= '<button type="button" class="articles-grid__sort articles-grid__sort--active" data-sort="newest" aria-pressed="true">Newest</button>';
            $h .= '<button type="button" class="articles-grid__sort" data-sort="oldest" aria-pressed="false">Oldest</button>';
            $h .= '<button type="button" class="articles-grid__sort" data-sort="alpha" aria-pressed="false">Title A&ndash;Z</button>';
            $h .= '</div></div>';
        }
        $h .= '<div class="am-grid" style="--am-cols:' . $cols . '">';
        foreach ($items as $a) {
            $slug    = htmlspecialchars((string)$a['slug'], ENT_QUOTES);
            $title   = htmlspecialchars((string)($a['title'] ?? ''), ENT_QUOTES);
            $eyebrow = htmlspecialchars((string)($a['eyebrow'] ?? ''), ENT_QUOTES);
            $excerpt = htmlspecialchars((string)($a['excerpt'] ?? ''), ENT_QUOTES);
            $rawDate = (string)($a['date'] ?? '');
            $date    = !empty($rawDate) ? date('M j, Y', strtotime($rawDate)) : '';
            // Machine-readable sort keys: ISO date (or 0) for date sorts,
            // lowercased title for alpha. Consumed by the sort-bar JS.
            $sortTs    = $rawDate !== '' ? (string)(strtotime($rawDate) ?: 0) : '0';
            $sortTitle = htmlspecialchars(mb_strtolower(trim((string)($a['title'] ?? ''))), ENT_QUOTES);
            $hero    = (string)($a['hero_image'] ?? '');
            $href    = !empty($a['external_url']) ? htmlspecialchars((string)$a['external_url'], ENT_QUOTES)
                                                   : (is_file(SITE_ROOT . '/article.php') ? '/blog/' . $slug : '/page.php?p=' . $slug);
            $h .= '<a class="am-card" href="' . $href . '" data-date="' . $sortTs . '" data-title="' . $sortTitle . '">';
            if ($showHero && $hero) {
                $hh = htmlspecialchars($hero, ENT_QUOTES);
                $h .= '<div class="am-card-hero"><img src="' . $hh . '" alt="' . $title . '" loading="lazy" onerror="this.remove()"></div>';
            }
            $h .= '<div class="am-card-body">';
            if ($eyebrow) $h .= '<div class="am-card-eyebrow">' . $eyebrow . '</div>';
            $h .= '<h3 class="am-card-title">' . $title . '</h3>';
            if ($showExc && $excerpt) $h .= '<p class="am-card-excerpt">' . $excerpt . '</p>';
            if ($date) $h .= '<div class="am-card-date">' . $date . '</div>';
            $h .= '</div></a>';
        }
        $h .= '</div>'; // .am-grid
        $h .= '</div>'; // .am-grid-wrap

        // Per-grid sort behaviour. Cards are server-rendered newest-first; this
        // only re-orders existing DOM nodes (no reload, no refetch).
        if ($showSort) {
            $h .= '<script>(function(){'
                . 'var wrap=document.getElementById(' . json_encode($gid) . ');if(!wrap)return;'
                . 'var grid=wrap.querySelector(".am-grid");if(!grid)return;'
                . 'var btns=wrap.querySelectorAll(".articles-grid__sort");'
                . 'function cards(){return Array.prototype.slice.call(grid.querySelectorAll(".am-card"));}'
                . 'function apply(mode){var c=cards();c.sort(function(a,b){'
                . 'if(mode==="alpha"){return (a.getAttribute("data-title")||"").localeCompare(b.getAttribute("data-title")||"",undefined,{sensitivity:"base"});}'
                . 'var da=parseInt(a.getAttribute("data-date")||"0",10),db=parseInt(b.getAttribute("data-date")||"0",10);'
                . 'return mode==="oldest"?(da-db):(db-da);});'
                . 'c.forEach(function(el){grid.appendChild(el);});}'
                . 'btns.forEach(function(btn){btn.addEventListener("click",function(){'
                . 'btns.forEach(function(b){b.classList.remove("articles-grid__sort--active");b.setAttribute("aria-pressed","false");});'
                . 'btn.classList.add("articles-grid__sort--active");btn.setAttribute("aria-pressed","true");'
                . 'apply(btn.getAttribute("data-sort"));});});'
                . '})();</script>';
        }

        $h .= '<style>.am-grid-wrap{margin:1.5rem 0}'
            . '.articles-grid__sortbar{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;margin:0 0 1.1rem}'
            . '.articles-grid__sortlabel{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#8b94a6;font-weight:600}'
            . '.articles-grid__sortbtns{display:flex;gap:.3rem;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:3px}'
            . '.articles-grid__sort{background:transparent;border:none;color:#c9d1e0;padding:.4rem .85rem;font-size:.82rem;border-radius:6px;cursor:pointer;font-weight:500;letter-spacing:.02em;transition:background .15s,color .15s}'
            . '.articles-grid__sort:hover{background:rgba(255,255,255,.06);color:#f3f6fc}'
            . '.articles-grid__sort--active{background:rgba(255,208,128,.18);color:#ffd080}'
            . '.articles-grid__sort:focus-visible{outline:2px solid rgba(255,208,128,.55);outline-offset:2px}'
            . '.am-grid{display:grid;grid-template-columns:repeat(var(--am-cols,3),1fr);gap:1.2rem;margin:0}'
            . '.am-grid a.am-card{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.10);border-radius:10px;overflow:hidden;transition:transform .15s,background .15s,border-color .15s}'
            . '.am-grid a.am-card:hover{background:rgba(255,255,255,.08);border-color:rgba(255,208,128,.35);transform:translateY(-2px)}'
            . '.am-card-hero{width:100%;overflow:hidden;background:rgba(0,0,0,.3);display:flex;justify-content:center;align-items:center;max-height:420px}'
            . '.am-card-hero img{max-width:100%;max-height:420px;width:100%;height:auto;object-fit:contain;display:block}'
            . '.am-card-body{padding:1rem 1.15rem 1.1rem;display:flex;flex-direction:column;gap:.45rem}'
            . '.am-card-eyebrow{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#ffd080;font-weight:600}'
            . '.am-card-title{font-size:1.05rem;color:#f3f6fc;margin:0;line-height:1.3;font-weight:600}'
            . '.am-card-excerpt{font-size:.87rem;color:#c9d1e0;line-height:1.55;margin:0}'
            . '.am-card-date{font-size:.72rem;color:#8b94a6;letter-spacing:.05em}'
            . '@media(max-width:720px){.am-grid{grid-template-columns:1fr}}</style>';
        return $h;
    }
}

if (!function_exists('render_articles_latest')) {
    function render_articles_latest(array $attrs = []): string
    {
        // Same rows/columns geometry as the grid ([[articles-latest rows=2
        // columns=3]]); when neither rows nor limit is given, default to the
        // classic latest-5 single column. Featured hero renders via the grid.
        $attrs['columns'] = $attrs['columns'] ?? 1;
        if (!isset($attrs['limit']) && !isset($attrs['rows'])) $attrs['limit'] = 5;
        $attrs['style']   = 'list';
        // A short "latest N" list is not a browsable listing — no sort bar.
        if (!isset($attrs['sort_controls'])) $attrs['sort_controls'] = '0';
        return render_articles_grid($attrs);
    }
}

if (!function_exists('render_articles_library')) {
    function render_articles_library(array $attrs = []): string
    {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4);
        $store = new \ArticlesManager\ArticleStore($siteRoot);
        $opts = [
            'limit' => max(1, (int)($attrs['limit'] ?? 100)),
            'published_only' => true,
        ];
        $items = $store->all($opts);
        if (empty($items)) return '<!-- articles_library: no articles -->';

        // Pull the one featured-home article out of the library body — it renders
        // as a full-width hero above the grid instead of as a card inside it.
        $featured = null;
        if (!isset($attrs['show_featured']) || (string)$attrs['show_featured'] !== '0') {
            $featured = $store->getFeaturedHome();
            if ($featured) {
                $items = array_values(array_filter($items, fn($a) => ($a['slug'] ?? '') !== $featured['slug']));
            }
        }

        $minGroup = max(1, (int)($attrs['min_group'] ?? 1));
        $catchall = (string)($attrs['catchall_label'] ?? 'Dispatches');

        // Group by category (genre). Order follows CATEGORY_ORDER;
        // uncategorized articles land in the catch-all.
        $CATEGORY_ORDER = ['Action Drama','Action Franchises','Comedy','Drama','Fantasy','Horror','SciFi','Documentary','Music'];
        $bySubject = [];
        $subjectDates = [];
        $noSubject = [];
        foreach ($items as $a) {
            $cat = (string)($a['category'] ?? '');
            if ($cat === '' || $cat === 'Dispatches' || !in_array($cat, $CATEGORY_ORDER, true)) {
                $noSubject[] = $a; continue;
            }
            $bySubject[$cat] = $bySubject[$cat] ?? [];
            $bySubject[$cat][] = $a;
            $ts = strtotime((string)($a['date'] ?? '')) ?: 0;
            if (!isset($subjectDates[$cat]) || $subjectDates[$cat] < $ts) {
                $subjectDates[$cat] = $ts;
            }
        }

        if ($minGroup > 1) {
            foreach ($bySubject as $subj => $arts) {
                if (count($arts) < $minGroup) {
                    foreach ($arts as $a) $noSubject[] = $a;
                    unset($bySubject[$subj]);
                }
            }
        }

        // Sort sections by the canonical category order
        uksort($bySubject, function($a, $b) use ($CATEGORY_ORDER) {
            $ai = array_search($a, $CATEGORY_ORDER, true);
            $bi = array_search($b, $CATEGORY_ORDER, true);
            if ($ai === false) $ai = 999;
            if ($bi === false) $bi = 999;
            return $ai <=> $bi;
        });

        $html = '<div class="am-library">';
        if ($featured) $html .= _am_library_hero($featured);
        $html .= _am_library_toolbar();
        foreach ($bySubject as $subject => $arts) {
            $count = count($arts);
            $safeSubj = htmlspecialchars($subject, ENT_QUOTES);
            $html .= '<section class="am-library-section">';
            $html .= '<div class="am-library-head">';
            $html .= '<h2 class="am-library-title">' . $safeSubj . '</h2>';
            $html .= '<span class="am-library-count">' . $count . ($count === 1 ? ' article' : ' articles') . '</span>';
            $html .= '</div>';
            $html .= '<div class="am-library-grid">';
            foreach ($arts as $a) $html .= _am_library_card($a);
            $html .= '</div>';
            $html .= '</section>';
        }
        if (!empty($noSubject)) {
            usort($noSubject, fn($a,$b) => (strtotime((string)($b['date'] ?? '')) ?: 0) <=> (strtotime((string)($a['date'] ?? '')) ?: 0));
            $html .= '<section class="am-library-section am-library-section--dispatches">';
            $html .= '<div class="am-library-head">';
            $html .= '<h2 class="am-library-title">' . htmlspecialchars($catchall, ENT_QUOTES) . '</h2>';
            $html .= '<span class="am-library-count">' . count($noSubject) . '</span>';
            $html .= '</div>';
            $html .= '<div class="am-library-grid">';
            foreach ($noSubject as $a) $html .= _am_library_card($a);
            $html .= '</div></section>';
        }
        $html .= '</div>';
        $html .= _am_library_styles();
        return $html;
    }


    /**
     * Full-width hero card for the "Featured on Home Page" article.
     * Renders above _am_library_toolbar. Uses a split layout when hero_image exists,
     * falls back to text-only when it doesn't.
     */
    function _am_library_hero(array $a): string
    {
        $slug    = htmlspecialchars((string)($a['slug'] ?? ''), ENT_QUOTES);
        $title   = htmlspecialchars((string)($a['title'] ?? ''), ENT_QUOTES);
        $eyebrow = htmlspecialchars((string)($a['eyebrow'] ?? ''), ENT_QUOTES);
        $excerpt = htmlspecialchars((string)($a['excerpt'] ?? ''), ENT_QUOTES);
        $date    = !empty($a['date']) ? date('M j, Y', strtotime((string)$a['date'])) : '';
        $hero    = (string)($a['hero_image'] ?? '');
        $href    = !empty($a['external_url']) ? htmlspecialchars((string)$a['external_url'], ENT_QUOTES) : '/blog/' . $slug;

        $h  = '<a class="aml-hero-card ' . ($hero ? 'aml-hero-card--split' : 'aml-hero-card--text') . '" href="' . $href . '">';
        if ($hero) {
            $hh = htmlspecialchars($hero, ENT_QUOTES);
            $h .= '<div class="aml-hero-media"><img src="' . $hh . '" alt="' . $title . '" loading="eager" onerror="this.parentElement.remove()"></div>';
        }
        $h .= '<div class="aml-hero-body">';
        $h .= '<div class="aml-hero-tag">★ Featured</div>';
        if ($eyebrow) $h .= '<div class="aml-hero-eyebrow">' . $eyebrow . '</div>';
        $h .= '<h2 class="aml-hero-title">' . $title . '</h2>';
        if ($excerpt) $h .= '<p class="aml-hero-excerpt">' . $excerpt . '</p>';
        $h .= '<div class="aml-hero-meta">';
        if ($date) $h .= '<span class="aml-hero-date">' . $date . '</span>';
        $h .= '<span class="aml-hero-cta">Read article →</span>';
        $h .= '</div></div></a>';
        // Scoped styles for the hero card. Emitted once; library-wide styles are separate.
        static $heroStylesEmitted = false;
        if (!$heroStylesEmitted) {
            $heroStylesEmitted = true;
            $h .= '<style>
.aml-hero-card{display:grid;grid-template-columns:1fr;gap:0;background:rgba(255,255,255,.04);border:1px solid rgba(255,208,128,.25);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;margin:1rem 0 2rem;transition:border-color .2s,transform .15s,box-shadow .2s}
.aml-hero-card:hover{border-color:rgba(255,208,128,.55);transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,.28)}
.aml-hero-card--split{grid-template-columns:minmax(320px,1.15fr) 1fr}
.aml-hero-media{background:rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;min-height:260px;max-height:420px;overflow:hidden}
.aml-hero-media img{width:100%;height:100%;max-height:420px;object-fit:cover;display:block}
.aml-hero-body{padding:2rem 2.25rem;display:flex;flex-direction:column;justify-content:center;gap:.7rem}
.aml-hero-tag{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:#ffd080;font-weight:700;align-self:flex-start;padding:.25rem .7rem;border:1px solid rgba(255,208,128,.45);border-radius:999px;background:rgba(255,208,128,.08)}
.aml-hero-eyebrow{font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;color:#ffd080;font-weight:600}
.aml-hero-title{font-size:1.9rem;line-height:1.2;color:#f3f6fc;margin:0;font-weight:700;letter-spacing:-.01em}
.aml-hero-excerpt{font-size:1rem;line-height:1.55;color:#c9d1e0;margin:0;max-width:62ch}
.aml-hero-meta{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:.3rem;font-size:.82rem}
.aml-hero-date{color:#8b94a6;letter-spacing:.04em}
.aml-hero-cta{color:#ffd080;font-weight:600;letter-spacing:.02em}
@media(max-width:820px){.aml-hero-card--split{grid-template-columns:1fr}.aml-hero-title{font-size:1.55rem}.aml-hero-body{padding:1.4rem 1.5rem}.aml-hero-media{min-height:220px;max-height:300px}.aml-hero-media img{max-height:300px}}
</style>';
        }
        return $h;
    }

    function _am_library_toolbar(): string
    {
        return '<div class="aml-toolbar" id="amlToolbar">
  <input type="search" class="aml-search" id="amlSearch" placeholder="Search titles, shows, actors…" autocomplete="off" spellcheck="false">
  <div class="aml-sort-group" role="tablist">
    <button type="button" class="aml-sort aml-sort--active" data-sort="default">Library</button>
    <button type="button" class="aml-sort" data-sort="date">Newest</button>
    <button type="button" class="aml-sort" data-sort="alpha">A–Z</button>
  </div>
  <div class="aml-result-count" id="amlResultCount"></div>
</div>
<script>(function(){
  var t = document.getElementById("amlToolbar"); if (!t) return;
  var search = document.getElementById("amlSearch");
  var countEl = document.getElementById("amlResultCount");
  var buttons = t.querySelectorAll(".aml-sort");
  var library = t.parentElement;
  var sections = Array.from(library.querySelectorAll(".am-library-section"));
  var allCards = Array.from(library.querySelectorAll(".aml-card"));

  // Snapshot the default DOM order per section so we can restore "Library" mode
  var originalOrder = sections.map(function(sec){
    var grid = sec.querySelector(".am-library-grid");
    return {grid: grid, cards: Array.from(grid.children)};
  });

  // Build a flat pool for global sort modes (Newest / A–Z)
  var pool = allCards.map(function(c){
    return {
      el: c,
      title: (c.querySelector(".aml-title") || {}).textContent || "",
      eyebrow: (c.querySelector(".aml-eyebrow") || {}).textContent || "",
      date: (c.querySelector(".aml-date") || {}).textContent || "",
      dateTs: Date.parse((c.querySelector(".aml-date") || {}).textContent || "") || 0,
      text: ((c.textContent || "") + " " + (c.closest(".am-library-section") || {}).querySelector?.(".am-library-title")?.textContent || "").toLowerCase()
    };
  });

  var currentSort = "default";

  function renderDefault() {
    // Put cards back in their original grid, in original order
    originalOrder.forEach(function(o){
      o.cards.forEach(function(c){ o.grid.appendChild(c); });
    });
    sections.forEach(function(s){ s.style.display = ""; });
    applySearch();
    refreshSectionCounts();
  }

  function renderFlat(mode) {
    // Hide the section headings, pile all cards into the first grid
    var firstGrid = originalOrder[0].grid;
    // Clear all grids first (but do not destroy the <section> nodes)
    originalOrder.forEach(function(o){
      while (o.grid.firstChild) o.grid.firstChild.remove();
    });
    // Sort
    var sorted = pool.slice();
    if (mode === "date") {
      sorted.sort(function(a,b){ return (b.dateTs||0) - (a.dateTs||0); });
    } else if (mode === "alpha") {
      sorted.sort(function(a,b){ return a.title.localeCompare(b.title, undefined, {sensitivity:"base"}); });
    }
    sorted.forEach(function(p){ firstGrid.appendChild(p.el); });
    // Hide all sections except the first one
    sections.forEach(function(s,i){ s.style.display = (i === 0) ? "" : "none"; });
    // Rename the first sections heading to match the sort mode
    var firstTitle = sections[0].querySelector(".am-library-title");
    var firstCount = sections[0].querySelector(".am-library-count");
    if (firstTitle) firstTitle.textContent = (mode === "date") ? "Newest First" : "A–Z";
    if (firstCount) firstCount.textContent = sorted.length + " articles";
    applySearch();
  }

  function applySearch() {
    var q = (search.value || "").trim().toLowerCase();
    var visible = 0;
    allCards.forEach(function(c, i) {
      var match = !q || pool[i].text.indexOf(q) >= 0;
      c.style.display = match ? "" : "none";
      if (match) visible++;
    });
    if (q) {
      countEl.textContent = visible + " match" + (visible === 1 ? "" : "es");
    } else {
      countEl.textContent = "";
    }
    if (currentSort === "default") refreshSectionCounts();
  }

  function refreshSectionCounts() {
    sections.forEach(function(sec){
      var cards = Array.from(sec.querySelectorAll(".aml-card"));
      var vis = cards.filter(function(c){ return c.style.display !== "none"; }).length;
      var total = cards.length;
      var countEl = sec.querySelector(".am-library-count");
      if (countEl) {
        countEl.textContent = (vis < total ? vis + "/" + total : total) + (total === 1 ? " article" : " articles");
      }
      sec.style.display = (vis === 0 && (search.value || "").trim() !== "") ? "none" : "";
    });
  }

  buttons.forEach(function(btn) {
    btn.addEventListener("click", function() {
      buttons.forEach(function(b){ b.classList.remove("aml-sort--active"); });
      btn.classList.add("aml-sort--active");
      currentSort = btn.dataset.sort;
      if (currentSort === "default") renderDefault();
      else renderFlat(currentSort);
    });
  });

  var debounceTimer = null;
  search.addEventListener("input", function() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applySearch, 120);
  });
})();</script>';
    }

    function _am_library_card(array $a): string
    {
        $slug    = htmlspecialchars((string)($a['slug'] ?? ''), ENT_QUOTES);
        $title   = htmlspecialchars((string)($a['title'] ?? ''), ENT_QUOTES);
        $eyebrow = htmlspecialchars((string)($a['eyebrow'] ?? ''), ENT_QUOTES);
        $date    = !empty($a['date']) ? date('M j, Y', strtotime((string)$a['date'])) : '';
        $hero    = (string)($a['hero_image'] ?? '');
        $href    = !empty($a['external_url']) ? htmlspecialchars((string)$a['external_url'], ENT_QUOTES) : '/blog/' . $slug;
        $h = '<a class="aml-card" href="' . $href . '">';
        if ($hero) {
            $hh = htmlspecialchars($hero, ENT_QUOTES);
            $h .= '<div class="aml-hero"><img src="' . $hh . '" alt="' . $title . '" loading="lazy" onerror="this.remove()"></div>';
        }
        $h .= '<div class="aml-body">';
        if ($eyebrow) $h .= '<div class="aml-eyebrow">' . $eyebrow . '</div>';
        $h .= '<h3 class="aml-title">' . $title . '</h3>';
        if ($date) $h .= '<div class="aml-date">' . $date . '</div>';
        $h .= '</div></a>';
        return $h;
    }

    function _am_library_styles(): string
    {
        static $emitted = false;
        if ($emitted) return '';
        $emitted = true;
        return '<style>
.aml-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:.85rem;margin:.5rem 0 1.6rem;padding:.8rem 1rem;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px}
.aml-search{flex:1 1 240px;min-width:200px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.12);border-radius:8px;color:#f3f6fc;padding:.55rem .9rem;font-size:.92rem;outline:none;transition:border-color .15s}
.aml-search:focus{border-color:rgba(255,208,128,.55)}
.aml-sort-group{display:flex;gap:.3rem;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:3px}
.aml-sort{background:transparent;border:none;color:#c9d1e0;padding:.4rem .85rem;font-size:.82rem;border-radius:6px;cursor:pointer;font-weight:500;letter-spacing:.02em;transition:background .15s,color .15s}
.aml-sort:hover{background:rgba(255,255,255,.06);color:#f3f6fc}
.aml-sort--active{background:rgba(255,208,128,.18);color:#ffd080}
.aml-result-count{font-size:.75rem;color:#8b94a6;letter-spacing:.05em}
.am-library{display:flex;flex-direction:column;gap:2.4rem;margin:1.5rem 0}
.am-library-head{display:flex;align-items:baseline;gap:.9rem;margin-bottom:.9rem;padding-bottom:.5rem;border-bottom:1px solid rgba(255,208,128,.18)}
.am-library-title{font-size:1.35rem;color:#ffd080;margin:0;font-weight:600}
.am-library-count{font-size:.72rem;color:#8b94a6;letter-spacing:.08em;text-transform:uppercase}
.am-library-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem}
.aml-card{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;overflow:hidden;transition:transform .15s,background .15s,border-color .15s}
.aml-card:hover{background:rgba(255,255,255,.08);border-color:rgba(255,208,128,.35);transform:translateY(-2px)}
.aml-hero{width:100%;max-height:320px;overflow:hidden;display:flex;justify-content:center;align-items:center;background:rgba(0,0,0,.3)}
.aml-hero img{max-width:100%;max-height:320px;width:100%;height:auto;object-fit:contain;display:block}
.aml-body{padding:.85rem 1rem 1rem;display:flex;flex-direction:column;gap:.3rem}
.aml-eyebrow{font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;color:#ffd080;font-weight:600}
.aml-title{font-size:.95rem;color:#f3f6fc;margin:0;line-height:1.3;font-weight:600}
.aml-date{font-size:.7rem;color:#8b94a6;letter-spacing:.04em}
.am-library-section--dispatches .am-library-title{color:#c9d1e0}
.am-library-section--dispatches .am-library-head{border-bottom-color:rgba(255,255,255,.10)}
@media(max-width:600px){.am-library-grid{grid-template-columns:1fr}}
</style>';
    }
}

if (!function_exists('render_article_embed')) {
    function render_article_embed(array $attrs = []): string
    {
        $slug = (string)($attrs['slug'] ?? $attrs['id'] ?? '');
        if ($slug === '') return '<!-- article_embed: slug required -->';
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4);
        $store    = new \ArticlesManager\ArticleStore($siteRoot);
        $a        = $store->get($slug);
        if (!$a || empty($a['published'])) return '<!-- article_embed: not found or unpublished -->';

        $h = '<article class="am-embed">' . ($a['body_html'] ?? '');
        $h .= am_render_share_rack($a);
        $h .= '</article>';
        return $h;
    }
}

/**
 * Social share rack + modal — appended to every article detail view.
 * Colored platform icons, click opens share modal with article preview.
 * Modeled on EventsManagerPro Facebook share modal pattern.
 */
if (!function_exists('am_render_share_rack')) {
    function am_render_share_rack(array $a): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $siteUrl  = $protocol . '://' . $host;
        $slug     = $a['slug'] ?? '';
        $pageUrl  = $siteUrl . '/' . urlencode($slug);
        $pageUrlFallback = $siteUrl . '/page.php?p=' . urlencode($slug);

        $title   = $a['title'] ?? 'Check out this article';
        $excerpt = $a['excerpt'] ?? $title;
        $hero    = $a['hero_image'] ?? '';

        // OG image resolve
        $ogImage = '';
        if ($hero) {
            $ogImage = (str_starts_with($hero, 'http') ? $hero : $siteUrl . '/' . ltrim($hero, '/'));
        } else {
            $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 4);
            $sf = $siteRoot . '/admin/data/site-settings.json';
            if (is_file($sf)) {
                $settings = json_decode(file_get_contents($sf), true) ?: [];
                $logo = $settings['logo_path'] ?? '';
                if ($logo) $ogImage = $siteUrl . '/' . ltrim($logo, '/');
            }
        }
        if ($ogImage && empty($GLOBALS['PAGE_OG_IMAGE'])) {
            $GLOBALS['PAGE_OG_IMAGE'] = $ogImage;
        }

        $eTitle   = htmlspecialchars($title, ENT_QUOTES);
        $eExcerpt = htmlspecialchars(substr($excerpt, 0, 160), ENT_QUOTES);
        $eHero    = htmlspecialchars($ogImage, ENT_QUOTES);
        $eUrl     = htmlspecialchars($pageUrl, ENT_QUOTES);
        $pixelSlug = htmlspecialchars(urlencode($slug), ENT_QUOTES);
        $uid = substr(md5($slug . microtime()), 0, 8);

        // Tracking pixel
        $h = '<img src="/panels/stat-pixel.php?t=article&amp;s=' . $pixelSlug . '" width="1" height="1" alt="" style="position:absolute;opacity:0" loading="eager">';

        // Share button bar — colored icons, inside the article
        $h .= '<div class="ams-bar">';
        $h .= '<span class="ams-label">Share this article</span>';
        $h .= '<div class="ams-icons">';
        $h .= '<button class="ams-icon ams-fb" title="Facebook" onclick="amsOpen(\'' . $uid . '\',\'facebook\')"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg></button>';
        $h .= '<button class="ams-icon ams-x" title="X / Twitter" onclick="amsOpen(\'' . $uid . '\',\'x\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></button>';
        $h .= '<button class="ams-icon ams-ss" title="Substack" onclick="amsOpen(\'' . $uid . '\',\'substack\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22.539 8.242H1.46V5.406h21.08v2.836zM1.46 10.812V24L12 18.11 22.54 24V10.812H1.46zM22.54 0H1.46v2.836h21.08V0z"/></svg></button>';
        $h .= '<button class="ams-icon ams-li" title="LinkedIn" onclick="amsOpen(\'' . $uid . '\',\'linkedin\')"><svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg></button>';
        $h .= '<button class="ams-icon ams-em" title="Email" onclick="amsOpen(\'' . $uid . '\',\'email\')"><svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor"><path d="M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0L1.5 8.67z"/><path d="M22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0L22.5 6.908z"/></svg></button>';
        $h .= '<button class="ams-icon ams-cp" title="Copy Link" onclick="amsCopy(\'' . $uid . '\')"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg></button>';
        $h .= '</div></div>';

        // Share modal (hidden, shown on icon click)
        $h .= '<div class="ams-overlay" id="amsOverlay' . $uid . '" style="display:none" onclick="if(event.target===this)amsClose(\'' . $uid . '\')">';
        $h .= '<div class="ams-modal">';
        // Header
        $h .= '<div class="ams-modal-head"><span class="ams-modal-title">Share Article</span><button class="ams-modal-close" onclick="amsClose(\'' . $uid . '\')">&times;</button></div>';
        // Preview card
        $h .= '<div class="ams-preview">';
        if ($eHero) $h .= '<img src="' . $eHero . '" class="ams-preview-img" onerror="this.remove()">';
        $h .= '<div class="ams-preview-info"><div class="ams-preview-title">' . $eTitle . '</div>';
        if ($eExcerpt) $h .= '<div class="ams-preview-excerpt">' . $eExcerpt . '</div>';
        $h .= '<div class="ams-preview-url">' . $eUrl . '</div></div></div>';
        // Share options (populated by JS based on which icon was clicked)
        $h .= '<div class="ams-options" id="amsOpts' . $uid . '"></div>';
        $h .= '</div></div>';

        // Data for JS
        $h .= '<script>window._amsData=window._amsData||{};window._amsData["' . $uid . '"]='
             . json_encode(['url' => $pageUrl, 'urlFb' => $pageUrlFallback, 'title' => $title, 'excerpt' => $excerpt, 'image' => $ogImage, 'slug' => $slug, 'pixel' => '/panels/stat-pixel.php?t=share_click&s=' . urlencode($slug)], JSON_UNESCAPED_SLASHES)
             . ';</script>';

        // CSS + JS (only emitted once via marker)
        if (empty($GLOBALS['_ams_share_assets'])) {
            $GLOBALS['_ams_share_assets'] = true;
            $h .= '<style>
/* ── Article Share Bar ─────────────────────────────── */
.ams-bar{display:flex;align-items:center;gap:14px;margin:1.5rem 0 .5rem;padding:1rem 0;border-top:1px solid rgba(255,255,255,.08);}
.ams-label{font:600 .8rem system-ui;color:#888;letter-spacing:.03em;white-space:nowrap;}
.ams-icons{display:flex;gap:8px;flex-wrap:wrap;}
.ams-icon{appearance:none;border:none;cursor:pointer;width:44px;height:44px;min-width:44px;min-height:44px;flex-shrink:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;transition:transform .12s,box-shadow .12s;-webkit-tap-highlight-color:transparent;}
.ams-icon svg{width:20px;height:20px;min-width:20px;min-height:20px;flex-shrink:0;}
.ams-icon:hover{transform:translateY(-3px);box-shadow:0 4px 12px rgba(0,0,0,.4);}
.ams-fb{background:#1877F2;color:#fff;}
.ams-x{background:#000;color:#fff;border:1px solid rgba(255,255,255,.15);}
.ams-ss{background:#FF6600;color:#fff;}
.ams-li{background:#0A66C2;color:#fff;}
.ams-em{background:#EA580C;color:#fff;}
.ams-cp{background:rgba(255,255,255,.1);color:#ccc;border:1px solid rgba(255,255,255,.12);}
.ams-cp:hover{background:rgba(255,255,255,.2);}
/* ── Share Modal ───────────────────────────────────── */
.ams-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;}
.ams-modal{background:#111318;border:1px solid rgba(255,255,255,.1);border-radius:14px;width:min(480px,94vw);max-height:88vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.6);}
.ams-modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.06);}
.ams-modal-title{font:700 1rem system-ui;color:#e8e8e8;letter-spacing:.02em;}
.ams-modal-close{appearance:none;background:none;border:none;color:#888;font-size:22px;cursor:pointer;padding:0 4px;line-height:1;}
.ams-modal-close:hover{color:#fff;}
.ams-preview{display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.05);align-items:center;}
.ams-preview-img{width:72px;height:72px;border-radius:8px;object-fit:cover;flex-shrink:0;background:rgba(255,255,255,.05);}
.ams-preview-info{flex:1;min-width:0;}
.ams-preview-title{font:600 .9rem system-ui;color:#e8e8e8;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ams-preview-excerpt{font:.78rem system-ui;color:#888;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.ams-preview-url{font:.7rem monospace;color:#555;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ams-options{padding:10px 14px 16px;}
.ams-opt{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:8px;cursor:pointer;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02);margin-bottom:8px;transition:background .12s,border-color .12s;text-decoration:none;color:inherit;}
.ams-opt:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);}
.ams-opt-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px;}
.ams-opt-text{flex:1;min-width:0;}
.ams-opt-text strong{display:block;font:600 .85rem system-ui;color:#e8e8e8;}
.ams-opt-text span{font:.75rem system-ui;color:#777;line-height:1.3;}
.ams-opt-arrow{color:#555;font-size:14px;flex-shrink:0;}
.ams-copied{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#22c55e;color:#fff;padding:8px 20px;border-radius:8px;font:600 .85rem system-ui;z-index:10000;animation:amsFadeOut 2s ease 1s forwards;}
@keyframes amsFadeOut{to{opacity:0;}}
@media(max-width:520px){.ams-bar{flex-direction:column;align-items:flex-start;gap:8px;}.ams-modal{border-radius:10px;}}
</style>
<script>
(function(){
  function track(d,p){new Image().src=d.pixel+"&c="+p;}
  function popup(url,w,h){var l=(screen.width-w)/2,t=(screen.height-h)/2;window.open(url,"share","width="+w+",height="+h+",left="+l+",top="+t+",toolbar=no,menubar=no");}

  window.amsOpen=function(uid,platform){
    var d=window._amsData[uid]; if(!d)return;
    var opts=document.getElementById("amsOpts"+uid);
    var u=encodeURIComponent(d.url), t=encodeURIComponent(d.title), ex=encodeURIComponent(d.excerpt);
    var html="";
    if(platform==="facebook"){
      html+=opt("#1877F2","fb","Share to Feed","Opens Facebook share dialog with article preview",
        function(){track(d,"facebook");popup("https://www.facebook.com/sharer/sharer.php?u="+u,600,400);});
      html+=opt("#1877F2","fb","Copy for Facebook","Copy formatted text to paste in Facebook",
        function(){track(d,"facebook_copy");copyText(d.title+"\\n\\n"+d.excerpt+"\\n\\n"+d.url);});
    } else if(platform==="x"){
      var tt=d.title.length>240?d.title.substring(0,237)+"...":d.title;
      html+=opt("#000","x","Post to X","Compose a post with article link",
        function(){track(d,"x");popup("https://twitter.com/intent/tweet?url="+u+"&text="+encodeURIComponent(tt),600,400);});
      html+=opt("#000","x","Copy for X","Copy title + link to clipboard",
        function(){track(d,"x_copy");copyText(tt+"\\n"+d.url);});
    } else if(platform==="substack"){
      html+=opt("#FF6600","ss","Copy Article for Substack","Copies rich HTML — paste directly into Substack editor with full formatting",
        function(){track(d,"substack_html");copyArticleHtml(d);});
      html+=opt("#FF6600","ss","Share on Substack Notes","Opens Substack Notes with article link",
        function(){track(d,"substack_note");popup("https://substack.com/note?url="+u+"&title="+t+"&body="+ex,600,500);});
    } else if(platform==="linkedin"){
      html+=opt("#0A66C2","li","Share on LinkedIn","Opens LinkedIn share dialog",
        function(){track(d,"linkedin");popup("https://www.linkedin.com/sharing/share-offsite/?url="+u,600,500);});
    } else if(platform==="email"){
      html+=opt("#EA580C","em","Send via Email","Opens your email client with article details",
        function(){track(d,"email");window.location="mailto:?subject="+encodeURIComponent(d.title)+"&body="+encodeURIComponent(d.title+"\\n\\n"+d.excerpt+"\\n\\n"+d.url);});
    }
    // Always add copy link
    html+=opt("rgba(255,255,255,.1)","cp","Copy Article Link","Copy the URL to your clipboard",
      function(){track(d,"copy_link");copyText(d.url);});

    opts.innerHTML="";
    var container=document.createElement("div");
    container.innerHTML=html;
    // Re-attach onclick handlers (innerHTML kills them)
    opts.appendChild(container);
    document.getElementById("amsOverlay"+uid).style.display="flex";
    document.addEventListener("keydown",escHandler);
  };

  function opt(color,cls,title,desc,fn){
    var id="amsOpt_"+Math.random().toString(36).substr(2,6);
    setTimeout(function(){var el=document.getElementById(id);if(el)el.onclick=fn;},0);
    return "<div class=\\"ams-opt\\" id=\\""+id+"\\">"
      +"<div class=\\"ams-opt-icon\\" style=\\"background:"+color+";color:#fff;\\"><span class=\\"ams-icon-sm ams-"+cls+"\\"></span></div>"
      +"<div class=\\"ams-opt-text\\"><strong>"+title+"</strong><span>"+desc+"</span></div>"
      +"<span class=\\"ams-opt-arrow\\">&#8250;</span></div>";
  }

  function copyArticleHtml(d){
    // Find the article content on the page — try multiple container patterns
    var el=document.querySelector(".miami-article-glass,.cinema-article-glass,.cd-glass,.am-embed,.miami-article,.cinema-article,.cd-article");
    if(!el){showToast("Could not find article content on page","#dc2626");return;}
    // Clone and clean for Substack
    var clone=el.cloneNode(true);
    // Remove share rack, tracking pixels, scripts, styles, nav elements
    clone.querySelectorAll(".ams-bar,.ams-overlay,script,style,nav,.am-share-rack,img[width=\\"1\\"]").forEach(function(n){n.remove();});
    // Strip all class and style attributes — Substack wants clean semantic HTML
    clone.querySelectorAll("*").forEach(function(n){
      n.removeAttribute("class");n.removeAttribute("style");n.removeAttribute("data-col");
      n.removeAttribute("onclick");n.removeAttribute("onerror");n.removeAttribute("loading");
    });
    // Build the final HTML with title header
    var html="<h1>"+d.title+"</h1>"+clone.innerHTML;
    // Append source link
    html+="<p><em>Originally published at <a href=\\""+d.url+"\\">"+d.url+"</a></em></p>";
    // Copy as rich text (text/html MIME) — Substack editor preserves formatting on paste
    var blob=new Blob([html],{type:"text/html"});
    var item=new ClipboardItem({"text/html":blob,"text/plain":new Blob([d.title+"\\n\\n"+el.innerText+"\\n\\nRead more: "+d.url],{type:"text/plain"})});
    navigator.clipboard.write([item]).then(function(){
      showToast("Article copied as rich HTML — paste into Substack!","#FF6600");
    }).catch(function(e){
      // Fallback: copy plain text
      navigator.clipboard.writeText(d.title+"\\n\\n"+el.innerText+"\\n\\n"+d.url);
      showToast("Copied as plain text (browser blocked rich copy)","#fbbf24");
    });
  }

  function showToast(msg,color){
    var toast=document.createElement("div");toast.className="ams-copied";
    if(color)toast.style.background=color;
    toast.textContent=msg;
    document.body.appendChild(toast);setTimeout(function(){toast.remove();},3500);
  }

  function copyText(text){
    navigator.clipboard.writeText(text).then(function(){
      showToast("Copied to clipboard!","#22c55e");
    });
  }

  window.amsClose=function(uid){
    document.getElementById("amsOverlay"+uid).style.display="none";
    document.removeEventListener("keydown",escHandler);
  };

  window.amsCopy=function(uid){
    var d=window._amsData[uid];if(!d)return;
    new Image().src=d.pixel+"&c=copy_link";
    copyText(d.url);
  };

  function escHandler(e){if(e.key==="Escape"){document.querySelectorAll(".ams-overlay").forEach(function(o){o.style.display="none";});document.removeEventListener("keydown",escHandler);}}
})();
</script>';
        }

        return $h;
    }
}
