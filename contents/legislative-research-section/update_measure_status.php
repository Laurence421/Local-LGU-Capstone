<?php
// Set headers to allow cross-origin requests (if needed) and indicate JSON response
header('Content-Type: application/json');

// Include the database connection for this system
require_once 'connection2.php';

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

// Check if required m1_MC_ID is provided
if (!isset($data['m1_MC_ID']) || empty($data['m1_MC_ID'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Missing required parameter: m1_MC_ID']);
    exit;
}

// Sanitize and prepare data for database update
$m1_MC_ID = (int)$data['m1_MC_ID'];
$measure_status = (isset($data['measure_status']) && !empty($data['measure_status'])) ? htmlspecialchars($data['measure_status']) : null;
$checking_remarks_pending = (isset($data['checking_remarks_pending']) && !empty($data['checking_remarks_pending'])) ? htmlspecialchars($data['checking_remarks_pending']) : null;
$checking_remarks_approved = (isset($data['checking_remarks_approved']) && !empty($data['checking_remarks_approved'])) ? htmlspecialchars($data['checking_remarks_approved']) : null;
$checking_notes = (isset($data['checking_notes']) && !empty($data['checking_notes'])) ? htmlspecialchars($data['checking_notes']) : null;

try {
    // Start a transaction for safe database operation
    $conn->begin_transaction();

    // Verify that the m1_MC_ID exists in the tables before attempting update
    $sql_check_exists = "SELECT COUNT(*) as count FROM m1_measurecreation WHERE m1_MC_ID = ?";
    $stmt_check = $conn->prepare($sql_check_exists);
    if ($stmt_check === false) {
        throw new Exception("Error preparing CHECK statement: " . $conn->error);
    }
    
    $stmt_check->bind_param("i", $m1_MC_ID);
    if (!$stmt_check->execute()) {
        throw new Exception("Error executing CHECK statement: " . $stmt_check->error);
    }
    
    $result = $stmt_check->get_result();
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        throw new Exception("No record found with m1_MC_ID: " . $m1_MC_ID);
    }
    $stmt_check->close();

    // Build dynamic UPDATE query based on provided fields
    $update_fields = [];
    $update_values = [];
    $param_types = "";

    if ($measure_status !== null) {
        $update_fields[] = "measure_status = ?";
        $update_values[] = $measure_status;
        $param_types .= "s";
    }
    
    if ($checking_remarks_pending !== null) {
        $update_fields[] = "checking_remarks_pending = ?";
        $update_values[] = $checking_remarks_pending;
        $param_types .= "s";
    }
    
    if ($checking_remarks_approved !== null) {
        $update_fields[] = "checking_remarks_approved = ?";
        $update_values[] = $checking_remarks_approved;
        $param_types .= "s";
    }
    
    if ($checking_notes !== null) {
        $update_fields[] = "checking_notes = ?";
        $update_values[] = $checking_notes;
        $param_types .= "s";
    }

    if (empty($update_fields)) {
        throw new Exception("No valid fields provided for update");
    }

    // Add m1_MC_ID to the end for WHERE clause
    $update_values[] = $m1_MC_ID;
    $param_types .= "i";

    // Update m1_measurecreation table
    $sql_update_mc = "UPDATE m1_measurecreation SET " . implode(", ", $update_fields) . " WHERE m1_MC_ID = ?";
    $stmt_update_mc = $conn->prepare($sql_update_mc);
    if ($stmt_update_mc === false) {
        throw new Exception("Error preparing UPDATE statement for m1_measurecreation: " . $conn->error);
    }
    
    $stmt_update_mc->bind_param($param_types, ...$update_values);
    if (!$stmt_update_mc->execute()) {
        throw new Exception("Error executing UPDATE statement for m1_measurecreation: " . $stmt_update_mc->error);
    }
    $affected_rows_mc = $stmt_update_mc->affected_rows;
    $stmt_update_mc->close();

    // Update m1_measurecreation_copy table
    $sql_update_mc_copy = "UPDATE m1_measurecreation_copy SET " . implode(", ", $update_fields) . " WHERE m1_MC_ID = ?";
    $stmt_update_mc_copy = $conn->prepare($sql_update_mc_copy);
    if ($stmt_update_mc_copy === false) {
        throw new Exception("Error preparing UPDATE statement for m1_measurecreation_copy: " . $conn->error);
    }
    
    $stmt_update_mc_copy->bind_param($param_types, ...$update_values);
    if (!$stmt_update_mc_copy->execute()) {
        throw new Exception("Error executing UPDATE statement for m1_measurecreation_copy: " . $stmt_update_mc_copy->error);
    }
    $affected_rows_mc_copy = $stmt_update_mc_copy->affected_rows;
    $stmt_update_mc_copy->close();

    // Commit the transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Measure status updated successfully',
        'data' => [
            'm1_MC_ID' => $m1_MC_ID,
            'affected_rows_m1_measurecreation' => $affected_rows_mc,
            'affected_rows_m1_measurecreation_copy' => $affected_rows_mc_copy,
            'updated_fields' => array_keys(array_filter([
                'measure_status' => $measure_status,
                'checking_remarks_pending' => $checking_remarks_pending,
                'checking_remarks_approved' => $checking_remarks_approved,
                'checking_notes' => $checking_notes
            ], function($value) { return $value !== null; }))
        ]
    ]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
