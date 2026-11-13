<?php
/**
 * Luminal CMS — Content Stacks API
 *
 * AJAX handler for stack management, content editing, and media browsing.
 *
 * @package    LuminalCMS
 * @module     ContentStacks
 * @version    1.0.0
 * @file       /admin/modules/ContentStacks/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';

guard_require_auth();

header('Content-Type: application/json');

/* ---- Registry helpers ---- */
define('REGISTRY_PATH', SITE_ROOT . '/admin/data/content-stacks-registry.json');

function cs_load_registry() {
    if (!is_file(REGISTRY_PATH)) return [];
    $data = @json_decode((string)@file_get_contents(REGISTRY_PATH), true);
    return is_array($data) ? $data : [];
}

function cs_save_registry(array $reg) {
    return file_put_contents(REGISTRY_PATH, json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function cs_stack_id_to_filename(string $id): string {
    // stack1 -> content-stack-1.json (legacy numeric fallback).
    $num = preg_replace('/[^0-9]/', '', $id);
    return 'content-stack-' . $num . '.json';
}

/* Preferred filename is slug-based: content-stack-{slug}.json. Falls back to
 * numeric form when the slug-named file doesn't exist yet (pre-migration). */
function cs_stack_filename_for_id(string $id, array $registry = null): string {
    if ($registry === null) $registry = cs_load_registry();
    foreach ($registry as $entry) {
        if (($entry['id'] ?? '') !== $id) continue;
        $slug = $entry['slug'] ?? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($entry['label'] ?? ''))), '-');
        if ($slug === '') break;
        $slugFile = 'content-stack-' . $slug . '.json';
        $numFile  = cs_stack_id_to_filename($id);
        $slugPath = SITE_ROOT . '/admin/data/' . $slugFile;
        $numPath  = SITE_ROOT . '/admin/data/' . $numFile;
        if (is_file($slugPath))   return $slugFile;
        if (is_file($numPath))    return $numFile;
        // Neither exists — create new stacks with slug name.
        return $slugFile;
    }
    return cs_stack_id_to_filename($id);
}

function cs_is_valid_stack_id(string $id): bool {
    return (bool)preg_match('/^stack\d+$/', $id);
}

/* ---- Which stack? ---- */
$stack = $_REQUEST['stack'] ?? '';

/* ---- Media roots for file browser ---- */
$MEDIA_ROOTS = [
    'images' => SITE_ROOT . '/media/images',
    'videos' => SITE_ROOT . '/media/videos',
    'pdfs'   => SITE_ROOT . '/media/pdfs',
    'audio'  => SITE_ROOT . '/media/audio',
];

/* ---- File browser (GET requests) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'browse') {
        $rootKey = $_GET['root'] ?? '';
        $subPath = $_GET['path'] ?? '';
        if (!isset($MEDIA_ROOTS[$rootKey])) {
            echo json_encode(['error' => "Unknown root: $rootKey"]);
            exit;
        }
        echo json_encode(cs_scan_secure_dir($MEDIA_ROOTS[$rootKey], $subPath));
        exit;
    }

    if ($action === 'list_stacks') {
        echo json_encode(['success' => true, 'stacks' => cs_load_registry()]);
        exit;
    }

    echo json_encode(['error' => 'Unknown GET action']);
    exit;
}

/* ---- Content operations (POST requests) ---- */
$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action.'];

/* ---- Validate stack for content ops ---- */
if (in_array($action, ['get_content', 'save_content'])) {
    if (!cs_is_valid_stack_id($stack)) {
        echo json_encode(['success' => false, 'message' => 'Invalid stack identifier.']);
        exit;
    }
    // Verify stack exists in registry
    $registry = cs_load_registry();
    $found = false;
    foreach ($registry as $entry) {
        if (($entry['id'] ?? '') === $stack) { $found = true; break; }
    }
    if (!$found) {
        echo json_encode(['success' => false, 'message' => 'Stack not found in registry.']);
        exit;
    }
    $filename = cs_stack_filename_for_id($stack, $registry);
}

switch ($action) {
    case 'get_content':
        $data = loadJsonData($filename);
        if (isset($data['content'])) {
            $response = ['success' => true, 'data' => $data];
        } else {
            $response = ['success' => true, 'data' => ['column_count' => 1, 'content' => $data ?: []]];
        }
        break;

    case 'save_content':
        $content = json_decode($_POST['content'] ?? '[]', true);
        $columnCount = (int)($_POST['column_count'] ?? 1);
        $toSave = ['column_count' => $columnCount, 'content' => $content];
        $stackCss = trim($_POST['css'] ?? '');
        $stackJs  = trim($_POST['js'] ?? '');
        if ($stackCss !== '') $toSave['css'] = $stackCss;
        if ($stackJs  !== '') $toSave['js']  = $stackJs;
        if (saveJsonData($filename, $toSave)) {
            $response = ['success' => true, 'message' => 'Content saved.'];
        } else {
            $response['message'] = 'Failed to save content.';
        }
        break;

    case 'create_stack':
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            $response = ['success' => false, 'message' => 'Stack name is required.'];
            break;
        }
        $registry = cs_load_registry();
        // Find next available number + ensure slug is unique
        $maxNum = 0; $existingSlugs = [];
        foreach ($registry as $entry) {
            $n = (int)preg_replace('/[^0-9]/', '', $entry['id'] ?? '');
            if ($n > $maxNum) $maxNum = $n;
            if (!empty($entry['slug'])) $existingSlugs[$entry['slug']] = true;
        }
        $newNum = $maxNum + 1;
        $newId = 'stack' . $newNum;
        $baseSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-') ?: ('stack-' . $newNum);
        $slug = $baseSlug; $i = 2;
        while (isset($existingSlugs[$slug])) { $slug = $baseSlug . '-' . $i; $i++; }
        $registry[] = ['id' => $newId, 'label' => $label, 'slug' => $slug];
        if (!cs_save_registry($registry)) {
            $response = ['success' => false, 'message' => 'Failed to save registry.'];
            break;
        }
        // Create empty slug-named data file (the new canonical name)
        $newFilename = 'content-stack-' . $slug . '.json';
        saveJsonData($newFilename, ['column_count' => 1, 'content' => []]);
        // Write frontend panel file (always refresh — old stale panels pointed at
        // numeric data that doesn't exist after slug migration)
        cs_write_panel_file($newNum, $slug, $label);
        $response = ['success' => true, 'stack' => ['id' => $newId, 'label' => $label, 'slug' => $slug]];
        break;

    case 'rename_stack':
        $label = trim($_POST['label'] ?? '');
        $targetId = trim($_POST['stack'] ?? '');
        if ($label === '' || !cs_is_valid_stack_id($targetId)) {
            $response = ['success' => false, 'message' => 'Invalid parameters.'];
            break;
        }
        $registry = cs_load_registry();
        $oldSlug = null; $existingSlugs = [];
        foreach ($registry as $entry) {
            if (!empty($entry['slug'])) $existingSlugs[$entry['slug']] = ($entry['id'] ?? '');
            if (($entry['id'] ?? '') === $targetId) $oldSlug = $entry['slug'] ?? '';
        }
        $baseSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-') ?: preg_replace('/[^0-9]/', '', $targetId);
        $newSlug = $baseSlug; $i = 2;
        while (isset($existingSlugs[$newSlug]) && $existingSlugs[$newSlug] !== $targetId) { $newSlug = $baseSlug . '-' . $i; $i++; }
        $found = false;
        foreach ($registry as &$entry) {
            if (($entry['id'] ?? '') === $targetId) {
                $entry['label'] = $label;
                $entry['slug']  = $newSlug;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (!$found) {
            $response = ['success' => false, 'message' => 'Stack not found.'];
            break;
        }
        cs_save_registry($registry);
        // Migrate data file: copy content from old slug/num name into new slug name.
        $dataDir  = SITE_ROOT . '/admin/data/';
        $newFile  = $dataDir . 'content-stack-' . $newSlug . '.json';
        $oldSlugF = $oldSlug ? ($dataDir . 'content-stack-' . $oldSlug . '.json') : null;
        $numFile  = $dataDir . cs_stack_id_to_filename($targetId);
        if (!is_file($newFile)) {
            $src = ($oldSlugF && is_file($oldSlugF)) ? $oldSlugF : (is_file($numFile) ? $numFile : null);
            if ($src) @copy($src, $newFile);
        }
        // Rewrite panel file so [[panel:content-stack-N.php]] points at the NEW slug
        $stackNum = (int)preg_replace('/[^0-9]/', '', $targetId);
        if ($stackNum > 0) cs_write_panel_file($stackNum, $newSlug, $label);
        $response = ['success' => true, 'message' => 'Stack renamed.', 'slug' => $newSlug];
        break;

    case 'delete_stack':
        $targetId = trim($_POST['stack'] ?? '');
        if (!cs_is_valid_stack_id($targetId)) {
            $response = ['success' => false, 'message' => 'Invalid stack ID.'];
            break;
        }
        $registry = cs_load_registry();
        if (count($registry) <= 1) {
            $response = ['success' => false, 'message' => 'Cannot delete the last stack.'];
            break;
        }
        // Protected/system stacks (e.g. the seeded Universal Content Stack) are undeletable.
        foreach ($registry as $entry) {
            if (($entry['id'] ?? '') === $targetId && !empty($entry['protected'])) {
                $response = ['success' => false, 'message' => 'Protected system stack (Universal Content Stack) — cannot be deleted.'];
                break 2;
            }
        }
        $newReg = [];
        $deleted = false;
        foreach ($registry as $entry) {
            if (($entry['id'] ?? '') === $targetId) {
                $deleted = true;
                continue;
            }
            $newReg[] = $entry;
        }
        if (!$deleted) {
            $response = ['success' => false, 'message' => 'Stack not found.'];
            break;
        }
        cs_save_registry($newReg);
        // Remove both slug and numeric data files if present
        $targetSlug = '';
        foreach ($registry as $entry) {
            if (($entry['id'] ?? '') === $targetId) { $targetSlug = $entry['slug'] ?? ''; break; }
        }
        foreach (array_filter([
            SITE_ROOT . '/admin/data/' . cs_stack_id_to_filename($targetId),
            $targetSlug ? SITE_ROOT . '/admin/data/content-stack-' . $targetSlug . '.json' : '',
        ]) as $delFile) {
            if (is_file($delFile)) @unlink($delFile);
        }
        $response = ['success' => true, 'message' => 'Stack deleted.'];
        break;
}

echo json_encode($response);

/* ---- Helpers ---- */

/**
 * (Re)write /panels/content-stack-N.php so [[panel:content-stack-N.php]]
 * resolves to the slug-named data file (content-stack-{slug}.json), with a
 * numeric-name fallback. Always overwrites — stale panels are the reason
 * stacks stopped rendering after rename.
 */
function cs_write_panel_file(int $num, string $slug, string $label): bool {
    if ($num <= 0) return false;
    $panelPath = SITE_ROOT . '/panels/content-stack-' . $num . '.php';
    $safeLabel = addslashes($label);
    $content = '<?php' . "\n"
        . '/**' . "\n"
        . ' * Content Stack ' . $num . ' — ' . $safeLabel . "\n"
        . ' * Shortcode: [[stack:' . $slug . ']]  (also [[panel:content-stack-' . $num . '.php]])' . "\n"
        . ' */' . "\n"
        . 'declare(strict_types=1);' . "\n"
        . 'if (!defined(\'SITE_ROOT\')) define(\'SITE_ROOT\', realpath(__DIR__ . \'/..\')'
        . ' ?: dirname(__DIR__));' . "\n"
        . '@include_once SITE_ROOT . \'/admin/modules/ContentStacks/includes/renderers/content_stack.renderer.php\';' . "\n"
        . 'if (function_exists(\'render_content_stack\')) {' . "\n"
        . '    $_csSlugFile = SITE_ROOT . \'/admin/data/content-stack-' . $slug . '.json\';' . "\n"
        . '    $_csNumFile  = SITE_ROOT . \'/admin/data/content-stack-' . $num . '.json\';' . "\n"
        . '    echo render_content_stack(is_file($_csSlugFile) ? $_csSlugFile : $_csNumFile);' . "\n"
        . '}' . "\n";
    return (bool)@file_put_contents($panelPath, $content);
}

function cs_scan_secure_dir($basePath, $subPath = '') {
    if (!is_dir($basePath) || !is_readable($basePath)) {
        return ['error' => 'Directory not found: ' . basename($basePath)];
    }
    $fullPath = realpath($basePath . '/' . $subPath);
    if ($fullPath === false || strpos($fullPath, realpath($basePath)) !== 0) {
        return ['error' => 'Access denied'];
    }
    $items = [];
    foreach (scandir($fullPath) as $file) {
        if ($file[0] === '.') continue;
        $itemPath = $fullPath . '/' . $file;
        $relative = ltrim($subPath . '/' . $file, '/');
        $items[] = ['name' => $file, 'path' => $relative, 'type' => is_dir($itemPath) ? 'folder' : 'file'];
    }
    return $items;
}
