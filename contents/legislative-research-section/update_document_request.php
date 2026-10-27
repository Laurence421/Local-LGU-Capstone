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

// Sanitize and prepare data for database update
$request_id = (int)$data['request_id'];
$status = htmlspecialchars($data['status']);
$admin_notes = !empty($data['admin_notes']) ? htmlspecialchars($data['admin_notes']) : null;

// Validate required fields
if (empty($request_id) || empty($status)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Missing required fields: request_id and status are required.']);
    exit;
}

// Validate status values
$valid_statuses = ['Pending', 'Processing', 'Approved', 'Rejected', 'Completed'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid status. Must be one of: ' . implode(', ', $valid_statuses)]);
    exit;
}

try {
    // Set MySQL timezone to Manila time to match PHP timezone
    $conn->query("SET time_zone = '+08:00'");
    
    // Start a transaction for safe database operation
    $conn->begin_transaction();

    // Check if the document_requests table exists
    $check_table_sql = "SHOW TABLES LIKE 'document_requests'";
    $table_result = $conn->query($check_table_sql);
    
    if ($table_result->num_rows == 0) {
        throw new Exception('Document requests table does not exist.');
    }

    // Check if the request exists
    $check_request_sql = "SELECT id, status FROM document_requests WHERE id = ?";
    $check_stmt = $conn->prepare($check_request_sql);
    $check_stmt->bind_param('i', $request_id);
    $check_stmt->execute();
    $request_result = $check_stmt->get_result();
    
    if ($request_result->num_rows == 0) {
        throw new Exception('Document request not found with ID: ' . $request_id);
    }
    
    $existing_request = $request_result->fetch_assoc();
    $check_stmt->close();

    // Update the document request status and admin notes
    $sql_update = "UPDATE document_requests 
                   SET status = ?, admin_notes = ?, updated_at = CURRENT_TIMESTAMP 
                   WHERE id = ?";

    $stmt_update = $conn->prepare($sql_update);
    if ($stmt_update === false) {
        throw new Exception("Error preparing UPDATE statement: " . $conn->error);
    }
    
    $stmt_update->bind_param("ssi", $status, $admin_notes, $request_id);

    if (!$stmt_update->execute()) {
        throw new Exception("Error executing UPDATE statement: " . $stmt_update->error);
    }
    
    $affected_rows = $stmt_update->affected_rows;
    $stmt_update->close();

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Document request updated successfully.',
        'request_id' => $request_id,
        'old_status' => $existing_request['status'],
        'new_status' => $status,
        'affected_rows' => $affected_rows
    ]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $conn->rollback();
    
    // Log detailed error information
    error_log("update_document_request.php Error: " . $e->getMessage());
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
