/**
 * Menu Manager - Dark Glassmorphism Theme
 * File: /admin/menu-manager/js/menu-manager.js
 * Version: 2025.10.24.235903
 * 
 * Features:
 * - Drag & drop reordering
 * - Inline editing of labels and URLs
 * - Quick add from pills
 * - External link modal
 * - Live preview of styling
 * - Settings persistence
 */

(function() {
    'use strict';
    
    // DOM Elements
    const list = document.getElementById('menu-list');
    const saveBtn = document.getElementById('mm-save');
    const statusBox = document.getElementById('mm-status');
    const pillsBox = document.getElementById('pill-list');
    const addExternalBtn = document.getElementById('mm-add-external');
    const modal = document.getElementById('mm-modal');
    const modalClose = document.getElementById('mm-modal-close');
    const modalCancel = document.getElementById('mm-modal-cancel');
    const modalAdd = document.getElementById('mm-modal-add');
    const externalTitle = document.getElementById('external-title');
    const externalUrl = document.getElementById('external-url');
    const preview = document.getElementById('mm-preview');
    const saveSettingsBtn = document.getElementById('mm-save-settings');
    
    if (!list) return;
    
    // State
    let draggingEl = null;
    
    /**
     * Set status message with styling
     */
    function setStatus(msg, level) {
        if (!statusBox) return;
        statusBox.textContent = msg;
        statusBox.className = 'mm-status ' + (level || '');
        
        // Auto-clear after 5 seconds
        setTimeout(() => {
            if (statusBox.textContent === msg) {
                statusBox.textContent = '';
                statusBox.className = 'mm-status';
            }
        }, 5000);
    }
    
    /**
     * HTML escape utility
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    /**
     * Check if slug already exists in menu
     */
    function alreadyInMenu(slug) {
        return !!list.querySelector(`.mm-item[data-slug="${CSS.escape(slug)}"]`);
    }
    
    /**
     * Generate slug from external URL
     */
    function generateSlugFromUrl(url) {
        try {
            const urlObj = new URL(url);
            return urlObj.hostname.replace(/^www\./, '').replace(/\./g, '-');
        } catch (e) {
            return 'external-' + Date.now();
        }
    }
    
    /**
     * Create menu item element
     */
    function createMenuItem(title, url, slug, type) {
        const li = document.createElement('li');
        const isExternal = type === 'external' || url.startsWith('http');
        
        li.className = 'mm-item';
        li.draggable = true;
        li.setAttribute('data-slug', slug);
        li.setAttribute('data-url', url);
        li.setAttribute('data-title', title);
        li.setAttribute('data-type', type || 'page');
        
        const externalBadge = isExternal ? '<span class="mm-badge mm-badge-external">External</span>' : '';
        const urlEditable = isExternal ? 'true' : 'false';
        
        li.innerHTML = `
            <span class="mm-drag-handle" aria-label="Drag to reorder">⋮⋮</span>
            <div class="mm-item-content">
                <div class="mm-item-label" contenteditable="true" data-original="${escapeHtml(title)}">
                    ${escapeHtml(title)}
                </div>
                <div class="mm-item-url" contenteditable="${urlEditable}" data-original="${escapeHtml(url)}"
                     title="${isExternal ? 'Click to edit URL' : 'Internal page URL'}">
                    ${escapeHtml(url)}
                </div>
                ${externalBadge}
            </div>
            <button class="mm-btn mm-btn-danger mm-remove" title="Remove from menu">×</button>
        `;
        
        return li;
    }
    
    /* ========================================
       DRAG & DROP FUNCTIONALITY
       ======================================== */
    
    list.addEventListener('dragstart', function(e) {
        const li = e.target.closest('.mm-item');
        if (!li) return;
        
        draggingEl = li;
        e.dataTransfer.effectAllowed = 'move';
        li.classList.add('dragging');
    });
    
    list.addEventListener('dragend', function(e) {
        if (draggingEl) {
            draggingEl.classList.remove('dragging');
        }
        draggingEl = null;
    });
    
    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        if (!draggingEl) return;
        
        const afterElement = getDragAfterElement(list, e.clientY);
        
        if (afterElement == null) {
            list.appendChild(draggingEl);
        } else {
            list.insertBefore(draggingEl, afterElement);
        }
    });
    
    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.mm-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    
    /* ========================================
       REMOVE BUTTON
       ======================================== */
    
    list.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('mm-remove')) {
            const li = e.target.closest('.mm-item');
            if (li) {
                const title = li.getAttribute('data-title');
                li.remove();
                setStatus(`Removed: ${title}`, 'warn');
            }
        }
    });
    
    /* ========================================
       INLINE EDITING
       ======================================== */
    
    list.addEventListener('blur', function(e) {
        if (e.target.classList.contains('mm-item-label') || 
            e.target.classList.contains('mm-item-url')) {
            
            const li = e.target.closest('.mm-item');
            if (!li) return;
            
            const newValue = e.target.textContent.trim();
            const original = e.target.getAttribute('data-original');
            
            if (newValue !== original) {
                if (e.target.classList.contains('mm-item-label')) {
                    li.setAttribute('data-title', newValue);
                    setStatus('Updated label', 'success');
                } else if (e.target.classList.contains('mm-item-url')) {
                    li.setAttribute('data-url', newValue);
                    setStatus('Updated URL', 'success');
                }
                e.target.setAttribute('data-original', newValue);
            }
        }
    }, true);
    
    /* ========================================
       PILLS: QUICK ADD
       ======================================== */
    
    if (pillsBox) {
        pillsBox.addEventListener('click', function(e) {
            const pill = e.target.closest('.mm-pill');
            if (!pill) return;
            
            const slug = pill.getAttribute('data-slug') || '';
            const url = pill.getAttribute('data-url') || '';
            const title = pill.getAttribute('data-title') || '';
            const type = pill.getAttribute('data-type') || 'page';
            
            if (!slug || alreadyInMenu(slug)) {
                setStatus(`Already in menu: ${title}`, 'info');
                return;
            }
            
            const li = createMenuItem(title, url, slug, type);
            list.appendChild(li);
            pill.remove();
            setStatus(`Added: ${title}`, 'success');
        });
    }
    
    /* ========================================
       EXTERNAL LINK MODAL
       ======================================== */
    
    if (addExternalBtn && modal) {
        addExternalBtn.addEventListener('click', function() {
            modal.style.display = 'flex';
            externalTitle.value = '';
            externalUrl.value = '';
            externalTitle.focus();
        });
        
        function closeModal() {
            modal.style.display = 'none';
        }
        
        if (modalClose) modalClose.addEventListener('click', closeModal);
        if (modalCancel) modalCancel.addEventListener('click', closeModal);
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
        
        if (modalAdd) {
            modalAdd.addEventListener('click', function() {
                const title = externalTitle.value.trim();
                const url = externalUrl.value.trim();
                
                if (!title || !url) {
                    setStatus('Please fill in both fields', 'error');
                    return;
                }
                
                if (!url.startsWith('http://') && !url.startsWith('https://')) {
                    setStatus('URL must start with http:// or https://', 'error');
                    return;
                }
                
                const slug = generateSlugFromUrl(url);
                
                if (alreadyInMenu(slug)) {
                    setStatus('This link already exists in the menu', 'warn');
                    return;
                }
                
                const li = createMenuItem(title, url, slug, 'external');
                list.appendChild(li);
                closeModal();
                setStatus(`Added external link: ${title}`, 'success');
            });
        }
    }
    
    /* ========================================
       SAVE MENU
       ======================================== */
    
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            const items = [...list.querySelectorAll('.mm-item')];
            const payload = items.map(li => ({
                title: li.getAttribute('data-title') || '',
                url: li.getAttribute('data-url') || '',
                slug: li.getAttribute('data-slug') || '',
                type: li.getAttribute('data-type') || 'page'
            })).filter(item => item.title && item.url && item.slug);
            
            setStatus('Saving menu...', 'info');
            
            fetch('/admin/modules/MenuManager/menu-manager.save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.ok) {
                    setStatus(`Saved ${resp.count || 0} menu items`, 'success');
                } else {
                    setStatus(`Save failed: ${resp.error || 'unknown error'}`, 'error');
                }
            })
            .catch(err => {
                setStatus(`Save error: ${err.message}`, 'error');
            });
        });
    }
    
    /* ========================================
       TABS
       ======================================== */

    function initTabs() {
        const tabTriggers = document.querySelectorAll('.mm-tab-trigger');
        const tabContents = document.querySelectorAll('.mm-tab-content');

        tabTriggers.forEach(trigger => {
            trigger.addEventListener('click', () => {
                const tabId = trigger.getAttribute('data-tab');

                // Update triggers
                tabTriggers.forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                trigger.classList.add('active');
                trigger.setAttribute('aria-selected', 'true');

                // Update content panels
                tabContents.forEach(content => {
                    content.classList.remove('active');
                });
                const targetContent = document.getElementById('tab-' + tabId);
                if (targetContent) {
                    targetContent.classList.add('active');
                }
            });
        });
    }

    initTabs();

    /* ========================================
       COLLAPSIBLES
       ======================================== */

    function initCollapsibles() {
        const collapsibles = document.querySelectorAll('.mm-collapsible');

        collapsibles.forEach(collapsible => {
            const header = collapsible.querySelector('.mm-collapsible-header');
            if (!header) return;

            header.addEventListener('click', () => {
                const isOpen = collapsible.classList.contains('open');
                collapsible.classList.toggle('open');
                header.setAttribute('aria-expanded', !isOpen);
            });
        });
    }

    initCollapsibles();

    /* ========================================
       COLOR HEX SYNC
       ======================================== */

    function initColorHexSync() {
        const colorPairs = [
            ['menu-font-color', 'menu-font-color-hex'],
            ['menu-font-hover-color', 'menu-font-hover-color-hex'],
            ['menu-current-font-color', 'menu-current-font-color-hex'],
            ['menu-bg-color', 'menu-bg-color-hex'],
            ['menu-item-bg-color', 'menu-item-bg-color-hex'],
            ['menu-item-hover-bg-color', 'menu-item-hover-bg-color-hex'],
            ['menu-current-item-bg-color', 'menu-current-item-bg-color-hex']
        ];

        colorPairs.forEach(([colorId, hexId]) => {
            const colorInput = document.getElementById(colorId);
            const hexInput = document.getElementById(hexId);

            if (!colorInput || !hexInput) return;

            // Color picker → Hex input
            colorInput.addEventListener('input', () => {
                hexInput.value = colorInput.value.toUpperCase();
                updatePreview();
            });

            // Hex input → Color picker
            hexInput.addEventListener('input', () => {
                let val = hexInput.value.trim();
                if (!val.startsWith('#')) val = '#' + val;
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    colorInput.value = val;
                    updatePreview();
                }
            });

            hexInput.addEventListener('blur', () => {
                // Normalize on blur
                hexInput.value = colorInput.value.toUpperCase();
            });
        });
    }

    initColorHexSync();

    /* ========================================
       SLIDER NUMBER SYNC
       ======================================== */

    function initSliderNumberSync() {
        const sliderPairs = [
            ['menu-font-size', 'menu-font-size-input'],
            ['menu-font-weight', 'menu-font-weight-input'],
            ['menu-bg-opacity', 'menu-bg-opacity-input'],
            ['menu-item-bg-opacity', 'menu-item-bg-opacity-input'],
            ['menu-item-hover-bg-opacity', 'menu-item-hover-bg-opacity-input'],
            ['menu-current-item-bg-opacity', 'menu-current-item-bg-opacity-input']
        ];

        sliderPairs.forEach(([sliderId, inputId]) => {
            const slider = document.getElementById(sliderId);
            const input = document.getElementById(inputId);

            if (!slider || !input) return;

            // Slider → Number input
            slider.addEventListener('input', () => {
                input.value = slider.value;
                updatePreview();
            });

            // Number input → Slider
            input.addEventListener('input', () => {
                const min = parseFloat(slider.min);
                const max = parseFloat(slider.max);
                let val = parseFloat(input.value);

                if (!isNaN(val)) {
                    val = Math.max(min, Math.min(max, val));
                    slider.value = val;
                    updatePreview();
                }
            });

            input.addEventListener('blur', () => {
                // Clamp and sync on blur
                const min = parseFloat(slider.min);
                const max = parseFloat(slider.max);
                let val = parseFloat(input.value);
                if (isNaN(val)) val = parseFloat(slider.value);
                val = Math.max(min, Math.min(max, val));
                input.value = val;
                slider.value = val;
            });
        });
    }

    initSliderNumberSync();

    /* ========================================
       ALIGNMENT CONTROL
       ======================================== */

    function initAlignmentControl() {
        const alignmentOptions = document.querySelectorAll('.mm-alignment-option');

        alignmentOptions.forEach(option => {
            option.addEventListener('click', () => {
                alignmentOptions.forEach(opt => opt.classList.remove('active'));
                option.classList.add('active');
                updatePreview();
            });
        });
    }

    initAlignmentControl();

    /* ========================================
       PREVIEW & SETTINGS
       ======================================== */

    const fontFamily = document.getElementById('menu-font-family');
    const fontSize = document.getElementById('menu-font-size');
    const fontWeight = document.getElementById('menu-font-weight');
    const fontStyle = document.getElementById('menu-font-style');
    const fontColor = document.getElementById('menu-font-color');
    const fontHoverColor = document.getElementById('menu-font-hover-color');
    const currentFontColor = document.getElementById('menu-current-font-color');
    const bgColor = document.getElementById('menu-bg-color');
    const bgOpacity = document.getElementById('menu-bg-opacity');
    const itemBgColor = document.getElementById('menu-item-bg-color');
    const itemBgOpacity = document.getElementById('menu-item-bg-opacity');
    const itemHoverBgColor = document.getElementById('menu-item-hover-bg-color');
    const itemHoverBgOpacity = document.getElementById('menu-item-hover-bg-opacity');
    const currentItemBgColor = document.getElementById('menu-current-item-bg-color');
    const currentItemBgOpacity = document.getElementById('menu-current-item-bg-opacity');

    function updatePreview() {
        if (!preview) return;

        const previewMenu = preview.querySelector('.mm-preview-menu');
        const previewLinks = preview.querySelectorAll('.mm-preview-menu li a');

        // Menu alignment
        if (previewMenu) {
            const checkedAlignment = document.querySelector('input[name="menu_alignment"]:checked');
            const alignment = checkedAlignment ? checkedAlignment.value : 'left';
            previewMenu.setAttribute('data-alignment', alignment);
        }

        // Style each preview item (matches front page .site-menu > li > a)
        previewLinks.forEach(link => {
            const li = link.parentElement;

            // Typography (applied to all items)
            if (fontFamily) link.style.fontFamily = fontFamily.value;
            if (fontSize) link.style.fontSize = fontSize.value + 'px';
            if (fontStyle) link.style.fontStyle = fontStyle.value;

            // Default state styling
            if (fontColor) link.style.color = fontColor.value;
            if (fontWeight) link.style.fontWeight = fontWeight.value;
            if (itemBgColor && itemBgOpacity) {
                const rgb = hexToRgb(itemBgColor.value);
                link.style.background = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${itemBgOpacity.value})`;
            }

            // Active/current state (matches .site-menu > li.active > a)
            if (li.classList.contains('preview-active')) {
                if (currentFontColor) link.style.color = currentFontColor.value;
                if (currentItemBgColor && currentItemBgOpacity) {
                    const rgb = hexToRgb(currentItemBgColor.value);
                    link.style.background = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${currentItemBgOpacity.value})`;
                }
                link.style.fontWeight = '800'; // Matches front page
            }

            // Hover state (matches .site-menu > li > a:hover)
            if (li.classList.contains('preview-hover')) {
                if (fontHoverColor) link.style.color = fontHoverColor.value;
                if (itemHoverBgColor && itemHoverBgOpacity) {
                    const rgb = hexToRgb(itemHoverBgColor.value);
                    link.style.background = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${itemHoverBgOpacity.value})`;
                }
            }
        });
    }

    function hexToRgb(hex) {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : { r: 0, g: 0, b: 0 };
    }

    // Add listeners to all controls
    [fontFamily, fontSize, fontWeight, fontStyle, fontColor, fontHoverColor, currentFontColor,
     bgColor, bgOpacity, itemBgColor, itemBgOpacity, itemHoverBgColor, itemHoverBgOpacity,
     currentItemBgColor, currentItemBgOpacity].forEach(control => {
        if (control) {
            control.addEventListener('input', updatePreview);
            control.addEventListener('change', updatePreview);
        }
    });

    // Font family: clickable samples and dropdown sync
    const fontSamples = document.querySelectorAll('.mm-font-sample');

    function updateFontSampleHighlight() {
        if (!fontFamily) return;
        const selectedFont = fontFamily.value;
        fontSamples.forEach(sample => {
            if (sample.dataset.font === selectedFont) {
                sample.classList.add('active');
            } else {
                sample.classList.remove('active');
            }
        });
    }

    // Click on font sample → update dropdown and preview
    fontSamples.forEach(sample => {
        sample.addEventListener('click', () => {
            const font = sample.dataset.font;
            if (fontFamily) {
                fontFamily.value = font;
                updateFontSampleHighlight();
                updatePreview();
            }
        });
    });

    // Dropdown change → update sample highlights
    if (fontFamily) {
        fontFamily.addEventListener('change', () => {
            updateFontSampleHighlight();
            updatePreview();
        });
    }

    // Initial preview update
    updatePreview();
    
    /* ========================================
       SAVE SETTINGS
       ======================================== */
    
    const menuDisabled = document.getElementById('menu-disabled');

    if (saveSettingsBtn) {
        saveSettingsBtn.addEventListener('click', function() {
            const checkedAlignment = document.querySelector('input[name="menu_alignment"]:checked');

            const settings = {
                menu_font_family: fontFamily ? fontFamily.value : '',
                menu_font_size: fontSize ? parseInt(fontSize.value) : 15,
                menu_font_weight: fontWeight ? parseInt(fontWeight.value) : 500,
                menu_font_style: fontStyle ? fontStyle.value : 'normal',
                menu_font_color: fontColor ? fontColor.value : '#ffffff',
                menu_font_hover_color: fontHoverColor ? fontHoverColor.value : '#60a5fa',
                menu_current_item_font_color: currentFontColor ? currentFontColor.value : '#ffffff',
                menu_bg_color: bgColor ? bgColor.value : '#0a0a0a',
                menu_bg_opacity: bgOpacity ? parseFloat(bgOpacity.value) : 0.4,
                menu_item_bg_color: itemBgColor ? itemBgColor.value : '#ffffff',
                menu_item_bg_opacity: itemBgOpacity ? parseFloat(itemBgOpacity.value) : 0.03,
                menu_item_hover_bg_color: itemHoverBgColor ? itemHoverBgColor.value : '#3b82f6',
                menu_item_hover_bg_opacity: itemHoverBgOpacity ? parseFloat(itemHoverBgOpacity.value) : 0.2,
                menu_current_item_bg_color: currentItemBgColor ? currentItemBgColor.value : '#ffffff',
                menu_current_item_bg_opacity: currentItemBgOpacity ? parseFloat(currentItemBgOpacity.value) : 0.1,
                menu_alignment: checkedAlignment ? checkedAlignment.value : 'left',
                menu_disabled: menuDisabled ? menuDisabled.checked : false
            };

            setStatus('Saving settings...', 'info');

            fetch('/admin/modules/MenuManager/api/save_menu_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(settings),
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.success) {
                    setStatus('Settings saved successfully', 'success');
                } else {
                    setStatus(`Failed to save settings: ${resp.error || 'unknown error'}`, 'error');
                }
            })
            .catch(err => {
                setStatus(`Settings save error: ${err.message}`, 'error');
            });
        });
    }
    
})();
