<?php
/**
 * PayPal Shopping Cart System - Background Customizer Component
 * Dark Theme Background Controls
 * 
 * File: /admin/paypal/includes/paypal-background-customizer.php
 * Version: 2025.10.01
 */

if (!defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}
?>

<!-- Background Customizer -->
<div id="background-customizer" class="bg-controls hidden">
    <div class="glass-card">
        <div class="p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-medium text-white flex items-center gap-2">
                    <i class="fas fa-palette text-yellow-400"></i>
                    Background
                </h3>
                <button 
                    onclick="toggleBackgroundCustomizer()"
                    class="btn btn-outline w-6 h-6 p-0"
                >
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
            
            <!-- Color Picker -->
            <div class="space-y-3">
                <div>
                    <label class="text-sm font-medium text-gray-300 mb-2 block">Color</label>
                    <div class="color-picker-wrapper">
                        <input
                            type="color"
                            id="bg-color-picker"
                            value="#000000"
                            class="color-picker"
                            onchange="updateBackgroundColor(this.value)"
                        />
                        <div 
                            id="color-preview"
                            class="color-preview"
                            style="background-color: #000000;"
                        >
                            #000000
                        </div>
                    </div>
                    
                    <!-- Color Presets -->
                    <div class="grid grid-cols-6 gap-1 mt-2">
                        <button onclick="setPresetColor('#ffffff')" style="background:#ffffff" class="preset-color" title="White"></button>
                        <button onclick="setPresetColor('#000000')" style="background:#000000" class="preset-color" title="Black"></button>
                        <button onclick="setPresetColor('#f3f4f6')" style="background:#f3f4f6" class="preset-color" title="Light Gray"></button>
                        <button onclick="setPresetColor('#374151')" style="background:#374151" class="preset-color" title="Dark Gray"></button>
                        <button onclick="setPresetColor('#1f2937')" style="background:#1f2937" class="preset-color" title="Darker Gray"></button>
                        <button onclick="setPresetColor('#dc2626')" style="background:#dc2626" class="preset-color" title="Red"></button>
                        <button onclick="setPresetColor('#ea580c')" style="background:#ea580c" class="preset-color" title="Orange"></button>
                        <button onclick="setPresetColor('#ca8a04')" style="background:#ca8a04" class="preset-color" title="Yellow"></button>
                        <button onclick="setPresetColor('#16a34a')" style="background:#16a34a" class="preset-color" title="Green"></button>
                        <button onclick="setPresetColor('#2563eb')" style="background:#2563eb" class="preset-color" title="Blue"></button>
                        <button onclick="setPresetColor('#7c3aed')" style="background:#7c3aed" class="preset-color" title="Purple"></button>
                        <button onclick="setPresetColor('#db2777')" style="background:#db2777" class="preset-color" title="Pink"></button>
                    </div>
                </div>

                <!-- Opacity Slider -->
                <div>
                    <div class="slider-label">
                        <label class="text-sm font-medium text-gray-300">Opacity</label>
                        <span id="opacity-value" class="text-xs text-gray-400">80%</span>
                    </div>
                    <input
                        type="range"
                        id="bg-opacity-slider"
                        min="0"
                        max="100"
                        value="80"
                        step="5"
                        class="slider w-full"
                        oninput="updateBackgroundOpacity(this.value)"
                    />
                </div>

                <!-- Blur Slider -->
                <div>
                    <div class="slider-label">
                        <label class="text-sm font-medium text-gray-300">Blur</label>
                        <span id="blur-value" class="text-xs text-gray-400">10px</span>
                    </div>
                    <input
                        type="range"
                        id="bg-blur-slider"
                        min="0"
                        max="50"
                        value="10"
                        step="1"
                        class="slider w-full"
                        oninput="updateBackgroundBlur(this.value)"
                    />
                </div>

                <!-- Reset Button -->
                <button
                    onclick="resetBackground()"
                    class="btn btn-outline w-full text-xs"
                >
                    Reset to Default
                </button>

                <!-- Current Settings Display -->
                <div class="text-xs text-gray-500 space-y-1 border-t border-white/10 pt-3">
                    <div>Color: <span id="current-color">#000000</span></div>
                    <div>Opacity: <span id="current-opacity">80%</span></div>
                    <div>Blur: <span id="current-blur">10px</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Background Customizer Toggle Button -->
<button
    id="bg-customizer-toggle"
    onclick="toggleBackgroundCustomizer()"
    class="fixed bottom-4 right-4 z-40 btn btn-outline w-12 h-12 p-0 rounded-full shadow-lg"
    title="Background Customizer"
>
    <i class="fas fa-palette"></i>
</button>

<style>
/* Additional styles for the background customizer */
.preset-color {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: all 0.2s ease;
}

.preset-color:hover {
    border-color: var(--primary);
    transform: scale(1.1);
}

.preset-color.active {
    border-color: var(--primary);
    transform: scale(1.1);
}

.slider {
    -webkit-appearance: none;
    appearance: none;
    height: 4px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 2px;
    outline: none;
    cursor: pointer;
}

.slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 16px;
    height: 16px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid #000;
}

.slider::-moz-range-thumb {
    width: 16px;
    height: 16px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid #000;
}

/* Background customizer positioning */
.bg-controls {
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.3s ease;
}

.bg-controls.hidden {
    transform: translateX(calc(100% + 1rem)) translateY(-50%);
}

@media (max-width: 768px) {
    .bg-controls {
        right: 0.5rem;
        width: 180px;
    }
    
    #bg-customizer-toggle {
        bottom: 1rem;
        right: 1rem;
    }
}
</style>

<script>
// Background Customizer JavaScript
let bgSettings = {
    color: '#000000',
    opacity: 80,
    blur: 10
};

// Load settings from localStorage on page load
document.addEventListener('DOMContentLoaded', function() {
    loadBackgroundSettings();
    updateBackgroundPreview();
});

function toggleBackgroundCustomizer() {
    const customizer = document.getElementById('background-customizer');
    customizer.classList.toggle('hidden');
}

function loadBackgroundSettings() {
    const saved = localStorage.getItem('paypal_bg_settings');
    if (saved) {
        try {
            bgSettings = JSON.parse(saved);
            
            // Update UI controls
            document.getElementById('bg-color-picker').value = bgSettings.color;
            document.getElementById('bg-opacity-slider').value = bgSettings.opacity;
            document.getElementById('bg-blur-slider').value = bgSettings.blur;
            
            updateUI();
        } catch (e) {
            console.error('Failed to load background settings:', e);
        }
    }
}

function saveBackgroundSettings() {
    localStorage.setItem('paypal_bg_settings', JSON.stringify(bgSettings));
}

function updateBackgroundColor(color) {
    bgSettings.color = color;
    updateUI();
    updateBackgroundPreview();
    saveBackgroundSettings();
}

function updateBackgroundOpacity(opacity) {
    bgSettings.opacity = parseInt(opacity);
    updateUI();
    updateBackgroundPreview();
    saveBackgroundSettings();
}

function updateBackgroundBlur(blur) {
    bgSettings.blur = parseInt(blur);
    updateUI();
    updateBackgroundPreview();
    saveBackgroundSettings();
}

function setPresetColor(color) {
    document.getElementById('bg-color-picker').value = color;
    updateBackgroundColor(color);
    
    // Update active preset
    document.querySelectorAll('.preset-color').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

function resetBackground() {
    bgSettings = {
        color: '#000000',
        opacity: 80,
        blur: 10
    };
    
    // Update UI controls
    document.getElementById('bg-color-picker').value = bgSettings.color;
    document.getElementById('bg-opacity-slider').value = bgSettings.opacity;
    document.getElementById('bg-blur-slider').value = bgSettings.blur;
    
    updateUI();
    updateBackgroundPreview();
    saveBackgroundSettings();
    
    showToast('Background reset to default', 'success');
}

function updateUI() {
    // Update color preview
    const colorPreview = document.getElementById('color-preview');
    colorPreview.style.backgroundColor = bgSettings.color;
    colorPreview.textContent = bgSettings.color.toUpperCase();
    
    // Update slider values
    document.getElementById('opacity-value').textContent = bgSettings.opacity + '%';
    document.getElementById('blur-value').textContent = bgSettings.blur + 'px';
    
    // Update current settings display
    document.getElementById('current-color').textContent = bgSettings.color.toUpperCase();
    document.getElementById('current-opacity').textContent = bgSettings.opacity + '%';
    document.getElementById('current-blur').textContent = bgSettings.blur + 'px';
}

function updateBackgroundPreview() {
    const root = document.documentElement;
    
    // Apply CSS custom properties
    root.style.setProperty('--custom-bg-color', bgSettings.color);
    root.style.setProperty('--custom-bg-opacity', (bgSettings.opacity / 100));
    root.style.setProperty('--custom-bg-blur', bgSettings.blur + 'px');
    
    // Force refresh of background container
    const bgContainer = document.querySelector('.custom-bg-container');
    if (bgContainer) {
        bgContainer.style.background = `color-mix(in srgb, ${bgSettings.color} ${bgSettings.opacity}%, transparent)`;
        bgContainer.style.backdropFilter = `blur(${bgSettings.blur}px)`;
        bgContainer.style.webkitBackdropFilter = `blur(${bgSettings.blur}px)`;
    }
}

// Auto-hide customizer when clicking outside
document.addEventListener('click', function(e) {
    const customizer = document.getElementById('background-customizer');
    const toggle = document.getElementById('bg-customizer-toggle');
    
    if (!customizer.contains(e.target) && !toggle.contains(e.target) && !customizer.classList.contains('hidden')) {
        customizer.classList.add('hidden');
    }
});
</script>