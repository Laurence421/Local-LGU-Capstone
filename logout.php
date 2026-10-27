<?php
// logout.php
require_once __DIR__ . '/bootstrap.php';

// Log logout activity before destroying session
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/audit_log.php';
    logActivity('logout', 'User logged out');
}

// Store session ID before destroying
$old_session = session_id();

// Unset all of the session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Prevent caching of the logout action
header("Cache-Control: no-cache, no-store, must-revalidate, private");
header("Pragma: no-cache");
header("Expires: 0");

// Start a new session with a new ID
session_start();
session_regenerate_id(true);

// Set a flash message for successful logout
$_SESSION['flash'] = 'You have been successfully logged out.';

// Redirect to index.php with cache-busting
header('Location: ' . url('index.php?logout=' . time()));
exit;
?>