/**
 * @file SITE-ROOT/admin/js/gallery-manager.js
 * @version 2025.08.06.1207.EDT
 * @description Handles AJAX calls for the standalone gallery manager.
 */
document.addEventListener('DOMContentLoaded', function() {
    const scanPhotosBtn = document.getElementById('scan-photos-btn');
    const scanVideosBtn = document.getElementById('scan-videos-btn');
    const photosOutput = document.getElementById('photos-output');
    const videosOutput = document.getElementById('videos-output');

    async function runScanner(type, button, outputElement) {
        outputElement.textContent = `Scanning for ${type}...`;
        button.disabled = true;

        const formData = new FormData();
        formData.append('type', type);

        try {
            const response = await fetch('ajax/gallery_scanner.php', {
                method: 'POST',
                body: formData
            });
            if (!response.ok) {
                throw new Error(`Server error: ${response.statusText}`);
            }
            const result = await response.json();
            outputElement.textContent = result.log;

        } catch (error) {
            outputElement.textContent = `A critical error occurred: ${error.message}`;
        } finally {
            button.disabled = false;
        }
    }

    scanPhotosBtn.addEventListener('click', (e) => {
        runScanner('photos', e.target, photosOutput);
    });

    scanVideosBtn.addEventListener('click', (e) => {
        runScanner('videos', e.target, videosOutput);
    });
});
