<?php
/**
 * @location SITE-ROOT/admin/admin_bootstrap.php
 * @version 07082025.0835 (Updated for consistent session variable check)
 * @comment Centralized bootstrap for all admin pages. Handles session and authentication before any HTML output. This file should be the very first include on any secure admin page.
 */

// Rigorous check to ensure this is the first thing running.
if (headers_sent($file, $line)) {
    // If headers are already sent, it's a fatal error in the logic of the calling page.
    die("Error: A file included before the bootstrap has already sent output. Halting execution. Output started in {$file} on line {$line}.");
}

// 1. Start the session if it's not already active.
// This is the single, authoritative place where the session is checked and started.
require_once __DIR__ . '/includes/session_boot.php';   // long-lived admin session params BEFORE session_start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 2. Perform the authentication check.
// Check new multi-user auth (user_id + user_role) OR legacy single-user auth (admin_logged_in)
$_bootstrapAuthed = false;
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    $_bootstrapAuthed = true;  // New multi-user session
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $_bootstrapAuthed = true;  // Legacy single-user session
}
if (!$_bootstrapAuthed) {
    header('Location: /admin/login.php');
    exit;
}
unset($_bootstrapAuthed);

// If the script reaches this point, the user is authenticated.
// Now, include utils.php which defines core functions like loadJsonData and constants like SITE_ROOT/SITE_ROOT_URL.
// auth.php also requires utils.php, but including it here ensures it's available for all bootstrapped pages.
require_once dirname(__DIR__) . '/utils.php';

// The calling script can now safely proceed to include menus and generate page content.
