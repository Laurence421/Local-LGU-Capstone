<?php
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'superadmin']); // same roles the sidebar shows this page to
require_once 'CAconnection.php'; // Includes the lgu2 database connection

// === PHP BACKEND LOGIC FOR AJAX REQUESTS ===
// This block handles fetching and updating user data for the modal.
// It must be placed at the top to prevent sending HTML headers.
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // Use the same database connection variable
    global $conn;

    // Only an admin may archive, restore or delete an admin account
    if (in_array($_GET['action'], ['archive_user', 'restore_user', 'delete_user'], true) && isset($_GET['id'])) {
        $targetId = intval($_GET['id']);
        $roleStmt = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
        $roleStmt->bind_param('i', $targetId);
        $roleStmt->execute();
        $targetRole = $roleStmt->get_result()->fetch_assoc()['user_type'] ?? '';
        $roleStmt->close();
        if (!can_assign_role($targetRole)) {
            echo json_encode(['success' => false, 'message' => 'Only an administrator can change admin accounts.']);
            exit;
        }
    }

    switch ($_GET['action']) {
        case 'get_user':
            if (!isset($_GET['id'])) {
                echo json_encode(['error' => 'User ID is required.']);
                exit;
            }
            $userId = intval($_GET['id']);
            $sql = "SELECT id, fullname, username, email, user_type, avatar_url FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                echo json_encode($user);
            } else {
                echo json_encode(['error' => 'User not found.']);
            }
            $stmt->close();
            exit;

        case 'update_user':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
                exit;
            }

            $id = $data['id'] ?? null;
            $fullname = $data['fullname'] ?? null;
            $username = $data['username'] ?? null;
            $email = $data['email'] ?? null;
            $user_type = $data['user_type'] ?? null;
            $password = $data['password'] ?? null;
            $avatar_url = $data['avatar_url'] ?? null;

            if (!$id || !$fullname || !$username || !$email || !$user_type) {
                echo json_encode(['success' => false, 'message' => 'Full name, username, email, and user type are required.']);
                exit;
            }

            // Only an admin may grant admin roles or edit an admin account
            $roleStmt = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
            $roleStmt->bind_param('i', $id);
            $roleStmt->execute();
            $targetRole = $roleStmt->get_result()->fetch_assoc()['user_type'] ?? '';
            $roleStmt->close();
            if (!can_assign_role($user_type) || !can_assign_role($targetRole)) {
                echo json_encode(['success' => false, 'message' => 'Only an administrator can change admin accounts.']);
                exit;
            }

            // Build the SQL query dynamically based on what fields are provided
            $updates = [];
            $params = [];
            $types = '';
            $updates[] = "fullname = ?";
            $params[] = $fullname;
            $types .= 's';

            $updates[] = "username = ?";
            $params[] = $username;
            $types .= 's';

            $updates[] = "email = ?";
            $params[] = $email;
            $types .= 's';

            $updates[] = "user_type = ?";
            $params[] = $user_type;
            $types .= 's';

            // Only update password if provided
            if (!empty($password)) {
                $updates[] = "password = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $types .= 's';
            }

            // Only update avatar_url if provided
            if (!empty($avatar_url)) {
                $updates[] = "avatar_url = ?";
                $params[] = $avatar_url;
                $types .= 's';
            }

            $params[] = $id;
            $types .= 'i';

            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                // If user is updating their own profile, update session data
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
                    $_SESSION['fullname'] = $fullname;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['user_type'] = $user_type;
                    if (!empty($avatar_url)) {
                        $_SESSION['avatar_url'] = $avatar_url;
                    }
                }
                echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $stmt->error]);
            }
            $stmt->close();
            exit;

        case 'archive_user':
            if (!isset($_GET['id'])) {
                echo json_encode(['success' => false, 'message' => 'User ID is required.']);
                exit;
            }
            $userId = intval($_GET['id']);
            
            // Check if archived column exists, if not create it
            $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'archived'");
            if ($checkColumn->num_rows == 0) {
                $conn->query("ALTER TABLE users ADD COLUMN archived TINYINT(1) DEFAULT 0");
            }
            
            $sql = "UPDATE users SET archived = 1 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $userId);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'User archived successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to archive user: ' . $stmt->error]);
            }
            $stmt->close();
            exit;

        case 'restore_user':
            if (!isset($_GET['id'])) {
                echo json_encode(['success' => false, 'message' => 'User ID is required.']);
                exit;
            }
            $userId = intval($_GET['id']);
            $sql = "UPDATE users SET archived = 0 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $userId);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'User restored successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to restore user: ' . $stmt->error]);
            }
            $stmt->close();
            exit;

        case 'delete_user':
            if (!isset($_GET['id'])) {
                echo json_encode(['success' => false, 'message' => 'User ID is required.']);
                exit;
            }
            $userId = intval($_GET['id']);
            $sql = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $userId);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'User deleted permanently.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $stmt->error]);
            }
            $stmt->close();
            exit;

        case 'upload_avatar':
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
                exit;
            }

            $file = $_FILES['avatar'];
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
                exit;
            }

            // Validate file size (5MB)
            if ($file['size'] > 5000000) {
                echo json_encode(['success' => false, 'message' => 'File size too large. Maximum 5MB allowed.']);
                exit;
            }

            // Create uploads directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../uploads/avatars/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to create upload directory.']);
                    exit;
                }
            }

            // Generate unique filename
            $fileExtension = upload_extension($file['name'], UPLOAD_IMAGE_EXTS);
            if ($fileExtension === null) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload a JPG, PNG, GIF or WebP image.']);
                exit;
            }
            $fileName = 'avatar_' . uniqid() . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;
            $relativePath = 'uploads/avatars/' . $fileName; // For storing in database

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Avatar uploaded successfully.',
                    'avatar_url' => $relativePath
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
            }
            exit;
    }
}
// === END OF AJAX LOGIC ===

$pageTitle = "Accounts List";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - LGU Research System</title>
    
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Unicons -->
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Super Admin Styles -->
    <link href="<?= e(url('assets/css/super_styles.css')) ?>" rel="stylesheet">
    
    <!-- System Lock CSS -->
    <link href="<?= e(url('assets/css/system-lock.css')) ?>" rel="stylesheet">
<?php

// Check if archived column exists, if not create it
$checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'archived'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN archived TINYINT(1) DEFAULT 0");
}

// View mode: active or archived
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'active';

// Pagination settings
$records_per_page = 12;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$search_condition = '';
$search_params = [];
$param_types = '';

if ($view_mode === 'archived') {
    $search_condition = "WHERE archived = 1";
    if (!empty($search)) {
        $search_condition .= " AND (fullname LIKE ? OR username LIKE ? OR email LIKE ? OR user_type LIKE ?)";
        $search_term = "%$search%";
        $search_params = [$search_term, $search_term, $search_term, $search_term];
        $param_types = 'ssss';
    }
} else {
    $search_condition = "WHERE archived = 0";
    if (!empty($search)) {
        $search_condition .= " AND (fullname LIKE ? OR username LIKE ? OR email LIKE ? OR user_type LIKE ?)";
        $search_term = "%$search%";
        $search_params = [$search_term, $search_term, $search_term, $search_term];
        $param_types = 'ssss';
    }
}

// Get total records for pagination
$count_sql = "SELECT COUNT(*) as total FROM users $search_condition";
$count_stmt = $conn->prepare($count_sql);
if (!empty($search_params)) {
    $count_stmt->bind_param($param_types, ...$search_params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get users with pagination and search
$sql = "SELECT id, fullname, username, email, user_type, avatar_url, date_created 
        FROM users 
        $search_condition 
        ORDER BY date_created DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!empty($search_params)) {
    $search_params[] = $records_per_page;
    $search_params[] = $offset;
    $param_types .= 'ii';
    $stmt->bind_param($param_types, ...$search_params);
} else {
    $stmt->bind_param('ii', $records_per_page, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();
?>

<style>
    .accounts-container {
        max-width: 1400px;
        width: 95%;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        text-align: center;
        margin-bottom: 40px;
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        color: white;
        padding: 40px 20px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(11, 61, 109, 0.3);
        position: relative;
        overflow: hidden;
    }

    .page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: float 6s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translate(-50%, -50%) rotate(0deg); }
        50% { transform: translate(-50%, -50%) rotate(180deg); }
    }

    .page-header h1 {
        font-size: 2.5em;
        margin-bottom: 10px;
        position: relative;
        z-index: 1;
    }

    .page-header p {
        font-size: 1.1em;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }

    .controls-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
        background: white;
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .search-container {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 300px;
    }

    .search-input {
        flex: 1;
        padding: 12px 20px;
        border: 2px solid #e1e5e9;
        border-radius: 25px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }

    .search-input:focus {
        outline: none;
        border-color: #0b3d6d;
        background: white;
        box-shadow: 0 0 10px rgba(11, 61, 109, 0.1);
    }

    .search-btn, .clear-btn {
        padding: 12px 20px;
        border: none;
        border-radius: 20px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .search-btn {
        background: #0b3d6d;
        color: white;
    }

    .search-btn:hover {
        background: #084157;
        transform: translateY(-2px);
    }

    .clear-btn {
        background: #6c757d;
        color: white;
    }

    .clear-btn:hover {
        background: #545b62;
        transform: translateY(-2px);
    }

    .stats-info {
        display: flex;
        align-items: center;
        gap: 20px;
        font-weight: 500;
        color: #0b3d6d;
    }

    .accounts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }

    .account-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        border: 1px solid #e9ecef;
    }

    .account-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(11, 61, 109, 0.15);
    }

    .account-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #0b3d6d, #1a5490, #2980b9);
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .avatar-container {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid #e9ecef;
        transition: all 0.3s ease;
        position: relative;
    }

    .account-card:hover .avatar-container {
        border-color: #0b3d6d;
        transform: scale(1.1);
    }

    .avatar-container img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .avatar-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: bold;
    }

    .user-info {
        flex: 1;
    }

    .username {
        font-size: 1.2em;
        font-weight: 700;
        color: #0b3d6d;
        margin: 0 0 5px 0;
    }

    .user-type {
        display: inline-block;
        padding: 4px 12px;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .card-body {
        margin-bottom: 15px;
    }

    .email {
        color: #6c757d;
        font-size: 0.95em;
        margin-bottom: 10px;
        word-break: break-word;
    }

    .date-created {
        color: #495057;
        font-size: 0.85em;
        font-weight: 500;
    }

    .card-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .action-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: 500;
        transition: all 0.3s ease;
        text-align: center;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }

    .edit-btn {
        background: #17a2b8;
        color: white;
    }

    .edit-btn:hover {
        background: #138496;
        transform: translateY(-1px);
    }

    .archive-btn {
        background: #ffc107;
        color: #000;
    }

    .archive-btn:hover {
        background: #e0a800;
        transform: translateY(-1px);
    }

    .delete-btn {
        background: #dc3545;
        color: white;
    }

    .delete-btn:hover {
        background: #c82333;
        transform: translateY(-1px);
    }

    .restore-btn {
        background: #28a745;
        color: white;
    }

    .restore-btn:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .view-toggle {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .toggle-btn {
        padding: 10px 20px;
        border: 2px solid #0b3d6d;
        background: white;
        color: #0b3d6d;
        border-radius: 20px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .toggle-btn:hover {
        background: #f0f4f7;
        transform: translateY(-2px);
    }

    .toggle-btn.active {
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        color: white;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .empty-state-icon {
        font-size: 4em;
        color: #dee2e6;
        margin-bottom: 20px;
    }

    .empty-state h3 {
        color: #6c757d;
        margin-bottom: 10px;
    }

    .empty-state p {
        color: #adb5bd;
        margin-bottom: 30px;
    }

    .create-account-btn {
        display: inline-block;
        padding: 12px 24px;
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        color: white;
        text-decoration: none;
        border-radius: 25px;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .create-account-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(11, 61, 109, 0.3);
        color: white;
        text-decoration: none;
    }

    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 40px;
        flex-wrap: wrap;
    }

    .pagination a, .pagination span {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 45px;
        height: 45px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .pagination a {
        background: white;
        color: #0b3d6d;
        border: 2px solid #e9ecef;
    }

    .pagination a:hover {
        background: #0b3d6d;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(11, 61, 109, 0.2);
    }

    .pagination .current {
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        color: white;
        border: 2px solid #0b3d6d;
    }

    .pagination .disabled {
        background: #f8f9fa;
        color: #adb5bd;
        border: 2px solid #e9ecef;
        cursor: not-allowed;
    }

    @media (max-width: 768px) {
        .accounts-grid {
            grid-template-columns: 1fr;
        }
        
        .controls-bar {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-container {
            min-width: unset;
        }
        
        .page-header h1 {
            font-size: 2em;
        }
    }

    .loading-animation {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #0b3d6d;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* New styles for the edit modal only */
    #editUserModal.modal {
        display: none; 
        position: fixed;
        z-index: 100;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.6);
        justify-content: center;
        align-items: center;
        overflow: auto;
        padding-top: 50px;
    }

    #editUserModal .modal-content {
        background-color: #fff;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        width: 90%;
        max-width: 500px;
        position: relative;
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .close-btn {
        color: #aaa;
        position: absolute;
        top: 15px;
        right: 25px;
        font-size: 30px;
        font-weight: bold;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .close-btn:hover,
    .close-btn:focus {
        color: #333;
    }

    #editUserModal .modal-content h2 {
        text-align: center;
        color: #0b3d6d;
        margin-bottom: 25px;
        font-size: 1.8em;
    }

    #editUserModal .modal-content label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #495057;
    }

    #editUserModal .modal-content input,
    #editUserModal .modal-content select {
        width: 100%;
        padding: 12px;
        margin-bottom: 20px;
        border: 1px solid #e1e5e9;
        border-radius: 8px;
        box-sizing: border-box;
    }

    .save-btn {
        width: 100%;
        padding: 15px;
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 1em;
        font-weight: bold;
        transition: all 0.3s ease;
    }

    .save-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(11, 61, 109, 0.3);
    }

    #editUserModal .modal-message {
        margin-top: 15px;
        padding: 10px;
        border-radius: 8px;
        text-align: center;
        font-weight: 500;
    }

    /* Avatar Upload Styles */
    .avatar-upload-section {
        margin-bottom: 20px;
    }

    .avatar-upload-container {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 15px;
        border: 2px dashed #e1e5e9;
        border-radius: 12px;
        background: #f8f9fa;
        transition: all 0.3s ease;
    }

    .avatar-upload-container:hover {
        border-color: #0b3d6d;
        background: #f0f8ff;
    }

    .avatar-preview-edit {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid #e1e5e9;
        position: relative;
        cursor: pointer;
        transition: all 0.3s ease;
        flex-shrink: 0;
    }

    .avatar-preview-edit:hover {
        border-color: #0b3d6d;
        transform: scale(1.05);
    }

    .avatar-preview-edit img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .avatar-placeholder-edit {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #0b3d6d, #1a5490);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 32px;
        font-weight: bold;
    }

    .upload-overlay-edit {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(11, 61, 109, 0.9), rgba(26, 84, 144, 0.9));
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
        border-radius: 50%;
        color: white;
        font-size: 12px;
        font-weight: 600;
        text-align: center;
    }

    .avatar-preview-edit:hover .upload-overlay-edit {
        opacity: 1;
    }

    .upload-options {
        flex: 1;
    }


    .remove-avatar-btn {
        width: 100%;
        padding: 8px 12px;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .remove-avatar-btn:hover {
        background: #c82333;
        transform: translateY(-1px);
    }

    /* Custom Confirmation Modal */
    .confirm-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.6);
        justify-content: center;
        align-items: center;
    }

    .confirm-modal-content {
        background: white;
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 15px 50px rgba(0,0,0,0.3);
        max-width: 450px;
        width: 90%;
        animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(-30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .confirm-modal-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .confirm-modal-icon {
        font-size: 3em;
    }

    .confirm-modal-title {
        font-size: 1.5em;
        font-weight: 700;
        color: #0b3d6d;
        margin: 0;
    }

    .confirm-modal-body {
        margin-bottom: 25px;
        color: #495057;
        line-height: 1.6;
    }

    .confirm-modal-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .confirm-btn, .cancel-btn {
        padding: 12px 24px;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
        font-size: 0.95em;
    }

    .confirm-btn {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
    }

    .confirm-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
    }

    .confirm-btn.archive {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        color: #000;
    }

    .confirm-btn.archive:hover {
        box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
    }

    .cancel-btn {
        background: #6c757d;
        color: white;
    }

    .cancel-btn:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    /* Success Toast */
    .success-toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 15px 25px;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(40, 167, 69, 0.3);
        z-index: 10000;
        animation: slideInRight 0.3s ease-out;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
    }

    @keyframes slideInRight {
        from { opacity: 0; transform: translateX(100px); }
        to { opacity: 1; transform: translateX(0); }
    }
</style>

</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/../../includes/super_header.php'; ?>
    
    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<div class="accounts-container">
    <div class="page-header">
        <h1>Accounts List</h1>
        <p>Manage and view all registered user accounts in the system</p>
    </div>

    <div class="controls-bar">
        <form method="GET" class="search-container">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
            <input type="text" 
                   name="search" 
                   class="search-input" 
                   placeholder="🔍 Search by full name, username, email, or user type..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="search-btn">
                <span>Search</span>
            </button>
            <?php if (!empty($search)): ?>
                <a href="?view=<?php echo htmlspecialchars($view_mode); ?>" class="clear-btn">
                    <span>Clear</span>
                </a>
            <?php endif; ?>
        </form>
        
        <div class="view-toggle">
            <a href="?view=active" class="toggle-btn <?php echo $view_mode === 'active' ? 'active' : ''; ?>">
                👥 Active Accounts
            </a>
            <a href="?view=archived" class="toggle-btn <?php echo $view_mode === 'archived' ? 'active' : ''; ?>">
                📦 Archived
            </a>
        </div>
        
        <div class="stats-info">
            <span>📊 Total: <?php echo $total_records; ?> accounts</span>
            <?php if (!empty($search)): ?>
                <span>🔍 Found: <?php echo count($users); ?> results</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($users)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">👤</div>
            <h3><?php echo !empty($search) ? 'No accounts found' : 'No accounts created yet'; ?></h3>
            <p><?php echo !empty($search) ? 'Try adjusting your search terms or clear the search to see all accounts.' : 'Get started by creating the first user account in the system.'; ?></p>
            <?php if (empty($search)): ?>
                <a href="create_account.php" class="create-account-btn">
                    ➕ Create First Account
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="accounts-grid">
            <?php foreach ($users as $user): ?>
                <div class="account-card">
                    <div class="card-header">
                        <div class="avatar-container">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <!-- Always try to load the avatar with fallback to default -->
                                <img src="<?= e(avatar_url($user['avatar_url'])) ?>" alt="Avatar" 
                                     onerror="this.onerror=null; this.src='<?= e(url('assets/img/default-avatar.png')) ?>';">
                            <?php else: ?>
                                <img src="<?= e(url('assets/img/default-avatar.png')) ?>" alt="Default Avatar">
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <h3 class="username"><?php echo htmlspecialchars($user['fullname'] ?: $user['username']); ?></h3>
                            <span class="user-type"><?php echo htmlspecialchars($user['user_type']); ?></span>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="email">
                            📧 <?php echo htmlspecialchars($user['email']); ?>
                        </div>
                        <div class="date-created">
                            📅 Created: <?php echo date('M j, Y \a\t g:i A', strtotime($user['date_created'])); ?>
                        </div>
                    </div>
                    
                    <div class="card-actions">
                        <?php if ($view_mode === 'archived'): ?>
                            <button class="action-btn restore-btn" onclick="restoreUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')">
                                ♻️ Restore
                            </button>
                            <button class="action-btn delete-btn" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')">
                                🗑️ Delete
                            </button>
                        <?php else: ?>
                            <button class="action-btn edit-btn" onclick="editUser(<?php echo $user['id']; ?>)">
                                ✏️ Edit
                            </button>
                            <button class="action-btn archive-btn" onclick="archiveUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')">
                                📦 Archive
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&view=<?php echo urlencode($view_mode) . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>">‹</a>
                <?php else: ?>
                    <span class="disabled">‹</span>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                $url_params = 'view=' . urlencode($view_mode) . (!empty($search) ? '&search=' . urlencode($search) : '');
                
                if ($start > 1) {
                    echo '<a href="?page=1&' . $url_params . '">1</a>';
                    if ($start > 2) {
                        echo '<span class="disabled">...</span>';
                    }
                }

                for ($i = $start; $i <= $end; $i++) {
                    if ($i == $page) {
                        echo '<span class="current">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . '&' . $url_params . '">' . $i . '</a>';
                    }
                }

                if ($end < $total_pages) {
                    if ($end < $total_pages - 1) {
                        echo '<span class="disabled">...</span>';
                    }
                    echo '<a href="?page=' . $total_pages . '&' . $url_params . '">' . $total_pages . '</a>';
                }
                ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&view=<?php echo urlencode($view_mode) . (!empty($search) ? '&search=' . urlencode($search) : ''); ?>">›</a>
                <?php else: ?>
                    <span class="disabled">›</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Custom Confirmation Modal -->
<div id="confirmModal" class="confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-header">
            <span class="confirm-modal-icon" id="confirmIcon">⚠️</span>
            <h3 class="confirm-modal-title" id="confirmTitle">Confirm Action</h3>
        </div>
        <div class="confirm-modal-body" id="confirmMessage">
            Are you sure you want to proceed?
        </div>
        <div class="confirm-modal-actions">
            <button class="cancel-btn" onclick="closeConfirmModal()">Cancel</button>
            <button class="confirm-btn" id="confirmButton" onclick="confirmAction()">Confirm</button>
        </div>
    </div>
</div>

<div id="editUserModal" class="modal">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <h2>Edit User Account</h2>
        <form id="editUserForm">
            <input type="hidden" id="editUserId" name="id">
            <label for="editFullname">Full Name</label>
            <input type="text" id="editFullname" name="fullname" required>

            <label for="editUsername">Username</label>
            <input type="text" id="editUsername" name="username" required>

            <label for="editEmail">Email</label>
            <input type="email" id="editEmail" name="email" required>

            <label for="editUserType">User Type</label>
            <select id="editUserType" name="user_type">
                <option value="admin">Admin</option>
                <option value="research">Research</option>
                <option value="researchstaff">Research Staff</option>
            </select>

            <label for="editPassword">New Password (Leave blank to keep current)</label>
            <input type="password" id="editPassword" name="password" placeholder="Enter new password">

            <div class="avatar-upload-section">
                <label>Profile Picture</label>
                <div class="avatar-upload-container">
                    <div class="avatar-preview-edit">
                        <img id="editAvatarPreview" src="#" alt="Avatar Preview" style="display: none;">
                        <div class="avatar-placeholder-edit" id="editAvatarPlaceholder">👤</div>
                        <div class="upload-overlay-edit">
                            <span>Click to<br>Upload Photo</span>
                        </div>
                    </div>
                    <div class="upload-options">
                        <input type="file" id="editAvatarFile" accept="image/*" style="display: none;">
                        <input type="hidden" id="editAvatarUrl" name="avatar_url">
                        <button type="button" class="remove-avatar-btn" id="removeAvatarBtn" style="margin-top: 10px; display: none;">Remove Photo</button>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="save-btn">Save Changes</button>
        </form>
        <div id="modalMessage" class="modal-message"></div>
    </div>
</div>

<script>
    const modal = document.getElementById('editUserModal');
    const closeBtn = document.querySelector('.close-btn');
    const editForm = document.getElementById('editUserForm');
    const modalMessage = document.getElementById('modalMessage');

    // Function to open the modal and fetch user data
    function editUser(userId) {
        modalMessage.style.display = 'block';
        modalMessage.style.backgroundColor = '#f0f8ff';
        modalMessage.style.color = '#0b3d6d';
        modalMessage.innerHTML = 'Fetching user data... <div class="loading-animation"></div>';

        // The AJAX call now targets this same file with the 'action' parameter
        fetch('accounts_list.php?action=get_user&id=' + userId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(user => {
                if (user.error) {
                    modalMessage.style.backgroundColor = '#f8d7da';
                    modalMessage.style.color = '#721c24';
                    modalMessage.innerHTML = 'Error: ' + user.error;
                    return;
                }

                document.getElementById('editUserId').value = user.id;
                document.getElementById('editFullname').value = user.fullname || '';
                document.getElementById('editUsername').value = user.username;
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editUserType').value = user.user_type;
                document.getElementById('editAvatarUrl').value = user.avatar_url || '';
                
                // Load existing avatar if available
                loadExistingAvatar(user.avatar_url, user.username);

                modalMessage.style.display = 'none';
                modal.style.display = 'flex';
            })
            .catch(error => {
                console.error('Error fetching user data:', error);
                modalMessage.style.backgroundColor = '#f8d7da';
                modalMessage.style.color = '#721c24';
                modalMessage.innerHTML = 'Failed to load user data.';
            });
    }

    // Function to close the modal
    function closeModal() {
        modal.style.display = 'none';
        modalMessage.style.display = 'none';
        modalMessage.innerHTML = '';
        editForm.reset();
    }

    // Close modal when the close button is clicked
    closeBtn.onclick = closeModal;

    // Close modal when clicking outside of it
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }


    // Custom confirmation modal
    let confirmCallback = null;
    const confirmModal = document.getElementById('confirmModal');
    const confirmButton = document.getElementById('confirmButton');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');

    function showConfirmModal(title, message, icon, callback, btnClass = '') {
        confirmTitle.textContent = title;
        confirmMessage.innerHTML = message;
        confirmIcon.textContent = icon;
        confirmCallback = callback;
        confirmButton.className = 'confirm-btn ' + btnClass;
        confirmModal.style.display = 'flex';
    }

    function closeConfirmModal() {
        confirmModal.style.display = 'none';
        confirmCallback = null;
    }

    function confirmAction() {
        if (confirmCallback) {
            confirmCallback();
        }
        closeConfirmModal();
    }

    // Close modal when clicking outside
    confirmModal.addEventListener('click', function(e) {
        if (e.target === confirmModal) {
            closeConfirmModal();
        }
    });

    // Success toast notification
    function showSuccessToast(message) {
        const toast = document.createElement('div');
        toast.className = 'success-toast';
        toast.innerHTML = '<span style="font-size: 1.5em;">✓</span> ' + message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideInRight 0.3s ease-out reverse';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Archive functionality
    function archiveUser(userId, username) {
        showConfirmModal(
            'Archive Account',
            'Are you sure you want to archive the account for <strong>"' + username + '"</strong>?<br><br>The account will be moved to archived accounts and can be restored later.',
            '📦',
            function() {
                fetch('accounts_list.php?action=archive_user&id=' + userId, {
                    method: 'GET'
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showSuccessToast('User "' + username + '" archived successfully!');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showConfirmModal('Error', 'Error archiving user: ' + result.message, '❌', null);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showConfirmModal('Error', 'An unexpected error occurred during archiving.', '❌', null);
                });
            },
            'archive'
        );
    }

    // Restore functionality
    function restoreUser(userId, username) {
        showConfirmModal(
            'Restore Account',
            'Are you sure you want to restore the account for <strong>"' + username + '"</strong>?<br><br>The account will be moved back to active accounts.',
            '♻️',
            function() {
                fetch('accounts_list.php?action=restore_user&id=' + userId, {
                    method: 'GET'
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showSuccessToast('User "' + username + '" restored successfully!');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showConfirmModal('Error', 'Error restoring user: ' + result.message, '❌', null);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showConfirmModal('Error', 'An unexpected error occurred during restoration.', '❌', null);
                });
            }
        );
    }

    // Delete functionality (permanent)
    function deleteUser(userId, username) {
        showConfirmModal(
            'Permanently Delete Account',
            'Are you sure you want to <strong>permanently delete</strong> the account for <strong>"' + username + '"</strong>?<br><br><span style="color: #dc3545; font-weight: 600;">⚠️ This action cannot be undone!</span>',
            '🗑️',
            function() {
                fetch('accounts_list.php?action=delete_user&id=' + userId, {
                    method: 'GET'
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showSuccessToast('User "' + username + '" deleted permanently!');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showConfirmModal('Error', 'Error deleting user: ' + result.message, '❌', null);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showConfirmModal('Error', 'An unexpected error occurred during deletion.', '❌', null);
                });
            }
        );
    }

    // Add loading states for search
    document.querySelector('.search-container form')?.addEventListener('submit', function() {
        const button = this.querySelector('.search-btn');
        const originalContent = button.innerHTML;
        button.innerHTML = '<div class="loading-animation"></div>';
        button.disabled = true;
        
        // Re-enable after a delay (in case of slow loading)
        setTimeout(() => {
            button.innerHTML = originalContent;
            button.disabled = false;
        }, 3000);
    });

    // Add smooth scroll to top when pagination is clicked
    document.querySelectorAll('.pagination a').forEach(link => {
        link.addEventListener('click', function() {
            window.scrollTo({top: 0, behavior: 'smooth'});
        });
    });

    // Add keyboard navigation for search
    document.querySelector('.search-input')?.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            this.blur();
        }
    });

    // Avatar upload functionality
    const editAvatarFile = document.getElementById('editAvatarFile');
    const editAvatarPreview = document.getElementById('editAvatarPreview');
    const editAvatarPlaceholder = document.getElementById('editAvatarPlaceholder');
    const avatarPreviewEdit = document.querySelector('.avatar-preview-edit');
    const editAvatarUrl = document.getElementById('editAvatarUrl');
    const removeAvatarBtn = document.getElementById('removeAvatarBtn');

    // Click to upload functionality
    avatarPreviewEdit.addEventListener('click', function() {
        editAvatarFile.click();
    });

    // File upload handler
    editAvatarFile.addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                showConfirmModal('Invalid File', 'Please select a valid image file (JPG, PNG, or GIF).', '⚠️', null);
                this.value = '';
                return;
            }

            // Validate file size (5MB)
            if (file.size > 5000000) {
                showConfirmModal('File Too Large', 'File size must be less than 5MB.', '⚠️', null);
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                editAvatarPreview.src = e.target.result;
                editAvatarPreview.style.display = 'block';
                editAvatarPlaceholder.style.display = 'none';
                removeAvatarBtn.style.display = 'block';
            }
            reader.readAsDataURL(file);
        }
    });


    // Remove avatar functionality
    removeAvatarBtn.addEventListener('click', function() {
        resetAvatarPreview();
        editAvatarFile.value = '';
        editAvatarUrl.value = '';
    });

    // Function to reset avatar preview
    function resetAvatarPreview() {
        editAvatarPreview.src = (window.APP_BASE || '') + '/assets/img/default-avatar.png';
        editAvatarPreview.style.display = 'block';
        editAvatarPlaceholder.style.display = 'none';
        removeAvatarBtn.style.display = 'none';
    }

    // Function to load existing avatar
    function loadExistingAvatar(avatarUrl, username) {
        resetAvatarPreview();
        
        if (avatarUrl) {
            // Ensure the avatar URL has the proper path format
            const fullAvatarPath = avatarUrl.startsWith('/') ? avatarUrl : '/' + avatarUrl;
            
            const img = new Image();
            img.onload = function() {
                editAvatarPreview.src = fullAvatarPath;
                editAvatarPreview.style.display = 'block';
                editAvatarPlaceholder.style.display = 'none';
                removeAvatarBtn.style.display = 'block';
                // Set the hidden input value
                editAvatarUrl.value = avatarUrl;
            };
            img.onerror = function() {
                // If avatar URL is invalid, show default avatar
                editAvatarPreview.src = (window.APP_BASE || '') + '/assets/img/default-avatar.png';
                editAvatarPreview.style.display = 'block';
                editAvatarPlaceholder.style.display = 'none';
                removeAvatarBtn.style.display = 'block';
                editAvatarUrl.value = '';
            };
            img.src = fullAvatarPath;
        } else {
            // No avatar, show default avatar
            editAvatarPreview.src = (window.APP_BASE || '') + '/assets/img/default-avatar.png';
            editAvatarPreview.style.display = 'block';
            editAvatarPlaceholder.style.display = 'none';
            removeAvatarBtn.style.display = 'block';
            editAvatarUrl.value = '';
        }
    }

    // Enhanced form submission to handle file upload
    editForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Check if we need to upload a file
        const fileInput = document.getElementById('editAvatarFile');
        const urlInput = document.getElementById('editAvatarUrl');
        
        if (fileInput.files[0]) {
            // Handle file upload first
            uploadAvatarFile(fileInput.files[0]).then(uploadedUrl => {
                // Update the avatar_url with uploaded file path
                urlInput.value = uploadedUrl;
                submitUserUpdate();
            }).catch(error => {
                console.error('File upload error:', error);
                modalMessage.style.display = 'block';
                modalMessage.style.backgroundColor = '#f8d7da';
                modalMessage.style.color = '#721c24';
                modalMessage.innerHTML = 'Error uploading file: ' + error.message;
            });
        } else {
            // No file to upload, proceed with form submission
            submitUserUpdate();
        }
    });

    // Function to upload avatar file
    function uploadAvatarFile(file) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('avatar', file);
            
            modalMessage.style.display = 'block';
            modalMessage.style.backgroundColor = '#f0f8ff';
            modalMessage.style.color = '#0b3d6d';
            modalMessage.innerHTML = 'Uploading image... <div class="loading-animation"></div>';

            fetch('accounts_list.php?action=upload_avatar', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    resolve(result.avatar_url);
                } else {
                    reject(new Error(result.message));
                }
            })
            .catch(error => {
                reject(error);
            });
        });
    }

    // Separate function for user update submission
    function submitUserUpdate() {
        const formData = new FormData(editForm);
        const data = Object.fromEntries(formData.entries());

        modalMessage.style.display = 'block';
        modalMessage.style.backgroundColor = '#f0f8ff';
        modalMessage.style.color = '#0b3d6d';
        modalMessage.innerHTML = 'Saving changes... <div class="loading-animation"></div>';

        fetch('accounts_list.php?action=update_user', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                modalMessage.style.backgroundColor = '#d4edda';
                modalMessage.style.color = '#155724';
                modalMessage.innerHTML = 'Changes saved successfully! Reloading...';
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                modalMessage.style.backgroundColor = '#f8d7da';
                modalMessage.style.color = '#721c24';
                modalMessage.innerHTML = 'Error: ' + result.message;
            }
        })
        .catch(error => {
            modalMessage.style.backgroundColor = '#f8d7da';
            modalMessage.style.color = '#721c24';
            modalMessage.innerHTML = 'An unexpected error occurred.';
            console.error('Error:', error);
        });
    }
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- System Lock JavaScript -->
<script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>

</body>
</html>