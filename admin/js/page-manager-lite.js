/**
 * @appname      Page Manager Logic
 * @file         SITE-ROOT/admin/js/page-manager-lite.js
 * @version      2025.08.30.1215.00-EST
 * @author       Gemini
 * @license      Apache-2.0 — see LICENSE and NOTICE
 * @description  Client-side logic for the Page Manager. FIX: Correctly inserts shortcodes into the last-focused editor (left or right).
 */

document.addEventListener('DOMContentLoaded', () => {
    // --- Element Refs ---
    const form = document.getElementById('ri-page-form');
    const pageTitleInput = document.getElementById('page_title');
    const pageNameHidden = document.getElementById('page_name');
    const leftContent = document.getElementById('left_content');
    const rightContent = document.getElementById('right_content');
    const rightEnabledCheckbox = document.getElementById('right_enabled');
    const rightEditorWrap = document.getElementById('right-editor-wrap');
    const saveBtn = document.getElementById('btn-save');
    const newBtn = document.getElementById('btn-new');
    const viewBtn = document.getElementById('btn-view');
    const deleteBtn = document.getElementById('btn-delete');
    const messagesDiv = document.getElementById('ri-messages');
    
    const leftPctSlider = document.getElementById('left_pct');
    const leftPctLbl = document.getElementById('left_pct_lbl');
    const rightPctLbl = document.getElementById('right_pct_lbl');

    // --- STATE TRACKING ---
    // **FIX: Track the last focused editor to solve the insertion bug.**
    let lastFocusedEditor = leftContent; // Default to left editor on load.

    // --- Functions ---
    function updateWidths() {
        if (!leftPctSlider || !leftPctLbl || !rightPctLbl) return;
        const leftVal = parseInt(leftPctSlider.value, 10);
        const rightVal = 100 - leftVal;
        leftPctLbl.textContent = `${leftVal}%`;
        rightPctLbl.textContent = `${rightVal}%`;
    }

    function showToast(message, isError = false) {
        const toast = document.getElementById('toast-notification');
        if (!toast) return;

        // Clear any existing timeout
        if (toast._hideTimeout) clearTimeout(toast._hideTimeout);

        toast.textContent = message;
        toast.className = (isError ? 'error' : 'success');

        // Trigger reflow for animation restart
        void toast.offsetWidth;

        // Show with slide-in
        toast.classList.add('show');

        // Hide after delay with slide-out
        toast._hideTimeout = setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    function resetForm() {
        if (form) form.reset();
        if (pageNameHidden) pageNameHidden.value = '';
        if (viewBtn) viewBtn.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'none';

        const revList = document.getElementById('ri-revisions-list');
        if (revList) revList.innerHTML = '<div class="row-line"><em>Load a page...</em></div>';

        updateWidths();

        if (rightEditorWrap && rightEnabledCheckbox) {
            rightEditorWrap.style.display = rightEnabledCheckbox.checked ? 'block' : 'none';
        }

        lastFocusedEditor = leftContent; // Reset focus tracker

        // Reset any pill highlights
        document.querySelectorAll('.pm-pill, .js-open, .js-sc-insert').forEach(p => {
            p.style.background = '';
            p.style.color = '';
        });

        if (pageTitleInput) pageTitleInput.focus();
    }

    async function loadPage(slug) {
        try {
            // Fade out editors during load
            if (leftContent) leftContent.style.opacity = '0.5';
            if (rightContent) rightContent.style.opacity = '0.5';

            const response = await fetch(`?__load=1&slug=${encodeURIComponent(slug)}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const pageData = await response.json();

            // Don't call resetForm() here as it hides viewBtn - just clear fields manually
            if (pageNameHidden) pageNameHidden.value = '';

            const left = pageData.components?.main_content?.content ?? pageData.left_content ?? '';
            const right = pageData.components?.right_column?.content ?? pageData.right_content ?? '';

            if (pageTitleInput) pageTitleInput.value = pageData.page_title || '';
            if (leftContent) leftContent.value = left;
            if (rightContent) rightContent.value = right;
            if (pageNameHidden) pageNameHidden.value = slug;

            if (rightEnabledCheckbox) rightEnabledCheckbox.checked = pageData.right_column_enabled !== false;

            // Use getElementById for checkboxes that may be outside the form
            const useWrapperCb = document.getElementById('use_wrapper');
            const includeBgCb = document.getElementById('include_bg');
            if (useWrapperCb) useWrapperCb.checked = pageData.use_wrapper !== false;
            if (includeBgCb) includeBgCb.checked = pageData.include_bg !== false;

            if (leftPctSlider) leftPctSlider.value = pageData.left_width || 65;
            updateWidths();

            if (rightEditorWrap) rightEditorWrap.style.display = (rightEnabledCheckbox && rightEnabledCheckbox.checked) ? 'block' : 'none';

            // Show view and delete buttons
            if (viewBtn) {
                viewBtn.href = `/page.php?p=${slug}`;
                viewBtn.style.display = 'inline-block';
            }
            if (deleteBtn) {
                deleteBtn.style.display = 'inline-block';
            }

            // Fade editors back in
            if (leftContent) leftContent.style.opacity = '1';
            if (rightContent) rightContent.style.opacity = '1';

            // Reset pill highlights
            document.querySelectorAll('.pm-pill, .js-open').forEach(p => {
                p.style.background = '';
                p.style.color = '';
            });

            showToast(`Loaded: ${pageData.page_title || slug}`);
        } catch (error) {
            console.error('Error loading page:', error);
            // Restore opacity on error
            if (leftContent) leftContent.style.opacity = '1';
            if (rightContent) rightContent.style.opacity = '1';
            showToast('Failed to load page data: ' + error.message, true);
        }
    }

    // --- Event Listeners ---
    if (saveBtn) {
        saveBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.ok) {
                    showToast('Page saved successfully!');
                    if (result.slug) {
                        pageNameHidden.value = result.slug;
                        if (viewBtn) {
                            viewBtn.href = `/page.php?p=${result.slug}`;
                            viewBtn.style.display = 'inline-block';
                        }
                        if (deleteBtn) {
                            deleteBtn.style.display = 'inline-block';
                        }
                    }
                } else { throw new Error(result.error || 'Unknown error'); }
            } catch (error) {
                showToast(`Save failed: ${error.message}`, true);
            }
        });
    }

    if (newBtn) {
        newBtn.addEventListener('click', (e) => {
            e.preventDefault();
            resetForm();
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const slug = pageNameHidden ? pageNameHidden.value : '';
            if (!slug) {
                showToast('No page loaded to delete', true);
                return;
            }
            if (!confirm(`Delete page "${slug}"? This will move it to trash.`)) return;

            try {
                const fd = new FormData();
                fd.append('__pm_action', 'delete_page');
                fd.append('slug', slug);

                const response = await fetch('', { method: 'POST', body: fd });
                const result = await response.json();

                if (result.ok) {
                    showToast(`Page "${slug}" deleted`);
                    resetForm();
                    // Remove the pill from the list
                    const pill = document.querySelector(`.js-open[data-page="${slug}"]`);
                    if (pill) pill.remove();
                } else {
                    throw new Error(result.error || 'Delete failed');
                }
            } catch (error) {
                showToast(`Delete failed: ${error.message}`, true);
            }
        });
    }

    if (rightEnabledCheckbox && rightEditorWrap) {
        rightEnabledCheckbox.addEventListener('change', () => {
            rightEditorWrap.style.display = rightEnabledCheckbox.checked ? 'block' : 'none';
        });
    }

    if (leftPctSlider) {
        leftPctSlider.addEventListener('input', updateWidths);
    }

    // **FIX: Add focus listeners to both textareas to track the last active one.**
    if (leftContent) {
        leftContent.addEventListener('focus', () => {
            lastFocusedEditor = leftContent;
        });
    }
    if (rightContent) {
        rightContent.addEventListener('focus', () => {
            lastFocusedEditor = rightContent;
        });
    }

    document.body.addEventListener('click', (e) => {
        if (e.target.classList.contains('js-open')) {
            e.preventDefault();
            e.stopPropagation();
            const slug = e.target.dataset.page;
            const pill = e.target;

            if (slug) {
                // Visual feedback
                pill.style.background = '#00d2ff';
                pill.style.color = '#000';
                loadPage(slug);
            }
        }
        
        if (e.target.classList.contains('js-del')) {
            e.preventDefault();
            const slug = e.target.dataset.page;
            if (slug && confirm(`Are you sure you want to delete the page '${slug}'? This cannot be undone.`)) {
                // Deletion logic...
            }
        }
        
        if (e.target.classList.contains('js-sc-insert')) {
            e.preventDefault();
            e.stopPropagation();
            const code = e.target.dataset.code;
            const pill = e.target;

            // **FIX: Use the tracked 'lastFocusedEditor' instead of 'document.activeElement'.**
            if (lastFocusedEditor && code) {
                const start = lastFocusedEditor.selectionStart;
                const end = lastFocusedEditor.selectionEnd;
                const text = lastFocusedEditor.value;

                // Insert the code at the cursor's position (no extra newlines)
                lastFocusedEditor.value = text.substring(0, start) + code + text.substring(end);

                // Move the cursor to after the inserted text and re-focus the editor
                lastFocusedEditor.selectionStart = lastFocusedEditor.selectionEnd = start + code.length;
                lastFocusedEditor.focus();

                // Visual feedback on pill
                pill.style.background = '#00d2ff';
                pill.style.color = '#000';
                setTimeout(() => {
                    pill.style.background = '';
                    pill.style.color = '';
                }, 200);

                showToast(`Inserted: ${code}`);
            }
        }
    });

    // --- Initial State ---
    resetForm();
});