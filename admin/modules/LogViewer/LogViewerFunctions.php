<?php
/**
 * LogViewer — Functions Library
 *
 * Log discovery, parsing, threat detection, and caching for single-site log analysis.
 * Borrows proven patterns from StatsCaptain's log_handler and ServerSentinel's SecurityScanner.
 *
 * @package  LuminalCMS
 * @module   LogViewer
 * @version  1.0.0
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  Config helpers
// ---------------------------------------------------------------------------

function lv_data_dir(): string
{
    return dirname(__DIR__, 2) . '/data/LogViewer';
}

function lv_load_config(): array
{
    $path = lv_data_dir() . '/config.json';
    if (!file_exists($path)) return lv_default_config();
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? array_merge(lv_default_config(), $data) : lv_default_config();
}

function lv_save_config(array $cfg): bool
{
    $path = lv_data_dir() . '/config.json';
    $dir  = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok !== false) @chmod($path, 0664);
    return $ok !== false;
}

function lv_default_config(): array
{
    // Auto-detect log paths from Apache vhost config for this site
    $detected = lv_detect_logs_from_apache();

    return [
        'access_logs'    => $detected['access'],
        'error_logs'     => $detected['error'],
        'cache_ttl'      => 300,      // 5 min dashboard cache
        'dns_cache_ttl'  => 86400,    // 24h DNS cache
        'lines_per_page' => 200,
    ];
}

// ---------------------------------------------------------------------------
//  Apache vhost config parser — extract log paths for THIS site
// ---------------------------------------------------------------------------

function lv_detect_logs_from_apache(): array
{
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $result = ['access' => [], 'error' => []];
    $seen = [];

    // Scan Apache sites-enabled for vhost configs matching this domain
    $sitesDir = '/etc/apache2/sites-enabled';
    if (!is_dir($sitesDir)) return $result;

    foreach (glob($sitesDir . '/*.conf') as $confFile) {
        $content = @file_get_contents($confFile);
        if ($content === false) continue;

        // Check if this vhost config is for our domain
        if (!preg_match('/ServerName\s+' . preg_quote($host, '/') . '\b/i', $content)) continue;

        // Extract CustomLog paths
        if (preg_match_all('/^\s*CustomLog\s+(\S+)/mi', $content, $m)) {
            foreach ($m[1] as $path) {
                if (!isset($seen[$path]) && is_file($path) && is_readable($path)) {
                    $seen[$path] = true;
                    $result['access'][] = $path;
                }
            }
        }

        // Extract ErrorLog paths
        if (preg_match_all('/^\s*ErrorLog\s+(\S+)/mi', $content, $m)) {
            foreach ($m[1] as $path) {
                if (!isset($seen[$path]) && is_file($path) && is_readable($path)) {
                    $seen[$path] = true;
                    $result['error'][] = $path;
                }
            }
        }
    }

    // Fallback: scan /var/log/vhosts/{domain}/ if Apache config parsing found nothing
    if (empty($result['access']) && empty($result['error'])) {
        $logDir = '/var/log/vhosts/' . $host;
        if (is_dir($logDir)) {
            foreach ((array)@scandir($logDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                $full = $logDir . '/' . $f;
                if (!is_file($full) || !is_readable($full)) continue;
                if (preg_match('/\.(gz|bz2|zip|tar|xz|zst)$/i', $f)) continue;
                if (stripos($f, 'error') !== false) {
                    $result['error'][] = $full;
                } elseif (stripos($f, 'access') !== false || preg_match('/\.log$/i', $f)) {
                    $result['access'][] = $full;
                }
            }
        }
    }

    return $result;
}

// ---------------------------------------------------------------------------
//  Log discovery — return access & error logs with metadata
// ---------------------------------------------------------------------------

function lv_discover_logs(): array
{
    $cfg  = lv_load_config();
    $logs = ['access' => [], 'error' => []];

    // Use explicitly configured paths (from Apache detection or saved config)
    $accessPaths = $cfg['access_logs'] ?? [];
    $errorPaths  = $cfg['error_logs'] ?? [];

    foreach ($accessPaths as $path) {
        if (is_file($path) && is_readable($path)) {
            $logs['access'][] = [
                'name' => basename($path), 'path' => $path,
                'size' => filesize($path), 'modified' => filemtime($path),
            ];
        }
    }
    foreach ($errorPaths as $path) {
        if (is_file($path) && is_readable($path)) {
            $logs['error'][] = [
                'name' => basename($path), 'path' => $path,
                'size' => filesize($path), 'modified' => filemtime($path),
            ];
        }
    }

    // Also scan log directories for rotated logs (e.g. access.log.1)
    $dirs = [];
    foreach (array_merge($accessPaths, $errorPaths) as $p) {
        $dirs[dirname($p)] = true;
    }
    foreach (array_keys($dirs) as $dir) {
        if (!is_dir($dir)) continue;
        $knownPaths = array_merge(
            array_column($logs['access'], 'path'),
            array_column($logs['error'], 'path')
        );
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            if (in_array($full, $knownPaths, true)) continue;
            if (!is_file($full) || !is_readable($full)) continue;
            if (preg_match('/\.(gz|bz2|zip|tar|xz|zst)$/i', $f)) continue;
            // Only pick up rotated versions (.1, .2, etc.)
            if (!preg_match('/\.\d+$/', $f)) continue;

            $entry = ['name' => $f, 'path' => $full, 'size' => filesize($full), 'modified' => filemtime($full)];
            if (stripos($f, 'error') !== false) {
                $logs['error'][] = $entry;
            } else {
                $logs['access'][] = $entry;
            }
        }
    }

    // Sort by modified desc
    $sorter = fn($a, $b) => $b['modified'] - $a['modified'];
    usort($logs['access'], $sorter);
    usort($logs['error'], $sorter);

    return $logs;
}

// ---------------------------------------------------------------------------
//  Log reading — snippet & tail (from StatsCaptain log_handler)
// ---------------------------------------------------------------------------

function lv_validate_log_path(string $requestedPath): ?string
{
    $real = realpath($requestedPath);
    if ($real === false) return null;
    if (!is_file($real) || !is_readable($real)) return null;

    // Validate against known log directories (from Apache config detection)
    $cfg = lv_load_config();
    $allowedDirs = [];
    foreach (array_merge($cfg['access_logs'] ?? [], $cfg['error_logs'] ?? []) as $p) {
        $allowedDirs[realpath(dirname($p)) ?: dirname($p)] = true;
    }
    // Also allow standard log dirs
    $allowedDirs['/var/log/apache2'] = true;
    $allowedDirs['/var/log/vhosts'] = true;

    $realDir = dirname($real);
    $valid = false;
    foreach (array_keys($allowedDirs) as $allowed) {
        if (str_starts_with($realDir, $allowed)) { $valid = true; break; }
    }
    if (!$valid) return null;

    return $real;
}

function lv_get_snippet(string $logPath, int $lines = 200, int $page = 0): array
{
    $validated = lv_validate_log_path($logPath);
    if (!$validated) return ['success' => false, 'message' => 'Invalid or inaccessible log file.'];

    $lines = max(1, min($lines, 2000));
    $file  = new SplFileObject($validated, 'r');
    $file->seek(PHP_INT_MAX);
    $totalLines = $file->key() + 1;
    $startLine  = max(0, $totalLines - ($lines * ($page + 1)));
    $endLine    = $totalLines - ($lines * $page);
    $result     = [];

    if ($totalLines > 0 && $startLine < $endLine) {
        $file->seek($startLine);
        $cur = $startLine;
        while (!$file->eof() && $cur < $endLine) {
            $line = rtrim($file->fgets(), "\r\n");
            if ($line !== '') $result[] = $line;
            $cur++;
            if (count($result) >= $lines) break;
        }
    }

    return [
        'success' => true,
        'data'    => [
            'lines'               => $result,
            'current_size'        => filesize($validated),
            'total_lines_in_file' => $totalLines,
            'page_loaded'         => $page,
            'lines_fetched'       => count($result),
        ],
    ];
}

/**
 * Get filtered log lines — scans entire log, returns only matching lines.
 * Filter types: threats, threat_CATEGORY, 4xx, 5xx, admin
 */
function lv_get_filtered_lines(string $logPath, string $filter, int $limit = 500): array
{
    $validated = lv_validate_log_path($logPath);
    if (!$validated) return ['success' => false, 'message' => 'Invalid or inaccessible log file.'];

    $patterns = lv_get_threat_patterns();
    $allThreatPats = [];
    foreach ($patterns as $cat) {
        foreach ($cat['patterns'] as $p) $allThreatPats[] = $p;
    }

    // Determine which category patterns to use for threat_xxx filters
    $catPats = null;
    if (str_starts_with($filter, 'threat_')) {
        $catKey = substr($filter, 7);
        if (isset($patterns[$catKey])) {
            $catPats = $patterns[$catKey]['patterns'];
        } else {
            return ['success' => true, 'data' => ['lines' => [], 'filter' => $filter, 'total_matched' => 0]];
        }
    }

    $result = [];
    $totalMatched = 0;
    $fileSize = filesize($validated);
    if ($fileSize === 0) {
        return ['success' => true, 'data' => ['lines' => [], 'filter' => $filter, 'total_matched' => 0]];
    }

    // Read backwards (newest first) in 64KB chunks
    $fh = @fopen($validated, 'r');
    if (!$fh) return ['success' => false, 'message' => 'Cannot open log file.'];

    $chunkSize = 65536;
    $leftover  = '';
    $offset    = $fileSize;
    $scanned   = 0;
    $maxScan   = 500000; // max lines to scan

    while ($offset > 0 && count($result) < $limit && $scanned < $maxScan) {
        $readSize = min($chunkSize, $offset);
        $offset  -= $readSize;
        fseek($fh, $offset);
        $chunk = fread($fh, $readSize) . $leftover;
        $parts = explode("\n", $chunk);
        $leftover = array_shift($parts);

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $line = rtrim($parts[$i], "\r");
            if ($line === '') continue;
            $scanned++;
            if ($scanned > $maxScan) break;

            $parsed = lv_parse_access_line($line);
            if (!$parsed) continue;

            $url = $parsed['url'];
            $ua  = $parsed['user_agent'] ?? '';
            $matched = false;

            switch ($filter) {
                case 'threats':
                    foreach ($allThreatPats as $p) {
                        if (preg_match($p, $url)) { $matched = true; break; }
                    }
                    break;
                case '4xx':
                    $matched = ($parsed['status'] >= 400 && $parsed['status'] < 500);
                    break;
                case '5xx':
                    $matched = ($parsed['status'] >= 500);
                    break;
                case 'admin':
                    $matched = (str_starts_with($url, '/admin/') || $url === '/admin');
                    break;
                default:
                    // threat_CATEGORY
                    if ($catPats) {
                        foreach ($catPats as $p) {
                            if (preg_match($p, $url) || preg_match($p, $ua)) { $matched = true; break; }
                        }
                    }
                    break;
            }

            if ($matched) {
                $totalMatched++;
                if (count($result) < $limit) {
                    $result[] = $line;
                }
            }
        }
    }
    fclose($fh);

    return [
        'success' => true,
        'data' => [
            'lines'         => $result,
            'filter'        => $filter,
            'total_matched' => $totalMatched,
            'lines_scanned' => $scanned,
            'current_size'  => $fileSize,
        ],
    ];
}

function lv_get_new_lines(string $logPath, int $offset): array
{
    $validated = lv_validate_log_path($logPath);
    if (!$validated) return ['success' => false, 'message' => 'Invalid log file.'];

    clearstatcache(true, $validated);
    $currentSize = filesize($validated);
    $newLines    = [];
    $newOffset   = $offset;

    if ($currentSize > $offset) {
        $fh = fopen($validated, 'r');
        fseek($fh, $offset);
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') $newLines[] = $line;
        }
        fclose($fh);
        $newOffset = $currentSize;
    } elseif ($currentSize < $offset) {
        // File rotated
        $newOffset = 0;
    }

    return [
        'success' => true,
        'data'    => [
            'new_lines'            => $newLines,
            'current_size'         => $currentSize,
            'new_offset_suggested' => $newOffset,
        ],
    ];
}

// ---------------------------------------------------------------------------
//  DNS resolution with cache (from StatsCaptain log_handler)
// ---------------------------------------------------------------------------

function lv_resolve_ip(string $ip): string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return $ip;

    $cacheDir = lv_data_dir() . '/dns_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

    $cfg       = lv_load_config();
    $cacheTtl  = $cfg['dns_cache_ttl'] ?? 86400;
    $cacheFile = $cacheDir . '/' . md5($ip) . '.cache';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if (isset($cached['hostname'])) return $cached['hostname'];
    }

    $hostname = @gethostbyaddr($ip);
    $resolved = ($hostname && $hostname !== $ip) ? $hostname : $ip;
    @file_put_contents($cacheFile, json_encode(['hostname' => $resolved, 'ip' => $ip, 'ts' => time()]));
    @chmod($cacheFile, 0664);
    return $resolved;
}

// ---------------------------------------------------------------------------
//  Threat detection — security patterns from ServerSentinel
// ---------------------------------------------------------------------------

function lv_get_threat_patterns(): array
{
    return [
        'wordpress_probing' => [
            'name'     => 'WordPress Probing',
            'severity' => 'high',
            'icon'     => 'fab fa-wordpress',
            'patterns' => [
                '#/wp-(?:admin|login|content|includes|config|json|api|cron|signup|activate|blog-header|comments-post|mail|links-opml|trackback)#i',
                '#/xmlrpc\.php#i',
            ],
        ],
        'env_file_hunting' => [
            'name'     => '.env File Hunting',
            'severity' => 'high',
            'icon'     => 'fas fa-key',
            'patterns' => ['#\.env(?:\.|$)#i'],
        ],
        'git_repo_probing' => [
            'name'     => 'Git/SVN Probing',
            'severity' => 'high',
            'icon'     => 'fab fa-git-alt',
            'patterns' => ['#/\.git(?:/|$)#i', '#/\.svn(?:/|$)#i', '#/\.hg(?:/|$)#i'],
        ],
        'sql_injection' => [
            'name'     => 'SQL Injection',
            'severity' => 'high',
            'icon'     => 'fas fa-database',
            'patterns' => [
                '#(?:UNION\s+(?:ALL\s+)?SELECT|SELECT\s+.*FROM\s|DROP\s+TABLE|INSERT\s+INTO)#i',
                '#(?:benchmark\s*\(|sleep\s*\(|waitfor\s+delay)#i',
            ],
        ],
        'xss_attack' => [
            'name'     => 'XSS / Script Injection',
            'severity' => 'high',
            'icon'     => 'fas fa-bug',
            'patterns' => [
                '#<script[^>]*>#i',
                '#javascript:#i',
                '#on(?:error|load|click|mouseover)\s*=#i',
            ],
        ],
        'path_traversal' => [
            'name'     => 'Path Traversal',
            'severity' => 'high',
            'icon'     => 'fas fa-folder-open',
            'patterns' => [
                '#\.\./\.\./|\.\.\\\\\.\.\\\\#',
                '#/etc/(?:passwd|shadow|hosts)#',
                '#/proc/self#',
            ],
        ],
        'shell_upload' => [
            'name'     => 'Shell / Backdoor',
            'severity' => 'high',
            'icon'     => 'fas fa-skull-crossbones',
            'patterns' => [
                '#/(?:c99|r57|wso|b374k|alfa|shell|cmd|eval|exec)\.php#i',
                '#/(?:webshell|backdoor)#i',
            ],
        ],
        'admin_probing' => [
            'name'     => 'Admin Panel Probing',
            'severity' => 'medium',
            'icon'     => 'fas fa-user-shield',
            'patterns' => [
                '#/(?:phpmyadmin|adminer|administrator|manager/html|cpanel|plesk|webmail)#i',
            ],
        ],
        'config_probing' => [
            'name'     => 'Config File Probing',
            'severity' => 'medium',
            'icon'     => 'fas fa-cog',
            'patterns' => [
                '#/(?:config|configuration|settings|database)\.(?:php|yml|yaml|ini|json)#i',
                '#/\.htpasswd#i',
            ],
        ],
        'backup_hunting' => [
            'name'     => 'Backup Hunting',
            'severity' => 'medium',
            'icon'     => 'fas fa-archive',
            'patterns' => [
                '#\.(?:sql|bak|backup|dump|old|orig|save|swp|sav)$#i',
                '#/(?:backup|dump)/?$#i',
            ],
        ],
        'scanner_bot' => [
            'name'     => 'Scanner / Bot Probing',
            'severity' => 'low',
            'icon'     => 'fas fa-robot',
            'patterns' => [
                '#(?:sqlmap|nikto|nmap|dirbuster|nuclei|wpscan|acunetix)#i',
            ],
        ],
        'suspicious_extensions' => [
            'name'     => 'Suspicious Extensions',
            'severity' => 'medium',
            'icon'     => 'fas fa-file-code',
            'patterns' => [
                '#\.(?:asp|aspx|jsp|cgi|exe|dll|bat|cmd|com)$#i',
            ],
        ],
    ];
}

/**
 * Parse a single Apache combined-format log line.
 */
function lv_parse_access_line(string $line): ?array
{
    $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\w+) (\S+) HTTP\/[^"]+" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?/';
    if (!preg_match($pattern, $line, $m)) return null;

    return [
        'ip'         => $m[1],
        'datetime'   => $m[2],
        'method'     => $m[3],
        'url'        => urldecode($m[4]),
        'status'     => (int) $m[5],
        'size'       => $m[6] === '-' ? 0 : (int) $m[6],
        'referrer'   => $m[7] ?? '-',
        'user_agent' => $m[8] ?? '-',
    ];
}

/**
 * Analyze access log for dashboard stats + threat detection.
 * Returns cached result if fresh enough.
 */
function lv_analyze_dashboard(string $range = 'today'): array
{
    $cfg      = lv_load_config();
    $cacheDir = lv_data_dir() . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

    $cacheFile = $cacheDir . '/dashboard_' . $range . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < ($cfg['cache_ttl'] ?? 300)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    // Determine time window
    $tz  = new DateTimeZone('America/New_York');
    $now = new DateTime('now', $tz);

    switch ($range) {
        case '24h':
            $since = (clone $now)->modify('-24 hours');
            break;
        case '7d':
            $since = (clone $now)->modify('-7 days')->setTime(0, 0);
            break;
        case '30d':
            $since = (clone $now)->modify('-30 days')->setTime(0, 0);
            break;
        case 'today':
        default:
            $since = (clone $now)->setTime(0, 0);
            break;
    }

    $sinceTs = $since->getTimestamp();

    // Find the primary access log
    $logs    = lv_discover_logs();
    $accLogs = $logs['access'];
    if (empty($accLogs)) {
        return ['ok' => true, 'range' => $range, 'totals' => lv_empty_totals(), 'threats' => [], 'top_threats' => [], 'recent_threats' => [], 'top_pages' => [], 'top_ips' => [], 'status_codes' => []];
    }

    $patterns = lv_get_threat_patterns();
    $totals   = lv_empty_totals();
    $threats  = [];   // category => count
    $recentThreats = [];
    $pages    = [];
    $ips      = [];
    $statuses = [];
    $uniqueIps = [];

    // Parse access logs — read backwards (tail-first) so we stop early once
    // we pass the time window instead of scanning the entire log from the top.
    $lineLimit = 200000;
    $lineCount = 0;
    $stoppedEarly = false;

    foreach ($accLogs as $logInfo) {
        if ($lineCount >= $lineLimit || $stoppedEarly) break;
        $path = $logInfo['path'];
        if (!is_readable($path)) continue;

        $fileSize = filesize($path);
        if ($fileSize === 0) continue;

        // Read file backwards in 64KB chunks
        $fh = @fopen($path, 'r');
        if (!$fh) continue;

        $chunkSize  = 65536;
        $leftover   = '';
        $offset     = $fileSize;
        $lines      = [];

        while ($offset > 0 && $lineCount < $lineLimit && !$stoppedEarly) {
            $readSize = min($chunkSize, $offset);
            $offset  -= $readSize;
            fseek($fh, $offset);
            $chunk = fread($fh, $readSize);
            $chunk = $chunk . $leftover;

            // Split into lines
            $parts    = explode("\n", $chunk);
            $leftover = array_shift($parts); // incomplete first line carries over

            // Process lines in reverse (newest first within chunk, but $parts is already ordered)
            // Actually, within the chunk the lines are oldest-first, so process from end
            for ($li = count($parts) - 1; $li >= 0; $li--) {
                $line = rtrim($parts[$li], "\r");
                if ($line === '') continue;
                $lineCount++;
                if ($lineCount > $lineLimit) break;

                $parsed = lv_parse_access_line($line);
                if (!$parsed) continue;

                $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $parsed['datetime']);
                if (!$dt) continue;
                if ($dt->getTimestamp() < $sinceTs) {
                    $stoppedEarly = true;
                    break;
                }

            // Skip admin panel requests from visitor stats
            $isAdmin = str_starts_with($parsed['url'], '/admin/') || $parsed['url'] === '/admin';

            if (!$isAdmin) {
                $totals['requests']++;
                $totals['bandwidth'] += $parsed['size'];
                $uniqueIps[$parsed['ip']] = true;

                // Status codes
                $sc = (string) $parsed['status'];
                $statuses[$sc] = ($statuses[$sc] ?? 0) + 1;

                if ($parsed['status'] >= 400 && $parsed['status'] < 500) $totals['client_errors']++;
                if ($parsed['status'] >= 500) $totals['server_errors']++;
                if ($parsed['status'] === 200 || $parsed['status'] === 304) {
                    // Only count page views for non-asset URLs
                    if (!preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|map|webp)$/i', $parsed['url'])) {
                        $totals['page_views']++;
                        $pages[$parsed['url']] = ($pages[$parsed['url']] ?? 0) + 1;
                    }
                }
            } else {
                $totals['admin_hits']++;
            }

            // Threat detection (still check admin URLs for probing attempts)
            $url = $parsed['url'];
            $ua  = $parsed['user_agent'] ?? '';
            foreach ($patterns as $catKey => $cat) {
                foreach ($cat['patterns'] as $pat) {
                    // Check URL and user_agent
                    if (preg_match($pat, $url) || ($catKey === 'scanner_bot' && preg_match($pat, $ua))) {
                        $threats[$catKey] = ($threats[$catKey] ?? 0) + 1;
                        $totals['threats']++;
                        $ips[$parsed['ip']] = ($ips[$parsed['ip']] ?? 0) + 1;

                        // Keep last 50 recent threats
                        if (count($recentThreats) < 50) {
                            $recentThreats[] = [
                                'time'     => $parsed['datetime'],
                                'ip'       => $parsed['ip'],
                                'category' => $catKey,
                                'name'     => $cat['name'],
                                'severity' => $cat['severity'],
                                'url'      => $parsed['url'],
                                'status'   => $parsed['status'],
                            ];
                        }
                        break 2; // One match per line
                    }
                }
            }
            }  // end for ($li)
        }  // end while ($offset > 0)

        // Process leftover (first line of file)
        if ($leftover !== '' && !$stoppedEarly && $lineCount < $lineLimit) {
            $parsed = lv_parse_access_line(rtrim($leftover, "\r"));
            if ($parsed) {
                $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $parsed['datetime']);
                if ($dt && $dt->getTimestamp() >= $sinceTs) {
                    $totals['requests']++;
                    $totals['bandwidth'] += $parsed['size'];
                    $uniqueIps[$parsed['ip']] = true;
                    $sc = (string) $parsed['status'];
                    $statuses[$sc] = ($statuses[$sc] ?? 0) + 1;
                    if ($parsed['status'] >= 400 && $parsed['status'] < 500) $totals['client_errors']++;
                    if ($parsed['status'] >= 500) $totals['server_errors']++;
                }
            }
        }

        fclose($fh);
    }

    $totals['unique_visitors'] = count($uniqueIps);

    // Build top threats
    arsort($threats);
    $topThreats = [];
    foreach ($threats as $cat => $count) {
        $info = $patterns[$cat] ?? ['name' => $cat, 'severity' => 'medium', 'icon' => 'fas fa-exclamation-triangle'];
        $topThreats[] = [
            'category' => $cat,
            'name'     => $info['name'],
            'severity' => $info['severity'],
            'icon'     => $info['icon'],
            'count'    => $count,
        ];
    }

    // Top pages (limit 20)
    arsort($pages);
    $topPages = [];
    $i = 0;
    foreach ($pages as $url => $count) {
        if ($i++ >= 20) break;
        $topPages[] = ['url' => $url, 'count' => $count];
    }

    // Top offending IPs
    arsort($ips);
    $topIps = [];
    $i = 0;
    foreach ($ips as $ip => $count) {
        if ($i++ >= 15) break;
        $topIps[] = ['ip' => $ip, 'count' => $count];
    }

    // Status code breakdown
    arsort($statuses);
    $statusCodes = [];
    foreach ($statuses as $code => $count) {
        $statusCodes[] = ['code' => $code, 'count' => $count];
    }

    $result = [
        'ok'             => true,
        'range'          => $range,
        'generated'      => date('c'),
        'totals'         => $totals,
        'top_threats'    => $topThreats,
        'recent_threats' => array_reverse($recentThreats), // newest first
        'top_pages'      => $topPages,
        'top_ips'        => $topIps,
        'status_codes'   => $statusCodes,
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    @chmod($cacheFile, 0664);
    return $result;
}

function lv_fmt_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function lv_empty_totals(): array
{
    return [
        'requests'        => 0,
        'unique_visitors' => 0,
        'page_views'      => 0,
        'bandwidth'       => 0,
        'client_errors'   => 0,
        'server_errors'   => 0,
        'threats'         => 0,
        'admin_hits'      => 0,
    ];
}

/**
 * Analyze error log for recent errors.
 */
function lv_analyze_errors(string $range = 'today'): array
{
    $cfg  = lv_load_config();
    $logs = lv_discover_logs();
    $errLogs = $logs['error'];
    if (empty($errLogs)) return ['ok' => true, 'errors' => [], 'total' => 0];

    $tz  = new DateTimeZone('America/New_York');
    $now = new DateTime('now', $tz);
    switch ($range) {
        case '24h':  $since = (clone $now)->modify('-24 hours'); break;
        case '7d':   $since = (clone $now)->modify('-7 days')->setTime(0, 0); break;
        case '30d':  $since = (clone $now)->modify('-30 days')->setTime(0, 0); break;
        default:     $since = (clone $now)->setTime(0, 0); break;
    }
    $sinceTs = $since->getTimestamp();

    $errors   = [];
    $total    = 0;
    $byLevel  = [];

    foreach ($errLogs as $logInfo) {
        $path = $logInfo['path'];
        if (!is_readable($path)) continue;

        $fh = @fopen($path, 'r');
        if (!$fh) continue;

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;

            // Apache error log: [Sun Mar 08 14:05:32.123456 2026] [php:error] ...
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $dtStr = preg_replace('/\.\d+/', '', $m[1]); // strip microseconds
                $dt = @date_create($dtStr);
                if (!$dt || $dt->getTimestamp() < $sinceTs) continue;
            } else {
                continue;
            }

            $total++;

            // Extract level
            $level = 'error';
            if (preg_match('/\[(php:)?(\w+)\]/', $line, $lm)) {
                $level = strtolower($lm[2]);
            }
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;

            // Extract client IP
            $ip = '-';
            if (preg_match('/\[client\s+([^\]:]+)/', $line, $ipm)) {
                $ip = $ipm[1];
            }

            // Extract clean message — strip the bracketed prefixes
            $msg = preg_replace('/^\[([^\]]*)\]\s*/', '', $line); // timestamp
            $msg = preg_replace('/^\[([^\]]*)\]\s*/', '', $msg);  // module:level
            $msg = preg_replace('/^\[([^\]]*)\]\s*/', '', $msg);  // pid/tid
            $msg = preg_replace('/^\[client\s+[^\]]+\]\s*/', '', $msg); // client
            if ($msg === '') $msg = $line;

            // Keep last 200
            if (count($errors) < 200) {
                $errors[] = [
                    'time'    => $m[1],
                    'level'   => $level,
                    'ip'      => $ip,
                    'message' => $msg,
                    'raw'     => $line,
                ];
            }
        }
        fclose($fh);
    }

    return [
        'ok'       => true,
        'errors'   => array_reverse($errors),
        'total'    => $total,
        'by_level' => $byLevel,
    ];
}
