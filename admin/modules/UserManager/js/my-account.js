/**
 * My Account — self-service profile + password.
 * Talks only to account_api.php, which is scoped to the logged-in user.
 * @module UserManager
 */
(function () {
  'use strict';

  var API = '/admin/modules/UserManager/account_api.php';

  function toast(msg, ok) {
    var t = document.getElementById('ma-toast');
    if (!t) { alert(msg); return; }
    t.textContent = msg;
    t.className = 'myacct-toast show ' + (ok ? 'ok' : 'err');
    setTimeout(function () { t.className = 'myacct-toast'; }, 3200);
  }

  function post(action, fields) {
    var body = new URLSearchParams();
    body.set('action', action);
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: body.toString()
    }).then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected server response.' }; }); });
  }

  function loadMe() {
    fetch(API + '?action=me', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) return;
        var u = res.user || {};
        var d = document.getElementById('ma-display');
        var e = document.getElementById('ma-email');
        if (d) d.value = u.display_name || '';
        if (e) e.value = u.email || '';
      })
      .catch(function () { /* leave fields blank on failure */ });
  }

  function wireProfile() {
    var f = document.getElementById('ma-profile-form');
    if (!f) return;
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var cur = document.getElementById('ma-profile-current');
      post('update_profile', {
        display_name: (document.getElementById('ma-display') || {}).value || '',
        email: (document.getElementById('ma-email') || {}).value || '',
        current_password: (cur || {}).value || ''
      }).then(function (res) {
        toast(res.message || res.error || 'Done', !!res.success);
        if (res.success && cur) cur.value = '';
      }).catch(function () { toast('Network error. Please try again.', false); });
    });
  }

  function wirePassword() {
    var f = document.getElementById('ma-password-form');
    if (!f) return;
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var cur = document.getElementById('ma-cur');
      var nw = document.getElementById('ma-new');
      var cf = document.getElementById('ma-confirm');
      if ((nw.value || '') !== (cf.value || '')) {
        toast('New passwords do not match.', false);
        return;
      }
      post('change_password', {
        current_password: cur.value || '',
        new_password: nw.value || '',
        confirm_password: cf.value || ''
      }).then(function (res) {
        toast(res.message || res.error || 'Done', !!res.success);
        if (res.success) { cur.value = ''; nw.value = ''; cf.value = ''; }
      }).catch(function () { toast('Network error. Please try again.', false); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadMe();
    wireProfile();
    wirePassword();
  });
})();
