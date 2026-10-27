<?php
// Set headers to allow cross-origin requests (if needed) and indicate JSON response
header('Content-Type: application/json');

// Include the database connection
require_once 'connection2.php';

// Define the API key
$secret_api_key = (string) env('LGU2_API_KEY', '');

// Get the raw POST data from the request body
$json_data = file_get_contents('php://input');

// Decode the JSON data into a PHP array
$data = json_decode($json_data, true);

// Check request method - allow if we have valid JSON data even if method detection fails
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$has_json_data = ($data !== null && is_array($data));

if (!$has_json_data && $request_method !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method.',
        'debug' => [
            'method' => $request_method,
            'has_data' => $has_json_data,
            'raw_length' => strlen($json_data)
        ]
    ]);
    exit;
}

// Check if the JSON decoding was successful and if required data is present
if ($data === null ||
    !api_key_valid(api_key_sent($data), $secret_api_key)) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid API key.']);
    exit;
}

// Sanitize and prepare data for database insertion
$m9_SC_ID = (int)$data['m9_SC_ID'];
$m9_SC_Code = htmlspecialchars($data['m9_SC_Code']);
$m1_MC_ID = (int)$data['m1_MC_ID'];
$m1_MC_code = htmlspecialchars($data['m1_MC_code']);
$date_created = htmlspecialchars($data['date_created']);
$measure_type = htmlspecialchars($data['measure_type']);
$measure_title = htmlspecialchars($data['measure_title']);
$measure_content = htmlspecialchars($data['measure_content']);
$introducers = htmlspecialchars($data['introducers']);
$measure_status = htmlspecialchars($data['measure_status']);
$checking_remarks_pending = htmlspecialchars($data['checking_remarks_pending']);
$checking_remarks_approved = htmlspecialchars($data['checking_remarks_approved']);
$checking_notes = htmlspecialchars($data['checking_notes']);
$checked_by = htmlspecialchars($data['checked_by']);
$datetime_submitted = htmlspecialchars($data['datetime_submitted']);
$category = htmlspecialchars($data['category']);
$sp_no = htmlspecialchars($data['sp_no']);
$year_cc = htmlspecialchars($data['year_cc']);

try {
    // Start a transaction for safe database operation
    $conn->begin_transaction();

    // Update the m9_similaritychecking table with the new status
    $sqlUpdateSc = "UPDATE m9_similaritychecking SET measure_status = ?, checking_remarks_pending = ?, checking_remarks_approved = ?, checking_notes = ?, checked_by = ?, datetime_submitted = ? WHERE m9_SC_ID = ?";
    $stmtUpdateSc = $conn->prepare($sqlUpdateSc);
    if ($stmtUpdateSc === false) {
        throw new Exception('Error preparing UPDATE m9_similaritychecking statement: ' . $conn->error);
    }
    $stmtUpdateSc->bind_param("ssssssi", $measure_status, $checking_remarks_pending, $checking_remarks_approved, $checking_notes, $checked_by, $datetime_submitted, $m9_SC_ID);
    if (!$stmtUpdateSc->execute()) {
        throw new Exception('Error executing UPDATE m9_similaritychecking statement: ' . $stmtUpdateSc->error);
    }
    $stmtUpdateSc->close();

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Record #{$m9_SC_ID} updated successfully in m9_similaritychecking table."
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
