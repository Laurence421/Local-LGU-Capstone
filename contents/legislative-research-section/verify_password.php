<?php
require_once __DIR__ . '/../../bootstrap.php';

// Don't require auth.php as it may redirect - we need to handle this ourselves
if (!isset($_SESSION['user_id']) || isset($_SESSION['otp'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once 'connection2.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_password') {
        $password = $_POST['password'] ?? '';
        
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Password is required']);
            exit;
        }
        
        $user_id = $_SESSION['user_id'];
        
        // Get user's password hash from database
        $sql = "SELECT password FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        
        $stmt->bind_param("i", $user_id);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            $stmt->close();
            exit;
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Verify password (screen unlock). Five wrong tries end the session.
        // The portfolio demo user has no usable password, so any entry unlocks the screen there.
        if (DEMO_MODE || password_verify($password, $user['password'])) {
            $_SESSION['unlock_failures'] = 0;
            echo json_encode(['success' => true, 'message' => 'Password verified']);
        } else {
            $_SESSION['unlock_failures'] = ($_SESSION['unlock_failures'] ?? 0) + 1;
            if ($_SESSION['unlock_failures'] >= 5) {
                session_unset();
                session_destroy();
                echo json_encode(['success' => false, 'message' => 'Too many attempts. Please log in again.', 'logout' => true]);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Invalid password']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
