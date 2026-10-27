<?php
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/connection2.php';

$secret_api_key = (string) env('SEND_TO_RESEARCH_API_KEY', '');
$receiver_name = 'research';
$table_name = 'research_measure';

function respond_error($message, $details = [], $status_code = 400)
{
    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'details' => $details
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Invalid request method.', ['method' => $_SERVER['REQUEST_METHOD'] ?? null], 405);
}

$payload = null;
$api_key = null;

$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'application/json') !== false) {
    $raw_input = file_get_contents('php://input');
    $payload = json_decode($raw_input, true);
    if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
        respond_error('Invalid JSON payload.', ['json_error' => json_last_error_msg()], 422);
    }
    $api_key = $payload['api_key'] ?? null;
} else {
    $api_key = $_POST['api_key'] ?? null;
    if (!empty($_POST['payload'])) {
        $payload = json_decode($_POST['payload'], true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            respond_error('Invalid JSON payload.', ['json_error' => json_last_error_msg()], 422);
        }
    } else {
        $payload = $_POST;
    }
}

if (!api_key_valid(api_key_sent(['api_key' => $api_key]), $secret_api_key)) {
    respond_error('Unauthorized: Invalid API key.', ['table' => $table_name], 401);
}

if (is_array($payload) && isset($payload['api_key'])) {
    unset($payload['api_key']);
}

// Handle file upload
$upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
    respond_error('Failed to create upload directory.', ['path' => $upload_dir], 500);
}

$file_path = null;
$original_filename = null;

if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $original_filename = basename($_FILES['file']['name']);
    $extension = upload_extension($original_filename, UPLOAD_DOC_EXTS);
    if ($extension === null) {
        respond_error('File type not allowed.', ['file' => $original_filename], 415);
    }
    $unique_name = sprintf('research_%s.%s', uniqid('', true), $extension);
    $destination = $upload_dir . DIRECTORY_SEPARATOR . $unique_name;

    // Check file size (max 100MB)
    $max_size = 100 * 1024 * 1024;
    if ($_FILES['file']['size'] > $max_size) {
        respond_error('File too large. Maximum size is 100MB.', ['file_size' => $_FILES['file']['size']], 413);
    }

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
        respond_error('Failed to save uploaded file.', ['file' => $original_filename], 500);
    }

    $file_path = 'uploads/' . $unique_name;
    
    // Add file info to payload
    $payload['file_path'] = $file_path;
    $payload['original_filename'] = $original_filename;
    $payload['file_size'] = $_FILES['file']['size'];
    $payload['file_type'] = $extension;
}

// Add metadata to payload
$payload['received_at'] = date('Y-m-d H:i:s');
$payload['status'] = 'Draft';

$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

// Create simple table with just id and payload_json
$create_sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `payload_json` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if (!$conn->query($create_sql)) {
    respond_error('Table creation failed.', [
        'table' => $table_name,
        'db_error' => $conn->error
    ], 500);
}

$insert_sql = "INSERT INTO `$table_name` (payload_json) VALUES (?)";

$stmt = $conn->prepare($insert_sql);
if ($stmt === false) {
    respond_error('Insert prepare failed.', [
        'table' => $table_name,
        'db_error' => $conn->error
    ], 500);
}

$stmt->bind_param('s', $payload_json);

if (!$stmt->execute()) {
    $stmt_error = $stmt->error;
    $stmt->close();
    respond_error('Insert execute failed.', [
        'table' => $table_name,
        'db_error' => $stmt_error
    ], 500);
}

$new_id = $stmt->insert_id;
$stmt->close();
$conn->close();

// Log successful insertion
$log_file = __DIR__ . '/research_log.txt';
$log_entry = date('Y-m-d H:i:s') . " - SUCCESS - ID: $new_id - File: " . ($file_path ?? 'none') . "\n";
@file_put_contents($log_file, $log_entry, FILE_APPEND);

echo json_encode([
    'success' => true,
    'message' => 'Measure received successfully for similarity checking.',
    'id' => $new_id,
    'table' => $table_name,
    'file_path' => $file_path,
    'debug' => [
        'db_host' => $db_host,
        'db_name' => $db_name,
        'payload_size' => strlen($payload_json)
    ]
]);
?>
