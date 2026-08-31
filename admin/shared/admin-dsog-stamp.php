<?php
/**
 * DashboardStatsOG — Admin Rail Postage Stamp Widget
 * Included from admin/includes/admin_menu.php, above View Site.
 * Reads from DashboardStatsOG cache (15-min TTL). Never blocks.
 *
 * File: admin/shared/admin-dsog-stamp.php
 */

if (!defined('SITE_ROOT')) return;

// Load DashboardStatsOG functions if not already loaded
if (!function_exists('dsog_get_stats')) {
    $dsogEngine = SITE_ROOT . '/admin/modules/Dashboard/DashboardStatsOG.php';
    if (!is_file($dsogEngine)) return;
    require_once $dsogEngine;
}

$_stDomain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$_stRange  = '7d'; // stamp always shows 7-day view
$_stStats  = dsog_get_stats($_stDomain, $_stRange);

echo dsog_render_stamp($_stStats, $_stDomain, true);
