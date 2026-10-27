<?php
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'connection2.php';

$secret_api_key = (string) env('RESEARCH_INBOX_API_KEY', '');
$receiver_name = 'research';
$table_name = 'research_inbox';

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

function respond_success($data, $message = 'Success')
{
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'GET') {
    $api_key = $_GET['api_key'] ?? null;
    if (!api_key_valid(api_key_sent(['api_key' => $api_key]), $secret_api_key)) {
        respond_error('Unauthorized: Invalid API key.', ['table' => $table_name], 401);
    }


    $search = $_GET['search'] ?? null;
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $docket_no = $_GET['docket_no'] ?? null;

    $limit = min(max($limit, 1), 500);
    $offset = max($offset, 0);

    $where_clauses = [];
    $params = [];
    $types = '';

    if ($id) {
        $where_clauses[] = "id = ?";
        $params[] = $id;
        $types .= 'i';
    }

    if ($docket_no) {
        $where_clauses[] = "JSON_EXTRACT(payload_json, '$.docket_no') = ?";
        $params[] = $docket_no;
        $types .= 's';
    }

    if ($search) {
        $where_clauses[] = "(payload_json LIKE ? OR original_filename LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'ss';
    }

    if ($date_from) {
        $where_clauses[] = "received_at >= ?";
        $params[] = $date_from . ' 00:00:00';
        $types .= 's';
    }

    if ($date_to) {
        $where_clauses[] = "received_at <= ?";
        $params[] = $date_to . ' 23:59:59';
        $types .= 's';
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }

    $count_sql = "SELECT COUNT(*) as total FROM `$table_name` $where_sql";
    $count_stmt = $conn->prepare($count_sql);

    if ($count_stmt === false) {
        respond_error('Database query preparation failed.', [
            'table' => $table_name,
            'db_error' => $conn->error
        ], 500);
    }

    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }

    if (!$count_stmt->execute()) {
        $count_stmt->close();
        respond_error('Database query execution failed.', [
            'table' => $table_name,
            'db_error' => $count_stmt->error
        ], 500);
    }

    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
    $count_result->free();
    $count_stmt->close();

    $data_sql = "SELECT * FROM `$table_name` $where_sql ORDER BY received_at DESC LIMIT ? OFFSET ?";
    $data_stmt = $conn->prepare($data_sql);

    if ($data_stmt === false) {
        respond_error('Database query preparation failed.', [
            'table' => $table_name,
            'db_error' => $conn->error
        ], 500);
    }

    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $data_stmt->bind_param($types, ...$params);

    if (!$data_stmt->execute()) {
        $data_stmt->close();
        respond_error('Database query execution failed.', [
            'table' => $table_name,
            'db_error' => $data_stmt->error
        ], 500);
    }

    $result = $data_stmt->get_result();
    $records = [];

    while ($row = $result->fetch_assoc()) {
        $decoded_payload = null;
        if ($row['payload_json']) {
            $decoded_payload = json_decode($row['payload_json'], true);
        }
        
        $records[] = [
            'id' => $row['id'],
            'payload' => $decoded_payload,
            'original_filename' => $row['original_filename'],
            'stored_filename' => $row['stored_filename'],
            'received_at' => $row['received_at'],
            'remote_addr' => $row['remote_addr']
        ];
    }

    $result->free();
    $data_stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => 'Data retrieved successfully',
        'count' => $total_count,
        'data' => $records
    ]);
    exit;

} elseif ($request_method === 'POST') {
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

    $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $receiver_name;
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        respond_error('Failed to create upload directory.', ['path' => $upload_dir], 500);
    }

    $saved_file = null;
    $original_filename = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $original_filename = basename($_FILES['file']['name']);
        $extension = upload_extension($original_filename, UPLOAD_DOC_EXTS);
        if ($extension === null) {
            respond_error('File type not allowed.', ['file' => $original_filename], 415);
        }
        $unique_name = sprintf('%s_%s.%s', $receiver_name, uniqid('', true), $extension);
        $destination = $upload_dir . DIRECTORY_SEPARATOR . $unique_name;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            respond_error('Failed to save uploaded file.', ['file' => $original_filename], 500);
        }

        $saved_file = $unique_name;
    }

    $payload_json = json_encode($payload ?? []);
    $received_at = date('Y-m-d H:i:s');
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

    $create_sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `payload_json` LONGTEXT DEFAULT NULL,
        `original_filename` VARCHAR(255) DEFAULT NULL,
        `stored_filename` VARCHAR(255) DEFAULT NULL,
        `received_at` DATETIME DEFAULT NULL,
        `remote_addr` VARCHAR(45) DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

    if (!$conn->query($create_sql)) {
        respond_error('Table creation failed.', [
            'table' => $table_name,
            'sql' => $create_sql,
            'db_error' => $conn->error,
            'db_errno' => $conn->errno,
            'db_sqlstate' => $conn->sqlstate
        ], 500);
    }

    $insert_sql = "INSERT INTO `$table_name` (
        payload_json,
        original_filename,
        stored_filename,
        received_at,
        remote_addr
    ) VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($insert_sql);
    if ($stmt === false) {
        respond_error('Insert prepare failed.', [
            'table' => $table_name,
            'sql' => $insert_sql,
            'db_error' => $conn->error,
            'db_errno' => $conn->errno,
            'db_sqlstate' => $conn->sqlstate
        ], 500);
    }

    $stmt->bind_param('sssss', $payload_json, $original_filename, $saved_file, $received_at, $remote_addr);

    if (!$stmt->execute()) {
        $stmt_error = $stmt->error;
        $stmt_errno = $stmt->errno;
        $stmt_sqlstate = $stmt->sqlstate;
        $stmt->close();
        respond_error('Insert execute failed.', [
            'table' => $table_name,
            'sql' => $insert_sql,
            'db_error' => $stmt_error,
            'db_errno' => $stmt_errno,
            'db_sqlstate' => $stmt_sqlstate
        ], 500);
    }

    $new_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => 'Data received and saved successfully.',
        'id' => $new_id,
        'table' => $table_name,
        'stored_filename' => $saved_file
    ]);
    exit;

} else {
    respond_error('Invalid request method. Use GET to fetch data or POST to send data.', ['method' => $request_method], 405);
}
?>
