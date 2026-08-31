/**
 * @file SITE-ROOT/admin/js/text-manager.js
 * @version 2025.08.03.0935.01
 * @description Complete file with all CKEditor-related code fully removed.
 */
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('text-box-form');
    const titleInput = document.getElementById('text-box-title');
    const contentTextarea = document.getElementById('text-box-content');
    const currentFilenameInput = document.getElementById('current-filename');
    const createNewBtn = document.getElementById('create-new-box-btn');
    const deleteBtn = document.getElementById('delete-box-btn');
    const listContainer = document.getElementById('existing-boxes-list');
    const adminMessages = document.getElementById('admin-messages');
    const editorHeading = document.getElementById('editor-heading');
    
    // CKEditor initialization is REMOVED.

    function showMessage(message, type = 'success') {
        adminMessages.textContent = message;
        adminMessages.className = `message-container ${type} active`;
        setTimeout(() => { adminMessages.classList.remove('active'); }, 4000);
    }

    function resetForm() {
        form.reset();
        currentFilenameInput.value = '';
        deleteBtn.style.display = 'none';
        editorHeading.textContent = "Create New Text Box";
        titleInput.focus();
    }
    
    async function loadBoxForEditing(filename) {
        try {
            const response = await fetch(`ajax/text_handler.php?action=load&filename=${filename}`);
            if (!response.ok) throw new Error('Network error or file not found.');
            const result = await response.json();
            if (!result.success) throw new Error(result.message);

            resetForm();
            titleInput.value = result.data.title || '';
            contentTextarea.value = result.data.content || ''; // Set value on the textarea directly
            currentFilenameInput.value = filename;
            deleteBtn.style.display = 'inline-block';
            editorHeading.textContent = `Editing: ${result.data.title}`;
        } catch (error) {
            showMessage(error.message, 'error');
        }
    }

    async function handleSave(e) {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', 'save');
        // The textarea content is already included in FormData, no special handling needed.

        try {
            const response = await fetch('ajax/text_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) throw new Error(result.message);

            showMessage(result.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } catch (error) {
            showMessage(error.message, 'error');
        }
    }

    async function handleDelete() {
        const filename = currentFilenameInput.value;
        if (!filename || !confirm('Are you sure you want to permanently delete this text box? This cannot be undone.')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('filename', filename);

        try {
            const response = await fetch('ajax/text_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) throw new Error(result.message);

            showMessage(result.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } catch (error) {
            showMessage(error.message, 'error');
        }
    }

    form.addEventListener('submit', handleSave);
    createNewBtn.addEventListener('click', resetForm);
    deleteBtn.addEventListener('click', handleDelete);
    listContainer.addEventListener('click', (e) => {
        const link = e.target.closest('.edit-box-link');
        if (link) {
            e.preventDefault();
            loadBoxForEditing(link.dataset.filename);
        }
    });

    resetForm();
});