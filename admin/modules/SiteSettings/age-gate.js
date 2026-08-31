/**
 * AudienceBuilder — Site-wide Age Gate
 *
 * Blocking overlay shown on page load until the visitor confirms they meet the
 * age threshold. Self-attestation (standard for hemp/cannabis sites). Remembers
 * the confirmation in a cookie so it doesn't nag every page. On deny: redirect
 * to a configured URL, or show a block message in place.
 *
 * Config is injected by footer.php as window.AB_AGE_GATE:
 *   { title, prompt, confirm_text, deny_text, threshold,
 *     remember_days, deny_url, deny_message }
 */
(function() {
'use strict';

var cfg = window.AB_AGE_GATE;
if (!cfg) return;

var COOKIE = 'ab_age_ok';

function hasCookie(name) {
    return document.cookie.split('; ').some(function(c) { return c.indexOf(name + '=1') === 0; });
}
function setCookie(name, days) {
    var d = new Date();
    d.setTime(d.getTime() + (days * 864e5));
    document.cookie = name + '=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
}
function clearCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
}

// Idle re-challenge (opt-in via cfg.idle_minutes > 0). Once the visitor has
// passed the gate, watch for activity; after N idle minutes, forget the
// acceptance and re-show the gate — so a shared device re-locks when someone
// walks away. Armed only while the gate is NOT on screen (after confirm or when
// the remember-cookie already cleared us).
var idleArmed = false, idleTimer = null;
function armIdle() {
    var mins = parseInt(cfg.idle_minutes, 10) || 0;
    if (!mins || idleArmed) return;
    idleArmed = true;
    var ms = mins * 60000;
    var EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
    function reset() { if (idleTimer) clearTimeout(idleTimer); idleTimer = setTimeout(trip, ms); }
    function stop() { EVENTS.forEach(function (e) { window.removeEventListener(e, reset, true); }); if (idleTimer) clearTimeout(idleTimer); idleArmed = false; }
    function trip() {
        stop();
        clearCookie(COOKIE);                                      // forget the acceptance
        logEvent('idle_relock');
        if (!document.querySelector('.ab-age-gate')) show();      // re-challenge
    }
    EVENTS.forEach(function (e) { window.addEventListener(e, reset, true); });
    reset();
}

// Safety-audit trail (opt-in via cfg.audit). Fire-and-forget beacon on each gate
// decision; the server stamps time/IP/UA. Uses sendBeacon so it survives the
// deny-redirect / page unload.
function logEvent(ev) {
    if (!cfg.audit) return;
    try {
        var fd = new FormData();
        fd.append('event', ev);
        fd.append('threshold', cfg.threshold || 21);
        fd.append('page', location.pathname + location.search);
        fd.append('ref', document.referrer || '');
        var url = '/admin/modules/SiteSettings/age-gate-log.php';
        if (navigator.sendBeacon) navigator.sendBeacon(url, fd);
        else { var x = new XMLHttpRequest(); x.open('POST', url, true); x.send(fd); }
    } catch (e) {}
}

// Browser-independent preview/reset hook (works in any browser, no private mode):
//   ?age_gate=preview  → show the gate this load even if the cookie remembers you
//   ?age_gate=reset    → forget the remembered acceptance entirely, then show
var agParam = '';
try { agParam = (new URLSearchParams(window.location.search).get('age_gate') || '').toLowerCase(); } catch (e) {}
if (agParam === 'reset') clearCookie(COOKIE);
var agForce = (agParam === 'preview' || agParam === 'reset');

if (!agForce && hasCookie(COOKIE)) { armIdle(); return; }   // already verified — idle re-challenge still applies

function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function build() {
    var ov = document.createElement('div');
    ov.className = 'ab-age-gate';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.innerHTML =
        '<div class="ab-age-gate-box">' +
            '<h2 class="ab-age-gate-title">' + esc(cfg.title || 'Age Verification') + '</h2>' +
            '<p class="ab-age-gate-prompt">' + esc(cfg.prompt || ('You must be ' + (cfg.threshold || 21) + ' or older to enter this site.')) + '</p>' +
            '<div class="ab-age-gate-actions">' +
                '<button type="button" class="ab-submit ab-age-confirm">' + esc(cfg.confirm_text || ('I am ' + (cfg.threshold || 21) + ' or older')) + '</button>' +
                '<button type="button" class="ab-age-deny">' + esc(cfg.deny_text || ('I am under ' + (cfg.threshold || 21))) + '</button>' +
            '</div>' +
        '</div>';
    return ov;
}

function show() {
    var ov = build();
    document.body.appendChild(ov);
    document.documentElement.style.overflow = 'hidden';   // block scroll behind the gate

    ov.querySelector('.ab-age-confirm').addEventListener('click', function() {
        setCookie(COOKIE, parseInt(cfg.remember_days, 10) || 30);
        logEvent('accepted');
        document.documentElement.style.overflow = '';
        ov.classList.add('ab-age-gate-out');
        setTimeout(function() { ov.remove(); }, 250);
        armIdle();
    });

    ov.querySelector('.ab-age-deny').addEventListener('click', function() {
        logEvent('denied');
        if (cfg.deny_url) { window.location.href = cfg.deny_url; return; }
        ov.querySelector('.ab-age-gate-box').innerHTML =
            '<h2 class="ab-age-gate-title">Access Denied</h2>' +
            '<p class="ab-age-gate-prompt">' + esc(cfg.deny_message || ('You must be ' + (cfg.threshold || 21) + ' or older to enter this site.')) + '</p>';
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', show);
} else {
    show();
}

})();
