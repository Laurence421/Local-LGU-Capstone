<?php
require_once __DIR__ . '/../../auth.php';

// Copies whole tables between this database and the Hostinger database. Admins only.
require_role([]);

// This server's own database (from .env)
$local_db = [
    'host' => env('DB_HOST', 'localhost'),
    'user' => env('DB_USER', 'root'),
    'pass' => env('DB_PASS', ''),
    'name' => env('DB_NAME', 'lgu2local'),
    'port' => (int) env('DB_PORT', 3306)
];

// The other environment's database (CLOUD_DB_* in .env). Never expose MySQL through tunnels.
$cloud_db = [
    'host' => env('CLOUD_DB_HOST', ''),
    'user' => env('CLOUD_DB_USER', ''),
    'pass' => env('CLOUD_DB_PASS', ''),
    'name' => env('CLOUD_DB_NAME', ''),
    'port' => (int) env('CLOUD_DB_PORT', 3306)
];
$sync_configured = $cloud_db['host'] !== '' && $cloud_db['name'] !== '';
$is_local = env('APP_ENV', 'production') === 'local';

// Tables to synchronize (in order to respect foreign key constraints).
// password_resets is deliberately excluded: reset tokens must never leave their environment.
$tables = ['users', 'audit_logs', 'document_requests', 'm9_similaritychecking', 'research_inbox'];

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!$sync_configured && $_GET['action'] !== 'get_sync_status' && $_GET['action'] !== 'import_data') {
        echo json_encode(['success' => false, 'message' => 'Remote database is not configured (CLOUD_DB_* in .env).']);
        exit;
    }
    
    switch ($_GET['action']) {
        case 'sync_data':
            $sync_direction = $_POST['sync_direction'] ?? 'pull';
            
            try {
                if ($sync_direction === 'pull') {
                    $result = pullFromCloud($local_db, $cloud_db, $tables);
                } elseif ($sync_direction === 'push') {
                    $result = pushToCloud($local_db, $cloud_db, $tables);
                } else {
                    throw new Exception('Invalid sync direction');
                }
                
                echo json_encode(['success' => true, 'message' => 'Synchronization completed successfully', 'data' => $result]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_sync_status':
            $status = getSyncStatus($local_db);
            echo json_encode(['success' => true, 'data' => $status]);
            exit;
            
        case 'export_cloud_data':
            // Export cloud data as JSON for manual download and import
            try {
                $cloud = new mysqli($cloud_db['host'], $cloud_db['user'], $cloud_db['pass'], $cloud_db['name'], $cloud_db['port']);
                
                if ($cloud->connect_error) {
                    throw new Exception('Cloud DB connection failed: ' . $cloud->connect_error);
                }
                
                $cloud->set_charset('utf8mb4');
                $export_data = [];
                
                foreach ($tables as $table) {
                    $table_check = $cloud->query("SHOW TABLES LIKE '$table'");
                    if ($table_check->num_rows === 0) {
                        $export_data[$table] = ['exists' => false, 'rows' => []];
                        continue;
                    }
                    
                    $result = $cloud->query("SELECT * FROM `$table`");
                    $rows = [];
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $export_data[$table] = ['exists' => true, 'count' => count($rows), 'rows' => $rows];
                }
                
                $cloud->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Cloud data exported successfully',
                    'export_date' => date('Y-m-d H:i:s'),
                    'tables' => $export_data
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
            }
            exit;
            
        case 'import_data':
            // Import data from uploaded JSON file
            try {
                $json_data = $_POST['json_data'] ?? '';
                if (empty($json_data)) {
                    throw new Exception('No data provided');
                }
                
                $import = json_decode($json_data, true);
                if (!$import || !isset($import['tables'])) {
                    throw new Exception('Invalid import data format');
                }
                
                $local = new mysqli($local_db['host'], $local_db['user'], $local_db['pass'], $local_db['name'], $local_db['port']);
                
                if ($local->connect_error) {
                    throw new Exception('Local DB connection failed: ' . $local->connect_error);
                }
                
                $local->set_charset('utf8mb4');
                $local->query("SET FOREIGN_KEY_CHECKS=0");
                
                $results = [];
                
                foreach ($import['tables'] as $table => $table_data) {
                    if (!in_array($table, $tables)) {
                        $results[$table] = ['status' => 'skipped', 'reason' => 'Table not in allowed list'];
                        continue;
                    }
                    
                    if (!$table_data['exists']) {
                        $results[$table] = ['status' => 'skipped', 'reason' => 'Table did not exist in export'];
                        continue;
                    }
                    
                    // Delete existing data
                    $local->query("DELETE FROM `$table`");
                    $deleted = $local->affected_rows;
                    
                    $inserted = 0;
                    foreach ($table_data['rows'] as $row) {
                        // Column names come from the uploaded file: allow plain identifiers only
                        $row = array_filter((array) $row, function ($col) { return is_string($col) && preg_match('/^[A-Za-z0-9_]+$/', $col); }, ARRAY_FILTER_USE_KEY);
                        if (!$row) { continue; }
                        $columns = array_keys($row);
                        $values = array_values($row);
                        
                        $escaped_values = array_map(function($val) use ($local) {
                            return $val === null ? 'NULL' : "'" . $local->real_escape_string($val) . "'";
                        }, $values);
                        
                        $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escaped_values) . ")";
                        
                        if ($local->query($sql)) {
                            $inserted++;
                        }
                    }
                    
                    $results[$table] = ['status' => 'success', 'deleted' => $deleted, 'inserted' => $inserted];
                }
                
                $local->query("SET FOREIGN_KEY_CHECKS=1");
                $local->close();
                
                echo json_encode(['success' => true, 'message' => 'Data imported successfully', 'data' => $results]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
            }
            exit;
    }
}

/**
 * Pull data from cloud to local (Cloud → Local)
 * Deletes all local data and copies everything from cloud
 */
function pullFromCloud($local_config, $cloud_config, $tables) {
    // Configure timeouts for remote connections
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    
    try {
        // Connect to local database with extended timeout
        $local = new mysqli($local_config['host'], $local_config['user'], $local_config['pass'], $local_config['name'], $local_config['port']);
        $local->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);
        $local->options(MYSQLI_OPT_READ_TIMEOUT, 60);
        
        // Connect to cloud database
        $cloud = new mysqli($cloud_config['host'], $cloud_config['user'], $cloud_config['pass'], $cloud_config['name'], $cloud_config['port']);
    } catch (Exception $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
    
    if ($local->connect_error) {
        throw new Exception('Local DB connection failed: ' . $local->connect_error);
    }
    if ($cloud->connect_error) {
        throw new Exception('Cloud DB connection failed: ' . $cloud->connect_error);
    }
    
    $results = [];
    
    // Turn off foreign key checks
    $local->query("SET FOREIGN_KEY_CHECKS=0");
    
    foreach ($tables as $table) {
        try {
            // Check if table exists in cloud
            $table_check = $cloud->query("SHOW TABLES LIKE '$table'");
            if ($table_check->num_rows === 0) {
                $results[$table] = ['status' => 'skipped', 'reason' => 'Table does not exist in cloud'];
                continue;
            }
            
            // Step A: Delete ALL rows in LOCAL table
            $local->query("DELETE FROM `$table`");
            $deleted = $local->affected_rows;
            
            // Step B: Get ALL rows from CLOUD table
            $result = $cloud->query("SELECT * FROM `$table`");
            
            if (!$result) {
                throw new Exception("Failed to read from cloud table $table: " . $cloud->error);
            }
            
            $inserted = 0;
            
            // Step C: Insert each row into LOCAL table
            while ($row = $result->fetch_assoc()) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escaped_values = array_map(function($val) use ($local) {
                    return $val === null ? 'NULL' : "'" . $local->real_escape_string($val) . "'";
                }, $values);
                
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escaped_values) . ")";
                
                if ($local->query($sql)) {
                    $inserted++;
                } else {
                    error_log("Failed to insert row into $table: " . $local->error);
                }
            }
            
            $results[$table] = [
                'status' => 'success',
                'deleted' => $deleted,
                'inserted' => $inserted
            ];
            
        } catch (Exception $e) {
            $results[$table] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Turn foreign keys back on
    $local->query("SET FOREIGN_KEY_CHECKS=1");
    
    $local->close();
    $cloud->close();
    
    return $results;
}

/**
 * Push data from local to cloud (Local → Cloud)
 * Deletes all cloud data and copies everything from local
 */
function pushToCloud($local_config, $cloud_config, $tables) {
    $local = new mysqli($local_config['host'], $local_config['user'], $local_config['pass'], $local_config['name'], $local_config['port']);
    $cloud = new mysqli($cloud_config['host'], $cloud_config['user'], $cloud_config['pass'], $cloud_config['name'], $cloud_config['port']);
    
    if ($local->connect_error) {
        throw new Exception('Local DB connection failed: ' . $local->connect_error);
    }
    if ($cloud->connect_error) {
        throw new Exception('Cloud DB connection failed: ' . $cloud->connect_error);
    }
    
    $results = [];
    
    // Turn off foreign key checks
    $cloud->query("SET FOREIGN_KEY_CHECKS=0");
    
    foreach ($tables as $table) {
        try {
            // Check if table exists in local
            $table_check = $local->query("SHOW TABLES LIKE '$table'");
            if ($table_check->num_rows === 0) {
                $results[$table] = ['status' => 'skipped', 'reason' => 'Table does not exist in local'];
                continue;
            }
            
            // Step A: Delete ALL rows in CLOUD table
            $cloud->query("DELETE FROM `$table`");
            $deleted = $cloud->affected_rows;
            
            // Step B: Get ALL rows from LOCAL table
            $result = $local->query("SELECT * FROM `$table`");
            
            if (!$result) {
                throw new Exception("Failed to read from local table $table: " . $local->error);
            }
            
            $inserted = 0;
            
            // Step C: Insert each row into CLOUD table
            while ($row = $result->fetch_assoc()) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escaped_values = array_map(function($val) use ($cloud) {
                    return $val === null ? 'NULL' : "'" . $cloud->real_escape_string($val) . "'";
                }, $values);
                
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escaped_values) . ")";
                
                if ($cloud->query($sql)) {
                    $inserted++;
                } else {
                    error_log("Failed to insert row into $table: " . $cloud->error);
                }
            }
            
            $results[$table] = [
                'status' => 'success',
                'deleted' => $deleted,
                'inserted' => $inserted
            ];
            
        } catch (Exception $e) {
            $results[$table] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Turn foreign keys back on
    $cloud->query("SET FOREIGN_KEY_CHECKS=1");
    
    $local->close();
    $cloud->close();
    
    return $results;
}

function getSyncStatus($local_config) {
    $local = new mysqli($local_config['host'], $local_config['user'], $local_config['pass'], $local_config['name'], $local_config['port']);
    
    if ($local->connect_error) {
        return [
            'total_records' => 0,
            'last_sync' => 'Never',
            'error' => 'Database connection failed'
        ];
    }
    
    $status = [
        'total_records' => 0,
        'last_sync' => 'Never'
    ];
    
    // Get total records from m9_similaritychecking
    $result = $local->query("SELECT COUNT(*) as count FROM m9_similaritychecking");
    if ($result) {
        $row = $result->fetch_assoc();
        $status['total_records'] = $row['count'];
    }
    
    $local->close();
    
    return $status;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Synchronization - LGU Research System</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/LGU2.png')) ?>">
    
    <!-- Fonts (preconnect to reduce OTS/CORS warnings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
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

.sync-container {
    padding: 2rem;
    background: var(--background-light);
    min-height: 100vh;
    font-family: 'Montserrat', sans-serif;
}

.page-header {
    background: var(--gradient-primary);
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

.sync-option {
    background: var(--background-lighter);
    border: 2px solid #e5e7eb;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    margin-bottom: 1rem;
    cursor: pointer;
    transition: var(--transition);
}

.sync-option:hover {
    border-color: var(--primary-blue);
    background: white;
    transform: translateY(-2px);
    box-shadow: var(--card-shadow);
}

.sync-option.selected {
    border-color: var(--primary-blue);
    background: rgba(17, 82, 114, 0.05);
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-family: 'Montserrat', sans-serif;
}

.btn-primary {
    background: var(--gradient-primary);
    color: white;
}

.btn-success {
    background: var(--gradient-success);
    color: white;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.status-card {
    background: linear-gradient(135deg, #f8fafc, #e2e8f0);
    border-left: 4px solid var(--primary-blue);
    padding: 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.status-item:last-child {
    border-bottom: none;
}

.progress-container {
    display: none;
    margin-top: 1rem;
}

.progress {
    height: 8px;
    border-radius: 4px;
    overflow: hidden;
    background: #e5e7eb;
}

.progress-bar {
    background: var(--gradient-success);
    transition: width 0.3s ease;
}

.log-container {
    background: #1a1a1a;
    color: #00ff00;
    padding: 1rem;
    border-radius: var(--border-radius);
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    max-height: 300px;
    overflow-y: auto;
    margin-top: 1rem;
    display: none;
}

@media (max-width: 768px) {
    .sync-container {
        padding: 1rem;
    }
    
    .page-header h1 {
        font-size: 2rem;
    }
    
    .card-body {
        padding: 1.5rem;
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
    <div class="sync-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-sync-alt"></i> Data Synchronization</h1>
            <p>Synchronize draft measures data between local and domain environments</p>
        </div>

        <!-- Sync Status -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-info-circle"></i> Synchronization Status</h2>
            </div>
            <div class="card-body">
                <?php
                // Check current environment and sync capability
                $current_env = $is_local ? 'Local (XAMPP)' : 'Cloud Server';
                $same_db = ($local_db['host'] === $cloud_db['host'] && $local_db['name'] === $cloud_db['name']);
                $can_sync_to_local = !$is_local && !$same_db;
                ?>
                
                <div class="alert alert-<?php echo $is_local ? 'success' : ($can_sync_to_local ? 'success' : 'info'); ?>" role="alert">
                    <i class="fas fa-<?php echo $is_local ? 'check-circle' : ($can_sync_to_local ? 'check-circle' : 'info-circle'); ?> me-2"></i>
                    <strong>Current Environment:</strong> <?php echo $current_env; ?>
                    
                    <?php if (!$is_local): ?>
                        <?php if ($same_db): ?>
                        <br><small><i class="fas fa-info-circle"></i> <strong>Cloud-to-Local Sync:</strong> Currently both databases point to cloud. Direct sync will be redundant. To enable true cloud-to-local sync, configure your local XAMPP endpoint in lines 37-45, or use the Export/Import method below.</small>
                        <?php else: ?>
                        <br><small><i class="fas fa-check-circle"></i> <strong>Cloud-to-Local Sync:</strong> Configured! Local endpoint is set to <?= DEMO_MODE ? 'the demo database' : e($local_db['host']) ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        <br><small><i class="fas fa-check-circle"></i> <strong>Bidirectional Sync:</strong> Both Pull (Cloud → Local) and Push (Local → Cloud) are available.</small>
                    <?php endif; ?>
                </div>
                
                <div class="status-card" id="statusCard">
                    <div class="status-item">
                        <span><strong>Total Records:</strong></span>
                        <span id="totalRecords">Loading...</span>
                    </div>
                    <div class="status-item">
                        <span><strong>Last Sync:</strong></span>
                        <span id="lastSync">Never</span>
                    </div>
                    <div class="status-item">
                        <span><strong>Current Environment:</strong></span>
                        <span id="currentEnv"><?php echo $current_env; ?></span>
                    </div>
                    <div class="status-item">
                        <span><strong>Local Database:</strong></span>
                        <span><?= DEMO_MODE ? 'Demo database' : e($local_db['host'] . ':' . $local_db['port'] . ' / ' . $local_db['name']) ?></span>
                    </div>
                    <div class="status-item">
                        <span><strong>Cloud Database:</strong></span>
                        <span><?= DEMO_MODE ? 'Not connected in the demo' : e($cloud_db['host'] . ':' . $cloud_db['port'] . ' / ' . $cloud_db['name']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync Controls -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-cogs"></i> Synchronization Controls</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Select Sync Direction:</h5>
                        
                        <div class="sync-option selected" data-direction="pull">
                            <h6><i class="fas fa-download"></i> Pull (Cloud → Local)</h6>
                            <p class="mb-0 text-muted">Delete all local data and copy everything from cloud (recommended)</p>
                        </div>
                        
                        <div class="sync-option" data-direction="push">
                            <h6><i class="fas fa-upload"></i> Push (Local → Cloud)</h6>
                            <p class="mb-0 text-muted">Delete all cloud data and copy everything from local</p>
                        </div>
                        
                        <div class="alert alert-warning mt-3" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This is a full replacement sync. The target will be completely overwritten!
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5>Actions:</h5>
                        
                        <button class="btn btn-primary w-100 mb-3" id="syncBtn" onclick="startSync()">
                            <i class="fas fa-sync-alt"></i> Start Auto Synchronization
                        </button>
                        
                        <button class="btn btn-success w-100" onclick="refreshStatus()">
                            <i class="fas fa-refresh"></i> Refresh Status
                        </button>
                        
                        <div class="progress-container" id="progressContainer">
                            <div class="progress">
                                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                            </div>
                            <small class="text-muted mt-1" id="progressText">Preparing synchronization...</small>
                        </div>
                    </div>
                </div>
                
                <!-- Sync Log -->
                <div class="log-container" id="syncLog"></div>
            </div>
        </div>

        <!-- Manual Export/Import Section -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #10b981, #059669);">
                <h2><i class="fas fa-file-export"></i> Manual Export/Import (Cloud → Local)</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Alternative Method:</strong> Use this when direct database sync is not possible. 
                    Export data from cloud, download the JSON file, then import it on your local XAMPP.
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h5><i class="fas fa-cloud-download-alt"></i> Step 1: Export from Cloud</h5>
                        <p class="text-muted">Download all cloud data as a JSON file.</p>
                        <button class="btn btn-primary w-100 mb-3" id="exportBtn" onclick="exportCloudData()">
                            <i class="fas fa-download"></i> Export Cloud Data
                        </button>
                    </div>
                    
                    <div class="col-md-6">
                        <h5><i class="fas fa-database"></i> Step 2: Import to Local</h5>
                        <p class="text-muted">Upload the exported JSON file to import into local database.</p>
                        <input type="file" id="importFile" accept=".json" style="display: none;" onchange="handleImportFile(this)">
                        <button class="btn btn-success w-100 mb-3" onclick="document.getElementById('importFile').click()">
                            <i class="fas fa-upload"></i> Import JSON File
                        </button>
                    </div>
                </div>
                
                <!-- Import Log -->
                <div class="log-container" id="importLog"></div>
            </div>
        </div>
    </div>
</div>

<script>
let selectedDirection = 'pull';

document.addEventListener('DOMContentLoaded', function() {
    // Initialize
    refreshStatus();
    
    // Setup sync direction selection
    document.querySelectorAll('.sync-option[data-direction]').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.sync-option[data-direction]').forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            selectedDirection = this.dataset.direction;
        });
    });
});

function refreshStatus() {
    fetch('?action=get_sync_status')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            document.getElementById('totalRecords').textContent = result.data.total_records;
            document.getElementById('lastSync').textContent = result.data.last_sync || 'Never';
        }
    })
    .catch(error => {
        console.error('Error refreshing status:', error);
    });
}

function startSync() {
    const syncBtn = document.getElementById('syncBtn');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const syncLog = document.getElementById('syncLog');
    
    // Disable button and show progress
    syncBtn.disabled = true;
    syncBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Synchronizing...';
    progressContainer.style.display = 'block';
    syncLog.style.display = 'block';
    syncLog.innerHTML = '';
    
    // Log start
    addLogEntry('Starting synchronization...');
    addLogEntry(`Direction: ${selectedDirection}`);
    
    // Simulate progress
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += Math.random() * 20;
        if (progress > 90) progress = 90;
        progressBar.style.width = progress + '%';
        progressText.textContent = `Synchronizing data... ${Math.round(progress)}%`;
    }, 500);
    
    // Perform sync
    const formData = new FormData();
    formData.append('sync_direction', selectedDirection);
    
    fetch('?action=sync_data', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        clearInterval(progressInterval);
        progressBar.style.width = '100%';
        progressText.textContent = 'Synchronization complete!';
        
        if (result.success) {
            addLogEntry('✓ Synchronization completed successfully');
            
            // Display results for each table
            if (result.data) {
                Object.keys(result.data).forEach(table => {
                    const tableResult = result.data[table];
                    if (tableResult.status === 'success') {
                        addLogEntry(`✓ ${table}: Deleted ${tableResult.deleted}, Inserted ${tableResult.inserted}`);
                    } else if (tableResult.status === 'skipped') {
                        addLogEntry(`⊘ ${table}: ${tableResult.reason}`);
                    } else if (tableResult.status === 'error') {
                        addLogEntry(`✗ ${table}: ${tableResult.message}`);
                    }
                });
            }
            
            showAlert('Synchronization completed successfully!', 'success');
            refreshStatus();
        } else {
            addLogEntry('✗ Synchronization failed: ' + result.message);
            showAlert('Synchronization failed: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        clearInterval(progressInterval);
        addLogEntry('✗ Error: ' + error.message);
        showAlert('An error occurred during synchronization.', 'danger');
    })
    .finally(() => {
        syncBtn.disabled = false;
        syncBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Start Auto Synchronization';
        
        setTimeout(() => {
            progressContainer.style.display = 'none';
        }, 3000);
    });
}

function addLogEntry(message) {
    const syncLog = document.getElementById('syncLog');
    const timestamp = new Date().toLocaleTimeString();
    syncLog.innerHTML += `[${timestamp}] ${message}\n`;
    syncLog.scrollTop = syncLog.scrollHeight;
}

function showAlert(message, type) {
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
    
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.parentNode.removeChild(alertDiv);
        }
    }, 5000);
}

// Export cloud data as JSON file
function exportCloudData() {
    const exportBtn = document.getElementById('exportBtn');
    const importLog = document.getElementById('importLog');
    
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Exporting...';
    importLog.style.display = 'block';
    importLog.innerHTML = '';
    
    addImportLogEntry('Starting cloud data export...');
    
    fetch('?action=export_cloud_data')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            addImportLogEntry('✓ Cloud data retrieved successfully');
            
            // Show table counts
            if (result.tables) {
                Object.keys(result.tables).forEach(table => {
                    const tableData = result.tables[table];
                    if (tableData.exists) {
                        addImportLogEntry(`  → ${table}: ${tableData.count} records`);
                    } else {
                        addImportLogEntry(`  → ${table}: Table not found`);
                    }
                });
            }
            
            // Create and download JSON file
            const dataStr = JSON.stringify(result, null, 2);
            const blob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `lgu_cloud_export_${new Date().toISOString().slice(0,10)}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            addImportLogEntry('✓ JSON file downloaded successfully');
            showAlert('Cloud data exported! Check your downloads folder.', 'success');
        } else {
            addImportLogEntry('✗ Export failed: ' + result.message);
            showAlert('Export failed: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        addImportLogEntry('✗ Error: ' + error.message);
        showAlert('An error occurred during export.', 'danger');
    })
    .finally(() => {
        exportBtn.disabled = false;
        exportBtn.innerHTML = '<i class="fas fa-download"></i> Export Cloud Data';
    });
}

// Handle import file selection
function handleImportFile(input) {
    const file = input.files[0];
    if (!file) return;
    
    const importLog = document.getElementById('importLog');
    importLog.style.display = 'block';
    importLog.innerHTML = '';
    
    addImportLogEntry(`Reading file: ${file.name}`);
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const jsonData = e.target.result;
            const parsed = JSON.parse(jsonData);
            
            if (!parsed.tables) {
                throw new Error('Invalid export file format - missing tables data');
            }
            
            addImportLogEntry('✓ File parsed successfully');
            addImportLogEntry(`Export date: ${parsed.export_date || 'Unknown'}`);
            
            // Show what will be imported
            Object.keys(parsed.tables).forEach(table => {
                const tableData = parsed.tables[table];
                if (tableData.exists) {
                    addImportLogEntry(`  → ${table}: ${tableData.count} records to import`);
                }
            });
            
            // Confirm and import
            if (confirm('Are you sure you want to import this data? This will REPLACE all local data!')) {
                importData(jsonData);
            } else {
                addImportLogEntry('Import cancelled by user');
            }
            
        } catch (error) {
            addImportLogEntry('✗ Error parsing file: ' + error.message);
            showAlert('Invalid JSON file: ' + error.message, 'danger');
        }
    };
    
    reader.onerror = function() {
        addImportLogEntry('✗ Error reading file');
        showAlert('Error reading file', 'danger');
    };
    
    reader.readAsText(file);
    
    // Reset input so same file can be selected again
    input.value = '';
}

// Import data to local database
function importData(jsonData) {
    addImportLogEntry('Starting import to local database...');
    
    const formData = new FormData();
    formData.append('json_data', jsonData);
    
    fetch('?action=import_data', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            addImportLogEntry('✓ Import completed successfully');
            
            // Show results
            if (result.data) {
                Object.keys(result.data).forEach(table => {
                    const tableResult = result.data[table];
                    if (tableResult.status === 'success') {
                        addImportLogEntry(`✓ ${table}: Deleted ${tableResult.deleted}, Inserted ${tableResult.inserted}`);
                    } else if (tableResult.status === 'skipped') {
                        addImportLogEntry(`⊘ ${table}: ${tableResult.reason}`);
                    }
                });
            }
            
            showAlert('Data imported successfully!', 'success');
            refreshStatus();
        } else {
            addImportLogEntry('✗ Import failed: ' + result.message);
            showAlert('Import failed: ' + result.message, 'danger');
        }
    })
    .catch(error => {
        addImportLogEntry('✗ Error: ' + error.message);
        showAlert('An error occurred during import.', 'danger');
    });
}

// Add log entry to import log
function addImportLogEntry(message) {
    const importLog = document.getElementById('importLog');
    const timestamp = new Date().toLocaleTimeString();
    importLog.innerHTML += `[${timestamp}] ${message}\n`;
    importLog.scrollTop = importLog.scrollHeight;
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- System Lock JavaScript -->
<script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>

</body>
</html>
