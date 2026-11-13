<?php
/**
 * Drag & Drop Screenshot Uploader with Preview
 */
$uploadDir = '/tmp/';
$uploaded = false;
$uploadedFile = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['screenshot'])) {
    $file = $_FILES['screenshot'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($ext, $allowed)) {
            $timestamp = date('YmdHis');
            $filename = 'screenshot-' . $timestamp . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                chmod($targetPath, 0644);
                $uploaded = true;
                $uploadedFile = $filename;
            } else {
                $error = 'Failed to save file';
            }
        } else {
            $error = 'Invalid file type. Use JPG, PNG, GIF, or WebP.';
        }
    } else {
        $error = 'Upload error: ' . $file['error'];
    }
}

// Show uploaded image if requested
if (isset($_GET['show']) && $_GET['show']) {
    $file = $uploadDir . basename($_GET['show']);
    if (file_exists($file)) {
        $mime = mime_content_type($file);
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Screenshot Upload</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            color: #3498db;
            margin-bottom: 30px;
            text-align: center;
            font-size: 28px;
        }
        .drop-zone {
            border: 3px dashed #3498db;
            border-radius: 12px;
            padding: 60px 40px;
            text-align: center;
            background: #2a2a2a;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 30px;
        }
        .drop-zone:hover, .drop-zone.drag-over {
            background: #34495e;
            border-color: #27ae60;
            transform: scale(1.02);
        }
        .drop-zone-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .drop-zone-text {
            font-size: 18px;
            color: #aaa;
            margin-bottom: 15px;
        }
        .drop-zone-subtext {
            font-size: 14px;
            color: #666;
        }
        .file-input {
            display: none;
        }
        .preview-area {
            display: none;
            background: #2a2a2a;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .preview-area.active {
            display: block;
        }
        .preview-image {
            width: 100%;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            margin-bottom: 20px;
        }
        .preview-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #1e1e1e;
            border-radius: 8px;
        }
        .preview-filename {
            font-family: 'Courier New', monospace;
            color: #60a5fa;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-success {
            background: #27ae60;
            color: white;
        }
        .btn-success:hover {
            background: #229954;
        }
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .success-message {
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
            padding: 20px 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
            font-size: 16px;
        }
        .error-message {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            padding: 20px 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
        }
        .uploading {
            text-align: center;
            padding: 40px;
            font-size: 18px;
            color: #3498db;
        }
        .spinner {
            border: 4px solid #333;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .file-path {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            padding: 15px;
            border-radius: 6px;
            color: #60a5fa;
            margin-top: 15px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📸 Screenshot Upload</h1>

        <?php if ($uploaded): ?>
            <div class="success-message">
                ✓ Screenshot uploaded successfully!
            </div>
            <div class="preview-area active">
                <img src="?show=<?= urlencode($uploadedFile) ?>" class="preview-image" alt="Uploaded screenshot">
                <div class="preview-info">
                    <div class="preview-filename">📁 <?= htmlspecialchars($uploadedFile) ?></div>
                    <button class="btn btn-success" onclick="window.location.reload()">Upload Another</button>
                </div>
                <div class="file-path">
                    Server path: /tmp/<?= htmlspecialchars($uploadedFile) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">✗ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="drop-zone" id="dropZone">
            <div class="drop-zone-icon">📤</div>
            <div class="drop-zone-text">Drag & Drop Screenshot Here</div>
            <div class="drop-zone-subtext">or click to browse</div>
        </div>

        <input type="file" id="fileInput" class="file-input" accept="image/*">

        <div class="preview-area" id="previewArea">
            <img id="previewImage" class="preview-image" alt="Preview">
            <div class="preview-info">
                <div class="preview-filename" id="previewFilename"></div>
                <div>
                    <button class="btn btn-success" id="uploadBtn">Upload</button>
                    <button class="btn btn-danger" id="cancelBtn">Cancel</button>
                </div>
            </div>
        </div>

        <div class="uploading" id="uploadingMessage" style="display:none">
            <div class="spinner"></div>
            Uploading...
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const previewArea = document.getElementById('previewArea');
        const previewImage = document.getElementById('previewImage');
        const previewFilename = document.getElementById('previewFilename');
        const uploadBtn = document.getElementById('uploadBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const uploadingMessage = document.getElementById('uploadingMessage');
        let selectedFile = null;

        // Click to browse
        dropZone.addEventListener('click', () => fileInput.click());

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('drag-over');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });

        // File input change
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFile(e.target.files[0]);
            }
        });

        // Handle file preview
        function handleFile(file) {
            if (!file.type.startsWith('image/')) {
                alert('Please select an image file');
                return;
            }

            selectedFile = file;
            const reader = new FileReader();

            reader.onload = (e) => {
                previewImage.src = e.target.result;
                previewFilename.textContent = '📁 ' + file.name;
                dropZone.style.display = 'none';
                previewArea.classList.add('active');
            };

            reader.readAsDataURL(file);
        }

        // Upload button
        uploadBtn.addEventListener('click', () => {
            if (!selectedFile) return;

            const formData = new FormData();
            formData.append('screenshot', selectedFile);

            previewArea.style.display = 'none';
            uploadingMessage.style.display = 'block';

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                alert('Upload failed: ' + error.message);
                uploadingMessage.style.display = 'none';
                previewArea.style.display = 'block';
            });
        });

        // Cancel button
        cancelBtn.addEventListener('click', () => {
            selectedFile = null;
            fileInput.value = '';
            previewArea.classList.remove('active');
            dropZone.style.display = 'block';
        });
    </script>
</body>
</html>
