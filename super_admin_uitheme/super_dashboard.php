<?php
/**
 * Super Admin Dashboard for Vanilla PHP Systems
 * 
 * INSTALLATION:
 * 1. Copy this file to your system's root directory
 * 2. Update the database connection below
 * 3. Update the stats queries to match your system's tables
 * 
 * USAGE:
 * Include in your existing dashboard or use as a standalone page.
 */

require_once __DIR__ . '/../auth.php';

// Allow admin and research roles to access super admin panel
$allowedRoles = ['admin', 'research', 'superadmin'];
$userRole = $_SESSION['user_type'] ?? $_SESSION['role'] ?? '';
if (!in_array($userRole, $allowedRoles)) {
    header('Location: ' . url('dashboard.php?error=access_denied'));
    exit;
}

// Include SSO functions
require_once __DIR__ . '/super_sso.php';

$mysqli = db_connect();
if ($mysqli->connect_error) {
    // Fallback to default stats if connection fails
    error_log('Super Dashboard DB connection failed: ' . $mysqli->connect_error);
    $mysqli = null;
}

// ==========================================
// STATS - Update queries for your system
// ==========================================
$stats = [
    'total_records' => 0,
    'pending_items' => 0,
    'active_users' => 0,
    'recent_activity' => 0
];

// Fetch stats from database if connection is available
if ($mysqli) {
    try {
        // Total records from similarity checking table
        $result = $mysqli->query("SELECT COUNT(*) as count FROM m9_similaritychecking");
        if ($result) {
            $stats['total_records'] = $result->fetch_assoc()['count'];
            $result->free();
        }
        
        // Pending items (Draft status)
        $result = $mysqli->query("SELECT COUNT(*) as count FROM m9_similaritychecking WHERE measure_status = 'Draft'");
        if ($result) {
            $stats['pending_items'] = $result->fetch_assoc()['count'];
            $result->free();
        }
        
        // Active users from main database
        require_once __DIR__ . '/../config/database.php';
        $mainConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$mainConn->connect_error) {
            $result = $mainConn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
            if ($result) {
                $stats['active_users'] = $result->fetch_assoc()['count'];
                $result->free();
            }
            $mainConn->close();
        }
        
        // Recent activity (last 7 days)
        $result = $mysqli->query("SELECT COUNT(*) as count FROM m9_similaritychecking WHERE datetime_submitted >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($result) {
            $stats['recent_activity'] = $result->fetch_assoc()['count'];
            $result->free();
        }
    } catch (Exception $e) {
        error_log('Super Dashboard stats query error: ' . $e->getMessage());
    }
}

// Get user info
$userName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
$userEmail = $_SESSION['email'] ?? '';
$userAvatar = avatar_url($_SESSION['avatar_url'] ?? '');
$currentSystem = getCurrentSystemKey();
$systemInfo = SSO_SYSTEMS[$currentSystem] ?? ['name' => 'LGU Research System', 'color' => '#115272', 'icon' => 'fa-magnifying-glass-chart'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - <?php echo htmlspecialchars($systemInfo['name']); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Unicons -->
    <link rel="stylesheet" href="https://unicons.iconscout.com/release/v4.0.0/css/line.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Super Admin Styles -->
    <link rel="stylesheet" href="<?= e(url('assets/css/super_styles.css')) ?>">
    
    <!-- System Lock CSS -->
    <link href="<?= e(url('assets/css/system-lock.css')) ?>" rel="stylesheet">
    
    <!-- Dashboard Styles -->
    <link rel="stylesheet" href="<?= e(url('assets/css/dashboard.css')) ?>">
    
    <style>
        :root {
            --primary-color: <?php echo $systemInfo['color']; ?>;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #1a1a2e 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 0;
            margin-bottom: 0;
            box-shadow: none;
        }
        
        .system-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .system-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        
        .system-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            color: inherit;
        }
        
        .system-card.current {
            border: 2px solid var(--primary-color);
            background: linear-gradient(135deg, rgba(var(--primary-color-rgb), 0.05) 0%, white 100%);
        }
        
        .system-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
        }
        
        .chart-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        
        .quick-action-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            border: none;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- Include Super Admin Header -->
    <?php include __DIR__ . '/../includes/super_header.php'; ?>
    
    <!-- Include Super Admin Sidebar -->
    <?php include __DIR__ . '/super_sidebar.php'; ?>
    
    <!-- Main Content -->
    
    <div class="container">
        <!-- Quick System Navigation -->
        <div class="mb-3">
            <h5 class="fw-bold mb-3"><i class="fas fa-layer-group me-2"></i>All Systems</h5>
            <div class="row g-2">
                <?php foreach (SSO_SYSTEMS as $key => $system): ?>
                <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                    <a href="<?php echo $key === $currentSystem ? '#' : getSSOUrl($key); ?>" 
                       class="system-card <?php echo $key === $currentSystem ? 'current' : ''; ?>">
                        <div class="d-flex align-items-center gap-3">
                            <div class="system-icon" style="background-color: <?php echo $system['color']; ?>">
                                <i class="fas <?php echo $system['icon']; ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="mb-0 small fw-semibold"><?php echo $system['name']; ?></h6>
                                <?php if ($key === $currentSystem): ?>
                                <span class="badge bg-success mt-1" style="font-size: 0.65rem;">Current</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="mb-3">
            <div class="row g-2">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="ms-3">
                                <h6 class="text-muted mb-1 small">Total Records</h6>
                                <h4 class="fw-bold mb-0"><?php echo number_format($stats['total_records']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="ms-3">
                                <h6 class="text-muted mb-1 small">Pending Items</h6>
                                <h4 class="fw-bold mb-0"><?php echo number_format($stats['pending_items']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="ms-3">
                                <h6 class="text-muted mb-1 small">Active Users</h6>
                                <h4 class="fw-bold mb-0"><?php echo number_format($stats['active_users']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="ms-3">
                                <h6 class="text-muted mb-1 small">Recent Activity</h6>
                                <h4 class="fw-bold mb-0"><?php echo number_format($stats['recent_activity']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- System Activity & Quick Actions -->
        <div class="row g-2 mb-3">
            <!-- Activity Chart -->
            <div class="col-12 col-lg-8">
                <div class="chart-card h-100">
                    <h5 class="fw-bold mb-3"><i class="fas fa-chart-bar me-2"></i>System Activity</h5>
                    <img src="<?= e(url('assets/img/LGU2.png')) ?>" alt="LGU Logo" style="width: 48px; height: 48px; object-fit: contain;">
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="col-12 col-lg-4">
                <div class="chart-card h-100">
                    <h5 class="fw-bold mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    <div class="d-grid gap-2">
                        <a href="<?php echo getSSOUrl('racs'); ?>" class="quick-action-btn btn btn-success">
                            <i class="fas fa-folder-open me-2"></i>Go to Records
                        </a>
                        <a href="<?php echo getSSOUrl('ordinance'); ?>" class="quick-action-btn btn btn-danger">
                            <i class="fas fa-gavel me-2"></i>View Ordinances
                        </a>
                        <a href="<?php echo getSSOUrl('agenda'); ?>" class="quick-action-btn btn btn-warning">
                            <i class="fas fa-calendar-days me-2"></i>Check Agenda
                        </a>
                        <a href="<?php echo getSSOUrl('journal'); ?>" class="quick-action-btn btn btn-info text-white">
                            <i class="fas fa-book me-2"></i>Open Journal
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity Table -->
        <div class="row g-2 mb-3">
            <div class="col-12">
                <div class="chart-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0"><i class="fas fa-history me-2"></i>Recent Activity</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Details</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <span class="badge bg-success">SSO Login</span>
                                    </td>
                                    <td><?php echo htmlspecialchars($userName); ?></td>
                                    <td>Logged in via SSO</td>
                                    <td class="text-muted small">Just now</td>
                                </tr>
                                <!-- Add more activity rows from your database -->
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="fas fa-database me-2"></i>
                                        Connect to database to see activity logs
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center py-4 text-muted">
            <small>Super Admin Dashboard &copy; <?php echo date('Y'); ?> - LGU 2 Legislative System</small>
        </div>
    </div><!-- End Container -->
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- System Lock JS -->
    <script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>
    
    <!-- Charts -->
    <script>
        // Activity Chart
        const activityCanvas = document.getElementById('activityChart');
        const ctx = activityCanvas ? activityCanvas.getContext('2d') : null;
        if (ctx) new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'System Activity',
                    data: [12, 19, 8, 15, 22, 5, 10],
                    backgroundColor: '<?php echo $systemInfo['color']; ?>',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
