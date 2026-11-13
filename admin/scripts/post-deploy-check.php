#!/usr/bin/env php
<?php
/**
 * Post-Deploy Invariant Check
 *
 * Asserts the things a deploy can silently break. Run it after every deploy;
 * a green regression suite does not prove the artifact on disk is usable.
 *
 * Motivating failure (2026-08-11): ServerSentinel's privilege wrapper
 * ss-run.sh was tracked in git as 0644. Every deploy faithfully shipped a
 * non-executable file, sudo answered "command not found", and the module
 * computed ~200 firewall bans every 6 hours and applied none of them for days.
 * Tests passed the whole time — nothing tested the file's mode.
 *
 * Usage:
 *   php post-deploy-check.php [--json] [--site-root=/path] [--verbose]
 *
 * Exit codes:
 *   0  all applicable checks passed
 *   1  one or more checks FAILED
 *   2  could not run (bad site root)
 *
 * Checks that do not apply to a given install SKIP rather than fail, so this
 * is safe to run on a spoke, the hub, or a fresh open-source install.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

$opts = getopt('', ['json', 'site-root::', 'verbose']);
$asJson  = isset($opts['json']);
$verbose = isset($opts['verbose']);
$siteRoot = rtrim((string)($opts['site-root'] ?? dirname(__DIR__, 2)), '/');

if (!is_dir($siteRoot . '/admin')) {
    fwrite(STDERR, "Not a site root (no admin/): {$siteRoot}\n");
    exit(2);
}

$results = [];

/** Record a check result. $status: pass|fail|skip */
function check(string $name, string $status, string $detail = '', string $fix = ''): void {
    global $results;
    $results[] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
}

// ---------------------------------------------------------------------
//  1. Executable bits on shipped scripts
// ---------------------------------------------------------------------
//  The bug class that started all this. Anything under a module's bin/ or
//  cli/ directory that is a shell script is meant to be executed.
// ---------------------------------------------------------------------
$nonExec = [];
$scanned = 0;
foreach (['admin/modules/*/bin/*.sh', 'admin/scripts/*.sh', 'admin/modules/*/cli/*.sh'] as $pattern) {
    foreach (glob($siteRoot . '/' . $pattern) ?: [] as $sh) {
        $scanned++;
        if (!is_executable($sh)) {
            $nonExec[] = str_replace($siteRoot . '/', '', $sh);
        }
    }
}
if ($scanned === 0) {
    check('shell scripts are executable', 'skip', 'no shell scripts found');
} elseif ($nonExec) {
    check(
        'shell scripts are executable',
        'fail',
        count($nonExec) . " of {$scanned} not executable:\n    " . implode("\n    ", $nonExec),
        'chmod +x the files above, AND fix the mode at source: '
            . 'git update-index --chmod=+x <file> (a deploy will otherwise put 0644 straight back)'
    );
} else {
    check('shell scripts are executable', 'pass', "{$scanned} checked");
}

// ---------------------------------------------------------------------
//  2. Data directories are owned by the web user, not root
// ---------------------------------------------------------------------
//  Running a maintenance task as root leaves root-owned files that the web
//  process can then never write. On this fleet that has bitten twice, once
//  badly enough to need a filesystem-wide permissions repair.
// ---------------------------------------------------------------------
$dataDir = $siteRoot . '/admin/data';
if (!is_dir($dataDir)) {
    check('admin/data writable by web user', 'skip', 'no admin/data');
} else {
    $rootOwned = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($it as $path) {
        if ($count++ > 4000) break;             // bounded: this runs after every deploy
        if (function_exists('fileowner') && @fileowner((string)$path) === 0) {
            $rootOwned[] = str_replace($siteRoot . '/', '', (string)$path);
            if (count($rootOwned) >= 10) break;
        }
    }
    if ($rootOwned) {
        check(
            'admin/data not root-owned',
            'fail',
            'root-owned paths under admin/data (web process cannot write these):' . "\n    "
                . implode("\n    ", $rootOwned),
            'chown -R <web-user>: admin/data — and re-run the offending task as the web user, never root'
        );
    } else {
        check('admin/data not root-owned', 'pass', "{$count} paths checked");
    }
}

// ---------------------------------------------------------------------
//  3. Every module directory declares module.json
// ---------------------------------------------------------------------
//  Only directories that actually look like modules. admin/modules/ also holds
//  pure data directories (registry/, _runtime/) which legitimately have no
//  manifest — flagging those trains people to ignore this check.
$missingManifest = [];
foreach (glob($siteRoot . '/admin/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file($dir . '/module.json')) continue;
    if (basename($dir)[0] === '_') continue;                  // _runtime and friends
    if (!glob($dir . '/*.php')) continue;                     // no PHP entry point = not a module
    $missingManifest[] = basename($dir);
}
if ($missingManifest) {
    check(
        'modules declare module.json',
        'fail',
        'missing manifest: ' . implode(', ', $missingManifest),
        'a module without module.json will not register its menu entry or be seen by the distribution allowlist'
    );
} else {
    check('modules declare module.json', 'pass');
}

// ---------------------------------------------------------------------
//  3b. Orphaned module data directories
// ---------------------------------------------------------------------
//  The module allowlist governs admin/modules/ and keeps it exact. It has no
//  say over admin/data/, which is site-owned and excluded from every deploy.
//  So when a module is retired, the allowlist stops shipping it but its
//  admin/data/<Module>/ directory stays behind forever — a tombstone no
//  deploy will ever clean up.
//
//  That is not cosmetic. On a client site 2026-08-11 the tombstones held a
//  live cPanel API token (CpanelAPI/, module long gone) and 28KB of another
//  client's AI prompts (MarketAnalyst/ on a music venue). Twelve in total.
//
//  Heuristic: a data dir named like a module (leading capital) with no
//  matching installed module. Lowercase dirs (logs, cache, podcasts, mystore,
//  telegram, artists) are site data and are skipped by construction.
// ---------------------------------------------------------------------
$modulesDir = $siteRoot . '/admin/modules';
$dataDir2   = $siteRoot . '/admin/data';

if (!is_dir($modulesDir) || !is_dir($dataDir2)) {
    check('no orphaned module data', 'skip', 'admin/modules or admin/data missing');
} else {
    $installed = [];
    $declared  = [];
    foreach (glob($modulesDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $installed[basename($d)] = true;

        // A module may own data dirs that are not named after it, via
        // requires_data_dirs in module.json (e.g. ArenaManager owns ArenaAgents).
        // Ignoring that reports live, correctly-declared data as orphaned.
        $mj = $d . '/module.json';
        if (!is_file($mj)) continue;
        $meta = json_decode((string)@file_get_contents($mj), true);
        if (!is_array($meta)) continue;
        foreach ((array)($meta['requires_data_dirs'] ?? []) as $dd) {
            if (!is_string($dd) || $dd === '') continue;
            $top = explode('/', trim($dd, '/'))[0];   // "Foo/bar" claims "Foo"
            if ($top !== '') $declared[$top] = basename($d);
        }
    }

    // Data dirs that look like modules but never are.
    $notModules = ['AgentScheduler_archive' => true];

    $orphans = [];
    foreach (glob($dataDir2 . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $name = basename($d);
        if ($name === '' || !ctype_upper($name[0])) continue;   // site data, not a module
        if (isset($installed[$name]) || isset($notModules[$name])) continue;
        if (isset($declared[$name])) continue;   // owned via requires_data_dirs

        // Recurse. A top-level glob undercounts badly — it counts subdirectories
        // as files but only sizes regular files, so a dir reported as
        // "1 file, 0 bytes" can actually hold megabytes. Also track the newest
        // mtime: "no installed module" does NOT mean dead. Hub-side pipelines
        // still write into some of these, and deleting those breaks live jobs.
        $files = 0; $bytes = 0; $newest = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $files++;
                $bytes  += (int)$f->getSize();
                $newest  = max($newest, (int)$f->getMTime());
            }
        } catch (Exception $e) {
            $orphans[] = sprintf('%s (unreadable: %s)', $name, $e->getMessage());
            continue;
        }
        $ageDays = $newest ? (time() - $newest) / 86400.0 : null;
        $isActive = ($ageDays !== null && $ageDays <= 30);
        $orphans[] = sprintf(
            '%s (%d file%s, %d bytes, %s)%s',
            $name,
            $files,
            $files === 1 ? '' : 's',
            $bytes,
            $ageDays === null ? 'empty' : sprintf('last write %.0fd ago', $ageDays),
            $isActive ? '  <-- ACTIVELY WRITTEN, do NOT delete' : ''
        );
    }

    if ($orphans) {
        sort($orphans);
        check(
            'no orphaned module data',
            'fail',
            count($orphans) . ' data dir(s) whose module is NOT installed:' . "\n    "
                . implode("\n    ", $orphans),
            'inspect before deleting — these can hold credentials or other clients\' content, '
                . 'and a few may be data you want to keep. Anything marked ACTIVELY WRITTEN still '
                . 'has a live writer (usually a hub-side pipeline) — deleting it breaks that job. '
                . 'Back up, then remove only the stale ones.'
        );
    } else {
        check(
            'no orphaned module data',
            'pass',
            count($installed) . ' modules cross-checked, ' . count($declared) . ' data dir(s) claimed via requires_data_dirs'
        );
    }
}

// ---------------------------------------------------------------------
//  4. ServerSentinel wiring (only where the module is installed)
// ---------------------------------------------------------------------
$ssDir = $siteRoot . '/admin/modules/ServerSentinel';
if (!is_dir($ssDir)) {
    check('ServerSentinel wiring', 'skip', 'module not installed');
} else {
    $wrapper = $ssDir . '/bin/ss-run.sh';
    if (!is_file($wrapper)) {
        check('ServerSentinel wrapper present', 'fail', 'bin/ss-run.sh missing');
    } elseif (!is_executable($wrapper)) {
        check(
            'ServerSentinel wrapper executable',
            'fail',
            'bin/ss-run.sh is ' . substr(sprintf('%o', fileperms($wrapper)), -4) . ' — sudo will report "command not found" and NO bans will be applied',
            'chmod +x bin/ss-run.sh and git update-index --chmod=+x'
        );
    } else {
        check('ServerSentinel wrapper executable', 'pass');
    }

    // The generated Apache config must be ENABLED, not merely written. A file
    // sitting in conf-available is inert; the bans in it do nothing.
    $available = '/etc/apache2/conf-available/server-sentinel-security.conf';
    $enabled   = '/etc/apache2/conf-enabled/server-sentinel-security.conf';
    if (!file_exists($available)) {
        check('ServerSentinel config deployed', 'skip', 'no config written yet');
    } elseif (!file_exists($enabled)) {
        check(
            'ServerSentinel config enabled',
            'fail',
            'config exists in conf-available but is NOT enabled — every ban in it is inert',
            'a2enconf server-sentinel-security && systemctl reload apache2'
        );
    } else {
        check('ServerSentinel config enabled', 'pass');
    }
}

// ---------------------------------------------------------------------
//  5. fail2ban jails are actually watching files
// ---------------------------------------------------------------------
//  An enabled jail with zero monitored files looks healthy in every status
//  summary and bans nothing, forever. Ask it what it is watching.
// ---------------------------------------------------------------------
$f2bClient = trim((string)@shell_exec('command -v fail2ban-client 2>/dev/null'));
if ($f2bClient === '') {
    check('fail2ban jails watching files', 'skip', 'fail2ban-client not present');
} else {
    $statusOut = (string)@shell_exec('fail2ban-client status 2>/dev/null');
    if (!preg_match('/Jail list:\s*(.+)/', $statusOut, $m)) {
        check('fail2ban jails watching files', 'skip', 'could not read jail list (needs root?)');
    } else {
        $jails = array_filter(array_map('trim', explode(',', $m[1])));
        $blind = [];
        foreach ($jails as $jail) {
            $lp = (string)@shell_exec('fail2ban-client get ' . escapeshellarg($jail) . ' logpath 2>/dev/null');
            if (stripos($lp, 'No file is currently monitored') === false) {
                continue;                                     // watching files — fine
            }
            // Monitoring no files is CORRECT for a journal-backed jail (sshd on
            // Debian/Ubuntu defaults to backend=systemd and reads the journal).
            // Only a jail with neither files nor a journal match is truly blind.
            $jm = trim((string)@shell_exec('fail2ban-client get ' . escapeshellarg($jail) . ' journalmatch 2>/dev/null'));
            $hasJournal = $jm !== ''
                && stripos($jm, 'no journal match') === false
                && stripos($jm, 'not set') === false;
            if (!$hasJournal) {
                $blind[] = $jail;
            }
        }
        if ($blind) {
            check(
                'fail2ban jails watching files',
                'fail',
                'enabled but monitoring ZERO files: ' . implode(', ', $blind)
                    . "\n    (these jails can never ban anything)",
                'check the jail logpath glob, and whether backend=systemd is overriding it — '
                    . 'a systemd-backed jail ignores logpath entirely'
            );
        } else {
            check('fail2ban jails watching files', 'pass', count($jails) . ' jails checked');
        }
    }
}

// ---------------------------------------------------------------------
//  Report
// ---------------------------------------------------------------------
$fail = count(array_filter($results, static fn($r) => $r['status'] === 'fail'));
$pass = count(array_filter($results, static fn($r) => $r['status'] === 'pass'));
$skip = count(array_filter($results, static fn($r) => $r['status'] === 'skip'));

if ($asJson) {
    echo json_encode([
        'ok'        => $fail === 0,
        'passed'    => $pass,
        'failed'    => $fail,
        'skipped'   => $skip,
        'site_root' => $siteRoot,
        'checked_at' => date('c'),
        'results'   => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($fail === 0 ? 0 : 1);
}

$icon = ['pass' => '  ok  ', 'fail' => ' FAIL ', 'skip' => ' skip '];
echo "\nPost-deploy invariant check — {$siteRoot}\n";
echo str_repeat('-', 66), "\n";
foreach ($results as $r) {
    printf("[%s] %s\n", $icon[$r['status']], $r['name']);
    if ($r['detail'] !== '' && ($r['status'] === 'fail' || $verbose)) {
        echo "         ", str_replace("\n", "\n         ", $r['detail']), "\n";
    }
    if ($r['status'] === 'fail' && $r['fix'] !== '') {
        echo "         fix: ", str_replace("\n", "\n              ", $r['fix']), "\n";
    }
}
echo str_repeat('-', 66), "\n";
printf("%d passed, %d failed, %d skipped\n\n", $pass, $fail, $skip);

exit($fail === 0 ? 0 : 1);
