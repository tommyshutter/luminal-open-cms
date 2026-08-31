/**
 * @file         SITE-ROOT/admin/js/utils/toast.js
 * @version      1.0.0 (2025-09-05)
 * @author       Gemini
 * @license      Apache-2.0 — see LICENSE and NOTICE
 * @description  A simple utility for displaying toast notifications.
 */

/**
 * Displays a toast notification message.
 * @param {string} message - The message to display.
 * @param {string} type - The type of message ('success', 'error', 'info').
 * @param {number} duration - How long the toast should be visible in milliseconds.
 */
export function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toast-notification');
    if (!container) {
        console.error('Toast notification container not found.');
        return;
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;

    container.appendChild(toast);

    // Animate in
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);

    // Hide and remove after duration
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => {
            toast.remove();
        });
    }, duration);
}