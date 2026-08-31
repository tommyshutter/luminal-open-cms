<?php
/**
 * Luminal CMS — Page Manager API
 *
 * AJAX handler for page load, save, and delete operations.
 *
 * @package    LuminalCMS
 * @module     PageManager
 * @version    1.0.0
 * @file       /admin/modules/PageManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();
header('Content-Type: application/json');

$PAGES_DIR = SITE_ROOT . '/admin/data/pages/';
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (empty($action)) {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);
    $action = $data['action'] ?? null;
}

switch ($action) {
    case 'load': pm_handle_load(); break;
    case 'save': pm_handle_save(); break;
    case 'delete': pm_handle_delete($data ?? null); break;
    case 'list_aip_pages': pm_handle_list_aip(); break;
    case 'delete_aip_page': pm_handle_delete_aip($data ?? null); break;
    default: echo json_encode(['error' => 'Invalid action specified.']); exit;
}

function pm_handle_load() {
    global $PAGES_DIR;
    $page_name = $_GET['page_name'] ?? '';
    if (empty($page_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $page_name)) { echo json_encode(['error' => 'Invalid page name.']); exit; }
    $page_dir = $PAGES_DIR . $page_name;
    if (!is_dir($page_dir)) { echo json_encode(['error' => 'Page not found.']); exit; }
    $revisions = glob($page_dir . '/*.json');
    if (empty($revisions)) { echo json_encode(['error' => 'No revisions found.']); exit; }
    usort($revisions, fn($a, $b) => filemtime($b) - filemtime($a));
    echo file_get_contents($revisions[0]);
    exit;
}

function pm_handle_save() {
    global $PAGES_DIR;
    $page_name = $_POST['page_name'] ?? '';
    if (empty($page_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $page_name)) {
        echo json_encode(['error' => 'The Page Name (slug) is required and can only contain letters, numbers, hyphens, and underscores.']);
        exit;
    }
    if (empty($_POST['current_page_name']) && is_dir($PAGES_DIR . $page_name)) {
        echo json_encode(['error' => 'A page with this name already exists.']);
        exit;
    }
    $components_data = [];
    if (isset($_POST['components']) && is_array($_POST['components'])) {
        foreach ($_POST['components'] as $key => $values) {
            $sanitized_values = [];
            // Sanitize all fields EXCEPT the raw HTML content fields
            foreach ($values as $field => $value) {
                if (($key === 'main_content' || $key === 'right_column') && $field === 'content') {
                    $sanitized_values[$field] = $value;
                } else {
                    $sanitized_values[$field] = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
                }
            }
            $sanitized_values['enabled'] = isset($values['enabled']) && $values['enabled'] === 'on';
            $components_data[$key] = $sanitized_values;
        }
    }
    $data = [
        'page_name' => $page_name,
        'page_layout' => $_POST['page_layout'] ?? 'two_column',
        'page_title' => htmlspecialchars($_POST['page_title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'meta_title' => htmlspecialchars($_POST['meta_title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'meta_description' => htmlspecialchars($_POST['meta_description'] ?? '', ENT_QUOTES, 'UTF-8'),
        'component_order' => json_decode($_POST['component_order'] ?? '[]'),
        'components' => $components_data,
        'last_updated' => date('c')
    ];
    $page_dir = $PAGES_DIR . $page_name . '/';
    if (!is_dir($page_dir)) { if (!mkdir($page_dir, 0755, true)) { echo json_encode(['error' => 'Could not create page directory.']); exit; } }
    $timestamp = date('Ymd.His');
    $revision_filename = "{$timestamp}.{$page_name}.rev.json";
    if (file_put_contents($page_dir . $revision_filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo json_encode(['success' => true, 'page_name' => $page_name, 'page_list_html' => pm_get_updated_page_list_html()]);
    } else {
        echo json_encode(['error' => 'Failed to save page data.']);
    }
    exit;
}

function pm_handle_delete($data) {
    $pages_dir = SITE_ROOT . '/admin/data/pages/';
    $page_name = $data['page_name'] ?? ($_POST['page_name'] ?? '');
    if (empty($page_name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $page_name)) { echo json_encode(['error' => 'Invalid page name for deletion.']); exit; }
    $page_dir = $pages_dir . $page_name;
    if (!is_dir($page_dir)) { echo json_encode(['error' => 'Page to delete not found.']); exit; }
    function pm_delete_directory($dir) { if (!is_dir($dir)) return false; $files = array_diff(scandir($dir), ['.', '..']); foreach ($files as $file) { (is_dir("$dir/$file")) ? pm_delete_directory("$dir/$file") : unlink("$dir/$file"); } return rmdir($dir); }
    if (pm_delete_directory($page_dir)) { echo json_encode(['success' => true, 'page_list_html' => pm_get_updated_page_list_html()]); } else { echo json_encode(['error' => 'Failed to delete the page directory.']); }
    exit;
}

function pm_get_updated_page_list_html() {
    $pages_dir = SITE_ROOT . '/admin/data/pages/';
    $pages = [];
    if (is_dir($pages_dir)) {
        $page_folders = array_diff(scandir($pages_dir), ['.', '..']);
        foreach ($page_folders as $page_folder) {
            if (is_dir($pages_dir . $page_folder)) {
                $revisions = glob($pages_dir . $page_folder . '/*.json');
                if (!empty($revisions)) {
                    usort($revisions, fn($a, $b) => filemtime($b) - filemtime($a));
                    $page_data = json_decode(file_get_contents($revisions[0]), true);
                    if ($page_data) { $pages[] = ['page_name' => $page_folder, 'page_title' => $page_data['page_title'] ?? 'Untitled', 'last_modified' => date("Y-m-d H:i:s", filemtime($revisions[0]))]; }
                }
            }
        }
    }
    if (empty($pages)) return '<p>No pages created yet.</p>';
    $html = '<ul>';
    foreach ($pages as $page) { $html .= '<li><a href="#" class="edit-page-link" data-page-name="' . htmlspecialchars($page['page_name']) . '"><strong>' . htmlspecialchars($page['page_title']) . '</strong><br><small>Last Modified: ' . $page['last_modified'] . '</small></a></li>'; }
    $html .= '</ul>';
    return $html;
}

function pm_handle_list_aip() {
    $aipDir = SITE_ROOT . '/admin/data/AIP_Pages';
    $pages = [];
    if (is_dir($aipDir)) {
        foreach (scandir($aipDir) as $slug) {
            if ($slug[0] === '.' || !is_dir($aipDir . '/' . $slug)) continue;
            $indexFile = $aipDir . '/' . $slug . '/index.html';
            if (!is_file($indexFile)) continue;
            $meta = [];
            $metaFile = $aipDir . '/' . $slug . '/meta.json';
            if (is_file($metaFile)) $meta = json_decode(file_get_contents($metaFile), true) ?: [];
            $pages[] = [
                'slug'        => $slug,
                'title'       => $meta['title'] ?? $slug,
                'published'   => $meta['published'] ?? date('c', filemtime($indexFile)),
                'source'      => $meta['source'] ?? 'local',
                'domain'      => $meta['domain'] ?? '',
                'word_count'  => $meta['word_count'] ?? 0,
                'shortcode'   => "[[aip:$slug]]",
            ];
        }
        usort($pages, fn($a, $b) => strtotime($b['published']) <=> strtotime($a['published']));
    }
    echo json_encode(['ok' => true, 'pages' => $pages]);
    exit;
}

function pm_handle_delete_aip($data) {
    $slug = $data['slug'] ?? ($_POST['slug'] ?? '');
    if (empty($slug) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        echo json_encode(['error' => 'Invalid AIP slug.']);
        exit;
    }
    $aipDir = SITE_ROOT . '/admin/data/AIP_Pages/' . $slug;
    if (!is_dir($aipDir)) {
        echo json_encode(['error' => 'AIP page not found.']);
        exit;
    }
    $files = array_diff(scandir($aipDir), ['.', '..']);
    foreach ($files as $f) @unlink($aipDir . '/' . $f);
    @rmdir($aipDir);
    echo json_encode(['ok' => true, 'slug' => $slug]);
    exit;
}
?>