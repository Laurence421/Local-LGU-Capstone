<?php
/**
 * Create (or reset) an admin account. Command line only.
 *
 *   php tools/create_admin.php <username> <email> [fullname]
 *
 * The password is read from the prompt, never from the command line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

[$script, $username, $email] = $argv + [null, null, null];
$fullname = $argv[3] ?? $username;

if (!$username || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/create_admin.php <username> <email> [fullname]\n");
    exit(1);
}

echo "Password (min 8 characters): ";
$password = trim((string) fgets(STDIN));
if (strlen($password) < 8) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}

$conn = db_connect();
if ($conn->connect_errno) {
    fwrite(STDERR, "Cannot connect to database: {$conn->connect_error}\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$email = strtolower($email);

$stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? OR username = ? LIMIT 1");
$stmt->bind_param('ss', $email, $username);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $stmt = $conn->prepare("UPDATE users SET password = ?, user_type = 'admin', archived = 0 WHERE id = ?");
    $stmt->bind_param('si', $hash, $existing['id']);
    $stmt->execute();
    echo "Updated user #{$existing['id']} to admin with the new password.\n";
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, fullname, user_type) VALUES (?, ?, ?, ?, 'admin')");
    $stmt->bind_param('ssss', $username, $hash, $email, $fullname);
    $stmt->execute();
    echo "Created admin user #{$conn->insert_id}.\n";
}
