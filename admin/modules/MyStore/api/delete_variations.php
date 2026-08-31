<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  AUTHENTICATION GATE — added 2026-08-30.
//  This endpoint writes and was reachable by an anonymous caller. Confirmed by probing:
//  it executed its handler for an unauthenticated GET rather than refusing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
/**
 * @file   /admin/paypal/ajax/delete_variations.php
 * @input  JSON { sku }
 * @effect Remove only the "variations" key from overrides JSON (if exists)
 */
@header('Content-Type: application/json; charset=UTF-8');
function jx($o){ echo json_encode($o, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));

$in = json_decode((string)file_get_contents('php://input'), true);
$sku = trim((string)($in['sku'] ?? ''));
if ($sku==='') jx(['ok'=>false,'error'=>'Missing SKU']);

$file = SITE_ROOT . '/admin/data/mystore/overrides/' . $sku . '.json';
if (!is_file($file)) jx(['ok'=>true,'sku'=>$sku,'changed'=>0]);

$j = json_decode((string)@file_get_contents($file), true);
if (!is_array($j)) $j=[];
unset($j['variations']);

$tmp = $file . '.tmp';
if (@file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false){
  jx(['ok'=>false,'error'=>'Write failed']);
}
@rename($tmp, $file);
jx(['ok'=>true,'sku'=>$sku,'changed'=>1]);
?>