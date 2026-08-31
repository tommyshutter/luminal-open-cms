<?php
/**
 * Dashboard API — AJAX endpoints
 *
 * Currently serves:
 *   ?action=stats_panel  — returns DashboardStatsOG panel HTML (lazy-loaded)
 *
 * Auth: requires admin session (same as Dashboard.php)
 */
require_once dirname(__DIR__, 2) . '/auth.php';
requireAuth();

$action = $_GET['action'] ?? '';

// All stats actions render an HTML fragment for the lazy-loaded analytics panel.
// stats_panel = full shell (initial load); the rest are tab/drill-down fragments
// the parent dashboard swaps into #dsog-tabbody.
$statsActions = ['stats_panel', 'stats_overview', 'stats_year', 'stats_months', 'stats_month'];
if (in_array($action, $statsActions, true)) {
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/DashboardStatsOG.php';
    $domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

    switch ($action) {
        case 'stats_panel':
            dsog_render_panel();
            break;
        case 'stats_overview':
            $range = $_GET['dsog_range'] ?? '7d';
            dsog_render_overview($domain, $range);
            break;
        case 'stats_year':
            $year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : '';
            dsog_render_year($domain, $year);
            break;
        case 'stats_months':
            $year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : '';
            dsog_render_months($domain, $year);
            break;
        case 'stats_month':
            dsog_render_month_detail($domain, (string)($_GET['ym'] ?? ''));
            break;
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
