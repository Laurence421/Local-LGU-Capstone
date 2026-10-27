<?php
// Set headers to allow cross-origin requests (if needed) and indicate JSON response
header('Content-Type: application/json');

// Include the database connection for this system (e.g., lgu2test's database)
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

    // Check if the table exists and create it if not
    $sql_create_table = "
    CREATE TABLE IF NOT EXISTS `m6_measuredocketing_fromresearch` (
      `m6_MD_ID` INT(11) NOT NULL AUTO_INCREMENT,
      `m6_md_code` VARCHAR(50) DEFAULT NULL,
      `m9_SC_ID` INT(11) DEFAULT NULL,
      `m9_SC_Code` VARCHAR(50) DEFAULT NULL,
      `m1_MC_ID` INT(11) DEFAULT NULL,
      `m1_MC_code` VARCHAR(50) DEFAULT NULL,
      `date_created` DATE DEFAULT NULL,
      `measure_type` VARCHAR(100) DEFAULT NULL,
      `measure_title` VARCHAR(255) DEFAULT NULL,
      `measure_content` TEXT DEFAULT NULL,
      `introducers` VARCHAR(255) DEFAULT NULL,
      `measure_status` VARCHAR(50) DEFAULT NULL,
      `checking_remarks_pending` TEXT DEFAULT NULL,
      `checking_remarks_approved` TEXT DEFAULT NULL,
      `checking_notes` TEXT DEFAULT NULL,
      `checked_by` VARCHAR(100) DEFAULT NULL,
      `datetime_submitted` DATETIME DEFAULT NULL,
      `category` VARCHAR(100) DEFAULT NULL,
      `sp_no` VARCHAR(50) DEFAULT NULL,
      `year_cc` VARCHAR(150) DEFAULT NULL,
      PRIMARY KEY (`m6_MD_ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    // Execute the CREATE TABLE query
    $conn->query($sql_create_table);

    // First, insert the data that doesn't rely on auto-incremented ID
    $sql_insert = "INSERT INTO m6_measuredocketing_fromresearch (
        m9_SC_ID,
        m9_SC_Code,
        m1_MC_ID,
        m1_MC_code,
        date_created,
        measure_type,
        measure_title,
        measure_content,
        introducers,
        measure_status,
        checking_remarks_pending,
        checking_remarks_approved,
        checking_notes,
        checked_by,
        datetime_submitted,
        category,
        sp_no,
        year_cc
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt_insert = $conn->prepare($sql_insert);
    if ($stmt_insert === false) {
        throw new Exception("Error preparing INSERT statement: " . $conn->error);
    }
    
    $stmt_insert->bind_param("isisssssssssssssss",
        $m9_SC_ID,
        $m9_SC_Code,
        $m1_MC_ID,
        $m1_MC_code,
        $date_created,
        $measure_type,
        $measure_title,
        $measure_content,
        $introducers,
        $measure_status,
        $checking_remarks_pending,
        $checking_remarks_approved,
        $checking_notes,
        $checked_by,
        $datetime_submitted,
        $category,
        $sp_no,
        $year_cc
    );

    if (!$stmt_insert->execute()) {
        throw new Exception("Error executing INSERT statement: " . $stmt_insert->error);
    }
    $new_id = $conn->insert_id;
    $stmt_insert->close();
    
    // Now, generate the new code using the auto-incremented ID
    $new_code = 'MD_' . str_pad($new_id, 3, '0', STR_PAD_LEFT);

    // Second, update the row with the newly generated m6_md_code
    $sql_update = "UPDATE m6_measuredocketing_fromresearch SET m6_md_code = ? WHERE m6_MD_ID = ?";
    $stmt_update = $conn->prepare($sql_update);
    if ($stmt_update === false) {
        throw new Exception("Error preparing UPDATE statement: " . $conn->error);
    }
    
    $stmt_update->bind_param("si", $new_code, $new_id);
    if (!$stmt_update->execute()) {
        throw new Exception("Error executing UPDATE statement: " . $stmt_update->error);
    }
    $stmt_update->close();

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Data received and saved successfully. New record ID: ' . $new_id . ', New code: ' . $new_code
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