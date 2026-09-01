<?php
/**
 * ArticlesManager API
 *
 * Actions:
 *   list      — all articles (filtered: ?status= ?category= ?tag= ?search= ?limit=)
 *   get       — one article by slug
 *   save      — upsert (POST with JSON body)
 *   delete    — by slug
 *   publish   — toggle published flag
 *   pin       — toggle pinned flag
 *   stats     — counts + top tags
 *   migrate   — one-shot legacy ingest (runs Migrator)
 *
 * @module  ArticlesManager
 * @version 1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/lib/ArticleStore.php';
require_once __DIR__ . '/lib/Migrator.php';

header('Content-Type: application/json');
$action = $_REQUEST['action'] ?? '';
$store  = new \ArticlesManager\ArticleStore(SITE_ROOT);

try {
    $result = dispatch($store, $action);
    echo json_encode(['ok' => true, 'action' => $action, 'data' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'action' => $action, 'error' => $e->getMessage()]);
}

function dispatch(\ArticlesManager\ArticleStore $store, string $action)
{
    switch ($action) {
        case 'list':
            $opts = [
                'limit'          => (int)($_GET['limit'] ?? 0),
                'category'       => (string)($_GET['category'] ?? ''),
                'tag'            => (string)($_GET['tag'] ?? ''),
                'search'         => (string)($_GET['search'] ?? $_GET['q'] ?? ''),
                'published_only' => (($_GET['status'] ?? '') === 'published'),
            ];
            $rows = $store->all($opts);
            // Enrich with the effective right-column injection state (for the per-card chips).
            @include_once SITE_ROOT . '/includes/content_injection.inc.php';
            if (function_exists('ci_resolve_slug') && is_array($rows)) {
                foreach ($rows as &$r) {
                    $eff = ci_resolve_slug(SITE_ROOT, (string)($r['slug'] ?? ''), true);
                    $r['_inject'] = ['affiliate' => !empty($eff['affiliate']), 'ucs' => !empty($eff['ucs'])];
                }
                unset($r);
            }
            return $rows;

        case 'get':
            $slug = (string)($_REQUEST['slug'] ?? '');
            if ($slug === '') throw new \InvalidArgumentException('slug required');
            $a = $store->get($slug);
            if (!$a) throw new \RuntimeException('not found');
            return $a;

        case 'save':
            $raw = file_get_contents('php://input');
            $body = json_decode((string)$raw, true);
            if (!is_array($body)) {
                // Fallback: form-encoded
                $body = $_POST;
                if (!empty($body['tags']) && is_string($body['tags'])) {
                    $body['tags'] = array_filter(array_map('trim', explode(',', $body['tags'])));
                }
                $body['published'] = !empty($body['published']) && $body['published'] !== 'false';
                $body['pinned']    = !empty($body['pinned']) && $body['pinned'] !== 'false';
            }
            return $store->save($body);

        case 'delete':
            $slug = (string)($_REQUEST['slug'] ?? '');
            if ($slug === '') throw new \InvalidArgumentException('slug required');
            return ['deleted' => $store->delete($slug), 'slug' => $slug];

        /* Bulk delete. Loops the SAME ArticleStore::delete() as the single
           case, so every article is still archived to _archive/ before removal
           — a bulk action must not be a shortcut past the safety net. */
        case 'delete_bulk':
            $raw   = $_REQUEST['slugs'] ?? '';
            $slugs = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string)$raw)));
            if (!$slugs) throw new \InvalidArgumentException('slugs required');
            $deleted = [];
            $missing = [];
            foreach ($slugs as $s) {
                $s = trim((string)$s);
                if ($s === '') continue;
                if ($store->delete($s)) { $deleted[] = $s; } else { $missing[] = $s; }
            }
            return ['deleted' => $deleted, 'not_found' => $missing, 'count' => count($deleted)];

        case 'publish':
            $slug = (string)($_REQUEST['slug'] ?? '');
            $pub  = !empty($_REQUEST['published']) && $_REQUEST['published'] !== 'false';
            $a    = $store->get($slug);
            if (!$a) throw new \RuntimeException('not found');
            $a['published'] = $pub;
            return $store->save($a);

        case 'pin':
            $slug = (string)($_REQUEST['slug'] ?? '');
            $pin  = !empty($_REQUEST['pinned']) && $_REQUEST['pinned'] !== 'false';
            $a    = $store->get($slug);
            if (!$a) throw new \RuntimeException('not found');
            $a['pinned'] = $pin;
            return $store->save($a);

        case 'stats':
            return $store->stats();

        case 'migrate':
            $m = new \ArticlesManager\Migrator(SITE_ROOT);
            return $m->run();

        // Global "all articles" right-column defaults — writes content_injection.article
        // (the SAME config PageManager's Right-Column Defaults uses; one source of truth).
        case 'article_injection_get': {
            @include_once SITE_ROOT . '/includes/content_injection.inc.php';
            $ss = is_file(SITE_ROOT.'/admin/data/site-settings.json') ? (json_decode((string)@file_get_contents(SITE_ROOT.'/admin/data/site-settings.json'), true) ?: []) : [];
            return [
                'config'   => function_exists('ci_defaults') ? ci_defaults($ss, true) : ['affiliate'=>false,'ucs'=>false,'columns'=>1],
                'ucs_slug' => function_exists('ci_ucs_slug') ? ci_ucs_slug($ss) : 'ucs',
                'stacks'   => am_ci_stacks(),
            ];
        }
        case 'article_injection_save': {
            $raw = file_get_contents('php://input'); $body = json_decode((string)$raw, true);
            if (!is_array($body)) $body = $_POST;
            $aff  = !empty($body['affiliate']) && $body['affiliate'] !== 'false';
            $ucs  = !empty($body['ucs']) && $body['ucs'] !== 'false';
            $cols = (int)($body['columns'] ?? 1); if ($cols < 1 || $cols > 3) $cols = 1;
            $uslug = preg_replace('/[^a-z0-9_-]/i', '', (string)($body['ucs_slug'] ?? ''));
            $f = SITE_ROOT.'/admin/data/site-settings.json';
            $ss = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
            if (!isset($ss['content_injection']) || !is_array($ss['content_injection'])) $ss['content_injection'] = [];
            $ss['content_injection']['article'] = ['affiliate'=>$aff, 'ucs'=>$ucs, 'columns'=>$cols];
            if ($uslug !== '') $ss['content_injection']['ucs_slug'] = $uslug;
            $tmp = $f.'.tmp'; file_put_contents($tmp, json_encode($ss, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); @chmod($tmp, 0664); rename($tmp, $f);
            return ['saved'=>true, 'config'=>$ss['content_injection']['article'], 'ucs_slug'=>$uslug];
        }
        case 'set_item_injection': {
            @include_once SITE_ROOT . '/includes/content_injection.inc.php';
            $ok = function_exists('ci_set_item_override')
                && ci_set_item_override(SITE_ROOT, (string)($_REQUEST['slug'] ?? ''), (string)($_REQUEST['field'] ?? ''), (string)($_REQUEST['value'] ?? ''));
            return ['saved'=>$ok];
        }

        default:
            throw new \InvalidArgumentException("unknown action: {$action}");
    }
}

/** Content stacks available on this site (for the UCS-stack picker). */
function am_ci_stacks(): array
{
    $reg = SITE_ROOT . '/admin/data/content-stacks-registry.json';
    $rows = is_file($reg) ? (json_decode((string)@file_get_contents($reg), true) ?: []) : [];
    $list = $rows['stacks'] ?? $rows;
    $out = [];
    foreach ((array)$list as $s) {
        if (is_string($s)) { $out[] = ['slug' => $s, 'label' => $s]; continue; }
        if (!is_array($s)) continue;
        $slug = $s['slug'] ?? '';
        if ($slug === '') continue;
        $out[] = ['slug' => $slug, 'label' => $s['label'] ?? $s['name'] ?? $slug];
    }
    return $out;
}

