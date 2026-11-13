<?php
/**
 * Menu Manager Router
 * File: /admin/menu-manager/index.php
 * Version: 2025.10.24.235900
 * 
 * Simple router that redirects to the actual menu manager
 * This keeps the URL clean while maintaining the actual manager file
 */

declare(strict_types=1);

// Redirect to the actual menu manager
header('Location: menu-manager.php');
exit;
