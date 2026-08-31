<?php
/**
 * MembershipManager — API Endpoint
 *
 * Invite-only membership with referral tracking, activity logging, and leaderboards.
 * No payment required — invite codes gate access. Stripe hooks preserved for future billing.
 *
 * Admin: get_config, save_config, list_members, generate_codes, get_referrals, get_leaderboard, get_activity
 * Public: redeem_invite, my_membership, my_invites, my_activity, log_activity
 * Webhook: stripe_webhook (preserved for future use)
 *
 * @package  LuminalCMS
 * @module   MembershipManager
 * @version  2.0.0 — Invite-Only Pivot
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/utils.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function mm_json(array $data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function mm_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data/MembershipManager';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

/* ── Data Loaders ────────────────────────────────── */

function mm_load_config(): array {
    $file = mm_data_dir() . '/config.json';
    if (!is_file($file)) return mm_default_config();
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return mm_default_config();
    // Credential Vault: un-seal stripe_secret_key (plaintext passes through untouched).
    if (!function_exists('cred_vault_reveal_deep') && defined('SITE_ROOT')
        && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
        require_once SITE_ROOT . '/admin/config/cred_vault.php';
    }
    if (function_exists('cred_vault_reveal_deep')) $data = cred_vault_reveal_deep($data);
    return array_merge(mm_default_config(), $data);
}

function mm_default_config(): array {
    return [
        'enabled' => true,
        'mode' => 'invite_only',            // invite_only | open | paid
        'invites_per_user' => 5,
        'referral_points_per_invite' => 10,
        'rewards_require_paid' => true,      // Points accumulate free; badges/swag require paid membership
        'paid_membership_price' => 500,      // cents ($5.00/mo) — for future Stripe billing
        'disclaimer' => 'Membership is provided for informational purposes. By accepting an invite, you acknowledge and agree to this site\'s Terms of Service and Privacy Policy.',
        'badge_tiers' => [
            ['id' => 'scout', 'name' => 'Scout', 'min_referrals' => 1, 'reward' => 'Badge + Profile flair'],
            ['id' => 'pathfinder', 'name' => 'Pathfinder', 'min_referrals' => 3, 'reward' => 'Coffee mug'],
            ['id' => 'trailblazer', 'name' => 'Trailblazer', 'min_referrals' => 5, 'reward' => 'T-shirt'],
            ['id' => 'pioneer', 'name' => 'Pioneer', 'min_referrals' => 10, 'reward' => 'Hoodie + lifetime early-access lock'],
            ['id' => 'founder', 'name' => 'Founder', 'min_referrals' => 25, 'reward' => 'Founder badge + all swag + advisory board seat']
        ],
        // Stripe preserved for future
        'stripe_secret_key' => '',
        'stripe_publishable_key' => '',
        'stripe_webhook_secret' => '',
    ];
}

function mm_save_config(array $config): bool {
    $file = mm_data_dir() . '/config.json';
    $result = file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($result !== false) @chmod($file, 0664);
    return $result !== false;
}

/**
 * Resolve this site's brand/identity for member-facing output (invite emails, disclaimers,
 * invite-code prefix, anon handles, hub-forwarding). Precedence: MembershipManager/config.json
 * → site-settings.json → request host. De-branded 2026-06-04;
 * any site overrides via config.json keys: brand_name, source_domain, join_url, email_from_name,
 * email_tagline, anon_salt, code_prefix, form_slug.
 */
function mm_brand(): array {
    static $b = null;
    if ($b !== null) return $b;
    $cfg = mm_load_config();
    $ss = [];
    $ssf = SITE_ROOT . '/admin/data/site-settings.json';
    if (is_file($ssf)) { $d = json_decode(file_get_contents($ssf), true); if (is_array($d)) $ss = $d; }
    // Domain resolution WITH trust tracking. $_SERVER['HTTP_HOST'] is attacker-controllable, so a
    // host-derived domain is treated as UNTRUSTED and never used for absolute links in outbound
    // email (host-header-poisoning guard, 2026-06-04). A trusted domain comes only from config
    // source_domain or a site-settings canonical key.
    $rawHost  = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $rawHost  = preg_replace('/^www\./', '', $rawHost);
    $validHost = ($rawHost !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $rawHost)) ? $rawHost : '';
    $canonical = !empty($cfg['source_domain']) ? $cfg['source_domain']
               : (!empty($ss['canonical_domain']) ? $ss['canonical_domain']
               : (!empty($ss['site_url']) ? $ss['site_url'] : ''));
    $canonical = $canonical ? strtolower(preg_replace('#^https?://#', '', rtrim((string)$canonical, '/'))) : '';
    $trusted   = ($canonical !== '');
    $domain    = $trusted ? $canonical : ($validHost ?: 'localhost');
    $name   = trim((string)($cfg['brand_name'] ?? '')) ?: (trim((string)($ss['site_title'] ?? $ss['site_name'] ?? '')) ?: $domain);
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($cfg['code_prefix'] ?? 'MM'))) ?: 'MM';
    $b = [
        'name'           => $name,
        'domain'         => $domain,
        'domain_trusted' => $trusted,
        // Absolute join URL ONLY when the domain is trusted; otherwise a same-site relative anchor.
        'join_url'       => !empty($cfg['join_url']) ? $cfg['join_url'] : ($trusted ? ('https://' . $domain . '/#join') : '/#join'),
        'from_name'      => !empty($cfg['email_from_name']) ? $cfg['email_from_name'] : $name,
        'tagline'        => (string)($cfg['email_tagline'] ?? ''),
        'anon_salt'      => !empty($cfg['anon_salt']) ? $cfg['anon_salt'] : ('mm-anon-' . md5($domain)),
        'code_prefix'    => $prefix,
        'form_slug'      => !empty($cfg['form_slug']) ? $cfg['form_slug'] : (preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)) . '-invites'),
    ];
    return $b;
}

function mm_load_json(string $filename): array {
    $file = mm_data_dir() . '/' . $filename;
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function mm_save_json(string $filename, array $data): bool {
    $file = mm_data_dir() . '/' . $filename;
    $result = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($result !== false) @chmod($file, 0664);
    return $result !== false;
}

/* ── Invite Code Helpers ─────────────────────────── */

function mm_generate_code(): string {
    // Format: {PREFIX}-XXXX-XXXX (readable, copy-friendly). Prefix is per-site (mm_brand).
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I,O,0,1 to avoid confusion
    $seg = function() use ($chars) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return mm_brand()['code_prefix'] . '-' . $seg() . '-' . $seg();
}

function mm_generate_anon_handle(string $userId): string {
    // Deterministic anonymous handle from user ID
    $hash = substr(md5($userId . mm_brand()['anon_salt']), 0, 8);
    $adjectives = ['Swift','Bold','Keen','Sharp','Wise','Quick','Bright','Deep','True','Vast'];
    $nouns = ['Fox','Hawk','Wolf','Bear','Eagle','Lion','Whale','Tiger','Owl','Raven'];
    $adj = $adjectives[hexdec(substr($hash, 0, 2)) % count($adjectives)];
    $noun = $nouns[hexdec(substr($hash, 2, 2)) % count($nouns)];
    $num = str_pad((string)(hexdec(substr($hash, 4, 4)) % 10000), 4, '0', STR_PAD_LEFT);
    return $adj . $noun . $num;
}

function mm_get_badge_tier(int $referralCount, array $config, bool $isPaid = true): ?array {
    $tiers = $config['badge_tiers'] ?? [];
    usort($tiers, fn($a, $b) => ($b['min_referrals'] ?? 0) - ($a['min_referrals'] ?? 0));
    $earnedTier = null;
    foreach ($tiers as $t) {
        if ($referralCount >= ($t['min_referrals'] ?? 0)) { $earnedTier = $t; break; }
    }
    if (!$earnedTier) return null;

    // If rewards require paid membership and member is free, mark tier as pending
    if (($config['rewards_require_paid'] ?? false) && !$isPaid) {
        $earnedTier['pending'] = true;  // Points earned but rewards locked until paid
    }
    return $earnedTier;
}

function mm_is_paid_member(array $member): bool {
    return ($member['membership_type'] ?? 'free') === 'paid';
}

/* ── Stripe Webhook (preserved for future) ───────── */
if ($action === 'stripe_webhook') {
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $config = mm_load_config();
    $secret = $config['stripe_webhook_secret'] ?? '';

    if (empty($secret) || empty($sigHeader)) {
        mm_json(['ok' => false, 'error' => 'Webhook not configured'], 400);
    }

    $elements = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $val] = explode('=', $part, 2);
        $elements[trim($key)] = trim($val);
    }
    $timestamp = $elements['t'] ?? '';
    $signature = $elements['v1'] ?? '';
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    if (!hash_equals($expected, $signature)) mm_json(['ok' => false, 'error' => 'Invalid signature'], 403);
    if (abs(time() - (int)$timestamp) > 300) mm_json(['ok' => false, 'error' => 'Timestamp too old'], 403);

    // Process webhook (future billing events)
    $event = json_decode($payload, true);
    mm_json(['ok' => true, 'received' => $event['type'] ?? 'unknown']);
}

/* ── Public: Check session (for Google OAuth prefill) ── */
if ($action === 'check_session') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $email = $_SESSION['user_email'] ?? '';
    $name = $_SESSION['user_display_name'] ?? '';
    if ($email) {
        mm_json(['ok' => true, 'authenticated' => true, 'name' => $name, 'email' => $email]);
    } else {
        mm_json(['ok' => true, 'authenticated' => false]);
    }
}

/* ── Public: Register Interest (Step 1 — capture email to AudienceCollector) ── */
if ($action === 'register_interest') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));

    if (!$email) mm_json(['ok' => false, 'error' => 'Email is required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) mm_json(['ok' => false, 'error' => 'Invalid email'], 400);

    // Check if already a member
    $members = mm_load_json('members.json');
    foreach ($members as $m) {
        if (strtolower($m['email'] ?? '') === $email) {
            mm_json(['ok' => true, 'already_member' => true, 'anon_handle' => $m['anon_handle'] ?? '']);
        }
    }

    // Forward to AudienceCollector on hub
    mm_forward_to_hub([
        'type' => 'interest_signup',
        'name' => $name,
        'email' => $email,
        'source' => mm_brand()['domain'],
        'timestamp' => date('c'),
    ]);

    // Store locally too
    $interests = mm_load_json('interests.json');
    // Deduplicate
    $found = false;
    foreach ($interests as &$i) {
        if (strtolower($i['email'] ?? '') === $email) { $i['last_seen'] = date('c'); $found = true; break; }
    }
    unset($i);
    if (!$found) {
        $interests[] = ['name' => $name, 'email' => $email, 'created_at' => date('c'), 'last_seen' => date('c'), 'invite_requested' => false];
    }
    mm_save_json('interests.json', $interests);

    mm_json(['ok' => true, 'already_member' => false]);
}

/* ── Public: Request Invite Code (rate-limited, emails a code) ── */
if ($action === 'request_invite') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) mm_json(['ok' => false, 'error' => 'Valid email required'], 400);

    // Rate limit: max 1 request per email per 24 hours
    $rateFile = mm_data_dir() . '/rate_limits.json';
    $rates = is_file($rateFile) ? (json_decode(file_get_contents($rateFile), true) ?: []) : [];
    $now = time();
    $lastRequest = $rates[$email] ?? 0;
    if ($now - $lastRequest < 86400) {
        $hoursLeft = ceil((86400 - ($now - $lastRequest)) / 3600);
        mm_json(['ok' => false, 'error' => "An invite was already sent to this email. You can request again in {$hoursLeft} hour(s)."], 429);
    }

    // Check if already a member
    $members = mm_load_json('members.json');
    foreach ($members as $m) {
        if (strtolower($m['email'] ?? '') === $email) {
            mm_json(['ok' => false, 'error' => 'You already have a membership! Check your email for your invite codes.'], 400);
        }
    }

    // Generate a single-use code
    $code = mm_generate_code();
    $codes = mm_load_json('invite_codes.json');
    $codes[] = [
        'code' => $code,
        'created_by' => 'system_request',
        'generated_for' => $email,
        'created_at' => date('c'),
        'redeemed' => false,
        'redeemed_by' => null,
        'redeemed_at' => null,
        'revoked' => false,
    ];
    mm_save_json('invite_codes.json', $codes);

    // Send via the shared transport. The old guard here meant that a site without a Mailgun
    // config never sent an invite code at all — silently, since $sent just stayed false.
    $sent = mm_send_invite_email($email, $code, mm_load_mailgun_config() ?: []);

    // Update rate limit
    $rates[$email] = $now;
    file_put_contents($rateFile, json_encode($rates, JSON_PRETTY_PRINT));
    @chmod($rateFile, 0664);

    // Log activity
    $log = mm_load_json('activity_log.json');
    $log[] = ['type' => 'invite_requested', 'message' => "Invite code requested and " . ($sent ? 'sent' : 'generated (email delivery failed)') . " for {$email}", 'timestamp' => date('c')];
    mm_save_json('activity_log.json', $log);

    if ($sent) {
        mm_json(['ok' => true, 'message' => 'Invite code sent to your email! Check your inbox (and spam folder).']);
    } else {
        // If Mailgun fails, still return the code directly as fallback
        mm_json(['ok' => true, 'message' => 'Here is your invite code:', 'code' => $code]);
    }
}

/* ── Mailgun Helpers ────────────────────────────────── */
function mm_load_mailgun_config(): ?array {
    $file = SITE_ROOT . '/admin/data/MailgunManager/mailgun-config.json';
    if (!is_file($file)) return null;
    $data = function_exists('cred_load_json') ? cred_load_json($file) : json_decode(file_get_contents($file), true);
    if (empty($data['api_key']) || empty($data['domain'])) return null;
    return $data;
}

function mm_send_invite_email(string $toEmail, string $code, array $mgConfig = []): bool {
    $brand = mm_brand();
    $fromName = $brand['from_name'];
    $fromEmail = $mgConfig['from_email'] ?? '';
    $subject = 'Your ' . $brand['name'] . ' Invite Code';

    $taglineHtml = $brand['tagline'] !== ''
        ? '<p style="color:#8B7355;font-size:13px;letter-spacing:0.15em;text-transform:uppercase;margin:0 0 30px">' . htmlspecialchars($brand['tagline']) . '</p>'
        : '';
    // Only emit an absolute clickable domain when it's trusted (config/canonical), never a
    // host-derived one — prevents host-header poisoning of the invite-email link.
    $visitLine = $brand['domain_trusted']
        ? '<p style="font-size:15px;line-height:1.7;color:#4A5C6A">Visit <a href="' . htmlspecialchars($brand['join_url']) . '" style="color:#C9A962">' . htmlspecialchars($brand['domain']) . '</a> and enter this code to complete your registration.</p>'
        : '<p style="font-size:15px;line-height:1.7;color:#4A5C6A">Return to ' . htmlspecialchars($brand['name']) . ' and enter this code to complete your registration.</p>';
    $html = '<!DOCTYPE html><html><body style="font-family:Georgia,serif;background:#FAF8F5;padding:40px 20px;color:#2A3B47">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #E8E4DD;padding:40px">'
        . '<h1 style="font-family:Georgia,serif;font-size:24px;font-weight:normal;color:#2A3B47;margin:0 0 8px">' . htmlspecialchars($brand['name']) . '</h1>'
        . $taglineHtml
        . '<p style="font-size:16px;line-height:1.7;color:#4A5C6A">You requested an invite code to join the ' . htmlspecialchars($brand['name']) . ' platform. Here it is:</p>'
        . '<div style="text-align:center;margin:30px 0;padding:24px;background:#FAF8F5;border:1px solid #E8E4DD">'
        . '<span style="font-family:monospace;font-size:28px;font-weight:bold;color:#C9A962;letter-spacing:0.1em">' . htmlspecialchars($code) . '</span>'
        . '</div>'
        . $visitLine
        . '<p style="font-size:13px;color:#8B7355;margin-top:30px;border-top:1px solid #E8E4DD;padding-top:20px">This code is single-use and was generated just for you. Do not share it publicly.</p>'
        . '</div></body></html>';

    // Transport selection belongs to mail.php, which also gives this module a fallback it
    // never had: it was Mailgun-or-nothing, so a site without Mailgun configured could not
    // deliver an invite code at all.
    require_once (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/includes/mail.php';
    $opt = ['from_name' => $fromName];
    if ($fromEmail !== '') $opt['from_email'] = $fromEmail;

    $res = luminal_send_mail($toEmail, $subject, $html, '', $opt);
    return !empty($res['ok']);
}

/* ── Public: Free Signup (no invite code required — open access) ── */
if ($action === 'signup') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $referralCode = strtoupper(trim($input['referral_code'] ?? ''));

    if (!$email) mm_json(['ok' => false, 'error' => 'Email is required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) mm_json(['ok' => false, 'error' => 'Invalid email'], 400);

    // Check if already a member
    $members = mm_load_json('members.json');
    foreach ($members as $m) {
        if (strtolower($m['email'] ?? '') === $email) {
            mm_json(['ok' => true, 'already_member' => true, 'member' => [
                'anon_handle' => $m['anon_handle'] ?? '',
                'invite_codes' => $m['invite_codes'] ?? [],
            ]]);
        }
    }

    $config = mm_load_config();
    $memberId = 'MBR-' . date('Ymd') . '-' . substr(md5(random_bytes(8)), 0, 6);

    // Generate this new member's invite codes
    $allCodes = mm_load_json('invite_codes.json');
    $newCodes = [];
    for ($i = 0; $i < ($config['invites_per_user'] ?? 5); $i++) {
        $nc = [
            'code' => mm_generate_code(),
            'created_by' => $memberId,
            'generated_for' => $memberId,
            'created_at' => date('c'),
            'redeemed' => false,
            'redeemed_by' => null,
            'redeemed_at' => null,
            'revoked' => false,
        ];
        $newCodes[] = $nc;
        $allCodes[] = $nc;
    }
    mm_save_json('invite_codes.json', $allCodes);

    // Process optional referral code
    $inviterId = null;
    if ($referralCode) {
        $codes = mm_load_json('invite_codes.json');
        foreach ($codes as &$c) {
            if ($c['code'] === $referralCode && !$c['redeemed'] && !($c['revoked'] ?? false)) {
                $c['redeemed'] = true;
                $c['redeemed_by'] = $email;
                $c['redeemed_at'] = date('c');
                $inviterId = $c['created_by'] ?? null;
                break;
            }
        }
        unset($c);
        mm_save_json('invite_codes.json', $codes);
    }

    $member = [
        'id' => $memberId,
        'name' => $name,
        'email' => $email,
        'anon_handle' => mm_generate_anon_handle($memberId),
        'membership_type' => 'free',           // free | paid — rewards locked until paid
        'status' => 'active',
        'invited_by_code' => $referralCode ?: null,
        'invited_by_member' => $inviterId ?: 'organic',
        'invite_codes' => array_column($newCodes, 'code'),
        'referral_count' => 0,
        'referral_points' => 0,
        'badge' => null,
        'wins' => 0,
        'total_picks' => 0,
        'joined_at' => date('c'),
        'last_active_at' => date('c'),
        'signup_method' => $referralCode ? 'invite_code' : 'organic',
        'disclaimer_accepted' => true,
        'disclaimer_accepted_at' => date('c'),
    ];
    $members[] = $member;
    mm_save_json('members.json', $members);

    // Update referrer stats if invite code was used
    if ($inviterId && $inviterId !== 'system' && $inviterId !== 'system_request') {
        $members = mm_load_json('members.json'); // reload
        foreach ($members as &$m) {
            if ($m['id'] === $inviterId) {
                $m['referral_count'] = ($m['referral_count'] ?? 0) + 1;
                $m['referral_points'] = ($m['referral_points'] ?? 0) + ($config['referral_points_per_invite'] ?? 10);
                $m['badge'] = mm_get_badge_tier($m['referral_count'], $config, mm_is_paid_member($m));

                // Auto-replenish if all codes used
                $referrerCodes = $m['invite_codes'] ?? [];
                $latestCodes = mm_load_json('invite_codes.json');
                $availableCount = 0;
                foreach ($latestCodes as $lc) {
                    if (in_array($lc['code'], $referrerCodes) && !$lc['redeemed'] && !($lc['revoked'] ?? false)) $availableCount++;
                }
                if ($availableCount === 0) {
                    $replenishCount = $config['invites_per_user'] ?? 5;
                    $freshCodes = [];
                    for ($r = 0; $r < $replenishCount; $r++) {
                        $fc = ['code'=>mm_generate_code(),'created_by'=>$m['id'],'generated_for'=>$m['id'],'created_at'=>date('c'),'redeemed'=>false,'redeemed_by'=>null,'redeemed_at'=>null,'revoked'=>false];
                        $latestCodes[] = $fc;
                        $freshCodes[] = $fc['code'];
                    }
                    mm_save_json('invite_codes.json', $latestCodes);
                    $m['invite_codes'] = array_merge($m['invite_codes'] ?? [], $freshCodes);
                }
                break;
            }
        }
        unset($m);
        mm_save_json('members.json', $members);

        // Log referral
        $ledger = mm_load_json('referral_ledger.json');
        $ledger[] = ['inviter_id'=>$inviterId,'invitee_id'=>$memberId,'invitee_email'=>$email,'code_used'=>$referralCode,'timestamp'=>date('c')];
        mm_save_json('referral_ledger.json', $ledger);
    }

    // Forward to hub
    mm_forward_to_hub(['type'=>'member_signup','member_id'=>$memberId,'name'=>$name,'email'=>$email,'method'=>$referralCode?'invite':'organic','source'=>mm_brand()['domain'],'timestamp'=>date('c')]);

    // Log activity
    $log = mm_load_json('activity_log.json');
    $log[] = ['type'=>'member_joined','message'=>$member['anon_handle'].' joined'.($referralCode?' via invite code':' (organic signup)'),'timestamp'=>date('c')];
    mm_save_json('activity_log.json', $log);

    mm_json(['ok' => true, 'member' => [
        'id' => $memberId,
        'anon_handle' => $member['anon_handle'],
        'invite_codes' => $member['invite_codes'],
        'disclaimer' => $config['disclaimer'] ?? '',
    ]]);
}

/* ── Public: Redeem Invite (legacy — still works) ── */
if ($action === 'redeem_invite') {
    $input = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(trim($input['code'] ?? ''));
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');

    if (!$code || !$email) mm_json(['ok' => false, 'error' => 'Invite code and email required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) mm_json(['ok' => false, 'error' => 'Invalid email'], 400);

    // Find the invite code
    $codes = mm_load_json('invite_codes.json');
    $codeRecord = null;
    $codeIdx = null;
    foreach ($codes as $i => $c) {
        if ($c['code'] === $code) { $codeRecord = $c; $codeIdx = $i; break; }
    }

    if (!$codeRecord) mm_json(['ok' => false, 'error' => 'Invalid invite code'], 404);
    if ($codeRecord['redeemed']) mm_json(['ok' => false, 'error' => 'This invite code has already been used'], 400);
    if ($codeRecord['revoked'] ?? false) mm_json(['ok' => false, 'error' => 'This invite code has been revoked'], 400);

    // Mark code as redeemed
    $codes[$codeIdx]['redeemed'] = true;
    $codes[$codeIdx]['redeemed_by'] = $email;
    $codes[$codeIdx]['redeemed_at'] = date('c');
    mm_save_json('invite_codes.json', $codes);

    // Create member record
    $members = mm_load_json('members.json');
    $memberId = 'MBR-' . date('Ymd') . '-' . substr(md5(random_bytes(8)), 0, 6);
    $config = mm_load_config();

    // Generate this new member's invite codes
    $newCodes = [];
    $allCodes = mm_load_json('invite_codes.json'); // reload after save
    for ($i = 0; $i < ($config['invites_per_user'] ?? 5); $i++) {
        $nc = [
            'code' => mm_generate_code(),
            'created_by' => $memberId,
            'generated_for' => $memberId,
            'created_at' => date('c'),
            'redeemed' => false,
            'redeemed_by' => null,
            'redeemed_at' => null,
            'revoked' => false,
        ];
        $newCodes[] = $nc;
        $allCodes[] = $nc;
    }
    mm_save_json('invite_codes.json', $allCodes);

    $member = [
        'id' => $memberId,
        'name' => $name,
        'email' => $email,
        'anon_handle' => mm_generate_anon_handle($memberId),
        'membership_type' => 'free',           // free | paid — rewards locked until paid
        'status' => 'active',
        'invited_by_code' => $code,
        'invited_by_member' => $codeRecord['created_by'] ?? 'system',
        'invite_codes' => array_column($newCodes, 'code'),
        'referral_count' => 0,
        'referral_points' => 0,
        'badge' => null,
        'wins' => 0,
        'total_picks' => 0,
        'joined_at' => date('c'),
        'last_active_at' => date('c'),
        'disclaimer_accepted' => true,
        'disclaimer_accepted_at' => date('c'),
    ];
    $members[] = $member;
    mm_save_json('members.json', $members);

    // Update referrer's count + auto-replenish codes
    $inviterId = $codeRecord['created_by'] ?? '';
    if ($inviterId && $inviterId !== 'system') {
        foreach ($members as &$m) {
            if ($m['id'] === $inviterId) {
                $m['referral_count'] = ($m['referral_count'] ?? 0) + 1;
                $m['referral_points'] = ($m['referral_points'] ?? 0) + ($config['referral_points_per_invite'] ?? 10);
                $m['badge'] = mm_get_badge_tier($m['referral_count'], $config, mm_is_paid_member($m));

                // Auto-replenish: if all of referrer's codes are used/revoked, give fresh rack
                $referrerCodes = $m['invite_codes'] ?? [];
                $latestCodes = mm_load_json('invite_codes.json');
                $availableCount = 0;
                foreach ($latestCodes as $lc) {
                    if (in_array($lc['code'], $referrerCodes) && !$lc['redeemed'] && !($lc['revoked'] ?? false)) {
                        $availableCount++;
                    }
                }
                if ($availableCount === 0) {
                    // Fresh rack
                    $replenishCount = $config['invites_per_user'] ?? 5;
                    $freshCodes = [];
                    for ($r = 0; $r < $replenishCount; $r++) {
                        $fc = [
                            'code' => mm_generate_code(),
                            'created_by' => $m['id'],
                            'generated_for' => $m['id'],
                            'created_at' => date('c'),
                            'redeemed' => false,
                            'redeemed_by' => null,
                            'redeemed_at' => null,
                            'revoked' => false,
                        ];
                        $latestCodes[] = $fc;
                        $freshCodes[] = $fc['code'];
                    }
                    mm_save_json('invite_codes.json', $latestCodes);
                    $m['invite_codes'] = array_merge($m['invite_codes'] ?? [], $freshCodes);

                    // Log replenish
                    $alog = mm_load_json('activity_log.json');
                    $alog[] = ['type' => 'code_generated', 'message' => "Auto-replenished {$replenishCount} invite codes for " . ($m['anon_handle'] ?? $m['id']), 'timestamp' => date('c')];
                    mm_save_json('activity_log.json', $alog);
                }
                break;
            }
        }
        unset($m);
        mm_save_json('members.json', $members);
    }

    // Log to referral ledger
    $ledger = mm_load_json('referral_ledger.json');
    $ledger[] = [
        'inviter_id' => $inviterId,
        'invitee_id' => $memberId,
        'invitee_email' => $email,
        'code_used' => $code,
        'timestamp' => date('c'),
    ];
    mm_save_json('referral_ledger.json', $ledger);

    // Forward to an AudienceBuilder hub, if one is configured
    mm_forward_to_hub([
        'type' => 'invite_redemption',
        'member_id' => $memberId,
        'name' => $name,
        'email' => $email,
        'invited_by' => $inviterId,
        'code' => $code,
        'source' => mm_brand()['domain'],
        'timestamp' => date('c'),
    ]);

    mm_json(['ok' => true, 'member' => [
        'id' => $memberId,
        'anon_handle' => $member['anon_handle'],
        'invite_codes' => $member['invite_codes'],
        'disclaimer' => $config['disclaimer'] ?? '',
    ]]);
}

/* ── Hub Forwarding (AudienceBuilder pipeline) ───── */
function mm_forward_to_hub(array $data): void {
    // Forward invite/referral data to a configured collector endpoint
    $hubUrl = $cfg['hub_forwarding']['url'] ?? '';
    $payload = [
        'form_slug' => mm_brand()['form_slug'],
        'name' => $data['name'] ?? '',
        'email' => $data['email'] ?? '',
        'source_domain' => $data['source'] ?? mm_brand()['domain'],
        'metadata' => json_encode($data),
    ];
    $ch = curl_init($hubUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

/* ── Auth-required endpoints ─────────────────────── */
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

switch ($action) {

    /* ── Config ────────────────────────── */
    case 'get_config':
        $cfg = mm_load_config();
        if (!empty($cfg['stripe_secret_key'])) {
            $cfg['stripe_secret_key'] = substr($cfg['stripe_secret_key'], 0, 12) . '****';
        }
        if (!empty($cfg['stripe_webhook_secret'])) {
            $cfg['stripe_webhook_secret'] = 'whsec_****';
        }
        mm_json(['ok' => true, 'config' => $cfg]);

    case 'save_config':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) mm_json(['ok' => false, 'error' => 'Invalid JSON'], 400);
        $current = mm_load_config();
        // Map JS field names to config keys
        if (isset($input['invite_codes_per_user'])) $input['invites_per_user'] = $input['invite_codes_per_user'];
        if (isset($input['referral_points_per_invite'])) $current['referral_points_per_invite'] = (int)$input['referral_points_per_invite'];
        if (isset($input['hub_forwarding'])) $current['hub_forwarding'] = $input['hub_forwarding'];
        $allowed = ['enabled', 'mode', 'invites_per_user', 'disclaimer', 'badge_tiers',
                     'rewards_require_paid', 'paid_membership_price',
                     'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) continue;
            if (in_array($key, ['stripe_secret_key', 'stripe_webhook_secret']) && str_contains((string)$input[$key], '****')) continue;
            $current[$key] = $input[$key];
        }
        mm_save_config($current) ? mm_json(['ok' => true]) : mm_json(['ok' => false, 'error' => 'Save failed'], 500);

    /* ── Members ───────────────────────── */
    case 'list_members':
        $members = mm_load_json('members.json');
        // Normalize for JS: ensure member_id, badge_tier, and membership_type fields
        $normalized = array_map(function($m) {
            $m['member_id'] = $m['id'] ?? '';
            $m['badge_tier'] = $m['badge']['name'] ?? null;
            $m['badge_pending'] = $m['badge']['pending'] ?? false;
            $m['membership_type'] = $m['membership_type'] ?? 'free';
            return $m;
        }, $members);
        $stats = [
            'total' => count($members),
            'active' => count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'active')),
            'paid' => count(array_filter($members, fn($m) => ($m['membership_type'] ?? 'free') === 'paid')),
            'free' => count(array_filter($members, fn($m) => ($m['membership_type'] ?? 'free') === 'free')),
            'total_referrals' => array_sum(array_column($members, 'referral_count')),
            'total_wins' => array_sum(array_column($members, 'wins')),
        ];
        mm_json(['ok' => true, 'members' => $normalized, 'stats' => $stats]);

    case 'get_member':
        $id = $_GET['id'] ?? '';
        $members = mm_load_json('members.json');
        foreach ($members as $m) {
            if ($m['id'] === $id) mm_json(['ok' => true, 'member' => $m]);
        }
        mm_json(['ok' => false, 'error' => 'Member not found'], 404);

    case 'set_membership_type':
        $input = json_decode(file_get_contents('php://input'), true);
        $memberId = $input['member_id'] ?? '';
        $type = $input['membership_type'] ?? '';
        if (!$memberId || !in_array($type, ['free', 'paid'])) {
            mm_json(['ok' => false, 'error' => 'member_id and membership_type (free|paid) required'], 400);
        }
        $members = mm_load_json('members.json');
        $config = mm_load_config();
        $found = false;
        foreach ($members as &$m) {
            if ($m['id'] === $memberId) {
                $m['membership_type'] = $type;
                if ($type === 'paid') $m['paid_at'] = date('c');
                // Recalculate badge with new paid status
                $m['badge'] = mm_get_badge_tier($m['referral_count'] ?? 0, $config, $type === 'paid');
                $found = true;
                break;
            }
        }
        unset($m);
        if (!$found) mm_json(['ok' => false, 'error' => 'Member not found'], 404);
        mm_save_json('members.json', $members);
        $log = mm_load_json('activity_log.json');
        $log[] = ['type' => 'membership_changed', 'message' => "Member {$memberId} set to {$type}", 'timestamp' => date('c')];
        mm_save_json('activity_log.json', $log);
        mm_json(['ok' => true]);

    /* ── Invite Codes ──────────────────── */
    case 'generate_codes':
        // Admin generates codes — optionally assigned to a specific member
        $input = json_decode(file_get_contents('php://input'), true);
        $count = min((int)($input['count'] ?? 5), 50);
        $forUser = $input['for_user'] ?? null; // member_id or null for system
        $codes = mm_load_json('invite_codes.json');
        $newCodes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = [
                'code' => mm_generate_code(),
                'created_by' => $forUser ?: 'system',
                'generated_for' => $forUser ?: 'system',
                'created_at' => date('c'),
                'redeemed' => false,
                'redeemed_by' => null,
                'redeemed_at' => null,
                'revoked' => false,
            ];
            $codes[] = $c;
            $newCodes[] = $c;
        }
        mm_save_json('invite_codes.json', $codes);

        // If assigned to a member, update their invite_codes array
        if ($forUser && $forUser !== 'system') {
            $members = mm_load_json('members.json');
            foreach ($members as &$m) {
                if ($m['id'] === $forUser || $m['email'] === $forUser) {
                    $m['invite_codes'] = array_merge($m['invite_codes'] ?? [], array_column($newCodes, 'code'));
                    break;
                }
            }
            unset($m);
            mm_save_json('members.json', $members);

            // Log activity
            $log = mm_load_json('activity_log.json');
            $log[] = [
                'type' => 'code_generated',
                'message' => "Admin generated {$count} invite code(s) for member {$forUser}",
                'timestamp' => date('c'),
            ];
            mm_save_json('activity_log.json', $log);
        }

        mm_json(['ok' => true, 'codes' => array_column($newCodes, 'code'), 'total' => count($codes)]);

    case 'list_codes':
        $codes = mm_load_json('invite_codes.json');
        $filter = $_GET['filter'] ?? 'all'; // all | available | redeemed | system
        if ($filter === 'available') $codes = array_values(array_filter($codes, fn($c) => !$c['redeemed'] && !($c['revoked'] ?? false)));
        if ($filter === 'redeemed') $codes = array_values(array_filter($codes, fn($c) => $c['redeemed']));
        if ($filter === 'system') $codes = array_values(array_filter($codes, fn($c) => ($c['created_by'] ?? '') === 'system'));
        // Normalize field names for JS
        $normalized = array_map(function($c) {
            return [
                'code' => $c['code'],
                'generated_by' => $c['created_by'] ?? 'system',
                'generated_for' => $c['generated_for'] ?? $c['created_by'] ?? 'system',
                'created_at' => $c['created_at'] ?? '',
                'used_by' => $c['redeemed'] ? ($c['redeemed_by'] ?? 'unknown') : null,
                'used_at' => $c['redeemed_at'] ?? null,
                'revoked' => $c['revoked'] ?? false,
            ];
        }, $codes);
        mm_json(['ok' => true, 'codes' => $normalized, 'count' => count($normalized)]);

    case 'revoke_code':
        // Revoke an unused invite code (admin or code owner)
        $input = json_decode(file_get_contents('php://input'), true);
        $codeStr = strtoupper(trim($input['code'] ?? ''));
        if (!$codeStr) mm_json(['ok' => false, 'error' => 'Code required'], 400);

        $codes = mm_load_json('invite_codes.json');
        $found = false;
        foreach ($codes as &$c) {
            if ($c['code'] === $codeStr) {
                if ($c['redeemed']) mm_json(['ok' => false, 'error' => 'Cannot revoke — code already redeemed'], 400);
                $c['revoked'] = true;
                $c['revoked_at'] = date('c');
                $found = true;
                break;
            }
        }
        unset($c);
        if (!$found) mm_json(['ok' => false, 'error' => 'Code not found'], 404);

        mm_save_json('invite_codes.json', $codes);

        $log = mm_load_json('activity_log.json');
        $log[] = ['type' => 'code_revoked', 'message' => "Invite code {$codeStr} was revoked", 'timestamp' => date('c')];
        mm_save_json('activity_log.json', $log);

        mm_json(['ok' => true]);

    /* ── Referral Ledger ───────────────── */
    case 'get_referrals':
        $ledger = mm_load_json('referral_ledger.json');
        $members = mm_load_json('members.json');
        // Enrich with handles
        $memberMap = [];
        foreach ($members as $m) $memberMap[$m['id'] ?? ''] = $m['anon_handle'] ?? 'Anonymous';
        $enriched = array_map(function($r) use ($memberMap) {
            $r['referrer_handle'] = $memberMap[$r['inviter_id'] ?? ''] ?? ($r['inviter_id'] === 'system' ? 'System' : 'Unknown');
            $r['new_member_handle'] = $memberMap[$r['invitee_id'] ?? ''] ?? 'Unknown';
            $r['referrer'] = $r['inviter_id'] ?? '';
            $r['new_member'] = $r['invitee_id'] ?? '';
            $r['code'] = $r['code_used'] ?? '';
            $r['points'] = 10;
            return $r;
        }, $ledger);
        mm_json(['ok' => true, 'referrals' => $enriched, 'count' => count($enriched)]);

    /* ── Activity Log ──────────────────── */
    case 'log_activity':
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $_SESSION['user_id'] ?? '';
        if (!$input || !$userId) mm_json(['ok' => false, 'error' => 'Invalid request'], 400);
        $log = mm_load_json('activity_log.json');
        $log[] = [
            'user_id' => $userId,
            'action' => $input['action'] ?? 'unknown',
            'detail' => $input['detail'] ?? '',
            'ticker' => $input['ticker'] ?? null,
            'result' => $input['result'] ?? null,    // win, loss, pending
            'timestamp' => date('c'),
        ];
        // Keep last 10000 entries
        if (count($log) > 10000) $log = array_slice($log, -10000);
        mm_save_json('activity_log.json', $log);
        mm_json(['ok' => true]);

    case 'get_activity':
        $log = mm_load_json('activity_log.json');
        $userId = $_GET['user_id'] ?? '';
        if ($userId) $log = array_values(array_filter($log, fn($e) => ($e['user_id'] ?? '') === $userId));
        // Return last 200
        mm_json(['ok' => true, 'activity' => array_slice($log, -200), 'total' => count($log)]);

    /* ── Leaderboard (anonymized) ──────── */
    case 'get_leaderboard':
        $members = mm_load_json('members.json');
        $config = mm_load_config();
        $board = [];
        foreach ($members as $m) {
            $isPaid = mm_is_paid_member($m);
            $badge = mm_get_badge_tier($m['referral_count'] ?? 0, $config, $isPaid);
            $board[] = [
                'anon_handle' => $m['anon_handle'] ?? 'Anonymous',
                'wins' => $m['wins'] ?? 0,
                'total_picks' => $m['total_picks'] ?? 0,
                'referral_count' => $m['referral_count'] ?? 0,
                'referral_points' => $m['referral_points'] ?? 0,
                'membership_type' => $m['membership_type'] ?? 'free',
                'badge_tier' => $badge['name'] ?? null,
                'badge_pending' => $badge['pending'] ?? false,  // earned tier but locked (free member)
                'joined' => $m['joined_at'] ?? '',
            ];
        }
        // Sort by referral_points desc, then wins
        usort($board, fn($a, $b) => ($b['referral_points'] ?? 0) - ($a['referral_points'] ?? 0) ?: ($b['wins'] ?? 0) - ($a['wins'] ?? 0));
        mm_json(['ok' => true, 'leaderboard' => $board]);

    /* ── My Membership (current user) ──── */
    case 'my_membership':
        $userId = $_SESSION['user_id'] ?? '';
        $userEmail = $_SESSION['user_email'] ?? '';
        $members = mm_load_json('members.json');
        foreach ($members as $m) {
            if ($m['email'] === $userEmail || $m['id'] === $userId) {
                mm_json(['ok' => true, 'membership' => $m]);
            }
        }
        mm_json(['ok' => true, 'membership' => null]);

    case 'my_invites':
        $userEmail = $_SESSION['user_email'] ?? '';
        $members = mm_load_json('members.json');
        $member = null;
        foreach ($members as $m) {
            if ($m['email'] === $userEmail) { $member = $m; break; }
        }
        if (!$member) mm_json(['ok' => true, 'invites' => [], 'member' => null]);

        $myCodes = $member['invite_codes'] ?? [];
        $allCodes = mm_load_json('invite_codes.json');
        $invites = [];
        foreach ($allCodes as $c) {
            if (in_array($c['code'], $myCodes)) {
                $invites[] = $c;
            }
        }
        mm_json(['ok' => true, 'invites' => $invites, 'member' => [
            'anon_handle' => $member['anon_handle'],
            'referral_count' => $member['referral_count'] ?? 0,
            'badge' => $member['badge'],
        ]]);

    default:
        mm_json(['ok' => false, 'error' => "Unknown action: {$action}"], 400);
}
