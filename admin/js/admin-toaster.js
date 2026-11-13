/**
 * =============================================================================
 * @appname  Earl's Admin Panel — Global Toaster & Persistent Console Logger
 * @file     /admin/js/admin-toaster.js
 * @version  2025.09.29.PERSISTENT-LOG
 * @purpose  Shows floating toasts and sends messages to a server-side log file.
 * =============================================================================
 */
(function() {
    if (window.RIToaster) return;

    let toastContainer = null;

    function createToastContainer() {
        if (document.getElementById('ri-toast-container')) return;
        const container = document.createElement('div');
        container.id = 'ri-toast-container';
        // (Styling for the container remains the same)
        container.style.cssText = `position:fixed;top:20px;right:20px;z-index:99999;width:320px;display:flex;flex-direction:column;gap:10px;`;
        document.body.appendChild(container);
        toastContainer = container;
    }

    // Server-side logging (disabled — legacy PayPal endpoint removed)
    async function logToServer(level, message) {
        // no-op: log only to browser console
    }

    function showToast(level, message) {
        if (!toastContainer) createToastContainer();

        const toastElement = document.createElement('div');
        toastElement.className = `ri-toast toast-${level}`;
        toastElement.textContent = message;

        const styles = {
            base: `padding:12px 16px; border-radius:8px; font-family:sans-serif; font-size:14px; color:white; box-shadow:0 4px 12px rgba(0,0,0,0.15); opacity:0; transform:translateX(100%); transition:all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);`,
            info: 'background-color:#2d3748;',
            success: 'background-color:#2f855a;',
            warn: 'background-color:#b7791f;',
            error: 'background-color:#c53030;'
        };
        toastElement.style.cssText = styles.base + (styles[level] || styles.info);
        toastContainer.insertBefore(toastElement, toastContainer.firstChild);

        setTimeout(() => {
            toastElement.style.opacity = '1';
            toastElement.style.transform = 'translateX(0)';
        }, 10);

        setTimeout(() => {
            toastElement.style.opacity = '0';
            toastElement.style.transform = 'translateX(100%)';
            toastElement.addEventListener('transitionend', () => toastElement.remove());
        }, 5000);
    }
    
    // The new global toaster function
    const showToastAndLog = (level, message) => {
        showToast(level, message);
        logToServer(level, message);
    };

    window.RIToaster = {
        info: (msg) => showToastAndLog('info', msg),
        success: (msg) => showToastAndLog('success', msg),
        warn: (msg) => showToastAndLog('warn', msg),
        error: (msg) => showToastAndLog('error', msg)
    };
    
    // Initial log to the file to confirm it's working
    logToServer('success', 'RIToaster initialized and connected to server log.');
})();

