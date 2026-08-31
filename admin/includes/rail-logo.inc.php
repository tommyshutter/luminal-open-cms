<!-- /admin/includes/rail-logo.inc.php -->
<div class="admin-rail-logo" style="padding:12px 10px 0;text-align:center;">
  <svg viewBox="0 0 160 40" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:0 auto;max-width:90%;height:auto;">
    <defs>
      <linearGradient id="lux-grad" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%" style="stop-color:#a78bfa"/>
        <stop offset="100%" style="stop-color:#60a5fa"/>
      </linearGradient>
    </defs>
    <text x="80" y="28" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="26" font-weight="700" letter-spacing="6" fill="url(#lux-grad)">LUMINAL</text>
    <text x="80" y="38" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="8" font-weight="400" letter-spacing="3" fill="#64748b">CMS</text>
  </svg>
  <p style="margin:8px 0 0;padding:0 4px;font-size:8px;line-height:1.3;color:#475569;font-family:system-ui,-apple-system,sans-serif;">Luminal Open CMS &mdash; open source under the Apache License 2.0. See LICENSE and NOTICE.</p>
  <button class="admin-docs-btn" id="adminDocsBtn" title="Luminal Open CMS Documentation"
          onclick="window._luminalDocsModal && window._luminalDocsModal.open()">
    <i class="fa-solid fa-book-open"></i>
    <span>Documentation</span>
  </button>
  <style>
    .admin-docs-btn{display:flex;align-items:center;gap:6px;margin:10px auto 4px;padding:6px 14px;background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.2);border-radius:8px;color:#a78bfa;font-size:.75rem;font-family:system-ui,-apple-system,sans-serif;font-weight:500;cursor:pointer;transition:all .2s ease;white-space:nowrap}
    .admin-docs-btn:hover{background:rgba(167,139,250,.18);border-color:rgba(167,139,250,.35);color:#c4b5fd}
    .admin-docs-btn i{font-size:.7rem}
    .admin-aside.collapsed .admin-docs-btn span{display:none}
    .admin-aside.collapsed .admin-docs-btn{padding:6px 8px;justify-content:center}
  </style>
</div>
