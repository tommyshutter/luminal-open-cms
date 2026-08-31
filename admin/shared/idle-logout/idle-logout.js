/**
 * Luminal CMS — Admin idle auto-logout ("Are you still here?").
 *
 * Banking-style inactivity guard for the admin shell: after a period of no
 * activity a centred modal appears with a live countdown; if the user does
 * nothing it bounces them to a full server-side logout (session destroyed) so
 * the screen they were on is never left sitting stale. Any sign of life —
 * moving the mouse, a keystroke, or the "I'm still here" button — cancels it.
 *
 * Activity is synced across tabs via localStorage, so working in one admin tab
 * keeps the others alive too.
 *
 * Tunable via window.LUM_IDLE (set before this script loads):
 *   { idleMs, warnMs, logoutUrl, keepaliveUrl }
 * Defaults: 15-min idle, 60-s countdown, /admin/logout.php.
 *
 * @module  shared/idle-logout
 * @version 1.0.0
 * @file    /admin/shared/idle-logout/idle-logout.js
 */
(function () {
  'use strict';
  if (window.__lumIdle) return;            // singleton
  window.__lumIdle = true;
  if (window.top !== window) return;       // run only on the top admin shell, not inside iframes

  var cfg = window.LUM_IDLE || {};
  var IDLE_MS   = cfg.idleMs   || 15 * 60 * 1000;   // inactivity before the warning
  var WARN_MS   = cfg.warnMs   || 60 * 1000;        // countdown length
  var LOGOUT    = cfg.logoutUrl   || '/admin/logout.php';
  var KEEPALIVE = cfg.keepaliveUrl || '';           // optional GET to refresh the server session
  var LS_KEY    = 'lum_idle_activity';

  /* Publish the RESOLVED values so UI elsewhere can state the truth without re-deriving
   * them. The Dashboard session strip needs this: it counts down the TOTAL session (hours)
   * while THIS timer is what actually ends the session (minutes idle), so without a shared
   * source the strip either lies or hardcodes a duplicate 15 that silently drifts from here.
   * Read-only reporting — nothing consumes it for control flow. (2026-07-19) */
  window.LUMIdleInfo = { idleMs: IDLE_MS, warnMs: WARN_MS, logoutUrl: LOGOUT };

  var last = Date.now();
  var warning = false;
  var warnEndsAt = 0;
  var tick = null, countdownTick = null;
  var overlay = null, numEl = null;

  /* ---- activity tracking ------------------------------------------------ */
  function markActivity(broadcast) {
    last = Date.now();
    if (broadcast !== false) {
      try { localStorage.setItem(LS_KEY, String(last)); } catch (e) {}
    }
    if (warning) cancelWarning();
  }

  function onUserEvent() { markActivity(true); }

  // Cross-tab: activity in another tab refreshes this one.
  window.addEventListener('storage', function (e) {
    if (e.key === LS_KEY && e.newValue) {
      var t = parseInt(e.newValue, 10);
      if (t && t > last) { last = t; if (warning) cancelWarning(); }
    }
  });

  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(function (ev) {
    window.addEventListener(ev, onUserEvent, { passive: true });
  });

  /* ---- the warning modal ------------------------------------------------ */
  function buildOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'lum-idle-overlay';
    overlay.innerHTML =
      '<div class="lum-idle-card" role="alertdialog" aria-live="assertive" aria-label="Session expiring">' +
        '<div class="lum-idle-title">Are you still here?</div>' +
        '<p class="lum-idle-msg">You\'ve been inactive for a while. For your security you\'ll be signed out in</p>' +
        '<div class="lum-idle-count"><span id="lum-idle-num">60</span><small>seconds</small></div>' +
        '<div class="lum-idle-actions">' +
          '<button type="button" class="lum-idle-btn primary" id="lum-idle-stay">I\'m still here</button>' +
          '<button type="button" class="lum-idle-btn" id="lum-idle-out">Log out now</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    numEl = overlay.querySelector('#lum-idle-num');
    overlay.querySelector('#lum-idle-stay').addEventListener('click', function (e) {
      e.stopPropagation();
      markActivity(true);
      if (KEEPALIVE) { try { fetch(KEEPALIVE, { credentials: 'same-origin', cache: 'no-store' }); } catch (x) {} }
    });
    overlay.querySelector('#lum-idle-out').addEventListener('click', function (e) {
      e.stopPropagation();
      doLogout();
    });
    return overlay;
  }

  function showWarning() {
    if (warning) return;
    warning = true;
    warnEndsAt = Date.now() + WARN_MS;
    buildOverlay();
    overlay.classList.add('show');
    renderCountdown();
    countdownTick = setInterval(renderCountdown, 250);
  }

  function renderCountdown() {
    var left = Math.max(0, warnEndsAt - Date.now());
    if (numEl) numEl.textContent = String(Math.ceil(left / 1000));
    if (left <= 0) doLogout();
  }

  function cancelWarning() {
    warning = false;
    if (countdownTick) { clearInterval(countdownTick); countdownTick = null; }
    if (overlay) overlay.classList.remove('show');
  }

  var loggingOut = false;
  function doLogout() {
    if (loggingOut) return;
    loggingOut = true;
    if (countdownTick) clearInterval(countdownTick);
    if (tick) clearInterval(tick);
    window.location.href = LOGOUT;
  }

  /* ---- main heartbeat --------------------------------------------------- */
  function heartbeat() {
    if (loggingOut) return;
    var idle = Date.now() - last;
    if (!warning && idle >= IDLE_MS) showWarning();
  }

  // Note: clicks on the modal buttons must NOT count as "activity" for the
  // generic listeners — they stopPropagation and drive the flow explicitly.
  markActivity(true);
  tick = setInterval(heartbeat, 1000);
})();
