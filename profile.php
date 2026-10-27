<?php require_once __DIR__ . '/auth.php';
$pageTitle = "Profile Settings";


$success = '';
$error = '';


$mysqli = db_connect();
if ($mysqli->connect_errno) {
    $error = 'Database connection failed.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    $error = 'Your session expired. Please reload the page and try again.';
} else {
    
    $stmt = $mysqli->prepare('SELECT username, email, avatar_url FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();


    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $avatar_path = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 2 * 1024 * 1024;
            
            if (!in_array($file['type'], $allowed_types) || upload_extension($file['name'], ['jpg', 'jpeg', 'png', 'webp']) === null || @getimagesize($file['tmp_name']) === false) {
                $error = 'Invalid file type. Please upload a JPG, PNG, or WebP image.';
            } elseif ($file['size'] > $max_size) {
                $error = 'File is too large. Maximum size is 2MB.';
            } else {
                $upload_dir = 'assets/img/avatars/';
                if (!file_exists(__DIR__ . '/' . $upload_dir)) {
                    mkdir(__DIR__ . '/' . $upload_dir, 0755, true);
                }
                
                $ext = upload_extension($file['name'], ['jpg', 'jpeg', 'png', 'webp']);
                $filename = uniqid('avatar_') . '.' . $ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $filepath)) {
                    $avatar_path = $filepath;
                } else {
                    $error = 'Failed to upload image. Please try again.';
                }
            }
        }

        if (empty($username) || empty($email)) {
            $error = 'Username and email are required.';
        } elseif ($new_password !== '' && strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            // Verify current password if changing password
            $can_update = true;
            if ($new_password !== '') {
                $stmt = $mysqli->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->bind_param('i', $_SESSION['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $current = $result->fetch_assoc();
                $stmt->close();

                if (!password_verify($current_password, $current['password'])) {
                    $error = 'Current password is incorrect.';
                    $can_update = false;
                }
            }

            if ($can_update) {
                // Start transaction
                $mysqli->begin_transaction();
                try {
                    // Update username, email, and avatar if provided
                    if ($avatar_path) {
                        $stmt = $mysqli->prepare('UPDATE users SET username = ?, email = ?, avatar_url = ? WHERE id = ?');
                        $stmt->bind_param('sssi', $username, $email, $avatar_path, $_SESSION['user_id']);
                    } else {
                        $stmt = $mysqli->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
                        $stmt->bind_param('ssi', $username, $email, $_SESSION['user_id']);
                    }
                    $stmt->execute();
                    $stmt->close();

                    // Update password if provided
                    if ($new_password !== '') {
                        $hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $mysqli->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $stmt->bind_param('si', $hash, $_SESSION['user_id']);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $mysqli->commit();
                    $success = 'Settings updated successfully.';
                    
                    // Update session username
                    $_SESSION['username'] = $username;
                    
                    // ➡️ Update session avatar URL if a new one was uploaded
                    if ($avatar_path) {
                        $_SESSION['avatar_url'] = $avatar_path;
                    }
                    
                    // Refresh user data
                    $user['username'] = $username;
                    $user['email'] = $email;
                    
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $error = 'An error occurred. Please try again.';
                }
            }
        }
    }

    $mysqli->close();
}

include __DIR__ . '/includes/layout/start.php';
?>
<div class="container mt-3">
                <div class="card shadow mx-auto" style="max-width: 600px">
                    <div class="card-body p-4">
                        <h1 class="card-title mb-4"><i class="fas fa-user-gear"></i> Account Settings</h1>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div class="mb-4">
                                <label class="form-label">Profile Picture</label>
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="avatar-preview" style="width:100px;height:100px;border-radius:50%;overflow:hidden;border:2px solid var(--border-color)">
                                        <img src="<?= e(avatar_url($user['avatar_url'] ?? '')) ?>" 
                                             alt="Profile picture" style="width:100%;height:100%;object-fit:cover" id="avatarPreview">
                                    </div>
                                    <div class="flex-grow-1">
                                        <input type="file" class="form-control" id="avatar" name="avatar" accept="image/*">
                                        <small class="text-muted d-block mt-1">Maximum file size: 2MB. Supported formats: JPG, PNG, WebP</small>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                            </div>

                            <hr class="my-4">
                            <h5 class="mb-3">Change Password</h5>
                            <small class="text-muted d-block mb-3">Leave blank to keep current password</small>

                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8">
                            </div>

                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="<?= e(url('dashboard.php')) ?>" class="btn btn-light me-md-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
<script>
    // Image preview
    document.getElementById('avatar').addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('avatarPreview').src = e.target.result;
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
</script>
<?php include __DIR__ . '/includes/layout/end.php'; ?>