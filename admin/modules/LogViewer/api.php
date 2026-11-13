<?php
/**
 * LogViewer — API
 *
 * Handles all AJAX requests for the log viewer module.
 *
 * @package  LuminalCMS
 * @module   LogViewer
 * @version  1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once __DIR__ . '/LogViewerFunctions.php';

header('Content-Type: application/json; charset=utf-8');

function lv_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Auth check — all actions require admin
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {

    // ---------------------------------------------------------------
    //  Dashboard stats + threat analysis
    // ---------------------------------------------------------------
    case 'dashboard':
        $range = trim($_GET['range'] ?? 'today');
        if (!in_array($range, ['today', '24h', '7d', '30d'])) $range = 'today';
        $force = !empty($_GET['force']);
        if ($force) {
            // Clear cache
            $cacheFile = lv_data_dir() . '/cache/dashboard_' . $range . '.json';
            if (file_exists($cacheFile)) @unlink($cacheFile);
        }
        $data = lv_analyze_dashboard($range);
        lv_json(['success' => true, 'data' => $data]);
        break;

    // ---------------------------------------------------------------
    //  Error log analysis
    // ---------------------------------------------------------------
    case 'errors':
        $range = trim($_GET['range'] ?? 'today');
        if (!in_array($range, ['today', '24h', '7d', '30d'])) $range = 'today';
        $data = lv_analyze_errors($range);
        lv_json(['success' => true, 'data' => $data]);
        break;

    // ---------------------------------------------------------------
    //  Discover available log files
    // ---------------------------------------------------------------
    case 'discover_logs':
        $logs = lv_discover_logs();
        lv_json(['success' => true, 'data' => $logs]);
        break;

    // ---------------------------------------------------------------
    //  Get log snippet (paginated)
    // ---------------------------------------------------------------
    case 'get_snippet':
        $logPath = trim($_GET['logpath'] ?? '');
        $lines   = (int) ($_GET['lines'] ?? 200);
        $page    = (int) ($_GET['page'] ?? 0);
        if (empty($logPath)) lv_json(['success' => false, 'message' => 'Log path required.'], 400);
        $result = lv_get_snippet($logPath, $lines, $page);
        lv_json($result);
        break;

    // ---------------------------------------------------------------
    //  Get filtered lines (server-side filter for card drill-down)
    // ---------------------------------------------------------------
    case 'get_filtered_lines':
        $logPath = trim($_GET['logpath'] ?? '');
        $filter  = trim($_GET['filter'] ?? '');
        $limit   = (int) ($_GET['limit'] ?? 500);
        if (empty($logPath)) lv_json(['success' => false, 'message' => 'Log path required.'], 400);
        if (empty($filter)) lv_json(['success' => false, 'message' => 'Filter required.'], 400);
        $result = lv_get_filtered_lines($logPath, $filter, min($limit, 2000));
        lv_json($result);
        break;

    // ---------------------------------------------------------------
    //  Get new lines since offset (live tail)
    // ---------------------------------------------------------------
    case 'get_new_lines':
        $logPath = trim($_GET['logpath'] ?? '');
        $offset  = (int) ($_GET['offset'] ?? 0);
        if (empty($logPath)) lv_json(['success' => false, 'message' => 'Log path required.'], 400);
        $result = lv_get_new_lines($logPath, $offset);
        lv_json($result);
        break;

    // ---------------------------------------------------------------
    //  Resolve IP to hostname
    // ---------------------------------------------------------------
    case 'resolve_ip':
        $ip = trim($_GET['ip'] ?? '');
        if (empty($ip)) lv_json(['success' => false, 'message' => 'IP required.'], 400);
        $hostname = lv_resolve_ip($ip);
        lv_json(['success' => true, 'hostname' => $hostname, 'ip' => $ip]);
        break;

    // ---------------------------------------------------------------
    //  Save config
    // ---------------------------------------------------------------
    case 'save_config':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) lv_json(['success' => false, 'message' => 'Invalid JSON.'], 400);

        $cfg = lv_load_config();
        if (isset($input['cache_ttl'])) $cfg['cache_ttl'] = max(60, (int) $input['cache_ttl']);
        // Re-detect logs from Apache if requested
        if (!empty($input['redetect'])) {
            $detected = lv_detect_logs_from_apache();
            $cfg['access_logs'] = $detected['access'];
            $cfg['error_logs'] = $detected['error'];
        }
        lv_save_config($cfg);

        // Clear cache on config change
        $cacheDir = lv_data_dir() . '/cache';
        foreach (glob($cacheDir . '/dashboard_*.json') as $f) @unlink($f);

        lv_json(['success' => true, 'message' => 'Config saved.']);
        break;

    // ---------------------------------------------------------------
    //  Get config
    // ---------------------------------------------------------------
    case 'get_config':
        lv_json(['success' => true, 'data' => lv_load_config()]);
        break;

    // ---------------------------------------------------------------
    //  Clear cache
    // ---------------------------------------------------------------
    case 'clear_cache':
        $cacheDir = lv_data_dir() . '/cache';
        $cleared = 0;
        foreach (glob($cacheDir . '/*.json') as $f) {
            @unlink($f);
            $cleared++;
        }
        lv_json(['success' => true, 'message' => "Cleared $cleared cache files."]);
        break;

    default:
        lv_json(['success' => false, 'message' => 'Unknown action.'], 404);
}
