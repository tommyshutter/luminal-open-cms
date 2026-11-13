<?php
/**
 * My Account — self-service profile + password panel.
 *
 * Shown to any authenticated user. Lower-tier users (anyone who is not a CMS
 * superadmin) are routed here when they click "Users" in the rail; superadmins
 * can also reach it to manage their own credentials. All writes go through
 * account_api.php, which is strictly scoped to the logged-in user.
 *
 * @module  UserManager
 * @version 1.1.0
 * @file    /admin/modules/UserManager/my-account.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/auth.php';

requireAuth();

$me   = getCurrentUser();
$role = getUserRole();

require_once SITE_ROOT . '/admin/admin_header.php';
?>
<h1 class="panel_header_h1" style="color:white">My Account</h1>

<style>
/* Transparent surfaces only — no solid backgrounds (BackgroundManager rules). */
.myacct-wrap { max-width: 640px; margin: 0 auto; padding: 8px 0 48px; }
.myacct-head { margin: 4px 0 20px; }
.myacct-head h1 { margin: 0 0 4px; font-size: 1.6rem; color: #fff; }
.myacct-head p  { margin: 0; color: rgba(255,255,255,.7); font-size: .9rem; }
.myacct-card {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 14px;
  padding: 22px 24px;
  margin-bottom: 20px;
  backdrop-filter: blur(8px);
}
.myacct-card h2 { margin: 0 0 4px; font-size: 1.1rem; color: #fff; }
.myacct-card .sub { margin: 0 0 16px; color: rgba(255,255,255,.6); font-size: .82rem; }
.myacct-fg { margin-bottom: 14px; }
.myacct-fg label { display:block; margin-bottom:6px; color: rgba(255,255,255,.85); font-size:.85rem; }
.myacct-fg input {
  width: 100%; box-sizing: border-box; padding: 10px 12px;
  background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.18);
  border-radius: 8px; color: #fff; font-size: .95rem;
}
.myacct-fg input:focus { outline: none; border-color: rgba(120,170,255,.8); }
.myacct-fg small { display:block; margin-top:6px; color: rgba(255,255,255,.5); font-size:.75rem; }
.myacct-meta { color: rgba(255,255,255,.6); font-size:.8rem; }
.myacct-meta .chip {
  display:inline-block; padding:2px 10px; border-radius:999px; margin-left:6px;
  background: rgba(120,170,255,.18); border:1px solid rgba(120,170,255,.35); color:#cfe0ff;
}
.myacct-btn {
  background: rgba(120,170,255,.22); border:1px solid rgba(120,170,255,.5);
  color:#eaf1ff; padding:10px 20px; border-radius:8px; font-size:.92rem; cursor:pointer;
}
.myacct-btn:hover { background: rgba(120,170,255,.34); }
.myacct-note { color: rgba(255,255,255,.5); font-size:.78rem; margin-top:10px; }
.myacct-note a { color:#cfe0ff; }
.myacct-toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
  background: rgba(20,24,38,.95); color:#fff; padding:12px 20px; border-radius:10px;
  border:1px solid rgba(255,255,255,.15); font-size:.9rem; opacity:0; pointer-events:none;
  transition: opacity .2s; z-index: 9999;
}
.myacct-toast.show { opacity:1; }
.myacct-toast.err { border-color: rgba(255,120,120,.6); }
.myacct-toast.ok  { border-color: rgba(120,220,160,.6); }
</style>

<div class="myacct-wrap">

  <div class="myacct-head">
    <h1>My Account</h1>
    <p>Manage your own login credentials. These changes only affect your account.</p>
  </div>

  <div class="myacct-card">
    <h2>Profile</h2>
    <p class="sub">Your display name and the email you sign in with.</p>
    <p class="myacct-meta">
      Signed in as <strong id="ma-role-name"><?= htmlspecialchars($me['display_name'] ?? 'You') ?></strong>
      <span class="chip">CMS: <?= htmlspecialchars($role) ?></span>
      <?php if (!empty($me['platform_role'])): ?>
      <span class="chip">Platform: <?= htmlspecialchars($me['platform_role']) ?></span>
      <?php endif; ?>
    </p>
    <form id="ma-profile-form" autocomplete="off" style="margin-top:16px">
      <div class="myacct-fg">
        <label for="ma-display">Display Name</label>
        <input type="text" id="ma-display" name="display_name" required>
      </div>
      <div class="myacct-fg">
        <label for="ma-email">Email Address</label>
        <input type="email" id="ma-email" name="email" required>
        <small>This is your sign-in identity and where password-reset links are sent.</small>
      </div>
      <div class="myacct-fg">
        <label for="ma-profile-current">Current Password</label>
        <input type="password" id="ma-profile-current" name="current_password" required
               autocomplete="current-password">
        <small>Required to confirm changes to your profile.</small>
      </div>
      <button type="submit" class="myacct-btn">Save Profile</button>
    </form>
  </div>

  <div class="myacct-card">
    <h2>Change Password</h2>
    <p class="sub">Choose a strong password. Min 8 characters with upper &amp; lower case, a number, and a special character.</p>
    <form id="ma-password-form" autocomplete="off">
      <div class="myacct-fg">
        <label for="ma-cur">Current Password</label>
        <input type="password" id="ma-cur" name="current_password" required autocomplete="current-password">
      </div>
      <div class="myacct-fg">
        <label for="ma-new">New Password</label>
        <input type="password" id="ma-new" name="new_password" required autocomplete="new-password">
      </div>
      <div class="myacct-fg">
        <label for="ma-confirm">Confirm New Password</label>
        <input type="password" id="ma-confirm" name="confirm_password" required autocomplete="new-password">
      </div>
      <button type="submit" class="myacct-btn">Update Password</button>
      <p class="myacct-note">Forgot your current password? Log out and use the
        “Forgot password?” link on the sign-in screen to reset it by email.</p>
    </form>
  </div>

</div>

<div class="myacct-toast" id="ma-toast"></div>

<script src="<?= sc_asset('/admin/modules/UserManager/js/my-account.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
