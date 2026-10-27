<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../auth.php';

$conn = db_connect();

// Check connection
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}


// Check if 'm9_similaritychecking' table exists and create it if not
$check_table_sql = "SHOW TABLES LIKE 'm9_similaritychecking'";
$table_result = $conn->query($check_table_sql);

if ($table_result->num_rows == 0) {
    $create_table_sql = "
        CREATE TABLE m9_similaritychecking (
            m9_SC_ID INT(30) AUTO_INCREMENT PRIMARY KEY,
            m9_SC_Code VARCHAR(50) NULL, 
            m1_MC_ID INT(30),
            m1_MC_code VARCHAR(50), 
            date_created DATE,
            measure_type VARCHAR(30),
            measure_title VARCHAR(255),
            measure_content LONGTEXT,
            introducers VARCHAR(255),
            measure_status VARCHAR(25),
            checking_remarks VARCHAR(30),
            checking_notes VARCHAR(255),
            checked_by VARCHAR(255),
            datetime_submitted DATETIME NULL
        )
    ";
    
    if (!$conn->query($create_table_sql)) {
        echo json_encode(['status' => 'error', 'message' => 'Error creating table: ' . $conn->error]);
        $conn->close();
        exit();
    }
}

$conn->begin_transaction();

try {
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        // Sanitize and validate input
        $date_created = $_POST['date_created'] ?? '';
        $measure_type = $_POST['measure_type'] ?? '';
        $measure_title = $_POST['measure_title'] ?? '';
        $measure_content = $_POST['measure_content'] ?? '';
        $introducers = $_POST['introducers'] ?? '';
        $measure_status = $_POST['measure_status'] ?? '';
        
        $checking_remarks = ""; 
        $checking_notes = "";
        
        if (empty($date_created) || empty($measure_type) || empty($measure_title) || empty($measure_content) || empty($introducers) || empty($measure_status)) {
            throw new Exception('All required fields must be filled.');
        }

        // --- Insert into m1_measurecreation and get the ID ---
        $sql1 = "INSERT INTO m1_measurecreation (date_created, measure_type, measure_title, measure_content, introducers, measure_status) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param("ssssss", $date_created, $measure_type, $measure_title, $measure_content, $introducers, $measure_status);
        if (!$stmt1->execute()) {
            throw new Exception('Error inserting into m1_measurecreation: ' . $stmt1->error);
        }
        $m1_mc_id = $stmt1->insert_id;
        $stmt1->close();

        // --- Generate and update the m1_MC_Code in m1_measurecreation ---
        $m1_MC_Code = 'MC_' . str_pad($m1_mc_id, 3, '0', STR_PAD_LEFT);
        $sqlUpdateCode = "UPDATE m1_measurecreation SET m1_MC_Code = ? WHERE m1_MC_ID = ?";
        $stmtUpdate = $conn->prepare($sqlUpdateCode);
        $stmtUpdate->bind_param("si", $m1_MC_Code, $m1_mc_id);
        if (!$stmtUpdate->execute()) {
            throw new Exception('Error updating m1_MC_Code: ' . $stmtUpdate->error);
        }
        $stmtUpdate->close();

        // --- Insert into m9_similaritychecking and get its ID ---
        $sql2 = "INSERT INTO m9_similaritychecking (m1_MC_ID, m1_MC_code, date_created, measure_type, measure_title, measure_content, introducers, measure_status, checking_remarks, checking_notes, checked_by, datetime_submitted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt2 = $conn->prepare($sql2);
        
        $null_value = NULL;
        $stmt2->bind_param("isssssssssss", $m1_mc_id, $m1_MC_Code, $date_created, $measure_type, $measure_title, $measure_content, $introducers, $measure_status, $checking_remarks, $checking_notes, $checked_by, $null_value);

        if (!$stmt2->execute()) {
            throw new Exception('Error inserting into m9_similaritychecking: ' . $stmt2->error);
        }
        $m9_sc_id = $stmt2->insert_id; // Get the auto-increment ID of the new row
        $stmt2->close();
        
        // --- Generate and update the m9_SC_Code in m9_similaritychecking ---
        $m9_SC_Code = 'RSC_' . str_pad($m9_sc_id, 3, '0', STR_PAD_LEFT);
        $sqlUpdateScCode = "UPDATE m9_similaritychecking SET m9_SC_Code = ? WHERE m9_SC_ID = ?";
        $stmtUpdateSc = $conn->prepare($sqlUpdateScCode);
        $stmtUpdateSc->bind_param("si", $m9_SC_Code, $m9_sc_id);
        if (!$stmtUpdateSc->execute()) {
            throw new Exception('Error updating m9_SC_Code: ' . $stmtUpdateSc->error);
        }
        $stmtUpdateSc->close();

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'New measure created and added for similarity check successfully!']);

    } else {
        throw new Exception('Invalid request method.');
    }

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

} finally {
    $conn->close();
}
?>

