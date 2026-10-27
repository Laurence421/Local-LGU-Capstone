<?php
// Enable error reporting but don't display errors (they interfere with JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers to allow cross-origin requests and indicate JSON response
header('Content-Type: application/json');
// Server-to-server only: no CORS headers on purpose.

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include the database connection
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

// Get the action
$action = $data['action'] ?? '';

try {
    // Set MySQL timezone to Manila time to match PHP timezone
    $conn->query("SET time_zone = '+08:00'");
    
    switch ($action) {
        case 'receive_data':
            // Receive and store data from another environment
            $received_data = $data['data'] ?? [];
            
            if (empty($received_data)) {
                echo json_encode(['success' => false, 'message' => 'No data provided.']);
                exit;
            }
            
            $result = storeReceivedData($conn, $received_data);
            echo json_encode([
                'success' => true,
                'message' => 'Data received and processed successfully.',
                'processed' => count($received_data),
                'inserted' => $result['inserted'],
                'updated' => $result['updated']
            ]);
            break;
            
        case 'get_data':
            // Send data to requesting environment
            $table_data = getTableData($conn, 'm9_similaritychecking');
            echo json_encode([
                'success' => true,
                'message' => 'Data retrieved successfully.',
                'data' => $table_data,
                'count' => count($table_data)
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
            break;
    }
    
} catch (Exception $e) {
    // Log detailed error information
    error_log("sync_endpoint.php Error: " . $e->getMessage());
    error_log("MySQL Error: " . $conn->error);
    error_log("Received payload: " . json_encode($data));
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. See server log.']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

function getTableData($conn, $table) {
    // First check if table exists, create if it doesn't
    $table_check = $conn->query("SHOW TABLES LIKE '{$table}'");
    if ($table_check->num_rows === 0) {
        createSimilarityCheckingTable($conn, $table);
    }
    
    // Check what columns exist in the table
    $columns_sql = "SHOW COLUMNS FROM {$table}";
    $columns_result = $conn->query($columns_sql);
    $primary_key = null;
    
    if ($columns_result) {
        while ($column = $columns_result->fetch_assoc()) {
            if ($column['Key'] === 'PRI') {
                $primary_key = $column['Field'];
                break;
            }
        }
    }
    
    // If no primary key found, try common alternatives
    if (!$primary_key) {
        $possible_keys = ['m9_SC_ID', 'id', 'ID'];
        foreach ($possible_keys as $key) {
            $check_sql = "SELECT {$key} FROM {$table} LIMIT 1";
            if (@$conn->query($check_sql)) {
                $primary_key = $key;
                break;
            }
        }
    }
    
    // Get recent data from the table
    if ($primary_key) {
        $sql = "SELECT * FROM {$table} ORDER BY {$primary_key} DESC LIMIT 1000";
    } else {
        $sql = "SELECT * FROM {$table} LIMIT 1000";
    }
    
    $result = $conn->query($sql);
    $data = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

function createSimilarityCheckingTable($conn, $table) {
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `m9_SC_ID` int(11) NOT NULL AUTO_INCREMENT,
        `date_created` datetime DEFAULT CURRENT_TIMESTAMP,
        `measure_type` varchar(50) DEFAULT NULL,
        `measure_title` text DEFAULT NULL,
        `introducers` text DEFAULT NULL,
        `measure_content` longtext DEFAULT NULL,
        `datetime_submitted` datetime DEFAULT NULL,
        `checking_remarks` text DEFAULT NULL,
        `checking_remarks_pending` text DEFAULT NULL,
        `checking_remarks_approved` text DEFAULT NULL,
        `category` varchar(100) DEFAULT NULL,
        `sp_no` varchar(50) DEFAULT NULL,
        `year_cc` varchar(10) DEFAULT NULL,
        `measure_file` varchar(255) DEFAULT NULL,
        `m1_MC_code` varchar(50) DEFAULT NULL,
        PRIMARY KEY (`m9_SC_ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!$conn->query($sql)) {
        throw new Exception("Error creating table: " . $conn->error);
    }
}

function storeReceivedData($conn, $data) {
    $inserted = 0;
    $updated = 0;
    $table = 'm9_similaritychecking';
    
    // Ensure table exists
    $table_check = $conn->query("SHOW TABLES LIKE '{$table}'");
    if ($table_check->num_rows === 0) {
        createSimilarityCheckingTable($conn, $table);
    }
    
    // Detect the primary key column dynamically
    $columns_sql = "SHOW COLUMNS FROM {$table}";
    $columns_result = $conn->query($columns_sql);
    $primary_key_column = null;
    $known_columns = [];

    if ($columns_result) {
        while ($column = $columns_result->fetch_assoc()) {
            $known_columns[$column['Field']] = true;
            if ($column['Key'] === 'PRI' && !$primary_key_column) {
                $primary_key_column = $column['Field'];
            }
        }
    }
    
    // If no primary key found, try common alternatives
    if (!$primary_key_column) {
        $possible_keys = ['m9_SC_ID', 'id', 'ID'];
        foreach ($possible_keys as $key) {
            $check_sql = "SELECT {$key} FROM {$table} LIMIT 1";
            if (@$conn->query($check_sql)) {
                $primary_key_column = $key;
                break;
            }
        }
    }
    
    if (!$primary_key_column) {
        throw new Exception('Could not determine primary key column for table ' . $table);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Only real columns of the table may be written (column names cannot be bound as parameters)
            $row = array_intersect_key($row, $known_columns);
            $primary_key_value = $row[$primary_key_column] ?? null;
            
            if (!$primary_key_value) {
                continue; // Skip rows without primary key
            }
            
            // Check if record exists
            $check_sql = "SELECT {$primary_key_column} FROM {$table} WHERE {$primary_key_column} = ?";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param('i', $primary_key_value);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            
            if ($exists) {
                // Update existing record
                $update_fields = [];
                $update_values = [];
                $update_types = '';
                
                foreach ($row as $column => $value) {
                    if ($column !== $primary_key_column) {
                        $update_fields[] = "`{$column}` = ?";
                        $update_values[] = $value;
                        $update_types .= 's';
                    }
                }
                
                if (!empty($update_fields)) {
                    $update_sql = "UPDATE {$table} SET " . implode(', ', $update_fields) . " WHERE {$primary_key_column} = ?";
                    $update_values[] = $primary_key_value;
                    $update_types .= 'i';
                    
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param($update_types, ...$update_values);
                    $stmt->execute();
                    $stmt->close();
                    $updated++;
                }
            } else {
                // Insert new record
                $columns = array_keys($row);
                $placeholders = str_repeat('?,', count($columns) - 1) . '?';
                $insert_sql = "INSERT INTO {$table} (`" . implode('`,`', $columns) . "`) VALUES ({$placeholders})";
                
                $stmt = $conn->prepare($insert_sql);
                $values = array_values($row);
                $types = str_repeat('s', count($values));
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $stmt->close();
                $inserted++;
            }
        }
        
        $conn->commit();
        
        return ['inserted' => $inserted, 'updated' => $updated];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
?>
