<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: SiteSettings v1.0.0
 * File: api.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$user = guard_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

require_once __DIR__ . '/SiteSettingsFunctions.php';

header('Content-Type: application/json');

function ok(mixed $data = null): never {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function bad(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        /* --- Read -------------------------------------------------- */

        case 'load':
            $file = $_GET['file'] ?? '';
            ok(ss_load_settings($file));

        case 'get_pages':
            ok(['pages' => ss_get_pages()]);

        /* --- Write ------------------------------------------------- */

        case 'save': {
            $file     = $_POST['file'] ?? '';
            $jsonData = $_POST['data'] ?? '';
            ss_save_settings($file, $jsonData);
            // READ IT BACK. ss_save_settings throws on a failed write, but a
            // silent no-op (wrong path, a write that lands somewhere else) used
            // to be indistinguishable from success at the UI. Return what is
            // actually on disk so the admin can see it — and so "I added a
            // TikTok link and it never showed up" is answerable in one glance.
            $onDisk = ss_load_settings($file);
            ok([
                'footer'   => $onDisk['footer'] ?? null,
                'saved_at' => date('Y-m-d H:i:s T', (int)@filemtime(ss_settings_path($file))),
                'bytes'    => (int)@filesize(ss_settings_path($file)),
            ]);
        }

        // Icon files this site can offer in the footer Social Links picker.
        // Sourced from /panels/footer_images/, which ships with every site and
        // is never purged — so the list needs no upload step to be useful.
        case 'footer_icons': {
            require_once SITE_ROOT . '/includes/social_icons.php';
            ok(['icons' => luminal_footer_icon_choices(SITE_ROOT)]);
        }

        // Single-field write for the Dashboard's "Stay signed in for" strip — the same
        // admin_session_hours the Site Settings → Admin Session panel writes, exposed up
        // front so it's reachable without digging. Clamped 1–24h to match the read side in
        // admin/includes/session_boot.php (anything outside the range there falls back to 12h).
        case 'set_session_hours': {
            $hrs = (int)($_POST['hours'] ?? 0);
            if ($hrs < 1 || $hrs > 24) bad('hours must be 1–24');
            $settings = ss_load_settings('site-settings.json');
            if (!is_array($settings)) $settings = [];
            $settings['admin_session_hours'] = $hrs;
            ss_save_settings('site-settings.json', json_encode($settings));

            // Apply to the CURRENT session immediately rather than waiting for re-login.
            // Re-issuing the cookie with the SAME session id and a new expiry extends the
            // browser-side lifetime without destroying the session — the id is unchanged, so
            // nothing is logged out. Cookie params are copied from the live session so we
            // overwrite the existing cookie instead of creating a second one.
            $expires = time() + ($hrs * 3600);
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => $expires,
                    'path'     => $p['path'] ?: '/',
                    'domain'   => $p['domain'] ?? '',
                    'secure'   => !empty($p['secure']),
                    'httponly' => true,
                    'samesite' => $p['samesite'] ?: 'Lax',
                ]);
                $_SESSION['admin_session_expires'] = $expires;
            }
            ok(['admin_session_hours' => $hrs, 'expires' => $expires]);
        }

        case 'upload_logo':
            if (!isset($_FILES['logo'])) {
                bad('No file uploaded');
            }
            ok(ss_upload_logo($_FILES['logo']));

        case 'generate_og':
            $logoPath = $_POST['logo_path'] ?? '';
            ok(ss_generate_og($logoPath));

        /* --- Maintenance ------------------------------------------- */

        case 'fix_permissions':
            ok(['output' => ss_fix_permissions()]);

        case 'recreate_paths':
            ok(['output' => ss_recreate_paths()]);

        /* --- Dashboard Stats Config --------------------------------- */

        case 'get_stats_config': {
            $cfgDir  = SITE_ROOT . '/admin/data/DashboardStatsOG';
            $cfgFile = $cfgDir . '/config.json';
            $cfg = [];
            if (is_file($cfgFile)) {
                $raw = @file_get_contents($cfgFile);
                if ($raw) $cfg = json_decode($raw, true) ?: [];
            }
            // Detect log files (reuse DashboardStatsOG helper if available)
            $domain  = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $detected = [];
            $dsogFile = SITE_ROOT . '/admin/modules/Dashboard/DashboardStatsOG.php';
            if (is_file($dsogFile)) {
                require_once $dsogFile;
                if (function_exists('dsog_find_log_files')) {
                    $detected = dsog_find_log_files($domain);
                }
            }
            ok(['config' => $cfg, 'detected' => $detected]);
        }

        case 'save_stats_config': {
            $cfgDir  = SITE_ROOT . '/admin/data/DashboardStatsOG';
            $cfgFile = $cfgDir . '/config.json';
            if (!is_dir($cfgDir)) mkdir($cfgDir, 0775, true);
            $cfg = [];
            if (is_file($cfgFile)) {
                $raw = @file_get_contents($cfgFile);
                if ($raw) $cfg = json_decode($raw, true) ?: [];
            }
            $customPath = trim($_POST['custom_log_path'] ?? '');
            if ($customPath) $cfg['custom_log_path'] = $customPath;
            else unset($cfg['custom_log_path']);
            $cpUser = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['cpanel_user'] ?? ''));
            if ($cpUser) $cfg['cpanel_user'] = $cpUser;
            else unset($cfg['cpanel_user']);
            file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            // Bust stats cache
            $domain   = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $safeDom  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $domain);
            $cacheDir = SITE_ROOT . '/admin/data/DashboardStatsOG';
            foreach (['today','7d','30d','this_month'] as $r) {
                $cp = $cacheDir . '/' . $safeDom . '_' . $r . '.json';
                if (is_file($cp)) @unlink($cp);
            }
            ok(['config' => $cfg, 'message' => 'Config saved and cache cleared']);
        }

        case 'clear_stats_cache': {
            $domain   = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $safeDom  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $domain);
            $cacheDir = SITE_ROOT . '/admin/data/DashboardStatsOG';
            $cleared  = 0;
            foreach (['today','7d','30d','this_month'] as $r) {
                $cp = $cacheDir . '/' . $safeDom . '_' . $r . '.json';
                if (is_file($cp)) { @unlink($cp); $cleared++; }
            }
            ok(['cleared' => $cleared, 'message' => $cleared > 0 ? "Cleared {$cleared} cache file(s)" : 'Cache already empty']);
        }

        /* --- SEO Scanner ------------------------------------------- */

        case 'seo_scan':
            require_once __DIR__ . '/SeoScanner.php';
            ok(['pages' => seo_scan_pages()]);

        case 'seo_generate':
            require_once __DIR__ . '/SeoScanner.php';
            $pages = seo_scan_pages();
            $suggestions = seo_generate_descriptions($pages);
            ok(['pages' => $pages, 'suggestions' => $suggestions]);

        case 'seo_generate_one':
            require_once __DIR__ . '/SeoScanner.php';
            $slug = $_POST['slug'] ?? '';
            if (!$slug) bad('Missing slug');
            $res = seo_auto_generate_one($slug);
            ok($res);

        case 'seo_apply':
            require_once __DIR__ . '/SeoScanner.php';
            $slug = $_POST['slug'] ?? '';
            $desc = $_POST['description'] ?? '';
            if (!$slug || !$desc) bad('Missing slug or description');
            if (!seo_apply_description($slug, $desc)) bad('Failed to apply description');
            ok(['slug' => $slug]);

        case 'seo_apply_all':
            require_once __DIR__ . '/SeoScanner.php';
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items) || empty($items)) bad('No items provided');
            $applied = 0;
            $errors = [];
            foreach ($items as $item) {
                $s = $item['slug'] ?? '';
                $d = $item['description'] ?? '';
                if ($s && $d) {
                    if (seo_apply_description($s, $d)) {
                        $applied++;
                    } else {
                        $errors[] = $s;
                    }
                }
            }
            ok(['applied' => $applied, 'errors' => $errors]);

        /* --- The Vault (llms.txt) ---------------------------------- */

        case 'vault_import_fb': {
            require_once SITE_ROOT . '/includes/llms.php';
            $fb = llms_import_facebook();
            if (isset($fb['error'])) bad($fb['error']);
            $settings = ss_load_settings('site-settings.json');
            $settings['llms_sources'] = is_array($settings['llms_sources'] ?? null) ? $settings['llms_sources'] : [];
            $settings['llms_sources']['facebook'] = $fb;
            ss_save_settings('site-settings.json', json_encode($settings));
            ok(['facebook' => $fb]);
        }

        case 'vault_gen_about': {
            require_once SITE_ROOT . '/includes/llms.php';
            $r = llms_generate_briefing();   // full multi-paragraph briefing document
            if (isset($r['error'])) bad($r['error']);
            ok(['about' => $r['briefing']]);
        }

        case 'vault_save_regen': {
            require_once SITE_ROOT . '/includes/llms.php';
            $settings = ss_load_settings('site-settings.json');
            if (array_key_exists('llms_about', $_POST)) $settings['llms_about'] = trim((string)$_POST['llms_about']);
            ss_save_settings('site-settings.json', json_encode($settings));
            $r = function_exists('llms_write')
                ? llms_write()
                : (function () { @file_put_contents(SITE_ROOT.'/llms.txt', render_llms(false));
                                 @file_put_contents(SITE_ROOT.'/llms-full.txt', render_llms(true));
                                 return ['idx'=>(int)@filesize(SITE_ROOT.'/llms.txt'),'full'=>(int)@filesize(SITE_ROOT.'/llms-full.txt')]; })();
            ok($r);
        }

        // Vault freshness status — powers the Site Settings status strip (last generated, sizes,
        // whether content has changed since, and the auto-refresh opt-in state).
        case 'vault_status': {
            require_once SITE_ROOT . '/includes/llms.php';
            $s = ss_load_settings('site-settings.json');
            ok([
                'last_gen'     => function_exists('llms_last_gen') ? llms_last_gen() : (int)@filemtime(SITE_ROOT.'/llms.txt'),
                'idx'          => (int)@filesize(SITE_ROOT . '/llms.txt'),
                'full'         => (int)@filesize(SITE_ROOT . '/llms-full.txt'),
                'stale'        => function_exists('llms_is_stale') ? llms_is_stale() : false,
                'auto_refresh' => !array_key_exists('llms_auto_refresh', $s) || (bool)$s['llms_auto_refresh'],
                'now'          => time(),
            ]);
        }

        // Plain "Refresh now" — regenerate from current content (no About edit required).
        case 'vault_refresh': {
            require_once SITE_ROOT . '/includes/llms.php';
            $r = function_exists('llms_write')
                ? llms_write()
                : (function () { @file_put_contents(SITE_ROOT.'/llms.txt', render_llms(false));
                                 @file_put_contents(SITE_ROOT.'/llms-full.txt', render_llms(true));
                                 return ['idx'=>(int)@filesize(SITE_ROOT.'/llms.txt'),'full'=>(int)@filesize(SITE_ROOT.'/llms-full.txt'),'last_gen'=>(int)@filemtime(SITE_ROOT.'/llms.txt')]; })();
            ok($r);
        }

        // Toggle the auto-refresh opt-in (default ON). Off = this site is skipped by the
        // staleness cron (still manually refreshable).
        case 'vault_set_auto': {
            $settings = ss_load_settings('site-settings.json');
            $settings['llms_auto_refresh'] = !empty($_POST['auto_refresh']) && $_POST['auto_refresh'] !== 'false' && $_POST['auto_refresh'] !== '0';
            ss_save_settings('site-settings.json', json_encode($settings));
            ok(['auto_refresh' => (bool)$settings['llms_auto_refresh']]);
        }

        default:
            bad('Unknown action: ' . $action);
    }
} catch (\Throwable $e) {
    bad($e->getMessage());
}
