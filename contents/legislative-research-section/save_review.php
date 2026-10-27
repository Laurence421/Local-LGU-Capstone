<?php
// Start output buffering to prevent any accidental output
ob_start();

require_once __DIR__ . '/../../bootstrap.php';

// Check if user is logged in (simple check without auth.php)
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$lgu2_conn = db_connect();

// Check connection
if ($lgu2_conn->connect_error) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// Clear any output and set JSON header
ob_end_clean();
header('Content-Type: application/json');

// Auto-create review table if it doesn't exist
function createReviewTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS m9_measure_reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY,
        m9_SC_ID INT NOT NULL,
        review_stage ENUM('1st_review', '2nd_review', 'final_review') NOT NULL,
        review_notes TEXT,
        reviewed_by VARCHAR(255) NOT NULL,
        reviewed_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_measure_id (m9_SC_ID),
        INDEX idx_review_stage (review_stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($sql)) {
        error_log("Error creating m9_measure_reviews table: " . $conn->error);
        return ['success' => false, 'error' => $conn->error];
    }
    return ['success' => true];
}

// Ensure table exists
$tableResult = createReviewTable($lgu2_conn);
if (!$tableResult['success']) {
    echo json_encode(['success' => false, 'message' => 'Failed to initialize review table: ' . ($tableResult['error'] ?? 'Unknown error')]);
    exit;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $m9_SC_ID = isset($input['m9_SC_ID']) ? (int)$input['m9_SC_ID'] : 0;
    $review_stage = isset($input['review_stage']) ? $input['review_stage'] : '';
    $review_notes = isset($input['review_notes']) ? trim($input['review_notes']) : '';
    
    // Validation
    if ($m9_SC_ID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid measure ID']);
        exit;
    }
    
    if (!in_array($review_stage, ['1st_review', '2nd_review', 'final_review'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid review stage']);
        exit;
    }
    
    if (empty($review_notes)) {
        echo json_encode(['success' => false, 'message' => 'Review notes are required']);
        exit;
    }
    
    // Get current user
    $reviewed_by = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Unknown User';
    $reviewed_at = date('Y-m-d H:i:s');
    
    try {
        $lgu2_conn->begin_transaction();
        
        // Check if this review stage already exists
        $check_sql = "SELECT review_id FROM m9_measure_reviews WHERE m9_SC_ID = ? AND review_stage = ?";
        $check_stmt = $lgu2_conn->prepare($check_sql);
        $check_stmt->bind_param("is", $m9_SC_ID, $review_stage);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This review stage has already been completed']);
            $check_stmt->close();
            exit;
        }
        $check_stmt->close();
        
        // Verify sequential review order
        if ($review_stage === '2nd_review') {
            $verify_sql = "SELECT review_id FROM m9_measure_reviews WHERE m9_SC_ID = ? AND review_stage = '1st_review'";
            $verify_stmt = $lgu2_conn->prepare($verify_sql);
            $verify_stmt->bind_param("i", $m9_SC_ID);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Please complete 1st Review before proceeding to 2nd Review']);
                $verify_stmt->close();
                exit;
            }
            $verify_stmt->close();
        }
        
        if ($review_stage === 'final_review') {
            $verify_sql = "SELECT review_id FROM m9_measure_reviews WHERE m9_SC_ID = ? AND review_stage IN ('1st_review', '2nd_review')";
            $verify_stmt = $lgu2_conn->prepare($verify_sql);
            $verify_stmt->bind_param("i", $m9_SC_ID);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows < 2) {
                echo json_encode(['success' => false, 'message' => 'Please complete 1st Review and 2nd Review before proceeding to Final Review']);
                $verify_stmt->close();
                exit;
            }
            $verify_stmt->close();
        }
        
        // Insert review
        $insert_sql = "INSERT INTO m9_measure_reviews (m9_SC_ID, review_stage, review_notes, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = $lgu2_conn->prepare($insert_sql);
        $insert_stmt->bind_param("issss", $m9_SC_ID, $review_stage, $review_notes, $reviewed_by, $reviewed_at);
        
        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to save review: ' . $insert_stmt->error);
        }
        
        $insert_stmt->close();
        $lgu2_conn->commit();
        
        // Log activity (wrapped in try-catch to prevent errors from breaking the response)
        try {
            if (file_exists(__DIR__ . '/../..' . '/config/database.php') && 
                file_exists(__DIR__ . '/../..' . '/includes/audit_log.php')) {
                require_once __DIR__ . '/../..' . '/config/database.php';
                $auditConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if (!$auditConn->connect_error) {
                    require_once __DIR__ . '/../..' . '/includes/audit_log.php';
                    $stage_label = str_replace('_', ' ', ucwords($review_stage, '_'));
                    logActivity('review', "Completed {$stage_label} for measure ID: {$m9_SC_ID}", $auditConn);
                    $auditConn->close();
                }
            }
        } catch (Exception $auditError) {
            // Log error but don't fail the request
            error_log("Audit log error: " . $auditError->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Review saved successfully',
            'review_stage' => $review_stage,
            'reviewed_by' => $reviewed_by,
            'reviewed_at' => $reviewed_at
        ]);
        
    } catch (Exception $e) {
        if ($lgu2_conn) {
            $lgu2_conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get review history for a measure
    $m9_SC_ID = isset($_GET['m9_SC_ID']) ? (int)$_GET['m9_SC_ID'] : 0;
    
    if ($m9_SC_ID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid measure ID']);
        exit;
    }
    
    try {
        $sql = "SELECT review_stage, review_notes, reviewed_by, reviewed_at 
                FROM m9_measure_reviews 
                WHERE m9_SC_ID = ? 
                ORDER BY 
                    CASE review_stage 
                        WHEN '1st_review' THEN 1 
                        WHEN '2nd_review' THEN 2 
                        WHEN 'final_review' THEN 3 
                    END";
        
        $stmt = $lgu2_conn->prepare($sql);
        $stmt->bind_param("i", $m9_SC_ID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }
        
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'reviews' => $reviews,
            'total_reviews' => count($reviews),
            'is_completed' => count($reviews) === 3
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Reset/delete all reviews for a measure
    parse_str(file_get_contents('php://input'), $delete_data);
    $m9_SC_ID = isset($delete_data['m9_SC_ID']) ? (int)$delete_data['m9_SC_ID'] : 0;
    
    if ($m9_SC_ID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid measure ID']);
        exit;
    }
    
    try {
        $lgu2_conn->begin_transaction();
        
        // Delete all reviews for this measure
        $delete_sql = "DELETE FROM m9_measure_reviews WHERE m9_SC_ID = ?";
        $delete_stmt = $lgu2_conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $m9_SC_ID);
        
        if (!$delete_stmt->execute()) {
            throw new Exception('Failed to reset reviews: ' . $delete_stmt->error);
        }
        
        $affected_rows = $delete_stmt->affected_rows;
        $delete_stmt->close();
        $lgu2_conn->commit();
        
        // Log activity
        try {
            if (file_exists(__DIR__ . '/../..' . '/config/database.php') && 
                file_exists(__DIR__ . '/../..' . '/includes/audit_log.php')) {
                require_once __DIR__ . '/../..' . '/config/database.php';
                $auditConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if (!$auditConn->connect_error) {
                    require_once __DIR__ . '/../..' . '/includes/audit_log.php';
                    $user = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Unknown User';
                    logActivity('review_reset', "Reset all reviews for measure ID: {$m9_SC_ID} by {$user}", $auditConn);
                    $auditConn->close();
                }
            }
        } catch (Exception $auditError) {
            error_log("Audit log error: " . $auditError->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'All reviews have been reset successfully',
            'deleted_count' => $affected_rows
        ]);
        
    } catch (Exception $e) {
        if ($lgu2_conn) {
            $lgu2_conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

if (isset($lgu2_conn) && $lgu2_conn) {
    $lgu2_conn->close();
}
?>
