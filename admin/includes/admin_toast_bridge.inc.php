<?php
/**
 * @appname    RI Admin — Toaster Bridge
 * @file       /admin/includes/admin_toast_bridge.inc.php
 * @version    2025.10.02.1436.00-EST r1202
 * @author     ChatGPT
 * @license    Business Source License
 * @description PHP-to-JS toaster bridge. Uses existing admin-toaster if present,
 *              otherwise prints a minimal fallback. Safe to include multiple times.
 */

declare(strict_types=1);

if (!function_exists('ri_toast')) {
  function ri_toast(string $level, string $msg, array $meta = []): void {
    $payload = [
      'level' => $level,
      'msg'   => $msg,
      'meta'  => $meta,
      'ts'    => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "<script>window.__RI_TOAST_QUEUE__ = (window.__RI_TOAST_QUEUE__||[]);"
       . "window.__RI_TOAST_QUEUE__.push($json);</script>\n";
  }
}

if (!defined('RI_TOAST_BRIDGE_EMITTED')) {
  define('RI_TOAST_BRIDGE_EMITTED', true);
  ?>
  <script>
  (function(){
    // Use existing AdminToaster if available, else minimal fallback.
    function ensureMount(){
      var host = document.getElementById('toast-notification');
      if (!host) {
        host = document.createElement('div');
        host.id = 'toast-notification';
        host.style.position = 'fixed';
        host.style.right = '12px';
        host.style.top = '12px';
        host.style.zIndex = 99999;
        document.body.appendChild(host);
      }
      return host;
    }

    function toastUsingFallback(payload){
      var host = ensureMount();
      var box = document.createElement('div');
      box.style.marginBottom = '8px';
      box.style.padding = '10px 12px';
      box.style.borderRadius = '10px';
      box.style.background = (payload.level === 'error' || payload.level === 'fatal') ? '#330' :
                             (payload.level === 'warn') ? '#332' : '#033';
      box.style.color = '#fff';
      box.style.boxShadow = '0 2px 8px rgba(0,0,0,.25)';
      var title = (payload.level||'info').toUpperCase();
      var meta = payload.meta ? ' ' + JSON.stringify(payload.meta) : '';
      box.textContent = '[' + title + '] ' + payload.msg + meta;
      host.appendChild(box);
      setTimeout(function(){ box.remove(); }, 8000);
    }

    function dispatch(payload){
      try {
        if (window.AdminToaster && typeof window.AdminToaster.show === 'function') {
          window.AdminToaster.show(payload.level || 'info', payload.msg, payload.meta || {});
        } else {
          toastUsingFallback(payload);
        }
        if (window.console) {
          var f = (console[payload.level] || console.log).bind(console);
          f('[TOAST]', payload.msg, payload.meta || {});
        }
      } catch (e) {
        try { toastUsingFallback({level:'error', msg:'Toaster dispatch failed: '+e, meta:{orig:payload}}); } catch(_){}
      }
    }

    function flush(){
      var q = window.__RI_TOAST_QUEUE__ || [];
      while(q.length){ dispatch(q.shift()); }
    }

    window.addEventListener('DOMContentLoaded', flush);
    window.addEventListener('load', flush);
    setInterval(flush, 500);
  })();
  </script>
  <?php
}
?>