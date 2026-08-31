<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: EmailShare.php
 *
 * Sends HTML email with embedded event flyer via mail() or Mailgun.
 * Auth hardened: source had NO authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/EventsManagerProFunctions.php';
require_once __DIR__ . '/classes/DataStore.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$eventId        = $_POST['event_id'] ?? '';
$recipientEmail = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$senderName     = htmlspecialchars(trim($_POST['sender_name'] ?? 'A friend'), ENT_QUOTES, 'UTF-8');

if (!$eventId || !$recipientEmail) {
    echo json_encode(['ok' => false, 'error' => 'Missing event ID or valid email address']);
    exit;
}

$events = DataStore::load(EMP_FILE_PUBLIC);
$venues = DataStore::load(EMP_FILE_VENUES);

$event = null;
foreach ($events as $e) {
    if (($e['id'] ?? '') === $eventId) { $event = $e; break; }
}
if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

$venue = null;
foreach ($venues as $v) {
    if (($v['id'] ?? '') === ($event['venue_id'] ?? '')) { $venue = $v; break; }
}

$siteRoot = emp_site_root();
$title     = htmlspecialchars($event['title'] ?? 'Untitled Event', ENT_QUOTES, 'UTF-8');
$dateStr   = ($event['start_date'] ?? '') . ' ' . ($event['start_time'] ?? '');
$prettyDate = strtotime($dateStr) ? date('l, F j, Y \a\t g:i A', strtotime($dateStr)) : 'TBA';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'];
$eventUrl = $siteUrl . '/event.php?id=' . urlencode($eventId);

// Build image HTML
$imageHtml = '';
if (!empty($event['image'])) {
    $imagePath = $siteRoot . '/' . ltrim($event['image'], '/');
    $imageUrl  = $siteUrl . '/' . ltrim($event['image'], '/');
    if (file_exists($imagePath) && filesize($imagePath) < 2000000) {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mimeType  = mime_content_type($imagePath) ?: 'image/jpeg';
        $imageHtml = '<img src="data:' . $mimeType . ';base64,' . $imageData . '" alt="' . $title . '" style="max-width:100%;height:auto;border-radius:12px;margin-bottom:20px;">';
    } else {
        $imageHtml = '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" style="max-width:100%;height:auto;border-radius:12px;margin-bottom:20px;">';
    }
}

// Venue HTML
$venueHtml = '';
if ($venue) {
    $venueName = htmlspecialchars($venue['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $venueAddr = htmlspecialchars($venue['address'] ?? '', ENT_QUOTES, 'UTF-8');
    $venueWeb  = $venue['website'] ?? '';

    $venueHtml = '<div style="margin:20px 0;padding:15px;background:#f5f5f5;border-radius:8px;">';
    $venueHtml .= '<strong style="color:#333;">VENUE</strong><br>';
    $venueHtml .= '<span style="font-size:16px;color:#222;">' . $venueName . '</span><br>';
    if ($venueAddr) {
        $mapUrl = 'https://www.google.com/maps/search/' . rawurlencode($venue['address']);
        $venueHtml .= '<a href="' . $mapUrl . '" style="color:#1877F2;text-decoration:none;">' . $venueAddr . '</a><br>';
    }
    if ($venueWeb) {
        $venueHtml .= '<a href="' . htmlspecialchars($venueWeb, ENT_QUOTES, 'UTF-8') . '" style="color:#1877F2;text-decoration:none;">Website</a>';
    }
    $venueHtml .= '</div>';
}

// Tickets link
$ticketsHtml = '';
if (!empty($event['fb_link'])) {
    $ticketsHtml = '<div style="margin:20px 0;"><a href="' . htmlspecialchars($event['fb_link'], ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 24px;background:#1877F2;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Get Tickets / More Info</a></div>';
}

$htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
    . '<body style="margin:0;padding:0;background:#1a1a1a;font-family:Arial,Helvetica,sans-serif;">'
    . '<div style="max-width:600px;margin:0 auto;padding:20px;">'
    . '<div style="background:#222;border-radius:16px;overflow:hidden;border:1px solid #333;">'
    . $imageHtml
    . '<div style="padding:25px;">'
    . '<h1 style="margin:0 0 10px;color:#fff;font-size:24px;">' . $title . '</h1>'
    . '<p style="margin:0 0 20px;color:#00d2ff;font-size:16px;font-weight:600;">' . $prettyDate . '</p>'
    . $venueHtml . $ticketsHtml
    . '<div style="margin-top:25px;padding-top:20px;border-top:1px solid #444;">'
    . '<a href="' . htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#00d2ff;text-decoration:none;">View Full Event Details</a>'
    . '</div></div></div>'
    . '<p style="margin-top:20px;color:#666;font-size:12px;text-align:center;">Shared by ' . $senderName . '</p>'
    . '</div></body></html>';

$textBody = "You're invited!\n\n" . html_entity_decode($title, ENT_QUOTES, 'UTF-8') . "\n" . $prettyDate . "\n\n";
if ($venue) {
    $textBody .= "Venue: " . ($venue['name'] ?? '') . "\n";
    if (!empty($venue['address'])) $textBody .= $venue['address'] . "\n";
}
if (!empty($event['fb_link'])) $textBody .= "\nTickets: " . $event['fb_link'] . "\n";
$textBody .= "\nView event: " . $eventUrl . "\n\nShared by " . $senderName;

$subject = "You're Invited: " . html_entity_decode($title, ENT_QUOTES, 'UTF-8');

// The multipart/alternative envelope is built by mail.php now, which also picks the
// transport and — importantly for this module — quoted-printable encodes the body. Flyers
// are embedded as base64 data: URIs, a single line of hundreds of KB, which exceeds the
// 998-octet line limit in RFC 5321 and was going out unwrapped.
require_once (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/includes/mail.php';
$res = luminal_send_mail($recipientEmail, $subject, $htmlBody, $textBody);

if (!empty($res['ok'])) {
    echo json_encode(['ok' => true, 'message' => 'Email sent successfully']);
} else {
    echo json_encode(['ok' => false, 'error' => $res['error'] ?: 'Failed to send email.']);
}
