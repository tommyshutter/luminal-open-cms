<?php
/**
 * Luminal CMS - Admin Logout
 * @file /admin/logout.php
 * @version 2026.02.03.1200.EST
 * @description Secure logout with complete session cleanup
 */

// Start session to access session data
session_start();

// Clear all session variables
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax'
    ]);
}

// Destroy the session
session_destroy();

// Start a new session and regenerate ID for security
session_start();
session_regenerate_id(true);

// Redirect to login page
header('Location: login.php');
exit;
