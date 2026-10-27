<?php
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'superadmin']); // same roles the sidebar shows this page to
require_once 'CAconnection.php'; // Includes the lgu2 database connection
$pageTitle = "Create Account";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - LGU Research System</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/super_styles.css')) ?>">
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>
<?php include __DIR__ . '/../../includes/super_header.php'; ?>
<?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>
<?php

$message = "";

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect and sanitize form data
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $email = trim(strtolower($_POST['email'])); // Trim and convert to lowercase
    $user_type = $_POST['user_type'];
    $avatar_url = null;

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif (!can_assign_role($user_type)) {
        $message = "Only an administrator can create admin accounts.";
    } else {
        // Check if username or email already exists
        $check_sql = "SELECT id FROM users WHERE username = ? OR email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = "Username or email already exists. Please choose different credentials.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            
            // Handle avatar upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                $uploadDir = __DIR__ . '/../../uploads/avatars/';
                // Create directory if it doesn't exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                $fileName = 'avatar_' . uniqid() . '.' . $fileExtension;
                $targetPath = $uploadDir . $fileName;
                $relativePath = 'uploads/avatars/' . $fileName; // For storing in database

                // Check if image file is a real image and validate
                $check = getimagesize($_FILES["avatar"]["tmp_name"]);
                if($check === false) {
                    $message = "File is not an image.";
                } else if ($_FILES["avatar"]["size"] > 5000000) { // 5MB
                    $message = "Sorry, your file is too large.";
                } else if(!in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $message = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                } else {
                    if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $targetPath)) {
                        $avatar_url = $relativePath; // Store relative path for database
                    } else {
                        $message = "Sorry, there was an error uploading your file.";
                    }
                }
            }

            if (empty($message)) {
                // Hash the password for security
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Prepare SQL statement to insert data
                $date_created = date('Y-m-d H:i:s');
                $sql = "INSERT INTO users (fullname, username, password, email, user_type, avatar_url, date_created) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssss", $fullname, $username, $hashed_password, $email, $user_type, $avatar_url, $date_created);

                if ($stmt->execute()) {
                    $message = "New account created successfully! You can now login with your credentials.";
                    
                    // Optional: Send welcome email to verify the email works
                    /*
                    use PHPMailer\PHPMailer\PHPMailer;
                    use PHPMailer\PHPMailer\Exception;
                    require 'vendor/autoload.php';
                    
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        require_once __DIR__ . '/../../config/email.php';
                        $mail->Host = MAIL_HOST;
                        $mail->SMTPAuth = true;
                        $mail->Username = MAIL_USERNAME;
                        $mail->Password = MAIL_PASSWORD;
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = MAIL_PORT;
                        
                        $mail->setFrom('no-reply@lgu.gov.ph', 'LGU System');
                        $mail->addAddress($email, $username);
                        $mail->isHTML(true);
                        $mail->Subject = 'Welcome to LGU2 System';
                        $mail->Body = 'Welcome to LGU2 System! Your account has been created successfully.';
                        
                        $mail->send();
                    } catch (Exception $e) {
                        // Don't fail account creation if welcome email fails
                        error_log("Welcome email failed: " . $mail->ErrorInfo);
                    }
                    */
                    
                } else {
                    $message = "Error creating account: " . $stmt->error;
                }

                $stmt->close();
            }
        }
    }
}

// Ensure the connection is closed at the end of the script
$conn->close();

error_reporting(E_ALL);
ini_set('display_errors', 1);

?>

<style>
    :root {
        --primary-blue: #115272;
        --secondary-blue: #004a8b;
        --dark-blue: #0b3d6d;
        --darker-blue: #084157;
        --bg-primary: #f0f4f7;
        --bg-secondary: #f0f0f0;
        --card-bg: rgba(255, 255, 255, 0.95);
        --text-primary: #333333;
        --text-secondary: #666666;
        --border-radius: 16px;
        --border-radius-small: 12px;
        --shadow-base: 0 4px 6px rgba(0, 0, 0, 0.1);
        --shadow-elevated: 0 8px 32px rgba(0, 0, 0, 0.12);
        --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    body {
        background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
        min-height: 100vh;
        margin: 0;
        padding: 0;
        position: relative;
    }

    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: 
            radial-gradient(circle at 20% 80%, rgba(17, 82, 114, 0.1) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(0, 74, 139, 0.1) 0%, transparent 50%),
            radial-gradient(circle at 40% 40%, rgba(11, 61, 109, 0.05) 0%, transparent 50%);
        pointer-events: none;
        z-index: -1;
    }

    .main-container {
        display: flex;
        justify-content: center;
        align-items: center;
        max-width: 1200px;
        width: 95%;
        margin: 30px auto;
        padding: 20px;
        box-sizing: border-box;
        position: relative;
        z-index: 1;
    }

    .unified-card {
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-elevated);
        width: 100%;
        max-width: 1100px;
        position: relative;
        overflow: hidden;
        transition: var(--transition-smooth);
    }

    .unified-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-blue), var(--secondary-blue), var(--dark-blue));
        border-radius: var(--border-radius) var(--border-radius) 0 0;
    }


    .card-header {
        padding: 40px 40px 20px 40px;
        text-align: center;
        border-bottom: 1px solid rgba(17, 82, 114, 0.1);
    }

    .card-content {
        display: flex;
        flex-direction: column;
        padding: 30px 40px 40px 40px;
        gap: 40px;
    }

    @media (min-width: 768px) {
        .card-content {
            flex-direction: row;
            align-items: flex-start;
        }
        
        .form-section {
            flex: 1;
            padding-right: 30px;
        }
        
        .preview-section {
            flex: 0 0 320px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }

    @media (min-width: 1024px) {
        .card-content {
            gap: 50px;
        }
        
        .form-section {
            padding-right: 40px;
        }
        
        .preview-section {
            flex: 0 0 380px;
        }
    }

    h2 {
        color: var(--dark-blue);
        text-align: center;
        margin: 0 0 30px 0;
        font-weight: 700;
        font-size: 28px;
        position: relative;
        padding-bottom: 15px;
    }

    h2::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 60px;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-blue), var(--secondary-blue));
        border-radius: 2px;
    }

    h3 {
        color: var(--dark-blue);
        font-size: 20px;
        font-weight: 600;
        margin: 10px 0 15px 0;
        letter-spacing: 0.3px;
    }

    .form-group {
        margin-bottom: 24px;
        position: relative;
    }

    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--text-primary);
        font-size: 14px;
        letter-spacing: 0.5px;
    }

    input[type="text"],
    input[type="password"],
    input[type="email"],
    select {
        width: 100%;
        padding: 14px 18px;
        border: 2px solid rgba(17, 82, 114, 0.1);
        border-radius: var(--border-radius-small);
        box-sizing: border-box;
        font-size: 15px;
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(10px);
        transition: var(--transition-smooth);
        font-family: inherit;
        color: var(--text-primary);
    }

    input[type="text"]:focus,
    input[type="password"]:focus,
    input[type="email"]:focus,
    select:focus {
        outline: none;
        border-color: var(--primary-blue);
        background: rgba(255, 255, 255, 0.95);
        box-shadow: 0 0 0 4px rgba(17, 82, 114, 0.1);
        transform: translateY(-1px);
    }

    select {
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='%23115272' viewBox='0 0 16 16'%3e%3cpath d='m7.247 4.86-4.796 5.481c-.566.647-.106 1.659.753 1.659h9.592a1 1 0 0 0 .753-1.659l-4.796-5.48a1 1 0 0 0-1.506 0z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 16px;
        padding-right: 40px;
    }

    select option {
        background: rgba(255, 255, 255, 0.95);
        color: var(--text-primary);
        padding: 10px;
    }

    input[type="text"]:read-only {
        background: rgba(240, 244, 247, 0.8);
        color: var(--text-secondary);
        cursor: not-allowed;
    }

    .create-btn {
        width: 100%;
        padding: 16px 24px;
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        color: #ffffff;
        border: none;
        border-radius: var(--border-radius-small);
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition-smooth);
        position: relative;
        overflow: hidden;
        letter-spacing: 0.5px;
        font-family: inherit;
    }

    .create-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .create-btn:hover {
        background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--dark-blue) 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(17, 82, 114, 0.3);
    }

    .create-btn:hover::before {
        left: 100%;
    }

    .create-btn:active {
        transform: translateY(-1px);
    }

    /* Button Ripple Effect */
    .create-btn {
        position: relative;
        overflow: hidden;
    }

    .create-btn .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.4);
        transform: scale(0);
        animation: ripple-animation 0.6s linear;
        pointer-events: none;
    }

    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }

    .avatar-preview-card {
        width: 100%;
        max-width: 320px;
        margin: auto;
    }

    .avatar-preview-circle {
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(240, 244, 247, 0.9) 100%);
        border: 4px solid var(--primary-blue);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        margin: 0 auto 25px auto;
        position: relative;
        cursor: pointer;
        transition: var(--transition-smooth);
        box-shadow: var(--shadow-base);
    }

    .avatar-preview-circle:hover {
        border-color: var(--secondary-blue);
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 12px 30px rgba(17, 82, 114, 0.2);
    }

    .avatar-preview-circle img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        display: none;
        transition: var(--transition-smooth);
    }

    .avatar-placeholder {
        font-size: 70px;
        color: var(--primary-blue);
        opacity: 0.7;
        transition: var(--transition-smooth);
    }

    .upload-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(17, 82, 114, 0.9) 0%, rgba(0, 74, 139, 0.9) 100%);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: var(--transition-smooth);
        border-radius: 50%;
        color: white;
        font-size: 14px;
        font-weight: 600;
        text-align: center;
    }

    .avatar-preview-circle:hover .upload-overlay {
        opacity: 1;
    }

    .file-input-hidden {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        overflow: hidden;
    }

    .upload-instruction {
        color: var(--text-secondary);
        font-size: 13px;
        margin-top: 15px;
        line-height: 1.5;
        font-weight: 500;
    }

    .message {
        text-align: center;
        margin-bottom: 20px;
        font-size: 14px;
        padding: 16px 20px;
        border-radius: var(--border-radius-small);
        font-weight: 500;
        letter-spacing: 0.3px;
        backdrop-filter: blur(10px);
        border: 1px solid;
        position: relative;
        overflow: hidden;
        animation: slideInFromTop 0.4s ease-out;
        background: rgba(209, 236, 241, 0.9);
        color: #0c5460;
        border-color: rgba(190, 229, 235, 0.8);
    }

    @keyframes slideInFromTop {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .message.error {
        background: rgba(248, 215, 218, 0.9);
        color: #721c24;
        border-color: rgba(245, 198, 203, 0.8);
    }

    .message.success {
        background: rgba(212, 237, 218, 0.9);
        color: #155724;
        border-color: rgba(195, 230, 203, 0.8);
    }

    .form-group.avatar-group-mobile {
        text-align: center;
        display: block;
    }

    .form-group.avatar-group-mobile label {
        margin-bottom: 15px;
    }

    @media (min-width: 768px) {
        .form-group.avatar-group-mobile {
            display: none;
        }
    }

    /* Modern Alert System */
    .modern-alert {
        position: fixed;
        top: 20px;
        right: 20px;
        max-width: 400px;
        background: var(--card-bg);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: var(--border-radius-small);
        box-shadow: var(--shadow-elevated);
        padding: 16px 20px;
        z-index: 1000;
        transform: translateX(100%);
        transition: var(--transition-smooth);
        opacity: 0;
    }

    .modern-alert.show {
        transform: translateX(0);
        opacity: 1;
    }

    .modern-alert.error {
        border-left: 4px solid #dc3545;
    }

    .modern-alert.warning {
        border-left: 4px solid #ffc107;
    }

    .modern-alert.success {
        border-left: 4px solid #28a745;
    }

    .alert-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .alert-icon {
        font-size: 20px;
        font-weight: bold;
    }

    .alert-message {
        flex: 1;
        font-size: 14px;
        font-weight: 500;
        color: var(--text-primary);
    }

    .alert-close {
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        color: var(--text-secondary);
        padding: 0;
        transition: var(--transition-smooth);
    }

    .alert-close:hover {
        color: var(--text-primary);
    }

    /* Responsive Enhancements */
    /* Landscape tablet adjustments */
    @media (min-width: 900px) and (max-height: 600px) {
        .main-container {
            margin: 15px auto;
        }
        
        .card-header {
            padding: 30px 40px 15px 40px;
        }
        
        .card-content {
            padding: 20px 40px 30px 40px;
        }
        
        .avatar-preview-circle {
            width: 160px;
            height: 160px;
        }
        
        h2 {
            font-size: 24px;
            margin-bottom: 15px;
        }
    }

    /* Mobile responsive */
    @media (max-width: 767px) {
        .main-container {
            margin: 20px auto;
            width: 98%;
            padding: 10px;
        }
        
        .card-header {
            padding: 30px 25px 15px 25px;
        }
        
        .card-content {
            padding: 20px 25px 30px 25px;
            gap: 30px;
        }
        
        .avatar-preview-circle {
            width: 140px;
            height: 140px;
        }
        
        .avatar-placeholder {
            font-size: 40px;
        }
        
        h2 {
            font-size: 22px;
        }
        
        .modern-alert {
            top: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
        }
    }

    /* Very wide screens */
    @media (min-width: 1400px) {
        .main-container {
            max-width: 1400px;
        }
        
        .unified-card {
            max-width: 1200px;
        }
    }
</style>

<div class="main-container">
    <div class="unified-card">
        <div class="card-header">
            <h2>Create New Account</h2>
            <?php if (!empty($message)): ?>
                <p class="message <?php echo (strpos($message, 'successfully') !== false) ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="card-content">
            <div class="form-section">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="fullname">Full Name</label>
                        <input type="text" id="fullname" name="fullname" placeholder="Enter your full name" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter your desired username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your strong password" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email address" required>
                    </div>
                    <div class="form-group">
                        <label for="user_type">User Type</label>
                        <select id="user_type" name="user_type" required>
                            <option value="">Select User Type</option>
                            <option value="admin">Admin</option>
                            <option value="research">Research</option>
                            <option value="researchstaff">Research Staff</option>
                        </select>
                    </div>
                    <div class="form-group avatar-group-mobile">
                        <label for="avatar">Profile Picture (Optional)</label>
                        <input type="file" id="avatar" name="avatar" accept="image/*" class="file-input-hidden">
                        <p class="upload-instruction">Click on the avatar preview to upload your profile picture</p>
                    </div>
                    <button type="submit" class="create-btn">Create Account</button>
                </form>
            </div>

            <div class="preview-section">
                <div class="avatar-preview-card">
                    <div class="avatar-preview-circle">
                        <img id="avatar-preview" src="<?= e(url('assets/img/default-avatar.png')) ?>" alt="Avatar Preview">
                        <span class="avatar-placeholder" style="display: none;">👤</span>
                        <div class="upload-overlay">
                            <span>Click to<br>Upload Photo</span>
                        </div>
                    </div>
                    <h3>Avatar Preview</h3>
                    <p class="upload-instruction">
                        Supported formats: JPG, PNG, GIF<br>
                        Maximum size: 5MB
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatar-preview');
    const avatarPlaceholder = document.querySelector('.avatar-placeholder');

    avatarInput.addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                showModernAlert('Please select a valid image file (JPG, PNG, or GIF).', 'error');
                this.value = '';
                return;
            }

            // Validate file size (5MB)
            if (file.size > 5000000) {
                showModernAlert('File size must be less than 5MB.', 'error');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                avatarPreview.src = e.target.result;
                avatarPreview.style.display = 'block';
                avatarPlaceholder.style.display = 'none';
                showModernAlert('Image uploaded successfully!', 'success');
            }
            reader.readAsDataURL(file);
        } else {
            // Reset preview to default avatar
            avatarPreview.src = (window.APP_BASE || '') + '/assets/img/default-avatar.png';
            avatarPreview.style.display = 'block';
            avatarPlaceholder.style.display = 'none';
        }
    });

    // Make sure the avatar circle click event works
    const avatarCircleClick = document.querySelector('.avatar-preview-circle');
    if (avatarCircleClick) {
        avatarCircleClick.addEventListener('click', function() {
            avatarInput.click();
        });
    }

    // Enhanced drag and drop functionality (reusing avatarCircleClick variable)
    if (avatarCircleClick) {
        avatarCircleClick.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--secondary-blue)';
            this.style.transform = 'translateY(-3px) scale(1.02)';
        });

        avatarCircleClick.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--primary-blue)';
            this.style.transform = '';
        });

        avatarCircleClick.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--primary-blue)';
            this.style.transform = '';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // Create a new DataTransfer object and assign the files
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                avatarInput.files = dt.files;
                
                // Trigger the change event
                avatarInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    // Modern Alert System
    function showModernAlert(message, type = 'info') {
        // Remove existing alerts
        const existingAlerts = document.querySelectorAll('.modern-alert');
        existingAlerts.forEach(alert => alert.remove());

        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = `modern-alert ${type}`;
        
        // Create alert content
        const alertContent = document.createElement('div');
        alertContent.className = 'alert-content';
        
        // Create icon
        const alertIcon = document.createElement('span');
        alertIcon.className = 'alert-icon';
        
        switch(type) {
            case 'error':
                alertIcon.innerHTML = '❌';
                break;
            case 'success':
                alertIcon.innerHTML = '✅';
                break;
            case 'warning':
                alertIcon.innerHTML = '⚠️';
                break;
            default:
                alertIcon.innerHTML = 'ℹ️';
        }
        
        // Create message
        const alertMessage = document.createElement('span');
        alertMessage.className = 'alert-message';
        alertMessage.textContent = message;
        
        // Create close button
        const alertClose = document.createElement('button');
        alertClose.className = 'alert-close';
        alertClose.innerHTML = '×';
        alertClose.onclick = () => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 300);
        };
        
        // Assemble alert
        alertContent.appendChild(alertIcon);
        alertContent.appendChild(alertMessage);
        alertContent.appendChild(alertClose);
        alertDiv.appendChild(alertContent);
        
        // Add to page
        document.body.appendChild(alertDiv);
        
        // Show alert
        setTimeout(() => alertDiv.classList.add('show'), 100);
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            if (alertDiv.classList.contains('show')) {
                alertDiv.classList.remove('show');
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 5000);
    }


    // Add ripple effect to button
    const createBtn = document.querySelector('.create-btn');
    createBtn.addEventListener('click', function(e) {
        const ripple = document.createElement('span');
        const rect = this.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = e.clientX - rect.left - size / 2;
        const y = e.clientY - rect.top - size / 2;
        
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = x + 'px';
        ripple.style.top = y + 'px';
        ripple.classList.add('ripple');
        
        this.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
    });
</script>

</body>
</html>