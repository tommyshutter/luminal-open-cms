<?php
/**
 * MDEditor API — Document CRUD
 *
 * Actions: list, load, save, delete
 * Storage: admin/data/md-documents/{slug}.json
 *
 * @package    LuminalCMS
 * @module     MDEditor
 * @version    1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

header('Content-Type: application/json');

$docsDir = SITE_ROOT . '/admin/data/md-documents/';
if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0775, true);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        md_list_docs($docsDir);
        break;
    case 'load':
        md_load_doc($docsDir);
        break;
    case 'save':
        md_save_doc($docsDir);
        break;
    case 'delete':
        md_delete_doc($docsDir);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

exit;

function md_list_docs(string $dir): void {
    $docs = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*.json') as $file) {
            $data = @json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) continue;
            $docs[] = [
                'slug'       => $data['slug'] ?? basename($file, '.json'),
                'title'      => $data['title'] ?? basename($file, '.json'),
                'preview'    => mb_substr($data['markdown'] ?? '', 0, 80),
                'updated_at' => $data['updated_at'] ?? '',
                'created_at' => $data['created_at'] ?? '',
            ];
        }
        usort($docs, function ($a, $b) {
            return strcmp($b['updated_at'] ?: $b['created_at'], $a['updated_at'] ?: $a['created_at']);
        });
    }
    echo json_encode(['success' => true, 'docs' => $docs]);
}

function md_load_doc(string $dir): void {
    $slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    if ($slug === '') {
        echo json_encode(['success' => false, 'message' => 'No slug provided.']);
        return;
    }
    $file = $dir . $slug . '.json';
    if (!is_file($file)) {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        return;
    }
    $data = @json_decode((string)file_get_contents($file), true);
    echo json_encode(['success' => true, 'doc' => $data]);
}

function md_save_doc(string $dir): void {
    $title    = trim($_POST['title'] ?? '');
    $markdown = $_POST['markdown'] ?? '';

    if ($title === '') {
        echo json_encode(['success' => false, 'message' => 'Title is required.']);
        return;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'doc-' . time();

    $file = $dir . $slug . '.json';
    $now  = gmdate('Y-m-d\TH:i:s\Z');

    $existing = is_file($file) ? @json_decode((string)file_get_contents($file), true) : null;

    $doc = [
        'title'      => $title,
        'slug'       => $slug,
        'markdown'   => $markdown,
        'created_at' => ($existing['created_at'] ?? '') ?: $now,
        'updated_at' => $now,
    ];

    $written = file_put_contents($file, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($written !== false) {
        @chmod($file, 0664);
        echo json_encode(['success' => true, 'slug' => $slug, 'message' => 'Saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to write file.']);
    }
}

function md_delete_doc(string $dir): void {
    $slug = $_POST['slug'] ?? '';
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    if ($slug === '') {
        echo json_encode(['success' => false, 'message' => 'No slug provided.']);
        return;
    }
    $file = $dir . $slug . '.json';
    if (is_file($file)) {
        @unlink($file);
        echo json_encode(['success' => true, 'message' => 'Deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
    }
}
