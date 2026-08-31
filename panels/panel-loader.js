/**
 * @filelocation    SITE-ROOT/panels/panel-loader.js
 * @version         2025.08.04.0904.EDT.3
 */

/**
 * Panel Loader
 * Dynamically loads panels into specified containers.
 * The 'panelsToLoad' array should be defined in the main HTML file before this script.
 *
 * Each object in 'panelsToLoad' should have:
 * - id: The ID of the container element to load the panel into.
 * - name: The name of the panel file (without 'panel-' prefix or '.php' suffix).
 * - params: (Optional) An object of additional key-value pairs to send as URL parameters.
 */
document.addEventListener('DOMContentLoaded', () => {
    if (window.panelsToLoad && Array.isArray(window.panelsToLoad)) {
        window.panelsToLoad.forEach(panelInfo => {
            const container = document.getElementById(panelInfo.id);
            if (container) {
                let params = new URLSearchParams(panelInfo.params || {});
                params.set('panel', panelInfo.name);

                // **BUG FIX**: Pass the parent page's full URL to the panel loading script.
                // This allows the panel to build links that point back to the main page,
                // instead of the panel loader script itself.
                params.set('parent_href', window.location.href);


                const url = `/panels/load-panel.php?${params.toString()}`;

                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.text();
                    })
                    .then(html => {
                        container.innerHTML = html;
                        // After loading, dispatch a custom event to signal completion
                        const event = new CustomEvent('panelLoaded', { detail: { containerId: panelInfo.id, panelName: panelInfo.name } });
                        document.dispatchEvent(event);
                    })
                    .catch(error => {
                        container.innerHTML = `<div class="error">Error loading panel: ${panelInfo.name}. ${error.message}</div>`;
                        console.error('Error loading panel:', error);
                    });
            } else {
                console.warn(`Panel container with id '${panelInfo.id}' not found.`);
            }
        });
    }
});