<?php
require_once __DIR__ . '/bootstrap.php';

// Portfolio demo: no login screen, go straight in as the demo admin
if (DEMO_MODE) {
    if (!demo_login()) {
        http_response_code(503);
        exit('Demo data is not loaded yet. Run: php tools/seed_demo.php');
    }
    header('Location: ' . url('dashboard.php'));
    exit;
}

// --- AGGRESSIVE CACHE PREVENTION ---
header("Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Vary: *");

// --- DESTROY SESSION IF USER IS LOGGED IN AND RETURNS TO LOGIN PAGE ---
// This ensures that going back to login page always requires fresh authentication
if (isset($_SESSION['user_id']) && !isset($_SESSION['otp'])) {
    // User is fully logged in but came back to login page
    session_unset();
    session_destroy();
    
    // Start new session for potential new login
    session_start();
    session_regenerate_id(true);
    
    // Force reload to clear any cached data
    echo '<script>window.location.replace("index.php");</script>';
    exit;
}

// --- HANDLE 'GO BACK' LINK TO CLEAR OTP SESSION ---
if (isset($_GET['clear_otp'])) {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    header("Location: index.php");
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include the Composer autoloader
require 'vendor/autoload.php';

// Include configuration files
require_once 'config/database.php';
require_once 'config/email.php';
require_once 'includes/audit_log.php';

if (isset($_SESSION['flash'])) {
    $error = $_SESSION['flash'];
    unset($_SESSION['flash']);
} else {
    $error = '';
}

// Helper function to determine error type and format
function getErrorClass($error) {
    if (is_array($error)) {
        $classes = 'error';
        if (isset($error['type'])) {
            $classes .= ' ' . $error['type'] . '-error';
        }
        return $classes;
    }
    return 'error';
}

function getErrorContent($error) {
    if (is_array($error)) {
        $title = htmlspecialchars($error['title'] ?? 'Error');
        $message = htmlspecialchars($error['message'] ?? '');
        return '<div class="error-content"><div class="error-title">' . $title . '</div><div class="error-message">' . $message . '</div></div>';
    }
    return htmlspecialchars($error);
}

// --- CSRF CHECK FOR EVERY FORM ON THIS PAGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    $_SESSION['flash'] = 'Your session expired. Please try again.';
    header('Location: ' . url('index.php'));
    exit;
}

// --- LOGIN THROTTLING (per identifier or IP) ---
const LOGIN_MAX_FAILURES = 10;
const LOGIN_WINDOW_MINUTES = 15;

function loginTooManyFailures($mysqli, $identifier) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS n FROM login_attempts WHERE (identifier = ? OR ip_address = ?) AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
    if (!$stmt) {
        return false; // table not migrated yet
    }
    $stmt->bind_param('ss', $identifier, $ip);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n >= LOGIN_MAX_FAILURES;
}

function loginRecordFailure($mysqli, $identifier) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $mysqli->prepare('INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)');
    if ($stmt) {
        $stmt->bind_param('ss', $identifier, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function loginClearFailures($mysqli, $identifier) {
    $stmt = $mysqli->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    if ($stmt) {
        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $stmt->close();
    }
}

// --- FORGOT PASSWORD LOGIC ---
// Handle forgot password request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $email = trim(strtolower($_POST['email'] ?? ''));
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            $error = 'Database connection failed. Please try again later.';
        } else {
            // Check if email exists in database
            $sql = 'SELECT id, username, email FROM users WHERE LOWER(email) = ? LIMIT 1';
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
                    
                    // Store only a hash of the token; the plain token goes in the email
                    $reset_token_hash = hash('sha256', $reset_token);
                    $insert_sql = 'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)';
                    $insert_stmt = $mysqli->prepare($insert_sql);
                    $insert_stmt->bind_param('iss', $row['id'], $reset_token_hash, $expires_at);
                    
                    if ($insert_stmt->execute()) {
                        // Send reset email
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = MAIL_HOST;
                            $mail->SMTPAuth = true;
                            $mail->Username = MAIL_USERNAME;
                            $mail->Password = MAIL_PASSWORD;
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = MAIL_PORT;
                            
                            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
                            $mail->addAddress($email, $row['username']);
                            
                            // Built from APP_URL, never from the request's Host header
                            $reset_link = rtrim((string) env('APP_URL', ''), '/') . '/index.php?reset_token=' . urlencode($reset_token);
                            
                            $mail->isHTML(true);
                            $mail->Subject = 'Password Reset - LGU Research System';
                            $mail->Body = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
                                <h2 style='color: #115272; text-align: center;'>Password Reset Request</h2>
                                <p>Dear " . e($row['username']) . ",</p>
                                <p>You have requested to reset your password for the LGU Research System.</p>
                                <p>Please click the link below to reset your password:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$reset_link}' style='background: linear-gradient(135deg, #115272 0%, #0d3f5a 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>Reset Password</a>
                                </div>
                                <p><strong>Important:</strong></p>
                                <ul>
                                    <li>This link will expire in 1 hour</li>
                                    <li>If you didn't request this reset, please ignore this email</li>
                                    <li>For security, do not share this link with anyone</li>
                                </ul>
                                <p>If you have any issues, please contact your system administrator.</p>
                                <hr style='margin: 20px 0; border: none; border-top: 1px solid #eee;'>
                                <p style='color: #666; font-size: 12px; text-align: center;'>LGU Research Management System</p>
                            </div>";
                            
                            $mail->send();
                            $_SESSION['reset_email_sent'] = true;
                            $_SESSION['reset_email'] = $email;
                            
                        } catch (Exception $e) {
                            error_log("Reset email error: " . $e->getMessage());
                            $error = 'Failed to send reset email. Please try again later.';
                        }
                    } else {
                        $error = 'Failed to generate reset token. Please try again.';
                    }
                    $insert_stmt->close();
                } else {
                    // Don't reveal if email exists or not for security
                    $_SESSION['reset_email_sent'] = true;
                    $_SESSION['reset_email'] = $email;
                }
                $stmt->close();
            }
            $mysqli->close();
        }
    }
}

// Handle clear reset session
if (isset($_GET['clear_reset'])) {
    unset($_SESSION['reset_email_sent']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['password_reset_success']);
    header('Location: ' . url('index.php'));
    exit;
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $token = $_POST['token'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in both password fields.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            $error = 'Database connection failed. Please try again later.';
        } else {
            // Verify token (stored as a SHA-256 hash)
            $token = hash('sha256', $token);
            $sql = 'SELECT pr.id, pr.user_id, u.username FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0 LIMIT 1';
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    // Valid token, update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_sql = 'UPDATE users SET password = ? WHERE id = ?';
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param('si', $hashed_password, $row['user_id']);
                    
                    if ($update_stmt->execute()) {
                        // Mark token as used
                        $mark_used_sql = 'UPDATE password_resets SET used = 1 WHERE id = ?';
                        $mark_stmt = $mysqli->prepare($mark_used_sql);
                        $mark_stmt->bind_param('i', $row['id']);
                        $mark_stmt->execute();
                        $mark_stmt->close();
                        
                        $_SESSION['password_reset_success'] = true;
                        header('Location: ' . url('index.php'));
                        exit;
                    } else {
                        $error = 'Failed to update password. Please try again.';
                    }
                    $update_stmt->close();
                } else {
                    $error = 'Invalid or expired reset token. Please request a new password reset.';
                }
                $stmt->close();
            }
            $mysqli->close();
        }
    }
}

// --- OTP VERIFICATION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    $otpExpired = !isset($_SESSION['otp_expires']) || time() > $_SESSION['otp_expires'];
    $otpLocked = $_SESSION['otp_attempts'] > 5;
    if ($otpExpired || $otpLocked) {
        session_unset();
        $_SESSION['flash'] = $otpExpired ? 'Your verification code expired. Please log in again.' : 'Too many incorrect codes. Please log in again.';
        header('Location: ' . url('index.php'));
        exit;
    }
    if (isset($_SESSION['otp']) && is_string($_POST['otp']) && hash_equals($_SESSION['otp'], trim($_POST['otp']))) {
        // OTP is correct, finalize login
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            $_SESSION['flash'] = 'Database connection failed. Please try again later.';
            header('Location: ' . url('index.php'));
            exit;
        }

        $user_id = $_SESSION['user_id'];
        $sql = 'SELECT id, fullname, username, password, email, user_type, avatar_url FROM users WHERE id = ?';
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $mysqli->close();

        // Clear OTP and set proper session variables
        unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_attempts']);
        unset($_SESSION['identifier']);
        
        // Set complete session data
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['fullname'] = $row['fullname'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['email'] = $row['email'];
        $_SESSION['user_type'] = strtolower($row['user_type'] ?? '');
        $_SESSION['avatar_url'] = $row['avatar_url'];
        $_SESSION['created'] = time();
        $_SESSION['last_activity'] = time();
        
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Log successful login
        logActivity('login', 'User logged in successfully');
        
        // Redirect to the appropriate dashboard
        if ($row['user_type'] === 'records') {
            header('Location: ' . url('contents/records-and-correspondence/document-tracking.php'));
        } else {
            header('Location: ' . url('dashboard.php'));
        }
        exit;
    } else {
        $error = array(
            'type' => 'otp',
            'title' => 'Invalid OTP Code',
            'message' => 'The verification code you entered is incorrect. Please check the code and try again.'
        );
    }
}

// --- INITIAL LOGIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['otp']) && !isset($_POST['forgot_password']) && !isset($_POST['reset_password'])) {
    $identifier = trim(strtolower($_POST['email'] ?? '')); // Trim and convert to lowercase
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter email/username and password.';
    } else {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            $error = 'Database connection failed. Please try again later.';
        } elseif (loginTooManyFailures($mysqli, $identifier)) {
            $error = array(
                'type' => 'credentials',
                'title' => 'Too Many Attempts',
                'message' => 'Too many failed logins. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.'
            );
        } else {
            // The users table and first admin come from migrations (tools/migrate.php, tools/create_admin.php).
            // Validate user credentials - Updated query to handle case-insensitive email comparison
            $sql = 'SELECT id, username, password, email, user_type, avatar_url FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1';
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    $hash = $row['password'];
                    $verified = false;
                    
                    // Check for plain-text password (one-time migration of old rows only)
                    if (password_get_info($hash)['algo'] === null && hash_equals($hash, $password)) {
                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                        $update_sql = "UPDATE users SET password = ? WHERE id = ?";
                        $update_stmt = $mysqli->prepare($update_sql);
                        $update_stmt->bind_param('si', $new_hash, $row['id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        $verified = true;
                    } 
                    // Check hashed password
                    else if (password_verify($password, $hash)) {
                        $verified = true;
                    }
                    
                    if ($verified) {
                        // Credentials are correct, now send OTP
                        loginClearFailures($mysqli, $identifier);
                        session_regenerate_id(true);
                        $otp = strval(random_int(100000, 999999));
                        $_SESSION['otp'] = $otp;
                        $_SESSION['otp_expires'] = time() + 600;
                        $_SESSION['otp_attempts'] = 0;
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['identifier'] = $identifier;
                        
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = MAIL_HOST;
                            $mail->SMTPAuth = true;
                            $mail->Username = MAIL_USERNAME;
                            $mail->Password = MAIL_PASSWORD;
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = MAIL_PORT;
                            
                            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
                            $mail->addAddress($row['email'], $row['username']); // Use the email from database
                            $mail->isHTML(true);
                            $mail->Subject = 'Your LGU Research System - One-Time Password';
                            
                            // Professional OTP Email Template
                            $mail->Body = '
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset="UTF-8">
                                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                <style>
                                    body { margin: 0; padding: 0; font-family: "Montserrat", Arial, sans-serif; background-color: #f0f4f7; }
                                    .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
                                    .header { background: linear-gradient(135deg, #115272 0%, #004a8b 100%); padding: 40px 20px; text-align: center; }
                                    .header h1 { color: #ffffff; margin: 0; font-size: 28px; font-weight: 700; }
                                    .header p { color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0; font-size: 14px; }
                                    .content { padding: 40px 30px; }
                                    .greeting { font-size: 18px; color: #333333; margin-bottom: 20px; font-weight: 600; }
                                    .message { font-size: 15px; color: #555555; line-height: 1.6; margin-bottom: 30px; }
                                    .otp-container { background: linear-gradient(135deg, #f0f4f7 0%, #e5e7eb 100%); border-radius: 12px; padding: 30px; text-align: center; margin: 30px 0; border: 2px solid #115272; }
                                    .otp-label { font-size: 14px; color: #666666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; font-weight: 600; }
                                    .otp-code { font-size: 42px; font-weight: 800; color: #115272; letter-spacing: 8px; font-family: "Courier New", monospace; margin: 10px 0; text-shadow: 0 2px 4px rgba(17, 82, 114, 0.1); }
                                    .otp-validity { font-size: 13px; color: #888888; margin-top: 15px; }
                                    .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 25px 0; border-radius: 4px; }
                                    .warning-box p { margin: 0; font-size: 14px; color: #856404; }
                                    .footer { background-color: #f8fafc; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
                                    .footer p { margin: 5px 0; font-size: 13px; color: #888888; }
                                    .footer a { color: #115272; text-decoration: none; }
                                    .divider { height: 1px; background: linear-gradient(90deg, transparent, #e5e7eb, transparent); margin: 25px 0; }
                                </style>
                            </head>
                            <body>
                                <div class="email-container">
                                    <div class="header">
                                        <h1>🔐 LGU Research System</h1>
                                        <p>Secure Login Verification</p>
                                    </div>
                                    
                                    <div class="content">
                                        <div class="greeting">Hello, ' . htmlspecialchars($row['fullname'] ?: $row['username']) . '!</div>
                                        
                                        <div class="message">
                                            <p>You have requested to log in to the <strong>LGU Research System</strong>. To complete your login, please use the One-Time Password (OTP) below:</p>
                                        </div>
                                        
                                        <div class="otp-container">
                                            <div class="otp-label">Your One-Time Password</div>
                                            <div class="otp-code">' . $otp . '</div>
                                            <div class="otp-validity">⏱️ Valid for 10 minutes</div>
                                        </div>
                                        
                                        <div class="warning-box">
                                            <p><strong>⚠️ Security Notice:</strong> Never share this code with anyone. LGU staff will never ask for your OTP.</p>
                                        </div>
                                        
                                        <div class="divider"></div>
                                        
                                        <div class="message">
                                            <p style="margin-bottom: 10px;">If you did not request this code, please ignore this email or contact your system administrator immediately.</p>
                                            <p style="margin: 0; font-size: 13px; color: #888888;">This is an automated message. Please do not reply to this email.</p>
                                        </div>
                                    </div>
                                    
                                    <div class="footer">
                                        <p><strong>Local Government Unit 2</strong></p>
                                        <p>Legislative Research Section</p>
                                        <p style="margin-top: 15px;">© ' . date('Y') . ' LGU Research System. All rights reserved.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                            ';
                            
                            $mail->AltBody = 'Your OTP for LGU Research System login is: ' . $otp . '. This code is valid for 10 minutes. Never share this code with anyone.';
                            
                            $mail->send();
                            // Redirect to show OTP form
                            header('Location: ' . url('index.php'));
                            exit;
                        } catch (Exception $e) {
                            $error = 'We could not send your verification code. Please try again later.';
                            unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['user_id']);
                            // Log the error for debugging
                            error_log("Email error for user {$row['username']} (email: {$row['email']}): " . $e->getMessage());
                        }
                    } else {
                        loginRecordFailure($mysqli, $identifier);
                        $error = array(
                            'type' => 'credentials',
                            'title' => 'Invalid Credentials',
                            'message' => 'The email/username or password you entered is incorrect. Please check your credentials and try again.'
                        );
                    }
                } else {
                    // User not found in database
                    loginRecordFailure($mysqli, $identifier);
                    $error = array(
                        'type' => 'credentials',
                        'title' => 'Invalid Credentials',
                        'message' => 'The email/username or password you entered is incorrect. Please check your credentials and try again.'
                    );
                }
                $stmt->close();
            } else {
                // Failed to prepare statement
                $error = 'Database query failed.';
            }
            $mysqli->close();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>LGU2 – Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <script>
        // Force reload on back/forward navigation
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
        
        // Clear form data on page load
        window.addEventListener('load', function() {
            if (document.getElementById('email')) {
                document.getElementById('email').value = '';
            }
            if (document.getElementById('password')) {
                document.getElementById('password').value = '';
            }
            if (document.getElementById('otp')) {
                document.getElementById('otp').value = '';
            }
        });
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Modern OTP Input Functionality
        function initOTPInputs() {
            const otpInputs = document.querySelectorAll('.otp-input');
            const otpHidden = document.getElementById('otp-hidden');
            
            if (otpInputs.length === 0) return;
            
            // Focus first input on load
            otpInputs[0].focus();
            
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    const value = e.target.value;
                    
                    // Only allow numbers
                    if (!/^\d$/.test(value)) {
                        e.target.value = '';
                        return;
                    }
                    
                    // Add filled class
                    e.target.classList.add('filled');
                    e.target.classList.remove('error');
                    
                    // Move to next input
                    if (value && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                    
                    // Update hidden field
                    updateOTPValue();
                    
                    // Auto-submit if all fields are filled
                    if (index === otpInputs.length - 1 && value) {
                        setTimeout(() => {
                            if (getAllOTPValues().length === 6) {
                                document.getElementById('btnLogin').click();
                            }
                        }, 100);
                    }
                });
                
                input.addEventListener('keydown', (e) => {
                    // Handle backspace
                    if (e.key === 'Backspace' && !e.target.value && index > 0) {
                        otpInputs[index - 1].focus();
                        otpInputs[index - 1].value = '';
                        otpInputs[index - 1].classList.remove('filled');
                        updateOTPValue();
                    }
                    
                    // Handle arrow keys
                    if (e.key === 'ArrowLeft' && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                    if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                });
                
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const paste = (e.clipboardData || window.clipboardData).getData('text');
                    const digits = paste.replace(/\D/g, '').slice(0, 6);
                    
                    digits.split('').forEach((digit, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = digit;
                            otpInputs[i].classList.add('filled');
                        }
                    });
                    
                    updateOTPValue();
                    
                    // Focus next empty input or last input
                    const nextEmpty = [...otpInputs].find(input => !input.value);
                    if (nextEmpty) {
                        nextEmpty.focus();
                    } else {
                        otpInputs[otpInputs.length - 1].focus();
                    }
                });
            });
            
            function getAllOTPValues() {
                return [...otpInputs].map(input => input.value).join('');
            }
            
            function updateOTPValue() {
                if (otpHidden) {
                    otpHidden.value = getAllOTPValues();
                }
            }
            
            // Add error animation for wrong OTP
            function showOTPError() {
                otpInputs.forEach(input => {
                    input.classList.add('error');
                    input.classList.remove('filled');
                });
                
                setTimeout(() => {
                    otpInputs.forEach(input => {
                        input.classList.remove('error');
                    });
                    otpInputs[0].focus();
                }, 500);
            }
            
            // Check for error and trigger animation
            const errorDiv = document.querySelector('.error');
            if (errorDiv && errorDiv.textContent.includes('Incorrect OTP')) {
                showOTPError();
            }
        }
        
        // OTP Timer Functionality
        function initOTPTimer() {
            const timerDisplay = document.getElementById('timer-display');
            const resendLink = document.getElementById('resend-otp');
            
            if (!timerDisplay) return;
            
            let timeLeft = 300; // 5 minutes in seconds
            
            const timer = setInterval(() => {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                
                timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                // Change color when time is running out
                const timerElement = document.getElementById('otp-timer');
                if (timeLeft <= 60) {
                    timerElement.classList.add('warning');
                }
                if (timeLeft <= 30) {
                    timerElement.classList.add('expired');
                }
                
                timeLeft--;
                
                if (timeLeft < 0) {
                    clearInterval(timer);
                    timerDisplay.textContent = 'EXPIRED';
                    timerElement.classList.add('expired');
                    if (resendLink) {
                        resendLink.style.display = 'inline-block';
                    }
                }
            }, 1000);
        }
        
        // Enhanced Error Animation System
        function initErrorAnimations() {
            const errorElements = document.querySelectorAll('.error');
            
            errorElements.forEach(errorEl => {
                // Add shake animation for credentials errors
                if (errorEl.classList.contains('credentials-error')) {
                    errorEl.classList.add('shake');
                    
                    // Clear input fields on credentials error
                    const emailInput = document.getElementById('email');
                    const passwordInput = document.getElementById('password');
                    
                    if (emailInput && passwordInput) {
                        // Add error shake to input fields
                        emailInput.style.animation = 'inputErrorShake 0.5s ease-in-out';
                        passwordInput.style.animation = 'inputErrorShake 0.5s ease-in-out';
                        
                        // Focus back to email field
                        setTimeout(() => {
                            passwordInput.value = ''; // Clear password for security
                            emailInput.focus();
                            emailInput.style.animation = '';
                            passwordInput.style.animation = '';
                        }, 600);
                    }
                }
                
                // Auto-dismiss after 8 seconds for non-critical errors
                if (!errorEl.classList.contains('credentials-error')) {
                    setTimeout(() => {
                        if (errorEl.parentNode) {
                            errorEl.style.animation = 'fadeOut 0.4s ease-out';
                            setTimeout(() => {
                                if (errorEl.parentNode) {
                                    errorEl.remove();
                                }
                            }, 400);
                        }
                    }, 8000);
                }
            });
        }
        
        // Add CSS for input error animation
        function addErrorAnimationStyles() {
            if (!document.getElementById('error-animation-styles')) {
                const style = document.createElement('style');
                style.id = 'error-animation-styles';
                style.textContent = `
                    @keyframes inputErrorShake {
                        0%, 100% { transform: translateX(0); }
                        25% { transform: translateX(-4px); }
                        75% { transform: translateX(4px); }
                    }
                    
                    @keyframes fadeOut {
                        from { opacity: 1; transform: scale(1); }
                        to { opacity: 0; transform: scale(0.95); }
                    }
                    
                    .error.credentials-error {
                        border-left: 4px solid #ff6b6b;
                    }
                    
                    .form-field.error-field input {
                        border-color: rgba(255, 107, 107, 0.6) !important;
                        box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1) !important;
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        // Password visibility toggle function - matching reference implementation
        function setupPasswordToggles() {
            document.querySelectorAll('.password-toggle').forEach(function(toggleButton) {
                toggleButton.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordField = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (passwordField.type === 'password') {
                        passwordField.type = 'text';
                        icon.className = 'fas fa-eye-slash';
                        toggleButton.classList.add('show-password');
                        toggleButton.title = 'Hide password';
                    } else {
                        passwordField.type = 'password';
                        icon.className = 'fas fa-eye';
                        toggleButton.classList.remove('show-password');
                        toggleButton.title = 'Show password';
                    }
                });
            });
        }

        // Data Privacy Modal Functions
        function initPrivacyModal() {
            const modal = document.getElementById('privacy-modal');
            const agreeCheckbox = document.getElementById('privacy-agree');
            const continueBtn = document.getElementById('privacy-continue');
            
            // Check if user has already agreed (using sessionStorage)
            const hasAgreed = sessionStorage.getItem('privacy_agreed');
            
            if (!hasAgreed) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
            
            // Enable/disable continue button based on checkbox
            agreeCheckbox.addEventListener('change', function() {
                continueBtn.disabled = !this.checked;
                if (this.checked) {
                    continueBtn.classList.add('enabled');
                } else {
                    continueBtn.classList.remove('enabled');
                }
            });
            
            // Handle continue button click
            continueBtn.addEventListener('click', function() {
                if (agreeCheckbox.checked) {
                    sessionStorage.setItem('privacy_agreed', 'true');
                    modal.style.animation = 'fadeOut 0.3s ease-out';
                    setTimeout(() => {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }, 300);
                }
            });
        }

        // Initialize OTP functionality when page loads
        document.addEventListener('DOMContentLoaded', () => {
            addErrorAnimationStyles();
            initErrorAnimations();
            initOTPInputs();
            initOTPTimer();
            setupPasswordToggles();
            initPrivacyModal();
        });
    </script>
</head>
<body>
    <!-- Data Privacy Modal -->
    <div id="privacy-modal" class="privacy-modal">
        <div class="privacy-modal-content">
            <div class="privacy-modal-header">
                <h2>📋 Important Notice</h2>
                <p class="privacy-subtitle">Please read and agree to continue</p>
            </div>
            
            <div class="privacy-modal-body">
                <div class="privacy-section">
                    <h3><i class="fas fa-shield-alt"></i> Data Privacy Act</h3>
                    <div class="privacy-content">
                        <p>In compliance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, we are committed to protecting your personal information. By using this system, you acknowledge that:</p>
                        <ul>
                            <li>Your personal data will be collected, processed, and stored securely</li>
                            <li>Information will only be used for legitimate LGU research purposes</li>
                            <li>Your data will not be shared with third parties without consent</li>
                            <li>You have the right to access, correct, and request deletion of your data</li>
                            <li>We implement appropriate security measures to protect your information</li>
                        </ul>
                    </div>
                </div>
                
                <div class="privacy-section">
                    <h3><i class="fas fa-file-contract"></i> Terms & Agreement</h3>
                    <div class="privacy-content">
                        <p>By accessing and using the <strong>LGU Research Management System</strong>, you agree to:</p>
                        <ul>
                            <li>Use the system only for authorized and legitimate purposes</li>
                            <li>Maintain the confidentiality of your login credentials</li>
                            <li>Not share your account access with unauthorized individuals</li>
                            <li>Comply with all applicable laws and regulations</li>
                            <li>Report any security vulnerabilities or unauthorized access immediately</li>
                            <li>Accept that misuse of the system may result in account suspension</li>
                        </ul>
                    </div>
                </div>
                
                <div class="privacy-section">
                    <h3><i class="fas fa-exclamation-triangle"></i> Disclaimer</h3>
                    <div class="privacy-content">
                        <p>Please note the following important disclaimers:</p>
                        <ul>
                            <li>The system is provided "as is" without warranties of any kind</li>
                            <li>We strive for accuracy but do not guarantee error-free operation</li>
                            <li>System availability may be interrupted for maintenance or updates</li>
                            <li>Users are responsible for backing up their own work</li>
                            <li>The LGU is not liable for any damages arising from system use</li>
                            <li>Content and features may be modified without prior notice</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="privacy-modal-footer">
                <div class="privacy-checkbox-container">
                    <input type="checkbox" id="privacy-agree" class="privacy-checkbox">
                    <label for="privacy-agree" class="privacy-checkbox-label">
                        I have read and agree to the Data Privacy Act, Terms & Agreement, and Disclaimer
                    </label>
                </div>
                <button id="privacy-continue" class="privacy-continue-btn" disabled>
                    Continue to Login
                </button>
            </div>
        </div>
    </div>
    <div class="stage">
        <div class="card" role="main" aria-label="LGU2 login">
            <div class="brand" aria-hidden="false">
                <div class="logo-wrap">
                    <div style="display:flex; align-items:center; gap:18px;">
                        <div>
                            <div class="logo">
                                <div class="unit">LGU</div>
                                <div class="lgutext">2</div>
                            </div>
                            <div class="logo-small">LOCAL GOVERNMENT UNIT</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-area">
                <?php
                    // Determine current state
                    $current_state = 'login';
                    if (isset($_SESSION['password_reset_success'])) {
                        $current_state = 'reset_success';
                        unset($_SESSION['password_reset_success']);
                    } elseif (isset($_GET['reset_token'])) {
                        $current_state = 'reset_form';
                    } elseif (isset($_SESSION['reset_email_sent'])) {
                        $current_state = 'reset_sent';
                    } elseif (isset($_GET['forgot_password'])) {
                        $current_state = 'forgot_password';
                    } elseif (isset($_SESSION['otp'])) {
                        $current_state = 'otp';
                    }
                ?>
                <div class="form-box<?= $current_state === 'otp' ? ' otp-mode' : '' ?>" role="form" aria-labelledby="login-title" data-state="<?= $current_state ?>">
                    <?php
                    $titles = [
                        'login' => 'Login',
                        'otp' => 'Enter OTP',
                        'forgot_password' => 'Forgot Password',
                        'reset_sent' => 'Check Your Email',
                        'reset_form' => 'Reset Password',
                        'reset_success' => 'Password Reset Successful'
                    ];
                    
                    $subtitles = [
                        'login' => 'Sign in to your account',
                        'otp' => 'A 6-digit code has been sent to your email.',
                        'forgot_password' => 'Enter your email to receive reset instructions',
                        'reset_sent' => 'Password reset instructions have been sent',
                        'reset_form' => 'Enter your new password',
                        'reset_success' => 'You can now login with your new password'
                    ];
                    ?>
                    <h3 id="login-title"><?= $titles[$current_state] ?></h3>
                    <p class="sub"><?= $subtitles[$current_state] ?></p>
                    
                    <?php 
                    // Show errors only for current state
                    $show_error = false;
                    if ($error) {
                        if ($current_state === 'login' && isset($_POST['email']) && !isset($_POST['forgot_password']) && !isset($_POST['otp']) && !isset($_POST['reset_password'])) {
                            $show_error = true;
                        } elseif ($current_state === 'forgot_password' && isset($_POST['forgot_password'])) {
                            $show_error = true;
                        } elseif ($current_state === 'reset_form' && isset($_POST['reset_password'])) {
                            $show_error = true;
                        } elseif ($current_state === 'otp' && isset($_POST['otp'])) {
                            $show_error = true;
                        }
                    }
                    ?>
                    <?php if ($show_error): ?>
        <div class="<?= getErrorClass($error) ?>"><?= getErrorContent($error) ?></div>
    <?php endif; ?>
    
                    <?php if ($current_state === 'otp'): ?>
                    <form method="post" action="" autocomplete="off" class="otp-form">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <label class="small" for="otp-1">One-Time Password</label>
                            <div class="otp-container">
                                <input type="text" class="otp-input" id="otp-1" maxlength="1" autocomplete="off" required>
                                <input type="text" class="otp-input" id="otp-2" maxlength="1" autocomplete="off" required>
                                <input type="text" class="otp-input" id="otp-3" maxlength="1" autocomplete="off" required>
                                <input type="text" class="otp-input" id="otp-4" maxlength="1" autocomplete="off" required>
                                <input type="text" class="otp-input" id="otp-5" maxlength="1" autocomplete="off" required>
                                <input type="text" class="otp-input" id="otp-6" maxlength="1" autocomplete="off" required>
                            </div>
                            <input type="hidden" name="otp" id="otp-hidden">
                        </div>
                        <button class="btn-login" id="btnLogin" type="submit">VERIFY OTP</button>
                        <div class="otp-timer" id="otp-timer">
                            OTP expires in: <span id="timer-display">5:00</span>
                        </div>
                    </form>
                    <div class="controls">
                        <a class="forgot" href="?clear_otp=true">Go Back to Login</a>
                        <a class="resend-otp" id="resend-otp" href="#" style="display: none;">Resend OTP</a>
                    </div>
                    
                    <?php elseif ($current_state === 'forgot_password'): ?>
                    <!-- Forgot Password Form -->
                    <form method="post" action="" autocomplete="off">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <label class="small" for="reset-email">Email Address</label>
                            <input id="reset-email" name="email" type="email" placeholder="Enter your email address" autocomplete="email" required>
                        </div>
                        <button class="btn-login" type="submit" name="forgot_password">Send Reset Link</button>
                    </form>
                    <div class="controls">
                        <a class="forgot" href="?clear_reset=1">Back to Login</a>
                    </div>
                    
                    <?php elseif ($current_state === 'reset_sent'): ?>
                    <!-- Reset Email Sent -->
                    <div class="success-message">
                        <p>📧 If an account with email <strong><?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?></strong> exists, you will receive password reset instructions within a few minutes.</p>
                        <p>Please check your inbox and spam folder.</p>
                    </div>
                    <div class="controls">
                        <a class="forgot" href="?clear_reset=1">Back to Login</a>
                    </div>
                    
                    <?php elseif ($current_state === 'reset_form'): ?>
                    <!-- Password Reset Form -->
                    <form method="post" action="" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['reset_token'] ?? '') ?>">
                        <div class="form-field">
                            <label class="small" for="new-password">New Password</label>
                            <div class="password-field-wrapper">
                                <input id="new-password" name="new_password" type="password" placeholder="Enter your new password" autocomplete="new-password" required minlength="6">
                                <button type="button" class="password-toggle" data-target="new-password" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-field">
                            <label class="small" for="confirm-password">Confirm Password</label>
                            <div class="password-field-wrapper">
                                <input id="confirm-password" name="confirm_password" type="password" placeholder="Confirm your new password" autocomplete="new-password" required minlength="6">
                                <button type="button" class="password-toggle" data-target="confirm-password" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button class="btn-login" type="submit" name="reset_password">Update Password</button>
                    </form>
                    <div class="controls">
                        <a class="forgot" href="?clear_reset=1">Back to Login</a>
                    </div>
                    
                    <?php elseif ($current_state === 'reset_success'): ?>
                    <!-- Reset Success -->
                    <div class="success-message">
                        <p>✅ Your password has been successfully updated!</p>
                        <p>You can now login with your new password.</p>
                    </div>
                    <div class="controls">
                        <a class="forgot" href="?clear_reset=1">Continue to Login</a>
                    </div>
                    
                    <?php else: ?>
                    <!-- Login Form -->
                    <form method="post" action="" autocomplete="off">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <label class="small" for="email">Email</label>
                            <input id="email" name="email" type="text" placeholder="Enter your email or username"
                                autocomplete="off" required>
                        </div>
                        <div class="form-field">
                            <label class="small" for="password">Password</label>
                            <div class="password-field-wrapper">
                                <input id="password" name="password" type="password" placeholder="Enter your password"
                                    autocomplete="off" required>
                                <button type="button" class="password-toggle" data-target="password" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="controls">
                            <a class="forgot" href="?forgot_password=1">Forgot Password?</a>
                        </div>
                        <button class="btn-login" id="btnLogin" type="submit">LOGIN</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>