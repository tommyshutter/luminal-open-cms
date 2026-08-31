/*
 * The Site - Admin Bulk Action JavaScript
 * File Path: /admin/js/drag-drop-and-select.js
 * Version: 2025.06.30.16.25.00
 * Description: Handles drag-and-drop file uploads and bulk selection for media items.
 * Author: Gemini
 */
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.querySelector('input[type="file"][multiple]');
    const fileListDisplay = document.getElementById('drop-zone-files');
    const uploadForm = document.getElementById('upload-form');

    if (dropZone && fileInput && uploadForm) {
        // Open file dialog when drop zone is clicked
        dropZone.addEventListener('click', () => {
            fileInput.click();
        });

        // Highlight drop zone on drag over
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        // Remove highlight on drag leave
        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        });

        // Handle dropped files
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files.length) {
                fileInput.files = files;
                updateFileList();
            }
        });

        // Update file list when files are selected via dialog
        fileInput.addEventListener('change', () => {
            updateFileList();
        });
        
        function updateFileList() {
            if (fileInput.files.length > 0) {
                let fileNames = Array.from(fileInput.files).map(f => f.name).join(', ');
                fileListDisplay.textContent = `${fileInput.files.length} file(s) selected: ${fileNames}`;
            } else {
                fileListDisplay.textContent = '';
            }
        }
    }

    // Bulk selection logic
    const selectAllCheckbox = document.getElementById('select-all');
    const itemCheckboxes = document.querySelectorAll('.media-item-checkbox');
    const mediaItems = document.querySelectorAll('.media-item');

    if (selectAllCheckbox && itemCheckboxes.length > 0) {
        selectAllCheckbox.addEventListener('change', function() {
            itemCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                const mediaItem = checkbox.closest('.media-item');
                if (mediaItem) {
                    mediaItem.classList.toggle('selected', this.checked);
                }
            });
        });

        itemCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const mediaItem = this.closest('.media-item');
                if (mediaItem) {
                    mediaItem.classList.toggle('selected', this.checked);
                }
                // Uncheck "Select All" if any item is unchecked
                if (!this.checked) {
                    selectAllCheckbox.checked = false;
                }
            });
        });
        
        mediaItems.forEach(item => {
            item.addEventListener('click', function(e) {
                // Allow clicking the checkbox directly without toggling twice
                if (e.target.type !== 'checkbox') {
                    const checkbox = item.querySelector('.media-item-checkbox');
                    if(checkbox) {
                        checkbox.checked = !checkbox.checked;
                        // Manually trigger change event to update styles
                        checkbox.dispatchEvent(new Event('change'));
                    }
                }
            });
        });
    }
});
