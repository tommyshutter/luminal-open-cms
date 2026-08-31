<?php
/**
 * Luminal CMS — Documentation API
 * Serves docs index and individual doc pages as JSON.
 * Reads from admin/data/pages/docs-* page data.
 *
 * GET /admin/api/docs.php         → { ok: true, groups: [...], docs: [...] }
 * GET /admin/api/docs.php?slug=X  → { ok: true, title: "...", content: "..." }
 *
 * @file /admin/api/docs.php  (moved out of /panels/ 2026-06-08 — APIs live in admin/api/)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

define('DOCS_DIR', __DIR__ . '/../data/docs');  // dedicated docs store (decoupled from Page Manager)

// Audience-tiered groups (render order top → bottom). Everyday content first,
// fleet/ops topics tucked at the bottom.
$DOC_GROUPS = [
    'everyday'  => ['title' => 'Everyday Content',         'icon' => 'fa-solid fa-pen-to-square',       'order' => 1],
    'setup'     => ['title' => 'Setup & Configuration',    'icon' => 'fa-solid fa-sliders',             'order' => 2],
    'features'  => ['title' => 'Store, Events & Analytics','icon' => 'fa-solid fa-store',               'order' => 3],
    'advanced'  => ['title' => 'Advanced & Fleet Admin',   'icon' => 'fa-solid fa-screwdriver-wrench',  'order' => 4],
];

// Doc index: slug → metadata. `group` keys into $DOC_GROUPS; `order` sorts within a group.
$DOC_INDEX = [
    // ── Everyday Content ──────────────────────────────────────────────
    'docs-getting-started' => [
        'title' => 'Getting Started', 'group' => 'everyday', 'order' => 1,
        'description' => 'First login, the dashboard, and the basic publish workflow.',
        'icon' => 'fa-solid fa-rocket', 'color' => '#2563eb',
    ],
    'docs-page-manager' => [
        'title' => 'Page Manager', 'group' => 'everyday', 'order' => 2,
        'description' => 'Create and edit pages with the visual editor.',
        'icon' => 'fa-solid fa-file-pen', 'color' => '#7c3aed',
    ],
    'docs-content-stacks' => [
        'title' => 'Content Stacks', 'group' => 'everyday', 'order' => 3,
        'description' => 'Reusable content blocks you embed across pages with one shortcode.',
        'icon' => 'fa-solid fa-layer-group', 'color' => '#0891b2',
    ],
    'docs-gallery-manager' => [
        'title' => 'Gallery Manager', 'group' => 'everyday', 'order' => 4,
        'description' => 'Image, video, and PDF galleries, plus the media browser.',
        'icon' => 'fa-solid fa-images', 'color' => '#059669',
    ],
    'docs-media' => [
        'title' => 'Media & Files', 'group' => 'everyday', 'order' => 5,
        'description' => 'Upload and organize images, video, PDFs, fonts, and files.',
        'icon' => 'fa-solid fa-photo-film', 'color' => '#dc2626',
    ],
    'docs-background-manager' => [
        'title' => 'Background Manager', 'group' => 'everyday', 'order' => 6,
        'description' => 'Image, video, or slideshow page backgrounds with live preview.',
        'icon' => 'fa-solid fa-panorama', 'color' => '#7c2d12',
    ],
    'docs-menu-manager' => [
        'title' => 'Menu Manager', 'group' => 'everyday', 'order' => 7,
        'description' => 'Build and order your site navigation.',
        'icon' => 'fa-solid fa-bars', 'color' => '#d97706',
    ],
    'docs-shortcodes' => [
        'title' => 'Shortcodes', 'group' => 'everyday', 'order' => 8,
        'description' => 'Embed galleries, widgets, and components in any page.',
        'icon' => 'fa-solid fa-code', 'color' => '#4f46e5',
    ],
    'docs-articles-manager' => [
        'title' => 'Articles Manager', 'group' => 'everyday', 'order' => 9,
        'description' => 'Publish and present articles from one canonical store.',
        'icon' => 'fa-solid fa-newspaper', 'color' => '#0e7490',
    ],

    // ── Setup & Configuration ─────────────────────────────────────────
    'docs-site-settings' => [
        'title' => 'Site Settings', 'group' => 'setup', 'order' => 1,
        'description' => 'Site identity, branding, typography, and SEO.',
        'icon' => 'fa-solid fa-gear', 'color' => '#64748b',
    ],
    'docs-users' => [
        'title' => 'User Management', 'group' => 'setup', 'order' => 2,
        'description' => 'Accounts, roles, passwords, and access control.',
        'icon' => 'fa-solid fa-users', 'color' => '#0d9488',
    ],
    'docs-ai' => [
        'title' => 'AI Tools', 'group' => 'setup', 'order' => 3,
        'description' => 'AI content generation and in-editor assists.',
        'icon' => 'fa-solid fa-brain', 'color' => '#a855f7',
    ],
    'docs-api-keys' => [
        'title' => 'API Keys', 'group' => 'setup', 'order' => 4,
        'description' => 'Connect third-party services and store credentials.',
        'icon' => 'fa-solid fa-key', 'color' => '#b45309',
    ],
    'docs-audience-builder' => [
        'title' => 'Audience Builder', 'group' => 'setup', 'order' => 5,
        'description' => 'Capture leads from forms and forward them for follow-up.',
        'icon' => 'fa-solid fa-users-line', 'color' => '#16a34a',
    ],

    // ── Store, Events & Analytics ─────────────────────────────────────
    'docs-ecommerce' => [
        'title' => 'Ecommerce', 'group' => 'features', 'order' => 1,
        'description' => 'Products, payments, and print-on-demand merchandise.',
        'icon' => 'fa-solid fa-cart-shopping', 'color' => '#ea580c',
    ],
    'docs-events-podcasts' => [
        'title' => 'Events & Podcasts', 'group' => 'features', 'order' => 2,
        'description' => 'Events, Facebook sync, and podcast hosting with RSS.',
        'icon' => 'fa-solid fa-calendar-days', 'color' => '#be185d',
    ],
    'docs-stats-captain' => [
        'title' => 'Stats Captain', 'group' => 'features', 'order' => 3,
        'description' => 'Traffic and podcast analytics, with embeddable widgets.',
        'icon' => 'fa-solid fa-chart-line', 'color' => '#059669',
    ],

    // ── Advanced & Fleet Admin ────────────────────────────────────────
    'docs-server-admin' => [
        'title' => 'Server Administration', 'group' => 'advanced', 'order' => 1,
        'description' => 'Domains, SSL, backups, cron, and multi-site deployment.',
        'icon' => 'fa-solid fa-server', 'color' => '#475569',
    ],
    'docs-site-guardian' => [
        'title' => 'Site Guardian', 'group' => 'advanced', 'order' => 2,
        'description' => 'Report-only fleet compliance: drift and version gaps.',
        'icon' => 'fa-solid fa-shield-halved', 'color' => '#b91c1c',
    ],
    'docs-tech-support' => [
        'title' => 'Tech Support', 'group' => 'advanced', 'order' => 3,
        'description' => 'In-site support ticket inbox and triage.',
        'icon' => 'fa-solid fa-life-ring', 'color' => '#0891b2',
    ],
    'docs-contribution-model' => [
        'title' => 'Contribution & Provenance Model', 'group' => 'advanced', 'order' => 4,
        'description' => 'One source of truth + watermarked contributor cockpits, scaling to sublicensed upstream contribution.',
        'icon' => 'fa-solid fa-code-branch', 'color' => '#7dd3fc',
    ],
];

$slug = $_GET['slug'] ?? '';

if ($slug) {
    // Serve individual doc page
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $pageFile = DOCS_DIR . '/' . $slug . '/' . $slug . '.json';

    if (!file_exists($pageFile)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Page not found']);
        exit;
    }

    $page = json_decode(file_get_contents($pageFile), true);
    $content = $page['components']['main_content']['content'] ?? '';
    $title = $page['page_title'] ?? $slug;

    echo json_encode([
        'ok'      => true,
        'slug'    => $slug,
        'title'   => $title,
        'content' => $content,
        'url'     => '/page.php?p=' . $slug,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Serve docs index (grouped)
$docs = [];
foreach ($DOC_INDEX as $s => $meta) {
    $pageFile = DOCS_DIR . '/' . $s . '/' . $s . '.json';
    if (!file_exists($pageFile)) continue;

    $g = $meta['group'] ?? 'everyday';
    $docs[] = [
        'slug'        => $s,
        'title'       => $meta['title'],
        'description' => $meta['description'],
        'icon'        => $meta['icon'],
        'color'       => $meta['color'],
        'group'       => $g,
        'group_order' => $DOC_GROUPS[$g]['order'] ?? 99,
        'order'       => $meta['order'],
        'url'         => '/page.php?p=' . $s,
    ];
}

// Sort by group order, then within-group order
usort($docs, function($a, $b) {
    return [$a['group_order'], $a['order']] <=> [$b['group_order'], $b['order']];
});

// Emit ordered group descriptors (only those that actually have docs)
$present = array_unique(array_column($docs, 'group'));
$groups = [];
foreach ($DOC_GROUPS as $key => $g) {
    if (in_array($key, $present, true)) {
        $groups[] = ['key' => $key, 'title' => $g['title'], 'icon' => $g['icon'], 'order' => $g['order']];
    }
}

echo json_encode(['ok' => true, 'groups' => $groups, 'docs' => $docs], JSON_UNESCAPED_SLASHES);
