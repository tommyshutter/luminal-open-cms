<?php
/**
 * Age Verification Gate — safety-audit beacon sink.
 *
 * Public endpoint (visitors are anonymous). age-gate.js POSTs a one-line event
 * on each gate decision (accepted / denied / idle_relock). We append a JSONL row
 * to admin/data/SiteSettings/age_gate_audit/{YYYY-MM-DD}.jsonl, stamping the
 * server-side facts (time, hashed IP, UA) so the client can't forge them.
 *
 * Retention: daily files, rotated after AGE_AUDIT_RETENTION_DAYS (default 3) —
 * older day-files are deleted on write. These are transient compliance logs, NOT
 * backups, and Tom asked for the 3-day rotation explicitly.
 *
 * Privacy: the IP is stored as a per-day pseudonym (sha256 of IP + date, first
 * 16 hex) so a visitor's events can be correlated within a day without persisting
 * the raw address. Switch to a raw IP here if your compliance regime requires it.
 *
 * @file /admin/modules/SiteSettings/age-gate-log.php
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }

$RETENTION_DAYS = 3;

$event = (string)($_POST['event'] ?? '');
$allowed = ['accepted', 'denied', 'idle_relock'];
if (!in_array($event, $allowed, true)) { http_response_code(204); exit; }

$clip = function ($v, $max) { $v = (string)$v; return strlen($v) > $max ? substr($v, 0, $max) : $v; };

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }  // first hop
$today = gmdate('Y-m-d');

$row = [
    'ts'        => gmdate('Y-m-d\TH:i:s\Z'),
    'event'     => $event,
    'threshold' => (int)($_POST['threshold'] ?? 0),
    'page'      => $clip($_POST['page'] ?? '', 300),
    'ref'       => $clip($_POST['ref'] ?? '', 300),
    'host'      => $clip($_SERVER['HTTP_HOST'] ?? '', 120),
    'ipref'     => substr(hash('sha256', $ip . '|' . $today), 0, 16),  // per-day pseudonym
    'ua'        => $clip($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
];

$dir = dirname(__DIR__, 3) . '/admin/data/SiteSettings/age_gate_audit';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$file = $dir . '/' . $today . '.jsonl';
@file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// Rotate: drop day-files older than the retention window.
$cutoff = strtotime('today -' . $RETENTION_DAYS . ' days');
foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
    if (preg_match('/(\d{4}-\d{2}-\d{2})\.jsonl$/', $f, $m)) {
        if (strtotime($m[1]) < $cutoff) { @unlink($f); }
    }
}

http_response_code(204);
