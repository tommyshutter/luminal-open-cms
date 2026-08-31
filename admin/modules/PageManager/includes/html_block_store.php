<?php
/**
 * html_block_store.php — one place that reads/writes the HTMLBlocks store.
 *
 * Canonical block schema: {id,slug,title,html,css,js,tags,created,updated,usage_count}.
 * Files: admin/data/html-blocks/{slug}.json  +  index.json (glob-rebuilt, newest first).
 * Shared by convert-to-html-block.php and html-block-io.php so the write/index logic
 * lives in exactly ONE place. Pure store helpers — no auth, no request handling.
 *
 * @file admin/modules/PageManager/includes/html_block_store.php
 */

if (!function_exists('hbstore_dir')) {

function hbstore_dir(string $siteRoot): string {
    return $siteRoot . '/admin/data/html-blocks';
}

function hbstore_slug_ok(string $s): bool {
    // Read/write limit is generous (200) so blocks created before slug-capping stay
    // editable; NEW slugs are capped much shorter at creation time (see convert).
    return $s !== '' && strlen($s) <= 200 && (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $s);
}

/** Read one block → array, or null if missing/corrupt. */
function hbstore_get(string $dir, string $slug): ?array {
    if (!hbstore_slug_ok($slug)) return null;
    $f = $dir . '/' . $slug . '.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Write one block (atomic-ish via LOCK_EX). $block must carry a valid 'slug'. */
function hbstore_save(string $dir, array $block): bool {
    $slug = (string)($block['slug'] ?? '');
    if (!hbstore_slug_ok($slug)) return false;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ok = @file_put_contents($dir . '/' . $slug . '.json', $json, LOCK_EX);
    if ($ok !== false) @chmod($dir . '/' . $slug . '.json', 0664);
    return $ok !== false;
}

/** Rebuild index.json from the block files on disk (newest-updated first). Self-healing. */
function hbstore_reindex(string $dir): void {
    $list = [];
    foreach (glob($dir . '/*.json') as $f) {
        $b = basename($f, '.json');
        if ($b === 'index') continue;
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $list[] = [
            'slug'    => $d['slug']  ?? $b,
            'title'   => $d['title'] ?? $b,
            'tags'    => is_array($d['tags'] ?? null) ? $d['tags'] : [],
            'updated' => $d['updated'] ?? ($d['updated_at'] ?? null),
            'created' => $d['created'] ?? ($d['created_at'] ?? null),
        ];
    }
    usort($list, fn($a, $b) => strcmp((string)($b['updated'] ?? ''), (string)($a['updated'] ?? '')));
    @file_put_contents($dir . '/index.json', json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Merge edits onto an existing block (preserving id/created/usage_count), or seed a new one. */
function hbstore_upsert(string $dir, string $slug, array $fields): ?array {
    $existing = hbstore_get($dir, $slug);
    $now = date('c');
    $block = [
        'id'          => $existing['id'] ?? ('hb_' . substr(bin2hex(random_bytes(4)), 0, 8)),
        'slug'        => $slug,
        'title'       => $fields['title'] ?? ($existing['title'] ?? $slug),
        'html'        => $fields['html'] ?? ($existing['html'] ?? ''),
        'css'         => $fields['css']  ?? ($existing['css']  ?? ''),
        'js'          => $fields['js']   ?? ($existing['js']   ?? ''),
        'tags'        => $fields['tags'] ?? ($existing['tags'] ?? []),
        'created'     => $existing['created'] ?? $now,
        'updated'     => $now,
        'usage_count' => $existing['usage_count'] ?? 0,
    ];
    if (!hbstore_save($dir, $block)) return null;
    hbstore_reindex($dir);
    return $block;
}

} // function_exists guard
