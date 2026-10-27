<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include other necessary files using the session variable
require_once __DIR__ . '/auth.php';
require_once './contents/legislative-research-section/connection.php'; // Includes the lgu2 database connection
require_once __DIR__ . '/includes/audit_log.php'; // Audit log system



// Get the current month and year from the URL, or default to the current date
$currentMonth = isset($_GET['month']) ? $_GET['month'] : date('m');
$currentYear = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Fetch daily counts for the selected month and year
$incomingTasksDaily = [];
try {
    $sqlDailyCounts = "SELECT DATE(date_created) as measure_date, COUNT(*) as daily_count FROM m9_similaritychecking WHERE measure_status = 'Draft' AND YEAR(date_created) = ? AND MONTH(date_created) = ? GROUP BY DATE(date_created) ORDER BY measure_date ASC";
    $stmt = $lgu2_conn->prepare($sqlDailyCounts);
    $stmt->bind_param("ss", $currentYear, $currentMonth);
    $stmt->execute();
    $resultDailyCounts = $stmt->get_result();

    if ($resultDailyCounts) {
        while ($row = $resultDailyCounts->fetch_assoc()) {
            $incomingTasksDaily[] = $row;
        }
    }
    $stmt->close();
} catch (Exception $e) {
    // Log the error or display a user-friendly message
    $errorMessage = "Database error: " . $e->getMessage();
}

// Map the fetched data into an associative array for easy lookup
$tasksByDate = [];
foreach ($incomingTasksDaily as $task) {
    $tasksByDate[$task['measure_date']] = $task['daily_count'];
}

// Calendar generation logic
$firstDayOfMonth = mktime(0, 0, 0, $currentMonth, 1, $currentYear);
$monthName = date('F Y', $firstDayOfMonth);
$daysInMonth = date('t', $firstDayOfMonth);
$startDayOfWeek = date('w', $firstDayOfMonth); // 0 (Sunday) to 6 (Saturday)

// Check if this is an AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $lgu2_conn->close();
    // Return only the calendar content
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    ?>
    <div class="calendar-nav-container">
        <a href="#" class="calendar-nav-link" data-month="<?php echo date('m', strtotime('-1 month', $firstDayOfMonth)); ?>" data-year="<?php echo date('Y', strtotime('-1 month', $firstDayOfMonth)); ?>"><i class="fas fa-chevron-left"></i></a>
        <h4 class="calendar-header mb-0"><?php echo htmlspecialchars($monthName); ?></h4>
        <a href="#" class="calendar-nav-link" data-month="<?php echo date('m', strtotime('+1 month', $firstDayOfMonth)); ?>" data-year="<?php echo date('Y', strtotime('+1 month', $firstDayOfMonth)); ?>"><i class="fas fa-chevron-right"></i></a>
    </div>

    <div class="calendar-container ">
        <?php foreach ($dayNames as $dayName): ?>
            <div class="calendar-day-header"><?php echo htmlspecialchars($dayName); ?></div>
        <?php endforeach; ?>

        <?php 
        for ($i = 0; $i < $startDayOfWeek; $i++) {
            echo '<div class="calendar-day empty-day"></div>';
        }
        
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $currentYear . '-' . str_pad($currentMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
            $taskCount = $tasksByDate[$date] ?? 0;
            echo '<div class="calendar-day">';
            echo '<span class="calendar-day-number">' . htmlspecialchars($day) . '</span>';
            if ($taskCount > 0) {
                echo '<span class="task-badge">' . htmlspecialchars($taskCount) . ' Task(s)</span>';
            }
            echo '</div>';
        }
        ?>
    </div>
    <?php
    exit; // Stop execution after sending the HTML fragment
}

// Initialize variables with default values
$totalDraftMeasures = 0;
$totalPendingMeasures = 0;
$totalOrdinances = 0;
$totalResolutions = 0;
$totalEnactedOrdinances = 0;
$totalEnactedResolutions = 0;
$totalMeasures = 0;

// Fetch counts for the dashboard statistics (only for full page load)
try {
    // Count Incoming Tasks (Draft)
    $sqlDraftCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_status = 'Draft'";
    $resultDraftCount = $lgu2_conn->query($sqlDraftCount);
    if ($resultDraftCount) {
        $totalDraftMeasures = $resultDraftCount->fetch_assoc()['total'];
    }

    // Count Pending Measures
    $sqlPendingCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_status = 'In Review for Docketing'";
    $resultPendingCount = $lgu2_conn->query($sqlPendingCount);
    if ($resultPendingCount) {
        $totalPendingMeasures = $resultPendingCount->fetch_assoc()['total'];
    }

    // Count Proposed Ordinances (1st, 2nd, 3rd Reading)
    $sqlOrdinanceCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_type = 'Ordinance' AND measure_status IN ('1st Reading', '2nd Reading', '3rd Reading')";
    $resultOrdinanceCount = $lgu2_conn->query($sqlOrdinanceCount);
    if ($resultOrdinanceCount) {
        $totalOrdinances = $resultOrdinanceCount->fetch_assoc()['total'];
    }

    // Count Proposed Resolutions (1st, 2nd, 3rd Reading)
    $sqlResolutionCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_type = 'Resolution' AND measure_status IN ('1st Reading', '2nd Reading', '3rd Reading')";
    $resultResolutionCount = $lgu2_conn->query($sqlResolutionCount);
    if ($resultResolutionCount) {
        $totalResolutions = $resultResolutionCount->fetch_assoc()['total'];
    }

    // Count Enacted Ordinances
    $sqlEnactedOrdinanceCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_type = 'Ordinance' AND measure_status = 'Enacted'";
    $resultEnactedOrdinanceCount = $lgu2_conn->query($sqlEnactedOrdinanceCount);
    if ($resultEnactedOrdinanceCount) {
        $totalEnactedOrdinances = $resultEnactedOrdinanceCount->fetch_assoc()['total'];
    }

    // Count Enacted Resolutions
    $sqlEnactedResolutionCount = "SELECT COUNT(*) AS total FROM m9_similaritychecking WHERE measure_type = 'Resolution' AND measure_status = 'Enacted'";
    $resultEnactedResolutionCount = $lgu2_conn->query($sqlEnactedResolutionCount);
    if ($resultEnactedResolutionCount) {
        $totalEnactedResolutions = $resultEnactedResolutionCount->fetch_assoc()['total'];
    }

    // Count measures by status for pie chart
    $statusCounts = [];
    $sqlStatusBreakdown = "SELECT measure_status, COUNT(*) as count FROM m9_similaritychecking GROUP BY measure_status";
    $resultStatusBreakdown = $lgu2_conn->query($sqlStatusBreakdown);
    if ($resultStatusBreakdown) {
        while ($row = $resultStatusBreakdown->fetch_assoc()) {
            $statusCounts[$row['measure_status']] = $row['count'];
        }
    }
    
    // Get total for percentage calculation (excluding rejected measures)
    $totalMeasures = 0;
    foreach ($statusCounts as $status => $count) {
        // Exclude rejected measures from total count
        if (strtolower($status) !== 'deferred') {
            $totalMeasures += $count;
        }
    }
    
    // Calculate Progress Overview metrics
    $completedCount = $totalEnactedOrdinances + $totalEnactedResolutions;
    $totalAllMeasures = $totalMeasures > 0 ? $totalMeasures : 1; // Avoid division by zero
    $completedPercentage = round(($completedCount / $totalAllMeasures) * 100);
    
    $pendingCount = $totalPendingMeasures;
    $pendingPercentage = round(($pendingCount / $totalAllMeasures) * 100);
    
    // Monthly goal: Calculate based on measures in reading stages vs total active measures
    $inProgressCount = $totalOrdinances + $totalResolutions;
    $monthlyGoalPercentage = $totalAllMeasures > 0 ? round((($completedCount + $inProgressCount) / $totalAllMeasures) * 100) : 0;
    
} catch (Exception $e) {
    $errorMessage = "Database error: " . $e->getMessage();
    // Set default values if error occurs
    $completedPercentage = 0;
    $pendingPercentage = 0;
    $monthlyGoalPercentage = 0;
}

// Fetch recent activity from audit logs
$recentActivities = [];
try {
    require_once __DIR__ . '/config/database.php';
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if (!$mysqli->connect_error) {
        $auditLog = new AuditLog($mysqli);
        $recentActivities = $auditLog->getRecentActivity(10); // Get last 10 activities
        $mysqli->close();
    }
} catch (Exception $e) {
    error_log('Error fetching audit logs: ' . $e->getMessage());
}

$lgu2_conn->close();

$pageTitle = "Dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - LGU Research System</title>
    
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
    
    <!-- Dashboard Styles -->
    <link rel="stylesheet" href="<?= e(url('assets/css/dashboard.css')) ?>">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/includes/super_header.php'; ?>
    
    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/includes/super_sidebar.php'; ?>

<!-- Dashboard Skeleton Loading Styles -->
<style>
/* === DASHBOARD SKELETON LOADING STYLES === */
.dashboard-skeleton-container {
    display: none;
}

.dashboard-skeleton-container.show {
    display: block;
}

.dashboard-skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
    border-radius: 8px;
}

@keyframes skeleton-loading {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

/* Dashboard Cards Skeleton */
.skeleton-dashboard-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.7) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.1);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    padding: 1.5rem;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.skeleton-dashboard-icon {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    opacity: 0.3;
}

.skeleton-dashboard-title {
    height: 20px;
    width: 70%;
    margin-bottom: 0.75rem;
    opacity: 0.4;
}

.skeleton-dashboard-count {
    height: 32px;
    width: 50%;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

.skeleton-dashboard-trend {
    height: 16px;
    width: 60%;
    opacity: 0.3;
}

/* Quick Actions Skeleton */
.skeleton-quick-actions {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.7) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.1);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.skeleton-quick-actions-title {
    height: 24px;
    width: 150px;
    margin-bottom: 1rem;
    opacity: 0.4;
}

.skeleton-quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.skeleton-quick-action-btn {
    height: 60px;
    border-radius: 12px;
    opacity: 0.3;
}

/* Calendar Skeleton */
.skeleton-calendar-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.7) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.1);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 1.5rem;
}

.skeleton-calendar-header {
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 16px 16px 0 0;
    font-weight: 600;
    opacity: 0.8;
}

.skeleton-calendar-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid rgba(17, 82, 114, 0.1);
}

.skeleton-nav-arrow {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    opacity: 0.3;
}

.skeleton-month-title {
    height: 28px;
    width: 150px;
    opacity: 0.4;
}

.skeleton-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: rgba(17, 82, 114, 0.1);
    padding: 1.5rem;
}

.skeleton-calendar-day {
    background: white;
    height: 60px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 0.3;
}

/* Widget Cards Skeleton */
.skeleton-widget-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0.7) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.1);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    height: 400px;
    display: flex;
    flex-direction: column;
}

.skeleton-widget-header {
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 16px 16px 0 0;
    font-weight: 600;
    opacity: 0.8;
}

.skeleton-widget-body {
    padding: 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.skeleton-activity-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid rgba(17, 82, 114, 0.05);
}

.skeleton-activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    opacity: 0.3;
}

.skeleton-activity-content {
    flex: 1;
}

.skeleton-activity-title {
    height: 16px;
    width: 70%;
    margin-bottom: 0.5rem;
    opacity: 0.4;
}

.skeleton-activity-time {
    height: 12px;
    width: 40%;
    opacity: 0.3;
}

.skeleton-progress-item {
    margin-bottom: 1.5rem;
}

.skeleton-progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.skeleton-progress-text {
    height: 16px;
    width: 60%;
    opacity: 0.4;
}

.skeleton-progress-value {
    height: 16px;
    width: 30px;
    opacity: 0.4;
}

.skeleton-progress-bar {
    height: 8px;
    border-radius: 4px;
    opacity: 0.3;
}

.skeleton-pie-chart {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    margin: 0 auto 1rem;
    opacity: 0.3;
}

.skeleton-legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.skeleton-legend-color {
    width: 14px;
    height: 14px;
    border-radius: 3px;
    opacity: 0.3;
}

.skeleton-legend-text {
    height: 14px;
    width: 80%;
    opacity: 0.3;
}

/* Mobile responsive skeleton */
@media (max-width: 768px) {
    .skeleton-quick-actions-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .skeleton-calendar-grid {
        padding: 1rem;
    }
    
    .skeleton-calendar-day {
        height: 50px;
    }
}
</style>

<style>
    /* Table Layout - Most reliable cross-browser solution */
    .dashboard-layout-table {
        display: table !important;
        width: 100% !important;
        table-layout: fixed !important;
        border-spacing: 1rem !important;
        margin: 0 auto !important;
        padding: 0 !important;
    }
    
    .dashboard-layout-row {
        display: table-row !important;
    }
    
    .dashboard-col-calendar {
        display: table-cell !important;
        width: 28% !important;
        vertical-align: top !important;
    }
    
    .dashboard-col-activity {
        display: table-cell !important;
        width: 24% !important;
        vertical-align: top !important;
    }
    
    .dashboard-col-progress {
        display: table-cell !important;
        width: 24% !important;
        vertical-align: top !important;
    }
    
    .dashboard-col-status {
        display: table-cell !important;
        width: 24% !important;
        vertical-align: top !important;
    }
    
    /* Card heights */
    .dashboard-col-calendar .card,
    .dashboard-col-activity .card,
    .dashboard-col-progress .card,
    .dashboard-col-status .card {
        height: 500px !important;
        margin: 0 !important;
        width: 100% !important;
        box-sizing: border-box !important;
        overflow: visible !important;
        display: flex !important;
        flex-direction: column !important;
    }
    
    .dashboard-col-activity .card-body,
    .dashboard-col-progress .card-body,
    .dashboard-col-status .card-body {
        flex: 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        overflow-y: auto !important;
    }
    
    .dashboard-col-status .card-body {
        justify-content: flex-start !important;
    }
    
    .dashboard-col-calendar .card-header-custom,
    .dashboard-col-activity .card-header-custom,
    .dashboard-col-progress .card-header-custom,
    .dashboard-col-status .card-header-custom {
        overflow: visible !important;
        min-height: auto !important;
    }
    
    /* Bootstrap container override */
    .container.dashboard-container {
        max-width: 100% !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }
    
    /* Mobile responsive - Stack vertically */
    @media (max-width: 991px) {
        .dashboard-layout-table {
            display: block !important;
        }
        
        .dashboard-layout-row {
            display: block !important;
        }
        
        .dashboard-col-calendar,
        .dashboard-col-activity,
        .dashboard-col-progress,
        .dashboard-col-status {
            display: block !important;
            width: 100% !important;
            margin-bottom: 1rem !important;
        }
        
        .dashboard-col-calendar .card,
        .dashboard-col-activity .card,
        .dashboard-col-progress .card,
        .dashboard-col-status .card {
            height: auto !important;
            min-height: 300px !important;
        }
    }
    
    /* Pie Chart Styling - Optimized for 400px height */
    .pie-chart-container {
        position: relative;
        height: 160px;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .status-legend {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        max-height: 180px;
        overflow-y: visible;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        font-size: 0.75rem;
        padding: 0.3rem 0;
        line-height: 1.3;
    }
    
    .legend-color {
        width: 14px;
        height: 14px;
        border-radius: 3px;
        margin-right: 0.6rem;
        flex-shrink: 0;
    }
    
    .legend-label {
        flex: 1;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.75rem;
        line-height: 1.3;
    }
    
    .legend-value {
        color: var(--text-primary);
        font-weight: 700;
        font-size: 0.7rem;
    }
    
    /* Document Status Widget specific adjustments */
    .dashboard-widget .widget-body {
        padding: 0.9rem 1rem 0.9rem 1rem;
        display: flex;
        flex-direction: column;
        height: calc(400px - 50px); /* Total height minus header - adjusted for better fit */
        overflow: hidden;
    }
    
    .dashboard-widget .pie-chart-container {
        flex-shrink: 0;
        height: 145px; /* Optimized size for better balance with readable legend */
        margin-bottom: 0.7rem;
    }
    
    .dashboard-widget .status-legend {
        flex: 1;
        overflow-y: visible;
        padding-bottom: 0.2rem; /* Add padding at bottom to prevent cutoff */
        min-height: 0; /* Allow shrinking if needed */
    }
    
    @media (max-width: 768px) {
        .pie-chart-container {
            height: 130px;
            margin-bottom: 0.5rem;
        }
        
        .dashboard-widget .pie-chart-container {
            height: 125px;
        }
        
        .legend-item {
            font-size: 0.7rem;
            padding: 0.25rem 0;
        }
        
        .legend-label {
            font-size: 0.7rem;
        }
        
        .legend-value {
            font-size: 0.65rem;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            margin-right: 0.5rem;
        }
    }
</style>
    
    <div class="container">

    <!-- Dashboard Statistics Skeleton -->
    <div class="dashboard-skeleton-container" id="dashboard-stats-skeleton">
        <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
        </div>
        
        <!-- Summary Cards Skeleton -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-lg-4">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="skeleton-dashboard-card">
                    <div class="skeleton-dashboard-icon dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-title dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-count dashboard-skeleton"></div>
                    <div class="skeleton-dashboard-trend dashboard-skeleton"></div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions Skeleton -->
        <div class="row g-2 mb-3">
            <div class="col-12">
                <div class="skeleton-quick-actions">
                    <div class="skeleton-quick-actions-title dashboard-skeleton"></div>
                    <div class="skeleton-quick-actions-grid">
                        <div class="skeleton-quick-action-btn dashboard-skeleton"></div>
                        <div class="skeleton-quick-action-btn dashboard-skeleton"></div>
                        <div class="skeleton-quick-action-btn dashboard-skeleton"></div>
                        <div class="skeleton-quick-action-btn dashboard-skeleton"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dashboard-card" style="animation-delay: 0.05s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon" src="<?= e(url('assets/img/task.svg')) ?>" alt="Tasks" loading="lazy">
                    <h5 class="dashboard-title">Incoming Tasks</h5>
                    <p class="dashboard-count"><?php echo $totalDraftMeasures; ?></p>
                    <div class="card-trend">
                        <i class="fas fa-arrow-up text-success"></i>
                        <span class="trend-text">+12% this week</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-card" style="animation-delay: 0.1s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon" src="<?= e(url('assets/img/file.svg')) ?>" alt="Files" loading="lazy">
                    <h5 class="dashboard-title">In Review for Docketing</h5>
                    <p class="dashboard-count"><?php echo $totalPendingMeasures; ?></p>
                    <div class="card-trend">
                        <i class="fas fa-arrow-down text-danger"></i>
                        <span class="trend-text">-5% this week</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-card" style="animation-delay: 0.15s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon" src="<?= e(url('assets/img/court.svg')) ?>" alt="Ordinances" loading="lazy">
                    <h5 class="dashboard-title">Proposed Ordinances</h5>
                    <p class="dashboard-count"><?php echo $totalOrdinances; ?></p>
                    <div class="card-trend">
                        <i class="fas fa-arrow-up text-success"></i>
                        <span class="trend-text">+8% this week</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-card" style="animation-delay: 0.2s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon" src="<?= e(url('assets/img/resolution.svg')) ?>" alt="Resolutions" loading="lazy">
                    <h5 class="dashboard-title">Proposed Resolutions</h5>
                    <p class="dashboard-count"><?php echo $totalResolutions; ?></p>
                    <div class="card-trend">
                        <i class="fas fa-minus text-warning"></i>
                        <span class="trend-text">No change</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Second Row - Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-4">
            <div class="card dashboard-card compact-card" style="animation-delay: 0.25s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon small-icon" src="<?= e(url('assets/img/enacted-ordinance.svg')) ?>" alt="Enacted Measures" loading="lazy">
                    <h5 class="dashboard-title">Enacted Measures</h5>
                    <div class="split-count">
                        <span class="count-section">
                            <span class="count-label">Ordinances</span>
                            <span class="dashboard-count small-count"><?php echo $totalEnactedOrdinances; ?></span>
                        </span>
                        <span class="count-divider">|</span>
                        <span class="count-section">
                            <span class="count-label">Resolutions</span>
                            <span class="dashboard-count small-count"><?php echo $totalEnactedResolutions; ?></span>
                        </span>
                    </div>
                    <div class="card-trend">
                        <i class="fas fa-check-circle text-success"></i>
                        <span class="trend-text">Completed</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="card dashboard-card compact-card" style="animation-delay: 0.3s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon small-icon" src="<?= e(url('assets/img/in-progress.svg')) ?>" alt="In Progress" loading="lazy">
                    <h5 class="dashboard-title">In Progress</h5>
                    <div class="single-count">
                        <span class="dashboard-count small-count"><?php echo ($totalDraftMeasures + $totalPendingMeasures + $totalOrdinances + $totalResolutions); ?></span>
                        <span class="count-label">Active</span>
                    </div>
                    <div class="card-trend">
                        <i class="fas fa-hourglass-half text-warning"></i>
                        <span class="trend-text">Active</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card dashboard-card compact-card" style="animation-delay: 0.35s;">
                <div class="card-body dashboard-card-body">
                    <img class="dashboard-icon small-icon" src="<?= e(url('assets/img/total-measures.svg')) ?>" alt="Total Measures" loading="lazy">
                    <h5 class="dashboard-title">Total Measures</h5>
                    <div class="single-count">
                        <span class="dashboard-count small-count"><?php echo $totalMeasures; ?></span>
                        <span class="count-label">All Status</span>
                    </div>
                    <div class="card-trend">
                        <i class="fas fa-chart-line text-info"></i>
                        <span class="trend-text">All Status</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions Section -->
    <div class="row g-2 mb-3">
        <div class="col-12">
            <div class="quick-actions-container">
                <h6 class="quick-actions-title">Quick Actions</h6>
                <div class="quick-actions-grid">
                    <a href="<?= e(url('contents/legislative-research-section/draftmeasurestask.php')) ?>" class="quick-action-btn">
                    <i class="fa-solid fa-list-check"></i>
                        <span>Draft Measures Task</span>
                    </a>
                    <a href="<?= e(url('contents/legislative-research-section/similarity-checking-tool.php')) ?>" class="quick-action-btn">
                    <i class="fa-solid fa-magnifying-glass"></i>
                        <span>Similarity Checking Tool</span>
                    </a>
                    <a href="<?= e(url('contents/legislative-research-section/citationcheckingtool.php')) ?>" class="quick-action-btn">
                    <i class="fa-solid fa-rectangle-list"></i>
                        <span>Citation Checking Tool</span>
                    </a>
                    <a href="<?= e(url('contents/legislative-research-section/measurecomparisontool.php')) ?>" class="quick-action-btn">
                    <i class="fa-solid fa-file-export"></i>
                        <span>Measure Comparison Tool</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- Calendar Section - Full Width -->
    <div class="container dashboard-container">
        <!-- Calendar Skeleton -->
        <div class="dashboard-skeleton-container" id="calendar-skeleton">
            <div class="row mb-3">
                <div class="col-12">
                    <div class="skeleton-calendar-card">
                        <div class="skeleton-calendar-header">Incoming Tasks Calendar</div>
                        <div class="skeleton-calendar-nav">
                            <div class="skeleton-nav-arrow dashboard-skeleton"></div>
                            <div class="skeleton-month-title dashboard-skeleton"></div>
                            <div class="skeleton-nav-arrow dashboard-skeleton"></div>
                        </div>
                        <div class="skeleton-calendar-grid">
                            <?php for($i = 0; $i < 42; $i++): ?>
                                <div class="skeleton-calendar-day dashboard-skeleton"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-12">
                <div class="card" style="border-radius: 12px; border: 1px solid var(--glass-border); box-shadow: 0 4px 20px var(--shadow-light);">
                    <div class="card-header card-header-custom">
                        Incoming Tasks Calendar
                    </div>
                    <div class="card-body">
                        <div id="calendar-wrapper">
                            <?php
                                $dayNames = ['Sun', 'Mon', 'Tues', 'Wed', 'Thurs', 'Fri', 'Sat'];
                            ?>
                            <div class="calendar-nav-container">
                                <a href="#" class="calendar-nav-link" data-month="<?php echo date('m', strtotime('-1 month', $firstDayOfMonth)); ?>" data-year="<?php echo date('Y', strtotime('-1 month', $firstDayOfMonth)); ?>"><i class="fas fa-chevron-left"></i></a>
                                <h4 class="calendar-header mb-0"><?php echo htmlspecialchars($monthName); ?></h4>
                                <a href="#" class="calendar-nav-link" data-month="<?php echo date('m', strtotime('+1 month', $firstDayOfMonth)); ?>" data-year="<?php echo date('Y', strtotime('+1 month', $firstDayOfMonth)); ?>"><i class="fas fa-chevron-right"></i></a>
                            </div>
                            <div class="calendar-container">
                                <?php foreach ($dayNames as $dayName): ?>
                                    <div class="calendar-day-header"><?php echo htmlspecialchars($dayName); ?></div>
                                <?php endforeach; ?>
                                <?php 
                                    for ($i = 0; $i < $startDayOfWeek; $i++) {
                                        echo '<div class="calendar-day empty-day"></div>';
                                    }
                                    for ($day = 1; $day <= $daysInMonth; $day++) {
                                        $date = $currentYear . '-' . str_pad($currentMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                                        $taskCount = $tasksByDate[$date] ?? 0;
                                        echo '<div class="calendar-day">';
                                        echo '<span class="calendar-day-number">' . htmlspecialchars($day) . '</span>';
                                        if ($taskCount > 0) {
                                            echo '<span class="task-badge">' . htmlspecialchars($taskCount) . ' Task(s)</span>';
                                        }
                                        echo '</div>';
                                    }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Widgets Skeleton -->
        <div class="dashboard-skeleton-container" id="widgets-skeleton">
            <div class="row g-3">
                <!-- Recent Activity Skeleton -->
                <div class="col-12 col-md-4">
                    <div class="skeleton-widget-card">
                        <div class="skeleton-widget-header">Recent Activity</div>
                        <div class="skeleton-widget-body">
                            <?php for($i = 0; $i < 4; $i++): ?>
                            <div class="skeleton-activity-item">
                                <div class="skeleton-activity-icon dashboard-skeleton"></div>
                                <div class="skeleton-activity-content">
                                    <div class="skeleton-activity-title dashboard-skeleton"></div>
                                    <div class="skeleton-activity-time dashboard-skeleton"></div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Overview Skeleton -->
                <div class="col-12 col-md-4">
                    <div class="skeleton-widget-card">
                        <div class="skeleton-widget-header">Progress Overview</div>
                        <div class="skeleton-widget-body">
                            <?php for($i = 0; $i < 3; $i++): ?>
                            <div class="skeleton-progress-item">
                                <div class="skeleton-progress-label">
                                    <div class="skeleton-progress-text dashboard-skeleton"></div>
                                    <div class="skeleton-progress-value dashboard-skeleton"></div>
                                </div>
                                <div class="skeleton-progress-bar dashboard-skeleton"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Document Status Skeleton -->
                <div class="col-12 col-md-4">
                    <div class="skeleton-widget-card">
                        <div class="skeleton-widget-header">Document Status</div>
                        <div class="skeleton-widget-body">
                            <div class="skeleton-pie-chart dashboard-skeleton"></div>
                            <?php for($i = 0; $i < 5; $i++): ?>
                            <div class="skeleton-legend-item">
                                <div class="skeleton-legend-color dashboard-skeleton"></div>
                                <div class="skeleton-legend-text dashboard-skeleton"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Three Cards Section - Full Width Row -->
        <div class="row g-3">
            <!-- Recent Activity -->
            <div class="col-12 col-md-4">
                <div class="card dashboard-widget" style="height: 400px;">
                    <div class="card-header card-header-custom" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Recent Activity</span>
                        <button class="btn btn-sm btn-outline-light" onclick="openActivityModal()" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                            <i class="fas fa-list me-1"></i>View All
                        </button>
                    </div>
                    <div class="card-body widget-body">
                        <?php if (!empty($recentActivities)): ?>
                            <?php foreach ($recentActivities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas <?php echo htmlspecialchars(AuditLog::getActionIcon($activity['action_type'])); ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($activity['action_description']); ?>
                                            <?php if (!empty($activity['username'])): ?>
                                                <span class="activity-user">by <?php echo htmlspecialchars($activity['username']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-time"><?php echo htmlspecialchars(AuditLog::timeAgo($activity['created_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-info-circle text-secondary"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">No recent activity</div>
                                    <div class="activity-time">Start using the system to see activity here</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Progress Overview -->
            <div class="col-12 col-md-4">
                <div class="card dashboard-widget" style="height: 400px;">
                    <div class="card-header card-header-custom">
                        Progress Overview
                    </div>
                    <div class="card-body widget-body">
                        <div class="progress-item">
                            <div class="progress-label">
                                <span>Completed Tasks</span>
                                <span class="progress-value"><?php echo $completedPercentage; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $completedPercentage; ?>%;"></div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="progress-label">
                                <span>Pending Reviews</span>
                                <span class="progress-value"><?php echo $pendingPercentage; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $pendingPercentage; ?>%; background: linear-gradient(90deg, #ff9800, #ffc107);"></div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="progress-label">
                                <span>Monthly Goal</span>
                                <span class="progress-value"><?php echo $monthlyGoalPercentage; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $monthlyGoalPercentage; ?>%; background: linear-gradient(90deg, #4caf50, #8bc34a);"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Document Status -->
            <div class="col-12 col-md-4">
                <div class="card dashboard-widget" style="height: 400px;">
                    <div class="card-header card-header-custom">
                        Document Status
                    </div>
                    <div class="card-body widget-body">
                        <div class="pie-chart-container">
                            <canvas id="statusPieChart"></canvas>
                        </div>
                        <div class="status-legend">
                            <?php
                            $statusColors = [
                                'Draft' => ['color' => '#115272', 'label' => 'Draft'],
                                'In Review for Docketing' => ['color' => '#ff9800', 'label' => 'In Review for Docketing'],
                                '1st Reading' => ['color' => '#FFD700', 'label' => '1st Reading'],
                                '1st reading' => ['color' => '#FFD700', 'label' => '1st Reading'],
                                '2nd Reading' => ['color' => '#00BCD4', 'label' => '2nd Reading'],
                                '2nd reading' => ['color' => '#00BCD4', 'label' => '2nd Reading'],
                                '3rd Reading' => ['color' => '#9C27B0', 'label' => '3rd Reading'],
                                '3rd reading' => ['color' => '#9C27B0', 'label' => '3rd Reading'],
                                'Enacted' => ['color' => '#4caf50', 'label' => 'Enacted'],
                                'Deferred' => ['color' => '#F44336', 'label' => 'Deferred'],
                                'deferred' => ['color' => '#F44336', 'label' => 'Deferred']
                            ];
                            
                            foreach ($statusColors as $status => $config) {
                                $count = $statusCounts[$status] ?? 0;
                                $percentage = $totalMeasures > 0 ? round(($count / $totalMeasures) * 100, 1) : 0;
                                if ($count > 0) {
                                    echo '<div class="legend-item">';
                                    echo '<span class="legend-color" style="background: ' . htmlspecialchars($config['color']) . ';"></span>';
                                    echo '<span class="legend-label">' . htmlspecialchars($config['label']) . '</span>';
                                    echo '<span class="legend-value">' . htmlspecialchars($count) . ' (' . htmlspecialchars($percentage) . '%)</span>';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity History Modal -->
    <div class="modal fade" id="activityHistoryModal" tabindex="-1" aria-labelledby="activityHistoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #115272 0%, #004a8b 100%); color: white;">
                    <h5 class="modal-title" id="activityHistoryModalLabel">
                        <i class="fas fa-history me-2"></i>Activity History
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                    <div id="activityHistoryContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading activity history...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>


<script>
    // Dashboard Skeleton Loading Functions
    function showDashboardSkeleton(skeletonId, contentSelector) {
        const skeleton = document.getElementById(skeletonId);
        const content = document.querySelector(contentSelector);
        
        if (skeleton) {
            skeleton.classList.add('show');
        }
        if (content) {
            content.style.display = 'none';
        }
    }

    function hideDashboardSkeleton(skeletonId, contentSelector) {
        const skeleton = document.getElementById(skeletonId);
        const content = document.querySelector(contentSelector);
        
        if (skeleton) {
            skeleton.classList.remove('show');
        }
        if (content) {
            content.style.display = 'block';
        }
    }

    // Open Activity History Modal
    function openActivityModal() {
        const modal = new bootstrap.Modal(document.getElementById('activityHistoryModal'));
        modal.show();
        
        // Load activity history
        loadActivityHistory();
    }
    
    // Load all activity history
    function loadActivityHistory() {
        const contentDiv = document.getElementById('activityHistoryContent');
        
        // Show loading state
        contentDiv.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading activity history...</p>
            </div>
        `;
        
        // Fetch activity history
        fetch('fetch_activity_history.php')
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities.length > 0) {
                    let html = '<div class="activity-history-list">';
                    
                    data.activities.forEach(activity => {
                        const iconClass = getActivityIcon(activity.action_type);
                        html += `
                            <div class="activity-item" style="padding: 1rem; border-bottom: 1px solid #e0e0e0; display: flex; gap: 1rem;">
                                <div class="activity-icon" style="flex-shrink: 0;">
                                    <i class="fas ${iconClass}" style="font-size: 1.25rem;"></i>
                                </div>
                                <div class="activity-content" style="flex: 1;">
                                    <div class="activity-title" style="font-weight: 600; margin-bottom: 0.25rem;">
                                        ${escapeHtml(activity.action_description)}
                                        ${activity.username ? `<span class="activity-user" style="color: #666; font-weight: 400;">by ${escapeHtml(activity.username)}</span>` : ''}
                                    </div>
                                    <div class="activity-time" style="font-size: 0.875rem; color: #888;">
                                        ${escapeHtml(activity.time_ago)}
                                    </div>
                                    ${activity.ip_address ? `<div style="font-size: 0.75rem; color: #999; margin-top: 0.25rem;"><i class="fas fa-network-wired me-1"></i>${escapeHtml(activity.ip_address)}</div>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    contentDiv.innerHTML = html;
                } else {
                    contentDiv.innerHTML = `
                        <div class="text-center py-5">
                            <i class="fas fa-info-circle text-secondary" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="mt-3 text-muted">No activity history found</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading activity history:', error);
                contentDiv.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem; opacity: 0.3;"></i>
                        <p class="mt-3 text-danger">Failed to load activity history</p>
                        <button class="btn btn-sm btn-primary mt-2" onclick="loadActivityHistory()">Retry</button>
                    </div>
                `;
            });
    }
    
    // Get activity icon based on action type
    function getActivityIcon(actionType) {
        const icons = {
            'login': 'fa-sign-in-alt text-success',
            'logout': 'fa-sign-out-alt text-secondary',
            'create': 'fa-file-plus text-success',
            'update': 'fa-edit text-warning',
            'delete': 'fa-trash text-danger',
            'view': 'fa-eye text-info',
            'approve': 'fa-check-circle text-success',
            'reject': 'fa-times-circle text-danger',
            'submit': 'fa-paper-plane text-primary',
            'download': 'fa-download text-info',
            'upload': 'fa-upload text-primary',
            'send': 'fa-share text-primary',
            'receive': 'fa-inbox text-info',
            'account_create': 'fa-user-plus text-success',
            'account_update': 'fa-user-edit text-warning',
            'password_reset': 'fa-key text-warning',
            'profile_update': 'fa-user-cog text-info'
        };
        return icons[actionType] || 'fa-circle text-secondary';
    }
    
    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize skeleton loading on page load
        showDashboardSkeleton('dashboard-stats-skeleton', '.container > .row.g-2.mb-3:nth-of-type(1)');
        showDashboardSkeleton('calendar-skeleton', '.dashboard-container > .row.mb-3:nth-of-type(1)');
        showDashboardSkeleton('widgets-skeleton', '.dashboard-container > .row.g-3:nth-of-type(2)');
        
        // Simulate loading with staggered timing for better UX
        setTimeout(() => hideDashboardSkeleton('dashboard-stats-skeleton', '.container > .row.g-2.mb-3:nth-of-type(1)'), 800);
        setTimeout(() => hideDashboardSkeleton('calendar-skeleton', '.dashboard-container > .row.mb-3:nth-of-type(1)'), 1200);
        setTimeout(() => hideDashboardSkeleton('widgets-skeleton', '.dashboard-container > .row.g-3:nth-of-type(2)'), 1600);
        
        const calendarWrapper = document.getElementById('calendar-wrapper');
        let isLoading = false;

        // Add event listener to the calendar wrapper with debouncing
        calendarWrapper.addEventListener('click', function(e) {
            // Check if a navigation link was clicked
            if (e.target.closest('.calendar-nav-link') && !isLoading) {
                e.preventDefault();
                isLoading = true;
                
                const link = e.target.closest('.calendar-nav-link');
                const newMonth = link.getAttribute('data-month');
                const newYear = link.getAttribute('data-year');
                
                // Add loading state
                link.style.opacity = '0.6';
                link.style.pointerEvents = 'none';

                // Make an optimized AJAX request
                fetch(`?ajax=1&month=${newMonth}&year=${newYear}`, {
                    method: 'GET',
                    cache: 'no-cache'
                })
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.text();
                    })
                    .then(html => {
                        // Use requestAnimationFrame for smooth updates
                        requestAnimationFrame(() => {
                            calendarWrapper.innerHTML = html;
                            isLoading = false;
                        });
                    })
                    .catch(error => {
                        console.error('Error loading calendar:', error);
                        link.style.opacity = '1';
                        link.style.pointerEvents = 'auto';
                        isLoading = false;
                    });
            }
        });
        
        // Performance optimization: Use passive event listeners
        document.addEventListener('scroll', function() {
            // Optimize scroll performance if needed
        }, { passive: true });
        
        // Initialize Pie Chart
        const ctx = document.getElementById('statusPieChart');
        if (ctx) {
            const statusData = <?php echo json_encode($statusCounts); ?>;
            const statusColors = {
                'Draft': '#115272',
                'In Review for Docketing': '#ff9800',
                '1st Reading': '#FFD700',
                '1st reading': '#FFD700',
                '2nd Reading': '#00BCD4',
                '2nd reading': '#00BCD4',
                '3rd Reading': '#9C27B0',
                '3rd reading': '#9C27B0',
                'Enacted': '#4caf50',
                'Deferred': '#F44336',
                'deferred': '#F44336'
            };
            
            const labels = Object.keys(statusData);
            const data = Object.values(statusData);
            const colors = labels.map(label => statusColors[label] || '#999999');
            
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderColor: '#ffffff',
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(17, 82, 114, 0.9)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    cutout: '60%',
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }
    });
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- System Lock JavaScript -->
<script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>

</body>
</html>