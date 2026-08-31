/**
 * @file SITE-ROOT/admin/js/home-page-settings.js
 * @version 2025.08.03.1700.01
 * @description Handles the new landing page type selector and saves all settings.
 */
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('home-settings-form');
    const toastContainer = document.getElementById('toast-container');
    const leftSlider = document.getElementById('left-slider');
    const rightSlider = document.getElementById('right-slider');
    const leftDisplay = document.getElementById('left-width-display');
    const rightDisplay = document.getElementById('right-width-display');
    const disableCheckbox = document.getElementById('disable-left-col-checkbox');
    const slidersContainer = document.getElementById('sliders-container');
    const homePageTypeSelect = document.getElementById('home-page-type');
    const pageManagerContainer = document.getElementById('page-manager-select-container');
    const manualPhpContainer = document.getElementById('manual-php-container');

    function showToast(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast-message ${type} show`;
        toast.textContent = message;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove());
        }, duration);
    }

    function syncSliders(sourceSlider) {
        const value = parseInt(sourceSlider.value);
        const otherValue = 100 - value;
        if (sourceSlider === leftSlider) {
            rightSlider.value = otherValue;
        } else {
            leftSlider.value = otherValue;
        }
        leftDisplay.textContent = leftSlider.value;
        rightDisplay.textContent = rightSlider.value;
    }

    function toggleSliders() {
        slidersContainer.style.opacity = disableCheckbox.checked ? '0.5' : '1';
        leftSlider.disabled = disableCheckbox.checked;
        rightSlider.disabled = disableCheckbox.checked;
    }

    function toggleHomePageInputs() {
        if (homePageTypeSelect.value === 'manual_php') {
            manualPhpContainer.style.display = 'block';
            pageManagerContainer.style.display = 'none';
        } else {
            manualPhpContainer.style.display = 'none';
            pageManagerContainer.style.display = 'block';
        }
    }

    leftSlider.addEventListener('input', () => syncSliders(leftSlider));
    rightSlider.addEventListener('input', () => syncSliders(rightSlider));
    disableCheckbox.addEventListener('change', toggleSliders);
    homePageTypeSelect.addEventListener('change', toggleHomePageInputs);

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        showToast('Saving settings...', 'info');
        const formData = new FormData(form);
        formData.append('action', 'save_home_settings');

        if (homePageTypeSelect.value === 'manual_php') {
            formData.set('home_page_slug', formData.get('manual_php_file'));
        }
        formData.delete('manual_php_file');

        try {
            const response = await fetch('ajax/save_settings.php', { method: 'POST', body: formData });
            if (!response.ok) throw new Error(`Server error: ${response.statusText}`);
            const result = await response.json();
            if (result.success) {
                showToast('Home page settings saved successfully!', 'success');
            } else {
                throw new Error(result.message || 'An unknown error occurred.');
            }
        } catch (error) {
            showToast(`Error: ${error.message}`, 'error');
        }
    });

    toggleSliders();
    toggleHomePageInputs();
});