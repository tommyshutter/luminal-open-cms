<?php
declare(strict_types=1);
/**
 * @file     /admin/modules/MyStore/api/order-receipt-upload.php
 * @purpose  Drag-n-drop auto-save of a shipping receipt image for an order, plus removal.
 * @input    multipart/form-data { op:upload, order_id, receipt(file) }  OR  { op:remove, order_id }
 * @effect   Stores image under /media/store/receipts/{order}/receipt-{hash}.{ext}
 *           and records shipping.receipt_url / receipt_uploaded_at on the order in orders.json.
 * @return   { ok, url } | { ok, removed } | { ok:false, error }
 */
@header('Content-Type: application/json; charset=UTF-8');
function jx($o){ echo json_encode($o, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();
if (!defined('PAYPAL_SYSTEM'))  define('PAYPAL_SYSTEM', true);
if (!defined('MYSTORE_SYSTEM')) define('MYSTORE_SYSTEM', true);
require_once __DIR__ . '/../includes/mystore-admin-functions.php';
require_once __DIR__ . '/../includes/mystore-config.php';
global $MYSTORE_DATA_BASE, $MYSTORE_MEDIA_BASE;

$op  = $_POST['op'] ?? 'upload';
$oid = trim((string)($_POST['order_id'] ?? ''));
if ($oid === '') jx(['ok'=>false, 'error'=>'Missing order_id']);

// Safe slug for filesystem path (the order_id itself is matched verbatim in JSON)
$slug = preg_replace('/[^A-Za-z0-9._-]/', '_', $oid);

$ordersFile = SITE_ROOT . $MYSTORE_DATA_BASE . 'orders.json';
$orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
if (!is_array($orders)) $orders = [];

$found = false;
foreach ($orders as $o) { if (($o['order_id'] ?? '') === $oid) { $found = true; break; } }
if (!$found) jx(['ok'=>false, 'error'=>'Order not found']);

$receiptDirRel = $MYSTORE_MEDIA_BASE . 'receipts/' . $slug;          // /media/store/receipts/{slug}
$receiptDirAbs = SITE_ROOT . $receiptDirRel;

/* ---- Remove existing receipt ---- */
if ($op === 'remove') {
    if (is_dir($receiptDirAbs)) {
        foreach (glob($receiptDirAbs . '/receipt-*') ?: [] as $f) { @unlink($f); }
    }
    foreach ($orders as &$o) {
        if (($o['order_id'] ?? '') === $oid) {
            unset($o['fulfillment']['receipt_url'], $o['fulfillment']['receipt_uploaded_at']);
        }
    }
    unset($o);
    file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    jx(['ok'=>true, 'removed'=>true]);
}

/* ---- Upload ---- */
if (empty($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jx(['ok'=>false, 'error'=>'No file received']);
}
$tmp  = $_FILES['receipt']['tmp_name'];
$size = (int)$_FILES['receipt']['size'];
if ($size <= 0 || $size > 8 * 1024 * 1024) jx(['ok'=>false, 'error'=>'File must be 1 byte–8 MB']);

$allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp', 'application/pdf'=>'pdf'];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
}
if ($mime === '' && function_exists('mime_content_type')) $mime = (string)(mime_content_type($tmp) ?: '');
if (!isset($allowed[$mime])) jx(['ok'=>false, 'error'=>'Receipt must be JPG, PNG, GIF, WEBP, or PDF']);
$ext = $allowed[$mime];

if (!is_dir($receiptDirAbs) && !@mkdir($receiptDirAbs, 0775, true) && !is_dir($receiptDirAbs)) {
    jx(['ok'=>false, 'error'=>'Could not create receipt directory']);
}
// One receipt per order — clear any prior file
foreach (glob($receiptDirAbs . '/receipt-*') ?: [] as $f) { @unlink($f); }

$fname   = 'receipt-' . bin2hex(random_bytes(8)) . '.' . $ext;
$destAbs = $receiptDirAbs . '/' . $fname;
if (!@move_uploaded_file($tmp, $destAbs)) jx(['ok'=>false, 'error'=>'Could not save file']);
@chmod($destAbs, 0644);
$urlRel = $receiptDirRel . '/' . $fname;

foreach ($orders as &$o) {
    if (($o['order_id'] ?? '') === $oid) {
        if (!isset($o['fulfillment']) || !is_array($o['fulfillment'])) $o['fulfillment'] = [];
        $o['fulfillment']['receipt_url']         = $urlRel;
        $o['fulfillment']['receipt_uploaded_at'] = date('c');
    }
}
unset($o);
file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

jx(['ok'=>true, 'url'=>$urlRel]);
