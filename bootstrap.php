<?php
/**
 * Shared bootstrap: config, session, database, escaping, CSRF and role checks.
 * Every page and endpoint should load this first (auth.php already does).
 */

require_once __DIR__ . '/config/env.php';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
    define('BASE_URL', rtrim((string) env('APP_BASE_URL', ''), '/'));
    define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
}

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Manila'));
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Absolute URL path inside this app, e.g. url('dashboard.php'). */
function url($path = '')
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Escape for HTML output. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Open a new mysqli connection (Manila time, utf8mb4).
 * $database defaults to the app database; pass another name for a sibling DB on the same server.
 */
function db_connect($database = null)
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(env('DB_HOST', 'localhost'), env('DB_USER', 'root'), env('DB_PASS', ''), $database ?? env('DB_NAME', 'lgu2local'), (int) env('DB_PORT', 3306));
    if ($conn->connect_errno) {
        error_log('DB connection failed: ' . $conn->connect_error);
        return $conn;
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    return $conn;
}

/** Shared connection for the current request. */
function db()
{
    static $conn = null;
    if ($conn === null || !@$conn->ping()) {
        $conn = db_connect();
    }
    return $conn;
}

/** Stop the request with a JSON error. */
function json_fail($message, $status = 400)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** Fail fast when a connection could not be opened, without leaking details. */
function require_db($conn, $json = false)
{
    if ($conn->connect_errno) {
        if ($json) {
            json_fail('Database connection failed.', 500);
        }
        http_response_code(500);
        exit('Database connection failed.');
    }
    return $conn;
}

const UPLOAD_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const UPLOAD_DOC_EXTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'jpg', 'jpeg', 'png', 'zip'];

/**
 * Lower-cased extension of an uploaded file if it is in $allowed, else null.
 * Never trust the client MIME type; the extension decides how the server treats the file.
 */
function upload_extension($originalName, array $allowed)
{
    $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true) ? $ext : null;
}

/**
 * API key sent by a partner system: Authorization: Bearer / X-API-Key header,
 * else the legacy "api_key" field in the body (still used by the other LGU2 systems).
 */
function api_key_sent($payload = null)
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }
    return is_array($payload) ? ($payload['api_key'] ?? null) : null;
}

/** Constant-time API key check; an unset key in .env never matches. */
function api_key_valid($sent, $expected)
{
    if (defined('DEMO_MODE') && DEMO_MODE) {
        return false; // partner APIs are off in the portfolio demo
    }
    return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** True when the request carries the session's CSRF token (form field or X-CSRF-Token header). */
function csrf_valid()
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function current_role()
{
    return strtolower($_SESSION['user_type'] ?? '');
}

/** Deny the request unless the logged-in user has one of $roles ('admin' always passes). */
function require_role(array $roles)
{
    $role = current_role();
    if ($role === 'admin' || in_array($role, $roles, true)) {
        return;
    }
    http_response_code(403);
    exit('You do not have access to this page.');
}

/** Only an admin may give an account the admin or superadmin role. */
function can_assign_role($role)
{
    return !in_array(strtolower((string) $role), ['admin', 'superadmin'], true) || current_role() === 'admin';
}

/** Web path of a stored avatar (users.avatar_url may be relative, root-relative or a full URL). */
function avatar_url($stored)
{
    $stored = trim((string) $stored);
    if ($stored === '') {
        return url('assets/img/default-avatar.png');
    }
    if (preg_match('#^https?://#i', $stored)) {
        return $stored;
    }
    // Older rows carry the local folder name; strip it so BASE_URL decides
    $stored = preg_replace('#^/?local-lgu-research/#', '', $stored);
    return url(ltrim($stored, '/'));
}

// ---------------------------------------------------------------------------
// Portfolio demo mode (DEMO_MODE=true in .env). Never enable it on the real system:
// visitors are logged in automatically and every request that would change data is refused.
// ---------------------------------------------------------------------------

defined('DEMO_MODE') || define('DEMO_MODE', filter_var(env('DEMO_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));
const DEMO_EMAIL = 'demo@example.com';
const DEMO_MESSAGE = 'Portfolio demo with sample data: saving, sending and deleting are turned off.';

/** Log the browser in as the seeded demo admin. False when the demo data is not loaded. */
function demo_login()
{
    $conn = db_connect();
    if ($conn->connect_errno) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id, fullname, username, email, user_type, avatar_url FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $email = DEMO_EMAIL;
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $conn->close();
    if (!$user) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'fullname' => $user['fullname'],
        'username' => $user['username'],
        'email' => $user['email'],
        'user_type' => strtolower($user['user_type']),
        'avatar_url' => $user['avatar_url'],
        'created' => time(),
        'last_activity' => time(),
        'profile_last_updated' => time(),
    ];
    return true;
}

/** Refuse anything that would write, send or delete while in demo mode. */
function demo_guard_request()
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $readOnlyPosts = ['verify_password.php', 'generate_pdf.php'];   // screen-lock unlock, PDF preview
    $readOnlyActions = ['get_user', 'get_requests', 'get_sync_status'];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $isWrite = (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !in_array($script, $readOnlyPosts, true))
        || (isset($_GET['action']) && !in_array($_GET['action'], $readOnlyActions, true));
    if (!$isWrite) {
        return;
    }

    // A normal form post goes back to its page with a notice; everything else gets JSON
    $wantsHtml = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false && !isset($_GET['action']);
    if ($wantsHtml) {
        $_SESSION['demo_notice'] = DEMO_MESSAGE;
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $sameHost = parse_url($back, PHP_URL_HOST) === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        header('Location: ' . ($sameHost ? $back : url('dashboard.php')));
        exit;
    }
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'status' => 'error', 'demo' => true, 'message' => DEMO_MESSAGE]);
    exit;
}

if (DEMO_MODE && PHP_SAPI !== 'cli') {
    demo_guard_request();
}

/** Demo-mode page that stands in for another LGU2 system (null for this system itself). */
function lgu2_preview_url($system)
{
    $pages = [
        'ordinance' => 'contents/lgu2-preview/ordinance.php', 'minutes' => 'contents/lgu2-preview/minutes.php',
        'agenda' => 'contents/lgu2-preview/agenda.php', 'committee' => 'contents/lgu2-preview/committee.php',
        'committeejournal' => 'contents/lgu2-preview/committee-journal.php', 'journal' => 'contents/lgu2-preview/committee-journal.php',
        'archives' => 'contents/lgu2-preview/archives.php', 'racs' => 'contents/records-and-correspondence/measure-docketing.php',
    ];
    return isset($pages[$system]) ? url($pages[$system]) : null;
}

/** Link to another LGU2 system: through SSO normally, to its preview page in the portfolio demo. */
function lgu2_system_link($system)
{
    return (DEMO_MODE && lgu2_preview_url($system)) ? lgu2_preview_url($system) : url('sso_go.php?system=' . $system);
}
