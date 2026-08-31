<?php
declare(strict_types=1);
/**
 * @file     /admin/paypal/ajax/save_product.php
 * @version  2025.09.16.sp4
 * @input    JSON { sku, name, price, desc, enabled, variations }
 * @effect   Writes /admin/data/mystore/overrides/{sku}.json atomically.
 * @return   { ok, sku, published:1, enabled }
 */
@header('Content-Type: application/json; charset=UTF-8');
function jx($o){ echo json_encode($o, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));

$raw = file_get_contents('php://input'); $in = json_decode((string)$raw, true);
$sku = trim((string)($in['sku'] ?? ''));
if ($sku==='') jx(['ok'=>false,'error'=>'Missing SKU']);

$dir = SITE_ROOT . '/admin/data/mystore/overrides';
@mkdir($dir, 0775, true);
$file = $dir . '/' . $sku . '.json';

$doc = [
  'name'      => (string)($in['name'] ?? ''),
  'price'     => (float)preg_replace('/[^\d.]/','', (string)($in['price'] ?? '0')),
  'desc'      => (string)($in['desc'] ?? ''),
  'enabled'   => (int)($in['enabled'] ?? 1),
  'variations'=> (is_array($in['variations'] ?? null) ? $in['variations'] : ['groups'=>[]]),
];

$tmp = $file . '.tmp';
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false){
  jx(['ok'=>false,'error'=>'Write failed']);
}
@rename($tmp, $file);

jx(['ok'=>true, 'sku'=>$sku, 'published'=>1, 'enabled'=>$doc['enabled']]);
?>