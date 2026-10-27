<?php
// Enable error reporting but don't display errors (they interfere with JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
    
// Sanitize and prepare data for database insertion
$m1_MC_ID = (int)$data['m1_MC_ID'];
$m1_MC_code = htmlspecialchars($data['m1_MC_code']);
$date_created = htmlspecialchars($data['date_created']);
$measure_type = htmlspecialchars($data['measure_type']);
$category = !empty($data['category']) ? htmlspecialchars($data['category']) : null;
$measure_title = htmlspecialchars($data['measure_title']);
$measure_content = htmlspecialchars($data['measure_content']);
$introducers = htmlspecialchars($data['introducers']);
$measure_status = htmlspecialchars($data['measure_status']);
$checking_remarks_pending = !empty($data['checking_remarks_pending']) ? htmlspecialchars($data['checking_remarks_pending']) : null;
$checking_remarks_approved = isset($data['checking_remarks_approved']) && $data['checking_remarks_approved'] !== null ? htmlspecialchars($data['checking_remarks_approved']) : null;
$checking_notes = !empty($data['checking_notes']) ? htmlspecialchars($data['checking_notes']) : null;
$checked_by = !empty($data['checked_by']) ? htmlspecialchars($data['checked_by']) : null;
$datetime_submitted = !empty($data['datetime_submitted']) ? htmlspecialchars($data['datetime_submitted']) : null;
$sp_no = isset($data['sp_no']) && $data['sp_no'] !== null && $data['sp_no'] !== '' ? htmlspecialchars($data['sp_no']) : null;
$year_cc = !empty($data['year_cc']) ? htmlspecialchars($data['year_cc']) : null;
$measure_file = !empty($data['measure_file']) ? htmlspecialchars($data['measure_file']) : null;

try {
    // Set MySQL timezone to Manila time to match PHP timezone
    $conn->query("SET time_zone = '+08:00'");
    
    // Start a transaction for safe database operation
    $conn->begin_transaction();

    // Check if the m9_similaritychecking table exists and create it if not
    $sql_create_table = "
    CREATE TABLE IF NOT EXISTS `m9_similaritychecking` (
      `m9_SC_ID` INT(30) NOT NULL AUTO_INCREMENT,
      `m9_SC_Code` VARCHAR(50) DEFAULT NULL,
      `m1_MC_ID` INT(30) DEFAULT NULL,
      `m1_MC_code` VARCHAR(50) DEFAULT NULL,
      `date_created` DATE DEFAULT NULL,
      `measure_type` VARCHAR(30) DEFAULT NULL,
      `category` VARCHAR(255) DEFAULT NULL,
      `measure_title` TEXT DEFAULT NULL,
      `measure_content` LONGTEXT DEFAULT NULL,
      `introducers` TEXT DEFAULT NULL,
      `measure_status` VARCHAR(25) DEFAULT NULL,
      `checking_remarks_pending` VARCHAR(255) DEFAULT NULL,
      `checking_remarks_approved` VARCHAR(255) DEFAULT NULL,
      `checking_notes` VARCHAR(255) DEFAULT NULL,
      `checked_by` VARCHAR(255) DEFAULT NULL,
      `datetime_submitted` DATETIME DEFAULT NULL,
      `sp_no` VARCHAR(255) DEFAULT NULL,
      `year_cc` VARCHAR(255) DEFAULT NULL,
      `measure_file` VARCHAR(500) DEFAULT NULL,
      PRIMARY KEY (`m9_SC_ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    // Execute the CREATE TABLE query
    $conn->query($sql_create_table);

    // Check if checking_remarks_pending column exists in m9_similaritychecking table, if not add it
    $check_crp_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'checking_remarks_pending'";
    $crp_result = $conn->query($check_crp_sql);
    
    if ($crp_result->num_rows == 0) {
        $add_crp_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN checking_remarks_pending VARCHAR(255) DEFAULT NULL AFTER measure_status";
        if (!$conn->query($add_crp_sql)) {
            throw new Exception('Error adding checking_remarks_pending column: ' . $conn->error);
        }
    }

    // Check if checking_remarks_approved column exists in m9_similaritychecking table, if not add it
    $check_cra_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'checking_remarks_approved'";
    $cra_result = $conn->query($check_cra_sql);
    
    if ($cra_result->num_rows == 0) {
        $add_cra_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN checking_remarks_approved VARCHAR(255) DEFAULT NULL AFTER checking_remarks_pending";
        if (!$conn->query($add_cra_sql)) {
            throw new Exception('Error adding checking_remarks_approved column: ' . $conn->error);
        }
    }

    // Check if sp_no column exists in m9_similaritychecking table, if not add it
    $check_sp_no_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'sp_no'";
    $sp_no_result = $conn->query($check_sp_no_sql);
    
    if ($sp_no_result->num_rows == 0) {
        $add_sp_no_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN sp_no VARCHAR(255) DEFAULT NULL AFTER datetime_submitted";
        if (!$conn->query($add_sp_no_sql)) {
            throw new Exception('Error adding sp_no column: ' . $conn->error);
        }
    }

    // Check if year_cc column exists in m9_similaritychecking table, if not add it
    $check_year_cc_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'year_cc'";
    $year_cc_result = $conn->query($check_year_cc_sql);
    
    if ($year_cc_result->num_rows == 0) {
        $add_year_cc_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN year_cc VARCHAR(255) DEFAULT NULL AFTER sp_no";
        if (!$conn->query($add_year_cc_sql)) {
            throw new Exception('Error adding year_cc column: ' . $conn->error);
        }
    }

    // Check if measure_file column exists in m9_similaritychecking table, if not add it
    $check_measure_file_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'measure_file'";
    $measure_file_result = $conn->query($check_measure_file_sql);
    
    if ($measure_file_result->num_rows == 0) {
        $add_measure_file_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN measure_file VARCHAR(500) DEFAULT NULL AFTER year_cc";
        if (!$conn->query($add_measure_file_sql)) {
            throw new Exception('Error adding measure_file column: ' . $conn->error);
        }
    }

    // Check if category column exists in m9_similaritychecking table, if not add it
    $check_category_sql = "SHOW COLUMNS FROM m9_similaritychecking LIKE 'category'";
    $category_result = $conn->query($check_category_sql);
    
    if ($category_result->num_rows == 0) {
        $add_category_sql = "ALTER TABLE m9_similaritychecking ADD COLUMN category VARCHAR(255) DEFAULT NULL AFTER measure_type";
        if (!$conn->query($add_category_sql)) {
            throw new Exception('Error adding category column: ' . $conn->error);
        }
    }

    // First, insert the data into m9_similaritychecking
    $sql_insert = "INSERT INTO m9_similaritychecking (
        m1_MC_ID,
        m1_MC_code,
        date_created,
        measure_type,
        category,
        measure_title,
        measure_content,
        introducers,
        measure_status,
        checking_remarks_pending,
        checking_remarks_approved,
        checking_notes,
        checked_by,
        datetime_submitted,
        sp_no,
        year_cc,
        measure_file
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt_insert = $conn->prepare($sql_insert);
    if ($stmt_insert === false) {
        throw new Exception("Error preparing INSERT statement: " . $conn->error);
    }
    
    $stmt_insert->bind_param("issssssssssssssss",
        $m1_MC_ID,
        $m1_MC_code,
        $date_created,
        $measure_type,
        $category,
        $measure_title,
        $measure_content,
        $introducers,
        $measure_status,
        $checking_remarks_pending,
        $checking_remarks_approved,
        $checking_notes,
        $checked_by,
        $datetime_submitted,
        $sp_no,
        $year_cc,
        $measure_file
    );

    if (!$stmt_insert->execute()) {
        throw new Exception("Error executing INSERT statement: " . $stmt_insert->error);
    }
    $new_id = $conn->insert_id;
    $stmt_insert->close();
    
    // Now, generate the new code using the auto-incremented ID
    $new_code = 'RSC_' . str_pad($new_id, 3, '0', STR_PAD_LEFT);

    // Second, update the row with the newly generated m9_SC_Code
    $sql_update = "UPDATE m9_similaritychecking SET m9_SC_Code = ? WHERE m9_SC_ID = ?";
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
        'message' => 'Measure data received and added to similarity checking. New record ID: ' . $new_id . ', New code: ' . $new_code
    ]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $conn->rollback();
    
    // Log detailed error information
    error_log("receive_measure_data.php Error: " . $e->getMessage());
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
