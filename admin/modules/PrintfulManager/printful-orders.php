<?php
/**
 * PrintfulManager — Standalone Orders Page
 * Redirects to Printful Manager with orders tab active
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

// Set a flag so the main page opens to orders tab
$_GET['tab'] = 'orders';
require __DIR__ . '/printful-manager.php';
