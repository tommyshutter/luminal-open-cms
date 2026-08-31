<?php
/**
 * Renderer: Community Comments Thread
 * Shortcode: [[community-comments thread="{thread_id}"]]
 *
 * Renders threaded comments for any content type (episode, article, page).
 * Server-renders the initial comment list. JS handles reply forms + async post.
 *
 * Thread IDs by convention:
 *   episode_S01E01, article_my-slug, page_home
 *
 * @package    LuminalCMS
 * @module     CommunityManager
 * @version    1.0.0
 */
declare(strict_types=1);

if (!function_exists('render_community_comments')) {

    function render_community_comments(array $attrs = []): string
    {
        $siteRoot  = defined('SITE_ROOT') ? SITE_ROOT : (realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
        $thread_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $attrs['thread'] ?? '');
        if ($thread_id === '') return '<!-- community-comments: missing thread attr -->';

        // Load community guard helpers
        $guard = $siteRoot . '/admin/modules/CommunityManager/community-guard.php';
        if (is_file($guard)) {
            require_once $guard;
        } else {
            return '<!-- community-comments: CommunityManager not installed -->';
        }

        // Load config
        $config_file = $siteRoot . '/admin/data/CommunityManager/config.json';
        $config = [];
        if (is_file($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?? [];
        }
        $tiers_enabled = !empty($config['tiers_enabled']);
        $tier_map = [];
        foreach ($config['tiers'] ?? [] as $t) {
            $tier_map[$t['id']] = $t;
        }

        // Current member
        $member = cm_get_member();

        // Load thread
        $comments_dir = $siteRoot . '/admin/data/CommunityManager/comments/';
        $thread_file  = $comments_dir . $thread_id . '.json';
        $comments = [];
        if (is_file($thread_file)) {
            $thread_data = json_decode(file_get_contents($thread_file), true);
            $comments = $thread_data['comments'] ?? [];
        }

        // Filter and count visible
        $visible_count = _cm_count_visible($comments);

        $api_url = '/admin/modules/CommunityManager/api.php';

        $out = _cm_comments_styles();
        $out .= '<div class="cmc-wrap" id="cmc-wrap-' . htmlspecialchars($thread_id) . '" data-thread="' . htmlspecialchars($thread_id) . '">';

        // Header
        $out .= '<div class="cmc-header">';
        $out .= '<h3 class="cmc-title">' . $visible_count . ' Comment' . ($visible_count !== 1 ? 's' : '') . '</h3>';
        $out .= '</div>';

        // Post form (if logged in)
        if ($member !== null) {
            $initials = strtoupper(substr($member['display_name'], 0, 1));
            $color    = htmlspecialchars($member['avatar_color'] ?? '#f59e0b');
            $out .= '<div class="cmc-post-form">';
            $out .= '<div class="cmc-avatar cmc-avatar--sm" style="background:' . $color . '">' . htmlspecialchars($initials) . '</div>';
            $out .= '<div class="cmc-post-area">';
            $out .= '<textarea class="cmc-textarea" id="cmc-body-' . htmlspecialchars($thread_id) . '" placeholder="Write a comment…" rows="3"></textarea>';
            $out .= '<div class="cmc-post-actions">';
            $out .= '<button class="cmc-btn" onclick="cmcPost(' . json_encode($thread_id) . ',null)">Post Comment</button>';
            $out .= '<span class="cmc-post-status" id="cmc-status-' . htmlspecialchars($thread_id) . '"></span>';
            $out .= '</div>';
            $out .= '</div>';
            $out .= '</div>';
        } else {
            $out .= '<div class="cmc-login-prompt">';
            $out .= 'Join the discussion — <a class="cmc-link" href="/community/">Sign In</a> or <a class="cmc-link" href="/community/?register=1">Register</a>';
            $out .= '</div>';
        }

        // Comment tree
        if (empty($comments)) {
            $out .= '<div class="cmc-empty">Be the first to comment.</div>';
        } else {
            $out .= '<div class="cmc-thread" id="cmc-thread-' . htmlspecialchars($thread_id) . '">';
            foreach ($comments as $c) {
                $out .= _cm_render_comment($c, $thread_id, 0, $member, $tiers_enabled, $tier_map);
            }
            $out .= '</div>';
        }

        $out .= '</div>';
        $out .= _cm_comments_scripts($api_url);
        return $out;
    }

    function _cm_count_visible(array $comments): int {
        $n = 0;
        foreach ($comments as $c) {
            $status = $c['status'] ?? 'visible';
            if ($status !== 'hidden') {
                $n++;
                $n += _cm_count_visible($c['replies'] ?? []);
            }
        }
        return $n;
    }

    function _cm_render_comment(array $c, string $thread_id, int $depth, ?array $member, bool $tiers_enabled, array $tier_map): string {
        $status = $c['status'] ?? 'visible';
        if ($status === 'hidden') return '';

        $id       = htmlspecialchars($c['id'] ?? '');
        $name     = htmlspecialchars($c['display_name'] ?? 'Member');
        $color    = htmlspecialchars($c['avatar_color'] ?? '#f59e0b');
        $initial  = strtoupper(substr($c['display_name'] ?? 'M', 0, 1));
        $body     = $status === 'redacted' ? '<em class="cmc-redacted">[STATEMENT REDACTED]</em>' : nl2br(htmlspecialchars($c['body'] ?? ''));
        $tier     = $c['tier'] ?? 'free';
        $posted   = _cm_time_ago($c['posted_at'] ?? '');
        $can_reply = ($depth < 2) && ($member !== null);

        $out = '<div class="cmc-comment" data-id="' . $id . '" style="margin-left:' . ($depth * 28) . 'px">';

        // Avatar
        $out .= '<div class="cmc-comment-avatar">';
        $out .= '<div class="cmc-avatar" style="background:' . $color . '">' . htmlspecialchars($initial) . '</div>';
        $out .= '</div>';

        $out .= '<div class="cmc-comment-body">';
        $out .= '<div class="cmc-comment-meta">';
        $out .= '<span class="cmc-name">' . $name . '</span>';
        if ($tiers_enabled && isset($tier_map[$tier]) && $tier !== 'free') {
            $tc = htmlspecialchars($tier_map[$tier]['color'] ?? '#f59e0b');
            $tn = htmlspecialchars($tier_map[$tier]['name'] ?? $tier);
            $out .= '<span class="cmc-tier-badge" style="background:' . $tc . '22;color:' . $tc . ';border-color:' . $tc . '44">' . $tn . '</span>';
        }
        $out .= '<span class="cmc-time">' . $posted . '</span>';
        $out .= '</div>';

        $out .= '<div class="cmc-text">' . $body . '</div>';

        if ($can_reply) {
            $out .= '<button class="cmc-reply-btn" onclick="cmcToggleReply(' . json_encode($id) . ',' . json_encode($thread_id) . ',this)">Reply</button>';
            $out .= '<div class="cmc-reply-form" id="cmc-reply-' . $id . '" style="display:none">';
            $out .= '<textarea class="cmc-textarea cmc-textarea--sm" id="cmc-reply-body-' . $id . '" placeholder="Write a reply…" rows="2"></textarea>';
            $out .= '<div class="cmc-post-actions">';
            $out .= '<button class="cmc-btn cmc-btn--sm" onclick="cmcPost(' . json_encode($thread_id) . ',' . json_encode($id) . ')">Post Reply</button>';
            $out .= '<button class="cmc-btn cmc-btn--ghost cmc-btn--sm" onclick="cmcToggleReply(' . json_encode($id) . ',' . json_encode($thread_id) . ',this)">Cancel</button>';
            $out .= '<span class="cmc-post-status" id="cmc-reply-status-' . $id . '"></span>';
            $out .= '</div>';
            $out .= '</div>';
        }

        $out .= '</div>'; // /comment-body

        $out .= '</div>'; // /comment

        // Replies
        foreach ($c['replies'] ?? [] as $reply) {
            $out .= _cm_render_comment($reply, $thread_id, $depth + 1, $member, $tiers_enabled, $tier_map);
        }

        return $out;
    }

    function _cm_time_ago(string $iso): string {
        if ($iso === '') return '';
        $ts   = strtotime($iso);
        $diff = time() - $ts;
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return floor($diff / 60) . 'm ago';
        if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', $ts);
    }

    function _cm_comments_styles(): string {
        static $done = false;
        if ($done) return '';
        $done = true;
        return '<style>
.cmc-wrap { max-width: 800px; margin: 48px auto 0; padding: 0 16px; }
.cmc-header { margin-bottom: 20px; }
.cmc-title { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; margin: 0; }
/* Post form */
.cmc-post-form { display: flex; gap: 12px; margin-bottom: 32px; align-items: flex-start; }
.cmc-post-area { flex: 1; }
.cmc-login-prompt { padding: 14px 18px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; font-size: 0.88rem; color: #888; margin-bottom: 28px; }
.cmc-link { color: #f59e0b; text-decoration: none; }
.cmc-link:hover { color: #fbbf24; }
/* Textarea */
.cmc-textarea { width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; color: #e0e0e0; font-size: 0.88rem; padding: 10px 14px; font-family: inherit; box-sizing: border-box; resize: vertical; outline: none; transition: border-color 0.15s; }
.cmc-textarea:focus { border-color: rgba(245,158,11,0.4); }
.cmc-textarea--sm { rows: 2; font-size: 0.83rem; }
/* Post actions */
.cmc-post-actions { display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
/* Buttons */
.cmc-btn { display: inline-flex; align-items: center; padding: 7px 16px; border-radius: 6px; border: 1px solid #f59e0b; background: #f59e0b; color: #111; font-size: 0.8rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: background 0.15s; }
.cmc-btn:hover { background: #fbbf24; }
.cmc-btn--ghost { background: transparent; color: #888; border-color: rgba(255,255,255,0.15); }
.cmc-btn--ghost:hover { background: rgba(255,255,255,0.06); color: #ccc; }
.cmc-btn--sm { padding: 5px 12px; font-size: 0.75rem; }
/* Post status */
.cmc-post-status { font-size: 0.78rem; color: #888; }
.cmc-post-status--ok { color: #4ade80; }
.cmc-post-status--err { color: #f87171; }
/* Comment thread */
.cmc-thread { display: flex; flex-direction: column; gap: 0; }
.cmc-comment { display: flex; gap: 12px; padding: 16px 0; border-top: 1px solid rgba(255,255,255,0.06); }
.cmc-comment-avatar { flex-shrink: 0; }
.cmc-comment-body { flex: 1; min-width: 0; }
/* Avatar */
.cmc-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.88rem; font-weight: 700; color: #111; flex-shrink: 0; }
.cmc-avatar--sm { width: 34px; height: 34px; font-size: 0.8rem; }
/* Comment meta */
.cmc-comment-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
.cmc-name { font-size: 0.88rem; font-weight: 700; color: #e0e0e0; }
.cmc-time { font-size: 0.75rem; color: #666; }
.cmc-tier-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid transparent; }
/* Comment text */
.cmc-text { font-size: 0.88rem; line-height: 1.6; color: #c0c0c0; margin-bottom: 8px; word-break: break-word; }
.cmc-redacted { color: #666; font-style: italic; }
/* Reply */
.cmc-reply-btn { background: none; border: none; color: #888; font-size: 0.75rem; cursor: pointer; font-family: inherit; padding: 0; margin-bottom: 10px; transition: color 0.15s; }
.cmc-reply-btn:hover { color: #f59e0b; }
.cmc-reply-form { margin-top: 8px; }
/* Empty */
.cmc-empty { padding: 28px; text-align: center; color: #666; font-size: 0.88rem; }
</style>';
    }

    function _cm_comments_scripts(string $api_url): string {
        static $done = false;
        if ($done) return '';
        $done = true;
        return '<script>
(function(){
var CMC_API = ' . json_encode($api_url) . ';

window.cmcPost = function(thread_id, parent_id) {
    var body_id = parent_id ? "cmc-reply-body-" + parent_id : "cmc-body-" + thread_id;
    var status_id = parent_id ? "cmc-reply-status-" + parent_id : "cmc-status-" + thread_id;
    var textarea = document.getElementById(body_id);
    var statusEl = document.getElementById(status_id);
    if (!textarea) return;
    var body = textarea.value.trim();
    if (!body) { statusEl.textContent = "Cannot be empty."; return; }
    statusEl.textContent = "Posting…";
    statusEl.className = "cmc-post-status";
    fetch(CMC_API, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({action:"post_comment", thread_id:thread_id, body:body, parent_id:parent_id||null}),
        credentials: "same-origin"
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) {
            if (d.auth_required) {
                statusEl.textContent = "Please sign in to comment.";
                statusEl.className = "cmc-post-status cmc-post-status--err";
            } else {
                statusEl.textContent = d.error || "Error.";
                statusEl.className = "cmc-post-status cmc-post-status--err";
            }
            return;
        }
        textarea.value = "";
        statusEl.textContent = "Posted!";
        statusEl.className = "cmc-post-status cmc-post-status--ok";
        // Reload page to show new comment
        setTimeout(function(){ window.location.reload(); }, 600);
    })
    .catch(function(e){
        statusEl.textContent = "Error: " + e.message;
        statusEl.className = "cmc-post-status cmc-post-status--err";
    });
};

window.cmcToggleReply = function(comment_id, thread_id, btn) {
    var form = document.getElementById("cmc-reply-" + comment_id);
    if (!form) return;
    var visible = form.style.display !== "none";
    form.style.display = visible ? "none" : "block";
    if (!visible) {
        var ta = document.getElementById("cmc-reply-body-" + comment_id);
        if (ta) ta.focus();
    }
};
})();
</script>';
    }
}
