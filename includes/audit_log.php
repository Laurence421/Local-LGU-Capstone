<?php
/**
 * Audit Log System
 * Tracks user activities throughout the LGU Research System
 */

require_once __DIR__ . '/../bootstrap.php';

class AuditLog {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
        $this->createTableIfNotExists();
    }
    
    /**
     * Create audit_logs table if it doesn't exist
     */
    private function createTableIfNotExists() {
        $sql = "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(100),
            action_type VARCHAR(50) NOT NULL,
            action_description TEXT NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action_type (action_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        try {
            $this->conn->query($sql);
        } catch (Exception $e) {
            error_log('Audit log table creation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log an action
     * 
     * @param string $actionType Type of action (login, logout, create, update, delete, view, etc.)
     * @param string $description Detailed description of the action
     * @param int|null $userId User ID (defaults to session user)
     * @param string|null $username Username (defaults to session username)
     * @return bool Success status
     */
    public function log($actionType, $description, $userId = null, $username = null) {
        // Get user info from session if not provided
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
        }
        
        if ($username === null && isset($_SESSION['username'])) {
            $username = $_SESSION['username'];
        }
        
        // Get IP address
        $ipAddress = $this->getClientIP();
        
        // Get user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Insert log entry
        $sql = "INSERT INTO audit_logs (user_id, username, action_type, action_description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('isssss', $userId, $username, $actionType, $description, $ipAddress, $userAgent);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
        } catch (Exception $e) {
            error_log('Audit log insert error: ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Get recent activity logs
     * 
     * @param int $limit Number of logs to retrieve
     * @param int|null $userId Filter by user ID (null for all users)
     * @return array Array of log entries
     */
    public function getRecentActivity($limit = 10, $userId = null) {
        $sql = "SELECT al.id, al.user_id, al.action_type, al.action_description, al.ip_address, al.created_at,
                       COALESCE(u.fullname, al.username, 'Unknown User') as username
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id ";
        
        if ($userId !== null) {
            $sql .= "WHERE al.user_id = ? ";
        }
        
        $sql .= "ORDER BY al.created_at DESC LIMIT ?";
        
        try {
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                if ($userId !== null) {
                    $stmt->bind_param('ii', $userId, $limit);
                } else {
                    $stmt->bind_param('i', $limit);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                $logs = [];
                
                while ($row = $result->fetch_assoc()) {
                    $logs[] = $row;
                }
                
                $stmt->close();
                return $logs;
            }
        } catch (Exception $e) {
            error_log('Audit log retrieval error: ' . $e->getMessage());
        }
        
        return [];
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function getClientIP() {
        $ipAddress = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
        
        return $ipAddress;
    }
    
    /**
     * Get action icon based on action type
     * 
     * @param string $actionType Action type
     * @return string Font Awesome icon class
     */
    public static function getActionIcon($actionType) {
        $icons = [
            'login' => 'fa-sign-in-alt text-success',
            'logout' => 'fa-sign-out-alt text-secondary',
            'create' => 'fa-file-plus text-success',
            'update' => 'fa-edit text-warning',
            'delete' => 'fa-trash text-danger',
            'view' => 'fa-eye text-info',
            'approve' => 'fa-check-circle text-success',
            'reject' => 'fa-times-circle text-danger',
            'submit' => 'fa-paper-plane text-primary',
            'download' => 'fa-download text-info',
            'upload' => 'fa-upload text-primary',
            'send' => 'fa-share text-primary',
            'receive' => 'fa-inbox text-info',
            'account_create' => 'fa-user-plus text-success',
            'account_update' => 'fa-user-edit text-warning',
            'password_reset' => 'fa-key text-warning',
            'profile_update' => 'fa-user-cog text-info',
        ];
        
        return $icons[$actionType] ?? 'fa-circle text-secondary';
    }
    
    /**
     * Format time ago
     * 
     * @param string $datetime Datetime string
     * @return string Formatted time ago string
     */
    public static function timeAgo($datetime) {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $timestamp);
        }
    }
}

/**
 * Helper function to log activity
 * 
 * @param string $actionType Action type
 * @param string $description Action description
 * @param mysqli|null $connection Database connection (optional)
 */
function logActivity($actionType, $description, $connection = null) {
    $closeConnection = false;
    
    // If no connection provided, create one
    if ($connection === null) {
        try {
            $connection = db_connect();
            
            if ($connection->connect_error) {
                error_log('Audit log connection error: ' . $connection->connect_error);
                return false;
            }
            
            $closeConnection = true;
        } catch (Exception $e) {
            error_log('Audit log connection error: ' . $e->getMessage());
            return false;
        }
    }
    
    $auditLog = new AuditLog($connection);
    $result = $auditLog->log($actionType, $description);
    
    if ($closeConnection && $connection) {
        $connection->close();
    }
    
    return $result;
}
?>
