<?php
/**
 * Admin Menu Config API
 * @file  /admin/ajax/admin_menu_config.php
 * @auth  requires /admin/auth.php
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//
//  This endpoint WRITES the site's navigation and had no authentication of any kind:
//  no guard, no session check, no CSRF token. Anonymous requests reached the JSON parse
//  and, with a well-formed body, would have rewritten the menu on any of the 30 sites.
//  Found by the web-exposure audit, which flagged the file as reachable.
//
//  guard_require_auth() answers API requests with 401 JSON and browser requests with a
//  redirect to the login page, so this is safe for both callers.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 4));
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$path = SITE_ROOT . '/admin/data/admin-menu.json';

function read_json($p){ if(!is_file($p)) return ['items'=>[]]; $r=@file_get_contents($p); $j=json_decode($r??'',true); return is_array($j)?$j:['items'=>[]]; }
function write_json($p,$data){ $dir=dirname($p); if(!is_dir($dir)) @mkdir($dir,0775,true); $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); return (bool)@file_put_contents($p,$json,LOCK_EX); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  echo json_encode(['ok'=>true, 'data'=>read_json($path)], JSON_UNESCAPED_SLASHES);
  exit;
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    echo json_encode(['ok'=>false,'error'=>'Invalid payload: expected {items:[...]}'], JSON_UNESCAPED_SLASHES);
    exit;
  }
  $ok = write_json($path, $data);
  echo json_encode(['ok'=>$ok], JSON_UNESCAPED_SLASHES);
  exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
?>