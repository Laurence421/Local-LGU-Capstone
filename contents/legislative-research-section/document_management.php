<?php
session_start();
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
require_once 'CAconnection.php';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'submit_request':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
                exit;
            }
            
            $measure_title = htmlspecialchars($data['measure_title'] ?? '');
            $request_reason = htmlspecialchars($data['request_reason'] ?? '');
            $email = htmlspecialchars($data['email'] ?? '');
            $user_id = $_SESSION['id'] ?? 0;
            $username = $_SESSION['username'] ?? 'Unknown';
            
            if (empty($measure_title) || empty($request_reason) || empty($email)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                exit;
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
                exit;
            }
            
            // Auto-create table if it doesn't exist
            $createTableSQL = "CREATE TABLE IF NOT EXISTS document_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                measure_title TEXT NOT NULL,
                request_reason TEXT NOT NULL,
                status ENUM('Pending', 'Processing', 'Approved', 'Rejected', 'Completed') DEFAULT 'Pending',
                request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                admin_notes TEXT,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_request_date (request_date),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!$conn->query($createTableSQL)) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit;
            }
            
            // Check if email column exists, if not add it
            $checkEmailColumn = "SHOW COLUMNS FROM document_requests LIKE 'email'";
            $emailResult = $conn->query($checkEmailColumn);
            
            if ($emailResult && $emailResult->num_rows == 0) {
                $addEmailColumn = "ALTER TABLE document_requests ADD COLUMN email VARCHAR(255) NOT NULL AFTER username";
                $conn->query($addEmailColumn);
            }
            
            // Insert the request
            $insertSQL = "INSERT INTO document_requests (user_id, username, email, measure_title, request_reason) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertSQL);
            
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            
            $stmt->bind_param('issss', $user_id, $username, $email, $measure_title, $request_reason);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Document request submitted successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to submit request: ' . $stmt->error]);
            }
            
            $stmt->close();
            exit;
            
        case 'get_requests':
            $user_id = $_SESSION['id'] ?? 0;
            
            // Ensure table exists
            $createTableSQL = "CREATE TABLE IF NOT EXISTS document_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                measure_title TEXT NOT NULL,
                request_reason TEXT NOT NULL,
                status ENUM('Pending', 'Processing', 'Approved', 'Rejected', 'Completed') DEFAULT 'Pending',
                request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                admin_notes TEXT,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_request_date (request_date),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $conn->query($createTableSQL);
            
            // Check if email column exists, if not add it
            $checkEmailColumn = "SHOW COLUMNS FROM document_requests LIKE 'email'";
            $emailResult = $conn->query($checkEmailColumn);
            
            if ($emailResult && $emailResult->num_rows == 0) {
                $addEmailColumn = "ALTER TABLE document_requests ADD COLUMN email VARCHAR(255) NOT NULL AFTER username";
                $conn->query($addEmailColumn);
            }
            
            $sql = "SELECT id, email, measure_title, request_reason, status, request_date, updated_at, admin_notes 
                    FROM document_requests 
                    WHERE user_id = ? 
                    ORDER BY request_date DESC";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            
            echo json_encode(['success' => true, 'data' => $requests]);
            $stmt->close();
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management - LGU Research System</title>
    
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Unicons -->
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Super Admin Styles -->
    <link href="<?= e(url('assets/css/super_styles.css')) ?>" rel="stylesheet">
    
    <!-- System Lock CSS -->
    <link href="<?= e(url('assets/css/system-lock.css')) ?>" rel="stylesheet">

<style>
:root {
    --primary-blue: #115272;
    --primary-blue-dark: #004a8b;
    --primary-blue-darker: #0b3d6d;
    --primary-blue-darkest: #084157;
    --background-light: #f0f4f7;
    --background-lighter: #f8fafc;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --card-shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);
    --border-radius: 12px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --gradient-primary: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-dark));
    --gradient-success: linear-gradient(135deg, #10b981, #059669);
    --gradient-warning: linear-gradient(135deg, #f59e0b, #d97706);
    --gradient-danger: linear-gradient(135deg, #ef4444, #dc2626);
}

.document-management-container {
    padding: 2rem;
    background: var(--background-light);
    min-height: 100vh;
    font-family: 'Montserrat', sans-serif;
}

.page-header {
    background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
    color: white;
    padding: 2rem;
    border-radius: var(--border-radius);
    margin-bottom: 2rem;
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: radial-gradient(circle at 30% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
    pointer-events: none;
}

.page-header h1 {
    margin: 0;
    font-size: 2.5rem;
    font-weight: 700;
    position: relative;
    z-index: 1;
}

.page-header p {
    margin: 0.5rem 0 0 0;
    font-size: 1.1rem;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

.main-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

.card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
    transition: var(--transition);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.card:hover {
    box-shadow: var(--card-shadow-hover);
    transform: translateY(-2px);
}

.card-header {
    background: var(--gradient-primary);
    color: white;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
}

.card-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
    transform: translateX(-100%);
    transition: transform 0.6s;
}

.card:hover .card-header::before {
    transform: translateX(100%);
}

.card-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    z-index: 1;
}

.card-body {
    padding: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--primary-blue-darker);
    font-size: 0.95rem;
}

.form-control {
    width: 100%;
    padding: 0.875rem 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 1rem;
    transition: var(--transition);
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 3px rgba(17, 82, 114, 0.1);
    background: white;
}

.form-control::placeholder {
    color: #9ca3af;
}

textarea.form-control {
    resize: vertical;
    min-height: 120px;
}

.btn {
    padding: 0.875rem 2rem;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-family: 'Montserrat', sans-serif;
}

.btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.3s, height 0.3s;
}

.btn:active::before {
    width: 300px;
    height: 300px;
}

.btn-primary {
    background: var(--gradient-primary);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(17, 82, 114, 0.3);
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

.history-table-container {
    grid-column: 1 / -1;
}

.table-responsive {
    overflow-x: auto;
    border-radius: var(--border-radius);
    box-shadow: var(--card-shadow);
}

.table {
    width: 100%;
    background: white;
    border-collapse: collapse;
    margin: 0;
}

.table th,
.table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.table th {
    background: var(--gradient-primary);
    color: white;
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table tbody tr {
    transition: var(--transition);
}

.table tbody tr:hover {
    background: rgba(17, 82, 114, 0.05);
}

.status-badge {
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

.status-processing {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.status-approved {
    background: var(--gradient-success);
    color: white;
}

.status-rejected {
    background: var(--gradient-danger);
    color: white;
}

.status-completed {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    border: none;
    font-weight: 500;
    position: relative;
    overflow: hidden;
}

.alert::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: currentColor;
}

.alert-success {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
    color: #059669;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
    color: #dc2626;
    border-left: 4px solid #ef4444;
}

.loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6b7280;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    color: var(--primary-blue-darker);
}

.empty-state p {
    margin: 0;
    font-size: 1rem;
}

@media (max-width: 768px) {
    .document-management-container {
        padding: 1rem;
    }
    
    .main-content {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .page-header h1 {
        font-size: 2rem;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    .table th,
    .table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {
    .page-header {
        padding: 1.5rem;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
    }
    
    .card-body {
        padding: 1rem;
    }
}
</style>

</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/../../includes/super_header.php'; ?>
    
    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<div class="container">
    <div class="document-management-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-file-alt"></i> Document Management</h1>
            <p>Request documents from the archives and track your request history</p>
        </div>

        <!-- Main Content Grid -->
        <div class="main-content">
            <!-- Request Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-plus-circle"></i> New Document Request</h2>
                </div>
                <div class="card-body">
                    <form id="documentRequestForm">
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   placeholder="Enter your email address for document delivery" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="measureTitle" class="form-label">Measure Title</label>
                            <input type="text" id="measureTitle" name="measure_title" class="form-control" 
                                   placeholder="Enter the title of the measure you need" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="requestReason" class="form-label">Reason for Request</label>
                            <textarea id="requestReason" name="request_reason" class="form-control" 
                                      placeholder="Please explain why you need this document" required></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i>
                            Submit Request
                        </button>
                    </form>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-bar"></i> Request Statistics</h2>
                </div>
                <div class="card-body">
                    <div id="statsContent">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span style="font-weight: 600; color: var(--primary-blue-darker);">Total Requests:</span>
                            <span id="totalRequests" style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue);">0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span style="font-weight: 600; color: var(--primary-blue-darker);">Pending:</span>
                            <span id="pendingRequests" style="font-size: 1.2rem; font-weight: 600; color: #f59e0b;">0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: var(--primary-blue-darker);">Completed:</span>
                            <span id="completedRequests" style="font-size: 1.2rem; font-weight: 600; color: #10b981;">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Request History Table -->
        <div class="card history-table-container">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Request History</h2>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table" id="requestsTable">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Email</th>
                                <th>Measure Title</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Admin Notes</th>
                                <th>Request Date</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody id="requestsTableBody">
                            <!-- Data will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Container -->
<div id="alertContainer" style="position: fixed; top: 20px; right: 20px; z-index: 1050; max-width: 400px;"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('documentRequestForm');
    const submitBtn = document.getElementById('submitBtn');
    const alertContainer = document.getElementById('alertContainer');
    
    // Load requests on page load
    loadRequests();
    
    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {
            email: formData.get('email'),
            measure_title: formData.get('measure_title'),
            request_reason: formData.get('request_reason')
        };
        
        // Validate form
        if (!data.email.trim() || !data.measure_title.trim() || !data.request_reason.trim()) {
            showAlert('Please fill in all required fields.', 'danger');
            return;
        }
        
        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(data.email)) {
            showAlert('Please enter a valid email address.', 'danger');
            return;
        }
        
        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="loading-spinner"></span> Submitting...';
        
        // Submit request
        fetch('?action=submit_request', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showAlert(result.message, 'success');
                form.reset();
                loadRequests(); // Reload the table
            } else {
                showAlert(result.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred while submitting your request.', 'danger');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        });
    });
    
    function loadRequests() {
        fetch('?action=get_requests')
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                displayRequests(result.data);
                updateStats(result.data);
            } else {
                console.error('Failed to load requests:', result.message);
                showEmptyState();
            }
        })
        .catch(error => {
            console.error('Error loading requests:', error);
            showEmptyState();
        });
    }
    
    function displayRequests(requests) {
        const tbody = document.getElementById('requestsTableBody');
        
        if (requests.length === 0) {
            showEmptyState();
            return;
        }
        
        tbody.innerHTML = requests.map(request => `
            <tr>
                <td><strong>#${request.id}</strong></td>
                <td>${escapeHtml(request.email || 'N/A')}</td>
                <td>${escapeHtml(request.measure_title)}</td>
                <td>${escapeHtml(request.request_reason.substring(0, 100))}${request.request_reason.length > 100 ? '...' : ''}</td>
                <td><span class="status-badge status-${request.status.toLowerCase()}">${request.status}</span></td>
                <td>${request.admin_notes ? escapeHtml(request.admin_notes.substring(0, 50)) + (request.admin_notes.length > 50 ? '...' : '') : '<em class="text-muted">No notes</em>'}</td>
                <td>${formatDate(request.request_date)}</td>
                <td>${formatDate(request.updated_at)}</td>
            </tr>
        `).join('');
    }
    
    function showEmptyState() {
        const tbody = document.getElementById('requestsTableBody');
        tbody.innerHTML = `
            <tr>
                <td colspan="8">
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Requests Yet</h3>
                        <p>You haven't submitted any document requests yet. Use the form above to get started.</p>
                    </div>
                </td>
            </tr>
        `;
    }
    
    function updateStats(requests) {
        const total = requests.length;
        const pending = requests.filter(r => r.status === 'Pending' || r.status === 'Processing').length;
        const completed = requests.filter(r => r.status === 'Completed').length;
        
        document.getElementById('totalRequests').textContent = total;
        document.getElementById('pendingRequests').textContent = pending;
        document.getElementById('completedRequests').textContent = completed;
    }
    
    function showAlert(message, type) {
        const alertId = 'alert-' + Date.now();
        const alertHtml = `
            <div id="${alertId}" class="alert alert-${type}" style="margin-bottom: 1rem; animation: slideInRight 0.3s ease-out;">
                ${message}
            </div>
        `;
        
        alertContainer.insertAdjacentHTML('beforeend', alertHtml);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            const alertElement = document.getElementById(alertId);
            if (alertElement) {
                alertElement.style.animation = 'slideOutRight 0.3s ease-in';
                setTimeout(() => alertElement.remove(), 300);
            }
        }, 5000);
    }
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>

</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
