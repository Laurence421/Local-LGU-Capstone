<?php
session_start();
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
require_once 'CAconnection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load email configuration
$email_config = require_once __DIR__ . '/../../config/email_config.php';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_requests':
            // Get all document requests
            $sql = "SELECT id, user_id, username, email, measure_title, request_reason, status, request_date, updated_at, admin_notes 
                    FROM document_requests 
                    ORDER BY request_date DESC";
            
            $result = $conn->query($sql);
            $requests = [];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $requests[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'data' => $requests]);
            exit;
            
        case 'update_status':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            $request_id = (int)$data['request_id'];
            $status = htmlspecialchars($data['status']);
            $admin_notes = htmlspecialchars($data['admin_notes'] ?? '');
            
            $sql = "UPDATE document_requests SET status = ?, admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $status, $admin_notes, $request_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Request updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update request']);
            }
            exit;
            
        case 'upload_and_send':
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                exit;
            }
            
            $request_id = (int)$_POST['request_id'];
            $email_notes = htmlspecialchars($_POST['email_notes'] ?? '');
            $admin_notes = htmlspecialchars($_POST['admin_notes'] ?? '');
            
            // Get request details
            $sql = "SELECT * FROM document_requests WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            
            if (!$request) {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
                exit;
            }
            
            // Create upload directory if it doesn't exist
            $upload_dir = __DIR__ . '/../../uploads/documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_extension = upload_extension($_FILES['document']['name'], UPLOAD_DOC_EXTS);
            if ($file_extension === null) {
                echo json_encode(['success' => false, 'message' => 'File type not allowed']);
                exit;
            }
            $filename = 'doc_' . $request_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
                exit;
            }
            
            // Send email with attachment
            $email_sent = sendDocumentEmail($request['email'], $request['username'], $request['measure_title'], $email_notes, $file_path, $_FILES['document']['name']);
            
            if ($email_sent) {
                // Update request status to completed
                $sql = "UPDATE document_requests SET status = 'Completed', admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('si', $admin_notes, $request_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Document sent successfully via email']);
            } else {
                // Delete uploaded file if email failed
                unlink($file_path);
                echo json_encode(['success' => false, 'message' => 'Failed to send email']);
            }
            exit;
    }
}

// Function to send email with document attachment using PHPMailer
function sendDocumentEmail($to_email, $username, $measure_title, $email_notes, $file_path, $original_filename) {
    global $email_config;
    $mail = new PHPMailer(true);

    try {
        // Check if we're in local mode (no SMTP configured)
        if ($email_config['local_mode']) {
            // For local development without SMTP server
            $mail->isSendmail();
            // Create a mock send for testing
            error_log("Mock email sent to: $to_email for document: $measure_title");
            return true; // Return true for local testing
        } else {
            // Server settings for production
            $mail->isSMTP();
            $mail->Host       = $email_config['smtp']['host'];
            $mail->SMTPAuth   = $email_config['smtp']['auth'];
            $mail->Username   = $email_config['smtp']['username'];
            $mail->Password   = $email_config['smtp']['password'];
            $mail->SMTPSecure = $email_config['smtp']['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $email_config['smtp']['port'];
        }
        
        // Recipients
        $mail->setFrom($email_config['from']['email'], $email_config['from']['name']);
        $mail->addAddress($to_email, $username);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Document Request - ' . $measure_title;
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: linear-gradient(135deg, #115272, #004a8b); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #115272; color: white; padding: 15px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>LGU Archive System</h2>
                <p>Document Request Fulfilled</p>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($username) . ",</p>
                <p>Your document request has been processed and the requested document is attached to this email.</p>
                <p><strong>Requested Document:</strong> " . htmlspecialchars($measure_title) . "</p>
                " . (!empty($email_notes) ? "<p><strong>Notes:</strong> " . htmlspecialchars($email_notes) . "</p>" : "") . "
                <p>Please find the requested document attached to this email.</p>
                <p>Thank you for using the LGU Archive System.</p>
            </div>
            <div class='footer'>
                <p>LGU Archive System - Document Management</p>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $message;

        // Add attachment if file exists
        if (file_exists($file_path)) {
            $mail->addAttachment($file_path, $original_filename);
        }

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        // Log the error for debugging
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Sender - LGU Research System</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <!-- Super Admin Styles -->
    <link href="<?= e(url('assets/css/super_styles.css')) ?>" rel="stylesheet">

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

.document-sender-container {
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

.card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
    transition: var(--transition);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    margin-bottom: 2rem;
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

.card-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-body {
    padding: 2rem;
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
    vertical-align: middle;
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

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    font-family: 'Montserrat', sans-serif;
    margin: 0.125rem;
}

.btn-primary {
    background: var(--gradient-primary);
    color: white;
}

.btn-success {
    background: var(--gradient-success);
    color: white;
}

.btn-warning {
    background: var(--gradient-warning);
    color: white;
}

.btn-danger {
    background: var(--gradient-danger);
    color: white;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8rem;
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

@media (max-width: 768px) {
    .document-sender-container {
        padding: 1rem;
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
</style>

</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/../../includes/super_header.php'; ?>
    
    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<div class="container">
    <div class="document-sender-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-paper-plane"></i> Document Sender</h1>
            <p>Manage and process document requests from users</p>
        </div>

        <!-- Document Requests Table -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Document Requests</h2>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table" id="requestsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>Measure Title</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Request Date</th>
                                <th>Actions</th>
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

<!-- Process Modal -->
<div class="modal fade" id="processModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Document Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="requestDetails"></div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select id="statusSelect" class="form-control">
                            <option value="Pending">Pending</option>
                            <option value="Processing">Processing</option>
                            <option value="Approved">Approved</option>
                            <option value="Rejected">Rejected</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-3">
                    <label class="form-label">Upload Document</label>
                    <input type="file" id="documentFile" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.png">
                    <small class="text-muted">Supported formats: PDF, DOC, DOCX, TXT, JPG, PNG</small>
                </div>
                
                <div class="mt-3">
                    <label class="form-label">Email Notes</label>
                    <textarea id="emailNotes" class="form-control" rows="2" placeholder="Notes to include in the email to the user..."></textarea>
                </div>
                
                <div class="mt-3">
                    <label class="form-label">Admin Notes</label>
                    <textarea id="adminNotes" class="form-control" rows="3" placeholder="Internal notes about this request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="uploadAndSend()" id="sendBtn">
                    <i class="fas fa-paper-plane"></i> Upload & Send Document
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentRequestId = null;

document.addEventListener('DOMContentLoaded', function() {
    loadRequests();
});

function loadRequests() {
    fetch('?action=get_requests')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            displayRequests(result.data);
        } else {
            console.error('Failed to load requests');
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
            <td>${escapeHtml(request.username)}</td>
            <td>${escapeHtml(request.email)}</td>
            <td>${escapeHtml(request.measure_title)}</td>
            <td>${escapeHtml(request.request_reason.substring(0, 50))}${request.request_reason.length > 50 ? '...' : ''}</td>
            <td><span class="status-badge status-${request.status.toLowerCase()}">${request.status}</span></td>
            <td>${formatDate(request.request_date)}</td>
            <td>
                <button class="btn btn-primary btn-sm" onclick="openProcessModal(${request.id})">
                    <i class="fas fa-cog"></i> Process
                </button>
            </td>
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
                    <h3>No Requests Found</h3>
                    <p>There are no document requests to display.</p>
                </div>
            </td>
        </tr>
    `;
}

function openProcessModal(requestId) {
    currentRequestId = requestId;
    
    // Find the request data
    fetch('?action=get_requests')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const request = result.data.find(r => r.id == requestId);
            if (request) {
                document.getElementById('requestDetails').innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Request ID:</strong> #${request.id}<br>
                            <strong>User:</strong> ${escapeHtml(request.username)}<br>
                            <strong>Email:</strong> ${escapeHtml(request.email)}
                        </div>
                        <div class="col-md-6">
                            <strong>Request Date:</strong> ${formatDate(request.request_date)}<br>
                            <strong>Current Status:</strong> <span class="status-badge status-${request.status.toLowerCase()}">${request.status}</span>
                        </div>
                    </div>
                    <div class="mt-2">
                        <strong>Measure Title:</strong><br>
                        ${escapeHtml(request.measure_title)}
                    </div>
                    <div class="mt-2">
                        <strong>Reason:</strong><br>
                        ${escapeHtml(request.request_reason)}
                    </div>
                `;
                
                document.getElementById('statusSelect').value = request.status;
                document.getElementById('adminNotes').value = request.admin_notes || '';
                
                new bootstrap.Modal(document.getElementById('processModal')).show();
            }
        }
    });
}

function updateRequest() {
    if (!currentRequestId) return;
    
    const status = document.getElementById('statusSelect').value;
    const adminNotes = document.getElementById('adminNotes').value;
    
    fetch('?action=update_status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            request_id: currentRequestId,
            status: status,
            admin_notes: adminNotes
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('processModal')).hide();
            loadRequests(); // Reload the table
            showAlert('Request updated successfully!', 'success');
        } else {
            showAlert('Failed to update request: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred while updating the request.', 'danger');
    });
}

function uploadAndSend() {
    if (!currentRequestId) return;
    
    const fileInput = document.getElementById('documentFile');
    const emailNotes = document.getElementById('emailNotes').value;
    const adminNotes = document.getElementById('adminNotes').value;
    
    if (!fileInput.files[0]) {
        showAlert('Please select a document to upload.', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('document', fileInput.files[0]);
    formData.append('request_id', currentRequestId);
    formData.append('email_notes', emailNotes);
    formData.append('admin_notes', adminNotes);
    
    const sendBtn = document.getElementById('sendBtn');
    const originalText = sendBtn.innerHTML;
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
    
    fetch('?action=upload_and_send', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('processModal')).hide();
            loadRequests(); // Reload the table
            showAlert('Document sent successfully via email!', 'success');
        } else {
            showAlert('Failed to send document: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred while sending the document.', 'danger');
    })
    .finally(() => {
        sendBtn.disabled = false;
        sendBtn.innerHTML = originalText;
    });
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

function showAlert(message, type) {
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.parentNode.removeChild(alertDiv);
        }
    }, 5000);
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
