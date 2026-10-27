<?php
// auth.php — include at the top of every logged-in page.

require_once __DIR__ . '/bootstrap.php';

// Portfolio demo: visitors are logged in as the seeded demo admin (writes are refused in bootstrap.php)
if (DEMO_MODE && (!isset($_SESSION['user_id']) || isset($_SESSION['otp'])) && !demo_login()) {
    http_response_code(503);
    exit('Demo data is not loaded yet. Run: php tools/seed_demo.php');
}

// --- ENHANCED CACHE PREVENTION ---
// Prevent any caching of protected pages
header("Cache-Control: no-cache, no-store, must-revalidate, private"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
header("Cache-Control: post-check=0, pre-check=0", false);
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

function auth_redirect_to_login($message)
{
    // Portfolio demo: start a fresh demo session on the requested page instead
    if (DEMO_MODE && demo_login()) {
        return;
    }
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['flash'] = $message;
    header('Location: ' . url('index.php'), true, 302);
    exit;
}

// --- SESSION VALIDATION WITH ADDITIONAL CHECKS ---
// Check if user is properly logged in (not just has user_id, but also no OTP pending)
if (!isset($_SESSION['user_id']) || isset($_SESSION['otp'])) {
    auth_redirect_to_login('Please login first');
}

// Reject cross-site POSTs and cross-site "?action=" calls (several pages change data on GET).
// Session cookies are also SameSite=Lax (bootstrap.php).
if (isset($_GET['action']) && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    http_response_code(403);
    exit('Cross-site request blocked.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $originHost = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;
    $ownHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if ($originHost !== null && $originHost !== $ownHost) {
        http_response_code(403);
        exit('Cross-site request blocked.');
    }
}

// Regenerate the session ID every hour to limit fixation/hijacking
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 3600) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Log out after 1 hour of inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    auth_redirect_to_login('Session expired. Please login again.');
}

// Update last activity time stamp
$_SESSION['last_activity'] = time();

// Refresh user profile data from database (every 5 minutes for good performance).
// user_id is always a local users.id (sso_login.php maps SSO users by email), so this is safe.
if (!isset($_SESSION['profile_last_updated']) || (time() - $_SESSION['profile_last_updated'] > 300)) {
    $mysqli = db_connect();
    if (!$mysqli->connect_errno) {
        $stmt = $mysqli->prepare("SELECT fullname, username, email, user_type, avatar_url FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $_SESSION['user_id']);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user) {
                $mysqli->close();
                auth_redirect_to_login('Your account is no longer available.');
            }
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['user_type'] = strtolower($user['user_type'] ?? '');
            $_SESSION['avatar_url'] = $user['avatar_url'];
            $_SESSION['profile_last_updated'] = time();
        }
        $mysqli->close();
    } else {
        error_log('Profile refresh error: ' . $mysqli->connect_error);
    }
}
