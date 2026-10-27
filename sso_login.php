<?php
/**
 * SSO Login Receiver
 *
 * Receives a signed SSO token from another LGU2 system and logs the matching
 * LOCAL user in. The token is accepted only if:
 *   - its HS256 signature matches SSO_SECRET (.env)
 *   - it has not expired and is not issued for more than 5 minutes
 *   - it is addressed to this system (target/aud == SYSTEM_KEY)
 *   - it has not been used before (one-time use)
 *   - its email belongs to an existing local user
 * The session always uses the local user's id and role, never the ones in the token.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/sso_token.php';

$login_url = url('index.php');

if (DEMO_MODE) {
    header('Location: ' . $login_url); // no SSO into the portfolio demo
    exit;
}

function sso_fail($code)
{
    global $login_url;
    error_log('SSO login rejected: ' . $code);
    header('Location: ' . $login_url . '?error=' . $code);
    exit;
}

// Prefer POST so the token stays out of logs and browser history; GET kept for systems not yet updated.
$token = $_POST['token'] ?? $_GET['token'] ?? null;
if (!is_string($token) || $token === '') {
    sso_fail('no_sso_token');
}

$secret = (string) env('SSO_SECRET', '');
if ($secret === '') {
    sso_fail('sso_not_configured');
}

$tokenData = validateSSOToken($token, $secret);
if (!$tokenData) {
    sso_fail('invalid_sso_token');
}

// Token must be addressed to this system
$systemKey = env('SYSTEM_KEY', 'research');
$audience = $tokenData['aud'] ?? $tokenData['target'] ?? null;
if ($audience !== $systemKey) {
    sso_fail('sso_wrong_audience');
}

// Only these roles may arrive by SSO (checked against the sender's claim AND the local account)
$allowedRoles = ['admin', 'research', 'superadmin'];
$claimedRole = strtolower($tokenData['user_type'] ?? $tokenData['role'] ?? '');
if (!in_array($claimedRole, $allowedRoles, true)) {
    sso_fail('sso_access_denied');
}

$email = strtolower(trim($tokenData['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sso_fail('invalid_sso_token');
}

$conn = db_connect();
if ($conn->connect_errno) {
    sso_fail('sso_unavailable');
}

// One-time use: remember each token until it expires
$tokenId = hash('sha256', $tokenData['jti'] ?? $token);
$expiresAt = date('Y-m-d H:i:s', (int) $tokenData['exp']);
$conn->query("DELETE FROM sso_used_tokens WHERE expires_at < NOW()");
$stmt = $conn->prepare("INSERT INTO sso_used_tokens (token_hash, expires_at) VALUES (?, ?)");
if (!$stmt) {
    error_log('SSO replay table missing — run tools/migrate.php');
    sso_fail('sso_unavailable');
}
$stmt->bind_param('ss', $tokenId, $expiresAt);
if (!$stmt->execute()) {
    sso_fail('sso_token_used');
}
$stmt->close();

// Map to the local account by email
$stmt = $conn->prepare("SELECT id, fullname, username, email, user_type, avatar_url FROM users WHERE LOWER(email) = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !in_array(strtolower($user['user_type'] ?? ''), $allowedRoles, true)) {
    $conn->close();
    sso_fail('sso_access_denied');
}

// ==========================================
// CREATE SESSION - SSO LOGIN SUCCESS
// ==========================================

session_regenerate_id(true);
unset($_SESSION['otp'], $_SESSION['identifier']);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['username'] = $user['username'];
$_SESSION['email'] = $user['email'];
$_SESSION['user_type'] = strtolower($user['user_type'] ?? '');
$_SESSION['avatar_url'] = $user['avatar_url'];
$_SESSION['sso_login'] = true;
$_SESSION['sso_source'] = (string) ($tokenData['source'] ?? $tokenData['iss'] ?? 'external');
$_SESSION['sso_timestamp'] = time();
$_SESSION['created'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['profile_last_updated'] = time();

logActivity('login', 'User logged in via SSO from ' . $_SESSION['sso_source'], $conn);
$conn->close();

header('Location: ' . url('dashboard.php'));
exit;
