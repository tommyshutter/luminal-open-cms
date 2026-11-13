<?php
/**
 * Universal media uploader for Home Left & Right columns.
 * Receives files via POST, determines the file type (image/video),
 * and saves them to the correct directory tree based on the 'target' parameter.
 * @file-location: SITE-ROOT/admin/upload_home_media.php
 * @version: 2025.07.27.1421.EDT
 */
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';
requireAuth(); // Ensures only authenticated users can upload.

$target = $_POST['target'] ?? ''; // Expects 'home_left' or 'home_right'
$response = ['success' => false, 'message' => 'Invalid target specified.'];

if ($target === 'home_left' || $target === 'home_right') {
    $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/' . $target;
    $imagesDir = $baseDir . '/media/images/';
    $videosDir = $baseDir . '/media/videos/';

    // Create directories if they don't exist to prevent upload errors.
    if (!is_dir($imagesDir)) mkdir($imagesDir, 0777, true);
    if (!is_dir($videosDir)) mkdir($videosDir, 0777, true);

    $uploadCount = 0;
    if (!empty($_FILES['media']['tmp_name'])) {
        // Loop through all uploaded files.
        foreach ($_FILES['media']['tmp_name'] as $key => $tmp_name) {
            $fileName = basename($_FILES['media']['name'][$key]);
            $fileType = $_FILES['media']['type'][$key];
            $destination = '';

            // Sort files into 'images' or 'videos' folder based on MIME type.
            if (strpos($fileType, 'image') === 0) {
                $destination = $imagesDir . $fileName;
            } elseif (strpos($fileType, 'video') === 0) {
                $destination = $videosDir . $fileName;
            }

            // Move the file from the temporary directory to the final destination.
            if ($destination && move_uploaded_file($tmp_name, $destination)) {
                $uploadCount++;
            }
        }
    }

    if ($uploadCount > 0) {
        $response = ['success' => true, 'message' => "$uploadCount files uploaded successfully."];
    } else {
        $response['message'] = 'No files were uploaded or an error occurred.';
    }
}

header('Content-Type: application/json');
echo json_encode($response);