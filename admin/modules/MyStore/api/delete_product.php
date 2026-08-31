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
 * =============================================================================
 * @appname  Earl's PayPal Admin — Delete Product (AJAX)
 * @file     /admin/paypal/ajax/delete_product.php
 * @version  2025.09.29.FINAL
 * =============================================================================
 */
header('Content-Type: application/json; charset=utf-8');
if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));

function j_exit($data, $is_error = false) {
    if ($is_error) http_response_code(400);
    echo json_encode(['ok' => !$is_error] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') j_exit(['error' => 'Invalid request method.'], true);

$sku = trim((string)($_POST['sku'] ?? ''));
if ($sku === '') j_exit(['error' => 'SKU is required.'], true);

$overrides_file = SITE_ROOT . '/admin/data/mystore/products_overrides.json';
$overrides = is_file($overrides_file) ? json_decode(file_get_contents($overrides_file) ?: '[]', true) : [];
if (!is_array($overrides)) $overrides = [];

if (!isset($overrides[$sku])) {
    j_exit(['error' => 'Product not found in overrides.'], true);
}

// Perform a soft delete by adding a "deleted" flag
$overrides[$sku]['deleted'] = true;
$overrides[$sku]['visible'] = false; // Also hide it

if (file_put_contents($overrides_file, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    j_exit(['error' => 'Failed to update overrides file for deletion.'], true);
}

j_exit(['sku' => $sku, 'message' => 'Product has been deleted.']);
?>