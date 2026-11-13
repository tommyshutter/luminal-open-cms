<?php
/**
 * Renderer: Article Archive
 * Shortcode: [[article-archive]]
 * Params: limit="10", type="spotlight", style="cards|list"
 *
 * Scans admin/data/article-archive/*.json, sorts newest-first,
 * renders as expandable cards or list items.
 */
declare(strict_types=1);

if (!function_exists('render_article_archive')) {
    function render_article_archive(array $attrs = []): string
    {
        $limit = max(1, (int)($attrs['limit'] ?? 10));
        $typeFilter = $attrs['type'] ?? '';
        $style = ($attrs['style'] ?? 'cards') === 'list' ? 'list' : 'cards';

        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : (realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
        $archiveDir = $siteRoot . '/admin/data/article-archive';

        if (!is_dir($archiveDir)) {
            return '<!-- article-archive: no archive directory -->';
        }

        $files = glob($archiveDir . '/*.json') ?: [];
        if (empty($files)) {
            return '<!-- article-archive: no articles found -->';
        }

        // Load and parse all articles
        $articles = [];
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data) || empty($data['title'])) continue;

            // Type filter
            if ($typeFilter !== '' && ($data['article_type'] ?? '') !== $typeFilter) continue;

            $articles[] = $data;
        }

        if (empty($articles)) {
            return '<!-- article-archive: no matching articles -->';
        }

        // Sort by generated_at descending (newest first)
        usort($articles, function ($a, $b) {
            return strcmp($b['generated_at'] ?? '', $a['generated_at'] ?? '');
        });

        // Apply limit
        $articles = array_slice($articles, 0, $limit);

        // Generate unique ID for this instance
        $uid = 'aa-' . substr(md5(uniqid('', true)), 0, 8);

        // Track newest article timestamp for polling baseline
        $newestTs = $articles[0]['generated_at'] ?? date('c');

        // Build feed endpoint URL params
        $feedParams = '?limit=' . $limit;
        if ($typeFilter !== '') $feedParams .= '&type=' . urlencode($typeFilter);

        // Auto-refresh interval (seconds) — 0 or "off" disables
        $refreshInterval = (int)($attrs['refresh'] ?? 120);

        // Render
        $out = '<div class="article-archive article-archive--' . htmlspecialchars($style) . '" id="' . $uid . '"'
             . ' data-feed-endpoint="/admin/api/feed.json.php' . htmlspecialchars($feedParams) . '"'
             . ' data-feed-since="' . htmlspecialchars($newestTs) . '"'
             . ' data-feed-style="' . htmlspecialchars($style) . '"'
             . ' data-feed-refresh="' . $refreshInterval . '"'
             . '>';
        $out .= _ra_renderStyles($uid, $style);

        foreach ($articles as $i => $article) {
            $title = htmlspecialchars($article['title'] ?? 'Untitled');
            $dateRaw = $article['generated_at'] ?? '';
            $date = $dateRaw !== '' ? date('M j, Y', strtotime($dateRaw)) : '';
            $type = htmlspecialchars($article['article_type'] ?? '');
            $htmlContent = $article['html'] ?? '';

            // If content looks like markdown (starts with # or has no HTML tags), convert
            if ($htmlContent !== '' && !preg_match('/<[a-z][\s>]/i', $htmlContent)) {
                require_once SITE_ROOT . '/includes/Parsedown.php';
                $htmlContent = (new \Parsedown())->text($htmlContent);
            }

            // Build preview snippet (plain text, first 150 chars)
            $snippet = trim(strip_tags($htmlContent));
            if (mb_strlen($snippet) > 150) {
                $snippet = mb_substr($snippet, 0, 150) . '…';
            }
            $snippet = htmlspecialchars($snippet);

            $cardId = $uid . '-item-' . $i;

            if ($style === 'cards') {
                $out .= '<div class="aa-card" id="' . $cardId . '">';
                $out .= '<div class="aa-card__header" onclick="this.parentElement.classList.toggle(\'aa-card--open\')">';
                $out .= '<div class="aa-card__meta">';
                if ($date !== '') $out .= '<span class="aa-card__date">' . $date . '</span>';
                if ($type !== '') $out .= '<span class="aa-card__type">' . $type . '</span>';
                $out .= '</div>';
                $out .= '<h3 class="aa-card__title">' . $title . '</h3>';
                $out .= '<p class="aa-card__snippet">' . $snippet . '</p>';
                $out .= '<span class="aa-card__toggle">Read more</span>';
                $out .= '</div>';
                $out .= '<div class="aa-card__body">' . $htmlContent . '</div>';
                $out .= '</div>';
            } else {
                // List style
                $out .= '<div class="aa-list-item" id="' . $cardId . '">';
                $out .= '<div class="aa-list-item__header" onclick="this.parentElement.classList.toggle(\'aa-list-item--open\')">';
                $out .= '<h3 class="aa-list-item__title">' . $title . '</h3>';
                $out .= '<div class="aa-list-item__meta">';
                if ($date !== '') $out .= '<span>' . $date . '</span>';
                if ($type !== '') $out .= '<span class="aa-list-item__type">' . $type . '</span>';
                $out .= '</div>';
                $out .= '</div>';
                $out .= '<div class="aa-list-item__body">' . $htmlContent . '</div>';
                $out .= '</div>';
            }
        }

        $out .= _ra_renderPollScript($uid);
        $out .= '</div>';
        return $out;
    }

    /**
     * JS polling script — checks feed.json for new articles and prepends them.
     */
    function _ra_renderPollScript(string $uid): string
    {
        return '<script>
(function(){
  var el = document.getElementById("' . $uid . '");
  if (!el) return;
  var endpoint = el.dataset.feedEndpoint;
  var interval = parseInt(el.dataset.feedRefresh, 10) || 0;
  var style = el.dataset.feedStyle || "cards";
  if (!endpoint || interval <= 0) return;

  function esc(s) {
    var d = document.createElement("div"); d.textContent = s; return d.innerHTML;
  }
  function snippet(html) {
    var d = document.createElement("div"); d.innerHTML = html;
    var t = (d.textContent || "").trim();
    return t.length > 150 ? t.substring(0, 150) + "\u2026" : t;
  }
  function fmtDate(iso) {
    if (!iso) return "";
    var d = new Date(iso);
    var m = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    return m[d.getMonth()] + " " + d.getDate() + ", " + d.getFullYear();
  }

  function buildCard(a) {
    var meta = "";
    if (a.generated_at) meta += \'<span class="aa-card__date">\' + esc(fmtDate(a.generated_at)) + "</span>";
    if (a.article_type) meta += \'<span class="aa-card__type">\' + esc(a.article_type) + "</span>";
    if (style === "cards") {
      return \'<div class="aa-card aa-card--new">\' +
        \'<div class="aa-card__header" onclick="this.parentElement.classList.toggle(\\\'aa-card--open\\\')">\' +
        \'<div class="aa-card__meta">\' + meta + "</div>" +
        \'<h3 class="aa-card__title">\' + esc(a.title) + "</h3>" +
        \'<p class="aa-card__snippet">\' + esc(snippet(a.html || "")) + "</p>" +
        \'<span class="aa-card__toggle">Read more</span></div>\' +
        \'<div class="aa-card__body">\' + (a.html || "") + "</div></div>";
    }
    return \'<div class="aa-list-item aa-list-item--new">\' +
      \'<div class="aa-list-item__header" onclick="this.parentElement.classList.toggle(\\\'aa-list-item--open\\\')">\' +
      \'<h3 class="aa-list-item__title">\' + esc(a.title) + "</h3>" +
      \'<div class="aa-list-item__meta">\' + meta + "</div></div>" +
      \'<div class="aa-list-item__body">\' + (a.html || "") + "</div></div>";
  }

  function poll() {
    var since = el.dataset.feedSince || "";
    var url = endpoint + (endpoint.indexOf("?") > -1 ? "&" : "?") + "since=" + encodeURIComponent(since);
    fetch(url).then(function(r){ return r.json(); }).then(function(data){
      if (!data.ok || !data.articles || !data.articles.length) return;
      // Prepend newest first (they come newest-first from API)
      for (var i = data.articles.length - 1; i >= 0; i--) {
        var a = data.articles[i];
        var tmp = document.createElement("div");
        tmp.innerHTML = buildCard(a);
        var card = tmp.firstElementChild;
        // Insert after <style> tag (first child), before first article card
        var firstCard = el.querySelector(".aa-card, .aa-list-item");
        if (firstCard) { el.insertBefore(card, firstCard); }
        else { el.appendChild(card); }
      }
      // Update since marker to newest
      el.dataset.feedSince = data.as_of || data.articles[0].generated_at || since;
    }).catch(function(){});
  }

  setInterval(poll, interval * 1000);
})();
</script>';
    }

    /**
     * Scoped inline styles for the archive renderer.
     */
    function _ra_renderStyles(string $uid, string $style): string
    {
        return '<style>
#' . $uid . ' { max-width: 900px; margin: 0 auto; }
/* Cards layout */
.article-archive--cards { display: grid; gap: 16px; }
.aa-card { background: var(--card-bg, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; overflow: hidden; transition: box-shadow 0.2s; }
.aa-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
.aa-card__header { padding: 16px 20px; cursor: pointer; }
.aa-card__meta { display: flex; gap: 10px; font-size: 0.8em; color: var(--text-muted, #888); margin-bottom: 6px; }
.aa-card__type { background: var(--accent-bg, #f0f0f0); padding: 1px 8px; border-radius: 10px; font-size: 0.9em; }
.aa-card__title { margin: 0 0 6px; font-size: 1.15em; }
.aa-card__snippet { margin: 0; color: var(--text-muted, #666); font-size: 0.9em; line-height: 1.4; }
.aa-card__toggle { display: inline-block; margin-top: 8px; font-size: 0.85em; color: var(--accent, #3b82f6); cursor: pointer; }
.aa-card__body { display: none; padding: 0 20px 20px; border-top: 1px solid var(--border, #e8e8e8); }
.aa-card--open .aa-card__body { display: block; }
.aa-card--open .aa-card__snippet { display: none; }
.aa-card--open .aa-card__toggle::after { content: none; }
.aa-card--open .aa-card__toggle { display: none; }
/* List layout */
.aa-list-item { border-bottom: 1px solid var(--border, #e0e0e0); }
.aa-list-item__header { padding: 12px 0; cursor: pointer; display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 6px; }
.aa-list-item__title { margin: 0; font-size: 1.05em; }
.aa-list-item__meta { display: flex; gap: 10px; font-size: 0.8em; color: var(--text-muted, #888); }
.aa-list-item__type { background: var(--accent-bg, #f0f0f0); padding: 1px 8px; border-radius: 10px; }
.aa-list-item__body { display: none; padding: 0 0 16px; }
.aa-list-item--open .aa-list-item__body { display: block; }
/* New item animation */
@keyframes aa-slide-in { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
.aa-card--new, .aa-list-item--new { animation: aa-slide-in 0.4s ease-out; }
</style>';
    }
}
