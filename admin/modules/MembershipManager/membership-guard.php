<?php
/**
 * MembershipManager — Access Guard
 *
 * Include this in any page/module that requires an active membership.
 * Usage: require_once 'modules/MembershipManager/membership-guard.php';
 *        requireMembership('early_access');
 *
 * @package  LuminalCMS
 * @module   MembershipManager
 * @version  1.0.0
 */

function requireMembership(string $tierId = ''): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $userId = $_SESSION['user_id'] ?? '';
    if (empty($userId)) return false;

    // Superadmins bypass membership checks
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'superadmin') return true;

    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/../../..');
    $file = $siteRoot . '/admin/data/MembershipManager/subscribers.json';
    if (!is_file($file)) return false;

    $subs = json_decode(file_get_contents($file), true);
    if (!is_array($subs)) return false;

    foreach ($subs as $s) {
        if ($s['user_id'] !== $userId) continue;
        if (!in_array($s['status'], ['active', 'trialing'], true)) continue;
        if ($tierId !== '' && $s['tier_id'] !== $tierId) continue;
        return true;
    }

    return false;
}

function requireMembershipOrRedirect(string $tierId = '', string $redirectUrl = ''): void {
    if (requireMembership($tierId)) return;

    if (empty($redirectUrl)) {
        $redirectUrl = '/admin/modules/MembershipManager/MembershipManager.php?gate=upgrade';
    }
    header('Location: ' . $redirectUrl);
    exit;
}
