<?php
/**
 * Luminal CMS — Community Manager API
 *
 * Two access modes:
 *   Public  — no auth required: register, login, logout, get_session,
 *              get_comments, post_comment, get_member_profile
 *   Admin   — guard_require_auth(): list_members, update_member,
 *              list_comments, moderate_comment, get_config, save_config
 *
 * @package    LuminalCMS
 * @module     CommunityManager
 * @version    1.0.0
 * @file       /admin/modules/CommunityManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

header('Content-Type: application/json');

// ── Public actions (no admin auth) ───────────────────────────────────────────

const CM_PUBLIC_ACTIONS = [
    'register', 'login', 'logout', 'get_session',
    'get_comments', 'post_comment', 'get_member_profile',
];

// ── Parse request ─────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$data = [];
if (empty($action)) {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true) ?? [];
    $action = $data['action'] ?? null;
} else {
    $data = array_merge($_GET, $_POST);
    $json_input = file_get_contents('php://input');
    if ($json_input) {
        $parsed = json_decode($json_input, true);
        if (is_array($parsed)) $data = array_merge($data, $parsed);
    }
}

// ── Auth gate ────────────────────────────────────────────────────────────────

$is_public = in_array($action, CM_PUBLIC_ACTIONS, true);

if (!$is_public) {
    require_once SITE_ROOT . '/admin/auth.php';
    requireAuth();
}

// ── Data dir setup ────────────────────────────────────────────────────────────

$CM_DIR      = SITE_ROOT . '/admin/data/CommunityManager/';
$MEMBERS_FILE = $CM_DIR . 'members.json';
$COMMENTS_DIR = $CM_DIR . 'comments/';
$CONFIG_FILE  = $CM_DIR . 'config.json';

foreach ([$CM_DIR, $COMMENTS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
}

// ── Session start (for member sessions) ──────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Router ────────────────────────────────────────────────────────────────────

switch ($action) {
    // Public
    case 'register':          cm_register($data);           break;
    case 'login':             cm_login($data);              break;
    case 'logout':            cm_logout();                  break;
    case 'get_session':       cm_get_session();             break;
    case 'get_comments':      cm_get_comments($data);       break;
    case 'post_comment':      cm_post_comment($data);       break;
    case 'get_member_profile':cm_get_member_profile($data); break;
    // Admin
    case 'list_members':      cm_list_members();            break;
    case 'update_member':     cm_update_member($data);      break;
    case 'list_comments':     cm_admin_list_comments($data);break;
    case 'moderate_comment':  cm_moderate_comment($data);   break;
    case 'get_config':        cm_get_config();              break;
    case 'save_config':       cm_save_config($data);        break;
    default:
        echo json_encode(['error' => 'Invalid action.']);
        exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PUBLIC HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

function cm_register(array $data): void {
    $email        = trim(strtolower($data['email'] ?? ''));
    $display_name = trim($data['display_name'] ?? '');
    $password     = $data['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Invalid email address.']); exit;
    }
    if (strlen($display_name) < 2 || strlen($display_name) > 50) {
        echo json_encode(['error' => 'Display name must be 2–50 characters.']); exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['error' => 'Password must be at least 6 characters.']); exit;
    }

    // Check registration open
    $config = cm_load_config();
    if (empty($config['registration_open'])) {
        echo json_encode(['error' => 'Registration is currently closed.']); exit;
    }

    $members = cm_load_members();
    $id = md5($email);

    if (isset($members[$id])) {
        echo json_encode(['error' => 'An account with that email already exists.']); exit;
    }

    // Avatar color — pick from a palette
    $colors = ['#f59e0b','#3b82f6','#10b981','#8b5cf6','#ef4444','#06b6d4','#ec4899'];
    $avatar_color = $colors[crc32($email) % count($colors)];

    $member = [
        'id'           => $id,
        'email'        => $email,
        'display_name' => $display_name,
        'password'     => password_hash($password, PASSWORD_DEFAULT),
        'avatar_color' => $avatar_color,
        'bio'          => '',
        'tier'         => 'free',
        'tier_expires' => null,
        'joined_at'    => date('c'),
        'verified'     => !(bool)($config['require_email_verify'] ?? false),
        'banned'       => false,
        'post_count'   => 0,
    ];

    $members[$id] = $member;
    cm_save_members($members);

    // Auto-login
    $_SESSION['cm_member_id'] = $id;

    $public = cm_public_member($member);
    echo json_encode(['success' => true, 'member' => $public]);
}

function cm_login(array $data): void {
    $email    = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        echo json_encode(['error' => 'Email and password are required.']); exit;
    }

    $members = cm_load_members();
    $id = md5($email);

    if (!isset($members[$id])) {
        echo json_encode(['error' => 'Invalid email or password.']); exit;
    }
    $member = $members[$id];
    if (!password_verify($password, $member['password'] ?? '')) {
        echo json_encode(['error' => 'Invalid email or password.']); exit;
    }
    if (!empty($member['banned'])) {
        echo json_encode(['error' => 'This account has been suspended.']); exit;
    }

    $_SESSION['cm_member_id'] = $id;
    echo json_encode(['success' => true, 'member' => cm_public_member($member)]);
}

function cm_logout(): void {
    unset($_SESSION['cm_member_id']);
    echo json_encode(['success' => true]);
}

function cm_get_session(): void {
    $member = cm_get_member_session();
    if ($member === null) {
        echo json_encode(['success' => true, 'member' => null]);
    } else {
        echo json_encode(['success' => true, 'member' => cm_public_member($member)]);
    }
}

function cm_get_comments(array $data): void {
    global $COMMENTS_DIR;
    $thread_id = cm_sanitize_thread_id($data['thread_id'] ?? '');
    if ($thread_id === '') { echo json_encode(['error' => 'Missing thread_id.']); exit; }

    $file = $COMMENTS_DIR . $thread_id . '.json';
    if (!is_file($file)) {
        echo json_encode(['success' => true, 'thread_id' => $thread_id, 'comments' => []]);
        return;
    }

    $thread = json_decode(file_get_contents($file), true) ?? [];
    $comments = $thread['comments'] ?? [];

    // Filter hidden/banned — only show visible
    $comments = array_filter($comments, fn($c) => ($c['status'] ?? 'visible') !== 'hidden');
    $comments = array_values($comments);

    // Redact body for redacted comments
    foreach ($comments as &$c) {
        if (($c['status'] ?? '') === 'redacted') {
            $c['body'] = '[STATEMENT REDACTED]';
        }
        // Filter nested replies too
        if (!empty($c['replies'])) {
            $c['replies'] = array_values(array_filter($c['replies'], fn($r) => ($r['status'] ?? 'visible') !== 'hidden'));
            foreach ($c['replies'] as &$r) {
                if (($r['status'] ?? '') === 'redacted') $r['body'] = '[STATEMENT REDACTED]';
                // Level 3
                if (!empty($r['replies'])) {
                    $r['replies'] = array_values(array_filter($r['replies'], fn($rr) => ($rr['status'] ?? 'visible') !== 'hidden'));
                    foreach ($r['replies'] as &$rr) {
                        if (($rr['status'] ?? '') === 'redacted') $rr['body'] = '[STATEMENT REDACTED]';
                    }
                    unset($rr);
                }
            }
            unset($r);
        }
    }
    unset($c);

    echo json_encode(['success' => true, 'thread_id' => $thread_id, 'comments' => $comments]);
}

function cm_post_comment(array $data): void {
    global $COMMENTS_DIR;

    $member = cm_require_member_session();
    if ($member === null) {
        echo json_encode(['error' => 'You must be logged in to comment.', 'auth_required' => true]); exit;
    }
    if (!empty($member['banned'])) {
        echo json_encode(['error' => 'Your account has been suspended.']); exit;
    }

    $thread_id = cm_sanitize_thread_id($data['thread_id'] ?? '');
    $body      = trim($data['body'] ?? '');
    $parent_id = trim($data['parent_id'] ?? '');

    if ($thread_id === '') { echo json_encode(['error' => 'Missing thread_id.']); exit; }
    if ($body === '') { echo json_encode(['error' => 'Comment cannot be empty.']); exit; }
    if (strlen($body) > 2000) { echo json_encode(['error' => 'Comment too long (max 2000 characters).']); exit; }

    // ── Content filter: gibberish, links, images ──────────────────────────────
    $config  = cm_load_config();
    $filtered = cm_filter_body($body, $member, $config);
    if (isset($filtered['error'])) {
        echo json_encode(['error' => $filtered['error']]); exit;
    }
    $body = $filtered['body'];
    if ($body === '') {
        echo json_encode(['error' => 'Comment is empty after content filtering.']); exit;
    }

    $comment = [
        'id'           => 'c_' . bin2hex(random_bytes(8)),
        'member_id'    => $member['id'],
        'display_name' => $member['display_name'],
        'avatar_color' => $member['avatar_color'],
        'tier'         => $member['tier'] ?? 'free',
        'body'         => htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
        'parent_id'    => $parent_id ?: null,
        'posted_at'    => date('c'),
        'status'       => 'visible',
        'replies'      => [],
    ];

    $file = $COMMENTS_DIR . $thread_id . '.json';
    if (is_file($file)) {
        $thread = json_decode(file_get_contents($file), true) ?? [];
    } else {
        $thread = ['thread_id' => $thread_id, 'comments' => []];
    }

    if ($parent_id) {
        // Append as reply (max 3 levels)
        $appended = false;
        foreach ($thread['comments'] as &$top) {
            if ($top['id'] === $parent_id) {
                $top['replies'][] = $comment; $appended = true; break;
            }
            foreach ($top['replies'] ?? [] as &$lvl2) {
                if ($lvl2['id'] === $parent_id) {
                    $lvl2['replies'][] = $comment; $appended = true; break 2;
                }
            }
            unset($lvl2);
        }
        unset($top);
        if (!$appended) {
            // Parent not found — add as top-level
            $comment['parent_id'] = null;
            $thread['comments'][] = $comment;
        }
    } else {
        $thread['comments'][] = $comment;
    }

    file_put_contents($file, json_encode($thread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Increment post count
    $members = cm_load_members();
    if (isset($members[$member['id']])) {
        $members[$member['id']]['post_count'] = ($members[$member['id']]['post_count'] ?? 0) + 1;
        cm_save_members($members);
    }

    echo json_encode(['success' => true, 'comment' => $comment]);
}

function cm_get_member_profile(array $data): void {
    $member_id = preg_replace('/[^a-f0-9]/', '', $data['member_id'] ?? '');
    if ($member_id === '') { echo json_encode(['error' => 'Missing member_id.']); exit; }
    $members = cm_load_members();
    if (!isset($members[$member_id])) { echo json_encode(['error' => 'Member not found.']); exit; }
    echo json_encode(['success' => true, 'member' => cm_public_member($members[$member_id])]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

function cm_list_members(): void {
    $members = cm_load_members();
    $list = [];
    foreach ($members as $m) {
        $list[] = [
            'id'           => $m['id'],
            'email'        => $m['email'],
            'display_name' => $m['display_name'],
            'tier'         => $m['tier'] ?? 'free',
            'joined_at'    => $m['joined_at'] ?? '',
            'post_count'   => $m['post_count'] ?? 0,
            'banned'       => (bool)($m['banned'] ?? false),
            'verified'     => (bool)($m['verified'] ?? true),
        ];
    }
    // Sort newest first
    usort($list, fn($a, $b) => strcmp($b['joined_at'], $a['joined_at']));
    echo json_encode(['success' => true, 'members' => $list]);
}

function cm_update_member(array $data): void {
    $member_id = preg_replace('/[^a-f0-9]/', '', $data['member_id'] ?? '');
    if ($member_id === '') { echo json_encode(['error' => 'Missing member_id.']); exit; }

    $members = cm_load_members();
    if (!isset($members[$member_id])) { echo json_encode(['error' => 'Member not found.']); exit; }

    $m = &$members[$member_id];
    if (isset($data['tier']))         $m['tier']         = trim($data['tier']);
    if (isset($data['banned']))       $m['banned']        = (bool)$data['banned'];
    if (isset($data['approved']))     $m['approved']      = (bool)$data['approved'];  // bypass tier check for links/media
    if (isset($data['display_name'])) $m['display_name']  = trim($data['display_name']);
    if (isset($data['bio']))          $m['bio']           = trim($data['bio']);
    unset($m);

    cm_save_members($members);
    echo json_encode(['success' => true, 'message' => 'Member updated.']);
}

function cm_admin_list_comments(array $data): void {
    global $COMMENTS_DIR;
    $status_filter = $data['status'] ?? 'all';
    $files = glob($COMMENTS_DIR . '*.json') ?: [];
    $results = [];

    foreach ($files as $file) {
        $thread = json_decode(file_get_contents($file), true);
        if (!is_array($thread)) continue;
        $thread_id = $thread['thread_id'] ?? basename($file, '.json');

        foreach ($thread['comments'] ?? [] as $c) {
            cm_collect_flat($results, $c, $thread_id, $status_filter);
        }
    }

    // Sort newest first
    usort($results, fn($a, $b) => strcmp($b['posted_at'], $a['posted_at']));
    echo json_encode(['success' => true, 'comments' => array_slice($results, 0, 500)]);
}

function cm_collect_flat(array &$out, array $c, string $thread_id, string $filter): void {
    $status = $c['status'] ?? 'visible';
    if ($filter === 'all' || $filter === $status) {
        $out[] = [
            'id'           => $c['id'],
            'thread_id'    => $thread_id,
            'member_id'    => $c['member_id'],
            'display_name' => $c['display_name'],
            'body'         => substr($c['body'] ?? '', 0, 200),
            'status'       => $status,
            'posted_at'    => $c['posted_at'] ?? '',
            'parent_id'    => $c['parent_id'] ?? null,
        ];
    }
    foreach ($c['replies'] ?? [] as $r) {
        cm_collect_flat($out, $r, $thread_id, $filter);
    }
}

function cm_moderate_comment(array $data): void {
    global $COMMENTS_DIR;
    $comment_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['comment_id'] ?? '');
    $new_status = $data['status'] ?? '';
    $thread_id  = cm_sanitize_thread_id($data['thread_id'] ?? '');

    if (!in_array($new_status, ['visible', 'hidden', 'redacted'], true)) {
        echo json_encode(['error' => 'Invalid status. Use: visible, hidden, redacted.']); exit;
    }

    $file = $COMMENTS_DIR . $thread_id . '.json';
    if (!is_file($file)) { echo json_encode(['error' => 'Thread not found.']); exit; }

    $thread = json_decode(file_get_contents($file), true);
    $found = cm_set_comment_status($thread['comments'], $comment_id, $new_status, $new_status === 'redacted');
    if (!$found) { echo json_encode(['error' => 'Comment not found.']); exit; }

    file_put_contents($file, json_encode($thread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['success' => true, 'message' => 'Comment status updated.']);
}

function cm_set_comment_status(array &$comments, string $id, string $status, bool $redact): bool {
    foreach ($comments as &$c) {
        if ($c['id'] === $id) {
            $c['status'] = $status;
            if ($redact) $c['body'] = '[STATEMENT REDACTED]';
            return true;
        }
        if (!empty($c['replies']) && cm_set_comment_status($c['replies'], $id, $status, $redact)) return true;
    }
    unset($c);
    return false;
}

function cm_get_config(): void {
    echo json_encode(['success' => true, 'config' => cm_load_config()]);
}

function cm_save_config(array $data): void {
    global $CONFIG_FILE;
    $config = cm_load_config();

    if (isset($data['community_name']))    $config['community_name']    = trim($data['community_name']);
    if (isset($data['tiers_enabled']))     $config['tiers_enabled']     = (bool)$data['tiers_enabled'];
    if (isset($data['who_can_comment']))   $config['who_can_comment']   = $data['who_can_comment'];
    if (isset($data['registration_open'])) $config['registration_open'] = (bool)$data['registration_open'];
    if (isset($data['require_email_verify'])) $config['require_email_verify'] = (bool)$data['require_email_verify'];
    if (isset($data['tiers']) && is_array($data['tiers'])) $config['tiers'] = $data['tiers'];
    if (isset($data['content_rules']) && is_array($data['content_rules'])) {
        $cr = $data['content_rules'];
        if (isset($cr['allowed_link_domains']) && is_array($cr['allowed_link_domains'])) {
            $config['content_rules']['allowed_link_domains'] = array_values(array_filter(array_map('trim', $cr['allowed_link_domains'])));
        }
        if (isset($cr['free_tier_max_images'])) $config['content_rules']['free_tier_max_images'] = max(0, (int)$cr['free_tier_max_images']);
        if (isset($cr['approved_tier_min']))    $config['content_rules']['approved_tier_min']    = trim($cr['approved_tier_min']);
    }

    file_put_contents($CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['success' => true, 'message' => 'Config saved.']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function cm_load_members(): array {
    global $MEMBERS_FILE;
    if (!is_file($MEMBERS_FILE)) return [];
    $data = json_decode(file_get_contents($MEMBERS_FILE), true);
    return is_array($data) ? $data : [];
}

function cm_save_members(array $members): void {
    global $MEMBERS_FILE;
    file_put_contents($MEMBERS_FILE, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function cm_load_config(): array {
    global $CONFIG_FILE;
    $defaults = [
        'community_name'       => 'Community',
        'tiers_enabled'        => false,
        'tiers' => [
            ['id' => 'free',      'name' => 'Member',    'price_monthly' => 0,  'color' => '#6b7280'],
            ['id' => 'supporter', 'name' => 'Supporter', 'price_monthly' => 7,  'color' => '#f59e0b'],
            ['id' => 'vip',       'name' => 'VIP',       'price_monthly' => 35, 'color' => '#a855f7'],
        ],
        'who_can_comment'      => 'members',
        'registration_open'    => true,
        'require_email_verify' => false,
        'content_rules' => [
            'allowed_link_domains' => ['instagram.com', 'facebook.com', 'youtube.com', 'youtu.be'],
            'free_tier_max_images' => 1,
            'approved_tier_min'    => 'supporter',
        ],
    ];
    if (!is_file($CONFIG_FILE)) return $defaults;
    $data = json_decode(file_get_contents($CONFIG_FILE), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function cm_get_member_session(): ?array {
    $member_id = $_SESSION['cm_member_id'] ?? null;
    if (!$member_id) return null;
    $members = cm_load_members();
    return $members[$member_id] ?? null;
}

function cm_require_member_session(): ?array {
    return cm_get_member_session();
}

function cm_public_member(array $m): array {
    return [
        'id'           => $m['id'],
        'display_name' => $m['display_name'],
        'avatar_color' => $m['avatar_color'],
        'bio'          => $m['bio'] ?? '',
        'tier'         => $m['tier'] ?? 'free',
        'joined_at'    => $m['joined_at'] ?? '',
        'post_count'   => $m['post_count'] ?? 0,
        'verified'     => (bool)($m['verified'] ?? true),
    ];
}

function cm_sanitize_thread_id(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
}

/**
 * Content filter for comment bodies.
 * Returns ['body' => filtered_string] on success or ['error' => message] on rejection.
 *
 * Rules by tier:
 *   free (not approved) — strip ALL links silently; enforce max images
 *   approved / supporter+ — whitelist-only links; non-whitelisted stripped silently
 */
function cm_filter_body(string $body, array $member, array $config): array {
    $rules           = $config['content_rules'] ?? [];
    $allowed_domains = $rules['allowed_link_domains'] ?? ['instagram.com', 'facebook.com', 'youtube.com', 'youtu.be'];
    $free_max_images = max(0, (int)($rules['free_tier_max_images'] ?? 1));
    $approved_min    = $rules['approved_tier_min'] ?? 'supporter';

    // Tier rank lookup — extend if new tiers are added via config
    $tier_order = ['free' => 0, 'supporter' => 1, 'vip' => 2];
    foreach ($config['tiers'] ?? [] as $i => $t) {
        if (!isset($tier_order[$t['id'] ?? ''])) $tier_order[$t['id']] = $i + 1;
    }
    $member_rank = $tier_order[$member['tier'] ?? 'free'] ?? 0;
    $min_rank    = $tier_order[$approved_min] ?? 1;

    // "approved" = explicit admin flag OR paid tier at or above the configured minimum
    $is_approved = !empty($member['approved']) || $member_rank >= $min_rank;

    // ── Gibberish gate (runs regardless of tier) ─────────────────────────────
    $plain = strip_tags($body);
    if (cm_is_gibberish($plain)) {
        return ['error' => 'Your comment could not be posted. Please write a clear, readable message.'];
    }

    if ($is_approved) {
        // Strip URLs pointing to non-whitelisted domains (silently)
        $body = preg_replace_callback(
            '/https?:\/\/[^\s"\'<>)\]]+/i',
            function ($m) use ($allowed_domains) {
                $host = strtolower((string)(parse_url($m[0], PHP_URL_HOST) ?: ''));
                foreach ($allowed_domains as $d) {
                    $d = strtolower(trim($d));
                    if ($host === $d || str_ends_with($host, '.' . $d)) {
                        return $m[0]; // allowed
                    }
                }
                return ''; // strip
            },
            $body
        );
    } else {
        // Free tier: strip all bare URLs
        $body = preg_replace('/https?:\/\/\S+/i', '', $body);
        // Strip markdown links [text](url) → keep text only
        $body = preg_replace('/\[([^\]]*)\]\([^)]+\)/', '$1', $body);
        // Strip bare www. links
        $body = preg_replace('/\bwww\.[a-z0-9\-]+\.[a-z]{2,}[^\s]*/i', '', $body);

        // Enforce image limit (markdown + HTML img)
        if ($free_max_images === 0) {
            // Strip all images
            $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/i', '', $body);
            $body = preg_replace('/<img\s[^>]*>/i', '', $body);
        } else {
            $found = 0;
            $replacer = function ($m) use (&$found, $free_max_images) {
                $found++;
                return ($found <= $free_max_images) ? $m[0] : '';
            };
            $body = preg_replace_callback('/!\[[^\]]*\]\([^)]+\)/i', $replacer, $body);
            $body = preg_replace_callback('/<img\s[^>]*>/i', $replacer, $body);
        }
    }

    return ['body' => trim($body)];
}

/**
 * Lightweight gibberish detector.
 * Returns true if the text appears to be keyboard-mash or random characters.
 * Deliberately permissive to avoid false-positives on UFO/sci-fi terminology
 * (MJ-12, UAP, Roswell, etc.).
 */
function cm_is_gibberish(string $text): bool {
    $text = trim($text);
    if (mb_strlen($text) < 10) return false; // too short to judge

    // 1. Excessive same-character repeats (5+ in a row): aaaaa, !!!!!!
    if (preg_match('/(.)\1{4,}/u', $text)) return true;

    // 2. Long consonant cluster with no vowel (7+ consecutive consonants)
    //    Excludes digits and punctuation so "MJ-12" or "CBRN" don't trigger.
    if (preg_match('/[bcdfghjklmnpqrstvwxyz]{7,}/i', $text)) return true;

    // 3. Vowel-ratio check on pure letter content (≥20 letters required)
    $letters = preg_replace('/[^a-zA-Z]/u', '', $text);
    $len = strlen($letters);
    if ($len >= 20) {
        $vowels = preg_match_all('/[aeiou]/i', $letters);
        if (($vowels / $len) < 0.07) return true; // < 7% vowels = likely gibberish
    }

    return false;
}
