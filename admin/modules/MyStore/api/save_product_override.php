<?php
declare(strict_types=1);
/**
 * =============================================================================
 * @appname  Earl's PayPal Admin — Save Product Override (AJAX)
 * @file     /admin/paypal/ajax/save_product_override.php
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
    $overrides[$sku] = []; // Create if it doesn't exist
}

// Update fields if they are present in the POST request
if (isset($_POST['name']))    $overrides[$sku]['name'] = trim((string)$_POST['name']);
if (isset($_POST['price']))   $overrides[$sku]['price'] = number_format((float)$_POST['price'], 2, '.', '');
if (isset($_POST['desc']))    $overrides[$sku]['desc'] = trim((string)$_POST['desc']);
if (isset($_POST['visible'])) $overrides[$sku]['visible'] = ($_POST['visible'] === '1');

// Handle variations
if (isset($_POST['v1n']) || isset($_POST['v1o']) || isset($_POST['v2n']) || isset($_POST['v2o'])) {
    $overrides[$sku]['variations'] = [];
    $v1n = trim((string)($_POST['v1n'] ?? ''));
    $v1o = array_filter(array_map('trim', explode('|', (string)($_POST['v1o'] ?? ''))));
    if ($v1n && !empty($v1o)) {
        $overrides[$sku]['variations'][] = ['name' => $v1n, 'options' => $v1o];
    }
    $v2n = trim((string)($_POST['v2n'] ?? ''));
    $v2o = array_filter(array_map('trim', explode('|', (string)($_POST['v2o'] ?? ''))));
    if ($v2n && !empty($v2o)) {
        $overrides[$sku]['variations'][] = ['name' => $v2n, 'options' => $v2o];
    }
}

if (file_put_contents($overrides_file, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    j_exit(['error' => 'Failed to save overrides file.'], true);
}

j_exit(['sku' => $sku, 'message' => 'Product updated successfully.']);
?>