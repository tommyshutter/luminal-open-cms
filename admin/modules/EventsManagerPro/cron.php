<?php
/**
 * EventsManagerPro — Cron Endpoint
 * Auto-archives published events whose date has passed.
 *
 * Usage: curl -s https://domain/admin/modules/EventsManagerPro/cron.php
 * No auth required (like UpdateManager cron pattern).
 *
 * Version: 2026.02.22.r1
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Bootstrap
require_once __DIR__ . '/EventsManagerProFunctions.php';
require_once __DIR__ . '/classes/DataStore.php';
require_once __DIR__ . '/classes/EventManager.php';

$now = time();
$db  = DataStore::load(EMP_FILE_MASTER);
if (!is_array($db) || empty($db)) {
    echo json_encode(['ok' => true, 'archived' => 0, 'message' => 'No events found']);
    exit;
}

$archived = [];
$changed  = false;

foreach ($db as &$event) {
    // Only auto-archive published events
    if (($event['status'] ?? '') !== 'published') continue;

    // Determine event end time — use end_date if set, otherwise start_date
    $dateStr = '';
    if (!empty($event['end_date'])) {
        $dateStr = $event['end_date'] . ' ' . ($event['end_time'] ?? '23:59');
    } elseif (!empty($event['start_date'])) {
        $dateStr = $event['start_date'] . ' ' . ($event['start_time'] ?? '23:59');
    }

    if (!$dateStr) continue;

    $eventTime = strtotime($dateStr);
    if ($eventTime === false) continue;

    // Archive if event time has passed
    if ($eventTime < $now) {
        $event['status'] = 'archived';
        $event['updated_at'] = $now;
        $archived[] = [
            'id'    => $event['id'] ?? '',
            'title' => $event['title'] ?? $event['event_name'] ?? '',
            'date'  => $dateStr,
        ];
        $changed = true;
    }
}
unset($event);

if ($changed) {
    DataStore::save(EMP_FILE_MASTER, $db);

    // Rebuild published cache (exclude newly archived events)
    $pub = [];
    foreach ($db as $e) {
        if (($e['status'] ?? '') === 'published') $pub[] = $e;
    }
    usort($pub, function ($a, $b) {
        $aKey = ($a['start_date'] ?? '') . ' ' . ($a['start_time'] ?? '');
        $bKey = ($b['start_date'] ?? '') . ' ' . ($b['start_time'] ?? '');
        return strcmp($aKey, $bKey);
    });
    DataStore::save(EMP_FILE_PUBLIC, $pub);
}

echo json_encode([
    'ok'       => true,
    'archived' => count($archived),
    'events'   => $archived,
    'checked'  => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
