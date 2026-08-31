<?php
/**
 * Renderer: Community Login / Register
 * Shortcode: [[community-login]]
 *
 * Renders a tabbed login + register form for the community member system.
 * On success, reloads the page so server-rendered member state updates.
 *
 * Place on a /community/ page to serve as the member login hub.
 *
 * @package    LuminalCMS
 * @module     CommunityManager
 * @version    1.0.0
 */
declare(strict_types=1);

if (!function_exists('render_community_login')) {

    function render_community_login(array $attrs = []): string
    {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : (realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));

        // If already logged in, show member card instead
        $guard = $siteRoot . '/admin/modules/CommunityManager/community-guard.php';
        if (is_file($guard)) {
            require_once $guard;
            $member = cm_get_member();
            if ($member !== null) {
                return _cmlogin_member_card($member);
            }
        }

        // Config
        $config_file = $siteRoot . '/admin/data/CommunityManager/config.json';
        $config = [];
        if (is_file($config_file)) {
            $config = json_decode(file_get_contents($config_file), true) ?? [];
        }
        $reg_open       = (bool)($config['registration_open'] ?? true);
        $community_name = htmlspecialchars($config['community_name'] ?? 'Community');

        // Check for ?register=1 hint
        $default_tab = isset($_GET['register']) ? 'register' : 'login';

        $api_url = '/admin/modules/CommunityManager/api.php';

        $uid = 'cmlogin_' . substr(md5(uniqid()), 0, 6);

        $out = _cmlogin_styles();
        $out .= '<div class="cmlogin-wrap" id="' . $uid . '">';
        $out .= '<div class="cmlogin-card">';
        $out .= '<div class="cmlogin-heading">' . $community_name . '</div>';

        // Tabs
        $out .= '<div class="cmlogin-tabs">';
        $out .= '<button class="cmlogin-tab' . ($default_tab === 'login' ? ' cmlogin-tab--active' : '') . '" onclick="cmlSwitchTab(' . json_encode($uid) . ',\'login\',this)">Sign In</button>';
        if ($reg_open) {
            $out .= '<button class="cmlogin-tab' . ($default_tab === 'register' ? ' cmlogin-tab--active' : '') . '" onclick="cmlSwitchTab(' . json_encode($uid) . ',\'register\',this)">Create Account</button>';
        }
        $out .= '</div>';

        // Login form
        $out .= '<div class="cmlogin-panel" id="' . $uid . '-login"' . ($default_tab !== 'login' ? ' style="display:none"' : '') . '>';
        $out .= '<form onsubmit="cmlLogin(' . json_encode($uid) . ',' . json_encode($api_url) . ',event)">';
        $out .= '<div class="cmlogin-field">';
        $out .= '<label class="cmlogin-label">Email</label>';
        $out .= '<input type="email" id="' . $uid . '-login-email" class="cmlogin-input" placeholder="your@email.com" required autocomplete="email">';
        $out .= '</div>';
        $out .= '<div class="cmlogin-field">';
        $out .= '<label class="cmlogin-label">Password</label>';
        $out .= '<input type="password" id="' . $uid . '-login-pw" class="cmlogin-input" placeholder="Password" required autocomplete="current-password">';
        $out .= '</div>';
        $out .= '<div class="cmlogin-actions">';
        $out .= '<button type="submit" class="cmlogin-btn">Sign In</button>';
        $out .= '<span class="cmlogin-status" id="' . $uid . '-login-status"></span>';
        $out .= '</div>';
        $out .= '</form>';
        $out .= '</div>';

        // Register form
        if ($reg_open) {
            $out .= '<div class="cmlogin-panel" id="' . $uid . '-register"' . ($default_tab !== 'register' ? ' style="display:none"' : '') . '>';
            $out .= '<form onsubmit="cmlRegister(' . json_encode($uid) . ',' . json_encode($api_url) . ',event)">';
            $out .= '<div class="cmlogin-field">';
            $out .= '<label class="cmlogin-label">Display Name</label>';
            $out .= '<input type="text" id="' . $uid . '-reg-name" class="cmlogin-input" placeholder="How you\'ll appear in comments" required minlength="2" maxlength="50">';
            $out .= '</div>';
            $out .= '<div class="cmlogin-field">';
            $out .= '<label class="cmlogin-label">Email</label>';
            $out .= '<input type="email" id="' . $uid . '-reg-email" class="cmlogin-input" placeholder="your@email.com" required autocomplete="email">';
            $out .= '</div>';
            $out .= '<div class="cmlogin-field">';
            $out .= '<label class="cmlogin-label">Password</label>';
            $out .= '<input type="password" id="' . $uid . '-reg-pw" class="cmlogin-input" placeholder="Choose a password (6+ characters)" required minlength="6" autocomplete="new-password">';
            $out .= '</div>';
            $out .= '<div class="cmlogin-actions">';
            $out .= '<button type="submit" class="cmlogin-btn">Create Account</button>';
            $out .= '<span class="cmlogin-status" id="' . $uid . '-reg-status"></span>';
            $out .= '</div>';
            $out .= '</form>';
            $out .= '</div>';
        }

        $out .= '</div></div>';
        $out .= _cmlogin_scripts();
        return $out;
    }

    function _cmlogin_member_card(array $member): string {
        $name     = htmlspecialchars($member['display_name']);
        $color    = htmlspecialchars($member['avatar_color'] ?? '#f59e0b');
        $initial  = strtoupper(substr($member['display_name'], 0, 1));
        $tier     = htmlspecialchars($member['tier'] ?? 'free');
        $joined   = $member['joined_at'] ? 'Joined ' . date('F Y', strtotime($member['joined_at'])) : '';
        $posts    = ($member['post_count'] ?? 0) . ' post' . (($member['post_count'] ?? 0) !== 1 ? 's' : '');
        $api_url  = '/admin/modules/CommunityManager/api.php';

        $out = _cmlogin_styles();
        $out .= '<div class="cmlogin-wrap">';
        $out .= '<div class="cmlogin-card cmlogin-card--profile">';
        $out .= '<div class="cmlogin-avatar" style="background:' . $color . '">' . htmlspecialchars($initial) . '</div>';
        $out .= '<div class="cmlogin-profile-name">' . $name . '</div>';
        $out .= '<div class="cmlogin-profile-meta">';
        $out .= '<span class="cmlogin-tier-badge">' . ucfirst($tier) . '</span> ';
        if ($joined) $out .= '<span class="cmlogin-muted">' . $joined . '</span> ';
        $out .= '<span class="cmlogin-muted">· ' . $posts . '</span>';
        $out .= '</div>';
        $out .= '<button class="cmlogin-btn cmlogin-btn--ghost" onclick="cmlLogout(' . json_encode($api_url) . ')">Sign Out</button>';
        $out .= '</div></div>';
        return $out;
    }

    function _cmlogin_styles(): string {
        static $done = false;
        if ($done) return '';
        $done = true;
        return '<style>
.cmlogin-wrap { display: flex; justify-content: center; padding: 20px 16px; }
.cmlogin-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 32px; width: 100%; max-width: 420px; }
.cmlogin-card--profile { text-align: center; }
.cmlogin-heading { font-size: 1.4rem; font-weight: 700; color: #fff; text-align: center; margin-bottom: 24px; }
/* Tabs */
.cmlogin-tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.cmlogin-tab { flex: 1; padding: 9px 16px; background: transparent; border: none; border-bottom: 2px solid transparent; color: #888; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: color 0.15s, border-color 0.15s; margin-bottom: -1px; }
.cmlogin-tab:hover { color: #ccc; }
.cmlogin-tab--active { color: #f59e0b; border-bottom-color: #f59e0b; }
/* Fields */
.cmlogin-field { margin-bottom: 16px; }
.cmlogin-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #888; margin-bottom: 6px; }
.cmlogin-input { width: 100%; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; color: #e0e0e0; font-size: 0.9rem; padding: 10px 14px; font-family: inherit; box-sizing: border-box; outline: none; transition: border-color 0.15s; }
.cmlogin-input:focus { border-color: rgba(245,158,11,0.45); }
/* Actions */
.cmlogin-actions { display: flex; align-items: center; gap: 12px; margin-top: 8px; }
/* Buttons */
.cmlogin-btn { padding: 10px 24px; border-radius: 7px; border: 1px solid #f59e0b; background: #f59e0b; color: #111; font-size: 0.88rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: background 0.15s; }
.cmlogin-btn:hover { background: #fbbf24; }
.cmlogin-btn--ghost { background: transparent; color: #aaa; border-color: rgba(255,255,255,0.2); margin-top: 12px; }
.cmlogin-btn--ghost:hover { background: rgba(255,255,255,0.06); color: #fff; }
/* Status */
.cmlogin-status { font-size: 0.8rem; color: #888; }
.cmlogin-status--ok  { color: #4ade80; }
.cmlogin-status--err { color: #f87171; }
/* Profile card */
.cmlogin-avatar { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 700; color: #111; margin: 0 auto 14px; }
.cmlogin-profile-name { font-size: 1.2rem; font-weight: 700; color: #fff; margin-bottom: 8px; }
.cmlogin-profile-meta { font-size: 0.82rem; margin-bottom: 20px; }
.cmlogin-tier-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.25); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
.cmlogin-muted { color: #777; }
</style>';
    }

    function _cmlogin_scripts(): string {
        static $done = false;
        if ($done) return '';
        $done = true;
        return '<script>
window.cmlSwitchTab = function(uid, tab, btn) {
    document.querySelectorAll("#" + uid + " .cmlogin-panel").forEach(function(p){ p.style.display = "none"; });
    document.querySelectorAll("#" + uid + " .cmlogin-tab").forEach(function(t){ t.classList.remove("cmlogin-tab--active"); });
    document.getElementById(uid + "-" + tab).style.display = "block";
    btn.classList.add("cmlogin-tab--active");
};

window.cmlLogin = function(uid, api, e) {
    e.preventDefault();
    var status = document.getElementById(uid + "-login-status");
    var email = document.getElementById(uid + "-login-email").value.trim();
    var pw    = document.getElementById(uid + "-login-pw").value;
    status.textContent = "Signing in…";
    status.className = "cmlogin-status";
    fetch(api, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        credentials: "same-origin",
        body: JSON.stringify({action:"login", email:email, password:pw})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) {
            status.textContent = d.error || "Login failed.";
            status.className = "cmlogin-status cmlogin-status--err";
        } else {
            status.textContent = "Welcome back!";
            status.className = "cmlogin-status cmlogin-status--ok";
            setTimeout(function(){ window.location.reload(); }, 600);
        }
    })
    .catch(function(err){
        status.textContent = "Error: " + err.message;
        status.className = "cmlogin-status cmlogin-status--err";
    });
};

window.cmlRegister = function(uid, api, e) {
    e.preventDefault();
    var status = document.getElementById(uid + "-reg-status");
    var name  = document.getElementById(uid + "-reg-name").value.trim();
    var email = document.getElementById(uid + "-reg-email").value.trim();
    var pw    = document.getElementById(uid + "-reg-pw").value;
    status.textContent = "Creating account…";
    status.className = "cmlogin-status";
    fetch(api, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        credentials: "same-origin",
        body: JSON.stringify({action:"register", display_name:name, email:email, password:pw})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) {
            status.textContent = d.error || "Registration failed.";
            status.className = "cmlogin-status cmlogin-status--err";
        } else {
            status.textContent = "Account created!";
            status.className = "cmlogin-status cmlogin-status--ok";
            setTimeout(function(){ window.location.reload(); }, 700);
        }
    })
    .catch(function(err){
        status.textContent = "Error: " + err.message;
        status.className = "cmlogin-status cmlogin-status--err";
    });
};

window.cmlLogout = function(api) {
    fetch(api, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        credentials: "same-origin",
        body: JSON.stringify({action:"logout"})
    })
    .then(function(){ window.location.reload(); });
};
</script>';
    }
}
