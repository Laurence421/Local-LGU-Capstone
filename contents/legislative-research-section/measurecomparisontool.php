<?php
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
$pageTitle = "Document Comparison Tool";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - LGU Research System</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/super_styles.css')) ?>">
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>
<?php include __DIR__ . '/../../includes/super_header.php'; ?>
<?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<style>
    body {
        background: linear-gradient(135deg, #f0f4f7 0%, #e8eef3 100%);
        font-family: 'Montserrat', system-ui, -apple-system, Arial, sans-serif;
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }
    
    .comparison-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
        margin-top: 20px;
    }
    
    .page-header {
        text-align: center;
        margin-bottom: 2.5rem;
        position: relative;
    }
    
    .page-header h1 {
        color: #004a8b;
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.75rem;
        text-shadow: 0 2px 4px rgba(0, 74, 139, 0.1);
        letter-spacing: -0.5px;
    }
    
    .page-header p {
        color: #5a6c7d;
        font-size: 1.1rem;
        font-weight: 500;
    }
    
    .page-header::after {
        content: '';
        display: block;
        width: 80px;
        height: 4px;
        background: linear-gradient(90deg, #004a8b, #115272);
        margin: 1.5rem auto 0;
        border-radius: 2px;
    }
    
    .comparison-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08), 0 0 1px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        border: 1px solid rgba(0, 74, 139, 0.08);
        transition: all 0.3s ease;
    }
    
    .comparison-card:hover {
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.12), 0 0 1px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
    
    .card-header {
        background: linear-gradient(135deg, #004a8b 0%, #115272 50%, #0b3d6d 100%);
        color: white;
        padding: 2rem 2.5rem;
        position: relative;
        overflow: hidden;
    }
    
    .card-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.6s ease;
    }
    
    .card-header:hover::before {
        left: 100%;
    }
    
    .card-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -0.3px;
    }
    
    .card-header p {
        margin: 0.75rem 0 0 0;
        opacity: 0.95;
        font-size: 0.95rem;
        font-weight: 400;
    }
    
    .card-body {
        padding: 2rem;
    }
    
    .upload-section {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        margin-bottom: 2rem;
    }
    
    @media (max-width: 768px) {
        .upload-section {
            grid-template-columns: 1fr;
        }
    }
    
    .upload-box {
        border: 3px dashed #c5d3e0;
        border-radius: 16px;
        padding: 2.5rem;
        text-align: center;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    
    .upload-box::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(0, 74, 139, 0.05);
        transform: translate(-50%, -50%);
        transition: width 0.6s ease, height 0.6s ease;
    }
    
    .upload-box:hover::before {
        width: 300px;
        height: 300px;
    }
    
    .upload-box:hover {
        border-color: #004a8b;
        background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%);
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 74, 139, 0.15);
    }
    
    .upload-box.dragover {
        border-color: #004a8b;
        background: linear-gradient(135deg, #e6f0ff 0%, #f0f7ff 100%);
        transform: scale(1.03);
        box-shadow: 0 12px 35px rgba(0, 74, 139, 0.2);
    }
    
    .upload-box.has-file {
        border-color: #28a745;
        background: linear-gradient(135deg, #f0fff4 0%, #ffffff 100%);
        box-shadow: 0 8px 25px rgba(40, 167, 69, 0.15);
    }
    
    .upload-box h3 {
        color: #004a8b;
        font-size: 1.2rem;
        margin-bottom: 1rem;
        font-weight: 700;
        position: relative;
        z-index: 1;
    }
    
    .upload-box .upload-icon {
        font-size: 3.5rem;
        color: #004a8b;
        margin-bottom: 1.25rem;
        position: relative;
        z-index: 1;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .upload-box:hover .upload-icon {
        transform: scale(1.1) translateY(-5px);
        color: #115272;
    }
    
    .upload-box p {
        color: #6b7280;
        font-size: 0.9rem;
        margin: 0.5rem 0;
    }
    
    .upload-box input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    
    .file-info {
        margin-top: 1rem;
        padding: 1rem;
        background: white;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }
    
    .file-info .file-name {
        font-weight: 600;
        color: #1f2937;
        word-break: break-all;
    }
    
    .file-info .file-size {
        color: #6b7280;
        font-size: 0.85rem;
    }
    
    .file-info .remove-file {
        color: #dc3545;
        cursor: pointer;
        font-size: 0.85rem;
        margin-top: 0.5rem;
        display: inline-block;
    }
    
    .file-info .remove-file:hover {
        text-decoration: underline;
    }
    
    .compare-btn-container {
        text-align: center;
        margin: 2rem 0;
    }
    
    .compare-btn {
        background: linear-gradient(135deg, #004a8b 0%, #115272 50%, #0b3d6d 100%);
        color: white;
        border: none;
        padding: 1.25rem 3.5rem;
        font-size: 1.15rem;
        font-weight: 700;
        border-radius: 14px;
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 8px 25px rgba(0, 74, 139, 0.3);
        position: relative;
        overflow: hidden;
        letter-spacing: 0.3px;
    }
    
    .compare-btn::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        transform: translate(-50%, -50%);
        transition: width 0.6s ease, height 0.6s ease;
    }
    
    .compare-btn:hover:not(:disabled)::before {
        width: 300px;
        height: 300px;
    }
    
    .compare-btn:hover:not(:disabled) {
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 12px 35px rgba(0, 74, 139, 0.45);
    }
    
    .compare-btn:active:not(:disabled) {
        transform: translateY(-1px) scale(0.98);
    }
    
    .compare-btn:disabled {
        background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
        cursor: not-allowed;
        box-shadow: none;
        opacity: 0.6;
    }
    
    .results-section {
        display: none;
        margin-top: 2rem;
    }
    
    .results-section.show {
        display: block;
    }
    
    .results-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .results-header h3 {
        color: #004a8b;
        margin: 0;
        font-size: 1.3rem;
    }
    
    .results-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .summary-card {
        padding: 1.5rem;
        border-radius: 12px;
        text-align: center;
    }
    
    .summary-card.identical {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        border: 1px solid #10b981;
    }
    
    .summary-card.different {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        border: 1px solid #ef4444;
    }
    
    .summary-card.info {
        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        border: 1px solid #3b82f6;
    }
    
    .summary-card .number {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    
    .summary-card.identical .number { color: #059669; }
    .summary-card.different .number { color: #dc2626; }
    .summary-card.info .number { color: #2563eb; }
    
    .summary-card .label {
        font-size: 0.9rem;
        color: #4b5563;
        font-weight: 500;
    }
    
    .comparison-results {
        background: #f8fafc;
        border-radius: 12px;
        padding: 1.5rem;
        max-height: 600px;
        overflow-y: auto;
    }
    
    .diff-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-top: 1rem;
    }
    
    @media (max-width: 992px) {
        .diff-container {
            grid-template-columns: 1fr;
        }
    }
    
    .diff-group {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        border: 1px solid #e5e7eb;
        transition: all 0.3s ease;
    }
    
    .diff-group:hover {
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }
    
    .diff-group-header {
        padding: 1rem 1.25rem;
        border-bottom: 3px solid #cbd5e1;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-transform: uppercase;
        font-size: 0.9rem;
        letter-spacing: 0.8px;
    }
    
    .diff-group-header i {
        font-size: 1.1rem;
    }
    
    /* Scanned Copy Header - Red theme */
    .diff-line.removed + .diff-line.removed .diff-group-header,
    .diff-group:has(.diff-line.removed) .diff-group-header {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border-bottom-color: #f87171;
    }
    
    .diff-group:has(.diff-line.removed) .diff-group-header i {
        color: #dc2626;
    }
    
    /* Soft Copy Header - Green theme */
    .diff-line.added + .diff-line.added .diff-group-header,
    .diff-group:has(.diff-line.added) .diff-group-header {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border-bottom-color: #34d399;
    }
    
    .diff-group:has(.diff-line.added) .diff-group-header i {
        color: #059669;
    }
    
    .diff-line {
        padding: 1rem 1.25rem;
        font-family: 'Segoe UI', 'Roboto', system-ui, sans-serif;
        font-size: 0.95rem;
        line-height: 1.8;
        position: relative;
        border-left: 4px solid transparent;
        transition: all 0.3s ease;
        word-wrap: break-word;
        white-space: pre-wrap;
    }
    
    .diff-line:hover {
        background: rgba(0, 0, 0, 0.03);
        border-left-width: 6px;
    }
    
    .diff-line.added {
        background: #f0fdf4;
        border-left-color: #10b981;
        border-left-width: 4px;
    }
    
    .diff-line.removed {
        background: #fef2f2;
        border-left-color: #ef4444;
        border-left-width: 4px;
    }
    
    .diff-line-number {
        position: absolute;
        left: 3.5rem;
        top: 0.75rem;
        color: #9ca3af;
        font-size: 0.75rem;
        min-width: 50px;
    }
    
    .diff-line-content {
        margin-left: 60px;
        word-wrap: break-word;
        white-space: pre-wrap;
    }
    
    .diff-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .diff-badge.added {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }
    
    .diff-badge.removed {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
    }
    
    /* Word-level diff highlighting - only highlight changed words */
    .diff-word-highlight {
        padding: 3px 6px;
        border-radius: 4px;
        font-weight: 600;
        display: inline-block;
        margin: 0 1px;
        transition: all 0.2s ease;
    }
    
    .diff-word-highlight:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }
    
    /* Red for removed words - Scanned Copy */
    .diff-word-highlight.removed {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fca5a5;
    }
    
    /* Yellow for altered/modified words */
    .diff-word-highlight.altered {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
    }
    
    /* Green for added words - Soft Copy */
    .diff-word-highlight.added {
        background: #d1fae5;
        color: #059669;
        border: 1px solid #6ee7b7;
    }
    
    .no-differences {
        text-align: center;
        padding: 3rem;
        color: #059669;
    }
    
    .no-differences i {
        font-size: 4rem;
        margin-bottom: 1rem;
    }
    
    .no-differences h4 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }
    
    .loading-overlay.show {
        display: flex;
    }
    
    .loading-content {
        background: white;
        padding: 3rem;
        border-radius: 16px;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }
    
    .loading-content i {
        font-size: 3rem;
        color: #004a8b;
        margin-bottom: 1rem;
    }
    
    .loading-content p {
        color: #4b5563;
        font-size: 1.1rem;
        margin: 0;
    }
    
    .supported-formats {
        margin-top: 1rem;
        padding: 1rem;
        background: #f0f7ff;
        border-radius: 8px;
        border: 1px solid #bfdbfe;
    }
    
    .supported-formats h4 {
        color: #1e40af;
        font-size: 0.9rem;
        margin: 0 0 0.5rem 0;
    }
    
    .supported-formats p {
        color: #3b82f6;
        font-size: 0.85rem;
        margin: 0;
    }
    
    .error-message {
        background: #fee2e2;
        border: 1px solid #fecaca;
        color: #dc2626;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        display: none;
    }
    
    .error-message.show {
        display: block;
    }
    
    .text-preview {
        background: #1f2937;
        color: #e5e7eb;
        padding: 1rem;
        border-radius: 8px;
        max-height: 300px;
        overflow-y: auto;
        font-family: 'Consolas', 'Monaco', monospace;
        font-size: 0.85rem;
        white-space: pre-wrap;
        word-wrap: break-word;
        margin-top: 1rem;
    }
    
    .side-by-side {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .side-by-side {
            grid-template-columns: 1fr;
        }
    }
    
    .side-panel {
        background: #f8fafc;
        border-radius: 8px;
        padding: 1rem;
    }
    
    .side-panel h4 {
        color: #004a8b;
        margin: 0 0 1rem 0;
        font-size: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .highlight-diff {
        background: #fef3c7;
        padding: 0 2px;
        border-radius: 2px;
    }
</style>

<div class="comparison-container">
    <div class="page-header">
        <h1><i class="fas fa-file-alt me-2"></i>Document Comparison Tool</h1>
        <p>Compare scanned copies with soft copies to identify differences</p>
    </div>
    
    <div class="comparison-card">
        <div class="card-header">
            <h2><i class="fas fa-balance-scale me-2"></i>Upload Documents for Comparison</h2>
            <p>Upload two documents to compare their content and identify any differences</p>
        </div>
        
        <div class="card-body">
            <div id="errorMessage" class="error-message"></div>
            
            <div class="upload-section">
                <!-- File 1: Scanned Copy -->
                <div class="upload-box" id="uploadBox1">
                    <i class="fas fa-file-image upload-icon"></i>
                    <h3>Scanned Copy</h3>
                    <p>Drag & drop or click to upload</p>
                    <p class="text-muted small">PDF, Image, or Text file</p>
                    <input type="file" id="file1" accept=".pdf,.txt,.doc,.docx,.png,.jpg,.jpeg,.gif,.bmp" onchange="handleFileSelect(1, this)">
                    <div id="fileInfo1" class="file-info" style="display: none;"></div>
                </div>
                
                <!-- File 2: Soft Copy -->
                <div class="upload-box" id="uploadBox2">
                    <i class="fas fa-file-alt upload-icon"></i>
                    <h3>Soft Copy</h3>
                    <p>Drag & drop or click to upload</p>
                    <p class="text-muted small">PDF, Image, or Text file</p>
                    <input type="file" id="file2" accept=".pdf,.txt,.doc,.docx,.png,.jpg,.jpeg,.gif,.bmp" onchange="handleFileSelect(2, this)">
                    <div id="fileInfo2" class="file-info" style="display: none;"></div>
                </div>
            </div>
            
            <div class="supported-formats">
                <h4><i class="fas fa-info-circle me-1"></i>Supported Formats</h4>
                <p><strong>Text Files:</strong> .txt, .doc, .docx | <strong>PDF:</strong> .pdf | <strong>Images:</strong> .png, .jpg, .jpeg, .gif, .bmp (OCR will be applied)</p>
            </div>
            
            <div class="compare-btn-container">
                <button class="compare-btn" id="compareBtn" onclick="compareDocuments()" disabled>
                    <i class="fas fa-search me-2"></i>Compare Documents
                </button>
            </div>
            
            <!-- Results Section -->
            <div class="results-section" id="resultsSection">
                <div class="results-header">
                    <h3><i class="fas fa-clipboard-check me-2"></i>Comparison Results</h3>
                </div>
                
                <div class="results-summary" id="resultsSummary">
                    <!-- Summary cards will be populated here -->
                </div>
                
                <div class="side-by-side" id="sideBySide">
                    <div class="side-panel">
                        <h4><i class="fas fa-file-image me-2"></i>Scanned Copy Content</h4>
                        <div id="content1" class="text-preview"></div>
                    </div>
                    <div class="side-panel">
                        <h4><i class="fas fa-file-alt me-2"></i>Soft Copy Content</h4>
                        <div id="content2" class="text-preview"></div>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <h4 style="color: #004a8b; margin-bottom: 1rem;"><i class="fas fa-exchange-alt me-2"></i>Differences Found</h4>
                    <div class="comparison-results" id="comparisonResults">
                        <!-- Diff results will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <i class="fas fa-spinner fa-spin"></i>
        <p id="loadingText">Analyzing documents...</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// Wait for PDF.js to load
window.addEventListener('load', function() {
    // Set PDF.js worker
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        console.log('PDF.js loaded successfully');
    } else {
        console.error('PDF.js failed to load');
    }
});

let file1Data = null;
let file2Data = null;
let file1Text = '';
let file2Text = '';

// Drag and drop handlers
['uploadBox1', 'uploadBox2'].forEach((boxId, index) => {
    const box = document.getElementById(boxId);
    
    box.addEventListener('dragover', (e) => {
        e.preventDefault();
        box.classList.add('dragover');
    });
    
    box.addEventListener('dragleave', () => {
        box.classList.remove('dragover');
    });
    
    box.addEventListener('drop', (e) => {
        e.preventDefault();
        box.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const input = document.getElementById(`file${index + 1}`);
            input.files = files;
            handleFileSelect(index + 1, input);
        }
    });
});

function handleFileSelect(fileNum, input) {
    const file = input.files[0];
    if (!file) return;
    
    const box = document.getElementById(`uploadBox${fileNum}`);
    const infoDiv = document.getElementById(`fileInfo${fileNum}`);
    
    // Validate file type
    const validTypes = ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/bmp',
                       'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    
    if (!validTypes.includes(file.type) && !file.name.match(/\.(txt|pdf|doc|docx|png|jpg|jpeg|gif|bmp)$/i)) {
        showError('Invalid file type. Please upload a PDF, text file, Word document, or image.');
        input.value = '';
        return;
    }
    
    // Store file data
    if (fileNum === 1) {
        file1Data = file;
    } else {
        file2Data = file;
    }
    
    // Update UI
    box.classList.add('has-file');
    infoDiv.style.display = 'block';
    infoDiv.innerHTML = `
        <div class="file-name"><i class="fas fa-file me-2"></i>${file.name}</div>
        <div class="file-size">${formatFileSize(file.size)}</div>
        <span class="remove-file" onclick="removeFile(${fileNum})"><i class="fas fa-times me-1"></i>Remove</span>
    `;
    
    // Enable compare button if both files are selected
    updateCompareButton();
}

function removeFile(fileNum) {
    const input = document.getElementById(`file${fileNum}`);
    const box = document.getElementById(`uploadBox${fileNum}`);
    const infoDiv = document.getElementById(`fileInfo${fileNum}`);
    
    input.value = '';
    box.classList.remove('has-file');
    infoDiv.style.display = 'none';
    
    if (fileNum === 1) {
        file1Data = null;
        file1Text = '';
    } else {
        file2Data = null;
        file2Text = '';
    }
    
    updateCompareButton();
}

function updateCompareButton() {
    const btn = document.getElementById('compareBtn');
    btn.disabled = !(file1Data && file2Data);
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    errorDiv.textContent = message;
    errorDiv.classList.add('show');
    setTimeout(() => {
        errorDiv.classList.remove('show');
    }, 5000);
}

function showLoading(text) {
    document.getElementById('loadingText').textContent = text;
    document.getElementById('loadingOverlay').classList.add('show');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('show');
}

async function extractTextFromFile(file) {
    const fileType = file.type;
    const fileName = file.name.toLowerCase();
    
    // Text file
    if (fileType === 'text/plain' || fileName.endsWith('.txt')) {
        return await file.text();
    }
    
    // PDF file
    if (fileType === 'application/pdf' || fileName.endsWith('.pdf')) {
        return await extractTextFromPDF(file);
    }
    
    // Image file - use OCR
    if (fileType.startsWith('image/') || fileName.match(/\.(png|jpg|jpeg|gif|bmp)$/)) {
        return await extractTextFromImage(file);
    }
    
    // Word documents - basic extraction
    if (fileName.endsWith('.docx') || fileName.endsWith('.doc')) {
        return await extractTextFromWord(file);
    }
    
    throw new Error('Unsupported file type');
}

async function extractTextFromPDF(file) {
    try {
        // Check if PDF.js is loaded
        if (typeof pdfjsLib === 'undefined') {
            throw new Error('PDF.js library is not loaded. Please refresh the page and try again.');
        }
        
        const arrayBuffer = await file.arrayBuffer();
        const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
        const pdf = await loadingTask.promise;
        let text = '';
        
        for (let i = 1; i <= pdf.numPages; i++) {
            const page = await pdf.getPage(i);
            const textContent = await page.getTextContent();
            const pageText = textContent.items.map(item => item.str).join(' ');
            text += pageText + '\n';
        }
        
        return text.trim();
    } catch (error) {
        console.error('PDF extraction error:', error);
        throw new Error('Failed to extract text from PDF: ' + error.message);
    }
}

async function extractTextFromImage(file) {
    try {
        showLoading('Performing OCR on image...');
        
        const result = await Tesseract.recognize(file, 'eng', {
            logger: m => {
                if (m.status === 'recognizing text') {
                    showLoading(`OCR Progress: ${Math.round(m.progress * 100)}%`);
                }
            }
        });
        
        return result.data.text.trim();
    } catch (error) {
        console.error('OCR error:', error);
        throw new Error('Failed to extract text from image using OCR');
    }
}

async function extractTextFromWord(file) {
    // For Word documents, we'll use a simple approach
    // In production, you might want to use a library like mammoth.js
    try {
        const arrayBuffer = await file.arrayBuffer();
        const text = new TextDecoder('utf-8').decode(arrayBuffer);
        // Extract readable text (basic approach)
        const cleanText = text.replace(/[^\x20-\x7E\n\r]/g, ' ').replace(/\s+/g, ' ').trim();
        return cleanText || 'Unable to extract text from Word document. Please convert to PDF or TXT.';
    } catch (error) {
        throw new Error('Failed to extract text from Word document');
    }
}

async function compareDocuments() {
    if (!file1Data || !file2Data) {
        showError('Please upload both files before comparing.');
        return;
    }
    
    try {
        showLoading('Extracting text from scanned copy...');
        file1Text = await extractTextFromFile(file1Data);
        
        showLoading('Extracting text from soft copy...');
        file2Text = await extractTextFromFile(file2Data);
        
        showLoading('Comparing documents...');
        
        // Perform comparison
        const results = compareTexts(file1Text, file2Text);
        
        // Display results
        displayResults(results);
        
        hideLoading();
        
    } catch (error) {
        hideLoading();
        showError(error.message || 'An error occurred during comparison.');
        console.error('Comparison error:', error);
    }
}

function compareTexts(text1, text2) {
    // Normalize texts
    const normalize = (text) => text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    
    const lines1 = normalize(text1).split('\n');
    const lines2 = normalize(text2).split('\n');
    
    // Simple line-by-line comparison
    const maxLines = Math.max(lines1.length, lines2.length);
    const differences = [];
    let matchingLines = 0;
    let differentLines = 0;
    
    for (let i = 0; i < maxLines; i++) {
        const line1 = lines1[i] || '';
        const line2 = lines2[i] || '';
        
        if (line1.trim() === line2.trim()) {
            matchingLines++;
            if (line1.trim()) {
                differences.push({
                    type: 'unchanged',
                    lineNum: i + 1,
                    content: line1
                });
            }
        } else {
            differentLines++;
            if (line1.trim() || line2.trim()) {
                if (line1.trim() && !line2.trim()) {
                    differences.push({
                        type: 'removed',
                        lineNum: i + 1,
                        content: line1,
                        source: 'scanned'
                    });
                } else if (!line1.trim() && line2.trim()) {
                    differences.push({
                        type: 'added',
                        lineNum: i + 1,
                        content: line2,
                        source: 'soft'
                    });
                } else {
                    differences.push({
                        type: 'removed',
                        lineNum: i + 1,
                        content: line1,
                        source: 'scanned'
                    });
                    differences.push({
                        type: 'added',
                        lineNum: i + 1,
                        content: line2,
                        source: 'soft'
                    });
                }
            }
        }
    }
    
    // Calculate similarity percentage
    const totalChars = Math.max(text1.length, text2.length);
    const similarity = totalChars > 0 ? calculateSimilarity(text1, text2) : 100;
    
    return {
        text1: text1,
        text2: text2,
        differences: differences,
        matchingLines: matchingLines,
        differentLines: differentLines,
        totalLines: maxLines,
        similarity: similarity,
        isIdentical: text1.trim() === text2.trim()
    };
}

function calculateSimilarity(str1, str2) {
    // Levenshtein distance based similarity
    const longer = str1.length > str2.length ? str1 : str2;
    const shorter = str1.length > str2.length ? str2 : str1;
    
    if (longer.length === 0) return 100;
    
    // For very long strings, use a sampling approach
    if (longer.length > 10000) {
        // Sample comparison
        const sampleSize = 1000;
        let matches = 0;
        for (let i = 0; i < sampleSize; i++) {
            const idx = Math.floor(Math.random() * shorter.length);
            if (longer.includes(shorter.substring(idx, idx + 10))) {
                matches++;
            }
        }
        return Math.round((matches / sampleSize) * 100);
    }
    
    // Simple character matching for shorter strings
    let matches = 0;
    const words1 = str1.toLowerCase().split(/\s+/);
    const words2 = str2.toLowerCase().split(/\s+/);
    const wordSet2 = new Set(words2);
    
    words1.forEach(word => {
        if (wordSet2.has(word)) matches++;
    });
    
    return Math.round((matches / Math.max(words1.length, words2.length)) * 100);
}

function displayResults(results) {
    const resultsSection = document.getElementById('resultsSection');
    const summaryDiv = document.getElementById('resultsSummary');
    const content1Div = document.getElementById('content1');
    const content2Div = document.getElementById('content2');
    const comparisonDiv = document.getElementById('comparisonResults');
    
    // Show results section
    resultsSection.classList.add('show');
    
    // Display summary
    summaryDiv.innerHTML = `
        <div class="summary-card ${results.isIdentical ? 'identical' : 'different'}">
            <div class="number">${results.isIdentical ? '✓' : '✗'}</div>
            <div class="label">${results.isIdentical ? 'Documents Match' : 'Differences Found'}</div>
        </div>
        <div class="summary-card info">
            <div class="number">${results.similarity}%</div>
            <div class="label">Similarity</div>
        </div>
        <div class="summary-card ${results.differentLines > 0 ? 'different' : 'identical'}">
            <div class="number">${results.differentLines}</div>
            <div class="label">Different Lines</div>
        </div>
        <div class="summary-card info">
            <div class="number">${results.totalLines}</div>
            <div class="label">Total Lines</div>
        </div>
    `;
    
    // Display content previews
    content1Div.textContent = results.text1 || '(No text extracted)';
    content2Div.textContent = results.text2 || '(No text extracted)';
    
    // Display differences
    if (results.isIdentical) {
        comparisonDiv.innerHTML = `
            <div class="no-differences">
                <i class="fas fa-check-circle"></i>
                <h4>Documents are Identical</h4>
                <p>No differences were found between the scanned copy and soft copy.</p>
            </div>
        `;
    } else {
        // Group differences by line number for better readability
        const diffGroups = [];
        let currentGroup = null;
        
        results.differences.forEach(diff => {
            if (diff.type !== 'unchanged') {
                if (!currentGroup || currentGroup.lineNum !== diff.lineNum) {
                    if (currentGroup) {
                        diffGroups.push(currentGroup);
                    }
                    currentGroup = {
                        lineNum: diff.lineNum,
                        diffs: []
                    };
                }
                currentGroup.diffs.push(diff);
            }
        });
        
        if (currentGroup) {
            diffGroups.push(currentGroup);
        }
        
        if (diffGroups.length === 0) {
            comparisonDiv.innerHTML = '<p class="text-muted text-center py-3">Minor formatting differences detected.</p>';
        } else {
            let diffHtml = '';
            
            diffGroups.forEach(group => {
                // Find the corresponding line from the other document for comparison
                const addedDiff = group.diffs.find(d => d.type === 'added');
                const removedDiff = group.diffs.find(d => d.type === 'removed');
                
                // Create a row container for side-by-side comparison
                diffHtml += '<div class="diff-container">';
                
                // Scanned Copy (Left Side)
                if (removedDiff) {
                    const otherText = addedDiff?.content || '';
                    const content = highlightDifferences(removedDiff.content, 'removed', otherText);
                    diffHtml += `
                        <div class="diff-group">
                            <div class="diff-group-header">
                                <i class="fas fa-file-image"></i>
                                <span>Scanned Copy - Line ${group.lineNum}</span>
                            </div>
                            <div class="diff-line removed">
                                <div class="diff-line-content">
                                    ${content}
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    // Empty placeholder if no scanned copy diff
                    diffHtml += `
                        <div class="diff-group">
                            <div class="diff-group-header">
                                <i class="fas fa-file-image"></i>
                                <span>Scanned Copy - Line ${group.lineNum}</span>
                            </div>
                            <div class="diff-line">
                                <div class="diff-line-content" style="color: #9ca3af; font-style: italic;">
                                    (No content in scanned copy)
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // Soft Copy (Right Side)
                if (addedDiff) {
                    const otherText = removedDiff?.content || '';
                    const content = highlightDifferences(addedDiff.content, 'added', otherText);
                    diffHtml += `
                        <div class="diff-group">
                            <div class="diff-group-header">
                                <i class="fas fa-file-alt"></i>
                                <span>Soft Copy - Line ${group.lineNum}</span>
                            </div>
                            <div class="diff-line added">
                                <div class="diff-line-content">
                                    ${content}
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    // Empty placeholder if no soft copy diff
                    diffHtml += `
                        <div class="diff-group">
                            <div class="diff-group-header">
                                <i class="fas fa-file-alt"></i>
                                <span>Soft Copy - Line ${group.lineNum}</span>
                            </div>
                            <div class="diff-line">
                                <div class="diff-line-content" style="color: #9ca3af; font-style: italic;">
                                    (No content in soft copy)
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                diffHtml += '</div>'; // Close row container
            });
            
            comparisonDiv.innerHTML = diffHtml;
        }
    }
    
    // Scroll to results
    resultsSection.scrollIntoView({ behavior: 'smooth' });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Context-aware word-level diff with local window comparison
function highlightDifferences(text, type, otherText = '') {
    if (!otherText) {
        return escapeHtml(text);
    }
    
    // Helper to normalize word for comparison
    const normalizeWord = (word) => {
        return word.toLowerCase().replace(/[^\w]/g, '').trim();
    };
    
    // Split into words
    const words = text.split(/\s+/).filter(w => w.trim());
    const otherWords = otherText.split(/\s+/).filter(w => w.trim());
    
    const WINDOW_SIZE = 5; // Look at 5 words before and after for context
    
    // Build result with context-aware highlighting
    let result = '';
    
    for (let i = 0; i < words.length; i++) {
        const word = words[i];
        const wordNormalized = normalizeWord(word);
        
        if (!wordNormalized) {
            result += escapeHtml(word);
            if (i < words.length - 1) result += ' ';
            continue;
        }
        
        // Check if this word exists in the local context window of the other text
        // Local context = same position +/- WINDOW_SIZE words
        const startPos = Math.max(0, i - WINDOW_SIZE);
        const endPos = Math.min(otherWords.length, i + WINDOW_SIZE + 1);
        
        let foundInContext = false;
        for (let j = startPos; j < endPos; j++) {
            if (normalizeWord(otherWords[j]) === wordNormalized) {
                foundInContext = true;
                break;
            }
        }
        
        if (foundInContext) {
            // Word exists in local context - NO HIGHLIGHT
            result += escapeHtml(word);
        } else {
            // Word doesn't exist in local context - HIGHLIGHT IT
            const highlightType = type === 'added' ? 'added' : 'removed';
            result += `<mark class="diff-word-highlight ${highlightType}">${escapeHtml(word)}</mark>`;
        }
        
        // Add space after word (except for last word)
        if (i < words.length - 1) {
            result += ' ';
        }
    }
    
    return result;
}

// Check if two words are similar (for detecting alterations)
function areSimilar(word1, word2) {
    // Normalize words (remove punctuation, spaces, lowercase, trim)
    const normalize = (w) => w.toLowerCase().replace(/[^\w]/g, '').trim();
    const w1 = normalize(word1);
    const w2 = normalize(word2);
    
    // If they're identical after normalization, they're not "similar" - they're the same
    if (w1 === w2) {
        return false;
    }
    
    // If one is empty, not similar
    if (!w1 || !w2) {
        return false;
    }
    
    // If one contains the other (for partial matches), they're similar
    if (w1.includes(w2) || w2.includes(w1)) {
        return true;
    }
    
    // Simple Levenshtein distance check for typos
    const maxLen = Math.max(w1.length, w2.length);
    const distance = levenshteinDistance(w1, w2);
    
    // If distance is less than 50% of max length, consider similar (more strict threshold)
    // This catches typos like "Herrera" vs "Hererra" but not completely different words
    return distance / maxLen < 0.5;
}

// Calculate Levenshtein distance between two strings
function levenshteinDistance(str1, str2) {
    const matrix = [];
    
    for (let i = 0; i <= str2.length; i++) {
        matrix[i] = [i];
    }
    
    for (let j = 0; j <= str1.length; j++) {
        matrix[0][j] = j;
    }
    
    for (let i = 1; i <= str2.length; i++) {
        for (let j = 1; j <= str1.length; j++) {
            if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
                matrix[i][j] = matrix[i - 1][j - 1];
            } else {
                matrix[i][j] = Math.min(
                    matrix[i - 1][j - 1] + 1,
                    matrix[i][j - 1] + 1,
                    matrix[i - 1][j] + 1
                );
            }
        }
    }
    
    return matrix[str2.length][str1.length];
}

function downloadReport() {
    if (!file1Text && !file2Text) {
        showError('No comparison results to download.');
        return;
    }
    
    const results = compareTexts(file1Text, file2Text);
    
    // Check if jsPDF is loaded
    if (typeof window.jspdf === 'undefined') {
        showError('PDF library not loaded. Please refresh the page and try again.');
        return;
    }
    
    showLoading('Generating PDF report...');
    
    try {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        let yPos = 20;
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        const margin = 15;
        const maxWidth = pageWidth - (margin * 2);
        
        // Helper function to add new page if needed
        const checkPageBreak = (requiredSpace = 10) => {
            if (yPos + requiredSpace > pageHeight - 20) {
                doc.addPage();
                yPos = 20;
                return true;
            }
            return false;
        };
        
        // Helper function to add wrapped text
        const addWrappedText = (text, fontSize = 10, isBold = false) => {
            doc.setFontSize(fontSize);
            doc.setFont('helvetica', isBold ? 'bold' : 'normal');
            const lines = doc.splitTextToSize(text, maxWidth);
            lines.forEach(line => {
                checkPageBreak();
                doc.text(line, margin, yPos);
                yPos += fontSize * 0.5;
            });
        };
        
        // Title
        doc.setFillColor(0, 74, 139);
        doc.rect(0, 0, pageWidth, 30, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(20);
        doc.setFont('helvetica', 'bold');
        doc.text('DOCUMENT COMPARISON REPORT', pageWidth / 2, 20, { align: 'center' });
        
        yPos = 40;
        doc.setTextColor(0, 0, 0);
        
        // Metadata
        doc.setFontSize(10);
        doc.setFont('helvetica', 'normal');
        doc.text(`Generated: ${new Date().toLocaleString()}`, margin, yPos);
        yPos += 6;
        doc.text(`Generated By: <?php echo $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'System User'; ?>`, margin, yPos);
        yPos += 10;
        
        // Summary Section
        doc.setFillColor(240, 240, 240);
        doc.rect(margin, yPos, maxWidth, 8, 'F');
        doc.setFontSize(14);
        doc.setFont('helvetica', 'bold');
        doc.text('SUMMARY', margin + 2, yPos + 6);
        yPos += 12;
        
        doc.setFontSize(10);
        doc.setFont('helvetica', 'normal');
        doc.text(`Documents Match: ${results.isIdentical ? 'YES' : 'NO'}`, margin, yPos);
        yPos += 6;
        doc.text(`Similarity: ${results.similarity}%`, margin, yPos);
        yPos += 6;
        doc.text(`Different Lines: ${results.differentLines}`, margin, yPos);
        yPos += 6;
        doc.text(`Total Lines: ${results.totalLines}`, margin, yPos);
        yPos += 12;
        
        // File Information
        checkPageBreak(20);
        doc.setFillColor(240, 240, 240);
        doc.rect(margin, yPos, maxWidth, 8, 'F');
        doc.setFontSize(14);
        doc.setFont('helvetica', 'bold');
        doc.text('FILE INFORMATION', margin + 2, yPos + 6);
        yPos += 12;
        
        doc.setFontSize(10);
        doc.setFont('helvetica', 'bold');
        doc.text('Scanned Copy:', margin, yPos);
        yPos += 6;
        doc.setFont('helvetica', 'normal');
        doc.text(file1Data ? file1Data.name : 'N/A', margin + 5, yPos);
        yPos += 8;
        
        doc.setFont('helvetica', 'bold');
        doc.text('Soft Copy:', margin, yPos);
        yPos += 6;
        doc.setFont('helvetica', 'normal');
        doc.text(file2Data ? file2Data.name : 'N/A', margin + 5, yPos);
        yPos += 12;
        
        // Differences Section
        checkPageBreak(20);
        doc.setFillColor(240, 240, 240);
        doc.rect(margin, yPos, maxWidth, 8, 'F');
        doc.setFontSize(14);
        doc.setFont('helvetica', 'bold');
        doc.text('DIFFERENCES FOUND', margin + 2, yPos + 6);
        yPos += 12;
        
        if (results.isIdentical) {
            doc.setFontSize(10);
            doc.setFont('helvetica', 'italic');
            doc.setTextColor(0, 150, 100);
            doc.text('No differences found. Documents are identical.', margin, yPos);
            doc.setTextColor(0, 0, 0);
            yPos += 10;
        } else {
            let diffCount = 0;
            results.differences.forEach(diff => {
                if (diff.type !== 'unchanged' && diffCount < 50) { // Limit to 50 differences for PDF
                    checkPageBreak(15);
                    
                    const label = diff.type === 'added' ? '[+] In Soft Copy' : '[-] In Scanned Copy';
                    const color = diff.type === 'added' ? [0, 150, 100] : [220, 53, 69];
                    
                    doc.setFontSize(9);
                    doc.setFont('helvetica', 'bold');
                    doc.setTextColor(...color);
                    doc.text(`Line ${diff.lineNum}: ${label}`, margin, yPos);
                    yPos += 5;
                    
                    doc.setFont('helvetica', 'normal');
                    doc.setTextColor(60, 60, 60);
                    const contentLines = doc.splitTextToSize(diff.content.substring(0, 200), maxWidth - 5);
                    contentLines.slice(0, 3).forEach(line => {
                        checkPageBreak();
                        doc.text(line, margin + 5, yPos);
                        yPos += 4;
                    });
                    
                    doc.setTextColor(0, 0, 0);
                    yPos += 3;
                    diffCount++;
                }
            });
            
            if (results.differentLines > 50) {
                doc.setFontSize(9);
                doc.setFont('helvetica', 'italic');
                doc.setTextColor(100, 100, 100);
                doc.text(`... and ${results.differentLines - 50} more differences`, margin, yPos);
                doc.setTextColor(0, 0, 0);
            }
        }
        
        // Save PDF
        doc.save(`comparison_report_${new Date().toISOString().split('T')[0]}.pdf`);
        
        hideLoading();
        
    } catch (error) {
        hideLoading();
        console.error('PDF generation error:', error);
        showError('Failed to generate PDF. Please try again.');
    }
}
</script>

</body>
</html>