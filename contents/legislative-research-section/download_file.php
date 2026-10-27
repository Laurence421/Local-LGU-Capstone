<?php
require_once __DIR__ . '/../../auth.php';
require_once 'connection.php';

// Only files inside these folders may ever be downloaded
$allowedRoots = array_filter([
    realpath(__DIR__ . '/../../uploads'),
    realpath(__DIR__ . '/uploads'),
    realpath(__DIR__ . '/../../files'),
]);

// Convert a stored file path to a URL inside this app (never an external site)
function convertToWebURL($filePath) {
    $filePath = str_replace('\\', '/', (string) $filePath);
    $marker = strpos($filePath, 'uploads/');
    if ($marker === false) {
        return '';
    }
    return url(substr($filePath, $marker));
}

/** Real path of $candidate if it is a file inside one of $roots, else ''. */
function resolveAllowedFile($candidate, array $roots) {
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return '';
    }
    foreach ($roots as $root) {
        if (strpos($real, $root . DIRECTORY_SEPARATOR) === 0) {
            return $real;
        }
    }
    return '';
}

// Check if file parameter is provided
if (!isset($_GET['file']) || empty($_GET['file']) || !is_string($_GET['file'])) {
    http_response_code(400);
    die('File parameter is required');
}

$fileParam = $_GET['file'];

// Option to redirect to web URL instead of serving file locally
if (isset($_GET['redirect']) && $_GET['redirect'] === 'true') {
    $webUrl = convertToWebURL($fileParam);
    if (!empty($webUrl)) {
        header('Location: ' . $webUrl);
        exit;
    }
}

try {
    $filePath = '';
    $possiblePaths = [];

    if (!filter_var($fileParam, FILTER_VALIDATE_URL)) {
        $sanitizedPath = ltrim(str_replace('\\', '/', $fileParam), '/');
        $fileName = basename($sanitizedPath);

        // Check common upload directories and handle path variations
        $possiblePaths = [
            __DIR__ . '/../../' . $sanitizedPath,
            __DIR__ . '/../../uploads/' . $fileName,
            __DIR__ . '/../../uploads/' . $sanitizedPath,
            __DIR__ . '/../../uploads/' . str_replace('uploads/measures/', '', $sanitizedPath),
            __DIR__ . '/../../uploads/' . str_replace(['uploads/measures/', 'uploads/', 'measures/'], '', $sanitizedPath),
            __DIR__ . '/../../uploads/measures/' . $sanitizedPath,
            __DIR__ . '/uploads/' . $fileName,
            __DIR__ . '/../../files/' . $sanitizedPath,
        ];

        // First existing file that is inside an allowed folder and has an allowed type
        foreach ($possiblePaths as $path) {
            $resolved = resolveAllowedFile($path, $allowedRoots);
            if ($resolved !== '' && upload_extension($resolved, UPLOAD_DOC_EXTS) !== null) {
                $filePath = $resolved;
                break;
            }
        }
    } else {
        // If it's a URL, we can't serve it directly
        http_response_code(400);
        die('Cannot download external URLs');
    }
    
    // Check if file exists
    if (empty($filePath) || !file_exists($filePath) || !is_file($filePath)) {
        // Log detailed error information
        error_log("File not found. Searched paths:");
        foreach ($possiblePaths as $path) {
            error_log("  - " . $path);
        }
        
        http_response_code(404);
        die('File not found.');
    }
    
    // Check if file is readable
    if (!is_readable($filePath)) {
        http_response_code(403);
        die('File is not readable');
    }
    
    // Get file information
    $fileName = basename($filePath);
    $fileSize = filesize($filePath);
    $mimeType = mime_content_type($filePath);
    
    // If mime type detection fails, use a default based on extension
    if (!$mimeType) {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        switch (strtolower($extension)) {
            case 'pdf':
                $mimeType = 'application/pdf';
                break;
            case 'doc':
                $mimeType = 'application/msword';
                break;
            case 'docx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                break;
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                $mimeType = 'image/png';
                break;
            case 'txt':
                $mimeType = 'text/plain';
                break;
            default:
                $mimeType = 'application/octet-stream';
        }
    }
    
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Read and output the file
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log("File download error: " . $e->getMessage());
    http_response_code(500);
    die('Error downloading file.');
}
?>
