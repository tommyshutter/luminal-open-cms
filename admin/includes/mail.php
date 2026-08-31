<?php
/**
 * mail.php — one place that decides HOW this site sends email.
 *
 * WHY THIS EXISTS
 * Before this, thirteen files across UserManager, AudienceBuilder, MyStore and
 * EventsManagerPro each curl'd api.mailgun.net directly. That meant a site with no Mailgun
 * account had no working email at all — including **admin password reset**, which is the
 * one that locks you out of your own CMS. There was no fallback of any kind.
 *
 * TRANSPORTS
 *   auto    (default) — first configured of smtp, then Mailgun, then local mail()
 *   local             — always PHP mail(), delivered by the host's own MTA
 *   mailgun           — always Mailgun; if it is not configured this FAILS LOUDLY rather
 *                       than silently doing nothing
 *   smtp              — always authenticated SMTP submission to a mailbox you own; likewise
 *                       fails loudly rather than silently if it is not configured
 *
 * 'auto' is the default on purpose: an existing site with Mailgun keeps its exact current
 * behaviour, and a site without it starts working instead of failing silently. SMTP is
 * preferred over Mailgun in auto because nothing acquires smtp_host/user/pass by accident —
 * a site that has them was given them deliberately.
 *
 * CONFIG
 *   admin/data/mail/mail-config.json          — transport choice + SMTP credentials
 *   admin/data/MailgunManager/mailgun-config.json — Mailgun key/domain
 * The first wins key-by-key, so a site can be moved off Mailgun by emptying the Mailgun
 * config without losing its transport choice.
 *
 * OPTIONS ($opt)
 *   from_email · from_name · reply_to · cc (comma-separated)
 *
 * IS LOCAL MAIL GOOD ENOUGH?
 * On cPanel hosting, generally yes. Verified for both client domains 2026-08-10: each
 * publishes an SPF record authorising the hosting server (a site may list its own
 * IP 192.254.188.94 explicitly) and each publishes a DKIM key. Mail sent by the host as
 * noreply@<their-domain> is therefore SPF- and DKIM-aligned. Mailgun buys better bulk
 * deliverability and analytics; it is not required for transactional mail to work.
 *
 * FROM ADDRESS
 * Defaults to noreply@<site domain>, the same convention WordPress uses. It must be on a
 * domain the sending host is authorised for — a From at gmail.com or similar will be
 * rejected or spam-filed regardless of transport.
 */

if (defined('LUMINAL_MAIL_LOADED')) return;
define('LUMINAL_MAIL_LOADED', true);

/**
 * Resolve the site's own domain, stripped of any leading www.
 * Falls back through config, then the request host, then the server name — because this
 * also has to work under cron, where there is no request at all.
 */
function luminal_mail_domain(): string {
    $d = '';
    if (defined('SITE_DOMAIN_NAME') && SITE_DOMAIN_NAME) $d = (string)SITE_DOMAIN_NAME;
    if ($d === '' && !empty($_SERVER['HTTP_HOST']))   $d = (string)$_SERVER['HTTP_HOST'];
    if ($d === '' && !empty($_SERVER['SERVER_NAME'])) $d = (string)$_SERVER['SERVER_NAME'];
    if ($d === '') $d = (string)gethostname();
    $d = preg_replace('/^www\./i', '', trim($d));
    return preg_replace('/:\d+$/', '', $d);   // strip any :port
}

/**
 * Load and normalise mail settings. Reads MailgunManager's config if present, then the
 * central site config, so either place can hold the credentials.
 */
function luminal_mail_config(): array {
    $cfg = [];

    $root = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2));

    /**
     * Read a config file, revealing any sealed credential in it.
     *
     * Credentials in this codebase may be stored sealed ("ENC:v1:…") and are un-sealed at
     * load by cred_load_json(). This file used a plain json_decode, so a sealed value was
     * handed on as the credential itself. Observed in practice: a Mailgun
     * api_key is sealed, so this resolved to transport=mailgun with a 127-char ciphertext as
     * the API key — every send there got a 401, and because a forced/selected mailgun
     * transport does not fall back, the mail was simply lost. SMTP passwords will be sealed
     * the same way, so this has to be right before the cPanel cutover.
     */
    $loadCfg = static function (string $file) use ($root): array {
        if (!is_file($file)) return [];
        if (!function_exists('cred_load_json') && is_file($root . '/admin/config/cred_vault.php')) {
            require_once $root . '/admin/config/cred_vault.php';
        }
        if (function_exists('cred_load_json')) {
            // cred_vault_reveal() THROWS on a missing/wrong key or a malformed envelope, and
            // luminal_send_mail() must never throw — its callers are form handlers and
            // checkout endpoints that must not 500 because mail is misconfigured.
            //
            // A credential we cannot decrypt is useless: sending the ciphertext as an API key
            // or password guarantees a 401. So drop this source entirely and let resolution
            // fall through to the next transport (usually the host MTA), which will probably
            // deliver. Log it, because a silently skipped credential is exactly the kind of
            // thing that makes a misconfiguration and a dead key look identical from outside.
            try {
                return cred_load_json($file);
            } catch (\Throwable $e) {
                error_log('luminal_mail_config: cannot un-seal ' . $file . ' — '
                        . $e->getMessage() . '; ignoring this config source.');
                return [];
            }
        }
        $j = json_decode((string)file_get_contents($file), true);
        return is_array($j) ? $j : [];
    };

    // MailgunManager has TWO config filenames in the wild and different code read different
    // ones — the same split-brain shape as the FacebookEvents bug. CardManager reads
    // config.json; this file has only ever read mailgun-config.json. Measured 2026-08-15:
    // Some sites have ONLY config.json, so mail.php saw no Mailgun
    // there at all; others have both. Read the older config.json first
    // and let mailgun-config.json win key-by-key, so sites that have both keep exactly the
    // behaviour they have today and sites that have only the old one stop being invisible.
    foreach (['config.json', 'mailgun-config.json'] as $mgName) {
        $j = $loadCfg($root . '/admin/data/MailgunManager/' . $mgName);
        if (!$j) continue;
        $cfg = array_merge($cfg, array_filter(
            $j,
            static function ($v) { return $v !== '' && $v !== null; }
        ));
    }

    // config.json carries base_url where this file uses region. Honour it rather than
    // silently posting EU-hosted mail at the US endpoint.
    if (!isset($cfg['region']) && !empty($cfg['base_url'])
        && stripos((string)$cfg['base_url'], 'api.eu.mailgun.net') !== false) {
        $cfg['region'] = 'eu';
    }

    // Dedicated mail config. Deliberately NOT MailgunManager's file: a site can be moved
    // off Mailgun by emptying that config without losing its transport choice or SMTP
    // credentials. Anything set here wins over the Mailgun file.
    $mailFile = $root . '/admin/data/mail/mail-config.json';
    if (is_file($mailFile)) {
        $j = $loadCfg($mailFile);
        if (is_array($j)) $cfg = array_merge($cfg, array_filter(
            $j,
            static function ($v) { return $v !== '' && $v !== null; }
        ));
    }

    // Central site config is a secondary source for the same values.
    if (isset($GLOBALS['_SITE_CONFIG']['mailgun']) && is_array($GLOBALS['_SITE_CONFIG']['mailgun'])) {
        $s = $GLOBALS['_SITE_CONFIG']['mailgun'];
        $cfg['api_key'] = $cfg['api_key'] ?? ($cfg['apiKey'] ?? ($s['apiKey'] ?? ''));
        $cfg['domain']  = $cfg['domain']  ?? ($s['domain'] ?? '');
        $cfg['region']  = $cfg['region']  ?? ($s['region'] ?? 'us');
        if (empty($cfg['from_email']) && !empty($s['from'])) $cfg['from_email'] = $s['from'];
    }

    $apiKey = trim((string)($cfg['api_key'] ?? $cfg['apiKey'] ?? ''));
    $domain = trim((string)($cfg['domain'] ?? ''));
    $mailgunReady = ($apiKey !== '' && $domain !== '');

    $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
    $smtpUser = trim((string)($cfg['smtp_user'] ?? ''));
    $smtpPass = (string)($cfg['smtp_pass'] ?? '');
    $smtpReady = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '');

    $transport = strtolower(trim((string)($cfg['transport'] ?? 'auto')));
    if (!in_array($transport, ['auto', 'local', 'mailgun', 'smtp'], true)) $transport = 'auto';

    // auto prefers an authenticated relay over an unauthenticated one, and only ever falls
    // back to the host MTA. SMTP is checked first because a site that has been given real
    // mailbox credentials means them; nothing configures SMTP by accident. Sites with no
    // smtp_* keys resolve exactly as they did before this existed.
    if ($transport === 'auto') {
        $effective = $smtpReady ? 'smtp' : ($mailgunReady ? 'mailgun' : 'local');
    } else {
        $effective = $transport;
    }

    $fromEmail = trim((string)($cfg['from_email'] ?? ''));
    if ($fromEmail === '') $fromEmail = 'noreply@' . luminal_mail_domain();

    return [
        'transport'      => $transport,
        'effective'      => $effective,
        'mailgun_ready'  => $mailgunReady,
        'smtp_ready'     => $smtpReady,
        'smtp_host'      => $smtpHost,
        'smtp_port'      => (int)($cfg['smtp_port'] ?? 587),
        'smtp_user'      => $smtpUser,
        'smtp_pass'      => $smtpPass,
        // tls = STARTTLS on 587 (cPanel default); ssl = implicit TLS on 465; none = plain
        'smtp_secure'    => in_array(strtolower((string)($cfg['smtp_secure'] ?? 'tls')),
                                     ['tls', 'ssl', 'none'], true)
                            ? strtolower((string)($cfg['smtp_secure'] ?? 'tls')) : 'tls',
        'api_key'        => $apiKey,
        'domain'         => $domain,
        'region'         => strtolower((string)($cfg['region'] ?? 'us')) === 'eu' ? 'eu' : 'us',
        'from_email'     => $fromEmail,
        'from_name'      => trim((string)($cfg['from_name'] ?? (defined('SITE_NAME') ? SITE_NAME : ''))),
    ];
}

/**
 * Send an email. Returns ['ok'=>bool, 'transport'=>string, 'error'=>string].
 * Never throws — callers are mostly form handlers that must not 500 on a mail failure.
 */
function luminal_send_mail(string $to, string $subject, string $html, string $text = '', array $opt = []): array {
    $c = luminal_mail_config();
    $fromEmail = $opt['from_email'] ?? $c['from_email'];
    $fromName  = $opt['from_name']  ?? $c['from_name'];
    $replyTo   = $opt['reply_to']   ?? '';
    // Comma-separated. MyStore copies the shop admin on invoices and shipping notices, so
    // without this the abstraction could not replace those callers without silently
    // dropping the admin's copy.
    $cc        = trim((string)($opt['cc'] ?? ''));
    if ($text === '') $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

    if ($c['effective'] === 'mailgun') {
        if (!$c['mailgun_ready']) {
            return ['ok' => false, 'transport' => 'mailgun',
                    'error' => 'Transport is forced to mailgun but no API key/domain is configured.'];
        }
        $base = $c['region'] === 'eu' ? 'https://api.eu.mailgun.net/v3' : 'https://api.mailgun.net/v3';
        $from = $fromName !== '' ? "$fromName <$fromEmail>" : $fromEmail;
        $post = ['from' => $from, 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
        if ($replyTo !== '') $post['h:Reply-To'] = $replyTo;
        if ($cc !== '')      $post['cc']         = $cc;

        $ch = curl_init("$base/{$c['domain']}/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => 'api:' . $c['api_key'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return ['ok' => true, 'transport' => 'mailgun', 'error' => ''];
        return ['ok' => false, 'transport' => 'mailgun',
                'error' => "Mailgun HTTP $code" . ($err ? " ($err)" : '') . ' ' . substr((string)$resp, 0, 200)];
    }

    // ---- authenticated SMTP submission ------------------------------------------------
    if ($c['effective'] === 'smtp') {
        if (!$c['smtp_ready']) {
            return ['ok' => false, 'transport' => 'smtp',
                    'error' => 'Transport is forced to smtp but smtp_host/smtp_user/smtp_pass are not all set.'];
        }
        return luminal_smtp_send($c, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, $cc);
    }

    // ---- local delivery via the host's MTA -------------------------------------------
    $boundary = 'lum_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'From: ' . ($fromName !== '' ? "$fromName <$fromEmail>" : $fromEmail);
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    if ($cc !== '')      $headers[] = 'Cc: ' . $cc;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";

    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n";
    $body .= "--$boundary--\r\n";

    // -f sets the envelope sender so the MTA sends with an address on our own domain,
    // which is what makes SPF line up. Silently ignored if the host disallows it.
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $fromEmail);

    return $ok
        ? ['ok' => true, 'transport' => 'local', 'error' => '']
        : ['ok' => false, 'transport' => 'local',
           'error' => 'PHP mail() returned false — the host MTA rejected or is unavailable.'];
}


/**
 * RFC 2047 encode a header value, but only if it needs it.
 *
 * Mail headers are 7-bit ASCII. Anything else has to be encoded or the message is malformed —
 * and this codebase writes em-dashes into subjects ("Password Reset — {domain}"), so it is
 * not hypothetical. Plain-ASCII values are returned untouched so the common case stays
 * readable on the wire.
 *
 * Split into multiple encoded-words folded with CRLF+space: RFC 2047 caps an encoded-word at
 * 75 chars, and a long subject would otherwise exceed it. Chunking walks whole UTF-8
 * characters, never bytes, or a multi-byte character would be cut in half.
 */
function luminal_mime_encode_header(string $v): string {
    if (!preg_match('/[^\x20-\x7E]/', $v)) return $v;   // printable ASCII — nothing to do

    $chunks = [];
    $cur    = '';
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        // 45 raw bytes encodes to 60 base64 chars, comfortably inside the 75-char limit
        // once "=?UTF-8?B?" and "?=" are added.
        if (strlen($cur) + strlen($ch) > 45) { $chunks[] = $cur; $cur = ''; }
        $cur .= $ch;
    }
    if ($cur !== '') $chunks[] = $cur;

    return implode("\r\n ", array_map(
        static function (string $p): string { return '=?UTF-8?B?' . base64_encode($p) . '?='; },
        $chunks
    ));
}

/**
 * Minimal authenticated SMTP client.
 *
 * Written directly rather than pulling in PHPMailer: this codebase has no vendor tree, and
 * a client that only has to do EHLO / STARTTLS / AUTH LOGIN / MAIL / RCPT / DATA is smaller
 * than the dependency would be. It is the same submission path the WP Mail SMTP plugin uses
 * against a cPanel mailbox.
 *
 * Never throws — callers are form handlers that must not 500 on a mail failure.
 *
 * @return array{ok:bool,transport:string,error:string}
 */
function luminal_smtp_send(array $c, string $to, string $subject, string $html, string $text,
                           string $fromEmail, string $fromName, string $replyTo,
                           string $cc = ''): array {
    $fail = static function (string $msg): array {
        return ['ok' => false, 'transport' => 'smtp', 'error' => $msg];
    };

    // Strip CR/LF from everything that lands in a header. This transport writes headers
    // straight to the socket, so unlike the other two it has no guard of its own: the
    // mailgun path hands values to the API as POST fields, and PHP's mail() rejects bare
    // CR/LF in its to/subject/headers arguments. A newline here would let a caller append
    // arbitrary headers — a Bcc: on a public form turns the site's own authenticated
    // mailbox into an open relay. Nothing legitimate needs a newline in these five values.
    $strip = static function (string $v): string {
        return str_replace(["\r", "\n"], '', $v);
    };
    $to        = $strip($to);
    $subject   = $strip($subject);
    $fromEmail = $strip($fromEmail);
    $fromName  = $strip($fromName);
    $replyTo   = $strip($replyTo);
    $cc        = $strip($cc);

    $host   = $c['smtp_host'];
    $port   = $c['smtp_port'] > 0 ? $c['smtp_port'] : 587;
    $secure = $c['smtp_secure'];
    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true]]);
    $fp = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return $fail("SMTP connect to $target failed: $errstr ($errno)");
    stream_set_timeout($fp, 20);

    // Read a reply, following multi-line continuations ("250-" continues, "250 " ends).
    $read = static function () use ($fp): array {
        $lines = [];
        while (($line = fgets($fp, 8192)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $last = end($lines) ?: '';
        return [(int)substr($last, 0, 3), implode(' | ', $lines)];
    };
    $say = static function (string $cmd) use ($fp): void { @fwrite($fp, $cmd . "\r\n"); };

    $close = static function () use ($fp) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); };

    [$code, $msg] = $read();
    if ($code !== 220) { $close(); return $fail("SMTP greeting was $code: $msg"); }

    $helo = luminal_mail_domain();
    $say("EHLO $helo");
    [$code, $ehlo] = $read();
    if ($code !== 250) { $close(); return $fail("EHLO refused ($code): $ehlo"); }

    if ($secure === 'tls') {
        $say('STARTTLS');
        [$code, $msg] = $read();
        if ($code !== 220) { $close(); return $fail("STARTTLS refused ($code): $msg"); }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $close(); return $fail('STARTTLS negotiation failed');
        }
        // RFC 3207: re-issue EHLO over the encrypted channel; the pre-TLS
        // capability list is not trustworthy and AUTH is usually only offered after.
        $say("EHLO $helo");
        [$code, $ehlo] = $read();
        if ($code !== 250) { $close(); return $fail("EHLO after STARTTLS refused ($code): $ehlo"); }
    }

    $say('AUTH LOGIN');
    [$code, $msg] = $read();
    if ($code !== 334) { $close(); return $fail("AUTH LOGIN refused ($code): $msg"); }
    $say(base64_encode($c['smtp_user']));
    [$code, $msg] = $read();
    if ($code !== 334) { $close(); return $fail("SMTP username rejected ($code): $msg"); }
    $say(base64_encode($c['smtp_pass']));
    [$code, $msg] = $read();
    if ($code !== 235) { $close(); return $fail("SMTP authentication failed ($code): $msg"); }

    $say('MAIL FROM:<' . $fromEmail . '>');
    [$code, $msg] = $read();
    if ($code !== 250) { $close(); return $fail("MAIL FROM rejected ($code): $msg"); }

    // Cc recipients need an envelope RCPT of their own — a Cc: header alone is just text and
    // delivers to nobody. (Bcc would be the reverse: envelope only, no header.)
    $rcptList = [];
    foreach (preg_split('/[,;]\s*/', $to, -1, PREG_SPLIT_NO_EMPTY) ?: [$to] as $r) {
        $rcptList[] = ['addr' => trim($r), 'required' => true];
    }
    if ($cc !== '') {
        foreach (preg_split('/[,;]\s*/', $cc, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $r) {
            $rcptList[] = ['addr' => trim($r), 'required' => false];
        }
    }
    foreach ($rcptList as $r) {
        $say('RCPT TO:<' . $r['addr'] . '>');
        [$code, $msg] = $read();
        if ($code === 250 || $code === 251) continue;
        // A rejected Cc must not lose the message. MyStore copies the shop admin on every
        // invoice; if that address is stale the customer must still get their invoice.
        // Under mail() a bad Cc was inert text, so aborting here would be a regression
        // introduced by centralising, not a pre-existing behaviour.
        if (!$r['required']) continue;
        $close();
        return $fail("RCPT TO <{$r['addr']}> rejected ($code): $msg");
    }

    $say('DATA');
    [$code, $msg] = $read();
    if ($code !== 354) { $close(); return $fail("DATA refused ($code): $msg"); }

    $boundary = 'lum_' . bin2hex(random_bytes(8));
    // Display names and subjects carry non-ASCII; the addresses themselves never do.
    $encName = luminal_mime_encode_header($fromName);
    $head = [];
    $head[] = 'Date: ' . date('r');
    $head[] = 'From: ' . ($fromName !== '' ? sprintf('%s <%s>', $encName, $fromEmail) : $fromEmail);
    $head[] = 'To: ' . $to;
    if ($cc !== '') $head[] = 'Cc: ' . $cc;
    $head[] = 'Subject: ' . luminal_mime_encode_header($subject);
    if ($replyTo !== '') $head[] = 'Reply-To: ' . $replyTo;
    $head[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $helo . '>';
    $head[] = 'MIME-Version: 1.0';
    $head[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    // Quoted-printable, not raw 8-bit. Two reasons, both of which bite in practice:
    //
    //  1. RFC 5321 caps a line at 998 octets. Real bodies here blow straight through that —
    //     EventsManagerPro embeds event flyers as base64 data: URIs, which is a single line
    //     of hundreds of KB. Measured before this change: a 5,043-octet line went out
    //     unwrapped. A server that hard-wraps it corrupts the markup; one that enforces the
    //     limit rejects the message.
    //  2. Without a Content-Transfer-Encoding, 8-bit UTF-8 in the body is only legal if the
    //     server advertised 8BITMIME and we negotiated it, which this client does not do.
    //
    // QP folds at 76 chars with soft "=\r\n" breaks and is understood universally. Line
    // endings are normalised to CRLF first so real breaks stay hard breaks instead of
    // being escaped to "=0A".
    $crlf     = static function (string $s): string { return preg_replace('/\r\n|\r|\n/', "\r\n", $s); };
    $encText  = quoted_printable_encode($crlf($text));
    $encHtml  = quoted_printable_encode($crlf($html));

    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: quoted-printable\r\n\r\n$encText\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: quoted-printable\r\n\r\n$encHtml\r\n";
    $body .= "--$boundary--\r\n";

    $payload = implode("\r\n", $head) . "\r\n\r\n" . $body;
    // RFC 5321 dot-stuffing: a line that is just "." would otherwise end the message early.
    $payload = preg_replace('/^\./m', '..', $payload);
    @fwrite($fp, $payload . "\r\n.\r\n");

    [$code, $msg] = $read();
    $close();
    if ($code !== 250) return $fail("Message rejected at DATA end ($code): $msg");

    return ['ok' => true, 'transport' => 'smtp', 'error' => ''];
}
