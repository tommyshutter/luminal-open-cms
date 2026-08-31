<?php
if (!defined('SITE_ROOT')) { define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3)); }
require_once SITE_ROOT . '/admin/auth.php'; requireAuth();
header('Content-Type: text/plain');
echo "FG HOTFIX Self-Test\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "GD loaded: " . (extension_loaded('gd') ? 'yes' : 'no') . "\n";
$dirs=[SITE_ROOT.'/admin/data/image_galleries', SITE_ROOT.'/admin/data/image_galleries/_thumbs', SITE_ROOT.'/admin/data/video_galleries'];
foreach ($dirs as $d){ echo $d.' exists: '.(is_dir($d)?'yes':'NO').' writable: '.(is_writable($d)?'yes':'NO')."\n"; }