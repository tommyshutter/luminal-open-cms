<?php
/**
 * Luminal CMS — Blog Manager API
 *
 * RESTful JSON API for blog article CRUD operations.
 *
 * @package    LuminalCMS
 * @module     BlogManager
 * @version    1.0.0
 * @file       /admin/modules/BlogManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();
header('Content-Type: application/json');

$ARTICLES_DIR = SITE_ROOT . '/admin/data/articles/';

// Ensure data directory exists
if (!is_dir($ARTICLES_DIR)) {
    mkdir($ARTICLES_DIR, 0775, true);
}

// Parse request
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (empty($action)) {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true) ?? [];
    $action = $data['action'] ?? null;
} else {
    $data = array_merge($_POST, $_GET);
    $json_input = file_get_contents('php://input');
    if ($json_input) {
        $parsed = json_decode($json_input, true);
        if (is_array($parsed)) $data = array_merge($data, $parsed);
    }
}

switch ($action) {
    case 'list_articles':    bm_list_articles();   break;
    case 'get_article':      bm_get_article($data); break;
    case 'save_article':     bm_save_article($data); break;
    case 'delete_article':   bm_delete_article($data); break;
    case 'list_categories':  bm_list_categories(); break;
    default:
        echo json_encode(['error' => 'Invalid action specified.']);
        exit;
}

// ── List all articles ────────────────────────────────────────────────────────

function bm_list_articles(): void {
    global $ARTICLES_DIR;
    $files = glob($ARTICLES_DIR . '*.json') ?: [];
    $articles = [];
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        $a = json_decode($raw, true);
        if (!is_array($a)) continue;
        $articles[] = [
            'slug'           => $a['slug'] ?? '',
            'title'          => $a['title'] ?? '',
            'category'       => $a['category'] ?? '',
            'is_published'   => (bool)($a['is_published'] ?? false),
            'published_date' => $a['published_date'] ?? '',
            'author'         => $a['author'] ?? '',
        ];
    }
    // Sort newest first
    usort($articles, fn($a, $b) => strcmp($b['published_date'], $a['published_date']));
    echo json_encode(['success' => true, 'articles' => $articles]);
}

// ── Get single article ───────────────────────────────────────────────────────

function bm_get_article(array $data): void {
    global $ARTICLES_DIR;
    $slug = bm_sanitize_slug($data['slug'] ?? '');
    if ($slug === '') { echo json_encode(['error' => 'Missing slug.']); exit; }
    $file = $ARTICLES_DIR . $slug . '.json';
    if (!is_file($file)) { echo json_encode(['error' => 'Article not found.']); exit; }
    $a = json_decode(file_get_contents($file), true);
    if (!is_array($a)) { echo json_encode(['error' => 'Corrupt article file.']); exit; }
    echo json_encode(['success' => true, 'article' => $a]);
}

// ── Save article (create or update) ─────────────────────────────────────────

function bm_save_article(array $data): void {
    global $ARTICLES_DIR;
    $title = trim($data['title'] ?? '');
    if ($title === '') { echo json_encode(['error' => 'Title is required.']); exit; }

    // Generate slug from title if not provided
    $slug = trim($data['slug'] ?? '');
    if ($slug === '') {
        $slug = bm_title_to_slug($title);
    } else {
        $slug = bm_sanitize_slug($slug);
    }
    if ($slug === '') { echo json_encode(['error' => 'Could not generate valid slug.']); exit; }

    // Build tags array
    $tags = [];
    if (!empty($data['tags'])) {
        if (is_array($data['tags'])) {
            $tags = array_values(array_filter(array_map('trim', $data['tags'])));
        } else {
            $tags = array_values(array_filter(array_map('trim', explode(',', (string)$data['tags']))));
        }
    }

    $is_published = filter_var($data['is_published'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $article = [
        'slug'             => $slug,
        'title'            => $title,
        'meta_description' => trim($data['meta_description'] ?? ''),
        'category'         => trim($data['category'] ?? ''),
        'tags'             => $tags,
        'published_date'   => trim($data['published_date'] ?? date('Y-m-d')),
        'hero_image_url'   => trim($data['hero_image_url'] ?? ''),
        'body_html'        => $data['body_html'] ?? '',
        'author'           => trim($data['author'] ?? 'Admin'),
        'read_time'        => trim($data['read_time'] ?? '3 min read'),
        'is_published'     => $is_published,
    ];

    $file = $ARTICLES_DIR . $slug . '.json';
    $bytes = file_put_contents($file, json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($bytes === false) {
        echo json_encode(['error' => 'Failed to write article file.']);
        exit;
    }
    echo json_encode(['success' => true, 'slug' => $slug, 'message' => 'Article saved.']);
}

// ── Delete article ───────────────────────────────────────────────────────────

function bm_delete_article(array $data): void {
    global $ARTICLES_DIR;
    $slug = bm_sanitize_slug($data['slug'] ?? '');
    if ($slug === '') { echo json_encode(['error' => 'Missing slug.']); exit; }
    $file = $ARTICLES_DIR . $slug . '.json';
    if (!is_file($file)) { echo json_encode(['error' => 'Article not found.']); exit; }
    if (!unlink($file)) { echo json_encode(['error' => 'Failed to delete article.']); exit; }
    echo json_encode(['success' => true, 'message' => 'Article deleted.']);
}

// ── List distinct categories ─────────────────────────────────────────────────

function bm_list_categories(): void {
    global $ARTICLES_DIR;
    $files = glob($ARTICLES_DIR . '*.json') ?: [];
    $cats = [];
    foreach ($files as $file) {
        $a = json_decode(file_get_contents($file), true);
        $cat = trim($a['category'] ?? '');
        if ($cat !== '') $cats[$cat] = true;
    }
    $list = array_keys($cats);
    sort($list);
    echo json_encode(['success' => true, 'categories' => $list]);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function bm_title_to_slug(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
}

function bm_sanitize_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
    return trim($slug, '-');
}
