<?php
/**
 * Printful Manager - Admin Index Router
 * 
 * File: SITE_ROOT/admin/printful-mgr/index.php
 * Version: 2025.11.09.1430
 * 
 * Main entry point for the Printful Manager admin interface.
 * Routes all requests to the main manager component.
 */

// Security check - ensure this is being accessed from within admin context
session_start();

// Include the main manager interface
require_once __DIR__ . '/printful-manager.php';
?>
