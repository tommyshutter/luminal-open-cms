<?php
/*
 * Luminal CMS - Admin Dashboard
 * File Path: /admin/modules/Dashboard/Dashboard.php
 * Version: 2026.03.07
 */

// Bootstrap: auth.php loads utils.php which defines SITE_ROOT
require_once dirname(__DIR__, 2) . '/auth.php';
requireAuth();

// DashboardStatsOG — load functions + handle config save/clear redirects BEFORE HTML
require_once __DIR__ . '/DashboardStatsOG.php';
dsog_handle_early_actions();

$metaSettings = loadJsonData('meta_settings.json');
$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Artist', ENT_QUOTES, 'UTF-8');

$baseDir = SITE_ROOT;


?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $artistName; ?>  - Admin Dashboard</title>

     <link rel="stylesheet" href="/admin/css/admin-styles.css">
     <link rel="stylesheet" href="/admin/css/admin_menu.css">

   <?php /* AdminThemes hook — Dashboard builds its own <head>, so include the
            per-user theming hook here too (it's the login landing page). Guarded. */
   $__at_hook = SITE_ROOT . '/admin/modules/AdminThemes/theme_head.inc.php';
   if (is_file($__at_hook)) include $__at_hook; ?>
</head>
<body>
               <?php @include_once SITE_ROOT . '/admin/includes/admin-bg-apply.inc.php'; ?>

    <div class="admin-container">

          <h1 class="panel_header_h1" style="color:red">Site Admin Panel</h1>

          <?php include SITE_ROOT . '/admin/admin_menu.php'; ?>

          <div id="admin-messages" style="display:none;"></div>

          <div id="toast-notification"></div>

<?php if (isset($_GET['error']) && $_GET['error'] === 'not_authorized'): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var t = document.getElementById('toast-notification');
  if (t) { t.innerHTML = '<div style="background:#b91c1c;color:#fff;padding:12px 20px;border-radius:8px;margin:12px 0;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.4)">Not Authorized for This Login Level</div>'; setTimeout(function(){ t.innerHTML=''; }, 6000); }
});
</script>
<?php endif; ?>

          <div class="content-area" style="min-height:80vh">

<?php
    // ---- Version Banner ----
    $cmsVersionFile = SITE_ROOT . '/admin/data/cms-version.json';
    $cmsVersion = null;
    if (file_exists($cmsVersionFile)) {
        $cmsVersion = json_decode(file_get_contents($cmsVersionFile), true);
    }
    if ($cmsVersion) {
        $v = htmlspecialchars($cmsVersion['version'] ?? '?', ENT_QUOTES, 'UTF-8');
        $b = htmlspecialchars($cmsVersion['build'] ?? '?', ENT_QUOTES, 'UTF-8');
        $brand = htmlspecialchars($cmsVersion['brand'] ?? 'CMS', ENT_QUOTES, 'UTF-8');
        echo '<div class="cms-version-banner" style="background:rgba(0,0,0,0.5);color:#ccc;padding:10px 16px;border-radius:6px;margin-bottom:20px;font-size:14px;">';
        echo "<strong>{$brand}</strong> v{$v} &mdash; Build {$b}";
        echo '</div>';
    }

    // ---- Server Meta Widget ----
    $phpVer = PHP_VERSION;
    $osInfo = php_uname('s') . ' ' . php_uname('r');
    $osDistro = '';
    if (is_file('/etc/os-release')) {
        $osRel = parse_ini_file('/etc/os-release');
        $osDistro = $osRel['PRETTY_NAME'] ?? $osRel['NAME'] ?? '';
    } elseif (is_file('/etc/redhat-release')) {
        $osDistro = trim(file_get_contents('/etc/redhat-release'));
    } elseif (is_file('/etc/centos-release')) {
        $osDistro = trim(file_get_contents('/etc/centos-release'));
    }
    $sslProto = $_SERVER['SSL_PROTOCOL'] ?? ($_SERVER['HTTPS'] ?? 'off');
    $serverSoft = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $diskFree = @disk_free_space('/') ?: 0;
    $diskTotal = @disk_total_space('/') ?: 1;
    $diskPct = round(($diskTotal - $diskFree) / $diskTotal * 100);
    $diskFreeGB = round($diskFree / 1073741824, 1);
    $uptime = is_readable('/proc/uptime') ? round((float)file_get_contents('/proc/uptime') / 86400, 1) : '?';

    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;font-size:0.72rem;font-family:system-ui,sans-serif">';
    $pills = [
        ['&#9881;', 'PHP ' . $phpVer, '#3b82f6'],
        ['&#128190;', ($osDistro ?: $osInfo), '#8b5cf6'],
        ['&#9875;', preg_replace('/\/.+$/', '', $serverSoft), '#f59e0b'],
        ['&#128274;', (strtolower($sslProto) !== 'off' ? 'SSL Active' : 'No SSL'), strtolower($sslProto) !== 'off' ? '#22c55e' : '#dc2626'],
        ['&#128200;', "Disk: {$diskPct}% used ({$diskFreeGB}GB free)", $diskPct > 90 ? '#dc2626' : '#22c55e'],
        ['&#9200;', "Up {$uptime}d", '#06b6d4'],
    ];
    foreach ($pills as $p) {
        echo '<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.06);border-radius:6px;padding:3px 8px;color:#aaa">';
        echo '<span style="color:' . $p[2] . '">' . $p[0] . '</span> ' . htmlspecialchars($p[1]);
        echo '</span>';
    }
    echo '</div>';

    // ---- IP Whitelist Banner ----
    require_once SITE_ROOT . '/admin/includes/IPWhitelistHelper.php';
    $wlClientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($wlClientIp, ',') !== false) $wlClientIp = trim(explode(',', $wlClientIp)[0]);
    $wlEntry = ipwl_get_entry($wlClientIp);
    $wlRemaining = $wlEntry ? ipwl_remaining_seconds($wlClientIp) : 0;
?>
<!-- IP Whitelist Banner -->
<div id="ipwl-banner" style="padding:12px 18px;border-radius:6px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;<?php echo $wlEntry ? 'background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.4);color:#4ade80;' : 'background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.4);color:#fbbf24;'; ?>">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:18px;"><?php echo $wlEntry ? '&#9989;' : '&#9888;'; ?></span>
        <div>
            <strong>IP: <?= htmlspecialchars($wlClientIp) ?></strong>
            <?php if ($wlEntry): ?>
                &mdash; Whitelisted
                <?php if ($wlRemaining > 0): ?>
                    <span id="ipwl-countdown" style="font-variant-numeric:tabular-nums;"></span>
                <?php else: ?>
                    (permanent)
                <?php endif; ?>
            <?php else: ?>
                &mdash; Not whitelisted
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <?php
            // Preset durations must match IPWL_ALLOWED_MINUTES in ip-whitelist-api.php
            $ipwlDurations = [60 => '1 hr', 180 => '3 hrs', 360 => '6 hrs', 480 => '8 hrs'];
            $ipwlActive = ($wlEntry && $wlRemaining > 0);
        ?>
        <span style="font-size:12px;font-weight:600;letter-spacing:.03em;opacity:.85;"><?= $ipwlActive ? 'Reset for:' : 'Whitelist my IP for:' ?></span>
        <?php foreach ($ipwlDurations as $mins => $lbl): ?>
            <button type="button"
                onclick="ipwlWhitelist(<?= (int)$mins ?>, this)"
                class="ipwl-dur-btn"
                data-mins="<?= (int)$mins ?>"
                style="background:<?= $ipwlActive ? 'rgba(34,197,94,0.28)' : 'rgba(245,158,11,0.28)' ?>;border:1px solid <?= $ipwlActive ? 'rgba(34,197,94,0.55)' : 'rgba(245,158,11,0.55)' ?>;color:<?= $ipwlActive ? '#4ade80' : '#fbbf24' ?>;padding:6px 12px;border-radius:4px;font-size:13px;cursor:pointer;font-weight:600;font-variant-numeric:tabular-nums;">
                <?= htmlspecialchars($lbl) ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<?php
    // ---- Admin Session strip ----
    // Same admin_session_hours the Site Settings → Admin Session panel writes; surfaced here
    // beside the IP whitelist so the two "why am I locked out?" controls sit together.
    // Changing it takes effect IMMEDIATELY — set_session_hours re-issues the session cookie
    // with the same id and a new expiry, so nothing is logged out and no re-login is needed.
    // The setting itself lives in site-settings.json, so it survives sign-out.
    $ssFile  = SITE_ROOT . '/admin/data/site-settings.json';
    $ssCfg   = is_file($ssFile) ? (json_decode((string)@file_get_contents($ssFile), true) ?: []) : [];
    $ashCur  = (int)($ssCfg['admin_session_hours'] ?? 12);
    if ($ashCur < 1 || $ashCur > 24) $ashCur = 12;   // mirrors session_boot.php's clamp
    $ashOpts = [1 => '1 hr', 4 => '4 hrs', 8 => '8 hrs', 12 => '12 hrs', 24 => '24 hrs'];
    // Countdown anchor stamped once per session in admin/includes/auth.php.
    $ashRemaining = max(0, (int)($_SESSION['admin_session_expires'] ?? 0) - time());
?>
<!-- Admin Session Banner -->
<div id="ash-banner" style="padding:12px 18px;border-radius:6px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:rgba(99,102,241,0.15);border:1px solid rgba(99,102,241,0.4);color:#a5b4fc;">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:18px;">&#128274;</span>
        <div>
            <strong>Admin session</strong>
            &mdash; <span id="ash-current" style="font-variant-numeric:tabular-nums;"><?= htmlspecialchars($ashOpts[$ashCur] ?? ($ashCur . ' hrs')) ?></span>
            <span id="ash-countdown" style="font-variant-numeric:tabular-nums;opacity:.85;"></span>
            <div id="ash-idle-note" style="font-size:12px;opacity:.8;margin-top:3px;"></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:600;letter-spacing:.03em;opacity:.85;">Stay signed in for:</span>
        <?php foreach ($ashOpts as $hrs => $lbl): $on = ($hrs === $ashCur); ?>
            <button type="button"
                onclick="ashSetHours(<?= (int)$hrs ?>, this)"
                class="ash-dur-btn"
                data-hours="<?= (int)$hrs ?>"
                style="background:<?= $on ? 'rgba(99,102,241,0.45)' : 'rgba(99,102,241,0.20)' ?>;border:1px solid rgba(99,102,241,<?= $on ? '0.85' : '0.45' ?>);color:#c7d2fe;padding:6px 12px;border-radius:4px;font-size:13px;cursor:pointer;font-weight:<?= $on ? '800' : '600' ?>;font-variant-numeric:tabular-nums;">
                <?= htmlspecialchars($lbl) ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>
<script>
// Session countdown. Anchored on $_SESSION['admin_session_expires'] (stamped in auth.php),
// so it reflects THIS session's real expiry rather than a rolling guess. ashResetCountdown()
// is called after a duration change, since set_session_hours re-issues the cookie live.
var ashRemaining = <?= (int)$ashRemaining ?>;
var ashTimer = null;
function ashFmt(s) {
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return h > 0 ? (h + ':' + pad(m) + ':' + pad(sec)) : (m + ':' + pad(sec));
}
function ashTick() {
    var el = document.getElementById('ash-countdown');
    if (!el) return;
    if (ashRemaining <= 0) { el.textContent = '(expired — reload to sign in again)'; return; }
    // Label it as the TOTAL-session clock. It is NOT what usually ends the session — the idle
    // timer below almost always fires first — and an unqualified "11:47:03 remaining" reads as
    // a promise of hours that idle-logout will not honour. (2026-07-19)
    el.textContent = '(' + ashFmt(ashRemaining) + ' of total session left)';
    ashRemaining--;
    ashTimer = setTimeout(ashTick, 1000);
}
// State the binding constraint. idle-logout.js loads from admin_footer.php (after this script),
// so read its published config on window load rather than now — and take the value FROM it, never
// a second hardcoded 15, which would drift the moment either side changed.
function ashIdleNote() {
    var note = document.getElementById('ash-idle-note');
    if (!note) return;
    var info = window.LUMIdleInfo;
    if (!info || !info.idleMs) { note.textContent = ''; return; }   // idle-logout not active here
    var mins = Math.round(info.idleMs / 60000);
    note.innerHTML = '⚠️ <b>Signed out after ' + mins + ' min of inactivity</b>, or when the '
                   + 'total session above runs out — whichever comes first. Idle almost always wins.';
}
window.addEventListener('load', ashIdleNote);
function ashResetCountdown(seconds) {
    if (ashTimer) { clearTimeout(ashTimer); ashTimer = null; }
    ashRemaining = seconds;
    ashTick();
}
if (ashRemaining > 0) ashTick();

function ashSetHours(hours, btn) {
    var group = document.querySelectorAll('.ash-dur-btn');
    group.forEach(function (b) { b.disabled = true; });
    var prev = btn ? btn.textContent : '';
    if (btn) btn.textContent = '…';
    var body = new URLSearchParams({ action: 'set_session_hours', hours: String(hours) });
    fetch('/admin/modules/SiteSettings/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.error) || 'save failed');
        if (btn) btn.textContent = prev;
        // Repaint the active pill + the summary text without a page reload.
        group.forEach(function (b) {
            var on = parseInt(b.dataset.hours, 10) === hours;
            b.style.background  = on ? 'rgba(99,102,241,0.45)' : 'rgba(99,102,241,0.20)';
            b.style.borderColor = on ? 'rgba(99,102,241,0.85)' : 'rgba(99,102,241,0.45)';
            b.style.fontWeight  = on ? '800' : '600';
            b.disabled = false;
        });
        var cur = document.getElementById('ash-current');
        if (cur && btn) cur.textContent = prev;
        // The cookie was re-issued server-side, so restart the clock on the new expiry.
        if (j.data && j.data.expires) {
            ashResetCountdown(Math.max(0, j.data.expires - Math.floor(Date.now() / 1000)));
        }
    })
    .catch(function (e) {
        if (btn) btn.textContent = prev;
        group.forEach(function (b) { b.disabled = false; });
        alert('Could not change session length: ' + e.message);
    });
}
(function(){
    var remaining = <?= (int)$wlRemaining ?>;
    var countdownEl = document.getElementById('ipwl-countdown');
    if (remaining > 0 && countdownEl) {
        function tick() {
            if (remaining <= 0) {
                countdownEl.textContent = '(expired)';
                return;
            }
            var h = Math.floor(remaining / 3600);
            var m = Math.floor((remaining % 3600) / 60);
            var s = remaining % 60;
            var pad = function(n){ return (n < 10 ? '0' : '') + n; };
            var txt = h > 0 ? (h + ':' + pad(m) + ':' + pad(s)) : (m + ':' + pad(s));
            countdownEl.textContent = '(' + txt + ' remaining)';
            remaining--;
            setTimeout(tick, 1000);
        }
        tick();
    }
})();
function ipwlWhitelist(minutes, btn) {
    var group = btn ? btn.parentNode.querySelectorAll('.ipwl-dur-btn') : [];
    group.forEach(function(b){ b.disabled = true; });
    var prev = btn ? btn.textContent : '';
    if (btn) btn.textContent = '…';
    var body = 'minutes=' + encodeURIComponent(minutes);
    fetch('/admin/ajax/ip-whitelist-api.php?action=whitelist_ip', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
        .then(r => r.json())
        .then(d => { if (d.ok) location.reload(); else { group.forEach(function(b){ b.disabled=false; }); if(btn) btn.textContent = prev; alert(d.error || 'Failed'); }})
        .catch(() => { group.forEach(function(b){ b.disabled=false; }); if(btn) btn.textContent = prev; });
}
</script>
<?php



    // ---- Agent Tasks Scoreboard (only if AgentScheduler is installed) ----
    $asInstalled = is_dir(SITE_ROOT . '/admin/modules/AgentScheduler');
    $asDataDir   = SITE_ROOT . '/admin/data/AgentScheduler';
    $asCounts    = ['running'=>0,'error'=>0,'success_today'=>0,'scheduled'=>0,'total'=>0];
    $asRecentTasks = [];
    if ($asInstalled && is_dir($asDataDir . '/tasks')) {
        $now = time();
        $today = date('Y-m-d');
        foreach (glob($asDataDir . '/tasks/*.json') ?: [] as $atf) {
            $at = json_decode((string)file_get_contents($atf), true);
            if (!$at || !($at['enabled'] ?? false)) continue;
            $asCounts['total']++;
            // Derive display status: trust last_status over stale status field.
            // A task with status=error but consecutive_errors=0 recovered — show as OK.
            $rawStatus = $at['status'] ?? '';
            $lastStatus = $at['last_status'] ?? '';
            $consErrors = (int)($at['consecutive_errors'] ?? 0);
            if ($rawStatus === 'running') {
                $atStatus = 'running';
            } elseif ($rawStatus === 'awaiting_approval') {
                $atStatus = 'awaiting_approval';
            } elseif ($rawStatus === 'blocked') {
                $atStatus = 'blocked';
            } elseif ($consErrors > 0) {
                $atStatus = 'error';
            } elseif ($rawStatus === 'error' && $consErrors === 0) {
                // Stale error — task failed once but isn't actively failing (no retries pending)
                $atStatus = 'stale';
            } elseif ($lastStatus === 'success' || $rawStatus === 'completed' || $rawStatus === 'applied') {
                $atStatus = 'success';
            } else {
                $atStatus = $rawStatus ?: $lastStatus ?: 'idle';
            }
            if ($atStatus === 'running') $asCounts['running']++;
            elseif ($atStatus === 'error') $asCounts['error']++;
            if (($at['last_status'] ?? '') === 'success' && str_starts_with($at['last_run'] ?? '', $today)) {
                $asCounts['success_today']++;
            }
            if (!empty($at['next_run']) && strtotime($at['next_run']) > $now) {
                $asCounts['scheduled']++;
            }
            // Collect tasks with recent activity for expandable list
            $asRecentTasks[] = [
                'id'       => $at['id'] ?? basename($atf, '.json'),
                'name'     => $at['name'] ?? basename($atf, '.json'),
                'status'   => $atStatus,
                'pipeline' => $at['pipeline'] ?? '',
                'last_run' => $at['last_run'] ?? '',
                'next_run' => $at['next_run'] ?? '',
                'errors'   => (int)($at['consecutive_errors'] ?? 0),
                'last_err' => $at['last_error'] ?? '',
            ];
        }
        // Sort: running first, then active errors, then reviews, stale at bottom
        $asStatusOrder = ['running' => 0, 'error' => 1, 'awaiting_approval' => 2, 'blocked' => 3, 'stale' => 8];
        usort($asRecentTasks, function($a, $b) use ($asStatusOrder) {
            $sa = $asStatusOrder[$a['status']] ?? 9;
            $sb = $asStatusOrder[$b['status']] ?? 9;
            if ($sa !== $sb) return $sa - $sb;
            return strcmp($b['last_run'], $a['last_run']);
        });
    }

    // ---- System Stats ----
    if (!function_exists('fmt_bytes')) {
        function fmt_bytes($b) {
            if ($b <= 0) return '0 B';
            $u = ['B','KB','MB','GB','TB','PB'];
            $i = (int)floor(log($b, 1024));
            return sprintf('%.1f %s', $b / pow(1024, $i), $u[$i]);
        }
    }
    $stats = [];
    $stats['hostname']    = gethostname() ?: '—';
    $stats['php_version'] = PHP_VERSION;
    $rawIps = trim(shell_exec("hostname -I 2>/dev/null") ?? '');
    $stats['server_ip'] = $rawIps ? explode(' ', $rawIps)[0] : '—';
    $loadRaw = @file_get_contents('/proc/loadavg');
    if ($loadRaw) {
        $parts = explode(' ', $loadRaw);
        $stats['load'] = "$parts[0] / $parts[1] / $parts[2]";
    } else {
        $stats['load'] = '—';
    }
    $memRaw = @file_get_contents('/proc/meminfo');
    if ($memRaw && preg_match('/MemTotal:\s+(\d+)/', $memRaw, $mt) && preg_match('/MemAvailable:\s+(\d+)/', $memRaw, $ma)) {
        $totalKB = (int)$mt[1]; $availKB = (int)$ma[1]; $usedKB = $totalKB - $availKB;
        $pct = $totalKB > 0 ? round(($usedKB / $totalKB) * 100) : 0;
        $stats['memory'] = sprintf('%s / %s (%d%%)', fmt_bytes($usedKB * 1024), fmt_bytes($totalKB * 1024), $pct);
        $stats['mem_pct'] = $pct;
    } else {
        $stats['memory'] = '—'; $stats['mem_pct'] = 0;
    }
    $diskTotal = @disk_total_space($baseDir);
    $diskFree  = @disk_free_space($baseDir);
    if ($diskTotal && $diskFree) {
        $diskUsed = $diskTotal - $diskFree;
        $diskPct  = round(($diskUsed / $diskTotal) * 100);
    }
    $uptimeRaw = @file_get_contents('/proc/uptime');
    if ($uptimeRaw) {
        $secs = (int)explode(' ', $uptimeRaw)[0];
        $days = floor($secs / 86400); $hrs = floor(($secs % 86400) / 3600); $mins = floor(($secs % 3600) / 60);
        $stats['uptime'] = "{$days}d {$hrs}h {$mins}m";
    } else {
        $stats['uptime'] = '—';
    }
    $pagesDir = SITE_ROOT . '/admin/data/pages';
    $pageCount = 0;
    if (is_dir($pagesDir)) {
        foreach (scandir($pagesDir) as $e) {
            if ($e !== '.' && $e !== '..' && is_dir("$pagesDir/$e")) $pageCount++;
        }
    }
    $stats['pages'] = $pageCount;
    $stats['galleries'] = "$ALL_GALLERY_COUNT (Img:$IMG_COUNT Vid:$VID_COUNT PDF:$PDF_COUNT Mix:$CMB_COUNT)";

    // Leads count from AudienceBuilder
    $abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/submissions';
    $abLeadCount = 0;
    if (is_dir($abLeadsDir)) {
        $abLeadCount = count(glob($abLeadsDir . '/*.json'));
    }
?>

<style>
/* ── Dashboard Layout ──────────────────────────────────────────────────── */
.dash-grid{max-width:100%;}

/* ── Sidebar panels ────────────────────────────────────────────────────── */

.vsd-panel{background:rgba(0,0,0,0.45);border:1px solid #333;border-radius:8px;padding:24px 28px;margin-bottom:0;}
.vsd-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.vsd-title{font-size:.8rem;text-transform:uppercase;letter-spacing:.6px;color:#999;font-weight:700;}
.vsd-updated{font-size:.7rem;color:#555;}

.vsd-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
@media(max-width:520px){.vsd-grid{grid-template-columns:repeat(2,1fr);}}
.vsd-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:6px;padding:12px 10px;text-align:center;transition:border-color .2s;cursor:pointer;}
.vsd-card:hover{border-color:rgba(255,255,255,0.15);}
.vsd-num{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.15;letter-spacing:-.5px;}
.vsd-label{font-size:.62rem;color:#777;margin-top:3px;text-transform:uppercase;letter-spacing:.3px;font-weight:500;}

.vsd-sys-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;}
@media(max-width:520px){.vsd-sys-grid{grid-template-columns:repeat(2,1fr);}}
.vsd-sys-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:6px;padding:10px 14px;transition:border-color .2s;}
.vsd-sys-card:hover{border-color:rgba(255,255,255,0.15);}
.vsd-sys-label{font-size:.6rem;text-transform:uppercase;letter-spacing:.3px;color:#666;font-weight:600;margin-bottom:3px;}
.vsd-sys-val{font-size:1rem;color:#e8e8e8;font-family:'Courier New',monospace;font-weight:600;}
.vsd-disk-meter{position:relative;height:8px;border-radius:999px;overflow:hidden;background:rgba(255,255,255,0.06);border:1px solid #444;margin:10px 0 8px;}
.vsd-disk-fill{position:absolute;left:0;top:0;height:100%;background:linear-gradient(90deg,#ff8a00,#ff4d4d);box-shadow:0 0 8px rgba(255,120,0,.3);}
.vsd-disk-legend{display:flex;justify-content:space-between;gap:8px;font-size:.75rem;font-family:'Courier New',monospace;}

/* ── Expandable scoreboard panels ──────────────────────────────────────── */
.sb-expand-btn{appearance:none;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);
  border-radius:6px;color:#888;font:600 11px system-ui;padding:5px 12px;cursor:pointer;
  transition:all .15s;margin-top:12px;width:100%;text-align:center;letter-spacing:.3px;}
.sb-expand-btn:hover{background:rgba(255,255,255,0.08);color:#bbb;border-color:rgba(255,255,255,0.15);}
.sb-expand-list{display:none;margin-top:12px;border-top:1px solid rgba(255,255,255,0.06);padding-top:8px;max-height:320px;overflow-y:auto;}
.sb-expand-list.open{display:block;}
.sb-ticket-row,.sb-task-row{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:5px;
  font-size:12px;transition:background .12s;text-decoration:none;color:#ccc;cursor:pointer;}
.sb-ticket-row:hover,.sb-task-row:hover{background:rgba(255,255,255,0.05);}
.sb-row-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.sb-row-id{color:#888;font-family:'Courier New',monospace;font-size:11px;min-width:100px;flex-shrink:0;}
.sb-row-subject{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#ddd;}
.sb-row-domain{color:#666;font-size:11px;flex-shrink:0;max-width:140px;overflow:hidden;text-overflow:ellipsis;}
.sb-row-status{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;padding:2px 6px;
  border-radius:3px;flex-shrink:0;}
.sb-task-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#ddd;font-weight:500;}
.sb-task-pipeline{color:#666;font-size:11px;flex-shrink:0;}
.sb-task-errors{color:#f87171;font-size:11px;font-weight:600;}
</style>

<?php if ($asInstalled && $asCounts['total'] > 0): ?>
<!-- ── Agent Tasks Scoreboard (only on sites with AgentScheduler) ─────── -->
<div class="vsd-panel" style="margin-bottom:20px;">
    <div class="vsd-header">
        <div class="vsd-title">Agent Tasks</div>
        <div class="vsd-updated"><?= $asCounts['total'] ?> tasks &middot; <?= $asCounts['success_today'] ?> ok today</div>
    </div>
    <div class="vsd-grid" style="grid-template-columns:repeat(4,1fr);">
        <div class="vsd-card" onclick="window.location='/admin/modules/AgentScheduler/AgentScheduler.php'">
            <div class="vsd-num" style="color:<?= $asCounts['running'] > 0 ? '#60a5fa' : '#e6eef6' ?>;"><?= $asCounts['running'] ?></div>
            <div class="vsd-label">Running</div>
        </div>
        <div class="vsd-card" onclick="window.location='/admin/modules/AgentScheduler/AgentScheduler.php'">
            <div class="vsd-num" style="color:<?= $asCounts['error'] > 0 ? '#f87171' : '#34d399' ?>;"><?= $asCounts['error'] ?></div>
            <div class="vsd-label">Errors</div>
        </div>
        <div class="vsd-card" onclick="window.location='/admin/modules/AgentScheduler/AgentScheduler.php'">
            <div class="vsd-num" style="color:#34d399;"><?= $asCounts['success_today'] ?></div>
            <div class="vsd-label">OK Today</div>
        </div>
        <div class="vsd-card" onclick="window.location='/admin/modules/AgentScheduler/AgentScheduler.php'">
            <div class="vsd-num" style="color:#fbbf24;"><?= $asCounts['scheduled'] ?></div>
            <div class="vsd-label">Scheduled</div>
        </div>
    </div>
    <?php if (!empty($asRecentTasks)): ?>
    <button class="sb-expand-btn" onclick="document.getElementById('asExpandList').classList.toggle('open');this.textContent=document.getElementById('asExpandList').classList.contains('open')?'Collapse':'Show Tasks'">Show Tasks</button>
    <div class="sb-expand-list" id="asExpandList">
        <?php foreach (array_slice($asRecentTasks, 0, 20) as $at):
            $atDot = match($at['status']) {
                'running' => '#60a5fa', 'error' => '#f87171', 'awaiting_approval' => '#fbbf24',
                'blocked' => '#fb923c', 'stale' => '#555', default => '#34d399',
            };
            $atStatusLabel = match($at['status']) {
                'running' => 'Running', 'error' => 'Error', 'awaiting_approval' => 'Review',
                'blocked' => 'Blocked', 'success' => 'OK', 'stale' => 'Stale',
                default => ucfirst($at['status']),
            };
            $atStatusBg = match($at['status']) {
                'error' => 'rgba(248,113,113,.15)', 'running' => 'rgba(96,165,250,.15)',
                'awaiting_approval' => 'rgba(251,191,36,.15)', 'blocked' => 'rgba(251,146,60,.15)',
                'stale' => 'rgba(255,255,255,.03)',
                default => 'rgba(52,211,153,.1)',
            };
        ?>
        <a class="sb-task-row" href="/admin/modules/AgentScheduler/AgentScheduler.php?task=<?= urlencode($at['id']) ?>">
            <span class="sb-row-dot" style="background:<?= $atDot ?>"></span>
            <span class="sb-task-name"><?= htmlspecialchars($at['name']) ?></span>
            <span class="sb-task-pipeline"><?= htmlspecialchars($at['pipeline']) ?></span>
            <?php if ($at['errors'] > 0): ?>
                <span class="sb-task-errors"><?= $at['errors'] ?>x err</span>
            <?php endif; ?>
            <span class="sb-row-status" style="background:<?= $atStatusBg ?>;color:<?= $atDot ?>"><?= $atStatusLabel ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ── Dashboard Grid Layout ───────────────────────────────────────────── -->
<div class="dash-grid">

<!-- ── System Info Panel ──────────────────────────────────────────────── -->
<div class="vsd-panel" style="margin-bottom:20px;">
    <div class="vsd-header">
        <div class="vsd-title">System</div>
        <div class="vsd-updated"><?= htmlspecialchars($stats['hostname']) ?> &middot; <?= htmlspecialchars($stats['server_ip']) ?></div>
    </div>
    <div class="vsd-sys-grid">
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">PHP</div>
            <div class="vsd-sys-val"><?= htmlspecialchars($stats['php_version']) ?></div>
        </div>
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">Load (1/5/15m)</div>
            <div class="vsd-sys-val" style="font-size:.85rem;"><?= htmlspecialchars($stats['load']) ?></div>
        </div>
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">Memory (<?= $stats['mem_pct'] ?>%)</div>
            <div class="vsd-sys-val" style="font-size:.8rem;"><?= htmlspecialchars($stats['memory']) ?></div>
        </div>
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">Uptime</div>
            <div class="vsd-sys-val"><?= htmlspecialchars($stats['uptime']) ?></div>
        </div>
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">Pages</div>
            <div class="vsd-sys-val" style="font-size:1.4rem;color:#5bff00;"><?= (int)$stats['pages'] ?></div>
        </div>
        <div class="vsd-sys-card">
            <div class="vsd-sys-label">Galleries</div>
            <div class="vsd-sys-val" style="font-size:.85rem;"><?= htmlspecialchars($stats['galleries']) ?></div>
        </div>
    </div>
    <?php if ($diskTotal && $diskFree): ?>
    <div style="margin-top:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
            <span class="vsd-sys-label" style="margin:0;">Disk Usage</span>
            <span style="font-size:.85rem;color:#ccc;font-weight:600;font-family:'Courier New',monospace;"><?= $diskPct ?>%</span>
        </div>
        <div class="vsd-disk-meter"><div class="vsd-disk-fill" style="width:<?= $diskPct ?>%"></div></div>
        <div class="vsd-disk-legend">
            <span style="color:#f87171"><?= fmt_bytes($diskUsed) ?></span>
            <span style="color:#4ade80"><?= fmt_bytes($diskFree) ?></span>
            <span style="color:#facc15"><?= fmt_bytes($diskTotal) ?></span>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- DashboardStatsOG — lazy-loaded via AJAX to avoid blocking login -->
<div id="dsog-lazy-container">
  <div class="dsog-panel" style="background:linear-gradient(145deg,rgba(10,10,25,0.7),rgba(15,20,40,0.6));border:1px solid rgba(91,255,0,0.15);border-radius:12px;overflow:hidden;margin-bottom:20px;box-shadow:0 4px 30px rgba(0,0,0,.4);">
    <div style="padding:40px 24px;text-align:center;">
      <div style="display:inline-block;width:20px;height:20px;border:2px solid rgba(91,255,0,0.3);border-top-color:#5bff00;border-radius:50%;animation:dsog-spin .8s linear infinite;margin-bottom:10px;"></div>
      <div style="font:600 .8rem system-ui;color:#888;letter-spacing:.05em;">Loading site stats&hellip;</div>
    </div>
  </div>
</div>
<style>@keyframes dsog-spin{to{transform:rotate(360deg)}}</style>
<!-- Shared mountain-chart component (lazy-loads Chart.js on first render) -->
<script src="/admin/shared/charts/mountain-chart.js"></script>
<script>
(function(){
  var c = document.getElementById('dsog-lazy-container');
  if (!c) return;
  var API = '/admin/modules/Dashboard/api.php?action=';
  var curRange = new URLSearchParams(window.location.search).get('dsog_range') || '7d';

  // Map each nav target to the tab it belongs under (month detail lives under
  // Monthly Details). Anything unmapped highlights its own matching tab.
  var TAB_OF = {overview:'overview', year:'year', months:'months', month:'months'};
  function setActiveTab(nav){
    var want = TAB_OF[nav] || nav;
    var tabs = c.querySelectorAll('.dsog-tab');
    for (var i = 0; i < tabs.length; i++) {
      tabs[i].classList.toggle('active', tabs[i].getAttribute('data-dsog-nav') === want);
    }
  }

  // Swap the tab body in place for tab clicks, month cards, year arrows, back links.
  function loadFragment(nav, el){
    var body = document.getElementById('dsog-tabbody');
    if (!body) return;
    var url = API + 'stats_' + nav;
    if (nav === 'overview') url += '&dsog_range=' + encodeURIComponent((el && el.getAttribute('data-dsog-range')) || curRange);
    if (nav === 'year')     url += '&year=' + encodeURIComponent((el && el.getAttribute('data-year')) || '');
    if (nav === 'months')   url += '&year=' + encodeURIComponent((el && el.getAttribute('data-year')) || '');
    if (nav === 'month')    url += '&ym=' + encodeURIComponent((el && el.getAttribute('data-ym')) || '');
    body.style.opacity = '.4';
    fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function(html){
        body.innerHTML = html;
        body.style.opacity = '1';
        setActiveTab(nav);
        // Scripts injected via innerHTML do not execute — drive charts from here.
        if (window.LuminalMountain) window.LuminalMountain.renderAll(body);
      })
      .catch(function(e){ body.style.opacity = '1'; body.innerHTML = '<div style="color:#f87171;padding:20px;font-size:.85rem">Could not load ('+e+')</div>'; });
  }

  // Delegated nav. Plain range-bar / refresh links carry no data-dsog-nav, so
  // they fall through to a normal full-page reload (intended).
  c.addEventListener('click', function(e){
    var el = e.target.closest ? e.target.closest('[data-dsog-nav]') : null;
    if (!el || !c.contains(el)) return;
    if (el.classList.contains('disabled')) { e.preventDefault(); return; }
    e.preventDefault();
    loadFragment(el.getAttribute('data-dsog-nav'), el);
  });

  // Initial load — full shell (header + tabs + overview body).
  fetch(API + 'stats_panel&dsog_range=' + encodeURIComponent(curRange), {credentials:'same-origin'})
    .then(function(r){ return r.ok ? r.text() : Promise.reject(r.status); })
    .then(function(html){
      c.innerHTML = html;
      if (window.LuminalMountain) window.LuminalMountain.renderAll(c);
    })
    .catch(function(e){ c.innerHTML = '<div style="color:#f87171;padding:16px;font-size:.85rem">Stats unavailable ('+e+')</div>'; });
})();
</script>

          </div>

    </div>

</body>
</html>
