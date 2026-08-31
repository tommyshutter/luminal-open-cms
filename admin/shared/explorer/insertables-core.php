<?php
/**
 * Luminal CMS — Explorer insertables catalog (shared library)
 * @file /admin/shared/explorer/insertables-core.php
 *
 * Harvests every insertable shortcode on the site into one grouped catalog
 * for the Explorer's "Inserts" tab. Ported from PageManager's legacy
 * ri_render_modal_pills() pill sidebar (PageManager.php) — same data
 * sources, but returns structured data instead of HTML so any host can
 * render it (cards, search, drag, lightbox preview).
 *
 * Pure functions, no output, no auth — callers gate (see insertables.php).
 *
 * Catalog shape:
 *   [ ['key'=>'stacks','label'=>'Content Stacks','icon'=>'⿻','accent'=>'#2ec4b6',
 *      'items'=>[ ['id'=>'stack:foo','label'=>'Foo','code'=>'[[stack:foo]]',
 *                  'badge'=>'foo','color'=>'#2ec4b6','desc'=>'…','preview'=>true], … ]], … ]
 *
 * 'preview' => true marks items the shortcode-preview.php endpoint can
 * live-render in the lightbox (renderer-backed shortcodes). Items without
 * it (e.g. raw panels) show an info card instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/explorer-core.php';   // ex_site_root()

if (!function_exists('ins_read_json')) {
/** Decode a JSON file to array ([] on any failure). */
function ins_read_json(string $file): array {
    if (!is_file($file)) return [];
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d) ? $d : [];
}
}

if (!function_exists('ins_gallery_slugs')) {
/** Recursive slug scan of a galleries data dir (index.json excluded). */
function ins_gallery_slugs(string $root): array {
    if (!is_dir($root)) return [];
    $set = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            if (!$fi->isFile() || strtolower($fi->getExtension()) !== 'json') continue;
            $base = basename($fi->getFilename(), '.json');
            if ($base === 'index') continue;
            $set[$base] = true;
        }
    } catch (Exception $e) { /* unreadable dir — skip */ }
    $out = array_keys($set);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
}

if (!function_exists('ins_catalog')) {
/** Build the full grouped insertables catalog for this site. */
function ins_catalog(): array {
    $root   = ex_site_root();
    $groups = [];

    // ── Sections (Luminal content-section containers) ───────────────
    // Drop-in [[section]] wrappers with a preset surface (opacity/radius/shadow). The comment
    // marks where to place HTML/shortcodes between the tags.
    $secDesc = 'A styled Luminal content section — put your HTML/shortcodes between the tags.';
    $groups[] = ['key' => 'sections', 'label' => 'Sections', 'accent' => '#f59e0b', 'items' => [
        ['id' => 'section-base',  'label' => 'Section · Base',  'code' => "[[section preset=\"base\"]]\n<!-- content between these tags is a Section -->\n\n[[/section]]",  'badge' => 'section', 'color' => '#f59e0b', 'desc' => $secDesc . ' Subtle surface.',   'preview' => false],
        ['id' => 'section-apple', 'label' => 'Section · Apple', 'code' => "[[section preset=\"apple\"]]\n<!-- content between these tags is a Section -->\n\n[[/section]]", 'badge' => 'section', 'color' => '#38bdf8', 'desc' => $secDesc . ' Frosted glass.',    'preview' => false],
        ['id' => 'section-auria', 'label' => 'Section · Auria', 'code' => "[[section preset=\"auria\"]]\n<!-- content between these tags is a Section -->\n\n[[/section]]", 'badge' => 'section', 'color' => '#a78bfa', 'desc' => $secDesc . ' Frosted + glow.',   'preview' => false],
    ]];

    // ── Content Stacks ──────────────────────────────────────────────
    $csReg    = ins_read_json($root . '/admin/data/content-stacks-registry.json');
    $csColors = ['#2ec4b6', '#f0a500', '#6580eb', '#e05252', '#1bb458', '#d94fd6', '#ff6b35', '#00d2ff'];
    $items = [];
    foreach (array_values($csReg) as $i => $cs) {
        $num   = preg_replace('/[^0-9]/', '', (string)($cs['id'] ?? ''));
        $label = (string)($cs['label'] ?? ('Stack ' . $num));
        $slug  = (string)($cs['slug'] ?? trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-'));
        // Prefer slug-based shortcode; legacy panel form if slug missing
        $code  = $slug !== '' ? '[[stack:' . $slug . ']]' : '[[panel:content-stack-' . $num . '.php]]';
        $items[] = [
            'id' => 'stack:' . ($slug !== '' ? $slug : $num), 'label' => $label, 'code' => $code,
            'badge' => $slug !== '' ? $slug : ('CS-' . $num),
            'color' => $csColors[$i % count($csColors)], 'desc' => 'Content stack', 'preview' => true,
        ];
    }
    $groups[] = ['key' => 'stacks', 'label' => 'Content Stacks', 'accent' => '#2ec4b6', 'items' => $items];

    // ── Galleries (image / video / pdf / combined) ──────────────────
    $items = [];
    foreach (ins_gallery_slugs($root . '/admin/data/galleries/images') as $s)
        $items[] = ['id' => "image-gallery:$s", 'label' => $s, 'code' => "[[image-gallery:$s]]", 'badge' => 'images', 'color' => '#38bdf8', 'desc' => 'Image gallery', 'preview' => true];
    foreach (ins_gallery_slugs($root . '/admin/data/galleries/videos') as $s)
        $items[] = ['id' => "video-gallery:$s", 'label' => $s, 'code' => "[[video-gallery:$s]]", 'badge' => 'videos', 'color' => '#a78bfa', 'desc' => 'Video gallery', 'preview' => true];
    foreach (ins_gallery_slugs($root . '/admin/data/galleries/pdfs') as $s)
        $items[] = ['id' => "pdf-gallery:$s", 'label' => $s, 'code' => "[[pdf-gallery:$s]]", 'badge' => 'pdfs', 'color' => '#f43f5e', 'desc' => 'PDF gallery', 'preview' => true];
    $cmbDir = $root . '/admin/data/galleries/combined';
    if (is_dir($cmbDir)) {
        $cmbs = [];
        foreach (new DirectoryIterator($cmbDir) as $fi) {
            if ($fi->isFile() && $fi->getExtension() === 'json' && $fi->getBasename('.json') !== 'index') $cmbs[] = $fi->getBasename('.json');
        }
        sort($cmbs);
        foreach ($cmbs as $s)
            $items[] = ['id' => "gallery:$s", 'label' => $s, 'code' => "[[gallery:$s]]", 'badge' => 'combined', 'color' => '#fbbf24', 'desc' => 'Combined gallery', 'preview' => true];
    }
    $groups[] = ['key' => 'galleries', 'label' => 'Galleries', 'accent' => '#38bdf8', 'items' => $items];

    // ── Articles (ArticlesManager grids) ────────────────────────────
    if (is_dir($root . '/admin/modules/ArticlesManager')) {
        $groups[] = ['key' => 'articles', 'label' => 'Articles', 'accent' => '#22c55e', 'items' => [
            ['id' => 'articles-grid',    'label' => '📰 Articles Grid',    'code' => '[[articles-grid]]',    'badge' => 'grid',    'color' => '#22c55e', 'desc' => 'Card grid of published articles', 'preview' => true],
            ['id' => 'articles-latest',  'label' => '📰 Latest Articles',  'code' => '[[articles-latest]]',  'badge' => 'latest',  'color' => '#22c55e', 'desc' => 'Most recent articles strip',     'preview' => true],
            ['id' => 'articles-library', 'label' => '📰 Articles Library', 'code' => '[[articles-library]]', 'badge' => 'library', 'color' => '#22c55e', 'desc' => 'Full library w/ featured hero',   'preview' => true],
        ]];
    }

    // ── Podcast (PodcastManager feed shortcode) ─────────────────────
    if (is_file($root . '/admin/modules/PodcastManager/includes/podcast_feed.renderer.php')) {
        $groups[] = ['key' => 'podcast', 'label' => 'Podcast', 'accent' => '#c084fc', 'items' => [
            ['id' => 'podcast-feed',      'label' => '🎙️ Episodes · List',     'code' => '[[podcast-feed]]',                        'badge' => 'list',     'color' => '#c084fc', 'desc' => 'Cover-left episode rows (default)',   'preview' => true],
            ['id' => 'podcast-grid',      'label' => '🎙️ Episodes · Grid',     'code' => '[[podcast-feed layout="grid"]]',          'badge' => 'grid',     'color' => '#c084fc', 'desc' => 'Cover-art card grid',                'preview' => true],
            ['id' => 'podcast-featured',  'label' => '🎙️ Episodes · Featured', 'code' => '[[podcast-feed layout="featured-grid"]]', 'badge' => 'featured', 'color' => '#c084fc', 'desc' => 'Newest spotlighted + grid below',    'preview' => true],
            ['id' => 'podcast-magazine',  'label' => '🎙️ Episodes · Magazine', 'code' => '[[podcast-feed layout="magazine"]]',      'badge' => 'magazine', 'color' => '#c084fc', 'desc' => 'Editorial hero + rows',              'preview' => true],
            ['id' => 'podcast-compact',   'label' => '🎙️ Episodes · Compact',  'code' => '[[podcast-feed layout="compact" limit="5"]]', 'badge' => 'compact', 'color' => '#c084fc', 'desc' => 'Dense list, no players (latest 5)',  'preview' => true],
        ]];
    }

    // ── HTML Blocks (the modern reusable-snippet primitive) ─────────
    $hbDir = $root . '/admin/data/html-blocks';
    if (is_dir($hbDir)) {
        $items = [];
        foreach (glob("$hbDir/*.json") ?: [] as $hbf) {
            $slug = basename($hbf, '.json');
            if ($slug === 'index') continue;
            $hbd = ins_read_json($hbf);
            $items[] = ['id' => "html-block:$slug", 'label' => (string)($hbd['title'] ?? $hbd['label'] ?? ucwords(str_replace('-', ' ', $slug))),
                        'code' => "[[html-block:$slug]]", 'badge' => $slug, 'color' => '#d94fd6', 'desc' => 'HTML block', 'preview' => true];
        }
        if ($items) $groups[] = ['key' => 'htmlblocks', 'label' => 'HTML Blocks', 'accent' => '#d94fd6', 'items' => $items];
    }

    // ── Site Text (push-delivered text slots, e.g. this-week) ───────
    $stDir = $root . '/admin/data/site-text';
    if (is_dir($stDir)) {
        $items = [];
        foreach (glob("$stDir/*.json") ?: [] as $stf) {
            $slug = basename($stf, '.json');
            if ($slug === 'index') continue;
            $items[] = ['id' => "site-text:$slug", 'label' => ucwords(str_replace('-', ' ', $slug)),
                        'code' => "[[site-text:$slug]]", 'badge' => $slug, 'color' => '#fbbf24', 'desc' => 'Site text slot', 'preview' => true];
        }
        if ($items) $groups[] = ['key' => 'sitetext', 'label' => 'Site Text', 'accent' => '#fbbf24', 'items' => $items];
    }

    // ── Store & Events (static widgets) ─────────────────────────────
    $groups[] = ['key' => 'store', 'label' => 'Store & Events', 'accent' => '#1bb458', 'items' => [
        ['id' => 'mystore',         'label' => '🛒 My Store',          'code' => '[[mystore]]',         'badge' => 'store',  'color' => '#1bb458', 'desc' => 'Full storefront',      'preview' => true],
        ['id' => 'facebook-events', 'label' => '📅 Facebook Events',   'code' => '[[facebook-events]]', 'badge' => 'events', 'color' => '#1bb458', 'desc' => 'Live from FB',         'preview' => true],
        ['id' => 'emp-events',      'label' => '🎪 All Events (Cards)','code' => '[[emp-events]]',      'badge' => 'events', 'color' => '#1bb458', 'desc' => 'EventsMgr card grid',  'preview' => true],
        ['id' => 'upcoming-shows',  'label' => '🎶 Upcoming Shows',    'code' => '[[upcoming-shows]]',  'badge' => 'events', 'color' => '#1bb458', 'desc' => 'Events table',         'preview' => true],
        ['id' => 'this-week',       'label' => '📄 This Week',         'code' => '[[this-week]]',       'badge' => 'events', 'color' => '#1bb458', 'desc' => 'Weekly summary',       'preview' => true],
    ]];

    // ── Printful ────────────────────────────────────────────────────
    $pf = ins_read_json($root . '/admin/data/printful/products.json');
    $items = [['id' => 'printful-store', 'label' => '👕 Full Catalog', 'code' => '[[printful-store]]', 'badge' => 'printful', 'color' => '#e05252', 'desc' => 'All products', 'preview' => true]];
    foreach ($pf as $p) {
        if (!is_array($p)) continue;
        $items[] = ['id' => 'printful:' . ($p['id'] ?? ''), 'label' => (string)($p['name'] ?? 'Product'),
                    'code' => '[[printful-store product=' . ($p['id'] ?? '') . ' layout=single]]',
                    'badge' => (string)($p['id'] ?? ''), 'color' => '#e05252', 'desc' => 'Single product (natural size)', 'preview' => true];
    }
    // Only surface the group when products exist (catalog pill alone is noise on non-merch sites)
    if (count($items) > 1 || is_file($root . '/admin/data/printful/products.json'))
        $groups[] = ['key' => 'printful', 'label' => 'Printful', 'accent' => '#e05252', 'items' => $items];

    // ── Events Manager (published events) ───────────────────────────
    $evts = ins_read_json($root . '/admin/data/events_master.json');
    $evts = array_filter($evts, fn($e) => is_array($e) && ($e['status'] ?? '') === 'published');
    usort($evts, fn($a, $b) => strtotime((string)($a['start_date'] ?? '')) <=> strtotime((string)($b['start_date'] ?? '')));
    $items = [];
    foreach ($evts as $evt) {
        $items[] = ['id' => 'event:' . ($evt['id'] ?? ''), 'label' => (string)($evt['title'] ?? 'Untitled'),
                    'code' => '[[event:' . ($evt['id'] ?? '') . ']]', 'badge' => (string)($evt['start_date'] ?? ''),
                    'color' => '#f0a500', 'desc' => 'Single event card', 'preview' => true];
    }
    $groups[] = ['key' => 'events', 'label' => 'Events Mgr', 'accent' => '#f0a500', 'items' => $items];

    // ── Widgets (vstats / YouTube / podcast) ────────────────────────
    $items = [
        ['id' => 'vstats',         'label' => '📊 Stats (7d Dark)',  'code' => '[[vstats]]',                  'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'Traffic widget',  'preview' => true],
        ['id' => 'vstats-light',   'label' => '📊 Stats (7d Light)', 'code' => '[[vstats style="light"]]',    'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'Light theme',     'preview' => true],
        ['id' => 'vstats-minimal', 'label' => '📊 Stats (Minimal)',  'code' => '[[vstats style="minimal"]]',  'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'No background',   'preview' => true],
        ['id' => 'vstats-30d',     'label' => '📊 Stats (30 Days)',  'code' => '[[vstats period="30d"]]',     'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'Monthly view',    'preview' => true],
        ['id' => 'vstats-today',   'label' => '📊 Stats (Today)',    'code' => '[[vstats period="today"]]',   'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'Live today',      'preview' => true],
        ['id' => 'vstats-pod',     'label' => '📊 Stats + Podcasts', 'code' => '[[vstats podcasts="true"]]',  'badge' => 'vstats', 'color' => '#6580eb', 'desc' => 'With platforms',  'preview' => true],
    ];
    $items[] = ['id' => 'podcast-feed', 'label' => '🎧 Podcast Feed', 'code' => '[[podcast-feed]]', 'badge' => 'podcast', 'color' => '#6580eb', 'desc' => 'Episode listing', 'preview' => true];
    $groups[] = ['key' => 'widgets', 'label' => 'Widgets', 'accent' => '#6580eb', 'items' => $items];

    // ── YouTube (YT Playlist Studio records) ────────────────────────
    // Its own group as of 2026-08-17. These used to be appended to "Widgets",
    // where a new playlist landed underneath six near-identical vstats entries
    // and was effectively unfindable — reported as "the new playlist isn't in
    // Inserts" when it was in the catalog all along.
    //
    // The store holds BOTH record types. A type="video" single needs
    // [[youtube-video:…]]; emitting the playlist tag for everything was wrong
    // (the renderers cross-delegate, so it happened to work, but the shortcode
    // dropped into the page named the wrong thing).
    $ytDir = $root . '/admin/data/youtube_playlists';
    if (is_dir($ytDir)) {
        $items = [];
        foreach (glob("$ytDir/*.json") ?: [] as $ytf) {
            $bn = basename($ytf, '.json');
            if ($bn === 'cache' || $bn === 'index') continue;
            $ytd = ins_read_json($ytf);

            $isVideo = (($ytd['type'] ?? '') === 'video') || (!empty($ytd['video_id']) && empty($ytd['playlist_id']));
            $tag     = $isVideo ? 'youtube-video' : 'youtube-playlist';
            $label   = (string)($ytd['title'] ?? ucwords(str_replace('-', ' ', $bn)));

            // A record with no playlist_id / video_id renders to nothing. Say so
            // on the card instead of handing over a shortcode that silently
            // produces an empty div once it is on the page.
            $ready = $isVideo ? !empty($ytd['video_id']) : !empty($ytd['playlist_id']);
            $desc  = $isVideo ? 'YouTube single video' : 'YouTube playlist';
            if (!$ready) $desc = '⚠ Not configured yet — open YT Playlist Studio and set the '
                              . ($isVideo ? 'video URL' : 'playlist ID');

            $items[] = [
                'id'    => "$tag:$bn",
                'label' => ($isVideo ? '▶️ ' : '📋 ') . $label,
                'code'  => "[[$tag:$bn]]",
                'badge' => $isVideo ? 'single' : 'playlist',
                'color' => $ready ? '#ef4444' : '#94a3b8',
                'desc'  => $desc,
                'preview' => $ready,
            ];
        }
        if ($items) $groups[] = ['key' => 'youtube', 'label' => 'YouTube', 'accent' => '#ef4444', 'items' => $items];
    }

    // ── Affiliates (only when products exist) ───────────────────────
    $apAll = ins_read_json($root . '/admin/data/AffiliateProducts/products.json');
    $apOn  = array_filter($apAll, fn($p) => is_array($p) && ($p['enabled'] ?? true));
    if (!empty($apOn)) {
        $cats = array_values(array_unique(array_filter(array_column($apAll, 'category'))));
        sort($cats);
        $items = [['id' => 'affiliate-products', 'label' => 'All Products', 'code' => '[[affiliate-products]]', 'badge' => 'all', 'color' => '#ff9900', 'desc' => 'All affiliate products', 'preview' => true]];
        foreach ($cats as $cat) {
            $items[] = ['id' => 'affiliate-products:' . $cat, 'label' => (string)$cat,
                        'code' => '[[affiliate-products category="' . htmlspecialchars((string)$cat, ENT_QUOTES) . '"]]',
                        'badge' => 'category', 'color' => '#a78bfa', 'desc' => 'Affiliate category', 'preview' => true];
        }
        $groups[] = ['key' => 'affiliates', 'label' => 'Affiliates', 'accent' => '#ff9900', 'items' => $items];
    }

    // ── Audience Builder forms (only when forms exist) ──────────────
    $abDir = $root . '/admin/data/AudienceBuilder/forms';
    if (is_dir($abDir)) {
        $items = [];
        foreach (glob("$abDir/*.json") ?: [] as $abf) {
            $abd = ins_read_json($abf);
            if (!$abd) continue;
            $slug = basename($abf, '.json');
            $items[] = ['id' => "ab-form:$slug", 'label' => (string)($abd['title'] ?? ucwords(str_replace('-', ' ', $slug))),
                        'code' => "[[ab-form:$slug]]", 'badge' => $slug, 'color' => '#f0a500', 'desc' => 'Audience Builder form', 'preview' => true];
        }
        if ($items) $groups[] = ['key' => 'forms', 'label' => 'Audience Forms', 'accent' => '#f0a500', 'items' => $items];
    }

    // ── Panels (LEGACY PHP includes — last on purpose) ──────────────
    // The panels/ dir mixes canonical and site-specific files; a proper
    // cleanup/classification is queued (2026-06-06). Until then they stay
    // insertable but live at the bottom, clearly marked legacy.
    $deny = ['background_loader.php','diag_hero.php','load-panel.php','panel-layout-controller.php','panel-left.php','panel-right.php','pdf-proxy.php','img-proxy.php','pdf-viewer.php'];
    $items = [];
    $panelsDir = $root . '/panels';
    if (is_dir($panelsDir)) {
        $files = [];
        foreach (scandir($panelsDir) as $f) {
            if ($f[0] === '.' || substr($f, -4) !== '.php' || in_array($f, $deny, true)) continue;
            if (preg_match('/^content-stack-/', $f)) continue;   // surfaced as stacks above
            $files[] = $f;
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($files as $pn) {
            $dn = ucwords(str_replace('-', ' ', (string)preg_replace('/^panel-/', '', str_replace('.php', '', $pn))));
            if ($pn === 'panel-events.php') $dn = 'FB Events';
            $items[] = ['id' => "panel:$pn", 'label' => $dn, 'code' => "[[panel:$pn]]", 'badge' => $pn, 'color' => '#94a3b8', 'desc' => 'Legacy PHP panel'];
        }
    }
    if ($items) $groups[] = ['key' => 'panels', 'label' => 'Panels (legacy)', 'accent' => '#94a3b8', 'items' => $items];

    // Drop empty groups the UI would render as dead sections — EXCEPT the
    // core two (stacks/galleries) which show an empty-state hint.
    $keep = ['stacks', 'galleries'];
    return array_values(array_filter($groups, fn($g) => $g['items'] || in_array($g['key'], $keep, true)));
}
}
