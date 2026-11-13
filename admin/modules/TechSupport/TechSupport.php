<?php
/**
 * Tech Support — Dashboard
 * Central ticket management interface.
 * Version: 2026.02.26.r5
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$_currentUser = guard_user();
$_userRole    = $_currentUser['role'] ?? 'user';

// Include Luminal admin header (opens HTML, loads admin CSS + menu + content-area)
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Tech Support Tickets</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/TechSupport/css/tech-support.css') ?>">

<div class="tsd-container">

  <!-- Header -->
  <div class="tsd-header">
    <div>
      <h1>Tech Support Tickets</h1>
      <p class="tsd-subtitle">Tickets from all CMS sites</p>
    </div>
    <div class="tsd-header-actions">
      <input type="text" class="tsd-search" id="tsdSearch" placeholder="Search tickets...">
      <select class="tsd-filter" id="tsdStatusFilter">
        <option value="active" selected>Active</option>
        <option value="">All Status</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="awaiting_feedback">Awaiting Feedback</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
        <option value="wont_fix">Won't Fix</option>
        <option value="archived">Archived</option>
      </select>
      <select class="tsd-filter" id="tsdUrgencyFilter">
        <option value="">All Urgency</option>
        <option value="critical">Critical</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
      </select>
      <select class="tsd-filter" id="tsdDomainFilter">
        <option value="">All Sites</option>
      </select>
      <select class="tsd-filter" id="tsdCategoryFilter">
        <option value="">All Categories</option>
      </select>
      <select class="tsd-filter" id="tsdAgentFilter">
        <option value="">All Tickets</option>
        <option value="exported">Exported to Agent</option>
        <option value="not_exported">Not Exported</option>
        <option value="orphaned">Orphaned / Broken</option>
      </select>
      <button class="tsd-btn tsd-btn-archive" id="tsdBulkArchive" type="button" style="display:none" title="Move all resolved tickets to archive">Archive All Resolved</button>
      <button class="tsd-btn tsd-btn-refresh" id="tsdRefresh" type="button">Refresh</button>
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="tsd-stats" id="tsdStats">
    <div class="tsd-stat-card" data-filter-status="open">
      <div class="tsd-stat-num" id="statOpen">-</div>
      <div class="tsd-stat-label">Open</div>
    </div>
    <div class="tsd-stat-card" data-filter-status="in_progress">
      <div class="tsd-stat-num tsd-stat-blue" id="statProgress">-</div>
      <div class="tsd-stat-label">In Progress</div>
    </div>
    <div class="tsd-stat-card" data-filter-status="awaiting_feedback">
      <div class="tsd-stat-num" id="statFeedback" style="color:#a78bfa">-</div>
      <div class="tsd-stat-label">Awaiting Feedback</div>
    </div>
    <div class="tsd-stat-card" data-filter-status="resolved">
      <div class="tsd-stat-num tsd-stat-green" id="statResolved">-</div>
      <div class="tsd-stat-label">Resolved</div>
    </div>
    <div class="tsd-stat-card" data-filter-status="closed">
      <div class="tsd-stat-num tsd-stat-yellow" id="statClosed">-</div>
      <div class="tsd-stat-label">Closed</div>
    </div>
    <div class="tsd-stat-card" data-filter-status="archived">
      <div class="tsd-stat-num" id="statArchived" style="color:#6b7280">-</div>
      <div class="tsd-stat-label">Archived</div>
    </div>
  </div>

  <!-- Ticket List -->
  <div class="tsd-list-panel">
    <table class="tsd-table">
      <thead>
        <tr>
          <th class="tsd-th-id">#</th>
          <th class="tsd-th-urg"></th>
          <th class="tsd-th-subject">Subject</th>
          <th class="tsd-th-site">Site</th>
          <th class="tsd-th-category">Category</th>
          <th class="tsd-th-owner">Owner</th>
          <th class="tsd-th-status">Stage</th>
          <th class="tsd-th-age">Age</th>
        </tr>
      </thead>
      <tbody id="tsdTicketBody">
        <tr><td colspan="8" class="tsd-empty">Loading tickets...</td></tr>
      </tbody>
    </table>
  </div>

</div>

<!-- Detail Modal — Conversation Layout -->
<div class="tsd-modal-overlay" id="tsdDetailOverlay">
  <div class="tsd-detail-modal tsd-detail-modal--conv" id="tsdDetailPanel">
    <div class="tsd-detail-body">
      <!-- All content built by JS via showDetail() render functions -->
      <div id="tsdDetailBody"></div>
    </div>
  </div>
</div>

<script src="<?= sc_asset('/admin/modules/TechSupport/js/tech-support.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
