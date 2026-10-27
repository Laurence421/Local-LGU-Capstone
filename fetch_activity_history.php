<?php
/**
 * Fetch Activity History
 * Returns all activity logs for the activity history modal
 */

require_once __DIR__ . '/bootstrap.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || isset($_SESSION['otp'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit_log.php';

header('Content-Type: application/json');

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
    
    $auditLog = new AuditLog($mysqli);
    
    // Get all activities (limit to 100 for performance)
    $activities = $auditLog->getRecentActivity(100);
    
    // Format the activities with time_ago
    $formattedActivities = [];
    foreach ($activities as $activity) {
        $formattedActivities[] = [
            'id' => $activity['id'],
            'user_id' => $activity['user_id'],
            'username' => $activity['username'],
            'action_type' => $activity['action_type'],
            'action_description' => $activity['action_description'],
            'ip_address' => $activity['ip_address'],
            'created_at' => $activity['created_at'],
            'time_ago' => AuditLog::timeAgo($activity['created_at'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'activities' => $formattedActivities,
        'total' => count($formattedActivities)
    ]);
    
    $mysqli->close();
    
} catch (Exception $e) {
    error_log('Activity history fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch activity history',
        'error' => $e->getMessage()
    ]);
}
?>
