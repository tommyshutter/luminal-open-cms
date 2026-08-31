<?php
/**
 * @appname  Earl’s CMS
 * @file     /admin/ajax/admin_bg_api.php
 * @version  2025.09.24.1529.00-EST
 * @author   ChatGPT
 * @license  Business Source License
 * @description
 * Admin background JSON API:
 *   GET  → { ok, image, url, opacity, diag }
 *   POST (multipart: file, opacity?) → saves /admin/data/admin_bg/<name>.<ts>.<ext>
 *   POST (application/json: {opacity}) → updates opacity only
 * Allowed: jpg,jpeg,png,webp
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
header('Content-Type: application/json; charset=UTF-8');

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}

$DATA_DIR = SITE_ROOT . '/admin/data/admin_bg';
$SETTINGS = $DATA_DIR . '/settings.json';
$LOGFILE  = $DATA_DIR . '/upload.log';
$ALLOWED  = ['jpg','jpeg','png','webp'];

@is_dir($DATA_DIR) || @mkdir($DATA_DIR, 0775, true);

function slog(string $f, string $m): void {
  @file_put_contents($f, '['.date('Y-m-d H:i:s')."] $m\n", FILE_APPEND);
}
function read_settings(string $path): array {
  $cfg = ['image'=>'','opacity'=>0.5];
  if (is_file($path)) {
    $raw = @file_get_contents($path);
    if ($raw !== false) { $j = json_decode($raw, true); if (is_array($j)) $cfg = array_merge($cfg, $j); }
  }
  return $cfg;
}
function write_settings(string $path, array $cfg, string $log): bool {
  $json = json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
  $ok = (bool)@file_put_contents($path, $json);
  if (!$ok) slog($log, "ERROR writing settings.json at $path");
  return $ok;
}
function upload_err_name(int $code): string {
  return [
    UPLOAD_ERR_OK=>'OK', UPLOAD_ERR_INI_SIZE=>'INI_SIZE', UPLOAD_ERR_FORM_SIZE=>'FORM_SIZE',
    UPLOAD_ERR_PARTIAL=>'PARTIAL', UPLOAD_ERR_NO_FILE=>'NO_FILE', UPLOAD_ERR_NO_TMP_DIR=>'NO_TMP_DIR',
    UPLOAD_ERR_CANT_WRITE=>'CANT_WRITE', UPLOAD_ERR_EXTENSION=>'EXTENSION'
  ][$code] ?? ('ERR_'.$code);
}

$cfg = read_settings($SETTINGS);

// Optional auth include
$auth = SITE_ROOT . '/admin/auth.php'; if (is_file($auth)) { @include $auth; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$diag = [
  'site_root'      => SITE_ROOT,
  'data_dir'       => $DATA_DIR,
  'dir_exists'     => is_dir($DATA_DIR),
  'dir_writable'   => is_writable($DATA_DIR),
  'settings_exists'=> is_file($SETTINGS),
  'php_user'       => get_current_user(),
];

if ($method === 'GET') {
  $url = $cfg['image'] ? '/admin/data/admin_bg/' . rawurlencode($cfg['image']) : '';
  echo json_encode([
    'ok'=>true, 'image'=>$cfg['image'], 'url'=>$url, 'opacity'=>$cfg['opacity'],
    'diag'=>($diag['dir_exists']? 'dir:ok':'dir:missing') . ', writable=' . ($diag['dir_writable']?'yes':'no')
  ]);
  exit;
}

if ($method === 'POST') {
  $ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

  // JSON: opacity only
  if (stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['opacity'])) {
      $op = max(0.1, min(0.9, floatval($j['opacity'])));
      $cfg['opacity'] = $op;
      if (!write_settings($SETTINGS, $cfg, $LOGFILE)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to write settings.json']); exit; }
      $url = $cfg['image'] ? '/admin/data/admin_bg/' . rawurlencode($cfg['image']) : '';
      echo json_encode(['ok'=>true, 'image'=>$cfg['image'], 'url'=>$url, 'opacity'=>$cfg['opacity']]); exit;
    }
  }

  // Multipart: file + optional opacity
  $op = isset($_POST['opacity']) ? max(0.1, min(0.9, floatval($_POST['opacity']))) : $cfg['opacity'];

  if (!isset($_FILES['file'])) {
    // opacity-only via form
    $cfg['opacity'] = $op;
    if (!write_settings($SETTINGS, $cfg, $LOGFILE)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to write settings.json']); exit; }
    $url = $cfg['image'] ? '/admin/data/admin_bg/' . rawurlencode($cfg['image']) : '';
    echo json_encode(['ok'=>true, 'image'=>$cfg['image'], 'url'=>$url, 'opacity'=>$cfg['opacity']]); exit;
  }

  $f     = $_FILES['file'];
  $err   = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
  $tmp   = $f['tmp_name'] ?? '';
  $fname = $f['name'] ?? 'upload';

  if ($err !== UPLOAD_ERR_OK) {
    $msg = 'Upload error: ' . upload_err_name($err) . " (code=$err)";
    slog($LOGFILE, $msg);
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
  }

  if (!$diag['dir_exists'] && !@mkdir($DATA_DIR, 0775, true)) {
    $msg = 'Data dir missing and could not be created: ' . $DATA_DIR;
    slog($LOGFILE, $msg);
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
  }
  if (!is_writable($DATA_DIR)) {
    $msg = 'Data dir not writable: ' . $DATA_DIR;
    slog($LOGFILE, $msg);
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
  }

  $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
  if (!in_array($ext, $ALLOWED, true)) {
    http_response_code(400); echo json_encode(['ok'=>false, 'error'=>'Unsupported file type']); exit;
  }

  $base  = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($fname, PATHINFO_FILENAME));
  $final = $base . '.' . date('Ymd_His') . '.' . $ext;
  $dest  = $DATA_DIR . '/' . $final;

  $moved = @move_uploaded_file($tmp, $dest);
  if (!$moved) { $moved = @copy($tmp, $dest); }

  if (!$moved || !is_file($dest)) {
    $msg = 'Failed to save file. tmp=' . $tmp . ' → dest=' . $dest;
    slog($LOGFILE, $msg);
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$msg,'diag_path'=>$dest]); exit;
  }

  @chmod($dest, 0644);

  $cfg['image']   = $final;
  $cfg['opacity'] = $op;
  if (!write_settings($SETTINGS, $cfg, $LOGFILE)) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Saved image but failed to write settings.json']); exit;
  }

  $url = '/admin/data/admin_bg/' . rawurlencode($final);
  echo json_encode(['ok'=>true, 'image'=>$final, 'url'=>$url, 'opacity'=>$cfg['opacity']]); exit;
}

http_response_code(405);
echo json_encode(['ok'=>false, 'error'=>'Method Not Allowed']);
?>