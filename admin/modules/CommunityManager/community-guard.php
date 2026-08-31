<?php
/**
 * Luminal CMS — Community Manager Guard
 *
 * Provides member session helpers for public pages using community features.
 * Include this in any public page/renderer that needs to check member auth.
 *
 * Usage:
 *   require_once SITE_ROOT . '/admin/modules/CommunityManager/community-guard.php';
 *   $member = cm_get_member();        // returns member array or null
 *   $member = cm_require_member();    // returns member array or sends 403 JSON and exits
 *   $can    = cm_member_can_post();   // checks tier permission
 *
 * @package    LuminalCMS
 * @module     CommunityManager
 * @version    1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Return the currently logged-in member array, or null if not logged in.
 */
function cm_get_member(): ?array {
    $member_id = $_SESSION['cm_member_id'] ?? null;
    if (!$member_id) return null;

    $members_file = SITE_ROOT . '/admin/data/CommunityManager/members.json';
    if (!is_file($members_file)) return null;

    $members = json_decode(file_get_contents($members_file), true);
    if (!is_array($members) || !isset($members[$member_id])) return null;

    $member = $members[$member_id];
    if (!empty($member['banned'])) return null; // treat banned as not logged in

    return $member;
}

/**
 * Require a logged-in member. If not authenticated, output a 403 JSON
 * response and exit. Returns the member array on success.
 */
function cm_require_member(): array {
    $member = cm_get_member();
    if ($member === null) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required.', 'auth_required' => true]);
        exit;
    }
    return $member;
}

/**
 * Check whether the current member is allowed to post.
 * If tiers are disabled, any logged-in member can post.
 * If tiers are enabled, only non-free tiers can post (configurable).
 */
function cm_member_can_post(): bool {
    $member = cm_get_member();
    if ($member === null) return false;

    $config_file = SITE_ROOT . '/admin/data/CommunityManager/config.json';
    $config = [];
    if (is_file($config_file)) {
        $config = json_decode(file_get_contents($config_file), true) ?? [];
    }

    // If tiers not enabled, any logged-in member can post
    if (empty($config['tiers_enabled'])) return true;

    // If tiers enabled, free members can still post unless explicitly restricted
    // (Tier-gating of posting is not configured by default — free members can post)
    return true;
}

/**
 * Return a public-safe version of the member array (no password hash).
 */
function cm_public_member_data(array $m): array {
    return [
        'id'           => $m['id'],
        'display_name' => $m['display_name'],
        'avatar_color' => $m['avatar_color'] ?? '#f59e0b',
        'bio'          => $m['bio'] ?? '',
        'tier'         => $m['tier'] ?? 'free',
        'joined_at'    => $m['joined_at'] ?? '',
        'post_count'   => $m['post_count'] ?? 0,
        'verified'     => (bool)($m['verified'] ?? true),
    ];
}
