<?php
/**
 * Admin Stats Widget — compact stats in the nav rail
 * @file /admin/widgets/admin-stats-widget.php
 * Included by admin_menu.php if file exists.
 */

// Load avg
$_sw_load = '—';
$_sw_raw = @file_get_contents('/proc/loadavg');
if ($_sw_raw) {
    $_sw_parts = explode(' ', $_sw_raw);
    $_sw_load = $_sw_parts[0];
}

// Memory %
$_sw_memPct = '—';
$_sw_mem = @file_get_contents('/proc/meminfo');
if ($_sw_mem && preg_match('/MemTotal:\s+(\d+)/', $_sw_mem, $_mt) && preg_match('/MemAvailable:\s+(\d+)/', $_sw_mem, $_ma)) {
    $_sw_totalKB = (int)$_mt[1];
    $_sw_availKB = (int)$_ma[1];
    $_sw_memPct = $_sw_totalKB > 0 ? round((($_sw_totalKB - $_sw_availKB) / $_sw_totalKB) * 100) . '%' : '—';
}
?>
<div class="rail-stats-widget" style="padding:6px 12px;font-size:0.7rem;color:#888;line-height:1.8;border-top:1px solid #333;margin-top:6px;">
    <span title="1-min load average">Load: <strong style="color:#e0e0e0"><?= htmlspecialchars($_sw_load) ?></strong></span> &nbsp;
    <span title="Memory used %">Mem: <strong style="color:#e0e0e0"><?= htmlspecialchars($_sw_memPct) ?></strong></span> &nbsp;
    <?php if (!empty($DU) && !empty($DU['ok'])): ?>
    <span title="Disk used %">Disk: <strong style="color:#e0e0e0"><?= (int)$DU['pct'] ?>%</strong></span>
    <?php endif; ?>
</div>
