<?php
/**
 * @file        SITE-ROOT/admin/page-save.php
 * @version     2025.08.29.SAVE-ENDPOINT
 * @description Saves a Page Manager document to /admin/data/pages/<slug>/
 *              Writes <timestamp>.rev.json and page.json. Handles rename.
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$SITE_ROOT = realpath(dirname(__DIR__, 4));
$PAGES_DIR = $SITE_ROOT . '/admin/data/pages';

function ri_slugify($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9\-_\s]/', '', $s);
    $s = preg_replace('/[\s_]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

function ri_bool($v) {
    if (is_bool($v)) return $v;
    $t = strtolower((string)$v);
    return in_array($t, ['1','true','on','yes'], true);
}

function read_payload() {
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return $_POST; // form-data / x-www-form-urlencoded
}

try {
    if (!is_dir($PAGES_DIR) && !@mkdir($PAGES_DIR, 0775, true)) {
        throw new RuntimeException('Pages directory is missing and could not be created.');
    }

    $in = read_payload();

    // derive slug
    $givenSlug  = trim((string)($in['page_name'] ?? ''));
    $title      = trim((string)($in['page_title'] ?? ''));
    $oldSlug    = ri_slugify($in['current_page_name'] ?? '');
    $slug       = ri_slugify($givenSlug !== '' ? $givenSlug : ($title !== '' ? $title : 'untitled'));

    if ($slug === '') $slug = 'untitled';

    // collect fields
    $left_width  = (int)($in['left_width']  ?? $in['left_pct']  ?? 65);
    $right_width = (int)($in['right_width'] ?? $in['right_pct'] ?? 35);

    // normalize to 100
    if ($left_width + $right_width !== 100) {
        $total = max(1, $left_width + $right_width);
        $left_width  = (int)round($left_width * 100 / $total);
        $right_width = 100 - $left_width;
    }

    $use_wrapper = ri_bool($in['use_wrapper'] ?? true);
    $include_bg  = ri_bool($in['include_bg']  ?? true);

    // components
    $left_content  = (string)($in['components']['main_content']['content']  ?? $in['left_content']  ?? '');
    $right_content = (string)($in['components']['right_column']['content'] ?? $in['right_content'] ?? '');
    $right_panel   = (string)($in['components']['right_column']['panel']   ?? $in['right_panel']   ?? 'None');
    if ($right_panel === 'None') $right_panel = '';

    $payload = [
        'page_title'  => $title ?: $slug,
        'use_wrapper' => $use_wrapper,
        'include_bg'  => $include_bg,
        'left_width'  => $left_width,
        'right_width' => $right_width,
        'components'  => [
            'main_content' => ['content' => $left_content],
            'right_column' => [
                'panel'   => $right_panel,
                'content' => $right_content,
            ],
        ],
        'saved_at' => gmdate('c'),
    ];

    // handle rename
    if ($oldSlug && $oldSlug !== $slug) {
        $oldDir = $PAGES_DIR . '/' . $oldSlug;
        $newDir = $PAGES_DIR . '/' . $slug;
        if (is_dir($oldDir) && !is_dir($newDir)) {
            @rename($oldDir, $newDir);
        }
    }

    $dir = $PAGES_DIR . '/' . $slug;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Could not create page directory.');
    }

    $revName = date('Ymd-His') . '.rev.json';
    $revPath = $dir . '/' . $revName;
    $curPath = $dir . '/page.json';
    $slugPath = $dir . '/' . $slug . '.json';

    // Preserve right_column_enabled from existing page data
    $existingData = [];
    if (is_file($slugPath)) {
        $existingData = json_decode(file_get_contents($slugPath), true) ?: [];
    } elseif (is_file($curPath)) {
        $existingData = json_decode(file_get_contents($curPath), true) ?: [];
    }
    // Carry over right_column_enabled explicitly
    $payload['right_column_enabled'] = isset($in['right_column_enabled']) ? ri_bool($in['right_column_enabled']) : false;

    // og:image — explicit value wins; otherwise carry over from existing JSON
    // so saves never silently strip a configured share image.
    $ogImage = array_key_exists('og_image', $in) ? trim((string)$in['og_image']) : (string)($existingData['og_image'] ?? '');
    if ($ogImage !== '') $payload['og_image'] = $ogImage;

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (@file_put_contents($revPath, $json) === false) {
        throw new RuntimeException('Could not write revision file.');
    }
    @file_put_contents($curPath, $json);
    // Also write to {slug}.json — the front-end page renderer reads this format
    @file_put_contents($slugPath, $json);

    echo json_encode([
        'ok'       => true,
        'slug'     => $slug,
        'view_url' => '/page.php?p=' . rawurlencode($slug),
        'rev'      => basename($revPath),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
