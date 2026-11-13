<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  PUBLIC ON PURPOSE — read-only "is this visitor signed in?" for the menu's
//  Login / Logout item (menu item type "account", rendered by
//  header/document_header_body.php). Takes no input, writes nothing, and answers
//  only yes/no plus which door — never who.
//
//  Doors: a site with a member portal (/member/auth.php) answers for its MEMBERS;
//  any other site answers for its CMS sign-in (admin/login.php).
//  A session is opened ONLY when the visitor already holds that door's cookie, so
//  a stranger's request never creates a session file.
// ---------------------------------------------------------------------------

$siteRoot = realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$door = is_file($siteRoot . '/member/auth.php') ? 'member' : 'admin';
$in   = false;

if ($door === 'member') {
    if (!empty($_COOKIE['bb_member_sess'])) {
        session_name('bb_member_sess');
        @session_start(['read_and_close' => true]);
        $in = !empty($_SESSION['bb_member_id']);
    }
} else {
    if (!empty($_COOKIE[session_name()])) {
        require_once $siteRoot . '/admin/includes/session_boot.php';
        @session_start(['read_and_close' => true]);
        $in = !empty($_SESSION['admin_logged_in']) || !empty($_SESSION['user_id'])
           || !empty($_SESSION['portal_user_id']);
    }
}

echo json_encode(['ok' => true, 'door' => $door, 'signed_in' => $in]);
