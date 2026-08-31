<?php
/**
 * Menu Manager - Dark Glassmorphism Theme
 * File: /admin/menu-manager/menu-manager.php
 * Version: 2025.10.24.235902
 * 
 * Complete menu management system with:
 * - CRUD operations
 * - Drag & drop ordering
 * - Inline label editing
 * - External URL support
 * - Live font/style preview
 * - Real-time updates
 * - Dark glassmorphism UI 
 */

declare(strict_types=1);
if (!defined('SITE_ROOT')) { define('SITE_ROOT', dirname(__DIR__, 3)); }
if (file_exists(SITE_ROOT . '/admin/admin_header.php')) { 
    include SITE_ROOT . '/admin/admin_header.php'; 
}

// Data paths - organized structure
$MENU_DIR = SITE_ROOT . '/admin/data/menu';
$MENU_ITEMS_JSON = $MENU_DIR . '/menu_items.json';
$MENU_SETTINGS_JSON = $MENU_DIR . '/menu_settings.json';
$PAGES_DIR = SITE_ROOT . '/admin/data/pages';

// Ensure menu directory exists
if (!is_dir($MENU_DIR)) {
    mkdir($MENU_DIR, 0755, true);
}

// Scan installed fonts
$FONTS_DIR = SITE_ROOT . '/media/fonts';
$installedFonts = [];
if (is_dir($FONTS_DIR)) {
    $fontExts = ['ttf', 'otf', 'woff', 'woff2'];
    foreach (scandir($FONTS_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $fontExts, true)) {
            $base = pathinfo($f, PATHINFO_FILENAME);
            // Create a safe CSS family name
            $family = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
            $installedFonts[$family] = [
                'file' => $f,
                'family' => $family,
                'display' => $base, // Human-readable name
                'url' => '/media/fonts/' . rawurlencode($f),
                'format' => match($ext) {
                    'woff2' => 'woff2',
                    'woff' => 'woff',
                    'otf' => 'opentype',
                    default => 'truetype'
                }
            ];
        }
    }
    ksort($installedFonts);
}

// Helper functions
function mm_safe_title(string $s): string { 
    $s = trim($s); 
    return $s !== '' ? $s : 'Untitled'; 
}

function mm_parse_slug_from_url(string $url): string {
    $parts = parse_url($url);
    if (!empty($parts['query'])) { 
        parse_str($parts['query'], $q); 
        if (!empty($q['p'])) return (string)$q['p']; 
    }
    $seg = $parts['path'] ?? ''; 
    $seg = $seg ? basename($seg) : '';
    return $seg ?: '';
}

function mm_normalize_item($row): ?array {
    if (!is_array($row)) return null;
    $title = trim((string)($row['title'] ?? $row['label'] ?? $row['text'] ?? ''));
    $url   = trim((string)($row['url']   ?? $row['href']  ?? ''));
    $slug  = trim((string)($row['slug']  ?? ''));
    $type  = trim((string)($row['type']  ?? 'page'));
    
    if ($slug === '' && $url !== '') $slug = mm_parse_slug_from_url($url);
    if ($title === '' || $url === '') return null;
    
    return [
        'title' => $title,
        'url' => $url,
        'slug' => $slug,
        'type' => $type
    ];
}

function mm_read_menu(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path); 
    if ($raw === false) return [];
    $data = json_decode($raw, true); 
    if (!is_array($data)) return [];
    if (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
    $out = []; 
    foreach ($data as $row) { 
        $n = mm_normalize_item($row); 
        if ($n) $out[] = $n; 
    }
    return $out;
}

function mm_read_settings(string $path): array {
    $defaults = [
        'menu_bg_color' => '#0a0a0a',
        'menu_bg_opacity' => 0.4,
        'menu_item_bg_color' => '#ffffff',
        'menu_item_bg_opacity' => 0.03,
        'menu_item_hover_bg_color' => '#3b82f6',
        'menu_item_hover_bg_opacity' => 0.2,
        'menu_current_item_bg_color' => '#ffffff',
        'menu_current_item_bg_opacity' => 0.1,
        'menu_font_color' => '#ffffff',
        'menu_font_hover_color' => '#60a5fa',
        'menu_current_item_font_color' => '#ffffff',
        'menu_font_family' => 'Inter, system-ui, sans-serif',
        'menu_font_size' => 15,
        'menu_font_weight' => 500,
        'menu_font_style' => 'normal',
        'menu_alignment' => 'left'
    ];

    if (!is_file($path)) return $defaults;
    $raw = @file_get_contents($path);
    if ($raw === false) return $defaults;
    $data = json_decode($raw, true);
    if (!is_array($data)) return $defaults;

    return array_merge($defaults, $data);
}

function mm_scan_pages(string $pagesDir): array {
    $out = []; 
    if (!is_dir($pagesDir)) return $out;
    foreach (@scandir($pagesDir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        $dir = $pagesDir . '/' . $slug; 
        if (!is_dir($dir)) continue;
        $title = $slug; 
        $json = $dir . '/' . $slug . '.json';
        if (is_file($json)) { 
            $j = json_decode((string)@file_get_contents($json), true); 
            if (is_array($j) && !empty($j['page_title'])) 
                $title = (string)$j['page_title']; 
        }
        $out[] = [
            'slug' => $slug,
            'title' => mm_safe_title($title),
            'url' => '/page.php?p=' . rawurlencode($slug),
            'type' => 'page'
        ];
    }
    usort($out, fn($a,$b) => strcasecmp($a['title'], $b['title']));
    return $out;
}

/**
 * Scan AIPageMaker pages from /admin/data/AIP_Pages/
 */
function mm_scan_aip_pages(string $aipDir): array {
    $out = [];
    if (!is_dir($aipDir)) return $out;

    foreach (@scandir($aipDir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        $dir = $aipDir . '/' . $slug;
        if (!is_dir($dir)) continue;

        // Check for index.html or meta.json
        $indexFile = $dir . '/index.html';
        $metaFile = $dir . '/meta.json';

        if (!is_file($indexFile)) continue;

        // Get title from meta.json if available
        $title = $slug;
        if (is_file($metaFile)) {
            $meta = json_decode((string)@file_get_contents($metaFile), true);
            if (is_array($meta) && !empty($meta['title'])) {
                $title = (string)$meta['title'];
            }
        }

        $out[] = [
            'slug' => $slug,
            'title' => mm_safe_title($title) . ' [AI]',
            'url' => '/admin/data/AIP_Pages/' . rawurlencode($slug) . '/',
            'type' => 'aip-page'
        ];
    }
    usort($out, fn($a,$b) => strcasecmp($a['title'], $b['title']));
    return $out;
}

// Load data
$menu = mm_read_menu($MENU_ITEMS_JSON);
$settings = mm_read_settings($MENU_SETTINGS_JSON);
$regularPages = mm_scan_pages($PAGES_DIR);
$aipPages = mm_scan_aip_pages(SITE_ROOT . '/admin/data/AIP_Pages');
$allPages = array_merge($regularPages, $aipPages);
$inMenu = []; 
foreach ($menu as $m) { 
    $inMenu[$m['slug']] = true; 
}
$available = array_values(array_filter($allPages, fn($p) => empty($inMenu[$p['slug']])));

// Valid page slugs — used to flag menu items that point at a page that no longer
// exists (e.g. deleted outside the delete-unlink path, or a legacy orphan).
$validSlugs = [];
foreach ($allPages as $p) { $validSlugs[$p['slug']] = true; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Manager - Dark Glassmorphism</title>
    <link rel="stylesheet" href="/admin/css/admin-styles.css">
    <link rel="stylesheet" href="css/menu-manager.css">
    <style id="installed-fonts">
    <?php foreach ($installedFonts as $font): ?>
    @font-face {
        font-family: '<?php echo $font['family']; ?>';
        src: url('<?php echo $font['url']; ?>') format('<?php echo $font['format']; ?>');
        font-display: swap;
    }
    <?php endforeach; ?>
    </style>
</head>
<body class="admin-page admin-menu-manager">

    <h1 class="panel_header_h1">Menu Manager</h1>

    <div class="mm-container">
        <!-- LEFT PANEL: Menu Items -->
        <div class="mm-panel mm-panel-items">
            <div class="mm-panel-header">
                <h2>Menu Items</h2>
                <div class="mm-actions">
                    <button id="mm-add-external" class="mm-btn mm-btn-secondary" title="Add External Link">
                        + External Link
                    </button>
                    <button id="mm-save" class="mm-btn mm-btn-primary" title="Save All Changes">
                        Save Menu
                    </button>
                </div>
            </div>
            
            <ul id="menu-list" class="mm-list" aria-label="Current menu items">
                <?php if (empty($menu)): ?>
                    <li class="mm-empty">No items. Add pages from the right panel or create external links.</li>
                <?php else: ?>
                    <?php foreach ($menu as $item): 
                        $title = htmlspecialchars(mm_safe_title($item['title'] ?? ''), ENT_QUOTES);
                        $slug  = htmlspecialchars($item['slug'] ?? '', ENT_QUOTES);
                        $url   = htmlspecialchars($item['url'] ?? '', ENT_QUOTES);
                        $type  = htmlspecialchars($item['type'] ?? 'page', ENT_QUOTES);
                        $isExternal = ($type === 'external' || strpos($url, 'http') === 0);
                        $rawSlug = $item['slug'] ?? '';
                        $isBroken = (!$isExternal && $rawSlug !== '' && empty($validSlugs[$rawSlug]));
                    ?>
                    <li class="mm-item<?php echo $isBroken ? ' mm-item-broken' : ''; ?>" draggable="true"
                        data-slug="<?php echo $slug; ?>" 
                        data-url="<?php echo $url; ?>" 
                        data-title="<?php echo $title; ?>"
                        data-type="<?php echo $type; ?>">
                        <span class="mm-drag-handle" aria-label="Drag to reorder">⋮⋮</span>
                        <div class="mm-item-content">
                            <div class="mm-item-label" contenteditable="true" data-original="<?php echo $title; ?>">
                                <?php echo $title; ?>
                            </div>
                            <div class="mm-item-url" contenteditable="<?php echo $isExternal ? 'true' : 'false'; ?>" 
                                 data-original="<?php echo $url; ?>"
                                 title="<?php echo $isExternal ? 'Click to edit URL' : 'Internal page URL'; ?>">
                                <?php echo $url; ?>
                            </div>
                            <?php if ($isExternal): ?>
                            <span class="mm-badge mm-badge-external">External</span>
                            <?php endif; ?>
                            <?php if ($isBroken): ?>
                            <span class="mm-badge mm-badge-broken" title="No page with slug &quot;<?php echo $slug; ?>&quot; exists — remove or fix this item.">⚠ Broken link</span>
                            <?php endif; ?>
                        </div>
                        <button class="mm-btn mm-btn-danger mm-remove" title="Remove from menu">×</button>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            
            <div id="mm-status" class="mm-status" role="status" aria-live="polite"></div>
        </div>
        
        <!-- RIGHT PANEL: Available Pages & Font Manager -->
        <div class="mm-panel mm-panel-sidebar">
            <!-- Available Pages Section -->
            <div class="mm-section">
                <h3>Available Pages</h3>
                <?php if (empty($available)): ?>
                    <p class="mm-empty-small">All pages are in the menu</p>
                <?php else: ?>
                    <div id="pill-list" class="mm-pills">
                        <?php foreach ($available as $p): 
                            $pt = htmlspecialchars($p['title'], ENT_QUOTES);
                            $ps = htmlspecialchars($p['slug'],  ENT_QUOTES);
                            $pu = htmlspecialchars($p['url'],   ENT_QUOTES);
                        ?>
                        <button class="mm-pill" 
                                data-slug="<?php echo $ps; ?>" 
                                data-url="<?php echo $pu; ?>" 
                                data-title="<?php echo $pt; ?>"
                                data-type="page">
                            <?php echo $pt; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Font & Style Manager Section -->
            <div class="mm-section mm-section-fonts">
                <h3>Menu Styling</h3>

                <!-- Live Preview - Matches Front Page Menu -->
                <div class="mm-preview" id="mm-preview">
                    <div class="mm-preview-label">
                        <span><span class="dot dot-default"></span> Default</span>
                        <span><span class="dot dot-hover"></span> Hover</span>
                        <span><span class="dot dot-active"></span> Active</span>
                    </div>
                    <ul class="mm-preview-menu">
                        <li><a href="#">Home</a></li>
                        <li class="preview-active"><a href="#">About</a></li>
                        <li class="preview-hover"><a href="#">Projects</a></li>
                        <li><a href="#">Contact</a></li>
                    </ul>
                </div>

                <!-- Menu Display Toggle -->
                <div class="mm-controls">
                    <div class="mm-control-group mm-control-checkbox">
                        <label for="menu-disabled" class="mm-checkbox-label">
                            <input type="checkbox" id="menu-disabled" <?php echo !empty($settings['menu_disabled']) ? 'checked' : ''; ?>>
                            <span>Disable Menu</span>
                        </label>
                        <p class="mm-hint">Hide the navigation menu on the public site</p>
                    </div>
                </div>

                <!-- Tab Bar -->
                <div class="mm-tabs" role="tablist" aria-label="Menu settings tabs">
                    <div class="mm-tab-list">
                        <button class="mm-tab-trigger active" role="tab" aria-selected="true" aria-controls="tab-typography" data-tab="typography">Typography</button>
                        <button class="mm-tab-trigger" role="tab" aria-selected="false" aria-controls="tab-colors" data-tab="colors">Colors</button>
                        <button class="mm-tab-trigger" role="tab" aria-selected="false" aria-controls="tab-layout" data-tab="layout">Layout</button>
                    </div>
                </div>

                <!-- Typography Tab -->
                <div class="mm-tab-content active" id="tab-typography" role="tabpanel">
                    <div class="mm-controls">
                        <div class="mm-control-group">
                            <label for="menu-font-family">Font Family</label>
                            <select id="menu-font-family" class="mm-control mm-font-select">
                                <optgroup label="System Fonts">
                                    <?php
                                    $systemFonts = [
                                        'Inter, system-ui, sans-serif' => 'Inter',
                                        'Arial, sans-serif' => 'Arial',
                                        'Helvetica, sans-serif' => 'Helvetica',
                                        'Georgia, serif' => 'Georgia',
                                        'Times New Roman, serif' => 'Times New Roman',
                                        'Courier New, monospace' => 'Courier New',
                                        'Verdana, sans-serif' => 'Verdana'
                                    ];
                                    foreach ($systemFonts as $value => $label):
                                        $selected = ($settings['menu_font_family'] === $value) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($installedFonts)): ?>
                                <optgroup label="Installed Fonts">
                                    <?php foreach ($installedFonts as $font):
                                        $fontValue = $font['family'] . ', sans-serif';
                                        $selected = ($settings['menu_font_family'] === $fontValue ||
                                                     $settings['menu_font_family'] === $font['family']) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($fontValue); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($font['display']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>

                            <!-- Visual Font Samples -->
                            <div class="mm-font-samples" id="font-samples">
                                <div class="mm-font-samples-label">Click to select:</div>
                                <?php foreach ($systemFonts as $value => $label):
                                    $isActive = ($settings['menu_font_family'] === $value) ? 'active' : '';
                                ?>
                                <button type="button" class="mm-font-sample <?php echo $isActive; ?>"
                                        data-font="<?php echo htmlspecialchars($value); ?>"
                                        style="font-family: <?php echo htmlspecialchars($value); ?>;">
                                    <?php echo htmlspecialchars($label); ?>
                                </button>
                                <?php endforeach; ?>
                                <?php foreach ($installedFonts as $font):
                                    $fontValue = $font['family'] . ', sans-serif';
                                    $isActive = ($settings['menu_font_family'] === $fontValue || $settings['menu_font_family'] === $font['family']) ? 'active' : '';
                                ?>
                                <button type="button" class="mm-font-sample <?php echo $isActive; ?>"
                                        data-font="<?php echo htmlspecialchars($fontValue); ?>"
                                        style="font-family: '<?php echo htmlspecialchars($font['family']); ?>', sans-serif;">
                                    <?php echo htmlspecialchars($font['display']); ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mm-control-group">
                            <label for="menu-font-size">Font Size</label>
                            <div class="mm-slider-with-input">
                                <input type="range" id="menu-font-size" class="mm-slider"
                                       min="10" max="32" value="<?php echo $settings['menu_font_size']; ?>">
                                <input type="number" id="menu-font-size-input" class="mm-slider-input"
                                       min="10" max="32" value="<?php echo $settings['menu_font_size']; ?>">
                                <span class="mm-unit">px</span>
                            </div>
                        </div>

                        <div class="mm-control-group">
                            <label for="menu-font-weight">Font Weight</label>
                            <div class="mm-slider-with-input">
                                <input type="range" id="menu-font-weight" class="mm-slider"
                                       min="100" max="900" step="100" value="<?php echo $settings['menu_font_weight']; ?>">
                                <input type="number" id="menu-font-weight-input" class="mm-slider-input"
                                       min="100" max="900" step="100" value="<?php echo $settings['menu_font_weight']; ?>">
                            </div>
                        </div>

                        <div class="mm-control-group">
                            <label for="menu-font-style">Font Style</label>
                            <select id="menu-font-style" class="mm-control">
                                <option value="normal" <?php echo $settings['menu_font_style'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="italic" <?php echo $settings['menu_font_style'] === 'italic' ? 'selected' : ''; ?>>Italic</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Colors Tab -->
                <div class="mm-tab-content" id="tab-colors" role="tabpanel">
                    <div class="mm-controls">
                        <!-- Text Colors Collapsible -->
                        <div class="mm-collapsible open">
                            <button class="mm-collapsible-header" aria-expanded="true">
                                <span>Text Colors</span>
                                <span class="mm-collapsible-icon"></span>
                            </button>
                            <div class="mm-collapsible-content">
                                <div class="mm-control-group">
                                    <label>Default Text</label>
                                    <div class="mm-color-with-hex">
                                        <input type="color" id="menu-font-color" class="mm-color"
                                               value="<?php echo $settings['menu_font_color']; ?>">
                                        <input type="text" id="menu-font-color-hex" class="mm-hex-input"
                                               value="<?php echo $settings['menu_font_color']; ?>" maxlength="7">
                                    </div>
                                </div>

                                <div class="mm-control-group">
                                    <label>Hover Text</label>
                                    <div class="mm-color-with-hex">
                                        <input type="color" id="menu-font-hover-color" class="mm-color"
                                               value="<?php echo $settings['menu_font_hover_color']; ?>">
                                        <input type="text" id="menu-font-hover-color-hex" class="mm-hex-input"
                                               value="<?php echo $settings['menu_font_hover_color']; ?>" maxlength="7">
                                    </div>
                                </div>

                                <div class="mm-control-group">
                                    <label>Active/Current Text</label>
                                    <div class="mm-color-with-hex">
                                        <input type="color" id="menu-current-font-color" class="mm-color"
                                               value="<?php echo $settings['menu_current_item_font_color']; ?>">
                                        <input type="text" id="menu-current-font-color-hex" class="mm-hex-input"
                                               value="<?php echo $settings['menu_current_item_font_color']; ?>" maxlength="7">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Menu Bar Background Collapsible -->
                        <div class="mm-collapsible open">
                            <button class="mm-collapsible-header" aria-expanded="true">
                                <span>Menu Bar Background</span>
                                <span class="mm-collapsible-icon"></span>
                            </button>
                            <div class="mm-collapsible-content">
                                <div class="mm-control-group">
                                    <label>Color</label>
                                    <div class="mm-color-with-hex">
                                        <input type="color" id="menu-bg-color" class="mm-color"
                                               value="<?php echo $settings['menu_bg_color']; ?>">
                                        <input type="text" id="menu-bg-color-hex" class="mm-hex-input"
                                               value="<?php echo $settings['menu_bg_color']; ?>" maxlength="7">
                                    </div>
                                </div>

                                <div class="mm-control-group">
                                    <label>Opacity</label>
                                    <div class="mm-slider-with-input">
                                        <input type="range" id="menu-bg-opacity" class="mm-slider"
                                               min="0" max="1" step="0.05" value="<?php echo $settings['menu_bg_opacity']; ?>">
                                        <input type="number" id="menu-bg-opacity-input" class="mm-slider-input"
                                               min="0" max="1" step="0.05" value="<?php echo $settings['menu_bg_opacity']; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item Backgrounds Collapsible (collapsed by default) -->
                        <div class="mm-collapsible">
                            <button class="mm-collapsible-header" aria-expanded="false">
                                <span>Item Backgrounds</span>
                                <span class="mm-collapsible-icon"></span>
                            </button>
                            <div class="mm-collapsible-content">
                                <div class="mm-item-bg-group">
                                    <label class="mm-item-bg-label">Default Item</label>
                                    <div class="mm-control-group">
                                        <label>Color</label>
                                        <div class="mm-color-with-hex">
                                            <input type="color" id="menu-item-bg-color" class="mm-color"
                                                   value="<?php echo $settings['menu_item_bg_color']; ?>">
                                            <input type="text" id="menu-item-bg-color-hex" class="mm-hex-input"
                                                   value="<?php echo $settings['menu_item_bg_color']; ?>" maxlength="7">
                                        </div>
                                    </div>
                                    <div class="mm-control-group">
                                        <label>Opacity</label>
                                        <div class="mm-slider-with-input">
                                            <input type="range" id="menu-item-bg-opacity" class="mm-slider"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_item_bg_opacity']; ?>">
                                            <input type="number" id="menu-item-bg-opacity-input" class="mm-slider-input"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_item_bg_opacity']; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mm-item-bg-group">
                                    <label class="mm-item-bg-label">Hover Item</label>
                                    <div class="mm-control-group">
                                        <label>Color</label>
                                        <div class="mm-color-with-hex">
                                            <input type="color" id="menu-item-hover-bg-color" class="mm-color"
                                                   value="<?php echo $settings['menu_item_hover_bg_color']; ?>">
                                            <input type="text" id="menu-item-hover-bg-color-hex" class="mm-hex-input"
                                                   value="<?php echo $settings['menu_item_hover_bg_color']; ?>" maxlength="7">
                                        </div>
                                    </div>
                                    <div class="mm-control-group">
                                        <label>Opacity</label>
                                        <div class="mm-slider-with-input">
                                            <input type="range" id="menu-item-hover-bg-opacity" class="mm-slider"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_item_hover_bg_opacity']; ?>">
                                            <input type="number" id="menu-item-hover-bg-opacity-input" class="mm-slider-input"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_item_hover_bg_opacity']; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mm-item-bg-group">
                                    <label class="mm-item-bg-label">Active Item</label>
                                    <div class="mm-control-group">
                                        <label>Color</label>
                                        <div class="mm-color-with-hex">
                                            <input type="color" id="menu-current-item-bg-color" class="mm-color"
                                                   value="<?php echo $settings['menu_current_item_bg_color']; ?>">
                                            <input type="text" id="menu-current-item-bg-color-hex" class="mm-hex-input"
                                                   value="<?php echo $settings['menu_current_item_bg_color']; ?>" maxlength="7">
                                        </div>
                                    </div>
                                    <div class="mm-control-group">
                                        <label>Opacity</label>
                                        <div class="mm-slider-with-input">
                                            <input type="range" id="menu-current-item-bg-opacity" class="mm-slider"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_current_item_bg_opacity']; ?>">
                                            <input type="number" id="menu-current-item-bg-opacity-input" class="mm-slider-input"
                                                   min="0" max="1" step="0.01" value="<?php echo $settings['menu_current_item_bg_opacity']; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Layout Tab -->
                <div class="mm-tab-content" id="tab-layout" role="tabpanel">
                    <div class="mm-controls">
                        <div class="mm-control-group">
                            <label>Menu Alignment</label>
                            <div class="mm-alignment-control" role="radiogroup" aria-label="Menu alignment">
                                <label class="mm-alignment-option <?php echo $settings['menu_alignment'] === 'left' ? 'active' : ''; ?>">
                                    <input type="radio" name="menu_alignment" value="left" <?php echo $settings['menu_alignment'] === 'left' ? 'checked' : ''; ?>>
                                    <span class="mm-alignment-icon">
                                        <svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="5" width="4" height="3"/><rect x="9" y="5" width="4" height="3"/><rect x="15" y="5" width="4" height="3"/></svg>
                                    </span>
                                    <span class="mm-alignment-label">Left</span>
                                </label>
                                <label class="mm-alignment-option <?php echo $settings['menu_alignment'] === 'center' ? 'active' : ''; ?>">
                                    <input type="radio" name="menu_alignment" value="center" <?php echo $settings['menu_alignment'] === 'center' ? 'checked' : ''; ?>>
                                    <span class="mm-alignment-icon">
                                        <svg viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="4" height="3"/><rect x="10" y="5" width="4" height="3"/><rect x="15" y="5" width="4" height="3"/></svg>
                                    </span>
                                    <span class="mm-alignment-label">Center</span>
                                </label>
                                <label class="mm-alignment-option <?php echo $settings['menu_alignment'] === 'right' ? 'active' : ''; ?>">
                                    <input type="radio" name="menu_alignment" value="right" <?php echo $settings['menu_alignment'] === 'right' ? 'checked' : ''; ?>>
                                    <span class="mm-alignment-icon">
                                        <svg viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="4" height="3"/><rect x="11" y="5" width="4" height="3"/><rect x="17" y="5" width="4" height="3"/></svg>
                                    </span>
                                    <span class="mm-alignment-label">Right</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Settings Button -->
                <div class="mm-controls mm-save-controls">
                    <button id="mm-save-settings" class="mm-btn mm-btn-success mm-btn-full">
                        Save Settings
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- External Link Modal -->
    <div id="mm-modal" class="mm-modal" style="display: none;">
        <div class="mm-modal-content">
            <div class="mm-modal-header">
                <h3>Add External Link</h3>
                <button class="mm-modal-close" id="mm-modal-close">×</button>
            </div>
            <div class="mm-modal-body">
                <div class="mm-form-group">
                    <label for="external-title">Link Title</label>
                    <input type="text" id="external-title" class="mm-input" placeholder="e.g. Our Blog">
                </div>
                <div class="mm-form-group">
                    <label for="external-url">URL</label>
                    <input type="url" id="external-url" class="mm-input" placeholder="https://example.com">
                </div>
            </div>
            <div class="mm-modal-footer">
                <button id="mm-modal-cancel" class="mm-btn mm-btn-secondary">Cancel</button>
                <button id="mm-modal-add" class="mm-btn mm-btn-primary">Add Link</button>
            </div>
        </div>
    </div>
    
    <script src="js/menu-manager.js"></script>
</body>
</html>
