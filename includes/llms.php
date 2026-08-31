<?php
/**
 * The Vault — llms.txt / llms-full.txt generator  (llmstxt.org standard)
 *
 * Publishes a machine-readable briefing of THIS site for AI / LLM readers
 * (ChatGPT, Claude, Perplexity, Google AI…). Self-contained: reads the site's
 * own pages + articles via SITE_ROOT, so it runs on any site in the fleet.
 *
 *   render_llms(false) → llms.txt      (the index: name, summary, linked contents)
 *   render_llms(true)  → llms-full.txt (The Vault: full page/article text inlined)
 *
 * "So the thread is never lost in the translation."
 */
declare(strict_types=1);

if (!function_exists('llms_domain')) {

function llms_domain(): string {
    // Real request host first (cPanel web-trigger sends it); then a configured
    // domain; then the SITE_ROOT dir name — but ONLY if it looks like a domain
    // (a vhost dir is typically the domain name; a cPanel docroot is "public_html").
    $h = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($h !== '' && $h !== 'localhost') return $h;
    $s = llms_settings();
    foreach (['site_domain', 'domain', 'canonical_url', 'site_url'] as $k) {
        if (!empty($s[$k])) {
            $d = preg_replace('#/.*$#', '', preg_replace('#^https?://#', '', rtrim((string)$s[$k], '/')));
            if ($d !== '') return $d;
        }
    }
    $b = defined('SITE_ROOT') ? basename(SITE_ROOT) : '';
    return strpos($b, '.') !== false ? $b : 'site';
}
function llms_base_url(): string { return 'https://' . llms_domain(); }

function llms_settings(): array {
    $f = SITE_ROOT . '/admin/data/site-settings.json';
    return is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
}

function llms_text_from_html(string $html, ?int $cap = null): string {
    // HTML → readable plain text; drop scripts/styles + CMS shortcode tokens first.
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('/\[\[[^\]]*\]\]/', ' ', $html);   // [[stack:x]] [[panel:x]] [[podcast-feed]] …
    $t = strip_tags($html);
    $t = str_replace("\xC2\xA0", ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));  // &nbsp; → space
    $t = trim(preg_replace('/[ \t]*\R+[ \t]*/', "\n", preg_replace('/[ \t]+/', ' ', $t)));
    $t = trim(preg_replace('/\n{3,}/', "\n\n", $t));
    if ($cap !== null) $t = mb_strimwidth(preg_replace('/\s+/', ' ', $t), 0, $cap, '…');
    return $t;
}

/** Published Page Manager pages (excludes trash + article-shadow slugs handled by caller). */
function llms_pages(): array {
    $dir = SITE_ROOT . '/admin/data/pages';
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $pd) {
        $slug = basename($pd);
        if ($slug === 'pages_trash') continue;
        $f = $pd . '/' . $slug . '.json';
        if (!is_file($f)) continue;
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $html = $d['components']['main_content']['content'] ?? '';
        $body = llms_text_from_html((string)$html);
        if ($body === '' && empty($d['page_title'])) continue;
        $out[] = [
            'slug'  => $slug,
            'title' => trim((string)($d['page_title'] ?? $slug)) ?: $slug,
            'desc'  => trim((string)($d['meta_description'] ?? '')) ?: llms_text_from_html((string)$html, 180),
            'body'  => $body,
            'url'   => llms_base_url() . '/?p=' . rawurlencode($slug),
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['slug'] === 'home') return -1;
        if ($b['slug'] === 'home') return 1;
        return strcasecmp($a['title'], $b['title']);
    });
    return $out;
}

/** Published articles from the ArticlesManager index (if the site has one). */
function llms_articles(): array {
    $f = SITE_ROOT . '/admin/data/Articles/index.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    $rows = $d['articles'] ?? $d;
    $out = [];
    foreach ((array)$rows as $r) {
        if (!is_array($r)) continue;
        $slug = (string)($r['slug'] ?? '');
        if ($slug === '') continue;
        if (isset($r['status']) && $r['status'] !== 'published') continue;
        $out[] = [
            'slug'  => $slug,
            'title' => trim((string)($r['title'] ?? $slug)) ?: $slug,
            'desc'  => trim((string)($r['excerpt'] ?? $r['description'] ?? '')),
            'url'   => llms_base_url() . '/blog/' . rawurlencode($slug),
        ];
    }
    return $out;
}

/** Imported/curated external sources for the briefing (Site Settings → The Vault → Add Sources). */
function llms_sources(): array {
    $s = llms_settings();
    return is_array($s['llms_sources'] ?? null) ? $s['llms_sources'] : [];
}

/** Upcoming events from the FacebookEvents module cache (the "content" of reflector/venue sites). */
function llms_events(int $max = 12): array {
    $dir = SITE_ROOT . '/admin/data/FacebookEvents/caches';
    if (!is_dir($dir)) return [];
    // Read the LIVE cache the site's homepage renders from (events.cache →
    // events_front.cache). NOT glob('*.json') — that catches stale relics like
    // the old this_week_events.json (an April snapshot left behind). 2026-07-08.
    $rows = [];
    foreach (['events.cache', 'events_front.cache'] as $cf) {
        $p = $dir . '/' . $cf;
        if (!is_file($p)) continue;
        $d = json_decode((string)@file_get_contents($p), true);
        $rows = $d['data'] ?? $d['events'] ?? (is_array($d) ? $d : []);
        if ($rows) break;
    }
    $out = []; $now = time();
    {
        foreach ((array)$rows as $e) {
            if (!is_array($e)) continue;
            $name = trim((string)($e['name'] ?? $e['title'] ?? ''));
            if ($name === '') continue;
            $start = (string)($e['start_time'] ?? $e['start'] ?? '');
            $ts = $start ? strtotime($start) : 0;
            if (!$ts || $ts < $now - 3600) continue; // UPCOMING only — require a real, non-past start time (drops stale events)
            $place = $e['place']['name'] ?? $e['location'] ?? '';
            $out[$name . '|' . $start] = [
                'name'  => $name,
                'when'  => (($dt = date_create($start)) ? $dt->format('D M j, Y g:ia') : ($ts ? date('D M j, Y g:ia', $ts) : $start)),  // preserve the event's own TZ offset (server is UTC)
                'ts'    => $ts,
                'place' => is_string($place) ? $place : '',
                'desc'  => llms_text_from_html((string)($e['description'] ?? ''), 200),
            ];
        }
    }
    usort($out, fn($a, $b) => ($a['ts'] ?: PHP_INT_MAX) <=> ($b['ts'] ?: PHP_INT_MAX));
    return array_slice(array_values($out), 0, $max);
}

/** Format Facebook's hours object (mon_1_open / mon_1_close …) into a readable line. */
function llms_format_hours(array $h): string {
    $days = ['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'];
    $out = [];
    foreach ($days as $k=>$label) {
        $spans = [];
        for ($i=1; $i<=3; $i++) {
            $o = $h["{$k}_{$i}_open"] ?? null; $c = $h["{$k}_{$i}_close"] ?? null;
            if ($o && $c) $spans[] = "{$o}–{$c}";
        }
        if ($spans) $out[] = "{$label} " . implode(', ', $spans);
    }
    return implode(' · ', $out);
}

/** Import the site's Facebook Page profile via the FacebookEvents token (Add Sources). */
function llms_import_facebook(): array {
    $cfgFile = SITE_ROOT . '/admin/data/FacebookEvents/config.json';
    if (!is_file($cfgFile)) return ['error' => 'FacebookEvents is not set up on this site'];
    $cfg   = json_decode((string)@file_get_contents($cfgFile), true) ?: [];
    $token = trim((string)($cfg['access_token'] ?? ''));
    $page  = trim((string)($cfg['page_id'] ?? ''));
    if ($token === '' || $page === '') return ['error' => 'Facebook access_token or page_id missing in FacebookEvents config'];
    $fields = 'name,about,description,category,category_list,location,single_line_address,hours,phone,emails,website';
    $url = 'https://graph.facebook.com/v19.0/' . rawurlencode($page) . '?fields=' . $fields . '&access_token=' . rawurlencode($token);
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['error' => 'Could not reach the Facebook Graph API'];
    $d = json_decode((string)$raw, true);
    if (!is_array($d) || isset($d['error'])) return ['error' => 'Facebook: ' . ($d['error']['message'] ?? 'unexpected response')];
    $loc = is_array($d['location'] ?? null) ? $d['location'] : [];
    $address = trim((string)($d['single_line_address'] ?? implode(', ', array_filter([
        $loc['street'] ?? '', $loc['city'] ?? '', $loc['state'] ?? '', (string)($loc['zip'] ?? '')]))));
    $cat = (string)($d['category'] ?? '');
    if ($cat === '' && !empty($d['category_list'][0]['name'])) $cat = $d['category_list'][0]['name'];
    return [
        'imported_at' => gmdate('c'),
        'name'     => (string)($d['name'] ?? ''),
        'about'    => trim((string)($d['about'] ?? $d['description'] ?? '')),
        'category' => $cat,
        'location' => (string)($loc['city'] ?? ''),
        'address'  => $address,
        'hours'    => (!empty($d['hours']) && is_array($d['hours'])) ? llms_format_hours($d['hours']) : '',
        'phone'    => (string)($d['phone'] ?? ''),
        'emails'   => $d['emails'] ?? [],
        'website'  => (string)($d['website'] ?? ''),
    ];
}

/** Load the site's active AIResources provider (or null). */
function llms_ai_provider() {
    $cfg = SITE_ROOT . '/admin/data/AIResources/config.json';
    $reg = SITE_ROOT . '/admin/modules/AIResources/providers/ProviderRegistry.php';
    if (!is_file($cfg) || !is_file($reg)) return null;
    require_once $reg;
    try { $r = new ProviderRegistry($cfg); return $r->getActiveProvider(); }
    catch (\Throwable $e) { return null; }
}

/** Assemble source material for the AI briefing (FB facts + events + page snippets). */
function llms_briefing_material(): string {
    $s  = llms_settings();
    $fb = $s['llms_sources']['facebook'] ?? [];
    $m  = 'Site: ' . llms_site_name() . ' (' . llms_base_url() . ")\n";
    foreach (['about'=>'Description','category'=>'Category','address'=>'Location','hours'=>'Hours','phone'=>'Phone','website'=>'Website'] as $k=>$lab) {
        $v = $fb[$k] ?? ''; if (is_array($v)) $v = implode(', ', $v);
        if (trim((string)$v) !== '') $m .= "{$lab}: {$v}\n";
    }
    foreach (llms_events(8) as $e) $m .= "Upcoming event: {$e['name']} — {$e['when']}" . ($e['place'] ? " @ {$e['place']}" : '') . "\n";
    foreach (array_slice(llms_pages(), 0, 8) as $p) if ($p['desc'] !== '') $m .= "Page \"{$p['title']}\": {$p['desc']}\n";
    return trim($m);
}

/** AI-write a full briefing DOCUMENT (multi-paragraph prose) from the material. */
function llms_generate_briefing(): array {
    $material = llms_briefing_material();
    if (substr_count($material, "\n") < 1) return ['error' => 'Not enough source material — import a source (Facebook) or add page content first.'];
    $provider = llms_ai_provider();
    if (!$provider) return ['error' => 'AI is not configured — set up a provider in AIResources first.'];
    $name = llms_site_name();
    $sys = "You write comprehensive, factual briefing documents about businesses and websites for AI/LLM search readers — the authoritative overview an AI reads to represent the business accurately. Clear prose, 3-5 short paragraphs. Cover: what it is, what it offers, location & practical details, and its character/reputation. Third person. No marketing fluff, no invented facts. Use ONLY the provided material.";
    $usr = "Write a briefing document for \"{$name}\" (" . llms_base_url() . ") from this material:\n\n{$material}";
    try { $res = $provider->generate($sys, $usr); }
    catch (\Throwable $e) { return ['error' => 'AI error: ' . $e->getMessage()]; }
    // ProviderInterface::generate() returns an array: ['content'=>text,...] on success,
    // ['ok'=>false,'message'=>...] on failure.
    if (is_array($res) && ($res['ok'] ?? true) === false) return ['error' => 'AI: ' . ($res['message'] ?? 'generation failed')];
    $out = trim((string)(is_array($res) ? ($res['content'] ?? '') : $res));
    return $out === '' ? ['error' => 'The AI returned nothing — try again.'] : ['briefing' => $out];
}

function llms_site_name(): string {
    $s = llms_settings();
    foreach (['site_name', 'site_title', 'name'] as $k) {
        $v = trim((string)($s[$k] ?? ''));
        if ($v !== '') return $v;
    }
    foreach (llms_pages() as $p) if ($p['slug'] === 'home') return $p['title'];
    return llms_domain();
}

function render_llms(bool $full = false): string {
    $name     = llms_site_name();
    $s        = llms_settings();
    $allPages = llms_pages();   // every page, with bodies
    $arts     = llms_articles();
    // Articles share the pages dir — keep a body map so the full briefing can inline them.
    $bodyBySlug = [];
    foreach ($allPages as $p) $bodyBySlug[strtolower($p['slug'])] = $p['body'];
    $artSlugs = [];
    foreach ($arts as $a) $artSlugs[strtolower($a['slug'])] = true;
    $pages = array_values(array_filter($allPages, fn($p) => !isset($artSlugs[strtolower($p['slug'])])));

    // Curated "About / Overview" (Site Settings → The Vault → llms_about, or ✨-generated),
    // else the site description / home summary. This LEADS the briefing — the sharpest framing up top.
    $about        = trim((string)($s['llms_about'] ?? ''));
    $aboutCurated = ($about !== '');   // an actually-authored About vs the auto-description fallback
    $desc  = trim((string)($s['site_description'] ?? $s['description'] ?? $s['tagline'] ?? ''));
    if ($desc === '')  foreach ($pages as $p) if ($p['slug'] === 'home') { $desc = $p['desc']; break; }
    if ($about === '') $about = $desc;

    $sources = llms_sources();
    $fb      = is_array($sources['facebook'] ?? null) ? $sources['facebook'] : [];
    $events  = llms_events();

    $out  = "# {$name}\n\n";
    if ($desc !== '') $out .= "> {$desc}\n\n";
    // About/Overview — in the index it's the ~about-page-sized summary; in the full briefing it's the lead.
    if ($about !== '' && $about !== $desc) $out .= "## About\n\n{$about}\n\n";
    $out .= "Machine-readable site briefing (llms.txt · llmstxt.org) for " . llms_base_url()
          . ", published for AI and LLM readers.\n\n";

    // AI Answer Bank — canonical, gap-closing SOURCE sections (Positioning / FAQ / Competitors /
    // AI Answer Bank) authored by AI Visibility to make assistants recommend + correctly identify
    // this entity. An authored atom in Site Settings (like llms_about); inlined verbatim near the
    // top so it leads the briefing. Present in both the index and the full trove.
    $answerBank = trim((string)($s['llms_answer_bank'] ?? ''));
    if ($answerBank !== '') $out .= $answerBank . "\n\n";

    // Business details — imported source facts (esp. valuable for content-thin / reflector sites).
    if ($fb) {
        $rows = [];
        foreach (['category'=>'Category','location'=>'Location','address'=>'Address','hours'=>'Hours',
                  'phone'=>'Phone','emails'=>'Email','website'=>'Website'] as $k=>$label) {
            $v = $fb[$k] ?? '';
            if (is_array($v)) $v = implode(', ', array_filter(array_map('strval', $v)));
            $v = trim((string)$v);
            if ($v !== '') $rows[] = "- **{$label}:** {$v}";
        }
        if ($rows) $out .= "## Business Details\n\n" . implode("\n", $rows) . "\n\n";
    }

    // Upcoming events — for reflector/venue sites, the events ARE the content.
    if ($events) {
        $out .= "## Upcoming Events\n\n";
        foreach ($events as $e) {
            $out .= "- **{$e['name']}**" . ($e['when'] !== '' ? " — {$e['when']}" : '')
                  . ($e['place'] !== '' ? " @ {$e['place']}" : '') . "\n";
            if ($full && $e['desc'] !== '') $out .= "  {$e['desc']}\n";
        }
        $out .= "\n";
    }

    if ($pages) {
        $out .= "## Pages\n\n";
        foreach ($pages as $p) {
            $out .= "- [{$p['title']}]({$p['url']})" . ($p['desc'] !== '' ? ": {$p['desc']}" : '') . "\n";
            if ($full && $p['body'] !== '') $out .= "\n{$p['body']}\n\n";
        }
        $out .= "\n";
    }
    if ($arts) {
        $out .= "## Articles\n\n";
        foreach ($arts as $a) {
            $out .= "- [{$a['title']}]({$a['url']})" . ($a['desc'] !== '' ? ": {$a['desc']}" : '') . "\n";
            // Full briefing inlines the article body (the richest content) — the kitchen sink, led by your framing.
            if ($full) {
                $body = $bodyBySlug[strtolower($a['slug'])] ?? '';
                if ($body !== '') $out .= "\n{$body}\n\n";
            }
        }
        $out .= "\n";
    }
    // ── Provenance: how this briefing was generated ──
    $srcNotes = [];
    $srcNotes[] = count($pages) . ' page' . (count($pages) === 1 ? '' : 's');
    if ($arts)   $srcNotes[] = count($arts) . ' article' . (count($arts) === 1 ? '' : 's');
    if ($events) $srcNotes[] = count($events) . ' upcoming event' . (count($events) === 1 ? '' : 's') . ' (Facebook)';
    if ($aboutCurated) $srcNotes[] = 'curated About';
    if (!empty($fb['imported_at'])) $srcNotes[] = 'Facebook profile (imported ' . substr((string)$fb['imported_at'], 0, 10) . ')';
    $out .= "---\n## How this was generated\n\n";
    $out .= "This document is auto-generated by **Luminal CMS — The Vault** from the site's own content and any connected sources, published for AI/LLM readers.\n\n";
    $out .= "- **Sources:** " . implode(' · ', $srcNotes) . "\n";
    $out .= "- **Generated:** " . gmdate('Y-m-d') . " UTC · refreshed weekly\n";
    $out .= "- **Format:** llmstxt.org (`llms.txt` = index · `llms-full.txt` = full briefing)\n";
    return $out;
}

/* ───────────────────────── Auto-refresh (staleness-driven) ─────────────────────────
 * The briefing is DERIVED from the site's own content, so "is it stale?" is derived
 * too — compare the newest content mtime to the llms.txt mtime. No dirty-flag to drift
 * (one source of truth). A frequent hub cron calls llms_is_stale() and regenerates only
 * the sites that actually changed; the weekly full run stays as an unconditional backstop. */

/** Unix mtime of the currently-published llms.txt (0 if never generated). */
function llms_last_gen(): int {
    return (int)@filemtime(SITE_ROOT . '/llms.txt');
}

/** Newest mtime across every source that feeds the briefing (pages, articles, events, settings). */
function llms_content_mtime(): int {
    $newest = 0;
    $pd = SITE_ROOT . '/admin/data/pages';
    if (is_dir($pd)) {
        foreach (glob($pd . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (basename($d) === 'pages_trash') continue;
            $f = $d . '/' . basename($d) . '.json';
            if (!is_file($f)) $f = $d . '/page.json';
            if (is_file($f)) $newest = max($newest, (int)@filemtime($f));
        }
    }
    foreach ([
        '/admin/data/Articles/index.json',
        '/admin/data/site-settings.json',
        '/admin/data/FacebookEvents/caches/events.cache',
        '/admin/data/FacebookEvents/caches/events_front.cache',
    ] as $rel) {
        $f = SITE_ROOT . $rel;
        if (is_file($f)) $newest = max($newest, (int)@filemtime($f));
    }
    return $newest;
}

/** True when the site's content is newer than its llms.txt — unless auto-refresh is opted out. */
function llms_is_stale(): bool {
    $s = llms_settings();
    if (array_key_exists('llms_auto_refresh', $s) && !$s['llms_auto_refresh']) return false; // opted out
    if (!is_file(SITE_ROOT . '/llms.txt')) return true;   // never generated
    return llms_content_mtime() > llms_last_gen();
}

/** Regenerate + publish both briefing files from current content. Returns byte sizes + mtime. */
function llms_write(): array {
    @file_put_contents(SITE_ROOT . '/llms.txt', render_llms(false));
    @file_put_contents(SITE_ROOT . '/llms-full.txt', render_llms(true));
    clearstatcache();
    return [
        'idx'      => (int)@filesize(SITE_ROOT . '/llms.txt'),
        'full'     => (int)@filesize(SITE_ROOT . '/llms-full.txt'),
        'last_gen' => llms_last_gen(),
    ];
}

} // end function guard
