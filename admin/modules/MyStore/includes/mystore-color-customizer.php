<?php
/**
 * PayPal Color Customizer Component
 * Allows dynamic color and styling changes
 * 
 * File: /admin/paypal/includes/paypal-color-customizer.php
 * Version: 2025.10.01
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access denied');
}

// Handle color settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_colors') {
    $settings = [
        'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '#fbbf24'),
        'background_color' => sanitize_hex_color($_POST['background_color'] ?? '#000000'),
        'text_color' => sanitize_hex_color($_POST['text_color'] ?? '#ffffff'),
        'card_color' => sanitize_hex_color($_POST['card_color'] ?? '#1f1f1f'),
        'accent_color' => sanitize_hex_color($_POST['accent_color'] ?? '#374151'),
        'opacity' => max(0, min(100, intval($_POST['opacity'] ?? 80))),
        'blur' => max(0, min(50, intval($_POST['blur'] ?? 20))),
        'border_radius' => max(0, min(20, intval($_POST['border_radius'] ?? 8)))
    ];
    
    // Save to session or file
    $_SESSION['color_settings'] = $settings;
    file_put_contents(PAYPAL_DATA_PATH . '/color-settings.json', json_encode($settings, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'message' => 'Colors updated successfully']);
    exit;
}

// Load current settings
function paypal_get_color_settings() {
    $defaults = [
        'primary_color' => '#fbbf24',
        'background_color' => '#000000',
        'text_color' => '#ffffff',
        'card_color' => '#1f1f1f',
        'accent_color' => '#374151',
        'opacity' => 80,
        'blur' => 20,
        'border_radius' => 8
    ];
    
    // Try session first
    if (isset($_SESSION['color_settings'])) {
        return array_merge($defaults, $_SESSION['color_settings']);
    }
    
    // Try file
    $settings_file = PAYPAL_DATA_PATH . '/color-settings.json';
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        if ($settings) {
            return array_merge($defaults, $settings);
        }
    }
    
    return $defaults;
}

function sanitize_hex_color($color) {
    $color = ltrim($color, '#');
    if (ctype_xdigit($color) && (strlen($color) === 3 || strlen($color) === 6)) {
        return '#' . $color;
    }
    return '#000000';
}

$color_settings = paypal_get_color_settings();
?>

<!-- Color Customizer Panel -->
<div id="color-customizer" class="color-customizer-panel hidden">
    <div class="color-panel-header">
        <h3>
            <i class="fas fa-palette"></i>
            Color Settings
        </h3>
        <button onclick="closeColorCustomizer()" class="close-btn">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="color-panel-content">
        <form id="color-settings-form" onsubmit="saveColorSettings(event)">
            <!-- Primary Color -->
            <div class="color-group">
                <label>Primary Color (Buttons)</label>
                <div class="color-input-wrapper">
                    <input type="color" name="primary_color" value="<?= $color_settings['primary_color'] ?>" 
                           onchange="updatePreview()">
                    <span class="color-value"><?= $color_settings['primary_color'] ?></span>
                </div>
            </div>
            
            <!-- Background Color -->
            <div class="color-group">
                <label>Background Color</label>
                <div class="color-input-wrapper">
                    <input type="color" name="background_color" value="<?= $color_settings['background_color'] ?>" 
                           onchange="updatePreview()">
                    <span class="color-value"><?= $color_settings['background_color'] ?></span>
                </div>
            </div>
            
            <!-- Text Color -->
            <div class="color-group">
                <label>Text Color</label>
                <div class="color-input-wrapper">
                    <input type="color" name="text_color" value="<?= $color_settings['text_color'] ?>" 
                           onchange="updatePreview()">
                    <span class="color-value"><?= $color_settings['text_color'] ?></span>
                </div>
            </div>
            
            <!-- Card Color -->
            <div class="color-group">
                <label>Card Background</label>
                <div class="color-input-wrapper">
                    <input type="color" name="card_color" value="<?= $color_settings['card_color'] ?>" 
                           onchange="updatePreview()">
                    <span class="color-value"><?= $color_settings['card_color'] ?></span>
                </div>
            </div>
            
            <!-- Accent Color -->
            <div class="color-group">
                <label>Accent Color</label>
                <div class="color-input-wrapper">
                    <input type="color" name="accent_color" value="<?= $color_settings['accent_color'] ?>" 
                           onchange="updatePreview()">
                    <span class="color-value"><?= $color_settings['accent_color'] ?></span>
                </div>
            </div>
            
            <!-- Opacity Slider -->
            <div class="slider-group">
                <label>Card Opacity: <span id="opacity-value"><?= $color_settings['opacity'] ?>%</span></label>
                <input type="range" name="opacity" min="0" max="100" value="<?= $color_settings['opacity'] ?>"
                       oninput="updateSliderValue('opacity', this.value); updatePreview()">
            </div>
            
            <!-- Blur Slider -->
            <div class="slider-group">
                <label>Background Blur: <span id="blur-value"><?= $color_settings['blur'] ?>px</span></label>
                <input type="range" name="blur" min="0" max="50" value="<?= $color_settings['blur'] ?>"
                       oninput="updateSliderValue('blur', this.value); updatePreview()">
            </div>
            
            <!-- Border Radius -->
            <div class="slider-group">
                <label>Border Radius: <span id="border_radius-value"><?= $color_settings['border_radius'] ?>px</span></label>
                <input type="range" name="border_radius" min="0" max="20" value="<?= $color_settings['border_radius'] ?>"
                       oninput="updateSliderValue('border_radius', this.value); updatePreview()">
            </div>
            
            <!-- Color Presets -->
            <div class="preset-group">
                <label>Quick Presets</label>
                <div class="preset-buttons">
                    <button type="button" onclick="applyPreset('dark')" class="preset-btn">
                        <span class="preset-preview" style="background: linear-gradient(45deg, #000000, #fbbf24);"></span>
                        Dark PayPal
                    </button>
                    <button type="button" onclick="applyPreset('light')" class="preset-btn">
                        <span class="preset-preview" style="background: linear-gradient(45deg, #ffffff, #0070ba);"></span>
                        Light PayPal
                    </button>
                    <button type="button" onclick="applyPreset('purple')" class="preset-btn">
                        <span class="preset-preview" style="background: linear-gradient(45deg, #1a1a2e, #7b2cbf);"></span>
                        Purple Theme
                    </button>
                    <button type="button" onclick="applyPreset('green')" class="preset-btn">
                        <span class="preset-preview" style="background: linear-gradient(45deg, #0d1b2a, #10b981);"></span>
                        Green Theme
                    </button>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Colors
                </button>
                <button type="button" onclick="resetToDefaults()" class="btn btn-outline">
                    <i class="fas fa-undo"></i>
                    Reset
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Color Customizer Toggle Button -->
<button id="color-customizer-toggle" onclick="toggleColorCustomizer()" class="color-toggle-btn" title="Customize Colors">
    <i class="fas fa-palette"></i>
</button>

<!-- Dynamic Color CSS -->
<style id="dynamic-colors">
:root {
    --dynamic-primary: <?= $color_settings['primary_color'] ?> !important;
    --dynamic-background: <?= $color_settings['background_color'] ?> !important;
    --dynamic-text: <?= $color_settings['text_color'] ?> !important;
    --dynamic-card: <?= $color_settings['card_color'] ?> !important;
    --dynamic-accent: <?= $color_settings['accent_color'] ?> !important;
    --dynamic-opacity: <?= $color_settings['opacity'] / 100 ?> !important;
    --dynamic-blur: <?= $color_settings['blur'] ?>px !important;
    --dynamic-radius: <?= $color_settings['border_radius'] ?>px !important;
}

/* Force apply dynamic colors */
body {
    background-color: var(--dynamic-background) !important;
    color: var(--dynamic-text) !important;
}

.btn-primary {
    background-color: var(--dynamic-primary) !important;
    border-color: var(--dynamic-primary) !important;
}

.glass-card {
    background: rgba(<?= hex2rgb($color_settings['card_color']) ?>, var(--dynamic-opacity)) !important;
    backdrop-filter: blur(var(--dynamic-blur)) !important;
    border-radius: var(--dynamic-radius) !important;
}

h1, h2, h3, h4, h5, h6, p, span, div, td, th {
    color: var(--dynamic-text) !important;
}

input, select, textarea {
    background-color: var(--dynamic-accent) !important;
    color: var(--dynamic-text) !important;
    border-color: var(--dynamic-accent) !important;
    border-radius: var(--dynamic-radius) !important;
}

.view-toggle a.btn-outline {
    color: var(--dynamic-text) !important;
    border-color: var(--dynamic-accent) !important;
}

.view-toggle a.btn-outline:hover {
    background-color: var(--dynamic-accent) !important;
}
</style>

<script>
// Color Customizer JavaScript
let colorCustomizerOpen = false;

function toggleColorCustomizer() {
    const panel = document.getElementById('color-customizer');
    colorCustomizerOpen = !colorCustomizerOpen;
    
    if (colorCustomizerOpen) {
        panel.classList.remove('hidden');
        panel.classList.add('open');
    } else {
        panel.classList.add('hidden');
        panel.classList.remove('open');
    }
}

function closeColorCustomizer() {
    document.getElementById('color-customizer').classList.add('hidden');
    document.getElementById('color-customizer').classList.remove('open');
    colorCustomizerOpen = false;
}

function updateSliderValue(name, value) {
    document.getElementById(name + '-value').textContent = value + (name === 'opacity' ? '%' : 'px');
}

function updatePreview() {
    const form = document.getElementById('color-settings-form');
    const formData = new FormData(form);
    
    // Update CSS variables in real-time
    const root = document.documentElement;
    root.style.setProperty('--dynamic-primary', formData.get('primary_color'));
    root.style.setProperty('--dynamic-background', formData.get('background_color'));
    root.style.setProperty('--dynamic-text', formData.get('text_color'));
    root.style.setProperty('--dynamic-card', formData.get('card_color'));
    root.style.setProperty('--dynamic-accent', formData.get('accent_color'));
    root.style.setProperty('--dynamic-opacity', formData.get('opacity') / 100);
    root.style.setProperty('--dynamic-blur', formData.get('blur') + 'px');
    root.style.setProperty('--dynamic-radius', formData.get('border_radius') + 'px');
    
    // Update color value displays
    document.querySelectorAll('.color-value').forEach((el, index) => {
        const input = el.previousElementSibling;
        el.textContent = input.value;
    });
}

function saveColorSettings(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'update_colors');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Colors saved successfully!', 'success');
        } else {
            showToast('Error saving colors', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving colors', 'error');
    });
}

function applyPreset(preset) {
    const presets = {
        dark: {
            primary_color: '#fbbf24',
            background_color: '#000000',
            text_color: '#ffffff',
            card_color: '#1f1f1f',
            accent_color: '#374151',
            opacity: 80,
            blur: 20,
            border_radius: 8
        },
        light: {
            primary_color: '#0070ba',
            background_color: '#ffffff',
            text_color: '#000000',
            card_color: '#f8f9fa',
            accent_color: '#e9ecef',
            opacity: 90,
            blur: 10,
            border_radius: 12
        },
        purple: {
            primary_color: '#7b2cbf',
            background_color: '#1a1a2e',
            text_color: '#ffffff',
            card_color: '#16213e',
            accent_color: '#0f3460',
            opacity: 85,
            blur: 15,
            border_radius: 10
        },
        green: {
            primary_color: '#10b981',
            background_color: '#0d1b2a',
            text_color: '#ffffff',
            card_color: '#1b263b',
            accent_color: '#415a77',
            opacity: 85,
            blur: 15,
            border_radius: 10
        }
    };
    
    const settings = presets[preset];
    if (!settings) return;
    
    // Update form inputs
    Object.keys(settings).forEach(key => {
        const input = document.querySelector(`[name="${key}"]`);
        if (input) {
            input.value = settings[key];
            if (input.type === 'range') {
                updateSliderValue(key, settings[key]);
            }
        }
    });
    
    updatePreview();
}

function resetToDefaults() {
    if (confirm('Reset all colors to default? This will reload the page.')) {
        // Clear session and reload
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=reset_colors'
        })
        .then(() => {
            window.location.reload();
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
});
</script>

<?php
function hex2rgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return implode(',', [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ]);
}

// Handle reset action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_colors') {
    unset($_SESSION['color_settings']);
    $settings_file = PAYPAL_DATA_PATH . '/color-settings.json';
    if (file_exists($settings_file)) {
        unlink($settings_file);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
?>