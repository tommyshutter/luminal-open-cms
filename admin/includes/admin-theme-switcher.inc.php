<?php
/**
 * Theme Switcher Widget - placed under Scratch Pad in admin rail
 * @file SITE-ROOT/admin/includes/admin-theme-switcher.inc.php
 * Provides: Theme selector (Default / Auria) + Dark / Light toggle
 */
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

// Read site logo from settings
$_ts_logo = '';
$_ts_settings_file = SITE_ROOT . '/admin/data/site-settings.json';
if (is_file($_ts_settings_file)) {
  $_ts_cfg = json_decode(file_get_contents($_ts_settings_file), true);
  if (!empty($_ts_cfg['logo_path']) && !empty($_ts_cfg['enable_logo'])) {
    $_ts_logo = '/' . ltrim($_ts_cfg['logo_path'], '/');
  }
}
?>
<div id="admin-theme-switcher" style="margin:10px 12px 6px; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; color:#ddd;">

  <!-- Site Logo Mirror -->
  <div style="text-align:center; padding:8px 0;">
    <?php if ($_ts_logo): ?>
      <img src="<?= htmlspecialchars($_ts_logo) ?>" alt="Site Logo"
           style="max-width:80%; max-height:80px; height:auto; display:block; margin:0 auto;">
    <?php endif; ?>
  </div>

  <!-- Theme Selector -->
  <div style="display:flex; gap:6px; margin:6px 0;">
    <button type="button" class="ts-btn" data-theme="default"
            style="flex:1; padding:5px 8px; border-radius:6px; border:1px solid #333;
                   background:#111; color:#ddd; cursor:pointer; font-size:11px;
                   text-transform:uppercase; letter-spacing:.06em;">
      Default
    </button>
    <button type="button" class="ts-btn" data-theme="auria"
            style="flex:1; padding:5px 8px; border-radius:6px; border:1px solid #333;
                   background:#111; color:#ddd; cursor:pointer; font-size:11px;
                   text-transform:uppercase; letter-spacing:.06em;">
      Auria
    </button>
  </div>

  <!-- Dark / Light Toggle -->
  <div style="display:flex; align-items:center; justify-content:center; gap:8px; margin:6px 0;">
    <span style="font-size:11px; color:#888;">Dark</span>
    <label style="position:relative; display:inline-block; width:36px; height:20px; cursor:pointer;">
      <input type="checkbox" id="ts-mode-toggle" style="display:none;">
      <span id="ts-slider" style="position:absolute; inset:0; background:#333; border-radius:20px; transition:.2s;"></span>
      <span id="ts-knob" style="position:absolute; top:2px; left:2px; width:16px; height:16px;
                                background:#ddd; border-radius:50%; transition:.2s;"></span>
    </label>
    <span style="font-size:11px; color:#888;">Light</span>
  </div>
</div>

<script>
(function(){
  const LS_THEME = 'admin_theme';
  const LS_MODE  = 'admin_theme_mode';

  function applyTheme(theme, mode) {
    const body = document.body;
    // Remove old theme classes
    body.classList.remove('theme-auria', 'light');

    if (theme === 'auria') {
      body.classList.add('theme-auria');
      if (mode === 'light') body.classList.add('light');
    }

    // Update button active states
    document.querySelectorAll('.ts-btn').forEach(b => {
      const isActive = b.dataset.theme === theme;
      b.style.background = isActive ? '#2f4fd6' : '#111';
      b.style.borderColor = isActive ? '#4c6fff' : '#333';
      b.style.color = isActive ? '#fff' : '#ddd';
    });

    // Update toggle visual
    const knob = document.getElementById('ts-knob');
    const slider = document.getElementById('ts-slider');
    const toggle = document.getElementById('ts-mode-toggle');
    if (knob && slider && toggle) {
      toggle.checked = mode === 'light';
      knob.style.left = mode === 'light' ? '18px' : '2px';
      slider.style.background = mode === 'light' ? '#6c5ce7' : '#333';
    }

    try {
      localStorage.setItem(LS_THEME, theme);
      localStorage.setItem(LS_MODE, mode);
    } catch(e){}
  }

  // Init from localStorage
  const savedTheme = localStorage.getItem(LS_THEME) || 'default';
  const savedMode  = localStorage.getItem(LS_MODE) || 'dark';
  applyTheme(savedTheme, savedMode);

  // Theme buttons
  document.querySelectorAll('.ts-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = localStorage.getItem(LS_MODE) || 'dark';
      applyTheme(btn.dataset.theme, mode);
    });
  });

  // Dark/Light toggle
  const modeToggle = document.getElementById('ts-mode-toggle');
  if (modeToggle) {
    modeToggle.addEventListener('change', () => {
      const theme = localStorage.getItem(LS_THEME) || 'default';
      applyTheme(theme, modeToggle.checked ? 'light' : 'dark');
    });
  }
})();
</script>
