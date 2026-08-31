<?php
declare(strict_types=1);

// type => subdir
$TYPE_DIR = [
  'images' => 'images',
  'pdfs'   => 'pdfs',
  'videos' => 'videos',
];

$type = isset($_GET['type']) ? strtolower($_GET['type']) : 'images';
if (!isset($TYPE_DIR[$type])) $type = 'images';

$slug = isset($_GET['gal']) ? preg_replace('/[^a-z0-9_\-]/i','', $_GET['gal']) : '';

$baseDir = __DIR__ . '/../admin/data/galleries/' . $TYPE_DIR[$type];

// Don’t emit headers if already sent
if (!headers_sent()) {
  header('Content-Type: text/html; charset=utf-8');
}

echo '<pre style="background:#111;color:#0f0;padding:10px;border-radius:8px;white-space:pre-wrap;line-height:1.35em">';
echo "Probe type=$type slug=" . ($slug !== '' ? $slug : '(none)') . "\n";
echo "Base: $baseDir\n";

if ($slug === '') {
  if (!is_dir($baseDir)) { echo "ERROR: Base directory missing.\n"; exit; }

  // List both foldered slugs and flat *.json files
  $entries = array_values(array_filter(scandir($baseDir), fn($d) => $d !== '.' && $d !== '..'));
  $found = [];
  foreach ($entries as $e) {
    $abs = $baseDir . '/' . $e;
    if (is_dir($abs)) {
      $j = $abs . '/' . $e . '.json';
      $found[] = [$e, is_file($j) ? 'yes' : 'no', $j];
    } elseif (is_file($abs) && str_ends_with(strtolower($e), '.json')) {
      $s = substr($e, 0, -5);
      $found[] = [$s, 'yes', $abs];
    }
  }
  echo "Available slugs (" . count($found) . "):\n";
  foreach ($found as [$s,$ok,$jp]) {
    echo "  - $s  [json exists: $ok]  ⇒ $jp\n";
  }
  echo "\nUse ?gal=<slug> to inspect a specific gallery.\n";
  echo "</pre>";
  exit;
}

// Resolve JSON path (foldered OR flat)
$pathFoldered = $baseDir . '/' . $slug . '/' . $slug . '.json';
$pathFlat     = $baseDir . '/' . $slug . '.json';
$jsonPath     = is_file($pathFoldered) ? $pathFoldered : (is_file($pathFlat) ? $pathFlat : $pathFoldered);

echo "JSON: $jsonPath\n";

if (!is_file($jsonPath)) {
  echo "MISSING\n</pre>";
  exit;
}

$sz = @filesize($jsonPath);
$mt = @filemtime($jsonPath) ?: 0;
$perms = substr(sprintf('%o', @fileperms($jsonPath)), -4);
echo "SIZE=$sz mtime=$mt perms=$perms\n";

$raw = @file_get_contents($jsonPath);
if ($raw === false) { echo "ERROR: file_get_contents failed\n</pre>"; exit; }

$head = substr($raw, 0, 240);
echo "HEAD=" . $head . "\n";

$j = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
  echo "JSON_ERROR=" . json_last_error_msg() . "\n</pre>";
  exit;
}

$items = isset($j['items']) && is_array($j['items']) ? $j['items'] : [];
echo "items_count=" . count($items) . "\n";

if (!empty($j['layout'])) echo "layout=" . $j['layout'] . "\n";

if ($items) {
  $first = $items[0];
  echo "first_item_keys=" . implode(',', array_keys($first)) . "\n";
  if (isset($first['src']))   echo "first.src="   . $first['src'] . "\n";
  if (isset($first['thumb'])) echo "first.thumb=" . $first['thumb'] . "\n";
}
echo "</pre>";
?>