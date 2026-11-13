<?php
/**
 * Universal media item deleter for Home Left & Right columns.
 * Receives a JSON array of file paths to delete.
 * Includes security checks to prevent deleting files outside the web root.
 * @file-location: SITE-ROOT/admin/delete_home_items.php
 * @version: 2025.07.27.1421.EDT
 */
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';
requireAuth(); // Ensures only authenticated users can delete.

// Get the JSON payload from the request body.
$itemsToDelete = json_decode(file_get_contents('php://input'), true);
$deletedCount = 0;
$errors = [];

if (is_array($itemsToDelete) && !empty($itemsToDelete['items'])) {
    foreach ($itemsToDelete['items'] as $itemPath) {
        // Security Check 1: Sanitize and validate the path.
        $itemPath = filter_var($itemPath, FILTER_SANITIZE_URL);
        $itemPath = ltrim($itemPath, '/'); // Ensure path is relative to document root.

        // Security Check 2: Prevent directory traversal attacks.
        if (strpos($itemPath, '..') !== false) {
            $errors[] = "Invalid path detected: $itemPath";
            continue;
        }

        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $itemPath;

        // Security Check 3: Ensure the file is within the intended directories.
        $allowedDirs = [
            $_SERVER['DOCUMENT_ROOT'] . '/home_left/',
            $_SERVER['DOCUMENT_ROOT'] . '/home_right/'
        ];
        $isAllowed = false;
        foreach ($allowedDirs as $dir) {
            if (strpos(realpath($fullPath), realpath($dir)) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if ($isAllowed && file_exists($fullPath)) {
            // Attempt to delete the file.
            if (unlink($fullPath)) {
                $deletedCount++;
            } else {
                $errors[] = "Could not delete file: $itemPath";
            }
        } else {
            $errors[] = "File not found or access denied: $itemPath";
        }
    }
}

if ($deletedCount > 0) {
    $response = ['success' => true, 'message' => "$deletedCount item(s) deleted."];
    if (!empty($errors)) {
        $response['message'] .= " Some errors occurred: " . implode(', ', $errors);
    }
} else {
    $response = ['success' => false, 'message' => 'No items were deleted. Errors: ' . implode(', ', $errors)];
}

header('Content-Type: application/json');
echo json_encode($response);
?>