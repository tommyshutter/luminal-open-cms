<?php
/**
 * MembershipManager — Admin Panel
 * Invite-only membership with referral tracking, leaderboards, activity logs
 * Version: 2.0.0 — Invite-Only Pivot
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$_currentUser = guard_user();
$_userRole = $_currentUser['role'] ?? 'user';

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Membership Manager</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/MembershipManager/css/membership-manager.css') ?>">

<div class="mm-container">

  <div class="mm-header">
    <div>
      <h1>Membership Manager</h1>
      <p class="mm-subtitle">Invite-Only Access &bull; Referral Tracking &bull; Leaderboards</p>
    </div>
    <div class="mm-header-actions">
      <button class="mm-btn mm-btn-primary" id="mmGenerateCodes" type="button">
        <i class="fa-solid fa-ticket"></i> Generate Invite Codes
      </button>
      <button class="mm-btn" id="mmShowSettings" type="button">
        <i class="fa-solid fa-gear"></i> Settings
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="mm-stats" id="mmStats">
    <div class="mm-stat-card">
      <div class="mm-stat-value" id="statMembers">-</div>
      <div class="mm-stat-label">Members</div>
    </div>
    <div class="mm-stat-card">
      <div class="mm-stat-value" id="statActive">-</div>
      <div class="mm-stat-label">Active</div>
    </div>
    <div class="mm-stat-card">
      <div class="mm-stat-value" id="statReferrals">-</div>
      <div class="mm-stat-label">Referrals</div>
    </div>
    <div class="mm-stat-card">
      <div class="mm-stat-value" id="statWins">-</div>
      <div class="mm-stat-label">Total Wins</div>
    </div>
    <div class="mm-stat-card">
      <div class="mm-stat-value" id="statCodes">-</div>
      <div class="mm-stat-label">Available Codes</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="mm-tabs">
    <button class="mm-tab active" data-tab="members">Members</button>
    <button class="mm-tab" data-tab="codes">Invite Codes</button>
    <button class="mm-tab" data-tab="referrals">Referral Ledger</button>
    <button class="mm-tab" data-tab="leaderboard">Leaderboard</button>
    <button class="mm-tab" data-tab="activity">Activity Log</button>
  </div>

  <div class="mm-tab-content">

    <!-- Members -->
    <div class="mm-panel" id="tabMembers">
      <div id="mmMembersBody">Loading...</div>
    </div>

    <!-- Invite Codes -->
    <div class="mm-panel" id="tabCodes" style="display:none">
      <div id="mmCodesBody">Loading...</div>
    </div>

    <!-- Referral Ledger -->
    <div class="mm-panel" id="tabReferrals" style="display:none">
      <div id="mmReferralsBody">Loading...</div>
    </div>

    <!-- Leaderboard -->
    <div class="mm-panel" id="tabLeaderboard" style="display:none">
      <div id="mmLeaderboardBody">Loading...</div>
    </div>

    <!-- Activity Log -->
    <div class="mm-panel" id="tabActivity" style="display:none">
      <div id="mmActivityBody">Loading...</div>
    </div>

  </div>

</div>

<!-- Settings Modal -->
<div class="mm-modal-overlay" id="mmSettingsOverlay" style="display:none">
  <div class="mm-modal">
    <div class="mm-modal-header">
      <h2>Membership Settings</h2>
      <button class="mm-modal-close" id="mmSettingsClose">&times;</button>
    </div>
    <div class="mm-modal-body" id="mmSettingsBody">Loading...</div>
    <div class="mm-modal-footer">
      <button class="mm-btn" id="mmSettingsCancel">Cancel</button>
      <button class="mm-btn mm-btn-primary" id="mmSettingsSave">Save Settings</button>
    </div>
  </div>
</div>

<script src="<?= sc_asset('/admin/modules/MembershipManager/js/membership-manager.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
