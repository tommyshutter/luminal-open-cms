<?php
/**
 * DashboardStatsOG — Dashboard Stats Engine
 * Reads Apache access logs directly. Zero dependency on StatsCaptainOG.
 * Included from Dashboard.php (full panel) and shortcodes.php (stamp).
 *
 * File:    admin/modules/Dashboard/DashboardStatsOG.php
 * Version: 2026.04.09
 */

if (!defined('SITE_ROOT')) return;

// ── Constants ─────────────────────────────────────────────────────────────────
if (!defined('DSOG_CACHE_TTL')) define('DSOG_CACHE_TTL', 900); // 15 minutes
if (!defined('DSOG_LOG_ROOT'))  define('DSOG_LOG_ROOT',  '/var/log/vhosts');

// ── Helpers ───────────────────────────────────────────────────────────────────
if (!function_exists('dsog_fmt_bytes')) {
    function dsog_fmt_bytes(int $b): string {
        if ($b <= 0) return '0 B';
        $u = ['B','KB','MB','GB','TB'];
        $i = min((int)floor(log($b, 1024)), 4);
        return sprintf('%.1f %s', $b / pow(1024, $i), $u[$i]);
    }
}
if (!function_exists('dsog_compact_num')) {
    function dsog_compact_num(int $n): string {
        if ($n >= 1_000_000) return sprintf('%.1fM', $n / 1_000_000);
        if ($n >= 10_000)    return sprintf('%.1fK', $n / 1_000);
        return number_format($n);
    }
}

// ── Config loader ─────────────────────────────────────────────────────────────
if (!function_exists('dsog_load_config')) {
    function dsog_load_config(): array {
        $path = SITE_ROOT . '/admin/data/DashboardStatsOG/config.json';
        if (!is_file($path)) return [];
        $d = json_decode(file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }
    function dsog_save_config(array $data): void {
        $dir = SITE_ROOT . '/admin/data/DashboardStatsOG';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/config.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// ── cPanel account home probe ─────────────────────────────────────────────────
// NEVER assume the home is /home/<user>. HostGator and most cPanel hosts number
// account homes (/home4, /home9, … up to /homeNNN) and can move them at will, so
// the path must be PROBED, not guessed. This panel's PHP runs AS the cPanel
// account user (suexec / per-account PHP-FPM), which makes the process's own
// passwd entry the single authoritative source — correct regardless of numbering,
// addon-domain docroot depth, or where the docroot physically lives.
if (!function_exists('dsog_cpanel_home')) {
    function dsog_cpanel_home(): ?string {
        static $cached = false;
        if ($cached !== false) return $cached;

        // 1. Authoritative: this process's own passwd home (the account user).
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if (!empty($pw['dir']) && $pw['dir'] !== '/' && is_dir($pw['dir'])) {
                return $cached = rtrim($pw['dir'], '/');
            }
        }
        // 2. Environment HOME (set by cPanel/suexec for the account).
        foreach ([getenv('HOME'), $_SERVER['HOME'] ?? null] as $h) {
            if ($h && $h !== '/' && is_dir($h)) return $cached = rtrim($h, '/');
        }
        // 3. Derive from the docroot: walk up until we find the dir that holds
        //    public_html (= the account home). Resolves addon/subdomain docroots
        //    to the real account home without caring about /home numbering.
        $p = SITE_ROOT;
        for ($i = 0; $i < 8 && $p && $p !== '/' && $p !== '.'; $i++) {
            if (is_dir($p . '/public_html') || is_dir($p . '/tmp/awstats')) {
                return $cached = rtrim($p, '/');
            }
            $p = dirname($p);
        }
        return $cached = null;
    }
}

// ── Log discovery ─────────────────────────────────────────────────────────────
if (!function_exists('dsog_find_log_files')) {
    function dsog_find_log_files(string $domain): array {
        $cfg   = dsog_load_config();
        $files = [];

        // 1. Manual override in config (admin-set custom path)
        if (!empty($cfg['custom_log_path'])) {
            $custom = str_replace('{domain}', $domain, $cfg['custom_log_path']);
            foreach (glob($custom) ?: [] as $f) {
                if (is_readable($f)) $files[] = $f;
            }
            if (!empty($files)) return $files;
        }

        // 2. Standard DO/DigitalOcean vhosts path
        $dir = DSOG_LOG_ROOT . '/' . $domain;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*access*log*') ?: [] as $f) {
                if (is_readable($f)) $files[] = $f;
            }
            // Also match plain access.log (no domain prefix)
            if (empty($files) && is_readable($dir . '/access.log')) {
                $files[] = $dir . '/access.log';
            }
        }
        if (!empty($files)) return $files;

        // 3. cPanel raw domlogs — anchored to the PROBED account home (never a
        //    /home*/* guess). These symlinks are frequently dead on HostGator (the
        //    AWStats DB above is preferred), but check the real account paths anyway.
        $cpanel_candidates = [];
        $home = dsog_cpanel_home();
        if ($home) {
            foreach (glob($home . '/logs/' . $domain . '*') ?: [] as $f)        $cpanel_candidates[] = $f;
            foreach (glob($home . '/access-logs/' . $domain . '*') ?: [] as $f) $cpanel_candidates[] = $f;
            $cpanel_candidates[] = $home . '/access-logs/' . $domain;
        }
        // Server-level domlogs fallback (WHM hosts that expose them).
        foreach (glob('/usr/local/apache/domlogs/*/' . $domain) ?: [] as $f) {
            $cpanel_candidates[] = $f;
        }
        foreach ($cpanel_candidates as $f) {
            if (is_readable($f) && !in_array($f, $files)) $files[] = $f;
        }
        if (!empty($files)) return $files;

        // 4. apache2 fallback
        foreach (["/var/log/apache2/{$domain}-access.log", "/var/log/apache2/access.log"] as $f) {
            if (is_readable($f) && !in_array($f, $files)) $files[] = $f;
        }
        return $files;
    }
}

// ── Static asset filter ───────────────────────────────────────────────────────
if (!function_exists('dsog_is_asset')) {
    function dsog_is_asset(string $uri): bool {
        return (bool)preg_match('/\.(css|js|ico|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|avif|mp4|webm|ogg|map|txt|xml|json|gz|zip|wasm)(\?|$)/i', $uri);
    }
}

// A real mp3 download vs an Apple/iTMS HEAD size-check or tiny range-probe.
if (!defined('DSOG_MP3_MIN_BYTES')) define('DSOG_MP3_MIN_BYTES', 500000); // 500 KB

// ── System / non-front traffic ────────────────────────────────────────────────
// The admin panel + every module backend/API (ServerMonitor/api.php, StatsCaptainOG
// pixel, AgentScheduler/cron.php, LogViewer, Vstats, …), generic /api/ roots, cron
// and health/liveness probes, and common bot-probe paths. NOT real visitor hits.
if (!function_exists('dsog_is_system')) {
    function dsog_is_system(string $uri): bool {
        if (preg_match('#(^|/)admin/#i', $uri)) return true;   // all module backends + admin panel
        if (preg_match('#(^|/)api/#i',   $uri)) return true;   // generic API roots
        return (bool)preg_match('#(^|/)(cron|liveness|healthz?|ping|status\.php|xmlrpc\.php|wp-login\.php|wp-admin)(/|\.php|$)#i', $uri);
    }
}

// ── Bot / crawler / catalog-fetcher filter (by User-Agent) ─────────────────────
// Excluded from visitor stats. Note: real podcast listeners stream via AppleCoreMedia
// (kept); Apple's iTMS agent is the catalog crawler + newest-episode prefetch hammer.
if (!function_exists('dsog_is_bot')) {
    function dsog_is_bot(string $ua): bool {
        if ($ua === '' || $ua === '-') return true;
        return (bool)preg_match('#(iTMS|bot\b|crawl|spider|slurp|monitor|uptime|pingdom|headless|curl|wget|python-requests|go-http|libwww|facebookexternalhit|okhttp|scrapy|semrush|ahrefs|bingpreview|dataminr|feedfetcher)#i', $ua);
    }
}

// ── Bot UA filter ─────────────────────────────────────────────────────────────
if (!function_exists('dsog_is_bot')) {
    function dsog_is_bot(string $ua): bool {
        return (bool)preg_match('/bot|crawler|spider|slurp|facebookexternalhit|WhatsApp|Slack|Discordbot|Twitterbot|linkedinbot|applebot|bingpreview|google-read-aloud|headless|python-requests|go-http-client|Java\/|curl\/|wget\/|libwww|scrapy|ahrefsbot|semrushbot|dotbot|mj12bot|petalbot|bytespider/i', $ua);
    }
}

// ── Parse one Apache Combined Log line ────────────────────────────────────────
if (!function_exists('dsog_parse_line')) {
    function dsog_parse_line(string $line): ?array {
        static $re = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d{3}) (\S+) "([^"]*)" "([^"]*)"$/';
        if (!preg_match($re, $line, $m)) return null;
        $reqParts = explode(' ', $m[3], 3);
        $uri = $reqParts[1] ?? '/';
        $dt  = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
        if (!$dt) return null;
        return [
            'ip'       => $m[1],
            'ts'       => $dt->getTimestamp(),
            'day'      => $dt->format('Y-m-d'),
            'method'   => $reqParts[0] ?? 'GET',
            'uri'      => $uri,
            'status'   => (int)$m[4],
            'bytes'    => $m[5] === '-' ? 0 : (int)$m[5],
            'referrer' => $m[6],
            'ua'       => $m[7],
        ];
    }
}

// ── Range → timestamp bounds (ET) ────────────────────────────────────────────
if (!function_exists('dsog_range_bounds')) {
    function dsog_range_bounds(string $range): array {
        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTime('now', $tz);
        switch ($range) {
            case 'today':      $start = (new DateTime('today',                   $tz))->getTimestamp(); break;
            case '7d':         $start = (new DateTime('-7 days midnight',        $tz))->getTimestamp(); break;
            case 'this_month': $start = (new DateTime('first day of this month midnight', $tz))->getTimestamp(); break;
            case '30d': default: $start = (new DateTime('-30 days midnight',     $tz))->getTimestamp(); break;
        }
        return [$start, $now->getTimestamp()];
    }
}

// ── Daily series zero-fill (for mountain chart) ──────────────────────────────
// Buckets are keyed on UTC calendar date (gmdate of the absolute timestamp), so
// the series is deterministic regardless of whether a server logs in UTC, ET, or
// CT — DO and cPanel sites land on the same calendar without per-site TZ config.
if (!function_exists('dsog_fill_series')) {
    function dsog_fill_series(array $series, int $start, int $end): array {
        $out    = [];
        $dStart = strtotime(gmdate('Y-m-d', $start) . ' UTC');
        $dEnd   = strtotime(gmdate('Y-m-d', $end)   . ' UTC');
        $guard  = 0;
        for ($t = $dStart; $t <= $dEnd && $guard < 400; $t += 86400, $guard++) {
            $key = gmdate('Y-m-d', $t);
            $row = $series[$key] ?? ['visitors' => 0, 'page_views' => 0, 'hits' => 0, 'mp3' => 0];
            $rowOut = [
                't'          => gmdate('M j', $t),
                'date'       => $key,
                'visitors'   => (int)($row['visitors']   ?? 0),
                'page_views' => (int)($row['page_views'] ?? 0),
                'hits'       => (int)($row['hits']       ?? 0),
                'mp3'        => (int)($row['mp3']         ?? 0),
            ];
            // Per-day top contributor (spike attribution in the chart hover), when known.
            if (!empty($row['top_page'])) { $rowOut['top_page'] = $row['top_page']; $rowOut['top_page_n'] = (int)($row['top_page_n'] ?? 0); }
            if (!empty($row['top_dl']))   { $rowOut['top_dl']   = $row['top_dl'];   $rowOut['top_dl_n']   = (int)($row['top_dl_n']   ?? 0); }
            $out[] = $rowOut;
        }
        return $out;
    }
}

// ── Referrer category ─────────────────────────────────────────────────────────
if (!function_exists('dsog_ref_category')) {
    function dsog_ref_category(string $host): array {
        $h = strtolower(preg_replace('/^www\./', '', $host));
        $search  = ['google.com','bing.com','yahoo.com','duckduckgo.com','baidu.com','yandex.ru','ecosia.org','brave.com','kagi.com','search.brave.com'];
        $social  = ['facebook.com'=>'Facebook','instagram.com'=>'Instagram','twitter.com'=>'Twitter','x.com'=>'X/Twitter','reddit.com'=>'Reddit','linkedin.com'=>'LinkedIn','tiktok.com'=>'TikTok','pinterest.com'=>'Pinterest','threads.net'=>'Threads','bsky.app'=>'Bluesky','youtube.com'=>'YouTube','mastodon.social'=>'Mastodon'];
        $podcast = ['podcasts.apple.com','spotify.com','castbox.fm','overcast.fm','pca.st','podcastaddict.com','podcastindex.org','podbean.com','music.amazon.com','iheart.com','tunein.com','deezer.com','fountain.fm','podverse.fm'];

        foreach ($search as $s) {
            if ($h === $s || str_ends_with($h, '.' . $s)) return ['cat'=>'search','color'=>'#60a5fa','icon'=>'&#128269;'];
        }
        foreach ($social as $dom => $name) {
            if ($h === $dom || str_ends_with($h, '.' . $dom)) return ['cat'=>'social','color'=>'#a78bfa','icon'=>'&#128172;'];
        }
        foreach ($podcast as $dom) {
            if ($h === $dom || str_ends_with($h, '.' . $dom)) return ['cat'=>'podcast','color'=>'#34d399','icon'=>'&#127911;'];
        }
        return ['cat'=>'direct','color'=>'#f59e0b','icon'=>'&#127760;'];
    }
}

// ── AWStats source (cPanel hosts) ────────────────────────────────────────────
// HostGator/cPanel sites keep a pre-aggregated flat-file DB at
// <account-home>/tmp/awstats/awstats<MMYYYY>.<domain>.txt — authoritative, with
// years of history, and immune to the broken raw access-logs symlink cPanel
// often leaves behind. When present we prefer it over raw-log parsing.
if (!function_exists('dsog_awstats_dir')) {
    function dsog_awstats_dir(): ?string {
        $cfg = dsog_load_config();
        if (!empty($cfg['awstats_dir']) && is_dir($cfg['awstats_dir'])) return $cfg['awstats_dir'];
        // The AWStats DB lives at <account-home>/tmp/awstats. Probe the real home
        // (handles /home4, addon-domain docroots, etc.) rather than assuming the
        // docroot's parent is the home.
        $home = dsog_cpanel_home();
        if ($home && is_dir($home . '/tmp/awstats')) return $home . '/tmp/awstats';
        // Legacy fallback for the simple case where the docroot sits directly in
        // the account home (…/public_html → …/tmp/awstats).
        $cand = dirname(SITE_ROOT) . '/tmp/awstats';
        return is_dir($cand) ? $cand : null;
    }
}

// Resolve the token used in awstats<MMYYYY>.<token>.txt filenames for a requested
// domain. On cPanel the DB is named after the AWStats config that OWNS the traffic
// — frequently the account's MAIN domain (or a leftover HostGator temp domain like
// bko.owl.temporary.site), with the domain the visitor actually typed listed only
// as a HostAlias. So never assume the filename == the requested domain: read the
// .conf files and match SiteDomain / HostAliases.
if (!function_exists('dsog_awstats_token')) {
    function dsog_awstats_token(string $dir, string $domain): ?string {
        $domain = strtolower($domain);
        // 1. Fast path: a DB literally named after the domain exists.
        if (glob($dir . '/awstats??????.' . $domain . '.txt')) return $domain;

        // 2. Inspect AWStats .conf files for the config that serves this domain.
        $candidates = []; // token => SiteDomain
        foreach (glob($dir . '/awstats.*.conf') ?: [] as $cf) {
            if (!preg_match('#/awstats\.(.+)\.conf$#', $cf, $mm)) continue;
            $token = $mm[1];
            $txt = @file_get_contents($cf);
            if ($txt === false) continue;
            $site    = preg_match('/^\s*SiteDomain\s*=\s*"?([^"\r\n]+)"?/mi', $txt, $a) ? strtolower(trim($a[1])) : strtolower($token);
            $aliases = preg_match('/^\s*HostAliases\s*=\s*"?([^"\r\n]+)"?/mi', $txt, $b) ? preg_split('/\s+/', strtolower(trim($b[1]))) : [];
            $set = array_merge([$site], $aliases);
            if (in_array($domain, $set, true) || in_array('www.' . $domain, $set, true)) {
                $candidates[$token] = $site;
            }
        }
        if (!$candidates) return null;
        // Exact SiteDomain match wins (admin viewing the config's own domain).
        foreach ($candidates as $tok => $sd) if ($sd === $domain) return $tok;
        // Drop child sites whose SiteDomain is a subdomain of the requested domain
        // (e.g. shop.<domain> is a separate site, not the requested parent).
        $pool = array_filter($candidates, fn($sd) => !str_ends_with($sd, '.' . $domain)) ?: $candidates;
        // Tie-break: fewest labels (closest to a primary/parked domain).
        uasort($pool, fn($x, $y) => substr_count($x, '.') <=> substr_count($y, '.'));
        return array_key_first($pool);
    }
}

if (!function_exists('dsog_awstats_files_for_range')) {
    // [ 'YYYYMM' => path ] for each month the range touches that has a DB file.
    function dsog_awstats_files_for_range(string $domain, int $start, int $end): array {
        $dir = dsog_awstats_dir();
        if (!$dir) return [];
        $token = dsog_awstats_token($dir, $domain);
        if (!$token) return [];
        $out  = [];
        $cur  = strtotime(gmdate('Y-m-01', $start) . ' UTC');
        $stop = strtotime(gmdate('Y-m-01', $end)   . ' UTC');
        $guard = 0;
        while ($cur !== false && $cur <= $stop && $guard < 36) {
            $f = $dir . '/awstats' . gmdate('m', $cur) . gmdate('Y', $cur) . '.' . $token . '.txt';
            if (is_readable($f)) $out[gmdate('Ym', $cur)] = $f;
            $cur = strtotime(gmdate('Y-m-01', $cur) . ' +1 month UTC');
            $guard++;
        }
        return $out;
    }
}

if (!function_exists('dsog_awstats_section')) {
    // Rows of an AWStats section as arrays of whitespace-split fields.
    function dsog_awstats_section(string $txt, string $name): array {
        if (!preg_match('/^BEGIN_' . $name . ' \d+\r?\n(.*?)^END_' . $name . '/ms', $txt, $m)) return [];
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($m[1])) as $line) {
            if ($line === '') continue;
            $rows[] = preg_split('/\s+/', trim($line));
        }
        return $rows;
    }
}

if (!function_exists('dsog_compute_stats_awstats')) {
    function dsog_compute_stats_awstats(string $domain, string $range, int $rangeStart, int $rangeEnd, array $files): array {
        $s = [
            'domain'        => $domain,
            'range'         => $range,
            'computed_at'   => time(),
            'source'        => 'awstats',
            'log_files'     => count($files),
            'log_file_list' => array_map('basename', array_values($files)),
            'page_views'    => 0,
            'total_hits'    => 0,
            'bandwidth'     => 0,
            'top_pages'     => [],
            'referrers'     => [],
            'mp3_hits'      => 0,
            'mp3_hits_raw'  => 0,
            'mp3_bandwidth' => 0,
            'mp3_files'     => [],
            'feed_hits'     => 0,
            'system_hits'   => 0,   // /admin module APIs, cron, probes — excluded from visitor stats
            'system_paths'  => [],  // top system endpoints (for the System Hits panel)
            'bot_hits'      => 0,   // crawlers / iTMS prefetch — excluded from visitor stats
            'status_codes'  => [],
            'lines_parsed'  => 0,
            'error'         => null,
        ];
        $buildSeries = ($range !== 'today');
        $series      = [];
        $startYmd    = gmdate('Ymd', $rangeStart);
        $endYmd      = gmdate('Ymd', $rangeEnd);

        foreach ($files as $f) {
            $txt = @file_get_contents($f);
            if ($txt === false || $txt === '') continue;

            // DAY: <yyyymmdd> <pages> <hits> <bandwidth> <visits>
            foreach (dsog_awstats_section($txt, 'DAY') as $r) {
                if (count($r) < 5 || !ctype_digit($r[0])) continue;
                if ($r[0] < $startYmd || $r[0] > $endYmd) continue;
                $pages = (int)$r[1]; $hits = (int)$r[2]; $bw = (int)$r[3]; $visits = (int)$r[4];
                $s['page_views'] += $pages;
                $s['total_hits'] += $hits;
                $s['bandwidth']  += $bw;
                if ($buildSeries) {
                    $key = substr($r[0],0,4).'-'.substr($r[0],4,2).'-'.substr($r[0],6,2);
                    $series[$key] = ['visitors'=>$visits, 'page_views'=>$pages, 'hits'=>$hits, 'mp3'=>0];
                }
                $s['lines_parsed'] += $hits;
                $s['__visits'] = ($s['__visits'] ?? 0) + $visits;
            }

            // SIDER: <page_url> <pages> <bandwidth> <entry> <exit>  → top pages
            foreach (dsog_awstats_section($txt, 'SIDER') as $r) {
                if (count($r) < 2) continue;
                $u = $r[0];
                if (strlen($u) > 120) continue;
                $s['top_pages'][$u] = ($s['top_pages'][$u] ?? 0) + (int)$r[1];
            }

            // SIDER_404: <page_url> <hits> ...  → fold into status_codes[404]
            foreach (dsog_awstats_section($txt, 'SIDER_404') as $r) {
                if (count($r) < 2) continue;
                $s['status_codes']['404'] = ($s['status_codes']['404'] ?? 0) + (int)$r[1];
            }

            // PAGEREFS: <external_referrer_url> <pages> <hits>  → referrers by host
            foreach (dsog_awstats_section($txt, 'PAGEREFS') as $r) {
                if (count($r) < 2) continue;
                $rp = @parse_url($r[0]);
                $rHost = preg_replace('/^www\./', '', strtolower($rp['host'] ?? ''));
                if (!$rHost || $rHost === $domain || str_ends_with($domain, $rHost) || str_ends_with($rHost, $domain)) continue;
                $cnt = (int)$r[1];
                if (!isset($s['referrers'][$rHost])) $s['referrers'][$rHost] = ['total'=>0, 'urls'=>[]];
                $s['referrers'][$rHost]['total'] += $cnt;
                $path = ($rp['path'] ?? '/') ?: '/';
                if (strlen($path) <= 160 && count($s['referrers'][$rHost]['urls']) < 25) {
                    $s['referrers'][$rHost]['urls'][$path] = ($s['referrers'][$rHost]['urls'][$path] ?? 0) + $cnt;
                }
            }
        }

        // AWStats counts sessions ("visits"), not distinct IPs. Surface visits as
        // the headline figure — the closest analogue to the DO sites' visitors.
        $s['unique_visitors'] = (int)($s['__visits'] ?? 0);
        unset($s['__visits']);

        arsort($s['top_pages']);  $s['top_pages'] = array_slice($s['top_pages'], 0, 20, true);
        uasort($s['referrers'], fn($a, $b) => $b['total'] - $a['total']);
        $s['referrers'] = array_slice($s['referrers'], 0, 40, true);
        foreach ($s['referrers'] as &$rd) { arsort($rd['urls']); $rd['urls'] = array_slice($rd['urls'], 0, 10, true); }
        unset($rd);

        if ($buildSeries) {
            ksort($series);
            $s['series']      = dsog_fill_series($series, $rangeStart, $rangeEnd);
            $s['series_meta'] = ['granularity' => 'day', 'source' => 'awstats'];
        } else {
            $s['series'] = [];
        }
        return $s;
    }
}

// ── Core compute (streaming, memory-safe) ─────────────────────────────────────
// dsog_compute_logs() is generalized over an explicit [start,end] window so the
// live range picker (seek-from-end heuristic) and the monthly archive (forward
// scan of an arbitrary closed month) can share one parser.
if (!function_exists('dsog_compute_logs')) {
    function dsog_compute_logs(string $domain, string $rangeLabel, int $rangeStart, int $rangeEnd, array $opts = []): array {
        $seek         = $opts['seek']          ?? true;
        $maxLines     = $opts['max_lines']     ?? 600000;
        $forwardBreak = $opts['forward_break'] ?? false;

        $logFiles = dsog_find_log_files($domain);

        $s = [
            'domain'        => $domain,
            'range'         => $rangeLabel,
            'computed_at'   => time(),
            'log_files'     => count($logFiles),
            'log_file_list' => array_map('basename', $logFiles),
            'unique_ips'    => [],
            'page_views'    => 0,
            'total_hits'    => 0,
            'bandwidth'     => 0,
            'top_pages'     => [],
            'referrers'     => [],
            'mp3_hits'      => 0,   // de-duped: 1 per IP per file per day
            'mp3_hits_raw'  => 0,   // raw total (bots/Apple repeat-fetches included)
            'mp3_bandwidth' => 0,
            'mp3_files'     => [],  // de-duped counts per filename
            'mp3_seen'      => [],  // temp: ip+day+file dedup set (removed before cache)
            'feed_hits'     => 0,
            'system_hits'   => 0,   // /admin module APIs, cron, probes — excluded from visitor stats
            'system_paths'  => [],  // top system endpoints (for the System Hits panel)
            'bot_hits'      => 0,   // crawlers / iTMS prefetch — excluded from visitor stats
            'status_codes'  => [],
            'lines_parsed'  => 0,
            'error'         => null,
        ];

        if (empty($logFiles)) {
            $s['error'] = 'No access logs found for ' . $domain;
            $s['unique_visitors'] = 0;
            return $s;
        }

        $linesRead   = 0;

        // Daily time-series buckets for the mountain chart. Skipped for 'today'
        // (a single point is not a meaningful curve — the cards cover it).
        $buildSeries = ($rangeLabel !== 'today');
        $series      = [];  // [utc-date] => [visitors,page_views,hits,mp3]
        $seriesIp    = [];  // [utc-date][ip] => true (per-day unique dedup)
        $seriesPage  = [];  // [utc-date][uriKey] => count (per-day top page → spike attribution)
        $seriesMp3   = [];  // [utc-date][fname]  => count (per-day top download)

        foreach ($logFiles as $logFile) {
            if (!is_readable($logFile)) continue;
            $fileSize = @filesize($logFile);
            if ($fileSize < 10) continue;

            $fh = @fopen($logFile, 'r');
            if (!$fh) continue;

            // Seek to an approximate start point (avg ~210 bytes/line) for recent
            // ranges; a month archive scans forward from the file head instead.
            if ($seek) {
                $seekOffset = max(0, $fileSize - ($maxLines * 210));
                if ($seekOffset > 0) {
                    fseek($fh, $seekOffset);
                    fgets($fh); // discard partial first line
                }
            }

            while (!feof($fh) && $linesRead < $maxLines) {
                $line = fgets($fh, 8192);
                if ($line === false) break;
                $line = rtrim($line);
                if ($line === '') continue;

                $p = dsog_parse_line($line);
                if (!$p) continue;
                if ($p['ts'] < $rangeStart) continue;
                if ($p['ts'] > $rangeEnd) {
                    // Logs are chronological — once past the window stop reading
                    // this file (forward scans of a closed month rely on this).
                    if ($forwardBreak) break;
                    continue;
                }

                $linesRead++;
                $uriClean = strtok($p['uri'], '?') ?: '/';
                $isMp3   = (bool)preg_match('/\.mp3$/i', $uriClean);
                $isFeed  = !$isMp3 && (bool)preg_match('/podcast_feed|podcast-feed|\/feed\.php|\/rss\.php|\/feed\/?$/i', $uriClean);
                $isAsset = !$isMp3 && !$isFeed && dsog_is_asset($uriClean);

                // ── (1) System / non-front traffic → own tally, EXCLUDED from visitor stats.
                //    ServerMonitor/api, AgentScheduler/cron, module APIs, the pixel beacon,
                //    health/liveness probes. (Podcast feed under /admin is exempted above.)
                if (!$isMp3 && !$isFeed && !$isAsset && dsog_is_system($uriClean)) {
                    $s['system_hits'] = ($s['system_hits'] ?? 0) + 1;
                    $sysKey = rtrim($uriClean, '/') ?: '/';
                    if (strlen($sysKey) <= 120) $s['system_paths'][$sysKey] = ($s['system_paths'][$sysKey] ?? 0) + 1;
                    continue;
                }
                // ── (2) Bots / crawlers / Apple iTMS catalog+prefetch hammer → own tally, EXCLUDED.
                //    Real podcast listeners use AppleCoreMedia (not iTMS) so they still count.
                if (dsog_is_bot($p['ua'])) {
                    $s['bot_hits'] = ($s['bot_hits'] ?? 0) + 1;
                    continue;
                }

                $s['total_hits']++;
                $s['bandwidth'] += $p['bytes'];
                $s['status_codes'][$p['status']] = ($s['status_codes'][$p['status']] ?? 0) + 1;
                $s['unique_ips'][$p['ip']] = true;

                $bk = $buildSeries ? gmdate('Y-m-d', $p['ts']) : null;
                if ($buildSeries) {
                    if (!isset($series[$bk])) $series[$bk] = ['visitors'=>0,'page_views'=>0,'hits'=>0,'mp3'=>0];
                    $series[$bk]['hits']++;
                    $seriesIp[$bk][$p['ip']] = true;
                }

                // MP3 downloads — count a REAL download only (GET 200/206, ≥ threshold bytes) so
                // HEAD size-checks + tiny range-probes (Apple hammers the newest episode) don't
                // inflate it. De-dupe by IP+day+file; keep the raw total for reference.
                if ($isMp3) {
                    $fname = basename($uriClean);
                    $s['mp3_hits_raw']++;
                    $s['mp3_bandwidth'] += $p['bytes'];
                    $isRealDl = ($p['method'] === 'GET')
                             && ($p['status'] === 200 || $p['status'] === 206)
                             && ($p['bytes'] >= DSOG_MP3_MIN_BYTES);
                    if ($isRealDl) {
                        $dedupKey = $p['ip'] . '|' . $p['day'] . '|' . $fname;
                        if (!isset($s['mp3_seen'][$dedupKey])) {
                            $s['mp3_seen'][$dedupKey] = true;
                            $s['mp3_hits']++;
                            $s['mp3_files'][$fname] = ($s['mp3_files'][$fname] ?? 0) + 1;
                            if ($buildSeries) { $series[$bk]['mp3']++; $seriesMp3[$bk][$fname] = ($seriesMp3[$bk][$fname] ?? 0) + 1; }
                        }
                    }
                    continue;
                }

                // Podcast feed
                if ($isFeed) {
                    $s['feed_hits']++;
                    continue;
                }

                // Static assets / errors
                if (dsog_is_asset($uriClean)) continue;
                if ($p['status'] >= 400) continue;

                // Page view
                $s['page_views']++;
                if ($buildSeries) $series[$bk]['page_views']++;

                $uriKey = rtrim($uriClean, '/') ?: '/';
                if (strlen($uriKey) <= 120) {
                    $s['top_pages'][$uriKey] = ($s['top_pages'][$uriKey] ?? 0) + 1;
                    if ($buildSeries) $seriesPage[$bk][$uriKey] = ($seriesPage[$bk][$uriKey] ?? 0) + 1;
                }

                // Referrers — detailed AWStats-style
                $ref = $p['referrer'];
                if ($ref && $ref !== '-' && $ref !== '') {
                    $rp = @parse_url($ref);
                    $rHost = strtolower($rp['host'] ?? '');
                    $rHost = preg_replace('/^www\./', '', $rHost);
                    // Skip self-referrals
                    if ($rHost && $rHost !== $domain && !str_ends_with($domain, $rHost) && !str_ends_with($rHost, $domain)) {
                        if (!isset($s['referrers'][$rHost])) {
                            $s['referrers'][$rHost] = ['total'=>0, 'urls'=>[]];
                        }
                        $s['referrers'][$rHost]['total']++;
                        $rUrl = ($rp['path'] ?? '/') . (isset($rp['query']) ? '?' . substr($rp['query'], 0, 80) : '');
                        if (strlen($rUrl) <= 160 && count($s['referrers'][$rHost]['urls']) < 25) {
                            $s['referrers'][$rHost]['urls'][$rUrl] = ($s['referrers'][$rHost]['urls'][$rUrl] ?? 0) + 1;
                        }
                    }
                }
            }
            fclose($fh);
        }

        // ── Post-process ──────────────────────────────────────────────────────
        $s['unique_visitors'] = count($s['unique_ips']);
        unset($s['unique_ips']);  // don't cache raw IPs
        unset($s['mp3_seen']);    // don't cache dedup set

        arsort($s['top_pages']);
        $s['top_pages'] = array_slice($s['top_pages'], 0, 20, true);

        uasort($s['referrers'], fn($a, $b) => $b['total'] - $a['total']);
        $s['referrers'] = array_slice($s['referrers'], 0, 40, true);
        foreach ($s['referrers'] as &$rd) {
            arsort($rd['urls']);
            $rd['urls'] = array_slice($rd['urls'], 0, 10, true);
        }
        unset($rd);

        arsort($s['mp3_files']);
        $s['mp3_files'] = array_slice($s['mp3_files'], 0, 15, true);

        if (!empty($s['system_paths'])) { arsort($s['system_paths']); $s['system_paths'] = array_slice($s['system_paths'], 0, 15, true); }

        // ── Daily series → resolve per-day uniques, then zero-fill the range ──
        if ($buildSeries) {
            foreach ($series as $bk => $row) {
                $series[$bk]['visitors'] = count($seriesIp[$bk] ?? []);
                if (!empty($seriesPage[$bk])) { arsort($seriesPage[$bk]); $tp = array_key_first($seriesPage[$bk]); $series[$bk]['top_page'] = $tp; $series[$bk]['top_page_n'] = $seriesPage[$bk][$tp]; }
                if (!empty($seriesMp3[$bk]))  { arsort($seriesMp3[$bk]);  $tm = array_key_first($seriesMp3[$bk]);  $series[$bk]['top_dl']   = $tm; $series[$bk]['top_dl_n']   = $seriesMp3[$bk][$tm]; }
            }
            $s['series']      = dsog_fill_series($series, $rangeStart, $rangeEnd);
            $s['series_meta'] = ['granularity' => 'day'];
        } else {
            $s['series'] = [];
        }

        $s['lines_parsed'] = $linesRead;
        return $s;
    }
}

// ── Core compute dispatcher (live range picker) ───────────────────────────────
if (!function_exists('dsog_compute_stats')) {
    function dsog_compute_stats(string $domain, string $range): array {
        [$rangeStart, $rangeEnd] = dsog_range_bounds($range);

        // Prefer AWStats where it exists (cPanel hosts) — pre-aggregated and
        // authoritative; falls through to raw-log parsing on DO sites.
        $awFiles = dsog_awstats_files_for_range($domain, $rangeStart, $rangeEnd);
        if (!empty($awFiles)) {
            return dsog_compute_stats_awstats($domain, $range, $rangeStart, $rangeEnd, $awFiles);
        }

        // Line budget per range — prevents multi-minute parses on huge logs.
        $lineBudgets = ['today'=>200000, '7d'=>600000, '30d'=>2000000, 'this_month'=>1500000];
        return dsog_compute_logs($domain, $range, $rangeStart, $rangeEnd, [
            'seek'      => true,
            'max_lines' => $lineBudgets[$range] ?? 600000,
        ]);
    }
}

// ── Cache ─────────────────────────────────────────────────────────────────────
if (!function_exists('dsog_cache_path')) {
    function dsog_cache_path(string $domain, string $range): string {
        $dir = SITE_ROOT . '/admin/data/DashboardStatsOG';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $domain);
        return $dir . '/' . $safe . '_' . $range . '.json';
    }
}
if (!function_exists('dsog_get_stats')) {
    function dsog_get_stats(string $domain, string $range): array {
        $path = dsog_cache_path($domain, $range);
        if (is_file($path) && (time() - filemtime($path)) < DSOG_CACHE_TTL) {
            $d = json_decode(file_get_contents($path), true);
            if (is_array($d)) return $d;
        }
        $stats = dsog_compute_stats($domain, $range);
        @file_put_contents($path, json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $stats;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MONTHLY ARCHIVE LAYER
// ──────────────────────────────────────────────────────────────────────────────
// A closed month never changes, so it is computed once from the best source
// (AWStats DB on cPanel, raw logs on DO) and frozen at
// admin/data/DashboardStatsOG/monthly/{YYYY-MM}.json. The current month is
// recomputed on the normal 15-min TTL. DO sites are additionally back-filled from
// the hub StatsCaptainOG history by the one-time seed script — those archive
// files simply pre-exist and are read like any other closed month.
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_monthly_dir')) {
    function dsog_monthly_dir(): string {
        $dir = SITE_ROOT . '/admin/data/DashboardStatsOG/monthly';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }
}
if (!function_exists('dsog_month_bounds')) {
    // [start,end] timestamps for a 'YYYY-MM', bounded in UTC to match the
    // UTC day-keys the series buckets use (dsog_fill_series / gmdate).
    function dsog_month_bounds(string $ym): array {
        $start = strtotime($ym . '-01 00:00:00 UTC');
        $end   = strtotime($ym . '-01 00:00:00 UTC +1 month -1 second');
        return [$start ?: 0, $end ?: 0];
    }
}
if (!function_exists('dsog_month_is_closed')) {
    function dsog_month_is_closed(string $ym): bool {
        return $ym < gmdate('Y-m'); // the current calendar month is still "open"
    }
}
if (!function_exists('dsog_valid_ym')) {
    function dsog_valid_ym(string $ym): bool {
        return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym);
    }
}

// Compute one calendar month from whatever live source the site has.
if (!function_exists('dsog_compute_month')) {
    function dsog_compute_month(string $domain, string $ym): array {
        [$start, $end] = dsog_month_bounds($ym);
        $awFiles = dsog_awstats_files_for_range($domain, $start, $end);
        if (!empty($awFiles)) {
            $s = dsog_compute_stats_awstats($domain, $ym, $start, $end, $awFiles);
        } elseif ($ym === gmdate('Y-m')) {
            // Current month: its data sits at the tail of the (never-rotated) log,
            // so seek from the end — avoids re-reading prior months on every
            // 15-min refresh as the single log grows across the year.
            $s = dsog_compute_logs($domain, $ym, $start, $end, [
                'seek'      => true,
                'max_lines' => 2_000_000,
            ]);
        } else {
            // Closed month: may sit anywhere in the log. Forward-scan from the head
            // and stop once past the window. Runs once, then the result is frozen.
            $s = dsog_compute_logs($domain, $ym, $start, $end, [
                'seek'          => false,
                'max_lines'     => 5_000_000,
                'forward_break' => true,
            ]);
        }
        $s['ym']     = $ym;
        $s['closed'] = dsog_month_is_closed($ym);
        return $s;
    }
}

// Archive-backed month accessor. Closed months are frozen permanently; the
// current month is cached on the standard TTL. A closed month with no data is
// NOT frozen — that would mask a later back-fill seed.
if (!function_exists('dsog_get_month')) {
    function dsog_get_month(string $domain, string $ym): array {
        if (!dsog_valid_ym($ym)) return ['error' => 'Invalid month', 'ym' => $ym];
        $path   = dsog_monthly_dir() . '/' . $ym . '.json';
        $closed = dsog_month_is_closed($ym);

        if (is_file($path)) {
            $d = json_decode((string)file_get_contents($path), true);
            if (is_array($d)) {
                if ($closed) {
                    // Serve a frozen archive only if it was actually finalized as
                    // closed. A file written while the month was still open (its
                    // closed flag false — e.g. the current-month cache the instant
                    // the month rolls over) is recomputed once here so the archived
                    // month is complete and correctly flagged.
                    if (!empty($d['closed'])) return $d;
                } elseif ((time() - filemtime($path)) < DSOG_CACHE_TTL) {
                    return $d; // current month, within TTL
                }
            }
        }

        $s = dsog_compute_month($domain, $ym);
        $hasData = ($s['total_hits'] ?? 0) > 0 || ($s['unique_visitors'] ?? 0) > 0;
        if (!$closed || $hasData) {
            @file_put_contents($path, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return $s;
    }
}

// Months that have (or can have) data, newest first. Union of frozen archives,
// AWStats monthly DBs (cPanel), and the always-live current + previous month.
if (!function_exists('dsog_available_months')) {
    function dsog_available_months(string $domain): array {
        $set = [];
        foreach (glob(dsog_monthly_dir() . '/*.json') ?: [] as $f) {
            if (preg_match('/(\d{4}-\d{2})\.json$/', basename($f), $m)) $set[$m[1]] = true;
        }
        $dir = dsog_awstats_dir();
        if ($dir) {
            $token = dsog_awstats_token($dir, $domain);
            if ($token) {
                foreach (glob($dir . '/awstats??????.' . $token . '.txt') ?: [] as $f) {
                    if (preg_match('/awstats(\d{2})(\d{4})\./', basename($f), $m)) {
                        $set[$m[2] . '-' . $m[1]] = true;
                    }
                }
            }
        }
        $set[gmdate('Y-m')] = true;                                            // current
        $set[gmdate('Y-m', strtotime('first day of last month UTC'))] = true; // previous
        $months = array_keys($set);
        rsort($months);
        return $months;
    }
}
if (!function_exists('dsog_available_years')) {
    function dsog_available_years(string $domain): array {
        $ys = [];
        foreach (dsog_available_months($domain) as $m) $ys[substr($m, 0, 4)] = true;
        $years = array_keys($ys);
        rsort($years);
        return $years;
    }
}

// ── Postage stamp renderer (used by admin rail + shortcode) ───────────────────
if (!function_exists('dsog_render_stamp')) {
    function dsog_render_stamp(array $stats, string $domain, bool $adminStyle = true, string $style = ''): string {
        $uniques = dsog_compact_num($stats['unique_visitors'] ?? 0);
        $mp3     = dsog_compact_num($stats['mp3_hits'] ?? 0);
        $bw      = dsog_fmt_bytes($stats['bandwidth'] ?? 0);
        $hasMp3  = ($stats['mp3_hits'] ?? 0) > 0;

        // Load site logo + name
        $logo   = '';
        $siteName = $domain;
        $sf = SITE_ROOT . '/admin/data/site-settings.json';
        if (is_file($sf)) {
            $sc = json_decode(file_get_contents($sf), true);
            if (!empty($sc['logo_path']) && !empty($sc['enable_logo'])) {
                $logo = '/' . ltrim($sc['logo_path'], '/');
            }
            if (!empty($sc['site_name'])) $siteName = $sc['site_name'];
        }

        // ── Theme palette ─────────────────────────────────────────────────────
        // $style (from the [[vstats style="…"]] widget) overrides the legacy
        // $adminStyle look: 'light' = light card/dark text, 'minimal' = no card
        // (for transparent BackgroundManager pages), '' or 'dark' = the classic
        // translucent-dark card. Legacy callers pass no $style → unchanged.
        if ($style === 'light') {
            $bg = 'rgba(255,255,255,0.9)'; $border = '1px solid rgba(0,0,0,0.12)';
            $cLabel = '#555'; $cDomain = '#2563eb'; $cUniq = '#16a34a'; $cMp3 = '#b45309'; $cBw = '#dc2626';
            $cFoot = '#999'; $cAge = '#333'; $cFootBorder = 'rgba(0,0,0,0.08)';
        } elseif ($style === 'minimal') {
            $bg = 'transparent'; $border = 'none';
            $cLabel = '#aaa'; $cDomain = '#60a5fa'; $cUniq = '#5bff00'; $cMp3 = '#f5ff08'; $cBw = '#f87171';
            $cFoot = '#888'; $cAge = '#f5f5f5'; $cFootBorder = 'rgba(255,255,255,0.08)';
        } else { // dark (default / legacy $adminStyle path)
            $bg = $adminStyle ? 'rgba(0,0,0,0.45)' : 'rgba(0,0,0,0.7)';
            $border = $adminStyle ? '1px solid #333' : '1px solid rgba(255,255,255,0.12)';
            $cLabel = '#888'; $cDomain = '#60a5fa'; $cUniq = '#5bff00'; $cMp3 = '#f5ff08'; $cBw = '#f87171';
            $cFoot = '#777'; $cAge = '#f5f5f5'; $cFootBorder = 'rgba(255,255,255,0.05)';
        }

        $html = '<div class="dsog-stamp dsog-stamp-' . ($style ?: 'dark') . '" style="background:' . $bg . ';border:' . $border . ';border-radius:10px;padding:14px 16px;width:100%;box-sizing:border-box;font-family:system-ui,sans-serif;font-size:13px;">';

        if ($logo) {
            $html .= '<div style="text-align:center;margin-bottom:6px;">';
            $html .= '<a href="/" target="_blank" style="display:inline-block;line-height:0;">';
            $html .= '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars($siteName) . '" style="max-width:min(140px,90%);max-height:60px;object-fit:contain;">';
            $html .= '</a>';
            $html .= '</div>';
        }

        $html .= '<div style="text-align:center;margin-bottom:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">';
        $html .= '<a href="/" target="_blank" style="color:' . $cDomain . ';font-size:.78rem;font-weight:600;text-decoration:none;">' . htmlspecialchars($domain) . '</a>';
        $html .= '</div>';

        $html .= '<div style="display:flex;flex-direction:column;gap:4px;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;">';
        $html .= '<span style="color:' . $cLabel . ';font-size:.72rem;">Uniques:</span>';
        $html .= '<span style="color:' . $cUniq . ';font-weight:700;font-variant-numeric:tabular-nums;">' . $uniques . '</span>';
        $html .= '</div>';

        if ($hasMp3) {
            $html .= '<div style="display:flex;justify-content:space-between;align-items:center;">';
            $html .= '<span style="color:' . $cLabel . ';font-size:.72rem;">MP3 D/Ls:</span>';
            $html .= '<span style="color:' . $cMp3 . ';font-weight:700;font-variant-numeric:tabular-nums;">' . $mp3 . '</span>';
            $html .= '</div>';
        }

        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;">';
        $html .= '<span style="color:' . $cLabel . ';font-size:.72rem;">Bandwidth:</span>';
        $html .= '<span style="color:' . $cBw . ';font-weight:700;font-variant-numeric:tabular-nums;">' . $bw . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        if (!empty($stats['error'])) {
            $html .= '<div style="color:#f87171;font-size:.65rem;margin-top:6px;text-align:center;">' . htmlspecialchars($stats['error']) . '</div>';
        } else {
            $age = '';
            if (!empty($stats['computed_at'])) {
                $mins = (int)round((time() - $stats['computed_at']) / 60);
                $age  = $mins < 1 ? 'just now' : ($mins < 60 ? "{$mins}m ago" : round($mins/60) . 'h ago');
            }
            // Human-friendly period label instead of terse "7D"/"30D".
            $rangeLabels = [
                'today'      => "Today's Visitors",
                '7d'         => 'Most Recent 7 Days',
                '30d'        => 'Most Recent 30 Days',
                'this_month' => 'This Month',
            ];
            $rangeLabel = $rangeLabels[$stats['range'] ?? ''] ?? ucwords(str_replace(['_', '-'], ' ', (string)($stats['range'] ?? '')));
            $html .= '<div style="margin-top:8px;text-align:center;border-top:1px solid ' . $cFootBorder . ';padding-top:6px;">';
            // Period reads at the stat-value size and in the yellow accent — a real
            // detail, not hidden fine print. Timestamp stays muted/secondary.
            $html .= '<span style="color:' . $cMp3 . ';font-weight:700;">' . htmlspecialchars($rangeLabel) . '</span>';
            $html .= '<span style="color:' . $cAge . ';font-size:.72rem;"> &nbsp;·&nbsp; ' . $age . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}

// ── Early action handler (call BEFORE any HTML output) ───────────────────────
// The log-path is now auto-probed (dsog_cpanel_home), so there is no config to
// save. The only remaining action is an on-demand cache bust (?dsog_refresh=1),
// which replaces the old "Save & Reparse" button.
if (!function_exists('dsog_handle_early_actions')) {
    function dsog_handle_early_actions(): void {
        if (!isset($_GET['dsog_refresh'])) return;
        $domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $range  = $_GET['dsog_range'] ?? '7d';
        foreach (['today','7d','30d','this_month'] as $r) {
            $cp = dsog_cache_path($domain, $r);
            if (is_file($cp)) @unlink($cp);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?dsog_range=' . urlencode($range));
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MAIN DASHBOARD PANEL RENDERER
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_panel')) {
function dsog_render_panel(): void {

// Determine domain
$dsogDomain  = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

// Range picker
$dsogRanges  = ['today'=>'Today','7d'=>'Last 7 Days','30d'=>'Last 30 Days','this_month'=>'This Month'];
$dsogRange   = $_GET['dsog_range'] ?? ($_GET['range'] ?? '7d');
if (!isset($dsogRanges[$dsogRange])) $dsogRange = '7d';

// Get stats (cached or computed)
$dsogStats   = dsog_get_stats($dsogDomain, $dsogRange);
$ds          = $dsogStats;
$hasPodcast  = ($ds['mp3_hits'] ?? 0) > 0 || ($ds['feed_hits'] ?? 0) > 0;
$dsAge       = '';
if (!empty($ds['computed_at'])) {
    $m  = (int)round((time() - $ds['computed_at']) / 60);
    $dsAge = $m < 1 ? 'just now' : ($m < 60 ? "{$m}m ago" : round($m/60) . 'h ago');
}
?>

<!-- ── DashboardStatsOG Panel ─────────────────────────────────────────────── -->
<style>
/* ── DashboardStatsOG ────────────────────────────────────────────── */
.dsog-panel{background:linear-gradient(145deg,rgba(10,10,25,0.7),rgba(15,20,40,0.6));border:1px solid rgba(91,255,0,0.15);border-radius:12px;overflow:hidden;margin-bottom:20px;box-shadow:0 4px 30px rgba(0,0,0,.4);}
.dsog-head{display:flex;justify-content:space-between;align-items:center;padding:16px 24px 12px;border-bottom:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.3);}
.dsog-title{font-size:.85rem;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;background:linear-gradient(90deg,#5bff00,#00ffd5);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.dsog-pulse{width:8px;height:8px;border-radius:50%;background:#5bff00;box-shadow:0 0 8px rgba(91,255,0,0.6);animation:dsog-pulse 2s ease-in-out infinite;flex-shrink:0;}
@keyframes dsog-pulse{0%,100%{opacity:1}50%{opacity:.3}}
.dsog-meta{font-size:.68rem;color:#555;}

/* Range header: BIG active-range title for the Overview mountain's current window */
.dsog-range-head{display:flex;justify-content:space-between;align-items:center;gap:12px 18px;flex-wrap:wrap;padding:12px 24px 6px;}
.dsog-range-title{font-size:1.6rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;line-height:1;white-space:nowrap;background:linear-gradient(90deg,#5bff00,#00ffd5);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-shadow:0 0 18px rgba(91,255,0,0.12);}
/* Range selector — rides in the tab row (right-aligned); styled to match the
   top-of-dashboard server-meta pills so it stays legible on every admin theme. */
.dsog-range-bar{display:flex;gap:6px;flex-wrap:wrap;padding:0;margin-left:14px;align-items:center;margin-bottom:6px;font-family:system-ui,sans-serif;}
.dsog-range-btn{display:inline-flex;align-items:center;gap:4px;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.06);color:#aaa;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;letter-spacing:.2px;white-space:nowrap;}
.dsog-range-btn:hover{border-color:rgba(255,255,255,0.18);color:#e8e8e8;}
.dsog-range-btn.active{background:color-mix(in srgb,var(--lum-go,#5bff00) 15%,transparent);border-color:color-mix(in srgb,var(--lum-go,#5bff00) 55%,transparent);color:var(--lum-go,#5bff00);font-weight:700;box-shadow:0 0 10px color-mix(in srgb,var(--lum-go,#5bff00) 20%,transparent);}

.dsog-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:0 24px 18px;}
@media(max-width:900px){.dsog-cards{grid-template-columns:repeat(2,1fr);}}
@media(max-width:480px){.dsog-cards{grid-template-columns:1fr;}}
.dsog-card{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:16px 12px;text-align:center;}
.dsog-card-val{font-size:2rem;font-weight:900;font-variant-numeric:tabular-nums;line-height:1.1;letter-spacing:-1px;}
.dsog-card-lbl{font-size:.63rem;color:#666;margin-top:4px;text-transform:uppercase;letter-spacing:.4px;}
.dsog-c-green .dsog-card-val{color:#5bff00;}
.dsog-c-blue  .dsog-card-val{color:#60a5fa;}
.dsog-c-amber .dsog-card-val{color:#fbbf24;}
.dsog-c-red   .dsog-card-val{color:#f87171;}
.dsog-c-yellow .dsog-card-val{color:#f5ff08;}
.dsog-c-purple .dsog-card-val{color:#a78bfa;}
.dsog-c-teal  .dsog-card-val{color:#34d399;}

.dsog-section{display:flex;align-items:center;gap:8px;padding:12px 24px 8px;}
.dsog-section-title{font-size:.7rem;text-transform:uppercase;letter-spacing:.6px;color:#888;font-weight:700;}
.dsog-section-badge{font-size:.62rem;color:#555;margin-left:auto;}

/* Top pages */
.dsog-pages{padding:0 24px 18px;}
.dsog-page-row{display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,0.03);}
.dsog-page-row:last-child{border-bottom:none;}
.dsog-page-path{font-family:'Courier New',monospace;font-size:.8rem;color:#ccc;min-width:100px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dsog-page-bar-wrap{flex:1;background:rgba(255,255,255,0.04);border-radius:3px;height:6px;}
.dsog-page-bar{height:100%;background:linear-gradient(90deg,#60a5fa,#a78bfa);border-radius:3px;transition:width .5s;}
.dsog-page-cnt{font-size:.78rem;color:#aaa;font-weight:600;font-variant-numeric:tabular-nums;min-width:36px;text-align:right;}

/* AWStats-style referrer table */
.dsog-ref-table{width:100%;border-collapse:collapse;font-size:.82rem;margin:0 0 4px;}
.dsog-ref-table th{text-align:left;color:#666;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:6px 8px;border-bottom:1px solid rgba(255,255,255,0.07);}
.dsog-ref-table td{padding:5px 8px;border-bottom:1px solid rgba(255,255,255,0.03);color:#ccc;vertical-align:top;}
.dsog-ref-domain-row td{background:rgba(255,255,255,0.025);}
.dsog-ref-domain{font-weight:600;color:#e2e8f0;}
.dsog-ref-cat-badge{display:inline-block;font-size:.6rem;padding:1px 5px;border-radius:3px;margin-left:6px;font-weight:500;background:rgba(255,255,255,0.06);}
.dsog-ref-url-row td{padding:3px 8px 3px 28px;color:#94a3b8;font-size:.78rem;}
.dsog-ref-url-path{font-family:'Courier New',monospace;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:340px;display:inline-block;}
.dsog-ref-cnt{font-variant-numeric:tabular-nums;font-weight:600;color:#888;}
.dsog-ref-cnt-main{font-variant-numeric:tabular-nums;font-weight:700;color:#e2e8f0;}
.dsog-ref-bar{height:4px;border-radius:2px;margin-top:3px;}

/* MP3 table */
.dsog-mp3-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.dsog-mp3-table th{text-align:left;color:#666;font-size:.63rem;text-transform:uppercase;letter-spacing:.3px;padding:5px 8px;border-bottom:1px solid rgba(255,255,255,0.07);}
.dsog-mp3-table td{padding:5px 8px;border-bottom:1px solid rgba(255,255,255,0.03);color:#ccc;}
.dsog-mp3-fname{max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dsog-mp3-cnt{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:#f5ff08;}

.dsog-no-logs{padding:32px 24px;text-align:center;color:#555;}
.dsog-foot{padding:12px 24px;border-top:1px solid rgba(255,255,255,0.04);text-align:right;font-size:.6rem;color:#333;text-transform:uppercase;letter-spacing:.8px;}
/* System / bot traffic panel (excluded from visitor stats) */
.dsog-sys{margin:0 24px 14px;border:1px solid rgba(255,255,255,0.07);border-radius:8px;background:rgba(255,255,255,0.015);}
.dsog-sys>summary{cursor:pointer;list-style:none;padding:10px 14px;font-size:.74rem;color:#8a93a6;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.dsog-sys>summary::-webkit-details-marker{display:none;}
.dsog-sys-ico{opacity:.6;}
.dsog-sys-note{color:#5b6472;font-size:.68rem;}
.dsog-sys-tot{margin-left:auto;color:#c9924a;font-weight:700;font-size:.72rem;}
.dsog-sys-body{padding:4px 14px 14px;}
.dsog-sys-h{font-size:.64rem;text-transform:uppercase;letter-spacing:.6px;color:#5b6472;margin:6px 0 4px;font-weight:700;}
.dsog-sys-tbl{width:100%;border-collapse:collapse;font-size:.72rem;}
.dsog-sys-tbl td{padding:3px 6px;border-bottom:1px solid rgba(255,255,255,0.03);}
.dsog-sys-path{color:#9aa4b2;font-family:ui-monospace,monospace;word-break:break-all;}
.dsog-sys-n{text-align:right;color:#c9924a;font-weight:600;white-space:nowrap;}
.dsog-sys-foot{margin-top:8px;font-size:.66rem;color:#4a5262;line-height:1.4;}
.dsog-sys-foot code{color:#8a93a6;}

/* ── Tab strip ─────────────────────────────────────────────────────── */
.dsog-tabs{display:flex;align-items:flex-end;gap:4px;padding:10px 24px 0;border-bottom:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;row-gap:8px;}
.dsog-tab{background:transparent;border:none;border-bottom:2px solid transparent;color:#888;padding:8px 16px;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;cursor:pointer;text-decoration:none;transition:color .2s,border-color .2s;}
.dsog-tab:hover{color:#ccc;}
.dsog-tab.active{color:#5bff00;border-bottom-color:#5bff00;}
#dsog-tabbody{transition:opacity .15s;}

/* ── Monthly Details: year nav + month cards ───────────────────────── */
.dsog-yearnav{display:flex;align-items:center;justify-content:center;gap:18px;padding:18px 24px 4px;}
.dsog-yearnav .yr{font-size:1.15rem;font-weight:800;color:#e2e8f0;font-variant-numeric:tabular-nums;letter-spacing:.5px;min-width:70px;text-align:center;}
.dsog-yearnav a{color:#5bff00;text-decoration:none;font-size:1rem;padding:4px 13px;border:1px solid rgba(91,255,0,0.3);border-radius:6px;background:rgba(91,255,0,0.08);font-weight:700;transition:all .15s;cursor:pointer;}
.dsog-yearnav a:hover{background:rgba(91,255,0,0.18);}
.dsog-yearnav a.disabled{color:#3a3a3a;border-color:rgba(255,255,255,0.05);background:transparent;pointer-events:none;}
.dsog-months{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:14px 24px 22px;}
@media(max-width:900px){.dsog-months{grid-template-columns:repeat(3,1fr);}}
@media(max-width:600px){.dsog-months{grid-template-columns:repeat(2,1fr);}}
.dsog-month-card{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:14px 14px 12px;cursor:pointer;transition:transform .18s,border-color .18s,background .18s;text-align:left;}
.dsog-month-card:hover{border-color:rgba(91,255,0,0.4);background:rgba(91,255,0,0.04);transform:translateY(-2px);}
.dsog-month-card.empty{opacity:.38;cursor:default;}
.dsog-month-card.empty:hover{transform:none;border-color:rgba(255,255,255,0.07);background:rgba(255,255,255,0.02);}
.dsog-mc-month{font-size:1.5rem;letter-spacing:-.3px;color:#e8edf4;font-weight:800;margin-bottom:10px;display:flex;justify-content:space-between;align-items:baseline;gap:8px;}
.dsog-mc-open{font-size:.54rem;color:#fbbf24;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
/* Stat tiles — same big-equal-value treatment as StatsCaptainOG score cards */
.dsog-mc-scores{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin-top:10px;}
.dsog-mc-tile{background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.06);border-radius:7px;padding:7px 9px;display:flex;flex-direction:column;gap:2px;}
.dsog-mc-tile.wide{grid-column:1/-1;}
.dsog-mc-tlabel{font-size:.55rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;line-height:1;}
.dsog-mc-tval{font-size:1.35rem;font-weight:800;line-height:1.15;font-variant-numeric:tabular-nums;letter-spacing:-.5px;}
.dsog-mc-tval.c-green{color:#5bff00;}
.dsog-mc-tval.c-blue{color:#60a5fa;}
.dsog-mc-tval.c-amber{color:#fbbf24;}
.dsog-mc-tval.c-yellow{color:#f5ff08;}
.dsog-mc-tval.c-red{color:#f87171;}
.dsog-mc-empty-val{font-size:1.7rem;font-weight:900;color:#555;margin-top:8px;}
.dsog-mc-bar-wrap{height:5px;border-radius:3px;background:rgba(255,255,255,0.05);margin-top:9px;overflow:hidden;}
.dsog-mc-bar{height:100%;background:linear-gradient(90deg,#5bff00,#00ffd5);border-radius:3px;}

/* ── Month detail: back bar + readable top pages (no tail-chop) ──────── */
.dsog-detail-head{display:flex;align-items:center;gap:14px;padding:16px 24px 6px;flex-wrap:wrap;}
.dsog-back{color:#5bff00;text-decoration:none;font-size:.78rem;font-weight:700;background:rgba(91,255,0,0.08);border:1px solid rgba(91,255,0,0.3);border-radius:6px;padding:5px 12px;cursor:pointer;transition:background .15s;}
.dsog-back:hover{background:rgba(91,255,0,0.18);}
.dsog-detail-title{font-size:1.05rem;font-weight:800;color:#e2e8f0;letter-spacing:.3px;}
.dsog-dpages{padding:0 24px 18px;}
.dsog-dpage-row{display:flex;align-items:center;gap:12px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.03);}
.dsog-dpage-row:last-child{border-bottom:none;}
/* direction:rtl keeps the meaningful leaf (filename) visible and ellipsizes the
   long directory prefix at the LEFT — far better than chopping the tail. */
.dsog-dpage-path{flex:1 1 auto;min-width:0;font-family:'Courier New',monospace;font-size:.8rem;color:#cbd5e1;direction:rtl;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;unicode-bidi:plaintext;}
.dsog-dpage-bar-wrap{flex:0 0 26%;background:rgba(255,255,255,0.04);border-radius:3px;height:6px;}
.dsog-dpage-bar{height:100%;background:linear-gradient(90deg,#60a5fa,#a78bfa);border-radius:3px;}
.dsog-dpage-cnt{flex:0 0 auto;font-size:.8rem;color:#aaa;font-weight:600;font-variant-numeric:tabular-nums;min-width:48px;text-align:right;}
.dsog-frag-empty{padding:30px 24px;text-align:center;color:#666;font-size:.85rem;}

/* ── Scoreboard strip (top of Overview) ────────────────────────────── */
.dsog-scoreboard{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;padding:2px 24px 8px;}
@media(max-width:760px){.dsog-scoreboard{grid-template-columns:repeat(2,1fr);}}
.dsog-sb{background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:11px 10px;text-align:center;}
.dsog-sb-val{font-size:1.45rem;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.1;letter-spacing:-.5px;}
.dsog-sb-val.c-green{color:#5bff00;}
.dsog-sb-val.c-blue{color:#60a5fa;}
.dsog-sb-val.c-amber{color:#fbbf24;}
.dsog-sb-val.c-yellow{color:#f5ff08;}
.dsog-sb-val.c-red{color:#f87171;}
.dsog-sb-lbl{font-size:.57rem;color:#6b7280;margin-top:4px;text-transform:uppercase;letter-spacing:.4px;font-weight:600;}

/* ── Yearly tab: columnar month-by-month table ─────────────────────── */
.dsog-ytable-wrap{padding:8px 24px 24px;overflow-x:auto;}
.dsog-ytable{width:100%;border-collapse:collapse;font-size:.86rem;min-width:520px;}
.dsog-ytable th{text-align:right;color:#64748b;font-size:.61rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,0.1);white-space:nowrap;}
.dsog-ytable th:first-child{text-align:left;}
.dsog-ytable td{text-align:right;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,0.04);font-variant-numeric:tabular-nums;color:#cbd5e1;white-space:nowrap;}
.dsog-ytable td:first-child{text-align:left;font-weight:700;color:#e8edf4;}
.dsog-ytable tbody tr{cursor:pointer;transition:background .15s;}
.dsog-ytable tbody tr:hover{background:rgba(91,255,0,0.05);}
.dsog-ytable tbody tr.empty{cursor:default;opacity:.4;}
.dsog-ytable tbody tr.empty:hover{background:transparent;}
.dsog-yt-vis{color:#5bff00;font-weight:700;}
.dsog-yt-live{font-size:.53rem;color:#fbbf24;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-left:7px;vertical-align:middle;}
.dsog-ytable tfoot td{border-top:2px solid rgba(91,255,0,0.3);border-bottom:none;font-weight:800;color:#f8fafc;padding-top:12px;}
.dsog-ytable tfoot td:first-child{color:#5bff00;text-transform:uppercase;font-size:.68rem;letter-spacing:.5px;}
.dsog-ytable tfoot td .dsog-yt-vis{color:#5bff00;}
</style>

<div class="dsog-panel">

  <!-- Header -->
  <div class="dsog-head">
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="font-size:1.2rem;filter:drop-shadow(0 0 5px rgba(91,255,0,0.4));">&#9784;</span>
      <span class="dsog-title">Site Analytics</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="dsog-meta"><?= $dsAge ? "Updated {$dsAge}" : '' ?></span>
      <span class="dsog-pulse"></span>
    </div>
  </div>

  <!-- Tab strip (Overview = live mountain · Monthly Details = month cards) + range selector, one row -->
  <div class="dsog-tabs">
    <a class="dsog-tab active" data-dsog-nav="overview" data-dsog-range="<?= htmlspecialchars($dsogRange) ?>">Overview</a>
    <a class="dsog-tab" data-dsog-nav="year" data-year="<?= htmlspecialchars(gmdate('Y')) ?>">Yearly</a>
    <a class="dsog-tab" data-dsog-nav="months" data-year="<?= htmlspecialchars(gmdate('Y')) ?>">Monthly Details</a>
    <!-- Range selector, right-aligned next to the tabs (full-page reload; governs the live mountain) -->
    <div class="dsog-range-bar">
      <?php foreach ($dsogRanges as $rk => $rl): ?>
      <a href="?dsog_range=<?= urlencode($rk) ?>" class="dsog-range-btn<?= $rk === $dsogRange ? ' active' : '' ?>"><?= htmlspecialchars($rl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tab body — swapped in place by the parent dashboard's delegated click JS -->
  <div id="dsog-tabbody"><?php dsog_render_overview($dsogDomain, $dsogRange); ?></div>

  <div class="dsog-foot">DashboardStatsOG &nbsp;·&nbsp; <?= htmlspecialchars($dsogDomain) ?></div>
</div>

<?php
} // end dsog_render_panel()
} // end if (!function_exists)

// ══════════════════════════════════════════════════════════════════════════════
// OVERVIEW TAB — the live mountain graph only (cards + tables moved to drill-down)
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_overview')) {
function dsog_render_overview(string $dsogDomain, string $dsogRange): void {
    $dsogRanges = ['today'=>'Today','7d'=>'Last 7 Days','30d'=>'Last 30 Days','this_month'=>'This Month'];
    if (!isset($dsogRanges[$dsogRange])) $dsogRange = '7d';
    $ds         = dsog_get_stats($dsogDomain, $dsogRange);
    $hasPodcast = ($ds['mp3_hits'] ?? 0) > 0 || ($ds['feed_hits'] ?? 0) > 0;
?>
  <!-- Big active-range title — the Overview mountain's current window (selector now lives in the tab row) -->
  <div class="dsog-range-head">
    <div class="dsog-range-title"><?= htmlspecialchars(strtoupper($dsogRanges[$dsogRange])) ?></div>
    <?php if (!empty($ds['error'])): ?>
    <span style="color:#f87171;font-size:.72rem;margin-left:8px;align-self:center;"><?= htmlspecialchars($ds['error']) ?></span>
    <?php endif; ?>
  </div>

  <?php $hasScoreData = ($ds['unique_visitors'] ?? 0) > 0 || ($ds['total_hits'] ?? 0) > 0; ?>
  <?php if ($hasScoreData): ?>
  <!-- Scoreboard — headline numbers for the selected range (compact; mountain stays the star) -->
  <div class="dsog-scoreboard">
    <div class="dsog-sb"><div class="dsog-sb-val c-green"><?= dsog_compact_num($ds['unique_visitors'] ?? 0) ?></div><div class="dsog-sb-lbl">Visitors</div></div>
    <div class="dsog-sb"><div class="dsog-sb-val c-blue"><?= dsog_compact_num($ds['page_views'] ?? 0) ?></div><div class="dsog-sb-lbl">Page Views</div></div>
    <div class="dsog-sb"><div class="dsog-sb-val c-amber"><?= dsog_compact_num($ds['total_hits'] ?? 0) ?></div><div class="dsog-sb-lbl">Total Hits</div></div>
    <div class="dsog-sb"><div class="dsog-sb-val c-yellow"><?= dsog_compact_num($ds['mp3_hits'] ?? 0) ?></div><div class="dsog-sb-lbl">MP3 D/Ls</div></div>
    <div class="dsog-sb"><div class="dsog-sb-val c-red"><?= dsog_fmt_bytes($ds['bandwidth'] ?? 0) ?></div><div class="dsog-sb-lbl">Bandwidth</div></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($ds['series']) && count($ds['series']) >= 2): ?>
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#9968;</span>
    <span class="dsog-section-title">Traffic Over Time</span>
    <span class="dsog-section-badge"><?= count($ds['series']) ?> days &middot; <?= htmlspecialchars($dsogRanges[$dsogRange]) ?></span>
  </div>
  <div style="padding:4px 24px 20px;">
    <div style="position:relative;height:240px;">
      <canvas class="lum-mountain"
        data-series='<?= htmlspecialchars(json_encode($ds['series'], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
        data-has-mp3="<?= $hasPodcast ? '1' : '0' ?>"></canvas>
    </div>
  </div>
  <div style="padding:0 24px 18px;text-align:center;">
    <a class="dsog-back" data-dsog-nav="months" data-year="<?= htmlspecialchars(gmdate('Y')) ?>" style="display:inline-block;">&#128197; Browse Monthly Details &rsaquo;</a>
  </div>
  <?php elseif (($ds['unique_visitors'] ?? 0) > 0 || ($ds['total_hits'] ?? 0) > 0): ?>
  <!-- Have data but too few points for a curve (e.g. 'today') — point at months -->
  <div class="dsog-no-logs">
    <div style="color:#888;"><?= dsog_compact_num($ds['unique_visitors'] ?? 0) ?> visitors in this range.</div>
    <div style="font-size:.78rem;color:#666;margin-top:8px;">A trend needs at least two days — try a wider range, or open
      <a class="dsog-back" data-dsog-nav="months" data-year="<?= htmlspecialchars(gmdate('Y')) ?>" style="display:inline-block;margin-left:4px;">Monthly Details</a>.</div>
  </div>
  <?php else: ?>
  <!-- No data state -->
  <div class="dsog-no-logs">
    <div style="font-size:2rem;margin-bottom:10px;opacity:.2;">&#128202;</div>
    <?php if (!empty($ds['error'])): ?>
    <div style="color:#f87171;margin-bottom:6px;"><?= htmlspecialchars($ds['error']) ?></div>
    <div style="font-size:.75rem;color:#555;">Expected logs in: <?= htmlspecialchars(DSOG_LOG_ROOT . '/' . $dsogDomain . '/') ?></div>
    <?php else: ?>
    <div style="color:#888;">No traffic data found for this range.</div>
    <div style="font-size:.75rem;color:#555;margin-top:4px;">Check back after some visits have been logged.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
  // ── System / bot traffic — surfaced separately, EXCLUDED from the visitor stats above.
  //    A spike here flags an over-eager probe/monitor or crawler hammer, not real traffic.
  $sysHits = (int)($ds['system_hits'] ?? 0); $botHits = (int)($ds['bot_hits'] ?? 0);
  if ($sysHits > 0 || $botHits > 0):
  ?>
  <details class="dsog-sys">
    <summary>
      <span class="dsog-sys-ico">&#9881;</span>
      System &amp; bot traffic <span class="dsog-sys-note">(excluded from visitor stats)</span>
      <span class="dsog-sys-tot"><?= dsog_compact_num($sysHits) ?> system &middot; <?= dsog_compact_num($botHits) ?> bot/crawler</span>
    </summary>
    <div class="dsog-sys-body">
      <?php if (!empty($ds['system_paths'])): ?>
      <div class="dsog-sys-h">Top system endpoints</div>
      <table class="dsog-sys-tbl">
        <?php foreach ($ds['system_paths'] as $path => $n): ?>
        <tr><td class="dsog-sys-path"><?= htmlspecialchars((string)$path) ?></td><td class="dsog-sys-n"><?= dsog_compact_num((int)$n) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <div class="dsog-sys-foot">Bots &amp; crawlers (incl. Apple <code>iTMS</code> newest-episode prefetch) are counted here, not as visitors; mp3 downloads count only real GETs (HEAD/range-probes ignored).</div>
    </div>
  </details>
  <?php endif; ?>

  <?php dsog_render_data_source($ds, $dsogDomain, $dsogRange); ?>
<?php
}
}

// ══════════════════════════════════════════════════════════════════════════════
// DATA SOURCE DIAGNOSTIC (shared by overview + month detail)
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_data_source')) {
function dsog_render_data_source(array $ds, string $dsogDomain, string $dsogRange): void {
  $dsogSource   = $ds['source'] ?? 'logs';
  $dsogHome     = function_exists('dsog_cpanel_home') ? dsog_cpanel_home() : null;
  $dsogAwDir    = function_exists('dsog_awstats_dir') ? dsog_awstats_dir() : null;
  $dsogDetected = ($dsogSource === 'awstats') ? ($ds['log_file_list'] ?? []) : dsog_find_log_files($dsogDomain);
?>
  <div style="padding:0 24px 16px;">
    <details style="font-size:.75rem;">
      <summary style="cursor:pointer;color:#555;padding:6px 0;user-select:none;list-style:none;">
        <span style="font-size:.65rem;text-transform:uppercase;letter-spacing:.5px;font-weight:600;">&#9881; Data Source</span>
        <?php if (!empty($dsogDetected)): ?>
        <span style="color:#34d399;margin-left:8px;">&#10003; <?= $dsogSource === 'awstats' ? 'AWStats' : 'access logs' ?> &middot; <?= count($dsogDetected) ?> file(s)</span>
        <?php else: ?>
        <span style="color:#f87171;margin-left:8px;">&#9888; No data source found</span>
        <?php endif; ?>
      </summary>
      <div style="margin-top:10px;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:14px;line-height:1.7;">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:.74rem;">
          <span style="color:#666;">Source</span>
          <span style="color:#e2e8f0;"><?= $dsogSource === 'awstats' ? 'AWStats pre-aggregated DB' : 'Raw Apache access logs' ?></span>
          <?php if ($dsogHome): ?>
          <span style="color:#666;">Account home</span>
          <span style="font-family:'Courier New',monospace;color:#94a3b8;"><?= htmlspecialchars($dsogHome) ?> <span style="color:#34d399;">(probed)</span></span>
          <?php endif; ?>
          <?php if ($dsogAwDir): ?>
          <span style="color:#666;">AWStats dir</span>
          <span style="font-family:'Courier New',monospace;color:#94a3b8;"><?= htmlspecialchars($dsogAwDir) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($dsogDetected)): ?>
        <div style="margin-top:10px;">
          <div style="font-size:.65rem;color:#666;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;"><?= $dsogSource === 'awstats' ? 'AWStats files' : 'Detected log files' ?></div>
          <?php foreach ($dsogDetected as $lf): ?>
          <div style="font-family:'Courier New',monospace;font-size:.72rem;color:#94a3b8;padding:2px 0;"><?= htmlspecialchars($lf) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:12px;display:flex;gap:12px;align-items:center;">
          <a href="?dsog_refresh=1&dsog_range=<?= urlencode($dsogRange) ?>" style="background:rgba(91,255,0,0.15);border:1px solid rgba(91,255,0,0.4);color:#5bff00;padding:5px 14px;border-radius:5px;font-size:.72rem;font-weight:600;text-decoration:none;">&#8635; Refresh now</a>
          <span style="font-size:.68rem;color:#555;">Account home is detected automatically &mdash; no manual configuration needed.</span>
        </div>
      </div>
    </details>
  </div>
<?php
}
}

// ══════════════════════════════════════════════════════════════════════════════
// MONTHLY DETAILS TAB — one card per month for a year, with year navigation
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_months')) {
function dsog_render_months(string $domain, string $year = ''): void {
    $years   = dsog_available_years($domain);
    $curYear = (int)gmdate('Y');
    $curYm   = gmdate('Y-m');
    if (!preg_match('/^\d{4}$/', $year)) $year = (string)($years[0] ?? $curYear);
    $yInt = (int)$year;

    // Year-nav bounds from the available set (fallback: current year only).
    $minYear = $years ? (int)end($years) : $curYear;
    $maxYear = $years ? (int)$years[0]   : $curYear;
    $prevOk  = $yInt - 1 >= $minYear;
    $nextOk  = $yInt + 1 <= $maxYear && $yInt + 1 <= $curYear;

    // Compute each month of the year (skip future months in the current year).
    $months     = [];
    $maxVal     = 1;
    $anyData    = false;
    $yearHasMp3 = false;
    for ($mo = 1; $mo <= 12; $mo++) {
        $ym = sprintf('%04d-%02d', $yInt, $mo);
        if ($ym > $curYm) continue; // not yet happened
        $m   = dsog_get_month($domain, $ym);
        $val = (int)($m['unique_visitors'] ?? 0);
        $mp3 = (int)($m['mp3_hits'] ?? 0);
        if ($val > 0) $anyData = true;
        if ($mp3 > 0) $yearHasMp3 = true;
        if ($val > $maxVal) $maxVal = $val;
        $months[] = [
            'ym'    => $ym,
            'label' => date('F', mktime(0, 0, 0, $mo, 1)),
            'short' => date('M', mktime(0, 0, 0, $mo, 1)),
            'val'   => $val,
            'pv'    => (int)($m['page_views'] ?? 0),
            'hits'  => (int)($m['total_hits'] ?? 0),
            'bw'    => (int)($m['bandwidth'] ?? 0),
            'mp3'   => $mp3,
            'open'  => ($ym === $curYm),
        ];
    }
?>
  <div class="dsog-yearnav">
    <a class="<?= $prevOk ? '' : 'disabled' ?>" data-dsog-nav="months" data-year="<?= $yInt - 1 ?>" title="Previous year">&lsaquo;</a>
    <span class="yr"><?= $yInt ?></span>
    <a class="<?= $nextOk ? '' : 'disabled' ?>" data-dsog-nav="months" data-year="<?= $yInt + 1 ?>" title="Next year">&rsaquo;</a>
  </div>

  <?php if (empty($months)): ?>
  <div class="dsog-frag-empty">No months available for <?= $yInt ?>.</div>
  <?php else: ?>
  <div class="dsog-months">
    <?php foreach ($months as $m):
      $pct   = $maxVal > 0 ? max(3, round($m['val'] / $maxVal * 100)) : 0;
      $empty = $m['val'] === 0 && !$m['open'];
    ?>
    <div class="dsog-month-card<?= $empty ? ' empty' : '' ?>"<?= $empty ? '' : ' data-dsog-nav="month" data-ym="' . $m['ym'] . '"' ?>>
      <div class="dsog-mc-month">
        <span><?= htmlspecialchars($m['label']) ?></span>
        <?php if ($m['open']): ?><span class="dsog-mc-open">&bull; live</span><?php endif; ?>
      </div>
      <?php if ($empty): ?>
      <div class="dsog-mc-empty-val">&mdash;</div>
      <?php else: ?>
      <div class="dsog-mc-scores">
        <div class="dsog-mc-tile"><span class="dsog-mc-tlabel">Visitors</span><span class="dsog-mc-tval c-green"><?= dsog_compact_num($m['val']) ?></span></div>
        <div class="dsog-mc-tile"><span class="dsog-mc-tlabel">Page Views</span><span class="dsog-mc-tval c-blue"><?= dsog_compact_num($m['pv']) ?></span></div>
        <div class="dsog-mc-tile"><span class="dsog-mc-tlabel">Total Hits</span><span class="dsog-mc-tval c-amber"><?= dsog_compact_num($m['hits']) ?></span></div>
        <?php if ($yearHasMp3): ?>
        <div class="dsog-mc-tile"><span class="dsog-mc-tlabel">MP3 D/Ls</span><span class="dsog-mc-tval c-yellow"><?= dsog_compact_num($m['mp3']) ?></span></div>
        <?php endif; ?>
        <div class="dsog-mc-tile<?= $yearHasMp3 ? ' wide' : '' ?>"><span class="dsog-mc-tlabel">Bandwidth</span><span class="dsog-mc-tval c-red"><?= dsog_fmt_bytes($m['bw']) ?></span></div>
      </div>
      <?php endif; ?>
      <div class="dsog-mc-bar-wrap" style="margin-top:10px;"><div class="dsog-mc-bar" style="width:<?= $empty ? 0 : $pct ?>%;"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$anyData): ?>
  <div class="dsog-frag-empty" style="padding-top:0;">No traffic recorded for <?= $yInt ?> yet.<br><span style="font-size:.74rem;color:#555;">Historical months back-fill from server analytics; recent months build as traffic is logged.</span></div>
  <?php endif; ?>
  <?php endif; ?>
<?php
}
}

// ══════════════════════════════════════════════════════════════════════════════
// YEARLY TAB — columnar month-by-month table (Visitors/Page Views/Hits/MP3/BW)
// Current year = year-to-date; previous years navigable. Rows drill into detail.
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_year')) {
function dsog_render_year(string $domain, string $year = ''): void {
    $years   = dsog_available_years($domain);
    $curYear = (int)gmdate('Y');
    $curYm   = gmdate('Y-m');
    if (!preg_match('/^\d{4}$/', $year)) $year = (string)($years[0] ?? $curYear);
    $yInt = (int)$year;

    // Year-nav bounds from the available set (fallback: current year only).
    $minYear = $years ? (int)end($years) : $curYear;
    $maxYear = $years ? (int)$years[0]   : $curYear;
    $prevOk  = $yInt - 1 >= $minYear;
    $nextOk  = $yInt + 1 <= $maxYear && $yInt + 1 <= $curYear;
    $isCur   = ($yInt === $curYear);

    // Build one row per elapsed month; accumulate the year total.
    $rows    = [];
    $tot     = ['vis' => 0, 'pv' => 0, 'hits' => 0, 'mp3' => 0, 'bw' => 0];
    $anyData = false;
    for ($mo = 1; $mo <= 12; $mo++) {
        $ym = sprintf('%04d-%02d', $yInt, $mo);
        if ($ym > $curYm) continue; // not yet happened
        $m    = dsog_get_month($domain, $ym);
        $vis  = (int)($m['unique_visitors'] ?? 0);
        $pv   = (int)($m['page_views'] ?? 0);
        $hits = (int)($m['total_hits'] ?? 0);
        $mp3  = (int)($m['mp3_hits'] ?? 0);
        $bw   = (int)($m['bandwidth'] ?? 0);
        if ($vis > 0 || $hits > 0) $anyData = true;
        $tot['vis'] += $vis; $tot['pv'] += $pv; $tot['hits'] += $hits; $tot['mp3'] += $mp3; $tot['bw'] += $bw;
        $rows[] = [
            'ym'    => $ym,
            'label' => date('F', mktime(0, 0, 0, $mo, 1)),
            'vis'   => $vis, 'pv' => $pv, 'hits' => $hits, 'mp3' => $mp3, 'bw' => $bw,
            'open'  => ($ym === $curYm),
            'empty' => ($vis === 0 && $hits === 0 && $ym !== $curYm),
        ];
    }
?>
  <div class="dsog-yearnav">
    <a class="<?= $prevOk ? '' : 'disabled' ?>" data-dsog-nav="year" data-year="<?= $yInt - 1 ?>" title="Previous year">&lsaquo;</a>
    <span class="yr"><?= $yInt ?><?= $isCur ? ' <span style="font-size:.6rem;color:#fbbf24;font-weight:700;letter-spacing:.5px;vertical-align:middle;">YTD</span>' : '' ?></span>
    <a class="<?= $nextOk ? '' : 'disabled' ?>" data-dsog-nav="year" data-year="<?= $yInt + 1 ?>" title="Next year">&rsaquo;</a>
  </div>

  <?php if (empty($rows)): ?>
  <div class="dsog-frag-empty">No months available for <?= $yInt ?>.</div>
  <?php else: ?>
  <div class="dsog-ytable-wrap">
    <table class="dsog-ytable">
      <thead>
        <tr>
          <th>Month</th>
          <th>Visitors</th>
          <th>Page Views</th>
          <th>Total Hits</th>
          <th>MP3 D/Ls</th>
          <th>Bandwidth</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['empty'] ? 'empty' : '' ?>"<?= $r['empty'] ? '' : ' data-dsog-nav="month" data-ym="' . $r['ym'] . '" title="Open ' . htmlspecialchars($r['label']) . ' detail"' ?>>
          <td><?= htmlspecialchars($r['label']) ?><?php if ($r['open']): ?><span class="dsog-yt-live">&bull; live</span><?php endif; ?></td>
          <?php if ($r['empty']): ?>
          <td colspan="5" style="text-align:center;color:#475569;">&mdash;</td>
          <?php else: ?>
          <td><span class="dsog-yt-vis"><?= number_format($r['vis']) ?></span></td>
          <td><?= number_format($r['pv']) ?></td>
          <td><?= number_format($r['hits']) ?></td>
          <td><?= number_format($r['mp3']) ?></td>
          <td><?= dsog_fmt_bytes($r['bw']) ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><?= $isCur ? 'YTD Total' : 'Year Total' ?></td>
          <td><span class="dsog-yt-vis"><?= number_format($tot['vis']) ?></span></td>
          <td><?= number_format($tot['pv']) ?></td>
          <td><?= number_format($tot['hits']) ?></td>
          <td><?= number_format($tot['mp3']) ?></td>
          <td><?= dsog_fmt_bytes($tot['bw']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php if (!$anyData): ?>
  <div class="dsog-frag-empty" style="padding-top:0;">No traffic recorded for <?= $yInt ?> yet.<br><span style="font-size:.74rem;color:#555;">Historical months back-fill from server analytics; recent months build as traffic is logged.</span></div>
  <?php endif; ?>
  <?php endif; ?>
<?php
}
}

// ══════════════════════════════════════════════════════════════════════════════
// MONTH DETAIL — drill-down: month mountain + cards + readable tables
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_month_detail')) {
function dsog_render_month_detail(string $domain, string $ym): void {
    if (!dsog_valid_ym($ym)) { echo '<div class="dsog-frag-empty">Invalid month.</div>'; return; }
    $ds         = dsog_get_month($domain, $ym);
    $year       = substr($ym, 0, 4);
    $title      = date('F Y', strtotime($ym . '-01'));
    $hasPodcast = ($ds['mp3_hits'] ?? 0) > 0 || ($ds['feed_hits'] ?? 0) > 0;
?>
  <div class="dsog-detail-head">
    <a class="dsog-back" data-dsog-nav="months" data-year="<?= htmlspecialchars($year) ?>">&lsaquo; <?= htmlspecialchars($year) ?></a>
    <span class="dsog-detail-title"><?= htmlspecialchars($title) ?><?= empty($ds['closed']) ? ' <span style="font-size:.6rem;color:#fbbf24;vertical-align:middle;">(in progress)</span>' : '' ?></span>
  </div>

  <?php if (($ds['unique_visitors'] ?? 0) > 0 || ($ds['total_hits'] ?? 0) > 0): ?>

  <?php if (!empty($ds['series']) && count($ds['series']) >= 2): ?>
  <div style="padding:6px 24px 18px;">
    <div style="position:relative;height:220px;">
      <canvas class="lum-mountain"
        data-series='<?= htmlspecialchars(json_encode($ds['series'], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
        data-has-mp3="<?= $hasPodcast ? '1' : '0' ?>"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <?php dsog_render_detail_body($ds); ?>

  <?php else: ?>
  <div class="dsog-frag-empty">No traffic recorded for <?= htmlspecialchars($title) ?>.</div>
  <?php endif; ?>
<?php
}
}

// ══════════════════════════════════════════════════════════════════════════════
// SHARED DETAIL BODY — traffic cards, podcast cards, top pages, referrers, mp3
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dsog_render_detail_body')) {
function dsog_render_detail_body(array $ds): void {
    $hasPodcast = ($ds['mp3_hits'] ?? 0) > 0 || ($ds['feed_hits'] ?? 0) > 0;
?>
  <!-- Traffic cards -->
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#128202;</span>
    <span class="dsog-section-title">Traffic</span>
    <?php if (!empty($ds['lines_parsed'])): ?>
    <span class="dsog-section-badge"><?= number_format($ds['lines_parsed']) ?> log lines parsed</span>
    <?php endif; ?>
  </div>
  <div class="dsog-cards">
    <div class="dsog-card dsog-c-green">
      <div class="dsog-card-val"><?= dsog_compact_num($ds['unique_visitors'] ?? 0) ?></div>
      <div class="dsog-card-lbl">Unique Visitors</div>
    </div>
    <div class="dsog-card dsog-c-blue">
      <div class="dsog-card-val"><?= dsog_compact_num($ds['page_views'] ?? 0) ?></div>
      <div class="dsog-card-lbl">Page Views</div>
    </div>
    <div class="dsog-card dsog-c-amber">
      <div class="dsog-card-val"><?= dsog_compact_num($ds['total_hits'] ?? 0) ?></div>
      <div class="dsog-card-lbl">Total Hits</div>
    </div>
    <div class="dsog-card dsog-c-red">
      <div class="dsog-card-val"><?= dsog_fmt_bytes($ds['bandwidth'] ?? 0) ?></div>
      <div class="dsog-card-lbl">Bandwidth</div>
    </div>
  </div>

  <?php if ($hasPodcast): ?>
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#127911;</span>
    <span class="dsog-section-title">Podcast</span>
  </div>
  <div class="dsog-cards" style="grid-template-columns:repeat(3,1fr);">
    <div class="dsog-card dsog-c-yellow">
      <div class="dsog-card-val"><?= dsog_compact_num($ds['mp3_hits'] ?? 0) ?></div>
      <div class="dsog-card-lbl">MP3 Downloads
        <?php if (($ds['mp3_hits_raw'] ?? 0) > ($ds['mp3_hits'] ?? 0)): ?>
        <span style="font-size:.55rem;color:#888;display:block;margin-top:2px;" title="Raw hits before de-duplication"><?= dsog_compact_num($ds['mp3_hits_raw']) ?> raw</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="dsog-card dsog-c-amber">
      <div class="dsog-card-val"><?= dsog_compact_num($ds['feed_hits'] ?? 0) ?></div>
      <div class="dsog-card-lbl">Feed Hits</div>
    </div>
    <div class="dsog-card dsog-c-purple">
      <div class="dsog-card-val"><?= dsog_fmt_bytes($ds['mp3_bandwidth'] ?? 0) ?></div>
      <div class="dsog-card-lbl">MP3 Bandwidth</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Top Pages (full width, readable — directory ellipsizes at the left) -->
  <?php if (!empty($ds['top_pages'])): ?>
  <?php $tpMax = max(array_values($ds['top_pages'])); ?>
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#128196;</span>
    <span class="dsog-section-title">Top Pages</span>
    <span class="dsog-section-badge"><?= count($ds['top_pages']) ?> paths</span>
  </div>
  <div class="dsog-dpages">
    <?php foreach (array_slice($ds['top_pages'], 0, 25, true) as $path => $cnt): ?>
    <?php $pct = $tpMax > 0 ? round(($cnt / $tpMax) * 100) : 0; ?>
    <div class="dsog-dpage-row">
      <div class="dsog-dpage-path" title="<?= htmlspecialchars($path) ?>"><?= htmlspecialchars($path) ?></div>
      <div class="dsog-dpage-bar-wrap"><div class="dsog-dpage-bar" style="width:<?= max(3, $pct) ?>%;"></div></div>
      <div class="dsog-dpage-cnt"><?= number_format($cnt) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- AWStats-style Referrer Log -->
  <?php if (!empty($ds['referrers'])): ?>
  <?php $refMax = max(array_column(array_values($ds['referrers']), 'total') ?: [1]); ?>
  <?php $refTotal = array_sum(array_column(array_values($ds['referrers']), 'total')); ?>
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#128279;</span>
    <span class="dsog-section-title">Referrer Log</span>
    <span class="dsog-section-badge"><?= number_format($refTotal) ?> referrals from <?= count($ds['referrers']) ?> domains</span>
  </div>
  <div style="padding:0 24px 20px;overflow-x:auto;">
    <table class="dsog-ref-table">
      <thead>
        <tr>
          <th style="width:44%;">Origin / URL</th>
          <th style="width:10%;text-align:right;">Hits</th>
          <th style="width:10%;text-align:right;">%</th>
          <th style="width:36%;">Bar</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ds['referrers'] as $refHost => $refData): ?>
      <?php
        $rTotal = $refData['total'];
        $rPct   = $refTotal > 0 ? round(($rTotal / $refTotal) * 100, 1) : 0;
        $rWidth = $refMax > 0  ? round(($rTotal / $refMax) * 100) : 0;
        $rCat   = dsog_ref_category($refHost);
      ?>
      <tr class="dsog-ref-domain-row">
        <td>
          <span class="dsog-ref-domain"><?= htmlspecialchars($refHost) ?></span>
          <span class="dsog-ref-cat-badge" style="color:<?= $rCat['color'] ?>;"><?= $rCat['icon'] ?></span>
        </td>
        <td style="text-align:right;"><span class="dsog-ref-cnt-main"><?= number_format($rTotal) ?></span></td>
        <td style="text-align:right;color:#666;"><?= $rPct ?>%</td>
        <td><div class="dsog-ref-bar" style="width:<?= max(3, $rWidth) ?>%;background:<?= $rCat['color'] ?>;opacity:.6;"></div></td>
      </tr>
      <?php foreach ($refData['urls'] as $rUrl => $rUCnt): ?>
      <tr class="dsog-ref-url-row">
        <td colspan="2">
          <span class="dsog-ref-url-path" title="<?= htmlspecialchars($rUrl) ?>"><?= htmlspecialchars($rUrl) ?></span>
        </td>
        <td style="text-align:right;"><span class="dsog-ref-cnt"><?= number_format($rUCnt) ?></span></td>
        <td></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- MP3 File Breakdown -->
  <?php if (!empty($ds['mp3_files'])): ?>
  <div class="dsog-section">
    <span style="font-size:.9rem;opacity:.4;">&#127925;</span>
    <span class="dsog-section-title">MP3 Downloads</span>
  </div>
  <div style="padding:0 24px 18px;overflow-x:auto;">
    <table class="dsog-mp3-table">
      <thead><tr><th>File</th><th style="text-align:right;">Hits</th></tr></thead>
      <tbody>
      <?php foreach ($ds['mp3_files'] as $fname => $fcnt): ?>
      <tr>
        <td class="dsog-mp3-fname" title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars(preg_replace('/\.mp3$/i', '', $fname)) ?></td>
        <td class="dsog-mp3-cnt"><?= number_format($fcnt) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php
}
}
