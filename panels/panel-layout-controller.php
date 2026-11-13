<?php
/**
 * @file panels/panel-layout-controller.php
 * @version 2025.07.26.1325.EST
 * @description REWRITE. Injects a JavaScript block to actively control the homepage column layout, bypassing CSS stripping or override issues.
 */
require_once __DIR__ . '/../utils.php';

// Load layout settings from the admin panel, with safe defaults
$layout_settings = loadJsonData('layout_settings.json');
$left_col_disabled = $layout_settings['left_col_disabled'] ?? false;
$left_col_width = $layout_settings['left_col_width'] ?? 30;
$right_col_width = $left_col_disabled ? 100 : ($layout_settings['right_col_width'] ?? 70);
$right_padding = $left_col_disabled ? '0' : '10px';

// We are outputting a JavaScript block. The browser will execute this immediately.
?>
<script id="layout-controller-script">
    (function() {
        // This script is injected by the layout controller panel.
        const leftPanel = document.getElementById('left-panel-container');
        const rightPanel = document.getElementById('right-panel-container');
        const grid = document.getElementById('home-layout-grid');

        // Apply styles to the parent container to ensure proper alignment
        if (grid) {
            grid.style.display = 'flex';
            grid.style.width = '100%';
        }

        // Apply the "light switch" for the left column
        if (<?php echo json_encode($left_col_disabled); ?>) {
            if (leftPanel) {
                leftPanel.style.display = 'none';
            }
        } else {
            if (leftPanel) {
                leftPanel.style.display = 'block';
                leftPanel.style.width = '<?php echo $left_col_width; ?>%';
                leftPanel.style.paddingRight = '10px';
                leftPanel.style.boxSizing = 'border-box';
            }
        }

        // Always style the right column based on the settings
        if (rightPanel) {
            rightPanel.style.width = '<?php echo $right_col_width; ?>%';
            rightPanel.style.paddingLeft = '<?php echo $right_padding; ?>';
            rightPanel.style.boxSizing = 'border-box';
        }
    })();
</script>