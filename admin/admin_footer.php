<?php
/**
 * @file /admin/admin_footer.php
 * @desc Closes the HTML structure opened by admin_header.php.
 */
?>
            </div><!-- /.content-area -->
    </div><!-- /.admin-container -->

<!-- Admin ? help button injected via admin_menu.php (loads on every admin page) -->

<?php
/* Shared admin-shell utilities — guarded so a site missing either is unaffected.
 * Both files cache-bust on their own mtime. */
$__lum_root = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__);

/* Upload progress meter — consistent per-file telemetry across every uploader. */
$__up_js  = $__lum_root . '/admin/shared/upload-progress/upload-progress.js';
$__up_css = $__lum_root . '/admin/shared/upload-progress/upload-progress.css';
if (is_file($__up_js) && is_file($__up_css)) {
    $__upv = max((int)@filemtime($__up_js), (int)@filemtime($__up_css)) ?: time();
    echo '<link rel="stylesheet" href="/admin/shared/upload-progress/upload-progress.css?v=' . $__upv . '">' . "\n";
    echo '<script src="/admin/shared/upload-progress/upload-progress.js?v=' . $__upv . '"></script>' . "\n";
}

/* Idle auto-logout — "Are you still here?" countdown → server logout.
 * Tuned per-site from site-settings.json → admin_idle_minutes (default 15, clamped 5–1440).
 * The LUM_IDLE hook has existed since this component shipped but was never fed, so every
 * site ran the hardcoded 15 min — which silently overrode "Stay signed in for up to 24h"
 * and was the real cause of the long-standing stale-page/"Save failed" irritant. The two
 * settings are now BOTH explicit and BOTH honest: total ceiling + idle cutoff. (2026-07-19)
 * MUST be emitted BEFORE the script — idle-logout.js reads window.LUM_IDLE at load. */
$__idle_js  = $__lum_root . '/admin/shared/idle-logout/idle-logout.js';
$__idle_css = $__lum_root . '/admin/shared/idle-logout/idle-logout.css';
if (is_file($__idle_js) && is_file($__idle_css)) {
    $__idleMin = 15;
    $__ssFile  = $__lum_root . '/admin/data/site-settings.json';
    if (is_file($__ssFile)) {
        $__ssCfg = json_decode((string)@file_get_contents($__ssFile), true);
        $__m = (int)($__ssCfg['admin_idle_minutes'] ?? 0);
        if ($__m >= 5 && $__m <= 1440) $__idleMin = $__m;
    }
    $__idv = max((int)@filemtime($__idle_js), (int)@filemtime($__idle_css)) ?: time();
    echo '<script>window.LUM_IDLE = ' . json_encode(['idleMs' => $__idleMin * 60000],
         JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n";
    echo '<link rel="stylesheet" href="/admin/shared/idle-logout/idle-logout.css?v=' . $__idv . '">' . "\n";
    echo '<script src="/admin/shared/idle-logout/idle-logout.js?v=' . $__idv . '"></script>' . "\n";
}

/* Session-expiry notifier — an expired session makes guard_require_auth() answer XHRs with
 * HTTP 401 + {"ok":false,...}, but module JS tests r.success, so every module reports its own
 * generic "…failed" and hides the real cause. This watches for 401 on any admin request and
 * says so plainly. Passive: inspects status only, never touches response bodies. */
$__sxp_js  = $__lum_root . '/admin/shared/session-expiry/session-expiry.js';
$__sxp_css = $__lum_root . '/admin/shared/session-expiry/session-expiry.css';
if (is_file($__sxp_js) && is_file($__sxp_css)) {
    $__sxpv = max((int)@filemtime($__sxp_js), (int)@filemtime($__sxp_css)) ?: time();
    echo '<link rel="stylesheet" href="/admin/shared/session-expiry/session-expiry.css?v=' . $__sxpv . '">' . "\n";
    echo '<script src="/admin/shared/session-expiry/session-expiry.js?v=' . $__sxpv . '"></script>' . "\n";
}

/* Password reveal — 👁 show/hide toggle auto-attached to every password field. */
$__pwr_js  = $__lum_root . '/admin/shared/password-reveal/password-reveal.js';
$__pwr_css = $__lum_root . '/admin/shared/password-reveal/password-reveal.css';
if (is_file($__pwr_js) && is_file($__pwr_css)) {
    $__pwrv = max((int)@filemtime($__pwr_js), (int)@filemtime($__pwr_css)) ?: time();
    echo '<link rel="stylesheet" href="/admin/shared/password-reveal/password-reveal.css?v=' . $__pwrv . '">' . "\n";
    echo '<script src="/admin/shared/password-reveal/password-reveal.js?v=' . $__pwrv . '"></script>' . "\n";
}

/* CMS-wide Ctrl/Cmd+S save — kills the browser "save page" dialog and triggers the
 * current context's save ([data-cms-save], else a careful Save-button heuristic). */
$__sh_js = $__lum_root . '/admin/shared/save-hotkey/save-hotkey.js';
if (is_file($__sh_js)) {
    $__shv = (int)@filemtime($__sh_js) ?: time();
    echo '<script src="/admin/shared/save-hotkey/save-hotkey.js?v=' . $__shv . '"></script>' . "\n";
}
?>
</body>
</html>
