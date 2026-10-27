<?php
// Enable error reporting but don't display errors (they interfere with JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers to allow cross-origin requests (if needed) and indicate JSON response
header('Content-Type: application/json');

// Include the database connection for this system
require_once 'CAconnection.php';

// Define the API key
$secret_api_key = (string) env('LGU2_API_KEY', '');

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Get the raw POST data from the request body
$json_data = file_get_contents('php://input');

// Decode the JSON data into a PHP array
$data = json_decode($json_data, true);

// Check if the JSON decoding was successful and if required data is present
if ($data === null ||
    !api_key_valid(api_key_sent($data), $secret_api_key)) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid API key.']);
    exit;
}
    
// Sanitize and prepare data for database insertion
$user_id = (int)$data['user_id'];
$username = htmlspecialchars($data['username']);
$email = htmlspecialchars($data['email']);
$measure_title = htmlspecialchars($data['measure_title']);
$request_reason = htmlspecialchars($data['request_reason']);
$status = !empty($data['status']) ? htmlspecialchars($data['status']) : 'Pending';
$admin_notes = !empty($data['admin_notes']) ? htmlspecialchars($data['admin_notes']) : null;

// Validate required fields
if (empty($email) || empty($measure_title) || empty($request_reason)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Missing required fields: email, measure_title, and request_reason are required.']);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

try {
    // Set MySQL timezone to Manila time to match PHP timezone
    $conn->query("SET time_zone = '+08:00'");
    
    // Start a transaction for safe database operation
    $conn->begin_transaction();

    // Check if the document_requests table exists and create it if not
    $sql_create_table = "
    CREATE TABLE IF NOT EXISTS `document_requests` (
      `id` INT(30) NOT NULL AUTO_INCREMENT,
      `user_id` INT(30) NOT NULL,
      `username` VARCHAR(100) NOT NULL,
      `email` VARCHAR(255) NOT NULL,
      `measure_title` TEXT NOT NULL,
      `request_reason` TEXT NOT NULL,
      `status` ENUM('Pending', 'Processing', 'Approved', 'Rejected', 'Completed') DEFAULT 'Pending',
      `request_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `admin_notes` TEXT DEFAULT NULL,
      PRIMARY KEY (`id`),
      INDEX `idx_user_id` (`user_id`),
      INDEX `idx_status` (`status`),
      INDEX `idx_request_date` (`request_date`),
      INDEX `idx_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    // Execute the CREATE TABLE query
    $conn->query($sql_create_table);

    // Check if email column exists in document_requests table, if not add it
    $check_email_sql = "SHOW COLUMNS FROM document_requests LIKE 'email'";
    $email_result = $conn->query($check_email_sql);
    
    if ($email_result->num_rows == 0) {
        $add_email_sql = "ALTER TABLE document_requests ADD COLUMN email VARCHAR(255) NOT NULL AFTER username";
        if (!$conn->query($add_email_sql)) {
            throw new Exception('Error adding email column: ' . $conn->error);
        }
    }

    // Check if admin_notes column exists in document_requests table, if not add it
    $check_notes_sql = "SHOW COLUMNS FROM document_requests LIKE 'admin_notes'";
    $notes_result = $conn->query($check_notes_sql);
    
    if ($notes_result->num_rows == 0) {
        $add_notes_sql = "ALTER TABLE document_requests ADD COLUMN admin_notes TEXT DEFAULT NULL AFTER updated_at";
        if (!$conn->query($add_notes_sql)) {
            throw new Exception('Error adding admin_notes column: ' . $conn->error);
        }
    }

    // Insert the document request data
    $sql_insert = "INSERT INTO document_requests (
        user_id,
        username,
        email,
        measure_title,
        request_reason,
        status,
        admin_notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt_insert = $conn->prepare($sql_insert);
    if ($stmt_insert === false) {
        throw new Exception("Error preparing INSERT statement: " . $conn->error);
    }
    
    $stmt_insert->bind_param("issssss",
        $user_id,
        $username,
        $email,
        $measure_title,
        $request_reason,
        $status,
        $admin_notes
    );

    if (!$stmt_insert->execute()) {
        throw new Exception("Error executing INSERT statement: " . $stmt_insert->error);
    }
    $new_id = $conn->insert_id;
    $stmt_insert->close();

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Document request received and processed successfully. New request ID: ' . $new_id,
        'request_id' => $new_id,
        'email' => $email
    ]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $conn->rollback();
    
    // Log detailed error information
    error_log("receive_document_request.php Error: " . $e->getMessage());
    error_log("MySQL Error: " . $conn->error);
    error_log("Received payload: " . json_encode($data));
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
