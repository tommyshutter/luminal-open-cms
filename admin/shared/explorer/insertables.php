<?php
/**
 * Luminal CMS — Explorer insertables endpoint
 * @file /admin/shared/explorer/insertables.php
 *
 * Auth-gated JSON: the grouped catalog of every insertable shortcode on
 * this site (content stacks, galleries, panels, widgets, …) for the
 * Explorer's "Inserts" tab. All harvesting lives in insertables-core.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireAuth();
// Read-only endpoint — release the session lock immediately so we never
// serialize against the parent admin page (media-cache.php lesson).
session_write_close();

require_once __DIR__ . '/insertables-core.php';

header('Content-Type: application/json');
echo json_encode(['success' => true, 'groups' => ins_catalog()]);
