<?php
/**
 * PiP Layout API — save/load/list/delete named layouts.
 */
if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 2));
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

header('Content-Type: application/json');

$user = getCurrentUser();
$username = preg_replace('/[^a-z0-9_-]/', '', strtolower($user['username'] ?? 'admin'));
$layoutDir = SITE_ROOT . '/admin/data/pip-layouts/' . $username;
if (!is_dir($layoutDir)) @mkdir($layoutDir, 0775, true);

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? $action;
}

switch ($action) {
    case 'save_layout':
        $name = preg_replace('/[^a-zA-Z0-9_ -]/', '', $body['name'] ?? '');
        if (!$name) { echo json_encode(['ok' => false, 'error' => 'Name required']); exit; }
        $layout = $body['layout'] ?? [];
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        $file = $layoutDir . '/' . $slug . '.json';
        file_put_contents($file, json_encode([
            'name' => $name,
            'layout' => $layout,
            'saved_at' => date('c'),
        ], JSON_PRETTY_PRINT));
        @chmod($file, 0664);
        echo json_encode(['ok' => true]);
        break;

    case 'load_layout':
        $name = preg_replace('/[^a-zA-Z0-9_ -]/', '', $_GET['name'] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        $file = $layoutDir . '/' . $slug . '.json';
        if (!is_file($file)) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
        $data = json_decode(file_get_contents($file), true);
        echo json_encode(['ok' => true, 'name' => $data['name'] ?? $name, 'layout' => $data['layout'] ?? []]);
        break;

    case 'list_layouts':
        $layouts = [];
        foreach (glob($layoutDir . '/*.json') as $f) {
            $d = json_decode(file_get_contents($f), true);
            $layouts[] = ['name' => $d['name'] ?? basename($f, '.json'), 'saved_at' => $d['saved_at'] ?? ''];
        }
        echo json_encode(['ok' => true, 'layouts' => $layouts]);
        break;

    case 'delete_layout':
        $name = preg_replace('/[^a-zA-Z0-9_ -]/', '', $body['name'] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        $file = $layoutDir . '/' . $slug . '.json';
        if (is_file($file)) @unlink($file);
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
