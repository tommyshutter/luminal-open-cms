<?php
/**
 * Tech Support — Server API
 * Path: /admin/modules/TechSupport/api.php
 *
 * Public endpoints (site_key auth):
 *   submit_ticket — receive a ticket from any CMS site
 *
 * Dashboard endpoints (session auth):
 *   list_tickets  — list all tickets (filterable, supports archived view)
 *   get_ticket    — get single ticket by ID
 *   update_status — change ticket status
 *   add_note      — add internal note
 *   resolve_ticket— mark resolved with resolution text
 *   delete_ticket — move to archive (preserves data)
 *   permanently_delete_ticket — permanent removal (file + screenshot + agent tasks)
 *   stats         — aggregate counts
 *
 * Version: 2026.03.07.r2
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/lib/SafeIO.php';

$TS_INBOX_DIR   = SITE_ROOT . '/admin/data/support_tickets/inbox';
$TS_ARCHIVE_DIR = SITE_ROOT . '/admin/data/support_tickets/archive';
$TS_SCREENS_DIR = SITE_ROOT . '/admin/data/support_tickets/screenshots';
$TS_RATE_DIR      = SITE_ROOT . '/admin/data/support_tickets/.ratelimit';
$TS_GRAVEYARD_DIR = SITE_ROOT . '/admin/data/support_tickets/graveyard';
$TS_DATA_DIR      = SITE_ROOT . '/admin/data/support_tickets';

// Search index (lazy-loaded)
require_once __DIR__ . '/TicketIndex.php';
function ts_get_index(): TicketIndex {
    static $idx = null;
    if ($idx === null) {
        $idx = new TicketIndex($GLOBALS['TS_DATA_DIR']);
    }
    return $idx;
}

// Ensure directories exist
foreach ([$TS_INBOX_DIR, $TS_ARCHIVE_DIR, $TS_SCREENS_DIR, $TS_RATE_DIR, $TS_GRAVEYARD_DIR] as $d) {
  if (!is_dir($d)) @mkdir($d, 0775, true);
}

/* ---------- CORS for cross-origin ticket submission ---------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ---------- helpers ---------- */
function ts_api_json_ok(array $data = []): void {
  echo json_encode(array_merge(['ok' => true], $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

function ts_api_json_err(string $error, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
  exit;
}

function ts_load_site_registry(): array {
  return safe_load_registry();
}

function ts_validate_site_auth(string $domain, string $siteKey): bool {
  $registry = ts_load_site_registry();

  // Find domain in registry — try exact, www variant, and TLD variants (.com/.pro)
  $entry = null;
  $candidates = [$domain];
  $candidates[] = str_starts_with($domain, 'www.') ? substr($domain, 4) : 'www.' . $domain;
  // Try swapping common TLD variants
  foreach (['.com' => '.pro', '.pro' => '.com'] as $from => $to) {
    if (str_ends_with($domain, $from)) {
      $candidates[] = substr($domain, 0, -strlen($from)) . $to;
    }
  }
  foreach ($candidates as $c) {
    if (isset($registry[$c]) && is_array($registry[$c])) {
      $entry = $registry[$c];
      break;
    }
  }

  // If site has a site_key, it MUST match (remote sites)
  if ($entry && !empty($entry['site_key'])) {
    return hash_equals($entry['site_key'], $siteKey);
  }

  // Known site without a site_key: accept (local sites)
  if ($entry) return true;

  // Unknown domain: check if request comes from same server (local vhost)
  $vhostDir = '/var/www/vhosts/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $domain);
  if (is_dir($vhostDir)) return true;

  return false;
}

function ts_check_rate_limit(string $domain): bool {
  global $TS_RATE_DIR;
  $file = $TS_RATE_DIR . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $domain) . '.json';

  $now = time();
  $window = 3600; // 1 hour
  $maxTickets = 10;

  $data = is_file($file) ? json_decode(file_get_contents($file), true) : [];
  if (!is_array($data)) $data = [];

  // Remove entries older than window
  $data = array_filter($data, fn($t) => ($now - $t) < $window);

  if (count($data) >= $maxTickets) return false;

  $data[] = $now;
  @file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
  return true;
}

function ts_generate_ticket_id(): string {
  $date = date('Ymd');
  global $TS_INBOX_DIR, $TS_ARCHIVE_DIR;
  $baseDir = dirname($TS_INBOX_DIR); // support_tickets/

  // Scan all directories where ticket files can exist (inbox, archive, root, agent_tasks)
  $seq = 1;
  $scanDirs = [$TS_INBOX_DIR, $TS_ARCHIVE_DIR, $baseDir, $baseDir . '/agent_tasks'];
  foreach ($scanDirs as $dir) {
    foreach (glob($dir . "/TS-{$date}-*.json") as $f) {
      $base = basename($f, '.json');
      $parts = explode('-', $base);
      $n = (int)end($parts);
      if ($n >= $seq) $seq = $n + 1;
    }
  }
  return sprintf('TS-%s-%03d', $date, $seq);
}

function ts_save_ticket(array $ticket): bool {
  global $TS_INBOX_DIR;
  $file = $TS_INBOX_DIR . '/' . $ticket['id'] . '.json';
  $json = json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  $ok = (bool)@file_put_contents($file, $json, LOCK_EX);
  if ($ok) { try { ts_get_index()->indexTicket($ticket, 'inbox'); } catch (\Throwable $e) {} }
  return $ok;
}

function ts_save_screenshot(string $ticketId, string $base64): ?string {
  global $TS_SCREENS_DIR;
  // Parse data URI
  if (preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/s', $base64, $m)) {
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $data = base64_decode($m[2], true);
    if ($data === false || strlen($data) > 2 * 1024 * 1024) return null;
    $filename = $ticketId . '.' . $ext;
    $path = $TS_SCREENS_DIR . '/' . $filename;
    if (@file_put_contents($path, $data, LOCK_EX)) return $filename;
  }
  return null;
}

function ts_load_ticket(string $id): ?array {
  global $TS_INBOX_DIR, $TS_ARCHIVE_DIR;
  foreach ([$TS_INBOX_DIR, $TS_ARCHIVE_DIR] as $dir) {
    $file = $dir . '/' . $id . '.json';
    if (is_file($file)) {
      $data = json_decode(file_get_contents($file), true);
      if (is_array($data)) return $data;
    }
  }
  return null;
}

function ts_get_ticket_path(string $id): ?string {
  global $TS_INBOX_DIR, $TS_ARCHIVE_DIR;
  foreach ([$TS_INBOX_DIR, $TS_ARCHIVE_DIR] as $dir) {
    $file = $dir . '/' . $id . '.json';
    if (is_file($file)) return $file;
  }
  return null;
}

function ts_is_admin_session(): bool {
  if (session_status() === PHP_SESSION_NONE) @session_start();
  // Modern multi-user auth (UserManager)
  if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_role'])) return true;
  // Legacy single-user auth
  if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) return true;
  return false;
}

function ts_get_session_user(): string {
  if (!empty($_SESSION['user_id'])) {
    // Try to get display name from guard if loaded
    if (function_exists('guard_user')) {
      $u = guard_user();
      return $u['name'] ?? (string)$_SESSION['user_id'];
    }
    return (string)$_SESSION['user_id'];
  }
  return 'admin';
}

/* ====================================================================
 * Route request
 * ==================================================================== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw    = ($method === 'POST') ? (file_get_contents('php://input') ?: '{}') : '{}';
$input  = json_decode($raw, true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

/* ------------------------------------------------------------------
 * PUBLIC: submit_ticket (site_key auth)
 * ------------------------------------------------------------------ */
if ($action === 'submit_ticket' && $method === 'POST') {
  $domain  = $input['domain']   ?? '';
  $siteKey = $input['site_key'] ?? '';
  $ticket  = $input['ticket']   ?? [];

  if (!$domain) ts_api_json_err('Missing domain', 400);
  if (!is_array($ticket) || empty($ticket['subject'])) ts_api_json_err('Missing ticket subject', 400);

  // Auth
  if (!ts_validate_site_auth($domain, $siteKey)) {
    ts_api_json_err('Authentication failed', 403);
  }

  // Rate limit
  if (!ts_check_rate_limit($domain)) {
    ts_api_json_err('Rate limit exceeded (max 10/hour)', 429);
  }

  // Build ticket record
  $ticketId = ts_generate_ticket_id();
  $now = date('c');

  // Determine site origin: local vhost or remote server
  $hubHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
  $isLocal = is_dir('/var/www/vhosts/' . $domain);
  $siteOrigin = $isLocal ? $hubHost : 'remote';

  // Handle screenshot
  $screenshotFile = null;
  if (!empty($ticket['screenshot_base64'])) {
    $screenshotFile = ts_save_screenshot($ticketId, $ticket['screenshot_base64']);
  }

  $record = [
    'id'             => $ticketId,
    'submitted_at'   => $now,
    'updated_at'     => $now,
    'domain'         => $domain,
    'site_origin'    => $siteOrigin,
    'user'           => [
      'username'     => $ticket['user']['username'] ?? 'unknown',
      'display_name' => $ticket['user']['display_name'] ?? 'unknown',
      'role'         => $ticket['user']['role'] ?? 'user',
    ],
    'category'       => $ticket['category'] ?? 'ui_issue',
    'affected_areas' => $ticket['affected_areas'] ?? [],
    'urgency'        => $ticket['urgency'] ?? 'low',
    'subject'        => substr($ticket['subject'] ?? '', 0, 255),
    'description'    => substr($ticket['description'] ?? '', 0, 10000),
    'screenshot'     => $screenshotFile,
    'status'         => 'open',
    'status_history' => [
      ['status' => 'open', 'at' => $now, 'by' => 'system'],
    ],
    'notes'          => [],
    'resolution'     => null,
    'triage_mode'    => in_array($ticket['triage_mode'] ?? 'ai', ['ai','direct']) ? $ticket['triage_mode'] : 'ai',
    'direct_details' => substr($ticket['direct_details'] ?? '', 0, 10000),
    'cms_version'    => $ticket['cms_version'] ?? 'unknown',
    'php_version'    => $ticket['php_version'] ?? 'unknown',
    'user_agent'     => substr($ticket['user_agent'] ?? '', 0, 500),
  ];

  // Module Request qualifying details
  if (($ticket['category'] ?? '') === 'module_request' && !empty($ticket['module_request_details'])) {
    $mrd = $ticket['module_request_details'];
    $record['module_request_details'] = [
      'nature'          => in_array($mrd['nature'] ?? '', ['new_module','enhancement','integration']) ? $mrd['nature'] : 'new_module',
      'ai_integration'  => in_array($mrd['ai_integration'] ?? '', ['yes','no','unsure']) ? $mrd['ai_integration'] : 'no',
      'protocols'       => array_slice(array_filter(array_map('trim', $mrd['protocols'] ?? []), 'strlen'), 0, 10),
      'target_audience' => in_array($mrd['target_audience'] ?? '', ['all_sites','server_only','specific_client']) ? $mrd['target_audience'] : 'all_sites',
      'brief'           => substr($mrd['brief'] ?? '', 0, 2000),
    ];
  }

  // Page Content qualifying details
  if (($ticket['category'] ?? '') === 'page_content' && !empty($ticket['page_content_details'])) {
    $pcd = $ticket['page_content_details'];
    $validTypes = ['text_edit','graphics','theme','colors','feels_moods'];
    $record['page_content_details'] = [
      'site'         => substr($pcd['site'] ?? $domain, 0, 100),
      'page_slug'    => substr(preg_replace('/[^a-z0-9\-_]/', '', $pcd['page_slug'] ?? ''), 0, 100),
      'page_title'   => substr($pcd['page_title'] ?? '', 0, 200),
      'change_types' => array_values(array_filter(
        array_map('trim', $pcd['change_types'] ?? []),
        fn($v) => in_array($v, $validTypes)
      )),
      'description'  => substr($pcd['description'] ?? '', 0, 10000),
    ];
  }

  if (!ts_save_ticket($record)) {
    ts_api_json_err('Failed to save ticket', 500);
  }

  // ── CC: Claude — Telegram notification for every new ticket ──
  $tgLib = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/lib/TelegramNotify.php';
  if (is_file($tgLib)) {
    try {
      require_once $tgLib;
      if (function_exists('tg_notify_ticket')) {
        tg_notify_ticket($record);
      }
    } catch (\Throwable $tgErr) {
      error_log('TechSupport: tg_notify_ticket failed: ' . $tgErr->getMessage());
    }
  }

  // ── Auto-queue: Create AgentScheduler task for AI analysis ──
  // Skip AI triage when user chose "direct" mode — ticket goes straight to support team
  $agentTaskId = null;
  $agentStatus = null;
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
  $asModule  = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';

  if (($record['triage_mode'] ?? 'ai') !== 'direct' && is_dir($asDataDir) && is_file($asModule)) {
    try {
      require_once $asModule;
      $engine = new \AgentEngine($asDataDir);

      // Idempotency: skip if task already exists for this ticket
      if (!$engine->findTaskByTicket($ticketId)) {
        $catLabels = [
          'ui_issue' => 'UI Bug', 'ui_request' => 'UI Enhancement',
          'something_is_broken' => 'Bug Fix', 'edit_page_html_css' => 'Page Content Edit',
          'page_content' => 'Page Content Edit',
          'feature_request' => 'Feature Implementation',
          'module_request' => 'Module Development', 'question' => 'Investigation',
        ];
        $taskType = $catLabels[$record['category'] ?? ''] ?? 'Investigation';

        // ── Load Ticket Triage Agent from library for intelligent routing ──
        $triageAgent = null;
        $agentLibFile = SITE_ROOT . '/admin/modules/AgentScheduler/lib/AgentLibrary.php';
        if (is_file($agentLibFile)) {
          require_once $agentLibFile;
          $lib = new \AgentLibrary();
          $triageAgent = $lib->getAgent('agt_117'); // Ticket Triage Agent

          // Also try to find a category-specific agent
          $catAgentMap = [
            'ui_issue'           => 'agt_002', // Frontend Developer
            'ui_request'         => 'agt_002',
            'something_is_broken'=> 'agt_009', // Bug Hunter
            'page_content'       => 'agt_108', // Content Strategist
            'feature_request'    => 'agt_005', // System Architect
            'module_request'     => 'agt_005',
          ];
          $catAgentId = $catAgentMap[$record['category'] ?? ''] ?? null;
          $categoryAgent = $catAgentId ? $lib->getAgent($catAgentId) : null;
        }

        // Generate agent task docs
        $agentDocsDir = SITE_ROOT . '/admin/data/support_tickets/agent_tasks';
        if (!is_dir($agentDocsDir)) @mkdir($agentDocsDir, 0775, true);

        $taskJsonFile = $agentDocsDir . '/' . $ticketId . '.json';
        $taskDoc = [
          'task_id' => $ticketId, 'task_type' => $taskType,
          'category' => $record['category'] ?? '',
          'urgency' => $record['urgency'], 'domain' => $domain,
          'site_origin' => $siteOrigin,
          'subject' => $record['subject'], 'description' => $record['description'],
          'affected_modules' => $record['affected_areas'] ?? [],
          'cms_version' => $record['cms_version'], 'reported_by' => $record['user'],
          'submitted_at' => $now, 'status' => 'pending_agent',
          'exported_at' => $now, 'exported_by' => 'auto_submit',
        ];
        if (!empty($record['page_content_details'])) $taskDoc['page_content_details'] = $record['page_content_details'];
        if (!empty($record['module_request_details'])) $taskDoc['module_request_details'] = $record['module_request_details'];
        // Include agent library metadata in task doc
        if ($triageAgent) $taskDoc['triage_agent'] = ['id' => $triageAgent['id'], 'name' => $triageAgent['name']];
        if ($categoryAgent) $taskDoc['category_agent'] = ['id' => $categoryAgent['id'], 'name' => $categoryAgent['name']];
        @file_put_contents($taskJsonFile, json_encode($taskDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($taskJsonFile, 0664);

        // Create AgentScheduler task with library agent wiring
        $agentTaskId = 'task_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $taskConfig = [
          'source_ticket_id' => $ticketId,
          'ticket_json_path' => $taskJsonFile,
          'domain'           => $domain,
          'urgency'          => $record['urgency'],
          'affected_modules' => $record['affected_areas'] ?? [],
          'task_type'        => $taskType,
          'execute_pipeline' => 'ticket_execute',
          'apply_pipeline'   => 'ticket_apply',
          'auto_apply'       => 'full_auto',
        ];

        // Inject agent library system prompts
        if ($triageAgent && !empty($triageAgent['system_prompt'])) {
          $taskConfig['_agent_system_prompt'] = $triageAgent['system_prompt'];
          $taskConfig['_triage_agent_id'] = $triageAgent['id'];
        }
        if ($categoryAgent && !empty($categoryAgent['system_prompt'])) {
          $taskConfig['_category_agent_prompt'] = $categoryAgent['system_prompt'];
          $taskConfig['_category_agent_id'] = $categoryAgent['id'];
        }

        $agentTask = [
          'id'       => $agentTaskId,
          'name'     => 'Ticket: ' . substr($record['subject'], 0, 80),
          'pipeline' => 'ticket_analysis',
          'enabled'  => true,
          'schedule' => ['type' => 'manual'],
          'config'   => $taskConfig,
          'source'        => 'auto_submit',
          'source_ticket' => $ticketId,
          'agent_id'      => $triageAgent ? $triageAgent['id'] : null,
        ];
        $engine->saveTask($agentTask);

        // Run Phase 1 analysis immediately
        $analysisRun = $engine->runTask($agentTaskId);
        $agentStatus = $analysisRun['status'] ?? 'unknown';

        // Update ticket with agent info
        $record['status']            = 'in_progress';
        $record['agent_exported']    = true;
        $record['agent_task_id']     = $agentTaskId;
        $record['agent_exported_at'] = $now;
        $record['agent_exported_by'] = 'auto_submit';
        $record['updated_at']        = date('c');
        $record['status_history'][]  = [
          'status' => 'in_progress', 'at' => date('c'),
          'by' => 'auto_submit', 'note' => "Auto-queued agent task {$agentTaskId}",
        ];
        ts_save_ticket($record);
      }
    } catch (\Throwable $e) {
      // Non-fatal: ticket was saved, agent queue failed silently
      $agentStatus = 'error: ' . $e->getMessage();
    }
  }

  ts_api_json_ok([
    'ticket_id'     => $ticketId,
    'agent_task_id' => $agentTaskId,
    'agent_status'  => $agentStatus,
    'message'       => 'Ticket received' . ($agentTaskId ? ' — agent analysis queued' : ''),
  ]);
}

/* ------------------------------------------------------------------
 * PUBLIC: check_status (site_key auth) — sites poll for ticket updates
 * ------------------------------------------------------------------ */
if ($action === 'check_status' && $method === 'POST') {
  $domain    = $input['domain']   ?? '';
  $siteKey   = $input['site_key'] ?? '';
  $ticketIds = $input['ticket_ids'] ?? [];

  if (!$domain) ts_api_json_err('Missing domain', 400);
  if (!ts_validate_site_auth($domain, $siteKey)) {
    ts_api_json_err('Authentication failed', 403);
  }

  $results = [];
  foreach ($ticketIds as $tid) {
    if (!is_string($tid) || !preg_match('/^TS-\d+-\d+$/', $tid)) continue;
    $ticket = ts_load_ticket($tid);
    if (!$ticket) continue;
    // Only return tickets belonging to this domain
    if (($ticket['domain'] ?? '') !== $domain) continue;
    $results[$tid] = [
      'status'      => $ticket['status'] ?? 'unknown',
      'updated_at'  => $ticket['updated_at'] ?? '',
      'resolution'  => $ticket['resolution'] ?? null,
      'resolved_by' => $ticket['resolved_by'] ?? null,
      'resolved_at' => $ticket['resolved_at'] ?? null,
      'notes'       => array_map(fn($n) => [
        'text' => $n['text'] ?? '',
        'at'   => $n['at'] ?? '',
        'by'   => $n['by'] ?? '',
      ], $ticket['notes'] ?? []),
    ];
  }

  ts_api_json_ok(['tickets' => $results]);
}

/* ------------------------------------------------------------------
 * DASHBOARD ENDPOINTS (session auth required)
 * NOTE: Do NOT call requireAuth() here — it redirects to login HTML.
 *       Dashboard API must always return JSON.
 * ------------------------------------------------------------------ */
if (!in_array($action, ['submit_ticket', 'check_status'])) {
  if (!ts_is_admin_session()) {
    ts_api_json_err('Authentication required', 401);
  }
}

/* ── Helper: compute lifecycle stage from ticket + agent state ── */
function ts_compute_lifecycle_stage(array $ticket, ?array $agentInfo = null): string {
  $status = $ticket['status'] ?? 'open';
  $agentExported = !empty($ticket['agent_exported']);
  $ticketId = $ticket['id'] ?? '';
  $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $ticketId);

  // CLI-pending overrides everything except terminal
  if (!empty($ticket['cli_flagged'])) return 'cli_pending';

  // Terminal states first
  if ($status === 'resolved') return 'resolved';
  if (in_array($status, ['closed', 'wont_fix', 'archived'])) return 'closed';
  if ($status === 'awaiting_feedback') return 'awaiting_feedback';

  // Check agent error state FIRST (before checking files from prior runs)
  if ($agentInfo && $agentInfo['agent_led'] === 'error') {
    return 'error';
  }

  if ($ticketId) {
    // Check for apply manifest (changes applied to disk)
    $applyManifest = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . $safeId . '_apply_manifest.json';
    if (is_file($applyManifest)) {
      // If agent has a NEW plan awaiting approval, the manifest is stale from a prior run — skip
      if ($agentInfo && ($agentInfo['agent_status'] ?? '') === 'awaiting_approval') {
        // Fall through to plan_ready detection below
      } else {
        // Check if rolled back
        $mData = json_decode((string)file_get_contents($applyManifest), true);
        if (!empty($mData['rolled_back_at'])) {
          // Rolled back — show impl_ready (can re-apply)
          return 'impl_ready';
        }
        $verification = $ticket['verification'] ?? [];
        if (!empty($verification['completed'])) return 'verified';
        return 'applied';  // Applied, awaiting verification
      }
    }

    // Check for impl guide (guide exists, not yet applied)
    $implFile = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . $safeId . '_implementation.md';
    if (is_file($implFile)) {
      // Check agent status for active apply
      if ($agentInfo) {
        $agentStatus = $agentInfo['agent_status'] ?? '';
        // If agent is back in plan stage, the impl file is stale from a prior run — skip it
        if ($agentStatus === 'awaiting_approval') {
          // Fall through to plan_ready detection below
        } else {
          if ($agentStatus === 'apply_approved') return 'applying';
          if ($agentStatus === 'applied') {
            $verification = $ticket['verification'] ?? [];
            if (!empty($verification['completed'])) return 'verified';
            return 'applied';
          }
          if ($agentStatus === 'impl_ready') return 'impl_ready';
          // Agent in other states (completed, approved, running) with impl file = impl_ready
          $verification = $ticket['verification'] ?? [];
          if (!empty($verification['completed'])) return 'verified';
          return 'impl_ready';
        }
      } else {
        // No agent info but impl file exists
        $verification = $ticket['verification'] ?? [];
        if (!empty($verification['completed'])) return 'verified';
        return 'impl_ready';
      }
    }
  }

  // No agent involvement
  if (!$agentExported) return 'submitted';

  // Agent involved — use agent info to determine stage
  if ($agentInfo) {
    $agentStatus = $agentInfo['agent_status'] ?? '';
    $agentLed = $agentInfo['agent_led'] ?? '';

    if ($agentStatus === 'completed' || $agentStatus === 'impl_ready' || $agentLed === 'done' || $agentLed === 'impl_ready') {
      $verification = $ticket['verification'] ?? [];
      if (!empty($verification['completed'])) return 'verified';
      return 'impl_ready';
    }
    if ($agentStatus === 'approved') return 'approved';
    if ($agentStatus === 'awaiting_approval' || $agentStatus === 'rejected' || $agentLed === 'awaiting') return 'plan_ready';
    if ($agentStatus === 'running' || $agentLed === 'running') return 'analyzing';
  }

  return 'queued';
}

/* ── Helper: check if implementation file exists for ticket ── */
function ts_has_implementation(string $ticketId): bool {
  if (!$ticketId) return false;
  $implFile = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $ticketId) . '_implementation.md';
  return is_file($implFile);
}

/* ── Helper: resolve agent status from AgentScheduler task ── */
function ts_get_agent_status(string $agentTaskId): ?array {
  if (!$agentTaskId) return null;
  $taskFile = SITE_ROOT . '/admin/data/AgentScheduler/tasks/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $agentTaskId) . '.json';
  if (!is_file($taskFile)) return null;
  $task = json_decode((string)file_get_contents($taskFile), true);
  if (!is_array($task)) return null;

  // Determine effective status for the LED indicator
  $taskStatus = $task['status'] ?? '';
  $lastStatus = $task['last_status'] ?? '';

  if ($taskStatus === 'awaiting_approval') {
    $led = 'awaiting';
  } elseif ($taskStatus === 'approved' || $taskStatus === 'running' || $taskStatus === 'apply_approved') {
    $led = 'running';
  } elseif ($taskStatus === 'applied') {
    $led = 'applied';
  } elseif ($taskStatus === 'impl_ready') {
    $led = 'impl_ready';
  } elseif ($taskStatus === 'completed' || $lastStatus === 'success') {
    $led = 'done';
  } elseif ($lastStatus === 'error' || $taskStatus === 'error') {
    $led = 'error';
  } elseif ($taskStatus === 'rejected' || $lastStatus === 'plan_generated') {
    $led = 'awaiting';
  } else {
    $led = 'running'; // default for exported but no result yet
  }

  return [
    'agent_task_id'    => $agentTaskId,
    'agent_led'        => $led,
    'agent_status'     => $taskStatus ?: $lastStatus ?: 'pending',
    'agent_name'       => $task['name'] ?? '',
    'agent_last_run'   => $task['last_run'] ?? null,
    'ai_model'         => $task['last_ai_model'] ?? null,
    'ai_tokens'        => $task['last_ai_tokens'] ?? null,
    'last_error'       => $task['last_error'] ?? null,
    'last_failed_step' => $task['last_failed_step'] ?? null,
  ];
}

/* ── list_tickets ── */
if ($action === 'list_tickets') {
  $statusFilter   = $_GET['status']   ?? $input['status']   ?? '';
  $domainFilter   = $_GET['domain']   ?? $input['domain']   ?? '';
  $urgencyFilter  = $_GET['urgency']  ?? $input['urgency']  ?? '';
  $categoryFilter = $_GET['category'] ?? $input['category'] ?? '';
  $agentFilter    = $_GET['agent']    ?? $input['agent']    ?? '';
  $search         = $_GET['search']   ?? $input['search']   ?? '';
  $showAll      = ($statusFilter === '' || $statusFilter === 'all');
  $showArchived = ($statusFilter === 'archived');
  $showActive   = ($statusFilter === 'active');

  $tickets = [];
  $domains = [];    // Collect unique domains for filter population
  $categories = []; // Collect unique categories for filter population

  // When search is present, use FTS5 index to pre-filter matching IDs
  $searchMatchIds = null; // null = no search filter, array = restrict to these IDs
  if ($search) {
    try {
      $idx = ts_get_index();
      $idxFilters = [];
      if ($domainFilter) $idxFilters['domain'] = $domainFilter;
      if ($categoryFilter) $idxFilters['category'] = $categoryFilter;
      if ($showActive) $idxFilters['location'] = 'inbox';
      elseif ($showArchived) $idxFilters['location'] = 'archive';
      $searchResults = $idx->search($search, $idxFilters, 200);
      $searchMatchIds = array_column($searchResults, 'id');
    } catch (\Throwable $e) {
      // Fallback: if index fails, continue with file scan
      $searchMatchIds = null;
    }
  }

  // "Active" = inbox only (open + in_progress + awaiting_feedback). "Archived" = archive dir only. Others = both.
  if ($showActive) {
    $scanDirs = [$TS_INBOX_DIR];
  } elseif ($showArchived) {
    $scanDirs = [$TS_ARCHIVE_DIR];
  } else {
    $scanDirs = [$TS_INBOX_DIR, $TS_ARCHIVE_DIR];
  }

  foreach ($scanDirs as $scanDir) {
    foreach (glob($scanDir . '/TS-*.json') as $f) {
      // If search index returned IDs, skip files not in that set
      $fileId = basename($f, '.json');
      if ($searchMatchIds !== null && !in_array($fileId, $searchMatchIds)) continue;

      $t = json_decode(file_get_contents($f), true);
      if (!$t) continue;

      // Track all domains/categories before filtering (for dropdown population)
      $d = $t['domain'] ?? '';
      $c = $t['category'] ?? '';
      if ($d && !in_array($d, $domains)) $domains[] = $d;
      if ($c && !in_array($c, $categories)) $categories[] = $c;

      // Apply status filter — "active" means anything not resolved/closed in the inbox
      if ($showActive) {
        if (in_array($t['status'] ?? '', ['resolved', 'closed', 'wont_fix', 'archived'])) continue;
      } elseif (!$showAll && !$showArchived && $statusFilter) {
        if (($t['status'] ?? '') !== $statusFilter) continue;
      }
      if ($domainFilter && ($t['domain'] ?? '') !== $domainFilter) continue;
      if ($urgencyFilter && ($t['urgency'] ?? '') !== $urgencyFilter) continue;
      if ($categoryFilter && ($t['category'] ?? '') !== $categoryFilter) continue;
      // Legacy substring fallback only if index search wasn't used
      if ($search && $searchMatchIds === null) {
        $hay = strtolower(($t['subject'] ?? '') . ' ' . ($t['description'] ?? '') . ' ' . ($t['domain'] ?? ''));
        if (strpos($hay, strtolower($search)) === false) continue;
      }

      // Determine agent export + sync status
      $agentExported = !empty($t['agent_exported']);
      $agentTaskId   = $t['agent_task_id'] ?? null;
      $agentSync     = 'none'; // not exported
      if ($agentExported) {
        if (!$agentTaskId || $agentTaskId === 'None') {
          $agentSync = 'broken'; // exported but no task ID saved
        } else {
          $taskPath = SITE_ROOT . '/admin/data/AgentScheduler/tasks/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $agentTaskId) . '.json';
          $agentSync = is_file($taskPath) ? 'synced' : 'orphaned';
        }
      }

      // Agent filter
      if ($agentFilter) {
        if ($agentFilter === 'exported' && !$agentExported) continue;
        if ($agentFilter === 'not_exported' && $agentExported) continue;
        if ($agentFilter === 'orphaned' && $agentSync !== 'orphaned' && $agentSync !== 'broken') continue;
      }

      // Determine owner — who's working on this ticket
      $owner = '';
      $ownerType = 'submitter';
      if ($agentExported && $agentSync === 'synced') {
        $owner = 'Agent';
        $ownerType = 'agent';
      } elseif ($agentExported && ($agentSync === 'orphaned' || $agentSync === 'broken')) {
        $owner = 'Agent (lost)';
        $ownerType = 'agent_lost';
      } else {
        // Find last person who changed status to in_progress or updated
        $statusHistory = $t['status_history'] ?? [];
        for ($i = count($statusHistory) - 1; $i >= 0; $i--) {
          $h = $statusHistory[$i];
          if (in_array($h['status'] ?? '', ['in_progress', 'resolved', 'closed'])) {
            $owner = $h['by'] ?? '';
            $ownerType = 'human';
            break;
          }
        }
        if (!$owner) {
          $owner = $t['user']['display_name'] ?? $t['user']['username'] ?? '';
          $ownerType = 'submitter';
        }
      }

      // Enrich with agent status if exported and task exists
      $agentInfo = null;
      if (!empty($t['agent_task_id']) && $agentSync === 'synced') {
        $agentInfo = ts_get_agent_status($t['agent_task_id']);
      }

      $ticketId = $t['id'] ?? basename($f, '.json');
      $item = [
        'id'              => $ticketId,
        'submitted_at'    => $t['submitted_at'] ?? '',
        'updated_at'      => $t['updated_at'] ?? '',
        'domain'          => $t['domain'] ?? '',
        'user'            => $t['user'] ?? [],
        'category'        => $t['category'] ?? '',
        'affected_areas'  => $t['affected_areas'] ?? [],
        'urgency'         => $t['urgency'] ?? 'low',
        'subject'         => $t['subject'] ?? '',
        'description'     => mb_substr($t['description'] ?? '', 0, 120),
        'status'          => $t['status'] ?? 'open',
        'lifecycle_stage' => ts_compute_lifecycle_stage($t, $agentInfo),
        'has_implementation' => ts_has_implementation($ticketId),
        'has_apply_manifest' => is_file(SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $ticketId) . '_apply_manifest.json'),
        'has_screenshot'  => !empty($t['screenshot']),
        'resolution'      => $t['resolution'] ?? '',
        'notes_count'     => count($t['notes'] ?? []),
        'archived'        => ($scanDir === $TS_ARCHIVE_DIR),
        'agent_exported'  => $agentExported,
        'agent_sync'      => $agentSync,
        'owner'           => $owner,
        'owner_type'      => $ownerType,
      ];

      if (!empty($t['cli_flagged'])) { $item['cli_flagged'] = true; $item['cli_flagged_at'] = $t['cli_flagged_at'] ?? ''; }
      if ($agentInfo) $item['agent'] = $agentInfo;
      if (!empty($t['page_content_details'])) $item['page_content_details'] = $t['page_content_details'];
      if (!empty($t['module_request_details'])) $item['module_request_details'] = $t['module_request_details'];

      $tickets[] = $item;
    }
  }

  // Sort by submitted_at descending (newest first)
  usort($tickets, fn($a, $b) => strcmp($b['submitted_at'], $a['submitted_at']));

  sort($domains);
  sort($categories);

  ts_api_json_ok([
    'tickets'    => $tickets,
    'total'      => count($tickets),
    'domains'    => $domains,
    'categories' => $categories,
  ]);
}

/* ── search_tickets — FTS5 powered full-text search ── */
if ($action === 'search_tickets') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);

  $query  = trim($_GET['q'] ?? $input['q'] ?? '');
  $limit  = min(100, max(1, (int)($_GET['limit'] ?? $input['limit'] ?? 50)));
  $offset = max(0, (int)($_GET['offset'] ?? $input['offset'] ?? 0));

  if ($query === '') ts_api_json_err('Search query is required');

  $filters = [];
  if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
  if (!empty($_GET['domain'])) $filters['domain'] = $_GET['domain'];
  if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
  if (!empty($_GET['location'])) $filters['location'] = $_GET['location'];

  try {
    $idx = ts_get_index();
    $results = $idx->search($query, $filters, $limit, $offset);
    ts_api_json_ok([
      'results' => $results,
      'total'   => count($results),
      'query'   => $query,
    ]);
  } catch (\Throwable $e) {
    ts_api_json_err('Search index error: ' . $e->getMessage(), 500);
  }
}

/* ── similar_tickets — find related/duplicate tickets ── */
if ($action === 'similar_tickets') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);

  $ticketId = $_GET['id'] ?? $input['id'] ?? '';
  if (!$ticketId) ts_api_json_err('Missing ticket ID');

  $resolvedOnly = !empty($_GET['resolved_only'] ?? $input['resolved_only'] ?? false);
  $limit = min(10, max(1, (int)($_GET['limit'] ?? $input['limit'] ?? 5)));

  $ticket = ts_load_ticket($ticketId);
  if (!$ticket) ts_api_json_err('Ticket not found', 404);

  try {
    $idx = ts_get_index();
    $similar = $idx->findSimilar($ticket, $limit, $resolvedOnly);
    ts_api_json_ok([
      'ticket_id' => $ticketId,
      'similar'   => $similar,
      'count'     => count($similar),
    ]);
  } catch (\Throwable $e) {
    ts_api_json_err('Search index error: ' . $e->getMessage(), 500);
  }
}

/* ── ticket_stats — index-based statistics ── */
if ($action === 'ticket_stats') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);

  try {
    $idx = ts_get_index();
    $stats = $idx->stats();
    ts_api_json_ok($stats);
  } catch (\Throwable $e) {
    ts_api_json_err('Index error: ' . $e->getMessage(), 500);
  }
}

/* ── rebuild_index — force rebuild of search index ── */
if ($action === 'rebuild_index') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);

  try {
    $idx = ts_get_index();
    $count = $idx->rebuild();
    ts_api_json_ok(['indexed' => $count, 'message' => "Rebuilt index with $count tickets"]);
  } catch (\Throwable $e) {
    ts_api_json_err('Rebuild failed: ' . $e->getMessage(), 500);
  }
}

/* ── get_ticket ── */
if ($action === 'get_ticket') {
  $id = $_GET['id'] ?? $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $ticket = ts_load_ticket($id);
  if (!$ticket) ts_api_json_err('Ticket not found', 404);

  // Enrich with agent status if exported
  $agentInfo = null;
  if (!empty($ticket['agent_task_id'])) {
    $agentInfo = ts_get_agent_status($ticket['agent_task_id']);
    if ($agentInfo) {
      // Inject library agent names from task config
      if (!empty($agentInfo['config']['_triage_agent_id'])) {
        $agentInfo['triage_agent_id'] = $agentInfo['config']['_triage_agent_id'];
      }
      if (!empty($agentInfo['config']['_category_agent_id'])) {
        $agentInfo['category_agent_id'] = $agentInfo['config']['_category_agent_id'];
        $agentInfo['category_agent_name'] = null;
        // Resolve agent name from library
        $libFile = SITE_ROOT . '/admin/modules/AgentScheduler/lib/AgentLibrary.php';
        if (is_file($libFile)) {
          require_once $libFile;
          $lib = new \AgentLibrary();
          $catAgent = $lib->getAgent($agentInfo['config']['_category_agent_id']);
          if ($catAgent) $agentInfo['category_agent_name'] = $catAgent['name'];
        }
      }
      $ticket['agent'] = $agentInfo;
    }
  }

  // Add lifecycle stage and implementation flag
  $ticket['lifecycle_stage'] = ts_compute_lifecycle_stage($ticket, $agentInfo);
  $ticket['has_implementation'] = ts_has_implementation($id);
  $ticket['has_apply_manifest'] = is_file(SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '_apply_manifest.json');

  ts_api_json_ok(['ticket' => $ticket]);
}

/* ── update_status ── */
if ($action === 'update_status' && $method === 'POST') {
  $id     = $input['id'] ?? '';
  $status = $input['status'] ?? '';
  $validStatuses = ['open', 'in_progress', 'awaiting_feedback', 'resolved', 'closed', 'wont_fix'];

  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!in_array($status, $validStatuses)) ts_api_json_err('Invalid status');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $inArchive = (strpos($path, '/archive/') !== false);

  $ticket = json_decode(file_get_contents($path), true);
  $ticket['status'] = $status;
  $ticket['updated_at'] = date('c');
  $ticket['status_history'][] = [
    'status' => $status,
    'at'     => date('c'),
    'by'     => ts_get_session_user(),
  ];

  // Unflag CLI if requested
  if (!empty($input['unflag_cli'])) {
    unset($ticket['cli_flagged']);
    unset($ticket['cli_flagged_at']);
  }

  // Determine where the ticket should live based on new status
  $shouldArchive = in_array($status, ['resolved', 'closed', 'wont_fix']);
  $shouldInbox   = in_array($status, ['open', 'in_progress', 'awaiting_feedback']);

  $newLocation = null;
  if ($shouldArchive && !$inArchive) {
    // Move to archive
    $ok = @file_put_contents($TS_ARCHIVE_DIR . '/' . $id . '.json',
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok !== false) { @unlink($path); $newLocation = 'archive'; }
    else ts_api_json_err('Failed to update — check file permissions', 500);
  } elseif ($shouldInbox && $inArchive) {
    // Reopen — move back to inbox
    $ok = @file_put_contents($TS_INBOX_DIR . '/' . $id . '.json',
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok !== false) { @unlink($path); $newLocation = 'inbox'; }
    else ts_api_json_err('Failed to update — check file permissions', 500);
  } else {
    // Stay in same directory
    $ok = @file_put_contents($path,
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok === false) ts_api_json_err('Failed to update — check file permissions', 500);
    $newLocation = $inArchive ? 'archive' : 'inbox';
  }

  try { ts_get_index()->indexTicket($ticket, $newLocation); } catch (\Throwable $e) {}

  ts_api_json_ok(['ticket' => $ticket]);
}

/* ── add_note ── */
if ($action === 'add_note' && $method === 'POST') {
  $id   = $input['id'] ?? '';
  $note = trim($input['note'] ?? '');

  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!$note) ts_api_json_err('Empty note');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $ticket['notes'][] = [
    'text' => $note,
    'at'   => date('c'),
    'by'   => ts_get_session_user(),
  ];
  $ticket['updated_at'] = date('c');

  // Adding a note reopens the ticket — move from archive to inbox if needed
  $inArchive = (strpos($path, '/archive/') !== false);
  $wasInactive = in_array($ticket['status'] ?? '', ['closed', 'resolved', 'wont_fix', 'archived']);

  if ($wasInactive) {
    $ticket['status'] = 'in_progress';
    $ticket['status_history'][] = [
      'status' => 'in_progress',
      'at'     => date('c'),
      'by'     => ts_get_session_user(),
      'note'   => 'Reopened by adding a note',
    ];
  }

  if ($inArchive) {
    // Move back to inbox
    $inboxPath = $TS_INBOX_DIR . '/' . $id . '.json';
    $ok = @file_put_contents($inboxPath,
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok !== false) @unlink($path);
    else ts_api_json_err('Failed to save note — check file permissions', 500);
  } else {
    $ok = @file_put_contents($path,
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok === false) ts_api_json_err('Failed to save note — check file permissions', 500);
  }

  try { ts_get_index()->indexTicket($ticket, 'inbox'); } catch (\Throwable $e) {}

  ts_api_json_ok(['ticket' => $ticket, 'reopened' => $wasInactive]);
}

/* ── resolve_ticket ── */
if ($action === 'resolve_ticket' && $method === 'POST') {
  $id         = $input['id'] ?? '';
  $resolution = trim($input['resolution'] ?? '');

  if (!$id) ts_api_json_err('Missing ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  // Soft-warn if impl file exists but verification not complete
  $hasImpl = ts_has_implementation($id);
  $isVerified = !empty($ticket['verification']['completed']);
  $warning = ($hasImpl && !$isVerified) ? 'Resolved without completing verification checklist' : null;

  $ticket['status'] = 'resolved';
  $ticket['resolution'] = $resolution;
  $ticket['resolved_by'] = ts_get_session_user();
  $ticket['resolved_at'] = date('c');
  $ticket['updated_at'] = date('c');
  $ticket['status_history'][] = [
    'status' => 'resolved',
    'at'     => date('c'),
    'by'     => ts_get_session_user(),
    'note'   => $warning,
  ];

  // Auto-retry delivery for AgentScheduler blocker tickets
  $retryResult = null;
  if (!empty($ticket['blocker_for_task'])) {
    $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
    $asModule  = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
    if (is_dir($asDataDir) && is_file($asModule)) {
      require_once $asModule;
      $asEngine = new \AgentEngine($asDataDir);
      $retryResult = $asEngine->retryDelivery($ticket['blocker_for_task']);

      $retryNote = 'Auto-retry delivery: ' . ($retryResult['ok'] ? 'SUCCESS' : 'FAILED — ' . ($retryResult['error'] ?? 'unknown'));
      $ticket['notes'][] = ['at' => date('c'), 'by' => 'system', 'text' => $retryNote];
    }
  }

  // Resolved = done. Auto-archive immediately (no purgatory).
  $inArchive = (strpos($path, '/archive/') !== false);
  if (!$inArchive) {
    $ok = @file_put_contents($TS_ARCHIVE_DIR . '/' . $id . '.json',
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok !== false) {
      @unlink($path);
      try { ts_get_index()->indexTicket($ticket, 'archive'); } catch (\Throwable $e) {}
    } else {
      ts_api_json_err('Failed to archive resolved ticket — check permissions', 500);
    }
  } else {
    @file_put_contents($path,
      json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    try { ts_get_index()->indexTicket($ticket, 'archive'); } catch (\Throwable $e) {}
  }

  ts_api_json_ok(['ticket' => $ticket, 'warning' => $warning, 'retry_result' => $retryResult]);
}

/* ── save_verification — QA checklist for impl-ready tickets ── */
if ($action === 'save_verification' && $method === 'POST') {
  $id = $input['id'] ?? '';
  $checklist = $input['checklist'] ?? [];

  if (!$id) ts_api_json_err('Missing ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);

  $allChecked = !empty($checklist['reviewed']) && !empty($checklist['tested']) && !empty($checklist['no_regressions']);

  $ticket['verification'] = [
    'checklist'   => $checklist,
    'completed'   => $allChecked,
    'verified_by' => ts_get_session_user(),
    'verified_at' => date('c'),
  ];
  $ticket['updated_at'] = date('c');

  if ($allChecked) {
    $ticket['status_history'][] = [
      'status' => 'verified',
      'at'     => date('c'),
      'by'     => ts_get_session_user(),
      'note'   => 'QA verification checklist completed',
    ];
  }

  @file_put_contents($path,
    json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  try { ts_get_index()->indexTicket($ticket); } catch (\Throwable $e) {}

  ts_api_json_ok(['ticket' => $ticket]);
}

/* ── delete_ticket (archive — preserves ticket data) ── */
if ($action === 'delete_ticket' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $ticket['status'] = 'archived';
  $ticket['updated_at'] = date('c');
  $ticket['status_history'][] = [
    'status' => 'archived',
    'at'     => date('c'),
    'by'     => ts_get_session_user(),
  ];

  @file_put_contents($TS_ARCHIVE_DIR . '/' . $id . '.json',
    json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  @unlink($path);

  try { ts_get_index()->indexTicket($ticket, 'archive'); } catch (\Throwable $e) {}

  ts_api_json_ok(['message' => 'Ticket archived']);
}

/* ── permanently_delete_ticket (permanent removal) ── */
if ($action === 'permanently_delete_ticket' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!preg_match('/^TS-\d+-\d+$/', $id)) ts_api_json_err('Invalid ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  // Remove ticket JSON
  @unlink($path);

  // Remove screenshot(s)
  foreach (glob($TS_SCREENS_DIR . '/' . $id . '.*') as $ss) {
    @unlink($ss);
  }

  // Remove agent task docs
  $agentDir = SITE_ROOT . '/admin/data/support_tickets/agent_tasks';
  if (is_dir($agentDir)) {
    foreach (glob($agentDir . '/' . $id . '.*') as $at) {
      @unlink($at);
    }
  }

  try { ts_get_index()->removeTicket($id); } catch (\Throwable $e) {}

  ts_api_json_ok(['message' => 'Ticket permanently deleted']);
}

/* ── bulk_archive_resolved — move all resolved tickets from inbox to archive ── */
if ($action === 'bulk_archive_resolved' && $method === 'POST') {
  $moved = 0;
  $now = date('c');
  foreach (glob($TS_INBOX_DIR . '/TS-*.json') as $f) {
    $t = json_decode(file_get_contents($f), true);
    if (!$t) continue;
    if (($t['status'] ?? '') !== 'resolved') continue;

    $t['status'] = 'archived';
    $t['updated_at'] = $now;
    if (!isset($t['status_history'])) $t['status_history'] = [];
    $t['status_history'][] = ['status' => 'archived', 'at' => $now, 'by' => 'bulk-action', 'note' => 'Bulk archive from dashboard'];

    $dest = $TS_ARCHIVE_DIR . '/' . basename($f);
    file_put_contents($dest, json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($dest, 0664);
    @unlink($f);
    try { ts_get_index()->indexTicket($t, 'archive'); } catch (\Throwable $e) {}
    $moved++;
  }
  ts_api_json_ok(['archived' => $moved]);
}

/* ── inter_ticket — send to graveyard (extract knowledge, destroy original) ── */
if ($action === 'inter_ticket' && $method === 'POST') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  if (!$ticket) ts_api_json_err('Could not read ticket');

  // Extract knowledge — squeeze the juice
  $tombstone = [
    'id'            => $ticket['id'],
    'original_id'   => $ticket['id'],
    'subject'       => $ticket['subject'] ?? '',
    'description'   => $ticket['description'] ?? '',
    'category'      => $ticket['category'] ?? '',
    'domain'        => $ticket['domain'] ?? '',
    'urgency'       => $ticket['urgency'] ?? '',
    'resolution'    => $ticket['resolution'] ?? '',
    'notes'         => $ticket['notes'] ?? [],
    'status_history'=> $ticket['status_history'] ?? [],
    'final_status'  => $ticket['status'] ?? 'unknown',
    'affected_areas'=> $ticket['affected_areas'] ?? [],
    'submitted_at'  => $ticket['submitted_at'] ?? '',
    'resolved_at'   => $ticket['resolved_at'] ?? $ticket['updated_at'] ?? '',
    'interred_at'   => date('c'),
    'interred_by'   => ts_get_session_user(),
    'tombstone'     => true,
    'tags'          => $input['tags'] ?? [],
  ];

  // Save to graveyard
  $gravePath = $GLOBALS['TS_GRAVEYARD_DIR'] . '/' . $id . '.json';
  file_put_contents($gravePath, json_encode($tombstone, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  @chmod($gravePath, 0664);

  // Destroy original
  @unlink($path);

  // Clean up screenshots
  foreach (glob($GLOBALS['TS_SCREENS_DIR'] . '/' . $id . '.*') as $ss) {
    @unlink($ss);
  }

  // Clean up agent tasks
  $agentDir = SITE_ROOT . '/admin/data/support_tickets/agent_tasks';
  if (is_dir($agentDir)) {
    foreach (glob($agentDir . '/' . $id . '.*') as $at) {
      @unlink($at);
    }
  }

  // Remove from search index
  try { ts_get_index()->removeTicket($id); } catch (\Throwable $e) {}

  ts_api_json_ok(['message' => 'Ticket interred', 'id' => $id]);
}

/* ── list_graveyard — knowledge base records ── */
if ($action === 'list_graveyard') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);
  $entries = [];
  $search = $_GET['search'] ?? $input['search'] ?? '';
  $searchLower = strtolower($search);

  foreach (glob($GLOBALS['TS_GRAVEYARD_DIR'] . '/TS-*.json') as $f) {
    $t = json_decode(file_get_contents($f), true);
    if (!$t) continue;

    // Search filter
    if ($search) {
      $haystack = strtolower(($t['subject'] ?? '') . ' ' . ($t['description'] ?? '') . ' ' . ($t['resolution'] ?? '') . ' ' . ($t['domain'] ?? '') . ' ' . ($t['category'] ?? ''));
      if (strpos($haystack, $searchLower) === false) continue;
    }

    $entries[] = [
      'id'          => $t['id'],
      'subject'     => $t['subject'] ?? '',
      'category'    => $t['category'] ?? '',
      'domain'      => $t['domain'] ?? '',
      'final_status'=> $t['final_status'] ?? '',
      'resolution'  => $t['resolution'] ?? '',
      'description' => substr($t['description'] ?? '', 0, 200),
      'submitted_at'=> $t['submitted_at'] ?? '',
      'interred_at' => $t['interred_at'] ?? '',
      'tags'        => $t['tags'] ?? [],
      'notes_count' => count($t['notes'] ?? []),
    ];
  }

  // Sort newest interred first
  usort($entries, function($a, $b) {
    return strcmp($b['interred_at'], $a['interred_at']);
  });

  ts_api_json_ok(['entries' => $entries, 'count' => count($entries)]);
}

/* ── get_graveyard_entry — full knowledge record ── */
if ($action === 'get_graveyard_entry') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);
  $id = $_GET['id'] ?? $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ID');

  $path = $GLOBALS['TS_GRAVEYARD_DIR'] . '/' . $id . '.json';
  if (!is_file($path)) ts_api_json_err('Graveyard entry not found', 404);

  $entry = json_decode(file_get_contents($path), true);
  if (!$entry) ts_api_json_err('Could not read entry');

  ts_api_json_ok(['entry' => $entry]);
}

/* ── clone_from_graveyard — reincarnate as a new ticket ── */
if ($action === 'clone_from_graveyard' && $method === 'POST') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);
  $sourceId = $input['source_id'] ?? '';
  if (!$sourceId) ts_api_json_err('Missing source ID');

  $path = $GLOBALS['TS_GRAVEYARD_DIR'] . '/' . $sourceId . '.json';
  if (!is_file($path)) ts_api_json_err('Graveyard entry not found', 404);

  $source = json_decode(file_get_contents($path), true);
  if (!$source) ts_api_json_err('Could not read source entry');

  // Generate new ticket ID
  $dateStr = date('Ymd');
  $seq = 1;
  while (is_file($GLOBALS['TS_INBOX_DIR'] . '/TS-' . $dateStr . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT) . '.json')) {
    $seq++;
  }
  $newId = 'TS-' . $dateStr . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);

  // Build new ticket from graveyard data
  $newTicket = [
    'id'          => $newId,
    'category'    => $source['category'] ?? 'feature_request',
    'subject'     => ($input['subject'] ?? '') ?: ('[Reincarnated] ' . ($source['subject'] ?? '')),
    'description' => ($input['description'] ?? '') ?: ($source['description'] ?? ''),
    'domain'      => $source['domain'] ?? '',
    'urgency'     => $input['urgency'] ?? 'medium',
    'affected_areas' => $source['affected_areas'] ?? [],
    'status'      => 'open',
    'submitted_at'=> date('c'),
    'updated_at'  => date('c'),
    'user'        => [
      'username'     => ts_get_session_user(),
      'display_name' => ts_get_session_user(),
      'role'         => $_SESSION['user_role'] ?? 'admin',
    ],
    'reincarnated_from' => $sourceId,
    'notes'       => [[
      'by'   => 'system',
      'at'   => date('c'),
      'text' => 'Reincarnated from graveyard entry ' . $sourceId . '. Original resolution: ' . ($source['resolution'] ?? 'none'),
    ]],
    'status_history' => [['status' => 'open', 'at' => date('c'), 'by' => 'system', 'note' => 'Reincarnated from ' . $sourceId]],
    'triage_mode' => 'manual',
  ];

  $newPath = $GLOBALS['TS_INBOX_DIR'] . '/' . $newId . '.json';
  file_put_contents($newPath, json_encode($newTicket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  @chmod($newPath, 0664);

  ts_api_json_ok(['message' => 'Ticket reincarnated', 'new_id' => $newId, 'source_id' => $sourceId]);
}

/* ── bulk_inter — inter all resolved/closed/archived tickets ── */
if ($action === 'bulk_inter' && $method === 'POST') {
  if (!ts_is_admin_session()) ts_api_json_err('Not authorized', 403);
  $interred = 0;
  $now = date('c');
  $user = ts_get_session_user();

  foreach ([$GLOBALS['TS_INBOX_DIR'], $GLOBALS['TS_ARCHIVE_DIR']] as $dir) {
    foreach (glob($dir . '/TS-*.json') as $f) {
      $t = json_decode(file_get_contents($f), true);
      if (!$t) continue;
      $status = $t['status'] ?? '';
      if (!in_array($status, ['resolved', 'closed', 'archived', 'wont_fix'])) continue;

      $tombstone = [
        'id'            => $t['id'],
        'original_id'   => $t['id'],
        'subject'       => $t['subject'] ?? '',
        'description'   => $t['description'] ?? '',
        'category'      => $t['category'] ?? '',
        'domain'        => $t['domain'] ?? '',
        'urgency'       => $t['urgency'] ?? '',
        'resolution'    => $t['resolution'] ?? '',
        'notes'         => $t['notes'] ?? [],
        'status_history'=> $t['status_history'] ?? [],
        'final_status'  => $status,
        'affected_areas'=> $t['affected_areas'] ?? [],
        'submitted_at'  => $t['submitted_at'] ?? '',
        'resolved_at'   => $t['resolved_at'] ?? $t['updated_at'] ?? '',
        'interred_at'   => $now,
        'interred_by'   => $user,
        'tombstone'     => true,
        'tags'          => [],
      ];

      $gravePath = $GLOBALS['TS_GRAVEYARD_DIR'] . '/' . basename($f);
      file_put_contents($gravePath, json_encode($tombstone, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      @chmod($gravePath, 0664);
      @unlink($f);

      // Clean up screenshots and agent tasks
      $id = $t['id'];
      foreach (glob($GLOBALS['TS_SCREENS_DIR'] . '/' . $id . '.*') as $ss) @unlink($ss);
      $agentDir = SITE_ROOT . '/admin/data/support_tickets/agent_tasks';
      if (is_dir($agentDir)) {
        foreach (glob($agentDir . '/' . $id . '.*') as $at) @unlink($at);
      }
      try { ts_get_index()->removeTicket($id); } catch (\Throwable $e) {}

      $interred++;
    }
  }

  ts_api_json_ok(['interred' => $interred]);
}

/* ── stats ── */
if ($action === 'stats') {
  $counts = ['open' => 0, 'in_progress' => 0, 'awaiting_feedback' => 0, 'resolved' => 0, 'closed' => 0, 'archived' => 0, 'wont_fix' => 0];
  $urgencyCounts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
  $domainCounts = [];

  // Scan both inbox and archive for accurate totals
  foreach ([$TS_INBOX_DIR, $TS_ARCHIVE_DIR] as $dir) {
    foreach (glob($dir . '/TS-*.json') as $f) {
      $t = json_decode(file_get_contents($f), true);
      if (!$t) continue;
      $s = $t['status'] ?? 'open';
      if (isset($counts[$s])) $counts[$s]++; else $counts[$s] = 1;
      $u = $t['urgency'] ?? 'low';
      if (isset($urgencyCounts[$u])) $urgencyCounts[$u]++;
      $d = $t['domain'] ?? 'unknown';
      $domainCounts[$d] = ($domainCounts[$d] ?? 0) + 1;
    }
  }

  $totalArchived  = count(glob($TS_ARCHIVE_DIR . '/TS-*.json'));
  $totalGraveyard = count(glob($TS_GRAVEYARD_DIR . '/TS-*.json'));

  ts_api_json_ok([
    'status_counts'   => $counts,
    'urgency_counts'  => $urgencyCounts,
    'domain_counts'   => $domainCounts,
    'total_inbox'     => array_sum($counts) - ($counts['archived'] ?? 0) - ($counts['closed'] ?? 0) - ($counts['resolved'] ?? 0) - ($counts['wont_fix'] ?? 0),
    'total_archived'  => $totalArchived,
    'total_graveyard' => $totalGraveyard,
  ]);
}

/* ── get_pages — scan pages for a given domain (dashboard only) ── */
if ($action === 'get_pages') {
  $targetDomain = $_GET['domain'] ?? $input['domain'] ?? '';
  if (!$targetDomain) ts_api_json_err('Missing domain');
  $cleanDomain = preg_replace('/[^a-zA-Z0-9._-]/', '', $targetDomain);
  $pagesDir = '/var/www/vhosts/' . $cleanDomain . '/admin/data/pages';

  if (!is_dir($pagesDir)) {
    // Check if remote site
    $reg = ts_load_site_registry();
    if (!empty($reg[$cleanDomain]['remote'])) {
      ts_api_json_ok(['pages' => [], 'domain' => $cleanDomain, 'note' => 'Remote site — page list not available']);
    }
    ts_api_json_err('Pages directory not found for ' . $cleanDomain, 404);
  }

  $pages = [];
  foreach (glob($pagesDir . '/*/') as $dir) {
    $slug = basename($dir);
    if ($slug === 'pages_trash' || $slug[0] === '.') continue;
    $jsonFile = $dir . '/' . $slug . '.json';
    if (!is_file($jsonFile)) continue;
    $data = json_decode(file_get_contents($jsonFile), true);
    $pages[] = [
      'slug'          => $slug,
      'title'         => $data['page_title'] ?? ucwords(str_replace('-', ' ', $slug)),
      'last_modified' => date('c', filemtime($jsonFile)),
    ];
  }
  usort($pages, fn($a, $b) => strcasecmp($a['title'], $b['title']));
  ts_api_json_ok(['pages' => $pages, 'domain' => $cleanDomain]);
}

/* ── get_sites — return enabled sites from site registry (dashboard only) ── */
if ($action === 'get_sites') {
  $registry = ts_load_site_registry();
  $sites = [];
  foreach ($registry as $dom => $entry) {
    if (!is_array($entry) || $dom[0] === '_') continue;
    $sites[] = [
      'domain'    => $dom,
      'profile'   => $entry['profile'] ?? 'standard',
      'site_type' => $entry['site_type'] ?? 'standard',
      'remote'    => !empty($entry['remote']),
    ];
  }
  usort($sites, fn($a, $b) => strcasecmp($a['domain'], $b['domain']));
  ts_api_json_ok(['sites' => $sites]);
}

/* ── screenshot serve ── */
if ($action === 'screenshot') {
  $file = $_GET['file'] ?? '';
  if (!$file || !preg_match('/^TS-[\d]+-[\d]+\.(png|jpe?g|gif|webp)$/', $file)) {
    ts_api_json_err('Invalid file', 400);
  }
  $path = $TS_SCREENS_DIR . '/' . $file;
  if (!is_file($path)) ts_api_json_err('File not found', 404);

  $mime = mime_content_type($path) ?: 'image/png';
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: public, max-age=86400');
  readfile($path);
  exit;
}

/* ── export_agent_task — generate MCP-ready task document ── */
if ($action === 'export_agent_task' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $ticket = ts_load_ticket($id);
  if (!$ticket) ts_api_json_err('Ticket not found', 404);

  $catLabels = [
    'ui_issue'            => 'UI Bug',
    'ui_request'          => 'UI Enhancement',
    'something_is_broken' => 'Bug Fix',
    'edit_page_html_css'  => 'Page Content Edit',
    'page_content'        => 'Page Content Edit',
    'feature_request'     => 'Feature Implementation',
    'module_request'      => 'Module Development',
    'question'            => 'Investigation',
  ];
  $urgLabels = [
    'low'      => 'Low — cosmetic, no rush',
    'medium'   => 'Medium — functional issue, workaround exists',
    'high'     => 'High — blocking workflow, needs attention soon',
    'critical' => 'Critical — site down or data at risk',
  ];

  $taskType = $catLabels[$ticket['category'] ?? ''] ?? 'Investigation';
  $areas    = implode(', ', $ticket['affected_areas'] ?? []);
  $urgency  = $urgLabels[$ticket['urgency'] ?? 'low'] ?? 'Low';
  $notesText = '';
  foreach (($ticket['notes'] ?? []) as $n) {
    $notesText .= sprintf("- [%s] %s: %s\n", $n['at'] ?? '', $n['by'] ?? '', $n['text'] ?? '');
  }

  // Build structured agent task document
  $taskDoc = "# Agent Task: {$ticket['id']}\n\n";
  $taskDoc .= "## Task Type\n{$taskType}\n\n";
  $taskDoc .= "## Urgency\n{$urgency}\n\n";
  $taskDoc .= "## Source\n";
  $taskDoc .= "- **Ticket:** {$ticket['id']}\n";
  $taskDoc .= "- **Filed from:** {$ticket['domain']} (informational — fix may apply to any/all sites)\n";
  $taskDoc .= "- **Reported by:** " . ($ticket['user']['display_name'] ?? $ticket['user']['username'] ?? 'unknown') . " ({$ticket['user']['role']})\n";
  $taskDoc .= "- **Submitted:** {$ticket['submitted_at']}\n";
  $taskDoc .= "- **CMS Version:** {$ticket['cms_version']}\n";
  $taskDoc .= "- **PHP Version:** {$ticket['php_version']}\n\n";
  $taskDoc .= "## Subject\n{$ticket['subject']}\n\n";
  $taskDoc .= "## Description\n{$ticket['description']}\n\n";
  if ($areas) {
    $taskDoc .= "## Affected Modules\n{$areas}\n\n";
    $taskDoc .= "## Files to Investigate\n";
    foreach (($ticket['affected_areas'] ?? []) as $area) {
      // Map module names to directory paths
      $clean = preg_replace('/[^a-zA-Z0-9]/', '', $area);
      if (in_array($area, ['Dashboard','Login / Auth','Frontend (site)','Deployment / Updates'])) {
        $taskDoc .= "- System area: {$area}\n";
      } else {
        $taskDoc .= "- `/admin/modules/{$clean}/`\n";
      }
    }
    $taskDoc .= "\n";
  }
  if ($notesText) {
    $taskDoc .= "## Internal Notes\n{$notesText}\n";
  }
  $taskDoc .= "## Instructions\n";
  $taskDoc .= "1. Investigate the reported issue — focus on the problem described, not the originating site\n";
  $taskDoc .= "2. Check the affected module(s) for the described problem\n";
  $taskDoc .= "3. Propose a fix with minimal changes (code fixes deploy to all sites)\n";
  $taskDoc .= "4. Do NOT deploy — prepare the fix for human review\n";
  $taskDoc .= "5. Report findings back to this ticket\n";

  // Save the task document
  $agentDir = SITE_ROOT . '/admin/data/support_tickets/agent_tasks';
  if (!is_dir($agentDir)) @mkdir($agentDir, 0775, true);
  $taskFile = $agentDir . '/' . $ticket['id'] . '.md';
  @file_put_contents($taskFile, $taskDoc, LOCK_EX);

  // Also save as structured JSON for MCP consumption
  $taskJson = [
    'task_id'      => $ticket['id'],
    'task_type'    => $taskType,
    'urgency'      => $ticket['urgency'] ?? 'low',
    'domain'       => $ticket['domain'],
    'subject'      => $ticket['subject'],
    'description'  => $ticket['description'],
    'affected_modules' => $ticket['affected_areas'] ?? [],
    'cms_version'  => $ticket['cms_version'] ?? 'unknown',
    'reported_by'  => $ticket['user'] ?? [],
    'submitted_at' => $ticket['submitted_at'],
    'notes'        => $ticket['notes'] ?? [],
    'status'       => 'pending_agent',
    'exported_at'  => date('c'),
    'exported_by'  => ts_get_session_user(),
  ];
  $taskJsonFile = $agentDir . '/' . $ticket['id'] . '.json';
  @file_put_contents($taskJsonFile, json_encode($taskJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  // ── Bridge: Create AgentScheduler task for AI analysis ──────
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
  $asModule  = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $agentTaskId = null;
  $agentStatus = null;

  if (is_dir($asDataDir) && is_file($asModule)) {
    require_once $asModule;
    $engine = new \AgentEngine($asDataDir);

    // Idempotency: check if a task already exists for this ticket
    $existing = $engine->findTaskByTicket($ticket['id']);
    if ($existing) {
      $agentTaskId = $existing['id'];
      // Re-run analysis if the existing task is in error state
      if (($existing['status'] ?? $existing['last_status'] ?? '') === 'error') {
        try {
          $analysisRun = $engine->rerunTask($agentTaskId);
          $agentStatus = $analysisRun['status'] ?? 'retried';
        } catch (\Throwable $e) {
          $agentStatus = 'error: ' . $e->getMessage();
        }
      } else {
        $agentStatus = $existing['status'] ?? $existing['last_status'] ?? 'exists';
      }
    } else {
      // Pre-generate ID since saveTask() takes array by value
      $agentTaskId = 'task_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

      $agentTask = [
        'id'             => $agentTaskId,
        'name'           => 'Ticket: ' . ($ticket['subject'] ?? $ticket['id']),
        'pipeline'       => 'ticket_analysis',
        'enabled'        => true,
        'schedule'       => ['type' => 'manual'],
        'config'         => [
          'source_ticket_id'  => $ticket['id'],
          'ticket_json_path'  => $taskJsonFile,
          'ticket_md_path'    => $taskFile,
          'domain'            => $ticket['domain'] ?? '',
          'urgency'           => $ticket['urgency'] ?? 'low',
          'affected_modules'  => $ticket['affected_areas'] ?? [],
          'task_type'         => $taskType,
          'execute_pipeline'  => 'ticket_execute',
          'apply_pipeline'    => 'ticket_apply',
          'auto_apply'        => 'full_auto',
          'export_context'    => $input['export_context'] ?? '',
          'export_example'    => $input['export_example'] ?? '',
        ],
        'source'         => 'tech_support',
        'source_ticket'  => $ticket['id'],
      ];

      // Detect target domain from ticket description/subject (URLs or "on <domain>" patterns)
      $descAndSubject = ($ticket['description'] ?? '') . ' ' . ($ticket['subject'] ?? '');
      if (preg_match('/https?:\/\/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:com|pro|net|org|rocks|group|io))/i', $descAndSubject, $dmInit)) {
        $detectedDomain = strtolower($dmInit[1]);
        if ($detectedDomain !== ($ticket['domain'] ?? '')) {
          $agentTask['config']['apply_target_domain'] = $detectedDomain;
        }
      }

      $engine->saveTask($agentTask);

      // Immediately trigger Phase 1 analysis
      try {
        $analysisRun = $engine->runTask($agentTaskId);
        $agentStatus = $analysisRun['status'] ?? 'unknown';
      } catch (\Throwable $e) {
        $agentStatus = 'error: ' . $e->getMessage();
      }
    }
  }

  // Update ticket status + agent_exported flag (after $agentTaskId is assigned)
  $path = ts_get_ticket_path($id);
  if ($path) {
    $ticket['status']            = 'in_progress';
    $ticket['updated_at']        = date('c');
    $ticket['agent_exported']    = true;
    $ticket['agent_task_id']     = $agentTaskId;
    $ticket['agent_exported_at'] = date('c');
    $ticket['agent_exported_by'] = ts_get_session_user();
    $ticket['status_history'][] = [
      'status' => 'in_progress',
      'at'     => date('c'),
      'by'     => ts_get_session_user(),
      'note'   => 'Exported to agent task' . ($agentTaskId ? " {$agentTaskId}" : ''),
    ];
    @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  ts_api_json_ok([
    'task_file'      => '/admin/data/support_tickets/agent_tasks/' . $ticket['id'] . '.md',
    'task_json'      => '/admin/data/support_tickets/agent_tasks/' . $ticket['id'] . '.json',
    'task_doc'       => $taskDoc,
    'agent_task_id'  => $agentTaskId,
    'agent_status'   => $agentStatus,
    'message'        => 'Agent task exported' . ($agentTaskId ? ' and analysis started' : '') . ': ' . $ticket['id'],
  ]);
}

/* ── clarify_ticket — inject clarification and re-analyze ── */
if ($action === 'clarify_ticket' && $method === 'POST') {
  $id            = $input['id'] ?? '';
  $clarification = trim($input['clarification'] ?? '');

  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!$clarification) ts_api_json_err('Clarification text is required');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if (!$agentTaskId) ts_api_json_err('No agent task associated with this ticket');

  $user = ts_get_session_user();
  $now = date('c');

  // Append to ticket notes
  $ticket['notes'][] = [
    'text' => '[Clarification] ' . $clarification,
    'at'   => $now,
    'by'   => $user,
  ];

  // Append to ticket clarifications array (audit trail)
  if (!isset($ticket['clarifications'])) $ticket['clarifications'] = [];
  $ticket['clarifications'][] = [
    'text'      => $clarification,
    'by'        => $user,
    'timestamp' => $now,
  ];

  $ticket['updated_at'] = $now;
  $ticket['status_history'][] = [
    'status' => 'in_progress',
    'at'     => $now,
    'by'     => $user,
    'note'   => 'Clarification submitted — re-analyzing',
  ];

  @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  // Call AgentEngine::clarifyPlan()
  $asModule = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
  $runResult = null;
  $newStage = 'analyzing';

  if (is_file($asModule) && is_dir($asDataDir)) {
    require_once $asModule;
    $engine = new \AgentEngine($asDataDir);

    // Detect domain references in clarification text and set apply_target_domain
    // Matches patterns like "on example.com", "example.com site", URLs containing a domain
    if (preg_match('/(?:on|target|for|at)\s+([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:com|pro|net|org|rocks|group|io))/i', $clarification, $dm)
        || preg_match('/https?:\/\/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:com|pro|net|org|rocks|group|io))/i', $clarification, $dm)) {
      $mentionedDomain = strtolower($dm[1]);
      $task = $engine->getTask($agentTaskId);
      if ($task && $mentionedDomain !== ($task['config']['domain'] ?? '')) {
        $task['config']['apply_target_domain'] = $mentionedDomain;
        $engine->saveTask($task);
      }
    }

    // Try clarifyPlan first (for awaiting_approval state)
    $runResult = $engine->clarifyPlan($agentTaskId, $clarification, $user);

    if ($runResult === null) {
      // Task not in awaiting_approval — may be error state. Add clarification to config and re-run.
      $task = $engine->getTask($agentTaskId);
      if (!$task) ts_api_json_err('Agent task not found');

      // Snapshot previous plan so re-analysis knows what was already tried
      $prevPlan = $engine->getPlan($agentTaskId);
      if ($prevPlan) {
        $task['config']['previous_plan'] = [
          'diagnosis'        => $prevPlan['diagnosis'] ?? '',
          'proposed_changes' => $prevPlan['proposed_changes'] ?? [],
          'risk_assessment'  => $prevPlan['risk_assessment'] ?? '',
          'created_at'       => $prevPlan['created_at'] ?? '',
        ];
      }

      if (!isset($task['config']['clarifications'])) $task['config']['clarifications'] = [];
      $task['config']['clarifications'][] = [
        'text' => $clarification, 'by' => $user, 'timestamp' => $now,
      ];
      $task['pipeline'] = 'ticket_analysis';
      $engine->saveTask($task);
      $runResult = $engine->rerunTask($agentTaskId);
    }

    // Determine new lifecycle stage from run result
    $runStatus = $runResult['status'] ?? '';
    if ($runStatus === 'plan_generated') $newStage = 'plan_ready';
    elseif ($runStatus === 'error') $newStage = 'error';
  } else {
    ts_api_json_err('AgentScheduler module not available', 500);
  }

  // Re-load ticket for updated agent info
  $agentInfo = ts_get_agent_status($agentTaskId);
  $ticket = ts_load_ticket($id);
  $lifecycle = ts_compute_lifecycle_stage($ticket, $agentInfo);

  ts_api_json_ok([
    'ticket_id'       => $id,
    'lifecycle_stage'  => $lifecycle,
    'run_status'       => $runResult['status'] ?? null,
    'clarification_count' => count($ticket['clarifications'] ?? []),
    'message'          => 'Clarification submitted — re-analysis ' . ($runResult['status'] === 'plan_generated' ? 'complete' : 'in progress'),
  ]);
}

/* ── rate_response — set 1-5 star rating on an agent response note ── */
if ($action === 'rate_response' && $method === 'POST') {
  $id        = $input['id'] ?? '';
  $noteIndex = $input['note_index'] ?? null;
  $stars     = (int)($input['stars'] ?? 0);

  if (!$id || $noteIndex === null) ts_api_json_err('id and note_index required');
  if ($stars < 1 || $stars > 5) ts_api_json_err('Stars must be 1-5');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $noteIndex = (int)$noteIndex;

  if (!isset($ticket['notes'][$noteIndex])) ts_api_json_err('Note not found at index ' . $noteIndex);

  $ticket['notes'][$noteIndex]['star_rating'] = $stars;
  $ticket['updated_at'] = date('c');

  @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  ts_api_json_ok(['rated' => true, 'stars' => $stars, 'note_index' => $noteIndex]);
}

/* ── resubmit_ticket — re-run agent analysis from scratch (no clarification required) ── */
if ($action === 'resubmit_ticket' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if (!$agentTaskId) ts_api_json_err('No agent task associated with this ticket');

  $user = ts_get_session_user();
  $now = date('c');

  $ticket['updated_at'] = $now;
  $ticket['status'] = 'in_progress';
  $ticket['status_history'][] = [
    'status' => 'in_progress',
    'at'     => $now,
    'by'     => $user,
    'note'   => 'Resubmitted for re-analysis',
  ];

  @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  // Reset agent task pipeline to ticket_analysis and re-run
  $asModule = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
  $runResult = null;
  $newStage = 'analyzing';

  if (is_file($asModule) && is_dir($asDataDir)) {
    require_once $asModule;
    $engine = new \AgentEngine($asDataDir);
    $task = $engine->getTask($agentTaskId);
    if (!$task) ts_api_json_err('Agent task not found');

    $task['pipeline'] = 'ticket_analysis';
    $task['status'] = 'pending';
    $task['last_error'] = null;
    $task['last_failed_step'] = null;
    $engine->saveTask($task);
    $runResult = $engine->rerunTask($agentTaskId);

    $runStatus = $runResult['status'] ?? '';
    if ($runStatus === 'plan_generated') $newStage = 'plan_ready';
    elseif ($runStatus === 'error') $newStage = 'error';
  } else {
    ts_api_json_err('AgentScheduler module not available', 500);
  }

  $agentInfo = ts_get_agent_status($agentTaskId);
  $ticket = ts_load_ticket($id);
  $lifecycle = ts_compute_lifecycle_stage($ticket, $agentInfo);

  ts_api_json_ok([
    'ticket_id'       => $id,
    'lifecycle_stage'  => $lifecycle,
    'run_status'       => $runResult['status'] ?? null,
    'message'          => 'Ticket resubmitted for re-analysis',
  ]);
}

/* ── reject_with_direction — reject AI plan + provide mandatory new direction, re-analyze ── */
if ($action === 'reject_with_direction' && $method === 'POST') {
  $id = $input['id'] ?? '';
  $direction = trim($input['direction'] ?? '');
  if (!$id) ts_api_json_err('Missing ticket ID');
  if ($direction === '') ts_api_json_err('Direction text is required');

  $path = ts_get_ticket_path($id);
  if (!$path) ts_api_json_err('Ticket not found', 404);

  $ticket = json_decode(file_get_contents($path), true);
  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if (!$agentTaskId) ts_api_json_err('No agent task associated with this ticket');

  $user = ts_get_session_user();
  $now = date('c');

  // Add the direction as a note for the conversation thread
  $ticket['notes'][] = [
    'text' => "[Admin Direction] " . $direction,
    'at'   => $now,
    'by'   => $user,
  ];
  $ticket['updated_at'] = $now;
  $ticket['status'] = 'in_progress';
  $ticket['status_history'][] = [
    'status' => 'in_progress',
    'at'     => $now,
    'by'     => $user,
    'note'   => 'Plan rejected with new direction — re-analyzing',
  ];

  @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

  // Call AgentEngine.rejectWithDirection — stores direction + re-runs analysis
  $asModule = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';
  $runResult = null;

  if (is_file($asModule) && is_dir($asDataDir)) {
    require_once $asModule;
    $engine = new \AgentEngine($asDataDir);
    $runResult = $engine->rejectWithDirection($agentTaskId, $user, $direction);
    if (!$runResult) ts_api_json_err('Failed to reject task — task not found or not in rejectable state');
  } else {
    ts_api_json_err('AgentScheduler module not available', 500);
  }

  $agentInfo = ts_get_agent_status($agentTaskId);
  $ticket = ts_load_ticket($id);
  $lifecycle = ts_compute_lifecycle_stage($ticket, $agentInfo);

  ts_api_json_ok([
    'ticket_id'       => $id,
    'lifecycle_stage'  => $lifecycle,
    'run_status'       => $runResult['status'] ?? null,
    'message'          => 'Plan rejected with new direction — re-analyzing',
  ]);
}

/* ── download_pickup — ZIP download of ticket + plan + impl guide + page files ── */
if ($action === 'download_pickup') {
  $id = $_GET['id'] ?? '';
  if (!preg_match('/^TS-\d+-\d+$/', $id)) ts_api_json_err('Invalid ticket ID');

  $ticketPath = ts_get_ticket_path($id);
  if (!$ticketPath) ts_api_json_err('Ticket not found', 404);
  $ticket = json_decode(file_get_contents($ticketPath), true);
  if (!$ticket) ts_api_json_err('Cannot parse ticket');

  // Load agent plan from AgentScheduler plans dir
  $plan = null;
  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if ($agentTaskId) {
    $planFile = SITE_ROOT . '/admin/data/AgentScheduler/plans/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $agentTaskId) . '.json';
    if (is_file($planFile)) {
      $plan = json_decode(file_get_contents($planFile), true);
    }
  }

  // Load implementation guide
  $implContent = null;
  $implFile = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . $id . '_implementation.md';
  if (is_file($implFile)) $implContent = file_get_contents($implFile);

  // Load target page files for page_content tickets
  $pageJson = null;
  $pageCss  = null;
  $pageSlug = null;
  if (($ticket['category'] ?? '') === 'page_content' && !empty($ticket['page_content_details'])) {
    $pcd = $ticket['page_content_details'];
    $site = preg_replace('/[^a-z0-9.\-]/', '', $pcd['site'] ?? '');
    $pageSlug = preg_replace('/[^a-z0-9\-_]/', '', $pcd['page_slug'] ?? '');
    if ($site && $pageSlug) {
      $pageFile = '/var/www/vhosts/' . $site . '/admin/data/pages/' . $pageSlug . '/' . $pageSlug . '.json';
      if (is_file($pageFile)) $pageJson = file_get_contents($pageFile);
      $cssFile = '/var/www/vhosts/' . $site . '/admin/data/pages/' . $pageSlug . '/' . $pageSlug . '.css';
      if (is_file($cssFile)) $pageCss = file_get_contents($cssFile);
    }
  }

  // Build ZIP
  $zip = new \ZipArchive();
  $tmpFile = tempnam(sys_get_temp_dir(), 'ts_pickup_');
  if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
    ts_api_json_err('Failed to create ZIP archive');
  }

  $prefix = $id . '_pickup';

  // README.md
  $readme = "# Manual Pickup: " . ($ticket['subject'] ?? $id) . "\n\n";
  $readme .= "| Field | Value |\n|-------|-------|\n";
  $readme .= "| Ticket | {$id} |\n";
  $readme .= "| Domain | " . ($ticket['domain'] ?? '') . " |\n";
  $readme .= "| Category | " . ($ticket['category'] ?? '') . " |\n";
  $readme .= "| Urgency | " . ($ticket['urgency'] ?? '') . " |\n";
  $readme .= "| Submitted | " . ($ticket['submitted_at'] ?? '') . " |\n";
  $readme .= "| Status | " . ($ticket['status'] ?? '') . " |\n\n";

  if (!empty($ticket['description'])) {
    $readme .= "## Description\n\n" . $ticket['description'] . "\n\n";
  }

  // Page content details section
  if (!empty($ticket['page_content_details'])) {
    $pcd = $ticket['page_content_details'];
    $readme .= "## Page Content Details\n\n";
    $readme .= "- **Site:** " . ($pcd['site'] ?? '') . "\n";
    $readme .= "- **Page:** " . ($pcd['page_title'] ?? $pcd['page_slug'] ?? '') . " (`" . ($pcd['page_slug'] ?? '') . "`)\n";
    if (!empty($pcd['change_types'])) $readme .= "- **Change Types:** " . implode(', ', $pcd['change_types']) . "\n";
    if (!empty($pcd['description'])) $readme .= "- **Request:** " . $pcd['description'] . "\n";
    $readme .= "\n";
  }

  // Module request details section
  if (!empty($ticket['module_request_details'])) {
    $mr = $ticket['module_request_details'];
    $readme .= "## Module Request Details\n\n";
    if (!empty($mr['nature'])) $readme .= "- **Nature:** " . str_replace('_', ' ', $mr['nature']) . "\n";
    if (!empty($mr['ai_integration'])) $readme .= "- **AI Integration:** " . $mr['ai_integration'] . "\n";
    if (!empty($mr['protocols'])) $readme .= "- **Protocols:** " . (is_array($mr['protocols']) ? implode(', ', $mr['protocols']) : $mr['protocols']) . "\n";
    if (!empty($mr['target_audience'])) $readme .= "- **Target:** " . str_replace('_', ' ', $mr['target_audience']) . "\n";
    if (!empty($mr['brief'])) $readme .= "- **Brief:** " . $mr['brief'] . "\n";
    $readme .= "\n";
  }

  // Diagnosis and proposed changes from plan
  if ($plan) {
    if (!empty($plan['diagnosis'])) {
      $readme .= "## Diagnosis\n\n" . $plan['diagnosis'] . "\n\n";
    }
    if (!empty($plan['proposed_changes'])) {
      $readme .= "## Proposed Changes\n\n";
      foreach ($plan['proposed_changes'] as $i => $c) {
        $readme .= ($i + 1) . ". **`" . ($c['file'] ?? $c['path'] ?? '') . "`** — " . ($c['description'] ?? $c['change_description'] ?? '') . "\n";
      }
      $readme .= "\n";
    }
    if (!empty($plan['risk_assessment'])) $readme .= "**Risk:** " . $plan['risk_assessment'] . "\n";
    if (!empty($plan['estimated_effort'])) $readme .= "**Effort:** " . $plan['estimated_effort'] . "\n\n";
  }

  $readme .= "---\n\n*Generated by Luminal CMS TechSupport — " . date('Y-m-d H:i:s T') . "*\n";
  $zip->addFromString($prefix . '/README.md', $readme);

  // ticket.json (full ticket data)
  $ticketClean = $ticket;
  unset($ticketClean['screenshot']); // Don't include base64 screenshot in ZIP
  $zip->addFromString($prefix . '/ticket.json', json_encode($ticketClean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

  // plan.md
  if ($plan) {
    $planMd = "# Agent Analysis Plan\n\n";
    if (!empty($plan['diagnosis'])) $planMd .= "## Diagnosis\n\n" . $plan['diagnosis'] . "\n\n";
    if (!empty($plan['proposed_changes'])) {
      $planMd .= "## Proposed Changes\n\n";
      foreach ($plan['proposed_changes'] as $i => $c) {
        $planMd .= ($i + 1) . ". **`" . ($c['file'] ?? $c['path'] ?? '') . "`** — " . ($c['description'] ?? $c['change_description'] ?? '') . "\n";
      }
      $planMd .= "\n";
    }
    if (!empty($plan['risk_assessment'])) $planMd .= "**Risk:** " . $plan['risk_assessment'] . "\n";
    if (!empty($plan['estimated_effort'])) $planMd .= "**Effort:** " . $plan['estimated_effort'] . "\n\n";
    if (!empty($plan['ai_reasoning'])) $planMd .= "## AI Reasoning\n\n" . $plan['ai_reasoning'] . "\n";
    $zip->addFromString($prefix . '/plan.md', $planMd);
  }

  // implementation-guide.md
  if ($implContent) {
    $zip->addFromString($prefix . '/implementation-guide.md', $implContent);
  }

  // Page files (for page_content tickets)
  if ($pageSlug) {
    if ($pageJson) $zip->addFromString($prefix . '/pages/' . $pageSlug . '/' . $pageSlug . '.json', $pageJson);
    if ($pageCss) $zip->addFromString($prefix . '/pages/' . $pageSlug . '/' . $pageSlug . '.css', $pageCss);
  }

  // Screenshot (if exists as file on disk)
  $screenFile = null;
  foreach (['png', 'jpg', 'gif', 'webp'] as $ext) {
    $candidate = $TS_SCREENS_DIR . '/' . $id . '.' . $ext;
    if (is_file($candidate)) { $screenFile = $candidate; break; }
  }
  if ($screenFile) {
    $zip->addFile($screenFile, $prefix . '/screenshot.' . pathinfo($screenFile, PATHINFO_EXTENSION));
  }

  $zip->close();

  // Override JSON content type — stream ZIP
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . $id . '_pickup.zip"');
  header('Content-Length: ' . filesize($tmpFile));
  header('Cache-Control: no-cache');
  readfile($tmpFile);
  @unlink($tmpFile);
  exit;
}

/* ── get_implementation — return implementation guide markdown ── */
if ($action === 'get_implementation') {
  $id = $_GET['id'] ?? $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!preg_match('/^TS-\d+-\d+$/', $id)) ts_api_json_err('Invalid ticket ID');

  $implFile = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . $id . '_implementation.md';
  if (!is_file($implFile)) ts_api_json_err('No implementation guide found', 404);

  $content = file_get_contents($implFile);
  $size = filesize($implFile);
  $modified = date('c', filemtime($implFile));

  ts_api_json_ok([
    'ticket_id' => $id,
    'content'   => $content,
    'size'      => $size,
    'modified'  => $modified,
  ]);
}

/* ── apply_changes — trigger Phase 3 via AgentScheduler ── */
if ($action === 'apply_changes' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $ticket = ts_load_ticket($id);
  if (!$ticket) ts_api_json_err('Ticket not found', 404);

  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if (!$agentTaskId) ts_api_json_err('No agent task associated with this ticket');

  $asModule = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';

  if (!is_file($asModule) || !is_dir($asDataDir)) {
    ts_api_json_err('AgentScheduler module not available', 500);
  }

  require_once $asModule;
  $engine = new \AgentEngine($asDataDir);
  $user = ts_get_session_user();

  $result = $engine->applyChanges($agentTaskId, $user);
  if (!$result) ts_api_json_err('Agent task not found');
  if (($result['status'] ?? '') === 'error' && isset($result['error'])) {
    ts_api_json_err($result['error']);
  }

  // Reload ticket for updated lifecycle
  $agentInfo = ts_get_agent_status($agentTaskId);
  $ticket = ts_load_ticket($id);
  $lifecycle = ts_compute_lifecycle_stage($ticket, $agentInfo);

  ts_api_json_ok([
    'ticket_id'       => $id,
    'lifecycle_stage'  => $lifecycle,
    'run_status'       => $result['status'] ?? null,
    'message'          => 'Changes applied',
  ]);
}

/* ── rollback_changes — restore files from backup manifest ── */
if ($action === 'rollback_changes' && $method === 'POST') {
  $id = $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');

  $ticket = ts_load_ticket($id);
  if (!$ticket) ts_api_json_err('Ticket not found', 404);

  $agentTaskId = $ticket['agent_task_id'] ?? '';
  if (!$agentTaskId) ts_api_json_err('No agent task associated with this ticket');

  $asModule = SITE_ROOT . '/admin/modules/AgentScheduler/AgentEngine.php';
  $asDataDir = SITE_ROOT . '/admin/data/AgentScheduler';

  if (!is_file($asModule) || !is_dir($asDataDir)) {
    ts_api_json_err('AgentScheduler module not available', 500);
  }

  require_once $asModule;
  $engine = new \AgentEngine($asDataDir);

  $result = $engine->rollbackChanges($agentTaskId);
  if (!($result['ok'] ?? false)) {
    ts_api_json_err($result['error'] ?? 'Rollback failed');
  }

  // Update ticket notes
  $path = ts_get_ticket_path($id);
  if ($path) {
    $ticket = json_decode(file_get_contents($path), true);
    $ticket['notes'][] = [
      'text'   => "Changes rolled back — {$result['restored']} files restored from backup.",
      'at'     => date('c'),
      'by'     => ts_get_session_user(),
      'source' => 'rollback',
    ];
    $ticket['updated_at'] = date('c');
    @file_put_contents($path, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  $agentInfo = ts_get_agent_status($agentTaskId);
  $ticket = ts_load_ticket($id);
  $lifecycle = ts_compute_lifecycle_stage($ticket, $agentInfo);

  ts_api_json_ok([
    'ticket_id'       => $id,
    'lifecycle_stage'  => $lifecycle,
    'restored'         => $result['restored'],
    'warnings'         => $result['warnings'] ?? [],
    'message'          => "Rolled back — {$result['restored']} files restored",
  ]);
}

/* ── get_apply_manifest — return manifest data for UI display ── */
if ($action === 'get_apply_manifest') {
  $id = $_GET['id'] ?? $input['id'] ?? '';
  if (!$id) ts_api_json_err('Missing ticket ID');
  if (!preg_match('/^TS-\d+-\d+$/', $id)) ts_api_json_err('Invalid ticket ID');

  $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
  $manifestFile = SITE_ROOT . '/admin/data/support_tickets/agent_tasks/' . $safeId . '_apply_manifest.json';

  if (!is_file($manifestFile)) ts_api_json_err('No apply manifest found', 404);

  $manifest = json_decode(file_get_contents($manifestFile), true);
  if (!is_array($manifest)) ts_api_json_err('Cannot parse manifest');

  ts_api_json_ok(['manifest' => $manifest]);
}

// ── Send to Claude Code CLI ─────────────────────────────────────────────
if ($action === 'send_to_cli' && $method === 'POST') {
    $id = $input['id'] ?? '';
    if (!$id) ts_api_json_err('Ticket ID required');

    // Load ticket
    $ticketFile = null;
    foreach ([$TS_INBOX_DIR, $TS_ARCHIVE_DIR] as $dir) {
        $candidate = $dir . '/' . basename($id) . '.json';
        if (is_file($candidate)) { $ticketFile = $candidate; break; }
    }
    if (!$ticketFile) ts_api_json_err('Ticket not found', 404);
    $ticket = json_decode(file_get_contents($ticketFile), true);

    // Build CLI inbox entry
    $cliInbox = SITE_ROOT . '/admin/data/AgentScheduler/cli-inbox';
    if (!is_dir($cliInbox)) mkdir($cliInbox, 0775, true);

    $cliTicket = [
        'source'          => 'tech_support',
        'ticket_id'       => $ticket['id'] ?? $id,
        'subject'         => $ticket['subject'] ?? '',
        'description'     => $ticket['description'] ?? '',
        'domain'          => $ticket['domain'] ?? '',
        'category'        => $ticket['category'] ?? '',
        'urgency'         => $ticket['urgency'] ?? '',
        'status'          => $ticket['status'] ?? '',
        'submitted_by'    => $ticket['submitted_by'] ?? '',
        'agent_task_id'   => $ticket['agent_task_id'] ?? '',
        'last_error'      => $ticket['last_error'] ?? ($ticket['agent_error'] ?? ''),
        'notes'           => $input['notes'] ?? '',
        'flagged_at'      => date('c'),
        'flagged_by'      => $_SESSION['admin_user'] ?? 'admin',
    ];

    // Include agent task details if available
    if (!empty($ticket['agent_task_id'])) {
        $agentTaskFile = SITE_ROOT . '/admin/data/AgentScheduler/tasks/' . basename($ticket['agent_task_id']) . '.json';
        if (is_file($agentTaskFile)) {
            $agentTask = json_decode(file_get_contents($agentTaskFile), true);
            if ($agentTask) {
                $cliTicket['agent_task'] = [
                    'pipeline'     => $agentTask['pipeline'] ?? '',
                    'last_status'  => $agentTask['last_status'] ?? $agentTask['status'] ?? '',
                    'last_error'   => $agentTask['last_error'] ?? '',
                    'config'       => $agentTask['config'] ?? [],
                ];
            }
        }
    }

    // Include solution/implementation if exists
    $implFile = SITE_ROOT . '/admin/data/support_tickets/implementations/' . basename($id) . '.md';
    if (is_file($implFile)) {
        $cliTicket['implementation_guide'] = file_get_contents($implFile);
    }

    $cliFile = $cliInbox . '/TS-' . basename($id) . '.json';
    file_put_contents($cliFile, json_encode($cliTicket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($cliFile, 0664);

    // Update ticket status
    $ticket['cli_flagged'] = true;
    $ticket['cli_flagged_at'] = date('c');
    $ticket['lifecycle_stage'] = 'cli_pending';
    $ticket['status'] = 'in_progress';
    file_put_contents($ticketFile, json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    ts_api_json_ok(['sent' => true, 'cli_file' => basename($cliFile)]);
}

/* ── fallthrough ── */
if (!$action) ts_api_json_err('Missing action parameter');
ts_api_json_err('Unknown action: ' . $action);
